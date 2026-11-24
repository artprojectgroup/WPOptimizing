<?php
/**
 * "History" tab: performance analytics over time.
 *
 * Read-only view composed into WPOPT_Admin_UI. Shows baseline-vs-now headline
 * cards and a timeline of every recorded snapshot with the change versus the
 * previous one. Data comes from WPOPT_Performance_Logger (history.jsonl); all the
 * number crunching lives in includes/analytics.php (pure, unit-tested).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_History_Trait {

	/**
	 * Record an "after cleanup" performance snapshot, correlated to the current
	 * backup operation so the History tab can show the improvement it produced.
	 * Cheap (a handful of COUNT/SUM queries); called once per finished operation.
	 *
	 * @return void
	 */
	protected function record_cleanup_snapshot() {
		if ( class_exists( 'WPOPT_Performance_Logger' ) ) {
			( new WPOPT_Performance_Logger() )->snapshot( 'after_clean', (string) $this->backup_op_key );
		}
	}

	/**
	 * Render the History tab.
	 *
	 * @return void
	 */
	protected function render_history_tab() {
		$history = ( new WPOPT_Performance_Logger() )->read_history();

		$this->render_section_header(
			__( 'Performance history', 'wpoptimizing' ),
			__( 'Where you started versus where you are now, and the change after each scan and cleanup. Snapshots are taken automatically when you scan or clean.', 'wpoptimizing' ),
			array(
				'icon' => 'chart-area',
				'doc'  => 'wpopt-doc-history',
			)
		);

		if ( count( $history ) < 1 ) {
			printf(
				'<div class="notice notice-info inline wpopt-notice"><p>%s</p></div>',
				esc_html__( 'No history yet. Run a scan to record the first (baseline) snapshot.', 'wpoptimizing' )
			);
			$this->render_history_toolbar( false );
			return;
		}

		$baseline_ts = (string) get_option( 'wpoptimizing_history_baseline_ts', '' );
		$baseline    = wpoptimizing_analytics_baseline( $history, $baseline_ts );
		$latest      = wpoptimizing_analytics_latest( $history );
		$schema      = wpoptimizing_analytics_schema();

		$this->render_history_toolbar( true );
		if ( '' !== $baseline_ts ) {
			$baseline_local = get_date_from_gmt( (string) ( $baseline['ts'] ?? $baseline_ts ), 'Y-m-d H:i:s' );
			printf(
				'<p class="description wpopt-desc-tight">%s</p>',
				sprintf(
					/* translators: %s: baseline date/time (site timezone). */
					esc_html__( 'Baseline pinned at %s.', 'wpoptimizing' ),
					esc_html( $baseline_local )
				)
			);
		}
		$this->render_history_cards( $baseline, $latest, $schema, $history );
		$this->render_history_timeline( $history, $schema );
	}

	/**
	 * Toolbar with the "Set baseline to now" and "Export CSV" actions.
	 *
	 * @param bool $has_history Whether any snapshot exists.
	 * @return void
	 */
	protected function render_history_toolbar( $has_history ) {
		echo '<div class="wpopt-btn-row">';

		echo '<form method="post">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="tab" value="history" />';
		echo '<button type="submit" name="wpopt_history_baseline" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Pin a new baseline at the current state? Past snapshots are kept; comparisons will start from now.', 'wpoptimizing' ) ) ) . ')">' . esc_html__( 'Set baseline to now', 'wpoptimizing' ) . '</button>';
		echo '</form>';

		if ( $has_history ) {
			echo '<form method="post">';
			wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
			echo '<input type="hidden" name="tab" value="history" />';
			echo '<button type="submit" name="wpopt_history_export" value="1" class="button">' . esc_html__( 'Export CSV', 'wpoptimizing' ) . '</button>';
			echo '</form>';
		}

		echo '</div>';
	}

	/**
	 * Retention control for the timeline (SelectWoo dropdown + aligned Save button), rendered
	 * below the timeline. Data is auto-pruned daily; the pinned baseline and the origin snapshot
	 * are always kept. Reuses the shared 'wpopt-select2-auto' dropdown so it matches the list-table
	 * filters exactly (same SelectWoo look, same 32px height) instead of a bespoke native control.
	 *
	 * @return void
	 */
	protected function render_history_retention_control() {
		$retention = function_exists( 'wpoptimizing_history_retention_months' ) ? wpoptimizing_history_retention_months() : 3;
		echo '<form method="post" class="wpopt-hist-retention wpopt-inline-select">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="tab" value="history" />';
		echo '<label for="wpopt-hist-retention">' . esc_html__( 'Keep timeline data:', 'wpoptimizing' ) . '</label> ';
		echo '<select id="wpopt-hist-retention" name="wpopt_history_retention" class="wpopt-select2-auto">';
		$ret_opts = array(
			0  => __( 'Forever', 'wpoptimizing' ),
			3  => __( '3 months', 'wpoptimizing' ),
			6  => __( '6 months', 'wpoptimizing' ),
			12 => __( '1 year', 'wpoptimizing' ),
		);
		foreach ( $ret_opts as $v => $label ) {
			printf( '<option value="%d"%s>%s</option>', (int) $v, selected( $retention, $v, false ), esc_html( $label ) );
		}
		echo '</select> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Save', 'wpoptimizing' ) . '</button>';
		echo '</form>';
	}

	/**
	 * Pin a new baseline at the current state: record a fresh snapshot and store
	 * its timestamp as the baseline marker. Premium/cap checks happen upstream.
	 *
	 * @return void
	 */
	public function reset_history_baseline() {
		if ( ! class_exists( 'WPOPT_Performance_Logger' ) ) {
			return;
		}
		$ts = ( new WPOPT_Performance_Logger() )->snapshot( 'baseline' );
		if ( is_string( $ts ) && '' !== $ts ) {
			update_option( 'wpoptimizing_history_baseline_ts', $ts, false );
		}
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_baseline',
				'msg'  => __( 'New performance baseline pinned at the current state.', 'wpoptimizing' ),
				'type' => 'updated',
			),
			60
		);
	}

	/**
	 * Stream the performance history as a CSV download and exit.
	 *
	 * @return void
	 */
	public function stream_history_csv() {
		$history = ( new WPOPT_Performance_Logger() )->read_history();
		$rows    = wpoptimizing_analytics_history_to_csv( $history );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wpoptimizing-history-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		foreach ( $rows as $row ) {
			// Downloaded file: neutralise cells a spreadsheet would evaluate as a formula.
			fputcsv( $out, wpoptimizing_csv_safe_row( (array) $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Baseline-vs-now cards for the headline metrics.
	 *
	 * @param array $baseline Baseline snapshot.
	 * @param array $latest   Most recent snapshot.
	 * @param array $schema   Metric schema.
	 * @param array $history  Full parsed history (for the trend sparkline).
	 * @return void
	 */
	protected function render_history_cards( array $baseline, array $latest, array $schema, array $history = array() ) {
		// Per-metric latest & first values across the WHOLE history. Different snapshots capture
		// different metric subsets (a DB-only scan has no media/cache/TTFB figures, and vice
		// versa), so keying the cards off just the single latest snapshot hid a card — e.g.
		// "Reclaimable (duplicates)" — on sites whose most recent snapshot happened to be a
		// DB-only scan. Using each metric's latest recorded value keeps the card set consistent.
		$now_map   = array();
		$first_map = array();
		foreach ( $history as $snap ) {
			$m = ( isset( $snap['metrics'] ) && is_array( $snap['metrics'] ) ) ? $snap['metrics'] : array();
			foreach ( $m as $k => $v ) {
				$now_map[ $k ] = $v; // last one wins
				if ( ! array_key_exists( $k, $first_map ) ) {
					$first_map[ $k ] = $v; // first one wins
				}
			}
		}
		// Baseline per metric: the pinned baseline value if present, else the metric's first seen.
		$was_map = array();
		foreach ( $now_map as $k => $v ) {
			if ( isset( $baseline['metrics'][ $k ] ) ) {
				$was_map[ $k ] = $baseline['metrics'][ $k ];
			} elseif ( array_key_exists( $k, $first_map ) ) {
				$was_map[ $k ] = $first_map[ $k ];
			}
		}
		$delta = wpoptimizing_analytics_delta( $was_map, $now_map );

		echo '<div class="wpopt-cards-grid">';
		foreach ( $schema as $key => $meta ) {
			if ( empty( $meta['headline'] ) || ! array_key_exists( $key, $now_map ) ) {
				continue;
			}
			$now  = $now_map[ $key ];
			$was  = array_key_exists( $key, $was_map ) ? $was_map[ $key ] : null;
			$dent = isset( $delta[ $key ] ) ? $delta[ $key ] : null;

			echo '<div class="wpopt-card">';
			echo '<div class="wpopt-card-label">' . esc_html( $this->history_metric_label( $key ) ) . '</div>';
			echo '<div class="wpopt-card-num">' . esc_html( $this->history_format_metric( $key, $now, $schema ) ) . '</div>';
			if ( null !== $was ) {
				echo '<div class="wpopt-card-sub">' . sprintf(
					/* translators: %s: baseline (starting) value of the metric. */
					esc_html__( 'from %s', 'wpoptimizing' ),
					esc_html( $this->history_format_metric( $key, $was, $schema ) )
				) . ' ' . $this->history_delta_badge( $dent ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge is pre-escaped markup.
			}
			echo $this->history_sparkline( $history, $key, $dent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped inline SVG.
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Build a tiny inline SVG sparkline for a metric's series over time.
	 *
	 * @param array      $history Parsed history (chronological).
	 * @param string     $key     Metric key.
	 * @param array|null $delta   Baseline→now delta (for line colour).
	 * @return string Escaped SVG markup, or '' if too few points.
	 */
	protected function history_sparkline( array $history, $key, $delta ) {
		$series = array();
		foreach ( $history as $snap ) {
			if ( isset( $snap['metrics'][ $key ] ) ) {
				$series[] = (float) $snap['metrics'][ $key ];
			}
		}
		if ( count( $series ) < 2 ) {
			return '';
		}
		$improved = is_array( $delta ) && isset( $delta['improved'] ) ? $delta['improved'] : null;
		$color    = ( true === $improved ) ? '#008a20' : ( ( false === $improved ) ? '#d63638' : '#646970' );

		return $this->build_sparkline_svg( $series, $color );
	}

	/**
	 * Build a tiny inline SVG sparkline from a numeric series. Shared by the History cards
	 * and the Cache analytics charts so the look is identical and the markup lives once.
	 *
	 * @param array  $series Numeric values (chronological).
	 * @param string $color  Stroke colour (hex).
	 * @param int    $w      Viewbox width.
	 * @param int    $h      Viewbox height.
	 * @return string Escaped SVG markup, or '' if fewer than two points.
	 */
	protected function build_sparkline_svg( array $series, $color = '#646970', $w = 200, $h = 34 ) {
		if ( count( $series ) < 2 ) {
			return '';
		}
		$points = wpoptimizing_analytics_sparkline_points( $series, (int) $w, (int) $h, 3 );
		if ( '' === $points ) {
			return '';
		}
		return '<svg viewBox="0 0 ' . (int) $w . ' ' . (int) $h . '" width="100%" height="' . (int) $h . '" preserveAspectRatio="none" class="wpopt-sparkline" aria-hidden="true" focusable="false">'
			. '<polyline fill="none" stroke="' . esc_attr( $color ) . '" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="' . esc_attr( $points ) . '" />'
			. '</svg>';
	}

	/**
	 * Timeline table: each snapshot newest-first with the change vs the previous.
	 *
	 * @param array $history Parsed history, oldest-first.
	 * @param array $schema  Metric schema.
	 * @return void
	 */
	protected function render_history_timeline( array $history, array $schema ) {
		// Columns: a compact, fixed set of the most telling metrics.
		$cols = array( 'autoload_bytes', 'db_options', 'db_transients', 'media_dupes_bytes' );

		$this->render_section_header(
			__( 'Timeline', 'wpoptimizing' ),
			array(
				'icon' => 'calendar-alt',
				'doc'  => 'wpopt-doc-history',
			)
		);

		// Column map for the shared list-table framework.
		$columns = array(
			'ts'    => __( 'Date', 'wpoptimizing' ),
			'event' => __( 'Event', 'wpoptimizing' ),
		);
		foreach ( $cols as $key ) {
			$columns[ $key ] = $this->history_metric_label( $key );
		}

		// Build rows newest-first, with each snapshot's delta computed against its
		// chronologically previous snapshot (BEFORE any filter/pagination, so a filtered or
		// paged view still shows the correct change), and the cell HTML precomputed so the
		// list table stays a thin renderer.
		$rows        = array_reverse( $history );
		$n           = count( $rows );
		$phases_seen = array();
		$months_seen = array();
		$built       = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$row  = $rows[ $i ];
			$prev = ( $i + 1 < $n ) ? $rows[ $i + 1 ] : null;
			$d    = $prev ? wpoptimizing_analytics_delta( $prev['metrics'], $row['metrics'] ) : array();
			$ph   = (string) $row['phase'];
			$ts   = (string) $row['ts'];
			// Snapshots are STORED as UTC ('Y-m-d H:i:s'); show, filter and search in the site's
			// configured timezone (more intuitive for the user) — convert only for the view.
			$ts_local = ( '' !== $ts ) ? get_date_from_gmt( $ts, 'Y-m-d H:i:s' ) : $ts;
			$ym       = substr( $ts_local, 0, 7 ); // YYYY-MM (local).

			$phases_seen[ $ph ] = $this->history_phase_label( $ph );
			if ( '' !== $ym && ! isset( $months_seen[ $ym ] ) ) {
				$months_seen[ $ym ] = date_i18n( 'F Y', (int) strtotime( $ym . '-01 12:00:00' ) );
			}

			$cells = array(
				'ts'    => esc_html( $ts_local ),
				'event' => esc_html( $this->history_phase_label( $ph ) ),
			);
			foreach ( $cols as $key ) {
				if ( ! isset( $row['metrics'][ $key ] ) ) {
					$cells[ $key ] = '&mdash;';
					continue;
				}
				$val           = $this->history_format_metric( $key, $row['metrics'][ $key ], $schema );
				$badge         = isset( $d[ $key ] ) ? $this->history_delta_badge( $d[ $key ], true ) : '';
				$cells[ $key ] = esc_html( $val ) . ( '' !== $badge ? ' ' . $badge : '' );
			}
			$built[] = array(
				'phase' => $ph,
				'ym'    => $ym,
				'ts'    => $ts_local,
				'cells' => $cells,
			);
		}
		krsort( $months_seen ); // Newest month first.

		// Read the shared filter params (scope 'hist'): event type, month, search, per-page.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list view state.
		$event = isset( $_REQUEST['hist_tipo'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hist_tipo'] ) ) : '';
		if ( 'all' === $event || ! isset( $phases_seen[ $event ] ) ) {
			$event = '';
		}
		$month = isset( $_REQUEST['hist_ext'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['hist_ext'] ) ) : '';
		if ( 'all' === $month || ! isset( $months_seen[ $month ] ) ) {
			$month = '';
		}
		$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$pp = isset( $_REQUEST['hist_pp'] ) ? (int) $_REQUEST['hist_pp'] : 25;
		if ( ! in_array( $pp, array( 10, 25, 50, 100, 200 ), true ) ) {
			$pp = 25;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$filtered = array();
		foreach ( $built as $it ) {
			if ( '' !== $event && $it['phase'] !== $event ) {
				continue;
			}
			if ( '' !== $month && $it['ym'] !== $month ) {
				continue;
			}
			if ( '' !== $q && false === stripos( $it['ts'] . ' ' . $this->history_phase_label( $it['phase'] ), $q ) ) {
				continue;
			}
			$filtered[] = $it;
		}

		// Reuse the shared list-table framework: unified Select2 filters (event + date),
		// per-page selector, pagination and search — no bespoke controls.
		$table = new WPOptimizing_History_List_Table(
			$this,
			$filtered,
			array(
				'scope'             => 'hist',
				'per_page'          => $pp,
				'columns'           => $columns,
				'type_filter'       => $event,
				'type_choices'      => $phases_seen,
				'type_all_label'    => __( 'All events', 'wpoptimizing' ),
				'project_filter'    => $month,
				'project_choices'   => $months_seen,
				'project_all_label' => __( 'All dates', 'wpoptimizing' ),
				'hidden_fields'     => array( 'tab' => 'history' ),
			)
		);
		$table->prepare_items();
		// Read-only timeline: no bulk/submit ("Delete") button.
		$this->render_table_form( $table, 'history', null, null, false, false );

		// Retention control below the timeline (auto-pruned daily; baseline + origin kept).
		$this->render_history_retention_control();
	}

	/**
	 * Localised label for a metric key.
	 *
	 * @param string $key Metric key.
	 * @return string
	 */
	protected function history_metric_label( $key ) {
		$labels = array(
			'server_ttfb_ms'        => __( 'Server TTFB', 'wpoptimizing' ),
			'autoload_bytes'        => __( 'Autoload size', 'wpoptimizing' ),
			'db_options'            => __( 'Options', 'wpoptimizing' ),
			'db_transients'         => __( 'Transients', 'wpoptimizing' ),
			'db_transients_expired' => __( 'Expired transients', 'wpoptimizing' ),
			'db_tables'             => __( 'Tables', 'wpoptimizing' ),
			'attachments'           => __( 'Attachments', 'wpoptimizing' ),
			'orphan_db'             => __( 'DB orphans', 'wpoptimizing' ),
			'orphan_fs'             => __( 'FS orphans', 'wpoptimizing' ),
			'media_dupes_bytes'     => __( 'Reclaimable (duplicates)', 'wpoptimizing' ),
			'media_orphan_bytes'    => __( 'Orphan media', 'wpoptimizing' ),
			'backups_bytes'         => __( 'Backups on disk', 'wpoptimizing' ),
			'cache_hit_rate'        => __( 'Cache hit rate', 'wpoptimizing' ),
			'cache_queries_saved'   => __( 'Queries saved', 'wpoptimizing' ),
		);
		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Localised label for a phase/event.
	 *
	 * @param string $phase Phase key.
	 * @return string
	 */
	protected function history_phase_label( $phase ) {
		$labels = array(
			'baseline'    => __( 'Baseline', 'wpoptimizing' ),
			'before_scan' => __( 'Before scan', 'wpoptimizing' ),
			'after_scan'  => __( 'After scan', 'wpoptimizing' ),
			'after_clean' => __( 'After cleanup', 'wpoptimizing' ),
		);
		return isset( $labels[ $phase ] ) ? $labels[ $phase ] : (string) $phase;
	}

	/**
	 * Format a metric value for display (bytes vs counts).
	 *
	 * @param string $key    Metric key.
	 * @param mixed  $value  Numeric value.
	 * @param array  $schema Metric schema.
	 * @return string
	 */
	protected function history_format_metric( $key, $value, array $schema ) {
		$unit = isset( $schema[ $key ]['unit'] ) ? $schema[ $key ]['unit'] : 'count';
		if ( 'bytes' === $unit ) {
			return size_format( (int) $value, 1 );
		}
		if ( 'percent' === $unit ) {
			return number_format_i18n( (int) $value ) . '%';
		}
		if ( 'ms' === $unit ) {
			/* translators: %s: milliseconds. */
			return sprintf( __( '%s ms', 'wpoptimizing' ), number_format_i18n( (int) $value ) );
		}
		return number_format_i18n( (int) $value );
	}

	/**
	 * Render a coloured change badge for a delta entry.
	 *
	 * @param array|null $delta   Delta entry {abs,pct,improved} or null.
	 * @param bool       $compact When true, omit the percentage (table cells).
	 * @return string Escaped HTML.
	 */
	protected function history_delta_badge( $delta, $compact = false ) {
		if ( ! is_array( $delta ) || ! isset( $delta['abs'] ) || 0 === $delta['abs'] ) {
			return '';
		}
		$improved = isset( $delta['improved'] ) ? $delta['improved'] : null;
		$cls      = ( true === $improved ) ? 'wpopt-trend-up' : ( ( false === $improved ) ? 'wpopt-trend-down' : 'wpopt-trend-flat' );
		$arrow    = ( $delta['abs'] < 0 ) ? '▼' : '▲';

		$text = $arrow;
		if ( ! $compact && null !== $delta['pct'] ) {
			$text .= ' ' . number_format_i18n( abs( (float) $delta['pct'] ), 1 ) . '%';
		}
		return '<span class="wpopt-trend ' . esc_attr( $cls ) . '">' . esc_html( $text ) . '</span>';
	}
}
