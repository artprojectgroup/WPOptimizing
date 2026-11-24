<?php
/**
 * Import source: SiteGround Speed Optimizer.
 *
 * One loose option per setting, `siteground_optimizer_<key>` (per site): 1/0 switches and
 * serialized arrays. A switch that was never saved is simply absent, which SiteGround reads as
 * off. Its own export (base64 JSON) leaves out the file cache, the excluded URLs and the
 * Heartbeat intervals, so the options are read one by one. Keys read in Speed Optimizer 7.8.6
 * (docs/cache-2026-11-06/importadores-A.md §6).
 *
 * On SiteGround's hosting, «Dynamic Caching» is their NGINX cache and «Memcached» runs against
 * their own socket: both belong to the hosting, so they are explained, never translated into
 * our page cache or object cache (importadores-A.md §0.1).
 *
 * Never imported: there are no credentials among these options; the Cloudflare switch only
 * works on their hosting and is not read.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The live configuration: every option that exists, without its prefix.
 *
 * @return array|null
 */
function wpoptimizing_import_src_siteground_read() {
	$keys = array(
		'enable_cache',
		'enable_memcached',
		'file_caching',
		'file_caching_interval_cleanup',
		'logged_in_cache',
		'user_agent_header',
		'preheat_cache',
		'excluded_urls',
		'post_types_exclude',
		'enable_gzip_compression',
		'enable_browser_caching',
		'optimize_css',
		'minify_css_exclude',
		'combine_css',
		'optimize_javascript',
		'minify_javascript_exclude',
		'combine_javascript',
		'optimize_javascript_async',
		'async_javascript_exclude',
		'optimize_html',
		'remove_query_strings',
		'disable_emojis',
		'lazyload_images',
		'excluded_lazy_load_media_types',
		'excluded_lazy_load_classes',
		'webp_support',
		'resize_images',
		'database_optimization',
		'heartbeat_post_interval',
		'heartbeat_dashboard_interval',
		'heartbeat_frontend_interval',
		'dns_prefetch',
		'dns_prefetch_urls',
		'optimize_web_fonts',
		'fonts_preload_urls',
	);
	$out  = array();
	foreach ( $keys as $key ) {
		$value = get_option( 'siteground_optimizer_' . $key, null );
		if ( null !== $value ) {
			$out[ $key ] = $value;
		}
	}
	return $out ? $out : null;
}

/**
 * Translate it. Pure apart from translations.
 *
 * Switches come as 1/0, "1"/"0" or are absent; a present switch is imported on or off, an
 * absent one is left alone.
 *
 * @param array $r Options without the `siteground_optimizer_` prefix.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_siteground_map( array $r ) {
	$c     = array();
	$notes = array();
	$on    = static function ( $key ) use ( $r ) {
		return isset( $r[ $key ] ) && 1 === (int) $r[ $key ];
	};
	$list  = static function ( $key ) use ( $r ) {
		return array_values( array_filter( array_map( 'strval', array_filter( (array) ( $r[ $key ] ?? array() ), 'is_scalar' ) ), 'strlen' ) );
	};

	// The hosting's own caches: said, not translated.
	if ( $on( 'enable_cache' ) ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'Dynamic Caching: this is SiteGround\'s own server cache and only works on their hosting, so it was not turned into WPOptimizing\'s page cache. If the site is hosted at SiteGround, keep it on in their Site Tools.', 'wpoptimizing' ) );
	}
	if ( $on( 'enable_memcached' ) ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'Memcached: it runs on SiteGround\'s own Memcached service, so it was not turned into WPOptimizing\'s object cache. If the site is hosted at SiteGround, keep it on in their Site Tools.', 'wpoptimizing' ) );
	}

	// Page cache: the file cache's preferences, only when it was in use.
	if ( $on( 'file_caching' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'info',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: the import does not switch on WPOptimizing\'s page cache. Deactivate the other plugin first, then switch it on in Cache › Page cache.', 'wpoptimizing' ),
				__( 'File-Based Caching', 'wpoptimizing' )
			)
		);
		if ( isset( $r['file_caching_interval_cleanup'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $r['file_caching_interval_cleanup'], $notes ), __( 'Scheduled cache cleanup', 'wpoptimizing' ) );
		}
		if ( isset( $r['user_agent_header'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'user_agent_header' ), __( 'Browser-Specific Caching', 'wpoptimizing' ) );
		}
		if ( isset( $r['preheat_cache'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( 'preheat_cache' ), __( 'Preheat Cache', 'wpoptimizing' ) );
		}
	}
	// Paths with * as the wildcard, anchored to the home URL: as a pattern, * is .*.
	$uri = wpoptimizing_importer_patterns(
		array_map(
			static function ( $p ) {
				return str_replace( '*', '.*', $p );
			},
			$list( 'excluded_urls' )
		),
		__( 'Exclude URLs from Caching', 'wpoptimizing' ),
		$notes
	);
	if ( $uri ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Exclude URLs from Caching', 'wpoptimizing' ) );
	}
	if ( $on( 'logged_in_cache' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ),
				__( 'Logged-in Users Cache', 'wpoptimizing' )
			)
		);
	}

	// Browser cache and compression: a block in .htaccess, switched on from our own screen.
	if ( $on( 'enable_gzip_compression' ) || $on( 'enable_browser_caching' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the settings' names in the other plugin. */
				__( '%s: not imported. Switch on the browser cache and compression in Cache › Browser & server once the other plugin is deactivated, because deactivating it can remove those rules from .htaccess.', 'wpoptimizing' ),
				__( 'GZIP Compression, Browser Caching', 'wpoptimizing' )
			)
		);
	}

	// CSS and JavaScript.
	foreach ( array(
		'optimize_css'        => array( 'minify_css', __( 'Minify CSS Files', 'wpoptimizing' ) ),
		'optimize_javascript' => array( 'minify_js', __( 'Minify JavaScript Files', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $to[0], $on( $key ), $to[1] );
		}
	}
	$exclude = array_merge( $list( 'minify_css_exclude' ), $list( 'minify_javascript_exclude' ) );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $exclude ) ), __( 'Exclude from CSS and JavaScript Minification', 'wpoptimizing' ) );
	}
	if ( isset( $r['optimize_javascript_async'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', $on( 'optimize_javascript_async' ), __( 'Defer Render-blocking JS', 'wpoptimizing' ) );
		// Its install routines fill this list on every site; it only means something when on.
		if ( $on( 'optimize_javascript_async' ) && $list( 'async_javascript_exclude' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', $list( 'async_javascript_exclude' ), __( 'Exclude from Loading JS Files Asynchronously', 'wpoptimizing' ) );
		}
	}
	foreach ( array(
		'combine_css'        => __( 'Combine CSS Files', 'wpoptimizing' ),
		'combine_javascript' => __( 'Combine JavaScript Files', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( $key ) ) {
			$notes[] = wpoptimizing_importer_note(
				'skipped',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ),
					$label
				)
			);
		}
	}
	$none = array();
	if ( $on( 'optimize_html' ) ) {
		$none[] = __( 'Minify the HTML Output', 'wpoptimizing' );
	}
	if ( $on( 'remove_query_strings' ) ) {
		$none[] = __( 'Remove Query Strings From Static Resources', 'wpoptimizing' );
	}
	if ( $list( 'post_types_exclude' ) ) {
		$none[] = __( 'Exclude Post Types', 'wpoptimizing' );
	}

	// Core tweaks.
	if ( isset( $r['disable_emojis'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( 'disable_emojis' ), __( 'Disable Emojis', 'wpoptimizing' ) );
	}

	// Media.
	if ( isset( $r['lazyload_images'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( 'lazyload_images' ), __( 'Lazy Load Media', 'wpoptimizing' ) );
	}
	// skip-lazy is what its install routine puts there (and ours respects it anyway); any other
	// class was the person's choice, matched as a whole class there (Abstract_Lazy_Load.php:62-72)
	// and as a fragment of the class here, which covers it.
	$classes = array_values( array_diff( $list( 'excluded_lazy_load_classes' ), array( 'skip-lazy' ) ) );
	if ( $on( 'lazyload_images' ) && $classes ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_exclude', wpoptimizing_importer_lazy_exclusions( $classes, __( 'Exclude from Lazy Load', 'wpoptimizing' ), $notes ), __( 'Exclude from Lazy Load', 'wpoptimizing' ) );
	}
	if ( $on( 'lazyload_images' ) && $list( 'excluded_lazy_load_media_types' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing excludes images by their own class or file name only.', 'wpoptimizing' ), __( 'Exclude from Lazy Load', 'wpoptimizing' ) )
		);
	}
	if ( $on( 'webp_support' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_image_settings', 'nextgen_on_upload', true, __( 'Generate WebP Copies of New Images', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_image_settings', 'serve_nextgen', true, __( 'Generate WebP Copies of New Images', 'wpoptimizing' ) );
	}
	if ( isset( $r['resize_images'] ) && is_scalar( $r['resize_images'] ) ) {
		$width = (int) $r['resize_images'];
		// Its own floor: anything under 1200 is resized to 1200.
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_image_settings', 'resize_uploads', $width > 0, __( 'Maximum Image Width', 'wpoptimizing' ) );
		if ( $width > 0 ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_image_settings', 'max_width', max( 1200, $width ), __( 'Maximum Image Width', 'wpoptimizing' ) );
		}
	}

	// Fonts and early connections.
	if ( isset( $r['optimize_web_fonts'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', $on( 'optimize_web_fonts' ), __( 'Web Fonts Optimization', 'wpoptimizing' ) );
		// The preload list is only printed by its fonts optimizer.
		if ( $on( 'optimize_web_fonts' ) && $list( 'fonts_preload_urls' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Fonts Preloading', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', $list( 'fonts_preload_urls' ), __( 'Fonts Preloading', 'wpoptimizing' ) );
		}
	}
	if ( $on( 'dns_prefetch' ) && $list( 'dns_prefetch_urls' ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'DNS Pre-fetch for External Domains', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ),
				__( 'DNS Pre-fetch for External Domains', 'wpoptimizing' )
			)
		);
	}

	// Database: a list of the tasks its weekly maintenance runs.
	$methods = $list( 'database_optimization' );
	if ( $methods ) {
		// Its weekly run does exactly the ticked methods (Database_Optimizer.php:63-77): every
		// other task here, revisions included, is left off.
		$selected = array();
		foreach ( array(
			'delete_auto_drafts'    => array( 'delete_auto_drafts', __( 'Auto drafts', 'wpoptimizing' ) ),
			'delete_trashed_posts'  => array( 'delete_trashed_posts', __( 'Trashed posts', 'wpoptimizing' ) ),
			'delete_spam_comments'  => array( 'delete_spam_comments', __( 'Spam comments', 'wpoptimizing' ) ),
			'delete_trash_comments' => array( 'delete_trashed_comments', __( 'Trashed comments', 'wpoptimizing' ) ),
			'expired_transients'    => array( 'delete_expired_transients', __( 'Expired transients', 'wpoptimizing' ) ),
			'optimize_tables'       => array( 'optimize_tables', __( 'Optimize tables', 'wpoptimizing' ) ),
		) as $method => $to ) {
			if ( in_array( $method, $methods, true ) ) {
				$selected[ $to[0] ] = $to[1];
			}
		}
		if ( in_array( 'delete_revisions', $methods, true ) ) {
			$selected['revisions'] = __( 'Delete all page and post revisions', 'wpoptimizing' );
		}
		$c   = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, __( 'Scheduled Database Maintenance', 'wpoptimizing' ) ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', 'weekly', __( 'Scheduled Database Maintenance', 'wpoptimizing' ) );
		if ( in_array( 'delete_revisions', $methods, true ) ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: the other plugin deletes every revision; WPOptimizing keeps the last ones of each post, as set in General cleanup.', 'wpoptimizing' ),
					__( 'Delete all page and post revisions', 'wpoptimizing' )
				)
			);
		}
	}

	// Heartbeat: the same three zones (core/Heartbeat_Control/Heartbeat_Control.php): an option
	// that is absent leaves the zone alone, 0 removes the script, more than 1 is the interval.
	$zones = array();
	foreach ( array(
		'admin'  => 'heartbeat_dashboard_interval',
		'editor' => 'heartbeat_post_interval',
		'front'  => 'heartbeat_frontend_interval',
	) as $zone => $key ) {
		if ( isset( $r[ $key ] ) && is_scalar( $r[ $key ] ) && false !== $r[ $key ] && '' !== $r[ $key ] ) {
			$n              = (int) $r[ $key ];
			$zones[ $zone ] = 0 === $n ? 'off' : ( $n > 1 ? $n : 'default' );
		}
	}
	if ( $zones ) {
		$c = array_merge( $c, wpoptimizing_importer_heartbeat( $zones, __( 'WordPress Heartbeat Optimization', 'wpoptimizing' ), $notes ) );
	}

	if ( $none ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: list of settings in the other plugin. */
				__( 'No equivalent in WPOptimizing, not imported: %s.', 'wpoptimizing' ),
				implode( ', ', $none )
			)
		);
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
