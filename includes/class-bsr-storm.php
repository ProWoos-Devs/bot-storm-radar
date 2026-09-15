<?php
/**
 * Storm state machine (design v1, 6): calm, warning, storm, cooling.
 *
 *  - calm to warning: score at or above the warning threshold for
 *    `warning_minutes` consecutive finished minutes (default 1, i.e. 60 s).
 *  - warning to storm: score at or above the storm threshold for
 *    `storm_minutes`, or error pressure alone above its threshold. The
 *    error-pressure rule needs the same minimum of distinct addresses as
 *    the score: with a page cache in front, PHP mostly sees cache misses,
 *    and three slow requests in a quiet minute are not a storm.
 *  - warning to calm: score below the warning threshold for
 *    `warning_clear_minutes`.
 *  - storm to cooling: score below the warning threshold for
 *    `storm_hold_minutes`.
 *  - cooling to calm: another `cooling_hold_minutes` without a re-trigger;
 *    a score at or above the storm threshold during cooling goes straight
 *    back to storm.
 *
 * Rungs escalate while the storm persists (score at or above the storm
 * threshold for RUNG_PERSIST_MINUTES after the current rung engaged), and
 * all stand down together when cooling ends. Every action goes through the
 * BSR_Actions implementations, which in v0.1 only log and alert.
 *
 * Every transition is logged with the metrics that caused it.
 *
 * One state per source (BSR_Sources); the site keeps the 0.1.x option. The
 * source travels in every transition context as `source`.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Storm {

	const STATE_OPTION         = 'bsr_state';
	const RUNG_PERSIST_MINUTES = 10;
	const MAX_RUNG             = 3;

	/**
	 * @var BSR_Actions[]|null
	 */
	private static $actions = null;

	/**
	 * @return array
	 */
	public static function initial_state() {
		return [
			'state'        => 'calm',
			'since'        => time(),
			'since_minute' => BSR_Helpers::minute(),
			's_warn'       => 0,
			's_storm'      => 0,
			's_below'      => 0,
			'rung'         => 0,
			'rung_minute'  => 0,
			'last_score'   => 0,
			'last_minute'  => 0,
			'storms'       => 0,
			'last_storm'   => null,
			'current'      => null,
		];
	}

	/**
	 * @param string $source
	 * @return array
	 */
	public static function get_state( $source = BSR_Sources::SITE ) {
		$s = get_option( BSR_Sources::option( self::STATE_OPTION, $source ), [] );
		return wp_parse_args( is_array( $s ) ? $s : [], self::initial_state() );
	}

	/**
	 * @param array  $state
	 * @param string $source
	 */
	public static function save_state( array $state, $source = BSR_Sources::SITE ) {
		// Only the site's state is read on every request (dashboard widget,
		// inline guard); other sources are read by the CLI and the Radar screen.
		update_option( BSR_Sources::option( self::STATE_OPTION, $source ), $state, BSR_Sources::SITE === $source );
	}

	/**
	 * Reset to calm (admin action, tests).
	 *
	 * @param string $source
	 */
	public static function reset( $source = BSR_Sources::SITE ) {
		self::save_state( self::initial_state(), $source );
	}

	/**
	 * @return BSR_Actions[]
	 */
	public static function actions() {
		if ( null === self::$actions ) {
			$list = apply_filters( 'bsr_actions', [ new BSR_Actions_Log() ] );
			self::$actions = array_values( array_filter( is_array( $list ) ? $list : [], function ( $a ) {
				return $a instanceof BSR_Actions;
			} ) );
		}
		return self::$actions;
	}

	/**
	 * Feed one finished minute. Returns the transitions made (0, 1 or 2).
	 *
	 * @param array      $row    Scored minute row.
	 * @param array|null $opts   Options (defaults to stored).
	 * @param string     $source
	 * @return array
	 */
	public static function step( array $row, $opts = null, $source = BSR_Sources::SITE ) {
		$opts  = null === $opts ? BSR_Helpers::get_options() : $opts;
		$state = self::get_state( $source );
		$made  = [];

		$score  = (int) ( $row['score'] ?? 0 );
		$ep     = (float) ( $row['ep'] ?? 0 );
		$minute = (int) ( $row['m'] ?? BSR_Helpers::minute() - 1 );

		$warn_t   = (int) ( $opts['warning_threshold'] ?? 40 );
		$storm_t  = (int) ( $opts['storm_threshold'] ?? 60 );
		$ep_t     = (float) ( $opts['error_pressure_storm'] ?? 0.5 );
		$warn_min = max( 1, (int) ( $opts['warning_minutes'] ?? 1 ) );
		$storm_min = max( 1, (int) ( $opts['storm_minutes'] ?? 1 ) );
		$clear_min = max( 1, (int) ( $opts['warning_clear_minutes'] ?? 5 ) );
		$hold_min  = max( 1, (int) ( $opts['storm_hold_minutes'] ?? 15 ) );
		$cool_min  = max( 1, (int) ( $opts['cooling_hold_minutes'] ?? 15 ) );
		$min_ips   = max( 1, (int) ( $opts['min_distinct_ips'] ?? 30 ) );
		$ips       = (int) ( $row['ips'] ?? 0 );

		$above_warn  = $score >= $warn_t;
		$above_storm = $score >= $storm_t || ( $ep_t > 0 && $ep >= $ep_t && $ips >= $min_ips );

		$state['s_storm'] = $above_storm ? $state['s_storm'] + 1 : 0;
		$state['s_warn']  = $above_warn ? $state['s_warn'] + 1 : 0;
		$state['s_below'] = $above_warn ? 0 : $state['s_below'] + 1;
		$state['last_score']  = $score;
		$state['last_minute'] = $minute;

		if ( is_array( $state['current'] ) && $score > (int) $state['current']['peak_score'] ) {
			$state['current']['peak_score']  = $score;
			$state['current']['peak_minute'] = $minute;
			$state['current']['peak_ips']    = (int) ( $row['ips'] ?? 0 );
			$state['current']['peak_total']  = (int) ( $row['total'] ?? 0 );
		}

		// Up to two hops per minute so a flash storm passes through warning
		// on the way to storm within the same tick.
		$through = false;
		for ( $hop = 0; $hop < 2; $hop++ ) {
			$next = null;
			$note = '';
			switch ( $state['state'] ) {
				case 'calm':
					if ( $state['s_warn'] >= $warn_min || $state['s_storm'] >= $storm_min ) {
						$next = 'warning';
					}
					break;
				case 'warning':
					if ( $state['s_storm'] >= $storm_min ) {
						$next = 'storm';
						if ( $score < $storm_t && $ep >= $ep_t ) {
							$note = __( 'error pressure alone crossed its threshold', 'bot-storm-radar' );
						}
					} elseif ( $state['s_below'] >= $clear_min ) {
						$next = 'calm';
					}
					break;
				case 'storm':
					if ( $state['s_below'] >= $hold_min ) {
						$next = 'cooling';
					} elseif ( $state['rung'] > 0 && $state['rung'] < self::MAX_RUNG && $state['s_storm'] >= 1 && ( $minute - (int) $state['rung_minute'] ) >= self::RUNG_PERSIST_MINUTES ) {
						$state['rung']++;
						$state['rung_minute'] = $minute;
						$ctx = self::context( 'storm', 'storm', $row, $minute, sprintf( __( 'storm persisted with rung %1$d engaged, rung %2$d requested', 'bot-storm-radar' ), $state['rung'] - 1, $state['rung'] ), $source );
						foreach ( self::actions() as $a ) {
							$a->escalate( $state['rung'], $ctx );
						}
						BSR_Storage::add_transition( $ctx, $source );
						$made[] = $ctx;
					}
					break;
				case 'cooling':
					if ( $above_storm ) {
						$next = 'storm';
						$note = __( 're-triggered during cooling', 'bot-storm-radar' );
					} elseif ( $state['s_below'] >= $cool_min ) {
						$next = 'calm';
					}
					break;
			}
			if ( null === $next ) {
				break;
			}
			$ctx = self::context( $state['state'], $next, $row, $minute, $note, $source );
			// Calm to warning with the storm streak already met goes on to
			// storm in the next hop: the warning case checks that streak
			// first and apply() does not touch it. Both contexts say so, and
			// the alert for the pair is sent once, on storm.
			if ( 'calm' === $state['state'] && 'warning' === $next && $state['s_storm'] >= $storm_min ) {
				$ctx['continues_to'] = 'storm';
				$through             = true;
			} elseif ( $through && 'storm' === $next ) {
				$ctx['started_from'] = 'calm';
			}
			$state = self::apply( $state, $next, $minute, $row, $ctx );
			$made[] = $ctx;
		}

		self::save_state( $state, $source );
		return $made;
	}

	/**
	 * @param string $from
	 * @param string $to
	 * @param array  $row
	 * @param int    $minute
	 * @param string $note
	 * @param string $source
	 * @return array
	 */
	private static function context( $from, $to, array $row, $minute, $note = '', $source = BSR_Sources::SITE ) {
		$light = $row;
		unset( $light['hot'], $light['ua_top'] );
		return [
			'source'      => $source,
			'from'        => $from,
			'to'          => $to,
			'at'          => time(),
			'minute'      => (int) $minute,
			'score'       => (int) ( $row['score'] ?? 0 ),
			'explanation' => BSR_Metrics::explain( $row, BSR_Baseline::effective( $source ) ) . ( '' !== $note ? ' (' . $note . ')' : '' ),
			'note'        => $note,
			'metrics'     => $light,
		];
	}

	/**
	 * Move to a new state, notify the actions, log.
	 *
	 * @param array  $state
	 * @param string $next
	 * @param int    $minute
	 * @param array  $row
	 * @param array  $ctx
	 * @return array
	 */
	private static function apply( array $state, $next, $minute, array $row, array $ctx ) {
		$prev = $state['state'];
		$state['state']        = $next;
		$state['since']        = time();
		$state['since_minute'] = $minute;
		// Streaks are properties of the score series, not of the state, so
		// they survive a transition: that is what lets a flash storm pass
		// through warning into storm within one tick. The one exception is
		// the below-warning streak that took storm into cooling, which must
		// start over so cooling holds for its own full period.
		if ( 'cooling' === $next ) {
			$state['s_below'] = 0;
		}

		if ( 'storm' === $next ) {
			if ( ! is_array( $state['current'] ) ) {
				$state['storms']++;
				$state['current'] = [
					'started'        => time(),
					'started_minute' => $minute,
					'peak_score'     => (int) ( $row['score'] ?? 0 ),
					'peak_minute'    => $minute,
					'peak_ips'       => (int) ( $row['ips'] ?? 0 ),
					'peak_total'     => (int) ( $row['total'] ?? 0 ),
					'explanation'    => $ctx['explanation'],
					'max_rung'       => 1,
				];
			}
			if ( $state['rung'] < 1 ) {
				$state['rung']        = 1;
				$state['rung_minute'] = $minute;
			}
		}
		if ( is_array( $state['current'] ) ) {
			$state['current']['max_rung'] = max( (int) $state['current']['max_rung'], (int) $state['rung'] );
		}
		if ( 'calm' === $next && is_array( $state['current'] ) ) {
			$state['current']['ended']        = time();
			$state['current']['ended_minute'] = $minute;
			$state['last_storm']              = $state['current'];
			$state['current']                 = null;
		}

		foreach ( self::actions() as $a ) {
			switch ( $next ) {
				case 'warning':
					$a->on_warning( $ctx );
					break;
				case 'storm':
					$a->on_storm( $ctx );
					if ( 'cooling' === $prev || 'warning' === $prev ) {
						$a->escalate( (int) $state['rung'], $ctx );
					}
					break;
				case 'cooling':
					$a->on_cooling( $ctx );
					break;
				case 'calm':
					if ( 'cooling' === $prev ) {
						$a->deescalate( $ctx );
						$state['rung'] = 0;
					}
					$a->on_calm( $ctx );
					break;
			}
		}
		if ( 'calm' === $next ) {
			$state['rung'] = 0;
		}
		BSR_Storage::add_transition( $ctx, (string) $ctx['source'] );
		return $state;
	}
}
