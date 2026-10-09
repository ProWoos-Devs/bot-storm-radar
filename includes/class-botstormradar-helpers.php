<?php
/**
 * Static helpers: options, IP arithmetic, list parsing.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Helpers {

	/**
	 * Options merged with defaults, read once per request.
	 *
	 * @var array|null
	 */
	private static $options = null;

	/**
	 * @return array
	 */
	public static function get_options() {
		if ( null === self::$options ) {
			$stored        = get_option( Bot_Storm_Radar::OPTION_KEY, [] );
			self::$options = wp_parse_args( is_array( $stored ) ? $stored : [], Bot_Storm_Radar::get_default_options() );
		}
		return self::$options;
	}

	/**
	 * Drop the per-request options cache (tests and the settings save path).
	 */
	public static function flush_options() {
		self::$options = null;
	}

	/**
	 * @param string $name
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function opt( $name, $default = null ) {
		$o = self::get_options();
		return array_key_exists( $name, $o ) ? $o[ $name ] : $default;
	}

	// ── IP arithmetic ───────────────────────────────────────────────

	/**
	 * @param string $ip
	 * @return bool
	 */
	public static function is_valid_ip( $ip ) {
		return BotStormRadar_IP_Resolver::is_valid_ip( $ip );
	}

	/**
	 * A routable internet address (see BotStormRadar_IP_Resolver::is_public_ip()).
	 * Same rule as WCAF_Helpers::is_public_ip().
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		return BotStormRadar_IP_Resolver::is_public_ip( $ip );
	}

	/**
	 * An address that cannot belong to an internet client (the complement of
	 * is_public_ip, invalid input included).
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function is_private_ip( $ip ) {
		return ! self::is_public_ip( $ip );
	}

	/**
	 * CIDR match for IPv4 and IPv6 (see BotStormRadar_IP_Resolver::ip_in_cidr()).
	 *
	 * @param string $ip
	 * @param string $cidr "a.b.c.d/nn", "x::/nn", or a bare address.
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		return BotStormRadar_IP_Resolver::ip_in_cidr( $ip, $cidr );
	}

	/**
	 * @param string       $ip
	 * @param string|array $list Newline-separated text or an array of entries.
	 * @return bool
	 */
	public static function ip_in_list( $ip, $list ) {
		return BotStormRadar_IP_Resolver::ip_in_list( $ip, $list );
	}

	/**
	 * Split a textarea or file into trimmed, non-empty, non-comment entries.
	 *
	 * @param string|array $list
	 * @return array
	 */
	public static function parse_list( $list ) {
		return BotStormRadar_IP_Resolver::parse_list( $list );
	}

	/**
	 * Network key for swarm counting: IPv4 /24, IPv6 /48.
	 *
	 * @param string $ip
	 * @return string "" when the address is invalid.
	 */
	public static function network_key( $ip ) {
		if ( ! self::is_valid_ip( $ip ) ) {
			return '';
		}
		if ( false !== strpos( $ip, ':' ) ) {
			$bin = inet_pton( $ip );
			if ( false === $bin ) {
				return '';
			}
			return 'n48:' . bin2hex( substr( $bin, 0, 6 ) );
		}
		$parts = explode( '.', $ip );
		return 'n24:' . $parts[0] . '.' . $parts[1] . '.' . $parts[2];
	}

	/**
	 * Short stable hash for user agents and session ids (never reversible,
	 * never stored with the original).
	 *
	 * @param string $s
	 * @return string 16 hex chars.
	 */
	public static function short_hash( $s ) {
		return substr( hash( 'sha256', (string) $s ), 0, 16 );
	}

	/**
	 * @param string $emails Comma-separated.
	 * @return array
	 */
	public static function sanitize_email_list( $emails ) {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) $emails ) ), 'is_email' ) );
	}

	/**
	 * Read a bundled list file from assets/data.
	 *
	 * @param string $file
	 * @return array
	 */
	public static function bundled_list( $file ) {
		$path = BOTSTORMRADAR_PLUGIN_DIR . 'assets/data/' . $file;
		if ( ! is_readable( $path ) ) {
			return [];
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return false === $lines ? [] : self::parse_list( $lines );
	}

	/**
	 * Minute bucket id for a timestamp.
	 *
	 * @param int|null $ts
	 * @return int
	 */
	public static function minute( $ts = null ) {
		return intdiv( null === $ts ? time() : (int) $ts, 60 );
	}

	/**
	 * @param float $v
	 * @param float $lo
	 * @param float $hi
	 * @return float
	 */
	public static function clamp( $v, $lo, $hi ) {
		return max( $lo, min( $hi, (float) $v ) );
	}

	/**
	 * @param string $path
	 * @param int    $flags
	 * @return string
	 */
	public static function request_path() {
		$uri = self::request_uri();
		$q   = strpos( $uri, '?' );
		return false === $q ? $uri : substr( $uri, 0, $q );
	}

	/**
	 * The request URI, sanitized as a URL. It keeps its percent-encoding, so
	 * an encoded probe is still recognized. At most 2048 bytes; '/' when
	 * there is none.
	 *
	 * @return string
	 */
	public static function request_uri() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? substr( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 2048 ) : '';
		return '' === $uri ? '/' : $uri;
	}

	/**
	 * @return string
	 */
	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) && is_string( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 ) : '';
	}
}
