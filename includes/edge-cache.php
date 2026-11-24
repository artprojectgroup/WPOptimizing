<?php
/**
 * Caches in front of the site: detect them from the response headers, and purge Varnish natively.
 *
 * A page cache WPOptimizing does not know about is the classic "I changed the page and it still
 * shows the old one". The plugins it coexists with are detected by their code; a server or CDN
 * cache has no code on the site, only headers on the response. The check asks for the home page
 * as an anonymous visitor, twice, and reads what each layer says about itself.
 *
 * When one of those layers is Varnish and nothing on the site purges it (Proxy Cache Purge is
 * the usual plugin), it can be purged directly: a PURGE request for each URL a content change
 * makes stale, and a regex PURGE of the whole site when every cache is flushed. Varnish only
 * honours PURGE when its VCL allows it from this server, which hosts that run Varnish set up.
 *
 * At file scope only hooks; the content hooks only exist when the Varnish purge is on.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// Which URLs a content change makes stale (shared with the page cache).
require_once __DIR__ . '/content-purge.php';

/**
 * Stored state: last check (time, layers found, headers seen) and whether to purge Varnish.
 *
 * @return array{checked:int,layers:array,headers:array,varnish_purge:bool}
 */
function wpoptimizing_edge_cache_state() {
	$s = get_option( 'wpoptimizing_edge_cache', array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'checked'       => (int) ( $s['checked'] ?? 0 ),
		'layers'        => is_array( $s['layers'] ?? null ) ? $s['layers'] : array(),
		'headers'       => is_array( $s['headers'] ?? null ) ? $s['headers'] : array(),
		'varnish_purge' => ! empty( $s['varnish_purge'] ),
	);
}

/**
 * Which cache layers a response's headers reveal. Pure.
 *
 * @param array<string,string> $h Response headers, names lower-cased.
 * @return array<int,array{id:string,label:string,hit:?bool}> hit: true/false when the layer
 *         says so, null when it only shows it is there.
 */
function wpoptimizing_edge_cache_classify( array $h ) {
	$h   = array_change_key_case( array_map( 'strval', $h ), CASE_LOWER );
	$get = static function ( $k ) use ( $h ) {
		return isset( $h[ $k ] ) ? strtolower( trim( $h[ $k ] ) ) : '';
	};
	$hit = static function ( $v ) {
		if ( '' === $v ) {
			return null;
		}
		if ( preg_match( '/\b(hit|stale|updating|revalidated)\b/', $v ) ) {
			return true;
		}
		return preg_match( '/\b(miss|bypass|expired|dynamic|pass|none)\b/', $v ) ? false : null;
	};
	$out = array();
	$add = static function ( $id, $label, $state ) use ( &$out ) {
		$out[] = array(
			'id'    => $id,
			'label' => $label,
			'hit'   => $state,
		);
	};

	if ( '' !== $get( 'cf-ray' ) || '' !== $get( 'cf-cache-status' ) ) {
		$add( 'cloudflare', 'Cloudflare', $hit( $get( 'cf-cache-status' ) ) );
	}
	if ( '' !== $get( 'x-varnish' ) || false !== strpos( $get( 'via' ), 'varnish' ) ) {
		// x-varnish carries two ids on a hit (the request's and the cached object's).
		$ids   = preg_split( '/\s+/', $get( 'x-varnish' ) );
		$state = $hit( $get( 'x-cache' ) );
		if ( null === $state && '' !== $get( 'x-varnish' ) ) {
			$state = count( array_filter( (array) $ids ) ) > 1;
		}
		$add( 'varnish', 'Varnish', $state );
	}
	if ( '' !== $get( 'x-litespeed-cache' ) || 0 === strpos( $get( 'server' ), 'litespeed' ) ) {
		$add( 'litespeed', 'LiteSpeed', $hit( $get( 'x-litespeed-cache' ) ) );
	}
	if ( '' !== $get( 'x-kinsta-cache' ) ) {
		$add( 'kinsta', 'Kinsta', $hit( $get( 'x-kinsta-cache' ) ) );
	}
	if ( '' !== $get( 'wpe-backend' ) || '' !== $get( 'x-cache-group' ) || false !== strpos( $get( 'x-powered-by' ), 'wp engine' ) ) {
		$add( 'wpengine', 'WP Engine', $hit( $get( 'x-cache' ) ) );
	}
	if ( '' !== $get( 'x-sucuri-id' ) || '' !== $get( 'x-sucuri-cache' ) ) {
		$add( 'sucuri', 'Sucuri', $hit( $get( 'x-sucuri-cache' ) ) );
	}
	if ( '' !== $get( 'x-fastly-request-id' ) || false !== strpos( $get( 'x-served-by' ), 'cache-' ) ) {
		$add( 'fastly', 'Fastly', $hit( $get( 'x-cache' ) ) );
	}
	if ( '' !== $get( 'x-amz-cf-id' ) || false !== strpos( $get( 'via' ), 'cloudfront' ) ) {
		$add( 'cloudfront', 'CloudFront', $hit( $get( 'x-cache' ) ) );
	}
	if ( '' !== $get( 'cdn-cache' ) || false !== strpos( $get( 'server' ), 'bunnycdn' ) ) {
		$add( 'bunny', 'Bunny CDN', $hit( $get( 'cdn-cache' ) ) );
	}
	foreach ( array( 'x-fastcgi-cache', 'x-nginx-cache', 'x-proxy-cache', 'x-cache-status', 'x-srcache-fetch-status' ) as $k ) {
		if ( '' !== $get( $k ) ) {
			$add( 'nginx', __( 'Server cache (Nginx)', 'wpoptimizing' ), $hit( $get( $k ) ) );
			break;
		}
	}
	if ( '' !== $get( 'x-wpopt-cache' ) ) {
		$add( 'wpoptimizing', __( 'Page cache (WPOptimizing)', 'wpoptimizing' ), $hit( $get( 'x-wpopt-cache' ) ) );
	}
	if ( empty( $out ) && (int) $get( 'age' ) > 0 ) {
		// Something kept this response for a while, but did not say what it is.
		$add( 'unknown', __( 'Unidentified cache', 'wpoptimizing' ), true );
	}
	return $out;
}

/**
 * Ask for the home page as an anonymous visitor, twice (the first request may be the one that
 * fills the cache), and store what the second one says.
 *
 * @return array The stored state.
 */
function wpoptimizing_edge_cache_check() {
	$args = array(
		'timeout'     => 10,
		'redirection' => 2,
		// Some firewalls answer 403 to WordPress' own user agent.
		'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 WPOptimizing-check',
		/** This filter is documented in wp-includes/class-wp-http-streams.php */
		'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
	);
	$url = home_url( '/' );
	wp_remote_get( $url, $args );
	$res     = wp_remote_get( $url, $args );
	$headers = array();
	if ( ! is_wp_error( $res ) ) {
		foreach ( (array) wp_remote_retrieve_headers( $res )->getAll() as $k => $v ) {
			$headers[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v;
		}
	}
	$state            = wpoptimizing_edge_cache_state();
	$state['checked'] = time();
	$state['layers']  = wpoptimizing_edge_cache_classify( $headers );
	// Only the headers that identify a cache, for the screen: never cookies.
	$state['headers'] = array_intersect_key( $headers, array_flip( array( 'server', 'via', 'age', 'cache-control', 'x-cache', 'cf-cache-status', 'x-varnish', 'x-litespeed-cache', 'x-kinsta-cache', 'x-sucuri-cache', 'x-fastcgi-cache', 'x-nginx-cache', 'x-proxy-cache', 'x-cache-status', 'cdn-cache', 'x-wpopt-cache' ) ) );
	if ( ! wpoptimizing_edge_cache_has( $state, 'varnish' ) ) {
		$state['varnish_purge'] = false;
	}
	update_option( 'wpoptimizing_edge_cache', $state, true );
	return $state;
}

/**
 * Was this layer found by the last check?
 *
 * @param array  $state State.
 * @param string $id    Layer id.
 * @return bool
 */
function wpoptimizing_edge_cache_has( array $state, $id ) {
	foreach ( (array) $state['layers'] as $l ) {
		if ( isset( $l['id'] ) && $id === $l['id'] ) {
			return true;
		}
	}
	return false;
}

/**
 * The layers in front of the site that keep copies of its PAGES and that our purges do not
 * reach. Pure.
 *
 * Installing our page cache under one of them makes two copies of every page, and a change
 * clears ours while the host's keeps serving the old one. Cloudflare is purged by us, and
 * Varnish too when its purge is on, so they are not here. A CDN that only carries the files
 * does not count either: only a layer that SERVED THE HOME PAGE FROM ITS CACHE (hit) does, and
 * the hosts that cache every page by design (WP Engine, Kinsta, Pantheon, Pressable) count by
 * being there.
 *
 * @param array $state       wpoptimizing_edge_cache_state().
 * @param bool  $varnish_ok  Our Varnish purge is on.
 * @param array $env         Hosts found by their constants (wpoptimizing_edge_host_by_env()).
 * @return string[] Labels.
 */
function wpoptimizing_edge_host_page_caches( array $state, $varnish_ok, array $env = array() ) {
	$out = array_values( $env );
	foreach ( (array) ( $state['layers'] ?? array() ) as $l ) {
		$id = (string) ( $l['id'] ?? '' );
		if ( in_array( $id, array( 'cloudflare', 'wpoptimizing' ), true ) || ( 'varnish' === $id && $varnish_ok ) ) {
			continue;
		}
		$by_design = in_array( $id, array( 'wpengine', 'kinsta' ), true );
		if ( $by_design || true === ( $l['hit'] ?? null ) ) {
			$out[] = (string) ( $l['label'] ?? $id );
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Managed hosts that cache every page by design, by the constant their platform defines.
 *
 * @return array<string,string> Constant => label.
 */
function wpoptimizing_edge_host_by_env() {
	$out = array();
	foreach ( array(
		'WPE_APIKEY'           => 'WP Engine',
		'PANTHEON_ENVIRONMENT' => 'Pantheon',
		'IS_PRESSABLE'         => 'Pressable',
	) as $const => $label ) {
		if ( defined( $const ) ) {
			$out[ $const ] = $label;
		}
	}
	return $out;
}

/**
 * Does something on the site already purge Varnish (Proxy Cache Purge)? Then purging it here
 * too would just double every request.
 *
 * @return bool
 */
function wpoptimizing_varnish_purged_elsewhere() {
	return class_exists( 'VarnishPurger', false );
}

/**
 * Is the native Varnish purge on (and still meaningful)?
 *
 * @return bool
 */
function wpoptimizing_varnish_purge_on() {
	$s = wpoptimizing_edge_cache_state();
	return $s['varnish_purge'] && ! wpoptimizing_varnish_purged_elsewhere();
}

/**
 * Send PURGE requests. The address can point at Varnish itself (filter
 * wpoptimizing_varnish_address, e.g. "127.0.0.1:6081") with the site's Host header, for setups
 * where the public name resolves to a CDN in front of it.
 *
 * @param string[] $urls  Absolute URLs.
 * @param bool     $regex Purge everything under the URL (Proxy Cache Purge's X-Purge-Method).
 * @return void
 */
function wpoptimizing_varnish_purge( array $urls, $regex = false ) {
	/**
	 * Filters where PURGE requests go: '' = the URL's own host, or "host:port" of Varnish.
	 *
	 * @since 0.1.0
	 * @param string $address Address.
	 */
	$address = (string) apply_filters( 'wpoptimizing_varnish_address', '' );
	foreach ( array_slice( array_values( array_unique( $urls ) ), 0, 50 ) as $url ) {
		$p = wp_parse_url( (string) $url );
		if ( empty( $p['host'] ) ) {
			continue;
		}
		$path    = ( $p['path'] ?? '/' ) . ( $regex ? '.*' : '' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		$target  = '' !== $address ? 'http://' . $address . $path : $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $path;
		$headers = array( 'Host' => $p['host'] );
		if ( $regex ) {
			$headers['X-Purge-Method'] = 'regex';
		}
		wp_remote_request(
			$target,
			array(
				'method'    => 'PURGE',
				'timeout'   => 3,
				'blocking'  => false,
				'headers'   => $headers,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
			)
		);
	}
}

/**
 * Purge everything (used by "Flush all caches" and after a cleanup).
 *
 * @return void
 */
function wpoptimizing_varnish_purge_all() {
	wpoptimizing_varnish_purge( array( home_url( '/' ) ), true );
	$GLOBALS['wpoptimizing_varnish_purged_all'] = true; // The URL queue of this request is covered.
}

/**
 * Our page cache was emptied whole (theme, menus, widgets, a plugin update…): Varnish holds the
 * same stale pages. Those full purges call the page cache directly, not the shared flush, so
 * nothing reached Varnish. One purge of everything, at shutdown.
 *
 * @return void
 */
function wpoptimizing_varnish_queue_all() {
	if ( ! empty( $GLOBALS['wpoptimizing_varnish_all_queued'] ) || ! wpoptimizing_varnish_purge_on() ) {
		return;
	}
	$GLOBALS['wpoptimizing_varnish_all_queued'] = true;
	add_action( 'shutdown', 'wpoptimizing_varnish_flush_all_queue', 5 ); // Before the URL queue (10).
}
add_action( 'wpoptimizing_page_cache_cleared', 'wpoptimizing_varnish_queue_all' );

/**
 * Send the queued purge of everything (shutdown), unless the shared flush already did.
 *
 * @return void
 */
function wpoptimizing_varnish_flush_all_queue() {
	$GLOBALS['wpoptimizing_varnish_all_queued'] = false;
	if ( empty( $GLOBALS['wpoptimizing_varnish_purged_all'] ) ) {
		wpoptimizing_varnish_purge_all();
	}
}

/**
 * Queue the URLs a content change made stale; sent once at shutdown.
 *
 * @param int $post_id Post id.
 * @return void
 */
function wpoptimizing_varnish_queue_post( $post_id ) {
	if ( ! function_exists( 'wpoptimizing_content_purge_urls' ) ) {
		return;
	}
	$urls = wpoptimizing_content_purge_urls( (int) $post_id );
	if ( empty( $urls ) ) {
		return;
	}
	if ( empty( $GLOBALS['wpoptimizing_varnish_queue'] ) ) {
		$GLOBALS['wpoptimizing_varnish_queue'] = array();
		add_action( 'shutdown', 'wpoptimizing_varnish_flush_queue' );
	}
	foreach ( $urls as $url ) {
		$GLOBALS['wpoptimizing_varnish_queue'][ $url ] = true;
	}
}

/**
 * Queue the post behind a comment that changed.
 *
 * @param int $comment_id Comment id.
 * @return void
 */
function wpoptimizing_varnish_queue_comment( $comment_id ) {
	$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
	if ( $comment && ! empty( $comment->comment_post_ID ) ) {
		wpoptimizing_varnish_queue_post( (int) $comment->comment_post_ID );
	}
}

/**
 * Send the queued PURGEs (shutdown).
 *
 * @return void
 */
function wpoptimizing_varnish_flush_queue() {
	$urls                                  = array_keys( (array) ( $GLOBALS['wpoptimizing_varnish_queue'] ?? array() ) );
	$GLOBALS['wpoptimizing_varnish_queue'] = array();
	if ( ! empty( $urls ) && empty( $GLOBALS['wpoptimizing_varnish_purged_all'] ) ) {
		wpoptimizing_varnish_purge( $urls );
	}
}

/**
 * Appear among the detected caches, so the shared flush purges Varnish too.
 *
 * @param array $found Detected caches.
 * @return array
 */
function wpoptimizing_varnish_register_detected( $found ) {
	if ( ! wpoptimizing_varnish_purge_on() ) {
		return $found;
	}
	$found[] = array(
		'id'      => 'varnish',
		'label'   => 'Varnish',
		'type'    => 'page',
		'purge'   => array(
			'kind' => 'function',
			'name' => 'wpoptimizing_varnish_purge_all',
		),
		'managed' => false,
	);
	return $found;
}
add_filter( 'wpoptimizing_detected_caches', 'wpoptimizing_varnish_register_detected' );

/**
 * Content-change hooks, only when the Varnish purge is on.
 *
 * @return void
 */
function wpoptimizing_varnish_register_content_hooks() {
	if ( ! wpoptimizing_varnish_purge_on() ) {
		return;
	}
	// Same hooks and "before the change" captures as the page cache (wpoptimizing_content_purge_urls()).
	if ( function_exists( 'wpoptimizing_content_register_captures' ) ) {
		wpoptimizing_content_register_captures();
	}
	$hooks = function_exists( 'wpoptimizing_content_purge_hooks' ) ? wpoptimizing_content_purge_hooks() : array( 'save_post', 'deleted_post', 'trashed_post' );
	foreach ( $hooks as $hook ) {
		add_action( $hook, 'wpoptimizing_varnish_queue_post' );
	}
	foreach ( array( 'comment_post', 'wp_set_comment_status', 'edit_comment' ) as $hook ) {
		add_action( $hook, 'wpoptimizing_varnish_queue_comment' );
	}
	// Stock and sales: the product page and its listings, like a save.
	if ( function_exists( 'wpoptimizing_content_product_hooks' ) ) {
		foreach ( wpoptimizing_content_product_hooks() as $hook ) {
			add_action( $hook, 'wpoptimizing_varnish_queue_product' );
		}
	}
}
add_action( 'init', 'wpoptimizing_varnish_register_content_hooks' );

/**
 * Queue the URLs of a product whose stock or sale changed.
 *
 * @param mixed $product WC_Product, id or ids.
 * @return void
 */
function wpoptimizing_varnish_queue_product( $product ) {
	foreach ( wpoptimizing_content_product_ids( $product ) as $id ) {
		wpoptimizing_varnish_queue_post( $id );
	}
}
