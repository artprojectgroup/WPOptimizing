<?php
/**
 * The hosting's page cache, purged on every change: WPOptimizing as the manager of that layer.
 *
 * On WP Engine, Kinsta, SiteGround, Cloudways, Pressable, RunCloud, GridPane, Pantheon or an
 * Nginx FastCGI cache run by Nginx Helper, the host keeps its own copy of every page. Our page
 * cache refuses to install there (two copies of each page: wpoptimizing_edge_host_page_caches()),
 * but the host's copy still goes stale on everything the host itself does not hear about: a
 * WooCommerce stock change, a translation, a cleanup, a menu, a plugin update. So this file
 * purges it, whether our page cache is on or off.
 *
 * Two pieces:
 *
 * - ADAPTERS, one per host, independent of each other: how to tell the host is there (a class,
 *   function or constant of its own platform or plugin), what it can purge ('url' and/or 'all')
 *   and the exact call. Every call was read in the code of a plugin that integrates it (the
 *   ZIPs of the lab's corpus, 08/11/2026): WP Rocket's inc/ThirdParty/Hostings, FlyingPress'
 *   src/Integrations/Hosting, and the hosts' own plugins (sg-cachepress, breeze, nginx-helper,
 *   pantheon-advanced-page-cache). Where those references only show a purge of everything
 *   (WP Engine, Pressable, RunCloud, GridPane), the adapter does not invent a selective one.
 *
 * - The COORDINATOR. It listens to the same content events as the page cache (the URL lists come
 *   from content-purge.php) and to the global ones (theme, menus, customizer, widgets, permalinks,
 *   terms, plugin and theme updates, our own full purges and "Flush all caches"), queues and
 *   deduplicates per request, and sends everything at shutdown:
 *     · a purge of everything cancels that host's URL purges, and happens at most once;
 *     · each URL is purged once per host and request;
 *     · a host that can only purge everything is NEVER purged for a content change: purging all
 *       of WP Engine on every post saved would empty its cache all day. Only global events;
 *     · a host that already purges a kind of change by itself (SiteGround, Breeze and Nginx Helper
 *       hear post saves, Pantheon Advanced Page Cache too) is left alone for that kind;
 *     · SiteGround, Breeze and Nginx Helper are already purged whole by the shared flush
 *       (host-cache.php's catalog): after it, they are marked done for the request;
 *     · nothing an adapter fires while purging is heard (no loops).
 *   The result of each host's last purge (ok/failed, scope, how many URLs) is kept for the
 *   "Browser & server" screen.
 *
 * The native Varnish purge (edge-cache.php) keeps its own queue: it is our own PURGE, not a
 * host's API, and is switched on by hand.
 *
 * Free (Lite): purging the caches we live alongside is coexistence, not a page cache feature.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// The URLs a content change makes stale (shared with the page cache, Cloudflare and Varnish).
require_once __DIR__ . '/content-purge.php';

/**
 * The adapters. Each: label, available (callable → bool), scopes (array or callable → array of
 * 'url'|'all'), purge_urls (callable taking string[] → bool|null), purge_all (callable → bool|null),
 * self (array or callable → kinds of change the host purges by itself) and shared_flush (the
 * catalog of host-cache.php already purges it whole on "Flush all caches").
 *
 * @return array<string,array>
 */
function wpoptimizing_host_purge_adapters() {
	$adapters = array(
		// wp-rocket/inc/ThirdParty/Hostings/WPEngine.php:58 (clean_wpengine), and
		// flying-press/src/Integrations/Hosting/WPEngine.php: both methods, no arguments. Global only.
		'wpengine'     => array(
			'label'     => 'WP Engine',
			'available' => 'wpoptimizing_host_purge_wpengine_available',
			'scopes'    => array( 'all' ),
			'purge_all' => 'wpoptimizing_host_purge_wpengine_all',
		),
		// wp-rocket/inc/ThirdParty/Hostings/Kinsta.php:47 (purge_complete_caches) and :98
		// (GET trailingslashit( $url ) . 'kinsta-clear-cache/', non-blocking).
		'kinsta'       => array(
			'label'      => 'Kinsta',
			'available'  => 'wpoptimizing_host_purge_kinsta_available',
			'scopes'     => 'wpoptimizing_host_purge_kinsta_scopes',
			'purge_urls' => 'wpoptimizing_host_purge_kinsta_urls',
			'purge_all'  => 'wpoptimizing_host_purge_kinsta_all',
		),
		// sg-cachepress/helpers/helpers.php:17,50; its own post and comment purges live in
		// core/Supercacher/Supercacher_Posts.php and Supercacher_Comments.php.
		'siteground'   => array(
			'label'        => 'SiteGround',
			'available'    => 'wpoptimizing_host_purge_siteground_available',
			'scopes'       => array( 'url', 'all' ),
			'purge_urls'   => 'wpoptimizing_host_purge_siteground_urls',
			'purge_all'    => 'wpoptimizing_host_purge_siteground_all',
			'self'         => array( 'post', 'comment' ),
			'shared_flush' => true,
		),
		// breeze/inc/cache/purge-varnish.php:207-259 (URLPURGE one URL, PURGE everything with the
		// ?breeze marker); it purges posts, comments and theme switches itself (same file, init()).
		'breeze'       => array(
			'label'        => 'Cloudways (Breeze Varnish)',
			'available'    => 'wpoptimizing_host_purge_breeze_available',
			'scopes'       => array( 'url', 'all' ),
			'purge_urls'   => 'wpoptimizing_host_purge_breeze_urls',
			'purge_all'    => 'wpoptimizing_host_purge_breeze_all',
			'self'         => array( 'post', 'comment', 'theme' ),
			'shared_flush' => true,
		),
		// wp-rocket/inc/ThirdParty/Hostings/Pressable.php:37: wp_cache_flush() (Batcache lives in
		// the object cache). Global only.
		'pressable'    => array(
			'label'        => 'Pressable',
			'available'    => 'wpoptimizing_host_purge_pressable_available',
			'scopes'       => array( 'all' ),
			'purge_all'    => 'wpoptimizing_host_purge_pressable_all',
			// "Flush all caches" with scope 'all' already empties the object cache.
			'object_flush' => true,
		),
		// nginx-helper/includes/class-nginx-helper.php:240 (the rt_nginx_helper_purge_all action)
		// and the purger it keeps in $nginx_purger (admin/class-purger.php:27, public purge_url()).
		// It purges posts, comments, terms and updates itself (class-nginx-helper.php:225-250).
		'nginx-helper' => array(
			'label'        => 'Nginx Helper',
			'available'    => 'wpoptimizing_host_purge_nginx_helper_available',
			'scopes'       => array( 'url', 'all' ),
			'purge_urls'   => 'wpoptimizing_host_purge_nginx_helper_urls',
			'purge_all'    => 'wpoptimizing_host_purge_nginx_helper_all',
			'self'         => array( 'post', 'comment', 'term', 'update' ),
			'shared_flush' => true,
		),
		// flying-press/src/Integrations/Hosting/RunCloud.php: RunCloud_Hub::purge_cache_all(). The
		// network-wide purge_cache_all_sites() is not used: one site's change is not the network's.
		'runcloud'     => array(
			'label'     => 'RunCloud',
			'available' => 'wpoptimizing_host_purge_runcloud_available',
			'scopes'    => array( 'all' ),
			'purge_all' => 'wpoptimizing_host_purge_runcloud_all',
		),
		// flying-press/src/Integrations/Hosting/GridPane.php: new Nginx_Cache_Purger_Admin() and
		// register_purge(). Not Nginx Helper's actions.
		'gridpane'     => array(
			'label'     => 'GridPane',
			'available' => 'wpoptimizing_host_purge_gridpane_available',
			'scopes'    => array( 'all' ),
			'purge_all' => 'wpoptimizing_host_purge_gridpane_all',
		),
		// pantheon-advanced-page-cache/pantheon-advanced-page-cache.php:31,54,73 call the platform's
		// pantheon_clear_edge_paths( $paths ) and pantheon_clear_edge_all(), inside try/catch. When
		// that plugin is active it purges posts, comments and terms by surrogate key (:176-187).
		'pantheon'     => array(
			'label'      => 'Pantheon',
			'available'  => 'wpoptimizing_host_purge_pantheon_available',
			'scopes'     => 'wpoptimizing_host_purge_pantheon_scopes',
			'purge_urls' => 'wpoptimizing_host_purge_pantheon_urls',
			'purge_all'  => 'wpoptimizing_host_purge_pantheon_all',
			'self'       => 'wpoptimizing_host_purge_pantheon_self',
		),
	);
	/**
	 * Filters the hosting purge adapters (add one, or remove one a site does not want).
	 *
	 * @since 0.1.0
	 * @param array $adapters Adapters by id.
	 */
	return (array) apply_filters( 'wpoptimizing_host_purge_adapters', $adapters );
}

/**
 * Kinds of change. A URL kind carries the URLs it made stale; a global kind makes every page stale.
 *
 * @return string[]
 */
function wpoptimizing_host_purge_global_kinds() {
	return array( 'global', 'layout', 'term', 'theme', 'update' );
}

// ----- Adapters ---------------------------------------------------------------------------------

/**
 * WP Engine: its platform class, with one of the two purges.
 *
 * @return bool
 */
function wpoptimizing_host_purge_wpengine_available() {
	return class_exists( 'WpeCommon', false ) && ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) || method_exists( 'WpeCommon', 'purge_memcached' ) );
}

/**
 * WP Engine: everything (its object cache and its Varnish), as WP Rocket and FlyingPress do.
 *
 * @return bool
 */
function wpoptimizing_host_purge_wpengine_all() {
	$done = false;
	if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
		WpeCommon::purge_memcached();
		$done = true;
	}
	if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
		WpeCommon::purge_varnish_cache();
		$done = true;
	}
	return $done;
}

/**
 * Kinsta: the global its must-use plugin leaves, or the cache zone of its platform.
 *
 * @return bool
 */
function wpoptimizing_host_purge_kinsta_available() {
	return isset( $GLOBALS['kinsta_cache'] ) || defined( 'KINSTA_CACHE_ZONE' ) || ! empty( $_SERVER['KINSTA_CACHE_ZONE'] );
}

/**
 * Kinsta: the URL endpoint always; everything only through its plugin's purger.
 *
 * @return string[]
 */
function wpoptimizing_host_purge_kinsta_scopes() {
	return null !== wpoptimizing_host_purge_kinsta_purger() ? array( 'url', 'all' ) : array( 'url' );
}

/**
 * The object behind $kinsta_cache->kinsta_cache_purge, when it has the full purge.
 *
 * @return object|null
 */
function wpoptimizing_host_purge_kinsta_purger() {
	$cache = isset( $GLOBALS['kinsta_cache'] ) ? $GLOBALS['kinsta_cache'] : null;
	$purge = is_object( $cache ) && ! empty( $cache->kinsta_cache_purge ) ? $cache->kinsta_cache_purge : null;
	return is_object( $purge ) && method_exists( $purge, 'purge_complete_caches' ) ? $purge : null;
}

/**
 * Kinsta: a non-blocking GET to each URL's kinsta-clear-cache/ endpoint.
 *
 * @param string[] $urls Absolute URLs.
 * @return bool
 */
function wpoptimizing_host_purge_kinsta_urls( array $urls ) {
	$ok = true;
	foreach ( $urls as $url ) {
		if ( false !== strpos( (string) $url, '?' ) ) {
			continue; // The endpoint is a path: a query string would end up in front of it.
		}
		$res = wp_safe_remote_get(
			trailingslashit( (string) $url ) . 'kinsta-clear-cache/',
			array(
				'blocking' => false,
				'timeout'  => 0.01,
			)
		);
		if ( is_wp_error( $res ) ) {
			$ok = false;
		}
	}
	return $ok;
}

/**
 * Kinsta: everything.
 *
 * @return bool
 */
function wpoptimizing_host_purge_kinsta_all() {
	$purge = wpoptimizing_host_purge_kinsta_purger();
	if ( null === $purge ) {
		return false;
	}
	$purge->purge_complete_caches();
	return true;
}

/**
 * SiteGround Speed Optimizer: its public purge API.
 *
 * @return bool
 */
function wpoptimizing_host_purge_siteground_available() {
	return function_exists( 'sg_cachepress_purge_cache' ) && function_exists( 'sg_cachepress_purge_everything' );
}

/**
 * SiteGround: sg_cachepress_purge_cache( $url ) for each URL — it purges the URL AND everything
 * under it (Supercacher::purge_cache_request( $url, true )). So never for the home page, which
 * would empty the whole site: there SiteGround's own queue purges the home page alone
 * (purge_cache_request( $home, false ), Supercacher.php:214 and process_purge_queue()), and
 * so does this.
 *
 * @param string[] $urls Absolute URLs.
 * @return bool
 */
function wpoptimizing_host_purge_siteground_urls( array $urls ) {
	$ok   = true;
	$home = untrailingslashit( home_url( '/' ) );
	$main = array( 'SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache_request' );
	foreach ( $urls as $url ) {
		if ( untrailingslashit( (string) $url ) === $home ) {
			$res = is_callable( $main ) ? call_user_func( $main, home_url( '/' ), false ) : null;
		} else {
			$res = sg_cachepress_purge_cache( (string) $url );
		}
		if ( false === $res ) {
			$ok = false; // null: its dynamic cache is off, there was nothing to purge.
		}
	}
	return $ok;
}

/**
 * SiteGround: everything.
 *
 * @return bool
 */
function wpoptimizing_host_purge_siteground_all() {
	sg_cachepress_purge_everything();
	return true;
}

/**
 * Breeze (Cloudways): its Varnish purger.
 *
 * @return bool
 */
function wpoptimizing_host_purge_breeze_available() {
	return class_exists( 'Breeze_PurgeVarnish', false ) && method_exists( 'Breeze_PurgeVarnish', 'purge_cache' );
}

/**
 * One Breeze_PurgeVarnish per request (its constructor hooks itself to init and shutdown).
 *
 * @return object
 */
function wpoptimizing_host_purge_breeze_purger() {
	if ( empty( $GLOBALS['wpoptimizing_host_purge_breeze'] ) ) {
		$GLOBALS['wpoptimizing_host_purge_breeze'] = new Breeze_PurgeVarnish();
	}
	return $GLOBALS['wpoptimizing_host_purge_breeze'];
}

/**
 * Breeze: purge_cache( $url ), a URLPURGE of that object. It checks itself that Varnish is on.
 *
 * @param string[] $urls Absolute URLs.
 * @return bool
 */
function wpoptimizing_host_purge_breeze_urls( array $urls ) {
	$ok     = true;
	$purger = wpoptimizing_host_purge_breeze_purger();
	foreach ( $urls as $url ) {
		if ( is_wp_error( $purger->purge_cache( (string) $url ) ) ) {
			$ok = false;
		}
	}
	return $ok;
}

/**
 * Breeze: the ?breeze marker turns it into a PURGE of the whole domain.
 *
 * @return bool
 */
function wpoptimizing_host_purge_breeze_all() {
	return ! is_wp_error( wpoptimizing_host_purge_breeze_purger()->purge_cache( home_url() . '/?breeze' ) );
}

/**
 * Pressable: its platform constant.
 *
 * @return bool
 */
function wpoptimizing_host_purge_pressable_available() {
	return defined( 'IS_PRESSABLE' ) && IS_PRESSABLE;
}

/**
 * Pressable: Batcache lives in the object cache.
 *
 * @return bool
 */
function wpoptimizing_host_purge_pressable_all() {
	return false !== wp_cache_flush();
}

/**
 * Nginx Helper: its constant.
 *
 * @return bool
 */
function wpoptimizing_host_purge_nginx_helper_available() {
	return defined( 'NGINX_HELPER_BASEPATH' );
}

/**
 * Nginx Helper: the public purge_url() of the purger it keeps in $nginx_purger (deletes the file
 * or calls the endpoint, as configured). rt_nginx_helper_purge_url is a filter, not a purge.
 *
 * @param string[] $urls Absolute URLs.
 * @return bool
 */
function wpoptimizing_host_purge_nginx_helper_urls( array $urls ) {
	$purger = isset( $GLOBALS['nginx_purger'] ) ? $GLOBALS['nginx_purger'] : null;
	if ( ! is_object( $purger ) || ! method_exists( $purger, 'purge_url' ) ) {
		return false;
	}
	foreach ( $urls as $url ) {
		$purger->purge_url( (string) $url );
	}
	return true;
}

/**
 * Nginx Helper: everything.
 *
 * @return bool
 */
function wpoptimizing_host_purge_nginx_helper_all() {
	do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own purge action.
	return true;
}

/**
 * RunCloud Hub.
 *
 * @return bool
 */
function wpoptimizing_host_purge_runcloud_available() {
	return class_exists( 'RunCloud_Hub', false ) && is_callable( array( 'RunCloud_Hub', 'purge_cache_all' ) );
}

/**
 * RunCloud: everything (this site).
 *
 * @return bool
 */
function wpoptimizing_host_purge_runcloud_all() {
	call_user_func( array( 'RunCloud_Hub', 'purge_cache_all' ) );
	return true;
}

/**
 * GridPane: its purger class (loaded on demand, as FlyingPress looks for it).
 *
 * @return bool
 */
function wpoptimizing_host_purge_gridpane_available() {
	return class_exists( 'Nginx_Cache_Purger_Admin' );
}

/**
 * GridPane: everything.
 *
 * @return bool
 */
function wpoptimizing_host_purge_gridpane_all() {
	$purger = new Nginx_Cache_Purger_Admin();
	if ( ! method_exists( $purger, 'register_purge' ) ) {
		return false;
	}
	$purger->register_purge();
	return true;
}

/**
 * Pantheon: its platform's edge functions.
 *
 * @return bool
 */
function wpoptimizing_host_purge_pantheon_available() {
	return function_exists( 'pantheon_clear_edge_paths' ) || function_exists( 'pantheon_clear_edge_all' );
}

/**
 * Pantheon: what the platform offers.
 *
 * @return string[]
 */
function wpoptimizing_host_purge_pantheon_scopes() {
	$scopes = array();
	if ( function_exists( 'pantheon_clear_edge_paths' ) ) {
		$scopes[] = 'url';
	}
	if ( function_exists( 'pantheon_clear_edge_all' ) ) {
		$scopes[] = 'all';
	}
	return $scopes;
}

/**
 * Pantheon: with Pantheon Advanced Page Cache active, posts, comments and terms are purged by it.
 *
 * @return string[]
 */
function wpoptimizing_host_purge_pantheon_self() {
	return function_exists( 'pantheon_wp_clear_edge_all' ) ? array( 'post', 'comment', 'term' ) : array();
}

/**
 * Pantheon: one call with the URI paths.
 *
 * @param string[] $urls Absolute URLs.
 * @return bool
 */
function wpoptimizing_host_purge_pantheon_urls( array $urls ) {
	$paths = array();
	foreach ( $urls as $url ) {
		$p = wp_parse_url( (string) $url );
		if ( is_array( $p ) ) {
			$paths[] = ( isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		}
	}
	if ( array() === $paths ) {
		return true;
	}
	try {
		pantheon_clear_edge_paths( array_values( array_unique( $paths ) ) );
	} catch ( \Exception $e ) {
		return false;
	}
	return true;
}

/**
 * Pantheon: everything.
 *
 * @return bool
 */
function wpoptimizing_host_purge_pantheon_all() {
	try {
		pantheon_clear_edge_all();
	} catch ( \Exception $e ) {
		return false;
	}
	return true;
}

// ----- Coordinator -----------------------------------------------------------------------------

/**
 * The adapters present on this site, cached per request.
 *
 * @return array<string,array>
 */
function wpoptimizing_host_purge_available() {
	if ( isset( $GLOBALS['wpoptimizing_host_purge_available'] ) ) {
		return $GLOBALS['wpoptimizing_host_purge_available'];
	}
	$found = array();
	foreach ( wpoptimizing_host_purge_adapters() as $id => $a ) {
		if ( ! empty( $a['available'] ) && is_callable( $a['available'] ) && call_user_func( $a['available'] ) ) {
			$found[ (string) $id ] = (array) $a;
		}
	}
	$GLOBALS['wpoptimizing_host_purge_available'] = $found;
	return $found;
}

/**
 * A list field of an adapter that may be a callable (scopes, self).
 *
 * @param array  $a   Adapter.
 * @param string $key Field.
 * @return string[]
 */
function wpoptimizing_host_purge_list( array $a, $key ) {
	$v = isset( $a[ $key ] ) ? $a[ $key ] : array();
	if ( is_string( $v ) && is_callable( $v ) ) {
		$v = call_user_func( $v );
	}
	return array_values( array_map( 'strval', (array) $v ) );
}

/**
 * The request's purge state: what is queued and what was already sent, per adapter.
 *
 * @return array
 */
function &wpoptimizing_host_purge_state() {
	if ( ! isset( $GLOBALS['wpoptimizing_host_purge'] ) || ! is_array( $GLOBALS['wpoptimizing_host_purge'] ) ) {
		$GLOBALS['wpoptimizing_host_purge'] = array(
			'queue'      => array(), // id => array( 'all' => bool, 'urls' => array( url => true ) ).
			'done'       => array(), // Same shape: sent in this request.
			'log'        => array(), // id => result of this request, written once.
			'scheduled'  => false,
			'running'    => false,
			'dispatched' => false,
			'content'    => array(), // URL => true: the URLs of the posts, comments and products changed here.
			'explicit'   => array(), // URLs our page cache cleared, decided at dispatch.
		);
	}
	return $GLOBALS['wpoptimizing_host_purge'];
}

/**
 * Forget this request's state and detection (tests, and a long-running CLI process).
 *
 * @return void
 */
function wpoptimizing_host_purge_reset() {
	unset( $GLOBALS['wpoptimizing_host_purge'], $GLOBALS['wpoptimizing_host_purge_available'], $GLOBALS['wpoptimizing_host_purge_breeze'] );
}

/**
 * A change happened: queue what each present host has to purge for it.
 *
 * @param string   $kind 'post', 'comment', 'product', 'url' (URL kinds) or a global kind.
 * @param string[] $urls Stale URLs (URL kinds only).
 * @return void
 */
function wpoptimizing_host_purge_request( $kind, array $urls = array() ) {
	$state = &wpoptimizing_host_purge_state();
	if ( $state['running'] ) {
		return; // Something an adapter fired while purging: hearing it would loop.
	}
	$kind = (string) $kind;
	// A URL our page cache cleared is decided at dispatch: when it came with a saved post the host
	// that purges posts by itself does not need it from us, but «Purge this page» or the CLI
	// purge one URL with no save behind them, and that host never hears of it. Treating every URL
	// as a post dropped those (Codex, 08/11/2026).
	if ( 'url' === $kind ) {
		$state['explicit'] = array_merge( $state['explicit'], array_map( 'strval', $urls ) );
		wpoptimizing_host_purge_schedule();
		return;
	}
	if ( in_array( $kind, array( 'post', 'comment', 'product' ), true ) ) {
		foreach ( $urls as $u ) {
			$state['content'][ (string) $u ] = true;
		}
	}
	$global    = in_array( $kind, wpoptimizing_host_purge_global_kinds(), true );
	$self_kind = 'url-explicit' === $kind ? 'url' : ( 'url-final' === $kind ? 'post' : $kind );
	$urls      = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
	$queued    = false;
	// «Purge also these pages» are not part of the change a host heard about: a host that purges
	// saved posts by itself still needs them from us.
	$always = function_exists( 'wpoptimizing_content_always_purge_urls' ) ? array_values( array_intersect( $urls, wpoptimizing_content_always_purge_urls() ) ) : array();
	foreach ( wpoptimizing_host_purge_available() as $id => $a ) {
		$host_urls = $urls;
		if ( in_array( $self_kind, wpoptimizing_host_purge_list( $a, 'self' ), true ) ) {
			if ( $global || array() === $always ) {
				continue;
			}
			$host_urls = $always;
		}
		$scopes = wpoptimizing_host_purge_list( $a, 'scopes' );
		if ( ! empty( $state['done'][ $id ]['all'] ) || ! empty( $state['queue'][ $id ]['all'] ) ) {
			continue; // Everything is (or will be) purged for this host in this request.
		}
		if ( $global ) {
			if ( in_array( 'all', $scopes, true ) && ! empty( $a['purge_all'] ) ) {
				$state['queue'][ $id ] = array(
					'all'  => true,
					'urls' => array(),
				);
				$queued                = true;
			}
			continue;
		}
		// A host that can only purge everything is never purged for one change.
		if ( ! in_array( 'url', $scopes, true ) || empty( $a['purge_urls'] ) ) {
			continue;
		}
		foreach ( $host_urls as $url ) {
			if ( empty( $state['done'][ $id ]['urls'][ $url ] ) ) {
				$state['queue'][ $id ]['all']          = false;
				$state['queue'][ $id ]['urls'][ $url ] = true;
				$queued                                = true;
			}
		}
	}
	if ( $queued ) {
		wpoptimizing_host_purge_schedule();
	}
}

/**
 * Send the queue at shutdown, after our own full page purge (1) and Varnish (5, 10). Once the
 * request's shutdown dispatch has run, a later change in the same shutdown is sent at once.
 *
 * @return void
 */
function wpoptimizing_host_purge_schedule() {
	$state = &wpoptimizing_host_purge_state();
	if ( $state['dispatched'] && function_exists( 'doing_action' ) && doing_action( 'shutdown' ) ) {
		wpoptimizing_host_purge_dispatch();
		return;
	}
	if ( ! $state['scheduled'] ) {
		$state['scheduled'] = true;
		add_action( 'shutdown', 'wpoptimizing_host_purge_dispatch', 20 );
	}
}

/**
 * Run an adapter's purge and say whether it worked. A purge that throws is a failed purge, never
 * a broken request.
 *
 * @param mixed $callback Callable.
 * @param array $args     Arguments.
 * @return bool
 */
function wpoptimizing_host_purge_call( $callback, array $args ) {
	if ( ! is_callable( $callback ) ) {
		return false;
	}
	try {
		$res = call_user_func_array( $callback, $args );
	} catch ( \Throwable $e ) {
		return false;
	}
	return false !== $res && ! is_wp_error( $res );
}

/**
 * Send what is queued: per host, everything or its URLs; then record the results.
 *
 * @return void
 */
function wpoptimizing_host_purge_dispatch() {
	$state = &wpoptimizing_host_purge_state();
	if ( $state['running'] ) {
		return;
	}
	if ( ! empty( $state['explicit'] ) ) {
		// URL by URL: one the saved content in this request covers is part of that change (a host
		// that purges saves by itself already has it); any other one was purged on its own and
		// goes to every host. Deciding for the whole request lost the second kind whenever a post
		// had also been saved (Codex, 08/11/2026).
		$explicit          = array_values( array_unique( $state['explicit'] ) );
		$state['explicit'] = array();
		$covered           = array_values(
			array_filter(
				$explicit,
				static function ( $u ) use ( $state ) {
					return isset( $state['content'][ $u ] );
				}
			)
		);
		$alone             = array_values( array_diff( $explicit, $covered ) );
		if ( $covered ) {
			wpoptimizing_host_purge_request( 'url-final', $covered );
		}
		if ( $alone ) {
			wpoptimizing_host_purge_request( 'url-explicit', $alone );
		}
	}
	$state['running']    = true;
	$state['scheduled']  = false;
	$state['dispatched'] = true;
	$queue               = $state['queue'];
	$state['queue']      = array();
	$adapters            = wpoptimizing_host_purge_available();
	foreach ( $queue as $id => $q ) {
		if ( ! isset( $adapters[ $id ] ) ) {
			continue;
		}
		$a = $adapters[ $id ];
		if ( ! empty( $q['all'] ) ) {
			$state['done'][ $id ]['all'] = true;
			$state['log'][ $id ]         = array(
				'time'  => time(),
				'scope' => 'all',
				'count' => 0,
				'ok'    => wpoptimizing_host_purge_call( $a['purge_all'], array() ),
			);
			continue;
		}
		$urls = array_keys( (array) ( $q['urls'] ?? array() ) );
		if ( array() === $urls ) {
			continue;
		}
		foreach ( $urls as $url ) {
			$state['done'][ $id ]['urls'][ $url ] = true;
		}
		$ok                  = wpoptimizing_host_purge_call( $a['purge_urls'], array( $urls ) );
		$prev                = isset( $state['log'][ $id ] ) && 'url' === $state['log'][ $id ]['scope'] ? $state['log'][ $id ] : null;
		$state['log'][ $id ] = array(
			'time'  => time(),
			'scope' => 'url',
			'count' => count( $urls ) + ( $prev ? (int) $prev['count'] : 0 ),
			'ok'    => $ok && ( $prev ? (bool) $prev['ok'] : true ),
		);
	}
	$state['running'] = false;
	wpoptimizing_host_purge_save_log();
}

/**
 * Keep the last result of each host (for the screen). One write per dispatch, never on a visit
 * that purged nothing.
 *
 * @return void
 */
function wpoptimizing_host_purge_save_log() {
	$state = &wpoptimizing_host_purge_state();
	if ( empty( $state['log'] ) ) {
		return;
	}
	$saved = get_option( 'wpoptimizing_host_purge_log', array() );
	$saved = is_array( $saved ) ? $saved : array();
	foreach ( $state['log'] as $id => $entry ) {
		$saved[ $id ] = $entry;
	}
	$state['log'] = array();
	update_option( 'wpoptimizing_host_purge_log', $saved, false );
}

/**
 * The present hosts and the last purge of each, for the screen.
 *
 * @return array<string,array{label:string,scopes:string[],self:string[],last:?array}>
 */
function wpoptimizing_host_purge_status() {
	$log = get_option( 'wpoptimizing_host_purge_log', array() );
	$log = is_array( $log ) ? $log : array();
	$out = array();
	foreach ( wpoptimizing_host_purge_available() as $id => $a ) {
		$out[ $id ] = array(
			'label'  => (string) ( $a['label'] ?? $id ),
			'scopes' => wpoptimizing_host_purge_list( $a, 'scopes' ),
			'self'   => wpoptimizing_host_purge_list( $a, 'self' ),
			'last'   => isset( $log[ $id ] ) && is_array( $log[ $id ] ) ? $log[ $id ] : null,
		);
	}
	return $out;
}

// ----- Events ----------------------------------------------------------------------------------

/**
 * A post changed: the URLs it made stale, or everything when it is a piece of layout (a template,
 * a template part, a navigation menu…) shown on every page.
 *
 * @param int $post_id Post id.
 * @return void
 */
function wpoptimizing_host_purge_on_post( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return;
	}
	if ( function_exists( 'wpoptimizing_page_cache_is_shared_template' ) && wpoptimizing_page_cache_is_shared_template( $post_id ) ) {
		wpoptimizing_host_purge_request( 'layout' );
		return;
	}
	wpoptimizing_host_purge_request( 'post', wpoptimizing_content_purge_urls( $post_id ) );
}

/**
 * A comment changed: its post's URLs.
 *
 * @param int $comment_id Comment id.
 * @return void
 */
function wpoptimizing_host_purge_on_comment( $comment_id ) {
	$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
	if ( $comment && ! empty( $comment->comment_post_ID ) ) {
		wpoptimizing_host_purge_request( 'comment', wpoptimizing_content_purge_urls( (int) $comment->comment_post_ID ) );
	}
}

/**
 * Stock or a scheduled sale changed: the product's URLs (and its parent's, for a variation).
 *
 * @param mixed $product WC_Product, id or ids.
 * @return void
 */
function wpoptimizing_host_purge_on_product( $product ) {
	$urls = array();
	foreach ( wpoptimizing_content_product_ids( $product ) as $id ) {
		$urls = array_merge( $urls, wpoptimizing_content_purge_urls( $id ) );
	}
	wpoptimizing_host_purge_request( 'product', $urls );
}

/**
 * Our page cache cleared one URL (a content change, or "Purge this page" in the toolbar).
 *
 * @param string $url Absolute URL.
 * @return void
 */
function wpoptimizing_host_purge_on_url( $url ) {
	wpoptimizing_host_purge_request( 'url', array( (string) $url ) );
}

/**
 * Something that changes every page: menus, customizer, widgets, permalinks, plugins, our own full
 * page purge.
 *
 * @return void
 */
function wpoptimizing_host_purge_on_global() {
	wpoptimizing_host_purge_request( 'global' );
}

/**
 * A term was created, edited or deleted (its listings, and the menus that show it).
 *
 * @return void
 */
function wpoptimizing_host_purge_on_term() {
	wpoptimizing_host_purge_request( 'term' );
}

/**
 * The theme was switched.
 *
 * @return void
 */
function wpoptimizing_host_purge_on_theme() {
	wpoptimizing_host_purge_request( 'theme' );
}

/**
 * A plugin, theme or core update finished.
 *
 * @return void
 */
function wpoptimizing_host_purge_on_update() {
	wpoptimizing_host_purge_request( 'update' );
}

/**
 * "Flush all caches" (toolbar, after a cleanup, REST, WP-CLI). The hosts in host-cache.php's
 * catalog were just purged whole by it, and with scope 'all' so was the object cache (Pressable's
 * pages live there): they are done for the request. The rest are queued.
 *
 * @param string $scope 'page' or 'all' (wpoptimizing_flush_all_caches()).
 * @return void
 */
function wpoptimizing_host_purge_on_flush_all( $scope = 'all' ) {
	$state = &wpoptimizing_host_purge_state();
	if ( $state['running'] ) {
		return;
	}
	// The shared flush skips the object cache on a network for anyone below a super admin.
	$object_flushed = 'all' === $scope && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
		&& ( ! is_multisite() || ( defined( 'WP_CLI' ) && WP_CLI ) || current_user_can( 'manage_network_options' ) );
	foreach ( wpoptimizing_host_purge_available() as $id => $a ) {
		if ( ! empty( $a['shared_flush'] ) || ( $object_flushed && ! empty( $a['object_flush'] ) ) ) {
			unset( $state['queue'][ $id ] );
			$state['done'][ $id ]['all'] = true;
			$state['log'][ $id ]         = array(
				'time'  => time(),
				'scope' => 'all',
				'count' => 0,
				'ok'    => true,
			);
		}
	}
	wpoptimizing_host_purge_request( 'global' );
	if ( ! empty( $state['log'] ) && ! $state['scheduled'] ) {
		wpoptimizing_host_purge_schedule(); // Only to record the shared flush's purges.
	}
}

/**
 * Hook the coordinator, only when a host it knows is here. It does not depend on our page cache:
 * the hooks are the same ones, registered on their own.
 *
 * @return void
 */
function wpoptimizing_host_purge_register() {
	if ( array() === wpoptimizing_host_purge_available() ) {
		return;
	}
	wpoptimizing_content_register_captures();
	foreach ( wpoptimizing_content_purge_hooks() as $hook ) {
		add_action( $hook, 'wpoptimizing_host_purge_on_post' );
	}
	wpoptimizing_content_register_comment_hooks( 'wpoptimizing_host_purge_on_comment' );
	foreach ( wpoptimizing_content_product_hooks() as $hook ) {
		add_action( $hook, 'wpoptimizing_host_purge_on_product' );
	}
	add_action( 'wpoptimizing_page_cache_url_cleared', 'wpoptimizing_host_purge_on_url' );
	foreach ( array( 'wp_update_nav_menu', 'customize_save_after', 'update_option_permalink_structure', 'update_option_sidebars_widgets', 'activated_plugin', 'deactivated_plugin', 'wpoptimizing_page_cache_cleared' ) as $hook ) {
		add_action( $hook, 'wpoptimizing_host_purge_on_global' );
	}
	foreach ( array( 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
		add_action( $hook, 'wpoptimizing_host_purge_on_term' );
	}
	add_action( 'switch_theme', 'wpoptimizing_host_purge_on_theme' );
	add_action( 'upgrader_process_complete', 'wpoptimizing_host_purge_on_update' );
	add_action( 'wpoptimizing_flush_all', 'wpoptimizing_host_purge_on_flush_all' );
}
add_action( 'init', 'wpoptimizing_host_purge_register' );
