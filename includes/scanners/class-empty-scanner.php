<?php
/**
 * Empty-records scanner.
 *
 * Lists every database record whose stored value is EMPTY — useful for cleaning up
 * leftover keys that no longer hold anything. "Empty" means: NULL, the empty string, or a
 * serialized/JSON empty structure (a:0:{}, [], {}, s:0:"";, N;). It deliberately does NOT
 * count '0' / 'false' / whitespace — those carry meaning.
 *
 * Scope: options (excluding transients, which have their own cleanup) and post/user/term
 * meta. Rows are grouped by (type, key) with a count, matching the rest of the UI. The
 * effective owner/status (pending/orphan/inactive/active) is resolved later at display time
 * by the attribution engine (overlay_runtime_attributions); this scanner only finds the
 * empties and writes them in the shared DB-scan CSV schema.
 *
 * Produces: WPOPT_CSV_SCAN_EMPTY with columns
 *   type, value, suggested_status, project_status, project_origins, project_slugs,
 *   size_bytes, count_rows, confianza
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Empty_Scanner {

	/** @var string Destination CSV path. */
	protected $csv_result_path;

	/**
	 * @param string $csv_result_path Optional override (defaults to WPOPT_CSV_SCAN_EMPTY).
	 */
	public function __construct( $csv_result_path = '' ) {
		$this->csv_result_path = $csv_result_path ?: WPOPT_CSV_SCAN_EMPTY;
	}

	/**
	 * The exact set of literal values considered "empty" (besides SQL NULL). Centralised so
	 * the scanner and the deleter agree byte-for-byte — the delete MUST only ever touch rows
	 * this scanner would have listed.
	 *
	 * @return string[]
	 */
	public static function empty_value_literals() {
		return array(
			'',          // Empty string.
			'a:0:{}',    // Serialized empty array.
			'[]',        // JSON empty array.
			'{}',        // JSON empty object.
			's:0:"";',   // Serialized empty string.
			'N;',        // Serialized null.
		);
	}

	/**
	 * SQL boolean fragment matching an empty value in the given (already-safe) column name.
	 * Returns the fragment plus the bound parameters to pass to $wpdb->prepare().
	 *
	 * @param string $column Column identifier (must be a trusted literal, e.g. 'meta_value').
	 * @return array{0:string,1:array<int,string>} [sql, params]
	 */
	public static function empty_sql_condition( $column ) {
		$literals     = self::empty_value_literals();
		$placeholders = implode( ', ', array_fill( 0, count( $literals ), '%s' ) );
		$sql          = "( {$column} IS NULL OR {$column} IN ( {$placeholders} ) )";
		return array( $sql, $literals );
	}

	/**
	 * Run the scan and write the result CSV (atomic temp + rename, like the impact cleaner).
	 *
	 * @return void
	 */
	public function run() {
		global $wpdb;

		$max_rows = defined( 'WPOPT_MAX_SCAN_ROWS' ) ? (int) WPOPT_MAX_SCAN_ROWS : 50000;
		$rows     = array();
		$failed   = static function ( $what ) {
			return function_exists( 'wpoptimizing_db_failed' ) && wpoptimizing_db_failed( 'empty records: ' . $what );
		};
		if ( function_exists( 'wpoptimizing_db_failures_take' ) ) {
			wpoptimizing_db_failures_take();
		}

		// --- Options (exclude transients: those are cleaned in their own section) ---
		list( $cond, $params ) = self::empty_sql_condition( 'option_value' );
		$opts_table            = $wpdb->options;
		$sql                   = "SELECT option_name FROM {$opts_table}
			WHERE {$cond}
			AND option_name NOT LIKE %s AND option_name NOT LIKE %s
			ORDER BY option_name LIMIT %d";
		$args                  = array_merge( $params, array( $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%', $max_rows ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$names = $wpdb->get_col( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- core options table; values bound with placeholders.
		$failed( 'options' );
		foreach ( (array) $names as $name ) {
			$rows[] = array( 'option', (string) $name, '', '', '', '', 0, 1, '' );
		}

		// --- Meta tables: group by key, count the empty rows ---
		$meta_tables = array(
			'post_meta' => $wpdb->postmeta,
			'user_meta' => $wpdb->usermeta,
			'term_meta' => $wpdb->termmeta,
		);
		foreach ( $meta_tables as $type => $table ) {
			if ( empty( $table ) ) {
				continue;
			}
			// A meta key qualifies ONLY when EVERY one of its rows is empty — never when just
			// some are (e.g. "Payer last name" with 24 blanks among hundreds of real names
			// must NOT appear). So group by key and keep only keys with ZERO non-empty rows;
			// the reported count is then the total rows for the key (all of them empty).
			$literals = self::empty_value_literals();
			$ph       = implode( ', ', array_fill( 0, count( $literals ), '%s' ) );
			$msql     = "SELECT meta_key, COUNT(*) AS c FROM {$table}
				GROUP BY meta_key
				HAVING SUM( CASE WHEN meta_value IS NOT NULL AND meta_value NOT IN ( {$ph} ) THEN 1 ELSE 0 END ) = 0
				ORDER BY c DESC LIMIT %d";
			$margs    = array_merge( $literals, array( $max_rows ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$grouped = $wpdb->get_results( $wpdb->prepare( $msql, $margs ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$failed( $type );
			foreach ( (array) $grouped as $g ) {
				$key = (string) ( $g['meta_key'] ?? '' );
				if ( '' === $key ) {
					continue; // A blank meta_key is not actionable from this view.
				}
				$rows[] = array( $type, $key, '', '', '', '', 0, (int) ( $g['c'] ?? 1 ), '' );
			}
			if ( count( $rows ) >= $max_rows ) {
				break;
			}
		}

		if ( count( $rows ) > $max_rows ) {
			$rows = array_slice( $rows, 0, $max_rows );
		}

		// As in WPOPT_Impact_Cleaner::run(): a cut-short query keeps the last good list.
		$failures = function_exists( 'wpoptimizing_db_failures_take' ) ? wpoptimizing_db_failures_take() : array();
		if ( function_exists( 'wpoptimizing_scan_partial_set' ) ) {
			wpoptimizing_scan_partial_set( 'empty_db', $failures );
		}
		if ( ! empty( $failures ) && file_exists( $this->csv_result_path ) ) {
			return;
		}
		$this->save_csv( $rows );
	}

	/**
	 * Write the CSV with the shared DB-scan header, then atomically swap it in.
	 *
	 * @param array<int,array{0:string,1:string,2:string,3:string,4:string,5:string,6:int,7:int,8:string}> $rows Rows.
	 * @return void
	 */
	protected function save_csv( $rows ) {
		$tmp_path = wpoptimizing_atomic_tmp_path( $this->csv_result_path );
		if ( file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		$fh = fopen( $tmp_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}
		fputcsv( $fh, array( 'type', 'value', 'suggested_status', 'project_status', 'project_origins', 'project_slugs', 'size_bytes', 'count_rows', 'confianza' ), ',', '"', '\\' );
		foreach ( (array) $rows as $item ) {
			fputcsv( $fh, $item, ',', '"', '\\' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Atomic swap: a reader never sees a partially written file (matches WPOPT_Impact_Cleaner).
		if ( file_exists( $tmp_path ) ) {
			rename( $tmp_path, $this->csv_result_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename, WordPress.WP.AlternativeFunctions.rename_rename
		}
	}
}
