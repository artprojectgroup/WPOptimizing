<?php
/**
 * Data & operations layer: scan/orphan CSV readers, record/file deletion, CSV pruning, cleanup execution and slug/index helpers (no presentation).
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Data_Ops_Trait {

	/**
	 * Open a scan CSV and parse its header.
	 *
	 * Shared scaffolding for read_orphans_db()/read_orphans_fs(): sniffs the
	 * delimiter, strips a UTF-8 BOM, decides whether the first line is a real
	 * header or already a data row, and builds a case-insensitive column reader.
	 *
	 * @param string   $file          CSV path.
	 * @param string[] $known_headers Lowercase tokens that mark a real header row.
	 * @return array{0:resource,1:string,2:array<string,int>,3:?array,4:callable}|false
	 *   [ handle, delimiter, header-map, first-data-row-or-null, column-reader ] or false.
	 */
	protected function open_scan_csv( $file, array $known_headers ) {
		if ( ! file_exists( $file ) ) {
			return false;
		}
		$fh = wpoptimizing_scan_read_handle( $file ); // decrypts if encrypted at rest
		if ( false === $fh ) {
			return false;
		}

		$first_line = fgets( $fh );
		if ( false === $first_line ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		$counts = array(
			','  => substr_count( $first_line, ',' ),
			';'  => substr_count( $first_line, ';' ),
			"\t" => substr_count( $first_line, "\t" ),
		);
		arsort( $counts );
		$delimiter = (string) array_key_first( $counts );
		$header    = str_getcsv( $first_line, $delimiter, '"', '\\' );
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
		}
		if ( ! is_array( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		$header_tokens    = array_map( 'strtolower', array_map( 'trim', $header ) );
		$has_known_header = (bool) array_intersect( $known_headers, $header_tokens );
		$first_row        = null;
		if ( ! $has_known_header ) {
			$first_row = $header;
			$header    = array();
		}
		$hmap = array_change_key_case( array_flip( array_map( 'strtolower', $header ) ), CASE_LOWER );

		// Reads a column supporting EN + ES legacy names.
		$col = static function ( $r, $hmap, $en, $es = null ) {
			$idx = null;
			if ( isset( $hmap[ strtolower( $en ) ] ) ) {
				$idx = $hmap[ strtolower( $en ) ];
			} elseif ( $es && isset( $hmap[ strtolower( $es ) ] ) ) {
				$idx = $hmap[ strtolower( $es ) ];
			}
			return (string) ( ( null !== $idx && isset( $r[ $idx ] ) ) ? $r[ $idx ] : '' );
		};

		return array( $fh, $delimiter, $hmap, $first_row, $col );
	}

	/**
	 * Stream the data rows of an opened scan CSV through $reader, applying the
	 * OOM-safety row cap. Closes the handle when done.
	 *
	 * Override the cap with define('WPOPT_MAX_SCAN_ROWS', N) in wp-config.php
	 * (0 = no limit). When the cap trips, $this->scan_rows_capped is set true.
	 *
	 * @param resource $fh        Open file handle (consumed and closed here).
	 * @param string   $delimiter CSV delimiter.
	 * @param ?array   $first_row First data row already read by open_scan_csv(), or null.
	 * @param callable $reader    fn(array $row):void — maps and appends a row.
	 * @return void
	 */
	protected function read_scan_csv_body( $fh, $delimiter, $first_row, callable $reader ) {
		$max_rows = defined( 'WPOPT_MAX_SCAN_ROWS' ) ? (int) WPOPT_MAX_SCAN_ROWS : 50000;

		$row_count = 0;
		if ( is_array( $first_row ) ) {
			$reader( $first_row );
			$row_count = 1;
		}
		while ( ( $r = fgetcsv( $fh, 0, $delimiter, '"', '\\' ) ) !== false ) {
			$reader( $r );
			++$row_count;
			if ( $max_rows > 0 && $row_count >= $max_rows ) {
				$this->scan_rows_capped = true;
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * Overlay-accurate DATABASE orphan count + reclaimable bytes for the "Database"
	 * overview card, so it agrees with the Orphans tab (both use the effective status,
	 * not the stored scan status). Cached briefly; the cache is busted on every deletion
	 * (see delete_bd_items) so the card reflects a cleanup immediately.
	 *
	 * @return array{orphan:int,reclaimable:int}
	 */
	public function db_orphan_overview() {
		// Computed live (cached only per-request below) so the card always matches the
		// Orphans tab. A short transient was removed because it could keep showing a stale
		// count for minutes after a cleanup.
		static $memo = null;
		if ( is_array( $memo ) ) {
			return $memo;
		}
		// MUST mirror the Orphans tab exactly: re-evaluate every row against the plugins
		// installed RIGHT NOW (overlay) before splitting. Without this the card trusts the
		// stale project_status baked into the scan CSV and over-counts rows that belong to a
		// plugin that is actually present again (reinstalled / renamed), so the card said 52
		// while the tab was nearly empty.
		$split   = $this->split_rows_by_effective_status( $this->overlay_runtime_attributions( $this->read_orphans_db() ) );
		$orphans = isset( $split['orphan'] ) && is_array( $split['orphan'] ) ? $split['orphan'] : array();
		// Never count orphans that no longer exist in the database (stale scan CSV, a
		// prune that could not rewrite it, or items deleted outside the plugin).
		$orphans = $this->filter_present_db_orphans( $orphans );
		$reclaim = 0;
		foreach ( $orphans as $r ) {
			$reclaim += (int) ( $r['size_bytes'] ?? 0 );
		}
		$memo = array(
			'orphan'      => count( $orphans ),
			'reclaimable' => $reclaim,
		);
		return $memo;
	}

	/**
	 * Filter DB-orphan rows down to those that STILL EXIST in the database, so the panel
	 * never lists or counts orphans that were already deleted — even when the scan CSV is
	 * stale (a re-scan has not run yet, or a prune could not rewrite it on this host) or
	 * the rows were removed outside the plugin. Verified in bulk (one query per type); the
	 * types we cannot cheaply verify (runtime registrations like post types, taxonomies,
	 * roles, cron, shortcodes) are kept untouched.
	 *
	 * @param array $rows Orphan rows, each with 'type' and 'value'.
	 * @return array Subset whose underlying database object still exists.
	 */
	protected function filter_present_db_orphans( array $rows ) {
		if ( empty( $rows ) ) {
			return $rows;
		}
		global $wpdb;

		$by = array();
		foreach ( $rows as $r ) {
			$t = $this->normalize_db_type( (string) ( $r['type'] ?? '' ) );
			$v = (string) ( $r['value'] ?? '' );
			if ( '' !== $t && '' !== $v ) {
				$by[ $t ][ $v ] = true;
			}
		}

		$present = array();

		// Options.
		if ( ! empty( $by['option'] ) ) {
			$vals              = array_keys( $by['option'] );
			$in                = implode( ', ', array_fill( 0, count( $vals ), '%s' ) );
			$found             = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($in)", $vals ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$present['option'] = array_fill_keys( (array) $found, true );
		}

		// Transients (stored value may be the raw name or an option-name form).
		if ( ! empty( $by['transient'] ) ) {
			$probe = array();
			foreach ( array_keys( $by['transient'] ) as $v ) {
				$probe[ $v ]                      = true;
				$probe[ '_transient_' . $v ]      = true;
				$probe[ '_site_transient_' . $v ] = true;
			}
			$vals                 = array_keys( $probe );
			$in                   = implode( ', ', array_fill( 0, count( $vals ), '%s' ) );
			$found                = array_fill_keys( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($in)", $vals ) ), true ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$present['transient'] = array();
			foreach ( array_keys( $by['transient'] ) as $v ) {
				if ( isset( $found[ $v ] ) || isset( $found[ '_transient_' . $v ] ) || isset( $found[ '_site_transient_' . $v ] ) ) {
					$present['transient'][ $v ] = true;
				}
			}
		}

		// Meta keys.
		foreach ( array(
			'post_meta' => $wpdb->postmeta,
			'user_meta' => $wpdb->usermeta,
			'term_meta' => $wpdb->termmeta,
		) as $type => $table ) {
			if ( empty( $by[ $type ] ) ) {
				continue;
			}
			$vals             = array_keys( $by[ $type ] );
			$in               = implode( ', ', array_fill( 0, count( $vals ), '%s' ) );
			$found            = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_key FROM {$table} WHERE meta_key IN ($in)", $vals ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- core meta table name; values bound with %s placeholders.
			$present[ $type ] = array_fill_keys( (array) $found, true );
		}

		// Custom tables.
		if ( ! empty( $by['table'] ) ) {
			$present['table'] = array();
			foreach ( array_keys( $by['table'] ) as $v ) {
				$name   = $wpdb->prefix . preg_replace( '/[^A-Za-z0-9_]/', '', $v );
				$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( (string) $exists === $name ) {
					$present['table'][ $v ] = true;
				}
			}
		}

		$verifiable = array( 'option', 'transient', 'post_meta', 'user_meta', 'term_meta', 'table' );
		$out        = array();
		foreach ( $rows as $r ) {
			$t = $this->normalize_db_type( (string) ( $r['type'] ?? '' ) );
			$v = (string) ( $r['value'] ?? '' );
			if ( ! in_array( $t, $verifiable, true ) || isset( $present[ $t ][ $v ] ) ) {
				$out[] = $r; // Not cheaply verifiable → keep; otherwise keep only if it still exists.
			}
		}
		return $out;
	}

	/**
	 * Filter FS-orphan rows down to files/directories that STILL EXIST on disk, so the
	 * panel never lists orphan files that were already deleted (stale scan CSV). Each row
	 * carries a path relative to wp-content; we keep only those that still resolve.
	 *
	 * @param array $rows FS-orphan rows, each with 'relative_path'.
	 * @return array Subset that still exists on disk.
	 */
	protected function filter_present_fs_orphans( array $rows ) {
		if ( empty( $rows ) ) {
			return $rows;
		}
		$base = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		$out  = array();
		foreach ( $rows as $r ) {
			$rel = (string) ( $r['relative_path'] ?? '' );
			if ( '' === $rel ) {
				$out[] = $r; // Nothing to verify → keep.
				continue;
			}
			$abs = wp_normalize_path( $base . ltrim( $rel, '/\\' ) );
			if ( 0 !== strpos( $abs, $base ) ) {
				$out[] = $r; // Outside wp-content (unexpected) → keep, conservative.
				continue;
			}
			if ( file_exists( $abs ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Read DB orphans CSV into a normalized (EN) structure.
	 *
	 * Accepts both EN and legacy ES headers and returns rows with EN keys:
	 *   type, value, suggested_status, project_status, project_origins, project_slugs, size_bytes
	 *
	 * @return array<int,array{
	 *   type:string,
	 *   value:string,
	 *   suggested_status:string,
	 *   project_status:string,
	 *   project_origins:string,
	 *   project_slugs:string,
	 *   size_bytes:string
	 * }>
	 */
	protected function read_orphans_db( $path = null ) {
		$rows          = array();
		$path          = ( null === $path ) ? WPOPT_CSV_SCAN_DB : $path;
		$known_headers = array( 'type', 'tipo', 'bucket', 'cubo', 'origin', 'origen', 'project_slug', 'proyecto_slug', 'status', 'estado', 'extracted_value', 'value', 'valor', 'project_status', 'size', 'size_bytes', 'tamano', 'tamaño', 'count_rows', 'confianza' );
		$open          = $this->open_scan_csv( $path, $known_headers );
		if ( false === $open ) {
			return $rows;
		}
		list( $fh, $delimiter, $hmap, $first_row, $col ) = $open;
		$is_impact                                       = isset( $hmap['extracted_value'] ) || isset( $hmap['project_slug'] );
		$use_index                                       = empty( $hmap ) || ( ! $is_impact && ! isset( $hmap['value'] ) && ! isset( $hmap['valor'] ) );

		$reader = function ( $row ) use ( &$rows, $use_index, $is_impact, $col, $hmap ) {
			if ( ! is_array( $row ) || empty( $row ) ) {
				return;
			}
			if ( $use_index ) {
				$legacy_remote = ( isset( $row[1] ) && in_array( strtolower( (string) $row[1] ), array( 'bd', 'archivos' ), true ) && count( $row ) >= 10 );
				if ( $legacy_remote ) {
					$rows[] = array(
						'type'             => isset( $row[7] ) ? (string) $row[7] : '',
						'value'            => isset( $row[9] ) ? (string) $row[9] : '',
						'suggested_status' => '',
						'project_status'   => '',
						'project_origins'  => isset( $row[4] ) ? (string) $row[4] : '',
						'project_slugs'    => isset( $row[2] ) ? (string) $row[2] : '',
						'size_bytes'       => '',
					);
					return;
				}
				$known_types = array(
					'post_type',
					'taxonomy',
					'role',
					'option',
					'update_option',
					'delete_option',
					'db_delta',
					'create_table',
					'image_size',
					'post_insert',
					'post_update',
					'post_meta_add',
					'post_meta_update',
					'post_meta_delete',
					'post_meta_delete_by_key',
					'user_meta_add',
					'user_meta_update',
					'user_meta_delete',
					'meta_add',
					'meta_update',
					'meta_delete',
					'transient_set',
					'transient_delete',
					'register_setting',
					'site_option',
					'site_update_option',
					'site_delete_option',
					'register_post_meta',
					'register_term_meta',
					'register_meta',
					'role_remove',
					'cap_add',
					'cap_remove',
					'register_sidebar',
					'register_widget',
					'cron_schedule',
					'cron_clear',
					'cron_unschedule',
					'shortcode',
					// Los dos: `rest_namespace` es lo que emite el escáner desde el 2026-10-09 y
					// `rest_route` lo que hay en los CSV ya escritos. Quitar el viejo dejaría de
					// reconocer la columna en los análisis anteriores.
					'rest_namespace',
					'rest_route',
					'block_type_php',
				);
				$type_idx    = null;
				$value_idx   = 4;
				if ( isset( $row[0] ) && in_array( strtolower( (string) $row[0] ), $known_types, true ) ) {
					$type_idx  = 0;
					$value_idx = 4;
				} elseif ( isset( $row[4] ) && in_array( strtolower( (string) $row[4] ), $known_types, true ) ) {
					$type_idx  = 4;
					$value_idx = 0;
				}
				$rows[] = array(
					'type'             => ( null !== $type_idx && isset( $row[ $type_idx ] ) ) ? (string) $row[ $type_idx ] : ( isset( $row[0] ) ? (string) $row[0] : '' ),
					'value'            => isset( $row[ $value_idx ] ) ? (string) $row[ $value_idx ] : '',
					'suggested_status' => '',
					'project_status'   => isset( $row[3] ) ? (string) $row[3] : '',
					'project_origins'  => isset( $row[1] ) ? (string) $row[1] : '',
					'project_slugs'    => isset( $row[2] ) ? (string) $row[2] : '',
					'size_bytes'       => '',
				);
			} elseif ( $is_impact ) {
				$rows[] = array(
					'type'             => $col( $row, $hmap, 'type', 'tipo' ),
					'value'            => $col( $row, $hmap, 'extracted_value', 'valor' ),
					'suggested_status' => '',
					'project_status'   => $col( $row, $hmap, 'status', 'estado' ),
					'project_origins'  => $col( $row, $hmap, 'origin', 'origen' ),
					'project_slugs'    => $col( $row, $hmap, 'project_slug', 'proyecto_slug' ),
					'size_bytes'       => $col( $row, $hmap, 'size_bytes', 'size' ),
					'count_rows'       => '',
				);
			} else {
				$type = $col( $row, $hmap, 'type', 'tipo' );
				if ( '' === $type ) {
					$type = $col( $row, $hmap, 'bucket', 'cubo' );
				}
				$rows[] = array(
					'type'             => $type,
					'value'            => $col( $row, $hmap, 'value', 'valor' ),
					'suggested_status' => $col( $row, $hmap, 'suggested_status', 'estado_sugerido' ),
					'project_status'   => $col( $row, $hmap, 'project_status', 'proyecto_estado' ),
					'project_origins'  => $col( $row, $hmap, 'project_origins', 'proyecto_origenes' ),
					'project_slugs'    => $col( $row, $hmap, 'project_slugs', 'proyecto_slugs' ),
					'size_bytes'       => $col( $row, $hmap, 'size_bytes', 'size' ),
					'count_rows'       => $col( $row, $hmap, 'count_rows' ),
					'confianza'        => $col( $row, $hmap, 'confianza' ),
				);
			}
		};

		$this->read_scan_csv_body( $fh, $delimiter, $first_row, $reader );

		// Defensive trim + normalize type values.
		foreach ( $rows as &$row ) {
			foreach ( $row as $k => $v ) {
				$row[ $k ] = is_string( $v ) ? trim( $v ) : $v;
			}
			if ( isset( $row['type'] ) ) {
				$row['type_filter'] = $this->normalize_db_type( (string) $row['type'] );
			}
			if ( isset( $row['size_bytes'] ) ) {
				$row['size_bytes'] = (string) $row['size_bytes'];
			}
			if ( isset( $row['count_rows'] ) ) {
				$row['count_rows'] = (string) $row['count_rows'];
			}
		}
		$rows = $this->apply_remote_db_mapping( $rows );
		return $rows;
	}

	/**
	 * Read the empty-records CSV (same schema as the DB scan CSV, produced by
	 * WPOPT_Empty_Scanner). Reuses the full flexible reader so it benefits from the same
	 * decryption, header tolerance and normalization.
	 *
	 * @return array<int,array<string,string>>
	 */
	protected function read_empty_db() {
		return $this->read_orphans_db( WPOPT_CSV_SCAN_EMPTY );
	}

	/**
	 * Enrich empty-record rows with the attribution the MAIN DB scan already computed for the
	 * same key. The empty scanner (WPOPT_Empty_Scanner) writes its rows UNATTRIBUTED on purpose
	 * and defers ownership to display time; but the runtime overlay alone does not run the
	 * scanner's prefix/KB heuristic, so an empty option like `updraft_*` showed as Pending here
	 * while the other tabs correctly attribute it to its plugin. The main scan already stores
	 * that attribution for every non-core key (empties included), so copy it over — keyed by
	 * (type, value) — BEFORE the overlay. The overlay then re-evaluates the status from the
	 * installed set and a manual rule still wins, so the Empty tab classifies identically to
	 * Pending/Orphans/Inactive/Active.
	 *
	 * @param array<int,array<string,string>> $rows Empty-record rows (from read_empty_db()).
	 * @return array<int,array<string,string>>
	 */
	protected function enrich_empty_from_main_scan( array $rows ) {
		if ( empty( $rows ) ) {
			return $rows;
		}
		$main = $this->read_orphans_db(); // Main DB scan (WPOPT_CSV_SCAN_DB), pre-overlay.
		if ( empty( $main ) ) {
			return $rows;
		}
		// Index the main scan's attributed rows by (type|value); first writer wins.
		$map = array();
		foreach ( $main as $m ) {
			if ( '' === trim( (string) ( $m['project_slugs'] ?? '' ) ) ) {
				continue; // Nothing to inherit from an unattributed main row.
			}
			$key = strtolower( trim( (string) ( $m['type'] ?? '' ) ) ) . '|' . trim( (string) ( $m['value'] ?? '' ) );
			if ( '' !== $key && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = $m;
			}
		}
		if ( empty( $map ) ) {
			return $rows;
		}
		foreach ( $rows as &$r ) {
			// Only fill rows the empty scanner left unattributed (it always does); never clobber.
			if ( '' !== trim( (string) ( $r['project_slugs'] ?? '' ) ) ) {
				continue;
			}
			$key = strtolower( trim( (string) ( $r['type'] ?? '' ) ) ) . '|' . trim( (string) ( $r['value'] ?? '' ) );
			if ( ! isset( $map[ $key ] ) ) {
				continue;
			}
			$src                   = $map[ $key ];
			$r['suggested_status'] = (string) ( $src['suggested_status'] ?? '' );
			$r['project_status']   = (string) ( $src['project_status'] ?? '' );
			$r['project_origins']  = (string) ( $src['project_origins'] ?? '' );
			$r['project_slugs']    = (string) ( $src['project_slugs'] ?? '' );
			$r['confianza']        = (string) ( $src['confianza'] ?? '' );
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Read FS orphans CSV into a normalized (EN) structure.
	 *
	 * Accepts both EN and legacy ES headers and returns rows with EN keys:
	 *   type, relative_path, reason, suggested_status, project_status, project_origins, project_slugs, size_bytes
	 *
	 * @return array<int,array{
	 *   type:string,
	 *   relative_path:string,
	 *   reason:string,
	 *   suggested_status:string,
	 *   project_status:string,
	 *   project_origins:string,
	 *   project_slugs:string,
	 *   size_bytes:string
	 * }>
	 */
	protected function read_orphans_fs() {
		$rows          = array();
		$known_headers = array( 'type', 'tipo', 'origin', 'origen', 'project_slug', 'proyecto_slug', 'status', 'estado', 'relative_file', 'relative_path', 'ruta_relativa', 'file', 'path', 'size', 'size_bytes', 'tamano', 'tamaño' );
		$open          = $this->open_scan_csv( WPOPT_CSV_SCAN_FS, $known_headers );
		if ( false === $open ) {
			return $rows;
		}
		list( $fh, $delimiter, $hmap, $first_row, $col ) = $open;
		$is_impact                                       = isset( $hmap['relative_file'] ) || isset( $hmap['project_slug'] );
		$use_index                                       = empty( $hmap ) || ( ! $is_impact && ! isset( $hmap['relative_path'] ) && ! isset( $hmap['ruta_relativa'] ) );

		$reader = function ( $row ) use ( &$rows, $use_index, $is_impact, $col, $hmap ) {
			if ( ! is_array( $row ) || empty( $row ) ) {
				return;
			}
			if ( $use_index ) {
				$legacy_remote = ( isset( $row[1] ) && in_array( strtolower( (string) $row[1] ), array( 'bd', 'archivos' ), true ) && count( $row ) >= 10 );
				if ( $legacy_remote ) {
					$rows[] = array(
						'type'             => isset( $row[7] ) ? (string) $row[7] : '',
						'relative_path'    => isset( $row[5] ) ? (string) $row[5] : '',
						'reason'           => '',
						'suggested_status' => '',
						'project_status'   => '',
						'project_origins'  => isset( $row[4] ) ? (string) $row[4] : '',
						'project_slugs'    => isset( $row[2] ) ? (string) $row[2] : '',
						'size_bytes'       => '',
					);
					return;
				}
				$path_idx = null;
				if (
					( isset( $row[0] ) && preg_match( '/[\\/]/', (string) $row[0] ) )
					|| ( isset( $row[0] ) && preg_match( '/\\.[a-z0-9]{2,6}$/i', (string) $row[0] ) )
				) {
					$path_idx = 0;
				} elseif (
					isset( $row[4] )
					&& ( preg_match( '/[\\/]/', (string) $row[4] ) || preg_match( '/\\.[a-z0-9]{2,6}$/i', (string) $row[4] ) )
				) {
					$path_idx = 4;
				}
				$rows[] = array(
					'type'             => isset( $row[0] ) ? (string) $row[0] : '',
					'relative_path'    => ( null !== $path_idx && isset( $row[ $path_idx ] ) ) ? (string) $row[ $path_idx ] : ( isset( $row[4] ) ? (string) $row[4] : '' ),
					'reason'           => '',
					'suggested_status' => '',
					'project_status'   => isset( $row[3] ) ? (string) $row[3] : '',
					'project_origins'  => isset( $row[1] ) ? (string) $row[1] : '',
					'project_slugs'    => isset( $row[2] ) ? (string) $row[2] : '',
					'size_bytes'       => '',
				);
			} elseif ( $is_impact ) {
				$rows[] = array(
					'type'             => $col( $row, $hmap, 'type', 'tipo' ),
					'relative_path'    => $col( $row, $hmap, 'relative_file', 'ruta_relativa' ),
					'reason'           => '',
					'suggested_status' => '',
					'project_status'   => $col( $row, $hmap, 'status', 'estado' ),
					'project_origins'  => $col( $row, $hmap, 'origin', 'origen' ),
					'project_slugs'    => $col( $row, $hmap, 'project_slug', 'proyecto_slug' ),
					'size_bytes'       => $col( $row, $hmap, 'size_bytes', 'size' ),
				);
			} else {
				$rows[] = array(
					'type'             => $col( $row, $hmap, 'type', 'tipo' ),
					'relative_path'    => $col( $row, $hmap, 'relative_path', 'ruta_relativa' ),
					'reason'           => $col( $row, $hmap, 'reason', 'motivo' ),
					'suggested_status' => $col( $row, $hmap, 'suggested_status', 'estado_sugerido' ),
					'project_status'   => $col( $row, $hmap, 'project_status', 'proyecto_estado' ),
					'project_origins'  => $col( $row, $hmap, 'project_origins', 'proyecto_origenes' ),
					'project_slugs'    => $col( $row, $hmap, 'project_slugs', 'proyecto_slugs' ),
					'size_bytes'       => $col( $row, $hmap, 'size_bytes', 'size' ),
				);
			}
		};

		$this->read_scan_csv_body( $fh, $delimiter, $first_row, $reader );

		foreach ( $rows as &$row ) {
			foreach ( $row as $k => $v ) {
				$row[ $k ] = is_string( $v ) ? trim( $v ) : $v;
			}
			if ( isset( $row['size_bytes'] ) ) {
				$row['size_bytes'] = (string) $row['size_bytes'];
			}
		}
		unset( $row );
		$rows = $this->apply_remote_fs_mapping( $rows );
		return $rows;
	}

	/**
	 * Most rows a single entry (a meta key, an Action Scheduler hook) may have to be deleted
	 * from the admin screen.
	 *
	 * The backup reads every row of the entry into memory before the delete, and the delete
	 * is one statement: on a news site with 3.3 M postmeta rows, one key can be hundreds of
	 * thousands of them — the request dies out of memory or holds the table for minutes.
	 * Those are refused with the reason, never with a command for the customer to run.
	 *
	 * @return int
	 */
	protected function web_delete_max_rows() {
		/**
		 * Most rows one entry may have to be deleted from the admin screen.
		 *
		 * @since 0.1.0
		 * @param int $rows Default 200,000.
		 */
		return max( 0, (int) apply_filters( 'wpoptimizing_web_delete_max_rows', 200000 ) );
	}

	/**
	 * Split the entries into those this request may delete and those too big for it.
	 *
	 * @param string[] $entries "type::value" entries the gate allowed.
	 * @return array{allowed:string[],too_big:array<string,string>} too_big: entry => reason.
	 */
	protected function split_too_big_to_delete_here( array $entries ) {
		global $wpdb;
		$out      = array(
			'allowed' => array(),
			'too_big' => array(),
		);
		$max_rows = $this->web_delete_max_rows();
		$sizes    = null;
		$tables   = array(
			'post_meta' => $wpdb->postmeta,
			'user_meta' => $wpdb->usermeta,
			'term_meta' => $wpdb->termmeta,
		);
		foreach ( $entries as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', (string) $entry, 2 ), 2, '' );
			$rows             = 0;
			$reason           = '';
			if ( $max_rows > 0 && isset( $tables[ $tipo ] ) && '' !== $valor ) {
				// meta_key is indexed: a count, not a scan.
				$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->quote_identifier( $tables[ $tipo ] ) . ' WHERE meta_key = %s', $valor ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter -- core meta table, quoted.
			} elseif ( $max_rows > 0 && 'action_scheduler' === $tipo && '' !== $valor ) {
				$as   = $wpdb->prefix . 'actionscheduler_actions';
				$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->quote_identifier( $as ) . ' WHERE hook = %s', $valor ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AS table, quoted.
			} elseif ( 'table' === $tipo && '' !== $valor ) {
				// The backup copies the whole table into memory first (see trait-backups).
				$sizes = null === $sizes ? $this->table_sizes_now() : $sizes;
				$name  = $wpdb->prefix . preg_replace( '/[^A-Za-z0-9_]/', '', $valor );
				$max   = $this->web_rebuild_max_bytes();
				if ( $max > 0 && (int) ( $sizes[ $name ] ?? 0 ) > $max ) {
					$reason = sprintf(
						/* translators: 1: table name, 2: its size, e.g. "2.7 GB". */
						__( 'The table %1$s was not deleted: it takes %2$s, too much to back up and drop from here. Your hosting provider can back it up and drop it.', 'wpoptimizing' ),
						$name,
						size_format( (int) $sizes[ $name ], 1 )
					);
				}
			}
			if ( '' === $reason && $max_rows > 0 && $rows > $max_rows ) {
				$reason = sprintf(
					/* translators: 1: entry name, 2: number of rows. */
					__( '%1$s was not deleted: it has %2$s rows, too many to back up and delete from here. Your hosting provider can back them up and delete them.', 'wpoptimizing' ),
					$valor,
					number_format_i18n( $rows )
				);
			}
			if ( '' !== $reason ) {
				$out['too_big'][ $entry ] = $reason;
			} else {
				$out['allowed'][] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Delete selected DB items.
	 *
	 * @param string[] $items           List like "type::value".
	 * @param bool     $require_in_scan Refuse entries the latest analysis does not list. Off
	 *                                  only for callers that build their own vetted list.
	 * @return void
	 */
	protected function delete_bd_items( array $items, $require_in_scan = true ) {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log(
				'delete_bd',
				array(
					'count' => count( $items ),
					'items' => array_slice( $items, 0, 50 ),
				)
			);
		}

		// Server-side gate, before anything else: the page the request came from may be stale,
		// or the ids crafted. Same rule and same engine as delete_empty_db_items().
		$gate    = $this->gate_db_items( $items, $require_in_scan );
		$refused = $gate['refused'];
		$allowed = $gate['allowed'];

		// Too big for one admin request: refused before the backup, which reads them whole.
		$big     = $this->split_too_big_to_delete_here( $allowed );
		$allowed = $big['allowed'];
		$too_big = $big['too_big'];

		// Backup-first is MANDATORY: if the backup subsystem cannot start (disk full /
		// permissions), do NOT delete anything — mirrors the filesystem path. Without
		// this, a failed backup silently fell through to irreversible deletes (DROP TABLE).
		$bk = $this->backup_db_items( $allowed, 'delete' );
		if ( is_array( $bk ) && empty( $bk['ok'] ) ) {
			// Nothing deleted in this batch: the bulk summary must not count it as done.
			$this->last_bulk_counts = array(
				'deleted' => 0,
				'kept'    => count( $items ),
			);
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log( 'delete_bd_aborted_no_backup', array( 'count' => count( $items ) ) );
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_delete',
					'msg'  => __( 'Nothing was deleted: a backup could not be created (check disk space and folder permissions). Please try again.', 'wpoptimizing' ),
					'type' => 'error',
				),
				30
			);
			return;
		}
		$tables_backed = ( is_array( $bk ) && ! empty( $bk['tables_backed'] ) ) ? (array) $bk['tables_backed'] : array();

		/**
		 * Fires before a batch of database items is deleted (backup already taken).
		 *
		 * @since 0.1.0
		 * @param string[] $items Entries in "type::value" format.
		 */
		do_action( 'wpoptimizing_before_delete_items', $allowed );

		// Entries that were intentionally NOT deleted (unsupported/inventory-only type, or
		// a destructive type without the opt-in, or refused by the gate). They must not be
		// pruned from the CSV.
		$not_deleted = array_merge( $refused, array_keys( $too_big ) );
		// Entries the delete was attempted on and that are still there afterwards.
		$failed = array();

		foreach ( $allowed as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );

			/**
			 * Filters whether a single database item may be deleted.
			 *
			 * Return false to protect an item from deletion (e.g. an add-on that
			 * knows a given option/meta key is in use). The full "type::value"
			 * entry is also provided for convenience.
			 *
			 * @since 0.1.0
			 * @param bool   $allowed Default true.
			 * @param string $tipo    Item type (option|transient|post_meta|user_meta|term_meta|cron|role|table|…).
			 * @param string $valor   Item value/name.
			 * @param string $entry   Raw "type::value" entry.
			 */
			if ( ! apply_filters( 'wpoptimizing_can_delete_item', true, (string) $tipo, (string) $valor, (string) $entry ) ) {
				$not_deleted[] = $entry; // Protected by an add-on → keep it in the CSV.
				continue;
			}

			switch ( $tipo ) {
				case 'option':
					delete_option( $valor );
					break;

				case 'transient':
					$val = (string) $valor;
					if ( '' === $val ) {
						break;
					}
					// If stored as option name, delete directly (and timeout).
					if ( 0 === strpos( $val, '_site_transient_' ) ) {
						$key = substr( $val, strlen( '_site_transient_' ) );
						if ( '' !== $key ) {
							delete_site_transient( $key );
						}
						$timeout = '_site_transient_timeout_' . $key;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $val, $timeout ) );
					} elseif ( 0 === strpos( $val, '_transient_' ) ) {
						$key = substr( $val, strlen( '_transient_' ) );
						if ( '' !== $key ) {
							delete_transient( $key );
						}
						$timeout = '_transient_timeout_' . $key;
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s)", $val, $timeout ) );
					} else {
						// Fallback: try both transient APIs and delete raw option keys.
						delete_transient( $val );
						delete_site_transient( $val );
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name IN (%s, %s, %s, %s)", $val, '_transient_' . $val, '_site_transient_' . $val, '_transient_timeout_' . $val ) );
					}
					break;

				case 'post_meta':
					if ( '' === $valor ) {
						break;
					}
					delete_metadata( 'post', 0, $valor, '', true ); // Every object's rows, and their meta cache with them.
					break;

				case 'user_meta':
					if ( '' === $valor ) {
						break;
					}
					delete_metadata( 'user', 0, $valor, '', true ); // Every object's rows, and their meta cache with them.
					break;

				case 'term_meta':
					if ( '' === $valor ) {
						break;
					}
					delete_metadata( 'term', 0, $valor, '', true ); // Every object's rows, and their meta cache with them.
					break;

				case 'cron':
					if ( '' === $valor ) {
						break;
					}
					// wp_unschedule_hook(), not wp_clear_scheduled_hook(): the latter only clears the
					// events scheduled WITHOUT arguments, and left every one with arguments in place.
					if ( function_exists( 'wp_unschedule_hook' ) ) {
						wp_unschedule_hook( $valor );
					}
					break;

				case 'action_scheduler':
					// Purge every Action Scheduler action for this hook (pending,
					// complete, failed and canceled) plus its logs. The actions are
					// in the backup taken above; the logs are trace lines, not copied.
					if ( '' === $valor ) {
						break;
					}
					// Let the AS API clear any pending actions + its in-memory caches.
					if ( function_exists( 'as_unschedule_all_actions' ) ) {
						as_unschedule_all_actions( $valor );
					}
					$as_actions = $wpdb->prefix . 'actionscheduler_actions';
					$as_logs    = $wpdb->prefix . 'actionscheduler_logs';
					$as_tbl     = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					if ( (string) $as_tbl === $as_actions ) {
						$as_ids = $wpdb->get_col( $wpdb->prepare( "SELECT action_id FROM `{$as_actions}` WHERE hook = %s", $valor ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AS table name is $wpdb->prefix + a fixed suffix; identifiers cannot be bound via prepare().
						$as_ids = array_map( 'intval', (array) $as_ids );
						if ( ! empty( $as_ids ) ) {
							$log_tbl = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_logs ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
							if ( (string) $log_tbl === $as_logs ) {
								$as_in = implode( ',', $as_ids ); // Already integer-cast above.
								// In slices: one DELETE over millions of log rows outlived the web request
								// (the WP Total Updater site, 28/10/2026).
								do {
									$as_sliced = $wpdb->query( "DELETE FROM `{$as_logs}` WHERE action_id IN ({$as_in}) LIMIT 5000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AS table name + integer-only ID list; identifiers cannot be bound via prepare().
								} while ( 5000 === $as_sliced );
								if ( false === $as_sliced ) {
									break; // A failed slice is not an empty one: the actions stay with their logs.
								}
							}
							$wpdb->query( $wpdb->prepare( "DELETE FROM `{$as_actions}` WHERE hook = %s", $valor ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AS table name is $wpdb->prefix + a fixed suffix; identifiers cannot be bound via prepare().
						}
					}
					break;

				case 'role':
					remove_role( $valor );
					break;

				case 'capability':
				case 'cap':
					// A leftover capability lives in the wp_user_roles option (backed up
					// above as a role). Remove it from every role so a re-scan no longer
					// finds it. Value is either "role:cap" or a bare cap applied site-wide.
					$cap = (string) $valor;
					if ( '' === $cap ) {
						break;
					}
					if ( false !== strpos( $cap, ':' ) ) {
						[ $role_name, $cap_only ] = array_pad( explode( ':', $cap, 2 ), 2, '' );
						$role_obj                 = ( '' !== $role_name && function_exists( 'get_role' ) ) ? get_role( $role_name ) : null;
						if ( $role_obj && '' !== $cap_only ) {
							$role_obj->remove_cap( $cap_only );
						}
					} elseif ( function_exists( 'wp_roles' ) ) {
						foreach ( array_keys( (array) wp_roles()->roles ) as $role_name ) {
							$role_obj = get_role( $role_name );
							if ( $role_obj ) {
								$role_obj->remove_cap( $cap );
							}
						}
					}
					break;

				case 'table':
					$sanitized = preg_replace( '/[^A-Za-z0-9_]/', '', $valor );
					if ( '' === $sanitized ) {
						break;
					}
					$table = $wpdb->prefix . $sanitized;
					// Irreversible: only DROP a table whose schema+data backup was actually
					// captured above. If the backup did not capture it (locked table, probe
					// failed…), skip the drop rather than lose it forever. WordPress' own tables
					// and another site's never go, whatever the request says (the gate refused
					// them already; this is the line that issues the DROP).
					if ( empty( $tables_backed[ $table ] ) || wpoptimizing_db_table_is_protected( $table ) ) {
						$not_deleted[] = $entry; // Not dropped → keep it visible.
						break;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
					break;

				case 'shortcode':
					// Surgical content cleanup: strip the shortcode (tag + its inner
					// content) from every post that uses it, leaving the rest intact.
					// Originals are in the backup taken above; restorable per post.
					$tag = trim( (string) $valor );
					if ( '' === $tag ) {
						break;
					}
					$like = '%[' . $wpdb->esc_like( $tag ) . '%';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 5000", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
					foreach ( (array) $rows as $r ) {
						$content = (string) $r['post_content'];
						if ( ! wpoptimizing_content_has_shortcode( $content, $tag ) ) {
							continue;
						}
						$clean = wpoptimizing_strip_shortcode( $content, $tag );
						if ( $clean === $content ) {
							continue;
						}
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$wpdb->update( $wpdb->posts, array( 'post_content' => $clean ), array( 'ID' => (int) $r['ID'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
						if ( function_exists( 'clean_post_cache' ) ) {
							clean_post_cache( (int) $r['ID'] );
						}
					}
					break;

				// Orphaned CPT content / orphaned taxonomy terms: opt-in only, and
				// only when the type is NOT currently registered (truly orphaned).
				// NOTE: the scanner emits both the bare type ('post_type'/'taxonomy')
				// and the content variant ('post_type_content'/'taxonomy_term'); all
				// must be handled or the row silently fails to delete and reappears.
				case 'post':
				case 'post_type':
				case 'post_type_content':
					if ( ! $this->confirm_destructive ) {
						$not_deleted[] = $entry; // Needs the "also delete content" opt-in.
						break;
					}
					$this->delete_cpt_content( (string) $valor );
					break;

				case 'taxonomy':
				case 'taxonomy_term':
					if ( ! $this->confirm_destructive ) {
						$not_deleted[] = $entry;
						break;
					}
					$this->delete_taxonomy_terms( (string) $valor );
					break;

				default:
					// Inventory-only types (e.g. rest_namespace) and anything not wired
					// to a destructive handler: there is nothing to remove from the DB.
					// Record it so it is NOT pruned from the scan CSV (which would make it
					// vanish then reappear on the next scan, looking like a broken delete).
					$not_deleted[] = $entry;
					break;
			}

			// Judge by what is left, not by what the call answered. A DROP on a table a foreign
			// key points at fails, and on a view it only warns and reports success; a cron hook
			// with arguments survived wp_clear_scheduled_hook(); a type with more posts than one
			// pass takes keeps the rest. Each of those used to be counted as deleted, left the
			// list and came back on the next scan.
			if ( ! in_array( $entry, $not_deleted, true ) && $this->db_item_still_present( (string) $tipo, (string) $valor ) ) {
				$not_deleted[] = $entry;
				$failed[]      = $entry;
			}
		}
		// Prune ONLY the items that were actually deleted, so a row that could not be
		// removed stays honestly visible instead of disappearing and coming back.
		$deleted = array_values( array_diff( $items, $not_deleted ) );
		// What this call really did, for the summary of a bulk job (run_bulk_batch()).
		$this->last_bulk_counts = array(
			'deleted' => count( $deleted ),
			'kept'    => count( $not_deleted ),
		);
		$this->prune_orphans_db_csv( $deleted );
		if ( ! empty( $failed ) ) {
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'delete_bd_still_present',
					array(
						'count' => count( $failed ),
						'items' => array_slice( $failed, 0, 50 ),
					)
				);
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_delete',
					/* translators: %d: number of entries. */
					'msg'  => sprintf( __( '%d selected entries were still there after deleting them, so they stay in the list. The backup taken before the delete is kept.', 'wpoptimizing' ), count( $failed ) ),
					'type' => 'warning',
				),
				30
			);
		} elseif ( ! empty( $too_big ) ) {
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_delete',
					'msg'  => implode( ' ', array_slice( array_values( $too_big ), 0, 3 ) ),
					'type' => 'warning',
				),
				30
			);
		} elseif ( ! empty( $refused ) ) {
			$this->set_delete_protected_flash();
		} elseif ( ! empty( $not_deleted ) ) {
			$needs_confirm = ! $this->confirm_destructive;
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_delete',
					'msg'  => $needs_confirm
						? __( 'Some selected entries were not removed: deleting orphaned post-type content or taxonomy terms needs the "also delete content" checkbox. Other entries (such as REST namespaces) are inventory only and have nothing to remove from the database.', 'wpoptimizing' )
						: __( 'Some selected entries are inventory only (such as REST namespaces) and have nothing to remove from the database, so they were left in place.', 'wpoptimizing' ),
					'type' => 'warning',
				),
				30
			);
		}
		$this->prune_backups();
		// Bust the dashboard overview caches so the "Database" and autoload cards
		// reflect the cleanup on the next page load instead of a stale snapshot.
		wpoptimizing_flush_kpi_cache();

		/**
		 * Fires after a batch of database items has been deleted.
		 *
		 * @since 0.1.0
		 * @param string[] $items Entries in "type::value" format that were processed.
		 */
		do_action( 'wpoptimizing_after_delete_items', $items );
	}

	/**
	 * Split a database delete request into what may go and what must stay.
	 *
	 * The list the request came from may be stale (a plugin activated since the page loaded) and
	 * the ids may be crafted, so the decision is taken again here, with the engine the list
	 * used: overlay_runtime_attributions() + get_effective_row_status(), refusing 'active' —
	 * exactly as delete_empty_db_items() does. A status the scan wrote down is tried both as it
	 * was and recomputed from the owners, because "inactive" in the CSV does not mean the plugin
	 * is still inactive now. On top of that: an entry the latest analysis does not list, a
	 * network-wide user meta key for a sub-site administrator, and WordPress' or another site's
	 * tables are refused too.
	 *
	 * @param string[] $items           Entries in "type::value" format.
	 * @param bool     $require_in_scan Refuse entries absent from the latest analysis.
	 * @return array{allowed:string[],refused:string[]}
	 */
	protected function gate_db_items( array $items, $require_in_scan = true ) {
		global $wpdb;
		$out  = array(
			'allowed' => array(),
			'refused' => array(),
		);
		$scan = array();
		foreach ( $this->read_orphans_db() as $r ) {
			$scan[ (string) ( $r['type'] ?? '' ) . '::' . (string) ( $r['value'] ?? '' ) ] = $r;
		}
		$probe = array();
		foreach ( $items as $entry ) {
			$entry = (string) $entry;
			if ( isset( $scan[ $entry ] ) ) {
				$row = $scan[ $entry ];
			} elseif ( $require_in_scan ) {
				$out['refused'][] = $entry; // Not in the latest analysis: nothing vouches for it.
				continue;
			} else {
				[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
				$row              = array(
					'type'  => (string) $tipo,
					'value' => (string) $valor,
				);
			}
			$row['wpopt_entry'] = $entry;
			$probe[]            = $row;
		}
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
		$can_network  = ! $is_multisite || current_user_can( 'manage_network_options' );
		foreach ( $this->overlay_runtime_attributions( $probe ) as $row ) {
			$entry = (string) ( $row['wpopt_entry'] ?? '' );
			if ( '' === $entry ) {
				continue;
			}
			$fresh = $row;
			unset( $fresh['project_status'] );
			$active = 'active' === $this->get_effective_row_status( $row ) || 'active' === $this->get_effective_row_status( $fresh );

			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$tipo             = $this->normalize_db_type( (string) $tipo );
			// usermeta is one table for the whole network: a sub-site administrator wiping a key
			// in it wipes it for every site.
			$network_only = ( 'user_meta' === $tipo && ! $can_network );
			$core_table   = ( 'table' === $tipo && wpoptimizing_db_table_is_protected( $wpdb->prefix . preg_replace( '/[^A-Za-z0-9_]/', '', (string) $valor ) ) );

			if ( $active || $network_only || $core_table ) {
				$out['refused'][] = $entry;
			} else {
				$out['allowed'][] = $entry;
			}
		}
		return $out;
	}

	/**
	 * Flash shown when a delete kept some selected entries because they are protected.
	 *
	 * @return void
	 */
	protected function set_delete_protected_flash() {
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_delete',
				'msg'  => __( 'Some selected entries were kept because they are protected here: in use by an active plugin or theme, part of WordPress or of WPOptimizing, reserved to the network administrator, or no longer in the latest analysis.', 'wpoptimizing' ),
				'type' => 'warning',
			),
			30
		);
	}

	/**
	 * Whether a database item is still there after its delete was attempted.
	 *
	 * Mirrors what each case of delete_bd_items() removes, so "deleted" means the thing is gone,
	 * not that the call returned. Types with no destructive handler answer false: those are
	 * already kept in the list by the caller.
	 *
	 * @param string $tipo  Item type.
	 * @param string $valor Item value/name.
	 * @return bool
	 */
	protected function db_item_still_present( $tipo, $valor ) {
		global $wpdb;
		if ( '' === $valor ) {
			return false;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Reading back what was just deleted; a cached answer is exactly what must not be trusted.
		switch ( $tipo ) {
			case 'option':
				return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $valor ) );

			case 'transient':
				$names = ( 0 === strpos( $valor, '_transient_' ) || 0 === strpos( $valor, '_site_transient_' ) )
					? array( $valor )
					: array( $valor, '_transient_' . $valor, '_site_transient_' . $valor );
				$in    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
				return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name IN ({$in}) LIMIT 1", $names ) ); // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only %s placeholders, one per name.

			case 'post_meta':
			case 'user_meta':
			case 'term_meta':
				$table = array(
					'post_meta' => $wpdb->postmeta,
					'user_meta' => $wpdb->usermeta,
					'term_meta' => $wpdb->termmeta,
				)[ $tipo ];
				return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT meta_key FROM %i WHERE meta_key = %s LIMIT 1', $table, $valor ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one row, by key.

			case 'cron':
				foreach ( (array) ( function_exists( '_get_cron_array' ) ? _get_cron_array() : array() ) as $hooks ) {
					if ( is_array( $hooks ) && isset( $hooks[ $valor ] ) ) {
						return true;
					}
				}
				return false;

			case 'action_scheduler':
				$as_actions = $wpdb->prefix . 'actionscheduler_actions';
				if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $as_actions ) ) ) !== $as_actions ) {
					return false;
				}
				return null !== $wpdb->get_var( $wpdb->prepare( "SELECT action_id FROM `{$as_actions}` WHERE hook = %s LIMIT 1", $valor ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prefix + fixed suffix.

			case 'role':
				return function_exists( 'wp_roles' ) && wp_roles()->is_role( $valor );

			case 'capability':
			case 'cap':
				if ( ! function_exists( 'wp_roles' ) ) {
					return false;
				}
				if ( false !== strpos( $valor, ':' ) ) {
					[ $role_name, $cap_only ] = array_pad( explode( ':', $valor, 2 ), 2, '' );
					$role_obj                 = get_role( $role_name );
					return $role_obj && '' !== $cap_only && $role_obj->has_cap( $cap_only );
				}
				foreach ( (array) wp_roles()->role_objects as $role_obj ) {
					if ( $role_obj->has_cap( $valor ) ) {
						return true;
					}
				}
				return false;

			case 'table':
				$sanitized = preg_replace( '/[^A-Za-z0-9_]/', '', $valor );
				if ( '' === $sanitized ) {
					return false;
				}
				$table = $wpdb->prefix . $sanitized;
				return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;

			case 'shortcode':
				$tag  = trim( $valor );
				$rows = $wpdb->get_col( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 5000", '%[' . $wpdb->esc_like( $tag ) . '%' ) );
				foreach ( (array) $rows as $content ) {
					if ( wpoptimizing_content_has_shortcode( (string) $content, $tag ) ) {
						return true;
					}
				}
				return false;

			case 'post':
			case 'post_type':
			case 'post_type_content':
				return null !== $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", sanitize_key( $valor ) ) );

			case 'taxonomy':
			case 'taxonomy_term':
				return null !== $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s LIMIT 1", sanitize_key( $valor ) ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return false;
	}

	/**
	 * Delete the EMPTY rows for the selected keys (Empty-records section).
	 *
	 * Critically different from delete_bd_items: it removes ONLY rows whose value is empty
	 * (per WPOPT_Empty_Scanner's exact definition), never the non-empty rows that share the
	 * same meta_key. Backup-first is mandatory and captures exactly those empty rows, so the
	 * delete is fully restorable. Rows owned by an ACTIVE extension or by WordPress core are
	 * refused here too (server-side mirror of the read-only UI rule), re-using the same
	 * attribution engine so the decision is identical to what the user saw.
	 *
	 * @param string[] $items Entries in "type::value" format (type in option|post_meta|user_meta|term_meta).
	 * @return void
	 */
	protected function delete_empty_db_items( array $items ) {
		global $wpdb;

		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log(
				'delete_empty_bd',
				array(
					'count' => count( $items ),
					'items' => array_slice( $items, 0, 50 ),
				)
			);
		}

		// Re-evaluate each item's effective status with the SAME engine the UI used, so an
		// active/core row can never be deleted even via a crafted request.
		$status_by_key = array();
		$probe_rows    = array();
		foreach ( $items as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$probe_rows[]     = array(
				'type'  => (string) $tipo,
				'value' => (string) $valor,
			);
		}
		$probe_rows = $this->overlay_runtime_attributions( $probe_rows );
		foreach ( $probe_rows as $pr ) {
			$status_by_key[ (string) ( $pr['type'] ?? '' ) . '::' . (string) ( $pr['value'] ?? '' ) ] = $this->get_effective_row_status( (array) $pr );
		}

		// Only the deletable statuses pass the server-side gate.
		$deletable = array();
		$protected = array();
		foreach ( $items as $entry ) {
			$st = $status_by_key[ $entry ] ?? 'none';
			if ( 'active' === $st ) {
				$protected[] = $entry; // Active or core → read-only, keep it.
			} else {
				$deletable[] = $entry;
			}
		}
		if ( empty( $deletable ) ) {
			if ( ! empty( $protected ) ) {
				$this->set_empty_protected_flash();
			}
			return;
		}

		// Backup-first (mandatory): capture the exact empty rows. Abort the whole batch on failure.
		$bk = $this->backup_empty_db_items( $deletable, 'delete' );
		if ( is_array( $bk ) && empty( $bk['ok'] ) ) {
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_delete',
					'msg'  => __( 'Nothing was deleted: a backup could not be created (check disk space and folder permissions). Please try again.', 'wpoptimizing' ),
					'type' => 'error',
				),
				30
			);
			return;
		}

		list( $opt_cond, $opt_params )   = WPOPT_Empty_Scanner::empty_sql_condition( 'option_value' );
		list( $meta_cond, $meta_params ) = WPOPT_Empty_Scanner::empty_sql_condition( 'meta_value' );

		$done = array();
		foreach ( $deletable as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$tipo             = $this->normalize_db_type( (string) $tipo );
			$valor            = (string) $valor;
			if ( '' === $valor ) {
				continue;
			}
			if ( ! apply_filters( 'wpoptimizing_can_delete_item', true, $tipo, $valor, $entry ) ) {
				$protected[] = $entry;
				continue;
			}

			if ( 'option' === $tipo ) {
				// Only remove it if it is STILL empty (a value written since the scan is left
				// alone). delete_option() handles autoload + object-cache invalidation.
				$opts_table = $this->quote_identifier( $wpdb->options );
				$sql        = "SELECT option_name FROM {$opts_table} WHERE option_name = %s AND {$opt_cond} LIMIT 1";
				$args       = array_merge( array( $valor ), $opt_params );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$still = $wpdb->get_var( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name via quote_identifier(); value bound with %s.
				if ( null !== $still ) {
					delete_option( $valor );
				}
				$done[] = $entry;
				continue;
			}

			$table = ( 'post_meta' === $tipo ) ? $wpdb->postmeta : ( 'user_meta' === $tipo ? $wpdb->usermeta : ( 'term_meta' === $tipo ? $wpdb->termmeta : '' ) );
			if ( '' === $table ) {
				$protected[] = $entry; // Unknown type for this view → never touch it.
				continue;
			}
			$tbl  = $this->quote_identifier( $table );
			$sql  = "DELETE FROM {$tbl} WHERE meta_key = %s AND {$meta_cond}";
			$args = array_merge( array( $valor ), $meta_params );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name via quote_identifier(); value bound with %s.
			$done[] = $entry;
		}

		$this->prune_orphans_db_csv( $done, WPOPT_CSV_SCAN_EMPTY );
		if ( ! empty( $protected ) ) {
			$this->set_empty_protected_flash();
		}
		$this->prune_backups();
		wpoptimizing_flush_kpi_cache();
	}

	/**
	 * Flash shown when some selected empty rows were left untouched because they belong to an
	 * active extension or to WordPress core (read-only in the Empty-records section).
	 *
	 * @return void
	 */
	protected function set_empty_protected_flash() {
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_delete',
				'msg'  => __( 'Some selected rows were kept: empty records owned by an active extension or by WordPress core are read-only here, as they may be empty on purpose.', 'wpoptimizing' ),
				'type' => 'warning',
			),
			30
		);
	}

	/**
	 * Delete all posts of an ORPHANED custom post type (its registration is gone),
	 * cascading to post meta, term relationships and comments via wp_delete_post().
	 * Refuses any post type that is still registered (i.e. in use) — a hard safety
	 * guard so core/active types can never be mass-deleted. Backup is taken first.
	 *
	 * @param string $post_type Post type name.
	 * @return void
	 */
	protected function delete_cpt_content( $post_type ) {
		global $wpdb;
		$post_type = sanitize_key( (string) $post_type );
		// Hard guard: only orphaned (unregistered) types. post_type_exists() is true
		// for every core/active type, so this excludes post/page/attachment/etc.
		if ( '' === $post_type || 'posts' === $post_type || post_type_exists( $post_type ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 50000", $post_type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		foreach ( (array) $ids as $id ) {
			wp_delete_post( (int) $id, true ); // force delete (no trash); cascades meta + term rels + comments.
		}
	}

	/**
	 * Delete all terms of an ORPHANED taxonomy (registration gone): term
	 * relationships, term meta, term_taxonomy rows, and the wp_terms rows that are
	 * not still used by another taxonomy. Refuses registered taxonomies. The
	 * taxonomy being unregistered means wp_delete_term() is unreliable, so we clean
	 * the tables directly. Backup is taken first.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return void
	 */
	protected function delete_taxonomy_terms( $taxonomy ) {
		global $wpdb;
		$taxonomy = sanitize_key( (string) $taxonomy );
		if ( '' === $taxonomy || taxonomy_exists( $taxonomy ) ) {
			return; // safety: only orphaned taxonomies.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term_id, term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $taxonomy ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		foreach ( (array) $rows as $row ) {
			$term_id = (int) $row['term_id'];
			$tt_id   = (int) $row['term_taxonomy_id'];
			// Relationships pointing at this term_taxonomy_id.
			$wpdb->delete( $wpdb->term_relationships, array( 'term_taxonomy_id' => $tt_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// The term_taxonomy row itself.
			$wpdb->delete( $wpdb->term_taxonomy, array( 'term_taxonomy_id' => $tt_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			// The wp_terms row + term meta, only if no other taxonomy still uses it.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$still = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d", $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
			if ( 0 === $still ) {
				$wpdb->delete( $wpdb->termmeta, array( 'term_id' => $term_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $wpdb->terms, array( 'term_id' => $term_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			}
			if ( function_exists( 'clean_term_cache' ) ) {
				clean_term_cache( $term_id, $taxonomy );
			}
		}
	}

	/**
	 * Append a data array as a JSON line to a file.
	 *
	 * The answer matters: the backup paths delete only what they wrote, and a write that failed
	 * (disk full, permissions) used to be ignored, so the delete went ahead with nothing saved.
	 *
	 * @param string              $path Absolute path to the JSONL file.
	 * @param array<string,mixed> $data Data to encode and append.
	 * @return bool Whether the whole line reached the file.
	 */
	protected function append_jsonl( $path, array $data ) {
		$line = wp_json_encode( $data );
		if ( false === $line || ! is_string( $line ) ) {
			return false;
		}
		return $this->append_text( $path, $line . "\n" );
	}

	/**
	 * Append raw text to a file.
	 *
	 * @param string $path Absolute file path.
	 * @param string $text Text to append.
	 * @return bool Whether every byte was written.
	 */
	protected function append_text( $path, $text ) {
		$text = (string) $text;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$written = @file_put_contents( $path, $text, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is reported through the return value.
		return false !== $written && strlen( $text ) === (int) $written;
	}

	/**
	 * Sum the byte size of every file under a directory (recursive). Used to
	 * show the real size of a filesystem backup. Bounded by backup pruning.
	 *
	 * @param string $dir Absolute directory path.
	 * @return int Total bytes.
	 */
	protected function dir_size( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$total = 0;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $it as $f ) {
				if ( $f->isFile() ) {
					$total += (int) $f->getSize();
				}
			}
		} catch ( Exception $e ) {
			return $total;
		}
		return $total;
	}

	protected function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $path ) {
			if ( $path->isDir() ) {
				rmdir( $path->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $path->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}

	/**
	 * Delete selected FS paths (relative to wp-content) using WP_Filesystem.
	 *
	 * @param string[] $paths "type::relative_path".
	 * @return void
	 */
	protected function delete_fs_paths( array $paths ) {
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log(
				'delete_fs',
				array(
					'count' => count( $paths ),
					'paths' => array_slice( $paths, 0, 50 ),
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return;
		}

		// Server-side gate, before the backup: the plugin's own live files (object-cache
		// database, page cache, minified assets, its data and backups) never go, and on a
		// network a sub-site administrator only reaches its own uploads folder — wp-content,
		// with every plugin, theme and every other site's uploads, is shared.
		$uploads      = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$uploads_rel  = wpoptimizing_fs_rel_from_abs( (string) ( $uploads['basedir'] ?? '' ), WP_CONTENT_DIR );
		$is_multisite = function_exists( 'is_multisite' ) && is_multisite();
		$can_network  = ! $is_multisite || current_user_can( 'manage_network_options' );
		$protected    = 0;
		$kept         = array();
		foreach ( $paths as $entry ) {
			$entry_rel = ltrim( (string) ( array_pad( explode( '::', (string) $entry, 2 ), 2, '' )[1] ), '/' );
			// An entry without "type::" leaves an empty path, which is wp-content itself.
			if ( '' === $entry_rel || wpoptimizing_fs_is_own_path( $entry_rel ) || ! wpoptimizing_fs_rel_allowed_for_blog( $entry_rel, $uploads_rel, $is_multisite, $can_network ) ) {
				++$protected;
				continue;
			}
			$kept[] = $entry;
		}
		$paths = $kept;

		// Data safety: abort the whole batch if the backup subsystem could not
		// start (filesystem unavailable) — never delete without a backup.
		$bk = $this->backup_fs_paths( $paths, 'delete' );
		if ( is_array( $bk ) && empty( $bk['ok'] ) ) {
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'delete_fs_aborted',
					array(
						'reason' => 'backup_failed',
						'count'  => count( $paths ),
					)
				);
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_backup_failed',
					'msg'  => __( 'Deletion aborted: the backup could not be created, so nothing was deleted. Check filesystem permissions.', 'wpoptimizing' ),
					'type' => 'error',
				),
				120
			);
			return;
		}

		// Backup-first, per path: only delete entries whose backup was VERIFIED (files copied
		// and existing, directories copied recursively). A folder whose copy failed is NOT in
		// this set, so it is skipped instead of being recursively removed without a backup.
		$backed  = array_flip( array_map( static fn( $r ) => ltrim( (string) $r, '/' ), (array) ( $bk['saved'] ?? array() ) ) );
		$deleted = array();
		$skipped = 0;
		$failed  = 0;

		/**
		 * Fires before a batch of filesystem paths is deleted (backup already taken).
		 *
		 * @since 0.1.0
		 * @param string[] $paths Entries in "type::relative_path" format.
		 */
		do_action( 'wpoptimizing_before_delete_paths', $paths );

		$base = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		// What realpath() answers has to be compared with a resolved base: with wp-content (or
		// uploads) behind a symlink, the resolved path never starts with the configured one, and
		// every delete was skipped without a word. A symlinked uploads folder may live outside
		// wp-content, so its own resolved path counts too, for paths under it.
		$real_content = realpath( WP_CONTENT_DIR );
		$real_base    = ( false !== $real_content ) ? wp_normalize_path( trailingslashit( $real_content ) ) : $base;
		$real_uploads = ( '' !== $uploads_rel && ! empty( $uploads['basedir'] ) ) ? realpath( (string) $uploads['basedir'] ) : false;
		$real_uploads = ( false !== $real_uploads ) ? wp_normalize_path( trailingslashit( $real_uploads ) ) : '';

		foreach ( $paths as $entry ) {
			[ $tipo, $rel ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			if ( '' === $rel ) {
				continue;
			}
			$rel = ltrim( (string) $rel, '/' );
			// Reject traversal and null-byte injection before building the path; the
			// post-normalization containment check below is the real guard, this is
			// belt-and-suspenders so a crafted entry never even reaches a fs call.
			if ( false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) ) {
				continue;
			}
			// Never delete a path we could not verifiably back up.
			if ( ! isset( $backed[ $rel ] ) ) {
				++$skipped;
				continue;
			}
			$abs = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . $rel );

			if ( 0 !== strpos( $abs, $base ) ) {
				continue;
			}

			// The string check above is not enough on its own: a SYMLINK inside wp-content
			// pointing outside it would pass, and rmdir()/delete() follow the link. Resolve
			// the real path (and, for a link, its parent) and require containment again.
			$real = realpath( $abs );
			if ( false === $real ) {
				continue; // Vanished between the scan and now.
			}
			$real   = wp_normalize_path( $real );
			$inside = 0 === strpos( $real, $real_base )
				|| ( '' !== $real_uploads && 0 === strpos( $rel, $uploads_rel . '/' ) && 0 === strpos( $real, $real_uploads ) );
			if ( ! $inside || is_link( $abs ) ) {
				// Outside wp-content, or a symlink: never delete through an indirection.
				++$skipped;
				continue;
			}

			/**
			 * Filters whether a single filesystem path may be deleted.
			 *
			 * Return false to protect a file or directory from deletion.
			 *
			 * @since 0.1.0
			 * @param bool   $allowed Default true.
			 * @param string $rel     Path relative to wp-content.
			 * @param string $tipo    Path type as classified by the scanner.
			 * @param string $abs     Absolute, normalized path.
			 */
			if ( ! apply_filters( 'wpoptimizing_can_delete_path', true, $rel, (string) $tipo, $abs ) ) {
				continue;
			}

			if ( is_dir( $abs ) ) {
				// The recursive delete follows a symlinked sub-folder and empties its target,
				// wherever it is (reproduced outside wp-content). Remove each link itself first;
				// if one will not go, the folder is not touched.
				if ( ! wpoptimizing_fs_unlink_nested_symlinks( $abs ) ) {
					++$failed;
					continue;
				}
				$wp_filesystem->rmdir( $abs, true );
			} elseif ( is_file( $abs ) ) {
				$wp_filesystem->delete( $abs );
			}
			// Judge by what is left: a file the web server cannot remove (permissions, a
			// folder with one stubborn file inside) used to count as deleted, leave the list
			// and come back on the next scan.
			clearstatcache( true, $abs );
			if ( file_exists( $abs ) ) {
				++$failed;
				continue;
			}
			$deleted[] = $entry;
		}

		// Remove only the actually-deleted paths from the current CSV; anything skipped for
		// lack of a verified backup must stay listed so the user can retry it.
		$this->prune_orphans_fs_csv( $deleted );
		wpoptimizing_flush_kpi_cache(); // The cards count file orphans too.

		if ( $failed > 0 ) {
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log( 'delete_fs_still_present', array( 'count' => $failed ) );
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_delete',
					/* translators: %d: number of items. */
					'msg'  => sprintf( __( '%d item(s) were still there after deleting them, usually because of file permissions, so they stay in the list. The backup taken before the delete is kept.', 'wpoptimizing' ), (int) $failed ),
					'type' => 'warning',
				),
				120
			);
		} elseif ( $skipped > 0 ) {
			// Tell the user if some paths were held back because their backup could not be verified.
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log( 'delete_fs_skipped', array( 'skipped' => $skipped ) );
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_backup_partial',
					/* translators: %d: number of items kept. */
					'msg'  => sprintf( __( 'Kept %d item(s) because their backup could not be verified — they were not deleted. Check filesystem permissions and retry.', 'wpoptimizing' ), (int) $skipped ),
					'type' => 'warning',
				),
				120
			);
		} elseif ( $protected > 0 ) {
			$this->set_delete_protected_flash();
		}

		/**
		 * Fires after a batch of filesystem paths has been deleted.
		 *
		 * @since 0.1.0
		 * @param string[] $paths Entries in "type::relative_path" format that were actually deleted.
		 */
		do_action( 'wpoptimizing_after_delete_paths', $deleted );
	}

	/**
	 * Remove selected DB items from the orphans CSV.
	 *
	 * @param string[] $items List like "type::value".
	 * @return void
	 */
	protected function prune_orphans_db_csv( array $items, $csv_path = null ) {
		$csv_path = ( null === $csv_path ) ? WPOPT_CSV_SCAN_DB : $csv_path;
		if ( empty( $items ) || ! file_exists( $csv_path ) ) {
			return;
		}
		$set = array();
		foreach ( $items as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$tipo             = $this->normalize_db_type( (string) $tipo );
			$valor            = (string) $valor;
			if ( '' === $tipo || '' === $valor ) {
				continue;
			}
			$set[ $tipo . '::' . $valor ] = true;
		}
		if ( empty( $set ) ) {
			return;
		}

		$tmp = wpoptimizing_atomic_tmp_path( $csv_path );
		$in  = wpoptimizing_scan_read_handle( $csv_path ); // decrypts if encrypted at rest
		if ( false === $in ) {
			return;
		}
		$out = fopen( $tmp, 'w' ); // phpcs:ignore
		if ( false === $out ) {
			fclose( $in ); // phpcs:ignore
			return;
		}
		$header = fgetcsv( $in, 0, ',', '"', '\\' );
		// Resolve the type/value columns BY NAME (mirroring read_orphans_db), so the prune
		// can never silently miss when the column order differs from type=0/value=1.
		$hmap = is_array( $header ) ? array_flip( array_map( 'strtolower', array_map( 'trim', $header ) ) ) : array();
		$ti   = $hmap['type'] ?? $hmap['tipo'] ?? $hmap['bucket'] ?? $hmap['cubo'] ?? 0;
		$vi   = $hmap['value'] ?? $hmap['valor'] ?? $hmap['extracted_value'] ?? 1;
		if ( is_array( $header ) ) {
			fputcsv( $out, $header, ',', '"', '\\' );
		}
		while ( ( $row = fgetcsv( $in, 0, ',', '"', '\\' ) ) !== false ) {
			if ( ! is_array( $row ) || count( $row ) < 2 ) {
				continue;
			}
			$type  = $this->normalize_db_type( (string) ( $row[ $ti ] ?? '' ) );
			$value = (string) ( $row[ $vi ] ?? '' );
			if ( '' !== $type && isset( $set[ $type . '::' . $value ] ) ) {
				continue;
			}
			fputcsv( $out, $row, ',', '"', '\\' );
		}
		fclose( $in ); // phpcs:ignore
		fclose( $out ); // phpcs:ignore
		$this->replace_scan_csv_atomic( $tmp, $csv_path );
	}

	/**
	 * Atomically replace a scan CSV with a freshly rebuilt temp file.
	 *
	 * Encrypts the temp file first (so it is encrypted at rest like the live one),
	 * then renames it over the destination. rename() is atomic within the same
	 * directory, so a concurrent reader always sees either the old complete file or
	 * the new one — never the empty/partial window that the previous
	 * wp_delete_file() + copy() of a multi-MB CSV left open (which made the Pending
	 * lists flash empty when a read raced a prune). On rename failure the original
	 * stays intact (the prune simply does not take effect this round).
	 *
	 * @param string $tmp  Rebuilt temp CSV path.
	 * @param string $dest Live scan CSV path to replace.
	 * @return void
	 */
	protected function replace_scan_csv_atomic( $tmp, $dest ) {
		if ( ! file_exists( $tmp ) ) {
			return;
		}
		if ( function_exists( 'wpoptimizing_scan_encrypt_file' ) ) {
			wpoptimizing_scan_encrypt_file( $tmp ); // atomic itself (.enc.tmp + rename).
		}
		@rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Remove selected FS items from the orphans CSV.
	 *
	 * @param string[] $paths List like "type::relative_path".
	 * @return void
	 */
	protected function prune_orphans_fs_csv( array $paths ) {
		if ( empty( $paths ) || ! file_exists( WPOPT_CSV_SCAN_FS ) ) {
			return;
		}
		$set = array();
		foreach ( $paths as $entry ) {
			[ $tipo, $rel ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$rel            = (string) $rel;
			if ( '' === $rel ) {
				continue;
			}
			$set[ strtolower( (string) $tipo ) . '::' . $rel ] = true;
		}
		if ( empty( $set ) ) {
			return;
		}

		$tmp = wpoptimizing_atomic_tmp_path( WPOPT_CSV_SCAN_FS );
		$in  = wpoptimizing_scan_read_handle( WPOPT_CSV_SCAN_FS ); // decrypts if encrypted at rest
		if ( false === $in ) {
			return;
		}
		$out = fopen( $tmp, 'w' ); // phpcs:ignore
		if ( false === $out ) {
			fclose( $in ); // phpcs:ignore
			return;
		}
		$header = fgetcsv( $in, 0, ',', '"', '\\' );
		if ( is_array( $header ) ) {
			fputcsv( $out, $header, ',', '"', '\\' );
		}
		while ( ( $row = fgetcsv( $in, 0, ',', '"', '\\' ) ) !== false ) {
			if ( ! is_array( $row ) || count( $row ) < 2 ) {
				continue;
			}
			$type = strtolower( (string) $row[0] );
			$rel  = (string) $row[1];
			if ( isset( $set[ $type . '::' . $rel ] ) ) {
				continue;
			}
			fputcsv( $out, $row, ',', '"', '\\' );
		}
		fclose( $in ); // phpcs:ignore
		fclose( $out ); // phpcs:ignore
		$this->replace_scan_csv_atomic( $tmp, WPOPT_CSV_SCAN_FS );
	}

	/**
	 * Run the General cleanup process now.
	 *
	 * @return void
	 */
	protected function run_general_cleanup_now() {
		$this->get_general_cleanup_service()->run( true );
		$this->record_cleanup_snapshot();
	}

	/**
	 * Deactivate plugins/themes by slug.
	 *
	 * Plugins: uses deactivate_plugins on matching active plugin files.
	 * Themes: deactivation requires switching to another installed theme.
	 *
	 * @param string[] $plugins Plugin slugs.
	 * @param string[] $themes  Theme slugs.
	 * @return void
	 */
	protected function deactivate_projects( array $plugins, array $themes ) {
		$plugins = array_values( array_unique( array_filter( array_map( 'sanitize_key', $plugins ) ) ) );
		$themes  = array_values( array_unique( array_filter( array_map( 'sanitize_key', $themes ) ) ) );

		if ( ! empty( $plugins ) && current_user_can( 'activate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$all          = function_exists( 'get_plugins' ) ? get_plugins() : array();
			$active_files = (array) get_option( 'active_plugins', array() );
			$network      = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
			$site_list    = array();
			$network_list = array();
			foreach ( $all as $plugin_file => $_data ) {
				$slug = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
				if ( ! in_array( $slug, $plugins, true ) ) {
					continue;
				}
				if ( in_array( $plugin_file, $active_files, true ) ) {
					$site_list[] = $plugin_file;
				}
				if ( in_array( $plugin_file, $network, true ) ) {
					$network_list[] = $plugin_file;
				}
			}
			/*
			 * Each list with the call that removes it. On a network this asked for the network
			 * deactivation of everything, and deactivate_plugins( $p, false, true ) SKIPS a plugin
			 * active only on this site: on multisite the button deactivated nothing at all. And a
			 * network-active plugin lives in active_sitewide_plugins, which the site call never
			 * touches — that is how the data feeder's lab kept a plugin loading after "deactivating"
			 * it, on 2026-10-12. A network-active one needs a network administrator.
			 */
			if ( ! empty( $site_list ) ) {
				deactivate_plugins( $site_list, false, false );
			}
			if ( ! empty( $network_list ) && current_user_can( 'manage_network_plugins' ) ) {
				deactivate_plugins( $network_list, false, true );
			}

			// Judge by what is left: anything still active is reported, not assumed gone.
			wp_cache_delete( 'alloptions', 'options' );
			$still = array();
			foreach ( array_unique( array_merge( $site_list, $network_list ) ) as $plugin_file ) {
				if ( in_array( $plugin_file, (array) get_option( 'active_plugins', array() ), true )
					|| ( is_multisite() && is_plugin_active_for_network( $plugin_file ) ) ) {
					$still[] = $plugin_file;
				}
			}
			if ( ! empty( $still ) ) {
				set_transient(
					'wpoptimizing_flash',
					array(
						'code' => 'wpoptimizing_deactivate',
						/* translators: %s: comma-separated plugin files. */
						'msg'  => sprintf( __( 'These plugins are still active: %s. A plugin active across the network can only be deactivated by a network administrator, from the network admin.', 'wpoptimizing' ), implode( ', ', $still ) ),
						'type' => 'warning',
					),
					30
				);
			}
		}

		if ( ! empty( $themes ) && current_user_can( 'switch_themes' ) ) {
			$current_stylesheet = get_stylesheet();
			$current_template   = get_template();
			$needs_switch       = in_array( $current_stylesheet, $themes, true ) || in_array( $current_template, $themes, true );
			if ( $needs_switch ) {
				$candidates = $this->get_all_inactive_theme_slugs();
				$fallback   = '';
				if ( defined( 'WP_DEFAULT_THEME' ) && WP_DEFAULT_THEME && in_array( WP_DEFAULT_THEME, $candidates, true ) ) {
					$fallback = WP_DEFAULT_THEME;
				} elseif ( ! empty( $candidates ) ) {
					$fallback = (string) $candidates[0];
				}
				if ( '' !== $fallback ) {
					switch_theme( $fallback );
				}
			}
		}
	}

	/**
	 * Uninstall plugins/themes by slug (delete_plugins/delete_theme).
	 *
	 * @param string[] $plugins Plugin slugs.
	 * @param string[] $themes  Theme slugs.
	 * @return array{plugins:string[],themes:string[]} Slugs whose folder is verifiably gone afterwards.
	 */
	protected function uninstall_projects( array $plugins, array $themes ) {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			$plugins = array();
		}
		if ( ! current_user_can( 'delete_themes' ) ) {
			$themes = array();
		}

		// Plugins
		if ( ! empty( $plugins ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$all             = function_exists( 'get_plugins' ) ? get_plugins() : array();
			$files_to_delete = array();

			foreach ( $all as $plugin_file => $_data ) {
				$slug = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
				if ( in_array( $slug, $plugins, true ) ) {
					// The folder (or the single file) goes into the backup first; only what was
					// copied is deleted.
					$abs = trailingslashit( WP_PLUGIN_DIR ) . ( false !== strpos( $plugin_file, '/' ) ? $slug : $plugin_file );
					if ( $this->backup_project_path( $abs ) ) {
						$files_to_delete[] = $plugin_file;
					}
				}
			}

			if ( ! empty( $files_to_delete ) ) {
				$this->with_suppressed_l10n_warnings(
					static function () use ( $files_to_delete ) {
						delete_plugins( $files_to_delete );
					}
				);
			}
		}

		// Themes
		if ( ! empty( $themes ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			$active_stylesheet = get_stylesheet();
			$active_template   = get_template();
			$themes            = array_values(
				array_filter(
					$themes,
					function ( $slug ) use ( $active_stylesheet, $active_template ) {
						return $slug !== $active_stylesheet && $slug !== $active_template
							&& $this->backup_project_path( trailingslashit( get_theme_root( $slug ) ) . $slug );
					}
				)
			);

			$this->with_suppressed_l10n_warnings(
				static function () use ( $themes, $active_stylesheet, $active_template ) {
					foreach ( $themes as $slug ) {
						if ( $slug === $active_stylesheet || $slug === $active_template ) {
							continue;
						}
						delete_theme( $slug );
					}
				}
			);
		}

		// What delete_plugins()/delete_theme() answered is not looked at: a WP_Error, a null
		// (filesystem credentials needed) or a skipped active theme all left the folder in place,
		// and the cascade deleted the data of an extension that was still installed. The folder
		// being gone is the only answer that counts.
		$gone = array(
			'plugins' => array(),
			'themes'  => array(),
		);
		foreach ( $plugins as $slug ) {
			if ( ! $this->project_still_on_disk( 'plugin', (string) $slug ) ) {
				$gone['plugins'][] = (string) $slug;
			}
		}
		foreach ( $themes as $slug ) {
			if ( ! $this->project_still_on_disk( 'theme', (string) $slug ) ) {
				$gone['themes'][] = (string) $slug;
			}
		}
		return $gone;
	}

	/**
	 * Copy a plugin or theme into the operation's backup before it is uninstalled.
	 *
	 * Only a verified, complete copy counts. One outside wp-content cannot be copied by the
	 * filesystem backup, so it is not uninstalled from here. What the extension's own
	 * uninstall routine removes from the database is its code's doing and is not in this copy.
	 *
	 * @param string $abs Absolute path of the folder (or single-file plugin).
	 * @return bool Whether it is safe to delete.
	 */
	protected function backup_project_path( $abs ) {
		$rel = wpoptimizing_fs_rel_from_abs( (string) $abs, WP_CONTENT_DIR );
		if ( '' === $rel || ! file_exists( $abs ) ) {
			wpoptimizing_backup_failure( 'read' );
			return false;
		}
		$bk = $this->backup_fs_paths( array( 'files::' . $rel ), 'uninstall' );
		if ( empty( $bk['ok'] ) || ! in_array( $rel, (array) ( $bk['saved'] ?? array() ), true ) ) {
			wpoptimizing_backup_failure( 'write' );
			return false;
		}
		return true;
	}

	/**
	 * Whether a plugin or theme is still on disk, read from the filesystem (not from a list
	 * cached earlier in the request).
	 *
	 * @param string $origin 'plugin'|'theme'.
	 * @param string $slug   Folder slug (or single-file plugin name).
	 * @return bool
	 */
	protected function project_still_on_disk( $origin, $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug || false !== strpos( $slug, '/' ) || false !== strpos( $slug, '..' ) ) {
			return true; // Not a name we can check: never treat it as gone.
		}
		clearstatcache();
		if ( 'plugin' === $origin ) {
			return file_exists( WP_PLUGIN_DIR . '/' . $slug ) || file_exists( WP_PLUGIN_DIR . '/' . $slug . '.php' );
		}
		if ( 'theme' === $origin ) {
			$root = function_exists( 'get_theme_root' ) ? (string) get_theme_root( $slug ) : WP_CONTENT_DIR . '/themes';
			return file_exists( trailingslashit( $root ) . $slug );
		}
		return true;
	}

	/**
	 * Suppress warnings for missing compiled translation files (l10n.php) during plugin/theme deletion.
	 *
	 * @param callable $callback Callback to execute.
	 * @return void
	 */
	protected function with_suppressed_l10n_warnings( callable $callback ) {
		$handler = static function ( $errno, $errstr ) {
			if ( E_WARNING !== $errno ) {
				return false;
			}
			if ( false === strpos( $errstr, 'l10n.php' ) ) {
				return false;
			}
			if ( false !== strpos( $errstr, '/wp-content/languages/plugins/' )
				|| false !== strpos( $errstr, '/wp-content/languages/themes/' ) ) {
				return true;
			}
			return false;
		};

		set_error_handler( $handler ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			$callback();
		} finally {
			restore_error_handler();
		}
	}

	/**
	 * Cascade delete: remove DB/FS items associated with the uninstalled slugs (from INACTIVE rows).
	 *
	 * Pass only the slugs uninstall_projects() reports as gone. A row goes only when it names
	 * one of them and every owner it names is gone: data shared with a plugin that is still
	 * installed used to be deleted along with the one that left.
	 *
	 * @param string[] $plugin_slugs Plugin slugs whose folder is gone.
	 * @param string[] $theme_slugs  Theme slugs whose folder is gone.
	 * @return void
	 */
	protected function cascade_delete_associated( array $plugin_slugs, array $theme_slugs ) {
		if ( empty( $plugin_slugs ) && empty( $theme_slugs ) ) {
			return;
		}

		// EN rows read from normalized CSV files.
		$db_rows = $this->read_orphans_db();
		$fs_rows = $this->read_orphans_fs();

		$still_there = function ( $origin, $slug ) {
			return $this->project_still_on_disk( $origin, $slug );
		};
		// Filter: only rows marked as INACTIVE, whose every owner is gone.
		$eligible = static function ( $r ) use ( $plugin_slugs, $theme_slugs, $still_there ) {
			if ( 'inactive' !== ( $r['project_status'] ?? $r['proyecto_estado'] ?? '' ) ) {
				return false;
			}
			return wpoptimizing_cascade_row_eligible(
				(string) ( $r['project_origins'] ?? $r['proyecto_origenes'] ?? '' ),
				(string) ( $r['project_slugs'] ?? $r['proyecto_slugs'] ?? '' ),
				$plugin_slugs,
				$theme_slugs,
				$still_there
			);
		};
		$bd       = array_values( array_filter( $db_rows, $eligible ) );
		$fs       = array_values( array_filter( $fs_rows, $eligible ) );

		// Execute deletions using EN keys.
		$this->delete_bd_items(
			array_map(
				static function ( $r ) {
					return ( $r['type'] ?? $r['tipo'] ?? '' ) . '::' . ( $r['value'] ?? $r['valor'] ?? '' );
				},
				$bd
			)
		);
		$this->delete_fs_paths(
			array_map(
				static function ( $r ) {
					return ( $r['type'] ?? $r['tipo'] ?? '' ) . '::' . ( $r['relative_path'] ?? $r['ruta_relativa'] ?? '' );
				},
				$fs
			)
		);
	}

	/**
	 * Map slug => Plugin Name.
	 *
	 * @return array<string,string>
	 */
	protected function get_all_plugins_by_slug() {
		// Cached per request: get_plugins() and the sort are called per row by
		// format_projects_nicely()/get_row_project_sort_value(); the installed
		// plugin list is stable for the request lifetime.
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
		foreach ( $all as $plugin_file => $data ) {
			$slug         = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
			$out[ $slug ] = $data['Name'] ?? $slug;
		}
		natcasesort( $out );
		$cache = $out;
		return $cache;
	}

	/**
	 * Map slug => Theme Name.
	 *
	 * @return array<string,string>
	 */
	protected function get_all_themes_by_slug() {
		// Cached per request: wp_get_themes() instantiates a WP_Theme per theme and
		// is called per row by format_projects_nicely()/get_row_project_sort_value();
		// the installed theme list is stable for the request lifetime.
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$out    = array();
		$themes = wp_get_themes();
		foreach ( $themes as $slug => $theme ) {
			$out[ $slug ] = $theme->get( 'Name' ) ?: $slug;
		}
		natcasesort( $out );
		$cache = $out;
		return $cache;
	}

	/**
	 * Return all inactive plugin slugs (installed but not active).
	 *
	 * @return string[] Slugs.
	 */
	protected function get_all_inactive_plugin_slugs() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Include network-active plugins so they are never mislabelled as inactive.
		$active_files = function_exists( 'wpoptimizing_active_plugin_files' )
			? wpoptimizing_active_plugin_files()
			: (array) get_option( 'active_plugins', array() );
		$all          = function_exists( 'get_plugins' ) ? get_plugins() : array();

		$inactive = array();
		foreach ( $all as $plugin_file => $_data ) {
			if ( in_array( $plugin_file, $active_files, true ) ) {
				continue;
			}
			$slug              = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
			$inactive[ $slug ] = true;
		}

		$cache = array_keys( $inactive );
		return $cache;
	}

	/**
	 * Return all active plugin slugs (including network-active on multisite).
	 *
	 * @return string[] Slugs.
	 */
	protected function get_all_active_plugin_slugs() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$active_files = (array) get_option( 'active_plugins', array() );
		$network      = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$all          = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active       = array();

		foreach ( $all as $plugin_file => $_data ) {
			if ( ! in_array( $plugin_file, $active_files, true ) && ! in_array( $plugin_file, $network, true ) ) {
				continue;
			}
			$slug            = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
			$active[ $slug ] = true;
		}

		$cache = array_keys( $active );
		return $cache;
	}

	/**
	 * Return all inactive theme slugs (installed but not the active stylesheet/template).
	 *
	 * @return string[] Slugs.
	 */
	protected function get_all_inactive_theme_slugs() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$themes  = wp_get_themes();
		$active1 = get_stylesheet();
		$active2 = get_template();

		$inactive = array();
		foreach ( $themes as $slug => $theme ) {
			if ( $slug === $active1 || $slug === $active2 ) {
				continue;
			}
			$inactive[ $slug ] = true;
		}

		$cache = array_keys( $inactive );
		return $cache;
	}

	/**
	 * Return all active theme slugs (stylesheet + template).
	 *
	 * @return string[] Slugs.
	 */
	protected function get_all_active_theme_slugs() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$active = array();
		$style  = get_stylesheet();
		$tmpl   = get_template();
		if ( '' !== (string) $style ) {
			$active[ $style ] = true;
		}
		if ( '' !== (string) $tmpl ) {
			$active[ $tmpl ] = true;
		}
		$cache = array_keys( $active );
		return $cache;
	}
}
