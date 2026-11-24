<?php
/**
 * Host / third-party cache coexistence.
 *
 * WPOptimizing never does full-page HTML caching. Many hosts (Kinsta, SiteGround,
 * Webempresa…) and plugins (WP Rocket, LiteSpeed, W3TC…) do. Instead of competing
 * with them, we DETECT them and INTEGRATE their purge: after a cleanup/optimization
 * (which changes the database) we flush their page caches so visitors never see
 * stale content, and the toolbar offers a single "Flush all caches" action.
 *
 * A generic `do_action( 'wpoptimizing_flush_all' )` lets hosts without a known API
 * (or custom setups) hook their own purge.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catalog of known page/CDN cache integrations. Pure data (no side effects), so it
 * is unit-testable. Each entry: id, label, type, a `detect` descriptor and a
 * `purge` descriptor (null when the provider only auto-purges and exposes no safe
 * programmatic call).
 *
 * Descriptor shapes — detect: {kind:function|class|constant|plugin, name}.
 *                      purge:  {kind:function|action|static, name[, args]} | null.
 *
 * @return array<int,array>
 */
function wpoptimizing_host_cache_catalog() {
	/*
	 * EVERY SYMBOL HERE WAS READ IN THE PLUGIN'S OWN SOURCE (14/10/2026, the lab's corpus), because a
	 * wrong name fails in silence: a purge action nobody listens to looks exactly like a purge.
	 * Left out on purpose: Swift Performance (its purge resets OPcache and cascades into other
	 * caches), Sucuri (needs the firewall's API keys) and Autoptimize (no page cache).
	 */
	return array(
		array(
			'id'     => 'wp-rocket',
			'label'  => 'WP Rocket',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'function',
				'name' => 'rocket_clean_domain',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'rocket_clean_domain',
			),
		),
		array(
			'id'     => 'litespeed',
			'label'  => 'LiteSpeed Cache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'LSCWP_V',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'litespeed_purge_all',
			),
		),
		// The plugin only FIRES siteground_optimizer_flush_cache, nobody listens to it: the action
		// this entry used to call purged nothing (verified in 7.8.4 source, 14/10/2026).
		array(
			'id'     => 'siteground',
			'label'  => 'SiteGround Speed Optimizer',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'function',
				'name' => 'sg_cachepress_purge_everything',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'sg_cachepress_purge_everything',
			),
		),
		// Detected by its active plugin, not by w3tc_flush_all(): W3TC's drop-ins load that API file
		// even with the plugin deactivated.
		array(
			'id'     => 'w3tc',
			'label'  => 'W3 Total Cache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'plugin',
				'name' => 'w3-total-cache/w3-total-cache.php',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'w3tc_flush_all',
			),
		),
		// WPSC_VERSION_ID only exists in the main file (the phase2 functions also come with its drop-in).
		array(
			'id'     => 'wp-super-cache',
			'label'  => 'WP Super Cache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'WPSC_VERSION_ID',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_wp_super_cache',
			),
		),
		array(
			'id'     => 'wp-fastest-cache',
			'label'  => 'WP Fastest Cache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'class',
				'name' => 'WpFastestCache',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'wpfc_clear_all_cache',
			),
		),
		array(
			'id'     => 'nginx-helper',
			'label'  => 'Nginx Helper',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'NGINX_HELPER_BASEPATH',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'rt_nginx_helper_purge_all',
			),
		),
		array(
			'id'     => 'varnish-http-purge',
			'label'  => 'Proxy Cache Purge (Varnish)',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'class',
				'name' => 'VarnishPurger',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_varnish_http',
			),
		),
		array(
			'id'     => 'breeze',
			'label'  => 'Breeze (Cloudways)',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'BREEZE_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'breeze_clear_all_cache',
			),
		),
		// _clear_site_cache, not _clear_complete_cache: on a network the latter clears every site.
		array(
			'id'     => 'cache-enabler',
			'label'  => 'Cache Enabler',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'CACHE_ENABLER_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'cache_enabler_clear_site_cache',
			),
		),
		array(
			'id'     => 'comet-cache',
			'label'  => 'Comet Cache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'class',
				'name' => 'comet_cache',
			),
			'purge'  => array(
				'kind' => 'static',
				'name' => 'comet_cache::clear',
			),
		),
		array(
			'id'     => 'hummingbird',
			'label'  => 'Hummingbird',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'WPHB_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'wphb_clear_page_cache',
			),
		),
		array(
			'id'     => 'super-page-cache',
			'label'  => 'Super Page Cache for Cloudflare',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'SWCFPC_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'swcfpc_purge_cache',
			),
		),
		// Its own "purge all" (Dashboard\purge_all_cache) deletes the whole wp-content/cache/ — every
		// plugin's cache. clean_site_cache_dir() clears only this site's page cache.
		array(
			'id'     => 'powered-cache',
			'label'  => 'Powered Cache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'POWERED_CACHE_VERSION',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'PoweredCache\\Utils\\clean_site_cache_dir',
			),
		),
		array(
			'id'     => 'cachify',
			'label'  => 'Cachify',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'CACHIFY_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'cachify_flush_cache',
			),
		),
		array(
			'id'     => 'wp-optimize',
			'label'  => 'WP-Optimize',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'WPO_VERSION',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_wp_optimize',
			),
		),
		// The official plugin. It only purges when its APO or plugin cache is on and credentials are
		// stored; WPOptimizing's own Cloudflare integration (cloudflare.php) needs neither.
		array(
			'id'     => 'cloudflare-plugin',
			'label'  => 'Cloudflare (plugin)',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'CLOUDFLARE_PLUGIN_DIR',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_cloudflare_plugin',
			),
		),
		// Added 16/10/2026; each purge call read in the plugin's own source (docs/competencia-2026-10-16).
		array(
			'id'     => 'flyingpress',
			'label'  => 'FlyingPress',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'FLYING_PRESS_VERSION',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_flyingpress',
			),
		),
		array(
			'id'     => 'speedycache',
			'label'  => 'SpeedyCache',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'SPEEDYCACHE_VERSION',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_speedycache',
			),
		),
		array(
			'id'     => 'jetpack-boost',
			'label'  => 'Jetpack Boost',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'JETPACK_BOOST_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'jetpack_boost_clear_page_cache_all',
			),
		),
		array(
			'id'     => 'nitropack',
			'label'  => 'NitroPack',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'function',
				'name' => 'nitropack_sdk_purge',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'nitropack_sdk_purge',
			),
		),
		array(
			'id'     => 'tenweb-booster',
			'label'  => '10Web Booster',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'TENWEB_SO_VERSION',
			),
			'purge'  => array(
				'kind' => 'action',
				'name' => 'two_clear_cache',
			),
		),
		array(
			'id'     => 'berqwp',
			'label'  => 'BerqWP',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'BERQWP_VERSION',
			),
			'purge'  => array(
				'kind' => 'function',
				'name' => 'wpoptimizing_purge_berqwp',
			),
		),
		array(
			'id'     => 'kinsta',
			'label'  => 'Kinsta',
			'type'   => 'page',
			'detect' => array(
				'kind' => 'constant',
				'name' => 'KINSTA_CACHE_ZONE',
			),
			'purge'  => null,
		),
	);
}

/**
 * FlyingPress: its purge is a static method (src/Purge.php).
 *
 * @return void
 */
function wpoptimizing_purge_flyingpress() {
	if ( is_callable( array( 'FlyingPress\\Purge', 'purge_everything' ) ) ) {
		call_user_func( array( 'FlyingPress\\Purge', 'purge_everything' ) );
	}
}

/**
 * SpeedyCache: static method too (main/delete.php).
 *
 * @return void
 */
function wpoptimizing_purge_speedycache() {
	if ( is_callable( array( 'SpeedyCache\\Delete', 'all_cache' ) ) ) {
		call_user_func( array( 'SpeedyCache\\Delete', 'all_cache' ) );
	}
}

/**
 * BerqWP: an instance method on its singleton (inc/cache/class-berqcache.php).
 *
 * @return void
 */
function wpoptimizing_purge_berqwp() {
	if ( class_exists( 'berqCache', false ) && is_callable( array( 'berqCache', 'getInstance' ) ) ) {
		$cache = call_user_func( array( 'berqCache', 'getInstance' ) );
		if ( is_object( $cache ) && method_exists( $cache, 'flush_all' ) ) {
			$cache->flush_all();
		}
	}
}

/**
 * WP Super Cache: this site only. wp_cache_clear_cache( 0 ) prunes the whole cache path, which on
 * a network is every site.
 *
 * @return void
 */
function wpoptimizing_purge_wp_super_cache() {
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache( is_multisite() ? get_current_blog_id() : 0 );
	}
}

/**
 * Proxy Cache Purge: it has no purge-all hook; its own WP-CLI command purges the home URL with the
 * vhp-regex marker, which is what this does.
 *
 * @return void
 */
function wpoptimizing_purge_varnish_http() {
	if ( is_callable( array( 'VarnishPurger', 'purge_url' ) ) && is_callable( array( 'VarnishPurger', 'the_home_url' ) ) ) {
		VarnishPurger::purge_url( VarnishPurger::the_home_url() . '/?vhp-regex' );
	}
}

/**
 * WP-Optimize: its page cache only. The global wpo_cache_flush() would also empty the object cache.
 *
 * @return void
 */
function wpoptimizing_purge_wp_optimize() {
	if ( function_exists( 'WP_Optimize' ) ) {
		$wpo = WP_Optimize();
		if ( is_object( $wpo ) && method_exists( $wpo, 'get_page_cache' ) ) {
			$cache = $wpo->get_page_cache();
			if ( is_object( $cache ) && method_exists( $cache, 'purge' ) ) {
				$cache->purge();
			}
		}
	}
}

/**
 * The official Cloudflare plugin: no hook to call, only the instance it leaves in a global.
 *
 * @return void
 */
function wpoptimizing_purge_cloudflare_plugin() {
	$hooks = isset( $GLOBALS['cloudflareHooks'] ) ? $GLOBALS['cloudflareHooks'] : null;
	if ( is_object( $hooks ) && method_exists( $hooks, 'purgeCacheEverything' ) ) {
		$hooks->purgeCacheEverything();
	}
}

/**
 * Evaluate a detection descriptor against the current runtime.
 *
 * @param array $detect {kind, name}.
 * @return bool
 */
function wpoptimizing_host_cache_present( $detect ) {
	$kind = isset( $detect['kind'] ) ? $detect['kind'] : '';
	$name = isset( $detect['name'] ) ? (string) $detect['name'] : '';
	if ( '' === $name ) {
		return false;
	}
	switch ( $kind ) {
		case 'function':
			return function_exists( $name );
		case 'class':
			return class_exists( $name );
		case 'constant':
			return defined( $name );
		case 'plugin':
			// Active on this site or across the network (basename, e.g. w3-total-cache/w3-total-cache.php).
			if ( in_array( $name, (array) get_option( 'active_plugins', array() ), true ) ) {
				return true;
			}
			return is_multisite() && isset( ( (array) get_site_option( 'active_sitewide_plugins', array() ) )[ $name ] );
	}
	return false;
}

/**
 * Detect the cache integrations active on this site.
 *
 * Returns page/CDN providers from the catalog that are present, plus a synthetic
 * "object cache" entry when a persistent object cache is active (ours or a foreign
 * drop-in). Cached per request. Filterable so add-ons/hosts can extend it.
 *
 * @return array<int,array{id:string,label:string,type:string,purge:?array,managed:bool}>
 */
function wpoptimizing_detect_host_cache() {
	static $found = null;
	if ( null !== $found ) {
		return $found;
	}
	$found = array();

	foreach ( wpoptimizing_host_cache_catalog() as $p ) {
		if ( wpoptimizing_host_cache_present( $p['detect'] ) ) {
			$found[] = array(
				'id'      => $p['id'],
				'label'   => $p['label'],
				'type'    => $p['type'],
				'purge'   => $p['purge'],
				'managed' => empty( $p['purge'] ), // auto-purge only (e.g. Kinsta).
			);
		}
	}

	// Persistent object cache (ours or the host's). wp_cache_flush() targets it.
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
		$ours    = function_exists( 'wpoptimizing_object_cache_active' ) && wpoptimizing_object_cache_active();
		$found[] = array(
			'id'      => 'object-cache',
			'label'   => $ours ? __( 'Object cache (WPOptimizing)', 'wpoptimizing' ) : __( 'Object cache (host/plugin)', 'wpoptimizing' ),
			'type'    => 'object',
			'purge'   => array(
				'kind' => 'function',
				'name' => 'wp_cache_flush',
			),
			'managed' => false,
		);
	}

	/**
	 * Filter the detected cache integrations.
	 *
	 * @since 0.1.0
	 * @param array $found Detected providers.
	 */
	$found = (array) apply_filters( 'wpoptimizing_detected_caches', $found );
	return $found;
}

/**
 * Run a purge descriptor.
 *
 * @param array $purge {kind, name[, args]}.
 * @return void
 */
function wpoptimizing_host_cache_run_purge( $purge ) {
	if ( empty( $purge['kind'] ) || empty( $purge['name'] ) ) {
		return;
	}
	$name = (string) $purge['name'];
	$args = isset( $purge['args'] ) ? (array) $purge['args'] : array();
	switch ( $purge['kind'] ) {
		case 'function':
			if ( function_exists( $name ) ) {
				return call_user_func_array( $name, $args );
			}
			break;
		case 'action':
			// Intentionally fires a THIRD-PARTY purge hook (e.g. litespeed_purge_all,
			// siteground_optimizer_flush_cache); it cannot carry our prefix.
			do_action_ref_array( $name, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
			break;
		case 'static':
			if ( is_callable( $name ) ) {
				call_user_func_array( $name, $args );
			}
			break;
	}
}

/**
 * Flush caches to keep content fresh after WPOptimizing changes the database.
 *
 * @param string $scope 'page' (page/CDN caches only — used after a cleanup) or
 *                       'all' (also the object cache — used by the toolbar).
 * @return string[] Human labels of the caches that were flushed.
 */
function wpoptimizing_flush_all_caches( $scope = 'all' ) {
	$flushed = array();
	foreach ( wpoptimizing_detect_host_cache() as $c ) {
		if ( 'object' === $c['type'] ) {
			if ( 'all' !== $scope ) {
				continue; // The object cache self-invalidates; only flush it on explicit "all".
			}
			// On a network the object cache is ONE cache. wp_cache_flush() empties all of it,
			// for every site, and this plugin grants its own capability to anyone holding
			// manage_options — which on a network is every sub-site's administrator. So the
			// button that clears one customer's cache would clear all of them, and the REST
			// route makes that a single unauthenticated-looking POST from any of them.
			//
			// The guard lives here rather than at the three call sites, because that is the
			// mistake this audit keeps finding: a rule enforced by whoever remembers it.
			if ( is_multisite()
				&& ! ( defined( 'WP_CLI' ) && WP_CLI )
				&& ! current_user_can( 'manage_network_options' ) ) {
				continue;
			}
		}
		if ( empty( $c['purge'] ) ) {
			continue; // Managed/auto-purge provider (e.g. Kinsta): nothing to call.
		}
		$result = wpoptimizing_host_cache_run_purge( $c['purge'] );
		// The object cache is the one purge that answers. It refuses when its backend cannot
		// delete by prefix (memcached), because emptying a shared instance is damage to other
		// sites — so listing it as flushed would be reporting something that did not happen.
		// The same for a CDN purged through its API (Cloudflare): a rejected token is not a purge.
		if ( in_array( $c['type'], array( 'object', 'cdn' ), true ) && false === $result ) {
			continue;
		}
		$flushed[] = (string) $c['label'];
	}

	/**
	 * Generic purge hook for hosts/setups without a known integration (e.g. some
	 * managed hosts, Varnish layers). Receives the scope.
	 *
	 * @since 0.1.0
	 * @param string $scope 'page'|'all'.
	 */
	do_action( 'wpoptimizing_flush_all', $scope );

	return array_values( array_unique( $flushed ) );
}

/**
 * After a general cleanup, purge page/CDN caches so visitors don't see stale
 * content. Object cache is left alone (it self-invalidates). Filterable off.
 *
 * @return void
 */
function wpoptimizing_autopurge_after_cleanup() {
	/** @since 0.1.0 — return false to disable automatic page-cache purging. */
	if ( ! apply_filters( 'wpoptimizing_autopurge_host_cache', true ) ) {
		return;
	}
	wpoptimizing_flush_all_caches( 'page' );
}
add_action( 'wpoptimizing_after_cleanup', 'wpoptimizing_autopurge_after_cleanup', 20 );
