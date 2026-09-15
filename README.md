# Bot Storm Radar

[![Version](https://img.shields.io/badge/Version-0.1.4-red.svg)](https://github.com/ProWoos-Devs/bot-storm-radar/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Detects bot swarms as swarms.** A WordPress plugin that classifies every request, keeps sliding-window counters, computes swarm-level metrics and a storm score, learns the site's normal traffic, and tells you in plain words whether a bot storm is running right now and why.

> **Current Version: 0.1.4** | **Released: September 15, 2026**

## Why

On 2026-08-19 one operator sent 268,851 requests to a WordPress site in three hours from 256,441 distinct addresses. 250,052 of them made exactly one request. Six browser user-agent strings were rotated evenly across the pool, and 99 percent of the addresses never fetched a stylesheet or a script. Every per-IP tool on that server worked correctly and none of them could see the attack, because no single address did anything wrong.

Bot Storm Radar looks at the crowd instead of the individual.

## What 0.1 does

This is the radar-only release. It observes and reports. **Nothing is blocked, challenged, or rate-limited.** Its purpose is to calibrate the swarm metrics against real traffic before any later release can affect a visitor.

- **Request classes.** Every request is classified once: html, search, rest (users, Store API, other), xmlrpc, login, register, comment, admin-ajax, wc-ajax, checkout, cart, asset, 404. WooCommerce adds its classes when present.
- **Counters** in sliding one-minute and ten-minute windows keyed by address, IPv4 /24, IPv6 /48, user-agent hash and WordPress session. Stored in the persistent object cache when there is one (Redis, Memcached), in APCu next, and in a transient fallback as the last resort. The Radar screen says which backend is active and warns on the fallback.
- **Swarm metrics** every minute: single-hit ratio, asset ratio (a beacon that real browsers fetch and swarms do not, uncacheable so page caches do not hide it), user-agent evenness, error pressure, endpoint concentration, and the combined storm score, gated by traffic volume against the learned baseline.
- **Learned baseline** over the first seven days: median distinct addresses per minute and the normal asset ratio, shown beside each threshold.
- **Good-bot verification.** Googlebot, Bingbot, Applebot and Yandex are verified by reverse DNS plus forward confirmation, DuckDuckBot against its published address list, cached per address for a day. A user agent alone proves nothing; the dashboard labels claims as verified, fake, or pending.
- **Storm states** calm, warning, storm, cooling, with the real transitions and hold times. Every transition is logged with the metrics that caused it and an explanation in words.
- **Alerts** by plain-text email on warning, storm, and all-clear.
- **Trusted-proxy address resolution.** A forwarding header is believed only when the request arrived through a known proxy: Cloudflare (ranges fetched daily, bundled fallback), a local proxy (private, link-local or loopback peer address), or a proxy you declare. Spoofed `X-Forwarded-For` from an ordinary client is ignored. An undeclared public proxy is detected and can be trusted with one click.
- **Radar screen** with the current state, the last 24 hours per minute, top classes, top keys, bot claims, and the storm timeline. Dashboard widget with the state and the last storm.

## What it does not do yet

Bans, the challenge ladder, the must-use gate, exporters (Cloudflare, CrowdSec, fail2ban, web-server include, AbuseIPDB), the tarpit, telemetry, multisite. See the roadmap in the design document.

## Requirements

- WordPress 6.0+
- PHP 7.4+
- A persistent object cache or APCu is strongly recommended. Without one, counters fall back to transients in the database.

## Installation

1. Upload the `bot-storm-radar` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Open **Bot Storm Radar** in wp-admin. Leave it running for a week so the baseline can learn, then review the thresholds on the Settings tab.

## Log sources (in development)

Traffic that never reaches WordPress, such as a MediaWiki on the same server or requests nginx answers from its page cache or refuses at a gate, can be read from the web server's access log (nginx or Apache `combined` format). Each log source keeps its own minute rows, learned baseline and storm state. Sources are defined and run from WP-CLI only:

```bash
wp bot-storm-radar source add wiki --profile=mediawiki --label="Wiki" \
  --logs=/var/log/nginx/wiki_access.log,/var/log/nginx/wiki-en_access.log
wp bot-storm-radar ingest            # once a minute, from a system timer
wp bot-storm-radar source list
wp bot-storm-radar replay old.log.gz --profile=mediawiki --baseline-ips=60   # what the radar would have said
```

Profiles: `mediawiki` (page views, special pages, old revisions, search, login, api.php, with `load.php` as the real-browser signal) and `wordpress`. The first ingest starts at the current end of the files. Log sources do not send alerts yet.

## Hooks

- `bsr_request_class( array $result, string $path, array $query, array $server )` adds or overrides the request class.
- `bsr_known_classes( array $classes )` extends the fixed set of classes the metrics enumerate.
- `bsr_actions( BSR_Actions[] $actions )` attaches implementations of the `BSR_Actions` interface, which later releases use for enforcement and export.

## Development

### Version Bump

```bash
./dev-tools/version-bump.sh [major|minor|patch] "description"
```

Updates the version in the plugin header, the `BSR_VERSION` constant, the README.md badge, and CHANGELOG.md.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed history of changes.

## License

GPL v2 or later.
