<?php
/**
 * Import the configuration of another cache or performance plugin.
 *
 * WHY. Someone who moves from WP Rocket, LiteSpeed Cache or W3 Total Cache has spent hours
 * tuning exclusions, lifetimes and switches. Asking them to redo it is the main reason not to
 * switch. Each source here reads the other plugin's LIVE configuration (it stays in the
 * database after the plugin is deactivated) and translates it into changes to our own options.
 *
 * HOW IT STAYS SAFE.
 *  - Everything lands through wpoptimizing_import_settings_payload(): the same per-option
 *    sanitizers as importing our own JSON, so an imported value can never be one our screens
 *    would refuse, and nothing that installs a drop-in is ever switched on by an import.
 *  - Credentials never travel: licence keys, API keys, passwords, tokens. A source that has a
 *    Cloudflare Global API Key says so, it does not copy it.
 *  - Before writing, our current configuration is kept, so «Undo the import» restores it.
 *  - What cannot be translated is not guessed: each skipped or partly translated setting comes
 *    back as a note, in the preview and in the report.
 *
 * A SOURCE is includes/importers/source-<id>.php with two functions:
 *    wpoptimizing_import_src_<id>_read()  → array|null  (null: nothing of that plugin here)
 *    wpoptimizing_import_src_<id>_map( $raw ) → array{changes:array,notes:array}
 * where <id> is the source id with dashes as underscores.
 *
 * Ships in Lite: importing and coexisting are free (decided 06/11/2026). Settings of features
 * that are not in this edition are simply not among the portable options here, and the report
 * lists them as not imported.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

// The page lifetime policy shared with the page-cache screen (wpoptimizing_page_cache_ttl_policy()).
require_once dirname( __DIR__ ) . '/content-purge.php';

/** Option holding the configuration as it was before the last import (for «Undo»). */
const WPOPTIMIZING_IMPORTER_UNDO_OPTION = 'wpoptimizing_importer_undo';

/**
 * The sources, in the order they are offered, each with the folder names its plugin is
 * installed under (to offer deactivating it after importing).
 *
 * @return array<string,array{label:string,slugs:string[]}>
 */
function wpoptimizing_importer_sources() {
	$sources = array(
		'wp-rocket'        => array(
			'label' => 'WP Rocket',
			'slugs' => array( 'wp-rocket' ),
		),
		'litespeed'        => array(
			'label' => 'LiteSpeed Cache',
			'slugs' => array( 'litespeed-cache' ),
		),
		'w3tc'             => array(
			'label' => 'W3 Total Cache',
			'slugs' => array( 'w3-total-cache' ),
		),
		'wp-super-cache'   => array(
			'label' => 'WP Super Cache',
			'slugs' => array( 'wp-super-cache' ),
		),
		'wp-fastest-cache' => array(
			'label' => 'WP Fastest Cache',
			'slugs' => array( 'wp-fastest-cache' ),
		),
		'siteground'       => array(
			'label' => 'SiteGround Speed Optimizer',
			'slugs' => array( 'sg-cachepress' ),
		),
		'perfmatters'      => array(
			'label' => 'Perfmatters',
			'slugs' => array( 'perfmatters' ),
		),
		'flyingpress'      => array(
			'label' => 'FlyingPress',
			'slugs' => array( 'flying-press' ),
		),
		'breeze'           => array(
			'label' => 'Breeze',
			'slugs' => array( 'breeze' ),
		),
		'wp-optimize'      => array(
			'label' => 'WP-Optimize',
			'slugs' => array( 'wp-optimize' ),
		),
		'powered-cache'    => array(
			'label' => 'Powered Cache',
			'slugs' => array( 'powered-cache' ),
		),
		'hummingbird'      => array(
			'label' => 'Hummingbird',
			'slugs' => array( 'hummingbird-performance', 'wp-hummingbird' ),
		),
		'autoptimize'      => array(
			'label' => 'Autoptimize',
			'slugs' => array( 'autoptimize' ),
		),
	);
	$out     = array();
	foreach ( $sources as $id => $def ) {
		if ( is_readable( wpoptimizing_importer_source_file( $id ) ) ) {
			$out[ $id ] = $def;
		}
	}
	return $out;
}

/**
 * File of a source.
 *
 * @param string $id Source id.
 * @return string
 */
function wpoptimizing_importer_source_file( $id ) {
	return __DIR__ . '/source-' . sanitize_key( (string) $id ) . '.php';
}

/**
 * Call a source's function, loading its file first. Null when the source does not exist.
 *
 * @param string $id   Source id.
 * @param string $what 'read' | 'map'.
 * @param array  $args Arguments.
 * @return mixed
 */
function wpoptimizing_importer_call( $id, $what, array $args = array() ) {
	$file = wpoptimizing_importer_source_file( $id );
	if ( ! is_readable( $file ) ) {
		return null;
	}
	require_once $file;
	$fn = 'wpoptimizing_import_src_' . str_replace( '-', '_', sanitize_key( (string) $id ) ) . '_' . $what;
	return function_exists( $fn ) ? call_user_func_array( $fn, $args ) : null;
}

/**
 * Sources with a configuration on this site, each with its plan (what would change).
 *
 * @return array<string,array{label:string,plan:array}>
 */
function wpoptimizing_importer_detected() {
	$out = array();
	foreach ( wpoptimizing_importer_sources() as $id => $def ) {
		$plan = wpoptimizing_importer_plan( $id );
		if ( null !== $plan ) {
			$out[ $id ] = array(
				'label' => $def['label'],
				'plan'  => $plan,
			);
		}
	}
	return $out;
}

/**
 * What importing a source would change, without changing anything.
 *
 * @param string $id Source id.
 * @return array{changes:array,notes:array}|null Null when that plugin left nothing here.
 */
function wpoptimizing_importer_plan( $id ) {
	$raw = wpoptimizing_importer_call( $id, 'read' );
	if ( ! is_array( $raw ) || empty( $raw ) ) {
		return null;
	}
	$plan = wpoptimizing_importer_call( $id, 'map', array( $raw ) );
	if ( ! is_array( $plan ) ) {
		return null;
	}
	return array(
		'changes' => array_values( (array) ( $plan['changes'] ?? array() ) ),
		'notes'   => array_values( (array) ( $plan['notes'] ?? array() ) ),
	);
}

/**
 * Sources whose settings have something to bring over, with their plan (the welcome wizard
 * offers only these: a plugin that left nothing importable has nothing to offer there).
 *
 * @return array<string,array{label:string,plan:array}>
 */
function wpoptimizing_importer_importable() {
	return array_filter(
		wpoptimizing_importer_detected(),
		static function ( $src ) {
			return ! empty( $src['plan']['changes'] );
		}
	);
}

/**
 * A planned value as the summary shows it, in the unit of the WPOptimizing setting it goes to.
 * Pure but for translations.
 *
 * @param mixed  $v      Value.
 * @param string $target «option.key» of the change ('' when unknown).
 * @return string
 */
function wpoptimizing_importer_value_text( $v, $target = '' ) {
	$target = (string) $target;
	if ( 'wpoptimizing_page_cache.ttl' === $target && is_numeric( $v ) ) {
		// Stored in seconds; the page-cache screen sets whole hours.
		$hours = (int) round( (int) $v / HOUR_IN_SECONDS );
		/* translators: %d: number of hours. */
		return sprintf( _n( '%d hour', '%d hours', $hours, 'wpoptimizing' ), $hours );
	}
	if ( 0 === strpos( $target, 'wpoptimizing_cache_settings.heartbeat_' ) ) {
		if ( is_numeric( $v ) ) {
			/* translators: %d: number of seconds. */
			return sprintf( __( 'Every %d seconds', 'wpoptimizing' ), (int) $v );
		}
		if ( 'off' === $v ) {
			return __( 'Off', 'wpoptimizing' );
		}
		if ( 'default' === $v ) {
			return __( 'Default', 'wpoptimizing' );
		}
	}
	if ( 'wpoptimizing_general_cleanup_schedule.' === $target || 'wpoptimizing_gc_task_schedules.revisions' === $target ) {
		$freq = array(
			'manual'     => __( 'Manual only', 'wpoptimizing' ),
			'hourly'     => __( 'Hourly', 'wpoptimizing' ),
			'twicedaily' => __( 'Twice daily', 'wpoptimizing' ),
			'daily'      => __( 'Daily', 'wpoptimizing' ),
			'weekly'     => __( 'Weekly', 'wpoptimizing' ),
			'monthly'    => __( 'Monthly', 'wpoptimizing' ),
			'never'      => __( 'Never', 'wpoptimizing' ),
		);
		if ( is_string( $v ) && isset( $freq[ $v ] ) ) {
			return $freq[ $v ];
		}
	}
	if ( 'wpoptimizing_image_settings.max_width' === $target && is_numeric( $v ) ) {
		/* translators: %d: width in pixels. */
		return sprintf( __( '%d px', 'wpoptimizing' ), (int) $v );
	}
	if ( 'wpoptimizing_cdn.dirs' === $target && is_array( $v ) ) {
		$names = array(
			'uploads'  => __( 'Uploads (media library)', 'wpoptimizing' ),
			'content'  => __( 'wp-content (themes and plugins)', 'wpoptimizing' ),
			'includes' => __( 'wp-includes (WordPress core assets)', 'wpoptimizing' ),
		);
		$on    = array();
		foreach ( $names as $scope => $name ) {
			if ( ! empty( $v[ $scope ] ) ) {
				$on[] = $name;
			}
		}
		return implode( ', ', $on );
	}
	if ( 'wpoptimizing_delay_js.timeout' === $target && is_numeric( $v ) ) {
		/* translators: %d: number of seconds. */
		return sprintf( _n( '%d second', '%d seconds', (int) $v, 'wpoptimizing' ), (int) $v );
	}
	if ( is_bool( $v ) || 0 === $v || 1 === $v ) {
		return $v ? __( 'On', 'wpoptimizing' ) : __( 'Off', 'wpoptimizing' );
	}
	if ( is_array( $v ) ) {
		return implode( ', ', array_map( 'strval', array_slice( $v, 0, 8 ) ) ) . ( count( $v ) > 8 ? ' …' : '' );
	}
	return mb_strimwidth( (string) $v, 0, 80, '…' );
}

/**
 * The WPOptimizing setting a change goes to, as the screens name it (the third column of the
 * summary). One setting of the other plugin often feeds several of ours, so without it those
 * rows read the same. Pure but for translations.
 *
 * @param array $c A wpoptimizing_importer_change() item.
 * @return string
 */
function wpoptimizing_importer_target_label( array $c ) {
	$key = (string) ( $c['option'] ?? '' ) . '.' . (string) ( $c['key'] ?? '' );
	return (string) ( wpoptimizing_importer_target_labels()[ $key ] ?? $key );
}

/**
 * Our settings an import can change, each as its screen labels it: the control's own label, or
 * «section › label» where the label alone does not say which (Heartbeat areas, host and port).
 * Pure but for translations.
 *
 * @return array<string,string> «option.key» => label. The whole-option ones end in «.».
 */
function wpoptimizing_importer_target_labels() {
	$in = static function ( $section, $label ) {
		return $section . ' › ' . $label;
	};
	return array(
		'wpoptimizing_asset_optimize.minify_css'                       => __( 'Minify CSS', 'wpoptimizing' ),
		'wpoptimizing_asset_optimize.minify_js'                        => __( 'Minify JavaScript', 'wpoptimizing' ),
		'wpoptimizing_asset_optimize.exclude'                          => __( 'Exclude these handles or URL fragments (one per line)', 'wpoptimizing' ),
		'wpoptimizing_asset_optimize.defer_js'                         => __( 'Defer JavaScript (load local scripts after the page renders; jQuery stays eager)', 'wpoptimizing' ),
		'wpoptimizing_asset_optimize.defer_exclude'                    => __( 'Do not defer these handles or URL fragments (one per line)', 'wpoptimizing' ),
		'wpoptimizing_asset_optimize.async_css'                        => __( 'Load CSS asynchronously (non-blocking render; only on pages that have critical CSS, so they never paint unstyled)', 'wpoptimizing' ),
		'wpoptimizing_asset_optimize.critical_css'                     => __( 'Critical CSS (inlined in the head, before the async stylesheets, to remove the flash)', 'wpoptimizing' ),
		'wpoptimizing_cache_settings.backend'                          => __( 'Backend', 'wpoptimizing' ),
		'wpoptimizing_cache_settings.redis_host'                       => $in( __( 'Redis host / port', 'wpoptimizing' ), __( 'Host', 'wpoptimizing' ) ),
		'wpoptimizing_cache_settings.redis_port'                       => $in( __( 'Redis host / port', 'wpoptimizing' ), __( 'Port', 'wpoptimizing' ) ),
		'wpoptimizing_cache_settings.redis_database'                   => __( 'Redis database', 'wpoptimizing' ),
		'wpoptimizing_cache_settings.memcached_host'                   => $in( __( 'Memcached host / port', 'wpoptimizing' ), __( 'Host', 'wpoptimizing' ) ),
		'wpoptimizing_cache_settings.memcached_port'                   => $in( __( 'Memcached host / port', 'wpoptimizing' ), __( 'Port', 'wpoptimizing' ) ),
		'wpoptimizing_cache_settings.heartbeat_admin'                  => $in( __( 'Heartbeat by area', 'wpoptimizing' ), __( 'Admin screens', 'wpoptimizing' ) ),
		'wpoptimizing_cache_settings.heartbeat_editor'                 => $in( __( 'Heartbeat by area', 'wpoptimizing' ), __( 'Editor (posts, pages and Site Editor)', 'wpoptimizing' ) ),
		'wpoptimizing_cache_settings.heartbeat_front'                  => $in( __( 'Heartbeat by area', 'wpoptimizing' ), __( 'Front end (logged-in users)', 'wpoptimizing' ) ),
		'wpoptimizing_cdn.enabled'                                     => __( 'Serve static files (images, CSS, JS) from a CDN', 'wpoptimizing' ),
		'wpoptimizing_cdn.host'                                        => $in( __( 'CDN rewriting', 'wpoptimizing' ), __( 'Host', 'wpoptimizing' ) ),
		'wpoptimizing_cdn.dirs'                                        => __( 'CDN rewriting', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.emojis'                              => __( 'Disable emojis', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.generator'                           => __( 'Remove WordPress version meta', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.rsd_link'                            => __( 'Remove RSD link', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.shortlink'                           => __( 'Remove shortlink tag', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.self_pingbacks'                      => __( 'Disable self-pingbacks', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.jquery_migrate'                      => __( 'Remove jQuery Migrate', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.dashicons_frontend'                  => __( 'Remove Dashicons on the front end', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.embeds'                              => __( 'Disable oEmbed extras', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.rest_head_links'                     => __( 'Remove REST API head links', 'wpoptimizing' ),
		'wpoptimizing_core_tweaks.cart_fragments'                      => __( 'Load WooCommerce cart fragments only with a cart', 'wpoptimizing' ),
		'wpoptimizing_delay_js.enabled'                                => __( 'Delay JavaScript', 'wpoptimizing' ),
		'wpoptimizing_delay_js.test_mode'                              => __( 'Test mode: apply only for logged-in administrators', 'wpoptimizing' ),
		'wpoptimizing_delay_js.profile'                                => __( 'What to delay', 'wpoptimizing' ),
		'wpoptimizing_delay_js.timeout'                                => __( 'Run anyway after', 'wpoptimizing' ),
		'wpoptimizing_delay_js.exclude'                                => __( 'Never delay these (one handle or URL fragment per line)', 'wpoptimizing' ),
		'wpoptimizing_delay_js.include'                                => __( 'Also delay these (one handle or URL fragment per line)', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.speculation'                       => __( 'Speculative loading', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.image_opt'                         => __( 'Image loading', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.image_eager'                       => __( 'Images never lazy-loaded at the top of the page', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.lazy_exclude'                      => __( 'Never lazy-load images whose class or file name contains (one per line)', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.resource_hints'                    => __( 'Resource hints', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.preload'                           => __( 'Preload key resources', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.preload_urls'                      => __( 'Also preload these URLs (one per line): above-the-fold fonts or a hero image', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.lazy_embeds'                       => __( 'Lazy embeds', 'wpoptimizing' ),
		'wpoptimizing_frontend_perf.google_fonts'                      => __( 'Google Fonts', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_schedule.'                       => __( 'Automatic maintenance', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.keep_revisions'         => __( 'Revisions to keep per post', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.revisions_max_age_days' => __( 'Delete revisions older than (days)', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_auto_drafts'     => __( 'Delete auto-drafts', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_trashed_posts'   => __( 'Delete trashed posts', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_spam_comments'   => __( 'Delete spam comments', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_trashed_comments' => __( 'Delete trashed comments', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_unapproved_comments' => __( 'Delete unapproved comments (includes those awaiting moderation, not just spam)', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_pingbacks'       => __( 'Delete pingbacks', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_trackbacks'      => __( 'Delete trackbacks', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_expired_transients' => __( 'Delete expired transients', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_all_transients'  => __( 'Delete all transients, including those that have not expired yet', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_oembed_cache'    => __( 'Delete oEmbed cache', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_unused_tags'     => __( 'Delete unused tags', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_orphan_elements' => __( 'Delete orphan elements', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_duplicate_meta'  => __( 'Delete duplicate metadata (keeps the oldest copy)', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_action_scheduler' => __( 'Delete finished Action Scheduler logs (completed/failed/cancelled, 30+ days)', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_wc_sessions'     => __( 'WooCommerce: delete expired customer sessions', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.delete_wc_orphan_variations' => __( 'WooCommerce: delete orphaned product variations (their product no longer exists)', 'wpoptimizing' ),
		'wpoptimizing_general_cleanup_settings.optimize_tables'        => __( 'Optimize database tables', 'wpoptimizing' ),
		'wpoptimizing_gc_task_schedules.revisions'                     => $in( __( 'Per-task frequency (advanced)', 'wpoptimizing' ), __( 'Post revisions', 'wpoptimizing' ) ),
		'wpoptimizing_image_settings.max_width'                        => __( 'Max dimensions (px)', 'wpoptimizing' ),
		'wpoptimizing_image_settings.resize_uploads'                   => __( 'Scale down oversized uploads to the max dimensions — the full-size original is preserved', 'wpoptimizing' ),
		'wpoptimizing_image_settings.serve_nextgen'                    => __( 'Serve WebP/AVIF on the front-end (as <picture> sources, falling back to the original)', 'wpoptimizing' ),
		'wpoptimizing_image_settings.nextgen_on_upload'                => __( 'Generate a WebP copy on upload — new images get their copy immediately, without waiting for the bulk pass. AVIF stays a bulk action (too slow per upload).', 'wpoptimizing' ),
		'wpoptimizing_page_cache.ttl'                                  => __( 'Rebuild cached pages after', 'wpoptimizing' ),
		'wpoptimizing_page_cache.gzip'                                 => __( 'Store compressed copies (gzip)', 'wpoptimizing' ),
		'wpoptimizing_page_cache.purge_always'                         => __( 'Purge also these URLs every time any page is purged', 'wpoptimizing' ),
		'wpoptimizing_page_cache.preload'                              => __( 'Rebuild pages automatically after they are cleared', 'wpoptimizing' ),
		'wpoptimizing_page_cache.reject_uri'                           => __( 'Never cache these URL paths', 'wpoptimizing' ),
		'wpoptimizing_page_cache.reject_cookies'                       => __( 'Never cache when these cookies are present', 'wpoptimizing' ),
		'wpoptimizing_page_cache.query_whitelist'                      => __( 'Still cache with these query parameters', 'wpoptimizing' ),
		'wpoptimizing_page_cache.ignored_query'                        => __( 'Also ignore these tracking parameters (the page is cached as if they were not there)', 'wpoptimizing' ),
		'wpoptimizing_page_cache.reject_ua'                            => __( 'Never cache for these browsers or bots', 'wpoptimizing' ),
		'wpoptimizing_page_cache.mobile_separate'                      => __( 'Separate cache for mobile devices', 'wpoptimizing' ),
		'wpoptimizing_page_cache.minify_html'                          => __( 'Minify the HTML of cached pages', 'wpoptimizing' ),
	);
}

/**
 * What importing a source would change, and its notes, as HTML: the same summary in Settings and
 * in the welcome wizard. Each row: the other plugin's setting, the WPOptimizing setting it
 * changes, and the value it gets.
 *
 * @param array $src An entry of wpoptimizing_importer_detected().
 * @return string Escaped HTML.
 */
function wpoptimizing_importer_summary_html( array $src ) {
	$n   = count( (array) ( $src['plan']['changes'] ?? array() ) );
	$out = '';
	if ( 0 === $n ) {
		$out .= '<p>' . esc_html__( 'Its settings have nothing WPOptimizing can bring over.', 'wpoptimizing' ) . '</p>';
	} else {
		$name = (string) ( $src['label'] ?? '' );
		$out .= '<details><summary>' . esc_html(
			sprintf(
				/* translators: %d: number of settings. */
				_n( '%d setting would be imported', '%d settings would be imported', $n, 'wpoptimizing' ),
				$n
			)
		) . '</summary><table class="widefat striped wpopt-table-full"><thead><tr>'
			. '<th scope="col">' . esc_html(
				'' === $name ? __( 'In the other plugin', 'wpoptimizing' ) : sprintf(
					/* translators: %s: the other plugin's name. */
					__( 'In %s', 'wpoptimizing' ),
					$name
				)
			) . '</th>'
			. '<th scope="col">' . esc_html__( 'In WPOptimizing', 'wpoptimizing' ) . '</th>'
			. '<th scope="col">' . esc_html__( 'Value', 'wpoptimizing' ) . '</th>'
			. '</tr></thead><tbody>';
		foreach ( $src['plan']['changes'] as $c ) {
			$target = (string) ( $c['option'] ?? '' ) . '.' . (string) ( $c['key'] ?? '' );
			$out   .= '<tr><td>' . esc_html( (string) $c['from'] ) . '</td><td>' . esc_html( wpoptimizing_importer_target_label( $c ) ) . '</td><td>' . esc_html( wpoptimizing_importer_value_text( $c['value'], $target ) ) . '</td></tr>';
		}
		$out .= '</tbody></table></details>';
	}
	if ( ! empty( $src['plan']['notes'] ) ) {
		$out .= '<ul class="ul-disc">';
		foreach ( $src['plan']['notes'] as $note ) {
			$out .= '<li>' . esc_html( (string) $note['text'] ) . '</li>';
		}
		$out .= '</ul>';
	}
	return $out;
}

/**
 * The installed plugin of a source: its main file («folder/file.php»), or ''.
 *
 * @param string     $id      Source id.
 * @param array|null $plugins get_plugins() (null: read it).
 * @return string
 */
function wpoptimizing_importer_plugin_file( $id, $plugins = null ) {
	$slugs = (array) ( wpoptimizing_importer_sources()[ (string) $id ]['slugs'] ?? array() );
	if ( empty( $slugs ) ) {
		return '';
	}
	if ( null === $plugins ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
	}
	foreach ( array_keys( (array) $plugins ) as $file ) {
		if ( in_array( dirname( (string) $file ), $slugs, true ) ) {
			return (string) $file;
		}
	}
	return '';
}

/**
 * Who may deactivate a plugin, and where. Pure.
 *
 * A plugin active for the whole network is the network's: only someone who manages the
 * network's plugins switches it off, and network-wide (switching it off on one site does
 * nothing while the network keeps it on).
 *
 * @param bool $site_active    Active on this site (on its own, not for the network).
 * @param bool $network_active Active for the whole network.
 * @param bool $can_site       activate_plugins.
 * @param bool $can_network    manage_network_plugins.
 * @return string network|site|denied|inactive
 */
function wpoptimizing_importer_deactivation_scope( $site_active, $network_active, $can_site, $can_network ) {
	if ( $network_active ) {
		return $can_network ? 'network' : 'denied';
	}
	if ( $site_active ) {
		return $can_site ? 'site' : 'denied';
	}
	return 'inactive';
}

/**
 * Can the current user deactivate a source's plugin here, and how?
 *
 * @param string     $id      Source id.
 * @param array|null $plugins get_plugins() (null: read it).
 * @return array{file:string,scope:string} scope as wpoptimizing_importer_deactivation_scope().
 */
function wpoptimizing_importer_deactivation( $id, $plugins = null ) {
	$file = wpoptimizing_importer_plugin_file( $id, $plugins );
	if ( '' === $file ) {
		return array(
			'file'  => '',
			'scope' => 'inactive',
		);
	}
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$network = is_multisite() && is_plugin_active_for_network( $file );
	return array(
		'file'  => $file,
		'scope' => wpoptimizing_importer_deactivation_scope(
			! $network && is_plugin_active( $file ),
			$network,
			current_user_can( 'activate_plugins' ),
			is_multisite() && current_user_can( 'manage_network_plugins' )
		),
	);
}

/**
 * Deactivate a source's plugin after importing from it. Never deletes it: its settings stay,
 * so it can be switched on again as it was. Its own deactivation routine runs (WordPress calls
 * it), which is what takes its rules out of .htaccess and its drop-ins out of wp-content.
 *
 * @param string $id Source id.
 * @return array{done:bool,scope:string}
 */
function wpoptimizing_importer_deactivate( $id ) {
	$d = wpoptimizing_importer_deactivation( $id );
	if ( ! in_array( $d['scope'], array( 'site', 'network' ), true ) ) {
		return array(
			'done'  => false,
			'scope' => $d['scope'],
		);
	}
	$network = 'network' === $d['scope'];
	deactivate_plugins( $d['file'], false, $network );
	$still = $network ? is_plugin_active_for_network( $d['file'] ) : is_plugin_active( $d['file'] );
	// wp-config.php is put right by wpoptimizing_page_cache_after_plugin_deactivated() (page-cache.php),
	// on «deactivated_plugin», whoever deactivates the plugin: LiteSpeed Cache takes WP_CACHE out.
	return array(
		'done'  => ! $still,
		'scope' => $d['scope'],
	);
}

/**
 * What to tell after deactivating a source's plugin.
 *
 * @param string $id Source id.
 * @return string
 */
function wpoptimizing_importer_deactivated_message( $id ) {
	$label = (string) ( wpoptimizing_importer_sources()[ (string) $id ]['label'] ?? $id );
	$msg   = sprintf(
		/* translators: %s: plugin name. */
		__( '%s has been deactivated (not deleted): its page cache no longer serves your pages. Switch on the WPOptimizing page cache in Speed › Cache; an import never switches it on by itself.', 'wpoptimizing' ),
		$label
	);
	if ( 'litespeed' === $id ) {
		// Checked in its code (src/activation.cls.php, register_deactivation(), 7.x): it purges,
		// takes WP_CACHE out of wp-config.php, deletes its own object-cache.php and clears its
		// .htaccess rules, but on a network only when no other site still has it active.
		$msg .= ' ' . __( 'LiteSpeed Cache cleans up after itself when it is deactivated: its rules in .htaccess, its object-cache.php and the WP_CACHE line in wp-config.php (WPOptimizing writes its own back if its page cache is installed). On a network it only does so when no other site still has it active.', 'wpoptimizing' );
	}
	return $msg;
}

/**
 * One change: set $key of our option $option to $value. Pure.
 *
 * @param string $option Our option.
 * @param string $key    Key inside it (for wpoptimizing_core_tweaks: the tweak id).
 * @param mixed  $value  Value (for a tweak: true/false).
 * @param string $from   The other plugin's setting, as its screen names it.
 * @return array{option:string,key:string,value:mixed,from:string}
 */
function wpoptimizing_importer_change( $option, $key, $value, $from ) {
	$lists = wpoptimizing_importer_match_lists();
	if ( isset( $lists[ $option . '.' . $key ] ) && is_array( $value ) ) {
		$value = wpoptimizing_importer_match_list( $value, $lists[ $option . '.' . $key ] );
	}
	return array(
		'option' => (string) $option,
		'key'    => (string) $key,
		'value'  => $value,
		'from'   => (string) $from,
	);
}

/**
 * The lists WPOptimizing matches as plain substrings, and which way a regular expression from
 * the other plugin must be bent to stay safe: an exclusion is widened (excluding more is safe),
 * a «do this too» list is narrowed (doing less is safe). Pure.
 *
 * @return array<string,string> «option.key» => 'exclude' | 'include'.
 */
function wpoptimizing_importer_match_lists() {
	return array(
		'wpoptimizing_asset_optimize.exclude'       => 'exclude',
		'wpoptimizing_asset_optimize.defer_exclude' => 'exclude',
		'wpoptimizing_delay_js.exclude'             => 'exclude',
		'wpoptimizing_frontend_perf.lazy_exclude'   => 'exclude',
		'wpoptimizing_delay_js.include'             => 'include',
	);
}

/**
 * One list of patterns, as WPOptimizing reads them (substrings). Pure.
 *
 * WP Rocket, LiteSpeed and others read these lists as regular expressions. Copied as they were,
 * «/jquery-?[0-9.](.*)(.min)?.js» matched nothing here, so a site that kept jQuery from being
 * deferred had it deferred after the import (seen on Mundotoro's WP Rocket, 07/11/2026). An
 * exclusion becomes its literal start («/jquery-»), which excludes at least what it did; an
 * entry of an «also do this» list that is a real expression is left out.
 *
 * @param array  $list Entries.
 * @param string $way  'exclude' or 'include'.
 * @return string[]
 */
function wpoptimizing_importer_match_list( array $list, $way ) {
	$out = array();
	foreach ( $list as $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry || 1 !== preg_match( '/[()\[\]*?+|^${}\\\\]/', $entry ) ) {
			if ( '' !== $entry ) {
				$out[] = $entry;
			}
			continue;
		}
		$plain = wpoptimizing_importer_pattern( $entry );
		if ( null === $plain && 'exclude' === $way ) {
			$plain = wpoptimizing_importer_literal_start( $entry );
		}
		if ( null !== $plain && strlen( $plain ) >= 3 ) {
			$out[] = $plain;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * The literal beginning of a regular expression, up to its first special character («\.» is a
 * literal dot). Pure.
 *
 * @param string $regex Expression.
 * @return string|null
 */
function wpoptimizing_importer_literal_start( $regex ) {
	$regex = ltrim( (string) $regex, '^' );
	$out   = '';
	$len   = strlen( $regex );
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $regex[ $i ];
		if ( '\\' === $ch && $i + 1 < $len && 1 === preg_match( '/[^A-Za-z0-9]/', $regex[ $i + 1 ] ) ) {
			$out .= $regex[ ++$i ];
			continue;
		}
		if ( false !== strpos( '()[]*?+|^${}\\', $ch ) ) {
			break;
		}
		$out .= $ch;
	}
	return '' === $out ? null : $out;
}

/**
 * One note about a setting that was skipped or only partly translated. Pure.
 *
 * @param string $kind 'skipped' (not imported) | 'review' (imported, check it) | 'info'.
 * @param string $text Sentence for the person.
 * @return array{kind:string,text:string}
 */
function wpoptimizing_importer_note( $kind, $text ) {
	return array(
		'kind' => in_array( $kind, array( 'skipped', 'review', 'info' ), true ) ? $kind : 'info',
		'text' => (string) $text,
	);
}

/**
 * A URL, cookie or user-agent pattern as the plain text our exclusions compare. Pure.
 *
 * The others write regular expressions (`/shop/(.*)`, `^/cart/?$`); we match substrings. The
 * anchors and the catch-alls at either end mean nothing to a substring match, so they go. An
 * optional slash at the end goes WITH the slash: `^/private/?$` matched `/private` too, so it
 * comes in as `/private`, a wider substring (excluding more from the cache is the safe side;
 * keeping the slash would let `/private` be cached). What still has a regex construct after that
 * (an alternation, a class, a quantifier) would match something else as text: it is not
 * imported, and the caller says so.
 *
 * @param string $pattern Pattern.
 * @return string|null Plain text, or null when it cannot be expressed as one.
 */
function wpoptimizing_importer_pattern( $pattern ) {
	$p = trim( (string) $pattern );
	$p = (string) preg_replace( '#^\^#', '', $p );
	$p = (string) preg_replace( '#\$$#', '', $p );
	do {
		$before = $p;
		$p      = (string) preg_replace( '#(\(\.\*\)\??|\.\*\??|\(\.\+\)|\.\+|\*)$#', '', $p );
		$p      = (string) preg_replace( '#^(\(\.\*\)|\.\*|\(\.\+\)|\.\+|\*)#', '', $p );
		$p      = (string) preg_replace( '#\\\\?/\?$#', '', $p );
	} while ( $p !== $before );
	$p = str_replace( array( '\\.', '\\/', '\\-' ), array( '.', '/', '-' ), $p );
	if ( '' === $p ) {
		return null;
	}
	return 1 === preg_match( '#[()\[\]{}|*+?\\\\^$]#', $p ) ? null : $p;
}

/**
 * Translate a list of patterns, noting the ones that could not be. Pure.
 *
 * @param array  $patterns Patterns.
 * @param string $what     What the list is, for the note («Never cache URLs»).
 * @param array  $notes    Notes, by reference.
 * @return string[]
 */
function wpoptimizing_importer_patterns( $patterns, $what, array &$notes ) {
	$out  = array();
	$lost = array();
	foreach ( (array) $patterns as $p ) {
		if ( ! is_scalar( $p ) || '' === trim( (string) $p ) ) {
			continue;
		}
		$plain = wpoptimizing_importer_pattern( (string) $p );
		if ( null === $plain ) {
			$lost[] = (string) $p;
		} else {
			$out[] = $plain;
		}
	}
	if ( $lost ) {
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: 1: setting name, 2: list of patterns. */
				__( '%1$s: these patterns use wildcards or regular expressions in the middle, which WPOptimizing does not read, so they were not imported. Add them again as plain text: %2$s', 'wpoptimizing' ),
				$what,
				implode( ', ', array_slice( $lost, 0, 10 ) ) . ( count( $lost ) > 10 ? ' …' : '' )
			)
		);
	}
	return array_values( array_unique( $out ) );
}

/**
 * A page lifetime, kept inside what a WordPress nonce survives. Pure.
 *
 * Forms and «add to cart» buttons carry nonces that stop working after 12–24 hours; a page
 * cached for longer serves forms that fail. The policy is the page-cache screen's one
 * (wpoptimizing_page_cache_ttl_policy()): a longer lifetime from the other plugin (LiteSpeed
 * ships with 7 days) comes in as 10 hours, 0 (never expires) too, and minutes become whole
 * hours; every conversion is said, so the report never hides one.
 *
 * @param int   $seconds Lifetime in seconds (0 = never).
 * @param array $notes   Notes, by reference.
 * @return int
 */
function wpoptimizing_importer_ttl( $seconds, array &$notes ) {
	$policy = wpoptimizing_page_cache_ttl_policy( $seconds );
	if ( 'long' === $policy['change'] ) {
		$notes[] = wpoptimizing_importer_note(
			'review',
			__( 'Page lifetime: the other plugin kept pages for longer than 12 hours (or for ever). Forms and «add to cart» buttons stop working on pages that old, so it was set to 10 hours.', 'wpoptimizing' )
		);
	} elseif ( 'rounded' === $policy['change'] ) {
		$hours   = (int) ( $policy['ttl'] / HOUR_IN_SECONDS );
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: 1: whole hours set, 2: minutes the other plugin used. */
				_n( 'Page lifetime: WPOptimizing keeps pages for whole hours, from 1 to 12, so the other plugin\'s %2$d minutes became %1$d hour.', 'Page lifetime: WPOptimizing keeps pages for whole hours, from 1 to 12, so the other plugin\'s %2$d minutes became %1$d hours.', $hours, 'wpoptimizing' ),
				$hours,
				max( 1, (int) round( (int) $seconds / MINUTE_IN_SECONDS ) )
			)
		);
	}
	return $policy['ttl'];
}

/**
 * The Heartbeat of each zone, as changes. Pure (with admin-cache.php loaded).
 *
 * The others split it like we do (admin screens, editor, front end); each zone comes as what
 * the other plugin did there: null (not touched: our zone stays as it is), 'default', 'off' or
 * seconds. What we cannot do the same is brought to the nearest safe value, and said: the
 * editor is never switched off (autosave and the edit lock live there), and an interval is kept
 * between 15 seconds and the zone's maximum.
 *
 * @param array  $zones admin|editor|front => null|'default'|'off'|int seconds.
 * @param string $from  The setting's name in the other plugin.
 * @param array  $notes Notes, by reference.
 * @return array wpoptimizing_importer_change() items.
 */
function wpoptimizing_importer_heartbeat( array $zones, $from, array &$notes ) {
	$c       = array();
	$changed = false;
	foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
		if ( ! isset( $zones[ $zone ] ) ) {
			continue;
		}
		$raw = $zones[ $zone ];
		$v   = wpoptimizing_heartbeat_zone_value( $raw, $zone );
		if ( $v !== $raw ) {
			$changed = true;
		}
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', $key, $v, $from );
	}
	if ( $changed ) {
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: one area could not be copied as it was. WPOptimizing never switches the Heartbeat off in the editor, where it autosaves drafts and keeps the edit lock, and keeps intervals between 15 seconds and 120 (editor) or 300 (elsewhere); the nearest of those was set. Check Performance › Server.', 'wpoptimizing' ),
				(string) $from
			)
		);
	}
	return $c;
}

/**
 * Lazy-load exclusions as the fragments ours compares. Pure.
 *
 * Ours matches a fragment inside the image's class or its file URL. The others match a
 * fragment of the whole tag (WP Rocket, Perfmatters, FlyingPress) or of the class or URL
 * (LiteSpeed). A bare fragment (hero.jpg, skip-me) means the same here; `class="hero"` and
 * `src="…"` keep their value; any other attribute (an id, an alt, a data-*) cannot be
 * compared here, is not imported, and the note says which.
 *
 * @param string[] $entries One per line in the other plugin.
 * @param string   $from    The setting's name in the other plugin.
 * @param array    $notes   Notes, by reference.
 * @return string[]
 */
function wpoptimizing_importer_lazy_exclusions( array $entries, $from, array &$notes ) {
	$out  = array();
	$lost = array();
	foreach ( $entries as $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry ) {
			continue;
		}
		if ( preg_match( '/^(?:class|src)\s*=\s*(["\']?)([^"\'<>=]+)\1$/i', $entry, $m ) ) {
			$entry = trim( $m[2] );
		} elseif ( preg_match( '/["\'<>=]/', $entry ) ) {
			$lost[] = $entry;
			continue;
		}
		if ( '' !== $entry ) {
			$out[] = $entry;
		}
	}
	if ( $lost ) {
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: 1: the setting's name in the other plugin, 2: the entries left out. */
				__( '%1$s: WPOptimizing excludes images by a part of their class or file name; these entries name another attribute and were not imported: %2$s', 'wpoptimizing' ),
				(string) $from,
				implode( ', ', $lost )
			)
		);
	}
	return array_values( array_unique( $out ) );
}

/**
 * The task switches of General cleanup (WPOPT_General_Cleanup::get_settings(), the keys that turn
 * a task on or off; limits and ages are not tasks). Kept here so the importers stay pure. Pure.
 *
 * @return string[]
 */
function wpoptimizing_importer_cleanup_task_keys() {
	return array(
		'delete_auto_drafts',
		'delete_trashed_posts',
		'delete_spam_comments',
		'delete_trashed_comments',
		'delete_unapproved_comments',
		'delete_pingbacks',
		'delete_trackbacks',
		'delete_expired_transients',
		'delete_all_transients',
		'delete_oembed_cache',
		'delete_unused_tags',
		'delete_orphan_elements',
		'delete_duplicate_meta',
		'delete_action_scheduler',
		'delete_wc_sessions',
		'delete_wc_orphan_variations',
		'optimize_tables',
	);
}

/**
 * The changes that make a scheduled cleanup run exactly the tasks the other plugin ran. Pure.
 *
 * WHY. Importing a schedule switches on a cleanup that deletes things on its own. Several of
 * our tasks are on by default (auto-drafts, expired transients, table optimisation…) and the
 * person may have ticked others here before: left as they are, a schedule that only emptied
 * spam there would delete much more here (Codex, 06/11/2026). So every task the source did not
 * run is set to 0 explicitly.
 *
 * Revisions have no on/off switch here: the task always runs and keeps `keep_revisions` per
 * post. What really stops a scheduled run from deleting them is that task's own frequency set
 * to «never» (WPOPT_General_Cleanup::is_task_due()), so that is what is written when the source
 * did not delete revisions. When it did, the frequency is left as it is.
 *
 * @param array<string,string> $selected Our task key => the other plugin's setting, for each
 *                                       task the source runs. 'revisions' for revisions.
 * @param string               $from     The other plugin's schedule setting, for the rest.
 * @return array[] wpoptimizing_importer_change() items.
 */
function wpoptimizing_importer_cleanup_tasks( array $selected, $from ) {
	$c = array();
	foreach ( wpoptimizing_importer_cleanup_task_keys() as $key ) {
		$on  = isset( $selected[ $key ] );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', $key, $on ? 1 : 0, $on ? (string) $selected[ $key ] : (string) $from );
	}
	if ( ! isset( $selected['revisions'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_gc_task_schedules', 'revisions', 'never', (string) $from );
	}
	return $c;
}

/**
 * The other plugin's CDN folders as our three scopes. Pure.
 *
 * The others list folders relative to the site root (`wp-content`, `wp-includes`,
 * `wp-content/uploads`). Ours has three scopes: the uploads, the whole content folder (which
 * holds the uploads) and wp-includes. A folder that is none of them (`wp-content/themes`, a
 * custom one) cannot be expressed: sending the whole content folder instead would put files on
 * the CDN that never went there, so the answer is null and the caller does not switch it on.
 *
 * @param string[] $dirs Folders.
 * @return array{uploads:int,content:int,includes:int}|null Null when one cannot be expressed or
 *                                                          there is none.
 */
function wpoptimizing_importer_cdn_dirs( array $dirs ) {
	$content = array( 'wp-content' );
	if ( defined( 'WP_CONTENT_DIR' ) && defined( 'ABSPATH' ) && 0 === strpos( (string) WP_CONTENT_DIR, (string) ABSPATH ) ) {
		$content[] = trim( substr( (string) WP_CONTENT_DIR, strlen( (string) ABSPATH ) ), '/' );
	}
	$uploads = array();
	foreach ( $content as $dir ) {
		$uploads[] = $dir . '/uploads';
	}
	if ( defined( 'UPLOADS' ) ) {
		$uploads[] = trim( (string) UPLOADS, '/' );
	}
	$out = array(
		'uploads'  => 0,
		'content'  => 0,
		'includes' => 0,
	);
	$any = false;
	foreach ( $dirs as $dir ) {
		$dir = trim( trim( (string) $dir ), '/' );
		if ( '' === $dir ) {
			continue;
		}
		$any = true;
		if ( 'wp-includes' === $dir ) {
			$out['includes'] = 1;
		} elseif ( in_array( $dir, $content, true ) ) {
			$out['content'] = 1;
			$out['uploads'] = 1;
		} elseif ( in_array( $dir, $uploads, true ) ) {
			$out['uploads'] = 1;
		} else {
			return null;
		}
	}
	return $any ? $out : null;
}

/**
 * Note for a CDN that was not switched on because the other plugin limited it in a way ours
 * cannot (by folder, by file type, or with exclusions). Pure apart from translations.
 *
 * @param string $from The other plugin's setting.
 * @param string $host The CDN host.
 * @return array{kind:string,text:string}
 */
function wpoptimizing_importer_cdn_restricted_note( $from, $host ) {
	return wpoptimizing_importer_note(
		'review',
		sprintf(
			/* translators: 1: the setting's name in the other plugin, 2: the CDN host. */
			__( '%1$s: not switched on. The other plugin sent only some files to %2$s (by folder, file type or exclusion list), and WPOptimizing\'s CDN cannot be limited that way. If that host can serve every static file of the site, set it up in WPOptimizing\'s CDN settings.', 'wpoptimizing' ),
			(string) $from,
			(string) $host
		)
	);
}

/**
 * Our options with the changes applied over their current values, as an import payload. Pure
 * given $current.
 *
 * @param array $changes wpoptimizing_importer_change() items.
 * @param array $current Current value of each option touched (option => value).
 * @return array Payload for wpoptimizing_import_settings_payload().
 */
function wpoptimizing_importer_payload( array $changes, array $current ) {
	$options = array();
	foreach ( $changes as $c ) {
		$opt = (string) ( $c['option'] ?? '' );
		if ( '' === $opt ) {
			continue;
		}
		if ( ! isset( $options[ $opt ] ) ) {
			$options[ $opt ] = is_array( $current[ $opt ] ?? null ) ? $current[ $opt ] : array();
		}
		if ( 'wpoptimizing_core_tweaks' === $opt ) {
			// A list of enabled tweak ids, not a map.
			$ids = array_values( array_filter( array_map( 'strval', $options[ $opt ] ) ) );
			$ids = array_values( array_diff( $ids, array( (string) $c['key'] ) ) );
			if ( ! empty( $c['value'] ) ) {
				$ids[] = (string) $c['key'];
			}
			$options[ $opt ] = $ids;
			continue;
		}
		if ( '' === (string) $c['key'] ) {
			$options[ $opt ] = $c['value'];
		} else {
			$options[ $opt ][ (string) $c['key'] ] = $c['value'];
		}
	}
	return array(
		'_wpoptimizing_export' => array(
			'plugin' => 'wpoptimizing',
			'source' => 'importer',
		),
		'options'              => $options,
	);
}

/**
 * Import a source: keep the current configuration for «Undo», then apply.
 *
 * @param string    $id                Source id.
 * @param bool|null $can_manage_dropin Whether the person may configure what the whole network
 *                                     shares (see wpoptimizing_import_settings_payload()).
 * @return array{ok:bool,error:string,applied:string[],skipped:string[],notes:array,source:string}
 */
function wpoptimizing_importer_apply( $id, $can_manage_dropin = null ) {
	$out  = array(
		'ok'      => false,
		'error'   => '',
		'applied' => array(),
		'skipped' => array(),
		'notes'   => array(),
		'source'  => (string) $id,
	);
	$plan = wpoptimizing_importer_plan( $id );
	if ( null === $plan ) {
		$out['error'] = 'nothing';
		return $out;
	}
	$out['notes'] = $plan['notes'];
	if ( empty( $plan['changes'] ) ) {
		$out['error'] = 'empty';
		return $out;
	}
	$current = array();
	$created = array();
	foreach ( $plan['changes'] as $c ) {
		$current[ $c['option'] ] = get_option( $c['option'], array() );
		// An option the import will create did not exist: the snapshot cannot hold it, so
		// «Undo» has to delete it (Codex, 06/11/2026: it kept the imported values).
		if ( null === get_option( $c['option'], null ) ) {
			$created[ $c['option'] ] = true;
		}
	}
	if ( function_exists( 'wpoptimizing_export_settings_payload' ) ) {
		update_option(
			WPOPTIMIZING_IMPORTER_UNDO_OPTION,
			array(
				'source'  => (string) $id,
				'when'    => time(),
				'payload' => wpoptimizing_export_settings_payload(),
				'created' => array_keys( $created ),
			),
			false
		);
	}
	$report         = wpoptimizing_import_settings_payload( wpoptimizing_importer_payload( $plan['changes'], $current ), $can_manage_dropin );
	$out['ok']      = ! empty( $report['ok'] );
	$out['error']   = (string) ( $report['error'] ?? '' );
	$out['applied'] = (array) ( $report['applied'] ?? array() );
	$out['skipped'] = (array) ( $report['skipped'] ?? array() );
	if ( function_exists( 'wpoptimizing_audit_log' ) ) {
		wpoptimizing_audit_log(
			'settings_imported_from',
			array(
				'source'  => (string) $id,
				'applied' => $out['applied'],
			)
		);
	}
	return $out;
}

/**
 * Put back the configuration as it was before the last import.
 *
 * @param bool|null $can_manage_dropin See wpoptimizing_importer_apply().
 * @return bool
 */
function wpoptimizing_importer_undo( $can_manage_dropin = null ) {
	$saved = get_option( WPOPTIMIZING_IMPORTER_UNDO_OPTION );
	if ( ! is_array( $saved ) || ! isset( $saved['payload'] ) ) {
		return false;
	}
	$report = wpoptimizing_import_settings_payload( $saved['payload'], $can_manage_dropin );
	$gone   = array();
	foreach ( (array) ( $saved['created'] ?? array() ) as $opt ) {
		$opt = (string) $opt;
		if ( 0 === strpos( $opt, 'wpoptimizing_' ) && function_exists( 'wpoptimizing_is_portable_option' ) && wpoptimizing_is_portable_option( $opt ) ) {
			delete_option( $opt );
			$gone[] = $opt;
		}
	}
	delete_option( WPOPTIMIZING_IMPORTER_UNDO_OPTION );
	if ( $gone ) {
		// The restore above told the page cache, .htaccess and the rest about what it wrote,
		// before these went: without a second word they kept following the deleted options
		// (Codex, 06/11/2026).
		do_action( 'wpoptimizing_settings_imported', $gone );
	}
	return ! empty( $report['ok'] ) || ! empty( $gone );
}
