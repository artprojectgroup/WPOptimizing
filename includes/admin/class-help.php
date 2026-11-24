<?php
/**
 * Contextual help: the native Help tab of WordPress on every screen of the plugin, one panel
 * per tab or settings section, explaining each section and setting. Every panel and the
 * sidebar link the full documentation: Pro's lives in the knowledge base of wpoptimizing.com,
 * the free build's on one page of the Art Project Group site in the reader's language.
 *
 * The labels are the same source strings the screens print, so they read exactly as the
 * screen in every language without a second translation.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the help panels to the WPOptimizing screens.
 */
class WPOptimizing_Help {


	/**
	 * Documentation of the free build: one page on the Art Project Group site, in Spanish for
	 * es_* readers and in English for everyone else. The free build comes without a support centre.
	 */
	const LITE_DOCS_ES = 'https://artprojectgroup.es/documentacion/wpoptimizing';
	const LITE_DOCS_EN = 'https://artprojectgroup.com/documentation/wpoptimizing';

	/**
	 * Section of that page for each panel, by view and panel id ("view.panel"); the same ids in
	 * both languages.
	 *
	 * @var array<string,string>
	 */
	const LITE_ANCHORS = array(
		'home.home'               => 'home',
		'home.analysis'           => 'analysis',
		'performance.performance' => 'performance',
		'history.history'         => 'history',
		'cache.cache'             => 'cache',
		'cache.cloudflare'        => 'cloudflare',
		'cache.browser'           => 'browser-server',
		'frontend.minify'         => 'minification',
		'frontend.loading'        => 'loading-delivery',
		'assets.assets'           => 'smart-assets',
		'autoload.autoload'       => 'autoload',
		'general.general'         => 'general-cleanup',
		'tables.tables'           => 'tables',
		'cron.cron'               => 'cron-jobs',
		'media.media'             => 'media',
		'media.images'            => 'image-optimization',
		'media.audit'             => 'library-audit',
		'review.states'           => 'result-states',
		'review.confidence'       => 'confidence-levels',
		'review.delete'           => 'deleting-uninstalling',
		'review.attribute'        => 'manual-attribution',
		'activity.activity'       => 'activity',
		'backups.backups'         => 'backups',
		'docs.docs'               => 'documentation',
		'settings.general'        => 'settings-backups',
		'settings.profile'        => 'quick-profile',
		'settings.cleanup'        => 'cleanup-options',
		'settings.tweaks'         => 'core-tweaks',
		'settings.webhooks'       => 'webhooks',
		'settings.compat'         => 'compatibility',
		'settings.dataset'        => 'shared-dataset',
		'settings.portable'       => 'import-export',
		'network.network'         => 'network',
	);


	/**
	 * Hooks the help into a screen. Called with the hook suffix add_menu_page() and
	 * add_submenu_page() return, so it follows the screen whatever the menu is called.
	 *
	 * @param string $hook Page hook suffix.
	 * @return void
	 */
	public static function register( $hook ) {
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'add_tabs' ) );
		}
	}

	/**
	 * Language of the knowledge base for the current user: one of the nine languages of
	 * wpoptimizing.com, English for any other.
	 *
	 * @return string
	 */
	private static function docs_language() {
		$lang = strtolower( substr( (string) get_user_locale(), 0, 2 ) );
		return in_array( $lang, array( 'es', 'de', 'fr', 'it', 'pt', 'ru', 'zh', 'ar' ), true ) ? $lang : 'en';
	}

	/**
	 * Address of the documentation in the user's language: of one panel, or of the whole
	 * documentation when both arguments are empty.
	 *
	 * @param string $slug   English slug of the knowledge-base article (Pro).
	 * @param string $anchor Panel, as "view.panel" (free build).
	 * @return string
	 */
	private static function docs_url( $slug = '', $anchor = '' ) {
		$url = 'es' === self::docs_language() ? self::LITE_DOCS_ES : self::LITE_DOCS_EN;
		if ( '' !== $anchor && isset( self::LITE_ANCHORS[ $anchor ] ) ) {
			$url .= '#' . self::LITE_ANCHORS[ $anchor ];
		}
		return $url;
	}

	/**
	 * Whether the links to the documentation and, in Pro, to the support centre are shown. A white-label
	 * install hides the Art Project Group links everywhere, and the help follows it.
	 *
	 * @return bool
	 */
	private static function show_links() {
		return ! ( function_exists( 'wpoptimizing_is_white_label' ) && wpoptimizing_is_white_label() );
	}

	/**
	 * Adds the panels of the current screen. Hooked to load-{page}.
	 *
	 * @return void
	 */
	public static function add_tabs() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only the plugin's help on its own screens.
		$screen->remove_help_tabs();

		$view = self::current_view();
		foreach ( self::get_panels( $view ) as $id => $panel ) {
			$screen->add_help_tab(
				array(
					'id'      => 'wpoptimizing_help_' . $id,
					'title'   => $panel['title'],
					'content' => self::render( $panel, $view . '.' . $id ),
				)
			);
		}

		if ( self::show_links() ) {
			$sidebar = '<p><strong>' . esc_html__( 'More help', 'wpoptimizing' ) . '</strong></p>'
				. '<p><a href="' . esc_url( self::docs_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'Documentation', 'wpoptimizing' ) . '</a></p>';
			$screen->set_help_sidebar( $sidebar );
		}
	}

	/**
	 * Which screen is open: network, settings or one tab of the main screen, resolved the way
	 * WPOPT_Admin_UI::render() resolves it.
	 *
	 * @return string
	 */
	private static function current_view() {
		if ( is_network_admin() ) {
			return 'network';
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Only chooses which help to show.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'wpoptimizing-settings' === $page ) {
			return 'settings';
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home';
		$psec = isset( $_GET['psec'] ) ? sanitize_key( wp_unslash( $_GET['psec'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'performance' === $tab && 'browser' === $psec ) {
			return 'frontend';
		}
		if ( in_array( $tab, array( 'review', 'orphans', 'pending', 'inactive', 'empty', 'active', 'db', 'fs' ), true ) ) {
			return 'review';
		}
		$known = array( 'home', 'performance', 'history', 'cache', 'frontend', 'assets', 'autoload', 'general', 'tables', 'cron', 'media', 'activity', 'backups', 'docs' );
		return in_array( $tab, $known, true ) ? $tab : 'home';
	}

	/**
	 * Outputs a panel: its lead paragraphs, a list of term => explanation and the link to its
	 * article of the knowledge base.
	 *
	 * @param array  $panel Title, items (int key: paragraph; string key: term => text) and doc.
	 * @param string $key   Panel, as "view.panel".
	 * @return string
	 */
	private static function render( array $panel, $key = '' ) {
		$html = '';
		$list = '';

		foreach ( $panel['items'] as $term => $text ) {
			if ( is_int( $term ) ) {
				$html .= '<p>' . esc_html( $text ) . '</p>';
			} else {
				$list .= '<li><strong>' . esc_html( $term ) . '</strong>: ' . esc_html( $text ) . '</li>';
			}
		}
		$html .= '' !== $list ? '<ul>' . $list . '</ul>' : '';

		$url = ! empty( $panel['doc'] ) && self::show_links() ? self::docs_url( $panel['doc'], $key ) : '';
		if ( '' !== $url ) {
			$label = __( 'Read more in the documentation', 'wpoptimizing' );
			$html .= '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a></p>';
		}

		return $html;
	}

	/**
	 * Panels of a screen.
	 *
	 * @param string $view Screen, from current_view().
	 * @return array<string,array{title:string,items:array,doc?:string}>
	 */
	private static function get_panels( $view ) {
		$method = 'panels_' . $view;
		return method_exists( __CLASS__, $method ) ? self::$method() : self::panels_home();
	}

	/**
	 * The buttons above every tab.
	 *
	 * @return array
	 */
	private static function panel_analysis() {
		return array(
			'title' => __( 'Analysis', 'wpoptimizing' ),
			'doc'   => 'the-first-analysis',
			'items' => array(
				__( 'The analysis reads your plugins, themes, database and files and works out who owns each record and file. It runs in the background in stages, so the window can be closed, and it changes nothing on the site.', 'wpoptimizing' ),
				__( 'Analyze now', 'wpoptimizing' ) => __( 'starts a new analysis straight away, whatever the low-traffic window says; at most five per hour.', 'wpoptimizing' ),
				__( 'View report', 'wpoptimizing' ) => __( 'opens a printable summary of the last analysis in a new window.', 'wpoptimizing' ),
				__( 'Below the buttons, the date of the last analysis. If the database cut some of its queries short, a notice says so and the database lists keep the results of the previous analysis.', 'wpoptimizing' ),
			),
		);
	}

	/**
	 * Home.
	 *
	 * @return array
	 */
	private static function panels_home() {
		return array(
			'home'     => array(
				'title' => __( 'Home', 'wpoptimizing' ),
				'doc'   => 'home-and-analysis-results',
				'items' => array(
					__( 'Where your site stands and what to do next. Nothing on this page changes your site: each suggestion opens the screen that does it.', 'wpoptimizing' ),
					__( 'Site status', 'wpoptimizing' ) => __( 'three cards: the weight of the autoloaded options (healthy under 800 KB), the orphaned data the analysis found and the state of the object cache.', 'wpoptimizing' ),
					__( 'Optimization health', 'wpoptimizing' ) => __( 'a score out of 100 with the server response time and up to three ways to raise it.', 'wpoptimizing' ),
					__( 'Needs attention', 'wpoptimizing' ) => __( 'orphaned records, an analysis older than 14 days, heavy autoload, reclaimable media space and orphaned scheduled tasks, each with a link to the screen that fixes it.', 'wpoptimizing' ),
					__( 'What the analysis found', 'wpoptimizing' ) => __( 'the records and files of the last analysis by state, with a link to each list.', 'wpoptimizing' ),
				),
			),
			'analysis' => self::panel_analysis(),
		);
	}

	/**
	 * Diagnose › Performance.
	 *
	 * @return array
	 */
	private static function panels_performance() {
		return array(
			'performance' => array(
				'title' => __( 'Performance', 'wpoptimizing' ),
				'doc'   => 'performance-assistant',
				'items' => array(
					__( 'Measures how long WordPress takes to start sending the page (the server share of TTFB) and lists what can make it faster.', 'wpoptimizing' ),
					__( 'Summary', 'wpoptimizing' ) => __( 'the measured response time, its trend and the verdict.', 'wpoptimizing' ),
					__( 'PageSpeed Insights', 'wpoptimizing' ) => __( 'tests a page of your site, on mobile or desktop, with the public API of Google. It sends the address of that page to Google and changes nothing on your site. With your own API key you can also load the history of real visitors (Chrome UX Report).', 'wpoptimizing' ),
					__( 'Server', 'wpoptimizing' )  => __( 'the levers the plugin can apply with one click (object cache, query deduplication, autoload, Heartbeat), the ones that need server access as guidance (system cron, PHP and OPcache) and a read-only check of the database and Redis.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Diagnose › History.
	 *
	 * @return array
	 */
	private static function panels_history() {
		return array(
			'history' => array(
				'title' => __( 'History', 'wpoptimizing' ),
				'doc'   => 'history-and-activity-log',
				'items' => array(
					__( 'Where you started versus where you are now. A snapshot is taken automatically before and after every analysis and after every cleanup.', 'wpoptimizing' ),
					__( 'Set baseline to now', 'wpoptimizing' ) => __( 'takes a new starting point to compare with. Earlier snapshots are kept.', 'wpoptimizing' ),
					__( 'Export CSV', 'wpoptimizing' ) => __( 'downloads every snapshot, to open it in a spreadsheet.', 'wpoptimizing' ),
					__( 'Keep timeline data:', 'wpoptimizing' ) => __( 'three months by default. Choosing a shorter period deletes the older snapshots at once; the baseline and the first snapshot are always kept.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Speed › Cache.
	 *
	 * @return array
	 */
	private static function panels_cache() {
		// La caché de página básica es de las dos versiones (24/11/2026); lo que cambia es la red:
		// en Lite no se ofrece en multisitio y en Pro sí, sitio por sitio.
		$page_cache_help = __( 'serves a static copy of each page to anonymous visitors, for 10 hours by default (from 1 to 12), and clears what changes. Logged-in users, carts, checkout and the admin are never cached. Enabling it writes wp-content/advanced-cache.php and adds WP_CACHE to wp-config.php. It stays off while another page cache is active, and it is not available on a multisite network.', 'wpoptimizing' );
		return array(
			'cache'      => array(
				'title' => __( 'Object and page cache', 'wpoptimizing' ),
				'doc'   => 'page-cache',
				'items' => array(
					__( 'Every cache is off until you turn it on, and turning it off puts back what was there. A drop-in that belongs to another plugin is never overwritten unless you choose to replace it, and the original is kept to give it back.', 'wpoptimizing' ),
					__( 'Object cache', 'wpoptimizing' ) => __( 'keeps the results of database queries in Redis, Memcached, APCu or a SQLite file. Enabling it writes wp-content/object-cache.php.', 'wpoptimizing' ),
					__( 'Page cache', 'wpoptimizing' )   => $page_cache_help,
					__( 'Custom exclusions', 'wpoptimizing' ) => __( 'paths, cookies and query parameters on top of the automatic rules, and a separate cache for mobile devices.', 'wpoptimizing' ),
					__( 'Query deduplication', 'wpoptimizing' ) => __( 'writes wp-content/db.php, which answers identical read queries of the same request from memory. Any write clears it.', 'wpoptimizing' ),
					__( 'Analytics', 'wpoptimizing' )    => __( 'hit rate, queries and the most duplicated queries of the last 30 days.', 'wpoptimizing' ),
					__( 'Settings', 'wpoptimizing' )     => __( 'the backend and its connection, Heartbeat and the cached comment counts of the dashboard. Saving flushes the object cache.', 'wpoptimizing' ),
				),
			),
			'cloudflare' => array(
				'title' => __( 'Cloudflare', 'wpoptimizing' ),
				'doc'   => 'cloudflare',
				'items' => array(
					__( 'With the zone ID and an API token with permission to purge the cache, the plugin also clears Cloudflare: everything when it flushes the other caches, and only the affected pages when content changes. Both are on once the token is saved. Saving tests it by purging the home page.', 'wpoptimizing' ),
				),
			),
			'browser'    => array(
				'title' => __( 'Browser & server', 'wpoptimizing' ),
				'doc'   => 'google-fonts-cdn-and-browser-cache',
				'items' => array(
					__( 'Caches in front of your site', 'wpoptimizing' ) => __( 'requests the home page twice as a visitor and reads the headers to see whether your host or a CDN keeps a copy. When it finds Varnish, it can purge it too, unless Proxy Cache Purge already does.', 'wpoptimizing' ),
					__( 'Browser cache & compression', 'wpoptimizing' )  => __( 'adds a block to .htaccess, after a copy of the file, so browsers keep your static files and the server compresses them. Not available on Nginx, and not added while another plugin handles it.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Speed › Page & browser.
	 *
	 * @return array
	 */
	private static function panels_frontend() {
		return array(
			'minify'  => array(
				'title' => __( 'CSS & JavaScript', 'wpoptimizing' ),
				'doc'   => 'css-and-javascript',
				'items' => array(
					__( 'What the browser of the visitor downloads and runs. Everything is off by default, and stays off while another optimizer is active.', 'wpoptimizing' ),
					__( 'Minification', 'wpoptimizing' )   => __( 'serves minified copies of your own CSS and JavaScript; files already minified, external or excluded are left as they are. Test mode applies it only to logged-in administrators. Saving clears the minified copies.', 'wpoptimizing' ),
				),
			),
			'loading' => array(
				'title' => __( 'Loading & delivery', 'wpoptimizing' ),
				'doc'   => 'loading-and-delivery',
				'items' => array(
					__( 'How the page reaches the visitor. All optional and off by default.', 'wpoptimizing' ),
					__( 'Speculative loading', 'wpoptimizing' ) => __( 'the browser prepares the next page on click (conservative) or on hover (eager). The admin, login, cart and checkout are left out.', 'wpoptimizing' ),
					__( 'Image loading', 'wpoptimizing' )  => __( 'gives priority to the first image and lazy-loads the rest.', 'wpoptimizing' ),
					__( 'Server-Timing', 'wpoptimizing' )  => __( 'a header with the time the server took, to read in the developer tools of the browser.', 'wpoptimizing' ),
					__( 'Resource hints', 'wpoptimizing' ) => __( 'automatic: preconnect for the Google Fonts origins and dns-prefetch for the other external origins whose scripts and styles the page loads.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Speed › Smart Assets.
	 *
	 * @return array
	 */
	private static function panels_assets() {
		$lite_note_0 = __( 'Smart Assets, which stops the CSS and JavaScript a page does not need, is not included in this plugin.', 'wpoptimizing' ); // Lite only says it is not included; Pro explains every setting.
		return array(
			'assets' => array(
				'title' => __( 'Smart Assets', 'wpoptimizing' ),
				'doc'   => 'smart-assets',
				'items' => array_filter( array(
					$lite_note_0,
				) ),
			),
		);
	}

	/**
	 * Speed › Autoload.
	 *
	 * @return array
	 */
	private static function panels_autoload() {
		return array(
			'autoload' => array(
				'title' => __( 'Autoload', 'wpoptimizing' ),
				'doc'   => 'autoload-and-health-score',
				'items' => array(
					__( 'Autoloaded options load into memory on every request; under about 800 KB in total is healthy. Disabling autoload keeps the option and stops loading it on every page, and it can be undone.', 'wpoptimizing' ),
					__( 'Disable autoload for selected', 'wpoptimizing' ) => __( 'saves a backup first, then stops loading the selected options on every request.', 'wpoptimizing' ),
					__( 'Smart Deactivation', 'wpoptimizing' )            => __( 'the same for the options confidently identified as belonging to uninstalled extensions.', 'wpoptimizing' ),
					__( 'Advanced autoload cleanup', 'wpoptimizing' )     => __( 'disables autoload for the options never read, or for every non-essential one, and deletes the confirmed orphans. Deleting is the only action here that removes options, and it saves a backup first.', 'wpoptimizing' ),
					__( 'Could be autoloaded', 'wpoptimizing' )           => __( 'options read on almost every request without being autoloaded. The plugin measures it by itself; Smart Activation autoloads those read on at least 90% of 50 or more requests, up to 20 KB each.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Clean up › General cleanup.
	 *
	 * @return array
	 */
	private static function panels_general() {
		return array(
			'general' => array(
				'title' => __( 'General cleanup', 'wpoptimizing' ),
				'doc'   => 'general-cleanup-tasks',
				'items' => array(
					__( 'A maintenance pass over revisions, drafts, trash, comments, transients and the other tasks chosen in Settings. Everything it deletes is backed up first and can be restored from Backups; if a backup cannot be written, the cleanup stops.', 'wpoptimizing' ),
					__( 'Preview (dry run)', 'wpoptimizing' ) => __( 'counts what the cleanup would remove with the current settings, without deleting anything.', 'wpoptimizing' ),
					__( 'Run general cleanup now', 'wpoptimizing' ) => __( 'runs it straight away, after asking. Every enabled task runs, whatever its frequency.', 'wpoptimizing' ),
					__( 'Logs', 'wpoptimizing' ) => __( 'the last run and the previous reports, to view, download or print. Event lines are included only with "Generate detailed log".', 'wpoptimizing' ),
					__( 'By content type', 'wpoptimizing' ) => __( 'where the weight of your database sits by content type. Read-only.', 'wpoptimizing' ),
					__( 'Active settings', 'wpoptimizing' ) => __( 'the tasks and limits are chosen in Settings, under General cleanup options.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Clean up › Tables.
	 *
	 * @return array
	 */
	private static function panels_tables() {
		return array(
			'tables' => array(
				'title' => __( 'Tables', 'wpoptimizing' ),
				'doc'   => 'tables',
				'items' => array(
					__( 'Every table of this site with its engine, rows, size, recoverable overhead and suspected owner. Optimizing and converting never delete data, so they do not make a backup. Orphaned custom tables are deleted from Analysis results.', 'wpoptimizing' ),
					__( 'Unusual table growth since the previous scan', 'wpoptimizing' ) => __( 'tables that grew at least 5 MB and 25% between two analyses.', 'wpoptimizing' ),
					__( 'Tables worth a look', 'wpoptimizing' )                           => __( 'tables whose owner is not active, or that are very large for the database.', 'wpoptimizing' ),
					__( 'Optimize selected', 'wpoptimizing' )                             => __( 'runs OPTIMIZE TABLE. On InnoDB the reported free space may not drop, and that is normal.', 'wpoptimizing' ),
					__( 'Convert selected to InnoDB', 'wpoptimizing' )                    => __( 'changes only the MyISAM tables and keeps all their data.', 'wpoptimizing' ),
					__( 'Tables over 256 MB are not converted from the browser, because it would take minutes and as much free disk space. When optimized, they are rebuilt in the background if that recovers space, and skipped otherwise.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Clean up › Cron jobs.
	 *
	 * @return array
	 */
	private static function panels_cron() {
		return array(
			'cron' => array(
				'title' => __( 'Cron jobs', 'wpoptimizing' ),
				'doc'   => 'scheduled-tasks-and-wp-cron',
				'items' => array(
					__( 'The scheduled tasks of WordPress. An event whose hook has no callback and no installed owner is orphaned: it will never run.', 'wpoptimizing' ),
					__( 'WP-Cron trigger', 'wpoptimizing' ) => __( '"Disable WP-Cron" adds DISABLE_WP_CRON to wp-config.php, after a copy of the file listed in Backups, and gives the line to add to the crontab of the server. "Re-enable WP-Cron" removes it.', 'wpoptimizing' ),
					__( 'Are the tasks running?', 'wpoptimizing' ) => __( 'warns when an event is more than 10 minutes late, because then nothing is firing WP-Cron.', 'wpoptimizing' ),
					__( 'Run now', 'wpoptimizing' ) => __( 'queues the task to run in the background in a moment, after a backup of its events.', 'wpoptimizing' ),
					__( 'Pause', 'wpoptimizing' )   => __( 'the task stays scheduled but does nothing until you resume it. WordPress core tasks cannot be paused.', 'wpoptimizing' ),
					__( 'Unschedule selected', 'wpoptimizing' ) => __( 'removes orphaned events only, after a backup restorable from Backups. Active and WordPress core events are protected.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Clean up › Media.
	 *
	 * @return array
	 */
	private static function panels_media() {
		$lite_note_1 = __( 'The library audit is not included in this plugin.', 'wpoptimizing' ); // Lite only says it is not included; Pro explains every setting.
		return array(
			'media'  => array(
				'title' => __( 'Media', 'wpoptimizing' ),
				'doc'   => 'media',
				'items' => array(
					__( 'The uploads folder as the last analysis found it. Every deletion here saves a copy of the files first, restorable from Trash or Backups.', 'wpoptimizing' ),
					__( 'Orphans', 'wpoptimizing' )        => __( 'files with no record in the database, thumbnails whose original is missing and files in named folders, usually left by plugins. Review them before deleting.', 'wpoptimizing' ),
					__( 'Unused attachments', 'wpoptimizing' ) => __( 'attachments with no reference found in content, meta, galleries or parent posts. It is a heuristic: confirm each one, or mark it as known to keep it. References are checked again before deleting, and "Delete all" is refused when the search was incomplete.', 'wpoptimizing' ),
					__( 'Trash', 'wpoptimizing' )          => __( 'the copies of the deleted media. Restore puts the files back; Delete removes the copy for good and frees its space.', 'wpoptimizing' ),
				),
			),
			'images' => array(
				'title' => __( 'Image optimization', 'wpoptimizing' ),
				'doc'   => 'image-optimization',
				'items' => array(
					__( 'The weight and formats of your images and what would help each one. The analysis runs with "Analyze now".', 'wpoptimizing' ),
					__( 'Settings', 'wpoptimizing' ) => __( 'quality (82 by default), maximum dimensions (2560 × 2560), folders never optimized, image sizes no longer generated for new uploads, and optimizing new uploads as they arrive. Serving WebP and AVIF as <picture> sources is on by default.', 'wpoptimizing' ),
					__( 'Serve WebP/AVIF from the server', 'wpoptimizing' ) => __( 'adds a rule to the .htaccess of the uploads folder, after a copy of it, and checks that it works. On Nginx it gives the configuration instead.', 'wpoptimizing' ),
				),
			),
			'audit'  => array(
				'title' => __( 'Library audit', 'wpoptimizing' ),
				'doc'   => 'library-audit',
				'items' => array_filter( array(
					$lite_note_1,
				) ),
			),
		);
	}

	/**
	 * Review the analysis › Analysis results, with its five states.
	 *
	 * @return array
	 */
	private static function panels_review() {
		// Lite deletes one record at a time; deleting the whole filtered list is Pro (23/10/2026).
		$bulk_label = __( 'Delete selected', 'wpoptimizing' );
		$bulk_desc  = __( 'deletes the record you ticked, after a backup: this plugin deletes one at a time. The notice that follows offers Undo for one day.', 'wpoptimizing' );
		return array(
			'states'     => array(
				'title' => __( 'Analysis results', 'wpoptimizing' ),
				'doc'   => 'home-and-analysis-results',
				'items' => array(
					__( 'Every record and file the last analysis attributed, filtered by state. Each state has a Database and a Files view.', 'wpoptimizing' ),
					__( 'Orphans', 'wpoptimizing' )     => __( 'left behind by extensions that are no longer installed. Nothing on the site uses them; delete them unless you plan to reinstall that extension and keep its settings.', 'wpoptimizing' ),
					__( 'To identify', 'wpoptimizing' ) => __( 'no owner could be identified. They may belong to something still in use: review them one by one, attribute them by hand or ask the attribution assistant.', 'wpoptimizing' ),
					__( 'Inactive', 'wpoptimizing' )    => __( 'they belong to installed but deactivated plugins or themes. Delete them only if you will not use those extensions again. Plugins & Themes lists them to uninstall.', 'wpoptimizing' ),
					__( 'Empty', 'wpoptimizing' )       => __( 'records with no value stored. Those of active extensions or of WordPress are read-only.', 'wpoptimizing' ),
					__( 'In use', 'wpoptimizing' )      => __( 'they belong to active extensions or WordPress. Nothing is deleted here: fix a wrong owner, protect a record or deactivate plugins and themes.', 'wpoptimizing' ),
				),
			),
			'confidence' => array(
				'title' => __( 'Confidence levels', 'wpoptimizing' ),
				'doc'   => 'confidence-levels',
				'items' => array(
					__( 'How much is known about the owner of each record. Only Manual, Sure, Likely and Possible can be deleted; anything lower goes to To identify.', 'wpoptimizing' ),
					__( 'Manual', 'wpoptimizing' )       => __( 'you attributed it yourself, and it outranks every automatic source.', 'wpoptimizing' ),
					__( 'Sure', 'wpoptimizing' )         => __( 'captured live on this site, by a plugin whose own namespace the item belongs to.', 'wpoptimizing' ),
					__( 'Likely', 'wpoptimizing' )       => __( 'seen by comparing what the site registered before and after activating the extension.', 'wpoptimizing' ),
					__( 'Possible', 'wpoptimizing' )     => __( 'read from the code of the extension, or confirmed by several sites in the shared dataset.', 'wpoptimizing' ),
					__( 'Doubtful', 'wpoptimizing' )     => __( 'one claim only, outside the namespace of the claimant, or reported by few sites.', 'wpoptimizing' ),
					__( 'Estimated', 'wpoptimizing' )    => __( 'inferred from a prefix or a slug.', 'wpoptimizing' ),
					__( 'Undetermined', 'wpoptimizing' ) => __( 'no owner found.', 'wpoptimizing' ),
				),
			),
			'delete'     => array(
				'title' => __( 'Deleting and uninstalling', 'wpoptimizing' ),
				'doc'   => 'what-gets-backed-up',
				'items' => array(
					__( 'Every deletion makes a backup first, and if it cannot be made nothing is deleted. Before deleting, each record is checked again: anything now in use, part of WordPress or no longer in the last analysis is kept, and very large entries are refused with the reason, since backing them up from the browser is not possible. The content of orphaned custom post types and the terms of orphaned taxonomies are only deleted when you tick that option.', 'wpoptimizing' ),
					$bulk_label => $bulk_desc,
					__( 'Protect selected', 'wpoptimizing' ) => __( 'protected records are never deleted by any cleanup until you unprotect them.', 'wpoptimizing' ),
					__( 'Uninstall selected', 'wpoptimizing' ) => __( 'copies the folder of each plugin or theme to Backups and then deletes it the WordPress way, which also runs its own uninstall routine; what that routine removes from the database is not in the copy. With the option ticked, the data and files the analysis found for them are deleted too, after a backup.', 'wpoptimizing' ),
				),
			),
			'attribute'  => array(
				'title' => __( 'Manual attribution', 'wpoptimizing' ),
				'doc'   => 'to-identify-and-manual-rules',
				'items' => array(
					__( 'Attribute manually', 'wpoptimizing' )     => __( 'tells WPOptimizing who owns an item when the automatic attribution is wrong or missing: "Contains" for a family of items, "Exact value" for one. Your rule takes priority, and the item moves to In use, Inactive or Orphans at once, without a new analysis. An item whose owner is active can no longer be deleted.', 'wpoptimizing' ),
					__( 'Attribution assistant', 'wpoptimizing' ) => __( 'suggests owners from the shared knowledge base, for To identify, or checks the owners shown, for Orphans. It needs data sharing. After each analysis the sure owners are applied on their own; the rest change only when you click Accept, one by one or all the sure ones at once, which creates a rule.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * History & recovery › Activity.
	 *
	 * @return array
	 */
	private static function panels_activity() {
		return array(
			'activity' => array(
				'title' => __( 'Activity', 'wpoptimizing' ),
				'doc'   => 'history-and-activity-log',
				'items' => array(
					__( 'What changed in WordPress since WPOptimizing was installed: tables, options, meta keys, scheduled events and plugin, theme and upload folders added or removed, with the date and the extension responsible when it is known. It is recorded when plugins or themes change and after every analysis, and keeps the latest 2,000 changes.', 'wpoptimizing' ),
					__( 'Clear activity log', 'wpoptimizing' ) => __( 'empties the list; the starting point is kept, so tracking continues from now.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * History & recovery › Backups.
	 *
	 * @return array
	 */
	private static function panels_backups() {
		return array(
			'backups' => array(
				'title' => __( 'Backups', 'wpoptimizing' ),
				'doc'   => 'backups-retention-and-restore',
				'items' => array(
					__( 'The copies made before deletions, cleanups, autoload changes, uninstalls and edits of .htaccess and wp-config.php. They are kept in wp-content/wpoptimizing/backups/, and how many and for how long is set in Settings.', 'wpoptimizing' ),
					__( 'Restore', 'wpoptimizing' ) => __( 'puts back the database rows and the files of that operation. The backup is kept after restoring.', 'wpoptimizing' ),
					__( 'Delete', 'wpoptimizing' )  => __( 'removes the backup for good.', 'wpoptimizing' ),
					__( 'Create backup now', 'wpoptimizing' ) => __( 'saves every record and file of the last analysis, without deleting anything. It is not a full backup of the site.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * Help › Documentation.
	 *
	 * @return array
	 */
	private static function panels_docs() {
		return array(
			'docs' => array(
				'title' => __( 'Documentation', 'wpoptimizing' ),
				'doc'   => 'what-is-wpoptimizing',
				'items' => array(
					__( 'A quick reference for every feature inside the plugin, in three groups: Performance, Cleanup and Tools & data. The question mark next to a section title opens the same text. The full manual, with every setting explained, is the knowledge base.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * The Settings screen, one panel per section.
	 *
	 * @return array
	 */
	private static function panels_settings() {
		$lite_note_2 = __( 'Webhooks are not included in this plugin.', 'wpoptimizing' ); // Lite only says it is not included; Pro explains every setting.
		return array(
			'general'  => array(
				'title' => __( 'Backups and notifications', 'wpoptimizing' ),
				'doc'   => 'notifications-report-and-settings-import',
				'items' => array(
					__( 'Max backups per type', 'wpoptimizing' ) => __( '25 by default; the oldest of each type are deleted automatically.', 'wpoptimizing' ),
					__( 'Max age (days)', 'wpoptimizing' ) => __( 'backups older than this are deleted automatically; 30 by default.', 'wpoptimizing' ),
					__( 'Max backup size (MB)', 'wpoptimizing' ) => __( 'when the backups would grow past it (2048 MB by default), the change stops instead of filling the disk. 0 means no limit.', 'wpoptimizing' ),
					__( 'Notification email', 'wpoptimizing' ) => __( 'where the periodic report is sent; the address of the site administrator when empty. Separate several addresses with commas.', 'wpoptimizing' ),
					__( 'Maintenance report', 'wpoptimizing' ) => __( 'an email with the health score, the main figures and the activity, daily to monthly. Off by default. "Send a test now" sends one at once without changing the schedule.', 'wpoptimizing' ),
					__( 'Background sweeper', 'wpoptimizing' ) => __( 'clears expired transients a few at a time on admin requests; off by default, and idle with a persistent object cache.', 'wpoptimizing' ),
					__( 'On uninstall', 'wpoptimizing' )   => __( 'on by default: deleting the plugin deletes its settings, backups and analysis data. The drop-ins, the must-use plugins and the lines added to wp-config.php are removed in any case. Optimized images are kept.', 'wpoptimizing' ),
				),
			),
			'profile'  => array(
				'title' => __( 'Quick profile', 'wpoptimizing' ),
				'doc'   => 'quick-profiles',
				'items' => array(
					__( 'Apply a preset', 'wpoptimizing' ) => __( 'Safe, Blog, WooCommerce, Membership or Agency (maximum), with the one that suits your site marked as recommended. Applying it sets the cleanup tasks, front-end performance, core tweaks and Heartbeat, and turns on the object cache and query deduplication. The cleanup schedule is not changed.', 'wpoptimizing' ),
				),
			),
			'cleanup'  => array(
				'title' => __( 'Cleanup options', 'wpoptimizing' ),
				'doc'   => 'scheduling-and-growth-triggered-cleanup',
				'items' => array(
					__( 'Revisions to keep per post', 'wpoptimizing' )     => __( '2 by default; the older revisions of each post are deleted on every cleanup. A post marked "Keep all revisions" keeps all of them.', 'wpoptimizing' ),
					__( 'Database cleanup tasks', 'wpoptimizing' )         => __( 'what the general cleanup removes. Auto-drafts, trashed posts, spam and trashed comments, expired transients and table optimization are on by default; the rest are off.', 'wpoptimizing' ),
					__( 'Keep trash and spam for at least (days)', 'wpoptimizing' ) => __( 'leaves alone what was sent to the trash or marked as spam more recently, so a mistake can still be undone.', 'wpoptimizing' ),
					__( 'Automatic analysis', 'wpoptimizing' )             => __( 'daily by default, only inside the low-traffic window (02:00 to 06:00, site time). A manual analysis always runs at once.', 'wpoptimizing' ),
				),
			),
			'tweaks'   => array(
				'title' => __( 'Core tweaks', 'wpoptimizing' ),
				'doc'   => 'core-tweaks',
				'items' => array(
					__( 'Turn off WordPress features you do not use. Every option is off by default and reversible; nothing is deleted. The safe ones only remove noise from the head; the moderate ones (jQuery Migrate, Dashicons, oEmbed, remote patterns, REST links, WooCommerce cart fragments) may affect a few setups, so review each one.', 'wpoptimizing' ),
					__( 'Front-end performance', 'wpoptimizing' ) => __( 'these settings are now in Speed › Page & browser.', 'wpoptimizing' ),
				),
			),
			'webhooks' => array(
				'title' => __( 'Webhooks', 'wpoptimizing' ),
				'doc'   => 'webhooks',
				'items' => array_filter( array(
					$lite_note_2,
				) ),
			),
			'compat'   => array(
				'title' => __( 'Compatibility', 'wpoptimizing' ),
				'doc'   => 'compatibility-with-other-plugins',
				'items' => array(
					__( 'The optimization plugins found on the site and what each one already does. Where doing it twice would conflict, the WPOptimizing version stays off.', 'wpoptimizing' ),
					__( 'Use WPOptimizing for this anyway', 'wpoptimizing' ) => __( 'runs ours as well for that feature.', 'wpoptimizing' ),
				),
			),
			'dataset'  => array(
				'title' => __( 'Shared dataset', 'wpoptimizing' ),
				'doc'   => 'shared-dataset',
				'items' => array(
					__( 'Contribute & receive', 'wpoptimizing' ) => __( 'off by default. When on, the names of options, meta keys, tables and files, their sizes and how often they are seen are sent with the domain of the site, and the shared dataset is received in return. Values and content are never sent.', 'wpoptimizing' ),
					__( 'Sync dataset now', 'wpoptimizing' )     => __( 'uploads and downloads at once instead of waiting for the daily sync.', 'wpoptimizing' ),
				),
			),
			'portable' => array(
				'title' => __( 'Import / export', 'wpoptimizing' ),
				'doc'   => 'notifications-report-and-settings-import',
				'items' => array(
					__( 'Download settings (.json)', 'wpoptimizing' ) => __( 'a file with the configuration, to set another site up the same way. Keys, passwords, webhooks and the data-sharing consent are never included.', 'wpoptimizing' ),
					__( 'Import settings', 'wpoptimizing' )           => __( 'overwrites the current settings with those of the file; unknown entries are ignored.', 'wpoptimizing' ),
					__( 'Replay welcome wizard', 'wpoptimizing' )     => __( 'shows the welcome wizard again on the main screen.', 'wpoptimizing' ),
				),
			),
		);
	}

	/**
	 * The network dashboard of a multisite.
	 *
	 * @return array
	 */
	private static function panels_network() {
		return array(
			'network' => array(
				'title' => __( 'Network', 'wpoptimizing' ),
				'doc'   => 'multisite-network-dashboard',
				'items' => array(
					__( 'A read-only summary of every site of the network. Each site analyses and cleans its own data; open a site to manage it.', 'wpoptimizing' ),
					__( 'Network quick setup', 'wpoptimizing' ) => __( 'applies a quick profile to every site or to the ones chosen, and sets the alert thresholds of that profile. Nothing is deleted.', 'wpoptimizing' ),
					__( 'Analyze all sites', 'wpoptimizing' ) => __( 'schedules a new analysis on every site.', 'wpoptimizing' ),
					__( 'Export CSV', 'wpoptimizing' )   => __( 'the table, to open it in a spreadsheet; View report opens a printable version.', 'wpoptimizing' ),
					__( 'Email alerts', 'wpoptimizing' ) => __( 'a daily summary to the network administrator when a site has this much orphaned data, has not been analysed in this many days or has this much autoload. 0 disables each one; the same sites are not reported again for a week.', 'wpoptimizing' ),
				),
			),
		);
	}
}
