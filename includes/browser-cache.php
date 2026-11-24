<?php
/**
 * Browser cache + compression (.htaccess) — Fase 2.
 *
 * Adds a managed block to the site's .htaccess with mod_expires (far-future
 * Cache-Control/Expires for static assets), mod_deflate (GZIP compression) and
 * mod_headers (public Cache-Control). This is what makes a returning visitor's
 * browser reuse CSS/JS/images/fonts instead of re-downloading them, and serve
 * everything compressed — a big, safe win that complements the page cache.
 *
 * Apache/LiteSpeed only (Nginx ignores .htaccess; the rules are shown for manual
 * copy there). The block is written with WordPress' own insert_with_markers(), so
 * it only ever touches OUR marker and never a third-party plugin's rules. The
 * rule builder is pure and unit-tested; apply/remove/status wrap the filesystem.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marker name for our managed .htaccess block.
 *
 * @return string
 */
function wpoptimizing_browser_cache_marker() {
	return 'WPOptimizing Browser Cache';
}

/**
 * Build the .htaccess rule lines (array, no BEGIN/END markers — insert_with_markers
 * adds those). Pure and unit-tested. TTLs mirror the industry defaults (WP Rocket):
 * HTML not cached by the browser, CSS/JS a year, images/fonts four months, favicon
 * a week, feeds an hour.
 *
 * @return array<string>
 */
function wpoptimizing_browser_cache_rules() {
	return array(
		'<IfModule mod_deflate.c>',
		'  AddOutputFilterByType DEFLATE text/html text/plain text/css text/javascript application/javascript application/json application/xml application/rss+xml image/svg+xml application/font-woff application/font-woff2 font/woff font/woff2 application/vnd.ms-fontobject application/x-font-ttf',
		'  <IfModule mod_headers.c>',
		'    Header append Vary: Accept-Encoding',
		'  </IfModule>',
		'</IfModule>',
		'<IfModule mod_brotli.c>',
		'  AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css text/javascript application/javascript application/json application/xml image/svg+xml',
		'</IfModule>',
		'<IfModule mod_expires.c>',
		'  ExpiresActive On',
		'  ExpiresByType text/html "access plus 0 seconds"',
		'  ExpiresByType text/css "access plus 1 year"',
		'  ExpiresByType application/javascript "access plus 1 year"',
		'  ExpiresByType text/javascript "access plus 1 year"',
		'  ExpiresByType image/jpeg "access plus 4 months"',
		'  ExpiresByType image/png "access plus 4 months"',
		'  ExpiresByType image/gif "access plus 4 months"',
		'  ExpiresByType image/webp "access plus 4 months"',
		'  ExpiresByType image/avif "access plus 4 months"',
		'  ExpiresByType image/svg+xml "access plus 4 months"',
		'  ExpiresByType image/x-icon "access plus 1 week"',
		'  ExpiresByType font/woff "access plus 4 months"',
		'  ExpiresByType font/woff2 "access plus 4 months"',
		'  ExpiresByType application/font-woff "access plus 4 months"',
		// Legacy names many servers still send: Avada's awb-icons.woff arrives as font/x-woff,
		// and without its own line it got no max-age at all (PSI, artprojectgroup.es, 12/11/2026).
		'  ExpiresByType font/x-woff "access plus 4 months"',
		'  ExpiresByType application/x-font-woff "access plus 4 months"',
		'  ExpiresByType font/ttf "access plus 4 months"',
		'  ExpiresByType font/otf "access plus 4 months"',
		'  ExpiresByType application/x-font-ttf "access plus 4 months"',
		'  ExpiresByType application/vnd.ms-fontobject "access plus 1 month"',
		'  ExpiresByType application/rss+xml "access plus 1 hour"',
		'</IfModule>',
		'<IfModule mod_headers.c>',
		'  <FilesMatch "\.(css|js|woff2?|ttf|otf|eot|svg|jpe?g|png|gif|webp|avif|ico)$">',
		'    Header append Cache-Control "public"',
		'    Header unset Pragma',
		'  </FilesMatch>',
		'</IfModule>',
	);
}

/**
 * Absolute path to the site's .htaccess (home path).
 *
 * @return string
 */
function wpoptimizing_browser_cache_htaccess_path() {
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	return get_home_path() . '.htaccess';
}

/**
 * Is the server Apache/LiteSpeed (so .htaccess is honoured)?
 *
 * @return bool
 */
function wpoptimizing_browser_cache_supported() {
	$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
	if ( '' === $server ) {
		return true; // Unknown (e.g. CLI): allow; writing the block is harmless on Nginx.
	}
	return ( false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' ) );
}

/**
 * Remove our whole managed block (markers included) from .htaccess contents. Pure.
 * insert_with_markers() with an empty array leaves the empty BEGIN/END markers
 * behind, so removal needs a real strip.
 *
 * @param string $contents .htaccess contents.
 * @return string
 */
function wpoptimizing_browser_cache_strip( $contents ) {
	$marker  = wpoptimizing_browser_cache_marker();
	$begin   = preg_quote( '# BEGIN ' . $marker, '/' );
	$end     = preg_quote( '# END ' . $marker, '/' );
	$pattern = '/\n*' . $begin . '.*?' . $end . '[^\n]*\n?/s';
	$out     = preg_replace( $pattern, "\n", (string) $contents );
	return null === $out ? (string) $contents : $out;
}

/**
 * Write (or remove) our managed .htaccess block. Enabling uses WordPress'
 * insert_with_markers (only touches our marker); disabling strips the whole block
 * (markers included) and writes atomically.
 *
 * @param bool $enable True to write the rules, false to remove the block.
 * @return array { ok:bool, code:string, owner?:string } code: done|notwritable|writefail|foreign|nobackup.
 */
function wpoptimizing_browser_cache_apply( $enable ) {
	// Refuse to ADD a second block when another plugin already manages browser caching. Each
	// plugin's block has its own markers so both survive the write, and then the same file
	// types get two sets of Expires/Cache-Control headers with the last one silently winning —
	// nobody can tell from the UI which TTL is in effect. Checked here, at the write, and not
	// only in the screen that offers the button: removal is always allowed, so a site that
	// installs a competitor afterwards can still take our block out.
	$owner = $enable ? wpoptimizing_compat_owner( 'browser_cache' ) : '';
	if ( '' !== $owner ) {
		return array(
			'ok'    => false,
			'code'  => 'foreign',
			'owner' => $owner,
		);
	}
	$path = wpoptimizing_browser_cache_htaccess_path();
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	if ( file_exists( $path ) ) {
		if ( ! wp_is_writable( $path ) ) {
			return array(
				'ok'   => false,
				'code' => 'notwritable',
			);
		}
	} elseif ( ! wp_is_writable( dirname( $path ) ) ) {
		return array(
			'ok'   => false,
			'code' => 'notwritable',
		);
	}

	// Removing a block that is not there changes nothing, and needs no backup.
	$current = file_exists( $path ) ? (string) file_get_contents( $path ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! $enable && ( null === $current || wpoptimizing_browser_cache_strip( $current ) === $current ) ) {
		return array(
			'ok'   => true,
			'code' => 'done',
		);
	}
	// Backup-first: the file as it is now goes into the Backups tab; without it nothing is written.
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	if ( ! function_exists( 'wpoptimizing_backup_file_first' ) || ! wpoptimizing_backup_file_first( $path, 'htaccess' ) ) {
		return array(
			'ok'   => false,
			'code' => 'nobackup',
		);
	}

	if ( $enable ) {
		$ok = insert_with_markers( $path, wpoptimizing_browser_cache_marker(), wpoptimizing_browser_cache_rules() );
		return array(
			'ok'   => (bool) $ok,
			'code' => $ok ? 'done' : 'writefail',
		);
	}

	// Disable: strip the whole block and write atomically.
	if ( ! file_exists( $path ) ) {
		return array(
			'ok'   => true,
			'code' => 'done',
		);
	}
	$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Editing .htaccess to remove our managed block; not a plugin-dir asset.
	$new      = wpoptimizing_browser_cache_strip( $contents );
	if ( $new === $contents ) {
		return array(
			'ok'   => true,
			'code' => 'done',
		);
	}
	// Unique per attempt: a fixed temp name is shared by every concurrent writer of this
	// destination, and here the destination is .htaccess. Two admins saving at the same
	// second would publish half a file over it, which does not degrade the site — it
	// stops it.
	$tmp = wpoptimizing_atomic_tmp_path( $path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Rewriting .htaccess without our block, via a temp file; not a plugin-dir asset.
	if ( false === file_put_contents( $tmp, $new, LOCK_EX ) ) {
		return array(
			'ok'   => false,
			'code' => 'writefail',
		);
	}
	if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		wp_delete_file( $tmp );
		return array(
			'ok'   => false,
			'code' => 'writefail',
		);
	}
	return array(
		'ok'   => true,
		'code' => 'done',
	);
}

/**
 * Is our managed block currently present in the .htaccess?
 *
 * @return bool
 */
function wpoptimizing_browser_cache_active() {
	$path = wpoptimizing_browser_cache_htaccess_path();
	if ( ! is_readable( $path ) ) {
		return false;
	}
	$contents = (string) file_get_contents( $path, false, null, 0, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads .htaccess head to report our block state; not a plugin-dir asset.
	return false !== strpos( $contents, '# BEGIN ' . wpoptimizing_browser_cache_marker() );
}

/**
 * Status snapshot for the admin UI.
 *
 * @return array
 */
function wpoptimizing_browser_cache_status() {
	$path = wpoptimizing_browser_cache_htaccess_path();
	return array(
		'active'    => wpoptimizing_browser_cache_active(),
		'supported' => wpoptimizing_browser_cache_supported(),
		'path'      => $path,
		'writable'  => file_exists( $path ) ? wp_is_writable( $path ) : wp_is_writable( dirname( $path ) ),
	);
}
