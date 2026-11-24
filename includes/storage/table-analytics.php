<?php
/**
 * Per-table database analytics: size/row growth between scans and anomalous-growth
 * detection (a log/cache/runaway-actions table ballooning while the rest stays flat).
 *
 * A compact snapshot (name → {b:size_bytes, r:rows}) is stored after each scan. On the next
 * scan the growth versus the previous snapshot is computed and the anomalies are persisted for
 * the Tables tab and the performance report (B9). The growth/anomaly maths is pure so it is
 * unit-testable offline; only the reader and the option store touch WordPress.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPT_TABLE_SNAPSHOT_OPTION' ) ) {
	define( 'WPOPT_TABLE_SNAPSHOT_OPTION', 'wpoptimizing_table_snapshot' );
}
if ( ! defined( 'WPOPT_TABLE_ANOMALIES_OPTION' ) ) {
	define( 'WPOPT_TABLE_ANOMALIES_OPTION', 'wpoptimizing_table_anomalies' );
}

/**
 * Default anomaly thresholds: a table must have grown by at least this many bytes AND by at
 * least this percentage since the previous scan to be flagged. Filterable.
 *
 * @return array{min_bytes:int,min_pct:float}
 */
function wpoptimizing_table_growth_thresholds() {
	$mb = defined( 'MB_IN_BYTES' ) ? MB_IN_BYTES : 1048576;
	return array(
		'min_bytes' => (int) apply_filters( 'wpoptimizing_table_growth_min_bytes', 5 * $mb ),
		'min_pct'   => (float) apply_filters( 'wpoptimizing_table_growth_min_pct', 25.0 ),
	);
}

/**
 * Compute per-table growth between two compact snapshots. PURE.
 *
 * @param array $prev Map name => {b:int,r:int} (previous scan). Missing/empty = no baseline.
 * @param array $curr Map name => {b:int,r:int} (current).
 * @param array $opts Optional 'min_bytes' / 'min_pct' overrides.
 * @return array<string,array{b:int,r:int,delta_bytes:int,delta_rows:int,pct:float,anomalous:bool,is_new:bool}>
 */
function wpoptimizing_table_growth( array $prev, array $curr, array $opts = array() ) {
	$th        = wpoptimizing_table_growth_thresholds();
	$min_bytes = isset( $opts['min_bytes'] ) ? (int) $opts['min_bytes'] : $th['min_bytes'];
	$min_pct   = isset( $opts['min_pct'] ) ? (float) $opts['min_pct'] : $th['min_pct'];

	$out = array();
	foreach ( $curr as $name => $now ) {
		$now_b   = (int) ( is_array( $now ) ? ( $now['b'] ?? 0 ) : 0 );
		$now_r   = (int) ( is_array( $now ) ? ( $now['r'] ?? 0 ) : 0 );
		$has_old = isset( $prev[ $name ] ) && is_array( $prev[ $name ] );
		$old_b   = $has_old ? (int) ( $prev[ $name ]['b'] ?? 0 ) : 0;
		$old_r   = $has_old ? (int) ( $prev[ $name ]['r'] ?? 0 ) : 0;

		$delta_b = $now_b - $old_b;
		$delta_r = $now_r - $old_r;
		// Percentage growth versus the previous size (0 baseline → treat as new, not %-huge).
		$pct = ( $has_old && $old_b > 0 ) ? ( $delta_b / $old_b ) * 100.0 : 0.0;
		// Only flag EXISTING tables that ballooned; a brand-new table is reported as new,
		// not anomalous, so a fresh install/plugin does not spam warnings.
		$anomalous = $has_old && $delta_b >= $min_bytes && $pct >= $min_pct;

		$out[ (string) $name ] = array(
			'b'           => $now_b,
			'r'           => $now_r,
			'delta_bytes' => $delta_b,
			'delta_rows'  => $delta_r,
			'pct'         => round( $pct, 1 ),
			'anomalous'   => $anomalous,
			'is_new'      => ! $has_old,
		);
	}
	return $out;
}

/**
 * The anomalous entries of a growth map, biggest byte-growth first. PURE.
 *
 * @param array $growth Output of wpoptimizing_table_growth().
 * @return array<int,array{name:string,delta_bytes:int,delta_rows:int,pct:float,b:int}>
 */
function wpoptimizing_table_anomalies( array $growth ) {
	$rows = array();
	foreach ( $growth as $name => $g ) {
		if ( empty( $g['anomalous'] ) ) {
			continue;
		}
		$rows[] = array(
			'name'        => (string) $name,
			'delta_bytes' => (int) $g['delta_bytes'],
			'delta_rows'  => (int) $g['delta_rows'],
			'pct'         => (float) $g['pct'],
			'b'           => (int) $g['b'],
		);
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['delta_bytes'] <=> $a['delta_bytes'];
		}
	);
	return $rows;
}

/**
 * Read the current per-table sizes as a compact snapshot map. Uses SHOW TABLE STATUS (the
 * same source as the Tables tab). Works in any context (headless scan included).
 *
 * @return array<string,array{b:int,r:int}>
 */
function wpoptimizing_table_analytics_read_current() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
		return array();
	}
	if ( function_exists( 'wpoptimizing_db_live_stats' ) ) {
		wpoptimizing_db_live_stats();
	}
	$status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( ! is_array( $status ) ) {
		return array();
	}
	$map = array();
	foreach ( $status as $t ) {
		$name = (string) ( $t['Name'] ?? '' );
		if ( '' === $name ) {
			continue;
		}
		$map[ $name ] = array(
			'b' => (int) ( $t['Data_length'] ?? 0 ) + (int) ( $t['Index_length'] ?? 0 ),
			'r' => (int) ( $t['Rows'] ?? 0 ),
		);
	}
	return $map;
}

/**
 * The stored snapshot { ts:int, tables:map }.
 *
 * @return array{ts:int,tables:array<string,array{b:int,r:int}>}
 */
function wpoptimizing_table_analytics_snapshot_get() {
	$s = get_option( WPOPT_TABLE_SNAPSHOT_OPTION, array() );
	if ( ! is_array( $s ) || empty( $s['tables'] ) || ! is_array( $s['tables'] ) ) {
		return array(
			'ts'     => 0,
			'tables' => array(),
		);
	}
	return array(
		'ts'     => (int) ( $s['ts'] ?? 0 ),
		'tables' => $s['tables'],
	);
}

/**
 * Persist a snapshot (non-autoloaded).
 *
 * @param array $tables Map name => {b,r}.
 * @param int   $ts     Timestamp.
 * @return void
 */
function wpoptimizing_table_analytics_snapshot_set( array $tables, $ts ) {
	update_option(
		WPOPT_TABLE_SNAPSHOT_OPTION,
		array(
			'ts'     => (int) $ts,
			'tables' => $tables,
		),
		false
	);
}

/**
 * The anomalies detected at the last scan { ts:int, since:int, items:array }.
 *
 * @return array{ts:int,since:int,items:array<int,array<string,mixed>>}
 */
function wpoptimizing_table_anomalies_get() {
	$a = get_option( WPOPT_TABLE_ANOMALIES_OPTION, array() );
	if ( ! is_array( $a ) ) {
		return array(
			'ts'    => 0,
			'since' => 0,
			'items' => array(),
		);
	}
	return array(
		'ts'    => (int) ( $a['ts'] ?? 0 ),
		'since' => (int) ( $a['since'] ?? 0 ),
		'items' => isset( $a['items'] ) && is_array( $a['items'] ) ? $a['items'] : array(),
	);
}

/**
 * Record a fresh snapshot at scan time: compute growth versus the previous snapshot, persist
 * the anomalies (for the Tables tab + report), then store the new snapshot. Hooked to
 * wpoptimizing_after_scan so the per-table series advances once per scan.
 *
 * @return void
 */
function wpoptimizing_table_analytics_record() {
	$prev = wpoptimizing_table_analytics_snapshot_get();
	$curr = wpoptimizing_table_analytics_read_current();
	if ( empty( $curr ) ) {
		return;
	}
	$now = time();
	if ( ! empty( $prev['tables'] ) ) {
		$growth    = wpoptimizing_table_growth( $prev['tables'], $curr );
		$anomalies = wpoptimizing_table_anomalies( $growth );
		update_option(
			WPOPT_TABLE_ANOMALIES_OPTION,
			array(
				'ts'    => $now,
				'since' => (int) $prev['ts'],
				'items' => $anomalies,
			),
			false
		);
	}
	wpoptimizing_table_analytics_snapshot_set( $curr, $now );
}
add_action( 'wpoptimizing_after_scan', 'wpoptimizing_table_analytics_record', 20 );

/**
 * Thresholds for calling a table oversized. Filterable, because "big" is a property of the
 * site and not of a number somebody once wrote down.
 *
 * @since 0.1.0
 * @return array{bytes:int,rows:int,share:float}
 */
function wpoptimizing_table_size_thresholds() {
	/**
	 * Filters the oversized-table thresholds.
	 *
	 * @since 0.1.0
	 * @param array $thresholds bytes (absolute floor), rows, share (0-1 of total DB size).
	 */
	return (array) apply_filters(
		'wpoptimizing_table_size_thresholds',
		array(
			'bytes' => 50 * MB_IN_BYTES,
			'rows'  => 100000,
			'share' => 0.25,
		)
	);
}

/**
 * Tables whose NAME says the contents are disposable.
 *
 * Not an owner check and not a promise: it is the difference between "this table is large"
 * and "this table is large and it is a cache". The first needs a human to think; the second
 * usually needs a button. The size floor is halved for these, because 25 MB of logs is
 * already worth mentioning while 25 MB of orders is not.
 *
 * @since 0.1.0
 * @param string $name Table name.
 * @return bool
 */
function wpoptimizing_table_is_disposable_by_name( $name ) {
	$name = strtolower( (string) $name );
	foreach ( array( 'cache', '_log', '_logs', 'session', 'transient', '_stats', 'avatar', 'visitor', 'tracking' ) as $needle ) {
		if ( false !== strpos( $name, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Why a table deserves a mention, if it does.
 *
 * TWO INDEPENDENT REASONS, and the second is the one that was missing. A table can belong to
 * a perfectly healthy, active plugin and still be the biggest thing in the database: the
 * finding that prompted this was a 525 MB Gravatar cache holding 1.7 million rows on a site
 * with 3,468 comments. Attribution called it "active" — which it was — and so nothing said a
 * word about it while it held half the database.
 *
 * Absolute size alone is a bad signal: 200 MB of orders is a successful shop. Share of the
 * database is what separates "this site is big" from "this ONE table is the site".
 *
 * Pure: takes numbers, returns reasons. No queries, no options.
 *
 * @since 0.1.0
 * @param array $table       { name, size_bytes, rows, owner_status }.
 * @param int   $total_bytes Total size of all tables considered.
 * @param array $opts        Threshold overrides (same shape as wpoptimizing_table_size_thresholds()).
 * @return string[] Reason keys: 'orphan', 'share', 'rows'. Empty when the table is unremarkable.
 */
function wpoptimizing_table_reasons( array $table, $total_bytes, array $opts = array() ) {
	$t = array_merge( wpoptimizing_table_size_thresholds(), $opts );

	$bytes = max( 0, (int) ( $table['size_bytes'] ?? 0 ) );
	$rows  = max( 0, (int) ( $table['rows'] ?? 0 ) );
	$total = max( 0, (int) $total_bytes );
	$floor = (int) $t['bytes'];

	if ( wpoptimizing_table_is_disposable_by_name( (string) ( $table['name'] ?? '' ) ) ) {
		$floor = (int) round( $floor / 2 );
	}

	$reasons = array();

	// A table owned by something that is not active is worth showing at any size: it is not
	// big, it is UNCLAIMED, and that is a different conversation.
	if ( in_array( (string) ( $table['owner_status'] ?? '' ), array( 'orphan', 'inactive' ), true ) ) {
		$reasons[] = 'orphan';
	}

	// Everything below needs the table to clear the absolute floor first, so a 4 MB table is
	// never "80% of a 5 MB database".
	//
	// AND THE FLOOR IS A PRECONDITION, NOT A REASON. Being over it says nothing on its own:
	// 60 MB of orders inside a 1 GB database is a shop that sells things. Reporting it would
	// turn the Tables tab into a wall of warnings about healthy sites, and a wall of warnings
	// is read exactly once.
	if ( $bytes >= $floor ) {
		if ( $total > 0 && ( $bytes / $total ) >= (float) $t['share'] ) {
			$reasons[] = 'share';
		}
		if ( $rows >= (int) $t['rows'] ) {
			$reasons[] = 'rows';
		}
	}

	return array_values( array_unique( $reasons ) );
}

/**
 * Run wpoptimizing_table_reasons() over a set of tables, biggest first.
 *
 * @since 0.1.0
 * @param array[] $rows Table rows as produced by the Tables tab.
 * @param array   $opts Threshold overrides.
 * @return array[] Only the tables with at least one reason, each with a `reasons` key.
 */
function wpoptimizing_tables_worth_reporting( array $rows, array $opts = array() ) {
	$total = 0;
	foreach ( $rows as $r ) {
		$total += max( 0, (int) ( $r['size_bytes'] ?? 0 ) );
	}

	$out = array();
	foreach ( $rows as $r ) {
		$reasons = wpoptimizing_table_reasons( $r, $total, $opts );
		if ( empty( $reasons ) ) {
			continue;
		}
		$r['reasons'] = $reasons;
		$out[]        = $r;
	}

	usort(
		$out,
		static function ( $a, $b ) {
			return (int) $b['size_bytes'] <=> (int) $a['size_bytes'];
		}
	);

	return $out;
}
