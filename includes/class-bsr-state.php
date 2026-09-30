<?php
/**
 * The gate's state file and the plugin's data directory.
 *
 * Data directory: `wp-content/bot-storm-radar-<random>/`, the suffix created
 * once and kept in option `bsr_data_dir`. It holds an `index.php`, an Apache
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
 * Rebuilt on: a ban or unban (`bsr_bans_changed`), a settings save, a
 * Cloudflare range refresh, and daily. The protected-address lists
 * (administrators, verified bots) are filled by the guard (#27), which calls
 * rebuild() when they change.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_State {

	const DIR_OPTION = 'bsr_data_dir';
	const DIR_PREFIX = 'bot-storm-radar-';
	const FILE       = 'state.php';
	const LOCK       = 'state.lock';

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
		add_action( 'bsr_bans_changed', [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'update_option_' . Bot_Storm_Radar::OPTION_KEY, [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'update_option_' . BSR_Client_IP::CF_OPTION, [ __CLASS__, 'rebuild' ], 10, 0 );
		add_action( 'add_option_' . BSR_Client_IP::CF_OPTION, [ __CLASS__, 'rebuild' ], 10, 0 );
		// Daily, after the ban cleanup on the shared list-refresh hook.
		add_action( BSR_Client_IP::CRON_HOOK, [ __CLASS__, 'rebuild' ], 40, 0 );
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
		$dir = trailingslashit( WP_CONTENT_DIR ) . self::DIR_PREFIX . $suffix . '/';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			self::$error = 'cannot create ' . $dir;
			return '';
		}
		self::protect( $dir );
		return $dir;
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
		BSR_Helpers::flush_options();
		$opts = BSR_Helpers::get_options();

		$bans = [];
		foreach ( BSR_Bans::active( $now ) as $b ) {
			$bans[] = [ $b['ip_text'], (int) $b['prefix_len'], (int) $b['expires_at'] ];
		}
		$unbans = [];
		foreach ( BSR_Bans::recent_unbans( $now - self::RECENT_UNBAN ) as $u ) {
			$unbans[] = [ $u['ip_text'], (int) $u['prefix_len'], (int) $u['unbanned_at'] ];
		}

		return [
			'format'     => BSR_State_Reader::FORMAT,
			'written_at' => $now,
			'generation' => BSR_Bans::generation(),
			'mode'       => 'enforce' === ( $opts['ban_mode'] ?? 'observe' ) ? 'enforce' : 'observe',
			'trust'      => BSR_Client_IP::trust_config(),
			'protected'  => [
				'allowlist' => BSR_Helpers::parse_list( $opts['allowlist'] ?? '' ),
				'admins'    => [],
				'bots'      => [],
			],
			'bans'       => $bans,
			'unbans'     => $unbans,
			'probe'      => [],
			'trips'      => [],
			'mail'       => [ 'recipients' => BSR_Sources::alert_recipients( BSR_Sources::SITE ) ],
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
			$body = BSR_State_Reader::encode( self::contents() );
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

	/**
	 * Remove the data directory and its option (uninstall only).
	 */
	public static function uninstall() {
		$suffix = (string) get_option( self::DIR_OPTION, '' );
		if ( preg_match( '/^[a-f0-9]{16}$/', $suffix ) ) {
			$dir = trailingslashit( WP_CONTENT_DIR ) . self::DIR_PREFIX . $suffix . '/';
			if ( is_dir( $dir ) ) {
				foreach ( (array) scandir( $dir ) as $f ) {
					if ( is_string( $f ) && is_file( $dir . $f ) ) {
						@unlink( $dir . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
					}
				}
				@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
		delete_option( self::DIR_OPTION );
	}
}
