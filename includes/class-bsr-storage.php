<?php
/**
 * Minute rows and transition log. Written by the tick only (never on the
 * request path except the self-healing inline tick, which runs after the
 * response is flushed).
 *
 * Minute rows live in hourly chunks, one non-autoloaded option per UTC hour,
 * kept for 25 hours. Each write is a few kilobytes, once a minute.
 *
 * Every method takes the source (BSR_Sources); the site is the default and
 * keeps the 0.1.x option names.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Storage {

	const CHUNK_PREFIX      = 'bsr_min_';
	const CHUNK_INDEX       = 'bsr_min_chunks';
	const CHUNK_HOURS       = 25;
	const TRANSITIONS       = 'bsr_transitions';
	const TRANSITIONS_CAP   = 200;

	/**
	 * @param int    $minute
	 * @param string $source
	 * @return string
	 */
	private static function chunk_for( $minute, $source = BSR_Sources::SITE ) {
		return BSR_Sources::option( self::CHUNK_PREFIX, $source ) . gmdate( 'YmdH', $minute * 60 );
	}

	/**
	 * @param array  $row    Must carry 'm' (minute id).
	 * @param string $source
	 */
	public static function add_minute( array $row, $source = BSR_Sources::SITE ) {
		$minute = (int) $row['m'];
		$name   = self::chunk_for( $minute, $source );
		$chunk  = get_option( $name, [] );
		$chunk  = is_array( $chunk ) ? $chunk : [];
		$chunk[ $minute ] = $row;
		ksort( $chunk );
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $chunk, '', false );
		} else {
			update_option( $name, $chunk, false );
		}

		$index_name = BSR_Sources::option( self::CHUNK_INDEX, $source );
		$index      = get_option( $index_name, [] );
		$index      = is_array( $index ) ? $index : [];
		if ( ! in_array( $name, $index, true ) ) {
			$index[] = $name;
			sort( $index );
			while ( count( $index ) > self::CHUNK_HOURS ) {
				$old = array_shift( $index );
				delete_option( $old );
			}
			update_option( $index_name, $index, false );
		}
	}

	/**
	 * Minute rows for the last $n minutes ending at $until (inclusive),
	 * keyed by minute id, oldest first. Missing minutes are absent.
	 *
	 * @param int      $n
	 * @param int|null $until
	 * @param string   $source
	 * @return array
	 */
	public static function minutes_last( $n, $until = null, $source = BSR_Sources::SITE ) {
		$until = null === $until ? BSR_Helpers::minute() - 1 : (int) $until;
		$from  = $until - (int) $n + 1;
		$rows  = [];
		$seen  = [];
		for ( $m = $from; $m <= $until; $m += 60 ) {
			$name = self::chunk_for( $m, $source );
			$seen[ $name ] = true;
		}
		$seen[ self::chunk_for( $until, $source ) ] = true;
		foreach ( array_keys( $seen ) as $name ) {
			$chunk = get_option( $name, [] );
			if ( ! is_array( $chunk ) ) {
				continue;
			}
			foreach ( $chunk as $m => $row ) {
				if ( $m >= $from && $m <= $until ) {
					$rows[ (int) $m ] = $row;
				}
			}
		}
		ksort( $rows );
		return $rows;
	}

	/**
	 * Every stored minute row for a UTC date (baseline day summaries).
	 *
	 * @param string $ymd
	 * @param string $source
	 * @return array
	 */
	public static function minutes_for_day( $ymd, $source = BSR_Sources::SITE ) {
		$rows   = [];
		$prefix = BSR_Sources::option( self::CHUNK_PREFIX, $source );
		for ( $h = 0; $h < 24; $h++ ) {
			$chunk = get_option( $prefix . $ymd . sprintf( '%02d', $h ), [] );
			if ( is_array( $chunk ) ) {
				foreach ( $chunk as $m => $row ) {
					$rows[ (int) $m ] = $row;
				}
			}
		}
		ksort( $rows );
		return $rows;
	}

	/**
	 * @param array  $t      from, to, at, minute, score, explanation, metrics.
	 * @param string $source
	 */
	public static function add_transition( array $t, $source = BSR_Sources::SITE ) {
		$name = BSR_Sources::option( self::TRANSITIONS, $source );
		$log  = get_option( $name, [] );
		$log = is_array( $log ) ? $log : [];
		array_unshift( $log, $t );
		if ( count( $log ) > self::TRANSITIONS_CAP ) {
			$log = array_slice( $log, 0, self::TRANSITIONS_CAP );
		}
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $log, '', false );
		} else {
			update_option( $name, $log, false );
		}
	}

	/**
	 * @param int    $n
	 * @param string $source
	 * @return array Newest first.
	 */
	public static function transitions( $n = 50, $source = BSR_Sources::SITE ) {
		$log = get_option( BSR_Sources::option( self::TRANSITIONS, $source ), [] );
		return is_array( $log ) ? array_slice( $log, 0, $n ) : [];
	}

	/**
	 * Remove every stored row, transition and chunk of a source (tests, reset).
	 *
	 * @param string $source
	 */
	public static function clear( $source = BSR_Sources::SITE ) {
		$index_name = BSR_Sources::option( self::CHUNK_INDEX, $source );
		$index      = get_option( $index_name, [] );
		if ( is_array( $index ) ) {
			foreach ( $index as $name ) {
				delete_option( $name );
			}
		}
		delete_option( $index_name );
		delete_option( BSR_Sources::option( self::TRANSITIONS, $source ) );
	}
}
