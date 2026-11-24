<?php
/**
 * One rule for every atomic write in the plugin.
 *
 * Its own file, and required at file scope, for two reasons: always-loaded code calls it (the
 * scan encryptor), and the test suite has to exercise the real function rather than a copy that
 * would drift from it.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * A temp path for an atomic write, unique to this attempt.
 *
 * Every atomic writer in this plugin followed the same shape — write "<dest>.tmp", then
 * rename it over the destination — and every one of them used that FIXED name. Two requests
 * writing the same destination therefore shared one temp file: the second truncates and
 * rewrites it while the first is still working, and then whichever reaches rename() first
 * publishes the other's bytes. The window is small and the failure is silent, which is why it
 * survived: the destination ends up holding a half-written file, and for the encrypted ones
 * that means the whole file stops decrypting and its contents are simply gone.
 *
 * rename() is atomic within a filesystem; sharing the source of that rename is what was not.
 * wpoptimizing_atomic_install_file() already got this right for the drop-ins; this is the same
 * rule for everything that writes data.
 *
 * Unique names cannot be overwritten by the next attempt the way a fixed one was, so a process
 * killed mid-write leaves its temp behind. Each call sweeps the stale ones for its own
 * destination first — bounded, and only the files this destination created.
 *
 * @since 0.1.0
 * @param string $path Destination path.
 * @return string Temp path to write to.
 */
function wpoptimizing_atomic_tmp_path( $path ) {
	$path = (string) $path;

	// Opportunistic sweep: temps older than an hour cannot belong to a live request.
	$stale = glob( $path . '.tmp.*' );
	if ( is_array( $stale ) ) {
		$cutoff = time() - HOUR_IN_SECONDS;
		$seen   = 0;
		foreach ( $stale as $file ) {
			if ( ++$seen > 50 ) {
				break; // Never turn a write into a directory scan.
			}
			if ( is_file( $file ) && (int) filemtime( $file ) < $cutoff ) {
				wp_delete_file( $file );
			}
		}
	}

	// uniqid() and not wp_generate_password(): this can run at plugins_loaded, before
	// pluggable.php. The suffix only has to be unique, never unpredictable.
	return $path . '.tmp.' . uniqid( '', true );
}
