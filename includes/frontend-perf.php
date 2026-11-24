<?php
/**
 * Front-end performance features inspired by the WordPress Performance Lab:
 *   - Speculative Loading (Speculation Rules API: prefetch/prerender on navigation).
 *   - Server-Timing diagnostic header (TTFB-ish, DB queries, object-cache hit rate).
 *   - Content image loading optimization (fetchpriority on the LCP candidate,
 *     lazy-load + async decoding for the rest), via WP_HTML_Tag_Processor.
 *
 * All opt-in and reversible from Settings. These run on the FRONT END, so this file
 * is loaded unconditionally (the engine classes are NOT) — every callback is cheap
 * and short-circuits unless its feature is enabled.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read the front-end performance settings with safe defaults.
 *
 * @return array{speculation:string,server_timing:int,image_opt:int}
 */
function wpoptimizing_frontend_perf_settings() {
	$raw = get_option( 'wpoptimizing_frontend_perf', array() );
	return wpoptimizing_frontend_perf_sanitize( is_array( $raw ) ? $raw : array() );
}

/**
 * Sanitize a raw front-end-performance settings array. Pure (unit-testable).
 *
 * @param array $raw Raw values.
 * @return array{speculation:string,server_timing:int,image_opt:int,resource_hints:int}
 */
function wpoptimizing_frontend_perf_sanitize( array $raw ) {
	$mode = isset( $raw['speculation'] ) ? (string) $raw['speculation'] : 'off';
	if ( ! in_array( $mode, array( 'off', 'conservative', 'eager' ), true ) ) {
		$mode = 'off';
	}
	$preload_urls = array();
	if ( isset( $raw['preload_urls'] ) ) {
		$list = is_array( $raw['preload_urls'] ) ? $raw['preload_urls'] : preg_split( '/[\r\n]+/', (string) $raw['preload_urls'] );
		foreach ( (array) $list as $u ) {
			$u = esc_url_raw( trim( (string) $u ) );
			if ( '' !== $u ) {
				$preload_urls[] = $u;
			}
		}
		$preload_urls = array_values( array_unique( $preload_urls ) );
	}
	return array(
		'speculation'    => $mode,
		'server_timing'  => empty( $raw['server_timing'] ) ? 0 : 1,
		'image_opt'      => empty( $raw['image_opt'] ) ? 0 : 1,
		'resource_hints' => empty( $raw['resource_hints'] ) ? 0 : 1,
		'google_fonts'   => empty( $raw['google_fonts'] ) ? 0 : 1,
		'preload'        => empty( $raw['preload'] ) ? 0 : 1,
		'preload_urls'   => $preload_urls,
		'lazy_embeds'    => empty( $raw['lazy_embeds'] ) ? 0 : 1,
		'image_opt_page' => empty( $raw['image_opt_page'] ) ? 0 : 1,
		// Images never deferred at the top of the document: -1 = automatic (the first image of
		// the content, the first three of the whole page: what it always did), else 0-5.
		'image_eager'    => wpoptimizing_image_eager_sanitize( $raw['image_eager'] ?? null ),
		// High fetch priority for the likely LCP image. On unless switched off: it is what the
		// image pass always did, and settings saved before the switch existed do not carry it.
		'image_lcp'      => array_key_exists( 'image_lcp', $raw ) ? ( empty( $raw['image_lcp'] ) ? 0 : 1 ) : 1,
		'lazy_exclude'   => wpoptimizing_lazy_exclude_sanitize( $raw['lazy_exclude'] ?? array() ),
	);
}

/**
 * «Images never deferred at the top», sanitized. Pure.
 *
 * @param mixed $value Raw value.
 * @return int -1 (automatic) or 0-5.
 */
function wpoptimizing_image_eager_sanitize( $value ) {
	if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
		$n = (int) $value;
		return ( $n >= 0 && $n <= 5 ) ? $n : -1;
	}
	return -1;
}

/**
 * The lazy-load exclusions, sanitized: fragments of a class or a file name, one per line. Pure.
 *
 * @param mixed $value Array or newline-separated text.
 * @return string[]
 */
function wpoptimizing_lazy_exclude_sanitize( $value ) {
	$list = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', is_scalar( $value ) ? (string) $value : '' );
	$out  = array();
	foreach ( (array) $list as $item ) {
		if ( ! is_scalar( $item ) ) {
			continue;
		}
		$item = trim( sanitize_text_field( (string) $item ) );
		if ( '' !== $item && strlen( $item ) <= 200 ) {
			$out[] = $item;
		}
	}
	return array_slice( array_values( array_unique( $out ) ), 0, 100 );
}

/**
 * Build the Speculation Rules document for a given mode. Pure (unit-testable).
 *
 * conservative → prefetch on pointerdown; eager → prerender on hover. Excludes the admin,
 * login and other wp-*.php endpoints, wp-content (uploads, plugins, themes), URLs with a query
 * string, the shop's cart/checkout/account pages, nofollow links and any link opted out with
 * .no-prerender / .no-prefetch. Only used on WordPress before 6.8; from 6.8 core prints the
 * rules and we only tune them (see below).
 *
 * @param string        $mode  'off'|'conservative'|'eager'.
 * @param string        $base  Site path with trailing slash ('/' or '/blog/'): every pattern is
 *                             rooted there, or a subdirectory install excluded nothing.
 * @param string[]|null $extra Extra paths relative to $base (the shop pages). Null = the
 *                             English WooCommerce defaults, for callers that cannot ask.
 * @return array Empty array when off (nothing should be printed).
 */
function wpoptimizing_build_speculation_rules( $mode, $base = '/', $extra = null ) {
	if ( ! in_array( $mode, array( 'conservative', 'eager' ), true ) ) {
		return array();
	}
	$base      = '/' . trim( (string) $base, '/' ) . '/';
	$base      = '//' === $base ? '/' : $base;
	$action    = ( 'eager' === $mode ) ? 'prerender' : 'prefetch';
	$eagerness = ( 'eager' === $mode ) ? 'moderate' : 'conservative';
	$extra     = null === $extra ? array( 'cart/*', 'checkout/*', 'my-account/*' ) : (array) $extra;
	$exclude   = array( $base . 'wp-admin/*', $base . 'wp-*.php', $base . 'wp-content/*', '/*\\?*' );
	foreach ( $extra as $path ) {
		$exclude[] = $base . ltrim( (string) $path, '/' );
	}

	$rule = array(
		'source'    => 'document',
		'where'     => array(
			'and' => array(
				array( 'href_matches' => $base . '*' ),
				array( 'not' => array( 'href_matches' => array_values( array_unique( $exclude ) ) ) ),
				array( 'not' => array( 'selector_matches' => 'a[rel~="nofollow"]' ) ),
				array( 'not' => array( 'selector_matches' => '.no-prerender, .no-prefetch' ) ),
			),
		),
		'eagerness' => $eagerness,
	);

	return array( $action => array( $rule ) );
}

/**
 * The shop pages that must never be prefetched, as paths relative to the site root, read from
 * WooCommerce itself. Fixed English slugs let a Spanish shop prerender /carrito/ and
 * /finalizar-compra/ on hover — firing cart and checkout code for a visitor who never clicked.
 *
 * @return string[]
 */
function wpoptimizing_speculation_shop_paths() {
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return array();
	}
	// Resolving three permalinks can cost three queries, and this runs on visitor pages: keep the
	// answer in an autoloaded option keyed by everything it depends on (the page ids and the
	// permalink structure are autoloaded options themselves, so checking the key is free).
	// One entry PER signature, not one for the site: WPML and Polylang give every language its
	// own home URL and its own cart/checkout pages, so a single slot was rewritten on every
	// visit that switched language — an UPDATE of an autoloaded option, alloptions included.
	$sig    = md5( (string) get_option( 'woocommerce_cart_page_id' ) . '|' . (string) get_option( 'woocommerce_checkout_page_id' ) . '|' . (string) get_option( 'woocommerce_myaccount_page_id' ) . '|' . (string) get_option( 'permalink_structure' ) . '|' . home_url( '/' ) );
	$cached = get_option( 'wpoptimizing_spec_shop_paths' );
	$by_sig = is_array( $cached ) && isset( $cached['by_sig'] ) && is_array( $cached['by_sig'] ) ? $cached['by_sig'] : array();
	if ( isset( $by_sig[ $sig ] ) && is_array( $by_sig[ $sig ] ) ) {
		return $by_sig[ $sig ];
	}
	$home  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$paths = array();
	foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
		$path = (string) wp_parse_url( (string) wc_get_page_permalink( $page ), PHP_URL_PATH );
		if ( '' !== $path && '/' !== $path && 0 === strpos( $path, $home ) ) {
			$paths[] = trim( substr( $path, strlen( $home ) ), '/' ) . '/*';
		}
	}
	update_option( 'wpoptimizing_spec_shop_paths', array( 'by_sig' => wpoptimizing_speculation_shop_paths_store( $by_sig, $sig, $paths ) ), true );
	return $paths;
}

/**
 * Add one signature's paths to the stored set, keeping the most recent few. Pure.
 *
 * Nine languages is the largest site this plugin knows; the cap only stops a permalink
 * structure changed again and again from growing the option without end.
 *
 * @since 0.1.0
 * @param array    $by_sig Stored signature => paths.
 * @param string   $sig    Signature.
 * @param string[] $paths  Paths for it.
 * @param int      $cap    Entries kept.
 * @return array
 */
function wpoptimizing_speculation_shop_paths_store( array $by_sig, $sig, array $paths, $cap = 16 ) {
	unset( $by_sig[ (string) $sig ] );
	$by_sig[ (string) $sig ] = array_values( $paths );
	return array_slice( $by_sig, -max( 1, (int) $cap ), null, true );
}

/**
 * Print the speculation-rules script in the front-end head — only on WordPress before 6.8.
 * From 6.8 core prints its own, and printing ours too gave the page two sets of rules.
 *
 * @return void
 */
function wpoptimizing_print_speculation_rules() {
	if ( is_admin() || function_exists( 'wp_get_speculation_rules_configuration' ) || is_user_logged_in() ) {
		return; // Logged in: same as core — a prefetched admin-bar link is a wasted request.
	}
	$base  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
	$rules = wpoptimizing_build_speculation_rules( wpoptimizing_frontend_perf_settings()['speculation'], '' === $base ? '/' : $base, function_exists( 'wc_get_page_permalink' ) ? wpoptimizing_speculation_shop_paths() : null );
	if ( empty( $rules ) ) {
		return;
	}
	wp_print_inline_script_tag( (string) wp_json_encode( $rules ), array( 'type' => 'speculationrules' ) );
}
add_action( 'wp_head', 'wpoptimizing_print_speculation_rules' );

/**
 * WordPress 6.8+: set the mode and eagerness of core's own rules instead of adding a second set.
 * Core already roots them at the site path, skips logged-in users and excludes its endpoints.
 *
 * @param array|null $config Core's configuration, or null when core disabled it.
 * @return array|null
 */
function wpoptimizing_speculation_core_config( $config ) {
	$mode = wpoptimizing_frontend_perf_settings()['speculation'];
	if ( null === $config || ! in_array( $mode, array( 'conservative', 'eager' ), true ) ) {
		return $config;
	}
	return array(
		'mode'      => 'eager' === $mode ? 'prerender' : 'prefetch',
		'eagerness' => 'eager' === $mode ? 'moderate' : 'conservative',
	);
}
add_filter( 'wp_speculation_rules_configuration', 'wpoptimizing_speculation_core_config' );

/**
 * WordPress 6.8+: add the shop pages to core's exclusions (core roots them at the site path).
 *
 * @param string[] $paths Paths core already excludes.
 * @return string[]
 */
function wpoptimizing_speculation_core_exclusions( $paths ) {
	if ( ! in_array( wpoptimizing_frontend_perf_settings()['speculation'], array( 'conservative', 'eager' ), true ) ) {
		return $paths;
	}
	return array_values( array_unique( array_merge( (array) $paths, array_map( 'wpoptimizing_speculation_rooted', wpoptimizing_speculation_shop_paths() ) ) ) );
}
add_filter( 'wp_speculation_rules_href_exclude_paths', 'wpoptimizing_speculation_core_exclusions' );

/**
 * Core wants exclusion paths that start with a slash.
 *
 * @param string $path Relative path.
 * @return string
 */
function wpoptimizing_speculation_rooted( $path ) {
	return '/' . ltrim( (string) $path, '/' );
}



/**
 * Send a diagnostic Server-Timing header on front-end requests.
 *
 * Values known by send_headers (before the template renders): time from WP start,
 * DB query count so far, and the object-cache hit rate from our drop-in stats.
 * A developer aid (read in DevTools → Network → Timing); off by default.
 *
 * @return void
 */
function wpoptimizing_send_server_timing() {
	if ( is_admin() || headers_sent() ) {
		return;
	}
	if ( empty( wpoptimizing_frontend_perf_settings()['server_timing'] ) ) {
		return;
	}
	global $timestart;
	$parts = array();
	if ( ! empty( $timestart ) ) {
		$parts[] = 'wpopt_wp;desc="WP to headers (ms)";dur=' . round( ( microtime( true ) - $timestart ) * 1000, 1 );
	}
	$parts[] = 'wpopt_dbq;desc="DB queries";dur=' . (int) get_num_queries();
	$oc      = function_exists( 'wpoptimizing_cache_stats_read' ) ? wpoptimizing_cache_stats_read() : array(
		'has'   => false,
		'ratio' => 0,
	);
	if ( ! empty( $oc['has'] ) ) {
		$parts[] = 'wpopt_cache;desc="Object cache hit %";dur=' . (int) $oc['ratio'];
	}
	if ( ! empty( $parts ) ) {
		header( 'Server-Timing: ' . implode( ', ', $parts ), false );
	}
}
add_action( 'send_headers', 'wpoptimizing_send_server_timing' );

/**
 * Fold a new server-TTFB sample into the running stats with an exponential moving
 * average (recent requests weigh more, so the figure tracks the current state without
 * unbounded history). PURE — unit-testable. The caller stamps 'updated'.
 *
 * @param array $prev Previous stats { avg:float, n:int }.
 * @param float $ms   New sample in milliseconds.
 * @return array{avg:float,n:int}
 */
function wpoptimizing_ttfb_rollup( array $prev, $ms ) {
	$ms  = max( 0.0, (float) $ms );
	$avg = isset( $prev['avg'] ) ? (float) $prev['avg'] : 0.0;
	$n   = isset( $prev['n'] ) ? (int) $prev['n'] : 0;
	$avg = ( $n <= 0 ) ? $ms : ( $avg * 0.8 + $ms * 0.2 ); // EWMA, alpha = 0.2.
	return array(
		'avg' => round( $avg, 1 ),
		'n'   => $n + 1,
	);
}

/**
 * Fold a TTFB sample into a per-day series (a plain daily mean, so a chart can show the
 * trend over time) and trim to the most recent $cap days. PURE — unit-testable.
 *
 * @param array  $days  Map of "Ymd" => { avg:float, n:int }.
 * @param float  $ms    New sample in milliseconds.
 * @param string $today Today's date as "Ymd".
 * @param int    $cap   Max days to retain.
 * @return array<string,array{avg:float,n:int}>
 */
function wpoptimizing_ttfb_rollup_day( array $days, $ms, $today, $cap = 30 ) {
	$ms    = max( 0.0, (float) $ms );
	$today = (string) $today;
	if ( '' === $today ) {
		return $days;
	}
	$cur            = isset( $days[ $today ] ) && is_array( $days[ $today ] ) ? $days[ $today ] : array(
		'avg' => 0.0,
		'n'   => 0,
	);
	$n              = (int) ( $cur['n'] ?? 0 );
	$avg            = (float) ( $cur['avg'] ?? 0.0 );
	$avg            = ( $n <= 0 ) ? $ms : ( ( $avg * $n + $ms ) / ( $n + 1 ) ); // equal-weight daily mean.
	$days[ $today ] = array(
		'avg' => round( $avg, 1 ),
		'n'   => $n + 1,
	);
	if ( count( $days ) > (int) $cap ) {
		ksort( $days ); // Keys are sortable Ymd.
		$days = array_slice( $days, -$cap, null, true );
	}
	return $days;
}

/**
 * Ordered per-day server-TTFB averages (oldest→newest) for charting, or [] if none.
 *
 * @return array<string,int> Map "Ymd" => average ms.
 */
function wpoptimizing_ttfb_day_series() {
	$s    = get_option( 'wpoptimizing_ttfb_stats', array() );
	$days = ( is_array( $s ) && isset( $s['days'] ) && is_array( $s['days'] ) ) ? $s['days'] : array();
	ksort( $days );
	$out = array();
	foreach ( $days as $ymd => $row ) {
		$out[ (string) $ymd ] = (int) round( (float) ( $row['avg'] ?? 0 ) );
	}
	return $out;
}

/**
 * Sample the SERVER share of TTFB (WP start → headers) on front-end requests and persist
 * a moving average, so it can be shown in the admin (a header alone is invisible there).
 * Heavily sampled and independent of the Server-Timing header toggle.
 *
 * What it costs a visitor is the point, so: the stats are AUTOLOADED (reading them is an array
 * lookup, where it used to be a SELECT on one request in twenty), and a sample is written at
 * most once every few minutes whatever the traffic (one UPDATE per window instead of one per
 * twenty requests). Up to a few hundred samples a day is plenty for a daily mean.
 *
 * @return void
 */
function wpoptimizing_sample_server_ttfb() {
	if ( is_admin() ) {
		return;
	}
	global $timestart;
	if ( empty( $timestart ) ) {
		return;
	}
	$prev = get_option( 'wpoptimizing_ttfb_stats', array() );
	$prev = is_array( $prev ) ? $prev : array();
	if ( ! wpoptimizing_ttfb_sample_due( (int) ( $prev['updated'] ?? 0 ), time() ) ) {
		return;
	}
	$rate = (int) apply_filters( 'wpoptimizing_ttfb_sample_rate', 20 );
	if ( $rate > 1 && function_exists( 'wp_rand' ) && 1 !== wp_rand( 1, $rate ) ) {
		return;
	}
	$ms               = ( microtime( true ) - (float) $timestart ) * 1000;
	$stats            = wpoptimizing_ttfb_rollup( $prev, $ms );
	$stats['days']    = wpoptimizing_ttfb_rollup_day( (array) ( $prev['days'] ?? array() ), $ms, gmdate( 'Ymd' ) );
	$stats['updated'] = time();
	update_option( 'wpoptimizing_ttfb_stats', $stats, true );
}

/**
 * Is a new TTFB sample due? Pure.
 *
 * @since 0.1.0
 * @param int $last Unix time of the last sample written (0 = never).
 * @param int $now  Unix time now.
 * @return bool
 */
function wpoptimizing_ttfb_sample_due( $last, $now ) {
	$gap = max( 0, (int) apply_filters( 'wpoptimizing_ttfb_sample_interval', 5 * MINUTE_IN_SECONDS ) );
	return (int) $last <= 0 || ( (int) $now - (int) $last ) >= $gap;
}
add_action( 'send_headers', 'wpoptimizing_sample_server_ttfb' );

/**
 * The recent average SERVER TTFB (ms), or 0 when not measured yet. This is only the part
 * the site controls (WP→headers), not the visitor's DNS/TLS/network.
 *
 * @return int
 */
function wpoptimizing_server_ttfb_ms() {
	$s = get_option( 'wpoptimizing_ttfb_stats', array() );
	return ( is_array( $s ) && isset( $s['avg'] ) ) ? (int) round( (float) $s['avg'] ) : 0;
}

/**
 * Classify a server-TTFB average (ms) into good/ok/high/unknown. Pure; thresholds
 * filterable (shared with the Health Score). 0 or less means "not measured yet".
 *
 * @param int|float $ms Server TTFB average in milliseconds.
 * @return string 'good'|'ok'|'high'|'unknown'
 */
function wpoptimizing_ttfb_level( $ms ) {
	$ms = (float) $ms;
	if ( $ms <= 0 ) {
		return 'unknown';
	}
	$good = (float) apply_filters( 'wpoptimizing_ttfb_good_ms', 200 );
	$high = (float) apply_filters( 'wpoptimizing_ttfb_high_ms', 600 );
	if ( $ms < $good ) {
		return 'good';
	}
	return ( $ms < $high ) ? 'ok' : 'high';
}

/**
 * Context-aware, prioritised action plan for a slow server TTFB. Pure: it reasons
 * only over the supplied $signals, so it is fully testable. Returns an ordered list
 * of array{ text:string, tab:?string } — `tab` is a plugin tab slug to deep-link to,
 * or null when the action is outside the plugin (host/PHP/system cron).
 *
 * The crucial honest case: when the database, object cache and autoload are already
 * optimal, the remaining time is the server/host itself — we say so plainly instead
 * of suggesting cleanups that cannot help.
 *
 * @param int|float $ms      Server TTFB average (ms).
 * @param array     $signals cache_active(bool), hit_rate(float %, -1 if unknown),
 *                           autoload_bytes(int), wp_cron_on_request(bool).
 * @return array<int,array{text:string,tab:?string}>
 */
function wpoptimizing_ttfb_action_plan( $ms, array $signals ) {
	$level = wpoptimizing_ttfb_level( $ms );
	if ( 'good' === $level || 'unknown' === $level ) {
		return array();
	}

	$cache_active = ! empty( $signals['cache_active'] );
	$hit_rate     = isset( $signals['hit_rate'] ) ? (float) $signals['hit_rate'] : -1.0;
	$autoload     = isset( $signals['autoload_bytes'] ) ? (float) $signals['autoload_bytes'] : 0.0;
	$cron_on_web  = ! empty( $signals['wp_cron_on_request'] );

	$out = array();

	if ( ! $cache_active ) {
		$out[] = array(
			'text' => __( 'Enable the persistent object cache — the single biggest server-TTFB win.', 'wpoptimizing' ),
			'tab'  => 'cache',
		);
	} elseif ( $hit_rate >= 0 && $hit_rate < 90 ) {
		$out[] = array(
			'text' => __( 'Object-cache hit rate is low: check the backend (Redis/Memcached/APCu) is healthy and not being flushed too often.', 'wpoptimizing' ),
			'tab'  => 'cache',
		);
	}
	if ( $autoload > 819200 ) {
		$out[] = array(
			'text' => __( 'Reduce the autoload weight loaded on every request.', 'wpoptimizing' ),
			'tab'  => 'autoload',
		);
	}
	if ( $cron_on_web ) {
		$out[] = array(
			'text' => __( 'Move WP-Cron to a real system cron (define DISABLE_WP_CRON) so scheduled tasks do not run during visitor requests.', 'wpoptimizing' ),
			'tab'  => null,
		);
	}

	// Everything WPOptimizing controls is already optimal → the bottleneck is the server.
	if ( empty( $out ) ) {
		$out[] = array(
			'text' => __( 'Database, object cache and autoload are already optimal — the remaining response time is your server/host. Check the PHP version, that OPcache is enabled, the hosting plan resources, and any theme or plugin doing heavy work on every load. WPOptimizing cannot lower this further.', 'wpoptimizing' ),
			'tab'  => null,
		);
	}

	return $out;
}

/**
 * Optimize images inside post content: the first image is the LCP candidate
 * (fetchpriority=high, not lazy); the rest are lazy-loaded with async decoding.
 * Uses WP_HTML_Tag_Processor so it is safe even on builder/raw markup that core's
 * own tag processing may not have touched. Idempotent.
 *
 * @param string $content Post content HTML.
 * @return string
 */
function wpoptimizing_optimize_content_images( $content ) {
	if ( is_admin() || ! in_the_loop() || ! is_main_query() ) {
		return $content;
	}
	if ( empty( wpoptimizing_frontend_perf_settings()['image_opt'] ) ) {
		return $content;
	}
	return wpoptimizing_optimize_images_html( $content );
}

/**
 * The document's image counter for the content passes.
 *
 * With a fixed number of eager images, «the first N» means the first N of the document, not of
 * each the_content call — an archive page runs the filter once per post, and every post's first
 * image is not «at the top». The content passes cannot see what the theme prints above them, so
 * they count from the first content image; the whole-page pass, when the page cache stores the
 * page, counts from the top and has the last word (see wpoptimizing_optimize_images_html()).
 *
 * @param bool $reset Start again (tests, or a new document in a long-running process).
 * @return int Position of the next image, from 1 (0 after a reset).
 */
function wpoptimizing_image_document_counter( $reset = false ) {
	static $seen = 0;
	if ( $reset ) {
		$seen = 0;
		return 0;
	}
	return ++$seen;
}

/**
 * Whether an image is kept out of lazy loading on purpose: the skip-lazy / no-lazy classes and
 * the data-skip-lazy / data-no-lazy attributes (the convention WP Rocket, LiteSpeed and
 * Perfmatters share), or one of the site's own fragments found in its class or file URL. Pure.
 *
 * @param string      $class     Class attribute.
 * @param string      $src       Src attribute.
 * @param string      $srcset    Srcset attribute.
 * @param bool        $data_skip Whether it carries data-skip-lazy or data-no-lazy.
 * @param string[]    $fragments The site's exclusions.
 * @return bool
 */
function wpoptimizing_image_never_lazy( $class, $src, $srcset, $data_skip, array $fragments ) {
	if ( $data_skip ) {
		return true;
	}
	$tokens = preg_split( '/\s+/', strtolower( (string) $class ), -1, PREG_SPLIT_NO_EMPTY );
	if ( array_intersect( array( 'skip-lazy', 'no-lazy' ), (array) $tokens ) ) {
		return true;
	}
	foreach ( $fragments as $fragment ) {
		$fragment = (string) $fragment;
		if ( '' !== $fragment && ( false !== strpos( (string) $class, $fragment ) || false !== strpos( (string) $src, $fragment ) || false !== strpos( (string) $srcset, $fragment ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The image-attribute rewriting itself, decoupled from the hook guards so it can
 * be exercised directly. Coexists with third-party lazy-loaders / image plugins
 * and WordPress core (6.3+): it only ADDS attributes, never overrides existing
 * ones, and skips any image another tool has already deferred.
 *
 * Two separate decisions per image:
 *  - not deferred (no loading=lazy added): the images at the top — automatic, or the first N
 *    of the document (image_eager) — the ones excluded on purpose (skip-lazy & co. and the
 *    site's list), and any image holding high fetch priority (lazy and «fetch first» contradict
 *    each other, and the page pass used to add lazy to the content's LCP image when the header
 *    had more than three images);
 *  - promoted (fetchpriority=high): only the likely LCP image, the first of the content, when
 *    image_lcp is on, it is not small, nothing else holds the page's single high priority and
 *    it is not already lazy (a loading="lazy" somebody set is respected, never removed, and a
 *    lazy image is not asked to come first). A promoted image is never deferred.
 * Automatic mode keeps exactly what this did before the setting: in the content, the first
 * image of each pass is the candidate and stays eager; on the whole page the first three stay
 * eager and nothing is promoted.
 *
 * @param string $content HTML.
 * @param string $mode    'content' (a the_content pass) or 'page' (the whole page, when the
 *                        page cache stores it).
 * @return string
 */
function wpoptimizing_optimize_images_html( $content, $mode = 'content' ) {
	if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
		return $content;
	}
	$fp        = wpoptimizing_frontend_perf_settings();
	$eager     = (int) $fp['image_eager'];
	$auto      = $eager < 0;
	$promote   = ! empty( $fp['image_lcp'] );
	$fragments = (array) $fp['lazy_exclude'];
	$whole     = 'page' === $mode;
	$p         = new WP_HTML_Tag_Processor( $content );
	$index     = 0;
	while ( $p->next_tag( 'img' ) ) {
		++$index;
		// Position in the document: on the whole page it is the index; in the content, the
		// request's counter when the number is fixed, the index of this pass when automatic.
		$pos = ( $whole || $auto ) ? $index : wpoptimizing_image_document_counter();

		// The first <img> of the content is the LCP candidate, even if we end up leaving it to
		// another tool — so a later image is never mis-promoted. On a whole page the first
		// image is usually the logo: nothing is promoted there.
		$is_candidate = ! $whole && 1 === $pos;
		if ( $whole ) {
			$at_top = $index <= ( $auto ? 3 : $eager );
		} else {
			$at_top = $auto ? $is_candidate : $pos <= $eager;
		}

		// Coexist with third-party lazy-loaders / image-optimization plugins
		// (Smush, Optimole, a3 Lazy Load, WP Rocket, EWWW…): if another tool has
		// already deferred this image (a placeholder data-* src or a lazy class),
		// leave it untouched so we never fight over it or break its JS swap.
		// skip-lazy / no-lazy say the opposite, so they are not taken for a lazy class.
		$cls      = (string) $p->get_attribute( 'class' );
		$skip     = wpoptimizing_image_never_lazy(
			$cls,
			(string) $p->get_attribute( 'src' ),
			(string) $p->get_attribute( 'srcset' ),
			null !== $p->get_attribute( 'data-skip-lazy' ) || null !== $p->get_attribute( 'data-no-lazy' ),
			$fragments
		);
		$cls_lazy = '' !== $cls && preg_match( '/\b(lazy|lazyload|lazyloaded)\b/i', (string) preg_replace( '/(?<![\w-])(?:skip|no)-lazy(?![\w-])/i', '', $cls ) );
		if ( null !== $p->get_attribute( 'data-src' )
			|| null !== $p->get_attribute( 'data-lazy-src' )
			|| null !== $p->get_attribute( 'data-lazyload' )
			|| $cls_lazy ) {
			continue;
		}

		// Missing dimensions: the browser cannot reserve the space, so the page jumps (CLS) when
		// the image arrives. Core fills them only for library images with a wp-image-N class.
		if ( null === $p->get_attribute( 'width' ) && null === $p->get_attribute( 'height' ) ) {
			$dims = wpoptimizing_image_local_dimensions( (string) $p->get_attribute( 'src' ) );
			if ( $dims ) {
				$p->set_attribute( 'width', (string) $dims[0] );
				$p->set_attribute( 'height', (string) $dims[1] );
			}
		}

		// Only ADD attributes; never override what the theme/core/another plugin
		// already set (WordPress core also sets these since 6.3).
		if ( null === $p->get_attribute( 'decoding' ) ) {
			$p->set_attribute( 'decoding', 'async' );
		}
		if ( $is_candidate && $promote && 'lazy' !== strtolower( (string) $p->get_attribute( 'loading' ) ) ) {
			// LCP candidate: prioritise it, but stay purely additive — set fetchpriority only
			// when absent. And only when nothing else holds the page's single high priority:
			// core marks the first big image of the template (often the featured image, printed
			// before the content) and two "high" images compete for the same bandwidth. Nor on a
			// small image — an icon is not the LCP; same pixel floor as core.
			$w     = (int) $p->get_attribute( 'width' );
			$h     = (int) $p->get_attribute( 'height' );
			$small = $w > 0 && $h > 0 && ( $w * $h ) < (int) apply_filters( 'wp_min_priority_img_pixels', 50000 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core's own filter, read so we use the same floor.
			$taken = function_exists( 'wp_high_priority_element_flag' ) && ! wp_high_priority_element_flag();
			if ( null === $p->get_attribute( 'fetchpriority' ) && ! $small && ! $taken ) {
				$p->set_attribute( 'fetchpriority', 'high' );
				if ( function_exists( 'wp_high_priority_element_flag' ) ) {
					wp_high_priority_element_flag( false );
				}
			}
		}
		if ( ! $at_top && ! $skip && null === $p->get_attribute( 'loading' )
			&& 'high' !== strtolower( (string) $p->get_attribute( 'fetchpriority' ) )
			&& null !== $p->get_attribute( 'width' ) && null !== $p->get_attribute( 'height' ) ) {
			// Only lazy-load images that declare their size. Lazy-loading a
			// dimensionless image can trigger layout shift (CLS) as it pops in, so
			// we leave those eager — same safety rule WordPress core applies.
			$p->set_attribute( 'loading', 'lazy' );
		}
	}
	return $p->get_updated_html();
}
add_filter( 'the_content', 'wpoptimizing_optimize_content_images', 12 );

/**
 * The same image pass over the WHOLE page — header, sidebars, footer, builder markup that never
 * goes through the_content — at the one moment it is cheap: when the page cache stores the page,
 * once per page and lifetime instead of on every visit. Nothing is given high priority here
 * (the first image of a page is usually the logo); the images at the top stay eager (the first
 * three, or the number set), and so does any image that already holds high priority.
 *
 * @param string $html Page HTML about to be cached.
 * @return string
 */
function wpoptimizing_optimize_page_html( $html ) {
	$fp = wpoptimizing_frontend_perf_settings();
	if ( empty( $fp['image_opt'] ) || empty( $fp['image_opt_page'] ) || ! is_string( $html ) || false === stripos( $html, '<img' ) ) {
		return $html;
	}
	return wpoptimizing_optimize_images_html( $html, 'page' );
}
add_filter( 'wpoptimizing_page_cache_html', 'wpoptimizing_optimize_page_html' );

/**
 * Width and height written in an image file name by WordPress' sizes (photo-800x600.jpg).
 * Pure.
 *
 * @param string $url Image URL.
 * @return int[]|null [width, height].
 */
function wpoptimizing_image_dims_from_name( $url ) {
	$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
	if ( preg_match( '/-(\d{1,5})x(\d{1,5})\.(?:jpe?g|png|gif|webp|avif)$/i', $path, $m ) && (int) $m[1] > 0 && (int) $m[2] > 0 ) {
		return array( (int) $m[1], (int) $m[2] );
	}
	return null;
}

/**
 * Dimensions of an image of this site's uploads folder: from its name when it says them, else
 * from the file (cached, and at most a few files read per request so a gallery of foreign
 * markup cannot turn the render into disk work). External, SVG or unknown: null.
 *
 * @param string $src Image src.
 * @return int[]|null [width, height].
 */
function wpoptimizing_image_local_dimensions( $src ) {
	static $reads = 0;
	$src          = (string) $src;
	if ( '' === $src || 0 === strpos( $src, 'data:' ) || preg_match( '/\.svg(?:$|\?)/i', $src ) ) {
		return null;
	}
	$dims = wpoptimizing_image_dims_from_name( $src );
	if ( $dims ) {
		return $dims;
	}
	$uploads = wp_get_upload_dir();
	$base    = preg_replace( '#^https?:#', '', (string) $uploads['baseurl'] );
	$rel     = preg_replace( '#^https?:#', '', strtok( $src, '?' ) );
	if ( '' === $base || 0 !== strpos( $rel, $base . '/' ) ) {
		return null;
	}
	$rel = rawurldecode( substr( $rel, strlen( $base ) + 1 ) );
	if ( false !== strpos( $rel, '..' ) ) {
		return null;
	}
	$key    = 'dims_' . md5( $rel );
	$cached = wp_cache_get( $key, 'wpoptimizing' );
	if ( is_array( $cached ) ) {
		return $cached ? $cached : null;
	}
	/**
	 * Filters how many image files one request may read to fill in missing dimensions.
	 *
	 * @since 0.1.0
	 * @param int $max Files.
	 */
	if ( $reads >= (int) apply_filters( 'wpoptimizing_image_dimension_reads', 10 ) ) {
		return null;
	}
	++$reads;
	$file = trailingslashit( (string) $uploads['basedir'] ) . $rel;
	$info = is_file( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a corrupt file must not print a warning into the page.
	$dims = ( is_array( $info ) && $info[0] > 0 && $info[1] > 0 ) ? array( (int) $info[0], (int) $info[1] ) : array();
	wp_cache_set( $key, $dims, 'wpoptimizing', DAY_IN_SECONDS );
	return $dims ? $dims : null;
}

/**
 * Extract the unique EXTERNAL hosts from a list of asset source URLs. Pure
 * (unit-testable). A host is external when it differs from the site host; empty,
 * relative and same-host URLs are skipped. Protocol-relative URLs (//host/..) are
 * handled.
 *
 * @param array  $srcs      Asset src URLs (strings).
 * @param string $site_host The site's own host (lowercased comparison).
 * @return string[] Unique external hosts, in first-seen order.
 */
function wpoptimizing_external_hosts_from_srcs( array $srcs, $site_host ) {
	$site_host = strtolower( (string) $site_host );
	$hosts     = array();
	foreach ( $srcs as $src ) {
		$src = (string) $src;
		if ( '' === $src ) {
			continue;
		}
		if ( 0 === strpos( $src, '//' ) ) {
			$src = 'https:' . $src; // Protocol-relative → give wp_parse_url a scheme.
		}
		$host = wp_parse_url( $src, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			continue; // Relative URL (no host) or unparseable.
		}
		$host = strtolower( $host );
		if ( $host === $site_host ) {
			continue;
		}
		$hosts[ $host ] = true;
	}
	return array_keys( $hosts );
}

/**
 * Collect the external hosts of every enqueued style and script on the current
 * page, so the page can hint the browser to resolve/connect to them early.
 *
 * @return string[]
 */
function wpoptimizing_collect_external_asset_hosts() {
	$srcs = array();
	foreach ( array( wp_styles(), wp_scripts() ) as $reg ) {
		if ( ! is_object( $reg ) || empty( $reg->queue ) ) {
			continue;
		}
		foreach ( (array) $reg->queue as $handle ) {
			if ( isset( $reg->registered[ $handle ]->src ) && is_string( $reg->registered[ $handle ]->src ) ) {
				$srcs[] = $reg->registered[ $handle ]->src;
			}
		}
	}
	$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	return wpoptimizing_external_hosts_from_srcs( $srcs, $site_host );
}

/**
 * Add automatic resource hints for the external origins the page actually uses:
 * `preconnect` for the well-known font origins (a full connection is worth it for
 * render-blocking fonts) and `dns-prefetch` for the rest. Advisory and safe —
 * a redundant hint only costs a cheap DNS lookup. Opt-in via the front-end
 * performance settings.
 *
 * @param array  $urls          Resource-hint entries (strings or arrays with 'href').
 * @param string $relation_type Hint relation type.
 * @return array
 */
function wpoptimizing_filter_resource_hints( $urls, $relation_type ) {
	if ( empty( wpoptimizing_frontend_perf_settings()['resource_hints'] ) ) {
		return $urls;
	}
	if ( ! in_array( $relation_type, array( 'dns-prefetch', 'preconnect' ), true ) ) {
		return $urls;
	}
	$hosts = wpoptimizing_collect_external_asset_hosts();
	if ( empty( $hosts ) ) {
		return $urls;
	}
	$urls = is_array( $urls ) ? $urls : array();

	// Hosts already hinted (avoid duplicates with core/theme hints).
	$existing = array();
	foreach ( $urls as $u ) {
		$href = is_array( $u ) ? ( $u['href'] ?? '' ) : $u;
		$h    = wp_parse_url( (string) $href, PHP_URL_HOST );
		if ( is_string( $h ) && '' !== $h ) {
			$existing[ strtolower( $h ) ] = true;
		}
	}

	// Render-blocking font origins benefit from a full early connection.
	$preconnect_hosts = array( 'fonts.googleapis.com', 'fonts.gstatic.com' );

	foreach ( $hosts as $host ) {
		if ( isset( $existing[ $host ] ) ) {
			continue;
		}
		$is_font = in_array( $host, $preconnect_hosts, true );
		if ( 'preconnect' === $relation_type && $is_font ) {
			// gstatic serves the font files cross-origin → must be CORS-aware.
			$urls[]            = ( 'fonts.gstatic.com' === $host )
				? array(
					'href'        => 'https://' . $host,
					'crossorigin' => 'anonymous',
				)
				: 'https://' . $host;
			$existing[ $host ] = true;
		} elseif ( 'dns-prefetch' === $relation_type && ! $is_font ) {
			$urls[]            = 'https://' . $host;
			$existing[ $host ] = true;
		}
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'wpoptimizing_filter_resource_hints', 10, 2 );

/**
 * `?wpopt_no_optimize` shows the page exactly as WordPress and the theme build it, which is
 * what anyone debugging a broken layout needs. It used to switch off only minification and
 * delayed JavaScript; Smart Assets, the CDN rewrite, next-gen images, video facades, local
 * fonts, preloads and speculative loading all kept running, so "it still breaks with the
 * bypass" pointed away from us when the culprit was ours. Every front-end hook comes off here,
 * once, before the page starts rendering.
 *
 * @return void
 */
function wpoptimizing_front_bypass() {
	if ( is_admin() || ! isset( $_GET['wpopt_no_optimize'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only debug bypass, no state change.
		return;
	}
	$hooks = array(
		array( 'wp_head', 'wpoptimizing_print_speculation_rules', 10 ),
		array( 'wp_speculation_rules_configuration', 'wpoptimizing_speculation_core_config', 10 ),
		array( 'wp_speculation_rules_href_exclude_paths', 'wpoptimizing_speculation_core_exclusions', 10 ),
		array( 'wp_head', 'wpoptimizing_print_preload_hints', 2 ),
		array( 'the_content', 'wpoptimizing_lazy_embeds_content', 15 ),
		array( 'wp_footer', 'wpoptimizing_video_facade_assets', 99 ),
		array( 'the_content', 'wpoptimizing_optimize_content_images', 12 ),
		array( 'wp_resource_hints', 'wpoptimizing_filter_resource_hints', 10 ),
		array( 'style_loader_src', 'wpoptimizing_gfonts_style_src', 20 ),
		array( 'style_loader_src', 'wpoptimizing_cdn_filter_src', 999 ),
		array( 'script_loader_src', 'wpoptimizing_cdn_filter_src', 999 ),
		array( 'wp_get_attachment_url', 'wpoptimizing_cdn_filter_src', 999 ),
		array( 'wp_calculate_image_srcset', 'wpoptimizing_cdn_filter_srcset', 999 ),
		array( 'the_content', 'wpoptimizing_cdn_filter_content', 999 ),
		array( 'wp_content_img_tag', 'wpoptimizing_image_filter_content_img', 20 ),
		array( 'wp_get_attachment_image', 'wpoptimizing_image_filter_attachment_html', 20 ),
		array( 'post_thumbnail_html', 'wpoptimizing_image_filter_attachment_html', 20 ),
		array( 'wp_enqueue_scripts', 'wpoptimizing_assets_apply_rules', 9999 ),
		array( 'wp_enqueue_scripts', 'wpoptimizing_assets_apply_page_rules', 9999 ),
	);
	foreach ( $hooks as $h ) {
		remove_filter( $h[0], $h[1], $h[2] );
	}
}
add_action( 'wp', 'wpoptimizing_front_bypass', 99 );
