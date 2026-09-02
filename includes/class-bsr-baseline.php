<?php
/**
 * Learned baseline (design v1, 5.3): median distinct IPs per minute and the
 * normal asset ratio, learned over the first seven days from daily medians
 * of the stored minute rows, then frozen. Shown beside each threshold, and
 * used by the score to decide what "a lot of addresses" means on this site.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Baseline {

	const OPTION     = 'bsr_baseline';
	const LEARN_DAYS = 7;
	const KEEP_DAYS  = 14;

	/**
	 * @return array
	 */
	public static function get() {
		$b = get_option( self::OPTION, [] );
		$b = is_array( $b ) ? $b : [];
		return wp_parse_args( $b, [
			'started'  => 0,
			'days'     => [],
			'learned'  => null,
			'last_day' => '',
		] );
	}

	public static function ensure_started() {
		$b = self::get();
		if ( empty( $b['started'] ) ) {
			$b['started'] = time();
			update_option( self::OPTION, $b, false );
		}
	}

	/**
	 * Forget everything and start learning again.
	 */
	public static function reset() {
		update_option( self::OPTION, [ 'started' => time(), 'days' => [], 'learned' => null, 'last_day' => '' ], false );
	}

	/**
	 * Called by the tick. When the UTC day has rolled over since the last
	 * summary, summarize yesterday from its minute rows.
	 *
	 * @param int $now
	 */
	public static function maybe_rollover( $now ) {
		$b         = self::get();
		$yesterday = gmdate( 'Y-m-d', $now - 86400 );
		if ( $b['last_day'] === $yesterday || gmdate( 'Y-m-d', $now ) === gmdate( 'Y-m-d', (int) $b['started'] ) ) {
			return;
		}
		$rows = BSR_Storage::minutes_for_day( str_replace( '-', '', $yesterday ) );
		if ( count( $rows ) < 60 ) {
			// Less than an hour of data: skip the day rather than learn from noise.
			$b['last_day'] = $yesterday;
			update_option( self::OPTION, $b, false );
			return;
		}
		$ips   = [];
		$asset = [];
		foreach ( $rows as $r ) {
			$ips[] = (int) ( $r['ips'] ?? 0 );
			if ( (int) ( $r['html_ips'] ?? 0 ) >= 5 ) {
				$asset[] = (float) ( $r['asset_ratio'] ?? 0 );
			}
		}
		$b['days'][ $yesterday ] = [
			'ips_median'   => self::median( $ips ),
			'ips_p90'      => self::percentile( $ips, 0.9 ),
			'asset_median' => empty( $asset ) ? null : self::median( $asset ),
			'minutes'      => count( $rows ),
		];
		ksort( $b['days'] );
		while ( count( $b['days'] ) > self::KEEP_DAYS ) {
			array_shift( $b['days'] );
		}
		$b['last_day'] = $yesterday;

		if ( null === $b['learned'] && count( $b['days'] ) >= self::LEARN_DAYS ) {
			$b['learned'] = self::summarize( array_slice( $b['days'], -self::LEARN_DAYS, null, true ) );
			$b['learned']['learned_at'] = $now;
		}
		update_option( self::OPTION, $b, false );
	}

	/**
	 * @param array $days
	 * @return array
	 */
	private static function summarize( array $days ) {
		$ips = [];
		$p90 = [];
		$ast = [];
		foreach ( $days as $d ) {
			$ips[] = (float) $d['ips_median'];
			$p90[] = (float) $d['ips_p90'];
			if ( null !== $d['asset_median'] ) {
				$ast[] = (float) $d['asset_median'];
			}
		}
		return [
			'ips_median'   => self::median( $ips ),
			'ips_p90'      => self::median( $p90 ),
			'asset_median' => empty( $ast ) ? null : self::median( $ast ),
			'days'         => count( $days ),
		];
	}

	/**
	 * What the score should use right now: the frozen values once learned,
	 * otherwise the provisional figure from whatever days exist, otherwise
	 * nothing (the score then falls back to the minimum-IPs setting).
	 *
	 * @return array {ips_median, ips_p90, asset_median, days, status}
	 */
	public static function effective() {
		$b = self::get();
		if ( is_array( $b['learned'] ) ) {
			return $b['learned'] + [ 'status' => 'learned' ];
		}
		if ( ! empty( $b['days'] ) ) {
			return self::summarize( $b['days'] ) + [ 'status' => 'learning' ];
		}
		return [ 'ips_median' => null, 'ips_p90' => null, 'asset_median' => null, 'days' => 0, 'status' => 'learning' ];
	}

	/**
	 * @param array $values
	 * @return float
	 */
	public static function median( array $values ) {
		return self::percentile( $values, 0.5 );
	}

	/**
	 * @param array $values
	 * @param float $p
	 * @return float
	 */
	public static function percentile( array $values, $p ) {
		if ( empty( $values ) ) {
			return 0.0;
		}
		sort( $values );
		$n   = count( $values );
		$pos = ( $n - 1 ) * $p;
		$lo  = (int) floor( $pos );
		$hi  = (int) ceil( $pos );
		if ( $lo === $hi ) {
			return (float) $values[ $lo ];
		}
		return (float) $values[ $lo ] + ( $pos - $lo ) * ( (float) $values[ $hi ] - (float) $values[ $lo ] );
	}
}
