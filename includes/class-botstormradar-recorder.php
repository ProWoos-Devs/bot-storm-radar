<?php
/**
 * Records one classified event per request into the counter store, at
 * shutdown, so the response status and duration are known. Never blocks.
 *
 * Per request, on the minute bucket: total, per class, per IP (distinct and
 * multi-hit detection), per network, per user-agent hash, per session,
 * HTML-serving IPs (addresses whose first request of the minute was a page),
 * beacon IPs, 5xx and slow responses, and bot claims with their verification
 * verdict. Every counter is one atomic increment; the ten-minute window is
 * derived from minute rows rather than counted again on the request path.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Recorder {

	/**
	 * Per-minute counts at which a key enters the "hot" registry.
	 */
	const HOT_IP  = 10;
	const HOT_NET = 20;

	const UA_REGISTRY_CAP  = 64;
	const HOT_REGISTRY_CAP = 100;
	const UA_SAMPLE_LENGTH = 120;

	/**
	 * @var bool
	 */
	private static $recorded = false;

	/**
	 * @var float Seconds spent in ingest() for this request (overhead report).
	 */
	private static $spent = 0.0;

	public static function init() {
		add_action( 'template_redirect', [ __CLASS__, 'late_classify' ], PHP_INT_MAX );
		// Last on shutdown: WordPress has flushed its output buffers by then,
		// so under PHP-FPM the response can be handed to the client first.
		add_action( 'shutdown', [ __CLASS__, 'record' ], PHP_INT_MAX );
	}

	/**
	 * Front-end requests reveal 404 and feeds only once the query ran.
	 */
	public static function late_classify() {
		$class = BotStormRadar_Classifier::get_class();
		if ( 'html' !== $class && 'search' !== $class ) {
			return;
		}
		if ( function_exists( 'is_404' ) && is_404() ) {
			BotStormRadar_Classifier::set( '404', $class );
		} elseif ( function_exists( 'is_feed' ) && is_feed() ) {
			BotStormRadar_Classifier::set( 'other', 'feed' );
		}
	}

	/**
	 * Record nothing for this request: it was refused before WordPress built
	 * anything, and the refusal is counted apart (BotStormRadar_Inline_Gate).
	 */
	public static function skip() {
		self::$recorded = true;
	}

	/**
	 * Whether this request should be recorded at all.
	 *
	 * @return bool
	 */
	public static function should_record() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		}
		if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
			return false;
		}
		if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
			return false; // wp-admin screens of logged-in users.
		}
		$class = BotStormRadar_Classifier::get_class();
		return 'cron' !== $class;
	}

	/**
	 * Shutdown handler: build the event from the request and ingest it once.
	 */
	public static function record() {
		if ( self::$recorded || ! self::should_record() ) {
			return;
		}
		self::$recorded = true;
		$event = self::event_from_request();
		if ( '' === $event['ip'] ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		$t0 = microtime( true );
		self::ingest( $event );
		self::$spent = microtime( true ) - $t0;
		if ( '404' === $event['class'] ) {
			self::trip_404( $event );
		}

		// github-build-only:start
		// Opt-in overhead log for calibration: define( 'BOTSTORMRADAR_DEBUG_TIMING', true ).
		if ( defined( 'BOTSTORMRADAR_DEBUG_TIMING' ) && BOTSTORMRADAR_DEBUG_TIMING ) {
			error_log( sprintf( '[Bot Storm Radar] timing: record %.3f ms on %s, class %s, request %d ms', self::$spent * 1000, BotStormRadar_Counters::backend(), $event['class'], $event['ms'] ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		// github-build-only:end

		if ( 'beacon' !== $event['class'] ) {
			BotStormRadar_Tick::maybe_run_inline();
		}
	}

	/**
	 * @return array
	 */
	public static function event_from_request() {
		$start = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true );
		$code  = function_exists( 'http_response_code' ) ? http_response_code() : 200;
		return [
			'class'   => BotStormRadar_Classifier::get_class(),
			'detail'  => BotStormRadar_Classifier::get_detail(),
			'ip'      => (string) BotStormRadar_Client_IP::resolve(),
			'ua'      => BotStormRadar_Helpers::user_agent(),
			'session' => self::session_key(),
			'status'  => is_int( $code ) ? $code : 200,
			'ms'      => (int) round( ( microtime( true ) - $start ) * 1000 ),
			'method'  => isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET',
		];
	}

	/**
	 * Page 404s per address and minute (assets are their own class and never
	 * get here). The request that reaches the count trips, once per minute.
	 * Live requests only; log sources never trip.
	 *
	 * @param array $e
	 * @return string BotStormRadar_Trips::trip() result, or ''.
	 */
	public static function trip_404( array $e ) {
		$count = BotStormRadar_Trips::settings()['404']['count'];
		$ip    = (string) ( $e['ip'] ?? '' );
		if ( $count <= 0 || '' === $ip ) {
			return '';
		}
		$minute = BotStormRadar_Helpers::minute();
		$n      = BotStormRadar_Counters::incr( 'm:' . $minute . ':p404:' . $ip );
		if ( $n !== $count ) {
			return '';
		}
		BotStormRadar_Counters::flush();
		$ev = [
			'by'         => 'radar',
			'page_404s'  => $n,
			'last_path'  => substr( (string) BotStormRadar_Helpers::request_path(), 0, 200 ),
			'user_agent' => (string) ( $e['ua'] ?? '' ),
			'minute'     => gmdate( 'Y-m-d H:i', $minute * 60 ) . ' UTC',
		];
		return BotStormRadar_Trips::trip( $ip, '404', $ev, 'radar', BotStormRadar_Good_Bots::claimed( (string) ( $e['ua'] ?? '' ) ) );
	}

	/**
	 * WordPress session where present: the logged-in user, else the
	 * WooCommerce session cookie. Hashed, never stored raw.
	 *
	 * @return string
	 */
	public static function session_key() {
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return 'u:' . get_current_user_id();
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( 0 !== strpos( (string) $name, 'wp_woocommerce_session_' ) || ! isset( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
				continue;
			}
			$value = sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
			if ( '' !== $value ) {
				return 's:' . BotStormRadar_Helpers::short_hash( explode( '||', $value )[0] );
			}
		}
		return '';
	}

	/**
	 * Seconds spent recording this request.
	 *
	 * @return float
	 */
	public static function spent() {
		return self::$spent;
	}

	/**
	 * Write one event into the counters. Pure with respect to the request, so
	 * tests can synthesize traffic.
	 *
	 * @param array    $e      class, detail, ip, ua, session, status, ms.
	 * @param int|null $minute Minute bucket (defaults to now).
	 */
	public static function ingest( array $e, $minute = null ) {
		$minute = null === $minute ? BotStormRadar_Helpers::minute() : (int) $minute;
		$m      = 'm:' . $minute . ':';
		$ip     = (string) ( $e['ip'] ?? '' );
		$class  = (string) ( $e['class'] ?? 'other' );
		if ( '' === $ip ) {
			return;
		}

		// The beacon is an asset fetch, not a request in the swarm sense.
		if ( 'beacon' === $class ) {
			if ( 1 === BotStormRadar_Counters::incr( $m . 'beacon_ip:' . $ip ) ) {
				BotStormRadar_Counters::incr( $m . 'beacon_ips' );
			}
			return;
		}

		BotStormRadar_Counters::incr( $m . 'total' );
		BotStormRadar_Counters::incr( $m . 'c:' . $class );

		$n = BotStormRadar_Counters::incr( $m . 'ip:' . $ip );
		if ( 1 === $n ) {
			BotStormRadar_Counters::incr( $m . 'ips' );
			if ( 'html' === $class || 'search' === $class || '404' === $class ) {
				BotStormRadar_Counters::incr( $m . 'html_ips' );
			}
		} elseif ( 2 === $n ) {
			BotStormRadar_Counters::incr( $m . 'multi' );
		} elseif ( self::HOT_IP === $n ) {
			BotStormRadar_Counters::registry_add( $m . 'hot_ips', $ip, 1, self::HOT_REGISTRY_CAP );
		}

		$net = BotStormRadar_Helpers::network_key( $ip );
		if ( '' !== $net ) {
			$n = BotStormRadar_Counters::incr( $m . 'net:' . $net );
			if ( 1 === $n ) {
				BotStormRadar_Counters::incr( $m . 'nets' );
			} elseif ( self::HOT_NET === $n ) {
				BotStormRadar_Counters::registry_add( $m . 'hot_nets', $net, 1, self::HOT_REGISTRY_CAP );
			}
		}

		$ua = (string) ( $e['ua'] ?? '' );
		$uh = BotStormRadar_Helpers::short_hash( $ua );
		$n  = BotStormRadar_Counters::incr( $m . 'ua:' . $uh );
		if ( 1 === $n ) {
			BotStormRadar_Counters::incr( $m . 'uas' );
			BotStormRadar_Counters::registry_add( $m . 'ua_reg', $uh, substr( $ua, 0, self::UA_SAMPLE_LENGTH ), self::UA_REGISTRY_CAP );
		}

		$session = (string) ( $e['session'] ?? '' );
		if ( '' !== $session && 1 === BotStormRadar_Counters::incr( $m . 'sess:' . $session ) ) {
			BotStormRadar_Counters::incr( $m . 'sessions' );
		}


		$status = (int) ( $e['status'] ?? 200 );
		if ( $status >= 500 ) {
			BotStormRadar_Counters::incr( $m . 'err5' );
		}
		$slow_ms = (int) BotStormRadar_Helpers::opt( 'slow_request_ms', 2000 );
		if ( $slow_ms > 0 && (int) ( $e['ms'] ?? 0 ) >= $slow_ms ) {
			BotStormRadar_Counters::incr( $m . 'slow' );
		}

		// Good-bot claims: labelled, never trusted on the user agent alone.
		$bot = BotStormRadar_Good_Bots::claimed( $ua );
		if ( '' !== $bot ) {
			$verdict = BotStormRadar_Good_Bots::verdict( $ip, $bot );
			BotStormRadar_Counters::incr( $m . 'bot:' . $bot . ':' . $verdict );
		}
	}
}
