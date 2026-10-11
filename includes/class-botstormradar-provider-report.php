<?php
/**
 * Provider report for probe bans (design v2, section 14; issue #76).
 *
 * GitHub build only: the wordpress.org build (build-zip.sh --wporg) removes
 * this file and every block that loads it.
 *
 * On a probe ban or would-be ban, "Provider report" on the Bans tab looks up
 * the abuse contact of the network the address belongs to with RIPEstat's
 * abuse-contact-finder (it answers for every regional registry, not only
 * RIPE) and shows a plain-text report the owner copies or opens in their own
 * mail app. The plugin never mails a third party.
 *
 * The lookup runs only when the owner asks for it, from the admin action;
 * rendering the Bans tab never calls out. The answer is kept a week, a
 * failure ten minutes.
 *
 * What is reportable is what the AbuseIPDB report allows
 * (BotStormRadar_AbuseIPDB::reportable()): probe trips of one public address
 * that may be banned, with probed paths. The text comes from the same
 * bounded evidence.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Provider_Report {

	/**
	 * RIPEstat abuse-contact-finder data call.
	 */
	const API_URL = 'https://stat.ripe.net/data/abuse-contact-finder/data.json';

	/**
	 * Lookup cache, per address.
	 */
	const CACHE_PREFIX = 'botstormradar_abuse_contact_';
	const CACHE_TTL    = WEEK_IN_SECONDS;
	const FAIL_TTL     = 10 * MINUTE_IN_SECONDS;

	/**
	 * At most this many contacts are kept from an answer.
	 */
	const CONTACTS = 3;

	/**
	 * The query argument that opens the report panel on the Bans tab.
	 */
	const ARG = 'botstormradar_provider';

	// ── Lookup ───────────────────────────────────────────────────────

	/**
	 * The abuse contacts of the network an address belongs to, from the
	 * cache or RIPEstat.
	 *
	 * @param string $ip
	 * @param bool   $fetch false: the cache only, null when nothing is cached.
	 * @return array|null ok, contacts (e-mail addresses), rir, error, t
	 */
	public static function lookup( $ip, $fetch = true ) {
		$key    = self::CACHE_PREFIX . md5( (string) $ip );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( ! $fetch ) {
			return null;
		}
		$response = wp_remote_get(
			add_query_arg(
				[
					'resource'  => rawurlencode( (string) $ip ),
					'sourceapp' => 'bot-storm-radar',
				],
				self::API_URL
			),
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/json',
					'User-Agent' => 'BotStormRadar/' . BOTSTORMRADAR_VERSION,
				],
			]
		);
		$res = self::parse( $response );
		$res['t'] = time();
		set_transient( $key, $res, $res['ok'] ? self::CACHE_TTL : self::FAIL_TTL );
		return $res;
	}

	/**
	 * The lookup result from a RIPEstat response.
	 *
	 * @param array|WP_Error $response
	 * @return array ok, contacts, rir, error
	 */
	public static function parse( $response ) {
		if ( is_wp_error( $response ) ) {
			return [ 'ok' => false, 'contacts' => [], 'rir' => '', 'error' => substr( sanitize_text_field( $response->get_error_message() ), 0, 200 ) ];
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $body ) || 'ok' !== ( $body['status'] ?? '' ) || ! isset( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return [ 'ok' => false, 'contacts' => [], 'rir' => '', 'error' => 'HTTP ' . $code ];
		}
		$contacts = [];
		foreach ( (array) ( $body['data']['abuse_contacts'] ?? [] ) as $email ) {
			$email = sanitize_email( (string) $email );
			if ( '' !== $email && is_email( $email ) && ! in_array( $email, $contacts, true ) ) {
				$contacts[] = $email;
			}
		}
		return [
			'ok'       => true,
			'contacts' => array_slice( $contacts, 0, self::CONTACTS ),
			'rir'      => strtoupper( sanitize_key( (string) ( $body['data']['authoritative_rir'] ?? '' ) ) ),
			'error'    => '',
		];
	}

	// ── The report ───────────────────────────────────────────────────

	/**
	 * @param array $c A candidate (BotStormRadar_AbuseIPDB::from_ban/from_would).
	 * @return string
	 */
	public static function subject( array $c ) {
		return 'Abuse report: web application probing from ' . $c['ip'] . ( (int) $c['first'] > 0 ? ' on ' . gmdate( 'Y-m-d', (int) $c['first'] ) : '' );
	}

	/**
	 * The plain-text body, in English, the language abuse desks read.
	 *
	 * @param array  $c
	 * @param string $sender Signature line.
	 * @return string
	 */
	public static function body( array $c, $sender = '' ) {
		$ev    = $c['evidence'];
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = [ 'Hello,', '' ];

		$lines[] = 'The address ' . $c['ip'] . ' probed a website I operate' . ( '' !== $host ? ' (' . $host . ')' : '' ) . ' for files that vulnerability scanners look for, ' . self::when( (int) $c['first'], (int) $c['last'] ) . '.';
		$lines[] = '';
		$count   = BotStormRadar_AbuseIPDB::probe_count( $ev );
		$lines[] = ( $count > 0 ? sprintf( 'It sent %d such %s. ', $count, 1 === $count ? 'request' : 'requests' ) : '' ) . 'The last paths requested were:';
		foreach ( BotStormRadar_AbuseIPDB::paths( $ev ) as $p ) {
			$lines[] = '  ' . $p;
		}
		$lines[] = '';
		$lines[] = 'These look for exposed configuration, version control, backups, other applications\' admin pages and PHP files that do not exist on the site. None of them is meant to be public.';
		$uas     = BotStormRadar_AbuseIPDB::user_agents( $ev );
		if ( $uas ) {
			$lines[] = '';
			$lines[] = ( 1 === count( $uas ) ? 'User agent: ' : 'User agents: ' ) . '"' . implode( '" and "', $uas ) . '"';
		}
		$fake = BotStormRadar_AbuseIPDB::fake_claim( $ev );
		if ( '' !== $fake ) {
			$lines[] = 'It claimed to be ' . $fake . '; reverse and forward DNS did not confirm it.';
		}
		$lines[] = '';
		$lines[] = 'Times are UTC, from the site\'s own records. I can provide more detail on request. I would appreciate it if you could investigate and stop the activity.';
		$lines[] = '';
		$lines[] = 'Regards,';
		if ( '' !== trim( (string) $sender ) ) {
			$lines[] = trim( (string) $sender );
		}
		return implode( "\n", $lines );
	}

	/**
	 * "on 2026-10-10 at 06:56 UTC", "on 2026-10-10 between 06:56 and 06:57
	 * UTC", or "between 2026-10-09 23:58 and 2026-10-10 00:02 UTC".
	 *
	 * @param int $first
	 * @param int $last
	 * @return string
	 */
	public static function when( $first, $last ) {
		$last = max( $first, $last );
		if ( $first <= 0 ) {
			return 'recently';
		}
		if ( gmdate( 'Y-m-d H:i', $first ) === gmdate( 'Y-m-d H:i', $last ) ) {
			return 'on ' . gmdate( 'Y-m-d \a\t H:i', $first ) . ' UTC';
		}
		if ( gmdate( 'Y-m-d', $first ) === gmdate( 'Y-m-d', $last ) ) {
			return 'on ' . gmdate( 'Y-m-d', $first ) . ' between ' . gmdate( 'H:i', $first ) . ' and ' . gmdate( 'H:i', $last ) . ' UTC';
		}
		return 'between ' . gmdate( 'Y-m-d H:i', $first ) . ' and ' . gmdate( 'Y-m-d H:i', $last ) . ' UTC';
	}

	// ── Bans tab ─────────────────────────────────────────────────────

	/**
	 * The candidate for an address's current ban or would-be ban, or null.
	 *
	 * @param string $ip
	 * @param string $kind ban | would
	 * @return array|null
	 */
	public static function candidate( $ip, $kind ) {
		if ( 'would' === $kind ) {
			$w = BotStormRadar_Bans::would_bans()[ $ip ] ?? null;
			return is_array( $w ) ? BotStormRadar_AbuseIPDB::from_would( $ip, $w ) : null;
		}
		$row = BotStormRadar_Bans::get( $ip );
		return is_array( $row ) ? BotStormRadar_AbuseIPDB::from_ban( $row ) : null;
	}

	/**
	 * The "Provider report" link of a ban or would-be ban row.
	 *
	 * @param array  $c
	 * @param string $kind ban | would
	 */
	public static function render_link( array $c, $kind ) {
		if ( '' !== BotStormRadar_AbuseIPDB::reportable( $c ) ) {
			return;
		}
		printf(
			'<br /><a class="button button-small bsr-provider-link" href="%1$s" title="%2$s">%3$s</a>',
			esc_url( BotStormRadar_Admin::action_url( 'provider_report', [ 'ip' => $c['ip'], 'kind' => $kind ] ) ),
			esc_attr__( 'Looks up the network\'s abuse contact at RIPEstat and prepares a report you send from your own mailbox.', 'bot-storm-radar' ),
			esc_html__( 'Provider report', 'bot-storm-radar' )
		);
	}

	/**
	 * The admin action: look the contact up, then show the panel. Returns
	 * the notice key, or '' when the panel opens.
	 *
	 * @param string $ip
	 * @param string $kind
	 * @return string
	 */
	public static function handle_action( $ip, $kind ) {
		$c = self::candidate( $ip, $kind );
		if ( null === $c || '' !== BotStormRadar_AbuseIPDB::reportable( $c ) ) {
			return 'provider_none';
		}
		self::lookup( $c['ip'] );
		return '';
	}

	/**
	 * The report panel, at the top of the Bans tab when the page was opened
	 * from the action. Reads the cached lookup only.
	 */
	public static function render_panel() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only; the lookup ran in the nonce-checked action.
		if ( empty( $_GET[ self::ARG ] ) ) {
			return;
		}
		$ip   = sanitize_text_field( wp_unslash( $_GET[ self::ARG ] ) );
		$kind = isset( $_GET['kind'] ) && 'would' === $_GET['kind'] ? 'would' : 'ban';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! BotStormRadar_IP_Resolver::is_valid_ip( $ip ) ) {
			return;
		}
		$c   = self::candidate( $ip, $kind );
		$res = self::lookup( $ip, false );
		if ( null === $c || '' !== BotStormRadar_AbuseIPDB::reportable( $c ) || null === $res ) {
			return;
		}
		$user    = wp_get_current_user();
		$subject = self::subject( $c );
		$body    = self::body( $c, $user ? $user->display_name : '' );
		$close   = remove_query_arg( [ self::ARG, 'kind' ] );
		?>
		<div class="bsr-provider-panel">
			<h2>
				<?php
				/* translators: %s: IP address */
				echo esc_html( sprintf( __( 'Provider report for %s', 'bot-storm-radar' ), $ip ) );
				?>
			</h2>
			<?php if ( ! $res['ok'] ) : ?>
				<p>
					<?php
					/* translators: %s: error message */
					echo esc_html( sprintf( __( 'The RIPEstat lookup failed (%s). Try again in a few minutes, or look the network up by hand.', 'bot-storm-radar' ), $res['error'] ) );
					?>
				</p>
			<?php elseif ( empty( $res['contacts'] ) ) : ?>
				<p><?php esc_html_e( 'RIPEstat has no abuse contact for this address. The report below can still go to the network\'s owner if you find another contact.', 'bot-storm-radar' ); ?></p>
			<?php else : ?>
				<p>
					<?php
					echo esc_html(
						'' !== $res['rir']
							/* translators: 1: e-mail addresses, 2: regional registry such as RIPE or ARIN */
							? sprintf( __( 'Abuse contact: %1$s (from the %2$s registry, through RIPEstat).', 'bot-storm-radar' ), implode( ', ', $res['contacts'] ), $res['rir'] )
							/* translators: %s: e-mail addresses */
							: sprintf( __( 'Abuse contact: %s (through RIPEstat).', 'bot-storm-radar' ), implode( ', ', $res['contacts'] ) )
					);
					?>
				</p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Send it from your own mailbox. The plugin never mails the provider. Check the text first: it names this site\'s domain.', 'bot-storm-radar' ); ?></p>
			<p><label><?php esc_html_e( 'Subject', 'bot-storm-radar' ); ?><br /><input type="text" class="large-text" readonly value="<?php echo esc_attr( $subject ); ?>" /></label></p>
			<p><label><?php esc_html_e( 'Message', 'bot-storm-radar' ); ?><br /><textarea class="large-text code" rows="16" readonly><?php echo esc_textarea( $body ); ?></textarea></label></p>
			<p>
				<?php if ( $res['ok'] && ! empty( $res['contacts'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( 'mailto:' . implode( ',', $res['contacts'] ) . '?subject=' . rawurlencode( $subject ) . '&body=' . rawurlencode( str_replace( "\n", "\r\n", $body ) ), [ 'mailto' ] ); ?>"><?php esc_html_e( 'Open in your mail app', 'bot-storm-radar' ); ?></a>
				<?php endif; ?>
				<a class="button" href="<?php echo esc_url( $close ); ?>"><?php esc_html_e( 'Close', 'bot-storm-radar' ); ?></a>
			</p>
		</div>
		<?php
	}
}
