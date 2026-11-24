=== WPOptimizing ===
Contributors: artprojectgroup
Tags: performance, cleanup, database, cache, optimization
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GNU General Public License v3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Speeds up WordPress with an object cache and front-end tweaks, and cleans up leftovers knowing which plugin or theme owns each one.

== Description ==

**WPOptimizing** measures where the time goes, speeds the site up (object cache, query deduplication, autoload, CSS and JavaScript, images, delivery) and cleans up what is left over, knowing *who* owns each database record and file before you delete anything. It caches database queries and objects, never whole pages, so it can never serve a stale page or break a form.

Most cleanup plugins delete blindly. WPOptimizing attributes every option, post/user/term meta, custom post type, taxonomy, shortcode, widget, cron job, Action Scheduler action and file to its owning plugin or theme using a multi-level confidence system, so you always know what you are deleting and why.

= Attribution & analysis =

* **Attribution-aware orphan detection** of the database and filesystem, with a confidence level for every item instead of guesswork.
* **Static code scanner** that reads each plugin and theme's source and resolves constants, variables and concatenations, attributing keys even before the data exists.
* **Real-time runtime map**: a lightweight must-use plugin records which extension registers each item as it runs, with effectively zero cost when nothing has changed.
* **Optional shared dataset** (opt-in, reciprocal, off by default): only names, sizes and frequencies are exchanged — never values or content. See External services below.
* **Manual attribution**: tell WPOptimizing who really owns an item when the automatic attribution is wrong or missing; your rule outranks every automatic source.
* **Analysis results** in one screen with a filter by state — To identify, Orphans, Inactive, Empty and In use — every list complete, searchable and paginated.
* **Kill switch**: `define( 'WPOPT_DISABLE', true );` in `wp-config.php` stands down everything that loads before the plugin (the drop-ins and the must-use runtime) without FTP, database changes or deactivation.

= Cleanup =

* **Delete what the analysis attributes** — orphan options, meta, tables, files, cron events and empty records — and **uninstall leftover extensions**, one at a time, always backup-first and restorable from the Backups tab.
* **General cleanup**: post revisions, auto-drafts, trashed posts, spam/pending/trashed comments, pingbacks/trackbacks, expired (or all) transients, oEmbed caches, unused tags, orphaned and duplicate metadata, Action Scheduler logs and table optimization.
* **Quick profiles** (Safe, Blog, WooCommerce, Membership, Agency) apply cleanup, performance and front-end settings in one click — from Settings or the welcome wizard.
* **Dry-run preview**, detailed logs and a printable report.
* **Backup-first**: orphan, autoload, empty-row, cron and media deletions, the general cleanup, uninstalls and the .htaccess and wp-config.php edits are backed up first and restorable from Backups; if the backup cannot be written, nothing is changed. Custom post types and taxonomies are never auto-deleted.

= Performance =

* **Persistent object cache** drop-in (Redis, Memcached, APCu or built-in SQLite) with hit-rate analytics; honours the WP_REDIS_* constants and never serves stale data after an outage.
* **Query deduplication** drop-in that collapses identical reads within a request and attributes duplicate queries to the responsible plugin or theme.
* **Database and Redis diagnosis** that lists only what needs fixing, each with the setting that fixes it.
* **Autoload management**: see the weight of every autoloaded option and who owns it, disable the heavy ones, run Smart Deactivation/Activation, all reversible.
* **Tables**: engine, size and recoverable overhead, OPTIMIZE, and MyISAM → InnoDB conversion.
* **Cron**: flags and removes orphaned scheduled events left by uninstalled plugins.
* **CSS and JavaScript minification** (opt-in), with test mode and exclusions.
* **Front-end performance** (opt-in): Speculative Loading, LCP image priority and lazy-loading with exclusions, resource hints and a Server-Timing diagnostic.
* **Heartbeat control** by area (admin, editor, front end) and cached dashboard comment counts.
* **Host and plugin cache coexistence**: detects WP Rocket, LiteSpeed, SiteGround Speed Optimizer, W3 Total Cache, WP Super Cache, Kinsta, Varnish and many more, and purges them after a cleanup and whenever content changes (WP Engine, Kinsta, SiteGround, Cloudways, Pressable, Nginx Helper, RunCloud, GridPane, Pantheon), plus a one-click "Flush all caches". What an old cache plugin left behind (.htaccess rules, its advanced-cache.php, a second WP_CACHE line) is cleaned up with a backup.
* **Import from another plugin**: brings over the settings of WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, SiteGround Speed Optimizer, Perfmatters, FlyingPress, Breeze, WP-Optimize, Powered Cache, Hummingbird and Autoptimize, with a preview and one-click undo; credentials are never copied and nothing is switched on that was not on there.
* **Cloudflare purge** with your own API token: everything when the caches are flushed, only the affected pages when content changes.
* **Browser cache and compression** through a managed .htaccess block.
* **History**: baseline-vs-now metrics with inline sparklines, and PageSpeed Insights / Chrome UX Report scores on demand.

= Media =

* Orphaned files and thumbnails and potentially unused attachments — deletable, always backup-first — and a count of the exact-content duplicate images.
* **Images**: scale down oversized uploads (WordPress' own handling, which keeps the full-size original), and serve the WebP/AVIF copies an image already has on the front-end with a fallback to the original.

= Tools & scale =

* **Multisite**: each site analyses and cleans its own data. An in-plugin **Documentation** tab and a toolbar menu (analyze / clean / flush).
* **Contextual help** on every screen, in the native Help tab of WordPress: one panel per tab and settings section, each linking to its section of the documentation, in Spanish or English.
* **Built for huge databases**: the analysis runs asynchronously in stages, switches the heaviest counts to fast approximate queries on very large installs and caps per-query time.

= 7-level confidence system =

* **Manual** — You said so yourself. Outranks every automatic source.
* **Sure** — Captured live on your site, by a plugin whose own namespace the item belongs to.
* **Likely** — Snapshot comparison between active/inactive states.
* **Possible** — Read from the extension's own code, or confirmed by several sites in the shared dataset.
* **Doubtful** — One claim only, outside the claimant's namespace. Review before deleting.
* **Estimated** — Prefix pattern match only. Review before deleting.
* **Undetermined** — No attribution possible. Never delete without manual review.

WPOptimizing Pro, sold separately at [wpoptimizing.com](https://wpoptimizing.com), adds a page cache, Smart Assets, delayed JavaScript, critical CSS, image optimization, the media library audit, scheduled cleanup, deletion in bulk and tools for agencies; this plugin works fully on its own.

= Requirements =

* WordPress 6.6 or higher
* PHP 7.4 or higher
* MySQL 5.7 / MariaDB 10.3 or higher
* Write access to wp-content/wpoptimizing/ and wp-content/mu-plugins/

= Languages =

English, plus complete Spanish (es_ES), German (de_DE), French (fr_FR), Italian (it_IT), Portuguese - Portugal (pt_PT), Russian (ru_RU), Arabic (ar) and Chinese Simplified (zh_CN) translations.

= Credits =

* [Plus Jakarta Sans](https://github.com/tokotype/PlusJakartaSans) by Tokotype — SIL Open Font License 1.1 (assets/fonts/PlusJakartaSans-OFL.txt).
* [Genericons](https://github.com/Automattic/Genericons) by Automattic — GPL-2.0-or-later.
* [matthiasmullie/minify](https://github.com/matthiasmullie/minify) and [matthiasmullie/path-converter](https://github.com/matthiasmullie/path-converter) by Matthias Mullie — MIT.
* [SelectWoo](https://github.com/woocommerce/selectWoo) by WooCommerce, based on Select2 — MIT.

= Documentation =

* Documentation in English: [artprojectgroup.com/documentation/wpoptimizing](https://artprojectgroup.com/documentation/wpoptimizing)
* Documentation in Spanish: [artprojectgroup.es/documentacion/wpoptimizing](https://artprojectgroup.es/documentacion/wpoptimizing)

== External services ==

WPOptimizing works without any of these. Each one is contacted only when the feature that needs it is used, always over HTTPS.

1. **WPOptimizing attribution gateway** (https://wpoptimizing.com) — only after you opt in to data sharing ("Help improve attribution accuracy" in the welcome wizard or in Settings › Shared dataset). It is off by default and, while it is off, the gateway is never contacted.
 - What is sent: your site's domain (to register the install and obtain a per-install token), and the names of options, meta keys, database tables and files, their sizes and how often they are seen, the slug of the plugin or theme that owns each one, and the handles and relative paths of the CSS/JS assets your pages load. Never option values, post or user content, or the contents of any record or file.
 - When: on a daily schedule and after an analysis (upload of that metadata and download of the shared attribution dataset), when you add a manual attribution rule (the rule you created is sent), when you use the attribution assistant (the names of the items you ask about are sent), and once more when the plugin is deactivated (a final upload of the same metadata).
 - Terms: https://wpoptimizing.com/legal-notice — Privacy: https://wpoptimizing.com/privacy-policy

2. **Google PageSpeed Insights API** (https://www.googleapis.com/pagespeedonline/) — only when you click to run a PageSpeed test.
 - What is sent: the public URL of the page being tested and, if you entered one, your Google API key.
 - Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy

3. **Chrome UX Report API** (https://chromeuxreport.googleapis.com) — only when you request the field-data history and have entered a Google API key.
 - What is sent: your site's public origin (home URL) and your Google API key.
 - Terms: https://policies.google.com/terms — Privacy: https://policies.google.com/privacy

4. **Cloudflare API** (https://api.cloudflare.com) — only when you enter a Cloudflare zone ID and your own API token in Cache › Cloudflare.
 - What is sent: your zone ID, the API token (as an authorization header) and the URLs to purge, and a request to purge everything when caches are flushed.
 - Terms: https://www.cloudflare.com/website-terms/ — Privacy: https://www.cloudflare.com/privacypolicy/

5. **WordPress.org Plugin and Theme Directory API** (https://api.wordpress.org) — the same public API WordPress uses to show and update plugins and themes.
 - What is sent: plugin and theme slugs. During an analysis, the slugs of extensions that are no longer installed but left data behind, to show their name instead of a bare slug; when you assign an owner by hand in the attribution picker, the text you type, to find matching plugins. Nothing about your site's content.
 - Terms: https://wordpress.org/about/ — Privacy: https://wordpress.org/about/privacy/

== Installation ==

1. Upload the wpoptimizing folder to /wp-content/plugins/, or install it from Plugins › Add New.
2. Activate the plugin through the Plugins screen.
3. Open WPOptimizing in the admin menu; the welcome wizard helps you pick a profile and the options you want.
4. Click Analyze now to run your first scan.
5. Review the results, starting with the Orphans list.

== Frequently Asked Questions ==

= Is it safe to delete low-confidence (Estimated / Undetermined) items? =

Not automatically. Estimated means the attribution is based on prefix matching, and Undetermined means no attribution was found. Only Manual, Sure, Likely and Possible items are offered for automatic cleanup; review the rest before deleting. Attributed deletions and the general cleanup create a backup first, restorable from the Backups tab.

= Does it cache my pages? Will it conflict with my host or cache plugin? =

No. WPOptimizing caches database query results and PHP objects, not full HTML pages, so it cannot serve stale pages or break forms. It detects your host or page-cache plugin and purges it after a cleanup instead of competing with it.

= Will it slow down a very large site? =

The analysis runs in the background in stages, so it never blocks a visitor's request or times out. On very large databases it uses fast approximate queries, caps the time per query and samples its instrumentation, so the live site is protected.

= Is it compatible with WooCommerce and HPOS? =

Yes. WPOptimizing declares compatibility with High-Performance Order Storage and the cart/checkout blocks, never reads or writes order data directly, and only ever deletes post types and taxonomies that are no longer registered.

= Does it support WordPress Multisite? =

Yes. Each subsite scans, reviews and cleans its own data in isolation.

= Where are my backups stored? =

Locally, in wp-content/wpoptimizing/backups/.

= Does it send my data anywhere? =

Not unless you opt in to the shared dataset, and even then only names, sizes and frequencies, never values or content. See External services for every service the plugin can contact and when.

== Screenshots ==

1. Home: health score, what needs attention and the analysis results by state
2. Analysis results filtered by state, with each record attributed to its plugin or theme
3. Media: orphaned files, unused attachments and the image analysis
4. Speed › Page & browser: minification and loading & delivery options

== Changelog ==

= 0.1.0 =
* Initial release.

== Upgrade Notice ==

= 0.1.0 =
Initial release.
