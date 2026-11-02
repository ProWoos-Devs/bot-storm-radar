<?php
/**
 * Reads what a page's response headers say about caching, and turns samples
 * of a site's public pages into findings (issue #86). This class only
 * analyzes: it makes no requests, stores nothing and depends on no WordPress
 * function, so every case can be tested on its own.
 *
 * It never says "not cached" on thin evidence. Without a recognized cache
 * header the answer is "could not tell": a loopback request can miss the CDN,
 * and a cache may simply not announce itself. One layer's hit wins over
 * another's miss, because a host cache behind a CDN that answers DYNAMIC is
 * still a cached site.
 *
 * Header values, from each vendor's documentation:
 * - cf-cache-status: https://developers.cloudflare.com/cache/concepts/cache-responses/
 * - x-cache: https://www.fastly.com/documentation/reference/http/http-headers/X-Cache/
 *   and https://repost.aws/knowledge-center/cloudfront-custom-object-caching
 * - x-litespeed-cache: https://docs.litespeedtech.com/lscache/noplugin/installation/
 * - x-proxy-cache: https://eu.siteground.com/kb/siteground-dynamic-caching-configuration/
 * - x-cache-status, x-srcache-fetch-status, x-varnish: WP_Site_Health::get_page_cache_headers()
 *
 * x-kinsta-cache, x-sucuri-cache, cdn-cache, x-fastcgi-cache and x-cacheable
 * have no value list we could confirm in vendor documentation, so only the
 * plain words below are believed from them and anything else stays unknown.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Cache_Check {

	// What a cache layer did with one response.
	const HIT     = 'hit';
	const MISS    = 'miss';
	const DYNAMIC = 'dynamic';
	const BYPASS  = 'bypass';
	const UNKNOWN = 'unknown';
	const NONE    = 'none';

	// What one sampled page amounts to.
	const PAGE_CACHED         = 'cached';
	const PAGE_COOKIE_BLOCKS  = 'cookie_blocks';
	const PAGE_NOT_STORED     = 'not_stored';
	const PAGE_ORIGIN_FORBIDS = 'origin_forbids';
	const PAGE_ORIGIN_SILENT  = 'origin_silent';
	const PAGE_NO_SIGNAL      = 'no_signal';
	const PAGE_NO_ANSWER      = 'no_answer';

	// What the whole sample amounts to.
	const SITE_CACHED         = 'cached';
	const SITE_PARTLY         = 'partly';
	const SITE_NOT_CACHED     = 'not_cached';
	const SITE_COULD_NOT_TELL = 'could_not_tell';

	// Findings a screen can word. A fault is worth acting on, info is not.
	const FAULT = 'fault';
	const INFO  = 'info';

	/**
	 * WordPress nonces last 12 to 24 hours by default, so a page held longer
	 * than this can carry a nonce that no longer works.
	 */
	const NONCE_SAFE_TTL = 43200;

	/**
	 * Headers that hold a cache status word, and the layer they name.
	 */
	const STATUS_HEADERS = [
		'cf-cache-status'        => 'cloudflare',
		'x-cache'                => 'x-cache',
		'x-litespeed-cache'      => 'litespeed',
		'x-proxy-cache'          => 'x-proxy-cache',
		'x-cache-status'         => 'x-cache-status',
		'x-srcache-fetch-status' => 'srcache',
		'x-kinsta-cache'         => 'kinsta',
		'x-sucuri-cache'         => 'sucuri',
		'cdn-cache'              => 'cdn-cache',
		'x-fastcgi-cache'        => 'fastcgi',
		'x-cacheable'            => 'x-cacheable',
	];

	/**
	 * Headers with lower-case names. wp_remote_retrieve_headers() gives a
	 * case-insensitive object, so callers pass ->getAll() or a plain array.
	 *
	 * @param array $headers
	 * @return array
	 */
	public static function normalize_headers( array $headers ) {
		$out = [];
		foreach ( $headers as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = $value;
		}
		return $out;
	}

	/**
	 * One status word list to a state. Several entries report several servers
	 * (Fastly shielding), and any hit means the origin was not asked.
	 *
	 * @param string $value
	 * @return string
	 */
	public static function status_word_state( $value ) {
		$found = [];
		foreach ( explode( ',', strtolower( (string) $value ) ) as $entry ) {
			if ( ! preg_match( '/^\s*([a-z]+)/', $entry, $m ) ) {
				continue;
			}
			switch ( $m[1] ) {
				case 'hit':
				case 'refreshhit':
				case 'stale':
				case 'updating':
				case 'revalidated':
					$found[ self::HIT ] = true;
					break;
				case 'bypass':
				case 'pass':
					$found[ self::BYPASS ] = true;
					break;
				case 'dynamic':
					$found[ self::DYNAMIC ] = true;
					break;
				case 'miss':
				case 'expired':
					$found[ self::MISS ] = true;
					break;
			}
		}
		foreach ( [ self::HIT, self::BYPASS, self::DYNAMIC, self::MISS ] as $state ) {
			if ( isset( $found[ $state ] ) ) {
				return $state;
			}
		}
		return self::UNKNOWN;
	}

	/**
	 * The cache layers one response announces.
	 *
	 * @param array $headers
	 * @param array $custom  Extra headers, name => callable( string $value ): string state. The caller applies its filter.
	 * @return array state (hit, bypass, dynamic, miss, unknown or none), layers [ layer, header, value, state ]
	 */
	public static function read_response( array $headers, array $custom = [] ) {
		$headers = self::normalize_headers( $headers );
		$layers  = [];

		foreach ( self::STATUS_HEADERS as $name => $layer ) {
			if ( ! isset( $headers[ $name ] ) ) {
				continue;
			}
			$value = self::flat( $headers[ $name ] );
			if ( 'x-cache' === $name && false !== stripos( $value, 'cloudfront' ) ) {
				$layer = 'cloudfront';
			}
			$layers[] = [
				'layer'  => $layer,
				'header' => $name,
				'value'  => $value,
				'state'  => self::status_word_state( $value ),
			];
		}

		if ( isset( $headers['x-varnish'] ) ) {
			$value    = self::flat( $headers['x-varnish'] );
			$layers[] = [
				'layer'  => 'varnish',
				'header' => 'x-varnish',
				'value'  => $value,
				'state'  => preg_match( '/^\s*\d+\s+\d+/', $value ) ? self::HIT : self::MISS,
			];
		}

		foreach ( $custom as $name => $reader ) {
			$name = strtolower( (string) $name );
			if ( ! isset( $headers[ $name ] ) || ! is_callable( $reader ) ) {
				continue;
			}
			$value    = self::flat( $headers[ $name ] );
			$state    = (string) call_user_func( $reader, $value );
			$layers[] = [
				'layer'  => $name,
				'header' => $name,
				'value'  => $value,
				'state'  => in_array( $state, [ self::HIT, self::MISS, self::DYNAMIC, self::BYPASS ], true ) ? $state : self::UNKNOWN,
			];
		}

		// A positive Age means some cache answered, whatever it calls itself.
		if ( isset( $headers['age'] ) && is_numeric( self::flat( $headers['age'] ) ) && (int) self::flat( $headers['age'] ) > 0 ) {
			$layers[] = [
				'layer'  => 'age',
				'header' => 'age',
				'value'  => self::flat( $headers['age'] ),
				'state'  => self::HIT,
			];
		}

		$state = $layers ? self::UNKNOWN : self::NONE;
		foreach ( [ self::HIT, self::BYPASS, self::DYNAMIC, self::MISS ] as $candidate ) {
			if ( in_array( $candidate, array_column( $layers, 'state' ), true ) ) {
				$state = $candidate;
				break;
			}
		}

		return [
			'state'  => $state,
			'layers' => $layers,
		];
	}

	/**
	 * What the origin asked caches to do.
	 *
	 * @param array $headers
	 * @return array asks (bool), forbids (bool), edge_ttl (int, 0 when none is stated)
	 */
	public static function origin_intent( array $headers ) {
		$headers = self::normalize_headers( $headers );
		$cc      = isset( $headers['cache-control'] ) ? strtolower( self::flat( $headers['cache-control'] ) ) : '';
		$max     = preg_match( '/(?:^|[\s,])max-age=(\d+)/', $cc, $m ) ? (int) $m[1] : null;
		$shared  = preg_match( '/(?:^|[\s,])s-maxage=(\d+)/', $cc, $m ) ? (int) $m[1] : null;
		$forbids = (bool) preg_match( '/(?:^|[\s,])(?:no-store|no-cache|private)(?:[\s,=]|$)/', $cc ) || 0 === $shared;
		$public  = (bool) preg_match( '/(?:^|[\s,])public(?:[\s,]|$)/', $cc );
		$ttl     = null !== $shared ? $shared : ( $public && null !== $max ? $max : 0 );

		return [
			'asks'     => ! $forbids && $ttl > 0,
			'forbids'  => $forbids,
			'edge_ttl' => $forbids ? 0 : $ttl,
		];
	}

	/**
	 * The names of the cookies a response sets. Values are never kept.
	 *
	 * @param array $headers
	 * @return string[]
	 */
	public static function cookie_names( array $headers ) {
		$headers = self::normalize_headers( $headers );
		if ( ! isset( $headers['set-cookie'] ) ) {
			return [];
		}
		$names = [];
		foreach ( (array) $headers['set-cookie'] as $line ) {
			if ( preg_match( '/^\s*([^=;\s]+)=/', (string) $line, $m ) ) {
				$names[ $m[1] ] = true;
			}
		}
		return array_keys( $names );
	}

	/**
	 * Whether a page's HTML carries something that looks like a nonce, in a
	 * script's data or a form field.
	 *
	 * @param string $body
	 * @return bool
	 */
	public static function body_has_nonce( $body ) {
		$body = (string) $body;
		return 1 === preg_match( '/["\'][a-z0-9_-]*nonce[a-z0-9_-]*["\']\s*:\s*["\'][a-f0-9]{10}["\']/i', $body )
			|| 1 === preg_match( '/name=["\'][a-z0-9_-]*nonce[a-z0-9_-]*["\'][^>]*value=["\'][a-f0-9]{10}["\']/i', $body );
	}

	/**
	 * One sampled page, requested two or more times without cookies.
	 *
	 * @param array $sample kind (string), url (string), has_nonce (bool), requests: list of [ status (int), headers (array) ], or [ error (string) ]
	 * @param array $custom See read_response().
	 * @return array kind, url, outcome, cookies, layers, edge_ttl, has_nonce
	 */
	public static function analyze_page( array $sample, array $custom = [] ) {
		$requests = isset( $sample['requests'] ) ? (array) $sample['requests'] : [];
		$result   = [
			'kind'      => isset( $sample['kind'] ) ? (string) $sample['kind'] : '',
			'url'       => isset( $sample['url'] ) ? (string) $sample['url'] : '',
			'outcome'   => self::PAGE_NO_ANSWER,
			'cookies'   => [],
			'layers'    => [],
			'edge_ttl'  => 0,
			'has_nonce' => ! empty( $sample['has_nonce'] ),
		];

		if ( ! $requests ) {
			return $result;
		}
		$states  = [];
		$forbids = false;
		$asks    = false;
		foreach ( $requests as $request ) {
			// A refusal, a login redirect or a maintenance page says nothing about caching.
			if ( ! empty( $request['error'] ) || ! isset( $request['status'] ) || 200 !== (int) $request['status'] ) {
				return $result;
			}
			$headers            = isset( $request['headers'] ) ? (array) $request['headers'] : [];
			$read               = self::read_response( $headers, $custom );
			$intent             = self::origin_intent( $headers );
			$states[]           = $read['state'];
			$result['layers']   = $read['layers'] ? $read['layers'] : $result['layers'];
			$result['cookies']  = array_values( array_unique( array_merge( $result['cookies'], self::cookie_names( $headers ) ) ) );
			$result['edge_ttl'] = max( $result['edge_ttl'], $intent['edge_ttl'] );
			$forbids            = $forbids || $intent['forbids'];
			$asks               = $asks || $intent['asks'];
		}

		if ( in_array( self::HIT, $states, true ) ) {
			$result['outcome'] = self::PAGE_CACHED;
		} elseif ( ! array_diff( $states, [ self::NONE, self::UNKNOWN ] ) ) {
			$result['outcome'] = self::PAGE_NO_SIGNAL;
		} elseif ( $forbids ) {
			// Uncached on purpose, and a cookie on such a page is no fault.
			$result['outcome'] = self::PAGE_ORIGIN_FORBIDS;
		} elseif ( $asks ) {
			$result['outcome'] = $result['cookies'] ? self::PAGE_COOKIE_BLOCKS : self::PAGE_NOT_STORED;
		} else {
			$result['outcome'] = self::PAGE_ORIGIN_SILENT;
		}
		return $result;
	}

	/**
	 * The whole sample.
	 *
	 * @param array      $samples See analyze_page().
	 * @param array|null $replay  A page that was cached, requested again with the cookies browsing handed out: kind, cookies (names), response [ status, headers ]. Null when browsing sets none.
	 * @param array      $custom  See read_response().
	 * @return array verdict, pages, findings [ code, severity, pages (kinds), cookies ]
	 */
	public static function analyze_site( array $samples, $replay = null, array $custom = [] ) {
		$pages = [];
		foreach ( $samples as $sample ) {
			$pages[] = self::analyze_page( (array) $sample, $custom );
		}

		$by_outcome = [];
		foreach ( $pages as $page ) {
			$by_outcome[ $page['outcome'] ][] = $page;
		}
		$usable = array_filter(
			$pages,
			static function ( $page ) {
				return ! in_array( $page['outcome'], [ self::PAGE_NO_SIGNAL, self::PAGE_NO_ANSWER ], true );
			}
		);
		$cached = isset( $by_outcome[ self::PAGE_CACHED ] ) ? count( $by_outcome[ self::PAGE_CACHED ] ) : 0;

		if ( ! $usable ) {
			$verdict = self::SITE_COULD_NOT_TELL;
		} elseif ( count( $usable ) === $cached ) {
			$verdict = self::SITE_CACHED;
		} else {
			$verdict = $cached > 0 ? self::SITE_PARTLY : self::SITE_NOT_CACHED;
		}

		$severities = [
			self::PAGE_COOKIE_BLOCKS  => self::FAULT,
			self::PAGE_NOT_STORED     => self::FAULT,
			self::PAGE_ORIGIN_FORBIDS => self::INFO,
			self::PAGE_ORIGIN_SILENT  => self::INFO,
			self::PAGE_NO_SIGNAL      => self::INFO,
			self::PAGE_NO_ANSWER      => self::INFO,
		];
		$findings   = [];
		foreach ( $severities as $outcome => $severity ) {
			if ( empty( $by_outcome[ $outcome ] ) ) {
				continue;
			}
			$cookies = [];
			foreach ( $by_outcome[ $outcome ] as $page ) {
				$cookies = array_merge( $cookies, $page['cookies'] );
			}
			$findings[] = [
				'code'     => $outcome,
				'severity' => $severity,
				'pages'    => array_column( $by_outcome[ $outcome ], 'kind' ),
				'cookies'  => self::PAGE_COOKIE_BLOCKS === $outcome ? array_values( array_unique( $cookies ) ) : [],
			];
		}

		$lost = self::replay_finding( $replay, $custom, $cached > 0 );
		if ( $lost ) {
			$findings[] = $lost;
		}

		$old_nonces = array_filter(
			$pages,
			static function ( $page ) {
				return $page['has_nonce'] && $page['edge_ttl'] > self::NONCE_SAFE_TTL
					&& in_array( $page['outcome'], [ self::PAGE_CACHED, self::PAGE_COOKIE_BLOCKS, self::PAGE_NOT_STORED ], true );
			}
		);
		if ( $old_nonces ) {
			$findings[] = [
				'code'     => 'ttl_outlives_nonces',
				'severity' => self::INFO,
				'pages'    => array_column( $old_nonces, 'kind' ),
				'cookies'  => [],
			];
		}

		return [
			'verdict'  => $verdict,
			'pages'    => $pages,
			'findings' => $findings,
		];
	}

	/**
	 * A page that is cached for a clean request and not once the visitor
	 * holds the cookies browsing hands out: one product view can take a
	 * visitor out of the cache on every page.
	 *
	 * @param array|null $replay
	 * @param array      $custom
	 * @param bool       $clean_cached Whether anything was cached without cookies.
	 * @return array|null
	 */
	private static function replay_finding( $replay, array $custom, $clean_cached ) {
		if ( ! $clean_cached || ! is_array( $replay ) || empty( $replay['cookies'] ) || empty( $replay['response'] ) ) {
			return null;
		}
		$response = (array) $replay['response'];
		if ( ! empty( $response['error'] ) || ! isset( $response['status'] ) || 200 !== (int) $response['status'] ) {
			return null;
		}
		$read = self::read_response( isset( $response['headers'] ) ? (array) $response['headers'] : [], $custom );
		if ( ! in_array( $read['state'], [ self::MISS, self::DYNAMIC, self::BYPASS ], true ) ) {
			return null;
		}
		return [
			'code'     => 'cookie_leaves_cache',
			'severity' => self::FAULT,
			'pages'    => [ isset( $replay['kind'] ) ? (string) $replay['kind'] : '' ],
			'cookies'  => array_values( (array) $replay['cookies'] ),
		];
	}

	/**
	 * @param mixed $value
	 * @return string
	 */
	private static function flat( $value ) {
		return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
	}
}
