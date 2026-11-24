<?php
/**
 * Detect NON‑native files/folders in wp-content that are not protected by ACTIVE projects,
 * and report if there are INACTIVE projects that reference them.
 *
 * Strategy
 * --------
 * - Native allowlist (natives.json)
 * - Code shield (WPOPT_CSV_FS_CODE → active/inactive mapping)
 * - Heuristic shield (optional): protect well‑known plugin folders in uploads/wp-content
 *   whenever the corresponding extension is ACTIVE (e.g., WooCommerce uploads buckets).
 *   Extend via 'wpoptimizing_active_fs_paths'.
 *
 * Produces: WPOPT_CSV_SCAN_FS
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_FS_Orphans {

	use WPOptimizing_Slug_Matching_Trait;

	/** @var string wp-content path. */
	protected $content_path;

	/** @var string This site's uploads folder, relative to wp-content (uploads/sites/<id> on a sub-site). */
	protected $uploads_rel = 'uploads';

	/**
	 * Where natives.json lives (shared with the impact cleaner).
	 *
	 * @return string
	 */
	public static function natives_path() {
		return dirname( __DIR__ ) . '/cleanup/natives.json';
	}

	/** @var array Natives (folders/files). */
	protected $natives = array(
		'folders' => array(),
		'files'   => array(),
	);

	/**
	 * Map path (relative to wp-content) → ['active'=>[...], 'inactive'=>[...]]
	 *
	 * @var array<string,array<string,array<int,array{origen:string,slug:string}>>>
	 */
	protected $fs_map = array();

	/** @var string[] Active slugs. */
	protected $active_slugs = array();

	/** @var string[] Installed slugs. */
	protected $installed_slugs = array();

	/** @var string[] Installed plugin slugs. */
	protected $installed_plugin_slugs = array();

	/** @var string[] Installed theme slugs. */
	protected $installed_theme_slugs = array();

	/** @var array<string,string> Normalized slug => original slug. */
	protected $installed_slug_map = array();

	/** @var array<string,string[]> Slug => aliases (normalized). */
	protected $installed_slug_aliases = array();

	/** @var array<string,string[]> Slug => regex patterns for matching paths. */
	protected $installed_slug_patterns = array();

	/** @var string[] Installed but inactive slugs. */
	protected $inactive_slugs = array();

	/** @var string[] Extra protected path prefixes (relative to wp-content). */
	protected $extra_protected_paths = array();
	/** @var string|null Temp CSV path for atomic writes. */
	protected $csv_tmp_path = null;
	/** @var array<string,int> Size cache by relative path. */
	protected $size_cache = array();
	/** @var array<string,bool> Cache for FS heuristic evidence checks. */
	protected $heuristic_evidence_cache = array();
	/** @var array<string,bool> Cache for slug evidence in fs_map. */
	protected $fs_map_slug_cache = array();
	/** @var string[] Allowed code extensions for heuristic evidence checks. */
	protected $heuristic_scan_ext = array( 'php', 'phtml', 'inc', 'php5', 'js' );
	/** @var bool Whether to require strong evidence for owner attribution. */
	protected $strict_attribution = true;
	/** @var array<string,array{origins:string,slugs:string}> Runtime FS evidence map. */
	protected $runtime_fs_map = array();
	/** @var callable|null Progress/heartbeat callback ( $pct, $label ): keeps the scan watchdog alive. */
	protected $tick = null;
	/** @var int Stage percentage to report on each heartbeat tick. */
	protected $tick_pct = 68;
	/** @var string Stage label to report on each heartbeat tick. */
	protected $tick_label = '';
	/** @var float Last time the heartbeat callback actually fired (rate limiting). */
	protected $last_tick = 0.0;
	/** @var array<string,bool> Memoized code-token searches, keyed by slug + sorted token set. */
	protected $token_search_cache = array();
	/** @var float Wall-clock deadline (epoch seconds) after which deep code searches are skipped. 0 = no limit. */
	protected $search_deadline = 0.0;
	/** @var bool True once a top-level pass stopped early at the wall-clock deadline (partial result). */
	protected $deadline_hit = false;
	/** @var array<string,bool>|null Lazily-built set of root-level Media Library filenames (basename => true). */
	protected $root_attachment_files = null;

	/**
	 * Constructor. Initialises content path and loads natives/maps.
	 */
	public function __construct() {
		$this->content_path = untrailingslashit( WP_CONTENT_DIR );
		$this->load_natives();
		$this->load_fs_map();
		$this->active_slugs            = $this->get_active_project_slugs();
		$this->installed_slugs         = $this->get_installed_project_slugs();
		$this->inactive_slugs          = array_values( array_diff( $this->installed_slugs, $this->active_slugs ) );
		$this->installed_slug_patterns = $this->build_installed_slug_patterns( $this->installed_slugs );
		$this->extra_protected_paths   = $this->build_active_fs_paths( $this->active_slugs );
		$this->strict_attribution      = (bool) apply_filters( 'wpoptimizing_strict_attribution', true, 'fs' );
		$this->runtime_fs_map          = $this->get_runtime_fs_map();
	}

	/**
	 * Execute analysis and generate FS orphans.
	 *
	 * @param callable|null $tick  Optional progress/heartbeat callback ( int $pct, string $label ).
	 *                             Called periodically so the scan watchdog does not treat a slow
	 *                             file-system pass (large sites) as a stalled stage and kill it.
	 * @param int           $pct   Stage percentage to report on each heartbeat.
	 * @param string        $label Stage label to report on each heartbeat.
	 * @return void
	 */
	public function run( $tick = null, $pct = 68, $label = '' ) {
		$this->tick       = is_callable( $tick ) ? $tick : null;
		$this->tick_pct   = (int) $pct;
		$this->tick_label = (string) $label;

		// Hard wall-clock budget for the expensive per-slug code searches, so the stage
		// always finishes inside one PHP request instead of being killed mid-pass at
		// max_execution_time and restarted forever. Kept well under the typical PHP limit;
		// once exceeded, a slug whose NAME already matched the path is trusted without
		// reading its files (conservative: it attributes to the installed owner, so it can
		// only AVOID false orphans, never create them).
		$budget = (int) apply_filters( 'wpoptimizing_fs_orphans_search_budget', 20 );
		$limit  = (int) ( function_exists( 'ini_get' ) ? ini_get( 'max_execution_time' ) : 0 );
		if ( $limit > 0 ) {
			// Always keep a healthy headroom (≥12s) below max_execution_time so the constructor,
			// the final atomic CSV write and any single-call overshoot can NEVER push the worker
			// to a hard fatal (which skips cleanup, holds the step lock and stalls the scan).
			$budget = min( $budget, max( 5, min( (int) floor( $limit * 0.5 ), $limit - 12 ) ) );
		}
		$this->search_deadline = microtime( true ) + $budget;

		$this->csv_tmp_path = wpoptimizing_atomic_tmp_path( WPOPT_CSV_SCAN_FS );
		if ( file_exists( $this->csv_tmp_path ) ) {
			wp_delete_file( $this->csv_tmp_path );
		}
		$fh = fopen( $this->csv_tmp_path, 'w' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		fputcsv( $fh, array( 'type', 'relative_path', 'reason', 'suggested_status', 'project_status', 'project_origins', 'project_slugs', 'size_bytes' ), ',', '"', '\\' );
		fclose( $fh ); // phpcs:ignore

		$this->scan_wp_content_root();
		$this->scan_uploads_first_level();

		// Atomic swap to avoid partial reads. rename() is atomic within the same
		// directory and overwrites the destination in one step, so a reader never
		// sees a missing or partially written file (copy()+delete left a window
		// where the destination did not exist).
		if ( file_exists( $this->csv_tmp_path ) ) {
			rename( $this->csv_tmp_path, WPOPT_CSV_SCAN_FS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename, WordPress.WP.AlternativeFunctions.rename_rename
		}

		// The result is partial when the time budget cut a pass or a code search short: kept
		// beside the CSV (when, or 0), so whoever reads it can tell.
		update_option( 'wpoptimizing_fs_orphans_partial', $this->deadline_hit ? time() : 0, false );
	}

	/**
	 * Whether the last run() stopped early at its wall-clock budget.
	 *
	 * @return bool
	 */
	public function is_partial() {
		return (bool) $this->deadline_hit;
	}

	/**
	 * Emit a heartbeat to the scan watchdog, rate-limited to once every few seconds so the
	 * callback itself never becomes a cost. No-op when run() was called without a callback
	 * (e.g. direct/unit invocation), preserving zero overhead off the async scan path.
	 *
	 * @return void
	 */
	protected function maybe_tick() {
		if ( null === $this->tick ) {
			return;
		}
		$now = microtime( true );
		if ( $now - $this->last_tick < 5.0 ) {
			return;
		}
		$this->last_tick = $now;
		call_user_func( $this->tick, $this->tick_pct, $this->tick_label );
	}

	/**
	 * Whether the wall-clock budget for this run() has been exceeded. Used to bound the
	 * top-level directory passes too (not only the per-slug code searches), so a single run
	 * NEVER overruns max_execution_time on a huge uploads tree — which would hard-fatal the
	 * PHP worker, skip its cleanup (the step lock would stay held ~5 min) and leave the scan
	 * looping forever at this stage. Stopping early yields a partial-but-valid result and lets
	 * the stage complete so the scan moves on.
	 *
	 * @return bool
	 */
	protected function past_deadline() {
		return $this->search_deadline > 0.0 && microtime( true ) > $this->search_deadline;
	}

	/**
	 * Load the native paths whitelist from natives.json.
	 *
	 * The file lives in includes/cleanup/, next to the impact cleaner that shares it. This
	 * used to look for it beside this file, where it has never been, so the scanner always
	 * ran on the short fallback list below.
	 *
	 * @return void
	 */
	protected function load_natives() {
		$path = self::natives_path();
		if ( file_exists( $path ) ) {
			$contents = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$json     = is_string( $contents ) ? json_decode( $contents, true, 5 ) : null;
			if ( is_array( $json ) ) {
				$this->natives['folders'] = array_map( 'strval', (array) ( $json['folders'] ?? array() ) );
				$this->natives['files']   = array_map( 'strval', (array) ( $json['files'] ?? array() ) );
			}
		}
		if ( empty( $this->natives['folders'] ) ) {
			$this->natives['folders'] = array( 'plugins', 'themes', 'uploads', 'upgrade', 'mu-plugins', 'languages' );
		}
		if ( empty( $this->natives['files'] ) ) {
			$this->natives['files'] = array( 'index.php', 'advanced-cache.php', 'object-cache.php', 'db.php', '.htaccess' );
		}
	}

	/**
	 * Fill $this->fs_map grouping by path and status.
	 * CSV header: [ type, origin, project_slug, status, relative_file ].
	 *
	 * @return void
	 */
	protected function load_fs_map() {
		if ( ! file_exists( WPOPT_CSV_FS_CODE ) ) {
			return;
		}
		$fh = function_exists( 'wpoptimizing_scan_read_handle' ) ? wpoptimizing_scan_read_handle( WPOPT_CSV_FS_CODE ) : fopen( WPOPT_CSV_FS_CODE, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- decrypts if encrypted at rest
		if ( false === $fh ) {
			return;
		}
		fgetcsv( $fh, 0, ',', '"', '\\' ); // header
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
			if ( count( $row ) < 5 ) {
				continue;
			}
			list( $type, $origin, $slug, $status, $rel ) = $row;
			$rel = $this->normalize_rel_path( $rel );
			if ( '' === $rel ) {
				continue;
			}
			if ( ! isset( $this->fs_map[ $rel ] ) ) {
				$this->fs_map[ $rel ] = array(
					'active'   => array(),
					'inactive' => array(),
				);
			}
			$status_key                            = ( 'active' === $status ) ? 'active' : 'inactive';
			$this->fs_map[ $rel ][ $status_key ][] = array(
				'origen' => $origin,
				'slug'   => $slug,
			);
		}
		fclose( $fh ); // phpcs:ignore
	}

	/**
	 * Scan wp-content root for non-native items.
	 *
	 * @return void
	 */
	protected function scan_wp_content_root() {
		$dir = opendir( $this->content_path ); // phpcs:ignore
		if ( false === $dir ) {
			return;
		}

		while ( false !== ( $entry = readdir( $dir ) ) ) { // phpcs:ignore
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->maybe_tick();
			if ( $this->past_deadline() ) {
				$this->deadline_hit = true;
				break;
			}
			$abs = $this->content_path . '/' . $entry;
			$rel = $this->normalize_rel_path( $entry );
			if ( function_exists( 'wpoptimizing_fs_is_own_path' ) && wpoptimizing_fs_is_own_path( $rel ) ) {
				continue; // The plugin's own live files are never offered for deletion.
			}

			if ( is_dir( $abs ) ) {
				if ( in_array( $entry, $this->natives['folders'], true ) ) {
					continue;
				}
				$this->maybe_append_orphan_row( 'dir', $rel, 'non_native_wpcontent' );
			} else {
				if ( in_array( $entry, $this->natives['files'], true ) ) {
					continue;
				}
				$this->maybe_append_orphan_row( 'file', $rel, 'non_native_wpcontent' );
			}
		}

		closedir( $dir ); // phpcs:ignore
	}

	/**
	 * Scan first-level entries under uploads/.
	 *
	 * @return void
	 */
	protected function scan_uploads_first_level() {
		$uploads = wp_get_upload_dir();
		$base    = $uploads['basedir'];
		if ( empty( $base ) || ! is_dir( $base ) ) {
			return;
		}
		// The path of this site's uploads folder relative to wp-content: on a sub-site it is
		// uploads/sites/<id>, and building it as 'uploads/<entry>' attributed, listed and
		// deleted the main site's folder of the same name instead.
		$uploads_rel = function_exists( 'wpoptimizing_fs_rel_from_abs' ) ? wpoptimizing_fs_rel_from_abs( $base, $this->content_path ) : 'uploads';
		if ( '' === $uploads_rel ) {
			return; // Uploads outside wp-content: nothing here is a wp-content path.
		}
		$this->uploads_rel = $uploads_rel;
		$dir               = opendir( $base ); // phpcs:ignore
		if ( false === $dir ) {
			return;
		}

		while ( false !== ( $entry = readdir( $dir ) ) ) { // phpcs:ignore
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$this->maybe_tick();
			if ( $this->past_deadline() ) {
				$this->deadline_hit = true;
				break;
			}
			$abs = $base . '/' . $entry;
			$rel = $this->normalize_rel_path( $uploads_rel . '/' . $entry );
			if ( function_exists( 'wpoptimizing_fs_is_own_path' ) && wpoptimizing_fs_is_own_path( $rel ) ) {
				continue; // The plugin's own live files are never offered for deletion.
			}
			if ( ! is_dir( $abs ) && in_array( 'uploads/' . $entry, $this->natives['files'], true ) ) {
				continue; // uploads/.htaccess, uploads/index.php: WordPress' own.
			}

			if ( is_dir( $abs ) ) {
				// Year folders and the network's uploads/sites are WordPress' own, and they hold
				// everybody's files. This ran AFTER the evidence match, so one plugin writing to
				// uploads/2024/x marked the whole year folder as that plugin's.
				if ( self::is_shared_uploads_folder( $entry, $uploads_rel ) ) {
					continue;
				}
				$match = $this->match_upload_entry_from_fs_map( $rel );
				if ( 'none' === $match['status'] && ! $this->strict_attribution ) {
					$match = $this->match_installed_upload_entry( $entry );
				}
				if ( 'active' === $match['status'] ) {
					$this->append_active_row( 'dir', $rel, 'uploads_top_level', $match['origins'], $match['slugs'] );
					continue;
				}
				if ( 'inactive' === $match['status'] ) {
					$this->append_inactive_row( 'dir', $rel, 'uploads_top_level', $match['origins'], $match['slugs'] );
					continue;
				}
				// Extra-protected paths should be assigned to active/inactive when possible.
				if ( $this->is_extra_protected_path( $rel ) ) {
					$extra_match = $this->match_installed_slug_in_path( $rel );
					if ( 'active' === $extra_match['status'] ) {
						$this->append_active_row( 'dir', $rel, 'uploads_top_level', $extra_match['origins'], $extra_match['slugs'] );
						continue;
					}
					if ( 'inactive' === $extra_match['status'] ) {
						$this->append_inactive_row( 'dir', $rel, 'uploads_top_level', $extra_match['origins'], $extra_match['slugs'] );
						continue;
					}
					// If unresolved, keep visibility by running normal orphan classification.
					$this->maybe_append_orphan_row( 'dir', $rel, 'uploads_top_level' );
					continue;
				}
				$this->maybe_append_orphan_row( 'dir', $rel, 'uploads_top_level' );
			} else {
				// WordPress can store media straight in uploads/ (no year/month folders).
				// Such a file — the attachment original or any of its generated sizes — is a
				// real Media Library item, NOT an orphan, even though its name may share a
				// plugin's prefix (e.g. akismet.jpg). Skip anything the attachments table
				// knows about; let the Media tab own those.
				if ( $this->is_registered_media_file( $rel ) ) {
					continue;
				}
				$this->maybe_append_orphan_row( 'file', $rel, 'uploads_root_file' );
			}
		}

		closedir( $dir ); // phpcs:ignore
	}

	/**
	 * Whether a uploads-root file belongs to a registered Media Library attachment — the
	 * original, a generated sub-size, the -scaled full, or the kept original_image. Built
	 * from the attachments table (exact, no name guessing) and limited to root-level files
	 * (those with no year/month subfolder), which is the only place this scan flags files.
	 *
	 * @param string $rel Path relative to wp-content, e.g. "uploads/akismet-150x150.jpg".
	 * @return bool
	 */
	protected function is_registered_media_file( $rel ) {
		$rel    = strtolower( $this->normalize_rel_path( (string) $rel ) );
		$prefix = strtolower( $this->uploads_rel ) . '/';
		if ( 0 !== strpos( $rel, $prefix ) ) {
			return false;
		}
		$name = substr( $rel, strlen( $prefix ) );
		if ( '' === $name || false !== strpos( $name, '/' ) ) {
			return false; // Only root-level uploads files are scanned here.
		}
		$this->load_root_attachment_files();
		if ( isset( $this->root_attachment_files[ $name ] ) ) {
			return true;
		}
		// A generated derivative (retina @2x/@3x, appended next-gen .jpg.webp, or a -WxH
		// size) whose source is a known root attachment is also Media, not a plugin orphan.
		// Reuse the canonical derivative logic so the two scanners never drift apart.
		if ( class_exists( 'WPOPT_Media_Analyzer' ) ) {
			foreach ( WPOPT_Media_Analyzer::derivative_sources( $name ) as $src ) {
				if ( isset( $this->root_attachment_files[ strtolower( $src ) ] ) ) {
					return true;
				}
			}
			return false;
		}
		// Fallback (analyzer not loaded): a "-WxH" sub-size of a known original.
		$base = preg_replace( '/-\d+x\d+(\.[a-z0-9]+)$/', '$1', $name );
		return null !== $base && $base !== $name && isset( $this->root_attachment_files[ $base ] );
	}

	/**
	 * Lazily build the set (basename => true, lowercased) of every file referenced by a
	 * root-level attachment: the original from _wp_attached_file, plus its sizes, -scaled
	 * full and original_image from _wp_attachment_metadata. Root-only because attachment
	 * sizes live in the original's directory, and this scan only flags root-level files.
	 *
	 * @return void
	 */
	protected function load_root_attachment_files() {
		if ( null !== $this->root_attachment_files ) {
			return;
		}
		$this->root_attachment_files = array();
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return;
		}
		// Root-level originals (no slash in _wp_attached_file) + their attachment IDs.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value <> '' AND meta_value NOT LIKE '%/%'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids  = array();
		foreach ( (array) $rows as $r ) {
			$f = strtolower( ltrim( (string) ( $r['meta_value'] ?? '' ), '/' ) );
			if ( '' !== $f ) {
				$this->root_attachment_files[ $f ] = true;
				$ids[]                             = (int) ( $r['post_id'] ?? 0 );
			}
		}
		$ids = array_values( array_filter( array_unique( $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}
		// Generated sizes / scaled full / original_image for those attachments. All live in
		// the same (root) directory, so we only need their basenames.
		$in = implode( ',', array_map( 'intval', $ids ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$metas = $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND post_id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- integer-only ID list built above.
		foreach ( (array) $metas as $m ) {
			$data = is_string( $m ) ? maybe_unserialize( $m ) : $m;
			if ( ! is_array( $data ) ) {
				continue;
			}
			if ( ! empty( $data['file'] ) ) {
				$this->root_attachment_files[ strtolower( basename( (string) $data['file'] ) ) ] = true;
			}
			// The un-scaled original and the companion files the client-side media flow
			// sideloads (see scanners/attachment-companions.php). Nothing links to any of them
			// directly, so without this they read as orphans and get offered for deletion.
			foreach ( wpoptimizing_attachment_companion_files( $data ) as $companion ) {
				$this->root_attachment_files[ strtolower( $companion ) ] = true;
			}
			if ( ! empty( $data['sizes'] ) && is_array( $data['sizes'] ) ) {
				foreach ( $data['sizes'] as $size ) {
					if ( ! empty( $size['file'] ) ) {
						$this->root_attachment_files[ strtolower( basename( (string) $size['file'] ) ) ] = true;
					}
				}
			}
		}
	}

	/**
	 * Match uploads top-level entries to installed slugs and return status.
	 *
	 * @param string $entry Folder name in uploads/.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function match_installed_upload_entry( $entry ) {
		$entry = strtolower( (string) $entry );
		if ( '' === $entry ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}

		$active_matches   = array();
		$inactive_matches = array();
		$inactive_origins = array();

		foreach ( $this->installed_slugs as $slug ) {
			$slug = strtolower( (string) $slug );
			if ( '' === $slug ) {
				continue;
			}
			$candidates = array( $slug );
			if ( 'woocommerce' === $slug ) {
				$candidates[] = 'wc';
			}
			$alias_prefixes = apply_filters( 'wpoptimizing_upload_alias_prefixes', array( 'wc-', 'woocommerce-' ) );
			foreach ( (array) $alias_prefixes as $prefix ) {
				if ( 0 === strpos( $slug, $prefix ) ) {
					$short = substr( $slug, strlen( $prefix ) );
					if ( $short ) {
						$candidates[] = $short;
					}
				}
			}

			foreach ( array_unique( $candidates ) as $cand ) {
				if ( $entry === $cand || 0 === strpos( $entry, $cand . '-' ) || 0 === strpos( $entry, $cand . '_' ) ) {
					if ( ! $this->heuristic_slug_has_evidence( $slug, $entry ) ) {
						continue;
					}
					if ( in_array( $slug, $this->active_slugs, true ) ) {
						$active_matches[ $slug ] = true;
					} else {
						$inactive_matches[ $slug ] = true;
						$inactive_origins[ $slug ] = $this->slug_origin( $slug );
					}
				}
			}
		}

		if ( ! empty( $active_matches ) ) {
			$active_origins = array();
			foreach ( array_keys( $active_matches ) as $slug ) {
				$active_origins[ $slug ] = $this->slug_origin( $slug );
			}
			return array(
				'status'  => 'active',
				'origins' => implode( '|', array_values( array_unique( array_filter( $active_origins ) ) ) ),
				'slugs'   => implode( '|', array_keys( $active_matches ) ),
			);
		}

		if ( ! empty( $inactive_matches ) ) {
			return array(
				'status'  => 'inactive',
				'origins' => implode( '|', array_values( array_unique( array_filter( $inactive_origins ) ) ) ),
				'slugs'   => implode( '|', array_keys( $inactive_matches ) ),
			);
		}

		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * Match uploads top-level entry by explicit evidence in fs_map.
	 *
	 * @param string $rel Relative uploads path, e.g. uploads/foo.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function match_upload_entry_from_fs_map( $rel ) {
		$rel = $this->normalize_rel_path( $rel );
		if ( '' === $rel ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}

		$meta          = $this->collect_fs_map_meta_for_prefix( $rel );
		$active_meta   = $meta['active'];
		$inactive_meta = $meta['inactive'];

		if ( ! empty( $active_meta ) ) {
			$summary = $this->summarize_project_meta( $active_meta );
			return array(
				'status'  => 'active',
				'origins' => $summary['origins'],
				'slugs'   => $summary['slugs'],
			);
		}
		if ( ! empty( $inactive_meta ) ) {
			$summary = $this->summarize_project_meta( $inactive_meta );
			return array(
				'status'  => 'inactive',
				'origins' => $summary['origins'],
				'slugs'   => $summary['slugs'],
			);
		}

		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * Summarize map metadata rows into pipe-separated origin/slug values.
	 *
	 * @param array<int,array{origen?:string,slug?:string}> $rows Metadata rows.
	 * @return array{origins:string,slugs:string}
	 */
	protected function summarize_project_meta( array $rows ) {
		$origins = array();
		$slugs   = array();
		foreach ( $rows as $row ) {
			$origin = isset( $row['origen'] ) ? (string) $row['origen'] : '';
			$slug   = isset( $row['slug'] ) ? (string) $row['slug'] : '';
			if ( '' !== $origin ) {
				$origins[ $origin ] = true;
			}
			if ( '' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}
		return array(
			'origins' => implode( '|', array_keys( $origins ) ),
			'slugs'   => implode( '|', array_keys( $slugs ) ),
		);
	}

	/**
	 * Append a row marked as inactive with provided slugs/origins.
	 *
	 * @param string $type    'dir'|'file'.
	 * @param string $rel     Path relative to wp-content.
	 * @param string $why     Reason/category.
	 * @param string $origins Origins string.
	 * @param string $slugs   Slugs string.
	 * @return void
	 */
	protected function append_inactive_row( $type, $rel, $why, $origins, $slugs ) {
		$target = $this->csv_tmp_path ?: WPOPT_CSV_SCAN_FS;
		$fh = fopen( $target, 'a' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		$size = $this->get_path_size( $type, $rel );
		fputcsv( $fh, array( $type, $rel, $why, 'disposable', 'inactive', $origins, $slugs, $size ), ',', '"', '\\' );
		fclose( $fh ); // phpcs:ignore
	}

	/**
	 * Append a row marked as active with provided slugs/origins.
	 *
	 * @param string $type    'dir'|'file'.
	 * @param string $rel     Path relative to wp-content.
	 * @param string $why     Reason/category.
	 * @param string $origins Origins string.
	 * @param string $slugs   Slugs string.
	 * @return void
	 */
	protected function append_active_row( $type, $rel, $why, $origins, $slugs ) {
		$target = $this->csv_tmp_path ?: WPOPT_CSV_SCAN_FS;
		$fh = fopen( $target, 'a' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		$size = $this->get_path_size( $type, $rel );
		fputcsv( $fh, array( $type, $rel, $why, 'keep', 'active', $origins, $slugs, $size ), ',', '"', '\\' );
		fclose( $fh ); // phpcs:ignore
	}

	/**
	 * Convert a slug to regex patterns with boundaries.
	 *
	 * @param string $slug Slug.
	 * @return string[]
	 */
	protected function slug_to_patterns( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return array();
		}
		// Minimum 6 chars: shorter fragments ("apg", "php", "wc"…) are too
		// generic and match unrelated paths (e.g. "php" matches every .php file).
		if ( strlen( $slug ) < 6 ) {
			return array();
		}
		$base    = preg_quote( $slug, '/' );
		$base    = str_replace( '\-', '[-_]', $base );
		$pattern = '/(^|[^a-z0-9])' . $base . '([^a-z0-9]|$)/';
		return array( $pattern );
	}

	/**
	 * Match any installed slug pattern in a path.
	 *
	 * @param string $rel Relative path.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function match_installed_slug_in_path( $rel ) {
		$rel            = strtolower( (string) $rel );
		$inactive_slugs = array();
		$inactive_orig  = array();
		foreach ( $this->installed_slug_patterns as $slug => $patterns ) {
			foreach ( $patterns as $pattern ) {
				if ( preg_match( $pattern, $rel ) ) {
					$orig_slug = $this->installed_slug_map[ $this->normalize_slug_key( $slug ) ] ?? $slug;
					if ( ! $this->heuristic_slug_has_evidence( $orig_slug, $rel ) ) {
						continue;
					}
					if ( in_array( $orig_slug, $this->active_slugs, true ) ) {
						return array(
							'status'  => 'active',
							'origins' => $this->slug_origin( $orig_slug ),
							'slugs'   => $orig_slug,
						);
					}
					if ( in_array( $orig_slug, $this->inactive_slugs, true ) ) {
						$inactive_slugs[ $orig_slug ] = true;
						$inactive_orig[ $orig_slug ]  = $this->slug_origin( $orig_slug );
					}
				}
			}
		}
		if ( ! empty( $inactive_slugs ) ) {
			return array(
				'status'  => 'inactive',
				'origins' => implode( '|', array_values( array_unique( array_filter( $inactive_orig ) ) ) ),
				'slugs'   => implode( '|', array_keys( $inactive_slugs ) ),
			);
		}
		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * If the path is not protected by 'active', mark as disposable and add info about 'inactive' projects if any.
	 *
	 * @param string $type 'dir'|'file'.
	 * @param string $rel  Path relative to wp-content.
	 * @param string $why  Reason/category.
	 * @return void
	 */
	protected function maybe_append_orphan_row( $type, $rel, $why ) {
		$entry = $this->fs_map[ $rel ] ?? array(
			'active'   => array(),
			'inactive' => array(),
		);
		/*
		 * NO MANIFEST HERE ANY MORE. It was consulted with a wp-content-relative path while its
		 * keys are wp-content/plugins/…, and this scan only lists wp-content's top level and
		 * uploads' first level — so it never answered for a single row, and the confidence gate
		 * still exempted file rows "because the manifest attributes them".
		 *
		 * The inactive verdict comes from collect_fs_map_meta_for_prefix(), never from the raw
		 * entry: that is where a shared folder, or one holding other owners, is refused.
		 */
		$prefix_meta = $this->collect_fs_map_meta_for_prefix( $rel );

		// Active mapping from scanner map.
		if ( ! empty( $entry['active'] ) ) {
			$origins = implode(
				'|',
				array_values(
					array_unique(
						array_map(
							static function ( $x ) {
								return (string) $x['origen'];
							},
							$entry['active']
						)
					)
				)
			);
			$slugs   = implode(
				'|',
				array_values(
					array_unique(
						array_map(
							static function ( $x ) {
								return (string) $x['slug'];
							},
							$entry['active']
						)
					)
				)
			);
			$this->append_active_row( $type, $rel, $why, $origins, $slugs );
			return;
		}

		if ( $this->strict_attribution ) {
			if ( ! empty( $prefix_meta['active'] ) ) {
				$summary = $this->summarize_project_meta( (array) $prefix_meta['active'] );
				$this->append_active_row( $type, $rel, $why, $summary['origins'], $summary['slugs'] );
				return;
			}
			$runtime = $this->match_runtime_fs_path( $rel );
			if ( 'active' === $runtime['status'] ) {
				$this->append_active_row( $type, $rel, $why, $runtime['origins'], $runtime['slugs'] );
				return;
			}
			if ( 'inactive' === $runtime['status'] ) {
				$this->append_inactive_row( $type, $rel, $why, $runtime['origins'], $runtime['slugs'] );
				return;
			}
			$match = $this->match_installed_slug_in_path( $rel );
			if ( 'active' === $match['status'] ) {
				$this->append_active_row( $type, $rel, $why, $match['origins'], $match['slugs'] );
				return;
			}
			if ( 'inactive' === $match['status'] ) {
				$this->append_inactive_row( $type, $rel, $why, $match['origins'], $match['slugs'] );
				return;
			}
			if ( ! empty( $prefix_meta['inactive'] ) ) {
				$summary = $this->summarize_project_meta( (array) $prefix_meta['inactive'] );
				$this->append_inactive_row( $type, $rel, $why, $summary['origins'], $summary['slugs'] );
				return;
			}
			$this->append_unattributed_row( $type, $rel, $why );
			return;
		}

		// Extra-protected path: resolve explicitly to active/inactive if possible.
		if ( $this->is_extra_protected_path( $rel ) ) {
			$match = $this->match_installed_slug_in_path( $rel );
			if ( 'active' === $match['status'] ) {
				$this->append_active_row( $type, $rel, $why, $match['origins'], $match['slugs'] );
				return;
			}
			if ( 'inactive' === $match['status'] ) {
				$this->append_inactive_row( $type, $rel, $why, $match['origins'], $match['slugs'] );
			}
			return;
		}

		if ( ! empty( $prefix_meta['active'] ) ) {
			$summary = $this->summarize_project_meta( (array) $prefix_meta['active'] );
			$this->append_active_row( $type, $rel, $why, $summary['origins'], $summary['slugs'] );
			return;
		}

		// If no explicit inactive mapping, try slug-based match against installed extensions.
		if ( empty( $prefix_meta['inactive'] ) ) {
			$match = $this->match_installed_slug_in_path( $rel );
			if ( 'active' === $match['status'] ) {
				$this->append_active_row( $type, $rel, $why, $match['origins'], $match['slugs'] );
				return;
			}
			if ( 'inactive' === $match['status'] ) {
				$this->append_inactive_row( $type, $rel, $why, $match['origins'], $match['slugs'] );
				return;
			}
			$this->append_unattributed_row( $type, $rel, $why );
			return;
		}

		$summary = $this->summarize_project_meta( (array) $prefix_meta['inactive'] );
		$this->append_inactive_row( $type, $rel, $why, $summary['origins'], $summary['slugs'] );
	}

	/**
	 * Append a row nobody could be named for: disposable, status none (Pending).
	 *
	 * @param string $type 'dir'|'file'.
	 * @param string $rel  Path relative to wp-content.
	 * @param string $why  Reason/category.
	 * @return void
	 */
	protected function append_unattributed_row( $type, $rel, $why ) {
		$target = $this->csv_tmp_path ?: WPOPT_CSV_SCAN_FS;
		$fh     = fopen( $target, 'a' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		$size = $this->get_path_size( $type, $rel );
		fputcsv( $fh, array( $type, $rel, $why, 'disposable', 'none', '', '', $size ), ',', '"', '\\' );
		fclose( $fh ); // phpcs:ignore
	}

	/**
	 * The code scan's evidence for a folder: who protects it (active) and who may be said to
	 * own it (inactive).
	 *
	 * ONE ROW OF EVIDENCE IS NOT A WHOLE SHARED FOLDER. This merged every descendant, so a
	 * single `uploads/2024/foo.log` or `cache/x/y` written by an inactive plugin made the whole
	 * year folder, or the whole `cache` that four other plugins use, that plugin's — and
	 * deletable with it. Now:
	 *  - active evidence anywhere inside protects the folder (keeping is always safe);
	 *  - inactive needs evidence that names THIS folder, on a folder that is not one of the
	 *    shared ones (folder_is_shared()), with no other owner seen inside it.
	 *
	 * @param string $rel Relative path.
	 * @return array{active:array<int,array{origen?:string,slug?:string}>,inactive:array<int,array{origen?:string,slug?:string}>}
	 */
	protected function collect_fs_map_meta_for_prefix( $rel ) {
		$rel  = $this->normalize_rel_path( $rel );
		$hits = array();
		if ( '' !== $rel ) {
			foreach ( $this->fs_map as $path => $entry ) {
				$path = $this->normalize_rel_path( $path );
				if ( $path !== $rel && 0 !== strpos( $path, $rel . '/' ) ) {
					continue;
				}
				foreach ( array( 'active', 'inactive' ) as $status ) {
					foreach ( (array) ( $entry[ $status ] ?? array() ) as $meta ) {
						$hits[] = array(
							'exact'  => ( $path === $rel ),
							'status' => $status,
							'meta'   => (array) $meta,
						);
					}
				}
			}
		}
		return self::folder_evidence_verdict( $rel, $hits );
	}

	/**
	 * Decide a folder from its evidence hits. Pure: see collect_fs_map_meta_for_prefix().
	 *
	 * @param string                                                  $rel  Folder, relative to wp-content.
	 * @param array<int,array{exact:bool,status:string,meta:array}> $hits Evidence on it and inside it.
	 * @return array{active:array<int,array>,inactive:array<int,array>}
	 */
	public static function folder_evidence_verdict( $rel, array $hits ) {
		$out    = array(
			'active'   => array(),
			'inactive' => array(),
		);
		$exact  = array();
		$owners = array();
		$inside = array();
		foreach ( $hits as $hit ) {
			$meta = (array) ( $hit['meta'] ?? array() );
			if ( 'active' === ( $hit['status'] ?? '' ) ) {
				$out['active'][] = $meta;
				continue;
			}
			$slug = strtolower( (string) ( $meta['slug'] ?? '' ) );
			if ( ! empty( $hit['exact'] ) ) {
				$exact[]        = $meta;
				$owners[ $slug ] = true;
			} else {
				$inside[ $slug ] = true;
			}
		}
		if ( ! empty( $out['active'] ) || empty( $exact ) || self::folder_is_shared( $rel ) ) {
			return $out;
		}
		foreach ( array_keys( $inside ) as $slug ) {
			if ( ! isset( $owners[ $slug ] ) ) {
				return $out; // Someone else's files are in there too.
			}
		}
		$out['inactive'] = $exact;
		return $out;
	}

	/**
	 * Folders everybody writes into, never one extension's: the uploads year folders, the
	 * network's uploads/sites, and wp-content's conventional shared names (cache, uploads…).
	 *
	 * @param string $rel Folder, relative to wp-content.
	 * @return bool
	 */
	public static function folder_is_shared( $rel ) {
		$rel  = strtolower( trim( str_replace( '\\', '/', (string) $rel ), '/' ) );
		$name = basename( $rel );
		if ( '' === $name || preg_match( '/^\d+$/', $name ) ) {
			return true;
		}
		return in_array( $name, self::generic_segments(), true ) && ( false === strpos( $rel, '/' ) || 0 === strpos( $rel, 'uploads/' ) );
	}

	/**
	 * Path segments that name a kind of content, not an extension.
	 *
	 * @return string[]
	 */
	public static function generic_segments() {
		return array( 'wp-content', 'uploads', 'sites', 'cache', 'caches', 'tmp', 'temp', 'logs', 'log', 'files', 'images', 'backup', 'backups', 'languages', 'upgrade', 'plugins', 'themes', 'mu-plugins', 'data', 'assets', 'fonts', 'css', 'js', 'export', 'exports', 'import', 'imports' );
	}

	/**
	 * Whether a top-level uploads entry is one of WordPress' shared folders.
	 *
	 * @param string $entry       Folder name in uploads/.
	 * @param string $uploads_rel This site's uploads folder, relative to wp-content.
	 * @return bool
	 */
	public static function is_shared_uploads_folder( $entry, $uploads_rel ) {
		return 1 === preg_match( '/^\d{4}$/', (string) $entry ) || ( 'sites' === (string) $entry && 'uploads' === (string) $uploads_rel );
	}

	/**
	 * Fetch runtime FS evidence map.
	 *
	 * @return array<string,array{origins:string,slugs:string}>
	 */
	protected function get_runtime_fs_map() {
		if ( ! class_exists( 'WPOPT_Runtime_Evidence' ) ) {
			return array();
		}
		$map = WPOPT_Runtime_Evidence::get_map();
		$fs  = (array) ( $map['fs'] ?? array() );
		$out = array();
		foreach ( $fs as $path => $meta ) {
			$path = $this->normalize_rel_path( (string) $path );
			if ( '' === $path ) {
				continue;
			}
			$out[ $path ] = array(
				'origins' => (string) ( $meta['origins'] ?? '' ),
				'slugs'   => (string) ( $meta['slugs'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Resolve runtime FS evidence for a path.
	 *
	 * @param string $rel Relative path.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function match_runtime_fs_path( $rel ) {
		$rel = $this->normalize_rel_path( $rel );
		if ( '' === $rel || empty( $this->runtime_fs_map ) ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}

		/*
		 * The folder itself or something inside it — never a parent. A parent's evidence
		 * (`uploads`) named every child as that plugin's, and the descendants' was merged into
		 * one verdict: folder_evidence_verdict() decides, the same as for the code scan.
		 */
		$hits = array();
		foreach ( $this->runtime_fs_map as $path => $meta ) {
			$path = $this->normalize_rel_path( (string) $path );
			if ( $path !== $rel && 0 !== strpos( $path, $rel . '/' ) ) {
				continue;
			}
			$orig = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $meta['origins'] ?? '' ) ) ) ) );
			$slgs = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $meta['slugs'] ?? '' ) ) ) ) );
			foreach ( $slgs as $i => $slug ) {
				if ( in_array( $slug, $this->active_slugs, true ) ) {
					$status = 'active';
				} elseif ( in_array( $slug, $this->inactive_slugs, true ) ) {
					$status = 'inactive';
				} else {
					continue;
				}
				$hits[] = array(
					'exact'  => ( $path === $rel ),
					'status' => $status,
					'meta'   => array(
						'origen' => (string) ( $orig[ $i ] ?? ( $orig[0] ?? '' ) ),
						'slug'   => (string) $slug,
					),
				);
			}
		}
		$verdict = self::folder_evidence_verdict( $rel, $hits );
		foreach ( array( 'active', 'inactive' ) as $status ) {
			if ( ! empty( $verdict[ $status ] ) ) {
				$summary = $this->summarize_project_meta( $verdict[ $status ] );
				return array(
					'status'  => $status,
					'origins' => $summary['origins'],
					'slugs'   => $summary['slugs'],
				);
			}
		}

		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * Validate heuristic slug matches against scanner FS evidence and code contents.
	 *
	 * @param string $slug  Candidate slug.
	 * @param string $value Path or entry being matched.
	 * @return bool
	 */
	protected function heuristic_slug_has_evidence( $slug, $value ) {
		$slug  = (string) $slug;
		$value = strtolower( (string) $value );
		$key   = $slug . '|' . $value;
		if ( isset( $this->heuristic_evidence_cache[ $key ] ) ) {
			return $this->heuristic_evidence_cache[ $key ];
		}

		if ( $this->path_has_fs_map_evidence_for_slug( $slug, $value ) ) {
			$this->heuristic_evidence_cache[ $key ] = true;
			return true;
		}

		$tokens = self::folder_evidence_tokens( $value );
		if ( empty( $tokens ) ) {
			$this->heuristic_evidence_cache[ $key ] = false;
			return false;
		}

		$has                                    = $this->search_slug_code_for_tokens( $slug, $tokens );
		$this->heuristic_evidence_cache[ $key ] = $has;
		return $has;
	}

	/**
	 * What the candidate's code must spell out for a folder to be its: the folder's own name,
	 * as a path literal. Pure.
	 *
	 * THIS CHECK ALWAYS PASSED. The tokens were the slug itself — every plugin's code contains
	 * its own name — plus every path segment of 3+ letters, `uploads` included, which every
	 * plugin that touches media spells. Now only the folder's name counts, quoted or between
	 * slashes, and a shared name (uploads, cache, a year…) proves nothing.
	 *
	 * @param string $value Path relative to wp-content, or a folder name.
	 * @return string[] Lowercased needles; empty = no evidence is possible.
	 */
	public static function folder_evidence_tokens( $value ) {
		$value = strtolower( trim( str_replace( '\\', '/', (string) $value ), '/' ) );
		$name  = basename( $value );
		if ( strlen( $name ) < 3 || self::folder_is_shared( $name ) ) {
			return array();
		}
		$out = array();
		foreach ( array( "'", '"', '/' ) as $open ) {
			foreach ( array( "'", '"', '/' ) as $close ) {
				$out[] = $open . $name . $close;
			}
		}
		return $out;
	}

	/**
	 * Check if fs_map already contains rows for a slug.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	protected function path_has_fs_map_evidence_for_slug( $slug, $value ) {
		$slug      = (string) $slug;
		$value     = $this->normalize_rel_path( (string) $value );
		$cache_key = $slug . '|' . strtolower( $value );
		if ( isset( $this->fs_map_slug_cache[ $cache_key ] ) ) {
			return $this->fs_map_slug_cache[ $cache_key ];
		}
		foreach ( $this->fs_map as $path => $entry ) {
			$path = $this->normalize_rel_path( (string) $path );
			foreach ( array( 'active', 'inactive' ) as $status_key ) {
				foreach ( (array) ( $entry[ $status_key ] ?? array() ) as $meta ) {
					if ( isset( $meta['slug'] ) && (string) $meta['slug'] === $slug && $this->path_matches_detected_candidate( $value, $path ) ) {
						$this->fs_map_slug_cache[ $cache_key ] = true;
						return true;
					}
				}
			}
		}
		$this->fs_map_slug_cache[ $cache_key ] = false;
		return false;
	}

	/**
	 * Check whether a candidate path is compatible with a known scanner path.
	 *
	 * @param string $candidate Candidate path.
	 * @param string $known_path Known scanner path.
	 * @return bool
	 */
	protected function path_matches_detected_candidate( $candidate, $known_path ) {
		$candidate  = strtolower( $this->normalize_rel_path( (string) $candidate ) );
		$known_path = strtolower( $this->normalize_rel_path( (string) $known_path ) );
		if ( '' === $candidate || '' === $known_path ) {
			return false;
		}
		if ( $candidate === $known_path ) {
			return true;
		}
		if ( 0 === strpos( $candidate, $known_path . '/' ) || 0 === strpos( $known_path, $candidate . '/' ) ) {
			return true;
		}

		$candidate_top = $this->path_top_segment( $candidate );
		$known_top     = $this->path_top_segment( $known_path );
		if ( '' !== $candidate_top && $candidate_top === $known_top ) {
			return true;
		}

		$candidate_base = basename( $candidate );
		$known_base     = basename( $known_path );
		if ( self::folder_is_shared( $candidate_base ) || self::folder_is_shared( $known_base ) ) {
			return false; // Two folders called `cache` are not the same folder.
		}
		if ( strlen( $candidate_base ) >= 4 && $candidate_base === $known_base ) {
			return true;
		}
		if ( strlen( $candidate_base ) >= 5 && 0 === strpos( $candidate_base, $known_base ) ) {
			return true;
		}
		if ( strlen( $known_base ) >= 5 && 0 === strpos( $known_base, $candidate_base ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Get the first meaningful path segment.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	protected function path_top_segment( $path ) {
		$path  = strtolower( $this->normalize_rel_path( (string) $path ) );
		$parts = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		if ( empty( $parts ) ) {
			return '';
		}
		$top = ( 'uploads' === $parts[0] && isset( $parts[1] ) ) ? $parts[1] : $parts[0];
		// A shared top (cache, a year, sites…) is everybody's: two paths under it are not
		// the same extension's for that reason.
		return self::folder_is_shared( $top ) ? '' : $top;
	}

	/**
	 * Search plugin/theme code files for any token.
	 *
	 * @param string   $slug   Slug.
	 * @param string[] $tokens Lowercased tokens.
	 * @return bool
	 */
	protected function search_slug_code_for_tokens( $slug, array $tokens ) {
		if ( empty( $tokens ) ) {
			return false;
		}

		// Memoize by slug + the (order-independent) token set. Different uploads paths for the
		// same slug usually reduce to the same token set, so this turns a repeated 600-file
		// content walk into a single read — the dominant cost on large sites.
		$sorted = $tokens;
		sort( $sorted );
		$cache_key = $slug . '|' . implode( ',', $sorted );
		if ( isset( $this->token_search_cache[ $cache_key ] ) ) {
			return $this->token_search_cache[ $cache_key ];
		}

		/*
		 * Past the time budget: NO evidence, and the run is marked partial. This used to trust
		 * the name match — "it attributes to the installed owner, so it never manufactures a
		 * false orphan" — but the owner it attributes to may be INACTIVE, and an inactive
		 * verdict is what gets offered for deletion. Not reading the code proves nothing either
		 * way; the row stays unattributed (Pending) until a run that does read it. Not cached:
		 * a later call in the same run must not inherit it.
		 */
		if ( $this->search_deadline > 0.0 && microtime( true ) > $this->search_deadline ) {
			$this->deadline_hit = true;
			return false;
		}

		$base_dirs  = array();
		$plugin_dir = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . $slug );
		$theme_dir  = wp_normalize_path( trailingslashit( get_theme_root() ) . $slug );
		if ( is_dir( $plugin_dir ) ) {
			$base_dirs[] = $plugin_dir;
		}
		if ( is_dir( $theme_dir ) ) {
			$base_dirs[] = $theme_dir;
		}
		if ( empty( $base_dirs ) ) {
			$this->token_search_cache[ $cache_key ] = false;
			return false;
		}

		$skip_dirs = array( 'vendor', 'node_modules', '.git', '.svn', 'tests', 'test', 'languages' );
		$max_files = 600;

		foreach ( $base_dirs as $base_dir ) {
			try {
				$it    = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				$files = 0;
				foreach ( $it as $info ) {
					// Heartbeat: a single big plugin/theme can exceed the watchdog timeout on
					// its own, so keep the scan marked alive while walking its files.
					if ( 0 === ( $files % 100 ) ) {
						$this->maybe_tick();
					}
					// Honour the wall-clock budget INSIDE the file loop: reading hundreds of (up
					// to 1 MB) files for one slug could otherwise overrun max_execution_time and
					// hard-fatal the worker. Past the budget: no evidence, run marked partial
					// (see above).
					if ( $this->past_deadline() ) {
						$this->deadline_hit = true;
						return false;
					}
					if ( ! $info->isFile() ) {
						continue;
					}
					$path = wp_normalize_path( $info->getPathname() );
					$rel  = ltrim( str_replace( $base_dir, '', $path ), '/' );
					if ( '' === $rel ) {
						continue;
					}
					$parts = explode( '/', $rel );
					$skip  = false;
					foreach ( $parts as $part ) {
						if ( in_array( $part, $skip_dirs, true ) ) {
							$skip = true;
							break;
						}
					}
					if ( $skip ) {
						continue;
					}
					$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					if ( '' === $ext || ! in_array( $ext, $this->heuristic_scan_ext, true ) ) {
						continue;
					}
					$size = (int) $info->getSize();
					if ( $size <= 0 || $size > 1024 * 1024 ) {
						continue;
					}
					$content = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
					if ( ! is_string( $content ) || '' === $content ) {
						continue;
					}
					$content_lc = strtolower( $content );
					foreach ( $tokens as $token ) {
						if ( false !== strpos( $content_lc, $token ) ) {
							$this->token_search_cache[ $cache_key ] = true;
							return true;
						}
					}
					++$files;
					if ( $files >= $max_files ) {
						break;
					}
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		$this->token_search_cache[ $cache_key ] = false;
		return false;
	}

	/**
	 * Get size for a file or directory relative to wp-content.
	 *
	 * @param string $type 'file'|'dir'.
	 * @param string $rel  Relative path.
	 * @return int
	 */
	protected function get_path_size( $type, $rel ) {
		$rel = ltrim( (string) $rel, '/' );
		if ( '' === $rel ) {
			return 0;
		}
		if ( isset( $this->size_cache[ $rel ] ) ) {
			return $this->size_cache[ $rel ];
		}
		$abs  = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . $rel );
		$base = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		if ( 0 !== strpos( $abs, $base ) ) {
			$this->size_cache[ $rel ] = 0;
			return 0;
		}
		$size = 0;
		if ( 'file' === $type ) {
			if ( is_file( $abs ) ) {
				$size = (int) filesize( $abs );
			}
		} elseif ( is_dir( $abs ) ) {
			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $abs, FilesystemIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY,
					RecursiveIteratorIterator::CATCH_GET_CHILD
				);
				$count    = 0;
				foreach ( $iterator as $fileinfo ) {
					// Honour the run() wall-clock budget too: a single huge orphan folder
					// (backups, caches…) summed file-by-file could otherwise overrun
					// max_execution_time and hard-fatal the worker. The size shown is then an
					// approximate lower bound, which is fine for an informational column.
					if ( 0 === ( $count % 256 ) && $this->past_deadline() ) {
						$this->deadline_hit = true;
						break;
					}
					if ( $fileinfo->isFile() ) {
						$size += (int) $fileinfo->getSize();
						// Stop early for very large directories to avoid scan stalls.
						if ( ++$count >= 4000 ) {
							break;
						}
					}
				}
			} catch ( Exception $e ) {
				$size = 0;
			}
		}
		$this->size_cache[ $rel ] = $size;
		return $size;
	}

	/**
	 * Build extra protected path prefixes (relative to wp-content) for ACTIVE slugs.
	 * Defaults cover common plugins (WooCommerce). Extend via filter.
	 *
	 * @param string[] $active_slugs Active slugs.
	 * @return string[]
	 */
	protected function build_active_fs_paths( array $active_slugs ) {
		$paths = array();

		/**
		 * Extra uploads buckets to protect when a plugin is active but whose
		 * folder name does not match its slug (so the generic per-slug rules
		 * below would miss them). Map of `owner_slug => string[] paths`.
		 * Data-driven default (WooCommerce); extend via filter, no hard-coded map.
		 *
		 * @param array<string,string[]> $buckets      Owner slug => protected paths.
		 * @param string[]               $active_slugs Active slugs.
		 */
		$owner_buckets = (array) apply_filters(
			'wpoptimizing_active_upload_buckets',
			array(
				'woocommerce' => array( 'uploads/wc-', 'uploads/woocommerce_uploads' ),
			),
			$active_slugs
		);
		foreach ( $owner_buckets as $owner => $buckets ) {
			if ( in_array( (string) $owner, $active_slugs, true ) ) {
				$paths = array_merge( $paths, (array) $buckets );
			}
		}

		// Protect common uploads buckets based on ACTIVE slugs.
		foreach ( $active_slugs as $slug ) {
			$slug = (string) $slug;
			if ( '' === $slug ) {
				continue;
			}
			$paths[] = 'uploads/' . $slug;
			$paths[] = 'uploads/' . $slug . '-';
			$paths[] = 'uploads/' . $slug . '_';

			// Strip common WooCommerce prefixes (wc-/woocommerce-) for uploads buckets.
			foreach ( array( 'wc-', 'woocommerce-' ) as $prefix ) {
				if ( 0 === strpos( $slug, $prefix ) ) {
					$short = substr( $slug, strlen( $prefix ) );
					if ( $short ) {
						$paths[] = 'uploads/' . $short;
						$paths[] = 'uploads/' . $short . '-';
						$paths[] = 'uploads/' . $short . '_';
					}
				}
			}
		}

		/**
		 * Filter to adjust extra protected path prefixes (relative to wp-content).
		 *
		 * @param string[] $paths        Current paths.
		 * @param string[] $active_slugs Active slugs.
		 */
		$paths = apply_filters( 'wpoptimizing_active_fs_paths', $paths, $active_slugs );

		// Normalize and deduplicate.
		$paths = array_values( array_unique( array_map( array( $this, 'normalize_rel_path' ), $paths ) ) );
		return $paths;
	}

	/**
	 * Check if $rel starts with any extra-protected prefix.
	 *
	 * @param string $rel Relative path.
	 * @return bool
	 */
	protected function is_extra_protected_path( $rel ) {
		foreach ( $this->extra_protected_paths as $prefix ) {
			$norm = rtrim( $prefix, '/' );
			if ( '' === $norm ) {
				continue;
			}
			$is_open_prefix = in_array( substr( $norm, -1 ), array( '-', '_' ), true );
			if ( $rel === $norm ) {
				return true;
			}
			if ( $is_open_prefix ) {
				if ( 0 === strpos( $rel, $norm ) ) {
					return true;
				}
				continue;
			}
			if ( 0 === strpos( $rel, $norm . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build active slugs list (plugins + theme).
	 *
	 * @return string[]
	 */
	protected function get_active_project_slugs() {
		$slugs = array();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active      = (array) get_option( 'active_plugins', array() );
		$network     = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		foreach ( $all_plugins as $plugin_file => $_data ) {
			$slug = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
			if ( in_array( $plugin_file, $active, true ) || in_array( $plugin_file, $network, true ) ) {
				$slugs[ $slug ] = true;
			}
		}

		// Must-use plugins: always loaded, never in active_plugins.
		if ( function_exists( 'wpoptimizing_mu_plugin_slugs' ) ) {
			$slugs += wpoptimizing_mu_plugin_slugs();
		}

		$stylesheet = get_stylesheet();
		$template   = get_template();
		if ( $stylesheet ) {
			$slugs[ $stylesheet ] = true;
		}
		if ( $template ) {
			$slugs[ $template ] = true;
		}

		return array_keys( $slugs );
	}

	/**
	 * Normalize path relative to wp-content.
	 *
	 * @param string $rel Path.
	 * @return string
	 */
	protected function normalize_rel_path( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );
		return ltrim( $rel, '/' );
	}
}
