<?php
/**
 * Learned baseline (design v1, 5.3): median distinct IPs per minute and the
 * normal asset ratio, learned over the first seven days from daily medians
 * of the stored minute rows, then frozen. Shown beside each threshold, and
 * used by the score to decide what "a lot of addresses" means on this site.
 * One baseline per source (BotStormRadar_Sources); the site keeps the 0.1.x option.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The learned baseline: normal distinct addresses per minute and normal asset ratio, learned over the first days.
 */
class BotStormRadar_Baseline {

	const OPTION     = 'botstormradar_baseline';
	const LEARN_DAYS = 7;
	const KEEP_DAYS  = 14;

	/**
	 * Return the name of the option that holds a source's baseline.
	 *
	 * @param string $source Source id: the site or a log source.
	 * @return string
	 */
	private static function option_name( $source ) {
		return BotStormRadar_Sources::option( self::OPTION, $source );
	}

	/**
	 * Return a source's stored baseline, with defaults for what is missing.
	 *
	 * @param string $source Source id; the site itself by default.
	 * @return array
	 */
	public static function get( $source = BotStormRadar_Sources::SITE ) {
		$b = get_option( self::option_name( $source ), [] );
		$b = is_array( $b ) ? $b : [];
		return wp_parse_args( $b, [
			'started'  => 0,
			'days'     => [],
			'learned'  => null,
			'last_day' => '',
		] );
	}

	/**
	 * Record when learning began, unless it already has.
	 *
	 * @param string $source Source id; the site itself by default.
	 */
	public static function ensure_started( $source = BotStormRadar_Sources::SITE ) {
		$b = self::get( $source );
		if ( empty( $b['started'] ) ) {
			$b['started'] = time();
			update_option( self::option_name( $source ), $b, false );
		}
	}

	/**
	 * Forget everything and start learning again.
	 *
	 * @param string $source Source id; the site itself by default.
	 */
	public static function reset( $source = BotStormRadar_Sources::SITE ) {
		update_option( self::option_name( $source ), [ 'started' => time(), 'days' => [], 'learned' => null, 'last_day' => '' ], false );
	}

	/**
	 * Called by the tick. When the UTC day has rolled over since the last summary, summarize yesterday from its minute rows.
	 *
	 * @param int    $now    Unix timestamp.
	 * @param string $source Source id; the site itself by default.
	 */
	public static function maybe_rollover( $now, $source = BotStormRadar_Sources::SITE ) {
		$b         = self::get( $source );
		$yesterday = gmdate( 'Y-m-d', $now - 86400 );
		if ( $b['last_day'] === $yesterday || gmdate( 'Y-m-d', $now ) === gmdate( 'Y-m-d', (int) $b['started'] ) ) {
			return;
		}
		$rows = BotStormRadar_Storage::minutes_for_day( str_replace( '-', '', $yesterday ), $source );
		if ( count( $rows ) < 60 ) {
			// Less than an hour of data: skip the day rather than learn from noise.
			$b['last_day'] = $yesterday;
			update_option( self::option_name( $source ), $b, false );
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
		update_option( self::option_name( $source ), $b, false );
	}

	/**
	 * Summarize the learned days into medians and a 90th percentile.
	 *
	 * @param array $days Daily summaries, keyed by UTC date.
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
	 * What the score should use right now: the frozen values once learned, otherwise the provisional figure from whatever days exist, otherwise nothing (the score then falls back to the minimum-IPs setting).
	 *
	 * @param string $source Source id; the site itself by default.
	 * @return array {ips_median, ips_p90, asset_median, days, status}
	 */
	public static function effective( $source = BotStormRadar_Sources::SITE ) {
		$b = self::get( $source );
		if ( is_array( $b['learned'] ) ) {
			return $b['learned'] + [ 'status' => 'learned' ];
		}
		if ( ! empty( $b['days'] ) ) {
			return self::summarize( $b['days'] ) + [ 'status' => 'learning' ];
		}
		return [ 'ips_median' => null, 'ips_p90' => null, 'asset_median' => null, 'days' => 0, 'status' => 'learning' ];
	}

	/**
	 * Return the median of the values.
	 *
	 * @param array $values Numbers.
	 * @return float
	 */
	public static function median( array $values ) {
		return self::percentile( $values, 0.5 );
	}

	/**
	 * Return a percentile of the values, interpolating between the nearest two.
	 *
	 * @param array $values Numbers.
	 * @param float $p      Percentile as a fraction between 0 and 1, such as 0.9.
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
