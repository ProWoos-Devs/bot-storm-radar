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
	 * @return array last_drain, minutes, events, late, lost, full, channel
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
		] );
	}
}
