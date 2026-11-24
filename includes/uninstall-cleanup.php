<?php
/**
 * Complete-uninstall purge: removes every trace WPOptimizing leaves behind so the
 * site is returned to a pristine state — options, transients, post meta, scheduled
 * events, the object-cache/db drop-ins, the runtime mu-plugin, the cache sidecar/
 * store, all backups and scan CSVs, and the WebP/AVIF copies the plugin generated.
 *
 * Loaded by uninstall.php (which runs standalone, WITHOUT the plugin bootstrapped),
 * so everything here computes its own paths from WP core constants/functions and
 * never depends on the plugin's own constants. The pure helpers are unit-testable.
 *
 * Per product decision: optimized originals are KEPT (the site keeps the smaller
 * images); only the EXTRA next-gen copies and the backups are removed. Originals are
 * not restored.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron hooks the plugin may have scheduled (cleared per-site).
 *
 * @return string[]
 */
function wpoptimizing_uninstall_cron_hooks() {
	return array(
		'wpoptimizing_after_cleanup',
		'wpoptimizing_autoclean_check',
		'wpoptimizing_cron_scan',
		'wpoptimizing_cron_scan_step',
		'wpoptimizing_data_flush',
		'wpoptimizing_general_cleanup_cron',
		'wpoptimizing_general_cleanup_resume',
		'wpoptimizing_gfonts_prime',
		'wpoptimizing_history_prune',
		'wpoptimizing_kb_sync_cron',
		'wpoptimizing_license_heartbeat',
		'wpoptimizing_page_cache_gc',
		'wpoptimizing_page_cache_text_refresh',
		'wpoptimizing_page_cache_gc_hourly',
		'wpoptimizing_page_cache_preload',
		'wpoptimizing_attr_suggest_auto',
		'wpoptimizing_ccss_auto_check',
		'wpoptimizing_ccss_auto_hourly',
		'wpoptimizing_ccss_force',
		'wpoptimizing_maintenance_report',
		'wpoptimizing_manual_attribution_added',
		'wpoptimizing_manual_lead_flush',
		'wpoptimizing_manual_lead_send',
		'wpoptimizing_network_alert_check',
		'wpoptimizing_rebuild_tables',
		'wpoptimizing_refresh_csv_cache',
		'wpoptimizing_resolve_ext_names',
		'wpoptimizing_scan_cron',
		'wpoptimizing_unified_daily',
		'wpoptimizing_update_token_refresh',
	);
}

/**
 * Post-meta keys the plugin writes (removed site-wide).
 *
 * @return string[]
 */
function wpoptimizing_uninstall_post_meta_keys() {
	return array( '_wpopt_keep_revisions', '_wpopt_assets_auto_exempt', '_wpopt_assets_page_disable' );
}

/**
 * Whether a drop-in file header carries our signature (so we only ever delete OUR
 * object-cache.php / db.php, never another plugin's). Pure.
 *
 * @param string $head   First bytes of the file.
 * @param string $marker Signature marker, e.g. "WPOPT_OBJECT_CACHE".
 * @return bool
 */
function wpoptimizing_uninstall_is_our_dropin( $head, $marker ) {
	return '' !== (string) $marker && false !== strpos( (string) $head, (string) $marker );
}

/**
 * Guard for the recursive deletion: a path is safe to remove only when it is
 * STRICTLY inside wp-content and is not one of the major base directories. Pure
 * (apart from reading WP_CONTENT_DIR / the uploads base, which are stable).
 *
 * @param string $path Absolute path proposed for deletion.
 * @return bool
 */
function wpoptimizing_uninstall_path_is_safe( $path ) {
	$path = (string) $path;
	if ( '' === $path || false !== strpos( $path, '..' ) || false !== strpos( $path, "\0" ) ) {
		return false;
	}
	$norm = untrailingslashit( wp_normalize_path( $path ) );
	$root = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	if ( '' === $norm || $norm === $root ) {
		return false;
	}
	// Must live strictly under wp-content/.
	if ( 0 !== strpos( $norm . '/', $root . '/' ) ) {
		return false;
	}
	$uploads = '';
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$u       = wp_get_upload_dir();
		$uploads = isset( $u['basedir'] ) ? untrailingslashit( wp_normalize_path( (string) $u['basedir'] ) ) : '';
	}
	$forbidden = array_filter(
		array(
			$root,
			$root . '/plugins',
			$root . '/themes',
			$root . '/mu-plugins',
			defined( 'WPMU_PLUGIN_DIR' ) ? untrailingslashit( wp_normalize_path( WPMU_PLUGIN_DIR ) ) : '',
			defined( 'WP_PLUGIN_DIR' ) ? untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) ) : '',
			$root . '/uploads',
			$root . '/cache',
			$uploads,
		)
	);
	return ! in_array( $norm, $forbidden, true );
}

/**
 * Recursively delete a directory, refusing any path the safety guard rejects and
 * never following symlinks.
 *
 * @param string $dir Directory to remove.
 * @return void
 */
function wpoptimizing_uninstall_rrmdir( $dir ) {
	if ( ! wpoptimizing_uninstall_path_is_safe( $dir ) || ! is_dir( $dir ) || is_link( $dir ) ) {
		return;
	}
	$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$path = $dir . '/' . $item;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			wpoptimizing_uninstall_rrmdir( $path );
		} else {
			wp_delete_file( $path );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

/**
 * The plugin data directory for the CURRENT blog (mirrors wpoptimizing_blog_data_dir).
 *
 * @return string
 */
function wpoptimizing_uninstall_blog_data_dir() {
	$base = WP_CONTENT_DIR . '/wpoptimizing';
	if ( function_exists( 'is_multisite' ) && is_multisite()
		&& function_exists( 'is_main_site' ) && ! is_main_site() ) {
		$base .= '/sites/' . (int) get_current_blog_id();
	}
	return $base;
}

/**
 * Delete the WebP/AVIF copies the plugin generated for the current blog, using the
 * authoritative generated-logs (each line is a path relative to the uploads base),
 * so we never touch next-gen images created by the user or another plugin.
 *
 * @param string $data_dir Plugin data dir holding the logs.
 * @return void
 */
function wpoptimizing_uninstall_delete_generated_images( $data_dir ) {
	if ( ! function_exists( 'wp_get_upload_dir' ) ) {
		return;
	}
	$u    = wp_get_upload_dir();
	$base = isset( $u['basedir'] ) ? untrailingslashit( wp_normalize_path( (string) $u['basedir'] ) ) : '';
	if ( '' === $base ) {
		return;
	}
	foreach ( array( 'webp', 'avif' ) as $fmt ) {
		$log = $data_dir . '/image-' . $fmt . '-generated.log';
		if ( ! is_file( $log ) ) {
			continue;
		}
		$lines = @file( $log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file
		if ( ! is_array( $lines ) ) {
			continue;
		}
		foreach ( $lines as $rel ) {
			$rel = ltrim( trim( (string) $rel ), '/\\' );
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				continue;
			}
			$abs = wp_normalize_path( $base . '/' . $rel );
			// Stay inside uploads and only remove next-gen derivatives.
			if ( 0 !== strpos( $abs, $base . '/' ) ) {
				continue;
			}
			$ext = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
			if ( ( 'webp' === $ext || 'avif' === $ext ) && is_file( $abs ) ) {
				wp_delete_file( $abs );
			}
		}
	}
}

/**
 * Take our WebP/AVIF block out of the uploads .htaccess. Pure on the contents.
 *
 * Once the plugin is gone its copies are deleted too, and the rules (which only answer with a
 * copy that exists) would be left with nothing to do; they still should not outlive it.
 *
 * @param string $contents File contents.
 * @return string
 */
function wpoptimizing_uninstall_strip_nextgen_block( $contents ) {
	$out = preg_replace( '/\n*# BEGIN WPOptimizing WebP AVIF.*?# END WPOptimizing WebP AVIF[^\n]*\n?/s', "\n", (string) $contents );
	return null === $out ? (string) $contents : ltrim( $out, "\n" );
}

/**
 * Apply wpoptimizing_uninstall_strip_nextgen_block() to this blog's uploads .htaccess.
 *
 * @return void
 */
function wpoptimizing_uninstall_remove_nextgen_rules() {
	if ( ! function_exists( 'wp_get_upload_dir' ) ) {
		return;
	}
	$u    = wp_get_upload_dir();
	$file = empty( $u['basedir'] ) ? '' : trailingslashit( (string) $u['basedir'] ) . '.htaccess';
	if ( '' === $file || ! is_file( $file ) || ! wp_is_writable( $file ) ) {
		return;
	}
	$old = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$new = wpoptimizing_uninstall_strip_nextgen_block( $old );
	if ( $new === $old ) {
		return;
	}
	if ( '' === trim( $new ) ) {
		wp_delete_file( $file );
		return;
	}
	file_put_contents( $file, $new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}

/**
 * Purge everything stored for the CURRENT blog: options + transients, post meta,
 * scheduled events, the generated next-gen copies and the whole plugin data dir.
 *
 * @return void
 */
function wpoptimizing_uninstall_purge_blog() {
	global $wpdb;

	// Options + our transients (this blog's options table). Direct, uncached deletes are
	// the only sensible approach when the plugin is being removed entirely.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'wpoptimizing_' ) . '%' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_wpoptimizing' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wpoptimizing' ) . '%',
			$wpdb->esc_like( '_transient_wpopt_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_wpopt_' ) . '%',
			// Every wpopt_* option, not a list of names: the list held two of the three runtime-
			// map options and left wpopt_rtmap_repair_wpdb behind on every uninstall.
			$wpdb->esc_like( 'wpopt_' ) . '%'
		)
	);

	// Post meta written by the plugin. The %s placeholders are built dynamically from a
	// fixed, internal key list (never user input), so the prepare is sound.
	$keys         = wpoptimizing_uninstall_post_meta_keys();
	$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)", $keys ) );

	// User meta written by the plugin (e.g. the per-user free-mode notice dismissal flag).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpoptimizing_' ) . '%' ) );

	// Scheduled events (per-site).
	// wp_unschedule_hook(), not wp_clear_scheduled_hook(): the latter only removes the events
	// scheduled without arguments, and the scan event carries its reason as one.
	if ( function_exists( 'wp_unschedule_hook' ) ) {
		foreach ( wpoptimizing_uninstall_cron_hooks() as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	// Generated next-gen copies, then the whole plugin data dir for this blog.
	$data_dir = wpoptimizing_uninstall_blog_data_dir();
	wpoptimizing_uninstall_delete_generated_images( $data_dir );
	wpoptimizing_uninstall_remove_nextgen_rules();
	wpoptimizing_uninstall_rrmdir( $data_dir );

	// Self-hosted Google Fonts cache (downloaded CSS + woff2) lives in uploads.
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$gf_up = wp_get_upload_dir();
		if ( ! empty( $gf_up['basedir'] ) ) {
			$gf_dir = untrailingslashit( wp_normalize_path( (string) $gf_up['basedir'] ) ) . '/wpoptimizing-fonts';
			if ( wpoptimizing_uninstall_path_is_safe( $gf_dir ) && is_dir( $gf_dir ) ) {
				wpoptimizing_uninstall_rrmdir( $gf_dir );
			}
		}
	}
}

/**
 * Purge network/global artifacts once: drop-ins (only if ours), the runtime
 * mu-plugin, our files inside wp-content/cache, and network-level options.
 *
 * @return void
 */
/**
 * The drop-ins we install, with the signature that proves a file is ours, by kind.
 *
 * @return array<string,array{file:string,marker:string}>
 */
function wpoptimizing_uninstall_dropins() {
	return array(
		'object' => array(
			'file'   => WP_CONTENT_DIR . '/object-cache.php',
			'marker' => 'WPOPT_OBJECT_CACHE',
		),
		'db'     => array(
			'file'   => WP_CONTENT_DIR . '/db.php',
			'marker' => 'WPOPT_DB_DROPIN',
		),
		'page'   => array(
			'file'   => WP_CONTENT_DIR . '/advanced-cache.php',
			'marker' => 'WPOPT_PAGE_CACHE',
		),
	);
}

/**
 * Names of the wp-config.php blocks we manage. The owners are wpoptimizing_page_cache_block_name()
 * and wpoptimizing_wp_cron_block_name(); this runs without the plugin loaded, so the names are
 * spelled here and a test holds them equal.
 *
 * Without the plugin, WP_CACHE only loads an advanced-cache.php that is gone or somebody else's,
 * and a DISABLE_WP_CRON nobody remembers switches the site's scheduled tasks off for good.
 *
 * @return string[]
 */
function wpoptimizing_uninstall_wpconfig_blocks() {
	return array( 'Page-Cache', 'WP-Cron' );
}

/**
 * Remove everything of ours that EXECUTES: the drop-ins (only when they carry our signature,
 * putting back an original we had replaced), the must-use plugins and our wp-config.php blocks.
 *
 * Runs on every uninstall, "keep my data" included. Keeping data means options and backups; it
 * cannot mean code that goes on running every request after the plugin is gone — an
 * advanced-cache.php serving pages nobody purges, an object cache nobody configures, or a
 * DISABLE_WP_CRON that stops scheduled tasks for ever. Must run before the per-site purge, which
 * deletes the backups the restore reads.
 *
 * @return void
 */
function wpoptimizing_uninstall_remove_executables() {
	if ( ! defined( 'WPOPT_TEARDOWN' ) ) {
		define( 'WPOPT_TEARDOWN', true ); // Our own blocks come out even if their backup cannot be written: see wpoptimizing_wpconfig_apply_block().
	}
	$owner = __DIR__ . '/dropin-owner.php';
	if ( ! function_exists( 'wpoptimizing_dropin_restore' ) && is_readable( $owner ) ) {
		require_once $owner;
	}
	foreach ( wpoptimizing_uninstall_dropins() as $kind => $dropin ) {
		$file = $dropin['file'];
		if ( is_file( $file ) ) {
			$head = (string) @file_get_contents( $file, false, null, 0, 1024 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
			if ( wpoptimizing_uninstall_is_our_dropin( $head, $dropin['marker'] ) ) {
				wp_delete_file( $file );
				if ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
			}
		}
		if ( function_exists( 'wpoptimizing_dropin_restore' ) ) {
			wpoptimizing_dropin_restore( $kind ); // A no-op unless ours had replaced one.
		}
	}

	// Runtime mu-plugin.
	$mu = WPMU_PLUGIN_DIR . '/apg-wpoptimizing-runtime.php';
	if ( is_file( $mu ) ) {
		wp_delete_file( $mu );
	}
	// Plugins-by-URL mu-plugin (Pro), only when it is ours.
	$mu = WPMU_PLUGIN_DIR . '/apg-wpoptimizing-plugin-rules.php';
	if ( is_file( $mu ) && false !== strpos( (string) @file_get_contents( $mu, false, null, 0, 2048 ), 'APG_WPOPT_PLUGIN_RULES' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
		wp_delete_file( $mu );
	}

	// Our wp-config.php blocks, through the same verified editor that wrote them.
	foreach ( array( 'storage/atomic-write.php', 'wpconfig-block.php' ) as $lib ) {
		if ( is_readable( __DIR__ . '/' . $lib ) ) {
			require_once __DIR__ . '/' . $lib;
		}
	}
	if ( function_exists( 'wpoptimizing_wpconfig_apply_block' ) && function_exists( 'wpoptimizing_atomic_tmp_path' ) ) {
		foreach ( wpoptimizing_uninstall_wpconfig_blocks() as $name ) {
			wpoptimizing_wpconfig_apply_block( $name, '', false ); // The body is only read when adding.
		}
	}
}

/**
 * Purge network/global artifacts once: our files inside wp-content/cache, the old wp-config
 * copy and network-level options. What executes went first (wpoptimizing_uninstall_remove_executables()).
 *
 * @return void
 */
function wpoptimizing_uninstall_purge_global() {
	global $wpdb;

	// Plain-text copy of wp-config.php that earlier versions left in the web root.
	foreach ( array( ABSPATH, trailingslashit( dirname( ABSPATH ) ) ) as $dir ) {
		if ( is_file( $dir . 'wp-config.php.wpopt-bak' ) && ! is_link( $dir . 'wp-config.php.wpopt-bak' ) ) {
			wp_delete_file( $dir . 'wp-config.php.wpopt-bak' );
		}
	}

	// Our own files inside the SHARED wp-content/cache dir (never the dir itself).
	$cache = WP_CONTENT_DIR . '/cache';
	if ( is_dir( $cache ) ) {
		$ours = @glob( $cache . '/wpopt-*' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $ours ) ) {
			foreach ( $ours as $path ) {
				if ( is_file( $path ) ) {
					wp_delete_file( $path );
				} elseif ( is_dir( $path ) ) {
					wpoptimizing_uninstall_rrmdir( $path );
				}
			}
		}
	}

	// The rules that served cached pages without PHP (Pro's page-cache-static.php), by their
	// marker: the cache folder they point at is gone above, and a site that went from Pro to
	// Lite has no code left to take them out.
	if ( ! function_exists( 'get_home_path' ) && is_readable( ABSPATH . 'wp-admin/includes/file.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	// get_home_path(), WordPress's own answer for where .htaccess lives (it may not be ABSPATH).
	$htaccess = function_exists( 'get_home_path' ) ? get_home_path() . '.htaccess' : '';
	if ( '' !== $htaccess && is_file( $htaccess ) && is_writable( $htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		$old = (string) file_get_contents( $htaccess ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$new = (string) preg_replace( '/# BEGIN WPOptimizing Page Cache.*?# END WPOptimizing Page Cache\R(\R)?/s', '', $old );
		if ( $new !== $old ) {
			file_put_contents( $htaccess, $new, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Uninstall: taking our own block out.
		}
	}

	// Network-level options (multisite).
	if ( function_exists( 'is_multisite' ) && is_multisite() && isset( $wpdb->sitemeta ) ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", $wpdb->esc_like( 'wpoptimizing_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}

/**
 * Run the full purge across the install (every blog on multisite + global artifacts).
 *
 * @return void
 */
function wpoptimizing_uninstall_run() {
	// First, while the drop-in backups and the option that lists them still exist.
	wpoptimizing_uninstall_remove_executables();
	if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_sites' ) ) {
		$sites = get_sites(
			array(
				'number' => 0,
				'fields' => 'ids',
			)
		);
		foreach ( (array) $sites as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			wpoptimizing_uninstall_purge_blog();
			restore_current_blog();
		}
	} else {
		wpoptimizing_uninstall_purge_blog();
	}
	wpoptimizing_uninstall_purge_global();
}
