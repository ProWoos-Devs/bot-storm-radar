<?php
/**
 * The gate's state file and the plugin's data directory.
 *
 * Data directory: `bot-storm-radar-<random>/` in the uploads folder, the
 * suffix created once and kept in option `bsr_data_dir`. Up to 0.2.0 it sat
 * directly in wp-content; the move is described at leave_legacy(). It holds an
 * `index.php`, an Apache
 * `.htaccess` that denies everything, and the files the gate reads. Every
 * file the plugin writes there, temporaries included, starts with
 * `<?php exit; ?>` and has a `.php` name, so a web request for it runs as
 * PHP and returns nothing even where the `.htaccess` is not honored (nginx;
 * the README gives the matching `location` rule).
 *
 * State file: `state.php`, a projection rebuilt whole from the ban tables and
 * the options by rebuild(), which holds an exclusive flock() on `state.lock`,
 * writes a temporary and renames it into place. The database is the truth,
 * nothing reads the file to change it, so two concurrent rebuilds cannot drop
 * each other's bans. Evidence never goes into the file.
 *
 * Rebuilt on: a ban or unban (`botstormradar_bans_changed`), a new protected address
 * (`botstormradar_protected_changed`: an administrator first seen, a bot newly
 * verified), a settings save, a Cloudflare range refresh, and daily. Every
 * projected ban is checked with BotStormRadar_Guard::may_ban() again.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_State {

	const DIR_OPTION   = 'bsr_data_dir';
	const MOVED_OPTION = 'bsr_data_dir_moved';
	const DIR_PREFIX   = 'bot-storm-radar-';
	const FILE         = 'state.php';
	const LOCK         = 'state.lock';

	/**
	 * What stays in a directory the plugin has left: the loader, because a
	 * prepend setting may still point at it, the marker that keeps it from
	 * running anything, and the two files that keep the directory closed.
	 */
	const LEFT_BEHIND = [ 'loader.php', 'disabled', 'index.php', '.htaccess' ];

	/**
	 * How long an unban keeps the address on the gate's recent-unban list.
	 */
	const RECENT_UNBAN = DAY_IN_SECONDS;

	/**
	 * Last rebuild error, for the Status tab and tests.
	 *
	 * @var string
	 */
	private static $error = '';

	public static function init() {
		add_action( 'botstormradar_bans_changed', [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'botstormradar_protected_changed', [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'update_option_' . Bot_Storm_Radar::OPTION_KEY, [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'update_option_' . BotStormRadar_Client_IP::CF_OPTION, [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'add_option_' . BotStormRadar_Client_IP::CF_OPTION, [ __CLASS__, 'rebuild' ], 10, 0 );
		// Daily, after the ban cleanup on the shared list-refresh hook.
		add_action( BotStormRadar_Client_IP::CRON_HOOK, [ __CLASS__, 'rebuild' ], 40, 0 );
	}

	// ── Data directory ────────────────────────────────────────────────

	/**
	 * Absolute path of the data directory with a trailing slash, created and
	 * protected on first use, or '' when it cannot be created.
	 *
	 * @return string
	 */
	public static function dir() {
		$suffix = (string) get_option( self::DIR_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $suffix ) ) {
			$suffix = bin2hex( random_bytes( 8 ) );
			update_option( self::DIR_OPTION, $suffix, true );
		}
		$dir = self::location( $suffix );
		if ( '' === $dir ) {
			self::$error = 'no uploads folder';
			return '';
		}
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			self::$error = 'cannot create ' . $dir;
			return '';
		}
		self::protect( $dir );
		return $dir;
	}

	/**
	 * Where the data directory belongs for a suffix, without creating it.
	 *
	 * @param string $suffix
	 * @return string
	 */
	private static function location( $suffix ) {
		$uploads = wp_upload_dir( null, false );
		$base    = is_array( $uploads ) ? (string) ( $uploads['basedir'] ?? '' ) : '';
		return '' === $base ? '' : trailingslashit( $base ) . self::DIR_PREFIX . $suffix . '/';
	}

	/**
	 * Absolute path of the state file, or '' when there is no data directory.
	 *
	 * @return string
	 */
	public static function path() {
		$dir = self::dir();
		return '' === $dir ? '' : $dir . self::FILE;
	}

	/**
	 * index.php and an Apache deny-all .htaccess, written once.
	 *
	 * @param string $dir
	 */
	private static function protect( $dir ) {
		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "# Bot Storm Radar data: never served.\n<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	// ── Contents ──────────────────────────────────────────────────────

	/**
	 * The state the gate needs, built from the tables and the options.
	 * Keys filled by later changes are present with empty values so the
	 * format stays the same.
	 *
	 * @param int|null $now Tests.
	 * @return array
	 */
	public static function contents( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		// A settings save fires the rebuild while this request's options
		// cache still holds the values it started with; read them fresh.
		BotStormRadar_Helpers::flush_options();
		$opts = BotStormRadar_Helpers::get_options();

		$bans = [];
		foreach ( BotStormRadar_Bans::active( $now ) as $b ) {
			// Asked again: a ban that became protected after it was written
			// (an allowlist entry, an administrator seen since) is left out.
			if ( BotStormRadar_Guard::may_ban( $b['ip_text'], (int) $b['prefix_len'] ) ) {
				$bans[] = [ $b['ip_text'], (int) $b['prefix_len'], (int) $b['expires_at'] ];
			}
		}
		$unbans = [];
		foreach ( BotStormRadar_Bans::recent_unbans( $now - self::RECENT_UNBAN ) as $u ) {
			$unbans[] = [ $u['ip_text'], (int) $u['prefix_len'], (int) $u['unbanned_at'] ];
		}

		return [
			'format'     => BotStormRadar_State_Reader::FORMAT,
			'written_at' => $now,
			'generation' => BotStormRadar_Bans::generation(),
			'mode'       => 'enforce' === ( $opts['ban_mode'] ?? 'observe' ) ? 'enforce' : 'observe',
			'trust'      => BotStormRadar_Client_IP::trust_config(),
			'protected'  => [
				'allowlist' => BotStormRadar_Helpers::parse_list( $opts['allowlist'] ?? '' ),
				'admins'    => BotStormRadar_Guard::admin_addresses( $now ),
				'bots'      => BotStormRadar_Guard::verified_bots( $now ),
			],
			'bans'       => $bans,
			'unbans'     => $unbans,
			'probe'      => self::probe_config(),
			'trips'      => BotStormRadar_Trips::state_config(),
			'mail'       => [ 'recipients' => BotStormRadar_Sources::alert_recipients( BotStormRadar_Sources::SITE ) ],
		];
	}

	/**
	 * The probe rules for BotStormRadar_Probe::classify(), from the options: switch,
	 * WordPress address path, uploads path, WordPress folder, the bundled
	 * scanner list, and the owner's patterns and exceptions compiled.
	 *
	 * @return array
	 */
	public static function probe_config() {
		static $scanner = null;
		if ( null === $scanner ) {
			$scanner = array_map( 'strtolower', BotStormRadar_Helpers::bundled_list( 'scanner-paths.txt' ) );
		}
		$opts    = BotStormRadar_Helpers::get_options();
		$uploads = wp_upload_dir( null, false );
		return [
			'on'      => ! empty( $opts['probe_refusal'] ),
			'home'    => untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ),
			'uploads' => trailingslashit( (string) wp_parse_url( (string) ( $uploads['baseurl'] ?? '' ), PHP_URL_PATH ) ),
			'root'    => ABSPATH,
			'scanner' => $scanner,
			'extra'   => BotStormRadar_Probe::compile( (string) ( $opts['probe_extra'] ?? '' ) ),
			'allow'   => BotStormRadar_Probe::compile( (string) ( $opts['probe_allow'] ?? '' ) ),
		];
	}

	// ── Rebuild ───────────────────────────────────────────────────────

	/**
	 * Rebuild the state file under an exclusive lock: build from the database,
	 * write a temporary, rename it into place. Returns true on success.
	 *
	 * @return bool
	 */
	public static function rebuild() {
		self::$error = '';
		$dir         = self::dir();
		if ( '' === $dir ) {
			return false;
		}
		$lock = fopen( $dir . self::LOCK, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $lock ) {
			self::$error = 'cannot open lock';
			return false;
		}
		flock( $lock, LOCK_EX );
		try {
			$body = BotStormRadar_State_Reader::encode( self::contents() );
			if ( false === $body ) {
				self::$error = 'cannot encode state';
				return false;
			}
			$tmp = $dir . 'state-' . bin2hex( random_bytes( 6 ) ) . '.tmp.php';
			if ( strlen( $body ) !== file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				self::$error = 'cannot write ' . $tmp;
				return false;
			}
			if ( ! rename( $tmp, $dir . self::FILE ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				self::$error = 'cannot rename into ' . self::FILE;
				return false;
			}
			return true;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * The last rebuild error, '' after a success.
	 *
	 * @return string
	 */
	public static function last_error() {
		return self::$error;
	}

	// ── The directory of 0.2.0 ────────────────────────────────────────

	/**
	 * Up to 0.2.0 the data directory sat directly in wp-content. Its path
	 * with a trailing slash for as long as it exists, otherwise ''.
	 *
	 * @return string
	 */
	public static function legacy_dir() {
		$suffix = (string) get_option( self::DIR_OPTION, '' );
		if ( ! preg_match( '/^[a-f0-9]{16}$/', $suffix ) ) {
			return '';
		}
		$old = trailingslashit( WP_CONTENT_DIR ) . self::DIR_PREFIX . $suffix . '/';
		return ( is_dir( $old ) && self::location( $suffix ) !== $old ) ? $old : '';
	}

	/**
	 * True while the old directory still runs the gate: it exists and the
	 * move has not been completed.
	 *
	 * @return bool
	 */
	public static function move_pending() {
		return '' !== self::legacy_dir() && ! is_array( get_option( self::MOVED_OPTION ) );
	}

	/**
	 * First step of the move, before the gate is installed in the new
	 * directory: an owner who switched the gate off with the marker keeps it
	 * switched off. Only while the new directory has no loader yet, so a
	 * repeated run copies nothing the move itself wrote.
	 */
	public static function carry_over() {
		if ( ! self::move_pending() ) {
			return;
		}
		$old = self::legacy_dir();
		$new = self::dir();
		if ( '' !== $new && is_file( $old . 'disabled' ) && ! is_file( $new . 'loader.php' ) && ! is_file( $new . 'disabled' ) ) {
			file_put_contents( $new . 'disabled', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Last step of the move, once the gate is installed in the new directory
	 * and the must-use plugin points there. The old directory is switched off
	 * with its marker and emptied down to LEFT_BEHIND: its loader stays,
	 * because an auto_prepend_file setting PHP still caches, or a line in
	 * wp-config.php, may point at it, and a prepend file that is missing
	 * breaks every request. With the marker the old loader runs nothing, so
	 * two copies of the gate never run in one request. remove_legacy()
	 * deletes the rest when nothing loads it any more.
	 *
	 * @param int|null $now Tests.
	 * @return bool
	 */
	public static function leave_legacy( $now = null ) {
		if ( ! self::move_pending() ) {
			return true;
		}
		$old = self::legacy_dir();
		if ( ! is_file( $old . 'disabled' ) && false === file_put_contents( $old . 'disabled', '' ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			self::$error = 'cannot switch off the gate in ' . $old;
			return false;
		}
		self::strip( $old );
		update_option( self::MOVED_OPTION, [ 'from' => $old, 'at' => null === $now ? time() : (int) $now, 'seen' => 0 ], false );
		return true;
	}

	/**
	 * The directory the plugin moved out of, while it still exists.
	 *
	 * @return array|null {from: string, at: int, seen: int}
	 */
	public static function left_behind() {
		$moved = get_option( self::MOVED_OPTION );
		if ( ! is_array( $moved ) || empty( $moved['from'] ) || ! is_dir( (string) $moved['from'] ) ) {
			return null;
		}
		return [ 'from' => (string) $moved['from'], 'at' => (int) ( $moved['at'] ?? 0 ), 'seen' => (int) ( $moved['seen'] ?? 0 ) ];
	}

	/**
	 * How long the old directory is kept at least: longer than PHP caches a
	 * per-directory ini file, with a wide margin.
	 *
	 * @return int Seconds.
	 */
	public static function legacy_wait() {
		return max( DAY_IN_SECONDS, 2 * (int) ini_get( 'user_ini.cache_ttl' ) );
	}

	/**
	 * Delete what is left of the old directory once nothing loads its loader:
	 * not in this request, not from the command line (which does not see the
	 * web server's prepend settings), and not before legacy_wait() has
	 * passed. A request that did load it is remembered, so the Settings tab
	 * can say that a setting still points there. Returns true when the
	 * directory is gone.
	 *
	 * @param int|null      $now      Tests.
	 * @param string|null   $sapi     Tests.
	 * @param string[]|null $included Files included in this request (tests).
	 * @return bool
	 */
	public static function remove_legacy( $now = null, $sapi = null, $included = null ) {
		$moved = get_option( self::MOVED_OPTION );
		if ( ! is_array( $moved ) || empty( $moved['from'] ) ) {
			return false;
		}
		$now = null === $now ? time() : (int) $now;
		$old = (string) $moved['from'];
		if ( ! is_dir( $old ) ) {
			delete_option( self::MOVED_OPTION );
			return true;
		}
		$loader   = $old . 'loader.php';
		$real     = realpath( $loader );
		$included = null === $included ? get_included_files() : $included;
		if ( in_array( $loader, $included, true ) || ( false !== $real && in_array( $real, $included, true ) ) ) {
			if ( $now - (int) ( $moved['seen'] ?? 0 ) >= HOUR_IN_SECONDS ) {
				$moved['seen'] = $now;
				update_option( self::MOVED_OPTION, $moved, false );
			}
			return false;
		}
		if ( 'cli' === ( null === $sapi ? PHP_SAPI : $sapi ) || $now - (int) ( $moved['at'] ?? $now ) < self::legacy_wait() ) {
			return false;
		}
		foreach ( self::LEFT_BEHIND as $f ) {
			if ( is_file( $old . $f ) ) {
				@unlink( $old . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
		@rmdir( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( is_dir( $old ) ) {
			return false; // Something that is not ours is in there.
		}
		delete_option( self::MOVED_OPTION );
		return true;
	}

	/**
	 * Delete every file of a directory except LEFT_BEHIND.
	 *
	 * @param string $dir
	 */
	private static function strip( $dir ) {
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( is_string( $f ) && is_file( $dir . $f ) && ! in_array( $f, self::LEFT_BEHIND, true ) ) {
				@unlink( $dir . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * Empty the data directory and forget its option (uninstall only). The
	 * gate loader and a `disabled` marker stay behind, with the directory: a
	 * cached auto_prepend_file line may still point at the loader, and a
	 * prepend file that is missing breaks every request. The marker keeps the
	 * loader from running anything. The same goes for the directory of 0.2.0
	 * where it still exists.
	 */
	public static function uninstall() {
		$suffix = (string) get_option( self::DIR_OPTION, '' );
		if ( preg_match( '/^[a-f0-9]{16}$/', $suffix ) ) {
			foreach ( array_filter( [ self::location( $suffix ), self::legacy_dir() ] ) as $left ) {
				if ( is_dir( $left ) ) {
					file_put_contents( $left . 'disabled', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					self::strip( $left );
				}
			}
		}
		delete_option( self::DIR_OPTION );
		delete_option( self::MOVED_OPTION );
	}
}
