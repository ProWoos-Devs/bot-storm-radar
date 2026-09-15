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
	 * Log source definitions (id => label, profile, logs, alert_to, created),
	 * written only by `wp bot-storm-radar source add|remove`.
	 */
	const LOG_OPTION = 'bsr_log_sources';

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

	/**
	 * @return array id => definition
	 */
	public static function log_sources() {
		$all = get_option( self::LOG_OPTION, [] );
		return is_array( $all ) ? $all : [];
	}

	/**
	 * The site, or a defined log source.
	 *
	 * @param mixed $source
	 * @return bool
	 */
	public static function exists( $source ) {
		return self::SITE === $source || ( is_string( $source ) && isset( self::log_sources()[ $source ] ) );
	}

	/**
	 * @param string $source
	 * @return string
	 */
	public static function label( $source ) {
		if ( self::SITE === $source ) {
			return __( 'Site', 'bot-storm-radar' );
		}
		$def = self::log_sources()[ $source ] ?? null;
		return is_array( $def ) && '' !== (string) ( $def['label'] ?? '' ) ? (string) $def['label'] : ucfirst( (string) $source );
	}

	/**
	 * A log source's reader state: last_minute, last_run, files, stats.
	 *
	 * @param string $source
	 * @return array
	 */
	public static function log_state( $source ) {
		$s = get_option( self::option( 'bsr_log_state', $source ), [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [ 'last_minute' => 0, 'last_run' => 0, 'files' => [], 'stats' => [] ] );
	}

	/**
	 * Who gets a source's alerts: its own recipients when it has them,
	 * otherwise the site's.
	 *
	 * @param string $source
	 * @return array
	 */
	public static function alert_recipients( $source ) {
		$def = self::SITE === $source ? null : ( self::log_sources()[ $source ] ?? null );
		if ( is_array( $def ) && '' !== trim( (string) ( $def['alert_to'] ?? '' ) ) ) {
			return BSR_Helpers::sanitize_email_list( (string) $def['alert_to'] );
		}
		return BSR_Helpers::sanitize_email_list( (string) BSR_Helpers::opt( 'email_recipients', '' ) );
	}

	/**
	 * Whether a source's transitions are mailed yet. A log source starts
	 * reading a busy log with no baseline, scored against the minimum-addresses
	 * floor, so its ordinary traffic can look like a storm (a wiki with 40 to
	 * 70 distinct addresses a minute does). It mails once its baseline holds
	 * at least one full day. The site mails from the start, as in 0.1.
	 *
	 * @param string $source
	 * @return bool
	 */
	public static function alerts_ready( $source ) {
		if ( self::SITE === $source ) {
			return true;
		}
		return (int) ( BSR_Baseline::effective( $source )['days'] ?? 0 ) >= 1;
	}
}
