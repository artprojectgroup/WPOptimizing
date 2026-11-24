<?php
/**
 * "Autoload" tab: autoloaded-options weight analysis and could-be-autoloaded candidates.
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Autoload_Trait {

	/**
	 * Render the Autoload tab.
	 *
	 * Free: shows count, weight and health of autoloaded options (info only).
	 * Premium: a full searchable/filterable/paginated table with per-option
	 * disable, a one-click Smart Deactivation for confident orphans, and a
	 * subsection suggesting options that could be autoloaded.
	 *
	 * @return void
	 */
	protected function render_autoload_tab() {
		if ( ! function_exists( 'wpoptimizing_analyze_autoload_full' ) ) {
			echo '<p>' . esc_html__( 'Autoload analyzer unavailable.', 'wpoptimizing' ) . '</p>';
			return;
		}
		$can_manage = true;
		$data       = wpoptimizing_analyze_autoload_full( 20000 );

		$health_map = array(
			'healthy'  => array( __( 'Healthy', 'wpoptimizing' ), '#d4edda', '#155724' ),
			'ok'       => array( __( 'Acceptable', 'wpoptimizing' ), '#e2f0d9', '#3c6e2e' ),
			'warning'  => array( __( 'Heavy', 'wpoptimizing' ), '#fff3cd', '#856404' ),
			'critical' => array( __( 'Critical', 'wpoptimizing' ), '#f8d7da', '#721c24' ),
		);
		$h          = $health_map[ $data['health'] ] ?? $health_map['healthy'];

		$this->render_section_header(
			__( 'Autoloaded options', 'wpoptimizing' ),
			__( 'Autoloaded options load into memory on every request. Keeping their total weight low keeps the site fast — under ~800 KB is healthy.', 'wpoptimizing' ),
			array(
				'icon' => 'database-view',
				'doc'  => 'wpopt-doc-autoload',
			)
		);

		// Crowd knowledge base: enrich each option with how many other sites
		// confirmed it as an autoload orphan (curated cross-site signal).
		$kb = function_exists( 'wpoptimizing_autoload_kb' ) ? wpoptimizing_autoload_kb() : array(
			'orphan' => array(),
			'read'   => array(),
		);

		// Info cards (free + premium).
		// "Orphan" needs an owner that is gone; an option nobody claims is "unattributed" — they
		// used to share one "likely orphans" count, so the number said nothing about either.
		$orphan_count       = 0;
		$unattributed_count = 0;
		$crowd_orphan_count = 0;
		foreach ( $data['options'] as &$o ) {
			$is_unattr               = ! empty( $o['orphan'] ) && in_array( (string) $o['component'], array( 'unknown', '' ), true );
			$unattributed_count     += $is_unattr ? 1 : 0;
			$orphan_count           += ( ! empty( $o['orphan'] ) && ! $is_unattr ) ? 1 : 0;
			$sites                   = wpoptimizing_autoload_kb_orphan_sites( $kb, (string) ( $o['name'] ?? '' ) );
			$o['crowd_orphan_sites'] = wpoptimizing_autoload_kb_is_confident( $sites ) ? (int) $sites : 0;
			if ( $o['crowd_orphan_sites'] > 0 ) {
				++$crowd_orphan_count;
			}
		}
		unset( $o );
		// Unified KPI cards (native look; state via class, never inline colour).
		echo '<div class="wpopt-kpi-grid wpopt-kpi-grid--3">';
		$cards = array(
			array( __( 'Autoloaded options', 'wpoptimizing' ), number_format_i18n( $data['count'] ), 'info' ),
			array( __( 'Total weight', 'wpoptimizing' ), $data['size_human'], 'info' ),
			array( __( 'Orphans', 'wpoptimizing' ), number_format_i18n( $orphan_count ), $orphan_count > 0 ? 'warn' : 'info' ),
			array( __( 'Unattributed', 'wpoptimizing' ), number_format_i18n( $unattributed_count ), 'info' ),
			array( __( 'Crowd-confirmed', 'wpoptimizing' ), number_format_i18n( $crowd_orphan_count ), $crowd_orphan_count > 0 ? 'good' : 'info' ),
		);
		foreach ( $cards as $c ) {
			printf(
				'<div class="wpopt-kpi"><div class="wpopt-kpi-num is-%3$s">%2$s</div><div class="wpopt-kpi-label">%1$s</div></div>',
				esc_html( $c[0] ),
				esc_html( $c[1] ),
				esc_attr( $c[2] )
			);
		}
		printf(
			'<div class="wpopt-kpi"><span class="wpopt-kpi-health is-%2$s">%1$s</span><div class="wpopt-kpi-label">%3$s</div></div>',
			esc_html( $h[0] ),
			esc_attr( (string) $data['health'] ),
			esc_html__( 'Health', 'wpoptimizing' )
		);
		echo '</div>';


		if ( empty( $data['options'] ) ) {
			echo '<p><em>' . esc_html__( 'No autoloaded options found.', 'wpoptimizing' ) . '</em></p>';
			return;
		}

		// Subsection navigation (responsive, WP-native subsubsub).
		$alsec = isset( $_GET['alsec'] ) ? sanitize_key( wp_unslash( $_GET['alsec'] ) ) : 'manage'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $alsec, array( 'manage', 'candidates' ), true ) ) {
			$alsec = 'manage';
		}
		$cand_count = function_exists( 'wpoptimizing_autoload_should_autoload' ) ? count( wpoptimizing_autoload_should_autoload() ) : 0;
		echo '<ul class="subsubsub wpopt-subsubsub">';
		$this->autoload_menu_link( 'manage', __( 'Autoloaded', 'wpoptimizing' ), $alsec, $data['count'] );
		echo ' | ';
		$this->autoload_menu_link( 'candidates', __( 'Could be autoloaded', 'wpoptimizing' ), $alsec, $cand_count );
		echo '</ul>';
		echo '<br class="clear" />';

		if ( 'candidates' === $alsec ) {
			$this->render_autoload_candidates();
			return;
		}

		// ── Manage: full searchable/filterable/paginated table ───────────────
		$this->render_section_header(
			__( 'Autoloaded', 'wpoptimizing' ),
			__( 'Disabling autoload keeps the option but stops loading it on every page (reversible). Smart Deactivation does this automatically for options confidently identified as belonging to uninstalled extensions.', 'wpoptimizing' ),
			array(
				'icon' => 'editor-ul',
				'doc'  => 'wpopt-doc-autoload',
			)
		);

		// Read filter/search/pagination params.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['autoload_tipo'] ) ? sanitize_text_field( wp_unslash( $_GET['autoload_tipo'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pp     = isset( $_GET['autoload_pp'] ) ? max( 25, (int) $_GET['autoload_pp'] ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Apply search + status filter.
		$rows = array();
		foreach ( $data['options'] as $o ) {
			if ( '' !== $search && false === stripos( $o['name'], $search ) ) {
				continue;
			}
			$unattr = ! empty( $o['orphan'] ) && in_array( (string) $o['component'], array( 'unknown', '' ), true );
			if ( 'orphans' === $filter && ( empty( $o['orphan'] ) || $unattr ) ) {
				continue;
			}
			if ( 'unattributed' === $filter && ! $unattr ) {
				continue;
			}
			if ( 'core' === $filter && 'wordpress-core' !== $o['component'] ) {
				continue;
			}
			if ( 'attributed' === $filter && ( ! empty( $o['orphan'] ) || in_array( $o['component'], array( 'wordpress-core', 'transient', 'unknown', '' ), true ) ) ) {
				continue;
			}
			$rows[] = $o;
		}

		$table = new WPOptimizing_Autoload_List_Table(
			$this,
			$rows,
			false,
			false,
			array(
				'scope'         => 'autoload',
				'per_page'      => $pp,
				'type_filter'   => $filter,
				'type_choices'  => array(
					'orphans'      => __( 'Orphans', 'wpoptimizing' ),
					'core'         => __( 'Core', 'wpoptimizing' ),
					'attributed'   => __( 'Attributed', 'wpoptimizing' ),
					'unattributed' => __( 'Unattributed', 'wpoptimizing' ),
				),
				'hidden_fields' => array(
					'page' => 'wpoptimizing',
					'tab'  => 'autoload',
				),
				'selectable'    => $can_manage,
			)
		);
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="autoload" />';
		$this->render_post_only_nonce();
		$table->search_box( __( 'Search options', 'wpoptimizing' ), 'autoload' );
		$table->display();

		// Smart / Aggressive deactivation + Delete-orphans live in the bulk button row.
		$smart_msg = __( 'Smart Deactivation will turn off autoload for every option confidently identified as belonging to an uninstalled extension. This is reversible. Continue?', 'wpoptimizing' );
		$smart_btn = '<button type="submit" formmethod="post" name="wpopt_autoload_smart" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( $smart_msg ) ) . ')">' . wpoptimizing_icon( 'lightbulb' ) . ' ' . esc_html__( 'Smart Deactivation', 'wpoptimizing' ) . '</button>';

		// Aggressive but safe: disable autoload for everything not core / not from an
		// installed extension (crowd-read options stay). Non-destructive, reversible.
		$aggr_msg = __( 'Aggressive Deactivation turns off autoload for EVERY option that is not core and not from an installed extension — including the unidentified ones. Options the community reads on most requests are kept. Nothing is deleted and it is fully reversible. Continue?', 'wpoptimizing' );
		$aggr_btn = ' <button type="submit" formmethod="post" name="wpopt_autoload_aggressive" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( $aggr_msg ) ) . ')">' . wpoptimizing_icon( 'performance' ) . ' ' . esc_html__( 'Disable all non-essential', 'wpoptimizing' ) . '</button>';

		// Surgical by usage: de-autoload options that load every request but are (almost)
		// never read — measured on THIS site and confirmed by the crowd KB. The safest,
		// most precise win; the button shows how many this site has measured so far.
		$unread_count = function_exists( 'wpoptimizing_autoload_unread_autoloaded' ) ? count( wpoptimizing_autoload_unread_autoloaded() ) : 0;
		$unread_msg   = __( 'Surgical Deactivation turns off autoload only for options that load on every request but are (almost) never read — measured on your own site and confirmed across the network. Nothing is deleted and it is fully reversible. Continue?', 'wpoptimizing' );
		$unread_label = $unread_count > 0
			/* translators: %d: number of never-read autoloaded options measured on this site. */
			? wpoptimizing_icon( 'marker' ) . ' ' . sprintf( esc_html__( 'De-autoload never-read (%d)', 'wpoptimizing' ), (int) $unread_count )
			: wpoptimizing_icon( 'marker' ) . ' ' . esc_html__( 'De-autoload never-read', 'wpoptimizing' );
		$unread_btn = ' <button type="submit" formmethod="post" name="wpopt_autoload_unread" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( $unread_msg ) ) . ')">' . $unread_label . '</button>';

		// Delete (not just de-autoload) the options confidently attributed to uninstalled
		// extensions — dead data. Backup-first via the standard DB delete path.
		$del_msg = __( 'Delete the autoloaded options confidently identified as belonging to uninstalled extensions? Nothing installed uses them any more, but they keep that extension\'s settings if you reinstall it. A backup is created first. Continue?', 'wpoptimizing' );
		$del_btn = ' <button type="submit" formmethod="post" name="wpopt_autoload_delete_orphans" value="1" class="button button-delete" onclick="return confirm(' . esc_attr( wp_json_encode( $del_msg ) ) . ')">' . wpoptimizing_icon( 'trash' ) . ' ' . esc_html__( 'Delete confirmed orphans (backup first)', 'wpoptimizing' ) . '</button>';

		// Deleting every confirmed orphan at once is bulk deletion, which is Pro (23/10/2026).
		$del_btn_out = '';

		// The four one-shot helpers (smart / never-read / all non-essential / delete
		// orphans) live behind one collapsed "Advanced" disclosure instead of a row of
		// buttons: the obvious control stays the single dynamic Disable button above.
		$adv_menu = '<details class="wpopt-more"><summary class="button">'
			. esc_html__( 'Advanced autoload cleanup', 'wpoptimizing' ) . '</summary>'
			. '<div class="wpopt-more-menu">' . $smart_btn . $unread_btn . $aggr_btn . $del_btn_out . '</div></details>';

		$this->render_bulk_controls(
			$table,
			array(
				'selected_label'   => __( 'Disable autoload for selected', 'wpoptimizing' ),
				'all_label'        => __( 'Disable all', 'wpoptimizing' ),
				'submit_name'      => 'wpopt_autoload_disable',
				'description'      => __( 'Disabling autoload keeps the option but stops loading it on every page (reversible). Open “Advanced autoload cleanup” for the smart, never-read, non-essential and delete-orphans helpers.', 'wpoptimizing' ),
				/* translators: %d: number of selected options. */
				'confirm_selected' => __( 'Disable autoload for the %d selected option(s)? This is reversible.', 'wpoptimizing' ),
				/* translators: %d: number of filtered options. */
				'confirm_all'      => __( 'Disable autoload for ALL %d filtered option(s)? This is reversible.', 'wpoptimizing' ),
				'extra_block'      => $adv_menu,
			)
		);
		echo '</form>';
	}

	/**
	 * Subsection nav link for the Autoload tab.
	 *
	 * @param string $key   Section key.
	 * @param string $label Link label.
	 * @param string $curr  Current section.
	 * @param int    $count Optional count badge.
	 * @return void
	 */
	protected function autoload_menu_link( $key, $label, $curr, $count = 0 ) {
		$url = add_query_arg(
			array(
				'page'  => 'wpoptimizing',
				'tab'   => 'autoload',
				'alsec' => $key,
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<li><a href="%s" class="%s">%s%s</a></li>',
			esc_url( $url ),
			( $curr === $key ) ? 'current' : '',
			esc_html( $label ),
			$count ? ' <span class="count">(' . esc_html( number_format_i18n( (int) $count ) ) . ')</span>' : ''
		);
	}

	/**
	 * Subsection of the Autoload tab: small non-autoloaded options that belong
	 * to active extensions or core and could be autoloaded. Heuristic — without
	 * request instrumentation we cannot measure real read frequency, so these
	 * are suggestions to review, not automatic actions.
	 *
	 * @return void
	 */
	protected function render_autoload_candidates() {
		if ( ! function_exists( 'wpoptimizing_autoload_should_autoload' ) ) {
			return;
		}
		$can_manage = true;

		$state = wpoptimizing_autoload_tracking_state();

		$this->render_section_header(
			__( 'Could be autoloaded', 'wpoptimizing' ),
			__( 'WPOptimizing measures how often each non-autoloaded option is actually read on each request. Options read on most requests but not autoloaded are real candidates for autoloading — no guessing.', 'wpoptimizing' ),
			array(
				'icon' => 'lightbulb',
				'doc'  => 'wpopt-doc-autoload',
			)
		);

		// Measurement runs automatically on every install — there is no button to
		// press. It starts itself and refreshes about once a month so the read
		// ratios always reflect the site's current plugin set.
		$nonce = wp_create_nonce( $this->nonce_action );
		$since = $state['started'] > 0 ? human_time_diff( $state['started'] ) : '';
		echo '<div class="wpopt-info-box">';
		echo '<p class="wpopt-notice"><span class="wpopt-badge-info">' . esc_html__( 'Measuring automatically', 'wpoptimizing' ) . '</span> ';
		if ( $state['requests'] > 0 ) {
			printf(
				/* translators: 1: requests, 2: time since the measurement window started */
				esc_html__( '%1$s requests sampled over %2$s.', 'wpoptimizing' ),
				'<strong>' . esc_html( number_format_i18n( $state['requests'] ) ) . '</strong>',
				esc_html( $since )
			);
		} else {
			echo esc_html__( 'Warming up — let normal traffic flow for a little while.', 'wpoptimizing' );
		}
		echo '</p>';
		echo '<p class="description wpopt-notice">' . esc_html__( 'Measurement is continuous and refreshes itself roughly once a month. This data also helps improve autoload recommendations across the WPOptimizing network.', 'wpoptimizing' ) . '</p>';
		if ( $state['requests'] > 0 ) {
			echo '<form method="post" class="wpopt-form-inline">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="page" value="wpoptimizing" /><input type="hidden" name="tab" value="autoload" /><input type="hidden" name="alsec" value="candidates" />';
			echo '<button type="submit" formmethod="post" name="wpopt_autoload_track_reset" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Clear the measured data and start a fresh measurement window?', 'wpoptimizing' ) ) ) . ')">' . esc_html__( 'Reset measurement', 'wpoptimizing' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';

		$candidates = wpoptimizing_autoload_should_autoload();
		if ( empty( $candidates ) ) {
			$msg = ( $state['requests'] < 50 )
				? __( 'Measuring automatically — candidates appear once enough requests have been sampled.', 'wpoptimizing' )
				: __( 'No options qualify: every option read on most requests is already autoloaded. Nothing to do — that is good news.', 'wpoptimizing' );
			echo '<p><em>' . esc_html( $msg ) . '</em></p>';
			return;
		}

		// Search + component filter + pagination params.
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter = isset( $_GET['autocand_tipo'] ) ? sanitize_text_field( wp_unslash( $_GET['autocand_tipo'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pp     = isset( $_GET['autocand_pp'] ) ? max( 25, (int) $_GET['autocand_pp'] ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$components = array();
		$rows       = array();
		foreach ( $candidates as $c ) {
			$components[ $c['component'] ] = true;
			if ( '' !== $search && false === stripos( $c['name'], $search ) ) {
				continue;
			}
			if ( 'all' !== $filter && $c['component'] !== $filter ) {
				continue;
			}
			$rows[] = array(
				'name'      => $c['name'],
				'size'      => (int) $c['size'],
				'component' => $c['component'],
				'orphan'    => false,
				'confident' => false,
				'reads'     => (int) ( $c['reads'] ?? 0 ),
				'requests'  => (int) ( $c['requests'] ?? 0 ),
				'ratio'     => (float) ( $c['ratio'] ?? 0 ),
			);
		}
		ksort( $components );

		$table = new WPOptimizing_Autoload_List_Table(
			$this,
			$rows,
			false,
			false,
			array(
				'scope'         => 'autocand',
				'per_page'      => $pp,
				'type_filter'   => $filter,
				'type_choices'  => array_keys( $components ),
				'hidden_fields' => array(
					'page'  => 'wpoptimizing',
					'tab'   => 'autoload',
					'alsec' => 'candidates',
				),
				'selectable'    => $can_manage,
				'cb_name'       => 'autoload_enable_selected[]',
				'show_reads'    => true,
			)
		);
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="autoload" />';
		echo '<input type="hidden" name="alsec" value="candidates" />';
		$this->render_post_only_nonce();
		$table->search_box( __( 'Search options', 'wpoptimizing' ), 'autocand' );
		$table->display();
		// Smart Activation sits next to the bulk "enable selected" button: it
		// auto-enables autoload for the high-confidence candidates (read on almost
		// every request and small enough to be safe). Reversible.
		$smart_msg = __( 'Smart Activation will enable autoload for every option read on almost every request and small enough to be safe. This is reversible. Continue?', 'wpoptimizing' );
		if ( $can_manage ) {
			echo '<p class="wpopt-notice">';
			echo '<button type="submit" formmethod="post" name="wpopt_autoload_enable" value="1" class="button">' . esc_html__( 'Enable autoload for selected', 'wpoptimizing' ) . '</button> ';
			echo '<button type="submit" formmethod="post" name="wpopt_autoload_smart_activate" value="1" class="button" onclick="return confirm(' . esc_attr( wp_json_encode( $smart_msg ) ) . ')">' . wpoptimizing_icon( 'lightbulb' ) . ' ' . esc_html__( 'Smart Activation', 'wpoptimizing' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			echo '</p>';
		}
		echo '</form>';
	}
}
