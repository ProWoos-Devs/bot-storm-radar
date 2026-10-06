<?php
/**
 * Plain-text alert emails on storm transitions, in the WC Antifraud style.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Email_Alerts {

	/**
	 * @param array $ctx from, to, at, minute, score, explanation, metrics.
	 * @return bool
	 */
	public static function send_transition( array $ctx ) {
		$source    = (string) ( $ctx['source'] ?? BotStormRadar_Sources::SITE );
		$is_site   = BotStormRadar_Sources::SITE === $source;
		$addresses = BotStormRadar_Sources::alert_recipients( $source );
		if ( empty( $addresses ) ) {
			return false;
		}
		$site  = get_bloginfo( 'name' );
		$label = BotStormRadar_Sources::label( $source );
		$to    = (string) $ctx['to'];
		$from  = (string) $ctx['from'];
		$m     = is_array( $ctx['metrics'] ?? null ) ? $ctx['metrics'] : [];
		$when  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ctx['at'] );
		$radar = admin_url( 'admin.php?page=bot-storm-radar' . ( $is_site ? '' : '&source=' . rawurlencode( $source ) ) );

		$titles = [
			'warning' => __( 'Bot storm warning', 'bot-storm-radar' ),
			'storm'   => __( 'Bot storm detected', 'bot-storm-radar' ),
			'cooling' => __( 'Bot storm cooling down', 'bot-storm-radar' ),
			'calm'    => __( 'Bot storm over', 'bot-storm-radar' ),
		];
		$title   = $titles[ $to ] ?? $to;
		$subject = sprintf( '[%s — Bot Storm Radar] %s', $site, $is_site ? $title : $label . ': ' . $title );

		$body   = [];
		if ( ! empty( $ctx['started_from'] ) ) {
			/* translators: 1: state the minute started in, 2: state passed through, 3: new state, 4: date and time */
			$body[] = sprintf( __( 'Bot Storm Radar moved from %1$s through %2$s to %3$s at %4$s, all within one minute.', 'bot-storm-radar' ), strtoupper( (string) $ctx['started_from'] ), strtoupper( $from ), strtoupper( $to ), $when );
		} else {
			/* translators: 1: previous state, 2: new state, 3: date and time */
			$body[] = sprintf( __( 'Bot Storm Radar moved from %1$s to %2$s at %3$s.', 'bot-storm-radar' ), strtoupper( $from ), strtoupper( $to ), $when );
		}
		if ( ! $is_site ) {
			/* translators: %s: label of the source */
			$body[] = sprintf( __( 'Source %s, read from the web-server access log.', 'bot-storm-radar' ), $label );
		}
		$body[] = __( 'Radar-only release: nothing was blocked, challenged or rate-limited.', 'bot-storm-radar' );
		$body[] = '';
		$body[] = __( 'WHY:', 'bot-storm-radar' );
		$body[] = (string) ( $ctx['explanation'] ?? '' );
		$body[] = '';
		$body[] = __( 'LAST MINUTE:', 'bot-storm-radar' );
		$body[] = sprintf( 'Storm score: %s', $ctx['score'] ?? '-' );
		$body[] = sprintf( 'Requests: %d, distinct addresses: %d, single-hit addresses: %d', (int) ( $m['total'] ?? 0 ), (int) ( $m['ips'] ?? 0 ), (int) ( $m['single'] ?? 0 ) );
		$body[] = sprintf( 'HTML-serving addresses: %d, beacon addresses: %d', (int) ( $m['html_ips'] ?? 0 ), (int) ( $m['beacon_ips'] ?? 0 ) );
		$body[] = sprintf( 'Distinct user agents: %d, networks: %d', (int) ( $m['uas'] ?? 0 ), (int) ( $m['nets'] ?? 0 ) );
		$body[] = sprintf( '5xx responses: %d, slow responses: %d', (int) ( $m['err5'] ?? 0 ), (int) ( $m['slow'] ?? 0 ) );
		if ( (int) ( $m['refused'] ?? 0 ) > 0 ) {
			$body[] = sprintf( 'Refused by the web server (403, 429, 444), not counted: %d', (int) $m['refused'] );
		}
		if ( ! empty( $m['top_class'] ) ) {
			$body[] = sprintf( 'Busiest sensitive endpoint class: %s (%s of requests)', $m['top_class'], self::pct( $m['concentration'] ?? 0 ) );
		}
		if ( ! empty( $m['classes'] ) && is_array( $m['classes'] ) ) {
			arsort( $m['classes'] );
			$parts = [];
			foreach ( array_slice( $m['classes'], 0, 6, true ) as $c => $n ) {
				if ( $n > 0 ) {
					$parts[] = $c . ' ' . (int) $n;
				}
			}
			if ( $parts ) {
				$body[] = 'Classes: ' . implode( ', ', $parts );
			}
		}
		$body[] = '';
		$body[] = __( 'ACTIONS:', 'bot-storm-radar' );
		$body[] = sprintf( 'Radar: %s', $radar );
		if ( $is_site ) {
			$body[] = sprintf( 'Counter backend: %s', BotStormRadar_Counters::backend_label() );
		} else {
			$def    = BotStormRadar_Sources::log_sources()[ $source ] ?? [];
			$body[] = sprintf( 'Log files: %s', implode( ', ', array_map( 'basename', (array) ( $def['logs'] ?? [] ) ) ) );
		}
		return self::deliver( $addresses, $subject, $body );
	}

	/**
	 * One alert for a 5xx burst on a log source (BotStormRadar_Error_Burst). The storm
	 * state is unchanged, so this is not a transition mail.
	 *
	 * @param array $ctx source, minute, at, err5, threshold, score, explanation, metrics.
	 * @return bool
	 */
	public static function send_error_burst( array $ctx ) {
		$source    = (string) ( $ctx['source'] ?? BotStormRadar_Sources::SITE );
		$addresses = BotStormRadar_Sources::alert_recipients( $source );
		if ( empty( $addresses ) ) {
			return false;
		}
		$site  = get_bloginfo( 'name' );
		$label = BotStormRadar_Sources::label( $source );
		$m     = is_array( $ctx['metrics'] ?? null ) ? $ctx['metrics'] : [];
		$when  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ctx['at'] );
		$radar = admin_url( 'admin.php?page=bot-storm-radar&source=' . rawurlencode( $source ) );
		$def   = BotStormRadar_Sources::log_sources()[ $source ] ?? [];

		$subject = sprintf( '[%s — Bot Storm Radar] %s: %s', $site, $label, __( '5xx burst', 'bot-storm-radar' ) );
		$body    = [];
		/* translators: 1: number of 5xx responses, 2: date and time, 3: alert threshold */
		$body[]  = sprintf( __( '%1$d responses with a 5xx status went out in one minute at %2$s (the alert threshold is %3$d).', 'bot-storm-radar' ), (int) ( $ctx['err5'] ?? 0 ), $when, (int) ( $ctx['threshold'] ?? 0 ) );
		/* translators: %s: label of the source */
		$body[]  = sprintf( __( 'Source %s, read from the web-server access log.', 'bot-storm-radar' ), $label );
		/* translators: %s: storm state */
		$body[]  = sprintf( __( 'The storm state is %s and was not changed by this rule. One mail per episode; the episode ends after the configured minutes below the threshold.', 'bot-storm-radar' ), strtoupper( (string) ( $ctx['to'] ?? '' ) ) );
		$body[]  = '';
		$body[]  = __( 'LAST MINUTE:', 'bot-storm-radar' );
		$body[]  = sprintf( 'Requests: %d, distinct addresses: %d, 5xx responses: %d', (int) ( $m['total'] ?? 0 ), (int) ( $m['ips'] ?? 0 ), (int) ( $ctx['err5'] ?? 0 ) );
		$body[]  = sprintf( 'Storm score: %s', $ctx['score'] ?? '-' );
		if ( ! empty( $m['classes'] ) && is_array( $m['classes'] ) ) {
			arsort( $m['classes'] );
			$parts = [];
			foreach ( array_slice( $m['classes'], 0, 6, true ) as $c => $n ) {
				if ( $n > 0 ) {
					$parts[] = $c . ' ' . (int) $n;
				}
			}
			if ( $parts ) {
				$body[] = 'Classes: ' . implode( ', ', $parts );
			}
		}
		$body[] = '';
		$body[] = __( 'ACTIONS:', 'bot-storm-radar' );
		$body[] = sprintf( 'Radar: %s', $radar );
		$body[] = sprintf( 'Log files: %s', implode( ', ', array_map( 'basename', (array) ( $def['logs'] ?? [] ) ) ) );
		return self::deliver( $addresses, $subject, $body );
	}

	/**
	 * One mail when more than BotStormRadar_Trips::DIGEST_COUNT addresses were banned
	 * within an hour (never one mail per trip).
	 *
	 * @param array $recent ip => [t, reason]
	 * @param int   $now
	 * @return bool
	 */
	public static function send_trip_digest( array $recent, $now ) {
		$addresses = BotStormRadar_Sources::alert_recipients( BotStormRadar_Sources::SITE );
		if ( empty( $addresses ) ) {
			return false;
		}
		$site    = get_bloginfo( 'name' );
		/* translators: %d: number of addresses */
		$subject = sprintf( '[%s — Bot Storm Radar] %s', $site, sprintf( __( '%d addresses banned in the last hour', 'bot-storm-radar' ), count( $recent ) ) );
		$by      = [];
		foreach ( $recent as $r ) {
			$by[ $r['reason'] ] = ( $by[ $r['reason'] ] ?? 0 ) + 1;
		}
		$body   = [];
		/* translators: 1: number of addresses, 2: start time, 3: end time */
		$body[] = sprintf( __( '%1$d addresses were banned for a while between %2$s and %3$s, each after crossing a trip on its own (probes for files only scanners ask for, or many missing pages in a minute).', 'bot-storm-radar' ), count( $recent ), wp_date( get_option( 'time_format' ), $now - BotStormRadar_Trips::DIGEST_WINDOW ), wp_date( get_option( 'time_format' ), $now ) );
		$body[] = __( 'This is the only mail for this hour. Nothing else changed; the storm state is separate.', 'bot-storm-radar' );
		$body[] = '';
		$parts = [];
		foreach ( $by as $reason => $n ) {
			$parts[] = $reason . ' ' . (int) $n;
		}
		$body[] = __( 'By reason: ', 'bot-storm-radar' ) . implode( ', ', $parts );
		$body[] = __( 'Latest: ', 'bot-storm-radar' ) . implode( ', ', array_slice( array_keys( $recent ), -10 ) );
		$body[] = '';
		/* translators: %s: URL of the Bans tab */
		$body[] = sprintf( __( 'Bans and Unban: %s', 'bot-storm-radar' ), admin_url( 'admin.php?page=bot-storm-radar&tab=bans' ) );
		return self::deliver( $addresses, $subject, $body );
	}

	/**
	 * Append the footer and send the plain-text mail to every recipient.
	 *
	 * @param array  $addresses
	 * @param string $subject
	 * @param array  $body Lines.
	 * @return bool
	 */
	private static function deliver( array $addresses, $subject, array $body ) {
		$site   = get_bloginfo( 'name' );
		$body[] = '';
		$body[] = '---';
		/* translators: 1: plugin version number, 2: site name */
		$body[] = sprintf( __( 'Generated by Bot Storm Radar %1$s on %2$s', 'bot-storm-radar' ), BOTSTORMRADAR_VERSION, $site );

		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			sprintf( 'From: %s <%s>', $site, get_option( 'admin_email' ) ),
		];

		$sent = true;
		foreach ( $addresses as $addr ) {
			if ( ! wp_mail( $addr, $subject, implode( "\n", $body ), $headers ) ) {
				$sent = false;
			}
		}
		return $sent;
	}

	/**
	 * @param float $v
	 * @return string
	 */
	private static function pct( $v ) {
		return round( (float) $v * 100 ) . '%';
	}
}
