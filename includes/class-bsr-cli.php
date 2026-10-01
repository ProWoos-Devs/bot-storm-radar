<?php
/**
 * WP-CLI commands for log-fed sources. Sources are defined here and nowhere
 * else: a log path is a file the server reads, so it is never taken from a
 * web form.
 *
 * @package Bot_Storm_Radar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read web-server logs into Bot Storm Radar sources.
 */
class BSR_CLI {

	/**
	 * Process what each log source's files gained since the last run.
	 *
	 * Run it once a minute from a system timer, a few seconds after the minute
	 * starts, as a user that can read the log files.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Sources to run. Default: every defined source.
	 *
	 * [--quiet-ok]
	 * : Print nothing when a run succeeds.
	 *
	 * @subcommand ingest
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function ingest( $args, $assoc ) {
		$ids = empty( $args ) ? array_keys( BSR_Log_Source::all() ) : $args;
		if ( empty( $ids ) ) {
			WP_CLI::error( 'No log sources are defined. Add one with `wp bot-storm-radar source add`.' );
		}
		$failed = false;
		foreach ( $ids as $id ) {
			$t0  = microtime( true );
			$sum = BSR_Log_Source::run( $id );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( 'unknown' === $sum['status'] ) {
				WP_CLI::warning( sprintf( '%s: no such source.', $id ) );
				$failed = true;
				continue;
			}
			foreach ( $sum['transitions'] ?? [] as $t ) {
				WP_CLI::log( sprintf( '%s: %s -> %s at %s UTC, score %d. %s', $id, $t['from'], $t['to'], gmdate( 'Y-m-d H:i', $t['minute'] * 60 ), $t['score'], $t['explanation'] ) );
			}
			if ( 'ok' === $sum['status'] && ! empty( $assoc['quiet-ok'] ) && empty( $sum['events'] ) ) {
				continue;
			}
			WP_CLI::log( sprintf(
				'%s: %s, %d minutes, %d lines, %s read, %d late, %d unparsed, %d refused%s%s, %d ms.',
				$id,
				$sum['status'],
				(int) ( $sum['minutes'] ?? 0 ),
				(int) ( $sum['lines'] ?? 0 ),
				size_format( (int) ( $sum['bytes'] ?? 0 ) ),
				(int) ( $sum['late'] ?? 0 ),
				(int) ( $sum['bad'] ?? 0 ),
				(int) ( $sum['refused'] ?? 0 ),
				empty( $sum['events'] ) ? '' : ', files: ' . self::events( $sum['events'] ),
				empty( $sum['stopped'] ) ? '' : ', stopped at the read budget',
				$ms
			) );
		}
		if ( $failed ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Replay log files and print what the radar would have said.
	 *
	 * The files go through a scratch source; nothing is kept and nothing is
	 * mailed.
	 *
	 * ## OPTIONS
	 *
	 * <file>...
	 * : Log files, plain or .gz, merged by minute.
	 *
	 * [--profile=<profile>]
	 * : Request classes to use. Required without --gate.
	 * ---
	 * options:
	 *   - mediawiki
	 *   - wordpress
	 * ---
	 *
	 * [--gate]
	 * : Instead of the radar, simulate the gate and the trips with this site's
	 * settings (as in enforce mode) over the logs of a WordPress site: which
	 * requests reached PHP, which the gate would have refused as probes or as
	 * banned, which addresses would have tripped. Nothing is written.
	 *
	 * [--root=<path>]
	 * : With --gate, the WordPress folder of the site the logs come from, for
	 * the missing-PHP rule. Without it that rule is skipped.
	 *
	 * [--home=<path>]
	 * : With --gate, the path of that site's WordPress address, for a site in a
	 * subfolder (for example /blog).
	 *
	 * [--verify-bots]
	 * : With --gate, verify search-bot claims by reverse and forward DNS
	 * instead of only listing them as pending.
	 *
	 * [--list=<n>]
	 * : With --gate, how many trips to list.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--baseline-ips=<n>]
	 * : Median distinct addresses per minute to score against. Without it the
	 * score falls back to the minimum-addresses setting, as on a fresh install.
	 *
	 * [--top=<n>]
	 * : How many of the highest-scoring minutes to list.
	 * ---
	 * default: 15
	 * ---
	 *
	 * [--csv=<file>]
	 * : Also write every minute (UTC time, score, addresses, requests) to a CSV file.
	 *
	 * @subcommand replay
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function replay( $args, $assoc ) {
		foreach ( $args as $f ) {
			if ( ! is_readable( $f ) ) {
				WP_CLI::error( sprintf( 'Cannot read %s.', $f ) );
			}
		}
		if ( ! empty( $assoc['gate'] ) ) {
			$this->replay_gate( $args, $assoc );
			return;
		}
		if ( empty( $assoc['profile'] ) ) {
			WP_CLI::error( 'Give --profile=mediawiki|wordpress, or --gate.' );
		}
		$baseline = isset( $assoc['baseline-ips'] ) ? [ 'ips_median' => (float) $assoc['baseline-ips'] ] : null;
		$t0       = microtime( true );
		$sum      = BSR_Log_Source::replay( BSR_Sources::REPLAY, $assoc['profile'], $args, $baseline );
		$ms       = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		WP_CLI::log( sprintf( '%d minutes, %d lines, %s, %d unparsed, %d late, %d refused by the web server (403, 429, 444; not counted), %d ms.', $sum['minutes'], $sum['lines'], size_format( $sum['bytes'] ), $sum['bad'], $sum['late'], (int) ( $sum['refused'] ?? 0 ), $ms ) );
		WP_CLI::log( '' );
		WP_CLI::log( 'Transitions:' );
		foreach ( $sum['transitions'] as $t ) {
			WP_CLI::log( sprintf( '  %s UTC  %s -> %s  score %d. %s', gmdate( 'Y-m-d H:i', $t['minute'] * 60 ), $t['from'], $t['to'], $t['score'], $t['explanation'] ) );
		}
		if ( empty( $sum['transitions'] ) ) {
			WP_CLI::log( '  none' );
		}

		$scores = $sum['scores'];
		uasort( $scores, function ( $a, $b ) {
			return $b[0] <=> $a[0] ?: $b[1] <=> $a[1];
		} );
		WP_CLI::log( '' );
		WP_CLI::log( 'Highest-scoring minutes (UTC, score, addresses, requests):' );
		foreach ( array_slice( $scores, 0, max( 1, (int) $assoc['top'] ), true ) as $m => $s ) {
			WP_CLI::log( sprintf( '  %s  %3d  %6d  %6d', gmdate( 'Y-m-d H:i', $m * 60 ), $s[0], $s[1], $s[2] ) );
		}
		if ( ! empty( $sum['scores'] ) ) {
			$ips = array_column( $sum['scores'], 1 );
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Distinct addresses per minute: median %s, p90 %s, max %d.', BSR_Metrics::fmt( BSR_Baseline::median( $ips ), 0 ), BSR_Metrics::fmt( BSR_Baseline::percentile( $ips, 0.9 ), 0 ), max( $ips ) ) );
		}

		if ( ! empty( $assoc['csv'] ) ) {
			$fh = fopen( $assoc['csv'], 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $fh ) {
				WP_CLI::warning( sprintf( 'Cannot write %s.', $assoc['csv'] ) );
			} else {
				fwrite( $fh, "minute_utc,score,ips,requests\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				foreach ( $sum['scores'] as $m => $s ) {
					fwrite( $fh, gmdate( 'Y-m-d H:i', $m * 60 ) . ',' . implode( ',', $s ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		BSR_Log_Source::purge( BSR_Sources::REPLAY );
	}

	/**
	 * The --gate dry run.
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	private function replay_gate( $args, $assoc ) {
		$t0  = microtime( true );
		$s   = BSR_Gate_Replay::run( $args, [
			'root'        => $assoc['root'] ?? '',
			'home'        => $assoc['home'] ?? '',
			'verify_bots' => ! empty( $assoc['verify-bots'] ),
		] );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$set = BSR_Trips::settings();
		$pct = function ( $a, $b ) {
			return $b > 0 ? round( 100 * $a / $b ) . '%' : '-';
		};
		WP_CLI::log( sprintf( 'Gate dry run over %s to %s UTC: %d lines (%d unparsed), %d ms. Nothing was written.', gmdate( 'Y-m-d H:i', (int) $s['first'] ), gmdate( 'Y-m-d H:i', (int) $s['last'] ), $s['lines'], $s['bad'], $ms ) );
		WP_CLI::log( sprintf( 'Settings: probe trip %d in %d min, page-404 trip %d a minute, ban %d min (%d h on a repeat), simulated as enforce mode.', $set['probe']['count'], intdiv( $set['probe']['window'], 60 ), $set['404']['count'], intdiv( $set['probe']['ttl'], 60 ), intdiv( $set['ttl_repeat'], 3600 ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Refused by the web server already (403, 429, 444): %d, of which %d probes. The gate never sees these.', $s['server_refused'], $s['server_probes'] ) );
		WP_CLI::log( sprintf( 'Static files served by the web server: %d.', $s['static'] ) );
		WP_CLI::log( sprintf( 'Reached PHP: %d.', $s['php'] ) );
		$cls = [];
		foreach ( $s['probe_classes'] as $c => $n ) {
			$cls[] = $c . ' ' . $n;
		}
		WP_CLI::log( sprintf( '  probes the gate would refuse: %d (%s)', $s['probes'], $cls ? implode( ', ', $cls ) : 'none' ) );
		WP_CLI::log( sprintf( '  requests from addresses under a simulated ban: %d', $s['banned_hits'] ) );
		WP_CLI::log( sprintf( '  WordPress builds saved: %d of %d (%s)', $s['saved'], $s['php'], $pct( $s['saved'], $s['php'] ) ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Trips: %d bans for %d addresses; %d trips on protected addresses skipped; %d search-bot claims %s.', count( $s['trips'] ), $s['trip_addresses'], $s['protected_skip'], count( $s['pending'] ), empty( $assoc['verify-bots'] ) ? 'held pending (not verified; add --verify-bots)' : 'checked by DNS' ) );
		foreach ( array_slice( $s['trips'], 0, max( 0, (int) ( $assoc['list'] ?? 30 ) ) ) as $t ) {
			WP_CLI::log( sprintf( '  %s UTC  %-39s %-5s until %s%s  %s', gmdate( 'Y-m-d H:i:s', $t['at'] ), $t['ip'], $t['reason'], gmdate( 'H:i', $t['until'] ), '' === $t['claim'] ? '' : '  claims ' . $t['claim'], implode( ' ', $t['paths'] ) ) );
		}
		foreach ( $s['pending'] as $ip => $claim ) {
			WP_CLI::log( sprintf( '  pending  %-39s claims %s', $ip, $claim ) );
		}
		WP_CLI::log( '' );
		WP_CLI::log( 'A log does not say who was logged in; every request was treated as anonymous. Without --root the missing-PHP rule was skipped.' );
	}

	/**
	 * @param array $events path => event
	 * @return string
	 */
	private static function events( array $events ) {
		$out = [];
		foreach ( $events as $path => $e ) {
			$out[] = basename( $path ) . ' ' . $e;
		}
		return implode( ', ', $out );
	}
}

/**
 * Define the log sources Bot Storm Radar reads.
 */
class BSR_CLI_Source {

	/**
	 * Add or update a log source.
	 *
	 * The first ingest after adding a source starts at the current end of its
	 * files; nothing older is read.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Lowercase letters and digits, starting with a letter (e.g. wiki).
	 *
	 * --profile=<profile>
	 * : Request classes to use.
	 * ---
	 * options:
	 *   - mediawiki
	 *   - wordpress
	 * ---
	 *
	 * --logs=<paths>
	 * : Comma-separated absolute paths of combined-format access logs.
	 *
	 * [--label=<label>]
	 * : Name shown on the Radar screen and in alerts.
	 *
	 * [--alert-to=<emails>]
	 * : Comma-separated alert recipients for this source. Default: the site's
	 * alert recipients. An empty value goes back to the default. Alerts start
	 * once the source's baseline holds one full day.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bot-storm-radar source add wiki --profile=mediawiki --label="Wiki" --logs=/var/log/nginx/wiki_access.log,/var/log/nginx/wiki-en_access.log
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function add( $args, $assoc ) {
		$id = (string) $args[0];
		if ( ! BSR_Sources::is_valid( $id ) ) {
			WP_CLI::error( sprintf( '"%s" is not a valid source id: 1 to 20 lowercase letters and digits, starting with a letter, and not one of %s.', $id, implode( ', ', BSR_Sources::RESERVED ) ) );
		}
		$logs = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['logs'] ) ) ) );
		if ( empty( $logs ) ) {
			WP_CLI::error( 'Give at least one log file with --logs.' );
		}
		foreach ( $logs as $path ) {
			if ( 0 !== strpos( $path, '/' ) ) {
				WP_CLI::error( sprintf( '%s is not an absolute path.', $path ) );
			}
			if ( ! is_file( $path ) ) {
				WP_CLI::warning( sprintf( '%s does not exist yet; it will be read from its first line once it does.', $path ) );
			} elseif ( ! is_readable( $path ) ) {
				WP_CLI::error( sprintf( '%s is not readable by this user.', $path ) );
			}
		}
		$existing = BSR_Log_Source::get( $id );
		$alert_to = (string) ( $existing['alert_to'] ?? '' );
		if ( array_key_exists( 'alert-to', $assoc ) ) {
			$given = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['alert-to'] ) ) ) );
			$valid = BSR_Helpers::sanitize_email_list( (string) $assoc['alert-to'] );
			if ( count( $valid ) !== count( $given ) ) {
				WP_CLI::error( sprintf( 'Not valid email addresses: %s.', implode( ', ', array_diff( $given, $valid ) ) ) );
			}
			$alert_to = implode( ', ', $valid );
		}
		BSR_Log_Source::save( $id, [
			'label'    => $assoc['label'] ?? ( $existing['label'] ?? ucfirst( $id ) ),
			'profile'  => $assoc['profile'],
			'logs'     => $logs,
			'alert_to' => $alert_to,
			'created'  => $existing['created'] ?? time(),
		] );
		WP_CLI::success( sprintf( '%s source "%s" with %d log file(s).', null === $existing ? 'Added' : 'Updated', $id, count( $logs ) ) );
	}

	/**
	 * List log sources.
	 *
	 * @subcommand list
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function list_( $args, $assoc ) {
		$rows = [];
		foreach ( BSR_Log_Source::all() as $id => $def ) {
			$st     = BSR_Log_Source::state( $id );
			$storm  = BSR_Storm::get_state( $id );
			$rows[] = [
				'id'       => $id,
				'label'    => $def['label'],
				'profile'  => $def['profile'],
				'logs'     => implode( ',', $def['logs'] ),
				'state'    => $storm['state'],
				'last_run' => $st['last_run'] ? gmdate( 'Y-m-d H:i:s', $st['last_run'] ) . ' UTC' : 'never',
				'alert_to' => implode( ', ', BSR_Sources::alert_recipients( $id ) ) . ( '' === (string) ( $def['alert_to'] ?? '' ) ? ' (site default)' : '' ),
				'alerts'   => BSR_Sources::alerts_ready( $id ) ? 'on' : 'after the first full day',
			];
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, [ 'id', 'label', 'profile', 'logs', 'state', 'last_run', 'alert_to', 'alerts' ] );
	}

	/**
	 * Forget a log source's learned baseline and start the seven-day learning
	 * period again.
	 *
	 * Needed once after upgrading to a version that stops counting refused
	 * requests (403, 429, 444): a baseline learned with them in is too high
	 * and hides real swarms. The source stays silent until the new baseline
	 * holds one full day.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Source id.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bot-storm-radar source reset-baseline wiki
	 *
	 * @subcommand reset-baseline
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function reset_baseline( $args, $assoc ) {
		$id = (string) $args[0];
		if ( null === BSR_Log_Source::get( $id ) ) {
			WP_CLI::error( sprintf( 'No source "%s".', $id ) );
		}
		$b   = BSR_Baseline::effective( $id );
		$was = empty( $b['ips_median'] ) ? 'nothing learned yet' : sprintf( 'was median %s distinct addresses per minute over %d day(s), %s', BSR_Metrics::fmt( $b['ips_median'], 0 ), (int) $b['days'], (string) $b['status'] );
		BSR_Baseline::reset( $id );
		WP_CLI::success( sprintf( 'Baseline of "%s" forgotten (%s). Learning starts again from the next full UTC day; alerts for this source resume once the baseline holds one full day.', $id, $was ) );
	}

	/**
	 * Remove a log source.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Source id.
	 *
	 * [--purge]
	 * : Also delete its stored minutes, state, baseline and file positions.
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function remove( $args, $assoc ) {
		$id = (string) $args[0];
		if ( null === BSR_Log_Source::get( $id ) ) {
			WP_CLI::error( sprintf( 'No source "%s".', $id ) );
		}
		BSR_Log_Source::remove( $id, ! empty( $assoc['purge'] ) );
		WP_CLI::success( sprintf( 'Removed source "%s"%s.', $id, empty( $assoc['purge'] ) ? '' : ' and its data' ) );
	}
}
