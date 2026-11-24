<?php
/**
 * Smart Assets rules that no longer do anything.
 *
 * An unload rule names a handle. Handles come and go: a plugin is deactivated, a theme is
 * replaced, a developer renames an enqueue. The rule stays, silently matching nothing, and it
 * accumulates — Asset CleanUp added a whole screen for this, and they are right that it
 * matters, because a rule list nobody trusts is a rule list nobody prunes.
 *
 * WE CAN ANSWER IT BETTER THAN A NAME COMPARISON. The inventory records who owns each handle,
 * and the site knows which extensions are active, so "this rule points at nothing" and "this
 * rule points at something switched off" are different answers with different fixes: the
 * first is dead and can go, the second comes back the moment the plugin is reactivated and
 * must NOT be deleted for the user.
 *
 * Pure: takes the rules, the handles the inventory has seen, and the active owners. No
 * queries, no options.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classify one rule.
 *
 * @since 0.1.0
 * @param array               $rule          Sanitized rule ({handle, kind, scope}).
 * @param array<string,string> $handle_owner Handle => owner slug, from the inventory.
 * @param array<string,true>  $active_owners Owner slugs that are active right now.
 * @return string 'live', 'sleeping' (owner exists but is not active) or 'dead' (handle unknown).
 */
function wpoptimizing_assets_rule_state( array $rule, array $handle_owner, array $active_owners ) {
	$handle = strtolower( trim( (string) ( $rule['handle'] ?? '' ) ) );
	if ( '' === $handle ) {
		return 'dead';
	}

	// NO INVENTORY MEANS NO VERDICT. An empty inventory is a site that has not been scanned
	// yet, or one whose registry file is missing — not a site where every rule is stale. Left
	// unguarded this condemns the entire rule list on a fresh install, and the first thing the
	// user is offered is a button that deletes all their work.
	if ( empty( $handle_owner ) ) {
		return 'live';
	}

	// NEVER SEEN AT ALL is not the same as "not on this page". The inventory is the union of
	// every handle recorded across the site, so a handle missing from it is one nothing has
	// enqueued since the inventory was built — not one that simply did not appear today.
	if ( ! isset( $handle_owner[ $handle ] ) ) {
		return 'dead';
	}

	$owner = strtolower( trim( (string) $handle_owner[ $handle ] ) );
	if ( '' === $owner || 'wordpress-core' === $owner ) {
		return 'live'; // Core handles do not get deactivated.
	}

	// Owner known but switched off: the rule is dormant, not dead. Deleting it would quietly
	// undo the user's decision the next time they reactivate the plugin.
	return isset( $active_owners[ $owner ] ) ? 'live' : 'sleeping';
}

/**
 * Classify a whole rule set.
 *
 * @since 0.1.0
 * @param array[]              $rules         Sanitized rules.
 * @param array<string,string> $handle_owner  Handle => owner slug.
 * @param array<string,true>   $active_owners Active owner slugs.
 * @return array{live:array[],sleeping:array[],dead:array[]}
 */
function wpoptimizing_assets_audit_rules( array $rules, array $handle_owner, array $active_owners ) {
	$out = array(
		'live'     => array(),
		'sleeping' => array(),
		'dead'     => array(),
	);

	foreach ( $rules as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}
		$state           = wpoptimizing_assets_rule_state( $rule, $handle_owner, $active_owners );
		$rule['owner']   = (string) ( $handle_owner[ strtolower( trim( (string) ( $rule['handle'] ?? '' ) ) ) ] ?? '' );
		$out[ $state ][] = $rule;
	}

	return $out;
}

/**
 * Drop the dead rules from a rule set, keeping everything else exactly as it was.
 *
 * ONLY THE DEAD ONES. A sleeping rule belongs to an extension that is merely switched off, and
 * removing it would undo a decision the user made, invisibly, at the moment they turn that
 * plugin back on.
 *
 * @since 0.1.0
 * @param array[]              $rules         Sanitized rules.
 * @param array<string,string> $handle_owner  Handle => owner slug.
 * @param array<string,true>   $active_owners Active owner slugs.
 * @return array[] The rules worth keeping.
 */
function wpoptimizing_assets_prune_dead_rules( array $rules, array $handle_owner, array $active_owners ) {
	$kept = array();
	foreach ( $rules as $rule ) {
		if ( ! is_array( $rule ) ) {
			continue;
		}
		if ( 'dead' !== wpoptimizing_assets_rule_state( $rule, $handle_owner, $active_owners ) ) {
			$kept[] = $rule;
		}
	}
	return $kept;
}

/**
 * Audit this site's rules against its own inventory. Request-cached.
 *
 * Admin-side by consequence: it reads the inventory CSV and the installed-extension list.
 *
 * @since 0.1.0
 * @return array{live:array[],sleeping:array[],dead:array[]}
 */
function wpoptimizing_assets_audit_current_rules() {
	static $cache = null;
	if ( null !== $cache ) {
		return $cache;
	}

	$rules = function_exists( 'wpoptimizing_assets_get_rules' ) ? wpoptimizing_assets_get_rules() : array();

	$handle_owner = array();
	if ( function_exists( 'wpoptimizing_assets_inventory_rows' ) ) {
		foreach ( (array) wpoptimizing_assets_inventory_rows() as $row ) {
			$h = strtolower( trim( (string) ( $row['handle'] ?? '' ) ) );
			if ( '' !== $h && ! isset( $handle_owner[ $h ] ) ) {
				$handle_owner[ $h ] = strtolower( trim( (string) ( $row['owner_slug'] ?? '' ) ) );
			}
		}
	}

	$active = array( 'wordpress-core' => true );
	if ( function_exists( 'wpoptimizing_active_plugin_files' ) ) {
		foreach ( (array) wpoptimizing_active_plugin_files() as $file ) {
			$dir = dirname( (string) $file );
			$active[ strtolower( '.' === $dir ? basename( (string) $file, '.php' ) : $dir ) ] = true;
		}
	}
	if ( function_exists( 'get_option' ) ) {
		foreach ( array( 'stylesheet', 'template' ) as $key ) {
			$slug = strtolower( (string) get_option( $key, '' ) );
			if ( '' !== $slug ) {
				$active[ $slug ] = true;
			}
		}
	}

	$cache = wpoptimizing_assets_audit_rules( $rules, $handle_owner, $active );
	return $cache;
}
