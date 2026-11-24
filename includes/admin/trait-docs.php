<?php
/**
 * "Documentation" tab: concise in-plugin help for every feature section.
 *
 * Each entry has an HTML anchor so other tabs can deep-link to it via
 * doc_link( $anchor ). Sections are grouped by logical area and navigated
 * via a ?dsec= sub-navigation (same pattern as cache uses ?csec=).
 * Composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Docs_Trait {

	/**
	 * Render the Documentation tab.
	 *
	 * @return void
	 */
	protected function render_docs_tab() {
		$this->render_section_header(
			__( 'Documentation', 'wpoptimizing' ),
			__( 'Quick reference for every feature. Other tabs link directly to the relevant section.', 'wpoptimizing' ),
			array( 'icon' => 'editor-help' )
		);

		// Groups: key => [ label, section-keys[] ].
		$groups = array(
			'performance' => array(
				'label'    => __( 'Performance', 'wpoptimizing' ),
				'sections' => array( 'wpopt-doc-cache', 'wpopt-doc-performance', 'wpopt-doc-pagespeed', 'wpopt-doc-frontend', 'wpopt-doc-tweaks', 'wpopt-doc-image', 'wpopt-doc-assets', 'wpopt-doc-autoload', 'wpopt-doc-tables', 'wpopt-doc-cron' ),
			),
			'cleanup'     => array(
				'label'    => __( 'Cleanup', 'wpoptimizing' ),
				'sections' => array( 'wpopt-doc-general', 'wpopt-doc-media', 'wpopt-doc-pending', 'wpopt-doc-empty', 'wpopt-doc-manual-attrib', 'wpopt-doc-attr-assist', 'wpopt-doc-orphans', 'wpopt-doc-inactive', 'wpopt-doc-active' ),
			),
			'tools'       => array(
				'label'    => __( 'Tools & data', 'wpoptimizing' ),
				'sections' => array( 'wpopt-doc-history', 'wpopt-doc-activity', 'wpopt-doc-backups', 'wpopt-doc-settings', 'wpopt-doc-webhooks', 'wpopt-doc-dataset' ),
			),
		);

		$dsec = isset( $_GET['dsec'] ) ? sanitize_key( wp_unslash( $_GET['dsec'] ) ) : 'performance'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $dsec, $groups ) ) {
			$dsec = 'performance';
		}

		// Sub-navigation menu.
		echo '<ul class="subsubsub wpopt-subsubsub">';
		$keys = array_keys( $groups );
		foreach ( $keys as $i => $gkey ) {
			$this->docs_menu_link( $gkey, $groups[ $gkey ]['label'], $dsec );
			if ( $i < count( $keys ) - 1 ) {
				echo ' | ';
			}
		}
		echo '</ul>';
		echo '<br class="clear" />';

		// Render sections for the active group, with a sticky index of them beside the text:
		// each group runs to a dozen long sections, and finding one meant scrolling through the
		// others. The index highlights the section being read (scroll spy below).
		$sections = $this->get_doc_sections();
		$present  = array_values(
			array_filter(
				$groups[ $dsec ]['sections'],
				static function ( $a ) use ( $sections ) {
					return isset( $sections[ $a ] );
				}
			)
		);
		echo '<div class="wpopt-docs-layout">';
		echo '<nav class="wpopt-docs-toc" aria-label="' . esc_attr__( 'In this section', 'wpoptimizing' ) . '"><ul>';
		foreach ( $present as $anchor ) {
			echo '<li><a href="#' . esc_attr( $anchor ) . '">' . esc_html( $sections[ $anchor ]['title'] ) . '</a></li>';
		}
		echo '</ul></nav>';
		echo '<div class="wpopt-docs-main">';
		foreach ( $present as $anchor ) {
			$s = $sections[ $anchor ];
			echo '<div id="' . esc_attr( $anchor ) . '" class="wpopt-doc-section">';
			$this->render_subsection_header( $s['title'], '', isset( $s['icon'] ) ? (string) $s['icon'] : '' );
			echo '<div class="wpopt-doc-body">';
			echo wp_kses_post( $s['body'] );
			echo '</div>';
			echo '</div>';
		}
		echo '</div></div>';
		?>
		<?php ob_start(); ?>
		(function(){
			var toc = document.querySelector('.wpopt-docs-toc');
			if (!toc || !('IntersectionObserver' in window)) { return; }
			var links = {};
			Array.prototype.forEach.call(toc.querySelectorAll('a'), function(a){ links[a.getAttribute('href').slice(1)] = a; });
			function mark(id){
				Object.keys(links).forEach(function(k){
					var on = k === id;
					links[k].classList.toggle('current', on);
					if (on) { links[k].setAttribute('aria-current', 'true'); } else { links[k].removeAttribute('aria-current'); }
				});
			}
			var io = new IntersectionObserver(function(entries){
				entries.forEach(function(e){ if (e.isIntersecting) { mark(e.target.id); } });
			}, { rootMargin: '-20% 0px -70% 0px' });
			Array.prototype.forEach.call(document.querySelectorAll('.wpopt-docs-main .wpopt-doc-section'), function(sec){ io.observe(sec); });
			if (location.hash && links[location.hash.slice(1)]) { mark(location.hash.slice(1)); }
		}());
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * Sub-nav menu link for the docs tab (?dsec=).
	 *
	 * @param string $key   Group key.
	 * @param string $label Label to display.
	 * @param string $curr  Current active group key.
	 * @return void
	 */
	protected function docs_menu_link( $key, $label, $curr ) {
		$url = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'docs',
				'dsec' => $key,
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<li><a href="%s" class="%s">%s</a></li>',
			esc_url( $url ),
			$curr === $key ? 'current' : '',
			esc_html( $label )
		);
	}

	/**
	 * Return the ordered list of documentation sections.
	 * Keys are the anchor IDs (without leading #).
	 * Each value: [ 'title', 'icon', 'summary' (1-3 sentences, shown in modal),
	 *               'body' (multi-paragraph kses HTML, shown in docs tab) ].
	 *
	 * @return array<string,array{title:string,icon:string,summary:string,body:string}>
	 */
	protected function get_doc_sections() {
		// Built once per request: doc_link() asks for this array for every help icon on the
		// screen (20+ on some tabs) and the array is a few hundred translated strings.
		static $sections = null;
		if ( null !== $sections ) {
			return $sections;
		}

		// Textos que Lite cuenta de otra manera: sin licencia y sin las funciones que sólo trae
		// Pro. Lite lleva su versión; Pro la sustituye dentro del bloque.
		$txt = array(
			'img_summary'       => __( 'Analyzes your uploads and optimizes new ones as they arrive: it resizes oversized uploads and recompresses them, and serves the next-gen copies they already have on the front-end with a fallback to the original.', 'wpoptimizing' ),
			'img_heaviest'      => __( 'The "Heaviest images" view of Image optimization turns the aggregate numbers into a concrete, per-image to-do list: a table with a thumbnail, dimensions, weight, a plain-language tag of what would help each image (Oversized, No WebP, No AVIF — only ever suggesting formats your server can actually create) and an estimated saving, with search, a format filter and pagination. On very large libraries the scan is time-bounded, so the list shows the worst offenders it reached.', 'wpoptimizing' ),
			'img_compat'        => __( 'Compatibility with other optimizers: WPOptimizing recognizes WebP/AVIF copies created by plugins such as Imagify, LiteSpeed Cache, EWWW or ShortPixel (both the photo.jpg.webp and photo.webp naming conventions), so it never regenerates a copy that already exists and can serve theirs too. If one of those plugins is active and already serving next-gen images, WPOptimizing automatically steps back from serving to avoid a double <picture>.', 'wpoptimizing' ),
			'assets_summary'    => __( 'Shows the CSS and JavaScript actually loaded on your public pages, sampled in the background, and lets you stop specific assets from loading — on the whole site or only where you choose.', 'wpoptimizing' ),
			'assets_test_mode'  => __( 'Test mode (free): before applying your rules to everyone, turn on test mode to try them safely. While it is on, every unload rule takes effect only for logged-in administrators, so your visitors keep every asset. Browse your key pages while logged in to confirm they still look and work correctly, then turn test mode off to apply the rules to all visitors. This lets you validate an aggressive setup with zero risk to the public site.', 'wpoptimizing' ),
			'assets_exceptions' => __( 'URL exceptions (free): list the pages that must always load every asset — the checkout, a booking form, a landing page — and no unloading of any kind (site-wide or per page) will run there. Enter one URL pattern per line, matched against the path, and use * as a wildcard: /checkout protects any URL containing that path, */thank-you/* protects every order-received page. The "Quick add" buttons fill in this site\'s own pages — the WooCommerce cart, checkout and account, the Easy Digital Downloads pages, the course and lesson addresses, the pages with a contact or booking form — and only appear for what is installed here. This is the surgical companion to broad unloading: strip assets site-wide, but never on the pages that need them.', 'wpoptimizing' ),
			'settings_summary'  => __( 'Quick Profiles apply a full recommended configuration (cleanup tasks plus object cache, query deduplication and heartbeat) with a single click. Individual cleanup options and backup retention are configured here.', 'wpoptimizing' ),
			'settings_notify'   => __( 'Notification email and maintenance report: the notification address is where WPOptimizing sends the periodic report; for agencies, point it at the maintainer instead of the site owner. Network alerts go to the network administrator instead. The maintenance report is a recurring email summary you can set to daily, weekly, fortnightly or monthly (off by default): it includes the Health Score and its trend, key database and media metrics, any unusual table growth, and the scans and cleanups done since the last report. Use "Send a test now" to check delivery without changing the schedule.', 'wpoptimizing' ),
			'settings_export'   => __( 'Import / export: download the whole configuration as a JSON file and restore it here or on another site — handy to replicate your setup across a fleet. The export covers the settings for cleanup, cache, media and images, backups and attribution. For safety it never includes keys, passwords, the data-sharing consent, branding or measured runtime state (scan results, baselines, cached sizes), so importing onto another site only carries configuration. On import, existing settings are overwritten and any unrecognized keys are ignored.', 'wpoptimizing' ),
			'cache_minify' => __( 'CSS and JavaScript minification (opt-in, off by default): under Speed › Page & browser › Minification WPOptimizing can serve minified copies of your own local CSS and JS, stripping comments and whitespace so they download faster. It is deliberately conservative: files that are already minified, hosted on another domain, or listed in your exclusions are left untouched; the minified copies live in wp-content/uploads/wpopt-assets/ and are rebuilt automatically when the source changes or a cache is flushed. A test mode lets administrators preview the result before exposing it to visitors, and it refuses to run alongside another asset optimizer (Autoptimize, WP Rocket, Perfmatters, LiteSpeed) to avoid double-processing.', 'wpoptimizing' ),
			'fe_summary' => __( 'Optional speed features for the public site, configured in Speed › Page & browser: speculative loading (prefetch/prerender the next page), content image loading optimization, resource hints and a Server-Timing diagnostic header. All opt-in and reversible; none cache full HTML pages.', 'wpoptimizing' ),
			'img_settings' => __( 'Settings: Max dimensions set separate width and height limits (0 on an axis disables it). Scale down oversized uploads applies them to new uploads through WordPress\' native handling, which keeps the full-size original.', 'wpoptimizing' ),
			'gen_summary' => __( 'Removes database clutter: post revisions, auto-drafts, trashed posts, spam comments, and expired transients. Runs from a single button. A detailed log is saved after every run.', 'wpoptimizing' ),
			'gen_backup' => __( 'Every batch the cleanup deletes is backed up first, into one Backups entry per run. If that backup cannot be written, the run stops with the status “Stopped: backup failed” and deletes nothing more. Table optimization changes no data and needs no backup.', 'wpoptimizing' ),
			'media_summary' => __( 'Scans your uploads for files with no database attachment record, orphaned thumbnail sizes and potentially unused attachments, and counts the exact-content duplicates.', 'wpoptimizing' ),
			'backups_summary' => __( 'Every change WPOptimizing makes to your data is backed up first: deletions (orphans, empty rows, media and the general cleanup) and changes (autoload flags, scheduled tasks, .htaccess and wp-config.php edits). Backups are grouped by operation so you can find and restore exactly what a specific action changed. Manual backups can also be created at any time.', 'wpoptimizing' ),
			'dataset_optout'    => __( 'You can opt out at any time from the Settings tab. Your cleanup features keep working, but sharing is strictly reciprocal: if your site does not contribute, it does not receive the shared dataset either — the community knowledge base only flows to sites that take part. With sharing off, attribution falls back to what your own site captures locally.', 'wpoptimizing' ),
			'media_backup' => __( 'Every media deletion is backed up first, so it is reversible: WPOptimizing copies each file before removing anything. If you ever need to undo a deletion, the Media tab shows how many file backups are available and links straight to the Backups tab, where you restore a whole operation in one click. Nothing is deleted that was not fully backed up.', 'wpoptimizing' ),
			'media_imgopt' => __( 'Image optimization (front-end WebP/AVIF serving, scaling down oversized uploads and the analysis of the library) has its own sub-section, documented separately under Image optimization.', 'wpoptimizing' ),
			'media_entries' => __( 'Media has six entries: Orphans (with a filter for orphan files, orphan thumbnails and other files), Duplicate sets, Unused attachments, Image optimization (Summary and Heaviest images), Trash and Library audit. Merging duplicates and the library audit are not included in this plugin: their entries only say so.', 'wpoptimizing' ),
		);

		$sections = array(

			// ── Performance ──────────────────────────────────────────────────

			'wpopt-doc-performance'   => array(
				'title'   => __( 'Performance assistant', 'wpoptimizing' ),
				'icon'    => 'performance',
				'summary' => __( 'A guided view of your server response time (TTFB) with one-click fixes. It rates the server share of TTFB, scores it into the Optimization health, and walks you through the levers that lower it — applying the ones WPOptimizing can do itself and explaining the ones it cannot.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'The Performance tab measures the server share of TTFB — the time WordPress takes to start sending a page, excluding the visitor network. It samples real front-end requests, averages them, and shows a level (good / improvable / high) that also feeds the Optimization health score.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Below the diagnosis is a checklist of levers. For the ones WPOptimizing can act on — the persistent object cache, query deduplication, autoload weight (Smart Deactivation) and the Heartbeat — an Apply button performs the change in one click, reusing the same safe, reversible actions as their own tabs. A result notice confirms each change.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'For the levers WPOptimizing cannot change it shows guidance instead of a button: moving WP-Cron to a real system cron (needs wp-config.php and server access), and the server or host itself (PHP version, OPcache, hosting resources, a light theme). This is the honest case: when the database, cache and autoload are all green and TTFB is still high, the remaining time is the server, and the assistant says so rather than suggesting cleanups that would not help.', 'wpoptimizing' ) . '</p>'
					. '',
			),

			'wpopt-doc-pagespeed'     => array(
				'title'   => 'PageSpeed Insights',
				'icon'    => 'chart-line',
				'summary' => __( 'Fetches your Google PageSpeed score and Core Web Vitals for the home page, on demand. It sends only the public URL of your site to Google’s public API and changes nothing here. The optional free API key simply raises the request quota.', 'wpoptimizing' ),
				'modal'   => self::psi_key_steps_html(),
				'body'    => '<p>' . esc_html__( 'PageSpeed Insights is Google’s own measurement of how fast your site loads. WPOptimizing asks for it on demand — one outbound HTTPS request carrying only the public URL of your home page — and shows the performance score plus the Core Web Vitals (LCP, INP, CLS, FCP, TBT and Speed Index). Nothing is installed, changed or stored on your site beyond the last result.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Lab metrics come from a simulated load and are always available. Field data (what real visitors experienced over the last 28 days) is only included when Google has enough traffic for your site, which is why it can be missing on smaller sites. Test both mobile and desktop: mobile is the stricter of the two and the one Google ranks on.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The API key is optional. Without one Google applies a shared, anonymous quota that is enough for occasional tests; if you test often you will eventually see a quota error. A free key raises that limit, costs nothing and needs no payment method.', 'wpoptimizing' ) . '</p>'
					. self::psi_key_steps_html(),
			),

			'wpopt-doc-cache'         => array(
				'title'   => __( 'Cache', 'wpoptimizing' ),
				'icon'    => 'cloud',
				'summary' => __( 'Installs a persistent object cache drop-in that stores expensive database queries in memory (Redis, Memcached, APCu or built-in SQLite). Query deduplication collapses repeated identical queries within a single request. Heartbeat control reduces admin-side server load.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'WPOptimizing installs a drop-in file (wp-content/object-cache.php) that intercepts WordPress object cache calls and routes them to the fastest available backend. Redis and Memcached are used when the server offers them; APCu works well on single-server hosts; the built-in SQLite store is the automatic fallback that requires zero configuration.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Query deduplication (db.php drop-in) intercepts wpdb calls and returns a cached result when the same query string is executed more than once in the same HTTP request. This is particularly effective on complex pages that call get_post(), get_terms() or get_user() for the same IDs from many different code paths.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Login-safe by design: WordPress stores user session tokens inside the user_meta cache group, so under heavy concurrent traffic a persistent cache can occasionally overwrite a freshly issued login token and log users out. WPOptimizing keeps the user_meta group out of the persistent store by default and reads it fresh on every request, so sessions stay reliable. Sites on a low-contention backend can opt back in from the cache config file.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Heartbeat control lets you set the WordPress admin heartbeat to Default, Slow (every 60 s) or Off outside the editor, where autosave and edit locks keep working. Slowing it or turning it off noticeably lowers PHP and database load during active admin sessions.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Server TTFB and what to do about it: the Cache analytics show the server share of TTFB (the time WordPress takes to start sending the page, not the visitor network). WPOptimizing rates it (good / improvable / high), folds it into the Optimization health score, and gives prioritized actions. The advice is context-aware: if the object cache is off it suggests enabling it; if autoload is heavy it points you there; and — crucially — when the database, cache and autoload are already optimal it tells you honestly that the bottleneck is the server itself (PHP version, OPcache, hosting resources or a heavy theme/plugin), which the plugin cannot fix.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Browser cache and compression (Apache): from the Cache tab WPOptimizing can add a managed block to your .htaccess that sets far-future Expires/Cache-Control headers for static files (images, CSS, JS, fonts) and enables on-the-fly compression (Brotli when the server offers it, otherwise gzip). Returning visitors then reuse files from their own browser instead of re-downloading them, and every text response travels compressed. The block is written between clearly marked WPOptimizing markers, a timestamped backup of .htaccess is kept, and disabling it removes exactly that block and nothing else. It is skipped when the server is not Apache or when another plugin already manages these headers.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['cache_minify'] ) . '</p>'
					. '<p>' . esc_html__( 'Cloudflare: if the site is behind Cloudflare, WPOptimizing also purges its cache — everything when it flushes the other caches, and only the affected pages when content changes. It needs the zone ID and an API token with the Zone › Cache Purge › Purge permission; “How to get them” in the Cache tab shows the steps. The Global API Key is refused, because it opens the whole account. A token for “All zones” can be reused on every site of the account, and it can only purge.', 'wpoptimizing' ) . '</p>'
					. '',
			),

			'wpopt-doc-autoload'      => array(
				'title'   => __( 'Autoload', 'wpoptimizing' ),
				'icon'    => 'database-view',
				'summary' => __( 'Autoloaded options are loaded from the database on every single page request. This tab lists them sorted by size so you can disable autoload for options that are not needed on every request, reducing the upfront database cost on every page.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'WordPress loads all autoloaded options in a single SELECT at the start of every request. If plugins have added large blobs or configuration arrays with autoload=yes, this query can transfer hundreds of kilobytes before a single line of your template runs. The Autoload tab lists every autoloaded option with its size, so you can identify the heaviest ones at a glance.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Smart Deactivation analyses every autoloaded option against the attribution manifest and automatically disables autoload for options that are confidently attributed to plugins that are no longer installed. This is completely safe: an uninstalled plugin cannot need its options on every request.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'De-autoload never-read goes further and is the most surgical option. WPOptimizing measures which of the largest autoloaded options are actually read on each request (a sampled, near-zero-overhead measurement) and, combined with the same signal aggregated across the network, turns off autoload only for options that load on every request yet are (almost) never read. WordPress core options are always protected, nothing is deleted, and every change is reversible.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Disable all non-essential is the broadest reversible action: it de-autoloads every option that is neither core nor owned by an installed extension (options the network reads on most requests are kept). Delete confirmed orphans removes, backup-first, the dead options of uninstalled extensions.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The Candidates sub-tab shows non-autoloaded options that are read on almost every request (measured by the read-frequency tracker). Enabling autoload for those options turns the per-read SELECT into a zero-cost cache hit.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Tip: a healthy site keeps total autoload weight below about 800 KB. Sites above 1 MB will see a measurable latency improvement after a cleanup.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-tables'        => array(
				'title'   => __( 'Tables', 'wpoptimizing' ),
				'icon'    => 'editor-table',
				'summary' => __( 'Lists every database table with its engine, row count, data size and recoverable overhead. Optimizing a table reclaims fragmented space. Tables still using the legacy MyISAM engine are highlighted for conversion to InnoDB.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Database tables accumulate fragmented space over time as rows are deleted or updated. The "overhead" column shows how much space can be reclaimed without touching any data: MySQL simply compacts the existing rows. For busy e-commerce or membership sites this can save tens or hundreds of megabytes.', 'wpoptimizing' ) . '</p>'
						. '<p>' . esc_html__( 'When there is reclaimable overhead, a "Reclaim overhead now" button appears above the list. It runs OPTIMIZE on exactly the tables that carry recoverable overhead (file-based engines such as MyISAM and Aria) in one click, so you do not have to find and select them by hand. It never touches InnoDB internal free space, which the engine reuses on its own, and it never deletes data.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'MyISAM is the older MySQL table format. It lacks transaction support, uses table-level locking (so a single slow query blocks all writes), and cannot recover safely after a crash. InnoDB offers row-level locking, crash-safe recovery via a redo log, and significantly better performance under concurrent write workloads.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The conversion from MyISAM to InnoDB is an online ALTER TABLE that rebuilds the table in place. It is non-destructive: all data is preserved, all indexes are rebuilt, and WordPress continues to function normally throughout. For safety, take a database backup before converting large tables.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Orphaned custom tables from plugins that have been uninstalled appear in the Orphans tab rather than here, so the Tables tab only lists tables that belong to something still installed.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'After each scan WPOptimizing records the size and row count of every table and compares them with the previous scan. When a table grows abnormally between scans — both by a meaningful amount and by a large percentage — it is flagged at the top of this tab (and in the performance report). This is how you catch a runaway log table, a transient/cache table that never clears, or a stuck background job before it bloats the database. A brand-new table is reported as new, never as a warning.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-cron'          => array(
				'title'   => __( 'Cron jobs', 'wpoptimizing' ),
				'icon'    => 'clock',
				'summary' => __( 'Shows all WordPress scheduled events with their hook, next run time and recurrence. Orphaned events — hooks whose callback function no longer exists, usually left by uninstalled plugins — are flagged so you can remove them safely.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'WordPress Cron (wp-cron) fires scheduled tasks on the next page load after the scheduled time. Each event is identified by its hook name. When a plugin is uninstalled without cleaning up, its scheduled hooks remain in the cron table but nothing ever runs them — they fire silently, waste a small amount of server time, and clutter the schedule.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'An event is flagged as orphaned only when TWO checks agree: nothing is listening for its hook, AND the hook name does not belong to any plugin still installed. The second check is not a refinement, it is what makes the first usable: plugins such as MainWP Child, Complianz or LiteSpeed register their cron callback only in the context where it runs — a REST call, the front end, their own request — so on this admin screen the hook looks unclaimed while the plugin is present and keeps re-creating the event. The bias is deliberately conservative: when in doubt, not an orphan. Removing a genuine orphan is entirely safe — no code listens for the hook, so firing it has no effect, and removing it only stops the silent wasted trigger.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The list also shows legitimate recurring events (WordPress core, active plugins) so you have a complete picture of what is scheduled and how frequently. This helps spot events that are running too often or that you did not expect.', 'wpoptimizing' ) . '</p>'
						. '<p>' . esc_html__( 'The WP-Cron trigger panel at the top of the tab controls how cron fires. By default WordPress runs it on visitor page loads, which adds latency to random requests and can miss tasks on low-traffic sites. Disabling WP-Cron writes the DISABLE_WP_CRON constant into wp-config.php (a protected backup copy is kept in the plugin\'s data folder) and shows the exact crontab line to add so a real server cron drives wp-cron.php on a fixed schedule. If wp-config.php is not writable, the panel shows the snippet to add by hand. The change is fully reversible from the same panel.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-frontend'      => array(
				'title'   => __( 'Front-end performance', 'wpoptimizing' ),
				'icon'    => 'editor-code',
				'summary' => $txt['fe_summary'],
				'body'    => '<p>' . esc_html__( 'Speculative loading uses the browser Speculation Rules API to load the next page before the visitor clicks. In Conservative mode the next page is prefetched when a link is clicked; in Eager mode it is prerendered on hover, so navigation can feel instant. The admin, the login page, URLs with a query string, and WooCommerce cart/checkout/account pages are excluded automatically, as are links marked rel="nofollow" or with the class no-prerender / no-prefetch.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Content image loading optimization marks the first image of a post as the priority (LCP) image — loaded eagerly with fetchpriority=high — while the remaining images are lazy-loaded with asynchronous decoding. It uses the WordPress HTML Tag Processor, so it works even on markup produced by page builders.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The Server-Timing header is a diagnostic for developers: it reports the WordPress processing time, the number of database queries and the object-cache hit rate, visible in the browser DevTools Network panel. It is off by default and intended for troubleshooting, not for end users.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Resource hints look at the external origins your page actually loads (web fonts, CDNs) and ask the browser to resolve DNS and open the connection early, so those assets start downloading sooner. WPOptimizing adds preconnect for the well-known font origins and dns-prefetch for the rest, automatically and only for origins the page really uses — there is nothing to configure. The hints are advisory and harmless: a redundant one only costs a cheap DNS lookup.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Compatibility with caching, SEO and image-optimization plugins: these features are built to coexist. WPOptimizing never overwrites an object-cache.php or db.php drop-in installed by another plugin — it detects it and steps aside. Resource hints are de-duplicated against hints the theme or another plugin already added. Image loading only ADDS attributes and skips any image another lazy-loader has already deferred, so it does not fight a dedicated image plugin or WordPress core. The sitemap and feed core-tweaks simply switch off the built-in versions, which an SEO plugin normally replaces anyway, so enabling them next to one is safe.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'You do not have to configure these by hand: each cleanup profile (applied from Settings or the welcome wizard) already enables sensible front-end defaults, so choosing a profile sets them up for you. You can override any of them anytime under Speed › Page & browser › Loading & delivery.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-tweaks'        => array(
				'title'   => __( 'Core tweaks', 'wpoptimizing' ),
				'icon'    => 'admin-customizer',
				'summary' => __( 'Optional toggles in Settings that disable WordPress features you may not use (emojis, the version meta, RSD/WLW links, jQuery Migrate, oEmbed extras, REST API head links and more), trimming front-end weight and head noise. All are off by default and reversible.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'WordPress loads a number of features on every page that many sites never use: the emoji script and its DNS prefetch, a generator meta tag with your version, RSD and Windows Live Writer links, a shortlink tag, the jQuery Migrate compatibility script, oEmbed discovery and the wp-embed script, REST API discovery links, and more. Each one is small, but together they add markup, requests and a little attack surface. The Core tweaks section lets you switch off the ones you do not need.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Tweaks are grouped by risk so you can decide how far to go. Safe tweaks have no visible effect (they only remove head noise). Moderate tweaks may affect a few setups — for example removing jQuery Migrate can reveal an old theme or plugin that still relies on deprecated jQuery, and removing front-end Dashicons assumes your theme does not use that icon font for logged-out visitors.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Everything here is fully reversible: a tweak only removes a core hook or adds a short-circuit filter while it is enabled, and unticking it restores the default behaviour on the next page load. No content or settings are deleted. Features that WPOptimizing already manages elsewhere — Heartbeat, post revisions and autosave, the oEmbed cache, the object cache, query deduplication and front-end asset unloading — are intentionally not duplicated here; configure those on their own screens. Security-oriented switches such as XML-RPC are also out of scope and belong to dedicated hardening tools.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'You do not have to enable these one by one: each cleanup profile (applied from Settings or the welcome wizard) already turns on a sensible set — all the Safe tweaks for every profile, plus selected Moderate ones for the blog and aggressive presets.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-image'         => array(
				'title'   => __( 'Image optimization', 'wpoptimizing' ),
				'icon'    => 'format-image',
				'summary' => $txt['img_summary'],
				'body'    => '<p>' . esc_html__( 'The analysis is free and read-only: it walks /uploads and reports the total image weight, a breakdown by format, how many images are large (≥ 300 KB), how many exceed your max dimensions, and how many lack a WebP/AVIF copy, with an estimate of the space a conversion could save. It never changes a file.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['img_heaviest'] ) . '</p>'
					. '<p>' . esc_html__( 'Front-end serving wraps each image in a <picture> element with the AVIF and WebP copies as sources and the original as the fallback. The browser picks the first format it supports; browsers that support neither receive the untouched JPEG/PNG. A source is only added when its copy actually exists on disk, so serving is always safe.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['img_compat'] ) . '</p>'
					. '<p>' . esc_html( $txt['img_settings'] ) . '</p>',
			),


			// ── Cleanup ───────────────────────────────────────────────────────

			'wpopt-doc-general'       => array(
				'title'   => __( 'General cleanup', 'wpoptimizing' ),
				'icon'    => 'admin-tools',
				'summary' => $txt['gen_summary'],
				'body'    => '<p>' . esc_html__( 'Over time WordPress accumulates records that are no longer needed for the site to function: every draft save creates a revision, every failed publish leaves an auto-draft, deleted content sits in the trash, spam fills the comments table, and transients created by plugins often expire but are never purged. General Cleanup removes all of these in one pass.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Quick Profiles apply a recommended set of tasks in a single click. Every profile enables the basic tasks (auto-drafts, trash, spam, expired transients and table optimization); Safe, Blog and Membership add nothing riskier, WooCommerce adds orphaned elements, Action Scheduler, WooCommerce sessions and orphaned variations, and Agency (maximum) adds unapproved comments, duplicate metadata, orphaned elements, Action Scheduler and unused tags. No profile deletes all transients.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The Dry Run option shows a count of what would be removed without deleting anything — useful for auditing before your first cleanup. After a real run, a detailed HTML log is saved and accessible from the history list so you can review exactly what was deleted.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['gen_backup'] ) . '</p>'
					. '<p>' . esc_html__( 'Per-post protection: open any post in the editor (block or classic) and tick "Keep all revisions" to exclude that single post from revision cleanup — useful for legal pages or contracts where the full edit history matters. WPOptimizing also honors the keep_revisions custom field used by "Optimize Database after Deleting Revisions", so you can migrate from that plugin without reconfiguring anything.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The "By content type" sub-tab is a read-only diagnostic that shows where your database weight concentrates, grouped by post type: live entries, revisions, auto-drafts and attached metadata rows. Revisions and auto-drafts are attributed to their real type (revisions through their parent post), so you can see at a glance which content types carry the most overhead before deciding what to clean.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Toolbar shortcuts: the WPOptimizing menu in the top admin bar runs a general cleanup or a full analysis in one click from any screen, on desktop and mobile alike.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Very large databases: the analysis runs asynchronously in stages, so it never blocks the browser or times out a request. On installs with tens of millions of rows, WPOptimizing automatically switches the heaviest counts to fast approximate queries and caps the per-query time, so some figures (like per-type metadata totals) may be shown as approximate or omitted to protect the live site.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-media'         => array(
				'title'   => __( 'Media', 'wpoptimizing' ),
				'icon'    => 'admin-media',
				'summary' => $txt['media_summary'],
				'body'    => '<p>' . esc_html__( 'The Media tab covers three separate problem types. Potentially-unused attachments are Media Library entries that are not referenced in any post content, post meta, widget, option or theme mod — they may have been uploaded and then forgotten. Review each one carefully: a file can be "unused" in the database but still referenced by hard-coded HTML in a template.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Orphaned thumbnail files are physical image files in wp-content/uploads that correspond to intermediate sizes no longer registered by any active theme or plugin. They consume disk space but are never served. They are safe to remove once you have confirmed the size is no longer needed.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['media_backup'] ) . '</p>'
					. '<p>' . esc_html( $txt['media_imgopt'] ) . '</p>'
					. '<p>' . esc_html( $txt['media_entries'] ) . '</p>',
			),

			'wpopt-doc-pending'       => array(
				'title'   => __( 'To identify', 'wpoptimizing' ),
				'icon'    => 'flag',
				'summary' => __( 'Records and files that could not be attributed to any known plugin or theme. They may belong to a custom plugin not yet in the manifest, or to bespoke code. Review them manually before taking any action.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Attribution uses a shared community dataset built from scan results contributed by thousands of WordPress installations. When WPOptimizing finds a database record or file that does not match any entry in the dataset and cannot be inferred from the slug or prefix of an installed extension, it is listed under “To identify” instead of being classified as Orphan or Inactive.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Every attributed item carries a confidence level, from most to least reliable: Manual (you attributed it yourself), Sure, Likely, Possible, Doubtful and Estimated; hover over a badge to see what it means. Only Manual, Sure, Likely and Possible allow bulk deletion. Items to identify that have no owner at all show Undetermined.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'What to do with items to identify: first, check whether the record or file belongs to a custom plugin or active feature of your site. If it does, no action is needed. If you are confident it is orphaned clutter from something you removed, you can delete it via the standard checkbox interface — a backup is created first as with all deletions.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Attribute manually: when you know who owns an item to identify, the "Attribute manually" panel above the list lets you teach WPOptimizing so the item leaves “To identify”. Open its own help — the ? next to the panel title — for the full guide.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The dataset grows with each contribution. Items still to identify today may be automatically attributed after the next dataset update.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-empty'         => array(
				'title'   => __( 'Empty records', 'wpoptimizing' ),
				'icon'    => 'marker',
				'summary' => __( 'Every database record whose stored value is empty, with a Status column telling you whether each one is To identify, Orphan, Inactive or In use. A convenient way to clear leftover keys that hold nothing.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'This section lists options and post/user/term meta whose value is empty: NULL, an empty string, or an empty serialized/JSON structure (a:0:{}, [], {}). It deliberately ignores values like 0 or false, which carry meaning. A meta key appears only when EVERY record under it is empty, never when only some are; the “Empty records” column then shows the total — all of them empty.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The Status column reuses the same attribution engine as the rest of the plugin, so each row is marked To identify (no known owner), Orphan (owner not installed), Inactive (owner installed but off) or In use (owner running now). This lets you clean with judgement instead of blindly.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Safety: records owned by an active extension or by WordPress core are shown read-only — they have no checkbox and cannot be deleted here, because a key may be empty on purpose and removing it could break the owner. Only empties marked To identify, Orphan or Inactive can be selected.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Empty-row deletion is backup-first and removes only the empty rows for a key, never the non-empty ones that share the same name — so it is fully restorable from the Backups tab.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-manual-attrib' => array(
				'title'   => __( 'Manual attribution', 'wpoptimizing' ),
				'icon'    => 'admin-users',
				'summary' => __( 'Manually tell WPOptimizing who owns items it could not attribute. Matched items leave “To identify” and move to Orphan or Inactive depending on whether the owner is installed.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Use this panel when you know who owns an item to identify but the shared dataset does not. Add a rule that matches either by “contains” — a family of items that share a fragment, e.g. wysija → MailPoet — or by “exact”, a single specific value. Rules are applied at display time, so matched items leave “To identify” on the next load without re-scanning, and are re-classified as Orphan (owner not installed) or Inactive (owner installed but currently off).', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Pick one or more owners per rule. The owner box searches as you type across three sources: your installed plugins and themes, the public wordpress.org repository, and the shared knowledge base — so you can pin the exact extension even if it is no longer installed, or type a slug for one that is not listed. Choosing several owners at once (e.g. MailPoet and MailPoet Premium) creates one rule per owner, which is useful when a value belongs to a plugin and its add-ons.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Faster still: tick the rows you recognise in the “To identify” list, click “Attribute selected”, and choose the owner(s) once — WPOptimizing creates one exact rule per selected row and owner and opens this panel with them prefilled.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'If the owner you pick is currently active, WPOptimizing also refuses to delete the matched items — including items already ticked in a list that was loaded before you added the rule. An owner that is switched off or no longer installed does not protect anything: attributing an orphan is how you get to delete it knowingly.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The rules you have added are listed under the form with their own search, owner filter and pagination; click “Remove” to delete a rule. Removing a rule sends its matched items back to “To identify” on the next load.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'With your consent (the shared-dataset setting), each rule is also sent — batched and off-request, never blocking the page — to the shared knowledge base, where it is verified before being promoted. Your manual hints improve automatic attribution for everyone over time.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-attr-assist'   => array(
				'title'   => __( 'Attribution assistant', 'wpoptimizing' ),
				'icon'    => 'lightbulb',
				'summary' => __( 'Ask the shared knowledge base to suggest the owner of database records still to identify. After each analysis the sure ones are applied on their own; you confirm the rest, and nothing is ever deleted automatically.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'The assistant appears under Analysis results › To identify › Database and under Orphans › Database. It sends only the identifier NAMES of the records shown (option, meta, cron and table names — never their values or any content) to the shared knowledge base, which returns the most likely owning plugin or theme for each one. On Orphans it also compares the answer with the owner already on screen and flags the records where the two disagree, which is what you want to see before deleting anything.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Each result carries a verdict: Sure or Likely (a confident, namespace-backed match), Possible (a single-owner hint to confirm), or Ambiguous / No data (shown for review only, with no owner). Confident results offer an Accept button that creates an exact manual attribution rule — exactly as if you had typed it yourself — so the record leaves “To identify” on the next load and your choice also feeds the knowledge base. The assistant is retrieval over the shared knowledge base, not a language model: it can only answer with owners that are already recorded there, and it abstains instead of guessing. When there are several Sure results, “Accept all sure suggestions” accepts them in one go.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Only the Sure verdicts are applied on their own: after each analysis, in the background, the records still to identify are asked about, and the Sure answers become Likely attributions — below anything this site saw itself, never manual rules, and they never delete anything. Everything else waits for you. A record attributed to a plugin that is not installed becomes an orphan like any other, deleted only when you choose to and with a backup first. The assistant is opt-in: it requires the shared-dataset setting to be on, since it relies on the same anonymous knowledge base you contribute to.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-orphans'       => array(
				'title'   => __( 'Orphans', 'wpoptimizing' ),
				'icon'    => 'editor-unlink',
				'summary' => __( 'Records and files confirmed as belonging to plugins or themes that are no longer installed. The manifest attributes them with high certainty, so nothing on the site uses them. Delete them unless you plan to reinstall that extension and want to keep its settings; orphan deletions are backed up automatically beforehand.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Items are listed as Orphans when the dataset can attribute them to a specific extension slug AND that slug is not present in the list of installed plugins or themes. Its confidence must be Possible or higher (Manual, Sure, Likely or Possible) for the item to be classified as an Orphan; with Doubtful or Estimated it stays in “To identify”.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Database orphans include options (rows in wp_options), custom tables, user roles, post meta keys, taxonomies, and custom post type records left by plugins that were deleted without running their uninstall routines. File orphans are files and directories inside wp-content that belonged to a plugin or theme folder that is now gone.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Orphan deletion is backup-first: WPOptimizing exports the affected rows (for database items) or records the file paths (for filesystem items) into a restorable backup entry before removing anything. If you delete something by mistake, go to the Backups tab and restore the relevant operation.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Protected items: you can mark any database element as protected so it is never deleted, even by a "delete all" action, regardless of how the attribution heuristics classify it. Click the shield icon next to an item in the To identify, Orphans, Inactive or Empty lists to protect it (the shield turns green) or click it again to unprotect. The shield is not shown under In use: those records belong to currently-active extensions and are never a deletion target, so there is nothing to protect them from. To protect (or unprotect) many at once, tick the rows and use the single "Protect selected" button: it unprotects when every ticked row is already protected, and protects otherwise (leaving any already-protected rows untouched). This is a hard safety net for the rare case where an item is misattributed. Developers can protect items programmatically by returning false from the wpoptimizing_can_delete_item filter.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-inactive'      => array(
				'title'   => __( 'Inactive', 'wpoptimizing' ),
				'icon'    => 'hidden',
				'summary' => __( 'Database records and files belonging to plugins or themes that are installed but currently inactive. These items are not needed while the extension is off, but may be required again if it is reactivated. The Plugins & Themes sub-tab lets you uninstall inactive extensions directly.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Inactive items are a middle ground: the plugin is still installed (its files are present), but it is not active, so its database entries and any files it manages outside its own plugin folder are serving no purpose right now. Whether to delete them depends on your intent: if the plugin is being kept around for occasional use, leave its data alone; if you know you will never reactivate it, uninstall it properly.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The Plugins & Themes sub-tab lists every inactive plugin and theme with a checkbox for selection. Clicking Delete runs the plugin\'s own uninstall hook (if it has one) and removes its files. The "Also remove associated data" checkbox cascade-deletes the database and filesystem items associated with those slugs from the Inactive lists at the same time.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Caution: deactivating a plugin does not remove its data. Uninstalling it may, depending on whether the plugin\'s author implemented an uninstall routine. WPOptimizing\'s cascade-delete fills that gap for items it can attribute, but it cannot know about data a plugin stores in non-standard locations.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-active'        => array(
				'title'   => __( 'Active', 'wpoptimizing' ),
				'icon'    => 'yes-alt',
				'summary' => __( 'Read-only view of records and files attributed to currently active plugins or themes. No deletion is allowed here. It is an audit view so you can see what active extensions are storing and how much space they use.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'The In use list (Analysis results › In use) gives you a complete picture of what each running plugin or theme is storing in your database and filesystem. Because the extension is active, deleting these items would break functionality — the tab is intentionally read-only. Its purpose is transparency and accountability.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Use the extension filter to drill down into a single plugin and see every option, post meta key, custom table or file it owns. This is useful when auditing a new plugin before committing to it, or when investigating unexpected database growth.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Each item carries its confidence level (Manual, Sure, Likely, Possible, Doubtful, Estimated or Undetermined), which says how its owner was established: from your own rule, captured live, read from the code or the shared dataset, or guessed from a prefix. Here it matters only for correcting a wrong owner or protecting a record, since nothing in this list is deleted.', 'wpoptimizing' ) . '</p>',
			),

			// ── Tools & data ──────────────────────────────────────────────────

			'wpopt-doc-history'       => array(
				'title'   => __( 'History', 'wpoptimizing' ),
				'icon'    => 'chart-area',
				'summary' => __( 'Tracks your site\'s database size, autoload weight, table overhead and other metrics over time. A snapshot is recorded automatically after every scan and cleanup so you can measure the impact of each action.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Every time a scan completes or a cleanup runs, WPOptimizing captures a snapshot of key metrics: total database size, autoloaded options weight, total table overhead, number of orphaned records, and more. These snapshots build a timeline you can browse to see whether your site is getting leaner over time.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The headline cards at the top summarise the metrics that matter most — including a Server TTFB card with a trend sparkline — each showing the current value, the change since the baseline, and a mini chart of its evolution. A card always shows the latest recorded value of its metric, even if the most recent snapshot happened to be a database-only scan that did not capture it, so the same set of cards appears consistently.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The baseline is the very first snapshot recorded. All subsequent values are shown relative to it so you can see absolute improvements at a glance. You can reset the baseline at any time — for example, after a major cleanup — to start measuring from a new reference point.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'The Export button streams the full timeline as a CSV file. Import it into a spreadsheet to build your own charts, share it with a client, or keep it as an audit record.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Browse the timeline with the event and date filters, per-page and search — the same controls as the other tables. You decide how long snapshots are kept with the "Keep timeline data" control below the timeline (Forever, 3 months by default, 6 months or 1 year); older ones are pruned automatically each day, while the pinned baseline is always kept.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-activity'      => array(
				'title'   => __( 'Activity log', 'wpoptimizing' ),
				'icon'    => 'list-view',
				'summary' => __( 'A change journal for the whole site: what other plugins and themes add or remove over time — tables, options, post/user meta keys, scheduled events and top-level files — each dated and attributed when possible.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'When WPOptimizing is installed it captures a baseline signature of your site: the set of database tables, option names, post and user meta keys, scheduled cron hooks, and the top-level entries under plugins, themes, mu-plugins and uploads. From then on it compares the current state against that baseline at meaningful moments and records what changed.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'A change is captured whenever a plugin or theme is installed, activated, deactivated, deleted or updated, and after each analysis. This has no per-page-load cost — nothing is measured on normal requests. When the change rides a plugin or theme action, that extension is recorded as responsible, so you can see exactly what a newly installed plugin added to your database and filesystem.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Each entry shows the date, whether the item was added or removed, its type, its name, and the responsible extension (or Unknown for background changes). Filter by type or change and search by name, with the same controls as the other tables. The journal is capped and kept out of autoload, so it never weighs on the site; you can clear it at any time and tracking continues from the current baseline.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-backups'       => array(
				'title'   => __( 'Backups', 'wpoptimizing' ),
				'icon'    => 'backup',
				'summary' => $txt['backups_summary'],
				'body'    => '<p>' . esc_html__( 'Before WPOptimizing removes or changes database rows, it serialises them into a backup file under wp-content/wpoptimizing/backups/ (not in the database). For filesystem deletions it writes a manifest and copies each file alongside it; an edited file (.htaccess, wp-config.php) is copied whole. Each entry is tagged with the operation that created it (orphan deletion, database cleanup, autoload change, media deletion, etc.) and a timestamp, so you can quickly find the one you need. If the backup cannot be written — disk full, permissions, or the size limit set in Settings — the action does not run.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'To restore a backup, open the Backups tab, find the entry, and click Restore. For database backups this re-inserts the rows keeping their original IDs; for file backups it copies the files back to their original paths and reports any it could not restore.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Old backups are pruned automatically according to the maximum count and maximum age limits set in Settings. You can also delete individual backups manually once you are confident the cleanup was correct. Creating a manual backup from the Backups tab captures the current state of a table or option set you specify.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Clean uninstall: by default, deleting the plugin from the Plugins screen removes every trace it created — all settings and transients, scheduled tasks, the object-cache and database drop-ins, the runtime mu-plugin, every backup and scan file, and the WebP/AVIF copies it generated. Your already-optimized images are kept (only the extra next-gen copies and the backups are removed; originals are not restored). You can turn this off in Settings if you plan to reinstall and want to keep your configuration and backups. Note that deactivating the plugin never deletes anything — only deleting it does.', 'wpoptimizing' ) . '</p>',
			),

			'wpopt-doc-settings'      => array(
				'title'   => __( 'Settings', 'wpoptimizing' ),
				'icon'    => 'admin-settings',
				'summary' => $txt['settings_summary'],
				'body'    => '<p>' . esc_html__( 'Quick Profiles are the fastest way to configure WPOptimizing for your site type. Each profile bundles a curated set of cleanup rules together with the appropriate performance settings: Safe, Blog and Membership stick to the basic tasks; WooCommerce adds the store-specific ones (sessions, orphaned variations, Action Scheduler); Agency (maximum) adds duplicate metadata and unused tags as well.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Applying a profile also configures the Cache tab automatically: the appropriate cache backend is installed, query deduplication is enabled or disabled based on the profile\'s recommendation, and the heartbeat rate is set. You can override any of these from the Cache tab after applying a profile.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Backup retention: set a maximum number of backup entries, a maximum age in days and a maximum total size. Entries beyond the count or age limit are pruned automatically; when a new backup would cross the size limit, the action stops instead of filling the disk. The minimum retention is 1 entry and 1 day — WPOptimizing always keeps at least the most recent backup.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['settings_notify'] ) . '</p>'
					. '<p>' . esc_html( $txt['settings_export'] ) . '</p>',
			),


			'wpopt-doc-dataset'       => array(
				'title'   => __( 'Shared dataset', 'wpoptimizing' ),
				'icon'    => 'share',
				'summary' => __( 'WPOptimizing uses a community manifest that maps plugin and theme slugs to their known database tables, options and files. The more sites that contribute their scan results, the more accurate attribution becomes for everyone.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'Attribution accuracy is the core quality metric of WPOptimizing: the more precisely the plugin can say "this option belongs to plugin X", the safer and more useful the cleanup is. The shared dataset is a crowd-sourced manifest that grows with every participating site. When your site uploads its scan results, the new data is merged into the dataset and made available to every participating site on the next update.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Dataset uploads never include page URLs, post content, user data, option values or personal information. The upload contains only structural metadata: table names, option key patterns, file path patterns, and the plugin slug they are associated with, plus your site’s domain, which authorises the exchange.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html( $txt['dataset_optout'] ) . '</p>',
			),

			'wpopt-doc-languages'     => array(
				'title'   => __( 'Languages and custom translations', 'wpoptimizing' ),
				'icon'    => 'translation',
				'summary' => __( 'English plus eight complete translations. If you want to change a string, put your catalogue in wp-content/languages/plugins/ — never inside the plugin folder, where an update would delete it and where it would not be read.', 'wpoptimizing' ),
				'body'    => '<p>' . esc_html__( 'The plugin ships English as its source language and eight complete translations: Spanish (es_ES), German (de_DE), French (fr_FR), Italian (it_IT), Portuguese for Portugal (pt_PT), Russian (ru_RU), Arabic (ar) and Chinese Simplified (zh_CN). Portuguese is pt_PT and is not the same as Brazilian Portuguese (pt_BR); a pt_BR site falls back to English until someone contributes that locale.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Catalogues are shipped in the WordPress 6.5 performance format (.l10n.php), which loads faster than the classic .mo and is the format WordPress prefers. The .pot template ships too, which is what a translation tool needs to start a new locale or to send us a correction.', 'wpoptimizing' ) . '</p>'
					. '<p><strong>' . esc_html__( 'Where to put your own catalogue:', 'wpoptimizing' ) . '</strong> '
					. esc_html__( 'wp-content/languages/plugins/, named wpoptimizing-<locale>.mo (or .l10n.php). WordPress searches that folder BEFORE the plugin\'s own, so your file wins, and it survives plugin updates — anything inside the plugin folder is replaced on every update.', 'wpoptimizing' ) . '</p>'
					. '<p><strong>' . esc_html__( 'The one trap worth knowing:', 'wpoptimizing' ) . '</strong> '
					. esc_html__( 'do not drop your .mo inside the plugin\'s own languages/ folder. Since WordPress 6.5 the .l10n.php in the SAME folder is preferred over a .mo, so your file would sit there being ignored with no error to tell you. In wp-content/languages/plugins/ there is no .l10n.php of ours to shadow it, which is exactly why that is the right place.', 'wpoptimizing' ) . '</p>'
					. '<p>' . esc_html__( 'Loco Translate and similar tools already save to that folder by default, so following their defaults is correct. If you improve a translation, send it back and it ships for everyone.', 'wpoptimizing' ) . '</p>',
			),

		);

		return $sections;
	}

	/**
	 * The ONE source for the "how to get a PageSpeed API key" steps. Used by the docs tab
	 * (long-form body) and by the help modal next to the key field, so the instructions can
	 * never drift between the two places. Returns pre-escaped, kses-safe HTML.
	 *
	 * @return string
	 */
	protected static function psi_key_steps_html() {
		$enable_url = 'https://console.cloud.google.com/apis/library/pagespeedonline.googleapis.com';
		$creds_url  = 'https://console.cloud.google.com/apis/credentials';
		$docs_url   = 'https://developers.google.com/speed/docs/insights/v5/get-started';

		$html  = '<p><strong>' . esc_html__( 'How to get the key, step by step', 'wpoptimizing' ) . '</strong></p>';
		$html .= '<ol class="wpopt-num-list">';
		$html .= '<li>' . esc_html__( 'Sign in to the Google Cloud console with any Google account and create a project (or pick an existing one).', 'wpoptimizing' ) . '</li>';
		$html .= '<li>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to the PageSpeed Insights API page in the Google Cloud console. */
				__( 'Open the <a href="%s" target="_blank" rel="noopener noreferrer">PageSpeed Insights API</a> page and press “Enable”.', 'wpoptimizing' ),
				esc_url( $enable_url )
			)
		) . '</li>';
		$html .= '<li>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to the Google Cloud console credentials page. */
				__( 'Go to <a href="%s" target="_blank" rel="noopener noreferrer">Credentials</a> and choose “Create credentials” → “API key”.', 'wpoptimizing' ),
				esc_url( $creds_url )
			)
		) . '</li>';
		$html .= '<li>' . esc_html__( 'Copy the key, paste it in the field above and save it. Restricting the key to the PageSpeed Insights API is recommended.', 'wpoptimizing' ) . '</li>';
		$html .= '</ol>';
		$html .= '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to Google's official PageSpeed Insights documentation. */
				__( 'Official documentation: <a href="%s" target="_blank" rel="noopener noreferrer">Get started with the PageSpeed Insights API</a>.', 'wpoptimizing' ),
				esc_url( $docs_url )
			)
		) . '</p>';

		return $html;
	}
}
