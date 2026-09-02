<?php
/**
 * Client IP resolution with a trusted-proxy model.
 *
 * REMOTE_ADDR is the client unless it belongs to a proxy we trust:
 *
 * 1. Cloudflare. Its published ranges are fetched daily and cached, with a
 *    bundled copy as the fallback. A request from a Cloudflare address carries
 *    the client in CF-Connecting-IP.
 * 2. A local proxy. A private, link-local, or loopback REMOTE_ADDR cannot be an
 *    internet client, so the request came through a proxy on the host (most
 *    managed hosts work this way). The client is the rightmost X-Forwarded-For
 *    entry that is itself public and not a trusted proxy, then X-Real-IP.
 * 3. An owner-declared proxy (Settings tab), same walk as above.
 *
 * Anything else is a direct connection and forwarding headers are ignored,
 * because any client can type them: a bot could otherwise spread one machine
 * over invented addresses, or point blame at innocent ones. The spoofable
 * behavior survives behind an explicit "trust all forwarding headers"
 * switch, off by default, for hosts with an unusual public-address proxy the
 * owner cannot identify yet.
 *
 * A public-address proxy that has NOT been declared is detected on admin
 * requests (same public REMOTE_ADDR, forwarding header present, repeatedly).
 * While it stays undeclared, anything keyed by IP must stand down, because
 * every visitor would share the proxy's address and the first trip would lock
 * all of them out. In 0.1 nothing enforces; the flag labels the dashboard.
 *
 * SHARED CODE. This file is a copy of WC Antifraud's class-wcaf-client-ip.php
 * (1.7.0, commit b3f15f8) with the prefix renamed and two plugin-specific
 * option names (`trust_all_forwarding` here, `trust_all_proxy_headers`
 * there). Fix bugs in both; do not let the two drift. Compare with:
 *   diff <(sed -e 's/WCAF_/BSR_/g' -e "s/'wcaf_/'bsr_/g" -e 's/WC_Antifraud::/Bot_Storm_Radar::/' \
 *          -e 's/trust_all_proxy_headers/trust_all_forwarding/' ../../../WC_Antifraud/www/wc-antifraud/includes/class-wcaf-client-ip.php) \
 *        includes/class-bsr-client-ip.php
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Client_IP {

	/**
	 * Option holding the fetched Cloudflare ranges: [ 'ranges' => [...], 'fetched' => ts ].
	 */
	const CF_OPTION = 'bsr_cloudflare_ips';

	/**
	 * Bundled fallback, relative to the plugin root.
	 */
	const CF_BUNDLED = 'assets/data/cloudflare-ips.txt';

	/**
	 * Published sources (verified 2026-09-02: plain text, one CIDR per line).
	 */
	const CF_URL_V4 = 'https://www.cloudflare.com/ips-v4';
	const CF_URL_V6 = 'https://www.cloudflare.com/ips-v6';

	/**
	 * Daily refresh hook.
	 */
	const CRON_HOOK = 'bsr_refresh_cloudflare_ips';

	/**
	 * Option recording a suspected undeclared public-address proxy.
	 */
	const SUSPECT_OPTION = 'bsr_proxy_suspect';

	/**
	 * Transient set when the admin says the suspect is not a proxy (30 days).
	 */
	const DISMISS_TRANSIENT = 'bsr_proxy_suspect_dismissed';

	/**
	 * Admin requests showing the pattern before the automatic IP rules are suspended.
	 */
	const SUSPECT_HITS = 5;

	/**
	 * Which rule produced the last resolution (for the settings diagnostic).
	 */
	private static $source = '';

	public static function init() {
		add_action( self::CRON_HOOK, [ __CLASS__, 'refresh_cloudflare_ranges' ] );
		if ( is_admin() ) {
			add_action( 'admin_init', [ __CLASS__, 'ensure_cron' ], 4 );
			add_action( 'admin_init', [ __CLASS__, 'detect_public_proxy' ], 5 );
		}
	}

	// ── Resolution ────────────────────────────────────────────────────

	/**
	 * The client IP for this request, or false when none can be established.
	 *
	 * @return string|false
	 */
	public static function resolve() {
		$opts   = BSR_Helpers::get_options();
		$remote = self::header_ip( 'REMOTE_ADDR' );

		if ( ! empty( $opts['trust_all_forwarding'] ) ) {
			self::$source   = 'legacy';
			return self::resolve_legacy( $remote );
		}

		if ( '' === $remote ) {
			self::$source   = 'none';
			return false;
		}

		if ( self::is_cloudflare_ip( $remote ) ) {
			$cf = self::header_ip( 'HTTP_CF_CONNECTING_IP' );
			if ( '' !== $cf && BSR_Helpers::is_public_ip( $cf ) ) {
				self::$source   = 'cloudflare';
				return $cf;
			}
			self::$source   = 'cloudflare-forwarded';
			return self::forwarded_client( $remote );
		}

		if ( ! BSR_Helpers::is_public_ip( $remote ) ) {
			self::$source   = 'local-proxy';
			return self::forwarded_client( $remote );
		}

		if ( self::is_declared_proxy( $remote ) ) {
			self::$source   = 'trusted-proxy';
			return self::forwarded_client( $remote );
		}

		self::$source   = 'direct';
		return $remote;
	}

	/**
	 * Which rule produced the resolved IP (for the settings diagnostic).
	 *
	 * @return string
	 */
	public static function source() {
		self::resolve();
		return self::$source;
	}

	/**
	 * Walk X-Forwarded-For from the right and take the first public entry that
	 * is not itself a proxy we trust; then X-Real-IP; then the peer address.
	 *
	 * @param string $remote
	 * @return string
	 */
	private static function forwarded_client( $remote ) {
		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' !== $xff ) {
			$entries = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
			foreach ( $entries as $entry ) {
				$ip = self::normalize( $entry );
				if ( '' === $ip || ! BSR_Helpers::is_public_ip( $ip ) ) {
					continue;
				}
				if ( self::is_cloudflare_ip( $ip ) || self::is_declared_proxy( $ip ) ) {
					continue;
				}
				return $ip;
			}
		}
		$real = self::header_ip( 'HTTP_X_REAL_IP' );
		if ( '' !== $real && BSR_Helpers::is_public_ip( $real ) ) {
			return $real;
		}
		return $remote;
	}

	/**
	 * Pre-1.7.0 behavior: first forwarding header wins, whoever sent it.
	 *
	 * @param string $remote
	 * @return string|false
	 */
	private static function resolve_legacy( $remote ) {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ] as $k ) {
			if ( empty( $_SERVER[ $k ] ) ) {
				continue;
			}
			$val = sanitize_text_field( wp_unslash( $_SERVER[ $k ] ) );
			if ( 'HTTP_X_FORWARDED_FOR' === $k ) {
				$parts = explode( ',', $val );
				$val   = $parts[0];
			}
			$ip = self::normalize( $val );
			return '' !== $ip ? $ip : false;
		}
		return '' !== $remote ? $remote : false;
	}

	/**
	 * A validated IP from one $_SERVER key, or ''.
	 *
	 * @param string $key
	 * @return string
	 */
	private static function header_ip( $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			return '';
		}
		return self::normalize( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
	}

	/**
	 * Strip a port or brackets and validate. Returns '' when not an IP.
	 *
	 * @param string $ip
	 * @return string
	 */
	public static function normalize( $ip ) {
		$ip = trim( (string) $ip );
		if ( preg_match( '/^\[(.+)\]:\d+$/', $ip, $m ) ) {
			$ip = $m[1];
		} elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $ip, $m ) ) {
			$ip = $m[1];
		}
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	// ── Trusted proxies ───────────────────────────────────────────────

	/**
	 * @param string $ip
	 * @return bool
	 */
	public static function is_cloudflare_ip( $ip ) {
		return BSR_Helpers::ip_in_list( $ip, self::cloudflare_ranges() );
	}

	/**
	 * @param string $ip
	 * @return bool
	 */
	public static function is_declared_proxy( $ip ) {
		$opts = BSR_Helpers::get_options();
		return BSR_Helpers::ip_in_list( $ip, $opts['trusted_proxies'] ?? '' );
	}

	/**
	 * Cloudflare ranges: the fetched set when present, else the bundled file.
	 *
	 * @return array
	 */
	public static function cloudflare_ranges() {
		static $ranges = null;
		if ( null !== $ranges ) {
			return $ranges;
		}
		$stored = get_option( self::CF_OPTION, [] );
		if ( is_array( $stored ) && ! empty( $stored['ranges'] ) && is_array( $stored['ranges'] ) ) {
			$ranges = $stored['ranges'];
			return $ranges;
		}
		$ranges = self::bundled_cloudflare_ranges();
		return $ranges;
	}

	/**
	 * When the fetched set was last refreshed, or 0 when running on the bundle.
	 *
	 * @return int
	 */
	public static function cloudflare_ranges_fetched_at() {
		$stored = get_option( self::CF_OPTION, [] );
		return is_array( $stored ) && ! empty( $stored['ranges'] ) ? (int) ( $stored['fetched'] ?? 0 ) : 0;
	}

	/**
	 * @return array
	 */
	private static function bundled_cloudflare_ranges() {
		$path = BSR_PLUGIN_DIR . self::CF_BUNDLED;
		if ( ! is_readable( $path ) ) {
			return [];
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return false === $lines ? [] : self::clean_cidr_lines( $lines );
	}

	/**
	 * Keep only well-formed CIDR lines.
	 *
	 * @param array $lines
	 * @return array
	 */
	private static function clean_cidr_lines( $lines ) {
		$out = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( preg_match( '#^([0-9a-fA-F:.]+)/(\d{1,3})$#', $line, $m ) && false !== filter_var( $m[1], FILTER_VALIDATE_IP ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Daily cron: fetch both lists; keep the previous set on any failure.
	 *
	 * @return bool True when a fresh set was stored.
	 */
	public static function refresh_cloudflare_ranges() {
		$ranges = [];
		foreach ( [ self::CF_URL_V4, self::CF_URL_V6 ] as $url ) {
			$response = wp_remote_get( $url, [ 'timeout' => 10 ] );
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}
			$lines = preg_split( '/\r\n|\r|\n/', (string) wp_remote_retrieve_body( $response ) );
			$clean = self::clean_cidr_lines( $lines );
			if ( empty( $clean ) ) {
				return false;
			}
			$ranges = array_merge( $ranges, $clean );
		}
		update_option( self::CF_OPTION, [ 'ranges' => $ranges, 'fetched' => time() ], false );
		return true;
	}

	/**
	 * Make sure the daily refresh is scheduled (activation does it; this covers
	 * upgrades that never re-activate).
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	// ── Undeclared public-address proxy detection ─────────────────────

	/**
	 * On ordinary admin page loads, notice the pattern of a public-address
	 * proxy that has not been declared: public REMOTE_ADDR that is neither
	 * Cloudflare nor declared, plus a forwarding header naming a different
	 * public address. Repeated sightings suspend the automatic IP rules.
	 */
	public static function detect_public_proxy() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$opts = BSR_Helpers::get_options();
		if ( ! empty( $opts['trust_all_forwarding'] ) ) {
			return;
		}
		$remote = self::header_ip( 'REMOTE_ADDR' );
		if ( '' === $remote || ! BSR_Helpers::is_public_ip( $remote ) ) {
			return;
		}
		if ( self::is_cloudflare_ip( $remote ) || self::is_declared_proxy( $remote ) ) {
			return;
		}
		$forwarded = '';
		$xff       = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		if ( '' !== $xff ) {
			$parts     = array_map( 'trim', explode( ',', $xff ) );
			$forwarded = self::normalize( end( $parts ) );
		}
		if ( '' === $forwarded ) {
			$forwarded = self::header_ip( 'HTTP_X_REAL_IP' );
		}
		if ( '' === $forwarded || $forwarded === $remote || ! BSR_Helpers::is_public_ip( $forwarded ) ) {
			return;
		}
		if ( get_transient( self::DISMISS_TRANSIENT ) === $remote ) {
			return;
		}

		$suspect = get_option( self::SUSPECT_OPTION, [] );
		if ( ! is_array( $suspect ) || ( $suspect['ip'] ?? '' ) !== $remote ) {
			$suspect = [ 'ip' => $remote, 'hits' => 0, 'first' => time(), 'last' => 0, 'forwarded' => $forwarded ];
		}
		// One sighting per minute is enough; admin screens fire many requests.
		if ( time() - (int) $suspect['last'] < MINUTE_IN_SECONDS ) {
			return;
		}
		$suspect['hits']      = (int) $suspect['hits'] + 1;
		$suspect['last']      = time();
		$suspect['forwarded'] = $forwarded;
		update_option( self::SUSPECT_OPTION, $suspect, false );
	}

	/**
	 * The suspected proxy record, or null.
	 *
	 * @return array|null
	 */
	public static function suspect() {
		$s = get_option( self::SUSPECT_OPTION, [] );
		return is_array( $s ) && ! empty( $s['ip'] ) ? $s : null;
	}

	/**
	 * Whether anything keyed by IP should currently stand down (and the
	 * dashboard warn that per-address metrics are unreliable).
	 *
	 * @return bool
	 */
	public static function ip_rules_suspended() {
		$s = self::suspect();
		return null !== $s && (int) $s['hits'] >= self::SUSPECT_HITS;
	}

	/**
	 * Admin confirmed the suspect is a proxy: declare it and clear the record.
	 */
	public static function trust_suspect() {
		$s = self::suspect();
		if ( null === $s ) {
			return;
		}
		$opts                    = BSR_Helpers::get_options();
		$opts['trusted_proxies'] = trim( (string) $opts['trusted_proxies'] . "\n" . $s['ip'] );
		update_option( Bot_Storm_Radar::OPTION_KEY, $opts );
		delete_option( self::SUSPECT_OPTION );
	}

	/**
	 * Admin said the suspect is not a proxy in front of the site: forget it for 30 days.
	 */
	public static function dismiss_suspect() {
		$s = self::suspect();
		if ( null !== $s ) {
			set_transient( self::DISMISS_TRANSIENT, $s['ip'], 30 * DAY_IN_SECONDS );
		}
		delete_option( self::SUSPECT_OPTION );
	}
}
