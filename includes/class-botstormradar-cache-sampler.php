<?php
/**
 * Requests a small sample of the site's own public pages and hands what came
 * back to BotStormRadar_Cache_Check (issue #86).
 *
 * It sends nothing anywhere except to the site's own URLs, adds no cache
 * buster and no no-cache header, so it sees what a first-time visitor sees,
 * and it stores cookie names only. A cookie value lives in memory for the
 * replay and is never written. Requests run from the daily cron hook or when
 * an administrator asks, never during a visitor's request, and the daily run
 * waits while the radar reports anything but calm.
 *
 * Many servers map their own domain to themselves in /etc/hosts, so a request
 * from the server never meets the CDN in front of it. When the system's answer
 * for the host is not among its DNS records, the request connects to the public
 * address instead (cURL only). Where DNS itself answers differently inside the
 * network, the check still cannot see the CDN and reports "could not tell".
 *
 * Whatever goes wrong (a timeout, a firewall refusal, a redirect off the site)
 * becomes "no answer" for that page, which reports nothing.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Cache_Sampler {

	const OPTION    = 'botstormradar_cache_check';
	const DISMISSED = 'botstormradar_cache_check_dismissed';
	const LOCK      = 'g:cache_check_lock';
	const LOCK_TTL  = 120;

	/**
	 * Seconds for one request, and for the whole run. No new request starts
	 * after the budget, so a run ends within BUDGET + TIMEOUT.
	 */
	const TIMEOUT = 8;
	const BUDGET  = 30;

	/**
	 * A cache can answer a second request before it finished storing the
	 * first, so a page that misses twice gets a third request before anyone
	 * calls it uncached.
	 */
	const MAX_REQUESTS = 3;
	const GAP          = 1;

	const MAX_REDIRECTS = 3;
	const MAX_BODY      = 2097152;

	/**
	 * The daily hook can fire again soon after a reschedule.
	 */
	const MIN_AGE = 72000;

	/**
	 * @var callable|null Tests: callable( string $host ): array system (string), dns (string[]).
	 */
	public static $lookup = null;

	/**
	 * @var array host => public address to connect to, '' for the normal route.
	 */
	private static $routes = [];

	public static function init() {
		add_action( BotStormRadar_Client_IP::CRON_HOOK, [ __CLASS__, 'daily' ], 60, 0 );
	}

	/**
	 * The daily run. During a storm the requests would add load, and the
	 * answers would say more about the storm than about the cache.
	 */
	public static function daily() {
		if ( self::is_dismissed() ) {
			return;
		}
		$last = self::last();
		if ( $last && time() - (int) $last['time'] < self::MIN_AGE ) {
			return;
		}
		$state = BotStormRadar_Storm::get_state();
		if ( 'calm' !== $state['state'] ) {
			return;
		}
		self::run( 'daily' );
	}

	/**
	 * @return array|null The stored result: BotStormRadar_Cache_Check::analyze_site() plus time and trigger.
	 */
	public static function last() {
		$result = get_option( self::OPTION, null );
		return is_array( $result ) && isset( $result['time'] ) ? $result : null;
	}

	/**
	 * Whether the owner said this site is uncached on purpose. It stays that
	 * way until they undo it, and no daily requests run meanwhile.
	 *
	 * @return bool
	 */
	public static function is_dismissed() {
		return (bool) get_option( self::DISMISSED, false );
	}

	/**
	 * @param bool $dismissed
	 */
	public static function set_dismissed( $dismissed ) {
		if ( $dismissed ) {
			update_option( self::DISMISSED, 1, false );
		} else {
			delete_option( self::DISMISSED );
		}
	}

	/**
	 * Sample and store. Returns null when another run holds the lock.
	 *
	 * @param string $trigger daily or admin.
	 * @return array|null
	 */
	public static function run( $trigger ) {
		if ( ! BotStormRadar_Counters::add_value( self::LOCK, 1, self::LOCK_TTL ) ) {
			return null;
		}
		$result            = self::sample();
		$result['time']    = time();
		$result['trigger'] = (string) $trigger;
		update_option( self::OPTION, $result, false );
		BotStormRadar_Counters::delete( self::LOCK );
		return $result;
	}

	/**
	 * Request the pages and analyze them. Stores nothing.
	 *
	 * @param int|float $budget Seconds.
	 * @return array See BotStormRadar_Cache_Check::analyze_site().
	 */
	public static function sample( $budget = self::BUDGET ) {
		$deadline     = microtime( true ) + $budget;
		self::$routes = [];
		/**
		 * Readers for cache headers the plugin does not know.
		 *
		 * @param array $readers header name => callable( string $value ): string, one of the BotStormRadar_Cache_Check states.
		 */
		$custom  = (array) apply_filters( 'botstormradar_cache_check_readers', [] );
		$samples = [];
		$seen    = [];
		$cookies = [];

		foreach ( self::pick_pages() as $kind => $url ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			$sample = self::sample_page( $kind, $url, $custom, $deadline, $seen, $cookies );
			if ( $sample ) {
				$samples[] = $sample;
			}
		}

		$site   = BotStormRadar_Cache_Check::analyze_site( $samples, null, $custom );
		$replay = $cookies ? self::replay( $site['pages'], $cookies, $deadline ) : null;
		return $replay ? BotStormRadar_Cache_Check::analyze_site( $samples, $replay, $custom ) : $site;
	}

	/**
	 * The pages to sample, kind => URL: home, shop, one product, one category
	 * and one post, where the site has them.
	 *
	 * @return array
	 */
	public static function pick_pages() {
		$pages = [ 'home' => home_url( '/' ) ];

		if ( function_exists( 'wc_get_page_permalink' ) && function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
			$pages['shop'] = wc_get_page_permalink( 'shop' );
		}
		if ( post_type_exists( 'product' ) ) {
			$pages['product'] = self::latest_permalink( 'product' );
		}
		$taxonomy          = taxonomy_exists( 'product_cat' ) ? 'product_cat' : 'category';
		$pages['category'] = self::busiest_term_link( $taxonomy );
		$pages['post']     = self::latest_permalink( 'post' );

		/**
		 * The pages the cache check requests. URLs off this site are dropped.
		 *
		 * @param array $pages kind => URL.
		 */
		$pages = (array) apply_filters( 'botstormradar_cache_check_pages', $pages );

		$out = [];
		foreach ( $pages as $kind => $url ) {
			if ( is_string( $url ) && '' !== $url && self::same_site( $url ) ) {
				$out[ (string) $kind ] = $url;
			}
		}
		return $out;
	}

	/**
	 * @param string $post_type
	 * @return string
	 */
	private static function latest_permalink( $post_type ) {
		$ids = get_posts(
			[
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'has_password' => false,
				'numberposts'  => 1,
				'fields'       => 'ids',
			]
		);
		return $ids ? (string) get_permalink( (int) $ids[0] ) : '';
	}

	/**
	 * @param string $taxonomy
	 * @return string
	 */
	private static function busiest_term_link( $taxonomy ) {
		$terms = get_terms(
			[
				'taxonomy'   => $taxonomy,
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			]
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return '';
		}
		$link = get_term_link( $terms[0] );
		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/**
	 * Request one page until the answer is clear.
	 *
	 * @param string $kind
	 * @param string $url
	 * @param array  $custom
	 * @param float  $deadline
	 * @param array  $seen    Final URLs already sampled. A shop page can be the home page.
	 * @param array  $cookies The cookies handed out so far, name => value.
	 * @return array|null A sample for analyze_page(), or null when this URL was already sampled.
	 */
	private static function sample_page( $kind, $url, array $custom, $deadline, array &$seen, array &$cookies ) {
		$followed = self::follow( $url );
		$final    = $followed['url'];
		if ( isset( $seen[ $final ] ) ) {
			return null;
		}
		$seen[ $final ] = true;

		$sample   = [
			'kind'      => $kind,
			'url'       => $final,
			'has_nonce' => false,
			'requests'  => [],
		];
		$response = $followed['response'];

		for ( $i = 1; $i <= self::MAX_REQUESTS; $i++ ) {
			$request              = self::to_request( $response );
			$sample['requests'][] = $request;
			if ( ! isset( $request['status'] ) || 200 !== $request['status'] ) {
				return $sample;
			}
			$sample['has_nonce'] = $sample['has_nonce'] || BotStormRadar_Cache_Check::body_has_nonce( wp_remote_retrieve_body( $response ) );
			self::collect_cookies( $response, $cookies );

			$read = BotStormRadar_Cache_Check::read_response( $request['headers'], $custom );
			if ( BotStormRadar_Cache_Check::HIT === $read['state'] || ( $i >= 2 && BotStormRadar_Cache_Check::MISS !== $read['state'] ) ) {
				return $sample;
			}
			if ( self::MAX_REQUESTS === $i ) {
				break;
			}
			if ( microtime( true ) + self::GAP >= $deadline ) {
				// One response that is no hit proves nothing about the cache.
				if ( 1 === $i ) {
					$sample['requests'] = [];
				}
				return $sample;
			}
			sleep( self::GAP );
			$response = self::get( $final );
		}
		return $sample;
	}

	/**
	 * Send the cookies back to a page that was cached without them. The first
	 * answer can miss on a cache that keeps a copy per cookie, so the second
	 * one counts: a miss then means the cookie really leaves the cache.
	 *
	 * @param array $pages    analyze_site() pages.
	 * @param array $cookies  name => value.
	 * @param float $deadline
	 * @return array|null See analyze_site().
	 */
	private static function replay( array $pages, array $cookies, $deadline ) {
		foreach ( $pages as $page ) {
			if ( BotStormRadar_Cache_Check::PAGE_CACHED !== $page['outcome'] ) {
				continue;
			}
			if ( microtime( true ) + self::GAP >= $deadline ) {
				return null;
			}
			$request = self::to_request( self::get( $page['url'], $cookies ) );
			if ( isset( $request['status'] ) && 200 === $request['status'] ) {
				sleep( self::GAP );
				$request = self::to_request( self::get( $page['url'], $cookies ) );
			}
			return [
				'kind'     => $page['kind'],
				'cookies'  => array_keys( $cookies ),
				'response' => $request,
			];
		}
		return null;
	}

	/**
	 * Request a URL, following redirects by hand: a home page can redirect to
	 * a language path, and a redirect off the site or to a login page has to
	 * stay what it is, an answer that says nothing about caching.
	 *
	 * @param string $url
	 * @return array url, response
	 */
	private static function follow( $url ) {
		$response = self::get( $url );
		for ( $hop = 0; $hop < self::MAX_REDIRECTS; $hop++ ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( is_wp_error( $response ) || $code < 300 || $code >= 400 ) {
				break;
			}
			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;
			if ( '' === $location ) {
				break;
			}
			$next = WP_Http::make_absolute_url( $location, $url );
			if ( ! self::same_site( $next ) || false !== stripos( $next, 'wp-login.php' ) ) {
				break;
			}
			$url      = $next;
			$response = self::get( $url );
		}
		return [
			'url'      => $url,
			'response' => $response,
		];
	}

	/**
	 * One request, the way core's Site Health loopback makes it.
	 *
	 * @param string $url
	 * @param array  $cookies name => value.
	 * @return array|WP_Error
	 */
	private static function get( $url, array $cookies = [] ) {
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$address = self::public_address( $host );
		$connect = null;
		if ( '' !== $address && defined( 'CURLOPT_RESOLVE' ) ) {
			$port    = wp_parse_url( $url, PHP_URL_PORT );
			$port    = $port ? (int) $port : ( 'http' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 80 : 443 );
			$connect = static function ( $handle ) use ( $host, $port, $address ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $address ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- http_api_curl hands over the handle, and the HTTP API has no way to choose the address.
			};
			add_action( 'http_api_curl', $connect );
		}
		$response = self::request( $url, $cookies );
		if ( $connect ) {
			remove_action( 'http_api_curl', $connect );
		}
		return $response;
	}

	/**
	 * The address to connect to when the system's answer for a host is not
	 * among its public DNS records, as with a hosts-file entry.
	 *
	 * @param string $host
	 * @return string '' for the normal route.
	 */
	public static function public_address( $host ) {
		if ( isset( self::$routes[ $host ] ) ) {
			return self::$routes[ $host ];
		}
		if ( self::$lookup ) {
			$found = call_user_func( self::$lookup, $host );
		} else {
			$records = function_exists( 'dns_get_record' ) ? @dns_get_record( $host, DNS_A ) : []; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a failed lookup only means the normal route.
			$found   = [
				'system' => gethostbyname( $host ),
				'dns'    => is_array( $records ) ? array_column( $records, 'ip' ) : [],
			];
		}
		$address = '';
		if ( $found['dns'] && ! in_array( $found['system'], $found['dns'], true ) && BotStormRadar_Helpers::is_public_ip( $found['dns'][0] ) ) {
			$address = (string) $found['dns'][0];
		}
		self::$routes[ $host ] = $address;
		return $address;
	}

	/**
	 * @param string $url
	 * @param array  $cookies
	 * @return array|WP_Error
	 */
	private static function request( $url, array $cookies ) {
		return wp_safe_remote_get(
			$url,
			[
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false, $url ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's filter.
				'limit_response_size' => self::MAX_BODY,
				'headers'             => [ 'Accept' => 'text/html' ],
				'cookies'             => $cookies,
			]
		);
	}

	/**
	 * @param array|WP_Error $response
	 * @return array status, headers; or error
	 */
	private static function to_request( $response ) {
		if ( is_wp_error( $response ) ) {
			return [ 'error' => $response->get_error_code() ];
		}
		$headers = wp_remote_retrieve_headers( $response );
		return [
			'status'  => (int) wp_remote_retrieve_response_code( $response ),
			'headers' => is_object( $headers ) && method_exists( $headers, 'getAll' ) ? $headers->getAll() : (array) $headers,
		];
	}

	/**
	 * Keep what a browser would keep: a cookie being deleted is not sent back.
	 *
	 * @param array $response
	 * @param array $cookies name => value.
	 */
	private static function collect_cookies( $response, array &$cookies ) {
		foreach ( wp_remote_retrieve_cookies( $response ) as $cookie ) {
			if ( ! $cookie instanceof WP_Http_Cookie || '' === (string) $cookie->name ) {
				continue;
			}
			$gone = '' === (string) $cookie->value || 'deleted' === $cookie->value || ( ! empty( $cookie->expires ) && (int) $cookie->expires <= time() );
			if ( $gone ) {
				unset( $cookies[ $cookie->name ] );
			} else {
				$cookies[ $cookie->name ] = (string) $cookie->value;
			}
		}
	}

	/**
	 * Same host as the site, with or without www.
	 *
	 * @param string $url
	 * @return bool
	 */
	private static function same_site( $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		if ( ! is_string( $host ) || ! is_string( $home ) ) {
			return false;
		}
		return preg_replace( '/^www\./', '', strtolower( $host ) ) === preg_replace( '/^www\./', '', strtolower( $home ) );
	}
}
