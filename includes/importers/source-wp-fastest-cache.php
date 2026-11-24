<?php
/**
 * Import source: WP Fastest Cache (and its Premium add-on).
 *
 * The settings screen is stored as it was posted: the option `WpFastestCache` is
 * json_encode( $_POST ) (inc/admin.php:255-290), so a ticked box is the key with "on" and an
 * unticked one is no key at all. Exclusions are `WpFastestCacheExclude` (JSON list of
 * {prefix, content, type}, wpFastestCache.php:795-823), CDNs `WpFastestCacheCDN`
 * (inc/cdn.php), and the cache timeout is not an option but WP-Cron events
 * `wp_fastest_cache_<n>` with a JSON argument {prefix, content[, hour, minute]}
 * (wpFastestCache.php:910-960). Premium's database cleanup is the event `wpfc_db_auto_cleanup`
 * (pro/library/db.php). Keys read in WP Fastest Cache 1.5.5 and Premium 1.7.8
 * (docs/cache-2026-11-06/importadores-A.md §5).
 *
 * An absent key means «off» as much as «never looked at», so only what was switched ON comes
 * over: importing every unticked box as «off» would switch off what WPOptimizing already does.
 * Premium switches act only with the add-on installed, so without it they are not imported.
 *
 * Never read: the Cloudflare entry's e-mail and key (`cdnurl`, `originurl`), the image
 * optimisation API key.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Seconds of the recurrences WP Fastest Cache registers (wpFastestCache.php:2394-2520), for when
 * it is deactivated and WordPress no longer knows them.
 *
 * @return array<string,int>
 */
function wpoptimizing_import_src_wp_fastest_cache_intervals() {
	return array(
		'everyminute'        => MINUTE_IN_SECONDS,
		'everyfiveminute'    => 5 * MINUTE_IN_SECONDS,
		'everyfifteenminute' => 15 * MINUTE_IN_SECONDS,
		'twiceanhour'        => 30 * MINUTE_IN_SECONDS,
		'onceanhour'         => HOUR_IN_SECONDS,
		'everytwohours'      => 2 * HOUR_IN_SECONDS,
		'everythreehours'    => 3 * HOUR_IN_SECONDS,
		'everyfourhours'     => 4 * HOUR_IN_SECONDS,
		'everyfivehours'     => 5 * HOUR_IN_SECONDS,
		'everysixhours'      => 6 * HOUR_IN_SECONDS,
		'everysevenhours'    => 7 * HOUR_IN_SECONDS,
		'everyeighthours'    => 8 * HOUR_IN_SECONDS,
		'everyninehours'     => 9 * HOUR_IN_SECONDS,
		'everytenhours'      => 10 * HOUR_IN_SECONDS,
		'onceaday'           => DAY_IN_SECONDS,
		'everythreedays'     => 3 * DAY_IN_SECONDS,
		'everysevendays'     => 7 * DAY_IN_SECONDS,
		'everytendays'       => 10 * DAY_IN_SECONDS,
		'everyfifteendays'   => 15 * DAY_IN_SECONDS,
		'montly'             => 30 * DAY_IN_SECONDS,
		'yearly'             => 360 * DAY_IN_SECONDS,
	);
}

/**
 * An option that WP Fastest Cache stores as a JSON string, decoded.
 *
 * @param string $name Option.
 * @return array
 */
function wpoptimizing_import_src_wp_fastest_cache_json( $name ) {
	$value = get_option( $name );
	if ( is_string( $value ) ) {
		$value = json_decode( $value, true );
	}
	return is_array( $value ) ? $value : array();
}

/**
 * The live configuration.
 *
 * @return array|null
 */
function wpoptimizing_import_src_wp_fastest_cache_read() {
	$raw = array(
		'options' => wpoptimizing_import_src_wp_fastest_cache_json( 'WpFastestCache' ),
		'exclude' => wpoptimizing_import_src_wp_fastest_cache_json( 'WpFastestCacheExclude' ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- WP Fastest Cache's exclusion rules, not a query argument.
		'cdn'     => array(),
		'timeout' => array(),
	);
	foreach ( wpoptimizing_import_src_wp_fastest_cache_json( 'WpFastestCacheCDN' ) as $cdn ) {
		if ( ! is_array( $cdn ) ) {
			continue;
		}
		// Cloudflare keeps the account e-mail in cdnurl and the key in originurl: left behind.
		$raw['cdn'][] = 'cloudflare' === ( $cdn['id'] ?? '' )
			? array( 'id' => 'cloudflare' )
			: array_intersect_key( $cdn, array_flip( array( 'id', 'cdnurl', 'file_types', 'keywords', 'excludekeywords', 'status' ) ) );
	}
	if ( empty( $raw['options'] ) && empty( $raw['exclude'] ) && empty( $raw['cdn'] ) ) {
		return null;
	}
	if ( function_exists( '_get_cron_array' ) ) {
		$intervals = wpoptimizing_import_src_wp_fastest_cache_intervals();
		foreach ( ( function_exists( 'wp_get_schedules' ) ? wp_get_schedules() : array() ) as $name => $schedule ) {
			$intervals[ $name ] = (int) ( $schedule['interval'] ?? 0 );
		}
		foreach ( (array) _get_cron_array() as $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( ! preg_match( '/^wp_fastest_cache(_\d+)?$/', (string) $hook ) ) {
					continue;
				}
				foreach ( (array) $events as $event ) {
					$args = json_decode( (string) ( $event['args'][0] ?? '' ), true );
					if ( is_array( $args ) ) {
						$args['interval'] = (int) ( $intervals[ $event['schedule'] ?? '' ] ?? 0 );
						$raw['timeout'][] = $args;
					}
				}
			}
		}
		$raw['db_cleanup'] = function_exists( 'wp_get_schedule' ) ? (string) wp_get_schedule( 'wpfc_db_auto_cleanup' ) : '';
	}
	$varnish        = get_option( 'WpFastestCacheVarnish' );
	$raw['varnish'] = is_array( $varnish ) && 'pause' !== ( $varnish['status'] ?? '' ) && ! empty( $varnish['server'] );
	$raw['premium'] = class_exists( 'WpFastestCachePowerfulHtml' ) || is_dir( WP_PLUGIN_DIR . '/wp-fastest-cache-premium' );
	return $raw;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $raw `options` (WpFastestCache), `exclude`, `cdn`, `timeout` (cron rules with
 *                   their `interval` in seconds), `db_cleanup` (daily|weekly|''), `varnish`,
 *                   `premium`.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_wp_fastest_cache_map( array $raw ) {
	$c       = array();
	$notes   = array();
	$o       = is_array( $raw['options'] ?? null ) ? $raw['options'] : array();
	$premium = ! empty( $raw['premium'] );
	// The plugin itself tests these with isset() (inc/cache.php).
	$on = static function ( $key ) use ( $o ) {
		return isset( $o[ $key ] ) && '' !== $o[ $key ];
	};

	// Page cache.
	$cache = $on( 'wpFastestCacheStatus' );
	$ttl   = null;
	$other = false;
	foreach ( (array) ( $raw['timeout'] ?? array() ) as $rule ) {
		if ( is_array( $rule ) && 'all' === ( $rule['prefix'] ?? '' ) && empty( $rule['hour'] ) && (int) ( $rule['interval'] ?? 0 ) > 0 ) {
			$ttl = (int) $rule['interval'];
		} elseif ( is_array( $rule ) ) {
			$other = true;
		}
	}
	if ( $other ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Timeout Rules: a rule for some pages only, or at a fixed time of day, was not imported. WPOptimizing has one lifetime for every page.', 'wpoptimizing' ) );
	}
	if ( null !== $ttl || $cache ) {
		// No rule: its pages never expire, which is not safe either.
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $ttl, $notes ), __( 'Timeout Rules', 'wpoptimizing' ) );
	}
	if ( $cache ) {
		// «Mobile» means NOT serving the desktop copy to phones; only with Premium's «Mobile
		// Theme» does it keep a mobile copy (inc/cache.php:131, :1277).
		$mobile = $on( 'wpFastestCacheMobile' );
		$c[]    = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $mobile, __( 'Mobile', 'wpoptimizing' ) );
		if ( $mobile && ! ( $premium && $on( 'wpFastestCacheMobileTheme' ) ) ) {
			$notes[] = wpoptimizing_importer_note( 'review', __( 'Mobile: WP Fastest Cache did not cache pages for phones at all; WPOptimizing caches them, in a copy of their own.', 'wpoptimizing' ) );
		}
		if ( ! $on( 'wpFastestCacheLoggedInUser' ) ) {
			$notes[] = wpoptimizing_importer_note(
				'skipped',
				/* translators: %s: the other plugin's setting. */
				sprintf( __( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Logged-in Users', 'wpoptimizing' ) )
			);
		}
	}
	if ( $on( 'wpFastestCachePreload' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', true, __( 'Preload', 'wpoptimizing' ) );
	}
	if ( $on( 'wpFastestCacheGzip' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'gzip', true, __( 'Gzip', 'wpoptimizing' ) );
	}
	if ( $on( 'wpFastestCacheWidgetCache' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing has no equivalent.', 'wpoptimizing' ), __( 'Widget Cache', 'wpoptimizing' ) )
		);
	}

	// Exclusions, by type. Their page rules are «exact», «startwith», «contain» or «regex»;
	// ours compare a fragment, which covers the first three (more widely for «exact»).
	$by       = array(
		'page'      => array(),
		'cookie'    => array(),
		'useragent' => array(),
		'asset'     => array(),
	);
	$plain    = $by;
	$tracking = false;
	$types    = false;
	foreach ( (array) ( $raw['exclude'] ?? array() ) as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}
		$type    = (string) ( $rule['type'] ?? 'page' );
		$prefix  = (string) ( $rule['prefix'] ?? '' );
		$content = trim( (string) ( $rule['content'] ?? '' ) );
		if ( in_array( $prefix, array( 'googleanalytics', 'googleads', 'facebookclickid', 'yandexclickid' ), true ) ) {
			$tracking = true;
		} elseif ( 'page' === $type && in_array( $prefix, array( 'homepage', 'category', 'tag', 'post', 'page', 'archive', 'attachment' ), true ) ) {
			$types = true;
		} elseif ( '' === $content ) {
			continue;
		} elseif ( in_array( $type, array( 'css', 'js' ), true ) ) {
			$by['asset'][] = $content;
		} elseif ( isset( $by[ $type ] ) && 'regex' === $prefix ) {
			$by[ $type ][] = $content;
		} elseif ( isset( $by[ $type ] ) ) {
			// Already plain text: WP Fastest Cache quotes it before matching (inc/cache.php:723-727).
			$plain[ $type ][] = ltrim( $content, '/' );
		}
	}
	foreach ( array(
		'page'      => array( 'reject_uri', __( 'Exclude Pages', 'wpoptimizing' ) ),
		'cookie'    => array( 'reject_cookies', __( 'Exclude Cookies', 'wpoptimizing' ) ),
		'useragent' => array( 'reject_ua', __( 'Exclude User-Agents', 'wpoptimizing' ) ),
	) as $type => $to ) {
		$list = array_values( array_unique( array_merge( $plain[ $type ], wpoptimizing_importer_patterns( $by[ $type ], $to[1], $notes ) ) ) );
		if ( $list ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', $to[0], $list, $to[1] );
		}
	}
	if ( $by['asset'] ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $by['asset'] ) ), __( 'Exclude CSS / Exclude JS', 'wpoptimizing' ) );
	}
	if ( $tracking ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'Exclude Pages (Google Analytics, Google Ads, Facebook and Yandex click IDs): not imported on purpose. WPOptimizing ignores those parameters and serves the cached page, which is faster and does not affect tracking.', 'wpoptimizing' ) );
	}
	if ( $types ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Exclude Pages (home page, categories, tags, posts, pages, archives, attachments): not imported; WPOptimizing cannot leave out whole page types. Add their addresses to the URLs that are never cached if you need to.', 'wpoptimizing' ) );
	}

	// CSS, JavaScript, lazy load, fonts and the CDN are applied to the page while it is cached
	// (inc/cache.php:872-1092), and that only happens with the cache switched on (:334): with it
	// off, their ticked boxes did nothing.
	if ( $cache && ( $on( 'wpFastestCacheMinifyCss' ) || ( $premium && $on( 'wpFastestCacheMinifyCssPowerFul' ) ) ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_css', true, __( 'Minify Css', 'wpoptimizing' ) );
	}
	$pro  = array(
		'wpFastestCacheMinifyJs'       => array( 'wpoptimizing_asset_optimize', 'minify_js', __( 'Minify Js', 'wpoptimizing' ) ),
		'wpFastestCacheRenderBlocking' => array( 'wpoptimizing_asset_optimize', 'defer_js', __( 'Render Blocking Js', 'wpoptimizing' ) ),
		'wpFastestCacheDelayJS'        => array( 'wpoptimizing_delay_js', 'enabled', __( 'Delay Js', 'wpoptimizing' ) ),
		'wpFastestCacheLazyLoad'       => array( 'wpoptimizing_frontend_perf', 'image_opt', __( 'Lazy Load', 'wpoptimizing' ) ),
		'wpFastestCacheGoogleFonts'    => array( 'wpoptimizing_frontend_perf', 'google_fonts', __( 'Google Fonts', 'wpoptimizing' ) ),
	);
	$idle = array();
	foreach ( $pro as $key => $to ) {
		if ( ! $cache || ! $on( $key ) ) {
			continue;
		}
		if ( ! $premium ) {
			$idle[] = $to[2];
			continue;
		}
		$c[] = wpoptimizing_importer_change( $to[0], $to[1], true, $to[2] );
	}
	if ( $idle ) {
		$notes[] = wpoptimizing_importer_note(
			'info',
			sprintf(
				/* translators: %s: list of settings. */
				__( '%s: they need WP Fastest Cache Premium, which is not installed here, so they were not acting and were not imported.', 'wpoptimizing' ),
				implode( ', ', $idle )
			)
		);
	}
	if ( $cache && $premium && $on( 'wpFastestCacheGoogleFonts' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'review',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: WPOptimizing does not load Google Fonts asynchronously; it serves them from your own server instead, which is faster and sends nothing to Google.', 'wpoptimizing' ), __( 'Google Fonts', 'wpoptimizing' ) )
		);
	}
	if ( $cache && $premium && $on( 'wpFastestCacheLazyLoad' ) && '' !== trim( (string) ( $o['wpFastestCacheLazyLoad_keywords'] ?? '' ) ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing has no exclusion list for lazy loading.', 'wpoptimizing' ), __( 'Lazy Load Exclude Sources', 'wpoptimizing' ) )
		);
	}
	if ( $cache && $premium && $on( 'wpFastestCacheRenderBlocking' ) && $on( 'wpFastestCacheRenderBlockingCss' ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Render Blocking Js › CSS: not imported. If you want it, switch on «Load CSS asynchronously» in WPOptimizing once its critical CSS is generated.', 'wpoptimizing' ) );
	}
	foreach ( array(
		'wpFastestCacheCombineCss'        => __( 'Combine Css', 'wpoptimizing' ),
		'wpFastestCacheCombineJs'         => __( 'Combine Js', 'wpoptimizing' ),
		'wpFastestCacheCombineJsPowerFul' => __( 'Combine Js Plus', 'wpoptimizing' ),
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
	if ( $on( 'wpFastestCacheMinifyHtml' ) || $on( 'wpFastestCacheMinifyHtmlPowerFul' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'Minify HTML', 'wpoptimizing' ) );
	}
	if ( $on( 'wpFastestCacheDisableEmojis' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', true, __( 'Disable Emojis', 'wpoptimizing' ) );
	}

	// CDN: one host here. A paused CDN is not brought over.
	// A CDN there takes only the file types ticked for it (all of them by default,
	// templates/cdn/file_types.php) and the keywords chosen (wpFastestCache.php:2666-2695).
	$hosts = array();
	$cf    = false;
	$extra = false;
	$every = array( 'aac', 'avif', 'css', 'eot', 'gif', 'jpeg', 'js', 'jpg', 'less', 'mp3', 'mp4', 'ogg', 'otf', 'pdf', 'png', 'svg', 'swf', 'ttf', 'webm', 'webp', 'woff', 'woff2' );
	foreach ( (array) ( $raw['cdn'] ?? array() ) as $cdn ) {
		if ( ! is_array( $cdn ) ) {
			continue;
		}
		if ( 'cloudflare' === ( $cdn['id'] ?? '' ) ) {
			$cf = true;
		} elseif ( $cache && 'pause' !== ( $cdn['status'] ?? '' ) && is_scalar( $cdn['cdnurl'] ?? null ) && '' !== trim( (string) $cdn['cdnurl'] ) ) {
			$hosts[] = trim( (string) $cdn['cdnurl'] );
			$types   = array_map( 'strtolower', array_map( 'trim', explode( ',', is_scalar( $cdn['file_types'] ?? null ) ? (string) $cdn['file_types'] : '' ) ) );
			$extra   = $extra || array_diff( $every, $types ) || '' !== trim( (string) ( $cdn['keywords'] ?? '' ) ) || '' !== trim( (string) ( $cdn['excludekeywords'] ?? '' ) );
		}
	}
	if ( $hosts && $extra ) {
		$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'CDN', 'wpoptimizing' ), $hosts[0] );
	} elseif ( $hosts ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'CDN', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', $hosts[0], __( 'CDN Url', 'wpoptimizing' ) );
		if ( count( $hosts ) > 1 ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				/* translators: %s: the other plugin's setting. */
				sprintf( __( '%s: WPOptimizing uses one CDN host, so only the first one was imported.', 'wpoptimizing' ), __( 'CDN Url', 'wpoptimizing' ) )
			);
		}
	}
	if ( $cf ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: the other plugin connects to Cloudflare with your account\'s credentials, which WPOptimizing does not copy. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ), 'Cloudflare' )
		);
	}
	if ( ! empty( $raw['varnish'] ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Varnish: switch on its purge in Cache › Browser & server once WPOptimizing has checked that Varnish is in front of the site.', 'wpoptimizing' ) );
	}

	// Database: Premium's automatic cleanup runs its «all warnings» set (pro/library/db.php:40-51).
	$schedule = (string) ( $raw['db_cleanup'] ?? '' );
	if ( in_array( $schedule, array( 'daily', 'weekly' ), true ) ) {
		// That set and nothing else: no auto-drafts, no table optimisation, so every other task
		// here is left off. It deletes revisions, so their task keeps running.
		$label    = __( 'Database Cleanup › Auto Cleanup', 'wpoptimizing' );
		$selected = array( 'revisions' => $label );
		foreach ( array( 'delete_trashed_posts', 'delete_spam_comments', 'delete_trashed_comments', 'delete_pingbacks', 'delete_trackbacks', 'delete_expired_transients', 'delete_orphan_elements' ) as $task ) {
			$selected[ $task ] = $label;
		}
		$c       = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, $label ) );
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', $schedule, $label );
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Database Cleanup: WP Fastest Cache deleted every revision and every transient. WPOptimizing keeps the last two revisions of each post and deletes only expired transients; you can change both in General cleanup.', 'wpoptimizing' ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
