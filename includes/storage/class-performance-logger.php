<?php
/**
 * Performance logger with history.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPOPT_Performance_Logger
 *
 * Records WPO metrics over time so the "History" tab can compare baseline vs
 * now and the improvement between cleanups.
 * Output: wp-content/wpoptimizing/history.jsonl (source of truth, one snapshot
 * per line) plus the legacy history.csv (basic columns, kept for export).
 */
class WPOPT_Performance_Logger {

	/** @var string Legacy historic CSV path. */
	protected $csv_path;

	/** @var string JSONL history path (source of truth). */
	protected $jsonl_path;

	public function __construct() {
		$this->csv_path   = WPOPT_DIR . '/history.csv';
		$this->jsonl_path = WPOPT_DIR . '/history.jsonl';
		$this->maybe_init_csv();
	}

	/**
	 * Takes a snapshot of metrics and persists it (JSONL + legacy CSV).
	 *
	 * The first snapshot ever recorded is labelled 'baseline' automatically so
	 * the UI always has a "where we started" reference, regardless of caller.
	 *
	 * @param string $fase   Phase label ('before_scan','after_scan','after_clean', …).
	 * @param string $op_key Optional backup operation key correlating this snapshot
	 *                       to a cleanup (links backup ↔ metric ↔ improvement).
	 * @return string The UTC timestamp recorded ('Y-m-d H:i:s').
	 */
	public function snapshot( $fase, $op_key = '' ) {
		$metrics = $this->collect_metrics();
		$context = $this->collect_context();
		$time    = gmdate( 'Y-m-d H:i:s' );

		if ( ! file_exists( $this->jsonl_path ) || 0 === (int) @filesize( $this->jsonl_path ) ) {
			$fase = 'baseline';
		}

		$line = wpoptimizing_analytics_encode_snapshot(
			array(
				'ts'      => $time,
				'phase'   => (string) $fase,
				'op_key'  => (string) $op_key,
				'metrics' => $metrics,
				'context' => $context,
			)
		);
		if ( '' !== $line ) {
			$this->append_text( $this->jsonl_path, $line . "\n" );
		}

		// Legacy CSV (basic columns) kept for spreadsheet export / back-compat.
		$this->append_csv(
			array(
				$time,
				$fase,
				$context['wp_version'],
				$context['theme'],
				$context['active_plugins'],
				$metrics['autoload_bytes'],
				$metrics['db_tables'],
				$metrics['db_options'],
				$metrics['db_transients'],
			)
		);

		return $time;
	}

	/**
	 * Trim the JSONL history to a retention window, ALWAYS keeping the pinned baseline and the
	 * very first (origin) snapshot so baseline-vs-now never breaks. Works on the raw lines
	 * (lossless — never re-encodes). $keep_months <= 0 keeps everything.
	 *
	 * @param int    $keep_months Months to retain (0 = indefinite).
	 * @param string $baseline_ts Pinned baseline timestamp to always keep.
	 * @return int Number of snapshots removed.
	 */
	public function prune_history( $keep_months, $baseline_ts = '' ) {
		$keep_months = (int) $keep_months;
		if ( $keep_months <= 0 || ! file_exists( $this->jsonl_path ) ) {
			return 0;
		}
		$raw     = (string) file_get_contents( $this->jsonl_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local history file.
		$lines   = preg_split( '/\r\n|\n|\r/', $raw );
		$entries = array();
		foreach ( (array) $lines as $ln ) {
			$ln = trim( (string) $ln );
			if ( '' === $ln ) {
				continue;
			}
			$obj       = json_decode( $ln, true );
			$entries[] = array(
				'ts'   => is_array( $obj ) ? (string) ( $obj['ts'] ?? '' ) : '',
				'line' => $ln,
			);
		}
		if ( count( $entries ) < 2 ) {
			return 0;
		}
		$cutoff    = gmdate( 'Y-m-d H:i:s', time() - $keep_months * 30 * DAY_IN_SECONDS );
		$origin_ts = (string) $entries[0]['ts']; // Oldest line = origin baseline.
		$kept      = array();
		foreach ( $entries as $e ) {
			if ( '' === $e['ts'] || strcmp( $e['ts'], $cutoff ) >= 0 || $e['ts'] === (string) $baseline_ts || $e['ts'] === $origin_ts ) {
				$kept[] = $e['line'];
			}
		}
		$removed = count( $entries ) - count( $kept );
		if ( $removed <= 0 ) {
			return 0;
		}
		$out = empty( $kept ) ? '' : implode( "\n", $kept ) . "\n";
		$tmp = $this->jsonl_path . '.tmp.' . wp_generate_password( 6, false, false );
		if ( false !== file_put_contents( $tmp, $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
			@rename( $tmp, $this->jsonl_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		} else {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
			return 0;
		}
		return $removed;
	}

	/**
	 * Gathers the metric set for a snapshot. Cheap by design: direct COUNT/SUM
	 * queries plus optional enrichment read from already-cached scan artifacts
	 * (never recomputes a scan). Only counts and byte sizes — never option
	 * values or file contents.
	 *
	 * @return array<string,int> Metric key => value (schema in analytics.php).
	 */
	public function collect_metrics() {
		global $wpdb;

		/*
		 * Matching the autoload column against the single legacy value stopped being the
		 * question WordPress asks in 6.6, which is the version this plugin requires: options
		 * are now stored as 'on', 'auto', 'auto-on' and 'off' too, and core reads the
		 * autoloaded set through wp_autoload_values_to_autoload().
		 * The Autoload tab has used that helper all along; this snapshot did not — so the same
		 * site reported 2 MB on one screen and 12 bytes here, and 12 bytes is what every
		 * analytics baseline and every "since you started" comparison was built on. Measured on
		 * the reference install, where 10,350 options carry 'auto' and six carry 'yes'.
		 */
		$autoload_values = function_exists( 'wpoptimizing_autoload_values' )
			? (array) wpoptimizing_autoload_values()
			: array( 'yes', 'on', 'auto-on', 'auto' );
		$placeholders    = implode( ',', array_fill( 0, count( $autoload_values ), '%s' ) );
		$autoload        = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholders are generated above and the values are bound below; the sniff cannot see through the variable.
				"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ({$placeholders})",
				$autoload_values
			)
		);

		// Only this site's tables. On the main site of a network SHOW TABLES lists every
		// sub-site's, so the metric counted the whole network and its history moved whenever
		// somebody else's plugin created a table.
		$tables = 0;
		foreach ( (array) $wpdb->get_col( 'SHOW TABLES' ) as $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! function_exists( 'wpoptimizing_table_belongs_to_current_blog' )
				|| wpoptimizing_table_belongs_to_current_blog( $table ) ) {
				++$tables;
			}
		}
		$options    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$expired    = self::count_expired_transients();
		$attached   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$metrics = array(
			'autoload_bytes'        => $autoload,
			'db_tables'             => $tables,
			'db_options'            => $options,
			'db_transients'         => $transients,
			'db_transients_expired' => $expired,
			'attachments'           => $attached,
			'backups_bytes'         => $this->backups_dir_bytes(),
		);

		// Optional enrichment from the cached media report (no recompute).
		$report = function_exists( 'wpoptimizing_media_report_get' ) ? wpoptimizing_media_report_get() : false;
		if ( is_array( $report ) ) {
			if ( isset( $report['duplicates']['reclaimable_bytes'] ) ) {
				$metrics['media_dupes_bytes'] = (int) $report['duplicates']['reclaimable_bytes'];
			}
			if ( isset( $report['orphan_files']['size_bytes'] ) ) {
				$metrics['media_orphan_bytes'] = (int) $report['orphan_files']['size_bytes'];
			}
		}

		// Optional server-TTFB average (Server-Timing rollup) — only when measured, so its
		// history card/sparkline builds up from real data.
		if ( function_exists( 'wpoptimizing_server_ttfb_ms' ) ) {
			$ttfb = (int) wpoptimizing_server_ttfb_ms();
			if ( $ttfb > 0 ) {
				$metrics['server_ttfb_ms'] = $ttfb;
			}
		}

		// Optional cache analytics (gracefully absent when cache is off or no data).
		if ( function_exists( 'wpoptimizing_cache_stats_read' ) ) {
			$cache = wpoptimizing_cache_stats_read();
			if ( $cache['has'] ) {
				$metrics['cache_hit_rate']      = $cache['ratio'];
				$metrics['cache_queries_saved'] = $cache['dups'];
			}
		}

		return $metrics;
	}

	/**
	 * Expired transients still in the options table: what the expired-transients cleanup removes.
	 *
	 * The health score used to weigh every transient row, live ones and their timeout rows
	 * included, and pointed at a cleanup that only removes expired ones — on wpoptimizing.com
	 * on 2026-10-12 it kept suggesting a cleanup that had nothing left to do.
	 *
	 * @return int
	 */
	public static function count_expired_transients() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST(option_value AS UNSIGNED) < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', $wpdb->esc_like( '_site_transient_timeout_' ) . '%', time() ) );
	}

	/**
	 * Contextual (non-metric) fields stored alongside the metrics.
	 *
	 * @return array{wp_version:string,theme:string,active_plugins:int}
	 */
	protected function collect_context() {
		return array(
			'wp_version'     => (string) get_bloginfo( 'version' ),
			'theme'          => (string) get_stylesheet(),
			'active_plugins' => count( (array) get_option( 'active_plugins', array() ) ),
		);
	}

	/**
	 * Total size on disk of the backups directory, in bytes (0 if absent).
	 *
	 * @return int
	 */
	protected function backups_dir_bytes() {
		$dir = WPOPT_DIR . '/backups';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$total = 0;
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $file ) {
			$total += (int) $file->getSize();
		}
		return $total;
	}

	/**
	 * Reads the full JSONL history, oldest snapshot first.
	 *
	 * @return array<int,array> Parsed snapshots.
	 */
	public function read_history() {
		if ( ! file_exists( $this->jsonl_path ) ) {
			return array();
		}
		$raw = (string) file_get_contents( $this->jsonl_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return wpoptimizing_analytics_parse_history( $raw );
	}

	/**
	 * Appends raw text to a file (used for the JSONL store).
	 *
	 * @param string $path File path.
	 * @param string $text Text to append.
	 * @return void
	 */
	protected function append_text( $path, $text ) {
		$fh = fopen( $path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}
		// Exclusive lock so concurrent appends never interleave into a half-written line.
		if ( flock( $fh, LOCK_EX ) ) {
			fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			fflush( $fh );
			flock( $fh, LOCK_UN );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Creates header if it doesn't exist.
	 *
	 * @return void
	 */
	protected function maybe_init_csv() {
		if ( file_exists( $this->csv_path ) ) {
			return;
		}
		$fh = fopen( $this->csv_path, 'w' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		fputcsv( $fh, array( 'utc_time', 'phase', 'wp_version', 'theme', 'active_plugins', 'autoload_bytes', 'tables', 'options', 'transients' ), ',', '"', '\\' );
		fclose( $fh ); // phpcs:ignore
	}

	/**
	 * Appends a row to the history.
	 *
	 * @param array $row Row.
	 * @return void
	 */
	protected function append_csv( $row ) {
		$fh = fopen( $this->csv_path, 'a' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		// Exclusive lock so concurrent appends never interleave into a half-written row.
		if ( flock( $fh, LOCK_EX ) ) {
			fputcsv( $fh, $row, ',', '"', '\\' );
			fflush( $fh );
			flock( $fh, LOCK_UN );
		}
		fclose( $fh ); // phpcs:ignore
	}
}
