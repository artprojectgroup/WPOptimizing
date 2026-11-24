<?php
/**
 * Import source: Powered Cache.
 *
 * Its whole configuration is one flat option, `powered_cache_settings` (includes/constants.php:13),
 * per site, or of the network when the site defines POWERED_CACHE_IS_NETWORK
 * (includes/classes/SettingsRepository.php:91-99, 456-458). Keys, types and defaults read in
 * Powered Cache 4.0.2, SettingsSchema::fields() (includes/classes/SettingsSchema.php:42-166);
 * docs/cache-2026-11-06/importadores-B.md §7. The files in wp-content/pc-config/ are generated
 * from it: never read.
 *
 * A key the option does not carry is at Powered Cache's default, and a default is not imported:
 * every change below needs the key present.
 *
 * Never imported: Cloudflare's token, e-mail and Global API Key, Sucuri's key and secret.
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
function wpoptimizing_import_src_powered_cache_read() {
	$network = defined( 'POWERED_CACHE_IS_NETWORK' ) && POWERED_CACHE_IS_NETWORK;
	$raw     = $network ? get_site_option( 'powered_cache_settings' ) : get_option( 'powered_cache_settings' );
	// Deactivated, the constant may be gone with it: a network still has its copy there.
	if ( ( ! is_array( $raw ) || empty( $raw ) ) && is_multisite() && ! $network ) {
		$raw = get_site_option( 'powered_cache_settings' );
	}
	return is_array( $raw ) && ! empty( $raw ) ? $raw : null;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r powered_cache_settings.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_powered_cache_map( array $r ) {
	$c     = array();
	$notes = array();
	$on    = static function ( $key ) use ( $r ) {
		return ! empty( $r[ $key ] );
	};
	// Its lists are textareas, one item per line (AdvancedCache::get_rejected_uri() and the rest).
	$lines = static function ( $key ) use ( $r ) {
		$v = $r[ $key ] ?? '';
		$v = is_array( $v ) ? $v : preg_split( '/[\r\n]+/', (string) $v );
		return array_values( array_filter( array_map( 'trim', array_filter( (array) $v, 'is_scalar' ) ), 'strlen' ) );
	};
	// File lists accept * as a wildcard (Optimizer/Helper.php:150).
	$files = static function ( $key, $label, array &$notes ) use ( $lines ) {
		return wpoptimizing_importer_patterns( str_replace( '*', '.*', $lines( $key ) ), $label, $notes );
	};
	/* translators: %s: setting name in the other plugin. */
	$not_imported = __( '%s: not imported.', 'wpoptimizing' );

	// Page cache. Switched off there, its exclusions and lifetime mean nothing.
	if ( ! isset( $r['enable_page_cache'] ) || $on( 'enable_page_cache' ) ) {
		if ( isset( $r['cache_timeout'] ) ) {
			// Minutes there; 0 means pages never expire.
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $r['cache_timeout'] * MINUTE_IN_SECONDS, $notes ), __( 'Cache Lifespan', 'wpoptimizing' ) );
		}
		if ( isset( $r['gzip_compression'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'gzip', $on( 'gzip_compression' ), __( 'Gzip Compression', 'wpoptimizing' ) );
		}
		if ( isset( $r['cache_mobile_separate_file'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', ( ! isset( $r['cache_mobile'] ) || $on( 'cache_mobile' ) ) && $on( 'cache_mobile_separate_file' ), __( 'Separate Mobile Cache', 'wpoptimizing' ) );
		}
		if ( isset( $r['enable_cache_preload'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( 'enable_cache_preload' ), __( 'Cache Preload', 'wpoptimizing' ) );
		}
		// Regular expressions matched whole against the request URI; a full URL counts by its path.
		$paths = array();
		foreach ( $lines( 'rejected_uri' ) as $uri ) {
			$paths[] = preg_match( '#^https?://#i', $uri ) ? (string) wp_parse_url( $uri, PHP_URL_PATH ) : $uri;
		}
		$uri = wpoptimizing_importer_patterns( $paths, __( 'Never Cache URLs', 'wpoptimizing' ), $notes );
		if ( $uri ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Never Cache URLs', 'wpoptimizing' ) );
		}
		$cookies = wpoptimizing_importer_patterns( $lines( 'rejected_cookies' ), __( 'Rejected Cookies', 'wpoptimizing' ), $notes );
		if ( $cookies ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $cookies, __( 'Rejected Cookies', 'wpoptimizing' ) );
		}
		$ua = wpoptimizing_importer_patterns( $lines( 'rejected_user_agents' ), __( 'Rejected User Agents', 'wpoptimizing' ), $notes );
		if ( $ua ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'Rejected User Agents', 'wpoptimizing' ) );
		}
		// Parameter names, compared whole there: on top of the tracking ones both plugins ignore.
		$ignored = wpoptimizing_importer_patterns( $lines( 'ignored_query_strings' ), __( 'Ignored Query Strings', 'wpoptimizing' ), $notes );
		if ( $ignored ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ignored_query', $ignored, __( 'Ignored Query Strings', 'wpoptimizing' ) );
		}
		if ( $lines( 'cache_query_strings' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'query_whitelist', $lines( 'cache_query_strings' ), __( 'Cache Query Strings', 'wpoptimizing' ) );
		}
		if ( $on( 'loggedin_user_cache' ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Logged-in User Cache', 'wpoptimizing' ) ) );
		}
		foreach ( array(
			'rejected_referrers'     => __( 'Rejected Referrers', 'wpoptimizing' ),
			'vary_cookies'           => __( 'Vary Cookies', 'wpoptimizing' ),
			'purge_additional_pages' => __( 'Purge Additional Pages', 'wpoptimizing' ),
		) as $key => $label ) {
			if ( $lines( $key ) ) {
				$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $not_imported, $label ) );
			}
		}
	}

	// Object cache: it installs a drop-in, which an import never does.
	if ( isset( $r['object_cache'] ) && 'off' !== $r['object_cache'] && is_scalar( $r['object_cache'] ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Object Cache: not imported. Set up the object cache in WPOptimizing once Powered Cache is deactivated, because both use the same drop-in file.', 'wpoptimizing' ) );
	}

	// CSS and JavaScript.
	foreach ( array(
		'minify_css' => __( 'Minify CSS', 'wpoptimizing' ),
		'minify_js'  => __( 'Minify JavaScript', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $key, $on( $key ), $label );
		}
	}
	$exclude = array_merge(
		$files( 'excluded_css_files', __( 'Excluded CSS Files', 'wpoptimizing' ), $notes ),
		$files( 'excluded_js_files', __( 'Excluded JavaScript Files', 'wpoptimizing' ), $notes )
	);
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $exclude ) ), __( 'Excluded JavaScript Files', 'wpoptimizing' ) );
	}
	// The old single «JavaScript execution» setting is migrated by Powered Cache on its next save.
	$method = (string) ( $r['js_execution_method'] ?? '' );
	$defer  = $on( 'js_defer' ) || in_array( $method, array( 'async', 'defer' ), true );
	$delay  = $on( 'js_delay' ) || in_array( $method, array( 'delay', 'delayed' ), true );
	if ( isset( $r['js_defer'] ) || $defer ) {
		$c[]      = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', $defer, __( 'Defer JavaScript', 'wpoptimizing' ) );
		$defer_ex = $defer ? $files( 'js_defer_exclusions', __( 'Defer JavaScript Exclusions', 'wpoptimizing' ), $notes ) : array();
		if ( $defer_ex ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', $defer_ex, __( 'Defer JavaScript Exclusions', 'wpoptimizing' ) );
		}
	}
	if ( isset( $r['js_delay'] ) || $delay ) {
		$c[]      = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', $delay, __( 'Delay JavaScript', 'wpoptimizing' ) );
		$delay_ex = $delay ? $files( 'js_delay_exclusions', __( 'Delay JavaScript Exclusions', 'wpoptimizing' ), $notes ) : array();
		if ( $delay_ex ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', $delay_ex, __( 'Delay JavaScript Exclusions', 'wpoptimizing' ) );
		}
		// 0 there means «only on interaction»; ours always has a safety timer, so 0 keeps ours.
		if ( $delay && (int) ( $r['js_delay_timeout'] ?? 0 ) > 0 ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'timeout', (int) $r['js_delay_timeout'], __( 'Delay Timeout', 'wpoptimizing' ) );
		}
	}
	if ( $on( 'critical_css' ) && is_string( $r['critical_css_fallback'] ?? null ) && '' !== trim( $r['critical_css_fallback'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'critical_css', (string) $r['critical_css_fallback'], __( 'Fallback Critical CSS', 'wpoptimizing' ) );
	}
	foreach ( array(
		'combine_css' => __( 'Combine CSS', 'wpoptimizing' ),
		'combine_js'  => __( 'Combine JavaScript', 'wpoptimizing' ),
	) as $key => $label ) {
		// The old «delay» method switches combining JavaScript off on Powered Cache's next save.
		if ( $on( $key ) && ! ( 'combine_js' === $key && in_array( $method, array( 'delay', 'delayed' ), true ) ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ), $label ) );
		}
	}
	if ( $on( 'minify_html' ) ) {
		// The page cache minifies the HTML of the copies it stores (html-minify.php).
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'Minify HTML', 'wpoptimizing' ) );
	}
	if ( $on( 'remove_unused_css' ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. WPOptimizing generates critical CSS on your server instead.', 'wpoptimizing' ), __( 'Remove Unused CSS', 'wpoptimizing' ) ) );
	}

	// Media and fonts.
	if ( isset( $r['enable_lazy_load'] ) ) {
		// Images, iframes and YouTube are separate switches (LazyLoad.php:178, :206, :262); images
		// and iframes default to on (SettingsSchema.php:99-105). Iframes and YouTube are ours
		// «lazy embeds»; only images are image lazy loading.
		$lazy    = $on( 'enable_lazy_load' );
		$images  = ! isset( $r['lazy_load_images'] ) || $on( 'lazy_load_images' );
		$iframes = ! isset( $r['lazy_load_iframes'] ) || $on( 'lazy_load_iframes' );
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $lazy && $images, __( 'Lazy Load', 'wpoptimizing' ) );
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', $lazy && ( $iframes || $on( 'lazy_load_youtube' ) ), __( 'YouTube Embeds', 'wpoptimizing' ) );
		if ( $lazy && $lines( 'lazy_load_exclusions' ) ) {
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $not_imported, __( 'Lazy Load Exclusions', 'wpoptimizing' ) ) );
		}
	}
	if ( isset( $r['disable_emoji_scripts'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( 'disable_emoji_scripts' ), __( 'Emoji Scripts', 'wpoptimizing' ) );
	}
	if ( isset( $r['disable_wp_embeds'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'embeds', $on( 'disable_wp_embeds' ), __( 'WordPress Embeds', 'wpoptimizing' ) );
	}
	if ( $on( 'self_host_google_fonts' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', true, __( 'Self-host Google Fonts', 'wpoptimizing' ) );
	}
	if ( $lines( 'preload_fonts' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Preload Fonts', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', $lines( 'preload_fonts' ), __( 'Preload Fonts', 'wpoptimizing' ) );
	}
	if ( $lines( 'prefetch_dns' ) || $lines( 'preconnect_resource' ) ) {
		$label = $lines( 'preconnect_resource' ) ? __( 'Preconnect', 'wpoptimizing' ) : __( 'DNS Prefetch', 'wpoptimizing' );
		$c[]   = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, $label );
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ), $label ) );
	}

	// CDN: one host here, for every static file. Each host there serves a zone (all, images,
	// CSS, JS); a file goes to its zone's host, else to an «all» host, else nowhere
	// (CDN.php:304-330, utils.php:830-845). So only a host of the «all» zone can take every
	// file, and only when nothing is excluded.
	if ( $on( 'enable_cdn' ) ) {
		$names = (array) ( $r['cdn_hostname'] ?? array() );
		$zones = (array) ( $r['cdn_zone'] ?? array() );
		$hosts = array();
		$all   = array();
		foreach ( $names as $i => $host ) {
			if ( ! is_scalar( $host ) || '' === trim( (string) $host ) ) {
				continue;
			}
			$hosts[] = trim( (string) $host );
			$zone    = isset( $zones[ $i ] ) && is_scalar( $zones[ $i ] ) ? (string) $zones[ $i ] : ( $zones ? '' : 'all' );
			if ( 'all' === $zone ) {
				$all[] = trim( (string) $host );
			}
		}
		if ( $hosts && ( ! $all || $lines( 'cdn_rejected_files' ) ) ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'CDN Delivery', 'wpoptimizing' ), $all ? $all[0] : $hosts[0] );
		} elseif ( $all ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'CDN Delivery', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', $all[0], __( 'CDN Hostnames', 'wpoptimizing' ) );
			if ( count( $hosts ) > 1 ) {
				$notes[] = wpoptimizing_importer_note( 'review', __( 'CDN Hostnames: WPOptimizing uses one CDN host for every static file, so only the first one was imported, without its zone or excluded files.', 'wpoptimizing' ) );
			}
		}
	}

	// Database cleanup. A schedule runs exactly the ticked tasks (Async/DatabaseOptimizer.php):
	// then every other task here, revisions included, is left off.
	$scheduled = $on( 'enable_scheduled_db_cleanup' ) && in_array( $r['scheduled_db_cleanup_frequency'] ?? 'daily', array( 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly' ), true );
	$selected  = array();
	foreach ( array(
		'db_cleanup_auto_drafts'        => array( 'delete_auto_drafts', __( 'Auto Drafts', 'wpoptimizing' ) ),
		'db_cleanup_trashed_posts'      => array( 'delete_trashed_posts', __( 'Trashed Posts', 'wpoptimizing' ) ),
		'db_cleanup_spam_comments'      => array( 'delete_spam_comments', __( 'Spam Comments', 'wpoptimizing' ) ),
		'db_cleanup_trashed_comments'   => array( 'delete_trashed_comments', __( 'Trashed Comments', 'wpoptimizing' ) ),
		'db_cleanup_expired_transients' => array( 'delete_expired_transients', __( 'Expired Transients', 'wpoptimizing' ) ),
		'db_cleanup_all_transients'     => array( 'delete_all_transients', __( 'All Transients', 'wpoptimizing' ) ),
		'db_cleanup_optimize_tables'    => array( 'optimize_tables', __( 'Optimize Tables', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( $scheduled && $on( $key ) ) {
			$selected[ $to[0] ] = $to[1];
		} elseif ( ! $scheduled && isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', $to[0], $on( $key ) ? 1 : 0, $to[1] );
		}
	}
	if ( $on( 'db_cleanup_post_revisions' ) ) {
		$selected['revisions'] = __( 'Post Revisions', 'wpoptimizing' );
		/* translators: %s: the other plugin's name. */
		$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( 'Revisions: %s deletes all of them; WPOptimizing keeps the last two of each post, which you can change in General cleanup.', 'wpoptimizing' ), 'Powered Cache' ) );
	}
	if ( $scheduled ) {
		$c   = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, __( 'Database Cleanup Frequency', 'wpoptimizing' ) ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', (string) ( $r['scheduled_db_cleanup_frequency'] ?? 'daily' ), __( 'Database Cleanup Frequency', 'wpoptimizing' ) );
	}

	// Heartbeat: the same three zones, each 'enable' (untouched), 'disable' or 'modify' with its
	// interval (Extensions/Heartbeat/Heartbeat.php; defaults 60 / 15 / 60, SettingsSchema.php:146-151).
	if ( $on( 'enable_heartbeat' ) ) {
		$zones = array();
		foreach ( array(
			'admin'  => array( 'dashboard', 60 ),
			'editor' => array( 'editor', 15 ),
			'front'  => array( 'frontend', 60 ),
		) as $zone => $from ) {
			$status   = (string) ( $r[ 'heartbeat_' . $from[0] . '_status' ] ?? 'enable' );
			$interval = isset( $r[ 'heartbeat_' . $from[0] . '_interval' ] ) && is_scalar( $r[ 'heartbeat_' . $from[0] . '_interval' ] ) ? (int) $r[ 'heartbeat_' . $from[0] . '_interval' ] : $from[1];
			if ( 'disable' === $status ) {
				$zones[ $zone ] = 'off';
			} elseif ( 'modify' === $status && $interval > 0 ) {
				$zones[ $zone ] = $interval;
			} else {
				$zones[ $zone ] = 'default';
			}
		}
		$c = array_merge( $c, wpoptimizing_importer_heartbeat( $zones, __( 'Heartbeat Control', 'wpoptimizing' ), $notes ) );
	}

	// Integrations.
	if ( $on( 'enable_cloudflare' ) ) {
		/* translators: %s: the other plugin's name. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( 'Cloudflare: the credentials %s keeps are not copied. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ), 'Powered Cache' ) );
	}
	if ( $on( 'enable_varnish' ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Varnish: switch on its purge in Cache › Browser & server once WPOptimizing has checked that Varnish is in front of the site.', 'wpoptimizing' ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
