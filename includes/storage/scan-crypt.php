<?php
/**
 * At-rest encryption for the local scan/impact working CSVs.
 *
 * These files (wp-scan-db/fs, wpoptimizing-impact-*) hold our attribution method —
 * the differentiator of the plugin — so we don't want them readable as plaintext on
 * a customer's disk (FTP) or fetchable on servers that ignore .htaccess (nginx). The
 * downloaded KB (db.enc/files.enc) is already encrypted; this covers the local
 * working files too.
 *
 * Approach: the scan writes plaintext during its run (so the multi-stage pipeline and
 * mid-scan reads are untouched); at FINALIZE each file is encrypted in place. Readers
 * go through wpoptimizing_scan_read_handle(), which transparently decrypts when the
 * file carries the magic header and is a no-op for plaintext — so it is safe even
 * mid-scan and on files written before this feature existed.
 *
 * Key: derived from wp_salt() (the empty-token branch of wpoptimizing_derive_key()).
 * That is site/network-local and STABLE — independent of the rotating per-install
 * data token — so multisite network reads of other blogs' files work and a token
 * rotation never strands a file. A failed decrypt degrades to "no data" (re-scan),
 * never a fatal.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/** Byte prefix marking an at-rest-encrypted scan working file. */
if ( ! defined( 'WPOPT_SCAN_ENC_MAGIC' ) ) {
	define( 'WPOPT_SCAN_ENC_MAGIC', "WPOPTENC1\n" );
}

/**
 * Whether a file is encrypted at rest by this module (starts with the magic header).
 * Cheap: reads only the first few bytes.
 *
 * @param string $path Absolute file path.
 * @return bool
 */
function wpoptimizing_scan_file_is_encrypted( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! is_readable( $path ) ) {
		return false;
	}
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $fh ) {
		return false;
	}
	$head = (string) fread( $fh, strlen( WPOPT_SCAN_ENC_MAGIC ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- partial binary header probe; WP_Filesystem has no streaming/partial read.
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return WPOPT_SCAN_ENC_MAGIC === $head;
}

/**
 * Encrypt a scan working CSV in place (atomic). No-op when the file is missing,
 * empty, already encrypted, or when encryption is unavailable (the file then stays
 * usable as plaintext — protection is best-effort, never breaks the scan).
 *
 * @param string $path Absolute file path.
 * @return void
 */
function wpoptimizing_scan_encrypt_file( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! file_exists( $path ) || (int) @filesize( $path ) <= 0 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return;
	}
	if ( ! function_exists( 'wpoptimizing_encrypt_csv' ) ) {
		return;
	}
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $fh ) {
		return;
	}
	$magic_len = strlen( WPOPT_SCAN_ENC_MAGIC );
	$head      = (string) fread( $fh, $magic_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- partial binary header probe; WP_Filesystem has no streaming/partial read.
	if ( WPOPT_SCAN_ENC_MAGIC === $head ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return; // Already encrypted.
	}
	rewind( $fh );
	$plain = stream_get_contents( $fh );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	if ( false === $plain || '' === $plain ) {
		return;
	}
	$enc = wpoptimizing_encrypt_csv( $plain, '' ); // '' => wp_salt-derived, stable key.
	if ( is_wp_error( $enc ) || ! is_string( $enc ) || '' === $enc ) {
		return; // Leave plaintext rather than lose data.
	}
	$tmp = wpoptimizing_atomic_tmp_path( $path . '.enc' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === file_put_contents( $tmp, WPOPT_SCAN_ENC_MAGIC . $enc, LOCK_EX ) ) {
		return;
	}
	if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		wp_delete_file( $tmp );
	}
}

/**
 * Move aside a working file that the key cannot open, so its writers start fresh.
 *
 * The key comes from wp_salt(): once the salts are rotated, every file encrypted before is
 * unreadable for good. Readers degraded to "no data", but the appenders (the runtime map
 * and its resolver fallback) refuse to touch an encrypted file they cannot read, so they
 * skipped every write, forever, without a word. Only an authentication failure or a broken
 * payload counts, and only with wp_salt() loaded: a missing OpenSSL or an early request
 * says nothing about the file.
 *
 * @param string $path  Absolute file path.
 * @param mixed  $error What wpoptimizing_decrypt_csv() returned.
 * @return bool True when the file was moved.
 */
function wpoptimizing_scan_quarantine_undecryptable( $path, $error ) {
	if ( ! function_exists( 'wp_salt' ) || ! is_wp_error( $error ) ) {
		return false;
	}
	if ( ! in_array( $error->get_error_code(), array( 'decrypt_failed', 'invalid_payload' ), true ) ) {
		return false;
	}
	return @rename( (string) $path, (string) $path . '.undecryptable' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- the old copy is kept beside it, never deleted.
}

/**
 * Open a scan working CSV for reading, transparently decrypting when it is encrypted
 * at rest. Returns a readable stream handle (a php://temp with the decrypted bytes
 * for encrypted files, the plain file handle otherwise), or false when the file is
 * missing/unreadable or cannot be decrypted.
 *
 * Drop-in replacement for `fopen( $path, 'r' )` at every scan-CSV read site.
 *
 * @param string $path Absolute file path.
 * @return resource|false
 */
function wpoptimizing_scan_read_handle( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! file_exists( $path ) || ! is_readable( $path ) ) {
		return false;
	}
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $fh ) {
		return false;
	}
	$magic_len = strlen( WPOPT_SCAN_ENC_MAGIC );
	$head      = (string) fread( $fh, $magic_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- partial binary header probe; WP_Filesystem has no streaming/partial read.
	if ( WPOPT_SCAN_ENC_MAGIC !== $head ) {
		rewind( $fh );
		return $fh; // Plaintext: hand back the real handle.
	}
	$payload = stream_get_contents( $fh );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	if ( false === $payload || '' === $payload || ! function_exists( 'wpoptimizing_decrypt_csv' ) ) {
		return false;
	}
	$plain = wpoptimizing_decrypt_csv( (string) $payload, '' );
	if ( is_wp_error( $plain ) || ! is_string( $plain ) ) {
		wpoptimizing_scan_quarantine_undecryptable( $path, $plain );
		return false;
	}
	$tmp = fopen( 'php://temp', 'r+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $tmp ) {
		return false;
	}
	fwrite( $tmp, $plain ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	rewind( $tmp );
	return $tmp;
}
