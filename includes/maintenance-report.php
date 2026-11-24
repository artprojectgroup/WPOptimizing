<?php
/**
 * B7 — periodic maintenance report by email.
 *
 * Sends the site owner / maintainer a recurring summary (off by default): the Health Score and
 * its trend, the key database/media metrics, any anomalous table growth (D4) and the activity
 * (scans / cleanups) since the previous report. Reuses the existing recipient routing
 * (wpoptimizing_notification_recipients — maintainer-aware, [[agency-multisite-features]]), the
 * analytics/history API and the same inline-styled email shell as the network alert.
 *
 * Frequency is a single daily cron that decides whether it is due (daily / weekly / monthly),
 * so it works without non-core cron schedules and survives a missed tick.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPT_REPORT_FREQ_OPTION' ) ) {
	define( 'WPOPT_REPORT_FREQ_OPTION', 'wpoptimizing_report_frequency' );
}
if ( ! defined( 'WPOPT_REPORT_LAST_OPTION' ) ) {
	define( 'WPOPT_REPORT_LAST_OPTION', 'wpoptimizing_report_last_sent' );
}

/**
 * The selectable frequencies (id => label). 'off' disables the report.
 *
 * @return array<string,string>
 */
function wpoptimizing_report_frequencies() {
	return array(
		'off'         => __( 'Off', 'wpoptimizing' ),
		'daily'       => __( 'Daily', 'wpoptimizing' ),
		'weekly'      => __( 'Weekly', 'wpoptimizing' ),
		'fortnightly' => __( 'Fortnightly', 'wpoptimizing' ),
		'monthly'     => __( 'Monthly', 'wpoptimizing' ),
	);
}

/**
 * The configured frequency, validated against the known set (default 'off').
 *
 * @return string
 */
function wpoptimizing_report_frequency() {
	$f = (string) get_option( WPOPT_REPORT_FREQ_OPTION, 'off' );
	return isset( wpoptimizing_report_frequencies()[ $f ] ) ? $f : 'off';
}

/**
 * The interval in days for a frequency (0 = off). PURE.
 *
 * @param string $freq Frequency id.
 * @return int
 */
function wpoptimizing_report_interval_days( $freq ) {
	switch ( (string) $freq ) {
		case 'daily':
			return 1;
		case 'weekly':
			return 7;
		case 'fortnightly':
			return 14;
		case 'monthly':
			return 30;
		default:
			return 0;
	}
}

/**
 * Whether a report is due now given the frequency, the last-sent time and the current time.
 * PURE (testable): a half-day of slack absorbs cron jitter so a daily report never skips a day.
 *
 * @param string $freq      Frequency id.
 * @param int    $last_sent Unix time of the previous send (0 = never).
 * @param int    $now       Current Unix time.
 * @return bool
 */
function wpoptimizing_report_is_due( $freq, $last_sent, $now ) {
	$days = wpoptimizing_report_interval_days( $freq );
	if ( $days <= 0 ) {
		return false;
	}
	if ( (int) $last_sent <= 0 ) {
		return true; // Never sent → send the first one.
	}
	$interval = $days * DAY_IN_SECONDS - 12 * HOUR_IN_SECONDS;
	return ( (int) $now - (int) $last_sent ) >= $interval;
}

/**
 * A short localized grade label for a 0-100 score.
 *
 * @param int $score Score.
 * @return string
 */
function wpoptimizing_report_score_label( $score ) {
	$score = (int) $score;
	if ( $score >= 90 ) {
		return __( 'Excellent', 'wpoptimizing' );
	}
	if ( $score >= 75 ) {
		return __( 'Good', 'wpoptimizing' );
	}
	if ( $score >= 50 ) {
		return __( 'Fair', 'wpoptimizing' );
	}
	return __( 'Needs attention', 'wpoptimizing' );
}

/**
 * Localized label + unit for a metric key shown in the report.
 *
 * @param string $key Metric key.
 * @return array{label:string,unit:string}
 */
function wpoptimizing_report_metric_meta( $key ) {
	// [ label, unit, higher_is_better ]. For most, smaller is better; cache hit rate is the
	// exception (higher is better), so the trend arrow colour is flipped for it.
	$map = array(
		'server_ttfb_ms'    => array( __( 'Server TTFB', 'wpoptimizing' ), 'ms', false ),
		'cache_hit_rate'    => array( __( 'Cache hit rate', 'wpoptimizing' ), 'percent', true ),
		'autoload_bytes'    => array( __( 'Autoload size', 'wpoptimizing' ), 'bytes', false ),
		'db_options'        => array( __( 'Options', 'wpoptimizing' ), 'count', false ),
		'db_transients'     => array( __( 'Transients', 'wpoptimizing' ), 'count', false ),
		'orphan_db'         => array( __( 'Database orphans', 'wpoptimizing' ), 'count', false ),
		'orphan_fs'         => array( __( 'File orphans', 'wpoptimizing' ), 'count', false ),
		'media_dupes_bytes' => array( __( 'Reclaimable (duplicates)', 'wpoptimizing' ), 'bytes', false ),
	);
	if ( isset( $map[ $key ] ) ) {
		return array(
			'label'         => $map[ $key ][0],
			'unit'          => $map[ $key ][1],
			'higher_better' => (bool) $map[ $key ][2],
		);
	}
	return array(
		'label'         => $key,
		'unit'          => 'count',
		'higher_better' => false,
	);
}

/**
 * Format a metric value for the email. PURE.
 *
 * @param int|float $value Value.
 * @param string    $unit  'bytes' | 'count' | 'ms' | 'percent'.
 * @return string
 */
function wpoptimizing_report_format_value( $value, $unit ) {
	if ( 'bytes' === $unit ) {
		return size_format( (int) $value, 1 );
	}
	if ( 'ms' === $unit ) {
		/* translators: %s: milliseconds. */
		return sprintf( __( '%s ms', 'wpoptimizing' ), number_format_i18n( (int) $value ) );
	}
	if ( 'percent' === $unit ) {
		return number_format_i18n( (int) $value ) . '%';
	}
	return number_format_i18n( (int) $value );
}

/**
 * Gather everything the report needs from the analytics/history + table-growth data. Returns
 * null when there is no history yet (nothing meaningful to send).
 *
 * @return array<string,mixed>|null
 */
function wpoptimizing_report_collect() {
	// Load the analytics/logger deps on demand (they are not loaded on a plain wp-cron request).
	if ( ! function_exists( 'wpoptimizing_analytics_latest' ) && is_file( __DIR__ . '/storage/analytics.php' ) ) {
		require_once __DIR__ . '/storage/analytics.php';
	}
	if ( ! class_exists( 'WPOPT_Performance_Logger' ) && is_file( __DIR__ . '/storage/class-performance-logger.php' ) ) {
		require_once __DIR__ . '/storage/class-performance-logger.php';
	}
	if ( ! function_exists( 'wpoptimizing_table_anomalies_get' ) && is_file( __DIR__ . '/storage/table-analytics.php' ) ) {
		require_once __DIR__ . '/storage/table-analytics.php';
	}
	if ( ! class_exists( 'WPOPT_Performance_Logger' ) || ! function_exists( 'wpoptimizing_analytics_latest' ) ) {
		return null;
	}
	$history = ( new WPOPT_Performance_Logger() )->read_history();
	if ( empty( $history ) ) {
		return null;
	}
	$latest   = wpoptimizing_analytics_latest( $history );
	$baseline = wpoptimizing_analytics_baseline( $history, (string) get_option( 'wpoptimizing_history_baseline_ts', '' ) );
	$now_m    = isset( $latest['metrics'] ) && is_array( $latest['metrics'] ) ? $latest['metrics'] : array();
	$base_m   = isset( $baseline['metrics'] ) && is_array( $baseline['metrics'] ) ? $baseline['metrics'] : array();

	$score_now = function_exists( 'wpoptimizing_health_score' ) ? (int) wpoptimizing_health_score( $now_m )['score'] : 0;
	$score_was = function_exists( 'wpoptimizing_health_score' ) ? (int) wpoptimizing_health_score( $base_m )['score'] : $score_now;

	// Headline metric rows: current value + change since the baseline. TTFB and cache hit rate
	// lead (the performance signals the user asked to surface), then the DB/media metrics.
	$rows = array();
	foreach ( array( 'server_ttfb_ms', 'cache_hit_rate', 'autoload_bytes', 'db_options', 'db_transients', 'orphan_db', 'orphan_fs', 'media_dupes_bytes' ) as $key ) {
		if ( ! array_key_exists( $key, $now_m ) ) {
			continue;
		}
		$meta   = wpoptimizing_report_metric_meta( $key );
		$now    = (float) $now_m[ $key ];
		$was    = isset( $base_m[ $key ] ) ? (float) $base_m[ $key ] : null;
		$rows[] = array(
			'label'         => $meta['label'],
			'value'         => wpoptimizing_report_format_value( $now, $meta['unit'] ),
			'delta'         => ( null !== $was ) ? ( $now - $was ) : null,
			'unit'          => $meta['unit'],
			'higher_better' => $meta['higher_better'],
		);
	}

	// Activity since the previous report (scans + cleanups).
	$last_sent = (int) get_option( WPOPT_REPORT_LAST_OPTION, 0 );
	$scans     = 0;
	$cleanups  = 0;
	foreach ( $history as $snap ) {
		$ts = isset( $snap['ts'] ) ? (int) strtotime( (string) $snap['ts'] . ' UTC' ) : 0;
		if ( $last_sent > 0 && $ts > 0 && $ts <= $last_sent ) {
			continue;
		}
		$phase = isset( $snap['phase'] ) ? (string) $snap['phase'] : '';
		if ( 'after_scan' === $phase ) {
			++$scans;
		} elseif ( 'after_clean' === $phase ) {
			++$cleanups;
		}
	}

	// Space freed by cleanups since the previous report (sum of the general-cleanup reports).
	$freed_bytes = 0;
	foreach ( (array) wpoptimizing_blob_get( 'cleanup-reports', 'wpoptimizing_general_cleanup_reports', array() ) as $rep ) {
		if ( ! is_array( $rep ) ) {
			continue;
		}
		$ended = isset( $rep['ended_at'] ) ? (int) strtotime( (string) $rep['ended_at'] ) : 0;
		if ( $last_sent > 0 && $ended > 0 && $ended <= $last_sent ) {
			continue;
		}
		$freed_bytes += (int) ( $rep['stats']['deleted_records_size_bytes'] ?? 0 );
	}

	$anom = function_exists( 'wpoptimizing_table_anomalies_get' ) ? wpoptimizing_table_anomalies_get() : array( 'items' => array() );

	return array(
		'score_now'   => $score_now,
		'score_was'   => $score_was,
		'rows'        => $rows,
		'scans'       => $scans,
		'cleanups'    => $cleanups,
		'freed_bytes' => $freed_bytes,
		'anomalies'   => isset( $anom['items'] ) ? $anom['items'] : array(),
		'freq'        => wpoptimizing_report_frequency(),
		'last_scan'   => isset( $latest['ts'] ) ? (string) $latest['ts'] : '',
	);
}

/**
 * The email subject for a report.
 *
 * @param array $data Collected data.
 * @return string
 */
function wpoptimizing_report_subject( array $data ) {
	$brand = function_exists( 'wpoptimizing_brand_name' ) ? wpoptimizing_brand_name() : 'WPOptimizing';
	$site  = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	return sprintf(
		/* translators: 1: brand, 2: site name, 3: health score. */
		__( '%1$s report for %2$s — health %3$d/100', 'wpoptimizing' ),
		$brand,
		$site,
		(int) $data['score_now']
	);
}

/**
 * Build the HTML email for a report. Reuses the same inline-styled shell as the network alert
 * so both emails look identical. Self-contained (inline styles) for email-client robustness.
 *
 * @param array $data Collected data.
 * @return string
 */
function wpoptimizing_report_email_html( array $data ) {
	$brand  = function_exists( 'wpoptimizing_brand_name' ) ? wpoptimizing_brand_name() : 'WPOptimizing';
	$url    = admin_url( 'admin.php?page=wpoptimizing&tab=history' );
	$site   = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	$score  = (int) $data['score_now'];
	$accent = $score >= 75 ? '#1a7a34' : ( $score >= 50 ? '#bd8600' : '#b32d2e' );

	$freq_labels = wpoptimizing_report_frequencies();
	$period      = isset( $freq_labels[ $data['freq'] ] ) ? $freq_labels[ $data['freq'] ] : '';

	// Metrics rows.
	$mrows = '';
	foreach ( (array) $data['rows'] as $r ) {
		$trend = '';
		if ( null !== $r['delta'] && abs( (float) $r['delta'] ) > 0 ) {
			$down = (float) $r['delta'] < 0;
			// Improvement is "smaller" for most metrics, but "larger" for higher-is-better ones
			// (cache hit rate), so the green/red is picked from that, not just the direction.
			$improved = ! empty( $r['higher_better'] ) ? ! $down : $down;
			$col      = $improved ? '#1a7a34' : '#b32d2e';
			$arrow    = $down ? '▼' : '▲';
			$trend    = '<span style="color:' . $col . ';font-size:12px;white-space:nowrap"> ' . $arrow . ' '
				. esc_html( wpoptimizing_report_format_value( abs( (float) $r['delta'] ), $r['unit'] ) ) . '</span>';
		}
		$mrows .= '<tr>'
			. '<td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#1d2327">' . esc_html( $r['label'] ) . '</td>'
			. '<td align="right" style="padding:8px 10px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#1d2327;font-weight:700;white-space:nowrap">' . esc_html( $r['value'] ) . $trend . '</td>'
			. '</tr>';
	}

	// Anomalous table growth (D4).
	$anom_html = '';
	if ( ! empty( $data['anomalies'] ) ) {
		$rows = '';
		foreach ( array_slice( (array) $data['anomalies'], 0, 8 ) as $a ) {
			$rows .= '<tr>'
				. '<td style="padding:8px 10px;border-bottom:1px solid #f0f0f0;font-size:13px;font-family:monospace;color:#1d2327">' . esc_html( (string) $a['name'] ) . '</td>'
				. '<td align="right" style="padding:8px 10px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#b32d2e;font-weight:700;white-space:nowrap">+' . esc_html( size_format( max( 0, (int) $a['delta_bytes'] ), 1 ) ) . '</td>'
				. '</tr>';
		}
		$anom_html = '<tr><td style="padding:20px 28px 0">'
			. '<h2 style="margin:0 0 4px;font-size:15px;color:#1d2327">' . esc_html__( '⚠️ Unusual table growth', 'wpoptimizing' ) . '</h2>'
			. '<p style="margin:0 0 12px;font-size:13px;color:#787c82">' . esc_html__( 'These tables grew abnormally between scans — often a runaway log or cache table.', 'wpoptimizing' ) . '</p>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $rows . '</table></td></tr>';
	}

	$activity = sprintf(
		/* translators: 1: number of scans, 2: number of cleanups. */
		esc_html__( '%1$d scans and %2$d cleanups since the last report.', 'wpoptimizing' ),
		(int) $data['scans'],
		(int) $data['cleanups']
	);
	if ( (int) ( $data['freed_bytes'] ?? 0 ) > 0 ) {
		$activity .= ' ' . sprintf(
			/* translators: %s: amount of disk space freed (e.g. "2.4 MB"). */
			esc_html__( 'Space freed: %s.', 'wpoptimizing' ),
			esc_html( size_format( (int) $data['freed_bytes'], 1 ) )
		);
	}

	// Score + label + delta (the score keeps its colour inside the body; the shared header uses
	// the brand colour so every email matches).
	$sd       = (int) $data['score_now'] - (int) $data['score_was'];
	$delta_ln = ( 0 !== $sd )
		? ' · ' . esc_html( sprintf( /* translators: %s: signed points vs baseline. */ __( '%s vs baseline', 'wpoptimizing' ), ( $sd > 0 ? '+' : '' ) . $sd ) )
		: '';

	$body  = '<tr><td style="padding:22px 28px 0">'
		. '<p style="margin:0 0 2px;font-size:14px;color:#1d2327">' . esc_html( $site ) . '</p>'
		. '<p style="margin:0;font-size:34px;font-weight:800;color:' . esc_attr( $accent ) . ';line-height:1.1">' . (int) $score . '<span style="font-size:16px;color:#787c82;font-weight:600">/100</span></p>'
		. '<p style="margin:2px 0 0;font-size:13px;color:#787c82">' . esc_html( wpoptimizing_report_score_label( $score ) ) . $delta_ln . '</p>'
		. '</td></tr>';
	$body .= '<tr><td style="padding:18px 28px 0">'
		. '<h2 style="margin:0 0 8px;font-size:15px;color:#1d2327">' . esc_html__( 'Key metrics', 'wpoptimizing' ) . '</h2>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $mrows . '</table>'
		. '</td></tr>';
	$body .= $anom_html;
	$body .= '<tr><td style="padding:16px 28px 0;font-size:13px;color:#787c82">' . $activity . '</td></tr>';

	$settings_url = admin_url( 'admin.php?page=wpoptimizing-settings' );
	$footer       = sprintf(
		/* translators: 1: brand name, 2: linked "WPOptimizing → Settings". */
		esc_html__( 'Sent by %1$s. Change the frequency or turn this off in %2$s.', 'wpoptimizing' ),
		esc_html( $brand ),
		'<a href="' . esc_url( $settings_url ) . '" style="color:#a7aaad">' . esc_html__( 'WPOptimizing → Settings', 'wpoptimizing' ) . '</a>'
	);

	return wpoptimizing_email_html(
		array(
			'subtitle'  => $period ? sprintf( /* translators: %s: frequency label. */ __( '%s report', 'wpoptimizing' ), $period ) : __( 'Maintenance report', 'wpoptimizing' ),
			'body'      => $body,
			'cta_url'   => $url,
			'cta_label' => __( 'Open the dashboard', 'wpoptimizing' ),
			'footer'    => $footer,
		)
	);
}

/**
 * Build and send the report to the resolved recipients. Records the send time. Returns true
 * when an email was actually dispatched.
 *
 * @param bool $force Send regardless of due-ness (for the "send test now" button).
 * @return bool
 */
function wpoptimizing_report_send( $force = false ) {
	$data = wpoptimizing_report_collect();
	if ( null === $data ) {
		return false;
	}
	$recipients = function_exists( 'wpoptimizing_notification_recipients' )
		? wpoptimizing_notification_recipients()
		: array_filter( array( get_option( 'admin_email' ) ), 'is_email' );
	if ( empty( $recipients ) ) {
		return false;
	}
	$ok = wp_mail(
		$recipients,
		wpoptimizing_report_subject( $data ),
		wpoptimizing_report_email_html( $data ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
	if ( ! $force ) {
		update_option( WPOPT_REPORT_LAST_OPTION, time(), false );
	}
	return (bool) $ok;
}

/**
 * Daily cron: send the report when it is due for the configured frequency.
 *
 * @return void
 */
function wpoptimizing_report_cron_run() {
	$freq = wpoptimizing_report_frequency();
	if ( 'off' === $freq ) {
		return;
	}
	if ( wpoptimizing_report_is_due( $freq, (int) get_option( WPOPT_REPORT_LAST_OPTION, 0 ), time() ) ) {
		wpoptimizing_report_send();
	}
}
add_action( 'wpoptimizing_maintenance_report', 'wpoptimizing_report_cron_run' );

/**
 * Keep the daily cron scheduled while a frequency is set, and cleared when off. Cheap; hooked
 * to admin_init.
 *
 * @return void
 */
function wpoptimizing_report_arm() {
	$on        = 'off' !== wpoptimizing_report_frequency();
	$scheduled = wp_next_scheduled( 'wpoptimizing_maintenance_report' );
	if ( $on && ! $scheduled ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpoptimizing_maintenance_report' );
	} elseif ( ! $on && $scheduled ) {
		wp_unschedule_event( $scheduled, 'wpoptimizing_maintenance_report' );
	}
}
add_action( 'admin_init', 'wpoptimizing_report_arm' );
