<?php
/**
 * The dashboard KPI caches, and the ONE function that drops them.
 *
 * The "Site status" cards cache what is expensive to compute (the autoload weight is a SUM
 * over wp_options, so it is cached for 5 minutes). That is fine, until a card keeps showing a
 * number the user has just changed: they delete an orphan, they attribute one correctly, and
 * the card still says what it said before. Trust in every other number on the screen goes with
 * it.
 *
 * The caches were being dropped in exactly two places — the DB-orphan delete and the empty-rows
 * delete — so every other action (file orphans, manual attribution, Smart Deactivation,
 * enabling the cache) left a stale card behind. This file is the single list and the single
 * flush; KpiCacheTest reads the source and fails if a KPI cache is added and not listed here.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every transient that backs a dashboard KPI.
 *
 * @return string[]
 */
function wpoptimizing_kpi_transients() {
	return array(
		'wpoptimizing_overview_orphans',
		'wpoptimizing_overview_autoload_bytes',
	);
}

/**
 * Drop every KPI cache. Call it after ANY change that a card could be showing.
 *
 * Cheap (a handful of transient deletes) and safe to over-call: the cards recompute on the
 * next render. Being stingy here is how a card ends up lying about a number the user just
 * changed with their own hands.
 *
 * @return void
 */
function wpoptimizing_flush_kpi_cache() {
	foreach ( wpoptimizing_kpi_transients() as $key ) {
		delete_transient( $key );
	}
}
