<?php
/**
 * WP-Cron trigger control.
 *
 * By default WordPress fires scheduled tasks on visitor page loads (WP-Cron),
 * which adds latency to random requests and is unreliable on low-traffic sites.
 * A real server cron that hits wp-cron.php on a fixed schedule is faster and
 * dependable. This module toggles the standard `DISABLE_WP_CRON` constant in
 * wp-config.php and surfaces the exact system-cron command to pair with it.
 *
 * The wp-config editing itself (marker block, backup-first, atomic write,
 * verification) lives in the shared helper `wpconfig-block.php`; this module only
 * supplies the block name/body and the cron-specific status + command builder.
 * The pure helpers here are thin named wrappers so existing callers/tests keep
 * working.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Name of our managed wp-config block (the `WP-Cron` in the BEGIN/END markers).
 *
 * @return string
 */
function wpoptimizing_wp_cron_block_name() {
	return 'WP-Cron';
}

/**
 * PHP body written between the markers. Guarded so it never redefines a constant
 * the user already set by hand.
 *
 * @return string
 */
function wpoptimizing_wp_cron_block_body() {
	return "if ( ! defined( 'DISABLE_WP_CRON' ) ) {\n\tdefine( 'DISABLE_WP_CRON', true );\n}";
}

/**
 * Begin marker for the managed wp-config block.
 *
 * @return string
 */
function wpoptimizing_wp_cron_marker_begin() {
	return wpoptimizing_wpconfig_marker_begin( wpoptimizing_wp_cron_block_name() );
}

/**
 * The managed block written into wp-config.php.
 *
 * @return string
 */
function wpoptimizing_wp_cron_managed_block() {
	return wpoptimizing_wpconfig_managed_block( wpoptimizing_wp_cron_block_name(), wpoptimizing_wp_cron_block_body() );
}

/**
 * Does the wp-config content already contain our managed block?
 *
 * @param string $contents wp-config.php contents.
 * @return bool
 */
function wpoptimizing_wp_cron_config_has_block( $contents ) {
	return wpoptimizing_wpconfig_has_block( $contents, wpoptimizing_wp_cron_block_name() );
}

/**
 * Insert the managed block right after the opening PHP tag. Idempotent; null when
 * no `<?php` opening tag is found.
 *
 * @param string $contents wp-config.php contents.
 * @return string|null Modified contents, or null when the opening tag is missing.
 */
function wpoptimizing_wp_cron_config_add( $contents ) {
	return wpoptimizing_wpconfig_add_block( $contents, wpoptimizing_wp_cron_block_name(), wpoptimizing_wp_cron_block_body() );
}

/**
 * Remove the managed block. Returns the input unchanged when the block is absent.
 *
 * @param string $contents wp-config.php contents.
 * @return string
 */
function wpoptimizing_wp_cron_config_strip( $contents ) {
	return wpoptimizing_wpconfig_strip_block( $contents, wpoptimizing_wp_cron_block_name() );
}

/**
 * Build the recommended system-cron line for a given wp-cron URL and interval.
 *
 * @param string $cron_url URL to wp-cron.php (with the doing_wp_cron query arg).
 * @param int    $minutes  Interval in minutes (clamped 1..60).
 * @return string
 */
function wpoptimizing_wp_cron_command( $cron_url, $minutes = 5 ) {
	$minutes = max( 1, min( 60, (int) $minutes ) );
	$spec    = 1 === $minutes ? '* * * * *' : '*/' . $minutes . ' * * * *';
	return $spec . ' curl -s ' . $cron_url . ' >/dev/null 2>&1';
}

/**
 * Locate wp-config.php (delegates to the shared resolver).
 *
 * @return string Absolute path, or '' when it cannot be located.
 */
function wpoptimizing_wp_cron_config_path() {
	return wpoptimizing_wpconfig_path();
}

/**
 * Current WP-Cron trigger status for the admin UI.
 *
 * @return array {
 *     @type bool   $disabled  DISABLE_WP_CRON is truthy (any source).
 *     @type bool   $alternate ALTERNATE_WP_CRON is on.
 *     @type bool   $managed   Our managed block is present in wp-config.php.
 *     @type string $path      wp-config.php path ('' if not found).
 *     @type bool   $writable  wp-config.php is writable.
 *     @type string $url       wp-cron.php URL.
 *     @type string $command   Recommended crontab line.
 * }
 */
function wpoptimizing_wp_cron_status() {
	$path     = wpoptimizing_wp_cron_config_path();
	$contents = ( '' !== $path && is_readable( $path ) ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads wp-config.php to report the WP-Cron trigger state; not a plugin-dir asset.
	$url      = site_url( 'wp-cron.php?doing_wp_cron' );

	return array(
		'disabled'  => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		'alternate' => defined( 'ALTERNATE_WP_CRON' ) && ALTERNATE_WP_CRON,
		'managed'   => '' !== $contents && wpoptimizing_wp_cron_config_has_block( $contents ),
		'path'      => $path,
		'writable'  => '' !== $path && wp_is_writable( $path ),
		'url'       => $url,
		'command'   => wpoptimizing_wp_cron_command( $url, 5 ),
	);
}

/**
 * Toggle the managed DISABLE_WP_CRON block in wp-config.php (backup-first, atomic,
 * verified — via the shared helper).
 *
 * @param bool $disable True to add the block (disable WP-Cron), false to remove it.
 * @return array {
 *     @type bool   $ok   Whether the change was applied.
 *     @type string $code Machine code: done|nochange|notfound|notwritable|badformat|writefail.
 * }
 */
function wpoptimizing_wp_cron_apply( $disable ) {
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/wpconfig-block.php' );
	}
	return wpoptimizing_wpconfig_apply_block( wpoptimizing_wp_cron_block_name(), wpoptimizing_wp_cron_block_body(), (bool) $disable );
}
