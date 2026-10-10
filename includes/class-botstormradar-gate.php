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
 *   2b. a probe (BotStormRadar_Probe) answers 403, for every address and in both modes,
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
 * The decision itself (steps 2b to 4) is BotStormRadar_Decision::decide().
 *
 * This file is not loaded by the plugin. BotStormRadar_Gate_Install bundles it with
 * BotStormRadar_IP_Resolver, BotStormRadar_State_Reader, BotStormRadar_Probe, BotStormRadar_Decision and BotStormRadar_Channel into one self-contained gate file in
 * the data directory, with every class renamed to a BotStormRadar_Gate_ prefix so it
 * never collides with the plugin's own copies.
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress (tests) or by the gate, which defines BOTSTORMRADAR_GATE first.
defined( 'BOTSTORMRADAR_GATE' ) || defined( 'ABSPATH' ) || exit;

class BotStormRadar_Gate {

	/**
	 * APCu key prefix for the decoded state.
	 */
	const CACHE_PREFIX = 'botstormradar_gate_state:';

	/**
	 * The gate for this request. Runs once, whichever loader called it.
	 *
	 * @param string $state_path
	 */
	public static function run( $state_path ) {
		if ( defined( 'BOTSTORMRADAR_GATE_RAN' ) || 'cli' === PHP_SAPI ) {
			return;
		}
		define( 'BOTSTORMRADAR_GATE_RAN', true );
		register_shutdown_function( [ __CLASS__, 'observe' ] );
		// The plugin's loopback check asks how the gate was loaded.
		if ( isset( $_SERVER['HTTP_X_BSR_GATE_CHECK'] ) && ! headers_sent() ) {
			header( 'X-BSR-Gate: ' . ( defined( 'BOTSTORMRADAR_GATE_EARLY' ) || defined( 'BSR_GATE_EARLY' ) ? 'early' : 'mu' ) ); // BSR_GATE_EARLY: a loader written before 0.3.
		}

		$state = self::state( $state_path );
		if ( null === $state ) {
			return; // No state yet, or unreadable: fail open.
		}
		$dir = dirname( $state_path ) . '/';
		$now = time();
		$d   = BotStormRadar_Decision::decide( $_SERVER, $state, $now, function ( $ip ) use ( $dir ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- the resolver validates every address; the path is only matched.
			return BotStormRadar_Channel::ban_get( $dir, $ip );
		} );
		if ( 'ban' === $d['action'] || 'probe' === $d['action'] ) {
			// The radar learns about it through the channel (drained by the tick).
			BotStormRadar_Channel::write( $dir, $d['action'], 'probe' === $d['action'] ? $d['why'] : '', (string) $d['ip'] );
			if ( 'probe' === $d['action'] ) {
				// Paths and user agent for the Bans tab and abuse reports (bounded, query strings cut).
				BotStormRadar_Channel::evidence_add( $dir, (string) $d['ip'], (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ), (string) ( $_SERVER['REQUEST_URI'] ?? '' ), (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), $now ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cut to printable ASCII and bounded by evidence_clean().
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
		if ( ! is_array( $cfg ) || empty( $cfg['count'] ) || '' === $ip || ! BotStormRadar_Channel::apcu() || BotStormRadar_Decision::is_protected( $ip, $state ) ) {
			return '';
		}
		$n = BotStormRadar_Channel::probe_count( $dir, $ip, (int) ( $cfg['window'] ?? 600 ), $now );
		if ( $n !== (int) $cfg['count'] ) {
			return '';
		}
		$ttl = max( 60, (int) ( $cfg['ttl'] ?? 3600 ) );
		$gen = (int) ( $state['generation'] ?? 0 );
		foreach ( (array) ( $cfg['claims'] ?? [] ) as $name => $pattern ) {
			if ( '' !== $ua && is_string( $pattern ) && 1 === @preg_match( $pattern, $ua ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				BotStormRadar_Channel::write( $dir, 'claimtrip', 'probe-' . $gen . '-' . $ttl . '-' . $name, $ip, $now );
				return 'claimtrip';
			}
		}
		if ( 'enforce' === ( $state['mode'] ?? 'observe' ) ) {
			BotStormRadar_Channel::ban_set( $dir, $ip, $now + $ttl, $gen, 'probe', $now );
			BotStormRadar_Channel::write( $dir, 'trip', 'probe-' . $gen . '-' . $ttl, $ip, $now );
			return 'trip';
		}
		BotStormRadar_Channel::write( $dir, 'wouldban', 'probe-' . $gen . '-' . $ttl, $ip, $now );
		return 'wouldban';
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
		$state = BotStormRadar_State_Reader::read( $path );
		if ( null === $state ) {
			return null;
		}
		$state['_index'] = BotStormRadar_Decision::index( $state );
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
