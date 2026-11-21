<?php
/**
 * The read position in one log source: the files of a source, the inode and
 * byte offset reached in each, and the rotation handling that keeps a run from
 * losing or repeating lines (see class-botstormradar-log-source.php).
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads one log file line by line from the saved position, and follows a rotation to the renamed file.
 */
class BotStormRadar_Log_Cursor {

	/**
	 * Path of the log file.
	 *
	 * @var string
	 */
	public $path;

	/**
	 * The next line of the log, parsed.
	 *
	 * @var array Parsed head line plus its position, or null at the end.
	 */
	public $head = null;

	/**
	 * Number of lines that were not combined-format lines.
	 *
	 * @var int Lines that were not combined-format lines.
	 */
	public $bad = 0;

	/**
	 * Number of bytes read so far.
	 *
	 * @var int Bytes read.
	 */
	public $bytes = 0;

	/**
	 * What happened to the file since the last run.
	 *
	 * @var string '' | 'new' | 'rotated' | 'truncated' | 'gap' | 'missing'
	 */
	public $event = '';

	/**
	 * The files to read, in order.
	 *
	 * @var array Segments to read in order: [ file, inode, offset ].
	 */
	private $segments = [];

	/**
	 * Index of the segment being read.
	 *
	 * @var int
	 */
	private $seg = -1;

	/**
	 * Handle of the segment being read.
	 *
	 * @var resource|null
	 */
	private $fh = null;

	/**
	 * Where the next run starts.
	 *
	 * @var array inode, pos: where the next run starts.
	 */
	private $commit;

	/**
	 * Open a cursor on a log file, picking up where the last run stopped.
	 *
	 * @param string $path       Path of the log file.
	 * @param array  $saved      inode, pos from the last run (empty on the first).
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
			$p = BotStormRadar_Log_Line::parse( $line );
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

	/**
	 * Close the file handle, if one is open.
	 *
	 * @return void
	 */
	public function close() {
		if ( null !== $this->fh ) {
			fclose( $this->fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->fh = null;
		}
	}

	/**
	 * Open the next segment that can be opened, skipping files that cannot be.
	 *
	 * @return bool True when a segment is open.
	 */
	private function open_next_segment() {
		$last = count( $this->segments ) - 1;
		while ( $this->seg < $last ) {
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
