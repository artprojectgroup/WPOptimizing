<?php
/**
 * Activity log tab — a read-only, filterable timeline of everything that changed
 * in WordPress since WPOptimizing was installed (see includes/activity-log.php for
 * the capture engine). Reuses the generic pre-rendered-cells list table for the
 * shared search / filter / pagination UX.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Activity_Trait {

	/**
	 * Render the Activity log tab.
	 *
	 * @return void
	 */
	protected function render_activity_tab() {
		$this->render_section_header(
			__( 'Activity log', 'wpoptimizing' ),
			__( 'Everything that changed in WordPress since WPOptimizing was installed: database tables, options, post/user meta keys, scheduled events and top-level plugin/theme/upload files that other plugins add or remove — each with the date and, when the change came from a plugin or theme action, the responsible extension.', 'wpoptimizing' ),
			array(
				'icon' => 'list-view',
				'doc'  => 'wpopt-doc-activity',
			)
		);

		if ( ! function_exists( 'wpoptimizing_activity_log_get' ) ) {
			return;
		}
		$log = wpoptimizing_activity_log_get();
		if ( empty( $log ) ) {
			$this->render_empty_section_notice( __( 'No changes recorded yet. WPOptimizing captured a baseline of your site when it was installed; from now on, changes made by plugins and themes (new tables, options, files, scheduled events…) show up here with their date and, when known, who made them.', 'wpoptimizing' ) );
			return;
		}

		$types   = wpoptimizing_activity_types();
		$actions = array(
			'added'   => __( 'Added', 'wpoptimizing' ),
			'removed' => __( 'Removed', 'wpoptimizing' ),
		);

		// Filters (scope 'act'): type, action and search — read the same params the
		// shared list-table filter controls post back.
		$q  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only.
		$pp = isset( $_GET['act_pp'] ) ? (int) $_GET['act_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $pp, array( 10, 25, 50, 100, 200 ), true ) ) {
			$pp = 50;
		}
		$tf = isset( $_GET['act_tipo'] ) ? sanitize_key( wp_unslash( $_GET['act_tipo'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'all' === $tf || ! isset( $types[ $tf ] ) ) {
			$tf = '';
		}
		$af = isset( $_GET['act_ext'] ) ? sanitize_key( wp_unslash( $_GET['act_ext'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $af, array( 'added', 'removed' ), true ) ) {
			$af = '';
		}

		$datetime_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$needle       = strtolower( $q );
		$rows         = array();
		foreach ( $log as $e ) {
			$type   = (string) ( $e['type'] ?? '' );
			$action = (string) ( $e['action'] ?? '' );
			$name   = (string) ( $e['name'] ?? '' );
			$owner  = (string) ( $e['owner'] ?? '' );
			$ts     = (int) ( $e['ts'] ?? 0 );
			if ( '' !== $tf && $type !== $tf ) {
				continue;
			}
			if ( '' !== $af && $action !== $af ) {
				continue;
			}
			if ( '' !== $needle && false === strpos( strtolower( $name . ' ' . $owner ), $needle ) ) {
				continue;
			}
			$act_cls = ( 'added' === $action ) ? 'wpopt-status-active' : 'wpopt-status-inactive';
			$rows[]  = array(
				'cells' => array(
					'date'   => esc_html( $ts > 0 ? wp_date( $datetime_fmt, $ts ) : '—' ),
					'action' => '<span class="wpopt-status-badge ' . esc_attr( $act_cls ) . '">' . esc_html( isset( $actions[ $action ] ) ? $actions[ $action ] : $action ) . '</span>',
					'type'   => esc_html( isset( $types[ $type ] ) ? $types[ $type ] : $type ),
					'name'   => '<code class="wpopt-code-wrap">' . esc_html( $name ) . '</code>',
					'owner'  => ( '' !== $owner ) ? '<code class="wpopt-code-sm">' . esc_html( $owner ) . '</code>' : '<span class="wpopt-muted">' . esc_html__( 'Unknown', 'wpoptimizing' ) . '</span>',
				),
			);
		}

		$columns = array(
			'date'   => __( 'Date', 'wpoptimizing' ),
			'action' => __( 'Change', 'wpoptimizing' ),
			'type'   => __( 'Type', 'wpoptimizing' ),
			'name'   => __( 'Name', 'wpoptimizing' ),
			'owner'  => __( 'Responsible', 'wpoptimizing' ),
		);

		$table = new WPOptimizing_History_List_Table(
			$this,
			$rows,
			array(
				'scope'             => 'act',
				'per_page'          => $pp,
				'columns'           => $columns,
				'type_filter'       => $tf,
				'type_choices'      => $types,
				'type_all_label'    => __( 'All types', 'wpoptimizing' ),
				'project_filter'    => $af,
				'project_choices'   => $actions,
				'project_all_label' => __( 'All changes', 'wpoptimizing' ),
				'hidden_fields'     => array( 'tab' => 'activity' ),
			)
		);
		$table->prepare_items();
		$this->render_table_form( $table, 'activity', null, null, false, false );

		// Clear button — a separate POST form so it never nests inside the GET filter form.
		echo '<form method="post" class="wpopt-btn-row wpopt-activity-clear">';
		wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="activity" />';
		echo '<button type="submit" name="wpopt_activity_clear" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Clear the whole activity log? The baseline is kept, so tracking continues from now.', 'wpoptimizing' ) ) ) . ');">' . esc_html__( 'Clear activity log', 'wpoptimizing' ) . '</button>';
		echo '</form>';
	}
}
