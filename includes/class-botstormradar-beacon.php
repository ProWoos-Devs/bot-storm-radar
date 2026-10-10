<?php
/**
 * The beacon (design v1, 5.3 and 8). A tiny endpoint the plugin serves
 * itself, answering 204 with Cache-Control: no-store, emitted as a
 * one-pixel image and a JS ping on every front-end HTML page. Real browsers
 * fetch it; the 2026-08-19 swarm did not. Counted by IP into the counter
 * store. The query string is unique per page render and per JS ping, and
 * the response forbids caching, so page-cached sites still see one beacon
 * per browser.
 *
 * In v0.1 it is served the moment the plugin file loads, before any other
 * plugin, theme, or user session work. The must-use gate takes it over in
 * v0.2 and removes the WordPress bootstrap from the path entirely.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Beacon {

	const PARAM = 'botstormradar-beacon';

	/**
	 * The name before 0.4.0, still answered: pages cached before the update
	 * keep asking for it for a while.
	 */
	const OLD_PARAM = 'bsr-beacon';

	const HANDLE = 'botstormradar-beacon';

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_footer', [ __CLASS__, 'emit' ], 1 );
	}

	/**
	 * Whether this front-end request gets the pixel and the ping.
	 *
	 * @return bool
	 */
	private static function wanted() {
		return ! ( is_admin() || is_feed() || is_embed() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) );
	}

	/**
	 * The JS ping: a script handle without a file, carrying one inline line
	 * in the footer.
	 */
	public static function enqueue() {
		if ( ! self::wanted() ) {
			return;
		}
		wp_register_script( self::HANDLE, false, [], BOTSTORMRADAR_VERSION, true );
		wp_enqueue_script( self::HANDLE );
		wp_add_inline_script( self::HANDLE, '(function(){try{var i=new Image();i.src=' . wp_json_encode( self::url_base() ) . '+"j"+Date.now().toString(36)+Math.random().toString(36).slice(2,10);}catch(e){}})();' );
	}

	/**
	 * @return bool
	 */
	public static function is_beacon_request() {
		// A public, anonymous pixel: only its presence is checked, nothing is read from it.
		return isset( $_GET[ self::PARAM ] ) || isset( $_GET[ self::OLD_PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Serve the beacon and stop. Called from the plugin bootstrap.
	 */
	public static function maybe_serve() {
		if ( ! self::is_beacon_request() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the page-cache convention, shared by every cache plugin.
		}
		BotStormRadar_Classifier::set( 'beacon' );

		if ( ! headers_sent() ) {
			http_response_code( 204 );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'X-Bot-Storm-Radar: beacon' );
		}
		// The client gets its 204 before the counters are touched.
		BotStormRadar_Recorder::record();
		exit;
	}

	/**
	 * Base URL without the unique suffix. Goes through index.php explicitly so
	 * a web-server rule on the bare root (a language redirect, a static
	 * front page) cannot swallow it before PHP runs. Filterable.
	 *
	 * @return string
	 */
	public static function url_base() {
		return apply_filters( 'botstormradar_beacon_url_base', home_url( '/index.php' ) . '?' . self::PARAM . '=' );
	}

	/**
	 * The pixel, on every front-end HTML page (the JS ping is enqueued).
	 */
	public static function emit() {
		if ( ! self::wanted() ) {
			return;
		}
		$img = self::url_base() . 'i' . BotStormRadar_Helpers::short_hash( uniqid( '', true ) );
		echo '<img src="' . esc_url( $img ) . '" width="1" height="1" alt="" decoding="async" fetchpriority="low" aria-hidden="true" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none">' . "\n";
	}
}
