<?php
/**
 * One web-server log line: parsing the nginx/Apache "combined" format and
 * classifying the request for a log source profile.
 *
 * combined = $remote_addr - $remote_user [$time_local] "$request" $status
 *            $body_bytes_sent "$http_referer" "$http_user_agent"
 *
 * nginx escapes '"', '\' and bytes below 32 or above 126 as \xXX in the
 * variables it logs (default escaping), so a quoted field never holds a
 * bare quote. The combined format carries no request time, so a log-fed
 * minute has no slow requests and error pressure counts 5xx only.
 *
 * Profiles:
 *  - mediawiki: /wiki/<Title> pages, index.php with a title, special pages,
 *    old revisions and diffs, search, login, api.php, and load.php as the
 *    real-browser signal (the role the beacon plays on a WordPress page:
 *    a browser rendering the page fetches it, a scraper does not).
 *  - wordpress: the same classes the in-plugin classifier gives.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Log_Line {

	const PROFILES = [ 'mediawiki', 'wordpress' ];

	/**
	 * Special namespace names, canonical first, then localized ones.
	 */
	const MW_SPECIAL = [ 'special', 'especial' ];

	/**
	 * Special pages that are searches or logins, in lowercase, without the
	 * namespace. Spanish aliases from MediaWiki's MessagesEs.php.
	 */
	const MW_SEARCH = [ 'search', 'buscar' ];
	const MW_LOGIN  = [ 'userlogin', 'entrar', 'entrada_del_usuario', 'createaccount', 'crear_una_cuenta', 'crearcuenta', 'userlogout', 'salida_del_usuario', 'salir' ];

	/**
	 * Query keys that make an index.php request something other than a page
	 * view: an old revision, a diff, an edit form, a history.
	 */
	const MW_REVISION_KEYS = [ 'oldid', 'diff', 'curid', 'undo', 'undoafter', 'direction', 'veaction', 'section', 'preload', 'rcid', 'printable' ];

	/**
	 * @var array Last parsed $time_local string => timestamp (lines come in
	 *            runs of the same second).
	 */
	private static $time_cache = [ '', 0 ];

	/**
	 * Parse one combined-format line.
	 *
	 * @param string $line
	 * @return array|null ip, ts, minute, method, target, status, ua; null when
	 *                    the line is not a combined line with a valid address.
	 */
	public static function parse( $line ) {
		$line = rtrim( (string) $line, "\r\n" );
		if ( '' === $line ) {
			return null;
		}
		if ( ! preg_match( '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d{3}) \S+ "[^"]*" "([^"]*)"/', $line, $m ) ) {
			return null;
		}
		$ip = $m[1];
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return null;
		}
		$ts = self::timestamp( $m[2] );
		if ( null === $ts ) {
			return null;
		}
		$method = '';
		$target = '';
		if ( preg_match( '/^([A-Z]{3,10}) (\S+)(?: HTTP\/[0-9.]+)?$/', $m[3], $r ) ) {
			$method = $r[1];
			$target = $r[2];
		}
		return [
			'ip'     => $ip,
			'ts'     => $ts,
			'minute' => intdiv( $ts, 60 ),
			'method' => $method,
			'target' => $target,
			'status' => (int) $m[4],
			'ua'     => '-' === $m[5] ? '' : $m[5],
		];
	}

	/**
	 * @param string $time_local e.g. "15/Sep/2026:18:08:57 +0000".
	 * @return int|null
	 */
	public static function timestamp( $time_local ) {
		if ( self::$time_cache[0] === $time_local ) {
			return self::$time_cache[1];
		}
		$dt = DateTime::createFromFormat( 'd/M/Y:H:i:s O', $time_local );
		if ( false === $dt ) {
			return null;
		}
		self::$time_cache = [ $time_local, $dt->getTimestamp() ];
		return self::$time_cache[1];
	}

	/**
	 * Request class for a parsed line under a profile.
	 *
	 * @param array  $p       parse() result.
	 * @param string $profile
	 * @return string
	 */
	public static function classify( array $p, $profile ) {
		if ( '' === $p['target'] ) {
			return 'other';
		}
		$qpos  = strpos( $p['target'], '?' );
		$path  = false === $qpos ? $p['target'] : substr( $p['target'], 0, $qpos );
		$query = [];
		if ( false !== $qpos ) {
			parse_str( substr( $p['target'], $qpos + 1 ), $query );
		}
		if ( 'wordpress' === $profile ) {
			$c = BSR_Classifier::classify( rawurldecode( $path ), $query, [ 'REQUEST_METHOD' => $p['method'] ] )['class'];
			if ( 'html' === $c && 404 === $p['status'] ) {
				$c = '404';
			}
			if ( false !== strpos( $p['target'], 'bsr-beacon=' ) ) {
				$c = 'beacon';
			}
			return $c;
		}
		return self::classify_mediawiki( $path, $query, $p['status'] );
	}

	/**
	 * @param string $path  Raw path (still percent-encoded).
	 * @param array  $query Decoded query variables.
	 * @param int    $status
	 * @return string
	 */
	public static function classify_mediawiki( $path, array $query, $status ) {
		$lower = strtolower( rawurldecode( $path ) );

		if ( '/load.php' === substr( $lower, -9 ) ) {
			return 'beacon';
		}
		if ( '/api.php' === substr( $lower, -8 ) || '/rest.php' === substr( $lower, -9 ) || false !== strpos( $lower, '/rest.php/' ) ) {
			return 'rest';
		}
		$ext = strtolower( (string) pathinfo( $lower, PATHINFO_EXTENSION ) );
		if ( '' !== $ext && in_array( $ext, BSR_Classifier::ASSET_EXTENSIONS, true ) && 0 !== strpos( $lower, '/wiki/' ) ) {
			return 'asset';
		}

		$title = null;
		if ( 0 === strpos( $lower, '/wiki/' ) ) {
			$title = substr( $lower, 6 );
		} elseif ( '/index.php' === substr( $lower, -10 ) || '/' === $lower ) {
			$title = isset( $query['title'] ) && is_string( $query['title'] ) ? strtolower( $query['title'] ) : '';
		}
		if ( null === $title ) {
			return 404 === (int) $status ? '404' : 'other';
		}

		if ( isset( $query['search'] ) ) {
			return 'search';
		}
		$action = isset( $query['action'] ) && is_string( $query['action'] ) ? strtolower( $query['action'] ) : 'view';
		if ( 'raw' === $action ) {
			return 'asset'; // Gadget scripts every reader loads (MediaWiki:*.js).
		}
		if ( 'view' !== $action ) {
			return 'revision';
		}
		foreach ( self::MW_REVISION_KEYS as $k ) {
			if ( isset( $query[ $k ] ) ) {
				return 'revision';
			}
		}

		$title = str_replace( ' ', '_', $title );
		$colon = strpos( $title, ':' );
		if ( false !== $colon && in_array( substr( $title, 0, $colon ), self::MW_SPECIAL, true ) ) {
			$page = substr( $title, $colon + 1 );
			$slash = strpos( $page, '/' );
			$page  = false === $slash ? $page : substr( $page, 0, $slash );
			if ( in_array( $page, self::MW_SEARCH, true ) ) {
				return 'search';
			}
			if ( in_array( $page, self::MW_LOGIN, true ) ) {
				return 'login';
			}
			return 'special';
		}
		return 404 === (int) $status ? '404' : 'html';
	}
}
