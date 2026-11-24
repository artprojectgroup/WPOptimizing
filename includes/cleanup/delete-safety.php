<?php
/**
 * Guards shared by the delete and backup paths: how a value is written into a SQL backup, how
 * cron events are captured and put back, which paths are the plugin's own live files, which
 * paths a sub-site administrator may touch, and how a folder is removed without following a
 * symlink out of wp-content.
 *
 * Kept free of the admin traits so each rule lives once and is unit-tested offline. Engine-
 * loaded: nothing here runs on a visitor request.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPT_SQL_BACKUP_FORMAT' ) ) {
	// First line of every table backup written since the escaping fix. Its absence is how the
	// restore recognises a file written by the old writer (see the strip helper below).
	define( 'WPOPT_SQL_BACKUP_FORMAT', '-- wpoptimizing-sql-backup: 2' );
}

/**
 * One value of a table backup, as a SQL literal MySQL reads back byte for byte.
 *
 * esc_sql() already escapes newlines through the connection, so a manual "\n" -> "\\n" before
 * it was escaped twice and every line break came back as a literal backslash-n. And esc_sql()
 * swaps each % for this request's placeholder hash, which only this request can undo: written
 * to a file as it was, the % never came back. Both were measured on restore.
 *
 * @param mixed $value Column value (null for SQL NULL).
 * @return string
 */
function wpoptimizing_backup_sql_value( $value ) {
	if ( null === $value ) {
		return 'NULL';
	}
	global $wpdb;
	$escaped = esc_sql( (string) $value );
	if ( is_object( $wpdb ) && method_exists( $wpdb, 'remove_placeholder_escape' ) ) {
		$escaped = $wpdb->remove_placeholder_escape( $escaped );
	}
	return "'" . $escaped . "'";
}

/**
 * The INSERT line of one row of a table backup.
 *
 * @param string              $table Full table name.
 * @param array<string,mixed> $row   Row as returned with ARRAY_A.
 * @return string
 */
function wpoptimizing_backup_sql_insert( $table, array $row ) {
	$cols = array();
	foreach ( array_keys( $row ) as $col ) {
		$cols[] = '`' . str_replace( '`', '``', (string) $col ) . '`';
	}
	$vals = array_map( 'wpoptimizing_backup_sql_value', array_values( $row ) );
	return 'INSERT INTO `' . str_replace( '`', '``', (string) $table ) . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n";
}

/**
 * Put back the % that a table backup of the old writer lost.
 *
 * Those files carry the placeholder hash of the request that wrote them, "{" + 64 hex + "}",
 * where the data had a %. No other request knows that hash, so it is recognised by its shape.
 * Only applied to files without the format line, so a backup written now is never rewritten.
 * The doubled line breaks of the old writer cannot be told apart from a real backslash-n in the
 * data, so they are left as they are.
 *
 * @param string $sql Statement read from an old backup.
 * @return string
 */
function wpoptimizing_backup_sql_strip_placeholders( $sql ) {
	return (string) preg_replace( '/\{[0-9a-f]{64}\}/', '%', (string) $sql );
}

/**
 * Every scheduled event of one hook, keyed exactly as the cron array keys it.
 *
 * The cron array is keyed by TIMESTAMP, then hook, then argument signature. Asking it for the
 * hook at the top level, as the backup did, never found anything, so no cron backup was ever
 * written and every delete of a cron hook was beyond undo.
 *
 * @param array  $cron Cron array (as _get_cron_array() returns it).
 * @param string $hook Hook name.
 * @return array<int,array<string,array<string,array>>> [ts][hook][sig] => event.
 */
function wpoptimizing_cron_events_for_hook( array $cron, $hook ) {
	$hook = (string) $hook;
	$out  = array();
	if ( '' === $hook ) {
		return $out;
	}
	foreach ( $cron as $ts => $hooks ) {
		if ( ! is_numeric( $ts ) || ! is_array( $hooks ) || empty( $hooks[ $hook ] ) || ! is_array( $hooks[ $hook ] ) ) {
			continue;
		}
		$out[ (int) $ts ][ $hook ] = $hooks[ $hook ];
	}
	return $out;
}

/**
 * Merge backed-up events into a cron array, each at its own timestamp.
 *
 * Never adds a hook as a top-level key: WordPress reads every top-level key as a timestamp, so
 * that shape corrupted the whole schedule instead of restoring one hook.
 *
 * @param array $cron   Current cron array.
 * @param array $events [ts][hook][sig] => event, from wpoptimizing_cron_events_for_hook().
 * @return array
 */
function wpoptimizing_cron_merge_events( array $cron, array $events ) {
	foreach ( $events as $ts => $hooks ) {
		if ( ! is_numeric( $ts ) || (int) $ts <= 0 || ! is_array( $hooks ) ) {
			continue;
		}
		$ts = (int) $ts;
		foreach ( $hooks as $hook => $sigs ) {
			if ( ! is_array( $sigs ) ) {
				continue;
			}
			foreach ( $sigs as $sig => $event ) {
				if ( is_array( $event ) ) {
					$cron[ $ts ][ (string) $hook ][ (string) $sig ] = $event;
				}
			}
		}
	}
	uksort(
		$cron,
		static function ( $a, $b ) {
			return (int) $a <=> (int) $b;
		}
	);
	return $cron;
}

/**
 * Paths, relative to wp-content, that hold the plugin's own live files.
 *
 * The files lists offered them for deletion — the object-cache database, the page cache, the
 * minified assets, the plugin's data and backups — and deleting them takes the site's cache or
 * the very backups an undo needs.
 *
 * @return string[] Relative prefixes (a match is the path itself or anything under it).
 */
function wpoptimizing_fs_own_paths() {
	$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	$own     = array( 'wpoptimizing', 'uploads/wpopt-assets' );
	$abs     = array();
	if ( defined( 'WPOPT_DIR' ) ) {
		$abs[] = WPOPT_DIR;
	}
	// The plugin's own folder: this file lives in <plugin>/includes/cleanup/.
	$abs[] = dirname( __DIR__, 2 );
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['basedir'] ) ) {
			$abs[] = trailingslashit( (string) $up['basedir'] ) . 'wpopt-assets';
		}
	}
	foreach ( $abs as $path ) {
		$path = untrailingslashit( wp_normalize_path( (string) $path ) );
		if ( 0 === strpos( $path, $content . '/' ) ) {
			$own[] = substr( $path, strlen( $content ) + 1 );
		}
	}
	return array_values( array_unique( $own ) );
}

/**
 * Whether a path relative to wp-content is, or contains, one of the plugin's live files.
 *
 * Covers cache/wpopt-* (object-cache SQLite and its -wal/-shm, page cache, statistics), the
 * paths of wpoptimizing_fs_own_paths(), and any folder that contains one of them: deleting
 * "cache" or a sub-site's uploads folder would take them along.
 *
 * @param string        $rel Path relative to wp-content.
 * @param string[]|null $own Own prefixes (null: wpoptimizing_fs_own_paths()).
 * @return bool
 */
function wpoptimizing_fs_is_own_path( $rel, $own = null ) {
	$rel = trim( str_replace( '\\', '/', (string) $rel ), '/' );
	if ( '' === $rel ) {
		return false;
	}
	if ( preg_match( '#^cache/wpopt-#', $rel ) ) {
		return true;
	}
	if ( 'cache' === $rel && glob( trailingslashit( WP_CONTENT_DIR ) . 'cache/wpopt-*' ) ) {
		return true; // The shared cache folder holds our live files: never wholesale.
	}
	$own = null === $own ? wpoptimizing_fs_own_paths() : (array) $own;
	foreach ( $own as $prefix ) {
		$prefix = trim( (string) $prefix, '/' );
		if ( '' === $prefix ) {
			continue;
		}
		if ( $rel === $prefix || 0 === strpos( $rel, $prefix . '/' ) || 0 === strpos( $prefix, $rel . '/' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a sub-site administrator may delete this path.
 *
 * On multisite every site shares one wp-content: plugins, themes, mu-plugins and every other
 * site's uploads. A site's own administrator (manage_options) used to be able to delete any of
 * it. Outside its own uploads folder, a path now needs manage_network_options; the main site's
 * uploads folder holds the others under sites/, so those stay out of its reach too.
 *
 * @param string $rel          Path relative to wp-content.
 * @param string $uploads_rel  Current site's uploads folder, relative to wp-content.
 * @param bool   $is_multisite Whether this is a network.
 * @param bool   $can_network  Whether the user has manage_network_options.
 * @return bool
 */
function wpoptimizing_fs_rel_allowed_for_blog( $rel, $uploads_rel, $is_multisite, $can_network ) {
	if ( ! $is_multisite || $can_network ) {
		return true;
	}
	$rel         = trim( (string) $rel, '/' );
	$uploads_rel = trim( (string) $uploads_rel, '/' );
	if ( '' === $rel || '' === $uploads_rel || 0 !== strpos( $rel, $uploads_rel . '/' ) ) {
		return false;
	}
	$inside = substr( $rel, strlen( $uploads_rel ) + 1 );
	// The main site's uploads folder also holds every sub-site's under sites/.
	return 0 !== strpos( $inside . '/', 'sites/' );
}

/**
 * A path relative to wp-content, from an absolute one.
 *
 * @param string $abs         Absolute path.
 * @param string $content_dir wp-content directory.
 * @return string '' when the path is not under it.
 */
function wpoptimizing_fs_rel_from_abs( $abs, $content_dir ) {
	$abs  = untrailingslashit( wp_normalize_path( (string) $abs ) );
	$base = untrailingslashit( wp_normalize_path( (string) $content_dir ) );
	if ( '' === $base || 0 !== strpos( $abs . '/', $base . '/' ) ) {
		return '';
	}
	return ltrim( substr( $abs, strlen( $base ) ), '/' );
}

/**
 * Remove every symlink inside a folder — the link itself, never what it points at.
 *
 * A recursive delete follows a symlinked sub-folder and empties its target, wherever it is:
 * reproduced deleting outside wp-content through a link two levels down. Only the top-level
 * path was checked. This walks the tree without following links and unlinks each one, so the
 * recursive delete that follows only sees real files.
 *
 * @param string $dir Absolute folder path.
 * @return bool False when a link could not be removed: the caller must not delete then.
 */
function wpoptimizing_fs_unlink_nested_symlinks( $dir ) {
	$dir = (string) $dir;
	if ( '' === $dir || is_link( $dir ) || ! is_dir( $dir ) ) {
		return ! is_link( $dir );
	}
	$handle = opendir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_opendir
	if ( false === $handle ) {
		return false;
	}
	$ok = true;
	while ( false !== ( $entry = readdir( $handle ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_link( $path ) ) {
			// unlink() removes the link; on Windows a link to a folder needs rmdir().
			$gone = @unlink( $path ) || @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			clearstatcache( true, $path );
			if ( ! $gone || is_link( $path ) ) {
				$ok = false;
			}
			continue;
		}
		if ( is_dir( $path ) && ! wpoptimizing_fs_unlink_nested_symlinks( $path ) ) {
			$ok = false;
		}
	}
	closedir( $handle );
	return $ok;
}

/**
 * Whether a database table must never be dropped from this site.
 *
 * WordPress' own tables (the network-wide ones included: users, usermeta, blogs…) and, on
 * multisite, another site's tables. The DROP used to be built from the prefix and the posted
 * value with no such check.
 *
 * @param string $table Full table name.
 * @return bool
 */
function wpoptimizing_db_table_is_protected( $table ) {
	global $wpdb;
	$table = (string) $table;
	if ( '' === $table ) {
		return true;
	}
	if ( function_exists( 'wpoptimizing_table_belongs_to_current_blog' ) && ! wpoptimizing_table_belongs_to_current_blog( $table ) ) {
		return true;
	}
	if ( is_object( $wpdb ) && method_exists( $wpdb, 'tables' ) ) {
		$core = array_merge( (array) $wpdb->tables( 'global' ), (array) $wpdb->tables( 'blog' ) );
		if ( in_array( $table, array_map( 'strval', array_values( $core ) ), true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a scan row may go with an uninstall's "delete associated data".
 *
 * Only when it names at least one of the extensions that were really removed, and every owner
 * it names is gone. A row shared with a plugin that is still installed stays, and so does a
 * row whose owner is not a plugin or a theme.
 *
 * @param string   $origins       Pipe-separated origins of the row.
 * @param string   $slugs         Pipe-separated slugs of the row (same order).
 * @param string[] $gone_plugins  Plugin slugs whose folder is verifiably gone.
 * @param string[] $gone_themes   Theme slugs whose folder is verifiably gone.
 * @param callable $still_there   fn( string $origin, string $slug ): bool — owner still on disk.
 * @return bool
 */
function wpoptimizing_cascade_row_eligible( $origins, $slugs, array $gone_plugins, array $gone_themes, callable $still_there ) {
	$orig  = array_map( 'trim', explode( '|', (string) $origins ) );
	$slgs  = array_map( 'trim', explode( '|', (string) $slugs ) );
	$named = false;
	$any   = false;
	foreach ( $slgs as $i => $slug ) {
		if ( '' === $slug ) {
			continue;
		}
		$any = true;
		$ori = $orig[ $i ] ?? '';
		if ( 'plugin' !== $ori && 'theme' !== $ori ) {
			return false; // Core, mu-plugin or unknown: not ours to judge gone.
		}
		$gone = ( 'plugin' === $ori ) ? $gone_plugins : $gone_themes;
		if ( in_array( $slug, $gone, true ) ) {
			$named = true;
			continue;
		}
		if ( call_user_func( $still_there, $ori, $slug ) ) {
			return false;
		}
	}
	return $any && $named;
}
