<?php
/**
 * Reads the gate's state file without WordPress.
 *
 * The file is `<?php exit; ?>` on its first line followed by JSON. Read as
 * data with file_get_contents(), it never passes through opcache, so a
 * rewrite is seen on the next request even with
 * opcache.validate_timestamps=0; requested over the web it runs as PHP and
 * returns an empty body. BSR_State writes it; the gate reads it here.
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress or by the gate, which defines BSR_GATE first.
defined( 'BSR_GATE' ) || defined( 'ABSPATH' ) || exit;

class BSR_State_Reader {

	/**
	 * First line of every file the plugin writes in its data directory.
	 */
	const HEADER = "<?php exit; ?>\n";

	/**
	 * Format version; readers refuse a file with a different one.
	 */
	const FORMAT = 1;

	/**
	 * Decoded state, or null when the file is missing, unreadable, not ours
	 * or of another format.
	 *
	 * @param string $path
	 * @return array|null
	 */
	public static function read( $path ) {
		$raw = @file_get_contents( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- a missing file is a normal answer here.
		if ( ! is_string( $raw ) || 0 !== strpos( $raw, self::HEADER ) ) {
			return null;
		}
		$data = json_decode( substr( $raw, strlen( self::HEADER ) ), true );
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? null ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Wrap a JSON-encodable array as file contents.
	 *
	 * @param array $data
	 * @return string|false
	 */
	public static function encode( array $data ) {
		$json = json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- the gate side has no wp_json_encode.
		return false === $json ? false : self::HEADER . $json;
	}
}
