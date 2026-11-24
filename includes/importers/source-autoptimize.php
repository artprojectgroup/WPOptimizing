<?php
/**
 * Import source: Autoptimize.
 *
 * Loose `autoptimize_*` options (defaults in classes/autoptimizeConfig.php:829-863) plus two arrays
 * with numbered keys, `autoptimize_extra_settings` (:873-883, meaning of each field in
 * classes/autoptimizeExtra.php:568-641) and `autoptimize_imgopt_settings` (:893-903). Keys read in
 * Autoptimize 3.1.17; docs/cache-2026-11-06/importadores-B.md §12.
 *
 * Multisite: when the plugin is network-activated and the network does not allow a configuration
 * per site, every option is a network option (classes/autoptimizeOptionWrapper.php:36-56).
 *
 * Never read, so never imported: the criticalcss.com API key (`autoptimize_ccss_key`) and the
 * rest of that service's settings. read() only notes whether the key is set, to say the service
 * is not carried over.
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
function wpoptimizing_import_src_autoptimize_read() {
	$network = false;
	if ( is_multisite() ) {
		$sitewide = get_site_option( 'active_sitewide_plugins' );
		$network  = is_array( $sitewide ) && isset( $sitewide['autoptimize/autoptimize.php'] )
			&& 'on' !== get_site_option( 'autoptimize_enable_site_config', 'on' );
	}
	$get  = static function ( $name ) use ( $network ) {
		return $network ? get_site_option( $name, null ) : get_option( $name, null );
	};
	$keys = array(
		'autoptimize_html',
		'autoptimize_js',
		'autoptimize_js_aggregate',
		'autoptimize_js_defer_not_aggregate',
		'autoptimize_js_defer_inline',
		'autoptimize_js_exclude',
		'autoptimize_css',
		'autoptimize_css_aggregate',
		'autoptimize_css_exclude',
		'autoptimize_css_defer',
		'autoptimize_css_defer_inline',
		'autoptimize_css_inline',
		'autoptimize_cdn_url',
		'autoptimize_extra_settings',
		'autoptimize_imgopt_settings',
	);
	$raw  = array();
	foreach ( $keys as $key ) {
		$value = $get( $key );
		if ( null !== $value && false !== $value ) {
			$raw[ $key ] = $value;
		}
	}
	if ( ! $raw ) {
		return null;
	}
	// What was never saved is at Autoptimize's default, which for these switches is «on».
	$raw               += array(
		'autoptimize_html'                   => 1,
		'autoptimize_js'                     => 1,
		'autoptimize_js_defer_not_aggregate' => 1,
		'autoptimize_css'                    => 1,
	);
	$raw['ccss_in_use'] = '' !== trim( (string) $get( 'autoptimize_ccss_key' ) );
	return $raw;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r What wpoptimizing_import_src_autoptimize_read() returns.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_autoptimize_map( array $r ) {
	$c      = array();
	$notes  = array();
	$on     = static function ( array $set, $key ) {
		$v = $set[ $key ] ?? '';
		return is_string( $v ) ? ! in_array( strtolower( trim( $v ) ), array( '', '0', 'off' ), true ) : ! empty( $v );
	};
	$commas = static function ( $value ) {
		return is_scalar( $value ) ? array_values( array_filter( array_map( 'trim', explode( ',', (string) $value ) ), 'strlen' ) ) : array();
	};
	$extra  = is_array( $r['autoptimize_extra_settings'] ?? null ) ? $r['autoptimize_extra_settings'] : array();
	$imgopt = is_array( $r['autoptimize_imgopt_settings'] ?? null ) ? $r['autoptimize_imgopt_settings'] : array();
	/* translators: %s: setting name in the other plugin. */
	$combine = __( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' );

	// JavaScript. Without aggregation, each file is minified and (by default) deferred.
	$js = $on( $r, 'autoptimize_js' );
	if ( isset( $r['autoptimize_js'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_js', $js, __( 'Optimize JavaScript Code?', 'wpoptimizing' ) );
	}
	if ( $js && $on( $r, 'autoptimize_js_aggregate' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $combine, __( 'Aggregate JS-files?', 'wpoptimizing' ) ) );
	}
	$defer = $js && ! $on( $r, 'autoptimize_js_aggregate' ) && $on( $r, 'autoptimize_js_defer_not_aggregate' );
	if ( $js ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', $defer, __( 'Do not aggregate but defer?', 'wpoptimizing' ) );
	}
	// Excluded there from everything Autoptimize does: from minifying, and from deferring.
	$js_exclude = $commas( $r['autoptimize_js_exclude'] ?? '' );
	if ( $defer && $js_exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_exclude', $js_exclude, __( 'Exclude scripts from Autoptimize:', 'wpoptimizing' ) );
	}

	// CSS.
	$css = $on( $r, 'autoptimize_css' );
	if ( isset( $r['autoptimize_css'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'minify_css', $css, __( 'Optimize CSS Code?', 'wpoptimizing' ) );
	}
	if ( $css && $on( $r, 'autoptimize_css_aggregate' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( $combine, __( 'Aggregate CSS-files?', 'wpoptimizing' ) ) );
	}
	if ( $css && $on( $r, 'autoptimize_css_defer' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'async_css', true, __( 'Eliminate render-blocking CSS?', 'wpoptimizing' ) );
		if ( is_string( $r['autoptimize_css_defer_inline'] ?? null ) && '' !== trim( $r['autoptimize_css_defer_inline'] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'critical_css', (string) $r['autoptimize_css_defer_inline'], __( 'Eliminate render-blocking CSS?', 'wpoptimizing' ) );
		}
	}
	if ( $css && $on( $r, 'autoptimize_css_inline' ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Inline all CSS?', 'wpoptimizing' ) ) );
	}
	$exclude = array_merge( $js ? $js_exclude : array(), $css ? $commas( $r['autoptimize_css_exclude'] ?? '' ) : array() );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', array_values( array_unique( $exclude ) ), __( 'Exclude CSS from Autoptimize:', 'wpoptimizing' ) );
	}
	if ( $on( $r, 'autoptimize_html' ) ) {
		// The page cache minifies the HTML of the copies it stores (html-minify.php).
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'Optimize HTML Code?', 'wpoptimizing' ) );
	}
	if ( '' !== trim( (string) ( is_scalar( $r['autoptimize_cdn_url'] ?? null ) ? $r['autoptimize_cdn_url'] : '' ) ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. It only applied to the files the other plugin optimized, while WPOptimizing\'s CDN rewrites every static file; set it up yourself if your CDN serves them all.', 'wpoptimizing' ), __( 'CDN Base URL', 'wpoptimizing' ) ) );
	}
	if ( ! empty( $r['ccss_in_use'] ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: generated by an online service, not imported. WPOptimizing generates critical CSS on your server instead.', 'wpoptimizing' ), __( 'Critical CSS', 'wpoptimizing' ) ) );
	}

	// Extra.
	if ( isset( $extra['autoptimize_extra_checkbox_field_1'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', $on( $extra, 'autoptimize_extra_checkbox_field_1' ), __( 'Remove emojis', 'wpoptimizing' ) );
	}
	$preload = $commas( $extra['autoptimize_extra_text_field_7'] ?? '' );
	if ( $preload ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Preload specific requests (advanced users)', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', $preload, __( 'Preload specific requests (advanced users)', 'wpoptimizing' ) );
	}
	if ( $commas( $extra['autoptimize_extra_text_field_2'] ?? '' ) ) {
		$label = __( 'Preconnect to 3rd party domains (advanced users)', 'wpoptimizing' );
		$c[]   = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, $label );
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'review', sprintf( __( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ), $label ) );
	}
	$fonts = (string) ( is_scalar( $extra['autoptimize_extra_radio_field_4'] ?? null ) ? $extra['autoptimize_extra_radio_field_4'] : '1' );
	if ( in_array( $fonts, array( '2', '3', '4', '5' ), true ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Google Fonts: not imported. Autoptimize removed or combined them; WPOptimizing can host them on your server instead (Self-host Google Fonts), which you can switch on yourself.', 'wpoptimizing' ) );
	}
	if ( $on( $extra, 'autoptimize_extra_checkbox_field_0' ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported. Removing the version from file URLs does not make pages faster and can serve outdated files after an update.', 'wpoptimizing' ), __( 'Remove query strings from static resources', 'wpoptimizing' ) ) );
	}
	if ( $commas( $extra['autoptimize_extra_text_field_3'] ?? '' ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Async Javascript-files (advanced users)', 'wpoptimizing' ) ) );
	}
	if ( $on( $extra, 'autoptimize_extra_checkbox_field_8' ) ) {
		/* translators: %s: setting name in the other plugin. */
		$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Remove WordPress block CSS', 'wpoptimizing' ) ) );
	}

	// Images: only lazy load has a counterpart; the optimization itself is ShortPixel's service.
	if ( isset( $imgopt['autoptimize_imgopt_checkbox_field_3'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( $imgopt, 'autoptimize_imgopt_checkbox_field_3' ), __( 'Lazy-load images?', 'wpoptimizing' ) );
		if ( $on( $imgopt, 'autoptimize_imgopt_checkbox_field_3' ) && '' !== trim( (string) ( is_scalar( $imgopt['autoptimize_imgopt_text_field_5'] ?? null ) ? $imgopt['autoptimize_imgopt_text_field_5'] : '' ) ) ) {
			/* translators: %s: setting name in the other plugin. */
			$notes[] = wpoptimizing_importer_note( 'skipped', sprintf( __( '%s: not imported.', 'wpoptimizing' ), __( 'Lazy-load exclusions', 'wpoptimizing' ) ) );
		}
	}
	if ( $on( $imgopt, 'autoptimize_imgopt_checkbox_field_1' ) ) {
		$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Image optimization & CDN: ShortPixel\'s online service, not imported. WPOptimizing optimizes images on your server.', 'wpoptimizing' ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
