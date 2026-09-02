<?php
/**
 * Good-bot verification (design v1, 5.4). Googlebot, Bingbot, Applebot and
 * Yandex are verified by reverse DNS plus forward confirmation; DuckDuckBot
 * publishes no reverse-DNS convention, only an address list, so it is
 * verified against that list (bundled, refreshed daily). Verdicts are cached
 * per IP for a day. A claim is never trusted on the user agent alone.
 *
 * DNS never runs on the request path: an unknown address claiming a
 * reverse-DNS bot is queued and verified by the minute tick, with a budget,
 * and counts as "pending" until then. In v0.1 the verdict only labels
 * traffic on the dashboard.
 *
 * Sources (fetched 2026-09-02): developers.google.com verifying-googlebot
 * (googlebot.com for common crawlers, google.com for special-case crawlers
 * and user-triggered fetchers, gae.googleusercontent.com for fetchers),
 * support.apple.com/119829, yandex.com check-yandex-robots,
 * duckduckgo.com/duckduckbot.json; Bingbot's search.msn.com rule from the
 * Bing Webmaster blog "How to Verify that Bingbot is Bingbot".
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Good_Bots {

	const VERDICT_TTL     = 86400;
	const PENDING_KEY     = 'g:bot_pending';
	const PENDING_CAP     = 200;
	const RECENT_KEY      = 'g:bot_recent';
	const RECENT_CAP      = 50;
	const DDG_OPTION      = 'bsr_duckduckbot_ranges';
	const DDG_URL         = 'https://duckduckgo.com/duckduckbot.json';
	const DDG_FILE        = 'duckduckbot-ips.txt';
	const VERIFY_PER_TICK = 20;
	const VERIFY_BUDGET_S = 8;

	/**
	 * @var callable|null Test hook: fn( string $ip ) => string|false host name.
	 */
	public static $reverse_resolver = null;

	/**
	 * @var callable|null Test hook: fn( string $host, string $ip ) => bool forward match.
	 */
	public static $forward_resolver = null;

	public static function init() {}

	/**
	 * @return array name => [pattern, rdns suffixes | ip list]
	 */
	public static function bots() {
		return [
			'googlebot'   => [
				'label'   => 'Googlebot',
				'pattern' => '~Googlebot|Google-InspectionTool|AdsBot-Google|Storebot-Google|GoogleOther|Google-Read-Aloud~i',
				'rdns'    => [ 'googlebot.com', 'google.com', 'googleusercontent.com' ],
			],
			'bingbot'     => [
				'label'   => 'Bingbot',
				'pattern' => '~bingbot|BingPreview|msnbot|adidxbot|MicrosoftPreview~i',
				'rdns'    => [ 'search.msn.com' ],
			],
			'applebot'    => [
				'label'   => 'Applebot',
				'pattern' => '~Applebot~i',
				'rdns'    => [ 'applebot.apple.com' ],
			],
			'duckduckbot' => [
				'label'   => 'DuckDuckBot',
				'pattern' => '~DuckDuckBot|DuckDuckGo~i',
				'iplist'  => true,
			],
			'yandex'      => [
				'label'   => 'Yandex',
				'pattern' => '~Yandex~i',
				'rdns'    => [ 'yandex.ru', 'yandex.net', 'yandex.com' ],
			],
		];
	}

	/**
	 * Which good bot the user agent claims to be, or "".
	 *
	 * @param string $ua
	 * @return string
	 */
	public static function claimed( $ua ) {
		if ( '' === $ua || false === stripos( $ua, 'bot' ) && false === stripos( $ua, 'google' ) && false === stripos( $ua, 'yandex' ) && false === stripos( $ua, 'preview' ) && false === stripos( $ua, 'duckduck' ) ) {
			return '';
		}
		foreach ( self::bots() as $name => $bot ) {
			if ( preg_match( $bot['pattern'], $ua ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Cached verdict for an address claiming a bot: verified | fake | pending.
	 * Address-list bots are resolved immediately (no DNS); the others are
	 * queued for the tick.
	 *
	 * @param string $ip
	 * @param string $name
	 * @return string
	 */
	public static function verdict( $ip, $name ) {
		$cached = BSR_Counters::get_value( 'g:bot:' . $ip );
		if ( is_array( $cached ) && isset( $cached['v'] ) ) {
			return $cached['v'];
		}
		$bots = self::bots();
		if ( ! isset( $bots[ $name ] ) ) {
			return 'fake';
		}
		if ( ! empty( $bots[ $name ]['iplist'] ) ) {
			$v = self::verify_by_list( $ip, $name ) ? 'verified' : 'fake';
			self::store_verdict( $ip, $name, $v );
			return $v;
		}
		BSR_Counters::registry_add( self::PENDING_KEY, $ip, $name, self::PENDING_CAP, BSR_Counters::TTL_GLOBAL );
		return 'pending';
	}

	/**
	 * Verify queued addresses, bounded in count and wall time. Called by the tick.
	 *
	 * @return int Number verified.
	 */
	public static function verify_pending() {
		$pending = BSR_Counters::get_value( self::PENDING_KEY, [] );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return 0;
		}
		$t0   = microtime( true );
		$done = 0;
		foreach ( $pending as $ip => $name ) {
			if ( $done >= self::VERIFY_PER_TICK || ( microtime( true ) - $t0 ) > self::VERIFY_BUDGET_S ) {
				break;
			}
			$v = self::verify_now( (string) $ip, (string) $name ) ? 'verified' : 'fake';
			self::store_verdict( (string) $ip, (string) $name, $v );
			unset( $pending[ $ip ] );
			$done++;
		}
		BSR_Counters::set_value( self::PENDING_KEY, $pending, BSR_Counters::TTL_GLOBAL );
		return $done;
	}

	/**
	 * Synchronous verification (DNS or list). Tests and the tick only.
	 *
	 * @param string $ip
	 * @param string $name
	 * @return bool
	 */
	public static function verify_now( $ip, $name ) {
		$bots = self::bots();
		if ( ! isset( $bots[ $name ] ) || ! BSR_Helpers::is_valid_ip( $ip ) ) {
			return false;
		}
		if ( ! empty( $bots[ $name ]['iplist'] ) ) {
			return self::verify_by_list( $ip, $name );
		}
		return self::verify_by_rdns( $ip, $bots[ $name ]['rdns'] );
	}

	/**
	 * Reverse lookup, suffix check, forward confirmation.
	 *
	 * @param string $ip
	 * @param array  $suffixes
	 * @return bool
	 */
	public static function verify_by_rdns( $ip, array $suffixes ) {
		$host = is_callable( self::$reverse_resolver ) ? call_user_func( self::$reverse_resolver, $ip ) : @gethostbyaddr( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_string( $host ) || '' === $host || $host === $ip ) {
			return false;
		}
		$host = strtolower( rtrim( $host, '.' ) );
		$ok   = false;
		foreach ( $suffixes as $s ) {
			$s = strtolower( $s );
			if ( $host === $s || substr( $host, -strlen( '.' . $s ) ) === '.' . $s ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			return false;
		}
		return self::forward_matches( $host, $ip );
	}

	/**
	 * @param string $host
	 * @param string $ip
	 * @return bool
	 */
	public static function forward_matches( $host, $ip ) {
		if ( is_callable( self::$forward_resolver ) ) {
			return (bool) call_user_func( self::$forward_resolver, $host, $ip );
		}
		$want = inet_pton( $ip );
		if ( false === $want ) {
			return false;
		}
		if ( false === strpos( $ip, ':' ) ) {
			$list = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return is_array( $list ) && in_array( $ip, $list, true );
		}
		$records = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $records ) ) {
			return false;
		}
		foreach ( $records as $r ) {
			if ( isset( $r['ipv6'] ) && inet_pton( $r['ipv6'] ) === $want ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $ip
	 * @param string $name
	 * @return bool
	 */
	public static function verify_by_list( $ip, $name ) {
		if ( 'duckduckbot' !== $name ) {
			return false;
		}
		return BSR_Helpers::ip_in_list( $ip, self::duckduckbot_ranges() );
	}

	/**
	 * @return array
	 */
	public static function duckduckbot_ranges() {
		static $ranges = null;
		if ( null !== $ranges ) {
			return $ranges;
		}
		$stored = get_option( self::DDG_OPTION );
		$ranges = ( is_array( $stored ) && ! empty( $stored['ranges'] ) ) ? $stored['ranges'] : BSR_Helpers::bundled_list( self::DDG_FILE );
		return $ranges;
	}

	/**
	 * Daily refresh of the DuckDuckBot list (called from BSR_Client_IP::refresh_lists).
	 */
	public static function refresh_ip_lists() {
		$body = BSR_Client_IP::fetch_text( self::DDG_URL );
		if ( null === $body ) {
			return;
		}
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) || empty( $json['prefixes'] ) || ! is_array( $json['prefixes'] ) ) {
			return;
		}
		$ranges = [];
		foreach ( $json['prefixes'] as $p ) {
			$c = $p['ipv4Prefix'] ?? ( $p['ipv6Prefix'] ?? '' );
			if ( is_string( $c ) && '' !== $c && BSR_Helpers::is_valid_ip( explode( '/', $c )[0] ) ) {
				$ranges[] = $c;
			}
		}
		if ( count( $ranges ) >= 10 ) {
			update_option( self::DDG_OPTION, [ 'ranges' => $ranges, 'fetched_at' => time() ], false );
		}
	}

	/**
	 * @param string $ip
	 * @param string $name
	 * @param string $verdict
	 */
	public static function store_verdict( $ip, $name, $verdict ) {
		BSR_Counters::set_value( 'g:bot:' . $ip, [ 'v' => $verdict, 'bot' => $name, 't' => time() ], self::VERDICT_TTL );
		$recent = BSR_Counters::get_value( self::RECENT_KEY, [] );
		$recent = is_array( $recent ) ? $recent : [];
		$recent = [ $ip => [ 'bot' => $name, 'v' => $verdict, 't' => time() ] ] + $recent;
		if ( count( $recent ) > self::RECENT_CAP ) {
			$recent = array_slice( $recent, 0, self::RECENT_CAP, true );
		}
		BSR_Counters::set_value( self::RECENT_KEY, $recent, BSR_Counters::TTL_GLOBAL );
	}

	/**
	 * Recently verified or rejected addresses, newest first.
	 *
	 * @return array ip => [bot, v, t]
	 */
	public static function recent() {
		$r = BSR_Counters::get_value( self::RECENT_KEY, [] );
		return is_array( $r ) ? $r : [];
	}

	/**
	 * @return int
	 */
	public static function pending_count() {
		$p = BSR_Counters::get_value( self::PENDING_KEY, [] );
		return is_array( $p ) ? count( $p ) : 0;
	}
}
