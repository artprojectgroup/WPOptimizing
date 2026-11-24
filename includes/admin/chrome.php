<?php
/**
 * Admin chrome: branding, the shared progress markup and the audit trail.
 *
 * Every one of these either prints part of a wp-admin screen or records what somebody did on
 * one. The public site never sees any of it — the admin-bar node, which DOES render on the
 * front end for logged-in users, deliberately stays in the main file and calls none of this.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

require_once dirname( __DIR__ ) . '/icons.php'; // wpoptimizing_icon(): the interface icons, Lite and Pro.

/**
 * The product/brand name shown across the admin UI (menu, headings, reports).
 *
 * Uses the account-managed white-label brand when active (agency licenses),
 * otherwise 'WPOptimizing'. Still filterable so add-ons can override.
 *
 * @since 0.1.0
 * @return string
 */
function wpoptimizing_brand_name() {
	$name = '';
	if ( '' === trim( $name ) ) {
		$name = 'WPOptimizing';
	}

	/**
	 * Filters the WPOptimizing brand/product name (white-label entry point).
	 *
	 * @since 0.1.0
	 * @param string $name Brand name (option value, or 'WPOptimizing' default).
	 */
	$name = (string) apply_filters( 'wpoptimizing_brand_name', $name );
	$name = trim( wp_strip_all_tags( $name ) );
	return '' !== $name ? $name : 'WPOptimizing';
}

/**
 * The brand logo URL shown in reports, emails and the admin header (white-label).
 *
 * @since 0.1.0
 * @return string Logo URL, or '' if none configured.
 */
function wpoptimizing_brand_logo() {
	$url = '';

	/**
	 * Filters the brand logo URL.
	 *
	 * @since 0.1.0
	 * @param string $url Logo URL (may be empty).
	 */
	$url = (string) apply_filters( 'wpoptimizing_brand_logo', $url );

	return esc_url_raw( trim( $url ) );
}

/**
 * Whether white-label mode is active.
 *
 * In white-label mode the plugin hides Art Project Group branding (header image,
 * info sidebar and the plugin-row promo links) and uses the configured brand
 * name/logo instead.
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_is_white_label() {
	$on = false;

	/**
	 * Filters whether white-label mode is active.
	 *
	 * @since 0.1.0
	 * @param bool $on Resolved from account-managed white-label, or the local option.
	 */
	return (bool) apply_filters( 'wpoptimizing_is_white_label', $on );
}

/**
 * The single, reusable Pro affordance: a small "Pro" pill (lock + label) that opens the
 * upgrade modal (any .wpopt-pro-cta element does). Global so both the admin UI class and
 * top-level notices render the exact same control. Returns pre-escaped HTML.
 *
 * Lite has no locks and no upgrade modal: there it is a plain link, "Available in
 * WPOptimizing Pro.", to wpoptimizing.com — a neutral note, never a disabled control.
 *
 * @return string
 */
function wpoptimizing_pro_hint() {
	$html = '<a class="wpopt-pro-link" href="' . esc_url( 'https://wpoptimizing.com' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Available in WPOptimizing Pro.', 'wpoptimizing' ) . '</a>';
	return $html;
}


/**
 * Unified progress-bar markup (track + percentage + label + ETA), shared by the
 * analysis overlay and every async bulk action so they all look identical. Styling
 * lives in assets/css/admin.css (.wpopt-pb-*). When $id_prefix is given the inner
 * nodes get ids "<prefix>-fill|-pct|-label|-eta" (the overlay JS targets them by
 * id); otherwise they are addressed by class within their container (the bulk JS).
 *
 * @param string $id_prefix     Optional id prefix for the inner nodes.
 * @param string $initial_label Optional initial label text.
 * @return string Markup.
 */
function wpoptimizing_progress_bar_markup( $id_prefix = '', $initial_label = '' ) {
	$idf = '' !== $id_prefix ? ' id="' . esc_attr( $id_prefix ) . '-fill"' : '';
	$idp = '' !== $id_prefix ? ' id="' . esc_attr( $id_prefix ) . '-pct"' : '';
	$idl = '' !== $id_prefix ? ' id="' . esc_attr( $id_prefix ) . '-label"' : '';
	$ide = '' !== $id_prefix ? ' id="' . esc_attr( $id_prefix ) . '-eta"' : '';
	return '<div class="wpopt-pb-track"><div class="wpopt-pb-fill"' . $idf . '></div></div>'
		. '<p class="wpopt-pb-pct"' . $idp . '>0%</p>'
		. '<p class="wpopt-pb-label"' . $idl . '>' . esc_html( $initial_label ) . '</p>'
		. '<p class="wpopt-pb-eta"' . $ide . '></p>';
}

/**
 * Centered progress-overlay markup (dimmed full-page backdrop + card + progress
 * bar), shared by the analysis and every async bulk action so they look identical.
 * Styling lives in assets/css/admin.css.
 *
 * @param string $title Card title (escaped).
 * @param array  $opts  id_prefix (bar node ids), overlay_id (id on the overlay,
 *                       for JS to show/hide), label (initial label), hidden (bool).
 * @return string Markup.
 */
function wpoptimizing_progress_overlay_markup( $title, $opts = array() ) {
	$id_prefix  = isset( $opts['id_prefix'] ) ? (string) $opts['id_prefix'] : '';
	$overlay_id = isset( $opts['overlay_id'] ) ? (string) $opts['overlay_id'] : '';
	$label      = isset( $opts['label'] ) ? (string) $opts['label'] : '';
	$oid        = '' !== $overlay_id ? ' id="' . esc_attr( $overlay_id ) . '"' : '';
	// Initial hidden state via class (toggled by JS setting inline display:flex), no inline style.
	$cls = 'wpoptimizing-scan-overlay' . ( ! empty( $opts['hidden'] ) ? ' wpopt-hidden' : '' );
	// Optional escape hatch. A staged scan can wedge on a hosting-level timeout, and without
	// this the overlay is a wall: the work is already in the background, but the screen stays
	// blurred until a lock expires. The endpoint behind it has existed all along with no way
	// to reach it.
	$cancel = '';
	if ( ! empty( $opts['cancel_id'] ) && ! empty( $opts['cancel_label'] ) ) {
		$cancel = '<p class="wpopt-progress-cancel"><button type="button" class="button-link" id="'
			. esc_attr( (string) $opts['cancel_id'] ) . '">'
			. esc_html( (string) $opts['cancel_label'] ) . '</button></p>';
	}

	return '<div class="' . esc_attr( $cls ) . '"' . $oid . ' role="status" aria-live="polite" aria-label="' . esc_attr( $title ) . '">'
		. '<div class="wpoptimizing-scan-card"><h3>' . esc_html( $title ) . '</h3>'
		. wpoptimizing_progress_bar_markup( $id_prefix, $label )
		. $cancel
		. '</div></div>';
}

/**
 * The "no-print" toolbar (Save as PDF / Print + Close) shown at the top of every
 * standalone report. Single source of truth; pair the $btn_id with the same id
 * passed to wpoptimizing_print_report_script().
 *
 * @param string $btn_id DOM id for the print button.
 * @return string HTML.
 */
function wpoptimizing_print_report_toolbar( $btn_id ) {
	return '<div class="no-print">'
		. '<button class="print-btn" id="' . esc_attr( $btn_id ) . '">&#8595; ' . esc_html__( 'Save as PDF / Print', 'wpoptimizing' ) . '</button>'
		. '<button class="close-btn" onclick="window.close()">&#10005; ' . esc_html__( 'Close', 'wpoptimizing' ) . '</button>'
		. '</div>';
}

/**
 * Simple per-user rate limiter using transients.
 * Returns true if the action is allowed; false if the limit has been reached.
 *
 * @param string $action  Identifier for the action (e.g. 'scan', 'delete').
 * @param int    $max     Max calls allowed in the window.
 * @param int    $window  Window in seconds.
 * @return bool
 */
function wpoptimizing_rate_allow( $action, $max, $window ) {
	$uid = get_current_user_id();
	if ( ! $uid ) {
		return true; // Not logged in — other guards will catch it.
	}
	$key   = 'wpopt_rl_' . absint( $uid ) . '_' . sanitize_key( $action );
	$count = (int) get_transient( $key );
	if ( $count >= $max ) {
		return false;
	}
	if ( 0 === $count ) {
		set_transient( $key, 1, $window );
	} else {
		set_transient( $key, $count + 1, $window );
	}
	return true;
}

/**
 * Append one entry to the audit log file (JSONL, capped at 500 lines).
 *
 * @param string $action  Short action identifier: delete_bd, delete_fs, uninstall_plugin, etc.
 * @param array  $context Extra context (items deleted, count, etc.).
 * @return void
 */
function wpoptimizing_audit_log( $action, array $context = array() ) {
	$log_file = WPOPT_DIR . '/audit.log';
	$user     = wp_get_current_user();
	$entry    = wp_json_encode(
		array_merge(
			array(
				'ts'     => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'user'   => $user ? (int) $user->ID : 0,
				'login'  => $user ? (string) $user->user_login : '',
				'action' => (string) $action,
			),
			$context
		)
	);
	if ( false === $entry ) {
		return;
	}

	// Rotate: keep last 500 lines.
	if ( file_exists( $log_file ) ) {
		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( is_array( $lines ) && count( $lines ) >= 500 ) {
			$lines = array_slice( $lines, -499 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $log_file, implode( "\n", $lines ) . "\n", LOCK_EX );
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $log_file, $entry . "\n", FILE_APPEND | LOCK_EX );
}
