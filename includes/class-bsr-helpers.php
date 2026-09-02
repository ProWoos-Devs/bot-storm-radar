<?php
/**
 * Static helpers: options, IP arithmetic, list parsing.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Helpers {

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
		return is_string( $ip ) && false !== filter_var( $ip, FILTER_VALIDATE_IP );
	}

	/**
	 * A routable internet address: valid, not private (RFC 1918, ULA), not
	 * reserved (loopback, link-local, documentation, multicast), and not
	 * carrier-grade NAT (100.64.0.0/10, which filter_var does not cover).
	 * Same rule as WCAF_Helpers::is_public_ip().
	 *
	 * @param string $ip
	 * @return bool
	 */
	public static function is_public_ip( $ip ) {
		if ( empty( $ip ) || false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
		return ! self::ip_in_cidr( $ip, '100.64.0.0/10' );
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
	 * CIDR match for IPv4 and IPv6, via inet_pton and a bit mask.
	 *
	 * @param string $ip
	 * @param string $cidr "a.b.c.d/nn", "x::/nn", or a bare address.
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		$cidr = trim( $cidr );
		if ( '' === $cidr ) {
			return false;
		}
		if ( false === strpos( $cidr, '/' ) ) {
			return self::is_valid_ip( $cidr ) && self::is_valid_ip( $ip ) && inet_pton( $ip ) === inet_pton( $cidr );
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		if ( ! self::is_valid_ip( $subnet ) || ! self::is_valid_ip( $ip ) || ! is_numeric( $bits ) ) {
			return false;
		}
		$ip_bin  = inet_pton( $ip );
		$net_bin = inet_pton( $subnet );
		if ( false === $ip_bin || false === $net_bin || strlen( $ip_bin ) !== strlen( $net_bin ) ) {
			return false; // IPv4 against IPv6 or vice versa.
		}
		$bits = (int) $bits;
		$max  = strlen( $ip_bin ) * 8;
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}
		$full_bytes = intdiv( $bits, 8 );
		$rest_bits  = $bits % 8;
		if ( $full_bytes > 0 && substr( $ip_bin, 0, $full_bytes ) !== substr( $net_bin, 0, $full_bytes ) ) {
			return false;
		}
		if ( 0 === $rest_bits ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest_bits ) ) & 0xFF;
		return ( ord( $ip_bin[ $full_bytes ] ) & $mask ) === ( ord( $net_bin[ $full_bytes ] ) & $mask );
	}

	/**
	 * @param string       $ip
	 * @param string|array $list Newline-separated text or an array of entries.
	 * @return bool
	 */
	public static function ip_in_list( $ip, $list ) {
		if ( ! self::is_valid_ip( $ip ) ) {
			return false;
		}
		foreach ( self::parse_list( $list ) as $entry ) {
			if ( self::ip_in_cidr( $ip, $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Split a textarea or file into trimmed, non-empty, non-comment entries.
	 *
	 * @param string|array $list
	 * @return array
	 */
	public static function parse_list( $list ) {
		if ( is_array( $list ) ) {
			$entries = $list;
		} elseif ( is_string( $list ) && '' !== $list ) {
			$entries = preg_split( '/\r\n|\r|\n|,/', $list );
		} else {
			return [];
		}
		$out = [];
		foreach ( $entries as $e ) {
			$e = trim( (string) $e );
			if ( '' === $e || '#' === $e[0] ) {
				continue;
			}
			$out[] = $e;
		}
		return $out;
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
		$path = BSR_PLUGIN_DIR . 'assets/data/' . $file;
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
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$q   = strpos( $uri, '?' );
		return false === $q ? $uri : substr( $uri, 0, $q );
	}

	/**
	 * @return string
	 */
	public static function user_agent() {
		return isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 512 ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}
}
