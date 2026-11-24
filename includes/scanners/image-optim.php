<?php
/**
 * Image optimization — analysis + engine.
 *
 * The analysis is read-only and free: it scans /uploads and reports image weight by
 * format, how many images are large or exceed the configured max dimensions, and how many
 * lack a WebP/AVIF copy, with a conservative savings estimate. The premium engine then
 * optimizes originals in place (backup-first, keep-smaller, reversible), generates WebP and
 * AVIF copies (never touching the originals) and resizes oversized images. Copies made by
 * other optimizers (appended photo.jpg.webp or replaced photo.webp) are recognized so we
 * never regenerate them.
 *
 * The summary builder is pure (offline-testable); the scan is a thin, cached disk walk.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the image-optimization summary from a list of image descriptors. PURE.
 *
 * @param array $images List of { ext:string, bytes:int, w?:int, h?:int, has_webp?:bool, has_avif?:bool, has_nextgen?:bool }.
 *                      has_webp/has_avif are preferred; has_nextgen is accepted for
 *                      backward compatibility (treated as "has both").
 * @param array $opts   Optional: 'large_bytes' (default 300 KB), 'max_width', 'max_height'
 *                      (when set, counts convertible images whose dimensions exceed them).
 * @return array{
 *   total:array{count:int,bytes:int},
 *   by_format:array<string,array{count:int,bytes:int}>,
 *   large:array{count:int,bytes:int},
 *   oversized:array{count:int,bytes:int},
 *   without_nextgen:array{count:int,bytes:int},
 *   without_webp:array{count:int,bytes:int},
 *   without_avif:array{count:int,bytes:int},
 *   est_savings_bytes:int
 * }
 */
function wpoptimizing_image_opt_summary( array $images, array $opts = array() ) {
	$large_min = isset( $opts['large_bytes'] ) ? (int) $opts['large_bytes'] : 307200; // 300 KB.
	$max_w     = isset( $opts['max_width'] ) ? (int) $opts['max_width'] : 0;
	$max_h     = isset( $opts['max_height'] ) ? (int) $opts['max_height'] : 0;
	// Conservative WebP savings ratio per source format (vs. the raster original).
	$ratio   = array(
		'jpg'  => 0.30,
		'jpeg' => 0.30,
		'png'  => 0.45,
		'gif'  => 0.50,
		'bmp'  => 0.70,
		'tif'  => 0.70,
		'tiff' => 0.70,
	);
	$nextgen = array( 'webp', 'avif' );

	$out = array(
		'total'             => array(
			'count' => 0,
			'bytes' => 0,
		),
		'by_format'         => array(),
		'large'             => array(
			'count' => 0,
			'bytes' => 0,
		),
		'oversized'         => array(
			'count' => 0,
			'bytes' => 0,
		),
		'without_nextgen'   => array(
			'count' => 0,
			'bytes' => 0,
		),
		'without_webp'      => array(
			'count' => 0,
			'bytes' => 0,
		),
		'without_avif'      => array(
			'count' => 0,
			'bytes' => 0,
		),
		'est_savings_bytes' => 0,
	);

	foreach ( $images as $im ) {
		$ext   = strtolower( (string) ( $im['ext'] ?? '' ) );
		$bytes = (int) ( $im['bytes'] ?? 0 );
		$w     = (int) ( $im['w'] ?? 0 );
		$h     = (int) ( $im['h'] ?? 0 );
		// Prefer per-format flags; fall back to the legacy combined flag.
		$has_webp = array_key_exists( 'has_webp', $im ) ? ! empty( $im['has_webp'] ) : ! empty( $im['has_nextgen'] );
		$has_avif = array_key_exists( 'has_avif', $im ) ? ! empty( $im['has_avif'] ) : ! empty( $im['has_nextgen'] );
		$hasng    = $has_webp || $has_avif;
		if ( '' === $ext ) {
			continue;
		}

		++$out['total']['count'];
		$out['total']['bytes'] += $bytes;

		if ( ! isset( $out['by_format'][ $ext ] ) ) {
			$out['by_format'][ $ext ] = array(
				'count' => 0,
				'bytes' => 0,
			);
		}
		++$out['by_format'][ $ext ]['count'];
		$out['by_format'][ $ext ]['bytes'] += $bytes;

		if ( $bytes >= $large_min ) {
			++$out['large']['count'];
			$out['large']['bytes'] += $bytes;
		}

		// Convertible raster (JPEG/PNG) larger than the configured max dimensions.
		$convertible = in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
		if ( $convertible && ( ( $max_w > 0 && $w > $max_w ) || ( $max_h > 0 && $h > $max_h ) ) ) {
			++$out['oversized']['count'];
			$out['oversized']['bytes'] += $bytes;
		}

		// Convertible raster (JPEG/PNG) that is missing a given next-gen copy.
		if ( $convertible && ! $has_webp ) {
			++$out['without_webp']['count'];
			$out['without_webp']['bytes'] += $bytes;
		}
		if ( $convertible && ! $has_avif ) {
			++$out['without_avif']['count'];
			$out['without_avif']['bytes'] += $bytes;
		}

		// Raster image (not already next-gen, not SVG) with no WebP/AVIF copy yet.
		if ( ! in_array( $ext, $nextgen, true ) && 'svg' !== $ext && ! $hasng ) {
			++$out['without_nextgen']['count'];
			$out['without_nextgen']['bytes'] += $bytes;
			$r                                = isset( $ratio[ $ext ] ) ? $ratio[ $ext ] : 0.30;
			$out['est_savings_bytes']        += (int) round( $bytes * $r );
		}
	}

	uasort(
		$out['by_format'],
		static function ( $a, $b ) {
			return $b['bytes'] <=> $a['bytes'];
		}
	);

	return $out;
}

/**
 * Rank the "worst offender" images for the per-image diagnostics list ("Image Detective").
 * PURE. Keeps only actionable source images (heavy, oversized, or missing a next-gen copy),
 * annotates each with its issue flags and an estimated WebP saving, sorts oversized-first then
 * heaviest-first, and returns the top $limit. Size derivatives (name-WxH.ext) and next-gen
 * copies are excluded so the list shows the originals worth acting on.
 *
 * @param array $images List of { ext, bytes, w, h, rel, has_webp, has_avif } (from the scan).
 * @param array $opts   Optional: 'large_bytes' (default 300 KB), 'max_width', 'max_height'.
 * @param int   $limit  Max rows to return.
 * @return array<int,array{rel:string,ext:string,w:int,h:int,bytes:int,oversized:bool,no_webp:bool,no_avif:bool,no_nextgen:bool,save:int}>
 */
function wpoptimizing_image_opt_worst( array $images, array $opts = array(), $limit = 40 ) {
	$large_min = isset( $opts['large_bytes'] ) ? (int) $opts['large_bytes'] : 307200; // 300 KB.
	$max_w     = isset( $opts['max_width'] ) ? (int) $opts['max_width'] : 0;
	$max_h     = isset( $opts['max_height'] ) ? (int) $opts['max_height'] : 0;
	$ratio     = array(
		'jpg'  => 0.30,
		'jpeg' => 0.30,
		'png'  => 0.45,
		'gif'  => 0.50,
		'bmp'  => 0.70,
		'tif'  => 0.70,
		'tiff' => 0.70,
	);
	$rows      = array();
	// When WordPress scales a big upload down it keeps the original beside foo-scaled.jpg (or
	// foo-rotated.jpg) and the attachment points at the scaled file: the original is never
	// served, only kept to regenerate sizes. Listing both showed the same image twice, the
	// original without an attachment ID, so the original is left out and the attachment's
	// own file is the one row.
	$all_rels = array();
	foreach ( $images as $im ) {
		$all_rels[ (string) ( $im['rel'] ?? '' ) ] = true;
	}
	foreach ( $images as $im ) {
		$ext = strtolower( (string) ( $im['ext'] ?? '' ) );
		$rel = (string) ( $im['rel'] ?? '' );
		if ( '' === $ext || '' === $rel || 'webp' === $ext || 'avif' === $ext ) {
			continue; // Sources only.
		}
		if ( preg_match( '/-\d+x\d+\.[a-z0-9]+$/i', $rel ) ) {
			continue; // Skip generated size derivatives (name-WxH.ext); focus on originals.
		}
		if ( isset( $all_rels[ (string) preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $rel ) ] )
			|| isset( $all_rels[ (string) preg_replace( '/(\.[a-z0-9]+)$/i', '-rotated$1', $rel ) ] ) ) {
			continue; // The pre-scaling original of a -scaled/-rotated attachment file.
		}
		$bytes       = (int) ( $im['bytes'] ?? 0 );
		$w           = (int) ( $im['w'] ?? 0 );
		$h           = (int) ( $im['h'] ?? 0 );
		$convertible = in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true );
		$has_webp    = ! empty( $im['has_webp'] );
		$has_avif    = ! empty( $im['has_avif'] );
		$oversized   = $convertible && ( ( $max_w > 0 && $w > $max_w ) || ( $max_h > 0 && $h > $max_h ) );
		$no_webp     = $convertible && ! $has_webp;
		$no_avif     = $convertible && ! $has_avif;
		$no_nextgen  = 'svg' !== $ext && ! ( $has_webp || $has_avif );
		if ( $bytes < $large_min && ! $oversized && ! $no_nextgen ) {
			continue; // Not actionable — skip.
		}
		$rows[] = array(
			'rel'        => $rel,
			'ext'        => $ext,
			'w'          => $w,
			'h'          => $h,
			'bytes'      => $bytes,
			'oversized'  => $oversized,
			'no_webp'    => $no_webp,
			'no_avif'    => $no_avif,
			'no_nextgen' => $no_nextgen,
			'save'       => $no_nextgen ? (int) round( $bytes * ( isset( $ratio[ $ext ] ) ? $ratio[ $ext ] : 0.30 ) ) : 0,
		);
	}
	usort(
		$rows,
		static function ( $a, $b ) {
			if ( $a['oversized'] !== $b['oversized'] ) {
				return $a['oversized'] ? -1 : 1; // Oversized first.
			}
			return $b['bytes'] <=> $a['bytes']; // Then heaviest first.
		}
	);
	return array_slice( $rows, 0, max( 1, (int) $limit ) );
}

/**
 * Scan /uploads for images and return the cached summary. Read-only; cached daily
 * (transient) because it walks the filesystem. Skips next-gen derivatives as sources.
 *
 * @param bool $force Bypass the cache.
 * @return array Summary from wpoptimizing_image_opt_summary() + 'scanned_at' + 'capped'.
 */
function wpoptimizing_image_opt_scan( $force = false ) {
	// v5: the cached 'worst' list no longer carries the original that WordPress keeps beside a
	// -scaled upload; a new key keeps a day-old v4 list from showing it twice after an update.
	$cache_key = 'wpoptimizing_image_opt_v5';
	if ( ! $force ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$uploads = wp_get_upload_dir();
	$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	$images  = array();
	$gen     = array(); // Next-gen derivatives (our copies), tallied by format.
	$capped  = false;
	$cap     = (int) apply_filters( 'wpoptimizing_image_opt_cap', 50000 );

	// Hard time budget so the walk never hangs a request on a huge library — whatever is
	// gathered before the budget runs out is summarized and cached; the rest is marked
	// capped. Generous on cron/CLI (background refresh), tight on a web request.
	$budget  = (float) apply_filters( 'wpoptimizing_image_opt_time_budget', ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) ? 60.0 : 12.0 );
	$started = microtime( true );

	if ( '' !== $base && is_dir( $base ) && class_exists( 'WPOPT_Media_Analyzer' ) ) {
		try {
			$it   = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			$seen = 0;
			foreach ( $it as $file ) {
				// Check the time budget every 256 files (microtime is cheap, but not free).
				if ( 0 === ( ++$seen & 255 ) && ( microtime( true ) - $started ) > $budget ) {
					$capped = true;
					break;
				}
				if ( ! $file->isFile() ) {
					continue;
				}
				$abs = (string) $file->getPathname();
				if ( ! WPOPT_Media_Analyzer::is_image_file( $abs ) ) {
					continue;
				}
				$ext = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
				// Don't count optimizer-made next-gen copies as their own "images";
				// tally them separately (count + weight) so the UI can show them.
				if ( ( 'webp' === $ext || 'avif' === $ext ) && WPOPT_Media_Analyzer::is_nextgen_derivative( $abs ) ) {
					if ( ! isset( $gen[ $ext ] ) ) {
						$gen[ $ext ] = array(
							'count' => 0,
							'bytes' => 0,
						);
					}
					++$gen[ $ext ]['count'];
					$gen[ $ext ]['bytes'] += (int) $file->getSize();
					continue;
				}
				$is_src = ! ( 'webp' === $ext || 'avif' === $ext || 'svg' === $ext );
				$w      = 0;
				$h      = 0;
				if ( $is_src ) {
					$dim = @getimagesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					if ( is_array( $dim ) && isset( $dim[0], $dim[1] ) ) {
						$w = (int) $dim[0];
						$h = (int) $dim[1];
					}
				}
				$images[] = array(
					'ext'      => $ext,
					'bytes'    => (int) $file->getSize(),
					'w'        => $w,
					'h'        => $h,
					'rel'      => ltrim( substr( $abs, strlen( $base ) ), '/\\' ), // Path relative to uploads (for the per-image list).
					'has_webp' => $is_src && wpoptimizing_image_has_nextgen_format( $abs, 'webp' ),
					'has_avif' => $is_src && wpoptimizing_image_has_nextgen_format( $abs, 'avif' ),
				);
				if ( count( $images ) >= $cap ) {
					$capped = true;
					break;
				}
			}
		} catch ( \Exception $e ) {
			$images = array();
		}
	}

	$cfg                   = function_exists( 'wpoptimizing_image_settings' ) ? wpoptimizing_image_settings() : array(
		'max_width'  => 0,
		'max_height' => 0,
	);
	$summary               = wpoptimizing_image_opt_summary(
		$images,
		array(
			'max_width'  => isset( $cfg['max_width'] ) ? (int) $cfg['max_width'] : 0,
			'max_height' => isset( $cfg['max_height'] ) ? (int) $cfg['max_height'] : 0,
		)
	);
	$summary['scanned_at'] = time();
	$summary['capped']     = $capped;
	$summary['generated']  = $gen; // ext => { count, bytes } for our next-gen copies.
	// Per-image "detective" sample: the worst offenders (oversized / heavy / no next-gen),
	// so the UI can show WHICH images to act on, not just aggregate counts.
	$summary['worst'] = wpoptimizing_image_opt_worst(
		$images,
		array(
			'max_width'  => isset( $cfg['max_width'] ) ? (int) $cfg['max_width'] : 0,
			'max_height' => isset( $cfg['max_height'] ) ? (int) $cfg['max_height'] : 0,
		),
		(int) apply_filters( 'wpoptimizing_image_detective_rows', 200 )
	);

	// Cheap autoloaded flag the front-end uses to decide whether to register the
	// <picture> serving hooks at all (zero per-image cost when there is nothing to serve).
	update_option( 'wpoptimizing_image_copies_present', ! empty( $gen ) ? 1 : 0, true );

	set_transient( $cache_key, $summary, DAY_IN_SECONDS );
	return $summary;
}

/**
 * Return the cached image-optimization summary without ever scanning (so a page render
 * never triggers the disk walk). Null when it has not been computed yet — the unified
 * "Analyze now" pass (or the "Re-scan now" link) populates it.
 *
 * @return array|null
 */
function wpoptimizing_image_opt_cached() {
	$cached = get_transient( 'wpoptimizing_image_opt_v5' );
	return is_array( $cached ) ? $cached : null;
}

/**
 * Image-optimization settings (option-backed, sanitized). Drives the conversion quality,
 * the max width/height used both when recompressing and (Imsanity-style) when down-sizing
 * very large uploads, whether uploads are auto-resized, whether metadata is stripped, and
 * whether recompression is lossless. `max_edge` from older versions migrates to both axes.
 *
 * @return array{quality:int,max_width:int,max_height:int,resize_uploads:bool,serve_nextgen:bool,strip_meta:bool,lossless:bool}
 */
function wpoptimizing_image_settings() {
	return wpoptimizing_image_settings_normalize( get_option( 'wpoptimizing_image_settings', array() ) );
}

/**
 * Pure normalizer for the image settings: merge defaults, migrate the legacy `max_edge` and
 * clamp every field to a safe range. No I/O, so it is the single sanitizer for BOTH the read
 * path (wpoptimizing_image_settings) and the import path (settings-portability), which is why
 * an imported file can never persist an out-of-range max_width/max_height or a bad threshold.
 *
 * @param mixed $stored Raw stored/imported value (array or anything).
 * @return array{quality:int,max_width:int,max_height:int,resize_uploads:bool,serve_nextgen:bool,serve_nextgen_page:bool,nextgen_on_upload:bool,strip_meta:bool,lossless:bool}
 */
function wpoptimizing_image_settings_normalize( $stored ) {
	$defaults = array(
		'quality'            => 82,
		'max_width'          => 2560,
		'max_height'         => 2560,
		'resize_uploads'     => false,
		'serve_nextgen'      => true,
		'serve_nextgen_page' => false,
		'nextgen_on_upload'  => false,
		'strip_meta'         => true,
		'lossless'           => false,
		'webp_quality'       => 0, // 0 = the general quality.
		'avif_quality'       => 0,
		'exclude'            => array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Our image exclusion list, not a query argument.
		'disabled_sizes'     => array(),
	);
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	// Migrate the legacy single "max_edge" to both axes when present.
	if ( ! isset( $stored['max_width'] ) && isset( $stored['max_edge'] ) ) {
		$stored['max_width'] = (int) $stored['max_edge'];
	}
	if ( ! isset( $stored['max_height'] ) && isset( $stored['max_edge'] ) ) {
		$stored['max_height'] = (int) $stored['max_edge'];
	}
	$out                       = array_merge( $defaults, $stored );
	$out                       = array_intersect_key( $out, $defaults ); // Drop unknown keys (e.g. max_edge).
	$out['quality']            = max( 40, min( 100, (int) $out['quality'] ) );
	$out['max_width']          = max( 0, min( 20000, (int) $out['max_width'] ) );
	$out['max_height']         = max( 0, min( 20000, (int) $out['max_height'] ) );
	$out['resize_uploads']     = ! empty( $out['resize_uploads'] );
	$out['serve_nextgen']      = ! empty( $out['serve_nextgen'] );
	$out['serve_nextgen_page'] = ! empty( $out['serve_nextgen_page'] );
	$out['nextgen_on_upload']  = ! empty( $out['nextgen_on_upload'] );
	$out['strip_meta']         = ! empty( $out['strip_meta'] );
	$out['lossless']           = ! empty( $out['lossless'] );
	foreach ( array( 'webp_quality', 'avif_quality' ) as $k ) {
		$q         = (int) $out[ $k ];
		$out[ $k ] = $q <= 0 ? 0 : max( 40, min( 100, $q ) );
	}
	$out['exclude']        = wpoptimizing_image_exclude_normalize( $out['exclude'] ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Our image exclusion list, not a query argument.
	$out['disabled_sizes'] = wpoptimizing_image_disabled_sizes_normalize( $out['disabled_sizes'] );
	return $out;
}

/**
 * Sizes WordPress' own admin draws with: the Media Library grid and the editors use them, so
 * they can never be switched off from here.
 *
 * @return string[]
 */
function wpoptimizing_image_protected_sizes() {
	return array( 'thumbnail', 'medium' );
}

/**
 * Clean a list of size names to stop generating: keys only, never a protected size. Pure.
 *
 * @param mixed $raw List of size names.
 * @return string[]
 */
function wpoptimizing_image_disabled_sizes_normalize( $raw ) {
	$out = array();
	foreach ( (array) $raw as $name ) {
		$name = strtolower( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $name ) );
		if ( '' !== $name && ! in_array( $name, wpoptimizing_image_protected_sizes(), true ) ) {
			$out[] = $name;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Clean the user's exclusion list: one entry per line or array item, relative to uploads/,
 * without leading slashes, at most 100. Pure.
 *
 * @param mixed $raw Array of patterns or a newline-separated string.
 * @return string[]
 */
function wpoptimizing_image_exclude_normalize( $raw ) {
	$lines = is_array( $raw ) ? $raw : preg_split( '/[\r\n]+/', (string) $raw );
	$out   = array();
	foreach ( (array) $lines as $line ) {
		$line = trim( str_replace( '\\', '/', (string) $line ) );
		$line = ltrim( preg_replace( '#[^A-Za-z0-9_\-./*?\[\]]#', '', $line ), '/' );
		if ( '' !== $line && '..' !== $line && false === strpos( $line, '../' ) ) {
			$out[] = $line;
		}
	}
	return array_slice( array_values( array_unique( $out ) ), 0, 100 );
}

/**
 * The quality to encode a format with: its own setting when there is one (AVIF usually looks
 * the same at a much lower number than WebP or JPEG), otherwise the general one.
 *
 * @param string $format  'webp' | 'avif' | anything else.
 * @param int    $quality General quality.
 * @return int
 */
function wpoptimizing_image_format_quality( $format, $quality ) {
	$s   = wpoptimizing_image_settings();
	$key = strtolower( (string) $format ) . '_quality';
	return ! empty( $s[ $key ] ) ? (int) $s[ $key ] : (int) $quality;
}

/*
─────────────────── WebP generation (Phase 2, premium) ───────────────────────
 * Creates next-gen COPIES (image.jpg.webp) next to the originals — it never modifies
 * or deletes a source image, so it is safe and reversible by definition. Each copy we
 * make is logged so the "remove generated copies" action deletes ONLY our files, never
 * another optimizer's. Premium + opt-in. Serving the copies on the front-end is a
 * separate, later step. */

/** @return string[] The next-gen formats the engine knows about. */
function wpoptimizing_image_nextgen_formats() {
	return array( 'webp', 'avif' );
}

/**
 * Whether this server can write a given next-gen format (Imagick with that format, or
 * GD's image{format}() encoder). Generic core for both WebP and AVIF.
 *
 * @param string $format 'webp' or 'avif'.
 * @return bool
 */
function wpoptimizing_image_nextgen_supported( $format ) {
	$format = strtolower( (string) $format );
	if ( ! in_array( $format, wpoptimizing_image_nextgen_formats(), true ) ) {
		return false;
	}
	if ( class_exists( 'Imagick' ) ) {
		try {
			$f = (array) \Imagick::queryFormats( strtoupper( $format ) );
			if ( ! empty( $f ) ) {
				return true;
			}
		} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$ignored = true;
		}
	}
	return function_exists( 'image' . $format ); // imagewebp() / imageavif().
}

/** @return bool Whether this server can write WebP. */
function wpoptimizing_image_webp_supported() {
	return wpoptimizing_image_nextgen_supported( 'webp' );
}

/** @return bool Whether this server can write AVIF (Imagick+libheif, or GD's imageavif()). */
function wpoptimizing_image_avif_supported() {
	return wpoptimizing_image_nextgen_supported( 'avif' );
}

/**
 * Does a raster image already have a copy in ONE specific next-gen format? Checks both
 * the appended-extension (photo.jpg.webp) and replaced-extension (photo.webp) conventions.
 *
 * @param string $abs    Absolute source path.
 * @param string $format 'webp' or 'avif'.
 * @return bool
 */
function wpoptimizing_image_has_nextgen_format( $abs, $format ) {
	$abs    = (string) $abs;
	$format = strtolower( (string) $format );
	$base   = (string) preg_replace( '/\.[A-Za-z0-9]+$/', '', $abs );
	return is_file( $abs . '.' . $format ) || is_file( $base . '.' . $format );
}

/**
 * Source formats we convert to WebP (animated GIF is intentionally skipped).
 *
 * @param string $ext Extension.
 * @return bool
 */
function wpoptimizing_image_is_convertible_ext( $ext ) {
	return in_array( strtolower( (string) $ext ), array( 'jpg', 'jpeg', 'png' ), true );
}

/**
 * The next-gen copy path for a source image (appended-extension convention).
 *
 * @param string $abs    Absolute source path.
 * @param string $format 'webp' or 'avif'.
 * @return string
 */
function wpoptimizing_image_nextgen_target( $abs, $format ) {
	return (string) $abs . '.' . strtolower( (string) $format );
}

/** @param string $abs Absolute source path. @return string WebP copy path. */
function wpoptimizing_image_webp_target( $abs ) {
	return wpoptimizing_image_nextgen_target( $abs, 'webp' );
}

/**
 * The log file that records the next-gen copies WE generated, per format.
 *
 * @param string $format 'webp' or 'avif'.
 * @return string '' when unavailable.
 */
function wpoptimizing_image_nextgen_log_path( $format ) {
	$dir    = defined( 'WPOPT_DIR' ) ? WPOPT_DIR : '';
	$format = strtolower( (string) $format );
	return ( '' !== $dir ) ? trailingslashit( $dir ) . 'image-' . $format . '-generated.log' : '';
}

/**
 * Prepare an Imagick image for re-encoding without changing how it LOOKS.
 *
 * A bare stripImage() threw away two things that are not metadata to the eye: the ICC colour
 * profile (an iPhone photo in Display P3 came out washed out) and the EXIF orientation (a
 * portrait shot came out lying on its side, because the rotation lived only in the tag). So:
 * the rotation is applied to the pixels first, CMYK is converted to sRGB (browsers render
 * CMYK JPEGs inconsistently), and the colour profile is put back after stripping. WordPress's
 * own editor keeps the profile too; we were doing worse than core.
 *
 * @param \Imagick $im    Image.
 * @param bool     $strip Strip metadata (the profile survives).
 * @return void
 */
function wpoptimizing_image_imagick_normalize( $im, $strip ) {
	try {
		$orient = (int) $im->getImageOrientation();
		if ( $orient > 1 ) {
			if ( method_exists( $im, 'autoOrient' ) ) {
				$im->autoOrient();
			} else {
				$ops = array(
					2 => array( 'flop', 0 ),
					3 => array( '', 180 ),
					4 => array( 'flip', 0 ),
					5 => array( 'flop', 270 ),
					6 => array( '', 90 ),
					7 => array( 'flop', 90 ),
					8 => array( '', 270 ),
				);
				if ( isset( $ops[ $orient ] ) ) {
					if ( '' !== $ops[ $orient ][0] ) {
						$im->{$ops[ $orient ][0]}();
					}
					if ( $ops[ $orient ][1] ) {
						$im->rotateImage( new \ImagickPixel( 'none' ), $ops[ $orient ][1] );
					}
				}
			}
			$im->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
		}
		if ( \Imagick::COLORSPACE_CMYK === $im->getImageColorspace() ) {
			$im->transformImageColorspace( \Imagick::COLORSPACE_SRGB );
		}
		if ( $strip ) {
			$icc = $im->getImageProfiles( 'icc', true );
			$im->stripImage();
			if ( ! empty( $icc['icc'] ) ) {
				$im->profileImage( 'icc', $icc['icc'] );
			}
		}
	} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		$ignored = true; // Best effort: an odd profile must not cost the whole conversion.
	}
}

/**
 * Free a GD image on PHP 7.4, where the batch loops would otherwise hold every decoded image
 * until the request ends. On PHP 8 it is a no-op, and from PHP 8.5 calling it is deprecated —
 * one log line per image on a big library.
 *
 * @param resource|\GdImage $img GD image.
 * @return void
 */
function wpoptimizing_image_gd_free( $img ) {
	if ( PHP_VERSION_ID < 80000 && $img ) {
		imagedestroy( $img ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- PHP 7.4 only, see above.
	}
}

/**
 * GD ignores EXIF: rotate a freshly decoded JPEG the way its Orientation tag says, so the
 * re-encoded file (which GD writes without that tag) still shows the right way up.
 *
 * @param resource|\GdImage $img GD image.
 * @param string            $abs Source path.
 * @return resource|\GdImage
 */
function wpoptimizing_image_gd_orient( $img, $abs ) {
	if ( ! function_exists( 'exif_read_data' ) || ! function_exists( 'imagerotate' ) ) {
		return $img;
	}
	$exif   = @exif_read_data( (string) $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- malformed EXIF warns; we only need the tag.
	$orient = is_array( $exif ) && isset( $exif['Orientation'] ) ? (int) $exif['Orientation'] : 1;
	$angle  = array(
		3 => 180,
		4 => 180,
		5 => 270,
		6 => 270,
		7 => 90,
		8 => 90,
	);
	if ( isset( $angle[ $orient ] ) ) {
		$rot = imagerotate( $img, $angle[ $orient ], 0 );
		if ( $rot ) {
			$img = $rot;
		}
	}
	if ( in_array( $orient, array( 2, 4, 5, 7 ), true ) && function_exists( 'imageflip' ) ) {
		imageflip( $img, IMG_FLIP_HORIZONTAL );
	}
	return $img;
}

/**
 * Upload folders the bulk passes never touch: paid downloads, form submissions and logs are
 * files somebody must get back byte for byte, not images to shrink. Matched on the first
 * segment under uploads/.
 *
 * @return string[]
 */
function wpoptimizing_image_excluded_dirs() {
	return (array) apply_filters(
		'wpoptimizing_image_excluded_dirs',
		array( 'woocommerce_uploads', 'edd', 'gravity_forms', 'wpforms', 'wc-logs', 'wpo-cache', 'cache' )
	);
}

/**
 * Is this upload inside a folder the bulk passes must leave alone?
 *
 * @param string $rel Path relative to uploads/.
 * @return bool
 */
function wpoptimizing_image_is_excluded_rel( $rel ) {
	$rel   = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
	$first = strtolower( (string) strtok( $rel, '/' ) );
	if ( '' !== $first && in_array( $first, array_map( 'strtolower', wpoptimizing_image_excluded_dirs() ), true ) ) {
		return true;
	}
	$s = function_exists( 'get_option' ) ? wpoptimizing_image_settings() : array( 'exclude' => array() ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- Our image exclusion list, not a query argument.
	return wpoptimizing_image_matches_exclusion( $rel, (array) $s['exclude'] );
}

/**
 * Does a path relative to uploads/ match one of the user's exclusions? A plain entry is a folder
 * (everything under it) or a file name; an entry with * or ? is a wildcard, tried against the
 * whole path and against the file name. Case-insensitive. Pure.
 *
 * @param string   $rel      Path relative to uploads/.
 * @param string[] $patterns Exclusions.
 * @return bool
 */
function wpoptimizing_image_matches_exclusion( $rel, array $patterns ) {
	$rel  = strtolower( ltrim( (string) $rel, '/' ) );
	$base = basename( $rel );
	foreach ( $patterns as $p ) {
		$p = strtolower( trim( (string) $p, '/' ) );
		if ( '' === $p ) {
			continue;
		}
		if ( false !== strpbrk( $p, '*?[' ) ) {
			if ( fnmatch( $p, $rel ) || fnmatch( $p, $base ) ) {
				return true;
			}
		} elseif ( $rel === $p || $base === $p || 0 === strpos( $rel, $p . '/' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Where we remember sources whose next-gen copy came out HEAVIER than the original. Those
 * copies are deleted on the spot (serving them would make the page slower), and the list
 * keeps the batch from converting the same image again on every pass.
 *
 * @param string $format 'webp' or 'avif'.
 * @return string
 */
function wpoptimizing_image_nextgen_larger_log_path( $format ) {
	$log = wpoptimizing_image_nextgen_log_path( $format );
	return '' === $log ? '' : $log . '.larger';
}

/**
 * Sources whose copy in $format was discarded for being heavier (relative paths as keys).
 *
 * @param string $format 'webp' or 'avif'.
 * @return array<string,true>
 */
function wpoptimizing_image_nextgen_larger_set( $format ) {
	$p = wpoptimizing_image_nextgen_larger_log_path( $format );
	if ( '' === $p || ! is_file( $p ) ) {
		return array();
	}
	$set = array();
	foreach ( (array) file( $p, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $l ) {
		$set[ (string) $l ] = true;
	}
	return $set;
}

/**
 * Convert one image to a next-gen COPY (does not touch the source). Skips when the copy
 * exists. Guards against very large images to avoid memory exhaustion. Generic core for
 * both WebP and AVIF.
 *
 * @param string $abs     Absolute source path.
 * @param string $format  'webp' or 'avif'.
 * @param int    $quality Quality 40-100.
 * @return array{ok:bool,skipped:bool,src_bytes:int,out_bytes:int}|false
 */
function wpoptimizing_image_convert_to_nextgen( $abs, $format, $quality = 82 ) {
	$abs    = (string) $abs;
	$format = strtolower( (string) $format );
	$ext    = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
	if ( ! in_array( $format, wpoptimizing_image_nextgen_formats(), true ) ) {
		return false;
	}
	if ( ! wpoptimizing_image_is_convertible_ext( $ext ) || ! is_file( $abs ) ) {
		return false;
	}
	$quality = wpoptimizing_image_format_quality( $format, (int) $quality );
	$target  = wpoptimizing_image_nextgen_target( $abs, $format );
	if ( is_file( $target ) ) {
		return array(
			'ok'        => true,
			'skipped'   => true,
			'src_bytes' => (int) filesize( $abs ),
			'out_bytes' => (int) filesize( $target ),
		);
	}
	$quality = max( 40, min( 100, (int) $quality ) );

	$info = @getimagesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$max  = (int) apply_filters( 'wpoptimizing_image_max_pixels', 26000000 );
	if ( is_array( $info ) && isset( $info[0], $info[1] ) && ( (int) $info[0] * (int) $info[1] ) > $max ) {
		return false; // too large; skip to stay safe on memory.
	}

	// Strip metadata from the generated copy unless the user opted to keep it.
	$strip = function_exists( 'wpoptimizing_image_settings' ) ? ! empty( wpoptimizing_image_settings()['strip_meta'] ) : true;
	$strip = (bool) apply_filters( 'wpoptimizing_image_strip_meta', $strip );

	$ok = false;
	if ( class_exists( 'Imagick' ) ) {
		try {
			$im = new \Imagick( $abs );
			wpoptimizing_image_imagick_normalize( $im, $strip );
			$im->setImageFormat( $format );
			$im->setImageCompressionQuality( $quality );
			$ok = (bool) $im->writeImage( $target );
			$im->clear();
			$im->destroy();
		} catch ( \Exception $e ) {
			$ok = false;
		}
	}
	$encoder = 'image' . $format; // imagewebp() / imageavif().
	if ( ! $ok && function_exists( $encoder ) ) {
		$img = null;
		if ( ( 'jpg' === $ext || 'jpeg' === $ext ) && function_exists( 'imagecreatefromjpeg' ) ) {
			$img = @imagecreatefromjpeg( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $img ) {
				$img = wpoptimizing_image_gd_orient( $img, $abs );
			}
		} elseif ( 'png' === $ext && function_exists( 'imagecreatefrompng' ) ) {
			$img = @imagecreatefrompng( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $img ) {
				imagepalettetotruecolor( $img );
				imagealphablending( $img, true );
				imagesavealpha( $img, true );
			}
		}
		if ( $img ) {
			$ok = (bool) @$encoder( $img, $target, $quality ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			wpoptimizing_image_gd_free( $img );
		}
	}
	if ( ! $ok ) {
		if ( is_file( $target ) ) {
			wp_delete_file( $target );
		}
		return false;
	}
	clearstatcache( true, $target );
	$src_bytes = (int) filesize( $abs );
	$out_bytes = (int) filesize( $target );
	// A copy that weighs more than its source would be served INSTEAD of it and make the page
	// slower (typical of small or already well-compressed PNGs). Drop it and remember why.
	if ( $out_bytes >= $src_bytes ) {
		wp_delete_file( $target );
		$p = wpoptimizing_image_nextgen_larger_log_path( $format );
		if ( '' !== $p ) {
			file_put_contents( $p, wpoptimizing_image_rel_to_uploads( $abs ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return array(
			'ok'        => true,
			'skipped'   => true,
			'larger'    => true,
			'src_bytes' => $src_bytes,
			'out_bytes' => $out_bytes,
		);
	}
	return array(
		'ok'        => true,
		'skipped'   => false,
		'src_bytes' => $src_bytes,
		'out_bytes' => $out_bytes,
	);
}
/**
 * Record a set of just-generated next-gen copies (relative-to-uploads paths) in the given
 * format's log — the single source of truth the "remove copies" undo reads — and flip the
 * "copies present" flag so front-end serving registers immediately. Shared by the bulk batch
 * and the on-upload generator so both stay reversible the same way. No-op on an empty set.
 *
 * @param string[] $rels   Relative-to-uploads target paths.
 * @param string   $format 'webp' or 'avif'.
 * @return void
 */
function wpoptimizing_image_nextgen_record_copies( array $rels, $format ) {
	$rels = array_values( array_filter( array_map( 'strval', $rels ), 'strlen' ) );
	if ( empty( $rels ) ) {
		return;
	}
	$p = wpoptimizing_image_nextgen_log_path( (string) $format );
	if ( '' !== $p ) {
		file_put_contents( $p, implode( "\n", $rels ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
	update_option( 'wpoptimizing_image_copies_present', 1, true ); // enable front-end serving now.
}

/**
 * Convert one image to a next-gen copy AND record it (log + serving flag), so a single
 * conversion outside the batch (e.g. on upload) stays reversible via "remove copies". Returns
 * true when a NEW copy was created (false when it failed or already existed).
 *
 * @param string $abs     Absolute source path.
 * @param string $format  'webp' or 'avif'.
 * @param int    $quality Quality 40-100.
 * @return bool
 */
function wpoptimizing_image_nextgen_convert_and_log( $abs, $format, $quality = 82 ) {
	$r = wpoptimizing_image_convert_to_nextgen( $abs, $format, $quality );
	if ( ! is_array( $r ) || empty( $r['ok'] ) || ! empty( $r['skipped'] ) ) {
		return false;
	}
	$uploads = wp_get_upload_dir();
	$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	$rel     = ltrim( str_replace( $base, '', wpoptimizing_image_nextgen_target( (string) $abs, (string) $format ) ), '/\\' );
	wpoptimizing_image_nextgen_record_copies( array( $rel ), $format );
	return true;
}


/**
 * How many next-gen copies WE generated in a format (count of non-empty log lines).
 *
 * @param string $format 'webp' or 'avif'.
 * @return int
 */
function wpoptimizing_image_nextgen_generated_count( $format ) {
	$p = wpoptimizing_image_nextgen_log_path( $format );
	if ( '' === $p || ! is_file( $p ) ) {
		return 0;
	}
	$lines = (array) file( $p, FILE_IGNORE_NEW_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- reading our own small log of generated copies.
	$n     = 0;
	foreach ( $lines as $line ) {
		if ( '' !== trim( (string) $line ) ) {
			++$n;
		}
	}
	return $n;
}

/*
──────────────── Image compression (Phase 2b, premium, backup-first) ─────────
 * Recompresses (and optionally down-sizes very large) JPEG/PNG ORIGINALS in place.
 * Because it modifies the source, it is strictly backup-first: every original is copied
 * to image-originals/ before being overwritten, the result is kept ONLY if it is
 * smaller, each image is processed once (no repeated lossy re-encoding), and
 * "Restore originals" copies the backups back. Best run BEFORE WebP generation so the
 * .webp copies are made from the already-optimized originals. Premium + opt-in. */

/**
 * Whether this server can recompress JPEG/PNG (Imagick, or GD with the encoders).
 *
 * @return bool
 */
function wpoptimizing_image_compress_supported() {
	if ( class_exists( 'Imagick' ) ) {
		return true;
	}
	return function_exists( 'imagejpeg' ) && function_exists( 'imagepng' );
}

/** @return string Backup base dir for compressed originals (trailing slash), or ''. */
function wpoptimizing_image_compress_backup_dir() {
	$dir = defined( 'WPOPT_DIR' ) ? WPOPT_DIR : '';
	return ( '' !== $dir ) ? trailingslashit( $dir ) . 'image-originals/' : '';
}

/** @return string Path to the "already processed" log, or ''. */
function wpoptimizing_image_compress_log_path() {
	$dir = defined( 'WPOPT_DIR' ) ? WPOPT_DIR : '';
	return ( '' !== $dir ) ? trailingslashit( $dir ) . 'image-compress-processed.log' : '';
}

/** @return string Path of $abs relative to the uploads basedir (forward slashes). */
function wpoptimizing_image_rel_to_uploads( $abs ) {
	$uploads = wp_get_upload_dir();
	$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	$abs     = (string) $abs;
	// Strip ONLY the leading basedir prefix. A plain str_replace would remove EVERY
	// occurrence, mangling the backup/processed key on any path where the uploads
	// dir name recurs (e.g. /var/uploads/site/uploads/...).
	if ( '' !== $base && 0 === strpos( $abs, $base ) ) {
		$abs = substr( $abs, strlen( $base ) );
	}
	return ltrim( $abs, '/\\' );
}

/**
 * Back up one original before it is overwritten (no-op if already backed up).
 *
 * @param string $abs Absolute source path.
 * @return bool
 */
function wpoptimizing_image_compress_backup_original( $abs ) {
	$bdir = wpoptimizing_image_compress_backup_dir();
	if ( '' === $bdir ) {
		return false;
	}
	$dest     = $bdir . wpoptimizing_image_rel_to_uploads( $abs );
	$src_size = (int) filesize( (string) $abs );
	// Trust an existing backup ONLY if it is byte-complete. A previous run interrupted
	// mid-copy (disk full, killed worker) can leave a truncated file; treating that as
	// "already backed up" would let us overwrite the original with no recoverable copy.
	if ( is_file( $dest ) && (int) filesize( $dest ) === $src_size && $src_size > 0 ) {
		return true; // already backed up (verified) on a previous run.
	}
	if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
		return false;
	}
	$ok = (bool) copy( (string) $abs, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- backing up an original before in-place recompression; runs in AJAX where WP_Filesystem cannot obtain FTP credentials.
	// Verify the copy is byte-complete before the caller is allowed to overwrite the
	// original; a short write must NOT pass as a successful backup. Remove the partial.
	clearstatcache( true, $dest );
	if ( ! $ok || ! is_file( $dest ) || (int) filesize( $dest ) !== $src_size ) {
		if ( is_file( $dest ) ) {
			wp_delete_file( $dest );
		}
		return false;
	}
	return true;
}

/** Append a processed rel path to the log so the image is never re-compressed. */
function wpoptimizing_image_compress_mark( $abs ) {
	$p = wpoptimizing_image_compress_log_path();
	if ( '' !== $p ) {
		file_put_contents( $p, wpoptimizing_image_rel_to_uploads( $abs ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}
}

/**
 * Fit (w×h) within a (max_w × max_h) box without upscaling. PURE. A zero on an axis
 * disables it. Returns the target [width, height]; equals the input when it already fits.
 *
 * @param int $w     Source width.
 * @param int $h     Source height.
 * @param int $max_w Max width (0 = unlimited).
 * @param int $max_h Max height (0 = unlimited).
 * @return array{0:int,1:int}
 */
function wpoptimizing_image_fit_within( $w, $h, $max_w, $max_h ) {
	$w     = (int) $w;
	$h     = (int) $h;
	$max_w = (int) $max_w;
	$max_h = (int) $max_h;
	if ( $w <= 0 || $h <= 0 ) {
		return array( $w, $h );
	}
	$scale = 1.0;
	if ( $max_w > 0 && $w > $max_w ) {
		$scale = min( $scale, $max_w / $w );
	}
	if ( $max_h > 0 && $h > $max_h ) {
		$scale = min( $scale, $max_h / $h );
	}
	if ( $scale >= 1.0 ) {
		return array( $w, $h );
	}
	return array( max( 1, (int) round( $w * $scale ) ), max( 1, (int) round( $h * $scale ) ) );
}

/**
 * Recompress one image in place (backup-first, keep-smaller-only). Down-sizes to fit
 * within the configured max width/height (0 on an axis = unlimited). Honors metadata
 * stripping (Imagick; GD always drops metadata) and a lossless mode: PNG stays lossless,
 * and a JPEG is left untouched unless it must be resized, because lossless JPEG
 * recompression is not possible with GD/Imagick.
 *
 * @param string $abs  Absolute path.
 * @param array  $opts { quality:int, max_width:int, max_height:int, strip_meta:bool, lossless:bool }.
 * @return array{ok:bool,changed:bool,saved:int}|false
 */
function wpoptimizing_image_compress_one( $abs, $opts = array() ) {
	$abs = (string) $abs;
	$ext = strtolower( (string) pathinfo( $abs, PATHINFO_EXTENSION ) );
	if ( ! wpoptimizing_image_is_convertible_ext( $ext ) || ! is_file( $abs ) ) {
		return false;
	}
	$defaults  = array(
		'quality'    => 82,
		'max_width'  => 2560,
		'max_height' => 2560,
		'strip_meta' => true,
		'lossless'   => false,
		'backup'     => true,
	);
	$o         = array_merge( $defaults, is_array( $opts ) ? $opts : array() );
	$quality   = max( 40, min( 100, (int) $o['quality'] ) );
	$max_w     = max( 0, (int) $o['max_width'] );
	$max_h     = max( 0, (int) $o['max_height'] );
	$strip     = ! empty( $o['strip_meta'] );
	$lossless  = ! empty( $o['lossless'] );
	$do_backup = ! empty( $o['backup'] );
	$is_jpeg   = ( 'jpg' === $ext || 'jpeg' === $ext );

	$orig = (int) filesize( $abs );
	$info = @getimagesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( is_array( $info ) && isset( $info[0], $info[1] ) && ( (int) $info[0] * (int) $info[1] ) > (int) apply_filters( 'wpoptimizing_image_max_pixels', 26000000 ) ) {
		wpoptimizing_image_compress_mark( $abs );
		return array(
			'ok'      => true,
			'changed' => false,
			'saved'   => 0,
		);
	}
	$w0              = ( is_array( $info ) && isset( $info[0] ) ) ? (int) $info[0] : 0;
	$h0              = ( is_array( $info ) && isset( $info[1] ) ) ? (int) $info[1] : 0;
	list( $tw, $th ) = wpoptimizing_image_fit_within( $w0, $h0, $max_w, $max_h );
	$needs_resize    = ( $w0 > 0 && $h0 > 0 && ( $tw !== $w0 || $th !== $h0 ) );

	// Lossless + JPEG that needs no resize → leave it untouched (any JPEG re-encode is
	// lossy), so just mark it processed.
	if ( $lossless && $is_jpeg && ! $needs_resize ) {
		wpoptimizing_image_compress_mark( $abs );
		return array(
			'ok'      => true,
			'changed' => false,
			'saved'   => 0,
		);
	}
	$jpeg_q = $lossless ? max( 90, $quality ) : $quality;
	/*
	 * Unique per attempt. A fixed "<file>.wpopt-tmp" is shared by every worker touching the
	 * same image, and this batch runs from AJAX: two tabs, or a tab and a cron tick, can pick
	 * the same file before either marks it processed. Both encode into that one temp, and the
	 * first to reach rename() puts half of the other's GD output over the visitor-facing
	 * original. The verified backup survives, but the media library does not — and nothing
	 * reports it, because from each worker's side the rename succeeded.
	 */
	$tmp = wpoptimizing_atomic_tmp_path( $abs . '.wpopt' );
	$ok  = false;

	if ( class_exists( 'Imagick' ) ) {
		try {
			$im = new \Imagick( $abs );
			wpoptimizing_image_imagick_normalize( $im, $strip );
			if ( $needs_resize ) {
				// After normalising: a rotated portrait has swapped its width and height.
				list( $rw, $rh ) = wpoptimizing_image_fit_within( (int) $im->getImageWidth(), (int) $im->getImageHeight(), $max_w, $max_h );
				$im->resizeImage( $rw, $rh, \Imagick::FILTER_LANCZOS, 1 );
			}
			if ( 'png' !== $ext ) {
				$im->setImageCompressionQuality( $jpeg_q );
			}
			$ok = (bool) $im->writeImage( $tmp );
			$im->clear();
			$im->destroy();
		} catch ( \Exception $e ) {
			$ok = false;
		}
	}
	if ( ! $ok && function_exists( 'imagejpeg' ) ) {
		$img = null;
		if ( $is_jpeg && function_exists( 'imagecreatefromjpeg' ) ) {
			$img = @imagecreatefromjpeg( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $img ) {
				$img = wpoptimizing_image_gd_orient( $img, $abs );
			}
		} elseif ( 'png' === $ext && function_exists( 'imagecreatefrompng' ) ) {
			$img = @imagecreatefrompng( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $img ) {
				imagepalettetotruecolor( $img );
				imagealphablending( $img, false );
				imagesavealpha( $img, true );
			}
		}
		if ( $img ) {
			if ( $needs_resize && function_exists( 'imagescale' ) ) {
				list( $rw, $rh ) = wpoptimizing_image_fit_within( (int) imagesx( $img ), (int) imagesy( $img ), $max_w, $max_h );
				$scaled          = imagescale( $img, $rw, $rh );
				if ( $scaled ) {
					wpoptimizing_image_gd_free( $img );
					$img = $scaled;
				}
			}
			if ( 'png' === $ext ) {
				$ok = @imagepng( $img, $tmp, 9 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			} else {
				$ok = @imagejpeg( $img, $tmp, $jpeg_q ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			wpoptimizing_image_gd_free( $img );
		}
	}

	if ( ! $ok || ! is_file( $tmp ) ) {
		if ( is_file( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		// Mark hard failures as processed so an unreadable/corrupt image does not stay a
		// candidate forever — otherwise the batch never drains and the on-screen pending
		// counter freezes above zero.
		wpoptimizing_image_compress_mark( $abs );
		return false;
	}
	$new = (int) filesize( $tmp );
	// Accept the result when it is smaller, OR when a resize was required (the dimension
	// cap is the goal there, even if a PNG re-encode does not shrink the byte size).
	if ( $new > 0 && ( $new < $orig || $needs_resize ) ) {
		// Back up the original first, unless the caller opted out. Every caller in the plugin
		// asks for it, the upload path included: a fresh upload is the only pristine copy.
		if ( $do_backup && ! wpoptimizing_image_compress_backup_original( $abs ) ) {
			wp_delete_file( $tmp );
			return false; // never overwrite without a verified backup.
		}
		// Atomic in-place replace of the recompressed temp; AJAX/CLI context (no WP_Filesystem credentials).
		if ( ! @rename( $tmp, $abs ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged
			// Replace failed (permissions / cross-device): the original is untouched and
			// the verified backup is intact. Do not report a phantom saving.
			wp_delete_file( $tmp );
			wpoptimizing_image_compress_mark( $abs );
			return array(
				'ok'      => false,
				'changed' => false,
				'saved'   => 0,
			);
		}
		wpoptimizing_image_compress_mark( $abs );
		return array(
			'ok'      => true,
			'changed' => true,
			'saved'   => max( 0, $orig - $new ),
			'resized' => $needs_resize,
		);
	}
	wp_delete_file( $tmp );
	wpoptimizing_image_compress_mark( $abs ); // processed; no worthwhile gain.
	return array(
		'ok'      => true,
		'changed' => false,
		'saved'   => 0,
	);
}

/**
 * After deleting media files, strip any now-dangling original_image reference so an
 * attachment never points at a removed rename-leftover backup. Only acts on deleted
 * files whose basename matches a rename-leftover marker, so a legitimate WordPress
 * big-image original (never named that way) is never touched.
 *
 * @param string[] $deleted Deleted entries in "type::relative_path" format.
 * @return void
 */
function wpoptimizing_clean_deleted_original_image_refs( $deleted ) {
	global $wpdb;
	if ( empty( $deleted ) || ! isset( $wpdb ) || ! is_object( $wpdb ) || ! class_exists( 'WPOPT_Media_Analyzer' ) ) {
		return;
	}
	$names = array();
	foreach ( (array) $deleted as $entry ) {
		$parts = explode( '::', (string) $entry, 2 );
		$rel   = isset( $parts[1] ) ? (string) $parts[1] : '';
		if ( '' === $rel ) {
			continue;
		}
		$base = basename( $rel );
		if ( WPOPT_Media_Analyzer::is_rename_leftover( $base ) ) {
			$names[ $base ] = true;
		}
	}
	if ( empty( $names ) ) {
		return;
	}
	$changes = array();
	foreach ( array_keys( $names ) as $base ) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$res = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata' AND meta_value LIKE %s", '%' . $wpdb->esc_like( $base ) . '%' ), ARRAY_A );
		foreach ( (array) $res as $row ) {
			$meta = maybe_unserialize( (string) $row['meta_value'] );
			if ( is_array( $meta ) && isset( $meta['original_image'] ) && (string) $meta['original_image'] === $base ) {
				unset( $meta['original_image'] );
				$changes[ (int) $row['meta_id'] ] = array( (int) $row['post_id'], $meta );
			}
		}
	}
	// The metadata rows as they are now go into a backup first; without it they stay as they are
	// (a dangling reference is harmless, an unrecoverable edit is not).
	if ( empty( $changes ) || ! class_exists( 'WPOptimizing_Backup_Writer' )
		|| ! ( new WPOptimizing_Backup_Writer( '', 'delete' ) )->rows( 'post_meta', 'post_meta', $wpdb->postmeta, 'meta_id', array_keys( $changes ), 'meta_key' ) ) {
		return;
	}
	foreach ( $changes as $change ) {
		update_post_meta( $change[0], '_wp_attachment_metadata', $change[1] );
	}
}
add_action( 'wpoptimizing_after_delete_paths', 'wpoptimizing_clean_deleted_original_image_refs' );
