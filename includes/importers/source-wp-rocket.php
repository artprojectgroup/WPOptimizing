<?php
/**
 * Import source: WP Rocket.
 *
 * Its whole configuration is one option, `wp_rocket_settings` (per site), and its own export is
 * that same array as JSON. The files in wp-content/wp-rocket-config/ are generated from it:
 * never read. Keys read in WP Rocket 3.23 (docs/cache-2026-11-06/importadores-A.md §1).
 *
 * Never imported: licence (consumer_key, consumer_email, secret_key), Cloudflare's Global API
 * Key and e-mail (we use an API token), Sucuri, the random keys, and its cloud services
 * (Remove Unused CSS, RocketCDN).
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
function wpoptimizing_import_src_wp_rocket_read() {
	$raw = get_option( 'wp_rocket_settings' );
	return is_array( $raw ) && ! empty( $raw ) ? $raw : null;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r wp_rocket_settings.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_wp_rocket_map( array $r ) {
	$c     = array();
	$notes = array();
	$on    = static function ( $key ) use ( $r ) {
		return ! empty( $r[ $key ] );
	};
	$list  = static function ( $key ) use ( $r ) {
		return array_values( array_filter( (array) ( $r[ $key ] ?? array() ), 'is_scalar' ) );
	};

	// Page cache.
	if ( isset( $r['purge_cron_interval'] ) ) {
		$unit = ( isset( $r['purge_cron_unit'] ) && 'DAY_IN_SECONDS' === $r['purge_cron_unit'] ) ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
		$c[]  = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $r['purge_cron_interval'] * $unit, $notes ), __( 'Cache lifespan', 'wpoptimizing' ) );
	}
	$uri = wpoptimizing_importer_patterns( $list( 'cache_reject_uri' ), __( 'Never cache URL(s)', 'wpoptimizing' ), $notes );
	if ( $uri ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Never cache URL(s)', 'wpoptimizing' ) );
	}
	$cookies = wpoptimizing_importer_patterns( $list( 'cache_reject_cookies' ), __( 'Never cache cookies', 'wpoptimizing' ), $notes );
	if ( $cookies ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $cookies, __( 'Never cache cookies', 'wpoptimizing' ) );
	}
	if ( $list( 'cache_query_strings' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'query_whitelist', array_map( 'strval', $list( 'cache_query_strings' ) ), __( 'Cache query string(s)', 'wpoptimizing' ) );
	}
	if ( isset( $r['do_caching_mobile_files'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'cache_mobile' ) && $on( 'do_caching_mobile_files' ), __( 'Separate cache files for mobile devices', 'wpoptimizing' ) );
	}
	if ( isset( $r['manual_preload'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( 'manual_preload' ), __( 'Activate preloading', 'wpoptimizing' ) );
	}
	if ( $on( 'cache_logged_user' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Enable caching for logged-in WordPress users: WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ) );
	}
	$ua = wpoptimizing_importer_patterns( $list( 'cache_reject_ua' ), __( 'Never cache user agent(s)', 'wpoptimizing' ), $notes );
	if ( $ua ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'Never cache user agent(s)', 'wpoptimizing' ) );
	}
	// Paths from the root of the site's host, or patterns: WP Rocket treats an entry with «*» as a
	// regular expression over its cache folders (inc/common/purge.php:113-121; its sanitizer turns
	// every wildcard into «(.*)», inc/functions/formatting.php:177). We purge pages, not folders
	// matched by a pattern, so those are left out and listed; the literal ones come in as URLs.
	if ( $list( 'cache_purge_pages' ) ) {
		$literal  = array();
		$patterns = array();
		foreach ( array_map( 'strval', $list( 'cache_purge_pages' ) ) as $page ) {
			if ( false !== strpos( $page, '*' ) ) {
				$patterns[] = $page;
			} else {
				$literal[] = '/' . ltrim( $page, '/' );
			}
		}
		$always = wpoptimizing_content_always_purge_parse( $literal, home_url( '/' ) );
		if ( $always['urls'] ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'purge_always', $always['urls'], __( 'Always Purge URL(s)', 'wpoptimizing' ) );
		}
		$lost = array_merge( $patterns, $always['rejected'] );
		if ( $lost ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: list of entries left out. */
					__( 'Always Purge URL(s): these entries are patterns (or not pages of this site), which WPOptimizing does not purge, so they were not imported. Add the pages they meant one by one: %s', 'wpoptimizing' ),
					implode( ', ', array_slice( $lost, 0, 10 ) ) . ( count( $lost ) > 10 ? ' …' : '' )
				)
			);
		}
	}

	// CSS and JavaScript.
	foreach ( array(
		'minify_css' => __( 'Minify CSS files', 'wpoptimizing' ),
		'minify_js'  => __( 'Minify JavaScript files', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $key, $on( $key ), $label );
		}
	}
	$exclude = array_merge( $list( 'exclude_css' ), $list( 'exclude_js' ) );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( array_map( 'strval', $exclude ) ) ), __( 'Excluded CSS and JavaScript files', 'wpoptimizing' ) );
	}
	if ( isset( $r['defer_all_js'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', $on( 'defer_all_js' ), __( 'Load JavaScript deferred', 'wpoptimizing' ) );
	}
	if ( $list( 'exclude_defer_js' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', array_map( 'strval', $list( 'exclude_defer_js' ) ), __( 'Excluded JavaScript files (deferred)', 'wpoptimizing' ) );
	}
	if ( isset( $r['async_css'] ) && ! $on( 'remove_unused_css' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'async_css', $on( 'async_css' ), __( 'Load CSS asynchronously', 'wpoptimizing' ) );
	}
	if ( isset( $r['critical_css'] ) && is_string( $r['critical_css'] ) && '' !== trim( $r['critical_css'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'critical_css', (string) $r['critical_css'], __( 'Fallback critical CSS', 'wpoptimizing' ) );
	}
	if ( isset( $r['delay_js'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', $on( 'delay_js' ), __( 'Delay JavaScript execution', 'wpoptimizing' ) );
		if ( $list( 'delay_js_exclusions' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', array_map( 'strval', $list( 'delay_js_exclusions' ) ), __( 'Excluded JavaScript files (delayed)', 'wpoptimizing' ) );
		}
	}
	if ( $on( 'minify_concatenate_js' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Combine JavaScript files: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ) );
	}
	if ( $on( 'remove_unused_css' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Remove Unused CSS: WP Rocket\'s own online service, not imported. WPOptimizing generates critical CSS on your server instead.', 'wpoptimizing' ) );
	}

	// Media and fonts.
	// Images and iframes are separate switches; the YouTube preview only acts with iframes on
	// (Engine/Media/Lazyload/Subscriber.php:173-181, :217). Iframes are ours «lazy embeds».
	if ( isset( $r['lazyload'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( 'lazyload' ), __( 'LazyLoad for images and iframes', 'wpoptimizing' ) );
	}
	if ( isset( $r['lazyload_iframes'] ) || isset( $r['lazyload_youtube'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', $on( 'lazyload_iframes' ), __( 'Replace YouTube iframe with preview image', 'wpoptimizing' ) );
	}
	if ( isset( $r['host_fonts_locally'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', $on( 'host_fonts_locally' ), __( 'Self-host Google Fonts', 'wpoptimizing' ) );
	}
	if ( $list( 'preload_fonts' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Preload fonts', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', array_map( 'strval', $list( 'preload_fonts' ) ), __( 'Preload fonts', 'wpoptimizing' ) );
	}
	if ( $list( 'dns_prefetch' ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'Prefetch DNS requests', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Prefetch DNS requests: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ) );
	}
	// Fragments of the tag or of the URL (Dependencies/RocketLazyload/Image.php:537-552).
	if ( $on( 'lazyload' ) && $list( 'exclude_lazyload' ) ) {
		$exclude = wpoptimizing_importer_lazy_exclusions( $list( 'exclude_lazyload' ), __( 'Excluded images or iframes', 'wpoptimizing' ), $notes );
		if ( $exclude ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_exclude', $exclude, __( 'Excluded images or iframes', 'wpoptimizing' ) );
		}
	}

	// Core tweaks.
	if ( isset( $r['emoji'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( 'emoji' ), __( 'Disable WordPress emoji', 'wpoptimizing' ) );
	}

	// CDN: one host here.
	if ( $on( 'cdn' ) && $list( 'cdn_cnames' ) ) {
		$host = (string) $list( 'cdn_cnames' )[0];
		$c[]  = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'Enable Content Delivery Network', 'wpoptimizing' ) );
		$c[]  = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', $host, __( 'CDN CNAME(s)', 'wpoptimizing' ) );
		if ( count( $list( 'cdn_cnames' ) ) > 1 ) {
			$notes[] = wpoptimizing_importer_note( 'review', __( 'CDN CNAME(s): WPOptimizing uses one CDN host, so only the first one was imported.', 'wpoptimizing' ) );
		}
	}

	// Database cleanup. A schedule runs exactly the ticked tasks: then every other task here,
	// revisions included, is left off.
	$scheduled = $on( 'schedule_automatic_cleanup' ) && isset( $r['automatic_cleanup_frequency'] ) && in_array( $r['automatic_cleanup_frequency'], array( 'daily', 'weekly', 'monthly' ), true );
	$selected  = array();
	foreach ( array(
		'database_auto_drafts'      => array( 'delete_auto_drafts', __( 'Auto drafts', 'wpoptimizing' ) ),
		'database_trashed_posts'    => array( 'delete_trashed_posts', __( 'Trashed posts', 'wpoptimizing' ) ),
		'database_spam_comments'    => array( 'delete_spam_comments', __( 'Spam comments', 'wpoptimizing' ) ),
		'database_trashed_comments' => array( 'delete_trashed_comments', __( 'Trashed comments', 'wpoptimizing' ) ),
		'database_all_transients'   => array( 'delete_all_transients', __( 'All transients', 'wpoptimizing' ) ),
		'database_optimize_tables'  => array( 'optimize_tables', __( 'Optimize tables', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( $scheduled && $on( $key ) ) {
			$selected[ $to[0] ] = $to[1];
		} elseif ( ! $scheduled && isset( $r[ $key ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', $to[0], $on( $key ) ? 1 : 0, $to[1] );
		}
	}
	if ( $on( 'database_revisions' ) ) {
		$selected['revisions'] = __( 'Revisions', 'wpoptimizing' );
		$notes[]               = wpoptimizing_importer_note( 'review', __( 'Revisions: WP Rocket deletes all of them; WPOptimizing keeps the last two of each post, which you can change in General cleanup.', 'wpoptimizing' ) );
	}
	if ( $scheduled ) {
		$c   = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, __( 'Schedule automatic cleanup', 'wpoptimizing' ) ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', (string) $r['automatic_cleanup_frequency'], __( 'Schedule automatic cleanup', 'wpoptimizing' ) );
	}

	// Heartbeat: the same three zones. '' leaves WordPress alone, 'disable' removes the script and
	// 'reduce_periodicity' sets 120 s (Engine/Heartbeat/HeartbeatSubscriber.php:82-90).
	if ( $on( 'control_heartbeat' ) ) {
		$zones = array();
		foreach ( array(
			'admin'  => 'heartbeat_admin_behavior',
			'editor' => 'heartbeat_editor_behavior',
			'front'  => 'heartbeat_site_behavior',
		) as $zone => $key ) {
			$behavior       = (string) ( $r[ $key ] ?? '' );
			$zones[ $zone ] = 'disable' === $behavior ? 'off' : ( 'reduce_periodicity' === $behavior ? 120 : 'default' );
		}
		$c = array_merge( $c, wpoptimizing_importer_heartbeat( $zones, __( 'Control Heartbeat', 'wpoptimizing' ), $notes ) );
	}

	// Cloudflare and Varnish.
	if ( $on( 'do_cloudflare' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Cloudflare: WP Rocket uses your account\'s Global API Key, which WPOptimizing does not copy. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ) );
	}
	if ( isset( $r['varnish_auto_purge'] ) && $on( 'varnish_auto_purge' ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Varnish: switch on its purge in Cache › Browser & server once WPOptimizing has checked that Varnish is in front of the site.', 'wpoptimizing' ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
