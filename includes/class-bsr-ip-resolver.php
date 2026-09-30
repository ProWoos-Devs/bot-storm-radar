<?php
/**
 * Client address resolution without WordPress.
 *
 * The decision logic of BSR_Client_IP, as pure functions of the server
 * variables and a trust configuration, so the gate can resolve the visitor
 * before WordPress loads and get exactly the answer the radar gets. No
 * option, no WordPress function, no state. BSR_Client_IP calls it with the
 * options; the gate will call it with the trust configuration from its
 * state file.
 *
 * Trust configuration:
 *   cloudflare => array of CIDR ranges (fetched daily, bundled fallback)
 *   proxies    => array of CIDR ranges or addresses declared by the owner
 *   trust_all  => bool, the legacy "trust all forwarding headers" switch
 *
 * The pure IP helpers (validation, public address, CIDR match, lists) live
 * here too; BSR_Helpers delegates to them.
 *
 * SHARED CODE. WC Antifraud has the same split (class-wcaf-ip-resolver.php);
 * fix bugs in both. Compare with:
 *   diff <(sed -e 's/WCAF_/BSR_/g' -e 's/WCAF_GATE/BSR_GATE/' ../../../WC_Antifraud/www/wc-antifraud/includes/class-wcaf-ip-resolver.php) \
 *        includes/class-bsr-ip-resolver.php
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress or by the gate, which defines BSR_GATE first.
if ( ! defined( 'ABSPATH' ) && ! defined( 'BSR_GATE' ) ) {
	exit;
}

class BSR_IP_Resolver {

	/**
	 * Resolve the client address.
	 *
	 * @param array $server Server variables ($_SERVER or a synthetic array).
	 * @param array $trust  cloudflare, proxies, trust_all.
	 * @return array {ip: string|false, source: string}
	 */
	public static function resolve( array $server, array $trust ) {
		$cf_ranges = isset( $trust['cloudflare'] ) && is_array( $trust['cloudflare'] ) ? $trust['cloudflare'] : [];
		$proxies   = isset( $trust['proxies'] ) ? self::parse_list( $trust['proxies'] ) : [];
		$remote    = self::header_ip( $server, 'REMOTE_ADDR' );

		if ( ! empty( $trust['trust_all'] ) ) {
			return [ 'ip' => self::resolve_legacy( $server, $remote ), 'source' => 'legacy' ];
		}

		if ( '' === $remote ) {
			return [ 'ip' => false, 'source' => 'none' ];
		}

		if ( self::ip_in_list( $remote, $cf_ranges ) ) {
			$cf = self::header_ip( $server, 'HTTP_CF_CONNECTING_IP' );
			if ( '' !== $cf && self::is_public_ip( $cf ) ) {
				return [ 'ip' => $cf, 'source' => 'cloudflare' ];
			}
			return [ 'ip' => self::forwarded_client( $server, $remote, $cf_ranges, $proxies ), 'source' => 'cloudflare-forwarded' ];
		}

		if ( ! self::is_public_ip( $remote ) ) {
			return [ 'ip' => self::forwarded_client( $server, $remote, $cf_ranges, $proxies ), 'source' => 'local-proxy' ];
		}

		if ( self::ip_in_list( $remote, $proxies ) ) {
			return [ 'ip' => self::forwarded_client( $server, $remote, $cf_ranges, $proxies ), 'source' => 'trusted-proxy' ];
		}

		return [ 'ip' => $remote, 'source' => 'direct' ];
	}

	/**
	 * Walk X-Forwarded-For from the right and take the first public entry that
	 * is not itself a proxy we trust; then X-Real-IP; then the peer address.
	 *
	 * @param array  $server
	 * @param string $remote
	 * @param array  $cf_ranges
	 * @param array  $proxies
	 * @return string
	 */
	private static function forwarded_client( array $server, $remote, array $cf_ranges, array $proxies ) {
		$xff = self::header( $server, 'HTTP_X_FORWARDED_FOR' );
		if ( '' !== $xff ) {
			$entries = array_reverse( array_map( 'trim', explode( ',', $xff ) ) );
			foreach ( $entries as $entry ) {
				$ip = self::normalize( $entry );
				if ( '' === $ip || ! self::is_public_ip( $ip ) ) {
					continue;
				}
				if ( self::ip_in_list( $ip, $cf_ranges ) || self::ip_in_list( $ip, $proxies ) ) {
					continue;
				}
				return $ip;
			}
		}
		$real = self::header_ip( $server, 'HTTP_X_REAL_IP' );
		if ( '' !== $real && self::is_public_ip( $real ) ) {
			return $real;
		}
		return $remote;
	}

	/**
	 * The legacy behavior: first forwarding header wins, whoever sent it.
	 *
	 * @param array  $server
	 * @param string $remote
	 * @return string|false
	 */
	private static function resolve_legacy( array $server, $remote ) {
		foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP' ] as $k ) {
			$val = self::header( $server, $k );
			if ( '' === $val ) {
				continue;
			}
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
	 * One raw server variable as a trimmed string ('' when absent). Values
	 * are only ever used after normalize() validates them as an address.
	 *
	 * @param array  $server
	 * @param string $key
	 * @return string
	 */
	private static function header( array $server, $key ) {
		if ( empty( $server[ $key ] ) || ! is_scalar( $server[ $key ] ) ) {
			return '';
		}
		return trim( stripslashes( (string) $server[ $key ] ) );
	}

	/**
	 * A validated address from one server variable, or ''.
	 *
	 * @param array  $server
	 * @param string $key
	 * @return string
	 */
	private static function header_ip( array $server, $key ) {
		return self::normalize( self::header( $server, $key ) );
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

	// ── Pure IP helpers (BSR_Helpers delegates here) ─────────────────

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
	 * CIDR match for IPv4 and IPv6, via inet_pton and a bit mask.
	 *
	 * @param string $ip
	 * @param string $cidr "a.b.c.d/nn", "x::/nn", or a bare address.
	 * @return bool
	 */
	public static function ip_in_cidr( $ip, $cidr ) {
		$cidr = trim( (string) $cidr );
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
}
