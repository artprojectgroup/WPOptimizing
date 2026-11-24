<?php
/**
 * Activity log — a change journal for the site, kept from the moment WPOptimizing
 * is installed.
 *
 * It records what changes in WordPress over time so the site owner (and the KB)
 * can see what future plugins add or remove: database tables, options, post/user
 * meta keys, scheduled events, and top-level plugin/theme/upload files — each with
 * a timestamp and, when the change rides a plugin/theme lifecycle event, the
 * responsible extension.
 *
 * Design: no per-request overhead. A baseline "state signature" is captured once,
 * and a diff runs only on meaningful moments — plugin/theme install, activate,
 * deactivate, delete, upgrade, and each analysis. New/removed items are appended
 * to a capped, non-autoloaded journal. The diff is pure and unit-tested.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

const WPOPT_ACTIVITY_OPTION   = 'wpoptimizing_activity_log';
const WPOPT_ACTIVITY_BASELINE = 'wpoptimizing_activity_baseline';

/**
 * Max journal entries kept (oldest dropped). Filterable.
 *
 * @return int
 */
function wpoptimizing_activity_cap() {
	return (int) apply_filters( 'wpoptimizing_activity_cap', 2000 );
}

/**
 * The tracked change types (key => human label). Also drives the UI filter.
 *
 * @return array<string,string>
 */
function wpoptimizing_activity_types() {
	return array(
		'table'    => __( 'Table', 'wpoptimizing' ),
		'option'   => __( 'Option', 'wpoptimizing' ),
		'postmeta' => __( 'Post meta', 'wpoptimizing' ),
		'usermeta' => __( 'User meta', 'wpoptimizing' ),
		'cron'     => __( 'Cron event', 'wpoptimizing' ),
		'plugin'   => __( 'Plugin', 'wpoptimizing' ),
		'theme'    => __( 'Theme', 'wpoptimizing' ),
		'file'     => __( 'File', 'wpoptimizing' ),
	);
}

/**
 * Capture the current state signature: for each tracked category, the set of
 * identifiers currently present. Read-only; safe in any context.
 *
 * @return array<string,string[]>
 */
function wpoptimizing_activity_current_state() {
	global $wpdb;
	$state = array(
		'table'    => array(),
		'option'   => array(),
		'postmeta' => array(),
		'usermeta' => array(),
		'cron'     => array(),
		'plugin'   => array(),
		'theme'    => array(),
		'file'     => array(),
	);
	if ( isset( $wpdb ) && is_object( $wpdb ) ) {
		/*
		 * Only this site's tables. On the main site of a network SHOW TABLES lists every
		 * sub-site's, which does two things here: it puts other customers' table names into a
		 * log this site's administrator reads — and a table name says which plugins they run —
		 * and it fills the "what changed" diff with events that happened on somebody else's
		 * site.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables         = array_map( 'strval', (array) $wpdb->get_col( 'SHOW TABLES' ) );
		$state['table'] = function_exists( 'wpoptimizing_table_belongs_to_current_blog' )
			? array_values( array_filter( $tables, 'wpoptimizing_table_belongs_to_current_blog' ) )
			: $tables;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$state['option'] = array_map( 'strval', (array) $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} ORDER BY option_name LIMIT 8000" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$state['postmeta'] = array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta} ORDER BY meta_key LIMIT 4000" ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$state['usermeta'] = array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta} ORDER BY meta_key LIMIT 4000" ) );
	}

	// Scheduled event hook names.
	if ( function_exists( '_get_cron_array' ) ) {
		$hooks = array();
		foreach ( (array) _get_cron_array() as $events ) {
			if ( is_array( $events ) ) {
				foreach ( array_keys( $events ) as $hook ) {
					$hooks[ (string) $hook ] = true;
				}
			}
		}
		$state['cron'] = array_keys( $hooks );
	}

	// Installed plugin + theme directories.
	if ( defined( 'WP_PLUGIN_DIR' ) ) {
		$state['plugin'] = wpoptimizing_activity_dir_entries( WP_PLUGIN_DIR );
	}
	if ( function_exists( 'get_theme_root' ) ) {
		$state['theme'] = wpoptimizing_activity_dir_entries( (string) get_theme_root() );
	}

	// Top-level files/dirs a plugin might drop: mu-plugins, wp-content drop-ins,
	// and the top level of uploads (never per-upload noise).
	$files = array();
	if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
		foreach ( wpoptimizing_activity_dir_entries( WPMU_PLUGIN_DIR ) as $e ) {
			$files[] = 'mu-plugins/' . $e;
		}
	}
	if ( defined( 'WP_CONTENT_DIR' ) ) {
		foreach ( array( 'object-cache.php', 'db.php', 'advanced-cache.php', 'maintenance.php' ) as $dropin ) {
			if ( file_exists( WP_CONTENT_DIR . '/' . $dropin ) ) {
				$files[] = $dropin;
			}
		}
	}
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['basedir'] ) ) {
			foreach ( wpoptimizing_activity_dir_entries( (string) $up['basedir'] ) as $e ) {
				$files[] = 'uploads/' . $e;
			}
		}
	}
	$state['file'] = $files;

	return $state;
}

/**
 * List the immediate entries (files + dirs) of a directory, names only. Empty on error.
 *
 * @param string $dir Absolute path.
 * @return string[]
 */
function wpoptimizing_activity_dir_entries( $dir ) {
	$dir = (string) $dir;
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return array();
	}
	$out = array();
	$dh  = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.directory_opendir -- Lightweight top-level listing for the change journal; not a plugin-dir asset.
	if ( ! $dh ) {
		return array();
	}
	// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, WordPress.WP.AlternativeFunctions.directory_readdir
	while ( false !== ( $entry = readdir( $dh ) ) ) {
		if ( '.' === $entry || '..' === $entry || '' === $entry ) {
			continue;
		}
		$out[] = (string) $entry;
	}
	closedir( $dh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.directory_closedir
	sort( $out );
	return $out;
}

/**
 * Diff two state signatures into a flat list of change events. Pure.
 *
 * @param array<string,string[]> $prev Previous state.
 * @param array<string,string[]> $curr Current state.
 * @return array<int,array{type:string,action:string,name:string}>
 */
function wpoptimizing_activity_diff( array $prev, array $curr ) {
	$events = array();
	$types  = array_keys( wpoptimizing_activity_types() );
	foreach ( $types as $type ) {
		$was = isset( $prev[ $type ] ) && is_array( $prev[ $type ] ) ? array_fill_keys( array_map( 'strval', $prev[ $type ] ), true ) : array();
		$now = isset( $curr[ $type ] ) && is_array( $curr[ $type ] ) ? array_fill_keys( array_map( 'strval', $curr[ $type ] ), true ) : array();
		foreach ( array_keys( $now ) as $name ) {
			if ( ! isset( $was[ $name ] ) ) {
				$events[] = array(
					'type'   => $type,
					'action' => 'added',
					'name'   => (string) $name,
				);
			}
		}
		foreach ( array_keys( $was ) as $name ) {
			if ( ! isset( $now[ $name ] ) ) {
				$events[] = array(
					'type'   => $type,
					'action' => 'removed',
					'name'   => (string) $name,
				);
			}
		}
	}
	return $events;
}

/**
 * Read the journal (newest first).
 *
 * @return array<int,array{ts:int,type:string,action:string,name:string,owner:string}>
 */
function wpoptimizing_activity_log_get() {
	$log = (array) wpoptimizing_blob_get( 'activity-log', WPOPT_ACTIVITY_OPTION, array() );
	return is_array( $log ) ? $log : array();
}

/**
 * The stored baseline state signature (empty until first captured).
 *
 * @return array<string,string[]>
 */
function wpoptimizing_activity_baseline_get() {
	$b = (array) wpoptimizing_blob_get( 'activity-baseline', WPOPT_ACTIVITY_BASELINE, array() );
	return is_array( $b ) ? $b : array();
}

/**
 * Persist the baseline state signature (non-autoloaded).
 *
 * @param array<string,string[]> $state State.
 * @return void
 */
function wpoptimizing_activity_baseline_set( array $state ) {
	wpoptimizing_blob_put( 'activity-baseline', WPOPT_ACTIVITY_BASELINE, $state );
}

/**
 * Append events to the journal (newest first), stamping each with a timestamp and
 * the responsible owner; trims to the cap.
 *
 * @param array<int,array{type:string,action:string,name:string}> $events Events.
 * @param string                                                   $owner  Responsible extension (slug/name) or ''.
 * @param int                                                      $ts     Timestamp.
 * @return void
 */
function wpoptimizing_activity_log_append( array $events, $owner, $ts ) {
	if ( empty( $events ) ) {
		return;
	}
	$log   = wpoptimizing_activity_log_get();
	$owner = (string) $owner;
	$ts    = (int) $ts;
	$new   = array();
	foreach ( $events as $e ) {
		$new[] = array(
			'ts'     => $ts,
			'type'   => (string) ( $e['type'] ?? '' ),
			'action' => (string) ( $e['action'] ?? '' ),
			'name'   => (string) ( $e['name'] ?? '' ),
			'owner'  => $owner,
		);
	}
	// Newest first.
	$log = array_merge( $new, $log );
	$cap = wpoptimizing_activity_cap();
	if ( count( $log ) > $cap ) {
		$log = array_slice( $log, 0, $cap );
	}
	wpoptimizing_blob_put( 'activity-log', WPOPT_ACTIVITY_OPTION, $log );
}

/**
 * Capture the current state, diff against the baseline, journal the changes and
 * advance the baseline. The first call just establishes the baseline (no events).
 *
 * @param string $owner Responsible extension for this change (from a lifecycle event), or ''.
 * @return int Number of events recorded.
 */
function wpoptimizing_activity_record( $owner = '' ) {
	$curr = wpoptimizing_activity_current_state();
	$prev = wpoptimizing_activity_baseline_get();
	if ( empty( $prev ) ) {
		wpoptimizing_activity_baseline_set( $curr );
		return 0;
	}
	$events = wpoptimizing_activity_diff( $prev, $curr );
	if ( ! empty( $events ) ) {
		wpoptimizing_activity_log_append( $events, $owner, time() );
	}
	wpoptimizing_activity_baseline_set( $curr );
	return count( $events );
}

/**
 * Ensure a baseline exists (captured the first time WPOptimizing runs in admin
 * after install), so the journal measures changes "since install".
 *
 * @return void
 */
function wpoptimizing_activity_ensure_baseline() {
	if ( empty( wpoptimizing_activity_baseline_get() ) ) {
		wpoptimizing_activity_baseline_set( wpoptimizing_activity_current_state() );
	}
}

/**
 * The plugin/theme slug from a lifecycle hook argument (basename of the folder).
 *
 * @param string $plugin_file e.g. "woocommerce/woocommerce.php".
 * @return string
 */
function wpoptimizing_activity_slug( $plugin_file ) {
	$plugin_file = (string) $plugin_file;
	if ( '' === $plugin_file ) {
		return '';
	}
	$parts = explode( '/', $plugin_file );
	return (string) $parts[0];
}

/**
 * Register the change-capture triggers. Bound to plugin/theme lifecycle events and
 * to each analysis, never to normal page loads.
 *
 * @return void
 */
function wpoptimizing_activity_boot() {
	add_action( 'admin_init', 'wpoptimizing_activity_ensure_baseline' );

	add_action(
		'activated_plugin',
		static function ( $plugin ) {
			wpoptimizing_activity_record( wpoptimizing_activity_slug( $plugin ) );
		}
	);
	add_action(
		'deactivated_plugin',
		static function ( $plugin ) {
			wpoptimizing_activity_record( wpoptimizing_activity_slug( $plugin ) );
		}
	);
	add_action(
		'deleted_plugin',
		static function ( $plugin ) {
			wpoptimizing_activity_record( wpoptimizing_activity_slug( $plugin ) );
		}
	);
	add_action(
		'switch_theme',
		static function ( $new_name ) {
			wpoptimizing_activity_record( (string) $new_name );
		}
	);
	add_action(
		'upgrader_process_complete',
		static function () {
			wpoptimizing_activity_record( '' );
		}
	);
	// After each analysis, capture any background changes (no lifecycle owner).
	add_action(
		'wpoptimizing_after_scan',
		static function () {
			wpoptimizing_activity_record( '' );
		},
		30
	);
}
add_action( 'init', 'wpoptimizing_activity_boot' );
