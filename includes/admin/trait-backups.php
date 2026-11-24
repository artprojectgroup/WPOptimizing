<?php
/**
 * Backup / restore subsystem for the WPOptimizing admin UI.
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 * Covers the per-operation FS/DB backups, the backups tab, restore/delete,
 * pruning, settings and the op-key helpers. Shared low-level helpers
 * (append_jsonl, append_text, dir_size, rrmdir) remain in WPOPT_Admin_UI and are
 * reachable here through $this since traits compose into the same class.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Backups_Trait {

	/**
	 * Role snapshots gathered by the restore in progress, earliest per option name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	protected $restore_role_snapshots = array();

	/**
	 * Render Backups tab.
	 *
	 * @return void
	 */
	protected function render_backups_tab() {
		$this->render_section_header(
			__( 'Backups', 'wpoptimizing' ),
			__( 'Manage backups created before cleanups. You can create a manual backup, restore one, or delete old backups.', 'wpoptimizing' ),
			array(
				'icon' => 'backup',
				'doc'  => 'wpopt-doc-backups',
			)
		);


		echo '<form method="post" class="wpopt-notice">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="backups" />';
		submit_button( __( 'Create backup now', 'wpoptimizing' ), 'secondary', 'wpopt_backup_create', false );
		echo '</form>';

		$backups = $this->list_backups();
		if ( empty( $backups ) ) {
			echo '<p><em>' . esc_html__( 'No backups available.', 'wpoptimizing' ) . '</em></p>';
			return;
		}

		// Reuse the shared pre-rendered-cells list table (the same engine as the History
		// timeline) so Backups get the identical search + operation filter + per-page +
		// pagination with zero bespoke controls. Rows carry a 'cells' map; the per-row
		// Restore/Delete are POST buttons bound to a standalone form (form="..."), so they
		// never nest inside the table's own filter <form>.
		$bk_q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search.
		$bk_pp = isset( $_REQUEST['bk_pp'] ) ? (int) $_REQUEST['bk_pp'] : 25; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $bk_pp, array( 10, 25, 50, 100, 200 ), true ) ) {
			$bk_pp = 25;
		}
		$bk_tr = isset( $_REQUEST['bk_tipo'] ) ? sanitize_key( wp_unslash( $_REQUEST['bk_tipo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$trigger_choices = array();
		foreach ( $backups as $b ) {
			$t = (string) ( $b['trigger'] ?? '' );
			if ( '' !== $t && ! isset( $trigger_choices[ $t ] ) ) {
				$trigger_choices[ $t ] = $this->backup_trigger_label( $t );
			}
		}
		if ( '' !== $bk_tr && ! isset( $trigger_choices[ $bk_tr ] ) ) {
			$bk_tr = '';
		}

		$needle       = strtolower( $bk_q );
		$datetime_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$rows         = array();
		foreach ( $backups as $b ) {
			$trigger = (string) ( $b['trigger'] ?? '' );
			if ( '' !== $bk_tr && $trigger !== $bk_tr ) {
				continue;
			}
			if ( '' !== $needle ) {
				$hay = strtolower( (string) ( $b['key'] ?? '' ) . ' ' . (string) ( $b['type'] ?? '' ) . ' ' . $this->backup_trigger_label( $trigger ) );
				if ( false === strpos( $hay, $needle ) ) {
					continue;
				}
			}

			$mtime = (int) ( $b['mtime'] ?? 0 );
			if ( $mtime > 0 ) {
				$date_display = wp_date( get_option( 'date_format' ), $mtime );
			} else {
				$date_ts      = preg_match( '/^\d{8}$/', (string) $b['date'] ) ? strtotime( (string) $b['date'] ) : false;
				$date_display = $date_ts ? wp_date( get_option( 'date_format' ), $date_ts ) : (string) $b['date'];
			}
			$time_display = $mtime > 0 ? wp_date( $datetime_fmt, $mtime ) : $date_display;
			$count        = (int) ( $b['count'] ?? 0 );

			// Contents: summary + the expandable detail (captured; already escaped inside).
			ob_start();
			$this->render_backup_detail( $b );
			$detail   = (string) ob_get_clean();
			$contents = '<span>' . esc_html( ( '' !== (string) $b['type'] ) ? (string) $b['type'] : '—' ) . '</span>';
			if ( $count > 0 ) {
				$contents .= ' <span class="wpopt-muted">(' . esc_html( number_format_i18n( $count ) . ' ' . _n( 'item', 'items', $count, 'wpoptimizing' ) ) . ')</span>';
			}
			$contents .= $detail;

			// POST buttons tied to the standalone form printed before the table (the table sits
			// inside its GET filter form, and forms cannot nest). A GET link would put the action
			// nonce in the URL, history and server logs. The button's value is the backup id.
			$bk_id   = (string) $b['id'];
			$actions = '<button type="submit" form="wpopt-backup-row-action" name="wpopt_backup_restore" value="' . esc_attr( $bk_id ) . '" class="button button-secondary" onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Restore this backup? It replaces the current data with the saved copy.', 'wpoptimizing' ) ) ) . ');">' . esc_html__( 'Restore', 'wpoptimizing' ) . '</button> '
				. '<button type="submit" form="wpopt-backup-row-action" name="wpopt_backup_delete" value="' . esc_attr( $bk_id ) . '" class="button button-link-delete" onclick="return confirm(' . esc_attr( (string) wp_json_encode( __( 'Delete this backup permanently?', 'wpoptimizing' ) ) ) . ');">' . esc_html__( 'Delete', 'wpoptimizing' ) . '</button>';

			$date_cell = '<span title="' . esc_attr( sprintf( /* translators: %s: last update date/time. */ __( 'Last updated %s', 'wpoptimizing' ), $time_display ) ) . '">' . esc_html( $date_display ) . '</span>';
			if ( $mtime > 0 ) {
				$date_cell .= '<br><span class="wpopt-meta">' . esc_html( wp_date( get_option( 'time_format' ), $mtime ) ) . '</span>';
			}

			$rows[] = array(
				'cells' => array(
					'operation' => '<strong>' . esc_html( $this->backup_trigger_label( $trigger ) ) . '</strong><br><span class="wpopt-key">' . esc_html( (string) $b['key'] ) . '</span>',
					'contents'  => $contents,
					'date'      => $date_cell,
					'size'      => esc_html( size_format( (int) $b['size'] ) ),
					'actions'   => $actions,
				),
			);
		}

		$columns = array(
			'operation' => __( 'Operation', 'wpoptimizing' ),
			'contents'  => __( 'Contents', 'wpoptimizing' ),
			'date'      => __( 'Date', 'wpoptimizing' ),
			'size'      => __( 'Size', 'wpoptimizing' ),
			'actions'   => __( 'Actions', 'wpoptimizing' ),
		);
		$table   = new WPOptimizing_Backups_List_Table(
			$this,
			$rows,
			array(
				'scope'          => 'bk',
				'per_page'       => $bk_pp,
				'columns'        => $columns,
				'type_filter'    => $bk_tr,
				'type_choices'   => $trigger_choices,
				'type_all_label' => __( 'All operations', 'wpoptimizing' ),
				'hidden_fields'  => array( 'tab' => 'backups' ),
			)
		);
		$table->prepare_items();
		echo '<form method="post" id="wpopt-backup-row-action" class="wpopt-form-inline">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="page" value="wpoptimizing" /><input type="hidden" name="tab" value="backups" /></form>';
		$this->render_table_form( $table, 'backups', null, null, false, false );

	}


	/**
	 * Human-readable label for an operation trigger.
	 *
	 * @param string $trigger Trigger code.
	 * @return string
	 */
	protected function backup_trigger_label( $trigger ) {
		switch ( (string) $trigger ) {
			case 'delete':
				return __( 'Cleanup (deletion)', 'wpoptimizing' );
			case 'manual':
				return __( 'Manual backup', 'wpoptimizing' );
			case 'general_cleanup':
				return __( 'Scheduled cleanup', 'wpoptimizing' );
			case 'dedupe':
				return __( 'Duplicate media removal', 'wpoptimizing' );
			case 'cleanup':
				return __( 'Cleanup', 'wpoptimizing' );
			case 'db_cleanup':
				return __( 'Database cleanup', 'wpoptimizing' );
			case 'unused':
				return __( 'Unused media removal', 'wpoptimizing' );
			case 'autoload':
				return __( 'Autoload change', 'wpoptimizing' );
			case 'sweeper':
				return __( 'Background transient sweep', 'wpoptimizing' );
			case 'cron_run':
				return __( 'Scheduled task run now', 'wpoptimizing' );
			case 'uninstall':
				return __( 'Plugin or theme uninstall', 'wpoptimizing' );
			case 'htaccess':
				return __( '.htaccess change', 'wpoptimizing' );
			case 'wpconfig':
				return __( 'wp-config.php change', 'wpoptimizing' );
			case 'media_regen':
				return __( 'Thumbnail regeneration', 'wpoptimizing' );
			default:
				return __( 'Backup', 'wpoptimizing' );
		}
	}

	/**
	 * Render the expandable, detailed log of what a backup operation contains, so
	 * the customer can confirm a copy holds exactly what they need before
	 * restoring it.
	 *
	 * @param array<string,mixed> $b Backup entry from list_backups().
	 * @return void
	 */
	protected function render_backup_detail( array $b ) {
		$parts    = (array) ( $b['parts'] ?? array() );
		$manifest = ( isset( $b['manifest'] ) && is_array( $b['manifest'] ) ) ? $b['manifest'] : null;
		if ( empty( $parts ) && null === $manifest ) {
			return;
		}

		echo '<details class="wpopt-details-sm">';
		echo '<summary>' . esc_html__( 'Details', 'wpoptimizing' ) . '</summary>';
		echo '<div>';

		// Per-component counts (every DB type + the filesystem copy).
		if ( ! empty( $parts ) ) {
			echo '<div class="wpopt-muted">' . esc_html__( 'Includes:', 'wpoptimizing' ) . '</div>';
			echo '<ul class="wpopt-disc-list">';
			foreach ( $parts as $p ) {
				$label = ( 'fs' === ( $p['kind'] ?? '' ) ) ? __( 'Files', 'wpoptimizing' ) : $this->get_db_type_label( (string) ( $p['label'] ?? '' ) );
				$pc    = (int) ( $p['count'] ?? 0 );
				echo '<li>' . esc_html( $label );
				if ( $pc > 0 ) {
					echo ' — ' . esc_html( number_format_i18n( $pc ) . ' ' . _n( 'item', 'items', $pc, 'wpoptimizing' ) );
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		// Sample names captured in the manifest (helps recognise the right copy).
		if ( $manifest && ! empty( $manifest['types'] ) && is_array( $manifest['types'] ) ) {
			foreach ( $manifest['types'] as $tname => $info ) {
				$samples = array_filter( (array) ( $info['samples'] ?? array() ) );
				if ( empty( $samples ) ) {
					continue;
				}
				$tcount = (int) ( $info['count'] ?? 0 );
				echo '<div class="wpopt-muted wpopt-mt-2"><strong>' . esc_html( $this->get_db_type_label( (string) $tname ) ) . '</strong>: ';
				echo esc_html( implode( ', ', array_map( 'strval', array_slice( $samples, 0, 6 ) ) ) );
				if ( $tcount > count( $samples ) ) {
					echo ' ' . esc_html( sprintf( /* translators: %d: number of additional items. */ __( '(+%d more)', 'wpoptimizing' ), $tcount - count( $samples ) ) );
				}
				echo '</div>';
			}
		}

		echo '</div></details>';
	}

	/**
	 * Copy selected filesystem paths into today's backup directory.
	 *
	 * @param string[] $paths   Entries in "type::relative_path" format.
	 * @param string   $trigger Short reason for this backup ('delete'|'manual'|'cleanup').
	 * @return void
	 */
	protected function backup_fs_paths( array $paths, $trigger = 'cleanup' ) {
		// Result contract: ok=false means the backup subsystem could not start
		// (filesystem unavailable) — callers MUST NOT delete in that case.
		// attempted = existing source files seen; copied = verified copies made.
		$result = array(
			'ok'        => false,
			'copied'    => 0,
			'attempted' => 0,
			'saved'     => array(), // Relative paths whose backup was VERIFIED — the only ones safe to delete.
		);
		if ( empty( $paths ) ) {
			$result['ok'] = true; // Nothing to back up is not a failure.
			return $result;
		}
		if ( ! $this->ensure_backup_dir() ) {
			return $result;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return $result;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $result;
		}
		$result['ok'] = true;

		$day          = $this->backup_group_key();
		$backup_base  = WPOPT_BACKUP_DIR . '/fs-' . $day;
		$content_base = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		wp_mkdir_p( $backup_base );
		$manifest = WPOPT_BACKUP_DIR . '/fs-' . $day . '.jsonl';
		$now      = time();
		$saved    = array(); // "files::<rel>" for the operation manifest.

		foreach ( $paths as $entry ) {
			[ $tipo, $rel ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$rel            = ltrim( (string) $rel, '/' );
			if ( '' === $rel ) {
				continue;
			}
			if ( false !== strpos( $rel, '..' ) ) {
				continue;
			}
			$src = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) . $rel );
			if ( 0 !== strpos( $src, $content_base ) ) {
				continue;
			}
			$dst    = $backup_base . '/' . $rel;
			$copied = false;
			if ( is_dir( $src ) ) {
				++$result['attempted'];
				// WP_Filesystem::copy() does NOT recurse into directories, so a folder would
				// "copy" as an empty shell (or fail) yet still be deleted. copy_dir() copies the
				// whole tree; we only treat it as backed up when it verifiably lands on disk.
				wp_mkdir_p( $dst );
				$copied = $wp_filesystem->is_dir( $dst ) && true === copy_dir( $src, $dst ) && $wp_filesystem->is_dir( $dst );
			} elseif ( is_file( $src ) ) {
				++$result['attempted'];
				wp_mkdir_p( dirname( $dst ) );
				$copied = (bool) $wp_filesystem->copy( $src, $dst, true, FS_CHMOD_FILE ) && $wp_filesystem->exists( $dst );
			}
			if ( $copied ) {
				++$result['copied'];
				$result['saved'][] = $rel; // Verified — safe for the caller to delete.
				$saved[]           = 'files::' . $rel;
				// Record only verified copies so the manifest matches what was saved.
				$this->append_jsonl(
					$manifest,
					array(
						'ts'   => $now,
						'type' => strtolower( (string) $tipo ),
						'rel'  => $rel,
					)
				);
			}
		}

		// Operation manifest (only when grouped under an op-key) so the Backups tab
		// can show which files this copy contains and why it was made.
		if ( ! empty( $saved ) ) {
			$this->record_backup_manifest( $this->backup_op_key, $trigger, $saved );
		}

		$this->prune_backups();
		return $result;
	}

	/**
	 * List backups grouped by operation. Each entry represents one cleanup/backup
	 * action (an op-key, or a legacy day) and aggregates all of its component
	 * files — every DB type plus the filesystem copy — into a single restorable
	 * unit, enriched with the operation manifest so the customer can identify and
	 * pick exactly the copy they need.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function list_backups() {
		if ( ! is_dir( WPOPT_BACKUP_DIR ) ) {
			return array();
		}
		$groups = array();
		foreach ( glob( WPOPT_BACKUP_DIR . '/*' ) as $path ) {
			$base = basename( $path );

			// Filesystem backup directory: fs-<key> (key = op-… or legacy 8-digit day).
			if ( is_dir( $path ) && preg_match( '/^fs-(op-[A-Za-z0-9-]+|\\d{8})$/', $base, $m ) ) {
				$key      = $m[1];
				$manifest = WPOPT_BACKUP_DIR . '/fs-' . $key . '.jsonl';
				$count    = 0;
				$mtime    = (int) filemtime( $path );
				if ( file_exists( $manifest ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
					$count = max( 0, (int) substr_count( (string) file_get_contents( $manifest ), "\n" ) );
					$mtime = max( $mtime, (int) filemtime( $manifest ) );
				}
				$this->add_backup_group_part(
					$groups,
					$key,
					array(
						'kind'  => 'fs',
						'label' => 'files',
						'count' => $count,
					),
					$this->dir_size( $path ),
					$count,
					$mtime
				);
				continue;
			}

			// DB backup file: db-<type>-<key>.(jsonl|sql|php) — .php holds copies of files.
			if ( is_file( $path ) && preg_match( wpoptimizing_backup_db_file_regex(), $base, $m ) ) {
				$type  = $m[1];
				$key   = $m[2];
				$count = wpoptimizing_backup_count_records( $path, $m[3] );
				$this->add_backup_group_part(
					$groups,
					$key,
					array(
						'kind'  => 'db',
						'label' => $type,
						'count' => $count,
					),
					(int) filesize( $path ),
					$count,
					(int) filemtime( $path )
				);
				continue;
			}
		}

		$items = array();
		foreach ( $groups as $key => $g ) {
			$manifest = $this->manifest_for_key( $key );
			$items[]  = array(
				'id'       => 'op:' . $key,
				'key'      => $key,
				'is_op'    => ( 0 === strpos( (string) $key, 'op-' ) ),
				'type'     => $this->summarize_backup_parts( $g['parts'] ),
				'parts'    => $g['parts'],
				'trigger'  => is_array( $manifest ) ? (string) ( $manifest['trigger'] ?? '' ) : '',
				'manifest' => $manifest,
				'date'     => $key,
				'size'     => $g['size'],
				'count'    => $g['count'],
				'mtime'    => $g['mtime'],
			);
		}
		usort(
			$items,
			static function ( $a, $b ) {
				return ( $b['mtime'] ?? 0 ) <=> ( $a['mtime'] ?? 0 );
			}
		);
		return $items;
	}

	/**
	 * Accumulate one component file into its operation group.
	 *
	 * @param array<string,array<string,mixed>> $groups Groups accumulator (by key).
	 * @param string                            $key    Operation/day key.
	 * @param array<string,mixed>               $part   Component descriptor.
	 * @param int                               $size   Component size in bytes.
	 * @param int                               $count  Component item count.
	 * @param int                               $mtime  Component mtime.
	 * @return void
	 */
	protected function add_backup_group_part( array &$groups, $key, array $part, $size, $count, $mtime ) {
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'size'  => 0,
				'count' => 0,
				'mtime' => 0,
				'parts' => array(),
			);
		}
		$groups[ $key ]['size']   += (int) $size;
		$groups[ $key ]['count']  += (int) $count;
		$groups[ $key ]['mtime']   = max( (int) $groups[ $key ]['mtime'], (int) $mtime );
		$groups[ $key ]['parts'][] = $part;
	}

	/**
	 * Human-readable summary of the component types in an operation.
	 *
	 * @param array<int,array<string,mixed>> $parts Component descriptors.
	 * @return string
	 */
	protected function summarize_backup_parts( array $parts ) {
		$labels = array();
		foreach ( $parts as $p ) {
			$labels[] = ( 'fs' === ( $p['kind'] ?? '' ) ) ? __( 'files', 'wpoptimizing' ) : $this->get_db_type_label( (string) ( $p['label'] ?? '' ) );
		}
		$labels = array_values( array_filter( array_unique( $labels ) ) );
		return implode( ', ', $labels );
	}

	/**
	 * Read the operation manifest for a key (null for legacy/day backups).
	 *
	 * @param string $key Operation key.
	 * @return array<string,mixed>|null
	 */
	protected function manifest_for_key( $key ) {
		$key = (string) $key;
		if ( 0 !== strpos( $key, 'op-' ) ) {
			return null;
		}
		$path = WPOPT_BACKUP_DIR . '/op-manifest-' . sanitize_file_name( $key ) . '.json';
		if ( ! file_exists( $path ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Resolve the actual component files of an operation (for restore/delete).
	 *
	 * @param string $key Operation/day key.
	 * @return array<int,array<string,string>>
	 */
	protected function operation_components( $key ) {
		$key   = (string) $key;
		$parts = array();
		if ( '' === $key || ! is_dir( WPOPT_BACKUP_DIR ) ) {
			return $parts;
		}
		foreach ( glob( WPOPT_BACKUP_DIR . '/*' ) as $path ) {
			$base = basename( $path );
			if ( is_file( $path ) && preg_match( wpoptimizing_backup_db_file_regex(), $base, $m ) && $m[2] === $key ) {
				$parts[] = array(
					'kind' => 'db',
					'type' => $m[1],
					'ext'  => $m[3],
					'path' => $path,
				);
			} elseif ( is_dir( $path ) && preg_match( '/^fs-(op-[A-Za-z0-9-]+|\\d{8})$/', $base, $m ) && $m[1] === $key ) {
				$parts[] = array(
					'kind' => 'fs',
					'key'  => $m[1],
					'path' => $path,
				);
			}
		}
		return $parts;
	}

	/**
	 * Save the front-end performance and CDN options from Speed › Page & browser.
	 *
	 * @return void
	 */
	protected function save_frontend_perf_from_post() {
		if ( ! current_user_can( wpoptimizing_cap() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verified by the shared action handler.
		// Front-end performance features (Performance Lab): speculative loading,
		// content-image loading optimization and the Server-Timing diagnostic.
		// Preload, lazy embeds, Google Fonts and the CDN are Pro: their fields are only on the form
		// (with this marker) when they can be used. Without it their stored values are kept, so a
		// lapsed license that is renewed finds its configuration where it left it.
		$fp_pro_rows = isset( $_POST['wpopt_fp_has_pro'] );
		$fp_prev     = function_exists( 'wpoptimizing_frontend_perf_settings' ) ? wpoptimizing_frontend_perf_settings() : array();
		if ( function_exists( 'wpoptimizing_frontend_perf_sanitize' ) ) {
			update_option(
				'wpoptimizing_frontend_perf',
				wpoptimizing_frontend_perf_sanitize(
					array(
						'speculation'    => isset( $_POST['wpopt_fp_speculation'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_fp_speculation'] ) ) : 'off',
						'server_timing'  => isset( $_POST['wpopt_fp_server_timing'] ) ? 1 : 0,
						'image_opt'      => isset( $_POST['wpopt_fp_image_opt'] ) ? 1 : 0,
						'resource_hints' => isset( $_POST['wpopt_fp_resource_hints'] ) ? 1 : 0,
						'google_fonts'   => $fp_pro_rows ? ( isset( $_POST['wpopt_fp_google_fonts'] ) ? 1 : 0 ) : (int) ( $fp_prev['google_fonts'] ?? 0 ),
						'preload'        => $fp_pro_rows ? ( isset( $_POST['wpopt_fp_preload'] ) ? 1 : 0 ) : (int) ( $fp_prev['preload'] ?? 0 ),
						'preload_urls'   => $fp_pro_rows ? ( isset( $_POST['wpopt_fp_preload_urls'] ) ? (string) wp_unslash( $_POST['wpopt_fp_preload_urls'] ) : '' ) : (array) ( $fp_prev['preload_urls'] ?? array() ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each URL is sanitized with esc_url_raw() in wpoptimizing_frontend_perf_sanitize().
						'lazy_embeds'    => $fp_pro_rows ? ( isset( $_POST['wpopt_fp_lazy_embeds'] ) ? 1 : 0 ) : (int) ( $fp_prev['lazy_embeds'] ?? 0 ),
						'image_opt_page' => isset( $_POST['wpopt_fp_image_opt_page'] ) ? 1 : 0,
						'image_lcp'      => isset( $_POST['wpopt_fp_image_lcp'] ) ? 1 : 0,
						'image_eager'    => isset( $_POST['wpopt_fp_image_eager'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_fp_image_eager'] ) ) : 'auto',
						'lazy_exclude'   => isset( $_POST['wpopt_fp_lazy_exclude'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wpopt_fp_lazy_exclude'] ) ) : '',
					)
				),
				true
			);
		}

		// CDN URL rewriting (static assets served from a CDN host).
		if ( $fp_pro_rows && function_exists( 'wpoptimizing_cdn_sanitize' ) ) {
			$cdn_dirs = isset( $_POST['wpopt_cdn_dirs'] ) && is_array( $_POST['wpopt_cdn_dirs'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['wpopt_cdn_dirs'] ) )
				: array();
			update_option(
				'wpoptimizing_cdn',
				wpoptimizing_cdn_sanitize(
					array(
						'enabled' => isset( $_POST['wpopt_cdn_enabled'] ) ? 1 : 0,
						'host'    => isset( $_POST['wpopt_cdn_host'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_cdn_host'] ) ) : '',
						'dirs'    => array(
							'uploads'  => ! empty( $cdn_dirs['uploads'] ) ? 1 : 0,
							'content'  => ! empty( $cdn_dirs['content'] ) ? 1 : 0,
							'includes' => ! empty( $cdn_dirs['includes'] ) ? 1 : 0,
						),
					)
				),
				true
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( method_exists( $this, 'cache_flash' ) ) {
			$this->cache_flash( __( 'Front-end settings saved.', 'wpoptimizing' ), 'updated' );
		}
	}

	/**
	 * Parse a backup ID string into its component parts.
	 *
	 * @param string $backup_id Backup ID (e.g. "fs:20240101" or "db:options:20240101:jsonl").
	 * @return array<string,string>|null Parsed components or null if invalid.
	 */
	protected function parse_backup_id( $backup_id ) {
		$backup_id = (string) $backup_id;
		// Operation-level id: restores/deletes every component of one cleanup.
		if ( preg_match( '/^op:(op-[A-Za-z0-9-]+|\\d{8})$/', $backup_id, $m ) ) {
			return array(
				'type' => 'op',
				'key'  => $m[1],
			);
		}
		// Key is either a legacy day (8 digits) or a per-operation key (op-…).
		if ( preg_match( '/^fs:([A-Za-z0-9_-]{1,80})$/', $backup_id, $m ) ) {
			return array(
				'type' => 'fs',
				'date' => $m[1],
			);
		}
		if ( preg_match( '/^db:([a-z0-9_]+):(\\d{8}):(jsonl|sql)$/', $backup_id, $m ) ) {
			return array(
				'type'    => 'db',
				'db_type' => $m[1],
				'date'    => $m[2],
				'ext'     => $m[3],
			);
		}
		return null;
	}

	protected function restore_backup( $backup_id ) {
		if ( ! $this->ensure_backup_dir() ) {
			return;
		}
		$parsed = $this->parse_backup_id( $backup_id );
		if ( ! $parsed ) {
			return;
		}
		// Role snapshots are gathered across every file of the restore and written once, at the
		// end, from the EARLIEST one: see restore_db_backup().
		$this->restore_role_snapshots = array();
		// Operation-level: restore every component file of the cleanup at once.
		if ( 'op' === $parsed['type'] ) {
			foreach ( $this->operation_components( $parsed['key'] ) as $part ) {
				if ( 'db' === $part['kind'] ) {
					$this->restore_db_backup( $part['type'], $part['path'] );
				} elseif ( 'fs' === $part['kind'] ) {
					$this->restore_fs_backup( $part['key'] );
				}
			}
			$this->restore_flush_role_snapshots();
			return;
		}
		if ( 'fs' === $parsed['type'] ) {
			$date = $parsed['date'];
			$this->restore_fs_backup( $date );
			return;
		}
		if ( 'db' === $parsed['type'] ) {
			$type = $parsed['db_type'];
			$date = $parsed['date'];
			$ext  = $parsed['ext'];
			$path = WPOPT_BACKUP_DIR . '/db-' . $type . '-' . $date . '.' . $ext;
			$this->restore_db_backup( $type, $path );
			$this->restore_flush_role_snapshots();
		}
	}

	/**
	 * Write back the earliest snapshot of each roles option gathered by this restore.
	 *
	 * Each role or capability item of the old writer appended its own snapshot, and the restore
	 * replayed them all with REPLACE: the last one won, and it was taken after the earlier
	 * batches of the job had already removed their roles. The earliest is the state before the
	 * operation began, which is what an undo puts back.
	 *
	 * @return void
	 */
	protected function restore_flush_role_snapshots() {
		global $wpdb;
		$names = array();
		foreach ( (array) $this->restore_role_snapshots as $name => $rec ) {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->options,
				array(
					'option_name'  => (string) $name,
					'option_value' => $rec['value'],
					'autoload'     => $rec['autoload'] ?? 'yes',
				)
			);
			$names[] = (string) $name;
		}
		$this->restore_role_snapshots = array();
		wpoptimizing_forget_restored_options( $names );
	}

	protected function delete_backup( $backup_id ) {
		$parsed = $this->parse_backup_id( $backup_id );
		if ( ! $parsed ) {
			return;
		}
		// Operation-level: delete every component file of the cleanup + its manifest.
		if ( 'op' === $parsed['type'] ) {
			$key = $parsed['key'];
			foreach ( $this->operation_components( $key ) as $part ) {
				if ( 'db' === $part['kind'] && file_exists( $part['path'] ) ) {
					wp_delete_file( $part['path'] );
				} elseif ( 'fs' === $part['kind'] ) {
					if ( is_dir( $part['path'] ) ) {
						$this->rrmdir( $part['path'] );
					}
					$manifest = WPOPT_BACKUP_DIR . '/fs-' . $part['key'] . '.jsonl';
					if ( file_exists( $manifest ) ) {
						wp_delete_file( $manifest );
					}
				}
			}
			if ( 0 === strpos( (string) $key, 'op-' ) ) {
				$op_manifest = WPOPT_BACKUP_DIR . '/op-manifest-' . sanitize_file_name( $key ) . '.json';
				if ( file_exists( $op_manifest ) ) {
					wp_delete_file( $op_manifest );
				}
			}
			return;
		}
		if ( 'fs' === $parsed['type'] ) {
			$date     = $parsed['date'];
			$dir      = WPOPT_BACKUP_DIR . '/fs-' . $date;
			$manifest = WPOPT_BACKUP_DIR . '/fs-' . $date . '.jsonl';
			if ( is_dir( $dir ) ) {
				$this->rrmdir( $dir );
			}
			if ( file_exists( $manifest ) ) {
				wp_delete_file( $manifest );
			}
			return;
		}
		if ( 'db' === $parsed['type'] ) {
			$type = $parsed['db_type'];
			$date = $parsed['date'];
			$ext  = $parsed['ext'];
			$path = WPOPT_BACKUP_DIR . '/db-' . $type . '-' . $date . '.' . $ext;
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	protected function restore_db_backup( $type, $path ) {
		if ( ! file_exists( $path ) ) {
			return;
		}
		$base = realpath( WPOPT_BACKUP_DIR );
		$real = realpath( $path );
		if ( false === $base || false === $real || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
			return;
		}
		global $wpdb;
		if ( 'table' === $type && substr( $path, -4 ) === '.sql' ) {
			$fh = fopen( $path, 'r' ); // phpcs:ignore
			if ( false === $fh ) {
				return;
			}
			// A file without the format line was written by the old writer, which stored the
			// placeholder hash of its own request in place of every %: put the % back.
			$first      = fgets( $fh );
			$old_format = ( false === $first || WPOPT_SQL_BACKUP_FORMAT !== trim( (string) $first ) );
			rewind( $fh );
			$buffer = '';
			$prefix = preg_quote( $wpdb->prefix, '/' );
			while ( ( $line = fgets( $fh ) ) !== false ) {
				$trim = trim( $line );
				if ( '' === $trim || 0 === strpos( $trim, '--' ) ) {
					continue;
				}
				$buffer .= $trim . "\n";
				if ( ! preg_match( '/;\\s*$/', $trim ) ) {
					continue;
				}
				$stmt   = trim( $buffer );
				$buffer = '';
				if ( ! preg_match( '/^(CREATE TABLE|INSERT INTO)\\s+`?' . $prefix . '[A-Za-z0-9_]+`?/i', $stmt ) ) {
					continue;
				}
				if ( $old_format ) {
					$stmt = wpoptimizing_backup_sql_strip_placeholders( $stmt );
				}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
						$wpdb->query( $stmt ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
			fclose( $fh ); // phpcs:ignore
			return;
		}
		$fh = fopen( $path, 'r' ); // phpcs:ignore
		if ( false === $fh ) {
			return;
		}
		$restored_options = array();
		// Objects whose meta was written straight into the tables: their meta cache goes too, or
		// with a persistent object cache the restored rows stay invisible.
		$restored_meta = array();
		// Rows are written with their own primary key (meta_id, comment_ID…) and REPLACE, so
		// restoring the same backup twice puts the same rows back instead of duplicating them.
		$meta_tables = array(
			'post_meta'    => array( $wpdb->postmeta, 'post_id' ),
			'user_meta'    => array( $wpdb->usermeta, 'user_id' ),
			'term_meta'    => array( $wpdb->termmeta, 'term_id' ),
			'comment_meta' => array( $wpdb->commentmeta, 'comment_id' ),
		);
		$put_meta    = static function ( $group, $row ) use ( $wpdb, $meta_tables, &$restored_meta ) {
			if ( ! is_array( $row ) || empty( $row ) ) {
				return;
			}
			list( $table, $id_col ) = $meta_tables[ $group ];
			$wpdb->replace( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( isset( $row[ $id_col ] ) ) {
				$restored_meta[ $group ][] = (int) $row[ $id_col ];
			}
		};
		// Tables whose rows go back whole, by record type.
		$plain_tables    = array(
			'term_relationship' => $wpdb->term_relationships,
			'term_taxonomy'     => $wpdb->term_taxonomy,
			'wc_session'        => $wpdb->prefix . 'woocommerce_sessions',
		);
		$table_exists    = static function ( $table ) use ( $wpdb ) {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === (string) $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		};
		$touched_objects = array();
		$comment_posts   = array();
		$files_failed    = 0;
		while ( ( $line = fgets( $fh ) ) !== false ) {
			$rec = json_decode( $line, true, 64 ); // Cron arguments and revisions nest deeper than ten.
			if ( ! is_array( $rec ) || empty( $rec['type'] ) ) {
				continue;
			}
			$rtype = $rec['type'];
			if ( 'role' === $rtype && isset( $rec['name'] ) ) {
				// Keep the earliest snapshot of each roles option; restore_backup() writes it.
				$name = (string) $rec['name'];
				$prev = $this->restore_role_snapshots[ $name ] ?? null;
				if ( null === $prev || (int) ( $rec['ts'] ?? 0 ) < (int) ( $prev['ts'] ?? 0 ) ) {
					$this->restore_role_snapshots[ $name ] = $rec;
				}
				continue;
			}
			if ( in_array( $rtype, array( 'option', 'transient' ), true ) ) {
				$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->options,
					array(
						'option_name'  => $rec['name'],
						'option_value' => $rec['value'],
						'autoload'     => $rec['autoload'] ?? 'no',
					)
				);
				$restored_options[] = (string) $rec['name'];
				continue;
			}
			if ( in_array( $rtype, array( 'post_meta', 'user_meta', 'term_meta', 'comment_meta' ), true ) && ! empty( $rec['row'] ) && is_array( $rec['row'] ) ) {
				$put_meta( $rtype, $rec['row'] );
				continue;
			}
			// Rows the general cleanup removed straight from their table, written back with their
			// own keys (REPLACE: restoring twice is still one row).
			if ( isset( $plain_tables[ $rtype ] ) && ! empty( $rec['row'] ) && is_array( $rec['row'] ) ) {
				$tbl = $plain_tables[ $rtype ];
				if ( 'wc_session' !== $rtype || $table_exists( $tbl ) ) {
					$wpdb->replace( $tbl, $rec['row'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep the original keys.
				}
				if ( 'term_relationship' === $rtype && function_exists( 'wp_cache_delete' ) && isset( $rec['row']['object_id'] ) ) {
					$touched_objects[] = (int) $rec['row']['object_id'];
				}
				continue;
			}
			if ( 'comment' === $rtype && ! empty( $rec['comment']['comment_ID'] ) && is_array( $rec['comment'] ) ) {
				$cid = (int) $rec['comment']['comment_ID'];
				$wpdb->replace( $wpdb->comments, $rec['comment'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep comment_ID.
				foreach ( (array) ( $rec['meta'] ?? array() ) as $m ) {
					$put_meta( 'comment_meta', $m );
				}
				// wp_delete_comment() moved its replies up to its own parent: point them back.
				$kids = wpoptimizing_backup_ids( (array) ( $rec['children'] ?? array() ) );
				if ( $kids ) {
					$ph = implode( ',', array_fill( 0, count( $kids ), '%d' ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_parent = %d WHERE comment_ID IN ({$ph})", array_merge( array( $cid ), $kids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are interpolated.
				}
				if ( function_exists( 'clean_comment_cache' ) ) {
					clean_comment_cache( $cid );
				}
				$post_id = (int) ( $rec['comment']['comment_post_ID'] ?? 0 );
				if ( $post_id > 0 ) {
					$comment_posts[ $post_id ] = true;
				}
				continue;
			}
			if ( 'autoload' === $rtype && isset( $rec['name'], $rec['autoload'] ) ) {
				$wpdb->update( $wpdb->options, array( 'autoload' => (string) $rec['autoload'] ), array( 'option_name' => (string) $rec['name'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$restored_options[] = (string) $rec['name'];
				continue;
			}
			if ( 'file' === $rtype ) {
				if ( ! wpoptimizing_backup_restore_file( $rec ) ) {
					++$files_failed;
				}
				continue;
			}
			if ( 'cron' === $rtype && ! empty( $rec['events'] ) && is_array( $rec['events'] ) && function_exists( '_get_cron_array' ) ) {
				// Each event back at its own timestamp, merged into what is scheduled now.
				$cron = wpoptimizing_cron_merge_events( (array) _get_cron_array(), $rec['events'] );
				if ( function_exists( '_set_cron_array' ) ) {
					_set_cron_array( $cron );
				} else {
					$cron['version'] = 2;
					update_option( 'cron', $cron, true );
				}
				continue;
			}
			if ( in_array( $rtype, array( 'as_action', 'as_log' ), true ) && ! empty( $rec['row'] ) && is_array( $rec['row'] ) ) {
				$as_tbl_name = ( 'as_action' === $rtype )
					? $wpdb->prefix . 'actionscheduler_actions'
					: $wpdb->prefix . 'actionscheduler_logs';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$as_present = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_tbl_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( (string) $as_present === $as_tbl_name ) {
					$wpdb->replace( $as_tbl_name, $rec['row'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep original action_id/log_id.
				}
				continue;
			}
			if ( 'cpt_post' === $rtype && ! empty( $rec['post']['ID'] ) ) {
				$pid = (int) $rec['post']['ID'];
				$this->restore_post_row( $rec['post'] );
				foreach ( (array) ( $rec['meta'] ?? array() ) as $m ) {
					$put_meta( 'post_meta', $m );
				}
				foreach ( (array) ( $rec['rels'] ?? array() ) as $rel ) {
					$wpdb->replace( $wpdb->term_relationships, $rel ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				}
				// What wp_delete_post() took along: comments with their meta, revisions with theirs.
				$comment_ids = array();
				foreach ( (array) ( $rec['comments'] ?? array() ) as $c ) {
					if ( is_array( $c ) && ! empty( $c['comment_ID'] ) ) {
						$wpdb->replace( $wpdb->comments, $c ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep comment_ID.
						$comment_ids[] = (int) $c['comment_ID'];
					}
				}
				foreach ( (array) ( $rec['comment_meta'] ?? array() ) as $m ) {
					$put_meta( 'comment_meta', $m );
				}
				foreach ( (array) ( $rec['revisions'] ?? array() ) as $rev ) {
					if ( empty( $rev['post']['ID'] ) ) {
						continue;
					}
					$this->restore_post_row( $rev['post'] );
					foreach ( (array) ( $rev['meta'] ?? array() ) as $m ) {
						$put_meta( 'post_meta', $m );
					}
					if ( function_exists( 'clean_post_cache' ) ) {
						clean_post_cache( (int) $rev['post']['ID'] );
					}
				}
				if ( ! empty( $comment_ids ) && function_exists( 'clean_comment_cache' ) ) {
					clean_comment_cache( $comment_ids );
				}
				// wp_delete_post() moved its children up to its own parent: point them back.
				$kids = wpoptimizing_backup_ids( (array) ( $rec['children'] ?? array() ) );
				if ( $kids ) {
					$ph = implode( ',', array_fill( 0, count( $kids ), '%d' ) );
					$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_parent = %d WHERE ID IN ({$ph})", array_merge( array( $pid ), $kids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only %d placeholders are interpolated.
					if ( function_exists( 'clean_post_cache' ) ) {
						foreach ( array_map( 'intval', (array) $rec['children'] ) as $kid ) {
							clean_post_cache( $kid );
						}
					}
				}
				if ( function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( $pid );
				}
				$restored_meta['post_meta'][] = $pid;
				continue;
			}
			if ( 'tax_term' === $rtype && ! empty( $rec['term']['term_id'] ) && ! empty( $rec['term_taxonomy']['term_taxonomy_id'] ) ) {
				$wpdb->replace( $wpdb->terms, $rec['term'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep term_id.
				$wpdb->replace( $wpdb->term_taxonomy, $rec['term_taxonomy'] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep term_taxonomy_id + term_id.
				foreach ( (array) ( $rec['meta'] ?? array() ) as $m ) {
					$put_meta( 'term_meta', $m );
				}
				$taxonomy = (string) ( $rec['term_taxonomy']['taxonomy'] ?? '' );
				foreach ( (array) ( $rec['rels'] ?? array() ) as $rel ) {
					$wpdb->replace( $wpdb->term_relationships, $rel ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					if ( '' !== $taxonomy && isset( $rel['object_id'] ) && function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( (int) $rel['object_id'], $taxonomy . '_relationships' );
					}
				}
				if ( function_exists( 'clean_term_cache' ) ) {
					clean_term_cache( (int) $rec['term']['term_id'], $taxonomy );
				}
				$restored_meta['term_meta'][] = (int) $rec['term']['term_id'];
				continue;
			}
			if ( in_array( $rtype, array( 'shortcode_content', 'post_content' ), true ) && isset( $rec['post_id'], $rec['post_content'] ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->posts, array( 'post_content' => (string) $rec['post_content'] ), array( 'ID' => (int) $rec['post_id'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( function_exists( 'clean_post_cache' ) ) {
					clean_post_cache( (int) $rec['post_id'] );
				}
				continue;
			}
		}
		fclose( $fh ); // phpcs:ignore
		wpoptimizing_forget_restored_options( $restored_options );
		wpoptimizing_forget_restored_meta( $restored_meta );
		if ( ! empty( $touched_objects ) && function_exists( 'wp_cache_delete' ) && function_exists( 'get_taxonomies' ) ) {
			foreach ( get_taxonomies( array(), 'names' ) as $taxonomy ) {
				if ( ! is_string( $taxonomy ) ) {
					continue;
				}
				foreach ( array_unique( $touched_objects ) as $object_id ) {
					wp_cache_delete( $object_id, $taxonomy . '_relationships' );
				}
			}
		}
		if ( function_exists( 'wp_update_comment_count_now' ) ) {
			foreach ( array_keys( $comment_posts ) as $post_id ) {
				wp_update_comment_count_now( (int) $post_id );
			}
		}
		$this->restore_incomplete_notice( $files_failed );
	}

	/**
	 * Put a backed-up post row back unless a post with that ID is already there.
	 *
	 * The original ID is kept: meta, term relations, comments and revisions point at it.
	 *
	 * @param array<string,mixed> $post Row of the posts table.
	 * @return void
	 */
	protected function restore_post_row( array $post ) {
		global $wpdb;
		$pid = (int) ( $post['ID'] ?? 0 );
		if ( $pid <= 0 ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $pid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $exists ) {
			$wpdb->insert( $wpdb->posts, $post ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- keep original ID (referenced by meta/rels).
		}
	}

	protected function restore_fs_backup( $date ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			return;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return;
		}
		$base = WPOPT_BACKUP_DIR . '/fs-' . $date;
		if ( ! is_dir( $base ) ) {
			return;
		}
		// CATCH_GET_CHILD so an unreadable directory inside the backup does not throw out of
		// the loop — but a restore is the ONE place where quietly skipping is worse than
		// stopping, because a partial restore that reports success is how somebody concludes
		// their data is back when it is not. So: skip, count, and say so afterwards. The same
		// goes for a copy that fails; its return value used to be discarded.
		$failed = 0;
		$it     = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $it as $path ) {
			$rel  = ltrim( str_replace( $base, '', $path->getPathname() ), '/' );
			$dest = trailingslashit( WP_CONTENT_DIR ) . $rel;
			if ( $path->isDir() ) {
				if ( ! wp_mkdir_p( $dest ) ) {
					++$failed;
				}
			} else {
				wp_mkdir_p( dirname( $dest ) );
				if ( ! $wp_filesystem->copy( $path->getPathname(), $dest, true, FS_CHMOD_FILE ) ) {
					++$failed;
				}
			}
		}

		$this->restore_incomplete_notice( $failed );
	}

	/**
	 * Say so when a restore could not write everything back: a partial restore that reports
	 * success is how somebody concludes their data is back when it is not.
	 *
	 * @param int $failed Items that could not be written back.
	 * @return void
	 */
	protected function restore_incomplete_notice( $failed ) {
		if ( $failed <= 0 ) {
			return;
		}
		add_settings_error(
			'wpoptimizing',
			'wpoptimizing_restore_incomplete',
			sprintf(
				/* translators: %d: number of files or folders that could not be restored. */
				_n(
					'The restore finished, but %d item could not be written back. Check the permissions of the backup folder and of wp-content, then restore again.',
					'The restore finished, but %d items could not be written back. Check the permissions of the backup folder and of wp-content, then restore again.',
					$failed,
					'wpoptimizing'
				),
				(int) $failed
			),
			'error'
		);
	}

	protected function create_manual_backup() {
		// Group this manual backup under its own operation key so DB + FS land in a
		// single, clearly-labelled entry (with a manifest) in the Backups tab.
		$this->backup_op_key = $this->new_backup_op_key();
		// Create backups for current orphans lists (DB + FS) without deleting.
		if ( file_exists( WPOPT_CSV_SCAN_DB ) ) {
			$items = array();
			$fh    = wpoptimizing_scan_read_handle( WPOPT_CSV_SCAN_DB ); // decrypts if encrypted at rest
			if ( $fh ) {
				fgetcsv( $fh, 0, ',', '"', '\\' );
				while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
					if ( count( $row ) < 2 ) {
						continue;
					}
					$items[] = $row[0] . '::' . $row[1];
				}
				fclose( $fh ); // phpcs:ignore
			}
			$this->backup_db_items( $items, 'manual' );
		}
		if ( file_exists( WPOPT_CSV_SCAN_FS ) ) {
			$paths = array();
			$fh    = wpoptimizing_scan_read_handle( WPOPT_CSV_SCAN_FS ); // decrypts if encrypted at rest
			if ( $fh ) {
				fgetcsv( $fh, 0, ',', '"', '\\' );
				while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
					if ( count( $row ) < 2 ) {
						continue;
					}
					$paths[] = $row[0] . '::' . $row[1];
				}
				fclose( $fh ); // phpcs:ignore
			}
			$this->backup_fs_paths( $paths, 'manual' );
		}

		add_settings_error( 'wpoptimizing', 'wpoptimizing_backup_created', __( 'Backup created.', 'wpoptimizing' ), 'updated' );
	}

	/**
	 * Apply the configured retention (copies per type, days). See wpoptimizing_backup_prune().
	 *
	 * @return void
	 */
	protected function prune_backups() {
		wpoptimizing_backup_prune( $this->get_backup_max_per_type(), $this->get_backup_max_days() );
	}

	protected function ensure_backup_dir() {
		return wpoptimizing_backup_ensure_dir();
	}

	protected function get_backup_max_per_type() {
		// Default raised for per-operation backups (each cleanup is its own entry).
		$val = (int) get_option( 'wpoptimizing_backup_max_per_type', 25 );
		return ( $val > 0 ) ? $val : 25;
	}

	protected function get_backup_max_days() {
		$val = (int) get_option( 'wpoptimizing_backup_max_days', 30 );
		return ( $val > 0 ) ? $val : 30;
	}

	protected function save_backup_settings() {
		if ( ! current_user_can( $this->cap ) ) {
			return;
		}
		if ( empty( $_POST[ $this->nonce_field ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_POST[ $this->nonce_field ] ) );
		if ( ! wp_verify_nonce( $nonce, $this->nonce_action ) ) {
			return;
		}

		// "Replay welcome wizard" action: re-arm the first-run flag and bounce to the
		// dashboard. Early-return so we don't overwrite settings with this form's
		// (absent) fields.
		if ( isset( $_POST['wpopt_replay_wizard'] ) ) {
			update_option( 'wpoptimizing_first_run', '1', false );
			wp_safe_redirect( admin_url( 'admin.php?page=wpoptimizing' ) );
			exit;
		}

		$max_per_type = isset( $_POST['wpopt_backup_max_per_type'] ) ? absint( wp_unslash( $_POST['wpopt_backup_max_per_type'] ) ) : 10;
		$max_days     = isset( $_POST['wpopt_backup_max_days'] ) ? absint( wp_unslash( $_POST['wpopt_backup_max_days'] ) ) : 30;
		update_option( 'wpoptimizing_backup_max_per_type', max( 1, min( 100, $max_per_type ) ), false );
		update_option( 'wpoptimizing_backup_max_days', max( 1, min( 3650, $max_days ) ), false );
		if ( isset( $_POST['wpopt_backup_max_mb'] ) ) {
			update_option( 'wpoptimizing_backup_max_mb', min( 1000000, absint( wp_unslash( $_POST['wpopt_backup_max_mb'] ) ) ), false );
		}

		// Complete-uninstall switch (default on): a checkbox, so absent means unchecked.
		update_option( 'wpoptimizing_delete_data_on_uninstall', isset( $_POST['wpopt_delete_data_on_uninstall'] ) ? '1' : '0', false );

		// Front-end performance and CDN are saved by their own form now (Speed › Page &
		// browser, save_frontend_perf_from_post()). This form no longer shows them, and saving
		// them here would read every missing checkbox as "off" and switch them all off.

		// Core tweaks: the form posts wpopt_tweaks[<id>]=1 for each enabled toggle;
		// unchecked boxes are absent, so an empty value disables them all.
		if ( function_exists( 'wpoptimizing_core_tweaks_sanitize' ) ) {
			$tweaks_raw = ( isset( $_POST['wpopt_tweaks'] ) && is_array( $_POST['wpopt_tweaks'] ) )
				? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['wpopt_tweaks'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys sanitized via sanitize_key + filtered against the registry below.
				: array();
			update_option( 'wpoptimizing_core_tweaks', wpoptimizing_core_tweaks_sanitize( $tweaks_raw ), true );
		}

		$cleanup     = $this->get_general_cleanup_service();
		$gc_settings = $cleanup->settings_from_request( $_POST );
		$cleanup->save_settings( $gc_settings );

		// Automatic background analysis: frequency + low-traffic (off-peak) window.
		$scan_schedule = isset( $_POST['wpopt_scan_schedule'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_scan_schedule'] ) ) : 'daily';
		if ( ! in_array( $scan_schedule, array( 'off', 'daily', 'weekly' ), true ) ) {
			$scan_schedule = 'daily';
		}
		update_option( 'wpoptimizing_scan_schedule', $scan_schedule, true );
		$op_start = isset( $_POST['wpopt_scan_offpeak_start'] ) ? max( 0, min( 23, absint( wp_unslash( $_POST['wpopt_scan_offpeak_start'] ) ) ) ) : 2;
		$op_end   = isset( $_POST['wpopt_scan_offpeak_end'] ) ? max( 0, min( 23, absint( wp_unslash( $_POST['wpopt_scan_offpeak_end'] ) ) ) ) : 6;
		update_option( 'wpoptimizing_scan_offpeak_start', $op_start, false );
		update_option( 'wpoptimizing_scan_offpeak_end', $op_end, false );
		if ( function_exists( 'wpoptimizing_sync_scan_schedule' ) ) {
			wpoptimizing_sync_scan_schedule( true ); // Re-arm with the new timing.
		}


		// Branding / white-label is managed remotely from the customer's account
		// (agency licenses); there is no local branding form to save here.


		// Notification email: where WPOptimizing sends its mails. Lets an agency route a
		// site's notices to the maintainer instead of the owner. Empty = use admin_email.
		// Accepts several addresses separated by comma/space/semicolon; invalid ones drop.
		if ( isset( $_POST['wpopt_notification_email'] ) ) {
			$notif_raw   = sanitize_text_field( wp_unslash( $_POST['wpopt_notification_email'] ) );
			$notif_clean = array();
			foreach ( preg_split( '/[\s,;]+/', $notif_raw ) as $notif_e ) {
				$notif_e = trim( (string) $notif_e );
				if ( '' !== $notif_e && is_email( $notif_e ) ) {
					$notif_clean[] = $notif_e;
				}
			}
			update_option( 'wpoptimizing_notification_email', implode( ', ', array_unique( $notif_clean ) ), false );
		}

		// Periodic maintenance report frequency (B7). Validated against the known set; the cron
		// is (un)scheduled on the next admin_init by wpoptimizing_report_arm().
		if ( isset( $_POST['wpopt_report_frequency'] ) && function_exists( 'wpoptimizing_report_frequencies' ) ) {
			$rep_freq = sanitize_key( wp_unslash( $_POST['wpopt_report_frequency'] ) );
			if ( ! isset( wpoptimizing_report_frequencies()[ $rep_freq ] ) ) {
				$rep_freq = 'off';
			}
			update_option( WPOPT_REPORT_FREQ_OPTION, $rep_freq, false );
			if ( function_exists( 'wpoptimizing_report_arm' ) ) {
				wpoptimizing_report_arm();
			}
		}

		// Background sweeper (D5): opt-in expired-transient housekeeping. Absent checkbox = off.
		if ( function_exists( 'wpoptimizing_sweeper_enabled' ) ) {
			update_option( WPOPT_SWEEPER_OPTION, empty( $_POST['wpopt_sweeper_enabled'] ) ? 0 : 1, true );
		}

		// "Send a test now": dispatch one report immediately without touching the schedule.
		if ( isset( $_POST['wpopt_report_test'] ) && function_exists( 'wpoptimizing_report_send' ) ) {
			$sent = wpoptimizing_report_send( true );
			add_settings_error(
				'wpoptimizing',
				'wpoptimizing_report_test',
				$sent ? __( 'Test report sent to the notification address.', 'wpoptimizing' ) : __( 'Could not send the test report — check the notification email and that a scan has run.', 'wpoptimizing' ),
				$sent ? 'updated' : 'error'
			);
		}

		// Compatibility overrides: capabilities the user takes back from a detected plugin.
		// Only keys the registry declares are stored, so a forged POST cannot invent one, and
		// an absent checkbox means "let the other plugin keep it".
		$known     = wpoptimizing_compat_capabilities();
		$raw_over  = isset( $_POST['wpopt_compat_override'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['wpopt_compat_override'] ) )
			: array();
		$overrides = array();
		foreach ( $raw_over as $cap ) {
			// Validated against the registry, not just sanitised: a key that is not a declared
			// capability is dropped, so a forged POST cannot create an override for anything.
			if ( isset( $known[ $cap ] ) ) {
				$overrides[ $cap ] = true;
			}
		}
		$prev_over = (array) get_option( 'wpoptimizing_compat_overrides', array() );
		update_option( 'wpoptimizing_compat_overrides', $overrides, true );
		if ( $overrides !== $prev_over && function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'compat_overrides', array( 'capabilities' => implode( ',', array_keys( $overrides ) ) ) );
		}

		// Data sharing consent (opt-in). An absent checkbox means "declined".
		$share_consent = ! empty( $_POST['wpopt_share_consent'] );
		$prev_consent  = (bool) get_option( 'wpoptimizing_share_consent', false );
		update_option( 'wpoptimizing_share_consent', $share_consent ? 1 : 0, true );
		if ( $share_consent !== $prev_consent && function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'data_sharing_consent', array( 'enabled' => $share_consent ? 1 : 0 ) );
		}

		add_settings_error( 'wpoptimizing', 'wpoptimizing_backup_saved', __( 'Settings saved.', 'wpoptimizing' ), 'updated' );
	}

	protected function backup_date_key() {
		return gmdate( 'Ymd' );
	}

	/**
	 * The grouping key for filesystem backups created right now. When an
	 * operation key has been set (one user action — a delete/clean job), every
	 * backup in that action lands in its own entry; otherwise it groups by day.
	 *
	 * @return string
	 */
	protected function backup_group_key() {
		return ( '' !== $this->backup_op_key ) ? $this->backup_op_key : $this->backup_date_key();
	}

	/**
	 * Generate a fresh, filesystem-safe per-operation backup key. The leading
	 * timestamp keeps it readable/sortable; a short suffix avoids collisions.
	 *
	 * @param string $token Optional client-supplied job token (shared across the
	 *                      async batches of a single "process all" job).
	 * @return string
	 */
	protected function new_backup_op_key( $token = '' ) {
		return wpoptimizing_backup_new_op_key( $token );
	}

	protected function backup_db_file_path( $type ) {
		$type = sanitize_key( $type );
		// Group by operation (op-key) so one cleanup = one identifiable backup;
		// falls back to the day key for backups taken outside an operation.
		return WPOPT_BACKUP_DIR . '/db-' . $type . '-' . $this->backup_group_key() . '.jsonl';
	}

	/**
	 * Record a human-readable manifest for the current backup operation, so the
	 * Backups tab can show exactly what each copy contains (and why), making the
	 * right one easy to find and select.
	 *
	 * @param string   $op_key  Operation key (groups all this op's backup files).
	 * @param string   $trigger Short reason ('delete_db'|'delete_fs'|'dedupe'|'general_cleanup'|'manual').
	 * @param string[] $items   Deleted entries in "type::value" form (for the DB summary).
	 * @return void
	 */
	protected function record_backup_manifest( $op_key, $trigger, array $items = array() ) {
		$op_key = (string) $op_key;
		if ( '' === $op_key || ! $this->ensure_backup_dir() ) {
			return;
		}
		$by_type = array();
		foreach ( $items as $entry ) {
			[ $t, $v ] = array_pad( explode( '::', (string) $entry, 2 ), 2, '' );
			// Filesystem items keep their literal "files" label; DB items are normalized.
			$t = ( 'files' === $t ) ? 'files' : $this->normalize_db_type( (string) $t );
			if ( '' === $t ) {
				continue;
			}
			if ( ! isset( $by_type[ $t ] ) ) {
				$by_type[ $t ] = array(
					'count'   => 0,
					'samples' => array(),
				);
			}
			++$by_type[ $t ]['count'];
			if ( '' !== $v && count( $by_type[ $t ]['samples'] ) < 6 ) {
				$by_type[ $t ]['samples'][] = $v;
			}
		}
		wpoptimizing_backup_merge_manifest( $op_key, $trigger, $by_type, count( $items ) );
	}

	/**
	 * Back up the given DB entries before deletion and record an operation
	 * manifest describing what was saved (and why).
	 *
	 * @param string[] $items   Entries in "type::value" form.
	 * @param string   $trigger Short reason for this backup ('delete'|'manual'|'general_cleanup'|'dedupe'|'cleanup').
	 * @return array{ok:bool,tables_backed:array<string,bool>}
	 */
	protected function backup_db_items( array $items, $trigger = 'cleanup' ) {
		// Result contract (mirrors backup_fs_paths): ok=false means the backup could not be
		// written in full (disk full / permissions / a read that failed) — callers MUST NOT
		// delete then. tables_backed lists prefixed table names whose schema AND every row
		// were written, so a caller never issues an irreversible DROP TABLE without them.
		// Both used to be set before anything was written, and the writes' answers were
		// ignored: a backup that never reached the disk still let the delete go ahead.
		$result = array(
			'ok'            => false,
			'tables_backed' => array(),
		);
		if ( empty( $items ) ) {
			$result['ok'] = true; // Nothing to back up is not a failure.
			return $result;
		}
		if ( ! $this->ensure_backup_dir() ) {
			return $result;
		}
		// Describe this operation up-front so even a partial/empty save is identifiable.
		$this->record_backup_manifest( $this->backup_op_key, $trigger, $items );
		global $wpdb;

		// Every write and every read below has to succeed for the backup to count.
		$all_ok   = true;
		$query_ok = static function () use ( $wpdb ) {
			return '' === (string) $wpdb->last_error;
		};

		$now = time();
		foreach ( $items as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$tipo             = $this->normalize_db_type( (string) $tipo );
			$valor            = (string) $valor;
			if ( '' === $tipo || '' === $valor ) {
				continue;
			}

			// Options / transients (stored in options table).
			if ( in_array( $tipo, array( 'option', 'transient' ), true ) ) {
				$names = array();
				if ( 0 === strpos( $valor, '_transient_' ) || 0 === strpos( $valor, '_site_transient_' ) ) {
					$names[] = $valor;
					if ( 0 === strpos( $valor, '_site_transient_' ) ) {
						$key     = substr( $valor, strlen( '_site_transient_' ) );
						$names[] = '_site_transient_timeout_' . $key;
					} else {
						$key     = substr( $valor, strlen( '_transient_' ) );
						$names[] = '_transient_timeout_' . $key;
					}
				} else {
					$names[] = $valor;
					$names[] = '_transient_' . $valor;
					$names[] = '_site_transient_' . $valor;
					$names[] = '_transient_timeout_' . $valor;
					$names[] = '_site_transient_timeout_' . $valor;
				}
				$names = array_values( array_unique( array_filter( $names ) ) );
				if ( empty( $names ) ) {
					continue;
				}
					$placeholders  = implode( ',', array_fill( 0, count( $names ), '%s' ) );
					$options_table = $this->quote_identifier( $wpdb->options );
					$args          = array_merge(
						array( "SELECT option_name, option_value, autoload FROM {$options_table} WHERE option_name IN ($placeholders)" ),
						$names
					);
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$prepared_sql = call_user_func_array( array( $wpdb, 'prepare' ), $args ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
				if ( null === $prepared_sql ) {
					$all_ok = false;
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
				$rows   = $wpdb->get_results( $prepared_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$all_ok = $query_ok() && $all_ok;
				foreach ( (array) $rows as $row ) {
					$rec    = array(
						'ts'       => $now,
						'type'     => $tipo,
						'name'     => $row['option_name'],
						'value'    => $row['option_value'],
						'autoload' => $row['autoload'],
					);
					$all_ok = $this->append_jsonl( $this->backup_db_file_path( $tipo ), $rec ) && $all_ok;
				}
				continue;
			}

			// Meta tables.
			if ( in_array( $tipo, array( 'post_meta', 'user_meta', 'term_meta' ), true ) ) {
				$table = ( 'post_meta' === $tipo ) ? $wpdb->postmeta : ( 'user_meta' === $tipo ? $wpdb->usermeta : $wpdb->termmeta );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
					$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $this->quote_identifier( $table ) . ' WHERE meta_key = %s', $valor ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$all_ok   = $query_ok() && $all_ok;
				foreach ( (array) $rows as $row ) {
					$rec    = array(
						'ts'   => $now,
						'type' => $tipo,
						'row'  => $row,
					);
					$all_ok = $this->append_jsonl( $this->backup_db_file_path( $tipo ), $rec ) && $all_ok;
				}
				continue;
			}

			// Roles and capabilities both live in the wp_user_roles option: store it whole, ONCE
			// per operation. Each item used to append its own copy and the restore replayed them
			// all, so the last one — taken after the earlier batches had already removed their
			// roles — won, and an undo of a multi-batch job put back only the last batch.
			if ( in_array( $tipo, array( 'role', 'capability', 'cap' ), true ) ) {
				$opt_name = $wpdb->prefix . 'user_roles';
				if ( $this->role_snapshot_taken( $opt_name ) ) {
					continue;
				}
				$rec    = array(
					'ts'       => $now,
					'type'     => 'role',
					'name'     => $opt_name,
					'value'    => maybe_serialize( get_option( $opt_name ) ),
					'autoload' => 'yes',
				);
				$all_ok = $this->append_jsonl( $this->backup_db_file_path( 'role' ), $rec ) && $all_ok;
				continue;
			}

			// Cron hooks: every event of the hook, at its own timestamp. The cron array is keyed
			// by timestamp, so asking it for the hook at the top level never found anything and
			// no cron backup was ever written.
			if ( 'cron' === $tipo && function_exists( '_get_cron_array' ) ) {
				$events = wpoptimizing_cron_events_for_hook( (array) _get_cron_array(), $valor );
				if ( ! empty( $events ) ) {
					$rec    = array(
						'ts'     => $now,
						'type'   => 'cron',
						'hook'   => $valor,
						'events' => $events,
					);
					$all_ok = $this->append_jsonl( $this->backup_db_file_path( 'cron' ), $rec ) && $all_ok;
				}
				continue;
			}

			// Action Scheduler actions: store every action row for this hook. Their log rows are
			// trace lines deleted without a copy, as in the general cleanup: a hundred per action,
			// they turned a backup into gigabytes (the WP Total Updater site, 27/10/2026).
			if ( 'action_scheduler' === $tipo ) {
				$as_actions = $wpdb->prefix . 'actionscheduler_actions';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$as_tbl = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
				if ( (string) $as_tbl === $as_actions ) {
					// In slices: one hook can carry hundreds of thousands of actions, and read at once
					// they ran PHP out of memory.
					$as_last = 0;
					do {
						$as_rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$as_actions}` WHERE hook = %s AND action_id > %d ORDER BY action_id LIMIT 2000", $valor, $as_last ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- AS table name is $wpdb->prefix + a fixed suffix; identifiers cannot be bound via prepare().
						$all_ok  = $query_ok() && $all_ok;
						$as_ids  = array();
						foreach ( $as_rows as $as_row ) {
							$as_ids[] = (int) ( $as_row['action_id'] ?? 0 );
							$all_ok   = $this->append_jsonl(
								$this->backup_db_file_path( 'action_scheduler' ),
								array(
									'ts'   => $now,
									'type' => 'as_action',
									'hook' => $valor,
									'row'  => $as_row,
								)
							) && $all_ok;
						}
						$as_n    = count( $as_rows );
						$as_ids  = array_filter( $as_ids );
						$as_last = empty( $as_ids ) ? 0 : max( $as_ids );
						unset( $as_rows );
					} while ( 2000 === $as_n && $as_last > 0 );
				}
				continue;
			}

			// Shortcode: store the ORIGINAL content of every post that uses it, so
			// the surgical strip can be reverted per post.
			if ( 'shortcode' === $tipo ) {
				$like = '%[' . $wpdb->esc_like( $valor ) . '%';
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 5000", $like ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
				$all_ok = $query_ok() && $all_ok;
				foreach ( (array) $rows as $row ) {
					if ( ! wpoptimizing_content_has_shortcode( (string) $row['post_content'], $valor ) ) {
						continue;
					}
					$rec    = array(
						'ts'           => $now,
						'type'         => 'shortcode_content',
						'tag'          => $valor,
						'post_id'      => (int) $row['ID'],
						'post_content' => (string) $row['post_content'],
					);
					$all_ok = $this->append_jsonl( $this->backup_db_file_path( 'shortcode' ), $rec ) && $all_ok;
				}
				continue;
			}

			// Orphaned CPT content: back up each post + its meta + term relations, and what
			// wp_delete_post( …, true ) takes along with it — its comments with their meta and
			// its revisions with theirs — so the destructive delete is fully restorable. Opt-in
			// only. The scanner also emits 'post_type_content' for the same thing.
			if ( in_array( $tipo, array( 'post', 'post_type', 'post_type_content' ), true ) ) {
				$pt = sanitize_key( $valor );
				if ( empty( $this->confirm_destructive ) || '' === $pt || 'posts' === $pt || post_type_exists( $pt ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 50000", $pt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
				$all_ok = $query_ok() && $all_ok;
				foreach ( (array) $ids as $id ) {
					$rec = $this->backup_post_record( (int) $id, $now );
					if ( false === $rec ) {
						$all_ok = false; // One of its reads failed.
						continue;
					}
					if ( null === $rec ) {
						continue; // Gone since the list was read.
					}
					$all_ok = $this->append_jsonl( $this->backup_db_file_path( 'post' ), $rec ) && $all_ok;
				}
				continue;
			}

			// Orphaned taxonomy terms: back up term + term_taxonomy + meta + rels.
			// The scanner emits both 'taxonomy' and 'taxonomy_term' — handle both.
			if ( in_array( $tipo, array( 'taxonomy', 'taxonomy_term' ), true ) ) {
				$tax = sanitize_key( $valor );
				if ( empty( $this->confirm_destructive ) || '' === $tax || taxonomy_exists( $tax ) ) {
					continue;
				}
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$tts    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", $tax ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
				$all_ok = $query_ok() && $all_ok;
				foreach ( (array) $tts as $tt ) {
					$term_id = (int) $tt['term_id'];
					$tt_id   = (int) $tt['term_taxonomy_id'];
					$term    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->terms} WHERE term_id = %d", $term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
					$all_ok  = $query_ok() && $all_ok;
					$meta    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->termmeta} WHERE term_id = %d", $term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
					$all_ok  = $query_ok() && $all_ok;
					$rels    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $tt_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
					$all_ok  = $query_ok() && $all_ok;
					$rec     = array(
						'ts'            => $now,
						'type'          => 'tax_term',
						'term'          => $term,
						'term_taxonomy' => $tt,
						'meta'          => $meta,
						'rels'          => $rels,
					);
					$all_ok  = $this->append_jsonl( $this->backup_db_file_path( 'taxonomy' ), $rec ) && $all_ok;
				}
				continue;
			}

			// Tables: store schema + data into a SQL file.
			if ( 'table' === $tipo ) {
				$table = $wpdb->prefix . preg_replace( '/[^A-Za-z0-9_]/', '', $valor );
				if ( '' === $table ) {
					continue;
				}
					$sql_path = WPOPT_BACKUP_DIR . '/db-table-' . $this->backup_group_key() . '.sql';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$create = $wpdb->get_row( 'SHOW CREATE TABLE ' . $this->quote_identifier( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				if ( ! empty( $create['Create Table'] ) ) {
					// A new file starts with the format line: the restore tells this writer's
					// files from the old one's (which lost every %) by it.
					$table_ok = file_exists( $sql_path ) || $this->append_text( $sql_path, WPOPT_SQL_BACKUP_FORMAT . "\n" );
					$table_ok = $table_ok && $this->append_text( $sql_path, "\n-- TABLE: {$table}\n" . $create['Create Table'] . ";\n" );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
						$rows = $wpdb->get_results( 'SELECT * FROM ' . $this->quote_identifier( $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$table_ok = $table_ok && $query_ok() && is_array( $rows );
					foreach ( (array) $rows as $row ) {
						if ( ! $table_ok ) {
							break;
						}
						$table_ok = $this->append_text( $sql_path, wpoptimizing_backup_sql_insert( $table, (array) $row ) );
					}
					if ( $table_ok ) {
						// Schema and every row are on disk: only now is the table safe to DROP.
						$result['tables_backed'][ $table ] = true;
					} else {
						$all_ok = false;
					}
				}
				continue;
			}
		}

		$result['ok'] = $all_ok;
		return $result;
	}

	/**
	 * Whether this operation already holds a snapshot of the roles option.
	 *
	 * One snapshot per operation, taken before its first role or capability is removed: that
	 * is the state an undo has to put back. The old writer filed capability snapshots under
	 * their own type, so those files are looked at too.
	 *
	 * @param string $opt_name Roles option name.
	 * @return bool
	 */
	protected function role_snapshot_taken( $opt_name ) {
		foreach ( array( 'role', 'capability', 'cap' ) as $type ) {
			$path = $this->backup_db_file_path( $type );
			if ( ! is_readable( $path ) ) {
				continue;
			}
			$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $fh ) {
				continue;
			}
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$rec = json_decode( $line, true, 10 );
				if ( is_array( $rec ) && 'role' === ( $rec['type'] ?? '' ) && (string) $opt_name === (string) ( $rec['name'] ?? '' ) ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return true;
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		return false;
	}

	/**
	 * Backup record of one post and of everything wp_delete_post( $id, true ) removes with it.
	 *
	 * Meta and term relations, and also its comments with their meta and its revisions with
	 * theirs: the delete of an orphaned post type used to lose those for good.
	 *
	 * @param int $id  Post ID.
	 * @param int $now Timestamp of the backup.
	 * @return array<string,mixed>|null|false Null when the post is gone, false when a read failed.
	 */
	protected function backup_post_record( $id, $now ) {
		$recs = wpoptimizing_backup_post_records( array( (int) $id ), (int) $now );
		if ( false === $recs ) {
			return false;
		}
		return $recs[ (int) $id ] ?? null;
	}

	/**
	 * Back up ONLY the empty rows for the selected keys (used by the Empty-records delete).
	 *
	 * Mirrors backup_db_items but scopes the SELECT to WPOPT_Empty_Scanner's exact empty
	 * condition, so a later restore re-inserts only what was removed — never duplicating the
	 * non-empty rows that share the same meta_key. Same result contract: ok=false means the
	 * backup subsystem could not start and the caller MUST NOT delete.
	 *
	 * @param string[] $items   Entries in "type::value" format (option|post_meta|user_meta|term_meta).
	 * @param string   $trigger Backup trigger label.
	 * @return array{ok:bool}
	 */
	protected function backup_empty_db_items( array $items, $trigger = 'cleanup' ) {
		$result = array( 'ok' => false );
		if ( empty( $items ) ) {
			$result['ok'] = true;
			return $result;
		}
		if ( ! $this->ensure_backup_dir() ) {
			return $result;
		}
		$this->record_backup_manifest( $this->backup_op_key, $trigger, $items );
		global $wpdb;
		$all_ok = true; // Same rule as backup_db_items(): a failed read or write is no backup.

		$now                             = time();
		list( $opt_cond, $opt_params )   = WPOPT_Empty_Scanner::empty_sql_condition( 'option_value' );
		list( $meta_cond, $meta_params ) = WPOPT_Empty_Scanner::empty_sql_condition( 'meta_value' );

		foreach ( $items as $entry ) {
			[ $tipo, $valor ] = array_pad( explode( '::', $entry, 2 ), 2, '' );
			$tipo             = $this->normalize_db_type( (string) $tipo );
			$valor            = (string) $valor;
			if ( '' === $tipo || '' === $valor ) {
				continue;
			}

			if ( 'option' === $tipo ) {
				$opts_table = $this->quote_identifier( $wpdb->options );
				$sql        = "SELECT option_name, option_value, autoload FROM {$opts_table} WHERE option_name = %s AND {$opt_cond}";
				$args       = array_merge( array( $valor ), $opt_params );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name via quote_identifier(); value bound with %s.
				$all_ok = '' === (string) $wpdb->last_error && $all_ok;
				foreach ( (array) $rows as $row ) {
					$all_ok = $this->append_jsonl(
						$this->backup_db_file_path( 'option' ),
						array(
							'ts'       => $now,
							'type'     => 'option',
							'name'     => $row['option_name'],
							'value'    => $row['option_value'],
							'autoload' => $row['autoload'] ?? 'no',
						)
					) && $all_ok;
				}
				continue;
			}

			if ( in_array( $tipo, array( 'post_meta', 'user_meta', 'term_meta' ), true ) ) {
				$table = ( 'post_meta' === $tipo ) ? $wpdb->postmeta : ( 'user_meta' === $tipo ? $wpdb->usermeta : $wpdb->termmeta );
				$tbl   = $this->quote_identifier( $table );
				$sql   = "SELECT * FROM {$tbl} WHERE meta_key = %s AND {$meta_cond}";
				$args  = array_merge( array( $valor ), $meta_params );
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name via quote_identifier(); value bound with %s.
				$all_ok = '' === (string) $wpdb->last_error && $all_ok;
				foreach ( (array) $rows as $row ) {
					$all_ok = $this->append_jsonl(
						$this->backup_db_file_path( $tipo ),
						array(
							'ts'   => $now,
							'type' => $tipo,
							'row'  => $row,
						)
					) && $all_ok;
				}
			}
		}

		$result['ok'] = $all_ok;
		return $result;
	}
}
