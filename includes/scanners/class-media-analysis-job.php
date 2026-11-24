<?php
/**
 * The media analysis as a resumable job.
 *
 * WHY (22/10/2026). On a 422,571-attachment library the analysis sat at 81 % for hours. The old
 * analyze() read the whole library in one go: every attachment with its file, every file again,
 * and every metadata row reduced to file names, all of it held in memory at once — 1.3-1.7 GB of
 * PHP arrays against a 1024 MB limit — before the first progress tick. The worker died, the step
 * lock went stale after 150 s, the pump started the stage again from zero and wrote "5 %" of the
 * media band (81 % overall), forever: a manual scan does not count its retries.
 *
 * So the same analysis now runs as a job that is cut into small pieces and remembers where it is:
 *
 *  - `index`   reads attachments in ID order, a few hundred at a time, and writes what it learns
 *              to disk: the attachment list (`attachments.tsv`) and the registered paths, one
 *              shard per directory (`reg/<md5(dir)>.txt`). Nothing of the library stays in memory.
 *  - `walk`    walks uploads ONE DIRECTORY AT A TIME, loading only that directory's shard, so the
 *              memory is bounded by the biggest folder and not by the library.
 *  - `dupes`   hashes original files from `attachments.tsv` by byte offset.
 *  - `unused`  runs the reference sweep once, keeps its result on disk, then checks attachments
 *              by byte offset.
 *
 * Each call to step() works until its deadline and saves the cursor, the report so far and the
 * sizes of the two append-only lists in `state.json` (written atomically). A request that dies
 * half-way leaves a marker; the next step() sees it, cuts the lists back to the last saved sizes
 * (so nothing is counted twice; the shards are sets, so a repeated line is harmless), halves the
 * batch and carries on. Three deaths at the same place and that piece is skipped and the report
 * says it is partial, the same way it already did when a pass ran out of time.
 *
 * analyze() runs this job to the end in one go (WP-CLI, REST); the unified scan runs one step
 * per request (see wpoptimizing_media_analysis_stage()).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resumable media analysis.
 */
class WPOPT_Media_Analysis_Job extends WPOPT_Media_Analyzer {

	/**
	 * State file format. Bumped when the layout changes so an old job is discarded, not misread.
	 */
	const VERSION = 1;

	/**
	 * Deaths at the same place before that piece is skipped.
	 */
	const MAX_FAILURES = 3;

	/**
	 * The lists a step appends to and a dead request could leave half-written: cut back to the
	 * last saved size before going on. (The shards and the used-* sets are sets: a repeated
	 * line there is harmless.)
	 */
	const APPEND_LISTS = array(
		'attachments' => 'attachments.tsv',
		'sizes'       => 'sizes.tsv',
		'candidates'  => 'candidates.tsv',
		'hashes'      => 'hashes.tsv',
	);

	/**
	 * Working directory of this job.
	 *
	 * @var string
	 */
	protected $dir;

	/**
	 * Uploads base directory (normalized, no trailing slash).
	 *
	 * @var string
	 */
	protected $basedir;

	/**
	 * Analysis arguments (see WPOPT_Media_Analyzer::analyze()).
	 *
	 * @var array
	 */
	protected $args;

	/**
	 * Current state, as saved in state.json.
	 *
	 * @var array
	 */
	protected $state = array();

	/**
	 * Progress callback ( int $pct 0-100, string $label ).
	 *
	 * @var callable|null
	 */
	protected $progress = null;

	/**
	 * Registered paths of the folder being walked, while the walk stays in it.
	 *
	 * @var array<string,true>
	 */
	protected $registered = array();

	/**
	 * Folder those paths belong to.
	 *
	 * @var string|null
	 */
	protected $registered_dir = null;

	/**
	 * Set up a job.
	 *
	 * @param array       $args    Analysis arguments (max_files, sample, attachments_hash_max, hash_max_size, detect_unused, progress).
	 * @param string      $dir     Working directory; it is created and owned by the job.
	 * @param string|null $basedir Uploads directory; null for the site's own.
	 */
	public function __construct( array $args, $dir, $basedir = null ) {
		$this->dir = untrailingslashit( wp_normalize_path( (string) $dir ) );
		if ( null === $basedir ) {
			$uploads = wp_get_upload_dir();
			$basedir = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		}
		$this->basedir  = '' !== (string) $basedir ? untrailingslashit( wp_normalize_path( (string) $basedir ) ) : '';
		$this->progress = isset( $args['progress'] ) && is_callable( $args['progress'] ) ? $args['progress'] : null;
		unset( $args['progress'] );
		$this->args = self::normalize_args( $args );
	}

	/**
	 * The working directory of the unified scan's job.
	 *
	 * @return string
	 */
	public static function scan_dir() {
		return trailingslashit( WPOPT_DIR ) . 'media-job';
	}

	/**
	 * Defaults and filters for the analysis arguments, in one place.
	 *
	 * @param array $args Caller's arguments.
	 * @return array
	 */
	public static function normalize_args( array $args ) {
		$out = array(
			// 0 = sized to the library when the walk starts (see walk_cap()).
			'max_files'            => isset( $args['max_files'] ) ? (int) $args['max_files'] : 0,
			'sample'               => isset( $args['sample'] ) ? (int) $args['sample'] : 5000,
			// Hashed only among attachments that share a size with another (see dupes_batch()).
			'attachments_hash_max' => isset( $args['attachments_hash_max'] ) ? (int) $args['attachments_hash_max'] : 100000,
			'hash_max_size'        => isset( $args['hash_max_size'] ) ? (int) $args['hash_max_size'] : 25 * MB_IN_BYTES,
			'detect_unused'        => ! isset( $args['detect_unused'] ) || (bool) $args['detect_unused'],
		);
		// Filterable so hosts can tune the duplicate-hashing caps on huge libraries
		// (hundreds of thousands of files): how many attachments to hash, and the
		// per-file size ceiling above which a file is skipped.
		$out['attachments_hash_max'] = (int) apply_filters( 'wpoptimizing_media_hash_max', $out['attachments_hash_max'] );
		$out['hash_max_size']        = (int) apply_filters( 'wpoptimizing_media_hash_max_size', $out['hash_max_size'] );
		return $out;
	}

	/**
	 * Run the whole job in this request and return the report. Used by analyze().
	 *
	 * @return array<string,mixed>
	 */
	public function run_to_end() {
		$this->reset();
		do {
			$done = $this->step( 0 );
		} while ( ! $done );
		$report = $this->report();
		$this->reset();
		return $report;
	}

	/**
	 * Work until the deadline, save, and say whether the job is finished.
	 *
	 * @param float $budget Seconds this call may work; 0 = until the end.
	 * @return bool True when the report is complete.
	 */
	public function step( $budget ) {
		$deadline = $budget > 0 ? microtime( true ) + (float) $budget : INF;

		if ( ! $this->load() ) {
			$this->start();
		}
		if ( 'done' === $this->state['phase'] ) {
			return true;
		}
		$this->recover();
		$this->mark_inflight();

		// At least one piece of work per call, whatever the budget: a step that only checked
		// the clock would let a slow host call it forever without moving.
		do {
			switch ( $this->state['phase'] ) {
				case 'index':
					$this->index_batch();
					break;
				case 'walk':
					$this->walk_batch( $deadline );
					break;
				case 'dupes':
					$this->dupes_batch( $deadline );
					break;
				case 'unused':
					$this->unused_batch( $deadline );
					break;
				default:
					$this->state['phase'] = 'done';
			}
			// One step of real work done: the place it was stuck at, if any, is behind it.
			$this->state['failures'] = 0;
		} while ( 'done' !== $this->state['phase'] && microtime( true ) < $deadline );

		if ( 'done' === $this->state['phase'] ) {
			$this->tick( 100, __( 'Media analysis complete.', 'wpoptimizing' ) );
		}
		$this->save();
		$this->clear_inflight();
		return 'done' === $this->state['phase'];
	}

	/**
	 * The report as it stands.
	 *
	 * @return array<string,mixed>
	 */
	public function report() {
		if ( empty( $this->state ) ) {
			$this->load();
		}
		return isset( $this->state['report'] ) && is_array( $this->state['report'] ) ? $this->state['report'] : self::empty_report();
	}

	/**
	 * Throw the job away, files and all.
	 *
	 * @return void
	 */
	public function reset() {
		$this->state = array();
		if ( '' === $this->dir || ! is_dir( $this->dir ) ) {
			return;
		}
		foreach ( array( $this->dir . '/reg', $this->dir ) as $d ) {
			$files = glob( $d . '/*' );
			foreach ( is_array( $files ) ? $files : array() as $f ) {
				if ( is_file( $f ) ) {
					wp_delete_file( $f );
				}
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $this->dir . '/reg' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $this->dir );
	}

	/**
	 * Which phase the job is in, for callers that report it.
	 *
	 * @return string
	 */
	public function phase() {
		return isset( $this->state['phase'] ) ? (string) $this->state['phase'] : '';
	}

	/**
	 * When the saved job was started (0 when there is none).
	 *
	 * @return int
	 */
	public function started_at() {
		if ( empty( $this->state ) ) {
			$this->load();
		}
		return isset( $this->state['started'] ) ? (int) $this->state['started'] : 0;
	}

	/**
	 * A report with every bucket at zero.
	 *
	 * @return array<string,mixed>
	 */
	public static function empty_report() {
		$bucket = array(
			'count'      => 0,
			'size_bytes' => 0,
			'sample'     => array(),
		);
		return array(
			'files_scanned' => 0,
			'registered'    => 0,
			'orphan_files'  => $bucket,
			'orphan_thumbs' => $bucket,
			'other_files'   => $bucket,
			'duplicates'    => array(
				'groups'             => 0,
				'extra'              => 0,
				'reclaimable_bytes'  => 0,
				'attachments_hashed' => 0,
				'partial'            => false,
				'sample'             => array(),
			),
			// Registered attachments with no detectable reference in content/meta.
			// Heuristic and conservative (over-counts "used"); never auto-delete.
			'unused'        => array(
				'count'      => 0,
				'size_bytes' => 0,
				'partial'    => false,
				'sample'     => array(),
			),
			'truncated'     => false,
		);
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * State.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * A fresh job.
	 *
	 * @return void
	 */
	protected function start() {
		$this->reset();
		wp_mkdir_p( $this->dir . '/reg' );
		$this->state = array(
			'v'        => self::VERSION,
			'started'  => time(),
			'phase'    => 'index',
			'failures' => 0,
			'report'   => self::empty_report(),
			'index'    => array(
				'cursor' => 0,
				'done'   => 0,
				'total'  => $this->count_attachments(),
				'batch'  => function_exists( 'wpoptimizing_batch_size' ) ? wpoptimizing_batch_size( 'media_index', 300 ) : 300,
				'ok'     => true,
			),
			'sizes'    => array_fill_keys( array_keys( self::APPEND_LISTS ), 0 ),
		);
		// No uploads folder: nothing to walk, nothing to hash, an empty report — as before.
		if ( '' === $this->basedir || ! is_dir( $this->basedir ) ) {
			$this->state['phase'] = 'done';
		}
	}

	/**
	 * Read state.json.
	 *
	 * @return bool False when there is no usable job.
	 */
	protected function load() {
		$path = $this->dir . '/state.json';
		if ( ! is_file( $path ) ) {
			return false;
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || self::VERSION !== (int) ( $data['v'] ?? 0 ) || empty( $data['phase'] ) ) {
			return false;
		}
		$this->state = $data;
		return true;
	}

	/**
	 * Write state.json atomically: a reader sees the old state or the new one, never half.
	 *
	 * The two append-only lists are flushed by then, so their sizes are recorded with the
	 * cursor they belong to.
	 *
	 * @return void
	 */
	protected function save() {
		if ( ! is_dir( $this->dir ) ) {
			wp_mkdir_p( $this->dir . '/reg' );
		}
		clearstatcache();
		$this->state['sizes'] = array();
		foreach ( self::APPEND_LISTS as $key => $file ) {
			$this->state['sizes'][ $key ] = $this->file_size( $file );
		}
		$path = $this->dir . '/state.json';
		$tmp  = wpoptimizing_atomic_tmp_path( $path );
		if ( false !== file_put_contents( $tmp, wp_json_encode( $this->state ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! @rename( $tmp, $path ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * After a request that died: cut the lists back, halve the batch, count the death, and
	 * skip the piece that keeps killing it.
	 *
	 * @return void
	 */
	protected function recover() {
		if ( ! is_file( $this->dir . '/inflight' ) ) {
			return;
		}
		foreach ( self::APPEND_LISTS as $key => $file ) {
			$this->truncate( $file, (int) ( $this->state['sizes'][ $key ] ?? 0 ) );
		}
		$this->state['failures']       = (int) ( $this->state['failures'] ?? 0 ) + 1;
		$this->state['index']['batch'] = max( 25, (int) floor( (int) $this->state['index']['batch'] / 2 ) );
		if ( $this->state['failures'] < self::MAX_FAILURES ) {
			return;
		}
		$this->state['failures'] = 0;
		$this->skip_current();
	}

	/**
	 * Give up on the piece the job is stuck on and say so in the report.
	 *
	 * @return void
	 */
	protected function skip_current() {
		$report =& $this->state['report'];
		switch ( $this->state['phase'] ) {
			case 'index':
				// A partial index would list every file it did not reach as an orphan next to
				// a delete button. So no orphan pass at all, and the report says it stopped short.
				$this->state['index']['ok'] = false;
				$this->enter_walk();
				break;
			case 'walk':
				$report['truncated'] = true;
				if ( ! empty( $this->state['walk']['queue'] ) ) {
					array_shift( $this->state['walk']['queue'] );
				}
				$this->state['walk']['pos'] = 0;
				break;
			case 'dupes':
				$report['duplicates']['partial'] = true;
				$this->enter_unused();
				break;
			case 'unused':
				$report['unused']['partial'] = true;
				$this->state['phase']        = 'done';
				break;
		}
	}

	/**
	 * Leave a mark that a step is running; a step that finds it knows the last one died.
	 *
	 * @return void
	 */
	protected function mark_inflight() {
		file_put_contents( $this->dir . '/inflight', (string) time() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * The step finished and saved.
	 *
	 * @return void
	 */
	protected function clear_inflight() {
		if ( is_file( $this->dir . '/inflight' ) ) {
			wp_delete_file( $this->dir . '/inflight' );
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Phase 1: index.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * How many attachments there are, for the progress bar. 0 when the count failed.
	 *
	 * @return int
	 */
	protected function count_attachments() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Index one batch of attachments.
	 *
	 * Two queries along indexes: attachment IDs past the cursor on the primary key, then their
	 * three meta rows by post_id. The old pass paged postmeta with `meta_key IN (…) ORDER BY
	 * meta_id`, which the database sorts again on every page.
	 *
	 * @return void
	 */
	protected function index_batch() {
		global $wpdb;
		$idx   =& $this->state['index'];
		$batch = max( 25, (int) $idx['batch'] );
		$t0    = microtime( true );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				(int) $idx['cursor'],
				$batch
			)
		);
		// A FAILED QUERY IS NOT THE END OF THE LIBRARY (see walk_column()): wpdb answers an
		// error with an empty array, and a scan arms a statement timeout.
		if ( '' !== (string) $wpdb->last_error ) {
			$this->index_failed();
			return;
		}
		$ids = array_map( 'intval', is_array( $ids ) ? $ids : array() );
		if ( empty( $ids ) ) {
			$this->enter_walk();
			return;
		}

		// One %d per ID and every value through prepare(), which core accepts as a single array;
		// the placeholder sniff counts that array as one replacement and miscounts (as in
		// walk_column()).
		$holders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$holders} ) AND meta_key IN ( %s, %s, %s )",
				array_merge( $ids, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) )
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( '' !== (string) $wpdb->last_error ) {
			$this->index_failed();
			return;
		}

		$by_id = array();
		foreach ( is_array( $rows ) ? $rows : array() as $i => $row ) {
			$by_id[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
			unset( $rows[ $i ] );
		}

		$lines  = '';
		$shards = array();
		foreach ( $ids as $id ) {
			$meta = isset( $by_id[ $id ] ) ? $by_id[ $id ] : array();
			$file = isset( $meta['_wp_attached_file'] ) ? self::normalize_rel( $meta['_wp_attached_file'] ) : '';
			// A tab or a line break would break the list; WordPress never lets one into a name.
			if ( '' !== $file && false === strpbrk( $file, "\t\r\n" ) ) {
				$lines .= $id . "\t" . $file . "\n";
			}
			$blobs = array();
			if ( isset( $meta['_wp_attachment_metadata'] ) ) {
				$blobs[] = self::slim_metadata( maybe_unserialize( $meta['_wp_attachment_metadata'] ) );
			}
			if ( '' !== $file && isset( $meta['_wp_attachment_backup_sizes'] ) ) {
				$backup = maybe_unserialize( $meta['_wp_attachment_backup_sizes'] );
				if ( is_array( $backup ) ) {
					$blobs[] = self::slim_metadata(
						array(
							'file'         => $file,
							'backup_sizes' => $backup,
						)
					);
				}
			}
			$attached = '' !== $file ? array( $file ) : array();
			foreach ( array_keys( self::build_registered_set( $attached, array_filter( $blobs ) ) ) as $rel ) {
				$shards[ self::dir_of( $rel ) ][] = $rel;
			}
			unset( $by_id[ $id ] );
		}

		$this->append( 'attachments.tsv', $lines );
		foreach ( $shards as $dir => $paths ) {
			$this->append( 'reg/' . md5( $dir ) . '.txt', implode( "\n", $paths ) . "\n" );
		}
		$this->state['report']['registered'] += array_sum( array_map( 'count', $shards ) );

		$idx['cursor'] = (int) end( $ids );
		$idx['done']  += count( $ids );
		if ( function_exists( 'wpoptimizing_tune_batch' ) ) {
			$idx['batch'] = wpoptimizing_tune_batch( $batch, microtime( true ) - $t0, 1.0, 50, 2000 );
		}

		$total = max( 1, (int) $idx['total'] );
		$this->tick(
			(int) floor( 40 * min( 1, $idx['done'] / $total ) ),
			/* translators: 1: attachments indexed so far, 2: attachments in the library. */
			sprintf( __( 'Indexing the media library… %1$s / %2$s', 'wpoptimizing' ), number_format_i18n( (int) $idx['done'] ), number_format_i18n( (int) $idx['total'] ) )
		);

		if ( count( $ids ) < $batch ) {
			$this->enter_walk();
		}
	}

	/**
	 * A query of the index failed. A database that is momentarily slow gets the same tries as
	 * a request that died; past that, the index is incomplete and the orphan pass is skipped.
	 *
	 * @return void
	 */
	protected function index_failed() {
		$this->state['index']['batch']  = max( 25, (int) floor( (int) $this->state['index']['batch'] / 2 ) );
		$this->state['index']['errors'] = (int) ( $this->state['index']['errors'] ?? 0 ) + 1;
		if ( $this->state['index']['errors'] >= self::MAX_FAILURES ) {
			$this->state['index']['ok'] = false;
			$this->enter_walk();
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Phase 2: walk uploads, one directory at a time.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * Start the walk (or skip it when the index is not complete).
	 *
	 * @return void
	 */
	protected function enter_walk() {
		if ( function_exists( 'wpoptimizing_batch_size_learn' ) ) {
			wpoptimizing_batch_size_learn( 'media_index', (int) $this->state['index']['batch'] );
		}
		$this->state['phase'] = 'walk';
		$this->state['walk']  = array(
			'queue'  => array( '' ),
			'pos'    => 0,
			'dirs'   => 0,
			'ignore' => self::ignored_dirs(),
			'max'    => $this->walk_cap( (int) $this->state['report']['registered'] ),
		);
		if ( empty( $this->state['index']['ok'] ) ) {
			$this->state['report']['truncated'] = true;
			$this->enter_dupes();
		}
	}

	/**
	 * Most files the uploads walk will look at.
	 *
	 * Sized to the library instead of a fixed 2,000,000: a 422,571-attachment site registers
	 * 3,976,716 paths (every size of every image), so the walk stopped half-way and the orphan
	 * report was marked truncated. The walk holds one folder at a time, so the cap bounds the
	 * time an analysis takes, not its memory: twice the registered paths, never under 2,000,000.
	 *
	 * @param int $registered Paths the index registered.
	 * @return int
	 */
	protected function walk_cap( $registered ) {
		$cap = (int) $this->args['max_files'] > 0 ? (int) $this->args['max_files'] : max( 2000000, 2 * (int) $registered );
		/**
		 * Most files the uploads walk will look at.
		 *
		 * @since 0.1.0
		 * @param int $cap        Files.
		 * @param int $registered Paths the media library registers.
		 */
		return max( 1, (int) apply_filters( 'wpoptimizing_media_max_files', $cap, (int) $registered ) );
	}

	/**
	 * Top-level folders that are somebody else's data, not orphaned media.
	 *
	 * @return string[] Lowercase names.
	 */
	protected static function ignored_dirs() {
		// Some of them hold a shop's paid downloads (woocommerce_uploads, edd) or form submissions.
		$ignore = array( 'cache', 'backups', 'wpoptimizing', 'wc-logs', 'wc-imports', 'woocommerce_uploads', 'gravity_forms', 'wpforms', 'shortpixelbackups', 'edd' );
		// On the main site of a network, uploads/sites/ holds EVERY sub-site's library, and
		// none of it is registered here — it was all listed as "Other files", with Delete all.
		if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'is_main_site' ) && is_main_site() ) {
			$ignore[] = 'sites';
			$ignore[] = 'blogs.dir';
		}
		/**
		 * Top-level upload subdirectories to skip (e.g. plugin caches/backups).
		 *
		 * @since 0.1.0
		 * @param string[] $dirs Directory names (relative to uploads root).
		 */
		$ignore = (array) apply_filters( 'wpoptimizing_media_ignore_dirs', $ignore );
		return array_values( array_map( 'strtolower', array_map( 'strval', $ignore ) ) );
	}

	/**
	 * Walk until the deadline: the directory at the head of the queue, from where it was left.
	 *
	 * @param float $deadline microtime(true) to stop at.
	 * @return void
	 */
	protected function walk_batch( $deadline ) {
		$walk   =& $this->state['walk'];
		$report =& $this->state['report'];
		$max    = isset( $walk['max'] ) ? (int) $walk['max'] : $this->walk_cap( (int) $report['registered'] );
		$sample = (int) $this->args['sample'];

		if ( empty( $walk['queue'] ) ) {
			$this->enter_dupes();
			return;
		}
		$rel_dir = (string) $walk['queue'][0];
		$abs_dir = $this->basedir . ( '' !== $rel_dir ? '/' . $rel_dir : '' );
		$names   = is_dir( $abs_dir ) ? scandir( $abs_dir ) : false;
		$names   = is_array( $names ) ? array_values( array_diff( $names, array( '.', '..' ) ) ) : array();

		if ( $this->registered_dir !== $rel_dir ) {
			$this->registered     = $this->load_shard( $rel_dir );
			$this->registered_dir = $rel_dir;
		}
		$registered = $this->registered;

		$count = count( $names );
		for ( $i = (int) $walk['pos']; $i < $count; $i++ ) {
			if ( $i > (int) $walk['pos'] && 0 === $i % 200 && microtime( true ) >= $deadline ) {
				$walk['pos'] = $i;
				return;
			}
			$name = (string) $names[ $i ];
			$rel  = '' !== $rel_dir ? $rel_dir . '/' . $name : $name;
			$abs  = $abs_dir . '/' . $name;

			// Symlinked folders are not followed, as RecursiveDirectoryIterator did not.
			if ( is_dir( $abs ) ) {
				if ( ! is_link( $abs ) && ( '' !== $rel_dir || ! in_array( strtolower( $name ), (array) $walk['ignore'], true ) ) ) {
					$walk['queue'][] = $rel;
				}
				continue;
			}
			if ( ! is_file( $abs ) ) {
				continue;
			}
			if ( $report['files_scanned'] >= $max ) {
				$report['truncated'] = true;
				$walk['queue']       = array();
				$this->enter_dupes();
				return;
			}
			++$report['files_scanned'];
			$this->classify_file( self::normalize_rel( $rel ), $abs, $registered, $sample );
		}

		array_shift( $walk['queue'] );
		$walk['pos'] = 0;
		++$walk['dirs'];
		$this->registered_dir = null;

		$left = count( $walk['queue'] );
		$this->tick(
			40 + (int) floor( 25 * $walk['dirs'] / max( 1, $walk['dirs'] + $left ) ),
			/* translators: %s: files looked at so far. */
			sprintf( __( 'Scanning uploads… %s files', 'wpoptimizing' ), number_format_i18n( (int) $report['files_scanned'] ) )
		);
		if ( 0 === $left ) {
			$this->enter_dupes();
		}
	}

	/**
	 * The registered paths of one folder, as a set.
	 *
	 * @param string $rel_dir Folder relative to uploads ('' = the root).
	 * @return array<string,true>
	 */
	protected function load_shard( $rel_dir ) {
		return $this->load_set( 'reg/' . md5( (string) $rel_dir ) . '.txt' );
	}

	/**
	 * Put one file that the library does not know into its bucket, like the old pass did.
	 *
	 * @param string             $rel        Path relative to uploads.
	 * @param string             $abs        Absolute path.
	 * @param array<string,true> $registered Registered paths of its folder.
	 * @param int                $sample     Sample size per bucket.
	 * @return void
	 */
	protected function classify_file( $rel, $abs, array $registered, $sample ) {
		if ( '' === $rel || isset( $registered[ $rel ] ) ) {
			return;
		}
		$name = basename( $rel );
		// Server/system stubs (index.php, .htaccess, OS metadata…) are seeded into
		// every uploads folder and are never media — skip them entirely.
		if ( self::is_system_stub( $name ) ) {
			return;
		}
		$ext = strtolower( (string) pathinfo( $rel, PATHINFO_EXTENSION ) );
		// Skip next-gen copies (.webp/.avif) that image optimizers (LiteSpeed,
		// EWWW, ShortPixel…) generate for an original still on disk: they have
		// no DB record by design and are NOT orphans. Genuinely orphaned
		// derivatives (their original is gone) still surface.
		if ( ( 'webp' === $ext || 'avif' === $ext ) && self::is_nextgen_derivative( $abs, true ) ) {
			return;
		}
		// Retina (@2x/@3x), appended next-gen (.jpg.webp) or extra size whose SOURCE
		// is a registered attachment file: a legitimate generated companion of real
		// media, not an orphan. (A derivative whose source is itself gone still
		// surfaces below.)
		if ( self::is_registered_derivative( $rel, $registered ) ) {
			return;
		}
		$size = (int) @filesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( self::is_thumbnail( $name ) ) {
			$bucket = 'orphan_thumbs';
		} elseif ( self::is_plugin_like_path( $rel ) ) {
			// In a named (non-YYYY) folder → likely plugin data, not media.
			$bucket = 'other_files';
		} elseif ( ! self::is_uploadable_media_ext( $ext ) ) {
			// Root/date-folder file that is NOT a recognised media/document type
			// (e.g. a GeoIP .dat, a .log or a .json a plugin left in /uploads):
			// real data, not a deletable media orphan → list it as "other".
			$bucket = 'other_files';
		} else {
			$bucket = 'orphan_files';
		}
		$report =& $this->state['report'];
		++$report[ $bucket ]['count'];
		$report[ $bucket ]['size_bytes'] += $size;
		if ( count( $report[ $bucket ]['sample'] ) < $sample ) {
			$report[ $bucket ]['sample'][] = array(
				'file'       => $rel,
				'size_bytes' => $size,
				'type'       => $ext,
			);
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Phase 3: duplicates.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * Start hashing.
	 *
	 * @return void
	 */
	protected function enter_dupes() {
		$this->state['phase'] = 'dupes';
		$this->state['dupes'] = array(
			'step'       => 'size',
			'offset'     => 0,
			'seen'       => 0,
			'candidates' => 0,
			'hashed'     => 0,
		);
	}

	/**
	 * Duplicates in two steps, so the whole library is compared without reading all of it.
	 *
	 * It used to hash the first 30,000 attachments in the list, whatever they were: on a
	 * 422,571-attachment site that was 7 % of the library and, had the cap been lifted, tens
	 * of GB read off the server's disk. Two files can only be identical if they are the same
	 * size, so first every original's SIZE (a stat), then a hash only for those that share a
	 * size with another one — the usual way, and a small fraction of the bytes.
	 *
	 * @param float $deadline microtime(true) to stop at.
	 * @return void
	 */
	protected function dupes_batch( $deadline ) {
		$d =& $this->state['dupes'];
		if ( ! isset( $d['step'] ) ) {
			$d['step'] = 'size'; // A job saved by the previous format.
		}
		if ( 'size' === $d['step'] ) {
			$this->dupes_sizes( $deadline );
			return;
		}
		$this->dupes_hashes( $deadline );
	}

	/**
	 * Step 1: the size of every original, to sizes.tsv; then the ones that share a size, to
	 * candidates.tsv.
	 *
	 * @param float $deadline microtime(true) to stop at.
	 * @return void
	 */
	protected function dupes_sizes( $deadline ) {
		$d     =& $this->state['dupes'];
		$limit = (int) $this->args['hash_max_size'];
		$total = max( 1, (int) $this->state['index']['done'] );
		$out   = '';
		$ended = $this->each_attachment(
			(int) $d['offset'],
			function ( $att, $offset ) use ( &$d, &$out, $limit, $deadline ) {
				$d['offset'] = $offset;
				++$d['seen'];
				$abs  = $this->basedir . '/' . $att['file'];
				$size = is_readable( $abs ) ? (int) @filesize( $abs ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( $size > 0 && $size <= $limit ) {
					$out .= $size . "\t" . $att['id'] . "\t" . $att['file'] . "\n";
				}
				return ! ( 0 === $d['seen'] % 500 && microtime( true ) >= $deadline );
			}
		);
		$this->append( 'sizes.tsv', $out );
		$this->tick( 65 + (int) floor( 10 * min( 1, $d['seen'] / $total ) ), __( 'Comparing duplicate images…', 'wpoptimizing' ) );
		if ( ! $ended ) {
			return;
		}

		// Sizes seen more than once. One int per attachment: a few MB on the biggest library.
		$count = array();
		$fh    = $this->open( 'sizes.tsv', 'rb' );
		if ( $fh ) {
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$size           = (int) $line;
				$count[ $size ] = isset( $count[ $size ] ) ? $count[ $size ] + 1 : 1;
			}
			rewind( $fh );
			$out = '';
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( ( $count[ (int) $line ] ?? 0 ) > 1 ) {
					$out .= $line;
					++$d['candidates'];
					if ( strlen( $out ) > 1048576 ) {
						$this->append( 'candidates.tsv', $out );
						$out = '';
					}
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->append( 'candidates.tsv', $out );
		}
		$d['step']   = 'hash';
		$d['offset'] = 0;
		$d['seen']   = 0;
	}

	/**
	 * Step 2: hash the candidates until the deadline, then group them.
	 *
	 * @param float $deadline microtime(true) to stop at.
	 * @return void
	 */
	protected function dupes_hashes( $deadline ) {
		$d      =& $this->state['dupes'];
		$report =& $this->state['report'];
		$max    = (int) $this->args['attachments_hash_max'];
		$total  = max( 1, min( (int) $d['candidates'], $max ) );
		$fh     = $this->open( 'candidates.tsv', 'rb' );
		$out    = '';
		$ended  = true;

		if ( $fh ) {
			fseek( $fh, (int) $d['offset'] );
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				if ( $d['hashed'] >= $max ) {
					$report['duplicates']['partial'] = true;
					break;
				}
				$d['offset'] = ftell( $fh );
				++$d['seen'];
				$parts = explode( "\t", rtrim( $line, "\r\n" ), 3 );
				if ( 3 === count( $parts ) ) {
					$hash = @md5_file( $this->basedir . '/' . $parts[2] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( false !== $hash ) {
						$out .= $parts[1] . "\t" . $hash . "\t" . $parts[0] . "\t" . $parts[2] . "\n";
						++$d['hashed'];
					}
				}
				if ( 0 === $d['seen'] % 100 && microtime( true ) >= $deadline ) {
					$ended = false;
					break;
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$this->append( 'hashes.tsv', $out );

		$this->tick( 75 + (int) floor( 10 * min( 1, $d['seen'] / $total ) ), __( 'Comparing duplicate images…', 'wpoptimizing' ) );
		if ( ! $ended ) {
			return;
		}

		$report['duplicates']['attachments_hashed'] = (int) $d['hashed'];
		$this->group_duplicates();
		$this->enter_unused();
	}

	/**
	 * Group the hashed attachments and fill the duplicates bucket.
	 *
	 * @return void
	 */
	protected function group_duplicates() {
		$report =& $this->state['report'];
		$sample = (int) $this->args['sample'];
		$items  = array();
		$fh     = $this->open( 'hashes.tsv', 'rb' );
		if ( $fh ) {
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$parts = explode( "\t", rtrim( $line, "\n" ), 4 );
				if ( 4 === count( $parts ) ) {
					$items[] = array(
						'id'   => (int) $parts[0],
						'hash' => $parts[1],
						'file' => $parts[3],
						'size' => (int) $parts[2],
					);
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		foreach ( self::group_attachment_duplicates( $items ) as $group ) {
			$keep  = $group[0];
			$dupes = array_slice( $group, 1 );
			++$report['duplicates']['groups'];
			$report['duplicates']['extra'] += count( $dupes );
			foreach ( $dupes as $dup ) {
				$report['duplicates']['reclaimable_bytes'] += (int) $dup['size'];
			}
			if ( count( $report['duplicates']['sample'] ) < $sample ) {
				$report['duplicates']['sample'][] = array(
					'keep'  => $keep,
					'dupes' => array_values( $dupes ),
				);
			}
		}
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Phase 4: unused attachments.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * Start (or skip) the unused-attachment pass.
	 *
	 * @return void
	 */
	protected function enter_unused() {
		$this->state['phase']  = $this->args['detect_unused'] ? 'unused' : 'done';
		$this->state['unused'] = array(
			'swept'  => false,
			'offset' => 0,
			'seen'   => 0,
		);
	}

	/**
	 * First the reference sweep, pass by pass (sweep_batch()); then attachments by offset
	 * until the deadline.
	 *
	 * Unused attachments are registered in the library but with no detectable reference in
	 * content or meta. Heuristic and conservative (errs toward "used").
	 *
	 * @param float $deadline microtime(true) to stop at.
	 * @return void
	 */
	protected function unused_batch( $deadline ) {
		$u      =& $this->state['unused'];
		$report =& $this->state['report'];
		$sample = (int) $this->args['sample'];

		if ( empty( $u['swept'] ) ) {
			$this->sweep_batch( $deadline );
			return;
		}

		$used = is_file( $this->dir . '/usage.ser' ) ? unserialize( (string) file_get_contents( $this->dir . '/usage.ser' ), array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( ! is_array( $used ) || 2 !== count( $used ) ) {
			$report['unused']['partial'] = true;
			$this->state['phase']        = 'done';
			return;
		}
		list( $used_ids, $used_keys ) = $used;
		unset( $used );

		$total = max( 1, (int) $this->state['index']['done'] );
		$ended = $this->each_attachment(
			(int) $u['offset'],
			function ( $att, $offset ) use ( &$u, &$report, $used_ids, $used_keys, $sample, $deadline ) {
				$u['offset'] = $offset;
				++$u['seen'];
				foreach ( self::find_unused_attachments( array( $att ), $used_ids, $used_keys ) as $unused ) {
					$abs  = $this->basedir . '/' . $unused['file'];
					$size = is_file( $abs ) ? (int) filesize( $abs ) : 0;
					++$report['unused']['count'];
					$report['unused']['size_bytes'] += $size;
					if ( count( $report['unused']['sample'] ) < $sample ) {
						$report['unused']['sample'][] = array(
							'id'    => $unused['id'],
							'file'  => $unused['file'],
							'bytes' => $size,
						);
					}
				}
				return ! ( 0 === $u['seen'] % 500 && microtime( true ) >= $deadline );
			}
		);

		$this->tick( 95 + (int) floor( 4 * min( 1, $u['seen'] / $total ) ), __( 'Checking for unused attachments…', 'wpoptimizing' ) );
		if ( $ended ) {
			$this->state['phase'] = 'done';
		}
	}

	/**
	 * One step of the reference sweep: the passes of usage_passes(), resumed from the saved
	 * pass and row, until the deadline.
	 *
	 * It used to be one call with 25 seconds for everything. On a 422,571-attachment site that
	 * ran out long before the end and the report called 205,407 attachments (42 GB) unused,
	 * partial — half a news site's library offered for deletion. Now it takes the requests it
	 * needs. What each step finds is appended to used-ids.txt and used-keys.txt (sets, so a
	 * step repeated after a death adds nothing); once every pass is done they become usage.ser.
	 *
	 * @param float $deadline microtime(true) to stop at.
	 * @return void
	 */
	protected function sweep_batch( $deadline ) {
		$u      =& $this->state['unused'];
		$report =& $this->state['report'];
		if ( ! isset( $u['sweep'] ) || ! is_array( $u['sweep'] ) ) {
			$u['sweep'] = array();
		}

		$att_set = array();
		$this->each_attachment(
			0,
			static function ( $att ) use ( &$att_set ) {
				$att_set[ $att['id'] ] = true;
				return true;
			}
		);
		$ids  = array();
		$keys = array();
		$done = $this->usage_step( $u['sweep'], $att_set, $deadline, $ids, $keys );
		unset( $att_set );
		$this->append( 'used-ids.txt', $ids ? implode( "\n", array_keys( $ids ) ) . "\n" : '' );
		$this->append( 'used-keys.txt', $keys ? implode( "\n", array_keys( $keys ) ) . "\n" : '' );
		unset( $ids, $keys );

		$total = max( 1, $this->usage_pass_count() );
		$pass  = (int) $u['sweep']['pass'];
		$this->tick(
			85 + (int) floor( 10 * min( 1, $pass / $total ) ),
			/* translators: 1: step of the reference sweep, 2: steps in all. */
			sprintf( __( 'Looking for where each attachment is used… step %1$d of %2$d', 'wpoptimizing' ), min( $total, $pass + 1 ), $total )
		);
		if ( ! $done ) {
			return;
		}

		$used_ids  = $this->load_set( 'used-ids.txt' );
		$used_keys = $this->load_set( 'used-keys.txt' );
		$used_ids  = array_fill_keys( array_map( 'intval', array_keys( $used_ids ) ), true );
		// Attachments the user marked as known/trusted stop resurfacing every scan (fonts a page
		// builder loads, an image an SEO plugin references): things the heuristic cannot see
		// but the user has confirmed are in use.
		foreach ( array_map( 'intval', (array) get_option( 'wpoptimizing_trusted_attachments', array() ) ) as $tid ) {
			$used_ids[ $tid ] = true;
		}
		file_put_contents( $this->dir . '/usage.ser', serialize( array( $used_ids, $used_keys ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$report['unused']['partial'] = ! empty( $u['sweep']['partial'] );
		$u['swept']                  = true;
	}

	/**
	 * A work file of one value per line, as a set.
	 *
	 * @param string $file Name inside the job directory.
	 * @return array<string,true>
	 */
	protected function load_set( $file ) {
		$path = $this->dir . '/' . $file;
		if ( ! is_file( $path ) ) {
			return array();
		}
		$lines = file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		return is_array( $lines ) ? array_fill_keys( $lines, true ) : array();
	}

	/*
	 * ---------------------------------------------------------------------------------------
	 * Files.
	 * ---------------------------------------------------------------------------------------
	 */

	/**
	 * Read attachments.tsv from a byte offset, one attachment at a time.
	 *
	 * @param int      $offset  Where to start.
	 * @param callable $consume Gets ( array{id:int,file:string} $att, int $next_offset ); false stops.
	 * @return bool True when the list ran out, false when the callback stopped it.
	 */
	protected function each_attachment( $offset, callable $consume ) {
		$fh = $this->open( 'attachments.tsv', 'rb' );
		if ( ! $fh ) {
			return true;
		}
		fseek( $fh, (int) $offset );
		$ended = true;
		while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition, Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$att = self::parse_line( $line );
			if ( null !== $att && false === $consume( $att, (int) ftell( $fh ) ) ) {
				$ended = false;
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $ended;
	}

	/**
	 * One line of attachments.tsv. Pure.
	 *
	 * @param string $line "id\tfile\n".
	 * @return array{id:int,file:string}|null
	 */
	public static function parse_line( $line ) {
		$parts = explode( "\t", rtrim( (string) $line, "\r\n" ), 2 );
		if ( 2 !== count( $parts ) || (int) $parts[0] <= 0 || '' === $parts[1] ) {
			return null;
		}
		return array(
			'id'   => (int) $parts[0],
			'file' => $parts[1],
		);
	}

	/**
	 * The folder of an uploads-relative path ('' for the root). Pure.
	 *
	 * @param string $rel Path.
	 * @return string
	 */
	public static function dir_of( $rel ) {
		$pos = strrpos( (string) $rel, '/' );
		return false === $pos ? '' : substr( (string) $rel, 0, $pos );
	}

	/**
	 * Append to a work file.
	 *
	 * @param string $file Name inside the job directory.
	 * @param string $data Data.
	 * @return void
	 */
	protected function append( $file, $data ) {
		if ( '' === (string) $data ) {
			return;
		}
		file_put_contents( $this->dir . '/' . $file, $data, FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Open a work file.
	 *
	 * @param string $file Name inside the job directory.
	 * @param string $mode fopen() mode.
	 * @return resource|false
	 */
	protected function open( $file, $mode ) {
		$path = $this->dir . '/' . $file;
		if ( ! is_file( $path ) ) {
			return false;
		}
		return fopen( $path, $mode ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	}

	/**
	 * Size of a work file (0 when missing).
	 *
	 * @param string $file Name inside the job directory.
	 * @return int
	 */
	protected function file_size( $file ) {
		$path = $this->dir . '/' . $file;
		return is_file( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * Cut a work file back to a size, dropping what a dead request appended after the save.
	 *
	 * @param string $file Name inside the job directory.
	 * @param int    $size Size to keep.
	 * @return void
	 */
	protected function truncate( $file, $size ) {
		if ( $this->file_size( $file ) <= $size ) {
			return;
		}
		$fh = fopen( $this->dir . '/' . $file, 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh ) {
			ftruncate( $fh, max( 0, (int) $size ) );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Report progress, 0-100 within the media analysis.
	 *
	 * @param int    $pct   Percent.
	 * @param string $label Label (translated).
	 * @return void
	 */
	protected function tick( $pct, $label ) {
		if ( $this->progress ) {
			call_user_func( $this->progress, (int) $pct, (string) $label );
		}
	}
}
