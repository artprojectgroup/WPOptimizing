<?php
/**
 * Pure helpers for the staged (asynchronous) scan orchestration.
 *
 * The heavy work lives in wpoptimizing.php (it needs the engine classes); these
 * side-effect-free helpers define the stage contract and the watchdog logic so
 * they can be unit-tested offline.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Ordered scan stages. The scan is split into these so that no single request
 * has to do everything at once (the cause of timeouts on large installs).
 *
 * There used to be an 'fs' stage between 'code_theme' and 'impact_db'. It announced
 * "Scanning filesystem…" at 42% and did nothing: it built WPOPT_FS_Scanner with its manifest
 * mode off, and nothing read that manifest anyway. The filesystem work the name promised is
 * done by WPOPT_FS_Orphans in the 'fs_orphans' stage. A progress bar that reports work nobody
 * is doing is worse than a shorter bar, so the stage is gone; the jump from 30% to 55% is
 * honest about where the time actually goes.
 *
 * @return string[]
 */
function wpoptimizing_scan_stage_keys() {
	return array(
		'prepare',
		'code_plugins',
		'code_mu',
		'code_theme',
		'impact_db',
		'empty_db',
		'fs_orphans',
		'media',
		'finalize',
	);
}

/**
 * Progress percentage shown when a stage starts. Monotonically increasing.
 *
 * @param string $key Stage key.
 * @return int 0-100.
 */
function wpoptimizing_scan_stage_pct( $key ) {
	$map = array(
		'prepare'      => 2,
		'code_plugins' => 10,
		'code_mu'      => 20,
		'code_theme'   => 30,
		'impact_db'    => 55,
		'empty_db'     => 62,
		'fs_orphans'   => 68,
		'media'        => 80,
		'finalize'     => 100,
	);
	return isset( $map[ $key ] ) ? (int) $map[ $key ] : 0;
}

/**
 * Whether a full scan is already under way, so a second one must not start: its staged
 * cursor exists and has beaten recently. A cursor left by a worker that died long ago does
 * not block — the watchdog only clears it while somebody watches.
 *
 * @param mixed $state   The wpoptimizing_scan_state option.
 * @param int   $now     Current timestamp.
 * @param int   $timeout Step timeout (seconds).
 * @return bool
 */
function wpoptimizing_scan_in_progress( $state, $now, $timeout ) {
	if ( ! is_array( $state ) ) {
		return false;
	}
	$heartbeat = (int) ( $state['heartbeat'] ?? 0 );
	return $heartbeat > 0 && ( (int) $now - $heartbeat ) <= max( 600, 3 * (int) $timeout );
}

/**
 * Whether a running scan looks stalled (its worker stopped sending heartbeats).
 *
 * @param int $heartbeat Last heartbeat timestamp (0 if unknown).
 * @param int $now       Current timestamp.
 * @param int $timeout   Seconds of silence after which we consider it stalled.
 * @return bool
 */
function wpoptimizing_scan_is_stalled( $heartbeat, $now, $timeout ) {
	$heartbeat = (int) $heartbeat;
	$timeout   = (int) $timeout;
	if ( $heartbeat <= 0 || $timeout <= 0 ) {
		return false;
	}
	return ( (int) $now - $heartbeat ) > $timeout;
}
