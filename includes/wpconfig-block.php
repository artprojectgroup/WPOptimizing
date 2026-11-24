<?php
/**
 * Generic managed-block editor for wp-config.php.
 *
 * Several features need to add or remove a small marked block in wp-config.php:
 * the WP-Cron control toggles DISABLE_WP_CRON, the page cache toggles WP_CACHE,
 * and more may follow. This is the ONE shared implementation — marker helpers,
 * idempotent insert/strip on a string (pure, unit-tested), and a backup-first +
 * atomic (temp+rename) + verified apply that touches the filesystem. Feature
 * modules pass only their block name and PHP body; they never reimplement the
 * file I/O, so a fix here (e.g. atomicity) covers all of them.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Begin marker for a named managed block.
 *
 * @param string $name Block name (e.g. 'WP-Cron', 'Page-Cache').
 * @return string
 */
function wpoptimizing_wpconfig_marker_begin( $name ) {
	return '/* BEGIN WPOptimizing ' . $name . ' */';
}

/**
 * End marker for a named managed block.
 *
 * @param string $name Block name.
 * @return string
 */
function wpoptimizing_wpconfig_marker_end( $name ) {
	return '/* END WPOptimizing ' . $name . ' */';
}

/**
 * The full managed block: begin marker, body, end marker. The body is trimmed of
 * surrounding blank lines and wrapped with exactly one newline on each side so the
 * output is stable regardless of how the caller formats it.
 *
 * @param string $name Block name.
 * @param string $body PHP between the markers.
 * @return string
 */
function wpoptimizing_wpconfig_managed_block( $name, $body ) {
	return wpoptimizing_wpconfig_marker_begin( $name ) . "\n"
		. trim( (string) $body, "\n" ) . "\n"
		. wpoptimizing_wpconfig_marker_end( $name );
}

/**
 * Does the content already contain the named managed block?
 *
 * @param string $contents wp-config.php contents.
 * @param string $name     Block name.
 * @return bool
 */
function wpoptimizing_wpconfig_has_block( $contents, $name ) {
	return false !== strpos( (string) $contents, wpoptimizing_wpconfig_marker_begin( $name ) )
		&& false !== strpos( (string) $contents, wpoptimizing_wpconfig_marker_end( $name ) );
}

/**
 * Insert the named block right after the opening PHP tag. Idempotent: returns the
 * input unchanged when the block is already present, and null when no `<?php`
 * opening tag is found (never blindly prepend to an unknown file).
 *
 * @param string $contents wp-config.php contents.
 * @param string $name     Block name.
 * @param string $body     PHP between the markers.
 * @return string|null Modified contents, or null when the opening tag is missing.
 */
function wpoptimizing_wpconfig_add_block( $contents, $name, $body ) {
	$contents = (string) $contents;
	if ( wpoptimizing_wpconfig_has_block( $contents, $name ) ) {
		return $contents;
	}
	$pos = strpos( $contents, '<?php' );
	if ( false === $pos ) {
		return null;
	}
	$after  = $pos + strlen( '<?php' );
	$nl     = strpos( $contents, "\n", $after );
	$insert = false === $nl ? strlen( $contents ) : $nl + 1;
	$block  = "\n" . wpoptimizing_wpconfig_managed_block( $name, $body ) . "\n";
	return substr( $contents, 0, $insert ) . $block . substr( $contents, $insert );
}

/**
 * Remove the named managed block (and the blank lines that wrap it). Returns the
 * input unchanged when the block is absent.
 *
 * @param string $contents wp-config.php contents.
 * @param string $name     Block name.
 * @return string
 */
function wpoptimizing_wpconfig_strip_block( $contents, $name ) {
	$contents = (string) $contents;
	if ( ! wpoptimizing_wpconfig_has_block( $contents, $name ) ) {
		return $contents;
	}
	$begin   = preg_quote( wpoptimizing_wpconfig_marker_begin( $name ), '/' );
	$end     = preg_quote( wpoptimizing_wpconfig_marker_end( $name ), '/' );
	$pattern = '/\n*' . $begin . '.*?' . $end . '\n*/s';
	$out     = preg_replace( $pattern, "\n", $contents );
	return null === $out ? $contents : $out;
}

/**
 * Locate wp-config.php using WordPress' own resolution order (in ABSPATH, or one
 * directory up when that copy is not part of another WordPress install).
 *
 * @return string Absolute path, or '' when it cannot be located.
 */
function wpoptimizing_wpconfig_path() {
	if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
		return ABSPATH . 'wp-config.php';
	}
	$parent = dirname( ABSPATH );
	if ( file_exists( $parent . '/wp-config.php' ) && ! file_exists( $parent . '/wp-settings.php' ) ) {
		return $parent . '/wp-config.php';
	}
	return '';
}

/**
 * Where the copy of wp-config.php is kept: the plugin's protected data folder, as a .php
 * file whose first line exits. Requested over HTTP it runs that line and prints nothing,
 * whatever the server (nginx ignores the folder's .htaccess).
 *
 * @return string Absolute path, or '' when the data folder is not known yet.
 */
function wpoptimizing_wpconfig_backup_path() {
	return defined( 'WPOPT_PROTECTED_DIR' ) ? WPOPT_PROTECTED_DIR . '/wp-config-backup.php' : '';
}

/** First line of the backup: it stops execution before any of the copied file runs. */
const WPOPTIMIZING_WPCONFIG_BACKUP_GUARD = "<?php exit; // WPOptimizing: copy of wp-config.php, never executed. ?>\n";

/**
 * Save a copy of wp-config.php before it is edited (best effort: a failed copy must not
 * block the verified write).
 *
 * @param string $contents Current contents of wp-config.php.
 * @return bool Whether the copy was written.
 */
function wpoptimizing_wpconfig_backup( $contents ) {
	$file = wpoptimizing_wpconfig_backup_path();
	if ( '' === $file || ! wp_mkdir_p( dirname( $file ) ) ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Private copy of wp-config.php in the plugin's data folder.
	$ok = false !== @file_put_contents( $file, WPOPTIMIZING_WPCONFIG_BACKUP_GUARD . $contents, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort.
	if ( $ok ) {
		@chmod( $file, 0600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Only the owner may read the credentials.
	}
	return $ok;
}

/**
 * Remove the plain-text sidecar copy (wp-config.php.wpopt-bak) left next to wp-config.php by
 * earlier versions, which any visitor could download. Its contents move to the protected copy
 * first unless that copy already exists.
 *
 * @return bool Whether a sidecar was found and removed.
 */
function wpoptimizing_wpconfig_remove_legacy_backup() {
	$removed = false;
	foreach ( array( ABSPATH, trailingslashit( dirname( ABSPATH ) ) ) as $dir ) {
		$legacy = $dir . 'wp-config.php.wpopt-bak';
		if ( ! is_file( $legacy ) || is_link( $legacy ) ) {
			continue;
		}
		$target = wpoptimizing_wpconfig_backup_path();
		if ( '' !== $target && ! file_exists( $target ) && is_readable( $legacy ) ) {
			wpoptimizing_wpconfig_backup( (string) file_get_contents( $legacy ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Moving our own backup out of the web root.
		}
		wp_delete_file( $legacy );
		$removed = $removed || ! file_exists( $legacy );
	}
	return $removed;
}

/**
 * Add or remove a named managed block in wp-config.php. Backs the file up first
 * (protected copy), writes atomically via a temp file, and verifies the result
 * before returning.
 *
 * @param string $name Block name (e.g. 'WP-Cron', 'Page-Cache').
 * @param string $body PHP between the markers (used only when $add is true).
 * @param bool   $add  True to insert the block, false to remove it.
 * @return array {
 *     @type bool   $ok   Whether the change was applied.
 *     @type string $code Machine code: done|nochange|notfound|notwritable|badformat|writefail|nobackup.
 * }
 */
function wpoptimizing_wpconfig_apply_block( $name, $body, $add ) {
	$path = wpoptimizing_wpconfig_path();
	if ( '' === $path ) {
		return array(
			'ok'   => false,
			'code' => 'notfound',
		);
	}
	if ( ! wp_is_writable( $path ) ) {
		return array(
			'ok'   => false,
			'code' => 'notwritable',
		);
	}
	$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Editing wp-config.php to toggle a managed constant block; not a plugin-dir asset.
	$new      = $add ? wpoptimizing_wpconfig_add_block( $contents, $name, $body ) : wpoptimizing_wpconfig_strip_block( $contents, $name );

	if ( null === $new ) {
		return array(
			'ok'   => false,
			'code' => 'badformat',
		);
	}
	if ( $new === $contents ) {
		return array(
			'ok'   => true,
			'code' => 'nochange',
		);
	}

	// Backup-first: keep a copy so the edit is always reversible. Never as a sidecar next to
	// wp-config.php: the web server sends an unknown extension as plain text, credentials
	// included (seen live on 18/10/2026). See wpoptimizing_wpconfig_backup().
	wpoptimizing_wpconfig_backup( $contents );
	// And the copy the Backups tab lists and restores. Not best effort: without it the file is
	// left as it is (rule of 19/10/2026: no backup, no change). One exception, on purpose: taking
	// OUR block out while the plugin is being deactivated or uninstalled does not wait for it. A
	// WP_CACHE or DISABLE_WP_CRON left behind by a plugin that is gone keeps acting on the site,
	// which is worse than the edit a backup would undo.
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	$backed = function_exists( 'wpoptimizing_backup_file_first' ) && wpoptimizing_backup_file_first( $path, 'wpconfig' );
	if ( ! $backed && ( $add || ! defined( 'WPOPT_TEARDOWN' ) ) ) {
		return array(
			'ok'   => false,
			'code' => 'nobackup',
		);
	}
	$mode = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable mode just means nothing to preserve.

	// Unique per attempt: a fixed temp name is shared by every concurrent writer of this
	// destination, and here the destination is wp-config.php. Two admins saving at the same
	// second would publish half a file over it, which does not degrade the site — it
	// stops it.
	$tmp = wpoptimizing_atomic_tmp_path( $path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Toggles a managed block in wp-config.php via a temp file; not a plugin-dir asset.
	$wrote = false !== file_put_contents( $tmp, $new, LOCK_EX );
	if ( ! $wrote ) {
		return array(
			'ok'   => false,
			'code' => 'writefail',
		);
	}
	// The rename puts a new file in place: without this a hardened 0600/0640 wp-config
	// would come out with the default 0644, readable by other accounts on shared hosting.
	if ( false !== $mode ) {
		@chmod( $tmp, $mode & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Keep the original permissions of wp-config.php.
	}
	// Atomic swap into place (rename is atomic on POSIX, so a concurrent request
	// reading wp-config.php never sees a half-written file).
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace of wp-config.php; WP_Filesystem::move() is not guaranteed atomic. Not a plugin-dir asset.
	if ( ! @rename( $tmp, $path ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Fallback in-place write when rename() is unavailable; not a plugin-dir asset.
		$wrote = false !== file_put_contents( $path, $new, LOCK_EX );
		wp_delete_file( $tmp ); // Remove the leftover temp file.
		if ( ! $wrote ) {
			return array(
				'ok'   => false,
				'code' => 'writefail',
			);
		}
	}

	// Verify the file now matches the intended state.
	$check = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Verify the wp-config.php edit landed; not a plugin-dir asset.
	$has   = wpoptimizing_wpconfig_has_block( $check, $name );
	if ( $add === $has ) {
		return array(
			'ok'   => true,
			'code' => 'done',
		);
	}
	return array(
		'ok'   => false,
		'code' => 'writefail',
	);
}

// Copies left in the web root by earlier versions go as soon as an administrator loads wp-admin.
add_action( 'admin_init', 'wpoptimizing_wpconfig_remove_legacy_backup' );
