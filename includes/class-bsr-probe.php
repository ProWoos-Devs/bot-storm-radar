<?php
/**
 * Probe classes: requests for files only scanners ask for.
 *
 * On a site where unknown paths are routed to index.php, every such request
 * is otherwise a full WordPress build that ends in a 404. The gate refuses
 * them with 403 before WordPress loads; the radar's classifier counts the ones
 * that still reach WordPress as the `probe` class. Pure functions of the path
 * and a configuration, no WordPress, so the gate bundles this class.
 *
 * Classes, checked in this order after the owner's exceptions:
 * Always allowed: /.well-known/ and compressed sitemaps (sitemap*.xml.gz),
 * which sitemap plugins serve through PHP.
 *
 *   dotfile    a path segment that starts with a dot (/.env, /.git/config,
 *              /.github/workflows/deploy.yml, /www/.git-credentials), except
 *              /.well-known/, which certificates and security.txt need;
 *   php-copy   a copy of a PHP source file (config.php.txt, wp-config.php.bak,
 *              index.php~, wp-config.php.save);
 *   config     a configuration or log file (.yml .yaml .toml .ini .log .lock
 *              .cfg .conf) or another stack's config or secrets file by name
 *              (package.json, composer.json, ecosystem.config.js, web.config,
 *              credentials, ...);
 *   archive    an archive, dump or backup name outside the uploads folder
 *              (.zip .tar .tgz .gz .bz2 .tbz2 .xz .zst .rar .7z .sql .bak ...);
 *   scanner    a path prefix from the bundled list of other applications'
 *              admin and debug paths (phpMyAdmin, phpunit, cgi-bin, ...);
 *   custom     a pattern the owner added;
 *   missing-php a .php path that does not exist on disk (only reaches PHP when
 *              the web server routes unknown paths to index.php).
 *
 * Paths the web server serves itself (an existing .zip, say) never reach PHP,
 * so these rules only ever turn a WordPress 404 into a cheap 403.
 *
 * Owner patterns and exceptions: one per line, matched case-insensitively
 * against the path below the WordPress address, `*` matching anything.
 * `/downloads/*.zip` excepts every zip below /downloads/.
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress or by the gate, which defines BSR_GATE first.
defined( 'BSR_GATE' ) || defined( 'ABSPATH' ) || exit;

class BSR_Probe {

	const ARCHIVE_EXTENSIONS = 'zip|tar|tgz|gz|bz2|tbz2|tbz|xz|zst|lz4|rar|7z|sql|bak|old|orig|save|swp';

	/**
	 * Configuration and log files WordPress never serves through PHP.
	 */
	const CONFIG_EXTENSIONS = 'ya?ml|toml|ini|log|lock|cfg|conf';

	/**
	 * Exact file names (any folder) of other stacks' configuration and
	 * secrets. Not .js or .json in general: PWA and service-worker plugins
	 * serve manifest.json or sw.js through PHP.
	 */
	const CONFIG_NAMES = [ 'package.json', 'package-lock.json', 'npm-shrinkwrap.json', 'composer.json', 'yarn.lock', 'ecosystem.config.js', 'ecosystem.config.json', 'deploy.json', 'credentials', 'credentials.json', 'secrets.json', 'appsettings.json', 'dockerfile', 'web.config', 'id_rsa', 'id_ed25519' ];

	/**
	 * The probe class of a request path, or '' when it is not a probe.
	 *
	 * @param string $request_uri Path with or without a query string.
	 * @param array  $cfg         on, home (path of the WordPress address, '' or
	 *                            '/sub'), uploads (path of the uploads folder),
	 *                            root (filesystem path of the WordPress folder,
	 *                            '' to skip the missing-php check), scanner
	 *                            (prefixes), extra and allow (patterns).
	 * @param string $script      SCRIPT_FILENAME of the request, '' if unknown.
	 * @return string
	 */
	public static function classify( $request_uri, array $cfg, $script = '' ) {
		if ( empty( $cfg['on'] ) ) {
			return '';
		}
		// Not parse_url(): it reads a leading // as a host name (//.git//config).
		$path = (string) $request_uri;
		$cut  = strcspn( $path, '?#' );
		$path = strtolower( rawurldecode( substr( $path, 0, $cut ) ) );
		$path = '/' . ltrim( preg_replace( '#/{2,}#', '/', $path ), '/' );
		$home = rtrim( strtolower( (string) ( $cfg['home'] ?? '' ) ), '/' );
		$rel  = ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) ? substr( $path, strlen( $home ) ) : $path;

		foreach ( (array) ( $cfg['allow'] ?? [] ) as $re ) {
			if ( self::pattern_matches( $re, $rel ) ) {
				return '';
			}
		}
		if ( 0 === strpos( $rel, '/.well-known/' ) ) {
			return '';
		}
		// Sitemap plugins serve compressed sitemaps through PHP.
		if ( preg_match( '#sitemap[^/]*\.xml\.gz$#', $rel ) ) {
			return '';
		}
		if ( preg_match( '#/\.[^/.]#', $rel ) ) {
			return 'dotfile';
		}
		if ( preg_match( '#\.php([.~_\-][a-z0-9]*|~)$#', $rel ) ) {
			return 'php-copy';
		}
		if ( preg_match( '#\.(' . self::CONFIG_EXTENSIONS . ')$#', $rel ) || in_array( basename( $rel ), self::CONFIG_NAMES, true ) ) {
			return 'config';
		}
		if ( preg_match( '#\.(' . self::ARCHIVE_EXTENSIONS . ')$#', $rel ) ) {
			$uploads = strtolower( (string) ( $cfg['uploads'] ?? '' ) );
			if ( '' === $uploads || 0 !== strpos( $path, rtrim( $uploads, '/' ) . '/' ) ) {
				return 'archive';
			}
		}
		foreach ( (array) ( $cfg['scanner'] ?? [] ) as $prefix ) {
			$prefix = strtolower( (string) $prefix );
			if ( '' !== $prefix && 0 === strpos( $rel, $prefix ) ) {
				return 'scanner';
			}
		}
		foreach ( (array) ( $cfg['extra'] ?? [] ) as $re ) {
			if ( self::pattern_matches( $re, $rel ) ) {
				return 'custom';
			}
		}
		if ( '.php' === substr( $rel, -4 ) && ! empty( $cfg['root'] ) ) {
			$file = rtrim( (string) $cfg['root'], '/' ) . $rel;
			// The script PHP is running is never missing, even when the stored
			// root does not match the paths PHP sees (symlinks, chroot).
			$running = '' !== $script && strtolower( substr( str_replace( '\\', '/', (string) $script ), -strlen( $rel ) ) ) === $rel;
			if ( ! $running && ! is_file( $file ) ) {
				return 'missing-php';
			}
		}
		return '';
	}

	/**
	 * Owner patterns (one per line, `*` wildcard) as anchored regular
	 * expressions for the state file.
	 *
	 * @param string|array $lines
	 * @return string[]
	 */
	public static function compile( $lines ) {
		$out = [];
		foreach ( is_array( $lines ) ? $lines : preg_split( '/\r\n|\r|\n/', (string) $lines ) as $line ) {
			$line = strtolower( trim( (string) $line ) );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			if ( '/' !== $line[0] ) {
				$line = '/' . $line;
			}
			$out[] = '#^' . str_replace( '\*', '.*', preg_quote( $line, '#' ) ) . '$#';
		}
		return $out;
	}

	/**
	 * @param string $re
	 * @param string $rel
	 * @return bool
	 */
	private static function pattern_matches( $re, $rel ) {
		return is_string( $re ) && '' !== $re && 1 === @preg_match( $re, $rel ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a bad stored pattern must not break a request.
	}
}
