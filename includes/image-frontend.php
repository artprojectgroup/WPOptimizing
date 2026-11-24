<?php
/**
 * Image optimization — runtime wiring (always loaded).
 *
 * Connects the option-backed image settings to:
 *   - the recompression engine (quality + max long edge), via the engine's filters;
 *   - WordPress' native upload down-scaling (big_image_size_threshold), Imsanity-style;
 *   - front-end serving of WebP/AVIF copies (added in a later step).
 *
 * The engine filters short-circuit to the incoming value unless the engine (and thus
 * wpoptimizing_image_settings()) is loaded, so this file is safe to load everywhere.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drive the conversion/recompression quality from the saved settings.
 *
 * @param int $quality Incoming default.
 * @return int
 */
function wpoptimizing_image_filter_quality( $quality ) {
	if ( function_exists( 'wpoptimizing_image_settings' ) ) {
		$s = wpoptimizing_image_settings();
		return (int) $s['quality'];
	}
	return (int) $quality;
}
add_filter( 'wpoptimizing_image_quality', 'wpoptimizing_image_filter_quality' );

/**
 * Resize on upload (Imsanity-style) using WordPress' native big-image handling: when
 * enabled, lower the threshold above which core creates a down-scaled "-scaled" copy and
 * serves it everywhere, while preserving the true original (so it stays reversible). The
 * native hook constrains the LONGEST side, so we feed it the larger of the configured
 * width/height; the precise per-axis fit is applied by the recompression step.
 *
 * The settings reader lives in an engine-only file, and this filter fires on EVERY image
 * upload — including a REST one (POST /wp/v2/media), which is not is_admin() and therefore
 * runs with no engine. Calling it unguarded fataled the whole upload: the block editor falls
 * back to REST whenever client-side processing declines a file, so an animated GIF was enough
 * to reproduce it, and any headless or mobile client hit it every time. Pull the one file in
 * on demand and keep a single sanitizer rather than growing a second copy of the clamping.
 *
 * @param int $threshold Core default (2560).
 * @return int
 */
function wpoptimizing_image_upload_threshold( $threshold ) {
	if ( ! function_exists( 'wpoptimizing_image_settings' ) && function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/scanners/image-optim.php' );
	}
	if ( ! function_exists( 'wpoptimizing_image_settings' ) ) {
		return $threshold; // Cannot read the setting → leave core behaviour untouched.
	}
	// Use the sanitized reader (clamps max_width/max_height and migrates max_edge), so a value
	// planted by an import can never push big_image_size_threshold out of a sane range.
	$s = wpoptimizing_image_settings();
	if ( empty( $s['resize_uploads'] ) ) {
		return $threshold; // Feature off → keep core behavior.
	}
	$max = max( (int) $s['max_width'], (int) $s['max_height'] );
	return ( $max > 0 ) ? $max : $threshold;
}
add_filter( 'big_image_size_threshold', 'wpoptimizing_image_upload_threshold', 9 );

/**
 * Stop generating the sizes the site switched off. Every size is one more file per upload,
 * written and kept forever, and themes and plugins register sizes nobody displays. Files that
 * already exist are left where they are.
 *
 * @param array $sizes Sizes WordPress is about to generate.
 * @return array
 */
function wpoptimizing_image_drop_disabled_sizes( $sizes ) {
	$s = get_option( 'wpoptimizing_image_settings', array() );
	if ( ! is_array( $sizes ) || ! is_array( $s ) || empty( $s['disabled_sizes'] ) ) {
		return $sizes;
	}
	$keep = array( 'thumbnail', 'medium' ); // The protected sizes of the image engine, which may not be loaded here.
	foreach ( (array) $s['disabled_sizes'] as $name ) {
		if ( ! in_array( (string) $name, $keep, true ) ) {
			unset( $sizes[ (string) $name ] );
		}
	}
	return $sizes;
}
add_filter( 'intermediate_image_sizes_advanced', 'wpoptimizing_image_drop_disabled_sizes', 20 );


/**
 * When WordPress deletes an image file, delete its companions too: the WebP/AVIF copies
 * (ours, or any optimizer's appended `.jpg.webp`) and the pre-compression backup. All twelve
 * image optimizers we watch do this; we left both on disk after the attachment was gone.
 *
 * Hooked to `wp_delete_file`, so it follows every deletion route — the media library, our own
 * cleanup, a plugin — and nothing else: when a caller short-circuits the deletion (the
 * deduper keeps a file two records share by returning '' at priority 99), the path arrives
 * empty here and nothing is touched.
 *
 * @param string $file Path WordPress is about to delete.
 * @return string Unchanged.
 */
function wpoptimizing_image_delete_companions( $file ) {
	$file = (string) $file;
	if ( '' === $file || ! preg_match( '/\.(jpe?g|png|gif)$/i', $file ) ) {
		return $file;
	}
	foreach ( array( 'webp', 'avif' ) as $format ) {
		if ( is_file( $file . '.' . $format ) ) {
			@unlink( $file . '.' . $format ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- inside the wp_delete_file filter; calling wp_delete_file() here would re-enter it.
		}
	}
	if ( ! function_exists( 'wpoptimizing_image_compress_backup_dir' ) && is_file( __DIR__ . '/scanners/image-optim.php' ) ) {
		require_once __DIR__ . '/scanners/image-optim.php';
	}
	$uploads = wp_get_upload_dir();
	$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
	// Only an upload has a pre-compression backup; restoring one deletes files elsewhere.
	if ( '' !== $base && 0 === strpos( $file, $base ) && function_exists( 'wpoptimizing_image_compress_backup_dir' ) ) {
		$bdir = wpoptimizing_image_compress_backup_dir();
		if ( '' !== $bdir ) {
			$backup = $bdir . wpoptimizing_image_rel_to_uploads( $file );
			if ( is_file( $backup ) ) {
				@unlink( $backup ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- same reason.
			}
		}
	}
	return $file;
}
add_filter( 'wp_delete_file', 'wpoptimizing_image_delete_companions', 100 );


/*
───────────────────── Front-end serving (WebP / AVIF) ─────────────────────────
 * Rewrites <img> tags to <picture> with <source type="image/avif"> and
 * <source type="image/webp"> when our appended-extension copies (image.jpg.webp /
 * .avif) exist next to the original, falling back to the untouched <img>. Browsers pick
 * the first format they support; if a copy is missing the original is served. Safe: it
 * never changes a URL the file for which is absent, and never touches the admin. */

/**
 * Detect another active plugin that already serves WebP/AVIF on the front-end, so we do
 * not double-wrap images in <picture>. Returns the plugin name, or '' when none is found.
 *
 * @return string
 */
function wpoptimizing_image_nextgen_serving_conflict() {
	/**
	 * Filters the plugin found to be serving next-gen images.
	 *
	 * Kept for back-compatibility; `wpoptimizing_compat_owner` is the general hook.
	 *
	 * @param string $name Detected plugin label ('' if none).
	 */
	return (string) apply_filters( 'wpoptimizing_image_serving_conflict', wpoptimizing_compat_owner( 'nextgen_images' ) );
}

/** @return bool Whether next-gen front-end serving is enabled (off if another plugin serves). Memoized. */
function wpoptimizing_image_serving_enabled() {
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}
	if ( function_exists( 'wpoptimizing_image_settings' ) ) {
		$s  = wpoptimizing_image_settings();
		$on = ! empty( $s['serve_nextgen'] );
	} else {
		$s  = get_option( 'wpoptimizing_image_settings', array() );
		$on = ! is_array( $s ) || ! array_key_exists( 'serve_nextgen', $s ) || ! empty( $s['serve_nextgen'] );
	}
	// Yield to another optimizer that already handles serving.
	if ( $on && '' !== wpoptimizing_image_nextgen_serving_conflict() ) {
		$on = false;
	}
	$cached = (bool) apply_filters( 'wpoptimizing_image_serve_nextgen', $on );
	return $cached;
}

/**
 * Cheap "are there any next-gen copies to serve?" signal, so the front-end serving hooks
 * are only registered when they can actually do something (zero overhead otherwise). The
 * scan keeps the option in sync; before the first scan we fall back to a single stat on
 * our generated-copy logs.
 *
 * @return bool
 */
function wpoptimizing_image_has_generated_copies() {
	$flag = get_option( 'wpoptimizing_image_copies_present', null );
	if ( null !== $flag ) {
		return (bool) $flag;
	}
	$dir = defined( 'WPOPT_DIR' ) ? WPOPT_DIR : '';
	if ( '' !== $dir && ( is_file( trailingslashit( $dir ) . 'image-webp-generated.log' ) || is_file( trailingslashit( $dir ) . 'image-avif-generated.log' ) ) ) {
		return true;
	}
	return false;
}

/**
 * Map an uploads URL to its absolute path, or '' when it is not inside this site's
 * uploads directory (so we never probe arbitrary paths).
 *
 * @param string $url Image URL.
 * @return string Absolute path, or ''.
 */
function wpoptimizing_image_url_to_path( $url ) {
	static $base = null;
	if ( null === $base ) {
		$u    = wp_get_upload_dir();
		$base = array(
			'baseurl' => isset( $u['baseurl'] ) ? (string) $u['baseurl'] : '',
			'basedir' => isset( $u['basedir'] ) ? (string) $u['basedir'] : '',
			// With the CDN rewrite on, attachment URLs arrive already on the CDN host; they are
			// the same files, and refusing them meant no <picture> and no WebP for any of them.
			'cdn'     => function_exists( 'wpoptimizing_cdn_alias_of' ) && isset( $u['baseurl'] ) ? wpoptimizing_cdn_alias_of( (string) $u['baseurl'] ) : '',
		);
	}
	if ( '' === $base['baseurl'] || '' === $base['basedir'] ) {
		return '';
	}
	$url = strtok( (string) $url, '?#' ); // drop query/fragment.
	// Normalize protocol-relative + scheme differences against the uploads baseurl.
	$u_norm  = (string) preg_replace( '#^https?:#', '', (string) $url );
	$baseurl = '';
	foreach ( array( preg_replace( '#^https?:#', '', $base['baseurl'] ), $base['cdn'] ) as $candidate ) {
		if ( '' !== (string) $candidate && 0 === strpos( $u_norm, (string) $candidate ) ) {
			$baseurl = (string) $candidate;
			break;
		}
	}
	if ( '' === $baseurl ) {
		return '';
	}
	$rel = ltrim( substr( $u_norm, strlen( $baseurl ) ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
		return '';
	}
	return trailingslashit( $base['basedir'] ) . $rel;
}

/**
 * Resolve the next-gen URL for one source URL, supporting both on-disk conventions so we
 * also serve copies made by other plugins (Imagify, LiteSpeed, EWWW, …):
 *   - appended extension:  photo.jpg.webp  (our default + LiteSpeed)
 *   - replaced extension:  photo.webp      (Imagify and others)
 *
 * @param string $url    Source image URL.
 * @param string $format 'webp' or 'avif'.
 * @return string The next-gen URL when a copy exists on disk, else ''.
 */
function wpoptimizing_image_nextgen_url_for( $url, $format ) {
	// Per-request memo: the same URL appears many times across a page (src + srcset),
	// so cache the disk lookups to keep front-end overhead negligible.
	static $memo = array();
	$key         = $format . '|' . $url;
	if ( array_key_exists( $key, $memo ) ) {
		return $memo[ $key ];
	}
	$out  = '';
	$path = wpoptimizing_image_url_to_path( $url );
	if ( '' !== $path ) {
		if ( is_file( $path . '.' . $format ) ) {
			// Appended convention (our copies + LiteSpeed).
			$out = wpoptimizing_image_nextgen_url_swap( $url, $format, false );
		} else {
			// Replaced convention (photo.jpg → photo.webp).
			$repl_path = preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $path );
			if ( $repl_path !== $path && is_file( $repl_path ) ) {
				$out = wpoptimizing_image_nextgen_url_swap( $url, $format, true );
			}
		}
	}
	$memo[ $key ] = $out;
	return $out;
}

/**
 * The next-gen URL for a source URL, with the format added to the PATH. Pure.
 *
 * A query string (a cache buster, an image CDN's resize arguments) stays where it was:
 * appending after it produced photo.jpg?ver=2.webp, which asks for the JPEG again under a
 * different query and never for the copy.
 *
 * @since 0.1.0
 * @param string $url     Source URL.
 * @param string $format  'webp' or 'avif'.
 * @param bool   $replace True for photo.webp, false for photo.jpg.webp.
 * @return string
 */
function wpoptimizing_image_nextgen_url_swap( $url, $format, $replace ) {
	$url  = (string) $url;
	$cut  = strcspn( $url, '?#' );
	$path = substr( $url, 0, $cut );
	$tail = (string) substr( $url, $cut );
	$path = $replace ? (string) preg_replace( '/\.(jpe?g|png|gif)$/i', '.' . $format, $path ) : $path . '.' . $format;
	return $path . $tail;
}

/**
 * Build a next-gen srcset from an existing srcset (or a single src), keeping only the
 * candidates whose copy actually exists on disk (in either naming convention).
 *
 * @param string $srcset Original srcset, or ''.
 * @param string $src    Original src (used when there is no srcset).
 * @param string $format 'webp' or 'avif'.
 * @return string Next-gen srcset, or '' when no copy exists.
 */
function wpoptimizing_image_nextgen_srcset( $srcset, $src, $format ) {
	$entries = array();
	if ( '' !== (string) $srcset ) {
		foreach ( explode( ',', (string) $srcset ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$bits = preg_split( '/\s+/', $part, 2 );
			$desc = isset( $bits[1] ) ? ' ' . $bits[1] : '';
			$ng   = wpoptimizing_image_nextgen_url_for( $bits[0], $format );
			if ( '' !== $ng ) {
				$entries[] = $ng . $desc;
			}
		}
	} elseif ( '' !== (string) $src ) {
		$ng = wpoptimizing_image_nextgen_url_for( $src, $format );
		if ( '' !== $ng ) {
			$entries[] = $ng;
		}
	}
	return implode( ', ', $entries );
}

/**
 * Wrap a single <img> tag in a <picture> with AVIF + WebP sources when copies exist.
 *
 * @param string $html An <img> tag (possibly the only markup passed by the filter).
 * @return string
 */
function wpoptimizing_image_wrap_picture( $html ) {
	if ( ! is_string( $html ) || '' === $html ) {
		return $html;
	}
	if ( is_admin() || is_feed() || ! wpoptimizing_image_serving_enabled() ) {
		return $html;
	}
	if ( false === stripos( $html, '<img' ) || false !== stripos( $html, '<picture' ) ) {
		return $html;
	}
	if ( ! preg_match( '/<img\b[^>]*>/i', $html, $m ) ) {
		return $html;
	}
	$img = $m[0];

	$src    = preg_match( '/\ssrc=("|\')(.*?)\1/i', $img, $a ) ? $a[2] : '';
	$srcset = preg_match( '/\ssrcset=("|\')(.*?)\1/i', $img, $b ) ? $b[2] : '';
	$sizes  = preg_match( '/\ssizes=("|\')(.*?)\1/i', $img, $c ) ? $c[2] : '';
	if ( '' === $src && '' === $srcset ) {
		return $html;
	}

	$sources   = '';
	$sizes_att = ( '' !== $sizes ) ? ' sizes="' . esc_attr( $sizes ) . '"' : '';
	// AVIF first (preferred), then WebP — browsers use the first they support.
	foreach ( array( 'avif', 'webp' ) as $format ) {
		$ng = wpoptimizing_image_nextgen_srcset( $srcset, $src, $format );
		if ( '' !== $ng ) {
			$sources .= '<source type="image/' . $format . '" srcset="' . esc_attr( $ng ) . '"' . $sizes_att . '>';
		}
	}
	if ( '' === $sources ) {
		return $html; // no copies on disk → leave the original untouched.
	}
	// Replace only the matched <img> with the wrapped version (keep any surrounding markup).
	return str_replace( $img, '<picture>' . $sources . $img . '</picture>', $html );
}

/**
 * Content image tags (WP 6.0+): wrap each in a <picture> when next-gen copies exist.
 *
 * @param string $filtered_image The <img> tag.
 * @return string
 */
function wpoptimizing_image_filter_content_img( $filtered_image ) {
	return wpoptimizing_image_wrap_picture( $filtered_image );
}

/**
 * Attachment images rendered by themes (featured images, galleries, blocks).
 *
 * @param string $html The <img> markup.
 * @return string
 */
function wpoptimizing_image_filter_attachment_html( $html ) {
	return wpoptimizing_image_wrap_picture( $html );
}

/**
 * Register the serving hooks lazily on `wp` (front-end only) and ONLY when serving is
 * enabled and there is something to serve — so a site that does not use next-gen serving
 * (or has another optimizer doing it) pays zero per-image cost on every request.
 *
 * @return void
 */
function wpoptimizing_image_maybe_register_serving() {
	if ( is_admin() || is_feed() ) {
		return;
	}
	if ( ! wpoptimizing_image_serving_enabled() || ! wpoptimizing_image_has_generated_copies() ) {
		return;
	}
	add_filter( 'wp_content_img_tag', 'wpoptimizing_image_filter_content_img', 20 );
	add_filter( 'wp_get_attachment_image', 'wpoptimizing_image_filter_attachment_html', 20 );
	add_filter( 'post_thumbnail_html', 'wpoptimizing_image_filter_attachment_html', 20 );
}
add_action( 'wp', 'wpoptimizing_image_maybe_register_serving' );

/*
 * Whole-page pass (opt-in), run once when the page cache stores a page — never per visit.
 * Covers what the tag filters above never see: inline CSS backgrounds (style attributes and
 * <style> blocks, which is where page builders put them) and images printed outside the
 * filtered functions (theme headers, footers, builder widgets).
 */

/**
 * Add an image-set() with the WebP/AVIF copies after every CSS background that uses one
 * local image. Pure: $resolve( $url, $format ) returns the copy's URL or ''.
 *
 * The original declaration stays first and the image-set() is a second background-image, so
 * a browser that cannot read image-set() with type() drops that one line and keeps the
 * original — the worst case is the JPEG it had before. Layered backgrounds, gradients and
 * declarations that already carry an image-set() are left alone, and a declaration already
 * followed by ours is not touched again.
 *
 * @param string   $css     CSS text (a style attribute's value or a <style> block).
 * @param string   $q       Quote character safe in this context (the one NOT delimiting the attribute).
 * @param callable $resolve Copy resolver.
 * @return string
 */
function wpoptimizing_image_nextgen_css( $css, $q, $resolve ) {
	if ( ! is_string( $css ) || false === stripos( $css, 'url(' ) ) {
		return $css;
	}
	$mimes = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
	);
	$out   = preg_replace_callback(
		// An HTML entity (&quot; in a style attribute) ends in ';' but does not end the value.
		'/(?<=^|[\s;{"\'])background(?:-image)?\s*:\s*((?:&\#?[a-z0-9]+;|[^;{}])*+)(?!;background-image:image-set\()/i',
		static function ( $m ) use ( $q, $resolve, $mimes ) {
			$val = $m[1];
			if ( 1 !== substr_count( strtolower( $val ), 'url(' ) || false !== stripos( $val, 'image-set' ) || false !== stripos( $val, 'gradient' ) ) {
				return $m[0];
			}
			if ( ! preg_match( '/url\(\s*(?:&quot;|&#0?39;|["\'])?([^"\'()\s&<>]+?)(?:&quot;|&#0?39;|["\'])?\s*\)/i', $val, $u ) ) {
				return $m[0];
			}
			// One layer only: a comma outside the url() means several backgrounds.
			if ( false !== strpos( str_replace( $u[0], '', $val ), ',' ) ) {
				return $m[0];
			}
			$url = $u[1];
			if ( ! preg_match( '/\.(jpe?g|png|gif)(?:[?#].*)?$/i', $url, $e ) ) {
				return $m[0];
			}
			$sets = array();
			foreach ( array( 'avif', 'webp' ) as $f ) {
				$ng = (string) call_user_func( $resolve, $url, $f );
				if ( '' !== $ng && ! preg_match( '/["\'()\s<>]/', $ng ) ) {
					$sets[] = 'url(' . $ng . ') type(' . $q . 'image/' . $f . $q . ')';
				}
			}
			if ( empty( $sets ) ) {
				return $m[0];
			}
			$sets[] = 'url(' . $url . ') type(' . $q . $mimes[ strtolower( $e[1] ) ] . $q . ')';
			$imp    = preg_match( '/!\s*important\s*$/i', $val ) ? ' !important' : '';
			return rtrim( $m[0] ) . ';background-image:image-set(' . implode( ',', $sets ) . ')' . $imp;
		},
		$css
	);
	return null === $out ? $css : $out;
}

/**
 * Whether the whole-page pass is on: serving on, the switch on, and copies to serve.
 *
 * @return bool
 */
function wpoptimizing_image_nextgen_page_enabled() {
	$s = function_exists( 'wpoptimizing_image_settings' ) ? wpoptimizing_image_settings() : (array) get_option( 'wpoptimizing_image_settings', array() );
	return ! empty( $s['serve_nextgen_page'] ) && wpoptimizing_image_serving_enabled() && wpoptimizing_image_has_generated_copies();
}

/**
 * Copy resolver for the page pass: root-relative URLs are made absolute first, so the uploads
 * check in wpoptimizing_image_url_to_path() can recognise them.
 *
 * @param string $url    Image URL as written in the page.
 * @param string $format 'webp' or 'avif'.
 * @return string
 */
function wpoptimizing_image_nextgen_page_resolve( $url, $format ) {
	if ( '/' !== substr( $url, 0, 1 ) || '//' === substr( $url, 0, 2 ) ) {
		return wpoptimizing_image_nextgen_url_for( $url, $format );
	}
	// Root-relative: relative to the host, whatever subdirectory WordPress lives in.
	$p   = wp_parse_url( (string) home_url() );
	$abs = ( isset( $p['scheme'] ) ? $p['scheme'] . ':' : '' ) . '//' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' ) . $url;
	$ng  = wpoptimizing_image_nextgen_url_for( $abs, $format );
	// Same prefix in both naming conventions, so the page keeps its root-relative form.
	return '' === $ng ? '' : substr( $ng, strlen( $abs ) - strlen( $url ) );
}

/**
 * The page pass itself. Scripts, templates, textareas and <noscript> are never touched (an
 * attribute quote added inside a JavaScript string would break it); an <img> already in a
 * <picture> keeps it.
 *
 * @param string $html Page HTML about to be cached.
 * @return string
 */
function wpoptimizing_image_nextgen_page_html( $html ) {
	if ( ! is_string( $html ) || '' === $html || ! wpoptimizing_image_nextgen_page_enabled() ) {
		return $html;
	}
	$resolve = 'wpoptimizing_image_nextgen_page_resolve';
	$chunks  = preg_split( '#(<(script|template|textarea|noscript)\b.*?</\2\s*>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( ! is_array( $chunks ) ) {
		return $html;
	}
	$out = '';
	$n   = count( $chunks );
	for ( $i = 0; $i < $n; $i++ ) {
		$c = $chunks[ $i ];
		if ( preg_match( '#^<(script|template|textarea|noscript)\b#i', $c ) ) {
			$out .= $c;
			++$i; // Skip the captured tag name that follows each delimiter.
			continue;
		}
		$c    = (string) preg_replace_callback(
			'#(<style\b[^>]*>)(.*?)(</style\s*>)#is',
			static function ( $m ) use ( $resolve ) {
				return $m[1] . wpoptimizing_image_nextgen_css( $m[2], '"', $resolve ) . $m[3];
			},
			$c
		);
		$c    = (string) preg_replace_callback(
			'#(\sstyle=)("|\')(.*?)\2#is',
			static function ( $m ) use ( $resolve ) {
				return $m[1] . $m[2] . wpoptimizing_image_nextgen_css( $m[3], '"' === $m[2] ? "'" : '"', $resolve ) . $m[2];
			},
			$c
		);
		$c    = (string) preg_replace_callback(
			'#<picture\b.*?</picture\s*>|<img\b[^>]*>#is',
			static function ( $m ) {
				return 0 === stripos( $m[0], '<picture' ) ? $m[0] : wpoptimizing_image_wrap_picture( $m[0] );
			},
			$c
		);
		$out .= $c;
	}
	return $out;
}
add_filter( 'wpoptimizing_page_cache_html', 'wpoptimizing_image_nextgen_page_html', 20 );
