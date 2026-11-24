<?php
/**
 * Manual attribution — local, user-defined ownership for items the dataset/heuristics
 * could not attribute (they sit in "Pending").
 *
 * The site owner can assert that a value belongs to a plugin/theme, either:
 *   - exact:    a specific identifier (option name, meta key, table, file path…), or
 *   - contains: any item whose identifier contains a substring (e.g. "wysija" → MailPoet,
 *               "b2s" → Blog2Social).
 *
 * Rules are applied at the display/classification layer (see get_row_project_pairs), so a
 * matched item leaves Pending immediately — no re-scan — and is routed to Inactive (owner
 * installed) or Orphan (owner gone) by the existing status logic. Stored locally only; the
 * `wpoptimizing_manual_attribution_added` action lets the KB-builder pipeline pick leads up
 * later without changing this layer.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * La clase de una regla manual, normalizada contra el vocabulario.
 *
 * ESTO COLAPSABA A DOS VALORES, EN TRES SITIOS DE ESTE FICHERO: todo lo que no fuera
 * literalmente 'theme' se guardaba como 'plugin'. Y es el peor sitio posible para perder la
 * clase, porque una regla manual manda sobre TODAS las automaticas, incluido el conjunto
 * compartido: lo que se escribe aqui no lo corrige nada despues.
 *
 * Lo destapo aceptar una sugerencia de biblioteca empaquetada —el motor del gateway manda
 * `library` en el candidato— que llegaba hasta aqui intacta y se guardaba como 'plugin'.
 *
 * Lo de fuera del vocabulario sigue cayendo en 'plugin', que es lo que hacia antes: no se
 * inyecta nunca un origen que la columna «Extension» no sepa pintar.
 *
 * @param mixed $tipo Clase propuesta.
 * @return string Una clase del vocabulario.
 */
function wpoptimizing_manual_attribution_type( $tipo ) {
	$tipo = is_string( $tipo ) ? $tipo : '';

	return wpoptimizing_attribution_origin_exists( $tipo ) ? $tipo : 'plugin';
}

/**
 * All manual attribution rules, sanitized.
 *
 * @param bool $flush Drop the per-request cache instead of returning rules (writers only).
 * @return array<int,array{scope:string,match:string,slug:string,name:string,type:string}>
 */
function wpoptimizing_manual_attributions( $flush = false ) {
	// Per-request cache. This is called twice for every row of the Pending/Orphans tables
	// (once by the resolver, once by the renderer), and each call used to re-sanitize the
	// whole rule set — sanitize_title() per rule, per row. On a table of a few thousand rows
	// with a handful of rules that is hundreds of thousands of redundant calls, and the
	// result cannot change within one request. Invalidated by the option hooks at the end of
	// this file, so a direct update_option() (settings import) flushes it too.
	static $cache = null;
	if ( $flush ) {
		$cache = null;
		return array();
	}
	if ( null !== $cache ) {
		return $cache;
	}

	$raw = get_option( 'wpoptimizing_manual_attributions', array() );
	if ( ! is_array( $raw ) ) {
		$cache = array();
		return $cache;
	}
	$out = array();
	foreach ( $raw as $r ) {
		if ( ! is_array( $r ) ) {
			continue;
		}
		$match = trim( (string) ( $r['match'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $r['slug'] ?? '' ) );
		if ( '' === $match || '' === $slug ) {
			continue;
		}
		$out[] = array(
			'scope' => ( 'exact' === ( $r['scope'] ?? '' ) ) ? 'exact' : 'contains',
			'match' => $match,
			'slug'  => $slug,
			'name'  => trim( (string) ( $r['name'] ?? '' ) ),
			'type'  => wpoptimizing_manual_attribution_type( $r['type'] ?? '' ),
		);
	}
	$cache = $out;
	return $cache;
}


/**
 * Persist the full set of rules (already in the shape returned above).
 *
 * @param array $rules Rules.
 * @return void
 */
function wpoptimizing_manual_attributions_save( array $rules ) {
	$clean = array();
	foreach ( $rules as $r ) {
		$match = trim( (string) ( $r['match'] ?? '' ) );
		$slug  = sanitize_title( (string) ( $r['slug'] ?? '' ) );
		if ( '' === $match || '' === $slug ) {
			continue;
		}
		$clean[] = array(
			'scope' => ( 'exact' === ( $r['scope'] ?? '' ) ) ? 'exact' : 'contains',
			'match' => $match,
			'slug'  => $slug,
			'name'  => trim( (string) ( $r['name'] ?? '' ) ),
			'type'  => wpoptimizing_manual_attribution_type( $r['type'] ?? '' ),
		);
	}
	update_option( 'wpoptimizing_manual_attributions', array_values( $clean ), false );

	// Flush here too, not only through the option hooks below. update_option() fires nothing
	// when the stored value is unchanged, and a writer that leaves a stale per-request cache
	// behind is only correct for as long as a hook registered elsewhere keeps working. The
	// hooks stay because they catch the direct writes this helper never sees (the settings
	// importer); this catches every write that goes through the helper.
	wpoptimizing_manual_attributions( true );
}

// Invalidate the per-request cache on the option itself rather than in this file's save
// helper: the settings importer (settings-portability.php) writes the option directly, so
// hooking the writer would miss it and leave a stale rule set for the rest of the request.
add_action(
	'update_option_wpoptimizing_manual_attributions',
	static function () {
		wpoptimizing_manual_attributions( true );
	}
);
add_action(
	'add_option_wpoptimizing_manual_attributions',
	static function () {
		wpoptimizing_manual_attributions( true );
	}
);

/**
 * Add one rule (de-duplicated) and fire a lead action for the KB-builder pipeline.
 *
 * @param array $rule { scope, match, slug, name, type }.
 * @return bool True if added.
 */
function wpoptimizing_manual_attribution_add( array $rule ) {
	$match = trim( (string) ( $rule['match'] ?? '' ) );
	$slug  = sanitize_title( (string) ( $rule['slug'] ?? '' ) );
	if ( '' === $match || '' === $slug ) {
		return false;
	}
	$rule  = array(
		'scope' => ( 'exact' === ( $rule['scope'] ?? '' ) ) ? 'exact' : 'contains',
		'match' => $match,
		'slug'  => $slug,
		'name'  => trim( (string) ( $rule['name'] ?? '' ) ),
		'type'  => wpoptimizing_manual_attribution_type( $rule['type'] ?? '' ),
	);
	$rules = wpoptimizing_manual_attributions();
	foreach ( $rules as $existing ) {
		// The owner is its class AND its slug: the theme `acme` and the plugin `acme` are two.
		if ( $existing['scope'] === $rule['scope'] && strtolower( $existing['match'] ) === strtolower( $rule['match'] ) && $existing['slug'] === $rule['slug'] && ( $existing['type'] ?? 'plugin' ) === $rule['type'] ) {
			return false; // already present.
		}
	}
	$rules[] = $rule;
	wpoptimizing_manual_attributions_save( $rules );

	/**
	 * Fires when the owner manually attributes content. The KB-builder ("alimentador")
	 * pipeline can hook this to receive the lead (install the named extension and deep-scan
	 * it), so manual hints feed the knowledge base as verified data rather than raw input.
	 *
	 * @since 0.1.0
	 * @param array $rule The added rule.
	 */
	// Attributing a row correctly REMOVES it from the orphan count, so every card that shows
	// that count is now wrong until this is dropped.
	if ( function_exists( 'wpoptimizing_flush_kpi_cache' ) ) {
		wpoptimizing_flush_kpi_cache();
	}

	do_action( 'wpoptimizing_manual_attribution_added', $rule );
	return true;
}

/**
 * Resolve ALL manual owners for an identifier (a value can belong to several, e.g.
 * "wysija" → MailPoet AND MailPoet Premium). Exact matches are listed first, then
 * "contains" matches; de-duplicated by owner slug. Matching is case-insensitive.
 *
 * @param string $value Primary identifier (option name, meta key, table, hook, role…).
 * @param string $path  Optional file path (for filesystem rows).
 * @return array<int,array{origin:string,slug:string,name:string}>
 */
function wpoptimizing_manual_attribution_match_all( $value, $path = '' ) {
	$value = (string) $value;
	$path  = (string) $path;
	if ( '' === $value && '' === $path ) {
		return array();
	}
	$rules = wpoptimizing_manual_attributions();
	if ( empty( $rules ) ) {
		return array();
	}
	$hay_value = strtolower( $value );
	$hay_path  = strtolower( $path );
	$base      = '' !== $hay_path ? strtolower( basename( $path ) ) : '';
	$exact     = array();
	$contains  = array();
	$seen      = array();
	foreach ( $rules as $r ) {
		$needle = strtolower( $r['match'] );
		$owner = ( $r['type'] ?? 'plugin' ) . ':' . $r['slug'];
		if ( '' === $needle || isset( $seen[ $owner ] ) ) {
			continue;
		}
		$hit = false;
		if ( 'exact' === $r['scope'] ) {
			$hit = ( $hay_value === $needle || ( '' !== $hay_path && $hay_path === $needle ) || ( '' !== $base && $base === $needle ) );
		} elseif ( ( '' !== $hay_value && false !== strpos( $hay_value, $needle ) ) || ( '' !== $hay_path && false !== strpos( $hay_path, $needle ) ) ) {
			$hit = true;
		}
		if ( ! $hit ) {
			continue;
		}
		$seen[ $owner ] = true;
		$entry              = array(
			'origin' => $r['type'],
			'slug'   => $r['slug'],
			'name'   => $r['name'],
		);
		if ( 'exact' === $r['scope'] ) {
			$exact[] = $entry;
		} else {
			$contains[] = $entry;
		}
	}
	return array_merge( $exact, $contains );
}

/**
 * Stable identity for a rule, used by the Remove link instead of its position.
 *
 * (scope, lowercased match, slug, class) is exactly what wpoptimizing_manual_attribution_add()
 * de-duplicates on, so it is unique across the stored set and survives any reordering.
 *
 * Positions do NOT survive: removing a rule re-indexes everything after it, so a Remove link
 * rendered before that write points at a DIFFERENT rule afterwards — a stale tab, the browser
 * Back button or a second window would silently delete the wrong attribution.
 *
 * @param array $rule Rule.
 * @return string 16-char key, '' when the rule is incomplete.
 */
function wpoptimizing_manual_attribution_key( array $rule ) {
	$match = trim( (string) ( $rule['match'] ?? '' ) );
	$slug  = sanitize_title( (string) ( $rule['slug'] ?? '' ) );
	if ( '' === $match || '' === $slug ) {
		return '';
	}
	$scope = ( 'exact' === ( $rule['scope'] ?? '' ) ) ? 'exact' : 'contains';
	// The class joins the identity only when it is not the default, so a plugin rule keeps the
	// key it always had.
	$tipo = wpoptimizing_manual_attribution_type( $rule['type'] ?? '' );
	return substr( md5( $scope . "\0" . strtolower( $match ) . "\0" . $slug . ( 'plugin' !== $tipo ? "\0" . $tipo : '' ) ), 0, 16 );
}

/**
 * Remove the rule with this identity.
 *
 * Returns false (instead of deleting a neighbour) when the key matches nothing, which is what
 * a stale Remove link now does.
 *
 * @param string $key Key from wpoptimizing_manual_attribution_key().
 * @return bool True if a rule was removed.
 */
function wpoptimizing_manual_attribution_remove( $key ) {
	$key = (string) $key;
	if ( '' === $key ) {
		return false;
	}
	$rules = wpoptimizing_manual_attributions();
	$kept  = array();
	$hit   = false;
	foreach ( $rules as $r ) {
		if ( ! $hit && wpoptimizing_manual_attribution_key( $r ) === $key ) {
			$hit = true;
			continue;
		}
		$kept[] = $r;
	}
	if ( ! $hit ) {
		return false;
	}
	wpoptimizing_manual_attributions_save( $kept );

	// Dropping a rule sends its items BACK to Pending/Orphan, so every card showing those
	// counts is stale — the same reason add() flushes. Without this the success notice claims
	// the items were re-evaluated while the KPI cards still show the pre-removal totals.
	if ( function_exists( 'wpoptimizing_flush_kpi_cache' ) ) {
		wpoptimizing_flush_kpi_cache();
	}
	return true;
}

/**
 * Slugs of everything currently switched on: active plugins plus the active theme
 * (and its parent). Memoised per request.
 *
 * @param bool $flush Rebuild instead of returning the memoised index (a plugin activated
 *                    mid-request leaves it stale).
 * @return array<string,true> Lowercased slug => true.
 */
function wpoptimizing_manual_attribution_active_slugs( $flush = false ) {
	static $index = null;
	if ( $flush ) {
		// Invalidate and return, exactly like wpoptimizing_manual_attributions(): rebuilding
		// here would memoise whatever the store happens to say mid-write.
		$index = null;
		return array();
	}
	if ( null !== $index ) {
		return $index;
	}
	$index = array();

	if ( function_exists( 'wpoptimizing_active_plugin_files' ) ) {
		foreach ( wpoptimizing_active_plugin_files() as $file ) {
			$slug = strtolower( trim( (string) $file ) );
			if ( '' === $slug ) {
				continue;
			}
			// "akismet/akismet.php" → "akismet"; a single-file plugin → its basename.
			$slug           = ( false !== strpos( $slug, '/' ) )
				? substr( $slug, 0, strpos( $slug, '/' ) )
				: preg_replace( '/\.php$/', '', basename( $slug ) );
			$index[ $slug ] = true;
		}
	}

	if ( function_exists( 'wp_get_theme' ) ) {
		$theme = wp_get_theme();
		if ( $theme && ! is_wp_error( $theme ) ) {
			$index[ strtolower( (string) $theme->get_stylesheet() ) ] = true;
			$index[ strtolower( (string) $theme->get_template() ) ]   = true;
		}
	}

	unset( $index[''] );
	return $index;
}

/**
 * Refuse to delete an item the owner has manually attributed to something that is ACTIVE.
 *
 * Until this existed the protection was cosmetic: the tables suppress the checkbox on
 * active-owned rows, but the delete routine trusts the submitted "type::value" list and
 * consults nothing but this filter. A form rendered BEFORE the rule was added still carried
 * the tick, so the very items the owner had just declared "in use" were deleted anyway.
 *
 * Only an ACTIVE owner protects. A rule naming an uninstalled or switched-off plugin is a
 * statement about who owned the data, not a reason to keep it — the whole point of attributing
 * an orphan is to be able to delete it knowingly.
 *
 * @param bool   $allowed Whether deletion is allowed so far.
 * @param string $tipo    Item type.
 * @param string $valor   Item value/name.
 * @param string $entry   Raw "type::value" entry.
 * @return bool
 */
function wpoptimizing_manual_attribution_can_delete( $allowed, $tipo, $valor, $entry ) {
	unset( $tipo, $entry );
	if ( ! $allowed ) {
		return false; // Somebody else already protected it; never widen that.
	}
	$owners = wpoptimizing_manual_attribution_match_all( (string) $valor );
	if ( empty( $owners ) ) {
		return true;
	}
	$active = wpoptimizing_manual_attribution_active_slugs();
	foreach ( $owners as $owner ) {
		if ( isset( $active[ strtolower( (string) $owner['slug'] ) ] ) ) {
			return false;
		}
	}
	return true;
}
add_filter( 'wpoptimizing_can_delete_item', 'wpoptimizing_manual_attribution_can_delete', 5, 4 );

// A plugin switched on or off mid-request changes who is active, and the delete routine may
// run after that in the same pageload (bulk actions on the Plugins screen).
add_action(
	'update_option_active_plugins',
	static function () {
		wpoptimizing_manual_attribution_active_slugs( true );
	}
);
