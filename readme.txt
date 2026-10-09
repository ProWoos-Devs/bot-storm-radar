=== Bot Storm Radar ===
Contributors: rafaelminuesa
Tags: security, bots, firewall, monitoring, bot protection
Requires at least: 6.0
Tested up to: 7.1
Stable tag: 0.3.1
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sees the bot swarms that per-address tools miss, says in plain words when a storm is running, and refuses scanner probes before a page is built.

== Description ==

Bot attacks no longer come from one address. They come as swarms of thousands of addresses that each make one or two requests, so a tool that judges visitors one by one sees nothing wrong. Bot Storm Radar watches the crowd instead.

Every minute it counts how many different addresses visited, how many made only one request, how many loaded a stylesheet or a script the way a real browser does, and how evenly the browser names are spread. It turns that into a storm score, learns what normal traffic looks like on your site, and tells you in plain words whether a bot storm is running right now and why.

It also protects. It refuses requests for files only scanners ask for before WordPress builds a page, and a single address that keeps probing or asking for missing pages can be banned for a while. Address bans start in observe mode, where they are listed and nobody is refused, until you switch them on.

**The radar**

* **Swarm metrics every minute.** Single-hit ratio, asset ratio, user-agent evenness, error pressure and endpoint concentration, combined into one storm score and gated by traffic volume.
* **Learned baseline.** Over the first seven days the plugin learns the site's normal number of distinct addresses per minute and its normal asset ratio, and shows them beside each threshold.
* **Storm states.** Calm, warning, storm and cooling. Every transition is logged with the numbers that caused it and an explanation in words.
* **Alerts** by plain-text email on warning, storm and all-clear.
* **Good-bot verification.** Googlebot, Bingbot, Applebot and Yandex are verified by reverse DNS plus forward confirmation, DuckDuckBot against its published address list. A user agent alone proves nothing.
* **The real visitor address behind a proxy.** A forwarding header is believed only when the request arrived through Cloudflare, a local proxy or a proxy you declare. A spoofed `X-Forwarded-For` from an ordinary client is ignored.
* **Radar screen** with the current state, the last 24 hours per minute, top classes, top keys, bot claims and the storm timeline, plus a dashboard widget.

The radar observes and reports. It never blocks a swarm. On a quiet site the numbers stay near zero and the state stays calm.

**The protection**

* **Refused early.** The refusal runs as soon as WordPress has loaded its plugins, before the theme, the query or any page is built, and reads its rules from one stored option without an extra database query.
* **Probe refusal**, on by default. A 403 for `.env` and other dot-files, copies of PHP files, configuration and log files, backups and dumps, other applications' admin paths and `.php` files that do not exist. Files your web server serves itself never reach it.
* **Address bans**, observe mode first. An address that sends 3 probes in 10 minutes or asks for 20 missing pages in a minute is banned for an hour, and for a day on a repeat. Administrators, verified search bots, Cloudflare and declared proxies and a never-ban list are never banned.
* **Bans tab** with every ban, its evidence and an Unban button, the bans that would have happened in observe mode, and search-bot claims waiting for verification.
* **A dry run over your own logs.** `wp bot-storm-radar replay --gate` shows what would have been refused in your access logs before you switch bans on.

**Also**

* Reads web-server access logs (nginx or Apache combined format) as separate sources from WP-CLI, for traffic that never reaches WordPress.
* WooCommerce request classes (cart, checkout, wc-ajax) when WooCommerce is active.
* Counters live in the persistent object cache or in APCu, with a transient fallback. One of the first two is strongly recommended.

== External services ==

The plugin downloads two public address lists once a day from WordPress cron. It sends nothing about your visitors. Like any HTTP request, each download shows the service your server's IP address and a user agent.

* **Cloudflare address ranges**, from `https://www.cloudflare.com/ips-v4` and `https://www.cloudflare.com/ips-v6`. The plugin needs them to tell a request that really came through Cloudflare from one that only claims to. The request carries the WordPress default user agent, which names your site's address. A bundled copy is used until the first download and whenever a download fails. The service is provided by Cloudflare, see its [terms](https://www.cloudflare.com/website-terms/) and [privacy policy](https://www.cloudflare.com/privacypolicy/).
* **DuckDuckBot address list**, from `https://duckduckgo.com/duckduckbot.json`. The plugin needs it to verify visitors that claim to be DuckDuckBot. The request carries the user agent `BotStormRadar/` followed by the plugin version. A bundled copy is used until the first download and whenever a download fails. The service is provided by DuckDuckGo, see its [terms](https://duckduckgo.com/terms) and [privacy policy](https://duckduckgo.com/privacy).

To verify a visitor that claims to be Googlebot, Bingbot, Applebot or Yandex, the plugin asks your server's own DNS resolver for the host name of that address.

== Installation ==

1. Upload the `bot-storm-radar` folder to `/wp-content/plugins/`, or install the plugin from the Plugins screen.
2. Activate it.
3. Open **Bot Storm Radar** in wp-admin. Leave it running for a week so the baseline can learn, then review the thresholds on the Settings tab.
4. Before you switch address bans to enforce, look at the Bans tab after a few days, or replay your access logs with `wp bot-storm-radar replay --gate`.

== Frequently Asked Questions ==

= Does it block a bot swarm? =

Not yet. The radar detects a swarm and tells you. What the plugin refuses today are scanner probes, for every visitor, and single addresses that tripped a ban once you switched bans to enforce.

= What is observe mode? =

Address bans start in observe mode. An address that would have been banned is listed on the Bans tab with the requests that caused it, and nobody is refused. When the list looks right, switch to enforce on the Settings tab.

= Can it lock me or a search engine out? =

Administrators, verified search bots, Cloudflare and declared proxies, and the addresses on your never-ban list are never banned. An address that claims to be a search bot is verified by DNS before anything happens to it.

= How do I switch the refusals off without wp-admin? =

Rename or delete the `bot-storm-radar` folder in `wp-content/plugins/` by SFTP or in your host's file manager. WordPress then stops loading the plugin, and nothing is refused.

= Does it work behind Cloudflare or another proxy? =

Yes. Cloudflare is recognized by its published address ranges. Another proxy has to be declared on the Settings tab, and the plugin tells you when it sees one that is not declared.

= Does it need an object cache? =

No, but a persistent object cache (Redis, Memcached) or APCu is strongly recommended. Without one the counters fall back to transients in the database, and the Radar screen warns about it.

= Does it write files? =

No. Settings, bans, minute history and the refusal rules are stored in the database.

= Does it track visitors? =

No. It sets no cookies and sends nothing about visitors anywhere. To tell real browsers from bots, every front-end page asks your own site for a 1-pixel image and runs one line of JavaScript that requests it once more; both requests only add to the counts of the current minute.

Visitor addresses are kept only as long as the detection needs them: per-minute counters expire after 25 minutes, and the minute history of the last 25 hours keeps totals plus the busiest addresses of each minute (at most 20, each with 10 or more requests in that minute). An address that tripped a ban is stored with the requests that caused it (paths and user agent) until 30 days after its ban ended, and the list of would-be bans keeps the latest 200 addresses. Administrator addresses are remembered for a day so they are never banned, and search-bot checks for a day.

== Screenshots ==

1. The Radar tab during a storm, with the current state, the score of the last minute, the learned baseline and the last 24 hours.
2. The storm timeline. Every transition is listed with the numbers that caused it, in words.
3. Request classes, the busiest networks and user agents, and search-bot claims with their verification.
4. The Bans tab, with each ban's evidence and an Unban button, the bans that would have happened in observe mode, and claims waiting for a search-bot check.
5. The settings for address bans and probe refusal.
6. The dashboard widget.

== Changelog ==

The full history is in [CHANGELOG.md](https://github.com/ProWoos-Devs/bot-storm-radar/blob/main/CHANGELOG.md).
