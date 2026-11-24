<?php
/**
 * Whose drop-in is this, and may WPOptimizing take its place?
 *
 * WordPress loads one object-cache.php, one db.php and one advanced-cache.php. When another plugin
 * already put one there, WPOptimizing used to say "another plugin" and stop: no name, and no way
 * forward even when the file was a leftover of a plugin uninstalled long ago (emiliocalvo.es,
 * 14/10/2026). This names the owner, says whether it is still active, and lets WPOptimizing take
 * the slot when that is safe — keeping a copy of the original and putting it back when ours is
 * switched off.
 *
 * One function for the three files, so the three sections never disagree about who owns what.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/** Where the originals wait while ours occupies their slot. */
const WPOPTIMIZING_DROPIN_BACKUPS_OPTION = 'wpoptimizing_dropin_backups';

/**
 * The drop-in kinds WPOptimizing can manage, and their path.
 *
 * @param string $kind 'object' | 'db' | 'page'.
 * @return string Absolute path, or '' for an unknown kind.
 */
function wpoptimizing_dropin_path( $kind ) {
	switch ( $kind ) {
		case 'object':
			return defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';
		case 'db':
			return defined( 'WPOPT_DB_DROPIN_DEST' ) ? WPOPT_DB_DROPIN_DEST : WP_CONTENT_DIR . '/db.php';
		case 'page':
			return defined( 'WPOPT_ADVCACHE_DEST' ) ? WPOPT_ADVCACHE_DEST : WP_CONTENT_DIR . '/advanced-cache.php';
	}
	return '';
}

/**
 * The start of a drop-in, line endings normalised the way get_file_data() does: a file saved with
 * a bare \r otherwise hides its whole header.
 *
 * @param string $path File.
 * @return string Up to 8 KB, or ''.
 */
function wpoptimizing_dropin_head( $path ) {
	if ( '' === (string) $path || ! is_readable( $path ) ) {
		return '';
	}
	$head = (string) file_get_contents( $path, false, null, 0, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- A small local drop-in header.
	return str_replace( array( "\r\n", "\r" ), "\n", $head );
}

/**
 * The name a drop-in gives itself in its header, without the "(Drop-in)" suffix.
 *
 * @param string $head Normalised head of the file.
 * @return string
 */
function wpoptimizing_dropin_header_name( $head ) {
	if ( 1 !== preg_match( '/^[ \t\/*#@]*Plugin Name:[ \t]*([^\n]+)/mi', (string) $head, $m ) ) {
		return '';
	}
	$name = trim( preg_replace( '/\s*\(?\s*Drop-?in\s*\)?\s*$/i', '', trim( $m[1] ) ) );
	return trim( $name, " \t*/" );
}

/**
 * Plugin slugs a drop-in points at (`…/plugins/<slug>/…`), in order of appearance.
 *
 * @param string $head Normalised head of the file.
 * @return string[]
 */
function wpoptimizing_dropin_slugs( $head ) {
	preg_match_all( '#plugins/([a-z0-9][a-z0-9_.-]*)/#i', (string) $head, $m );
	return array_values( array_unique( array_map( 'strtolower', $m[1] ) ) );
}

/**
 * Who owns a drop-in.
 *
 * @param string $path File.
 * @return array{name:string,plugin:string,active:?bool,routes_db:bool}
 *         `plugin` is the owner's plugin file when it is installed here; `active` is null when the
 *         owner could not be matched to an installed plugin (a mu-plugin, a host, a leftover).
 *         `routes_db` marks HyperDB/LudicrousDB-style drop-ins, which hold the database connection
 *         itself and must never be replaced.
 */
function wpoptimizing_dropin_owner( $path ) {
	$head  = wpoptimizing_dropin_head( $path );
	$name  = wpoptimizing_dropin_header_name( $head );
	$slugs = wpoptimizing_dropin_slugs( $head );
	$owner = array(
		'name'      => $name,
		'plugin'    => '',
		'active'    => null,
		'routes_db' => 1 === preg_match( '/hyperdb|ludicrousdb|db-config\.php/i', $head ),
	);
	if ( '' === $head ) {
		return $owner;
	}
	if ( ! function_exists( 'get_plugins' ) && defined( 'ABSPATH' ) && is_readable( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$installed = function_exists( 'get_plugins' ) ? (array) get_plugins() : array();
	$needle    = strtolower( $name );
	foreach ( $installed as $file => $data ) {
		$dir   = strtolower( (string) dirname( (string) $file ) );
		$pname = strtolower( (string) ( $data['Name'] ?? '' ) );
		$by_slug = '.' !== $dir && in_array( $dir, $slugs, true );
		$by_name = '' !== $needle && '' !== $pname && strlen( $pname ) >= 4
			&& ( false !== strpos( $needle, $pname ) || false !== strpos( $pname, $needle ) );
		if ( $by_slug || $by_name ) {
			$owner['plugin'] = (string) $file;
			$owner['name']   = '' !== $name ? $name : (string) ( $data['Name'] ?? '' );
			$owner['active'] = function_exists( 'is_plugin_active' ) ? is_plugin_active( (string) $file ) : null;
			break;
		}
	}
	if ( '' === $owner['name'] && ! empty( $slugs ) ) {
		$owner['name'] = $slugs[0];
	}
	return $owner;
}

/**
 * Whether WPOptimizing may take a foreign drop-in's slot, and if not, why.
 *
 * - advanced-cache.php: never while another page cache is active — two page caches fighting
 *   over the same page is worse than either alone.
 * - object-cache.php: only when its owner is no longer active (a leftover), or when the person
 *   chose ours for the object cache in Settings > Compatibility.
 * - db.php: yes, except a drop-in that routes the database connection (HyperDB, LudicrousDB):
 *   without it the site may not reach its database at all. A profiler or Query Monitor only
 *   loses its query log while ours is in place.
 *
 * @param string $kind          'object' | 'db' | 'page'.
 * @param array  $owner         wpoptimizing_dropin_owner().
 * @param array  $active_caches Labels of the third-party page caches detected as active.
 * @return array{ok:bool,reason:string} reason: '' | active_cache | owner_active | routes_db.
 */
function wpoptimizing_dropin_can_replace( $kind, array $owner, array $active_caches = array() ) {
	// Ni tampoco cuando el plugin que firma el drop-in sigue activo, aunque no lo reconozca
	// ningún catálogo: su caché sigue viva y la nuestra le quitaría el sitio.
	if ( 'page' === $kind && ( ! empty( $active_caches ) || true === $owner['active'] ) ) {
		return array(
			'ok'     => false,
			'reason' => 'active_cache',
		);
	}
	// Unless the person chose ours over it in Settings > Compatibility: then «Enable now» kept
	// answering that the file belonged to Redis Object Cache, and nothing else happened
	// (the WP Total Updater site, 27/10/2026). The original is still kept and put back.
	$elegido = function_exists( 'wpoptimizing_compat_overrides' ) && isset( wpoptimizing_compat_overrides()['object_cache'] );
	if ( 'object' === $kind && true === $owner['active'] && ! $elegido ) {
		return array(
			'ok'     => false,
			'reason' => 'owner_active',
		);
	}
	if ( 'db' === $kind && ! empty( $owner['routes_db'] ) ) {
		return array(
			'ok'     => false,
			'reason' => 'routes_db',
		);
	}
	return array(
		'ok'     => true,
		'reason' => '',
	);
}

/**
 * Where a kind's original is kept.
 *
 * @param string $kind Kind.
 * @return string
 */
function wpoptimizing_dropin_backup_file( $kind ) {
	$base = defined( 'WPOPT_PROTECTED_DIR' ) ? WPOPT_PROTECTED_DIR : WP_CONTENT_DIR . '/wpoptimizing/protected';
	return $base . '/dropin-backups/' . sanitize_key( $kind ) . '.php.bak';
}

/**
 * Keep the foreign drop-in before ours takes its slot. Verified by hash: a copy that cannot be
 * proven identical is not a backup, and then nothing is replaced.
 *
 * @param string $kind Kind.
 * @return bool
 */
function wpoptimizing_dropin_backup( $kind ) {
	$src = wpoptimizing_dropin_path( $kind );
	if ( '' === $src || ! is_readable( $src ) ) {
		return false;
	}
	if ( function_exists( 'wpoptimizing_ensure_protected_dir' ) ) {
		wpoptimizing_ensure_protected_dir();
	}
	$dest = wpoptimizing_dropin_backup_file( $kind );
	if ( ! wp_mkdir_p( dirname( $dest ) ) || ! @copy( $src, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false;
	}
	if ( md5_file( $src ) !== md5_file( $dest ) ) {
		wp_delete_file( $dest );
		return false;
	}
	$all          = (array) get_option( WPOPTIMIZING_DROPIN_BACKUPS_OPTION, array() );
	$owner        = wpoptimizing_dropin_owner( $src );
	$all[ $kind ] = array(
		'name' => (string) $owner['name'],
		'time' => time(),
	);
	update_option( WPOPTIMIZING_DROPIN_BACKUPS_OPTION, $all, false );
	return true;
}

/**
 * Put the original back after ours left the slot.
 *
 * @param string $kind Kind.
 * @return string The owner's name when something was restored, '' otherwise.
 */
function wpoptimizing_dropin_restore( $kind ) {
	$all    = (array) get_option( WPOPTIMIZING_DROPIN_BACKUPS_OPTION, array() );
	$backup = wpoptimizing_dropin_backup_file( $kind );
	$dest   = wpoptimizing_dropin_path( $kind );
	if ( ! isset( $all[ $kind ] ) || ! is_readable( $backup ) || '' === $dest || file_exists( $dest ) ) {
		return '';
	}
	$ok = function_exists( 'wpoptimizing_atomic_install_file' ) ? wpoptimizing_atomic_install_file( $backup, $dest ) : @copy( $backup, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! $ok ) {
		return '';
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	$name = (string) ( $all[ $kind ]['name'] ?? '' );
	wp_delete_file( $backup );
	unset( $all[ $kind ] );
	update_option( WPOPTIMIZING_DROPIN_BACKUPS_OPTION, $all, false );
	return '' !== $name ? $name : basename( $dest );
}

/**
 * Whether ours is sitting in a slot that had another drop-in before.
 *
 * @param string $kind Kind.
 * @return string The original owner's name, or ''.
 */
function wpoptimizing_dropin_replaced_name( $kind ) {
	$all = (array) get_option( WPOPTIMIZING_DROPIN_BACKUPS_OPTION, array() );
	return isset( $all[ $kind ] ) ? ( '' !== (string) $all[ $kind ]['name'] ? (string) $all[ $kind ]['name'] : '?' ) : '';
}
