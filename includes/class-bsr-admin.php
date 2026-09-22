<?php
/**
 * Admin: the Bot Storm Radar screen (Radar and Settings tabs), the
 * dashboard widget, notices, and the small nonce'd actions.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Admin {

	const PAGE         = 'bot-storm-radar';
	const ACTION_NONCE = 'bsr_admin_action';
	const CAP          = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'add_menu_page' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'handle_admin_actions' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notices' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'add_dashboard_widget' ] );
		add_filter( 'plugin_action_links_' . BSR_PLUGIN_BASENAME, [ __CLASS__, 'add_action_links' ] );
	}

	public static function add_menu_page() {
		add_menu_page(
			__( 'Bot Storm Radar', 'bot-storm-radar' ),
			__( 'Bot Storm Radar', 'bot-storm-radar' ),
			self::CAP,
			self::PAGE,
			[ __CLASS__, 'render_page' ],
			'dashicons-visibility',
			57
		);
	}

	public static function add_action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ), __( 'Radar', 'bot-storm-radar' ) ) );
		return $links;
	}

	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook && 'index.php' !== $hook ) {
			return;
		}
		wp_add_inline_style( 'wp-admin', self::css() );
	}

	// ── Tabs ────────────────────────────────────────────────────────

	private static function current_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'radar';
		return in_array( $tab, [ 'radar', 'settings' ], true ) ? $tab : 'radar';
	}

	/**
	 * The source the Radar tab and the per-source actions refer to: a defined
	 * log source named in the query, otherwise the site.
	 *
	 * @return string
	 */
	private static function current_source() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$source = isset( $_GET['source'] ) ? sanitize_key( wp_unslash( $_GET['source'] ) ) : BSR_Sources::SITE;
		return BSR_Sources::exists( $source ) ? $source : BSR_Sources::SITE;
	}

	private static function tabs() {
		return [
			'radar'    => __( 'Radar', 'bot-storm-radar' ),
			'settings' => __( 'Settings', 'bot-storm-radar' ),
		];
	}

	// ── Settings ────────────────────────────────────────────────────

	public static function register_settings() {
		register_setting( 'bsr_group', Bot_Storm_Radar::OPTION_KEY, [ __CLASS__, 'sanitize' ] );

		$b = BSR_Baseline::effective();
		$baseline_note = self::baseline_sentence( $b );

		add_settings_section( 'bsr_thresholds', __( 'Storm thresholds', 'bot-storm-radar' ), function () use ( $baseline_note ) {
			echo '<p>' . esc_html__( 'The storm score runs from 0 to 100. It only rises when the number of distinct addresses in a minute exceeds the baseline by the spike factor, so a quiet minute never scores.', 'bot-storm-radar' ) . '</p>';
			echo '<p class="bsr-baseline">' . esc_html( $baseline_note ) . '</p>';
			if ( self::page_cache_counts_slow() ) {
				echo '<p class="bsr-danger">' . esc_html__( 'A page cache is installed (WP_CACHE is on). PHP then sees mostly cache misses, so slow requests measure the cache and not the site. Set "Slow request" to 0 so that error pressure counts 5xx responses only.', 'bot-storm-radar' ) . '</p>';
			}
		}, self::PAGE );
		self::number_field( 'warning_threshold', __( 'Warning threshold', 'bot-storm-radar' ), 'bsr_thresholds', 0, 100, 1, __( 'Score at or above which the state moves from calm to warning.', 'bot-storm-radar' ) );
		self::number_field( 'storm_threshold', __( 'Storm threshold', 'bot-storm-radar' ), 'bsr_thresholds', 0, 100, 1, __( 'Score at or above which warning becomes storm.', 'bot-storm-radar' ) );
		self::number_field( 'error_pressure_storm', __( 'Error pressure alone', 'bot-storm-radar' ), 'bsr_thresholds', 0, 1, 0.01, __( 'Share of 5xx and slow responses (0 to 1) that turns a warning into a storm on its own, whatever the score.', 'bot-storm-radar' ) );
		self::number_field( 'min_distinct_ips', __( 'Minimum distinct addresses', 'bot-storm-radar' ), 'bsr_thresholds', 1, 100000, 1, sprintf( __( 'Below this many distinct addresses in a minute the score is always 0. Also the reference volume until a baseline is learned. Baseline: %s', 'bot-storm-radar' ), self::fmt_or_dash( $b['ips_median'] ?? null, 0 ) ) );
		self::number_field( 'spike_factor', __( 'Spike factor', 'bot-storm-radar' ), 'bsr_thresholds', 1.5, 100, 0.5, __( 'The score reaches full weight when the minute has this many times the baseline addresses.', 'bot-storm-radar' ) );
		self::number_field( 'slow_request_ms', __( 'Slow request (ms)', 'bot-storm-radar' ), 'bsr_thresholds', 0, 60000, 100, __( 'PHP requests taking at least this long count toward error pressure. 0 disables. Behind a page cache PHP sees mostly cache misses, so slow requests then measure the cache, not the site; use 0 there.', 'bot-storm-radar' ) );
		self::number_field( 'error_burst_5xx', __( '5xx burst (log sources)', 'bot-storm-radar' ), 'bsr_thresholds', 0, 100000, 1, __( 'A log source minute with at least this many 5xx responses sends one alert per episode, whatever the ratio and the score. The storm state is not changed. 0 disables. Applies to log sources only.', 'bot-storm-radar' ) );

		add_settings_section( 'bsr_timing', __( 'Timing', 'bot-storm-radar' ), function () {
			echo '<p>' . esc_html__( 'Consecutive finished minutes. One minute is sixty seconds of observation.', 'bot-storm-radar' ) . '</p>';
		}, self::PAGE );
		self::number_field( 'warning_minutes', __( 'Minutes above warning before warning', 'bot-storm-radar' ), 'bsr_timing', 1, 60, 1 );
		self::number_field( 'storm_minutes', __( 'Minutes above storm before storm', 'bot-storm-radar' ), 'bsr_timing', 1, 60, 1 );
		self::number_field( 'warning_clear_minutes', __( 'Minutes below warning to clear a warning', 'bot-storm-radar' ), 'bsr_timing', 1, 240, 1 );
		self::number_field( 'storm_hold_minutes', __( 'Storm hold (minutes below warning before cooling)', 'bot-storm-radar' ), 'bsr_timing', 1, 720, 1 );
		self::number_field( 'cooling_hold_minutes', __( 'Cooling hold (minutes before calm)', 'bot-storm-radar' ), 'bsr_timing', 1, 720, 1 );
		self::number_field( 'error_burst_clear_minutes', __( 'Minutes below the 5xx burst threshold before the episode ends', 'bot-storm-radar' ), 'bsr_timing', 1, 720, 1 );

		add_settings_section( 'bsr_alerts', __( 'Alerts', 'bot-storm-radar' ), '__return_null', self::PAGE );
		add_settings_field( 'email_recipients', __( 'Alert email recipients', 'bot-storm-radar' ), function () {
			printf( '<input type="text" class="regular-text" name="%1$s[email_recipients]" value="%2$s" /><p class="description">%3$s</p>', esc_attr( Bot_Storm_Radar::OPTION_KEY ), esc_attr( BSR_Helpers::opt( 'email_recipients', '' ) ), esc_html__( 'Comma-separated. Leave empty to disable email alerts.', 'bot-storm-radar' ) );
		}, self::PAGE, 'bsr_alerts' );
		add_settings_field( 'alerts_on', __( 'Send an email on', 'bot-storm-radar' ), function () {
			foreach ( [ 'alert_on_warning' => __( 'warning', 'bot-storm-radar' ), 'alert_on_storm' => __( 'storm', 'bot-storm-radar' ), 'alert_on_calm' => __( 'all-clear after a storm', 'bot-storm-radar' ) ] as $k => $label ) {
				printf( '<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label><br />', esc_attr( Bot_Storm_Radar::OPTION_KEY ), esc_attr( $k ), checked( 1, (int) BSR_Helpers::opt( $k, 1 ), false ), esc_html( $label ) );
			}
		}, self::PAGE, 'bsr_alerts' );

		add_settings_section( 'bsr_proxies', __( 'Client addresses', 'bot-storm-radar' ), function () {
			$cf_count   = count( BSR_Client_IP::cloudflare_ranges() );
			$cf_fetched = BSR_Client_IP::cloudflare_ranges_fetched_at();
			echo '<p>' . esc_html__( 'A forwarding header is believed only when the request arrived through a known proxy: Cloudflare, a local proxy (private peer address), or one declared here. Anything else is the client itself.', 'bot-storm-radar' ) . '</p>';
			echo '<p class="description">' . esc_html( sprintf( __( 'Cloudflare ranges: %1$d entries, %2$s.', 'bot-storm-radar' ), $cf_count, $cf_fetched > 0 ? sprintf( __( 'fetched %s', 'bot-storm-radar' ), wp_date( get_option( 'date_format' ), $cf_fetched ) ) : __( 'bundled copy, fetch pending', 'bot-storm-radar' ) ) ) . '</p>';
		}, self::PAGE );
		add_settings_field( 'trusted_proxies', __( 'Trusted proxy addresses', 'bot-storm-radar' ), function () {
			printf( '<textarea class="large-text code" rows="4" name="%1$s[trusted_proxies]">%2$s</textarea><p class="description">%3$s</p>', esc_attr( Bot_Storm_Radar::OPTION_KEY ), esc_textarea( BSR_Helpers::opt( 'trusted_proxies', '' ) ), esc_html__( 'One per line, IPv4 or IPv6, address or CIDR. Only for a proxy with a public address (external load balancer, a CDN other than Cloudflare).', 'bot-storm-radar' ) );
		}, self::PAGE, 'bsr_proxies' );
		add_settings_field( 'trust_all_forwarding', __( 'Trust all forwarding headers', 'bot-storm-radar' ), function () {
			printf( '<label><input type="checkbox" name="%1$s[trust_all_forwarding]" value="1" %2$s /> %3$s</label><p class="description bsr-danger">%4$s</p>', esc_attr( Bot_Storm_Radar::OPTION_KEY ), checked( 1, (int) BSR_Helpers::opt( 'trust_all_forwarding', 0 ), false ), esc_html__( 'Insecure, previous behavior', 'bot-storm-radar' ), esc_html__( 'Any client can then choose the address it is counted under. Use it only while you find your proxy address, then declare the proxy above and turn this off.', 'bot-storm-radar' ) );
		}, self::PAGE, 'bsr_proxies' );
	}

	/**
	 * @param string $key
	 * @param string $label
	 * @param string $section
	 * @param float  $min
	 * @param float  $max
	 * @param float  $step
	 * @param string $desc
	 */
	private static function number_field( $key, $label, $section, $min, $max, $step, $desc = '' ) {
		add_settings_field( $key, $label, function () use ( $key, $min, $max, $step, $desc ) {
			printf( '<input type="number" class="small-text" name="%1$s[%2$s]" value="%3$s" min="%4$s" max="%5$s" step="%6$s" />', esc_attr( Bot_Storm_Radar::OPTION_KEY ), esc_attr( $key ), esc_attr( BSR_Helpers::opt( $key, '' ) ), esc_attr( $min ), esc_attr( $max ), esc_attr( $step ) );
			if ( '' !== $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
		}, self::PAGE, $section );
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = Bot_Storm_Radar::get_default_options();
		$current  = get_option( Bot_Storm_Radar::OPTION_KEY, [] );
		$current  = wp_parse_args( is_array( $current ) ? $current : [], $defaults );
		if ( ! is_array( $input ) ) {
			return $current;
		}
		$out = $current;
		$ints = [ 'warning_threshold' => [ 0, 100 ], 'storm_threshold' => [ 0, 100 ], 'min_distinct_ips' => [ 1, 100000 ], 'slow_request_ms' => [ 0, 60000 ], 'warning_minutes' => [ 1, 60 ], 'storm_minutes' => [ 1, 60 ], 'warning_clear_minutes' => [ 1, 240 ], 'storm_hold_minutes' => [ 1, 720 ], 'cooling_hold_minutes' => [ 1, 720 ], 'error_burst_5xx' => [ 0, 100000 ], 'error_burst_clear_minutes' => [ 1, 720 ] ];
		foreach ( $ints as $k => $range ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = (int) BSR_Helpers::clamp( (int) $input[ $k ], $range[0], $range[1] );
			}
		}
		if ( isset( $input['error_pressure_storm'] ) ) {
			$out['error_pressure_storm'] = round( BSR_Helpers::clamp( (float) $input['error_pressure_storm'], 0, 1 ), 2 );
		}
		if ( isset( $input['spike_factor'] ) ) {
			$out['spike_factor'] = round( BSR_Helpers::clamp( (float) $input['spike_factor'], 1.5, 100 ), 1 );
		}
		if ( $out['storm_threshold'] < $out['warning_threshold'] ) {
			$out['storm_threshold'] = $out['warning_threshold'];
			add_settings_error( 'storm_threshold', 'bsr_order', __( 'The storm threshold cannot be below the warning threshold; it was raised to match.', 'bot-storm-radar' ), 'warning' );
		}
		if ( array_key_exists( 'email_recipients', $input ) ) {
			$raw   = sanitize_text_field( (string) $input['email_recipients'] );
			$valid = BSR_Helpers::sanitize_email_list( $raw );
			if ( '' !== trim( $raw ) && count( $valid ) !== count( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) ) ) {
				add_settings_error( 'email_recipients', 'bsr_emails', __( 'Some alert addresses are not valid email addresses and were dropped.', 'bot-storm-radar' ), 'warning' );
			}
			$out['email_recipients'] = implode( ', ', $valid );
		}
		foreach ( [ 'alert_on_warning', 'alert_on_storm', 'alert_on_calm', 'trust_all_forwarding' ] as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}
		if ( array_key_exists( 'trusted_proxies', $input ) ) {
			$kept = [];
			foreach ( BSR_Helpers::parse_list( sanitize_textarea_field( (string) $input['trusted_proxies'] ) ) as $e ) {
				$addr = explode( '/', $e )[0];
				if ( BSR_Helpers::is_valid_ip( $addr ) ) {
					$kept[] = $e;
				} else {
					add_settings_error( 'trusted_proxies', 'bsr_proxy_' . md5( $e ), sprintf( __( 'Ignored "%s": not an IP address or CIDR range.', 'bot-storm-radar' ), $e ), 'warning' );
				}
			}
			$out['trusted_proxies'] = implode( "\n", $kept );
		}
		BSR_Helpers::flush_options();
		return $out;
	}

	// ── Actions and notices ─────────────────────────────────────────

	public static function handle_admin_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! isset( $_GET['bsr_action'] ) || ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		check_admin_referer( self::ACTION_NONCE );
		$action = sanitize_key( wp_unslash( $_GET['bsr_action'] ) );
		$notice = '';
		switch ( $action ) {
			case 'reset_baseline':
				BSR_Baseline::reset( self::current_source() );
				$notice = 'baseline_reset';
				break;
			case 'reset_state':
				BSR_Storm::reset( self::current_source() );
				$notice = 'state_reset';
				break;
			case 'run_tick':
				$r      = BSR_Tick::run();
				$notice = false === $r ? 'tick_locked' : 'tick_ran';
				break;
			case 'trust_proxy':
				$s = BSR_Client_IP::suspect();
				if ( $s ) {
					BSR_Client_IP::trust_suspect();
					BSR_Helpers::flush_options();
					$notice = 'proxy_trusted';
				} else {
					$notice = 'proxy_invalid';
				}
				break;
			case 'dismiss_proxy':
				BSR_Client_IP::dismiss_suspect();
				$notice = 'proxy_dismissed';
				break;
			case 'test_alert':
				$state = BSR_Storm::get_state();
				$rows  = BSR_Storage::minutes_last( 1 );
				$row   = $rows ? end( $rows ) : BSR_Metrics::score( [ 'm' => BSR_Helpers::minute() - 1, 'total' => 0, 'ips' => 0 ] );
				$sent  = BSR_Email_Alerts::send_transition( [
					'from'        => $state['state'],
					'to'          => 'warning',
					'at'          => time(),
					'minute'      => (int) $row['m'],
					'score'       => (int) $row['score'],
					'explanation' => __( 'Test alert sent from the Settings tab. ', 'bot-storm-radar' ) . BSR_Metrics::explain( $row ),
					'metrics'     => $row,
				] );
				$notice = $sent ? 'alert_sent' : 'alert_failed';
				break;
			case 'refresh_lists':
				BSR_Client_IP::refresh_cloudflare_ranges();
				BSR_Good_Bots::refresh_ip_lists();
				$notice = 'lists_refreshed';
				break;
		}
		$redirect = remove_query_arg( [ 'bsr_action', 'ip', '_wpnonce' ] );
		wp_safe_redirect( add_query_arg( 'bsr_notice', $notice, $redirect ) );
		exit;
	}

	/**
	 * @param string $action
	 * @param array  $extra
	 * @return string
	 */
	public static function action_url( $action, array $extra = [] ) {
		$tab  = self::current_tab();
		$args = array_merge( [ 'page' => self::PAGE, 'tab' => $tab, 'bsr_action' => $action ], $extra );
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin.php' ) ), self::ACTION_NONCE );
	}

	public static function admin_notices() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! empty( $_GET['bsr_notice'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$key      = sanitize_key( wp_unslash( $_GET['bsr_notice'] ) );
			$messages = [
				'baseline_reset'  => [ 'success', __( 'The baseline was reset and will learn again over the next seven days.', 'bot-storm-radar' ) ],
				'state_reset'     => [ 'success', __( 'The storm state was reset to calm.', 'bot-storm-radar' ) ],
				'tick_ran'        => [ 'success', __( 'The minute tick ran.', 'bot-storm-radar' ) ],
				'tick_locked'     => [ 'warning', __( 'Another tick was already running; nothing was done.', 'bot-storm-radar' ) ],
				'proxy_trusted'   => [ 'success', __( 'The proxy address was added to the trusted list.', 'bot-storm-radar' ) ],
				'proxy_invalid'   => [ 'error', __( 'There is no suspected proxy to trust right now.', 'bot-storm-radar' ) ],
				'proxy_dismissed' => [ 'success', __( 'Noted: that address is not a proxy. It will not be reported again for 30 days.', 'bot-storm-radar' ) ],
				'alert_sent'      => [ 'success', __( 'A test alert was sent.', 'bot-storm-radar' ) ],
				'alert_failed'    => [ 'error', __( 'The test alert could not be sent. Check the recipients and the site\'s mail setup.', 'bot-storm-radar' ) ],
				'lists_refreshed' => [ 'success', __( 'The Cloudflare and DuckDuckBot address lists were refreshed.', 'bot-storm-radar' ) ],
			];
			if ( isset( $messages[ $key ] ) ) {
				printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
			}
		}

		$suspect = BSR_Client_IP::suspect();
		if ( $suspect && BSR_Client_IP::ip_rules_suspended() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a class="button button-small" href="%3$s">%4$s</a> <a class="button button-small" href="%5$s">%6$s</a></p></div>',
				esc_html__( 'Bot Storm Radar:', 'bot-storm-radar' ),
				esc_html( sprintf( __( 'recent admin requests arrived from %1$s with a forwarding header naming %2$s, so a proxy with that public address appears to sit in front of the site. Until it is trusted, every visitor is counted under that one address and the per-address metrics are meaningless.', 'bot-storm-radar' ), $suspect['ip'], $suspect['forwarded'] ) ),
				esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'settings', 'bsr_action' => 'trust_proxy' ], admin_url( 'admin.php' ) ), self::ACTION_NONCE ) ),
				esc_html__( 'Trust this proxy', 'bot-storm-radar' ),
				esc_url( wp_nonce_url( add_query_arg( [ 'page' => self::PAGE, 'tab' => 'settings', 'bsr_action' => 'dismiss_proxy' ], admin_url( 'admin.php' ) ), self::ACTION_NONCE ) ),
				esc_html__( 'Not a proxy', 'bot-storm-radar' )
			);
		}
	}

	// ── Page ────────────────────────────────────────────────────────

	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'bot-storm-radar' ) );
		}
		$tab = self::current_tab();
		?>
		<div class="wrap bsr-wrap">
			<div class="bsr-header">
				<span class="dashicons dashicons-visibility bsr-header-icon"></span>
				<div>
					<h1><?php esc_html_e( 'Bot Storm Radar', 'bot-storm-radar' ); ?></h1>
					<span class="bsr-version"><?php printf( esc_html__( 'Version %s, radar only: nothing is blocked', 'bot-storm-radar' ), esc_html( BSR_VERSION ) ); ?></span>
				</div>
			</div>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::tabs() as $slug => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $slug ) ); ?>" class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php settings_errors(); ?>
			<?php if ( 'settings' === $tab ) : ?>
				<form method="post" action="options.php">
					<?php settings_fields( 'bsr_group' ); ?>
					<?php do_settings_sections( self::PAGE ); ?>
					<?php submit_button( __( 'Save Settings', 'bot-storm-radar' ) ); ?>
				</form>
				<h2><?php esc_html_e( 'Maintenance', 'bot-storm-radar' ); ?></h2>
				<p>
					<a class="button" href="<?php echo esc_url( self::action_url( 'test_alert' ) ); ?>"><?php esc_html_e( 'Send a test alert', 'bot-storm-radar' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'run_tick' ) ); ?>"><?php esc_html_e( 'Run the minute tick now', 'bot-storm-radar' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'refresh_lists' ) ); ?>"><?php esc_html_e( 'Refresh address lists', 'bot-storm-radar' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'reset_state' ) ); ?>"><?php esc_html_e( 'Reset state to calm', 'bot-storm-radar' ); ?></a>
					<a class="button" href="<?php echo esc_url( self::action_url( 'reset_baseline' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Forget the learned baseline and start the seven-day learning period again?', 'bot-storm-radar' ) ); ?>');"><?php esc_html_e( 'Reset the baseline', 'bot-storm-radar' ); ?></a>
				</p>
				<?php self::render_log_sources_table(); ?>
			<?php else : ?>
				<?php self::render_source_switcher(); ?>
				<?php self::render_radar(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// ── Radar tab ───────────────────────────────────────────────────

	private static function render_source_switcher() {
		$log_sources = BSR_Sources::log_sources();
		if ( empty( $log_sources ) ) {
			return;
		}
		$current = self::current_source();
		$labels  = self::state_labels();
		$links   = [];
		foreach ( array_merge( [ BSR_Sources::SITE ], array_keys( $log_sources ) ) as $id ) {
			$state   = BSR_Storm::get_state( $id );
			$url     = admin_url( 'admin.php?page=' . self::PAGE . ( BSR_Sources::SITE === $id ? '' : '&source=' . rawurlencode( $id ) ) );
			$links[] = sprintf(
				'<li><a href="%1$s" class="%2$s">%3$s <span class="bsr-pill bsr-pill-%4$s">%5$s</span></a></li>',
				esc_url( $url ),
				$current === $id ? 'current' : '',
				esc_html( BSR_Sources::label( $id ) ),
				esc_attr( $state['state'] ),
				esc_html( $labels[ $state['state'] ] ?? $state['state'] )
			);
		}
		echo '<ul class="subsubsub bsr-sources">' . implode( ' | ', $links ) . '</ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	private static function render_radar() {
		$source   = self::current_source();
		$is_site  = BSR_Sources::SITE === $source;
		$state    = BSR_Storm::get_state( $source );
		$tick     = BSR_Tick::status();
		$baseline = BSR_Baseline::effective( $source );
		$backend  = BSR_Counters::backend();
		$rows     = BSR_Storage::minutes_last( 1440, null, $source );
		$last     = $rows ? end( $rows ) : null;
		$opts     = BSR_Helpers::get_options();
		$labels   = self::state_labels();
		?>
		<p class="bsr-intro"><?php esc_html_e( 'Bot Storm Radar watches your traffic as a crowd, not one visitor at a time. Every minute it counts the addresses that visited, how many made a single request, how many loaded a stylesheet or a script like a real browser, and how the browser names are spread, and turns that into a storm score from 0 to 100. A quiet site scores 0 and stays calm. This version only reports, nothing is ever blocked.', 'bot-storm-radar' ); ?></p>
		<div class="bsr-cards">
			<div class="bsr-card bsr-state bsr-state-<?php echo esc_attr( $state['state'] ); ?>">
				<div class="bsr-card-label"><?php esc_html_e( 'Current state', 'bot-storm-radar' ); ?></div>
				<div class="bsr-state-name"><?php echo esc_html( $labels[ $state['state'] ] ?? $state['state'] ); ?></div>
				<div class="bsr-card-sub"><?php printf( esc_html__( 'since %s', 'bot-storm-radar' ), esc_html( self::ago( (int) $state['since'] ) ) ); ?></div>
				<div class="bsr-card-sub"><?php printf( esc_html__( 'last score %1$s, %2$d storms so far', 'bot-storm-radar' ), esc_html( (string) (int) $state['last_score'] ), (int) $state['storms'] ); ?></div>
			</div>
			<div class="bsr-card">
				<div class="bsr-card-label"><?php esc_html_e( 'Last finished minute', 'bot-storm-radar' ); ?></div>
				<?php if ( $last ) : ?>
					<div class="bsr-big"><?php echo esc_html( (string) (int) $last['score'] ); ?> <small><?php esc_html_e( 'score', 'bot-storm-radar' ); ?></small></div>
					<div class="bsr-card-sub"><?php printf( esc_html__( '0 means nothing looks like a swarm. Warning starts at %1$d, storm at %2$d.', 'bot-storm-radar' ), (int) $opts['warning_threshold'], (int) $opts['storm_threshold'] ); ?></div>
					<div class="bsr-card-sub"><?php printf( esc_html__( '%1$d requests from %2$d addresses, %3$d single-hit', 'bot-storm-radar' ), (int) $last['total'], (int) $last['ips'], (int) $last['single'] ); ?></div>
					<div class="bsr-card-sub"><?php printf( esc_html__( 'asset ratio %1$s, %2$d user agents, %3$d networks', 'bot-storm-radar' ), esc_html( null === $last['asset_ratio'] ? '–' : BSR_Metrics::fmt( $last['asset_ratio'] ) ), (int) $last['uas'], (int) $last['nets'] ); ?></div>
					<?php if ( (int) ( $last['refused'] ?? 0 ) > 0 ) : ?>
						<div class="bsr-card-sub"><?php printf( esc_html__( '%d refused by the web server (403, 429, 444), not counted', 'bot-storm-radar' ), (int) $last['refused'] ); ?></div>
					<?php endif; ?>
					<div class="bsr-card-sub"><?php echo esc_html( wp_date( get_option( 'time_format' ), (int) $last['m'] * 60 ) ); ?></div>
				<?php elseif ( $is_site ) : ?>
					<div class="bsr-card-sub"><?php esc_html_e( 'No minute has been processed yet. The tick runs every minute through WP cron, or on the next front-end request when cron is late.', 'bot-storm-radar' ); ?></div>
				<?php else : ?>
					<div class="bsr-card-sub"><?php esc_html_e( 'No minute has been processed yet. A system timer runs wp bot-storm-radar ingest every minute; the first run only notes where the log files end.', 'bot-storm-radar' ); ?></div>
				<?php endif; ?>
			</div>
			<div class="bsr-card">
				<div class="bsr-card-label"><?php esc_html_e( 'Baseline', 'bot-storm-radar' ); ?></div>
				<div class="bsr-card-sub"><?php echo esc_html( self::baseline_sentence( $baseline ) ); ?></div>
				<div class="bsr-card-sub"><?php printf( esc_html__( 'Thresholds: warning %1$d, storm %2$d, minimum addresses %3$d, spike factor %4$s.', 'bot-storm-radar' ), (int) $opts['warning_threshold'], (int) $opts['storm_threshold'], (int) $opts['min_distinct_ips'], esc_html( BSR_Metrics::fmt( $opts['spike_factor'], 1 ) ) ); ?></div>
			</div>
			<?php if ( ! $is_site ) : ?>
				<?php self::render_log_card( $source ); ?>
			<?php else : ?>
			<div class="bsr-card">
				<div class="bsr-card-label"><?php esc_html_e( 'Status', 'bot-storm-radar' ); ?></div>
				<div class="bsr-card-sub <?php echo 'transient' === $backend ? 'bsr-danger' : ''; ?>"><strong><?php esc_html_e( 'Counters:', 'bot-storm-radar' ); ?></strong> <?php echo esc_html( BSR_Counters::backend_label() ); ?>
					<?php if ( 'transient' === $backend ) : ?>
						<br /><?php esc_html_e( 'The fallback writes to the database on every request and can lose counts under load. Install a persistent object cache (Redis, Memcached) or enable APCu.', 'bot-storm-radar' ); ?>
					<?php endif; ?>
				</div>
				<div class="bsr-card-sub <?php echo BSR_Tick::is_late() ? 'bsr-danger' : ''; ?>"><strong><?php esc_html_e( 'Tick:', 'bot-storm-radar' ); ?></strong>
					<?php
					if ( (int) $tick['last_run'] > 0 ) {
						printf( esc_html__( 'last ran %1$s (%2$s)', 'bot-storm-radar' ), esc_html( self::ago( (int) $tick['last_run'] ) ), esc_html( (string) $tick['source'] ) );
						if ( BSR_Tick::is_late() ) {
							echo ' ' . esc_html__( 'WP cron looks stalled; the guard runs the tick from front-end requests meanwhile.', 'bot-storm-radar' );
						}
						if ( 'apcu' === $backend ) {
							echo ' ' . esc_html__( 'On APCu the tick only runs inside the web server (a command-line cron cannot see these counters), so it rides on front-end requests when WP cron is disabled.', 'bot-storm-radar' );
						}
					} else {
						esc_html_e( 'never ran yet', 'bot-storm-radar' );
					}
					$next = wp_next_scheduled( BSR_Tick::HOOK );
					if ( $next ) {
						printf( ', ' . esc_html__( 'next scheduled %s', 'bot-storm-radar' ), esc_html( self::ago( $next ) ) );
					}
					?>
				</div>
				<div class="bsr-card-sub"><strong><?php esc_html_e( 'Addresses:', 'bot-storm-radar' ); ?></strong>
					<?php
					$src  = BSR_Client_IP::source();
					$srcs = [ 'direct' => __( 'direct (no proxy)', 'bot-storm-radar' ), 'cloudflare' => __( 'behind Cloudflare', 'bot-storm-radar' ), 'cloudflare-forwarded' => __( 'behind Cloudflare without CF-Connecting-IP', 'bot-storm-radar' ), 'local-proxy' => __( 'behind a local proxy', 'bot-storm-radar' ), 'trusted-proxy' => __( 'behind a declared proxy', 'bot-storm-radar' ), 'legacy' => __( 'trusting all forwarding headers (insecure)', 'bot-storm-radar' ), 'none' => __( 'unknown', 'bot-storm-radar' ) ];
					printf( esc_html__( 'this request %1$s, you are %2$s', 'bot-storm-radar' ), esc_html( $srcs[ $src ] ?? $src ), esc_html( (string) BSR_Client_IP::resolve() ) );
					if ( BSR_Client_IP::ip_rules_suspended() ) {
						echo ' <span class="bsr-danger">' . esc_html__( 'undeclared proxy detected, per-address metrics unreliable', 'bot-storm-radar' ) . '</span>';
					}
					?>
				</div>
				<?php if ( self::page_cache_counts_slow() ) : ?>
					<div class="bsr-card-sub bsr-danger"><?php esc_html_e( 'A page cache is installed (WP_CACHE is on) and slow requests still count toward error pressure. Set "Slow request" to 0 on the Settings tab.', 'bot-storm-radar' ); ?></div>
				<?php endif; ?>
				<div class="bsr-card-sub"><strong><?php esc_html_e( 'WooCommerce:', 'bot-storm-radar' ); ?></strong> <?php echo BSR_WooCommerce::active() ? esc_html__( 'module active', 'bot-storm-radar' ) : esc_html__( 'not present', 'bot-storm-radar' ); ?></div>
			</div>
			<?php endif; ?>
		</div>

		<h2><?php esc_html_e( 'Last 24 hours', 'bot-storm-radar' ); ?></h2>
		<?php self::render_chart( $rows, $opts ); ?>

		<div class="bsr-columns">
			<div>
				<h2><?php esc_html_e( 'Top classes, last hour', 'bot-storm-radar' ); ?></h2>
				<?php self::render_classes( $rows ); ?>
				<h2><?php esc_html_e( 'Good-bot claims, last 24 hours', 'bot-storm-radar' ); ?></h2>
				<?php self::render_bots( $rows, $source ); ?>
			</div>
			<div>
				<h2><?php esc_html_e( 'Top keys, last ten minutes', 'bot-storm-radar' ); ?></h2>
				<?php self::render_top_keys( $source ); ?>
			</div>
		</div>

		<h2><?php esc_html_e( 'Storm timeline', 'bot-storm-radar' ); ?></h2>
		<?php self::render_timeline( $state, $source ); ?>
		<?php
	}

	/**
	 * @param array $rows
	 * @param array $opts
	 */
	private static function render_chart( array $rows, array $opts ) {
		if ( empty( $rows ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing recorded yet.', 'bot-storm-radar' ) . '</p>';
			return;
		}
		$w      = 1440;
		$h      = 160;
		$end    = BSR_Helpers::minute() - 1;
		$start  = $end - 1439;
		$max_ip = 1;
		foreach ( $rows as $r ) {
			$max_ip = max( $max_ip, (int) $r['ips'] );
		}
		$bars   = '';
		$points = [];
		foreach ( $rows as $m => $r ) {
			$x  = (int) $m - $start;
			if ( $x < 0 || $x >= $w ) {
				continue;
			}
			$bh = (int) round( ( (int) $r['ips'] / $max_ip ) * ( $h - 20 ) );
			if ( $bh > 0 ) {
				$bars .= sprintf( '<rect x="%d" y="%d" width="1" height="%d"><title>%s</title></rect>', $x, $h - $bh, $bh, esc_attr( sprintf( '%s: %d addresses, %d requests, score %d', wp_date( 'H:i', (int) $m * 60 ), (int) $r['ips'], (int) $r['total'], (int) $r['score'] ) ) );
			}
			$points[] = sprintf( '%d,%d', $x, $h - (int) round( ( (int) $r['score'] / 100 ) * ( $h - 20 ) ) );
		}
		$warn_y  = $h - (int) round( ( (int) $opts['warning_threshold'] / 100 ) * ( $h - 20 ) );
		$storm_y = $h - (int) round( ( (int) $opts['storm_threshold'] / 100 ) * ( $h - 20 ) );
		?>
		<div class="bsr-chart-wrap">
			<svg class="bsr-chart" viewBox="0 0 <?php echo (int) $w; ?> <?php echo (int) $h; ?>" preserveAspectRatio="none" role="img" aria-label="<?php esc_attr_e( 'Distinct addresses per minute (bars) and storm score (line) over the last 24 hours', 'bot-storm-radar' ); ?>">
				<g class="bsr-bars"><?php echo $bars; // phpcs:ignore WordPress.Security.EscapeOutput ?></g>
				<line class="bsr-warn" x1="0" x2="<?php echo (int) $w; ?>" y1="<?php echo (int) $warn_y; ?>" y2="<?php echo (int) $warn_y; ?>" />
				<line class="bsr-storm" x1="0" x2="<?php echo (int) $w; ?>" y1="<?php echo (int) $storm_y; ?>" y2="<?php echo (int) $storm_y; ?>" />
				<?php if ( count( $points ) > 1 ) : ?>
					<polyline class="bsr-score" points="<?php echo esc_attr( implode( ' ', $points ) ); ?>" />
				<?php endif; ?>
			</svg>
			<div class="bsr-chart-legend">
				<span class="bsr-legend-bars"><?php printf( esc_html__( 'distinct addresses per minute (max %d)', 'bot-storm-radar' ), (int) $max_ip ); ?></span>
				<span class="bsr-legend-score"><?php esc_html_e( 'storm score (0 to 100)', 'bot-storm-radar' ); ?></span>
				<span class="bsr-legend-lines"><?php esc_html_e( 'warning and storm thresholds', 'bot-storm-radar' ); ?></span>
				<span><?php printf( esc_html__( '%1$s to %2$s', 'bot-storm-radar' ), esc_html( wp_date( 'D H:i', $start * 60 ) ), esc_html( wp_date( 'D H:i', $end * 60 ) ) ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $rows
	 */
	private static function render_classes( array $rows ) {
		$sum   = [];
		$total = 0;
		foreach ( array_slice( $rows, -60, null, true ) as $r ) {
			foreach ( (array) ( $r['classes'] ?? [] ) as $c => $n ) {
				$sum[ $c ] = ( $sum[ $c ] ?? 0 ) + (int) $n;
				$total    += (int) $n;
			}
		}
		if ( empty( $sum ) ) {
			echo '<p class="description">' . esc_html__( 'Nothing recorded in the last hour.', 'bot-storm-radar' ) . '</p>';
			return;
		}
		arsort( $sum );
		echo '<table class="widefat striped bsr-table"><thead><tr><th>' . esc_html__( 'Class', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Requests', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Share', 'bot-storm-radar' ) . '</th></tr></thead><tbody>';
		foreach ( $sum as $c => $n ) {
			printf( '<tr><td>%s</td><td>%d</td><td>%s%%</td></tr>', esc_html( $c ), (int) $n, esc_html( (string) round( 100 * $n / max( 1, $total ) ) ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array  $rows
	 * @param string $source
	 */
	private static function render_bots( array $rows, $source = BSR_Sources::SITE ) {
		$sum = [];
		foreach ( $rows as $r ) {
			foreach ( (array) ( $r['bots'] ?? [] ) as $b => $set ) {
				foreach ( (array) $set as $v => $n ) {
					$sum[ $b ][ $v ] = ( $sum[ $b ][ $v ] ?? 0 ) + (int) $n;
				}
			}
		}
		$bots = BSR_Good_Bots::bots();
		echo '<table class="widefat striped bsr-table"><thead><tr><th>' . esc_html__( 'Claimed', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Verified', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Fake', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Pending', 'bot-storm-radar' ) . '</th></tr></thead><tbody>';
		foreach ( $bots as $name => $bot ) {
			printf( '<tr><td>%s</td><td>%d</td><td class="%s">%d</td><td>%d</td></tr>', esc_html( $bot['label'] ), (int) ( $sum[ $name ]['verified'] ?? 0 ), ( (int) ( $sum[ $name ]['fake'] ?? 0 ) > 0 ? 'bsr-danger' : '' ), (int) ( $sum[ $name ]['fake'] ?? 0 ), (int) ( $sum[ $name ]['pending'] ?? 0 ) );
		}
		echo '</tbody></table>';
		if ( BSR_Sources::SITE !== $source ) {
			return; // The verification queue and verdicts below are the site's.
		}
		$pending = BSR_Good_Bots::pending_count();
		if ( $pending > 0 ) {
			echo '<p class="description">' . esc_html( sprintf( __( '%d addresses queued for DNS verification (up to 20 per minute).', 'bot-storm-radar' ), $pending ) ) . '</p>';
		}
		$recent = BSR_Good_Bots::recent();
		if ( $recent ) {
			echo '<p class="description">' . esc_html__( 'Recent verdicts:', 'bot-storm-radar' ) . ' ';
			$parts = [];
			foreach ( array_slice( $recent, 0, 10, true ) as $ip => $r ) {
				$parts[] = sprintf( '%s %s (%s)', $ip, $bots[ $r['bot'] ]['label'] ?? $r['bot'], $r['v'] );
			}
			echo esc_html( implode( ', ', $parts ) ) . '</p>';
		}
	}

	/**
	 * @param string $source
	 */
	private static function render_top_keys( $source = BSR_Sources::SITE ) {
		$rows = BSR_Storage::minutes_last( 10, null, $source );
		$out  = [ 'ips' => [], 'nets' => [], 'uas' => [] ];
		foreach ( $rows as $r ) {
			foreach ( [ 'ips', 'nets' ] as $kind ) {
				foreach ( (array) ( $r['hot'][ $kind ] ?? [] ) as $k => $n ) {
					$out[ $kind ][ $k ] = ( $out[ $kind ][ $k ] ?? 0 ) + (int) $n;
				}
			}
			foreach ( (array) ( $r['ua_top'] ?? [] ) as $pair ) {
				if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) ) {
					$out['uas'][ (string) $pair[0] ] = ( $out['uas'][ (string) $pair[0] ] ?? 0 ) + (int) $pair[1];
				}
			}
		}
		$titles = [ 'ips' => __( 'Addresses with 10 or more requests in a minute', 'bot-storm-radar' ), 'nets' => __( 'Networks (/24, /48) with 20 or more requests in a minute', 'bot-storm-radar' ), 'uas' => __( 'Busiest user agents', 'bot-storm-radar' ) ];
		foreach ( $out as $kind => $list ) {
			echo '<h3>' . esc_html( $titles[ $kind ] ) . '</h3>';
			if ( empty( $list ) ) {
				echo '<p class="description">' . esc_html__( 'None.', 'bot-storm-radar' ) . '</p>';
				continue;
			}
			arsort( $list );
			echo '<table class="widefat striped bsr-table"><tbody>';
			foreach ( array_slice( $list, 0, 15, true ) as $k => $n ) {
				printf( '<tr><td class="bsr-key">%s</td><td class="bsr-num">%d</td></tr>', esc_html( (string) $k ), (int) $n );
			}
			echo '</tbody></table>';
		}
	}

	/**
	 * @param array  $state
	 * @param string $source
	 */
	private static function render_timeline( array $state, $source = BSR_Sources::SITE ) {
		$labels = self::state_labels();
		if ( is_array( $state['last_storm'] ) ) {
			$ls = $state['last_storm'];
			printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Last storm:', 'bot-storm-radar' ), esc_html( sprintf( __( 'from %1$s to %2$s, peak score %3$d with %4$d addresses and %5$d requests in one minute, highest rung %6$d.', 'bot-storm-radar' ), wp_date( 'Y-m-d H:i', (int) $ls['started'] ), wp_date( 'Y-m-d H:i', (int) ( $ls['ended'] ?? time() ) ), (int) $ls['peak_score'], (int) $ls['peak_ips'], (int) $ls['peak_total'], (int) ( $ls['max_rung'] ?? 0 ) ) ) );
		}
		$log = BSR_Storage::transitions( 50, $source );
		if ( empty( $log ) ) {
			echo '<p class="description">' . esc_html__( 'No transitions yet.', 'bot-storm-radar' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped bsr-table"><thead><tr><th>' . esc_html__( 'When', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Transition', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Why', 'bot-storm-radar' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $t ) {
			if ( ! empty( $t['burst'] ) ) {
				printf(
					'<tr><td class="bsr-nowrap">%s</td><td class="bsr-nowrap"><span class="bsr-pill bsr-pill-burst">%s</span></td><td>%s</td></tr>',
					esc_html( wp_date( 'Y-m-d H:i', (int) $t['at'] ) ),
					esc_html__( '5xx burst', 'bot-storm-radar' ),
					esc_html( (string) $t['explanation'] )
				);
				continue;
			}
			printf(
				'<tr><td class="bsr-nowrap">%s</td><td class="bsr-nowrap"><span class="bsr-pill bsr-pill-%s">%s</span> → <span class="bsr-pill bsr-pill-%s">%s</span></td><td>%s</td></tr>',
				esc_html( wp_date( 'Y-m-d H:i', (int) $t['at'] ) ),
				esc_attr( $t['from'] ),
				esc_html( $labels[ $t['from'] ] ?? $t['from'] ),
				esc_attr( $t['to'] ),
				esc_html( $labels[ $t['to'] ] ?? $t['to'] ),
				esc_html( (string) $t['explanation'] )
			);
		}
		echo '</tbody></table>';
	}

	// ── Dashboard widget ────────────────────────────────────────────

	public static function add_dashboard_widget() {
		if ( current_user_can( self::CAP ) ) {
			wp_add_dashboard_widget( 'bsr_dashboard_widget', __( 'Bot Storm Radar', 'bot-storm-radar' ), [ __CLASS__, 'render_dashboard_widget' ] );
		}
	}

	public static function render_dashboard_widget() {
		$state  = BSR_Storm::get_state();
		$labels = self::state_labels();
		$rows   = BSR_Storage::minutes_last( 1 );
		$last   = $rows ? end( $rows ) : null;
		printf( '<p class="bsr-widget-state bsr-state-%1$s"><strong>%2$s</strong> <span>%3$s</span></p>', esc_attr( $state['state'] ), esc_html( $labels[ $state['state'] ] ?? $state['state'] ), esc_html( sprintf( __( 'since %s', 'bot-storm-radar' ), self::ago( (int) $state['since'] ) ) ) );
		if ( $last ) {
			printf( '<p>%s</p>', esc_html( sprintf( __( 'Last minute: score %1$d, %2$d requests from %3$d addresses.', 'bot-storm-radar' ), (int) $last['score'], (int) $last['total'], (int) $last['ips'] ) ) );
		}
		if ( is_array( $state['last_storm'] ) ) {
			$ls = $state['last_storm'];
			printf( '<p>%s</p>', esc_html( sprintf( __( 'Last storm: %1$s, peak score %2$d, %3$d addresses in one minute.', 'bot-storm-radar' ), wp_date( 'Y-m-d H:i', (int) $ls['started'] ), (int) $ls['peak_score'], (int) $ls['peak_ips'] ) ) );
		} else {
			echo '<p>' . esc_html__( 'No storm recorded yet.', 'bot-storm-radar' ) . '</p>';
		}
		if ( 'transient' === BSR_Counters::backend() ) {
			echo '<p class="bsr-danger">' . esc_html__( 'Counters are on the transient fallback (database). A persistent object cache or APCu is recommended.', 'bot-storm-radar' ) . '</p>';
		}
		foreach ( array_keys( BSR_Sources::log_sources() ) as $id ) {
			$st   = BSR_Storm::get_state( $id );
			$lr   = BSR_Sources::log_state( $id );
			$late = self::ingest_is_late( $lr );
			printf(
				'<p class="bsr-widget-source bsr-state-%1$s"><a href="%2$s">%3$s</a> <strong>%4$s</strong> <span>%5$s</span>%6$s</p>',
				esc_attr( $st['state'] ),
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&source=' . rawurlencode( $id ) ) ),
				esc_html( BSR_Sources::label( $id ) ),
				esc_html( $labels[ $st['state'] ] ?? $st['state'] ),
				esc_html( sprintf( __( 'since %s', 'bot-storm-radar' ), self::ago( (int) $st['since'] ) ) ),
				$late ? ' <span class="bsr-danger">' . esc_html( sprintf( __( 'log not read for %s', 'bot-storm-radar' ), human_time_diff( (int) $lr['last_run'], time() ) ) ) . '</span>' : ''
			);
		}
		printf( '<p><a href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ), esc_html__( 'Open the radar', 'bot-storm-radar' ) );
	}

	// ── Log sources ─────────────────────────────────────────────────

	/**
	 * The card that replaces Status for a log source: its files, the last
	 * ingest, the alert recipients, and per-source resets.
	 *
	 * @param string $source
	 */
	private static function render_log_card( $source ) {
		$def   = BSR_Sources::log_sources()[ $source ] ?? [];
		$lr    = BSR_Sources::log_state( $source );
		$stats = (array) $lr['stats'];
		$late  = self::ingest_is_late( $lr );
		$extra = [ 'tab' => 'radar', 'source' => $source ];
		?>
		<div class="bsr-card">
			<div class="bsr-card-label"><?php esc_html_e( 'Log reader', 'bot-storm-radar' ); ?></div>
			<div class="bsr-card-sub"><strong><?php esc_html_e( 'Files:', 'bot-storm-radar' ); ?></strong> <span class="bsr-key"><?php echo esc_html( implode( ', ', (array) ( $def['logs'] ?? [] ) ) ); ?></span> (<?php echo esc_html( (string) ( $def['profile'] ?? '' ) ); ?>)</div>
			<div class="bsr-card-sub <?php echo $late ? 'bsr-danger' : ''; ?>"><strong><?php esc_html_e( 'Ingest:', 'bot-storm-radar' ); ?></strong>
				<?php
				if ( (int) $lr['last_run'] > 0 ) {
					printf( esc_html__( 'last ran %s', 'bot-storm-radar' ), esc_html( self::ago( (int) $lr['last_run'] ) ) );
					if ( isset( $stats['lines'] ) ) {
						printf( ', ' . esc_html__( '%1$d lines, %2$d minutes, %3$d late, %4$d unparsed, %5$d refused', 'bot-storm-radar' ), (int) $stats['lines'], (int) ( $stats['minutes'] ?? 0 ), (int) ( $stats['late'] ?? 0 ), (int) ( $stats['bad'] ?? 0 ), (int) ( $stats['refused'] ?? 0 ) );
					}
					if ( ! empty( $stats['events'] ) ) {
						$ev = [];
						foreach ( (array) $stats['events'] as $path => $e ) {
							$ev[] = basename( (string) $path ) . ' ' . $e;
						}
						echo ', ' . esc_html( implode( ', ', $ev ) );
					}
					if ( $late ) {
						echo ' ' . esc_html__( 'The log has not been read for more than five minutes; check the system timer that runs wp bot-storm-radar ingest.', 'bot-storm-radar' );
					}
				} else {
					esc_html_e( 'never ran yet', 'bot-storm-radar' );
				}
				?>
			</div>
			<div class="bsr-card-sub"><strong><?php esc_html_e( 'Alerts:', 'bot-storm-radar' ); ?></strong>
				<?php
				$to = BSR_Sources::alert_recipients( $source );
				echo esc_html( empty( $to ) ? __( 'no recipients', 'bot-storm-radar' ) : implode( ', ', $to ) );
				if ( '' === (string) ( $def['alert_to'] ?? '' ) ) {
					echo ' ' . esc_html__( '(the site\'s recipients)', 'bot-storm-radar' );
				}
				if ( ! BSR_Sources::alerts_ready( $source ) ) {
					echo '. ' . esc_html__( 'Mailing starts once the baseline holds one full day; transitions are logged meanwhile.', 'bot-storm-radar' );
				}
				?>
			</div>
			<div class="bsr-card-sub">
				<a href="<?php echo esc_url( self::action_url( 'reset_state', $extra ) ); ?>"><?php esc_html_e( 'Reset state to calm', 'bot-storm-radar' ); ?></a> |
				<a href="<?php echo esc_url( self::action_url( 'reset_baseline', $extra ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Forget this source\'s learned baseline and start the seven-day learning period again?', 'bot-storm-radar' ) ); ?>');"><?php esc_html_e( 'Reset the baseline', 'bot-storm-radar' ); ?></a>
			</div>
		</div>
		<?php
	}

	/**
	 * Read-only list of log sources on the Settings tab. Sources are defined
	 * with WP-CLI, never from this screen.
	 */
	private static function render_log_sources_table() {
		$all = BSR_Sources::log_sources();
		echo '<h2>' . esc_html__( 'Log sources', 'bot-storm-radar' ) . '</h2>';
		if ( empty( $all ) ) {
			echo '<p class="description">' . esc_html__( 'None. Traffic that never reaches WordPress (another application on the server, requests answered by a page cache) can be read from the web-server access log with wp bot-storm-radar source add.', 'bot-storm-radar' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped bsr-table"><thead><tr><th>' . esc_html__( 'Source', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Profile', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Files', 'bot-storm-radar' ) . '</th><th>' . esc_html__( 'Alert recipients', 'bot-storm-radar' ) . '</th></tr></thead><tbody>';
		foreach ( $all as $id => $def ) {
			printf(
				'<tr><td><a href="%1$s">%2$s</a> <code>%3$s</code></td><td>%4$s</td><td class="bsr-key">%5$s</td><td>%6$s</td></tr>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&source=' . rawurlencode( $id ) ) ),
				esc_html( BSR_Sources::label( $id ) ),
				esc_html( $id ),
				esc_html( (string) ( $def['profile'] ?? '' ) ),
				esc_html( implode( ', ', (array) ( $def['logs'] ?? [] ) ) ),
				esc_html( implode( ', ', BSR_Sources::alert_recipients( $id ) ) )
			);
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Add, change or remove sources with wp bot-storm-radar source add and source remove.', 'bot-storm-radar' ) . '</p>';
	}

	/**
	 * A page-cache drop-in is active and slow requests still count. Behind a
	 * page cache PHP mostly sees cache misses, so "slow" measures the cache.
	 *
	 * @return bool
	 */
	private static function page_cache_counts_slow() {
		return defined( 'WP_CACHE' ) && WP_CACHE && (int) BSR_Helpers::opt( 'slow_request_ms', 2000 ) > 0;
	}

	/**
	 * @param array $lr BSR_Sources::log_state()
	 * @return bool
	 */
	private static function ingest_is_late( array $lr ) {
		return (int) $lr['last_run'] > 0 && ( time() - (int) $lr['last_run'] ) > 300;
	}

	// ── Small helpers ───────────────────────────────────────────────

	private static function state_labels() {
		return [
			'calm'    => __( 'Calm', 'bot-storm-radar' ),
			'warning' => __( 'Warning', 'bot-storm-radar' ),
			'storm'   => __( 'Storm', 'bot-storm-radar' ),
			'cooling' => __( 'Cooling', 'bot-storm-radar' ),
		];
	}

	/**
	 * @param array $b
	 * @return string
	 */
	private static function baseline_sentence( array $b ) {
		if ( empty( $b['days'] ) ) {
			return __( 'Learning: no full day recorded yet. Until then the minimum-addresses setting is the reference volume.', 'bot-storm-radar' );
		}
		$asset = null === ( $b['asset_median'] ?? null ) ? __( 'asset ratio not yet measurable', 'bot-storm-radar' ) : sprintf( __( 'normal asset ratio %s', 'bot-storm-radar' ), BSR_Metrics::fmt( $b['asset_median'] ) );
		if ( 'learned' === ( $b['status'] ?? '' ) ) {
			return sprintf( __( 'Learned over %1$d days: median %2$s distinct addresses per minute (90th percentile %3$s), %4$s.', 'bot-storm-radar' ), (int) $b['days'], BSR_Metrics::fmt( $b['ips_median'], 0 ), BSR_Metrics::fmt( $b['ips_p90'], 0 ), $asset );
		}
		return sprintf( __( 'Learning, %1$d of 7 days: so far median %2$s distinct addresses per minute (90th percentile %3$s), %4$s.', 'bot-storm-radar' ), (int) $b['days'], BSR_Metrics::fmt( $b['ips_median'], 0 ), BSR_Metrics::fmt( $b['ips_p90'], 0 ), $asset );
	}

	/**
	 * @param mixed $v
	 * @param int   $d
	 * @return string
	 */
	private static function fmt_or_dash( $v, $d = 2 ) {
		return null === $v ? __( 'not learned yet', 'bot-storm-radar' ) : BSR_Metrics::fmt( $v, $d );
	}

	/**
	 * @param int $ts
	 * @return string
	 */
	private static function ago( $ts ) {
		$ts = (int) $ts;
		if ( $ts <= 0 ) {
			return __( 'never', 'bot-storm-radar' );
		}
		$now = time();
		if ( $ts > $now ) {
			return sprintf( __( 'in %s', 'bot-storm-radar' ), human_time_diff( $now, $ts ) );
		}
		return sprintf( __( '%s ago', 'bot-storm-radar' ), human_time_diff( $ts, $now ) );
	}

	private static function css() {
		return '
.bsr-header{display:flex;align-items:center;gap:12px;margin:12px 0 8px}
.bsr-header-icon{font-size:40px;width:40px;height:40px;color:#2271b1}
.bsr-header h1{margin:0;padding:0;line-height:1.2}
.bsr-version{color:#646970;font-size:12px}
.bsr-intro{max-width:900px;color:#3c434a;margin:8px 0 16px}
.bsr-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin:16px 0}
.bsr-card{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px 14px}
.bsr-card-label{text-transform:uppercase;font-size:11px;letter-spacing:.04em;color:#646970;margin-bottom:6px}
.bsr-card-sub{color:#3c434a;font-size:13px;margin:3px 0}
.bsr-big{font-size:32px;font-weight:600;line-height:1.1;margin:2px 0 6px}
.bsr-big small{font-size:13px;font-weight:400;color:#646970}
.bsr-state-name{font-size:28px;font-weight:700;line-height:1.1;margin:2px 0 6px}
.bsr-state-calm .bsr-state-name{color:#00a32a}
.bsr-state-warning .bsr-state-name{color:#dba617}
.bsr-state-storm .bsr-state-name{color:#d63638}
.bsr-state-cooling .bsr-state-name{color:#2271b1}
.bsr-danger{color:#d63638}
.bsr-baseline{font-style:italic}
.bsr-wrap input[type=number].small-text{width:6em}
.bsr-chart-wrap{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:10px}
.bsr-chart{width:100%;height:180px;display:block}
.bsr-bars rect{fill:#a7c7e7}
.bsr-score{fill:none;stroke:#d63638;stroke-width:1.5;vector-effect:non-scaling-stroke}
.bsr-warn{stroke:#dba617;stroke-width:1;stroke-dasharray:4 4;vector-effect:non-scaling-stroke}
.bsr-storm{stroke:#d63638;stroke-width:1;stroke-dasharray:4 4;vector-effect:non-scaling-stroke}
.bsr-chart-legend{display:flex;gap:18px;flex-wrap:wrap;font-size:12px;color:#646970;margin-top:6px}
.bsr-legend-bars::before{content:"";display:inline-block;width:10px;height:10px;background:#a7c7e7;margin-right:4px}
.bsr-legend-score::before{content:"";display:inline-block;width:14px;height:2px;background:#d63638;margin-right:4px;vertical-align:middle}
.bsr-legend-lines::before{content:"";display:inline-block;width:14px;border-top:1px dashed #dba617;margin-right:4px;vertical-align:middle}
.bsr-columns{display:grid;grid-template-columns:1fr 1fr;gap:24px}
@media (max-width:1100px){.bsr-columns{grid-template-columns:1fr}}
.bsr-table{margin-bottom:12px}
.bsr-table .bsr-key{font-family:monospace;font-size:12px;word-break:break-all}
.bsr-table .bsr-num{text-align:right;width:80px}
.bsr-nowrap{white-space:nowrap}
.bsr-pill{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;color:#fff;background:#646970}
.bsr-pill-calm{background:#00a32a}.bsr-pill-warning{background:#dba617}.bsr-pill-storm{background:#d63638}.bsr-pill-cooling{background:#2271b1}.bsr-pill-burst{background:#b32d2e}
.bsr-widget-state strong{font-size:18px}
.bsr-sources{margin:8px 0 0}.bsr-sources a.current{font-weight:600}
.bsr-widget-source.bsr-state-calm strong{color:#00a32a}.bsr-widget-source.bsr-state-warning strong{color:#dba617}.bsr-widget-source.bsr-state-storm strong{color:#d63638}.bsr-widget-source.bsr-state-cooling strong{color:#2271b1}
.bsr-widget-state.bsr-state-calm strong{color:#00a32a}.bsr-widget-state.bsr-state-warning strong{color:#dba617}.bsr-widget-state.bsr-state-storm strong{color:#d63638}.bsr-widget-state.bsr-state-cooling strong{color:#2271b1}
';
	}
}
