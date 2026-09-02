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

	const OPTION_KEY = 'bsr_options';

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
		require_once $dir . 'class-bsr-helpers.php';
		require_once $dir . 'class-bsr-client-ip.php';
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
		require_once $dir . 'class-bsr-tick.php';
		require_once $dir . 'class-bsr-email-alerts.php';
		require_once $dir . 'class-bsr-admin.php';
		require_once $dir . 'class-bsr-github-updater.php';
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
		BSR_WooCommerce::init();
		BSR_Beacon::init();
		BSR_Recorder::init();
		BSR_Client_IP::init();
		BSR_Good_Bots::init();
		BSR_Tick::init();
	}

	public function init() {
		load_plugin_textdomain( 'bot-storm-radar', false, dirname( BSR_PLUGIN_BASENAME ) . '/languages' );

		if ( is_admin() ) {
			BSR_Admin::init();
			new BSR_GitHub_Updater();
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
		BSR_Tick::schedule();
		BSR_Client_IP::schedule_refresh();
	}

	public function deactivate() {
		BSR_Tick::unschedule();
		BSR_Client_IP::unschedule_refresh();
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
			'trusted_proxies'       => '',
			'trust_all_forwarding'  => 0,
		];
	}
}

/**
 * @return Bot_Storm_Radar
 */
function bot_storm_radar() {
	return Bot_Storm_Radar::get_instance();
}
