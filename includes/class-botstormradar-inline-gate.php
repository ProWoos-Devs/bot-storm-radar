<?php
/**
 * The gate inside the plugin, for builds without the early gate (the
 * wordpress.org build). Same decision as the early gate
 * (BotStormRadar_Decision), taken when WordPress has loaded the plugins:
 * probes answer 403 for every address, and in enforce mode so does an
 * address under an active ban. Everything else passes untouched.
 *
 * It decides from the projection (BotStormRadar_Projection) stored whole in
 * an autoloaded option, rebuilt on the same changes that rebuild the early
 * gate's state file, so a request costs no extra query.
 *
 * A refusal answers 403 with `X-Bot-Storm-Radar: probe` or `refused`. It is counted like the early gate's channel counts it, so the Radar
 * tab reads both the same way (never toward the addresses or the score):
 *   m:<minute>:gate_probe          probes refused
 *   m:<minute>:gate_ban            requests from banned addresses refused
 *   m:<minute>:gprobe:<ip>         probes per address (for the trips)
 *   m:<minute>:gprobe_ips          registry of those addresses
 *   m:<minute>:gprobe_c:<class>    probes per class
 * An address trips on the probe that brings its count within the window to
 * the threshold, through BotStormRadar_Trips::trip() like every other trip.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BotStormRadar_Inline_Gate {

	const OPTION       = 'botstormradar_gate_rules';
	const REGISTRY_CAP = 200;

	/**
	 * The server variables the decision reads besides the address headers
	 * (BotStormRadar_IP_Resolver::SERVER_KEYS).
	 */
	const SERVER_KEYS = [ 'REQUEST_URI', 'SCRIPT_FILENAME' ];

	/**
	 * Whether this build refuses inside the plugin: only when it has no early
	 * gate.
	 *
	 * @return bool
	 */
	public static function active() {
		return ! defined( 'BOTSTORMRADAR_EARLY_GATE' );
	}

	public static function init() {
		if ( ! self::active() ) {
			return;
		}
		BotStormRadar_Projection::on_change( [ __CLASS__, 'store' ] );
		self::run();
	}

	/**
	 * Store the projection with its ban index. Returns false when this build
	 * has the early gate.
	 *
	 * @return bool
	 */
	public static function store() {
		if ( ! self::active() ) {
			return false;
		}
		$rules           = BotStormRadar_Projection::build();
		$rules['_index'] = BotStormRadar_Decision::index( $rules );
		update_option( self::OPTION, $rules, true );
		return true;
	}

	/**
	 * The stored projection, or null before the first store.
	 *
	 * @return array|null
	 */
	public static function rules() {
		$rules = get_option( self::OPTION );
		return ( is_array( $rules ) && BotStormRadar_Projection::FORMAT === ( $rules['format'] ?? null ) ) ? $rules : null;
	}

	/**
	 * The gate for this request: refuse and stop, or return.
	 */
	public static function run() {
		if ( 'cli' === PHP_SAPI ) {
			return;
		}
		$d = self::check( self::server() );
		if ( 'pass' !== $d['action'] ) {
			BotStormRadar_Recorder::skip();
			self::refuse( 'probe' === $d['action'] ? 'probe' : 'refused' );
		}
	}

	/**
	 * Decide one request and count a refusal. Never refuses itself, so tests
	 * can feed synthetic requests.
	 *
	 * @param array    $server
	 * @param int|null $now
	 * @return array {action: pass|ban|probe, why: string, ip: string|false, trip: string}
	 */
	public static function check( array $server, $now = null ) {
		$now   = null === $now ? time() : (int) $now;
		$rules = self::rules();
		if ( null === $rules ) {
			return [ 'action' => 'pass', 'why' => 'no-rules', 'ip' => false, 'trip' => '' ]; // Fail open.
		}
		$d         = BotStormRadar_Decision::decide( $server, $rules, $now );
		$d['trip'] = '';
		if ( 'pass' === $d['action'] ) {
			return $d;
		}
		$ip = false === $d['ip'] ? '' : (string) $d['ip'];
		$m  = BotStormRadar_Helpers::minute( $now );
		$p  = 'm:' . $m . ':';
		if ( 'probe' === $d['action'] ) {
			BotStormRadar_Counters::incr( $p . 'gate_probe', BotStormRadar_Counters::TTL_MINUTE );
			BotStormRadar_Counters::incr( $p . 'gprobe_c:' . $d['why'], BotStormRadar_Counters::TTL_MINUTE );
			$here = 0;
			if ( '' !== $ip ) {
				$here = BotStormRadar_Counters::incr( $p . 'gprobe:' . $ip, BotStormRadar_Counters::TTL_MINUTE );
				BotStormRadar_Counters::registry_add( $p . 'gprobe_ips', $ip, 1, self::REGISTRY_CAP );
			}
			BotStormRadar_Counters::flush();
			if ( $here > 0 ) {
				$d['trip'] = self::probe_trip( $ip, $m, $here, (string) $d['why'], $now );
			}
		} else {
			BotStormRadar_Counters::incr( $p . 'gate_ban', BotStormRadar_Counters::TTL_MINUTE );
			BotStormRadar_Counters::flush();
		}
		return $d;
	}

	/**
	 * Trip the address on the probe that brings its count within the window
	 * to the threshold, once per crossing.
	 *
	 * @param string $ip
	 * @param int    $minute
	 * @param int    $here  The address's probes in this minute, this one included.
	 * @param string $class Probe class of this request.
	 * @param int    $now
	 * @return string BotStormRadar_Trips::trip() result, or ''.
	 */
	private static function probe_trip( $ip, $minute, $here, $class, $now ) {
		$s = BotStormRadar_Trips::settings()['probe'];
		if ( $s['count'] <= 0 ) {
			return '';
		}
		$span = max( 1, intdiv( (int) $s['window'], 60 ) );
		$n    = (int) $here;
		for ( $k = $minute - $span + 1; $k < $minute; $k++ ) {
			$n += BotStormRadar_Counters::get( 'm:' . $k . ':gprobe:' . $ip );
		}
		if ( $n !== (int) $s['count'] ) {
			return '';
		}
		$ua = BotStormRadar_Helpers::user_agent();
		$ev = [
			'by'            => 'plugin',
			'window_probes' => $n,
			'last_probe'    => $class,
			'last_path'     => substr( BotStormRadar_Helpers::request_path(), 0, 200 ),
			'user_agent'    => $ua,
			'minute'        => gmdate( 'Y-m-d H:i', $minute * 60 ) . ' UTC',
		];
		return BotStormRadar_Trips::trip( $ip, 'probe', $ev, 'gate', BotStormRadar_Good_Bots::claimed( $ua ), $now );
	}

	/**
	 * The server variables the decision reads, sanitized. The path keeps its
	 * percent-encoding (an encoded dot is still a probe); addresses are
	 * validated again by the resolver.
	 *
	 * @return array
	 */
	public static function server() {
		$out = BotStormRadar_Client_IP::server_vars();
		if ( isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ) {
			$out['REQUEST_URI'] = BotStormRadar_Helpers::request_uri();
		}
		if ( isset( $_SERVER['SCRIPT_FILENAME'] ) && is_string( $_SERVER['SCRIPT_FILENAME'] ) ) {
			$out['SCRIPT_FILENAME'] = substr( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ), 0, 2048 );
		}
		return $out;
	}

	/**
	 * Answer 403 and stop.
	 *
	 * @param string $kind refused (a ban) or probe.
	 */
	private static function refuse( $kind ) {
		if ( ! headers_sent() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Bot-Storm-Radar: ' . ( 'probe' === $kind ? 'probe' : 'refused' ) );
		}
		echo "Forbidden\n";
		exit;
	}
}
