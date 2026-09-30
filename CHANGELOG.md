# Changelog

All notable changes to **Bot Storm Radar** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- A stable gate loader, a manifest and versioned gate copies. The data directory now holds `loader.php`, written once and never rewritten, which reads `manifest.php` (the `<?php exit; ?>` plus JSON format, read as data) and includes the gate copy it names, `gate-<version>-<hash>.php`. With `opcache.validate_timestamps=0` a PHP worker keeps the version of a file it compiled first, so each new gate gets a new name instead of rewriting the old one. The two previous copies are kept, and older ones are deleted once the manifest has pointed away from them for more than an hour. The mu-plugin now includes the loader.
- The `disabled` marker. While a file named `disabled` exists in the data directory the loader does nothing, which switches the gate off within one request. Deactivation writes it first and activation removes it last; the README explains how to create it by SFTP. Uninstall now keeps the loader, the marker and the directory, because a cached `auto_prepend_file` line may still point at the loader.
- The gate. `BSR_Gate` runs before themes and plugins without loading WordPress or touching the database. It resolves the visitor with the state file's trust configuration, lets protected addresses through (private and reserved addresses, the CDN and declared proxy ranges, the allowlist, administrator and verified-bot addresses) and, in enforce mode, answers 403 to an address under an active ban. The expiry is checked on every request, so a ban ends without cron, and a login cookie is not an exemption because the gate cannot validate one. Enforce mode has no setting yet, so the gate observes only and refuses no one.
- `BSR_Gate_Install` writes the gate as one self-contained file in the data directory (the resolver, the state reader and the gate, with every class renamed to a `BSR_Gate_` prefix so they never collide with the plugin's own), checks that it parses before it replaces the previous one, and installs a mu-plugin loader, `wp-content/mu-plugins/bot-storm-radar-gate.php`, that includes it by absolute path only when the file exists. Installed on activation and on every version change, and removed on deactivation and uninstall. Where APCu is available the gate caches the decoded state, keyed on the file's inode, modification time and size.
- Ban tables `{prefix}bsr_bans` and `{prefix}bsr_ban_exports` (schema version in `bsr_db_version`, created on activation and on load when missing). One row per banned key, unique on the address in binary form plus the prefix length, so an address and its /64 are separate keys. `BSR_Bans::trip()` is a single `INSERT ... ON DUPLICATE KEY UPDATE` that keeps the later expiry and counts the trip; `BSR_Bans::unban()` is a single `UPDATE` that ends the ban now, records who lifted it, and raises the generation number (`bsr_generation`) that later lets an unban override bans decided before it. Evidence is capped at 10 entries of 200 characters per list. Rows that expired more than 30 days ago are deleted daily unless an export removal is still pending. Nothing writes to these tables yet; the gate and the trips arrive in later changes.
- `uninstall.php`, which drops the two ban tables and their options. The rest of the plugin's data is left as it was.
- The gate's state file. It lives in a data directory `wp-content/bot-storm-radar-<random>/` (suffix kept in `bsr_data_dir`) with an `index.php` and an Apache deny-all `.htaccess`; the README gives the nginx rule. `state.php` starts with `<?php exit; ?>` followed by JSON: a web request for it returns an empty body, and `BSR_State_Reader` (no WordPress needed) reads it as data, so opcache never serves a stale copy. `BSR_State::rebuild()` builds it from the ban tables and the options under an exclusive lock, writes a temporary and renames it into place. It runs on every ban and unban, on a settings save, on a Cloudflare range refresh and daily. It carries the trust configuration, active bans with their expiry, addresses unbanned in the last 24 hours, the generation number, the mode (observe until a later change adds the setting) and the alert recipients, never evidence. Uninstall removes the directory.

### Changed
- Client address resolution moved into `BSR_IP_Resolver`, a class with no WordPress dependency that takes the server variables and a trust configuration (Cloudflare ranges, declared proxies, the forwarding switch). `BSR_Client_IP::resolve()` calls it with the options, and `BSR_Client_IP::trust_config()` returns that configuration, so the v0.2 gate can resolve the visitor before WordPress loads and get the same answer. The pure IP helpers of `BSR_Helpers` (`is_valid_ip`, `is_public_ip`, `ip_in_cidr`, `ip_in_list`, `parse_list`) delegate to it. No behavior change.

## [0.1.7] - 2026-09-22

### Added
- Absolute 5xx rule for log sources. A minute with at least `error_burst_5xx` responses in the 5xx range (default 20) mails one alert per episode and logs it in the source's storm timeline without changing the storm state; the episode ends after `error_burst_clear_minutes` minutes (default 15) below the threshold. The ratio rule needs 5xx to be half of a minute's requests, so 108 HTTP 500 in fourteen seconds of a 700-request minute never fired it.
- `wp bot-storm-radar source reset-baseline <id>` forgets a log source's learned baseline and starts learning again.

### Changed
- Plain-language description. The plugin header and the README now open with the problem (swarms of single-request addresses that per-address tools cannot see) instead of the metric names, the Radar tab starts with a short paragraph saying what the score measures and that a quiet site scores 0, the score card says where warning and storm start, and the "Plumbing" card is called "Status". Prompted by a tester who could not tell what the plugin was for from its own screens.
- Behind a page cache (`WP_CACHE` on) with slow requests still counting toward error pressure, the Settings tab and the Radar tab now say that slow requests measure cache misses there and to set "Slow request" to 0.
- Log sources no longer count requests the web server refused (status 403, 429 or 444) toward addresses, volume or request classes. They go to a new `refused` figure on the minute row, shown on the Radar tab's score card, in the explanation of every transition, in alert mails and in the ingest and replay output. On a wiki whose server refuses crawler requests at a gate, the seven-day baseline had learned 308 addresses a minute while the served traffic ran at 30 to 83, so a known flood replayed against it produced no transition and the volume gate opened only above five times the real traffic. Existing log sources need `wp bot-storm-radar source reset-baseline <id>` once after the upgrade.

### Fixed
- `wp bot-storm-radar replay` with `--baseline-ips` sent real alert mails to the site's recipients. The figure is stored as the scratch source's learned baseline so that the explanations read it, and a learned baseline is what switches a log source's alerts on. The replay source is now excluded from mailing whatever its baseline says, as the command's help always promised.

## [0.1.6] - 2026-09-15

### Fixed
- A log source's alerts now wait for a baseline day with at least 23 hours of data, as documented. In 0.1.5 they started at the first midnight after the source was added, because the baseline also summarizes the partial first day (60 minutes of data are enough for that).

## [0.1.5] - 2026-09-15

### Added
- Traffic sources. The site keeps its option names; other sources store their minute rows, baseline, state and transitions under `bsr_<source>_*`.
- Log sources read from a web server's access log (`combined` format) through WP-CLI: `wp bot-storm-radar source add|list|remove`, `wp bot-storm-radar ingest`, `wp bot-storm-radar replay`. Profiles `mediawiki` and `wordpress`. Positions are kept per file by inode and offset and committed at minute boundaries; daily rotation by rename and in-place truncation are followed. A log source mails its transitions once its baseline holds one full day, to its own recipients (`--alert-to`) or the site's; the subject and body name the source and link to its radar.
- Radar tab per source: a source switcher, each source's own state, last minute, baseline, chart, classes, top keys and storm timeline, and for a log source a log reader card with its files, the last ingest, its alert recipients and reset links. The dashboard widget lists every log source and warns when a log has not been read for five minutes. The Settings tab lists log sources read-only.
- In-memory counter backend, used by the log reader only.
- Request classes `special` and `revision` (MediaWiki special pages, old revisions, diffs and non-view actions), both counted as sensitive for endpoint concentration.

### Fixed
- A storm held up by error pressure alone (many 5xx at a low score) no longer leaves and re-enters the storm state in the same minute every `storm_hold_minutes`. Those minutes counted as quiet for the hold, so storm moved to cooling and the error-pressure rule sent it straight back, with a "Bot storm detected" alert each time. Cooling now starts only after the hold passes with neither a high score nor high error pressure.

## [0.1.4] - 2026-09-15

### Changed
- A minute that takes the radar from calm through warning to storm now sends one alert, "Bot storm detected", saying it went from CALM through WARNING to STORM within one minute. Before, the warning and the storm mail went out in the same second for every such episode. Both transitions are still logged on the Radar screen. With storm alerts switched off, the warning alert is still sent.
- For custom `BSR_Actions` implementations, the warning context of such a minute carries `continues_to` => `storm` and the storm context `started_from` => `calm`.

## [0.1.3] - 2026-09-04

### Fixed
- The "error pressure alone" rule no longer turns a quiet minute into a storm. It now needs the same minimum of distinct addresses as the score (the "Minimum distinct addresses" setting). With a page cache in front, PHP mostly sees cache misses, and three slow requests from two addresses were enough to re-trigger a storm every fifteen minutes and email each time.
- The tick no longer repeats a minute it has already processed. A slow front-end request loads the options at its start; when the cron tick ran meanwhile, the inline guard at that request's shutdown saw a stale cursor and a stale state, recomputed the same minute and sent the transition alert again (two to five copies of each). The tick now drops the runtime options cache after taking its lock and re-reads the cursor, the state, and the stored rows.
- Alerts are formatted in the site language. When the inline guard ran the tick inside a translated front-end page, the date and the decimals followed that page's locale.

## [0.1.2] - 2026-09-03

### Changed
- Client IP resolution is now the same code as WC Antifraud 1.7.0's `WCAF_Client_IP`, copied with the prefix renamed, so the two plugins agree on who the client is and fixes land in both. Gains over 0.1.1: header values with a port suffix are normalized, carrier-grade NAT peers (100.64.0.0/10) count as local proxies, a Cloudflare peer without `CF-Connecting-IP` falls back to the forwarded-header walk, and the undeclared-proxy notice gains a "Not a proxy" dismissal (30 days) next to "Trust this proxy".
- The daily address-list refresh hook is now `bsr_refresh_cloudflare_ips` (the DuckDuckBot list refreshes on the same hook). An upgrade routine reschedules it and removes the 0.1.1 hook and options on the first request after the update.

## [0.1.1] - 2026-09-02

### Fixed
- The beacon URL now goes through `index.php` explicitly (`/index.php?bsr-beacon=`), so a plugin or server rule that redirects the bare root (a language redirect to `/en/`, a static front page) can no longer swallow it before the plugin answers. Filter `bsr_beacon_url_base`.
- On the APCu backend the minute tick refuses to run from the command line (wp-cli cron, `wp cron event run`): a CLI process cannot see the counters PHP-FPM wrote and would have stored empty minutes while advancing the cursor past the real data. The inline guard on the next front-end request does the work instead, and the Radar screen says so.

## [0.1.0] - 2026-09-02

Radar only. Detects and reports; nothing is blocked, challenged, or rate-limited.

### Added
- Request classifier with one class per request (html, search, rest, xmlrpc, login, register, comment, admin-ajax, wc-ajax, checkout, cart, asset, 404, other) and the `bsr_request_class` filter. The WooCommerce module adds wc-ajax with its action, Store API cart and checkout, and `?add-to-cart=`.
- Counter store with three backends, detected in order: persistent object cache with atomic increments, APCu, and a transient fallback that buffers per request and writes once at shutdown. Sliding one-minute and ten-minute windows keyed by address, IPv4 /24, IPv6 /48, user-agent hash and WordPress session. Counters never live in options on the request path.
- Swarm metrics per finished minute: single-hit ratio, asset ratio, user-agent evenness, error pressure, endpoint concentration, and the storm score gated by traffic volume against the baseline.
- Beacon: a one-pixel image and a JS ping on every front-end HTML page, answered by the plugin itself with 204 and `Cache-Control: no-store`, counted by address. Unique per page render and per ping, so page caches do not hide it.
- Learned baseline over the first seven days (median distinct addresses per minute, normal asset ratio), frozen once learned, shown beside each threshold, with a reset.
- Good-bot verification for Googlebot, Bingbot, Applebot, Yandex (reverse DNS plus forward confirmation, queued off the request path) and DuckDuckBot (published address list, bundled and refreshed daily). Verdicts cached per address for a day; the dashboard labels claims as verified, fake, or pending.
- Storm state machine with calm, warning, storm and cooling, configurable thresholds and hold times, escalation rungs, and a transition log with the metrics and a plain-words explanation for each transition. Actions go through the `BSR_Actions` interface; the only implementation logs and alerts.
- Minute tick through WP cron with a self-healing guard: when the tick is late, the next front-end request runs it after its response is flushed.
- Trusted-proxy address resolution: Cloudflare ranges (fetched daily, bundled fallback), local proxies trusted automatically, owner-declared proxies, detection of an undeclared public proxy with a one-click trust button, and an insecure legacy switch that trusts every forwarding header.
- Admin screen with the Radar tab (state, last 24 hours per minute, top classes, top keys, bot claims, storm timeline) and the Settings tab (thresholds with the baseline beside each, hold times, alert recipients, proxies). Dashboard widget with the state and the last storm.
- Plain-text alert emails on warning, storm, and all-clear.
- GitHub release updater.
