<?php
/**
 * WPOptimizing uninstall.
 *
 * Runs when the plugin is DELETED from the Plugins screen (not on deactivation).
 * Removes every trace the plugin created so the site is returned to a pristine
 * state — unless the site opted out via the "Delete all data on uninstall" setting
 * (Settings → on by default). The actual work lives in a self-contained module so
 * it never depends on the plugin being bootstrapped.
 *
 * @package WPOptimizing
 */

// Only ever run in WordPress's own uninstall context.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/uninstall-cleanup.php';

// Honour the opt-out. Absent option (fresh installs that never saved settings) means
// purge, matching the default-on checkbox; only an explicit '0' keeps the data. Kept data is
// options and files, never code: the drop-ins, must-use plugins and wp-config.php blocks go
// either way, or they would go on running on every request with no plugin behind them.
if ( '0' === (string) get_option( 'wpoptimizing_delete_data_on_uninstall', '1' ) ) {
	wpoptimizing_uninstall_remove_executables();
	return;
}

wpoptimizing_uninstall_run();
