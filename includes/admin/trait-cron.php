<?php
/**
 * "Cron jobs" tab: lists WordPress scheduled events, flags the orphaned ones
 * (a hook with no registered callback — typically left behind by an uninstalled
 * plugin, so it never runs) and lets you unschedule them. Listing is read-only;
 * only orphaned events are selectable for removal. WordPress core hooks are never
 * flagged or removable.
 *
 * Composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Cron_Trait {

	/**
	 * WordPress core scheduled hooks — never flagged as orphan nor removable.
	 *
	 * @return string[]
	 */
	protected function core_cron_hooks() {
		// The same list the analysis uses, so this screen and Analysis results never disagree.
		// The last three were here before the list moved to the registry: kept, not re-checked.
		return array_merge( WPOPT_WP_Core_Registry::core_cron_hooks(), array( 'wp_cron', 'wp_scheduled_delete_attachment', 'wp_cleanup_personal_data_request' ) );
	}

	/**
	 * Does a scheduled hook belong to a plugin that is still installed?
	 *
	 * has_action() alone is not enough: many active plugins (MainWP Child, Complianz,
	 * LiteSpeed, …) register their cron callback only in a specific context — a MainWP
	 * request, the front-end, a REST call — so on this admin screen has_action() returns
	 * false even though the plugin is present and keeps re-creating the event. Flagging
	 * those as "orphan" is a false positive: the user removes them and the plugin schedules
	 * them again. We therefore also check the hook's namespace against the installed plugin
	 * slugs (dir names) plus a small alias map for plugins whose hook prefix differs from
	 * their slug. The bias is deliberately conservative: when in doubt, NOT an orphan.
	 *
	 * @param string               $hook            Hook name.
	 * @param array<string,string> $installed_slugs Map slug => Name of installed plugins.
	 * @return bool
	 */
	protected function cron_hook_has_installed_owner( $hook, array $installed_slugs ) {
		$hook = strtolower( trim( (string) $hook ) );
		if ( '' === $hook || empty( $installed_slugs ) ) {
			return false;
		}
		// Normalise hook + slug to the same separator so "mainwp_child_*" matches the
		// "mainwp-child" directory slug.
		$hook_norm = str_replace( '_', '-', $hook );
		foreach ( array_keys( $installed_slugs ) as $slug ) {
			$slug = strtolower( str_replace( '_', '-', (string) $slug ) );
			if ( strlen( $slug ) < 4 ) {
				continue; // Too generic to match safely.
			}
			// Hook starts with the whole slug ("mainwp-child-cron-…" ⊃ "mainwp-child"),
			// or the slug starts with the hook's first token ("litespeed-cache" ⊃ "litespeed").
			if ( 0 === strpos( $hook_norm, $slug ) ) {
				return true;
			}
			$hook_first = explode( '-', $hook_norm, 2 )[0];
			$slug_first = explode( '-', $slug, 2 )[0];
			if ( strlen( $hook_first ) >= 4 && $hook_first === $slug_first ) {
				return true;
			}
		}
		// Plugins whose cron-hook prefix does not resemble their directory slug.
		$alias      = array(
			'cmplz'             => 'complianz',
			'rocket'            => 'wp-rocket',
			'wpforms'           => 'wpforms',
			'aioseo'            => 'all-in-one-seo',
			'wpseo'             => 'wordpress-seo',
			'gravityforms'      => 'gravityforms',
			'sumosubscriptions' => 'sumosubscriptions',
		);
		$hook_first = explode( '-', $hook_norm, 2 )[0];
		if ( isset( $alias[ $hook_first ] ) ) {
			$want = $alias[ $hook_first ];
			foreach ( array_keys( $installed_slugs ) as $slug ) {
				$slug = strtolower( str_replace( '_', '-', (string) $slug ) );
				if ( 0 === strpos( $slug, $want ) || 0 === strpos( $want, $slug ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Collect all scheduled cron events as flat rows ({hook,timestamp,schedule,
	 * args,orphan,core,id}). Orphans (hook without callback) are listed first.
	 *
	 * @return array<int,array>
	 */
	protected function get_cron_events() {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		if ( ! is_array( $cron ) ) {
			return array();
		}
		$core      = $this->core_cron_hooks();
		$installed = method_exists( $this, 'get_all_plugins_by_slug' ) ? (array) $this->get_all_plugins_by_slug() : array();
		$rows      = array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue;
			}
			foreach ( $hooks as $hook => $events ) {
				if ( ! is_array( $events ) ) {
					continue;
				}
				$hook = (string) $hook;
				// Skip empty/blank hook names: corrupt cron entries that can't be
				// targeted by wp_unschedule_hook() and would show as a non-removable
				// "orphan". They never run anyway.
				if ( '' === trim( $hook ) ) {
					continue;
				}
				$is_core = in_array( $hook, $core, true );
				// A hook owned by an installed plugin is never an orphan, even when no
				// callback is registered on this particular admin request.
				$has_cb = has_action( $hook ) || $this->cron_hook_has_installed_owner( $hook, $installed );
				foreach ( $events as $data ) {
					$args   = ( isset( $data['args'] ) && is_array( $data['args'] ) ) ? $data['args'] : array();
					$rows[] = array(
						'hook'      => $hook,
						'timestamp' => (int) $timestamp,
						'schedule'  => ( ! empty( $data['schedule'] ) ) ? (string) $data['schedule'] : 'once',
						'args'      => $args,
						'orphan'    => ( ! $has_cb && ! $is_core ),
						'core'      => $is_core,
						// Identify the row by hook name (simple + transport-safe). An
						// orphaned hook is unscheduled wholesale via wp_unschedule_hook().
						'id'        => $hook,
					);
				}
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				if ( $a['orphan'] !== $b['orphan'] ) {
					return $a['orphan'] ? -1 : 1;
				}
				return $a['timestamp'] <=> $b['timestamp'];
			}
		);
		return $rows;
	}

	/**
	 * Unschedule the given cron events (by encoded id). Only orphaned, non-core
	 * hooks are removed — anything still backed by a callback or core is skipped.
	 *
	 * @param string[] $ids Encoded event ids.
	 * @return int Number of events removed.
	 */
	protected function delete_cron_events( array $ids ) {
		$core = $this->core_cron_hooks();
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();

		// Names of hooks that are actually scheduled right now (validates input).
		$scheduled = array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $hooks ) {
				if ( is_array( $hooks ) ) {
					foreach ( array_keys( $hooks ) as $h ) {
						$scheduled[ (string) $h ] = true;
					}
				}
			}
		}

		$installed = method_exists( $this, 'get_all_plugins_by_slug' ) ? (array) $this->get_all_plugins_by_slug() : array();
		$removed   = 0;
		$seen      = array();
		$eligible  = array();
		foreach ( $ids as $id ) {
			$hook = (string) $id;
			if ( '' === $hook || isset( $seen[ $hook ] ) ) {
				continue;
			}
			$seen[ $hook ] = true;
			// Safety: only a currently-scheduled, non-core hook with no callback AND no
			// installed-plugin owner (i.e. genuinely orphaned) may be removed. Unschedule
			// every event of it.
			if ( ! isset( $scheduled[ $hook ] ) || in_array( $hook, $core, true ) || has_action( $hook )
				|| $this->cron_hook_has_installed_owner( $hook, $installed ) ) {
				continue;
			}
			// The same add-on veto the Orphans delete honours, for the same item.
			if ( ! apply_filters( 'wpoptimizing_can_delete_item', true, 'cron', $hook, 'cron::' . $hook ) ) {
				continue;
			}
			$eligible[] = $hook;
		}

		// Backup-first, as every other delete: this path used to unschedule with no copy at all.
		// The events go into the operation's backup, each at its own timestamp, and the Backups
		// tab restores them from there.
		$bk = empty( $eligible ) ? array( 'ok' => true ) : $this->backup_db_items(
			array_map(
				static function ( $h ) {
					return 'cron::' . $h;
				},
				$eligible
			),
			'delete'
		);
		if ( empty( $bk['ok'] ) ) {
			if ( function_exists( 'wpoptimizing_audit_log' ) ) {
				wpoptimizing_audit_log( 'cron_unschedule_aborted_no_backup', array( 'count' => count( $eligible ) ) );
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
			return 0;
		}

		foreach ( $eligible as $hook ) {
			$n = wp_unschedule_hook( $hook );
			if ( ! empty( $n ) ) {
				$removed += (int) $n;
			}
		}
		if ( $removed > 0 && function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'cron_unschedule', array( 'count' => $removed ) );
		}
		return $removed;
	}

	/**
	 * Render the Cron jobs tab.
	 *
	 * @return void
	 */
	protected function render_cron_tab() {
		$can_delete = true;
		$rows         = $this->get_cron_events();
		$orphan_count = 0;
		foreach ( $rows as $r ) {
			$orphan_count += $r['orphan'] ? 1 : 0;
		}

		// Search filter (the shared base engine sorts/paginates; filtering rows is
		// the caller's job, same as the media tables).
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search.
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $needle ) {
						return false !== strpos( strtolower( (string) $r['hook'] ), $needle );
					}
				)
			);
		}

		$this->render_section_header(
			__( 'Cron jobs', 'wpoptimizing' ),
			__( 'WordPress scheduled tasks. An event whose hook has no registered callback is likely orphaned (left by an uninstalled plugin) and will never run — safe to remove. Active and WordPress-core events are protected.', 'wpoptimizing' ),
			array(
				'icon' => 'clock',
				'doc'  => 'wpopt-doc-cron',
			)
		);

		$this->render_wp_cron_trigger_panel();
		$this->render_cron_health_panel( $rows );

		if ( $orphan_count > 0 ) {
			echo '<div class="notice notice-warning inline wpopt-notice"><p>' . esc_html(
				sprintf(
				/* translators: %d: number of orphaned cron events. */
					_n( '%d scheduled event looks orphaned (its hook has no callback).', '%d scheduled events look orphaned (their hooks have no callback).', $orphan_count, 'wpoptimizing' ),
					$orphan_count
				)
			) . '</p></div>';
		}

		$pp    = isset( $_GET['cron_pp'] ) ? max( 25, (int) $_GET['cron_pp'] ) : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$table = new WPOptimizing_Cron_List_Table(
			$this,
			$rows,
			false,
			false,
			array(
				'scope'      => 'cron',
				'per_page'   => $pp,
				'can_delete' => $can_delete,
			)
		);
		$table->prepare_items();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="wpoptimizing" />';
		echo '<input type="hidden" name="tab" value="cron" />';
		$table->search_box( __( 'Search hooks', 'wpoptimizing' ), 'cron' );
		$table->display();
		$this->render_bulk_controls(
			$table,
			array(
				'selected_label'   => __( 'Unschedule selected', 'wpoptimizing' ),
				'all_label'        => __( 'Unschedule all', 'wpoptimizing' ),
				'description'      => __( 'Only orphaned events (a hook with no callback) can be removed; active and WordPress-core events are protected.', 'wpoptimizing' ),
				/* translators: %d: number of selected cron events. */
				'confirm_selected' => __( 'Unschedule the %d selected orphaned event(s)?', 'wpoptimizing' ),
				/* translators: %d: number of filtered cron events. */
				'confirm_all'      => __( 'Unschedule ALL %d filtered orphaned event(s)?', 'wpoptimizing' ),
			)
		);
		echo '</form>';
	}

	/**
	 * Health panel: are the scheduled tasks actually running?
	 *
	 * @param array $rows Rows from get_cron_events().
	 * @return void
	 */
	protected function render_cron_health_panel( array $rows ) {
		if ( ! function_exists( 'wpoptimizing_cron_health' ) ) {
			return;
		}
		$timestamps = array();
		foreach ( $rows as $r ) {
			if ( empty( $r['orphan'] ) ) {
				$timestamps[] = (int) $r['timestamp']; // An orphan never runs: it is not lateness.
			}
		}
		$health   = wpoptimizing_cron_health( $timestamps, time() );
		$disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$this->render_subsection_header(
			__( 'Are the tasks running?', 'wpoptimizing' ),
			__( 'An event that is long overdue means nothing is firing WP-Cron, and every task behind it — updates, emails, cleanups, scheduled posts — is silently not happening.', 'wpoptimizing' ),
			'heart'
		);
		if ( 'ok' === $health['level'] ) {
			echo '<p><span class="wpopt-status-badge wpopt-status-active">' . esc_html__( 'Running on time', 'wpoptimizing' ) . '</span> '
				. esc_html__( 'No scheduled event is more than 10 minutes late.', 'wpoptimizing' ) . '</p>';
			return;
		}
		$badge = 'bad' === $health['level'] ? 'wpopt-status-orphan' : 'wpopt-status-inactive';
		echo '<p><span class="wpopt-status-badge ' . esc_attr( $badge ) . '">' . esc_html__( 'Tasks overdue', 'wpoptimizing' ) . '</span> '
			. esc_html(
				sprintf(
					/* translators: 1: number of events, 2: human time such as "3 hours". */
					_n( '%1$d event is overdue; the oldest by %2$s.', '%1$d events are overdue; the oldest by %2$s.', (int) $health['overdue'], 'wpoptimizing' ),
					(int) $health['overdue'],
					human_time_diff( time() - (int) $health['oldest'], time() )
				)
			) . '</p>';
		echo '<p class="description">' . esc_html(
			$disabled
				? __( 'WP-Cron is disabled, so a server cron has to call wp-cron.php: check that the crontab line above is in place and running.', 'wpoptimizing' )
				: __( 'WP-Cron only runs when someone visits the site, and some hosts block the request it makes to itself. On a quiet site, switch to a server cron with the panel above.', 'wpoptimizing' )
		) . '</p>';
	}

	/**
	 * Link for a per-event action (run now, pause, resume), nonce included.
	 *
	 * The nonce is the link's own ('wpopt_cron_task'), never the action handler's: a link
	 * lands in the browser history and the server logs, and the handler's nonce there would
	 * be good for every destructive action on the screen.
	 *
	 * @param string $task 'run' | 'pause' | 'resume'.
	 * @param string $hook Hook name.
	 * @param array  $args Event arguments (run only).
	 * @return string
	 */
	public function cron_task_url( $task, $hook, array $args = array() ) {
		return add_query_arg(
			array(
				'page'              => 'wpoptimizing',
				'tab'               => 'cron',
				'wpopt_cron_task'   => $task,
				'wpopt_cron_hook'   => rawurlencode( (string) $hook ),
				'wpopt_cron_args'   => md5( maybe_serialize( $args ) ),
				'_wpopt_cron_nonce' => wp_create_nonce( 'wpopt_cron_task' ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Run, pause or resume one event from its row link. Sets the flash; the caller redirects.
	 *
	 * @return void
	 */
	protected function handle_cron_task() {
		$task = isset( $_REQUEST['wpopt_cron_task'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpopt_cron_task'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by the shared handler before this runs.
		$hook = isset( $_REQUEST['wpopt_cron_hook'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_REQUEST['wpopt_cron_hook'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified by the shared handler; sanitized after decoding.
		$key  = isset( $_REQUEST['wpopt_cron_args'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpopt_cron_args'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by the shared handler.

		// Only an event that is scheduled right now, and has a callback, can be acted on.
		$event = null;
		foreach ( $this->get_cron_events() as $r ) {
			if ( $r['hook'] === $hook && empty( $r['orphan'] ) && ( 'run' !== $task || md5( maybe_serialize( $r['args'] ) ) === $key ) ) {
				$event = $r;
				break;
			}
		}
		if ( null === $event ) {
			$this->cron_task_flash( __( 'That scheduled event no longer exists or has no callback.', 'wpoptimizing' ), 'error' );
			return;
		}
		if ( 'run' === $task ) {
			$ok = wpoptimizing_cron_run_now( $hook, (array) $event['args'] );
			$this->cron_task_flash(
				$ok
					/* translators: %s: hook name. */
					? sprintf( __( '%s will run in a moment, in the background.', 'wpoptimizing' ), $hook )
					/* translators: %s: hook name. */
					: sprintf( __( '%s could not be queued (it may be paused).', 'wpoptimizing' ), $hook ),
				$ok ? 'updated' : 'error'
			);
			if ( ! $ok && function_exists( 'wpoptimizing_backup_flash_failure' ) ) {
				wpoptimizing_backup_flash_failure(); // Not queued because its backup failed: say that instead.
			}
			return;
		}
		if ( ! empty( $event['core'] ) ) {
			// Update checks and the like keep a site patched: never pausable from here.
			$this->cron_task_flash( __( 'WordPress core tasks cannot be paused.', 'wpoptimizing' ), 'error' );
			return;
		}
		wpoptimizing_cron_set_paused( $hook, 'pause' === $task );
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'pause' === $task ? 'cron_pause' : 'cron_resume', array( 'hook' => $hook ) );
		}
		$this->cron_task_flash(
			'pause' === $task
				/* translators: %s: hook name. */
				? sprintf( __( '%s is paused: it stays scheduled but does nothing until you resume it.', 'wpoptimizing' ), $hook )
				/* translators: %s: hook name. */
				: sprintf( __( '%s resumed.', 'wpoptimizing' ), $hook ),
			'updated'
		);
	}

	/**
	 * Flash notice for the cron tab.
	 *
	 * @param string $msg  Message.
	 * @param string $type updated|error.
	 * @return void
	 */
	protected function cron_task_flash( $msg, $type ) {
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_cron_task',
				'msg'  => $msg,
				'type' => $type,
			),
			60
		);
	}

	/**
	 * WP-Cron trigger panel: shows whether WordPress fires cron on page loads and
	 * lets the admin switch it to a real server cron (edits wp-config.php through
	 * the managed, backup-first helper) with the exact crontab line to add.
	 */
	protected function render_wp_cron_trigger_panel() {
		if ( ! function_exists( 'wpoptimizing_wp_cron_status' ) ) {
			return;
		}
		$st    = wpoptimizing_wp_cron_status();
		$nonce = wp_create_nonce( $this->nonce_action );
		// Same gate as the handler: on multisite only a network administrator may edit
		// wp-config.php. Sub-site administrators still see the diagnosis and the crontab
		// snippet, but not a button that would only be refused.
		$can_edit = $this->can_manage_dropin();

		$this->render_subsection_header(
			__( 'WP-Cron trigger', 'wpoptimizing' ),
			__( 'By default WordPress runs scheduled tasks on visitor page loads: this adds latency to random requests and misses tasks on low-traffic sites. Disable WP-Cron and drive it from a real server cron instead for faster page loads and dependable scheduling.', 'wpoptimizing' ),
			'clock'
		);

		if ( $st['disabled'] ) {
			echo '<p><span class="wpopt-status-badge wpopt-status-active">' . esc_html__( 'WP-Cron disabled', 'wpoptimizing' ) . '</span> '
				. esc_html__( 'WordPress no longer fires cron on page loads.', 'wpoptimizing' ) . '</p>';
		} else {
			echo '<p><span class="wpopt-status-badge wpopt-status-inactive">' . esc_html__( 'Runs on page loads', 'wpoptimizing' ) . '</span> '
				. esc_html__( 'WP-Cron fires on visitor requests (the WordPress default).', 'wpoptimizing' ) . '</p>';
		}

		// Pre-escaped crontab reminder, reused across branches.
		$command_block = '<p class="description">' . esc_html__( 'Add this line to your server crontab so scheduled tasks keep running every 5 minutes:', 'wpoptimizing' ) . '</p>'
			. '<pre class="wpopt-snippet"><code>' . esc_html( $st['command'] ) . '</code></pre>';

		if ( $st['disabled'] ) {
			echo $command_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above.
			if ( $st['managed'] && $can_edit ) {
				echo '<form method="post" class="wpopt-btn-row">';
				echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
				echo '<input type="hidden" name="tab" value="cron" />';
				echo '<button type="submit" name="wpopt_wp_cron_apply" value="0" class="button">' . esc_html__( 'Re-enable WP-Cron', 'wpoptimizing' ) . '</button>';
				echo '</form>';
			} else {
				echo '<p class="description">' . esc_html__( 'DISABLE_WP_CRON is already defined elsewhere (wp-config.php or a must-use plugin), so there is nothing to change here.', 'wpoptimizing' ) . '</p>';
			}
			return;
		}

		if ( $st['writable'] && $can_edit ) {
			echo '<form method="post" class="wpopt-btn-row">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="tab" value="cron" />';
			echo '<button type="submit" name="wpopt_wp_cron_apply" value="1" class="button button-primary">' . esc_html__( 'Disable WP-Cron', 'wpoptimizing' ) . '</button>';
			echo '</form>';
			echo '<p class="description">' . esc_html__( 'This edits wp-config.php and keeps a protected backup copy of it in the plugin\'s data folder. After disabling, add the crontab line below.', 'wpoptimizing' ) . '</p>';
			echo $command_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above.
		} else {
			echo '<p class="description">' . esc_html__( 'wp-config.php is not writable. Add this line by hand, just after its opening <?php tag:', 'wpoptimizing' ) . '</p>';
			echo '<pre class="wpopt-snippet"><code>' . esc_html( "define( 'DISABLE_WP_CRON', true );" ) . '</code></pre>';
			echo $command_block; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above.
		}
	}
}
