<?php
/**
 * The minute tick: computes the finished minute's metrics, stores the row,
 * feeds the state machine, rolls the baseline over at midnight UTC, and
 * verifies queued good-bot claims. Scheduled through WP cron every minute,
 * with a self-healing guard: when the tick is late, the next front-end
 * request runs it after its response has been flushed.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Tick {

	const HOOK       = 'bsr_tick';
	const OPTION     = 'bsr_tick';
	const LOCK       = 'g:tick_lock';
	const MAX_BACK   = 15;
	const LATE_AFTER = 90;

	/**
	 * @var bool Whether this request ran the tick inline.
	 */
	private static $ran_inline = false;

	public static function init() {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
	}

	public static function schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'bsr_minute', self::HOOK );
		}
	}

	public static function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * @return array last_minute, last_run, source
	 */
	public static function status() {
		$s = get_option( self::OPTION, [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [ 'last_minute' => 0, 'last_run' => 0, 'source' => '' ] );
	}

	/**
	 * Process every finished minute since the last run (bounded). Returns
	 * the rows computed, or false when another tick holds the lock.
	 *
	 * @param int|null $now
	 * @return array|false
	 */
	public static function run( $now = null ) {
		if ( ! BSR_Counters::add_value( self::LOCK, 1, 55 ) ) {
			return false;
		}
		$now     = null === $now ? time() : (int) $now;
		$status  = self::status();
		$current = BSR_Helpers::minute( $now );
		$target  = $current - 1;
		$last    = (int) $status['last_minute'];
		$from    = $last > 0 ? max( $last + 1, $target - self::MAX_BACK + 1 ) : $target;
		$rows    = [];

		for ( $m = $from; $m <= $target; $m++ ) {
			$row = BSR_Metrics::compute_minute( $m );
			BSR_Storage::add_minute( $row );
			BSR_Storm::step( $row );
			$rows[ $m ] = $row;
		}

		BSR_Baseline::maybe_rollover( $now );
		BSR_Good_Bots::verify_pending();

		update_option( self::OPTION, [
			'last_minute' => $target,
			'last_run'    => $now,
			'source'      => self::$ran_inline ? 'inline' : ( wp_doing_cron() ? 'cron' : 'direct' ),
		], true );

		BSR_Counters::delete( self::LOCK );
		return $rows;
	}

	/**
	 * Self-healing guard, called from the recorder at shutdown on front-end
	 * requests. Runs the tick after the response is handed to the client.
	 */
	public static function maybe_run_inline() {
		if ( self::$ran_inline || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$status = self::status();
		$now    = time();
		if ( ( $now - (int) $status['last_run'] ) < self::LATE_AFTER ) {
			return;
		}
		if ( (int) $status['last_minute'] >= BSR_Helpers::minute( $now ) - 1 ) {
			return;
		}
		self::$ran_inline = true;
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::run( $now );
	}

	/**
	 * Whether the scheduled tick is overdue (dashboard notice).
	 *
	 * @return bool
	 */
	public static function is_late() {
		$status = self::status();
		return (int) $status['last_run'] > 0 && ( time() - (int) $status['last_run'] ) > 3 * self::LATE_AFTER;
	}
}
