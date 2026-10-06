<?php
/**
 * Per-address trips: one address crossing a per-class count in a short
 * window. The single-address case the swarm triggers cannot see (design v2,
 * section 5). Trips never change the storm state and never count toward the
 * score.
 *
 * Where trips are counted:
 *   - probes: by the gate when APCu is enabled (provisional ban at once); by
 *     the channel drain otherwise, from the per-address probe counts, at the
 *     next successful drain;
 *   - page 404s: by the recorder at the end of the request, per address and
 *     minute. Assets never reach the `404` class (a missing image is the
 *     `asset` class), so a page with broken images behind one shared office or
 *     mobile address never trips.
 *
 * Every trip goes through trip(), which decides what happens:
 *   - a protected address (BotStormRadar_Guard) never trips;
 *   - an address whose user agent claims a verified-bot family and has no
 *     verdict yet is held as pending until the tick has verified it, and is
 *     banned only when the verdict is "fake";
 *   - observe mode (the default) records a would-be ban and writes no ban;
 *   - enforce mode writes the ban: base length, or the repeat length when
 *     the address was banned in the last day.
 * No mail per trip; one digest when more than DIGEST_COUNT addresses trip
 * within an hour.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Trips {

	const PENDING_OPTION = 'bsr_pending_trips';
	const PENDING_CAP    = 100;
	const PENDING_MAX    = DAY_IN_SECONDS;
	const DIGEST_OPTION  = 'bsr_trip_digest';
	const DIGEST_COUNT   = 10;
	const DIGEST_WINDOW  = HOUR_IN_SECONDS;

	/**
	 * Trip settings from the options.
	 *
	 * @return array mode, probe{count,window,ttl}, 404{count,ttl}, ttl_repeat
	 */
	public static function settings() {
		$o   = BotStormRadar_Helpers::get_options();
		$ttl = max( 1, (int) ( $o['trip_ban_minutes'] ?? 60 ) ) * MINUTE_IN_SECONDS;
		return [
			'mode'       => 'enforce' === ( $o['ban_mode'] ?? 'observe' ) ? 'enforce' : 'observe',
			'probe'      => [
				'count'  => max( 0, (int) ( $o['trip_probe_count'] ?? 3 ) ),
				'window' => max( 1, (int) ( $o['trip_probe_window_minutes'] ?? 10 ) ) * MINUTE_IN_SECONDS,
				'ttl'    => $ttl,
			],
			'404'        => [
				'count' => max( 0, (int) ( $o['trip_404_count'] ?? 20 ) ),
				'ttl'   => $ttl,
			],
			'ttl_repeat' => max( 1, (int) ( $o['trip_ban_repeat_hours'] ?? 24 ) ) * HOUR_IN_SECONDS,
		];
	}

	/**
	 * What the gate needs (state file `trips`): the probe trip and the bot
	 * families whose claims must not trip a provisional ban.
	 *
	 * @return array
	 */
	public static function state_config() {
		$s      = self::settings();
		$claims = [];
		foreach ( BotStormRadar_Good_Bots::bots() as $name => $bot ) {
			$claims[ $name ] = $bot['pattern'];
		}
		return [
			'probe' => $s['probe']['count'] > 0 ? array_merge( $s['probe'], [ 'claims' => $claims ] ) : [],
		];
	}

	/**
	 * One trip. Returns what happened: banned | would | pending | guarded | verified-bot.
	 *
	 * @param string   $ip
	 * @param string   $reason   probe | 404
	 * @param array    $evidence
	 * @param string   $origin   gate | radar
	 * @param string   $claim    Bot family the user agent claims ('' if none).
	 * @param int|null $now
	 * @return string
	 */
	public static function trip( $ip, $reason, array $evidence, $origin, $claim = '', $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( ! BotStormRadar_Guard::may_ban( $ip ) ) {
			return 'guarded';
		}
		if ( '' !== $claim ) {
			$verdict = BotStormRadar_Good_Bots::verdict( $ip, $claim );
			if ( 'verified' === $verdict ) {
				return 'verified-bot';
			}
			if ( 'pending' === $verdict ) {
				self::hold( $ip, $reason, $evidence + [ 'claims' => $claim ], $origin, $claim, $now );
				return 'pending';
			}
			$evidence['claims'] = $claim . ' (fake)';
		}
		$s = self::settings();
		if ( 'enforce' !== $s['mode'] ) {
			return BotStormRadar_Bans::would_ban( $ip, $reason, $evidence, $now ) ? 'would' : 'guarded';
		}
		$ttl = isset( $s[ $reason ]['ttl'] ) ? (int) $s[ $reason ]['ttl'] : (int) $s['probe']['ttl'];
		$old = BotStormRadar_Bans::get( $ip );
		if ( $old && max( (int) $old['created_at'], (int) $old['updated_at'] ) > $now - DAY_IN_SECONDS ) {
			$ttl = max( $ttl, (int) $s['ttl_repeat'] ); // A repeat within a day.
		}
		if ( BotStormRadar_Bans::trip( $ip, $ttl, $reason, $evidence, $origin, null, $now ) <= 0 ) {
			return 'guarded';
		}
		self::note_for_digest( $ip, $reason, $now );
		return 'banned';
	}

	// ── Pending bot claims ────────────────────────────────────────────

	/**
	 * @param string $ip
	 * @param string $reason
	 * @param array  $evidence
	 * @param string $origin
	 * @param string $claim
	 * @param int    $now
	 */
	private static function hold( $ip, $reason, array $evidence, $origin, $claim, $now ) {
		$list        = self::pending();
		$list[ $ip ] = [ 'reason' => $reason, 'evidence' => BotStormRadar_Bans::bound_evidence( $evidence ), 'origin' => $origin, 'claim' => $claim, 'at' => (int) ( $list[ $ip ]['at'] ?? $now ) ];
		uasort( $list, function ( $a, $b ) {
			return $b['at'] <=> $a['at'];
		} );
		update_option( self::PENDING_OPTION, array_slice( $list, 0, self::PENDING_CAP, true ), false );
	}

	/**
	 * @return array ip => reason, evidence, origin, claim, at
	 */
	public static function pending() {
		$p = get_option( self::PENDING_OPTION, [] );
		return is_array( $p ) ? $p : [];
	}

	/**
	 * After the tick verified the queued bot claims: a verified address is
	 * dropped (it is protected), a fake one trips now, one still pending after
	 * a day is dropped. Returns what was decided per address.
	 *
	 * @param int|null $now
	 * @return array ip => result
	 */
	public static function process_pending( $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$list = self::pending();
		if ( empty( $list ) ) {
			return [];
		}
		$done = [];
		foreach ( $list as $ip => $p ) {
			$cached = BotStormRadar_Counters::get_value( 'g:bot:' . $ip );
			$v      = is_array( $cached ) ? (string) ( $cached['v'] ?? '' ) : '';
			if ( 'verified' === $v ) {
				$done[ $ip ] = 'verified-bot';
			} elseif ( 'fake' === $v ) {
				$ev           = (array) $p['evidence'];
				$ev['claims'] = $p['claim'] . ' (fake)';
				unset( $list[ $ip ] ); // Before trip(), which must not find it pending again.
				update_option( self::PENDING_OPTION, $list, false );
				$done[ $ip ] = self::trip( $ip, (string) $p['reason'], $ev, (string) $p['origin'], '', $now );
				continue;
			} elseif ( (int) $p['at'] < $now - self::PENDING_MAX ) {
				$done[ $ip ] = 'expired';
			} else {
				continue;
			}
			unset( $list[ $ip ] );
		}
		update_option( self::PENDING_OPTION, $list, false );
		return $done;
	}

	// ── Digest ────────────────────────────────────────────────────────

	/**
	 * Count a ban for the hourly digest; mail once when more than
	 * DIGEST_COUNT distinct addresses tripped within the last hour.
	 *
	 * @param string $ip
	 * @param string $reason
	 * @param int    $now
	 * @return bool Whether a digest was sent.
	 */
	public static function note_for_digest( $ip, $reason, $now ) {
		$d       = get_option( self::DIGEST_OPTION, [] );
		$d       = is_array( $d ) ? $d : [];
		$recent  = array_filter( (array) ( $d['recent'] ?? [] ), function ( $r ) use ( $now ) {
			return (int) $r['t'] > $now - self::DIGEST_WINDOW;
		} );
		$recent[ $ip ] = [ 't' => (int) $now, 'reason' => (string) $reason ];
		$d['recent']   = $recent;
		$sent          = false;
		if ( count( $recent ) > self::DIGEST_COUNT && (int) ( $d['mailed'] ?? 0 ) <= $now - self::DIGEST_WINDOW ) {
			$sent        = BotStormRadar_Email_Alerts::send_trip_digest( $recent, $now );
			$d['mailed'] = (int) $now;
		}
		update_option( self::DIGEST_OPTION, $d, false );
		return $sent;
	}
}
