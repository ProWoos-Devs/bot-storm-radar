<?php
/**
 * Absolute 5xx rule for log sources. The error-pressure rule in the storm
 * machine is a ratio (5xx plus slow over all requests, at least half by
 * default), so a short burst of server errors inside a busy minute never
 * fires it: 108 HTTP 500 in fourteen seconds of a 700-request minute is a
 * pressure of 0.15. This rule counts the 5xx responses themselves. A minute
 * with at least `error_burst_5xx` of them opens an episode, mails once, and
 * the episode ends after `error_burst_clear_minutes` minutes below the
 * threshold. The storm state is never touched; every burst is written to the
 * source's transition log with the state unchanged.
 *
 * Applies to log sources only. The site's requests are recorded from inside
 * PHP, where a 5xx is the request that just failed, and the ratio rule with
 * its address floor stays the site's rule for now.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Error_Burst {

	const OPTION = 'bsr_errors';

	/**
	 * @param string $source
	 * @return array active, started_minute, peak, below, bursts, last_minute
	 */
	public static function get_state( $source ) {
		$s = get_option( BSR_Sources::option( self::OPTION, $source ), [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [
			'active'         => false,
			'started_minute' => 0,
			'peak'           => 0,
			'below'          => 0,
			'bursts'         => 0,
			'last_minute'    => 0,
		] );
	}

	/**
	 * @param string $source
	 */
	public static function reset( $source ) {
		delete_option( BSR_Sources::option( self::OPTION, $source ) );
	}

	/**
	 * Feed one finished minute of a log source. Returns the context of a
	 * burst that just opened, null otherwise.
	 *
	 * @param array      $row    Scored minute row.
	 * @param array|null $opts   Options (defaults to stored).
	 * @param string     $source
	 * @return array|null
	 */
	public static function step( array $row, $opts, $source ) {
		$opts      = null === $opts ? BSR_Helpers::get_options() : $opts;
		$threshold = (int) ( $opts['error_burst_5xx'] ?? 20 );
		if ( $threshold <= 0 ) {
			return null;
		}
		$clear  = max( 1, (int) ( $opts['error_burst_clear_minutes'] ?? 15 ) );
		$err5   = (int) ( $row['err5'] ?? 0 );
		$minute = (int) ( $row['m'] ?? BSR_Helpers::minute() - 1 );
		$state  = self::get_state( $source );
		$opened = null;

		if ( $err5 >= $threshold ) {
			if ( ! $state['active'] ) {
				$state['active']         = true;
				$state['started_minute'] = $minute;
				$state['peak']           = $err5;
				$state['bursts']         = (int) $state['bursts'] + 1;
				$opened                  = self::context( $row, $minute, $err5, $threshold, $source );
			}
			$state['peak']  = max( (int) $state['peak'], $err5 );
			$state['below'] = 0;
		} elseif ( $state['active'] ) {
			$state['below'] = (int) $state['below'] + 1;
			if ( $state['below'] >= $clear ) {
				$state['active'] = false;
				$state['below']  = 0;
			}
		}
		$state['last_minute'] = $minute;
		update_option( BSR_Sources::option( self::OPTION, $source ), $state, false );
		return $opened;
	}

	/**
	 * step(), then log and mail a burst that opened. This is what the log
	 * reader calls once per finished minute.
	 *
	 * @param array      $row
	 * @param array|null $opts
	 * @param string     $source
	 * @return array|null The burst context, null when nothing opened.
	 */
	public static function observe( array $row, $opts, $source ) {
		$ctx = self::step( $row, $opts, $source );
		if ( null === $ctx ) {
			return null;
		}
		BSR_Storage::add_transition( $ctx, $source );
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Bot Storm Radar] ' . $source . ': ' . $ctx['explanation'] ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		// Same gate as the state transitions: a log source mails once its
		// baseline holds a day, the replay scratch source never.
		if ( BSR_Sources::alerts_ready( $source ) && BSR_Helpers::opt( 'alert_on_storm', 1 ) ) {
			BSR_Email_Alerts::send_error_burst( $ctx );
		}
		return $ctx;
	}

	/**
	 * A transition-log entry whose state does not change.
	 *
	 * @param array  $row
	 * @param int    $minute
	 * @param int    $err5
	 * @param int    $threshold
	 * @param string $source
	 * @return array
	 */
	private static function context( array $row, $minute, $err5, $threshold, $source ) {
		$state = BSR_Storm::get_state( $source )['state'];
		$light = $row;
		unset( $light['hot'], $light['ua_top'] );
		return [
			'source'      => $source,
			'from'        => $state,
			'to'          => $state,
			'burst'       => true,
			'at'          => time(),
			'minute'      => (int) $minute,
			'score'       => (int) ( $row['score'] ?? 0 ),
			'threshold'   => (int) $threshold,
			'err5'        => (int) $err5,
			'explanation' => sprintf(
				/* translators: 1: 5xx count, 2: request count, 3: threshold, 4: storm state */
				__( 'Error burst: %1$d responses with a 5xx status among %2$d requests in the minute (threshold %3$d). The storm state stays %4$s; this rule only alerts.', 'bot-storm-radar' ),
				(int) $err5,
				(int) ( $row['total'] ?? 0 ),
				(int) $threshold,
				$state
			),
			'note'        => 'error burst',
			'metrics'     => $light,
		];
	}
}
