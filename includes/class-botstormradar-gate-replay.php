<?php
/**
 * A dry run of the gate and the trips over web-server access logs, to see on
 * a site's own traffic what probe refusal and address bans would do before
 * they refuse anyone. Pure simulation in memory: no ban row, no would-be
 * ban, no counter, no mail.
 *
 * Lines are read in time order across all files (plain or .gz). For each:
 *   - refused by the web server already (403, 429, 444): counted apart, the
 *     gate never sees those;
 *   - a static file the web server served itself (an asset that was not a
 *     404): not a PHP request, skipped;
 *   - otherwise a request that reached PHP. The simulated gate refuses it when
 *     the address is under a simulated ban, or when it is a probe
 *     (BotStormRadar_Probe with the site's rules; the missing-PHP rule only with
 *     --root, since a log does not say which files exist). Probes and page
 *     404s count toward the trips with the site's settings, simulated as in
 *     enforce mode, with the guard's protected addresses never tripping and
 *     search-bot claims held pending (or verified by DNS with verify_bots).
 *
 * A log does not say who was logged in; every request counts as anonymous.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Gate_Replay {

	const REFUSED_STATUSES = [ 403, 429, 444 ];

	/**
	 * @param string[] $files
	 * @param array    $opts root, home, verify_bots, list
	 * @return array Summary.
	 */
	public static function run( array $files, array $opts = [] ) {
		$cfg = BotStormRadar_State::probe_config();
		$cfg['root'] = isset( $opts['root'] ) ? (string) $opts['root'] : '';
		$cfg['home'] = isset( $opts['home'] ) ? rtrim( (string) $opts['home'], '/' ) : '';
		if ( isset( $opts['home'] ) ) {
			$cfg['uploads'] = $cfg['home'] . '/wp-content/uploads/';
		}
		$cfg['on'] = true;
		$trips     = BotStormRadar_Trips::settings();
		$verify    = ! empty( $opts['verify_bots'] );

		$sum = [
			'lines'          => 0,
			'bad'            => 0,
			'server_refused' => 0,
			'server_probes'  => 0,
			'static'         => 0,
			'php'            => 0,
			'probes'         => 0,
			'probe_classes'  => [],
			'banned_hits'    => 0,
			'trips'          => [],
			'pending'        => [],
			'protected_skip' => 0,
			'first'          => 0,
			'last'           => 0,
		];
		$bans      = [];   // ip => expires
		$last_ban  = [];   // ip => time of the last simulated ban
		$probe_ts  = [];   // ip => [timestamps]
		$p404      = [];   // ip => [minute, count]
		$evidence  = [];   // ip => [paths]
		$bot_cache = [];

		foreach ( self::lines( $files ) as $p ) {
			if ( null === $p ) {
				$sum['bad']++;
				continue;
			}
			$sum['lines']++;
			$sum['first'] = $sum['first'] ?: $p['ts'];
			$sum['last']  = $p['ts'];
			$ip           = $p['ip'];
			$probe        = BotStormRadar_Probe::classify( $p['target'], $cfg );
			if ( in_array( $p['status'], self::REFUSED_STATUSES, true ) ) {
				$sum['server_refused']++;
				if ( '' !== $probe ) {
					$sum['server_probes']++;
				}
				continue;
			}
			// Asset or page from the extension and the status only: the radar's
			// classifier would apply this machine's files to the missing-PHP rule.
			$path  = strtolower( (string) strtok( (string) $p['target'], '?' ) );
			$ext   = (string) pathinfo( $path, PATHINFO_EXTENSION );
			$class = in_array( $ext, BotStormRadar_Classifier::ASSET_EXTENSIONS, true ) ? 'asset' : ( 404 === $p['status'] ? '404' : 'page' );
			if ( 'asset' === $class && 404 !== $p['status'] && '' === $probe ) {
				$sum['static']++;
				continue;
			}
			$sum['php']++;
			if ( isset( $bans[ $ip ] ) && $bans[ $ip ] > $p['ts'] ) {
				$sum['banned_hits']++;
				continue;
			}
			$reason = '';
			if ( '' !== $probe ) {
				$sum['probes']++;
				$sum['probe_classes'][ $probe ] = ( $sum['probe_classes'][ $probe ] ?? 0 ) + 1;
				self::note( $evidence, $ip, $p['target'] );
				if ( $trips['probe']['count'] > 0 ) {
					$w   = $p['ts'] - (int) $trips['probe']['window'];
					$lst = array_values( array_filter( $probe_ts[ $ip ] ?? [], function ( $t ) use ( $w ) {
						return $t > $w;
					} ) );
					$lst[]           = $p['ts'];
					$probe_ts[ $ip ] = $lst;
					if ( count( $lst ) === (int) $trips['probe']['count'] ) {
						$reason = 'probe';
					}
				}
			} elseif ( '404' === $class && $trips['404']['count'] > 0 ) {
				self::note( $evidence, $ip, $p['target'] );
				$cur          = $p404[ $ip ] ?? [ 0, 0 ];
				$p404[ $ip ]  = $cur[0] === $p['minute'] ? [ $cur[0], $cur[1] + 1 ] : [ $p['minute'], 1 ];
				if ( $p404[ $ip ][1] === (int) $trips['404']['count'] ) {
					$reason = '404';
				}
			}
			if ( '' === $reason ) {
				continue;
			}
			// A trip.
			if ( ! BotStormRadar_Guard::may_ban( $ip ) ) {
				$sum['protected_skip']++;
				continue;
			}
			$claim = BotStormRadar_Good_Bots::claimed( $p['ua'] );
			if ( '' !== $claim ) {
				if ( ! $verify ) {
					$sum['pending'][ $ip ] = $claim;
					continue;
				}
				if ( ! isset( $bot_cache[ $ip ] ) ) {
					$bot_cache[ $ip ] = BotStormRadar_Good_Bots::verify_now( $ip, $claim );
				}
				if ( $bot_cache[ $ip ] ) {
					$sum['pending'][ $ip ] = $claim . ' (verified, never banned)';
					continue;
				}
			}
			$ttl = (int) $trips[ $reason ]['ttl'];
			if ( isset( $last_ban[ $ip ] ) && $last_ban[ $ip ] > $p['ts'] - DAY_IN_SECONDS ) {
				$ttl = max( $ttl, (int) $trips['ttl_repeat'] );
			}
			$bans[ $ip ]     = $p['ts'] + $ttl;
			$last_ban[ $ip ] = $p['ts'];
			$sum['trips'][]  = [
				'ip'     => $ip,
				'reason' => $reason,
				'at'     => $p['ts'],
				'until'  => $p['ts'] + $ttl,
				'claim'  => '' === $claim ? '' : $claim . ' (fake)',
				'ua'     => substr( (string) $p['ua'], 0, 120 ),
				'paths'  => $evidence[ $ip ] ?? [],
			];
		}
		$sum['trip_addresses'] = count( array_unique( array_column( $sum['trips'], 'ip' ) ) );
		$sum['saved']          = $sum['probes'] + $sum['banned_hits'];
		return $sum;
	}

	/**
	 * The last few paths an address asked for (evidence).
	 *
	 * @param array  $evidence
	 * @param string $ip
	 * @param string $target
	 */
	private static function note( array &$evidence, $ip, $target ) {
		$evidence[ $ip ][] = substr( (string) strtok( (string) $target, '?' ), 0, 120 );
		if ( count( $evidence[ $ip ] ) > 5 ) {
			array_shift( $evidence[ $ip ] );
		}
	}

	/**
	 * Parsed lines of every file merged in time order (each file is in order
	 * itself). Yields null for a line that does not parse.
	 *
	 * @param string[] $files
	 * @return Generator
	 */
	public static function lines( array $files ) {
		$hs  = [];
		$cur = [];
		foreach ( array_values( $files ) as $i => $f ) {
			$h = @fopen( '.gz' === substr( $f, -3 ) ? 'compress.zlib://' . $f : $f, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			if ( false !== $h ) {
				$hs[ $i ] = $h;
			}
		}
		$next = function ( $i ) use ( &$hs, &$cur ) {
			while ( isset( $hs[ $i ] ) ) {
				$line = fgets( $hs[ $i ] );
				if ( false === $line ) {
					fclose( $hs[ $i ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					unset( $hs[ $i ], $cur[ $i ] );
					return;
				}
				if ( '' === trim( $line ) ) {
					continue;
				}
				$cur[ $i ] = BotStormRadar_Log_Line::parse( $line );
				return;
			}
		};
		foreach ( array_keys( $hs ) as $i ) {
			$next( $i );
		}
		while ( ! empty( $cur ) ) {
			// Unparsable lines first, then the earliest timestamp.
			$pick = null;
			foreach ( $cur as $i => $p ) {
				if ( null === $p ) {
					$pick = $i;
					break;
				}
				if ( null === $pick || $p['ts'] < $cur[ $pick ]['ts'] ) {
					$pick = $i;
				}
			}
			yield $cur[ $pick ];
			$next( $pick );
		}
	}
}
