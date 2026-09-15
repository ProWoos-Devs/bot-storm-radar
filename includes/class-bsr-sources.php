<?php
/**
 * Traffic sources. The WordPress site itself is the `site` source, fed by
 * the recorder and the minute tick. Other sources (a MediaWiki beside the
 * site, read from its web-server log) keep their own minute rows, baseline,
 * state and transition log, so their traffic never mixes into the site's.
 *
 * The site keeps the option names 0.1.x used; another source inserts its id
 * after the prefix: `bsr_state` becomes `bsr_wiki_state`, `bsr_min_` chunks
 * become `bsr_wiki_min_`. Ids are validated where a source is defined, not
 * on every option lookup.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Sources {

	const SITE = 'site';

	/**
	 * Ids that would produce option names 0.1.x already uses
	 * (`bsr_min_chunks`, `bsr_tick`, `bsr_version`, ...), `log` because the
	 * source definitions live in `bsr_log_sources`, and `replay`, the scratch
	 * source `wp bot-storm-radar replay` purges.
	 */
	const RESERVED = [ 'site', 'min', 'tick', 'state', 'baseline', 'transitions', 'version', 'options', 'update', 'log', 'replay' ];

	/**
	 * The option name a per-source option has for this source.
	 *
	 * @param string $name   Site option name, starting with `bsr_`.
	 * @param string $source
	 * @return string
	 */
	public static function option( $name, $source = self::SITE ) {
		if ( self::SITE === $source ) {
			return $name;
		}
		return 'bsr_' . $source . '_' . substr( $name, 4 );
	}

	/**
	 * A source id is 1 to 20 lowercase letters and digits, starting with a
	 * letter, and not reserved.
	 *
	 * @param mixed $source
	 * @return bool
	 */
	public static function is_valid( $source ) {
		return is_string( $source )
			&& 1 === preg_match( '/^[a-z][a-z0-9]{0,19}$/', $source )
			&& ! in_array( $source, self::RESERVED, true );
	}
}
