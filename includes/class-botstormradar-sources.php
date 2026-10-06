<?php
/**
 * Traffic sources. The WordPress site itself is the `site` source, fed by
 * the recorder and the minute tick. Other sources (a MediaWiki beside the
 * site, read from its web-server log) keep their own minute rows, baseline,
 * state and transition log, so their traffic never mixes into the site's.
 *
 * The site keeps the option names 0.1.x used; another source inserts its id
 * after the prefix: `botstormradar_state` becomes `botstormradar_wiki_state`, `botstormradar_min_` chunks
 * become `botstormradar_wiki_min_`. Ids are validated where a source is defined, not
 * on every option lookup.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Sources {

	const SITE = 'site';

	/**
	 * Log source definitions (id => label, profile, logs, alert_to, created),
	 * written only by `wp bot-storm-radar source add|remove`.
	 */
	const LOG_OPTION = 'botstormradar_log_sources';

	/**
	 * A baseline day counts as full for alerts with this many minute rows
	 * (23 hours, so an hour of downtime does not delay alerts by a day).
	 */
	const FULL_DAY_MINUTES = 1380;

	/**
	 * Ids that would produce option names 0.1.x already uses
	 * (`botstormradar_min_chunks`, `botstormradar_tick`, `botstormradar_version`, ...), `log` because the
	 * source definitions live in `botstormradar_log_sources`, and `replay`, the scratch
	 * source `wp bot-storm-radar replay` purges.
	 */
	const RESERVED = [ 'site', 'min', 'tick', 'state', 'baseline', 'transitions', 'version', 'options', 'update', 'log', 'replay' ];

	/**
	 * The scratch source `wp bot-storm-radar replay` writes and purges. It
	 * never mails, whatever its baseline says.
	 */
	const REPLAY = 'replay';

	/**
	 * The option name a per-source option has for this source.
	 *
	 * @param string $name   Site option name, starting with `botstormradar_`.
	 * @param string $source
	 * @return string
	 */
	public static function option( $name, $source = self::SITE ) {
		if ( self::SITE === $source ) {
			return $name;
		}
		return 'botstormradar_' . $source . '_' . substr( $name, strlen( 'botstormradar_' ) );
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
		$s = get_option( self::option( 'botstormradar_log_state', $source ), [] );
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
			return BotStormRadar_Helpers::sanitize_email_list( (string) $def['alert_to'] );
		}
		return BotStormRadar_Helpers::sanitize_email_list( (string) BotStormRadar_Helpers::opt( 'email_recipients', '' ) );
	}

	/**
	 * Whether a source's transitions are mailed yet. A log source starts
	 * reading a busy log with no baseline, scored against the minimum-addresses
	 * floor, so its ordinary traffic can look like a storm (a wiki with 40 to
	 * 70 distinct addresses a minute does). It mails once its baseline holds
	 * at least one full day. The baseline also summarizes the partial day the
	 * source started on (60 minutes are enough for that), so counting days
	 * alone would switch alerts on at the first midnight after a few hours of
	 * data; a day only counts here with FULL_DAY_MINUTES rows. The site mails
	 * from the start, as in 0.1. The replay scratch source never mails.
	 *
	 * @param string $source
	 * @return bool
	 */
	public static function alerts_ready( $source ) {
		if ( self::SITE === $source ) {
			return true;
		}
		// A replay stores the --baseline-ips figure as a learned baseline so
		// that the explanations read it; that must not switch alerts on.
		if ( self::REPLAY === $source ) {
			return false;
		}
		$b = BotStormRadar_Baseline::get( $source );
		if ( is_array( $b['learned'] ) ) {
			return true;
		}
		foreach ( (array) $b['days'] as $day ) {
			if ( (int) ( $day['minutes'] ?? 0 ) >= self::FULL_DAY_MINUTES ) {
				return true;
			}
		}
		return false;
	}
}
