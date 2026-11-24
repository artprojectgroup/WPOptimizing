<?php
/**
 * The files an attachment owns besides its sizes — the single list, in one place.
 *
 * An attachment is not just the file in `file` plus the generated `sizes`. WordPress has long
 * kept the un-scaled `original_image` for big uploads, and since 7.1 the client-side media flow
 * sideloads more: when the browser converts a HEIC to something web-viewable it keeps the
 * source under `source_image`, and when it turns an opaque animated GIF into a web-safe video
 * it writes the MP4/WebM and a first-frame poster under `animated_video` and
 * `animated_video_poster`.
 *
 * Every one of these sits in the attachment's own directory and is referenced by NOTHING except
 * that metadata array. So a scanner that walks the uploads folder and asks "does anything point
 * at this file?" will answer no, call it an orphan and offer it for deletion — and the source
 * original it deletes is the one file the site cannot recreate.
 *
 * Two scanners have to agree about this (the filesystem orphan scan and the media reference
 * sweep), and they used to keep separate, shorter lists. A list that exists twice is a list
 * that goes stale once: `original_image` was in both, and the three keys WordPress added in 7.1
 * were in neither. This is the only list; AttachmentCompanionsTest fails if a scanner stops
 * using it.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Metadata keys that each hold the filename of one companion file.
 *
 * @since 0.1.0
 * @return string[]
 */
function wpoptimizing_attachment_companion_keys() {
	/**
	 * Filters the attachment-metadata keys treated as companion files.
	 *
	 * Anything listed here is considered IN USE by the orphan scans. Adding a key is how a
	 * plugin that sideloads its own companion keeps it from being reported as an orphan.
	 *
	 * @since 0.1.0
	 * @param string[] $keys Metadata keys.
	 */
	return (array) apply_filters(
		'wpoptimizing_attachment_companion_keys',
		array( 'original_image', 'source_image', 'animated_video', 'animated_video_poster' )
	);
}

/**
 * The companion filenames recorded in one attachment's metadata.
 *
 * Returns basenames, which is what both callers need: every companion lives in the same
 * directory as the attachment itself.
 *
 * @since 0.1.0
 * @param mixed $meta Attachment metadata (anything; non-arrays yield an empty list).
 * @return string[] Basenames, de-duplicated, in the order the keys are listed.
 */
function wpoptimizing_attachment_companion_files( $meta ) {
	if ( ! is_array( $meta ) ) {
		return array();
	}
	$out = array();
	foreach ( wpoptimizing_attachment_companion_keys() as $key ) {
		$key = (string) $key;
		if ( '' === $key || empty( $meta[ $key ] ) || ! is_string( $meta[ $key ] ) ) {
			continue;
		}
		// A key may legitimately hold a path relative to the uploads root; take the basename
		// so a caller that prepends the attachment's directory cannot build a doubled path.
		$name = basename( str_replace( '\\', '/', $meta[ $key ] ) );
		if ( '' !== $name ) {
			$out[ $name ] = $name;
		}
	}

	return array_values( $out );
}
