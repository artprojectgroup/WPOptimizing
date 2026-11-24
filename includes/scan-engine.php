<?php
/**
 * The staged scan: the worker that actually runs it.
 *
 * The scan is split into stages so no single request has to do everything at once, which is
 * what used to time out large installs. Each stage runs in its own request — a cron event, an
 * admin-ajax pump or WP-CLI — and this file holds the machinery that drives them: the stage
 * dispatcher, the step worker, the watchdog that notices a dead worker, and the off-peak gate.
 *
 * It lives here rather than in the main plugin file because NONE of it can run on a visitor
 * request. Every entry point — wpoptimizing_cron_scan, wpoptimizing_cron_scan_step,
 * wpoptimizing_scan_cron and the admin-ajax pump — is behind wpoptimizing_request_needs_engine(),
 * so a public page load was compiling ~25 KB it could never call. The hook registrations stay
 * in the main file: a string callback does not need its function to exist when it is registered,
 * only when it fires, and by then the engine is loaded.
 *
 * The pure, side-effect-free helpers (stage list, progress percentages, the stall test) live in
 * scanners/scan.php and are unit-tested offline.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Human, translatable label for a scan trigger reason slug (shown as
 * "Last analysis: … ago (REASON)"). Falls back to the slug if unknown.
 *
 * @param string $reason Reason slug stored in wpoptimizing_last_scan_reason.
 * @return string
 */
function wpoptimizing_scan_reason_label( $reason ) {
	$reason = sanitize_key( (string) $reason );
	$labels = array(
		'manual'        => __( 'manual', 'wpoptimizing' ),
		'activation'    => __( 'activation', 'wpoptimizing' ),
		'plugin-change' => __( 'plugin change', 'wpoptimizing' ),
		'theme-change'  => __( 'theme change', 'wpoptimizing' ),
		'upgrade'       => __( 'upgrade', 'wpoptimizing' ),
		'post-clean'    => __( 'after cleanup', 'wpoptimizing' ),
		'api'           => __( 'API', 'wpoptimizing' ),
		'network-bulk'  => __( 'network', 'wpoptimizing' ),
	);
	return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
}

/**
 * Perform the full analysis end-to-end.
 *
 * Steps:
 *  1) Remove previous CSV artifacts (fresh recomputation).
 *  2) Snapshot performance timings.
 *  3) Scan code (plugins + themes).
 *  4) Scan real filesystem (wp-content).
 *  5) Generate orphan candidates (DB + FS).
 *  6) Persist the last-run metadata.
 *
 * Concurrency: governed by a short-lived transient lock.
 *
 * @param string $reason Reason propagated from the scheduler.
 * @return void
 */
/**
 * Store current scan phase and percentage so the UI can show a progress bar.
 *
 * @param int    $pct   Percentage complete (0-100).
 * @param string $label Human-readable phase label (already translated).
 * @param int    $eta   Seconds left, when the stage can tell (0 = unknown).
 * @return void
 */
function wpoptimizing_scan_progress_update( $pct, $label, $eta = 0 ) {
	set_transient(
		'wpoptimizing_scan_progress',
		array(
			'pct'   => (int) $pct,
			'label' => (string) $label,
			// Seconds left as the running stage measures them; 0 = let the poll estimate.
			'eta'   => max( 0, (int) $eta ),
		),
		15 * MINUTE_IN_SECONDS
	);

	// Keep the async watchdog heartbeat (and the step lock) fresh while a long
	// stage is actively emitting progress, so it is never mistaken for stalled and
	// restarted in parallel. Throttled to avoid an option write on every tick.
	$state = get_option( 'wpoptimizing_scan_state', null );
	if ( is_array( $state ) && isset( $state['heartbeat'] ) && ( time() - (int) $state['heartbeat'] ) >= 15 ) {
		$state['heartbeat'] = time();
		update_option( 'wpoptimizing_scan_state', $state, false );
		if ( get_transient( 'wpoptimizing_scan_step_lock' ) ) {
			$timeout = (int) apply_filters( 'wpoptimizing_scan_step_timeout', 180 );
			// Refresh with the CURRENT time (not a bare 1) so an actively-ticking stage keeps a
			// "fresh" lock and is never mistaken for a dead worker by the staleness check.
			set_transient( 'wpoptimizing_scan_step_lock', time(), max( 300, $timeout + 60 ) );
		}
	}
}

/**
 * Keep the running stage's step lock and heartbeat fresh from inside a long scanner.
 *
 * Only wpoptimizing_scan_progress_update() refreshed the step lock, and most stages report
 * progress once, when they start. The code scan of a big plugin tree, or the database pass on
 * a large site, runs for minutes without another word: after 150 s its lock looked stale, the
 * watchdog or the pump reclaimed it, and a second worker ran the same stage in parallel over
 * the same CSVs. The long loops call this; it costs a time() comparison except once every
 * 20 s, and writes nothing when no scan holds the lock.
 *
 * @param bool $force Skip the 20-second throttle.
 * @return void
 */
function wpoptimizing_scan_heartbeat( $force = false ) {
	static $last = 0;
	$now         = time();
	if ( ! $force && $now - $last < 20 ) {
		return;
	}
	$last = $now;
	if ( ! get_transient( 'wpoptimizing_scan_step_lock' ) ) {
		return;
	}
	$timeout = (int) apply_filters( 'wpoptimizing_scan_step_timeout', 180 );
	set_transient( 'wpoptimizing_scan_step_lock', $now, max( 300, $timeout + 60 ) );
	$state = get_option( 'wpoptimizing_scan_state', null );
	if ( is_array( $state ) ) {
		$state['heartbeat'] = $now;
		update_option( 'wpoptimizing_scan_state', $state, false );
	}
}

/**
 * Persist a compact media summary (duplicate + orphan reclaimable bytes) as blog
 * options so the network overview can read it per-blog via get_blog_option,
 * without switching context. Bytes only — never option values or file contents.
 *
 * @param array $report Media report (WPOPT_Media_Analyzer::analyze() output).
 * @return void
 */
function wpoptimizing_store_media_stats( $report ) {
	if ( ! is_array( $report ) ) {
		return;
	}
	update_option( 'wpoptimizing_media_dupes_bytes', isset( $report['duplicates']['reclaimable_bytes'] ) ? (int) $report['duplicates']['reclaimable_bytes'] : 0, false );
	update_option( 'wpoptimizing_media_orphan_bytes', isset( $report['orphan_files']['size_bytes'] ) ? (int) $report['orphan_files']['size_bytes'] : 0, false );
}

/**
 * Perform the media analysis in one go and cache the report + per-blog stats. Used by the
 * standalone cron callback; the unified scan runs the same job one step per request instead
 * (wpoptimizing_media_analysis_stage()), so both paths produce the exact same cached report.
 *
 * @param callable|null $progress Optional progress callback ( int $pct, string $label ).
 * @return array|null The report, or null if the analyzer is unavailable.
 */
function wpoptimizing_perform_media_analysis( $progress = null ) {
	if ( function_exists( 'wpoptimizing_load_engine' ) ) {
		wpoptimizing_load_engine();
	}
	if ( ! class_exists( 'WPOPT_Media_Analyzer' ) ) {
		return null;
	}
	$args = array();
	if ( is_callable( $progress ) ) {
		$args['progress'] = $progress;
	}
	return wpoptimizing_media_analysis_finish( ( new WPOPT_Media_Analyzer() )->analyze( $args ) );
}

/**
 * Seconds one step of the media analysis may work inside a request.
 *
 * Kept well under max_execution_time, with the same headroom rule as the file-orphan pass:
 * a step that hits the limit dies without saving, and the work of that request is redone.
 * And short anyway, because in a manual scan the step runs inside the progress poll, behind
 * whatever proxy timeout the host has.
 *
 * @return int
 */
function wpoptimizing_media_step_budget() {
	/**
	 * Seconds one step of the media analysis may work.
	 *
	 * @since 0.1.0
	 * @param int $seconds Seconds.
	 */
	$budget = max( 3, (int) apply_filters( 'wpoptimizing_media_step_budget', 20 ) );
	$limit  = (int) ( function_exists( 'ini_get' ) ? ini_get( 'max_execution_time' ) : 0 );
	if ( $limit > 0 ) {
		$budget = min( $budget, max( 5, min( (int) floor( $limit * 0.5 ), $limit - 12 ) ) );
	}
	return $budget;
}

/**
 * The 'media' stage of the unified scan: one step of the resumable media job.
 *
 * @param int $lo Where the stage starts on the overall bar (80).
 * @return bool True when the analysis is complete; false to be called again.
 */
function wpoptimizing_media_analysis_stage( $lo ) {
	if ( ! class_exists( 'WPOPT_Media_Analysis_Job' ) ) {
		return true;
	}
	$span = 99 - (int) $lo; // Leave 100 for finalize.
	$job  = null;
	$job  = new WPOPT_Media_Analysis_Job(
		array(
			// Map the job's own 0-100 into this stage's band so the bar keeps moving up to
			// finalize, with the time left as the job measures it: the overall rate is
			// meaningless here, the code stages before it took seconds and this takes hours.
			'progress' => static function ( $mp, $label ) use ( $lo, $span, &$job ) {
				$mp      = max( 0, min( 100, (int) $mp ) );
				$started = $job ? $job->started_at() : 0;
				$eta     = ( $started > 0 && $mp >= 2 && $mp < 100 ) ? (int) round( ( time() - $started ) * ( 100 - $mp ) / $mp ) : 0;
				wpoptimizing_scan_progress_update( $lo + (int) round( $mp * $span / 100 ), (string) $label, $eta );
			},
		),
		WPOPT_Media_Analysis_Job::scan_dir()
	);
	// A job left by an earlier scan (cancelled, failed) is not this scan's.
	if ( $job->started_at() > 0 && $job->started_at() < (int) get_option( 'wpoptimizing_scan_started_at', 0 ) ) {
		$job->reset();
	}
	if ( ! $job->step( wpoptimizing_media_step_budget() ) ) {
		return false;
	}
	wpoptimizing_media_analysis_finish( $job->report() );
	$job->reset();
	return true;
}

/**
 * Stamp, store and follow up a finished media report.
 *
 * @param array $report Report.
 * @return array The report as stored.
 */
function wpoptimizing_media_analysis_finish( $report ) {
	$report                 = is_array( $report ) ? $report : array();
	$report['generated_at'] = time();
	wpoptimizing_media_report_save( $report );
	wpoptimizing_store_media_stats( $report );
	// Refresh the image-optimization analysis in the SAME background pass, so its data is
	// always fresh after an analysis and never has to be scanned during a page render.
	if ( function_exists( 'wpoptimizing_image_opt_scan' ) ) {
		wpoptimizing_image_opt_scan( true );
	}
	return $report;
}

/**
 * Execute a full site scan: code, filesystem, DB orphans.
 *
 * @param string $reason Trigger: 'activation'|'plugin-change'|'theme-change'|'upgrade'|'manual'.
 * @return void
 */
function wpoptimizing_run_full_scan( $reason = 'manual' ) {
	// Scans instantiate engine classes directly; ensure they are loaded even if
	// this runs from a context where the engine was not auto-loaded.
	wpoptimizing_load_engine();
	if ( get_transient( 'wpoptimizing_scan_lock' ) ) {
		return;
	}
	// The scan lock lives 10 minutes and an async scan on a large site lasts longer: past it, a
	// second run started over the cursor of the first and both wrote the same CSVs. The staged
	// cursor, while it keeps beating, is the scan in progress.
	if ( wpoptimizing_scan_in_progress( get_option( 'wpoptimizing_scan_state', null ), time(), (int) apply_filters( 'wpoptimizing_scan_step_timeout', 180 ) ) ) {
		return;
	}
	set_transient( 'wpoptimizing_scan_lock', 1, 10 * MINUTE_IN_SECONDS );
	update_option( 'wpoptimizing_scan_started_at', time(), false );
	delete_option( 'wpoptimizing_scan_failed_at' );
	wpoptimizing_scan_progress_update( 2, __( 'Preparing scan…', 'wpoptimizing' ) );

	/**
	 * Fires right before a full scan starts (after the scan lock is acquired).
	 *
	 * @since 0.1.0
	 * @param string $reason Trigger: activation|plugin-change|theme-change|upgrade|manual.
	 */
	do_action( 'wpoptimizing_before_scan', $reason );

	/**
	 * Whether to run the scan asynchronously (one self-chaining cron job per
	 * stage) so no single request times out on large installs. Defaults to true
	 * except under WP-CLI, where a synchronous run is simpler and reliable.
	 *
	 * @since 0.1.0
	 * @param bool   $async  Default decision.
	 * @param string $reason Trigger reason.
	 */
	$async = (bool) apply_filters( 'wpoptimizing_async_scan', ! ( defined( 'WP_CLI' ) && WP_CLI ), $reason );

	if ( ! $async ) {
		// Synchronous: run every stage inline (WP-CLI, tests, opt-out installs).
		foreach ( wpoptimizing_scan_stage_keys() as $key ) {
			// A resumable stage (media) answers false until it is finished.
			while ( false === wpoptimizing_scan_do_stage( $key, $reason ) ) {
				continue;
			}
		}
		delete_transient( 'wpoptimizing_scan_lock' );
		delete_transient( 'wpoptimizing_scan_progress' );
		delete_option( 'wpoptimizing_scan_state' );
		return;
	}

	// Asynchronous: persist the stage cursor and kick off the first stage. Each
	// stage runs in its own job and schedules the next, so a slow site never
	// blows max_execution_time doing the whole scan in one request.
	// Clear any step lock left behind by a previous worker that died mid-stage (a hard
	// fatal / killed request skips the finally that releases it). Without this, a fresh
	// scan would sit at "Preparing…" until the stale lock's TTL expired (~5 min), because
	// the watchdog keeps a locked scan alive instead of advancing it.
	delete_transient( 'wpoptimizing_scan_step_lock' );
	update_option(
		'wpoptimizing_scan_state',
		array(
			'reason'    => sanitize_key( $reason ),
			'index'     => 0,
			'heartbeat' => time(),
			'retries'   => 0,
		),
		false
	);
	wpoptimizing_scan_enqueue_step();
}

/**
 * Execute a single scan stage. Each stage is self-contained and idempotent
 * (re-running rebuilds its own artifacts), so a stalled stage can be retried
 * safely. The heavy lifting is in the engine classes.
 *
 * A stage may be RESUMABLE: it does part of its work, saves where it is and answers false,
 * and the caller runs it again (the next request, or the next turn of the loop under WP-CLI).
 * The media analysis is one; every other stage finishes in one call.
 *
 * @param string $key    Stage key from wpoptimizing_scan_stage_keys().
 * @param string $reason Trigger reason (used by prepare/finalize).
 * @return bool True when the stage is finished; false when it must be called again.
 */
function wpoptimizing_scan_do_stage( $key, $reason = 'manual' ) {
	wpoptimizing_load_engine();

	// Arm the same per-connection SQL guard the cleanup engine uses (see
	// WPOPT_General_Cleanup::run() / WPOPT_Impact_Cleaner). The scanners read harder than the
	// cleaners do — the media pass runs REGEXP over every wp_postmeta row — and they run
	// unattended from cron, so an unguarded scan on a large database can hold the server
	// for minutes with nobody watching. Per stage, because each stage is its own request.
	if ( function_exists( 'wpoptimizing_db_statement_timeout' ) ) {
		/**
		 * Seconds any single scan query may run before the server aborts it.
		 *
		 * @since 0.1.0
		 * @param int    $seconds Timeout in seconds.
		 * @param string $key     Scan stage key.
		 */
		wpoptimizing_db_statement_timeout( (int) apply_filters( 'wpoptimizing_scan_statement_timeout', 60, $key ) );
	}

	$pct = wpoptimizing_scan_stage_pct( $key );

	switch ( $key ) {
		case 'prepare':
			wpoptimizing_scan_progress_update( $pct, __( 'Preparing scan…', 'wpoptimizing' ) );
			if ( class_exists( 'WPOPT_Runtime_Evidence' ) ) {
				WPOPT_Runtime_Evidence::reset();
			}
			// Delete ONLY the intermediate inputs the scanners rebuild by appending
			// (code CSVs) plus the derived upload files. Do NOT delete the display
			// result files WPOPT_CSV_SCAN_DB / WPOPT_CSV_SCAN_FS: every admin
			// subsection (Pending, Orphans, Inactive, Active, Files) reads them, so
			// deleting them up front blanks those sections for the whole duration of
			// the async scan — data visibly disappears and reappears at the end. The
			// producing stages (WPOPT_Impact_Cleaner / WPOPT_FS_Orphans) write them via a
			// temp file + atomic rename, so the last good results stay readable
			// throughout the scan and are swapped in one step when the new ones land.
			foreach ( array( WPOPT_CSV_DB, WPOPT_CSV_FS_CODE, WPOPT_CSV_UNIFIED_DB, WPOPT_CSV_UNIFIED_FILES ) as $f ) {
				if ( file_exists( $f ) ) {
					wp_delete_file( $f );
				}
			}
			// The media job of an earlier scan is not this one's to resume.
			if ( class_exists( 'WPOPT_Media_Analysis_Job' ) ) {
				( new WPOPT_Media_Analysis_Job( array(), WPOPT_Media_Analysis_Job::scan_dir() ) )->reset();
			}
			( new WPOPT_Performance_Logger() )->snapshot( 'before_scan' );
			break;

		case 'code_plugins':
			wpoptimizing_scan_progress_update( $pct, __( 'Scanning plugin code…', 'wpoptimizing' ) );
			( new WPOPT_Code_Scanner( WP_PLUGIN_DIR, 'plugin' ) )->run();
			break;

		case 'code_mu':
			if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
				wpoptimizing_scan_progress_update( $pct, __( 'Scanning must-use plugin code…', 'wpoptimizing' ) );
				( new WPOPT_Code_Scanner( WPMU_PLUGIN_DIR, 'plugin' ) )->run();
			}
			break;

		case 'code_theme':
			wpoptimizing_scan_progress_update( $pct, __( 'Scanning theme code…', 'wpoptimizing' ) );
			( new WPOPT_Code_Scanner( get_theme_root(), 'theme' ) )->run();
			break;

		case 'impact_db':
			wpoptimizing_scan_progress_update( $pct, __( 'Analysing database records…', 'wpoptimizing' ) );
			( new WPOPT_Impact_Cleaner() )->run();
			break;

		case 'empty_db':
			wpoptimizing_scan_progress_update( $pct, __( 'Listing empty database records…', 'wpoptimizing' ) );
			if ( class_exists( 'WPOPT_Empty_Scanner' ) ) {
				( new WPOPT_Empty_Scanner() )->run();
			}
			break;

		case 'fs_orphans':
			// CIRCUIT BREAKER: the file-orphan pass is the heaviest stage and, on some hosts
			// (huge plugin trees, low memory/time limits), it can hard-fatal the worker before
			// finishing — which would block the WHOLE analysis at this stage. We count how many
			// times it STARTS without ever COMPLETING; after a few failures we skip it so the
			// scan still finishes and delivers everything else (database, empties, autoload,
			// media…). A later scan retries it (the counter resets on any success). Skipping is
			// also available immediately via the wpoptimizing_skip_fs_orphans filter / the
			// WPOPT_SKIP_FS_ORPHANS constant.
			$fs_attempts = (int) get_option( 'wpoptimizing_fs_orphans_attempts', 0 );
			$fs_max_try  = (int) apply_filters( 'wpoptimizing_fs_orphans_max_attempts', 2 );
			$fs_skip     = ( defined( 'WPOPT_SKIP_FS_ORPHANS' ) && WPOPT_SKIP_FS_ORPHANS )
				|| (bool) apply_filters( 'wpoptimizing_skip_fs_orphans', false )
				|| ( $fs_attempts >= $fs_max_try );
			if ( $fs_skip ) {
				update_option( 'wpoptimizing_fs_orphans_skipped', time(), false );
				delete_option( 'wpoptimizing_fs_orphans_attempts' );
				wpoptimizing_scan_progress_update( $pct, __( 'Skipping file-orphan detection (too heavy on this server)…', 'wpoptimizing' ) );
				break;
			}
			// Mark this attempt BEFORE running: a hard fatal (OOM / max_execution_time) leaves
			// the counter incremented, so repeated failures trip the breaker above.
			update_option( 'wpoptimizing_fs_orphans_attempts', $fs_attempts + 1, false );
			$fs_label = __( 'Detecting orphaned files…', 'wpoptimizing' );
			wpoptimizing_scan_progress_update( $pct, $fs_label );
			// Pass a heartbeat callback so a slow file-system pass on a large site keeps the
			// stage marked alive instead of tripping the stall watchdog (which would kill and
			// restart it forever, leaving the orphan CSV frozen on an old snapshot).
			( new WPOPT_FS_Orphans() )->run(
				static function ( $hp, $hl ) {
					wpoptimizing_scan_progress_update( (int) $hp, (string) $hl );
				},
				$pct,
				$fs_label
			);
			delete_option( 'wpoptimizing_fs_orphans_attempts' ); // Reached here = completed → reset.
			break;

		case 'media':
			// Only on the first step: the later ones carry the job's own label and figures.
			if ( ! class_exists( 'WPOPT_Media_Analysis_Job' ) || ! is_dir( WPOPT_Media_Analysis_Job::scan_dir() ) ) {
				wpoptimizing_scan_progress_update( $pct, __( 'Analysing media & uploads…', 'wpoptimizing' ) );
			}
			return wpoptimizing_media_analysis_stage( $pct );

		case 'finalize':
			( new WPOPT_Performance_Logger() )->snapshot( 'after_scan' );
			wpoptimizing_scan_progress_update( $pct, __( 'Analysis complete.', 'wpoptimizing' ) );

			// Sync the knowledge base both ways — AFTER the scan, never inside it. Analysing
			// is when this site's picture of itself is freshest, so it is the right moment to
			// contribute and to pull the shared data down; but it moves several MB, and the
			// user must not wait a second longer for a progress bar because of it. So we hand
			// it to its own background job and finish. The daily job still runs regardless.
			wpoptimizing_kb_sync_schedule( $reason );

			// Store autoload metrics so the network overview can read them per-blog
			// (via get_blog_option) without switching context.
			if ( function_exists( 'wpoptimizing_analyze_autoload' ) ) {
				$autoload = wpoptimizing_analyze_autoload( 0 );
				update_option( 'wpoptimizing_autoload_size', (int) $autoload['size'], false );
				update_option( 'wpoptimizing_autoload_count', (int) $autoload['count'], false );
			}

			// Encrypt the local working CSVs at rest now the scan is done: their schema
			// is our attribution method. Every reader goes through
			// wpoptimizing_scan_read_handle() (decrypts transparently). The next scan
			// overwrites the result CSVs (SCAN_DB/SCAN_FS) via an atomic temp+rename
			// that writes fresh plaintext, and rebuilds the code CSVs (DB/FS_CODE)
			// from scratch — so each run produces fresh plaintext before this re-encrypts.
			if ( function_exists( 'wpoptimizing_scan_encrypt_file' ) ) {
				$wpopt_enc_list = array( WPOPT_CSV_SCAN_DB, WPOPT_CSV_SCAN_FS, WPOPT_CSV_SCAN_EMPTY, WPOPT_CSV_DB, WPOPT_CSV_FS_CODE );
				// The runtime attribution map (item name -> owner) is sensitive too. The
				// mu-plugin now writes it encrypted, but encrypt here as well so a copy
				// written before the drop-in updated (or without crypto) is never left in
				// plaintext — critical on nginx, where the data dir's .htaccess is ignored.
				$wpopt_enc_list[] = trailingslashit( WPOPT_DIR ) . 'apg-runtime-map.csv';
				foreach ( $wpopt_enc_list as $wpopt_csv ) {
					wpoptimizing_scan_encrypt_file( $wpopt_csv );
				}
			}

			$finished_at = time();
			update_option( 'wpoptimizing_last_scan', $finished_at, false );
			update_option( 'wpoptimizing_last_scan_reason', sanitize_key( $reason ), false );
			// First scan completed — dismiss the onboarding wizard and notice.
			delete_option( 'wpoptimizing_first_run' );

			/**
			 * Fires after a full scan completes successfully.
			 *
			 * @since 0.1.0
			 * @param string $reason  Trigger reason.
			 * @param array  $summary Scan summary (finished_at, autoload_size, autoload_count).
			 */
			do_action(
				'wpoptimizing_after_scan',
				$reason,
				array(
					'finished_at'    => $finished_at,
					'autoload_size'  => (int) get_option( 'wpoptimizing_autoload_size', 0 ),
					'autoload_count' => (int) get_option( 'wpoptimizing_autoload_count', 0 ),
				)
			);
			break;
	}
	return true;
}

/**
 * Enqueue the next scan stage: an async Action Scheduler job when available,
 * otherwise a near-immediate single WP-Cron event (nudged with spawn_cron).
 *
 * @return void
 */
function wpoptimizing_scan_enqueue_step() {
	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'wpoptimizing_cron_scan_step', array(), 'wpoptimizing' );
		return;
	}
	if ( ! wp_next_scheduled( 'wpoptimizing_cron_scan_step' ) ) {
		wp_schedule_single_event( time() + 1, 'wpoptimizing_cron_scan_step' );
	}
	if ( ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
		spawn_cron( time() );
	}
}

/**
 * Re-check the off-peak gate later without advancing the scan (used when an
 * automatic scan is deferred). No spawn_cron nudge — the point is to wait.
 *
 * @return void
 */
function wpoptimizing_scan_defer_step() {
	$delay = (int) apply_filters( 'wpoptimizing_scan_defer_seconds', 15 * MINUTE_IN_SECONDS );
	$delay = max( 60, $delay );
	if ( function_exists( 'as_schedule_single_action' ) ) {
		// One re-check at a time, as wp_next_scheduled() does below. Without it, a scan started
		// while an automatic one was waiting for the off-peak window added a second chain that
		// never died: each waiting step scheduled its own successor (emiliocalvo.es, 09/11/2026,
		// two steps every 15 minutes). Only PENDING counts: the step running this is in progress
		// and must still schedule the next one.
		if ( function_exists( 'as_get_scheduled_actions' ) && as_get_scheduled_actions(
			array(
				'hook'     => 'wpoptimizing_cron_scan_step',
				'group'    => 'wpoptimizing',
				'status'   => 'pending',
				'per_page' => 1,
			),
			'ids'
		) ) {
			return;
		}
		as_schedule_single_action( time() + $delay, 'wpoptimizing_cron_scan_step', array(), 'wpoptimizing' );
		return;
	}
	if ( ! wp_next_scheduled( 'wpoptimizing_cron_scan_step' ) ) {
		wp_schedule_single_event( time() + $delay, 'wpoptimizing_cron_scan_step' );
	}
}

/**
 * Whether a held step lock is stale (its worker died without releasing it). The lock value is
 * the acquisition time; a lock older than the threshold, or a legacy non-timestamp lock, is
 * reclaimable — otherwise a single dead worker would freeze the scan for the whole TTL (~5 min)
 * because the watchdog keeps a locked scan alive instead of advancing it.
 *
 * @param mixed $lock_value Stored transient value.
 * @return bool
 */
function wpoptimizing_scan_lock_is_stale( $lock_value ) {
	if ( ! is_numeric( $lock_value ) ) {
		return true; // Legacy bare-1 lock (pre-upgrade) → reclaimable.
	}
	$stale_after = (int) apply_filters( 'wpoptimizing_scan_lock_stale_seconds', 150 );
	$stale_after = max( 60, $stale_after );
	return ( time() - (int) $lock_value ) > $stale_after;
}

/**
 * Run the current scan stage, then advance and schedule the next one. Hooked to
 * the per-stage cron/Action-Scheduler job.
 *
 * @return void
 */
function wpoptimizing_scan_run_step() {
	wpoptimizing_load_engine();

	// Run exactly one stage at a time. If a worker is already crunching a stage,
	// bail out: this stops an over-eager watchdog re-enqueue (or a duplicate cron
	// nudge) from spawning a parallel run that restarts the stage and makes the
	// progress bar jump/loop. The lock auto-expires if a worker truly dies, so the
	// watchdog can still recover a genuinely stalled scan.
	$held = get_transient( 'wpoptimizing_scan_step_lock' );
	if ( $held && ! wpoptimizing_scan_lock_is_stale( $held ) ) {
		return; // A worker is genuinely crunching a stage right now.
	}
	$timeout = (int) apply_filters( 'wpoptimizing_scan_step_timeout', 180 );
	// Store the acquisition time (not a bare 1) so a stale lock from a dead worker can be
	// detected and reclaimed instead of blocking the scan until its TTL expires.
	set_transient( 'wpoptimizing_scan_step_lock', time(), max( 300, $timeout + 60 ) );

	// A hard fatal (out of memory, max_execution_time) skips the catch AND the finally below,
	// so the lock stayed held until it looked stale, 150 s later, and the stage then started
	// again with nobody counting. Release it here and count the death: a resumable stage
	// picks up from its last save, and the pump gives up after a few deaths in a row.
	// One token per step: Action Scheduler can run several steps in one request.
	$wpopt_step_token = uniqid( 'step', true );
	register_shutdown_function( 'wpoptimizing_scan_step_shutdown', $wpopt_step_token );

	try {
		$state = get_option( 'wpoptimizing_scan_state', null );
		if ( ! is_array( $state ) || ! isset( $state['index'] ) ) {
			return; // Cancelled or nothing in progress.
		}

		$keys  = wpoptimizing_scan_stage_keys();
		$index = (int) $state['index'];
		if ( $index < 0 || $index >= count( $keys ) ) {
			wpoptimizing_scan_finish();
			return;
		}

		$reason = isset( $state['reason'] ) ? (string) $state['reason'] : 'manual';

		// Off-peak gate: an AUTOMATIC scan can be deferred (e.g. to low-traffic
		// hours) by returning false from this filter — heavy stages then wait
		// instead of competing with live traffic. Manual scans always proceed (the
		// user is waiting). Default true = no change. Deferring keeps the heartbeat
		// fresh so the watchdog does not treat the wait as a stall.
		// A user actively watching the progress overlay (scan_pending_ui) also bypasses the
		// gate: they asked for results now, so a scheduled scan they are watching must not sit
		// deferred (it would look frozen, especially where the background cron does not fire).
		$watching = (bool) get_transient( 'wpoptimizing_scan_pending_ui' );
		if ( 'manual' !== $reason && ! $watching && ! apply_filters( 'wpoptimizing_scan_run_now', true, $reason ) ) {
			delete_transient( 'wpoptimizing_scan_step_lock' );
			$state['heartbeat'] = time();
			update_option( 'wpoptimizing_scan_state', $state, false );
			wpoptimizing_scan_defer_step();
			return;
		}

		try {
			$finished = wpoptimizing_scan_do_stage( $keys[ $index ], $reason );
		} catch ( Throwable $e ) {
			// Never leave the lock stuck; surface the failure so the user can retry.
			wpoptimizing_scan_fail( $e->getMessage() );
			return;
		}

		// A resumable stage saved where it is: same stage again, next request. It moved, so
		// whatever retries it had used are behind it.
		if ( false === $finished ) {
			$state['heartbeat'] = time();
			$state['retries']   = 0;
			update_option( 'wpoptimizing_scan_state', $state, false );
			wpoptimizing_scan_enqueue_step();
			return;
		}

		$state['index']     = $index + 1;
		$state['heartbeat'] = time();
		$state['retries']   = 0;
		update_option( 'wpoptimizing_scan_state', $state, false );

		if ( $state['index'] < count( $keys ) ) {
			wpoptimizing_scan_enqueue_step();
		} else {
			wpoptimizing_scan_finish();
		}
	} finally {
		delete_transient( 'wpoptimizing_scan_step_lock' );
		wpoptimizing_scan_step_shutdown( $wpopt_step_token, true );
	}
}

/**
 * After a step: release the lock and count the death if PHP died inside it.
 *
 * Registered by wpoptimizing_scan_run_step() and called again from its finally to disarm it
 * (a shutdown function cannot be unregistered, so it remembers it was disarmed).
 *
 * @param string $token  The step's token.
 * @param bool   $disarm Called from the finally: the step ended normally.
 * @return void
 */
function wpoptimizing_scan_step_shutdown( $token, $disarm = false ) {
	static $disarmed = array();
	if ( $disarm ) {
		$disarmed[ (string) $token ] = true;
		return;
	}
	if ( isset( $disarmed[ (string) $token ] ) ) {
		return;
	}
	$err = error_get_last();
	if ( ! is_array( $err ) || ! in_array( (int) $err['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
		return;
	}
	delete_transient( 'wpoptimizing_scan_step_lock' );
	$state = get_option( 'wpoptimizing_scan_state', null );
	if ( is_array( $state ) ) {
		$state['retries']    = (int) ( $state['retries'] ?? 0 ) + 1;
		$state['heartbeat']  = time();
		$state['last_fatal'] = substr( (string) $err['message'], 0, 200 );
		update_option( 'wpoptimizing_scan_state', $state, false );
	}
}

/**
 * Most deaths in a row, without the stage moving, before a scan is given up as stalled.
 *
 * Above the media job's own limit (three deaths at the same place, then it skips that piece),
 * so the job always gets the chance to step past what kills it.
 *
 * @return int
 */
function wpoptimizing_scan_max_retries() {
	return max( 1, (int) apply_filters( 'wpoptimizing_scan_max_retries', 5 ) );
}

/**
 * Clear scan state and release the lock after a successful run.
 *
 * @return void
 */
function wpoptimizing_scan_finish() {
	delete_option( 'wpoptimizing_scan_state' );
	delete_transient( 'wpoptimizing_scan_lock' );
	delete_transient( 'wpoptimizing_scan_progress' );
	delete_transient( 'wpoptimizing_scan_step_lock' );
	delete_transient( 'wpoptimizing_scan_starting' );
	// Clear the pending-overlay flags so the foreground pump does not see a lingering
	// "pending" request and restart the scan it just finished (the cause of a manual
	// scan running several times in a row on cron-less hosts).
	delete_transient( 'wpoptimizing_scan_pending_ui' );
	delete_transient( 'wpoptimizing_scan_pending_since' );
}

/**
 * Abort a scan cleanly (stall or fatal in a stage): release everything and
 * record the failure so the UI can report it.
 *
 * @param string $msg Optional error detail (truncated for the audit log).
 * @return void
 */
function wpoptimizing_scan_fail( $msg = '' ) {
	delete_option( 'wpoptimizing_scan_state' );
	delete_transient( 'wpoptimizing_scan_lock' );
	delete_transient( 'wpoptimizing_scan_progress' );
	delete_transient( 'wpoptimizing_scan_step_lock' );
	update_option( 'wpoptimizing_scan_failed_at', time(), false );
	if ( '' !== (string) $msg && function_exists( 'wpoptimizing_audit_log' ) ) {
		wpoptimizing_audit_log( 'scan_failed', array( 'error' => substr( (string) $msg, 0, 200 ) ) );
	}
}

/**
 * Watchdog: if the in-progress scan looks stalled (its worker died mid-stage),
 * re-enqueue the current idempotent stage a few times, then give up and fail.
 * Called from the status poll so recovery happens while the user is watching.
 *
 * @return void
 */
function wpoptimizing_scan_watchdog() {
	$state = get_option( 'wpoptimizing_scan_state', null );
	if ( ! is_array( $state ) || ! isset( $state['heartbeat'] ) ) {
		return;
	}
	// A held step lock means a worker is actively running a (possibly long) stage such as
	// media hashing — keep the heartbeat fresh and never restart it. But if the lock is STALE
	// (its worker died without releasing it), reclaim it and fall through to re-enqueue the
	// stage; otherwise the scan would sit frozen for the whole lock TTL.
	$held = get_transient( 'wpoptimizing_scan_step_lock' );
	if ( $held && ! wpoptimizing_scan_lock_is_stale( $held ) ) {
		$state['heartbeat'] = time();
		update_option( 'wpoptimizing_scan_state', $state, false );
		return;
	}
	if ( $held ) {
		delete_transient( 'wpoptimizing_scan_step_lock' ); // Reclaim a dead worker's lock.
	}
	/** @since 0.1.0 Seconds of silence before a stage is considered stalled. */
	$timeout = (int) apply_filters( 'wpoptimizing_scan_step_timeout', 180 );
	if ( ! wpoptimizing_scan_is_stalled( (int) $state['heartbeat'], time(), $timeout ) ) {
		return;
	}

	$retries = isset( $state['retries'] ) ? (int) $state['retries'] : 0;
	if ( $retries >= wpoptimizing_scan_max_retries() ) {
		wpoptimizing_scan_fail( 'stalled' );
		return;
	}
	$state['retries']   = $retries + 1;
	$state['heartbeat'] = time();
	update_option( 'wpoptimizing_scan_state', $state, false );
	wpoptimizing_scan_enqueue_step();
}

/**
 * Foreground scan pump, called from the status poll.
 *
 * On many real hosts WP-Cron and outgoing loopback requests are blocked (security
 * plugins, HTTP auth on staging, firewalls), so the background per-stage worker
 * (wpoptimizing_cron_scan_step) never runs and a MANUAL scan stalls forever at the
 * first stage. The browser, however, polls the status endpoint every few seconds —
 * a request we KNOW works. This pump rides that request to drive the scan: each poll
 * starts the scan if it was only scheduled, or advances exactly one idempotent stage.
 *
 * Background workers stay the fast path when they do run: the per-stage lock
 * serialises worker and pump so a stage is never executed twice at once. Automatic
 * scans are left to the background worker + off-peak gating (delegated to the
 * watchdog) so heavy stages never run inside an unrelated admin page's poll.
 *
 * @return void
 */
function wpoptimizing_scan_pump() {
	$state = get_option( 'wpoptimizing_scan_state', null );

	// No staged cursor yet. A manual scan may have been scheduled (cron event) but its
	// cron never fired on this host — start it here, in the foreground, so the next
	// poll has a cursor to advance. Only for user-initiated (pending/scheduled) scans.
	if ( ! is_array( $state ) ) {
		$pending    = (bool) get_transient( 'wpoptimizing_scan_pending_ui' );
		$since      = (int) get_transient( 'wpoptimizing_scan_pending_since' );
		$scheduled  = wpoptimizing_scan_event_pending();
		$started_at = (int) get_option( 'wpoptimizing_scan_started_at', 0 );
		$failed_at  = (int) get_option( 'wpoptimizing_scan_failed_at', 0 );
		$last_scan  = (int) get_option( 'wpoptimizing_last_scan', 0 );
		// Never auto-restart a scan that just failed: the failure is terminal until the
		// user retries, otherwise a failing stage would loop on every poll.
		$recently_failed = ( $failed_at > 0 && $failed_at >= $started_at );
		// Already completed for this pending request: a scan finished at or after the
		// request was made, so there is nothing to start — just let the status report
		// done. Prevents the manual scan running several times in a row.
		$already_done = ( $since > 0 && $last_scan >= $since );
		// Without the since mark (it lives 5 minutes; a big site's analysis lasts longer) the
		// request cannot be dated, and a stale "pending" flag read as a manual scan never run:
		// the pump started a whole new analysis by itself, seen on a 422,571-attachment site on
		// 22/10/2026 (reason "manual", nobody had clicked). Then the last scan that finished
		// after the last one started is the answer.
		if ( $since <= 0 && $last_scan > 0 && $last_scan >= $started_at ) {
			$already_done = true;
		}
		// scan_starting serialises near-simultaneous polls so only the first one kicks
		// off the scan (run_full_scan also takes the scan lock, but a hair later).
		if ( ( $pending || $scheduled ) && ! $already_done && ! $recently_failed
			&& ! get_transient( 'wpoptimizing_scan_lock' )
			&& ! get_transient( 'wpoptimizing_scan_starting' ) ) {
			set_transient( 'wpoptimizing_scan_starting', 1, 30 );
			wp_unschedule_hook( 'wpoptimizing_cron_scan' ); // Every queued one, whatever its reason.
			wpoptimizing_run_full_scan( 'manual' );
		}
		return;
	}

	$reason = isset( $state['reason'] ) ? (string) $state['reason'] : 'manual';

	// The pump only runs while the user is actively watching the progress overlay
	// (scan_pending_ui is set). In that case we drive ANY in-progress scan in the foreground —
	// including a background 'scheduled' one — so it never sits frozen on a host whose cron /
	// loopback does not fire. Only a genuinely unattended automatic scan (no overlay open) is
	// left to the background worker + off-peak gating, self-healing real stalls.
	if ( 'manual' !== $reason && ! get_transient( 'wpoptimizing_scan_pending_ui' ) ) {
		wpoptimizing_scan_watchdog();
		return;
	}

	// A worker is actively crunching a stage (its lock is held): let it finish and keep
	// the heartbeat fresh so the watchdog never restarts it in parallel. A STALE lock (dead
	// worker) is reclaimed instead, so a manual scan never freezes at "Preparing…" waiting
	// for the lock TTL — we fall through to run_step, which takes a fresh lock.
	$held = get_transient( 'wpoptimizing_scan_step_lock' );
	if ( $held && ! wpoptimizing_scan_lock_is_stale( $held ) ) {
		$state['heartbeat'] = time();
		update_option( 'wpoptimizing_scan_state', $state, false );
		return;
	}
	if ( $held ) {
		delete_transient( 'wpoptimizing_scan_step_lock' ); // Reclaim a dead worker's lock.
		// A dead worker is a retry, here as in the watchdog. Uncounted, a manual scan whose
		// stage died every time was started again on every poll, forever.
		$state['retries'] = (int) ( $state['retries'] ?? 0 ) + 1;
		update_option( 'wpoptimizing_scan_state', $state, false );
	}
	if ( (int) ( $state['retries'] ?? 0 ) > wpoptimizing_scan_max_retries() ) {
		wpoptimizing_scan_fail( 'stalled' );
		return;
	}

	// No worker running → advance exactly one stage right here. run_step takes the step
	// lock, runs one idempotent stage, advances the cursor and enqueues the next (which
	// a background worker, if any, may also pick up — the lock keeps them serialised).
	wpoptimizing_scan_run_step();
}

/**
 * Whether the current site-local time is inside the configured low-traffic (off-peak) window.
 * Used to keep AUTOMATIC full analyses off busy hours. A window whose start equals its end
 * means "no restriction" (always allowed). Hours are site-local (wp_date).
 *
 * @return bool
 */
function wpoptimizing_is_offpeak_now() {
	$start = (int) apply_filters( 'wpoptimizing_offpeak_start_hour', (int) get_option( 'wpoptimizing_scan_offpeak_start', 2 ) );
	$end   = (int) apply_filters( 'wpoptimizing_offpeak_end_hour', (int) get_option( 'wpoptimizing_scan_offpeak_end', 6 ) );
	$start = max( 0, min( 23, $start ) );
	$end   = max( 0, min( 23, $end ) );
	if ( $start === $end ) {
		return true; // Disabled window → never gate.
	}
	$hour = (int) ( function_exists( 'wp_date' ) ? wp_date( 'G' ) : gmdate( 'G' ) );
	if ( $start < $end ) {
		return ( $hour >= $start && $hour < $end );
	}
	return ( $hour >= $start || $hour < $end ); // Wraps past midnight (e.g. 23 → 5).
}

/**
 * Off-peak gate for AUTOMATIC scans: heavy stages of a non-manual analysis only run inside the
 * low-traffic window; outside it the per-stage worker defers and re-checks later. Manual scans
 * (the user is waiting) always run immediately.
 *
 * @param bool   $run    Whether the stage may run now.
 * @param string $reason Scan reason.
 * @return bool
 */
function wpoptimizing_scan_offpeak_gate( $run, $reason ) {
	if ( 'manual' === $reason ) {
		return $run;
	}
	return $run && wpoptimizing_is_offpeak_now();
}

/**
 * Recurring background-analysis handler. Launches a full analysis with reason 'scheduled'
 * (background + off-peak gated, never the blocking UI overlay) unless one is already running
 * or the last one finished recently. So sites of any size stay analysed without the user ever
 * waiting on a manual scan.
 *
 * @return void
 */
function wpoptimizing_scan_cron_run() {
	if ( is_array( get_option( 'wpoptimizing_scan_state', null ) ) ) {
		return; // A scan is already in progress.
	}
	$last    = (int) get_option( 'wpoptimizing_last_scan', 0 );
	$min_age = (int) apply_filters( 'wpoptimizing_scan_min_age', 20 * HOUR_IN_SECONDS );
	if ( $last > 0 && ( time() - $last ) < $min_age ) {
		return; // Scanned recently — don't pile up duplicate runs.
	}
	wpoptimizing_schedule_scan( 'scheduled' );
}

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_cron_scan', 'wpoptimizing_run_full_scan', 10, 1 );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_cron_scan_step', 'wpoptimizing_scan_run_step' );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_filter( 'wpoptimizing_scan_run_now', 'wpoptimizing_scan_offpeak_gate', 10, 2 );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_scan_cron', 'wpoptimizing_scan_cron_run' );
