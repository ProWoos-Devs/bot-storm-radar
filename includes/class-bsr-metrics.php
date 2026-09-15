<?php
/**
 * Swarm metrics (design v1, 5.3), computed per finished minute from
 * counters only, never from a per-IP scan:
 *
 *  - single-hit ratio: distinct addresses that made exactly one request,
 *    over all distinct addresses;
 *  - asset ratio: addresses that fetched the beacon over addresses that were
 *    served an HTML page (capped at 1; page caches make it exceed 1);
 *  - user-agent evenness: normalized entropy of the request spread across
 *    the user-agent strings seen, weighted by how few strings serve how many
 *    addresses (one operator rotating six strings over a huge pool);
 *  - error pressure: 5xx plus slow responses over all requests;
 *  - endpoint concentration: the largest share held by one sensitive class
 *    (search, xmlrpc, REST, login, register, comment, cart, checkout, 404,
 *    admin-ajax, wc-ajax, and for a MediaWiki log source special pages and
 *    old revisions).
 *
 * The storm score combines them, gated by volume against the learned
 * baseline so a quiet minute with three single-hit visitors scores zero.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Metrics {

	const SENSITIVE_CLASSES = [ 'search', 'xmlrpc', 'rest', 'login', 'register', 'comment', 'cart', 'checkout', '404', 'admin-ajax', 'wc-ajax', 'special', 'revision' ];

	const WEIGHTS = [
		'single_hit'    => 0.35,
		'asset_gap'     => 0.35,
		'ua_evenness'   => 0.10,
		'error'         => 0.10,
		'concentration' => 0.10,
	];

	/**
	 * Fewer HTML-serving addresses than this and the asset ratio says nothing.
	 */
	const MIN_HTML_IPS_FOR_ASSET = 5;

	/**
	 * Read one minute's counters and compute the row the tick stores.
	 *
	 * @param int        $minute
	 * @param array|null $opts     Plugin options (defaults to stored).
	 * @param array|null $baseline Baseline (defaults to the site's effective one).
	 * @return array
	 */
	public static function compute_minute( $minute, $opts = null, $baseline = null ) {
		$minute = (int) $minute;
		$p      = 'm:' . $minute . ':';
		$base   = [ 'total', 'ips', 'multi', 'nets', 'uas', 'sessions', 'html_ips', 'beacon_ips', 'err5', 'slow' ];
		$keys   = [];
		foreach ( $base as $k ) {
			$keys[] = $p . $k;
		}
		$classes = BSR_Classifier::known_classes();
		foreach ( $classes as $c ) {
			$keys[] = $p . 'c:' . $c;
		}
		$bots = array_keys( BSR_Good_Bots::bots() );
		foreach ( $bots as $b ) {
			foreach ( [ 'verified', 'fake', 'pending' ] as $v ) {
				$keys[] = $p . 'bot:' . $b . ':' . $v;
			}
		}
		$vals = BSR_Counters::get_multi( $keys );

		$row = [ 'm' => $minute ];
		foreach ( $base as $k ) {
			$row[ $k ] = (int) ( $vals[ $p . $k ] ?? 0 );
		}
		$row['single'] = max( 0, $row['ips'] - $row['multi'] );

		$row['classes'] = [];
		foreach ( $classes as $c ) {
			$n = (int) ( $vals[ $p . 'c:' . $c ] ?? 0 );
			if ( $n > 0 ) {
				$row['classes'][ $c ] = $n;
			}
		}
		$row['bots'] = [];
		foreach ( $bots as $b ) {
			$set = [];
			foreach ( [ 'verified', 'fake', 'pending' ] as $v ) {
				$n = (int) ( $vals[ $p . 'bot:' . $b . ':' . $v ] ?? 0 );
				if ( $n > 0 ) {
					$set[ $v ] = $n;
				}
			}
			if ( $set ) {
				$row['bots'][ $b ] = $set;
			}
		}

		// User-agent distribution from the bounded registry.
		$row['ua_top']  = [];
		$row['ua_even'] = 0.0;
		$reg = BSR_Counters::get_value( $p . 'ua_reg', [] );
		if ( is_array( $reg ) && ! empty( $reg ) ) {
			$ua_keys = [];
			foreach ( array_keys( $reg ) as $h ) {
				$ua_keys[] = $p . 'ua:' . $h;
			}
			$counts = BSR_Counters::get_multi( $ua_keys );
			$dist   = [];
			foreach ( $reg as $h => $sample ) {
				$n = (int) ( $counts[ $p . 'ua:' . $h ] ?? 0 );
				if ( $n > 0 ) {
					$dist[ $h ] = $n;
				}
			}
			arsort( $dist );
			foreach ( array_slice( $dist, 0, 5, true ) as $h => $n ) {
				$row['ua_top'][] = [ (string) $reg[ $h ], $n ];
			}
			$row['ua_even'] = self::normalized_entropy( array_values( $dist ) );
		}

		// Hot keys for the dashboard (present only when something got hot).
		$row['hot'] = [];
		foreach ( [ 'ips', 'nets' ] as $kind ) {
			$hot = BSR_Counters::get_value( $p . 'hot_' . $kind, [] );
			if ( is_array( $hot ) && ! empty( $hot ) ) {
				$hk = [];
				foreach ( array_keys( $hot ) as $k ) {
					$hk[] = $p . ( 'ips' === $kind ? 'ip:' : 'net:' ) . $k;
				}
				$hc  = BSR_Counters::get_multi( $hk );
				$out = [];
				foreach ( array_keys( $hot ) as $k ) {
					$out[ $k ] = (int) ( $hc[ $p . ( 'ips' === $kind ? 'ip:' : 'net:' ) . $k ] ?? 0 );
				}
				arsort( $out );
				$row['hot'][ $kind ] = array_slice( $out, 0, 20, true );
			}
		}

		return self::score( $row, $opts, $baseline );
	}

	/**
	 * Derive the ratios, the volume gate and the storm score for a row.
	 * Pure: tests feed synthetic rows.
	 *
	 * @param array      $row
	 * @param array|null $opts     Plugin options (defaults to stored).
	 * @param array|null $baseline Baseline (defaults to the effective one).
	 * @return array
	 */
	public static function score( array $row, $opts = null, $baseline = null ) {
		$opts     = null === $opts ? BSR_Helpers::get_options() : $opts;
		$baseline = null === $baseline ? BSR_Baseline::effective() : $baseline;

		$ips      = (int) ( $row['ips'] ?? 0 );
		$total    = (int) ( $row['total'] ?? 0 );
		$single   = (int) ( $row['single'] ?? max( 0, $ips - (int) ( $row['multi'] ?? 0 ) ) );
		$html_ips = (int) ( $row['html_ips'] ?? 0 );
		$beacons  = (int) ( $row['beacon_ips'] ?? 0 );
		$uas      = (int) ( $row['uas'] ?? 0 );

		$row['shr']         = $ips > 0 ? round( $single / $ips, 4 ) : 0.0;
		$row['asset_ratio'] = $html_ips > 0 ? round( min( 1.0, $beacons / $html_ips ), 4 ) : null;
		$row['ep']          = $total > 0 ? round( ( (int) ( $row['err5'] ?? 0 ) + (int) ( $row['slow'] ?? 0 ) ) / $total, 4 ) : 0.0;

		$row['conc']      = 0.0;
		$row['top_class'] = '';
		if ( $total > 0 && ! empty( $row['classes'] ) ) {
			foreach ( self::SENSITIVE_CLASSES as $c ) {
				$share = ( (int) ( $row['classes'][ $c ] ?? 0 ) ) / $total;
				if ( $share > $row['conc'] ) {
					$row['conc']      = round( $share, 4 );
					$row['top_class'] = $c;
				}
			}
		}

		// Few strings serving many addresses: the factor is 1 when the
		// user-agent count is negligible next to the address count and 0
		// once there is at least one string per ten addresses.
		$share_factor = $ips > 0 ? BSR_Helpers::clamp( 1 - ( $uas / $ips ) * 10, 0, 1 ) : 0.0;
		$ua_flat      = round( (float) ( $row['ua_even'] ?? 0 ) * $share_factor, 4 );

		// Volume gate against the baseline.
		$min_ips = max( 1, (int) ( $opts['min_distinct_ips'] ?? 30 ) );
		$spike   = max( 1.5, (float) ( $opts['spike_factor'] ?? 4 ) );
		$ref     = ! empty( $baseline['ips_median'] ) ? max( (float) $baseline['ips_median'], 1.0 ) : (float) $min_ips;
		$row['vol']  = round( $ips / $ref, 3 );
		$row['gate'] = $ips < $min_ips ? 0.0 : round( BSR_Helpers::clamp( ( $row['vol'] - 1 ) / ( $spike - 1 ), 0, 1 ), 4 );

		$ep_storm = max( 0.01, (float) ( $opts['error_pressure_storm'] ?? 0.5 ) );
		$terms    = [
			'single_hit'    => round( self::WEIGHTS['single_hit'] * $row['shr'], 4 ),
			'asset_gap'     => ( null !== $row['asset_ratio'] && $html_ips >= self::MIN_HTML_IPS_FOR_ASSET ) ? round( self::WEIGHTS['asset_gap'] * ( 1 - $row['asset_ratio'] ), 4 ) : 0.0,
			'ua_evenness'   => round( self::WEIGHTS['ua_evenness'] * $ua_flat, 4 ),
			'error'         => round( self::WEIGHTS['error'] * BSR_Helpers::clamp( $row['ep'] / $ep_storm, 0, 1 ), 4 ),
			'concentration' => round( self::WEIGHTS['concentration'] * $row['conc'], 4 ),
		];
		$row['terms'] = $terms;
		$row['raw']   = round( array_sum( $terms ), 4 );
		$row['score'] = (int) round( 100 * $row['gate'] * $row['raw'] );

		return $row;
	}

	/**
	 * Plain-words explanation of a row, naming the signals in descending
	 * order of contribution.
	 *
	 * @param array $row
	 * @param array|null $baseline
	 * @return string
	 */
	public static function explain( array $row, $baseline = null ) {
		$baseline = null === $baseline ? BSR_Baseline::effective() : $baseline;
		$terms    = is_array( $row['terms'] ?? null ) ? $row['terms'] : [];
		arsort( $terms );
		$parts = [];
		foreach ( $terms as $name => $contrib ) {
			if ( $contrib <= 0 ) {
				continue;
			}
			switch ( $name ) {
				case 'single_hit':
					$parts[] = sprintf( __( 'single-hit ratio %1$s (%2$d of %3$d addresses made exactly one request)', 'bot-storm-radar' ), self::fmt( $row['shr'] ?? 0 ), (int) ( $row['single'] ?? 0 ), (int) ( $row['ips'] ?? 0 ) );
					break;
				case 'asset_gap':
					$parts[] = sprintf( __( 'asset ratio %1$s (%2$d addresses fetched the beacon for %3$d served HTML)', 'bot-storm-radar' ), self::fmt( $row['asset_ratio'] ?? 0 ), (int) ( $row['beacon_ips'] ?? 0 ), (int) ( $row['html_ips'] ?? 0 ) );
					break;
				case 'ua_evenness':
					$parts[] = sprintf( __( 'user-agent evenness %1$s over %2$d strings', 'bot-storm-radar' ), self::fmt( $row['ua_even'] ?? 0 ), (int) ( $row['uas'] ?? 0 ) );
					break;
				case 'error':
					$parts[] = sprintf( __( 'error pressure %1$s (%2$d 5xx, %3$d slow of %4$d)', 'bot-storm-radar' ), self::fmt( $row['ep'] ?? 0 ), (int) ( $row['err5'] ?? 0 ), (int) ( $row['slow'] ?? 0 ), (int) ( $row['total'] ?? 0 ) );
					break;
				case 'concentration':
					$parts[] = sprintf( __( 'concentration %1$s on %2$s', 'bot-storm-radar' ), self::fmt( $row['conc'] ?? 0 ), (string) ( $row['top_class'] ?? '' ) );
					break;
			}
		}
		$ref = ! empty( $baseline['ips_median'] )
			? sprintf( __( 'baseline %s', 'bot-storm-radar' ), self::fmt( $baseline['ips_median'], 0 ) )
			: __( 'no baseline yet, using the minimum-addresses setting', 'bot-storm-radar' );
		$volume = sprintf( __( 'Volume: %1$d distinct addresses in the minute, %2$s (%3$s×), gate %4$s.', 'bot-storm-radar' ), (int) ( $row['ips'] ?? 0 ), $ref, self::fmt( $row['vol'] ?? 0, 1 ), self::fmt( $row['gate'] ?? 0 ) );

		if ( empty( $parts ) ) {
			return sprintf( __( 'Storm score %d: no signal fired. %s', 'bot-storm-radar' ), (int) ( $row['score'] ?? 0 ), $volume );
		}
		return sprintf( __( 'Storm score %1$d: %2$s. %3$s', 'bot-storm-radar' ), (int) ( $row['score'] ?? 0 ), implode( ', ', $parts ), $volume );
	}

	/**
	 * Shannon entropy over counts, normalized to 0..1 by log(n).
	 *
	 * @param array $counts
	 * @return float
	 */
	public static function normalized_entropy( array $counts ) {
		$counts = array_values( array_filter( array_map( 'intval', $counts ), function ( $c ) {
			return $c > 0;
		} ) );
		$n = count( $counts );
		if ( $n < 2 ) {
			return 0.0;
		}
		$sum = array_sum( $counts );
		$h   = 0.0;
		foreach ( $counts as $c ) {
			$p  = $c / $sum;
			$h -= $p * log( $p );
		}
		return round( $h / log( $n ), 4 );
	}

	/**
	 * @param float $v
	 * @param int   $d
	 * @return string
	 */
	public static function fmt( $v, $d = 2 ) {
		return number_format_i18n( (float) $v, $d );
	}
}
