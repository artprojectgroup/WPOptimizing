<?php
/**
 * After a full purge, the pages are rebuilt with the code that is on the disk, not with the copy
 * OPcache still keeps.
 *
 * OPcache checks whether a file changed at most every `opcache.revalidate_freq` seconds (60 on
 * many hostings), and never with `validate_timestamps` off. A theme file edited and the cache
 * purged straight after: the preload rebuilt every page with the old code, and they stayed like
 * that until they expired (artprojectgroup.com, 10/11/2026: a purge had to be repeated).
 *
 * OPcache lives in the web server's PHP, not in WP-CLI's or in a cron run from the command line,
 * so the purge only leaves a flag. The first request of the web server that loads WordPress
 * afterwards (the preload's own, usually) takes it and invalidates every cached script whose file
 * is newer than its cached copy, before the theme is loaded. If something already loaded in that
 * request was stale, the request itself is not cached.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The flag: one per server, like OPcache.
 *
 * @return string
 */
function wpoptimizing_opcache_flag_file() {
	$dir = defined( 'WPOPT_PC_CACHE_DIR' ) ? WPOPT_PC_CACHE_DIR : WP_CONTENT_DIR . '/cache/wpopt-page';
	return rtrim( (string) $dir, '/' ) . '/.opcache-recheck';
}

/**
 * Ask the web server to check its OPcache on its next request; in the web server, check now.
 *
 * @return void
 */
function wpoptimizing_opcache_request_recheck() {
	if ( ! wpoptimizing_opcache_usable() ) {
		return;
	}
	if ( 'cli' !== PHP_SAPI ) {
		wpoptimizing_opcache_refresh_changed(); // This is the web server: no need to ask it.
		return;
	}
	$flag = wpoptimizing_opcache_flag_file();
	if ( is_dir( dirname( $flag ) ) ) {
		@touch( $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- our own cache folder; a flag that cannot be written only costs the check.
	}
}

/**
 * Can this PHP read and invalidate its OPcache? (Hosts disable the functions or restrict the API.)
 *
 * @return bool
 */
function wpoptimizing_opcache_usable() {
	return function_exists( 'opcache_get_status' ) && function_exists( 'opcache_invalidate' );
}

/**
 * Scripts whose cached copy is older than their file. Pure.
 *
 * @param array    $status  opcache_get_status( true ).
 * @param callable $mtime   File path => modification time, or false if the file is gone.
 * @return string[] Paths.
 */
function wpoptimizing_opcache_stale_scripts( array $status, callable $mtime ) {
	$out = array();
	// Without validate_timestamps OPcache keeps no time per script: anything modified since it
	// started may be stale. Invalidating a fresh one only costs a recompile.
	$start = (int) ( $status['opcache_statistics']['start_time'] ?? 0 );
	foreach ( (array) ( $status['scripts'] ?? array() ) as $key => $script ) {
		$path = (string) ( $script['full_path'] ?? $key );
		if ( '' === $path || 0 === strpos( $path, 'phar://' ) ) {
			continue;
		}
		$cached = isset( $script['timestamp'] ) ? (int) $script['timestamp'] : $start;
		$now    = $mtime( $path );
		if ( false !== $now && (int) $now > $cached ) {
			$out[] = $path;
		}
	}
	return $out;
}

/**
 * Invalidate the stale scripts of this server's OPcache.
 *
 * @return string[] Invalidated paths.
 */
function wpoptimizing_opcache_refresh_changed() {
	if ( ! wpoptimizing_opcache_usable() ) {
		return array();
	}
	$status = @opcache_get_status( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- restrict_api raises a warning; no status is the answer.
	if ( ! is_array( $status ) || empty( $status['opcache_enabled'] ) ) {
		return array();
	}
	$stale = wpoptimizing_opcache_stale_scripts(
		$status,
		static function ( $path ) {
			return @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a deleted file answers false.
		}
	);
	foreach ( $stale as $path ) {
		opcache_invalidate( $path, true );
	}
	return $stale;
}

/**
 * Take the flag on the web server's first request after a purge.
 *
 * @return void
 */
function wpoptimizing_opcache_take_flag() {
	if ( 'cli' === PHP_SAPI || ! wpoptimizing_opcache_usable() ) {
		return;
	}
	$flag = wpoptimizing_opcache_flag_file();
	// unlink() succeeds for one process only: the check runs once, however many requests arrive.
	if ( ! file_exists( $flag ) || ! @unlink( $flag ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- our own flag; another process may have taken it.
		return;
	}
	$stale = wpoptimizing_opcache_refresh_changed();
	// Something this very request already ran from the old copy: its page is not stored.
	if ( $stale && array_intersect( $stale, get_included_files() ) && ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant every page cache honours.
	}
}
wpoptimizing_opcache_take_flag();

add_action( 'wpoptimizing_page_cache_cleared', 'wpoptimizing_opcache_request_recheck' );
