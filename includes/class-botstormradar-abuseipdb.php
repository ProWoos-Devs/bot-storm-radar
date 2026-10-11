<?php
/**
 * AbuseIPDB reports for probe bans (design v2, section 14; issue #76).
 *
 * Adapted from WC Antifraud's WCAF_AbuseIPDB. GitHub build only: the
 * wordpress.org build (build-zip.sh --wporg) removes this file and every
 * block that loads it.
 *
 * What is reported, and what never is:
 *   - only addresses that tripped on scanner probes (reason `probe`), with
 *     at least one probed path in their evidence;
 *   - never 404 trips, swarm members or anything from `replay` (none of them
 *     reach the bans table or the would-be list as probes), and never an
 *     address BotStormRadar_Guard::may_ban() refuses or that is not public.
 *
 * Modes (`abuseipdb_mode`): off; manual, a "Report" button with a preview of
 * the exact comment on the Bans tab, for bans and would-be bans; automatic,
 * which also reports new probe bans written in enforce mode, from cron.
 * Without a saved API key the mode is off whatever is stored.
 *
 * Limits: one report per address per REPORT_GAP, never two for the same ban
 * (the `abuseipdb` row in the exports table), and at most the owner's daily
 * cap per UTC day; automatic reports beyond it wait for the next day.
 *
 * Reports are public, so the comment is built from the bounded evidence and
 * nothing else: the probed paths without query strings, the count, the UTC
 * window and the user agent. No cookie, user, e-mail address or site name.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_AbuseIPDB {

	/**
	 * AbuseIPDB v2 report endpoint.
	 */
	const API_URL = 'https://api.abuseipdb.com/api/v2/report';

	/**
	 * Category 21 is "Web App Attack"; 19, "Bad Web Bot", is added when the
	 * user agent claimed a search engine that its DNS did not confirm.
	 */
	const CATEGORY_PROBE = '21';
	const CATEGORY_FAKE  = '19';

	/**
	 * Layer name in the exports table.
	 */
	const LAYER = 'abuseipdb';

	/**
	 * Reports made, per address: t, ok, score or error, by (manual|auto).
	 */
	const LOG_OPTION = 'botstormradar_abuseipdb_log';
	const LOG_CAP    = 500;
	const LOG_KEEP   = 7 * DAY_IN_SECONDS;

	/**
	 * One report per address per day.
	 */
	const REPORT_GAP = DAY_IN_SECONDS;

	/**
	 * The automatic run: probe bans written in the last AUTO_WINDOW, at most
	 * AUTO_BATCH per run, failed reports retried up to AUTO_ATTEMPTS times.
	 */
	const HOOK          = 'botstormradar_abuseipdb_run';
	const AUTO_WINDOW   = 2 * DAY_IN_SECONDS;
	const AUTO_BATCH    = 10;
	const AUTO_ATTEMPTS = 3;

	const PATHS        = 5;
	const COMMENT_MAX  = 1000;
	const DEFAULT_CAP  = 50;
	const MAX_CAP      = 1000;

	public static function init() {
		add_action( 'botstormradar_bans_changed', [ __CLASS__, 'schedule' ], 10, 0 );
		add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 0 );
	}

	// ── Settings ──────────────────────────────────────────────────────

	/**
	 * off | manual | automatic; off without a saved key.
	 *
	 * @return string
	 */
	public static function mode() {
		if ( '' === self::key() ) {
			return 'off';
		}
		$m = (string) BotStormRadar_Helpers::opt( 'abuseipdb_mode', 'manual' );
		return in_array( $m, [ 'off', 'manual', 'automatic' ], true ) ? $m : 'manual';
	}

	/**
	 * @return string
	 */
	private static function key() {
		return trim( (string) BotStormRadar_Helpers::opt( 'abuseipdb_key', '' ) );
	}

	/**
	 * @return int
	 */
	public static function daily_cap() {
		return (int) BotStormRadar_Helpers::clamp( (int) BotStormRadar_Helpers::opt( 'abuseipdb_daily_cap', self::DEFAULT_CAP ), 1, self::MAX_CAP );
	}

	/**
	 * The settings section, on the plugin's settings page.
	 *
	 * @param string $page
	 */
	public static function register_settings( $page ) {
		$name = Bot_Storm_Radar::OPTION_KEY;
		add_settings_section( 'botstormradar_abuseipdb', __( 'AbuseIPDB reports', 'bot-storm-radar' ), function () {
			echo '<p>' . esc_html__( 'Report addresses that probed this site for scanner files to AbuseIPDB, a public database other site owners and firewalls read. Only probe trips are reported, never missing-page trips or swarm members, and never an address that is protected from bans. The report names the probed paths without query strings, how many there were, when, and the user agent; nothing about your site or its visitors.', 'bot-storm-radar' ) . '</p>';
		}, $page );
		add_settings_field( 'abuseipdb_key', __( 'API key', 'bot-storm-radar' ), function () use ( $name ) {
			$key = self::key();
			printf(
				'<input type="password" class="regular-text" autocomplete="off" name="%1$s[abuseipdb_key]" value="" placeholder="%2$s" />',
				esc_attr( $name ),
				esc_attr( '' === $key ? __( 'not set', 'bot-storm-radar' ) : sprintf( /* translators: %s: last four characters of the saved key */ __( 'saved, ends in %s', 'bot-storm-radar' ), substr( $key, -4 ) ) )
			);
			if ( '' !== $key ) {
				printf( ' <label><input type="checkbox" name="%1$s[abuseipdb_key_remove]" value="1" /> %2$s</label>', esc_attr( $name ), esc_html__( 'Remove the saved key', 'bot-storm-radar' ) );
			}
			echo '<p class="description">' . esc_html__( 'From your AbuseIPDB account, under API. Leave the field empty to keep the saved key. Without a key nothing is reported.', 'bot-storm-radar' ) . '</p>';
		}, $page, 'botstormradar_abuseipdb' );
		add_settings_field( 'abuseipdb_mode', __( 'Reporting', 'bot-storm-radar' ), function () use ( $name ) {
			$v = (string) BotStormRadar_Helpers::opt( 'abuseipdb_mode', 'manual' );
			$labels = [
				'off'       => __( 'Off', 'bot-storm-radar' ),
				'manual'    => __( 'By hand: a Report button on the Bans tab, with a preview of the report', 'bot-storm-radar' ),
				'automatic' => __( 'Automatic: also report every new probe ban (enforce mode only)', 'bot-storm-radar' ),
			];
			foreach ( $labels as $k => $label ) {
				printf( '<label><input type="radio" name="%1$s[abuseipdb_mode]" value="%2$s" %3$s /> %4$s</label><br />', esc_attr( $name ), esc_attr( $k ), checked( $k, $v, false ), esc_html( $label ) );
			}
		}, $page, 'botstormradar_abuseipdb' );
		add_settings_field( 'abuseipdb_daily_cap', __( 'Reports per day', 'bot-storm-radar' ), function () use ( $name ) {
			printf( '<input type="number" class="small-text" name="%1$s[abuseipdb_daily_cap]" value="%2$d" min="1" max="%3$d" step="1" />', esc_attr( $name ), (int) self::daily_cap(), (int) self::MAX_CAP );
			/* translators: %d: reports made today */
			echo '<p class="description">' . esc_html( sprintf( __( 'At most this many per UTC day; keep it below your AbuseIPDB plan\'s limit. Automatic reports beyond it wait for the next day. %d made today.', 'bot-storm-radar' ), self::sent_today() ) ) . '</p>';
		}, $page, 'botstormradar_abuseipdb' );
	}

	/**
	 * The settings of this section, from the submitted form into $out.
	 *
	 * @param array $input
	 * @param array $out
	 * @return array
	 */
	public static function sanitize( array $input, array $out ) {
		if ( isset( $input['abuseipdb_mode'] ) ) {
			$m                     = sanitize_key( (string) $input['abuseipdb_mode'] );
			$out['abuseipdb_mode'] = in_array( $m, [ 'off', 'manual', 'automatic' ], true ) ? $m : 'manual';
		}
		if ( isset( $input['abuseipdb_daily_cap'] ) ) {
			$out['abuseipdb_daily_cap'] = (int) BotStormRadar_Helpers::clamp( (int) $input['abuseipdb_daily_cap'], 1, self::MAX_CAP );
		}
		if ( ! empty( $input['abuseipdb_key_remove'] ) ) {
			$out['abuseipdb_key'] = '';
		} elseif ( isset( $input['abuseipdb_key'] ) ) {
			// AbuseIPDB keys are hex; anything else is dropped from what was typed.
			$k = preg_replace( '/[^A-Za-z0-9]/', '', (string) $input['abuseipdb_key'] );
			if ( '' !== $k ) {
				$out['abuseipdb_key'] = substr( $k, 0, 200 );
			}
		}
		return $out;
	}

	// ── What gets reported ───────────────────────────────────────────

	/**
	 * A report candidate from a ban row (BotStormRadar_Bans::listing/get).
	 *
	 * @param array $row
	 * @return array ip, reason, evidence, first, last, ban_id
	 */
	public static function from_ban( array $row ) {
		return [
			'ip'       => (string) $row['ip_text'],
			'single'   => (int) $row['prefix_len'] === ( false !== strpos( (string) $row['ip_text'], ':' ) ? 128 : 32 ),
			'reason'   => (string) $row['reason'],
			'evidence' => is_array( $row['evidence'] ) ? $row['evidence'] : [],
			'first'    => (int) $row['created_at'],
			'last'     => max( (int) $row['created_at'], (int) $row['updated_at'] ),
			'ban_id'   => (int) $row['id'],
		];
	}

	/**
	 * A report candidate from a would-be ban (BotStormRadar_Bans::would_bans).
	 *
	 * @param string $ip
	 * @param array  $w
	 * @return array
	 */
	public static function from_would( $ip, array $w ) {
		return [
			'ip'       => (string) $ip,
			'single'   => true,
			'reason'   => (string) ( $w['reason'] ?? '' ),
			'evidence' => is_array( $w['evidence'] ?? null ) ? $w['evidence'] : [],
			'first'    => (int) ( $w['first'] ?? 0 ),
			'last'     => (int) ( $w['last'] ?? 0 ),
			'ban_id'   => 0,
		];
	}

	/**
	 * Why a candidate cannot be reported now, or '' when it can. Does not
	 * look at the daily cap (see sent_today()).
	 *
	 * @param array    $c
	 * @param int|null $now
	 * @return string not-probe | no-paths | protected | reported | sent
	 */
	public static function refusal( array $c, $now = null ) {
		$now = null === $now ? time() : (int) $now;
		$why = self::reportable( $c );
		if ( '' !== $why ) {
			return $why;
		}
		if ( $c['ban_id'] > 0 && 'sent' === self::export_status( $c['ban_id'] ) ) {
			return 'sent';
		}
		$last = self::log()[ $c['ip'] ] ?? null;
		if ( is_array( $last ) && ! empty( $last['ok'] ) && (int) $last['t'] > $now - self::REPORT_GAP ) {
			return 'reported';
		}
		return '';
	}

	/**
	 * Why a candidate is not reportable at all, or '' when it is: a probe
	 * trip of one public address that may be banned, with probed paths.
	 * Shared with the provider report.
	 *
	 * @param array $c
	 * @return string not-probe | protected | no-paths
	 */
	public static function reportable( array $c ) {
		if ( 'probe' !== $c['reason'] ) {
			return 'not-probe';
		}
		if ( empty( $c['single'] ) || ! BotStormRadar_Helpers::is_public_ip( $c['ip'] ) || ! BotStormRadar_Guard::may_ban( $c['ip'] ) ) {
			return 'protected';
		}
		if ( empty( self::paths( $c['evidence'] ) ) ) {
			return 'no-paths';
		}
		return '';
	}

	/**
	 * The categories for a candidate.
	 *
	 * @param array $c
	 * @return string
	 */
	public static function categories( array $c ) {
		$claims = (string) ( $c['evidence']['claims'] ?? '' );
		return false !== strpos( $claims, '(fake)' ) ? self::CATEGORY_PROBE . ',' . self::CATEGORY_FAKE : self::CATEGORY_PROBE;
	}

	/**
	 * The exact comment sent with the report.
	 *
	 * @param array $c
	 * @return string
	 */
	public static function comment( array $c ) {
		$ev    = $c['evidence'];
		$parts = [ 'Requested paths that vulnerability scanners look for (exposed configuration, version control, backups, other applications\' admin pages, PHP files that do not exist): ' . implode( ', ', self::paths( $ev ) ) . '.' ];
		$count = self::probe_count( $ev );
		$first = (int) $c['first'];
		$last  = max( $first, (int) $c['last'] );
		if ( $first > 0 ) {
			$when = gmdate( 'Y-m-d H:i', $first ) . ( gmdate( 'Y-m-d H:i', $last ) !== gmdate( 'Y-m-d H:i', $first ) ? ' to ' . ( gmdate( 'Y-m-d', $last ) === gmdate( 'Y-m-d', $first ) ? gmdate( 'H:i', $last ) : gmdate( 'Y-m-d H:i', $last ) ) : '' ) . ' UTC';
			$parts[] = ( $count > 0 ? sprintf( '%d probe %s, ', $count, 1 === $count ? 'request' : 'requests' ) : '' ) . $when . '.';
		}
		$uas = self::user_agents( $ev );
		if ( $uas ) {
			$parts[] = ( 1 === count( $uas ) ? 'User agent: ' : 'User agents: ' ) . implode( ' | ', $uas ) . '.';
		}
		$fake = self::fake_claim( $ev );
		if ( '' !== $fake ) {
			$parts[] = 'Claimed to be ' . $fake . '; reverse and forward DNS did not confirm it.';
		}
		$parts[] = 'Reported by Bot Storm Radar.';
		return mb_substr( implode( ' ', $parts ), 0, self::COMMENT_MAX );
	}

	/**
	 * Up to PATHS probed paths, newest last, query strings cut, anything that
	 * looks like an e-mail address masked.
	 *
	 * @param array $ev
	 * @return string[]
	 */
	public static function paths( array $ev ) {
		$out = [];
		foreach ( (array) ( $ev['paths'] ?? [] ) as $p ) {
			$p = self::clean( preg_split( '/[?#]/', (string) $p, 2 )[0] );
			if ( '' !== $p && ! in_array( $p, $out, true ) ) {
				$out[] = $p;
			}
		}
		return array_slice( $out, -self::PATHS );
	}

	/**
	 * The last two user agents in the evidence, cleaned.
	 *
	 * @param array $ev
	 * @return string[]
	 */
	public static function user_agents( array $ev ) {
		return array_slice( array_values( array_filter( array_map( [ __CLASS__, 'clean' ], (array) ( $ev['user_agents'] ?? [] ) ) ) ), -2 );
	}

	/**
	 * The search engine the address falsely claimed to be, or ''.
	 *
	 * @param array $ev
	 * @return string
	 */
	public static function fake_claim( array $ev ) {
		$claims = (string) ( $ev['claims'] ?? '' );
		if ( false === strpos( $claims, '(fake)' ) ) {
			return '';
		}
		$name = trim( str_replace( '(fake)', '', $claims ) );
		return self::clean( (string) ( BotStormRadar_Good_Bots::bots()[ $name ]['label'] ?? $name ) );
	}

	/**
	 * Probes counted in the evidence: the radar's window count, or the
	 * gate's per-class counts added up.
	 *
	 * @param array $ev
	 * @return int
	 */
	public static function probe_count( array $ev ) {
		if ( isset( $ev['window_probes'] ) ) {
			return (int) $ev['window_probes'];
		}
		$n = 0;
		foreach ( $ev as $k => $v ) {
			if ( 0 === strpos( (string) $k, 'probes_' ) && is_numeric( $v ) ) {
				$n += (int) $v;
			}
		}
		return $n;
	}

	/**
	 * Printable ASCII only, e-mail addresses masked, 200 characters.
	 *
	 * @param string $s
	 * @return string
	 */
	public static function clean( $s ) {
		$s = preg_replace( '/[^\x20-\x7e]/', '?', (string) $s );
		$s = preg_replace( '/[^\s\/@]+@[^\s\/@]+\.[a-z]{2,}/i', '[e-mail]', $s );
		return substr( trim( $s ), 0, 200 );
	}

	// ── Reporting ────────────────────────────────────────────────────

	/**
	 * Report one candidate now. Returns ok, plus score or error.
	 *
	 * @param array    $c
	 * @param string   $by  manual | auto
	 * @param int|null $now
	 * @return array ok, score, error
	 */
	public static function report( array $c, $by = 'manual', $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( 'off' === self::mode() ) {
			return [ 'ok' => false, 'error' => 'off' ];
		}
		$why = self::refusal( $c, $now );
		if ( '' !== $why ) {
			return [ 'ok' => false, 'error' => $why ];
		}
		if ( self::sent_today( $now ) >= self::daily_cap() ) {
			return [ 'ok' => false, 'error' => 'cap' ];
		}
		$response = wp_remote_post(
			self::API_URL,
			[
				'timeout' => 10,
				'headers' => [
					'Key'        => self::key(),
					'Accept'     => 'application/json',
					'User-Agent' => 'BotStormRadar/' . BOTSTORMRADAR_VERSION,
				],
				'body'    => [
					'ip'         => $c['ip'],
					'categories' => self::categories( $c ),
					'comment'    => self::comment( $c ),
					'timestamp'  => gmdate( 'c', max( 1, (int) $c['first'] ) ),
				],
			]
		);
		if ( is_wp_error( $response ) ) {
			$res = [ 'ok' => false, 'error' => $response->get_error_message() ];
		} else {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( 200 === $code ) {
				$res = [ 'ok' => true, 'score' => isset( $body['data']['abuseConfidenceScore'] ) ? (int) $body['data']['abuseConfidenceScore'] : null ];
			} else {
				$detail = isset( $body['errors'][0]['detail'] ) ? (string) $body['errors'][0]['detail'] : 'HTTP ' . $code;
				$res    = [ 'ok' => false, 'error' => 'HTTP ' . $code . ': ' . $detail ];
			}
		}
		$res['error'] = isset( $res['error'] ) ? substr( sanitize_text_field( $res['error'] ), 0, 200 ) : '';
		self::record( $c, $res, $by, $now );
		return $res;
	}

	/**
	 * Keep the outcome: the per-address log, and the ban's exports row.
	 *
	 * @param array  $c
	 * @param array  $res
	 * @param string $by
	 * @param int    $now
	 */
	private static function record( array $c, array $res, $by, $now ) {
		$log = self::log();
		unset( $log[ $c['ip'] ] );
		$log = [ $c['ip'] => [ 't' => (int) $now, 'ok' => ! empty( $res['ok'] ), 'score' => $res['score'] ?? null, 'error' => (string) $res['error'], 'by' => 'auto' === $by ? 'auto' : 'manual' ] ] + $log;
		$log = array_filter( $log, function ( $e ) use ( $now ) {
			return (int) $e['t'] > $now - self::LOG_KEEP;
		} );
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_CAP, true ), false );
		if ( $c['ban_id'] > 0 ) {
			global $wpdb;
			$table = BotStormRadar_Bans::exports_table();
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
					"INSERT INTO {$table} (ban_id, layer, remote_id, status, attempts, last_error, updated_at) VALUES (%d, %s, %s, %s, 1, %s, %d)
					ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), status = VALUES(status), attempts = attempts + 1, last_error = VALUES(last_error), updated_at = VALUES(updated_at)",
					$c['ban_id'],
					self::LAYER,
					null === ( $res['score'] ?? null ) ? '' : 'score ' . (int) $res['score'],
					empty( $res['ok'] ) ? 'error' : 'sent',
					(string) $res['error'],
					(int) $now
				)
			);
		}
	}

	/**
	 * @return array ip => t, ok, score, error, by (newest first)
	 */
	public static function log() {
		$l = get_option( self::LOG_OPTION, [] );
		return is_array( $l ) ? $l : [];
	}

	/**
	 * Reports accepted since the start of the UTC day.
	 *
	 * @param int|null $now
	 * @return int
	 */
	public static function sent_today( $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$start = $now - $now % DAY_IN_SECONDS;
		$n     = 0;
		foreach ( self::log() as $e ) {
			if ( ! empty( $e['ok'] ) && (int) $e['t'] >= $start ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * The exports row status of a ban on this layer, or ''.
	 *
	 * @param int $ban_id
	 * @return string
	 */
	public static function export_status( $ban_id ) {
		global $wpdb;
		$table = BotStormRadar_Bans::exports_table();
		return (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT status FROM {$table} WHERE ban_id = %d AND layer = %s", (int) $ban_id, self::LAYER ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		);
	}

	// ── Automatic ────────────────────────────────────────────────────

	/**
	 * After a ban change: queue a run in a minute (automatic mode only).
	 */
	public static function schedule() {
		if ( 'automatic' === self::mode() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::HOOK );
		}
	}

	/**
	 * Report probe bans written in the last AUTO_WINDOW that have no report
	 * yet, up to AUTO_BATCH and the daily cap. Runs again in a minute while
	 * more wait, or after the next UTC midnight when the cap was reached.
	 * Returns the number reported.
	 *
	 * @param int|null $now
	 * @return int
	 */
	public static function run( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		if ( 'automatic' !== self::mode() || 'enforce' !== BotStormRadar_Trips::settings()['mode'] ) {
			return 0;
		}
		global $wpdb;
		$bans    = BotStormRadar_Bans::table();
		$exports = BotStormRadar_Bans::exports_table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT b.id, b.ip_text, b.prefix_len, b.reason, b.evidence, b.created_at, b.updated_at FROM {$bans} b
				LEFT JOIN {$exports} e ON e.ban_id = b.id AND e.layer = %s
				WHERE b.reason = 'probe' AND b.unbanned_at = 0 AND b.created_at > %d AND ( e.id IS NULL OR ( e.status = 'error' AND e.attempts < %d AND e.updated_at < %d ) )
				ORDER BY b.created_at ASC LIMIT %d",
				self::LAYER,
				$now - self::AUTO_WINDOW,
				self::AUTO_ATTEMPTS,
				$now - 15 * MINUTE_IN_SECONDS,
				self::AUTO_BATCH + 1
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = is_array( $rows ) ? $rows : [];
		$done = 0;
		$more = count( $rows ) > self::AUTO_BATCH;
		foreach ( array_slice( $rows, 0, self::AUTO_BATCH ) as $r ) {
			$r['evidence'] = json_decode( (string) $r['evidence'], true );
			$c             = self::from_ban( $r );
			if ( '' !== self::refusal( $c, $now ) ) {
				continue;
			}
			if ( self::sent_today( $now ) >= self::daily_cap() ) {
				wp_schedule_single_event( $now - $now % DAY_IN_SECONDS + DAY_IN_SECONDS + 60, self::HOOK );
				return $done;
			}
			if ( ! empty( self::report( $c, 'auto', $now )['ok'] ) ) {
				$done++;
			}
		}
		if ( $more && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $now + 60, self::HOOK );
		}
		return $done;
	}

	// ── Bans tab ─────────────────────────────────────────────────────

	/**
	 * The report cell of a ban or would-be ban row: what was reported, or a
	 * preview of the comment with the Report button.
	 *
	 * @param array  $c
	 * @param string $kind ban | would
	 */
	public static function render_cell( array $c, $kind ) {
		if ( 'off' === self::mode() || 'probe' !== $c['reason'] ) {
			return;
		}
		$last = self::log()[ $c['ip'] ] ?? null;
		if ( is_array( $last ) ) {
			$text = ! empty( $last['ok'] )
				/* translators: 1: relative time, 2: AbuseIPDB confidence score in percent */
				? sprintf( __( 'Reported to AbuseIPDB %1$s, confidence %2$s%%.', 'bot-storm-radar' ), self::ago( (int) $last['t'] ), null === $last['score'] ? '?' : (int) $last['score'] )
				/* translators: 1: relative time, 2: error message */
				: sprintf( __( 'AbuseIPDB report failed %1$s: %2$s', 'bot-storm-radar' ), self::ago( (int) $last['t'] ), $last['error'] );
			echo '<small>' . esc_html( $text ) . '</small>';
		}
		$why = self::refusal( $c );
		if ( '' !== $why ) {
			if ( 'no-paths' === $why ) {
				echo '<small>' . esc_html__( 'No probed paths recorded, nothing to report.', 'bot-storm-radar' ) . '</small>';
			}
			return;
		}
		?>
		<details class="bsr-report">
			<summary><?php esc_html_e( 'Report to AbuseIPDB', 'bot-storm-radar' ); ?></summary>
			<p><small>
				<?php
				/* translators: %s: AbuseIPDB category numbers */
				echo esc_html( sprintf( __( 'Categories %s. Comment, public on AbuseIPDB:', 'bot-storm-radar' ), self::categories( $c ) ) );
				?>
			</small></p>
			<pre class="bsr-report-text"><?php echo esc_html( self::comment( $c ) ); ?></pre>
			<a class="button button-small" data-botstormradar-confirm="<?php esc_attr_e( 'Send this report to AbuseIPDB? Reports are public and cannot be taken back from here.', 'bot-storm-radar' ); ?>" href="<?php echo esc_url( BotStormRadar_Admin::action_url( 'abuseipdb_report', [ 'ip' => $c['ip'], 'kind' => $kind ] ) ); ?>"><?php esc_html_e( 'Send this report', 'bot-storm-radar' ); ?></a>
		</details>
		<?php
	}

	/**
	 * The admin action: report the address's current ban or would-be ban.
	 * Returns the notice key.
	 *
	 * @param string $ip
	 * @param string $kind
	 * @return string
	 */
	public static function handle_action( $ip, $kind ) {
		$c = null;
		if ( 'would' === $kind ) {
			$w = BotStormRadar_Bans::would_bans()[ $ip ] ?? null;
			$c = is_array( $w ) ? self::from_would( $ip, $w ) : null;
		} else {
			$row = BotStormRadar_Bans::get( $ip );
			$c   = is_array( $row ) ? self::from_ban( $row ) : null;
		}
		if ( null === $c ) {
			return 'abuseipdb_none';
		}
		$res = self::report( $c, 'manual' );
		if ( ! empty( $res['ok'] ) ) {
			return 'abuseipdb_sent';
		}
		$why = [
			'off'       => __( 'reporting is off or no API key is saved', 'bot-storm-radar' ),
			'not-probe' => __( 'only probe trips are reported', 'bot-storm-radar' ),
			'protected' => __( 'the address is protected from bans or not public', 'bot-storm-radar' ),
			'no-paths'  => __( 'no probed paths were recorded', 'bot-storm-radar' ),
			'sent'      => __( 'this ban was already reported', 'bot-storm-radar' ),
			'reported'  => __( 'the address was reported in the last 24 hours', 'bot-storm-radar' ),
			'cap'       => __( 'today\'s report limit is reached', 'bot-storm-radar' ),
		];
		set_transient( 'botstormradar_abuseipdb_error', $why[ $res['error'] ] ?? (string) $res['error'], 300 );
		return 'abuseipdb_failed';
	}

	/**
	 * @param int $ts
	 * @return string
	 */
	private static function ago( $ts ) {
		/* translators: %s: human-readable time difference */
		return sprintf( __( '%s ago', 'bot-storm-radar' ), human_time_diff( $ts ) );
	}
}
