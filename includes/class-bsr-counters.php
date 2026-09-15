<?php
/**
 * Counter store (design v1, 8). Backends in order of preference:
 *
 *  - object_cache: a persistent object cache (Redis, Memcached) with atomic
 *    increments through wp_cache_incr().
 *  - apcu: shared memory on the PHP process pool, atomic apcu_inc().
 *  - transient: the fallback. Increments are buffered per request and written
 *    once at shutdown into one transient per minute bucket (one read and one
 *    write per request per touched bucket), so the database sees a bounded
 *    number of writes and never one per increment. Concurrent requests can
 *    lose updates; the dashboard warns while this backend is active.
 *  - memory: a plain PHP array for one process, never chosen by detection.
 *    The log reader forces it on the command line to build minute rows from
 *    web-server log lines without touching the site's live counters.
 *
 * Keys are "<scope>:<bucket>:<name>" where scope is m (minute bucket),
 * t (reserved for ten-minute buckets) or g (global, no bucket). The
 * transient backend groups by "<scope>:<bucket>".
 *
 * Counters never live in options on the request path.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BSR_Counters {

	const GROUP = 'bsr';

	/**
	 * Default lifetimes: a minute bucket must survive long enough for the
	 * tick to read it late, a ten-minute bucket for one full window after it.
	 */
	const TTL_MINUTE = 1500;
	const TTL_TEN    = 1500;
	const TTL_GLOBAL = 86400;

	/**
	 * Transient fallback: cap on entries per bucket. Beyond it, unknown keys
	 * increment as first hits without being stored.
	 */
	const TRANSIENT_CAP = 2000;

	/**
	 * @var string|null object_cache | apcu | transient
	 */
	private static $backend = null;

	/**
	 * @var string|null Forced backend (tests, or the BSR_COUNTER_BACKEND constant).
	 */
	private static $forced = null;

	/**
	 * @var string
	 */
	private static $apcu_prefix = '';

	/**
	 * Transient backend state: group => array of key => value, and dirty flags.
	 *
	 * @var array
	 */
	private static $t_groups = [];
	private static $t_dirty  = [];
	private static $t_flush_registered = false;

	/**
	 * Memory backend: key => value, and key => expiry timestamp.
	 *
	 * @var array
	 */
	private static $mem     = [];
	private static $mem_exp = [];

	// ── Backend selection ───────────────────────────────────────────

	/**
	 * @return string
	 */
	public static function backend() {
		if ( null !== self::$backend ) {
			return self::$backend;
		}
		$forced = self::$forced;
		if ( null === $forced && defined( 'BSR_COUNTER_BACKEND' ) ) {
			$forced = (string) BSR_COUNTER_BACKEND;
		}
		if ( in_array( $forced, [ 'object_cache', 'apcu', 'transient', 'memory' ], true ) ) {
			self::$backend = $forced;
		} elseif ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() && function_exists( 'wp_cache_incr' ) ) {
			self::$backend = 'object_cache';
		} elseif ( self::apcu_available() ) {
			self::$backend = 'apcu';
		} else {
			self::$backend = 'transient';
		}
		if ( 'apcu' === self::$backend ) {
			self::$apcu_prefix = 'bsr:' . substr( md5( defined( 'ABSPATH' ) ? ABSPATH : __DIR__ ), 0, 8 ) . ':';
		}
		return self::$backend;
	}

	/**
	 * @return bool
	 */
	public static function apcu_available() {
		return function_exists( 'apcu_inc' ) && function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	/**
	 * Force a backend (tests). Pass null to return to detection.
	 *
	 * @param string|null $backend
	 */
	public static function force( $backend ) {
		self::flush();
		self::$forced   = $backend;
		self::$backend  = null;
		self::$t_groups = [];
		self::$t_dirty  = [];
		self::backend();
	}

	/**
	 * Human label for the dashboard.
	 *
	 * @return string
	 */
	public static function backend_label() {
		switch ( self::backend() ) {
			case 'object_cache':
				return __( 'Persistent object cache (atomic increments)', 'bot-storm-radar' );
			case 'apcu':
				return __( 'APCu shared memory (atomic increments)', 'bot-storm-radar' );
			case 'memory':
				return __( 'In-process memory (log reader)', 'bot-storm-radar' );
			default:
				return __( 'Transient fallback (database, one write per request)', 'bot-storm-radar' );
		}
	}

	// ── Public API ──────────────────────────────────────────────────

	/**
	 * Atomic increment; creates the key at 1 when absent.
	 *
	 * @param string $key
	 * @param int    $ttl
	 * @return int The new value (0 on backend failure).
	 */
	public static function incr( $key, $ttl = self::TTL_MINUTE ) {
		switch ( self::backend() ) {
			case 'object_cache':
				// Increment first: one round trip for the common case of an
				// existing key. Backends whose incr() requires the key to exist
				// (core, Memcached) return false, then add() creates it with the
				// TTL; backends that create on increment (Redis) return 1, and
				// the key gets its TTL through set() so it cannot live forever.
				$v = wp_cache_incr( $key, 1, self::GROUP );
				if ( false === $v ) {
					if ( wp_cache_add( $key, 1, self::GROUP, $ttl ) ) {
						return 1;
					}
					$v = wp_cache_incr( $key, 1, self::GROUP );
					return false === $v ? 0 : (int) $v;
				}
				if ( 1 === (int) $v ) {
					wp_cache_set( $key, 1, self::GROUP, $ttl );
				}
				return (int) $v;
			case 'apcu':
				apcu_add( self::$apcu_prefix . $key, 0, $ttl );
				$v = apcu_inc( self::$apcu_prefix . $key );
				return false === $v ? 0 : (int) $v;
			case 'memory':
				if ( ! self::m_live( $key ) ) {
					self::$mem[ $key ]     = 0;
					self::$mem_exp[ $key ] = time() + (int) $ttl;
				}
				return ++self::$mem[ $key ];
			default:
				return self::t_incr( $key, $ttl );
		}
	}

	/**
	 * @param string $key
	 * @return int
	 */
	public static function get( $key ) {
		switch ( self::backend() ) {
			case 'object_cache':
				$v = wp_cache_get( $key, self::GROUP );
				return false === $v ? 0 : (int) $v;
			case 'apcu':
				$v = apcu_fetch( self::$apcu_prefix . $key );
				return false === $v ? 0 : (int) $v;
			case 'memory':
				return self::m_live( $key ) ? (int) self::$mem[ $key ] : 0;
			default:
				return (int) self::t_get( $key, 0 );
		}
	}

	/**
	 * @param array $keys
	 * @return array key => int
	 */
	public static function get_multi( array $keys ) {
		$out = [];
		if ( empty( $keys ) ) {
			return $out;
		}
		switch ( self::backend() ) {
			case 'object_cache':
				if ( function_exists( 'wp_cache_get_multiple' ) ) {
					$vals = wp_cache_get_multiple( $keys, self::GROUP );
					foreach ( $keys as $k ) {
						$out[ $k ] = ( isset( $vals[ $k ] ) && false !== $vals[ $k ] ) ? (int) $vals[ $k ] : 0;
					}
					return $out;
				}
				foreach ( $keys as $k ) {
					$out[ $k ] = self::get( $k );
				}
				return $out;
			case 'apcu':
				$pref = [];
				foreach ( $keys as $k ) {
					$pref[] = self::$apcu_prefix . $k;
				}
				$vals = apcu_fetch( $pref );
				$vals = is_array( $vals ) ? $vals : [];
				foreach ( $keys as $k ) {
					$out[ $k ] = isset( $vals[ self::$apcu_prefix . $k ] ) ? (int) $vals[ self::$apcu_prefix . $k ] : 0;
				}
				return $out;
			case 'memory':
				foreach ( $keys as $k ) {
					$out[ $k ] = self::m_live( $k ) ? (int) self::$mem[ $k ] : 0;
				}
				return $out;
			default:
				foreach ( $keys as $k ) {
					$out[ $k ] = (int) self::t_get( $k, 0 );
				}
				return $out;
		}
	}

	/**
	 * Store an arbitrary value (registries, snapshots). Not atomic.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 * @return bool
	 */
	public static function set_value( $key, $value, $ttl = self::TTL_MINUTE ) {
		switch ( self::backend() ) {
			case 'object_cache':
				return (bool) wp_cache_set( $key, $value, self::GROUP, $ttl );
			case 'apcu':
				return (bool) apcu_store( self::$apcu_prefix . $key, $value, $ttl );
			case 'memory':
				self::$mem[ $key ]     = $value;
				self::$mem_exp[ $key ] = time() + (int) $ttl;
				return true;
			default:
				self::t_set( $key, $value, $ttl );
				return true;
		}
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	public static function get_value( $key, $default = null ) {
		switch ( self::backend() ) {
			case 'object_cache':
				$v = wp_cache_get( $key, self::GROUP );
				return false === $v ? $default : $v;
			case 'apcu':
				$ok = false;
				$v  = apcu_fetch( self::$apcu_prefix . $key, $ok );
				return $ok ? $v : $default;
			case 'memory':
				return self::m_live( $key ) ? self::$mem[ $key ] : $default;
			default:
				return self::t_get( $key, $default );
		}
	}

	/**
	 * Add only when absent: the lock primitive for the tick.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 * @return bool True when this call created the key.
	 */
	public static function add_value( $key, $value, $ttl = 120 ) {
		switch ( self::backend() ) {
			case 'object_cache':
				return (bool) wp_cache_add( $key, $value, self::GROUP, $ttl );
			case 'apcu':
				return (bool) apcu_add( self::$apcu_prefix . $key, $value, $ttl );
			case 'memory':
				if ( self::m_live( $key ) ) {
					return false;
				}
				self::$mem[ $key ]     = $value;
				self::$mem_exp[ $key ] = time() + (int) $ttl;
				return true;
			default:
				// Global keys go straight to a transient here, because a lock
				// that is only visible after shutdown is not a lock.
				$name = self::t_name( $key );
				if ( false !== get_transient( $name ) ) {
					return false;
				}
				set_transient( $name, [ '_v' => $value ], $ttl );
				return true;
		}
	}

	/**
	 * @param string $key
	 */
	public static function delete( $key ) {
		switch ( self::backend() ) {
			case 'object_cache':
				wp_cache_delete( $key, self::GROUP );
				return;
			case 'apcu':
				apcu_delete( self::$apcu_prefix . $key );
				return;
			case 'memory':
				unset( self::$mem[ $key ], self::$mem_exp[ $key ] );
				return;
			default:
				delete_transient( self::t_name( $key ) );
				$g = self::t_group_of( $key );
				if ( isset( self::$t_groups[ $g ][ $key ] ) ) {
					unset( self::$t_groups[ $g ][ $key ] );
					self::$t_dirty[ $g ] = true;
				}
		}
	}

	/**
	 * Append to a bounded registry (list of distinct entries) stored under a
	 * key. Read-modify-write, so entries can be lost under contention; used
	 * only for display and for the UA distribution, never for counts.
	 *
	 * @param string $key
	 * @param string $entry_key
	 * @param mixed  $entry_value
	 * @param int    $cap
	 * @param int    $ttl
	 * @return bool True when added.
	 */
	public static function registry_add( $key, $entry_key, $entry_value, $cap, $ttl = self::TTL_MINUTE ) {
		$reg = self::get_value( $key, [] );
		$reg = is_array( $reg ) ? $reg : [];
		if ( isset( $reg[ $entry_key ] ) ) {
			return false;
		}
		if ( count( $reg ) >= $cap ) {
			return false;
		}
		$reg[ $entry_key ] = $entry_value;
		self::set_value( $key, $reg, $ttl );
		return true;
	}

	/**
	 * Write buffered transient data now (tests, and the inline tick before it
	 * reads the previous minute).
	 */
	public static function flush() {
		if ( 'transient' !== self::$backend ) {
			return;
		}
		foreach ( self::$t_dirty as $g => $dirty ) {
			if ( ! $dirty ) {
				continue;
			}
			$data = self::$t_groups[ $g ] ?? [];
			$ttl  = isset( $data['_ttl'] ) ? (int) $data['_ttl'] : self::TTL_MINUTE;
			set_transient( self::t_name( $g ), $data, $ttl );
			self::$t_dirty[ $g ] = false;
		}
	}

	// ── Memory backend ──────────────────────────────────────────────

	/**
	 * @param string $key
	 * @return bool Whether the key exists and has not expired.
	 */
	private static function m_live( $key ) {
		if ( ! array_key_exists( $key, self::$mem ) ) {
			return false;
		}
		if ( self::$mem_exp[ $key ] < time() ) {
			unset( self::$mem[ $key ], self::$mem_exp[ $key ] );
			return false;
		}
		return true;
	}

	/**
	 * Drop every memory key starting with a prefix (a finished minute).
	 *
	 * @param string $prefix
	 * @return int Keys removed.
	 */
	public static function memory_purge( $prefix ) {
		$n   = 0;
		$len = strlen( $prefix );
		foreach ( array_keys( self::$mem ) as $k ) {
			if ( 0 === strncmp( $k, $prefix, $len ) ) {
				unset( self::$mem[ $k ], self::$mem_exp[ $k ] );
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Live memory keys starting with a prefix, with their expiry, so a
	 * caller can persist them between runs (good-bot verdicts).
	 *
	 * @param string $prefix
	 * @return array key => [ value, expiry ]
	 */
	public static function memory_export( $prefix ) {
		$out = [];
		$len = strlen( $prefix );
		foreach ( array_keys( self::$mem ) as $k ) {
			if ( 0 === strncmp( $k, $prefix, $len ) && self::m_live( $k ) ) {
				$out[ $k ] = [ self::$mem[ $k ], self::$mem_exp[ $k ] ];
			}
		}
		return $out;
	}

	/**
	 * Load what memory_export() returned; expired entries are skipped.
	 *
	 * @param array $entries key => [ value, expiry ]
	 */
	public static function memory_import( array $entries ) {
		$now = time();
		foreach ( $entries as $k => $e ) {
			if ( is_array( $e ) && 2 === count( $e ) && (int) $e[1] >= $now ) {
				self::$mem[ (string) $k ]     = $e[0];
				self::$mem_exp[ (string) $k ] = (int) $e[1];
			}
		}
	}

	/**
	 * Empty the memory backend.
	 */
	public static function memory_reset() {
		self::$mem     = [];
		self::$mem_exp = [];
	}

	// ── Transient backend internals ─────────────────────────────────

	/**
	 * @param string $key
	 * @return string "m:12345" style group, or "g:<key>" for globals.
	 */
	private static function t_group_of( $key ) {
		$parts = explode( ':', $key, 3 );
		if ( count( $parts ) >= 3 && in_array( $parts[0], [ 'm', 't' ], true ) ) {
			return $parts[0] . ':' . $parts[1];
		}
		return 'g:' . $key;
	}

	/**
	 * @param string $group
	 * @return string Transient name (<= 172 chars for the option table).
	 */
	private static function t_name( $group ) {
		return 'bsr_' . ( strlen( $group ) > 40 ? md5( $group ) : preg_replace( '/[^a-z0-9_:.\-]/i', '_', $group ) );
	}

	/**
	 * @param string $group
	 * @return array
	 */
	private static function t_load( $group ) {
		if ( ! isset( self::$t_groups[ $group ] ) ) {
			$data = get_transient( self::t_name( $group ) );
			self::$t_groups[ $group ] = is_array( $data ) ? $data : [];
			self::$t_dirty[ $group ]  = false;
			if ( ! self::$t_flush_registered ) {
				self::$t_flush_registered = true;
				register_shutdown_function( [ __CLASS__, 'flush' ] );
			}
		}
		return self::$t_groups[ $group ];
	}

	/**
	 * @param string $key
	 * @param int    $ttl
	 * @return int
	 */
	private static function t_incr( $key, $ttl ) {
		$g = self::t_group_of( $key );
		self::t_load( $g );
		if ( 0 === strpos( $g, 'g:' ) ) {
			$cur = isset( self::$t_groups[ $g ]['_v'] ) ? (int) self::$t_groups[ $g ]['_v'] : 0;
			self::$t_groups[ $g ]['_v']   = $cur + 1;
			self::$t_groups[ $g ]['_ttl'] = $ttl;
			self::$t_dirty[ $g ]          = true;
			return $cur + 1;
		}
		if ( isset( self::$t_groups[ $g ][ $key ] ) ) {
			self::$t_groups[ $g ][ $key ] = (int) self::$t_groups[ $g ][ $key ] + 1;
			self::$t_dirty[ $g ]          = true;
			return (int) self::$t_groups[ $g ][ $key ];
		}
		if ( count( self::$t_groups[ $g ] ) >= self::TRANSIENT_CAP ) {
			return 1; // Bucket full: count as a first hit without storing it.
		}
		self::$t_groups[ $g ][ $key ] = 1;
		self::$t_groups[ $g ]['_ttl'] = $ttl;
		self::$t_dirty[ $g ]          = true;
		return 1;
	}

	/**
	 * @param string $key
	 * @param mixed  $default
	 * @return mixed
	 */
	private static function t_get( $key, $default ) {
		$g    = self::t_group_of( $key );
		$data = self::t_load( $g );
		if ( 0 === strpos( $g, 'g:' ) ) {
			return array_key_exists( '_v', $data ) ? $data['_v'] : $default;
		}
		return array_key_exists( $key, $data ) ? $data[ $key ] : $default;
	}

	/**
	 * @param string $key
	 * @param mixed  $value
	 * @param int    $ttl
	 */
	private static function t_set( $key, $value, $ttl ) {
		$g = self::t_group_of( $key );
		self::t_load( $g );
		if ( 0 === strpos( $g, 'g:' ) ) {
			self::$t_groups[ $g ]['_v'] = $value;
		} else {
			self::$t_groups[ $g ][ $key ] = $value;
		}
		self::$t_groups[ $g ]['_ttl'] = $ttl;
		self::$t_dirty[ $g ]          = true;
	}
}
