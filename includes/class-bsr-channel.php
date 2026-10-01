<?php
/**
 * The gate channel: what the gate refuses reaches the radar through here.
 *
 * The gate has no WordPress and no counters of its own. It writes one event
 * per refused request (kind, detail, address) to the channel; a drain run by
 * the tick moves each finished minute into the radar's counters. Pure
 * functions, no WordPress: the gate bundles this class for the write side,
 * BSR_Channel_Drain (WordPress side) uses its read side.
 *
 * Two channels, chosen by the process that writes:
 *   - APCu, when enabled: per-minute keys `bsrc:<site>:<minute>:<kind>:<detail>:<ip>`
 *     incremented with apcu_inc(), atomic across the workers of a pool. The
 *     site id (a hash of the data directory's real path) keeps two sites that
 *     share one PHP-FPM pool apart.
 *   - Otherwise a spool: one file per minute in the data directory,
 *     `spool-<minute>.php` (the `<?php exit; ?>` header, then one tab-separated
 *     line per event), appended under flock(). A minute file stops growing at
 *     SPOOL_CAP bytes; the request that creates a new minute file deletes the
 *     one from SPOOL_KEEP minutes earlier, so at most SPOOL_KEEP files exist
 *     and no request ever lists the directory.
 *
 * Drain contract: at most once, never twice. A minute is taken only once it
 * has ended (plus a two-second grace for requests already past their
 * decision) and claimed by exactly one drainer: each APCu key by the drainer
 * whose apcu_delete() succeeds, the spool file by an atomic rename to a
 * `.draining` name. Events that arrive for a minute after its drain are taken
 * by the next drain.
 * A drain interrupted after its claim loses that minute, visibly, and never
 * counts it twice.
 *
 * @package Bot_Storm_Radar
 */

// Loadable inside WordPress or by the gate, which defines BSR_GATE first.
if ( ! defined( 'ABSPATH' ) && ! defined( 'BSR_GATE' ) ) {
	exit;
}

class BSR_Channel {

	const PREFIX     = 'bsrc:';
	const TTL        = 7200;
	const SPOOL_CAP  = 262144;
	const SPOOL_KEEP = 120;
	const GRACE      = 2;
	const HEADER     = "<?php exit; ?>\n";

	/**
	 * A short id for a data directory, shared by the gate and the drain.
	 *
	 * @param string $dir
	 * @return string
	 */
	public static function site_id( $dir ) {
		$real = realpath( $dir );
		return substr( md5( false === $real ? (string) $dir : $real ), 0, 12 );
	}

	/**
	 * Whether this process writes to APCu.
	 *
	 * @return bool
	 */
	public static function apcu() {
		return function_exists( 'apcu_inc' ) && function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	/**
	 * Record one event. Never throws, never blocks a request for long.
	 *
	 * @param string   $dir    Data directory with a trailing slash.
	 * @param string   $kind   probe | ban
	 * @param string   $detail Probe class, or '' for a ban.
	 * @param string   $ip     Client address ('' when unknown).
	 * @param int|null $now
	 * @return bool
	 */
	public static function write( $dir, $kind, $detail, $ip, $now = null ) {
		$now    = null === $now ? time() : (int) $now;
		$minute = intdiv( $now, 60 );
		$kind   = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $kind ) );
		$detail = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $detail ) );
		$ip     = false !== @inet_pton( (string) $ip ) ? (string) $ip : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( '' === $kind ) {
			return false;
		}
		if ( self::apcu() ) {
			$key = self::PREFIX . self::site_id( $dir ) . ':' . $minute . ':' . $kind . ':' . $detail . ':' . $ip;
			apcu_add( $key, 0, self::TTL );
			return false !== apcu_inc( $key );
		}
		return self::spool_append( $dir, $minute, $kind . "\t" . $detail . "\t" . $ip . "\n" );
	}

	/**
	 * @param string $dir
	 * @param int    $minute
	 * @param string $line
	 * @return bool
	 */
	private static function spool_append( $dir, $minute, $line ) {
		$file = $dir . 'spool-' . $minute . '.php';
		$new  = @fopen( $file, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- succeeds for exactly one request per minute.
		if ( false !== $new ) {
			fwrite( $new, self::HEADER ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $new ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			@unlink( $dir . 'spool-' . ( $minute - self::SPOOL_KEEP ) . '.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
		$h = @fopen( $file, 'a' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( false === $h ) {
			return false;
		}
		$ok = false;
		if ( flock( $h, LOCK_EX ) ) {
			$st = fstat( $h );
			if ( is_array( $st ) && $st['size'] + strlen( $line ) <= self::SPOOL_CAP ) {
				$ok = strlen( $line ) === fwrite( $h, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			flock( $h, LOCK_UN );
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $ok;
	}

	// ── Gate-side trips and provisional bans (APCu only) ─────────────

	/**
	 * Count one probe from an address in a fixed window. Returns the count
	 * in the current window, 0 without APCu.
	 *
	 * @param string $dir
	 * @param string $ip
	 * @param int    $window Seconds.
	 * @param int    $now
	 * @return int
	 */
	public static function probe_count( $dir, $ip, $window, $now ) {
		if ( ! self::apcu() || '' === (string) $ip ) {
			return 0;
		}
		$window = max( 60, (int) $window );
		$key    = self::PREFIX . self::site_id( $dir ) . ':pc:' . $ip . ':' . intdiv( (int) $now, $window );
		apcu_add( $key, 0, $window + 60 );
		return (int) apcu_inc( $key );
	}

	/**
	 * Store a provisional ban: enforced by the gate at once, written to the
	 * ban table by the next drain. Stamped with the generation it was decided
	 * under, so any administrative change since makes it void.
	 *
	 * @param string $dir
	 * @param string $ip
	 * @param int    $expires
	 * @param int    $generation
	 * @param string $reason
	 * @param int    $now
	 * @return bool
	 */
	public static function ban_set( $dir, $ip, $expires, $generation, $reason, $now ) {
		if ( ! self::apcu() || '' === (string) $ip || $expires <= $now ) {
			return false;
		}
		return apcu_store( self::PREFIX . self::site_id( $dir ) . ':pban:' . $ip, [ 'exp' => (int) $expires, 'gen' => (int) $generation, 'reason' => (string) $reason, 't' => (int) $now ], (int) $expires - (int) $now );
	}

	/**
	 * The provisional ban stored for an address, or null.
	 *
	 * @param string $dir
	 * @param string $ip
	 * @return array|null exp, gen, reason, t
	 */
	public static function ban_get( $dir, $ip ) {
		if ( ! self::apcu() || '' === (string) $ip ) {
			return null;
		}
		$ok = false;
		$v  = apcu_fetch( self::PREFIX . self::site_id( $dir ) . ':pban:' . $ip, $ok );
		return $ok && is_array( $v ) ? $v : null;
	}

	// ── Read side (the drain) ─────────────────────────────────────────

	/**
	 * Minutes that have events waiting, oldest first, and are closed.
	 *
	 * @param string   $dir
	 * @param int|null $now
	 * @return int[]
	 */
	public static function closed_minutes( $dir, $now = null ) {
		$now     = null === $now ? time() : (int) $now;
		$limit   = intdiv( $now - self::GRACE, 60 ) - 1; // The newest minute that ended at least GRACE seconds ago.
		$minutes = [];
		if ( self::apcu() && class_exists( 'APCUIterator' ) ) {
			$re = '/^' . preg_quote( self::PREFIX . self::site_id( $dir ) . ':', '/' ) . '(\d+):/';
			foreach ( new APCUIterator( $re, APC_ITER_KEY ) as $item ) {
				if ( preg_match( $re, $item['key'], $m ) && (int) $m[1] <= $limit ) {
					$minutes[ (int) $m[1] ] = true;
				}
			}
		}
		foreach ( (array) @scandir( $dir ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $f ) && preg_match( '/^spool-(\d+)\.php$/', $f, $m ) && (int) $m[1] <= $limit ) {
				$minutes[ (int) $m[1] ] = true;
			}
		}
		$minutes = array_keys( $minutes );
		sort( $minutes );
		return $minutes;
	}

	/**
	 * Claim and read one minute from both channels. Returns the events as
	 * [ "kind\tdetail\tip" => count ] (empty when another drainer took them)
	 * and sets $full when the spool file had reached its cap.
	 *
	 * @param string $dir
	 * @param int    $minute
	 * @param bool   $full
	 * @return array
	 */
	public static function take( $dir, $minute, &$full = false ) {
		$full   = false;
		$events = [];
		if ( self::apcu() && class_exists( 'APCUIterator' ) ) {
			// Each key is its own claim: only the drainer whose apcu_delete()
			// succeeds counts it. No minute-wide marker, so events that arrive
			// for a minute after a drain are simply taken by the next one.
			$site = self::PREFIX . self::site_id( $dir ) . ':';
			$re   = '/^' . preg_quote( $site . $minute . ':', '/' ) . '([^:]*):([^:]*):(.*)$/';
			foreach ( new APCUIterator( $re, APC_ITER_KEY | APC_ITER_VALUE ) as $item ) {
				if ( preg_match( $re, $item['key'], $m ) && apcu_delete( $item['key'] ) ) {
					$k            = $m[1] . "\t" . $m[2] . "\t" . $m[3];
					$events[ $k ] = ( $events[ $k ] ?? 0 ) + (int) $item['value'];
				}
			}
		}
		$file = $dir . 'spool-' . (int) $minute . '.php';
		if ( is_file( $file ) ) {
			$claimed = $dir . 'spool-' . (int) $minute . '.draining-' . bin2hex( random_bytes( 4 ) ) . '.php';
			if ( @rename( $file, $claimed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- the rename is the claim: only one drainer wins.
				$h = @fopen( $claimed, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				if ( false !== $h ) {
					flock( $h, LOCK_EX ); // Waits for an append still in flight.
					$raw = stream_get_contents( $h );
					flock( $h, LOCK_UN );
					fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					$raw  = is_string( $raw ) ? $raw : '';
					$full = strlen( $raw ) >= self::SPOOL_CAP - 128;
					// A writer that arrived after the claim recreated its file with
					// the header ('x' first); strip the header only where it is.
					if ( 0 === strpos( $raw, self::HEADER ) ) {
						$raw = substr( $raw, strlen( self::HEADER ) );
					}
					foreach ( explode( "\n", $raw ) as $line ) {
						if ( 3 === substr_count( $line . "\t", "\t" ) ) {
							$events[ $line ] = ( $events[ $line ] ?? 0 ) + 1;
						}
					}
				}
				@unlink( $claimed ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
			}
		}
		return $events;
	}

	/**
	 * Delete `.draining` files older than $age seconds: drains interrupted
	 * after their claim. Returns the minutes lost.
	 *
	 * @param string   $dir
	 * @param int      $age
	 * @param int|null $now
	 * @return int[]
	 */
	public static function sweep_interrupted( $dir, $age = 600, $now = null ) {
		$now  = null === $now ? time() : (int) $now;
		$lost = [];
		foreach ( (array) @scandir( $dir ) as $f ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( is_string( $f ) && preg_match( '/^spool-(\d+)\.draining-[0-9a-f]+\.php$/', $f, $m ) && (int) @filemtime( $dir . $f ) < $now - $age ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $dir . $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				$lost[] = (int) $m[1];
			}
		}
		return $lost;
	}
}
