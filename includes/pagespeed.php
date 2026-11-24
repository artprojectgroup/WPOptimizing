<?php
/**
 * PageSpeed Insights — fetch Google's PSI/Lighthouse score + Core Web Vitals for the site and
 * show them in the panel. Admin-initiated, read-only: the only outbound effect is one HTTPS
 * GET to the public PSI API with the site's (public) URL. The JSON parser is pure/unit-tested;
 * the runner wraps the HTTP call.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

const WPOPTIMIZING_PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

/**
 * Parse a PSI v5 API response into a compact result. Pure (unit-tested).
 *
 * @param array $data Decoded PSI JSON.
 * @return array{score:?int,lab:array<string,string>,field:array<string,mixed>}
 */
function wpoptimizing_psi_parse( array $data ) {
	$out = array(
		'score' => null,
		'lab'   => array(),
		'field' => array(),
	);

	// Overall performance score (0..1 → 0..100).
	$score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
	if ( is_numeric( $score ) ) {
		$out['score'] = (int) round( (float) $score * 100 );
	}

	// Lab metrics (Lighthouse audits) — keep the human displayValue.
	$audits = isset( $data['lighthouseResult']['audits'] ) && is_array( $data['lighthouseResult']['audits'] )
		? $data['lighthouseResult']['audits'] : array();
	$map    = array(
		'lcp' => 'largest-contentful-paint',
		'fcp' => 'first-contentful-paint',
		'cls' => 'cumulative-layout-shift',
		'tbt' => 'total-blocking-time',
		'si'  => 'speed-index',
		'tti' => 'interactive',
	);
	foreach ( $map as $key => $audit ) {
		if ( isset( $audits[ $audit ]['displayValue'] ) && '' !== (string) $audits[ $audit ]['displayValue'] ) {
			$out['lab'][ $key ] = (string) $audits[ $audit ]['displayValue'];
		}
	}

	// Field data (CrUX) when available — real-user metric percentile + category.
	$metrics = isset( $data['loadingExperience']['metrics'] ) && is_array( $data['loadingExperience']['metrics'] )
		? $data['loadingExperience']['metrics'] : array();
	$fmap    = array(
		'lcp' => 'LARGEST_CONTENTFUL_PAINT_MS',
		'cls' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
		'inp' => 'INTERACTION_TO_NEXT_PAINT',
		'fcp' => 'FIRST_CONTENTFUL_PAINT_MS',
	);
	foreach ( $fmap as $key => $metric ) {
		if ( isset( $metrics[ $metric ]['percentile'] ) ) {
			$out['field'][ $key ] = array(
				'value'    => (int) $metrics[ $metric ]['percentile'],
				'category' => isset( $metrics[ $metric ]['category'] ) ? (string) $metrics[ $metric ]['category'] : '',
			);
		}
	}
	if ( isset( $data['loadingExperience']['overall_category'] ) ) {
		$out['field']['overall'] = (string) $data['loadingExperience']['overall_category'];
	}

	// Raw numbers too, for the history (the display strings are localised by Google).
	$out['num'] = array();
	foreach ( array( 'lcp' => 'largest-contentful-paint', 'cls' => 'cumulative-layout-shift', 'tbt' => 'total-blocking-time' ) as $key => $audit ) {
		if ( isset( $audits[ $audit ]['numericValue'] ) && is_numeric( $audits[ $audit ]['numericValue'] ) ) {
			$out['num'][ $key ] = round( (float) $audits[ $audit ]['numericValue'], 'cls' === $key ? 3 : 0 );
		}
	}

	// Which element was the LCP. Lighthouse 10+ nests it in a list of tables; older versions put
	// the node straight in the first item.
	$out['lcp_element'] = null;
	$items              = $audits['largest-contentful-paint-element']['details']['items'] ?? array();
	if ( is_array( $items ) && ! empty( $items ) ) {
		$first = $items[0];
		$node  = isset( $first['items'][0]['node'] ) ? $first['items'][0]['node'] : ( $first['node'] ?? null );
		if ( is_array( $node ) && ( ! empty( $node['snippet'] ) || ! empty( $node['selector'] ) ) ) {
			$snippet            = (string) ( $node['snippet'] ?? '' );
			$out['lcp_element'] = array(
				'snippet'  => substr( $snippet, 0, 300 ),
				'selector' => substr( (string) ( $node['selector'] ?? '' ), 0, 200 ),
				'image'    => preg_match( '/<img\b[^>]*\ssrc="([^"]+)"/i', $snippet, $m ) ? html_entity_decode( $m[1] ) : '',
			);
		}
	}
	return $out;
}

/**
 * Append a result to the test history (newest first, at most 30). Pure.
 *
 * @param array $history Current history.
 * @param array $result  Parsed result with url/strategy/ts.
 * @return array
 */
function wpoptimizing_psi_history_push( array $history, array $result ) {
	array_unshift(
		$history,
		array(
			'ts'       => (int) ( $result['ts'] ?? 0 ),
			'url'      => (string) ( $result['url'] ?? '' ),
			'page'     => (string) ( $result['page'] ?? '' ),
			'strategy' => (string) ( $result['strategy'] ?? 'mobile' ),
			'score'    => isset( $result['score'] ) ? (int) $result['score'] : null,
			'lcp'      => $result['num']['lcp'] ?? null,
			'cls'      => $result['num']['cls'] ?? null,
			'tbt'      => $result['num']['tbt'] ?? null,
			'element'  => isset( $result['lcp_element']['selector'] ) ? (string) $result['lcp_element']['selector'] : '',
		)
	);
	return array_slice( $history, 0, 30 );
}

/**
 * One URL for each kind of page the site has, so the LCP can be checked template by template:
 * the home page, the newest post, page and product, the shop and the busiest category.
 *
 * @return array<string,string> Key => URL, only the kinds that exist.
 */
function wpoptimizing_psi_template_urls() {
	$out   = array( 'home' => home_url( '/' ) );
	$front = (int) get_option( 'page_on_front' );
	foreach ( array( 'post' => 'post', 'page' => 'page', 'product' => 'product' ) as $key => $type ) {
		if ( ! post_type_exists( $type ) ) {
			continue;
		}
		$ids = get_posts(
			array(
				'post_type'    => $type,
				'post_status'  => 'publish',
				'has_password' => false,
				'numberposts'  => 1,
				'fields'       => 'ids',
				'exclude'      => $front > 0 ? array( $front ) : array(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- one id, one row.
			)
		);
		if ( ! empty( $ids ) ) {
			$out[ $key ] = (string) get_permalink( (int) $ids[0] );
		}
	}
	if ( function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ) {
		$out['shop'] = (string) get_permalink( wc_get_page_id( 'shop' ) );
	}
	$cats = get_terms(
		array(
			'taxonomy'   => 'category',
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => 1,
			'hide_empty' => true,
		)
	);
	if ( is_array( $cats ) && ! empty( $cats ) ) {
		$link = get_term_link( $cats[0] );
		if ( is_string( $link ) ) {
			$out['archive'] = $link;
		}
	}
	return array_filter( $out );
}

const WPOPTIMIZING_CRUX_HISTORY_ENDPOINT = 'https://chromeuxreport.googleapis.com/v1/records:queryHistoryRecord';

/**
 * Parse a CrUX History API response into p75 series per metric. Pure.
 *
 * @param array $data Decoded JSON.
 * @return array{periods:string[],metrics:array<string,array<int,float|null>>}
 */
function wpoptimizing_crux_history_parse( array $data ) {
	$out     = array(
		'periods' => array(),
		'metrics' => array(),
	);
	$periods = $data['record']['collectionPeriods'] ?? array();
	foreach ( (array) $periods as $p ) {
		$d                = $p['lastDate'] ?? array();
		$out['periods'][] = sprintf( '%04d-%02d-%02d', (int) ( $d['year'] ?? 0 ), (int) ( $d['month'] ?? 0 ), (int) ( $d['day'] ?? 0 ) );
	}
	$map = array(
		'lcp' => 'largest_contentful_paint',
		'inp' => 'interaction_to_next_paint',
		'cls' => 'cumulative_layout_shift',
	);
	foreach ( $map as $key => $metric ) {
		$p75s = $data['record']['metrics'][ $metric ]['percentilesTimeseries']['p75s'] ?? null;
		if ( is_array( $p75s ) ) {
			$out['metrics'][ $key ] = array_map(
				static function ( $v ) {
					return is_numeric( $v ) ? (float) $v : null;
				},
				$p75s
			);
		}
	}
	return $out;
}

/**
 * Fetch the origin's 25-week Chrome UX Report history (real visitors). Needs an API key with the
 * Chrome UX Report API enabled; stores and returns the parsed series.
 *
 * @return array|WP_Error
 */
function wpoptimizing_crux_history_run() {
	$key = wpoptimizing_psi_key();
	if ( '' === $key ) {
		return new WP_Error( 'wpopt_crux_key', __( 'The Chrome UX Report history needs a Google API key.', 'wpoptimizing' ) );
	}
	$origin   = untrailingslashit( (string) preg_replace( '#^(https?://[^/]+).*$#', '$1', home_url( '/' ) ) );
	$response = wp_remote_post(
		add_query_arg( 'key', rawurlencode( $key ), WPOPTIMIZING_CRUX_HISTORY_ENDPOINT ),
		array(
			'timeout' => 30,
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode(
				array(
					'origin'  => $origin,
					'metrics' => array( 'largest_contentful_paint', 'interaction_to_next_paint', 'cumulative_layout_shift' ),
				)
			),
		)
	);
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 404 === $code ) {
		return new WP_Error( 'wpopt_crux_none', __( 'Google has no Chrome UX Report data for this site yet: it needs enough real visits from Chrome.', 'wpoptimizing' ) );
	}
	if ( 200 !== $code || ! is_array( $body ) ) {
		$msg = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : sprintf( /* translators: %d: HTTP status code. */ __( 'The Chrome UX Report API returned HTTP %d.', 'wpoptimizing' ), $code );
		return new WP_Error( 'wpopt_crux_http', $msg );
	}
	$result       = wpoptimizing_crux_history_parse( $body );
	$result['ts'] = time();
	update_option( 'wpoptimizing_crux_history', $result, false );
	return $result;
}

/**
 * Map a 0..100 PSI score to a traffic-light grade. Pure.
 *
 * @param int|null $score Score.
 * @return string 'good'|'ok'|'poor'|'unknown'.
 */
function wpoptimizing_psi_grade( $score ) {
	if ( null === $score || ! is_numeric( $score ) ) {
		return 'unknown';
	}
	$score = (int) $score;
	if ( $score >= 90 ) {
		return 'good';
	}
	return ( $score >= 50 ) ? 'ok' : 'poor';
}

/**
 * Optional PSI API key (raises the keyless quota). Stored as a plain option.
 *
 * @return string
 */
function wpoptimizing_psi_key() {
	return (string) get_option( 'wpoptimizing_psi_key', '' );
}

/**
 * Run a PageSpeed Insights test for a URL and return the parsed result or a WP_Error. Wraps
 * the single outbound HTTPS GET; no side effects other than the request itself.
 *
 * @param string $url      URL to test (defaults to home_url()).
 * @param string $strategy 'mobile'|'desktop'.
 * @return array|WP_Error
 */
function wpoptimizing_psi_run( $url = '', $strategy = 'mobile' ) {
	$url      = '' !== (string) $url ? esc_url_raw( (string) $url ) : home_url( '/' );
	$strategy = ( 'desktop' === $strategy ) ? 'desktop' : 'mobile';
	if ( '' === $url ) {
		return new WP_Error( 'wpopt_psi_url', __( 'No URL to test.', 'wpoptimizing' ) );
	}
	$args = array(
		'url'      => $url,
		'strategy' => $strategy,
		'category' => 'performance',
	);
	$key  = wpoptimizing_psi_key();
	if ( '' !== $key ) {
		$args['key'] = $key;
	}
	$request  = add_query_arg( array_map( 'rawurlencode', $args ), WPOPTIMIZING_PSI_ENDPOINT );
	$response = wp_remote_get( $request, array( 'timeout' => 60 ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $code ) {
		$msg = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : sprintf( /* translators: %d: HTTP status code. */ __( 'PageSpeed Insights returned HTTP %d.', 'wpoptimizing' ), $code );
		return new WP_Error( 'wpopt_psi_http', $msg );
	}
	if ( ! is_array( $body ) ) {
		return new WP_Error( 'wpopt_psi_json', __( 'PageSpeed Insights returned an unreadable response.', 'wpoptimizing' ) );
	}
	$result             = wpoptimizing_psi_parse( $body );
	$result['url']      = $url;
	$result['strategy'] = $strategy;
	$result['ts']       = time();
	$result['page']     = (string) array_search( $url, wpoptimizing_psi_template_urls(), true );
	update_option( 'wpoptimizing_psi_result', $result, false );
	$history = get_option( 'wpoptimizing_psi_history', array() );
	update_option( 'wpoptimizing_psi_history', wpoptimizing_psi_history_push( is_array( $history ) ? $history : array(), $result ), false );
	return $result;
}
