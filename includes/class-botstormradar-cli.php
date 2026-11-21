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
class BotStormRadar_CLI {

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
		$ids = empty( $args ) ? array_keys( BotStormRadar_Log_Source::all() ) : $args;
		if ( empty( $ids ) ) {
			WP_CLI::error( __( 'No log sources are defined. Add one with `wp bot-storm-radar source add`.', 'bot-storm-radar' ) );
		}
		$failed = false;
		foreach ( $ids as $id ) {
			$t0  = microtime( true );
			$sum = BotStormRadar_Log_Source::run( $id );
			$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
			if ( 'unknown' === $sum['status'] ) {
				/* translators: %s: log source id */
				WP_CLI::warning( sprintf( __( '%s: no such source.', 'bot-storm-radar' ), $id ) );
				$failed = true;
				continue;
			}
			foreach ( $sum['transitions'] ?? [] as $t ) {
				/* translators: 1: log source id, 2: previous storm state, 3: new storm state, 4: date and time, 5: storm score, 6: explanation sentence */
				WP_CLI::log( sprintf( __( '%1$s: %2$s -> %3$s at %4$s UTC, score %5$d. %6$s', 'bot-storm-radar' ), $id, $t['from'], $t['to'], gmdate( 'Y-m-d H:i', $t['minute'] * 60 ), $t['score'], $t['explanation'] ) );
			}
			if ( 'ok' === $sum['status'] && ! empty( $assoc['quiet-ok'] ) && empty( $sum['events'] ) ) {
				continue;
			}
			/* translators: %s: files and what happened to them, such as "access.log rotated" */
			$files   = empty( $sum['events'] ) ? '' : sprintf( __( ', files: %s', 'bot-storm-radar' ), self::events( $sum['events'] ) );
			$stopped = empty( $sum['stopped'] ) ? '' : __( ', stopped at the read budget', 'bot-storm-radar' );
			WP_CLI::log( sprintf(
				/* translators: 1: log source id, 2: run status, 3: minutes stored, 4: log lines read, 5: size read, 6: late lines, 7: unparsed lines, 8: refused requests, 9: optional ", files: ..." part, 10: optional ", stopped at the read budget" part, 11: milliseconds */
				__( '%1$s: %2$s, %3$d minutes, %4$d lines, %5$s read, %6$d late, %7$d unparsed, %8$d refused%9$s%10$s, %11$d ms.', 'bot-storm-radar' ),
				$id,
				$sum['status'],
				(int) ( $sum['minutes'] ?? 0 ),
				(int) ( $sum['lines'] ?? 0 ),
				size_format( (int) ( $sum['bytes'] ?? 0 ) ),
				(int) ( $sum['late'] ?? 0 ),
				(int) ( $sum['bad'] ?? 0 ),
				(int) ( $sum['refused'] ?? 0 ),
				$files,
				$stopped,
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
	 * [--csv]
	 * : Print every minute as CSV (UTC time, score, addresses, requests) instead of the summary. Redirect it to keep it, for example `> replay.csv`.
	 *
	 * @subcommand replay
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function replay( $args, $assoc ) {
		foreach ( $args as $f ) {
			if ( ! is_readable( $f ) ) {
				/* translators: %s: file path */
					WP_CLI::error( sprintf( __( 'Cannot read %s.', 'bot-storm-radar' ), $f ) );
			}
		}
		if ( ! empty( $assoc['gate'] ) ) {
			$this->replay_gate( $args, $assoc );
			return;
		}
		if ( empty( $assoc['profile'] ) ) {
			WP_CLI::error( __( 'Give --profile=mediawiki|wordpress, or --gate.', 'bot-storm-radar' ) );
		}
		$baseline = isset( $assoc['baseline-ips'] ) ? [ 'ips_median' => (float) $assoc['baseline-ips'] ] : null;
		$t0       = microtime( true );
		$sum      = BotStormRadar_Log_Source::replay( BotStormRadar_Sources::REPLAY, $assoc['profile'], $args, $baseline );
		$ms       = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( ! empty( $assoc['csv'] ) ) {
			// Machine-readable header, deliberately not translated.
			WP_CLI::line( 'minute_utc,score,ips,requests' );
			foreach ( $sum['scores'] as $m => $s ) {
				WP_CLI::line( gmdate( 'Y-m-d H:i', $m * 60 ) . ',' . implode( ',', array_map( 'intval', $s ) ) );
			}
			BotStormRadar_Log_Source::purge( BotStormRadar_Sources::REPLAY );
			return;
		}

		/* translators: 1: minutes stored, 2: log lines read, 3: size read, 4: unparsed lines, 5: late lines, 6: refused requests, 7: milliseconds */
		WP_CLI::log( sprintf( __( '%1$d minutes, %2$d lines, %3$s, %4$d unparsed, %5$d late, %6$d refused by the web server (403, 429, 444; not counted), %7$d ms.', 'bot-storm-radar' ), $sum['minutes'], $sum['lines'], size_format( $sum['bytes'] ), $sum['bad'], $sum['late'], (int) ( $sum['refused'] ?? 0 ), $ms ) );
		WP_CLI::log( '' );
		WP_CLI::log( __( 'Transitions:', 'bot-storm-radar' ) );
		foreach ( $sum['transitions'] as $t ) {
			/* translators: 1: date and time, 2: previous storm state, 3: new storm state, 4: storm score, 5: explanation sentence */
			WP_CLI::log( '  ' . sprintf( __( '%1$s UTC  %2$s -> %3$s  score %4$d. %5$s', 'bot-storm-radar' ), gmdate( 'Y-m-d H:i', $t['minute'] * 60 ), $t['from'], $t['to'], $t['score'], $t['explanation'] ) );
		}
		if ( empty( $sum['transitions'] ) ) {
			WP_CLI::log( '  ' . __( 'none', 'bot-storm-radar' ) );
		}

		$scores = $sum['scores'];
		uasort( $scores, function ( $a, $b ) {
			return $b[0] <=> $a[0] ?: $b[1] <=> $a[1];
		} );
		WP_CLI::log( '' );
		WP_CLI::log( __( 'Highest-scoring minutes (UTC, score, addresses, requests):', 'bot-storm-radar' ) );
		foreach ( array_slice( $scores, 0, max( 1, (int) $assoc['top'] ), true ) as $m => $s ) {
			WP_CLI::log( sprintf( '  %s  %3d  %6d  %6d', gmdate( 'Y-m-d H:i', $m * 60 ), $s[0], $s[1], $s[2] ) );
		}
		if ( ! empty( $sum['scores'] ) ) {
			$ips = array_column( $sum['scores'], 1 );
			WP_CLI::log( '' );
			/* translators: 1: median number of distinct addresses per minute, 2: 90th percentile, 3: maximum */
			WP_CLI::log( sprintf( __( 'Distinct addresses per minute: median %1$s, p90 %2$s, max %3$d.', 'bot-storm-radar' ), BotStormRadar_Metrics::fmt( BotStormRadar_Baseline::median( $ips ), 0 ), BotStormRadar_Metrics::fmt( BotStormRadar_Baseline::percentile( $ips, 0.9 ), 0 ), max( $ips ) ) );
		}
		BotStormRadar_Log_Source::purge( BotStormRadar_Sources::REPLAY );
	}

	/**
	 * The --gate dry run.
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	private function replay_gate( $args, $assoc ) {
		$t0  = microtime( true );
		$s   = BotStormRadar_Gate_Replay::run( $args, [
			'root'        => $assoc['root'] ?? '',
			'home'        => $assoc['home'] ?? '',
			'verify_bots' => ! empty( $assoc['verify-bots'] ),
		] );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$set = BotStormRadar_Trips::settings();
		$pct = function ( $a, $b ) {
			return $b > 0 ? round( 100 * $a / $b ) . '%' : '-';
		};
		/* translators: 1: first date and time in the logs, 2: last date and time in the logs, 3: log lines read, 4: unparsed lines, 5: milliseconds */
		WP_CLI::log( sprintf( __( 'Gate dry run over %1$s to %2$s UTC: %3$d lines (%4$d unparsed), %5$d ms. Nothing was written.', 'bot-storm-radar' ), gmdate( 'Y-m-d H:i', (int) $s['first'] ), gmdate( 'Y-m-d H:i', (int) $s['last'] ), $s['lines'], $s['bad'], $ms ) );
		/* translators: 1: probes that trip a ban, 2: window in minutes, 3: missing pages a minute that trip a ban, 4: ban length in minutes, 5: ban length on a repeat in hours */
		WP_CLI::log( sprintf( __( 'Settings: probe trip %1$d in %2$d min, page-404 trip %3$d a minute, ban %4$d min (%5$d h on a repeat), simulated as enforce mode.', 'bot-storm-radar' ), $set['probe']['count'], intdiv( $set['probe']['window'], 60 ), $set['404']['count'], intdiv( $set['probe']['ttl'], 60 ), intdiv( $set['ttl_repeat'], 3600 ) ) );
		WP_CLI::log( '' );
		/* translators: 1: requests the web server already refused, 2: how many of them were probes */
		WP_CLI::log( sprintf( __( 'Refused by the web server already (403, 429, 444): %1$d, of which %2$d probes. The gate never sees these.', 'bot-storm-radar' ), $s['server_refused'], $s['server_probes'] ) );
		/* translators: %d: number of requests */
		WP_CLI::log( sprintf( __( 'Static files served by the web server: %d.', 'bot-storm-radar' ), $s['static'] ) );
		/* translators: %d: number of requests */
		WP_CLI::log( sprintf( __( 'Reached PHP: %d.', 'bot-storm-radar' ), $s['php'] ) );
		$cls = [];
		foreach ( $s['probe_classes'] as $c => $n ) {
			$cls[] = $c . ' ' . $n;
		}
		/* translators: 1: number of probes, 2: probe classes with their counts, or "none" */
		WP_CLI::log( '  ' . sprintf( __( 'probes the gate would refuse: %1$d (%2$s)', 'bot-storm-radar' ), $s['probes'], $cls ? implode( ', ', $cls ) : __( 'none', 'bot-storm-radar' ) ) );
		/* translators: %d: number of requests */
		WP_CLI::log( '  ' . sprintf( __( 'requests from addresses under a simulated ban: %d', 'bot-storm-radar' ), $s['banned_hits'] ) );
		/* translators: 1: page builds saved, 2: requests that reached PHP, 3: percentage */
		WP_CLI::log( '  ' . sprintf( __( 'WordPress builds saved: %1$d of %2$d (%3$s)', 'bot-storm-radar' ), $s['saved'], $s['php'], $pct( $s['saved'], $s['php'] ) ) );
		WP_CLI::log( '' );
		if ( empty( $assoc['verify-bots'] ) ) {
			/* translators: 1: bans, 2: addresses, 3: trips on protected addresses skipped, 4: search-bot claims */
			$trips_line = __( 'Trips: %1$d bans for %2$d addresses; %3$d trips on protected addresses skipped; %4$d search-bot claims held pending (not verified; add --verify-bots).', 'bot-storm-radar' );
		} else {
			/* translators: 1: bans, 2: addresses, 3: trips on protected addresses skipped, 4: search-bot claims */
			$trips_line = __( 'Trips: %1$d bans for %2$d addresses; %3$d trips on protected addresses skipped; %4$d search-bot claims checked by DNS.', 'bot-storm-radar' );
		}
		WP_CLI::log( sprintf( $trips_line, count( $s['trips'] ), $s['trip_addresses'], $s['protected_skip'], count( $s['pending'] ) ) );
		foreach ( array_slice( $s['trips'], 0, max( 0, (int) ( $assoc['list'] ?? 30 ) ) ) as $t ) {
			/* translators: %s: the search bot the address claims to be */
			$claim = '' === $t['claim'] ? '' : sprintf( __( '  claims %s', 'bot-storm-radar' ), $t['claim'] );
			/* translators: 1: date and time, 2: IP address, 3: reason, 4: time the ban ends, 5: optional "claims ..." part, 6: probed paths */
			WP_CLI::log( '  ' . sprintf( __( '%1$s UTC  %2$-39s %3$-5s until %4$s%5$s  %6$s', 'bot-storm-radar' ), gmdate( 'Y-m-d H:i:s', $t['at'] ), $t['ip'], $t['reason'], gmdate( 'H:i', $t['until'] ), $claim, implode( ' ', $t['paths'] ) ) );
		}
		foreach ( $s['pending'] as $ip => $claim ) {
			/* translators: 1: IP address, 2: the search bot the address claims to be */
			WP_CLI::log( '  ' . sprintf( __( 'pending  %1$-39s claims %2$s', 'bot-storm-radar' ), $ip, $claim ) );
		}
		WP_CLI::log( '' );
		WP_CLI::log( __( 'A log does not say who was logged in; every request was treated as anonymous. Without --root the missing-PHP rule was skipped.', 'bot-storm-radar' ) );
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
class BotStormRadar_CLI_Source {

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
		if ( ! BotStormRadar_Sources::is_valid( $id ) ) {
			/* translators: 1: the id that was given, 2: comma-separated list of reserved ids */
			WP_CLI::error( sprintf( __( '"%1$s" is not a valid source id: 1 to 20 lowercase letters and digits, starting with a letter, and not one of %2$s.', 'bot-storm-radar' ), $id, implode( ', ', BotStormRadar_Sources::RESERVED ) ) );
		}
		$logs = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['logs'] ) ) ) );
		if ( empty( $logs ) ) {
			WP_CLI::error( __( 'Give at least one log file with --logs.', 'bot-storm-radar' ) );
		}
		foreach ( $logs as $path ) {
			if ( 0 !== strpos( $path, '/' ) ) {
				/* translators: %s: file path */
					WP_CLI::error( sprintf( __( '%s is not an absolute path.', 'bot-storm-radar' ), $path ) );
			}
			if ( ! is_file( $path ) ) {
				/* translators: %s: file path */
					WP_CLI::warning( sprintf( __( '%s does not exist yet; it will be read from its first line once it does.', 'bot-storm-radar' ), $path ) );
			} elseif ( ! is_readable( $path ) ) {
				/* translators: %s: file path */
					WP_CLI::error( sprintf( __( '%s is not readable by this user.', 'bot-storm-radar' ), $path ) );
			}
		}
		$existing = BotStormRadar_Log_Source::get( $id );
		$alert_to = (string) ( $existing['alert_to'] ?? '' );
		if ( array_key_exists( 'alert-to', $assoc ) ) {
			$given = array_values( array_filter( array_map( 'trim', explode( ',', (string) $assoc['alert-to'] ) ) ) );
			$valid = BotStormRadar_Helpers::sanitize_email_list( (string) $assoc['alert-to'] );
			if ( count( $valid ) !== count( $given ) ) {
				/* translators: %s: comma-separated list of the rejected addresses */
					WP_CLI::error( sprintf( __( 'Not valid email addresses: %s.', 'bot-storm-radar' ), implode( ', ', array_diff( $given, $valid ) ) ) );
			}
			$alert_to = implode( ', ', $valid );
		}
		BotStormRadar_Log_Source::save( $id, [
			'label'    => $assoc['label'] ?? ( $existing['label'] ?? ucfirst( $id ) ),
			'profile'  => $assoc['profile'],
			'logs'     => $logs,
			'alert_to' => $alert_to,
			'created'  => $existing['created'] ?? time(),
		] );
		if ( null === $existing ) {
			/* translators: 1: log source id, 2: number of log files */
			$done = _n( 'Added source "%1$s" with %2$d log file.', 'Added source "%1$s" with %2$d log files.', count( $logs ), 'bot-storm-radar' );
		} else {
			/* translators: 1: log source id, 2: number of log files */
			$done = _n( 'Updated source "%1$s" with %2$d log file.', 'Updated source "%1$s" with %2$d log files.', count( $logs ), 'bot-storm-radar' );
		}
		WP_CLI::success( sprintf( $done, $id, count( $logs ) ) );
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
		foreach ( BotStormRadar_Log_Source::all() as $id => $def ) {
			$st     = BotStormRadar_Log_Source::state( $id );
			$storm  = BotStormRadar_Storm::get_state( $id );
			$rows[] = [
				'id'       => $id,
				'label'    => $def['label'],
				'profile'  => $def['profile'],
				'logs'     => implode( ',', $def['logs'] ),
				'state'    => $storm['state'],
				'last_run' => $st['last_run'] ? gmdate( 'Y-m-d H:i:s', $st['last_run'] ) . ' UTC' : __( 'never', 'bot-storm-radar' ),
				'alert_to' => implode( ', ', BotStormRadar_Sources::alert_recipients( $id ) ) . ( '' === (string) ( $def['alert_to'] ?? '' ) ? ' ' . __( '(site default)', 'bot-storm-radar' ) : '' ),
				'alerts'   => BotStormRadar_Sources::alerts_ready( $id ) ? __( 'on', 'bot-storm-radar' ) : __( 'after the first full day', 'bot-storm-radar' ),
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
		if ( null === BotStormRadar_Log_Source::get( $id ) ) {
			/* translators: %s: log source id */
			WP_CLI::error( sprintf( __( 'No source "%s".', 'bot-storm-radar' ), $id ) );
		}
		$b = BotStormRadar_Baseline::effective( $id );
		if ( empty( $b['ips_median'] ) ) {
			$was = __( 'nothing learned yet', 'bot-storm-radar' );
		} else {
			/* translators: 1: median distinct addresses per minute, 2: number of days, 3: baseline status */
			$was = sprintf( _n( 'was median %1$s distinct addresses per minute over %2$d day, %3$s', 'was median %1$s distinct addresses per minute over %2$d days, %3$s', (int) $b['days'], 'bot-storm-radar' ), BotStormRadar_Metrics::fmt( $b['ips_median'], 0 ), (int) $b['days'], (string) $b['status'] );
		}
		BotStormRadar_Baseline::reset( $id );
		/* translators: 1: log source id, 2: what the baseline was, for example "nothing learned yet" */
		WP_CLI::success( sprintf( __( 'Baseline of "%1$s" forgotten (%2$s). Learning starts again from the next full UTC day; alerts for this source resume once the baseline holds one full day.', 'bot-storm-radar' ), $id, $was ) );
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
		if ( null === BotStormRadar_Log_Source::get( $id ) ) {
			/* translators: %s: log source id */
			WP_CLI::error( sprintf( __( 'No source "%s".', 'bot-storm-radar' ), $id ) );
		}
		BotStormRadar_Log_Source::remove( $id, ! empty( $assoc['purge'] ) );
		if ( empty( $assoc['purge'] ) ) {
			/* translators: %s: log source id */
			$done = __( 'Removed source "%s".', 'bot-storm-radar' );
		} else {
			/* translators: %s: log source id */
			$done = __( 'Removed source "%s" and its data.', 'bot-storm-radar' );
		}
		WP_CLI::success( sprintf( $done, $id ) );
	}
}
