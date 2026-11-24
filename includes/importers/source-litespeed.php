<?php
/**
 * Import source: LiteSpeed Cache.
 *
 * One option per setting, `litespeed.conf.<id>` (src/root.cls.php:563-566); lists are stored as
 * JSON (:668-673) or, in old installs, as text with one entry per line (src/base.cls.php:731-734).
 * With LITESPEED_CONF defined, a LITESPEED_CONF__<ID> constant overrides the database
 * (src/conf.cls.php:252-259, Base::conf_const()). Keys read in LiteSpeed Cache 7.9.3
 * (docs/cache-2026-11-06/importadores-A.md §3).
 *
 * On a LiteSpeed web server its page cache is served by the server itself, before PHP: there
 * our PHP page cache would be a step back, so its page cache settings are left out and the
 * note says why. Everything else still comes in.
 *
 * Never read: api_key, the Cloudflare key and e-mail, object-user / object-pswd,
 * crawler-cookies, the security hash. QUIC.cloud services (UCSS, CCSS generation, VPI, LQIP,
 * image optimisation) are noted, never copied.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The settings this source reads. Nothing outside this list is ever loaded, so credentials
 * cannot travel by accident.
 *
 * @return string[]
 */
function wpoptimizing_import_src_litespeed_ids() {
	return array(
		'cache',
		'cache-priv',
		'cache-ttl_pub',
		'cache-exc',
		'cache-exc_cookies',
		'cache-exc_useragents',
		'cache-drop_qs',
		'cache-mobile',
		'crawler',
		'guest',
		'optm-css_min',
		'optm-js_min',
		'optm-css_exc',
		'optm-js_exc',
		'optm-css_comb',
		'optm-js_comb',
		'optm-html_min',
		'optm-js_defer',
		'optm-js_defer_exc',
		'optm-js_delay_inc',
		'optm-css_async',
		'optm-ccss_con',
		'optm-ucss',
		'optm-emoji_rm',
		'optm-ggfonts_async',
		'optm-ggfonts_rm',
		'optm-dns_prefetch',
		'optm-dns_preconnect',
		'util-instant_click',
		'media-lazy',
		'media-iframe_lazy',
		'media-lazy_exc',
		'media-lazy_cls_exc',
		'media-lazy_parent_cls_exc',
		'media-lazy_uri_exc',
		'img_optm-webp',
		'cdn',
		'cdn-mapping',
		'cdn-ori_dir',
		'cdn-exc',
		'cdn-cloudflare',
		'db_optm-revisions_max',
		'db_optm-revisions_age',
		'misc-heartbeat_front',
		'misc-heartbeat_front_ttl',
		'misc-heartbeat_back',
		'misc-heartbeat_back_ttl',
		'misc-heartbeat_editor',
		'misc-heartbeat_editor_ttl',
		'object',
		'object-kind',
		'object-host',
		'object-port',
		'object-db_id',
	);
}

/**
 * The live configuration: the database, with the wp-config constants on top.
 *
 * @return array|null
 */
function wpoptimizing_import_src_litespeed_read() {
	$missing = new stdClass();
	$consts  = defined( 'LITESPEED_CONF' ) && LITESPEED_CONF;
	$raw     = array();
	foreach ( wpoptimizing_import_src_litespeed_ids() as $id ) {
		$const = 'LITESPEED_CONF__' . strtoupper( str_replace( '-', '__', $id ) );
		if ( $consts && defined( $const ) ) {
			$value = constant( $const );
		} else {
			$value = get_option( 'litespeed.conf.' . $id, $missing );
			if ( $missing === $value ) {
				continue;
			}
		}
		if ( is_string( $value ) && '' !== $value && in_array( $value[0], array( '[', '{' ), true ) ) {
			$decoded = json_decode( $value, true );
			$value   = is_array( $decoded ) ? $decoded : $value;
		}
		$raw[ $id ] = $value;
	}
	if ( empty( $raw ) ) {
		return null;
	}
	// The same test LiteSpeed Cache makes (litespeed-cache.php:95-108).
	$software       = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
	$edition        = isset( $_SERVER['LSWS_EDITION'] ) ? sanitize_text_field( wp_unslash( $_SERVER['LSWS_EDITION'] ) ) : '';
	$raw['_server'] = isset( $_SERVER['HTTP_X_LSCACHE'] ) || 0 === stripos( $software, 'LiteSpeed' ) || 0 === stripos( $edition, 'Openlitespeed' )
		|| ( defined( 'LITESPEED_SERVER_TYPE' ) && 'NONE' !== LITESPEED_SERVER_TYPE );
	return $raw;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r Setting id => value, plus `_server` (true on a LiteSpeed web server).
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_litespeed_map( array $r ) {
	$c     = array();
	$notes = array();
	$on    = static function ( $key ) use ( $r ) {
		return ! empty( $r[ $key ] ) && 'false' !== $r[ $key ];
	};
	// Lists come as JSON arrays or, in old installs, as one entry per line.
	$list = static function ( $key ) use ( $r ) {
		$value = $r[ $key ] ?? array();
		$value = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_filter( (array) $value, 'is_scalar' ) ), 'strlen' ) );
	};

	// Page cache.
	// On a LiteSpeed web server its page cache answers before WordPress loads. The settings come
	// over all the same (whoever imports is usually moving away from it), and importing never
	// switches the page cache on: the note says to switch LiteSpeed's off first.
	if ( ! empty( $r['_server'] ) ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'This site runs on a LiteSpeed web server, which serves LiteSpeed Cache\'s page cache before WordPress even loads. Its page cache settings were imported, but the WPOptimizing page cache stays off: switch LiteSpeed Cache\'s page cache off before you turn it on.', 'wpoptimizing' ) );
	}
	if ( isset( $r['cache-ttl_pub'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $r['cache-ttl_pub'], $notes ), __( 'Default Public Cache TTL', 'wpoptimizing' ) );
	}
	$uri = wpoptimizing_importer_patterns( $list( 'cache-exc' ), __( 'Do Not Cache URIs', 'wpoptimizing' ), $notes );
	if ( $uri ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Do Not Cache URIs', 'wpoptimizing' ) );
	}
	$cookies = wpoptimizing_importer_patterns( $list( 'cache-exc_cookies' ), __( 'Do Not Cache Cookies', 'wpoptimizing' ), $notes );
	if ( $cookies ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $cookies, __( 'Do Not Cache Cookies', 'wpoptimizing' ) );
	}
	if ( isset( $r['cache-mobile'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'cache-mobile' ), __( 'Cache Mobile', 'wpoptimizing' ) );
	}
	if ( isset( $r['crawler'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( 'crawler' ), __( 'Crawler', 'wpoptimizing' ) );
	}
	if ( $on( 'cache' ) && $on( 'cache-priv' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Cache Logged-in Users', 'wpoptimizing' ) )
		);
	}
	$ua = wpoptimizing_importer_patterns( $list( 'cache-exc_useragents' ), __( 'Do Not Cache User Agents', 'wpoptimizing' ), $notes );
	if ( $ua ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'Do Not Cache User Agents', 'wpoptimizing' ) );
	}
	// «Drop Query String» admits a trailing * (utm*). Its defaults (data/const.default.json:25)
	// are tracking parameters our engine already ignores, so only what was added comes over.
	// The defaults are compared as written, before the pattern helper drops the trailing *.
	$drop = array_diff( $list( 'cache-drop_qs' ), array( 'fbclid', 'gclid', 'utm*', '_ga' ) );
	$drop = wpoptimizing_importer_patterns( $drop, __( 'Drop Query String', 'wpoptimizing' ), $notes );
	if ( $drop ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ignored_query', $drop, __( 'Drop Query String', 'wpoptimizing' ) );
	}
	if ( $on( 'guest' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing has no equivalent.', 'wpoptimizing' ), __( 'Guest Mode', 'wpoptimizing' ) )
		);
	}

	// CSS and JavaScript.
	foreach ( array(
		'optm-css_min' => array( 'minify_css', __( 'CSS Minify', 'wpoptimizing' ) ),
		'optm-js_min'  => array( 'minify_js', __( 'JS Minify', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $to[0], $on( $key ), $to[1] );
		}
	}
	$exclude = array_values( array_unique( array_merge( $list( 'optm-css_exc' ), $list( 'optm-js_exc' ) ) ) );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', $exclude, __( 'CSS Excludes / JS Excludes', 'wpoptimizing' ) );
	}
	// optm-js_defer is three-state: 0 off, 1 defer, 2 delay (src/media.cls.php:881).
	if ( isset( $r['optm-js_defer'] ) ) {
		$mode = (int) $r['optm-js_defer'];
		$c[]  = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', 1 === $mode, __( 'Load JS Deferred', 'wpoptimizing' ) );
		$c[]  = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', 2 === $mode, __( 'Load JS Deferred', 'wpoptimizing' ) );
		if ( 1 === $mode && $list( 'optm-js_defer_exc' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', $list( 'optm-js_defer_exc' ), __( 'JS Deferred / Delayed Excludes', 'wpoptimizing' ) );
		}
		if ( 2 === $mode ) {
			// «Delayed» there delays every script but the excluded ones: our «all» profile.
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'profile', 'all', __( 'Load JS Deferred', 'wpoptimizing' ) );
			if ( $list( 'optm-js_defer_exc' ) ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', $list( 'optm-js_defer_exc' ), __( 'JS Deferred / Delayed Excludes', 'wpoptimizing' ) );
			}
			if ( $list( 'optm-js_delay_inc' ) ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'include', $list( 'optm-js_delay_inc' ), __( 'JS Delayed Includes', 'wpoptimizing' ) );
			}
		}
	}
	if ( isset( $r['optm-css_async'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'async_css', $on( 'optm-css_async' ), __( 'Load CSS Asynchronously', 'wpoptimizing' ) );
	}
	if ( isset( $r['optm-ccss_con'] ) && is_string( $r['optm-ccss_con'] ) && '' !== trim( $r['optm-ccss_con'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'critical_css', (string) $r['optm-ccss_con'], __( 'Critical CSS Rules', 'wpoptimizing' ) );
	} elseif ( $on( 'optm-css_async' ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Load CSS Asynchronously: LiteSpeed Cache gets its critical CSS from QUIC.cloud, which is not imported. WPOptimizing only loads CSS asynchronously on pages that have their own critical CSS, so generate it in WPOptimizing for this setting to take effect.', 'wpoptimizing' ) );
	}
	foreach ( array(
		'optm-css_comb' => __( 'CSS Combine', 'wpoptimizing' ),
		'optm-js_comb'  => __( 'JS Combine', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( $key ) ) {
			$notes[] = wpoptimizing_importer_note(
				'skipped',
				/* translators: %s: the other plugin's setting. */
				sprintf( __( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ), $label )
			);
		}
	}
	// The page cache minifies the HTML of the copies it stores (html-minify.php).
	if ( $on( 'optm-html_min' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'HTML Minify', 'wpoptimizing' ) );
	}
	if ( $on( 'optm-ucss' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; it is an online service of the other plugin. WPOptimizing generates critical CSS on your own server instead.', 'wpoptimizing' ), __( 'Generate UCSS', 'wpoptimizing' ) )
		);
	}

	// Media, fonts and hints.
	if ( isset( $r['media-lazy'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( 'media-lazy' ), __( 'Lazy Load Images', 'wpoptimizing' ) );
	}
	if ( isset( $r['media-iframe_lazy'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', $on( 'media-iframe_lazy' ), __( 'Lazy Load Iframes', 'wpoptimizing' ) );
	}
	// Fragments of the image URL and of its class, as ours (src/media.cls.php:1159-1172).
	if ( $on( 'media-lazy' ) ) {
		$exclude = array_values( array_unique( array_merge( wpoptimizing_importer_lazy_exclusions( $list( 'media-lazy_exc' ), __( 'Lazy Load Image Excludes', 'wpoptimizing' ), $notes ), wpoptimizing_importer_lazy_exclusions( $list( 'media-lazy_cls_exc' ), __( 'Lazy Load Image Class Name Excludes', 'wpoptimizing' ), $notes ) ) ) );
		if ( $exclude ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_exclude', $exclude, __( 'Lazy Load Image Excludes', 'wpoptimizing' ) );
		}
		foreach ( array(
			'media-lazy_parent_cls_exc' => __( 'Lazy Load Image Parent Class Name Excludes', 'wpoptimizing' ),
			'media-lazy_uri_exc'        => __( 'Lazy Load URI Excludes', 'wpoptimizing' ),
		) as $key => $label ) {
			if ( $list( $key ) ) {
				$notes[] = wpoptimizing_importer_note(
					'skipped',
					/* translators: %s: the other plugin's setting. */
					sprintf( __( '%s: not imported; WPOptimizing excludes images by their own class or file name only.', 'wpoptimizing' ), $label )
				);
			}
		}
	}
	if ( isset( $r['img_optm-webp'] ) && (int) $r['img_optm-webp'] > 0 ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_image_settings', 'serve_nextgen', true, __( 'Next-Gen Image Format', 'wpoptimizing' ) );
	}
	if ( isset( $r['optm-emoji_rm'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( 'optm-emoji_rm' ), __( 'Remove WordPress Emoji', 'wpoptimizing' ) );
	}
	if ( isset( $r['optm-ggfonts_async'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', $on( 'optm-ggfonts_async' ), __( 'Load Google Fonts Asynchronously', 'wpoptimizing' ) );
		if ( $on( 'optm-ggfonts_async' ) ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				/* translators: %s: the other plugin's setting. */
				sprintf( __( '%s: WPOptimizing does not load Google Fonts asynchronously; it serves them from your own server instead, which is faster and sends nothing to Google.', 'wpoptimizing' ), __( 'Load Google Fonts Asynchronously', 'wpoptimizing' ) )
			);
		}
	}
	if ( $on( 'optm-ggfonts_rm' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing has no equivalent.', 'wpoptimizing' ), __( 'Remove Google Fonts', 'wpoptimizing' ) )
		);
	}
	if ( $list( 'optm-dns_prefetch' ) || $list( 'optm-dns_preconnect' ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'DNS Prefetch / DNS Preconnect', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ), __( 'DNS Prefetch / DNS Preconnect', 'wpoptimizing' ) )
		);
	}
	if ( isset( $r['util-instant_click'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'speculation', $on( 'util-instant_click' ) ? 'conservative' : 'off', __( 'Instant Click', 'wpoptimizing' ) );
	}

	// CDN: one host here. cdn-mapping is a list of rows {url, inc_img, inc_css, inc_js, filetype}.
	// A row sends only what it ticks (images, CSS, JS) plus its file types (src/cdn.cls.php:
	// 193-245), from the listed folders (content and includes by default, base.cls.php:866-869),
	// except the exclusions. Only a first row with images, CSS and JS ticked, folders ours has a
	// scope for and no exclusion can be kept; otherwise it is not switched on.
	if ( $on( 'cdn' ) ) {
		$hosts = array();
		$every = false;
		foreach ( (array) ( $r['cdn-mapping'] ?? array() ) as $row ) {
			if ( is_array( $row ) && isset( $row['url'] ) && is_scalar( $row['url'] ) && '' !== trim( (string) $row['url'] ) ) {
				if ( ! $hosts ) {
					$every = ! empty( $row['inc_img'] ) && ! empty( $row['inc_css'] ) && ! empty( $row['inc_js'] );
				}
				$hosts[] = trim( (string) $row['url'] );
			}
		}
		$dirs = $list( 'cdn-ori_dir' );
		$dirs = wpoptimizing_importer_cdn_dirs( $dirs ? $dirs : array( 'wp-content', 'wp-includes' ) );
		if ( $hosts && ( ! $every || null === $dirs || $list( 'cdn-exc' ) ) ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'Use CDN Mapping', 'wpoptimizing' ), $hosts[0] );
		} elseif ( $hosts ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'Use CDN Mapping', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', $hosts[0], __( 'CDN URL', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'dirs', $dirs, __( 'Original Directories', 'wpoptimizing' ) );
			if ( count( $hosts ) > 1 ) {
				$notes[] = wpoptimizing_importer_note(
					'review',
					/* translators: %s: the other plugin's setting. */
					sprintf( __( '%s: WPOptimizing uses one CDN host, so only the first one was imported.', 'wpoptimizing' ), __( 'CDN URL', 'wpoptimizing' ) )
				);
			}
		}
	}
	if ( $on( 'cdn-cloudflare' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: the other plugin connects to Cloudflare with your account\'s credentials, which WPOptimizing does not copy. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ), __( 'Cloudflare API', 'wpoptimizing' ) )
		);
	}

	// Database: 0 means «no limit» there, so only a real limit is brought over.
	if ( isset( $r['db_optm-revisions_max'] ) && (int) $r['db_optm-revisions_max'] > 0 ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', 'keep_revisions', (int) $r['db_optm-revisions_max'], __( 'Revisions Max Number', 'wpoptimizing' ) );
	}
	if ( isset( $r['db_optm-revisions_age'] ) && (int) $r['db_optm-revisions_age'] > 0 ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', 'revisions_max_age_days', (int) $r['db_optm-revisions_age'], __( 'Revisions Max Age', 'wpoptimizing' ) );
	}

	// Heartbeat: the same three zones, each with its switch and its interval; an interval of 0
	// removes the script (src/tool.cls.php:92-101). Unset intervals are its defaults: 60 s, and
	// 15 s in the editor (data/const.default.json).
	$zones = array();
	foreach ( array(
		'admin'  => array( 'back', 60 ),
		'editor' => array( 'editor', 15 ),
		'front'  => array( 'front', 60 ),
	) as $zone => $from ) {
		if ( $on( 'misc-heartbeat_' . $from[0] ) ) {
			$ttl            = isset( $r[ 'misc-heartbeat_' . $from[0] . '_ttl' ] ) && is_scalar( $r[ 'misc-heartbeat_' . $from[0] . '_ttl' ] ) ? (int) $r[ 'misc-heartbeat_' . $from[0] . '_ttl' ] : $from[1];
			$zones[ $zone ] = $ttl > 0 ? $ttl : 'off';
		}
	}
	if ( $zones ) {
		$c = array_merge( $c, wpoptimizing_importer_heartbeat( $zones, __( 'Heartbeat Control', 'wpoptimizing' ), $notes ) );
	}

	// Object cache: the connection only. The drop-in is installed from our screen, never by an import.
	if ( $on( 'object' ) ) {
		$redis = $on( 'object-kind' ); // true Redis, false Memcached (src/object-cache.cls.php:153).
		$host  = isset( $r['object-host'] ) && is_scalar( $r['object-host'] ) ? trim( (string) $r['object-host'] ) : '';
		$c[]   = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', 'backend', $redis ? 'redis' : 'memcached', __( 'Object Cache', 'wpoptimizing' ) );
		if ( '' !== $host ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', $redis ? 'redis_host' : 'memcached_host', 'localhost' === $host ? '127.0.0.1' : $host, __( 'Host', 'wpoptimizing' ) );
		}
		if ( isset( $r['object-port'] ) && (int) $r['object-port'] > 0 ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', $redis ? 'redis_port' : 'memcached_port', (int) $r['object-port'], __( 'Port', 'wpoptimizing' ) );
		}
		if ( $redis && isset( $r['object-db_id'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', 'redis_database', (int) $r['object-db_id'], __( 'Redis Database ID', 'wpoptimizing' ) );
		}
		$notes[] = wpoptimizing_importer_note(
			'review',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: the connection was imported, but not switched on and without any password. If the server needs one, type it again in Cache › Object cache.', 'wpoptimizing' ), __( 'Object Cache', 'wpoptimizing' ) )
		);
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
