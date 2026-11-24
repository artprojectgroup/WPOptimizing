<?php
/**
 * Smart Assets knowledge base: who owns a front-end script/style handle, according to
 * the sites that contribute their asset registry.
 *
 * A site only sees the handles ITS OWN plugins print, and a handle whose owner cannot be
 * resolved locally (a generic name, an asset enqueued by a library shared by several
 * plugins) shows up as unknown — which is exactly the row a user cannot act on. The
 * gateway aggregates every contributed registry into `wp-assets-distrib.csv`
 * (kind, handle, owner_type, owner_slug, src_rel, deps, source, sites), so a handle one
 * site cannot name is very often named by the hundreds that can.
 *
 * NEVER GUESS. If the crowd attributes the same handle to two different extensions, that
 * is a real conflict, and the aggregate keeps BOTH rows precisely so we can see it. A
 * wrong owner here does not merely mislabel a row: the user unloads an asset the page
 * needs, and the front end breaks. Ambiguity resolves to "no answer", not to a majority
 * vote.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse the curated cross-site asset registry.
 *
 * @param string $csv Raw CSV.
 * @return array<string,array<int,array{owner_type:string,owner_slug:string,sites:int}>> Map "kind|handle" => candidate owners.
 */
function wpoptimizing_assets_kb_parse( $csv ) {
	$out = array();
	if ( '' === $csv ) {
		return $out;
	}
	$lines  = preg_split( '/\r\n|\r|\n/', $csv );
	$header = null;
	foreach ( (array) $lines as $line ) {
		if ( '' === trim( (string) $line ) ) {
			continue;
		}
		$row = str_getcsv( (string) $line, ',', '"', '\\' );
		if ( null === $header ) {
			$header = array_flip( array_map( 'trim', (array) $row ) );
			// Refuse an aggregate we do not understand rather than mis-reading its columns.
			foreach ( array( 'kind', 'handle', 'owner_type', 'owner_slug', 'sites' ) as $needed ) {
				if ( ! isset( $header[ $needed ] ) ) {
					return array();
				}
			}
			continue;
		}
		$kind   = strtolower( trim( (string) ( $row[ $header['kind'] ] ?? '' ) ) );
		$handle = trim( (string) ( $row[ $header['handle'] ] ?? '' ) );
		// Una marca interna del alimentador no es el nombre de una extensión: ver wpoptimizing_non_extension_slugs().
		$slug = wpoptimizing_shared_slug( $row[ $header['owner_slug'] ] ?? '' );
		if ( '' === $handle || '' === $slug || ( 'script' !== $kind && 'style' !== $kind ) ) {
			continue; // Owner-less rows carry src/deps for the inventory, but name nobody.
		}
		$key           = $kind . '|' . strtolower( $handle );
		$out[ $key ][] = array(
			'owner_type' => strtolower( trim( (string) ( $row[ $header['owner_type'] ] ?? '' ) ) ),
			'owner_slug' => $slug,
			'sites'      => max( 1, (int) ( $row[ $header['sites'] ] ?? 1 ) ),
		);
	}
	return $out;
}

/**
 * Load + cache the parsed assets KB for this request, fetching the curated aggregate
 * from the gateway when the local copy is stale (daily).
 *
 * @param bool $force Bypass the in-request memo.
 * @return array<string,array<int,array>>
 */
function wpoptimizing_assets_kb( $force = false ) {
	static $memo = null;
	if ( null !== $memo && ! $force ) {
		return $memo;
	}
	$path  = trailingslashit( WPOPT_DIR ) . 'wp-assets-distrib.csv';
	$stale = ! file_exists( $path ) || ( time() - (int) filemtime( $path ) ) > DAY_IN_SECONDS;
	if ( $stale && function_exists( 'wpoptimizing_download_kb_file' ) ) {
		wpoptimizing_download_kb_file( 'wp-assets-distrib.csv', $path );
	}
	$csv  = file_exists( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$memo = wpoptimizing_assets_kb_parse( $csv );
	return $memo;
}

/**
 * Minimum sightings before a contributed attribution is worth showing.
 *
 * Deliberately 1: the site count is NOT what makes this trustworthy, and treating it as a
 * consensus threshold throws away almost everything for nothing. Each contributor derives
 * an owner from its own code (where the wp_enqueue call lives), so one site's answer is a
 * derivation, not an opinion to be out-voted. What actually protects the user is (a) the
 * conflict rule and (b) the installed check in wpoptimizing_assets_kb_owner(). Raise it if
 * you want a stricter tier.
 *
 * @return int
 */
function wpoptimizing_assets_kb_threshold() {
	/** @since 0.1.0 Minimum contributing sites before a crowd attribution is surfaced. */
	return max( 1, (int) apply_filters( 'wpoptimizing_assets_kb_threshold', 1 ) );
}

/**
 * Is this extension present on THIS site? Core always is.
 *
 * @param string $slug Owner slug.
 * @param string $type plugin|theme|core.
 * @return bool
 */
function wpoptimizing_assets_kb_owner_is_installed( $slug, $type ) {
	$slug = strtolower( trim( (string) $slug ) );
	if ( '' === $slug ) {
		return false;
	}
	if ( 'core' === strtolower( (string) $type ) || 'wordpress-core' === $slug ) {
		return true;
	}
	if ( ! function_exists( 'wpoptimizing_installed_slug_index' ) ) {
		return false; // Cannot verify ⇒ do not claim.
	}
	$installed = wpoptimizing_installed_slug_index();
	return isset( $installed[ $slug ] );
}

/**
 * Who owns this handle, according to the sites that share their registry?
 *
 * Verdicts:
 *   certain   — one owner, and that extension EXISTS on this site.
 *   ambiguo   — several contributors, several DIFFERENT owners. We refuse to pick one.
 *   sin_datos — nobody names it, too few sightings, or the named owner is not installed here.
 *
 * The installed check is the subtle one, and it is the whole point. That `select2` is
 * registered by UpdraftPlus on somebody else's site proves nothing about THIS site, where
 * the very same handle may well come from WooCommerce. A contributed owner is only
 * evidence about our own handle when that extension is actually here; if it is not, the
 * handle comes from something else and we do not know what. Saying nothing is the correct
 * answer — a wrong owner is how a user unloads an asset their page needed.
 *
 * @param string $kind   script|style.
 * @param string $handle Handle.
 * @return array{veredicto:string,owner_slug:string,owner_type:string,sites:int}
 */
function wpoptimizing_assets_kb_owner( $kind, $handle ) {
	$none = array(
		'veredicto'  => 'sin_datos',
		'owner_slug' => '',
		'owner_type' => '',
		'sites'      => 0,
	);

	$kb  = wpoptimizing_assets_kb();
	$key = strtolower( (string) $kind ) . '|' . strtolower( trim( (string) $handle ) );
	if ( empty( $kb[ $key ] ) ) {
		return $none;
	}

	// Collapse candidates by owner: the same owner reported by several rows (a static and a
	// runtime row, say) is ONE owner, not two.
	$owners = array();
	foreach ( $kb[ $key ] as $cand ) {
		$slug = $cand['owner_slug'];
		if ( ! isset( $owners[ $slug ] ) ) {
			$owners[ $slug ] = $cand;
			continue;
		}
		$owners[ $slug ]['sites'] = max( (int) $owners[ $slug ]['sites'], (int) $cand['sites'] );
	}

	// Two different extensions claim the same handle. That is a genuine conflict and the
	// answer is "we do not know" — picking the more popular one is how a user ends up
	// unloading an asset their site actually needs.
	if ( count( $owners ) > 1 ) {
		$best              = $none;
		$best['veredicto'] = 'ambiguo';
		$best['sites']     = 0;
		foreach ( $owners as $o ) {
			$best['sites'] += (int) $o['sites'];
		}
		return $best;
	}

	$owner = reset( $owners );
	if ( (int) $owner['sites'] < wpoptimizing_assets_kb_threshold() ) {
		return $none;
	}

	// The named extension is not on this site, so it is not what printed our handle.
	if ( ! wpoptimizing_assets_kb_owner_is_installed( $owner['owner_slug'], $owner['owner_type'] ) ) {
		return $none;
	}

	return array(
		'veredicto'  => 'certain',
		'owner_slug' => (string) $owner['owner_slug'],
		'owner_type' => (string) $owner['owner_type'],
		'sites'      => (int) $owner['sites'],
	);
}
