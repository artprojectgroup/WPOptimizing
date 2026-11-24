<?php
/**
 * Import source: Hummingbird (free 4.0 and Pro 3.19, same keys for what is read here).
 *
 * Two places (docs/cache-2026-11-06/importadores-B.md §2):
 *  - `wphb_settings`, an array per module (core/class-settings.php:125-356). In a multisite
 *    network each module is split between the network option and the site option
 *    (get_blog_option_names(), :367-381); both are read and put back together, as
 *    Settings::get_settings() does.
 *  - the page cache's own settings, in wp-content/wphb-cache/wphb-cache.php, which in spite of the
 *    extension is plain JSON written with wp_json_encode() (core/modules/class-page-cache.php:
 *    505-522, defaults :536-560). Read as text and decoded, never included.
 * Its own «config» export leaves out the cache exclusions and most of the rest, so it is not used.
 *
 * Never imported: Cloudflare's e-mail and Global API Key, and the services of WPMU DEV (its CDN,
 * cloud critical CSS, uptime and reports).
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The live configuration.
 *
 * @return array|null array{settings:array,page_cache:array,multisite:bool}
 */
function wpoptimizing_import_src_hummingbird_read() {
	$settings = get_option( 'wphb_settings' );
	$settings = is_array( $settings ) ? $settings : array();
	if ( is_multisite() ) {
		$network = get_site_option( 'wphb_settings' );
		foreach ( is_array( $network ) ? $network : array() as $module => $values ) {
			$settings[ $module ] = array_merge( is_array( $values ) ? $values : array(), is_array( $settings[ $module ] ?? null ) ? $settings[ $module ] : array() );
		}
	}
	$page_cache = array();
	$file       = WP_CONTENT_DIR . '/wphb-cache/wphb-cache.php';
	if ( is_readable( $file ) && filesize( $file ) < 1048576 ) {
		$json       = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local JSON file, read as text.
		$page_cache = is_array( $json ) ? $json : array();
	}
	if ( ! $settings && ! $page_cache ) {
		return null;
	}
	return array(
		'settings'   => $settings,
		'page_cache' => $page_cache,
		'multisite'  => is_multisite(),
	);
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r What wpoptimizing_import_src_hummingbird_read() returns (`multisite`: whether
 *                 this is a network).
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_hummingbird_map( array $r ) {
	$c        = array();
	$notes    = array();
	$s        = (array) ( $r['settings'] ?? array() );
	$file     = (array) ( $r['page_cache'] ?? array() );
	$pc       = (array) ( $s['page_cache'] ?? array() );
	$minify   = (array) ( $s['minify'] ?? array() );
	$advanced = (array) ( $s['advanced'] ?? array() );
	$fset     = (array) ( $file['settings'] ?? array() );
	$exclude  = (array) ( $file['exclude'] ?? array() );
	$list     = static function ( $value ) {
		$value = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_filter( (array) $value, 'is_scalar' ) ), 'strlen' ) );
	};
	/* translators: %s: setting name in the other plugin. */
	$not_imported = __( '%s: not imported.', 'wpoptimizing' );

	// Page cache: only when it was switched on there (the file exists with defaults anyway).
	if ( ! empty( $pc['enabled'] ) ) {
		$interval = (array) ( $file['clear_interval'] ?? array() );
		if ( ! empty( $interval['enabled'] ) ) {
			// Hours there.
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) ( $interval['interval'] ?? 0 ) * HOUR_IN_SECONDS, $notes ), __( 'Clear cache at set intervals', 'wpoptimizing' ) );
		}
		if ( isset( $fset['compress'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'gzip', ! empty( $fset['compress'] ), __( 'Serve compressed cached files', 'wpoptimizing' ) );
		}
		if ( isset( $pc['preload'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', ! empty( $pc['preload'] ), __( 'Preload cache', 'wpoptimizing' ) );
		}
		// Unanchored regular expressions. Its defaults (wp-*.php, index.php, xmlrpc.php, sitemaps)
		// are pages WPOptimizing never caches anyway: they are left out, not reported.
		$defaults = array( 'wp-.*\.php', 'index\.php', 'xmlrpc\.php', 'sitemap[^\/.]*\.xml' );
		$uri      = wpoptimizing_importer_patterns( array_diff( $list( $exclude['url_strings'] ?? array() ), $defaults ), __( 'Exclude URL strings', 'wpoptimizing' ), $notes );
		if ( $uri ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Exclude URL strings', 'wpoptimizing' ) );
		}
		$cookies = wpoptimizing_importer_patterns( $list( $exclude['cookies'] ?? array() ), __( 'Exclude cookies', 'wpoptimizing' ), $notes );
		if ( $cookies ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $cookies, __( 'Exclude cookies', 'wpoptimizing' ) );
		}
		// Its stock list (bot, crawl, spider…) sends every crawler past the cache; only what the
		// person added comes in.
		$ua = wpoptimizing_importer_patterns( array_diff( $list( $exclude['user_agents'] ?? array() ), array( 'bot', 'is_archive', 'slurp', 'crawl', 'spider', 'Yandex' ) ), __( 'Exclude user agent', 'wpoptimizing' ), $notes );
		if ( $ua ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'Exclude user agent', 'wpoptimizing' ) );
		}
		if ( ! empty( $fset['logged_in'] ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Cache for logged-in users', 'wpoptimizing' ) ) );
		}
		foreach ( array(
			'url_queries' => __( 'Cache URL queries', 'wpoptimizing' ),
			'cache_404'   => __( 'Cache 404 requests', 'wpoptimizing' ),
		) as $key => $label ) {
			if ( ! empty( $fset[ $key ] ) ) {
				$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $not_imported, $label ) );
			}
		}
		if ( ! empty( $pc['integrations']['varnish'] ) ) {
			$notes[] = wpoptimizing_importer_note( 'review', __( 'Varnish: switch on its purge in Cache › Browser & server once WPOptimizing has checked that Varnish is in front of the site.', 'wpoptimizing' ) );
		}
	}

	// Asset optimization: minify when it is on with «Compress» (the default). Its exclusions are
	// handles or file paths, both of which our exclusion list takes. On a network the site also
	// needs its own switch, `minify_blog`, off by default (core/modules/class-minify.php:1238-1246,
	// core/class-settings.php:117): without it nothing of the module acted on this site.
	$minify_on = ! empty( $minify['enabled'] ) && ( empty( $r['multisite'] ) || ! empty( $minify['minify_blog'] ) );
	if ( $minify_on ) {
		$assets   = (array) ( $minify['do_assets'] ?? array() );
		$compress = ! isset( $minify['compress'] ) || ! empty( $minify['compress'] );
		$c[]      = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_css', $compress && ( ! isset( $assets['styles'] ) || ! empty( $assets['styles'] ) ), __( 'Compress assets', 'wpoptimizing' ) );
		$c[]      = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_js', $compress && ( ! isset( $assets['scripts'] ) || ! empty( $assets['scripts'] ) ), __( 'Compress assets', 'wpoptimizing' ) );
		$dont     = (array) ( $minify['dont_minify'] ?? array() );
		$skip     = array_merge( $list( $dont['styles'] ?? array() ), $list( $dont['scripts'] ?? array() ) );
		if ( $skip ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $skip ) ), __( 'Compress assets', 'wpoptimizing' ) );
		}
		if ( ! isset( $minify['combine'] ) || ! empty( $minify['combine'] ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ), __( 'Combine & Compress', 'wpoptimizing' ) ) );
		}
		$defer = (array) ( $minify['defer'] ?? array() );
		if ( $list( $defer['scripts'] ?? array() ) || $list( $defer['styles'] ?? array() ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing defers every script except the ones you exclude, not a chosen few, so this list was not imported.', 'wpoptimizing' ), __( 'Defer', 'wpoptimizing' ) ) );
		}
	}

	// Delay JavaScript: it works on its own, without asset optimization.
	if ( isset( $minify['delay_js'] ) ) {
		$on  = ! empty( $minify['delay_js'] );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', $on, __( 'Delay JavaScript', 'wpoptimizing' ) );
		if ( $on ) {
			$delay_ex = array_values( array_unique( array_merge( $list( $minify['delay_js_exclusions'] ?? '' ), $list( $minify['delay_js_files_exclusion'] ?? array() ) ) ) );
			if ( $delay_ex ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', $delay_ex, __( 'Delay JavaScript exclusions', 'wpoptimizing' ) );
			}
			if ( isset( $minify['delay_js_timeout'] ) && (int) $minify['delay_js_timeout'] > 0 ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'timeout', (int) $minify['delay_js_timeout'], __( 'Delay JavaScript', 'wpoptimizing' ) );
			}
			foreach ( array(
				'delay_js_post_types_exclusion'     => __( 'Delay JavaScript post type exclusions', 'wpoptimizing' ),
				'delay_js_post_urls_exclusion'      => __( 'Delay JavaScript URL exclusions', 'wpoptimizing' ),
				'delay_js_plugins_themes_exclusion' => __( 'Delay JavaScript plugin and theme exclusions', 'wpoptimizing' ),
				'delay_js_ads_tracker_exclusion'    => __( 'Delay JavaScript ad and tracker exclusions', 'wpoptimizing' ),
			) as $key => $label ) {
				if ( $list( $minify[ $key ] ?? array() ) ) {
					$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $not_imported, $label ) );
				}
			}
		}
	}
	if ( ! empty( $minify['critical_css'] ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: generated by an online service, not imported. WPOptimizing generates critical CSS on your server instead.', 'wpoptimizing' ), __( 'Critical CSS', 'wpoptimizing' ) ) );
	}
	// Font preloading is part of that module and needs it active (minify/class-fonts.php:75-91).
	if ( $minify_on && ! empty( $minify['font_optimization'] ) && $list( $minify['preload_fonts'] ?? '' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Font Optimization', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', $list( $minify['preload_fonts'] ), __( 'Font Optimization', 'wpoptimizing' ) );
	}

	// Advanced tools.
	if ( isset( $advanced['emoji'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', ! empty( $advanced['emoji'] ), __( 'Remove default emoji files', 'wpoptimizing' ) );
	}
	if ( isset( $advanced['cart_fragments'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'cart_fragments', ! empty( $advanced['cart_fragments'] ), __( 'Disable WooCommerce cart fragments', 'wpoptimizing' ) );
		if ( ! empty( $advanced['cart_fragments'] ) ) {
			$notes[] = wpoptimizing_importer_note( 'review', __( 'Disable WooCommerce cart fragments: Hummingbird removed them by type of page; WPOptimizing loads them only for visitors with something in the cart.', 'wpoptimizing' ) );
		}
	}
	if ( $list( $advanced['prefetch'] ?? array() ) || $list( $advanced['preconnect'] ?? array() ) ) {
		$label = $list( $advanced['preconnect'] ?? array() ) ? __( 'Preconnect', 'wpoptimizing' ) : __( 'DNS prefetch', 'wpoptimizing' );
		$c[]   = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, $label );
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ), $label ) );
	}
	if ( ! empty( $advanced['query_string'] ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. Removing the version from file URLs does not make pages faster and can serve outdated files after an update.', 'wpoptimizing' ), __( 'Remove URL query strings', 'wpoptimizing' ) ) );
	}
	if ( isset( $advanced['post_revisions'] ) && '' !== (string) $advanced['post_revisions'] && ! is_bool( $advanced['post_revisions'] ) && (int) $advanced['post_revisions'] > 0 ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: Hummingbird limits how many revisions WordPress keeps; WPOptimizing cleans old ones instead, keeping the last two of each post, which you can change in General cleanup.', 'wpoptimizing' ), __( 'Post revisions', 'wpoptimizing' ) ) );
	}
	if ( ! empty( $s['gravatar']['enabled'] ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $not_imported, __( 'Gravatar Caching', 'wpoptimizing' ) ) );
	}
	if ( ! empty( $s['cdn']['enabled'] ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: WPMU DEV\'s own CDN, not imported.', 'wpoptimizing' ), 'CDN' ) );
	}
	if ( ! empty( $s['cloudflare']['enabled'] ) || ! empty( $s['cloudflare']['connected'] ) ) {
		/* translators: %s: the other plugin's name. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( 'Cloudflare: the credentials %s keeps are not copied. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ), 'Hummingbird' ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
