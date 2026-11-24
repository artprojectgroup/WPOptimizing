<?php
/**
 * WordPress core tables whose id column has lost its AUTO_INCREMENT.
 *
 * On the WP Total Updater site (27/10/2026) postmeta, termmeta, terms, term_taxonomy
 * and users had lost it at some point before April 2024, probably in a migration that copied
 * the tables without their attributes. Nothing looked wrong until an insert without an id went
 * in as 0 and the next one collided with it: the plugin update failed with «The installation
 * package is not available», and a term with id 0 had been living in three tables. Nothing in
 * WordPress warns about it.
 *
 * What each column should be is not copied here: wp_get_db_schema() gives it for the WordPress
 * version installed, multisite tables included. Plugin tables are not covered.
 *
 * The fix is the ALTER we ran by hand, with three guards. A row with id 0 is moved to the next
 * free id first, but only in tables nobody points at (metadata and options): moving a post,
 * user, term or comment would break every reference to it, so those are explained, not touched
 * (decided with Emilio, 28/10/2026). A table without its primary key is not altered: it may hold
 * duplicates. And the ALTER rebuilds the table and blocks writes meanwhile, so a big one is
 * explained and not touched, with the same limit as «Convert to InnoDB».
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Id columns that nothing else references: a row with id 0 can move without breaking anything.
 */
const WPOPTIMIZING_AUTOINC_FREE_COLUMNS = array( 'meta_id', 'umeta_id', 'option_id' );

/**
 * The AUTO_INCREMENT column of every table in a WordPress schema. Pure.
 *
 * @param string $schema SQL from wp_get_db_schema().
 * @return array<string,array{column:string,definition:string}> Table name => its id column and
 *         the definition to restore it with (e.g. «bigint(20) unsigned NOT NULL auto_increment»).
 */
function wpoptimizing_autoinc_parse_schema( $schema ) {
	$out = array();
	if ( ! preg_match_all( '/CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*?)\n\)/s', (string) $schema, $tables, PREG_SET_ORDER ) ) {
		return $out;
	}
	foreach ( $tables as $t ) {
		foreach ( preg_split( '/\R/', $t[2] ) as $line ) {
			if ( preg_match( '/^\s*`?([A-Za-z0-9_]+)`?\s+(.+?\bauto_increment\b)\s*,?\s*$/i', $line, $m ) ) {
				$out[ $t[1] ] = array(
					'column'     => $m[1],
					'definition' => trim( $m[2] ),
				);
				break;
			}
		}
	}
	return $out;
}

/**
 * Which tables of a live schema have lost the attribute or their primary key. Pure.
 *
 * @param array<string,array{column:string,definition:string}> $expected From wpoptimizing_autoinc_parse_schema(), existing tables only.
 * @param array<string,array{extra:string,key:string}>         $live     Table => EXTRA and COLUMN_KEY of its id column.
 * @return array<string,array{column:string,definition:string,no_pk:bool}>
 */
function wpoptimizing_autoinc_broken( array $expected, array $live ) {
	$out = array();
	foreach ( $expected as $table => $want ) {
		if ( ! isset( $live[ $table ] ) ) {
			continue; // Table or column missing: another problem, not this one.
		}
		if ( false !== stripos( (string) $live[ $table ]['extra'], 'auto_increment' ) ) {
			continue;
		}
		$out[ $table ] = $want + array( 'no_pk' => 'PRI' !== strtoupper( (string) $live[ $table ]['key'] ) );
	}
	return $out;
}

/**
 * The core tables of this site with the problem, and what can be done about each.
 *
 * On a multisite sub-site only its own tables; the global ones (users, usermeta, the network
 * tables) are checked from the main site.
 *
 * @return array<string,array{column:string,definition:string,no_pk:bool,zero:bool,free:bool,bytes:int}>
 */
function wpoptimizing_autoinc_findings() {
	global $wpdb;
	if ( ! function_exists( 'wp_get_db_schema' ) ) {
		require_once ABSPATH . 'wp-admin/includes/schema.php';
	}
	$scope    = ( ! is_multisite() || is_main_site() ) ? 'all' : 'blog';
	$expected = wpoptimizing_autoinc_parse_schema( wp_get_db_schema( $scope ) );
	if ( empty( $expected ) ) {
		return array();
	}
	$names = array_keys( $expected );
	$in    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
	// One read of information_schema for every table, ids and sizes together.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders built for the list.
	$cols = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME t, COLUMN_NAME c, EXTRA e, COLUMN_KEY k FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})", $names ), ARRAY_A );
	$size = $wpdb->get_results( $wpdb->prepare( "SELECT TABLE_NAME t, DATA_LENGTH + INDEX_LENGTH b FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ({$in})", $names ), ARRAY_A );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$live = array();
	foreach ( (array) $cols as $c ) {
		if ( isset( $expected[ $c['t'] ] ) && $expected[ $c['t'] ]['column'] === $c['c'] ) {
			$live[ $c['t'] ] = array(
				'extra' => (string) $c['e'],
				'key'   => (string) $c['k'],
			);
		}
	}
	$bytes = array_column( (array) $size, 'b', 't' );
	$out   = array();
	foreach ( wpoptimizing_autoinc_broken( array_intersect_key( $expected, $live ), $live ) as $table => $f ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A live check of the table, never cached.
		$f['zero']     = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE %i = 0 LIMIT 1', $table, $f['column'] ) );
		$f['free']     = in_array( $f['column'], WPOPTIMIZING_AUTOINC_FREE_COLUMNS, true );
		$f['bytes']    = (int) ( $bytes[ $table ] ?? 0 );
		$out[ $table ] = $f;
	}
	return $out;
}

/**
 * Most bytes a table may have for the fix to run from a browser request. Same filter and limit
 * as Optimize and Convert to InnoDB, which rebuild the table the same way.
 *
 * @return int
 */
function wpoptimizing_autoinc_max_bytes() {
	/** This filter is documented in includes/admin/trait-tables.php */
	return max( 0, (int) apply_filters( 'wpoptimizing_web_rebuild_max_bytes', 256 * MB_IN_BYTES ) );
}

/**
 * Why a finding cannot be fixed from the screen, or '' when it can. Pure.
 *
 * @param array $f   One finding.
 * @param int   $max wpoptimizing_autoinc_max_bytes().
 * @return string no_pk | zero_row | too_big | ''
 */
function wpoptimizing_autoinc_blocker( array $f, $max ) {
	if ( ! empty( $f['no_pk'] ) ) {
		return 'no_pk';
	}
	if ( ! empty( $f['zero'] ) && empty( $f['free'] ) ) {
		return 'zero_row';
	}
	if ( $max > 0 && (int) ( $f['bytes'] ?? 0 ) > $max ) {
		return 'too_big';
	}
	return '';
}

/**
 * The statement that restores the attribute.
 *
 * @param string $table Table.
 * @param array  $f     Finding.
 * @return string
 */
function wpoptimizing_autoinc_sql( $table, array $f ) {
	return 'ALTER TABLE `' . str_replace( '`', '``', $table ) . '` MODIFY `' . str_replace( '`', '``', $f['column'] ) . '` ' . $f['definition'];
}

/**
 * Restore the attribute on one table, re-checking everything first.
 *
 * @param string $table Table name.
 * @return true|string True when fixed, or the reason it was not.
 */
function wpoptimizing_autoinc_fix( $table ) {
	global $wpdb;
	$all = wpoptimizing_autoinc_findings();
	if ( ! isset( $all[ $table ] ) ) {
		return __( 'the table no longer needs it, or is not a WordPress table of this site', 'wpoptimizing' );
	}
	$f = $all[ $table ];
	if ( '' !== wpoptimizing_autoinc_blocker( $f, wpoptimizing_autoinc_max_bytes() ) ) {
		return __( 'it cannot be fixed from here; follow the instructions in the notice', 'wpoptimizing' );
	}
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- Live schema change; the ALTER is built from the WordPress schema (see wpoptimizing_autoinc_sql()).
	if ( $f['zero'] ) {
		// Nothing points at a meta or option id, so the row only changes number. Without this the
		// ALTER either fails or renumbers it on its own.
		$new = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(%i) FROM %i', $f['column'], $table ) ) + 1;
		if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE %i SET %i = %d WHERE %i = 0', $table, $f['column'], $new, $f['column'] ) ) ) {
			return (string) $wpdb->last_error;
		}
		wpoptimizing_audit_log(
			'autoinc_zero_moved',
			array(
				'table' => $table,
				'to'    => $new,
			)
		);
	}
	// The column definition is DDL and cannot be a placeholder; both names are quoted there.
	$ok = $wpdb->query( wpoptimizing_autoinc_sql( $table, $f ) ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table and column of the WordPress schema, quoted; definition from the core schema.
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
	if ( false === $ok || '' !== (string) $wpdb->last_error ) {
		return '' !== (string) $wpdb->last_error ? (string) $wpdb->last_error : __( 'rejected by the database', 'wpoptimizing' );
	}
	// Verified, not assumed.
	$still = wpoptimizing_autoinc_findings();
	if ( isset( $still[ $table ] ) ) {
		return __( 'the database accepted the change but the column still has no AUTO_INCREMENT', 'wpoptimizing' );
	}
	wpoptimizing_audit_log( 'autoinc_fixed', array( 'table' => $table ) );
	return true;
}
