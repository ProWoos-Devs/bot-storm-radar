<?php
/**
 * Early loading of the gate through auto_prepend_file, opt-in.
 *
 * With early loading the gate runs before WordPress: before the database
 * connection, before the options load, and still while the database is down.
 * The owner switches it on from the Settings tab. The plugin then writes one
 * marked block pointing auto_prepend_file at the gate loader:
 *   - PHP-FPM and CGI (FastCGI): `.user.ini` in the WordPress root. PHP re-reads
 *     it every user_ini.cache_ttl seconds, so the change can take that long;
 *     the screen shows the real value;
 *   - Apache mod_php: `php_value auto_prepend_file` in the root `.htaccess`.
 * A loopback request to admin-ajax.php (always PHP, never page-cached) with an
 * `X-BSR-Gate-Check` header proves it: the gate answers `X-BSR-Gate: early`
 * when it ran before WordPress, `mu` when only the mu-plugin loaded it.
 *
 * Never overwritten or chained: another auto_prepend_file already in force
 * (a firewall plugin's, for example), or an auto_prepend_file line outside
 * our block in the file we would write. The plugin reports the conflict and
 * the gate stays on the mu-plugin.
 *
 * A third way, for hosts that ignore both files, is one line in wp-config.php
 * above the wp-settings.php line (README); the plugin never edits wp-config.php.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Gate_Early {

	const OPTION       = 'bsr_gate_early';
	const MARKER       = 'Bot Storm Radar gate';
	const CHECK_HEADER = 'X-BSR-Gate-Check';

	/**
	 * @var string
	 */
	private static $error = '';

	/**
	 * Which mechanism this server honors: 'user_ini' (FastCGI with a per-user
	 * ini file name), 'htaccess' (Apache mod_php) or '' (neither).
	 *
	 * @param string|null $sapi Tests.
	 * @return string
	 */
	public static function method( $sapi = null ) {
		$sapi = null === $sapi ? PHP_SAPI : (string) $sapi;
		if ( in_array( $sapi, [ 'fpm-fcgi', 'cgi-fcgi' ], true ) ) {
			return '' !== (string) ini_get( 'user_ini.filename' ) ? 'user_ini' : '';
		}
		return 'apache2handler' === $sapi ? 'htaccess' : '';
	}

	/**
	 * The file the block goes into for a method.
	 *
	 * @param string $method
	 * @return string
	 */
	public static function target( $method ) {
		if ( 'user_ini' === $method ) {
			$name = (string) ini_get( 'user_ini.filename' );
			return ABSPATH . ( '' !== $name ? $name : '.user.ini' );
		}
		return 'htaccess' === $method ? ABSPATH . '.htaccess' : '';
	}

	/**
	 * Absolute path of the gate loader.
	 *
	 * @return string
	 */
	public static function loader_path() {
		$dir = BSR_State::dir();
		return '' === $dir ? '' : $dir . BSR_Gate_Install::LOADER_FILE;
	}

	/**
	 * Another prepend that would be overwritten or chained, or '' when none.
	 *
	 * @param string      $method
	 * @param string|null $current auto_prepend_file in force for this request (tests).
	 * @param string|null $file    Contents of the target file (tests).
	 * @return string
	 */
	public static function conflict( $method, $current = null, $file = null ) {
		$current = null === $current ? (string) ini_get( 'auto_prepend_file' ) : (string) $current;
		$loader  = self::loader_path();
		// Our own loader in the data directory of 0.2.0 is no conflict: PHP
		// may still cache the line that pointed there before the move.
		$legacy = BSR_State::legacy_dir();
		$ours   = self::same_file( $current, $loader ) || ( '' !== $legacy && self::same_file( $current, $legacy . BSR_Gate_Install::LOADER_FILE ) );
		if ( '' !== $current && ! $ours ) {
			return $current;
		}
		if ( null === $file ) {
			$target = self::target( $method );
			$file   = '' !== $target && is_readable( $target ) ? (string) file_get_contents( $target ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$outside = self::strip_block( $file, $method );
		if ( preg_match( '/^\s*(php_value\s+)?auto_prepend_file\b.*$/mi', $outside, $m ) ) {
			return trim( $m[0] );
		}
		return '';
	}

	/**
	 * Switch early loading on: write the block. Verification is separate
	 * (verify()), because PHP-FPM can take user_ini.cache_ttl seconds to read it.
	 *
	 * @return bool
	 */
	public static function enable() {
		self::$error = '';
		$method      = self::method();
		if ( '' === $method ) {
			self::$error = 'unsupported';
			return false;
		}
		$loader = self::loader_path();
		if ( '' === $loader || ! is_file( $loader ) ) {
			self::$error = 'no loader';
			return false;
		}
		$conflict = self::conflict( $method );
		if ( '' !== $conflict ) {
			self::$error = 'conflict: ' . $conflict;
			return false;
		}
		$target = self::target( $method );
		if ( ! self::write_block( $target, $method, self::block( $method, $loader ) ) ) {
			return false;
		}
		update_option( self::OPTION, [ 'method' => $method, 'target' => $target, 'loader' => $loader, 'at' => time(), 'result' => 'pending', 'checked' => 0 ], false );
		return true;
	}

	/**
	 * After the data directory moved: point the block at the loader's new
	 * place. PHP-FPM keeps the old line for up to user_ini.cache_ttl seconds;
	 * the old loader stays where it was and runs nothing meanwhile, so those
	 * requests get the gate from the must-use plugin.
	 *
	 * @return bool
	 */
	public static function repoint() {
		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) || empty( $state['method'] ) ) {
			return true;
		}
		$loader = self::loader_path();
		if ( '' === $loader || ! is_file( $loader ) || $loader === ( $state['loader'] ?? '' ) ) {
			return true;
		}
		$method = (string) $state['method'];
		$target = ! empty( $state['target'] ) ? (string) $state['target'] : self::target( $method );
		if ( ! self::write_block( $target, $method, self::block( $method, $loader ) ) ) {
			return false;
		}
		update_option( self::OPTION, [ 'loader' => $loader, 'at' => time(), 'result' => 'pending', 'checked' => 0 ] + $state, false );
		return true;
	}

	/**
	 * Switch early loading off: remove the block wherever the plugin wrote it.
	 * Deactivation calls this after writing the `disabled` marker, so a
	 * prepend line PHP still caches runs no gate.
	 *
	 * @return bool
	 */
	public static function disable() {
		self::$error = '';
		$ok          = true;
		$stored      = get_option( self::OPTION, [] );
		foreach ( [ 'user_ini' => '.user.ini', 'htaccess' => '.htaccess' ] as $method => $default ) {
			$targets = [ ABSPATH . $default, self::target( $method ) ];
			if ( is_array( $stored ) && $method === ( $stored['method'] ?? '' ) && ! empty( $stored['target'] ) ) {
				$targets[] = (string) $stored['target'];
			}
			foreach ( array_unique( array_filter( $targets ) ) as $t ) {
				if ( is_file( $t ) && false !== strpos( (string) file_get_contents( $t ), self::begin( $method ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$ok = self::write_block( $t, $method, '' ) && $ok;
				}
			}
		}
		delete_option( self::OPTION );
		return $ok;
	}

	/**
	 * Loopback check: 'early', 'mu', 'none' or 'error: ...'. Stored with the time.
	 *
	 * @return string
	 */
	public static function verify() {
		$r = wp_remote_get( admin_url( 'admin-ajax.php?action=bsr_gate_check&_=' . wp_rand() ), [
			'timeout'     => 10,
			'redirection' => 3,
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- core filter.
			'headers'     => [ self::CHECK_HEADER => '1' ],
		] );
		if ( is_wp_error( $r ) ) {
			$result = 'error: ' . $r->get_error_message();
		} else {
			$h      = strtolower( (string) wp_remote_retrieve_header( $r, 'x-bsr-gate' ) );
			$result = in_array( $h, [ 'early', 'mu' ], true ) ? $h : 'none';
		}
		$state = get_option( self::OPTION, [] );
		if ( is_array( $state ) && ! empty( $state ) ) {
			$state['result']  = $result;
			$state['checked'] = time();
			update_option( self::OPTION, $state, false );
		}
		return $result;
	}

	/**
	 * Everything the Settings tab shows.
	 *
	 * @return array
	 */
	public static function status() {
		$method = self::method();
		$state  = get_option( self::OPTION, [] );
		return [
			'method'    => $method,
			'target'    => self::target( $method ),
			'cache_ttl' => 'user_ini' === $method ? (int) ini_get( 'user_ini.cache_ttl' ) : 0,
			'enabled'   => is_array( $state ) && ! empty( $state['method'] ),
			'result'    => is_array( $state ) ? (string) ( $state['result'] ?? '' ) : '',
			'checked'   => is_array( $state ) ? (int) ( $state['checked'] ?? 0 ) : 0,
			'at'        => is_array( $state ) ? (int) ( $state['at'] ?? 0 ) : 0,
			'current'   => (string) ini_get( 'auto_prepend_file' ),
			'conflict'  => '' === $method ? '' : self::conflict( $method ),
			'loader'    => self::loader_path(),
		];
	}

	/**
	 * @return string
	 */
	public static function last_error() {
		return self::$error;
	}

	// ── Blocks ────────────────────────────────────────────────────────

	/**
	 * The lines between the markers for a method.
	 *
	 * @param string $method
	 * @param string $loader
	 * @return string
	 */
	public static function block( $method, $loader ) {
		if ( 'user_ini' === $method ) {
			return 'auto_prepend_file = "' . $loader . "\"\n";
		}
		$lines = '';
		foreach ( [ 'php_module', 'mod_php7.c', 'mod_php.c' ] as $mod ) {
			$lines .= "<IfModule {$mod}>\n\tphp_value auto_prepend_file \"{$loader}\"\n</IfModule>\n";
		}
		return $lines;
	}

	/**
	 * @param string $method
	 * @return string
	 */
	private static function begin( $method ) {
		return ( 'user_ini' === $method ? '; ' : '# ' ) . 'BEGIN ' . self::MARKER;
	}

	/**
	 * @param string $method
	 * @return string
	 */
	private static function end( $method ) {
		return ( 'user_ini' === $method ? '; ' : '# ' ) . 'END ' . self::MARKER;
	}

	/**
	 * The file contents without our block.
	 *
	 * @param string $contents
	 * @param string $method
	 * @return string
	 */
	public static function strip_block( $contents, $method ) {
		$re = '/^' . preg_quote( self::begin( $method ), '/' ) . '\R.*?^' . preg_quote( self::end( $method ), '/' ) . '\R?/ms';
		return (string) preg_replace( $re, '', (string) $contents );
	}

	/**
	 * Replace our block in a file (or remove it with an empty body), keeping
	 * everything else. The block goes first, so it applies whatever follows.
	 * Written through a temporary and a rename.
	 *
	 * @param string $target
	 * @param string $method
	 * @param string $body
	 * @return bool
	 */
	private static function write_block( $target, $method, $body ) {
		$old = is_file( $target ) ? file_get_contents( $target ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $old ) {
			self::$error = 'cannot read ' . $target;
			return false;
		}
		$rest = self::strip_block( $old, $method );
		$new  = '' === $body ? $rest : self::begin( $method ) . "\n" . $body . self::end( $method ) . "\n" . $rest;
		if ( $new === $old ) {
			return true;
		}
		$tmp = $target . '.bsr-' . bin2hex( random_bytes( 6 ) ) . '.tmp';
		if ( strlen( $new ) !== file_put_contents( $tmp, $new ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			self::$error = 'cannot write ' . $target;
			return false;
		}
		if ( is_file( $target ) ) {
			@chmod( $tmp, fileperms( $target ) & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		if ( ! rename( $tmp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			self::$error = 'cannot replace ' . $target;
			return false;
		}
		return true;
	}

	/**
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	private static function same_file( $a, $b ) {
		if ( '' === $a || '' === $b ) {
			return false;
		}
		$ra = realpath( $a );
		$rb = realpath( $b );
		return false !== $ra && false !== $rb ? $ra === $rb : $a === $b;
	}
}
