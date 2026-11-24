<?php
/**
 * Cloudflare cache purge.
 *
 * Cloudflare sits in front of the site and keeps its own copy of the static files and, with
 * APO or a "Cache Everything" rule, of the HTML too. Nothing WPOptimizing purges reaches it,
 * so after a cleanup — or after editing a post — visitors could keep getting the old copy from
 * Cloudflare's edge. This purges it through the Cloudflare API:
 *
 * - everything, wherever WPOptimizing flushes the other caches (after a cleanup and from
 *   "Flush all caches"): Cloudflare is one more entry of wpoptimizing_detect_host_cache();
 * - only the affected URLs when content changes, the same list our page cache purges
 *   (wpoptimizing_content_purge_urls()).
 *
 * LOADED ON EVERY REQUEST, ON PURPOSE. The block editor saves through the REST API, where the
 * admin engine (and host-cache.php) is not loaded; a purge on save that lived there would never
 * run for a post edited in Gutenberg. What runs on every request is one option read; the HTTP
 * code only runs when there is something to purge.
 *
 * The API token is stored encrypted with the site's salts (token-crypt.php), never exported
 * with the settings, and never printed back into the form.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// Which URLs a content change makes stale (shared with the page cache).
require_once __DIR__ . '/content-purge.php';

/** Settings: zone id, the two switches and the encrypted token. One option, not autoloaded… */
const WPOPTIMIZING_CF_OPTION = 'wpoptimizing_cloudflare';

/**
 * …except this one, the only thing a visitor request reads, so it is autoloaded and holds just
 * bits: configured (zone and token), purge on cleanup, purge on content change.
 */
const WPOPTIMIZING_CF_ACTIVE_OPTION = 'wpoptimizing_cloudflare_active';

const WPOPTIMIZING_CF_BIT_CONFIGURED = 1;
const WPOPTIMIZING_CF_BIT_CLEANUP    = 2;
const WPOPTIMIZING_CF_BIT_CONTENT    = 4;
const WPOPTIMIZING_CF_BIT_EDGE       = 8; // HTML cached at Cloudflare's edge (cloudflare-rules.php).

/** Outcome of the last purge, for the settings screen. */
const WPOPTIMIZING_CF_LAST_OPTION = 'wpoptimizing_cloudflare_last';

/** Cloudflare accepts at most 30 URLs per purge call on the Free, Pro and Business plans. */
const WPOPTIMIZING_CF_URLS_PER_CALL = 30;

/**
 * The stored settings, with defaults.
 *
 * @return array{zone:string,token:string,on_cleanup:int,on_content:int}
 */
function wpoptimizing_cf_settings() {
	$s = (array) get_option( WPOPTIMIZING_CF_OPTION, array() );
	return array(
		'zone'       => isset( $s['zone'] ) ? (string) $s['zone'] : '',
		'token'      => isset( $s['token'] ) ? (string) $s['token'] : '',
		'on_cleanup' => isset( $s['on_cleanup'] ) ? (int) $s['on_cleanup'] : 1,
		'on_content' => isset( $s['on_content'] ) ? (int) $s['on_content'] : 1,
	);
}

/**
 * The API token in clear, or '' when there is none or it cannot be decrypted.
 *
 * @return string
 */
function wpoptimizing_cf_token() {
	$stored = wpoptimizing_cf_settings()['token'];
	if ( '' === $stored ) {
		return '';
	}
	if ( function_exists( 'wpoptimizing_token_decrypt' ) ) {
		return (string) wpoptimizing_token_decrypt( $stored );
	}
	return '';
}

/**
 * Whether a zone id looks like one: 32 hex characters.
 *
 * @param string $zone Zone id.
 * @return bool
 */
function wpoptimizing_cf_zone_is_valid( $zone ) {
	return 1 === preg_match( '/^[a-f0-9]{32}$/', (string) $zone );
}

/**
 * Whether a value is Cloudflare's Global API Key rather than an API token. Pure.
 *
 * The Global API Key is 37 lowercase hexadecimal characters and authenticates with the account
 * e-mail in two headers; an API token is 40 characters and goes in `Authorization: Bearer`.
 * Pasted into the token field, the key is sent as a bearer and Cloudflare answers
 * "Authentication error (10000)", which says nothing about the actual mistake. It happened on
 * 15/10/2026 with a key that had been working for years in other tools. It is also not
 * something to store here: it opens the whole account, not one zone's purge.
 *
 * @param string $value Pasted value.
 * @return bool
 */
function wpoptimizing_cf_looks_like_global_key( $value ) {
	return 1 === preg_match( '/^[a-f0-9]{37}$/', trim( (string) $value ) );
}

/**
 * Whether Cloudflare purging is configured on this site.
 *
 * @return bool
 */
function wpoptimizing_cf_configured() {
	return wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_CONFIGURED );
}

/**
 * Whether the integration is configured AND the given switch is on.
 *
 * @param int $bit WPOPTIMIZING_CF_BIT_*.
 * @return bool
 */
function wpoptimizing_cf_active( $bit ) {
	$flags = (int) get_option( WPOPTIMIZING_CF_ACTIVE_OPTION, 0 );
	return ( $flags & WPOPTIMIZING_CF_BIT_CONFIGURED ) && ( $flags & (int) $bit );
}

/**
 * Whether this site answers through Cloudflare's proxy. Every proxied request — the admin's
 * included — reaches PHP with a CF-Ray header (and the visitor's address in CF-Connecting-IP), so
 * the screen that offers the integration can say "your site is behind Cloudflare" without asking.
 *
 * @return bool
 */
function wpoptimizing_cf_detected() {
	return ! empty( $_SERVER['HTTP_CF_RAY'] ) || ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- presence check only, the value is never used.
}

/**
 * Save the settings. An empty token field keeps the stored token: the form never prints it.
 *
 * @param string $zone       Zone id.
 * @param string $token      New token, or '' to keep the current one.
 * @param bool   $on_cleanup Purge everything when WPOptimizing flushes caches.
 * @param bool   $on_content Purge the affected URLs when content changes.
 * @param bool   $forget     Remove the stored token and switch the integration off.
 * @return void
 */
function wpoptimizing_cf_save( $zone, $token, $on_cleanup, $on_content, $forget = false ) {
	$current = wpoptimizing_cf_settings();
	$zone    = strtolower( trim( (string) $zone ) );
	$stored  = $current['token'];
	if ( $forget ) {
		$stored = '';
	} elseif ( '' !== trim( (string) $token ) && function_exists( 'wpoptimizing_token_encrypt' ) ) {
		$stored = (string) wpoptimizing_token_encrypt( trim( (string) $token ) );
	}
	update_option(
		WPOPTIMIZING_CF_OPTION,
		array(
			'zone'       => $zone,
			'token'      => $stored,
			'on_cleanup' => $on_cleanup ? 1 : 0,
			'on_content' => $on_content ? 1 : 0,
		),
		false
	);
	$flags = 0;
	if ( wpoptimizing_cf_zone_is_valid( $zone ) && '' !== $stored ) {
		$flags = WPOPTIMIZING_CF_BIT_CONFIGURED
			| ( $on_cleanup ? WPOPTIMIZING_CF_BIT_CLEANUP : 0 )
			| ( $on_content ? WPOPTIMIZING_CF_BIT_CONTENT : 0 )
			// The edge rule outlives a re-save of the form; only cloudflare-rules.php switches it.
			| ( ( (int) get_option( WPOPTIMIZING_CF_ACTIVE_OPTION, 0 ) ) & WPOPTIMIZING_CF_BIT_EDGE );
	}
	update_option( WPOPTIMIZING_CF_ACTIVE_OPTION, $flags, true );
}

/**
 * One call to the purge endpoint.
 *
 * @param array $body {purge_everything:true} or {files:string[]}.
 * @return true|WP_Error
 */
function wpoptimizing_cf_request( array $body ) {
	$zone  = wpoptimizing_cf_settings()['zone'];
	$token = wpoptimizing_cf_token();
	if ( ! wpoptimizing_cf_zone_is_valid( $zone ) || '' === $token ) {
		return new WP_Error( 'wpoptimizing_cf_not_configured', __( 'Cloudflare is not configured.', 'wpoptimizing' ) );
	}
	// A zone known to be another domain's: Cloudflare would say yes and purge nothing of ours.
	$other = wpoptimizing_cf_zone_mismatch();
	if ( '' !== $other ) {
		return new WP_Error(
			'wpoptimizing_cf_other_zone',
			/* translators: %s: domain the zone belongs to. */
			sprintf( __( 'The zone ID belongs to %s, not to this site.', 'wpoptimizing' ), $other )
		);
	}
	/**
	 * Filters the Cloudflare API base URL (a proxy or a test double, for instance).
	 *
	 * @since 0.1.0
	 * @param string $base Base URL, without a trailing slash.
	 */
	$base     = untrailingslashit( (string) apply_filters( 'wpoptimizing_cloudflare_api', 'https://api.cloudflare.com/client/v4' ) );
	$endpoint = $base . '/zones/' . rawurlencode( $zone ) . '/purge_cache';
	$response = wp_remote_post(
		$endpoint,
		array(
			'timeout' => 8,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 === $code && is_array( $data ) && ! empty( $data['success'] ) ) {
		return true;
	}
	// Cloudflare explains itself in errors[].message ("Authentication error", "Invalid zone
	// identifier"…); that is what the screen shows, not a bare status code.
	$message = '';
	if ( is_array( $data ) && ! empty( $data['errors'] ) && is_array( $data['errors'] ) ) {
		$parts = array();
		foreach ( $data['errors'] as $error ) {
			if ( is_array( $error ) && isset( $error['message'] ) ) {
				$parts[] = (string) $error['message'] . ( isset( $error['code'] ) ? ' (' . (int) $error['code'] . ')' : '' );
			}
		}
		$message = implode( '; ', $parts );
	}
	return new WP_Error(
		'wpoptimizing_cf_http',
		'' !== $message ? $message : sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Cloudflare answered with HTTP %d.', 'wpoptimizing' ),
			$code
		)
	);
}

/**
 * Remember how the last purge went, for the settings screen.
 *
 * @param string        $what   'everything' or 'urls'.
 * @param true|WP_Error $result Result.
 * @param int           $count  URLs purged (0 for everything).
 * @return void
 */
function wpoptimizing_cf_record( $what, $result, $count = 0 ) {
	update_option(
		WPOPTIMIZING_CF_LAST_OPTION,
		array(
			'time'  => time(),
			'what'  => (string) $what,
			'count' => (int) $count,
			'ok'    => true === $result,
			'error' => is_wp_error( $result ) ? $result->get_error_message() : '',
		),
		false
	);
}

/**
 * Purge everything in the zone. This is the purge registered in the shared cache flush.
 *
 * @return bool True when Cloudflare confirmed the purge.
 */
function wpoptimizing_cf_purge_everything() {
	if ( ! wpoptimizing_cf_configured() ) {
		return false;
	}
	$result = wpoptimizing_cf_request( array( 'purge_everything' => true ) );
	wpoptimizing_cf_record( 'everything', $result );
	// Whatever else this request queued for Cloudflare is covered now (see the shutdown flushes).
	$GLOBALS['wpoptimizing_cf_purged_everything'] = true;
	return true === $result;
}

/**
 * Our page cache was emptied whole (a theme switch, a menu or widget change, a plugin update…):
 * the copies at Cloudflare's edge are just as stale. Those full purges call the page cache
 * directly, not the shared flush, so until now nothing told Cloudflare. Queued, and sent once at
 * shutdown however many times the page cache is cleared in the request.
 *
 * Only when Cloudflare holds pages for us: HTML at the edge, or the purge on content change.
 *
 * @return void
 */
function wpoptimizing_cf_queue_everything() {
	if ( ! wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_EDGE ) && ! wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_CONTENT ) ) {
		return;
	}
	if ( empty( $GLOBALS['wpoptimizing_cf_everything_queued'] ) ) {
		$GLOBALS['wpoptimizing_cf_everything_queued'] = true;
		add_action( 'shutdown', 'wpoptimizing_cf_flush_everything', 5 ); // Before the URL queue (10).
	}
}
add_action( 'wpoptimizing_page_cache_cleared', 'wpoptimizing_cf_queue_everything' );

/**
 * The page cache of the whole network was emptied (the network purge, a plugin or core update):
 * every site with its own Cloudflare zone is just as stale, not only the one this request runs
 * in, which wpoptimizing_cf_queue_everything() already covers. Each zone once, at shutdown.
 *
 * @return void
 */
function wpoptimizing_cf_queue_network() {
	if ( empty( $GLOBALS['wpoptimizing_cf_network_queued'] ) ) {
		$GLOBALS['wpoptimizing_cf_network_queued'] = true;
		add_action( 'shutdown', 'wpoptimizing_cf_flush_network', 6 ); // After this site's own (5).
	}
}
add_action( 'wpoptimizing_page_cache_network_cleared', 'wpoptimizing_cf_queue_network' );

/**
 * Send the network's purges: one purge-everything per zone of the other sites that keep pages
 * at Cloudflare for us (HTML at the edge or purge on content change).
 *
 * @return void
 */
function wpoptimizing_cf_flush_network() {
	$GLOBALS['wpoptimizing_cf_network_queued'] = false;
	if ( ! function_exists( 'get_sites' ) || ! is_multisite() ) {
		return;
	}
	$here  = get_current_blog_id();
	$zones = array();
	if ( wpoptimizing_cf_configured() ) {
		$zones[ wpoptimizing_cf_settings()['zone'] ] = true; // Purged by this site's own queue.
	}
	foreach ( get_sites(
		array(
			'number'   => 2000,
			'fields'   => 'ids',
			'archived' => 0,
			'spam'     => 0,
			'deleted'  => 0,
		)
	) as $id ) {
		if ( (int) $id === $here ) {
			continue;
		}
		$flags = (int) get_blog_option( (int) $id, WPOPTIMIZING_CF_ACTIVE_OPTION, 0 );
		if ( ! ( $flags & WPOPTIMIZING_CF_BIT_CONFIGURED ) || ! ( $flags & ( WPOPTIMIZING_CF_BIT_EDGE | WPOPTIMIZING_CF_BIT_CONTENT ) ) ) {
			continue;
		}
		switch_to_blog( (int) $id );
		$zone = wpoptimizing_cf_settings()['zone'];
		if ( '' !== $zone && empty( $zones[ $zone ] ) ) {
			$zones[ $zone ]                               = true;
			$GLOBALS['wpoptimizing_cf_purged_everything'] = false;
			wpoptimizing_cf_purge_everything();
		}
		restore_current_blog();
	}
	// This request's own purge already went (priority 5): the flag stays as it found it.
	$GLOBALS['wpoptimizing_cf_purged_everything'] = true;
}

/**
 * Send the queued purge-everything (shutdown), unless the shared flush already sent one.
 *
 * @return void
 */
function wpoptimizing_cf_flush_everything() {
	$GLOBALS['wpoptimizing_cf_everything_queued'] = false;
	if ( empty( $GLOBALS['wpoptimizing_cf_purged_everything'] ) ) {
		wpoptimizing_cf_purge_everything();
	}
}

/**
 * Purge a list of URLs, in calls of at most 30.
 *
 * @param string[] $urls Absolute URLs.
 * @return bool True when every call was confirmed.
 */
function wpoptimizing_cf_purge_urls( array $urls ) {
	$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
	if ( empty( $urls ) || ! wpoptimizing_cf_configured() ) {
		return false;
	}
	$result = true;
	foreach ( array_chunk( $urls, WPOPTIMIZING_CF_URLS_PER_CALL ) as $chunk ) {
		$result = wpoptimizing_cf_request( array( 'files' => $chunk ) );
		if ( true !== $result ) {
			break;
		}
	}
	wpoptimizing_cf_record( 'urls', $result, count( $urls ) );
	return true === $result;
}

/**
 * Queue the URLs a content change made stale. Purged once, at the end of the request: saving a
 * post fires save_post several times (and a term change per term), and one call is enough.
 *
 * @param int $post_id Post id.
 * @return void
 */
function wpoptimizing_cf_queue_post( $post_id ) {
	if ( ! function_exists( 'wpoptimizing_content_purge_urls' ) ) {
		return;
	}
	$urls = wpoptimizing_content_purge_urls( (int) $post_id );
	if ( empty( $urls ) ) {
		return;
	}
	if ( empty( $GLOBALS['wpoptimizing_cf_queue'] ) ) {
		$GLOBALS['wpoptimizing_cf_queue'] = array();
		add_action( 'shutdown', 'wpoptimizing_cf_flush_queue' );
	}
	foreach ( $urls as $url ) {
		$GLOBALS['wpoptimizing_cf_queue'][ $url ] = true;
	}
}

/**
 * Queue the post behind a comment that changed.
 *
 * @param int $comment_id Comment id.
 * @return void
 */
function wpoptimizing_cf_queue_comment( $comment_id ) {
	$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
	if ( $comment && ! empty( $comment->comment_post_ID ) ) {
		wpoptimizing_cf_queue_post( (int) $comment->comment_post_ID );
	}
}

/**
 * Send the queued URLs (shutdown).
 *
 * @return void
 */
function wpoptimizing_cf_flush_queue() {
	$urls                             = array_keys( (array) ( $GLOBALS['wpoptimizing_cf_queue'] ?? array() ) );
	$GLOBALS['wpoptimizing_cf_queue'] = array();
	if ( ! empty( $urls ) && empty( $GLOBALS['wpoptimizing_cf_purged_everything'] ) ) {
		wpoptimizing_cf_purge_urls( $urls );
	}
}

/**
 * Appear in the detected caches, so the shared flush (after a cleanup, "Flush all caches")
 * purges Cloudflare too and lists it among the flushed caches.
 *
 * @param array $found Detected caches.
 * @return array
 */
function wpoptimizing_cf_register_detected( $found ) {
	if ( ! wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_CLEANUP ) ) {
		return $found;
	}
	$found[] = array(
		'id'      => 'cloudflare',
		'label'   => 'Cloudflare',
		'type'    => 'cdn',
		'purge'   => array(
			'kind' => 'function',
			'name' => 'wpoptimizing_cf_purge_everything',
		),
		'managed' => false,
	);
	return $found;
}
add_filter( 'wpoptimizing_detected_caches', 'wpoptimizing_cf_register_detected' );

/**
 * Content-change hooks, only when the integration is on: a visitor request pays one option read.
 *
 * @return void
 */
function wpoptimizing_cf_register_content_hooks() {
	if ( ! wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_CONTENT ) ) {
		return;
	}
	// The same hooks and the same "before the change" captures as the page cache, so a renamed,
	// unpublished or deleted post is purged at its old address here too.
	if ( function_exists( 'wpoptimizing_content_register_captures' ) ) {
		wpoptimizing_content_register_captures();
	}
	$hooks = function_exists( 'wpoptimizing_content_purge_hooks' ) ? wpoptimizing_content_purge_hooks() : array( 'save_post', 'deleted_post', 'trashed_post' );
	foreach ( $hooks as $hook ) {
		add_action( $hook, 'wpoptimizing_cf_queue_post' );
	}
	wpoptimizing_content_register_comment_hooks( 'wpoptimizing_cf_queue_comment' );
	// Stock and sales: the product page and its listings, like a save.
	if ( function_exists( 'wpoptimizing_content_product_hooks' ) ) {
		foreach ( wpoptimizing_content_product_hooks() as $hook ) {
			add_action( $hook, 'wpoptimizing_cf_queue_product' );
		}
	}
}
add_action( 'init', 'wpoptimizing_cf_register_content_hooks' );

/**
 * Queue the URLs of a product whose stock or sale changed.
 *
 * @param mixed $product WC_Product, id or ids.
 * @return void
 */
function wpoptimizing_cf_queue_product( $product ) {
	foreach ( wpoptimizing_content_product_ids( $product ) as $id ) {
		wpoptimizing_cf_queue_post( $id );
	}
}

/*
 * HTML AT CLOUDFLARE'S EDGE (opt-in, cloudflare-rules.php creates the Cache Rule).
 *
 * The rule makes HTML eligible for Cloudflare's cache and caches only what the origin asks for;
 * what asks, page by page, is the page cache drop-in (advanced-cache.php), with a header only
 * Cloudflare's edge reads: `max-age` when it stores or serves the page, `no-store` when it would
 * not store it. So the decision is the one the page cache already makes — logged-in users,
 * sessions and carts, Set-Cookie, 404, search, DONOTCACHEPAGE, excluded pages — and a request
 * the drop-in does not judge carries no header, which the rule treats as "do not cache". That
 * is also why this needs the page cache on: the decision lives in its buffer, the only one that
 * wraps the whole page, and no other file of the plugin opens one.
 */

/**
 * Seconds a page may live at the edge: the page cache's lifetime (10 hours by default).
 *
 * @return int
 */
function wpoptimizing_cf_edge_ttl() {
	$ttl = function_exists( 'wpoptimizing_page_cache_ttl' ) ? (int) wpoptimizing_page_cache_ttl() : 36000;
	/**
	 * Filters how long Cloudflare keeps a page at its edge.
	 *
	 * @since 0.1.0
	 * @param int $ttl Seconds.
	 */
	$ttl = (int) apply_filters( 'wpoptimizing_cloudflare_edge_ttl', $ttl > 0 ? $ttl : 36000 );
	return max( 60, $ttl );
}

/**
 * The edge header for a response. Pure.
 *
 * @param bool $cacheable Whether the page cache would store this response.
 * @param int  $ttl       Seconds.
 * @return string
 */
function wpoptimizing_cf_edge_header( $cacheable, $ttl ) {
	return 'Cloudflare-CDN-Cache-Control: ' . ( $cacheable ? 'max-age=' . max( 60, (int) $ttl ) : 'no-store' );
}

/*
 * A ZONE THAT IS NOT THIS SITE'S, AND FILES REPLACED UNDER THE SAME NAME.
 *
 * Seen on artprojectgroup.es (11/11/2026): a photo replaced in the media library kept showing the
 * old one. Two causes. The zone ID saved on the .es was the .com's (both sites of the network had
 * the same one), and Cloudflare answers success to a purge of URLs that are not in the zone, so
 * every purge of the .es went to the .com and the screen said «confirmed by Cloudflare». And
 * replacing a file under the same name (Enable Media Replace, regenerating thumbnails) purged
 * nothing: Cloudflare keeps uploads for a year.
 */

/**
 * The domain a zone belongs to, as Cloudflare says. A purge-only token may not read the zone:
 * then the answer is '' (unknown), never a guess.
 *
 * @param string $zone  Zone id.
 * @param string $token API token.
 * @return string Zone name in lowercase, or ''.
 */
function wpoptimizing_cf_zone_name( $zone, $token ) {
	if ( ! wpoptimizing_cf_zone_is_valid( $zone ) || '' === (string) $token || ! function_exists( 'wp_remote_get' ) ) {
		return '';
	}
	/** This filter is documented in wpoptimizing_cf_request(). */
	$base     = untrailingslashit( (string) apply_filters( 'wpoptimizing_cloudflare_api', 'https://api.cloudflare.com/client/v4' ) );
	$response = wp_remote_get(
		$base . '/zones/' . rawurlencode( $zone ),
		array(
			'timeout' => 8,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		)
	);
	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return '';
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	return is_array( $data ) && ! empty( $data['success'] ) && isset( $data['result']['name'] ) ? strtolower( (string) $data['result']['name'] ) : '';
}

/**
 * Whether a host is inside a zone (the zone itself or a subdomain of it). Pure.
 *
 * @param string $host      Host of the site.
 * @param string $zone_name Zone name.
 * @return bool
 */
function wpoptimizing_cf_host_in_zone( $host, $zone_name ) {
	$host = strtolower( trim( (string) $host, ". \t\n\r" ) );
	$zone = strtolower( trim( (string) $zone_name, ". \t\n\r" ) );
	if ( '' === $host || '' === $zone ) {
		return false;
	}
	return $host === $zone || substr( $host, -strlen( '.' . $zone ) ) === '.' . $zone;
}

/**
 * Whether two hosts can share a Cloudflare zone (one is the other or a subdomain of it). Pure.
 *
 * @param string $a Host.
 * @param string $b Host.
 * @return bool
 */
function wpoptimizing_cf_hosts_share_zone( $a, $b ) {
	$a = preg_replace( '/^www\./', '', strtolower( (string) $a ) );
	$b = preg_replace( '/^www\./', '', strtolower( (string) $b ) );
	return wpoptimizing_cf_host_in_zone( $a, $b ) || wpoptimizing_cf_host_in_zone( $b, $a );
}

/**
 * Check, once, that the saved zone is this site's, and remember the answer: 'ok', 'other:<name>'
 * or 'unknown' (the token cannot read the zone). Run on save and when the settings screen opens.
 *
 * @param bool $force Ask again even if there is an answer.
 * @return string
 */
function wpoptimizing_cf_zone_check( $force = false ) {
	$s     = (array) get_option( WPOPTIMIZING_CF_OPTION, array() );
	$zone  = isset( $s['zone'] ) ? (string) $s['zone'] : '';
	$known = isset( $s['zone_check'], $s['zone_checked_for'] ) && $s['zone_checked_for'] === $zone ? (string) $s['zone_check'] : '';
	if ( '' !== $known && ! $force ) {
		return $known;
	}
	if ( ! wpoptimizing_cf_zone_is_valid( $zone ) || '' === wpoptimizing_cf_token() ) {
		return 'unknown';
	}
	$name   = wpoptimizing_cf_zone_name( $zone, wpoptimizing_cf_token() );
	$host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$answer = '' === $name ? 'unknown' : ( wpoptimizing_cf_host_in_zone( $host, $name ) ? 'ok' : 'other:' . $name );

	$s['zone_check']       = $answer;
	$s['zone_checked_for'] = $zone;
	update_option( WPOPTIMIZING_CF_OPTION, $s, false );
	return $answer;
}

/**
 * The domain the saved zone belongs to when it is not this site's, or ''.
 *
 * @return string
 */
function wpoptimizing_cf_zone_mismatch() {
	$s     = (array) get_option( WPOPTIMIZING_CF_OPTION, array() );
	$check = isset( $s['zone_check'], $s['zone_checked_for'], $s['zone'] ) && $s['zone_checked_for'] === $s['zone'] ? (string) $s['zone_check'] : '';
	return 0 === strpos( $check, 'other:' ) ? substr( $check, 6 ) : '';
}

/**
 * Other sites of the network that use this same zone ID on a domain that cannot share it.
 * Works with a purge-only token: it only compares what the network has saved.
 *
 * @return string[] Hosts.
 */
function wpoptimizing_cf_zone_shared_with() {
	if ( ! is_multisite() || ! function_exists( 'get_sites' ) ) {
		return array();
	}
	$mine = wpoptimizing_cf_settings()['zone'];
	if ( ! wpoptimizing_cf_zone_is_valid( $mine ) ) {
		return array();
	}
	$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	$out  = array();
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 500,
		)
	) as $site_id ) {
		if ( (int) $site_id === get_current_blog_id() ) {
			continue;
		}
		$other = get_blog_option( (int) $site_id, WPOPTIMIZING_CF_OPTION );
		if ( ! is_array( $other ) || strtolower( (string) ( $other['zone'] ?? '' ) ) !== $mine ) {
			continue;
		}
		$other_host = (string) wp_parse_url( get_home_url( (int) $site_id, '/' ), PHP_URL_HOST );
		if ( ! wpoptimizing_cf_hosts_share_zone( $host, $other_host ) ) {
			$out[] = $other_host;
		}
	}
	return $out;
}

/**
 * Every URL of an attachment's files: the main file, the untouched original, each size, and the
 * WebP/AVIF copies next to them under both namings (photo.jpg.webp and photo.webp). Pure.
 *
 * @param string $base_url URL of the uploads folder, without a trailing slash.
 * @param array  $meta     Attachment metadata (file, original_image, sizes).
 * @return string[]
 */
function wpoptimizing_cf_attachment_file_urls( $base_url, array $meta ) {
	$file = isset( $meta['file'] ) ? ltrim( (string) $meta['file'], '/' ) : '';
	if ( '' === $file ) {
		return array();
	}
	$dir   = untrailingslashit( (string) $base_url ) . '/' . ( false !== strpos( $file, '/' ) ? dirname( $file ) . '/' : '' );
	$names = array( basename( $file ) );
	if ( ! empty( $meta['original_image'] ) ) {
		$names[] = basename( (string) $meta['original_image'] );
	}
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		if ( is_array( $size ) && ! empty( $size['file'] ) ) {
			$names[] = basename( (string) $size['file'] );
		}
	}
	$urls = array();
	foreach ( array_unique( $names ) as $name ) {
		$urls[] = $dir . $name;
		$stem   = (string) preg_replace( '/\.[^.]+$/', '', $name );
		foreach ( array( 'webp', 'avif' ) as $ext ) {
			$urls[] = $dir . $name . '.' . $ext;
			$urls[] = $dir . $stem . '.' . $ext;
		}
	}
	return array_values( array_unique( array_diff( $urls, array( $dir ) ) ) );
}

/**
 * An attachment that already existed has new files (replaced, regenerated, re-optimized): queue
 * the URLs of the old and the new files for Cloudflare. A new upload has nothing cached yet.
 *
 * @param mixed $data          New metadata.
 * @param int   $attachment_id Attachment id.
 * @return mixed The metadata, untouched.
 */
function wpoptimizing_cf_queue_attachment( $data, $attachment_id ) {
	if ( ! is_array( $data ) || ! wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_CONTENT ) ) {
		return $data;
	}
	$old = get_post_meta( (int) $attachment_id, '_wp_attachment_metadata', true );
	if ( ! is_array( $old ) || empty( $old['file'] ) ) {
		return $data;
	}
	$uploads = wp_get_upload_dir();
	$base    = isset( $uploads['baseurl'] ) ? (string) $uploads['baseurl'] : '';
	if ( '' === $base ) {
		return $data;
	}
	$urls = array_merge(
		wpoptimizing_cf_attachment_file_urls( $base, $old ),
		wpoptimizing_cf_attachment_file_urls( $base, $data )
	);
	if ( empty( $urls ) ) {
		return $data;
	}
	if ( empty( $GLOBALS['wpoptimizing_cf_queue'] ) ) {
		$GLOBALS['wpoptimizing_cf_queue'] = array();
		add_action( 'shutdown', 'wpoptimizing_cf_flush_queue' );
	}
	foreach ( $urls as $url ) {
		$GLOBALS['wpoptimizing_cf_queue'][ $url ] = true;
	}
	return $data;
}
add_filter( 'wp_update_attachment_metadata', 'wpoptimizing_cf_queue_attachment', 10, 2 );
