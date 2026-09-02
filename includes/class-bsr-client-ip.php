<?php
/**
 * Client IP resolution with a trusted-proxy model (design v1, decision 13.1).
 *
 * REMOTE_ADDR is the client unless it belongs to a trusted proxy:
 *  1. Cloudflare ranges, fetched daily and bundled as a fallback. Behind
 *     Cloudflare the client is CF-Connecting-IP.
 *  2. A private, link-local, or loopback REMOTE_ADDR cannot be an internet
 *     client, so it is a local proxy by definition. Walk X-Forwarded-For from
 *     the right and take the first entry that is not private and not itself a
 *     trusted proxy; fall back to X-Real-IP, then REMOTE_ADDR.
 *  3. Owner-declared proxies (public addresses of an external load balancer
 *     or a CDN not on the built-in list), same right-to-left walk.
 *
 * Nothing else is believed. The legacy "trust all forwarding headers" switch
 * restores the old, spoofable behavior for owners who are stuck.
 *
 * This class is shared with WC Antifraud 1.7.0 by copying (WCAF_ prefix);
 * keep the two in sync rather than diverging.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Client_IP {

	const CLOUDFLARE_OPTION = 'bsr_cloudflare_ranges';
	const CLOUDFLARE_V4_URL = 'https://www.cloudflare.com/ips-v4';
	const CLOUDFLARE_V6_URL = 'https://www.cloudflare.com/ips-v6';
	const CLOUDFLARE_FILE   = 'cloudflare-ips.txt';
	const REFRESH_HOOK      = 'bsr_refresh_ip_lists';

	/**
	 * Proxy detection state: how many consecutive admin requests must share
	 * one public REMOTE_ADDR with a forwarding header before we conclude an
	 * undeclared proxy sits in front of the site.
	 */
	const DETECT_OPTION = 'bsr_proxy_detect';
	const DETECT_AFTER  = 5;

	/**
	 * @var string|null
	 */
	private static $resolved = null;

	/**
	 * @var string|null Why the resolved address was chosen (for the dashboard).
	 */
	private static $source = null;

	public static function init() {
		add_action( self::REFRESH_HOOK, [ __CLASS__, 'refresh_lists' ] );
		if ( is_admin() ) {
			add_action( 'admin_init', [ __CLASS__, 'observe_admin_request' ] );
		}
	}

	public static function schedule_refresh() {
		if ( ! wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_event( time() + 300, 'daily', self::REFRESH_HOOK );
		}
	}

	public static function unschedule_refresh() {
		$ts = wp_next_scheduled( self::REFRESH_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::REFRESH_HOOK );
		}
	}

	// ── Resolution ──────────────────────────────────────────────────

	/**
	 * The client address for this request, resolved once.
	 *
	 * @return string "" when nothing usable exists (CLI).
	 */
	public static function get() {
		if ( null === self::$resolved ) {
			self::$resolved = self::resolve( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		return self::$resolved;
	}

	/**
	 * @return string One of remote_addr, cloudflare, local_proxy, declared_proxy, legacy, none.
	 */
	public static function source() {
		self::get();
		return (string) self::$source;
	}

	/**
	 * Forget the per-request result (tests).
	 */
	public static function reset() {
		self::$resolved = null;
		self::$source   = null;
	}

	/**
	 * Pure resolution over a server array, so tests can feed arbitrary
	 * combinations without touching $_SERVER.
	 *
	 * @param array $server
	 * @param array|null $opts Plugin options (defaults to the stored ones).
	 * @return string
	 */
	public static function resolve( array $server, $opts = null ) {
		$opts   = null === $opts ? BSR_Helpers::get_options() : $opts;
		$remote = isset( $server['REMOTE_ADDR'] ) ? trim( (string) $server['REMOTE_ADDR'] ) : '';
		if ( ! BSR_Helpers::is_valid_ip( $remote ) ) {
			self::$source = 'none';
			return '';
		}

		// Legacy, spoofable: first header found wins.
		if ( ! empty( $opts['trust_all_forwarding'] ) ) {
			foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ] as $k ) {
				if ( empty( $server[ $k ] ) ) {
					continue;
				}
				$val = trim( (string) $server[ $k ] );
				if ( 'HTTP_X_FORWARDED_FOR' === $k ) {
					$val = trim( explode( ',', $val )[0] );
				}
				if ( BSR_Helpers::is_valid_ip( $val ) ) {
					self::$source = 'legacy';
					return $val;
				}
			}
			self::$source = 'remote_addr';
			return $remote;
		}

		// 1. Cloudflare.
		if ( self::is_cloudflare( $remote ) ) {
			$cf = isset( $server['HTTP_CF_CONNECTING_IP'] ) ? trim( (string) $server['HTTP_CF_CONNECTING_IP'] ) : '';
			if ( BSR_Helpers::is_valid_ip( $cf ) ) {
				self::$source = 'cloudflare';
				return $cf;
			}
			// Cloudflare edge without the header is not a thing; fall through to
			// the generic walk so a misconfigured setup still yields something.
		}

		// 2. Local proxy, 3. declared proxy.
		$local    = BSR_Helpers::is_private_ip( $remote );
		$declared = ! $local && self::is_declared_proxy( $remote, $opts );
		if ( $local || $declared ) {
			$xff = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? (string) $server['HTTP_X_FORWARDED_FOR'] : '';
			if ( '' !== $xff ) {
				$hops = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
				foreach ( $hops as $hop ) {
					if ( ! BSR_Helpers::is_valid_ip( $hop ) ) {
						continue;
					}
					if ( BSR_Helpers::is_private_ip( $hop ) || self::is_cloudflare( $hop ) || self::is_declared_proxy( $hop, $opts ) ) {
						continue;
					}
					self::$source = $local ? 'local_proxy' : 'declared_proxy';
					return $hop;
				}
			}
			$real = isset( $server['HTTP_X_REAL_IP'] ) ? trim( (string) $server['HTTP_X_REAL_IP'] ) : '';
			if ( BSR_Helpers::is_valid_ip( $real ) && ! BSR_Helpers::is_private_ip( $real ) ) {
				self::$source = $local ? 'local_proxy' : 'declared_proxy';
				return $real;
			}
		}

		self::$source = 'remote_addr';
		return $remote;
	}

	/**
	 * @param string $ip
	 * @param array  $opts
	 * @return bool
	 */
	public static function is_declared_proxy( $ip, $opts ) {
		return BSR_Helpers::ip_in_list( $ip, $opts['trusted_proxies'] ?? '' );
	}

	/**
	 * @param string $ip
	 * @return bool
	 */
	public static function is_cloudflare( $ip ) {
		return BSR_Helpers::ip_in_list( $ip, self::cloudflare_ranges() );
	}

	/**
	 * Whether this request arrived through Cloudflare's proxy (orange cloud).
	 *
	 * @return bool
	 */
	public static function behind_cloudflare() {
		self::get();
		return 'cloudflare' === self::$source;
	}

	/**
	 * Cloudflare ranges: the fetched option when present, the bundled file
	 * otherwise. Cached per request.
	 *
	 * @return array
	 */
	public static function cloudflare_ranges() {
		static $ranges = null;
		if ( null !== $ranges ) {
			return $ranges;
		}
		$stored = get_option( self::CLOUDFLARE_OPTION );
		if ( is_array( $stored ) && ! empty( $stored['ranges'] ) ) {
			$ranges = $stored['ranges'];
		} else {
			$ranges = BSR_Helpers::bundled_list( self::CLOUDFLARE_FILE );
		}
		return $ranges;
	}

	/**
	 * @return array {source: fetched|bundled, fetched_at: int|null, count: int}
	 */
	public static function cloudflare_status() {
		$stored = get_option( self::CLOUDFLARE_OPTION );
		if ( is_array( $stored ) && ! empty( $stored['ranges'] ) ) {
			return [
				'source'     => 'fetched',
				'fetched_at' => (int) ( $stored['fetched_at'] ?? 0 ),
				'count'      => count( $stored['ranges'] ),
			];
		}
		return [
			'source'     => 'bundled',
			'fetched_at' => null,
			'count'      => count( BSR_Helpers::bundled_list( self::CLOUDFLARE_FILE ) ),
		];
	}

	/**
	 * Daily cron: refresh the Cloudflare ranges (and the DuckDuckBot prefixes
	 * through BSR_Good_Bots). Keeps the previous copy when a fetch fails.
	 */
	public static function refresh_lists() {
		$ranges = [];
		foreach ( [ self::CLOUDFLARE_V4_URL, self::CLOUDFLARE_V6_URL ] as $url ) {
			$body = self::fetch_text( $url );
			if ( null === $body ) {
				$ranges = [];
				break;
			}
			foreach ( BSR_Helpers::parse_list( $body ) as $entry ) {
				if ( false !== strpos( $entry, '/' ) && BSR_Helpers::is_valid_ip( explode( '/', $entry )[0] ) ) {
					$ranges[] = $entry;
				}
			}
		}
		if ( count( $ranges ) >= 10 ) {
			update_option( self::CLOUDFLARE_OPTION, [ 'ranges' => $ranges, 'fetched_at' => time() ], false );
		}
		if ( class_exists( 'BSR_Good_Bots' ) ) {
			BSR_Good_Bots::refresh_ip_lists();
		}
	}

	/**
	 * @param string $url
	 * @return string|null
	 */
	public static function fetch_text( $url ) {
		$r = wp_remote_get( $url, [ 'timeout' => 10, 'user-agent' => 'BotStormRadar/' . BSR_VERSION ] );
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			return null;
		}
		$body = wp_remote_retrieve_body( $r );
		return is_string( $body ) && '' !== $body ? $body : null;
	}

	// ── Undeclared public proxy detection ───────────────────────────

	/**
	 * On admin requests, notice the "every request from one public address
	 * with a forwarding header" pattern. Only concerns a public-address proxy
	 * that is neither Cloudflare nor declared; local proxies never get here.
	 */
	public static function observe_admin_request() {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! BSR_Helpers::is_valid_ip( $remote ) || BSR_Helpers::is_private_ip( $remote ) ) {
			return;
		}
		$opts = BSR_Helpers::get_options();
		if ( ! empty( $opts['trust_all_forwarding'] ) || self::is_cloudflare( $remote ) || self::is_declared_proxy( $remote, $opts ) ) {
			if ( get_option( self::DETECT_OPTION ) ) {
				delete_option( self::DETECT_OPTION );
			}
			return;
		}
		$has_fwd = ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) || ! empty( $_SERVER['HTTP_X_REAL_IP'] );
		$state   = get_option( self::DETECT_OPTION );
		$state   = is_array( $state ) ? $state : [ 'ip' => '', 'streak' => 0 ];

		if ( ! $has_fwd || $state['ip'] !== $remote ) {
			$new = [ 'ip' => $has_fwd ? $remote : '', 'streak' => $has_fwd ? 1 : 0, 'seen' => time() ];
		} else {
			$new = [ 'ip' => $remote, 'streak' => min( 1000, (int) $state['streak'] + 1 ), 'seen' => time() ];
		}
		if ( $new !== $state ) {
			update_option( self::DETECT_OPTION, $new, false );
		}
	}

	/**
	 * The detected undeclared proxy address, or "" when none.
	 *
	 * @return string
	 */
	public static function detected_proxy() {
		$state = get_option( self::DETECT_OPTION );
		if ( is_array( $state ) && ! empty( $state['ip'] ) && (int) $state['streak'] >= self::DETECT_AFTER ) {
			return (string) $state['ip'];
		}
		return '';
	}

	/**
	 * While an undeclared proxy is detected every visitor looks like one
	 * address, so anything keyed by IP must stand down. Nothing enforces in
	 * v0.1; the flag is exposed for the dashboard and for the actions layer.
	 *
	 * @return bool
	 */
	public static function ip_keying_suspended() {
		return '' !== self::detected_proxy();
	}

	/**
	 * One-click "trust this proxy": append the detected address to the
	 * declared list and clear the detection state.
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function declare_proxy( $ip ) {
		if ( ! BSR_Helpers::is_valid_ip( $ip ) ) {
			return false;
		}
		$opts = get_option( Bot_Storm_Radar::OPTION_KEY, [] );
		$opts = is_array( $opts ) ? $opts : [];
		$list = BSR_Helpers::parse_list( $opts['trusted_proxies'] ?? '' );
		if ( ! in_array( $ip, $list, true ) ) {
			$list[] = $ip;
		}
		$opts['trusted_proxies'] = implode( "\n", $list );
		update_option( Bot_Storm_Radar::OPTION_KEY, $opts );
		delete_option( self::DETECT_OPTION );
		BSR_Helpers::flush_options();
		return true;
	}
}
