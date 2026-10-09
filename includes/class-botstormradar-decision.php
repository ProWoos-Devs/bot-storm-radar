<?php
/**
 * The gate's decision for one request, pure: no output, no files, no
 * globals. Shared by the early gate (bundled into its file, renamed
 * BotStormRadar_Gate_Decision) and the plugin's own refusal, so both decide
 * the same way from the same projection (BotStormRadar_Projection).
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress or by the gate, which defines BOTSTORMRADAR_GATE first.
defined( 'BOTSTORMRADAR_GATE' ) || defined( 'ABSPATH' ) || exit;

class BotStormRadar_Decision {

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
			if ( is_array( $u ) && isset( $u[0], $u[2] ) && (int) $u[2] >= (int) ( $pb['t'] ?? 0 ) && BotStormRadar_IP_Resolver::ip_in_cidr( $ip, $u[0] . '/' . (int) ( $u[1] ?? 32 ) ) ) {
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
		$ip    = BotStormRadar_IP_Resolver::resolve( $server, $trust )['ip'];
		// Probes first, for every address: these paths are never served by
		// WordPress, so refusing them only turns a 404 into a cheap 403.
		$probe = BotStormRadar_Probe::classify( (string) ( $server['REQUEST_URI'] ?? '' ), isset( $state['probe'] ) && is_array( $state['probe'] ) ? $state['probe'] : [], (string) ( $server['SCRIPT_FILENAME'] ?? '' ) );
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
		if ( ! BotStormRadar_IP_Resolver::is_public_ip( $ip ) ) {
			return true;
		}
		$trust = $state['trust'] ?? [];
		$prot  = $state['protected'] ?? [];
		foreach ( [ $trust['cloudflare'] ?? [], $trust['proxies'] ?? [], $prot['allowlist'] ?? [], $prot['admins'] ?? [], $prot['bots'] ?? [] ] as $list ) {
			if ( ! empty( $list ) && BotStormRadar_IP_Resolver::ip_in_list( $ip, $list ) ) {
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
			if ( $r[1] > $now && BotStormRadar_IP_Resolver::ip_in_cidr( $ip, $r[0] ) ) {
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
}
