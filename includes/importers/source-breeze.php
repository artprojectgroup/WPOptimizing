<?php
/**
 * Import source: Breeze.
 *
 * Seven array options, `breeze_<group>`, with switches stored as '1'/'0'. On a network a site
 * reads the network's copy unless it has stopped inheriting (`breeze_inherit_settings` = '0'),
 * the same rule as its breeze_get_option(). The files in wp-content/breeze-config/ are
 * generated from these options: never read. Keys read in Breeze 2.6.4
 * (docs/cache-2026-11-06/importadores-B.md §1).
 *
 * Never imported: its API token (`breeze-api-token`) and the Cloudways Cloudflare details.
 * Breeze has no database cleanup settings: there it is a button.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The live configuration, by group (basic, file, advanced, heartbeat, cdn, preload, varnish).
 *
 * @return array|null
 */
function wpoptimizing_import_src_breeze_read() {
	$network = is_multisite() && '0' !== get_option( 'breeze_inherit_settings' );
	$out     = array();
	foreach ( array(
		'basic'     => 'basic_settings',
		'file'      => 'file_settings',
		'advanced'  => 'advanced_settings',
		'heartbeat' => 'heartbeat_settings',
		'cdn'       => 'cdn_integration',
		'preload'   => 'preload_settings',
		'varnish'   => 'varnish_cache',
	) as $group => $name ) {
		$value = $network ? get_site_option( 'breeze_' . $name ) : get_option( 'breeze_' . $name );
		if ( is_array( $value ) && ! empty( $value ) ) {
			$out[ $group ] = $value;
		}
	}
	return $out ? $out : null;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r The seven groups, as read().
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_breeze_map( array $r ) {
	$c     = array();
	$notes = array();
	$none  = array();
	$get   = static function ( $group, $key ) use ( $r ) {
		return is_array( $r[ $group ] ?? null ) ? ( $r[ $group ][ $key ] ?? null ) : null;
	};
	$has   = static function ( $group, $key ) use ( $get ) {
		return null !== $get( $group, $key );
	};
	$on    = static function ( $group, $key ) use ( $get ) {
		return '1' === (string) ( is_scalar( $get( $group, $key ) ) ? $get( $group, $key ) : '' );
	};
	$list  = static function ( $group, $key ) use ( $get ) {
		$v = $get( $group, $key );
		$v = is_string( $v ) ? preg_split( '/[\r\n,]+/', $v ) : (array) $v;
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ), 'strlen' ) );
	};

	// Page cache.
	if ( $on( 'basic', 'breeze-active' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'info',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: the import does not switch on WPOptimizing\'s page cache. Deactivate the other plugin first, then switch it on in Cache › Page cache.', 'wpoptimizing' ),
				__( 'Cache System', 'wpoptimizing' )
			)
		);
	}
	// Minutes of a cron that empties the whole cache; 0 = never.
	if ( $has( 'basic', 'breeze-b-ttl' ) && is_scalar( $get( 'basic', 'breeze-b-ttl' ) ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $get( 'basic', 'breeze-b-ttl' ) * MINUTE_IN_SECONDS, $notes ), __( 'Purge Cache After', 'wpoptimizing' ) );
	}
	if ( $has( 'basic', 'breeze-mobile-separate' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'basic', 'breeze-mobile-separate' ), __( 'Mobile Cache', 'wpoptimizing' ) );
	}
	// Paths are regular expressions there (with * or (.*) at the end); full URLs are compared
	// whole, so they come in as their path.
	$uri = array();
	foreach ( $list( 'advanced', 'breeze-exclude-urls' ) as $u ) {
		if ( preg_match( '#^(?:https?:)?//#i', $u ) ) {
			$u = (string) wp_parse_url( ( 0 === strpos( $u, '//' ) ? 'https:' : '' ) . $u, PHP_URL_PATH );
			$u = '' === $u ? '/' : $u;
		}
		$uri[] = (string) preg_replace( '#(?<!\.)\*$#', '.*', $u );
	}
	$uri = wpoptimizing_importer_patterns( $uri, __( 'Never Cache URL(s)', 'wpoptimizing' ), $notes );
	if ( $uri ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Never Cache URL(s)', 'wpoptimizing' ) );
	}
	if ( $list( 'advanced', 'cached-query-strings' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'query_whitelist', $list( 'advanced', 'cached-query-strings' ), __( 'Cache Query Strings', 'wpoptimizing' ) );
	}
	// Despite its name, 1 for a role means «cache pages for that role».
	if ( array_filter( (array) $get( 'basic', 'breeze-disable-admin' ) ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ),
				__( 'Cache Logged-in Users', 'wpoptimizing' )
			)
		);
	}
	if ( $on( 'preload', 'breeze-cache-warmup-enabled' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', true, __( 'Cache Warmup', 'wpoptimizing' ) );
		if ( $list( 'preload', 'breeze-preload-cache-urls' ) ) {
			$notes[] = wpoptimizing_importer_note( 'review', __( 'Cache Warmup: WPOptimizing warms up the home page and your latest content on its own, so the list of URLs was not copied.', 'wpoptimizing' ) );
		}
	}

	// Browser cache and compression.
	if ( $on( 'basic', 'breeze-gzip-compression' ) || $on( 'basic', 'breeze-browser-cache' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the settings' names in the other plugin. */
				__( '%s: not imported. Switch on the browser cache and compression in Cache › Browser & server once the other plugin is deactivated, because deactivating it can remove those rules from .htaccess.', 'wpoptimizing' ),
				__( 'Gzip Compression, Browser Cache', 'wpoptimizing' )
			)
		);
	}

	// CSS and JavaScript.
	foreach ( array(
		'breeze-minify-css' => array( 'minify_css', __( 'CSS Minify', 'wpoptimizing' ) ),
		'breeze-minify-js'  => array( 'minify_js', __( 'JS Minify', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( $has( 'file', $key ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $to[0], $on( 'file', $key ), $to[1] );
		}
	}
	$exclude = array_merge( $list( 'file', 'breeze-exclude-css' ), $list( 'file', 'breeze-exclude-js' ) );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $exclude ) ), __( 'Exclude CSS, Exclude JS', 'wpoptimizing' ) );
	}
	foreach ( array(
		'breeze-group-css' => __( 'Combine CSS', 'wpoptimizing' ),
		'breeze-group-js'  => __( 'Combine JS', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( 'file', $key ) ) {
			$notes[] = wpoptimizing_importer_note(
				'skipped',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ),
					$label
				)
			);
		}
	}
	// A list of the files to defer there; every script but the exclusions here.
	if ( $list( 'file', 'breeze-defer-js' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'JS Files With Deferred Loading: Breeze defers only the files you list, while WPOptimizing defers every script except the ones you exclude, so it was not switched on. Try «Load JavaScript deferred» in WPOptimizing and check the site.', 'wpoptimizing' ) );
	}
	// The page cache minifies the HTML of the copies it stores (html-minify.php).
	if ( $on( 'file', 'breeze-minify-html' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'HTML Minify', 'wpoptimizing' ) );
	}
	if ( $list( 'file', 'breeze-move-to-footer-js' ) ) {
		$none[] = __( 'Move JS Files to Footer', 'wpoptimizing' );
	}
	if ( $on( 'file', 'breeze-font-display-swap' ) ) {
		$none[] = __( 'Font remain visible during load', 'wpoptimizing' );
	}

	// Delay: everything, or the listed scripts (Breeze only delays those when the list is not
	// empty).
	$all    = $on( 'file', 'breeze-delay-all-js' );
	$listed = $on( 'file', 'breeze-enable-js-delay' ) && $list( 'file', 'breeze-delay-js-scripts' );
	if ( $all || $listed ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', true, $all ? __( 'Delay All JavaScript', 'wpoptimizing' ) : __( 'Delay JS Inline Scripts', 'wpoptimizing' ) );
		// The list alone is our «custom» profile: «safe» would add its own targets to it.
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'profile', $all ? 'all' : 'custom', $all ? __( 'Delay All JavaScript', 'wpoptimizing' ) : __( 'Delay JS Inline Scripts', 'wpoptimizing' ) );
		if ( $listed && ! $all ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'include', $list( 'file', 'breeze-delay-js-scripts' ), __( 'Delay JS Inline Scripts', 'wpoptimizing' ) );
		}
		if ( $list( 'file', 'no-breeze-no-delay-js' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', $list( 'file', 'no-breeze-no-delay-js' ), __( 'List of scripts not to delay', 'wpoptimizing' ) );
		}
		if ( $all ) {
			$c[]     = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'test_mode', true, __( 'Delay All JavaScript', 'wpoptimizing' ) );
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: imported with «Test mode: apply only for logged-in administrators» on, because delaying every script can break menus, sliders and carts. Check your key pages, then switch test mode off in Delay JavaScript.', 'wpoptimizing' ),
					__( 'Delay All JavaScript', 'wpoptimizing' )
				)
			);
		}
	} elseif ( $has( 'file', 'breeze-delay-all-js' ) || $has( 'file', 'breeze-enable-js-delay' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', false, __( 'Delay All JavaScript', 'wpoptimizing' ) );
	}

	// Lazy load: images are the main switch, iframes a separate one that only acts with it on
	// (class-breeze-lazy-load.php:94, :230). Videos are <video> tags, which ours does not touch.
	if ( $has( 'basic', 'breeze-lazy-load' ) ) {
		$lazy = $on( 'basic', 'breeze-lazy-load' );
		$c[]  = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $lazy, __( 'Lazy Load Images', 'wpoptimizing' ) );
		if ( $has( 'basic', 'breeze-lazy-load-iframes' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', $lazy && $on( 'basic', 'breeze-lazy-load-iframes' ), __( 'Lazy Load Images', 'wpoptimizing' ) );
		}
	}

	// Preload, fonts, prefetch.
	if ( $list( 'preload', 'breeze-preload-fonts' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Preload Webfont', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', $list( 'preload', 'breeze-preload-fonts' ), __( 'Preload Webfont', 'wpoptimizing' ) );
	}
	if ( $on( 'preload', 'breeze-preload-links' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'speculation', 'conservative', __( 'Preload Links', 'wpoptimizing' ) );
	}
	if ( $list( 'preload', 'breeze-prefetch-urls' ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'Prefetch of DNS Request', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ),
				__( 'Prefetch of DNS Request', 'wpoptimizing' )
			)
		);
	}
	if ( $has( 'advanced', 'breeze-store-googlefonts-locally' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', $on( 'advanced', 'breeze-store-googlefonts-locally' ), __( 'Host Files Locally', 'wpoptimizing' ) );
	}
	if ( $on( 'advanced', 'breeze-store-gravatars-locally' ) ) {
		$none[] = __( 'Host Files Locally: Gravatars', 'wpoptimizing' );
	}
	if ( $has( 'advanced', 'breeze-wp-emoji' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( 'advanced', 'breeze-wp-emoji' ), __( 'Disable Emoji', 'wpoptimizing' ) );
	}

	// Heartbeat: the same three zones, each '' / 'default' (untouched), 'disable' or seconds
	// (inc/class-breeze-heartbeat-settings.php).
	if ( $on( 'heartbeat', 'breeze-control-heartbeat' ) ) {
		$zones = array();
		foreach ( array(
			'admin'  => 'breeze-heartbeat-backend',
			'editor' => 'breeze-heartbeat-postedit',
			'front'  => 'breeze-heartbeat-front',
		) as $zone => $key ) {
			$v              = is_scalar( $get( 'heartbeat', $key ) ) ? (string) $get( 'heartbeat', $key ) : '';
			$zones[ $zone ] = 'disable' === $v ? 'off' : ( ( is_numeric( $v ) && (int) $v > 0 ) ? (int) $v : 'default' );
		}
		$c = array_merge( $c, wpoptimizing_importer_heartbeat( $zones, __( 'Heartbeat Control', 'wpoptimizing' ), $notes ) );
	}

	// CDN: one host and three folders here.
	$cdn_url = $get( 'cdn', 'cdn-url' );
	if ( $on( 'cdn', 'cdn-active' ) && is_string( $cdn_url ) && '' !== trim( $cdn_url ) ) {
		// No folder means wp-content and wp-includes (breeze-cdn-rewrite.php:97-104). Its stock
		// exclusion, .php, is what ours never rewrites anyway; any other one cannot be kept.
		$dirs = $list( 'cdn', 'cdn-content' );
		$dirs = wpoptimizing_importer_cdn_dirs( $dirs ? $dirs : array( 'wp-content', 'wp-includes' ) );
		$excl = array_diff( $list( 'cdn', 'cdn-exclude-content' ), array( '.php' ) );
		if ( null === $dirs || $excl ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'Activate CDN', 'wpoptimizing' ), trim( $cdn_url ) );
		} else {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'Activate CDN', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', trim( $cdn_url ), __( 'CDN CNAME', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'dirs', $dirs, __( 'CDN Content', 'wpoptimizing' ) );
		}
	}

	// Varnish.
	if ( $on( 'varnish', 'auto-purge-varnish' ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Varnish: switch on its purge in Cache › Browser & server once WPOptimizing has checked that Varnish is in front of the site.', 'wpoptimizing' ) );
	}

	if ( $none ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: list of settings in the other plugin. */
				__( 'No equivalent in WPOptimizing, not imported: %s.', 'wpoptimizing' ),
				implode( ', ', $none )
			)
		);
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
