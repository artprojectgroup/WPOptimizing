<?php
/**
 * "Tables" tab: read-only inventory of every database table with engine, row
 * count, size (data + index) and recoverable overhead, plus selective table
 * optimization. Orphaned custom tables (from uninstalled plugins) are already
 * surfaced in the Orphans tab; this tab is about visibility and reclaiming
 * overhead, not deletion.
 *
 * Composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/db-autoinc.php'; // Core tables that lost AUTO_INCREMENT: the notice and its fix.
require_once dirname( __DIR__ ) . '/db-rebuild.php'; // Tables too big for a click, rebuilt by WP-Cron.

trait WPOptimizing_Tables_Trait {

	/**
	 * Collect every table with stats via SHOW TABLE STATUS.
	 * Row: {name, engine, rows, size_bytes, overhead_bytes, id}.
	 *
	 * @return array<int,array>
	 */
	protected function get_db_tables() {
		global $wpdb;
		wpoptimizing_db_live_stats();
		$status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! is_array( $status ) ) {
			return array();
		}
		$rows = array();
		foreach ( $status as $t ) {
			$name = (string) ( $t['Name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			// On multisite, SHOW TABLE STATUS returns EVERY sub-site's tables; show only
			// the current blog's (plus shared/global tables) — the same scoping the
			// scanner uses — so other sites' wp_N_* tables don't appear here.
			if ( ! $this->table_belongs_to_current_blog( $name ) ) {
				continue;
			}
			$engine = (string) ( $t['Engine'] ?? '—' );
			// OPTIMIZE only returns free space to the OS on file-based engines (MyISAM,
			// Aria). On InnoDB the Data_free is reused internally and never shrinks, so
			// it is NOT "recoverable overhead" — flag it so the UI can be honest.
			$reclaimable = in_array( strtolower( $engine ), array( 'myisam', 'aria' ), true );
			$rows[]      = array(
				'name'                 => $name,
				'engine'               => $engine,
				'rows'                 => (int) ( $t['Rows'] ?? 0 ),
				'size_bytes'           => (int) ( $t['Data_length'] ?? 0 ) + (int) ( $t['Index_length'] ?? 0 ),
				'overhead_bytes'       => (int) ( $t['Data_free'] ?? 0 ),
				'overhead_reclaimable' => $reclaimable,
				'id'                   => $name,
			);
		}
		// Biggest first.
		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['size_bytes'] <=> $a['size_bytes'];
			}
		);

		// Owner + confidence come from the SAME scanned attribution the Orphans tab
		// uses (so the two always agree), joined by the prefix-stripped table name.
		return $this->attribute_db_tables( $rows );
	}

	/**
	 * Whether a table belongs to the current blog (multisite-aware). Mirrors the
	 * scanner so other sub-sites' wp_N_* tables are not listed on the Tables tab.
	 *
	 * @param string $table Full table name.
	 * @return bool
	 */
	protected function table_belongs_to_current_blog( $table ) {
		return wpoptimizing_table_belongs_to_current_blog( $table );
	}

	/**
	 * Annotate table rows with suspected owner + effective status from the scanned
	 * attribution (read_orphans_db → effective-status split), matching the Orphans tab,
	 * falling back to a local recogniser for core / well-known plugin tables.
	 *
	 * @param array $rows Table rows from get_db_tables (before attribution).
	 * @return array
	 */
	protected function attribute_db_tables( array $rows ) {
		global $wpdb;
		if ( empty( $rows ) ) {
			return $rows;
		}
		// Reuse the split render() already computed this request (stable, no second read
		// of the encrypted scan CSV — re-reading it made the values flicker per load).
		if ( is_array( $this->bd_split_cache ) ) {
			$split = $this->bd_split_cache;
		} else {
			$split = $this->split_rows_by_effective_status( $this->overlay_runtime_attributions( $this->read_orphans_db() ) );
		}

		// Map prefix-stripped table name → [ status, owner HTML, confidence ]. The scan
		// stores table entities as type 'table' with the value already prefix-stripped.
		$attr = array();
		foreach ( array( 'orphan', 'inactive', 'active', 'none' ) as $status ) {
			foreach ( (array) ( $split[ $status ] ?? array() ) as $r ) {
				if ( 'table' !== strtolower( (string) ( $r['type'] ?? '' ) ) ) {
					continue;
				}
				$val = strtolower( trim( (string) ( $r['value'] ?? '' ) ) );
				if ( '' === $val || isset( $attr[ $val ] ) ) {
					continue;
				}
				$attr[ $val ] = array(
					'status'    => $status,
					'owner'     => $this->format_projects_nicely( $r ),
					'confianza' => (string) ( $r['confianza'] ?? '' ),
				);
			}
		}
		// No early return when the scan has no table rows: the local recogniser below still
		// knows core and the well-known families, and used to be skipped with it.
		$prefix  = strtolower( (string) $wpdb->prefix );
		$plugins = $this->get_all_plugins_by_slug();
		foreach ( $rows as &$row ) {
			$name = strtolower( (string) $row['name'] );
			$bare = ( '' !== $prefix && 0 === strpos( $name, $prefix ) ) ? substr( $name, strlen( $prefix ) ) : $name;
			$hit  = $attr[ $bare ] ?? ( $attr[ $name ] ?? null );
			// A scan row with no resolved owner (empty / "<em>-</em>") is not useful —
			// drop it so the local recogniser can still label it.
			if ( is_array( $hit ) && ( '' === (string) $hit['owner'] || '<em>-</em>' === (string) $hit['owner'] ) ) {
				$hit = null;
			}
			// Fall back to a local recogniser for WordPress core + well-known plugin
			// table families, so the obvious owners are shown even when the scanner
			// skipped them (core) or the shared KB is not present (e.g. WooCommerce).
			if ( null === $hit ) {
				$hit = $this->recognize_known_table( $bare, $plugins );
			}
			if ( null === $hit ) {
				continue;
			}
			$row['owner_html']   = $hit['owner'];
			$row['owner_status'] = $hit['status'];
			$row['confianza']    = $hit['confianza'];
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Recognise WordPress core tables and well-known plugin/library table families
	 * locally (no KB needed). Returns [ owner (pre-escaped HTML), status, confianza ]
	 * or null when the table is not a known one.
	 *
	 * @param string               $bare    Table name with the install prefix stripped (lower-case).
	 * @param array<string,string> $plugins Installed plugins (slug => display name).
	 * @return array|null
	 */
	protected function recognize_known_table( $bare, array $plugins ) {
		$bare = strtolower( (string) $bare );
		// Strip a per-sub-site "<n>_" segment (multisite) so wp_2_posts → posts.
		$core_name = preg_match( '/^[0-9]+_(.+)$/', $bare, $m ) ? $m[1] : $bare;

		$core = class_exists( 'WPOPT_WP_Core_Registry' ) ? WPOPT_WP_Core_Registry::get_core_tables() : array();
		// Multisite/network core tables (not in the single-site registry list).
		$ms_core = array(
			'blogs'            => true,
			'blogmeta'         => true,
			'blog_versions'    => true,
			'registration_log' => true,
			'signups'          => true,
			'site'             => true,
			'sitemeta'         => true,
			'sitecategories'   => true,
		);
		if ( isset( $core[ $core_name ] ) || isset( $ms_core[ $core_name ] ) ) {
			return array(
				'owner'     => '<small>' . esc_html__( 'WordPress core', 'wpoptimizing' ) . '</small>',
				'status'    => 'in_use',
				'confianza' => 'certain',
			);
		}

		// Well-known table families: prefix => [ plugin slug for install check, name ].
		$families = array(
			'woocommerce_'     => array( 'woocommerce', 'WooCommerce' ),
			'wc_'              => array( 'woocommerce', 'WooCommerce' ),
			'actionscheduler_' => array( '', 'Action Scheduler' ),
			'yoast_'           => array( 'wordpress-seo', 'Yoast SEO' ),
			'aioseo_'          => array( 'all-in-one-seo-pack', 'All in One SEO' ),
			'wpforms_'         => array( 'wpforms-lite', 'WPForms' ),
			'gf_'              => array( 'gravityforms', 'Gravity Forms' ),
			'elementor_'       => array( 'elementor', 'Elementor' ),
			'icl_'             => array( 'sitepress-multilingual-cms', 'WPML' ),
			'wpml_'            => array( 'sitepress-multilingual-cms', 'WPML' ),
		);
		foreach ( $families as $pfx => $info ) {
			if ( 0 !== strpos( $core_name, $pfx ) && 0 !== strpos( $bare, $pfx ) ) {
				continue;
			}
			$slug      = (string) $info[0];
			$installed = ( '' === $slug ) || isset( $plugins[ $slug ] );
			$display   = ( '' !== $slug && isset( $plugins[ $slug ] ) ) ? $plugins[ $slug ] : (string) $info[1];
			return array(
				'owner'     => '<small>' . esc_html__( 'Plugin', 'wpoptimizing' ) . ': ' . esc_html( $display ) . '</small>',
				'status'    => $installed ? 'in_use' : 'orphan',
				'confianza' => 'strong',
			);
		}
		return null;
	}

	/**
	 * Most bytes (data + indexes) a table may have to be rebuilt from a browser request.
	 *
	 * OPTIMIZE on InnoDB, and any ALTER … ENGINE, copy the whole table: minutes, as much free
	 * disk as the table takes, and (for the ALTER) writes blocked meanwhile. On a 2.7 GB
	 * activity-log table that is not something a click in wp-admin should start. Optimize sends
	 * those to WP-Cron (includes/db-rebuild.php); the rest are explained, never handed to the
	 * customer as a command to run.
	 *
	 * @return int Bytes.
	 */
	protected function web_rebuild_max_bytes() {
		/**
		 * Most bytes (data + indexes) of a table that Optimize / Convert to InnoDB will
		 * rebuild from the admin screen. Bigger ones are queued (Optimize) or refused (Convert).
		 *
		 * @since 0.1.0
		 * @param int $bytes Default 256 MB.
		 */
		return max( 0, (int) apply_filters( 'wpoptimizing_web_rebuild_max_bytes', 256 * MB_IN_BYTES ) );
	}

	/**
	 * Data + index bytes of every table, from one SHOW TABLE STATUS.
	 *
	 * @return array<string,int>
	 */
	protected function table_sizes_now() {
		global $wpdb;
		$sizes  = array();
		$status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( is_array( $status ) ? $status : array() as $t ) {
			$tn = (string) ( $t['Name'] ?? '' );
			if ( '' !== $tn ) {
				$sizes[ $tn ] = (int) ( $t['Data_length'] ?? 0 ) + (int) ( $t['Index_length'] ?? 0 );
			}
		}
		return $sizes;
	}

	/**
	 * Why a table must not be rebuilt from the browser, or '' when it may.
	 *
	 * @param string            $name  Table name.
	 * @param array<string,int> $sizes From table_sizes_now().
	 * @return string The reason, ready to show.
	 */
	protected function table_too_big_to_rebuild( $name, array $sizes ) {
		$max   = $this->web_rebuild_max_bytes();
		$bytes = (int) ( $sizes[ $name ] ?? 0 );
		if ( $max <= 0 || $bytes <= $max ) {
			return '';
		}
		return sprintf(
			/* translators: %s: table size, e.g. "2.7 GB". */
			__( 'not done from here: the table takes %s, and converting it would take minutes, as much free disk space, and block writes to it meanwhile. Your hosting provider can do it at a quiet time.', 'wpoptimizing' ),
			size_format( $bytes, 1 )
		);
	}

	/**
	 * Optimize a list of tables (OPTIMIZE TABLE). Only existing tables of this
	 * install's prefix scope are touched; returns the number optimized.
	 *
	 * @param string[] $names Table names.
	 * @return int
	 */
	protected function optimize_table_list( array $names ) {
		global $wpdb;
		$allowed = array_flip( (array) $wpdb->get_col( 'SHOW TABLES' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sizes   = $this->table_sizes_now();
		$done    = 0;
		$errors  = (array) get_transient( 'wpoptimizing_optimize_err' );
		$queued  = array();
		$skipped = array();
		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			if ( '' === $name || ! isset( $allowed[ $name ] ) ) {
				continue;
			}
			// Multisite: existing is not enough. SHOW TABLES lists EVERY sub-site's tables,
			// so without this a forged POST from one sub-site could OPTIMIZE another's.
			if ( ! $this->table_belongs_to_current_blog( $name ) ) {
				continue;
			}
			// Too big to rebuild from a browser request (see table_too_big_to_rebuild()). Never a
			// command for the customer to run: WP-Cron rebuilds it if that recovers something,
			// and otherwise it is skipped (includes/db-rebuild.php).
			if ( '' !== $this->table_too_big_to_rebuild( $name, $sizes ) ) {
				$info = wpoptimizing_rebuild_table_info( $name );
				if ( null !== $info && wpoptimizing_rebuild_worth( $info['bytes'], $info['free'], wpoptimizing_rebuild_min_free() ) ) {
					$queued[] = $name;
				} else {
					$skipped[] = $name;
				}
				continue;
			}
			// Identifier is validated against the live table list above; quote it.
			$quoted = '`' . str_replace( '`', '``', $name ) . '`';
			// OPTIMIZE returns a status result set (one row per table). On InnoDB it
			// reports a 'note' ("doing recreate + analyze instead") then 'status: OK',
			// which IS a successful run — only a Msg_type of 'error' (or a wpdb error)
			// is a real failure. Counting the row blindly used to report phantom
			// successes when the query was actually rejected by the host.
			$res = $wpdb->get_results( "OPTIMIZE TABLE {$quoted}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier validated against the live table list and quoted; not bindable.
			$err = (string) $wpdb->last_error;
			if ( '' === $err && is_array( $res ) ) {
				foreach ( $res as $row ) {
					if ( isset( $row['Msg_type'] ) && 'error' === strtolower( (string) $row['Msg_type'] ) ) {
						$err = (string) ( $row['Msg_text'] ?? 'error' );
					}
				}
			}
			if ( '' === $err ) {
				++$done;
			} else {
				$errors[ $name ] = $err;
			}
		}
		set_transient( 'wpoptimizing_optimize_err', $errors, HOUR_IN_SECONDS );
		if ( $queued ) {
			wpoptimizing_rebuild_enqueue( $queued );
		}
		// Gathered across the batches of one «Optimize all»; table_op_big_notes() reads them.
		$big = (array) get_transient( 'wpoptimizing_optimize_big' );
		set_transient(
			'wpoptimizing_optimize_big',
			array(
				'queued'  => array_merge( (array) ( $big['queued'] ?? array() ), $queued ),
				'skipped' => array_merge( (array) ( $big['skipped'] ?? array() ), $skipped ),
			),
			HOUR_IN_SECONDS
		);
		if ( $done > 0 && function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'tables_optimize', array( 'count' => $done ) );
		}
		return $done;
	}

	/**
	 * Names of the tables that hold reclaimable overhead right now — file-based
	 * engines (MyISAM/Aria) with Data_free. InnoDB internal free space is excluded
	 * because OPTIMIZE never returns it to the operating system. Used by the
	 * targeted "Reclaim overhead" action so it touches only the tables that benefit.
	 *
	 * @return string[]
	 */
	protected function reclaimable_overhead_table_names() {
		$names = array();
		foreach ( $this->get_db_tables() as $r ) {
			if ( ! empty( $r['overhead_reclaimable'] ) && (int) $r['overhead_bytes'] > 0 ) {
				$names[] = (string) $r['name'];
			}
		}
		return $names;
	}

	/**
	 * Convert a list of tables to the InnoDB storage engine. Only existing tables
	 * that are currently MyISAM are touched (the engine is re-checked against the
	 * live SHOW TABLE STATUS, so anything already InnoDB/other is skipped). The
	 * conversion is non-destructive: ALTER TABLE … ENGINE=InnoDB rebuilds the
	 * table in place and preserves all data. Returns the number converted.
	 *
	 * @param string[] $names Table names.
	 * @return int
	 */
	protected function convert_tables_to_innodb( array $names ) {
		global $wpdb;
		// Authoritative map of existing table => current engine.
		$engines = array();
		wpoptimizing_db_live_stats();
		$status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sizes  = array();
		if ( is_array( $status ) ) {
			foreach ( $status as $t ) {
				$tn = (string) ( $t['Name'] ?? '' );
				if ( '' !== $tn ) {
					$engines[ $tn ] = strtoupper( (string) ( $t['Engine'] ?? '' ) );
					$sizes[ $tn ]   = (int) ( $t['Data_length'] ?? 0 ) + (int) ( $t['Index_length'] ?? 0 );
				}
			}
		}
		$done   = 0;
		$errors = (array) get_transient( 'wpoptimizing_convert_err' );
		foreach ( $names as $name ) {
			$name = trim( (string) $name );
			// Only convert real, existing MyISAM tables. Skip MySQL internal/temporary
			// tables (#sql-…) that an interrupted ALTER may briefly expose.
			if ( '' === $name || '#' === $name[0] || ! isset( $engines[ $name ] ) || 'MYISAM' !== $engines[ $name ] ) {
				continue;
			}
			// Multisite: only this blog's tables (SHOW TABLE STATUS lists the whole network).
			// An ALTER rebuilds the table, so this must gate the action, not just the listing.
			if ( ! $this->table_belongs_to_current_blog( $name ) ) {
				continue;
			}
			// The ALTER copies the whole table and blocks writes to it meanwhile.
			$too_big = $this->table_too_big_to_rebuild( $name, $sizes );
			if ( '' !== $too_big ) {
				$errors[ $name ] = $too_big;
				continue;
			}
			// Identifier validated against the live table list above; quote it.
			$quoted = '`' . str_replace( '`', '``', $name ) . '`';
			// ALTER returns false (or sets wpdb->last_error) when the host rejects the
			// change (no ALTER privilege, read-only table, InnoDB disabled…). Count
			// only genuine successes and keep the reason so the UI can surface it,
			// instead of reporting a conversion that never happened.
			$result = $wpdb->query( "ALTER TABLE {$quoted} ENGINE=InnoDB" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier validated against the live table list and quoted; not bindable.
			if ( false !== $result && '' === (string) $wpdb->last_error ) {
				++$done;
			} else {
				$errors[ $name ] = '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'conversion rejected by the database', 'wpoptimizing' );
			}
		}
		set_transient( 'wpoptimizing_convert_err', $errors, HOUR_IN_SECONDS );
		if ( $done > 0 && function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'tables_convert_innodb', array( 'count' => $done ) );
		}
		return $done;
	}

	/**
	 * Build and store the result flash for a table operation (optimize / convert),
	 * surfacing any per-table database errors so a silent host rejection is visible
	 * instead of a phantom "all done".
	 *
	 * @param string               $code   Flash code (settings-error slug).
	 * @param string               $msg    Base success message (already pluralised).
	 * @param array<string,string> $errors Map of table name => error reason.
	 * @return void
	 */
	protected function set_table_op_flash( $code, $msg, array $errors ) {
		// Drop any malformed entries (empty table name or empty reason) so a stray
		// row can never surface as a bare "0: " in the notice.
		$clean = array();
		foreach ( $errors as $tn => $em ) {
			$tn = trim( (string) $tn );
			$em = trim( (string) $em );
			if ( '' === $tn || '' === $em ) {
				continue;
			}
			$clean[ $tn ] = $em;
		}
		if ( 'wpoptimizing_optimize' === $code ) {
			$msg .= $this->table_op_big_notes();
		}
		$type = 'success';
		if ( ! empty( $clean ) ) {
			$type  = 'warning';
			$shown = array_slice( $clean, 0, 3, true );
			$parts = array();
			foreach ( $shown as $tn => $em ) {
				$parts[] = $tn . ': ' . $em;
			}
			$msg .= ' ' . sprintf(
				/* translators: %d: number of tables that could not be processed. */
				_n( '%d table could not be processed', '%d tables could not be processed', count( $clean ), 'wpoptimizing' ),
				count( $clean )
			) . ' — ' . implode( '; ', $parts ) . ( count( $clean ) > count( $shown ) ? ' …' : '' );
		}
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => $code,
				'msg'  => $msg,
				'type' => $type,
			),
			30
		);
	}

	/**
	 * What happened to the tables too big to optimize from here, for the notice after
	 * «Optimize»: skipped because there was little to recover, or queued for WP-Cron.
	 *
	 * @return string Sentences to append, starting with a space, or ''.
	 */
	protected function table_op_big_notes() {
		$big = (array) get_transient( 'wpoptimizing_optimize_big' );
		delete_transient( 'wpoptimizing_optimize_big' );
		$out     = '';
		$skipped = array_values( array_unique( (array) ( $big['skipped'] ?? array() ) ) );
		$queued  = array_values( array_unique( (array) ( $big['queued'] ?? array() ) ) );
		if ( $skipped ) {
			$out .= ' ' . sprintf(
				/* translators: 1: number of tables, 2: their names. */
				_n( '%1$d large table had almost no free space to recover and was skipped: %2$s.', '%1$d large tables had almost no free space to recover and were skipped: %2$s.', count( $skipped ), 'wpoptimizing' ),
				count( $skipped ),
				implode( ', ', $skipped )
			);
		}
		if ( $queued ) {
			$out .= ' ' . sprintf(
				/* translators: 1: number of tables, 2: their names. */
				_n( '%1$d large table will be rebuilt in the background, one at a time and without stopping the site: %2$s. You can leave this page.', '%1$d large tables will be rebuilt in the background, one at a time and without stopping the site: %2$s. You can leave this page.', count( $queued ), 'wpoptimizing' ),
				count( $queued ),
				implode( ', ', $queued )
			);
		}
		return $out;
	}

	/**
	 * Tables being rebuilt by WP-Cron (see includes/db-rebuild.php), and how the last day's went.
	 *
	 * @return void
	 */
	protected function render_rebuild_notice() {
		if ( ! function_exists( 'wpoptimizing_rebuild_state' ) ) {
			return;
		}
		$s     = wpoptimizing_rebuild_state();
		$items = array();
		if ( null !== $s['running'] ) {
			$items[] = sprintf(
				/* translators: 1: table name, 2: time since it started, e.g. "5 mins". */
				__( '%1$s is being rebuilt in the background (started %2$s ago). The site keeps working meanwhile.', 'wpoptimizing' ),
				(string) ( $s['running']['table'] ?? '' ),
				human_time_diff( (int) ( $s['running']['since'] ?? time() ) )
			);
		}
		if ( $s['queue'] ) {
			$items[] = sprintf(
				/* translators: %s: table names. */
				__( 'Waiting to be rebuilt: %s.', 'wpoptimizing' ),
				implode( ', ', $s['queue'] )
			);
		}
		$failed = false;
		foreach ( $s['done'] as $d ) {
			if ( (int) ( $d['ts'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
				continue;
			}
			$table = (string) ( $d['table'] ?? '' );
			if ( empty( $d['ok'] ) ) {
				$failed  = true;
				$items[] = sprintf(
					/* translators: 1: table name, 2: reason. */
					__( '%1$s was not rebuilt: %2$s.', 'wpoptimizing' ),
					$table,
					wpoptimizing_rebuild_reason( $d )
				);
			} elseif ( isset( $d['freed'] ) ) {
				$items[] = sprintf(
					/* translators: 1: table name, 2: space recovered, e.g. "1.2 GB". */
					__( '%1$s rebuilt: %2$s recovered.', 'wpoptimizing' ),
					$table,
					size_format( (int) $d['freed'], 1 )
				);
			} else {
				$items[] = $table . ': ' . wpoptimizing_rebuild_reason( $d ) . '.';
			}
		}
		if ( ! $items ) {
			return;
		}
		echo '<div class="notice ' . ( $failed ? 'notice-warning' : 'notice-info' ) . ' inline wpopt-notice"><p><strong>' . esc_html__( 'Large tables rebuilt in the background', 'wpoptimizing' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Core tables whose id column lost its AUTO_INCREMENT (see includes/db-autoinc.php): what
	 * happens if it is left, and a button where the fix is safe to run from here.
	 *
	 * @return void
	 */
	protected function render_autoinc_notice() {
		if ( ! function_exists( 'wpoptimizing_autoinc_findings' ) ) {
			return;
		}
		$found = wpoptimizing_autoinc_findings();
		if ( empty( $found ) ) {
			return;
		}
		$max = wpoptimizing_autoinc_max_bytes();
		echo '<div class="notice notice-error inline wpopt-notice"><p><strong>' . esc_html__( 'WordPress tables without AUTO_INCREMENT', 'wpoptimizing' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Their id column no longer numbers new rows by itself, usually after a migration that copied the tables without their attributes. New rows go in with id 0 and the next one collides: saving, updating plugins or creating users and terms fails with errors that do not point here.', 'wpoptimizing' ) . '</p><ul class="ul-disc">';
		foreach ( $found as $table => $f ) {
			$why = wpoptimizing_autoinc_blocker( $f, $max );
			echo '<li><code>' . esc_html( $table ) . '</code> (' . esc_html( $f['column'] ) . ') ';
			if ( 'no_pk' === $why ) {
				echo esc_html__( '— it has also lost its primary key, so it may hold duplicate ids. Fix it with your host or a developer, from a backup; it is not changed from here.', 'wpoptimizing' );
			} elseif ( 'zero_row' === $why ) {
				echo esc_html(
					sprintf(
						/* translators: %s: column name. */
						__( '— it has a row with %s 0, and other tables and plugins point at that id. Moving it to another id would break those links, so it is not done automatically: give that row a new id, update what refers to it, and come back.', 'wpoptimizing' ),
						$f['column']
					)
				);
			} elseif ( 'too_big' === $why ) {
				echo esc_html(
					sprintf(
						/* translators: %s: table size. */
						__( '— the table takes %s, and the change rebuilds it and blocks writes to it meanwhile, so it is not done from here. Your hosting provider can do it at a quiet time.', 'wpoptimizing' ),
						size_format( $f['bytes'], 1 )
					)
				);
			} else {
				echo '<form method="post" class="wpopt-inline-form">';
				wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
				echo '<button type="submit" class="button button-small" name="wpopt_autoinc_fix" value="' . esc_attr( $table ) . '" onclick="return window.confirm(' . esc_attr( wp_json_encode( __( 'The table is rebuilt and writes to it wait until it finishes, usually a few seconds. Continue?', 'wpoptimizing' ) ) ) . ');">' . esc_html__( 'Fix', 'wpoptimizing' ) . '</button>';
				if ( $f['zero'] ) {
					echo ' <span class="description">' . esc_html__( 'Its row with id 0 moves to the next free id first; nothing refers to it.', 'wpoptimizing' ) . '</span>';
				}
				echo '</form>';
			}
			echo '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Notice listing tables that grew abnormally since the previous scan (D4). Reads the
	 * anomalies detected and stored at scan time; silent when there are none. Feeds the same
	 * data the performance report consumes, so the two always agree.
	 *
	 * @return void
	 */
	protected function render_table_growth_notice() {
		if ( ! function_exists( 'wpoptimizing_table_anomalies_get' ) ) {
			return;
		}
		$a = wpoptimizing_table_anomalies_get();
		if ( empty( $a['items'] ) ) {
			return;
		}
		echo '<div class="notice notice-warning inline wpopt-notice"><p><strong>' . esc_html__( 'Unusual table growth since the previous scan', 'wpoptimizing' ) . '</strong>';
		if ( ! empty( $a['since'] ) ) {
			echo ' <span class="wpopt-meta">' . esc_html(
				sprintf(
					/* translators: %s: date/time of the previous scan (site timezone). */
					__( '(compared with %s)', 'wpoptimizing' ),
					get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $a['since'] ), 'Y-m-d H:i' )
				)
			) . '</span>';
		}
		echo '</p><ul class="wpopt-growth-list">';
		foreach ( array_slice( $a['items'], 0, 10 ) as $it ) {
			echo '<li><code>' . esc_html( (string) $it['name'] ) . '</code> — ' . esc_html(
				sprintf(
					/* translators: 1: size added, 2: growth percent, 3: rows added. */
					__( '+%1$s (+%2$s%%), %3$s rows added', 'wpoptimizing' ),
					size_format( max( 0, (int) $it['delta_bytes'] ), 1 ),
					number_format_i18n( (float) $it['pct'], 1 ),
					number_format_i18n( max( 0, (int) $it['delta_rows'] ) )
				)
			) . '</li>';
		}
		echo '</ul><p class="description wpopt-desc-tight">' . esc_html__( 'A table ballooning between scans is often a runaway log, transient/cache table or stuck background job. Review what writes to it before it keeps growing.', 'wpoptimizing' ) . '</p></div>';
	}

	/**
	 * The tables that are worth a second look, whoever owns them.
	 *
	 * The growth notice above answers "what changed since the last scan". This answers the
	 * question nobody was asking here: what is disproportionate RIGHT NOW. A production audit
	 * found a 525 MB Gravatar cache holding half a database — 1.7 million rows for 3,468
	 * comments — and this tab said nothing, because the table belongs to an active plugin and
	 * attribution therefore called it healthy. It was healthy. It was also the database.
	 *
	 * @since 0.1.0
	 * @param array[] $rows Table rows (already attributed).
	 * @return void
	 */
	protected function render_table_size_notice( array $rows ) {
		if ( ! function_exists( 'wpoptimizing_tables_worth_reporting' ) ) {
			return;
		}
		$flagged = wpoptimizing_tables_worth_reporting( $rows );
		if ( empty( $flagged ) ) {
			return;
		}

		echo '<div class="notice notice-info inline wpopt-notice"><p><strong>'
			. esc_html__( 'Tables worth a look', 'wpoptimizing' ) . '</strong></p><ul class="wpopt-growth-list">';

		foreach ( array_slice( $flagged, 0, 8 ) as $t ) {
			$reasons = (array) $t['reasons'];
			$why     = array();
			if ( in_array( 'orphan', $reasons, true ) ) {
				$why[] = __( 'its owner is not active', 'wpoptimizing' );
			}
			if ( in_array( 'share', $reasons, true ) ) {
				$why[] = __( 'a large share of the whole database', 'wpoptimizing' );
			}
			if ( in_array( 'rows', $reasons, true ) ) {
				$why[] = __( 'a very high row count', 'wpoptimizing' );
			}

			echo '<li><code>' . esc_html( (string) $t['name'] ) . '</code> — ' . esc_html(
				sprintf(
					/* translators: 1: table size, 2: row count, 3: comma-separated reasons. */
					__( '%1$s, %2$s rows — %3$s', 'wpoptimizing' ),
					size_format( max( 0, (int) $t['size_bytes'] ), 1 ),
					number_format_i18n( max( 0, (int) $t['rows'] ) ),
					implode( ', ', $why )
				)
			) . '</li>';
		}

		echo '</ul><p class="description wpopt-desc-tight">'
			. esc_html__( 'A table can belong to a plugin that is working perfectly and still be the largest thing in your database — caches, logs and tracking tables are the usual ones. Check the plugin\'s own settings for a retention limit before deleting anything here.', 'wpoptimizing' )
			. '</p></div>';
	}

	/**
	 * Render the Tables tab.
	 *
	 * @return void
	 */
	protected function render_tables_tab() {
		// Table optimization and MyISAM -> InnoDB conversion are FREE: they are
		// non-destructive (defragment / rebuild in place, never delete data) and make
		// the free tier more attractive, in line with General cleanup also being free.
		$can_optimize = true;
		$rows         = $this->get_db_tables();

		$total_size           = 0;
		$reclaimable_overhead = 0;
		$innodb_free          = 0;
		$myisam               = array();
		foreach ( $rows as $r ) {
			$total_size += (int) $r['size_bytes'];
			if ( ! empty( $r['overhead_reclaimable'] ) ) {
				$reclaimable_overhead += (int) $r['overhead_bytes'];
			} else {
				$innodb_free += (int) $r['overhead_bytes'];
			}
			if ( 'myisam' === strtolower( (string) $r['engine'] ) ) {
				$myisam[] = (string) $r['name'];
			}
		}

		$this->render_section_header(
			__( 'Database tables', 'wpoptimizing' ),
			__( 'Every table with its engine, row count, size and recoverable overhead. Optimizing reclaims the overhead; it never deletes data. Orphaned custom tables from uninstalled plugins appear in the Orphans tab.', 'wpoptimizing' ),
			array(
				'icon' => 'editor-table',
				'doc'  => 'wpopt-doc-tables',
			)
		);

		$this->render_autoinc_notice();
		$this->render_rebuild_notice();
		$this->render_table_growth_notice();
		$this->render_table_size_notice( $rows );

		echo '<div class="wpopt-kpi-grid">';
		printf(
			'<div class="wpopt-kpi"><div class="wpopt-kpi-num is-info">%1$s</div><div class="wpopt-kpi-label">%2$s</div></div>',
			esc_html( number_format_i18n( count( $rows ) ) ),
			esc_html__( 'Tables', 'wpoptimizing' )
		);
		printf(
			'<div class="wpopt-kpi"><div class="wpopt-kpi-num is-info">%1$s</div><div class="wpopt-kpi-label">%2$s</div></div>',
			esc_html( size_format( $total_size, 1 ) ),
			esc_html__( 'Total size', 'wpoptimizing' )
		);
		printf(
			'<div class="wpopt-kpi"><div class="wpopt-kpi-num is-%1$s">%2$s</div><div class="wpopt-kpi-label">%3$s</div></div>',
			esc_attr( $reclaimable_overhead > 0 ? 'warn' : 'info' ),
			esc_html( size_format( $reclaimable_overhead, 1 ) ),
			esc_html__( 'Recoverable overhead', 'wpoptimizing' )
		);
		if ( ! empty( $myisam ) ) {
			printf(
				'<div class="wpopt-kpi"><div class="wpopt-kpi-num is-warn">%1$s</div><div class="wpopt-kpi-label">%2$s</div></div>',
				esc_html( number_format_i18n( count( $myisam ) ) ),
				esc_html__( 'MyISAM tables', 'wpoptimizing' )
			);
		}
		echo '</div>';

		// Be honest about InnoDB: its free space is reused internally and OPTIMIZE never
		// returns it to the OS, so it is NOT counted as recoverable above. Show it once,
		// as information, so a large figure here is not mistaken for a cleanup failure.
		if ( $innodb_free > 0 ) {
			printf(
				'<p class="description wpopt-desc-tight">%s</p>',
				esc_html(
					sprintf(
					/* translators: %s: human-readable size of InnoDB internal free space. */
						__( 'InnoDB tables also hold %s of internal free space. InnoDB reuses it for new rows, so OPTIMIZE does not hand it back to the operating system — that is normal and not recoverable.', 'wpoptimizing' ),
						size_format( $innodb_free, 1 )
					)
				)
			);
		}

		// Targeted one-click action: optimize ONLY the tables that actually carry
		// reclaimable overhead, without hunting for them in the list or running
		// OPTIMIZE across every table. Non-destructive; the set is recomputed
		// server-side when the form is submitted.
		if ( $can_optimize && $reclaimable_overhead > 0 ) {
			$nonce = wp_create_nonce( $this->nonce_action );
			echo '<form method="post" class="wpopt-btn-row">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="tab" value="tables" />';
			printf(
				'<button type="submit" name="wpopt_reclaim_overhead" value="1" class="button button-primary">%s</button>',
				esc_html(
					sprintf(
						/* translators: %s: human-readable size of recoverable overhead. */
						__( 'Reclaim %s of overhead now', 'wpoptimizing' ),
						size_format( $reclaimable_overhead, 1 )
					)
				)
			);
			echo ' <span class="description">' . esc_html__( 'Optimizes only the tables with reclaimable overhead. Non-destructive — no data is deleted.', 'wpoptimizing' ) . '</span>';
			echo '</form>';
		}

		// Param name must match the list table scope ('optimize_tables'); the per-page
		// control is rendered as name="optimize_tables_pp". Reading tables_pp made the
		// selector a no-op (always reverted to 50).
		$pp = isset( $_GET['optimize_tables_pp'] ) ? max( 25, (int) $_GET['optimize_tables_pp'] ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Second filter: by attribution status, to isolate candidate orphan tables at a
		// glance. Reuses the generic second-filter control (named "{scope}_ext"). KPIs
		// above are computed from the full set, so totals stay accurate; only the listed
		// rows are filtered.
		$status_choices = array(
			'orphan'       => __( 'Orphaned only', 'wpoptimizing' ),
			'in_use'       => __( 'In use', 'wpoptimizing' ),
			'unattributed' => __( 'Unattributed', 'wpoptimizing' ),
		);
		$status_filter  = '';
		if ( isset( $_GET['optimize_tables_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$status_filter = sanitize_key( wp_unslash( $_GET['optimize_tables_ext'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $status_choices[ $status_filter ] ) ) {
				$status_filter = '';
			}
		}
		if ( '' !== $status_filter ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $status_filter ) {
						$st = (string) ( $r['owner_status'] ?? '' );
						if ( 'unattributed' === $status_filter ) {
							return '' === $st || 'none' === $st;
						}
						// The scan says active/inactive, the local recogniser in_use: all "in use".
						if ( 'in_use' === $status_filter ) {
							return in_array( $st, array( 'in_use', 'active', 'inactive' ), true );
						}
						return $st === $status_filter;
					}
				)
			);
		}

		$table = new WPOptimizing_Tables_List_Table(
			$this,
			$rows,
			false,
			false,
			array(
				'scope'             => 'optimize_tables',
				'per_page'          => $pp,
				'can_optimize'      => $can_optimize,
				'project_filter'    => $status_filter,
				'project_choices'   => $status_choices,
				'project_all_label' => __( 'All tables', 'wpoptimizing' ),
			)
		);
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="tables" />';
		$table->search_box( __( 'Search tables', 'wpoptimizing' ), 'tables' );
		$table->display();
		$bulk_opts = array(
			'selected_label'   => __( 'Optimize selected', 'wpoptimizing' ),
			'all_label'        => __( 'Optimize all', 'wpoptimizing' ),
			'description'      => __( 'Optimizing reclaims overhead and defragments the table. It does not delete data and is safe to run. On InnoDB tables it rebuilds and re-analyses the table, but the reported free space may not drop because InnoDB reuses that space internally instead of returning it to the operating system — that is normal, not a failure.', 'wpoptimizing' ),
			/* translators: %d: number of selected tables. */
			'confirm_selected' => __( 'Optimize the %d selected table(s)?', 'wpoptimizing' ),
			/* translators: %d: number of filtered tables. */
			'confirm_all'      => __( 'Optimize ALL %d filtered table(s)?', 'wpoptimizing' ),
		);
		// Offer MyISAM → InnoDB conversion only when there is at least one MyISAM
		// table. Shares the same checkboxes/overlay as the optimize action; the
		// server re-validates the engine, so non-MyISAM selections are skipped.
		if ( $can_optimize && ! empty( $myisam ) ) {
			$bulk_opts['description'] .= ' ' . __( 'Converting to InnoDB enables row-level locking and crash recovery; it rebuilds the table in place and keeps all data.', 'wpoptimizing' );
			$bulk_opts['extra_action'] = array(
				'scope'            => 'convert_innodb',
				'all_values'       => $myisam,
				'selected_label'   => __( 'Convert selected to InnoDB', 'wpoptimizing' ),
				'all_label'        => __( 'Convert all MyISAM', 'wpoptimizing' ),
				/* translators: %d: number of selected tables. */
				'confirm_selected' => __( 'Convert the %d selected table(s) to InnoDB? Only MyISAM tables are changed.', 'wpoptimizing' ),
				/* translators: %d: number of MyISAM tables. */
				'confirm_all'      => __( 'Convert ALL %d MyISAM table(s) to InnoDB?', 'wpoptimizing' ),
			);
		}
		$this->render_bulk_controls( $table, $bulk_opts );
		echo '</form>';
	}
}
