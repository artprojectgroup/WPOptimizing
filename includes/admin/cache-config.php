<?php
/**
 * Object-cache drop-in configuration: the sidecar file and the guards around it.
 *
 * Plain functions, and a file of their own, because two callers need them and neither can
 * reach the other's: the Cache tab (WPOptimizing_Cache_Trait, composed into WPOPT_Admin_UI) and
 * the unattended admin_init restore in screen.php, which runs with no WPOPT_Admin_UI instance.
 * One implementation, so the two can never drift.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default + sanitized cache settings.
 *
 * @since 0.1.0
 * @return array
 */
function wpoptimizing_cache_settings() {
	$defaults = array(
		'backend'        => 'auto', // auto|redis|memcached|apcu|sqlite
		'redis_host'     => '127.0.0.1',
		'redis_port'     => 6379,
		'redis_password' => '',
		'redis_database' => 0,
		'memcached_host' => '127.0.0.1',
		'memcached_port' => 11211,
		'admin_counts'   => 0,
		'heartbeat'      => 'default',
	);
	$stored   = get_option( 'wpoptimizing_cache_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	$s                   = wp_parse_args( $stored, $defaults );
	$allowed             = array( 'auto', 'redis', 'memcached', 'apcu', 'sqlite' );
	$s['backend']        = in_array( $s['backend'], $allowed, true ) ? $s['backend'] : 'auto';
	$s['admin_counts']   = empty( $s['admin_counts'] ) ? 0 : 1;
	$s['heartbeat']      = in_array( $s['heartbeat'], array( 'default', 'slow', 'off' ), true ) ? $s['heartbeat'] : 'default';
	$s['redis_host']     = (string) $s['redis_host'];
	$s['redis_port']     = max( 1, min( 65535, (int) $s['redis_port'] ) );
	$s['redis_password'] = (string) $s['redis_password'];
	$s['redis_database'] = max( 0, min( 255, (int) $s['redis_database'] ) );
	$s['memcached_host'] = (string) $s['memcached_host'];
	$s['memcached_port'] = max( 1, min( 65535, (int) $s['memcached_port'] ) );
	// The Heartbeat per zone (admin-cache.php): migrated in memory, and the single word kept as
	// their summary, which is what the preset select shows and sends back.
	if ( function_exists( 'wpoptimizing_heartbeat_migrate' ) ) {
		$s              = wpoptimizing_heartbeat_migrate( $s );
		$s['heartbeat'] = wpoptimizing_heartbeat_summary( wpoptimizing_heartbeat_zones( $s ) );
	}
	return $s;
}

/**
 * Compose and write the drop-in sidecar config from the saved settings.
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_write_cache_config_sidecar() {
	$dir = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR : WP_CONTENT_DIR . '/cache';
	if ( ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	// Make sure the cache dir is guarded (index.php + .htaccess): it can hold
	// the SQLite store and the analytics file (which may contain SQL samples).
	wpoptimizing_ensure_cache_dir_protection();

	$s    = wpoptimizing_cache_settings();
	$salt = (string) get_option( 'wpoptimizing_cache_salt', '' );
	if ( '' === $salt ) {
		$salt = substr( md5( wp_generate_password( 24, false ) . ABSPATH ), 0, 12 );
		update_option( 'wpoptimizing_cache_salt', $salt, false );
	}

	$config = array(
		'backend'   => $s['backend'],
		'salt'      => $salt,
		'redis'     => array(
			'host'     => $s['redis_host'],
			'port'     => (int) $s['redis_port'],
			'password' => $s['redis_password'],
			'database' => (int) $s['redis_database'],
		),
		'memcached' => array(
			'host' => $s['memcached_host'],
			'port' => (int) $s['memcached_port'],
		),
	);

	$php  = "<?php\n";
	$php .= "// WPOptimizing object-cache config. Auto-generated; edit via the Cache tab.\n";
	$php .= 'return ' . var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.RestrictedFunctions.var_export_var_export, WordPress.PHP.DevelopmentFunctions.error_log_var_export -- not debug; formatting a value into a static PHP config the drop-in includes.

	$path = defined( 'WPOPT_CACHE_CONFIG' ) ? WPOPT_CACHE_CONFIG : $dir . '/wpopt-cache-config.php';
	return wpoptimizing_write_php_sidecar( $path, $php );
}

/**
 * Write a PHP file that a drop-in include()s on every request: temp file + rename, then OPcache
 * told about it.
 *
 * Written in place, a request arriving mid-write included half an array — a parse error before
 * WordPress had any handler for it. And without the invalidation OPcache kept running the old
 * compiled copy (for good with validate_timestamps=0), so a changed backend or password did not
 * take effect.
 *
 * @since 0.1.0
 * @param string $path     Destination.
 * @param string $contents PHP source.
 * @return bool
 */
function wpoptimizing_write_php_sidecar( $path, $contents ) {
	$tmp = function_exists( 'wpoptimizing_atomic_tmp_path' ) ? wpoptimizing_atomic_tmp_path( $path ) : $path . '.tmp.' . uniqid( '', true );
	if ( false === file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Config sidecar in wp-content/cache, not the plugin directory (standard drop-in mechanism).
		return false;
	}
	if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace; WP_Filesystem::move() is not guaranteed atomic.
		wp_delete_file( $tmp );
		return false;
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	return true;
}

/**
 * Ensure the cache directory exists and is not browsable (index.php + .htaccess).
 * The analytics/SQLite files live here.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_ensure_cache_dir_protection() {
	$dir = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR : WP_CONTENT_DIR . '/cache';
	if ( ! wp_mkdir_p( $dir ) ) {
		return;
	}
	$index = $dir . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Installs the object-cache/db drop-in into wp-content, not the plugin directory (standard drop-in mechanism).
	}
	$ht = $dir . '/.htaccess';
	if ( ! file_exists( $ht ) ) {
		$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			. "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n";
		file_put_contents( $ht, $rules, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Installs the object-cache/db drop-in into wp-content, not the plugin directory (standard drop-in mechanism).
	}
}
