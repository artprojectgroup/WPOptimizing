<?php
/**
 * Scheduled tasks: health, run now, pause.
 *
 * - Health: how many events are overdue and by how long. An event late by hours means nothing is
 *   firing WP-Cron — a low-traffic site, a loopback the host blocks, or DISABLE_WP_CRON without the
 *   server cron that should replace it — and every task behind it (updates, emails, cleanups,
 *   scheduled posts) is silently not happening.
 * - Run now: queues one extra run of the event for immediately and fires WP-Cron, the way
 *   WP Crontrol does, so the task runs in a real cron request and not inside the admin page.
 * - Pause: the event stays scheduled (so its owner does not just add it again) but its callbacks
 *   are taken off in cron requests while it is paused.
 *
 * At file scope it only registers the pause guard, and only on a cron request.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * How late an event may be before it counts as overdue: WP-Cron fires at most once a minute
 * and a busy run can push the next ones back a few minutes.
 */
if ( ! defined( 'WPOPT_CRON_GRACE' ) ) {
	define( 'WPOPT_CRON_GRACE', 10 * MINUTE_IN_SECONDS );
}

/**
 * Health of the schedule from the events' due times. Pure.
 *
 * @param int[] $timestamps Due time of every scheduled event.
 * @param int   $now        Current time.
 * @return array{level:string,overdue:int,oldest:int} level ok|warn|bad; oldest = seconds late.
 */
function wpoptimizing_cron_health( array $timestamps, $now ) {
	$overdue = 0;
	$oldest  = 0;
	foreach ( $timestamps as $ts ) {
		$late = (int) $now - (int) $ts;
		if ( $late > WPOPT_CRON_GRACE ) {
			++$overdue;
			$oldest = max( $oldest, $late );
		}
	}
	$level = 0 === $overdue ? 'ok' : ( $oldest > HOUR_IN_SECONDS ? 'bad' : 'warn' );
	return array(
		'level'   => $level,
		'overdue' => $overdue,
		'oldest'  => $oldest,
	);
}

/**
 * Hooks the site has paused.
 *
 * @return string[]
 */
function wpoptimizing_cron_paused() {
	$paused = get_option( 'wpoptimizing_cron_paused', array() );
	return is_array( $paused ) ? array_values( array_filter( array_map( 'strval', $paused ) ) ) : array();
}

/**
 * Pause or resume a hook.
 *
 * @param string $hook  Hook name.
 * @param bool   $pause True to pause, false to resume.
 * @return void
 */
function wpoptimizing_cron_set_paused( $hook, $pause ) {
	$hook   = (string) $hook;
	$paused = array_values( array_diff( wpoptimizing_cron_paused(), array( $hook ) ) );
	if ( $pause && '' !== $hook ) {
		$paused[] = $hook;
	}
	update_option( 'wpoptimizing_cron_paused', $paused, false );
}

/**
 * Take the callbacks off every paused hook. Runs on wp_loaded of a cron request, when every
 * plugin has registered its callbacks and before wp-cron.php fires the due events.
 *
 * @return void
 */
function wpoptimizing_cron_apply_pauses() {
	foreach ( wpoptimizing_cron_paused() as $hook ) {
		remove_all_actions( $hook );
		add_action(
			$hook,
			function ( ...$args ) use ( $hook ) {
				wpoptimizing_cron_hold_single( $hook, $args );
			},
			10,
			20
		);
	}
}

/**
 * A recurring event is already rescheduled when its turn comes; a one-time event is gone, so a
 * paused one would be lost. Put it back an hour later, so resuming the hook still finds it.
 *
 * @param string $hook Hook name.
 * @param array  $args Event arguments.
 * @return void
 */
function wpoptimizing_cron_hold_single( $hook, array $args ) {
	if ( ! wp_next_scheduled( $hook, $args ) ) {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, $hook, $args );
	}
}
if ( wp_doing_cron() ) {
	add_action( 'wp_loaded', 'wpoptimizing_cron_apply_pauses', PHP_INT_MAX );
}

/**
 * A cron array with one more single run of an event, due at timestamp 1.
 *
 * Written straight into the array, as WP Crontrol does: wp_schedule_single_event() refuses an
 * event within ten minutes of an identical one, and for an OVERDUE event it compares against
 * the occurrence already due — so "Run now" on the very events that needed it was refused.
 *
 * @param array  $cron Cron array (as _get_cron_array() returns it).
 * @param string $hook Hook name.
 * @param array  $args Event arguments.
 * @return array
 */
function wpoptimizing_cron_with_run_now( array $cron, $hook, array $args ) {
	$cron[1][ (string) $hook ][ md5( serialize( $args ) ) ] = array( // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- the signature WordPress itself uses for cron events.
		'schedule' => false,
		'args'     => $args,
	);
	uksort(
		$cron,
		static function ( $a, $b ) {
			return (int) $a <=> (int) $b;
		}
	);
	return $cron;
}

/**
 * Queue one immediate run of an event and fire WP-Cron.
 *
 * The copy is put at timestamp 1 rather than now, written into the cron array directly: see
 * wpoptimizing_cron_with_run_now().
 *
 * @param string $hook Hook name.
 * @param array  $args Event arguments.
 * @return bool Queued.
 */
function wpoptimizing_cron_run_now( $hook, array $args = array() ) {
	$hook = (string) $hook;
	if ( '' === $hook || in_array( $hook, wpoptimizing_cron_paused(), true ) ) {
		return false;
	}
	if ( ! function_exists( '_get_cron_array' ) || ! function_exists( '_set_cron_array' ) ) {
		return false;
	}
	// The cron array is site data too: the events of the hook go into a backup first (merged
	// back at their own timestamps on restore). No backup, no change.
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	if ( ! class_exists( 'WPOptimizing_Backup_Writer' ) || ! ( new WPOptimizing_Backup_Writer( '', 'cron_run' ) )->cron( array( $hook ) ) ) {
		return false;
	}
	$saved = _set_cron_array( wpoptimizing_cron_with_run_now( (array) _get_cron_array(), $hook, $args ) );
	if ( function_exists( 'is_wp_error' ) && is_wp_error( $saved ) ) {
		return false;
	}
	// false also means "nothing changed": the same run was already queued, which is fine.
	$now = (array) _get_cron_array();
	if ( false === $saved && empty( $now[1][ $hook ][ md5( serialize( $args ) ) ] ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- cron event signature.
		return false;
	}
	// A stale lock left by a run that died would hold the spawn back for up to a minute.
	delete_transient( 'doing_cron' );
	if ( function_exists( 'spawn_cron' ) ) {
		spawn_cron();
	}
	return true;
}
