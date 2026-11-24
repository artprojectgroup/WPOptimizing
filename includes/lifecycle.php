<?php
/**
 * Install, activation, deactivation and update plumbing.
 *
 * None of this can run on a visitor request: activating, deactivating, updating or deleting a
 * plugin all happen in wp-admin or through WP-CLI, and the self-healing of the data directory
 * runs on admin_init. So the bodies live here, behind wpoptimizing_load_engine(), and only the
 * registrations stay in the main file — register_activation_hook() needs __FILE__ to be the
 * plugin file, and a string callback does not need its function to exist until the hook fires.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create wp-content/wpoptimizing and the files that keep it out of reach of the web.
 *
 * This used to run at the top of this file, so every request — including every anonymous
 * front-end page view — paid one is_dir() and three file_exists() calls to confirm that four
 * files which are written once still exist. Same rule as the object-cache sidecar next door:
 * self-healing belongs on admin_init, never on the visitor's path.
 *
 * Activation runs it before the site can serve a single request with the plugin on, so the
 * directory is protected from the moment it can contain anything. The admin_init pass exists
 * for the case where somebody deletes the files afterwards. The writers that create the
 * directory (blob store, profiler) already wp_mkdir_p() it themselves, so nothing depends on
 * this having run first.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_ensure_data_dir_protection() {
	if ( ! defined( 'WPOPT_DIR' ) ) {
		return;
	}
	if ( ! is_dir( WPOPT_DIR ) ) {
		wp_mkdir_p( WPOPT_DIR );
	}

	$htaccess = WPOPT_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			. "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess, $rules, LOCK_EX );
	}

	$index = WPOPT_DIR . '/index.php';
	if ( ! file_exists( $index ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
	}

	// IIS equivalent of the deny rule (Apache/LiteSpeed use the .htaccess above; on
	// nginx neither is read, so the only data we download is stored ENCRYPTED — see
	// the protected/ drop below — and is useless without this install's token).
	$webconfig = WPOPT_DIR . '/web.config';
	if ( ! file_exists( $webconfig ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n", LOCK_EX );
	}
}

/**
 * Remove the manage_wpoptimizing capability from all roles.
 * Called on plugin deactivation.
 *
 * @return void
 */
function wpoptimizing_revoke_cap() {
	global $wp_roles;
	if ( ! isset( $wp_roles ) ) {
		$wp_roles = new WP_Roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Standard WP idiom to lazily initialise the roles global before editing capabilities.
	}
	foreach ( array_keys( $wp_roles->roles ) as $role_slug ) {
		$role = get_role( $role_slug );
		if ( $role ) {
			$role->remove_cap( wpoptimizing_cap() );
		}
	}
}

/**
 * Plugin action links (Settings).
 *
 * @param string[] $links Existing links.
 * @return string[]
 */
function wpoptimizing_plugin_action_links( $links ) {
	global $wpoptimizing_info;

	$custom_links = array(
		'<a href="' . esc_url( admin_url( 'admin.php?page=wpoptimizing-settings' ) ) . '" title="' . esc_attr__( 'Settings for ', 'wpoptimizing' ) . esc_attr( $wpoptimizing_info['plugin'] ) . '">' . esc_html__( 'Settings', 'wpoptimizing' ) . '</a>',
	);

	foreach ( $custom_links as $custom_link ) {
		array_unshift( $links, $custom_link );
	}

	return $links;
}

/**
 * Plugin activation hook.
 *
 * On a network-wide multisite activation, set up every existing subsite so each
 * one gets the capability grant, its own data directory and the first-run flag.
 *
 * @param bool $network_wide True when activated network-wide on multisite.
 * @return void
 */
function wpoptimizing_activate( $network_wide = false ) {
	if ( is_multisite() && $network_wide ) {
		$site_ids = get_sites(
			array(
				'number' => 0,
				'fields' => 'ids',
			)
		);
		foreach ( $site_ids as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			wpoptimizing_activate_single_site();
			restore_current_blog();
		}
		return;
	}
	wpoptimizing_activate_single_site();
}

/**
 * Plugin deactivation hook.
 * Generates and uploads the latest unified CSVs before going offline so
 * wpoptimizing.com has fresh data even if the daily cron never fires again.
 *
 * @return void
 */
function wpoptimizing_deactivate() {
	wpoptimizing_revoke_cap();

	// Final upload so the remote server is up to date.
	wpoptimizing_generate_unified_files();
	wpoptimizing_upload_unified_files();

	$timestamp = wp_next_scheduled( 'wpoptimizing_refresh_csv_cache' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wpoptimizing_refresh_csv_cache' );
	}

	$timestamp = wp_next_scheduled( 'wpoptimizing_unified_daily' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wpoptimizing_unified_daily' );
	}

	$timestamp = wp_next_scheduled( 'wpoptimizing_license_heartbeat' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wpoptimizing_license_heartbeat' );
	}
	wp_clear_scheduled_hook( 'wpoptimizing_page_cache_gc' );
	wp_clear_scheduled_hook( 'wpoptimizing_page_cache_text_refresh' );
	wp_clear_scheduled_hook( 'wpoptimizing_page_cache_gc_hourly' );
	wp_clear_scheduled_hook( 'wpoptimizing_page_cache_preload' );
	wp_clear_scheduled_hook( 'wpoptimizing_attr_suggest_auto' );
	wp_clear_scheduled_hook( 'wpoptimizing_ccss_auto_check' );
	wp_clear_scheduled_hook( 'wpoptimizing_ccss_auto_hourly' );
	wp_clear_scheduled_hook( 'wpoptimizing_ccss_force' );
	// HTML at Cloudflare's edge: without the plugin no edit purges it, so the rule goes too.
	if ( function_exists( 'wpoptimizing_cf_edge_disable' ) && function_exists( 'wpoptimizing_cf_active' ) && wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_EDGE ) ) {
		wpoptimizing_cf_edge_disable();
	}
}

/**
 * Render the "Keep all revisions" meta box.
 *
 * When ticked, the post's `_wpopt_keep_revisions` meta is set and the general
 * cleanup never deletes that post's revisions.
 *
 * @param WP_Post $post Current post.
 * @return void
 */
function wpoptimizing_revision_keep_metabox( $post ) {
	$checked = '' !== (string) get_post_meta( $post->ID, '_wpopt_keep_revisions', true );
	wp_nonce_field( 'wpoptimizing_keep_revisions', 'wpoptimizing_keep_revisions_nonce' );
	echo '<label><input type="checkbox" name="wpopt_keep_revisions" value="1" ' . checked( $checked, true, false ) . ' /> ';
	echo esc_html__( 'Keep all revisions (exclude from WPOptimizing cleanup)', 'wpoptimizing' );
	echo '</label>';
}

/**
 * On activation, schedule an initial scan.
 */
function wpoptimizing_on_activation() {
	// Before anything can be written there, and before the first front-end request.
	wpoptimizing_ensure_data_dir_protection();
	wpoptimizing_schedule_scan( 'activation' );
	// Install runtime-map mu-plugin (atomic tmp+rename).
	if ( file_exists( WPOPT_MUPL_SRC ) ) {
		wpoptimizing_atomic_install_file( WPOPT_MUPL_SRC, WPOPT_MUPL_DEST );
	}
	// Invalidate signature so the mu-plugin captures on next boot.
	delete_option( 'wpopt_rtmap_sig' );
	delete_option( 'wpopt_rtmap_settings_sig' ); // El escaneo de ajustes tiene firma propia.
}

/**
 * On deactivation, clear any pending cron.
 *
 * @param bool $network_wide Deactivated for the whole network.
 */
function wpoptimizing_on_deactivation( $network_wide = false ) {
	if ( ! defined( 'WPOPT_TEARDOWN' ) ) {
		define( 'WPOPT_TEARDOWN', true ); // Our own blocks come out even if their backup cannot be written: see wpoptimizing_wpconfig_apply_block().
	}
	wp_unschedule_hook( 'wpoptimizing_cron_scan' ); // Carries its reason as an argument.
	$recurring_scan = 'wpoptimizing_scan_cron';
	$rs_ts          = wp_next_scheduled( $recurring_scan );
	while ( $rs_ts ) {
		wp_unschedule_event( $rs_ts, $recurring_scan );
		$rs_ts = wp_next_scheduled( $recurring_scan );
	}
	wp_clear_scheduled_hook( 'wpoptimizing_general_cleanup_resume' ); // The next pass of a cleanup cut short.
	wp_clear_scheduled_hook( 'wpoptimizing_rebuild_tables' ); // Large tables waiting to be rebuilt.
	$cleanup_hook = 'wpoptimizing_general_cleanup_cron';
	$cleanup_ts   = wp_next_scheduled( $cleanup_hook );
	while ( $cleanup_ts ) {
		wp_unschedule_event( $cleanup_ts, $cleanup_hook );
		$cleanup_ts = wp_next_scheduled( $cleanup_hook );
	}
	// Remove runtime-map mu-plugin.
	if ( file_exists( WPOPT_MUPL_DEST ) ) {
		wp_delete_file( WPOPT_MUPL_DEST );
	}
	delete_option( 'wpopt_rtmap_sig' );
	delete_option( 'wpopt_rtmap_settings_sig' ); // El escaneo de ajustes tiene firma propia.

	// Remove our object-cache drop-in (only if it is ours), so a deactivated
	// plugin never leaves an unmanaged cache behind. Foreign drop-ins are left
	// untouched. The self-contained store/config are harmless and kept.
	if ( file_exists( WPOPT_DROPIN_DEST ) ) {
		$head = (string) file_get_contents( WPOPT_DROPIN_DEST, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header.
		if ( false !== strpos( $head, 'WPOPT_OBJECT_CACHE' ) ) {
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			wp_delete_file( WPOPT_DROPIN_DEST );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( WPOPT_DROPIN_DEST, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
	delete_option( 'wpoptimizing_cache_dropin' );
	// The object-cache.php ours took the place of, if any, goes back: deleting ours without this
	// left the site with no drop-in at all and the original waiting forever in our backup folder.
	if ( function_exists( 'wpoptimizing_dropin_restore' ) ) {
		wpoptimizing_dropin_restore( 'object' );
	}

	// Remove our query-dedup db.php drop-in (only if it is ours). Foreign db.php
	// files (HyperDB, Query Monitor, …) are left untouched.
	if ( file_exists( WPOPT_DB_DROPIN_DEST ) ) {
		$dbhead = (string) file_get_contents( WPOPT_DB_DROPIN_DEST, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header.
		if ( false !== strpos( $dbhead, 'WPOPT_DB_DROPIN' ) ) {
			wp_delete_file( WPOPT_DB_DROPIN_DEST );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( WPOPT_DB_DROPIN_DEST, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}
	delete_option( 'wpoptimizing_db_dropin' );
	if ( function_exists( 'wpoptimizing_dropin_restore' ) ) {
		wpoptimizing_dropin_restore( 'db' );
	}

	// Page cache: advanced-cache.php, the WP_CACHE block and an 'active' sidecar used to stay
	// behind, so the site kept serving pages from disk with nothing left to purge them — an edit
	// after deactivating never showed. Same path as switching it off in the Cache tab: our file
	// and block go, the sidecar says inactive, the pages are deleted, a replaced original returns.
}

/**
 * Capture plugin/theme installs/updates via upgrader.
 *
 * @param WP_Upgrader $upgrader    Upgrader instance.
 * @param array       $hook_extra  Process data.
 * @return void
 */
function wpoptimizing_on_upgrader_process_complete( $upgrader, $hook_extra ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	if ( empty( $hook_extra['type'] ) ) {
		return;
	}
	$type = $hook_extra['type']; // 'plugin' | 'theme' | 'translation' | 'core'
	if ( in_array( $type, array( 'plugin', 'theme' ), true ) ) {
		wpoptimizing_schedule_scan( 'upgrade' );
	}
}

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'admin_init', 'wpoptimizing_ensure_data_dir_protection' );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'upgrader_process_complete', 'wpoptimizing_on_upgrader_process_complete', 10, 2 );
