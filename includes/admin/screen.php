<?php
/**
 * Admin screen: menu registration, the WPOptimizing page renderer, admin assets, admin
 * notices and the admin_init maintenance hooks (license check, drop-in refresh, report
 * screens).
 *
 * None of this is reachable from a front-end page load, so it is required from
 * wpoptimizing_load_engine() (admin / cron / WP-CLI) rather than on every request. The
 * capability and nonce checks live inside each function; the lazy load is purely a
 * performance measure.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-help.php'; // Native Help tab of every plugin screen.

/**
 * Register (from WooCommerce when present, our bundled copy otherwise) + enqueue
 * Select2/SelectWoo and init every .wpopt-select2 control. Shared by the plugin screens and
 * the post editor (meta box). Still degrades to a native <select> if the script fails to load.
 *
 * @return void
 */
function wpoptimizing_enqueue_select2() {
	// Select2/SelectWoo — needed on per-site screens (settings) AND the network
	// overview (site multi-select in the wizard). The init script runs on any screen that has
	// .wpopt-select2. WooCommerce registers its own selectWoo on every admin screen, and that copy
	// is used when present; its folder is no longer probed by path, which assumed the default
	// plugins directory.

	/*
	 * WITHOUT WOOCOMMERCE, OUR OWN COPY. The fields used to fall back to native <select>s, and a
	 * native multi-select is a bare list box that most people do not know takes Ctrl-click: on
	 * wpoptimizing.com, which has no WooCommerce, "Excluded tables" was exactly that (14/10/2026).
	 * The bundled SelectWoo is only registered when nobody else has one, so a site with
	 * WooCommerce keeps loading a single copy, WooCommerce's.
	 */
	if ( ! wp_script_is( 'select2', 'registered' ) && ! wp_script_is( 'selectWoo', 'registered' ) ) {
		$min = ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ? '' : '.min';
		wp_register_script( 'selectWoo', plugins_url( 'assets/vendor/selectwoo/selectWoo.full' . $min . '.js', WPOPTIMIZING_FILE ), array( 'jquery' ), '1.0.10', true );
	}
	if ( ! wp_style_is( 'select2', 'registered' ) ) {
		wp_register_style( 'select2', plugins_url( 'assets/vendor/selectwoo/select2.css', WPOPTIMIZING_FILE ), array(), '1.0.10' );
	}

	$select2_handle = '';
	if ( wp_script_is( 'select2', 'registered' ) ) {
		$select2_handle = 'select2';
	} elseif ( wp_script_is( 'selectWoo', 'registered' ) ) {
		$select2_handle = 'selectWoo';
	}
	if ( '' !== $select2_handle ) {
		wp_enqueue_script( $select2_handle );
		if ( wp_style_is( 'select2', 'registered' ) ) {
			wp_enqueue_style( 'select2' );
		}
		$select2_l10n    = wp_json_encode(
			array(
				'short'        => __( 'Type 2 or more characters to search…', 'wpoptimizing' ),
				'searching'    => __( 'Searching…', 'wpoptimizing' ),
				'noResults'    => __( 'No matches found', 'wpoptimizing' ),
				'errorLoading' => __( 'Could not load the results', 'wpoptimizing' ),
				'loadingMore'  => __( 'Loading more results…', 'wpoptimizing' ),
			)
		);
		$select2_init_js = 'jQuery(function($){
			var WPOPTL10N = ' . $select2_l10n . ";
			var \$fields = $('.wpopt-select2, .wpopt-select2-auto');
			if (!\$fields.length) { return; }
			\$fields.each(function(){
				var \$field = $(this);
				// '-auto' fields (e.g. inline list-table filters) size to their content
				// and stay on one row; plain '.wpopt-select2' fill their container.
				var autoW = \$field.hasClass('wpopt-select2-auto');
				if (typeof $.fn.select2 === 'function') {
					var opts = {
						width: autoW ? 'auto' : '100%',
						minimumResultsForSearch: 0,
						// '-tags' fields let the user type a value not in the list
						// (e.g. attribute to an uninstalled, now-orphaned plugin).
						tags: \$field.hasClass('wpopt-select2-tags')
					};
					// '-ajax' fields search live across installed + wp.org + KB so the
					// user pins the exact slug/type even for uninstalled extensions.
					if (\$field.hasClass('wpopt-select2-ajax') && window.ajaxurl) {
						opts.minimumInputLength = 2;
						opts.ajax = {
							url: window.ajaxurl,
							dataType: 'json',
							delay: 250,
							data: function (params) {
								return { action: 'wpoptimizing_owner_search', nonce: \$field.data('nonce'), term: params.term };
							},
							processResults: function (data) {
								return { results: (data && data.results) ? data.results : [] };
							}
						};
					}
					opts.language = {
						inputTooShort: function () { return WPOPTL10N.short; },
						searching: function () { return WPOPTL10N.searching; },
						noResults: function () { return WPOPTL10N.noResults; },
						errorLoading: function () { return WPOPTL10N.errorLoading; },
						loadingMore: function () { return WPOPTL10N.loadingMore; }
					};
					\$field.select2(opts);
					if (\$field.hasClass('wpopt-select2-ajax')) {
						// Force the chosen remote result to persist + render. SelectWoo can
						// drop a single-select AJAX selection — notably the first, auto-
						// highlighted result — so append the option (if missing) and set it.
						\$field.on('select2:select', function(e){
							var d = (e.params && e.params.data) ? e.params.data : null;
							if (!d || typeof d.id === 'undefined') { return; }
							var found = false;
							\$field.find('option').each(function(){ if (this.value === d.id) { found = true; } });
							if (!found) { \$field.append(new Option(d.text, d.id, true, true)); }
							if (\$field.attr('multiple')) {
								var v = \$field.val() || [];
								if (v.indexOf(d.id) === -1) { v.push(d.id); }
								\$field.val(v);
							} else {
								\$field.val(d.id);
							}
							\$field.trigger('change.select2');
						});
					}
					if (!\$field.attr('multiple')) {
						// The manual-attribution add form uses 40px controls; everywhere else
						// (filters, settings) is 32px to match WP's native buttons/inputs
						// (1px border + 30px + 1px border).
						var h = \$field.closest('.wpopt-ma-row').length ? 40 : 32;
						var \$c = \$field.next('.select2-container');
						\$c.find('.select2-selection--single').css({'height':h+'px','line-height':(h-2)+'px','margin-bottom':'0'});
						\$c.find('.select2-selection__rendered').css({'line-height':h+'px','padding-top':'0','padding-bottom':'0'});
						\$c.find('.select2-selection__arrow').css({'height':(h-2)+'px'});
					}
				}
				\$field.on('change', function(){
					if (!\$field.attr('multiple')) { return; } // single-select (e.g. owner): skip sentinel logic so a value containing 'all' isn't cleared.
						var values = \$field.val() || [];
					// Exclusive sentinel options: when one is picked alongside real
					// entries it wins and clears the rest ('__none__' = none excluded,
					// 'all' = apply to every site in the network wizard).
					var exclusive = ['__none__', 'all'];
					for (var i = 0; i < exclusive.length; i++) {
						if (values.indexOf(exclusive[i]) !== -1 && values.length > 1) {
							\$field.val([exclusive[i]]).trigger('change.select2');
							break;
						}
					}
				});
			});
		});";
		wp_add_inline_script( $select2_handle, $select2_init_js );
	}
}

/**
 * Enqueue admin styles for WPOptimizing screens and plugin list.
 *
 * @param string $hook Current admin hook.
 * @return void
 */
function wpoptimizing_admin_assets( $hook ) {
	$screen    = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$screen_id = $screen ? $screen->id : '';
	$allowed   = array(
		'toplevel_page_wpoptimizing',
		'wpoptimizing_page_wpoptimizing-settings',
		'plugins',
		'toplevel_page_wpoptimizing-network',         // network overview
		'toplevel_page_wpoptimizing-network-network', // network overview (network admin suffix)
	);
	if ( ! in_array( $screen_id, $allowed, true ) ) {
		return;
	}

	wp_enqueue_style(
		'wpoptimizing-admin',
		plugins_url( 'assets/css/admin.css', WPOPTIMIZING_FILE ),
		array(),
		(string) @filemtime( plugin_dir_path( WPOPTIMIZING_FILE ) . 'assets/css/admin.css' ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	);

	wpoptimizing_enqueue_select2();

}
add_action( 'admin_enqueue_scripts', 'wpoptimizing_admin_assets' );

/**
 * On the post editor (post.php / post-new.php) for public post types, load Select2 so
 * the Smart Assets meta box's multi-select gets the searchable picker. Only for users
 * with the plugin capability; no admin.css is loaded here (the meta box reuses core
 * classes), just Select2.
 *
 * @param string $hook Current admin hook.
 * @return void
 */
function wpoptimizing_editor_select2_assets( $hook ) {
	if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	$cap = function_exists( 'wpoptimizing_cap' ) ? wpoptimizing_cap() : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( $screen && $screen->post_type && ! post_type_supports( $screen->post_type, 'editor' ) && ! is_post_type_viewable( $screen->post_type ) ) {
		return;
	}
	wpoptimizing_enqueue_select2();
}
add_action( 'admin_enqueue_scripts', 'wpoptimizing_editor_select2_assets' );

/*
 * Avisos de licencia y recordatorio del modo gratuito: sólo Pro. Lite no tiene licencia, así
 * que no hay modo gratuito que recordar ni caducidad que avisar.
 */

/**
 * Intercept the impact report request before WordPress renders any admin chrome.
 * Outputs a standalone HTML page and exits so no sidebar/header appears.
 *
 * @return void
 */
function wpoptimizing_maybe_serve_impact_report() {
	if (
		! isset( $_REQUEST['page'], $_REQUEST['wpopt_impact_report'], $_REQUEST['_wpopt_ir_nonce'] )
		|| 'wpoptimizing' !== sanitize_key( wp_unslash( $_REQUEST['page'] ) )
	) {
		return;
	}
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpopt_ir_nonce'] ) ), 'wpopt_impact_report' ) ) {
		wp_die( esc_html__( 'Security check failed.', 'wpoptimizing' ) );
	}
	if ( class_exists( 'WPOPT_Admin_UI' ) ) {
		( new WPOPT_Admin_UI() )->render_impact_report();
	}
}
add_action( 'admin_init', 'wpoptimizing_maybe_serve_impact_report', 1 );

/**
 * Stylesheet of the standalone reports (general cleanup, impact, network): assets/css/report.css,
 * printed into the report's own <head>. The page width and the summary-grid column count are
 * the only things that differ between reports, so they go in as two custom properties.
 *
 * @param int $max_width    Body max-width in px.
 * @param int $summary_cols Columns for the .summary-grid card row.
 * @return string The stylesheet tags, as WordPress prints them.
 */
function wpoptimizing_print_report_styles( $max_width = 960, $summary_cols = 4 ) {
	$mw   = max( 320, (int) $max_width );
	$cols = max( 1, (int) $summary_cols );
	$file = plugin_dir_path( WPOPTIMIZING_FILE ) . 'assets/css/report.css';
	wp_register_style( 'wpoptimizing-report', plugins_url( 'assets/css/report.css', WPOPTIMIZING_FILE ), array(), (string) @filemtime( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	wp_add_inline_style( 'wpoptimizing-report', ':root{--wpopt-report-width:' . $mw . 'px;--wpopt-report-cols:' . $cols . '}' );
	ob_start();
	wp_print_styles( 'wpoptimizing-report' );
	return (string) ob_get_clean();
}

/**
 * Intercept the general-cleanup PDF report / log download before WordPress renders
 * any admin chrome, so the report is a clean standalone page (its own document, no
 * nested sidebar/header) and download headers are sent before any output.
 *
 * @return void
 */
function wpoptimizing_maybe_serve_gc_report() {
	if ( ! isset( $_REQUEST['page'] ) || 'wpoptimizing' !== sanitize_key( wp_unslash( $_REQUEST['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page detection; the report itself is capability-checked + nonce-verified below.
		return;
	}
	if ( class_exists( 'WPOPT_Admin_UI' ) ) {
		( new WPOPT_Admin_UI() )->maybe_serve_gc_report();
	}
}
add_action( 'admin_init', 'wpoptimizing_maybe_serve_gc_report', 1 );


/**
 * Plugin row meta links.
 *
 * @param string[] $links Existing links.
 * @param string   $file  Plugin file.
 * @return string[]
 */
function wpoptimizing_plugin_row_meta( $links, $file ) {
	global $wpoptimizing_info;

	if ( WPOPTIMIZING_BASENAME !== $file ) {
		return $links;
	}

	// White-label installs hide the Art Project Group promo links.
	if ( function_exists( 'wpoptimizing_is_white_label' ) && wpoptimizing_is_white_label() ) {
		return $links;
	}

	$links[] = '<a href="' . esc_url( $wpoptimizing_info['plugin_url'] ) . '" target="_blank" title="' . esc_attr( $wpoptimizing_info['plugin'] ) . '"><strong class="artprojectgroup">APG</strong></a>';
	$links[] = '<a href="https://www.facebook.com/artprojectgroup" title="' . esc_attr__( 'Follow us on ', 'wpoptimizing' ) . 'Facebook" target="_blank"><span class="genericon genericon-facebook-alt"></span></a> <a href="https://x.com/artprojectgroup" title="' . esc_attr__( 'Follow us on ', 'wpoptimizing' ) . 'X" target="_blank"><span class="genericon genericon-x-alt"></span></a> <a href="https://es.linkedin.com/in/artprojectgroup" title="' . esc_attr__( 'Follow us on ', 'wpoptimizing' ) . 'LinkedIn" target="_blank"><span class="genericon genericon-linkedin"></span></a>';
	$links[] = '<a href="https://profiles.wordpress.org/artprojectgroup/" title="' . esc_attr__( 'More plugins on ', 'wpoptimizing' ) . 'WordPress" target="_blank"><span class="genericon genericon-wordpress"></span></a>';
	$links[] = '<a href="mailto:info@artprojectgroup.es" title="' . esc_attr__( 'Contact us by ', 'wpoptimizing' ) . 'e-mail"><span class="genericon genericon-mail"></span></a>';

	return $links;
}
add_filter( 'plugin_row_meta', 'wpoptimizing_plugin_row_meta', 10, 2 );

/**
 * Styles of the two notices WPOptimizing shows outside its own stylesheet: the welcome notice,
 * which appears on any admin screen after the first activation, and the licence notice on the
 * plugin's screens. Attached to an empty handle so they go out through wp_add_inline_style(),
 * scoped to each notice's class, and only for the users who can see either notice.
 *
 * @return void
 */
function wpoptimizing_notice_styles() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		return;
	}
	$css = '.wpopt-welcome-notice{padding:16px 16px 12px;border-left-color:#2271b1}'
		. '.wpopt-welcome-notice .wpopt-welcome-row{display:flex;align-items:center;gap:16px}'
		. '.wpopt-welcome-notice h2{margin:0 0 6px}'
		. '.wpopt-welcome-notice .wpopt-welcome-lead{margin:0 0 10px}'
		. '.wpopt-welcome-notice .wpopt-welcome-actions{margin:0}';
	wp_register_style( 'wpoptimizing-notices', false, array(), WPOPTIMIZING_VERSION );
	wp_enqueue_style( 'wpoptimizing-notices' );
	wp_add_inline_style( 'wpoptimizing-notices', $css );
}
add_action( 'admin_enqueue_scripts', 'wpoptimizing_notice_styles' );

/**
 * First-run onboarding notice — shown once after activation until dismissed.
 *
 * @return void
 */
function wpoptimizing_onboarding_notice() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		return;
	}
	if ( ! get_option( 'wpoptimizing_first_run' ) ) {
		return;
	}
	// On the plugin's own page the first-run wizard already invites the first
	// analysis, so this notice would be redundant — show it only elsewhere.
	$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'wpoptimizing' === $current_page ) {
		return;
	}
	// Dismiss via ?wpopt_dismiss_welcome=1.
	if ( isset( $_GET['wpopt_dismiss_welcome'] ) && check_admin_referer( 'wpopt_dismiss_welcome' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		delete_option( 'wpoptimizing_first_run' );
		return;
	}
	$plugin_url  = admin_url( 'admin.php?page=wpoptimizing' );
	$dismiss_url = wp_nonce_url( add_query_arg( 'wpopt_dismiss_welcome', '1' ), 'wpopt_dismiss_welcome' );
	printf(
		'<div class="notice notice-info is-dismissible wpopt-welcome-notice">
			<div class="wpopt-welcome-row">
				<div>
					<h2>%s</h2>
					<p class="wpopt-welcome-lead">%s</p>
					<p class="wpopt-welcome-actions">
						<a href="%s" class="button button-primary">%s</a>&nbsp;
						<a href="%s" class="button">%s</a>
					</p>
				</div>
			</div>
		</div>',
		esc_html__( 'Welcome to WPOptimizing!', 'wpoptimizing' ),
		esc_html__( 'Your installation is ready. Run your first analysis to discover orphaned records, files and media, see what belongs to each plugin, and safely clean your database.', 'wpoptimizing' ),
		esc_url( $plugin_url ),
		esc_html__( 'Run first analysis →', 'wpoptimizing' ),
		esc_url( $dismiss_url ),
		esc_html__( 'Dismiss', 'wpoptimizing' )
	);
	// The core X (is-dismissible) only hides the notice for this page view; persist it the same
	// way the Dismiss button does, so a closed welcome never comes back on the next screen.
	wp_print_inline_script_tag(
		sprintf(
			'(function(){document.addEventListener("click",function(e){var d=e.target.closest(".notice-dismiss");if(!d||!d.closest(".wpopt-welcome-notice")){return;}var f=new FormData();f.append("action","wpoptimizing_dismiss_welcome");f.append("nonce","%s");fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:f});});}());',
			esc_js( wp_create_nonce( 'wpopt_dismiss_welcome' ) )
		)
	);
}
add_action( 'admin_notices', 'wpoptimizing_onboarding_notice' );

/**
 * Default admin-menu icon: the isotype data URI, or — if the asset is missing — WordPress's
 * Tools icon: the SVG one where the menu takes icon names (wp_is_valid_icon_name(), WordPress
 * 7.2+), the Dashicon before. Filterable via `wpoptimizing_brand_menu_icon`.
 *
 * @return string Icon spec for add_menu_page().
 */
function wpoptimizing_brand_menu_icon_default() {
	$uri = wpoptimizing_isotype_data_uri();
	if ( '' !== $uri ) {
		return $uri;
	}
	return function_exists( 'wp_is_valid_icon_name' ) ? 'core-admin/tool' : 'dashicons-admin-tools';
}

/**
 * Registers the top-level WPOptimizing admin page.
 *
 * @return void
 */
function wpoptimizing_register_admin_page() {
	$brand     = wpoptimizing_brand_name();
	$menu_icon = (string) apply_filters( 'wpoptimizing_brand_menu_icon', wpoptimizing_brand_menu_icon_default() );

	$hooks = array(
		add_menu_page(
			$brand,
			$brand,
			wpoptimizing_cap(),
			'wpoptimizing',
			'wpoptimizing_render_page',
			$menu_icon,
			80
		),
		add_submenu_page(
			'wpoptimizing',
			esc_html__( 'Settings', 'wpoptimizing' ),
			esc_html__( 'Settings', 'wpoptimizing' ),
			wpoptimizing_cap(),
			'wpoptimizing-settings',
			'wpoptimizing_render_page'
		),
	);
	// Actions run here, before any output; see wpoptimizing_process_page_actions().
	foreach ( $hooks as $hook ) {
		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'load-' . $hook, 'wpoptimizing_process_page_actions' );
			WPOptimizing_Help::register( $hook );
		}
	}
}
add_action( 'admin_menu', 'wpoptimizing_register_admin_page' );

/**
 * Render the Overview status cards on the main dashboard landing.
 *
 * Three at-a-glance, native-looking cards (Performance / Database / Cache) with a
 * health state (good/warn/bad — conveyed by an icon-word AND a colour, never colour
 * alone) and a single one-click action each. Data is cheap: one CSV read, one local
 * JSON read and one lightweight options query. Pure presentation; no side effects.
 *
 * @return void
 */
function wpoptimizing_render_overview_cards() {
	$dash = admin_url( 'admin.php?page=wpoptimizing' );

	// ── Database ──────────────────────────────────────────────────────────
	// Use the SAME effective-status (overlay) count the Orphans tab shows, so the card
	// never disagrees with the table and reflects a cleanup immediately (cache busted on
	// delete). Falls back to the cheap stored-status count if the admin UI is unavailable.
	if ( class_exists( 'WPOPT_Admin_UI' ) ) {
		$counts = ( new WPOPT_Admin_UI() )->db_orphan_overview();
	} elseif ( function_exists( 'wpoptimizing_network_count_dir' ) && defined( 'WPOPT_DIR' ) ) {
		$counts = wpoptimizing_network_count_dir( WPOPT_DIR );
	} else {
		$counts = array(
			'orphan'      => 0,
			'reclaimable' => 0,
		);
	}
	$orphans     = (int) ( $counts['orphan'] ?? 0 );
	$reclaimable = (int) ( $counts['reclaimable'] ?? 0 );
	$last_scan   = (int) get_option( 'wpoptimizing_last_scan', 0 );
	$db_value    = number_format_i18n( $orphans );
	if ( 0 === $last_scan ) {
		// No analysis yet — don't claim the database is clean.
		$db_state = 'warn';
		$db_word  = __( 'Not analyzed', 'wpoptimizing' );
		$db_value = '—';
		$db_sub   = __( 'Run an analysis first', 'wpoptimizing' );
	} elseif ( 0 === $orphans ) {
		$db_state = 'good';
		$db_word  = __( 'Clean', 'wpoptimizing' );
		$db_sub   = __( 'No orphaned data', 'wpoptimizing' );
	} elseif ( $reclaimable < 5 * MB_IN_BYTES ) {
		$db_state = 'warn';
		$db_word  = __( 'Review', 'wpoptimizing' );
		/* translators: %s: human-readable size (e.g. 2 MB). */
		$db_sub = sprintf( __( '%s recoverable', 'wpoptimizing' ), size_format( $reclaimable, 1 ) );
	} else {
		$db_state = 'bad';
		$db_word  = __( 'Action needed', 'wpoptimizing' );
		/* translators: %s: human-readable size (e.g. 2 MB). */
		$db_sub = sprintf( __( '%s recoverable', 'wpoptimizing' ), size_format( $reclaimable, 1 ) );
	}

	// ── Performance (autoload weight) ─────────────────────────────────────
	global $wpdb;
	$autoload_bytes = get_transient( 'wpoptimizing_overview_autoload_bytes' );
	if ( false === $autoload_bytes ) {
		$autoload_bytes = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		set_transient( 'wpoptimizing_overview_autoload_bytes', $autoload_bytes, 5 * MINUTE_IN_SECONDS );
	}
	$autoload_bytes = (int) $autoload_bytes;
	// Same line as the Autoload screen, the analyzer and the health score: healthy up to 800 KB.
	if ( $autoload_bytes <= 819200 ) {
		$perf_state = 'good';
		$perf_word  = __( 'Healthy', 'wpoptimizing' );
	} elseif ( $autoload_bytes < MB_IN_BYTES ) {
		$perf_state = 'warn';
		$perf_word  = __( 'Could improve', 'wpoptimizing' );
	} else {
		$perf_state = 'bad';
		$perf_word  = __( 'Heavy', 'wpoptimizing' );
	}

	// ── Cache ─────────────────────────────────────────────────────────────
	$provider = function_exists( 'wpoptimizing_object_cache_provider' )
		? wpoptimizing_object_cache_provider()
		: array(
			'active' => false,
			'ours'   => false,
			'name'   => '',
		);
	$oc_on    = ! empty( $provider['ours'] );
	$cache    = function_exists( 'wpoptimizing_cache_stats_read' ) ? wpoptimizing_cache_stats_read() : array(
		'has'   => false,
		'ratio' => 0,
	);
	if ( ! empty( $provider['active'] ) && ! $oc_on ) {
		// Another plugin owns the drop-in and the cache IS working. Saying "not enabled" here
		// is simply false, and the "Enable cache" button it used to offer goes nowhere: we
		// never overwrite a foreign drop-in.
		$cache_state = 'good';
		$cache_word  = __( 'Active', 'wpoptimizing' );
		$cache_value = __( 'Active', 'wpoptimizing' );
		/* translators: %s: the plugin that manages the object cache (e.g. LiteSpeed Cache). */
		$cache_sub = sprintf( __( 'Managed by %s', 'wpoptimizing' ), $provider['name'] );
	} elseif ( ! $oc_on ) {
		$cache_state = 'warn';
		$cache_word  = __( 'Not enabled', 'wpoptimizing' );
		$cache_value = '—';
		$cache_sub   = __( 'Object cache is off', 'wpoptimizing' );
	} else {
		$ratio       = (int) ( $cache['ratio'] ?? 0 );
		$cache_state = $ratio >= 80 ? 'good' : 'warn';
		$cache_word  = $ratio >= 80 ? __( 'Active', 'wpoptimizing' ) : __( 'Warming up', 'wpoptimizing' );
		$cache_value = $cache['has'] ? $ratio . '%' : __( 'Active', 'wpoptimizing' );
		$cache_sub   = $cache['has'] ? __( 'Hit rate', 'wpoptimizing' ) : __( 'Object cache on', 'wpoptimizing' );
	}

	$cards = array(
		array(
			// It measures the autoload weight, so it says Autoload — it was labelled
			// "Performance", which is the name of a different screen. Icons match the menu.
			'icon'   => 'database-view',
			'state'  => $perf_state,
			'label'  => __( 'Autoload', 'wpoptimizing' ),
			'value'  => size_format( max( 0, $autoload_bytes ), 0 ),
			'word'   => $perf_word,
			'sub'    => __( 'Autoload weight (target < 800 KB)', 'wpoptimizing' ),
			'href'   => add_query_arg( 'tab', 'autoload', $dash ),
			'action' => __( 'View autoload', 'wpoptimizing' ),
		),
		array(
			'icon'   => 'editor-unlink',
			'state'  => $db_state,
			'label'  => __( 'Database', 'wpoptimizing' ),
			'value'  => number_format_i18n( $orphans ),
			'word'   => $db_word,
			'sub'    => $db_sub,
			'href'   => add_query_arg( 'tab', 'orphans', $dash ),
			'action' => __( 'Review orphans', 'wpoptimizing' ),
		),
		array(
			'icon'   => 'cloud',
			'state'  => $cache_state,
			'label'  => __( 'Cache', 'wpoptimizing' ),
			'value'  => $cache_value,
			'word'   => $cache_word,
			'sub'    => $cache_sub,
			'href'   => add_query_arg( 'tab', 'cache', $dash ),
			'action' => ( $oc_on || ! empty( $provider['active'] ) ) ? __( 'Open cache', 'wpoptimizing' ) : __( 'Enable cache', 'wpoptimizing' ),
		),
	);

	$state_words = array(
		'good' => __( 'Good', 'wpoptimizing' ),
		'warn' => __( 'Warning', 'wpoptimizing' ),
		'bad'  => __( 'Critical', 'wpoptimizing' ),
	);

	echo '<h2 class="wpopt-overview-title">' . esc_html__( 'Site status', 'wpoptimizing' ) . '</h2>';
	echo '<div class="wpopt-overview" role="list">';
	foreach ( $cards as $c ) {
		printf(
			'<div class="wpopt-overview-card wpopt-state-%1$s" role="listitem">'
				. '<div class="wpopt-overview-top">'
					. '%2$s'
					. '<div class="wpopt-overview-body">'
						. '<span class="wpopt-overview-label">%3$s</span>'
						. '<span class="wpopt-overview-value">%4$s</span>'
						. '<span class="wpopt-overview-status"><span class="wpopt-state-dot" aria-hidden="true"></span><span class="screen-reader-text">%5$s: </span>%6$s</span>'
						. '<span class="wpopt-overview-sub">%7$s</span>'
					. '</div>'
				. '</div>'
				. '<a class="button wpopt-overview-action" href="%8$s">%9$s</a>'
			. '</div>',
			esc_attr( $c['state'] ),
			wpoptimizing_icon( $c['icon'], 'wpopt-overview-icon' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			esc_html( $c['label'] ),
			esc_html( $c['value'] ),
			esc_html( $state_words[ $c['state'] ] ),
			esc_html( $c['word'] ),
			esc_html( $c['sub'] ),
			esc_url( $c['href'] ),
			esc_html( $c['action'] )
		);
	}
	echo '</div>';
}

/**
 * The plugin page's own UI object for this request, with its view context set.
 *
 * @param bool $forced_settings Whether this is the Settings submenu page.
 * @return WPOPT_Admin_UI
 */
function wpoptimizing_page_ui_for( $forced_settings ) {
	$ui = new WPOPT_Admin_UI();
	if ( $forced_settings ) {
		$ui->set_view_context( 'settings', false );
	} else {
		$ui->set_view_context( null, true );
	}
	return $ui;
}

/**
 * Process every action of the plugin page BEFORE WordPress prints the admin header.
 *
 * They used to run inside the page callback, wpoptimizing_render_page(), which WordPress
 * calls after admin-header.php has already sent the sidebar and the top bar. Every one of
 * them ends in wp_safe_redirect() + exit, and a redirect needs headers that by then are long
 * gone: it fails silently and the exit cuts the page short. It only ever worked where
 * something held the output in a buffer large enough — the local install did, so nothing
 * showed. Reported on 2026-10-11 on wpoptimizing.com, a clean host: a blank screen after
 * finishing the welcome wizard, and again after "Smart deactivation" in the Autoload tab.
 * The action had run both times (the audit log has it); only the redirect had failed.
 *
 * load-{page} is the hook WordPress fires for a plugin page before any output, which is
 * where a Post/Redirect/Get handler belongs.
 *
 * @return void
 */
function wpoptimizing_process_page_actions() {
	if ( ! current_user_can( wpoptimizing_cap() ) || ! class_exists( 'WPOPT_Admin_UI' ) ) {
		return;
	}
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'wpoptimizing'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Page selector only; every action verifies its own nonce.

	$ui                              = wpoptimizing_page_ui_for( 'wpoptimizing-settings' === $page );
	$GLOBALS['wpoptimizing_page_ui'] = $ui;
	$ui->maybe_handle_post();

	wpoptimizing_handle_page_get_actions();
}

/**
 * The page's GET actions: reset the known-attachments list, and "Run analysis now" — which
 * is also how the welcome wizard applies its choices. Each ends in a redirect, so this must
 * run before any output (see wpoptimizing_process_page_actions()).
 *
 * @return void
 */
function wpoptimizing_handle_page_get_actions() {
	// 3) Handle the async “Run analysis now” action if present and nonce-valid.
	// This schedules a scan and shows the pending overlay.
	// Reset the "known/trusted attachments" allow-list (Media → Unused). Non-destructive.
	if (
		isset( $_GET['wpopt_reset_trusted'], $_GET['_wpopt_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpopt_nonce'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'wpoptimizing_reset_trusted'
		)
	) {
		delete_option( 'wpoptimizing_trusted_attachments' );
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_trust_reset',
				'msg'  => __( 'Known list reset. Run an analysis to re-evaluate those attachments.', 'wpoptimizing' ),
				'type' => 'success',
			),
			30
		);
		wp_safe_redirect( remove_query_arg( array( 'wpopt_reset_trusted', '_wpopt_nonce' ) ) );
		exit;
	}

	if (
		isset( $_GET['wpopt_run_async'], $_GET['_wpopt_nonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		&& wp_verify_nonce(
			sanitize_text_field( wp_unslash( $_GET['_wpopt_nonce'] ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'wpoptimizing_run_async'
		)
	) {
		// Scanning is available in free mode; no license check here.
		// Rate limit: max 5 manual scans per hour per user.
		if ( ! wpoptimizing_rate_allow( 'scan', 5, HOUR_IN_SECONDS ) ) {
			$clean_url = remove_query_arg( array( 'wpopt_run_async', '_wpopt_nonce' ), wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing' ) );
			wp_safe_redirect( $clean_url );
			exit;
		}
		// If this scan was launched from the welcome wizard (it always carries
		// wpopt_wizard_* params), the wizard has done its job — dismiss it NOW so it
		// cannot reappear while the async scan is still running. Otherwise first_run
		// is only cleared at the finalize stage, and returning to Settings before the
		// scan finishes would show the wizard again (the reported bug).
		if ( isset( $_GET['wpopt_wizard_img'] ) || isset( $_GET['wpopt_wizard_profile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			delete_option( 'wpoptimizing_first_run' );
		}
		// The first-run wizard can carry a chosen cleanup preset; apply it now (the
		// async nonce verified above already authorised this request).
		if ( isset( $_GET['wpopt_wizard_profile'] ) && class_exists( 'WPOPT_General_Cleanup' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$wiz_profile = sanitize_key( wp_unslash( $_GET['wpopt_wizard_profile'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $wiz_profile ) {
				( new WPOPT_General_Cleanup( wpoptimizing_cap() ) )->apply_profile( $wiz_profile );
				// Self-hosted Google Fonts travels with the profile; run the one-time
				// discovery + download here so the wizard sets it up end-to-end.
				$wiz_perf = WPOPT_General_Cleanup::profile_performance_map();
				if ( ! empty( $wiz_perf[ $wiz_profile ]['google_fonts'] ) && function_exists( 'wpoptimizing_gfonts_queue_prime' ) ) {
					wpoptimizing_gfonts_queue_prime();
				}
			}
		}
		// Independent advanced tweaks picked in the wizard (those not part of any
		// profile). Merge them on top of whatever the profile enabled; restricted to
		// the unprofiled set, so this param can never toggle anything else.
		if ( isset( $_GET['wpopt_wizard_tweaks'] ) && is_array( $_GET['wpopt_wizard_tweaks'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			&& class_exists( 'WPOPT_General_Cleanup' ) && function_exists( 'wpoptimizing_core_tweaks_sanitize' ) ) {
			$wiz_allowed = WPOPT_General_Cleanup::tweaks_not_in_any_profile();
			$wiz_picked  = array_intersect( array_map( 'sanitize_key', wp_unslash( $_GET['wpopt_wizard_tweaks'] ) ), $wiz_allowed ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! empty( $wiz_picked ) ) {
				update_option(
					'wpoptimizing_core_tweaks',
					wpoptimizing_core_tweaks_sanitize( array_merge( wpoptimizing_core_tweaks_enabled_ids(), $wiz_picked ) ),
					true
				);
			}
		}
		// The wizard offers the object cache and query deduplication, both on by
		// default; install whichever the user kept checked.
		$wiz_cache = isset( $_GET['wpopt_wizard_cache'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wpopt_wizard_cache'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		$wiz_dedup = isset( $_GET['wpopt_wizard_dedup'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['wpopt_wizard_dedup'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
		if ( $wiz_cache || $wiz_dedup ) {
			if ( ! class_exists( 'WPOPT_Admin_UI' ) && function_exists( 'wpoptimizing_load_engine' ) ) {
				wpoptimizing_load_engine();
			}
			if ( class_exists( 'WPOPT_Admin_UI' ) ) {
				$wiz_ui = new WPOPT_Admin_UI();
				if ( $wiz_cache ) {
					$wiz_ui->wizard_install_object_cache();
				}
				if ( $wiz_dedup ) {
					$wiz_ui->wizard_install_db_dropin();
				}
			}
		}
		// Image-optimization settings chosen in the wizard's Images step.
		if ( isset( $_GET['wpopt_wizard_img'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$wiz_iq = isset( $_GET['wpopt_wizard_img_quality'] ) ? absint( wp_unslash( $_GET['wpopt_wizard_img_quality'] ) ) : 82; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$wiz_iw = isset( $_GET['wpopt_wizard_img_maxw'] ) ? absint( wp_unslash( $_GET['wpopt_wizard_img_maxw'] ) ) : 2560; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$wiz_ih = isset( $_GET['wpopt_wizard_img_maxh'] ) ? absint( wp_unslash( $_GET['wpopt_wizard_img_maxh'] ) ) : 2560; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Merged over what is saved: the wizard only asks about some settings, and writing just
			// those dropped the rest (on-upload conversion, per-format quality, exclusions).
			$wiz_prev = get_option( 'wpoptimizing_image_settings', array() );
			update_option(
				'wpoptimizing_image_settings',
				array_merge(
					is_array( $wiz_prev ) ? $wiz_prev : array(),
					array(
						'quality'        => max( 40, min( 100, $wiz_iq ) ),
						'max_width'      => max( 0, min( 20000, $wiz_iw ) ),
						'max_height'     => max( 0, min( 20000, $wiz_ih ) ),
						'lossless'       => isset( $_GET['wpopt_wizard_img_lossless'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'strip_meta'     => 1,
						'resize_uploads' => isset( $_GET['wpopt_wizard_img_resize'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						'serve_nextgen'  => isset( $_GET['wpopt_wizard_img_serve'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					)
				),
				true
			);
		}


		// Heartbeat activity (default|slow|off) — safe partial update of cache settings.
		if ( isset( $_GET['wpopt_wizard_heartbeat'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$wiz_hb_val = sanitize_key( wp_unslash( $_GET['wpopt_wizard_heartbeat'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( in_array( $wiz_hb_val, array( 'default', 'slow', 'off' ), true ) ) {
				$wiz_cs = (array) get_option( 'wpoptimizing_cache_settings', array() );
				$wiz_cs = function_exists( 'wpoptimizing_heartbeat_with_preset' ) ? wpoptimizing_heartbeat_with_preset( $wiz_cs, $wiz_hb_val ) : array_merge( $wiz_cs, array( 'heartbeat' => $wiz_hb_val ) );
				update_option( 'wpoptimizing_cache_settings', $wiz_cs, false );
			}
		}


		// Periodic maintenance report: frequency + destination email.
		if ( isset( $_GET['wpopt_wizard_report_freq'] ) && function_exists( 'wpoptimizing_report_frequencies' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$wiz_rf = sanitize_key( wp_unslash( $_GET['wpopt_wizard_report_freq'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( isset( wpoptimizing_report_frequencies()[ $wiz_rf ] ) ) {
				update_option( WPOPT_REPORT_FREQ_OPTION, $wiz_rf, false );
			}
		}
		if ( isset( $_GET['wpopt_wizard_notif_email'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$wiz_emails_raw = sanitize_text_field( wp_unslash( $_GET['wpopt_wizard_notif_email'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$wiz_emails     = array();
			foreach ( preg_split( '/[\s,;]+/', $wiz_emails_raw ) as $wiz_em ) {
				$wiz_em = sanitize_email( (string) $wiz_em );
				if ( '' !== $wiz_em && is_email( $wiz_em ) ) {
					$wiz_emails[] = $wiz_em;
				}
			}
			update_option( 'wpoptimizing_notification_email', implode( ', ', $wiz_emails ), false );
		}

		// Import from another plugin (and, if asked, deactivate it): last, so what is imported
		// wins over the defaults of the wizard's other steps (see WPOPT_Admin_UI::wizard_import_from()).
		if ( isset( $_GET['wpopt_wizard_import'] ) && function_exists( 'wpoptimizing_importer_sources' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			$wiz_import_id = sanitize_key( wp_unslash( $_GET['wpopt_wizard_import'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' !== $wiz_import_id && isset( wpoptimizing_importer_sources()[ $wiz_import_id ] ) ) {
				if ( ! class_exists( 'WPOPT_Admin_UI' ) && function_exists( 'wpoptimizing_load_engine' ) ) {
					wpoptimizing_load_engine();
				}
				if ( class_exists( 'WPOPT_Admin_UI' ) ) {
					$wiz_imported = ( new WPOPT_Admin_UI() )->wizard_import_from( $wiz_import_id, isset( $_GET['wpopt_wizard_deactivate'] ) && current_user_can( 'activate_plugins' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
					if ( '' !== $wiz_imported['msg'] ) {
						set_transient(
							'wpoptimizing_flash',
							array(
								'code' => 'wpoptimizing_wizard_import',
								'msg'  => $wiz_imported['msg'],
								'type' => $wiz_imported['type'],
							),
							120
						);
					}
				}
			}
		}

		wpoptimizing_start_manual_scan();

		// Redirect to the same page (without action params) to prevent re‑execution on refresh.
		$clean_url = remove_query_arg(
			array( 'wpopt_run_async', '_wpopt_nonce' ),
			wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpoptimizing' )
		);
		// The wizard also opens on Settings, and going back to the referrer left the first
		// analysis running on Settings instead of Home, where its results are
		// (soporte.artprojectgroup.es, 26/10/2026).
		if ( isset( $_GET['wpopt_wizard_img'] ) || isset( $_GET['wpopt_wizard_profile'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified above.
			// The mark lets Home find the analysis even if this request installed the object
			// cache, which hides the flags written above (see wpoptimizing_render_page()).
			$clean_url = admin_url( 'admin.php?page=wpoptimizing&wpopt_scan_started=1' );
		}
		wp_safe_redirect( $clean_url );
		exit;
	}
}

function wpoptimizing_render_page() {
	// Capability gate for the entire screen.
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wpoptimizing' ) );
	}

	global $wpoptimizing_info;

	$page            = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'wpoptimizing'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$forced_settings = ( 'wpoptimizing-settings' === $page );
	$tab             = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( $forced_settings ? 'settings' : 'general' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$tab             = in_array( $tab, array( 'general', 'orphans', 'inactive', 'active', 'backups', 'settings' ), true ) ? $tab : 'general';
	if ( $forced_settings ) {
		$tab = 'settings';
	}
	$wrap_class = 'wrap wpoptimizing-admin';

	echo '<div class="' . esc_attr( $wrap_class ) . '"><h1>WPOptimizing</h1>';

	// Live region for screen readers (polite announcements).
	echo '<div id="wpoptimizing-aria-live" class="screen-reader-text" aria-live="polite" aria-atomic="true"></div>';

	// Short description of what this tool does. Keep it concise and actionable.
	echo '<p class="description">'
		. esc_html__(
			'Analyses your plugins, themes, database and files to speed up your site and find what was left behind. Use the menu on the left: Diagnose, Speed, Clean up, and Analysis results to review what the analysis found.',
			'wpoptimizing'
		)
		. '</p>';

	if ( wpoptimizing_is_white_label() ) {
		$wl_logo = wpoptimizing_brand_logo();
		if ( '' !== $wl_logo ) {
			echo '<div class="cabecera"><img src="' . esc_url( $wl_logo ) . '" class="imagen" alt="' . esc_attr( wpoptimizing_brand_name() ) . '" /></div>';
		}
	} else {
		echo '<div class="cabecera">';
		echo '<a href="' . esc_url( $wpoptimizing_info['plugin_url'] ) . '" title="' . esc_attr( $wpoptimizing_info['plugin'] ) . '" target="_blank">';
		echo '<img src="' . esc_url( plugins_url( 'assets/images/cabecera.jpg', WPOPTIMIZING_FILE ) ) . '" class="imagen" alt="' . esc_attr( $wpoptimizing_info['plugin'] ) . '" />';
		echo '</a>';
		echo '</div>';
	}

	echo '<div class="wpoptimizing-layout">';
	echo '<div class="wpoptimizing-content">';

	// 1) The actions were already processed on load-{page}, before WordPress printed anything
	// (see wpoptimizing_process_page_actions()). Reuse that instance: some actions leave their
	// result on it — a pending notice, the attribution assistant's suggestions — for the
	// render to paint. The fallback only runs if that hook never fired.
	$ui = isset( $GLOBALS['wpoptimizing_page_ui'] ) && $GLOBALS['wpoptimizing_page_ui'] instanceof WPOPT_Admin_UI ? $GLOBALS['wpoptimizing_page_ui'] : null;
	if ( null === $ui ) {
		$ui = wpoptimizing_page_ui_for( $forced_settings );
		$ui->maybe_handle_post();
	}

	// 2) Stash any flash (transient) set by a previous request so the UI can render it
	// INSIDE the section/tab where the action happened (clearer than a single notice
	// dumped at the top of the page). PRG pattern still prevents re-processing on refresh.
	$flash = get_transient( 'wpoptimizing_flash' );
	if ( is_array( $flash ) && ! empty( $flash['code'] ) && isset( $flash['msg'], $flash['type'] ) ) {
		$ui->set_pending_flash( $flash );
		delete_transient( 'wpoptimizing_flash' );

		// Announce to screen readers.
		wp_print_inline_script_tag(
			sprintf(
				'document.getElementById(%s).textContent=%s;',
				wp_json_encode( 'wpoptimizing-aria-live' ),
				wp_json_encode( wp_strip_all_tags( $flash['msg'] ) )
			)
		);
	}

	// 5) Show last analysis metadata if available. This is a fast, user-friendly status.
	$last_ts     = (int) get_option( 'wpoptimizing_last_scan', 0 );
	$last_reason = get_option( 'wpoptimizing_last_scan_reason', '' );

	$scan_btn_url = wp_nonce_url(
		add_query_arg( 'wpopt_run_async', '1', admin_url( 'admin.php?page=wpoptimizing' ) ),
		'wpoptimizing_run_async',
		'_wpopt_nonce'
	);
	// Action buttons first; the "last analysis" status line goes BELOW them.
	// Scanning and reporting are available in both free and premium. Only
	// destructive actions (delete/uninstall) require an active license.
	echo '<div class="wpopt-scan-actions">';
	printf(
		'<a href="%s" class="button button-primary">%s</a>',
		esc_url( $scan_btn_url ),
		esc_html__( 'Analyze now', 'wpoptimizing' )
	);
	// Report button — only shown after at least one scan.
	if ( $last_ts ) {
		$report_url = esc_url(
			add_query_arg(
				array(
					'page'                => 'wpoptimizing',
					'wpopt_impact_report' => '1',
					'_wpopt_ir_nonce'     => wp_create_nonce( 'wpopt_impact_report' ),
				),
				admin_url( 'admin.php' )
			)
		);
		printf(
			'<button class="button button-secondary" onclick="window.open(%s,\'wpopt_report\')">%s</button>',
			esc_attr( wp_json_encode( $report_url ) ),
			esc_html__( 'View report', 'wpoptimizing' )
		);
	}
	echo '</div>';
	echo '<p class="wpopt-scan-status">';
	if ( $last_ts ) {
		// translators: 1: human time difference (e.g., "5 mins"), 2: localized reason.
		$label = sprintf( __( 'Last analysis: %1$s ago (%2$s).', 'wpoptimizing' ), human_time_diff( $last_ts ), wpoptimizing_scan_reason_label( $last_reason ) );
		echo '<em>' . esc_html( $label ) . '</em>';
	} else {
		echo '<em>' . esc_html__( 'No analysis has been completed yet.', 'wpoptimizing' ) . '</em>';
	}
	echo '</p>';

	// A database stage whose queries the server cut short kept its previous results instead of
	// writing a list with whole sections missing (wpoptimizing_db_failed()). Say so where the
	// date of the analysis is, on every screen, so nobody reads old lists as today's.
	$wpopt_partial = function_exists( 'wpoptimizing_scan_partial_get' ) ? wpoptimizing_scan_partial_get() : array();
	if ( ! empty( $wpopt_partial ) ) {
		$wpopt_failed = 0;
		foreach ( $wpopt_partial as $wpopt_stage ) {
			$wpopt_failed += count( (array) ( $wpopt_stage['failures'] ?? array() ) );
		}
		echo '<div class="notice notice-warning inline wpopt-notice"><p>' . esc_html(
			sprintf(
				/* translators: %s: number of database queries that did not finish. */
				_n(
					'The last analysis could not read part of the database: %s query did not finish (it took too long or the server returned an error). The database lists keep the results of the previous analysis; analyse again at a quieter time.',
					'The last analysis could not read part of the database: %s queries did not finish (they took too long or the server returned an error). The database lists keep the results of the previous analysis; analyse again at a quieter time.',
					$wpopt_failed,
					'wpoptimizing'
				),
				number_format_i18n( $wpopt_failed )
			)
		) . '</p></div>';
	}

	// Overview status cards — the at-a-glance dashboard, only on the main landing
	// (general tab), not on the standalone Settings page.
	// Shown on every in-app tab (General, Orphans, Inactive, Active, Backups…) so
	// the "Site status" header is consistent across the screen; only the standalone
	// Settings page omits it.
	// Only on Home: on every other screen they pushed the content (and, on mobile, the menu)
	// several screens down, and repeated what the sidebar health badge already says.
	$wpopt_req_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
	if ( ! $forced_settings && 'home' === $wpopt_req_tab ) {
		wpoptimizing_render_overview_cards();
	}

	// 6a) First-run wizard — shown when plugin is freshly activated and no scan
	// done yet. Available in both free and premium (onboarding is not gated).
	$show_wizard = (bool) get_option( 'wpoptimizing_first_run' );

	// Data-sharing reminder — shown on every WPOptimizing page (never site-wide)
	// when sharing is OFF, so almost every install ends up contributing to (and
	// receiving) the shared dataset. Hidden while the first-run wizard is up
	// (which already carries the consent checkbox). Informative, one-click.
	if ( ! $show_wizard && function_exists( 'wpoptimizing_has_share_consent' ) && ! wpoptimizing_has_share_consent() ) {
		$share_nonce = wp_create_nonce( 'wpoptimizing_share_consent' );
		$share_ajax  = admin_url( 'admin-ajax.php' );
		echo '<div class="wpopt-share-banner">';
		echo '<span class="wpopt-share-banner-icon" aria-hidden="true">' . wpoptimizing_icon( 'rss' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
		echo '<div class="wpopt-share-banner-body">';
		echo '<strong>' . esc_html__( 'Data sharing is off.', 'wpoptimizing' ) . '</strong> ';
		echo esc_html__( 'WPOptimizing is using only your local attribution — it is not receiving the shared attribution dataset that improves accuracy. Turn on sharing (opt-in and reciprocal) to give back and receive the community dataset.', 'wpoptimizing' );
		echo '</div>';
		echo '<button type="button" id="wpopt-share-enable" class="button button-primary">' . esc_html__( 'Enable sharing', 'wpoptimizing' ) . '</button>';
		echo '</div>';
		wp_print_inline_script_tag(
			sprintf(
				'(function(){var b=document.getElementById("wpopt-share-enable");if(!b){return;}b.addEventListener("click",function(){b.disabled=true;var d=new URLSearchParams();d.set("action","wpoptimizing_set_share_consent");d.set("nonce",%1$s);d.set("enabled","1");d.set("source","banner");fetch(%2$s,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:d.toString()}).then(function(){window.location.reload();}).catch(function(){b.disabled=false;});});}());',
				wp_json_encode( $share_nonce ),
				wp_json_encode( $share_ajax )
			)
		);
	}

	if ( $show_wizard ) {
		$dismiss_nonce = wp_create_nonce( 'wpoptimizing_dismiss_wizard' );
		$consent_nonce = wp_create_nonce( 'wpoptimizing_share_consent' );
		$ajax_url_w    = admin_url( 'admin-ajax.php' );
		/*
		 * La leyenda sale del vocabulario único (resolvers/confidence-levels.php). Escrita a
		 * mano aquí le faltaba `manual`, que es la MÁXIMA autoridad —una regla puesta a mano
		 * gana a todo lo automático—, así que quien aprendía el sistema por este asistente no
		 * sabía que existe. Y explicaba «Possible» como «el conjunto de la comunidad con
		 * varias confirmaciones» mientras la leyenda de la tabla lo explicaba como «lo
		 * encontró el escáner de código».
		 */
		$conf_levels = array();
		foreach ( array_keys( wpoptimizing_confidence_levels() ) as $conf_key ) {
			$conf_levels[] = array(
				'cls'   => $conf_key,
				'label' => wpoptimizing_confidence_label( $conf_key ),
				'tip'   => wpoptimizing_confidence_description( $conf_key ),
			);
		}
		/*
		 * A FULL-SCREEN DIALOG, not a card on top of the page (16/10/2026): rendered inline it sat
		 * above whichever tab was open, mixed with that tab's own options and save buttons.
		 */
		echo '<div id="wpopt-wizard-overlay" class="wpopt-wizard-overlay" role="dialog" aria-modal="true" aria-labelledby="wpopt-wizard-heading">';
		echo '<div id="wpopt-wizard" class="wpopt-wizard wpopt-wizard--stepped" data-step="1">';
		// Dismiss button.
		echo '<button type="button" id="wpopt-wizard-dismiss" class="wpopt-wizard-dismiss" aria-label="' . esc_attr__( 'Dismiss', 'wpoptimizing' ) . '">'
			. wpoptimizing_icon( 'no-alt' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.

		// Another cache or performance plugin left its settings here: the second step offers to
		// bring them over (and to deactivate it). Without one, the step does not exist.
		$wiz_import = function_exists( 'wpoptimizing_importer_importable' ) ? wpoptimizing_importer_importable() : array();

		// Step indicator.
		$wiz_steps = array(
			__( 'Welcome', 'wpoptimizing' ),
		);
		if ( $wiz_import ) {
			$wiz_steps[] = __( 'Import', 'wpoptimizing' );
		}
		$wiz_steps = array_merge(
			$wiz_steps,
			array(
				__( 'Cleanup', 'wpoptimizing' ),
				__( 'Performance', 'wpoptimizing' ),
				__( 'Images', 'wpoptimizing' ),
				__( 'Ready', 'wpoptimizing' ),
			)
		);
		echo '<ol class="wpopt-wizard-stepper">';
		foreach ( $wiz_steps as $wiz_i => $wiz_label ) {
			printf(
				'<li class="wpopt-wizard-dot%s" data-step="%d"><span class="wpopt-wizard-dot-num">%d</span><span class="wpopt-wizard-dot-label">%s</span></li>',
				0 === $wiz_i ? ' is-active' : '',
				(int) ( $wiz_i + 1 ),
				(int) ( $wiz_i + 1 ),
				esc_html( $wiz_label )
			);
		}
		echo '</ol>';

		// ── Step 1 — Welcome ─────────────────────────────────────────────
		// Steps are numbered as they are printed, so a build without one (Lite has no License
		// step) still counts 1..N, which is what the stepper and its script expect.
		$wiz_n = 1;
		echo '<div class="wpopt-wizard-step is-active" data-step="1">';
		echo '<h2 id="wpopt-wizard-heading" class="wpopt-wizard-title">' . esc_html__( 'Welcome to WPOptimizing', 'wpoptimizing' ) . '</h2>';
		echo '<p class="wpopt-wizard-lead">'
			. esc_html__( 'All the WPO your WordPress needs in a single plugin, with the only cleanup that knows who owns each leftover before you delete anything.', 'wpoptimizing' ) . '</p>';

		// 3 feature cards.
		echo '<div class="wpopt-wizard-features">';
		$home_speed_desc = __( 'See where the time goes and fix it: object cache, CSS and JavaScript, images and delivery, each one switched on separately.', 'wpoptimizing' );
		$features = array(
			array(
				'icon'  => 'performance',
				'title' => __( 'Measure and speed up', 'wpoptimizing' ),
				'desc'  => $home_speed_desc,
			),
			array(
				'icon'  => 'search',
				'title' => __( 'Smart attribution', 'wpoptimizing' ),
				'desc'  => __( 'Every option, post meta, cron job and file is traced back to its owning plugin or theme using backtrace analysis, callback reflection and a community dataset.', 'wpoptimizing' ),
			),
			array(
				'icon'  => 'trash',
				'title' => __( 'Safe cleanup', 'wpoptimizing' ),
				'desc'  => __( 'Attributed deletions, media cleanups and the general cleanup create a backup first, restorable from the Backups tab. Orphaned records (from uninstalled plugins) are separated from inactive ones so you always know the risk.', 'wpoptimizing' ),
			),
			array(
				'icon'  => 'chart-bar',
				// The count here is pinned to the real badge map by ConfidenceLevelsTest, so
				// adding or removing a level cannot leave this claim (or the readme) stale.
				'title' => __( '7-level confidence', 'wpoptimizing' ),
				'desc'  => __( 'Each attributed record gets a confidence badge, from the Manual rules you set yourself down to Undetermined. Only Manual, Sure, Likely and Possible items are considered fully safe for automatic cleanup.', 'wpoptimizing' ),
			),
		);
		foreach ( $features as $f ) {
			echo '<div class="wpopt-wizard-feature">';
			echo wpoptimizing_icon( $f['icon'], 'wpopt-wizard-feature-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			echo '<strong class="wpopt-wizard-feature-title">' . esc_html( $f['title'] ) . '</strong>';
			echo '<p class="wpopt-wizard-feature-desc">' . esc_html( $f['desc'] ) . '</p>';
			echo '</div>';
		}
		echo '</div>';

		// Confidence badges legend — same hover tooltip (title + cursor:help) as the
		// rest of the admin, instead of a separate text-below readout.
		echo '<div class="wpopt-conf-block">';
		echo '<p class="wpopt-conf-intro">'
			. esc_html__( 'Confidence levels — hover to learn more:', 'wpoptimizing' ) . '</p>';
		echo '<div class="wpopt-conf-legend" id="wpopt-wizard-badges">';
		foreach ( $conf_levels as $c ) {
			printf(
				'<span class="wpopt-conf-badge wpopt-conf-%s" title="%s">%s</span>',
				esc_attr( $c['cls'] ),
				esc_attr( $c['tip'] ),
				esc_html( $c['label'] )
			);
		}
		echo '</div>';
		echo '</div>';

		// Data-sharing consent — shown prominently so the choice is informed. The box starts
		// unticked: consent is only consent when the person gives it (wordpress.org guideline 7
		// and the GDPR), so sharing stays off unless they tick it. Unless they already gave it:
		// the wizard comes back on every reactivation and with «Replay welcome wizard», and
		// finishing it saved the unticked box, switching off a site that was sharing.
		echo '<div class="wpopt-wizard-panel">';
		echo '<label class="wpopt-toggle wpopt-toggle--block">';
		echo '<input type="checkbox" id="wpopt-wizard-consent" class="wpopt-toggle-input"' . checked( function_exists( 'wpoptimizing_has_share_consent' ) && wpoptimizing_has_share_consent(), true, false ) . ' />';
		echo '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>';
		echo '<span class="wpopt-toggle-label">';
		echo '<strong>' . esc_html__( 'Help improve attribution accuracy (recommended).', 'wpoptimizing' ) . '</strong> ';
		echo esc_html__( 'Share attribution metadata with the WPOptimizing shared dataset and receive the community dataset in return. Only the names of options, meta keys, tables and files, their sizes and how often they are seen are sent, with your site’s domain — never option values, post or user content, or any record contents. It is reciprocal (off = the plugin keeps working with your local data only, but does not exchange the shared dataset) and you can change it anytime in Settings.', 'wpoptimizing' );
		echo '</span></label>';
		echo '</div>';

		echo '</div>'; // step 1 (Welcome)

		// ── Import from another plugin (only when one left settings here) ────────────
		if ( $wiz_import ) {
			echo '<div class="wpopt-wizard-step" data-step="' . (int) ++$wiz_n . '">';
			echo '<h3 class="wpopt-wizard-step-title">' . esc_html__( 'Import your current settings', 'wpoptimizing' ) . '</h3>';
			echo '<p class="wpopt-wizard-step-lead">' . esc_html__( 'Another cache or performance plugin left its settings on this site. WPOptimizing can bring over what has an equivalent here, so you do not have to set it up again. The import is applied when the analysis starts, after the choices of the next steps, so what you bring over wins over their defaults. Passwords and API keys are never copied, the page cache and the object cache are never switched on by an import, and you can undo it in Settings.', 'wpoptimizing' ) . '</p>';
			$wiz_first = true;
			foreach ( $wiz_import as $wiz_src_id => $wiz_src ) {
				$wiz_off = wpoptimizing_importer_deactivation( $wiz_src_id );
				echo '<div class="wpopt-wizard-panel">';
				echo '<label><input type="radio" name="wpopt-wizard-import" value="' . esc_attr( $wiz_src_id ) . '"' . checked( $wiz_first, true, false ) . ' /> <strong>' . esc_html(
					sprintf(
						/* translators: %s: plugin name. */
						__( 'Import from %s', 'wpoptimizing' ),
						$wiz_src['label']
					)
				) . '</strong></label>';
				echo wpoptimizing_importer_summary_html( $wiz_src ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
				if ( in_array( $wiz_off['scope'], array( 'site', 'network' ), true ) ) {
					echo '<p><label><input type="checkbox" class="wpopt-wizard-deact" data-source="' . esc_attr( $wiz_src_id ) . '" /> ' . esc_html(
						sprintf(
							'network' === $wiz_off['scope']
								/* translators: %s: plugin name. */
								? __( 'Deactivate %s on the whole network after importing (it is not deleted)', 'wpoptimizing' )
								/* translators: %s: plugin name. */
								: __( 'Deactivate %s after importing (it is not deleted)', 'wpoptimizing' ),
							$wiz_src['label']
						)
					) . '</label></p>';
				} elseif ( 'denied' === $wiz_off['scope'] ) {
					echo '<p class="description">' . esc_html(
						sprintf(
							/* translators: %s: plugin name. */
							__( '%s is active for the whole network: only a network administrator can deactivate it.', 'wpoptimizing' ),
							$wiz_src['label']
						)
					) . '</p>';
				}
				echo '</div>';
				$wiz_first = false;
			}
			echo '<div class="wpopt-wizard-panel"><label><input type="radio" name="wpopt-wizard-import" value="" /> ' . esc_html__( 'Do not import anything', 'wpoptimizing' ) . '</label></div>';
			echo '</div>'; // step (Import)
		}


		// ── Step 3 — Cleanup ─────────────────────────────────────────────
		echo '<div class="wpopt-wizard-step" data-step="' . (int) ++$wiz_n . '">';
		echo '<h3 class="wpopt-wizard-step-title">' . esc_html__( 'Database cleanup', 'wpoptimizing' ) . '</h3>';
		echo '<p class="wpopt-wizard-step-lead">' . esc_html__( 'Choose how thorough the automatic cleanup should be. You can fine-tune everything later in Settings.', 'wpoptimizing' ) . '</p>';
		// Cleanup preset chooser — applied when the first analysis starts.
		if ( class_exists( 'WPOPT_General_Cleanup' ) ) {
			$wiz_profiles    = WPOPT_General_Cleanup::cleanup_profiles();
			$wiz_recommended = WPOPT_General_Cleanup::recommend_profile( WPOPT_General_Cleanup::detect_site_signals() );
			echo '<div class="wpopt-wizard-panel">';
			echo '<label for="wpopt-wizard-preset" class="wpopt-wizard-panel-label">' . esc_html__( 'Cleanup preset', 'wpoptimizing' ) . '</label>';
			echo '<select id="wpopt-wizard-preset" class="wpopt-select2 wpopt-field-std">';
			foreach ( $wiz_profiles as $wiz_pk => $wiz_pp ) {
				$wiz_opt_label = ( $wiz_pk === $wiz_recommended )
					/* translators: %s: cleanup profile label. */
					? sprintf( __( '%s — recommended for your site', 'wpoptimizing' ), $wiz_pp['label'] )
					: $wiz_pp['label'];
				echo '<option value="' . esc_attr( $wiz_pk ) . '"' . selected( $wiz_pk, $wiz_recommended, false ) . '>' . esc_html( $wiz_opt_label ) . '</option>';
			}
			echo '</select>';
			echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Pre-selected based on the plugins we detected on your site. Applied when you start the analysis below; you can change it anytime in Settings.', 'wpoptimizing' ) . '</p>';
			echo '</div>';
		}

		// Ongoing maintenance: schedule automatic cleanup by DB growth + the periodic
		// email report. Both off by default; applied when the analysis starts.
		echo '<div class="wpopt-wizard-panel">';
		echo '<span class="wpopt-wizard-panel-label">' . esc_html__( 'Ongoing maintenance (optional)', 'wpoptimizing' ) . '</span>';
		if ( function_exists( 'wpoptimizing_report_frequencies' ) ) {
			echo '<p class="wpopt-wizard-inline-field"><label for="wpopt-wizard-report-freq">' . esc_html__( 'Email maintenance report', 'wpoptimizing' ) . '</label> ';
			echo '<select id="wpopt-wizard-report-freq" class="wpopt-select2 wpopt-field-std">';
			foreach ( wpoptimizing_report_frequencies() as $rf_key => $rf_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $rf_key ), selected( 'off', $rf_key, false ), esc_html( $rf_label ) );
			}
			echo '</select></p>';
			echo '<p class="wpopt-wizard-inline-field"><label for="wpopt-wizard-notif-email">' . esc_html__( 'Send report to', 'wpoptimizing' ) . '</label> ';
			echo '<input type="text" id="wpopt-wizard-notif-email" class="regular-text" value="" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" /></p>';
			echo '<p class="description wpopt-desc-tight">' . esc_html__( 'For agencies: route the report to the maintainer instead of the site owner. Separate several addresses with commas; leave empty to use the site admin email.', 'wpoptimizing' ) . '</p>';
		}
		echo '</div>';

		echo '</div>'; // step 3 (Cleanup)

		// ── Step 4 — Performance ─────────────────────────────────────────
		echo '<div class="wpopt-wizard-step" data-step="' . (int) ++$wiz_n . '">';
		echo '<h3 class="wpopt-wizard-step-title">' . esc_html__( 'Performance', 'wpoptimizing' ) . '</h3>';
		echo '<p class="wpopt-wizard-step-lead">' . esc_html__( 'Safe, reversible speed-ups. Sensible defaults are pre-selected; uncheck anything you would rather skip.', 'wpoptimizing' ) . '</p>';
		// Object cache opt-in — on by default (native), installed when the first
		// analysis starts. Only offered when no drop-in is present yet.
		if ( ! defined( 'WPOPT_DROPIN_DEST' ) || ! file_exists( WPOPT_DROPIN_DEST ) ) {
			echo '<div class="wpopt-wizard-panel">';
			echo '<label class="wpopt-toggle wpopt-toggle--block">';
			echo '<input type="checkbox" id="wpopt-wizard-cache" class="wpopt-toggle-input" checked />';
			echo '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>';
			echo '<span class="wpopt-toggle-label"><strong>' . esc_html__( 'Enable the object cache (recommended).', 'wpoptimizing' ) . '</strong> '
				. esc_html__( 'Speeds up the admin and the front end with a safe persistent object cache — no full-page caching, so it never serves stale pages. You can disable it anytime in Speed › Cache.', 'wpoptimizing' )
				. '</span></label>';
			echo '</div>';
		}

		// Query-deduplication opt-in — on by default, installed when the first
		// analysis starts. Only offered when no db.php drop-in is present yet.
		if ( ! defined( 'WPOPT_DB_DROPIN_DEST' ) || ! file_exists( WPOPT_DB_DROPIN_DEST ) ) {
			echo '<div class="wpopt-wizard-panel">';
			echo '<label class="wpopt-toggle wpopt-toggle--block">';
			echo '<input type="checkbox" id="wpopt-wizard-dedup" class="wpopt-toggle-input" checked />';
			echo '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>';
			echo '<span class="wpopt-toggle-label"><strong>' . esc_html__( 'Enable query deduplication (recommended).', 'wpoptimizing' ) . '</strong> '
				. esc_html__( 'Stops the same database read query running twice within a request. Safe and reversible — any write clears it instantly, so results are never stale. You can disable it anytime in Speed › Cache.', 'wpoptimizing' )
				. '</span></label>';
			echo '</div>';
		}

		// Self-hosted Google Fonts + Heartbeat control — safe, reversible front-end
		// speed-ups. Google Fonts on by default (privacy + one less third-party host);
		// the localize step runs when the analysis starts.
		echo '<div class="wpopt-wizard-panel">';
		echo '<p class="wpopt-wizard-inline-field"><label for="wpopt-wizard-heartbeat">' . esc_html__( 'Heartbeat activity', 'wpoptimizing' ) . '</label> ';
		echo '<select id="wpopt-wizard-heartbeat" class="wpopt-select2 wpopt-field-std">';
		$wiz_hb = array(
			'default' => __( 'Default (leave as-is)', 'wpoptimizing' ),
			'slow'    => __( 'Slow it down (recommended)', 'wpoptimizing' ),
			'off'     => __( 'Turn it off outside the editor', 'wpoptimizing' ),
		);
		foreach ( $wiz_hb as $hb_k => $hb_l ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $hb_k ), selected( 'slow', $hb_k, false ), esc_html( $hb_l ) );
		}
		echo '</select></p>';
		echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Heartbeat is the WordPress admin auto-save/notification ping. Slowing it down cuts admin CPU with no visible downside; you can change it in Diagnose › Performance › Server.', 'wpoptimizing' ) . '</p>';
		echo '</div>';


		// Advanced core tweaks — any tweak NOT enabled by a profile is offered here
		// as an independent opt-in (off by default), so the wizard covers everything.
		// Currently the "advanced" tier (disable feeds / sitemap), which change site
		// behaviour and so are never part of a preset.
		if ( class_exists( 'WPOPT_General_Cleanup' ) && function_exists( 'wpoptimizing_core_tweaks_registry' ) ) {
			$wiz_adv = WPOPT_General_Cleanup::tweaks_not_in_any_profile();
			if ( ! empty( $wiz_adv ) ) {
				$wiz_reg = wpoptimizing_core_tweaks_offered();
				echo '<div class="wpopt-wizard-panel">';
				echo '<span class="wpopt-wizard-panel-label">' . esc_html__( 'Advanced (optional)', 'wpoptimizing' ) . '</span>';
				echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Not part of any preset because they change how the site behaves. Off by default — enable only if they apply to you.', 'wpoptimizing' ) . '</p>';
				foreach ( $wiz_adv as $wiz_tid ) {
					if ( empty( $wiz_reg[ $wiz_tid ] ) ) {
						continue;
					}
					echo '<label class="wpopt-toggle wpopt-toggle--block">';
					echo '<input type="checkbox" class="wpopt-toggle-input wpopt-wizard-tweak" data-wpopt-tweak="' . esc_attr( $wiz_tid ) . '" />';
					echo '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>';
					echo '<span class="wpopt-toggle-label"><strong>' . esc_html( $wiz_reg[ $wiz_tid ]['label'] ) . '</strong> ' . esc_html( $wiz_reg[ $wiz_tid ]['desc'] ) . '</span></label>';
				}
				echo '</div>';
			}
		}

		echo '</div>'; // step 4 (Performance)

		// ── Step 5 — Images ──────────────────────────────────────────────
		echo '<div class="wpopt-wizard-step" data-step="' . (int) ++$wiz_n . '">';
		echo '<h3 class="wpopt-wizard-step-title">' . esc_html__( 'Image optimization', 'wpoptimizing' ) . '</h3>';
		echo '<p class="wpopt-wizard-step-lead">' . esc_html__( 'Shrink images and serve modern formats. These feed the optimizer in the Media tab and can be changed anytime.', 'wpoptimizing' ) . '</p>';
		$wiz_img = function_exists( 'wpoptimizing_image_settings' )
			? wpoptimizing_image_settings()
			: array(
				'quality'        => 82,
				'max_width'      => 2560,
				'max_height'     => 2560,
				'lossless'       => false,
				'resize_uploads' => false,
				'serve_nextgen'  => true,
			);
		// Serve next-gen on the front-end (default on).
		echo '<div class="wpopt-wizard-panel">';
		echo '<label class="wpopt-toggle wpopt-toggle--block">';
		echo '<input type="checkbox" id="wpopt-wizard-img-serve" class="wpopt-toggle-input"' . checked( ! empty( $wiz_img['serve_nextgen'] ), true, false ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe literal.
		echo '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>';
		// En Lite las copias no se generan en bloque desde Optimización de imágenes.
		$serve_hint = __( 'Delivers lighter next-gen images, for every image that has a WebP or AVIF copy, with an automatic fallback to the original.', 'wpoptimizing' );
		echo '<span class="wpopt-toggle-label"><strong>' . esc_html__( 'Serve WebP/AVIF on the front-end (recommended).', 'wpoptimizing' ) . '</strong> '
			. esc_html( $serve_hint )
			. '</span></label>';
		echo '</div>';
		// Resize on upload (default off).
		echo '<div class="wpopt-wizard-panel">';
		echo '<label class="wpopt-toggle wpopt-toggle--block">';
		echo '<input type="checkbox" id="wpopt-wizard-img-resize" class="wpopt-toggle-input"' . checked( ! empty( $wiz_img['resize_uploads'] ), true, false ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- checked() returns a safe literal.
		echo '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>';
		$wiz_resize_title = __( 'Scale down oversized uploads.', 'wpoptimizing' );
		$wiz_resize_hint  = __( 'Uploads above the max size are scaled down; the full-size original is preserved.', 'wpoptimizing' );
		echo '<span class="wpopt-toggle-label"><strong>' . esc_html( $wiz_resize_title ) . '</strong> '
			. esc_html( $wiz_resize_hint )
			. '</span></label>';
		echo '</div>';
		// Quality + max dimensions.
		echo '<div class="wpopt-wizard-panel">';
		$wiz_size_label = __( 'Max size', 'wpoptimizing' );
		$wiz_size_hint  = __( '0 on an axis disables resizing.', 'wpoptimizing' );
		echo '<label for="wpopt-wizard-img-maxw" class="wpopt-wizard-panel-label">' . esc_html( $wiz_size_label ) . '</label>';
		printf( '<input type="number" id="wpopt-wizard-img-maxw" min="0" max="20000" step="10" value="%d" class="small-text" aria-label="%s"> &times; ', (int) $wiz_img['max_width'], esc_attr__( 'Max width in pixels', 'wpoptimizing' ) );
		printf( '<input type="number" id="wpopt-wizard-img-maxh" min="0" max="20000" step="10" value="%d" class="small-text" aria-label="%s">', (int) $wiz_img['max_height'], esc_attr__( 'Max height in pixels', 'wpoptimizing' ) );
		echo '<p class="description wpopt-desc-tight">' . esc_html( $wiz_size_hint ) . '</p>';
		echo '</div>';
		echo '</div>'; // step 5 (Images)

		// ── Step 6 — Ready ───────────────────────────────────────────────
		echo '<div class="wpopt-wizard-step" data-step="' . (int) ++$wiz_n . '">';
		echo '<h3 class="wpopt-wizard-step-title">' . esc_html__( 'All set', 'wpoptimizing' ) . '</h3>';
		echo '<p class="wpopt-wizard-step-lead">' . esc_html__( 'Start your first analysis. Your choices are saved when it begins, and everything stays adjustable in Settings.', 'wpoptimizing' ) . '</p>';
		// CTA.
		echo '<div class="wpopt-wizard-cta-row">';
		printf(
			'<a href="%s" id="wpopt-wizard-cta" class="button button-primary button-hero wpopt-wizard-cta">%s</a>',
			esc_url( $scan_btn_url ),
			esc_html__( 'Apply these settings and analyze', 'wpoptimizing' )
		);
		echo '<a href="#" id="wpopt-wizard-skip" class="wpopt-wizard-skip">'
			. esc_html__( 'Set up later', 'wpoptimizing' ) . '</a>';
		echo '</div>';

		// Clarify that the chosen profile and options above are persisted at the
		// moment the analysis is launched (the CTA carries them to the run handler,
		// which saves the profile and installs the cache/dedup drop-ins).
		echo '<p class="description wpopt-wizard-note">'
			. esc_html__( 'Your selection is saved and the options you ticked (persistent object cache, query deduplication) are switched on when the analysis starts. You can switch them off again in Speed › Cache.', 'wpoptimizing' ) . '</p>';
		echo '</div>'; // step 6 (Ready)

		// Step navigation (Back / Next). The final step shows the CTA above instead of Next.
		echo '<div class="wpopt-wizard-nav">';
		echo '<button type="button" id="wpopt-wizard-back" class="button button-secondary wpopt-wizard-back">' . esc_html__( 'Back', 'wpoptimizing' ) . '</button>';
		echo '<button type="button" id="wpopt-wizard-next" class="button button-primary wpopt-wizard-next">' . esc_html__( 'Next', 'wpoptimizing' ) . '</button>';
		echo '</div>';

		echo '</div>'; // #wpopt-wizard
		echo '</div>'; // #wpopt-wizard-overlay

		// Wizard JS: dismiss + badge tooltips.
		wp_print_inline_script_tag(
			sprintf(
				'
(function(){
	var ajax=%1$s, nonce=%2$s, cNonce=%3$s;
	var consent=document.getElementById("wpopt-wizard-consent");
	function saveConsent(enabled, beacon){
		var d=new URLSearchParams();
		d.set("action","wpoptimizing_set_share_consent");
		d.set("nonce",cNonce);
		d.set("enabled",enabled?"1":"0");
		d.set("source","wizard");
		if(beacon&&navigator.sendBeacon){navigator.sendBeacon(ajax,d);return;}
		fetch(ajax,{method:"POST",credentials:"same-origin",keepalive:true,
			headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},
			body:d.toString()}).catch(function(){});
	}
	if(consent){consent.addEventListener("change",function(){saveConsent(consent.checked,false);});}
	var cta=document.getElementById("wpopt-wizard-cta");
	if(cta){cta.addEventListener("click",function(e){
		var ps=document.getElementById("wpopt-wizard-preset");
		var cc=document.getElementById("wpopt-wizard-cache");
		var dd=document.getElementById("wpopt-wizard-dedup");
		var sa=document.getElementById("wpopt-wizard-assets");
		var saa=document.getElementById("wpopt-wizard-assets-auto");
		var extra=[];
		if(ps&&ps.value){extra.push("wpopt_wizard_profile="+encodeURIComponent(ps.value));}
		if(cc&&cc.checked){extra.push("wpopt_wizard_cache=1");}
		if(dd&&dd.checked){extra.push("wpopt_wizard_dedup=1");}
		if(sa&&sa.checked){extra.push("wpopt_wizard_assets=1");}
		if(saa&&saa.checked){extra.push("wpopt_wizard_assets_auto=1");}
		var gf=document.getElementById("wpopt-wizard-gfonts");
		if(gf&&gf.checked){extra.push("wpopt_wizard_gfonts=1");}
		var hb=document.getElementById("wpopt-wizard-heartbeat");
		if(hb&&hb.value){extra.push("wpopt_wizard_heartbeat="+encodeURIComponent(hb.value));}
		var ac=document.getElementById("wpopt-wizard-autoclean");
		if(ac&&ac.checked){
			extra.push("wpopt_wizard_autoclean=1");
			var acmb=document.getElementById("wpopt-wizard-autoclean-mb");
			if(acmb&&acmb.value){extra.push("wpopt_wizard_autoclean_mb="+encodeURIComponent(acmb.value));}
		}
		var rf=document.getElementById("wpopt-wizard-report-freq");
		if(rf&&rf.value){extra.push("wpopt_wizard_report_freq="+encodeURIComponent(rf.value));}
		var ne=document.getElementById("wpopt-wizard-notif-email");
		if(ne&&ne.value){extra.push("wpopt_wizard_notif_email="+encodeURIComponent(ne.value));}
		var tw=document.querySelectorAll(".wpopt-wizard-tweak");
		for(var ti=0;ti<tw.length;ti++){if(tw[ti].checked){extra.push("wpopt_wizard_tweaks[]="+encodeURIComponent(tw[ti].getAttribute("data-wpopt-tweak")));}}
		var imp=document.querySelector("input[name=\"wpopt-wizard-import\"]:checked");
		if(imp&&imp.value){extra.push("wpopt_wizard_import="+encodeURIComponent(imp.value));var dct=document.querySelector(".wpopt-wizard-deact[data-source=\""+imp.value+"\"]");if(dct&&dct.checked){extra.push("wpopt_wizard_deactivate=1");}}
		var iqv=document.getElementById("wpopt-wizard-img-quality");
		var iwv=document.getElementById("wpopt-wizard-img-maxw");
		var ihv=document.getElementById("wpopt-wizard-img-maxh");
		var irv=document.getElementById("wpopt-wizard-img-resize");
		var ilv=document.getElementById("wpopt-wizard-img-lossless");
		var isv=document.getElementById("wpopt-wizard-img-serve");
		extra.push("wpopt_wizard_img=1");
		if(iqv&&iqv.value){extra.push("wpopt_wizard_img_quality="+encodeURIComponent(iqv.value));}
		if(iwv&&iwv.value!==""){extra.push("wpopt_wizard_img_maxw="+encodeURIComponent(iwv.value));}
		if(ihv&&ihv.value!==""){extra.push("wpopt_wizard_img_maxh="+encodeURIComponent(ihv.value));}
		if(irv&&irv.checked){extra.push("wpopt_wizard_img_resize=1");}
		if(ilv&&ilv.checked){extra.push("wpopt_wizard_img_lossless=1");}
		if(isv&&isv.checked){extra.push("wpopt_wizard_img_serve=1");}
		saveConsent(consent?consent.checked:false,extra.length===0);
		if(extra.length){e.preventDefault();var u=cta.getAttribute("href");u+=(u.indexOf("?")>-1?"&":"?")+extra.join("&");window.location.href=u;}
	});}
	var ov=document.getElementById("wpopt-wizard-overlay");
	if(ov){document.body.classList.add("wpopt-modal-open");var f0=ov.querySelector("button,a,input,select");if(f0){f0.focus();}}
	function dismiss(){
		if(ov){ov.hidden=true;}
		document.body.classList.remove("wpopt-modal-open");
		var d=new URLSearchParams();
		d.set("action","wpoptimizing_dismiss_wizard");
		d.set("nonce",nonce);
		var ps2=document.getElementById("wpopt-wizard-preset");
		if(ps2&&ps2.value){d.set("profile",ps2.value);}
		fetch(ajax,{method:"POST",credentials:"same-origin",
			headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},
			body:d.toString()}).catch(function(){});
	}
	var db=document.getElementById("wpopt-wizard-dismiss");
	if(db)db.addEventListener("click",dismiss);
	var sk=document.getElementById("wpopt-wizard-skip");
	if(sk)sk.addEventListener("click",function(e){e.preventDefault();dismiss();});
	var wiz=document.getElementById("wpopt-wizard");
	var steps=wiz?wiz.querySelectorAll(".wpopt-wizard-step"):[];
	var dots=wiz?wiz.querySelectorAll(".wpopt-wizard-dot"):[];
	var back=document.getElementById("wpopt-wizard-back");
	var next=document.getElementById("wpopt-wizard-next");
	var cur=1, total=steps.length;
	function showStep(n){
		cur=Math.max(1,Math.min(total,n));
		Array.prototype.forEach.call(steps,function(s){s.classList.toggle("is-active",parseInt(s.getAttribute("data-step"),10)===cur);});
		Array.prototype.forEach.call(dots,function(d){var dn=parseInt(d.getAttribute("data-step"),10);d.classList.toggle("is-active",dn===cur);d.classList.toggle("is-done",dn<cur);});
		if(back){back.style.visibility=(cur===1)?"hidden":"visible";}
		if(next){next.style.display=(cur===total)?"none":"";}
		if(wiz){wiz.setAttribute("data-step",String(cur));}
	}
	if(next){next.addEventListener("click",function(){showStep(cur+1);});}
	if(back){back.addEventListener("click",function(){showStep(cur-1);});}
	Array.prototype.forEach.call(dots,function(d){d.addEventListener("click",function(){showStep(parseInt(d.getAttribute("data-step"),10)||1);});});
	if(total){showStep(1);}
}());
',
				wp_json_encode( $ajax_url_w ),
				wp_json_encode( $dismiss_nonce ),
				wp_json_encode( $consent_nonce )
			)
		);
	}

	// 6) Render the tabbed UI (Orphans/Inactive/Backups).
	// The blocking progress overlay is shown ONLY for a user-initiated (manual)
	// scan — scan_pending_ui is set just for those. Automatic scans (plugin/theme
	// changes, upgrades, scheduled) run transparently in the background and must
	// NOT take over the screen, so we deliberately do not key off scan_lock here.
	// Show the blocking progress overlay on ANY plugin screen where a manual scan
	// was launched — including Settings (it has its own "Analyze now" button). Only
	// user-initiated scans set scan_pending_ui, so background scans never blur a page.
	//
	// The overlay and its poller are on every plugin screen, hidden unless a scan is pending, so
	// "Analyze now" can open them at once instead of loading a whole admin page only to schedule
	// the scan and redirect to a second one — two full loads, ~4 s on wpoptimizing.com
	// (2026-10-12). Without JavaScript the button stays a link and the old path still works.
	$scan_pending = (bool) get_transient( 'wpoptimizing_scan_pending_ui' );
	/*
	 * THE WIZARD'S FIRST ANALYSIS, WITH THE OBJECT CACHE IT HAS JUST INSTALLED. The request that
	 * installs the drop-in still runs without it, so the «analysis pending» flags went to the
	 * options table, and from the next request on WordPress only reads transients from the new,
	 * empty cache: no overlay, nobody driving the analysis, and the user had to start it by hand
	 * (limpio.local, 11/11/2026). The wizard's redirect carries a mark; with the analysis still
	 * queued, the start is repeated: it queues nothing new (wpoptimizing_schedule_scan() sees the
	 * queued event) and writes the flags again, now where they will be read.
	 */
	if ( ! $scan_pending && isset( $_GET['wpopt_scan_started'] ) && wpoptimizing_scan_event_pending() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only re-shows the progress of an analysis that is already queued.
		wpoptimizing_start_manual_scan();
		$scan_pending = true;
	}
	$ajax_url = admin_url( 'admin-ajax.php' );
	$nonce    = wp_create_nonce( 'wpoptimizing_scan_status' );
	$cancel_n = wp_create_nonce( 'wpoptimizing_cancel_scan' );
	// Styling lives in admin.css (.wpoptimizing-scan-overlay/.wpopt-pb-*).
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped overlay markup.
	echo wpoptimizing_progress_overlay_markup(
		__( 'WPOptimizing — Analysis in progress', 'wpoptimizing' ),
		array(
			'id_prefix'    => 'wpopt-pb',
			'label'        => __( 'Starting…', 'wpoptimizing' ),
			'cancel_id'    => 'wpopt-pb-cancel',
			'cancel_label' => __( 'Cancel analysis', 'wpoptimizing' ),
			'overlay_id'   => 'wpopt-scan-overlay',
			'hidden'       => ! $scan_pending,
		)
	); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-escaped markup helper.
	echo '<div id="wpopt-scan-host" class="' . ( $scan_pending ? 'wpoptimizing-scan-blur' : '' ) . '">';
	$ui->render();
	echo '</div>';
	wp_print_inline_script_tag(
		sprintf(
			'
(function(){
var url=%1$s, nonce=%2$s, cancelNonce=%4$s, startNonce=%5$s, pendingNow=%6$s, timer=null, starting=false;
var fill=document.getElementById("wpopt-pb-fill");
var pctEl=document.getElementById("wpopt-pb-pct");
var lblEl=document.getElementById("wpopt-pb-label");
var etaEl=document.getElementById("wpopt-pb-eta");
var etaLbl=%3$s;
function fmtEta(s){
	if(s<=0)return"";
	if(s<60)return s+etaLbl.sec;
	if(s<5400)return Math.round(s/60)+etaLbl.min;
	return Math.round(s/3600)+etaLbl.hour;
}
function poll(){
	var d=new URLSearchParams();
	d.set("action","wpoptimizing_scan_status");
	d.set("nonce",nonce);
	fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:d.toString()})
		.then(function(r){return r.json();})
		.then(function(r){
			if(!r||!r.success||!r.data)return;
			var p=r.data.progress||{};
			var pct=p.pct||0;
			if(fill)fill.style.width=pct+"%%";
			if(pctEl)pctEl.textContent=pct+"%%";
			if(lblEl&&p.label){var base=String(p.label).replace(/[.…\s]+$/,"");lblEl.textContent=base;if(base){var dts=document.createElement("span");dts.className="wpopt-dots";dts.setAttribute("aria-hidden","true");dts.innerHTML="<i>.</i><i>.</i><i>.</i>";lblEl.appendChild(dts);}}
			if(etaEl)etaEl.textContent=p.eta>0?fmtEta(p.eta):"";
			if(r.data.done){window.location.reload();}
		})
		.catch(function(){});
}
function show(){
	var ov=document.getElementById("wpopt-scan-overlay"), host=document.getElementById("wpopt-scan-host");
	if(ov){ov.classList.remove("wpopt-hidden");}
	if(host){host.classList.add("wpoptimizing-scan-blur");}
}
function follow(){
	poll();
	timer=setInterval(poll,3000);
}
if(pendingNow){show();follow();}
// "Analyze now" without leaving the page: only a plain scan link (page, tab, nonce), never
// the wizard, which carries its settings in the URL. If the start fails, follow the link.
// A click another handler already took is left alone: the wizard button keeps a plain href
// and adds its settings in JS, so reading the href let this start a second scan by AJAX beside
// the wizard request, which could then hit the rate limit and come back with nothing saved
// and the wizard open at step 1 (21/10/2026).
document.addEventListener("click",function(e){
	if(e.defaultPrevented){return;}
	var a=e.target.closest&&e.target.closest("a[href*=\"wpopt_run_async=1\"]");
	if(!a||e.ctrlKey||e.metaKey||e.shiftKey||e.button){return;}
	if(timer||starting){e.preventDefault();return;} // One is already on its way.
	var u;try{u=new URL(a.href,location.href);}catch(x){return;}
	var plain=true;u.searchParams.forEach(function(v,k){if(["page","tab","wpopt_run_async","_wpopt_nonce"].indexOf(k)<0){plain=false;}});
	if(!plain||u.origin!==location.origin){return;}
	e.preventDefault();
	starting=true;
	show();
	var s=new URLSearchParams();
	s.set("action","wpoptimizing_start_scan");
	s.set("nonce",startNonce);
	fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:s.toString()})
		.then(function(r){return r.json();})
		// Follow only once the server has the scan: a poll sent before it would find nothing
		// pending, answer "done" and reload the page under the overlay.
		.then(function(r){if(!r||!r.success){window.location.href=a.href;return;}follow();})
		.catch(function(){window.location.href=a.href;});
});
var cancelBtn=document.getElementById("wpopt-pb-cancel");
if(cancelBtn){cancelBtn.addEventListener("click",function(){
	cancelBtn.disabled=true;
	if(timer){clearInterval(timer);}
	var c=new URLSearchParams();
	c.set("action","wpoptimizing_cancel_scan");
	c.set("nonce",cancelNonce);
	fetch(url,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:c.toString()})
		.then(function(){window.location.reload();})
		.catch(function(){window.location.reload();});
});}
})();
',
			wp_json_encode( $ajax_url ),
			wp_json_encode( $nonce ),
			wp_json_encode(
				array(
					'sec'  => ' ' . __( 's remaining', 'wpoptimizing' ),
					'min'  => ' ' . __( 'min remaining', 'wpoptimizing' ),
					// A big media library takes hours; the estimate used to hide itself past one.
					'hour' => ' ' . __( 'h remaining', 'wpoptimizing' ),
				)
			),
			wp_json_encode( $cancel_n ),
			wp_json_encode( wp_create_nonce( 'wpoptimizing_run_async' ) ),
			wp_json_encode( $scan_pending )
		)
	);

	echo '</div>';

	// Right column on the Settings page: the info box (Art Project Group links,
	// hidden in white-label mode) with the vertical, sticky section menu below it,
	// so the section nav does not take width away from the content. On mobile this
	// column drops below the content and the nav is hidden (the dropdown at the top
	// of the content is used instead).
	if ( $forced_settings ) {
		echo '<aside class="wpopt-settings-aside">';
		if ( ! wpoptimizing_is_white_label() ) {
			require_once __DIR__ . '/cuadro-informacion.php';
		}
		$ui->render_settings_section_nav();
		echo '</aside>';
	}

	// Single reusable doc-help modal (populated by JS from data attributes on any
	// .wpopt-doc-help-btn button — no per-section duplication). Reuse the instance
	// that rendered the page rather than constructing a second one.
	$ui->render_doc_modal();
	$ui->render_record_viewer(); // Every list that shows a database record opens it here.
	$ui->render_save_forms_js(); // One save behaviour for every settings form.

	echo '</div></div>';
}

/**
 * Auto-redeploy our own object-cache drop-in when it is present but stale.
 *
 * The shipped drop-in carries the session-token race fix (the "constant logout"
 * bug: a stale persistent copy of the per-user `user_meta` blob could drop a
 * freshly issued login token). The Cache tab only OFFERS an update behind a manual
 * click, so on sites where an admin never opens it the safety fix would never reach
 * wp-content/object-cache.php. This refreshes it unattended, exactly once per shipped
 * drop-in version, and ONLY ever rewrites a drop-in that is already ours (never a
 * foreign one, never installing where the admin never opted in).
 *
 * @return void
 */
function wpoptimizing_maybe_refresh_object_cache_dropin() {
	$current = defined( 'WPOPT_DROPIN_VERSION' ) ? (string) WPOPT_DROPIN_VERSION : '1';
	if ( (string) get_option( 'wpoptimizing_dropin_autorefreshed_ver', '' ) === $current ) {
		return; // Already handled this version.
	}
	// Only a drop-in manager's pageload performs the filesystem write; lower-priv users hitting
	// admin_init leave the marker unset so a manager still triggers it later.
	if ( ! wpoptimizing_user_can_manage_dropin() ) {
		return;
	}

	$src  = defined( 'WPOPT_DROPIN_SRC' ) ? WPOPT_DROPIN_SRC : '';
	$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';

	// Nothing to refresh unless our template is readable and a drop-in already exists.
	if ( '' === $src || ! is_readable( $src ) || ! file_exists( $dest ) ) {
		update_option( 'wpoptimizing_dropin_autorefreshed_ver', $current, false );
		return;
	}

	$head = (string) file_get_contents( $dest, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header, not a remote/uploaded file.
	if ( false === strpos( $head, 'WPOPT_OBJECT_CACHE' ) ) {
		// Foreign drop-in (another plugin owns it): never touch it.
		update_option( 'wpoptimizing_dropin_autorefreshed_ver', $current, false );
		return;
	}

	$installed = '';
	if ( preg_match( '/WPOPT_OBJECT_CACHE\s+v(\d+)/', $head, $m ) ) {
		$installed = $m[1];
	}
	if ( '' !== $installed && $installed === $current ) {
		update_option( 'wpoptimizing_dropin_autorefreshed_ver', $current, false ); // Already up to date.
		return;
	}

	// Stale copy of OUR drop-in: refresh it in place. Mark handled only on success so
	// a transient write failure retries on the next admin_init.
	// Atomic replace (tmp+rename) so a concurrent boot never loads a half-written drop-in;
	// the installer invalidates opcache for $dest on success.
	$ok = wpoptimizing_atomic_install_file( $src, $dest );
	if ( $ok ) {
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log(
				'cache_dropin_autoupdate',
				array(
					'from' => $installed,
					'to'   => $current,
				)
			);
		}
		update_option( 'wpoptimizing_dropin_autorefreshed_ver', $current, false );
	}
}
add_action( 'admin_init', 'wpoptimizing_maybe_refresh_object_cache_dropin' );

/**
 * Put back the drop-in sidecar and the cache directory guards when something has removed them.
 *
 * Emptying wp-content/cache is the universal "clear the cache" gesture: a hosting panel
 * button, another optimisation plugin, a deploy script or an admin doing it by hand. It also
 * takes the two files that are NOT cache with it, and nothing notices:
 *
 *  - wpopt-cache-config.php. Without it the drop-in silently falls back to auto-detection and
 *    a derived key salt, so a site configured for Redis with a host, port or password stops
 *    using it — the connection fails, the backend degrades to the per-request array, and the
 *    Cache tab still reports the settings the user saved. Performance quietly halves and the
 *    only symptom is "the site feels slower".
 *  - index.php and .htaccess. The directory holds the SQLite store and the analytics file,
 *    which carries sample SQL. On a server that does not block dot-directories by itself,
 *    losing those two makes both fetchable over HTTP.
 *
 * Only touches an install where OUR object-cache drop-in is the live one, and only from a
 * pageload by somebody allowed to manage it. Three file_exists() calls on admin_init, none on
 * the front end.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_maybe_restore_cache_sidecar() {
	$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';
	if ( ! file_exists( $dest ) ) {
		return; // No object cache here: the sidecar would configure nothing.
	}

	$dir  = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR : WP_CONTENT_DIR . '/cache';
	$path = defined( 'WPOPT_CACHE_CONFIG' ) ? WPOPT_CACHE_CONFIG : $dir . '/wpopt-cache-config.php';
	if ( file_exists( $path ) && file_exists( $dir . '/index.php' ) && file_exists( $dir . '/.htaccess' ) ) {
		return; // Everything is where it should be.
	}

	if ( ! wpoptimizing_user_can_manage_dropin() ) {
		return; // Leave the marker unset so a manager's pageload does the write.
	}

	// Never write a config for a drop-in belonging to another plugin.
	$head = (string) file_get_contents( $dest, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header, not a remote/uploaded file.
	if ( false === strpos( $head, 'WPOPT_OBJECT_CACHE' ) ) {
		return;
	}

	// Writes the sidecar AND restores index.php/.htaccess. The saved settings and the stored
	// salt both live in the database, so the file comes back exactly as it was.
	wpoptimizing_write_cache_config_sidecar();
}
add_action( 'admin_init', 'wpoptimizing_maybe_restore_cache_sidecar' );

/**
 * Auto-redeploy our own db.php (query-dedup) drop-in when it is present but stale, mirroring
 * the object-cache refresher. Keeps a deployed drop-in in step with new versions (e.g. the
 * per-query source attribution added in v3) without the user having to click "Update drop-in".
 *
 * @return void
 */
function wpoptimizing_maybe_refresh_db_dropin() {
	$current = defined( 'WPOPT_DB_DROPIN_VERSION' ) ? (string) WPOPT_DB_DROPIN_VERSION : '1';
	if ( (string) get_option( 'wpoptimizing_db_dropin_autorefreshed_ver', '' ) === $current ) {
		return; // Already handled this version.
	}
	if ( ! wpoptimizing_user_can_manage_dropin() ) {
		return;
	}

	$src  = defined( 'WPOPT_DB_DROPIN_SRC' ) ? WPOPT_DB_DROPIN_SRC : dirname( __DIR__ ) . '/dropins/db.php';
	$dest = defined( 'WPOPT_DB_DROPIN_DEST' ) ? WPOPT_DB_DROPIN_DEST : WP_CONTENT_DIR . '/db.php';

	if ( '' === $src || ! is_readable( $src ) || ! file_exists( $dest ) ) {
		update_option( 'wpoptimizing_db_dropin_autorefreshed_ver', $current, false );
		return;
	}

	$head = (string) file_get_contents( $dest, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header, not a remote/uploaded file.
	if ( false === strpos( $head, 'WPOPT_DB_DROPIN' ) ) {
		// Foreign db.php (Query Monitor, HyperDB…): never touch it.
		update_option( 'wpoptimizing_db_dropin_autorefreshed_ver', $current, false );
		return;
	}

	$installed = '';
	if ( preg_match( '/WPOPT_DB_DROPIN\s+v(\d+)/', $head, $m ) ) {
		$installed = $m[1];
	}
	if ( '' !== $installed && $installed === $current ) {
		update_option( 'wpoptimizing_db_dropin_autorefreshed_ver', $current, false );
		return;
	}

	// Atomic replace (tmp+rename) so a concurrent boot never loads a half-written drop-in;
	// the installer invalidates opcache for $dest on success.
	$ok = wpoptimizing_atomic_install_file( $src, $dest );
	if ( $ok ) {
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log(
				'db_dropin_autoupdate',
				array(
					'from' => $installed,
					'to'   => $current,
				)
			);
		}
		update_option( 'wpoptimizing_db_dropin_autorefreshed_ver', $current, false );
	}
}
add_action( 'admin_init', 'wpoptimizing_maybe_refresh_db_dropin' );

/**
 * Register the "Keep all revisions" meta box on post types that support revisions.
 *
 * A classic meta box (not the publish-box hook) so it is visible in BOTH the block
 * editor and the classic editor.
 *
 * @return void
 */
function wpoptimizing_register_revision_metabox() {
	foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $pt ) {
		if ( ! post_type_supports( $pt, 'revisions' ) ) {
			continue;
		}
		add_meta_box(
			'wpoptimizing_keep_revisions',
			__( 'Post revisions', 'wpoptimizing' ),
			'wpoptimizing_revision_keep_metabox',
			$pt,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'wpoptimizing_register_revision_metabox' );

/**
 * Signature of the analysis the «To identify» count belongs to: a new analysis changes it.
 *
 * @return string
 */
function wpoptimizing_pending_count_signature() {
	$parts = array();
	foreach ( array( WPOPT_CSV_SCAN_DB, WPOPT_CSV_SCAN_FS ) as $file ) {
		$parts[] = file_exists( $file ) ? (int) filemtime( $file ) . ':' . (int) filesize( $file ) : '-';
	}
	return md5( implode( '|', $parts ) );
}

/**
 * Keep the «To identify» count of the analysis just read, for the tabs that do not read it.
 *
 * @param int $count Records and files still to identify.
 * @return void
 */
function wpoptimizing_pending_count_remember( $count ) {
	$entry = array(
		'sig' => wpoptimizing_pending_count_signature(),
		'n'   => max( 0, (int) $count ),
	);
	if ( get_transient( 'wpoptimizing_pending_count' ) !== $entry ) {
		set_transient( 'wpoptimizing_pending_count', $entry, DAY_IN_SECONDS );
	}
}

/**
 * The «To identify» count last computed for the current analysis; 0 (the label goes without a
 * number) when the analysis changed since, until a review tab or Home reads it again.
 *
 * @return int
 */
function wpoptimizing_pending_count_cached() {
	$entry = get_transient( 'wpoptimizing_pending_count' );
	if ( ! is_array( $entry ) || ( $entry['sig'] ?? '' ) !== wpoptimizing_pending_count_signature() ) {
		return 0;
	}
	return (int) ( $entry['n'] ?? 0 );
}
