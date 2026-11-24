<?php
/**
 * Performance analytics: pure data helpers (no WordPress, no I/O).
 *
 * These power the "History" tab and WPOPT_Performance_Logger. Everything here is
 * side-effect free so it can be unit-tested offline: snapshot encode/decode to
 * JSONL, the metric schema, and baseline/latest/delta computations.
 *
 * A snapshot is an associative array:
 *   array(
 *     'ts'      => 'Y-m-d H:i:s' (UTC string),
 *     'phase'   => 'baseline'|'after_scan'|'after_clean'|... ,
 *     'op_key'  => '' or the backup operation key correlating it to a cleanup,
 *     'metrics' => array<string,int|float>  // keys from the schema below
 *     'context' => array<string,string>     // wp_version, theme, active_plugins
 *   )
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical metric schema: key => meta.
 *
 * - unit: 'count', 'bytes', or 'percent' (drives formatting in the UI).
 * - lower_is_better: a decrease is an improvement (drives delta colouring).
 * - headline: shown in the top baseline-vs-now cards.
 *
 * @return array<string,array{label:string,unit:string,lower_is_better:bool,headline:bool}>
 */
function wpoptimizing_analytics_schema() {
	return array(
		'server_ttfb_ms'        => array(
			'label'           => 'Server TTFB',
			'unit'            => 'ms',
			'lower_is_better' => true,
			'headline'        => true,
		),
		'autoload_bytes'        => array(
			'label'           => 'Autoload size',
			'unit'            => 'bytes',
			'lower_is_better' => true,
			'headline'        => true,
		),
		'db_options'            => array(
			'label'           => 'Options',
			'unit'            => 'count',
			'lower_is_better' => true,
			'headline'        => true,
		),
		'db_transients'         => array(
			'label'           => 'Transients',
			'unit'            => 'count',
			'lower_is_better' => true,
			'headline'        => true,
		),
		'db_transients_expired' => array(
			'label'           => 'Expired transients',
			'unit'            => 'count',
			'lower_is_better' => true,
			'headline'        => false,
		),
		'db_tables'             => array(
			'label'           => 'Tables',
			'unit'            => 'count',
			'lower_is_better' => true,
			'headline'        => false,
		),
		'attachments'           => array(
			'label'           => 'Attachments',
			'unit'            => 'count',
			'lower_is_better' => false,
			'headline'        => false,
		),
		'orphan_db'             => array(
			'label'           => 'DB orphans',
			'unit'            => 'count',
			'lower_is_better' => true,
			'headline'        => false,
		),
		'orphan_fs'             => array(
			'label'           => 'FS orphans',
			'unit'            => 'count',
			'lower_is_better' => true,
			'headline'        => false,
		),
		'media_dupes_bytes'     => array(
			'label'           => 'Reclaimable (duplicates)',
			'unit'            => 'bytes',
			'lower_is_better' => true,
			'headline'        => true,
		),
		'media_orphan_bytes'    => array(
			'label'           => 'Orphan media',
			'unit'            => 'bytes',
			'lower_is_better' => true,
			'headline'        => false,
		),
		'backups_bytes'         => array(
			'label'           => 'Backups on disk',
			'unit'            => 'bytes',
			'lower_is_better' => false,
			'headline'        => false,
		),
		'cache_hit_rate'        => array(
			'label'           => 'Cache hit rate',
			'unit'            => 'percent',
			'lower_is_better' => false,
			'headline'        => true,
		),
		'cache_queries_saved'   => array(
			'label'           => 'Queries saved',
			'unit'            => 'count',
			'lower_is_better' => false,
			'headline'        => true,
		),
	);
}

/**
 * Read the durable cache-analytics JSON file and return a normalised summary.
 *
 * This is a pure file-read helper shared by WPOPT_Performance_Logger (snapshots)
 * and WPOptimizing_Cache_Trait (live UI). It never uses WordPress cache APIs so
 * it is safe to call from outside the request cycle.
 *
 * Returns an array with at minimum:
 *   has           bool   true when the file has usable data.
 *   ratio         int    Hit rate 0-100.
 *   dups          int    Duplicate queries avoided (total over retention window).
 *
 * @param string $stats_file Absolute path to wpopt-cache-stats.json (optional;
 *                           defaults to WPOPT_CACHE_DIR or WP_CONTENT_DIR/cache).
 * @return array{has:bool,ratio:int,dups:int}
 */
function wpoptimizing_cache_stats_read( $stats_file = '' ) {
	if ( '' === $stats_file ) {
		$dir        = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR : ( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/cache' : '' );
		$stats_file = '' !== $dir ? $dir . '/wpopt-cache-stats.json' : '';
	}
	$out = array(
		'has'   => false,
		'ratio' => 0,
		'dups'  => 0,
	);
	if ( '' === $stats_file || ! is_readable( $stats_file ) ) {
		return $out;
	}
	$data = json_decode( (string) file_get_contents( $stats_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local analytics file.
	if ( ! is_array( $data ) ) {
		return $out;
	}
	$hits   = 0;
	$misses = 0;
	$dups   = 0;
	$daily  = ( isset( $data['daily'] ) && is_array( $data['daily'] ) ) ? $data['daily'] : array();
	foreach ( $daily as $b ) {
		if ( ! is_array( $b ) ) {
			continue;
		}
		$hits   += (int) ( $b['h'] ?? 0 );
		$misses += (int) ( $b['m'] ?? 0 );
		$dups   += (int) ( $b['dup'] ?? 0 );
	}
	$total        = $hits + $misses;
	$out['ratio'] = $total > 0 ? (int) round( $hits / $total * 100 ) : 0;
	$out['dups']  = $dups;
	$out['has']   = ( $total > 0 || $dups > 0 );
	return $out;
}

/**
 * Encode one snapshot as a single JSONL line (no trailing newline).
 *
 * Unknown metric keys are dropped and values coerced to numbers so the store
 * stays clean regardless of caller hygiene.
 *
 * @param array $snapshot Raw snapshot (see file header).
 * @return string One-line JSON, or '' if it cannot be encoded.
 */
function wpoptimizing_analytics_encode_snapshot( array $snapshot ) {
	$schema  = wpoptimizing_analytics_schema();
	$metrics = array();
	$raw     = isset( $snapshot['metrics'] ) && is_array( $snapshot['metrics'] ) ? $snapshot['metrics'] : array();
	foreach ( $raw as $key => $value ) {
		if ( ! isset( $schema[ $key ] ) ) {
			continue;
		}
		if ( null === $value || '' === $value ) {
			continue;
		}
		$metrics[ $key ] = 0 + $value; // int|float.
	}

	$context = array();
	$rawctx  = isset( $snapshot['context'] ) && is_array( $snapshot['context'] ) ? $snapshot['context'] : array();
	foreach ( $rawctx as $key => $value ) {
		$context[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
	}

	$record = array(
		'ts'      => isset( $snapshot['ts'] ) ? (string) $snapshot['ts'] : '',
		'phase'   => isset( $snapshot['phase'] ) ? (string) $snapshot['phase'] : '',
		'op_key'  => isset( $snapshot['op_key'] ) ? (string) $snapshot['op_key'] : '',
		'metrics' => $metrics,
		'context' => $context,
	);

	$json = wp_json_encode( $record );
	if ( false === $json ) {
		return '';
	}
	// Guard against embedded newlines so one record always stays one line.
	return str_replace( array( "\r", "\n" ), '', $json );
}

/**
 * Parse a JSONL history blob into an array of snapshots, sorted oldest-first.
 *
 * Malformed lines are skipped. Metrics are filtered to the known schema.
 *
 * @param string $jsonl Raw file contents.
 * @return array<int,array> Decoded snapshots.
 */
function wpoptimizing_analytics_parse_history( $jsonl ) {
	$out    = array();
	$schema = wpoptimizing_analytics_schema();
	$lines  = preg_split( '/\r\n|\r|\n/', (string) $jsonl );
	foreach ( (array) $lines as $line ) {
		$line = trim( (string) $line );
		if ( '' === $line ) {
			continue;
		}
		$row = json_decode( $line, true );
		if ( ! is_array( $row ) || ! isset( $row['ts'] ) ) {
			continue;
		}
		$metrics = array();
		if ( isset( $row['metrics'] ) && is_array( $row['metrics'] ) ) {
			foreach ( $row['metrics'] as $key => $value ) {
				if ( isset( $schema[ $key ] ) && is_numeric( $value ) ) {
					$metrics[ $key ] = 0 + $value;
				}
			}
		}
		$out[] = array(
			'ts'      => (string) $row['ts'],
			'phase'   => isset( $row['phase'] ) ? (string) $row['phase'] : '',
			'op_key'  => isset( $row['op_key'] ) ? (string) $row['op_key'] : '',
			'metrics' => $metrics,
			'context' => ( isset( $row['context'] ) && is_array( $row['context'] ) ) ? $row['context'] : array(),
		);
	}

	usort(
		$out,
		static function ( $a, $b ) {
			return strcmp( (string) $a['ts'], (string) $b['ts'] );
		}
	);
	return $out;
}

/**
 * The baseline snapshot: by default the earliest one, or — when a baseline
 * marker timestamp is given (the user pinned a new starting point) — the
 * earliest snapshot at or after that marker. Falls back to the earliest if the
 * marker is empty or matches nothing.
 *
 * @param array  $history      Parsed history (any order).
 * @param string $baseline_ts  Optional pinned baseline timestamp ('Y-m-d H:i:s').
 * @return array|null
 */
function wpoptimizing_analytics_baseline( array $history, $baseline_ts = '' ) {
	if ( empty( $history ) ) {
		return null;
	}
	$sorted = $history;
	usort(
		$sorted,
		static function ( $a, $b ) {
			return strcmp( (string) ( $a['ts'] ?? '' ), (string) ( $b['ts'] ?? '' ) );
		}
	);

	$baseline_ts = (string) $baseline_ts;
	if ( '' !== $baseline_ts ) {
		foreach ( $sorted as $snap ) {
			if ( strcmp( (string) ( $snap['ts'] ?? '' ), $baseline_ts ) >= 0 ) {
				return $snap;
			}
		}
	}
	return $sorted[0];
}

/**
 * Compute polyline points for a tiny inline sparkline.
 *
 * Maps the series onto a width×height box (max value at the top). Pure: returns
 * an SVG `points` string like "0,10 12,4 24,8". Empty series → ''.
 *
 * @param array $values Numeric series in chronological order.
 * @param int   $width  Box width in px.
 * @param int   $height Box height in px.
 * @param int   $pad    Inner padding in px.
 * @return string
 */
function wpoptimizing_analytics_sparkline_points( array $values, $width = 120, $height = 28, $pad = 2 ) {
	$values = array_values( array_map( 'floatval', $values ) );
	$n      = count( $values );
	if ( 0 === $n ) {
		return '';
	}
	$iw = max( 1, $width - 2 * $pad );
	$ih = max( 1, $height - 2 * $pad );
	if ( 1 === $n ) {
		return $pad . ',' . round( $pad + $ih / 2, 2 );
	}
	$min  = min( $values );
	$max  = max( $values );
	$span = $max - $min;
	$pts  = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$x     = $pad + $iw * ( $i / ( $n - 1 ) );
		$y     = ( $span > 0 ) ? ( $pad + $ih * ( 1 - ( $values[ $i ] - $min ) / $span ) ) : ( $pad + $ih / 2 );
		$pts[] = round( $x, 2 ) . ',' . round( $y, 2 );
	}
	return implode( ' ', $pts );
}

/**
 * Flatten parsed history into CSV rows (first row is the header).
 *
 * One column per schema metric so the export is stable and self-describing.
 *
 * @param array $history Parsed history (chronological or not).
 * @return array<int,array<int,string|int|float>>
 */
function wpoptimizing_analytics_history_to_csv( array $history ) {
	$keys   = array_keys( wpoptimizing_analytics_schema() );
	$header = array_merge( array( 'ts', 'phase', 'op_key' ), $keys );
	$rows   = array( $header );
	foreach ( $history as $snap ) {
		$row = array(
			(string) ( $snap['ts'] ?? '' ),
			(string) ( $snap['phase'] ?? '' ),
			(string) ( $snap['op_key'] ?? '' ),
		);
		foreach ( $keys as $k ) {
			$row[] = isset( $snap['metrics'][ $k ] ) ? $snap['metrics'][ $k ] : '';
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * The most recent snapshot, or null if history is empty.
 *
 * @param array $history Parsed history (any order).
 * @return array|null
 */
function wpoptimizing_analytics_latest( array $history ) {
	if ( empty( $history ) ) {
		return null;
	}
	$sorted = $history;
	usort(
		$sorted,
		static function ( $a, $b ) {
			return strcmp( (string) ( $a['ts'] ?? '' ), (string) ( $b['ts'] ?? '' ) );
		}
	);
	return $sorted[ count( $sorted ) - 1 ];
}

/**
 * Per-metric change between two metric sets (only keys present in both).
 *
 * @param array $from Baseline metrics.
 * @param array $to   Current metrics.
 * @return array<string,array{abs:float,pct:?float,improved:?bool}>
 *   abs = to - from; pct = relative change vs from (null if from is 0);
 *   improved = whether the change is an improvement per the schema direction.
 */
function wpoptimizing_analytics_delta( array $from, array $to ) {
	$schema = wpoptimizing_analytics_schema();
	$out    = array();
	foreach ( $to as $key => $tval ) {
		if ( ! array_key_exists( $key, $from ) || ! is_numeric( $tval ) || ! is_numeric( $from[ $key ] ) ) {
			continue;
		}
		$fval = 0 + $from[ $key ];
		$tval = 0 + $tval;
		$abs  = $tval - $fval;
		$pct  = ( 0.0 !== (float) $fval ) ? ( $abs / $fval * 100 ) : null;

		$improved = null;
		if ( isset( $schema[ $key ] ) && 0 !== $abs ) {
			$lower_better = ! empty( $schema[ $key ]['lower_is_better'] );
			$improved     = $lower_better ? ( $abs < 0 ) : ( $abs > 0 );
		}

		$out[ $key ] = array(
			'abs'      => $abs,
			'pct'      => $pct,
			'improved' => $improved,
		);
	}
	return $out;
}

/**
 * Compute a single 0-100 optimization "health score" from a metrics snapshot.
 *
 * Pure and UI-agnostic (no i18n, no URLs) so it is fully unit-testable and reusable
 * (panel card, network dashboard, REST). The score starts at 100 and subtracts a
 * bounded penalty per problem area, each scaled so a healthy site lands at/near 100
 * and a badly bloated one degrades gracefully (never below 0). Every penalised area
 * is returned as a "factor" with its penalty and current value so the UI can surface
 * the few actions that would raise the score the most.
 *
 * @param array $m Metrics keyed like wpoptimizing_analytics_schema() (missing keys = 0).
 * @return array{score:int,grade:string,factors:array<string,array>,improve:array<int,array>}
 */
function wpoptimizing_health_score( array $m ) {
	$val = static function ( $k ) use ( $m ) {
		return ( isset( $m[ $k ] ) && is_numeric( $m[ $k ] ) ) ? (float) $m[ $k ] : 0.0;
	};

	$factors = array();

	// Autoload weight: ideal <= 800 KB; reaches the full -25 around ~2 MB.
	$aload               = $val( 'autoload_bytes' );
	$factors['autoload'] = array(
		'key'     => 'autoload',
		'penalty' => $aload > 819200 ? min( 25.0, ( $aload - 819200 ) / 1258291.0 * 25.0 ) : 0.0,
		'value'   => $aload,
	);

	// Orphaned records + files left by uninstalled extensions: -1 each, capped at -20.
	$orph               = $val( 'orphan_db' ) + $val( 'orphan_fs' );
	$factors['orphans'] = array(
		'key'     => 'orphans',
		'penalty' => min( 20.0, $orph ),
		'value'   => $orph,
	);

	// Reclaimable media (duplicates + orphaned uploads): reaches -20 at ~50 MB.
	$media            = $val( 'media_dupes_bytes' ) + $val( 'media_orphan_bytes' );
	$factors['media'] = array(
		'key'     => 'media',
		'penalty' => min( 20.0, $media / 52428800.0 * 20.0 ),
		'value'   => $media,
	);

	// Expired transients: harmless in small numbers; -10 around 500. Only the expired ones: a
	// live transient is doing its job, and the action this factor suggests removes expired ones.
	$tr                    = $val( 'db_transients_expired' );
	$factors['transients'] = array(
		'key'     => 'transients',
		'penalty' => $tr > 200 ? min( 10.0, ( $tr - 200 ) / 300.0 * 10.0 ) : 0.0,
		'value'   => $tr,
	);

	// Option-table bloat: -10 around 3,000 options.
	$op                 = $val( 'db_options' );
	$factors['options'] = array(
		'key'     => 'options',
		'penalty' => $op > 1500 ? min( 10.0, ( $op - 1500 ) / 1500.0 * 10.0 ) : 0.0,
		'value'   => $op,
	);

	// Server-side TTFB (WP boot -> first byte): the single most user-visible perf
	// metric, so it must move the score. Only penalised when actually measured
	// (server_ttfb_ms > 0). Ideal <= 200 ms; reaches the -25 cap around ~1,000 ms.
	// Thresholds are filterable so hosts/agencies can recalibrate.
	$ttfb     = $val( 'server_ttfb_ms' );
	$ttfb_pen = 0.0;
	if ( $ttfb > 0 ) {
		$good     = (float) apply_filters( 'wpoptimizing_ttfb_good_ms', 200 );
		$reach    = (float) apply_filters( 'wpoptimizing_ttfb_reach_ms', 1000 );
		$span     = max( 1.0, $reach - $good );
		$ttfb_pen = $ttfb > $good ? min( 25.0, ( $ttfb - $good ) / $span * 25.0 ) : 0.0;
	}
	$factors['ttfb'] = array(
		'key'     => 'ttfb',
		'penalty' => $ttfb_pen,
		'value'   => $ttfb,
	);

	$penalty = 0.0;
	foreach ( $factors as $f ) {
		$penalty += $f['penalty'];
	}
	$score = (int) round( max( 0.0, 100.0 - $penalty ) );

	if ( $score >= 90 ) {
		$grade = 'excellent';
	} elseif ( $score >= 75 ) {
		$grade = 'good';
	} elseif ( $score >= 50 ) {
		$grade = 'fair';
	} else {
		$grade = 'poor';
	}

	// The actionable few: penalised areas, biggest first.
	$improve = array_values(
		array_filter(
			$factors,
			static function ( $f ) {
				return $f['penalty'] >= 0.5;
			}
		)
	);
	usort(
		$improve,
		static function ( $a, $b ) {
			return $b['penalty'] <=> $a['penalty'];
		}
	);

	return array(
		'score'   => $score,
		'grade'   => $grade,
		'factors' => $factors,
		'improve' => $improve,
	);
}
