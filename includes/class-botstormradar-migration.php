<?php
/**
 * One-time migrations of stored names.
 *
 * 0.3.0 renamed the prefix from `bsr` to `botstormradar`. Everything the
 * plugin had stored under the old prefix (options, the two ban tables, the
 * cron hooks, transients) is moved here, once, before anything else reads
 * it, and a marker option records that it happened. A new install has
 * nothing to move and only writes the marker.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Migration {

	const MARKER     = 'botstormradar_prefix_migrated';
	const LOCK       = 'botstormradar_prefix_lock';
	const OLD_PREFIX = 'bsr_';
	const NEW_PREFIX = 'botstormradar_';

	/**
	 * Tables of 0.2.x and their new names, without the WordPress prefix.
	 */
	const TABLES = [
		'bsr_bans'        => 'botstormradar_bans',
		'bsr_ban_exports' => 'botstormradar_ban_exports',
	];

	/**
	 * Cron hooks of 0.2.x. The new ones are scheduled again by the upgrade.
	 */
	const CRON_HOOKS = [ 'bsr_tick', 'bsr_refresh_cloudflare_ips' ];

	/**
	 * Whether the last prefix() call moved anything (tests).
	 *
	 * @var bool
	 */
	public static $moved = false;

	/**
	 * Move everything from the old prefix to the new one. Runs on every
	 * load until the marker exists, so an interrupted run completes on the
	 * next; every step leaves nothing to redo once done.
	 *
	 * @return bool True once the stored names are in place (already, or
	 *              moved now); false while another run is moving them, in
	 *              which case the caller must not touch them this request.
	 */
	public static function prefix() {
		self::$moved = false;
		if ( get_option( self::MARKER ) ) {
			return true;
		}
		global $wpdb;
		// One run at a time: a web request and a WP-CLI run (a log source's
		// timer) can load the new code in the same second. add_option() is
		// one INSERT, so only one of them gets the lock; a lock older than
		// five minutes is from a run that died and is taken over.
		$lock = get_option( self::LOCK );
		if ( $lock && (int) $lock > time() - 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		if ( $lock ) {
			delete_option( self::LOCK );
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			return false;
		}
		$moved = false;

		// Tables first: while the old ones still carry the data, the schema
		// install would create empty tables under the new names.
		foreach ( self::TABLES as $old => $new ) {
			$old_table = $wpdb->prefix . $old;
			$new_table = $wpdb->prefix . $new;
			$has_old   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) === $old_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$has_new   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) === $new_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( $has_old && ! $has_new ) {
				$wpdb->query( "RENAME TABLE `{$old_table}` TO `{$new_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from $wpdb->prefix and constants.
				$moved = true;
			}
		}

		// Options, whatever their name after the prefix (per-source names and
		// the hourly minute chunks included), autoload flag kept. An option
		// already written under the new name by this request loses to the
		// stored one: the old name holds the data.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::OLD_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$suppressed = $wpdb->suppress_errors();
		foreach ( $names as $old ) {
			$new = self::NEW_PREFIX . substr( $old, strlen( self::OLD_PREFIX ) );
			// One statement, so a row renamed meanwhile is simply not found;
			// only a real duplicate (the new name written by this request
			// before the move) is removed and the rename done again.
			$sql = $wpdb->prepare( "UPDATE {$wpdb->options} SET option_name = %s WHERE option_name = %s", $new, $old );
			if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above.
				$wpdb->delete( $wpdb->options, [ 'option_name' => $new ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above.
			}
			wp_cache_delete( $old, 'options' );
			wp_cache_delete( $new, 'options' );
			$moved = true;
		}
		$wpdb->suppress_errors( $suppressed );
		if ( $moved ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}

		// Transients are caches (the update check, a dismissed notice, a
		// gate error): dropped, the plugin builds them again.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_' . $wpdb->esc_like( self::OLD_PREFIX ) . '%', '_transient_timeout_' . $wpdb->esc_like( self::OLD_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		foreach ( self::CRON_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		if ( $moved ) {
			BotStormRadar_Tick::schedule();
			BotStormRadar_Client_IP::ensure_cron();
		}

		update_option( self::MARKER, BOTSTORMRADAR_VERSION, true );
		delete_option( self::LOCK );
		self::$moved = $moved;
		return true;
	}
}
