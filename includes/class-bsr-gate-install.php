<?php
/**
 * Installs the gate: a self-contained gate file in the data directory and a
 * small mu-plugin that loads it before any regular plugin.
 *
 * The gate file (`gate.php` in the data directory) is generated from three
 * source files (BSR_IP_Resolver, BSR_State_Reader, BSR_Gate) with every class
 * renamed to a BSR_Gate_ prefix, so it never collides with the plugin's own
 * classes when WordPress loads them later in the same request. The bundle is
 * checked with PHP's parser before it replaces the previous one, and written
 * through a temporary and a rename, so a request never includes half a file.
 *
 * The mu-plugin (`wp-content/mu-plugins/bot-storm-radar-gate.php`) carries
 * the gate file's absolute path, so it needs no option or database read. It
 * includes the gate only when the file exists, so a deleted data directory
 * never breaks the site. Deactivation removes it.
 *
 * The gate file keeps a fixed name for now; with opcache.validate_timestamps=0
 * a worker keeps the version it compiled first. Versioned copies behind a
 * manifest, and early loading through auto_prepend_file, are the next change.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Gate_Install {

	const GATE_FILE = 'gate.php';
	const MU_FILE   = 'bot-storm-radar-gate.php';
	const MU_MARKER = 'Bot Storm Radar gate loader';

	/**
	 * Source class => class name inside the bundle. BSR_Gate first: the
	 * pattern is anchored on word boundaries, so it never touches the
	 * BSR_GATE constants or the already-renamed names.
	 */
	const RENAMES = [
		'BSR_Gate'         => 'BSR_Gate_Runner',
		'BSR_IP_Resolver'  => 'BSR_Gate_IP_Resolver',
		'BSR_State_Reader' => 'BSR_Gate_State_Reader',
	];

	/**
	 * @var string
	 */
	private static $error = '';

	/**
	 * Write the gate file and the mu-plugin. Returns true when both are in place.
	 *
	 * @return bool
	 */
	public static function install() {
		self::$error = '';
		$dir         = BSR_State::dir();
		if ( '' === $dir ) {
			self::$error = 'no data directory: ' . BSR_State::last_error();
			return false;
		}
		$bundle = self::bundle( BSR_PLUGIN_DIR . 'includes/', BSR_VERSION );
		if ( '' === $bundle ) {
			return false;
		}
		if ( ! self::write_atomic( $dir . self::GATE_FILE, $bundle ) ) {
			return false;
		}
		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			self::$error = 'cannot create ' . WPMU_PLUGIN_DIR;
			return false;
		}
		return self::write_atomic( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE, self::mu_plugin( $dir . self::GATE_FILE ) );
	}

	/**
	 * Remove the mu-plugin (deactivation and uninstall). The gate file stays
	 * in the data directory, which uninstall removes as a whole.
	 */
	public static function remove_loader() {
		$mu = trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE;
		if ( is_file( $mu ) && false !== strpos( (string) file_get_contents( $mu ), self::MU_MARKER ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * Whether the loader and the gate file are in place, and which version.
	 *
	 * @return array {loader: bool, gate: bool, version: string}
	 */
	public static function status() {
		$dir     = BSR_State::dir();
		$gate    = '' !== $dir && is_file( $dir . self::GATE_FILE );
		$version = '';
		if ( $gate && preg_match( '/generated for version ([0-9][0-9A-Za-z.\-]*[0-9A-Za-z])/', (string) file_get_contents( $dir . self::GATE_FILE, false, null, 0, 400 ), $m ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$version = $m[1];
		}
		return [
			'loader'  => is_file( trailingslashit( WPMU_PLUGIN_DIR ) . self::MU_FILE ),
			'gate'    => $gate,
			'version' => $version,
		];
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
	 * @param string $includes Directory holding the three source files.
	 * @param string $version
	 * @return string
	 */
	public static function bundle( $includes, $version ) {
		$parts = [];
		foreach ( [ 'class-bsr-ip-resolver.php', 'class-bsr-state-reader.php', 'class-bsr-gate.php' ] as $f ) {
			$src = @file_get_contents( $includes . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( ! is_string( $src ) || 0 !== strpos( $src, '<?php' ) ) {
				self::$error = 'missing source ' . $f;
				return '';
			}
			$src = substr( $src, 5 );
			$src = preg_replace( "/if \\( ! defined\\( 'ABSPATH' \\) && ! defined\\( 'BSR_GATE' \\) \\) \\{\\s*exit;\\s*\\}/", '', $src, 1, $count );
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
			. "if ( ! defined( 'BSR_GATE' ) ) {\n\tdefine( 'BSR_GATE', true );\n}\n"
			. $code
			. "\nBSR_Gate_Runner::run( __DIR__ . '/state.php' );\n";
		try {
			token_get_all( $bundle, TOKEN_PARSE );
		} catch ( ParseError $e ) {
			self::$error = 'bundle does not parse: ' . $e->getMessage();
			return '';
		}
		return $bundle;
	}

	/**
	 * The mu-plugin that loads the gate file at an absolute path.
	 *
	 * @param string $gate
	 * @return string
	 */
	public static function mu_plugin( $gate ) {
		return "<?php\n/**\n * Plugin Name: " . self::MU_MARKER . "\n * Description: Loads the Bot Storm Radar gate before other plugins. Written by Bot Storm Radar, removed on deactivation.\n */\n\n"
			. "if ( ! defined( 'ABSPATH' ) ) {\n\texit;\n}\n"
			. '$bsr_gate_file = ' . var_export( $gate, true ) . ";\n" // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
			. "if ( is_file( \$bsr_gate_file ) ) {\n\tinclude_once \$bsr_gate_file;\n}\nunset( \$bsr_gate_file );\n";
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
