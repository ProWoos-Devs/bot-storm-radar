<?php
/**
 * Request classifier (design v1, 5.1). One class per request, resolved once,
 * cheap string matching on the path and a handful of query keys. Modules add
 * or override classes through the `bsr_request_class` filter.
 *
 * Classes: html, search, rest (detail: users, wc-store, other), xmlrpc,
 * login, register, comment, admin-ajax (detail: action), wc-ajax (detail:
 * action), checkout, cart, asset, 404, beacon, cron, other.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Classifier {

	const ASSET_EXTENSIONS = [ 'css', 'js', 'mjs', 'map', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp4', 'webm', 'mp3', 'pdf', 'txt', 'xml' ];

	/**
	 * @var array|null {class, detail}
	 */
	private static $result = null;

	/**
	 * Fixed set of classes the metrics enumerate. Modules may report others;
	 * they are counted under their own name but only these are multi-fetched
	 * by the tick.
	 *
	 * @return array
	 */
	public static function known_classes() {
		return apply_filters( 'bsr_known_classes', [ 'html', 'search', 'rest', 'xmlrpc', 'login', 'register', 'comment', 'admin-ajax', 'wc-ajax', 'checkout', 'cart', 'asset', '404', 'other' ] );
	}

	/**
	 * @return string
	 */
	public static function get_class() {
		return self::get()['class'];
	}

	/**
	 * @return string
	 */
	public static function get_detail() {
		return self::get()['detail'];
	}

	/**
	 * @return array {class: string, detail: string}
	 */
	public static function get() {
		if ( null === self::$result ) {
			self::$result = self::classify( BSR_Helpers::request_path(), $_GET, $_SERVER ); // phpcs:ignore WordPress.Security
		}
		return self::$result;
	}

	/**
	 * Override the resolved class (beacon, late 404 detection).
	 *
	 * @param string $class
	 * @param string $detail
	 */
	public static function set( $class, $detail = '' ) {
		self::$result = [ 'class' => (string) $class, 'detail' => (string) $detail ];
	}

	/**
	 * Forget the per-request result (tests).
	 */
	public static function reset() {
		self::$result = null;
	}

	/**
	 * Pure classification so tests can feed synthetic requests.
	 *
	 * @param string $path   Request path without the query string.
	 * @param array  $query  Query variables.
	 * @param array  $server Server variables.
	 * @return array {class, detail}
	 */
	public static function classify( $path, array $query, array $server ) {
		$path   = '/' . ltrim( (string) $path, '/' );
		$lower  = strtolower( $path );
		$class  = 'html';
		$detail = '';

		$ext = strtolower( (string) pathinfo( $lower, PATHINFO_EXTENSION ) );

		if ( isset( $query['rest_route'] ) || false !== strpos( $lower, '/wp-json/' ) || '/wp-json' === substr( $lower, -8 ) ) {
			$route  = isset( $query['rest_route'] ) ? strtolower( (string) $query['rest_route'] ) : substr( $lower, (int) strpos( $lower, '/wp-json' ) + 8 );
			$class  = 'rest';
			$detail = 'other';
			if ( false !== strpos( $route, '/wp/v2/users' ) ) {
				$detail = 'users';
			} elseif ( false !== strpos( $route, '/wc/store' ) ) {
				$detail = 'wc-store';
			}
		} elseif ( '/xmlrpc.php' === substr( $lower, -11 ) ) {
			$class = 'xmlrpc';
		} elseif ( '/wp-login.php' === substr( $lower, -13 ) ) {
			$class = ( isset( $query['action'] ) && 'register' === $query['action'] ) ? 'register' : 'login';
		} elseif ( '/wp-comments-post.php' === substr( $lower, -21 ) ) {
			$class = 'comment';
		} elseif ( '/wp-admin/admin-ajax.php' === substr( $lower, -24 ) ) {
			$class  = 'admin-ajax';
			$detail = isset( $query['action'] ) ? substr( sanitize_key( (string) $query['action'] ), 0, 40 ) : '';
			if ( '' === $detail && isset( $server['REQUEST_METHOD'] ) && 'POST' === $server['REQUEST_METHOD'] && isset( $_POST['action'] ) ) { // phpcs:ignore WordPress.Security
				$detail = substr( sanitize_key( (string) wp_unslash( $_POST['action'] ) ), 0, 40 ); // phpcs:ignore WordPress.Security
			}
		} elseif ( '/wp-cron.php' === substr( $lower, -12 ) ) {
			$class = 'cron';
		} elseif ( '' !== $ext && in_array( $ext, self::ASSET_EXTENSIONS, true ) ) {
			$class  = 'asset';
			$detail = $ext;
		} elseif ( isset( $query['s'] ) || false !== strpos( $lower, '/search/' ) || '/search' === substr( $lower, -7 ) ) {
			$class = 'search';
		} elseif ( 0 === strpos( $lower, '/wp-admin/' ) ) {
			$class = 'other';
		}

		/**
		 * Let modules (WooCommerce, others) add or override request classes.
		 *
		 * @param array  $result {class: string, detail: string}
		 * @param string $path
		 * @param array  $query
		 * @param array  $server
		 */
		$result = apply_filters( 'bsr_request_class', [ 'class' => $class, 'detail' => $detail ], $path, $query, $server );
		if ( ! is_array( $result ) || empty( $result['class'] ) ) {
			$result = [ 'class' => $class, 'detail' => $detail ];
		}
		$result['class']  = substr( sanitize_key( (string) $result['class'] ), 0, 20 );
		$result['detail'] = substr( (string) ( $result['detail'] ?? '' ), 0, 40 );
		return $result;
	}
}
