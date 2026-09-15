<?php
/**
 * Plugin Name: Bot Storm Radar
 * Plugin URI:  https://github.com/ProWoos-Devs/bot-storm-radar
 * Description: Detects bot swarms as swarms. Classifies every request, keeps sliding-window counters, computes swarm metrics (single-hit ratio, asset ratio, user-agent evenness, error pressure, endpoint concentration) and a storm score, learns the site's baseline, and reports calm, warning, storm and cooling states with email alerts. Radar only in this release: nothing is ever blocked.
 * Version:     0.1.5
 * Author:      ProWoos
 * Author URI:  https://github.com/ProWoos-Devs
 * Text Domain: bot-storm-radar
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Bot Storm Radar requires PHP 7.4 or higher.', 'bot-storm-radar' )
			);
		}
	);
	return;
}

define( 'BSR_VERSION', '0.1.5' );
define( 'BSR_PLUGIN_FILE', __FILE__ );
define( 'BSR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BSR_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// WooCommerce is optional. The WooCommerce module only adds request classes,
// so no feature compatibility needs declaring, but declaring it costs nothing
// and keeps the Features screen quiet.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

require_once BSR_PLUGIN_DIR . 'includes/class-bot-storm-radar.php';

bot_storm_radar();
