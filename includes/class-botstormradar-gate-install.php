<?php
/**
 * Installs the gate: versioned gate copies in the data directory (in the
 * uploads folder; BotStormRadar_State describes it and the move out of wp-content), a manifest
 * naming the current one, a stable loader that reads the manifest, and a
 * small mu-plugin that includes the loader before any regular plugin.
 *
 * Gate copies (`gate-<version>-<hash>.php`) are generated from the source
 * files in SOURCES with every class
 * renamed to a BotStormRadar_Gate_ prefix, so they never collide with the plugin's own
 * classes when WordPress loads them later in the same request. A bundle is
 * checked with PHP's parser before it is used, and every file is written
 * through a temporary and a rename, so a request never includes half a file.
 *
 * Why versioned names: with opcache.validate_timestamps=0 a PHP worker keeps
 * running whatever version of a PHP file it compiled first, whatever is on
 * disk later. A new name is compiled fresh. The manifest (`manifest.php`,
 * `<?php exit; ?>` plus JSON) is read as data, so opcache never caches it,
 * and the loader (`loader.php`) is written once and never rewritten, so no
 * worker can hold a stale copy of it. The two previous gate copies are kept;
 * older ones are deleted once the manifest has pointed away from them for
 * more than an hour, which covers requests still running on them.
 *
 * The `disabled` marker: while a file of that name exists in the data
 * directory, the loader returns at once and no gate runs. Deactivation writes
 * it first; activation removes it last. An owner without wp-admin can create
 * it by SFTP to switch the gate off within one request.
 *
 * The mu-plugin (`wp-content/mu-plugins/bot-storm-radar-gate.php`) carries the
 * loader's absolute path, so it needs no option or database read, and
 * includes it only when it exists. Deactivation removes it. Early loading
 * through auto_prepend_file points at the same loader (a later change).
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Gate_Install {

	const LOADER_FILE   = 'loader.php';
	const MANIFEST_FILE = 'manifest.php';
	const DISABLED_FILE = 'disabled';
	const LEGACY_GATE   = 'gate.php';
	const MU_FILE       = 'bot-storm-radar-gate.php';
	const MU_MARKER     = 'Bot Storm Radar gate loader';

	/**
	 * Previous gate copies always kept, and how long a copy the manifest no
	 * longer names survives beyond those.
	 */
	const KEEP_PREVIOUS = 2;
	const RETIRE_AFTER  = HOUR_IN_SECONDS;
	const HISTORY_CAP   = 10;

	/**
	 * The source files bundled into a gate copy, in order.
	 */
	const SOURCES = [ 'class-botstormradar-ip-resolver.php', 'class-botstormradar-state-reader.php', 'class-botstormradar-probe.php', 'class-botstormradar-decision.php', 'class-botstormradar-channel.php', 'class-botstormradar-gate.php' ];

	/**
	 * Source class => class name inside the bundle. BotStormRadar_Gate first: the
	 * pattern is anchored on word boundaries, so it never touches the
	 * BOTSTORMRADAR_GATE constants or the already-renamed names.
	 */
	const RENAMES = [
		'BotStormRadar_Gate'         => 'BotStormRadar_Gate_Runner',
		'BotStormRadar_IP_Resolver'  => 'BotStormRadar_Gate_IP_Resolver',
		'BotStormRadar_State_Reader' => 'BotStormRadar_Gate_State_Reader',
		'BotStormRadar_Probe'        => 'BotStormRadar_Gate_Probe',
		'BotStormRadar_Decision'     => 'BotStormRadar_Gate_Decision',
		'BotStormRadar_Channel'      => 'BotStormRadar_Gate_Channel',
	];

	/**
	 * @var string
	 */
	private static $error = '';

	public static function init() {
		// Daily, on the shared list-refresh hook.
		add_action( BotStormRadar_Client_IP::CRON_HOOK, [ __CLASS__, 'finish_move' ], 45, 0 );
		add_action( BotStormRadar_Client_IP::CRON_HOOK, [ __CLASS__, 'retire' ], 50, 0 );
	}

	/**
	 * Daily: a move out of the old data directory that could not be completed
	 * (the new place or the must-use plugin could not be written) is tried
	 * again. Until it succeeds the old directory keeps running the gate.
	 */
	public static function finish_move() {
		if ( BotStormRadar_State::move_pending() ) {
			BotStormRadar_State::rebuild();
			self::install();
		}
	}

	/**
	 * Put the current gate in place: its versioned copy, the loader (only if
	 * missing), the manifest (only if it names another copy) and the
	 * mu-plugin. Returns true when all are in place.
	 *
	 * @param string|null $includes Source directory (tests).
	 * @param string|null $version  Version label (tests).
	 * @param int|null    $now      Tests.
	 * @return bool
	 */
	public static function install( $includes = null, $version = null, $now = null ) {
		self::$error = '';
		$now         = null === $now ? time() : (int) $now;
		$dir         = BotStormRadar_State::dir();
		if ( '' === $dir ) {
			self::$error = 'no data directory: ' . BotStormRadar_State::last_error();
			return false;
		}
		BotStormRadar_State::carry_over();
		$version = null === $version ? BOTSTORMRADAR_VERSION : (string) $version;
		$bundle  = self::bundle( null === $includes ? BOTSTORMRADAR_PLUGIN_DIR . 'includes/' : $includes, $version );
		if ( '' === $bundle ) {
			return false;
		}
		$name = 'gate-' . preg_replace( '/[^0-9A-Za-z.\-]/', '', $version ) . '-' . substr( sha1( $bundle ), 0, 10 ) . '.php';
		if ( ! is_file( $dir . $name ) && ! self::write_atomic( $dir . $name, $bundle ) ) {
			return false;
		}
		if ( ! is_file( $dir . self::LOADER_FILE ) && ! self::write_atomic( $dir . self::LOADER_FILE, self::loader_code() ) ) {
			return false;
		}
		$manifest = self::manifest();
		if ( ( $manifest['gate'] ?? '' ) !== $name ) {
			$previous = $manifest['previous'] ?? [];
			if ( ! empty( $manifest['gate'] ) ) {
				array_unshift( $previous, [ 'file' => $manifest['gate'], 'left' => $now ] );
			}
			$new = [
				'format'   => 1,
				'gate'     => $name,
				'version'  => $version,
				'written'  => $now,
				'previous' => array_slice( $previous, 0, self::HISTORY_CAP ),
			];
			if ( ! self::write_atomic( $dir . self::MANIFEST_FILE, BotStormRadar_State_Reader::encode( $new ) ) ) {
				return false;
			}
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			self::$error = 'cannot create ' . WPMU_PLUGIN_DIR;
			return false;
		}
		$mu      = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE;
		$mu_code = self::mu_plugin( $dir . self::LOADER_FILE );
		if ( ! is_file( $mu ) || file_get_contents( $mu ) !== $mu_code ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( ! self::write_atomic( $mu, $mu_code ) ) {
				return false;
			}
		}
		// Everything is in place in the data directory: leave the one of
		// 0.2.0, if it still runs the gate, and move a prepend line along.
		if ( BotStormRadar_State::leave_legacy( $now ) ) {
			BotStormRadar_Gate_Early::repoint();
		}
		self::retire( $now );
		return true;
	}

	/**
	 * The manifest, or an empty array.
	 *
	 * @return array
	 */
	public static function manifest() {
		$dir = BotStormRadar_State::dir();
		if ( '' === $dir ) {
			return [];
		}
		$m = BotStormRadar_State_Reader::read( $dir . self::MANIFEST_FILE );
		return is_array( $m ) ? $m : [];
	}

	/**
	 * Delete gate copies the manifest no longer needs: keep the current one
	 * and the KEEP_PREVIOUS most recent previous ones; delete any other once
	 * the manifest left it more than RETIRE_AFTER ago. A copy with no history
	 * entry (and the fixed-name gate.php of 0.2 development builds) goes once
	 * its file is older than a day. Returns the files deleted.
	 *
	 * @param int|null $now
	 * @return string[]
	 */
	public static function retire( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$dir = BotStormRadar_State::dir();
		if ( '' === $dir ) {
			return [];
		}
		$manifest = self::manifest();
		$current  = (string) ( $manifest['gate'] ?? '' );
		if ( '' === $current ) {
			return []; // Without a manifest nothing is known to be unused.
		}
		$keep = [ $current => true ];
		$left = [];
		foreach ( array_values( (array) ( $manifest['previous'] ?? [] ) ) as $i => $p ) {
			$file = (string) ( $p['file'] ?? '' );
			if ( $i < self::KEEP_PREVIOUS ) {
				$keep[ $file ] = true;
			}
			if ( ! isset( $left[ $file ] ) ) {
				$left[ $file ] = (int) ( $p['left'] ?? $now );
			}
		}
		$deleted = [];
		foreach ( (array) scandir( $dir ) as $f ) {
			if ( ! is_string( $f ) || isset( $keep[ $f ] ) ) {
				continue;
			}
			if ( self::LEGACY_GATE !== $f && ! preg_match( '/^gate-[0-9A-Za-z.\-]+\.php$/', $f ) ) {
				continue;
			}
			$since = $left[ $f ] ?? null;
			$old   = null === $since ? ( (int) @filemtime( $dir . $f ) < $now - DAY_IN_SECONDS ) : ( $since < $now - self::RETIRE_AFTER ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $old && @unlink( $dir . $f ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				$deleted[] = $f;
			}
		}
		BotStormRadar_State::remove_legacy( $now );
		return $deleted;
	}

	/**
	 * Switch the gate off at once: the loader returns while the marker exists.
	 *
	 * @return bool
	 */
	public static function disable() {
		$dir = BotStormRadar_State::dir();
		return '' !== $dir && ( is_file( $dir . self::DISABLED_FILE ) || false !== file_put_contents( $dir . self::DISABLED_FILE, '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Remove the marker (activation, last step).
	 *
	 * @return bool
	 */
	public static function enable() {
		$dir = BotStormRadar_State::dir();
		return '' !== $dir && ( ! is_file( $dir . self::DISABLED_FILE ) || @unlink( $dir . self::DISABLED_FILE ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
	}

	/**
	 * Remove the mu-plugin (deactivation and uninstall). The loader and the
	 * marker stay in the data directory: a cached auto_prepend_file line may
	 * still point at the loader.
	 */
	public static function remove_loader() {
		$mu = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE;
		if ( is_file( $mu ) && false !== strpos( (string) file_get_contents( $mu ), self::MU_MARKER ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * What is in place.
	 *
	 * @return array {loader: bool, mu_plugin: bool, disabled: bool, gate: string, gate_present: bool, version: string}
	 */
	public static function status() {
		$dir      = BotStormRadar_State::dir();
		$manifest = self::manifest();
		$gate     = (string) ( $manifest['gate'] ?? '' );
		return [
			'loader'       => '' !== $dir && is_file( $dir . self::LOADER_FILE ),
			'mu_plugin'    => is_file( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE ),
			'disabled'     => '' !== $dir && is_file( $dir . self::DISABLED_FILE ),
			'gate'         => $gate,
			'gate_present' => '' !== $gate && is_file( $dir . $gate ),
			'version'      => (string) ( $manifest['version'] ?? '' ),
		];
	}

	/**
	 * The loader. Written once and never rewritten, so it must stay generic:
	 * it only knows its own directory. No WordPress, no opcache dependency
	 * (the manifest is read as data), no failure mode that breaks a request.
	 *
	 * @return string
	 */
	public static function loader_code() {
		return <<<'LOADER'
<?php
/**
 * Bot Storm Radar gate loader. Written once by the plugin and never rewritten.
 *
 * It includes the gate copy named in manifest.php next to it. While a file
 * named "disabled" exists next to it, it does nothing: create that empty file
 * to switch the gate off without wp-admin. A missing or unreadable manifest,
 * or a missing gate copy, also means no gate; the site keeps working.
 */
if ( ! is_file( __DIR__ . '/disabled' ) ) {
	$botstormradar_gate_manifest = @file_get_contents( __DIR__ . '/manifest.php' );
	if ( is_string( $botstormradar_gate_manifest ) && 0 === strpos( $botstormradar_gate_manifest, "<?php exit; ?>\n" ) ) {
		$botstormradar_gate_manifest = json_decode( substr( $botstormradar_gate_manifest, 15 ), true );
		if ( is_array( $botstormradar_gate_manifest ) && isset( $botstormradar_gate_manifest['gate'] ) && is_string( $botstormradar_gate_manifest['gate'] )
			&& preg_match( '/^gate-[0-9A-Za-z.\-]+\.php$/', $botstormradar_gate_manifest['gate'] )
			&& is_file( __DIR__ . '/' . $botstormradar_gate_manifest['gate'] ) ) {
			if ( ! defined( 'ABSPATH' ) && ! defined( 'BOTSTORMRADAR_GATE_EARLY' ) ) {
				define( 'BOTSTORMRADAR_GATE_EARLY', true ); // Loaded before WordPress (auto_prepend_file or wp-config).
			}
			include_once __DIR__ . '/' . $botstormradar_gate_manifest['gate'];
		}
	}
	unset( $botstormradar_gate_manifest );
}

LOADER;
	}

	/**
	 * @return string
	 */
	public static function last_error() {
		return self::$error;
	}

	/**
	 * Build the self-contained gate. Returns '' when a source is missing or
	 * the result does not parse.
	 *
	 * @param string $includes Directory holding the SOURCES files.
	 * @param string $version
	 * @return string
	 */
	public static function bundle( $includes, $version ) {
		$parts = [];
		foreach ( self::SOURCES as $f ) {
			$src = @file_get_contents( $includes . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( ! is_string( $src ) || 0 !== strpos( $src, '<?php' ) ) {
				self::$error = 'missing source ' . $f;
				return '';
			}
			$src = substr( $src, 5 );
			$src = preg_replace( "/defined\\( 'BOTSTORMRADAR_GATE' \\) \\|\\| defined\\( 'ABSPATH' \\) \\|\\| exit;/", '', $src, 1, $count );
			if ( 1 !== $count ) {
				self::$error = 'unexpected guard in ' . $f;
				return '';
			}
			$parts[] = $src;
		}
		$code = implode( "\n", $parts );
		foreach ( self::RENAMES as $from => $to ) {
			$code = preg_replace( '/\b' . $from . '\b/', $to, $code );
		}
		$bundle = "<?php\n/**\n * Bot Storm Radar gate, generated for version " . $version . ". Do not edit:\n * the plugin rewrites this file from its includes/ on activation and update.\n */\n"
			// Every gate copy defines this constant: BOTSTORMRADAR_GATE now,
			// BSR_GATE up to 0.2.0. A second copy in the same request (an old
			// prepend line and the must-use plugin pointing at two directories)
			// returns here, before it would declare the same classes again.
			. "if ( defined( 'BOTSTORMRADAR_GATE' ) || defined( 'BSR_GATE' ) ) {\n\treturn;\n}\ndefine( 'BOTSTORMRADAR_GATE', true );\n"
			. $code
			. "\nBotStormRadar_Gate_Runner::run( __DIR__ . '/state.php' );\n";
		try {
			token_get_all( $bundle, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			self::$error = 'bundle does not parse: ' . $e->getMessage();
			return '';
		}
		return $bundle;
	}

	/**
	 * The mu-plugin that includes the loader at an absolute path.
	 *
	 * @param string $loader
	 * @return string
	 */
	public static function mu_plugin( $loader ) {
		return "<?php\n/**\n * Plugin Name: " . self::MU_MARKER . "\n * Description: Loads the Bot Storm Radar gate before other plugins. Written by Bot Storm Radar, removed on deactivation.\n */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n"
			. '$botstormradar_gate_file = ' . var_export( $loader, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			. "if ( is_file( \$botstormradar_gate_file ) ) {\n\tinclude_once \$botstormradar_gate_file;\n}\nunset( \$botstormradar_gate_file );\n";
	}

	/**
	 * @param string $path
	 * @param string $contents
	 * @return bool
	 */
	private static function write_atomic( $path, $contents ) {
		// Never a .php name: WordPress loads every *.php in mu-plugins, dotfiles
		// included, so a half-written temporary there would be executed.
		$tmp = dirname( $path ) . '/.' . basename( $path, '.php' ) . '-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		if ( strlen( $contents ) !== file_put_contents( $tmp, $contents ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			self::$error = 'cannot write ' . $tmp;
			return false;
		}
		if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			self::$error = 'cannot rename into ' . $path;
			return false;
		}
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return true;
	}
}
