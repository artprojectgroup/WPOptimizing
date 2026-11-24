<?php
/**
 * Pure content-cleanup helpers for safe, surgical deletion of certain entity
 * types (e.g. removing a specific shortcode from post content without touching
 * the rest). Side-effect free so the logic is unit-tested offline.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove every occurrence of a specific shortcode from a content string —
 * including its inner content and closing tag, leaving no leftover markup.
 *
 * Handles enclosing (`[tag]…[/tag]`), self-closing (`[tag /]`) and standalone
 * (`[tag]`) forms, plus orphaned closing tags. Other shortcodes are untouched.
 *
 * @param string $content Content (e.g. post_content).
 * @param string $tag     Shortcode tag (no brackets).
 * @return string Cleaned content.
 */
function wpoptimizing_strip_shortcode( $content, $tag ) {
	$content = (string) $content;
	$tag     = trim( (string) $tag );
	if ( '' === $tag || '' === $content || false === strpos( $content, '[' ) ) {
		return $content;
	}
	$t = preg_quote( $tag, '#' );
	// The tag ends where WordPress' own shortcode regex says it ends: not before a word
	// character or a hyphen. "\b" stopped at the hyphen, so deleting "contact" also stripped
	// every [contact-form-7 …] and the text after it up to the next [/contact].
	$b = '(?![\w-])';

	// 1) Enclosing form with its inner content: [tag attrs]…[/tag] (a self-closing [tag /]
	// is not the opening of an enclosing one).
	$content = (string) preg_replace( '#\[' . $t . $b . '[^\]]*(?<!/)\][\s\S]*?\[\/' . $t . '\]#i', '', $content );
	// 2) Self-closing / standalone opening: [tag attrs /] or [tag attrs].
	$content = (string) preg_replace( '#\[' . $t . $b . '[^\]]*\]#i', '', $content );
	// 3) Any orphaned closing tag left by unusual nesting.
	$content = (string) preg_replace( '#\[\/' . $t . '\]#i', '', $content );
	// 4) Tidy up the whitespace the removal may leave behind.
	$content = (string) preg_replace( "#[ \t]+\n#", "\n", $content );
	$content = (string) preg_replace( "#\n{3,}#", "\n\n", $content );

	return trim( $content );
}

/**
 * Whether a content string contains a given shortcode (cheap pre-check).
 *
 * @param string $content Content.
 * @param string $tag     Shortcode tag.
 * @return bool
 */
function wpoptimizing_content_has_shortcode( $content, $tag ) {
	$tag = trim( (string) $tag );
	if ( '' === $tag ) {
		return false;
	}
	return (bool) preg_match( '#\[' . preg_quote( $tag, '#' ) . '(?![\w-])[^\]]*\]#i', (string) $content );
}
