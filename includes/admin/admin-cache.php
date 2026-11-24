<?php
/**
 * Safe admin optimizations (opt-in, free):
 *  1) Cache the GLOBAL comment counts that the dashboard "At a Glance" widget and
 *     the comments menu bubble compute on most admin loads. Uses the
 *     `wp_count_comments` pre-filter (short-circuits the COUNT query) and a
 *     transient, so it speeds up even sites WITHOUT a persistent object cache.
 *     Only the global count (post_id 0) is cached, and it is invalidated on any
 *     comment change — never stale.
 *  2) Heartbeat control: slow down or disable the admin Heartbeat polling, which
 *     hits admin-ajax.php every 15–60 s. Purely a load reducer; reversible.
 *
 * This is NOT full-page admin caching (which would break nonces/per-user state).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Effective admin-optimization settings (stored alongside the cache settings).
 *
 * 'heartbeat' is the one-word summary older code and the presets still read;
 * 'heartbeat_zones' is what is applied.
 *
 * @return array{admin_counts:bool,heartbeat:string,heartbeat_zones:array}
 */
function wpoptimizing_admin_cache_settings() {
	$s     = get_option( 'wpoptimizing_cache_settings', array() );
	$s     = is_array( $s ) ? $s : array();
	$zones = wpoptimizing_heartbeat_zones( $s );
	return array(
		'admin_counts'    => ! empty( $s['admin_counts'] ),
		'heartbeat'       => wpoptimizing_heartbeat_summary( $zones ),
		'heartbeat_zones' => $zones,
	);
}

/**
 * Short-circuit the global comment-count computation with a cached value.
 *
 * @param array|object $override Default short-circuit value (empty array).
 * @param int          $post_id  Post ID (0 = global).
 * @return array|object Cached stats object on hit, else the untouched override.
 */
function wpoptimizing_admin_cache_comments( $override, $post_id ) {
	if ( 0 !== (int) $post_id || ! wpoptimizing_admin_cache_settings()['admin_counts'] ) {
		return $override;
	}
	$cached = get_transient( 'wpopt_cc_global' );
	if ( is_object( $cached ) ) {
		return $cached;
	}
	// Compute once (get_comment_count is the cheap building block core also uses),
	// build the same stats object core returns, cache it and short-circuit.
	$count = get_comment_count( 0 );
	$stats = (object) array(
		'approved'       => (int) ( $count['approved'] ?? 0 ),
		'moderated'      => (int) ( $count['awaiting_moderation'] ?? 0 ),
		'spam'           => (int) ( $count['spam'] ?? 0 ),
		'trash'          => (int) ( $count['trash'] ?? 0 ),
		'post-trashed'   => (int) ( $count['post-trashed'] ?? 0 ),
		'total_comments' => (int) ( $count['total_comments'] ?? 0 ),
		'all'            => (int) ( $count['all'] ?? 0 ),
	);
	set_transient( 'wpopt_cc_global', $stats, 12 * HOUR_IN_SECONDS );
	return $stats;
}
add_filter( 'wp_count_comments', 'wpoptimizing_admin_cache_comments', 5, 2 );

/**
 * Drop the cached global comment count whenever comments change.
 *
 * @return void
 */
function wpoptimizing_admin_cache_flush_comments() {
	delete_transient( 'wpopt_cc_global' );
}
foreach ( array( 'wp_insert_comment', 'wp_set_comment_status', 'edit_comment', 'deleted_comment', 'trashed_comment', 'untrashed_comment', 'spammed_comment', 'unspammed_comment', 'wp_update_comment_count' ) as $wpopt_cc_hook ) {
	add_action( $wpopt_cc_hook, 'wpoptimizing_admin_cache_flush_comments' );
}
unset( $wpopt_cc_hook );

/*
 * ── Heartbeat by zone ─────────────────────────────────────────────────────────────────────────
 *
 * Three zones, as the other plugins split it: the admin screens, the editors (classic and block
 * editor in post.php / post-new.php, and the Site Editor) and the front end. Each one is
 * 'default' (WordPress decides), 'off' or an interval in seconds.
 *
 * Until schema 2 there was a single value, applied like this — and the migration keeps exactly
 * that: 'slow' set 60 s in every zone, editor included; 'off' removed the script everywhere
 * except the editors, which kept WordPress' own rate.
 *
 * The single 'heartbeat' key stays, as a preset: the cache form, the quick profiles, the wizard
 * and the Performance lever still write it. When something changes it without touching the
 * zones, the zones follow the preset; when the zones change, it is rewritten as their summary.
 * See wpoptimizing_heartbeat_before_save().
 */

/**
 * Schema of the Heartbeat settings; 2 = per zone.
 */
const WPOPT_HEARTBEAT_SCHEMA = 2;

/**
 * Option key of each zone. Pure.
 *
 * @return array<string,string> zone => key in wpoptimizing_cache_settings.
 */
function wpoptimizing_heartbeat_zone_keys() {
	return array(
		'admin'  => 'heartbeat_admin',
		'editor' => 'heartbeat_editor',
		'front'  => 'heartbeat_front',
	);
}

/**
 * Longest interval a zone accepts, in seconds. Pure.
 *
 * The editor stops at 120: post locks expire after 150 s, and a slower beat lets another person
 * take a post that is still open. Elsewhere five minutes is plenty for anything that polls.
 *
 * @param string $zone admin|editor|front.
 * @return int
 */
function wpoptimizing_heartbeat_max_interval( $zone ) {
	return 'editor' === $zone ? 120 : 300;
}

/**
 * One zone's value, sanitized. Pure.
 *
 * 'off' is not accepted for the editor: there the Heartbeat is autosave and the edit lock, and
 * removing the script also drops autosave.js, which depends on it (a long post lost everything
 * since the last manual save when the tab closed). The editor is slowed, never switched off.
 *
 * @param mixed  $value Raw value: 'default', 'off' or seconds.
 * @param string $zone  admin|editor|front.
 * @return string|int 'default', 'off' or seconds (15 to the zone's maximum).
 */
function wpoptimizing_heartbeat_zone_value( $value, $zone ) {
	if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
		$n = (int) $value;
		return $n > 0 ? max( 15, min( wpoptimizing_heartbeat_max_interval( $zone ), $n ) ) : 'default';
	}
	if ( 'off' === $value && 'editor' !== $zone ) {
		return 'off';
	}
	return 'default';
}

/**
 * The zones a one-word preset stands for — exactly what that word did before zones. Pure.
 *
 * @param string $mode default|slow|off.
 * @return array{admin:string|int,editor:string|int,front:string|int}
 */
function wpoptimizing_heartbeat_preset_zones( $mode ) {
	if ( 'slow' === $mode ) {
		return array(
			'admin'  => 60,
			'editor' => 60,
			'front'  => 60,
		);
	}
	if ( 'off' === $mode ) {
		return array(
			'admin'  => 'off',
			'editor' => 'default',
			'front'  => 'off',
		);
	}
	return array(
		'admin'  => 'default',
		'editor' => 'default',
		'front'  => 'default',
	);
}

/**
 * The one word that best describes some zones (for the preset select, the profiles summary and
 * the Performance lever). Pure.
 *
 * @param array $zones Sanitized zones.
 * @return string default|slow|off
 */
function wpoptimizing_heartbeat_summary( array $zones ) {
	foreach ( array( 'default', 'slow', 'off' ) as $mode ) {
		if ( wpoptimizing_heartbeat_preset_zones( $mode ) === $zones ) {
			return $mode;
		}
	}
	if ( in_array( 'off', $zones, true ) ) {
		return 'off';
	}
	foreach ( $zones as $v ) {
		if ( is_int( $v ) ) {
			return 'slow';
		}
	}
	return 'default';
}

/**
 * Bring stored cache settings to the per-zone schema. Pure and idempotent: settings already on
 * schema 2 come back with their zones only sanitized; older ones get the zones their single
 * value stood for, so nothing changes in behaviour.
 *
 * @param array $s Stored wpoptimizing_cache_settings.
 * @return array
 */
function wpoptimizing_heartbeat_migrate( array $s ) {
	$legacy = isset( $s['heartbeat'] ) && in_array( $s['heartbeat'], array( 'default', 'slow', 'off' ), true ) ? $s['heartbeat'] : 'default';
	$preset = wpoptimizing_heartbeat_preset_zones( $legacy );
	$is_v2  = isset( $s['heartbeat_v'] ) && (int) $s['heartbeat_v'] >= WPOPT_HEARTBEAT_SCHEMA;
	foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
		$s[ $key ] = ( $is_v2 && array_key_exists( $key, $s ) ) ? wpoptimizing_heartbeat_zone_value( $s[ $key ], $zone ) : $preset[ $zone ];
	}
	$s['heartbeat']   = $legacy;
	$s['heartbeat_v'] = WPOPT_HEARTBEAT_SCHEMA;
	return $s;
}

/**
 * The zones in force for some stored settings. Pure.
 *
 * @param array $s Stored wpoptimizing_cache_settings.
 * @return array{admin:string|int,editor:string|int,front:string|int}
 */
function wpoptimizing_heartbeat_zones( array $s ) {
	$s   = wpoptimizing_heartbeat_migrate( $s );
	$out = array();
	foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
		$out[ $zone ] = $s[ $key ];
	}
	return $out;
}

/**
 * Settings with the zones of a preset and the preset word, for the writers that think in one
 * word (quick profiles, wizard, Performance lever). Pure.
 *
 * @param array  $s    Cache settings.
 * @param string $mode default|slow|off.
 * @return array
 */
function wpoptimizing_heartbeat_with_preset( array $s, $mode ) {
	$mode  = in_array( $mode, array( 'default', 'slow', 'off' ), true ) ? $mode : 'default';
	$zones = wpoptimizing_heartbeat_preset_zones( $mode );
	foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
		$s[ $key ] = $zones[ $zone ];
	}
	$s['heartbeat']   = $mode;
	$s['heartbeat_v'] = WPOPT_HEARTBEAT_SCHEMA;
	return $s;
}

/**
 * Keep the zones and the preset word consistent whoever saves the option. Pure given $old.
 *
 * Several writers predate the zones and save the whole option with only the word: the cache
 * form (it rebuilds the array from its fields, so the zone keys are missing), the profiles and
 * the wizard (they change the word on what they read). The rule:
 *  - a zone that differs from what was stored is an explicit choice and wins;
 *  - otherwise, a word that differs from the stored one is a preset being applied: the zones
 *    become that preset;
 *  - otherwise the stored zones are kept (a save that never touched the Heartbeat).
 * The word is then rewritten as the summary of the zones, so the old select always shows one
 * of its three options and resubmitting it unchanged never resets custom zones.
 *
 * @param mixed $new New value.
 * @param mixed $old Stored value.
 * @return mixed
 */
function wpoptimizing_heartbeat_before_save( $new, $old ) {
	if ( ! is_array( $new ) ) {
		return $new;
	}
	$was      = wpoptimizing_heartbeat_migrate( is_array( $old ) ? $old : array() );
	$explicit = false;
	$zones    = array();
	foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
		$zones[ $zone ] = $was[ $key ];
		if ( array_key_exists( $key, $new ) ) {
			$v = wpoptimizing_heartbeat_zone_value( $new[ $key ], $zone );
			if ( $v !== $was[ $key ] ) {
				$explicit       = true;
				$zones[ $zone ] = $v;
			}
		}
	}
	$word = isset( $new['heartbeat'] ) && in_array( $new['heartbeat'], array( 'default', 'slow', 'off' ), true ) ? $new['heartbeat'] : $was['heartbeat'];
	if ( ! $explicit && $word !== $was['heartbeat'] ) {
		$zones = wpoptimizing_heartbeat_preset_zones( $word );
	}
	foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
		$new[ $key ] = $zones[ $zone ];
	}
	$new['heartbeat']   = wpoptimizing_heartbeat_summary( $zones );
	$new['heartbeat_v'] = WPOPT_HEARTBEAT_SCHEMA;
	return $new;
}
add_filter( 'pre_update_option_wpoptimizing_cache_settings', 'wpoptimizing_heartbeat_before_save', 10, 2 );

/**
 * Store the per-zone schema once, on an admin request. The readers already migrate in memory,
 * so this only saves them the work; it is idempotent (schema 2 is left alone).
 *
 * @return void
 */
function wpoptimizing_heartbeat_migrate_option() {
	$s = get_option( 'wpoptimizing_cache_settings' );
	if ( ! is_array( $s ) || ( isset( $s['heartbeat_v'] ) && (int) $s['heartbeat_v'] >= WPOPT_HEARTBEAT_SCHEMA ) ) {
		return;
	}
	update_option( 'wpoptimizing_cache_settings', wpoptimizing_heartbeat_migrate( $s ), false );
}
add_action( 'admin_init', 'wpoptimizing_heartbeat_migrate_option' );

/**
 * Which zone this request is in.
 *
 * The editors are post.php / post-new.php (classic and block editor alike) and site-editor.php:
 * the same screens the single «off» always spared.
 *
 * @return string admin|editor|front
 */
function wpoptimizing_heartbeat_current_zone() {
	global $pagenow;
	if ( ! is_admin() ) {
		$zone = 'front';
	} elseif ( in_array( (string) $pagenow, array( 'post.php', 'post-new.php', 'site-editor.php' ), true ) ) {
		$zone = 'editor';
	} else {
		$zone = 'admin';
	}
	/**
	 * Filters the Heartbeat zone of the current request (admin, editor or front).
	 *
	 * @since 0.1.0
	 * @param string $zone Zone.
	 */
	$filtered = apply_filters( 'wpoptimizing_heartbeat_zone', $zone );
	return in_array( $filtered, array( 'admin', 'editor', 'front' ), true ) ? $filtered : $zone;
}

/**
 * Set the zone's interval.
 *
 * The minimal interval is set to the same value: without it WordPress overrides the choice —
 * the classic editor asks for 10 s as soon as its post-lock dialog exists (wp-admin/js/post.js),
 * and above 120 s a window that loses focus drops back to 120. With it, an imported «120 s»
 * stays 120 s in the editor too (Codex, 06/11/2026). The editor's ceiling of 120 s keeps the
 * lock refreshed before it expires at 150 s.
 *
 * @param array $settings Heartbeat settings.
 * @return array
 */
function wpoptimizing_admin_cache_heartbeat( $settings ) {
	$zones = wpoptimizing_admin_cache_settings()['heartbeat_zones'];
	$v     = $zones[ wpoptimizing_heartbeat_current_zone() ] ?? 'default';
	if ( is_int( $v ) ) {
		$settings                    = is_array( $settings ) ? $settings : array();
		$settings['interval']        = $v;
		$settings['minimalInterval'] = $v;
	}
	return $settings;
}
add_filter( 'heartbeat_settings', 'wpoptimizing_admin_cache_heartbeat' );

/**
 * Remove the Heartbeat in the zones where it is off. Never in the editors: see
 * wpoptimizing_heartbeat_zone_value().
 *
 * @return void
 */
function wpoptimizing_admin_cache_heartbeat_off() {
	$zone = wpoptimizing_heartbeat_current_zone();
	if ( 'editor' === $zone || 'off' !== ( wpoptimizing_admin_cache_settings()['heartbeat_zones'][ $zone ] ?? 'default' ) ) {
		return;
	}
	wp_deregister_script( 'heartbeat' );
}
add_action( 'init', 'wpoptimizing_admin_cache_heartbeat_off', 1 );
