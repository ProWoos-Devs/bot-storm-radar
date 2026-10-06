<?php
/**
 * Ban tables: the durable, concurrency-safe record of temporary bans.
 *
 * `{prefix}bsr_bans` holds one row per banned key, unique on (address in
 * binary form, prefix length), so an address and its /64 are distinct keys
 * and a second trip on the same key never creates a second row. Every change
 * is one atomic statement (addresses travel as hex through UNHEX(), because
 * wpdb::query() refuses a query with bytes that are not valid UTF-8):
 *   - a trip is one INSERT ... ON DUPLICATE KEY UPDATE that keeps the later
 *     expiry, raises the trip count and replaces the latest reason and
 *     evidence;
 *   - an unban is one UPDATE that sets the expiry to now and records who and
 *     when, then raises the generation number.
 * No code path reads a row, changes it in PHP and writes it back.
 *
 * `{prefix}bsr_ban_exports` holds one row per ban and enforcement layer
 * (Cloudflare, web-server include, AbuseIPDB, from v0.3 on). Its schema is
 * created now so the cleanup rule can already respect pending exports.
 *
 * The generation number (option `bsr_generation`) changes on every
 * administrative change that affects bans. The gate stamps provisional bans
 * with it, so an unban wins over a ban decided before it (design v2, 3.6).
 *
 * trip() asks BSR_Guard::may_ban() before it writes anything, so a protected
 * address (CDN, proxy, allowlist, administrator, verified bot) never gets a
 * row, whoever calls.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Bans {

	/**
	 * Schema version of both tables; bump on any column or index change.
	 */
	const DB_VERSION = 1;

	const DB_VERSION_OPTION = 'bsr_db_version';

	const GENERATION_OPTION = 'bsr_generation';

	/**
	 * Evidence limits: entries per list and characters per entry.
	 */
	const EVIDENCE_ENTRIES = 10;
	const EVIDENCE_CHARS   = 200;

	/**
	 * Expired rows are kept this long for the Bans tab and reports.
	 */
	const RETENTION = 30 * DAY_IN_SECONDS;

	/**
	 * Export statuses that keep an expired row alive (removal still owed).
	 */
	const OPEN_EXPORT_STATUSES = [ 'pending', 'failed' ];

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bsr_bans';
	}

	/**
	 * @return string
	 */
	public static function exports_table() {
		global $wpdb;
		return $wpdb->prefix . 'bsr_ban_exports';
	}

	/**
	 * Settings whose change voids provisional bans decided before it.
	 */
	const GENERATION_SETTINGS = [ 'ban_mode', 'probe_refusal', 'probe_extra', 'probe_allow', 'allowlist', 'trusted_proxies', 'trust_all_forwarding' ];

	const WOULD_OPTION       = 'bsr_would_bans';
	const HAND_UNBANS_OPTION = 'bsr_hand_unbans';
	const WOULD_CAP    = 200;

	public static function init() {
		// Daily, on the shared list-refresh hook.
		add_action( BSR_Client_IP::CRON_HOOK, [ __CLASS__, 'cleanup' ], 30, 0 );
		// Before the state rebuild (priority 10), so the file carries the new number.
		add_action( 'update_option_' . Bot_Storm_Radar::OPTION_KEY, [ __CLASS__, 'settings_changed' ], 5, 2 );
	}

	/**
	 * Raise the generation when a setting that affects bans changed (any
	 * `trip_*` key too).
	 *
	 * @param mixed $old
	 * @param mixed $new
	 */
	public static function settings_changed( $old, $new ) {
		$old  = is_array( $old ) ? $old : [];
		$new  = is_array( $new ) ? $new : [];
		$keys = self::GENERATION_SETTINGS;
		foreach ( array_merge( array_keys( $old ), array_keys( $new ) ) as $k ) {
			if ( 0 === strpos( (string) $k, 'trip_' ) ) {
				$keys[] = $k;
			}
		}
		foreach ( array_unique( $keys ) as $k ) {
			if ( ( $old[ $k ] ?? null ) != ( $new[ $k ] ?? null ) ) { // phpcs:ignore WordPress.PHP.StrictComparisons -- '1' and 1 are the same setting.
				self::bump_generation();
				return;
			}
		}
	}

	/**
	 * Record a would-be ban (observe mode) for the Bans tab: per address the
	 * reason, first and last time, count and the latest evidence, capped at
	 * WOULD_CAP addresses (oldest dropped). Never a ban, never through the
	 * table. Protected addresses are not recorded.
	 *
	 * @param string $ip
	 * @param string $reason
	 * @param array  $evidence
	 * @param int    $at
	 * @return bool
	 */
	public static function would_ban( $ip, $reason, array $evidence, $at ) {
		if ( ! BSR_Guard::may_ban( $ip ) ) {
			return false;
		}
		$list = get_option( self::WOULD_OPTION, [] );
		$list = is_array( $list ) ? $list : [];
		$cur  = $list[ $ip ] ?? [ 'first' => (int) $at, 'count' => 0 ];
		unset( $list[ $ip ] );
		$list = [ $ip => [
			'reason'   => substr( sanitize_key( (string) $reason ), 0, 40 ),
			'first'    => (int) $cur['first'],
			'last'     => (int) $at,
			'count'    => (int) $cur['count'] + 1,
			'evidence' => self::bound_evidence( $evidence ),
		] ] + $list;
		update_option( self::WOULD_OPTION, array_slice( $list, 0, self::WOULD_CAP, true ), false );
		return true;
	}

	/**
	 * @return array ip => reason, first, last, count, evidence (newest first)
	 */
	public static function would_bans() {
		$l = get_option( self::WOULD_OPTION, [] );
		return is_array( $l ) ? $l : [];
	}

	// ── Schema ────────────────────────────────────────────────────────

	/**
	 * Create or update both tables when the stored schema version is behind.
	 * Cheap on every load: one autoloaded option compared with a constant.
	 */
	public static function maybe_install() {
		if ( (int) get_option( self::DB_VERSION_OPTION, 0 ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * dbDelta both tables and record the schema version.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$bans    = self::table();
		$exports = self::exports_table();

		// dbDelta wants two spaces after PRIMARY KEY and one field per line.
		dbDelta( "CREATE TABLE {$bans} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  address varbinary(16) NOT NULL,
  prefix_len tinyint(3) unsigned NOT NULL,
  ip_text varchar(45) NOT NULL,
  reason varchar(40) NOT NULL DEFAULT '',
  evidence text NULL,
  origin varchar(20) NOT NULL DEFAULT '',
  trips int(10) unsigned NOT NULL DEFAULT 1,
  created_at int(10) unsigned NOT NULL,
  updated_at int(10) unsigned NOT NULL,
  expires_at int(10) unsigned NOT NULL,
  unbanned_at int(10) unsigned NOT NULL DEFAULT 0,
  unbanned_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY address_prefix (address,prefix_len),
  KEY expires_at (expires_at),
  KEY unbanned_at (unbanned_at)
) {$charset};" );

		dbDelta( "CREATE TABLE {$exports} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  ban_id bigint(20) unsigned NOT NULL,
  layer varchar(20) NOT NULL,
  remote_id varchar(100) NOT NULL DEFAULT '',
  status varchar(12) NOT NULL DEFAULT 'pending',
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error varchar(255) NOT NULL DEFAULT '',
  updated_at int(10) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ban_layer (ban_id,layer),
  KEY status (status)
) {$charset};" );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
		if ( false === get_option( self::GENERATION_OPTION, false ) ) {
			add_option( self::GENERATION_OPTION, 1, '', false );
		}
	}

	/**
	 * Drop both tables and their options (uninstall only).
	 */
	public static function uninstall() {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::exports_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
		delete_option( self::DB_VERSION_OPTION );
		delete_option( self::GENERATION_OPTION );
		delete_option( self::WOULD_OPTION );
		delete_option( self::HAND_UNBANS_OPTION );
	}

	// ── Keys ──────────────────────────────────────────────────────────

	/**
	 * The ban key for an address and prefix length: the network address in
	 * binary form (host bits cleared) and its text. A single address is /32
	 * (IPv4) or /128 (IPv6).
	 *
	 * @param string   $ip
	 * @param int|null $prefix_len Null for a single address.
	 * @return array|null {address: binary, prefix_len: int, ip_text: string}
	 */
	public static function key( $ip, $prefix_len = null ) {
		$bin = BSR_IP_Resolver::is_valid_ip( $ip ) ? inet_pton( $ip ) : false;
		if ( false === $bin ) {
			return null;
		}
		$max = strlen( $bin ) * 8;
		$len = null === $prefix_len ? $max : (int) $prefix_len;
		if ( $len < 1 || $len > $max ) {
			return null;
		}
		$full = intdiv( $len, 8 );
		$rest = $len % 8;
		$out  = substr( $bin, 0, $full );
		if ( $rest > 0 ) {
			$out .= chr( ord( $bin[ $full ] ) & ( ( 0xFF << ( 8 - $rest ) ) & 0xFF ) );
			$full++;
		}
		$out .= str_repeat( "\0", strlen( $bin ) - $full );
		return [
			'address'    => $out,
			'prefix_len' => $len,
			'ip_text'    => inet_ntop( $out ),
		];
	}

	// ── Changes (each one statement) ─────────────────────────────────

	/**
	 * Record a trip: create the ban or extend it. Returns the ban id, or 0
	 * when the address is not valid or the query failed.
	 *
	 * @param string   $ip
	 * @param int      $ttl        Seconds the ban lasts from now.
	 * @param string   $reason     Trip class (probe, 404, manual, ...).
	 * @param array    $evidence   paths, user_agents (lists), anything else scalar.
	 * @param string   $origin     Source id or 'gate' / 'manual'.
	 * @param int|null $prefix_len Null for a single address.
	 * @param int|null $now        Tests.
	 * @return int
	 */
	public static function trip( $ip, $ttl, $reason, array $evidence = [], $origin = '', $prefix_len = null, $now = null ) {
		global $wpdb;
		$key = self::key( $ip, $prefix_len );
		if ( null === $key || (int) $ttl <= 0 ) {
			return 0;
		}
		// The guard is the only way in: CDN edges, proxies, the allowlist,
		// administrators and verified bots are never banned, whoever asks.
		if ( ! BSR_Guard::may_ban( $ip, $prefix_len ) ) {
			return 0;
		}
		$now     = null === $now ? time() : (int) $now;
		$expires = $now + (int) $ttl;
		$table   = self::table();

		// A re-trip after an unban bans again: unbanned_at is cleared, and the
		// later-expiry rule holds because the unban set the expiry to its time.
		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			"INSERT INTO {$table} (address, prefix_len, ip_text, reason, evidence, origin, trips, created_at, updated_at, expires_at, unbanned_at, unbanned_by)
			VALUES (UNHEX(%s), %d, %s, %s, %s, %s, 1, %d, %d, %d, 0, 0)
			ON DUPLICATE KEY UPDATE
				trips = trips + 1,
				reason = VALUES(reason),
				evidence = VALUES(evidence),
				origin = VALUES(origin),
				updated_at = VALUES(updated_at),
				expires_at = GREATEST(expires_at, VALUES(expires_at)),
				unbanned_at = 0,
				unbanned_by = 0,
				id = LAST_INSERT_ID(id)",
			bin2hex( $key['address'] ),
			$key['prefix_len'],
			$key['ip_text'],
			substr( sanitize_key( (string) $reason ), 0, 40 ),
			wp_json_encode( self::bound_evidence( $evidence ) ),
			substr( sanitize_key( (string) $origin ), 0, 20 ),
			$now,
			$now,
			$expires
		);
		if ( false === $wpdb->query( $sql ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above.
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		/** Fires after a ban was created or extended (the state file rebuilds). */
		do_action( 'bsr_bans_changed' );
		return $id;
	}

	/**
	 * Lift a ban now. Returns true when an active ban was lifted. Raises the
	 * generation so provisional bans decided before it stop applying.
	 *
	 * @param string   $ip
	 * @param int      $by         User id who lifted it (0 = system).
	 * @param int|null $prefix_len
	 * @param int|null $now        Tests.
	 * @return bool
	 */
	public static function unban( $ip, $by = 0, $prefix_len = null, $now = null ) {
		global $wpdb;
		$key = self::key( $ip, $prefix_len );
		if ( null === $key ) {
			return false;
		}
		$now   = null === $now ? time() : (int) $now;
		$table = self::table();
		$rows  = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				"UPDATE {$table} SET expires_at = %d, unbanned_at = %d, unbanned_by = %d, updated_at = %d
				WHERE address = UNHEX(%s) AND prefix_len = %d AND expires_at > %d",
				$now,
				$now,
				(int) $by,
				$now,
				bin2hex( $key['address'] ),
				$key['prefix_len'],
				$now
			)
		);
		if ( ! $rows ) {
			return false;
		}
		self::bump_generation();
		/** Fires after a ban was lifted (the state file rebuilds). */
		do_action( 'bsr_bans_changed' );
		return true;
	}

	/**
	 * Raise the generation number in one statement and return the new value.
	 *
	 * @return int
	 */
	public static function bump_generation() {
		global $wpdb;
		if ( false === get_option( self::GENERATION_OPTION, false ) ) {
			add_option( self::GENERATION_OPTION, 1, '', false );
		}
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
				self::GENERATION_OPTION
			)
		);
		wp_cache_delete( self::GENERATION_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		return self::generation();
	}

	/**
	 * The current generation number, read from the database.
	 *
	 * @return int
	 */
	public static function generation() {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::GENERATION_OPTION )
		);
	}

	/**
	 * Delete rows that expired more than RETENTION ago and owe no export
	 * removal on any layer. Returns the number of rows deleted.
	 *
	 * @param int|null $now Tests.
	 * @return int
	 */
	public static function cleanup( $now = null ) {
		global $wpdb;
		$now     = null === $now ? time() : (int) $now;
		$bans    = self::table();
		$exports = self::exports_table();
		$open    = "'" . implode( "','", self::OPEN_EXPORT_STATUSES ) . "'";
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix, statuses are constants.
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
			$wpdb->prepare(
				"DELETE b FROM {$bans} b
				LEFT JOIN {$exports} e ON e.ban_id = b.id AND e.status IN ({$open})
				WHERE b.expires_at < %d AND e.id IS NULL",
				$now - self::RETENTION
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// Export rows whose ban is gone.
		$wpdb->query( "DELETE e FROM {$exports} e LEFT JOIN {$bans} b ON b.id = e.ban_id WHERE b.id IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from $wpdb->prefix.
		return (int) $deleted;
	}

	// ── Reads ─────────────────────────────────────────────────────────

	/**
	 * Bans in force now, for the state file and the Bans tab.
	 *
	 * @param int|null $now Tests.
	 * @return array[] ip_text, prefix_len, reason, origin, trips, created_at, expires_at
	 */
	public static function active( $now = null ) {
		global $wpdb;
		$now   = null === $now ? time() : (int) $now;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
			$wpdb->prepare(
				"SELECT id, ip_text, prefix_len, reason, origin, trips, created_at, expires_at FROM {$table} WHERE expires_at > %d AND unbanned_at = 0 ORDER BY expires_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$now
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Rows for the Bans tab: in force now, or ended within the last $days
	 * days, newest first, evidence decoded.
	 *
	 * @param int      $days
	 * @param int      $limit
	 * @param int|null $now
	 * @return array[]
	 */
	public static function listing( $days = 7, $limit = 200, $now = null ) {
		global $wpdb;
		$now   = null === $now ? time() : (int) $now;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
			$wpdb->prepare(
				"SELECT id, ip_text, prefix_len, reason, evidence, origin, trips, created_at, updated_at, expires_at, unbanned_at, unbanned_by FROM {$table} WHERE expires_at > %d ORDER BY (expires_at > %d) DESC, updated_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				$now - (int) $days * DAY_IN_SECONDS,
				$now,
				(int) $limit
			),
			ARRAY_A
		);
		$rows = is_array( $rows ) ? $rows : [];
		foreach ( $rows as &$r ) {
			$r['evidence'] = json_decode( (string) $r['evidence'], true );
			$r['active']   = (int) $r['expires_at'] > $now && 0 === (int) $r['unbanned_at'];
		}
		return $rows;
	}

	/**
	 * Count an unban made by hand (the false-positive signal).
	 *
	 * @return int The new total.
	 */
	public static function count_hand_unban() {
		$n = (int) get_option( self::HAND_UNBANS_OPTION, 0 ) + 1;
		update_option( self::HAND_UNBANS_OPTION, $n, false );
		return $n;
	}

	/**
	 * Keys unbanned by hand since a time (the gate's recent-unban list).
	 *
	 * @param int $since
	 * @return array[] ip_text, prefix_len, unbanned_at
	 */
	public static function recent_unbans( $since ) {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
			$wpdb->prepare(
				"SELECT ip_text, prefix_len, unbanned_at FROM {$table} WHERE unbanned_at >= %d AND unbanned_at > 0", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				(int) $since
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * One ban row by key, evidence decoded, or null.
	 *
	 * @param string   $ip
	 * @param int|null $prefix_len
	 * @return array|null
	 */
	public static function get( $ip, $prefix_len = null ) {
		global $wpdb;
		$key = self::key( $ip, $prefix_len );
		if ( null === $key ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name from $wpdb->prefix.
			$wpdb->prepare(
				"SELECT id, ip_text, prefix_len, reason, evidence, origin, trips, created_at, updated_at, expires_at, unbanned_at, unbanned_by FROM {$table} WHERE address = UNHEX(%s) AND prefix_len = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
				bin2hex( $key['address'] ),
				$key['prefix_len']
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['evidence'] = json_decode( (string) $row['evidence'], true );
		return $row;
	}

	/**
	 * Cut evidence to the limits: lists to EVIDENCE_ENTRIES entries of
	 * EVIDENCE_CHARS characters, scalars to EVIDENCE_CHARS, nothing nested.
	 *
	 * @param array $evidence
	 * @return array
	 */
	public static function bound_evidence( array $evidence ) {
		$out = [];
		foreach ( $evidence as $k => $v ) {
			$k = substr( sanitize_key( (string) $k ), 0, 40 );
			if ( '' === $k ) {
				continue;
			}
			if ( is_array( $v ) ) {
				$list = [];
				foreach ( array_slice( array_values( $v ), -self::EVIDENCE_ENTRIES ) as $item ) {
					if ( is_scalar( $item ) ) {
						$list[] = mb_substr( (string) $item, 0, self::EVIDENCE_CHARS );
					}
				}
				$out[ $k ] = $list;
			} elseif ( is_scalar( $v ) ) {
				$out[ $k ] = is_string( $v ) ? mb_substr( $v, 0, self::EVIDENCE_CHARS ) : $v;
			}
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}
}
