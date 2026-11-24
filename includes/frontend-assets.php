<?php
/**
 * Smart Assets — inventory (read-only foundation).
 *
 * The mu-plugin feeder samples which front-end script/style handles are actually
 * printed and records them to WPOPT_CSV_ASSETS (kind, handle, owner_type,
 * owner_slug, src_rel, deps, source). This file turns that raw registry into a
 * human-readable inventory: "what loads on your site, grouped by which plugin/theme
 * owns it". It NEVER unloads anything — it is the safe, zero-risk groundwork the
 * later selective-unloading feature builds on.
 *
 * The parsing/grouping helpers are pure (offline-testable); the reader is a thin
 * filesystem wrapper.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse the raw assets-registry CSV into normalized rows.
 *
 * @param string $csv Raw CSV (header: kind,handle,owner_type,owner_slug,src_rel,deps,source).
 * @return array<int,array{kind:string,handle:string,owner_type:string,owner_slug:string,src:string,deps:string[]}>
 */
function wpoptimizing_assets_parse_registry( $csv ) {
	if ( ! function_exists( 'wpoptimizing_csv_parse' ) ) {
		return array();
	}
	$rows = wpoptimizing_csv_parse( (string) $csv, array( 'header' => true ) );
	$core = wpoptimizing_core_asset_handles();
	$out  = array();
	foreach ( $rows as $r ) {
		$kind   = strtolower( trim( (string) ( $r['kind'] ?? '' ) ) );
		$handle = trim( (string) ( $r['handle'] ?? '' ) );
		if ( '' === $handle || ( 'script' !== $kind && 'style' !== $kind ) ) {
			continue;
		}
		$deps_raw = trim( (string) ( $r['deps'] ?? '' ) );
		$deps     = ( '' === $deps_raw ) ? array() : array_values( array_filter( array_map( 'trim', preg_split( '/[\s,|]+/', $deps_raw ) ) ) );
		$otype    = strtolower( trim( (string) ( $r['owner_type'] ?? '' ) ) );
		$oslug    = trim( (string) ( $r['owner_slug'] ?? '' ) );
		// Recover core handles that carry no src (aliases like `jquery`, inline/
		// placeholder core styles like `wp-block-library`, `wp-emoji-styles`):
		// they cannot be attributed by path, but WordPress' own default registries
		// list them authoritatively — no guessing. Normalize core to the same
		// 'wordpress-core' identifier used elsewhere so it is never shown "unknown".
		if ( '' === $otype || 'unknown' === $otype ) {
			$up = wpoptimizing_asset_owner_from_upload_dir( (string) ( $r['src_rel'] ?? '' ), wpoptimizing_installed_slug_index() );
			if ( isset( $core[ $handle ] ) ) {
				$otype = 'core';
			} elseif ( null !== $up ) {
				// Generated into uploads/: the stored path names the owner, so rows already in
				// the inventory are fixed without a new capture.
				$otype = $up['type'];
				$oslug = $up['slug'];
			} else {
				// No src and not core: attribute by handle prefix against installed
				// slugs (e.g. woocommerce-inline → woocommerce, storefront-fonts →
				// storefront). Only matches a real installed slug at a '-' boundary.
				$own = wpoptimizing_asset_owner_by_handle( $handle );
				if ( null !== $own ) {
					$otype = $own['type'];
					$oslug = $own['slug'];
				}
			}
		}
		if ( 'core' === $otype ) {
			$oslug = 'wordpress-core';
		}
		$out[] = array(
			'kind'       => $kind,
			'handle'     => $handle,
			'owner_type' => $otype,
			'owner_slug' => $oslug,
			'src'        => trim( (string) ( $r['src_rel'] ?? '' ) ),
			'deps'       => $deps,
		);
	}
	return $out;
}

/**
 * Authoritative set of WordPress core script/style handles, built from WP's own
 * default registries (no hardcoded list, version-correct). Used to attribute core
 * assets that register with no src (aliases, inline/placeholder styles) and would
 * otherwise show as "unknown". Cached per request.
 *
 * @return array<string,bool> handle => true
 */
function wpoptimizing_core_asset_handles() {
	static $set = null;
	if ( null !== $set ) {
		return $set;
	}
	$set = array();
	if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
		return $set; // WP not loaded (e.g. unit tests): skip core recovery.
	}
	// wp-settings.php loads the script loader on every request; nothing to require.
	if ( ! class_exists( 'WP_Scripts' ) || ! class_exists( 'WP_Styles' ) || ! function_exists( 'wp_default_scripts' ) || ! function_exists( 'wp_default_styles' ) ) {
		return $set;
	}
	try {
		$s = new WP_Scripts();
		wp_default_scripts( $s );
		if ( function_exists( 'wp_default_packages' ) ) {
			wp_default_packages( $s );
		}
		foreach ( array_keys( $s->registered ) as $h ) {
			$set[ (string) $h ] = true;
		}
		$st = new WP_Styles();
		wp_default_styles( $st );
		foreach ( array_keys( $st->registered ) as $h ) {
			$set[ (string) $h ] = true;
		}
	} catch ( \Throwable $e ) {
		$set = array();
	}
	return $set;
}

/**
 * Installed plugin/theme slugs, lower-cased, for handle-prefix attribution.
 *
 * Kept in an AUTOLOADED option, not only per request: building it is get_plugins() plus
 * wp_get_themes(), which read the header of every plugin and theme on disk, and the automatic
 * unload asked for it on visitor pages. It is rebuilt when a plugin or theme is installed,
 * updated, switched or deleted, and at most a day after anything that skipped those hooks
 * (a plugin copied over FTP).
 *
 * @return array<string,array{0:string,1:string}> lc-slug => [type, original-slug]
 */
function wpoptimizing_installed_slug_index() {
	static $idx = null;
	if ( null !== $idx ) {
		return $idx;
	}
	$stored = get_option( 'wpoptimizing_slug_index', 0 );
	if ( is_array( $stored ) && isset( $stored['idx'], $stored['ts'] ) && is_array( $stored['idx'] ) && ( time() - (int) $stored['ts'] ) < DAY_IN_SECONDS ) {
		$idx = $stored['idx'];
		return $idx;
	}
	$idx = wpoptimizing_build_installed_slug_index();
	update_option(
		'wpoptimizing_slug_index',
		array(
			'ts'  => time(),
			'idx' => $idx,
		),
		true
	);
	return $idx;
}

/**
 * Mark the stored slug index stale. Kept present (0), not deleted: a missing option is a query
 * on every request that asks for it.
 *
 * @return void
 */
function wpoptimizing_installed_slug_index_reset() {
	update_option( 'wpoptimizing_slug_index', 0, true );
}
foreach ( array( 'activated_plugin', 'deactivated_plugin', 'deleted_plugin', 'switch_theme', 'deleted_theme', 'upgrader_process_complete' ) as $wpoptimizing_hook ) {
	add_action( $wpoptimizing_hook, 'wpoptimizing_installed_slug_index_reset', 10, 0 );
}
unset( $wpoptimizing_hook );

/**
 * Build the slug index from what is installed on disk.
 *
 * @return array<string,array{0:string,1:string}>
 */
function wpoptimizing_build_installed_slug_index() {
	$idx = array();
	if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( function_exists( 'get_plugins' ) ) {
		foreach ( array_keys( (array) get_plugins() ) as $file ) {
			$slug = dirname( (string) $file );
			if ( '.' === $slug || '' === $slug ) {
				$slug = basename( (string) $file, '.php' );
			}
			if ( '' !== $slug ) {
				$idx[ strtolower( $slug ) ] = array( 'plugin', $slug );
			}
		}
	}
	if ( function_exists( 'wp_get_themes' ) ) {
		foreach ( array_keys( (array) wp_get_themes() ) as $slug ) {
			if ( '' !== (string) $slug ) {
				$idx[ strtolower( (string) $slug ) ] = array( 'theme', (string) $slug );
			}
		}
	}
	return $idx;
}

/**
 * Attribute a src-less asset handle by matching it against an installed slug at a
 * '-' boundary (longest wins): e.g. woocommerce-inline → woocommerce,
 * storefront-fonts → storefront. Returns null when no installed slug matches, so
 * unrelated handles stay unattributed rather than mislabelled.
 *
 * @param string $handle Asset handle.
 * @return array{type:string,slug:string}|null
 */
function wpoptimizing_asset_owner_by_handle( $handle ) {
	$h = strtolower( trim( (string) $handle ) );
	if ( '' === $h ) {
		return null;
	}
	$idx  = wpoptimizing_installed_slug_index();
	$best = '';
	foreach ( $idx as $slug => $info ) {
		if ( ( $h === $slug || 0 === strpos( $h, $slug . '-' ) ) && strlen( $slug ) > strlen( $best ) ) {
			$best = $slug;
		}
	}
	if ( '' === $best ) {
		return null;
	}
	return array(
		'type' => $idx[ $best ][0],
		'slug' => $idx[ $best ][1],
	);
}

/**
 * Group parsed asset rows by owner (plugin/theme) into a sortable summary.
 *
 * @param array $rows Output of wpoptimizing_assets_parse_registry().
 * @return array{owners:array<int,array{owner_type:string,owner_slug:string,scripts:int,styles:int,total:int,handles:string[]}>,totals:array{scripts:int,styles:int,owners:int}}
 */
function wpoptimizing_assets_summary( array $rows ) {
	$by_owner = array();
	$seen     = array(); // de-dupe identical (owner|kind|handle) rows from many samples
	$t_script = 0;
	$t_style  = 0;

	foreach ( $rows as $r ) {
		$owner_slug = '' !== $r['owner_slug'] ? $r['owner_slug'] : '(unknown)';
		$key        = $r['owner_type'] . '|' . $owner_slug;
		$dedupe     = $key . '|' . $r['kind'] . '|' . $r['handle'];
		if ( isset( $seen[ $dedupe ] ) ) {
			continue;
		}
		$seen[ $dedupe ] = true;

		if ( ! isset( $by_owner[ $key ] ) ) {
			$by_owner[ $key ] = array(
				'owner_type' => $r['owner_type'],
				'owner_slug' => $owner_slug,
				'scripts'    => 0,
				'styles'     => 0,
				'total'      => 0,
				'handles'    => array(),
			);
		}
		if ( 'script' === $r['kind'] ) {
			++$by_owner[ $key ]['scripts'];
			++$t_script;
		} else {
			++$by_owner[ $key ]['styles'];
			++$t_style;
		}
		++$by_owner[ $key ]['total'];
		$by_owner[ $key ]['handles'][] = $r['handle'];
	}

	$owners = array_values( $by_owner );
	usort(
		$owners,
		static function ( $a, $b ) {
			if ( $a['total'] === $b['total'] ) {
				return strcmp( $a['owner_slug'], $b['owner_slug'] );
			}
			return $b['total'] <=> $a['total'];
		}
	);

	return array(
		'owners' => $owners,
		'totals' => array(
			'scripts' => $t_script,
			'styles'  => $t_style,
			'owners'  => count( $owners ),
		),
	);
}

/**
 * Read + summarize the local assets registry. Returns the empty summary when the
 * registry does not exist yet (collection is sampled and opt-in).
 *
 * @return array Summary as returned by wpoptimizing_assets_summary().
 */
function wpoptimizing_assets_inventory() {
	$path = defined( 'WPOPT_CSV_ASSETS' ) ? WPOPT_CSV_ASSETS : '';
	$csv  = ( '' !== $path && is_readable( $path ) ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	return wpoptimizing_assets_summary( wpoptimizing_assets_parse_registry( $csv ) );
}

/**
 * The de-duplicated individual asset handles recorded for this site, sorted by owner
 * then handle. Used by the manual-unload UI (one checkbox per handle).
 *
 * @return array<int,array{kind:string,handle:string,owner_type:string,owner_slug:string}>
 */
function wpoptimizing_assets_inventory_rows() {
	$path = defined( 'WPOPT_CSV_ASSETS' ) ? WPOPT_CSV_ASSETS : '';
	$csv  = ( '' !== $path && is_readable( $path ) ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$rows = wpoptimizing_assets_parse_registry( $csv );

	$seen = array();
	$out  = array();
	foreach ( $rows as $r ) {
		$key = $r['kind'] . '|' . $r['handle'];
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$row          = array(
			'kind'       => $r['kind'],
			'handle'     => $r['handle'],
			'owner_type' => $r['owner_type'],
			'owner_slug' => '' !== $r['owner_slug'] ? $r['owner_slug'] : '(unknown)',
		);

		// A handle this site cannot name is the one row the user cannot act on. Ask the
		// crowd: the shared registry very often names it. The suggestion is kept SEPARATE
		// from the local attribution (kb_* keys) — it is shown as what it is, an outside
		// opinion, and never silently promoted to fact.
		if ( '(unknown)' === $row['owner_slug'] && function_exists( 'wpoptimizing_assets_kb_owner' ) ) {
			$kb = wpoptimizing_assets_kb_owner( $r['kind'], $r['handle'] );
			if ( 'sin_datos' !== $kb['veredicto'] ) {
				$row['kb_veredicto']  = $kb['veredicto'];
				$row['kb_owner_slug'] = $kb['owner_slug'];
				$row['kb_owner_type'] = $kb['owner_type'];
				$row['kb_sites']      = $kb['sites'];
			}
		}

		$out[] = $row;
	}
	usort(
		$out,
		static function ( $a, $b ) {
			$o = strcmp( $a['owner_slug'], $b['owner_slug'] );
			return ( 0 !== $o ) ? $o : strcmp( $a['handle'], $b['handle'] );
		}
	);
	return $out;
}

/*
─────────────────────────── Manual unloading (free) ─────────────────────────
 * Phase 2, manual tier: the user picks specific handles to stop loading, optionally
 * scoped to a context. The KB-driven AUTOMATIC unloading is a separate, premium
 * feature. This engine is OFF by default and a NO-OP until the user both enables it
 * and adds rules, and a hard safety list protects handles that would break the site.
 * Pure helpers are unit-tested; the front-end hook is a thin wrapper. */

/**
 * Handles that must NEVER be dequeued (would break the site): jQuery and the core
 * script foundation, the admin bar, etc. Filterable so power users can extend it.
 *
 * @return array<int,string> Lowercased handles.
 */
function wpoptimizing_assets_safe_handles() {
	$safe = array(
		'jquery',
		'jquery-core',
		'jquery-migrate',
		'wp-hooks',
		'wp-i18n',
		'wp-polyfill',
		'wp-polyfill-inert',
		'regenerator-runtime',
		'wp-dom-ready',
		'wp-element',
		'wp-url',
		'wp-api-fetch',
		'admin-bar',
		'wp-a11y',
	);
	/**
	 * Filters the handles that manual unloading must never dequeue.
	 *
	 * @param string[] $safe Lowercased handle names.
	 */
	return array_map( 'strval', (array) apply_filters( 'wpoptimizing_assets_safe_handles', $safe ) );
}

/**
 * Normalize one raw rule into a known shape, or null if it is not usable.
 *
 * @param mixed $rule Raw rule (expects handle, kind, scope, only).
 * @return array{handle:string,kind:string,scope:string,only:string[]}|null
 */
function wpoptimizing_assets_sanitize_rule( $rule ) {
	if ( ! is_array( $rule ) ) {
		return null;
	}
	$handle = strtolower( trim( (string) ( $rule['handle'] ?? '' ) ) );
	if ( '' === $handle ) {
		return null;
	}
	$kind  = ( 'style' === ( $rule['kind'] ?? '' ) ) ? 'style' : 'script';
	$scope = (string) ( $rule['scope'] ?? 'everywhere' );
	$ok    = ( in_array( $scope, array( 'everywhere', 'front_page', 'singular', 'archive' ), true )
		|| ( 0 === strpos( $scope, 'post_type:' ) && strlen( $scope ) > 10 ) );
	if ( ! $ok ) {
		$scope = 'everywhere';
	}
	// Positive URL restriction ("unload ONLY on these URLs"): same wildcard patterns as the
	// exclusions. When set, the rule applies solely where the request path matches one.
	$only = function_exists( 'wpoptimizing_assets_normalize_url_patterns' )
		? wpoptimizing_assets_normalize_url_patterns( $rule['only'] ?? array() )
		: array();
	return array(
		'handle' => $handle,
		'kind'   => $kind,
		'scope'  => $scope,
		'only'   => $only,
	);
}

/**
 * The current set of sanitized manual-unload rules.
 *
 * @return array<int,array{handle:string,kind:string,scope:string}>
 */
function wpoptimizing_assets_get_rules() {
	$raw = get_option( 'wpoptimizing_assets_rules', array() );
	$out = array();
	foreach ( (array) $raw as $r ) {
		$rule = wpoptimizing_assets_sanitize_rule( $r );
		if ( null !== $rule ) {
			$out[] = $rule;
		}
	}
	return $out;
}

/**
 * Does a rule apply on the current front-end context? A rule applies when its SCOPE matches the
 * context AND — when it carries a positive URL restriction ("only") — the request path matches
 * one of those wildcard patterns. Scope and "only" are ANDed, so "singular + only /shop/*" means
 * "on singular views whose URL is under /shop".
 *
 * @param array $rule Sanitized rule.
 * @param array $ctx  Context: front_page, singular, archive (bool), post_type + url (string).
 * @return bool
 */
function wpoptimizing_assets_rule_matches_context( array $rule, array $ctx ) {
	if ( ! wpoptimizing_assets_scope_matches( (string) ( $rule['scope'] ?? 'everywhere' ), $ctx ) ) {
		return false;
	}
	$only = (array) ( $rule['only'] ?? array() );
	if ( ! empty( $only ) && function_exists( 'wpoptimizing_assets_url_matches_any' )
		&& ! wpoptimizing_assets_url_matches_any( (string) ( $ctx['url'] ?? '' ), $only ) ) {
		return false;
	}
	return true;
}

/**
 * Does a scope enum match the current context? Pure.
 *
 * @param string $scope everywhere|front_page|singular|archive|post_type:X.
 * @param array  $ctx   Context flags.
 * @return bool
 */
function wpoptimizing_assets_scope_matches( $scope, array $ctx ) {
	$scope = (string) $scope;
	if ( 'everywhere' === $scope ) {
		return true;
	}
	if ( 'front_page' === $scope ) {
		return ! empty( $ctx['front_page'] );
	}
	if ( 'singular' === $scope ) {
		return ! empty( $ctx['singular'] );
	}
	if ( 'archive' === $scope ) {
		return ! empty( $ctx['archive'] );
	}
	if ( 0 === strpos( $scope, 'post_type:' ) ) {
		return ! empty( $ctx['singular'] ) && substr( $scope, 10 ) === (string) ( $ctx['post_type'] ?? '' );
	}
	return false;
}

/**
 * Resolve the current front-end context for rule matching.
 *
 * @return array{front_page:bool,singular:bool,archive:bool,post_type:string,url:string}
 */
function wpoptimizing_assets_current_context() {
	return array(
		'front_page' => function_exists( 'is_front_page' ) ? (bool) is_front_page() : false,
		'singular'   => function_exists( 'is_singular' ) ? (bool) is_singular() : false,
		'archive'    => function_exists( 'is_archive' ) ? (bool) ( is_archive() || ( function_exists( 'is_home' ) && is_home() ) ) : false,
		'post_type'  => function_exists( 'get_post_type' ) ? (string) get_post_type() : '',
		'url'        => function_exists( 'wpoptimizing_assets_request_path' ) ? wpoptimizing_assets_request_path() : '',
	);
}

/*
─────────────────── URL exclusions (D3: "unload everywhere except X") ─────────
 * A global list of URL patterns that are PROTECTED from every kind of unloading (manual,
 * automatic and per-page). The common real-world need — "strip assets site-wide but never on
 * the checkout / a critical landing page". Patterns are simple wildcard globs (`*`), matched
 * as a substring of the request path, so over-matching only ever protects MORE (the safe bias).
 * All matching is pure and offline-testable. */

/**
 * Split a raw URL-pattern input (textarea / comma list) into clean patterns. A pasted full URL
 * is reduced to its path. `*` is the only wildcard. Caps count + length so a huge paste cannot
 * bloat the option or slow matching. Pure.
 *
 * @param mixed $raw String (newline/comma separated) or array.
 * @return string[] Clean, unique patterns.
 */
function wpoptimizing_assets_normalize_url_patterns( $raw ) {
	if ( is_string( $raw ) ) {
		$parts = preg_split( '/[\r\n,]+/', $raw );
	} elseif ( is_array( $raw ) ) {
		$parts = $raw;
	} else {
		$parts = array();
	}
	$out  = array();
	$seen = array();
	foreach ( (array) $parts as $p ) {
		$p = trim( (string) $p );
		if ( '' === $p ) {
			continue;
		}
		// Reduce a pasted full URL to its path (keep any wildcard the user added).
		if ( preg_match( '#^https?://[^/]+(/.*)?$#i', $p, $m ) ) {
			$p = ( isset( $m[1] ) && '' !== $m[1] ) ? $m[1] : '/';
		}
		// Keep only the characters a URL path pattern needs.
		$p = preg_replace( '#[^\w\-/*.?=&%~:]#', '', (string) $p );
		if ( '' === $p ) {
			continue;
		}
		$p   = substr( $p, 0, 255 );
		$key = strtolower( $p );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[]        = $p;
		if ( count( $out ) >= 100 ) {
			break;
		}
	}
	return $out;
}

/**
 * Compile one wildcard pattern into a safe, non-anchored, case-insensitive regex. Everything is
 * quoted except `*`, which becomes `.*`, so a user pattern can never inject regex or cause a
 * catastrophic backtrack. Returns '' for an empty pattern. Pure.
 *
 * @param string $pattern Wildcard pattern.
 * @return string PCRE (delimited) or ''.
 */
function wpoptimizing_assets_url_pattern_to_regex( $pattern ) {
	$pattern = (string) $pattern;
	if ( '' === $pattern ) {
		return '';
	}
	$rx = preg_quote( $pattern, '#' );
	$rx = str_replace( '\*', '.*', $rx );
	return '#' . $rx . '#i';
}

/**
 * Does the path match ANY of the wildcard patterns (substring match). Pure.
 *
 * @param string   $path     Request path.
 * @param string[] $patterns Wildcard patterns.
 * @return bool
 */
function wpoptimizing_assets_url_matches_any( $path, array $patterns ) {
	$path = (string) $path;
	foreach ( $patterns as $pat ) {
		$rx = wpoptimizing_assets_url_pattern_to_regex( $pat );
		if ( '' !== $rx && preg_match( $rx, $path ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Current request path (no host, no query), defaulting to '/'.
 *
 * @return string
 */
function wpoptimizing_assets_request_path() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	return '' !== $path ? $path : '/';
}

/**
 * Path part of each page's permalink, translations included, ready to use as an exclusion.
 *
 * A page that does not exist (id 0, deleted, unpublished) gives nothing, and neither does the
 * front page: its path is "/", which as a substring pattern would match every URL on the site.
 *
 * @param int[] $ids Page IDs.
 * @return string[]
 */
function wpoptimizing_assets_page_paths( array $ids ) {
	$all = array();
	foreach ( $ids as $id ) {
		$id = (int) $id;
		if ( $id <= 0 ) {
			continue;
		}
		$all[ $id ] = true;
		// WPML: the same page in every active language.
		$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API.
		foreach ( is_array( $langs ) ? array_keys( $langs ) : array() as $code ) {
			$tr = (int) apply_filters( 'wpml_object_id', $id, get_post_type( $id ), false, $code ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API.
			if ( $tr > 0 ) {
				$all[ $tr ] = true;
			}
		}
		// Polylang.
		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( (array) pll_get_post_translations( $id ) as $tr ) {
				$all[ (int) $tr ] = true;
			}
		}
	}
	$paths = array();
	foreach ( array_keys( $all ) as $id ) {
		if ( 'publish' !== get_post_status( $id ) ) {
			continue;
		}
		$path = (string) wp_parse_url( (string) get_permalink( $id ), PHP_URL_PATH );
		if ( '' !== $path && '/' !== $path ) {
			$paths[ $path ] = true;
		}
	}
	return array_keys( $paths );
}

/**
 * Exclusion presets built from THIS site: the real pages of the plugins that are installed.
 *
 * They used to be fixed English paths (/cart, /checkout, /contact…) offered whatever was
 * installed. On a Spanish WooCommerce shop the cart is /carrito/, so the button added three
 * patterns that matched nothing, and buttons for LMS or Easy Digital Downloads showed on sites
 * without them. Now each preset reads where the pages actually are, and one with nothing to
 * add is not offered. Filterable so add-ons can extend them; applying a preset just appends its
 * patterns to the exclusion list.
 *
 * @return array<string,array{label:string,patterns:string[]}>
 */
function wpoptimizing_assets_exclusion_presets() {
	$presets = array();

	if ( function_exists( 'wc_get_page_id' ) ) {
		$presets['woocommerce'] = array(
			'label'    => __( 'WooCommerce (cart, checkout, account)', 'wpoptimizing' ),
			'patterns' => wpoptimizing_assets_page_paths( array( wc_get_page_id( 'cart' ), wc_get_page_id( 'checkout' ), wc_get_page_id( 'myaccount' ) ) ),
		);
	}

	if ( function_exists( 'edd_get_option' ) ) {
		$ids = array();
		foreach ( array( 'purchase_page', 'success_page', 'failure_page', 'purchase_history_page', 'login_page' ) as $key ) {
			$ids[] = (int) edd_get_option( $key, 0 );
		}
		$presets['edd'] = array(
			'label'    => __( 'Easy Digital Downloads (checkout, account)', 'wpoptimizing' ),
			'patterns' => wpoptimizing_assets_page_paths( $ids ),
		);
	}

	// LMS: the URL base of each course, lesson and quiz type that is registered here.
	$lms = array();
	foreach ( array( 'sfwd-courses', 'sfwd-lessons', 'sfwd-topic', 'sfwd-quiz', 'course', 'lesson', 'llms_quiz', 'courses', 'tutor_quiz', 'lp_course', 'lp_lesson', 'lp_quiz', 'quiz' ) as $type ) {
		$obj = post_type_exists( $type ) ? get_post_type_object( $type ) : null;
		if ( ! $obj || empty( $obj->public ) || empty( $obj->rewrite['slug'] ) ) {
			continue;
		}
		$slug = trim( (string) $obj->rewrite['slug'], '/' );
		if ( '' !== $slug ) {
			$lms[ '/' . $slug . '/' ] = true;
		}
	}
	$presets['lms'] = array(
		'label'    => __( 'Courses / LMS (course, lesson, quiz)', 'wpoptimizing' ),
		'patterns' => array_keys( $lms ),
	);

	// Contact and booking: the published pages that hold a form of a plugin that is active.
	$markers   = array();
	$active    = array(
		'contact-form-7' => defined( 'WPCF7_VERSION' ),
		'wpforms'        => function_exists( 'wpforms' ),
		'gravityforms'   => class_exists( 'GFForms' ),
		'ninja-forms'    => class_exists( 'Ninja_Forms' ),
		'fluentform'     => defined( 'FLUENTFORM' ),
		'formidable'     => class_exists( 'FrmAppHelper' ),
		'avada-forms'    => defined( 'FUSION_BUILDER_VERSION' ),
		'amelia'         => defined( 'AMELIA_VERSION' ),
		'bookly'         => class_exists( '\Bookly\Lib\Plugin' ),
	);
	$by_plugin = array(
		'contact-form-7' => array( '[contact-form-7', '<!-- wp:contact-form-7/' ),
		'wpforms'        => array( '[wpforms', '<!-- wp:wpforms/' ),
		'gravityforms'   => array( '[gravityform', '<!-- wp:gravityforms/' ),
		'ninja-forms'    => array( '[ninja_form', '<!-- wp:ninja-forms/' ),
		'fluentform'     => array( '[fluentform', '<!-- wp:fluentfom/' ),
		'formidable'     => array( '[formidable', '<!-- wp:formidable/' ),
		'avada-forms'    => array( '[fusion_form ' ),
		'amelia'         => array( '[ameliabooking', '[ameliastepbooking', '<!-- wp:amelia/' ),
		'bookly'         => array( '[bookly-form', '<!-- wp:bookly/' ),
	);
	foreach ( $active as $plugin => $on ) {
		if ( $on ) {
			$markers = array_merge( $markers, $by_plugin[ $plugin ] );
		}
	}
	$form_ids = array();
	if ( ! empty( $markers ) ) {
		global $wpdb;
		$like = array();
		foreach ( $markers as $m ) {
			$like[] = '%' . $wpdb->esc_like( $m ) . '%';
		}
		$where    = implode( ' OR ', array_fill( 0, count( $like ), 'post_content LIKE %s' ) );
		$form_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( {$where} ) LIMIT 20", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where is only "post_content LIKE %s", once per marker; settings screen only.
	}
	if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
		global $wpdb;
		// Elementor keeps the layout in post meta, not in the content.
		$form_ids = array_merge( (array) $form_ids, (array) $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_type = 'page' AND p.post_status = 'publish' AND m.meta_value LIKE %s LIMIT 20", '%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
	}
	$presets['forms'] = array(
		'label'    => __( 'Contact / booking pages', 'wpoptimizing' ),
		'patterns' => wpoptimizing_assets_page_paths( array_map( 'intval', (array) $form_ids ) ),
	);

	$presets = (array) apply_filters( 'wpoptimizing_assets_exclusion_presets', $presets );

	// A preset with nothing to add is not offered.
	return array_filter(
		$presets,
		static function ( $preset ) {
			return is_array( $preset ) && ! empty( $preset['patterns'] );
		}
	);
}

/**
 * Is the CURRENT (or given) request path protected from all asset unloading?
 *
 * @param string|null $path Path to test (defaults to the current request).
 * @return bool
 */
function wpoptimizing_assets_url_excluded( $path = null ) {
	$patterns = wpoptimizing_assets_normalize_url_patterns( get_option( 'wpoptimizing_assets_url_exclusions', '' ) );
	if ( empty( $patterns ) ) {
		return false;
	}
	if ( null === $path ) {
		$path = wpoptimizing_assets_request_path();
	}
	return wpoptimizing_assets_url_matches_any( $path, $patterns );
}



