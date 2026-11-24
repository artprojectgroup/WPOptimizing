<?php
/**
 * Libraries that ship INSIDE other extensions, and the values they register.
 *
 * Action Scheduler is the clearest case: WooCommerce bundles it, and so do dozens of unrelated
 * plugins. Each copy registers the same `scheduled-action` post type and `action-group`
 * taxonomy. The static scanner finds that registration in whichever host it happened to read
 * and records the owner "with certainty" — but the certainty is about where the CODE lives, not
 * about who owns the DATA. Uninstall that host and the taxonomy reads as an orphan attributed
 * to a plugin that is gone, offered for deletion as SAFE, while WooCommerce is still running
 * its job queue on exactly those rows.
 *
 * That was a real report from a production site: `action-group` shown as a certain orphan of a
 * theme named after an affiliate plugin. Two things were wrong at once — the owner, and the
 * certainty.
 *
 * So the rule here is not "who owns this" (nobody can answer that for a vendored library) but
 * "is the library still present". Presence is detected from the library's own footprint, never
 * from a list of hosts: enumerating every plugin that bundles Action Scheduler is impossible
 * and would be wrong the day after it was written.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The registry: library key => definition.
 *
 * `registers` maps an item type to the values that library registers. `detect` returns whether
 * the library is present on this site at all.
 *
 * @since 0.1.0
 * @return array<string,array{label:string,registers:array<string,string[]>,detect:callable}>
 */
function wpoptimizing_shared_libraries() {
	$libs = array(
		'action-scheduler' => array(
			'label'     => 'Action Scheduler',
			'registers' => array(
				'post_type'         => array( 'scheduled-action' ),
				'post_type_content' => array( 'scheduled-action' ),
				'taxonomy'          => array( 'action-group' ),
				'taxonomy_term'     => array( 'action-group' ),
				'table'             => array(
					'actionscheduler_actions',
					'actionscheduler_claims',
					'actionscheduler_groups',
					'actionscheduler_logs',
				),
				/*
				 * SUS OPCIONES Y SUS CRONES FALTABAN, y es lo que llegó de producción: en dos
				 * sitios, `as_has_wp_comment_logs` aparecía como huérfana SEGURA de `ip-locator`
				 * —que simplemente era el plugin que se estaba ejecutando cuando el mu-plugin la
				 * vio—. El escudo cubría el post type, la taxonomía y las tablas, así que a la
				 * opción no llegaba.
				 *
				 * Las tres claves salen de constantes del propio Action Scheduler, no de la
				 * memoria de nadie: `$has_logs_option_key` en ActionScheduler_WPCommentCleaner,
				 * `DEMARKATION_OPTION` y `STATUS_FLAG`. Los ganchos de cron, de sus constantes
				 * WP_CRON_HOOK y las de limpieza. `action_scheduler_run_queue` es además la clave
				 * más disputada del mapa entero: la reclaman 24 plugins.
				 */
				'option'            => array(
					'as_has_wp_comment_logs',
					'action_scheduler_hybrid_store_demarkation',
					'action_scheduler_migration_status',
					// ActionScheduler_Abstract_Schema: 'schema-' . static::class, one per table set.
					'schema-ActionScheduler_StoreSchema',
					'schema-ActionScheduler_LoggerSchema',
				),
				'cron'              => array(
					'action_scheduler_run_queue',
					'action_scheduler_run_actions_cleanup_hook',
					'action_scheduler_continue_actions_cleanup_hook',
					'action_scheduler_run_recurring_actions_schedule_hook',
				),
			),
			'detect'    => 'wpoptimizing_action_scheduler_present',
		),
		/*
		 * El instalador de OTGS va dentro de WPML, WooCommerce Multilingual y el resto de
		 * productos de OneTheme/Toolset, y existe también como plugin suelto. En emiliocalvo.es
		 * (23/10/2026) `otgs_installer_products_urls` salía como huérfana SEGURA del plugin suelto,
		 * ya borrado, mientras WPML la volvía a escribir tras cada borrado. Las claves salen de
		 * las constantes y literales de vendor/otgs/installer: PRODUCTS_CONFIG_KEY,
		 * PRODUCT_VERSIONS_CONFIG_KEY, COMPONENTS_CACHE_OPTION_KEY, los ADMIN_NOTICES_OPTION, el
		 * OPTION_KEY del registro y de compartir componentes, y los ajustes wp_installer_*.
		 */
		'otgs-installer'   => array(
			'label'     => 'OTGS Installer',
			'registers' => array(
				'option' => array(
					'otgs_installer_products_urls',
					'otgs_installer_products_urls_version',
					'otgs_installer_admin_notices',
					'otgs_installer_recommendations_admin_notices_v2',
					'otgs_active_components',
					'otgs_share_local_components',
					'otgs-installer-log',
					'wp_installer_settings',
					'wp_installer_settings_common',
					'wp_installer_last_update',
					'wp_installer_clientside_active_tab',
					'wp_installer_network',
					'installer_repositories_with_theme',
				),
				'cron'   => array(
					'otgs_send_components_data',
					'otgs_installer_site_key_remove_retry',
				),
			),
			'detect'    => 'wpoptimizing_otgs_installer_present',
		),
	);

	/**
	 * Filters the bundled-library registry.
	 *
	 * @since 0.1.0
	 * @param array $libs Library definitions.
	 */
	return (array) apply_filters( 'wpoptimizing_shared_libraries', $libs );
}

/**
 * Whether Action Scheduler is present on this site, whoever bundles it.
 *
 * Checked by footprint rather than by host: the class when it is loaded, otherwise its own
 * tables. A site whose tables still exist is a site whose job queue may still be read.
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_action_scheduler_present() {
	if ( class_exists( 'ActionScheduler', false ) || function_exists( 'as_schedule_single_action' ) ) {
		return true;
	}
	global $wpdb;
	if ( ! is_object( $wpdb ) ) {
		return false;
	}
	static $has_tables = null;
	if ( null !== $has_tables ) {
		return $has_tables;
	}
	$like = $wpdb->esc_like( $wpdb->prefix . 'actionscheduler_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$has_tables = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

	return $has_tables;
}

/**
 * Whether the OTGS installer is present on this site, whoever bundles it.
 *
 * Its classes when it is loaded; otherwise its own loader inside ANY installed plugin, active
 * or not: an inactive WPML still owns its installer settings, so they are not orphans to offer
 * for deletion. The loader is the library's footprint, so no host has to be named.
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_otgs_installer_present() {
	if ( class_exists( 'WP_Installer', false ) || function_exists( 'WP_Installer_Setup' ) || function_exists( 'OTGS_Installer' ) ) {
		return true;
	}
	static $found = null;
	if ( null !== $found ) {
		return $found;
	}
	$base  = defined( 'WP_PLUGIN_DIR' ) ? (string) WP_PLUGIN_DIR : '';
	$found = '' !== $base && (
		array() !== (array) glob( $base . '/*/vendor/otgs/installer/loader.php', GLOB_NOSORT )
		|| array() !== (array) glob( $base . '/*/includes/class-wp-installer.php', GLOB_NOSORT )
	);

	return $found;
}

/**
 * Which bundled library registers this value, if any.
 *
 * @since 0.1.0
 * @param string $tipo  Item type (taxonomy, post_type, table…).
 * @param string $value Item value.
 * @return string Library key, or '' when none.
 */
function wpoptimizing_shared_library_for( $tipo, $value ) {
	$tipo  = strtolower( trim( (string) $tipo ) );
	$value = strtolower( trim( (string) $value ) );
	if ( '' === $tipo || '' === $value ) {
		return '';
	}
	global $wpdb;
	$prefix = is_object( $wpdb ) ? (string) $wpdb->prefix : '';
	foreach ( wpoptimizing_shared_libraries() as $key => $lib ) {
		$values = isset( $lib['registers'][ $tipo ] ) ? (array) $lib['registers'][ $tipo ] : array();
		foreach ( $values as $candidate ) {
			$candidate = strtolower( (string) $candidate );
			// A table may be reported with or without the site prefix.
			if ( $candidate === $value || ( '' !== $prefix && strtolower( $prefix ) . $candidate === $value ) ) {
				return (string) $key;
			}
		}
	}

	return '';
}

/**
 * Whether a bundled library is still present on this site.
 *
 * @since 0.1.0
 * @param string $key Library key.
 * @return bool
 */
function wpoptimizing_shared_library_present( $key ) {
	$libs = wpoptimizing_shared_libraries();
	if ( ! isset( $libs[ $key ]['detect'] ) || ! is_callable( $libs[ $key ]['detect'] ) ) {
		return false;
	}

	return (bool) call_user_func( $libs[ $key ]['detect'] );
}

/**
 * Human label for a library key.
 *
 * @since 0.1.0
 * @param string $key Library key.
 * @return string
 */
function wpoptimizing_shared_library_label( $key ) {
	$libs = wpoptimizing_shared_libraries();

	return isset( $libs[ $key ]['label'] ) ? (string) $libs[ $key ]['label'] : (string) $key;
}
