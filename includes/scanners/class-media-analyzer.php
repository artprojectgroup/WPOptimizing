<?php
/**
 * Media analyzer.
 *
 * Inspects the media library / uploads directory for:
 *  - orphan files: images on disk with no DB record (_wp_attached_file / sizes),
 *  - orphan thumbnails: generated -WxH sizes whose record is gone,
 *  - duplicate images: identical content (hash), capped for I/O.
 *
 * Read-only: produces a report. The free plan shows everything; deletion (a
 * future premium action) is not performed here. The matching/parsing helpers are
 * pure (no WP, no I/O) so they can be unit-tested.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Media_Analyzer {

	/** Image extensions considered. */
	const IMAGE_EXTS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff', 'ico', 'svg' );

	/** LIKE values that pick serialized, JSON-object and JSON-array values. */
	const BLOB_LIKES = array( 'a:%', '{%', '[%' );

	/**
	 * Whether a path has a known image extension. Pure.
	 *
	 * @param string $path File path or name.
	 * @return bool
	 */
	public static function is_image_file( $path ) {
		$ext = strtolower( (string) pathinfo( (string) $path, PATHINFO_EXTENSION ) );
		return '' !== $ext && in_array( $ext, self::IMAGE_EXTS, true );
	}

	/**
	 * Candidate "source" names for a possible derivative file, by peeling derivative
	 * markers off the tail (directory part, if any, is preserved). Handles, in any
	 * combination: an appended next-gen extension (photo-500x383.jpg.webp → .jpg),
	 * a retina suffix (photo-500x383@2x.jpg → photo-500x383.jpg, also @1.5x / @3x),
	 * and a -WxH size (photo-500x383.jpg → photo.jpg). Case-preserving and pure, so the
	 * caller can match against either a case-sensitive (full-path) or lowercased set.
	 *
	 * @param string $name File name or upload-relative path.
	 * @return string[] Distinct source candidates (excluding the input itself).
	 */
	public static function derivative_sources( $name ) {
		$name = (string) $name;

		// Suffixes stack, and they stack in any order: WordPress adds -scaled and -WxH, the
		// optimisers append .webp/.avif, retina tooling adds @2x, and a rename plugin can leave
		// its own marker in the middle of all that. Stripping them in one fixed-order pass only
		// unwinds the combinations that happen to match that order — "foto@2x-300x200.jpg"
		// resolved to "foto@2x.jpg" and never reached "foto.jpg", and anything carrying -scaled
		// was not unwound at all.
		//
		// That matters because an unresolved derivative looks like a file nothing references,
		// and a file nothing references is offered for deletion. On a real 6,010-file library
		// this single defect accounted for 728 files reported as orphans that were not.
		//
		// So: apply every rule repeatedly until nothing changes, collecting each intermediate
		// form, because a caller tests all of them against the registered set.
		//
		// Termination is guaranteed by the seen-set, not by the round cap: a form is never
		// revisited, and every rule shortens the name, so the reachable set is finite. The cap
		// bounds the WORK instead — a name carrying sixty stacked suffixes is not a library
		// this needs to resolve, it is something to stop chewing on, and this walk runs once
		// per file across an entire uploads folder.
		$rules = array(
			// Appended next-gen extension: X.<raster>.<webp|avif> → X.<raster>.
			'/^(.+\.(?:jpe?g|png|gif|bmp|tiff?))\.(?:webp|avif)$/i' => '$1',
			// Retina marker before the extension: @2x / @3x / @1.5x.
			'/@[0-9]+(?:[._][0-9]+)?x(\.[a-z0-9]+)$/i' => '$1',
			// -WxH size suffix (any case: a camera's "IMG_1.JPG" gets "IMG_1-300x200.JPG").
			'/-\d+x\d+(\.[a-z0-9]+)$/i'                => '$1',
			// WordPress' big-image copy.
			'/-scaled(\.[a-z0-9]+)$/i'                 => '$1',
		);

		$seen    = array( $name => true );
		$pending = array( $name );
		$rounds  = 0;
		while ( $pending && $rounds < 10 ) {
			++$rounds;
			$next = array();
			foreach ( $pending as $base ) {
				foreach ( $rules as $pattern => $replacement ) {
					$stripped = (string) preg_replace( $pattern, $replacement, $base );
					if ( '' !== $stripped && $stripped !== $base && ! isset( $seen[ $stripped ] ) ) {
						$seen[ $stripped ] = true;
						$next[]            = $stripped;
					}
				}
			}
			$pending = $next;
		}

		unset( $seen[ $name ] );

		return array_keys( $seen );
	}

	/**
	 * The trunk of a file name: what is left once every stacked suffix and the extension are
	 * gone.
	 *
	 * A file in uploads is not identified by its name but by this. "foto.jpg",
	 * "foto-300x200.jpg", "foto-scaled.jpg" and "foto.jpg.webp" are four names for one unit,
	 * and an audit that separates them is lying to the user: after a WebP conversion the
	 * library original can be "foto.webp" while its own sizes are still "foto-300x200.jpg".
	 *
	 * Built on derivative_sources() rather than on a second set of rules. A list that exists
	 * twice is a list that goes stale once, and this project has already paid for that lesson.
	 *
	 * @since 0.1.0
	 * @param string $name File name (basename or upload-relative path).
	 * @return string Trunk, without directory or extension.
	 */
	public static function trunk( $name ) {
		$name = basename( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		// The most-reduced form the chain can reach: the shortest one it produced, or the name
		// itself when it is already a trunk.
		$base = $name;
		foreach ( self::derivative_sources( $name ) as $candidate ) {
			if ( strlen( $candidate ) < strlen( $base ) ) {
				$base = $candidate;
			}
		}
		$dot = strrpos( $base, '.' );

		return ( false === $dot || 0 === $dot ) ? $base : substr( $base, 0, $dot );
	}

	/**
	 * Whether $rel is a generated derivative (retina, next-gen copy, or size) of a file
	 * that IS in the registered set — i.e. a real attachment's companion, not an orphan.
	 *
	 * @param string             $rel        Upload-relative path.
	 * @param array<string,true> $registered Registered upload-relative paths.
	 * @return bool
	 */
	public static function is_registered_derivative( $rel, array $registered ) {
		foreach ( self::derivative_sources( $rel ) as $src ) {
			if ( isset( $registered[ $src ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a basename is a server/system stub that WordPress and plugins drop into
	 * every uploads folder (directory-listing guards, OS metadata). These are never media
	 * and must never be reported as orphans. Pure.
	 *
	 * @param string $name Basename (any case).
	 * @return bool
	 */
	public static function is_system_stub( $name ) {
		$name  = strtolower( trim( (string) $name ) );
		$stubs = array(
			'index.php',
			'index.html',
			'index.htm',
			'.htaccess',
			'web.config',
			'.ds_store',
			'thumbs.db',
			'desktop.ini',
			'error_log',
			'.user.ini',
			'php.ini',
		);
		return in_array( $name, $stubs, true );
	}

	/**
	 * Whether an extension is a real media/document upload type WordPress recognises
	 * (image, audio, video, PDF, office doc, archive…). Anything else found in /uploads —
	 * GeoIP .dat files, .log, .json, .php and other plugin data — is NOT library media and
	 * should be reported as "other files", never as a deletable media orphan. Cached.
	 *
	 * @param string $ext Lowercased extension (no dot).
	 * @return bool
	 */
	public static function is_uploadable_media_ext( $ext ) {
		$ext = strtolower( (string) $ext );
		if ( '' === $ext ) {
			return false;
		}
		static $exts = null;
		if ( null === $exts ) {
			$exts  = array();
			$mimes = function_exists( 'wp_get_mime_types' ) ? wp_get_mime_types() : array();
			foreach ( array_keys( (array) $mimes ) as $group ) {
				foreach ( explode( '|', (string) $group ) as $e ) {
					$e = strtolower( trim( $e ) );
					if ( '' !== $e ) {
						$exts[ $e ] = true;
					}
				}
			}
			// Always treat our known image set as media even if a filter trimmed the map.
			foreach ( self::IMAGE_EXTS as $e ) {
				$exts[ $e ] = true;
			}
		}
		return isset( $exts[ $ext ] );
	}

	/**
	 * Whether a .webp/.avif file is a next-gen copy generated by an image optimizer
	 * (LiteSpeed Cache, EWWW, ShortPixel, Optimole, Imagify…) — NOT a real orphan. Two
	 * shapes are recognised:
	 *
	 *   photo.jpg.webp  → appended extension (LiteSpeed/our default): the double
	 *                     image extension is unambiguous, so it is treated as a
	 *                     derivative whether or not the source is still on disk (a
	 *                     leftover .jpg.webp is still an optimizer artifact, not media).
	 *   photo.webp      → replaced extension: only a derivative when a sibling source
	 *                     (photo.jpg|jpeg|png|gif) is present, since a bare .webp could
	 *                     be a genuine standalone upload.
	 *
	 * Touches the disk for the replaced-extension case, and for the appended case only when
	 * $require_source asks whether the source still exists.
	 *
	 * @param string $abs            Absolute file path (already normalized).
	 * @param bool   $require_source Appended copies count only while their source exists.
	 * @return bool
	 */
	public static function is_nextgen_derivative( $abs, $require_source = false ) {
		$abs  = (string) $abs;
		$base = (string) preg_replace( '/\.(webp|avif)$/i', '', $abs );
		if ( $base === $abs ) {
			return false; // Not a next-gen file.
		}
		// 1) Appended extension: original.jpg.webp / image-300x200.jpeg.avif → derivative
		// by naming convention. For the orphan scan ($require_source) it only counts while
		// its source is still on disk: the copies of a deleted image are exactly the
		// orphans that scan exists to find, and answering "derivative" regardless hid them.
		$base_ext = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
		if ( in_array( $base_ext, array( 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff' ), true ) ) {
			return $require_source ? is_file( $base ) : true;
		}
		// 2) Replaced extension: original.webp only when its source raster sits next to it.
		foreach ( array( 'jpg', 'jpeg', 'png', 'gif' ) as $oext ) {
			if ( is_file( $base . '.' . $oext ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a filename looks like a generated thumbnail (e.g. img-150x150.jpg). Pure.
	 *
	 * @param string $filename Basename.
	 * @return bool
	 */
	public static function is_thumbnail( $filename ) {
		return 1 === preg_match( '/-\d+x\d+\.[A-Za-z0-9]+$/', (string) $filename );
	}

	/**
	 * The presumed original filename for a thumbnail (strip the -WxH suffix). Pure.
	 *
	 * @param string $filename Basename.
	 * @return string
	 */
	public static function original_from_thumbnail( $filename ) {
		return (string) preg_replace( '/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', (string) $filename );
	}

	/**
	 * Whether a basename is a rename-plugin backup leftover — a redundant copy of the
	 * pre-rename original that plugins like Media File Renamer keep on disk (and often
	 * register as the attachment's original_image). The marker is unambiguous, so a
	 * real WordPress original (X.jpg for X-scaled.jpg) is never matched. Pure + filterable.
	 *
	 * @param string $name Basename (any case).
	 * @return bool
	 */
	public static function is_rename_leftover( $name ) {
		$name = strtolower( trim( (string) $name ) );
		if ( '' === $name ) {
			return false;
		}
		/**
		 * Filters the regex markers that identify a rename-plugin backup leftover.
		 *
		 * @param string[] $markers Regex fragments matched against the basename (before the extension).
		 */
		$markers = (array) apply_filters( 'wpoptimizing_rename_leftover_markers', array( '-mfrh-original' ) );
		foreach ( $markers as $marker ) {
			$marker = (string) $marker;
			if ( '' !== $marker && preg_match( '/' . preg_quote( $marker, '/' ) . '\.[a-z0-9]+$/i', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a relative upload path (separators + leading slash). Pure.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize_rel( $path ) {
		$path = str_replace( '\\', '/', (string) $path );
		return ltrim( $path, '/' );
	}

	/**
	 * Whether a path lives in a named top-level folder (not a YYYY date folder),
	 * i.e. likely plugin-managed data rather than media-library uploads. Pure.
	 *
	 * @param string $rel Path relative to uploads.
	 * @return bool
	 */
	public static function is_plugin_like_path( $rel ) {
		$rel = self::normalize_rel( $rel );
		if ( '' === $rel || false === strpos( $rel, '/' ) ) {
			return false; // Root-level file: treated as a normal orphan candidate.
		}
		$first = (string) strtok( $rel, '/' );
		return 1 !== preg_match( '/^\d{4}$/', $first );
	}

	/**
	 * Build the set of upload-relative paths that the DB knows about. Pure.
	 *
	 * @param string[] $attached_files Values of _wp_attached_file (e.g. "2024/01/a.jpg").
	 * @param array[]  $metadata_blobs Unserialized _wp_attachment_metadata arrays.
	 * @return array<string,true> Set keyed by normalized relative path.
	 */
	public static function build_registered_set( array $attached_files, array $metadata_blobs ) {
		$set = array();

		foreach ( $attached_files as $file ) {
			$file = self::normalize_rel( $file );
			if ( '' !== $file ) {
				$set[ $file ] = true;
			}
		}

		foreach ( $metadata_blobs as $meta ) {
			if ( ! is_array( $meta ) ) {
				continue;
			}
			$main = isset( $meta['file'] ) ? self::normalize_rel( $meta['file'] ) : '';
			if ( '' !== $main ) {
				$set[ $main ] = true;
			}
			$dir = ( '' !== $main && false !== strpos( $main, '/' ) ) ? substr( $main, 0, strrpos( $main, '/' ) ) : '';

			// The un-scaled original and the companion files the client-side media flow
			// sideloads (see scanners/attachment-companions.php). The attachment metadata is
			// the only record that any of them is in use.
			foreach ( wpoptimizing_attachment_companion_files( $meta ) as $companion ) {
				$rel         = self::normalize_rel( ( '' !== $dir ? $dir . '/' : '' ) . $companion );
				$set[ $rel ] = true;
			}

			// `backup_sizes` is _wp_attachment_backup_sizes paired in by the caller (see
			// metadata_blobs()): the pre-edit originals core keeps after a crop or rotate in the
			// image editor. Nothing else records them, so without this every edited image left
			// its own original listed as an orphan file.
			foreach ( array( 'sizes', 'backup_sizes' ) as $sizes_key ) {
				if ( empty( $meta[ $sizes_key ] ) || ! is_array( $meta[ $sizes_key ] ) ) {
					continue;
				}
				foreach ( $meta[ $sizes_key ] as $size ) {
					if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
						$rel         = self::normalize_rel( ( '' !== $dir ? $dir . '/' : '' ) . basename( $size['file'] ) );
						$set[ $rel ] = true;
					}
				}
			}
		}

		return $set;
	}

	/**
	 * The file names of one _wp_attachment_metadata value and nothing else. Pure.
	 *
	 * A full metadata array carries EXIF, every size's dimensions and mime type, and often a
	 * plugin's own additions — some 10 KB a row. Holding all of them at once to build the
	 * registered set is what ran a 13,000-image library out of a 128 MB memory limit. The set
	 * only needs the names.
	 *
	 * @since 0.1.0
	 * @param mixed $meta Unserialized metadata.
	 * @return array|null Same shape as the input, reduced to file names; null if unusable.
	 */
	public static function slim_metadata( $meta ) {
		if ( ! is_array( $meta ) ) {
			return null;
		}
		$slim = array();
		if ( isset( $meta['file'] ) && is_string( $meta['file'] ) ) {
			$slim['file'] = $meta['file'];
		}
		foreach ( wpoptimizing_attachment_companion_keys() as $key ) {
			$key = (string) $key;
			if ( '' !== $key && ! empty( $meta[ $key ] ) && is_string( $meta[ $key ] ) ) {
				$slim[ $key ] = $meta[ $key ];
			}
		}
		foreach ( array( 'sizes', 'backup_sizes' ) as $sizes_key ) {
			if ( empty( $meta[ $sizes_key ] ) || ! is_array( $meta[ $sizes_key ] ) ) {
				continue;
			}
			foreach ( $meta[ $sizes_key ] as $name => $size ) {
				if ( is_array( $size ) && ! empty( $size['file'] ) && is_string( $size['file'] ) ) {
					$slim[ $sizes_key ][ $name ] = array( 'file' => $size['file'] );
				}
			}
		}
		return $slim;
	}

	/**
	 * The key under which a file counts as referenced: its directory plus its trunk. Pure.
	 *
	 * A page shows "foto-1024x768.jpg", the optimiser serves "foto.jpg.webp", WordPress keeps
	 * "foto-scaled.jpg" as the attachment file — all one image. Comparing exact basenames
	 * reported the attachment as unused while a page was displaying one of its sizes. The
	 * directory stays in the key so a "logo.png" in one month does not vouch for a different
	 * "logo.png" in another; a multisite "sites/N/" prefix is dropped because a sub-site's
	 * _wp_attached_file is relative to its own uploads folder while its URLs are not.
	 *
	 * @since 0.1.0
	 * @param string $rel Path relative to the uploads folder (URL-encoded is fine).
	 * @return string Lowercase "dir/trunk", or '' when there is no file name.
	 */
	public static function usage_key( $rel ) {
		$rel   = self::normalize_rel( rawurldecode( (string) $rel ) );
		$rel   = (string) preg_replace( '#^sites/\d+/#', '', $rel );
		$trunk = self::trunk( $rel );
		if ( '' === $trunk ) {
			return '';
		}
		$pos = strrpos( $rel, '/' );
		$dir = ( false === $pos ) ? '' : substr( $rel, 0, $pos );

		return strtolower( ( '' !== $dir ? $dir . '/' : '' ) . $trunk );
	}

	/**
	 * Run the analysis against the uploads directory.
	 *
	 * @param array $args {
	 *     @type int $max_files     Max files to scan (default 2000000).
	 *     @type int $sample        Sample entries to include per category (default 100).
	 *     @type int $hash_max      Max files to hash for duplicates (default 10000).
	 *     @type int $hash_max_size Per-file size cap for hashing in bytes (default 25MB).
	 * }
	 * @return array<string,mixed>
	 */
	public function analyze( array $args = array() ) {
		// The analysis is a resumable job (see WPOPT_Media_Analysis_Job): here it runs to the end
		// in this request, in a working folder of its own so it never touches the unified
		// scan's job. Memory is bounded by a batch and a folder, not by the library.
		$dir = trailingslashit( WPOPT_DIR ) . 'media-job-' . str_replace( '.', '', uniqid( '', true ) );
		return ( new WPOPT_Media_Analysis_Job( $args, $dir ) )->run_to_end();
	}

	/**
	 * Pure: attachments not present in either the used-ID set or the
	 * used-file set. Conservative — anything possibly referenced is excluded.
	 *
	 * Files are compared by usage_key() on both sides, so a reference to any size, the
	 * -scaled copy, the un-scaled original or a .webp/.avif copy counts for the attachment.
	 *
	 * @param array<int,array{id:int,file:string}> $attachments Registered attachments.
	 * @param array<int,true>                      $used_ids    Set of referenced attachment IDs.
	 * @param array<string,true>                   $used_keys   Set of usage_key() values seen referenced.
	 * @return array<int,array{id:int,file:string}>
	 */
	public static function find_unused_attachments( array $attachments, array $used_ids, array $used_keys ) {
		$out = array();
		foreach ( $attachments as $att ) {
			$id   = (int) ( $att['id'] ?? 0 );
			$file = (string) ( $att['file'] ?? '' );
			if ( $id <= 0 || '' === $file ) {
				continue;
			}
			if ( isset( $used_ids[ $id ] ) ) {
				continue;
			}
			$key = self::usage_key( $file );
			if ( '' !== $key && isset( $used_keys[ $key ] ) ) {
				continue;
			}
			$out[] = array(
				'id'   => $id,
				'file' => $file,
			);
		}
		return $out;
	}

	/**
	 * Extract attachment IDs referenced in content by GALLERY / SLIDER / BLOCK markup — the
	 * classic [gallery ids="1,2,3"] shortcode, other shortcodes' ids="…"/id="…"/data-ids="…"
	 * attributes, and Gutenberg block JSON ("ids":[…] / "id":N). These point at attachments by
	 * ID with no wp-image- class or upload URL, so a plain content scan misses them and would
	 * wrongly flag gallery images "unused". Values must start with a digit, so a textual id like
	 * id="header" is ignored. Pure and offline-testable; the caller intersects with real IDs.
	 *
	 * @param string $content Post content.
	 * @return int[] Unique candidate attachment IDs.
	 */
	/**
	 * Base64 payloads embedded in content, decoded so their references can be read.
	 *
	 * Divi stores parts of its layout as base64 inside shortcode attributes, and several
	 * builders do the same for anything with quotes in it. To a reference scan that is an
	 * opaque blob: the images it names are invisible, so they are reported as unused while a
	 * page is displaying them.
	 *
	 * DELIBERATELY NARROW. Decoding every base64-looking run of a large post is a good way to
	 * turn a scan into a CPU sink, and most of them are not references. So: a minimum length,
	 * a cap on how many are decoded, a cap on the decoded size, and the result is kept only
	 * when it actually looks like it contains a reference. One level deep — a payload inside a
	 * payload is somebody else's problem.
	 *
	 * Pure.
	 *
	 * @since 0.1.0
	 * @param string $content Raw content.
	 * @param int    $limit   Maximum payloads to decode.
	 * @return string[] Decoded payloads that look like they carry references.
	 */
	public static function decode_embedded_payloads( $content, $limit = 20 ) {
		$content = (string) $content;
		$out     = array();
		if ( '' === $content || ( false === strpos( $content, '=' ) && ! preg_match( '/[A-Za-z0-9+\/]{60,}/', $content ) ) ) {
			return $out;
		}

		if ( ! preg_match_all( '#[A-Za-z0-9+/]{60,}={0,2}#', $content, $m ) ) {
			return $out;
		}

		foreach ( $m[0] as $candidate ) {
			if ( count( $out ) >= max( 1, (int) $limit ) ) {
				break;
			}
			if ( strlen( $candidate ) > 200000 ) {
				continue; // Absurd for an embedded payload; not worth the memory.
			}
			$decoded = base64_decode( $candidate, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a builder's own payload to find image references, not executing anything.
			if ( false === $decoded || '' === $decoded ) {
				continue;
			}
			// Only keep it if it plausibly carries a reference. Random binary that happens to
			// decode is noise, and feeding noise to the id extractor invents references.
			if ( false === strpos( $decoded, '/uploads/' )
				&& ! preg_match( '/"(?:ids?|image|attachment_id|media_id|thumbnail_id)"/i', $decoded )
				&& false === strpos( $decoded, 'wp-image-' ) ) {
				continue;
			}
			$out[] = $decoded;
		}

		return $out;
	}

	public static function extract_content_id_refs( $content ) {
		$content = (string) $content;
		$ids     = array();
		// Shortcode / data attributes: ids="1,2,3", id="9", data-ids="4 5". Plus the
		// *image_id attributes Avada writes, which carry the size after a pipe —
		// image_id="77|medium", background_image_id="12|full" — and so never matched a
		// pattern that wanted the closing quote right after the digits.
		if ( preg_match_all( '/\b(?:ids?|[a-z_]*image_ids?)\s*=\s*["\']([0-9][0-9,\s]*)(?:\|[^"\']*)?["\']/i', $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( preg_split( '/[,\s]+/', $list ) as $p ) {
					$n = (int) $p;
					if ( $n > 0 ) {
						$ids[ $n ] = true;
					}
				}
			}
		}
		// Gutenberg block JSON: "ids":[1,2,3] or "id":123.
		if ( preg_match_all( '/"ids?"\s*:\s*(\[[0-9,\s]*\]|[0-9]+)/', $content, $m2 ) ) {
			foreach ( $m2[1] as $chunk ) {
				if ( preg_match_all( '/\d{1,10}/', $chunk, $cm ) ) {
					foreach ( $cm[0] as $n ) {
						$n = (int) $n;
						if ( $n > 0 ) {
							$ids[ $n ] = true;
						}
					}
				}
			}
		}

		// Builder keys that hold an attachment id under a name that is not "id". SiteOrigin's
		// panels_data uses "image", Soliloquy's slider data uses "attachment_id", and several
		// themes use "bg_image" or "media_id" for a background. The generic "id" pattern above
		// misses every one of them, so those images were being reported as unused while a page
		// was displaying them.
		if ( preg_match_all( '/"(?:image|image_id|images|attachment_id|attachment_ids|media_id|thumbnail_id|bg_image|background_image|poster_id|logo_id)"\s*:\s*(\[[0-9,\s"]*\]|"?[0-9]{1,10}"?)/i', $content, $m3 ) ) {
			foreach ( $m3[1] as $chunk ) {
				if ( preg_match_all( '/\d{1,10}/', $chunk, $cm ) ) {
					foreach ( $cm[0] as $n ) {
						$n = (int) $n;
						if ( $n > 0 ) {
							$ids[ $n ] = true;
						}
					}
				}
			}
		}

		// Serialized (not JSON) builder payloads: s:5:"image";i:123; and friends.
		if ( preg_match_all( '/"(?:image|attachment_id|media_id|thumbnail_id|bg_image)"\s*;\s*[is]:(?:\d+:")?(\d{1,10})/i', $content, $m4 ) ) {
			foreach ( $m4[1] as $n ) {
				$n = (int) $n;
				if ( $n > 0 ) {
					$ids[ $n ] = true;
				}
			}
		}

		return array_keys( $ids );
	}

	/**
	 * Curated map of plugin FOLDER => display name for extensions that commonly reference media
	 * DYNAMICALLY (sliders, galleries, builders, WooCommerce galleries) in ways a static scan
	 * cannot see. Used to warn the user, by name, on the unused-attachments list. Filterable.
	 *
	 * @return array<string,string>
	 */
	public static function dynamic_source_map() {
		$map = array(
			'revslider'                   => 'Slider Revolution',
			'smart-slider-3'              => 'Smart Slider 3',
			'ml-slider'                   => 'MetaSlider',
			'layerslider'                 => 'LayerSlider',
			'soliloquy'                   => 'Soliloquy',
			'soliloquy-lite'              => 'Soliloquy',
			'nextgen-gallery'             => 'NextGEN Gallery',
			'envira-gallery'              => 'Envira Gallery',
			'envira-gallery-lite'         => 'Envira Gallery',
			'foogallery'                  => 'FooGallery',
			'modula-best-grid-gallery'    => 'Modula',
			'photo-gallery'               => 'Photo Gallery',
			'elementor'                   => 'Elementor',
			'js_composer'                 => 'WPBakery Page Builder',
			'siteorigin-panels'           => 'SiteOrigin Page Builder',
			'beaver-builder-lite-version' => 'Beaver Builder',
			'bb-plugin'                   => 'Beaver Builder',
			'woocommerce'                 => 'WooCommerce',
		);
		return (array) apply_filters( 'wpoptimizing_media_dynamic_sources', $map );
	}

	/**
	 * Which curated dynamic-media sources are ACTIVE, matched by plugin folder. PURE (offline
	 * testable): pass the active-plugin basenames and the map; returns the distinct display
	 * names of the active ones.
	 *
	 * @param string[]              $active_plugins Active plugin basenames ("dir/file.php").
	 * @param array<string,string>  $map            Folder => label map.
	 * @return string[] Distinct display names.
	 */
	public static function match_dynamic_sources( array $active_plugins, array $map ) {
		$dirs = array();
		foreach ( $active_plugins as $p ) {
			$d = strtolower( (string) strtok( (string) $p, '/' ) );
			if ( '' !== $d ) {
				$dirs[ $d ] = true;
			}
		}
		$out = array();
		foreach ( $map as $dir => $label ) {
			if ( isset( $dirs[ strtolower( (string) $dir ) ] ) ) {
				$out[ (string) $label ] = true;
			}
		}
		return array_keys( $out );
	}

	/**
	 * How long the reference sweep may take before it gives up and admits it is partial.
	 *
	 * @since 0.1.0
	 * @return float Seconds.
	 */
	protected function usage_time_budget() {
		/**
		 * Filters the time budget for the unused-media reference sweep.
		 *
		 * @since 0.1.0
		 * @param float $seconds Seconds.
		 */
		return (float) apply_filters( 'wpoptimizing_media_usage_budget', 25.0 );
	}

	/**
	 * Walk a whole column, in primary-key order, handing each value to a callback.
	 *
	 * WHY THIS REPLACED `LIMIT 50000`. The sweep used to read the first N rows of each table
	 * and set a `partial` flag. That is not a sample — a reference past the cap is not weighed
	 * and discarded, it is never seen, so an image that IS in use gets listed as unused next to
	 * a delete button. And on a WooCommerce store the very first of those queries — every
	 * distinct numeric meta_value in postmeta — passes 50,000 routinely, which means it was
	 * the ordinary case on exactly the sites with the most media to lose.
	 *
	 * A cap also cannot be paged away: `LIMIT n` with no ORDER BY has no defined order, so
	 * "the next 50,000" is not a thing you can ask for. Hence the primary key as a cursor —
	 * deterministic, and it reads along an index instead of sorting a DISTINCT over the whole
	 * table, so the complete sweep is usually FASTER than the truncated one it replaces.
	 *
	 * Still bounded, by time rather than by rows: a site can always be big enough. But the
	 * bound is now the exception instead of the rule, and when it is hit the caller still
	 * hears about it.
	 *
	 * @since 0.1.0
	 * LIKE PATTERNS GO IN $where_args, NEVER IN $where. A literal % inside the interpolated
	 * condition is read by wpdb::prepare() as a placeholder, so `LIKE 'a:%'` makes it count
	 * three placeholders where two arguments were passed and complain — found by running this
	 * against a real database, which no unit test would have done.
	 *
	 * @param string   $table      Table name (already a trusted $wpdb property).
	 * @param string   $pk         Primary-key column.
	 * @param string   $column     Column to read.
	 * @param string   $where      SQL condition with %s/%d placeholders for its values.
	 * @param array    $where_args Values for those placeholders, in order.
	 * @param callable $consume    Receives each value.
	 * @param float    $deadline   microtime(true) after which to stop.
	 * @param int      $chunk      Rows per round trip.
	 * @param int|null $cursor     Where to start (exclusive), and where it stopped on return —
	 *                             so a caller can resume the walk in another request.
	 * @return bool True when the whole column was read.
	 */
	protected function walk_column( $table, $pk, $column, $where, array $where_args, callable $consume, $deadline, $chunk = 5000, &$cursor = null ) {
		global $wpdb;
		$cursor = (int) $cursor;
		// Start from whatever this server settled on last time rather than a number somebody
		// picked once: 5,000 rows is instant on a laptop and the reason a request dies halfway
		// on shared hosting with a cold disk. See wpoptimizing_tune_batch().
		$chunk = function_exists( 'wpoptimizing_batch_size' )
			? max( 500, wpoptimizing_batch_size( 'media_usage', (int) $chunk ) )
			: max( 500, (int) $chunk );

		while ( true ) {
			if ( microtime( true ) >= $deadline ) {
				if ( function_exists( 'wpoptimizing_batch_size_learn' ) ) {
					wpoptimizing_batch_size_learn( 'media_usage', $chunk );
				}
				return false;
			}
			$batch_started = microtime( true );
			// $table, $pk and $column are $wpdb properties and literals chosen in this file —
			// never request data. Every VALUE goes through prepare(), which core accepts as a
			// single array ($passed_as_array in wpdb::prepare); the placeholder sniff counts
			// that array as one replacement and miscounts.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT {$pk} AS wpopt_k, {$column} AS wpopt_v FROM {$table} WHERE {$where} AND {$pk} > %d ORDER BY {$pk} ASC LIMIT %d",
					array_merge( array_values( $where_args ), array( $cursor, $chunk ) )
				),
				ARRAY_A
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
			// A FAILED QUERY IS NOT THE END OF THE DATA. wpdb answers an error with an empty
			// array, and the scan arms a statement timeout, so a killed query looked exactly
			// like a finished column: the sweep reported itself complete and media it never
			// got to read was offered for deletion. wpdb clears last_error before each query.
			if ( '' !== (string) $wpdb->last_error ) {
				return false;
			}
			if ( ! is_array( $rows ) || empty( $rows ) ) {
				return true; // Reached the end.
			}
			foreach ( $rows as $row ) {
				$cursor = (int) $row['wpopt_k'];
				$consume( (string) $row['wpopt_v'] );
			}

			$short = count( $rows ) < $chunk;

			// Time the whole round trip, query and processing together, because that is what
			// actually has to fit in the budget.
			if ( function_exists( 'wpoptimizing_tune_batch' ) ) {
				$chunk = wpoptimizing_tune_batch( $chunk, microtime( true ) - $batch_started, 0.5, 500, 20000 );
			}

			if ( $short ) {
				if ( function_exists( 'wpoptimizing_batch_size_learn' ) ) {
					wpoptimizing_batch_size_learn( 'media_usage', $chunk );
				}
				return true; // Short page: nothing left behind it.
			}
		}
	}

	/**
	 * The usage_key() of every upload URL in a blob, as keys. Unescapes JSON first (`\/` →
	 * `/`), so a URL saved by the Site Editor or a page builder counts the same as one in plain
	 * HTML, and decodes percent-encoding, so "a%C3%B1o.jpg" counts for "año.jpg".
	 *
	 * @param string $blob Any stored value.
	 * @return array<string,true>
	 */
	public static function upload_usage_keys_in( $blob ) {
		$blob = (string) $blob;
		if ( false !== strpos( $blob, '\\/' ) ) {
			$blob = str_replace( '\\/', '/', $blob );
		}
		// A whole URL stored percent-encoded (some builders keep links that way).
		if ( false !== stripos( $blob, '%2Fuploads%2F' ) ) {
			$blob = rawurldecode( $blob );
		}
		$out = array();
		// Commas, pipes and brackets end a path: sanitize_file_name() never lets them into a
		// file name, and without them "a.jpg,/uploads/b.jpg" read as one long path.
		if ( false !== strpos( $blob, '/uploads/' ) && preg_match_all( '#/uploads/([^\s"\'\\\\<>()\[\],;|]+\.[a-z0-9]{2,5})#i', $blob, $m ) ) {
			foreach ( $m[1] as $u ) {
				$key = self::usage_key( (string) preg_replace( '/[?#].*$/', '', (string) $u ) );
				if ( '' !== $key ) {
					$out[ $key ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Every pass of the reference sweep, in the order it runs.
	 *
	 * ONE LIST, TWO WAYS OF WALKING IT. collect_content_usage() runs it in one request under a
	 * time budget; the media job (WPOPT_Media_Analysis_Job) runs it pass by pass and chunk by
	 * chunk across requests with usage_step(), saving the pass and the cursor, so on a big
	 * database it finishes instead of stopping at the budget and calling half the library
	 * "unused" (422,571 attachments on 22/10/2026: 205,407 reported unused, partial).
	 *
	 * Each pass is a walk_column() call: a table, its primary key, the column read, the
	 * condition and its values, and the KIND of value, which says how usage_consume() reads it.
	 * Every pass only ever ADDS to "used", so the worst a false hit can do is keep a file
	 * nobody wanted — the only acceptable direction for this whole sweep.
	 *
	 * LIKE PATTERNS GO IN 'args', NEVER IN 'where' (see walk_column()).
	 *
	 * @since 0.1.0
	 * @return array<int,array{table:string,pk:string,column:string,where:string,args:array,kind:string}>
	 */
	protected function usage_passes() {
		global $wpdb;
		// JSON stores slashes escaped: the Site Editor's global styles and template parts keep a
		// background image as `wp-content\/uploads\/2026\/…`, which the plain uploads pattern
		// never matches. Every image used only there read as unused. This catches that spelling.
		$escaped_uploads = '%' . $wpdb->esc_like( 'uploads\/' ) . '%';
		$pass            = static function ( $table, $pk, $column, $where, array $args, $kind ) {
			return array(
				'table'  => $table,
				'pk'     => $pk,
				'column' => $column,
				'where'  => $where,
				'args'   => $args,
				'kind'   => $kind,
			);
		};

		$passes = array(
			// 1) Featured images. Paged like the rest: on a big site the old one-shot DISTINCT
			// over every _thumbnail_id was itself one of the slow queries.
			// A theme that migrated from another system can keep a PATH here instead of an ID
			// ("/wp-content/uploads/portada/x.jpg", seen on a news site), so both are read.
			$pass( $wpdb->postmeta, 'meta_id', 'meta_value', 'meta_key = %s', array( '_thumbnail_id' ), 'id_or_url' ),
			// 1b) Attachments attached to a live post (post_parent), e.g. product images
			// uploaded to a product. A strong, cheap "associated" signal.
			$pass( "{$wpdb->posts} a", 'a.ID', 'a.ID', "a.post_type = 'attachment' AND a.post_parent > 0 AND EXISTS ( SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = a.post_parent AND p.post_status NOT IN ('trash','auto-draft') )", array(), 'id' ),
			// 2) Single numeric postmeta values matching a known attachment ID.
			$pass( $wpdb->postmeta, 'meta_id', 'meta_value', "meta_value REGEXP '^[0-9]{1,10}$'", array(), 'id' ),
			// 2b) Comma-separated ID lists (WooCommerce _product_image_gallery, etc.).
			$pass( $wpdb->postmeta, 'meta_id', 'meta_value', "meta_value REGEXP '^[0-9]+(,[0-9]+)+$'", array(), 'id_list' ),
			// 2c) Serialized arrays / JSON blobs that embed attachment IDs (ACF galleries,
			// page builders, gallery shortcodes saved to meta), and the /uploads/ files in them.
			$pass( $wpdb->postmeta, 'meta_id', 'meta_value', '( meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s ) AND meta_value REGEXP \'[0-9]\'', array( 'a:%', '{%', '[%' ), 'blob' ),
		);

		// 2d) The OTHER meta tables. postmeta is where most references live, and for a long
		// time it was the only one swept — which quietly made every WooCommerce product
		// CATEGORY image read as unused, because a category thumbnail is a termmeta row
		// (`thumbnail_id`) holding an attachment ID and nothing else points at it. Avatar
		// plugins do the same in usermeta, and a few comment-attachment plugins in commentmeta.
		// Each table pages on ITS OWN primary key: usermeta's is not meta_id.
		$meta_tables = array(
			$wpdb->termmeta    => 'meta_id',
			$wpdb->usermeta    => 'umeta_id',
			$wpdb->commentmeta => 'meta_id',
		);
		foreach ( $meta_tables as $meta_table => $meta_pk ) {
			if ( '' === (string) $meta_table ) {
				continue;
			}
			$passes[] = $pass( $meta_table, $meta_pk, 'meta_value', "meta_value REGEXP '^[0-9]{1,10}$'", array(), 'id' );
			$passes[] = $pass( $meta_table, $meta_pk, 'meta_value', '( meta_value LIKE %s OR meta_value LIKE %s )', array( '%/uploads/%', $escaped_uploads ), 'urls' );
		}

		// 3) References inside post content (bounded to posts that mention uploads OR carry a
		// gallery/slider shortcode that references attachments by ID — the classic
		// [gallery ids="1,2,3"] is stored as literal shortcode text with no wp-image- class or
		// URL, so without this it would wrongly flag every gallery image "unused").
		$passes[] = $pass( $wpdb->posts, 'ID', 'post_content', "post_status NOT IN ('trash','auto-draft') AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )", array( '%wp-image-%', '%/uploads/%', '%[gallery%', '%ids=%', $escaped_uploads ), 'content' );

		// 4) References inside the OPTIONS table: widgets (widget_*), the Customizer and theme
		// mods (theme_mods_* — including the site logo), page-builder global settings and any
		// option that embeds an upload URL; and the IDs inside serialized options.
		$passes[] = $pass( $wpdb->options, 'option_id', 'option_value', '( option_value LIKE %s OR option_value LIKE %s )', array( '%/uploads/%', $escaped_uploads ), 'urls' );
		$passes[] = $pass( $wpdb->options, 'option_id', 'option_value', 'option_value LIKE %s AND option_value REGEXP \'[0-9]\'', array( 'a:%' ), 'ids_in' );

		// 5) Postmeta holding a plain upload URL and nothing else — Toolset, JetEngine, CMB2
		// and many theme options save an image field that way. Pass 2c only reads serialized
		// and JSON values, so these files read as unused while a page showed them.
		$passes[] = $pass( $wpdb->postmeta, 'meta_id', 'meta_value', '( meta_value LIKE %s OR meta_value LIKE %s ) AND meta_value NOT LIKE %s AND meta_value NOT LIKE %s AND meta_value NOT LIKE %s', array_merge( array( '%/uploads/%', $escaped_uploads ), self::BLOB_LIKES ), 'urls' );

		return $passes;
	}

	/**
	 * Read one value of a sweep pass into the used sets. Pure over its arguments.
	 *
	 * @since 0.1.0
	 * @param string             $kind    Kind of the pass (see usage_passes()).
	 * @param string             $value   The value read.
	 * @param array<int,true>    $att_set Known attachment IDs.
	 * @param array<int,true>    $ids     Used attachment IDs (added to).
	 * @param array<string,true> $keys    Used usage_key() values (added to).
	 * @return void
	 */
	public static function usage_consume( $kind, $value, array $att_set, array &$ids, array &$keys ) {
		$value = (string) $value;
		$add   = static function ( $n ) use ( &$ids, $att_set ) {
			$n = (int) $n;
			if ( $n > 0 && isset( $att_set[ $n ] ) ) {
				$ids[ $n ] = true;
			}
		};
		switch ( $kind ) {
			case 'id':
				$add( $value );
				break;
			case 'id_or_url':
				if ( ctype_digit( trim( $value ) ) ) {
					$add( $value );
				} else {
					$keys += self::upload_usage_keys_in( $value );
				}
				break;
			case 'id_list':
				foreach ( explode( ',', $value ) as $part ) {
					$add( trim( $part ) );
				}
				break;
			case 'blob':
				$keys += self::upload_usage_keys_in( $value );
				// Fall through to the IDs: a blob names files AND attachments.
			case 'ids_in':
				if ( preg_match_all( '/\d{1,10}/', $value, $m ) ) {
					foreach ( $m[0] as $n ) {
						$add( $n );
					}
				}
				break;
			case 'urls':
				$keys += self::upload_usage_keys_in( $value );
				break;
			case 'content':
				if ( preg_match_all( '/wp-image-(\d+)/', $value, $m ) ) {
					foreach ( $m[1] as $id ) {
						$ids[ (int) $id ] = true;
					}
				}
				$keys += self::upload_usage_keys_in( $value );
				// Gallery / slider / block ID references (see extract_content_id_refs).
				foreach ( self::extract_content_id_refs( $value ) as $id ) {
					$add( $id );
				}
				// And the same again inside base64 payloads, which some builders use for
				// anything containing quotes. To a scan that does not decode them they are an
				// opaque blob, and every image they name looks unused.
				foreach ( self::decode_embedded_payloads( $value ) as $payload ) {
					foreach ( self::extract_content_id_refs( $payload ) as $id ) {
						$add( $id );
					}
					$keys += self::upload_usage_keys_in( $payload );
				}
				break;
		}
	}

	/**
	 * Options whose whole value is one attachment ID, as used IDs. The last, cheap step of the
	 * sweep (theme_mods' custom_logo and header image live inside the serialized theme_mods_*
	 * option, which the options pass reads).
	 *
	 * @since 0.1.0
	 * @return array<int,true>
	 */
	protected function usage_from_id_options() {
		$ids = array();
		foreach ( self::id_options() as $id_option ) {
			$v = get_option( $id_option, 0 );
			if ( is_numeric( $v ) && (int) $v > 0 ) {
				$ids[ (int) $v ] = true;
			}
		}
		return $ids;
	}

	/**
	 * Advance the sweep until the deadline, from where $state says it is.
	 *
	 * $state is {pass, cursor, errors, partial}, saved by the caller between requests. A
	 * pass whose query fails is retried from the same row; after three failures in a row it
	 * is skipped and the sweep is partial — a failed query is not the end of the data (see
	 * walk_column()).
	 *
	 * @since 0.1.0
	 * @param array           $state    Sweep state (changed in place).
	 * @param array<int,true> $att_set  Known attachment IDs.
	 * @param float           $deadline microtime(true) to stop at.
	 * @param array           $ids      Used IDs found in this call (added to).
	 * @param array           $keys     Used keys found in this call (added to).
	 * @return bool True when every pass is done.
	 */
	public function usage_step( array &$state, array $att_set, $deadline, array &$ids, array &$keys ) {
		global $wpdb;
		$passes = $this->usage_passes();
		// At least one chunk per call, whatever is left of the budget: a step that only looked
		// at the clock would let a slow host call it forever without moving.
		$deadline = max( (float) $deadline, microtime( true ) + 0.05 );
		$state   += array(
			'pass'    => 0,
			'cursor'  => 0,
			'errors'  => 0,
			'partial' => false,
		);
		$n_passes = count( $passes );
		while ( $state['pass'] < $n_passes ) {
			$p               = $passes[ (int) $state['pass'] ];
			$cursor          = (int) $state['cursor'];
			$kind            = $p['kind'];
			$done            = $this->walk_column(
				$p['table'],
				$p['pk'],
				$p['column'],
				$p['where'],
				$p['args'],
				static function ( $v ) use ( $kind, $att_set, &$ids, &$keys ) {
					self::usage_consume( $kind, $v, $att_set, $ids, $keys );
				},
				$deadline,
				5000,
				$cursor
			);
			$state['cursor'] = $cursor;
			if ( $done ) {
				$state['pass']   = (int) $state['pass'] + 1;
				$state['cursor'] = 0;
				$state['errors'] = 0;
				continue;
			}
			if ( '' !== (string) $wpdb->last_error ) {
				$state['errors'] = (int) $state['errors'] + 1;
				if ( $state['errors'] >= 3 ) {
					$state['partial'] = true;
					$state['pass']    = (int) $state['pass'] + 1;
					$state['cursor']  = 0;
					$state['errors']  = 0;
					continue;
				}
			}
			return false; // Out of time (or a failure to retry): the next call goes on from here.
		}
		$ids += $this->usage_from_id_options();
		return true;
	}

	/**
	 * How many passes the sweep has, for progress.
	 *
	 * @since 0.1.0
	 * @return int
	 */
	public function usage_pass_count() {
		return count( $this->usage_passes() );
	}

	/**
	 * Collect "used" signals cheaply and conservatively, in one request: featured images,
	 * numeric postmeta values that match an attachment ID (ACF/galleries/page builders),
	 * `wp-image-{ID}` + `/uploads/...` references and gallery/slider/block ID refs inside
	 * post content, the other meta tables and the options. The passes are usage_passes().
	 *
	 * COMPLETE, NOT SAMPLED: each column is walked in primary-key order until it runs out —
	 * see walk_column(). `partial` means the time budget ran out or a query failed; the media
	 * job does not use this, it walks the same passes across requests with usage_step().
	 *
	 * @param int[] $attachment_ids Known attachment IDs (to validate numeric meta).
	 * @param int   $cap            Unused. Kept so existing callers do not break.
	 * @return array{ids:array<int,true>,keys:array<string,true>,partial:bool}
	 */
	protected function collect_content_usage( array $attachment_ids, $cap = 50000 ) {
		unset( $cap );
		$deadline  = microtime( true ) + $this->usage_time_budget();
		$att_set   = array_flip( array_map( 'intval', $attachment_ids ) );
		$used_ids  = array();
		$used_keys = array();
		$partial   = false;

		foreach ( $this->usage_passes() as $p ) {
			$kind     = $p['kind'];
			$complete = $this->walk_column(
				$p['table'],
				$p['pk'],
				$p['column'],
				$p['where'],
				$p['args'],
				static function ( $v ) use ( $kind, $att_set, &$used_ids, &$used_keys ) {
					self::usage_consume( $kind, $v, $att_set, $used_ids, $used_keys );
				},
				$deadline
			);
			$partial  = $partial || ! $complete;
		}
		$used_ids += $this->usage_from_id_options();

		return array(
			'ids'     => $used_ids,
			'keys'    => $used_keys,
			'partial' => $partial,
		);
	}

	/**
	 * Options whose whole value is one attachment ID.
	 *
	 * @since 0.1.0
	 * @return string[]
	 */
	public static function id_options() {
		/**
		 * Filters the options that hold a bare attachment ID, so their image counts as used.
		 *
		 * @since 0.1.0
		 * @param string[] $options Option names.
		 */
		return (array) apply_filters( 'wpoptimizing_media_id_options', array( 'site_icon', 'site_logo', 'woocommerce_placeholder_image' ) );
	}

	/**
	 * Which of the given attachments something still points at, read NOW from the database.
	 *
	 * The delete buttons act on a report that can be days old. Between the analysis and the
	 * click an editor can have put the image on a page, and the report has no way to know.
	 * So the batch that is about to delete asks again — for its own IDs only, which lets the
	 * database filter (a REGEXP on the IDs) instead of handing every row to PHP — through the
	 * same signals the analysis uses: the ID in a numeric, list, serialized or JSON value of
	 * any meta table, in a serialized option (widgets, theme_mods) or a bare-ID option, in
	 * post content (wp-image-N, gallery and block ids, Avada image_id), and the file — any size
	 * or copy of it — named in content, postmeta or options.
	 *
	 * The attachments' OWN rows are left out: their metadata is full of numbers (widths,
	 * file sizes) and would vouch for itself.
	 *
	 * @since 0.1.0
	 * @param array<int,string> $atts        Attachment ID => _wp_attached_file.
	 * @param bool              $with_parent Count "attached to a live post" as use, as the analysis does.
	 * @return array<int,true>|null Referenced IDs, or null when the check could not finish
	 *                              (timeout, query error) — the caller must then delete nothing.
	 */
	public function referenced_now( array $atts, $with_parent = true ) {
		global $wpdb;
		$ids  = array();
		$keys = array();
		foreach ( $atts as $id => $file ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			$ids[ $id ] = true;
			$key        = self::usage_key( (string) $file );
			if ( '' !== $key ) {
				$keys[ $key ][] = $id;
			}
		}
		$hit = array();
		if ( empty( $ids ) ) {
			return $hit;
		}
		$deadline = microtime( true ) + $this->usage_time_budget();
		$id_list  = array_keys( $ids );
		$in       = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );
		// Digits and pipes only, so it is safe as a REGEXP value; prepare() quotes it.
		$num_re = '(^|[^0-9])(' . implode( '|', $id_list ) . ')([^0-9]|$)';
		$ok     = true;

		$by_value = static function ( $v ) use ( &$hit, $ids ) {
			$hit += self::ids_in_value( $v, $ids );
		};

		// The ID in any meta table, in the same value shapes the analysis reads.
		$shapes      = "( meta_value REGEXP '^[0-9]+(,[0-9]+)*$' OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s ) AND meta_value REGEXP %s";
		$ok          = $ok && $this->walk_column(
			$wpdb->postmeta,
			'meta_id',
			'meta_value',
			$shapes . " AND post_id NOT IN ({$in}) AND meta_key NOT IN ( %s, %s )",
			array_merge( self::BLOB_LIKES, array( $num_re ), $id_list, array( '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ) ),
			$by_value,
			$deadline
		);
		$meta_tables = array(
			$wpdb->termmeta    => 'meta_id',
			$wpdb->usermeta    => 'umeta_id',
			$wpdb->commentmeta => 'meta_id',
		);
		foreach ( $meta_tables as $meta_table => $meta_pk ) {
			if ( $ok && '' !== (string) $meta_table ) {
				$ok = $this->walk_column( $meta_table, $meta_pk, 'meta_value', $shapes, array_merge( self::BLOB_LIKES, array( $num_re ) ), $by_value, $deadline );
			}
		}
		$ok = $ok && $this->walk_column(
			$wpdb->options,
			'option_id',
			'option_value',
			'option_value LIKE %s AND option_value REGEXP %s',
			array( 'a:%', $num_re ),
			$by_value,
			$deadline
		);
		foreach ( self::id_options() as $id_option ) {
			$v = get_option( $id_option, 0 );
			if ( is_numeric( $v ) && isset( $ids[ (int) $v ] ) ) {
				$hit[ (int) $v ] = true;
			}
		}

		// The ID in post content. The REGEXP only narrows; what counts is decided by the same
		// extractors the analysis uses, so a price of "123" is not a reference to image 123.
		$ok = $ok && $this->walk_column(
			$wpdb->posts,
			'ID',
			'post_content',
			"post_status NOT IN ('trash','auto-draft') AND ( post_content LIKE %s OR post_content LIKE %s ) AND post_content REGEXP %s",
			array( '%wp-image-%', '%id%', $num_re ),
			static function ( $c ) use ( &$hit, $ids ) {
				$hit += self::ids_in_content( $c, $ids );
			},
			$deadline
		);

		// The file, under any of its names, in content, postmeta or options.
		if ( $ok && ! empty( $keys ) ) {
			$likes = array();
			foreach ( array_keys( $keys ) as $key ) {
				$likes[] = '%' . $wpdb->esc_like( 'uploads/' . $key ) . '%';
				$likes[] = '%' . $wpdb->esc_like( 'uploads\\/' . str_replace( '/', '\\/', $key ) ) . '%';
				$encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
				if ( $encoded !== $key ) {
					$likes[] = '%' . $wpdb->esc_like( 'uploads/' . $encoded ) . '%';
				}
			}
			$by_file   = static function ( $v ) use ( &$hit, $keys ) {
				foreach ( array_intersect_key( $keys, self::upload_usage_keys_in( $v ) ) as $owners ) {
					foreach ( $owners as $owner ) {
						$hit[ $owner ] = true;
					}
				}
			};
			$file_cols = array(
				array( $wpdb->posts, 'ID', 'post_content', "post_status NOT IN ('trash','auto-draft')", array() ),
				array( $wpdb->postmeta, 'meta_id', 'meta_value', "post_id NOT IN ({$in})", $id_list ),
				array( $wpdb->options, 'option_id', 'option_value', '1=1', array() ),
			);
			foreach ( $file_cols as $fc ) {
				if ( $ok ) {
					$cond = $fc[3] . ' AND ( ' . implode( ' OR ', array_fill( 0, count( $likes ), $fc[2] . ' LIKE %s' ) ) . ' )';
					$ok   = $this->walk_column( $fc[0], $fc[1], $fc[2], $cond, array_merge( $fc[4], $likes ), $by_file, $deadline );
				}
			}
		}

		if ( $ok && $with_parent ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$parented = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT a.ID FROM {$wpdb->posts} a INNER JOIN {$wpdb->posts} p ON p.ID = a.post_parent WHERE a.ID IN ({$in}) AND p.post_status NOT IN ('trash','auto-draft')",
					$id_list
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$ok = '' === (string) $wpdb->last_error;
			foreach ( (array) $parented as $pid ) {
				$hit[ (int) $pid ] = true;
			}
		}

		return $ok ? array_intersect_key( $hit, $ids ) : null;
	}

	/**
	 * Which of the given IDs appear as a whole number in a stored value. Pure.
	 *
	 * @since 0.1.0
	 * @param string          $value Stored value.
	 * @param array<int,true> $ids   Candidate IDs.
	 * @return array<int,true>
	 */
	public static function ids_in_value( $value, array $ids ) {
		$out = array();
		if ( preg_match_all( '/\d{1,10}/', (string) $value, $m ) ) {
			foreach ( $m[0] as $n ) {
				if ( isset( $ids[ (int) $n ] ) ) {
					$out[ (int) $n ] = true;
				}
			}
		}
		return $out;
	}

	/**
	 * Which of the given IDs a post's content references, by the analysis' own rules. Pure.
	 *
	 * @since 0.1.0
	 * @param string          $content Post content.
	 * @param array<int,true> $ids     Candidate IDs.
	 * @return array<int,true>
	 */
	public static function ids_in_content( $content, array $ids ) {
		$content = (string) $content;
		$found   = array();
		if ( preg_match_all( '/wp-image-(\d+)/', $content, $m ) ) {
			foreach ( $m[1] as $n ) {
				$found[] = (int) $n;
			}
		}
		$found = array_merge( $found, self::extract_content_id_refs( $content ) );
		foreach ( self::decode_embedded_payloads( $content ) as $payload ) {
			$found = array_merge( $found, self::extract_content_id_refs( $payload ) );
		}
		$out = array();
		foreach ( $found as $n ) {
			if ( isset( $ids[ (int) $n ] ) ) {
				$out[ (int) $n ] = true;
			}
		}
		return $out;
	}

	/**
	 * Remove cleaned duplicate sets from a cached report IN PLACE, decrementing
	 * the group/extra/reclaimable totals so the Media tab reflects the deletion
	 * without a full re-scan. Pure.
	 *
	 * @param array $report             Report array (with a 'duplicates' section).
	 * @param int[] $processed_keep_ids Kept attachment ids whose sets were cleaned.
	 * @return array Updated report.
	 */
	public static function prune_dupes_from_report( array $report, array $processed_keep_ids ) {
		if ( empty( $processed_keep_ids ) || ! isset( $report['duplicates']['sample'] ) ) {
			return $report;
		}
		$drop = array_flip( array_map( 'intval', $processed_keep_ids ) );
		$kept = array();
		foreach ( (array) $report['duplicates']['sample'] as $group ) {
			$gid = (int) ( $group['keep']['id'] ?? 0 );
			if ( isset( $drop[ $gid ] ) ) {
				$dupes                          = isset( $group['dupes'] ) ? (array) $group['dupes'] : array();
				$report['duplicates']['groups'] = max( 0, (int) ( $report['duplicates']['groups'] ?? 0 ) - 1 );
				$report['duplicates']['extra']  = max( 0, (int) ( $report['duplicates']['extra'] ?? 0 ) - count( $dupes ) );
				foreach ( $dupes as $d ) {
					$report['duplicates']['reclaimable_bytes'] = max( 0, (int) ( $report['duplicates']['reclaimable_bytes'] ?? 0 ) - (int) ( $d['size'] ?? 0 ) );
				}
				continue;
			}
			$kept[] = $group;
		}
		$report['duplicates']['sample'] = $kept;
		return $report;
	}

	/**
	 * Remove deleted orphan/thumb/other files from a cached report IN PLACE,
	 * decrementing the per-section count and size totals. Pure.
	 *
	 * @param array    $report Report array.
	 * @param string[] $files  Uploads-relative file paths that were deleted.
	 * @return array Updated report.
	 */
	public static function prune_files_from_report( array $report, array $files ) {
		if ( empty( $files ) ) {
			return $report;
		}
		$drop = array_flip( array_map( 'strval', $files ) );
		foreach ( array( 'orphan_files', 'orphan_thumbs', 'other_files' ) as $sec ) {
			if ( ! isset( $report[ $sec ]['sample'] ) ) {
				continue;
			}
			$kept = array();
			foreach ( (array) $report[ $sec ]['sample'] as $row ) {
				$f = (string) ( $row['file'] ?? '' );
				if ( '' !== $f && isset( $drop[ $f ] ) ) {
					$report[ $sec ]['count']      = max( 0, (int) ( $report[ $sec ]['count'] ?? 0 ) - 1 );
					$report[ $sec ]['size_bytes'] = max( 0, (int) ( $report[ $sec ]['size_bytes'] ?? 0 ) - (int) ( $row['size_bytes'] ?? 0 ) );
					continue;
				}
				$kept[] = $row;
			}
			$report[ $sec ]['sample'] = $kept;
		}
		return $report;
	}

	/**
	 * Group duplicate attachments by content hash. Pure.
	 *
	 * Each item must have `hash` and `id`. Returns groups (size >= 2) with the
	 * lowest id first (the suggested file to keep).
	 *
	 * Records that point at the SAME file are not duplicates of each other: identical bytes
	 * are a certainty when it is one file. That is what WPML Media's per-language copies look
	 * like, and merging them deletes the translations. Only the lowest ID per file stays in a
	 * group, and a group left with one file is no group.
	 *
	 * @param array<int,array<string,mixed>> $items Items with id/hash/file/size.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	public static function group_attachment_duplicates( array $items ) {
		$by_hash = array();
		foreach ( $items as $it ) {
			$hash = isset( $it['hash'] ) ? (string) $it['hash'] : '';
			if ( '' !== $hash ) {
				$by_hash[ $hash ][] = $it;
			}
		}
		$groups = array();
		foreach ( $by_hash as $group ) {
			if ( count( $group ) < 2 ) {
				continue;
			}
			usort(
				$group,
				static function ( $a, $b ) {
					return ( (int) ( $a['id'] ?? 0 ) ) <=> ( (int) ( $b['id'] ?? 0 ) );
				}
			);
			$seen_files = array();
			$distinct   = array();
			foreach ( $group as $it ) {
				$file = strtolower( (string) ( $it['file'] ?? '' ) );
				if ( '' !== $file && isset( $seen_files[ $file ] ) ) {
					continue;
				}
				$seen_files[ $file ] = true;
				$distinct[]          = $it;
			}
			if ( count( $distinct ) >= 2 ) {
				$groups[] = $distinct;
			}
		}
		return $groups;
	}
}

// The resumable job is the analyzer's own way of running; it always comes with it.
require_once __DIR__ . '/class-media-analysis-job.php';
