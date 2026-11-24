<?php
/**
 * Somewhere to keep the big values that do not belong in wp_options.
 *
 * WHY THIS EXISTS. A profile of a production store found this plugin holding 1.2 MB of
 * serialised data across four rows of wp_options — the activity baseline (564 KB), the
 * activity journal (321 KB), the runtime ownership evidence (194 KB) and the cleanup report
 * history (123 KB). Every one of them is correctly marked autoload = false, so none of it is
 * read on a normal request, and that is exactly the defence that stops being convincing when
 * the plugin doing it is the one that tells other plugins not to.
 *
 * WHY FILES AND NOT A CUSTOM TABLE, which is what the report suggested. A table means dbDelta,
 * a schema version to migrate, a per-blog decision on multisite, a DROP on uninstall and a
 * second storage strategy in a plugin that already has one: WPOPT_DIR, where the scan CSVs,
 * the JSONL history and the backup manifests already live, with atomic writes, pruning and a
 * daily cron. The data here is read whole and written whole — it is never queried — so a table
 * would buy nothing and cost a migration. And the deeper objection ("1.2 MB of serialised
 * blobs in the database") would survive the move to a table unchanged.
 *
 * MIGRATION IS LAZY AND HAS NO UPGRADE ROUTINE. The first read moves the value out of the
 * option and into a file; the option is deleted only after the file is on disk. An install
 * that never reads a given blob simply keeps it in wp_options, which is where it already is.
 *
 * THE FILESYSTEM IS ALLOWED TO SAY NO. Read-only wp-content, a hardened host, a full disk: if
 * the write fails, the value goes back to the option and the caller cannot tell. Losing a
 * journal because a directory was not writable would be a far worse bug than the one this
 * file is here to fix.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Absolute path for a blob, or '' when there is nowhere to put it.
 *
 * @since 0.1.0
 * @param string $name Blob name (a-z0-9- only; it becomes a file name).
 * @return string
 */
function wpoptimizing_blob_path( $name ) {
	$name = preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $name ) );
	if ( '' === $name || ! defined( 'WPOPT_DIR' ) ) {
		return '';
	}
	return WPOPT_DIR . '/' . $name . '.json';
}

/**
 * Read a blob, migrating it out of its legacy option the first time.
 *
 * @since 0.1.0
 * @param string $name    Blob name.
 * @param string $option  Legacy option key it used to live in.
 * @param mixed  $default Value when neither exists.
 * @return mixed
 */
function wpoptimizing_blob_get( $name, $option, $default = array() ) {
	$path = wpoptimizing_blob_path( $name );

	if ( '' !== $path && is_readable( $path ) ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Our own data file, not a remote resource.
		if ( false !== $raw && '' !== $raw ) {
			$value = json_decode( $raw, true );
			if ( null !== $value || 'null' === trim( $raw ) ) {
				return $value;
			}
		}
		// Unreadable or truncated (a half-written file from a killed request): fall through to
		// the option rather than handing the caller a corrupt value.
	}

	$value = get_option( $option, null );
	if ( null === $value ) {
		return $default;
	}

	// First read after this file shipped: move it, then drop the option. Order matters — the
	// option is the only copy until the file is on disk.
	if ( wpoptimizing_blob_write_file( $name, $value ) ) {
		delete_option( $option );
	}

	return $value;
}

/**
 * Write a blob. Falls back to the legacy option when the filesystem refuses.
 *
 * @since 0.1.0
 * @param string $name   Blob name.
 * @param string $option Legacy option key, used as the fallback store.
 * @param mixed  $value  Value to store (must be JSON-encodable).
 * @return bool True when the value is stored somewhere.
 */
function wpoptimizing_blob_put( $name, $option, $value ) {
	if ( wpoptimizing_blob_write_file( $name, $value ) ) {
		return true;
	}

	// Nowhere to write: keep it in the option, explicitly not autoloaded.
	return (bool) update_option( $option, $value, false );
}

/**
 * Write a blob to its file, and ONLY to its file.
 *
 * Separate from wpoptimizing_blob_put() because the migration in wpoptimizing_blob_get() has
 * to know which store actually took the value. When it used put(), a site whose data
 * directory is not writable went: write falls back to the option → put() reports success →
 * migration deletes the option. The first read of each blob destroyed it. The two answers
 * "it is stored" and "it is stored IN A FILE" are not the same question, and only one of them
 * makes it safe to delete the original.
 *
 * @since 0.1.0
 * @param string $name  Blob name.
 * @param mixed  $value Value to store.
 * @return bool True only when the value is on disk.
 */
function wpoptimizing_blob_write_file( $name, $value ) {
	$path = wpoptimizing_blob_path( $name );
	$json = wp_json_encode( $value );

	if ( '' !== $path && false !== $json ) {
		$dir = dirname( $path );
		if ( is_dir( $dir ) || wp_mkdir_p( $dir ) ) {
			// tmp + rename in the same directory: rename() is atomic there, so a concurrent
			// reader never sees a half-written file. A plain write can be read mid-write, and
			// json_decode() of half a journal returns null.
			// One rule for every atomic write; wp_generate_password() also needs pluggable.php,
			// which is not loaded on every path that reaches here.
			$tmp = wpoptimizing_atomic_tmp_path( $path );
			if ( false !== file_put_contents( $tmp, $json, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Our own data directory.
				if ( @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic tmp+rename in the same directory on purpose (WP_Filesystem::move() is not atomic); failure is handled below.
					return true;
				}
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Best-effort cleanup of our own temp file.
			}
		}
	}

	return false;
}

/**
 * Remove a blob from both stores. For uninstall, and for a reset.
 *
 * @since 0.1.0
 * @param string $name   Blob name.
 * @param string $option Legacy option key.
 * @return void
 */
function wpoptimizing_blob_delete( $name, $option ) {
	$path = wpoptimizing_blob_path( $name );
	if ( '' !== $path && file_exists( $path ) ) {
		wp_delete_file( $path );
	}
	delete_option( $option );
}
