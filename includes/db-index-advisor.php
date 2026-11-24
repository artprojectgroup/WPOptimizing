<?php
/**
 * Database index advisor — pure analysis core (read-only, no external deps).
 *
 * Interprets a MySQL EXPLAIN row for a slow query and, only in the unambiguous single-column
 * WHERE case, proposes an index the user can copy. It NEVER runs DDL and never touches the
 * database; the profiler wiring that feeds it EXPLAIN rows lives elsewhere (see
 * docs/diseno-index-advisor.md). Every function here is pure and unit-tested.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalise a MySQL EXPLAIN row to boolean performance flags. Pure.
 *
 * @param array $row EXPLAIN row (keys: type, key, rows, Extra — case as MySQL returns them).
 * @return array{full_scan:bool,no_key:bool,filesort:bool,temp_table:bool,rows:int}
 */
function wpoptimizing_dbindex_classify( array $row ) {
	$type  = strtolower( trim( (string) ( $row['type'] ?? '' ) ) );
	$key   = $row['key'] ?? null;
	$extra = strtolower( (string) ( $row['Extra'] ?? $row['extra'] ?? '' ) );
	$rows  = (int) ( $row['rows'] ?? 0 );

	return array(
		'full_scan'  => ( 'all' === $type ),
		'no_key'     => ( null === $key || '' === $key ),
		'filesort'   => ( false !== strpos( $extra, 'using filesort' ) ),
		'temp_table' => ( false !== strpos( $extra, 'using temporary' ) ),
		'rows'       => $rows,
	);
}

/**
 * Build a finding for one EXPLAIN row, or null when there is nothing worth advising (e.g. a
 * full scan over few rows is harmless). Pure.
 *
 * @param array $row      EXPLAIN row (also read: 'table').
 * @param int   $min_rows Only flag a full scan when it scans at least this many rows.
 * @return array{severity:string,code:string,label:string,table:string,rows:int}|null
 */
function wpoptimizing_dbindex_explain_finding( array $row, $min_rows = 500 ) {
	$min_rows = max( 1, (int) $min_rows );
	$f        = wpoptimizing_dbindex_classify( $row );
	$table    = trim( (string) ( $row['table'] ?? '' ) );

	// A full table scan with no usable index over a large table: the classic missing-index case.
	if ( $f['full_scan'] && $f['no_key'] && $f['rows'] >= $min_rows ) {
		return array(
			'severity' => 'warn',
			'code'     => 'full_scan_no_index',
			'label'    => 'Full table scan with no usable index',
			'table'    => $table,
			'rows'     => $f['rows'],
		);
	}
	// A large full scan that DOES have a candidate index but is not using it: lower severity.
	if ( $f['full_scan'] && $f['rows'] >= $min_rows ) {
		return array(
			'severity' => 'info',
			'code'     => 'full_scan',
			'label'    => 'Full table scan',
			'table'    => $table,
			'rows'     => $f['rows'],
		);
	}
	// Sorting/grouping into a temp table or filesort on a large row set is worth surfacing.
	if ( ( $f['filesort'] || $f['temp_table'] ) && $f['rows'] >= $min_rows ) {
		return array(
			'severity' => 'info',
			'code'     => $f['temp_table'] ? 'temp_table' : 'filesort',
			'label'    => $f['temp_table'] ? 'Using a temporary table' : 'Using filesort',
			'table'    => $table,
			'rows'     => $f['rows'],
		);
	}
	return null;
}

/**
 * Extract the candidate column ONLY from the unambiguous single-column equality WHERE
 * (`WHERE col = ...` or `WHERE `col` = ...`). Returns '' for anything else — JOINs, OR,
 * ranges, functions, multiple predicates — so we never guess a wrong index. Pure.
 *
 * @param string $sql The query.
 * @return string Column name, or '' when the pattern is not a single-column equality.
 */
function wpoptimizing_dbindex_single_column_where( $sql ) {
	$sql = (string) $sql;
	// Isolate the WHERE clause up to the next clause keyword.
	if ( ! preg_match( '/\bWHERE\b(.+?)(\bGROUP\b|\bORDER\b|\bLIMIT\b|\bHAVING\b|$)/is', $sql, $m ) ) {
		return '';
	}
	$where = trim( $m[1] );
	// Reject compound / range / function predicates: keep only a lone equality.
	if ( preg_match( '/\b(AND|OR|BETWEEN|LIKE|IN|IS|<|>|!=|<>)\b|[<>]/i', $where ) ) {
		return '';
	}
	// `col` = ... | table.col = ... | col = ...  (equality only).
	if ( preg_match( '/^`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*=\s*/', $where, $c )
		|| preg_match( '/^`?[a-zA-Z_][a-zA-Z0-9_]*`?\.`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*=\s*/', $where, $c ) ) {
		return $c[1];
	}
	return '';
}

/**
 * Build the suggested CREATE INDEX statement (text to copy), or '' when data is missing or
 * the identifiers are not safe. Pure — produces text only, never executes anything.
 *
 * @param string $table  Table name.
 * @param string $column Column name.
 * @return string
 */
function wpoptimizing_dbindex_suggest( $table, $column ) {
	$table  = trim( (string) $table );
	$column = trim( (string) $column );
	// Only emit for plain identifiers (defensive: this string may be shown/copied verbatim).
	if ( '' === $table || '' === $column
		|| ! preg_match( '/^[a-zA-Z0-9_]+$/', $table )
		|| ! preg_match( '/^[a-zA-Z0-9_]+$/', $column ) ) {
		return '';
	}
	$index = 'wpopt_' . $column;
	return sprintf( 'CREATE INDEX `%s` ON `%s` (`%s`);', $index, $table, $column );
}

/**
 * The statement that actually CREATES a suggested index. Pure.
 *
 * `CREATE INDEX` lets the server pick the algorithm, and on a big InnoDB table some servers
 * pick one that blocks writes for the whole build: a `postmeta` of a few million rows meant a
 * shop that could not take orders while the index was built, inside a web request that could
 * time out halfway. `ALTER TABLE … ADD INDEX, ALGORITHM=INPLACE, LOCK=NONE` asks for the
 * online build and, if the server cannot do it (MyISAM, very old MariaDB), it REFUSES instead
 * of silently locking — which is the behaviour we want: the caller decides what to do then.
 *
 * @param string $table    Validated table name.
 * @param string $index    Index name.
 * @param string $col_spec Column spec, already quoted (e.g. "`meta_value`(191)").
 * @param bool   $online   Ask for the non-locking build.
 * @return string
 */
function wpoptimizing_dbindex_apply_sql( $table, $index, $col_spec, $online = true ) {
	$sql = sprintf( 'ALTER TABLE `%s` ADD INDEX `%s` (%s)', $table, $index, $col_spec );
	return $online ? $sql . ', ALGORITHM=INPLACE, LOCK=NONE' : $sql;
}

/**
 * Below this many rows a table is small enough to lock for the build when the server cannot
 * do it online: the lock lasts well under a second.
 *
 * @return int
 */
function wpoptimizing_dbindex_lock_ok_rows() {
	return (int) apply_filters( 'wpoptimizing_dbindex_lock_ok_rows', 100000 );
}

/**
 * From a $wpdb->queries array (each entry [0]=sql, [1]=seconds, [2]=caller), pick the
 * slowest SELECT reads worth EXPLAINing. Pure.
 *
 * @param array $queries The $wpdb->queries array (SAVEQUERIES format).
 * @param int   $limit   Maximum queries to return.
 * @param float $min_sec Ignore queries faster than this.
 * @return array<int,array{sql:string,time_ms:int,caller:string}>
 */
function wpoptimizing_dbindex_pick_slow_selects( array $queries, $limit = 3, $min_sec = 0.02 ) {
	$limit   = max( 1, (int) $limit );
	$min_sec = (float) $min_sec;
	$rows    = array();
	foreach ( $queries as $entry ) {
		$sql = isset( $entry[0] ) ? trim( (string) $entry[0] ) : '';
		$sec = isset( $entry[1] ) ? (float) $entry[1] : 0.0;
		if ( '' === $sql || $sec < $min_sec ) {
			continue;
		}
		if ( ! preg_match( '/^\(?\s*SELECT\b/i', $sql ) ) {
			continue; // Only SELECT reads are EXPLAIN-worthy for a missing-index diagnosis.
		}
		$rows[] = array(
			'sql'     => $sql,
			'time_ms' => (int) round( $sec * 1000.0 ),
			'caller'  => isset( $entry[2] ) ? (string) $entry[2] : '',
		);
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			return $b['time_ms'] - $a['time_ms'];
		}
	);
	return array_slice( $rows, 0, $limit );
}

/**
 * Guess the owning plugin/theme slug from a $wpdb->queries caller stack (the LAST
 * plugin/theme in the path is the closest to the query). Pure.
 *
 * @param string $caller The caller string ("require(...), ..., include('.../plugins/x/...')").
 * @return string "plugin:slug" | "theme:slug" | '' when core/unknown.
 */
function wpoptimizing_dbindex_owner_from_caller( $caller ) {
	$caller = (string) $caller;
	if ( preg_match_all( '#/(plugins|themes)/([a-z0-9][a-z0-9._-]*)#i', $caller, $m, PREG_SET_ORDER ) ) {
		$last = end( $m );
		$kind = ( 'themes' === strtolower( $last[1] ) ) ? 'theme' : 'plugin';
		return $kind . ':' . strtolower( $last[2] );
	}
	return '';
}
