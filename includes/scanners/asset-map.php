<?php
/**
 * Asset-registry helpers (front-end CSS/JS attribution — KB collection for the
 * future "Smart Assets" feature).
 *
 * The plugin and the runtime feeder record, per site, which script/style handles
 * exist and who owns them, so a crowd-validated map of "handle -> plugin/theme +
 * dependencies" accumulates now and is ready when the unloading UI ships. Only
 * structural data (handle, owner, normalized src path, dependency handles) is
 * collected — never asset contents.
 *
 * These helpers are pure (no WP, no I/O) so they are unit-testable and can be
 * mirrored by the standalone feeder.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize an asset src to a stable, privacy-safe, wp-content-relative path:
 * strip the scheme/host and any query string (cache busters, tokens), and reduce
 * to the path under wp-content when present.
 *
 * @param string $src Raw enqueued src (may be absolute, protocol-relative or relative).
 * @return string Normalized path (e.g. "plugins/foo/assets/app.js"), or ''.
 */
function wpoptimizing_normalize_asset_src( $src ) {
	$src = trim( (string) $src );
	if ( '' === $src ) {
		return '';
	}
	// Drop query string and fragment (version args, tokens, nonces).
	$src = preg_replace( '/[?#].*$/', '', $src );
	// Drop scheme + host: keep from the first path separator.
	$src = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $src ); // scheme://
	$src = preg_replace( '#^//#', '', $src );                    // protocol-relative
	// If a host is still prefixed (no leading slash but has a dot before the first /),
	// strip up to the first slash.
	if ( '' !== $src && '/' !== $src[0] && preg_match( '#^[^/]+\.[^/]+/#', $src ) ) {
		$src = substr( $src, (int) strpos( $src, '/' ) );
	}
	// Reduce to the part under wp-content when present (with or without a leading
	// slash), else just drop a leading slash (e.g. core /wp-includes/… paths).
	$pos = stripos( $src, 'wp-content/' );
	if ( false !== $pos ) {
		$src = substr( $src, $pos + strlen( 'wp-content/' ) );
	} else {
		$src = ltrim( $src, '/' );
	}
	return $src;
}

/**
 * Resolve the owner (plugin/theme/core) of an asset from its normalized,
 * wp-content-relative src path.
 *
 * @param string $rel Normalized path from wpoptimizing_normalize_asset_src().
 * @return array{type:string,slug:string} type is plugin|theme|core|unknown.
 */
function wpoptimizing_asset_owner_from_src( $rel ) {
	$rel = ltrim( (string) $rel, '/' );
	if ( '' === $rel ) {
		return array(
			'type' => 'unknown',
			'slug' => '',
		);
	}
	if ( 0 === strpos( $rel, 'plugins/' ) || 0 === strpos( $rel, 'mu-plugins/' ) ) {
		$after = substr( $rel, (int) strpos( $rel, '/' ) + 1 );
		$slug  = strtok( $after, '/' );
		return array(
			'type' => 'plugin',
			'slug' => (string) $slug,
		);
	}
	if ( 0 === strpos( $rel, 'themes/' ) ) {
		$slug = strtok( substr( $rel, strlen( 'themes/' ) ), '/' );
		return array(
			'type' => 'theme',
			'slug' => (string) $slug,
		);
	}
	// Core assets are served from wp-includes / wp-admin (no wp-content prefix, so
	// the normalizer leaves them with that lead segment).
	if ( 0 === strpos( $rel, 'wp-includes/' ) || 0 === strpos( $rel, 'wp-admin/' ) ) {
		return array(
			'type' => 'core',
			'slug' => '',
		);
	}
	return array(
		'type' => 'unknown',
		'slug' => '',
	);
}

/**
 * Owner of an asset generated into uploads/ (Complianz's banner CSS, Avada's compiled styles,
 * Elementor's per-post CSS…), from the folder it lives in. Path attribution only knew plugins/,
 * themes/ and core, so these came out ownerless — and their handles rarely start with the slug
 * (`cmplz-banner-1-optin` is complianz-gdpr's), so the handle fallback missed them too.
 *
 * The first folder under uploads/ (skipping sites/N/ and year folders) is matched against the
 * installed slugs: equal, the slug starting with "<folder>-" (complianz → complianz-gdpr), or the
 * folder starting with "<slug>-" (elementor-pro…). A few well-known folders that name no slug go
 * through an alias map. Ambiguous matches stay unattributed: the owner decides what Smart Assets
 * may unload, so a wrong owner is worse than none.
 *
 * @param string $rel Normalized path from wpoptimizing_normalize_asset_src().
 * @param array  $idx Slug index: lowercase slug => array( type, slug ).
 * @return array{type:string,slug:string}|null
 */
function wpoptimizing_asset_owner_from_upload_dir( $rel, array $idx ) {
	$rel = ltrim( strtolower( (string) $rel ), '/' );
	if ( 0 !== strpos( $rel, 'uploads/' ) ) {
		return null;
	}
	$parts = explode( '/', substr( $rel, 8 ) );
	if ( isset( $parts[0], $parts[1] ) && 'sites' === $parts[0] && ctype_digit( $parts[1] ) ) {
		$parts = array_slice( $parts, 2 );
	}
	if ( count( $parts ) < 2 || '' === $parts[0] || 1 === preg_match( '/^\d{4}$/', $parts[0] ) ) {
		return null;
	}
	$dir     = $parts[0];
	$aliases = array(
		'fusion-styles'       => 'avada',
		'fusion-scripts'      => 'avada',
		'fusion-gfonts'       => 'avada',
		'fusion-icons'        => 'avada',
		'et-cache'            => 'divi',
		'oxygen'              => 'oxygen',
		'bb-plugin'           => 'bb-plugin',
		'wc-logs'             => 'woocommerce',
		'woocommerce_uploads' => 'woocommerce',
		'wc-imports'          => 'woocommerce',
	);
	if ( isset( $aliases[ $dir ] ) ) {
		$dir = $aliases[ $dir ];
		if ( isset( $idx[ $dir ] ) ) {
			return array(
				'type' => $idx[ $dir ][0],
				'slug' => $idx[ $dir ][1],
			);
		}
	}
	if ( isset( $idx[ $dir ] ) ) {
		return array(
			'type' => $idx[ $dir ][0],
			'slug' => $idx[ $dir ][1],
		);
	}
	$found = array();
	foreach ( $idx as $slug => $info ) {
		$slug = (string) $slug;
		if ( strlen( $dir ) >= 4 && ( 0 === strpos( $slug, $dir . '-' ) || 0 === strpos( $dir, $slug . '-' ) ) ) {
			$found[ $slug ] = $info;
		}
	}
	if ( 1 !== count( $found ) ) {
		return null;
	}
	$info = reset( $found );
	return array(
		'type' => $info[0],
		'slug' => $info[1],
	);
}
