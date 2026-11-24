<?php
/**
 * Settings tab and the impact (uninstall preview) report renderer.
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Settings_Trait {

	/**
	 * Generate and output the impact-cleaner HTML report, then exit.
	 * Designed to be opened in a new tab; includes print CSS and auto-print JS.
	 *
	 * @return void
	 */
	public function render_impact_report() {
		if ( ! current_user_can( $this->cap ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'wpoptimizing' ) );
		}

		// --- Gather data ---------------------------------------------------

		$site_name = get_bloginfo( 'name' );
		$site_url  = home_url();
		$last_scan = (int) get_option( 'wpoptimizing_last_scan', 0 );
		$scan_date = $last_scan > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) : __( 'Never', 'wpoptimizing' );
		$gen_date  = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), time() );

		// Helper: parse a scan CSV and return status counts, totals and per-extension stats.
		$parse_scan_csv = static function ( $path ) {
			$counts     = array(
				'orphan'   => 0,
				'inactive' => 0,
				'active'   => 0,
				'none'     => 0,
			);
			$ext_stats  = array();  // slug => ['count'=>N,'size'=>N,'status'=>'...']
			$total_rows = 0;
			$total_size = 0;
			if ( ! file_exists( $path ) ) {
				return compact( 'counts', 'ext_stats', 'total_rows', 'total_size' );
			}
			// Scan CSVs are encrypted at rest; read through the transparent handle
			// (no-op on plaintext) so the report is not parsed from ciphertext.
			$fh = function_exists( 'wpoptimizing_scan_read_handle' ) ? wpoptimizing_scan_read_handle( $path ) : fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- decrypts at-rest CSVs transparently.
			if ( ! $fh ) {
				return compact( 'counts', 'ext_stats', 'total_rows', 'total_size' );
			}
			$first_line = fgets( $fh );
			if ( ! $first_line ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return compact( 'counts', 'ext_stats', 'total_rows', 'total_size' );
			}
			$delim_map = array(
				','  => substr_count( $first_line, ',' ),
				"\t" => substr_count( $first_line, "\t" ),
				';'  => substr_count( $first_line, ';' ),
			);
			arsort( $delim_map );
			$delim  = (string) array_key_first( $delim_map );
			$header = str_getcsv( $first_line, $delim, '"', '\\' );
			$hmap   = $header ? array_flip( array_map( 'strtolower', array_map( 'trim', $header ) ) ) : array();
			$si     = $hmap['project_status'] ?? $hmap['proyecto_estado'] ?? $hmap['status'] ?? null;
			$bi     = $hmap['size_bytes'] ?? $hmap['tamano'] ?? $hmap['size'] ?? null;
			$xi     = $hmap['project_slugs'] ?? $hmap['proyecto_slugs'] ?? $hmap['project_slug'] ?? null;
			$oi     = $hmap['project_origins'] ?? $hmap['project_origin'] ?? null;
			$ci     = $hmap['count_rows'] ?? null;
			while ( ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
				++$total_rows;
				$st   = null !== $si ? strtolower( trim( (string) ( $row[ $si ] ?? '' ) ) ) : '';
				$size = null !== $bi ? (int) ( $row[ $bi ] ?? 0 ) : 0;
				$rcnt = null !== $ci ? max( 1, (int) ( $row[ $ci ] ?? 1 ) ) : 1;
				$slug = null !== $xi ? trim( (string) ( $row[ $xi ] ?? '' ) ) : '';
				$orig = null !== $oi ? strtolower( trim( (string) ( $row[ $oi ] ?? '' ) ) ) : 'plugin';
				if ( isset( $counts[ $st ] ) ) {
					++$counts[ $st ];
				} else {
					++$counts['none'];
				}
				$total_size += $size;
				// Aggregate per extension (use first pipe-separated slug as key).
				if ( '' !== $slug ) {
					$primary = explode( '|', $slug )[0];
					if ( ! isset( $ext_stats[ $primary ] ) ) {
						$ext_stats[ $primary ] = array(
							'count'  => 0,
							'size'   => 0,
							'status' => $st,
							'origin' => $orig,
						);
					}
					$ext_stats[ $primary ]['count'] += $rcnt;
					$ext_stats[ $primary ]['size']  += $size;
					// Upgrade status: active > inactive > orphan > none.
					$priority = array(
						'active'   => 3,
						'inactive' => 2,
						'orphan'   => 1,
						'none'     => 0,
					);
					if ( ( $priority[ $st ] ?? 0 ) > ( $priority[ $ext_stats[ $primary ]['status'] ] ?? 0 ) ) {
						$ext_stats[ $primary ]['status'] = $st;
					}
				}
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return compact( 'counts', 'ext_stats', 'total_rows', 'total_size' );
		};

		$db_data = $parse_scan_csv( WPOPT_CSV_SCAN_DB );
		$fs_data = $parse_scan_csv( WPOPT_CSV_SCAN_FS );

		$status_counts = array(
			'orphan'   => $db_data['counts']['orphan'] + $fs_data['counts']['orphan'],
			'inactive' => $db_data['counts']['inactive'] + $fs_data['counts']['inactive'],
			'active'   => $db_data['counts']['active'] + $fs_data['counts']['active'],
			'none'     => $db_data['counts']['none'] + $fs_data['counts']['none'],
		);
		$total_scanned = $db_data['total_rows'] + $fs_data['total_rows'];
		$total_size    = $db_data['total_size'] + $fs_data['total_size'];

		// Merge extension stats from DB + FS and sort by record count descending.
		$ext_stats = $db_data['ext_stats'];
		foreach ( $fs_data['ext_stats'] as $slug => $s ) {
			if ( ! isset( $ext_stats[ $slug ] ) ) {
				$ext_stats[ $slug ] = $s;
			} else {
				$ext_stats[ $slug ]['count'] += $s['count'];
				$ext_stats[ $slug ]['size']  += $s['size'];
			}
		}
		uasort(
			$ext_stats,
			static function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		$ext_stats = array_slice( $ext_stats, 0, 30, true ); // top 30

		// Read audit log (last 50 entries, newest first).
		$audit_entries = array();
		$audit_file    = WPOPT_DIR . '/audit.log';
		if ( file_exists( $audit_file ) ) {
			$lines = file( $audit_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( is_array( $lines ) ) {
				$lines = array_reverse( array_slice( $lines, -50 ) );
				foreach ( $lines as $line ) {
					$entry = json_decode( $line, true );
					if ( is_array( $entry ) ) {
						$audit_entries[] = $entry;
					}
				}
			}
		}
		$total_deleted = array_sum( array_column( $audit_entries, 'count' ) );

		// --- Output HTML ---------------------------------------------------
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( sprintf( '%s — WPOptimizing Report', $site_name ) ); ?></title>
		<?php echo wpoptimizing_print_report_styles( 960, 4 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS from a single shared source. ?>
</head>
<body>

		<?php
		echo wpoptimizing_print_report_toolbar( 'wpopt-print-btn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped static toolbar markup.
		echo wpoptimizing_print_report_script( 'wpopt-print-btn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static toolbar + script. 
		?>

<div class="report-header">
	<div>
		<div class="report-brand">WPOptimizing</div>
		<div class="report-sub"><?php esc_html_e( 'Cleanup Report', 'wpoptimizing' ); ?></div>
	</div>
	<div class="report-meta">
		<strong><?php echo esc_html( $site_name ); ?></strong><br>
		<?php echo esc_html( $site_url ); ?><br>
		<?php esc_html_e( 'Last analysis:', 'wpoptimizing' ); ?> <?php echo esc_html( $scan_date ); ?><br>
		<?php esc_html_e( 'Generated:', 'wpoptimizing' ); ?> <?php echo esc_html( $gen_date ); ?>
	</div>
</div>

<div class="summary-grid">
	<div class="card">
		<div class="num"><?php echo esc_html( number_format_i18n( $total_scanned ) ); ?></div>
		<div class="lbl"><?php esc_html_e( 'Records analysed', 'wpoptimizing' ); ?></div>
	</div>
	<div class="card">
		<div class="num"><?php echo esc_html( number_format_i18n( $total_deleted ) ); ?></div>
		<div class="lbl"><?php esc_html_e( 'Records cleaned', 'wpoptimizing' ); ?></div>
	</div>
	<div class="card">
		<div class="num"><?php echo esc_html( $total_size > 0 ? size_format( $total_size, 1 ) : '—' ); ?></div>
		<div class="lbl"><?php esc_html_e( 'DB size analysed', 'wpoptimizing' ); ?></div>
	</div>
	<div class="card">
		<div class="num"><?php echo esc_html( number_format_i18n( $status_counts['orphan'] ) ); ?></div>
		<div class="lbl"><?php esc_html_e( 'Orphans found', 'wpoptimizing' ); ?></div>
	</div>
</div>

<h2><?php esc_html_e( 'Analysis summary', 'wpoptimizing' ); ?></h2>
<table>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'Status', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Records', 'wpoptimizing' ); ?></th>
	</tr></thead>
	<tbody>
		<tr><td><span class="badge badge-orphan"><?php esc_html_e( 'Orphan', 'wpoptimizing' ); ?></span></td><td><?php echo esc_html( number_format_i18n( $status_counts['orphan'] ) ); ?></td></tr>
		<tr><td><span class="badge badge-inactive"><?php esc_html_e( 'Inactive', 'wpoptimizing' ); ?></span></td><td><?php echo esc_html( number_format_i18n( $status_counts['inactive'] ) ); ?></td></tr>
		<tr><td><span class="badge badge-active"><?php esc_html_e( 'Active', 'wpoptimizing' ); ?></span></td><td><?php echo esc_html( number_format_i18n( $status_counts['active'] ) ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Pending review', 'wpoptimizing' ); ?></td><td><?php echo esc_html( number_format_i18n( $status_counts['none'] ) ); ?></td></tr>
	</tbody>
</table>
<p class="hint">
		<?php esc_html_e( 'Includes both database and filesystem records. Status reflects raw scan results; the live panel applies additional real-time attribution that may reclassify some entries.', 'wpoptimizing' ); ?>
</p>

		<?php if ( ! empty( $ext_stats ) ) : ?>
<h2><?php esc_html_e( 'Extension breakdown (top 30 by record count)', 'wpoptimizing' ); ?></h2>
<table>
	<thead><tr>
		<th class="col-w35" scope="col"><?php esc_html_e( 'Extension', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Type', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Records', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Size', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Status', 'wpoptimizing' ); ?></th>
	</tr></thead>
	<tbody>
			<?php
			foreach ( $ext_stats as $slug => $s ) :
				$st_class = 'active' === $s['status'] ? 'badge-active' : ( 'inactive' === $s['status'] ? 'badge-inactive' : ( 'orphan' === $s['status'] ? 'badge-orphan' : '' ) );
				$clases   = array(
					'plugin'  => __( 'Plugin', 'wpoptimizing' ),
					'theme'   => __( 'Theme', 'wpoptimizing' ),
					'core'    => __( 'WordPress core', 'wpoptimizing' ),
					'library' => __( 'Library', 'wpoptimizing' ),
				);
				$origin   = $clases[ '' !== $s['origin'] ? $s['origin'] : 'plugin' ] ?? $s['origin'];
				$ext_name = method_exists( $this, 'ext_display_name' ) ? (string) $this->ext_display_name( $slug, (string) $s['origin'] ) : $slug;
				?>
	<tr>
		<td class="mono"><?php echo esc_html( $ext_name !== $slug ? $ext_name . ' (' . $slug . ')' : $slug ); ?></td>
		<td class="cell-url"><?php echo esc_html( $origin ); ?></td>
		<td><?php echo esc_html( number_format_i18n( $s['count'] ) ); ?></td>
		<td><?php echo esc_html( $s['size'] > 0 ? size_format( $s['size'], 1 ) : '—' ); ?></td>
		<td>
				<?php
				if ( $st_class ) :
					?>
			<span class="badge <?php echo esc_attr( $st_class ); ?>"><?php echo esc_html( $s['status'] ); ?></span>
					<?php
else :
	?>
			<?php echo esc_html( $s['status'] ?: '—' ); ?><?php endif; ?></td>
	</tr>
			<?php endforeach; ?>
	</tbody>
</table>
		<?php endif; ?>

		<?php if ( ! empty( $audit_entries ) ) : ?>
<h2><?php esc_html_e( 'Recent cleanup operations', 'wpoptimizing' ); ?></h2>
<table>
	<thead><tr>
		<th scope="col"><?php esc_html_e( 'Date', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'User', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Type', 'wpoptimizing' ); ?></th>
		<th scope="col"><?php esc_html_e( 'Items', 'wpoptimizing' ); ?></th>
	</tr></thead>
	<tbody>
			<?php
			foreach ( $audit_entries as $entry ) :
				$ts    = isset( $entry['ts'] ) ? strtotime( (string) $entry['ts'] ) : 0;
				$ddisp = $ts > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : (string) ( $entry['ts'] ?? '' );
				$act   = (string) ( $entry['action'] ?? '' );
				$login = esc_html( (string) ( $entry['login'] ?? '' ) );
				$cnt   = (int) ( $entry['count'] ?? 0 );
				$badge = 'delete' === substr( $act, 0, 6 ) ? 'badge-delete' : '';
				?>
	<tr>
		<td><?php echo esc_html( $ddisp ); ?></td>
		<td><?php echo esc_html( $login ); ?></td>
		<td><span class="badge <?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $act ); ?></span></td>
		<td><?php echo esc_html( number_format_i18n( $cnt ) ); ?></td>
	</tr>
			<?php endforeach; ?>
	</tbody>
</table>
		<?php else : ?>
<p class="hint"><em><?php esc_html_e( 'No cleanup operations recorded yet. Run a cleanup to generate history.', 'wpoptimizing' ); ?></em></p>
		<?php endif; ?>

<div class="footer">
	<span><?php /* translators: %s: plugin version. */ printf( esc_html__( 'Generated by WPOptimizing %s', 'wpoptimizing' ), esc_html( WPOPTIMIZING_VERSION ) ); ?></span>
	<span><?php echo esc_html( $site_url ); ?></span>
</div>

</body>
</html>
		<?php
		exit;
	}

	/**
	 * Render Settings tab.
	 *
	 * @return void
	 */
	protected function render_settings_tab() {
		$max_per_type    = $this->get_backup_max_per_type();
		$max_days        = $this->get_backup_max_days();
		$gc_settings     = $this->get_general_cleanup_service()->get_settings();
		$gc_tables       = $this->get_general_cleanup_service()->get_optimizable_tables();
		$gc_schedule     = $this->get_general_cleanup_service()->get_schedule();
		$gc_sched_config = $this->get_general_cleanup_service()->get_schedule_config();

		// Direct link back to the dashboard (Settings has no tab menu) + replay wizard.
		echo '<div class="wpopt-btn-row">';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=wpoptimizing' ) ) . '" class="button">&larr; ' . esc_html__( 'Back to WPOptimizing', 'wpoptimizing' ) . '</a>';
		echo '<form method="post">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<button type="submit" name="wpopt_replay_wizard" value="1" class="button">' . esc_html__( 'Replay welcome wizard', 'wpoptimizing' ) . '</button>';
		echo '</form>';
		echo '</div>';
		// Lite no tiene licencia, programación ni integraciones: su cabecera no las nombra.
		$settings_desc = __( 'Configure cleanup options and backups. Quick profiles below apply a recommended configuration with one click.', 'wpoptimizing' );
		$this->render_section_header(
			__( 'Settings', 'wpoptimizing' ),
			$settings_desc,
			array(
				'icon' => 'admin-settings',
				'doc'  => 'wpopt-doc-settings',
			)
		);

		// Section navigation. On DESKTOP the vertical menu is rendered in the right
		// column (render_settings_section_nav, below the info box) so it steals no
		// content width. Here we print the MOBILE version at the top of the content:
		// the same dark hamburger + panel as the plugin's main menu (reuses its
		// .wpopt-layout.wpopt-mobile-open / .wpopt-sidebar styling). Each link closes
		// the panel after the tap.
		echo '<div class="wpopt-layout wpopt-settings-mobilenav">';
		echo '<button type="button" class="wpopt-mobile-toggle" aria-controls="wpopt-settings-mobilenav-panel" aria-expanded="false" onclick="var l=this.closest(&quot;.wpopt-layout&quot;);if(l){var o=l.classList.toggle(&quot;wpopt-mobile-open&quot;);this.setAttribute(&quot;aria-expanded&quot;,o?&quot;true&quot;:&quot;false&quot;);}" aria-label="' . esc_attr__( 'Menu', 'wpoptimizing' ) . '">' . wpoptimizing_icon( 'menu' ) . ' ' . esc_html__( 'Menu', 'wpoptimizing' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
		// Each link closes the panel and resets the toggle's aria-expanded.
		$this->render_settings_nav_tabs( 'nav-tab-wrapper wpopt-sidebar', 'onclick="var l=this.closest(&quot;.wpopt-layout&quot;);if(l){l.classList.remove(&quot;wpopt-mobile-open&quot;);var b=l.querySelector(&quot;.wpopt-mobile-toggle&quot;);if(b)b.setAttribute(&quot;aria-expanded&quot;,&quot;false&quot;);}"', 'wpopt-settings-mobilenav-panel' );
		echo '</div>';

		// The id is what the save buttons outside this form point at (form="…"): the one under
		// the section menu on desktop, and the bar fixed to the bottom on mobile.
		echo '<form method="post" class="wpopt-settings-form" id="wpopt-settings-main">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="settings" />';

		// Pro opens the form with the licence; Lite has none, so the same block is just Backups.
		$first_head = __( 'Backups', 'wpoptimizing' );
		$first_icon = 'backup';

		$this->render_section_header(
			$first_head,
			array(
				'icon'   => $first_icon,
				'doc'    => 'wpopt-doc-settings',
				'anchor' => 'wpopt-set-license',
			)
		);
		echo '<table class="form-table wpopt-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpopt_backup_max_per_type">' . esc_html__( 'Max backups per type', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_backup_max_per_type" aria-describedby="wpopt_backup_max_per_type-desc" min="1" max="100" name="wpopt_backup_max_per_type" value="' . esc_attr( (string) $max_per_type ) . '" />';
		echo '<p id="wpopt_backup_max_per_type-desc" class="description">' . esc_html__( 'How many backups to keep for each operation type. Older ones are pruned automatically.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpopt_backup_max_days">' . esc_html__( 'Max age (days)', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_backup_max_days" aria-describedby="wpopt_backup_max_days-desc" min="1" max="3650" name="wpopt_backup_max_days" value="' . esc_attr( (string) $max_days ) . '" />';
		echo '<p id="wpopt_backup_max_days-desc" class="description">' . esc_html__( 'Backups older than this many days are deleted automatically.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		$max_mb = (int) get_option( 'wpoptimizing_backup_max_mb', 2048 );
		echo '<tr><th scope="row"><label for="wpopt_backup_max_mb">' . esc_html__( 'Max backup size (MB)', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_backup_max_mb" aria-describedby="wpopt_backup_max_mb-desc" min="0" max="1000000" name="wpopt_backup_max_mb" value="' . esc_attr( (string) $max_mb ) . '" />';
		echo '<p id="wpopt_backup_max_mb-desc" class="description">' . esc_html__( 'Every change of your data is backed up first. When the backups would grow past this size, the change (or the cleanup) stops instead of filling the disk. 0 means no limit.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		$notif_email = (string) get_option( 'wpoptimizing_notification_email', '' );
		echo '<tr><th scope="row"><label for="wpopt_notification_email">' . esc_html__( 'Notification email', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="text" id="wpopt_notification_email" aria-describedby="wpopt_notification_email-desc" class="regular-text" name="wpopt_notification_email" value="' . esc_attr( $notif_email ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" />';
		// Lite no envía avisos de licencia.
		$notif_desc = __( 'Where WPOptimizing sends the periodic report. For agencies managing client sites: route it to the maintainer instead of the site owner. Separate several addresses with commas. Leave empty to use the site admin email. Network alerts go to the network administrator instead.', 'wpoptimizing' );
		echo '<p id="wpopt_notification_email-desc" class="description">' . esc_html( $notif_desc ) . '</p>';
		echo '</td></tr>';
		// Periodic maintenance report (B7): frequency + a "send a test now" button.
		if ( function_exists( 'wpoptimizing_report_frequencies' ) ) {
			$rep_freq = wpoptimizing_report_frequency();
			echo '<tr><th scope="row"><label for="wpopt_report_frequency">' . esc_html__( 'Maintenance report', 'wpoptimizing' ) . '</label></th><td>';
			echo '<select id="wpopt_report_frequency" name="wpopt_report_frequency" class="wpopt-select2 wpopt-select-290">';
			foreach ( wpoptimizing_report_frequencies() as $rf_key => $rf_label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $rf_key ), selected( $rep_freq, $rf_key, false ), esc_html( $rf_label ) );
			}
			echo '</select> ';
			echo '<button type="submit" name="wpopt_report_test" value="1" class="button">' . esc_html__( 'Send a test now', 'wpoptimizing' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Email a recurring summary to the notification address above: the Health Score and its trend, key database/media metrics, unusual table growth and the activity since the last report. Off by default. The test is sent immediately to check delivery and does not change the schedule.', 'wpoptimizing' ) . '</p>';
			echo '</td></tr>';
		}
		// Background sweeper (D5): incremental expired-transient housekeeping.
		if ( function_exists( 'wpoptimizing_sweeper_enabled' ) ) {
			$sweep_on = wpoptimizing_sweeper_enabled();
			echo '<tr><th scope="row">' . esc_html__( 'Background sweeper', 'wpoptimizing' ) . '</th><td>';
			echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_sweeper_enabled" value="1" ' . checked( $sweep_on, true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Continuously clear expired transients in the background', 'wpoptimizing' ) . '</span></label>';
			echo '<p class="description">' . esc_html__( 'Removes expired transients a small batch at a time on normal admin requests, so a site with a leaky transient producer never piles up thousands of dead rows between scans. Pure housekeeping — WordPress regenerates any transient on demand — and it does nothing when a persistent object cache is active. Off by default.', 'wpoptimizing' ) . '</p>';
			echo '</td></tr>';
		}
		$wpopt_del_data = '0' !== (string) get_option( 'wpoptimizing_delete_data_on_uninstall', '1' );
		echo '<tr><th scope="row">' . esc_html__( 'On uninstall', 'wpoptimizing' ) . '</th><td>';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_delete_data_on_uninstall" value="1" ' . checked( $wpopt_del_data, true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Delete ALL plugin data when the plugin is deleted', 'wpoptimizing' ) . '</span></label>';
		echo '<p class="description">' . esc_html__( 'When you delete the plugin from Plugins, remove every trace it created: settings, transients, scheduled tasks, the object-cache and database drop-ins, all backups, scan data and the generated WebP/AVIF copies. Your already-optimized images are kept (only the extra next-gen copies and backups are removed). Leave this on to keep the site clean; turn it off only if you plan to reinstall and want to keep your settings and backups.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		// Quick profiles: one click applies a recommended set of cleanup tasks.
		if ( class_exists( 'WPOPT_General_Cleanup' ) ) {
			$gc_profiles        = WPOPT_General_Cleanup::cleanup_profiles();
			$gc_current_profile = (string) get_option( 'wpoptimizing_general_cleanup_profile', '' );
			// Analyze the site (active plugins) to recommend a preset and preselect it
			// when none has been applied yet, so the right choice is offered up front.
			$gc_signals     = WPOPT_General_Cleanup::detect_site_signals();
			$gc_recommended = WPOPT_General_Cleanup::recommend_profile( $gc_signals );
			$gc_selected    = '' !== $gc_current_profile ? $gc_current_profile : $gc_recommended;
			$this->render_section_header(
				__( 'Quick profile', 'wpoptimizing' ),
				array(
					'icon'   => 'clipboard',
					'doc'    => 'wpopt-doc-settings',
					'anchor' => 'wpopt-set-profile',
				)
			);
			echo '<table class="form-table wpopt-table"><tbody><tr><th scope="row">' . esc_html__( 'Apply a preset', 'wpoptimizing' ) . '</th><td>';
			echo '<select name="wpopt_gc_profile" class="wpopt-select2 wpopt-select-290">';
			foreach ( $gc_profiles as $gc_pkey => $gc_p ) {
				$gc_opt_label = ( $gc_pkey === $gc_recommended )
					/* translators: %s: cleanup profile label. */
					? sprintf( __( '%s — recommended for your site', 'wpoptimizing' ), $gc_p['label'] )
					: $gc_p['label'];
				echo '<option value="' . esc_attr( $gc_pkey ) . '"' . selected( $gc_selected, $gc_pkey, false ) . '>' . esc_html( $gc_opt_label ) . '</option>';
			}
			echo '</select>';
			echo '<button type="submit" name="wpopt_gc_apply_profile" value="1" class="button button-secondary wpopt-field-action">' . esc_html__( 'Apply profile', 'wpoptimizing' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Applies a recommended set of cleanup tasks for your site type. Performance tuning (object cache, query deduplication and Heartbeat) is configured automatically for each profile. You can still fine-tune the options below before saving.', 'wpoptimizing' ) . '</p>';
			// Tailored notes about the detected environment (SEO/cache/image plugins).
			$gc_notes = WPOPT_General_Cleanup::recommendation_notes( $gc_signals );
			if ( ! empty( $gc_notes ) ) {
				echo '<ul class="wpopt-disc-list wpopt-muted wpopt-meta">';
				foreach ( $gc_notes as $gc_note ) {
					echo '<li>' . wpoptimizing_icon( 'info-outline', 'wpopt-icon-inline' ) . ' ' . esc_html( $gc_note ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
				}
				echo '</ul>';
			}
			// Read-only summary: what each profile also tunes on the performance side.
			$gc_perf_map = WPOPT_General_Cleanup::profile_performance_map();
			$hb_labels   = array(
				'default' => __( 'Heartbeat: default', 'wpoptimizing' ),
				'slow'    => __( 'Heartbeat: slow', 'wpoptimizing' ),
				'off'     => __( 'Heartbeat: off', 'wpoptimizing' ),
			);
			echo '<ul class="wpopt-disc-list wpopt-muted wpopt-meta">';
			foreach ( $gc_profiles as $gc_pkey => $gc_p ) {
				$pf         = isset( $gc_perf_map[ $gc_pkey ] ) ? $gc_perf_map[ $gc_pkey ] : array();
				$perf_parts = array();
				if ( ! empty( $pf['enable_object_cache'] ) ) {
					$perf_parts[] = esc_html__( 'object cache', 'wpoptimizing' );
				}
				if ( ! empty( $pf['enable_query_dedup'] ) ) {
					$perf_parts[] = esc_html__( 'query deduplication', 'wpoptimizing' );
				}
				if ( ! empty( $pf['enable_admin_counts'] ) ) {
					$perf_parts[] = esc_html__( 'cached comment counts', 'wpoptimizing' );
				}
				if ( ! empty( $pf['speculation'] ) && 'off' !== $pf['speculation'] ) {
					$perf_parts[] = esc_html__( 'speculative loading', 'wpoptimizing' );
				}
				$hb_key       = isset( $pf['heartbeat'] ) ? (string) $pf['heartbeat'] : 'default';
				$perf_parts[] = isset( $hb_labels[ $hb_key ] ) ? $hb_labels[ $hb_key ] : $hb_key;
				$perf_note    = empty( $perf_parts ) ? '' : ' (' . implode( ', ', $perf_parts ) . ')';
				echo '<li><strong>' . esc_html( $gc_p['label'] ) . ':</strong> ' . esc_html( $gc_p['description'] ) . esc_html( $perf_note ) . '</li>';
			}
			echo '</ul>';
			echo '</td></tr></tbody></table>';
		}

		$this->render_section_header(
			__( 'General cleanup options', 'wpoptimizing' ),
			array(
				'icon'   => 'admin-generic',
				'doc'    => 'wpopt-doc-settings',
				'anchor' => 'wpopt-set-cleanup',
			)
		);
		echo '<table class="form-table wpopt-table"><tbody>';
		echo '<tr><th scope="row"><label for="wpopt_gc_keep_revisions">' . esc_html__( 'Revisions to keep per post', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_gc_keep_revisions" min="0" max="100" name="wpopt_gc_keep_revisions" value="' . esc_attr( (string) (int) $gc_settings['keep_revisions'] ) . '" />';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpopt_gc_revisions_max_age_days">' . esc_html__( 'Delete revisions older than (days)', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_gc_revisions_max_age_days" aria-describedby="wpopt_gc_revisions_max_age_days-desc" min="0" max="3650" name="wpopt_gc_revisions_max_age_days" value="' . esc_attr( (string) (int) $gc_settings['revisions_max_age_days'] ) . '" />';
		echo '<p id="wpopt_gc_revisions_max_age_days-desc" class="description">' . esc_html__( 'Extra guard: revisions beyond the kept count are only deleted when older than this many days. Set 0 to disable (delete by count only).', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpopt_gc_keep_auto_drafts">' . esc_html__( 'Auto-drafts to keep', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_gc_keep_auto_drafts" aria-describedby="wpopt_gc_keep_auto_drafts-desc" min="0" max="500" name="wpopt_gc_keep_auto_drafts" value="' . esc_attr( (string) (int) $gc_settings['keep_auto_drafts'] ) . '" />';
		echo '<p id="wpopt_gc_keep_auto_drafts-desc" class="description">' . esc_html__( 'If greater than 0, all auto-drafts except the latest N will be deleted.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpopt_gc_auto_drafts_max_age_days">' . esc_html__( 'Delete auto-drafts older than (days)', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_gc_auto_drafts_max_age_days" aria-describedby="wpopt_gc_auto_drafts_max_age_days-desc" min="0" max="3650" name="wpopt_gc_auto_drafts_max_age_days" value="' . esc_attr( (string) (int) $gc_settings['auto_drafts_max_age_days'] ) . '" />';
		echo '<p id="wpopt_gc_auto_drafts_max_age_days-desc" class="description">' . esc_html__( 'Used when "Auto-drafts to keep" is 0. Set 0 to disable age-based deletion.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wpopt_gc_trash_min_age_days">' . esc_html__( 'Keep trash and spam for at least (days)', 'wpoptimizing' ) . '</label></th><td>';
		echo '<input type="number" id="wpopt_gc_trash_min_age_days" aria-describedby="wpopt_gc_trash_min_age_days-desc" min="0" max="3650" name="wpopt_gc_trash_min_age_days" value="' . esc_attr( (string) (int) $gc_settings['trash_min_age_days'] ) . '" />';
		echo '<p id="wpopt_gc_trash_min_age_days-desc" class="description">' . esc_html__( 'Posts and comments sent to the trash or marked as spam more recently than this are left alone, so a mistake can still be undone. 0 empties them whatever their age.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Database cleanup tasks', 'wpoptimizing' ) . '</th><td>';
		$gc_task_options = array(
			'delete_auto_drafts'          => __( 'Delete auto-drafts', 'wpoptimizing' ),
			'delete_trashed_posts'        => __( 'Delete trashed posts', 'wpoptimizing' ),
			'delete_spam_comments'        => __( 'Delete spam comments', 'wpoptimizing' ),
			'delete_trashed_comments'     => __( 'Delete trashed comments', 'wpoptimizing' ),
			'delete_unapproved_comments'  => __( 'Delete unapproved comments (includes those awaiting moderation, not just spam)', 'wpoptimizing' ),
			'delete_pingbacks'            => __( 'Delete pingbacks', 'wpoptimizing' ),
			'delete_trackbacks'           => __( 'Delete trackbacks', 'wpoptimizing' ),
			'delete_expired_transients'   => __( 'Delete expired transients', 'wpoptimizing' ),
			'delete_all_transients'       => __( 'Delete all transients, including those that have not expired yet', 'wpoptimizing' ),
			'delete_oembed_cache'         => __( 'Delete oEmbed cache', 'wpoptimizing' ),
			'delete_unused_tags'          => __( 'Delete unused tags', 'wpoptimizing' ),
			'delete_orphan_elements'      => __( 'Delete orphan elements', 'wpoptimizing' ),
			'delete_duplicate_meta'       => __( 'Delete duplicate metadata (keeps the oldest copy)', 'wpoptimizing' ),
			'delete_action_scheduler'     => __( 'Delete finished Action Scheduler logs (completed/failed/cancelled, 30+ days)', 'wpoptimizing' ),
			'delete_wc_sessions'          => __( 'WooCommerce: delete expired customer sessions', 'wpoptimizing' ),
			'delete_wc_orphan_variations' => __( 'WooCommerce: delete orphaned product variations (their product no longer exists)', 'wpoptimizing' ),
			'optimize_tables'             => __( 'Optimize database tables', 'wpoptimizing' ),
		);
		// Clear toggle switches (one per task) instead of a multi-select: every
		// state is visible at a glance. Same field name (wpopt_gc_tasks[]) so the
		// save handler is unchanged; unchecked = absent = off.
		echo '<div class="wpopt-toggle-grid" role="group" aria-label="' . esc_attr__( 'Database cleanup tasks', 'wpoptimizing' ) . '">';
		foreach ( $gc_task_options as $task_key => $task_label ) {
			// Hide the Action Scheduler option entirely when it is not installed.
			if ( 'delete_action_scheduler' === $task_key && ! WPOPT_General_Cleanup::has_action_scheduler() ) {
				continue;
			}
			// Same for the store tasks: HIDDEN, not disabled. On a site with no shop they are
			// noise, and on a site where WooCommerce is merely deactivated they are a trap —
			// the tables are still there, full of data the owner may be about to switch back on.
			if ( 0 === strpos( $task_key, 'delete_wc_' ) && ! WPOPT_General_Cleanup::has_woocommerce() ) {
				continue;
			}
			$is_active = ! empty( $gc_settings[ $task_key ] );
			printf(
				'<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_gc_tasks[]" value="%1$s"%2$s /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">%3$s</span></label>',
				esc_attr( $task_key ),
				checked( $is_active, true, false ),
				esc_html( $task_label )
			);
		}
		echo '</div>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Detailed cleanup log', 'wpoptimizing' ) . '</th><td>';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_gc_generate_report" value="1" ' . checked( ! empty( $gc_settings['generate_report'] ), true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Generate detailed log', 'wpoptimizing' ) . '</span></label>';
		echo '<p class="description">' . esc_html__( 'Summary report is always stored. Enable this to include per-step event lines.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Tables excluded from optimization', 'wpoptimizing' ) . '</th><td>';
		echo '<div class="wpopt-select-290">';
		echo '<select name="wpopt_gc_excluded_tables[]" class="wpopt-select2" multiple="multiple">';
		echo '<option value="__none__"' . selected( empty( $gc_settings['excluded_optimize_tables'] ), true, false ) . '>' . esc_html__( 'None', 'wpoptimizing' ) . '</option>';
		foreach ( $gc_tables as $table_name ) {
			echo '<option value="' . esc_attr( $table_name ) . '"' . selected( in_array( $table_name, (array) $gc_settings['excluded_optimize_tables'], true ), true, false ) . '>' . esc_html( $table_name ) . '</option>';
		}
		echo '</select>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'Selected tables will be skipped during optimization.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		// Automatic background analysis (off-peak). Keeps every site analysed without the user
		// ever waiting on a blocking manual scan; heavy stages run only in the quiet window.
		$scan_schedule = (string) get_option( 'wpoptimizing_scan_schedule', 'daily' );
		if ( ! in_array( $scan_schedule, array( 'off', 'daily', 'weekly' ), true ) ) {
			$scan_schedule = 'daily';
		}
		$op_start = max( 0, min( 23, (int) get_option( 'wpoptimizing_scan_offpeak_start', 2 ) ) );
		$op_end   = max( 0, min( 23, (int) get_option( 'wpoptimizing_scan_offpeak_end', 6 ) ) );
		echo '<tr><th scope="row">' . esc_html__( 'Automatic analysis', 'wpoptimizing' ) . '</th><td>';
		echo '<div class="wpopt-select-290">';
		echo '<select name="wpopt_scan_schedule" class="wpopt-select2">';
		echo '<option value="off"' . selected( $scan_schedule, 'off', false ) . '>' . esc_html__( 'Off (manual only)', 'wpoptimizing' ) . '</option>';
		echo '<option value="daily"' . selected( $scan_schedule, 'daily', false ) . '>' . esc_html__( 'Daily', 'wpoptimizing' ) . '</option>';
		echo '<option value="weekly"' . selected( $scan_schedule, 'weekly', false ) . '>' . esc_html__( 'Weekly', 'wpoptimizing' ) . '</option>';
		echo '</select>';
		echo '</div>';
		echo '<div class="wpopt-sched-row">';
		echo '<label class="wpopt-field-label">' . esc_html__( 'Low-traffic window', 'wpoptimizing' ) . '</label>';
		echo '<span class="wpopt-window-wrap">';
		echo '<select name="wpopt_scan_offpeak_start" class="wpopt-select2 wpopt-window-sel" aria-label="' . esc_attr__( 'Window start hour', 'wpoptimizing' ) . '">';
		for ( $wpopt_h = 0; $wpopt_h < 24; $wpopt_h++ ) {
			echo '<option value="' . esc_attr( (string) $wpopt_h ) . '"' . selected( $op_start, $wpopt_h, false ) . '>' . esc_html( sprintf( '%02d:00', $wpopt_h ) ) . '</option>';
		}
		echo '</select>';
		echo '<span class="wpopt-window-sep" aria-hidden="true">&ndash;</span>';
		echo '<select name="wpopt_scan_offpeak_end" class="wpopt-select2 wpopt-window-sel" aria-label="' . esc_attr__( 'Window end hour', 'wpoptimizing' ) . '">';
		for ( $wpopt_h = 0; $wpopt_h < 24; $wpopt_h++ ) {
			echo '<option value="' . esc_attr( (string) $wpopt_h ) . '"' . selected( $op_end, $wpopt_h, false ) . '>' . esc_html( sprintf( '%02d:00', $wpopt_h ) ) . '</option>';
		}
		echo '</select>';
		echo '</span>';
		echo '</div>';
		echo '<p class="description">' . esc_html__( 'WPOptimizing analyses your site automatically in the background, only during this low-traffic window (site timezone), so the lists stay fresh and you never wait on a manual scan. A manual scan always runs immediately, regardless of this window.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';

		// Scheduled cleanup (with its per-task frequency) and the cleanup on database growth are
		// Pro (23/10/2026). Lite runs the cleanup by hand; Pro without a license shows the lock.
		$sched_pro = false;
		if ( ! $sched_pro ) {
			$sched_note = __( 'Scheduled cleanup (hourly to monthly, with a frequency per task) and automatic cleanup when the database grows are not included in this plugin. Run the cleanup from General cleanup whenever you need it.', 'wpoptimizing' );
			echo '<tr><th scope="row">' . esc_html__( 'Automatic maintenance', 'wpoptimizing' ) . '</th><td>';
			$this->render_pro_note( $sched_note );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// --- Front-end performance: moved to Speed › Page & browser ------------
		// The options live there now, next to minify / delay JS / critical CSS, with their own
		// save (Settings saves everything it shows, and treats a missing checkbox as "off", so
		// they could not just be hidden from this form). A pointer stays here for whoever looks.
		// Lite no lleva precarga, incrustados diferidos, Google Fonts, CDN, retraso de JS ni CSS crítico.
		$fe_moved_desc = __( 'Speculative loading, image loading, resource hints and Server-Timing are now under Speed › Page & browser, next to minify.', 'wpoptimizing' );
		$this->render_section_header(
			__( 'Front-end performance', 'wpoptimizing' ),
			$fe_moved_desc,
			array(
				'icon'   => 'editor-code',
				'doc'    => 'wpopt-doc-frontend',
				'anchor' => 'wpopt-set-frontend',
			)
		);
		echo '<p><a class="button button-secondary" href="' . esc_url(
			add_query_arg(
				array(
					'page' => 'wpoptimizing',
					'tab'  => 'frontend',
					'fsec' => 'loading',
				),
				admin_url( 'admin.php' )
			)
		) . '">' . esc_html__( 'Open Speed › Page & browser', 'wpoptimizing' ) . '</a></p>';

		// --- Core tweaks (disable unused WordPress features) ------------------
		if ( function_exists( 'wpoptimizing_core_tweaks_registry' ) ) {
			$tw_on       = array_fill_keys( wpoptimizing_core_tweaks_enabled_ids(), true );
			$tw_registry = wpoptimizing_core_tweaks_offered();
			$this->render_section_header(
				__( 'Core tweaks', 'wpoptimizing' ),
				__( 'Turn off WordPress features you do not use to trim front-end weight and head/feed noise. Every option is off by default and fully reversible — nothing is deleted.', 'wpoptimizing' ),
				array(
					'icon'   => 'admin-customizer',
					'doc'    => 'wpopt-doc-tweaks',
					'anchor' => 'wpopt-set-tweaks',
				)
			);
			echo '<table class="form-table wpopt-table" role="presentation"><tbody>';
			foreach ( wpoptimizing_core_tweaks_tiers() as $tw_tier_key => $tw_tier ) {
				// Skip a tier with no tweaks in it (keeps the layout clean as the registry changes).
				$tw_tier_has = false;
				foreach ( $tw_registry as $tw ) {
					if ( $tw['tier'] === $tw_tier_key && 'head' === ( isset( $tw['group'] ) ? $tw['group'] : 'head' ) ) {
						$tw_tier_has = true;
						break;
					}
				}
				if ( ! $tw_tier_has ) {
					continue;
				}
				echo '<tr><th scope="row">' . esc_html( $tw_tier['label'] ) . '<span class="wpopt-tier-note">' . esc_html( $tw_tier['note'] ) . '</span></th><td>';
				foreach ( $tw_registry as $tw_id => $tw ) {
					if ( $tw['tier'] !== $tw_tier_key || 'head' !== ( isset( $tw['group'] ) ? $tw['group'] : 'head' ) ) {
						continue;
					}
					echo '<label class="wpopt-toggle wpopt-tweak-row">'
						. '<input type="checkbox" class="wpopt-toggle-input" name="wpopt_tweaks[' . esc_attr( $tw_id ) . ']" value="1" ' . checked( isset( $tw_on[ $tw_id ] ), true, false ) . ' />'
						. '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>'
						. '<span class="wpopt-toggle-label"><strong>' . esc_html( $tw['label'] ) . '</strong><span class="wpopt-tweak-desc">' . esc_html( $tw['desc'] ) . '</span></span>'
						. '</label>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}


		// --- Webhooks ---------------------------------------------------------
		// Pro (23/10/2026): Lite keeps the section with its anchor and only the note.
		$wh_pro = false;
		if ( ! $wh_pro ) {
			$this->render_section_header(
				__( 'Webhooks', 'wpoptimizing' ),
				__( 'Send a signed JSON POST to an external URL when WPOptimizing finishes a scan, a cleanup, a deletion or a dataset upload (e.g. Slack, Zapier, n8n or your own endpoint).', 'wpoptimizing' ),
				array(
					'icon'   => 'rest-api',
					'anchor' => 'wpopt-set-webhooks',
				)
			);
			$wh_note = __( 'Webhooks are not included in this plugin.', 'wpoptimizing' );
			$this->render_pro_note( $wh_note );
		}

		// --- Compatibility with other plugins ---------------------------------
		$this->render_compat_section();

		// --- Data sharing (consent) -------------------------------------------
		$share_on = function_exists( 'wpoptimizing_has_share_consent' ) ? wpoptimizing_has_share_consent() : false;
		$this->render_section_header(
			__( 'Shared attribution dataset', 'wpoptimizing' ),
			array(
				'icon'   => 'share',
				'doc'    => 'wpopt-doc-dataset',
				'anchor' => 'wpopt-set-dataset',
			)
		);
		echo '<p class="description wpopt-desc-mb">'
			. esc_html__( 'WPOptimizing can contribute attribution metadata to a shared dataset that improves accuracy for everyone. Only names (of options, meta keys, tables and files), their sizes and how often they are seen are sent, together with your site’s domain, which authorises the exchange — never option values, post or user content, or any record contents. Sharing is opt-in and reciprocal: while it is off, the plugin keeps working with locally captured attribution but does not exchange data with the shared dataset.', 'wpoptimizing' )
			. '</p>';
		echo '<table class="form-table wpopt-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Contribute & receive', 'wpoptimizing' ) . '</th><td>';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_share_consent" value="1" ' . checked( $share_on, true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>'
			. '<span class="wpopt-toggle-label">' . esc_html__( 'Share attribution metadata to improve and receive the shared dataset.', 'wpoptimizing' ) . '</span></label>';
		echo '<p class="description">'
			. esc_html__( 'You can turn this off at any time.', 'wpoptimizing' ) . ' '
			. esc_html__( 'See exactly what is shared and what is never sent:', 'wpoptimizing' ) . ' '
			. $this->doc_link( 'wpopt-doc-dataset' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link() returns self-escaped markup (esc_attr on every attribute).
			. '</p>';
		echo '</td></tr>';
		// The manual sync only makes sense while sharing is on (both the upload and
		// the dataset download are gated by consent), so it is shown only then.
		if ( $share_on ) {
			// Freshness of EVERY layer, not just one: green < 24 h, amber < 7 d, red older,
			// and — the one that matters — "Not downloaded yet" for a layer that never
			// arrived. A layer can die silently on the server (an artifact that is never
			// built) or on the client (a filename with no mapping), and both have happened.
			// The user, and we, must be able to see it at a glance instead of discovering it
			// months later by accident.
			if ( function_exists( 'wpoptimizing_kb_layers_freshness' ) ) {
				$kb_labels = array(
					'fresh'   => __( 'Up to date', 'wpoptimizing' ),
					'aging'   => __( 'A few days old', 'wpoptimizing' ),
					'stale'   => __( 'Outdated', 'wpoptimizing' ),
					'missing' => __( 'Not downloaded yet', 'wpoptimizing' ),
				);
				echo '<tr><th scope="row">' . esc_html__( 'Knowledge base', 'wpoptimizing' ) . '</th><td>';
				echo '<ul class="wpopt-kb-layers">';
				foreach ( wpoptimizing_kb_layers_freshness() as $layer ) {
					$status = (string) $layer['status'];
					$label  = isset( $kb_labels[ $status ] ) ? $kb_labels[ $status ] : $status;
					echo '<li>';
					echo '<span class="wpopt-kb-layer-name">' . esc_html( (string) $layer['label'] ) . '</span>';
					echo '<span class="wpopt-freshness wpopt-freshness--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
					if ( ! empty( $layer['exists'] ) ) {
						echo ' <span class="description">' . esc_html(
							sprintf(
							/* translators: %s: human-readable time-ago, e.g. "3 hours". */
								__( 'updated %s ago', 'wpoptimizing' ),
								human_time_diff( (int) $layer['mtime'] )
							)
						) . '</span>';
					}
					// Y por qué no llegó, cuando no llegó: ocho motivos distintos se veían
					// todos como el mismo rótulo rojo, que es como `manifest` pasó inadvertida.
					if ( '' !== (string) ( $layer['reason'] ?? '' ) ) {
						echo ' <span class="description wpopt-kb-layer-reason">' . esc_html( '— ' . (string) $layer['reason'] ) . '</span>';
					}
					echo '</li>';
				}
				echo '</ul>';
				echo '<p class="description">' . esc_html__( 'Your local copy of each layer of the shared dataset. They refresh automatically every day; use "Sync dataset now" to update them immediately. A layer that stays on "Not downloaded yet" is not reaching your site — tell us if you see one.', 'wpoptimizing' ) . '</p>';
				echo '</td></tr>';
			}
			echo '<tr><th scope="row">' . esc_html__( 'Sync now', 'wpoptimizing' ) . '</th><td>';
			echo '<button type="submit" name="wpopt_sync_kb" value="1" class="button">' . esc_html__( 'Sync dataset now', 'wpoptimizing' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Uploads your contributions and refreshes your local copy of the shared dataset now, instead of waiting for the daily schedule. Save any sharing change first.', 'wpoptimizing' ) . '</p>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// Mobile: the save button is a bar fixed to the bottom of the screen. On desktop it sits
		// under the section menu instead (render_settings_section_nav()); CSS shows one or the
		// other. It used to be only here, at the end of a very long form.
		echo '<div class="wpopt-settings-savebar">';
		$this->render_settings_save_button();
		echo '</div>';
		echo '</form>';

		$this->render_settings_portability();
		$this->render_settings_importer();

		// JS: show/hide time config fields based on schedule selection.
		?>
		<?php ob_start(); ?>
		jQuery(function($){
			var $sel = $('#wpopt-gc-schedule-select');
			function wpoptSchedToggle(){
				var v = $sel.val();
				var timed = ['twicedaily','daily','weekly','monthly'].indexOf(v) !== -1;
				$('#wpopt-gc-sched-time-wrap').toggleClass('wpopt-hidden', !timed);
				$('#wpopt-gc-sched-dow-row').toggleClass('wpopt-hidden', v !== 'weekly');
				$('#wpopt-gc-sched-dom-row').toggleClass('wpopt-hidden', v !== 'monthly');
			}
			$sel.on('change select2:select', wpoptSchedToggle);
			wpoptSchedToggle();
		});
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * Front-end performance options (Speed › Page & browser › Loading & delivery): speculative
	 * loading, image loading, preload, lazy embeds, Server-Timing, resource hints, Google Fonts
	 * and CDN rewriting. Rows only; the caller wraps them in its own form, which must carry the
	 * wpopt_frontend_save action.
	 *
	 * @return void
	 */
	protected function render_frontend_perf_rows() {
		$fp = wpoptimizing_frontend_perf_settings();
		echo '<tr><th scope="row"><label for="wpopt-fp-speculation">' . esc_html__( 'Speculative loading', 'wpoptimizing' ) . '</label></th><td>';
		echo '<div class="wpopt-select-290"><select name="wpopt_fp_speculation" id="wpopt-fp-speculation" class="wpopt-select2">';
		foreach ( array(
			'off'          => __( 'Off', 'wpoptimizing' ),
			'conservative' => __( 'Conservative (prefetch on click)', 'wpoptimizing' ),
			'eager'        => __( 'Eager (prerender on hover)', 'wpoptimizing' ),
		) as $fp_k => $fp_label ) {
			echo '<option value="' . esc_attr( $fp_k ) . '"' . selected( $fp['speculation'], $fp_k, false ) . '>' . esc_html( $fp_label ) . '</option>';
		}
		echo '</select></div>';
		echo '<p class="description">' . esc_html__( 'Uses the browser Speculation Rules API to prefetch or prerender the next page on hover/click, so navigation feels instant. Admin, login, cart/checkout/account and URLs with a query string are excluded automatically.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Image loading', 'wpoptimizing' ) . '</th><td>';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_fp_image_opt" value="1" ' . checked( ! empty( $fp['image_opt'] ), true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Optimize content image loading (prioritize the first image, lazy-load the rest)', 'wpoptimizing' ) . '</span></label>';
		// «Not deferred» and «LCP» are two decisions (frontend-perf.php): the first is the number
		// at the top and the exclusions, the second this switch.
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_fp_image_lcp" value="1" ' . checked( ! empty( $fp['image_lcp'] ), true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Give the first image of the content high fetch priority (the likely LCP image); it is never lazy-loaded', 'wpoptimizing' ) . '</span></label>';
		echo '<p><label for="wpopt-fp-image-eager"><strong>' . esc_html__( 'Images never lazy-loaded at the top of the page', 'wpoptimizing' ) . '</strong></label></p>';
		echo '<div class="wpopt-select-290"><select name="wpopt_fp_image_eager" id="wpopt-fp-image-eager" class="wpopt-select2">';
		echo '<option value="auto"' . selected( (int) $fp['image_eager'], -1, false ) . '>' . esc_html__( 'Automatic (the first image of the content; the first three of the whole page)', 'wpoptimizing' ) . '</option>';
		for ( $fp_n = 0; $fp_n <= 5; $fp_n++ ) {
			echo '<option value="' . esc_attr( (string) $fp_n ) . '"' . selected( (int) $fp['image_eager'], $fp_n, false ) . '>' . esc_html( number_format_i18n( $fp_n ) ) . '</option>';
		}
		echo '</select></div>';
		echo '<p><label for="wpopt-fp-lazy-exclude"><strong>' . esc_html__( 'Never lazy-load images whose class or file name contains (one per line)', 'wpoptimizing' ) . '</strong></label><br />';
		echo '<textarea id="wpopt-fp-lazy-exclude" aria-describedby="wpopt-fp-lazy-exclude-desc" name="wpopt_fp_lazy_exclude" rows="3" class="large-text code" placeholder="hero.jpg">' . esc_textarea( implode( "\n", (array) $fp['lazy_exclude'] ) ) . '</textarea></p>';
		echo '<p id="wpopt-fp-lazy-exclude-desc" class="description">' . wp_kses(
			__( 'A fixed number counts the images of the whole page from the top, header included. Images with the <code>skip-lazy</code> or <code>no-lazy</code> class, or the <code>data-skip-lazy</code> or <code>data-no-lazy</code> attribute, are never lazy-loaded either; an image already marked <code>loading="lazy"</code> keeps it and is not given priority.', 'wpoptimizing' ),
			array( 'code' => array() )
		) . '</p>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Server-Timing', 'wpoptimizing' ) . '</th><td>';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_fp_server_timing" value="1" ' . checked( ! empty( $fp['server_timing'] ), true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Send a Server-Timing header (DB queries, object-cache hit rate) for diagnostics', 'wpoptimizing' ) . '</span></label>';
		echo '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Resource hints', 'wpoptimizing' ) . '</th><td>';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_fp_resource_hints" value="1" ' . checked( ! empty( $fp['resource_hints'] ), true, false ) . ' /><span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span><span class="wpopt-toggle-label">' . esc_html__( 'Add preconnect / dns-prefetch hints for the external origins the page loads (fonts, CDNs), so the browser connects sooner', 'wpoptimizing' ) . '</span></label>';
		echo '</td></tr>';
		// Preload, lazy embeds, self-hosted Google Fonts and CDN rewriting are Pro (23/10/2026):
		// Lite has none of them, only the note; Pro without a license shows it locked.
		$fp_pro = false;
		if ( ! $fp_pro ) {
			$fp_note = __( 'Preloading key resources, lazy YouTube embeds, self-hosted Google Fonts and CDN rewriting are not included in this plugin.', 'wpoptimizing' );
			echo '<tr><th scope="row">' . esc_html__( 'More delivery options', 'wpoptimizing' ) . '</th><td>';
			$this->render_pro_note( $fp_note );
			echo '</td></tr>';
		}
	}



	/**
	 * The Settings in-page section menu items (anchor => label). Single source of
	 * truth shared by the mobile dropdown (top of the content) and the desktop
	 * vertical nav (right column).
	 *
	 * @return array<string,string>
	 */
	protected function settings_subnav_items() {
		$items = array(
			// Same anchor in both builds: Pro's first block is the licence, Lite's is Backups.
			'#wpopt-set-license'     => array(
				'label' => __( 'Backups', 'wpoptimizing' ),
				'icon'  => 'backup',
			),
			'#wpopt-set-profile'     => array(
				'label' => __( 'Quick profile', 'wpoptimizing' ),
				'icon'  => 'clipboard',
			),
			'#wpopt-set-cleanup'     => array(
				'label' => __( 'Cleanup options', 'wpoptimizing' ),
				'icon'  => 'admin-generic',
			),
			'#wpopt-set-frontend'    => array(
				'label' => __( 'Front-end performance', 'wpoptimizing' ),
				'icon'  => 'editor-code',
			),
			'#wpopt-set-tweaks'      => array(
				'label' => __( 'Core tweaks', 'wpoptimizing' ),
				'icon'  => 'admin-customizer',
			),
			'#wpopt-set-compat'      => array(
				'label' => __( 'Compatibility', 'wpoptimizing' ),
				'icon'  => 'admin-links',
			),
			'#wpopt-set-dataset'     => array(
				'label' => __( 'Shared dataset', 'wpoptimizing' ),
				'icon'  => 'share',
			),
			'#wpopt-set-portability' => array(
				'label' => __( 'Import / export', 'wpoptimizing' ),
				'icon'  => 'migrate',
			),
		);


		// Webhooks had a section but no entry here, so it was only reachable by scrolling. Same
		// condition as the section itself (the Lite build has no webhooks), in page order.
		if ( function_exists( 'wpoptimizing_webhook_events' ) ) {
			$at    = array_search( '#wpopt-set-tweaks', array_keys( $items ), true );
			$items = array_slice( $items, 0, $at + 1, true )
				+ array(
					'#wpopt-set-webhooks' => array(
						'label' => __( 'Webhooks', 'wpoptimizing' ),
						'icon'  => 'rest-api',
					),
				)
				+ array_slice( $items, $at + 1, null, true );
		}

		return $items;
	}

	/**
	 * Compatibility: what else is installed, what it owns, and how to take it back.
	 *
	 * Reads includes/compat/registry.php — never its own list of competitors. Shows a row per
	 * capability another active plugin provides, with a checkbox that overrides our standing
	 * down. The override exists because switching off silently leaves somebody migrating FROM a
	 * competitor unable to cut over: they would have to deactivate the other plugin first and
	 * run with no cache at all in between.
	 *
	 * @return void
	 */
	protected function render_compat_section() {
		$this->render_section_header(
			__( 'Compatibility with other plugins', 'wpoptimizing' ),
			array(
				'icon'   => 'admin-links',
				'anchor' => 'wpopt-set-compat',
			)
		);

		$detected = wpoptimizing_compat_detected();
		if ( empty( $detected ) ) {
			echo '<p class="description wpopt-desc-mb">'
				. esc_html__( 'No other performance or optimization plugin was detected, so every WPOptimizing feature is free to run.', 'wpoptimizing' )
				. '</p>';
			return;
		}

		// Lite no tiene retraso de JS ni CSS crítico: la advertencia sobre ellos sólo vale en Pro.
		$compat_desc = __( 'These plugins are active and already provide some of what WPOptimizing does. Where doing it twice would fight, our version is switched off. Tick a row to run ours anyway.', 'wpoptimizing' );
		echo '<p class="description wpopt-desc-mb">'
			. esc_html( $compat_desc )
			. '</p>';

		$overrides = wpoptimizing_compat_overrides();
		$caps      = wpoptimizing_compat_capabilities();

		echo '<table class="form-table wpopt-table" role="presentation"><tbody>';
		foreach ( $caps as $cap => $cap_label ) {
			$providers = wpoptimizing_compat_providers( $cap );
			if ( empty( $providers ) ) {
				continue;
			}
			$names = implode( ', ', array_map( 'strval', $providers ) );
			echo '<tr><th scope="row">' . esc_html( $cap_label ) . '</th><td>';

			/*
			 * SIN INTERRUPTOR en las de solo-informe. Para estas nunca nos retiramos, así que
			 * «usar WPOptimizing de todos modos» no tendría nada que anular: era un control que
			 * no hacía nada, y uno que no hace nada es peor que ninguno porque quien lo pulsa
			 * cree que ha cambiado algo. La frase también cambia: decir «la nuestra está
			 * apagada» aquí sería falso.
			 */
			if ( isset( wpoptimizing_compat_report_only()[ $cap ] ) ) {
				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: %s: comma-separated plugin names. */
							__( 'Also provided by %s. Both run: doing this twice is harmless, so WPOptimizing does not stand down and there is nothing to switch.', 'wpoptimizing' ),
							$names
						)
					)
				);
				echo '</td></tr>';
				continue;
			}

			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: comma-separated plugin names. */
						__( 'Provided by %s.', 'wpoptimizing' ),
						$names
					)
				)
			);
			echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_compat_override[]" value="' . esc_attr( $cap ) . '" '
				. checked( isset( $overrides[ $cap ] ), true, false ) . ' />'
				. '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>'
				. '<span class="wpopt-toggle-label">' . esc_html__( 'Use WPOptimizing for this anyway', 'wpoptimizing' ) . '</span></label>';
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		// Only for plugins whose whole job is something we do. Never for a suite: telling
		// somebody to drop WP Rocket because we minify would be false.
		$replaceable = array();
		foreach ( array_keys( $detected ) as $id ) {
			if ( wpoptimizing_compat_fully_covered( $id ) ) {
				$replaceable[] = $detected[ $id ];
			}
		}
		if ( ! empty( $replaceable ) ) {
			echo '<p class="description wpopt-desc-mb">' . esc_html(
				sprintf(
					/* translators: %s: comma-separated plugin names. */
					__( 'WPOptimizing covers everything these plugins are used for, so you can manage it all from one place and deactivate them: %s. Check your settings here first.', 'wpoptimizing' ),
					implode( ', ', $replaceable )
				)
			) . '</p>';
		}
	}

	/**
	 * Render the section menu as nav-tabs that reuse the main menu's look
	 * (.wpopt-sidebar .nav-tab — icons, hover, active box). $extra_link_attr lets
	 * the mobile instance close its panel after a tap.
	 *
	 * @param string $nav_class      Class for the <nav> wrapper.
	 * @param string $extra_link_attr Extra attributes appended to each link.
	 * @param string $nav_id          Optional id for the <nav> (for aria-controls).
	 * @return void
	 */
	protected function render_settings_nav_tabs( $nav_class, $extra_link_attr = '', $nav_id = '' ) {
		echo '<nav class="' . esc_attr( $nav_class ) . '"' . ( '' !== $nav_id ? ' id="' . esc_attr( $nav_id ) . '"' : '' ) . ' aria-label="' . esc_attr__( 'Settings sections', 'wpoptimizing' ) . '">';
		foreach ( $this->settings_subnav_items() as $wpopt_href => $wpopt_item ) {
			// $extra_link_attr is a controlled literal supplied by the caller (e.g. an
			// onclick that closes the mobile panel) — not user input.
			echo '<a class="nav-tab" href="' . esc_attr( $wpopt_href ) . '" ' . $extra_link_attr // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- controlled literal attribute string.
				. '>' . wpoptimizing_icon( $wpopt_item['icon'] ) . '<span class="wpopt-tab-label">' . esc_html( $wpopt_item['label'] ) . '</span></a>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
		}
		echo '</nav>';
	}

	/**
	 * The desktop vertical, sticky section menu. Rendered by the page wrapper in
	 * the right column (below the info box) so it does not reduce the content
	 * width. Reuses the main menu's .wpopt-sidebar/.nav-tab styling; hidden on
	 * mobile (the dark dropdown at the top of the content is used instead).
	 *
	 * @return void
	 */
	public function render_settings_section_nav() {
		$this->render_settings_nav_tabs( 'wpopt-settings-nav wpopt-sidebar' );
		// Desktop: the save button under the menu, so it sticks with it while scrolling.
		echo '<div class="wpopt-settings-save">';
		$this->render_settings_save_button();
		echo '</div>';
	}

	/**
	 * The settings save button. Printed twice — under the desktop section menu and in the mobile
	 * bottom bar — and both submit the main settings form through the `form` attribute, with the
	 * same name the save handler looks for. Not one button moved by CSS: the menu lives outside
	 * the form, in the right column.
	 *
	 * @return void
	 */
	protected function render_settings_save_button() {
		echo '<button type="submit" form="wpopt-settings-main" name="wpopt_backup_settings" value="1" class="button button-primary wpopt-save-btn"'
			. ' data-label="' . esc_attr__( 'Save settings', 'wpoptimizing' ) . '"'
			. ' data-label-dirty="' . esc_attr__( 'Save changes', 'wpoptimizing' ) . '">'
			. esc_html__( 'Save settings', 'wpoptimizing' ) . '</button>';
	}

	/**
	 * Import / export of the plugin configuration as a portable JSON file.
	 * Carries only configuration (see wpoptimizing_is_portable_option()):
	 * license, tokens and runtime state never travel between sites. Its own
	 * multipart form so the import file upload works regardless of the main
	 * settings form above.
	 *
	 * @return void
	 */
	protected function render_settings_portability() {
		$this->render_section_header(
			__( 'Import / export settings', 'wpoptimizing' ),
			array(
				'icon'   => 'migrate',
				'doc'    => 'wpopt-doc-settings',
				'anchor' => 'wpopt-set-portability',
			)
		);
		// Lite no tiene licencia, programación, Smart Assets ni webhooks: el texto no los nombra.
		$portability_desc = __( 'Save this site’s configuration to a JSON file and restore it here or on another site — handy to replicate your setup across sites. It includes the settings for cleanup, cache, media and images, backups and attribution. Keys, passwords, data-sharing consent and measured runtime state (scan results, baselines, cached sizes) are never included.', 'wpoptimizing' );
		echo '<p class="description wpopt-desc-tight">' . esc_html( $portability_desc ) . '</p>';

		echo '<form method="post" enctype="multipart/form-data" class="wpopt-settings-form">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="settings" />';
		echo '<table class="form-table wpopt-table"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Export', 'wpoptimizing' ) . '</th><td>';
		echo '<button type="submit" name="wpopt_settings_export" value="1" class="button">' . esc_html__( 'Download settings (.json)', 'wpoptimizing' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Downloads a JSON file with the current configuration.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Import', 'wpoptimizing' ) . '</th><td>';
		echo '<input type="file" name="wpopt_settings_file" accept="application/json,.json" />';
		echo '<button type="submit" name="wpopt_settings_import" value="1" class="button button-secondary wpopt-field-action">' . esc_html__( 'Import settings', 'wpoptimizing' ) . '</button>';
		echo '<p class="description">' . esc_html__( 'Select a file exported from WPOptimizing. Existing settings are overwritten; unknown keys are ignored.', 'wpoptimizing' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		echo '</form>';
	}

	/**
	 * Re-arm what an imported option only takes effect through: the general-cleanup schedule is
	 * armed by update_schedule(), so importing the option alone would not reschedule the event
	 * (auto-clean re-arms itself on 'init'; per-task schedules are evaluated by the master cron),
	 * and the automatic background analysis by wpoptimizing_sync_scan_schedule().
	 *
	 * @param string[] $applied Options the import wrote.
	 * @return void
	 */
	protected function rearm_after_settings_import( array $applied ) {
		if ( ( in_array( 'wpoptimizing_general_cleanup_schedule', $applied, true )
				|| in_array( 'wpoptimizing_gc_schedule_config', $applied, true ) )
			&& method_exists( $this, 'get_general_cleanup_service' ) ) {
			$svc = $this->get_general_cleanup_service();
			$svc->update_schedule( $svc->get_schedule(), $svc->get_schedule_config() );
		}
		if ( function_exists( 'wpoptimizing_sync_scan_schedule' )
			&& ( in_array( 'wpoptimizing_scan_schedule', $applied, true )
				|| in_array( 'wpoptimizing_scan_offpeak_start', $applied, true )
				|| in_array( 'wpoptimizing_scan_offpeak_end', $applied, true ) ) ) {
			wpoptimizing_sync_scan_schedule( true );
		}
	}

	/**
	 * Import from another cache or performance plugin: what each one found here would change,
	 * with its notes, and the button that does it. Its own form, like the JSON import.
	 *
	 * @return void
	 */
	protected function render_settings_importer() {
		if ( ! function_exists( 'wpoptimizing_importer_detected' ) ) {
			return;
		}
		$this->render_section_header(
			__( 'Import from another plugin', 'wpoptimizing' ),
			array(
				'icon'   => 'migrate',
				'doc'    => 'wpopt-doc-settings',
				'anchor' => 'wpopt-set-importer',
			)
		);
		echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Moving from another cache or performance plugin? WPOptimizing reads the settings it left on this site, even if it is deactivated, and brings over what has an equivalent here. Passwords, licence and API keys are never copied, and nothing that changes files (the page cache, the object cache) is switched on by an import.', 'wpoptimizing' ) . '</p>';

		$undo  = get_option( WPOPTIMIZING_IMPORTER_UNDO_OPTION );
		$found = wpoptimizing_importer_detected();

		echo '<form method="post" class="wpopt-settings-form" id="wpopt-set-importer">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="settings" />';
		if ( empty( $found ) ) {
			echo '<p>' . esc_html__( 'No settings from another cache or performance plugin were found on this site.', 'wpoptimizing' ) . '</p>';
		}
		foreach ( $found as $id => $src ) {
			$n = count( $src['plan']['changes'] );
			echo '<div class="wpopt-card wpopt-prose"><h3>' . esc_html( $src['label'] ) . '</h3>';
			echo wpoptimizing_importer_summary_html( $src ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
			if ( $n > 0 ) {
				echo '<p><button type="submit" name="wpopt_importer_apply" value="' . esc_attr( $id ) . '" class="button button-secondary">' . esc_html(
					sprintf(
						/* translators: %s: plugin name. */
						__( 'Import from %s', 'wpoptimizing' ),
						$src['label']
					)
				) . '</button></p>';
			}
			echo '</div>';
		}
		if ( is_array( $undo ) && ! empty( $undo['when'] ) ) {
			$label = wpoptimizing_importer_sources()[ (string) ( $undo['source'] ?? '' ) ]['label'] ?? (string) ( $undo['source'] ?? '' );
			echo '<p><button type="submit" name="wpopt_importer_undo" value="1" class="button">' . esc_html(
				sprintf(
					/* translators: 1: plugin name, 2: date. */
					__( 'Undo the import from %1$s (%2$s)', 'wpoptimizing' ),
					$label,
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $undo['when'] )
				)
			) . '</button></p>';
		}
		echo '</form>';
	}

	/**
	 * Import from another plugin, or undo the last import.
	 *
	 * @param string $id Source id; '' to undo.
	 * @return void
	 */
	protected function handle_settings_importer( $id ) {
		check_admin_referer( $this->nonce_action, $this->nonce_field );
		$flash = static function ( $type, $msg ) {
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_settings_importer',
					'msg'  => $msg,
					'type' => $type,
				),
				60
			);
		};
		if ( '' === $id ) {
			$undone = wpoptimizing_importer_undo( $this->can_manage_dropin() );
			if ( $undone ) {
				$this->rearm_after_settings_import( array( 'wpoptimizing_general_cleanup_schedule', 'wpoptimizing_scan_schedule' ) );
			}
			$flash(
				$undone ? 'updated' : 'error',
				$undone ? __( 'The settings are back to how they were before the import.', 'wpoptimizing' ) : __( 'There is no import to undo.', 'wpoptimizing' )
			);
			return;
		}
		$sources = wpoptimizing_importer_sources();
		if ( ! isset( $sources[ $id ] ) ) {
			$flash( 'error', __( 'That plugin cannot be imported from.', 'wpoptimizing' ) );
			return;
		}
		$report = wpoptimizing_importer_apply( $id, $this->can_manage_dropin() );
		if ( ! $report['ok'] ) {
			$flash( 'warning', __( 'Nothing was imported: none of its settings could be brought over.', 'wpoptimizing' ) );
			return;
		}
		$this->rearm_after_settings_import( $report['applied'] );
		$flash(
			'updated',
			sprintf(
				/* translators: %s: plugin name. */
				__( 'Settings imported from %s. Check the pages they belong to, and switch the page cache or the object cache on yourself when the other plugin is deactivated. You can undo the import below.', 'wpoptimizing' ),
				$sources[ $id ]['label']
			)
		);
	}

	/**
	 * The welcome wizard's import: bring a source's settings over and, when asked, deactivate its
	 * plugin. Applied when the analysis starts, after the wizard's own choices, so what was
	 * imported wins over the defaults of its other steps. «Undo the import» in Settings restores
	 * what there was before.
	 *
	 * @param string $id         Source id.
	 * @param bool   $deactivate Deactivate its plugin after importing.
	 * @return array{type:string,msg:string} A notice for the page ('' msg: nothing to say).
	 */
	public function wizard_import_from( $id, $deactivate ) {
		$sources = wpoptimizing_importer_sources();
		if ( ! isset( $sources[ $id ] ) ) {
			return array(
				'type' => 'error',
				'msg'  => '',
			);
		}
		$report = wpoptimizing_importer_apply( $id, $this->can_manage_dropin() );
		if ( ! $report['ok'] ) {
			return array(
				'type' => 'warning',
				'msg'  => __( 'Nothing was imported: none of its settings could be brought over.', 'wpoptimizing' ),
			);
		}
		$this->rearm_after_settings_import( $report['applied'] );
		$msg = sprintf(
			/* translators: %s: plugin name. */
			__( 'Settings imported from %s. You can undo the import in Settings.', 'wpoptimizing' ),
			$sources[ $id ]['label']
		);
		if ( $deactivate ) {
			$off = wpoptimizing_importer_deactivate( $id );
			if ( $off['done'] ) {
				$msg .= ' ' . wpoptimizing_importer_deactivated_message( $id );
			} elseif ( 'denied' === $off['scope'] ) {
				$msg .= ' ' . sprintf(
					/* translators: %s: plugin name. */
					__( '%s was not deactivated: only a network administrator can do it, because it is active for the whole network.', 'wpoptimizing' ),
					$sources[ $id ]['label']
				);
			}
		}
		return array(
			'type' => 'updated',
			'msg'  => $msg,
		);
	}

	/**
	 * Stream the portable settings as a JSON download and exit. Mirrors the
	 * CSV exports (history / network): nocache headers, attachment disposition.
	 *
	 * @return void
	 */
	public function stream_settings_export() {
		// Build inside a buffer and discard it: a stray PHP notice/deprecation
		// emitted while reading options (common on dev installs with
		// WP_DEBUG_DISPLAY on, e.g. from another plugin on a new PHP) would
		// otherwise be printed into the file and corrupt the JSON.
		ob_start();
		$payload = wpoptimizing_export_settings_payload();
		ob_end_clean();

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( ! is_string( $json ) ) {
			$json = '{}';
		}

		// Drop ANY output buffered upstream (early deprecations, a BOM) so the
		// download body is pure JSON and re-imports cleanly.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=wpoptimizing-settings-' . gmdate( 'Ymd-His' ) . '.json' );
		header( 'Content-Length: ' . strlen( $json ) );
		echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download, not HTML.
		exit;
	}

	/**
	 * Read the uploaded JSON file, apply the portable settings, and stash a
	 * flash notice with the result. The caller (maybe_handle_post) redirects
	 * back to the Settings screen. Cap + nonce are already verified upstream.
	 *
	 * @return void
	 */
	protected function handle_settings_import() {
		// Nonce + capability are already verified upstream in maybe_handle_post();
		// re-assert the nonce here as defense-in-depth (and so static analysis sees a
		// nonce check guarding the $_FILES access below).
		check_admin_referer( $this->nonce_action, $this->nonce_field );

		$flash = static function ( $type, $msg ) {
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_settings_import',
					'msg'  => $msg,
					'type' => $type,
				),
				60
			);
		};

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- $_FILES handled via WP_Filesystem + json_decode below.
		if ( empty( $_FILES['wpopt_settings_file']['tmp_name'] ) || ! empty( $_FILES['wpopt_settings_file']['error'] ) ) {
			$flash( 'error', __( 'No file was uploaded, or the upload failed.', 'wpoptimizing' ) );
			return;
		}
		$tmp = sanitize_text_field( wp_unslash( $_FILES['wpopt_settings_file']['tmp_name'] ) );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput
		if ( ! is_uploaded_file( $tmp ) ) {
			$flash( 'error', __( 'The uploaded file could not be read.', 'wpoptimizing' ) );
			return;
		}

		// A real export is a few KB; refuse oversized uploads before reading them into
		// memory (defends against a huge file exhausting admin memory). Filterable, but
		// never trust the client-reported size alone — also stat the temp file, and
		// keep a sane floor so a bad filter value cannot disable the check.
		$max_bytes = (int) apply_filters( 'wpoptimizing_settings_import_max_bytes', 2 * MB_IN_BYTES );
		if ( $max_bytes <= 0 ) {
			$max_bytes = 2 * MB_IN_BYTES;
		}
		$reported_bytes = isset( $_FILES['wpopt_settings_file']['size'] ) ? (int) $_FILES['wpopt_settings_file']['size'] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- integer size cast from $_FILES.
		$file_bytes     = max( $reported_bytes, (int) filesize( $tmp ) );
		if ( $file_bytes > $max_bytes ) {
			$flash( 'error', __( 'The file is too large to be a settings export.', 'wpoptimizing' ) );
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( ! WP_Filesystem() ) {
			$flash( 'error', __( 'The uploaded file could not be read.', 'wpoptimizing' ) );
			return;
		}
		global $wp_filesystem;
		$raw = ( $wp_filesystem && $wp_filesystem->exists( $tmp ) ) ? $wp_filesystem->get_contents( $tmp ) : false;
		if ( false === $raw || '' === $raw ) {
			$flash( 'error', __( 'The uploaded file could not be read.', 'wpoptimizing' ) );
			return;
		}

		// Tolerate a UTF-8 BOM and surrounding whitespace that would otherwise
		// make json_decode fail on an otherwise-valid export.
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $raw );
		$raw = trim( $raw );
		// Depth limit (64) as defense-in-depth against deeply-nested input.
		$payload = json_decode( $raw, true, 64 );
		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			$flash( 'error', __( 'The file is not valid JSON.', 'wpoptimizing' ) );
			return;
		}

		// The cache, page-cache and plugin-rules settings configure files the whole network
		// shares; a sub-site administrator holds this plugin's capability, so they only land
		// for whoever may manage those files (the same check the Cache screen applies).
		$report = wpoptimizing_import_settings_payload( $payload, $this->can_manage_dropin() );
		if ( 'foreign' === $report['error'] ) {
			$flash( 'error', __( 'That file was not exported from WPOptimizing.', 'wpoptimizing' ) );
			return;
		}
		if ( 'shape' === $report['error'] ) {
			$flash( 'error', __( 'The file does not look like a WPOptimizing settings export.', 'wpoptimizing' ) );
			return;
		}
		if ( ! $report['ok'] ) {
			$flash( 'warning', __( 'Nothing was imported: the file had no recognized settings.', 'wpoptimizing' ) );
			return;
		}

		$this->rearm_after_settings_import( $report['applied'] );

		$msg = sprintf(
			/* translators: %d: number of settings groups imported. */
			_n( '%d settings group imported.', '%d settings groups imported.', count( $report['applied'] ), 'wpoptimizing' ),
			count( $report['applied'] )
		);
		if ( ! empty( $report['skipped'] ) ) {
			$msg .= ' ' . sprintf(
				/* translators: %d: number of unknown keys ignored. */
				_n( '%d unknown entry was ignored.', '%d unknown entries were ignored.', count( $report['skipped'] ), 'wpoptimizing' ),
				count( $report['skipped'] )
			);
		}
		$flash( 'updated', $msg );
	}

	/**
	 * Smart Assets tab: the CSS/JS actually loaded on the public site, with a FREE
	 * manual unloading control. A master switch (off by default) plus one "don't load"
	 * checkbox per handle; WordPress core / jQuery are protected (shown locked). The
	 * list has native search, type/owner filters and pagination; every toggle is saved
	 * live over AJAX (so changing page never loses a choice). The automatic, KB-driven
	 * unloading is a separate premium feature.
	 *
	 * @return void
	 */
	protected function render_assets_tab() {
		// Smart Assets is Pro (23/10/2026): Lite has only the note, Pro without a license the lock.
		$assets_pro = false;
		if ( ! $assets_pro ) {
			$this->render_section_header(
				__( 'Smart Assets', 'wpoptimizing' ),
				__( 'Smart Assets stops the CSS and JavaScript a page does not need — site-wide, on chosen URLs or on a single page — with a test mode that applies it to administrators first.', 'wpoptimizing' ),
				array( 'icon' => 'media-code' )
			);
			$assets_note = __( 'Smart Assets is not included in this plugin.', 'wpoptimizing' );
			$this->render_pro_note( $assets_note );
			return;
		}
	}

}
