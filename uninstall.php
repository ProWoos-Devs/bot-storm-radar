<?php
/**
 * Uninstall: removes the gate's mu-plugin loader, the ban tables, the gate
 * copies and the state file, and their options. The data directory keeps the
 * few-line gate loader and a `disabled` marker: a cached auto_prepend_file
 * line may still point at the loader, and a missing prepend file would break
 * every request.
 *
 * Everything else the plugin stores (settings, minute rows, baselines,
 * storm state) is left in place for now; removing it is a separate change.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-botstormradar-ip-resolver.php';
require_once __DIR__ . '/includes/class-botstormradar-migration.php';
require_once __DIR__ . '/includes/class-botstormradar-bans.php';
require_once __DIR__ . '/includes/class-botstormradar-state.php';
require_once __DIR__ . '/includes/class-botstormradar-gate-install.php';
require_once __DIR__ . '/includes/class-botstormradar-gate-early.php';

BotStormRadar_Gate_Early::disable();
BotStormRadar_Gate_Install::remove_loader();
BotStormRadar_Bans::uninstall();
BotStormRadar_State::uninstall();
