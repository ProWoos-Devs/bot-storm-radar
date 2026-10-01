# Bot Storm Radar

[![Version](https://img.shields.io/badge/Version-0.1.7-red.svg)](https://github.com/ProWoos-Devs/bot-storm-radar/releases)
[![WordPress](https://img.shields.io/badge/WordPress-6.0+-blue.svg)](https://wordpress.org/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Bot attacks no longer come from one address.** They come as swarms of thousands of addresses that each make one or two requests, so a tool that judges visitors one by one sees nothing wrong. Bot Storm Radar is a WordPress plugin that watches the crowd instead. Every minute it counts how many different addresses visited, how many made only one request, how many loaded a stylesheet or a script the way a real browser does, and how evenly the browser names are spread. It turns that into a storm score, learns what normal traffic looks like on your site, and tells you in plain words whether a bot storm is running right now and why.

> **Current Version: 0.1.7** | **Released: September 22, 2026**

## Why

On 2026-08-19 one operator sent 268,851 requests to a WordPress site in three hours from 256,441 distinct addresses. 250,052 of them made exactly one request. Six browser user-agent strings were rotated evenly across the pool, and 99 percent of the addresses never fetched a stylesheet or a script. Every per-IP tool on that server worked correctly and none of them could see the attack, because no single address did anything wrong.

Bot Storm Radar looks at the crowd instead of the individual.

## What 0.1 does

This is the radar-only release. It observes and reports. **Nothing is blocked, challenged, or rate-limited.** Its purpose is to calibrate the swarm metrics against real traffic before any later release can affect a visitor. On a quiet site or a test install the numbers stay near zero and the state stays calm. That is the radar saying nothing looks like a swarm, not a plugin doing nothing.

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

## Gate

The plugin installs a small must-use plugin, `wp-content/mu-plugins/bot-storm-radar-gate.php`, that loads its gate before any other plugin. The gate reads the state file in the data directory, never loads WordPress and never queries the database, and answers 403 to an address under an active ban. Until address bans can be switched to enforce, it only observes. Deactivating Bot Storm Radar removes the loader. If the gate file is missing the loader does nothing, so the site keeps working.

**Probes.** The gate also refuses, with 403, requests for files only scanners ask for: `.env` and `.git` files, copies of PHP files, configuration and log files, backups and database dumps, other applications' admin pages, and `.php` files that do not exist. It does this for every visitor. Files your web server serves itself never reach it, so on a normal site these requests would only ever have ended as a WordPress "not found" page. The full list, a switch and your own exceptions are on the Settings tab under Probes.

**Address bans.** One address that keeps asking for scanner files, or for many pages that do not exist, trips and is banned for a while; the gate then answers it 403 before the site loads. It starts in observe mode: trips are listed as bans that would have happened, and nobody is refused until you switch to enforce on the Settings tab. Administrators, verified search bots, the never-ban list and the proxies are never banned, and an address that claims to be a search bot is verified first. Swarms of many addresses are only reported, on the Radar tab.

**Early protection.** By default the gate runs from the must-use plugin, after WordPress has connected to the database. On the Settings tab, **Enable early protection** makes it run before WordPress instead, so a refused request costs no database connection and the gate keeps working while the database is down. The plugin writes one marked block pointing PHP's `auto_prepend_file` at the gate loader: in `.user.ini` in the WordPress folder on PHP-FPM, or as `php_value` in `.htaccess` on Apache with mod_php. It then checks with a request to the site that the gate really runs first. PHP-FPM re-reads `.user.ini` only every `user_ini.cache_ttl` seconds (300 by default; the screen shows this server's value), so switching on or off can take that long. If another `auto_prepend_file` is already in use, for example by a firewall plugin, Bot Storm Radar reports it and never replaces or chains it. Deactivating the plugin removes the block.

On a server that honors neither file, the same can be done by hand with one line at the very top of `wp-config.php`, right after `<?php`, using the loader path shown on the Settings tab. The plugin never edits `wp-config.php`:

```php
include_once '/path/to/wp-content/bot-storm-radar-<random>/loader.php';
```

**Switching the gate off without wp-admin.** Create an empty file named `disabled` in the data directory (`wp-content/bot-storm-radar-<random>/`), by SFTP or in the host's file manager. The gate stops on the next request. Delete the file to switch it back on. Uninstalling the plugin leaves that directory with a few-line `loader.php` and the `disabled` marker, which do nothing and can be deleted by hand once no `auto_prepend_file` setting points at them.

## Trying the gate on your own logs

Before switching address bans to enforce, replay a few days of the site's access logs through the gate. Nothing is written and nothing is mailed:

```
wp bot-storm-radar replay --gate /var/log/nginx/access.log.2.gz /var/log/nginx/access.log.1
```

It reports how many requests reached PHP, how many the gate would have refused as probes or as coming from a banned address, and every address that would have tripped, with the paths it asked for. Add `--verify-bots` to check search-bot claims by DNS, and `--root=/path/to/wordpress` to include the missing-PHP rule.

## Data directory

The plugin keeps the files its gate reads in `wp-content/bot-storm-radar-<random>/`. Nothing there is meant to be served. On Apache the directory's own `.htaccess` refuses every request. On nginx, add this inside the site's `server` block (`^~` makes it win over the site's `\.php$` location):

```nginx
location ^~ /wp-content/bot-storm-radar- {
    deny all;
}
```

Every data file in the directory is written with `<?php exit; ?>` as its first line and a `.php` name, so a server that runs PHP returns an empty body for it even without that rule. The one exception is `gate.php`, the gate itself; requested directly it only runs the gate check and returns nothing else.

## Log sources

Traffic that never reaches WordPress, such as a MediaWiki on the same server or requests nginx answers from its page cache or refuses at a gate, can be read from the web server's access log (nginx or Apache `combined` format). Each log source keeps its own minute rows, learned baseline and storm state. Sources are defined and run from WP-CLI only:

```bash
wp bot-storm-radar source add wiki --profile=mediawiki --label="Wiki" \
  --logs=/var/log/nginx/wiki_access.log,/var/log/nginx/wiki-en_access.log \
  --alert-to=ops@example.com   # optional, default: the site's alert recipients
wp bot-storm-radar ingest            # once a minute, from a system timer
wp bot-storm-radar source list
wp bot-storm-radar replay old.log.gz --profile=mediawiki --baseline-ips=60   # what the radar would have said
wp bot-storm-radar source reset-baseline wiki   # forget the learned baseline, learn again
```

The Radar tab gets a switcher with one entry per source, each with its own state, chart, classes, top keys and storm timeline, and a log reader card (files, last ingest, alert recipients, per-source resets). The dashboard widget lists every source, and the Settings tab shows the sources read-only.

Profiles: `mediawiki` (page views, special pages, old revisions, search, login, api.php, with `load.php` as the real-browser signal) and `wordpress`. A line the web server answered with 403, 429 or 444 is a request it refused without doing any work; it counts toward the minute's `refused` figure, shown on the Radar tab and in explanations, and toward nothing else, so a crawler flood the server refuses at a gate does not define what normal traffic is. A baseline learned before this rule includes those addresses and is too high; forget it once with `source reset-baseline`. The first ingest starts at the current end of the files. A log source mails its transitions, naming the source, once its baseline holds one full day of data; until then it only logs them, because a busy log scored against the minimum-addresses floor can look like a storm.

Log sources also carry an absolute 5xx rule. The error-pressure rule is a ratio and a short burst of server errors inside a busy minute never reaches it, so a minute with at least `error_burst_5xx` responses in the 5xx range (default 20) sends one alert per episode, without touching the storm state. The episode ends after `error_burst_clear_minutes` minutes (default 15) below the threshold. Bursts appear in the source's storm timeline.

Behind a page cache PHP sees mostly cache misses, so the site's "slow request" measure counts the cache rather than the site. Set `Slow request (ms)` to 0 there, so that error pressure counts 5xx responses only; the Settings tab and the Radar tab say so when `WP_CACHE` is on and slow requests still count.

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
