<?php
/**
 * Admin UI with tabs, pagination, and filters.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

	/**
	 * Class WPOPT_Admin_UI
	 *
	 * Tabs:
	 *  - db: Database disposables (pre-checked)
	 *  - fs: Filesystem disposables (pre-checked)
	 *  - inactive: Inactive (DB, FS) + uninstall plugins/themes
	 *
	 * Features:
	 *  - Filter by type
	 *  - Server-side pagination + page size selector
	 *  - “Extensions” column only if data exists. Format “Plugin: Name / Theme: Name”
	 *  - Uninstall inactive plugins/themes sorted by name
	 *  - Option “Also remove associated disposables” on uninstall
	 */
class WPOPT_Admin_UI {

	use WPOptimizing_Backups_Trait;
	use WPOptimizing_History_Trait;
	use WPOptimizing_Activity_Trait;
	use WPOptimizing_Media_Trait;
	use WPOptimizing_Autoload_Trait;
	use WPOptimizing_Cron_Trait;
	use WPOptimizing_Tables_Trait;
	use WPOptimizing_Remote_Maps_Trait;
	use WPOptimizing_Data_Ops_Trait;
	use WPOptimizing_Settings_Trait;
	use WPOptimizing_Cleanup_Tab_Trait;
	use WPOptimizing_Attr_Suggest_Trait;
	use WPOptimizing_Table_Data_Trait;
	use WPOptimizing_Cache_Trait;
	use WPOptimizing_Performance_Trait;
	use WPOptimizing_Docs_Trait;

	/** @var string Required capability. */
	protected $cap = 'manage_wpoptimizing';

	/** @var bool Set to true when the DB scan CSV exceeds WPOPT_MAX_SCAN_ROWS. */
	protected $scan_rows_capped = false;

	/** @var string Nonce action. */
	protected $nonce_action = 'wpoptimizing_clean';

	/** @var string Nonce field name. */
	protected $nonce_field = '_wpoptimizing_nonce';


	/** @var string Backup grouping key for the current operation ('' = group by day). */
	protected $backup_op_key = '';

	/** @var bool Opt-in to delete the *content* of orphaned CPTs / terms of orphaned taxonomies. */
	protected $confirm_destructive = false;

	/** @var string|null Forced tab key (general|db|fs|inactive|active|backups|settings). */
	protected $forced_tab = null;

	/** @var bool Whether to show tabs navigation. */
	protected $show_tabs = true;

	/** @var WPOPT_General_Cleanup|null General cleanup service instance. */
	protected $general_cleanup = null;

	/** @var array|null Pending flash notice (code/msg/type), rendered inside its section. */
	protected $pending_flash = null;

	/** @var array{deleted:int,kept:int}|null What the last database delete really did (bulk summary). */
	protected $last_bulk_counts = null;

	/** @var array|null DB effective-status split for THIS request, computed once in render() and reused by tabs (e.g. Tables attribution) so they never re-read the scan CSV and flicker. */
	protected $bd_split_cache = null;

	/**
	 * Stash a flash notice to be rendered inside the relevant tab/section rather than
	 * at the very top of the page.
	 *
	 * @param array $flash Flash data: code, msg, type.
	 * @return void
	 */
	public function set_pending_flash( $flash ) {
		if ( is_array( $flash ) && ! empty( $flash['code'] ) && isset( $flash['msg'], $flash['type'] ) ) {
			$this->pending_flash = $flash;
		}
	}

	/**
	 * Render the pending flash notice (once) at the current point in the output, so it
	 * appears within the section where the action happened. Maps the flash "type" to a
	 * WP notice class. No-op when there is nothing pending.
	 *
	 * @return void
	 */
	public function render_pending_flash() {
		if ( ! is_array( $this->pending_flash ) ) {
			return;
		}
		$flash               = $this->pending_flash;
		$this->pending_flash = null; // Consume so it renders exactly once.

		$type    = (string) $flash['type'];
		$classes = array(
			'updated' => 'notice-success',
			'success' => 'notice-success',
			'error'   => 'notice-error',
			'warning' => 'notice-warning',
			'info'    => 'notice-info',
		);
		$cls     = isset( $classes[ $type ] ) ? $classes[ $type ] : 'notice-info';
		// A bulk job's summary can carry an Undo: one click puts back exactly what that job
		// changed (its backup, or the options it took off autoload). Confirmed, and POSTed.
		$undo = '';
		if ( ! empty( $flash['undo'] ) && is_string( $flash['undo'] ) && false !== get_transient( 'wpoptimizing_undo_' . $flash['undo'] ) ) {
			$undo = '<form method="post" class="wpopt-undo-form">'
				. '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( wp_create_nonce( $this->nonce_action ) ) . '" />'
				. '<input type="hidden" name="page" value="wpoptimizing" />'
				. '<input type="hidden" name="wpopt_bulk_undo" value="' . esc_attr( $flash['undo'] ) . '" />'
				. '<button type="submit" class="button button-secondary" onclick="return window.confirm(' . esc_attr( (string) wp_json_encode( __( 'Undo this action? What it changed is put back as it was before.', 'wpoptimizing' ) ) ) . ');">'
				. wpoptimizing_icon( 'undo' ) . ' ' . esc_html__( 'Undo', 'wpoptimizing' ) . '</button></form>';
		}
		printf(
			'<div id="wpopt-flash-notice" class="notice %1$s inline wpopt-notice is-dismissible"><p>%2$s</p>%3$s</div>',
			esc_attr( $cls ),
			wp_kses_post( (string) $flash['msg'] ),
			$undo // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
		);
		// After an action reloads the page the user is often scrolled down to the table
		// they acted on; bring the result notice into view so it is never missed.
		wp_print_inline_script_tag( '(function(){var n=document.getElementById("wpopt-flash-notice");if(!n||!n.scrollIntoView){return;}window.addEventListener("load",function(){setTimeout(function(){n.scrollIntoView({behavior:"smooth",block:"center"});},150);});})();' );
	}

	/**
	 * Periodic, respectful reminder to turn on data sharing. Shown at most once per interval
	 * (default 30 days) on the plugin page while sharing is OFF, with a one-click enable. The
	 * shared dataset is reciprocal, so this is genuine value for the user, not just for us; it
	 * self-limits by writing the next snooze the moment it renders, so it never nags per load.
	 *
	 * @return void
	 */
	protected function render_share_nudge() {
		if ( ! current_user_can( wpoptimizing_cap() ) ) {
			return;
		}
		if ( function_exists( 'wpoptimizing_has_share_consent' ) && wpoptimizing_has_share_consent() ) {
			return; // Already sharing — nothing to nudge.
		}
		if ( ! (bool) apply_filters( 'wpoptimizing_show_share_nudge', true ) ) {
			return;
		}
		$snooze = (int) get_option( 'wpoptimizing_share_nudge_snooze', 0 );
		if ( $snooze > time() ) {
			return; // Snoozed (dismissed, just nudged in-context, or shown recently).
		}
		// Self-limit: don't show again until the next interval, whether or not they act.
		if ( function_exists( 'wpoptimizing_share_nudge_interval' ) ) {
			update_option( 'wpoptimizing_share_nudge_snooze', time() + wpoptimizing_share_nudge_interval(), false );
		}

		$enable_url = wp_nonce_url( admin_url( 'admin.php?page=wpoptimizing&wpopt_enable_sharing=1' ), 'wpopt_enable_sharing' );
		$snooze_url = wp_nonce_url( admin_url( 'admin.php?page=wpoptimizing&wpopt_share_snooze=1' ), 'wpopt_share_snooze' );

		echo '<div class="notice notice-info wpopt-notice wpopt-share-nudge">';
		echo '<p><strong>' . esc_html__( 'Improve attribution for your site — and everyone’s', 'wpoptimizing' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'Data sharing is off. Turn it on and WPOptimizing contributes only attribution metadata (names, sizes and how often things are seen — never values or content) to a shared dataset, and in return your site receives it, so plugins and options are identified far more accurately. It is reciprocal: while it is off, your site does not receive the shared dataset.', 'wpoptimizing' ) . '</p>';
		echo '<p>';
		echo '<a class="button button-primary" href="' . esc_url( $enable_url ) . '">' . esc_html__( 'Turn on sharing', 'wpoptimizing' ) . '</a> ';
		echo '<a class="button-link" href="' . esc_url( $snooze_url ) . '">' . esc_html__( 'Not now', 'wpoptimizing' ) . '</a>';
		echo '</p></div>';
	}

	/**
	 * Quote a trusted SQL identifier such as a table name.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	protected function quote_identifier( $identifier ) {
		return wpoptimizing_quote_identifier( $identifier );
	}


	/**
	 * Get the General cleanup service.
	 *
	 * @return WPOPT_General_Cleanup
	 */
	protected function get_general_cleanup_service() {
		if ( null === $this->general_cleanup ) {
			$this->general_cleanup = new WPOPT_General_Cleanup( $this->cap );
		}
		return $this->general_cleanup;
	}

	/**
	 * Set view context (forced tab and tab visibility).
	 *
	 * @param string|null $tab       Forced tab key or null.
	 * @param bool        $show_tabs Whether to show tabs navigation.
	 * @return void
	 */
	public function set_view_context( $tab = null, $show_tabs = true ) {
		$this->forced_tab = $tab;
		$this->show_tabs  = (bool) $show_tabs;
	}
	/**
	 * Serve the general-cleanup PDF report / log download as a STANDALONE response,
	 * before WordPress prints any admin chrome.
	 *
	 * These responses emit a full HTML document (the printable report) or send file
	 * download headers, then exit. If they ran later — inside the page-render
	 * callback (maybe_handle_post) — the admin sidebar/header would already be on the
	 * page, the report's <!DOCTYPE>/<html> would be nested inside the admin body (so
	 * its max-width wrapper never applies and the layout overflows), and download
	 * headers would fail with "headers already sent". Hook this on admin_init (early)
	 * so the report is its own clean page. Mirrors wpoptimizing_maybe_serve_impact_report().
	 *
	 * @return void
	 */
	public function maybe_serve_gc_report() {
		if ( ! isset( $_REQUEST['wpopt_gc_view_report'] ) && ! isset( $_REQUEST['wpopt_gc_download_report'] ) ) {
			return;
		}
		if ( ! current_user_can( $this->cap ) ) {
			return;
		}
		// The printable report is opened by a GET form, so it has a read-only nonce of its own:
		// the action nonce in that URL would sit in the history and the server logs. The
		// download comes from a POST form and keeps the action nonce.
		$ok = false;
		if ( isset( $_REQUEST['wpopt_gc_view_report'], $_REQUEST['_wpopt_gcr_nonce'] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpopt_gcr_nonce'] ) ), 'wpopt_gc_report' ) ) {
				$ok = true;
			}
		} elseif ( ! empty( $_REQUEST[ $this->nonce_field ] ) ) {
			if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ $this->nonce_field ] ) ), $this->nonce_action ) ) {
				$ok = true;
			}
		}
		if ( ! $ok ) {
			return;
		}

		$report_id = isset( $_REQUEST['wpopt_gc_report_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpopt_gc_report_id'] ) ) : '';

		if ( isset( $_REQUEST['wpopt_gc_download_report'] ) ) {
			$this->get_general_cleanup_service()->download_report( $report_id ); // Streams and exits.
		}
		$this->get_general_cleanup_service()->print_report_html( $report_id ); // Emits HTML and exits.
	}

	/**
	 * Process POST form: deletions and uninstallations.
	 *
	 * @return void
	 */
	public function maybe_handle_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( ! in_array( $method, array( 'POST', 'GET' ), true ) ) {
			return;
		}
		if ( ! current_user_can( $this->cap ) ) {
			return;
		}

		// Impact report — uses its own nonce, must be checked before the main nonce guard.
		if (
			isset( $_REQUEST['wpopt_impact_report'], $_REQUEST['_wpopt_ir_nonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpopt_ir_nonce'] ) ), 'wpopt_impact_report' )
		) {
			$this->render_impact_report();
			// render_impact_report() calls exit, so we never reach here.
		}

		/*
		 * LOS PANELES QUE TRAEN SU PROPIO NONCE SE DESPACHAN AQUÍ, ANTES DE LA GUARDA DE ABAJO.
		 *
		 * Estaban al FINAL de este método, detrás de `if ( empty( $_REQUEST[ $this->nonce_field
		 * ] ) ) return;`. Y sus formularios no llevan ese nonce: llevan el suyo. Así que sólo
		 * llegaban a ejecutarse cuando la URL ARRASTRABA el nonce principal en la cadena de
		 * consulta — cosa que ocurre porque render_table_form() lo emite dentro de un
		 * formulario **GET**, de modo que en cuanto usas el buscador o un filtro el nonce se
		 * queda pegado a la URL y a partir de ahí todo funciona.
		 *
		 * Resultado: en una pantalla recién cargada el panel no hacía NADA, sin un aviso; y
		 * después de buscar, funcionaba. Reportado con el asistente de atribución, pero la
		 * atribución manual tenía exactamente el mismo fallo y no se había notado porque quien
		 * usa ese panel suele venir de filtrar.
		 *
		 * Cada uno verifica su propio nonce con check_admin_referer(), así que adelantarlos es
		 * lo correcto y además lo más seguro: la alternativa —darles el nonce principal— los
		 * metería por el manejador de acciones de abajo, que es el que borra.
		 *
		 * Lo de correr ANTES de pintar sigue tan vigente como cuando se escribió: la atribución
		 * cambia la fila, y una tarjeta pintada antes se queda con el número viejo hasta la
		 * siguiente carga.
		 */
		$wpopt_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab selector; the actions below verify their own nonces.
		if ( in_array( $wpopt_tab, array( 'pending', 'empty', 'orphans', 'inactive', 'active', 'review' ), true ) ) {
			$this->preprocess_manual_attribution();
			$this->preprocess_attr_suggest();
		}

		// The audit must have stored its result before the screen paints, or the user clicks
		// "Run" and sees the previous run's numbers. And it goes BEFORE the early return below:
		// its form carries its own nonce (wpopt_media_audit), not this handler's, so placed after
		// it the button reloaded the page and never ran the audit.

		// Scheduled tasks: run one now, pause or resume it. They are row links on the Cron tab,
		// so GET, and carry a nonce of their own (see cron_task_url()), never the one below.
		if ( isset( $_GET['wpopt_cron_task'], $_GET['_wpopt_cron_nonce'] ) && function_exists( 'wpoptimizing_cron_run_now' )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpopt_cron_nonce'] ) ), 'wpopt_cron_task' ) ) {
			$this->handle_cron_task();
			wp_safe_redirect( admin_url( 'admin.php?page=wpoptimizing&tab=cron' ) );
			exit;
		}

		if ( empty( $_REQUEST[ $this->nonce_field ] ) ) {
			return;
		}
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $this->nonce_field ] ) );
		if ( ! wp_verify_nonce( $nonce, $this->nonce_action ) ) {
			return;
		}

		/*
		 * EVERYTHING BELOW CHANGES SOMETHING, SO IT ONLY ANSWERS A POST.
		 *
		 * It used to read $_REQUEST for GET too, and the filter forms are GET: their search
		 * button carried this nonce into the URL — history, server logs, a shared screenshot —
		 * and from there one crafted link with bd_selected[]/uninstall_plugins[] did the rest.
		 * The GET forms now print it disabled (render_post_only_nonce()) and only their action
		 * buttons, which carry formmethod="post", send it.
		 *
		 * The Backups tab's Restore / Delete row actions were the last GET links with this
		 * nonce; they are POST buttons now (19/10/2026).
		 */
		if ( 'POST' !== $method ) {
			return;
		}


		$did_action = false;

		// Group every backup made while handling this request into one
		// per-operation entry (so the user sees a distinct, recognisable backup
		// for each cleanup, not one growing per-day blob).
		$this->backup_op_key = $this->new_backup_op_key();

		// Opt-in for the destructive deletions (orphaned CPT content / orphaned
		// taxonomy terms). Off unless the user explicitly confirmed.
		$this->confirm_destructive = ! empty( $_REQUEST['wpopt_destructive'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above.

		// Rate limit: max 10 delete operations per minute per user.
		$rate_ok = ! function_exists( 'wpoptimizing_rate_allow' )
			|| wpoptimizing_rate_allow( 'delete', 10, MINUTE_IN_SECONDS );

		// Lite deletes attributed orphans one at a time, here too (the form without JavaScript).
		$one_cap = 1;

		// 1) Delete selected DB items.
		if ( $rate_ok && ! empty( $_REQUEST['bd_selected'] ) && is_array( $_REQUEST['bd_selected'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$bd_selected = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['bd_selected'] ) );
			$this->delete_bd_items( $one_cap > 0 ? array_slice( $bd_selected, 0, $one_cap ) : $bd_selected );
			$did_action = true;
		}

		// 2) Delete selected FS paths.
		if ( $rate_ok && ! empty( $_REQUEST['fs_selected'] ) && is_array( $_REQUEST['fs_selected'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$fs_selected = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['fs_selected'] ) );
			if ( $one_cap > 0 ) {
				$fs_selected = array_slice( $fs_selected, 0, $one_cap );
			}
			$this->delete_fs_paths( $fs_selected );
			// Keep the Media tab in sync by pruning ONLY the deleted media orphans from the
			// cached report in place — never drop the whole report, or every other finding
			// would vanish and force a full re-scan. Mirrors the async batch path.
			$this->prune_media_report_files( $fs_selected );
			$did_action = true;
		}

		// 2b) Autoload management (premium): disable selected.
		if ( isset( $_REQUEST['wpopt_autoload_disable'] ) && ! empty( $_REQUEST['autoload_selected'] ) && is_array( $_REQUEST['autoload_selected'] )
			&& function_exists( 'wpoptimizing_autoload_disable' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$names = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['autoload_selected'] ) );
			$n     = wpoptimizing_autoload_disable( $names, $this->backup_op_key );
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'autoload_disable',
					array(
						'count' => $n,
						'items' => array_slice( $names, 0, 50 ),
					)
				);
			}
			$did_action = true;
		}

		// 2c) Smart Deactivation: auto-disable autoload for confident orphans.
		if ( isset( $_REQUEST['wpopt_autoload_smart'] ) && function_exists( 'wpoptimizing_autoload_smart_deactivate' ) ) {
			$res = wpoptimizing_autoload_smart_deactivate();
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'autoload_smart',
					array(
						'count' => (int) $res['deactivated'],
						'items' => array_slice( $res['names'], 0, 50 ),
					)
				);
			}
			$did_action = true;
		}

		// 2c-ii) Aggressive (but safe) deactivation: disable autoload for every
			// non-essential option (heuristic orphans included; crowd-read options are
			// protected). Reversible, non-destructive.
		if ( isset( $_REQUEST['wpopt_autoload_aggressive'] ) && function_exists( 'wpoptimizing_autoload_deactivate_nonessential' ) ) {
			$res = wpoptimizing_autoload_deactivate_nonessential();
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'autoload_aggressive',
					array(
						'count' => (int) $res['deactivated'],
						'items' => array_slice( $res['names'], 0, 50 ),
					)
				);
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_autoload',
					'msg'  => sprintf(
						/* translators: %d: number of options whose autoload was turned off. */
						_n( 'Autoload turned off for %d non-essential option (reversible).', 'Autoload turned off for %d non-essential options (reversible).', (int) $res['deactivated'], 'wpoptimizing' ),
						(int) $res['deactivated']
					),
					'type' => 'success',
				),
				30
			);
			$did_action = true;
		}

			// 2c-ii-bis) Surgical deactivation by measured usage: disable autoload for
				// options that load every request but are (almost) never read — from this
				// site's own measurement and from the crowd knowledge base. Reversible.
		if ( isset( $_REQUEST['wpopt_autoload_unread'] ) && function_exists( 'wpoptimizing_autoload_deactivate_unread' ) ) {
			$res = wpoptimizing_autoload_deactivate_unread();
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'autoload_unread',
					array(
						'count' => (int) $res['deactivated'],
						'bytes' => (int) $res['bytes'],
						'items' => array_slice( $res['names'], 0, 50 ),
					)
				);
			}
			if ( empty( $res['measured'] ) && 0 === (int) $res['deactivated'] ) {
				$msg = __( 'Not enough usage measured yet. WPOptimizing is learning which autoloaded options are actually read on your site; check back in a day or two, or after more visits.', 'wpoptimizing' );
				$typ = 'info';
			} else {
				$msg = sprintf(
					/* translators: 1: number of options de-autoloaded; 2: human-readable size reclaimed from the per-request payload. */
					_n( 'Autoload turned off for %1$d never-read option (%2$s off every request, reversible).', 'Autoload turned off for %1$d never-read options (%2$s off every request, reversible).', (int) $res['deactivated'], 'wpoptimizing' ),
					(int) $res['deactivated'],
					size_format( (int) $res['bytes'], 1 )
				);
				$typ = 'success';
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_autoload',
					'msg'  => $msg,
					'type' => $typ,
				),
				30
			);
			$did_action = true;
		}

				// 2c-iii) Delete autoloaded options confidently attributed to uninstalled
			// extensions (dead data) — backup-first, via the standard DB delete path.
		if ( $rate_ok && 0 === $one_cap && isset( $_REQUEST['wpopt_autoload_delete_orphans'] ) && function_exists( 'wpoptimizing_autoload_confirmed_orphan_entries' ) ) {
			$entries = wpoptimizing_autoload_confirmed_orphan_entries();
			if ( ! empty( $entries ) ) {
				// Built from the autoload analysis, not from the orphan list: the active-owner
				// gate still applies, the "listed in the latest analysis" check cannot.
				$this->delete_bd_items( $entries, false );
			}
			$did_action = true;
		}

			// 2c-bis) Smart Activation: auto-enable autoload for high-confidence
		// candidates (options read on almost every request and small enough).
		if ( isset( $_REQUEST['wpopt_autoload_smart_activate'] ) && function_exists( 'wpoptimizing_autoload_smart_activate' ) ) {
			$res = wpoptimizing_autoload_smart_activate();
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'autoload_smart_activate',
					array(
						'count' => (int) $res['activated'],
						'items' => array_slice( $res['names'], 0, 50 ),
					)
				);
			}
			$did_action = true;
		}

		// 2d) Enable autoload for selected candidates.
		if ( isset( $_REQUEST['wpopt_autoload_enable'] ) && ! empty( $_REQUEST['autoload_enable_selected'] ) && is_array( $_REQUEST['autoload_enable_selected'] )
			&& function_exists( 'wpoptimizing_autoload_enable' ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$names = array_map( 'sanitize_text_field', wp_unslash( $_REQUEST['autoload_enable_selected'] ) );
			$n     = wpoptimizing_autoload_enable( $names, $this->backup_op_key );
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log(
					'autoload_enable',
					array(
						'count' => $n,
						'items' => array_slice( $names, 0, 50 ),
					)
				);
			}
			$did_action = true;
		}

		// 2e) Autoload read-measurement controls (premium).
		if ( isset( $_REQUEST['wpopt_autoload_track_start'] ) && function_exists( 'wpoptimizing_autoload_tracking_start' ) ) {
			wpoptimizing_autoload_tracking_start( 7 );
			$did_action = true;
		}
		if ( isset( $_REQUEST['wpopt_autoload_track_stop'] ) && function_exists( 'wpoptimizing_autoload_tracking_stop' ) ) {
			wpoptimizing_autoload_tracking_stop();
			$did_action = true;
		}
		if ( isset( $_REQUEST['wpopt_autoload_track_reset'] ) && function_exists( 'wpoptimizing_autoload_tracking_reset' ) ) {
			wpoptimizing_autoload_tracking_reset();
			// Measurement is perpetual: clear the tally and resume immediately
			// instead of waiting for the daily heartbeat to re-arm it.
			if ( function_exists( 'wpoptimizing_autoload_tracking_ensure' ) ) {
				delete_option( 'wpoptimizing_autoload_ensure_ts' );
				wpoptimizing_autoload_tracking_ensure();
			}
			$did_action = true;
		}

		// 3) Uninstall inactive plugins/themes.
		$plugins = array();
		$themes  = array();
		if ( ! empty( $_REQUEST['uninstall_plugins'] ) && is_array( $_REQUEST['uninstall_plugins'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$plugins = array_map( 'sanitize_key', wp_unslash( $_REQUEST['uninstall_plugins'] ) );
		}
		if ( ! empty( $_REQUEST['uninstall_themes'] ) && is_array( $_REQUEST['uninstall_themes'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$themes = array_map( 'sanitize_key', wp_unslash( $_REQUEST['uninstall_themes'] ) );
		}
		$cascade = ! empty( $_REQUEST['cascade_associated'] );
		// Lite uninstalls one leftover extension at a time; in bulk it is Pro (23/10/2026).
		$one_by_one = true;
		if ( $one_by_one && count( $plugins ) + count( $themes ) > 1 ) {
			$plugins = array_slice( $plugins, 0, 1 );
			$themes  = empty( $plugins ) ? array_slice( $themes, 0, 1 ) : array();
		}

		if ( ! empty( $plugins ) || ! empty( $themes ) ) {
			$gone       = $this->uninstall_projects( $plugins, $themes );
			$did_action = true;

			if ( $cascade ) {
				// Also remove disposables associated with those slugs (from INACTIVE DB and FS) —
				// only the ones whose folder is really gone: a failed or skipped uninstall keeps
				// its data.
				$this->cascade_delete_associated( (array) ( $gone['plugins'] ?? array() ), (array) ( $gone['themes'] ?? array() ) );
			}
		}

		$deactivate_plugins = array();
		$deactivate_themes  = array();
		if ( ! empty( $_REQUEST['deactivate_plugins'] ) && is_array( $_REQUEST['deactivate_plugins'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$deactivate_plugins = array_map( 'sanitize_key', wp_unslash( $_REQUEST['deactivate_plugins'] ) );
		}
		if ( ! empty( $_REQUEST['deactivate_themes'] ) && is_array( $_REQUEST['deactivate_themes'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$deactivate_themes = array_map( 'sanitize_key', wp_unslash( $_REQUEST['deactivate_themes'] ) );
		}
		if ( ! empty( $deactivate_plugins ) || ! empty( $deactivate_themes ) ) {
			$this->deactivate_projects( $deactivate_plugins, $deactivate_themes );
			$did_action = true;
		}

		// Backup actions.
		if ( isset( $_REQUEST['wpopt_backup_create'] ) ) {
			$this->create_manual_backup();
			$did_action = true;
		}
		if ( isset( $_REQUEST['wpopt_bulk_undo'] ) ) {
			$this->undo_bulk_job( sanitize_key( wp_unslash( $_REQUEST['wpopt_bulk_undo'] ) ) );
			$did_action = true;
		}
		// The id comes in backup_id (Media trash forms) or as the button's value (Backups tab).
		foreach ( array(
			'wpopt_backup_restore' => 'restore_backup',
			'wpopt_backup_delete'  => 'delete_backup',
		) as $bk_field => $bk_method ) {
			if ( ! isset( $_REQUEST[ $bk_field ] ) ) {
				continue;
			}
			$bk_id = ! empty( $_REQUEST['backup_id'] ) ? wp_unslash( $_REQUEST['backup_id'] ) : wp_unslash( $_REQUEST[ $bk_field ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized just below.
			$bk_id = sanitize_text_field( (string) $bk_id );
			if ( '' !== $bk_id && '1' !== $bk_id ) {
				$this->$bk_method( $bk_id );
				$did_action = true;
			}
		}
		if ( isset( $_REQUEST['wpopt_backup_settings'] ) || isset( $_REQUEST['wpopt_replay_wizard'] ) ) {
			$this->save_backup_settings();
			$did_action = true;
		}
		if ( isset( $_REQUEST['wpopt_autoinc_fix'] ) && function_exists( 'wpoptimizing_autoinc_fix' ) ) {
			$ai_table = sanitize_key( wp_unslash( $_REQUEST['wpopt_autoinc_fix'] ) );
			$ai_res   = wpoptimizing_autoinc_fix( $ai_table );
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_autoinc',
					'msg'  => true === $ai_res
						/* translators: %s: table name. */
						? sprintf( __( '%s: AUTO_INCREMENT restored.', 'wpoptimizing' ), $ai_table )
						/* translators: 1: table name, 2: reason. */
						: sprintf( __( '%1$s was not changed: %2$s.', 'wpoptimizing' ), $ai_table, $ai_res ),
					'type' => true === $ai_res ? 'updated' : 'error',
				),
				30
			);
			$did_action = true;
		}
		if ( isset( $_REQUEST['wpopt_general_run'] ) ) {
			$this->run_general_cleanup_now();
			$did_action = true;
		}
		// Dry-run preview: estimate what the current settings would remove, store
		// it for the General tab to display, then redirect (nothing is deleted).
		if ( isset( $_REQUEST['wpopt_gc_preview'] ) ) {
			$gc_service = $this->get_general_cleanup_service();
			$estimate   = $gc_service->estimate();
			set_transient( 'wpoptimizing_gc_preview', $estimate, 5 * MINUTE_IN_SECONDS );
			// Apart from the counts, which callers sum: whether some of them were cut short.
			set_transient( 'wpoptimizing_gc_preview_partial', $gc_service->estimate_is_partial() ? 1 : 0, 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'             => 'wpoptimizing',
						'tab'              => 'general',
						'wpopt_gc_section' => 'run',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		// Object cache (Cache tab) actions. Each handles its own flash + redirect
		// and exits, so they never schedule a rescan or land on another tab.
		if ( isset( $_REQUEST['wpopt_cache_install'] ) || isset( $_REQUEST['wpopt_cache_uninstall'] )
			|| isset( $_REQUEST['wpopt_cache_flush'] ) || isset( $_REQUEST['wpopt_cache_settings'] )
			|| isset( $_REQUEST['wpopt_cf_save'] ) || isset( $_REQUEST['wpopt_cf_forget'] )
			|| isset( $_REQUEST['wpopt_cache_replace'] ) || isset( $_REQUEST['wpopt_cache_db_replace'] )
			|| isset( $_REQUEST['wpopt_cache_db_install'] ) || isset( $_REQUEST['wpopt_cache_db_uninstall'] )
			|| isset( $_REQUEST['wpopt_edge_check'] ) || isset( $_REQUEST['wpopt_edge_save'] )
			|| isset( $_REQUEST['wpopt_browsercache_apply'] ) || isset( $_REQUEST['wpopt_browsercache_remove'] )
			|| isset( $_REQUEST['wpopt_cache_leftovers_clean'] )
			|| isset( $_REQUEST['wpopt_assetmin_save'] )
			|| isset( $_REQUEST['wpopt_perf_heartbeat'] ) || isset( $_REQUEST['wpopt_perf_heartbeat_zones'] )
			|| isset( $_REQUEST['wpopt_psi_run'] ) || isset( $_REQUEST['wpopt_psi_key_save'] ) || isset( $_REQUEST['wpopt_crux_run'] )
			|| isset( $_REQUEST['wpopt_frontend_save'] ) ) {
			if ( isset( $_REQUEST['wpopt_cache_install'] ) || isset( $_REQUEST['wpopt_cache_replace'] ) ) {
				$this->install_object_cache_dropin( isset( $_REQUEST['wpopt_cache_replace'] ) );
			} elseif ( isset( $_REQUEST['wpopt_cache_uninstall'] ) ) {
				$this->uninstall_object_cache_dropin();
			} elseif ( isset( $_REQUEST['wpopt_cache_flush'] ) ) {
				$this->flush_object_cache();
			} elseif ( isset( $_REQUEST['wpopt_cache_db_install'] ) || isset( $_REQUEST['wpopt_cache_db_replace'] ) ) {
				$this->install_db_dropin( isset( $_REQUEST['wpopt_cache_db_replace'] ) );
			} elseif ( isset( $_REQUEST['wpopt_cache_db_uninstall'] ) ) {
				$this->uninstall_db_dropin();
			} elseif ( isset( $_REQUEST['wpopt_edge_check'] ) || isset( $_REQUEST['wpopt_edge_save'] ) ) {
				$this->cache_edge_action( isset( $_REQUEST['wpopt_edge_check'] ) );
			} elseif ( isset( $_REQUEST['wpopt_browsercache_apply'] ) ) {
				$this->cache_browser_apply( true );
			} elseif ( isset( $_REQUEST['wpopt_browsercache_remove'] ) ) {
				$this->cache_browser_apply( false );
			} elseif ( isset( $_REQUEST['wpopt_cache_leftovers_clean'] ) ) {
				$this->cache_leftovers_clean();
			} elseif ( isset( $_REQUEST['wpopt_frontend_save'] ) ) {
				$this->save_frontend_perf_from_post();
			} elseif ( isset( $_REQUEST['wpopt_assetmin_save'] ) ) {
				$this->save_asset_optimize();
			} elseif ( isset( $_REQUEST['wpopt_cache_settings'] ) ) {
				$this->save_cache_settings();
			} elseif ( isset( $_REQUEST['wpopt_cf_save'] ) || isset( $_REQUEST['wpopt_cf_forget'] ) ) {
				$this->save_cloudflare( isset( $_REQUEST['wpopt_cf_forget'] ) );
			} elseif ( isset( $_REQUEST['wpopt_perf_heartbeat'] ) ) {
				$this->apply_heartbeat_mode( sanitize_key( wp_unslash( $_REQUEST['wpopt_perf_heartbeat'] ) ) );
			} elseif ( isset( $_REQUEST['wpopt_perf_heartbeat_zones'] ) ) {
				$this->save_heartbeat_zones();
			} elseif ( isset( $_REQUEST['wpopt_psi_run'] ) ) {
				$this->run_pagespeed_test();
			} elseif ( isset( $_REQUEST['wpopt_psi_key_save'] ) ) {
				$this->save_pagespeed_key();
			} elseif ( isset( $_REQUEST['wpopt_crux_run'] ) ) {
				$this->run_crux_history();
			}
			// The Performance assistant reuses these handlers but wants to land back on
			// its own tab; honour an explicit, whitelisted return tab.
			$ret_tab = (
				isset( $_REQUEST['wpopt_assetmin_save'] )
				|| isset( $_REQUEST['wpopt_psi_run'] ) || isset( $_REQUEST['wpopt_psi_key_save'] ) || isset( $_REQUEST['wpopt_crux_run'] )
				|| ( isset( $_REQUEST['wpopt_return_tab'] ) && 'performance' === sanitize_key( wp_unslash( $_REQUEST['wpopt_return_tab'] ) ) )
			) ? 'performance' : 'cache';
			$args = array(
				'page' => 'wpoptimizing',
				'tab'  => $ret_tab,
			);
			// Page-cache actions land back on the page sub-section.
			// Browser cache and the caches in front of the site: Cache › Browser & server.
			if ( isset( $_REQUEST['wpopt_browsercache_apply'] ) || isset( $_REQUEST['wpopt_browsercache_remove'] )
				|| isset( $_REQUEST['wpopt_edge_check'] ) || isset( $_REQUEST['wpopt_edge_save'] ) ) {
				$args['csec'] = 'browser';
			}
			// Query deduplication has its own sub-section.
			if ( isset( $_REQUEST['wpopt_cache_db_replace'] ) ) {
				$args['csec'] = 'dedup';
			}
			// Cloudflare has its own sub-section: land back there, next to its result.
			if ( isset( $_REQUEST['wpopt_cf_save'] ) || isset( $_REQUEST['wpopt_cf_forget'] ) || isset( $_REQUEST['wpopt_cf_edge'] ) ) {
				$args['csec'] = 'cloudflare';
			}
			// Performance sub-sections: each action lands back where it was pressed. Minify, delay
			// JS and critical CSS live in their own tab (Speed › CSS & JavaScript).
			if ( 'performance' === $ret_tab ) {
				if ( isset( $_REQUEST['wpopt_assetmin_save'] )
				) {
					$args['tab']  = 'frontend';
					$args['fsec'] = 'assets';
				} elseif ( isset( $_REQUEST['wpopt_psi_run'] ) || isset( $_REQUEST['wpopt_psi_key_save'] ) ) {
					$args['psec'] = 'summary';
				} else {
					$args['psec'] = 'server';
				}
			}
			// Front-end loading options: back to Speed › Page & browser › Loading & delivery.
			if ( isset( $_REQUEST['wpopt_frontend_save'] ) ) {
				$args = array(
					'page' => 'wpoptimizing',
					'tab'  => 'frontend',
					'fsec' => 'loading',
				);
			}
			// After a profiling test the report is captured synchronously, so land the user
			// straight on the dedicated report view instead of the top of the assistant.
			wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
			exit;
		}
		// History (performance analytics) actions.
		if ( isset( $_REQUEST['wpopt_history_export'] ) ) {
			$this->stream_history_csv(); // Streams and exits.
		}
		if ( isset( $_REQUEST['wpopt_history_baseline'] ) ) {
			$this->reset_history_baseline();
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'wpoptimizing',
						'tab'  => 'history',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		if ( isset( $_REQUEST['wpopt_history_retention'] ) ) {
			$val = (int) $_REQUEST['wpopt_history_retention'];
			if ( ! in_array( $val, array( 0, 3, 6, 12 ), true ) ) {
				$val = 3;
			}
			update_option( 'wpoptimizing_history_retention', $val, false );
			// Apply immediately (always keeping the pinned baseline + origin snapshot).
			if ( $val > 0 && class_exists( 'WPOPT_Performance_Logger' ) ) {
				( new WPOPT_Performance_Logger() )->prune_history( $val, (string) get_option( 'wpoptimizing_history_baseline_ts', '' ) );
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_history_retention',
					'msg'  => __( 'Timeline retention updated.', 'wpoptimizing' ),
					'type' => 'success',
				),
				60
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'wpoptimizing',
						'tab'  => 'history',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		// Settings portability (Settings → Import / export).
		if ( isset( $_REQUEST['wpopt_settings_export'] ) ) {
			$this->stream_settings_export(); // Streams JSON and exits.
		}
		if ( isset( $_POST['wpopt_importer_apply'] ) || isset( $_POST['wpopt_importer_undo'] ) ) {
			$this->handle_settings_importer( isset( $_POST['wpopt_importer_apply'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_importer_apply'] ) ) : '' );
			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'wpoptimizing-settings' ),
					admin_url( 'admin.php' )
				) . '#wpopt-set-importer'
			);
			exit;
		}
		if ( isset( $_POST['wpopt_settings_import'] ) ) {
			$this->handle_settings_import();
			wp_safe_redirect(
				add_query_arg(
					array( 'page' => 'wpoptimizing-settings' ),
					admin_url( 'admin.php' )
				) . '#wpopt-set-portability'
			);
			exit;
		}
		// WP-Cron trigger: switch WordPress off its per-page-load cron so a real
		// server cron can drive wp-cron.php (edits wp-config.php, backup-first).
		if ( isset( $_REQUEST['wpopt_wp_cron_apply'] ) && function_exists( 'wpoptimizing_wp_cron_apply' ) ) {
			// Editing wp-config.php affects the WHOLE install, so it takes the same gate as
			// the drop-ins: network administrators only on multisite. Without this, any
			// sub-site administrator (they all have manage_options) could switch WP-Cron off
			// for every site in the network.
			if ( ! $this->can_manage_dropin() ) {
				set_transient(
					'wpoptimizing_flash',
					array(
						'code' => 'wpoptimizing_wp_cron',
						'msg'  => __( 'You do not have permission to change the WP-Cron setting.', 'wpoptimizing' ),
						'type' => 'error',
					),
					60
				);
				wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wpoptimizing&tab=cron' ) );
				exit;
			}
			$disable = '1' === (string) sanitize_text_field( wp_unslash( $_REQUEST['wpopt_wp_cron_apply'] ) );
			$res     = wpoptimizing_wp_cron_apply( $disable );
			$codes   = array(
				'notfound'    => __( 'wp-config.php could not be located, so WP-Cron was left unchanged.', 'wpoptimizing' ),
				'notwritable' => __( 'wp-config.php is not writable. Add the constant manually using the snippet shown below.', 'wpoptimizing' ),
				'badformat'   => __( 'wp-config.php has an unexpected format, so it was left untouched. Add the constant manually.', 'wpoptimizing' ),
				'writefail'   => __( 'Could not save wp-config.php. Check file permissions and try again.', 'wpoptimizing' ),
				'nobackup'    => function_exists( 'wpoptimizing_backup_failure_message' ) ? wpoptimizing_backup_failure_message( wpoptimizing_backup_failure() ) : '',
			);
			if ( ! empty( $res['ok'] ) ) {
				$msg  = $disable
					? __( 'WP-Cron disabled. Now add the system-cron line shown below so scheduled tasks keep running.', 'wpoptimizing' )
					: __( 'WP-Cron re-enabled — WordPress will run scheduled tasks on page loads again.', 'wpoptimizing' );
				$type = 'updated';
			} else {
				$msg  = isset( $codes[ $res['code'] ] ) ? $codes[ $res['code'] ] : __( 'WP-Cron was left unchanged.', 'wpoptimizing' );
				$type = 'error';
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_wp_cron',
					'msg'  => $msg,
					'type' => $type,
				),
				60
			);
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing&tab=cron' ) );
			exit;
		}
		// Tables: reclaim overhead on just the tables that carry it (targeted OPTIMIZE).
		// The set is recomputed server-side, so a stale form can never optimize the
		// wrong tables. Non-destructive.
		if ( isset( $_REQUEST['wpopt_reclaim_overhead'] ) && method_exists( $this, 'reclaimable_overhead_table_names' ) ) {
			$names = $this->reclaimable_overhead_table_names();
			$count = $names ? (int) $this->optimize_table_list( $names ) : 0;
			$errs  = (array) get_transient( 'wpoptimizing_optimize_err' );
			delete_transient( 'wpoptimizing_optimize_err' );
			if ( ! $names ) {
				set_transient(
					'wpoptimizing_flash',
					array(
						'code' => 'wpoptimizing_optimize',
						'msg'  => __( 'No reclaimable overhead was found — nothing to optimize.', 'wpoptimizing' ),
						'type' => 'updated',
					),
					30
				);
			} else {
				$this->set_table_op_flash(
					'wpoptimizing_optimize',
					sprintf(
						/* translators: %d: number of tables optimized. */
						_n( '%d table optimized — overhead reclaimed.', '%d tables optimized — overhead reclaimed.', $count, 'wpoptimizing' ),
						$count
					),
					$errs
				);
			}
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing&tab=tables' ) );
			exit;
		}
		// Google Fonts self-hosting: download detected fonts locally, or remove them.
		// Activity log: clear the journal (keeps the baseline so tracking continues).
		if ( isset( $_REQUEST['wpopt_activity_clear'] ) && defined( 'WPOPT_ACTIVITY_OPTION' ) ) {
			// The journal lives in a file (blob store); the option only holds it when that write
			// fails. delete_option() alone said "cleared" and left the whole log in place.
			wpoptimizing_blob_delete( 'activity-log', WPOPT_ACTIVITY_OPTION );
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_activity',
					'msg'  => __( 'Activity log cleared. Tracking continues from now.', 'wpoptimizing' ),
					'type' => 'updated',
				),
				60
			);
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing&tab=activity' ) );
			exit;
		}
		// Growth nudge: one-click enable data sharing from the CTA/reminder (nonced link).
		if ( isset( $_REQUEST['wpopt_enable_sharing'] ) ) {
			check_admin_referer( 'wpopt_enable_sharing' );
			if ( current_user_can( wpoptimizing_cap() ) ) {
				if ( ! wpoptimizing_has_share_consent() && function_exists( 'wpoptimizing_audit_log' ) ) {
					wpoptimizing_audit_log(
						'data_sharing_consent',
						array(
							'enabled' => 1,
							'source'  => 'reminder',
						)
					);
				}
				update_option( 'wpoptimizing_share_consent', 1, true );
				delete_option( 'wpoptimizing_share_nudge_snooze' );
				if ( function_exists( 'wpoptimizing_sync_kb_now' ) ) {
					wpoptimizing_sync_kb_now(); // Push contributions + pull the shared dataset right away.
				}
				set_transient(
					'wpoptimizing_flash',
					array(
						'code' => 'wpoptimizing_share_on',
						'msg'  => __( 'Thank you! Data sharing is now on — your attribution metadata helps everyone, and your site now receives the shared dataset too.', 'wpoptimizing' ),
						'type' => 'success',
					),
					60
				);
			}
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing' ) );
			exit;
		}
		// Growth nudge: snooze the periodic sharing reminder for one interval.
		if ( isset( $_REQUEST['wpopt_share_snooze'] ) ) {
			check_admin_referer( 'wpopt_share_snooze' );
			if ( current_user_can( wpoptimizing_cap() ) && function_exists( 'wpoptimizing_share_nudge_interval' ) ) {
				update_option( 'wpoptimizing_share_nudge_snooze', time() + wpoptimizing_share_nudge_interval(), false );
			}
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing' ) );
			exit;
		}
		// Manual KB sync (Settings → Shared dataset): upload contributions (if sharing
		// is on) and refresh the local dataset, with a per-file report.
		if ( isset( $_REQUEST['wpopt_sync_kb'] ) && function_exists( 'wpoptimizing_sync_kb_now' ) ) {
			$r = wpoptimizing_sync_kb_now();
			/*
			 * LA DESCARGA SE CUENTA POR CAPAS, y las que no llegaron se nombran con su motivo.
			 * Antes era el código de una sola sonda a `?file=db`, así que «Download: OK» salía
			 * igual de verde con seis capas de siete caídas. Así pasó meses sin llegar
			 * `manifest`, la lista blanca de extensiones conocidas.
			 */
			$capas    = isset( $r['layers'] ) && is_array( $r['layers'] ) ? $r['layers'] : array();
			$fallidas = array();
			foreach ( $capas as $capa => $resultado ) {
				if ( 'ok' === $resultado ) {
					continue;
				}
				$motivo     = function_exists( 'wpoptimizing_kb_download_reason_label' )
					? wpoptimizing_kb_download_reason_label( $resultado )
					: '';
				$nombre     = function_exists( 'wpoptimizing_kb_layer_label' ) ? wpoptimizing_kb_layer_label( $capa ) : $capa;
				$fallidas[] = '' !== $motivo ? $nombre . ' (' . $motivo . ')' : $nombre;
			}
			if ( empty( $capas ) ) {
				$dl = __( 'nothing to download', 'wpoptimizing' );
			} elseif ( empty( $fallidas ) ) {
				$dl = sprintf(
					/* translators: %d: number of knowledge-base layers downloaded. */
					__( 'all %d layers', 'wpoptimizing' ),
					count( $capas )
				);
			} else {
				$dl = sprintf(
					/* translators: 1: layers downloaded, 2: total layers, 3: list of layers that did not arrive, with the reason. */
					__( '%1$d of %2$d layers. Did not arrive — %3$s', 'wpoptimizing' ),
					count( $capas ) - count( $fallidas ),
					count( $capas ),
					implode( '; ', $fallidas )
				);
			}
			if ( empty( $r['consent'] ) ) {
				$msg = __( 'Data sharing is off, so nothing was uploaded.', 'wpoptimizing' ) . ' '
					. sprintf(
						/* translators: %s: download result. */
						__( 'Download: %s.', 'wpoptimizing' ),
						$dl
					);
			} else {
				$layer_labels = array(
					'db'       => __( 'database', 'wpoptimizing' ),
					'files'    => __( 'files', 'wpoptimizing' ),
					'assets'   => __( 'assets', 'wpoptimizing' ),
					'autoload' => __( 'autoload', 'wpoptimizing' ),
				);
				// A layer the upload gate held back reports why, in words. A manual sync never
				// waits for the spacing, so 'wait' cannot reach this screen.
				$held_labels = array(
					'backoff' => __( 'paused after repeated failures', 'wpoptimizing' ),
					'budget'  => __( 'daily limit reached, it goes out tomorrow', 'wpoptimizing' ),
				);
				$parts       = array();
				foreach ( (array) $r['upload'] as $k => $code ) {
					$label   = isset( $layer_labels[ $k ] ) ? $layer_labels[ $k ] : $k;
					$parts[] = $label . ': ' . ( isset( $held_labels[ (string) $code ] ) ? $held_labels[ (string) $code ] : (string) $code );
				}
				$msg = sprintf(
					/* translators: 1: per-layer upload result, 2: download result. */
					__( 'Shared dataset synced. Upload — %1$s. Download: %2$s.', 'wpoptimizing' ),
					$parts ? implode( ', ', $parts ) : __( 'nothing to send', 'wpoptimizing' ),
					$dl
				);
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_kb_sync',
					'msg'  => $msg,
					'type' => 'updated',
				),
				60
			);
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing-settings' ) );
			exit;
		}
		if ( isset( $_REQUEST['wpopt_gc_download_report'] ) ) {
			$report_id = isset( $_REQUEST['wpopt_gc_report_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpopt_gc_report_id'] ) ) : '';
			$this->get_general_cleanup_service()->download_report( $report_id );
		}
		if ( isset( $_REQUEST['wpopt_gc_view_report'] ) ) {
			$report_id = isset( $_REQUEST['wpopt_gc_report_id'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpopt_gc_report_id'] ) ) : '';
			$this->get_general_cleanup_service()->print_report_html( $report_id );
		}
		if ( isset( $_REQUEST['wpopt_gc_apply_profile'] ) ) {
			$profile_key = isset( $_REQUEST['wpopt_gc_profile'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpopt_gc_profile'] ) ) : '';
			$applied     = $this->get_general_cleanup_service()->apply_profile( $profile_key );
			// Apply the profile's built-in performance flags (object cache, query
			// deduplication, heartbeat). These are integral to each profile — no
			// separate checkbox needed. Each action is a no-op when already done
			// and is safe + reversible from the Cache tab.
			if ( $applied && class_exists( 'WPOPT_General_Cleanup' ) ) {
				$perf_map = WPOPT_General_Cleanup::profile_performance_map();
				$perf     = isset( $perf_map[ $profile_key ] ) ? $perf_map[ $profile_key ] : array();
				if ( ! empty( $perf['enable_object_cache'] ) ) {
					$this->wizard_install_object_cache();
				}
				if ( ! empty( $perf['enable_query_dedup'] ) ) {
					$this->wizard_install_db_dropin();
				}
				if ( ! empty( $perf['heartbeat'] ) || ! empty( $perf['enable_admin_counts'] ) ) {
					$cache_settings = $this->get_cache_settings();
					$heartbeat      = isset( $perf['heartbeat'] ) ? sanitize_key( (string) $perf['heartbeat'] ) : '';
					if ( in_array( $heartbeat, array( 'default', 'slow', 'off' ), true ) ) {
						// The preset sets every zone, so a profile always lands the same way.
						$cache_settings = function_exists( 'wpoptimizing_heartbeat_with_preset' ) ? wpoptimizing_heartbeat_with_preset( $cache_settings, $heartbeat ) : array_merge( $cache_settings, array( 'heartbeat' => $heartbeat ) );
					}
					if ( ! empty( $perf['enable_admin_counts'] ) ) {
						$cache_settings['admin_counts'] = 1;
					}
					update_option( 'wpoptimizing_cache_settings', $cache_settings, false );
				}
				// Self-hosted Google Fonts: the option is set by apply_profile(); here
				// we queue the one-time discovery + download so it works end-to-end
				// without holding this request (see wpoptimizing_gfonts_queue_prime()).
				if ( ! empty( $perf['google_fonts'] ) && function_exists( 'wpoptimizing_gfonts_queue_prime' ) ) {
					wpoptimizing_gfonts_queue_prime();
				}
			}
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_profile',
					'msg'  => $applied ? __( 'Cleanup profile applied.', 'wpoptimizing' ) : __( 'Unknown cleanup profile.', 'wpoptimizing' ),
					'type' => $applied ? 'updated' : 'error',
				),
				60
			);
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing-settings' ) );
			exit;
		}
		// Media analysis is no longer a standalone action: it runs as a stage of
		// the unified "Analyze now" scan, so the Media tab stays fed automatically.


		// A change that could not be backed up did not run: say so, over any other notice.
		if ( function_exists( 'wpoptimizing_backup_flash_failure' ) ) {
			wpoptimizing_backup_flash_failure();
		}

		// Schedule a background rescan.
		if ( $did_action ) {
			wpoptimizing_schedule_scan( 'post-clean' );
			// Only when the action left no notice of its own. This used to overwrite every one of
			// them: "nothing was deleted, the backup failed" and "still there after deleting it"
			// both reached the screen as "Actions executed".
			if ( false === get_transient( 'wpoptimizing_flash' ) ) {
				set_transient(
					'wpoptimizing_flash',
					array(
						'code' => 'wpoptimizing_saved',
						'msg'  => __( 'Actions executed. Run a rescan to refresh the lists.', 'wpoptimizing' ),
						'type' => 'updated',
					),
					60
				);
			}

			$tab  = isset( $_REQUEST['tab'] ) ? sanitize_key( wp_unslash( $_REQUEST['tab'] ) ) : '';
			$isec = isset( $_REQUEST['isec'] ) ? sanitize_key( wp_unslash( $_REQUEST['isec'] ) ) : '';
			$osec = isset( $_REQUEST['osec'] ) ? sanitize_key( wp_unslash( $_REQUEST['osec'] ) ) : '';
			$asec = isset( $_REQUEST['asec'] ) ? sanitize_key( wp_unslash( $_REQUEST['asec'] ) ) : '';
			$gsec = isset( $_REQUEST['wpopt_gc_section'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpopt_gc_section'] ) ) : '';

			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=wpoptimizing' );
			if ( '' !== $tab ) {
				$redirect = add_query_arg( 'tab', $tab, $redirect );
			}
			if ( '' !== $isec ) {
				$redirect = add_query_arg( 'isec', $isec, $redirect );
			}
			if ( '' !== $osec ) {
				$redirect = add_query_arg( 'osec', $osec, $redirect );
			}
			if ( '' !== $asec ) {
				$redirect = add_query_arg( 'asec', $asec, $redirect );
			}
			if ( '' !== $gsec ) {
				$redirect = add_query_arg( 'wpopt_gc_section', $gsec, $redirect );
			}
			wp_safe_redirect( $redirect );
			exit;
		}
	}

	/**
	 * Main render with tabs, filters, and pagination.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( $this->cap ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions.', 'wpoptimizing' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$last_scan_ts = (int) get_option( 'wpoptimizing_last_scan', 0 );
		// Home is where the plugin opens: it only diagnoses. It used to open on General cleanup,
		// then the one deletion with no backup (UX audit, 15/10/2026).
		$tab          = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'home'; // phpcs:ignore
		if ( $this->forced_tab ) {
			$tab = $this->forced_tab;
		}
		// Performance › Browser became its own tab (Speed › CSS & JavaScript): old links still land.
		if ( 'performance' === $tab && isset( $_GET['psec'] ) && 'browser' === sanitize_key( wp_unslash( $_GET['psec'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
			$tab = 'frontend';
		}
		// "Analysis results" lands on a state; which one is decided once the counts are known.
		$review_entry = ( 'review' === $tab );
		if ( $review_entry ) {
			$tab = 'orphans';
		}
		$allowed_tabs    = $this->show_tabs ? array_keys( $this->admin_tab_labels() ) : array( 'settings' );
		$orphans_section = isset( $_GET['osec'] ) ? sanitize_key( wp_unslash( $_GET['osec'] ) ) : ''; // phpcs:ignore
		if ( 'db' === $tab ) {
			$tab             = 'orphans';
			$orphans_section = 'bd';
		} elseif ( 'fs' === $tab ) {
			$tab             = 'orphans';
			$orphans_section = 'fs';
		}
		$tab = in_array( $tab, $allowed_tabs, true ) ? $tab : $allowed_tabs[0];
		if ( ( 'orphans' === $tab || 'pending' === $tab ) && '' === $orphans_section ) {
			$orphans_section = 'bd';
		}

		/*
		 * EL ANÁLISIS SÓLO DONDE SE ENSEÑA. Leer, descifrar y atribuir el análisis entero (las
		 * capas remotas, el mapa de ejecución, cada fila, las consultas sobre los meta) costaba
		 * unos 3,5 s por carga en un sitio grande, y se hacía en TODAS las pestañas: también en
		 * Documentación o Caché, que no lo usan (Mundotoro, 06/11/2026: 5–6,5 s cada pestaña
		 * frente a 1,8 s del escritorio). Fuera de las pestañas de revisión basta con el número
		 * de «Por identificar» del menú, que se guarda cada vez que se calcula de verdad.
		 */
		$empty_split = array(
			'active'   => array(),
			'inactive' => array(),
			'orphan'   => array(),
			'none'     => array(),
		);
		$needs_scan  = $review_entry || in_array( $tab, array( 'home', 'orphans', 'pending', 'inactive', 'empty', 'active' ), true );
		$bd_split    = $empty_split;
		$fs_split    = $empty_split;
		if ( $needs_scan ) {
			// Enrich the runtime map on every tab open (rate-limited to 6 h for the DB scan).
			if ( class_exists( 'WPOPT_Runtime_Resolver' ) ) {
				( new WPOPT_Runtime_Resolver() )->enrich( false );
			}

			// CSVs (read once)
			$bd_rows = $this->read_orphans_db();   // keys in EN: type, value, suggested_status, project_status, ...
			$bd_rows = $this->overlay_runtime_attributions( $bd_rows );
			$fs_rows = $this->read_orphans_fs();   // keys in ES:  tipo, ruta_relativa, proyecto_estado, ...

			// Freemium teaser is applied PER SECTION (see freemium_section_teaser()): each gated
			// list shows its own first 25 rows clearly plus a blurred teaser and reports its own
			// true total, instead of every tab sharing one global row budget — which starved
			// Pending/Orphans/Inactive (they split a single 40-row slice) and corrupted their
			// counts. The full dataset is split below FIRST, so tab counts and per-section totals
			// stay accurate; the cap is then applied at each list's own render site.

			$bd_split = $this->split_rows_by_effective_status( $bd_rows );
			// Drop already-deleted orphans (stale scan CSV) so the Orphans tab, the "Needs
			// attention" notice and the counts never show records that no longer exist.
			if ( ! empty( $bd_split['orphan'] ) ) {
				$bd_split['orphan'] = $this->filter_present_db_orphans( $bd_split['orphan'] );
			}
			$fs_split = $this->split_rows_by_effective_status( $fs_rows );
			// Same for file orphans: drop entries whose file no longer exists on disk.
			if ( ! empty( $fs_split['orphan'] ) ) {
				$fs_split['orphan'] = $this->filter_present_fs_orphans( $fs_split['orphan'] );
			}

			// Reused by the Tables tab to attribute owner/confidence from the SAME data,
			// so it never re-reads the scan CSV (which caused the values to flicker).
			$this->bd_split_cache = $bd_split;

			// FS splits (strict rules mirroring DB):
			$fs_orphan   = $fs_split['orphan'];
			$fs_none     = $fs_split['none'];
			$fs_inactive = $fs_split['inactive'];
			$fs_active   = $fs_split['active'];

			// Entering from the menu: the first state with something in it, safest first.
			if ( $review_entry && empty( $bd_split['orphan'] ) && empty( $fs_split['orphan'] ) ) {
				if ( ! empty( $bd_split['none'] ) || ! empty( $fs_split['none'] ) ) {
					$tab = 'pending';
				} elseif ( ! empty( $bd_split['inactive'] ) || ! empty( $fs_split['inactive'] ) ) {
					$tab = 'inactive';
				}
			}
			wpoptimizing_pending_count_remember( count( $bd_split['none'] ) + count( $fs_split['none'] ) );
		}

		$pending_total = $needs_scan ? count( $bd_split['none'] ) + count( $fs_split['none'] ) : wpoptimizing_pending_count_cached();
		$pending_label = $pending_total > 0
			? sprintf( '%s (%d)', __( 'To identify', 'wpoptimizing' ), $pending_total )
			: __( 'To identify', 'wpoptimizing' );

		// Row cap notice (large installs).
		if ( $this->scan_rows_capped ) {
			$max_rows = defined( 'WPOPT_MAX_SCAN_ROWS' ) ? (int) WPOPT_MAX_SCAN_ROWS : 50000;
			printf(
				'<div class="notice notice-warning inline wpopt-notice"><p>%s</p></div>',
				wp_kses_post(
					sprintf(
					/* translators: 1: row limit, 2: constant name */
						__( '<strong>Large installation:</strong> Results are limited to %1$d rows to prevent memory exhaustion. Add <code>define( \'%2$s\', 100000 );</code> to wp-config.php to raise the limit.', 'wpoptimizing' ),
						$max_rows,
						'WPOPT_MAX_SCAN_ROWS'
					)
				)
			);
		}

		// The free-plan "showing 25 of N" notice is rendered PER SECTION now, by
		// freemium_section_teaser() at each gated list's own render site, so each list reports
		// its own true total instead of one global figure shared across all the record tabs.

		// "Needs attention" sits full-width directly under the Site status cards
		// (rendered just before this method), not buried in the General tab's content
		// column — clearer and more prominent. Only on the General landing tab.
		if ( $this->show_tabs ) {
			// Vertical side navigation: tabs in a left column, content on the right.
			// Collapses to icons-only via the bottom toggle (state in localStorage). Expanded
			// by default: icons alone did not tell Autoload from Cache to a first-time user.
			// The remembered collapsed state is applied HERE, the moment the layout element
			// exists and before the menu is painted. It used to run after the whole tab had
			// rendered, so on a heavy screen (To identify, the page after a delete) the menu
			// showed expanded while the content streamed in and then folded on its own.
			echo '<div class="wpopt-layout">';
			wp_print_inline_script_tag( '(function(){try{if(localStorage.getItem("wpoptSidebarCollapsed")==="1"){var s=document.currentScript;if(s&&s.parentNode){s.parentNode.classList.add("wpopt-collapsed");}}}catch(e){}}());' );
			echo '<nav class="nav-tab-wrapper wpopt-sidebar" aria-label="' . esc_attr__( 'WPOptimizing sections', 'wpoptimizing' ) . '">';
			// Mobile-only hamburger: collapses the tab list behind a tap target.
			echo '<button type="button" class="wpopt-mobile-toggle" onclick="var l=this.closest(&quot;.wpopt-layout&quot;);if(l){this.setAttribute(&quot;aria-expanded&quot;,l.classList.toggle(&quot;wpopt-mobile-open&quot;)?&quot;true&quot;:&quot;false&quot;);}" aria-expanded="false" aria-label="' . esc_attr__( 'Menu', 'wpoptimizing' ) . '">' . wpoptimizing_icon( 'menu' ) . ' ' . esc_html( $this->admin_tab_labels( $pending_label )[ $tab ] ?? __( 'Menu', 'wpoptimizing' ) ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			// Compact health badge so the score (and a quick read on TTFB) is visible on
			// every tab, not just the General landing.
			$this->render_sidebar_health();
			foreach ( $this->admin_nav_groups( $pending_label ) as $wpopt_group ) {
				if ( '' === $wpopt_group['label'] ) {
					echo '<div class="wpopt-nav-group">';
				} else {
					echo '<div class="wpopt-nav-group" role="group" aria-label="' . esc_attr( $wpopt_group['label'] ) . '"><span class="wpopt-nav-group-label" aria-hidden="true">' . esc_html( $wpopt_group['label'] ) . '</span>';
				}
				if ( isset( $wpopt_group['single'] ) ) {
					$this->tab_link( $wpopt_group['single'][0], $wpopt_group['single'][1], isset( $wpopt_group['tabs'][ $tab ] ) ? $wpopt_group['single'][0] : $tab );
				} else {
					foreach ( $wpopt_group['tabs'] as $wpopt_tab_key => $wpopt_tab_label ) {
						$this->tab_link( $wpopt_tab_key, $wpopt_tab_label, $tab );
					}
				}
				echo '</div>';
			}
			// Settings is its own admin page, but it belongs in this menu: it was reachable only
			// from the WordPress sidebar.
			printf(
				'<div class="wpopt-nav-group"><a href="%s" class="nav-tab" title="%s">' . wpoptimizing_icon( 'admin-settings' ) . '<span class="wpopt-tab-label">%s</span></a></div>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
				esc_url( admin_url( 'admin.php?page=wpoptimizing-settings' ) ),
				esc_attr__( 'Settings', 'wpoptimizing' ),
				esc_html__( 'Settings', 'wpoptimizing' )
			);
			echo '<button type="button" class="wpopt-sidebar-toggle" onclick="var l=this.closest(&quot;.wpopt-layout&quot;);if(l){var c=l.classList.toggle(&quot;wpopt-collapsed&quot;);this.setAttribute(&quot;aria-expanded&quot;,c?&quot;false&quot;:&quot;true&quot;);try{localStorage.setItem(&quot;wpoptSidebarCollapsed&quot;,c?&quot;1&quot;:&quot;0&quot;);}catch(e){}}" aria-expanded="true" aria-label="' . esc_attr__( 'Collapse or expand the menu', 'wpoptimizing' ) . '">' . wpoptimizing_icon( 'arrow-left-alt2' ) . '<span class="wpopt-tab-label">' . esc_html__( 'Collapse menu', 'wpoptimizing' ) . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			wp_print_inline_script_tag( '(function(){var t=document.currentScript&&document.currentScript.previousElementSibling;if(t&&t.closest(".wpopt-collapsed")){t.setAttribute("aria-expanded","false");}}());' );
			echo '</nav><div class="wpopt-content">';
		}

		// We'll render a GET form for filters/search and a POST form for actions/checkboxes.

		// Result notice for the just-performed action, rendered at the top of the active
		// tab/section's content (where it happened) instead of at the top of the page.
		// Covers both the transient flash and same-request add_settings_error notices
		// (general cleanup, backups, license) so every generated message stays in context.
		$this->render_pending_flash();
		settings_errors( 'wpoptimizing' );
		$this->render_share_nudge();

		if ( in_array( $tab, array( 'orphans', 'pending', 'inactive', 'empty', 'active' ), true ) ) {
			$this->render_review_states(
				$tab,
				array(
					'orphans'  => count( $bd_split['orphan'] ) + count( $fs_split['orphan'] ),
					'pending'  => count( $bd_split['none'] ) + count( $fs_split['none'] ),
					'inactive' => count( $bd_split['inactive'] ) + count( $fs_split['inactive'] ),
					'empty'    => $last_scan_ts > 0 ? count( $this->read_empty_db() ) : 0,
					'active'   => count( $bd_split['active'] ) + count( $fs_split['active'] ),
				)
			);
		}

		if ( 'home' === $tab ) {
			$this->render_home_tab(
				$last_scan_ts,
				count( $bd_split['orphan'] ),
				count( $fs_split['orphan'] ),
				array(
					'orphans'  => count( $bd_split['orphan'] ) + count( $fs_split['orphan'] ),
					'pending'  => count( $bd_split['none'] ) + count( $fs_split['none'] ),
					'inactive' => count( $bd_split['inactive'] ) + count( $fs_split['inactive'] ),
					'empty'    => $last_scan_ts > 0 ? count( $this->read_empty_db() ) : 0,
					'active'   => count( $bd_split['active'] ) + count( $fs_split['active'] ),
				)
			);
		} elseif ( 'general' === $tab ) {
			$this->render_general_cleanup_tab();
		} elseif ( 'orphans' === $tab ) {
			$db_orphans = $bd_split['orphan'];

			// Subsection: bd | fs (default bd).
			$osec = $orphans_section;
			if ( ! in_array( $osec, array( 'bd', 'fs' ), true ) ) {
				$osec = 'bd';
			}

			echo '<ul class="subsubsub wpopt-subsubsub">';
			$this->orphans_menu_link( 'bd', __( 'Database', 'wpoptimizing' ), $osec, count( $db_orphans ) );
			echo ' | ';
			$this->orphans_menu_link( 'fs', __( 'Files', 'wpoptimizing' ), $osec, count( $fs_orphan ) );
			echo '</ul>';
			echo '<br class="clear" />';
			echo '<input type="hidden" name="osec" value="' . esc_attr( $osec ) . '" />';

			if ( 'bd' === $osec ) {
				$this->render_section_header(
					__( 'Database', 'wpoptimizing' ),
					__( 'Entries confirmed as belonging to extensions that are no longer installed. Nothing on the site uses them; delete them unless you plan to reinstall that extension and keep its settings (a backup is made first).', 'wpoptimizing' ),
					array(
						'icon' => 'database',
						'doc'  => 'wpopt-doc-orphans',
					)
				);
				if ( 0 === $last_scan_ts ) {
					$this->render_scan_pending_notice();
					return;
				}
				if ( empty( $db_orphans ) ) {
					$this->render_empty_section_notice( __( 'Nothing was left behind by uninstalled extensions. If you no longer use a plugin or theme, uninstall it from the Inactive tab and re-run the analysis.', 'wpoptimizing' ) );
					return;
				}

				$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				$pp = isset( $_REQUEST['db_pp'] ) ? (int) $_REQUEST['db_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
					$pp = 50;
				}
				$type_filter = '';
				if ( isset( $_REQUEST['db_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['db_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
						$type_filter = '';
					}
				}

				$type_choices = $this->collect_types( 'db', $db_orphans );
				if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
					$type_filter = '';
				}
				$ext_filter = '';
				if ( isset( $_REQUEST['db_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['db_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
						$ext_filter = '';
					}
				}
				$project_choices = $this->collect_project_choices( $db_orphans );
				if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
					$ext_filter = '';
				}

				$rows = $this->apply_tipo_filter_bd( $db_orphans, $type_filter, $q );
				$rows = $this->apply_extension_filter( $rows, $ext_filter );
				$table = new WPOptimizing_DB_List_Table(
					$this,
					$rows,
					false,
					$this->should_show_projects_column( $rows ),
					array(
						'scope'           => 'db',
						'per_page'        => $pp,
						'type_filter'     => $type_filter,
						'type_choices'    => $type_choices,
						'project_filter'  => $ext_filter,
						'project_choices' => $project_choices,
						'hidden_fields'   => array(
							'tab'  => 'orphans',
							'osec' => 'bd',
						),
					)
				);
				$table->prepare_items();
				$this->render_table_form( $table, 'orphans', $osec );
				if ( function_exists( 'wpoptimizing_manual_attributions' ) ) {
					$this->render_manual_attribution_panel( $table->bulk_cb_name(), 'bd', 'orphans' );
				}
				// El asistente también aquí: donde hay un botón de borrar es donde más falta hace
				// poder preguntarle al conjunto compartido si el dueño que se enseña es el bueno.
				if ( method_exists( $this, 'render_attr_suggest_panel' ) ) {
					$this->render_attr_suggest_panel( (array) $table->items, 'orphans' );
				}
			} else {
				$this->render_section_header(
					__( 'Files', 'wpoptimizing' ),
					__( 'Files and folders confirmed as belonging to extensions that are no longer installed. Nothing on the site uses them; delete them unless you plan to reinstall that extension and keep its data (a backup is made first).', 'wpoptimizing' ),
					array(
						'icon' => 'media-default',
						'doc'  => 'wpopt-doc-orphans',
					)
				);

				if ( 0 === $last_scan_ts ) {
					$this->render_scan_pending_notice();
					return;
				}
				if ( empty( $fs_orphan ) ) {
					$this->render_empty_section_notice( __( 'Nothing was left behind by uninstalled extensions. If you no longer use a plugin or theme, uninstall it from the Inactive tab and re-run the analysis.', 'wpoptimizing' ) );
					return;
				}

				$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				$pp = isset( $_REQUEST['fs_pp'] ) ? (int) $_REQUEST['fs_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
					$pp = 50;
				}
				$type_filter = '';
				if ( isset( $_REQUEST['fs_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['fs_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
						$type_filter = '';
					}
				}
				$type_choices = $this->collect_types( 'fs', $fs_orphan );
				if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
					$type_filter = '';
				}
				$ext_filter = '';
				if ( isset( $_REQUEST['fs_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['fs_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
						$ext_filter = '';
					}
				}
				$project_choices = $this->collect_project_choices( $fs_orphan );
				if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
					$ext_filter = '';
				}

				$rows = $this->apply_tipo_filter_fs( $fs_orphan, $type_filter, $q );
				$rows = $this->apply_extension_filter( $rows, $ext_filter );

				$table = new WPOptimizing_FS_List_Table(
					$this,
					$rows,
					false,
					$this->should_show_projects_column( $rows ),
					array(
						'scope'           => 'fs',
						'per_page'        => $pp,
						'type_filter'     => $type_filter,
						'type_choices'    => $type_choices,
						'project_filter'  => $ext_filter,
						'project_choices' => $project_choices,
						'hidden_fields'   => array(
							'tab'  => 'orphans',
							'osec' => 'fs',
						),
					)
				);
				$table->prepare_items();
				$this->render_table_form( $table, 'orphans', $osec );
				// The table offers "Attribute selected": without the panel it ships the rows to, the
				// button stayed at 0 and a folder of an installed plugin (uploads/mainwp of a
				// white-labelled MainWP Child) could only be deleted.
				if ( function_exists( 'wpoptimizing_manual_attributions' ) ) {
					$this->render_manual_attribution_panel( $table->bulk_cb_name(), 'fs', 'orphans' );
				}
			}
		} elseif ( 'pending' === $tab ) {
			$this->render_pending_tab( $bd_split['none'], $fs_none, $orphans_section, $last_scan_ts );
		} elseif ( 'empty' === $tab ) {
			$this->render_empty_tab( $last_scan_ts );
		} elseif ( 'inactive' === $tab ) {
			// Split FS by project_status once (already done arriba como $fs_inactive).
			$inactive_bd = $bd_split['inactive'];
			$inactive_fs = $fs_inactive;

			// Build full inactive sets from the system (not from CSV), so counts match UI.
			$inactive_plugin_slugs_all = $this->get_all_inactive_plugin_slugs(); // e.g., 101
			$inactive_theme_slugs_all  = $this->get_all_inactive_theme_slugs();  // …

			// Subsection: bd | fs | proj (default bd)
			$isec = isset( $_GET['isec'] ) ? sanitize_key( wp_unslash( $_GET['isec'] ) ) : 'bd'; // phpcs:ignore
			if ( ! in_array( $isec, array( 'bd', 'fs', 'proj' ), true ) ) {
				$isec = 'bd';
			}

			// --- MENU (not tabs) ---
			echo '<ul class="subsubsub wpopt-subsubsub">';
			$this->inactive_menu_link( 'bd', __( 'Database', 'wpoptimizing' ), $isec, count( $inactive_bd ) );
			echo ' | ';
			$this->inactive_menu_link( 'fs', __( 'Files', 'wpoptimizing' ), $isec, count( $inactive_fs ) );
			echo ' | ';
			$this->inactive_menu_link( 'proj', __( 'Plugins & Themes', 'wpoptimizing' ), $isec, ( count( $inactive_plugin_slugs_all ) + count( $inactive_theme_slugs_all ) ) );
			echo '</ul>';
			echo '<br class="clear" />';
			echo '<input type="hidden" name="isec" value="' . esc_attr( $isec ) . '" />';

			// --- CONTENT by active subsection ---
			if ( 'bd' === $isec ) {
				$this->render_section_header(
					__( 'Database', 'wpoptimizing' ),
					__( 'This section lists options, tables, user roles, data types, and taxonomies that belong to installed but currently inactive plugins or themes. These items are not required for the site to run now, though they may be needed again if those extensions are reactivated.', 'wpoptimizing' ),
					array(
						'icon' => 'database',
						'doc'  => 'wpopt-doc-inactive',
					)
				);
				echo '<p class="description wpopt-desc-tight">' . wp_kses(
					__( 'You can select or deselect all rows from the header checkbox, or manually mark and unmark the ones you want to keep or remove. Once you have reviewed your selection, click <strong>Delete</strong> to apply the changes.', 'wpoptimizing' ),
					array( 'strong' => array() )
				) . '</p>';
				if ( 0 === $last_scan_ts ) {
					$this->render_scan_pending_notice();
					return;
				}
				if ( empty( $inactive_bd ) ) {
					$this->render_empty_section_notice( __( 'Nothing here belongs to inactive extensions. If you stopped using a plugin or theme, uninstall it from the Plugins & Themes list so WPOptimizing can flag anything it leaves behind.', 'wpoptimizing' ) );
					return;
				}
				$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				$pp = isset( $_REQUEST['ibd_pp'] ) ? (int) $_REQUEST['ibd_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
					$pp = 50;
				}
				$type_filter = '';
				if ( isset( $_REQUEST['ibd_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['ibd_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
						$type_filter = '';
					}
				}
				$type_choices = $this->collect_types( 'ibd', $inactive_bd );
				if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
					$type_filter = '';
				}
				$ext_filter = '';
				if ( isset( $_REQUEST['ibd_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['ibd_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
						$ext_filter = '';
					}
				}
				$project_choices = $this->collect_project_choices( $inactive_bd );
				if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
					$ext_filter = '';
				}

				$rows = $this->apply_tipo_filter_bd( $inactive_bd, $type_filter, $q );
				$rows = $this->apply_extension_filter( $rows, $ext_filter );

				$table = new WPOptimizing_DB_List_Table(
					$this,
					$rows,
					false,
					$this->should_show_projects_column( $rows ),
					array(
						'scope'           => 'ibd',
						'per_page'        => $pp,
						'type_filter'     => $type_filter,
						'type_choices'    => $type_choices,
						'project_filter'  => $ext_filter,
						'project_choices' => $project_choices,
						'hidden_fields'   => array(
							'tab'  => 'inactive',
							'isec' => 'bd',
						),
					)
				);
				$table->prepare_items();
				$this->render_table_form( $table, 'inactive', $isec );
				if ( function_exists( 'wpoptimizing_manual_attributions' ) ) {
					$this->render_manual_attribution_panel( $table->bulk_cb_name(), 'bd', 'inactive' );
				}
			} elseif ( 'fs' === $isec ) {
				$this->render_section_header(
					__( 'Files', 'wpoptimizing' ),
					__( 'This section lists files and folders that belong to installed but currently inactive plugins or themes. These items are not required for the site to run now, though they may be needed again if those extensions are reactivated.', 'wpoptimizing' ),
					array(
						'icon' => 'media-default',
						'doc'  => 'wpopt-doc-inactive',
					)
				);
				echo '<p class="description wpopt-desc-tight">' . wp_kses(
					__( 'You can select or deselect all rows from the header checkbox, or manually mark and unmark the ones you want to keep or remove. Once you have reviewed your selection, click <strong>Delete</strong> to apply the changes.', 'wpoptimizing' ),
					array( 'strong' => array() )
				) . '</p>';
				if ( 0 === $last_scan_ts ) {
					$this->render_scan_pending_notice();
					return;
				}
				if ( empty( $inactive_fs ) ) {
					$this->render_empty_section_notice( __( 'Nothing here belongs to inactive extensions. If you stopped using a plugin or theme, uninstall it from the Plugins & Themes list so WPOptimizing can flag anything it leaves behind.', 'wpoptimizing' ) );
					return;
				}
				$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				$pp = isset( $_REQUEST['ifs_pp'] ) ? (int) $_REQUEST['ifs_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
				if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
					$pp = 50;
				}
				$type_filter = '';
				if ( isset( $_REQUEST['ifs_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['ifs_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
						$type_filter = '';
					}
				}
				$type_choices = $this->collect_types( 'ifs', $inactive_fs );
				if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
					$type_filter = '';
				}
				$ext_filter = '';
				if ( isset( $_REQUEST['ifs_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['ifs_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
					if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
						$ext_filter = '';
					}
				}
				$project_choices = $this->collect_project_choices( $inactive_fs );
				if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
					$ext_filter = '';
				}

				$rows = $this->apply_tipo_filter_fs( $inactive_fs, $type_filter, $q );
				$rows = $this->apply_extension_filter( $rows, $ext_filter );

				$table = new WPOptimizing_FS_List_Table(
					$this,
					$rows,
					false,
					$this->should_show_projects_column( $rows ),
					array(
						'scope'           => 'ifs',
						'per_page'        => $pp,
						'type_filter'     => $type_filter,
						'type_choices'    => $type_choices,
						'project_filter'  => $ext_filter,
						'project_choices' => $project_choices,
						'hidden_fields'   => array(
							'tab'  => 'inactive',
							'isec' => 'fs',
						),
					)
				);
				$table->prepare_items();
				$this->render_table_form( $table, 'inactive', $isec );
				// Same as Orphans › Files: the "Attribute selected" button needs its panel.
				if ( function_exists( 'wpoptimizing_manual_attributions' ) ) {
					$this->render_manual_attribution_panel( $table->bulk_cb_name(), 'fs', 'inactive' );
				}
			} else { // 'proj'
				// Render the same real inactive sets used in the menu count.
				$this->render_section_header(
					__( 'Plugins & Themes', 'wpoptimizing' ),
					__( 'This section lists installed plugins and themes that are currently inactive. You can select them to uninstall directly and, if you want, check the option to also remove all associated disposable items.', 'wpoptimizing' ),
					array(
						'icon' => 'admin-plugins',
						'doc'  => 'wpopt-doc-inactive',
					)
				);
				if ( empty( $inactive_plugin_slugs_all ) && empty( $inactive_theme_slugs_all ) ) {
					$this->render_empty_section_notice(
						__( 'No inactive plugins or themes — everything installed is in use. Check Orphans for leftovers from extensions you have already removed.', 'wpoptimizing' ),
						__( 'Review orphans', 'wpoptimizing' ),
						admin_url( 'admin.php?page=wpoptimizing&tab=orphans' )
					);
					return;
				}
				$this->render_uninstall_form( $inactive_plugin_slugs_all, $inactive_theme_slugs_all, $tab, $isec );
			}
		} elseif ( 'active' === $tab ) {
			$active_bd               = $bd_split['active'];
			$active_fs               = $fs_active;
			$active_plugin_slugs_all = $this->get_all_active_plugin_slugs();
			$active_theme_slugs_all  = $this->get_all_active_theme_slugs();

			$asec = isset( $_GET['asec'] ) ? sanitize_key( wp_unslash( $_GET['asec'] ) ) : 'bd'; // phpcs:ignore
			if ( ! in_array( $asec, array( 'bd', 'fs', 'proj' ), true ) ) {
				$asec = 'bd';
			}

			echo '<ul class="subsubsub wpopt-subsubsub">';
			$this->active_menu_link( 'bd', __( 'Database', 'wpoptimizing' ), $asec, count( $active_bd ) );
			echo ' | ';
			$this->active_menu_link( 'fs', __( 'Files', 'wpoptimizing' ), $asec, count( $active_fs ) );
			echo ' | ';
			$this->active_menu_link( 'proj', __( 'Plugins & Themes', 'wpoptimizing' ), $asec, ( count( $active_plugin_slugs_all ) + count( $active_theme_slugs_all ) ) );
			echo '</ul>';
			echo '<br class="clear" />';
			echo '<input type="hidden" name="asec" value="' . esc_attr( $asec ) . '" />';

			if ( 'bd' === $asec ) {
				$this->render_section_header(
					__( 'Database', 'wpoptimizing' ),
					__( 'Records currently associated with active plugins or themes. You cannot delete these, but if one is attributed to the wrong extension you can select it and reassign it to the correct owner below.', 'wpoptimizing' ),
					array(
						'icon' => 'database',
						'doc'  => 'wpopt-doc-active',
					)
				);
				if ( 0 === $last_scan_ts ) {
					$this->render_scan_pending_notice();
					return;
				}
				if ( empty( $active_bd ) ) {
					$this->render_empty_section_notice( __( 'This informational view lists database records attributed to your active extensions; it fills in after an analysis runs.', 'wpoptimizing' ) );
					return;
				}
				$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$pp = isset( $_REQUEST['abd_pp'] ) ? (int) $_REQUEST['abd_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
					$pp = 50;
				}
				$type_filter = '';
				if ( isset( $_REQUEST['abd_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['abd_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
						$type_filter = '';
					}
				}
				$type_choices = $this->collect_types( 'abd', $active_bd );
				if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
					$type_filter = '';
				}
				$ext_filter = '';
				if ( isset( $_REQUEST['abd_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['abd_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
						$ext_filter = '';
					}
				}
				$project_choices = $this->collect_project_choices( $active_bd );
				if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
					$ext_filter = '';
				}
				$rows  = $this->apply_tipo_filter_bd( $active_bd, $type_filter, $q );
				$rows  = $this->apply_extension_filter( $rows, $ext_filter );
				$table = new WPOptimizing_DB_List_Table(
					$this,
					$rows,
					false,
					$this->should_show_projects_column( $rows ),
					array(
						'scope'           => 'abd',
						'per_page'        => $pp,
						'type_filter'     => $type_filter,
						'type_choices'    => $type_choices,
						'project_filter'  => $ext_filter,
						'project_choices' => $project_choices,
						// Selectable for manual attribution only (no delete action in this
						// informational view): lets the user correct a wrong owner.
						'selectable'      => true,
						'hidden_fields'   => array(
							'tab'  => 'active',
							'asec' => 'bd',
						),
					)
				);
				$table->prepare_items();
				// show_submit=true so the Active view gets "Attribute selected" + "Protect
				// selected" (render_table_form forces primary=false for 'active', so there is
				// no Delete button — active records are never deletable).
				$this->render_table_form( $table, 'active', $asec, null, false, true );
				if ( function_exists( 'wpoptimizing_manual_attributions' ) ) {
					$this->render_manual_attribution_panel( $table->bulk_cb_name(), 'bd', 'active' );
				}
			} elseif ( 'fs' === $asec ) {
				$this->render_section_header(
					__( 'Files', 'wpoptimizing' ),
					__( 'This section lists files and folders currently associated with active plugins or themes. It is informational and does not allow deletion.', 'wpoptimizing' ),
					array(
						'icon' => 'media-default',
						'doc'  => 'wpopt-doc-active',
					)
				);
				if ( 0 === $last_scan_ts ) {
					$this->render_scan_pending_notice();
					return;
				}
				if ( empty( $active_fs ) ) {
					$this->render_empty_section_notice( __( 'This informational view lists files attributed to your active extensions; it fills in after an analysis runs.', 'wpoptimizing' ) );
					return;
				}
				$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$pp = isset( $_REQUEST['afs_pp'] ) ? (int) $_REQUEST['afs_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
					$pp = 50;
				}
				$type_filter = '';
				if ( isset( $_REQUEST['afs_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['afs_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
						$type_filter = '';
					}
				}
				$type_choices = $this->collect_types( 'afs', $active_fs );
				if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
					$type_filter = '';
				}
				$ext_filter = '';
				if ( isset( $_REQUEST['afs_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['afs_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
						$ext_filter = '';
					}
				}
				$project_choices = $this->collect_project_choices( $active_fs );
				if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
					$ext_filter = '';
				}
				$rows  = $this->apply_tipo_filter_fs( $active_fs, $type_filter, $q );
				$rows  = $this->apply_extension_filter( $rows, $ext_filter );
				$table = new WPOptimizing_FS_List_Table(
					$this,
					$rows,
					false,
					$this->should_show_projects_column( $rows ),
					array(
						'scope'           => 'afs',
						'per_page'        => $pp,
						'type_filter'     => $type_filter,
						'type_choices'    => $type_choices,
						'project_filter'  => $ext_filter,
						'project_choices' => $project_choices,
						'selectable'      => false,
						'hidden_fields'   => array(
							'tab'  => 'active',
							'asec' => 'fs',
						),
					)
				);
				$table->prepare_items();
				$this->render_table_form( $table, 'active', $asec, null, false, false );
			} else {
				$this->render_section_header(
					__( 'Plugins & Themes', 'wpoptimizing' ),
					__( 'This section lists active plugins and themes. You can select items to deactivate.', 'wpoptimizing' ),
					array(
						'icon' => 'admin-plugins',
						'doc'  => 'wpopt-doc-active',
					)
				);
				if ( empty( $active_plugin_slugs_all ) && empty( $active_theme_slugs_all ) ) {
					$this->render_empty_section_notice();
					return;
				}
				$this->render_deactivate_form( $active_plugin_slugs_all, $active_theme_slugs_all, $tab, $asec );
			}
		} elseif ( 'autoload' === $tab ) {
			$this->render_autoload_tab();
		} elseif ( 'assets' === $tab ) {
			$this->render_assets_tab();
		} elseif ( 'cron' === $tab ) {
			$this->render_cron_tab();
		} elseif ( 'tables' === $tab ) {
			$this->render_tables_tab();
		} elseif ( 'media' === $tab ) {
			$this->render_media_tab();
		} elseif ( 'cache' === $tab ) {
			$this->render_cache_tab();
		} elseif ( 'performance' === $tab ) {
			$this->render_performance_tab();
		} elseif ( 'frontend' === $tab ) {
			$this->render_frontend_tab();
		} elseif ( 'activity' === $tab ) {
			$this->render_activity_tab();
		} elseif ( 'backups' === $tab ) {
			$this->render_backups_tab();
		} elseif ( 'history' === $tab ) {
			$this->render_history_tab();
		} elseif ( 'docs' === $tab ) {
			$this->render_docs_tab();
		} else { // settings
			$this->render_settings_tab();
		}

		if ( $this->show_tabs ) {
			echo '</div></div>'; // .wpopt-content + .wpopt-layout

		}
	}

	/*
	======================================================
	 * Render helpers: tabs, controls, tables (paginated)
	 * ====================================================== */

	/**
	 * The state filter of "Analysis results": one row of chips above the Database / Files /
	 * Plugins & Themes filter each state already has.
	 *
	 * These were five menu entries (Pending, Orphans, Inactive, Empty, Active) for what is one
	 * data set split by the owner's state, so the user had to know the vocabulary before
	 * finding anything (UX audit, 15/10/2026). They are filters now, each with its count, a
	 * colour that says how safe acting on it is, and one line saying what it means. Each state
	 * keeps its own table and its own actions: a state that is safe to delete is never mixed in
	 * one bulk action with one that is not.
	 *
	 * @param string             $curr   Current state (tab key).
	 * @param array<string,int>  $counts State => records.
	 * @return void
	 */
	protected function render_review_states( $curr, array $counts ) {
		$this->render_review_state_chips( $curr, $counts, true );
	}

	/**
	 * Home: where the site stands and what to do next. Read-only — nothing on this screen
	 * changes the site; every action is a link to the screen that does it, where it is
	 * confirmed. It gathers what used to sit above the General cleanup tab (health score,
	 * Needs attention) and the result counts, which were spread over five menu entries.
	 *
	 * @param int               $last_scan_ts Last analysis timestamp (0 = never).
	 * @param int               $db_orphans   Database orphans.
	 * @param int               $fs_orphans   File orphans.
	 * @param array<string,int> $counts       Results per state.
	 * @return void
	 */
	protected function render_home_tab( $last_scan_ts, $db_orphans, $fs_orphans, array $counts ) {
		$this->render_section_header(
			__( 'Home', 'wpoptimizing' ),
			__( 'Where your site stands and what to do next. Nothing on this page changes your site: each suggestion opens the screen that does it, where you confirm it.', 'wpoptimizing' ),
			array( 'icon' => 'wpoptimizing' )
		);
		if ( 0 === (int) $last_scan_ts ) {
			$this->render_scan_pending_notice();
			return;
		}
		$this->render_health_score();
		$this->render_attention_area( (int) $db_orphans, (int) $fs_orphans );

		$this->render_subsection_header(
			__( 'What the analysis found', 'wpoptimizing' ),
			__( 'Records and files the last analysis attributed, by state. Open one to review it.', 'wpoptimizing' ),
			'search'
		);
		$this->render_review_state_chips( '', $counts, false );
	}

	/**
	 * The state chips themselves (shared by Analysis results and Home).
	 *
	 * @param string            $curr      Current state, or '' for none.
	 * @param array<string,int> $counts    State => records.
	 * @param bool              $with_help Print the current state's explanation under the chips.
	 * @return void
	 */
	protected function render_review_state_chips( $curr, array $counts, $with_help ) {
		$states = array(
			'orphans'  => array( __( 'Orphans', 'wpoptimizing' ), 'safe', __( 'Left behind by extensions that are no longer installed. Attributed with evidence: you can delete them (a backup is made first).', 'wpoptimizing' ) ),
			'pending'  => array( __( 'To identify', 'wpoptimizing' ), 'risk', __( 'No owner could be identified. They may belong to something still in use: review them one by one before deleting anything.', 'wpoptimizing' ) ),
			'inactive' => array( __( 'Inactive', 'wpoptimizing' ), 'warn', __( 'They belong to plugins or themes that are installed but deactivated. Delete them only if you will not use those extensions again.', 'wpoptimizing' ) ),
			'empty'    => array( __( 'Empty', 'wpoptimizing' ), 'warn', __( 'Records with no value stored. The Status column says whose they are; those of active extensions or WordPress are read-only.', 'wpoptimizing' ) ),
			'active'   => array( __( 'In use', 'wpoptimizing' ), 'info', __( 'They belong to active extensions or WordPress. Read-only: fix a wrong owner or protect a record, but nothing is deleted here.', 'wpoptimizing' ) ),
		);
		echo '<nav class="wpopt-review-states" aria-label="' . esc_attr__( 'Filter the results by state', 'wpoptimizing' ) . '">';
		foreach ( $states as $key => $st ) {
			printf(
				'<a href="%s" class="wpopt-review-state is-%s%s"%s><span class="wpopt-review-state-label">%s</span> <span class="wpopt-count">%s</span></a>',
				esc_url(
					add_query_arg(
						array(
							'page' => 'wpoptimizing',
							'tab'  => $key,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_attr( $st[1] ),
				$curr === $key ? ' current' : '',
				$curr === $key ? ' aria-current="page"' : '',
				esc_html( $st[0] ),
				esc_html( number_format_i18n( (int) ( $counts[ $key ] ?? 0 ) ) )
			);
		}
		echo '</nav>';
		if ( $with_help && isset( $states[ $curr ] ) ) {
			echo '<p class="wpopt-review-state-help is-' . esc_attr( $states[ $curr ][1] ) . '">' . esc_html( $states[ $curr ][2] ) . '</p>';
		}
	}

	/**
	 * Las pestañas de la pantalla, en orden y EN UN SOLO SITIO.
	 *
	 * Estaban escritas dos veces: la barra lateral las pintaba una a una con tab_link() y la
	 * lista de las que se aceptan en `?tab=` era un array literal cien líneas más arriba. Y
	 * divergieron: «Activity» se pintaba en el menú, el dispatcher tenía su rama y el mapa de
	 * iconos su entrada — sólo faltaba en la lista de válidas, así que al pulsarla caías en
	 * «General cleanup» sin un solo aviso, y el registro de actividad era inalcanzable.
	 *
	 * Es el vicio de siempre en este código: la afirmación («estas son las pestañas») vive donde
	 * alguien la recordó y no donde se usa. Ahora la lista es una, y el menú y la validación
	 * salen las dos de aquí.
	 *
	 * @param string $pending_label Etiqueta de «Pendiente», que lleva el contador. Vacía cuando
	 *                              sólo se piden las claves.
	 * @return array<string,string> clave => etiqueta, en el orden del menú.
	 */
	protected function admin_tab_labels( $pending_label = '' ) {
		$out = array();
		foreach ( $this->admin_nav_groups( $pending_label ) as $group ) {
			$out += $group['tabs'];
		}
		return $out;
	}

	/**
	 * The menu in groups, by what the user wants to do rather than by mechanism.
	 *
	 * Seventeen tabs in one flat column, folded to icons by default, mixed tasks (cleanup),
	 * objects (tables, cron), states (orphans, inactive) and records (history) at one level, and
	 * the speed features were spread over Cache, Performance › Browser and Settings. Two
	 * independent UX reviews (15/10/2026) proposed the same grouping: diagnose, speed up, clean,
	 * review what the analysis found, history and recovery, help. The tab keys do not change, so
	 * every existing link keeps working; the review tabs stay separate until they are unified.
	 *
	 * @param string $pending_label Label for Pending, which carries its counter.
	 * @return array<int,array{label:string,tabs:array<string,string>}>
	 */
	protected function admin_nav_groups( $pending_label = '' ) {
		return array(
			array(
				'label' => '',
				'tabs'  => array(
					'home' => __( 'Home', 'wpoptimizing' ),
				),
			),
			array(
				'label' => __( 'Diagnose', 'wpoptimizing' ),
				'tabs'  => array(
					'performance' => __( 'Performance', 'wpoptimizing' ),
					'history'     => __( 'History', 'wpoptimizing' ),
				),
			),
			array(
				'label' => __( 'Speed', 'wpoptimizing' ),
				'tabs'  => array(
					'cache'    => __( 'Cache', 'wpoptimizing' ),
					'frontend' => __( 'Page & browser', 'wpoptimizing' ),
					'assets'   => __( 'Smart Assets', 'wpoptimizing' ),
					'autoload' => __( 'Autoload', 'wpoptimizing' ),
				),
			),
			array(
				'label' => __( 'Clean up', 'wpoptimizing' ),
				'tabs'  => array(
					'general' => __( 'General cleanup', 'wpoptimizing' ),
					'tables'  => __( 'Tables', 'wpoptimizing' ),
					'cron'    => __( 'Cron jobs', 'wpoptimizing' ),
					'media'   => __( 'Media', 'wpoptimizing' ),
				),
			),
			array(
				'label'  => __( 'Review the analysis', 'wpoptimizing' ),
				// One menu entry for the five result states: they are one data set split by
				// status, and the status is now a filter inside the screen (render_review_states()).
				'single' => array( 'review', __( 'Analysis results', 'wpoptimizing' ) ),
				'tabs'   => array(
					'pending'  => '' !== $pending_label ? $pending_label : __( 'To identify', 'wpoptimizing' ),
					'orphans'  => __( 'Orphans', 'wpoptimizing' ),
					'inactive' => __( 'Inactive', 'wpoptimizing' ),
					'empty'    => __( 'Empty', 'wpoptimizing' ),
					'active'   => __( 'In use', 'wpoptimizing' ),
				),
			),
			array(
				'label' => __( 'History & recovery', 'wpoptimizing' ),
				'tabs'  => array(
					'activity' => __( 'Activity', 'wpoptimizing' ),
					'backups'  => __( 'Backups', 'wpoptimizing' ),
				),
			),
			array(
				'label' => __( 'Help', 'wpoptimizing' ),
				'tabs'  => array(
					'docs' => __( 'Documentation', 'wpoptimizing' ),
				),
			),
		);
	}


	/**
	 * An icon of the screens: an interface icon by its Dashicon name (wpoptimizing_icon(), the
	 * library SVG), or the brand isotype for 'wpoptimizing', the same one the admin menu shows
	 * (wpoptimizing_brand_menu_icon), so Home is the plugin itself and not one more gauge next to
	 * Performance. The isotype is a CSS mask painted with the text colour, so it follows the
	 * grey, active and mobile states of the icons around it.
	 *
	 * @param string $icon  Dashicon name without the prefix, or 'wpoptimizing'.
	 * @param string $class Extra class.
	 * @return string Escaped HTML.
	 */
	protected function icon_html( $icon, $class = '' ) {
		if ( 'wpoptimizing' !== $icon ) {
			return wpoptimizing_icon( $icon, $class );
		}
		$brand = function_exists( 'wpoptimizing_brand_menu_icon_default' ) ? wpoptimizing_brand_menu_icon_default() : '';
		$brand = (string) apply_filters( 'wpoptimizing_brand_menu_icon', $brand );
		// A menu icon given by name — 'dashicons-…', or 'core-admin/…' on WordPress 7.2+ — is
		// drawn with the same interface icon; anything that is not an image falls back to Home.
		if ( 0 === strpos( $brand, 'dashicons-' ) ) {
			return wpoptimizing_icon( substr( $brand, 10 ), $class );
		}
		if ( 0 === strpos( $brand, 'core-admin/' ) ) {
			$dashicon = array_search( substr( $brand, 11 ), wpoptimizing_icon_map(), true );
			return wpoptimizing_icon( false !== $dashicon ? (string) $dashicon : 'admin-home', $class );
		}
		if ( 0 !== strpos( $brand, 'data:image/' ) && 0 !== strpos( $brand, 'https://' ) ) {
			return wpoptimizing_icon( 'admin-home', $class );
		}
		$class = '' !== $class ? ' ' . $class : '';
		$mask  = 'url("' . esc_url_raw( $brand, array( 'data', 'https' ) ) . '")';
		return '<span class="dashicons wpopt-icon-isotype' . esc_attr( $class ) . '" style="' . esc_attr( '--wpopt-isotype:' . $mask ) . '" aria-hidden="true"></span>';
	}

	/**
	 * Print a nav tab link.
	 *
	 * @param string $key   Tab key.
	 * @param string $label Label.
	 * @param string $curr  Current.
	 * @return void
	 */
	protected function tab_link( $key, $label, $curr ) {
		$icons = array(
			'general'     => 'admin-tools',
			'history'     => 'chart-area',
			'pending'     => 'flag',
			'orphans'     => 'editor-unlink',
			'inactive'    => 'hidden',
			'active'      => 'yes-alt',
			'autoload'    => 'database-view',
			'performance' => 'performance',
			'empty'       => 'marker',
			'frontend'    => 'editor-code',
			'home'        => 'wpoptimizing',
			'review'      => 'search',
			'assets'      => 'media-code',
			'cron'        => 'clock',
			'tables'      => 'editor-table',
			'media'       => 'admin-media',
			'cache'       => 'cloud',
			'backups'     => 'backup',
			'activity'    => 'list-view',
			'docs'        => 'editor-help',
		);
		$icon  = isset( $icons[ $key ] ) ? $icons[ $key ] : 'admin-generic';
		$url   = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => $key,
			),
			admin_url( 'admin.php' )
		);
		// title carries the label so it works as a tooltip in the icons-only state.
		printf(
			'<a href="%s" class="nav-tab %s" title="%s"%s>%s<span class="wpopt-tab-label">%s</span></a>',
			esc_url( $url ),
			esc_attr( $curr === $key ? 'nav-tab-active' : '' ),
			esc_attr( $label ),
			$curr === $key ? ' aria-current="page"' : '',
			$this->icon_html( $icon ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon_html() escapes.
			esc_html( $label )
		);
	}

	/**
	 * Internal menu link (subsubsub) for the "Inactive" tab.
	 *
	 * @param string $key   'bd' | 'fs' | 'proj'.
	 * @param string $label Label to display.
	 * @param string $curr  Current active key.
	 * @param int    $count Optional count shown in parentheses.
	 * @return void
	 */
	protected function inactive_menu_link( $key, $label, $curr, $count = 0 ) {
		$base = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'inactive',
				'isec' => $key,
			),
			admin_url( 'admin.php' )
		);

		$is_current = ( $curr === $key );
		printf(
			'<li><a href="%s" class="%s">%s%s</a></li>',
			esc_url( $base ),
			$is_current ? 'current' : '',
			esc_html( $label ),
			$count ? ' <span class="count">(' . esc_html( (string) $count ) . ')</span>' : ''
		);
	}

	/**
	 * Internal menu link (subsubsub) for the "Active" tab.
	 *
	 * @param string $key   'bd' | 'fs' | 'proj'.
	 * @param string $label Label to display.
	 * @param string $curr  Current active key.
	 * @param int    $count Optional count shown in parentheses.
	 * @return void
	 */
	protected function active_menu_link( $key, $label, $curr, $count = 0 ) {
		$base = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'active',
				'asec' => $key,
			),
			admin_url( 'admin.php' )
		);

		$is_current = ( $curr === $key );
		printf(
			'<li><a href="%s" class="%s">%s%s</a></li>',
			esc_url( $base ),
			$is_current ? 'current' : '',
			esc_html( $label ),
			$count ? ' <span class="count">(' . esc_html( (string) $count ) . ')</span>' : ''
		);
	}

	/**
	 * Render "orphans" submenu link.
	 *
	 * @param string $key   Subsection key.
	 * @param string $label Label to display.
	 * @param string $curr  Current active key.
	 * @param int    $count Optional count shown in parentheses.
	 * @return void
	 */
	protected function orphans_menu_link( $key, $label, $curr, $count = 0 ) {
		$base = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'orphans',
				'osec' => $key,
			),
			admin_url( 'admin.php' )
		);

		$is_current = ( $curr === $key );
		printf(
			'<li><a href="%s" class="%s">%s%s</a></li>',
			esc_url( $base ),
			$is_current ? 'current' : '',
			esc_html( $label ),
			$count ? ' <span class="count">(' . esc_html( (string) $count ) . ')</span>' : ''
		);
	}

	/**
	 * Render "scan pending" notice with spinner and auto-refresh.
	 *
	 * @return void
	 */
	protected function render_scan_pending_notice() {
		// A scan only counts as "running" when the staged-scan cursor or lock is
		// held, the user just kicked one off, or one is queued in cron. Source of
		// truth mirrors wpoptimizing_ajax_scan_status(). IMPORTANT: rendering this
		// notice must NEVER schedule a scan — that auto-started "phantom" analyses
		// on every visit to an empty section.
		$scan_running = (bool) get_transient( 'wpoptimizing_scan_lock' )
			|| is_array( get_option( 'wpoptimizing_scan_state', null ) )
			|| (bool) get_transient( 'wpoptimizing_scan_pending_ui' )
			|| wpoptimizing_scan_event_pending();

		if ( ! $scan_running ) {
			// Nothing running: prompt the user to start a scan explicitly instead of
			// triggering one as a side effect of viewing the page.
			$scan_btn_url = wp_nonce_url(
				add_query_arg( 'wpopt_run_async', '1', admin_url( 'admin.php?page=wpoptimizing' ) ),
				'wpoptimizing_run_async',
				'_wpopt_nonce'
			);
			echo '<div class="notice notice-info wpopt-notice-padded">';
			echo '<p class="wpopt-notice">' . esc_html__( 'No analysis has been completed yet.', 'wpoptimizing' ) . '</p>';
			printf(
				'<a href="%s" class="button button-primary">%s</a>',
				esc_url( $scan_btn_url ),
				esc_html__( 'Analyze now', 'wpoptimizing' )
			);
			echo '</div>';
			return;
		}

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'wpoptimizing_scan_status' );

		echo '<div class="notice notice-info wpopt-notice-padded">';
		echo '<p class="wpopt-desc-flush"><span class="spinner is-active wpopt-spinner-inline"></span>' . esc_html__( 'Please wait while we analyse your ecosystem.', 'wpoptimizing' ) . '</p>';
		echo '</div>';

		wp_print_inline_script_tag(
			sprintf(
				'(function(){
				var url = %1$s;
				var nonce = %2$s;
				function check(){
					var data = new URLSearchParams();
					data.set("action","wpoptimizing_scan_status");
					data.set("nonce", nonce);
					fetch(url, {method:"POST", credentials:"same-origin", headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"}, body:data.toString()})
						.then(function(r){return r.json();})
						.then(function(r){ if(r && r.success && r.data && r.data.done){ window.location.reload(); } })
						.catch(function(){});
				}
				setInterval(check, 5000);
			})();',
				wp_json_encode( $ajax_url ),
				wp_json_encode( $nonce )
			)
		);
	}

	/**
	 * Render "empty section" notice after a completed scan. Optionally guides the user to the
	 * next step (a plain-language hint and/or a call-to-action button) so an empty section
	 * points somewhere instead of dead-ending.
	 *
	 * @param string $hint      Optional plain-language "what next".
	 * @param string $cta_label Optional CTA button label.
	 * @param string $cta_url   Optional CTA button URL.
	 * @return void
	 */
	protected function render_empty_section_notice( $hint = '', $cta_label = '', $cta_url = '' ) {
		// 'inline' keeps the notice where it is rendered (below the subsection
		// nav) instead of WordPress hoisting it to the top of the page.
		echo '<div class="notice notice-success inline wpopt-notice wpopt-notice-padded">';
		echo '<p>' . esc_html__( 'Great news! There is nothing to display in this section.', 'wpoptimizing' ) . '</p>';
		if ( '' !== (string) $hint ) {
			echo '<p class="description wpopt-desc-tight">' . esc_html( (string) $hint ) . '</p>';
		}
		if ( '' !== (string) $cta_label && '' !== (string) $cta_url ) {
			echo '<p><a class="button" href="' . esc_url( (string) $cta_url ) . '">' . esc_html( (string) $cta_label ) . '</a></p>';
		}
		echo '</div>';
	}

	/**
	 * Build the prioritised "Needs attention" signals from data already gathered by
	 * the last analysis (no re-scan). Each item: icon, color, title, desc, url, cta.
	 *
	 * @return array<int,array<string,string>>
	 */
	/*
	 * ICONS ARE DASHICON NAMES, NOT EMOJI. WordPress rewrites an emoji in admin output into an
	 * <img> pointing at s.w.org, so any site that cannot reach that host — offline, firewalled,
	 * a CDN outage, a privacy plugin blocking it — shows a broken image where the icon should
	 * be. Which is what a customer reported: a torn-page placeholder on the "Orphaned records
	 * found" card. Dashicons are a font WordPress already loads in wp-admin, they take our
	 * colour tokens, and they need no network at all.
	 */
	protected function collect_attention_items( $db_orphans = 0, $fs_orphans = 0 ) {
		$base  = admin_url( 'admin.php?page=wpoptimizing' );
		$items = array();

		// 0) Orphaned records attributed to uninstalled plugins — the flagship signal,
		// shown first (counts come from render(), already computed). DB and file orphans
		// are counted separately and spelled out, so the total never looks "stale" when the
		// database card shows only the DB share (the rest are leftover files).
		$db_orphans   = (int) $db_orphans;
		$fs_orphans   = (int) $fs_orphans;
		$orphan_total = $db_orphans + $fs_orphans;
		if ( $orphan_total > 0 ) {
			if ( $db_orphans > 0 && $fs_orphans > 0 ) {
				$desc = sprintf(
					/* translators: 1: number of database records, 2: number of files. */
					__( '%1$d in the database and %2$d files, left by uninstalled plugins, can be reviewed and cleaned.', 'wpoptimizing' ),
					$db_orphans,
					$fs_orphans
				);
			} elseif ( $fs_orphans > 0 ) {
				$desc = sprintf(
					/* translators: %d: number of files. */
					_n( '%d file left by an uninstalled plugin can be reviewed and cleaned.', '%d files left by uninstalled plugins can be reviewed and cleaned.', $fs_orphans, 'wpoptimizing' ),
					$fs_orphans
				);
			} else {
				$desc = sprintf(
					/* translators: %d: number of orphaned records. */
					_n( '%d record left by an uninstalled plugin can be reviewed and cleaned.', '%d records left by uninstalled plugins can be reviewed and cleaned.', $db_orphans, 'wpoptimizing' ),
					$db_orphans
				);
			}
			$items[] = array(
				'icon'  => 'editor-unlink',
				'sev'   => 'warn',
				'title' => __( 'Orphaned records found', 'wpoptimizing' ),
				'desc'  => $desc,
				'url'   => add_query_arg( 'tab', 'orphans', $base ),
				'cta'   => __( 'Review orphans', 'wpoptimizing' ),
			);
		}

		// 1) Analysis out of date (only once an analysis exists — a brand-new install
		// is handled by the wizard, not here).
		$last = (int) get_option( 'wpoptimizing_last_scan', 0 );
		if ( $last > 0 && ( time() - $last ) > 14 * DAY_IN_SECONDS ) {
			$items[] = array(
				'icon'  => 'update',
				'sev'   => 'warn',
				'title' => __( 'Analysis is out of date', 'wpoptimizing' ),
				/* translators: %s: human-readable time difference, e.g. "3 weeks". */
				'desc'  => sprintf( __( 'Last analysed %s ago — re-run it to refresh the findings.', 'wpoptimizing' ), human_time_diff( $last, time() ) ),
				'url'   => wp_nonce_url( add_query_arg( 'wpopt_run_async', '1', $base ), 'wpoptimizing_run_async', '_wpopt_nonce' ),
				'cta'   => __( 'Analyze now', 'wpoptimizing' ),
			);
		}

		// 2) Heavy autoloaded options (> ~900 KB load on every request).
		$autoload_size = (int) get_option( 'wpoptimizing_autoload_size', 0 );
		if ( $autoload_size > 900 * 1024 ) {
			$items[] = array(
				'icon'  => 'database-view',
				'sev'   => 'warn',
				'title' => __( 'Autoloaded options are heavy', 'wpoptimizing' ),
				/* translators: %s: human-readable size, e.g. "1.2 MB". */
				'desc'  => sprintf( __( '%s of options load on every page request. Review what can be disabled.', 'wpoptimizing' ), size_format( $autoload_size, 1 ) ),
				'url'   => add_query_arg( 'tab', 'autoload', $base ),
				'cta'   => __( 'Review autoload', 'wpoptimizing' ),
			);
		}

		// 3) Reclaimable media space (duplicates + potentially-unused attachments).
		$report = function_exists( 'wpoptimizing_media_report_get' ) ? wpoptimizing_media_report_get() : get_transient( 'wpoptimizing_media_report' );
		if ( is_array( $report ) ) {
			// A partial reference sweep counts every attachment it never got to as unused (on a
			// 422,571-attachment site: 42 GB, half the library), so that figure is not space
			// anybody can reclaim and stays out of the sum. The Media tab still lists them, with
			// its warning and without "delete all".
			$unused_ok = ! ( function_exists( 'wpoptimizing_media_unused_scan_is_partial' ) ? wpoptimizing_media_unused_scan_is_partial() : ! empty( $report['unused']['partial'] ) );
			$reclaim   = (int) ( $report['duplicates']['reclaimable_bytes'] ?? 0 ) + ( $unused_ok ? (int) ( $report['unused']['size_bytes'] ?? 0 ) : 0 );
			if ( $reclaim > 5 * 1024 * 1024 ) {
				$items[] = array(
					'icon'  => 'admin-media',
					'sev'   => 'info',
					'title' => __( 'Reclaimable media space', 'wpoptimizing' ),
					/* translators: %s: human-readable size, e.g. "12 MB". */
					'desc'  => sprintf( __( 'About %s in duplicate or potentially-unused attachments.', 'wpoptimizing' ), size_format( $reclaim, 1 ) ),
					'url'   => add_query_arg( 'tab', 'media', $base ),
					'cta'   => __( 'Review media', 'wpoptimizing' ),
				);
			}
		}

		// 4) Orphaned cron events (hook with no callback — uninstalled plugins).
		if ( method_exists( $this, 'get_cron_events' ) ) {
			$cron_orphans = 0;
			foreach ( $this->get_cron_events() as $e ) {
				$cron_orphans += ! empty( $e['orphan'] ) ? 1 : 0;
			}
			if ( $cron_orphans > 0 ) {
				$items[] = array(
					'icon'  => 'clock',
					'sev'   => 'warn',
					'title' => __( 'Orphaned scheduled tasks', 'wpoptimizing' ),
					'desc'  => sprintf(
						/* translators: %d: number of orphaned cron events. */
						_n( '%d scheduled event left by an uninstalled plugin.', '%d scheduled events left by uninstalled plugins.', $cron_orphans, 'wpoptimizing' ),
						$cron_orphans
					),
					'url'   => add_query_arg( 'tab', 'cron', $base ),
					'cta'   => __( 'Review cron jobs', 'wpoptimizing' ),
				);
			}
		}

		// 5) A cookie banner and Consent Mode with nothing between them: visitors accept and
		// Google still gets every hit as «denied» (includes/consent-check.php).
		// Only Home needs it, so it is loaded here and not on every request.
		require_once dirname( __DIR__ ) . '/consent-check.php';
		$gap = wpoptimizing_consent_gap_here();
		if ( $gap ) {
			$items[] = array(
				'icon'  => 'chart-bar',
				'sev'   => 'warn',
				'title' => __( 'Analytics never receives cookie consent', 'wpoptimizing' ),
				'desc'  => sprintf(
					/* translators: 1: consent banner plugin, e.g. "Complianz"; 2: analytics plugin, e.g. "Google Analytics for WooCommerce". */
					__( '%2$s only learns that visitors accepted cookies in %1$s through the WP Consent API plugin, which is not installed: Google receives every visit as without consent and they do not appear in Realtime.', 'wpoptimizing' ),
					$gap['banner'],
					$gap['analytics']
				),
				'url'   => add_query_arg(
					array(
						's'    => 'wp-consent-api',
						'tab'  => 'search',
						'type' => 'term',
					),
					is_multisite() ? network_admin_url( 'plugin-install.php' ) : admin_url( 'plugin-install.php' )
				),
				'cta'   => __( 'Install WP Consent API', 'wpoptimizing' ),
			);
		}

		return $items;
	}

	/**
	 * Render the "Needs attention" area: a prioritised set of action cards shown at
	 * the top of the dashboard. Nothing is rendered when there is nothing to flag.
	 *
	 * @return void
	 */
	/**
	 * Render the single 0-100 optimization "health score" card with the few actions
	 * that would raise it the most. Read-only; uses the latest performance snapshot
	 * (no extra queries). Silent until the first analysis produces a snapshot.
	 *
	 * @return void
	 */
	protected function render_health_score() {
		if ( ! class_exists( 'WPOPT_Performance_Logger' ) || ! function_exists( 'wpoptimizing_health_score' ) ) {
			return;
		}
		$history = ( new WPOPT_Performance_Logger() )->read_history();
		$latest  = is_array( $history ) ? wpoptimizing_analytics_latest( $history ) : null;
		if ( empty( $latest ) || ! is_array( $latest ) || empty( $latest['metrics'] ) || ! is_array( $latest['metrics'] ) ) {
			return; // No snapshot yet; the scan-pending notice already guides the user.
		}

		$metrics = $latest['metrics'];
		// Fold in the measured server TTFB so it actually moves the score (it is stored
		// separately from the scan snapshot, in wpoptimizing_ttfb_stats).
		$metrics['server_ttfb_ms'] = function_exists( 'wpoptimizing_server_ttfb_ms' ) ? wpoptimizing_server_ttfb_ms() : 0;
		// Counted now, not at the last analysis: a cleanup has to move the score at once.
		$metrics['db_transients_expired'] = WPOPT_Performance_Logger::count_expired_transients();

		$health = wpoptimizing_health_score( $metrics );
		$score  = (int) $health['score'];
		$grade  = (string) $health['grade'];

		$grade_labels = array(
			'excellent' => __( 'Excellent', 'wpoptimizing' ),
			'good'      => __( 'Good', 'wpoptimizing' ),
			'fair'      => __( 'Fair', 'wpoptimizing' ),
			'poor'      => __( 'Needs work', 'wpoptimizing' ),
		);

		// Each scored area maps to a plain-language action and the tab that fixes it.
		$base    = admin_url( 'admin.php?page=wpoptimizing' );
		$actions = array(
			'autoload'   => array( __( 'Reduce autoload weight', 'wpoptimizing' ), add_query_arg( 'tab', 'autoload', $base ) ),
			'orphans'    => array( __( 'Remove orphaned items', 'wpoptimizing' ), add_query_arg( 'tab', 'orphans', $base ) ),
			'media'      => array( __( 'Reclaim media space', 'wpoptimizing' ), add_query_arg( 'tab', 'media', $base ) ),
			'transients' => array( __( 'Delete expired transients', 'wpoptimizing' ), add_query_arg( 'tab', 'general', $base ) ),
			'options'    => array( __( 'Trim the options table', 'wpoptimizing' ), add_query_arg( 'tab', 'autoload', $base ) ),
		);

		// The TTFB action is context-aware: it depends on what is already optimal (e.g.
		// "the server itself is slow" when cache/autoload/DB are all fine). An empty url
		// renders as plain text (no tab fixes a host-level bottleneck).
		if ( function_exists( 'wpoptimizing_ttfb_action_plan' ) ) {
			$ttfb_plan = wpoptimizing_ttfb_action_plan( (int) $metrics['server_ttfb_ms'], $this->wpopt_ttfb_signals() );
			if ( ! empty( $ttfb_plan ) ) {
				$ttfb_first      = $ttfb_plan[0];
				$actions['ttfb'] = array(
					(string) $ttfb_first['text'],
					! empty( $ttfb_first['tab'] ) ? add_query_arg( 'tab', $ttfb_first['tab'], $base ) : '',
				);
			}
		}

		echo '<div class="wpopt-health wpopt-health--' . esc_attr( $grade ) . '">';
		printf(
			'<div class="wpopt-health-score" role="img" aria-label="%s"><span class="wpopt-health-frac"><span class="wpopt-health-num">%s</span><span class="wpopt-health-max">/100</span></span></div>',
			/* translators: %d: 0-100 health score. */
			esc_attr( sprintf( __( 'Optimization health score: %d out of 100', 'wpoptimizing' ), $score ) ),
			esc_html( number_format_i18n( $score ) )
		);
		echo '<div class="wpopt-health-body">';
		echo '<strong class="wpopt-health-grade">' . esc_html__( 'Optimization health', 'wpoptimizing' ) . ' — ' . esc_html( isset( $grade_labels[ $grade ] ) ? $grade_labels[ $grade ] : '' ) . '</strong>';
		$wpopt_ttfb = function_exists( 'wpoptimizing_server_ttfb_ms' ) ? wpoptimizing_server_ttfb_ms() : 0;
		if ( $wpopt_ttfb > 0 ) {
			echo '<p class="wpopt-health-hint">' . esc_html__( 'Server TTFB (avg)', 'wpoptimizing' ) . ': <strong>' . (int) $wpopt_ttfb . ' ms</strong></p>';
		}
		if ( empty( $health['improve'] ) ) {
			echo '<p class="wpopt-health-hint">' . esc_html__( 'Your site is in great shape. Keep it that way with a periodic analysis.', 'wpoptimizing' ) . '</p>';
		} else {
			echo '<p class="wpopt-health-hint">' . esc_html__( 'Top ways to raise your score:', 'wpoptimizing' ) . '</p>';
			echo '<ul class="wpopt-health-actions">';
			foreach ( array_slice( $health['improve'], 0, 3 ) as $f ) {
				$k = (string) $f['key'];
				if ( ! isset( $actions[ $k ] ) ) {
					continue;
				}
				$txt = (string) $actions[ $k ][0];
				$url = (string) $actions[ $k ][1];
				if ( '' !== $url ) {
					printf( '<li><a href="%s">%s</a></li>', esc_url( $url ), esc_html( $txt ) );
				} else {
					printf( '<li>%s</li>', esc_html( $txt ) );
				}
			}
			echo '</ul>';
		}
		echo '</div></div>';
	}

	/**
	 * Gather the live signals the TTFB action plan reasons over: whether our object
	 * cache is active, its aggregate hit rate (-1 when unknown), the autoload weight,
	 * and whether WP-Cron still fires on visitor requests.
	 *
	 * @return array{cache_active:bool,hit_rate:float,autoload_bytes:int,wp_cron_on_request:bool}
	 */
	protected function wpopt_ttfb_signals() {
		$ratio = -1.0;
		if ( method_exists( $this, 'get_cache_analytics' ) ) {
			$an = $this->get_cache_analytics();
			if ( ! empty( $an['has'] ) ) {
				$ratio = (float) $an['ratio'];
			}
		}
		return array(
			'cache_active'       => function_exists( 'wpoptimizing_object_cache_active' ) && wpoptimizing_object_cache_active(),
			'hit_rate'           => $ratio,
			'autoload_bytes'     => (int) get_option( 'wpoptimizing_autoload_size', 0 ),
			'wp_cron_on_request' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
		);
	}

	/**
	 * Compact health badge for the sidebar: score/100 colored by grade, shown on every
	 * tab. Links to the General dashboard for the full card. Renders nothing until a
	 * first analysis exists. When the sidebar is collapsed, only the number shows.
	 *
	 * @return void
	 */
	protected function render_sidebar_health() {
		if ( ! class_exists( 'WPOPT_Performance_Logger' ) || ! function_exists( 'wpoptimizing_health_score' ) ) {
			return;
		}
		$history = ( new WPOPT_Performance_Logger() )->read_history();
		$latest  = is_array( $history ) ? wpoptimizing_analytics_latest( $history ) : null;
		if ( empty( $latest ) || ! is_array( $latest ) || empty( $latest['metrics'] ) || ! is_array( $latest['metrics'] ) ) {
			return;
		}
		$metrics                          = $latest['metrics'];
		$metrics['server_ttfb_ms']        = function_exists( 'wpoptimizing_server_ttfb_ms' ) ? wpoptimizing_server_ttfb_ms() : 0;
		$metrics['db_transients_expired'] = WPOPT_Performance_Logger::count_expired_transients();
		$health                           = wpoptimizing_health_score( $metrics );
		$score                            = (int) $health['score'];
		$grade                            = (string) $health['grade'];
		$grade_labels                     = array(
			'excellent' => __( 'Excellent', 'wpoptimizing' ),
			'good'      => __( 'Good', 'wpoptimizing' ),
			'fair'      => __( 'Fair', 'wpoptimizing' ),
			'poor'      => __( 'Needs work', 'wpoptimizing' ),
		);
		printf(
			'<a class="wpopt-side-health wpopt-side-health--%1$s" href="%2$s" title="%3$s"><span class="wpopt-side-health-score">%4$s</span><span class="wpopt-side-health-cap">/100</span><span class="wpopt-tab-label wpopt-side-health-grade">%5$s</span></a>',
			esc_attr( $grade ),
			esc_url( admin_url( 'admin.php?page=wpoptimizing&tab=home' ) ),
			esc_attr__( 'Optimization health — open the dashboard for details', 'wpoptimizing' ),
			esc_html( number_format_i18n( $score ) ),
			esc_html( isset( $grade_labels[ $grade ] ) ? $grade_labels[ $grade ] : '' )
		);
	}

	protected function render_attention_area( $db_orphans = 0, $fs_orphans = 0 ) {
		$items = $this->collect_attention_items( (int) $db_orphans, (int) $fs_orphans );
		if ( empty( $items ) ) {
			return;
		}
		echo '<div class="wpopt-attention">';
		$this->render_section_header(
			__( 'Needs attention', 'wpoptimizing' ),
			'',
			array(
				'icon' => 'warning',
				'doc'  => 'wpopt-doc-general',
			)
		);
		echo '<div class="wpopt-attention-grid">';
		foreach ( $items as $it ) {
			$sev = ( isset( $it['sev'] ) && in_array( $it['sev'], array( 'warn', 'info' ), true ) ) ? $it['sev'] : 'warn';
			// Severity is conveyed by a class-based left border (no inline style) AND a
			// screen-reader-only label, so it is never colour-only.
			$sev_label = ( 'warn' === $sev ) ? __( 'Warning:', 'wpoptimizing' ) : __( 'Heads up:', 'wpoptimizing' );
			printf(
				'<div class="wpopt-attention-card wpopt-attention-card--%1$s">'
					. '<div class="wpopt-attention-card-icon" aria-hidden="true">%2$s</div>'
					. '<strong class="wpopt-attention-card-title"><span class="screen-reader-text">%3$s </span>%4$s</strong>'
					. '<p class="wpopt-attention-card-desc">%5$s</p>'
					. '<a href="%6$s" class="button button-small">%7$s</a>'
					. '</div>',
				esc_attr( $sev ),
				wpoptimizing_icon( $it['icon'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
				esc_html( $sev_label ),
				esc_html( $it['title'] ),
				esc_html( $it['desc'] ),
				esc_url( $it['url'] ),
				esc_html( $it['cta'] )
			);
		}
		echo '</div></div>';
	}

	/**
	 * Standard section header used across every tab/subsection: an <h3> title
	 * (optionally with a dashicon) followed by a muted description paragraph.
	 * Centralised so the look stays uniform — change it here, it changes
	 * everywhere. Per-section quirks go through $args.
	 *
	 * @param string $title       Section title (plain text, will be escaped).
	 * @param string $description Optional description (plain text, will be escaped).
	 * @param array  $args        Optional: 'icon' (dashicon name, no prefix), 'anchor' (id for
	 *                            the <h3>), 'doc' (docs-tab anchor id, e.g. 'wpopt-doc-cache';
	 *                            renders a small Documentation link beside the title).
	 *
	 *                            There is deliberately no way to pass raw HTML here. An
	 *                            'extra_html' argument existed for one, echoed unescaped on the
	 *                            promise that callers pre-escape it — and in fifty-seven call
	 *                            sites not one ever used it. It was a standing invitation for
	 *                            the fifty-eighth to pass something that came from a request.
	 * @return void
	 */
	protected function render_section_header( $title, $description = '', $args = array() ) {
		// Allow the args array to be passed as the 2nd argument when there is no
		// description, e.g. render_section_header( $t, array( 'icon' => 'x' ) ).
		if ( is_array( $description ) ) {
			$args        = $description;
			$description = '';
		}
		$args       = is_array( $args ) ? $args : array();
		$icon       = isset( $args['icon'] ) ? (string) $args['icon'] : '';
		$doc_anchor = isset( $args['doc'] ) ? (string) $args['doc'] : '';
		$anchor     = isset( $args['anchor'] ) ? sanitize_html_class( (string) $args['anchor'] ) : '';

		echo '<h3 class="wpopt-section-title"' . ( '' !== $anchor ? ' id="' . esc_attr( $anchor ) . '"' : '' ) . '>';
		if ( '' !== $icon ) {
			echo $this->icon_html( $icon, 'wpopt-section-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon_html() escapes.
		}
		echo esc_html( $title );
		if ( '' !== $doc_anchor ) {
			echo ' ' . $this->doc_link( $doc_anchor ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link() returns pre-escaped HTML.
		}
		echo '</h3>';

		if ( '' !== $description ) {
			echo '<p class="description wpopt-desc-tight">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Reusable subsection header: an <h3> with the wpopt-subsection-title class
	 * followed by an optional muted description paragraph. Use this for every
	 * sub-heading that appears below a top-level render_section_header() — e.g.
	 * "Backend", "Admin optimizations", "Most duplicated queries". Centralised so
	 * the visual rhythm is identical across every tab.
	 *
	 * @param string $title       Subsection title (plain text, will be escaped).
	 * @param string $description Optional description (plain text, will be escaped).
	 * @return void
	 */
	protected function render_subsection_header( $title, $description = '', $icon = '' ) {
		$icon_html = ( '' !== $icon ) ? $this->icon_html( $icon, 'wpopt-subsection-icon' ) : '';
		echo '<h3 class="wpopt-subsection-title">' . $icon_html . esc_html( $title ) . '</h3>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- icon markup pre-escaped above.
		if ( '' !== $description ) {
			echo '<p class="description wpopt-subsection-desc">' . esc_html( $description ) . '</p>';
		}
	}

	/**
	 * Return a small help button that opens the doc-modal for the given anchor.
	 * The button carries data attributes (title, summary, full-docs URL) so JS can
	 * populate the single shared modal without a server round-trip.
	 * Used by render_section_header() when the 'doc' arg is set; callers may also
	 * use it directly to place the button outside a section header.
	 *
	 * @param string $anchor Anchor id (e.g. 'wpopt-doc-cache'), without leading '#'.
	 * @return string Pre-escaped HTML string (safe to echo directly).
	 */
	protected function doc_link( $anchor ) {
		// Resolve the group key for the ?dsec= deep-link URL.
		static $anchor_group_map = null;
		if ( null === $anchor_group_map ) {
			$anchor_group_map = array(
				'wpopt-doc-cache'         => 'performance',
				'wpopt-doc-pagespeed'     => 'performance',
				'wpopt-doc-tweaks'        => 'performance',
				'wpopt-doc-autoload'      => 'performance',
				'wpopt-doc-tables'        => 'performance',
				'wpopt-doc-cron'          => 'performance',
				'wpopt-doc-general'       => 'cleanup',
				'wpopt-doc-media'         => 'cleanup',
				'wpopt-doc-pending'       => 'cleanup',
				'wpopt-doc-manual-attrib' => 'cleanup',
				'wpopt-doc-orphans'       => 'cleanup',
				'wpopt-doc-inactive'      => 'cleanup',
				'wpopt-doc-active'        => 'cleanup',
				'wpopt-doc-history'       => 'tools',
				'wpopt-doc-backups'       => 'tools',
				'wpopt-doc-settings'      => 'tools',
				'wpopt-doc-webhooks'      => 'tools',
				'wpopt-doc-dataset'       => 'tools',
			);
		}
		$dsec = isset( $anchor_group_map[ $anchor ] ) ? $anchor_group_map[ $anchor ] : 'performance';

		// Sin escapar aquí: se escapa al imprimir, como sus tres atributos hermanos.
		// Escapar al asignar es la costumbre que hace que años después nadie sepa, en el
		// punto de salida, si el valor viene ya escapado o no.
		$url = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'docs',
				'dsec' => $dsec,
			),
			admin_url( 'admin.php' )
		) . '#' . $anchor;

		// Pull title + summary from the data array (no DB hit — pure PHP array).
		$sections = $this->get_doc_sections();
		$title    = '';
		$summary  = '';
		$rich     = '';
		if ( isset( $sections[ $anchor ] ) ) {
			$title   = $sections[ $anchor ]['title'];
			$summary = $sections[ $anchor ]['summary'] ?? '';
			// Optional rich body: sections whose help is a procedure (links, ordered steps)
			// ship pre-escaped, kses-filtered HTML the modal renders instead of the plain
			// summary. Everything else keeps the text-only path.
			$rich = isset( $sections[ $anchor ]['modal'] ) ? wp_kses_post( (string) $sections[ $anchor ]['modal'] ) : '';
		}

		return '<button type="button" class="wpopt-doc-help-btn" '
			. 'data-doc-title="' . esc_attr( $title ) . '" '
			. 'data-doc-summary="' . esc_attr( $summary ) . '" '
			. ( '' !== $rich ? 'data-doc-html="' . esc_attr( $rich ) . '" ' : '' )
			. 'data-doc-url="' . esc_url( $url ) . '" '
			. 'aria-label="' . esc_attr__( 'Help', 'wpoptimizing' ) . '">'
			. wpoptimizing_icon( 'editor-help', 'wpopt-doc-link-icon' )
			. '</button>';
	}

	/**
	 * Render the single reusable doc-modal markup and its JS driver.
	 * Called once in the admin footer; the modal is hidden by default and populated
	 * via data attributes on any .wpopt-doc-help-btn button.
	 *
	 * @return void
	 */
	public function render_doc_modal() {
		?>
		<div id="wpopt-doc-modal" class="wpopt-doc-modal" role="dialog" aria-modal="true" aria-labelledby="wpopt-doc-modal-title" hidden>
			<div class="wpopt-doc-modal-backdrop"></div>
			<div class="wpopt-doc-modal-box" tabindex="-1">
				<div class="wpopt-doc-modal-header">
					<strong id="wpopt-doc-modal-title" class="wpopt-doc-modal-title"></strong>
					<button type="button" id="wpopt-doc-modal-close" class="wpopt-doc-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wpoptimizing' ); ?>"><?php echo wpoptimizing_icon( 'no-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes. ?></button>
				</div>
				<div id="wpopt-doc-modal-body" class="wpopt-doc-modal-body"></div>
				<div class="wpopt-doc-modal-footer">
					<a id="wpopt-doc-modal-link" href="#" class="button button-primary" target="_self"><?php esc_html_e( 'More info', 'wpoptimizing' ); ?></a>
				</div>
			</div>
		</div>
		<?php ob_start(); ?>
		(function(){
			var modal   = document.getElementById('wpopt-doc-modal');
			var title   = document.getElementById('wpopt-doc-modal-title');
			var body    = document.getElementById('wpopt-doc-modal-body');
			var link    = document.getElementById('wpopt-doc-modal-link');
			var closeBtn = document.getElementById('wpopt-doc-modal-close');
			var box     = modal ? modal.querySelector('.wpopt-doc-modal-box') : null;

			if (!modal) return;

			function openModal(btn) {
				title.textContent = btn.dataset.docTitle || '';
				// data-doc-html is built server-side with wp_kses_post(); when a section does
				// not provide one we stay on the text-only path.
				if (btn.dataset.docHtml) {
					body.innerHTML = btn.dataset.docHtml;
				} else {
					body.textContent = btn.dataset.docSummary || '';
				}
				link.href         = btn.dataset.docUrl || '#';
				modal.hidden = false;
				document.body.classList.add('wpopt-modal-open');
				// Focus the dialog box (not the X) so no control shows a focus ring on
				// open; keyboard users still get a ring when they Tab to the close button.
				(box || closeBtn).focus();
			}

			function closeModal() {
				modal.hidden = true;
				document.body.classList.remove('wpopt-modal-open');
				var last = modal._lastTrigger;
				if (last) { last.focus(); modal._lastTrigger = null; }
			}

			document.addEventListener('click', function(e) {
				var btn = e.target.closest('.wpopt-doc-help-btn');
				if (btn) {
					e.preventDefault();
					modal._lastTrigger = btn;
					openModal(btn);
					return;
				}
				if (e.target.closest('#wpopt-doc-modal-close')) { closeModal(); return; }
				if (e.target.closest('.wpopt-doc-modal-backdrop')) { closeModal(); return; }
			});

			document.addEventListener('keydown', function(e) {
				if (!modal.hidden && (e.key === 'Escape' || e.keyCode === 27)) { closeModal(); }
			});
		})();
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}


	/**
	 * A small "Pro" pill/info affordance that opens the reusable upgrade modal (same
	 * pattern as the docs help icon). Placed next to a Pro-only control that is shown but
	 * disabled on the free plan, so the user learns what it is and how to unlock it.
	 *
	 * @return string Safe HTML.
	 */
	protected function pro_hint() {
		return function_exists( 'wpoptimizing_pro_hint' ) ? wpoptimizing_pro_hint() : '';
	}

	/**
	 * The ONE consistent way to explain a premium feature on the free plan: a note card with
	 * a lock icon, a sentence on what Pro adds, and the "Pro" pill that opens the upgrade
	 * modal. Use this instead of ad-hoc `<div class="wpopt-pro-hint">` blocks — that class is
	 * the small inline pill, so wrapping a paragraph in it renders a tiny bordered blob with
	 * no call to action. Keeps every upsell identical across tabs.
	 *
	 * In Lite the note is neutral: no lock, no modal, just the sentence and the "Available in
	 * WPOptimizing Pro." link that pro_hint() returns there.
	 *
	 * @param string $text Plain sentence describing what the Pro version adds.
	 * @return void
	 */
	protected function render_pro_note( $text ) {
		$note = '<div class="wpopt-pro-note wpopt-pro-note--plain" role="note">'
			. '<p class="wpopt-pro-note-text">' . esc_html( $text ) . ' ' . $this->pro_hint() . '</p>'
			. '</div>';
		echo $note; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() and the pre-escaped pro_hint().
	}



	/**
	 * What the extra block of the bulk controls may carry: a disclosure (details/summary) holding
	 * submit buttons with their icons. Anything else a caller passes is stripped. Pure.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function extra_block_allowed_tags() {
		return array_merge(
			wpoptimizing_icon_allowed_tags(),
			array(
				'details' => array(
					'class' => true,
					'open'  => true,
				),
				'summary' => array( 'class' => true ),
				'div'     => array( 'class' => true ),
				'span'    => array(
					'class'       => true,
					'aria-hidden' => true,
				),
				'button'  => array(
					'type'       => true,
					'formmethod' => true,
					'name'       => true,
					'value'      => true,
					'class'      => true,
					'onclick'    => true,
					'disabled'   => true,
				),
			)
		);
	}

	/**
	 * Render the async bulk-action controls for a selectable list table: a
	 * "delete selected" submit (progressive-enhancement fallback to the normal
	 * form POST) and a JS-only "process all (N)" button that operates on the
	 * full FILTERED set. Both run in batches of 50 via AJAX with a progress bar.
	 * The descriptive text is rendered BELOW the buttons.
	 *
	 * @param WPOptimizing_Base_List_Table $table List table (must expose a bulk_scope()).
	 * @param array                        $opts  Labels/confirm/description overrides.
	 * @return void
	 */
	protected function render_bulk_controls( $table, array $opts = array() ) {
		if ( ! ( $table instanceof WPOptimizing_Base_List_Table ) || ! $table->is_selectable() ) {
			return;
		}
		$scope = $table->bulk_scope();
		if ( '' === $scope ) {
			return;
		}

		$o = array_merge(
			array(
				// Set by a caller whose list cannot be trusted as a complete set — today, the
				// unused-media scan when it hit its row cap. The "act on everything" mode is
				// then withdrawn and only an explicit selection is accepted.
				'no_all'           => false,
				'selected_label'   => __( 'Delete selected', 'wpoptimizing' ),
				'all_label'        => __( 'Process all', 'wpoptimizing' ),
				'description'      => __( 'A backup is created before deletion.', 'wpoptimizing' ),
				/* translators: %d: number of selected items. */
				'confirm_selected' => __( 'Delete the %d selected item(s)? A backup is created first.', 'wpoptimizing' ),
				/* translators: %d: number of filtered items. */
				'confirm_all'      => __( 'Process ALL %d filtered item(s)? A backup is created first.', 'wpoptimizing' ),
				'low_conf'         => false,
				'submit_name'      => '',
				'submit_value'     => '1',
				'extra_buttons'    => '',
				'extra_block'      => '',
				'extra_action'     => array(),
				'button_class'     => 'button-primary',
				'primary'          => true,
			),
			$opts
		);

		// Lite deletes attributed orphans (records and files) one at a time; deleting them in bulk
		// is Pro (23/10/2026). The server enforces it too (run_bulk_batch()).
		$one_by_one = in_array( $scope, array( 'db', 'fs' ), true );
		if ( $one_by_one ) {
			$o['no_all']      = true;
			$o['description'] = __( 'This plugin deletes one item at a time, backing it up first. Pro deletes as many as you select, or all of them at once.', 'wpoptimizing' );
		}

		$all    = $table->get_all_cb_values();
		$values = $all['values'];
		$total  = count( $values );
		$cbname = $table->bulk_cb_name();
		$nonce  = wp_create_nonce( 'wpoptimizing_bulk' );

		static $uid = 0;
		++$uid;
		$list_id = 'wpopt-bulk-list-' . $uid;
		$pb_id   = 'wpopt-bulk-pb-' . $uid;

		// Full filtered identifier list for "process all" (every page).
		wp_print_inline_script_tag(
			(string) wp_json_encode( $values ),
			array(
				'type' => 'application/json',
				'id'   => $list_id,
			)
		);

		// Optional secondary bulk action (e.g. MyISAM → InnoDB conversion). Shares
		// the same checkboxes, overlay and nonce as the primary action but targets
		// its own scope; "all" operates on a caller-supplied subset list.
		$extra      = is_array( $o['extra_action'] ) ? $o['extra_action'] : array();
		$ex_values  = ( ! empty( $extra['scope'] ) && isset( $extra['all_values'] ) && is_array( $extra['all_values'] ) ) ? array_values( $extra['all_values'] ) : array();
		$ex_list_id = 'wpopt-bulk-list-x' . $uid;
		if ( ! empty( $extra['scope'] ) ) {
			wp_print_inline_script_tag(
				(string) wp_json_encode( $ex_values ),
				array(
					'type' => 'application/json',
					'id'   => $ex_list_id,
				)
			);
		}

		// Progress bar (hidden until a job starts). Reuses the .wpopt-pb-* look.
		// Centered progress overlay (the very same modal the analysis uses), shown
		// by the bulk JS while processing — not an inline bar above the buttons.
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped overlay markup.
		echo wpoptimizing_progress_overlay_markup(
			__( 'WPOptimizing — Working…', 'wpoptimizing' ),
			array(
				'overlay_id' => $pb_id,
				'hidden'     => true,
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-escaped markup helper.

		// Opt-in to the destructive deletions (orphaned CPT content / orphaned
		// taxonomy terms). Only meaningful on the database tables.
		if ( 'db' === $scope && $o['primary'] ) {
			echo '<p class="wpopt-para-check"><label><input type="checkbox" name="wpopt_destructive" value="1" class="wpopt-destructive-cb" /> '
				. esc_html__( 'Also delete the content of orphaned custom post types and the terms of orphaned taxonomies (backed up and restorable).', 'wpoptimizing' )
				. '</label></p>';
		}

		echo '<p class="wpopt-notice">';
		// The primary action can be suppressed (e.g. unused-attachments for free users,
		// where deletion is Pro but "Mark as known" stays available as the extra action).
		if ( $o['primary'] ) {
			// ONE dynamic button instead of two. With nothing checked it acts on the
			// whole filtered set ("Clean all (N)"); as soon as rows are checked the JS
			// rewrites its label and switches it to act on the selection only
			// ("Clean selected (M)"). Fewer buttons, clearer intent, same safety
			// (confirm + backup-first). type=submit keeps a no-JS fallback.
			printf(
				'<button type="submit"%s class="button %s wpopt-bulk-btn wpopt-bulk-dyn" data-wpopt-bulk="dynamic" data-mode="all" data-scope="%s" data-cbname="%s" data-nonce="%s" data-pb="%s" data-lowconf="%s" data-lowcount="%d" data-list="%s" data-total="%d" data-confirm-selected="%s" data-confirm-all="%s" data-label-selected="%s" data-label-all="%s" data-noall="%s"%s>%s</button>',
				( '' !== (string) $o['submit_name'] ? ' name="' . esc_attr( (string) $o['submit_name'] ) . '" value="' . esc_attr( (string) $o['submit_value'] ) . '"' : '' ) . ( $one_by_one ? ' data-max="1"' : '' ),
				esc_attr( (string) $o['button_class'] ),
				esc_attr( $scope ),
				esc_attr( $cbname ),
				esc_attr( $nonce ),
				esc_attr( $pb_id ),
				$o['low_conf'] ? '1' : '0',
				(int) ( $all['low'] ?? 0 ),
				esc_attr( $list_id ),
				(int) $total,
				esc_attr( (string) $o['confirm_selected'] ),
				esc_attr( (string) $o['confirm_all'] ),
				esc_attr( (string) $o['selected_label'] ),
				esc_attr( (string) $o['all_label'] ),
				$o['no_all'] ? '1' : '0',
				( $o['no_all'] || 0 === $total ) ? ' disabled' : '',
				// Without "act on everything" the label is the one dynSync() sets, not "Process all (N)".
				esc_html( $o['no_all'] ? __( 'Select the items to delete', 'wpoptimizing' ) : sprintf( '%s (%s)', (string) $o['all_label'], number_format_i18n( $total ) ) )
			);
		}
		if ( '' !== (string) $o['extra_buttons'] ) {
			echo ' ' . $o['extra_buttons']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Caller passes pre-escaped button HTML.
		}
		if ( ! empty( $extra['scope'] ) ) {
			$ex_scope  = (string) $extra['scope'];
			$ex_total  = count( $ex_values );
			$ex_btncls = isset( $extra['button_class'] ) ? (string) $extra['button_class'] : '';
			// Secondary action (e.g. MyISAM -> InnoDB) as ONE dynamic button too:
			// "Convert all (N)" with nothing checked, "Convert selected (M)" otherwise.
			// It shares the checkboxes; the server re-validates and silently skips
			// ineligible tables, so a broad selection is safe.
			printf(
				' <button type="button" class="button %s wpopt-bulk-btn wpopt-bulk-dyn" data-wpopt-bulk="dynamic" data-mode="all" data-scope="%s" data-cbname="%s" data-nonce="%s" data-pb="%s" data-lowconf="0" data-list="%s" data-total="%d" data-confirm-selected="%s" data-confirm-all="%s" data-label-selected="%s" data-label-all="%s"%s>%s</button>',
				esc_attr( $ex_btncls ),
				esc_attr( $ex_scope ),
				esc_attr( $cbname ),
				esc_attr( $nonce ),
				esc_attr( $pb_id ),
				esc_attr( $ex_list_id ),
				(int) $ex_total,
				esc_attr( (string) ( $extra['confirm_selected'] ?? '' ) ),
				esc_attr( (string) ( $extra['confirm_all'] ?? '' ) ),
				esc_attr( (string) ( $extra['selected_label'] ?? '' ) ),
				esc_attr( (string) ( $extra['all_label'] ?? '' ) ),
				0 === $ex_total ? ' disabled' : '',
				esc_html( sprintf( '%s (%s)', (string) ( $extra['all_label'] ?? '' ), number_format_i18n( $ex_total ) ) )
			);
		}
		echo '</p>';

		if ( '' !== (string) $o['description'] ) {
			echo '<p class="description wpopt-desc-tight">' . esc_html( (string) $o['description'] ) . '</p>';
		}
		if ( ! empty( $all['truncated'] ) ) {
			echo '<p class="description wpopt-warn">' . esc_html__( '“Process all” is capped at 20,000 items per run; repeat to continue.', 'wpoptimizing' ) . '</p>';
		}

		// Block-level extra content (e.g. an "Advanced actions" <details> menu) that
		// cannot live inside the <p> above. Caller passes pre-escaped HTML.
		if ( '' !== (string) $o['extra_block'] ) {
			echo wp_kses( (string) $o['extra_block'], self::extra_block_allowed_tags() );
		}

		$this->print_bulk_js_once();
	}

	/**
	 * Print the shared async-bulk JavaScript once per page: collects ids
	 * (checked rows for "selected", the embedded list for "all"), confirms, then
	 * POSTs them to the bulk endpoint in batches of 50 while driving a progress
	 * bar, and reloads when finished.
	 *
	 * @return void
	 */
	protected function print_bulk_js_once() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$strings = array(
			'none'       => __( 'Select at least one item first.', 'wpoptimizing' ),
			'noall'      => __( 'This scan was bounded, so acting on everything is not offered. Tick the items you have checked yourself.', 'wpoptimizing' ),
			'pickfirst'  => __( 'Select the items to delete', 'wpoptimizing' ),
			'onebyone'   => __( 'This plugin deletes one item at a time. Select just one; Pro deletes as many as you select.', 'wpoptimizing' ),
			/* translators: 1: processed count, 2: total count. */
			'running'    => __( 'Processing %1$s of %2$s…', 'wpoptimizing' ),
			/* translators: %s: total processed. */
			'doneMsg'    => __( 'Done — %s processed. Reloading…', 'wpoptimizing' ),
			'error'      => __( 'A batch failed. Stopping and reporting what was done…', 'wpoptimizing' ),
			'lowconf'    => __( 'One or more selected items are attributed by heuristic or are unknown — they may belong to an active plugin. Continue?', 'wpoptimizing' ),
			/* translators: %d: number of low-confidence items in the filtered set. */
			'lowconfAll' => __( '%d of the items to process are attributed by heuristic or are unknown — they may belong to an active plugin. Review them, or filter by confidence first. Continue anyway?', 'wpoptimizing' ),
			// Per-scope action verb shown on the progress bar (e.g. "Optimizing tables (3/10)").
			'verbs'      => array(
				'optimize_tables' => __( 'Optimizing tables', 'wpoptimizing' ),
				'convert_innodb'  => __( 'Converting tables to InnoDB', 'wpoptimizing' ),
				'unused'          => __( 'Deleting attachments', 'wpoptimizing' ),
				'trust_unused'    => __( 'Marking as known', 'wpoptimizing' ),
				'media_dupes'     => __( 'Deleting duplicates', 'wpoptimizing' ),
				'cron'            => __( 'Removing scheduled events', 'wpoptimizing' ),
				'autoload'        => __( 'Updating options', 'wpoptimizing' ),
				'db'              => __( 'Deleting records', 'wpoptimizing' ),
				'fs'              => __( 'Deleting files', 'wpoptimizing' ),
			),
		);

		wp_print_inline_script_tag(
			'(function(){
	if(window.wpoptBulkBound){return;} window.wpoptBulkBound=true;
	var AJAX=' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ', S=' . wp_json_encode( $strings ) . ', BATCH=50;
	function fmt(t,a,b){return String(t).replace("%1$s",a).replace("%2$s",b).replace("%s",a);}
	function setBtns(d){document.querySelectorAll(".wpopt-bulk-btn").forEach(function(b){b.disabled=d;});}
	function runBulk(scope,ids,nonce,pb,mode){
		var total=ids.length,done=0;
		// Heavy per-item ops (OPTIMIZE/ALTER rebuild a table each) use a small batch so
		// the bar advances often and no single request risks a timeout; rows are sorted
		// biggest-first, so a 50-wide first batch is exactly the slowest and looked stuck.
		var batch=(scope==="optimize_tables"||scope==="convert_innodb"||scope==="media_dupes")?3:BATCH;
		// One job token for the whole operation so every batch backs up into a
		// single, recognisable backup entry.
		var job=("j"+Date.now().toString(36)+Math.random().toString(36).slice(2,8)).replace(/[^a-z0-9]/g,"");
		var fill=pb?pb.querySelector(".wpopt-pb-fill"):null,label=pb?pb.querySelector(".wpopt-pb-label"):null,pctEl=pb?pb.querySelector(".wpopt-pb-pct"):null;
		if(pb){pb.style.display="flex";}
		setBtns(true);
		var verb=(S.verbs&&S.verbs[scope])?S.verbs[scope]:"";
		function update(){var p=total?Math.round(done/total*100):100;if(fill){fill.style.width=Math.max(1,p)+"%";}if(pctEl){pctEl.textContent=p+"%";}if(label){label.textContent=verb?(verb+"… ("+done.toLocaleString()+"/"+total.toLocaleString()+")"):fmt(S.running,done.toLocaleString(),total.toLocaleString());}}
		update();
		function step(i){
			if(i>=total){if(label){label.textContent=fmt(S.doneMsg,total.toLocaleString());}if(fill){fill.style.width="100%";}setTimeout(function(){window.location.reload();},600);return;}
			var chunk=ids.slice(i,i+batch),isFinal=(i+batch)>=total;
			var body=new URLSearchParams();
			body.set("action","wpoptimizing_bulk_delete");body.set("nonce",nonce);body.set("scope",scope);body.set("final",isFinal?"1":"0");body.set("job",job);body.set("mode",mode||"all");
			var dcb=document.querySelector(".wpopt-destructive-cb");if(dcb&&dcb.checked){body.set("wpopt_destructive","1");}
			chunk.forEach(function(v){body.append("ids[]",v);});
			fetch(AJAX,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:body.toString()})
				.then(function(r){return r.json();})
				.then(function(r){if(!r||!r.success){throw new Error("batch");}done+=chunk.length;update();step(i+batch);})
				.catch(function(){
					if(label){label.textContent=S.error;label.style.color="#b32d2e";}
					// Close the job on the server, so the page reports what was already done and offers Undo.
					var ab=new URLSearchParams();ab.set("action","wpoptimizing_bulk_delete");ab.set("nonce",nonce);ab.set("scope",scope);ab.set("job",job);ab.set("abort","1");
					fetch(AJAX,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:ab.toString()})
						.then(function(){setTimeout(function(){window.location.reload();},1500);})
						.catch(function(){setBtns(false);});
				});
		}
		step(0);
	}
	// Keep every dynamic button in sync with its form selection: 0 checked -> act on
	// all (N); >=1 checked -> act on selection (M). Runs after WP toggles rows on
	// "select all" (deferred), since WP sets .checked without firing change events.
	function dynSync(btn){
		var cb=btn.getAttribute("data-cbname"),form=btn.closest("form"),root=form||document;
		var n=root.querySelectorAll(\'input[type=checkbox][name="\'+cb+\'"]:checked\').length;
		var total=parseInt(btn.getAttribute("data-total"),10)||0;
		var noAll=btn.getAttribute("data-noall")==="1";
		if(n>0){btn.setAttribute("data-mode","selected");btn.textContent=btn.getAttribute("data-label-selected")+" ("+n.toLocaleString()+")";btn.disabled=false;}
		else{btn.setAttribute("data-mode","all");btn.textContent=noAll?(S.pickfirst||btn.getAttribute("data-label-all")):(btn.getAttribute("data-label-all")+" ("+total.toLocaleString()+")");btn.disabled=(noAll||total===0);}
	}
	function dynSyncAll(root){(root||document).querySelectorAll(".wpopt-bulk-dyn").forEach(dynSync);}
	document.addEventListener("change",function(e){var t=e.target;if(t&&t.type==="checkbox"){var f=t.closest("form")||document;setTimeout(function(){f.querySelectorAll(".wpopt-bulk-dyn").forEach(dynSync);},0);}});
	if(document.readyState!=="loading"){dynSyncAll(document);}else{document.addEventListener("DOMContentLoaded",function(){dynSyncAll(document);});}
	document.addEventListener("click",function(e){
		var btn=e.target.closest(".wpopt-bulk-btn");if(!btn||btn.disabled){return;}
		e.preventDefault();
		var raw=btn.getAttribute("data-wpopt-bulk"),mode=(raw==="dynamic")?(btn.getAttribute("data-mode")||"all"):raw;
		// A bounded scan cannot say which items are really unused, so "process all" is not on
		// offer — only rows somebody ticked after looking at them.
		if(mode==="all"&&btn.getAttribute("data-noall")==="1"){window.alert(S.noall||S.none);return;}
		var scope=btn.getAttribute("data-scope"),cbname=btn.getAttribute("data-cbname"),nonce=btn.getAttribute("data-nonce");
		var pb=document.getElementById(btn.getAttribute("data-pb")),ids=[];
		if(mode==="all"){var el=document.getElementById(btn.getAttribute("data-list"));if(el){try{ids=JSON.parse(el.textContent||"[]");}catch(_){ids=[];}}}
		else{var form=btn.closest("form"),root=form||document;root.querySelectorAll(\'input[type=checkbox][name="\'+cbname+\'"]:checked\').forEach(function(b){ids.push(b.value);});}
		if(!ids.length){window.alert(S.none);return;}
		var mx=parseInt(btn.getAttribute("data-max")||"0",10);if(mx>0&&ids.length>mx){window.alert(S.onebyone);return;}
		if(mode==="selected"&&btn.getAttribute("data-lowconf")==="1"){
			var low=["heuristic","unknown","none","weak"],formx=btn.closest("form"),hasLow=false;
			if(formx){formx.querySelectorAll("input[type=checkbox][data-conf]:checked").forEach(function(b){if(low.indexOf(b.dataset.conf)!==-1){hasLow=true;}});}
			if(hasLow&&!window.confirm(S.lowconf)){return;}
		}
		// "Process all" used to skip this warning altogether, so the riskiest mode was the one
		// that never asked. The count of low-confidence rows comes from the full filtered set.
		var lowAll=parseInt(btn.getAttribute("data-lowcount")||"0",10);
		if(mode==="all"&&btn.getAttribute("data-lowconf")==="1"&&lowAll>0&&!window.confirm(String(S.lowconfAll).replace("%d",lowAll))){return;}
		var conf=(raw==="dynamic")?(mode==="all"?btn.getAttribute("data-confirm-all"):btn.getAttribute("data-confirm-selected")):btn.getAttribute("data-confirm");
		var msg=(conf||"").replace("%d",ids.length);
		if(!window.confirm(msg)){return;}
		runBulk(scope,ids,nonce,pb,mode);
	});
}());'
		);
	}


	/**
	 * The action nonce, for a form that is GET because of its search, filters and paging.
	 *
	 * Printed disabled, so a search or a filter never carries it into the URL; the form's
	 * action buttons carry formmethod="post", and the script enables the nonce only when one
	 * of them is the submitter. maybe_handle_post() acts on POST only, so without that nonce
	 * a GET submit changes nothing.
	 *
	 * @return void
	 */
	protected function render_post_only_nonce() {
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( wp_create_nonce( $this->nonce_action ) ) . '" class="wpopt-post-nonce" disabled />';
		wp_print_inline_script_tag( '(function(){var f=document.currentScript.closest("form");if(!f){return;}f.addEventListener("submit",function(e){var s=e.submitter,n=f.querySelector("input.wpopt-post-nonce");if(n){n.disabled=!(s&&"post"===String(s.getAttribute("formmethod")||"").toLowerCase());}});}());' );
	}

	/**
	 * Render a native WP_List_Table form (GET) with search, filters, pagination, and Delete action.
	 *
	 * @param WPOptimizing_Base_List_Table $table List table instance.
	 * @param string                       $tab   Current tab key.
	 * @param string|null                  $sub   Subview key (dbview or isec).
	 * @param string|null                  $cta   CTA label (defaults to "Delete").
	 * @param bool                         $cascade Render the "also remove associated data" checkbox before the CTA.
	 * @param bool                         $show_submit Whether to render the submit button.
	 * @return void
	 */
	protected function render_table_form( WPOptimizing_Base_List_Table $table, $tab, $sub = null, $cta = null, $cascade = false, $show_submit = true ) {
		static $db_table_counter = 0;


		$wrap_id = '';
		if ( $table instanceof WPOptimizing_DB_List_Table ) {
			++$db_table_counter;
			$wrap_id = 'wpopt-db-wrap-' . $db_table_counter;
			echo '<div id="' . esc_attr( $wrap_id ) . '">';
		}

		echo '<form method="get">';
		$this->render_post_only_nonce();
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( (string) $tab ) . '" />';
		if ( 'orphans' === $tab && null !== $sub ) {
			echo '<input type="hidden" name="osec" value="' . esc_attr( (string) $sub ) . '" />';
		}
		if ( 'inactive' === $tab && null !== $sub ) {
			echo '<input type="hidden" name="isec" value="' . esc_attr( (string) $sub ) . '" />';
		}
		if ( 'active' === $tab && null !== $sub ) {
			echo '<input type="hidden" name="asec" value="' . esc_attr( (string) $sub ) . '" />';
		}
		if ( 'media' === $tab && null !== $sub ) {
			echo '<input type="hidden" name="msec" value="' . esc_attr( (string) $sub ) . '" />';
		}

		if ( $table instanceof WPOptimizing_DB_List_Table ) {
			echo '<div class="wpopt-legend-search">';
			$this->render_confidence_legend_compact();
			$table->search_box( __( 'Search', 'wpoptimizing' ), 'wpoptimizing-search' );
			echo '</div>';
		} else {
			$table->search_box( __( 'Search', 'wpoptimizing' ), 'wpoptimizing-search' );
		}
		$table->display();

		// This used to take raw HTML from the caller and print it verbatim, which made the
		// safety of this line a property of code somewhere else. There was only ever one
		// caller and only ever one thing it passed, so it is a flag now: nothing to escape,
		// nothing to suppress, and no contract for a future caller to break.
		if ( $cascade ) {
			// Off by default: uninstalling is already irreversible for the plugin files, and the
			// associated data is a second, separate decision the user has to make on purpose.
			echo '<p><label><input type="checkbox" name="cascade_associated" value="1"> '
				. esc_html__( 'When uninstalling, also remove any unnecessary files or data associated with the selected plugins or themes.', 'wpoptimizing' )
				. '</label></p>';
		}

		if ( $show_submit ) {
			if ( '' !== $table->bulk_scope() ) {
				// Async batch engine (DB/FS): "delete selected" + "process all".
				$bulk_opts = array();
				if ( $table instanceof WPOptimizing_DB_List_Table ) {
					$bulk_opts['low_conf']    = true;
					$bulk_opts['description'] = __( 'Deleted records are removed from the scan immediately. Low-confidence selections are confirmed first.', 'wpoptimizing' );
				} else {
					$bulk_opts['description'] = __( 'A copy is saved to the Backups tab before deletion.', 'wpoptimizing' );
				}
				if ( $cta ) {
					$bulk_opts['selected_label'] = $cta;
				}
				// On any attribution surface, offer "Attribute selected…": ships the checked
				// rows up to the manual-attribution form (one exact rule each). Shares the
				// table's own checkbox group via data-cbname.
				if ( in_array( $tab, array( 'pending', 'empty', 'orphans', 'inactive', 'active' ), true ) && function_exists( 'wpoptimizing_manual_attributions' ) ) {
					$bulk_opts['extra_buttons'] = sprintf(
						'<button type="button" class="button wpopt-ma-pick" data-cbname="%s" disabled>%s <span class="wpopt-count wpopt-ma-pick-count">0</span></button>',
						esc_attr( $table->bulk_cb_name() ),
						esc_html__( 'Attribute selected', 'wpoptimizing' )
					);
				}
				// Bulk protect for DB items (same "type::value" entries as the per-row shield).
				// One button that flips its own action from the selection: it unprotects when
				// every ticked row is already protected, and protects otherwise (the common case,
				// and idempotent for any already-protected rows in a mixed selection). Only on the
				// DB tables, where the protected-items guard applies.
				if ( $table instanceof WPOptimizing_DB_List_Table && function_exists( 'wpoptimizing_item_is_protected' ) ) {
					$bulk_opts['extra_buttons'] = ( isset( $bulk_opts['extra_buttons'] ) ? $bulk_opts['extra_buttons'] : '' ) . sprintf(
						' <button type="button" class="button wpopt-protect-pick" data-cbname="%1$s" data-protect="1" data-label-protect="%2$s" data-label-unprotect="%3$s" disabled><span class="wpopt-protect-pick-label">%2$s</span> <span class="wpopt-count wpopt-protect-pick-count">0</span></button>',
						esc_attr( $table->bulk_cb_name() ),
						esc_attr__( 'Protect selected', 'wpoptimizing' ),
						esc_attr__( 'Unprotect selected', 'wpoptimizing' )
					);
				}
				// The Active view is informational: records of active extensions are never
				// deletable. Suppress the primary Delete button but keep "Attribute selected"
				// (to correct a wrong owner) and "Protect selected".
				if ( 'active' === $tab ) {
					$bulk_opts['primary']     = false;
					$bulk_opts['description'] = __( 'Select records to reassign a wrong attribution, or to protect them from future cleanups. Active records are never deleted here.', 'wpoptimizing' );
				}
				$this->render_bulk_controls( $table, $bulk_opts );
			} else {
				// Legacy synchronous submit (non-async tables, e.g. Projects).
				submit_button( $cta ? $cta : __( 'Delete', 'wpoptimizing' ), 'primary', 'submit', true, array( 'formmethod' => 'post' ) );
				// Uninstalling or deactivating extensions went through on one click, with nothing
				// naming what was about to go. Now the selection is listed and confirmed, and an
				// empty selection does nothing.
				$proj_msgs = array(
					'none'       => __( 'Select at least one plugin or theme first.', 'wpoptimizing' ),
					'uninstall'  => __( 'These plugins and themes will be uninstalled: their files are deleted from the server. This cannot be undone from WPOptimizing.', 'wpoptimizing' ),
					'cascade'    => __( 'Their data and files found by the analysis will also be deleted (a copy is saved to Backups).', 'wpoptimizing' ),
					'deactivate' => __( 'These plugins and themes will be deactivated. You can activate them again from the Plugins screen.', 'wpoptimizing' ),
				);
				wp_print_inline_script_tag(
					'(function(){
var form=document.currentScript.closest("form");
if(!form){return;}
var M=' . wp_json_encode( $proj_msgs ) . ';
form.addEventListener("submit",function(e){
  var proj=form.querySelectorAll("input[type=checkbox][data-label]");
  // Search and Filter submit this same form, and the handler acts on any ticked box: a
  // search with rows ticked would uninstall them unconfirmed. Those submits carry no selection.
  if(proj.length&&!(e.submitter&&e.submitter.name==="submit")){proj.forEach(function(b){b.disabled=true;});return;}
  if(proj.length){
    var picked=[],mode="";
    proj.forEach(function(b){if(b.checked){picked.push("• "+b.dataset.label);mode=b.name.indexOf("deactivate_")===0?"deactivate":"uninstall";}});
    if(!picked.length){e.preventDefault();window.alert(M.none);return;}
    var cas=form.querySelector("input[name=cascade_associated]");
    var msg=M[mode]+"\n\n"+picked.join("\n")+((mode==="uninstall"&&cas&&cas.checked)?"\n\n"+M.cascade:"");
    if(!window.confirm(msg)){e.preventDefault();}
    return;
  }
  var low=["heuristic","unknown","none","weak"];
  var boxes=form.querySelectorAll("input[type=checkbox][data-conf]:checked");
  var hasLow=false;
  boxes.forEach(function(b){if(low.indexOf(b.dataset.conf)!==-1){hasLow=true;}});
  if(hasLow&&!window.confirm(' . wp_json_encode( __( 'One or more selected items are attributed by heuristic or are unknown — they may belong to an active plugin. Are you sure you want to delete them?', 'wpoptimizing' ) ) . ')){e.preventDefault();}
});
}());'
				);
			}
		}
		echo '</form>';

		if ( $table instanceof WPOptimizing_DB_List_Table ) {
			$this->render_protect_toggle_js();
			$this->render_protect_bulk_js();
			echo '</div>';
		}

	}

	/**
	 * Render a compact inline badge row for use next to the search box.
	 *
	 * @return void
	 */
	protected function render_confidence_legend_compact() {
		echo '<div class="wpopt-conf-legend">';
		echo '<span class="wpopt-conf-label">' . esc_html__( 'Confidence:', 'wpoptimizing' ) . '</span>';
		// Las etiquetas y las frases vienen de resolvers/confidence-levels.php, igual que la
		// insignia de la columna y la leyenda del asistente: antes esta lista y la del
		// asistente explicaban «Possible» de dos maneras distintas.
		foreach ( array_keys( wpoptimizing_confidence_levels() ) as $level ) {
			echo '<span class="wpopt-conf-badge wpopt-conf-' . esc_attr( $level ) . '" title="'
				. esc_attr( wpoptimizing_confidence_description( $level ) ) . '">'
				. esc_html( wpoptimizing_confidence_label( $level ) ) . '</span>';
		}
		echo '</div>';
	}

	/**
	 * One-time JS for the bulk "Protect selected" / "Unprotect selected" buttons: keeps their
	 * count in sync with the ticked rows and, on click, sends the checked "type::value" entries
	 * to the bulk-protect endpoint and reloads so the shields reflect the new state. Reuses the
	 * same nonce as the per-row shield toggle.
	 *
	 * @return void
	 */
	protected function render_protect_bulk_js() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done  = true;
		$nonce = wp_create_nonce( 'wpoptimizing_toggle_protected' );
		?>
		<?php ob_start(); ?>
		(function(){
			var AJAX = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, NONCE = <?php echo wp_json_encode( $nonce ); ?>;
			function gather( cb ){
				var out = [];
				document.querySelectorAll('input[type=checkbox][name="' + cb + '"]:checked').forEach(function(b){
					if ( b.value && b.value.indexOf('::') !== -1 ) { out.push(b.value); }
				});
				return out;
			}
			function refresh(){
				// Map every visible row's entry to its current protected state (from the shields).
				var state = {};
				document.querySelectorAll('.wpopt-shield-toggle').forEach(function(sh){
					state[ sh.getAttribute('data-entry') ] = ( sh.getAttribute('data-protected') === '1' );
				});
				document.querySelectorAll('.wpopt-protect-pick').forEach(function(btn){
					var cb = btn.getAttribute('data-cbname');
					var checked = cb ? Array.prototype.slice.call(document.querySelectorAll('input[type=checkbox][name="' + cb + '"]:checked')) : [];
					var n = checked.length;
					// Unprotect only when EVERY ticked row is already protected; otherwise protect.
					var allProtected = n > 0;
					checked.forEach(function(b){ if ( ! state[ b.value ] ) { allProtected = false; } });
					var mode = allProtected ? '0' : '1';
					btn.setAttribute('data-protect', mode);
					var lbl = btn.querySelector('.wpopt-protect-pick-label');
					if ( lbl ) { lbl.textContent = ( '0' === mode ) ? ( btn.getAttribute('data-label-unprotect') || lbl.textContent ) : ( btn.getAttribute('data-label-protect') || lbl.textContent ); }
					var c = btn.querySelector('.wpopt-protect-pick-count');
					if ( c ) { c.textContent = String(n); }
					btn.disabled = ( n === 0 );
				});
			}
			document.addEventListener('change', function(e){ if ( e.target && e.target.type === 'checkbox' ) { refresh(); } });
			document.addEventListener('click', function(e){
				var btn = e.target.closest('.wpopt-protect-pick');
				if ( ! btn || btn.disabled ) { return; }
				e.preventDefault();
				var entries = gather( btn.getAttribute('data-cbname') );
				if ( ! entries.length ) { return; }
				var data = new URLSearchParams();
				data.set('action', 'wpoptimizing_bulk_protect');
				data.set('nonce', NONCE);
				data.set('protect', btn.getAttribute('data-protect') || '1');
				entries.forEach(function(x){ data.append('entries[]', x); });
				document.querySelectorAll('.wpopt-protect-pick').forEach(function(b){ b.disabled = true; });
				fetch(AJAX, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: data.toString()
				}).then(function(r){ return r.json(); }).then(function(){ location.reload(); }).catch(function(){ location.reload(); });
			});
			refresh();
		})();
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * One-time JS for the per-row protect shield of the database tables.
	 *
	 * @return void
	 */
	protected function render_protect_toggle_js() {
		$protect_nonce = wp_create_nonce( 'wpoptimizing_toggle_protected' );
		?>
		<?php ob_start(); ?>
		(function(){
			if ( window.wpoptProtectToggleBound ) { return; }
			window.wpoptProtectToggleBound = true;
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var protectNonce = <?php echo wp_json_encode( $protect_nonce ); ?>;
			var protectTitles = {
				on:  <?php echo wp_json_encode( __( 'Protected from deletion — click to unprotect', 'wpoptimizing' ) ); ?>,
				off: <?php echo wp_json_encode( __( 'Protect from deletion', 'wpoptimizing' ) ); ?>
			};
			function toggleProtected( btn ) {
				if ( btn.dataset.busy === '1' ) { return; }
				btn.dataset.busy = '1';
				var want = btn.dataset.protected === '1' ? '0' : '1';
				var data = new URLSearchParams();
				data.set('action', 'wpoptimizing_toggle_protected');
				data.set('nonce', protectNonce);
				data.set('entry', btn.dataset.entry);
				data.set('protect', want);
				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: data.toString()
				})
				.then(function(r){ return r.json(); })
				.then(function(r){
					if (r && r.success) {
						var on = !!(r.data && r.data.protected);
						btn.dataset.protected = on ? '1' : '0';
						btn.classList.toggle('is-protected', on);
						btn.setAttribute('aria-pressed', on ? 'true' : 'false');
						var t = on ? protectTitles.on : protectTitles.off;
						btn.title = t;
						var sr = btn.querySelector('.screen-reader-text');
						if (sr) { sr.textContent = t; }
					}
				})
				.finally(function(){ btn.dataset.busy = '0'; });
			}
			document.addEventListener('click', function(e){
				var shield = e.target.closest('.wpopt-shield-toggle');
				if (shield) {
					e.preventDefault();
					toggleProtected(shield);
				}
			});
		})();
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * The button that opens a record in the record viewer, for any list that shows one.
	 *
	 * Every table that lists a database record — scan results, empty records, autoload options,
	 * cron events, tables, attachments — calls this, so they all open the same viewer the same
	 * way. A type the viewer cannot read keeps its plain label.
	 *
	 * @param string $type       Record type (see wpoptimizing_record_query_types()).
	 * @param string $value      Record key: option name, meta key, hook, table name, ID…
	 * @param string $label_html Safe HTML shown as the button text.
	 * @return string Safe HTML.
	 */
	public function record_query_button( $type, $value, $label_html ) {
		$type  = strtolower( (string) $type );
		$value = (string) $value;
		if ( '' === $value || ! function_exists( 'wpoptimizing_record_query_types' ) || ! in_array( $type, wpoptimizing_record_query_types(), true ) ) {
			return $label_html;
		}
		return '<button type="button" class="wpopt-lq-trigger wpopt-btn-ghost" data-type="' . esc_attr( $type ) . '" data-value="' . esc_attr( $value ) . '" title="' . esc_attr__( 'View its content in the database', 'wpoptimizing' ) . '">' . $label_html . '</button>';
	}

	/**
	 * The record viewer: one modal per page that shows what a record holds in the database.
	 *
	 * It used to be a panel printed under each database table: opening a record scrolled the
	 * page down to it, so comparing several meant scrolling down and back up for each one, and
	 * the tabs that are not scan tables (Autoload, Cron, Tables, Media) had no way to look at
	 * all. Now any .wpopt-lq-trigger on the page opens this modal, the content is fetched only
	 * when asked for (some options weigh megabytes), and a long cell opens whole on top of it.
	 *
	 * @return void
	 */
	public function render_record_viewer() {
		$nonce = wp_create_nonce( 'wpoptimizing_live_query' );
		?>
		<div id="wpopt-record-modal" class="wpopt-doc-modal wpopt-record-modal" role="dialog" aria-modal="true" aria-labelledby="wpopt-record-modal-title" hidden>
			<div class="wpopt-doc-modal-backdrop"></div>
			<div class="wpopt-doc-modal-box" tabindex="-1">
				<div class="wpopt-doc-modal-header">
					<strong id="wpopt-record-modal-title" class="wpopt-doc-modal-title"></strong>
					<button type="button" class="wpopt-doc-modal-close wpopt-record-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wpoptimizing' ); ?>"><?php echo wpoptimizing_icon( 'no-alt' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes. ?></button>
				</div>
				<div id="wpopt-record-modal-body" class="wpopt-doc-modal-body wpopt-lqp-body"></div>
			</div>
		</div>
		<div id="wpopt-content-modal" class="wpopt-content-modal" role="dialog" aria-modal="true" aria-hidden="true">
			<div class="wpopt-content-modal-box">
				<div class="wpopt-content-modal-head">
					<strong class="wpopt-content-modal-title"><?php esc_html_e( 'Full content', 'wpoptimizing' ); ?></strong>
					<button type="button" id="wpopt-content-modal-close" class="button button-small"><?php esc_html_e( 'Close', 'wpoptimizing' ); ?></button>
				</div>
				<pre id="wpopt-content-modal-pre" class="wpopt-content-modal-pre" tabindex="0"></pre>
			</div>
		</div>
		<?php ob_start(); ?>
		(function(){
			var ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			var nonce   = <?php echo wp_json_encode( $nonce ); ?>;
			var modal   = document.getElementById('wpopt-record-modal');
			var title   = document.getElementById('wpopt-record-modal-title');
			var body    = document.getElementById('wpopt-record-modal-body');
			var box     = modal ? modal.querySelector('.wpopt-doc-modal-box') : null;
			var lastTrigger = null;
			var fullCells = [];
			var seq = 0;
			if (!modal) { return; }

			// Escapes for ANY position, attributes included (quotes too).
			function esc(s){
				if (s === null || typeof s === 'undefined') { return ''; }
				return String(s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#039;');
			}

			function openRecord( btn ) {
				var type = btn.dataset.type, value = btn.dataset.value;
				lastTrigger = btn;
				title.textContent = value;
				body.innerHTML = '<span class="spinner is-active wpopt-spinner-inline"></span>';
				modal.hidden = false;
				document.body.classList.add('wpopt-modal-open');
				(box || modal).focus();

				var mine = ++seq; // A slow answer for a record already closed must not land in the next one.
				var data = new URLSearchParams();
				data.set('action', 'wpoptimizing_live_query');
				data.set('nonce', nonce);
				data.set('type', type);
				data.set('value', value);
				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
					body: data.toString()
				})
				.then(function(r){ return r.json(); })
				.then(function(r){
					if (mine !== seq) { return; }
					if (!r || !r.success) {
						body.innerHTML = '<p class="wpopt-error-msg">' + (r && r.data && r.data.message ? esc(r.data.message) : <?php echo wp_json_encode( __( 'Query failed.', 'wpoptimizing' ) ); ?>) + '</p>';
						return;
					}
					var d = r.data;
					title.textContent = d.title || value;
					if (!d.rows || !d.rows.length) {
						body.innerHTML = '<p class="wpopt-desc-flush"><em>' + esc(<?php echo wp_json_encode( __( 'No rows found.', 'wpoptimizing' ) ); ?>) + '</em></p>';
						return;
					}
					fullCells = [];
					var html = '<table class="widefat striped wpopt-lqp-table"><thead><tr>';
					d.columns.forEach(function(c){ html += '<th scope="col">' + esc(c) + '</th>'; });
					html += '</tr></thead><tbody>';
					d.rows.forEach(function(row){
						html += '<tr>';
						d.columns.forEach(function(c){
							var v = String(row[c] ?? '');
							if ( v.length > 80 ) {
								var idx = fullCells.push( v ) - 1;
								html += '<td class="wpopt-cell-clip" data-full-idx="' + Number( idx ) + '" role="button" tabindex="0" title="' + esc(<?php echo wp_json_encode( __( 'Click to view the full content', 'wpoptimizing' ) ); ?>) + '">' + esc( v.slice( 0, 80 ) ) + '…</td>';
							} else {
								html += '<td>' + esc( v ) + '</td>';
							}
						});
						html += '</tr>';
					});
					html += '</tbody></table>';
					if (d.rows.length >= 50) {
						html += '<p class="wpopt-lqp-note">' + esc(<?php echo wp_json_encode( __( 'Showing first 50 rows.', 'wpoptimizing' ) ); ?>) + '</p>';
					}
					body.innerHTML = html;
				})
				.catch(function(){
					if (mine !== seq) { return; }
					body.innerHTML = '<p class="wpopt-error-msg">' + esc(<?php echo wp_json_encode( __( 'Request failed.', 'wpoptimizing' ) ); ?>) + '</p>';
				});
			}
			function closeRecord() {
				seq++;
				modal.hidden = true;
				document.body.classList.remove('wpopt-modal-open');
				if (lastTrigger) { lastTrigger.focus(); lastTrigger = null; }
			}

			function contentOpen(){ var m = document.getElementById('wpopt-content-modal'); return m && m.classList.contains('is-open'); }
			function openContentModal( text ){
				var m = document.getElementById('wpopt-content-modal');
				var pre = document.getElementById('wpopt-content-modal-pre');
				if (!m || !pre) { return; }
				pre.textContent = text;
				m.classList.add('is-open');
				m.setAttribute('aria-hidden','false');
				pre.focus();
			}
			function closeContentModal(){
				var m = document.getElementById('wpopt-content-modal');
				if (!m) { return; }
				m.classList.remove('is-open');
				m.setAttribute('aria-hidden','true');
			}
			function clipText( el ){
				var i = parseInt(el.getAttribute('data-full-idx'), 10);
				return (i >= 0 && fullCells[i] !== undefined) ? fullCells[i] : el.textContent;
			}

			// A value cut by the shared cell width (admin.css, --wpopt-cell-max) shows whole on
			// hover. Only the cut ones: a title on every cell would just repeat what is visible.
			function titleClipped(){
				var sel = '.wpopt-table-scroll td code, .wpopt-table-scroll td .wpopt-code-sm, .wpopt-table-scroll td .wpopt-key, .wpopt-table-scroll td .wpopt-lq-trigger';
				Array.prototype.forEach.call(document.querySelectorAll(sel), function(el){
					if (el.scrollWidth > el.clientWidth + 1) {
						var full = (el.getAttribute('data-value') || el.textContent || '').trim();
						if (full) { el.setAttribute('title', full); }
					}
				});
			}
			if ('loading' === document.readyState) { document.addEventListener('DOMContentLoaded', titleClipped); } else { titleClipped(); }


			document.addEventListener('click', function(e){
				var btn = e.target.closest('.wpopt-lq-trigger');
				if (btn) {
					e.preventDefault();
					openRecord(btn);
					return;
				}
				var clip = e.target.closest('.wpopt-cell-clip');
				if (clip) { openContentModal( clipText( clip ) ); return; }
				if (e.target.id === 'wpopt-content-modal-close' || e.target.id === 'wpopt-content-modal') { closeContentModal(); return; }
				if (e.target.closest('.wpopt-record-modal-close') || (e.target.closest('.wpopt-doc-modal-backdrop') && e.target.closest('#wpopt-record-modal'))) { closeRecord(); }
			});
			document.addEventListener('keydown', function(e){
				if ('Enter' === e.key && e.target && e.target.classList && e.target.classList.contains('wpopt-cell-clip')) {
					openContentModal( clipText( e.target ) );
				} else if ('Escape' === e.key) {
					// The one on top closes first.
					if (contentOpen()) { closeContentModal(); } else if (!modal.hidden) { closeRecord(); }
				}
			});
		})();
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * The test-mode switch, identical wherever a front-end change can be tried first (minify,
	 * delay JavaScript, Smart Assets): same control, same words, same rule — the change applies
	 * only to logged-in administrators until it is switched off (wpoptimizing_test_mode_blocks()).
	 *
	 * @param string $input_attrs Extra attributes for the checkbox (name/value, or data-* for AJAX). Pre-escaped by the caller.
	 * @param bool   $checked     Current state.
	 * @param string $extra_class Extra class for the checkbox.
	 * @return void
	 */
	protected function render_test_mode_toggle( $input_attrs, $checked, $extra_class = '' ) {
		echo '<div class="wpopt-test-mode">';
		echo '<label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input' . ( '' !== $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '" ' . $input_attrs . checked( (bool) $checked, true, false ) . ' />' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attributes built and escaped by the caller.
			. '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>'
			. '<span class="wpopt-toggle-label">' . esc_html__( 'Test mode: apply only for logged-in administrators', 'wpoptimizing' ) . '</span></label>';
		echo '<p class="description">' . esc_html__( 'While test mode is on, only you (a logged-in user who can manage WPOptimizing) get the change; visitors get the page as before. Check your key pages, then switch test mode off to apply it to everyone.', 'wpoptimizing' ) . '</p>';
		echo '</div>';
	}

	/**
	 * ONE way to save, everywhere. Every settings form on the plugin's screens (Settings,
	 * Cache, Cloudflare, page-cache exclusions, CSS & JavaScript, Loading & delivery, the
	 * PageSpeed key) marks its save button with .wpopt-save-btn, and this script gives them
	 * all the same behaviour: a change marks the button and shows "Unsaved changes", and
	 * leaving the page with changes pending asks first. Only Settings did this before; the
	 * other forms lost changes silently on navigation (UX audit, 15/10/2026).
	 *
	 * A button may carry data-label / data-label-dirty to swap its own text (Settings does);
	 * otherwise a note is shown beside it. Buttons outside their form (form="…") count too.
	 *
	 * @return void
	 */
	public function render_save_forms_js() {
		$note = __( 'Unsaved changes', 'wpoptimizing' );
		?>
		<?php ob_start(); ?>
		(function(){
			var NOTE = <?php echo wp_json_encode( $note ); ?>;
			var forms = [], any = function(){ return forms.some(function(s){ return s.dirty && !s.sending; }); };
			Array.prototype.forEach.call(document.querySelectorAll('.wpopt-save-btn'), function(b){
				var f = b.form;
				if (!f) { return; }
				var st = forms.filter(function(s){ return s.form === f; })[0];
				if (!st) { st = { form: f, dirty: false, sending: false, btns: [] }; forms.push(st); }
				st.btns.push(b);
			});
			forms.forEach(function(st){
				st.btns.forEach(function(b){
					if (b.hasAttribute('data-label-dirty')) { return; }
					var n = document.createElement('span');
					n.className = 'wpopt-unsaved-note';
					n.setAttribute('role', 'status');
					n.hidden = true;
					n.textContent = NOTE;
					b.insertAdjacentElement('afterend', n);
					b._note = n;
				});
				function mark(on){
					st.dirty = on;
					st.btns.forEach(function(b){
						b.classList.toggle('is-dirty', on);
						if (b.hasAttribute('data-label-dirty')) { b.textContent = on ? b.getAttribute('data-label-dirty') : b.getAttribute('data-label'); }
						if (b._note) { b._note.hidden = !on; }
					});
				}
				function changed(e){
					var t = e.target;
					if (!t || !t.name || 'hidden' === t.type || t.closest('.search-box')) { return; }
					if (!st.dirty) { mark(true); }
				}
				st.form.addEventListener('input', changed);
				st.form.addEventListener('change', changed);
				st.form.addEventListener('submit', function(){ st.sending = true; });
			});
			window.addEventListener('beforeunload', function(e){
				if (any()) { e.preventDefault(); e.returnValue = ''; }
			});
		})();
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * Render POST form for uninstalling inactive plugins/themes.
	 *
	 * @param array  $plugins Plugin slugs.
	 * @param array  $themes  Theme slugs.
	 * @param string $tab     Current tab key.
	 * @param string $isec    Current sub-section key.
	 * @return void
	 */
	protected function render_uninstall_form( array $plugins, array $themes, $tab, $isec ) {
		$plugin_names = $this->get_all_plugins_by_slug();
		$theme_names  = $this->get_all_themes_by_slug();

		$rows = array();
		foreach ( $plugins as $slug ) {
			$rows[] = array(
				'kind' => 'plugin',
				'type' => __( 'Plugin', 'wpoptimizing' ),
				'name' => $plugin_names[ $slug ] ?? $slug,
				'slug' => $slug,
			);
		}
		foreach ( $themes as $slug ) {
			$rows[] = array(
				'kind' => 'theme',
				'type' => __( 'Theme', 'wpoptimizing' ),
				'name' => $theme_names[ $slug ] ?? $slug,
				'slug' => $slug,
			);
		}

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No related inactive extensions.', 'wpoptimizing' ) . '</em></p>';
			return;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$pp = isset( $_REQUEST['proj_pp'] ) ? (int) $_REQUEST['proj_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
			$pp = 50;
		}

		$type_filter = '';
		if ( isset( $_REQUEST['proj_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
			$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['proj_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
			if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
				$type_filter = '';
			}
		}
		$type_choices = $this->collect_types( 'proj', $rows );
		if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
			$type_filter = '';
		}

		$q = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		if ( '' !== $type_filter || '' !== $q ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $type_filter, $q ) {
						if ( '' !== $type_filter && $row['type'] !== $type_filter ) {
							return false;
						}
						if ( '' !== $q ) {
							$haystack = strtolower( (string) $row['name'] . ' ' . (string) $row['slug'] );
							return false !== strpos( $haystack, strtolower( $q ) );
						}
						return true;
					}
				)
			);
		}

		$table = new WPOptimizing_Projects_List_Table(
			$this,
			$rows,
			false,
			false,
			array(
				'scope'         => 'proj',
				'per_page'      => $pp,
				'type_filter'   => $type_filter,
				'type_choices'  => $type_choices,
				'hidden_fields' => array(
					'tab'  => 'inactive',
					'isec' => 'proj',
				),
			)
		);
		$table->prepare_items();

		$this->render_table_form( $table, $tab, $isec, __( 'Uninstall selected', 'wpoptimizing' ), true );
		$uninstall_note = __( 'This plugin uninstalls one extension at a time: if you tick several, only the first is uninstalled. Pro uninstalls every one you select.', 'wpoptimizing' );
		if ( '' !== $uninstall_note ) {
			echo '<p class="description">' . esc_html( $uninstall_note ) . '</p>';
		}
	}

	/**
	 * Render POST form for deactivating active plugins/themes.
	 *
	 * @param array  $plugins Plugin slugs.
	 * @param array  $themes  Theme slugs.
	 * @param string $tab     Current tab key.
	 * @param string $asec    Current sub-section key.
	 * @return void
	 */
	protected function render_deactivate_form( array $plugins, array $themes, $tab, $asec ) {
		$plugin_names = $this->get_all_plugins_by_slug();
		$theme_names  = $this->get_all_themes_by_slug();

		$rows = array();
		foreach ( $plugins as $slug ) {
			$rows[] = array(
				'kind' => 'plugin',
				'type' => __( 'Plugin', 'wpoptimizing' ),
				'name' => $plugin_names[ $slug ] ?? $slug,
				'slug' => $slug,
			);
		}
		foreach ( $themes as $slug ) {
			$rows[] = array(
				'kind' => 'theme',
				'type' => __( 'Theme', 'wpoptimizing' ),
				'name' => $theme_names[ $slug ] ?? $slug,
				'slug' => $slug,
			);
		}

		if ( empty( $rows ) ) {
			echo '<p><em>' . esc_html__( 'No related active extensions.', 'wpoptimizing' ) . '</em></p>';
			return;
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return strcasecmp( (string) $a['name'], (string) $b['name'] );
			}
		);

		$pp = isset( $_REQUEST['aproj_pp'] ) ? (int) $_REQUEST['aproj_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
			$pp = 50;
		}

		$type_filter = '';
		if ( isset( $_REQUEST['aproj_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
			$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['aproj_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
			if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
				$type_filter = '';
			}
		}
		$type_choices = $this->collect_types( 'aproj', $rows );
		if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
			$type_filter = '';
		}

		$q = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filters.
		if ( '' !== $type_filter || '' !== $q ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $type_filter, $q ) {
						if ( '' !== $type_filter && $row['type'] !== $type_filter ) {
							return false;
						}
						if ( '' !== $q ) {
							$haystack = strtolower( (string) $row['name'] . ' ' . (string) $row['slug'] );
							return false !== strpos( $haystack, strtolower( $q ) );
						}
						return true;
					}
				)
			);
		}

		$table = new WPOptimizing_Projects_List_Table(
			$this,
			$rows,
			false,
			false,
			array(
				'scope'         => 'aproj',
				'per_page'      => $pp,
				'type_filter'   => $type_filter,
				'type_choices'  => $type_choices,
				'action_mode'   => 'deactivate',
				'hidden_fields' => array(
					'tab'  => 'active',
					'asec' => 'proj',
				),
			)
		);
		$table->prepare_items();
		$this->render_table_form( $table, $tab, $asec, __( 'Deactivate selected', 'wpoptimizing' ) );
	}

	/**
	 * Run one async delete/clean batch for the given scope. Public entry point
	 * for the bulk AJAX handler. License/capability/rate gating is enforced by
	 * the caller (wpoptimizing_ajax_bulk_delete). Reuses the same per-scope
	 * handlers as the synchronous form submit, so behaviour is identical.
	 *
	 * @param string   $scope One of db|fs|autoload|media_dupes.
	 * @param string[] $ids   Up to ~50 checkbox values for this batch.
	 * @param bool     $final    Whether this is the final batch of the job.
	 * @param string   $op_token Client job token (shared across the batches of one
	 *                           "process all" job) so they back up into one entry.
	 * @return int Number of identifiers processed in this batch.
	 */
	public function run_bulk_batch( $scope, array $ids, $final = false, $op_token = '' ) {
		/*
		 * The AJAX endpoint checks the capability, the nonce and the rate limit before it gets
		 * here, and this stays anyway. It is a PUBLIC method that deletes database records,
		 * files, attachments and cron events — the most destructive entry point in the plugin —
		 * and "the caller checks" is the assumption every boundary gap in this audit was built
		 * on. A second check costs one capability lookup on an operation that is about to run
		 * fifty deletions.
		 */
		if ( ! current_user_can( $this->cap ) ) {
			return 0;
		}

		if ( '' !== $op_token ) {
			$this->backup_op_key = $this->new_backup_op_key( $op_token );
		}
		// Opt-in for destructive deletions also honoured in the async batch path
		// (the AJAX handler verified the bulk nonce before calling us).
		$this->confirm_destructive = ! empty( $_POST['wpopt_destructive'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$ids                       = array_values(
			array_filter(
				array_map( 'strval', $ids ),
				static function ( $s ) {
					return '' !== $s;
				}
			)
		);
		if ( empty( $ids ) ) {
			return 0;
		}
		// Lite deletes attributed orphans one at a time (see render_bulk_controls()).
		$one_by_one = in_array( $scope, array( 'db', 'fs' ), true );
		if ( $one_by_one ) {
			$ids = array_slice( $ids, 0, 1 );
		}

		switch ( $scope ) {
			case 'db':
				$this->delete_bd_items( $ids );
				break;
			case 'empty':
				$this->delete_empty_db_items( $ids );
				break;
			case 'fs':
				$this->delete_fs_paths( $ids );
				// Keep the Media tab in sync: drop deleted media orphans from the
				// cached report in place (no full re-scan).
				$this->prune_media_report_files( $ids );
				break;
			case 'autoload':
				if ( function_exists( 'wpoptimizing_autoload_disable' ) ) {
					$n = wpoptimizing_autoload_disable( $ids, $this->backup_op_key );
					if ( function_exists( 'wpoptimizing_backup_flash_failure' ) ) {
						wpoptimizing_backup_flash_failure(); // No backup, no change: say why.
					}
					if ( function_exists( 'wpoptimizing_audit_log' ) ) {
						wpoptimizing_audit_log(
							'autoload_disable',
							array(
								'count' => (int) $n,
								'items' => array_slice( $ids, 0, 50 ),
							)
						);
					}
				}
				break;
			case 'unused':
				// Potentially-unused attachments: delete (backup-first) in batches,
				// accumulating deleted/skipped counts so the final batch flashes once.
				$r   = $this->delete_unused_batch( array_map( 'intval', $ids ) );
				$acc = (array) get_transient( 'wpoptimizing_unused_acc' );
				foreach ( array( 'deleted', 'skipped', 'backed' ) as $k ) {
					$acc[ $k ] = (int) ( $acc[ $k ] ?? 0 ) + (int) ( $r[ $k ] ?? 0 );
				}
				set_transient( 'wpoptimizing_unused_acc', $acc, HOUR_IN_SECONDS );
				if ( $final ) {
					delete_transient( 'wpoptimizing_unused_acc' );
					$this->set_unused_flash( (int) $acc['deleted'], (int) $acc['skipped'] );
				}
				break;
			case 'trust_unused':
				// Mark attachments as known/trusted: add to the allow-list so the unused
				// heuristic stops flagging them, and drop them from the cached report in
				// place so they disappear from the list now (no re-scan needed).
				$trusted = array_map( 'intval', (array) get_option( 'wpoptimizing_trusted_attachments', array() ) );
				$add     = array_values( array_filter( array_map( 'intval', $ids ) ) );
				$trusted = array_values( array_unique( array_merge( $trusted, $add ) ) );
				update_option( 'wpoptimizing_trusted_attachments', $trusted, false );
				$this->prune_unused_report_ids( $add );
				$trust_acc = (int) get_transient( 'wpoptimizing_trust_acc' ) + count( $add );
				set_transient( 'wpoptimizing_trust_acc', $trust_acc, HOUR_IN_SECONDS );
				if ( $final ) {
					delete_transient( 'wpoptimizing_trust_acc' );
					if ( function_exists( 'wpoptimizing_audit_log' ) ) {
						wpoptimizing_audit_log( 'media_unused_trusted', array( 'count' => $trust_acc ) );
					}
					set_transient(
						'wpoptimizing_flash',
						array(
							'code' => 'wpoptimizing_trust',
							'msg'  => sprintf(
								/* translators: %d: number of attachments marked as known. */
								_n( '%d attachment marked as known; it will no longer appear as unused.', '%d attachments marked as known; they will no longer appear as unused.', $trust_acc, 'wpoptimizing' ),
								$trust_acc
							),
							'type' => 'success',
						),
						30
					);
				}
				break;
			case 'cron':
				// Unschedule orphaned cron events (hook without callback); accumulate
				// across batches and flash once on the final batch.
				$cron_acc = (int) get_transient( 'wpoptimizing_cron_acc' ) + (int) $this->delete_cron_events( $ids );
				set_transient( 'wpoptimizing_cron_acc', $cron_acc, HOUR_IN_SECONDS );
				$cron_prev = get_transient( 'wpoptimizing_flash' );
				if ( $final && 0 === $cron_acc && is_array( $cron_prev ) && 'error' === ( $cron_prev['type'] ?? '' ) ) {
					// Nothing went because the backup could not be written: keep that notice.
					delete_transient( 'wpoptimizing_cron_acc' );
				} elseif ( $final ) {
					delete_transient( 'wpoptimizing_cron_acc' );
					set_transient(
						'wpoptimizing_flash',
						array(
							'code' => 'wpoptimizing_cron',
							'msg'  => sprintf(
								/* translators: %d: number of unscheduled events. */
								_n( '%d orphaned scheduled event unscheduled.', '%d orphaned scheduled events unscheduled.', $cron_acc, 'wpoptimizing' ),
								$cron_acc
							),
							'type' => 'updated',
						),
						30
					);
				}
				break;
			case 'optimize_tables':
				// OPTIMIZE selected tables (reclaims overhead; never deletes data).
				$opt_acc = (int) get_transient( 'wpoptimizing_optimize_acc' ) + (int) $this->optimize_table_list( $ids );
				set_transient( 'wpoptimizing_optimize_acc', $opt_acc, HOUR_IN_SECONDS );
				if ( $final ) {
					delete_transient( 'wpoptimizing_optimize_acc' );
					$opt_err = (array) get_transient( 'wpoptimizing_optimize_err' );
					delete_transient( 'wpoptimizing_optimize_err' );
					$this->set_table_op_flash(
						'wpoptimizing_optimize',
						sprintf(
							/* translators: %d: number of tables optimized. */
							_n( '%d table optimized.', '%d tables optimized.', $opt_acc, 'wpoptimizing' ),
							$opt_acc
						),
						$opt_err
					);
				}
				break;
			case 'convert_innodb':
				// Convert selected MyISAM tables to InnoDB (rebuilds in place; never
				// deletes data). The engine is re-validated per table server-side.
				$conv_acc = (int) get_transient( 'wpoptimizing_convert_acc' ) + (int) $this->convert_tables_to_innodb( $ids );
				set_transient( 'wpoptimizing_convert_acc', $conv_acc, HOUR_IN_SECONDS );
				if ( $final ) {
					delete_transient( 'wpoptimizing_convert_acc' );
					$conv_err = (array) get_transient( 'wpoptimizing_convert_err' );
					delete_transient( 'wpoptimizing_convert_err' );
					$this->set_table_op_flash(
						'wpoptimizing_convert',
						sprintf(
							/* translators: %d: number of tables converted to InnoDB. */
							_n( '%d table converted to InnoDB.', '%d tables converted to InnoDB.', $conv_acc, 'wpoptimizing' ),
							$conv_acc
						),
						$conv_err
					);
				}
				break;
			default:
				return 0;
		}

		if ( in_array( $scope, array( 'db', 'empty', 'fs', 'autoload' ), true ) ) {
			$this->accumulate_bulk_job( $scope, $op_token, $ids );
			if ( $final ) {
				$this->finish_bulk_job( $scope, $op_token, false );
			}
		}

		// Record the result once the operation completes, linked to its op-key.
		if ( $final ) {
			$this->record_cleanup_snapshot();
		}

		return count( $ids );
	}

	/**
	 * Add one batch to its job's running totals.
	 *
	 * @param string   $scope    Bulk scope.
	 * @param string   $op_token Job token.
	 * @param string[] $ids      This batch.
	 * @return void
	 */
	protected function accumulate_bulk_job( $scope, $op_token, array $ids ) {
		$key               = 'wpoptimizing_bulkjob_' . md5( (string) $op_token );
		$acc               = get_transient( $key );
		$acc               = is_array( $acc ) ? $acc : array(
			'scope'     => $scope,
			'processed' => 0,
			'deleted'   => 0,
			'kept'      => 0,
			'measured'  => false,
			'ids'       => array(),
		);
		$acc['processed'] += count( $ids );
		if ( is_array( $this->last_bulk_counts ) ) {
			$acc['deleted']        += (int) $this->last_bulk_counts['deleted'];
			$acc['kept']           += (int) $this->last_bulk_counts['kept'];
			$acc['measured']        = true;
			$this->last_bulk_counts = null;
		}
		// Autoload is undone by name: keep the names (bounded) to switch back on exactly these.
		if ( 'autoload' === $scope && count( $acc['ids'] ) < 20000 ) {
			$acc['ids'] = array_merge( $acc['ids'], $ids );
		}
		set_transient( $key, $acc, DAY_IN_SECONDS );
	}

	/**
	 * Close a bulk job: one summary of the whole job, with an Undo when there is something to
	 * undo. Bulk actions used to reload the page with no word on what happened — or with the
	 * notice of the LAST batch only — and the way back was to find the right copy in Backups.
	 * Also called when a batch fails mid-way, so a half-done job still says what it did.
	 *
	 * @param string $scope    Bulk scope.
	 * @param string $op_token Job token.
	 * @param bool   $aborted  The job stopped on a failed batch.
	 * @return void
	 */
	public function finish_bulk_job( $scope, $op_token, $aborted ) {
		if ( ! current_user_can( $this->cap ) ) {
			return;
		}
		$key = 'wpoptimizing_bulkjob_' . md5( (string) $op_token );
		$acc = get_transient( $key );
		delete_transient( $key );
		if ( ! is_array( $acc ) || $acc['processed'] < 1 ) {
			if ( $aborted ) {
				$tracked = in_array( $scope, array( 'db', 'empty', 'fs', 'autoload' ), true );
				set_transient(
					'wpoptimizing_flash',
					array(
						'code' => 'wpoptimizing_bulk',
						'msg'  => $tracked
							? __( 'The action stopped before changing anything. Please try again.', 'wpoptimizing' )
							: __( 'The action stopped part-way because a batch failed. Part of it may have been applied: check the list, and run it again for the rest.', 'wpoptimizing' ),
						'type' => 'error',
					),
					60
				);
			}
			return;
		}
		// The last batch may have left the reason some records stayed (they need the "also
		// delete content" box, or are inventory only): keep it in the summary.
		$prev   = get_transient( 'wpoptimizing_flash' );
		$reason = ( is_array( $prev ) && 'wpoptimizing_delete' === ( $prev['code'] ?? '' ) ) ? (string) $prev['msg'] : '';

		// What can be put back: the job's backup (database and files), or the autoload flags.
		$undo = array();
		if ( 'autoload' === $acc['scope'] && ! empty( $acc['ids'] ) ) {
			$undo = array(
				'kind' => 'autoload',
				'ids'  => array_values( array_unique( $acc['ids'] ) ),
			);
		} elseif ( in_array( $acc['scope'], array( 'db', 'empty', 'fs' ), true ) ) {
			$op = $this->new_backup_op_key( (string) $op_token );
			if ( ! empty( $this->operation_components( $op ) ) ) {
				$undo = array(
					'kind' => 'backup',
					'id'   => 'op:' . $op,
				);
			}
		}
		$undo_id = '';
		if ( ! empty( $undo ) ) {
			$undo_id = substr( md5( (string) $op_token . wp_rand() ), 0, 16 );
			set_transient( 'wpoptimizing_undo_' . $undo_id, $undo, DAY_IN_SECONDS );
		}

		if ( 'autoload' === $acc['scope'] ) {
			/* translators: %s: number of options. */
			$msg = sprintf( _n( 'Autoload turned off for %s option.', 'Autoload turned off for %s options.', $acc['processed'], 'wpoptimizing' ), number_format_i18n( $acc['processed'] ) );
		} elseif ( $acc['measured'] ) {
			/* translators: %s: number of records. */
			$msg = sprintf( _n( '%s record deleted.', '%s records deleted.', $acc['deleted'], 'wpoptimizing' ), number_format_i18n( $acc['deleted'] ) );
			if ( $acc['kept'] > 0 ) {
				/* translators: %s: number of records. */
				$msg .= ' ' . sprintf( _n( '%s could not be removed and stays in the list.', '%s could not be removed and stay in the list.', $acc['kept'], 'wpoptimizing' ), number_format_i18n( $acc['kept'] ) );
				if ( '' !== $reason ) {
					$msg .= ' ' . $reason;
				}
			}
		} else {
			/* translators: %s: number of items. */
			$msg = sprintf( _n( '%s item processed.', '%s items processed.', $acc['processed'], 'wpoptimizing' ), number_format_i18n( $acc['processed'] ) );
		}
		if ( $aborted ) {
			$msg = __( 'The action stopped part-way because a batch failed.', 'wpoptimizing' ) . ' ' . $msg . ' ' . __( 'The rest was not touched; you can run it again.', 'wpoptimizing' );
		}
		if ( '' !== $undo_id ) {
			$msg .= ' ' . ( 'autoload' === $acc['scope']
				? __( 'You can undo it below, or from the Autoload tab at any time.', 'wpoptimizing' )
				: __( 'A backup was made first: you can undo it below, or from Backups at any time.', 'wpoptimizing' ) );
		}
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_bulk',
				'msg'  => $msg,
				'type' => $aborted || $acc['kept'] > 0 ? 'warning' : 'updated',
				'undo' => $undo_id,
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Undo a finished bulk job from its summary notice.
	 *
	 * @param string $undo_id Undo record id.
	 * @return void
	 */
	protected function undo_bulk_job( $undo_id ) {
		if ( ! current_user_can( $this->cap ) || '' === $undo_id ) {
			return;
		}
		$undo = get_transient( 'wpoptimizing_undo_' . $undo_id );
		delete_transient( 'wpoptimizing_undo_' . $undo_id ); // One use: a second click must not restore twice.
		if ( ! is_array( $undo ) ) {
			set_transient(
				'wpoptimizing_flash',
				array(
					'code' => 'wpoptimizing_undo',
					'msg'  => __( 'That action can no longer be undone from here. Its backup, if any, is still in Backups.', 'wpoptimizing' ),
					'type' => 'warning',
				),
				60
			);
			return;
		}
		if ( 'autoload' === $undo['kind'] && function_exists( 'wpoptimizing_autoload_enable' ) ) {
			$n = (int) wpoptimizing_autoload_enable( (array) $undo['ids'] );
			/* translators: %s: number of options. */
			$msg = sprintf( _n( 'Undone: autoload turned back on for %s option.', 'Undone: autoload turned back on for %s options.', $n, 'wpoptimizing' ), number_format_i18n( $n ) );
		} elseif ( 'backup' === $undo['kind'] ) {
			$this->restore_backup( (string) $undo['id'] );
			$msg = __( 'Undone: what that action deleted was restored from its backup. Run a new analysis to refresh the lists.', 'wpoptimizing' );
		} else {
			return;
		}
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_undo',
				'msg'  => $msg,
				'type' => 'updated',
			),
			60
		);
	}


	/*
	=========================
	 * CSV reading and formatting
	 * ========================= */

	/** @var array|null Remote DB map cache. */
	protected $remote_db_map = null;

	/** @var array|null Remote DB value-only map cache. */
	protected $remote_db_value_map = null;

	/** @var array<string,int>|null Crowd confidence (distinct contributing sites) per remote DB key. */
	protected $remote_db_confidence = null;

	/** @var array|null Remote Files value-only map cache. */
	protected $remote_files_value_map = null;

	/** @var array<string,array<string,bool>>|null Remote slug index with origins. */
	protected $remote_slug_index = null;

	/** @var array<string,string>|null Remote slug => name map. */
	protected $remote_slug_name_map = null;

	/** @var array|null Remote FS map cache. */
	protected $remote_fs_map = null;

	/*
	=========================
	 * Actions (delete/uninstall)
	 * ========================= */









	/*
	=========================
	 * Backups
	 * ========================= */
}
