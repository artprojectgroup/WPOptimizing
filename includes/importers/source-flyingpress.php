<?php
/**
 * Import source: FlyingPress.
 *
 * One flat option, `FLYING_PRESS_CONFIG` (per site), always complete: on every update it is
 * merged with the defaults, so every key is there and a present `false` really means off. The
 * copy embedded in advanced-cache.php is generated from it: never read. Keys read in
 * FlyingPress 5.6.5, src/Config.php (docs/cache-2026-11-06/importadores-B.md §10).
 *
 * Its defaults are aggressive (almost everything on), so a site used them even if nobody
 * touched a switch. «Delay JavaScript» there has three methods: «defer» only defers first-party
 * scripts (our «Load JavaScript deferred»), the other two really delay them.
 *
 * Never imported: the licence (license_key, license_*), the FlyingCDN API key and Cloudflare's
 * Global API Key, e-mail and zone (cf_*).
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
function wpoptimizing_import_src_flyingpress_read() {
	$raw = get_option( 'FLYING_PRESS_CONFIG' );
	return is_array( $raw ) && ! empty( $raw ) ? $raw : null;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r FLYING_PRESS_CONFIG.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_flyingpress_map( array $r ) {
	$c     = array();
	$notes = array();
	$none  = array();
	$on    = static function ( $key ) use ( $r ) {
		return ! empty( $r[ $key ] );
	};
	$list  = static function ( $key ) use ( $r ) {
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', array_filter( (array) ( $r[ $key ] ?? array() ), 'is_scalar' ) ) ), 'strlen' ) );
	};

	// Page cache. Its lifetime is a cron that refreshes everything; off means pages only go when
	// content changes, and ours keep their own lifetime.
	if ( $on( 'cache_refresh' ) ) {
		$schedules = array(
			'1min'       => MINUTE_IN_SECONDS,
			'hourly'     => HOUR_IN_SECONDS,
			'2hours'     => 2 * HOUR_IN_SECONDS,
			'6hours'     => 6 * HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
			'weekly'     => WEEK_IN_SECONDS,
			'monthly'    => 2635200,
		);
		$interval  = (string) ( $r['cache_refresh_interval'] ?? '' );
		if ( isset( $schedules[ $interval ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( $schedules[ $interval ], $notes ), __( 'Auto-refresh Cache', 'wpoptimizing' ) );
		}
	}
	if ( isset( $r['cache_mobile'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'cache_mobile' ), __( 'Separate Mobile Cache & Optimizations', 'wpoptimizing' ) );
	}
	// Plain keywords matched anywhere in the URL there, so no regular expression to undo.
	if ( $list( 'cache_bypass_urls' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $list( 'cache_bypass_urls' ), __( 'Exclude Pages from Cache', 'wpoptimizing' ) );
	}
	if ( $list( 'cache_bypass_cookies' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $list( 'cache_bypass_cookies' ), __( 'Bypass Cache for Cookies', 'wpoptimizing' ) );
	}
	if ( $list( 'cache_include_queries' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'query_whitelist', $list( 'cache_include_queries' ), __( 'Query Strings to Cache', 'wpoptimizing' ) );
	}
	if ( $on( 'cache_logged_in' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ),
				__( 'Cache for Logged-in Users by Role', 'wpoptimizing' )
			)
		);
	}
	if ( $on( 'cache_link_prefetch' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'speculation', 'conservative', __( 'Preload Links on Hover', 'wpoptimizing' ) );
	}

	// CSS and JavaScript.
	if ( isset( $r['css_js_minify'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_css', $on( 'css_js_minify' ), __( 'Minify CSS and JavaScript', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_js', $on( 'css_js_minify' ), __( 'Minify CSS and JavaScript', 'wpoptimizing' ) );
	}
	if ( $on( 'css_rucss' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: not imported. WPOptimizing generates critical CSS on your server instead of removing unused CSS.', 'wpoptimizing' ),
				__( 'Remove Unused CSS', 'wpoptimizing' )
			)
		);
	}
	$method   = (string) ( $r['js_delay_method'] ?? 'defer' );
	$delay_on = $on( 'js_delay' ) && 'defer' !== $method;
	if ( isset( $r['js_delay'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', $on( 'js_delay' ) && 'defer' === $method, __( 'Delay All JavaScript', 'wpoptimizing' ) );
		if ( $on( 'js_delay' ) && 'defer' === $method && $list( 'js_delay_excludes' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', $list( 'js_delay_excludes' ), __( 'Delay All JavaScript', 'wpoptimizing' ) );
		}
	}
	// Third-party and selected scripts only run when the method is not «user interaction».
	$third    = $on( 'js_delay_third_party' ) && 'user-interaction' !== $method;
	$selected = $on( 'js_delay_selected' ) && 'user-interaction' !== $method && $list( 'js_delay_selected_includes' );
	if ( $delay_on || $third || $selected ) {
		$exclude = $delay_on ? $list( 'js_delay_excludes' ) : array();
		if ( $third ) {
			$exclude = array_merge( $exclude, $list( 'js_delay_third_party_excludes' ) );
		}
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', true, __( 'Delay All JavaScript', 'wpoptimizing' ) );
		// Only the listed scripts («Delay Specific Scripts» alone) is our «custom» profile: «safe»
		// would add its own targets to the list.
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'profile', $delay_on ? 'all' : ( $third ? 'safe' : 'custom' ), __( 'Delay All JavaScript', 'wpoptimizing' ) );
		if ( $exclude ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', array_values( array_unique( $exclude ) ), __( 'Delay All JavaScript', 'wpoptimizing' ) );
		}
		if ( $selected && ! $delay_on ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'include', $list( 'js_delay_selected_includes' ), __( 'Delay Specific Scripts on Interaction', 'wpoptimizing' ) );
		}
		if ( $delay_on ) {
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
	} elseif ( isset( $r['js_delay'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', false, __( 'Delay All JavaScript', 'wpoptimizing' ) );
	}

	// Images and fonts.
	if ( isset( $r['lazy_load'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( 'lazy_load' ), __( 'Lazy Load Images, Videos, and Iframes', 'wpoptimizing' ) );
	}
	// Keywords matched anywhere in the tag (src/Optimizer/Image.php:100-123). It also gives the
	// excluded images high priority; here «not deferred» and «LCP» are separate, so it says so.
	if ( $on( 'lazy_load' ) && $list( 'lazy_load_exclusions' ) ) {
		$exclude = wpoptimizing_importer_lazy_exclusions( $list( 'lazy_load_exclusions' ), __( 'Media Exclusions', 'wpoptimizing' ), $notes );
		if ( $exclude ) {
			$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_exclude', $exclude, __( 'Media Exclusions', 'wpoptimizing' ) );
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: the excluded images are not deferred, but they no longer all get high fetch priority; WPOptimizing gives it only to the likely LCP image.', 'wpoptimizing' ),
					__( 'Media Exclusions', 'wpoptimizing' )
				)
			);
		}
	}
	if ( isset( $r['youtube_placeholder'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', $on( 'youtube_placeholder' ), __( 'Lightweight YouTube Previews', 'wpoptimizing' ) );
	}
	if ( isset( $r['fonts_optimize_google'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', $on( 'fonts_optimize_google' ), __( 'Self-host Google Fonts', 'wpoptimizing' ) );
	}
	if ( $on( 'image_auto_optimize_uploads' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Image optimization: FlyingPress compresses images on its own servers, so it was not imported. WPOptimizing converts images to WebP and AVIF on your server; set it up in Media.', 'wpoptimizing' ) );
	}
	foreach ( array(
		'lazy_render'                  => __( 'Lazy Render Elements', 'wpoptimizing' ),
		'properly_size_images'         => __( 'Properly size images', 'wpoptimizing' ),
		'self_host_gravatars'          => __( 'Self-host Gravatar Images', 'wpoptimizing' ),
		'css_js_self_host_third_party' => __( 'Self-host third-party CSS and JavaScript', 'wpoptimizing' ),
		'bloat_disable_block_css'      => __( 'Disable Block Editor CSS', 'wpoptimizing' ),
		'bloat_disable_xml_rpc'        => __( 'Disable XML-RPC', 'wpoptimizing' ),
		'bloat_disable_rss_feed'       => __( 'Disable RSS Feed', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( $key ) ) {
			$none[] = $label;
		}
	}

	// Bloat: the core tweaks.
	foreach ( array(
		'bloat_disable_emojis'         => array( 'emojis', __( 'Disable Emojis', 'wpoptimizing' ) ),
		'bloat_disable_dashicons'      => array( 'dashicons_frontend', __( 'Remove Dashicons', 'wpoptimizing' ) ),
		'bloat_disable_jquery_migrate' => array( 'jquery_migrate', __( 'Disable jQuery Migrate', 'wpoptimizing' ) ),
		'bloat_disable_oembeds'        => array( 'embeds', __( 'Disable oEmbeds', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', $to[0], $on( $key ), $to[1] );
		}
	}
	// Heartbeat: 60 s everywhere, editor included (src/Optimizer/Bloat.php:162-171).
	if ( $on( 'bloat_heartbeat_control' ) ) {
		$c = array_merge(
			$c,
			wpoptimizing_importer_heartbeat(
				array(
					'admin'  => 60,
					'editor' => 60,
					'front'  => 60,
				),
				__( 'Throttle Heartbeat API', 'wpoptimizing' ),
				$notes
			)
		);
	}
	if ( $on( 'bloat_post_revisions_control' ) ) {
		// It limits WordPress to three revisions per post.
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', 'keep_revisions', 3, __( 'Limit Post Revisions', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: the other plugin stops WordPress from keeping more revisions; WPOptimizing deletes the extra ones each time General cleanup runs.', 'wpoptimizing' ),
				__( 'Limit Post Revisions', 'wpoptimizing' )
			)
		);
	}

	// CDN: a host of your own, yes; FlyingCDN and Cloudflare are services with credentials.
	$cdn_type = (string) ( $r['cdn_type'] ?? 'custom' );
	if ( $on( 'cdn' ) && 'custom' === $cdn_type && is_string( $r['cdn_url'] ?? null ) && '' !== trim( $r['cdn_url'] ) ) {
		// «All» sends every static type; «CSS, JS & fonts» or «Images» only some (src/Optimizer/CDN.php:41-48).
		if ( 'all' !== (string) ( $r['cdn_file_types'] ?? 'all' ) ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'Enable CDN', 'wpoptimizing' ), trim( $r['cdn_url'] ) );
		} else {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'Enable CDN', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', trim( $r['cdn_url'] ), __( 'CDN URL', 'wpoptimizing' ) );
		}
	} elseif ( $on( 'cdn' ) && 'flying_cdn' === $cdn_type ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'FlyingCDN: FlyingPress\'s own paid CDN, not imported. If you keep it, enter its address as the CDN host in WPOptimizing.', 'wpoptimizing' ) );
	}
	if ( $on( 'cf_page_caching' ) || 'cloudflare' === $cdn_type || ( isset( $r['cf_status'] ) && 'not_setup' !== $r['cf_status'] ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: the other plugin connects with your Cloudflare account\'s Global API Key, which WPOptimizing does not copy. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ),
				'Cloudflare'
			)
		);
	}

	// Database: mapped one by one when any task is ticked.
	$db_tasks = array(
		'db_post_auto_drafts'   => array( 'delete_auto_drafts', __( 'Auto drafts', 'wpoptimizing' ) ),
		'db_post_trashed'       => array( 'delete_trashed_posts', __( 'Trashed posts', 'wpoptimizing' ) ),
		'db_comments_spam'      => array( 'delete_spam_comments', __( 'Spam comments', 'wpoptimizing' ) ),
		'db_comments_trashed'   => array( 'delete_trashed_comments', __( 'Trashed comments', 'wpoptimizing' ) ),
		'db_transients_expired' => array( 'delete_expired_transients', __( 'Expired transients', 'wpoptimizing' ) ),
		'db_optimize_tables'    => array( 'optimize_tables', __( 'Optimize tables', 'wpoptimizing' ) ),
	);
	$any_db   = $on( 'db_post_revisions' );
	foreach ( array_keys( $db_tasks ) as $key ) {
		$any_db = $any_db || $on( $key );
	}
	if ( $any_db ) {
		$scheduled = $on( 'db_auto_clean' ) && in_array( $r['db_auto_clean_interval'] ?? '', array( 'daily', 'weekly', 'monthly' ), true );
		if ( $scheduled ) {
			// A schedule runs exactly what was ticked there (src/Database.php:34-97): every other
			// task, revisions included, is left off.
			$selected = array();
			foreach ( $db_tasks as $key => $to ) {
				if ( $on( $key ) ) {
					$selected[ $to[0] ] = $to[1];
				}
			}
			if ( $on( 'db_post_revisions' ) ) {
				$selected['revisions'] = __( 'Delete Post Revisions', 'wpoptimizing' );
			}
			$c   = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, __( 'Automatic Cleaning', 'wpoptimizing' ) ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', (string) $r['db_auto_clean_interval'], __( 'Automatic Cleaning', 'wpoptimizing' ) );
		} else {
			foreach ( $db_tasks as $key => $to ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', $to[0], $on( $key ) ? 1 : 0, $to[1] );
			}
		}
		if ( $on( 'db_post_revisions' ) ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: the other plugin deletes every revision; WPOptimizing keeps the last ones of each post, as set in General cleanup.', 'wpoptimizing' ),
					__( 'Delete Post Revisions', 'wpoptimizing' )
				)
			);
		}
	}

	if ( $on( 'object_cache' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: not switched on by the import. Deactivate the other plugin first, then switch it on in Cache › Object cache.', 'wpoptimizing' ),
				__( 'Object Cache', 'wpoptimizing' )
			)
		);
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
