<?php
/**
 * Uninstall: removes the ban tables and their options.
 *
 * Everything else the plugin stores (settings, minute rows, baselines,
 * storm state) is left in place for now; removing it is a separate change.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-bsr-ip-resolver.php';
require_once __DIR__ . '/includes/class-bsr-bans.php';

BSR_Bans::uninstall();
