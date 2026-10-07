<?php
/**
 * Main plugin class: orchestrator and lifecycle manager.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bot_Storm_Radar {

	const OPTION_KEY     = 'botstormradar_options';
	const VERSION_OPTION = 'botstormradar_version';

	/**
	 * @var Bot_Storm_Radar|null
	 */
	private static $instance = null;

	/**
	 * @return Bot_Storm_Radar
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->load_dependencies();
		// The beacon answers before anything else loads (see BotStormRadar_Beacon).
		BotStormRadar_Beacon::maybe_serve();
		$this->init_hooks();
	}

	private function load_dependencies() {
		$dir = BOTSTORMRADAR_PLUGIN_DIR . 'includes/';
		require_once $dir . 'class-botstormradar-ip-resolver.php';
		require_once $dir . 'class-botstormradar-helpers.php';
		require_once $dir . 'class-botstormradar-sources.php';
		require_once $dir . 'class-botstormradar-client-ip.php';
		require_once $dir . 'class-botstormradar-bans.php';
		require_once $dir . 'class-botstormradar-guard.php';
		require_once $dir . 'class-botstormradar-state-reader.php';
		require_once $dir . 'class-botstormradar-probe.php';
		require_once $dir . 'class-botstormradar-channel.php';
		require_once $dir . 'class-botstormradar-channel-drain.php';
		require_once $dir . 'class-botstormradar-trips.php';
		require_once $dir . 'class-botstormradar-state.php';
		require_once $dir . 'class-botstormradar-gate-install.php';
		require_once $dir . 'class-botstormradar-gate-early.php';
		require_once $dir . 'class-botstormradar-classifier.php';
		require_once $dir . 'class-botstormradar-woocommerce.php';
		require_once $dir . 'class-botstormradar-counters.php';
		require_once $dir . 'class-botstormradar-recorder.php';
		require_once $dir . 'class-botstormradar-beacon.php';
		require_once $dir . 'class-botstormradar-good-bots.php';
		require_once $dir . 'class-botstormradar-metrics.php';
		require_once $dir . 'class-botstormradar-storage.php';
		require_once $dir . 'class-botstormradar-baseline.php';
		require_once $dir . 'interface-botstormradar-actions.php';
		require_once $dir . 'class-botstormradar-actions-log.php';
		require_once $dir . 'class-botstormradar-storm.php';
		require_once $dir . 'class-botstormradar-error-burst.php';
		require_once $dir . 'class-botstormradar-tick.php';
		require_once $dir . 'class-botstormradar-email-alerts.php';
		require_once $dir . 'class-botstormradar-admin.php';
		require_once $dir . 'class-botstormradar-migration.php';
		// github-build-only:start (build-zip.sh --wporg removes this block and the file it loads)
		require_once $dir . 'class-botstormradar-github-updater.php';
		// github-build-only:end

		// Log-fed sources are read from the command line only.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-botstormradar-log-line.php';
			require_once $dir . 'class-botstormradar-log-source.php';
			require_once $dir . 'class-botstormradar-gate-replay.php';
			require_once $dir . 'class-botstormradar-cli.php';
			WP_CLI::add_command( 'bot-storm-radar', 'BotStormRadar_CLI' );
			WP_CLI::add_command( 'bot-storm-radar source', 'BotStormRadar_CLI_Source' );
		}
	}

	private function init_hooks() {
		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
		add_action( 'init', [ $this, 'init' ] );
		add_filter( 'cron_schedules', [ $this, 'cron_schedules' ] );
		register_activation_hook( BOTSTORMRADAR_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( BOTSTORMRADAR_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Everything that must be in place before the request is classified.
	 */
	public function on_plugins_loaded() {
		// First: stored names of 0.2.x move to the new prefix before anything
		// reads them. While another process is moving them, this request
		// leaves the plugin alone (no tables, no cron, no counting).
		if ( ! BotStormRadar_Migration::prefix() ) {
			return;
		}
		BotStormRadar_Bans::maybe_install();
		$this->maybe_upgrade();
		BotStormRadar_WooCommerce::init();
		BotStormRadar_Beacon::init();
		BotStormRadar_Recorder::init();
		BotStormRadar_Client_IP::init();
		BotStormRadar_Bans::init();
		BotStormRadar_Guard::init();
		BotStormRadar_State::init();
		BotStormRadar_Gate_Install::init();
		BotStormRadar_Good_Bots::init();
		BotStormRadar_Tick::init();
	}

	public function init() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- builds installed from GitHub get no language packs from wordpress.org.
		load_plugin_textdomain( 'bot-storm-radar', false, dirname( BOTSTORMRADAR_PLUGIN_BASENAME ) . '/languages' );

		if ( is_admin() ) {
			BotStormRadar_Admin::init();
			// github-build-only:start
			new BotStormRadar_GitHub_Updater();
			// github-build-only:end
		}
	}

	/**
	 * One-minute schedule for the metrics tick and a daily one for list refreshes.
	 *
	 * @param array $schedules
	 * @return array
	 */
	public function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['botstormradar_minute'] ) ) {
			$schedules['botstormradar_minute'] = [
				'interval' => 60,
				'display'  => __( 'Every minute (Bot Storm Radar)', 'bot-storm-radar' ),
			];
		}
		return $schedules;
	}

	public function activate() {
		if ( ! BotStormRadar_Migration::prefix() ) {
			return; // Another process is moving the stored names; the next load finishes the upgrade.
		}
		$defaults = self::get_default_options();
		$existing = get_option( self::OPTION_KEY, [] );
		update_option( self::OPTION_KEY, wp_parse_args( is_array( $existing ) ? $existing : [], $defaults ) );

		if ( ! get_option( BotStormRadar_Storm::STATE_OPTION ) ) {
			update_option( BotStormRadar_Storm::STATE_OPTION, BotStormRadar_Storm::initial_state(), true );
		}
		BotStormRadar_Baseline::ensure_started();
		BotStormRadar_Bans::install();
		BotStormRadar_State::rebuild();
		BotStormRadar_Gate_Install::install();
		BotStormRadar_Tick::schedule();
		BotStormRadar_Client_IP::ensure_cron();
		update_option( self::VERSION_OPTION, BOTSTORMRADAR_VERSION, false );
		// Last: the gate runs again only once its copy, manifest and state exist.
		BotStormRadar_Gate_Install::enable();
	}

	public function deactivate() {
		// First: the marker stops the gate on the very next request, whatever
		// loader (mu-plugin, or a cached auto_prepend_file line) still runs.
		BotStormRadar_Gate_Install::disable();
		BotStormRadar_Gate_Early::disable();
		BotStormRadar_Gate_Install::remove_loader();
		BotStormRadar_Tick::unschedule();
		BotStormRadar_Client_IP::unschedule();
	}

	/**
	 * Housekeeping when the code version changes without a re-activation:
	 * reschedule cron hooks, drop options earlier versions no longer read.
	 */
	private function maybe_upgrade() {
		$stored = get_option( self::VERSION_OPTION, '' );
		if ( BOTSTORMRADAR_VERSION === $stored ) {
			return;
		}
		if ( ! wp_next_scheduled( BotStormRadar_Tick::HOOK ) ) {
			BotStormRadar_Tick::schedule();
		}
		BotStormRadar_Client_IP::ensure_cron();
		// 0.1.0 and 0.1.1 named these differently (replaced by the shared
		// WC Antifraud client-IP class in 0.1.2).
		$old = wp_next_scheduled( 'bsr_refresh_ip_lists' );
		while ( $old ) {
			wp_unschedule_event( $old, 'bsr_refresh_ip_lists' );
			$old = wp_next_scheduled( 'bsr_refresh_ip_lists' );
		}
		delete_option( 'bsr_cloudflare_ranges' );
		delete_option( 'bsr_proxy_detect' );
		// 0.3.0 left old chunk names inside the minute-chunk indexes.
		BotStormRadar_Migration::repair_chunk_indexes();
		// A new version brings a new gate: rebuild the state and the bundle.
		BotStormRadar_State::rebuild();
		BotStormRadar_Gate_Install::install();
		update_option( self::VERSION_OPTION, BOTSTORMRADAR_VERSION, false );
	}

	/**
	 * Default options. Thresholds are deliberately conservative starting
	 * points; v0.1 exists to calibrate them against real traffic.
	 *
	 * @return array
	 */
	public static function get_default_options() {
		return [
			'email_recipients'      => get_option( 'admin_email' ),
			'alert_on_warning'      => 1,
			'alert_on_storm'        => 1,
			'alert_on_calm'         => 1,
			'warning_threshold'     => 40,
			'storm_threshold'       => 60,
			'warning_minutes'       => 1,
			'storm_minutes'         => 1,
			'error_pressure_storm'  => 0.5,
			'storm_hold_minutes'    => 15,
			'cooling_hold_minutes'  => 15,
			'warning_clear_minutes' => 5,
			'min_distinct_ips'      => 30,
			'spike_factor'          => 4,
			'slow_request_ms'       => 2000,
			'error_burst_5xx'       => 20,
			'error_burst_clear_minutes' => 15,
			'trusted_proxies'       => '',
			'trust_all_forwarding'  => 0,
			'allowlist'             => '',
			'probe_refusal'         => 1,
			'probe_extra'           => '',
			'probe_allow'           => '',
			'ban_mode'              => 'observe',
			'trip_probe_count'      => 3,
			'trip_probe_window_minutes' => 10,
			'trip_404_count'        => 20,
			'trip_ban_minutes'      => 60,
			'trip_ban_repeat_hours' => 24,
		];
	}
}

/**
 * @return Bot_Storm_Radar
 */
function bot_storm_radar() {
	return Bot_Storm_Radar::get_instance();
}
