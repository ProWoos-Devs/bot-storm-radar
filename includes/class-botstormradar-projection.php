<?php
/**
 * What the gate decides with: the active bans, recent unbans, protected
 * addresses, trust configuration, probe rules and trip settings, projected
 * from the ban tables and the options. The database is the truth; this is
 * a read-only view of it, stored whole wherever the gate reads it.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Projection {

	/**
	 * Format of the projection. BotStormRadar_State_Reader::FORMAT must match.
	 */
	const FORMAT = 1;

	/**
	 * How long an unban keeps the address on the gate's recent-unban list.
	 */
	const RECENT_UNBAN = DAY_IN_SECONDS;

	/**
	 * The state the gate needs, built from the tables and the options.
	 * Keys filled by later changes are present with empty values so the
	 * format stays the same.
	 *
	 * @param int|null $now Tests.
	 * @return array
	 */
	public static function build( $now = null ) {
		$now = null === $now ? time() : (int) $now;
		// A settings save fires the rebuild while this request's options
		// cache still holds the values it started with; read them fresh.
		BotStormRadar_Helpers::flush_options();
		$opts = BotStormRadar_Helpers::get_options();

		$bans = [];
		foreach ( BotStormRadar_Bans::active( $now ) as $b ) {
			// Asked again: a ban that became protected after it was written
			// (an allowlist entry, an administrator seen since) is left out.
			if ( BotStormRadar_Guard::may_ban( $b['ip_text'], (int) $b['prefix_len'] ) ) {
				$bans[] = [ $b['ip_text'], (int) $b['prefix_len'], (int) $b['expires_at'] ];
			}
		}
		$unbans = [];
		foreach ( BotStormRadar_Bans::recent_unbans( $now - self::RECENT_UNBAN ) as $u ) {
			$unbans[] = [ $u['ip_text'], (int) $u['prefix_len'], (int) $u['unbanned_at'] ];
		}

		return [
			'format'     => self::FORMAT,
			'written_at' => $now,
			'generation' => BotStormRadar_Bans::generation(),
			'mode'       => 'enforce' === ( $opts['ban_mode'] ?? 'observe' ) ? 'enforce' : 'observe',
			'trust'      => BotStormRadar_Client_IP::trust_config(),
			'protected'  => [
				'allowlist' => BotStormRadar_Helpers::parse_list( $opts['allowlist'] ?? '' ),
				'admins'    => BotStormRadar_Guard::admin_addresses( $now ),
				'bots'      => BotStormRadar_Guard::verified_bots( $now ),
			],
			'bans'       => $bans,
			'unbans'     => $unbans,
			'probe'      => self::probe_config(),
			'trips'      => BotStormRadar_Trips::state_config(),
			'mail'       => [ 'recipients' => BotStormRadar_Sources::alert_recipients( BotStormRadar_Sources::SITE ) ],
		];
	}

	/**
	 * The probe rules for BotStormRadar_Probe::classify(), from the options: switch,
	 * WordPress address path, uploads path, WordPress folder, the bundled
	 * scanner list, and the owner's patterns and exceptions compiled.
	 *
	 * @return array
	 */
	public static function probe_config() {
		static $scanner = null;
		if ( null === $scanner ) {
			$scanner = array_map( 'strtolower', BotStormRadar_Helpers::bundled_list( 'scanner-paths.txt' ) );
		}
		$opts    = BotStormRadar_Helpers::get_options();
		$uploads = wp_upload_dir( null, false );
		return [
			'on'      => ! empty( $opts['probe_refusal'] ),
			'home'    => untrailingslashit( (string) wp_parse_url( (string) get_option( 'home' ), PHP_URL_PATH ) ),
			'uploads' => trailingslashit( (string) wp_parse_url( (string) ( $uploads['baseurl'] ?? '' ), PHP_URL_PATH ) ),
			'root'    => ABSPATH,
			'scanner' => $scanner,
			'extra'   => BotStormRadar_Probe::compile( (string) ( $opts['probe_extra'] ?? '' ) ),
			'allow'   => BotStormRadar_Probe::compile( (string) ( $opts['probe_allow'] ?? '' ) ),
		];
	}
}
