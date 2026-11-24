<?php
/**
 * Per-language copies of a key: the same owner as the key they copy.
 *
 * A multilingual site keeps one copy of some keys per language. Avada stores its options as
 * fusion_options_{lang} (and fusion_options_ for «all languages»), WPML keeps the term
 * hierarchy of every taxonomy as {taxonomy}_children_{lang}. None of those names is written
 * literally in any code, so no scan finds them: on wpoptimizing.com (9 languages) they all sat
 * in Pending while fusion_options and nav_menu_children themselves were attributed (27/10/2026).
 *
 * Only this site's own languages count as a suffix, never any two letters: `foo_de` is a copy
 * of `foo` only where German is one of the site's languages.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The language suffixes of this site, lowercase with a dash: `es`, `pt-pt`, `zh-cn`, `en-us`.
 *
 * WPML's and Polylang's languages (code and locale), the installed translations and the site
 * locale. WPML's codes are not always locales (`pt-pt`, `zh-hans`), and plugins that copy a key
 * per language use either one, so both are kept.
 *
 * @return string[]
 */
function wpoptimizing_site_language_suffixes() {
	static $memo = null;
	if ( null !== $memo ) {
		return $memo;
	}
	$raw = array();
	if ( function_exists( 'get_locale' ) ) {
		$raw[] = get_locale();
	}
	if ( function_exists( 'get_available_languages' ) ) {
		$raw = array_merge( $raw, (array) get_available_languages() );
	}
	$wpml = function_exists( 'apply_filters' ) ? apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) : null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API hook.
	if ( is_array( $wpml ) ) {
		foreach ( $wpml as $code => $lang ) {
			$raw[] = (string) $code;
			$raw[] = is_array( $lang ) ? (string) ( $lang['default_locale'] ?? '' ) : '';
		}
	}
	// WPML also keeps an «all languages» copy: "{$taxonomy}_children_all" (WPML_Term_Filters).
	if ( is_array( $wpml ) ) {
		$raw[] = 'all';
	}
	if ( function_exists( 'pll_languages_list' ) ) {
		$raw = array_merge( $raw, (array) pll_languages_list( array( 'fields' => 'slug' ) ), (array) pll_languages_list( array( 'fields' => 'locale' ) ) );
	}
	$memo = wpoptimizing_language_suffixes_normalize( $raw );
	return $memo;
}

/**
 * Normalizes language codes and locales to suffixes: lowercase, `_` → `-`, and the bare
 * language of every locale too (`en_US` gives `en-us` and `en`). Pure.
 *
 * @param string[] $raw Codes and locales.
 * @return string[] Longest first, so `pt-pt` is tried before `pt`.
 */
function wpoptimizing_language_suffixes_normalize( array $raw ) {
	$out = array();
	foreach ( $raw as $code ) {
		$code = strtolower( str_replace( '_', '-', trim( (string) $code ) ) );
		if ( ! preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $code ) ) {
			continue;
		}
		$out[ $code ] = true;
		$out[ strtok( $code, '-' ) ] = true;
	}
	$out = array_keys( $out );
	usort(
		$out,
		static function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	return $out;
}

/**
 * The key a per-language copy copies, or null. Pure.
 *
 * `fusion_options_ar` → `fusion_options` (language `ar`); `fusion_options_` → `fusion_options`
 * (language empty: the «all languages» copy). The suffix is matched in both separators the
 * plugins use with it, `_es` and `_en-us`.
 *
 * @param string   $value Key, lowercase.
 * @param string[] $langs Suffixes from wpoptimizing_site_language_suffixes().
 * @return array{base:string,lang:string}|null
 */
function wpoptimizing_language_variant_base( $value, array $langs ) {
	$value = (string) $value;
	foreach ( $langs as $lang ) {
		$cola = '_' . $lang;
		if ( strlen( $value ) > strlen( $cola ) + 1 && substr( $value, -strlen( $cola ) ) === $cola ) {
			return array(
				'base' => substr( $value, 0, -strlen( $cola ) ),
				'lang' => $lang,
			);
		}
	}
	if ( strlen( $value ) > 2 && '_' === substr( $value, -1 ) && '_' !== substr( $value, -2, 1 ) ) {
		return array(
			'base' => substr( $value, 0, -1 ),
			'lang' => '',
		);
	}
	return null;
}
