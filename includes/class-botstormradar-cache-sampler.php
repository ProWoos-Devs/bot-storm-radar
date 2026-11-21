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
 * Requests take the system's normal route to the site, as core's loopback
 * does. Where that route skips the CDN in front of the site (a hosts-file
 * entry, a DNS view inside the network), the check cannot see the CDN and
 * reports "could not tell".
 *
 * Whatever goes wrong (a timeout, a firewall refusal, a certificate that does
 * not verify, a redirect off the site) becomes "no answer" for that page,
 * which reports nothing.
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
	 * Seconds for one request, and for the whole run. Every request, redirects
	 * and replays included, gets at most the time left before the deadline,
	 * and none starts with less than MIN_LEFT left.
	 */
	const TIMEOUT  = 8;
	const BUDGET   = 30;
	const MIN_LEFT = 1;

	/**
	 * The error a request skipped for lack of time returns.
	 */
	const OUT_OF_TIME = 'botstormradar_out_of_time';

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

	// github-build-only:start
	/**
	 * @var callable|null Tests: callable( string $host ): array system (string), dns (string[]).
	 */
	public static $lookup = null;

	/**
	 * @var array host => public address to connect to, '' for the normal route.
	 */
	private static $routes = [];
	// github-build-only:end

	public static function init() {
		add_action( BotStormRadar_Client_IP::CRON_HOOK, [ __CLASS__, 'daily' ], 60, 0 );
	}

	/**
	 * The daily run. During a storm the requests would add load, and the
	 * answers would say more about the storm than about the cache.
	 */
	public static function daily() {
		if ( ! self::daily_enabled() || self::is_dismissed() ) {
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
	 * Whether the daily run is switched on (`cache_check_daily`, default in
	 * Bot_Storm_Radar::get_default_options()). Check now runs either way.
	 *
	 * @return bool
	 */
	public static function daily_enabled() {
		return ! empty( BotStormRadar_Helpers::opt( 'cache_check_daily', 0 ) );
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
		// github-build-only:start
		self::$routes = [];
		// github-build-only:end
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
	 * @param array  $cookies The cookies handed out so far, see collect_cookies().
	 * @return array|null A sample for analyze_page(), or null when this URL was already sampled.
	 */
	private static function sample_page( $kind, $url, array $custom, $deadline, array &$seen, array &$cookies ) {
		$followed = self::follow( $url, $deadline );
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
			if ( self::out_of_time( $response ) ) {
				// One response that is no hit proves nothing about the cache.
				if ( 2 === $i ) {
					$sample['requests'] = [];
				}
				return $sample;
			}
			$request              = self::to_request( $response );
			$sample['requests'][] = $request;
			if ( ! isset( $request['status'] ) || 200 !== $request['status'] ) {
				return $sample;
			}
			$sample['has_nonce'] = $sample['has_nonce'] || BotStormRadar_Cache_Check::body_has_nonce( wp_remote_retrieve_body( $response ) );
			self::collect_cookies( $response, $final, $cookies );

			$read = BotStormRadar_Cache_Check::read_response( $request['headers'], $custom );
			if ( BotStormRadar_Cache_Check::HIT === $read['state'] || ( $i >= 2 && BotStormRadar_Cache_Check::MISS !== $read['state'] ) ) {
				return $sample;
			}
			if ( self::MAX_REQUESTS === $i ) {
				break;
			}
			if ( ! self::wait_for_retry( $deadline ) ) {
				// One response that is no hit proves nothing about the cache.
				if ( 1 === $i ) {
					$sample['requests'] = [];
				}
				return $sample;
			}
			$response = self::get( $final, [], $deadline );
		}
		return $sample;
	}

	/**
	 * Send the cookies back to a page that was cached without them. The first
	 * answer can miss on a cache that keeps a copy per cookie, so the second
	 * one counts: a miss then means the cookie really leaves the cache. Only
	 * the cookies a browser would send to that page go back, so a cookie
	 * scoped to /shop/ is never blamed for the home page.
	 *
	 * @param array $pages    analyze_site() pages.
	 * @param array $cookies  See collect_cookies().
	 * @param float $deadline
	 * @return array|null See analyze_site().
	 */
	private static function replay( array $pages, array $cookies, $deadline ) {
		foreach ( $pages as $page ) {
			if ( BotStormRadar_Cache_Check::PAGE_CACHED !== $page['outcome'] ) {
				continue;
			}
			$send = self::cookies_for( $cookies, $page['url'] );
			if ( ! $send ) {
				continue;
			}
			if ( $deadline - microtime( true ) < self::GAP + self::MIN_LEFT ) {
				return null;
			}
			$response = self::get( $page['url'], $send, $deadline );
			if ( self::out_of_time( $response ) ) {
				return null;
			}
			$request = self::to_request( $response );
			if ( isset( $request['status'] ) && 200 === $request['status'] ) {
				if ( ! self::wait_for_retry( $deadline ) ) {
					return null;
				}
				$response = self::get( $page['url'], $send, $deadline );
				// Without the second answer the first could be a per-cookie miss.
				if ( self::out_of_time( $response ) ) {
					return null;
				}
				$request = self::to_request( $response );
			}
			return [
				'kind'     => $page['kind'],
				'cookies'  => array_keys( $send ),
				'response' => $request,
			];
		}
		return null;
	}

	/**
	 * Wait only when the budget covers both the gap and another request.
	 *
	 * @param float $deadline
	 * @return bool
	 */
	private static function wait_for_retry( $deadline ) {
		if ( $deadline - microtime( true ) < self::GAP + self::MIN_LEFT ) {
			return false;
		}
		sleep( self::GAP );
		return true;
	}

	/**
	 * Request a URL, following redirects by hand: a home page can redirect to
	 * a language path, and a redirect off the site or to a login page has to
	 * stay what it is, an answer that says nothing about caching.
	 *
	 * @param string $url
	 * @param float  $deadline
	 * @return array url, response
	 */
	private static function follow( $url, $deadline ) {
		$response = self::get( $url, [], $deadline );
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
			$response = self::get( $url, [], $deadline );
		}
		return [
			'url'      => $url,
			'response' => $response,
		];
	}

	/**
	 * One request to the site's own page. The certificate is checked: the
	 * answer counts as what visitors get, and the request can go through a
	 * public CDN. Core's `https_ssl_verify` filter still applies (#120).
	 *
	 * @param string $url
	 * @param array  $cookies  name => value.
	 * @param float  $deadline The run's deadline. With less than MIN_LEFT left
	 *                         no request is made; otherwise the timeout is
	 *                         TIMEOUT or the time left, whichever is shorter.
	 * @return array|WP_Error
	 */
	private static function get( $url, array $cookies, $deadline ) {
		if ( $deadline - microtime( true ) < self::MIN_LEFT ) {
			return new WP_Error( self::OUT_OF_TIME, __( 'No time left for this request.', 'bot-storm-radar' ) );
		}
		// github-build-only:start
		if ( defined( 'CURLOPT_RESOLVE' ) ) {
			$address = self::public_address( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' !== $address ) {
				return self::request_via( $url, $cookies, $deadline, $address );
			}
		}
		// github-build-only:end
		return self::request( $url, $cookies, $deadline );
	}

	// github-build-only:start
	/**
	 * A request connected to the public address (see public_address()), with
	 * the certificate always checked: that answer counts as what visitors
	 * get, so it must come from a server holding the site's certificate.
	 *
	 * @param string $url
	 * @param array  $cookies
	 * @param float  $deadline
	 * @param string $address
	 * @return array|WP_Error
	 */
	private static function request_via( $url, array $cookies, $deadline, $address ) {
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$port    = wp_parse_url( $url, PHP_URL_PORT );
		$port    = $port ? (int) $port : ( 'http' === wp_parse_url( $url, PHP_URL_SCHEME ) ? 80 : 443 );
		$connect = static function ( $handle ) use ( $host, $port, $address ) {
			curl_setopt( $handle, CURLOPT_RESOLVE, [ $host . ':' . $port . ':' . $address ] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- http_api_curl hands over the handle, and the HTTP API has no way to choose the address.
		};
		add_action( 'http_api_curl', $connect );
		try {
			return self::request( $url, $cookies, $deadline );
		} finally {
			remove_action( 'http_api_curl', $connect );
		}
	}

	/**
	 * The address to connect to when the system's answer for a host is not
	 * among its public DNS records, as with a hosts-file entry.
	 *
	 * Many servers map their own domain to themselves in /etc/hosts, so a
	 * request from the server never meets the CDN in front of it. When the
	 * system's answer for the host is not among its DNS records, the request
	 * connects to the public address instead (cURL only). On that route the
	 * certificate is always checked, whatever core's loopback filter says, so
	 * the answer cannot come from a server that only claims to be the site.
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
	// github-build-only:end

	/**
	 * @param string $url
	 * @param array  $cookies
	 * @param float  $deadline The sampling deadline.
	 * @return array|WP_Error
	 */
	private static function request( $url, array $cookies, $deadline ) {
		$left = $deadline - microtime( true );
		if ( $left < self::MIN_LEFT ) {
			return new WP_Error( self::OUT_OF_TIME, __( 'No time left for this request.', 'bot-storm-radar' ) );
		}
		return wp_safe_remote_get(
			$url,
			[
				'timeout'             => min( self::TIMEOUT, $left ),
				'redirection'         => 0,
				'sslverify'           => true,
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
	 * Keep what a browser would keep: a cookie being deleted is not sent back,
	 * and each cookie keeps its scope. A browser keys a cookie by name, domain
	 * and path, so the same name under two paths is two cookies. A Secure
	 * cookie is sent over HTTPS only, and one an HTTP answer sets is dropped
	 * (#120). The cookies are read from the Set-Cookie lines themselves: core
	 * keeps one cookie per name and no Secure flag, so its cookie objects
	 * cannot tell two paths apart or say which one is Secure.
	 *
	 * @param array  $response
	 * @param string $url     The URL that answered.
	 * @param array  $cookies "name|domain|path" => name, value, domain, host_only, path, secure.
	 */
	private static function collect_cookies( $response, $url, array &$cookies ) {
		$https = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		foreach ( (array) wp_remote_retrieve_header( $response, 'set-cookie' ) as $line ) {
			$entry = self::parse_set_cookie( (string) $line, $url );
			if ( null === $entry || ( $entry['secure'] && ! $https ) ) {
				continue;
			}
			$gone = $entry['gone'];
			unset( $entry['gone'] );
			$key = $entry['name'] . '|' . $entry['domain'] . '|' . $entry['path'];
			if ( $gone ) {
				unset( $cookies[ $key ] );
			} else {
				$cookies[ $key ] = $entry;
			}
		}
	}

	/**
	 * One Set-Cookie line as a browser stores it (RFC 6265 5.2 and 5.3). An
	 * attribute counts by its name, whatever follows it, so "Secure=1" is
	 * Secure; the last Domain, Path, Expires or Max-Age on the line wins.
	 *
	 * @param string $line
	 * @param string $url  The URL that answered.
	 * @return array|null name, value, domain, host_only, path, secure, gone;
	 *                    null for a line a browser ignores.
	 */
	private static function parse_set_cookie( $line, $url ) {
		$parts = explode( ';', $line );
		$pair  = (string) array_shift( $parts );
		$eq    = strpos( $pair, '=' );
		$name  = false === $eq ? '' : trim( substr( $pair, 0, $eq ) );
		if ( '' === $name ) {
			return null;
		}
		$value = trim( substr( $pair, $eq + 1 ) );
		$attrs = [];
		foreach ( $parts as $part ) {
			$eq          = strpos( $part, '=' );
			$key         = strtolower( trim( false === $eq ? $part : substr( $part, 0, $eq ) ) );
			$attrs[ $key ] = false === $eq ? '' : trim( substr( $part, $eq + 1 ) );
		}
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$domain = isset( $attrs['domain'] ) ? ltrim( strtolower( $attrs['domain'] ), '.' ) : '';
		if ( '' !== $domain && $host !== $domain && substr( $host, -strlen( '.' . $domain ) ) !== '.' . $domain ) {
			return null; // Set for a domain the answering host is not part of.
		}
		if ( isset( $attrs['path'] ) && 0 === strpos( $attrs['path'], '/' ) ) {
			$path = $attrs['path'];
		} else {
			// The default path: the answering URL's path up to its last slash.
			$from = (string) wp_parse_url( $url, PHP_URL_PATH );
			$cut  = 0 === strpos( $from, '/' ) ? (int) strrpos( $from, '/' ) : 0;
			$path = $cut > 0 ? substr( $from, 0, $cut ) : '/';
		}
		$gone = '' === $value || 'deleted' === $value;
		if ( isset( $attrs['max-age'] ) && preg_match( '/^-?\d+$/', $attrs['max-age'] ) ) {
			$gone = $gone || (int) $attrs['max-age'] <= 0;
		} elseif ( isset( $attrs['expires'] ) ) {
			$when = strtotime( $attrs['expires'] );
			$gone = $gone || ( false !== $when && $when <= time() );
		}
		return [
			'name'      => $name,
			'value'     => $value,
			'domain'    => '' === $domain ? $host : $domain,
			'host_only' => '' === $domain,
			'path'      => $path,
			'secure'    => isset( $attrs['secure'] ),
			'gone'      => $gone,
		];
	}

	/**
	 * The cookies a browser would send to a URL (RFC 6265 5.1.3 and 5.1.4),
	 * name => value. Where two match under one name, the longer path wins, as
	 * it comes first in a browser's Cookie header.
	 *
	 * @param array  $cookies See collect_cookies().
	 * @param string $url
	 * @return array
	 */
	private static function cookies_for( array $cookies, $url ) {
		$https = 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		$send = [];
		$best = [];
		foreach ( $cookies as $c ) {
			$domain_ok = $c['host_only'] ? $host === $c['domain'] : ( $host === $c['domain'] || substr( $host, -strlen( '.' . $c['domain'] ) ) === '.' . $c['domain'] );
			$path_ok   = $path === $c['path'] || ( 0 === strpos( $path, $c['path'] ) && ( '/' === substr( $c['path'], -1 ) || '/' === substr( $path, strlen( $c['path'] ), 1 ) ) );
			if ( ! $domain_ok || ! $path_ok || ( ! empty( $c['secure'] ) && ! $https ) ) {
				continue;
			}
			if ( ! isset( $best[ $c['name'] ] ) || strlen( $c['path'] ) > $best[ $c['name'] ] ) {
				$best[ $c['name'] ] = strlen( $c['path'] );
				$send[ $c['name'] ] = $c['value'];
			}
		}
		return $send;
	}

	/**
	 * A request skipped for lack of time.
	 *
	 * @param array|WP_Error $response
	 * @return bool
	 */
	private static function out_of_time( $response ) {
		return is_wp_error( $response ) && self::OUT_OF_TIME === $response->get_error_code();
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
