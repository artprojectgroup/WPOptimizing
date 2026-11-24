<?php
/**
 * D5 — background micro-task sweeper.
 *
 * A continuous, low-impact housekeeping pass that clears EXPIRED transients from the options
 * table a small batch at a time, on the tail of normal admin requests (the `shutdown` hook).
 * It complements the threshold-based auto-cleanup: instead of one big run, it nibbles a little
 * on each request so a site with a leaky transient producer never accumulates thousands of dead
 * rows between scans.
 *
 * Deliberately limited to expired transients: that is pure, reversible-by-nature housekeeping
 * (WordPress treats an expired transient as absent and regenerates it on demand), so running it
 * unattended is safe — it never touches attributed data, posts or the risk-carrying cleanup
 * engine. Opt-in (off by default), self-throttled (one small batch every N seconds), lock-guarded
 * against concurrency, and a no-op the moment there is nothing expired to remove. With a persistent
 * object cache active, transients live in the cache (not the options table) and expire on their
 * own, so the query simply finds nothing and the sweeper stays idle.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPT_SWEEPER_OPTION' ) ) {
	define( 'WPOPT_SWEEPER_OPTION', 'wpoptimizing_sweeper_enabled' );
}
if ( ! defined( 'WPOPT_SWEEPER_LAST' ) ) {
	define( 'WPOPT_SWEEPER_LAST', 'wpoptimizing_sweeper_last' );
}
if ( ! defined( 'WPOPT_SWEEPER_TOTAL' ) ) {
	define( 'WPOPT_SWEEPER_TOTAL', 'wpoptimizing_sweeper_total' );
}

/**
 * Whether the background sweeper is enabled.
 *
 * @return bool
 */
function wpoptimizing_sweeper_enabled() {
	return (bool) get_option( WPOPT_SWEEPER_OPTION, false );
}

/**
 * Seconds between ticks (filterable). Keeps the per-request cost negligible.
 *
 * @return int
 */
function wpoptimizing_sweeper_interval() {
	return max( 15, (int) apply_filters( 'wpoptimizing_sweeper_interval', 90 ) );
}

/**
 * Max expired transients removed per tick (filterable, hard-capped).
 *
 * @return int
 */
function wpoptimizing_sweeper_batch() {
	return max( 1, min( 1000, (int) apply_filters( 'wpoptimizing_sweeper_batch', 150 ) ) );
}

/**
 * Whether a tick should run now. PURE (testable): enabled, and at least `interval` seconds
 * since the last tick (a never-run last of 0 always passes).
 *
 * @param bool $enabled  Sweeper on.
 * @param int  $last_run Unix time of the previous tick.
 * @param int  $now      Current Unix time.
 * @param int  $interval Minimum seconds between ticks.
 * @return bool
 */
function wpoptimizing_sweeper_should_run( $enabled, $last_run, $now, $interval ) {
	if ( ! $enabled || (int) $interval <= 0 ) {
		return false;
	}
	return ( (int) $now - (int) $last_run ) >= (int) $interval;
}

/**
 * The transient name from a `_transient_timeout_*` / `_site_transient_timeout_*` option name.
 * PURE. Returns '' when the option is not a transient timeout.
 *
 * @param string $timeout_option The timeout option name.
 * @return string
 */
function wpoptimizing_sweeper_name_from_timeout( $timeout_option ) {
	$timeout_option = (string) $timeout_option;
	foreach ( array( '_site_transient_timeout_', '_transient_timeout_' ) as $prefix ) {
		if ( 0 === strpos( $timeout_option, $prefix ) ) {
			return substr( $timeout_option, strlen( $prefix ) );
		}
	}
	return '';
}

/**
 * Remove up to $limit expired transients (regular + single-site "site" transients) from the
 * options table, using WordPress' own delete_transient()/delete_site_transient() so the object
 * cache and both option rows stay consistent. Returns how many were removed.
 *
 * @param int $limit Max to remove this tick.
 * @param int $now   Current Unix time (expiry cutoff).
 * @return int
 */
function wpoptimizing_sweeper_purge_expired_transients( $limit, $now ) {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return 0;
	}
	$limit = max( 1, min( 1000, (int) $limit ) );
	$now   = (int) $now;

	// Regular transients.
	$rows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $now, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	// Single-site "site" transients (in options too; skip on multisite where they live in sitemeta).
	$srows = array();
	if ( ! is_multisite() && count( $rows ) < $limit ) {
		$srows = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d LIMIT %d", $wpdb->esc_like( '_site_transient_timeout_' ) . '%', $now, $limit - count( $rows ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}
	if ( empty( $rows ) && empty( $srows ) ) {
		return 0;
	}

	// Backup-first, like every other delete: both rows of each transient, into ONE backup per
	// day — a tick every 90 seconds must not become hundreds of entries, nor push the other
	// backups of its type out of the retention. No backup, nothing removed this tick.
	$names = array();
	foreach ( array_merge( $rows, $srows ) as $timeout_option ) {
		if ( '' !== wpoptimizing_sweeper_name_from_timeout( (string) $timeout_option ) ) {
			$names[] = (string) $timeout_option;
			$names[] = str_replace( 'transient_timeout_', 'transient_', (string) $timeout_option );
		}
	}
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	if ( ! class_exists( 'WPOptimizing_Backup_Writer' ) || ! ( new WPOptimizing_Backup_Writer( wpoptimizing_backup_new_op_key( 'sweeper' . gmdate( 'Ymd', $now ) ), 'sweeper' ) )->options( $names, 'transient' ) ) {
		return 0;
	}

	$count = 0;
	foreach ( $rows as $timeout_option ) {
		$name = wpoptimizing_sweeper_name_from_timeout( $timeout_option );
		if ( '' !== $name ) {
			delete_transient( $name );
			++$count;
		}
	}
	foreach ( $srows as $timeout_option ) {
		$name = wpoptimizing_sweeper_name_from_timeout( $timeout_option );
		if ( '' !== $name ) {
			delete_site_transient( $name );
			++$count;
		}
	}

	return $count;
}

/**
 * One background tick, on the shutdown of a normal admin request. Cheap and heavily guarded:
 * only for a capable user on a real admin page (never AJAX/cron/REST), only when enabled and the
 * throttle has elapsed, and only one at a time (a short lock transient). Records the tick time
 * (even when nothing was removed) so the throttle holds, and the running total when it did work.
 *
 * @return void
 */
function wpoptimizing_sweeper_tick() {
	if ( ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) || ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return;
	}
	if ( ! is_admin() || ! function_exists( 'wpoptimizing_cap' ) || ! current_user_can( wpoptimizing_cap() ) ) {
		return;
	}
	if ( ! wpoptimizing_sweeper_enabled() ) {
		return;
	}
	$now = time();
	if ( ! wpoptimizing_sweeper_should_run( true, (int) get_option( WPOPT_SWEEPER_LAST, 0 ), $now, wpoptimizing_sweeper_interval() ) ) {
		return;
	}
	// Concurrency lock (short-lived): if another tick holds it, skip this one.
	if ( false !== get_transient( 'wpoptimizing_sweeper_lock' ) ) {
		return;
	}
	set_transient( 'wpoptimizing_sweeper_lock', 1, 30 );
	update_option( WPOPT_SWEEPER_LAST, $now, false ); // Throttle holds even when nothing was expired.

	$swept = wpoptimizing_sweeper_purge_expired_transients( wpoptimizing_sweeper_batch(), $now );
	if ( $swept > 0 ) {
		update_option( WPOPT_SWEEPER_TOTAL, (int) get_option( WPOPT_SWEEPER_TOTAL, 0 ) + $swept, false );
	}

	delete_transient( 'wpoptimizing_sweeper_lock' );
}
add_action( 'shutdown', 'wpoptimizing_sweeper_tick' );
