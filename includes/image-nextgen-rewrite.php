<?php
/**
 * WebP/AVIF served by the web server itself (opt-in).
 *
 * The <picture> serving in image-frontend.php only reaches the <img> tags WordPress filters.
 * Backgrounds set in CSS files, images a page builder or a theme prints by hand and anything
 * cached elsewhere keep the JPEG/PNG. A rule in the uploads .htaccess answers the request for
 * photo.jpg with photo.jpg.avif or photo.jpg.webp (or photo.avif / photo.webp, the naming other
 * optimizers use) when the browser says it accepts the format and the copy exists, with
 * `Vary: Accept` so a shared cache keeps one answer per format. The URL never changes, so it
 * covers every place an image is used, and PHP is never involved.
 *
 * Apache and LiteSpeed only; Nginx is given the equivalent configuration to paste. The block
 * lives in the uploads folder (so it can only ever act on uploads) between our own markers,
 * next to whatever a security plugin keeps there. A check asks the server for a real image the
 * way a browser does and reports which format came back.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Marker of our block in the uploads .htaccess.
 *
 * @return string
 */
function wpoptimizing_nextgen_rewrite_marker() {
	return 'WPOptimizing WebP AVIF';
}

/**
 * The .htaccess lines. Pure.
 *
 * AVIF before WebP, each in both naming conventions; `-f` on the copy so a missing copy can
 * never turn into a 404. In a per-directory .htaccess the substitution is relative, so the
 * rule works whatever the uploads path is. `T=` sets the type for servers whose mime table
 * does not know .avif yet.
 *
 * @return string[]
 */
function wpoptimizing_nextgen_rewrite_rules() {
	$lines = array(
		'<IfModule mod_mime.c>',
		'	AddType image/webp .webp',
		'	AddType image/avif .avif',
		'</IfModule>',
		'<IfModule mod_rewrite.c>',
		'	RewriteEngine On',
		// A RewriteEngine in a subfolder REPLACES the parent's rules for it: without this line
		// the root .htaccess (an HTTPS redirect, hotlink protection, another plugin's rules)
		// stopped applying to every file in uploads. InheritBefore runs them first, so a
		// hotlink block still refuses the JPEG before it is swapped for its copy. Apache 2.4.8+.
		'	RewriteOptions InheritBefore',
	);
	foreach ( array( 'avif', 'webp' ) as $f ) {
		array_push(
			$lines,
			'	RewriteCond %{HTTP_ACCEPT} image/' . $f,
			'	RewriteCond %{REQUEST_FILENAME}.' . $f . ' -f',
			'	RewriteRule ^(.+\.(?:jpe?g|png|gif))$ $1.' . $f . ' [NC,T=image/' . $f . ',L]',
			'	RewriteCond %{HTTP_ACCEPT} image/' . $f,
			'	RewriteCond %{REQUEST_FILENAME} ^(.+)\.(?:jpe?g|png|gif)$ [NC]',
			'	RewriteCond %1.' . $f . ' -f',
			'	RewriteRule ^(.+)\.(?:jpe?g|png|gif)$ $1.' . $f . ' [NC,T=image/' . $f . ',L]'
		);
	}
	array_push(
		$lines,
		'</IfModule>',
		'<IfModule mod_headers.c>',
		'	<FilesMatch "(?i)\.(jpe?g|png|gif|webp|avif)$">',
		'		Header merge Vary Accept',
		'	</FilesMatch>',
		'</IfModule>'
	);
	return $lines;
}

/**
 * The same for Nginx, which ignores .htaccess. Pure.
 *
 * A format the browser does not accept maps to a suffix no file has, so try_files moves on to
 * the next candidate instead of matching the original under an empty suffix. The location
 * captures the name without its extension for the photo.webp naming.
 *
 * @param string $path URL path of the uploads folder, e.g. "/wp-content/uploads".
 * @return string
 */
function wpoptimizing_nextgen_rewrite_nginx_snippet( $path ) {
	$path = untrailingslashit( (string) $path );
	return "# http { } block:\n"
		. "map \$http_accept \$wpo_avif { default \".wpo-none\"; \"~*image/avif\" \".avif\"; }\n"
		. "map \$http_accept \$wpo_webp { default \".wpo-none\"; \"~*image/webp\" \".webp\"; }\n\n"
		. "# server { } block:\n"
		. 'location ~* ^(?<wpo_base>' . $path . "/.+)\\.(jpe?g|png|gif)$ {\n"
		. "\tadd_header Vary Accept;\n"
		. "\ttry_files \$uri\$wpo_avif \$wpo_base\$wpo_avif \$uri\$wpo_webp \$wpo_base\$wpo_webp \$uri =404;\n"
		. '}';
}

/**
 * Remove our block (markers included) from .htaccess contents. Pure.
 *
 * @param string $contents File contents.
 * @return string
 */
function wpoptimizing_nextgen_rewrite_strip( $contents ) {
	$marker = wpoptimizing_nextgen_rewrite_marker();
	$out    = preg_replace( '/\n*' . preg_quote( '# BEGIN ' . $marker, '/' ) . '.*?' . preg_quote( '# END ' . $marker, '/' ) . '[^\n]*\n?/s', "\n", (string) $contents );
	return null === $out ? (string) $contents : ltrim( $out, "\n" );
}

/**
 * The uploads folder and its .htaccess.
 *
 * @return array{basedir:string,baseurl:string,path:string}
 */
function wpoptimizing_nextgen_rewrite_paths() {
	$up      = wp_get_upload_dir();
	$basedir = (string) ( $up['basedir'] ?? '' );
	return array(
		'basedir' => $basedir,
		'baseurl' => (string) ( $up['baseurl'] ?? '' ),
		'path'    => '' === $basedir ? '' : trailingslashit( $basedir ) . '.htaccess',
	);
}

/**
 * Whether the uploads .htaccess carries our block.
 *
 * @return bool
 */
function wpoptimizing_nextgen_rewrite_active() {
	$p = wpoptimizing_nextgen_rewrite_paths();
	return '' !== $p['path'] && is_readable( $p['path'] ) && false !== strpos( (string) file_get_contents( $p['path'] ), '# BEGIN ' . wpoptimizing_nextgen_rewrite_marker() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * Write or remove the block.
 *
 * Writing is refused while another plugin serves WebP/AVIF: two sets of rules answering the
 * same request leave nobody able to tell which one did. Removing always works.
 *
 * @param bool $enable True to write, false to remove.
 * @return array{ok:bool,code:string,owner?:string} code: done|notwritable|writefail|foreign|nouploads|nobackup.
 */
function wpoptimizing_nextgen_rewrite_apply( $enable ) {
	$owner = ( $enable && function_exists( 'wpoptimizing_image_nextgen_serving_conflict' ) ) ? wpoptimizing_image_nextgen_serving_conflict() : '';
	if ( '' !== $owner ) {
		return array(
			'ok'    => false,
			'code'  => 'foreign',
			'owner' => $owner,
		);
	}
	$path = wpoptimizing_nextgen_rewrite_paths()['path'];
	if ( '' === $path ) {
		return array(
			'ok'   => false,
			'code' => 'nouploads',
		);
	}
	if ( file_exists( $path ) ? ! wp_is_writable( $path ) : ! wp_is_writable( dirname( $path ) ) ) {
		return array(
			'ok'   => false,
			'code' => 'notwritable',
		);
	}
	// Removing a block that is not there changes nothing, and needs no backup.
	$current = file_exists( $path ) ? (string) file_get_contents( $path ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	if ( ! $enable && ( null === $current || wpoptimizing_nextgen_rewrite_strip( $current ) === $current ) ) {
		delete_option( 'wpoptimizing_nextgen_rewrite_check' );
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
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		$ok = (bool) insert_with_markers( $path, wpoptimizing_nextgen_rewrite_marker(), wpoptimizing_nextgen_rewrite_rules() );
		return array(
			'ok'   => $ok,
			'code' => $ok ? 'done' : 'writefail',
		);
	}
	delete_option( 'wpoptimizing_nextgen_rewrite_check' );
	if ( ! file_exists( $path ) ) {
		return array(
			'ok'   => true,
			'code' => 'done',
		);
	}
	$contents = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$new      = wpoptimizing_nextgen_rewrite_strip( $contents );
	if ( $new === $contents ) {
		return array(
			'ok'   => true,
			'code' => 'done',
		);
	}
	// Only our block was in it: leave no empty .htaccess behind.
	if ( '' === trim( $new ) ) {
		wp_delete_file( $path );
		return array(
			'ok'   => ! file_exists( $path ),
			'code' => file_exists( $path ) ? 'writefail' : 'done',
		);
	}
	$tmp = wpoptimizing_atomic_tmp_path( $path );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Rewriting the uploads .htaccess without our block, via a temp file.
	if ( false === file_put_contents( $tmp, $new, LOCK_EX ) || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
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
 * Which format a response came back in, from its Content-Type. Pure.
 *
 * @param string $content_type Content-Type header.
 * @return string 'avif', 'webp', 'original' or '' when it is not an image at all.
 */
function wpoptimizing_nextgen_rewrite_format_of( $content_type ) {
	$t = strtolower( trim( (string) $content_type ) );
	if ( 0 === strpos( $t, 'image/avif' ) ) {
		return 'avif';
	}
	if ( 0 === strpos( $t, 'image/webp' ) ) {
		return 'webp';
	}
	return 0 === strpos( $t, 'image/' ) ? 'original' : '';
}

/**
 * A recent upload that has a next-gen copy, to ask the server for.
 *
 * @return array{url:string,expect:string}|null expect: the best format it has a copy in.
 */
function wpoptimizing_nextgen_rewrite_sample() {
	$ids = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => array( 'image/jpeg', 'image/png', 'image/gif' ),
			'posts_per_page' => 40,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( (array) $ids as $id ) {
		$url = (string) wp_get_attachment_url( (int) $id );
		if ( '' === $url || ! function_exists( 'wpoptimizing_image_nextgen_url_for' ) ) {
			continue;
		}
		foreach ( array( 'avif', 'webp' ) as $f ) {
			if ( '' !== wpoptimizing_image_nextgen_url_for( $url, $f ) ) {
				return array(
					'url'    => $url,
					'expect' => $f,
				);
			}
		}
	}
	return null;
}

/**
 * Ask the server for a real image the way a browser does, and keep what came back.
 *
 * @return array{state:string,checked:int,url?:string,expect?:string,got?:string,vary?:bool,cdn?:string}
 *         state: works|not_applied|no_sample|error.
 */
function wpoptimizing_nextgen_rewrite_check() {
	$out    = array(
		'state'   => 'no_sample',
		'checked' => time(),
	);
	$sample = wpoptimizing_nextgen_rewrite_sample();
	if ( null !== $sample ) {
		$r             = wp_remote_head(
			$sample['url'],
			array(
				'timeout'     => 8,
				'redirection' => 2,
				// Some firewalls answer 403 to WordPress' own user agent.
				'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 WPOptimizing-check',
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter for loopback requests.
				'headers'     => array(
					'Accept'        => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
					'Cache-Control' => 'no-cache',
				),
			)
		);
		$out['url']    = $sample['url'];
		$out['expect'] = $sample['expect'];
		if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
			$out['state'] = 'error';
		} else {
			$out['got']   = wpoptimizing_nextgen_rewrite_format_of( (string) wp_remote_retrieve_header( $r, 'content-type' ) );
			$out['vary']  = false !== stripos( (string) wp_remote_retrieve_header( $r, 'vary' ), 'accept' );
			$out['state'] = in_array( $out['got'], array( 'avif', 'webp' ), true ) ? 'works' : 'not_applied';
			// A CDN in front of the images: it has to keep one copy per format.
			$headers = array();
			$raw = wp_remote_retrieve_headers( $r );
			// An object (Requests' case-insensitive dictionary), not an array: cast, it yields its
			// private properties and no header at all — which is how cf-ray read as missing.
			$raw = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
			foreach ( $raw as $k => $v ) {
				$headers[ strtolower( (string) $k ) ] = strtolower( is_array( $v ) ? implode( ', ', $v ) : (string) $v );
			}
			if ( function_exists( 'wpoptimizing_edge_cache_classify' ) ) {
				$layers     = wpoptimizing_edge_cache_classify( $headers );
				$out['cdn'] = implode( ', ', wp_list_pluck( $layers, 'label' ) );
			}
		}
	}
	update_option( 'wpoptimizing_nextgen_rewrite_check', $out, false );
	return $out;
}

/**
 * Last check, or null.
 *
 * @return array|null
 */
function wpoptimizing_nextgen_rewrite_last_check() {
	$c = get_option( 'wpoptimizing_nextgen_rewrite_check', null );
	return is_array( $c ) && isset( $c['state'] ) ? $c : null;
}
