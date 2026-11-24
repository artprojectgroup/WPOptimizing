<?php
/**
 * Back-off for contribution uploads.
 *
 * Lives here and not in the main file so it can be unit-tested: the bootstrap loads includes/,
 * and wpoptimizing.php cannot be required standalone.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Exponential back-off for a contribution upload that keeps failing.
 *
 * The local CSV is deleted only on a 2xx, so a persistent failure means the next scheduled run
 * uploads the same file again — for ever. That is not a retry, it is a loop, and on
 * 2026-09-28 it was one: the same 7 MB upload every minute until ~500 abandoned temps filled
 * the gateway server's /tmp and took the whole machine down. A client has to give up.
 *
 * Waits 5, 10, 20, 40… minutes, capped at a day, and stops trying after
 * WPOPTIMIZING_UPLOAD_MAX_FAILURES consecutive failures until something resets it (a
 * successful upload, or the next manual sync).
 *
 * @param string $key Layer key, so one failing layer does not silence the others.
 * @return bool True when this layer should stay quiet right now.
 */
function wpoptimizing_upload_backoff_active( $key ) {
	$state = (array) get_option( 'wpoptimizing_upload_backoff', array() );
	$entry = isset( $state[ $key ] ) ? (array) $state[ $key ] : array();
	$until = isset( $entry['until'] ) ? (int) $entry['until'] : 0;

	return $until > time();
}

/**
 * Classify a gateway response. The ONE place that decides what an answer means.
 *
 * Some 4xx are flow control, not a fault: nothing was stored, nothing is wrong with what we
 * sent, and waiting resolves it. Treating them as faults punishes a healthy client — and for
 * 409 it punishes it precisely when the knowledge base is BUSY, so the more sites contribute
 * the more each one silences itself.
 *
 *   409  another site is merging this layer right now (gateway 9d2bb70)
 *   507  the gateway is out of disk for the temp file
 *   429  rate limited — the only one that announces a window, so the only one that waits
 *
 * AN HTML BODY MEANS THE GATEWAY NEVER SAW THE REQUEST, whatever the status says. Every
 * response the gateway emits is application/json, text/plain or text/csv — never HTML (the
 * gateway's own ShippedNeverHtmlTest holds it to that). So HTML is always something in FRONT
 * of it answering instead: the site in maintenance or development mode, a WAF, a host error
 * page, a parked domain. None of those are a fault in our file and none of them are permanent.
 *
 * That distinction is not academic. On 2026-09-30 wpoptimizing.com was in development mode and
 * answered 403 text/html to everything, including the gateway endpoint. Classified as a fault,
 * every contributing install would have burned its eight retries in about 21 hours and then
 * gone silent FOR A YEAR — over a switch somebody flipped for an afternoon. And an HTML page
 * served with a 200 (a cache, a captive portal) was worse still: "ok" deletes the local CSV,
 * so the contribution would have been destroyed without ever reaching the gateway.
 *
 * @param int    $code         HTTP status.
 * @param string $content_type Response Content-Type header, when available.
 * @param string $body         Response body, or enough of its start to recognise HTML.
 * @return string ok|transient|throttled|failed
 */
function wpoptimizing_upload_outcome( $code, $content_type = '', $body = '' ) {
	$code = (int) $code;

	// Checked BEFORE the status, including before 2xx: a 200 that did not come from the
	// gateway must never be treated as a stored contribution.
	if ( wpoptimizing_response_is_html( $content_type, $body ) ) {
		return 'transient';
	}

	if ( $code >= 200 && $code < 300 ) {
		return 'ok';
	}
	if ( 429 === $code ) {
		return 'throttled';
	}
	if ( 409 === $code || 507 === $code ) {
		return 'transient';
	}
	if ( $code >= 500 ) {
		return 'transient';
	}

	return 'failed';
}

/**
 * Did something other than the gateway answer?
 *
 * Two signals, because either can be missing: a maintenance page may arrive without a
 * Content-Type, and a HEAD-like empty body has no markup to look at. Neither is a
 * whitelist of known error pages — the test is "this is not what the gateway emits".
 *
 * @param string $content_type Response Content-Type header.
 * @param string $body         Response body.
 * @return bool
 */
function wpoptimizing_response_is_html( $content_type, $body ) {
	if ( '' !== (string) $content_type && false !== stripos( (string) $content_type, 'html' ) ) {
		return true;
	}

	$start = ltrim( (string) $body );

	return '' !== $start && '<' === $start[0];
}

/**
 * Record the outcome of an upload attempt and arm (or clear) the back-off.
 *
 * @param string $key Layer key.
 * @param bool   $ok  Whether the upload succeeded.
 * @return void
 */
function wpoptimizing_upload_record_attempt( $key, $ok ) {
	$state = (array) get_option( 'wpoptimizing_upload_backoff', array() );
	if ( $ok ) {
		unset( $state[ $key ] );
		update_option( 'wpoptimizing_upload_backoff', $state, false );
		return;
	}
	$fails = isset( $state[ $key ]['fails'] ) ? (int) $state[ $key ]['fails'] + 1 : 1;
	$max   = defined( 'WPOPTIMIZING_UPLOAD_MAX_FAILURES' ) ? (int) WPOPTIMIZING_UPLOAD_MAX_FAILURES : 8;
	$wait  = min( DAY_IN_SECONDS, 300 * ( 2 ** min( 8, $fails - 1 ) ) );
	if ( $fails >= $max ) {
		// Given up. Nothing retries until a success or a manual sync clears this.
		// A year, i.e. never again on its own. Only a success or a manual sync revives it.
		$wait = 365 * DAY_IN_SECONDS;
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log(
				'upload_gave_up',
				array(
					'layer'    => $key,
					'failures' => $fails,
				)
			);
		}
	}
	$state[ $key ] = array(
		'fails' => $fails,
		'until' => time() + $wait,
	);
	update_option( 'wpoptimizing_upload_backoff', $state, false );
}

/**
 * Give a layer that gave up uploading one more chance after an update.
 *
 * Eight consecutive failures silence a layer for a year, which is right when the client is
 * genuinely broken and wrong when the verdict was reached for the wrong reason. It has been
 * reached for the wrong reason: a site answering an HTML error page (maintenance or
 * development mode, a firewall) counted as a fault, so about 21 hours of that was enough to
 * silence an install permanently. New code means that verdict was passed by a version that no
 * longer exists, so it does not get to stand.
 *
 * ONLY the layers that GAVE UP are cleared, never a layer in its normal 5-10-20-minute cycle:
 * that cycle is flood protection doing its job, it expires on its own within hours, and
 * resetting it on every release is how a client starts hammering a gateway that is down.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_revive_abandoned_uploads() {
	$state = get_option( 'wpoptimizing_upload_backoff', array() );
	if ( ! is_array( $state ) || array() === $state ) {
		return;
	}

	$kept = array();
	foreach ( $state as $key => $entry ) {
		$until = isset( $entry['until'] ) ? (int) $entry['until'] : 0;
		// The give-up wait is a year; every ordinary wait is capped at a day. Anything more
		// than a month out can only be a layer that stopped trying.
		if ( $until - time() <= 30 * DAY_IN_SECONDS ) {
			$kept[ $key ] = $entry;
		}
	}

	if ( count( $kept ) === count( $state ) ) {
		return;
	}

	update_option( 'wpoptimizing_upload_backoff', $kept, false );
}

/**
 * Daily cap per layer: how many POSTs this site may send to the gateway per UTC day.
 *
 * Below the gateway's own 240 on purpose, so a site never learns its limit from a 429. And far
 * below it since 21/10/2026: every upload makes the gateway rewrite that layer for ALL sites,
 * so a cap sized for one busy producer (200) is no brake at all once there are a thousand
 * ordinary sites. A site's picture of itself changes a few times a day at most; whatever is
 * held stays on disk and goes up with the next allowed upload. A producer that really needs
 * more (the lab) raises it in its own wp-config.php.
 *
 * @return int
 */
function wpoptimizing_upload_daily_max() {
	return defined( 'WPOPTIMIZING_UPLOAD_DAILY_MAX' ) ? max( 1, (int) WPOPTIMIZING_UPLOAD_DAILY_MAX ) : 6;
}

/**
 * Minimum seconds between two POSTs of the same layer (a sync the user asked for skips it).
 *
 * @return int
 */
function wpoptimizing_upload_min_interval() {
	return defined( 'WPOPTIMIZING_UPLOAD_MIN_INTERVAL' ) ? max( 0, (int) WPOPTIMIZING_UPLOAD_MIN_INTERVAL ) : HOUR_IN_SECONDS;
}

/**
 * The gateway's whole-server brake answered: wait what it asks, and count nothing.
 *
 * Since gateway 2.3.26 a 429 says which one it is. `X-WPO-Throttle: global` means the SERVER is
 * busy with everybody's uploads: nothing is wrong with this site, nothing was stored and the
 * gateway did not spend this site's quota. Treated as a fault it would arm the back-off and, the
 * busier the knowledge base, the more sites would silence themselves. So the layer waits the
 * `Retry-After` (30-900 s, the range agreed with the alimentador) without a failure, the attempt
 * is taken back off the daily count, and the CSV stays for the next run. `quota`, or a 429
 * without the header (an older gateway), is still this site's own cap: the usual path.
 *
 * @param string         $key      Layer key.
 * @param array|WP_Error $response The gateway's response.
 * @return bool True when it was the global brake (the caller stops there).
 */
function wpoptimizing_upload_busy( $key, $response ) {
	if ( is_wp_error( $response ) || 429 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return false;
	}
	if ( 'global' !== strtolower( trim( (string) wp_remote_retrieve_header( $response, 'x-wpo-throttle' ) ) ) ) {
		return false;
	}
	$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
	$wait = min( 900, max( 30, $wait ) );

	$state = (array) get_option( 'wpoptimizing_upload_backoff', array() );
	$entry = isset( $state[ $key ] ) ? (array) $state[ $key ] : array();

	// The failure count stays as it was: only the wait changes.
	$entry['until'] = max( (int) ( $entry['until'] ?? 0 ), time() + $wait );
	$state[ $key ]  = $entry;
	update_option( 'wpoptimizing_upload_backoff', $state, false );

	$all = (array) get_option( 'wpoptimizing_upload_budget', array() );
	if ( isset( $all[ $key ]['n'] ) && (int) $all[ $key ]['n'] > 0 ) {
		$all[ $key ]['n'] = (int) $all[ $key ]['n'] - 1;
		update_option( 'wpoptimizing_upload_budget', $all, false );
	}
	return true;
}

/**
 * Was exactly this content already accepted for this layer, recently?
 *
 * The client re-sent its layers after every analysis whether they had changed or not, and each
 * one costs the gateway a rewrite of that layer for every site (21/10/2026: artprojectgroup.es,
 * 3 of 8 uploads in a day identical to an earlier one). Recently, because the gateway can lose
 * what it got (a purge, a restore): once a week the same content goes up anyway.
 *
 * @param string $key    Layer key.
 * @param string $hash   md5 of the body about to be sent.
 * @param bool   $manual A sync the user asked for always sends.
 * @return bool True when the upload can be skipped.
 */
function wpoptimizing_upload_unchanged( $key, $hash, $manual = false ) {
	if ( $manual || '' === (string) $hash ) {
		return false;
	}
	$all   = (array) get_option( 'wpoptimizing_upload_sent', array() );
	$entry = isset( $all[ $key ] ) ? (array) $all[ $key ] : array();
	return isset( $entry['h'], $entry['t'] )
		&& hash_equals( (string) $entry['h'], (string) $hash )
		&& time() - (int) $entry['t'] < WEEK_IN_SECONDS;
}

/**
 * The fingerprint of an upload body, independent of the order of its rows.
 *
 * The rows carry no dates or counters, but their ORDER follows the code scanner's walk, and
 * RecursiveDirectoryIterator returns files in whatever order the filesystem keeps them, which
 * moves when a cache file appears or a plugin updates. The same rows in another order are the
 * same contribution; hashing the raw body would send them again. A header stays first.
 *
 * @param string $body       CSV body.
 * @param bool   $has_header Whether the first line is a header.
 * @return string md5.
 */
function wpoptimizing_upload_body_hash( $body, $has_header = false ) {
	$lines  = preg_split( '/\r\n|\n|\r/', rtrim( (string) $body, "\r\n" ) );
	$lines  = is_array( $lines ) ? $lines : array();
	$header = $has_header ? (string) array_shift( $lines ) : '';
	sort( $lines, SORT_STRING );
	return md5( $header . "\n" . implode( "\n", $lines ) );
}

/**
 * Remember what the gateway accepted for a layer. Called only on an accepted upload.
 *
 * @param string $key  Layer key.
 * @param string $hash md5 of the body that was accepted.
 * @return void
 */
function wpoptimizing_upload_remember( $key, $hash ) {
	$all         = (array) get_option( 'wpoptimizing_upload_sent', array() );
	$all[ $key ] = array(
		'h' => (string) $hash,
		't' => time(),
	);
	update_option( 'wpoptimizing_upload_sent', $all, false );
}

/**
 * May this layer be uploaded now? The single gate every contribution upload goes through.
 *
 * The back-off above only brakes a layer that keeps FAILING. A layer that keeps CHANGING has
 * something new each time and sails through it: on 2026-10-10 the lab's `autoload`, sent on
 * every 66-second cycle because the options it describes change that often, plausibly used up
 * the gateway's 240 a day before 04:30 UTC. A cap and a minimum spacing brake that case too.
 *
 * @param string $key    Layer key.
 * @param bool   $manual True for a sync the user asked for: it skips the spacing (they want it
 *                       sent now), never the daily cap (that is what protects the gateway).
 * @return string '' when the layer may go; otherwise 'backoff', 'budget' or 'wait'.
 */
function wpoptimizing_upload_gate( $key, $manual = false ) {
	if ( wpoptimizing_upload_backoff_active( $key ) ) {
		return 'backoff';
	}
	$all   = (array) get_option( 'wpoptimizing_upload_budget', array() );
	$entry = isset( $all[ $key ] ) ? (array) $all[ $key ] : array();
	$today = gmdate( 'Y-m-d' );
	if ( isset( $entry['dia'] ) && $today === $entry['dia'] && (int) ( $entry['n'] ?? 0 ) >= wpoptimizing_upload_daily_max() ) {
		return 'budget';
	}
	if ( ! $manual && isset( $entry['enviado'] ) && time() - (int) $entry['enviado'] < wpoptimizing_upload_min_interval() ) {
		return 'wait';
	}
	return '';
}

/**
 * Note that a POST of this layer was made. Called right after wp_remote_post(), whatever came back.
 *
 * It counts ATTEMPTS, not acceptances, because that is what the gateway counts: every POST that
 * reaches it spends one of its 240, a rejected one included. Counting only what was accepted is
 * how, on 2026-10-10, a producer reported 54 uploads of a layer and could not explain a 429. A
 * connection failure (no response) still restarts the spacing, but does not spend the cap: the
 * gateway may never have seen it.
 *
 * @param string $key          Layer key.
 * @param bool   $had_response Whether the gateway answered at all.
 * @return void
 */
function wpoptimizing_upload_count( $key, $had_response ) {
	$all   = (array) get_option( 'wpoptimizing_upload_budget', array() );
	$entry = isset( $all[ $key ] ) ? (array) $all[ $key ] : array();
	$today = gmdate( 'Y-m-d' );
	if ( ! isset( $entry['dia'] ) || $today !== $entry['dia'] ) {
		$entry = array(
			'dia' => $today,
			'n'   => 0,
		);
	}
	if ( $had_response ) {
		$entry['n'] = (int) ( $entry['n'] ?? 0 ) + 1;
	}
	$entry['enviado'] = time();
	$all[ $key ]      = $entry;
	update_option( 'wpoptimizing_upload_budget', $all, false );
}

/**
 * May this site try to register with the gateway now?
 *
 * Registration ran on demand with no brake at all: a site whose stored token was missing, or
 * bound to another domain (a URL change, a clone, a migration), re-registered on EVERY call
 * that needed a token — every upload, every download attempt. The gateway allows 5 a day per
 * site and 50 a day per IP, and the per-IP counter is checked first and spends even on the
 * attempts the per-site one then refuses. So one migrated site, retrying, used up the IP's 50
 * within the hour and left every other site behind the same address (shared hosting) unable
 * to register until the next UTC day — with nothing on screen but "no token".
 *
 * Now: at most WPOPTIMIZING_REGISTER_DAILY_MAX attempts per UTC day (4, one under the
 * gateway's 5), 30 minutes apart, and none at all for the rest of the UTC day after a 429.
 *
 * @return string '' when it may try; otherwise 'wait', 'budget' or 'throttled'.
 */
function wpoptimizing_register_gate() {
	$state = (array) get_option( 'wpoptimizing_register_budget', array() );
	$today = gmdate( 'Y-m-d' );
	if ( isset( $state['bloqueado'] ) && $today === $state['bloqueado'] ) {
		return 'throttled';
	}
	$max = defined( 'WPOPTIMIZING_REGISTER_DAILY_MAX' ) ? max( 1, (int) WPOPTIMIZING_REGISTER_DAILY_MAX ) : 4;
	if ( isset( $state['dia'] ) && $today === $state['dia'] && (int) ( $state['n'] ?? 0 ) >= $max ) {
		return 'budget';
	}
	if ( isset( $state['intento'] ) && time() - (int) $state['intento'] < 30 * MINUTE_IN_SECONDS ) {
		return 'wait';
	}
	return '';
}

/**
 * Note a registration attempt. Called right after the request, whatever came back.
 *
 * Counts every attempt the gateway answered, as the gateway does; a connection failure only
 * restarts the spacing. A 429 closes registration for the rest of the UTC day: the gateway's
 * counters are per UTC day and send no Retry-After, so nothing tried before midnight can work.
 *
 * @param int $code HTTP status, or 0 when there was no response.
 * @return void
 */
function wpoptimizing_register_note( $code ) {
	$state = (array) get_option( 'wpoptimizing_register_budget', array() );
	$today = gmdate( 'Y-m-d' );
	if ( ! isset( $state['dia'] ) || $today !== $state['dia'] ) {
		$state['dia'] = $today;
		$state['n']   = 0;
	}
	if ( (int) $code > 0 ) {
		$state['n'] = (int) ( $state['n'] ?? 0 ) + 1;
	}
	if ( 429 === (int) $code ) {
		$state['bloqueado'] = $today;
	}
	$state['intento'] = time();
	update_option( 'wpoptimizing_register_budget', $state, false );
}


/**
 * Minimum seconds between two downloads of the same layer (a 304 counts as one).
 *
 * The shared knowledge base changes slowly and an attribution a day old is still right, so
 * once a day is plenty; it is under a day so a site's daily sync never lands just short of it.
 *
 * @return int
 */
function wpoptimizing_kb_download_min_interval() {
	return defined( 'WPOPTIMIZING_KB_DOWNLOAD_MIN_INTERVAL' ) ? max( 0, (int) WPOPTIMIZING_KB_DOWNLOAD_MIN_INTERVAL ) : 20 * HOUR_IN_SECONDS;
}
