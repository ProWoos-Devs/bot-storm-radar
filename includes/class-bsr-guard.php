<?php
/**
 * The guard: no ban may ever cover an address that must stay reachable.
 *
 * A ban on a CDN edge or a proxy blocks every visitor routed through it, a
 * ban on an administrator locks the owner out, and a ban on a verified search
 * bot hurts the site in search results. may_ban() refuses all of them. It is
 * the only way into the ban table: BSR_Bans::trip() asks it before writing,
 * the state rebuild asks it again for every ban it projects (so one bug
 * upstream cannot leak a protected address into the gate's list), and the
 * exporters will ask it before they send anything out.
 *
 * Refused:
 *   - an invalid address or prefix, and a range wider than /16 (IPv4) or /32
 *     (IPv6);
 *   - private, loopback, link-local, reserved and CGNAT addresses;
 *   - the Cloudflare ranges and the declared proxies;
 *   - the allowlist (Settings, "Never ban");
 *   - administrator addresses seen on an authenticated wp-admin request in
 *     the last 24 hours;
 *   - addresses with a verified search-bot verdict.
 * A range is refused when it overlaps any protected entry.
 *
 * The protected lists reach the gate through the state file; the guard fires
 * `bsr_protected_changed` (the state rebuilds) when an administrator address
 * is first seen and when a bot is newly verified.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Guard {

	const ADMINS_OPTION = 'bsr_admin_addresses';

	/**
	 * How long an administrator address stays protected after it was last
	 * seen, and how often the record of a known one is refreshed.
	 */
	const ADMIN_TTL     = DAY_IN_SECONDS;
	const ADMIN_REFRESH = HOUR_IN_SECONDS;
	const ADMINS_CAP    = 50;

	/**
	 * Narrowest allowed prefix length for a range ban (the widest range).
	 */
	const MIN_PREFIX_V4 = 16;
	const MIN_PREFIX_V6 = 32;

	/**
	 * Test hook: fn() => array of protected lists, replacing the stored ones.
	 *
	 * @var callable|null
	 */
	public static $lists_override = null;

	public static function init() {
		// Zero accepted arguments: do_action() passes an empty string otherwise,
		// which note_admin() would take as its time.
		add_action( 'admin_init', [ __CLASS__, 'note_admin' ], 1, 0 );
	}

	/**
	 * @param string   $ip
	 * @param int|null $prefix_len Null for a single address.
	 * @return bool
	 */
	public static function may_ban( $ip, $prefix_len = null ) {
		return '' === self::refusal( $ip, $prefix_len );
	}

	/**
	 * Why a ban is refused, '' when it is allowed.
	 *
	 * @param string   $ip
	 * @param int|null $prefix_len
	 * @return string invalid | too-wide | private | cloudflare | proxy | allowlist | admin | bot | ''
	 */
	public static function refusal( $ip, $prefix_len = null ) {
		$key = BSR_Bans::key( $ip, $prefix_len );
		if ( null === $key ) {
			return 'invalid';
		}
		$net    = $key['ip_text'];
		$v6     = false !== strpos( $net, ':' );
		$single = $key['prefix_len'] === ( $v6 ? 128 : 32 );
		if ( ! $single && $key['prefix_len'] < ( $v6 ? self::MIN_PREFIX_V6 : self::MIN_PREFIX_V4 ) ) {
			return 'too-wide';
		}
		if ( ! BSR_IP_Resolver::is_public_ip( $net ) || ( $single && ! BSR_IP_Resolver::is_public_ip( $ip ) ) ) {
			return 'private';
		}
		$cidr = $net . '/' . $key['prefix_len'];
		foreach ( self::lists() as $why => $list ) {
			foreach ( (array) $list as $entry ) {
				if ( self::overlaps( $cidr, (string) $entry ) ) {
					return $why;
				}
			}
		}
		if ( $single && self::is_verified_bot( $net ) ) {
			return 'bot';
		}
		return '';
	}

	/**
	 * The protected lists, named by the refusal they produce.
	 *
	 * @return array {cloudflare, proxy, allowlist, admin, bot}
	 */
	public static function lists() {
		if ( is_callable( self::$lists_override ) ) {
			return call_user_func( self::$lists_override );
		}
		$opts = BSR_Helpers::get_options();
		return [
			'cloudflare' => BSR_Client_IP::cloudflare_ranges(),
			'proxy'      => BSR_Helpers::parse_list( $opts['trusted_proxies'] ?? '' ),
			'allowlist'  => BSR_Helpers::parse_list( $opts['allowlist'] ?? '' ),
			'admin'      => self::admin_addresses(),
			'bot'        => self::verified_bots(),
		];
	}

	/**
	 * Whether two CIDR ranges (or addresses) share any address: same family,
	 * and one contains the other's network address.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	public static function overlaps( $a, $b ) {
		$na = explode( '/', trim( $a ) )[0];
		$nb = explode( '/', trim( $b ) )[0];
		if ( ! BSR_IP_Resolver::is_valid_ip( $na ) || ! BSR_IP_Resolver::is_valid_ip( $nb ) || ( false !== strpos( $na, ':' ) ) !== ( false !== strpos( $nb, ':' ) ) ) {
			return false;
		}
		return BSR_IP_Resolver::ip_in_cidr( $na, trim( $b ) ) || BSR_IP_Resolver::ip_in_cidr( $nb, trim( $a ) );
	}

	// ── Administrator addresses ───────────────────────────────────────

	/**
	 * Record the address of an administrator on an authenticated wp-admin
	 * request. Written at most once an hour per address; a new address
	 * rebuilds the state so the gate protects it at once.
	 *
	 * @param int|null    $now Tests.
	 * @param string|null $ip  Tests.
	 */
	public static function note_admin( $now = null, $ip = null ) {
		if ( null === $ip && ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$ip  = null === $ip ? (string) BSR_Client_IP::resolve() : (string) $ip;
		$now = null === $now ? time() : (int) $now;
		if ( '' === $ip || ! BSR_IP_Resolver::is_public_ip( $ip ) ) {
			return;
		}
		$known = self::admin_records();
		$last  = (int) ( $known[ $ip ] ?? 0 );
		if ( $last > $now - self::ADMIN_REFRESH ) {
			return;
		}
		$is_new       = $last <= $now - self::ADMIN_TTL;
		$known[ $ip ] = $now;
		$known        = array_filter( $known, function ( $t ) use ( $now ) {
			return (int) $t > $now - self::ADMIN_TTL;
		} );
		arsort( $known );
		update_option( self::ADMINS_OPTION, array_slice( $known, 0, self::ADMINS_CAP, true ), false );
		if ( $is_new ) {
			/** Fires when a protected address appears (the state file rebuilds). */
			do_action( 'bsr_protected_changed' );
		}
	}

	/**
	 * @return array ip => last seen
	 */
	public static function admin_records() {
		$r = get_option( self::ADMINS_OPTION, [] );
		return is_array( $r ) ? $r : [];
	}

	/**
	 * Administrator addresses seen in the last 24 hours.
	 *
	 * @param int|null $now
	 * @return string[]
	 */
	public static function admin_addresses( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = [];
		foreach ( self::admin_records() as $ip => $t ) {
			if ( (int) $t > $now - self::ADMIN_TTL ) {
				$out[] = (string) $ip;
			}
		}
		return $out;
	}

	// ── Verified bots ─────────────────────────────────────────────────

	/**
	 * Addresses with a verified search-bot verdict still in force.
	 *
	 * @param int|null $now
	 * @return string[]
	 */
	public static function verified_bots( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$out = [];
		foreach ( BSR_Good_Bots::recent() as $ip => $r ) {
			if ( 'verified' === ( $r['v'] ?? '' ) && (int) ( $r['t'] ?? 0 ) > $now - BSR_Good_Bots::VERDICT_TTL ) {
				$out[] = (string) $ip;
			}
		}
		return $out;
	}

	/**
	 * @param string $ip
	 * @return bool
	 */
	public static function is_verified_bot( $ip ) {
		$cached = BSR_Counters::get_value( 'g:bot:' . $ip );
		return is_array( $cached ) && 'verified' === ( $cached['v'] ?? '' );
	}
}
