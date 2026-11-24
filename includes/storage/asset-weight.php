<?php
/**
 * How much CSS and JavaScript this site actually sends, and who sent it.
 *
 * WHY THIS EXISTS. A production audit found the performance report recommending PHP and
 * database changes worth tens of milliseconds while the same page shipped 1.1 MB of CSS in a
 * single handle and 850 KB of JavaScript, and said nothing about either. Not because the data
 * was wrong — because the report only ever looked at PHP.
 *
 * The Smart Assets inventory already records every handle that loads, its owner, and a
 * normalised path. Adding the byte count turns "these plugins load assets" into "this is what
 * your visitors download, and this is who asked for it", which is the first thing a
 * performance report should say and the last thing ours did.
 *
 * MEASURED, NOT ESTIMATED, AND ONLY WHAT WE CAN SEE. A file we cannot resolve on disk — a CDN
 * URL, an inline handle, a plugin that builds its asset at runtime — contributes nothing and
 * is counted separately, so the total is always "at least this much" and never a guess
 * presented as a measurement.
 *
 * The helpers take an injected sizer, so the arithmetic is testable without a filesystem.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Absolute path for an inventory src, or '' when it is not a local file.
 *
 * The normaliser stores plugin/theme paths relative to wp-content and core paths relative to
 * ABSPATH (they keep their wp-includes/ or wp-admin/ lead segment), so both bases are needed.
 *
 * REFUSES ANYTHING THAT CLIMBS. The inventory is written by a mu-plugin from enqueued URLs,
 * which is not user input — but it is data from outside this function, it names a path, and
 * this function's whole job is turning it into a filesystem read. A `..` in there must not be
 * able to reach outside the install.
 *
 * @since 0.1.0
 * @param string $rel         Normalised src from wpoptimizing_normalize_asset_src().
 * @param string $content_dir WP_CONTENT_DIR.
 * @param string $abspath     ABSPATH.
 * @return string Absolute path, or '' when it is not resolvable inside the install.
 */
function wpoptimizing_asset_src_to_path( $rel, $content_dir, $abspath ) {
	$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '../' ) || '..' === $rel ) {
		return '';
	}

	$content_dir = rtrim( str_replace( '\\', '/', (string) $content_dir ), '/' );
	$abspath     = rtrim( str_replace( '\\', '/', (string) $abspath ), '/' );

	if ( 0 === strpos( $rel, 'wp-includes/' ) || 0 === strpos( $rel, 'wp-admin/' ) ) {
		return '' !== $abspath ? $abspath . '/' . $rel : '';
	}
	if ( 0 === strpos( $rel, 'plugins/' ) || 0 === strpos( $rel, 'mu-plugins/' ) || 0 === strpos( $rel, 'themes/' ) || 0 === strpos( $rel, 'uploads/' ) ) {
		return '' !== $content_dir ? $content_dir . '/' . $rel : '';
	}

	return ''; // Remote, inline, or somewhere we do not recognise.
}

/**
 * Add up the inventory by owner and by handle.
 *
 * @since 0.1.0
 * @param array[]  $rows  Inventory rows ({kind, handle, owner_type, owner_slug, src_rel}).
 * @param callable $sizer Given a row's src_rel, returns its size in bytes, or 0 when unknown.
 * @return array{
 *     css:int, js:int, total:int, measured:int, unresolved:int,
 *     by_owner:array<string,int>, by_handle:array<string,array{bytes:int,owner:string,kind:string}>
 * }
 */
function wpoptimizing_assets_weigh( array $rows, callable $sizer ) {
	$out = array(
		'css'        => 0,
		'js'         => 0,
		'total'      => 0,
		'measured'   => 0,
		'unresolved' => 0,
		'by_owner'   => array(),
		'by_handle'  => array(),
	);

	$seen = array();
	foreach ( $rows as $r ) {
		$kind   = strtolower( trim( (string) ( $r['kind'] ?? '' ) ) );
		$handle = trim( (string) ( $r['handle'] ?? '' ) );
		if ( '' === $handle || ( 'style' !== $kind && 'script' !== $kind ) ) {
			continue;
		}
		// One row per handle: the inventory can carry the same handle from several sources.
		$key = $kind . ':' . $handle;
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;

		$bytes = (int) call_user_func( $sizer, (string) ( $r['src_rel'] ?? '' ) );
		if ( $bytes <= 0 ) {
			++$out['unresolved'];
			continue;
		}

		$owner = trim( (string) ( $r['owner_slug'] ?? '' ) );
		if ( '' === $owner ) {
			$owner = 'core' === (string) ( $r['owner_type'] ?? '' ) ? 'wordpress-core' : 'unknown';
		}

		++$out['measured'];
		$out['total']                            += $bytes;
		$out[ 'style' === $kind ? 'css' : 'js' ] += $bytes;
		$out['by_owner'][ $owner ]                = ( $out['by_owner'][ $owner ] ?? 0 ) + $bytes;
		$out['by_handle'][ $handle ]              = array(
			'bytes' => $bytes,
			'owner' => $owner,
			'kind'  => $kind,
		);
	}

	arsort( $out['by_owner'] );
	uasort(
		$out['by_handle'],
		static function ( $a, $b ) {
			return (int) $b['bytes'] <=> (int) $a['bytes'];
		}
	);

	return $out;
}

/**
 * Is this weight worth telling the user about, and why?
 *
 * TWO SIGNALS, AND NEITHER IS "IT IS BIG". A content-heavy site legitimately ships a lot; what
 * is actionable is a single handle carrying a large share of the whole — one unsplittable file
 * nobody can cache around — and a total past the point where it costs a visitor real time on a
 * phone.
 *
 * @since 0.1.0
 * @param array $weight From wpoptimizing_assets_weigh().
 * @param array $opts   Thresholds: total (bytes), handle (bytes), share (0-1).
 * @return array{code:string,handle:string,bytes:int}|null
 */
function wpoptimizing_assets_weight_finding( array $weight, array $opts = array() ) {
	$t = array_merge(
		array(
			'total'  => 800 * 1024,
			'handle' => 300 * 1024,
			'share'  => 0.30,
		),
		$opts
	);

	$total = (int) ( $weight['total'] ?? 0 );
	if ( $total <= 0 ) {
		return null;
	}

	// A single file that is both large in itself and a large share of everything.
	foreach ( (array) ( $weight['by_handle'] ?? array() ) as $handle => $info ) {
		$bytes = (int) ( $info['bytes'] ?? 0 );
		if ( $bytes >= (int) $t['handle'] && ( $bytes / $total ) >= (float) $t['share'] ) {
			return array(
				'code'   => 'dominant_handle',
				'handle' => (string) $handle,
				'bytes'  => $bytes,
			);
		}
		break; // by_handle is sorted; only the biggest can qualify.
	}

	if ( $total >= (int) $t['total'] ) {
		return array(
			'code'   => 'heavy_total',
			'handle' => '',
			'bytes'  => $total,
		);
	}

	return null;
}

/**
 * Weigh this site's inventory for real, from disk. Request-cached.
 *
 * Admin only by consequence: it stats every resolvable asset, which is filesystem work no
 * visitor request should ever do. Bounded so an inventory that has grown unexpectedly cannot
 * turn a report page into a directory walk.
 *
 * @since 0.1.0
 * @param int $limit Maximum assets to stat.
 * @return array As wpoptimizing_assets_weigh(), or an empty total when there is no inventory.
 */
function wpoptimizing_assets_weight_measured( $limit = 400 ) {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	if ( ! function_exists( 'wpoptimizing_assets_inventory_rows' ) ) {
		$cache = wpoptimizing_assets_weigh( array(), '__return_zero' );
		return $cache;
	}

	$rows = array_slice( (array) wpoptimizing_assets_inventory_rows(), 0, max( 1, (int) $limit ) );

	$content = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '';
	$abs     = defined( 'ABSPATH' ) ? ABSPATH : '';

	$cache = wpoptimizing_assets_weigh(
		$rows,
		static function ( $rel ) use ( $content, $abs ) {
			$path = wpoptimizing_asset_src_to_path( $rel, $content, $abs );
			if ( '' === $path || ! is_file( $path ) ) {
				return 0;
			}
			return (int) filesize( $path );
		}
	);

	return $cache;
}
