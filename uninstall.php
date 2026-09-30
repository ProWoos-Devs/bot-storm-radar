<?php
/**
 * Uninstall: removes the gate's mu-plugin loader, the ban tables, the data
 * directory with the gate and its state file, and their options.
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
require_once __DIR__ . '/includes/class-bsr-state.php';
require_once __DIR__ . '/includes/class-bsr-gate-install.php';

BSR_Gate_Install::remove_loader();
BSR_Bans::uninstall();
BSR_State::uninstall();
