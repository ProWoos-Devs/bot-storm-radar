<?php
/**
 * Log-fed sources: traffic the WordPress request path never sees (a
 * MediaWiki on the same server, requests nginx answers from its page cache
 * or refuses at a gate) read from the web server's access log.
 *
 * A source is defined from the command line (`wp bot-storm-radar source
 * add`) with a profile and one or more log files, and run once a minute by
 * `wp bot-storm-radar ingest` (a system timer). Each run reads the lines
 * written since the last run, replays every finished minute through the
 * same recorder, metrics and state machine the site uses, on the in-memory
 * counter backend, and stores the rows, the state and the transitions under
 * the source's own option names (BSR_Sources).
 *
 * Positions are kept per file as inode and byte offset, committed only at
 * minute boundaries so a run that stops early never loses half a minute.
 * Daily rotation by rename (logrotate without copytruncate, then an nginx
 * reload) is followed by finishing the renamed `<file>.1` from the saved
 * offset before starting the new file. A file that shrank was truncated in
 * place and is read from the start.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Log_Cursor {

	/**
	 * @var string
	 */
	public $path;

	/**
	 * @var array Parsed head line plus its position, or null at the end.
	 */
	public $head = null;

	/**
	 * @var int Lines that were not combined-format lines.
	 */
	public $bad = 0;

	/**
	 * @var int Bytes read.
	 */
	public $bytes = 0;

	/**
	 * @var string '' | 'new' | 'rotated' | 'truncated' | 'gap' | 'missing'
	 */
	public $event = '';

	/**
	 * @var array Segments to read in order: [ file, inode, offset ].
	 */
	private $segments = [];

	/**
	 * @var int
	 */
	private $seg = -1;

	/**
	 * @var resource|null
	 */
	private $fh = null;

	/**
	 * @var array inode, pos: where the next run starts.
	 */
	private $commit;

	/**
	 * @param string $path
	 * @param array  $saved inode, pos from the last run (empty on the first).
	 * @param bool   $from_start Read the file from its first byte (replay).
	 */
	public function __construct( $path, array $saved, $from_start = false ) {
		$this->path   = (string) $path;
		$this->commit = [ 'ino' => (int) ( $saved['ino'] ?? 0 ), 'pos' => (int) ( $saved['pos'] ?? 0 ) ];
		if ( $from_start ) {
			$this->segments = [ [ $this->path, 0, 0 ] ];
			return;
		}
		clearstatcache();
		$st = @stat( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $st ) {
			$this->event = 'missing';
			return;
		}
		if ( 0 === $this->commit['ino'] ) {
			// Never seen: a file added to the source, or one that did not exist
			// yet. Read it from the start; on a source's first run
			// skip_to_end() moves it to the end instead.
			$this->event    = 'new';
			$this->segments = [ [ $this->path, (int) $st['ino'], 0 ] ];
			return;
		}
		if ( (int) $st['ino'] === $this->commit['ino'] ) {
			$pos = $this->commit['pos'];
			if ( $pos > (int) $st['size'] ) {
				$pos         = 0;
				$this->event = 'truncated';
			}
			$this->segments = [ [ $this->path, (int) $st['ino'], $pos ] ];
			return;
		}
		$rotated = @stat( $this->path . '.1' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $rotated && (int) $rotated['ino'] === $this->commit['ino'] ) {
			$this->event    = 'rotated';
			$this->segments = [ [ $this->path . '.1', (int) $rotated['ino'], $this->commit['pos'] ], [ $this->path, (int) $st['ino'], 0 ] ];
			return;
		}
		$this->event    = 'gap';
		$this->segments = [ [ $this->path, (int) $st['ino'], 0 ] ];
	}

	/**
	 * Start at the current end of the file (first run, or a backlog too old
	 * to be worth replaying).
	 */
	public function skip_to_end() {
		$this->close();
		clearstatcache();
		$st = @stat( $this->path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false !== $st ) {
			$this->commit = [ 'ino' => (int) $st['ino'], 'pos' => (int) $st['size'] ];
		}
		$this->segments = [];
		$this->head     = null;
	}

	/**
	 * Advance to the next parseable, complete line.
	 */
	public function next() {
		$this->head = null;
		while ( true ) {
			if ( null === $this->fh && ! $this->open_next_segment() ) {
				return;
			}
			$start = ftell( $this->fh );
			$line  = fgets( $this->fh );
			if ( false === $line || "\n" !== substr( $line, -1 ) ) {
				// End of this segment, or a line nginx is still writing: leave
				// it for the next run.
				$this->commit = [ 'ino' => $this->segments[ $this->seg ][1], 'pos' => (int) $start ];
				if ( $this->seg < count( $this->segments ) - 1 ) {
					$this->close();
					continue;
				}
				return;
			}
			$this->bytes += strlen( $line );
			$p = BSR_Log_Line::parse( $line );
			if ( null === $p ) {
				$this->bad++;
				continue;
			}
			$p['ino']   = $this->segments[ $this->seg ][1];
			$p['pos']   = (int) $start;
			$this->head = $p;
			return;
		}
	}

	/**
	 * Where the next run should start: the unread head line, or the end.
	 *
	 * @return array inode, pos
	 */
	public function commit_point() {
		if ( null !== $this->head ) {
			return [ 'ino' => (int) $this->head['ino'], 'pos' => (int) $this->head['pos'] ];
		}
		return $this->commit;
	}

	public function close() {
		if ( null !== $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->fh = null;
		}
	}

	/**
	 * @return bool
	 */
	private function open_next_segment() {
		while ( $this->seg < count( $this->segments ) - 1 ) {
			$this->seg++;
			list( $file, , $pos ) = $this->segments[ $this->seg ];
			$open = '.gz' === substr( $file, -3 ) ? 'compress.zlib://' . $file : $file;
			$fh   = @fopen( $open, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( false === $fh ) {
				continue;
			}
			if ( $pos > 0 ) {
				fseek( $fh, $pos );
			}
			$this->fh = $fh;
			return true;
		}
		return false;
	}
}

class BSR_Log_Source {

	const OPTION        = BSR_Sources::LOG_OPTION;
	const MAX_BYTES     = 67108864;
	const MAX_CATCHUP   = 60;
	const LOCK_STALE_S  = 600;
	const GLOBALS_TTL   = 86400;

	/**
	 * @return array id => label, profile, logs, alert_to, created
	 */
	public static function all() {
		return BSR_Sources::log_sources();
	}

	/**
	 * @param string $id
	 * @return array|null
	 */
	public static function get( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) && is_array( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * @param string $id
	 * @param array  $def label, profile, logs, alert_to
	 */
	public static function save( $id, array $def ) {
		$all        = self::all();
		$all[ $id ] = [
			'label'    => (string) ( $def['label'] ?? $id ),
			'profile'  => (string) $def['profile'],
			'logs'     => array_values( array_map( 'strval', (array) $def['logs'] ) ),
			'alert_to' => implode( ', ', BSR_Helpers::sanitize_email_list( (string) ( $def['alert_to'] ?? '' ) ) ),
			'created'  => (int) ( $def['created'] ?? time() ),
		];
		update_option( self::OPTION, $all, false );
	}

	/**
	 * @param string $id
	 * @param bool   $purge Also delete its rows, state, baseline and cursor.
	 */
	public static function remove( $id, $purge = false ) {
		$all = self::all();
		unset( $all[ $id ] );
		update_option( self::OPTION, $all, false );
		if ( $purge ) {
			self::purge( $id );
		}
	}

	/**
	 * @param string $id
	 */
	public static function purge( $id ) {
		BSR_Storage::clear( $id );
		foreach ( [ BSR_Storm::STATE_OPTION, BSR_Baseline::OPTION, 'bsr_log_state', 'bsr_log_lock' ] as $name ) {
			delete_option( BSR_Sources::option( $name, $id ) );
		}
		delete_transient( BSR_Sources::option( 'bsr_log_globals', $id ) );
	}

	/**
	 * @param string $id
	 * @return array last_minute, last_run, files, stats
	 */
	public static function state( $id ) {
		return BSR_Sources::log_state( $id );
	}

	/**
	 * Read what the source's logs gained since the last run and process every
	 * finished minute.
	 *
	 * @param string   $id
	 * @param int|null $now
	 * @return array Run summary (status: ok | started | caught_up | locked | unknown).
	 */
	public static function run( $id, $now = null ) {
		$def = self::get( $id );
		if ( null === $def ) {
			return [ 'status' => 'unknown' ];
		}
		$now  = null === $now ? time() : (int) $now;
		$lock = BSR_Sources::option( 'bsr_log_lock', $id );
		if ( ! add_option( $lock, $now, '', false ) ) {
			if ( $now - (int) get_option( $lock ) < self::LOCK_STALE_S ) {
				return [ 'status' => 'locked' ];
			}
			update_option( $lock, $now, false );
		}

		try {
			$state   = self::state( $id );
			$current = BSR_Helpers::minute( $now );
			$target  = $current - 1;
			$cursors = [];
			foreach ( $def['logs'] as $path ) {
				$cursors[ $path ] = new BSR_Log_Cursor( $path, (array) ( $state['files'][ $path ] ?? [] ) );
			}
			BSR_Baseline::ensure_started( $id );

			$last = (int) $state['last_minute'];
			if ( 0 === $last || $last < $target - self::MAX_CATCHUP ) {
				foreach ( $cursors as $path => $c ) {
					$c->skip_to_end();
					$state['files'][ $path ] = $c->commit_point();
				}
				$status               = 0 === $last ? 'started' : 'caught_up';
				$state['last_minute'] = $target;
				$state['last_run']    = $now;
				$state['stats']       = [ 'status' => $status, 'skipped_minutes' => 0 === $last ? 0 : $target - $last ];
				update_option( BSR_Sources::option( 'bsr_log_state', $id ), $state, false );
				return $state['stats'];
			}

			self::begin_memory( $id );
			$summary = self::process( $id, $def['profile'], $cursors, $last + 1, $current, $state, true );
			BSR_Baseline::maybe_rollover( $now, $id );
			BSR_Good_Bots::verify_pending();
			self::end_memory( $id );

			$state                = $summary['state'];
			$state['last_run']    = $now;
			$state['stats']       = array_diff_key( $summary, [ 'state' => 1, 'transitions' => 1 ] ) + [ 'status' => 'ok' ];
			update_option( BSR_Sources::option( 'bsr_log_state', $id ), $state, false );
			return $state['stats'] + [ 'transitions' => $summary['transitions'] ];
		} finally {
			foreach ( $cursors ?? [] as $c ) {
				$c->close();
			}
			delete_option( $lock );
		}
	}

	/**
	 * Replay whole log files (plain or .gz) through a scratch source, for
	 * testing detection against a past storm. Stores into the source's
	 * options, which the caller purges.
	 *
	 * @param string     $id       Scratch source id.
	 * @param string     $profile
	 * @param array      $files    Read in parallel, merged by minute.
	 * @param array|null $baseline Baseline to score against (ips_median ...).
	 * @return array Summary with every minute row's score.
	 */
	public static function replay( $id, $profile, array $files, $baseline = null ) {
		self::purge( $id );
		if ( is_array( $baseline ) ) {
			// Stored as the scratch source's learned baseline, so the score and
			// the explanation in each transition read the same figure.
			$learned = $baseline + [ 'ips_median' => null, 'ips_p90' => null, 'asset_median' => null, 'days' => 7 ];
			update_option( BSR_Sources::option( BSR_Baseline::OPTION, $id ), [ 'started' => time(), 'days' => [], 'learned' => $learned, 'last_day' => '' ], false );
		}
		$cursors = [];
		foreach ( $files as $f ) {
			$cursors[ $f ] = new BSR_Log_Cursor( $f, [], true );
		}
		self::begin_memory( $id, false );
		$state   = [ 'last_minute' => 0, 'files' => [] ];
		$summary = self::process( $id, $profile, $cursors, null, PHP_INT_MAX, $state, false );
		self::end_memory( $id, false );
		foreach ( $cursors as $c ) {
			$c->close();
		}
		return $summary;
	}

	/**
	 * The merge loop: take the earliest head line across the files, feed it
	 * to its minute, finish each minute when a later line shows up, commit
	 * positions at every finished minute.
	 *
	 * @param string     $id
	 * @param string     $profile
	 * @param array      $cursors  path => BSR_Log_Cursor
	 * @param int|null   $bucket   First minute to fill (null: the first line's).
	 * @param int        $current  Lines at or after this minute are left unread.
	 * @param array      $state    Source state (files, last_minute), updated.
	 * @param bool       $live     Commit the state after every minute and stop at the byte budget.
	 * @return array
	 */
	private static function process( $id, $profile, array $cursors, $bucket, $current, array $state, $live ) {
		$opts     = BSR_Helpers::get_options();
		$baseline = BSR_Baseline::effective( $id );
		$sum      = [ 'lines' => 0, 'late' => 0, 'minutes' => 0, 'bytes' => 0, 'bad' => 0, 'events' => [], 'transitions' => [], 'scores' => [] ];
		$stopped  = false;

		foreach ( $cursors as $c ) {
			$c->next();
		}

		$finish = function ( $m ) use ( $id, $opts, $baseline, $cursors, &$state, &$sum, $live ) {
			$row = BSR_Metrics::compute_minute( $m, $opts, $baseline );
			BSR_Storage::add_minute( $row, $id );
			foreach ( BSR_Storm::step( $row, $opts, $id ) as $t ) {
				$sum['transitions'][] = [ 'minute' => $m, 'from' => $t['from'], 'to' => $t['to'], 'score' => $t['score'], 'explanation' => $t['explanation'] ];
			}
			BSR_Counters::memory_purge( 'm:' . $m . ':' );
			$sum['scores'][ $m ] = [ (int) $row['score'], (int) $row['ips'], (int) $row['total'] ];
			$sum['minutes']++;
			$state['last_minute'] = $m;
			foreach ( $cursors as $path => $c ) {
				if ( 'missing' !== $c->event ) {
					$state['files'][ $path ] = $c->commit_point();
				}
			}
			if ( $live ) {
				update_option( BSR_Sources::option( 'bsr_log_state', $id ), $state, false );
			}
		};

		while ( true ) {
			$pick = null;
			foreach ( $cursors as $c ) {
				if ( null !== $c->head && $c->head['minute'] < $current && ( null === $pick || $c->head['minute'] < $pick->head['minute'] ) ) {
					$pick = $c;
				}
			}
			if ( null === $pick ) {
				break;
			}
			$lm = (int) $pick->head['minute'];
			if ( null === $bucket ) {
				$bucket = $lm;
			}
			if ( $lm < $bucket ) {
				$sum['late']++;
				$pick->next();
				continue;
			}
			while ( $lm > $bucket ) {
				$finish( $bucket );
				$bucket++;
				if ( $live && self::bytes( $cursors ) > self::MAX_BYTES ) {
					$stopped = true;
					break 2;
				}
			}
			$class = BSR_Log_Line::classify( $pick->head, $profile );
			if ( 'cron' !== $class ) {
				BSR_Recorder::ingest( [
					'class'   => $class,
					'ip'      => $pick->head['ip'],
					'ua'      => $pick->head['ua'],
					'session' => '',
					'status'  => $pick->head['status'],
					'ms'      => 0,
				], $bucket );
			}
			$sum['lines']++;
			$pick->next();
		}

		if ( ! $stopped && null !== $bucket ) {
			$last_full = PHP_INT_MAX === $current ? $bucket : $current - 1;
			while ( $bucket <= $last_full ) {
				$finish( $bucket );
				$bucket++;
			}
		}

		$sum['bytes']   = self::bytes( $cursors );
		$sum['stopped'] = $stopped;
		foreach ( $cursors as $path => $c ) {
			$sum['bad'] += $c->bad;
			if ( '' !== $c->event ) {
				$sum['events'][ $path ] = $c->event;
			}
		}
		$sum['state'] = $state;
		return $sum;
	}

	/**
	 * @param array $cursors
	 * @return int
	 */
	private static function bytes( array $cursors ) {
		$n = 0;
		foreach ( $cursors as $c ) {
			$n += $c->bytes;
		}
		return $n;
	}

	/**
	 * Switch the counters to memory for this process and load the source's
	 * good-bot verdicts from the last run.
	 *
	 * @param string $id
	 * @param bool   $load_globals
	 */
	private static function begin_memory( $id, $load_globals = true ) {
		BSR_Counters::force( 'memory' );
		BSR_Counters::memory_reset();
		if ( $load_globals ) {
			$g = get_transient( BSR_Sources::option( 'bsr_log_globals', $id ) );
			if ( is_array( $g ) ) {
				BSR_Counters::memory_import( $g );
			}
		}
	}

	/**
	 * Keep the verdicts for the next run and give the counters back.
	 *
	 * @param string $id
	 * @param bool   $save_globals
	 */
	private static function end_memory( $id, $save_globals = true ) {
		if ( $save_globals ) {
			set_transient( BSR_Sources::option( 'bsr_log_globals', $id ), BSR_Counters::memory_export( 'g:' ), self::GLOBALS_TTL );
		}
		BSR_Counters::memory_reset();
		BSR_Counters::force( null );
	}
}
