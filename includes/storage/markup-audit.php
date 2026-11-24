<?php
/**
 * What the browser is actually handed: how many elements the page has, and how many of its
 * images are missing the three attributes that decide whether a page feels fast.
 *
 * Everything else the report measures is PHP: time on the server, queries, hooks, opcode
 * cache. None of that explains the two complaints users actually have — "it takes ages to
 * settle" and "it jumps while it loads" — because both are decided by the markup:
 *
 *   - no width/height  → the browser cannot reserve the space, so everything below the image
 *                        jumps when it arrives. This is the whole of Cumulative Layout Shift.
 *   - no loading=lazy  → every image below the fold competes for bandwidth with the ones the
 *                        visitor can actually see.
 *   - no srcset        → a phone downloads the desktop image.
 *   - element count    → past a few thousand nodes, style and layout cost grows with the tree
 *                        whatever the server does. Usually a page builder nesting wrappers.
 *
 * ZERO front-end cost, by construction. The analysis is pure (no WordPress calls, so it is
 * unit-testable offline) and the HTML comes from a loopback request made when the admin asks
 * for it — the same way Google Fonts discovery works. Nothing is buffered, measured or
 * injected on a visitor's request: a diagnostic that slowed down the thing it measures would
 * be a poor advertisement for a performance plugin.
 *
 * The page is fetched as an anonymous visitor, so what is measured is what the public gets,
 * after this plugin's own image optimisation has run.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Measure a page's markup.
 *
 * Deliberately a tag scan and not DOMDocument: real-world WordPress output is not valid XML,
 * DOMDocument on a large page costs many megabytes, and every number here is a count of
 * something that appears verbatim in the source.
 *
 * @param string $html Rendered HTML.
 * @return array{elements:int,images:int,no_dimensions:int,no_lazy:int,no_srcset:int,no_alt:int,iframes:int,inline_styles:int,ok:bool}
 */
function wpoptimizing_markup_measure( $html ) {
	$html = (string) $html;
	$out  = array(
		'elements'      => 0,
		'images'        => 0,
		'no_dimensions' => 0,
		'no_lazy'       => 0,
		'no_srcset'     => 0,
		'no_alt'        => 0,
		'iframes'       => 0,
		'inline_styles' => 0,
		'ok'            => false,
	);
	if ( '' === $html || false === stripos( $html, '<body' ) ) {
		return $out; // Not a rendered page (an error, a redirect, a JSON body).
	}

	// Only the body: a <head> full of <link> and <meta> is not what makes a tree expensive,
	// and counting it would make every page look heavier than it is.
	$pos  = stripos( $html, '<body' );
	$body = substr( $html, $pos );

	// Opening tags of real elements. Closing tags, comments, doctypes and processing
	// instructions all start with a character this excludes.
	$out['elements'] = (int) preg_match_all( '/<[a-zA-Z][a-zA-Z0-9-]*(?:\s|>|\/)/', $body );
	$out['iframes']  = (int) preg_match_all( '/<iframe\b/i', $body );

	// style="…" on an element. A handful is normal; hundreds means a builder is inlining a
	// stylesheet one element at a time, which no cache can help with.
	$out['inline_styles'] = (int) preg_match_all( '/<[a-zA-Z][^>]*\sstyle\s*=\s*["\']/i', $body );

	if ( ! preg_match_all( '/<img\b[^>]*>/i', $body, $imgs ) ) {
		$out['ok'] = true;
		return $out;
	}

	foreach ( $imgs[0] as $tag ) {
		// An SVG sprite or a 1px tracking pixel is not a layout-shift problem and would only
		// add noise to counts the user is meant to act on.
		if ( preg_match( '/\ssrc\s*=\s*["\'][^"\']*\.svg\b/i', $tag ) ) {
			continue;
		}
		++$out['images'];

		$has_w = (bool) preg_match( '/\swidth\s*=\s*["\']?\d/i', $tag );
		$has_h = (bool) preg_match( '/\sheight\s*=\s*["\']?\d/i', $tag );
		if ( ! $has_w || ! $has_h ) {
			++$out['no_dimensions'];
		}
		// Deferred is deferred. loading="eager" is a deliberate choice (the LCP image), and a
		// third-party lazy-loader that swaps a data-* src in JavaScript achieves the same thing
		// without the native attribute — Avada, Smush, Optimole, a3 Lazy Load and WP Rocket all
		// do. Telling the owner of such a site that 145 images "load immediately" would be
		// advice to fix something their theme already handles.
		//
		// This is deliberately the SAME set of markers wpoptimizing_optimize_images_html() skips
		// when it declines to touch an image: the diagnostic and the fix must agree about what
		// counts as already handled, or the report asks for something the optimiser then
		// refuses to do.
		$native = (bool) preg_match( '/\sloading\s*=\s*["\']?(?:lazy|eager)\b/i', $tag );
		$third  = (bool) preg_match( '/\sdata-(?:src|lazy-src|lazysrc|lazyload|orig-src)\s*=/i', $tag )
			|| (bool) preg_match( '/\sclass\s*=\s*["\'][^"\']*\b(?:lazy|lazyload|lazyloaded)\b/i', $tag );
		if ( ! $native && ! $third ) {
			++$out['no_lazy'];
		}
		if ( ! preg_match( '/\ssrcset\s*=\s*["\'][^"\']+["\']/i', $tag ) ) {
			++$out['no_srcset'];
		}
		if ( ! preg_match( '/\salt\s*=/i', $tag ) ) {
			++$out['no_alt'];
		}
	}

	$out['ok'] = true;
	return $out;
}

/**
 * Thresholds. One place, so the finding text and any future UI cannot disagree.
 *
 * @return array{elements_warn:int,elements_bad:int,share:float,min_images:int,inline_styles:int}
 */
function wpoptimizing_markup_thresholds() {
	return array(
		// Lighthouse warns at 1,400 body elements and fails at 2,500. Matching those numbers
		// means our advice and the score the user is chasing agree.
		'elements_warn' => 1400,
		'elements_bad'  => 2500,
		// A couple of stragglers on a big gallery is not a finding; a third of the page is.
		'share'         => 0.33,
		'min_images'    => 4,
		'inline_styles' => 150,
	);
}

/**
 * Turn a measurement into at most one finding per problem, worst first.
 *
 * Pure. Each finding is {key, severity, count, total} — the wording lives in the admin layer
 * so it can be translated where every other string is.
 *
 * @param array $m Measurement from wpoptimizing_markup_measure().
 * @return array<int,array{key:string,severity:string,count:int,total:int}>
 */
function wpoptimizing_markup_findings( array $m ) {
	if ( empty( $m['ok'] ) ) {
		return array();
	}
	$t   = wpoptimizing_markup_thresholds();
	$out = array();

	$elements = (int) ( $m['elements'] ?? 0 );
	if ( $elements >= $t['elements_warn'] ) {
		$out[] = array(
			'key'      => 'dom_size',
			'severity' => ( $elements >= $t['elements_bad'] ) ? 'high' : 'medium',
			'count'    => $elements,
			'total'    => $elements,
		);
	}

	$images = (int) ( $m['images'] ?? 0 );
	if ( $images >= $t['min_images'] ) {
		// Ordered by what the visitor notices: the page jumping, then it being slow to
		// settle, then a phone downloading a desktop image.
		foreach ( array( 'no_dimensions', 'no_lazy', 'no_srcset' ) as $key ) {
			$n = (int) ( $m[ $key ] ?? 0 );
			if ( $n > 0 && ( $n / $images ) >= $t['share'] ) {
				$out[] = array(
					'key'      => 'images_' . $key,
					'severity' => ( $n === $images ) ? 'high' : 'medium',
					'count'    => $n,
					'total'    => $images,
				);
			}
		}
	}

	$inline = (int) ( $m['inline_styles'] ?? 0 );
	if ( $inline >= $t['inline_styles'] ) {
		$out[] = array(
			'key'      => 'inline_styles',
			'severity' => 'medium',
			'count'    => $inline,
			'total'    => $elements,
		);
	}

	// High before medium, and stable within a severity so the list does not reshuffle between
	// two runs that found the same things. usort() only became stable in PHP 8.0 and this
	// plugin supports 7.4, so the original position is part of the comparison rather than
	// something we hope the sort preserves.
	$rank  = array(
		'high'   => 0,
		'medium' => 1,
	);
	$keyed = array();
	foreach ( $out as $i => $f ) {
		$keyed[] = array( $rank[ $f['severity'] ] ?? 9, $i, $f );
	}
	usort(
		$keyed,
		static function ( $a, $b ) {
			return ( $a[0] === $b[0] ) ? ( $a[1] <=> $b[1] ) : ( $a[0] <=> $b[0] );
		}
	);
	return array_column( $keyed, 2 );
}

/**
 * Measure the public home page and remember the result.
 *
 * One loopback request, made from an admin/cron context only. The page is fetched the way an
 * anonymous visitor gets it — no cookies — so what is measured is what the public sees, after
 * this plugin's own image optimisation and any page cache have had their say.
 *
 * Failure is not an error state worth surfacing: a site behind HTTP auth, a firewall that
 * blocks loopbacks or a home page that redirects simply leaves the previous result in place
 * and the report says nothing rather than something wrong.
 *
 * @param string $url Optional URL. Defaults to the home page.
 * @return array Measurement, or the stored one on failure.
 */
function wpoptimizing_markup_audit_run( $url = '' ) {
	$url  = ( '' !== (string) $url ) ? (string) $url : home_url( '/' );
	$resp = wp_remote_get(
		$url,
		array(
			'timeout'     => 12,
			// A request to this same site: WordPress's own rule for loopbacks, not a blanket false.
			'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
			'redirection' => 3,
			// Same UA the Google Fonts discovery uses: some hosts serve a different page to
			// an unknown client, and a measurement of that page would be a measurement of
			// nothing the visitor sees.
			'user-agent'  => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
		)
	);
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return wpoptimizing_markup_audit_get();
	}

	$m = wpoptimizing_markup_measure( (string) wp_remote_retrieve_body( $resp ) );
	if ( empty( $m['ok'] ) ) {
		return wpoptimizing_markup_audit_get();
	}

	$m['url']         = $url;
	$m['measured_at'] = time();
	update_option( 'wpoptimizing_markup_audit', $m, false );
	return $m;
}

/**
 * The last good measurement, or an empty one.
 *
 * @return array
 */
function wpoptimizing_markup_audit_get() {
	$stored = get_option( 'wpoptimizing_markup_audit', array() );
	if ( ! is_array( $stored ) || empty( $stored['ok'] ) ) {
		return wpoptimizing_markup_measure( '' );
	}
	return $stored;
}
