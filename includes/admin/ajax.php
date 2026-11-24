<?php
/**
 * admin-ajax endpoints.
 *
 * Every handler here is reachable ONLY through wp-admin/admin-ajax.php, so this file is
 * loaded from wpoptimizing_load_engine() (admin / cron / WP-CLI) instead of on every
 * request: a front-end page load never needs to parse it. Each handler keeps its own
 * capability check plus check_ajax_referer() — the lazy load is a performance measure,
 * never a security boundary.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Persist the welcome notice dismissal from its core X button (AJAX), exactly like its own
 * Dismiss button does: the first-run flag goes, so the notice does not come back.
 *
 * @return void
 */
function wpoptimizing_dismiss_welcome_ajax() {
	if ( ! current_user_can( wpoptimizing_cap() )
		|| ! check_ajax_referer( 'wpopt_dismiss_welcome', 'nonce', false ) ) {
		wp_send_json_error();
	}
	delete_option( 'wpoptimizing_first_run' );
	wp_send_json_success();
}
add_action( 'wp_ajax_wpoptimizing_dismiss_welcome', 'wpoptimizing_dismiss_welcome_ajax' );


/**
 * AJAX: check scan status for auto-refresh.
 *
 * @return void
 */
function wpoptimizing_ajax_scan_status() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}

	check_ajax_referer( 'wpoptimizing_scan_status', 'nonce' );

	// This endpoint is only polled by the progress overlay, so a poll while a scan is in
	// progress IS proof the user is actively watching. Keep the "watching" flag fresh (its
	// own 5-minute TTL would otherwise expire mid-scan) so the pump drives the scan in the
	// foreground — including a background 'scheduled' one — instead of leaving it frozen on a
	// host whose cron/loopback never fires. The flag is cleared when the scan completes.
	if ( is_array( get_option( 'wpoptimizing_scan_state', null ) ) ) {
		set_transient( 'wpoptimizing_scan_pending_ui', 1, 3 * MINUTE_IN_SECONDS );
		// The since mark has to last as long as the flag it dates, or a scan longer than its
		// 5 minutes leaves a flag nobody can tell is already served.
		$wpopt_since = (int) get_transient( 'wpoptimizing_scan_pending_since' );
		if ( $wpopt_since > 0 ) {
			set_transient( 'wpoptimizing_scan_pending_since', $wpopt_since, 5 * MINUTE_IN_SECONDS );
		}
	}

	// Drive AND self-heal the scan from this poll. On hosts where WP-Cron / loopback
	// never fire (so the background per-stage worker never runs), a scan would otherwise
	// stall forever at the first stage: the pump advances it one stage per poll using the
	// request we know works — this very AJAX call.
	if ( function_exists( 'wpoptimizing_scan_pump' ) ) {
		wpoptimizing_scan_pump();
	}

	$last_ts    = (int) get_option( 'wpoptimizing_last_scan', 0 );
	$lock       = (bool) get_transient( 'wpoptimizing_scan_lock' );
	$since      = (int) get_transient( 'wpoptimizing_scan_pending_since' );
	$pending    = (bool) get_transient( 'wpoptimizing_scan_pending_ui' );
	$started_at = (int) get_option( 'wpoptimizing_scan_started_at', 0 );
	$failed_at  = (int) get_option( 'wpoptimizing_scan_failed_at', 0 );
	$failed     = ( $failed_at > 0 && $failed_at >= $started_at );
	$done       = false;

	// "In progress" = the lock is held OR the staged-scan cursor still exists. This
	// is the source of truth: scans triggered without the pending-overlay transients
	// (network-bulk, activation, plugin-change, API) must NOT be reported as done
	// just because a *previous* scan left last_scan>0 — that made the progress
	// overlay reload the page in a loop while the scan was still running.
	$in_progress = $lock || is_array( get_option( 'wpoptimizing_scan_state', null ) );

	if ( $pending && $since > 0 ) {
		$done = ( ! $in_progress ) && ( $last_ts >= $since );
		if ( $done ) {
			delete_transient( 'wpoptimizing_scan_pending_ui' );
			delete_transient( 'wpoptimizing_scan_pending_since' );
		}
	} else {
		$done = ! $in_progress;
		// The "since" mark lives 5 minutes and a big site's analysis lasts longer (6 on a
		// 422,571-attachment library): with it gone, the scan finished but the overlay flag
		// stayed, so every load showed the overlay, the poll said "done" and the page reloaded
		// every 3 s until the flag expired on its own (22/10/2026).
		if ( $done && $pending ) {
			delete_transient( 'wpoptimizing_scan_pending_ui' );
			delete_transient( 'wpoptimizing_scan_pending_since' );
		}
	}

	$progress = get_transient( 'wpoptimizing_scan_progress' );
	$elapsed  = $started_at > 0 ? max( 1, time() - $started_at ) : 0;
	$pct      = is_array( $progress ) ? (int) ( $progress['pct'] ?? 0 ) : 0;
	// The stage's own figure first: the media job measures its own pace, and the overall
	// rate says nothing about it — the code stages before it take seconds, it can take hours,
	// so the overall estimate grew while the bar crawled and then hid itself.
	$eta_secs = is_array( $progress ) ? (int) ( $progress['eta'] ?? 0 ) : 0;
	if ( 0 === $eta_secs && $elapsed > 0 && $pct > 2 && $pct < 100 ) {
		$rate     = $pct / $elapsed;        // % per second.
		$eta_secs = (int) round( ( 100 - $pct ) / $rate );
	}

	// A failed scan is terminal: stop the UI from polling AND clear the pending-overlay
	// transients so a reload does not re-show the overlay (and the pump does not see a
	// lingering "pending" flag and try to restart the just-failed scan).
	if ( $failed ) {
		$done = true;
		delete_transient( 'wpoptimizing_scan_pending_ui' );
		delete_transient( 'wpoptimizing_scan_pending_since' );
	}

	wp_send_json_success(
		array(
			'done'      => $done,
			'failed'    => $failed,
			'last_scan' => $last_ts,
			'progress'  => array(
				'pct'     => $pct,
				'label'   => $failed
					? __( 'Scan interrupted. Please try again.', 'wpoptimizing' )
					: ( is_array( $progress ) ? (string) ( $progress['label'] ?? '' ) : '' ),
				'elapsed' => $elapsed,
				'eta'     => $eta_secs,
			),
		)
	);
}
add_action( 'wp_ajax_wpoptimizing_scan_status', 'wpoptimizing_ajax_scan_status' );

/**
 * AJAX: cancel a pending/running scan.
 *
 * @return void
 */
function wpoptimizing_ajax_cancel_scan() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_cancel_scan', 'nonce' );

	delete_transient( 'wpoptimizing_scan_pending_ui' );
	delete_transient( 'wpoptimizing_scan_pending_since' );
	delete_transient( 'wpoptimizing_scan_lock' );
	delete_transient( 'wpoptimizing_scan_progress' );
	delete_option( 'wpoptimizing_scan_state' );
	// wp_unschedule_hook(): the scan event carries its reason as an argument, and
	// wp_clear_scheduled_hook() would only clear events without one — the cancelled scan
	// came back at the next cron tick.
	wp_unschedule_hook( 'wpoptimizing_cron_scan' );
	wp_unschedule_hook( 'wpoptimizing_cron_scan_step' );

	wp_send_json_success( array( 'cancelled' => true ) );
}
add_action( 'wp_ajax_wpoptimizing_cancel_scan', 'wpoptimizing_ajax_cancel_scan' );

/**
 * AJAX: start an analysis from the in-page "Analyze now" button, without loading a page.
 *
 * @return void
 */
function wpoptimizing_ajax_start_scan() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_run_async', 'nonce' );
	wpoptimizing_start_manual_scan();
	wp_send_json_success( array( 'started' => true ) );
}
add_action( 'wp_ajax_wpoptimizing_start_scan', 'wpoptimizing_ajax_start_scan' );

/**
 * AJAX: dismiss the first-run wizard.
 *
 * @return void
 */
function wpoptimizing_ajax_dismiss_wizard() {
	check_ajax_referer( 'wpoptimizing_dismiss_wizard', 'nonce' );
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( null, 403 );
	}
	// Even when the wizard is skipped, persist the selected (default: Safe) cleanup
	// preset so the site is left correctly configured. The client sends the preset
	// currently chosen in the wizard dropdown.
	$profile = isset( $_POST['profile'] ) ? sanitize_key( wp_unslash( $_POST['profile'] ) ) : '';
	if ( '' !== $profile && class_exists( 'WPOPT_General_Cleanup' ) ) {
		( new WPOPT_General_Cleanup( wpoptimizing_cap() ) )->apply_profile( $profile );
	}
	delete_option( 'wpoptimizing_first_run' );
	wp_send_json_success();
}
add_action( 'wp_ajax_wpoptimizing_dismiss_wizard', 'wpoptimizing_ajax_dismiss_wizard' );

/**
 * AJAX: set the data-sharing consent from the first-run wizard. Stores an
 * affirmative (or negative) choice; nothing is sent anywhere here — it only
 * records the option that gates the gateway exchange.
 *
 * @return void
 */
function wpoptimizing_ajax_set_share_consent() {
	check_ajax_referer( 'wpoptimizing_share_consent', 'nonce' );
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( null, 403 );
	}
	$enabled = isset( $_POST['enabled'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['enabled'] ) );
	$source  = ( isset( $_POST['source'] ) && 'banner' === sanitize_key( wp_unslash( $_POST['source'] ) ) ) ? 'banner' : 'wizard';
	$prev    = (bool) get_option( 'wpoptimizing_share_consent', false );
	update_option( 'wpoptimizing_share_consent', $enabled ? 1 : 0, true );
	if ( $enabled !== $prev && function_exists( 'wpoptimizing_audit_log' ) ) {
		wpoptimizing_audit_log(
			'data_sharing_consent',
			array(
				'enabled' => $enabled ? 1 : 0,
				'source'  => $source,
			)
		);
	}
	wp_send_json_success();
}
add_action( 'wp_ajax_wpoptimizing_set_share_consent', 'wpoptimizing_ajax_set_share_consent' );

/**
 * Record types the record viewer can open. The one list both sides read: the button that opens
 * the viewer (WPOPT_Admin_UI::record_query_button()) and the endpoint that answers it, so a type
 * cannot get a button the endpoint refuses, or an answer nobody can ask for.
 *
 * @return string[]
 */
function wpoptimizing_record_query_types() {
	return array(
		'option',
		'transient',
		'transient_expired',
		'post_meta',
		'user_meta',
		'term_meta',
		'post_type',
		'post_type_content',
		'taxonomy',
		'taxonomy_term',
		'shortcode',
		'cron',
		'action_scheduler',
		'role',
		'capability',
		'cap',
		'table',
		'attachment',
	);
}

/**
 * AJAX: live DB query preview for a DB scan item (type + value).
 *
 * @return void
 */
function wpoptimizing_ajax_live_query() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_live_query', 'nonce' );
	// Rate limit: max 60 live queries per minute per user.
	if ( function_exists( 'wpoptimizing_rate_allow' ) && ! wpoptimizing_rate_allow( 'live_query', 60, MINUTE_IN_SECONDS ) ) {
		wp_send_json_error( array( 'message' => 'rate_limit_exceeded' ), 429 );
	}

	global $wpdb;

	$type  = isset( $_POST['type'] ) ? sanitize_text_field( wp_unslash( $_POST['type'] ) ) : '';
	$value = isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '';

	if ( '' === $type || '' === $value ) {
		wp_send_json_error( array( 'message' => 'missing params' ) );
	}
	if ( ! in_array( $type, wpoptimizing_record_query_types(), true ) ) {
		wp_send_json_error( array( 'message' => 'unsupported type' ) );
	}

	$rows    = array();
	$columns = array();
	$title   = '';
	$limit   = 50;

	switch ( $type ) {
		case 'option':
		case 'transient':
		case 'transient_expired':
			/*
			 * EL NOMBRE DE LA TABLA SALE DE $wpdb, NO ESCRITO A MANO. Estos seis rotulos decian
			 * `wp_options`, `wp_postmeta`… mientras la consulta de debajo usaba {$wpdb->options}
			 * correctamente: la consulta estaba bien y el rotulo mentia. En cualquier sitio con
			 * prefijo propio —que es cualquiera medianamente endurecido— nombraba una tabla que
			 * no existe, y lo hacia justo en la ventana que se mira ANTES DE BORRAR.
			 */
			$title   = sprintf( '%s WHERE option_name = %s', $wpdb->options, $value );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$value
				),
				ARRAY_A
			);
			$columns = array( 'option_id', 'option_name', 'option_value', 'autoload' );
			$rows    = (array) $results;
			break;

		case 'post_meta':
			$title   = sprintf( '%s WHERE meta_key = %s', $wpdb->postmeta, $value );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT meta_id, post_id, meta_key, LEFT(meta_value,20000) AS meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
					$value,
					$limit
				),
				ARRAY_A
			);
			$columns = array( 'meta_id', 'post_id', 'meta_key', 'meta_value' );
			$rows    = (array) $results;
			break;

		case 'user_meta':
			$title = sprintf( '%s WHERE meta_key = %s', $wpdb->usermeta, $value );
			/*
			 * On a network the users table is shared by every site, and this plugin's capability
			 * is held by every sub-site administrator: unrestricted, the viewer showed them the
			 * metadata of every user on the network (session tokens included). Only a super admin
			 * sees the whole table; anyone else sees the users of THIS site — those holding a
			 * role here, i.e. a {prefix}capabilities row.
			 */
			if ( is_multisite() && ! is_super_admin() ) {
				$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT um.umeta_id, um.user_id, um.meta_key, LEFT(um.meta_value,20000) AS meta_value FROM {$wpdb->usermeta} um INNER JOIN {$wpdb->usermeta} cap ON cap.user_id = um.user_id AND cap.meta_key = %s WHERE um.meta_key = %s ORDER BY um.umeta_id DESC LIMIT %d",
						$wpdb->get_blog_prefix() . 'capabilities',
						$value,
						$limit
					),
					ARRAY_A
				);
			} else {
				$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT umeta_id, user_id, meta_key, LEFT(meta_value,20000) AS meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY umeta_id DESC LIMIT %d",
						$value,
						$limit
					),
					ARRAY_A
				);
			}
			$columns = array( 'umeta_id', 'user_id', 'meta_key', 'meta_value' );
			$rows    = (array) $results;
			break;

		case 'term_meta':
			$title   = sprintf( '%s WHERE meta_key = %s', $wpdb->termmeta, $value );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT meta_id, term_id, meta_key, LEFT(meta_value,20000) AS meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT %d",
					$value,
					$limit
				),
				ARRAY_A
			);
			$columns = array( 'meta_id', 'term_id', 'meta_key', 'meta_value' );
			$rows    = (array) $results;
			break;

		// 'post_type_content' and 'taxonomy_term' are the SAME question asked about the same
		// value — the row just reports a record count instead of a byte size. Without these
		// aliases those rows rendered with no way to look inside, which is exactly where a
		// user most wants to look: they are the rows that say "this many records will go".
		case 'post_type':
		case 'post_type_content':
			$title   = sprintf( '%s WHERE post_type = %s', $wpdb->posts, $value );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ID, post_title, post_status, post_date FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','trash') ORDER BY ID DESC LIMIT %d",
					$value,
					$limit
				),
				ARRAY_A
			);
			$columns = array( 'ID', 'post_title', 'post_status', 'post_date' );
			$rows    = (array) $results;
			break;

		case 'taxonomy':
		case 'taxonomy_term':
			$title   = sprintf( '%s WHERE taxonomy = %s', $wpdb->term_taxonomy, $value );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT tt.term_taxonomy_id, t.name, t.slug, tt.count FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = %s ORDER BY tt.count DESC LIMIT %d",
					$value,
					$limit
				),
				ARRAY_A
			);
			$columns = array( 'term_taxonomy_id', 'name', 'slug', 'count' );
			$rows    = (array) $results;
			break;

		case 'shortcode':
			$title   = sprintf( 'Posts containing shortcode [%s]', $value );
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ID, post_title, post_type, post_status FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status <> 'auto-draft' ORDER BY ID DESC LIMIT %d",
					'%[' . $wpdb->esc_like( $value ) . '%',
					$limit
				),
				ARRAY_A
			);
			$columns = array( 'ID', 'post_title', 'post_type', 'post_status' );
			$rows    = (array) $results;
			break;

		case 'cron':
			$title = sprintf( 'Scheduled events for hook: %s', $value );
			$cron  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
			$rows  = array();
			if ( is_array( $cron ) ) {
				foreach ( $cron as $timestamp => $hooks ) {
					if ( ! is_array( $hooks ) || ! isset( $hooks[ $value ] ) ) {
						continue;
					}
					foreach ( (array) $hooks[ $value ] as $callback_hash => $info ) {
						if ( is_array( $info ) ) {
							$rows[] = array(
								'timestamp'     => date_i18n( 'Y-m-d H:i:s', (int) $timestamp ),
								'callback_hash' => (string) $callback_hash,
								'schedule'      => isset( $info['schedule'] ) ? (string) $info['schedule'] : 'once',
								'interval'      => isset( $info['interval'] ) ? (int) $info['interval'] . 's' : '—',
							);
						}
					}
				}
			}
			$columns = array( 'timestamp', 'callback_hash', 'schedule', 'interval' );
			break;

		case 'role':
			$title     = sprintf( 'Users with role: %s', $value );
			$user_data = count_users();
			$count     = (int) ( $user_data['avail_roles'][ $value ] ?? 0 );
			$results   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT u.ID, u.user_login, u.user_email, u.user_registered FROM {$wpdb->users} u INNER JOIN {$wpdb->usermeta} um ON um.user_id = u.ID WHERE um.meta_key = %s AND um.meta_value LIKE %s ORDER BY u.ID DESC LIMIT %d",
					$wpdb->prefix . 'capabilities',
					'%' . $wpdb->esc_like( '"' . $value . '"' ) . '%',
					$limit
				),
				ARRAY_A
			);
			$columns   = array( 'ID', 'user_login', 'user_email', 'user_registered' );
			$rows      = (array) $results;
			$title    .= sprintf( ' (%d total)', $count );
			break;

		case 'action_scheduler':
			$as_actions = $wpdb->prefix . 'actionscheduler_actions';
			$title      = sprintf( '%s WHERE hook = %s', $as_actions, $value );
			if ( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $as_actions ) ) ) !== $as_actions ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				break;
			}
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT action_id, hook, status, scheduled_date_gmt, LEFT(args,2000) AS args FROM %i WHERE hook = %s ORDER BY action_id DESC LIMIT %d',
					$as_actions,
					$value,
					$limit
				),
				ARRAY_A
			);
			$columns = array( 'action_id', 'hook', 'status', 'scheduled_date_gmt', 'args' );
			$rows    = (array) $results;
			break;

		case 'capability':
		case 'cap':
			// "role:cap" for one role, or a bare capability: which roles grant it.
			[ $only_role, $cap ] = ( false !== strpos( $value, ':' ) ) ? array_pad( explode( ':', $value, 2 ), 2, '' ) : array( '', $value );
			$title               = sprintf( 'Roles with capability: %s', $cap );
			foreach ( (array) wp_roles()->role_objects as $role_name => $role_obj ) {
				if ( ( '' === $only_role || $only_role === $role_name ) && isset( $role_obj->capabilities[ $cap ] ) ) {
					$rows[] = array(
						'role'    => (string) $role_name,
						'granted' => $role_obj->capabilities[ $cap ] ? 'true' : 'false',
					);
				}
			}
			$columns = array( 'role', 'granted' );
			break;

		case 'attachment':
			// The attachment's own row, then its meta, as field/value pairs.
			$id    = absint( $value );
			$title = sprintf( '%s WHERE ID = %d', $wpdb->posts, $id );
			$post  = $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_title, post_name, post_mime_type, post_parent, post_date, guid FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'attachment'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! is_array( $post ) ) {
				break;
			}
			foreach ( $post as $field => $field_value ) {
				$rows[] = array(
					'field' => (string) $field,
					'value' => (string) $field_value,
				);
			}
			$meta = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, LEFT(meta_value,20000) AS meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id LIMIT %d", $id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( (array) $meta as $m ) {
				$rows[] = array(
					'field' => (string) $m['meta_key'],
					'value' => (string) $m['meta_value'],
				);
			}
			$columns = array( 'field', 'value' );
			break;

		case 'table':
			// The Tables tab lists full names; the scan tables give the name without the prefix.
			// Read as a full name only when that table exists and the prefixed reading does not.
			if ( '' !== $wpdb->prefix && 0 === strpos( $value, $wpdb->prefix )
				&& (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $value ) ) ) === $value // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				&& (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . $value ) ) ) !== $wpdb->prefix . $value ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$value = substr( $value, strlen( $wpdb->prefix ) );
			}
			// Restrict table-name suffix to alphanumeric + underscore before building $prefixed.
			$prefixed = $wpdb->prefix . preg_replace( '/[^a-zA-Z0-9_]/', '', $value );
			$title    = 'SELECT * FROM ' . $prefixed;
			$exists   = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.TABLES WHERE table_schema = DATABASE() AND table_name = %s', $prefixed ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( ! $exists ) {
				wp_send_json_error( array( 'message' => 'table not found' ) );
			}
			// On a network, $wpdb->prefix for the MAIN site ("wp_") is also a prefix of every
			// subsite's tables ("wp_2_posts") and of the shared globals. Every subsite
			// administrator holds manage_options and therefore this plugin's capability, so
			// without this check a main-site administrator who is not a network administrator
			// could read another site's rows through this endpoint. The Tables tab already
			// applies exactly this rule when deciding what to LIST; the endpoint that reads a
			// table has to apply it too, or the restriction is only in the user interface —
			// and a restriction that lives only in the interface is not a restriction.
			//
			// Fails CLOSED: if the ownership helper is somehow not loaded, a network request is
			// refused rather than waved through. A security check behind function_exists() that
			// passes when the function is missing is not a check.
			if ( is_multisite() && ! current_user_can( 'manage_network_options' ) ) {
				$owns = function_exists( 'wpoptimizing_table_belongs_to_current_blog' )
					&& wpoptimizing_table_belongs_to_current_blog( $prefixed );
				if ( ! $owns ) {
					wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
				}
			}
			$results = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
				'SELECT * FROM `' . esc_sql( $prefixed ) . '` LIMIT ' . (int) $limit,
				ARRAY_A
			);
			$rows    = (array) $results;
			if ( ! empty( $rows ) ) {
				$columns = array_keys( $rows[0] );
			}
			break;

		default:
			wp_send_json_error( array( 'message' => 'unsupported type' ) );
	}

	// Redact values that look sensitive before sending to the browser.
	$rows = wpoptimizing_redact_sensitive_rows( $columns, $rows );

	wp_send_json_success(
		array(
			'title'   => $title,
			'columns' => $columns,
			'rows'    => $rows,
		)
	);
}
add_action( 'wp_ajax_wpoptimizing_live_query', 'wpoptimizing_ajax_live_query' );

/**
 * Toggle a DB item's "protected from deletion" state from the items table. The entry is
 * the same "type::value" string the deletion path uses, so the protected-items guard
 * (`wpoptimizing_can_delete_item`) picks it up with no change to the delete routine.
 *
 * @return void
 */
function wpoptimizing_ajax_toggle_protected() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_toggle_protected', 'nonce' );
	if ( ! function_exists( 'wpoptimizing_protected_items_add' ) ) {
		wp_send_json_error( array( 'message' => 'unavailable' ) );
	}
	$entry = isset( $_POST['entry'] ) ? sanitize_text_field( wp_unslash( $_POST['entry'] ) ) : '';
	if ( '' === $entry || false === strpos( $entry, '::' ) ) {
		wp_send_json_error( array( 'message' => 'missing params' ) );
	}
	$protect_raw = isset( $_POST['protect'] ) ? sanitize_text_field( wp_unslash( $_POST['protect'] ) ) : '0';
	$protect     = ( '' !== $protect_raw && '0' !== $protect_raw );
	if ( $protect ) {
		wpoptimizing_protected_items_add( $entry );
	} else {
		wpoptimizing_protected_items_remove( $entry );
	}
	wp_send_json_success( array( 'protected' => wpoptimizing_item_is_protected( $entry ) ) );
}
add_action( 'wp_ajax_wpoptimizing_toggle_protected', 'wpoptimizing_ajax_toggle_protected' );

/**
 * Bulk protect/unprotect: mark or clear "protected from deletion" for a set of DB items in
 * one request (the checked rows of an items table). Same entry format and guard as the
 * per-row toggle.
 *
 * @return void
 */
function wpoptimizing_ajax_bulk_protect() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_toggle_protected', 'nonce' );
	if ( ! function_exists( 'wpoptimizing_protected_items_add' ) ) {
		wp_send_json_error( array( 'message' => 'unavailable' ) );
	}
	$entries     = ( isset( $_POST['entries'] ) && is_array( $_POST['entries'] ) )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['entries'] ) )
		: array();
	$protect_raw = isset( $_POST['protect'] ) ? sanitize_text_field( wp_unslash( $_POST['protect'] ) ) : '0';
	$protect     = ( '' !== $protect_raw && '0' !== $protect_raw );
	$changed     = 0;
	$seen        = 0;
	foreach ( $entries as $entry ) {
		if ( ++$seen > 5000 ) {
			break; // Safety cap.
		}
		$entry = (string) $entry;
		if ( '' === $entry || false === strpos( $entry, '::' ) ) {
			continue;
		}
		$ok = $protect ? wpoptimizing_protected_items_add( $entry ) : wpoptimizing_protected_items_remove( $entry );
		if ( $ok ) {
			++$changed;
		}
	}
	wp_send_json_success( array( 'changed' => $changed ) );
}
add_action( 'wp_ajax_wpoptimizing_bulk_protect', 'wpoptimizing_ajax_bulk_protect' );

/**
 * Owner search for the manual-attribution picker: returns Select2 results merged
 * from installed extensions, the public wp.org repository and the shared KB, so
 * the user can pin the exact plugin/theme (correct slug + type) even when it is
 * no longer installed.
 *
 * @return void
 */
function wpoptimizing_ajax_owner_search() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json( array( 'results' => array() ) );
	}
	check_ajax_referer( 'wpoptimizing_owner_search', 'nonce' );
	$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
	$ui   = new WPOPT_Admin_UI();
	wp_send_json( array( 'results' => $ui->owner_search( $term ) ) );
}
add_action( 'wp_ajax_wpoptimizing_owner_search', 'wpoptimizing_ajax_owner_search' );


/**
 * AJAX endpoint for the async bulk delete/clean engine. Processes ONE batch of
 * up to 50 identifiers for a given scope (db|fs|autoload|media_dupes), reusing
 * the same per-scope handlers as the synchronous form submit. The client drives
 * the loop and the progress bar. Same trust model as the existing manual delete
 * (premium + capability + nonce), plus a generous per-user rate limit so a long
 * multi-batch job is not throttled.
 *
 * @return void
 */
function wpoptimizing_ajax_bulk_delete() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_bulk', 'nonce' );

	if ( function_exists( 'wpoptimizing_rate_allow' ) && ! wpoptimizing_rate_allow( 'bulk', 1000, MINUTE_IN_SECONDS ) ) {
		wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
	}

	$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '';
	$final = isset( $_POST['final'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['final'] ) );
	$job   = isset( $_POST['job'] ) ? sanitize_key( wp_unslash( $_POST['job'] ) ) : '';
	$ids   = array();
	if ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$ids = array_map( 'sanitize_text_field', wp_unslash( $_POST['ids'] ) );
	}
	$ids = array_slice( $ids, 0, 50 ); // Hard cap per batch.

	// 'all' (the whole filtered list) or 'selected' (ticked rows). Only used to decide
	// whether a wholesale action is allowed; it never widens what is processed.
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
	$mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all';
	$mode = ( 'selected' === $mode ) ? 'selected' : 'all';

	$scopes = array( 'db', 'empty', 'fs', 'autoload', 'media_dupes', 'unused', 'cron', 'optimize_tables', 'convert_innodb', 'trust_unused' );
	if ( ! in_array( $scope, $scopes, true ) ) {
		wp_send_json_error( array( 'message' => 'bad_scope' ), 400 );
	}


	// A BOUNDED UNUSED-MEDIA SCAN MAY NOT BE ACTED ON WHOLESALE. When the reference scan hits
	// its row cap it does not weigh a reference and discard it — it never sees it, so items
	// that ARE in use can be listed as unused. Deleting a selection somebody ticked after
	// looking at it is their call; deleting everything the list happens to contain is the one
	// action that turns a detection limit into missing images.
	//
	// The button is already withdrawn in that state, so this is the second lock, not the
	// first: a hidden control is not a guard.
	if ( 'unused' === $scope && 'all' === $mode && wpoptimizing_media_unused_scan_is_partial() ) {
		wp_send_json_error(
			array(
				'message' => 'partial_scan',
				'detail'  => __( 'This scan was bounded, so this list may include media that is actually in use. Select the items you have checked yourself instead.', 'wpoptimizing' ),
			),
			409
		);
	}
	// Nor on an old report: the whole list is only as true as the day it was made.
	if ( in_array( $scope, array( 'unused', 'media_dupes' ), true ) && 'all' === $mode && wpoptimizing_media_report_is_stale() ) {
		wp_send_json_error( array( 'message' => 'stale_report' ), 409 );
	}

	$ui = new WPOPT_Admin_UI();
	// A batch failed in the browser: close the job so it still reports what it did.
	if ( ! empty( $_POST['abort'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above.
		$ui->finish_bulk_job( $scope, $job, true );
		wp_send_json_success( array( 'processed' => 0 ) );
	}
	$processed = $ui->run_bulk_batch( $scope, $ids, $final, $job );

	wp_send_json_success( array( 'processed' => (int) $processed ) );
}
add_action( 'wp_ajax_wpoptimizing_bulk_delete', 'wpoptimizing_ajax_bulk_delete' );

/**
 * AJAX: flush the whole object cache from the toolbar.
 *
 * @return void
 */
function wpoptimizing_ajax_cache_flush() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_cache_flush', 'nonce' );
	// Flush our object cache and every detected host/plugin page cache.
	$flushed = function_exists( 'wpoptimizing_flush_all_caches' )
		? wpoptimizing_flush_all_caches( 'all' )
		: ( function_exists( 'wp_cache_flush' ) && wp_cache_flush() ? array( __( 'Object cache', 'wpoptimizing' ) ) : array() );
	if ( function_exists( 'wpoptimizing_audit_log' ) ) {
		wpoptimizing_audit_log( 'cache_flush_toolbar', array( 'flushed' => $flushed ) );
	}
	wp_send_json_success(
		array(
			'ok'      => ! empty( $flushed ),
			'flushed' => $flushed,
		)
	);
}
add_action( 'wp_ajax_wpoptimizing_cache_flush', 'wpoptimizing_ajax_cache_flush' );



/**
 * AJAX: run the general database cleanup from the toolbar menu.
 *
 * Synchronous run with the user's saved settings/profile; the click is deliberate
 * and rate-limited. Returns the per-category counts so the toolbar can confirm.
 *
 * @return void
 */
function wpoptimizing_ajax_quick_cleanup() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_quick_cleanup', 'nonce' );
	if ( function_exists( 'wpoptimizing_rate_allow' ) && ! wpoptimizing_rate_allow( 'quick_cleanup', 10, MINUTE_IN_SECONDS ) ) {
		wp_send_json_error( array( 'message' => 'rate_limited' ), 429 );
	}
	if ( ! class_exists( 'WPOPT_General_Cleanup' ) ) {
		wpoptimizing_load_engine();
	}
	if ( ! class_exists( 'WPOPT_General_Cleanup' ) ) {
		wp_send_json_error( array( 'message' => 'unavailable' ), 500 );
	}
	( new WPOPT_General_Cleanup( wpoptimizing_cap() ) )->run( false );
	$stats = get_option( 'wpoptimizing_general_cleanup_last_stats', array() );
	wp_send_json_success(
		array(
			'revisions'  => isset( $stats['revisions'] ) ? (int) $stats['revisions'] : 0,
			'posts'      => isset( $stats['posts'] ) ? (int) $stats['posts'] : 0,
			'comments'   => isset( $stats['comments'] ) ? (int) $stats['comments'] : 0,
			'transients' => isset( $stats['transients'] ) ? (int) $stats['transients'] : 0,
		)
	);
}
add_action( 'wp_ajax_wpoptimizing_quick_cleanup', 'wpoptimizing_ajax_quick_cleanup' );

/**
 * AJAX: one step of «Run general cleanup now», behind its progress bar.
 *
 * The button used to run one request of two minutes and leave the rest to WP-Cron, which on
 * the WP Total Updater site (DISABLE_WP_CRON, a system cron every few minutes) meant
 * a page that said «done» with millions of rows still there (28/10/2026). Now the screen drives
 * the passes itself, short ones, until nothing is left; if the page is closed, the passes
 * scheduled by WPOPT_General_Cleanup::RESUME_OPTION carry on from where it stopped. A step that
 * finds a background pass holding the lock answers `locked`, and the browser waits and asks again.
 *
 * @return void
 */
function wpoptimizing_ajax_gc_step() {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
	}
	check_ajax_referer( 'wpoptimizing_gc_step', 'nonce' );
	if ( ! class_exists( 'WPOPT_General_Cleanup' ) ) {
		wpoptimizing_load_engine();
	}
	if ( ! class_exists( 'WPOPT_General_Cleanup' ) ) {
		wp_send_json_error( array( 'message' => 'unavailable' ), 500 );
	}
	if ( ! empty( $_POST['finish'] ) ) {
		if ( class_exists( 'WPOPT_Performance_Logger' ) ) {
			( new WPOPT_Performance_Logger() )->snapshot( 'after_clean', '' );
		}
		wp_send_json_success( array( 'finished' => true ) );
	}
	$state = WPOPT_General_Cleanup::resume_state();
	if ( empty( $_POST['first'] ) && null === $state ) {
		wp_send_json_success(
			array(
				'more'    => false,
				'deleted' => 0,
			)
		);
	}
	// A background pass is working: wait for it rather than record a skipped run.
	$lock = get_transient( 'wpoptimizing_general_cleanup_lock' );
	if ( false !== $lock && ! WPOPT_General_Cleanup::lock_is_stale( $lock, time() ) ) {
		wp_send_json_success(
			array(
				'more'    => true,
				'locked'  => true,
				'deleted' => 0,
			)
		);
	}
	// Short steps, so the bar moves and no request comes near a host's timeout.
	add_filter( 'wpoptimizing_cleanup_web_budget', static fn() => 25 );
	( new WPOPT_General_Cleanup( wpoptimizing_cap() ) )->run( false, 0, false, empty( $_POST['first'] ) ? (array) $state['tasks'] : null );

	wp_send_json_success(
		array(
			'more'    => null !== WPOPT_General_Cleanup::resume_state(),
			'locked'  => false,
			'deleted' => WPOPT_General_Cleanup::stats_progress( (array) get_option( 'wpoptimizing_general_cleanup_last_stats', array() ) ),
		)
	);
}
add_action( 'wp_ajax_wpoptimizing_gc_step', 'wpoptimizing_ajax_gc_step' );

