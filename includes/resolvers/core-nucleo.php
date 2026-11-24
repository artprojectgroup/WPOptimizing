<?php
/**
 * What belongs to WordPress, measured — the `nucleo` layer of the knowledge base.
 *
 * The core registry (scanners/class-wp-core-registry.php) is a list written by hand, and it ages
 * with every WordPress release: on 15/10/2026 it had to be widened by hand for table prefixes
 * and core transients. The alimentador now measures WordPress itself on a clean install (empty
 * wp-content, no mu-plugins, single site and network) for every release, and the gateway
 * serves the union of all releases with the first and last version each item was seen in.
 *
 * The registry asks here as well as its own list: the list stays as the fallback for sites that
 * do not share data or have not downloaded the layer yet. What was core in ANY release counts
 * as core — leftovers of an older WordPress (db_upgraded…) belong to no extension.
 *
 * Three levels: data (options, tables, post types…), code (hooks, functions, classes) and the
 * difference between releases, which is first_seen/last_seen.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The inventory, parsed once per request.
 *
 * @return array{items:array<string,array<string,array{0:string,1:string,2:string}>>,dynamic:array<string,string[]>}
 *         items: tipo => clave(lowercase) => [contexto, first_seen, last_seen];
 *         dynamic: hook patterns with {placeholders}, as regexes, by tipo.
 */
function wpoptimizing_nucleo_index() {
	static $index = null;
	if ( null !== $index ) {
		return $index;
	}
	$index = array(
		'items'   => array(),
		'dynamic' => array(),
	);
	if ( ! defined( 'WPOPT_DIR' ) ) {
		return $index;
	}
	$path = trailingslashit( WPOPT_DIR ) . 'wp-nucleo-distrib.csv';
	if ( ! is_readable( $path ) ) {
		return $index;
	}
	$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $fh ) {
		return $index;
	}
	$head = fgetcsv( $fh, 0, ',', '"', '\\' );
	if ( ! is_array( $head ) || array( 'tipo', 'clave', 'contexto', 'first_seen', 'last_seen' ) !== array_map( 'trim', $head ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $index;
	}
	while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition -- idiomatic CSV streaming.
		if ( count( $row ) < 5 ) {
			continue;
		}
		list( $tipo, $clave, $ctx, $first, $last ) = array_map( 'strval', $row );
		if ( false !== strpos( $ctx, 'dynamic' ) ) {
			$index['dynamic'][ $tipo ][] = wpoptimizing_nucleo_pattern( $clave );
			continue;
		}
		$index['items'][ $tipo ][ strtolower( $clave ) ] = array( $ctx, $first, $last );
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $index;
}

/**
 * A dynamic hook name (`save_post_{$post->post_type}`) as an anchored regex: each {…} part
 * matches one or more characters. Pure.
 *
 * @param string $name Pattern with {placeholders}.
 * @return string
 */
function wpoptimizing_nucleo_pattern( $name ) {
	$parts = preg_split( '/\{[^}]*\}/', (string) $name );
	return '/^' . implode( '.+', array_map( 'preg_quote', (array) $parts, array_fill( 0, count( (array) $parts ), '/' ) ) ) . '$/i';
}

/**
 * Is this item WordPress's own, in any release?
 *
 * @param string $tipo  option|table|post_type|taxonomy|rest_namespace|cron|capability|role|
 *                      shortcode|widget|sidebar|image_size|post_meta|user_meta|term_meta|
 *                      hook|function|class.
 * @param string $clave Name (tables bare, without the site prefix).
 * @return bool
 */
function wpoptimizing_nucleo_has( $tipo, $clave ) {
	$idx   = wpoptimizing_nucleo_index();
	$tipo  = strtolower( (string) $tipo );
	$clave = (string) $clave;
	if ( isset( $idx['items'][ $tipo ][ strtolower( $clave ) ] ) ) {
		return true;
	}
	foreach ( (array) ( $idx['dynamic'][ $tipo ] ?? array() ) as $re ) {
		if ( 1 === preg_match( $re, $clave ) ) {
			return true;
		}
	}
	return false;
}

/**
 * The WordPress releases an item was seen in: the third level, the difference between releases.
 *
 * @param string $tipo  Tipo.
 * @param string $clave Name.
 * @return array{first:string,last:string,network:bool}|null Null when it is not core.
 */
function wpoptimizing_nucleo_seen( $tipo, $clave ) {
	$idx = wpoptimizing_nucleo_index();
	$hit = $idx['items'][ strtolower( (string) $tipo ) ][ strtolower( (string) $clave ) ] ?? null;
	if ( null === $hit ) {
		return null;
	}
	return array(
		'first'   => $hit[1],
		'last'    => $hit[2],
		'network' => 'network' === $hit[0],
	);
}
