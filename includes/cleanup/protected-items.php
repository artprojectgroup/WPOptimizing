<?php
/**
 * Protected items: a user-curated allow-list of database elements that must NEVER
 * be deleted by the Impact cleaner, regardless of what the attribution heuristics
 * suggest. A hard safety net on top of the three attribution shields.
 *
 * Each entry is the SAME "type::value" string the deletion path receives (e.g.
 * "options::my_plugin_settings", "post_type::my_cpt", "meta_key::_my_meta"), so the
 * guard is a direct membership test on `wpoptimizing_can_delete_item` — no change to
 * the deletion routine itself.
 *
 * The normalize/contains helpers are pure (no WordPress calls) so they are unit-testable.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPT_PROTECTED_ITEMS_OPTION' ) ) {
	define( 'WPOPT_PROTECTED_ITEMS_OPTION', 'wpoptimizing_protected_items' );
}

if ( ! defined( 'WPOPT_PROTECTED_ITEMS_MAX' ) ) {
	// Generous cap so the option can never grow unbounded from repeated adds.
	define( 'WPOPT_PROTECTED_ITEMS_MAX', 2000 );
}

/**
 * Normalize a raw list of protected entries. Pure: trims, drops control chars,
 * keeps only well-formed "type::value" strings (both sides non-empty), removes
 * duplicates preserving first-seen order, and caps the total count.
 *
 * @param array $list Raw entries.
 * @return string[] Clean, de-duplicated, capped list.
 */
function wpoptimizing_protected_items_normalize( $list ) {
	$out  = array();
	$seen = array();
	foreach ( (array) $list as $raw ) {
		if ( is_array( $raw ) ) {
			continue;
		}
		$entry = trim( (string) $raw );
		// Drop ASCII control characters (newlines, tabs, NUL) that could come from a paste.
		$entry = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $entry );
		if ( '' === $entry || strlen( $entry ) > 512 || false === strpos( $entry, '::' ) ) {
			continue;
		}
		list( $type, $value ) = array_pad( explode( '::', $entry, 2 ), 2, '' );
		if ( '' === trim( $type ) || '' === trim( $value ) ) {
			continue;
		}
		if ( isset( $seen[ $entry ] ) ) {
			continue;
		}
		$seen[ $entry ] = true;
		$out[]          = $entry;
		if ( count( $out ) >= WPOPT_PROTECTED_ITEMS_MAX ) {
			break;
		}
	}
	return $out;
}

/**
 * Whether a single "type::value" entry is present in a (already normalized) set.
 * Pure: normalizes the entry the same way before comparing.
 *
 * @param string $entry "type::value" entry to test.
 * @param array  $set   The protected set.
 * @return bool
 */
function wpoptimizing_protected_items_contains( $entry, $set ) {
	$norm = wpoptimizing_protected_items_normalize( array( $entry ) );
	if ( empty( $norm ) ) {
		return false;
	}
	return in_array( $norm[0], (array) $set, true );
}

/**
 * The current protected set, normalized.
 *
 * @return string[]
 */
function wpoptimizing_protected_items_get() {
	return wpoptimizing_protected_items_normalize( (array) get_option( WPOPT_PROTECTED_ITEMS_OPTION, array() ) );
}

/**
 * Persist a protected set (normalized first). Stored non-autoloaded.
 *
 * @param array $list Entries.
 * @return bool
 */
function wpoptimizing_protected_items_set( $list ) {
	return update_option( WPOPT_PROTECTED_ITEMS_OPTION, wpoptimizing_protected_items_normalize( $list ), false );
}

/**
 * Whether a "type::value" entry is currently protected.
 *
 * @param string $entry Entry.
 * @return bool
 */
function wpoptimizing_item_is_protected( $entry ) {
	return wpoptimizing_protected_items_contains( $entry, wpoptimizing_protected_items_get() );
}

/**
 * Add an entry to the protected set. Sanitized to match the deletion path's own
 * input handling (sanitize_text_field) so the stored key compares exactly.
 *
 * @param string $entry "type::value" entry.
 * @return bool True when the set changed.
 */
function wpoptimizing_protected_items_add( $entry ) {
	if ( function_exists( 'sanitize_text_field' ) ) {
		$entry = sanitize_text_field( $entry );
	}
	$set = wpoptimizing_protected_items_get();
	if ( wpoptimizing_protected_items_contains( $entry, $set ) ) {
		return false; // Already protected.
	}
	$norm = wpoptimizing_protected_items_normalize( array_merge( $set, array( $entry ) ) );
	if ( $norm === $set ) {
		return false; // Malformed entry: nothing added.
	}
	return wpoptimizing_protected_items_set( $norm );
}

/**
 * Remove an entry from the protected set.
 *
 * @param string $entry "type::value" entry.
 * @return bool True when the set changed.
 */
function wpoptimizing_protected_items_remove( $entry ) {
	if ( function_exists( 'sanitize_text_field' ) ) {
		$entry = sanitize_text_field( $entry );
	}
	$set = wpoptimizing_protected_items_get();
	if ( ! wpoptimizing_protected_items_contains( $entry, $set ) ) {
		return false;
	}
	$norm = wpoptimizing_protected_items_normalize( array( $entry ) );
	$kept = array_values( array_diff( $set, $norm ) );
	return wpoptimizing_protected_items_set( $kept );
}

/**
 * Hard guard: veto deletion of any protected item. Hooks the existing
 * `wpoptimizing_can_delete_item` filter, so the deletion routine is untouched.
 * Runs early and never overrides an existing veto.
 *
 * @param bool   $allowed Whether deletion is currently allowed.
 * @param string $tipo    Item type.
 * @param string $valor   Item value.
 * @param string $entry   Raw "type::value" entry.
 * @return bool
 */
function wpoptimizing_protected_items_can_delete( $allowed, $tipo, $valor, $entry ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	if ( ! $allowed ) {
		return false; // Respect an earlier veto.
	}
	return wpoptimizing_item_is_protected( (string) $entry ) ? false : $allowed;
}
add_filter( 'wpoptimizing_can_delete_item', 'wpoptimizing_protected_items_can_delete', 5, 4 );
