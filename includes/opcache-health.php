<?php
/**
 * OPcache: a diagnosis somebody can act on, instead of a percentage.
 *
 * WHY THIS REPLACES A HIT RATE. The old advice looked at opcache_hit_rate and, below 90%,
 * told the user to raise memory_consumption and max_accelerated_files. On the site that
 * prompted this it was wrong twice over. The hit rate is CUMULATIVE SINCE THE SHARED MEMORY
 * SEGMENT STARTED, so a few minutes after a restart it means nothing at all — and on that
 * server the segment was being rebuilt constantly, because with the LiteSpeed SAPI OPcache
 * dies with the last lsphp process of the account. No amount of memory fixes that. The fix
 * was the process manager's idle timeout, and we were pointing at php.ini.
 *
 * So the question is never "is the hit rate low". It is "WHY is it low", and OPcache answers
 * that itself if you read the rest of the array:
 *
 *   oom_restarts   → it ran out of memory          → memory_consumption
 *   hash_restarts  → it ran out of script slots    → max_accelerated_files
 *   wasted memory  → churn from changing files     → usually normal after a deploy
 *   interned full  → string buffer too small       → interned_strings_buffer
 *   short uptime   → it just started               → say nothing yet
 *   short uptime,  → the process pool is recycling → the SAPI, not php.ini
 *   repeatedly
 *
 * Pure: takes arrays, returns findings. The caller supplies opcache_get_status( false ),
 * opcache_get_configuration()['directives'] and the SAPI name.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * How long a segment must have been up before its numbers mean anything, in seconds.
 *
 * Below this the hit rate is an artefact of the warm-up, not a property of the server:
 * every file is a miss the first time by definition.
 *
 * @since 0.1.0
 * @return int
 */
function wpoptimizing_opcache_warmup_seconds() {
	/**
	 * Filters the OPcache warm-up window.
	 *
	 * @since 0.1.0
	 * @param int $seconds Seconds before OPcache statistics are considered meaningful.
	 */
	return (int) apply_filters( 'wpoptimizing_opcache_warmup_seconds', 900 );
}

/**
 * SAPIs where the OPcache segment lives and dies with a pool of worker processes.
 *
 * On these, an idle timeout that reaps the last worker takes the compiled code with it, and
 * the cache is rebuilt from nothing on the next visit. More memory does not help; the process
 * manager's settings do.
 *
 * @since 0.1.0
 * @param string $sapi PHP_SAPI value.
 * @return bool
 */
function wpoptimizing_opcache_sapi_recycles( $sapi ) {
	return in_array( strtolower( (string) $sapi ), array( 'litespeed', 'cgi-fcgi', 'fpm-fcgi' ), true );
}

/**
 * Read the numbers that matter out of OPcache's own status array.
 *
 * @since 0.1.0
 * @param array $status opcache_get_status( false ).
 * @param array $directives opcache_get_configuration()['directives'], or empty.
 * @param int   $now        Current timestamp.
 * @return array Normalised metrics.
 */
function wpoptimizing_opcache_metrics( array $status, array $directives = array(), $now = 0 ) {
	$now   = $now > 0 ? (int) $now : time();
	$stats = (array) ( $status['opcache_statistics'] ?? array() );
	$mem   = (array) ( $status['memory_usage'] ?? array() );
	$str   = (array) ( $status['interned_strings_usage'] ?? array() );

	$start  = (int) ( $stats['start_time'] ?? 0 );
	$keys   = (int) ( $stats['num_cached_keys'] ?? 0 );
	$maxk   = (int) ( $stats['max_cached_keys'] ?? 0 );
	$buffer = (int) ( $str['buffer_size'] ?? 0 );

	return array(
		'enabled'         => ! empty( $status['opcache_enabled'] ),
		'full'            => ! empty( $status['cache_full'] ),
		'uptime'          => ( $start > 0 && $now > $start ) ? ( $now - $start ) : 0,
		'hit_rate'        => isset( $stats['opcache_hit_rate'] ) ? (float) $stats['opcache_hit_rate'] : 100.0,
		'scripts'         => (int) ( $stats['num_cached_scripts'] ?? 0 ),
		'keys'            => $keys,
		'max_keys'        => $maxk,
		'keys_ratio'      => $maxk > 0 ? ( $keys / $maxk ) : 0.0,
		'oom_restarts'    => (int) ( $stats['oom_restarts'] ?? 0 ),
		'hash_restarts'   => (int) ( $stats['hash_restarts'] ?? 0 ),
		'manual_restarts' => (int) ( $stats['manual_restarts'] ?? 0 ),
		'used_memory'     => (int) ( $mem['used_memory'] ?? 0 ),
		'free_memory'     => (int) ( $mem['free_memory'] ?? 0 ),
		'wasted_pct'      => (float) ( $mem['current_wasted_percentage'] ?? 0 ),
		'max_wasted_pct'  => (float) ( $directives['opcache.max_wasted_percentage'] ?? 5 ),
		'interned_ratio'  => $buffer > 0 ? ( (int) ( $str['used_memory'] ?? 0 ) / $buffer ) : 0.0,
	);
}

/**
 * What is wrong with OPcache on this server, if anything.
 *
 * Returns at most ONE finding. OPcache problems are not a checklist to work through — they
 * have a cause, and listing four symptoms of the same cause is how a report stops being read.
 * The order below is the order of causation: a segment that keeps restarting explains every
 * other number in the array, so it is answered first and the rest are not mentioned.
 *
 * @since 0.1.0
 * @param array $metrics       From wpoptimizing_opcache_metrics().
 * @param string $sapi         PHP_SAPI.
 * @param int    $restart_runs How many consecutive observations saw a DIFFERENT start time.
 * @return array{code:string,severity:string}|null
 */
function wpoptimizing_opcache_finding( array $metrics, $sapi = '', $restart_runs = 0 ) {
	if ( empty( $metrics['enabled'] ) ) {
		return array(
			'code'     => 'disabled',
			'severity' => 'high',
		);
	}

	// A segment that has been rebuilt on several consecutive page loads is not short of
	// memory: something is killing the processes that hold it. Said first, because every
	// other number below is a consequence of it.
	if ( $restart_runs >= 3 && wpoptimizing_opcache_sapi_recycles( $sapi ) ) {
		return array(
			'code'     => 'recycling',
			'severity' => 'high',
		);
	}

	// Too young to judge. NOT a finding — reporting one here is how a user is sent to their
	// host over a server that restarted twenty minutes ago.
	if ( $metrics['uptime'] > 0 && $metrics['uptime'] < wpoptimizing_opcache_warmup_seconds() ) {
		return null;
	}

	if ( $metrics['oom_restarts'] > 0 ) {
		return array(
			'code'     => 'oom',
			'severity' => 'high',
		);
	}

	if ( $metrics['hash_restarts'] > 0 || $metrics['keys_ratio'] >= 0.9 || ! empty( $metrics['full'] ) ) {
		return array(
			'code'     => 'slots',
			'severity' => 'high',
		);
	}

	if ( $metrics['interned_ratio'] >= 0.9 ) {
		return array(
			'code'     => 'interned',
			'severity' => 'medium',
		);
	}

	if ( $metrics['max_wasted_pct'] > 0 && $metrics['wasted_pct'] >= $metrics['max_wasted_pct'] ) {
		return array(
			'code'     => 'wasted',
			'severity' => 'medium',
		);
	}

	// Nothing structural, and the segment has been up long enough for the rate to mean
	// something. Only now is a low hit rate worth mentioning on its own.
	if ( $metrics['hit_rate'] < 90 ) {
		return array(
			'code'     => 'hit_rate',
			'severity' => 'medium',
		);
	}

	return null;
}

/**
 * Watch whether the OPcache segment is being rebuilt between page loads.
 *
 * One request cannot see this: `start_time` is just a number until you have another one to
 * compare it with. So each admin page load records the start time it saw, and counts how many
 * times in a row it has changed. Three consecutive changes is not a coincidence — the process
 * pool holding the cache is being reaped, and that is a completely different problem from
 * "OPcache needs more memory", with a completely different fix.
 *
 * One autoloaded option, written only when the value actually changes.
 *
 * @since 0.1.0
 * @param int $start_time The current segment's start time.
 * @return int Consecutive observations that saw a different start time.
 */
function wpoptimizing_opcache_observe_restarts( $start_time ) {
	$start = (int) $start_time;
	if ( $start <= 0 ) {
		return 0;
	}

	$seen = (array) get_option( 'wpoptimizing_opcache_seen', array() );
	$last = (int) ( $seen['start'] ?? 0 );
	$runs = (int) ( $seen['runs'] ?? 0 );

	if ( $last === $start ) {
		return $runs; // Same segment as last time: nothing to record, nothing to write.
	}

	// A first observation is not a restart — there is nothing to compare it with.
	$runs = ( $last > 0 ) ? ( $runs + 1 ) : 0;

	update_option(
		'wpoptimizing_opcache_seen',
		array(
			'start' => $start,
			'runs'  => $runs,
		),
		false
	);

	return $runs;
}

/**
 * The advice for a finding: what to change, and where.
 *
 * Separate from the diagnosis so the rules stay testable without WordPress, and so the
 * wording can change without touching them.
 *
 * @since 0.1.0
 * @param string $code From wpoptimizing_opcache_finding().
 * @param array  $metrics Metrics, for the numbers quoted in the text.
 * @return array{title:string,tip:string}|null
 */
function wpoptimizing_opcache_advice( $code, array $metrics = array() ) {
	switch ( $code ) {
		case 'disabled':
			return array(
				'title' => __( 'PHP OPcache is switched off', 'wpoptimizing' ),
				'tip'   => __( 'Without OPcache, PHP compiles every file of WordPress, your theme and every plugin on every single request. Enabling it is the single largest server-side improvement available, and it is a one-line change your host can make: opcache.enable=1.', 'wpoptimizing' ),
			);

		case 'recycling':
			return array(
				'title' => __( 'OPcache is being rebuilt constantly', 'wpoptimizing' ),
				'tip'   => __( 'The compiled-code cache has started over on several page loads in a row. On this kind of server it lives inside the PHP worker processes, so when the last one is closed for being idle it is thrown away and rebuilt from nothing for the next visitor. More memory will not help. Ask your host to stop PHP workers being recycled while idle — on LiteSpeed that is the external application\'s “Max Idle Time” (set it to -1) and PHP_LSAPI_MAX_REQUESTS.', 'wpoptimizing' ),
			);

		case 'oom':
			return array(
				'title' => __( 'OPcache keeps running out of memory', 'wpoptimizing' ),
				'tip'   => __( 'OPcache has restarted itself because it filled up, which throws away every compiled file and recompiles them from scratch. This is the one number that says so directly, and the fix is exact: raise opcache.memory_consumption (256M or more on a site with many plugins).', 'wpoptimizing' ),
			);

		case 'slots':
			return array(
				'title' => __( 'OPcache has run out of file slots', 'wpoptimizing' ),
				'tip'   => __( 'It is not short of memory — it is short of places to put files, so the ones that do not fit are recompiled on every request. Raise opcache.max_accelerated_files above the number of PHP files on the site (30000 or more is normal for WordPress with a page builder and a few dozen plugins).', 'wpoptimizing' ),
			);

		case 'interned':
			return array(
				'title' => __( 'OPcache\'s string buffer is full', 'wpoptimizing' ),
				'tip'   => __( 'Repeated strings are shared between compiled files to save memory, and that buffer is full, so the saving stops. Raise opcache.interned_strings_buffer (16 to 32 MB).', 'wpoptimizing' ),
			);

		case 'wasted':
			return array(
				'title' => __( 'OPcache memory is fragmenting', 'wpoptimizing' ),
				'tip'   => __( 'A large share of the cache is taken up by versions of files that have since changed. This is normal right after an update and clears itself on the next restart; if it stays high, files are being modified on every request — a plugin writing PHP, or a cache directory inside the site.', 'wpoptimizing' ),
			);

		case 'hit_rate':
			return array(
				'title' => __( 'OPcache is recompiling more than it should', 'wpoptimizing' ),
				'tip'   => __( 'Nothing is obviously misconfigured — the cache is not full, has not restarted and has been running long enough to judge — so this is worth watching rather than acting on immediately. If it stays low, the usual cause is files changing on disk during normal traffic.', 'wpoptimizing' ),
			);
	}

	return null;
}
