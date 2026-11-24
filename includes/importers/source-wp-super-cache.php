<?php
/**
 * Import source: WP Super Cache.
 *
 * Its configuration is PHP: wp-content/wp-cache-config.php (WPCACHECONFIGPATH, wp-cache.php:89-93)
 * holds global assignments (`$cache_max_time = 1800;`) that the plugin rewrites line by line
 * (wp_cache_replace_line(), wp_cache_setting() in wp-cache-phase2.php:1680). It is never
 * included from here: it may not be WPSC's any more, it defines constants and it runs code. The
 * tokenizer reads only `$var = <literal>;` and `$var[ 'key' ] = <literal>;`, where the literal is
 * a scalar or a literal array (var_export() writes `array ( 0 => '…', )`); anything else, such as
 * `$cache_path = WP_CONTENT_DIR . '/cache/';`, is skipped.
 *
 * The CDN («CDN» tab, OSSDL) lives in per-site options `ossdl_*` (ossdl-cdn.php:26-82) plus
 * `$ossdlcdn` in the file. Keys read in WP Super Cache 3.1.5
 * (docs/cache-2026-11-06/importadores-A.md §2). WPSC has no credentials.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The literal assignments of a wp-cache-config.php, without running it. Pure.
 *
 * @param string $php File contents.
 * @return array Variable name (without `$`) => value; `name[key]` assignments become arrays.
 */
function wpoptimizing_import_src_wp_super_cache_parse( $php ) {
	if ( ! function_exists( 'token_get_all' ) ) {
		return array();
	}
	$tokens = array();
	foreach ( token_get_all( (string) $php ) as $t ) {
		if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML ), true ) ) {
			continue;
		}
		$tokens[] = $t;
	}
	$out   = array();
	$count = count( $tokens );
	$prev  = ';';
	for ( $i = 0; $i < $count; $i++ ) {
		$t     = $tokens[ $i ];
		$start = in_array( $prev, array( ';', '{', '}' ), true );
		$prev  = is_array( $t ) ? '' : $t;
		if ( ! $start || ! is_array( $t ) || T_VARIABLE !== $t[0] ) {
			continue;
		}
		$name = substr( $t[1], 1 );
		$j    = $i + 1;
		$key  = null;
		if ( '[' === ( $tokens[ $j ] ?? '' ) ) {
			$k = wpoptimizing_import_src_wp_super_cache_literal( $tokens, $j + 1 );
			if ( null === $k || ']' !== ( $tokens[ $k[1] ] ?? '' ) || ! is_scalar( $k[0] ) ) {
				continue;
			}
			$key = (string) $k[0];
			$j   = $k[1] + 1;
		}
		if ( '=' !== ( $tokens[ $j ] ?? '' ) ) {
			continue;
		}
		$v = wpoptimizing_import_src_wp_super_cache_literal( $tokens, $j + 1 );
		if ( null === $v || ';' !== ( $tokens[ $v[1] ] ?? '' ) ) {
			continue;
		}
		if ( null === $key ) {
			$out[ $name ] = $v[0];
		} else {
			$out[ $name ]         = is_array( $out[ $name ] ?? null ) ? $out[ $name ] : array();
			$out[ $name ][ $key ] = $v[0];
		}
		$i    = $v[1];
		$prev = ';';
	}
	return $out;
}

/**
 * One literal starting at $tokens[$i]. Pure.
 *
 * @param array $tokens Tokens without whitespace or comments.
 * @param int   $i      Position.
 * @return array{0:mixed,1:int}|null Value and the position right after it; null when it is not a literal.
 */
function wpoptimizing_import_src_wp_super_cache_literal( array $tokens, $i ) {
	$t   = $tokens[ $i ] ?? null;
	$neg = false;
	if ( '-' === $t ) {
		$neg = true;
		$t   = $tokens[ ++$i ] ?? null;
	}
	if ( is_array( $t ) && in_array( $t[0], array( T_LNUMBER, T_DNUMBER ), true ) ) {
		$n = T_LNUMBER === $t[0] ? (int) $t[1] : (float) $t[1];
		return array( $neg ? -$n : $n, $i + 1 );
	}
	if ( $neg ) {
		return null;
	}
	if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
		$s = substr( $t[1], 1, -1 );
		$s = "'" === $t[1][0] ? str_replace( array( '\\\\', "\\'" ), array( '\\', "'" ), $s ) : stripcslashes( $s );
		return array( $s, $i + 1 );
	}
	if ( is_array( $t ) && T_STRING === $t[0] ) {
		$word = strtolower( $t[1] );
		$map  = array(
			'true'  => true,
			'false' => false,
			'null'  => null,
		);
		return array_key_exists( $word, $map ) ? array( $map[ $word ], $i + 1 ) : null;
	}
	$close = null;
	if ( is_array( $t ) && T_ARRAY === $t[0] && '(' === ( $tokens[ $i + 1 ] ?? '' ) ) {
		$close = ')';
		$i    += 2;
	} elseif ( '[' === $t ) {
		$close = ']';
		++$i;
	}
	if ( null === $close ) {
		return null;
	}
	$arr = array();
	while ( isset( $tokens[ $i ] ) && $tokens[ $i ] !== $close ) {
		$item = wpoptimizing_import_src_wp_super_cache_literal( $tokens, $i );
		if ( null === $item ) {
			return null;
		}
		$i = $item[1];
		if ( is_array( $tokens[ $i ] ?? null ) && T_DOUBLE_ARROW === $tokens[ $i ][0] ) {
			$val = wpoptimizing_import_src_wp_super_cache_literal( $tokens, $i + 1 );
			if ( null === $val || ! is_scalar( $item[0] ) ) {
				return null;
			}
			$arr[ $item[0] ] = $val[0];
			$i               = $val[1];
		} else {
			$arr[] = $item[0];
		}
		if ( ',' === ( $tokens[ $i ] ?? '' ) ) {
			++$i;
		} elseif ( ! isset( $tokens[ $i ] ) || $tokens[ $i ] !== $close ) {
			return null;
		}
	}
	return isset( $tokens[ $i ] ) ? array( $arr, $i + 1 ) : null;
}

/**
 * The live configuration: the file, plus the CDN options of this site.
 *
 * @return array|null
 */
function wpoptimizing_import_src_wp_super_cache_read() {
	$dir  = defined( 'WPCACHECONFIGPATH' ) ? (string) WPCACHECONFIGPATH : WP_CONTENT_DIR;
	$file = $dir . '/wp-cache-config.php';
	$raw  = array();
	if ( is_readable( $file ) ) {
		$raw['config'] = wpoptimizing_import_src_wp_super_cache_parse( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config file read as tokens, never executed.
	}
	if ( empty( $raw['config'] ) ) {
		return null;
	}
	foreach ( array( 'ossdl_off_cdn_url', 'ossdl_off_include_dirs', 'ossdl_off_exclude', 'ossdl_cname', 'ossdl_https' ) as $option ) {
		$value = get_option( $option );
		if ( is_scalar( $value ) && '' !== (string) $value ) {
			$raw['cdn'][ $option ] = (string) $value;
		}
	}
	$raw['site_url'] = (string) get_option( 'siteurl' );
	return $raw;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $raw `config` (the parsed file), `cdn` (ossdl_* options), `site_url`.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_wp_super_cache_map( array $raw ) {
	$c     = array();
	$notes = array();
	$r     = is_array( $raw['config'] ?? null ) ? $raw['config'] : array();
	$on    = static function ( $key ) use ( $r ) {
		return ! empty( $r[ $key ] );
	};
	$list  = static function ( $key ) use ( $r ) {
		$value = $r[ $key ] ?? array();
		$value = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_filter( (array) $value, 'is_scalar' ) ), 'strlen' ) );
	};

	// Page cache. The values may be written quoted ('1800'), so everything is cast.
	if ( isset( $r['cache_max_time'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $r['cache_max_time'], $notes ), __( 'Cache Timeout', 'wpoptimizing' ) );
	}
	// Its two default rules (wp-.*\.php, index\.php) are files our engine never caches anyway.
	$uri = wpoptimizing_importer_patterns( array_diff( $list( 'cache_rejected_uri' ), array( 'wp-.*\.php', 'index\.php' ) ), __( 'Rejected URL Strings', 'wpoptimizing' ), $notes );
	if ( $uri ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Rejected URL Strings', 'wpoptimizing' ) );
	}
	$cookies = wpoptimizing_importer_patterns( $list( 'wpsc_rejected_cookies' ), __( 'Rejected Cookies', 'wpoptimizing' ), $notes );
	if ( $cookies ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $cookies, __( 'Rejected Cookies', 'wpoptimizing' ) );
	}
	$ua = wpoptimizing_importer_patterns( $list( 'cache_rejected_user_agent' ), __( 'Rejected User Agents', 'wpoptimizing' ), $notes );
	if ( $ua ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'Rejected User Agents', 'wpoptimizing' ) );
	}
	if ( $on( 'wpsc_ignore_tracking_parameters' ) ) {
		$qs = wpoptimizing_importer_patterns( $list( 'wpsc_tracking_parameters' ), __( 'Tracking Parameters', 'wpoptimizing' ), $notes );
		if ( $qs ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ignored_query', $qs, __( 'Tracking Parameters', 'wpoptimizing' ) );
		}
	}
	if ( isset( $r['cache_compression'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'gzip', $on( 'cache_compression' ), __( 'Compress pages so they’re served more quickly to visitors.', 'wpoptimizing' ) );
	}
	if ( isset( $r['wp_cache_mobile_enabled'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'wp_cache_mobile_enabled' ), __( 'Mobile device support', 'wpoptimizing' ) );
	}
	if ( isset( $r['wp_cache_preload_on'] ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( 'wp_cache_preload_on' ), __( 'Preload mode', 'wpoptimizing' ) );
	}
	// 0 caches everybody, 1 nobody with a cookie, 2 (recommended) nobody logged in. Not tied to
	// $cache_enabled: deactivating WPSC writes it to false (inc/lifecycle.php:84).
	if ( isset( $r['wp_cache_not_logged_in'] ) && 2 !== (int) $r['wp_cache_not_logged_in'] ) {
		$notes[] = wpoptimizing_importer_note(
			'review',
			0 === (int) $r['wp_cache_not_logged_in']
				/* translators: %s: the other plugin's setting. */
				? sprintf( __( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Enable caching for all visitors.', 'wpoptimizing' ) )
				: __( 'Disable caching for visitors who have a cookie set in their browser: WPOptimizing does cache visitors with ordinary cookies, and skips only logged-in users and the cookies you list.', 'wpoptimizing' )
		);
	}
	$skipped = array();
	foreach ( (array) ( $r['wp_cache_pages'] ?? array() ) as $type => $off ) {
		if ( ! empty( $off ) ) {
			$skipped[] = (string) $type;
		}
	}
	if ( $skipped ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			sprintf(
				/* translators: %s: list of page types (search, feed, category…). */
				__( 'Do not cache the following page types (%s): not imported; WPOptimizing cannot leave out whole page types. Add their addresses to the URLs that are never cached if you need to.', 'wpoptimizing' ),
				implode( ', ', $skipped )
			)
		);
	}
	// Deactivating WPSC removes its .htaccess block only when it can write the file
	// (inc/lifecycle.php:680-683); otherwise it just e-mails the administrator.
	if ( $on( 'wp_cache_mod_rewrite' ) ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'Cache Delivery Method «Expert»: WP Super Cache serves pages straight from rules in .htaccess. After deactivating it, check that the block between «BEGIN WPSuperCache» and «END WPSuperCache» is gone, or visitors will keep getting its old pages.', 'wpoptimizing' ) );
	}

	// CDN: one host here.
	$cdn = is_array( $raw['cdn'] ?? null ) ? $raw['cdn'] : array();
	$url = trim( (string) ( $cdn['ossdl_off_cdn_url'] ?? '' ) );
	$own = trim( (string) ( $raw['site_url'] ?? '' ) );
	if ( $on( 'ossdlcdn' ) && '' !== $url && untrailingslashit( $url ) !== untrailingslashit( $own ) ) {
		// Folders relative to the site (content and includes by default, ossdl-cdn.php:54-60,
		// :91-103) and words that keep a file off the CDN (.php by default, :62-68, :159), which
		// ours never rewrites anyway. A folder ours has no scope for, another exclusion, or
		// «Skip https URLs» cannot be kept: then it is not switched on.
		$dirs = wpoptimizing_importer_cdn_dirs( array_filter( array_map( 'trim', explode( ',', (string) ( $cdn['ossdl_off_include_dirs'] ?? 'wp-content,wp-includes' ) ) ), 'strlen' ) );
		$excl = array_diff( array_filter( array_map( 'trim', explode( ',', (string) ( $cdn['ossdl_off_exclude'] ?? '.php' ) ) ), 'strlen' ), array( '.php' ) );
		if ( null === $dirs || $excl || ! empty( $cdn['ossdl_https'] ) ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'Enable CDN Support', 'wpoptimizing' ), $url );
			$dirs    = null;
		} else {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'Enable CDN Support', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', $url, __( 'Off-site URL', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'dirs', $dirs, __( 'Include directories', 'wpoptimizing' ) );
		}
		if ( null !== $dirs && '' !== trim( (string) ( $cdn['ossdl_cname'] ?? '' ) ) ) {
			$notes[] = wpoptimizing_importer_note(
				'review',
				/* translators: %s: the other plugin's setting. */
				sprintf( __( '%s: WPOptimizing uses one CDN host, so only the first one was imported.', 'wpoptimizing' ), __( 'Additional CNAMES', 'wpoptimizing' ) )
			);
		}
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
