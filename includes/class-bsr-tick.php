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
		if ( ! self::can_run_here() ) {
			return false;
		}
		if ( ! BSR_Counters::add_value( self::LOCK, 1, 55 ) ) {
			return false;
		}
		self::refresh_options();
		$switched = self::switch_to_site_locale();
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
		if ( $switched ) {
			restore_previous_locale();
		}
		return $rows;
	}

	/**
	 * The cursor and the state must be read as they are now, not as they
	 * were when this request started. A slow front-end request loads the
	 * options at its start; if the cron tick runs meanwhile, the inline
	 * guard at that request's shutdown would otherwise see a stale cursor
	 * and a stale state, recompute the same minutes and repeat their
	 * transitions (and their alerts). The lock cannot prevent that because
	 * the two runs are sequential. Dropping the runtime copies makes the
	 * next reads go to the database (or the persistent object cache, which
	 * update_option keeps current).
	 */
	private static function refresh_options() {
		wp_cache_delete( 'alloptions', 'options' );
		foreach ( [ self::OPTION, BSR_Storm::STATE_OPTION, BSR_Storage::TRANSITIONS, BSR_Storage::CHUNK_INDEX, Bot_Storm_Radar::OPTION_KEY ] as $name ) {
			wp_cache_delete( $name, 'options' );
		}
		BSR_Helpers::flush_options();
	}

	/**
	 * Explanations and alerts are built during the tick with wp_date() and
	 * number_format_i18n(). When the inline guard runs the tick inside a
	 * front-end request, that request's locale (a translated page, a user's
	 * profile language) would format them; the site language is the one
	 * the recipient expects.
	 *
	 * @return bool Whether a switch was made (and must be restored).
	 */
	private static function switch_to_site_locale() {
		if ( ! function_exists( 'switch_to_locale' ) ) {
			return false;
		}
		$site = (string) get_option( 'WPLANG', '' );
		$site = '' === $site ? 'en_US' : $site;
		if ( $site === determine_locale() ) {
			return false;
		}
		return (bool) switch_to_locale( $site );
	}

	/**
	 * APCu is per process pool: a command-line PHP (wp-cli cron, `wp cron
	 * event run`) cannot see the counters PHP-FPM wrote, and running the tick
	 * there would store empty minutes and advance the cursor past the real
	 * data. On the APCu backend the tick only runs inside the web server;
	 * the inline guard on the next front-end request does the work instead.
	 *
	 * @return bool
	 */
	public static function can_run_here() {
		if ( 'cli' !== PHP_SAPI ) {
			return true;
		}
		// From the CLI, APCu may look disabled (apc.enable_cli=0) and the
		// detection would fall through to the transient backend, which is
		// equally blind to the web pool's counters. Decide on what the web
		// server would use: APCu present means the web pool is on APCu unless
		// a persistent object cache is in place.
		if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return true;
		}
		if ( function_exists( 'apcu_inc' ) ) {
			return false;
		}
		return true;
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
