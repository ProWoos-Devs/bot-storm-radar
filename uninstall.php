<?php
/**
 * Uninstall: removes everything the plugin stored. The ban tables, every
 * option and transient named botstormradar_*, and the cron hooks; in builds
 * with the early gate its files too. Counters in the object cache or APCu
 * expire by themselves within a day.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-botstormradar-ip-resolver.php';
require_once __DIR__ . '/includes/class-botstormradar-migration.php';
require_once __DIR__ . '/includes/class-botstormradar-bans.php';
// github-build-only:start
// The early gate: its mu-plugin loader, the gate copies, the state file and
// their options go. The data directory keeps the few-line gate loader and a
// `disabled` marker: a cached auto_prepend_file line may still point at the
// loader, and a missing prepend file would break every request.
require_once __DIR__ . '/includes/class-botstormradar-state.php';
require_once __DIR__ . '/includes/class-botstormradar-gate-install.php';
require_once __DIR__ . '/includes/class-botstormradar-gate-early.php';

BotStormRadar_Gate_Early::disable();
BotStormRadar_Gate_Install::remove_loader();
BotStormRadar_State::uninstall();
// github-build-only:end
BotStormRadar_Bans::uninstall();

global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'botstormradar_' ) . '%',
		$wpdb->esc_like( '_transient_botstormradar_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_botstormradar_' ) . '%'
	)
);
wp_cache_delete( 'alloptions', 'options' );
wp_clear_scheduled_hook( 'botstormradar_tick' );
wp_clear_scheduled_hook( 'botstormradar_refresh_cloudflare_ips' );
