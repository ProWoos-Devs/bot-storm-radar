# Changelog

All notable changes to **Bot Storm Radar** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
