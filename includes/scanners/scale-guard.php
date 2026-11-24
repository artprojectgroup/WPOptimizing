<?php
/**
 * Scale guards for very large databases (GB-scale, tens of millions of meta rows).
 *
 * Two protections used across the scan/cleanup engine:
 *   - Large-site detection: above a per-table row threshold the engine switches
 *     heavy aggregations to cheaper / approximate variants and marks results
 *     partial, instead of running multi-minute full-value scans on the live DB.
 *   - A per-connection SQL statement timeout so a runaway SELECT aborts cleanly
 *     rather than dragging the database or tripping the scan watchdog.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Row count (in a single table) at or above which the DB is treated as "large".
 *
 * @return int
 */
function wpoptimizing_large_site_threshold() {
	$t = (int) apply_filters( 'wpoptimizing_large_site_threshold', 5000000 );
	return $t > 0 ? $t : 5000000;
}

/**
 * Pure: is a row count "large" relative to a threshold? (Unit-testable.)
 *
 * @param int $rows      Row count.
 * @param int $threshold Threshold.
 * @return bool
 */
function wpoptimizing_rows_are_large( $rows, $threshold ) {
	return (int) $threshold > 0 && (int) $rows >= (int) $threshold;
}

/**
 * Largest table row count for the current database (approximate, from
 * information_schema — instant, no full scan). Cached for an hour.
 *
 * @return int
 */
function wpoptimizing_db_max_table_rows() {
	$cached = get_transient( 'wpoptimizing_db_max_rows' );
	if ( false !== $cached ) {
		return (int) $cached;
	}
	global $wpdb;
	// Cached for an hour, so this runs rarely — but when it does it decides whether the whole
	// scan takes the approximate path, and a figure from before the last cleanup would keep a
	// site on the slow branch long after it stopped being large.
	wpoptimizing_db_live_stats();
	$n = (int) $wpdb->get_var( 'SELECT MAX(TABLE_ROWS) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	set_transient( 'wpoptimizing_db_max_rows', $n, HOUR_IN_SECONDS );
	return $n;
}

/**
 * Whether the engine should use large-site mode (approximate/sampled aggregations).
 * A boolean returned by the `wpoptimizing_is_large_site` filter overrides detection.
 *
 * @return bool
 */
function wpoptimizing_scan_large_site() {
	$override = apply_filters( 'wpoptimizing_is_large_site', null );
	if ( is_bool( $override ) ) {
		return $override;
	}
	return wpoptimizing_rows_are_large( wpoptimizing_db_max_table_rows(), wpoptimizing_large_site_threshold() );
}

/**
 * Apply a per-session SQL statement timeout so a runaway read aborts on its own.
 *
 * MySQL 5.7.8+ honours `max_execution_time` (milliseconds, SELECT only); MariaDB
 * honours `max_statement_time` (seconds). We set both; the irrelevant one errors
 * harmlessly (suppressed). Persists for the connection — intended for scan/cron
 * requests and on-demand admin aggregations, both short-lived contexts.
 *
 * @param int $seconds Maximum seconds a statement may run (>=1).
 * @return void
 */
function wpoptimizing_db_statement_timeout( $seconds ) {
	$seconds = max( 1, (int) $seconds );
	$seconds = (int) apply_filters( 'wpoptimizing_db_statement_timeout', $seconds );
	global $wpdb;
	$prev = $wpdb->suppress_errors( true );
	$wpdb->query( $wpdb->prepare( 'SET SESSION max_execution_time = %d', $seconds * 1000 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL session guard.
	$wpdb->query( $wpdb->prepare( 'SET SESSION max_statement_time = %d', $seconds ) );        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MariaDB session guard.
	$wpdb->suppress_errors( $prev );
}

/**
 * Lift the statement timeout again (0 = no limit, the server default) once the bounded work
 * is done. It is a SESSION variable: left in place, a limit meant for one heavy read also
 * stopped whatever ran after it in the same request — on MariaDB, deletes and ALTERs too.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_db_statement_timeout_clear() {
	global $wpdb;
	$prev = $wpdb->suppress_errors( true );
	$wpdb->query( 'SET SESSION max_execution_time = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL session guard.
	$wpdb->query( 'SET SESSION max_statement_time = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- MariaDB session guard.
	$wpdb->suppress_errors( $prev );
}

/**
 * Ask MySQL for LIVE table statistics for the rest of this request.
 *
 * MySQL 8 serves SHOW TABLE STATUS and information_schema.TABLES from cached data-dictionary
 * statistics, refreshed at most every `information_schema_stats_expiry` seconds — 24 hours by
 * default. So the size of a table you have just emptied stays exactly where it was, for a day.
 *
 * WHY THIS IS A FUNCTION AND NOT A LINE IN ONE PLACE. Six code paths in this plugin read table
 * sizes or row counts and exactly one of them armed this, which meant the numbers the plugin
 * showed disagreed with each other depending on which screen you were on. The worst of them
 * was the cleanup report: it measures the database before and after, and both readings came
 * from statistics that could not have noticed the deletion in between — an optimisation plugin
 * reporting "0 bytes freed" after freeing hundreds of megabytes. The growth detector and the
 * auto-cleanup threshold read the same stale numbers and decided whether to act on them.
 *
 * Once per request is enough: it is a SESSION variable. Unknown on MariaDB, which always
 * reports live figures, so the error is suppressed and ignored.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_db_live_stats() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	global $wpdb;
	if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
		return;
	}
	$prev = $wpdb->suppress_errors( true );
	$wpdb->query( 'SET SESSION information_schema_stats_expiry = 0' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Session flag; unknown on MariaDB, which is already live.
	$wpdb->suppress_errors( $prev );
}

/**
 * Next batch size, from how long the last one took.
 *
 * WHY A CONSTANT IS THE WRONG ANSWER. Every batch size in this plugin is a number somebody
 * picked once: 1,000 rows here, 5,000 there, 50 for a bulk delete. On a laptop those finish
 * instantly and the scan wastes its time budget on round trips; on shared hosting with a cold
 * disk the same number is what makes a request die halfway through. The right size is not a
 * property of the task, it is a property of the server, and the server will tell you if you
 * time it.
 *
 * A DAMPED CONTROLLER, not a calculator. Doubling and halving on a single reading chases
 * noise: one slow batch because a backup kicked in, and the next hundred are tiny. Each step
 * may at most double or halve, so a transient blip costs one adjustment and corrects itself,
 * while a genuinely slow server converges in a handful of rounds.
 *
 * Pure.
 *
 * @since 0.1.0
 * @param int   $current Batch size just used.
 * @param float $elapsed Seconds that batch took.
 * @param float $target  Seconds a batch should take.
 * @param int   $min     Floor.
 * @param int   $max     Ceiling.
 * @return int The size to use next.
 */
function wpoptimizing_tune_batch( $current, $elapsed, $target = 0.5, $min = 200, $max = 20000 ) {
	$current = max( 1, (int) $current );
	$elapsed = (float) $elapsed;
	$target  = max( 0.05, (float) $target );
	$min     = max( 1, (int) $min );
	$max     = max( $min, (int) $max );

	// Too fast to measure. Grow, but at the same damped rate as everything else — a batch that
	// reports 0 seconds is usually a batch that returned nothing, not a fast server.
	$ratio = ( $elapsed <= 0.0 ) ? 2.0 : ( $target / $elapsed );

	// Never more than double or less than half in one step.
	$ratio = max( 0.5, min( 2.0, $ratio ) );

	return (int) max( $min, min( $max, (int) round( $current * $ratio ) ) );
}

/**
 * The learned batch size for an operation, or the caller's default the first time.
 *
 * Stored per operation rather than globally: reading a column of postmeta and rebuilding a
 * table are not the same work, and a size learned from one is meaningless for the other.
 *
 * @since 0.1.0
 * @param string $op      Operation key.
 * @param int    $default Size to start from.
 * @return int
 */
function wpoptimizing_batch_size( $op, $default ) {
	$sizes = (array) get_option( 'wpoptimizing_batch_sizes', array() );
	$op    = sanitize_key( (string) $op );
	return isset( $sizes[ $op ] ) ? max( 1, (int) $sizes[ $op ] ) : max( 1, (int) $default );
}

/**
 * Remember what an operation settled on.
 *
 * ONLY WHEN IT MOVED, and only past a threshold. This is called once per batch, and writing an
 * option on every one would mean a scan that spends its time recording how fast it is going.
 *
 * @since 0.1.0
 * @param string $op   Operation key.
 * @param int    $size Size to remember.
 * @return void
 */
function wpoptimizing_batch_size_learn( $op, $size ) {
	$op   = sanitize_key( (string) $op );
	$size = max( 1, (int) $size );
	if ( '' === $op ) {
		return;
	}

	$sizes = (array) get_option( 'wpoptimizing_batch_sizes', array() );
	$prev  = isset( $sizes[ $op ] ) ? (int) $sizes[ $op ] : 0;

	// A 25% move is worth recording; anything smaller is the controller breathing.
	if ( $prev > 0 && abs( $size - $prev ) < max( 1, (int) round( $prev * 0.25 ) ) ) {
		return;
	}

	$sizes[ $op ] = $size;
	update_option( 'wpoptimizing_batch_sizes', $sizes, false );
}

/**
 * Whether the query that just ran failed, remembering it for the stage that ran it.
 *
 * A FAILED QUERY IS NOT "NOTHING FOUND". wpdb answers an error with null or an empty array,
 * and `(array) null` is an empty list: when the statement timeout killed a query on a big
 * database, the stage read "no meta keys", "no orphans", "nothing to remove" — and wrote that
 * over the last good result. Only the media sweep checked. Every heavy query of the database
 * stages now asks this right after running; the stage then keeps its previous result and the
 * screens say the analysis was partial (wpoptimizing_scan_partial_set()).
 *
 * @since 0.1.0
 * @param string $what Short name of the query, for the notice and the log.
 * @return bool True when it failed.
 */
function wpoptimizing_db_failed( $what ) {
	global $wpdb;
	$err = isset( $wpdb->last_error ) ? (string) $wpdb->last_error : '';
	if ( '' === $err ) {
		return false;
	}
	$GLOBALS['wpoptimizing_db_failures'][] = array(
		'what'  => (string) $what,
		'error' => substr( $err, 0, 200 ),
	);
	return true;
}

/**
 * The failures recorded since the last call, and forget them.
 *
 * @since 0.1.0
 * @return array<int,array{what:string,error:string}>
 */
function wpoptimizing_db_failures_take() {
	$failures                            = isset( $GLOBALS['wpoptimizing_db_failures'] ) ? (array) $GLOBALS['wpoptimizing_db_failures'] : array();
	$GLOBALS['wpoptimizing_db_failures'] = array();
	return $failures;
}

/**
 * Record (or clear) that a stage of the analysis could not read everything.
 *
 * @since 0.1.0
 * @param string $stage    Stage key ('impact_db', 'empty_db').
 * @param array  $failures From wpoptimizing_db_failures_take(); empty clears the stage.
 * @return void
 */
function wpoptimizing_scan_partial_set( $stage, array $failures ) {
	$all = (array) get_option( 'wpoptimizing_scan_partial', array() );
	if ( empty( $failures ) ) {
		if ( ! isset( $all[ $stage ] ) ) {
			return;
		}
		unset( $all[ $stage ] );
	} else {
		$all[ $stage ] = array(
			'at'       => time(),
			'failures' => array_slice( array_values( $failures ), 0, 10 ),
		);
	}
	update_option( 'wpoptimizing_scan_partial', $all, false );
}

/**
 * The stages of the last analysis that could not read everything.
 *
 * @since 0.1.0
 * @return array<string,array{at:int,failures:array}>
 */
function wpoptimizing_scan_partial_get() {
	return (array) get_option( 'wpoptimizing_scan_partial', array() );
}
