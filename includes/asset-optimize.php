<?php
/**
 * Asset optimization — CSS/JS delivery.
 *
 * Fase 3a (free): minifies LOCAL CSS/JS files (matthiasmullie/minify, vendored under
 * vendor/, from Composer) and rewrites their URL to a cached minified copy. Cached artefacts
 * live in wp-content/uploads/wpopt-assets/ (a public dir — NOT under wp-content/cache/,
 * which the page cache protects with "Require all denied") and are written atomically.
 *
 * Fase 3b (premium): defers eligible LOCAL scripts (adds a defer attribute via
 * script_loader_tag), keeping the jQuery family eager so inline snippets still run.
 *
 * Both are opt-in and off by default. Shared safety rails: never run alongside a
 * third-party asset optimizer (Autoptimize, WP Rocket, Perfmatters, LiteSpeed…),
 * never touch already-minified/deferred/external/admin assets, honour a
 * ?wpopt_no_optimize bypass, and (minify) an optional admin-only test mode.
 *
 * The decision/path helpers are pure (unit-tested); the rewrite + minify wraps I/O.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * ONE TEST MODE. Minify, delay JavaScript and Smart Assets each have a test mode, and it means
 * the same in all three: the change applies only to a logged-in user who can manage
 * WPOptimizing, and every visitor gets the page untouched. They used to differ — minify checked
 * manage_options, Smart Assets the plugin's own capability, and delay JavaScript had no test
 * mode at all (UX audit, 15/10/2026).
 *
 * @param bool $on Whether that feature's test mode is on.
 * @return bool True when the CURRENT request must be left untouched.
 */
function wpoptimizing_test_mode_blocks( $on ) {
	if ( ! $on ) {
		return false;
	}
	$cap = function_exists( 'wpoptimizing_cap' ) ? wpoptimizing_cap() : 'manage_options';
	return ! ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && current_user_can( $cap ) );
}

/**
 * Load the Composer autoloader, once, and only when a third-party class is about to be used.
 *
 * NOT AT FILE SCOPE, WHICH IS WHERE IT USED TO BE. This file runs on every visitor request,
 * and requiring the autoloader there pulled ~37 KB of PHP into each one: Composer's
 * ClassLoader plus everything a dependency declares under `files` autoloading, which runs
 * immediately whether or not anything asks for it. One of ours declares a loader that way,
 * for a library that must never touch a visitor request at all. Worse than the parsing, it
 * left TWO extra spl_autoload_register callbacks in place, so every unresolved class lookup
 * anywhere on the site walked them.
 *
 * The one thing that actually needs it is MatthiasMullie\Minify, on a cache MISS. Cache hits
 * return before this is called, so a warm site never loads it, and the premium-only code that
 * uses the other library requires its loader directly rather than relying on this.
 *
 * Guarded because a tree assembled by hand, or a half-finished deployment, should simply not
 * minify rather than fatal.
 *
 * @since 0.1.0
 * @return bool Whether the autoloader is available.
 */
function wpoptimizing_assetmin_autoload() {
	static $loaded = null;
	if ( null !== $loaded ) {
		return $loaded;
	}
	$file   = dirname( __DIR__ ) . '/vendor/autoload.php';
	$loaded = is_readable( $file );
	if ( $loaded ) {
		require_once $file;
	}
	return $loaded;
}

/**
 * Effective options (defaults merged).
 *
 * @return array
 */
function wpoptimizing_assetmin_options() {
	$opt = (array) get_option( 'wpoptimizing_asset_optimize', array() );
	return wp_parse_args(
		$opt,
		array(
			'minify_css'        => false,
			'minify_js'         => false,
			'test_mode'         => false,
			'exclude'           => array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- our own settings key, not a WP_Query/get_posts() argument.
			'defer_js'          => false, // Fase 3b (premium).
			'defer_exclude'     => array(),
			'defer_jquery'      => false, // Defer jQuery too, rewriting the inline code that uses it.
			'async_css'         => false, // Fase 3c (premium).
			'async_css_exclude' => array(),
			'critical_css'      => '',    // Fase 3d (premium): inlined above-the-fold CSS.
		)
	);
}

/**
 * Third-party asset optimizers we must never run alongside (they minify too).
 *
 * @return string Label of the detected optimizer, or '' when none.
 */
function wpoptimizing_assetmin_conflict() {
	return wpoptimizing_compat_owner( 'minify' );
}

/**
 * Is the URL local to this site (same host, under wp-content/wp-includes)? Pure.
 *
 * @param string $src      Asset URL.
 * @param string $site_url Site URL (home/site).
 * @return bool
 */
function wpoptimizing_assetmin_is_local( $src, $site_url ) {
	$src = (string) $src;
	if ( '' === $src ) {
		return false;
	}
	// Protocol-relative / relative → local.
	if ( 0 === strpos( $src, '//' ) ) {
		$src = 'https:' . $src;
	}
	if ( '/' === $src[0] ) {
		return true;
	}
	$host  = wp_parse_url( $src, PHP_URL_HOST );
	$shost = wp_parse_url( $site_url, PHP_URL_HOST );
	return $host && $shost && strtolower( $host ) === strtolower( $shost );
}

/**
 * Is the URL on the CDN host this plugin rewrites to? Then it is still one of our own files.
 *
 * The tag filters receive the URL after every src filter has run, the CDN rewrite included,
 * so a local stylesheet arrives there on the CDN host.
 *
 * @since 0.1.0
 * @param string $src Asset URL.
 * @return bool
 */
function wpoptimizing_assetmin_is_cdn( $src ) {
	$ctx = function_exists( 'wpoptimizing_cdn_context' ) ? wpoptimizing_cdn_context() : null;
	if ( null === $ctx ) {
		return false;
	}
	$src = (string) $src;
	$src = 0 === strpos( $src, '//' ) ? 'https:' . $src : $src;
	return wpoptimizing_assetmin_is_local( $src, 'https://' . $ctx['host'] );
}

/**
 * Should we skip minifying this asset? Pure (URL + handle + exclusion list).
 * Skips already-minified files, non-css/js, data URIs, and user-excluded handles/paths.
 *
 * @param string $src     Asset URL (query stripped is fine).
 * @param string $handle  WP handle.
 * @param string $type    'css' or 'js'.
 * @param array  $exclude Handle names or URL substrings to skip.
 * @return bool True to skip.
 */
function wpoptimizing_assetmin_should_skip( $src, $handle, $type, $exclude ) {
	$src  = (string) $src;
	$path = (string) wp_parse_url( $src, PHP_URL_PATH );
	if ( '' === $path || 0 === strpos( $src, 'data:' ) ) {
		return true;
	}
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( ( 'css' === $type && 'css' !== $ext ) || ( 'js' === $type && 'js' !== $ext ) ) {
		return true;
	}
	// Already minified.
	if ( preg_match( '/[\.\-]min\.(css|js)$/i', $path ) ) {
		return true;
	}
	foreach ( (array) $exclude as $needle ) {
		$needle = (string) $needle;
		if ( '' === $needle ) {
			continue;
		}
		if ( $needle === $handle || false !== strpos( $src, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Map a local asset URL to its filesystem path. Pure-ish (uses content/includes dirs).
 *
 * @param string $src Asset URL.
 * @return string Absolute path, or '' if it cannot be resolved safely.
 */
function wpoptimizing_assetmin_src_to_path( $src ) {
	$path = (string) wp_parse_url( $src, PHP_URL_PATH );
	if ( '' === $path ) {
		return '';
	}
	$path = rawurldecode( $path );
	if ( false !== strpos( $path, '..' ) ) {
		return '';
	}
	// content_url path prefix → WP_CONTENT_DIR; else ABSPATH.
	$content_path = (string) wp_parse_url( content_url(), PHP_URL_PATH );
	if ( '' !== $content_path && 0 === strpos( $path, $content_path ) ) {
		$file = WP_CONTENT_DIR . substr( $path, strlen( $content_path ) );
	} else {
		$file = untrailingslashit( ABSPATH ) . $path;
	}
	$real = realpath( $file );
	if ( false === $real ) {
		return '';
	}
	// Must stay within ABSPATH.
	$base = realpath( ABSPATH );
	if ( $base && 0 !== strpos( $real, $base ) ) {
		return '';
	}
	return $real;
}

/**
 * Public base dir + URL for the minified assets. They live in uploads/wpopt-assets/
 * (always public + writable), NOT under wp-content/cache/ which the page cache
 * protects with "Require all denied" — the browser fetches these files directly.
 *
 * @return array{dir:string,url:string}
 */
function wpoptimizing_assetmin_base() {
	$up  = wp_upload_dir();
	$sub = wpoptimizing_assetmin_site_folder( (string) $up['basedir'], is_multisite(), is_main_site(), get_current_blog_id() );
	return array(
		'dir' => trailingslashit( $up['basedir'] ) . 'wpopt-assets' . $sub,
		'url' => trailingslashit( $up['baseurl'] ) . 'wpopt-assets' . $sub,
	);
}

/**
 * The site's own folder under wpopt-assets, when it needs one. Pure.
 *
 * Each site of a network normally uploads to its own uploads/sites/<id>, so wpopt-assets is
 * already its own. A plugin that shares one media library across the network (APG Multisite
 * Suite) points every site at the main site's uploads, and then the sites wrote the same
 * ccss-<template>.css: artprojectgroup.com served the critical CSS generated for
 * artprojectgroup.es, with the .es fonts in it (10/11/2026). A site other than the main one whose
 * uploads are not its own gets «/site-<id>». The main site keeps the folder it always had, and
 * the flush never goes into subfolders (wpoptimizing_assetmin_clear_dir()).
 *
 * @param string $basedir Uploads folder of the current site.
 * @param bool   $multi   is_multisite().
 * @param bool   $main    is_main_site().
 * @param int    $blog_id Current site.
 * @return string '' or '/site-<id>'.
 */
function wpoptimizing_assetmin_site_folder( $basedir, $multi, $main, $blog_id ) {
	if ( ! $multi || $main || $blog_id <= 0 ) {
		return '';
	}
	$basedir = untrailingslashit( str_replace( '\\', '/', (string) $basedir ) );
	return 1 === preg_match( '#/sites/' . (int) $blog_id . '$#', $basedir ) ? '' : '/site-' . (int) $blog_id;
}

/**
 * Cache file + URL for a minified asset, keyed by the source path + mtime so a
 * changed source produces a new file.
 *
 * @param string $file  Source absolute path.
 * @param string $type  'css'|'js'.
 * @return array{path:string,url:string}
 */
function wpoptimizing_assetmin_cache_target( $file, $type ) {
	$mtime = @filemtime( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$name  = md5( $file . '|' . $mtime ) . '.' . ( 'css' === $type ? 'css' : 'js' );
	$base  = wpoptimizing_assetmin_base();
	return array(
		'path' => $base['dir'] . '/' . $name,
		'url'  => $base['url'] . '/' . $name,
	);
}

/**
 * Is minification live for this asset type right now? Honours the option, the
 * third-party conflict, the ?wpopt_no_optimize bypass and test mode.
 *
 * @param string $type 'css'|'js'.
 * @return bool
 */
function wpoptimizing_assetmin_active( $type ) {
	$opt = wpoptimizing_assetmin_options();
	$key = ( 'css' === $type ) ? 'minify_css' : 'minify_js';
	if ( empty( $opt[ $key ] ) ) {
		return false;
	}
	if ( '' !== wpoptimizing_assetmin_conflict() ) {
		return false;
	}
	if ( isset( $_GET['wpopt_no_optimize'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only debug bypass, no state change.
		return false;
	}
	if ( wpoptimizing_test_mode_blocks( ! empty( $opt['test_mode'] ) ) ) {
		return false;
	}
	return true;
}

/**
 * The CSS minifier for one file, configured to inline nothing.
 *
 * By default the library pastes every local @import'ed sheet, and url() images under 5 KB as
 * data URIs, into the output. The cache key is the source file and ITS mtime only, so an edit
 * to an imported file never produced a new copy and the site kept serving the old styles until
 * a flush. With nothing inlined the @import stays an @import (rebased to the cache location)
 * and the browser fetches the current file.
 *
 * The import size limit only governs the images; the @import pasting has no switch, so it is
 * turned off by overriding the one method that does it. An anonymous class, so the parent is
 * resolved here, after the autoloader, and never on a request that does not minify.
 *
 * @since 0.1.0
 * @param string $file Source absolute path.
 * @return MatthiasMullie\Minify\CSS
 */
function wpoptimizing_assetmin_css_minifier( $file ) {
	$m = new class( $file ) extends MatthiasMullie\Minify\CSS {
		/**
		 * Leave every @import as it is.
		 *
		 * @param string   $source  File being minified.
		 * @param string   $content Its CSS.
		 * @param string[] $parents Import chain.
		 * @return string
		 */
		protected function combineImports( $source, $content, $parents ) {
			unset( $source, $parents );
			return $content;
		}
	};
	$m->setMaxImportSize( 0 );
	return $m;
}

/**
 * Minify one local file to the cache and return its URL (or '' to fall back to the
 * original — a minify failure must never break the page).
 *
 * @param string $file Source absolute path.
 * @param string $type 'css'|'js'.
 * @return string
 */
function wpoptimizing_assetmin_minify_file( $file, $type ) {
	$target = wpoptimizing_assetmin_cache_target( $file, $type );
	if ( is_readable( $target['path'] ) ) {
		return $target['url']; // Already cached for this mtime.
	}
	$dir = dirname( $target['path'] );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	if ( ! wpoptimizing_assetmin_autoload() ) {
		return ''; // No library: serve the original file untouched.
	}
	try {
		if ( 'css' === $type ) {
			$m   = wpoptimizing_assetmin_css_minifier( $file );
			$out = $m->minify( $target['path'] ); // Rebases relative url()s to the cache location.
		} else {
			$m   = new MatthiasMullie\Minify\JS( $file );
			$out = $m->minify();
		}
	} catch ( \Throwable $e ) {
		return '';
	}
	if ( '' === (string) $out ) {
		return '';
	}
	$ok = function_exists( 'wpopt_pc_write_atomic' )
		? wpopt_pc_write_atomic( $target['path'], $out )
		: ( false !== file_put_contents( $target['path'], $out, LOCK_EX ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
	return $ok ? $target['url'] : '';
}

/**
 * Rewrite an enqueued asset URL to its minified copy, or return it unchanged.
 *
 * @param string $src    Asset URL.
 * @param string $handle WP handle.
 * @param string $type   'css'|'js'.
 * @return string
 */
function wpoptimizing_assetmin_rewrite( $src, $handle, $type ) {
	if ( ! is_string( $src ) || '' === $src || ! wpoptimizing_assetmin_active( $type ) ) {
		return $src;
	}
	$opt   = wpoptimizing_assetmin_options();
	$clean = preg_replace( '/\?.*$/', '', $src );
	if ( ! wpoptimizing_assetmin_is_local( $src, home_url() ) ) {
		return $src;
	}
	if ( wpoptimizing_assetmin_should_skip( $clean, (string) $handle, $type, $opt['exclude'] ) ) {
		return $src;
	}
	$file = wpoptimizing_assetmin_src_to_path( $src );
	if ( '' === $file ) {
		return $src;
	}
	$url = wpoptimizing_assetmin_minify_file( $file, $type );
	return ( '' !== $url ) ? $url : $src;
}

/**
 * Filter callback for style_loader_src.
 *
 * @param string $src    URL.
 * @param string $handle Handle.
 * @return string
 */
function wpoptimizing_assetmin_filter_css( $src, $handle ) {
	return wpoptimizing_assetmin_rewrite( $src, $handle, 'css' );
}

/**
 * Filter callback for script_loader_src.
 *
 * @param string $src    URL.
 * @param string $handle Handle.
 * @return string
 */
function wpoptimizing_assetmin_filter_js( $src, $handle ) {
	return wpoptimizing_assetmin_rewrite( $src, $handle, 'js' );
}


/**
 * Register the front-end rewrite filters (never in admin).
 *
 * @return void
 */
function wpoptimizing_assetmin_boot() {
	if ( is_admin() ) {
		return;
	}
	add_filter( 'style_loader_src', 'wpoptimizing_assetmin_filter_css', 20, 2 );
	add_filter( 'script_loader_src', 'wpoptimizing_assetmin_filter_js', 20, 2 );
}
add_action( 'wp_enqueue_scripts', 'wpoptimizing_assetmin_boot', 99 );

/**
 * Delete all cached minified assets (e.g. on a flush).
 *
 * @return void
 */
function wpoptimizing_assetmin_clear() {
	$base = wpoptimizing_assetmin_base();
	wpoptimizing_assetmin_clear_dir( $base['dir'] );
}

/**
 * Delete the minified copies in a folder, and only those.
 *
 * The folder is shared: the generated critical CSS lives beside them as ccss-<template>.css, and
 * a "*.css" sweep deleted it on every flush with nothing regenerating it, so the above-the-fold
 * styles quietly stopped being inlined. A minified copy is always named by an md5, so that is
 * the name matched. No GLOB_BRACE either: it is not defined on musl (Alpine) builds of PHP, and
 * using it there is a fatal error on the flush.
 *
 * @since 0.1.0
 * @param string $dir Folder.
 * @return int Files deleted.
 */
function wpoptimizing_assetmin_clear_dir( $dir ) {
	$dir = untrailingslashit( (string) $dir );
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return 0;
	}
	$names = scandir( $dir );
	$n     = 0;
	foreach ( false === $names ? array() : $names as $name ) {
		if ( preg_match( '/^[0-9a-f]{32}\.(?:css|js)$/', (string) $name ) && is_file( $dir . '/' . $name ) ) {
			wp_delete_file( $dir . '/' . $name );
			++$n;
		}
	}
	return $n;
}
add_action( 'wpoptimizing_flush_all', 'wpoptimizing_assetmin_clear' );
