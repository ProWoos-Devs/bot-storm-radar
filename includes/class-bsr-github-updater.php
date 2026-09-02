<?php
/**
 * GitHub Updater for Bot Storm Radar
 *
 * Hooks into WordPress's native plugin update system to check for
 * new releases on GitHub and enable one-click updates from wp-admin.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_GitHub_Updater {

	const SLUG         = 'bot-storm-radar';
	const REPO         = 'ProWoos-Devs/bot-storm-radar';
	const ASSETS_URL   = 'https://raw.githubusercontent.com/ProWoos-Devs/bot-storm-radar/main/assets';
	const CACHE_KEY    = 'bsr_update_data';
	const CACHE_EXPIRY = 43200; // 12 hours

	/**
	 * Plugin basename (e.g. bot-storm-radar/bot-storm-radar.php)
	 *
	 * @var string
	 */
	private $plugin_basename;

	public function __construct() {
		$this->plugin_basename = BSR_PLUGIN_BASENAME;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 2 );

		// Clear cache on force-check.
		if ( is_admin() && isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Check GitHub for a newer release
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->get_remote_data();

		if ( ! $remote || empty( $remote['version'] ) ) {
			return $transient;
		}

		$current_version = $transient->checked[ $this->plugin_basename ] ?? BSR_VERSION;

		$icons = [
			'1x' => self::ASSETS_URL . '/icon-128x128.png',
			'2x' => self::ASSETS_URL . '/icon-256x256.png',
		];

		if ( version_compare( $remote['version'], $current_version, '>' ) ) {
			$transient->response[ $this->plugin_basename ] = (object) [
				'slug'         => self::SLUG,
				'plugin'       => $this->plugin_basename,
				'new_version'  => $remote['version'],
				'url'          => 'https://github.com/' . self::REPO,
				'package'      => $remote['package'],
				'tested'       => $remote['tested'] ?? '',
				'requires_php' => $remote['requires_php'] ?? '7.4',
				'requires'     => $remote['requires'] ?? '6.0',
				'icons'        => $icons,
			];
		} else {
			// Tell WP we checked and it's up to date (prevents WP.org lookup).
			$transient->no_update[ $this->plugin_basename ] = (object) [
				'slug'        => self::SLUG,
				'plugin'      => $this->plugin_basename,
				'new_version' => $current_version,
				'url'         => 'https://github.com/' . self::REPO,
				'icons'       => $icons,
			];
		}

		return $transient;
	}

	/**
	 * Provide plugin info for the "View Details" popup
	 *
	 * @param false|object $result Default result.
	 * @param string       $action API action.
	 * @param object       $args   Request args.
	 * @return false|object
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}

		if ( ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		$remote = $this->get_remote_data();

		if ( ! $remote || empty( $remote['version'] ) ) {
			return $result;
		}

		$changelog = '';
		if ( ! empty( $remote['changelog'] ) ) {
			$body      = esc_html( $remote['changelog'] );
			$body      = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $body );
			$body      = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $body );
			$body      = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $body );
			$body      = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $body );
			$body      = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $body );
			$changelog = nl2br( $body );
		}

		return (object) [
			'name'          => 'Bot Storm Radar',
			'slug'          => self::SLUG,
			'version'       => $remote['version'],
			'author'        => '<a href="https://github.com/ProWoos-Devs">ProWoos</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => $remote['requires'] ?? '6.0',
			'tested'        => $remote['tested'] ?? '',
			'requires_php'  => $remote['requires_php'] ?? '7.4',
			'last_updated'  => $remote['updated'] ?? '',
			'download_link' => $remote['package'] ?? '',
			'banners'       => [
				'low'  => self::ASSETS_URL . '/banner-772x250.png',
				'high' => self::ASSETS_URL . '/banner-1544x500.png',
			],
			'icons'         => [
				'1x' => self::ASSETS_URL . '/icon-128x128.png',
				'2x' => self::ASSETS_URL . '/icon-256x256.png',
			],
			'sections'      => [
				'description' => 'Detects bot swarms as swarms: request classes, sliding-window counters, swarm metrics, a learned baseline, a storm state machine and email alerts. Radar only in 0.1, nothing is blocked.',
				'changelog'   => $changelog,
			],
		];
	}

	/**
	 * Clear cache after plugin upgrades
	 *
	 * @param object $upgrader   Upgrader instance.
	 * @param array  $hook_extra Hook data.
	 */
	public function clear_cache( $upgrader, $hook_extra ) {
		if (
			isset( $hook_extra['action'], $hook_extra['type'] ) &&
			'update' === $hook_extra['action'] &&
			'plugin' === $hook_extra['type']
		) {
			delete_transient( self::CACHE_KEY );
		}
	}

	/**
	 * Fetch latest release data from GitHub API (cached 12h)
	 *
	 * @return array|false
	 */
	private function get_remote_data() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return $cached;
		}

		$url      = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'BotStormRadar/' . BSR_VERSION . ' WordPress/' . get_bloginfo( 'version' ),
			],
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$release = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			return false;
		}

		$version = ltrim( $release['tag_name'], 'v' );

		// Look for a .zip asset; fall back to the GitHub source zipball.
		$package = $release['zipball_url'] ?? '';
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( '.zip' === substr( $asset['name'], -4 ) ) {
					$package = $asset['browser_download_url'];
					break;
				}
			}
		}

		$data = [
			'version'      => $version,
			'package'      => $package,
			'changelog'    => $release['body'] ?? '',
			'updated'      => $release['published_at'] ?? '',
			'tested'       => '',
			'requires'     => '6.0',
			'requires_php' => '7.4',
		];

		set_transient( self::CACHE_KEY, $data, self::CACHE_EXPIRY );

		return $data;
	}
}
