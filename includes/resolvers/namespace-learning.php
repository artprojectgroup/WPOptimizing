<?php
/**
 * A plugin's own namespace, learned from what the scan already attributed to it.
 *
 * Attribution by name only knew a plugin's folder slug: WPML is sitepress-multilingual-cms, and
 * none of its keys say so — icl_*, WPML(setup), wpml_*, otgs_*. On wpoptimizing.com about 150 of
 * its options sat in Pending with no owner while WPML was running the site (14/10/2026); the same
 * gap left tables such as cmplz_cookies or icl_links_post_to_post ownerless.
 *
 * But the scan does attribute some of those keys with evidence (code, live capture, the shared
 * base). Their leading token is the plugin's namespace: when every attributed key with a token
 * belongs to one installed extension, an unattributed key with the same token is very likely
 * its too. Learned per site from its own data, so there is no plugin list to keep.
 *
 * Only ever "Estimated" (heuristic) confidence: it decides the tab a row is shown in, never
 * whether it may be deleted.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The leading token of a key: lowercased, without transient wrappers, the table prefix or
 * leading underscores, cut at the first separator (_ - ( # : . / space).
 *
 * @param string $value        Key (option name, meta key, table name…).
 * @param string $table_prefix This site's table prefix (user meta and tables may carry it).
 * @return string '' when the token is too short or too generic to mean an owner.
 */
function wpoptimizing_namespace_token( $value, $table_prefix = '' ) {
	$v = strtolower( trim( (string) $value ) );
	foreach ( array( '_site_transient_timeout_', '_site_transient_', '_transient_timeout_', '_transient_' ) as $w ) {
		if ( 0 === strpos( $v, $w ) ) {
			$v = substr( $v, strlen( $w ) );
			break;
		}
	}
	$v  = ltrim( $v, '_' );
	$tp = strtolower( (string) $table_prefix );
	if ( '' !== $tp && 'wp_' !== $tp && 0 === strpos( $v, $tp ) ) {
		$v = ltrim( substr( $v, strlen( $tp ) ), '_' );
	}
	$token = (string) preg_split( '/[_\-(#:.\/\s]/', $v, 2 )[0];
	if ( strlen( $token ) < 3 || in_array( $token, wpoptimizing_namespace_generic_tokens(), true ) ) {
		return '';
	}
	return $token;
}

/**
 * Tokens that many unrelated plugins use, so they never name an owner.
 *
 * @return string[]
 */
function wpoptimizing_namespace_generic_tokens() {
	return (array) apply_filters(
		'wpoptimizing_namespace_generic_tokens',
		array( 'wp', 'wpo', 'the', 'site', 'user', 'users', 'post', 'posts', 'page', 'term', 'terms', 'theme', 'widget', 'option', 'options', 'default', 'settings', 'setting', 'cache', 'data', 'admin', 'custom', 'meta', 'plugin', 'plugins', 'auto', 'can', 'last', 'new', 'old', 'temp', 'tmp', 'test', 'log', 'logs', 'api', 'app', 'woo', 'seo', 'pro', 'lite', 'free', 'my', 'your', 'show', 'hide', 'enable', 'disable', 'dismissed', 'notice', 'notices', 'version', 'db', 'cron', 'rest', 'block', 'blocks', 'nav', 'menu', 'form', 'forms', 'mail', 'email', 'social', 'google', 'facebook', 'image', 'images', 'media', 'video', 'license', 'core', 'wordpress', 'session', 'sessions', 'stats', 'analytics', 'backup', 'security', 'shop', 'product', 'products', 'order', 'orders', 'cart', 'checkout', 'shipping', 'billing', 'payment', 'payments', 'tax', 'edit', 'delete', 'manage', 'read', 'date', 'attribute', 'attributes', 'zzz' )
	);
}

/**
 * Learn token => owners from attributed keys.
 *
 * A token may belong to a family: WPML's wpml_* and icl_* keys are attributed to its core,
 * String Translation and WooCommerce Multilingual alike, so "one slug per token" learned nothing
 * for the very plugin this exists for (wpoptimizing.com, 14/10/2026). Up to four owners are
 * kept, most frequent first (Fusion: fusion-core, fusion-builder, Avada and its child); more than
 * that is a generic word, not a namespace. The caller only
 * feeds keys owned by installed extensions, so every owner returned is installed here.
 *
 * @param array<int,array{0:string,1:string}> $pairs        [key, owner slug] of attributed rows.
 * @param string                              $table_prefix Site table prefix.
 * @param int                                 $min          Keys needed before a token counts.
 * @return array<string,string> token => "slug" or "slug|slug2|slug3", most frequent first.
 */
function wpoptimizing_namespace_learn( array $pairs, $table_prefix = '', $min = 3 ) {
	$seen = array();
	foreach ( $pairs as $p ) {
		$token = wpoptimizing_namespace_token( (string) ( $p[0] ?? '' ), $table_prefix );
		if ( '' === $token ) {
			continue;
		}
		foreach ( array_filter( array_map( 'trim', explode( '|', strtolower( (string) ( $p[1] ?? '' ) ) ) ) ) as $slug ) {
			if ( false === strpbrk( $slug, ', ;' ) ) {
				$seen[ $token ][ $slug ] = ( $seen[ $token ][ $slug ] ?? 0 ) + 1;
			}
		}
	}
	$out = array();
	foreach ( $seen as $token => $slugs ) {
		if ( count( $slugs ) > 4 || array_sum( $slugs ) < $min ) {
			continue;
		}
		arsort( $slugs );
		$out[ $token ] = implode( '|', array_keys( $slugs ) );
	}
	return $out;
}

/**
 * The key's token names an installed extension outright once separators are dropped:
 * EnableMediaReplace-notices → enable-media-replace. Only for long, specific names.
 *
 * @param string   $token Token from wpoptimizing_namespace_token().
 * @param string[] $slugs Installed slugs/aliases.
 * @return string The slug, or ''.
 */
function wpoptimizing_namespace_slug_by_name( $token, array $slugs ) {
	if ( strlen( (string) $token ) < 8 ) {
		return '';
	}
	foreach ( $slugs as $slug ) {
		if ( str_replace( array( '-', '_' ), '', strtolower( (string) $slug ) ) === $token ) {
			return (string) $slug;
		}
	}
	return '';
}
