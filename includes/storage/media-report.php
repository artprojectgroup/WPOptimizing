<?php
/**
 * The media report, persisted.
 *
 * It used to live only in a transient. With a persistent object cache a transient is one
 * wp_cache_flush() away from gone — and a flush is what applying an autoload change, the
 * "clear cache" button and any host purge all do. So an analysis that takes minutes over a
 * large library was being thrown away routinely, and the Media section went blank for a
 * reason the user could not connect to anything they had done.
 *
 * Every other section keeps its last good result in a file. Media is now no different: the
 * file is the truth, the transient is only a fast path in front of it.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the media report is kept between analyses.
 *
 * @return string
 */
function wpoptimizing_media_report_path() {
	return trailingslashit( WPOPT_DIR ) . 'wp-media-report.json';
}

/**
 * Save the media report so it SURVIVES.
 *
 * It used to live only in a transient, and with a persistent object cache a transient is one
 * wp_cache_flush() away from gone — a flush any autoload change, any "clear cache" button and
 * any host purge performs. So an analysis that takes minutes over a big library was being
 * thrown away routinely, and the user saw the Media section go blank for no reason they could
 * connect to anything they had done.
 *
 * Every other section keeps its last good result in a file on disk. Media is now no different:
 * the file is the truth, the transient is only a fast path in front of it.
 *
 * @param array $report Report.
 * @return void
 */
function wpoptimizing_media_report_save( $report ) {
	if ( ! is_array( $report ) ) {
		return;
	}
	set_transient( 'wpoptimizing_media_report', $report, DAY_IN_SECONDS );

	$path = wpoptimizing_media_report_path();
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	// Atomic swap, and rename() is the point: it is atomic within a directory, so a concurrent
	// reader always sees either the old complete report or the new one — never the half-written
	// window. WP_Filesystem::move() is NOT atomic (several transports copy then delete), which
	// would reintroduce exactly the blank-section flicker this file exists to prevent. Same
	// rationale, and same annotation, as the other atomic writers (class-runtime-resolver,
	// trait-data-ops). On failure the previous report stays intact.
	$tmp = wpoptimizing_atomic_tmp_path( $path );
	if ( false !== file_put_contents( $tmp, wp_json_encode( $report ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
		}
	}
}

/**
 * The last good media report: the transient if it is there, the file otherwise.
 *
 * @return array|false
 */
function wpoptimizing_media_report_get() {
	$report = get_transient( 'wpoptimizing_media_report' );
	if ( is_array( $report ) ) {
		return $report;
	}

	$path = wpoptimizing_media_report_path();
	if ( ! file_exists( $path ) ) {
		return false;
	}
	$report = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! is_array( $report ) ) {
		return false;
	}

	// Re-prime the cache so the next render does not touch the disk again.
	set_transient( 'wpoptimizing_media_report', $report, DAY_IN_SECONDS );
	return $report;
}

/**
 * Whether the unused-media detection ran into its bounds.
 *
 * ONE ANSWER, TWO CALLERS: the Media tab, which withdraws "delete all" when this is true, and
 * the bulk endpoint, which refuses a wholesale run even if the request arrives anyway. A
 * hidden button is not a guard, and two copies of this question would be one copy too many —
 * which is why the tab calls this and no longer reads report['unused']['partial'] itself.
 *
 * `partial` means the reference sweep did not read everything: a pass that kept failing was
 * skipped (the media job), or the time budget ran out (the one-shot sweep). It did not weigh
 * a reference and discard it — it never saw it, so the conservative bias that normally makes
 * a false "unused" impossible does not hold.
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_media_unused_scan_is_partial() {
	$report = wpoptimizing_media_report_get();
	return is_array( $report ) && ! empty( $report['unused']['partial'] );
}

/**
 * Whether the media report is too old to act on wholesale.
 *
 * The report is kept on disk with no expiry, which is right for showing it and wrong for
 * "delete all": a list from last month names images that pages have used since. The bulk
 * endpoint refuses a whole-list run on a report older than this and the user re-analyses;
 * deleting rows somebody ticked stays allowed, and every batch re-checks its own IDs anyway.
 * A report with no date counts as old.
 *
 * @since 0.1.0
 * @param int|null $now Current timestamp (for tests).
 * @return bool
 */
function wpoptimizing_media_report_is_stale( $now = null ) {
	$report = wpoptimizing_media_report_get();
	if ( ! is_array( $report ) || empty( $report['generated_at'] ) ) {
		return true;
	}
	/**
	 * Filters how old (in seconds) the media report may be for a "delete all" to run.
	 *
	 * @since 0.1.0
	 * @param int $max_age Seconds. Default one day.
	 */
	$max_age = (int) apply_filters( 'wpoptimizing_media_report_max_age', DAY_IN_SECONDS );
	$now     = null === $now ? time() : (int) $now;

	return ( $now - (int) $report['generated_at'] ) > $max_age;
}
