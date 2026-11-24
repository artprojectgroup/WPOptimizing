<?php
/**
 * Import source: Perfmatters.
 *
 * Two options (per site): `perfmatters_options`, nested by section (`[assets][delay_js]`, the
 * general switches at the top level), and `perfmatters_tools` (database cleanup, Script
 * Manager). Its own export is those two arrays as JSON. Keys read in Perfmatters 2.6.9
 * (docs/cache-2026-11-06/importadores-B.md §11).
 *
 * It has no page cache, so nothing of ours that installs a drop-in is involved. A switch that
 * is off is simply absent from the array, so absence cannot tell «off» from «never touched»:
 * only the switches that are ON are imported, and nothing of ours is switched off.
 *
 * Never imported: the licence (`perfmatters_edd_license_key`, not even read), the analytics
 * tracking ID, the custom login URL and the header/body/footer code snippets.
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
function wpoptimizing_import_src_perfmatters_read() {
	$options = get_option( 'perfmatters_options' );
	$tools   = get_option( 'perfmatters_tools' );
	$options = is_array( $options ) ? $options : array();
	$tools   = is_array( $tools ) ? $tools : array();
	if ( empty( $options ) && empty( $tools ) ) {
		return null;
	}
	return array(
		'options' => $options,
		'tools'   => $tools,
	);
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r {options: perfmatters_options, tools: perfmatters_tools}.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_perfmatters_map( array $r ) {
	$c     = array();
	$notes = array();
	$none  = array();
	$o     = is_array( $r['options'] ?? null ) ? $r['options'] : array();
	$t     = is_array( $r['tools'] ?? null ) ? $r['tools'] : array();
	// Value of a setting: top level, or inside a section.
	$get = static function ( $key, $section = '', $from = 'options' ) use ( $o, $t ) {
		$src = 'tools' === $from ? $t : $o;
		if ( '' !== $section ) {
			$src = is_array( $src[ $section ] ?? null ) ? $src[ $section ] : array();
		}
		return $src[ $key ] ?? null;
	};
	$on  = static function ( $key, $section = '', $from = 'options' ) use ( $get ) {
		return ! empty( $get( $key, $section, $from ) );
	};
	// A one-per-line field: an array since 2.x, a string in older saves.
	$list = static function ( $key, $section = '' ) use ( $get ) {
		$v = $get( $key, $section );
		$v = is_string( $v ) ? preg_split( '/[\r\n]+/', $v ) : (array) $v;
		return array_values( array_filter( array_map( 'trim', array_map( 'strval', array_filter( $v, 'is_scalar' ) ) ), 'strlen' ) );
	};
	// Input rows ([preload][preload], [preload][preconnect]): the URL of each row.
	$rows = static function ( $key, $section ) use ( $get ) {
		$out = array();
		foreach ( (array) $get( $key, $section ) as $row ) {
			if ( is_array( $row ) && isset( $row['url'] ) && is_scalar( $row['url'] ) && '' !== trim( (string) $row['url'] ) ) {
				$out[] = trim( (string) $row['url'] );
			}
		}
		return $out;
	};

	// General: the core tweaks.
	foreach ( array(
		'disable_emojis'                         => array( 'emojis', __( 'Disable Emojis', 'wpoptimizing' ) ),
		'disable_dashicons'                      => array( 'dashicons_frontend', __( 'Disable Dashicons', 'wpoptimizing' ) ),
		'disable_embeds'                         => array( 'embeds', __( 'Disable Embeds', 'wpoptimizing' ) ),
		'remove_rsd_link'                        => array( 'rsd_link', __( 'Remove RSD Link', 'wpoptimizing' ) ),
		'remove_jquery_migrate'                  => array( 'jquery_migrate', __( 'Remove jQuery Migrate', 'wpoptimizing' ) ),
		'hide_wp_version'                        => array( 'generator', __( 'Hide WP Version', 'wpoptimizing' ) ),
		'remove_shortlink'                       => array( 'shortlink', __( 'Remove Shortlink', 'wpoptimizing' ) ),
		'disable_self_pingbacks'                 => array( 'self_pingbacks', __( 'Disable Self Pingbacks', 'wpoptimizing' ) ),
		'remove_rest_api_links'                  => array( 'rest_head_links', __( 'Remove REST API Links', 'wpoptimizing' ) ),
		'disable_woocommerce_cart_fragmentation' => array( 'cart_fragments', __( 'Disable Cart Fragmentation', 'wpoptimizing' ) ),
	) as $key => $to ) {
		if ( $on( $key ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', $to[0], true, $to[1] );
		}
	}
	foreach ( array(
		'disable_xmlrpc'                  => __( 'Disable XML-RPC', 'wpoptimizing' ),
		'disable_rss_feeds'               => __( 'Disable RSS Feeds', 'wpoptimizing' ),
		'remove_feed_links'               => __( 'Remove RSS Feed Links', 'wpoptimizing' ),
		'disable_rest_api'                => __( 'Disable REST API', 'wpoptimizing' ),
		'disable_google_maps'             => __( 'Disable Google Maps', 'wpoptimizing' ),
		'disable_password_strength_meter' => __( 'Disable Password Meter', 'wpoptimizing' ),
		'disable_comments'                => __( 'Disable Comments', 'wpoptimizing' ),
		'remove_comment_urls'             => __( 'Remove Comment URLs', 'wpoptimizing' ),
		'blank_favicon'                   => __( 'Add Blank Favicon', 'wpoptimizing' ),
		'remove_global_styles'            => __( 'Remove Global Styles', 'wpoptimizing' ),
		'separate_block_styles'           => __( 'Separate Block Styles', 'wpoptimizing' ),
		'autosave_interval'               => __( 'Autosave Interval', 'wpoptimizing' ),
		'login_url'                       => __( 'Custom Login URL', 'wpoptimizing' ),
		'disable_woocommerce_scripts'     => __( 'Disable Scripts', 'wpoptimizing' ),
		'disable_woocommerce_status'      => __( 'Disable Status Meta Box', 'wpoptimizing' ),
		'disable_woocommerce_widgets'     => __( 'Disable Widgets', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( $key ) ) {
			$none[] = $label;
		}
	}

	// Heartbeat: where it is removed ('disable_everywhere', or everywhere but the post editor with
	// 'allow_posts') and how often it beats where it stays (heartbeat_frequency, 30-60 s), as
	// two separate settings (inc/classes/General.php:795-850).
	$hb   = (string) $get( 'disable_heartbeat' );
	$freq = is_scalar( $get( 'heartbeat_frequency' ) ) ? (int) $get( 'heartbeat_frequency' ) : 0;
	$off  = in_array( $hb, array( 'disable_everywhere', 'allow_posts' ), true );
	if ( $off || $freq > 0 ) {
		$beat  = $freq > 0 ? $freq : 'default';
		$zones = array(
			'admin'  => $beat,
			'editor' => $beat,
			'front'  => $beat,
		);
		if ( $off ) {
			$zones['admin'] = 'off';
			$zones['front'] = 'off';
		}
		if ( 'disable_everywhere' === $hb ) {
			$zones['editor'] = 'off';
		}
		$c = array_merge( $c, wpoptimizing_importer_heartbeat( $zones, $off ? __( 'Disable Heartbeat', 'wpoptimizing' ) : __( 'Heartbeat Frequency', 'wpoptimizing' ), $notes ) );
	}

	// Revisions: a limit WordPress applies there, what the cleanup keeps here.
	$limit = $get( 'limit_post_revisions' );
	if ( is_scalar( $limit ) && '' !== (string) $limit ) {
		$keep    = 'false' === (string) $limit ? 0 : max( 0, (int) $limit );
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', 'keep_revisions', $keep, __( 'Limit Post Revisions', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: the other plugin stops WordPress from keeping more revisions; WPOptimizing deletes the extra ones each time General cleanup runs.', 'wpoptimizing' ),
				__( 'Limit Post Revisions', 'wpoptimizing' )
			)
		);
	}

	// JavaScript and CSS.
	if ( $on( 'defer_js', 'assets' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', true, __( 'Defer Javascript', 'wpoptimizing' ) );
		if ( $list( 'js_exclusions', 'assets' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', $list( 'js_exclusions', 'assets' ), __( 'Excluded from Deferral', 'wpoptimizing' ) );
		}
	}
	// Any behaviour but the empty one («individual») delays everything; individual delays only
	// the listed scripts, and nothing with an empty list (inc/classes/JS.php:262-275). That list
	// alone is our «custom» profile: «safe» would add its own targets to it.
	$all      = 'all' === (string) $get( 'delay_js_behavior', 'assets' );
	$delay_on = $on( 'delay_js', 'assets' ) && ( $all || $list( 'delay_js_inclusions', 'assets' ) );
	if ( $on( 'delay_js', 'assets' ) && ! $delay_on ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', false, __( 'Delay JavaScript', 'wpoptimizing' ) );
	}
	if ( $delay_on ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', true, __( 'Delay JavaScript', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'profile', $all ? 'all' : 'custom', __( 'Delay Behavior', 'wpoptimizing' ) );
		if ( ! $all ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'include', $list( 'delay_js_inclusions', 'assets' ), __( 'Delayed Scripts', 'wpoptimizing' ) );
		}
		if ( $list( 'delay_js_exclusions', 'assets' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'exclude', $list( 'delay_js_exclusions', 'assets' ), __( 'Excluded from Delay', 'wpoptimizing' ) );
		}
		if ( $all ) {
			$c[]     = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'test_mode', true, __( 'Delay Behavior', 'wpoptimizing' ) );
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: imported with «Test mode: apply only for logged-in administrators» on, because delaying every script can break menus, sliders and carts. Check your key pages, then switch test mode off in Delay JavaScript.', 'wpoptimizing' ),
					__( 'Delay All Scripts', 'wpoptimizing' )
				)
			);
		}
	}
	foreach ( array(
		'minify_css' => __( 'Minify CSS', 'wpoptimizing' ),
		'minify_js'  => __( 'Minify JavaScript', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( $key, 'assets' ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $key, true, $label );
		}
	}
	$exclude = array_merge( $list( 'minify_css_exclusions', 'assets' ), $list( 'minify_js_exclusions', 'assets' ) );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $exclude ) ), __( 'Excluded from Minification', 'wpoptimizing' ) );
	}
	if ( $on( 'remove_unused_css', 'assets' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: not imported. WPOptimizing generates critical CSS on your server instead of removing unused CSS.', 'wpoptimizing' ),
				__( 'Remove Unused CSS', 'wpoptimizing' )
			)
		);
	}
	if ( $on( 'header_code', 'assets' ) || $on( 'body_code', 'assets' ) || $on( 'footer_code', 'assets' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Add Header Code, Add Body Code, Add Footer Code: code snippets are not imported. If you still need them, move them to your theme or to a code snippets plugin before deactivating Perfmatters.', 'wpoptimizing' ) );
	}

	// Preloading.
	$mode = (string) $get( 'speculative_mode', 'preload' );
	$spec = array(
		'prefetch'  => 'conservative',
		'prerender' => 'eager',
		'disabled'  => 'off',
	);
	if ( isset( $spec[ $mode ] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'speculation', $spec[ $mode ], __( 'Speculative Loading', 'wpoptimizing' ) );
	} elseif ( $on( 'instant_page', 'preload' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'speculation', 'conservative', __( 'Enable Instant Page', 'wpoptimizing' ) );
	}
	if ( $rows( 'preload', 'preload' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Preload', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', $rows( 'preload', 'preload' ), __( 'Preload', 'wpoptimizing' ) );
	}
	if ( $rows( 'preconnect', 'preload' ) || $list( 'dns_prefetch', 'preload' ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'Preconnect, DNS Prefetch', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			sprintf(
				/* translators: %s: the setting's name in the other plugin. */
				__( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ),
				__( 'Preconnect, DNS Prefetch', 'wpoptimizing' )
			)
		);
	}

	// Lazy loading.
	// Images and iframes are separate switches there (LazyLoad/Iframes.php:22); the YouTube
	// thumbnails only act with iframes on (Iframes.php:352-358).
	if ( $on( 'lazy_loading', 'lazyload' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', true, __( 'Lazy Loading: Images, iFrames and Videos', 'wpoptimizing' ) );
	}
	if ( $on( 'lazy_loading_iframes', 'lazyload' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_embeds', true, $on( 'youtube_preview_thumbnails', 'lazyload' ) ? __( 'YouTube Preview Thumbnails', 'wpoptimizing' ) : __( 'Lazy Loading: Images, iFrames and Videos', 'wpoptimizing' ) );
	}
	// Its exclusions match a fragment of the tag (LazyLoad/Attributes.php), and «Exclude Leading
	// Images» leaves the first 1-5 images of the page eager (LazyLoad/Images.php:71-83): our
	// «images never deferred at the top», which counts the same way.
	if ( $on( 'lazy_loading', 'lazyload' ) ) {
		$exclude = wpoptimizing_importer_lazy_exclusions( $list( 'lazy_loading_exclusions', 'lazyload' ), __( 'Exclude from Lazy Loading', 'wpoptimizing' ), $notes );
		if ( $exclude ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'lazy_exclude', $exclude, __( 'Exclude from Lazy Loading', 'wpoptimizing' ) );
		}
		$leading = $get( 'exclude_leading_images', 'lazyload' );
		if ( is_scalar( $leading ) && (int) $leading > 0 ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_eager', min( 5, (int) $leading ), __( 'Exclude Leading Images', 'wpoptimizing' ) );
		}
		if ( $list( 'lazy_loading_parent_exclusions', 'lazyload' ) ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: not imported; WPOptimizing excludes an image by its own class or file name, not by the element around it. Add the images\' class or file name to its lazy-load exclusions.', 'wpoptimizing' ),
					__( 'Exclude by Parent Selector', 'wpoptimizing' )
				)
			);
		}
	}
	foreach ( array(
		'css_background_images' => __( 'Background Images', 'wpoptimizing' ),
		'image_dimensions'      => __( 'Add Image Dimensions', 'wpoptimizing' ),
		'elements'              => __( 'Lazy Load Elements', 'wpoptimizing' ),
	) as $key => $label ) {
		if ( $on( $key, 'lazyload' ) ) {
			$none[] = $label;
		}
	}

	// Fonts.
	if ( $on( 'local_google_fonts', 'fonts' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'google_fonts', true, __( 'Local Google Fonts', 'wpoptimizing' ) );
	}

	// CDN: one host and three folders here.
	$cdn_url = $get( 'cdn_url', 'cdn' );
	if ( $on( 'enable_cdn', 'cdn' ) && is_string( $cdn_url ) && '' !== trim( $cdn_url ) ) {
		// Empty means wp-content and wp-includes (inc/classes/CDN.php:52-58). A folder that is not
		// one of our scopes, or any exclusion, cannot be kept: then it is not switched on.
		$csv  = static function ( $key ) use ( $get ) {
			$v = $get( $key, 'cdn' );
			return array_values( array_filter( array_map( 'trim', is_scalar( $v ) ? explode( ',', (string) $v ) : array_map( 'strval', array_filter( (array) $v, 'is_scalar' ) ) ), 'strlen' ) );
		};
		$dirs = $csv( 'cdn_directories' );
		$dirs = wpoptimizing_importer_cdn_dirs( $dirs ? $dirs : array( 'wp-content', 'wp-includes' ) );
		$excl = $csv( 'cdn_exclusions' );
		if ( null === $dirs || $excl ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'Enable CDN Rewrite', 'wpoptimizing' ), trim( $cdn_url ) );
		} else {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'Enable CDN Rewrite', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', trim( $cdn_url ), __( 'CDN URL', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'dirs', $dirs, __( 'Included Directories', 'wpoptimizing' ) );
		}
	}

	if ( $on( 'enable_local_ga', 'analytics' ) ) {
		$none[] = __( 'Enable Local Analytics', 'wpoptimizing' );
	}

	// Database: the tasks ticked in Tools › Database, mapped one by one when any is ticked.
	$db_tasks = array(
		'post_auto_drafts'   => array( 'delete_auto_drafts', __( 'Post Auto-Drafts', 'wpoptimizing' ) ),
		'trashed_posts'      => array( 'delete_trashed_posts', __( 'Trashed Posts', 'wpoptimizing' ) ),
		'spam_comments'      => array( 'delete_spam_comments', __( 'Spam Comments', 'wpoptimizing' ) ),
		'trashed_comments'   => array( 'delete_trashed_comments', __( 'Trashed Comments', 'wpoptimizing' ) ),
		'expired_transients' => array( 'delete_expired_transients', __( 'Expired Transients', 'wpoptimizing' ) ),
		'all_transients'     => array( 'delete_all_transients', __( 'All Transients', 'wpoptimizing' ) ),
		'tables'             => array( 'optimize_tables', __( 'Tables', 'wpoptimizing' ) ),
	);
	$any_db   = $on( 'post_revisions', 'database', 'tools' );
	foreach ( array_keys( $db_tasks ) as $key ) {
		$any_db = $any_db || $on( $key, 'database', 'tools' );
	}
	if ( $any_db ) {
		$schedule = (string) $get( 'optimize_schedule', 'database', 'tools' );
		if ( in_array( $schedule, array( 'daily', 'weekly', 'monthly' ), true ) ) {
			// Its scheduled run does exactly the ticked tasks (DatabaseOptimizer.php:158-175):
			// every other task here, revisions included, is left off.
			$selected = array();
			foreach ( $db_tasks as $key => $to ) {
				if ( $on( $key, 'database', 'tools' ) ) {
					$selected[ $to[0] ] = $to[1];
				}
			}
			if ( $on( 'post_revisions', 'database', 'tools' ) ) {
				$selected['revisions'] = __( 'Post Revisions', 'wpoptimizing' );
			}
			$c   = array_merge( $c, wpoptimizing_importer_cleanup_tasks( $selected, __( 'Scheduled Optimization', 'wpoptimizing' ) ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_schedule', '', $schedule, __( 'Scheduled Optimization', 'wpoptimizing' ) );
		} else {
			foreach ( $db_tasks as $key => $to ) {
				$c[] = wpoptimizing_importer_change( 'wpoptimizing_general_cleanup_settings', $to[0], $on( $key, 'database', 'tools' ) ? 1 : 0, $to[1] );
			}
		}
		if ( $on( 'post_revisions', 'database', 'tools' ) ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				sprintf(
					/* translators: %s: the setting's name in the other plugin. */
					__( '%s: the other plugin deletes every revision; WPOptimizing keeps the last ones of each post, as set in General cleanup.', 'wpoptimizing' ),
					__( 'Post Revisions', 'wpoptimizing' )
				)
			);
		}
	}
	if ( $on( 'script_manager', '', 'tools' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Script Manager: its rules are not imported. Use Smart Assets in WPOptimizing to unload files page by page; it tells you which plugin each file belongs to.', 'wpoptimizing' ) );
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
