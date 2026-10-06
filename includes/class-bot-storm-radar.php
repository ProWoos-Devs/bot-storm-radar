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

	const OPTION_KEY     = 'bsr_options';
	const VERSION_OPTION = 'bsr_version';

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
		// The beacon answers before anything else loads (see BSR_Beacon).
		BSR_Beacon::maybe_serve();
		$this->init_hooks();
	}

	private function load_dependencies() {
		$dir = BSR_PLUGIN_DIR . 'includes/';
		require_once $dir . 'class-bsr-ip-resolver.php';
		require_once $dir . 'class-bsr-helpers.php';
		require_once $dir . 'class-bsr-sources.php';
		require_once $dir . 'class-bsr-client-ip.php';
		require_once $dir . 'class-bsr-bans.php';
		require_once $dir . 'class-bsr-guard.php';
		require_once $dir . 'class-bsr-state-reader.php';
		require_once $dir . 'class-bsr-probe.php';
		require_once $dir . 'class-bsr-channel.php';
		require_once $dir . 'class-bsr-channel-drain.php';
		require_once $dir . 'class-bsr-trips.php';
		require_once $dir . 'class-bsr-state.php';
		require_once $dir . 'class-bsr-gate-install.php';
		require_once $dir . 'class-bsr-gate-early.php';
		require_once $dir . 'class-bsr-classifier.php';
		require_once $dir . 'class-bsr-woocommerce.php';
		require_once $dir . 'class-bsr-counters.php';
		require_once $dir . 'class-bsr-recorder.php';
		require_once $dir . 'class-bsr-beacon.php';
		require_once $dir . 'class-bsr-good-bots.php';
		require_once $dir . 'class-bsr-metrics.php';
		require_once $dir . 'class-bsr-storage.php';
		require_once $dir . 'class-bsr-baseline.php';
		require_once $dir . 'interface-bsr-actions.php';
		require_once $dir . 'class-bsr-actions-log.php';
		require_once $dir . 'class-bsr-storm.php';
		require_once $dir . 'class-bsr-error-burst.php';
		require_once $dir . 'class-bsr-tick.php';
		require_once $dir . 'class-bsr-email-alerts.php';
		require_once $dir . 'class-bsr-admin.php';
		// github-build-only:start (build-zip.sh --wporg removes this block and the file it loads)
		require_once $dir . 'class-bsr-github-updater.php';
		// github-build-only:end

		// Log-fed sources are read from the command line only.
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-bsr-log-line.php';
			require_once $dir . 'class-bsr-log-source.php';
			require_once $dir . 'class-bsr-gate-replay.php';
			require_once $dir . 'class-bsr-cli.php';
			WP_CLI::add_command( 'bot-storm-radar', 'BSR_CLI' );
			WP_CLI::add_command( 'bot-storm-radar source', 'BSR_CLI_Source' );
		}
	}

	private function init_hooks() {
		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
		add_action( 'init', [ $this, 'init' ] );
		add_filter( 'cron_schedules', [ $this, 'cron_schedules' ] );
		register_activation_hook( BSR_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( BSR_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Everything that must be in place before the request is classified.
	 */
	public function on_plugins_loaded() {
		BSR_Bans::maybe_install();
		$this->maybe_upgrade();
		BSR_WooCommerce::init();
		BSR_Beacon::init();
		BSR_Recorder::init();
		BSR_Client_IP::init();
		BSR_Bans::init();
		BSR_Guard::init();
		BSR_State::init();
		BSR_Gate_Install::init();
		BSR_Good_Bots::init();
		BSR_Tick::init();
	}

	public function init() {
		// phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- builds installed from GitHub get no language packs from wordpress.org.
		load_plugin_textdomain( 'bot-storm-radar', false, dirname( BSR_PLUGIN_BASENAME ) . '/languages' );

		if ( is_admin() ) {
			BSR_Admin::init();
			// github-build-only:start
			new BSR_GitHub_Updater();
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
		if ( ! isset( $schedules['bsr_minute'] ) ) {
			$schedules['bsr_minute'] = [
				'interval' => 60,
				'display'  => __( 'Every minute (Bot Storm Radar)', 'bot-storm-radar' ),
			];
		}
		return $schedules;
	}

	public function activate() {
		$defaults = self::get_default_options();
		$existing = get_option( self::OPTION_KEY, [] );
		update_option( self::OPTION_KEY, wp_parse_args( is_array( $existing ) ? $existing : [], $defaults ) );

		if ( ! get_option( BSR_Storm::STATE_OPTION ) ) {
			update_option( BSR_Storm::STATE_OPTION, BSR_Storm::initial_state(), true );
		}
		BSR_Baseline::ensure_started();
		BSR_Bans::install();
		BSR_State::rebuild();
		BSR_Gate_Install::install();
		BSR_Tick::schedule();
		BSR_Client_IP::ensure_cron();
		update_option( self::VERSION_OPTION, BSR_VERSION, false );
		// Last: the gate runs again only once its copy, manifest and state exist.
		BSR_Gate_Install::enable();
	}

	public function deactivate() {
		// First: the marker stops the gate on the very next request, whatever
		// loader (mu-plugin, or a cached auto_prepend_file line) still runs.
		BSR_Gate_Install::disable();
		BSR_Gate_Early::disable();
		BSR_Gate_Install::remove_loader();
		BSR_Tick::unschedule();
		BSR_Client_IP::unschedule();
	}

	/**
	 * Housekeeping when the code version changes without a re-activation:
	 * reschedule cron hooks, drop options earlier versions no longer read.
	 */
	private function maybe_upgrade() {
		$stored = get_option( self::VERSION_OPTION, '' );
		if ( BSR_VERSION === $stored ) {
			return;
		}
		if ( ! wp_next_scheduled( BSR_Tick::HOOK ) ) {
			BSR_Tick::schedule();
		}
		BSR_Client_IP::ensure_cron();
		// 0.1.0 and 0.1.1 named these differently (replaced by the shared
		// WC Antifraud client-IP class in 0.1.2).
		$old = wp_next_scheduled( 'bsr_refresh_ip_lists' );
		while ( $old ) {
			wp_unschedule_event( $old, 'bsr_refresh_ip_lists' );
			$old = wp_next_scheduled( 'bsr_refresh_ip_lists' );
		}
		delete_option( 'bsr_cloudflare_ranges' );
		delete_option( 'bsr_proxy_detect' );
		// A new version brings a new gate: rebuild the state and the bundle.
		BSR_State::rebuild();
		BSR_Gate_Install::install();
		update_option( self::VERSION_OPTION, BSR_VERSION, false );
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
