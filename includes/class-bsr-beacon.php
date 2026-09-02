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

class BSR_Beacon {

	const PARAM = 'bsr-beacon';

	public static function init() {
		add_action( 'wp_footer', [ __CLASS__, 'emit' ], 1 );
	}

	/**
	 * @return bool
	 */
	public static function is_beacon_request() {
		return isset( $_GET[ self::PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Serve the beacon and stop. Called from the plugin bootstrap.
	 */
	public static function maybe_serve() {
		if ( ! self::is_beacon_request() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		BSR_Classifier::set( 'beacon' );

		if ( ! headers_sent() ) {
			http_response_code( 204 );
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			header( 'Pragma: no-cache' );
			header( 'Expires: Wed, 11 Jan 1984 05:00:00 GMT' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			header( 'X-Bot-Storm-Radar: beacon' );
		}
		// The client gets its 204 before the counters are touched.
		BSR_Recorder::record();
		exit;
	}

	/**
	 * @return string Base URL without the unique suffix.
	 */
	public static function url_base() {
		return home_url( '/' ) . '?' . self::PARAM . '=';
	}

	/**
	 * The pixel and the JS ping, on every front-end HTML page.
	 */
	public static function emit() {
		if ( is_admin() || is_feed() || is_embed() || ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) ) {
			return;
		}
		$base = self::url_base();
		$img  = $base . 'i' . BSR_Helpers::short_hash( uniqid( '', true ) );
		echo '<img src="' . esc_url( $img ) . '" width="1" height="1" alt="" decoding="async" fetchpriority="low" aria-hidden="true" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none">' . "\n";
		echo '<script>(function(){try{var i=new Image();i.src=' . wp_json_encode( $base ) . '+"j"+Date.now().toString(36)+Math.random().toString(36).slice(2,10);}catch(e){}})();</script>' . "\n";
	}
}
