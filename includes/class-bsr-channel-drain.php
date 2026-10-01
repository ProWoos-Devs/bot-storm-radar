<?php
/**
 * Moves the gate channel's closed minutes into the radar's counters. Run by
 * the tick before it computes its minutes, so each row includes what the
 * gate refused in it.
 *
 * Per minute, into whatever backend the radar uses (the one explicit bridge
 * when the gate writes APCu and the radar uses Redis):
 *   m:<minute>:gate_probe          probes the gate refused
 *   m:<minute>:gate_ban            requests from banned addresses it refused
 *   m:<minute>:gprobe:<ip>         probes per address (for the trips)
 *   m:<minute>:gprobe_ips          registry of those addresses
 *   m:<minute>:gprobe_c:<class>    probes per class
 * Like the requests a web server refuses on a log source, these never count
 * toward the addresses, the volume or the score: a refused request cost the
 * site almost nothing and must not define "normal".
 *
 * An APCu channel is invisible to the command line, so a CLI tick drains only
 * the spool and leaves APCu to the next tick that runs inside PHP-FPM. A
 * minute whose row was already computed when its events arrive is counted as
 * late in the status, not added to a row that is already stored.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Channel_Drain {

	const STATUS_OPTION = 'bsr_channel_status';
	const REGISTRY_CAP  = 200;

	/**
	 * Drain every closed minute. Returns the per-minute totals drained.
	 *
	 * @param int|null $now
	 * @param int|null $computed_up_to Last minute whose row is already stored.
	 * @return array minute => [probe, ban]
	 */
	public static function run( $now = null, $computed_up_to = null ) {
		$now = null === $now ? time() : (int) $now;
		$dir = BSR_State::dir();
		if ( '' === $dir ) {
			return [];
		}
		$st   = self::status();
		$lost = BSR_Channel::sweep_interrupted( $dir, 600, $now );
		$out  = [];
		foreach ( BSR_Channel::closed_minutes( $dir, $now ) as $m ) {
			$full   = false;
			$events = BSR_Channel::take( $dir, $m, $full );
			if ( $full ) {
				$st['full']++;
			}
			if ( empty( $events ) ) {
				continue;
			}
			$tot = [ 'probe' => 0, 'ban' => 0 ];
			foreach ( $events as $line => $n ) {
				list( $kind, $detail, $ip ) = array_pad( explode( "\t", $line ), 3, '' );
				if ( isset( $tot[ $kind ] ) ) {
					$tot[ $kind ] += (int) $n;
				} elseif ( 'trip' === $kind || 'wouldban' === $kind || 'claimtrip' === $kind ) {
					// Bans are never lost to a late row: handled whatever the minute.
					self::trip_event( $kind, $detail, $ip, $m, $events, $st );
				}
			}
			if ( null !== $computed_up_to && $m <= (int) $computed_up_to ) {
				$st['late'] += $tot['probe'] + $tot['ban'];
				continue;
			}
			$p = 'm:' . $m . ':';
			foreach ( $events as $line => $n ) {
				list( $kind, $detail, $ip ) = array_pad( explode( "\t", $line ), 3, '' );
				$n = (int) $n;
				if ( 'probe' === $kind ) {
					BSR_Counters::incr( $p . 'gate_probe', BSR_Counters::TTL_MINUTE, $n );
					if ( '' !== $detail ) {
						BSR_Counters::incr( $p . 'gprobe_c:' . $detail, BSR_Counters::TTL_MINUTE, $n );
					}
					if ( '' !== $ip ) {
						BSR_Counters::incr( $p . 'gprobe:' . $ip, BSR_Counters::TTL_MINUTE, $n );
						BSR_Counters::registry_add( $p . 'gprobe_ips', $ip, 1, self::REGISTRY_CAP );
					}
				} elseif ( 'ban' === $kind ) {
					BSR_Counters::incr( $p . 'gate_ban', BSR_Counters::TTL_MINUTE, $n );
				}
			}
			if ( ! BSR_Channel::apcu() ) {
				self::radar_probe_trips( $m, $events, $st );
			}
			$st['minutes']++;
			$st['events'] += $tot['probe'] + $tot['ban'];
			$out[ $m ]     = $tot;
		}
		BSR_Counters::flush();
		$st['lost']      += count( $lost );
		$st['last_drain'] = $now;
		$st['channel']    = BSR_Channel::apcu() ? 'apcu' : ( 'cli' === PHP_SAPI ? 'spool (APCu not visible from the command line)' : 'spool' );
		update_option( self::STATUS_OPTION, $st, false );
		return $out;
	}

	/**
	 * A gate-side trip. `trip` (enforce mode) becomes a ban row through the
	 * guard unless an administrative change voided it: the generation moved
	 * on since the gate decided, or the address was unbanned after that.
	 * `wouldban` (observe mode) is recorded for the Bans tab.
	 *
	 * @param string $kind
	 * @param string $detail <reason>-<generation>-<ttl>
	 * @param string $ip
	 * @param int    $minute
	 * @param array  $events The minute's events (evidence).
	 * @param array  $st     Status, updated.
	 */
	private static function trip_event( $kind, $detail, $ip, $minute, array $events, array &$st ) {
		list( $reason, $gen, $ttl, $claim ) = array_pad( explode( '-', (string) $detail ), 4, '' );
		if ( '' === $ip || '' === $reason ) {
			return;
		}
		$evidence = [ 'by' => 'gate', 'minute' => gmdate( 'Y-m-d H:i', $minute * 60 ) . ' UTC' ];
		foreach ( $events as $line => $n ) {
			list( $k, $class, $addr ) = array_pad( explode( "\t", $line ), 3, '' );
			if ( 'probe' === $k && $addr === $ip ) {
				$evidence[ 'probes_' . $class ] = (int) $n;
			}
		}
		if ( 'wouldban' === $kind ) {
			if ( BSR_Bans::would_ban( $ip, $reason, $evidence, $minute * 60 ) ) {
				$st['wouldban']++;
			} else {
				$st['guarded']++;
			}
			return;
		}
		$overridden = (int) $gen !== BSR_Bans::generation();
		foreach ( BSR_Bans::recent_unbans( $minute * 60 ) as $u ) {
			if ( BSR_IP_Resolver::ip_in_cidr( $ip, $u['ip_text'] . '/' . (int) $u['prefix_len'] ) ) {
				$overridden = true;
			}
		}
		if ( $overridden ) {
			$st['overridden']++;
			return;
		}
		// The one trip path: mode, repeat length, bot claims, guard.
		$r = BSR_Trips::trip( $ip, $reason, $evidence, 'gate', 'claimtrip' === $kind ? $claim : '' ); // The ban starts now, when it is written.
		self::count_result( $r, $st );
	}

	/**
	 * @param string $r  BSR_Trips::trip() result.
	 * @param array  $st Status, updated.
	 */
	private static function count_result( $r, array &$st ) {
		$map = [ 'banned' => 'trips', 'would' => 'wouldban', 'pending' => 'pending', 'guarded' => 'guarded', 'verified-bot' => 'guarded' ];
		$k   = $map[ $r ] ?? 'guarded';
		$st[ $k ] = (int) ( $st[ $k ] ?? 0 ) + 1;
	}

	/**
	 * Radar-side probe trips, for a site whose gate wrote the spool (no APCu,
	 * so the gate could not count). An address trips on the minute its
	 * probes within the window cross the threshold, once per crossing.
	 *
	 * @param int   $minute
	 * @param array $events The minute's events.
	 * @param array $st     Status, updated.
	 */
	private static function radar_probe_trips( $minute, array $events, array &$st ) {
		$s = BSR_Trips::settings()['probe'];
		if ( $s['count'] <= 0 ) {
			return;
		}
		$per = [];
		foreach ( $events as $line => $n ) {
			list( $kind, $class, $ip ) = array_pad( explode( "\t", $line ), 3, '' );
			if ( 'probe' === $kind && '' !== $ip ) {
				$per[ $ip ]['n']                    = ( $per[ $ip ]['n'] ?? 0 ) + (int) $n;
				$per[ $ip ][ 'probes_' . $class ] = ( $per[ $ip ][ 'probes_' . $class ] ?? 0 ) + (int) $n;
			}
		}
		$span = max( 1, intdiv( (int) $s['window'], 60 ) );
		foreach ( $per as $ip => $p ) {
			$before = 0;
			for ( $k = $minute - $span + 1; $k < $minute; $k++ ) {
				$before += BSR_Counters::get( 'm:' . $k . ':gprobe:' . $ip );
			}
			if ( $before < $s['count'] && $before + $p['n'] >= $s['count'] ) {
				$ev = array_diff_key( $p, [ 'n' => 1 ] ) + [ 'by' => 'radar', 'window_probes' => $before + $p['n'], 'minute' => gmdate( 'Y-m-d H:i', $minute * 60 ) . ' UTC' ];
				self::count_result( BSR_Trips::trip( $ip, 'probe', $ev, 'radar' ), $st );
			}
		}
	}

	/**
	 * How long the oldest closed minute has been waiting for a drain, in
	 * seconds (0 when none waits). On a site whose cron only runs on visits
	 * nothing can promise when the next drain comes; the Radar shows this.
	 *
	 * @param int|null $now
	 * @return int
	 */
	public static function oldest_waiting_age( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$dir = BSR_State::dir();
		if ( '' === $dir ) {
			return 0;
		}
		$m = BSR_Channel::closed_minutes( $dir, $now );
		return $m ? max( 0, $now - ( (int) $m[0] + 1 ) * 60 ) : 0;
	}

	/**
	 * @return array last_drain, minutes, events, late, lost, full, channel,
	 *               trips (ban rows written), wouldban, overridden (voided by an
	 *               administrative change), guarded (refused by the guard)
	 */
	public static function status() {
		$s = get_option( self::STATUS_OPTION, [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [
			'last_drain' => 0,
			'minutes'    => 0,
			'events'     => 0,
			'late'       => 0,
			'lost'       => 0,
			'full'       => 0,
			'channel'    => '',
			'trips'      => 0,
			'wouldban'   => 0,
			'overridden' => 0,
			'guarded'    => 0,
			'pending'    => 0,
		] );
	}
}
