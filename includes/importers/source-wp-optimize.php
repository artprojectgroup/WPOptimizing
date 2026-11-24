<?php
/**
 * Import source: WP-Optimize (free and Premium).
 *
 * Its configuration lives in three places (keys read in WP-Optimize 4.7.0,
 * docs/cache-2026-11-06/importadores-B.md §3):
 *  - `wpo_cache_config`: the page cache (cache/class-wpo-cache-config.php:221-256);
 *  - `wpo_minify_config`: minification, defer, fonts (minify/class-wp-optimize-minify-config.php:100-180);
 *  - loose `wp-optimize-<key>` options: the scheduled database cleanup and, in Premium, lazy load
 *    (includes/class-wp-optimize-options.php:60-86).
 * In a multisite network all of them are NETWORK options, and the loose ones are named
 * `wp-optimize-mu-<key>`. The copy in wp-content/wpo-cache/config/ is generated: never read.
 *
 * Each block is imported only when it was switched on there: WP-Optimize saves the defaults of a
 * block as soon as its screen is saved once, and importing an unused block would only bring noise.
 *
 * Never imported: Cloudflare's e-mail, Global API Key and token (Premium, inside wpo_cache_config),
 * the Google Analytics tracking id, and its image-compression service.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The live configuration.
 *
 * @return array|null
 */
function wpoptimizing_import_src_wp_optimize_read() {
	$network = is_multisite();
	$opt     = static function ( $name ) use ( $network ) {
		return $network ? get_site_option( $name ) : get_option( $name );
	};
	$loose   = static function ( $key ) use ( $opt, $network ) {
		return $opt( ( $network ? 'wp-optimize-mu-' : 'wp-optimize-' ) . $key );
	};
	$raw     = array(
		'cache'             => $opt( 'wpo_cache_config' ),
		'minify'            => $opt( 'wpo_minify_config' ),
		'auto'              => $loose( 'auto' ),
		'schedule'          => $loose( 'schedule' ),
		'schedule-type'     => $loose( 'schedule-type' ),
		'retention-enabled' => $loose( 'retention-enabled' ),
		'lazyload'          => $loose( 'lazyload' ),
	);
	foreach ( array( 'cache', 'minify', 'auto', 'lazyload' ) as $block ) {
		$raw[ $block ] = is_array( $raw[ $block ] ) ? $raw[ $block ] : array();
	}
	return ( $raw['cache'] || $raw['minify'] || $raw['auto'] || $raw['lazyload'] ) ? $raw : null;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r What wpoptimizing_import_src_wp_optimize_read() returns.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_wp_optimize_map( array $r ) {
	$c      = array();
	$notes  = array();
	$cache  = (array) ( $r['cache'] ?? array() );
	$minify = (array) ( $r['minify'] ?? array() );
	$lazy   = (array) ( $r['lazyload'] ?? array() );
	$auto   = (array) ( $r['auto'] ?? array() );
	// Its switches are booleans, 0/1 or the strings 'true'/'false', depending on the screen.
	$on    = static function ( array $set, $key ) {
		$v = $set[ $key ] ?? false;
		return is_string( $v ) ? ! in_array( strtolower( trim( $v ) ), array( '', '0', 'false', 'off', 'no' ), true ) : ! empty( $v );
	};
	$lines = static function ( $value ) {
		$value = is_array( $value ) ? $value : preg_split( '/[\r\n]+/', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_filter( (array) $value, 'is_scalar' ) ), 'strlen' ) );
	};

	// Page cache. Its exclusions are paths or full URLs with * as a wildcard, matched whole.
	if ( $on( $cache, 'enable_page_caching' ) ) {
		if ( isset( $cache['page_cache_length'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $cache['page_cache_length'], $notes ), __( 'Cache lifespan', 'wpoptimizing' ) );
		}
		$masks = array();
		foreach ( $lines( $cache['cache_exception_urls'] ?? array() ) as $mask ) {
			$path    = preg_match( '#^https?://#i', $mask ) ? (string) wp_parse_url( $mask, PHP_URL_PATH ) : $mask;
			$masks[] = str_replace( '*', '.*', '' === $path ? '/' : $path );
		}
		$uri = wpoptimizing_importer_patterns( $masks, __( 'URLs to exclude from caching', 'wpoptimizing' ), $notes );
		if ( $uri ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'URLs to exclude from caching', 'wpoptimizing' ) );
		}
		// Cookies: plain substrings there too (strpos on the cookie name).
		if ( $lines( $cache['cache_exception_cookies'] ?? array() ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $lines( $cache['cache_exception_cookies'] ), __( 'Cookies which, if present, will prevent caching', 'wpoptimizing' ) );
		}
		if ( isset( $cache['enable_mobile_caching'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( $cache, 'enable_mobile_caching' ), __( 'Generate separate files for mobile devices', 'wpoptimizing' ) );
		}
		if ( isset( $cache['auto_preload_purged_contents'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( $cache, 'auto_preload_purged_contents' ), __( 'Automatically preload content after it is purged', 'wpoptimizing' ) );
		}
		if ( $on( $cache, 'enable_user_caching' ) || $on( $cache, 'enable_per_role_cache' ) || $on( $cache, 'enable_user_specific_cache' ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Serve cached pages to logged in users', 'wpoptimizing' ) ) );
		}
		// User agents: unanchored masks with * as a wildcard, case-insensitive, as here.
		$ua = wpoptimizing_importer_patterns( str_replace( '*', '.*', $lines( $cache['cache_exception_browser_agents'] ?? array() ) ), __( 'List of browser agent strings which, if detected, will prevent caching', 'wpoptimizing' ), $notes );
		if ( $ua ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'List of browser agent strings which, if detected, will prevent caching', 'wpoptimizing' ) );
		}
		$ignored = wpoptimizing_importer_patterns( $lines( $cache['cache_ignore_query_variables'] ?? array() ), __( 'Ignore these query variables when caching', 'wpoptimizing' ), $notes );
		if ( $ignored ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ignored_query', $ignored, __( 'Ignore these query variables when caching', 'wpoptimizing' ) );
		}
		if ( $lines( $cache['cache_exception_conditional_tags'] ?? array() ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Conditional tags to exclude from caching', 'wpoptimizing' ) ) );
		}
		if ( $on( $cache, 'cache_specific_urls_only' ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Cache Specific URLs Only', 'wpoptimizing' ) ) );
		}
	}
	if ( '' !== trim( (string) ( $cache['cloudflare_api_key'] ?? '' ) . (string) ( $cache['cloudflare_api_token'] ?? '' ) ) ) {
		/* translators: %s: the other plugin's name. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( 'Cloudflare: the credentials %s keeps are not copied. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ), 'WP-Optimize' ) );
	}

	// Minify: nothing of it runs unless its main switch is on. What the saved option leaves out
	// takes WP-Optimize's own default (minify/class-wp-optimize-minify-config.php:63, :126-131),
	// where CSS and JavaScript and their minification are on.
	$minify += array(
		'enable_css'              => true,
		'enable_css_minification' => true,
		'enable_js'               => true,
		'enable_js_minification'  => true,
	);
	if ( $on( $minify, 'enabled' ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_css', $on( $minify, 'enable_css' ) && $on( $minify, 'enable_css_minification' ), __( 'Enable minification of CSS files', 'wpoptimizing' ) );
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_js', $on( $minify, 'enable_js' ) && $on( $minify, 'enable_js_minification' ), __( 'Enable minification of JavaScript files', 'wpoptimizing' ) );
		$exclude = array_merge( $lines( $minify['exclude_css'] ?? '' ), $lines( $minify['exclude_js'] ?? '' ) );
		if ( $exclude ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $exclude ) ), __( 'Exclude JavaScript from processing', 'wpoptimizing' ) );
		}
		if ( $on( $minify, 'enable_js' ) && 'all' === ( $minify['enable_defer_js'] ?? '' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', true, __( 'Defer all the JavaScript files', 'wpoptimizing' ) );
		} elseif ( $lines( $minify['async_js'] ?? '' ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing defers every script except the ones you exclude, not a chosen few, so this list was not imported.', 'wpoptimizing' ), __( 'Asynchronously load selected JavaScript files', 'wpoptimizing' ) ) );
		}
		if ( $on( $minify, 'enable_css' ) && $on( $minify, 'loadcss' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'async_css', true, __( 'Load all CSS files asynchronously', 'wpoptimizing' ) );
		}
		if ( is_string( $minify['critical_path_css'] ?? null ) && '' !== trim( $minify['critical_path_css'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'critical_css', (string) $minify['critical_path_css'], __( 'Fallback CSS', 'wpoptimizing' ) );
		}
		if ( isset( $minify['enable_delay_js'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', $on( $minify, 'enable_delay_js' ), __( 'Delay JS', 'wpoptimizing' ) );
			// Paths with * as a wildcard: the ones at either end go, one in the middle is noted.
			$delay = $on( $minify, 'enable_delay_js' ) ? wpoptimizing_importer_patterns( str_replace( '*', '.*', $lines( $minify['exclude_delay_js'] ?? '' ) ), __( 'Delay JS', 'wpoptimizing' ), $notes ) : array();
			if ( $delay ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', $delay, __( 'Delay JS', 'wpoptimizing' ) );
			}
		}
		if ( isset( $minify['emoji_removal'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( $minify, 'emoji_removal' ), __( 'Remove default WordPress and TinyMCE icons', 'wpoptimizing' ) );
		}
		if ( $on( $minify, 'host_local_google_fonts' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', true, __( 'Host Google Fonts locally', 'wpoptimizing' ) );
		}
		if ( $lines( $minify['hpreconnect'] ?? '' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'Preconnect Headers', 'wpoptimizing' ) );
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ), __( 'Preconnect Headers', 'wpoptimizing' ) ) );
		}
		foreach ( array(
			'enable_merging_of_css' => __( 'Enable merging of CSS files', 'wpoptimizing' ),
			'enable_merging_of_js'  => __( 'Enable merging of JavaScript files', 'wpoptimizing' ),
		) as $key => $label ) {
			if ( $on( $minify, $key ) ) {
				/* translators: %s: setting name in the other plugin. */
				$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ), $label ) );
			}
		}
		if ( $on( $minify, 'html_minification' ) ) {
			// The page cache minifies the HTML of the copies it stores (html-minify.php).
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'Process HTML', 'wpoptimizing' ) );
		}
		if ( $on( $minify, 'enable_unused_css' ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. WPOptimizing generates critical CSS on your server instead.', 'wpoptimizing' ), __( 'Enable removal of unused rules from CSS files (Experimental)', 'wpoptimizing' ) ) );
		}
		if ( '' !== trim( (string) ( $minify['cdn_url'] ?? '' ) ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. It only applied to the files the other plugin optimized, while WPOptimizing\'s CDN rewrites every static file; set it up yourself if your CDN serves them all.', 'wpoptimizing' ), __( 'Your CDN domain', 'wpoptimizing' ) ) );
		}
	}

	// Lazy load (Premium).
	// Images, «Iframes and Videos» and the YouTube thumbnail are separate switches
	// (templates/images/lazyload.php:43-47); the last two are ours «lazy embeds».
	if ( isset( $lazy['images'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( $lazy, 'images' ), __( 'Lazy-load images', 'wpoptimizing' ) );
	}
	if ( isset( $lazy['iframes'] ) || isset( $lazy['youtube_preview'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', $on( $lazy, 'iframes' ) || $on( $lazy, 'youtube_preview' ), __( 'Replace YouTube iframe with thumbnail image', 'wpoptimizing' ) );
	}
	if ( '' !== trim( (string) ( $lazy['skip_classes'] ?? '' ) ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Skip image classes', 'wpoptimizing' ) ) );
	}

	// Scheduled database cleanup: the tasks only mean something when the schedule is on.
	if ( $on( $r, 'schedule' ) && $auto ) {
		// The schedule runs exactly the ticked tasks: every other task here, revisions included,
		// is left off.
		$selected = array();
		foreach ( array(
			'drafts'     => array( array( 'delete_auto_drafts' ), __( 'Clean all auto-draft posts', 'wpoptimizing' ) ),
			'trash'      => array( array( 'delete_trashed_posts' ), __( 'Clean all trashed posts', 'wpoptimizing' ) ),
			'spams'      => array( array( 'delete_spam_comments', 'delete_trashed_comments' ), __( 'Remove spam and trashed comments', 'wpoptimizing' ) ),
			'unapproved' => array( array( 'delete_unapproved_comments' ), __( 'Remove unapproved comments', 'wpoptimizing' ) ),
			'pingbacks'  => array( array( 'delete_pingbacks' ), __( 'Remove pingbacks', 'wpoptimizing' ) ),
			'trackbacks' => array( array( 'delete_trackbacks' ), __( 'Remove trackbacks', 'wpoptimizing' ) ),
			'transient'  => array( array( 'delete_expired_transients' ), __( 'Remove expired transient options', 'wpoptimizing' ) ),
			'optimize'   => array( array( 'optimize_tables' ), __( 'Optimize database tables', 'wpoptimizing' ) ),
		) as $key => $to ) {
			if ( $on( $auto, $key ) ) {
				foreach ( $to[0] as $ours ) {
					$selected[ $ours ] = $to[1];
				}
			}
		}
		// Orphaned post/comment metadata and relationships: one task here.
		if ( $on( $auto, 'postmeta' ) || $on( $auto, 'commentmeta' ) || $on( $auto, 'orphandata' ) ) {
			$selected['delete_orphan_elements'] = __( 'Clean post metadata', 'wpoptimizing' );
		}
		if ( $on( $auto, 'revisions' ) ) {
			$selected['revisions'] = __( 'Clean all post revisions', 'wpoptimizing' );
			/* translators: %s: the other plugin's name. */
			$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( 'Revisions: %s deletes all of them; WPOptimizing keeps the last two of each post, which you can change in General cleanup.', 'wpoptimizing' ), 'WP-Optimize' ) );
		}
		$type = (string) ( $r['schedule-type'] ?? 'wpo_weekly' );
		$type = 'wpo_otherweekly' === $type ? 'wpo_fortnightly' : $type;
		$map  = array(
			'wpo_daily'       => 'daily',
			'wpo_weekly'      => 'weekly',
			'wpo_fortnightly' => 'weekly',
			'wpo_monthly'     => 'monthly',
		);
		if ( isset( $map[ $type ] ) ) {
			$c   = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, __( 'Enable scheduled clean-up and optimization', 'wpoptimizing' ) ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', $map[ $type ], __( 'Enable scheduled clean-up and optimization', 'wpoptimizing' ) );
			if ( 'wpo_fortnightly' === $type ) {
				/* translators: %s: setting name in the other plugin. */
				$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing has no fortnightly schedule, so it was set to weekly.', 'wpoptimizing' ), __( 'Select schedule type (default is Weekly)', 'wpoptimizing' ) ) );
			}
		}
	}
	if ( $on( $r, 'retention-enabled' ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: not imported. Set the minimum age of what is cleaned in General cleanup.', 'wpoptimizing' ), __( 'Keep last X weeks data', 'wpoptimizing' ) ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
