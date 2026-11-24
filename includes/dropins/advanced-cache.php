<?php
/**
 * WPOPT_PAGE_CACHE v18
 *
 * WPOptimizing page-cache drop-in. This is a TEMPLATE: the plugin fills the
 * %%PLACEHOLDER%% strings with real paths when it installs it to
 * wp-content/advanced-cache.php. Do not edit the installed copy by hand — the
 * plugin overwrites it (atomically) when its version changes.
 *
 * It runs on EVERY request, before WordPress core loads, so it stays tiny and
 * self-contained: it only requires the page-cache engine (pure decision/path
 * logic) and a precompiled config file, then either serves a cached page and
 * exits, or starts an output buffer that stores the page on shutdown.
 *
 * @package WPOptimizing
 */

// PANIC SWITCH. `define( 'WPOPT_DISABLE', true );` in wp-config.php stands every piece of
// WPOptimizing that runs BEFORE the plugin down — this drop-in, the other drop-ins and the
// must-use runtime — without FTP, without touching the database and without deactivating
// anything. Code that executes before WordPress has any recovery path of its own needs a way
// out that does not depend on the admin being reachable; this is it, and it is the first thing
// any agency asks for before installing software that loads this early.
if ( defined( 'WPOPT_DISABLE' ) && WPOPT_DISABLE ) {
	return;
}


// Direct access protection. This drop-in is included from wp-settings.php, and
// wp-config.php has defined ABSPATH long before that, so the guard never fires during a
// normal request — it only stops the file being hit directly over HTTP.
defined( 'ABSPATH' ) || exit;

// Never let a caching layer take down the site.
if ( PHP_VERSION_ID < 70400 ) {
	return;
}
if ( ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI ) {
	return;
}
if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
	return;
}

$wpopt_pc_engine      = '%%WPOPT_PC_ENGINE%%';
$wpopt_pc_config_file = '%%WPOPT_PC_CONFIG%%';
$wpopt_pc_home        = '%%WPOPT_PC_HOME%%';

// A copy of this file that runs from another wp-content is a clone (staging, a migration)
// still pointing at the original install: its engine is the other site's, and loading it made
// the plugin of the clone die redeclaring wpopt_pc_default_config() (staging.emiliocalvo.es,
// 23/10/2026). The clone renders uncached until the plugin rewrites this file with its own paths.
if ( '' !== $wpopt_pc_home && __DIR__ !== $wpopt_pc_home ) {
	return;
}

if ( ! is_readable( $wpopt_pc_engine ) || ! is_readable( $wpopt_pc_config_file ) ) {
	return; // Plugin gone / not configured yet → behave as if no cache.
}

require_once $wpopt_pc_engine;

// Precompiled config: array with at least 'active' (bool) and 'base_dir' (string),
// plus optional exclusion overrides, 'gzip' (bool), 'brotli' (bool) and 'mobile_separate' (bool).
$wpopt_pc_sidecar = include $wpopt_pc_config_file;

// A network: this file only says where the sites keep theirs. The sidecar of the site the request
// belongs to is found by its host and path (wpopt_pc_site_sidecars()); none, and nothing is cached.
// It says 'active' => false itself, so a drop-in from before networks were supported caches nothing.
if ( is_array( $wpopt_pc_sidecar ) && ! empty( $wpopt_pc_sidecar['network'] ) ) {
	if ( ! function_exists( 'wpopt_pc_site_sidecars' ) || empty( $wpopt_pc_sidecar['base_dir'] ) ) {
		return;
	}
	$wpopt_pc_site_file = '';
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Before WordPress; only compared with file names.
	foreach ( wpopt_pc_site_sidecars( $wpopt_pc_sidecar['base_dir'], isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : '', (string) strtok( isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '/', '?' ), isset( $wpopt_pc_sidecar['depth'] ) ? (int) $wpopt_pc_sidecar['depth'] : 1 ) as $wpopt_pc_candidate ) {
		if ( is_readable( $wpopt_pc_candidate ) ) {
			$wpopt_pc_site_file = $wpopt_pc_candidate;
			break;
		}
	}
	if ( '' === $wpopt_pc_site_file ) {
		return;
	}
	$wpopt_pc_config_file = $wpopt_pc_site_file;
	$wpopt_pc_sidecar     = include $wpopt_pc_config_file;
}

if ( ! is_array( $wpopt_pc_sidecar ) || empty( $wpopt_pc_sidecar['active'] ) || empty( $wpopt_pc_sidecar['base_dir'] ) ) {
	return;
}

$wpopt_pc_cfg = wpopt_pc_config( $wpopt_pc_sidecar );

// This drop-in runs BEFORE WordPress loads, so wp_unslash()/sanitize_*() do not exist yet.
// The raw $_SERVER values below are only compared (method/URI/cookie/UA/host/encoding) to
// decide whether to serve a cached page; they are never echoed. Sanitisation is therefore
// N/A here and the input sniffs are disabled for this request-inspection block only.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
$wpopt_pc_method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';
$wpopt_pc_uri    = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
$wpopt_pc_cookie = isset( $_SERVER['HTTP_COOKIE'] ) ? $_SERVER['HTTP_COOKIE'] : '';
$wpopt_pc_ua     = isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '';

// Hit diagnostics (Pro, off unless the site turned them on): one count per request, by outcome.
// An engine from before them has no counters; every call below is then skipped.
$wpopt_pc_stats_on = function_exists( 'wpopt_pc_stats_start' );
if ( $wpopt_pc_stats_on ) {
	wpopt_pc_stats_start( $wpopt_pc_sidecar, $wpopt_pc_ua );
}

// EARLY gate: judged from $_SERVER + cookies only. If it fails, do nothing (WP
// renders normally, uncached).
if ( $wpopt_pc_stats_on ) {
	$wpopt_pc_why = wpopt_pc_early_reason( $wpopt_pc_method, $wpopt_pc_uri, $wpopt_pc_cookie, $wpopt_pc_ua, $wpopt_pc_cfg );
	if ( '' !== $wpopt_pc_why ) {
		wpopt_pc_stats_record( 'bypass-' . $wpopt_pc_why );
		return;
	}
} elseif ( ! wpopt_pc_can_serve_early( $wpopt_pc_method, $wpopt_pc_uri, $wpopt_pc_cookie, $wpopt_pc_ua, $wpopt_pc_cfg ) ) {
	return;
}

$wpopt_pc_base = $wpopt_pc_sidecar['base_dir'];
$wpopt_pc_host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';
// «example.com:443» is example.com, as WordPress reads it: one folder for both, which the purges reach.
if ( function_exists( 'wpopt_pc_site_host' ) ) {
	$wpopt_pc_host = wpopt_pc_site_host( $wpopt_pc_host );
}

// The cache directory is keyed by host, so a forged Host header would let anyone create
// unlimited directories under the cache folder. Only hosts this install answers to are
// cached; anything else renders normally, uncached.
if ( ! wpopt_pc_host_ok( $wpopt_pc_host, $wpopt_pc_cfg ) ) {
	if ( $wpopt_pc_stats_on ) {
		wpopt_pc_stats_record( 'bypass-host' );
	}
	return;
}

$wpopt_pc_parts = wpopt_pc_parse_uri( $wpopt_pc_uri );
$wpopt_pc_https = wpopt_pc_request_is_https( $_SERVER );

// A REST route the site caches (wpopt_pc_rest_allowed()): only for an anonymous call. A REST
// nonce in the request means a script acting for a session, whose answer is that visitor's.
$wpopt_pc_rest = function_exists( 'wpopt_pc_rest_allowed' ) && wpopt_pc_rest_allowed( $wpopt_pc_parts['path'], $wpopt_pc_cfg );
// A text file the site caches (llms.txt, robots.txt, a sitemap: wpopt_pc_text_file_allowed()).
// Like a REST answer it is not a page: kept as it comes, never reworked nor served without PHP.
$wpopt_pc_text = ! $wpopt_pc_rest && function_exists( 'wpopt_pc_text_file_allowed' ) && wpopt_pc_text_file_allowed( $wpopt_pc_parts['path'], $wpopt_pc_cfg );
if ( $wpopt_pc_rest && ! empty( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
	if ( $wpopt_pc_stats_on ) {
		wpopt_pc_stats_record( 'bypass-cookie' );
	}
	return;
}
// The whitelisted query parameters (s, lang, …) change the rendered page, so they are part
// of the cache key: without this, /?lang=fr and / shared one file.
$wpopt_pc_qkey = wpopt_pc_query_key( $wpopt_pc_parts['query'], $wpopt_pc_cfg );
// A theme that renders differently on phones (wp_is_mobile()) needs its own copy for them;
// the sidecar only turns this on when the site asked for it (Pro).
// A text file is the same for every device: one copy, which the rebuild after a purge (asked for
// with a desktop agent) keeps warm. With two, the phone copy stayed expired and the first phone
// to ask paid for the whole rebuild: PageSpeed's mobile run gave up on llms.txt (18/11/2026).
$wpopt_pc_mobile = ! $wpopt_pc_text && ! empty( $wpopt_pc_sidecar['mobile_separate'] ) && wpopt_pc_is_mobile( $_SERVER );
// The currency or country a multi-currency store remembers in a cookie changes the prices on
// the same URL: one copy per value. A value that is not a currency/country code is not cached.
$wpopt_pc_ckey = function_exists( 'wpopt_pc_cookie_key' ) ? wpopt_pc_cookie_key( $_COOKIE, $wpopt_pc_cfg ) : '';
if ( false === $wpopt_pc_ckey && $wpopt_pc_stats_on ) {
	wpopt_pc_stats_record( 'bypass-currency' );
}
if ( false === $wpopt_pc_ckey ) {
	return;
}
$wpopt_pc_variant = wpopt_pc_variant( $wpopt_pc_https, $wpopt_pc_mobile, $wpopt_pc_qkey, $wpopt_pc_ckey );
// Which copies this client may get, best first (wpopt_pc_negotiate_encoding()): Brotli only when
// the site stores it (a sidecar from before Brotli has no key: none), always ending with the plain
// page. Every coding is its own file, its own fingerprint in the .meta and its own ETag.
$wpopt_pc_codings = array( 'gzip' );
$wpopt_pc_codings = wpopt_pc_negotiate_encoding( isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? (string) $_SERVER['HTTP_ACCEPT_ENCODING'] : '', $wpopt_pc_codings );

$wpopt_pc_plain = wpopt_pc_cache_file( $wpopt_pc_base, $wpopt_pc_host, $wpopt_pc_parts['path'], $wpopt_pc_variant, 'identity' );
$wpopt_pc_gz    = wpopt_pc_cache_file( $wpopt_pc_base, $wpopt_pc_host, $wpopt_pc_parts['path'], $wpopt_pc_variant, 'gzip' );

// ── Serve from cache if we have it ───────────────────────────────────────────
// phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

// Older than its lifetime → a miss (see wpopt_pc_is_fresh()): render and overwrite.
$wpopt_pc_ttl = isset( $wpopt_pc_sidecar['ttl'] ) ? (int) $wpopt_pc_sidecar['ttl'] : 36000;
$wpopt_pc_now = time();
// HTML at Cloudflare's edge (cloudflare.php): a page served from here passed the same gate the
// edge header is decided by, so it tells the edge how long it may keep it.
$wpopt_pc_edge = 0;
$wpopt_pc_cf = $wpopt_pc_edge > 0 ? 'Cloudflare-CDN-Cache-Control: max-age=' . $wpopt_pc_edge : '';

$wpopt_pc_stale     = '';
$wpopt_pc_stale_enc = 'identity';
foreach ( $wpopt_pc_codings as $wpopt_pc_enc ) {
	$wpopt_pc_file = $wpopt_pc_plain . wpopt_pc_encoding_ext( $wpopt_pc_enc );
	if ( is_readable( $wpopt_pc_file ) ) {
		$wpopt_pc_mtime = (int) filemtime( $wpopt_pc_file );
		if ( wpopt_pc_is_fresh( $wpopt_pc_mtime, $wpopt_pc_now, $wpopt_pc_ttl ) ) {
			// A hit never loads WordPress, so it never runs WP-Cron: fire it when a task is due
			// (a fallback only, see wpopt_pc_cron_arm()).
			if ( function_exists( 'wpopt_pc_cron_arm' ) ) {
				wpopt_pc_cron_arm( $wpopt_pc_sidecar, $wpopt_pc_now );
			}
			// Returns only when the copy and its .meta disagree: the next coding is tried.
			wpopt_pc_send_hit( $wpopt_pc_file, $wpopt_pc_enc, $wpopt_pc_mtime, $wpopt_pc_now, $wpopt_pc_ttl, $wpopt_pc_edge, $_SERVER );
		}
		if ( '' === $wpopt_pc_stale && function_exists( 'wpopt_pc_is_stale_ok' ) && wpopt_pc_is_stale_ok( $wpopt_pc_mtime, $wpopt_pc_now, $wpopt_pc_ttl, isset( $wpopt_pc_sidecar['stale_grace'] ) ? (int) $wpopt_pc_sidecar['stale_grace'] : 7200 ) ) {
			$wpopt_pc_stale     = $wpopt_pc_file;
			$wpopt_pc_stale_enc = $wpopt_pc_enc;
		}
	}
}

// Expired, but young enough to bridge a rebuild: one request rebuilds it, the others are served
// this copy meanwhile (see wpopt_pc_try_lock()).
$wpopt_pc_lock = false;
if ( '' !== $wpopt_pc_stale && 'GET' === strtoupper( $wpopt_pc_method ) && function_exists( 'wpopt_pc_try_lock' ) ) {
	$wpopt_pc_lock = wpopt_pc_try_lock( $wpopt_pc_plain . '.lock' );
	if ( false === $wpopt_pc_lock ) {
		if ( function_exists( 'wpopt_pc_cron_arm' ) ) {
			wpopt_pc_cron_arm( $wpopt_pc_sidecar, $wpopt_pc_now );
		}
		wpopt_pc_send_hit( $wpopt_pc_stale, $wpopt_pc_stale_enc, (int) filemtime( $wpopt_pc_stale ), $wpopt_pc_now, $wpopt_pc_ttl, 0, $_SERVER, 'STALE' );
	}
}

// ── No cache yet: buffer the render and store it on shutdown (HEAD never stored) ─
if ( 'GET' !== strtoupper( $wpopt_pc_method ) ) {
	if ( $wpopt_pc_stats_on ) {
		wpopt_pc_stats_record( 'head' );
	}
	return;
}
// A text file is rebuilt by a request that does not wait for the answer (the loopback after a
// purge), and a plugin can take seconds to write it: the render goes on to the end when the
// client leaves. Otherwise PHP stopped it halfway and the copy kept half of llms.txt.
if ( $wpopt_pc_text ) {
	ignore_user_abort( true );
}

$GLOBALS['wpopt_pc_store'] = array(
	'plain'   => $wpopt_pc_plain,
	'gz'      => $wpopt_pc_gz,
	'gzip'    => ! empty( $wpopt_pc_sidecar['gzip'] ),
	'page_id' => isset( $wpopt_pc_sidecar['reject_page_ids'] ) ? (array) $wpopt_pc_sidecar['reject_page_ids'] : array(),
	'cf'      => $wpopt_pc_cf,
	// The web server serves a twin of this copy without PHP (page-cache-static.php) only when
	// nothing PHP does is needed to answer it: no edge header to send.
	'static'  => ! empty( $wpopt_pc_sidecar['static'] ) && '' === $wpopt_pc_cf,
	'ttl'     => $wpopt_pc_ttl,
	'offset'  => isset( $wpopt_pc_sidecar['static_offset'] ) ? (int) $wpopt_pc_sidecar['static_offset'] : 0,
	'lock'    => $wpopt_pc_lock,
	// Page types the site never caches (Pro). A sidecar from before these rules has no key.
	'types'   => isset( $wpopt_pc_sidecar['reject_types'] ) ? (array) $wpopt_pc_sidecar['reject_types'] : array(),
	// The sidecar as this request read it, to tell at the end whether it was replaced meanwhile.
	'cfgfile' => $wpopt_pc_config_file,
	'cfg'     => $wpopt_pc_sidecar,
	// For the MISS sample of the hit diagnostics: the path only, never the query string.
	'path'    => $wpopt_pc_parts['path'],
	// When this render started, before WordPress read any content: a purge after this moment
	// (wpopt_pc_purged_after()) may have deleted a page older than what this request renders.
	'start'   => microtime( true ),
	'base'    => $wpopt_pc_base,
	// On a network: the site's own folder (its full purges leave their mark there) and the site
	// the sidecar belongs to, which must be the one WordPress loads (see the late gate).
	'site'    => isset( $wpopt_pc_sidecar['site_root'] ) ? (string) $wpopt_pc_sidecar['site_root'] : '',
	'blog'    => isset( $wpopt_pc_sidecar['blog_id'] ) ? (int) $wpopt_pc_sidecar['blog_id'] : 0,
	'sitekey' => isset( $wpopt_pc_sidecar['site_key'] ) ? (string) $wpopt_pc_sidecar['site_key'] : '',
	// A REST route the site caches: kept as JSON, never reworked as a page nor served without PHP.
	'rest'    => $wpopt_pc_rest || $wpopt_pc_text,
	'text'    => $wpopt_pc_text,
);

ob_start(
	function ( $buffer, $phase = PHP_OUTPUT_HANDLER_FINAL ) {
		$store = isset( $GLOBALS['wpopt_pc_store'] ) ? $GLOBALS['wpopt_pc_store'] : null;
		if ( ! is_array( $store ) ) {
			return $buffer;
		}
		// Somebody flushed (ob_flush) or cleaned (ob_clean) this buffer before the end: what
		// reaches the final call is only the tail of the page, or a different response
		// altogether. Storing it served a truncated page to every later visitor. Once that has
		// happened, this request stores nothing.
		if ( function_exists( 'wpopt_pc_buffer_phase_ok' ) && ! wpopt_pc_buffer_phase_ok( $phase ) ) {
			$GLOBALS['wpopt_pc_store'] = null;
			if ( function_exists( 'wpopt_pc_stats_record' ) ) {
				wpopt_pc_stats_record( 'bypass-flushed' );
			}
			if ( '' !== $store['cf'] && ! headers_sent() ) {
				header( 'Cloudflare-CDN-Cache-Control: no-store' );
			}
			if ( function_exists( 'wpopt_pc_release_lock' ) ) {
				wpopt_pc_release_lock( $store['lock'] );
			}
			return $buffer;
		}
		// LATE gate — WordPress is loaded here, so we can read its state.
		$rejected_page = false;
		if ( ! empty( $store['page_id'] ) && function_exists( 'is_page' ) ) {
			$rejected_page = is_page( $store['page_id'] );
		}
		$flags = array(
			'is_admin'          => function_exists( 'is_admin' ) && is_admin(),
			'is_404'            => function_exists( 'is_404' ) && is_404(),
			'is_search'         => function_exists( 'is_search' ) && is_search(),
			'is_feed'           => function_exists( 'is_feed' ) && is_feed(),
			'donotcachepage'    => defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE,
			'is_user_logged_in' => function_exists( 'is_user_logged_in' ) && is_user_logged_in(),
			'is_rejected_page'  => $rejected_page,
			// Front page, archives, chosen content types… (wpopt_pc_type_rejected()). Only asked
			// when the site excluded something, so other sites pay nothing for it.
			'is_rejected_type'  => ! empty( $store['types'] ) && function_exists( 'wpopt_pc_type_rejected' ) && function_exists( 'wpopt_pc_page_context' ) && wpopt_pc_type_rejected( $store['types'], wpopt_pc_page_context() ),
			// Did anything in this render hand the visitor a cookie? Read at buffer close,
			// so every header the request was going to send has been sent by now.
			'sets_cookie'       => wpopt_pc_response_sets_cookie( headers_list() ),
			// The origin said "not for a shared cache" (private / no-store): we are one.
			// A text file is judged by wpopt_pc_text_response_ok() instead, where no-store is not a refusal.
			'forbids_store'     => empty( $store['text'] ) && function_exists( 'wpopt_pc_response_forbids_store' ) && wpopt_pc_response_forbids_store( headers_list() ),
			'is_rest'           => ! empty( $store['rest'] ) && empty( $store['text'] ),
			'is_text'           => ! empty( $store['text'] ),
			'text_type_ok'      => ! empty( $store['text'] ) && function_exists( 'wpopt_pc_text_response_ok' ) && wpopt_pc_text_response_ok( headers_list() ),
			// A render cut short: the client left (and PHP stopped), the time limit, a fatal error.
			'incomplete'        => function_exists( 'wpopt_pc_render_incomplete' ) && wpopt_pc_render_incomplete( connection_status(), (bool) ignore_user_abort(), error_get_last() ),
		);
		$code  = function_exists( 'http_response_code' ) ? (int) http_response_code() : 200;
		// The reason is what the hit diagnostics count; an older engine only answers yes or no.
		$why  = function_exists( 'wpopt_pc_late_reason' ) ? wpopt_pc_late_reason( $code, $buffer, $flags ) : ( wpopt_pc_can_store( $code, $buffer, $flags ) ? '' : 'other' );
		$keep = '' === $why;
		// Exclusions saved while this page rendered: it was judged by the old ones, and the purge
		// that followed the save may already be over. Read last, only when about to store.
		if ( $keep && function_exists( 'wpopt_pc_config_changed' ) && wpopt_pc_config_changed( $store['cfgfile'], $store['cfg'] ) ) {
			$keep = false;
			$why  = 'config';
		}
		// On a network, the sidecar was picked by host and path before WordPress ran: a page is kept
		// only when WordPress loaded that same site. A sunrise.php or a site_by_path filter can send
		// the request elsewhere, and another site's page must never be filed under this one.
		if ( $keep && ! empty( $store['blog'] ) && function_exists( 'get_current_blog_id' ) && (int) get_current_blog_id() !== (int) $store['blog'] ) {
			$keep = false;
			$why  = 'site';
		}
		// And the copy must land in that site's folder, outside the folders of the sites under it.
		if ( $keep && '' !== $store['site'] && function_exists( 'wpopt_pc_copy_in_site' ) && ! wpopt_pc_copy_in_site( $store['plain'], $store['site'], $store['base'], $store['sitekey'] ) ) {
			$keep = false;
			$why  = 'site';
		}
		// Content purged while this page rendered: what it shows may be what the purge removed.
		if ( $keep && isset( $store['start'] ) && function_exists( 'wpopt_pc_purged_after' ) && wpopt_pc_purged_after( $store['plain'], $store['base'], $store['start'], isset( $store['site'] ) ? $store['site'] : '' ) ) {
			$keep = false;
			$why  = 'purged';
		}
		// A price variant only while the URL has room for one more (wpopt_pc_variant_room()).
		if ( $keep && false !== strpos( basename( $store['plain'] ), '-c' ) && function_exists( 'wpopt_pc_variant_room' ) ) {
			$keep = wpopt_pc_variant_room( $store['plain'] );
			$why  = $keep ? '' : 'variants';
		}
		// Counted, and one MISS in twenty sampled for the paths that fail most (an estimate).
		if ( function_exists( 'wpopt_pc_stats_record' ) && 'miss' === wpopt_pc_stats_record( $keep ? 'miss' : 'bypass-' . $why ) && 1 === mt_rand( 1, 20 ) ) {
			wpopt_pc_stats_sample( $GLOBALS['wpopt_pc_stats']['dir'], isset( $store['path'] ) ? $store['path'] : '' );
		}
		if ( ! headers_sent() ) {
			header( 'X-WPOpt-Cache: ' . ( $keep ? 'MISS' : 'BYPASS' ) );
		}
		// HTML at Cloudflare's edge: the same verdict tells the edge whether it may keep the page.
		if ( '' !== $store['cf'] && ! headers_sent() ) {
			header( $keep ? $store['cf'] : 'Cloudflare-CDN-Cache-Control: no-store' );
		}
		if ( $keep ) {
			// Last chance to rework the page once, before it is served from disk for hours. Never a
			// JSON answer: the HTML filters (minification, preloads) would break it.
			if ( empty( $store['rest'] ) && function_exists( 'apply_filters' ) ) {
				try {
					$buffer = (string) apply_filters( 'wpoptimizing_page_cache_html', $buffer );
				} catch ( Throwable $e ) {
					unset( $e ); // A filter that fails must not take the page with it.
				}
			}
			$kept_now = function_exists( 'wpopt_pc_kept_headers' ) ? wpopt_pc_kept_headers( headers_list() ) : array();
			// Each coding the site stores, compressed once here; one that is off or fails this
			// time loses its old file (wpopt_pc_publish_copy()), so no .br outlives its page.
			$bodies = array(
				'gzip' => ! empty( $store['gzip'] ) ? wpopt_pc_compress( $buffer, 'gzip' ) : false,
				'br'   => ! empty( $store['brotli'] ) ? wpopt_pc_compress( $buffer, 'br' ) : false,
			);
			$hours  = function_exists( 'wpopt_pc_static_hours' ) ? wpopt_pc_static_hours( time(), $store['ttl'], $store['offset'] ) : array();
			$twin   = array(
				// A page that sends security headers from PHP (CSP, HSTS…) is served without PHP only
				// when the web server sends them too, from the site folder's .htaccess.
				'wanted' => ! empty( $store['static'] ) && empty( $store['rest'] ) && ( function_exists( 'wpopt_pc_static_headers_ready' )
					? wpopt_pc_static_headers_ready( wpopt_pc_static_headers_folder( $store['plain'], $store['base'], isset( $store['site'] ) ? $store['site'] : '' ), $kept_now )
					: ( function_exists( 'wpopt_pc_static_headers_ok' ) ? wpopt_pc_static_headers_ok( $kept_now ) : empty( $kept_now ) ) ),
				'hours'  => $hours,
			);
			// The last check, made again right before the files go live: a save that rewrote the
			// sidecar, or a content purge, while this page was filtered and compressed (Codex,
			// 06/11/2026). A save writes the sidecar before it purges, and a purge leaves its mark
			// before deleting, so one of the two is always seen here.
			$stale = function () use ( $store ) {
				return ( function_exists( 'wpopt_pc_config_changed' ) && wpopt_pc_config_changed( $store['cfgfile'], $store['cfg'] ) )
					|| ( isset( $store['start'] ) && function_exists( 'wpopt_pc_purged_after' ) && wpopt_pc_purged_after( $store['plain'], $store['base'], $store['start'], isset( $store['site'] ) ? $store['site'] : '' ) );
			};
			if ( function_exists( 'wpopt_pc_publish_copy' ) ) {
				// Written under temporary names and published only if that check passes, under the
				// lock a purge needs to leave its mark: nothing is ever deleted afterwards, so a
				// newer request's copy under the same names is never removed (Codex, 08/11/2026).
				wpopt_pc_publish_copy( $store['plain'], $buffer, $bodies, $kept_now, $store['base'], $stale, function_exists( 'wpopt_pc_static_twin' ) ? $twin : array() );
			} else {
				// An engine from before v16 (a plugin rolled back): write, then check once more.
				$written = wpopt_pc_store_copy( $store['plain'], $buffer, $bodies, $kept_now );
				if ( function_exists( 'wpopt_pc_static_twin' ) ) {
					wpopt_pc_static_twin( $store['plain'], $written['gz'], $twin['wanted'], $hours, $written['br'] );
				}
				if ( $stale() && function_exists( 'wpopt_pc_forget_copy' ) ) {
					wpopt_pc_forget_copy( $store['plain'], $store['gz'] );
				}
			}
		}
		// The rebuild is over, stored or not: the next request may take it (the kernel would
		// release it at the end of the process anyway).
		if ( function_exists( 'wpopt_pc_release_lock' ) ) {
			wpopt_pc_release_lock( $store['lock'] );
		}
		$GLOBALS['wpopt_pc_store'] = null;
		return $buffer;
	}
);
