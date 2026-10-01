<?php
/**
 * The gate: runs before themes and plugins, never loads WordPress, never
 * touches the database. It reads the state file, resolves the visitor like
 * the radar does, and answers 403 to an address under an active ban.
 * Everything else passes to WordPress untouched.
 *
 * Order on every request:
 *   0. answer the plugin's loopback check (`X-BSR-Gate: early` or `mu`) when
 *      the request carries `X-BSR-Gate-Check`;
 *   1. register the shutdown observer (the hook point exists now so that
 *      protected and refused requests are observed too; it records nothing
 *      until the health work fills it in);
 *   2. resolve the client address with the trust configuration of the state;
 *   2b. a probe (BSR_Probe) answers 403, for every address and in both modes,
 *      unless the owner switched probe refusal off;
 *   3. a protected address passes (allowlist, administrator addresses,
 *      verified bots, CDN and proxy ranges, private addresses);
 *   4. in enforce mode, an active ban answers 403: one from the state file,
 *      or a provisional one the gate set itself in APCu (see probe_trip())
 *      while it still holds (provisional_holds()). Expiry is compared with
 *      the current time on every decision, so a ban ends even if cron never
 *      runs. Observe mode never refuses.
 *
 * There is no exemption for a login cookie: the gate cannot validate one
 * (that needs the user, the signature and the session token), and a cookie
 * that is merely present can be forged.
 *
 * This file is not loaded by the plugin. BSR_Gate_Install bundles it with
 * BSR_IP_Resolver, BSR_State_Reader and BSR_Probe into one self-contained gate file in
 * the data directory, with every class renamed to a BSR_Gate_ prefix so it
 * never collides with the plugin's own copies.
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress (tests) or by the gate, which defines BSR_GATE first.
if ( ! defined( 'ABSPATH' ) && ! defined( 'BSR_GATE' ) ) {
	exit;
}

class BSR_Gate {

	/**
	 * APCu key prefix for the decoded state.
	 */
	const CACHE_PREFIX = 'bsr_gate_state:';

	/**
	 * The gate for this request. Runs once, whichever loader called it.
	 *
	 * @param string $state_path
	 */
	public static function run( $state_path ) {
		if ( defined( 'BSR_GATE_RAN' ) || 'cli' === PHP_SAPI ) {
			return;
		}
		define( 'BSR_GATE_RAN', true );
		register_shutdown_function( [ __CLASS__, 'observe' ] );
		// The plugin's loopback check asks how the gate was loaded.
		if ( isset( $_SERVER['HTTP_X_BSR_GATE_CHECK'] ) && ! headers_sent() ) {
			header( 'X-BSR-Gate: ' . ( defined( 'BSR_GATE_EARLY' ) ? 'early' : 'mu' ) );
		}

		$state = self::state( $state_path );
		if ( null === $state ) {
			return; // No state yet, or unreadable: fail open.
		}
		$dir = dirname( $state_path ) . '/';
		$now = time();
		$d   = self::decide( $_SERVER, $state, $now, function ( $ip ) use ( $dir ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- the resolver validates every address; the path is only matched.
			return BSR_Channel::ban_get( $dir, $ip );
		} );
		if ( 'ban' === $d['action'] || 'probe' === $d['action'] ) {
			// The radar learns about it through the channel (drained by the tick).
			BSR_Channel::write( $dir, $d['action'], 'probe' === $d['action'] ? $d['why'] : '', (string) $d['ip'] );
			if ( 'probe' === $d['action'] ) {
				self::probe_trip( $dir, (string) $d['ip'], $state, $now, (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only matched against the bot patterns.
			}
			self::refuse( 'probe' === $d['action'] ? 'probe' : 'refused' );
		}
	}

	/**
	 * Gate-side probe trip (APCu only; without APCu the radar trips from the
	 * channel counts). On the probe that reaches the threshold: in enforce
	 * mode a provisional ban, stamped with the generation, plus a `trip`
	 * event the drain turns into a ban row; in observe mode only a `wouldban`
	 * event. Protected addresses never trip. A user agent that claims a
	 * verified-bot family gets no provisional ban: the gate cannot verify it,
	 * so it sends a `claimtrip` event and the radar decides after verifying.
	 * Returns trip | wouldban | claimtrip | ''.
	 *
	 * @param string $dir
	 * @param string $ip
	 * @param array  $state
	 * @param int    $now
	 * @param string $ua
	 * @return string
	 */
	public static function probe_trip( $dir, $ip, array $state, $now, $ua = '' ) {
		$cfg = $state['trips']['probe'] ?? null;
		if ( ! is_array( $cfg ) || empty( $cfg['count'] ) || '' === $ip || ! BSR_Channel::apcu() || self::is_protected( $ip, $state ) ) {
			return '';
		}
		$n = BSR_Channel::probe_count( $dir, $ip, (int) ( $cfg['window'] ?? 600 ), $now );
		if ( $n !== (int) $cfg['count'] ) {
			return '';
		}
		$ttl = max( 60, (int) ( $cfg['ttl'] ?? 3600 ) );
		$gen = (int) ( $state['generation'] ?? 0 );
		foreach ( (array) ( $cfg['claims'] ?? [] ) as $name => $pattern ) {
			if ( '' !== $ua && is_string( $pattern ) && 1 === @preg_match( $pattern, $ua ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				BSR_Channel::write( $dir, 'claimtrip', 'probe-' . $gen . '-' . $ttl . '-' . $name, $ip, $now );
				return 'claimtrip';
			}
		}
		if ( 'enforce' === ( $state['mode'] ?? 'observe' ) ) {
			BSR_Channel::ban_set( $dir, $ip, $now + $ttl, $gen, 'probe', $now );
			BSR_Channel::write( $dir, 'trip', 'probe-' . $gen . '-' . $ttl, $ip, $now );
			return 'trip';
		}
		BSR_Channel::write( $dir, 'wouldban', 'probe-' . $gen . '-' . $ttl, $ip, $now );
		return 'wouldban';
	}

	/**
	 * Whether a provisional ban still holds: not expired, decided under the
	 * current generation (any unban, mode switch or settings change since
	 * raised it), and the address not unbanned after it was decided.
	 *
	 * @param array|null $pb    exp, gen, reason, t
	 * @param string     $ip
	 * @param array      $state
	 * @param int        $now
	 * @return bool
	 */
	public static function provisional_holds( $pb, $ip, array $state, $now ) {
		if ( ! is_array( $pb ) || (int) ( $pb['exp'] ?? 0 ) <= $now || (int) ( $pb['gen'] ?? -1 ) !== (int) ( $state['generation'] ?? 0 ) ) {
			return false;
		}
		foreach ( (array) ( $state['unbans'] ?? [] ) as $u ) {
			if ( is_array( $u ) && isset( $u[0], $u[2] ) && (int) $u[2] >= (int) ( $pb['t'] ?? 0 ) && BSR_IP_Resolver::ip_in_cidr( $ip, $u[0] . '/' . (int) ( $u[1] ?? 32 ) ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The decision for one request, pure: no output, no globals.
	 *
	 * @param array         $server
	 * @param array         $state       Decoded state (with or without the index).
	 * @param int           $now
	 * @param callable|null $provisional fn( $ip ) => provisional ban or null.
	 * @return array {action: pass|ban|probe, why: string, ip: string|false}
	 */
	public static function decide( array $server, array $state, $now, $provisional = null ) {
		$trust = isset( $state['trust'] ) && is_array( $state['trust'] ) ? $state['trust'] : [];
		$ip    = BSR_IP_Resolver::resolve( $server, $trust )['ip'];
		// Probes first, for every address: these paths are never served by
		// WordPress, so refusing them only turns a 404 into a cheap 403.
		$probe = BSR_Probe::classify( (string) ( $server['REQUEST_URI'] ?? '' ), isset( $state['probe'] ) && is_array( $state['probe'] ) ? $state['probe'] : [], (string) ( $server['SCRIPT_FILENAME'] ?? '' ) );
		if ( '' !== $probe ) {
			return [ 'action' => 'probe', 'why' => $probe, 'ip' => $ip ];
		}
		if ( false === $ip ) {
			return [ 'action' => 'pass', 'why' => 'no-address', 'ip' => false ];
		}
		if ( self::is_protected( $ip, $state ) ) {
			return [ 'action' => 'pass', 'why' => 'protected', 'ip' => $ip ];
		}
		if ( 'enforce' !== ( $state['mode'] ?? 'observe' ) ) {
			return [ 'action' => 'pass', 'why' => 'observe', 'ip' => $ip ];
		}
		if ( self::banned( $ip, $state, (int) $now ) ) {
			return [ 'action' => 'ban', 'why' => 'banned', 'ip' => $ip ];
		}
		if ( is_callable( $provisional ) && self::provisional_holds( call_user_func( $provisional, $ip ), $ip, $state, (int) $now ) ) {
			return [ 'action' => 'ban', 'why' => 'provisional', 'ip' => $ip ];
		}
		return [ 'action' => 'pass', 'why' => 'clear', 'ip' => $ip ];
	}

	/**
	 * Never refused: private and reserved addresses, the CDN and declared
	 * proxy ranges, the allowlist, administrator addresses, verified bots.
	 *
	 * @param string $ip
	 * @param array  $state
	 * @return bool
	 */
	public static function is_protected( $ip, array $state ) {
		if ( ! BSR_IP_Resolver::is_public_ip( $ip ) ) {
			return true;
		}
		$trust = $state['trust'] ?? [];
		$prot  = $state['protected'] ?? [];
		foreach ( [ $trust['cloudflare'] ?? [], $trust['proxies'] ?? [], $prot['allowlist'] ?? [], $prot['admins'] ?? [], $prot['bots'] ?? [] ] as $list ) {
			if ( ! empty( $list ) && BSR_IP_Resolver::ip_in_list( $ip, $list ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether an active ban covers the address. Single-address bans are
	 * looked up by their binary form (so any spelling of an IPv6 address
	 * matches); prefix bans are matched as ranges.
	 *
	 * @param string $ip
	 * @param array  $state
	 * @param int    $now
	 * @return bool
	 */
	public static function banned( $ip, array $state, $now ) {
		$index = isset( $state['_index'] ) ? $state['_index'] : self::index( $state );
		$bin   = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- validated already; defensive.
		if ( false === $bin ) {
			return false;
		}
		$hex = bin2hex( $bin );
		if ( isset( $index['single'][ $hex ] ) && $index['single'][ $hex ] > $now ) {
			return true;
		}
		foreach ( $index['ranges'] as $r ) {
			if ( $r[1] > $now && BSR_IP_Resolver::ip_in_cidr( $ip, $r[0] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Lookup index for the bans: single addresses by hex binary => expiry,
	 * prefixes as [cidr, expiry].
	 *
	 * @param array $state
	 * @return array {single: array, ranges: array}
	 */
	public static function index( array $state ) {
		$single = [];
		$ranges = [];
		foreach ( (array) ( $state['bans'] ?? [] ) as $b ) {
			if ( ! is_array( $b ) || count( $b ) < 3 ) {
				continue;
			}
			$bin = @inet_pton( (string) $b[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $bin ) {
				continue;
			}
			$len = (int) $b[1];
			if ( $len === strlen( $bin ) * 8 ) {
				$hex            = bin2hex( $bin );
				$single[ $hex ] = max( (int) $b[2], $single[ $hex ] ?? 0 );
			} else {
				$ranges[] = [ $b[0] . '/' . $len, (int) $b[2] ];
			}
		}
		return [ 'single' => $single, 'ranges' => $ranges ];
	}

	/**
	 * The decoded state with its ban index. With APCu the decoded array is
	 * cached under the file's inode, modification time and size: a rebuild
	 * renames a new file into place, so its inode changes even within one
	 * second.
	 *
	 * @param string $path
	 * @return array|null
	 */
	public static function state( $path ) {
		$st = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a missing file is a normal answer.
		if ( false === $st ) {
			return null;
		}
		$apcu = function_exists( 'apcu_fetch' ) && function_exists( 'apcu_enabled' ) && apcu_enabled();
		$key  = self::CACHE_PREFIX . md5( $path ) . ':' . $st['ino'] . ':' . $st['mtime'] . ':' . $st['size'];
		if ( $apcu ) {
			$hit = apcu_fetch( $key, $ok );
			if ( $ok && is_array( $hit ) ) {
				return $hit;
			}
		}
		$state = BSR_State_Reader::read( $path );
		if ( null === $state ) {
			return null;
		}
		$state['_index'] = self::index( $state );
		if ( $apcu ) {
			apcu_store( $key, $state, 3600 );
		}
		return $state;
	}

	/**
	 * Answer 403 without WordPress and stop.
	 *
	 * @param string $kind refused (a ban) or probe.
	 */
	public static function refuse( $kind = 'refused' ) {
		if ( ! headers_sent() ) {
			http_response_code( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'X-BSR-Gate: ' . ( 'probe' === $kind ? 'probe' : 'refused' ) );
		}
		echo "Forbidden\n";
		exit;
	}

	/**
	 * Shutdown observer. Registered first on every request; the response
	 * recording (5xx counts, database failures) arrives with the health work.
	 */
	public static function observe() {
	}
}
