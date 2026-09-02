<?php
/**
 * WooCommerce module. In v0.1 it only contributes request classes:
 * wc-ajax (with the action), Store API cart and checkout, ?add-to-cart=.
 * No fraud signals yet. Inert when WooCommerce is absent.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_WooCommerce {

	public static function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		add_filter( 'bsr_request_class', [ __CLASS__, 'classify' ], 10, 4 );
	}

	/**
	 * @return bool
	 */
	public static function active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * @param array  $result
	 * @param string $path
	 * @param array  $query
	 * @param array  $server
	 * @return array
	 */
	public static function classify( $result, $path, $query, $server ) {
		if ( isset( $query['wc-ajax'] ) ) {
			$action = substr( sanitize_key( (string) $query['wc-ajax'] ), 0, 40 );
			if ( 'checkout' === $action ) {
				return [ 'class' => 'checkout', 'detail' => 'wc-ajax' ];
			}
			if ( 'add_to_cart' === $action ) {
				return [ 'class' => 'cart', 'detail' => 'wc-ajax' ];
			}
			return [ 'class' => 'wc-ajax', 'detail' => $action ];
		}
		if ( isset( $query['add-to-cart'] ) ) {
			return [ 'class' => 'cart', 'detail' => 'add-to-cart' ];
		}
		if ( 'rest' === $result['class'] && 'wc-store' === $result['detail'] ) {
			$route = isset( $query['rest_route'] ) ? strtolower( (string) $query['rest_route'] ) : strtolower( (string) $path );
			if ( preg_match( '#/wc/store/v\d+/checkout#', $route ) ) {
				return [ 'class' => 'checkout', 'detail' => 'store-api' ];
			}
			if ( preg_match( '#/wc/store/v\d+/cart#', $route ) ) {
				return [ 'class' => 'cart', 'detail' => 'store-api' ];
			}
		}
		return $result;
	}
}
