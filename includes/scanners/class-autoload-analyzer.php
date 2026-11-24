<?php
/**
 * Autoloaded options analyzer.
 *
 * Autoloaded options are loaded into memory on EVERY WordPress request, so a
 * bloated autoload set slows down the whole site. This analyzer reports the
 * count and weight (free tier) and lets premium users disable autoload or
 * delete orphaned options.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the option `autoload` column values that mean "autoloaded".
 * WordPress 6.6 introduced 'on'/'auto-on'; older versions only use 'yes'.
 *
 * @return string[]
 */
function wpoptimizing_autoload_values() {
	if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
		$vals = (array) wp_autoload_values_to_autoload();
		if ( ! empty( $vals ) ) {
			return $vals;
		}
	}
	return array( 'yes', 'on', 'auto-on' );
}

/**
 * Analyze autoloaded options for the current site.
 *
 * @param int $top How many largest options to return (default 50).
 * @return array{
 *   count:int, size:int, size_human:string, health:string,
 *   options:array<int,array{name:string,size:int,component:string,orphan:bool}>
 * }
 */
function wpoptimizing_analyze_autoload( $top = 50 ) {
	global $wpdb;

	$values       = wpoptimizing_autoload_values();
	$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$total_size  = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT SUM(LENGTH(option_value)) FROM ' . $wpdb->options . ' WHERE autoload IN (' . $placeholders . ')',
			$values
		)
	);
	$total_count = (int) $wpdb->get_var(
		$wpdb->prepare(
			'SELECT COUNT(*) FROM ' . $wpdb->options . ' WHERE autoload IN (' . $placeholders . ')',
			$values
		)
	);
	$top_rows    = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, LENGTH(option_value) AS size_bytes FROM ' . $wpdb->options . ' WHERE autoload IN (' . $placeholders . ') ORDER BY LENGTH(option_value) DESC LIMIT %d',
			array_merge( $values, array( (int) $top ) )
		),
		ARRAY_A
	);
	// phpcs:enable

	// Build prefixes of installed extensions for attribution.
	$installed_prefixes = wpoptimizing_autoload_known_prefixes();

	$options = array();
	foreach ( (array) $top_rows as $row ) {
		$name                       = (string) $row['option_name'];
		$size                       = (int) $row['size_bytes'];
		list( $component, $orphan ) = wpoptimizing_autoload_classify( $name, $installed_prefixes );
		$options[]                  = array(
			'name'      => $name,
			'size'      => $size,
			'component' => $component,
			'orphan'    => $orphan,
		);
	}

	// Health: <800KB healthy, 800KB-1MB ok, 1-2MB warning, >2MB critical.
	if ( $total_size > 2097152 ) {
		$health = 'critical';
	} elseif ( $total_size > 1048576 ) {
		$health = 'warning';
	} elseif ( $total_size > 800000 ) {
		$health = 'ok';
	} else {
		$health = 'healthy';
	}

	return array(
		'count'      => $total_count,
		'size'       => $total_size,
		'size_human' => size_format( $total_size, 1 ),
		'health'     => $health,
		'options'    => $options,
	);
}

/**
 * Return ALL autoloaded options for the current site, fully classified.
 * Lightweight: selects only the option name + value LENGTH (never the value).
 * Uses the runtime/remote attribution map when available so orphans from
 * uninstalled extensions are identified confidently — this is the knowledge
 * base that "smart deactivation" relies on.
 *
 * @param int $cap Safety cap on rows fetched (0 = no cap, default 20000).
 * @return array{count:int,size:int,size_human:string,health:string,capped:bool,
 *   options:array<int,array{name:string,size:int,component:string,orphan:bool,confident:bool}>}
 */
function wpoptimizing_analyze_autoload_full( $cap = 20000 ) {
	global $wpdb;

	$values       = wpoptimizing_autoload_values();
	$placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );

	$limit_sql = ( $cap > 0 ) ? ' LIMIT ' . (int) $cap : '';
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, LENGTH(option_value) AS size_bytes FROM ' . $wpdb->options . ' WHERE autoload IN (' . $placeholders . ') ORDER BY LENGTH(option_value) DESC' . $limit_sql,
			$values
		),
		ARRAY_A
	);
	// phpcs:enable

	$prefixes     = wpoptimizing_autoload_known_prefixes();
	$installed    = wpoptimizing_autoload_installed_slugs();
	$resolver     = class_exists( 'WPOPT_Runtime_Resolver' ) ? new WPOPT_Runtime_Resolver() : null;
	$scan_attr    = wpoptimizing_autoload_scan_attributions(); // option_name => [slug, status], from the last scan
	$scan_orphans = array();
	foreach ( $scan_attr as $sn => $sa ) {
		if ( 'orphan' === $sa[1] ) {
			$scan_orphans[ $sn ] = $sa[0];
		}
	}
	$prefixes['learned'] = wpoptimizing_autoload_learned_prefixes( $scan_attr );
	$kb                  = function_exists( 'wpoptimizing_autoload_kb' ) ? wpoptimizing_autoload_kb() : array(
		'orphan' => array(),
		'read'   => array(),
		'unread' => array(),
	);
	$usage               = wpoptimizing_autoload_usage_state();          // measured autoloaded-option usage
	$usage_req           = (int) $usage['requests'];
	$usage_min           = (int) apply_filters( 'wpoptimizing_autoload_usage_min_requests', 50 );
	$usage_floor         = (float) apply_filters( 'wpoptimizing_autoload_usage_unread_ratio', 0.05 );

	$options    = array();
	$total_size = 0;
	foreach ( (array) $rows as $row ) {
		$name        = (string) $row['option_name'];
		$size        = (int) $row['size_bytes'];
		$total_size += $size;

		$component = '';
		$orphan    = false;
		$confident = false;

		// 1) The last scan already attributed this option to an uninstalled
		// extension — the most reliable signal (this is the knowledge base). Checked against
		// what is installed NOW: the scan may predate the owner's (re)installation.
		$nl = strtolower( $name );
		if ( isset( $scan_orphans[ $nl ] ) ) {
			$component = $scan_orphans[ $nl ];
			$orphan    = ! wpoptimizing_autoload_any_installed( $component, $installed );
			$confident = $orphan && wpoptimizing_autoload_is_confident_orphan( $component, $installed, (string) ( $scan_attr[ $nl ][2] ?? '' ) );
		}

		// 1b) The scan attributed it to an installed extension: that owner, not an orphan.
		if ( '' === $component && isset( $scan_attr[ $nl ] ) && '' !== $scan_attr[ $nl ][0] && in_array( $scan_attr[ $nl ][1], array( 'active', 'inactive' ), true ) ) {
			$component = $scan_attr[ $nl ][0];
		}

		// 2) Runtime/remote attribution map.
		if ( '' === $component && $resolver ) {
			$slug = $resolver->get_slug_for_key( 'option', $nl );
			if ( '' !== $slug ) {
				$component = $slug;
				$orphan    = ! isset( $installed[ strtolower( $slug ) ] );
				$confident = $orphan && wpoptimizing_autoload_is_confident_orphan( $slug, $installed, (string) $resolver->get_confianza_for_key( 'option', $nl ) );
			}
		}

		// 3) Fall back to prefix heuristic (not confident).
		if ( '' === $component ) {
			list( $component, $orphan ) = wpoptimizing_autoload_classify( $name, $prefixes );
		}

		/*
		 * 4) Refine with the shared knowledge base (consume the crowd signal). An option that
		 * the crowd READS on most requests is a legitimate one — protect it (never flag or
		 * disable), even if its prefix is unknown here.
		 *
		 * THE CROWD NEVER MAKES AN ORPHAN CONFIDENT. It used to: ten sites calling an option an
		 * orphan promoted it, whatever was installed HERE — and with Yoast active,
		 * `wpseo_titles` (an unknown prefix locally) came out as a confident orphan and went
		 * into "delete confirmed orphans". Other sites do not know what this one has. Confident
		 * means the owner was named on this site and is not installed on it; the crowd count
		 * stays visible as kb_orphan_sites.
		 */
		$kb_orphan_sites = wpoptimizing_autoload_kb_orphan_sites( $kb, $name );
		$kb_read         = wpoptimizing_autoload_kb_read_signal( $kb, $name );
		$confident = $confident && wpoptimizing_autoload_is_confident_orphan( $component, $installed );
		if ( ! $confident && is_array( $kb_read )
			&& wpoptimizing_autoload_kb_is_confident( (int) ( $kb_read['sites'] ?? 0 ) )
			&& (float) ( $kb_read['avg_ratio'] ?? 0 ) >= 0.5 ) {
			$orphan = false; // crowd-legitimate, widely-read option.
		}

		// 5) Dead-weight ("unread") signal: an option that loads on every request but
		// is (almost) never read. From local measurement when there is enough data,
		// or from the crowd when enough installs agree. Distinct from orphan: the
		// owner may still be installed; this is de-autoload, never delete. Core is
		// excluded (early-boot reads can be invisible to the measurement).
		$unread = false;
		if ( 'wordpress-core' !== $component && ! $orphan ) {
			if ( $usage_req >= $usage_min && isset( $usage['reads'][ $name ] ) ) {
				$ratio  = $usage_req > 0 ? ( (int) $usage['reads'][ $name ] / $usage_req ) : 0;
				$unread = ( $ratio <= $usage_floor );
			}
			if ( ! $unread && wpoptimizing_autoload_kb_is_unread( $kb, $name, $usage_floor ) ) {
				$unread = true;
			}
		}

		$options[] = array(
			'name'            => $name,
			'size'            => $size,
			'component'       => $component,
			'orphan'          => (bool) $orphan,
			'confident'       => (bool) $confident,
			'kb_orphan_sites' => (int) $kb_orphan_sites,
			'unread'          => (bool) $unread,
		);
	}

	if ( $total_size > 2097152 ) {
		$health = 'critical';
	} elseif ( $total_size > 1048576 ) {
		$health = 'warning';
	} elseif ( $total_size > 800000 ) {
		$health = 'ok';
	} else {
		$health = 'healthy';
	}

	return array(
		'count'      => count( $options ),
		'size'       => $total_size,
		'size_human' => size_format( $total_size, 1 ),
		'health'     => $health,
		'capped'     => ( $cap > 0 && count( $rows ) >= $cap ),
		'options'    => $options,
	);
}

/**
 * May an orphan verdict be CONFIDENT (and so reach "delete confirmed orphans")? Only when
 * this site named the owner — the last scan or the runtime map, never the crowd — and that
 * owner is not installed here, with a confidence that allows deleting. Pure.
 *
 * @param string             $component Owner the local evidence named, or several with `|`.
 * @param array<string,bool> $installed wpoptimizing_autoload_installed_slugs().
 * @param string|null        $confianza Confidence of that attribution; null when not known.
 * @return bool
 */
function wpoptimizing_autoload_is_confident_orphan( $component, array $installed, $confianza = null ) {
	/*
	 * VARIOS DUEÑOS Y UNA CONFIANZA. El análisis guarda `plugin-a|plugin-b` en una sola celda, y
	 * esto lo buscaba como un único slug que no existe: con `plugin-a` instalado seguía saliendo
	 * huérfana confirmada. Y la atribución la daba por segura fuera cual fuera su nivel: una
	 * `weak` del mapa entraba en «Borrar huérfanos confirmados» (auditoría del 26/10/2026).
	 */
	$partes = array_filter( array_map( 'strtolower', array_map( 'trim', explode( '|', (string) $component ) ) ) );
	if ( empty( $partes ) || in_array( 'unknown', $partes, true ) || wpoptimizing_autoload_any_installed( $component, $installed ) ) {
		return false;
	}
	if ( null !== $confianza && function_exists( 'wpoptimizing_confidence_allows_deletion' ) ) {
		return wpoptimizing_confidence_allows_deletion( (string) $confianza );
	}
	return true;
}

/**
 * Is any of the owners in a `slug|slug2` cell installed here? Pure.
 *
 * @param string             $component Owner, or several separated by `|`.
 * @param array<string,bool> $installed wpoptimizing_autoload_installed_slugs().
 * @return bool
 */
function wpoptimizing_autoload_any_installed( $component, array $installed ) {
	foreach ( explode( '|', (string) $component ) as $c ) {
		$c = strtolower( trim( $c ) );
		if ( '' !== $c && isset( $installed[ $c ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Read option names the last scan attributed to uninstalled extensions
 * (project_status = orphan) from wp-scan-db.csv. This is the richest source
 * of confident orphan attribution and the basis for smart deactivation.
 *
 * @return array<string,string> option_name (lowercased) => slug
 */
function wpoptimizing_autoload_scan_orphans() {
	$out = array();
	foreach ( wpoptimizing_autoload_scan_attributions() as $name => $a ) {
		if ( 'orphan' === $a[1] ) {
			$out[ $name ] = $a[0];
		}
	}
	return $out;
}

/**
 * Every option the last scan attributed, with the owner's state. The Autoload tab used to read
 * only the orphans, so an option the scan had pinned on an active plugin still came out here as
 * "likely orphan" of nobody.
 *
 * @return array<string,array{0:string,1:string,2:string}> option_name (lowercased) => [slug, status, confianza]
 */
function wpoptimizing_autoload_scan_attributions() {
	$out = array();
	if ( ! defined( 'WPOPT_CSV_SCAN_DB' ) || ! is_readable( WPOPT_CSV_SCAN_DB ) ) {
		return $out;
	}
	$fh = wpoptimizing_scan_read_handle( WPOPT_CSV_SCAN_DB ); // decrypts if encrypted at rest
	if ( ! $fh ) {
		return $out;
	}
	$first = fgets( $fh );
	if ( false === $first ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}
	$delims = array(
		','  => substr_count( $first, ',' ),
		"\t" => substr_count( $first, "\t" ),
		';'  => substr_count( $first, ';' ),
	);
	arsort( $delims );
	$delim  = (string) array_key_first( $delims );
	$header = str_getcsv( $first, $delim, '"', '\\' );
	$hmap   = $header ? array_flip( array_map( 'strtolower', array_map( 'trim', $header ) ) ) : array();
	$ti     = $hmap['type'] ?? null;
	$vi     = $hmap['value'] ?? null;
	$si     = $hmap['project_status'] ?? $hmap['status'] ?? null;
	$gi     = $hmap['project_slugs'] ?? $hmap['project_slug'] ?? null;
	$ci     = $hmap['confianza'] ?? null;
	if ( null === $vi || null === $si ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}
	while ( ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
		$type = null !== $ti ? strtolower( trim( (string) ( $row[ $ti ] ?? '' ) ) ) : '';
		// Options and transients live in wp_options and can be autoloaded.
		if ( '' !== $type && ! in_array( $type, array( 'option', 'transient', 'transient_expired', 'autoload' ), true ) ) {
			continue;
		}
		$status = strtolower( trim( (string) ( $row[ $si ] ?? '' ) ) );
		$name   = strtolower( trim( (string) ( $row[ $vi ] ?? '' ) ) );
		if ( '' === $name || '' === $status ) {
			continue;
		}
		$out[ $name ] = array( null !== $gi ? trim( (string) ( $row[ $gi ] ?? '' ) ) : '', $status, null !== $ci ? strtolower( trim( (string) ( $row[ $ci ] ?? '' ) ) ) : '' );
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $out;
}

/**
 * ── "Should be autoloaded" detection by real read-frequency measurement ──────
 *
 * Static heuristics cannot tell which non-autoloaded options SHOULD be
 * autoloaded — most are non-autoloaded on purpose. The reliable signal is how
 * often an option is actually read on each request. The runtime mu-plugin
 * records reads of non-autoloaded options during a measurement window; this
 * tally is then used to surface options read on most requests but not loaded.
 *
 * Options used (in wp_options on the current blog):
 *  - wpoptimizing_autoload_track_until : AUTOLOADED timestamp; the mu-plugin
 *      only instruments while time() < this value (cheap to check every request).
 *  - wpoptimizing_autoload_reads       : non-autoloaded tally
 *      array{ requests:int, started:int, reads:array<string,int> }
 */

/**
 * Get the measurement state.
 *
 * @return array{active:bool,until:int,requests:int,started:int,reads:array<string,int>}
 */
function wpoptimizing_autoload_tracking_state() {
	$until = (int) get_option( 'wpoptimizing_autoload_track_until', 0 );
	$tally = get_option( 'wpoptimizing_autoload_reads', array() );
	if ( ! is_array( $tally ) ) {
		$tally = array();
	}
	return array(
		'active'   => ( $until > time() ),
		'until'    => $until,
		'requests' => (int) ( $tally['requests'] ?? 0 ),
		'started'  => (int) ( $tally['started'] ?? 0 ),
		'reads'    => is_array( $tally['reads'] ?? null ) ? $tally['reads'] : array(),
	);
}

/**
 * Start (or restart) a measurement window.
 *
 * @param int $days Window length in days (default 7).
 * @return void
 */
function wpoptimizing_autoload_tracking_start( $days = 7 ) {
	$days = max( 1, (int) $days );
	update_option(
		'wpoptimizing_autoload_reads',
		array(
			'requests' => 0,
			'started'  => time(),
			'reads'    => array(),
		),
		false
	);
	// Autoloaded flag so the mu-plugin can check it for free each request.
	update_option( 'wpoptimizing_autoload_track_until', time() + ( $days * DAY_IN_SECONDS ), true );
}

/**
 * Stop measuring now (keeps collected data).
 *
 * @return void
 */
function wpoptimizing_autoload_tracking_stop() {
	update_option( 'wpoptimizing_autoload_track_until', 0, true );
}

/**
 * Clear all measurement data and stop.
 *
 * @return void
 */
function wpoptimizing_autoload_tracking_reset() {
	update_option( 'wpoptimizing_autoload_track_until', 0, true );
	delete_option( 'wpoptimizing_autoload_reads' );
}

/**
 * Keep the measurement running on its own, with no button to press.
 *
 * Measurement is perpetual: it starts itself the first time this runs and then
 * stays open so every install continuously learns which non-autoloaded options
 * are actually read. The tally is refreshed roughly once a month so the read
 * ratios always reflect the site's CURRENT plugin set (a recently added option
 * is not penalised by months of older requests). The window is only nudged ~2
 * days ahead, so if the renewing cron ever stops the measurement winds down on
 * its own instead of running forever — but in normal operation the daily call
 * keeps it permanently active. Cheap: it writes at most once per call and the
 * caller is throttled to once per day.
 *
 * @param int $refresh_days Reset the tally after this many days (default 30).
 * @return void
 */
function wpoptimizing_autoload_tracking_ensure( $refresh_days = 30 ) {
	$now   = time();
	$tally = get_option( 'wpoptimizing_autoload_reads', null );

	if ( ! is_array( $tally ) || ! isset( $tally['started'] ) ) {
		// First time: begin measuring.
		update_option(
			'wpoptimizing_autoload_reads',
			array(
				'requests' => 0,
				'started'  => $now,
				'reads'    => array(),
			),
			false
		);
	} elseif ( ( $now - (int) $tally['started'] ) > ( max( 1, (int) $refresh_days ) * DAY_IN_SECONDS ) ) {
		// Monthly refresh so ratios track the current site, not its whole history.
		update_option(
			'wpoptimizing_autoload_reads',
			array(
				'requests' => 0,
				'started'  => $now,
				'reads'    => array(),
			),
			false
		);
	}

	// Keep the window open. Only write when it is about to lapse so we don't
	// bust the autoload cache on every call.
	$until = (int) get_option( 'wpoptimizing_autoload_track_until', 0 );
	if ( $until < ( $now + DAY_IN_SECONDS ) ) {
		update_option( 'wpoptimizing_autoload_track_until', $now + ( 2 * DAY_IN_SECONDS ), true );
	}
}

/**
 * Options that SHOULD be autoloaded, based on measured read frequency: read on
 * at least $min_ratio of tracked requests, currently NOT autoloaded, and small
 * enough to be worth autoloading. Returns empty until enough data is collected.
 *
 * @param float $min_ratio Minimum read ratio (0..1), default 0.5 (half of requests).
 * @param int   $min_req   Minimum tracked requests before suggesting anything.
 * @param int   $max_bytes Only suggest options at or below this size.
 * @return array<int,array{name:string,size:int,component:string,reads:int,requests:int,ratio:float}>
 */
function wpoptimizing_autoload_should_autoload( $min_ratio = 0.5, $min_req = 50, $max_bytes = 51200 ) {
	$state = wpoptimizing_autoload_tracking_state();
	$req   = $state['requests'];
	if ( $req < $min_req || empty( $state['reads'] ) ) {
		return array();
	}

	global $wpdb;

	$values = wpoptimizing_autoload_values();

	// Current sizes + autoload status for the measured option names.
	$names = array_keys( $state['reads'] );
	$names = array_slice( $names, 0, 5000 );
	$ph2   = implode( ',', array_fill( 0, count( $names ), '%s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$sizes = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, LENGTH(option_value) AS size_bytes, autoload FROM ' . $wpdb->options
			. ' WHERE option_name IN (' . $ph2 . ')',
			$names
		),
		ARRAY_A
	);
	// phpcs:enable

	$autoload_set = array_fill_keys( array_map( 'strval', $values ), true );
	$prefixes     = wpoptimizing_autoload_known_prefixes();

	$out = array();
	foreach ( (array) $sizes as $row ) {
		$name = (string) $row['option_name'];
		$nl   = strtolower( $name );
		$size = (int) $row['size_bytes'];

		// Already autoloaded? skip. Too big? skip. Transient/own? skip.
		if ( isset( $autoload_set[ (string) $row['autoload'] ] ) ) {
			continue;
		}
		if ( $size > $max_bytes ) {
			continue;
		}
		if ( str_starts_with( $nl, '_transient_' ) || str_starts_with( $nl, '_site_transient_' ) || str_starts_with( $nl, 'wpopt' ) ) {
			continue;
		}

		$reads = (int) ( $state['reads'][ $name ] ?? 0 );
		$ratio = $req > 0 ? ( $reads / $req ) : 0;
		if ( $ratio < $min_ratio ) {
			continue;
		}

		list( $component, ) = wpoptimizing_autoload_classify( $name, $prefixes );
		$out[]              = array(
			'name'      => $name,
			'size'      => $size,
			'component' => $component,
			'reads'     => $reads,
			'requests'  => $req,
			'ratio'     => $ratio,
		);
	}

	// Most-read first.
	usort(
		$out,
		static function ( $a, $b ) {
			return $b['reads'] <=> $a['reads'];
		}
	);
	return $out;
}

/**
 * ── "Should NOT be autoloaded" detection by measured non-usage ───────────────
 *
 * The complement of the read measurement above. The mu-plugin also instruments the
 * largest AUTOLOADED options (served from the alloptions cache, so invisible to the
 * SQL stream) and records which ones are actually read each request, into option
 * `wpoptimizing_autoload_aloaded`. An option that loads on every request but is read
 * on (almost) none is dead weight: de-autoloading it is a measured, surgical win, not
 * a name guess. Fully reversible — the value is untouched and fetched on demand.
 *
 * Get the measured autoloaded-usage state.
 *
 * @return array{requests:int,started:int,reads:array<string,int>,sizes:array<string,int>}
 */
function wpoptimizing_autoload_usage_state() {
	$tally = get_option( 'wpoptimizing_autoload_aloaded', array() );
	if ( ! is_array( $tally ) ) {
		$tally = array();
	}
	return array(
		'requests' => (int) ( $tally['requests'] ?? 0 ),
		'started'  => (int) ( $tally['started'] ?? 0 ),
		'reads'    => is_array( $tally['reads'] ?? null ) ? $tally['reads'] : array(),
		'sizes'    => is_array( $tally['sizes'] ?? null ) ? $tally['sizes'] : array(),
	);
}

/**
 * Autoloaded options that the usage measurement shows are (almost) never read:
 * read on at most $max_ratio of sampled requests, still autoloaded now, not core
 * and not our own. Returns empty until enough requests have been sampled.
 *
 * Core options are always excluded: some are read during early bootstrap (before the
 * mu-plugin's capture hooks are registered) and would look unread here even though
 * de-autoloading them would break the site. The measurement is trustworthy for plugin
 * and unknown options, which is exactly where the bloat lives.
 *
 * @param float $max_ratio Maximum read ratio (0..1) to treat as unread. Default 0.0.
 * @param int   $min_req   Minimum sampled requests before suggesting anything.
 * @return array<int,array{name:string,size:int,component:string,reads:int,requests:int,ratio:float}>
 */
function wpoptimizing_autoload_unread_autoloaded( $max_ratio = 0.0, $min_req = 50 ) {
	$state = wpoptimizing_autoload_usage_state();
	$req   = $state['requests'];
	if ( $req < $min_req || empty( $state['sizes'] ) ) {
		return array();
	}

	global $wpdb;

	$names = array_keys( $state['sizes'] );
	$names = array_slice( $names, 0, 5000 );
	$ph    = implode( ',', array_fill( 0, count( $names ), '%s' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT option_name, LENGTH(option_value) AS size_bytes, autoload FROM ' . $wpdb->options
			. ' WHERE option_name IN (' . $ph . ')',
			$names
		),
		ARRAY_A
	);
	// phpcs:enable

	$autoload_set = array_fill_keys( array_map( 'strval', wpoptimizing_autoload_values() ), true );
	$prefixes     = wpoptimizing_autoload_known_prefixes();

	$out = array();
	foreach ( (array) $rows as $row ) {
		$name = (string) $row['option_name'];
		$nl   = strtolower( $name );

		// Must still be autoloaded right now (it may have been de-autoloaded already).
		if ( ! isset( $autoload_set[ (string) $row['autoload'] ] ) ) {
			continue;
		}
		// Never touch our own bookkeeping or transients.
		if ( str_starts_with( $nl, 'wpopt' ) || str_starts_with( $nl, '_transient_' ) || str_starts_with( $nl, '_site_transient_' ) ) {
			continue;
		}
		// Protect WordPress core: early-boot reads can be invisible to the capture.
		list( $component, ) = wpoptimizing_autoload_classify( $name, $prefixes );
		if ( 'wordpress-core' === $component ) {
			continue;
		}

		$reads = (int) ( $state['reads'][ $name ] ?? 0 );
		$ratio = $req > 0 ? ( $reads / $req ) : 0;
		if ( $ratio > $max_ratio ) {
			continue;
		}

		$out[] = array(
			'name'      => $name,
			'size'      => (int) $row['size_bytes'],
			'component' => $component,
			'reads'     => $reads,
			'requests'  => $req,
			'ratio'     => $ratio,
		);
	}

	// Biggest dead weight first.
	usort(
		$out,
		static function ( $a, $b ) {
			return $b['size'] <=> $a['size'];
		}
	);
	return $out;
}

/**
 * Surgical deactivation by usage: turn off autoload for every autoloaded option that
 * is (almost) never read — from local measurement when there is enough data, and from
 * the crowd knowledge base when enough installs agree (so even a fresh install with
 * thin local data benefits). Non-destructive and fully reversible.
 *
 * @param float $max_ratio Maximum read ratio (0..1) to treat as unread.
 * @param int   $min_req   Minimum sampled requests for the LOCAL signal.
 * @return array{deactivated:int,names:string[],bytes:int,measured:bool}
 */
function wpoptimizing_autoload_deactivate_unread( $max_ratio = 0.0, $min_req = 50 ) {
	$state    = wpoptimizing_autoload_usage_state();
	$measured = ( $state['requests'] >= $min_req );

	$targets = array();
	$bytes   = array();

	// Local measured-unread (precise, only when we have enough requests).
	if ( $measured ) {
		foreach ( wpoptimizing_autoload_unread_autoloaded( $max_ratio, $min_req ) as $c ) {
			$targets[ $c['name'] ] = true;
			$bytes[ $c['name'] ]   = (int) $c['size'];
		}
	}

	// Crowd-confirmed unread (and local-unread folded in via the analyzer's flag).
	$full = wpoptimizing_analyze_autoload_full( 0 );
	foreach ( $full['options'] as $o ) {
		if ( ! empty( $o['unread'] ) ) {
			$targets[ $o['name'] ] = true;
			$bytes[ $o['name'] ]   = (int) $o['size'];
		}
	}

	$names = array_keys( $targets );
	$count = $names ? wpoptimizing_autoload_disable( $names ) : 0;
	return array(
		'deactivated' => $count,
		'names'       => $names,
		'bytes'       => array_sum( $bytes ),
		'measured'    => $measured,
	);
}

/**
 * CSV-escape a single cell (quote only when it contains a separator/quote/EOL).
 *
 * @param string $v Cell value.
 * @return string
 */
function wpoptimizing_autoload_csv_cell( $v ) {
	$v = (string) $v;
	if ( false !== strpbrk( $v, ",\"\n\r" ) ) {
		return '"' . str_replace( '"', '""', $v ) . '"';
	}
	return $v;
}

/**
 * Build this site's autoload contribution dataset as CSV text.
 *
 * Two row kinds feed the central knowledge base that nourishes the whole plugin
 * network (the same idea as the remote attribution dataset):
 *  - kind=read   : a non-autoloaded option plus how often it was actually read
 *                  (the measured "should be autoloaded" signal — never guessed).
 *  - kind=orphan : an autoloaded option confidently attributed to an extension
 *                  that is no longer installed (the crowd-sourced orphan signal).
 *
 * Privacy: only option NAMES, byte sizes and read frequencies are included —
 * option VALUES are never read or sent. Read rows are contributed with a low
 * floor so the server can aggregate raw frequencies across many sites and pick
 * the real threshold itself. Returns '' when there is nothing worth sending.
 *
 * @param int $min_req Minimum sampled requests before contributing read rows.
 * @return string CSV text (with header) or '' when there is no useful data.
 */
function wpoptimizing_autoload_build_contribution_csv( $min_req = 50 ) {
	$state = wpoptimizing_autoload_tracking_state();
	$req   = (int) $state['requests'];

	$lines = array();
	// Header from the central schema registry (single source of truth) with a safe
	// fallback so this never hard-depends on load order.
	$schema  = function_exists( 'wpoptimizing_csv_schema' ) ? wpoptimizing_csv_schema( 'autoload_contribution' ) : null;
	$lines[] = ( is_array( $schema ) && ! empty( $schema['header'] ) ) ? $schema['header'] : 'kind,option_name,size_bytes,reads,requests,ratio,component';

	// 1) Measured reads of non-autoloaded options (the "should autoload" signal).
	if ( $req >= $min_req ) {
		$reads = wpoptimizing_autoload_should_autoload( 0.1, $min_req, 51200 );
		foreach ( $reads as $r ) {
			$lines[] = implode(
				',',
				array(
					'read',
					wpoptimizing_autoload_csv_cell( $r['name'] ),
					(int) $r['size'],
					(int) $r['reads'],
					(int) $r['requests'],
					round( (float) $r['ratio'], 3 ),
					wpoptimizing_autoload_csv_cell( (string) $r['component'] ),
				)
			);
		}
	}

	// 2) Measured-unread autoloaded options (the "should NOT autoload" signal). A low
	// floor so the server aggregates raw non-usage across many sites and decides the
	// threshold; core is already excluded by wpoptimizing_autoload_unread_autoloaded().
	$ustate = wpoptimizing_autoload_usage_state();
	if ( (int) $ustate['requests'] >= $min_req ) {
		$unread = wpoptimizing_autoload_unread_autoloaded( 0.05, $min_req );
		foreach ( $unread as $u ) {
			$lines[] = implode(
				',',
				array(
					'unread',
					wpoptimizing_autoload_csv_cell( $u['name'] ),
					(int) $u['size'],
					(int) $u['reads'],
					(int) $u['requests'],
					round( (float) $u['ratio'], 3 ),
					wpoptimizing_autoload_csv_cell( (string) $u['component'] ),
				)
			);
		}
	}

	// 3) Confident orphans (autoloaded options of uninstalled extensions).
	$full = wpoptimizing_analyze_autoload_full( 0 );
	foreach ( $full['options'] as $o ) {
		if ( empty( $o['confident'] ) || empty( $o['orphan'] ) ) {
			continue;
		}
		$lines[] = implode(
			',',
			array(
				'orphan',
				wpoptimizing_autoload_csv_cell( $o['name'] ),
				(int) $o['size'],
				0,
				$req,
				0,
				wpoptimizing_autoload_csv_cell( (string) $o['component'] ),
			)
		);
	}

	// Header only? nothing to contribute.
	if ( count( $lines ) < 2 ) {
		return '';
	}
	return implode( "\n", $lines ) . "\n";
}

/**
 * Save the current autoload flag of options before it is changed, so the Backups tab can put it
 * back. No backup, no change: the callers return 0 and the request shows why.
 *
 * @param string[] $names  Option names.
 * @param string   $op_key Operation key to file it under ('' = a new one).
 * @return bool
 */
function wpoptimizing_autoload_backup( array $names, $op_key = '' ) {
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	if ( ! class_exists( 'WPOptimizing_Backup_Writer' ) ) {
		return false;
	}
	return ( new WPOptimizing_Backup_Writer( (string) $op_key, 'autoload' ) )->autoload( $names );
}

/**
 * Enable autoload for the given option names. Premium action (used by the
 * "should be autoloaded" suggestions).
 *
 * @param string[] $names  Option names.
 * @param string   $op_key Backup operation key ('' = a new one).
 * @return int Number of options updated.
 */
function wpoptimizing_autoload_enable( array $names, $op_key = '' ) {
	global $wpdb;
	$names   = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
	$updated = 0;
	if ( empty( $names ) || ! wpoptimizing_autoload_backup( $names, $op_key ) ) {
		return 0;
	}
	foreach ( $names as $name ) {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			if ( wp_set_option_autoload( $name, true ) ) {
				++$updated;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$res = $wpdb->update( $wpdb->options, array( 'autoload' => 'yes' ), array( 'option_name' => $name ) );
			if ( false !== $res && $res > 0 ) {
				++$updated;
			}
		}
	}
	wpoptimizing_flush_options_cache();
	// The Performance card IS the autoload weight, and it caches for 5 minutes: without this
	// the user turns options off and the card keeps showing the old size.
	if ( function_exists( 'wpoptimizing_flush_kpi_cache' ) ) {
		wpoptimizing_flush_kpi_cache();
	}
	return $updated;
}

/**
 * Refresh the OPTIONS cache after writing to wp_options directly, without nuking the rest.
 *
 * We used to call wp_cache_flush() here. It works — and it also wipes EVERY transient on the
 * site, ours and everyone else's, because with a persistent object cache transients live in
 * that same cache. That is how the Media report kept vanishing: applying an autoload change
 * quietly threw away an analysis that had taken minutes to produce.
 *
 * The options group is the only one a direct wp_options write can make stale, so that is the
 * only one we drop. Falls back to the full flush only where group flushing is unsupported.
 *
 * @return void
 */
function wpoptimizing_flush_options_cache() {
	if ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_group' )
		&& function_exists( 'wp_cache_flush_group' ) ) {
		wp_cache_flush_group( 'options' );
		return;
	}
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Lowercased set (slug => true) of all installed plugin + theme slugs.
 *
 * @return array<string,bool>
 */
function wpoptimizing_autoload_installed_slugs() {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$slugs = array();
	if ( function_exists( 'get_plugins' ) ) {
		foreach ( array_keys( get_plugins() ) as $file ) {
			$dir                          = dirname( (string) $file );
			$slug                         = ( '.' === $dir ) ? basename( (string) $file, '.php' ) : $dir;
			$slugs[ strtolower( $slug ) ] = true;
		}
	}
	if ( function_exists( 'wp_get_themes' ) ) {
		foreach ( array_keys( wp_get_themes() ) as $theme ) {
			$slugs[ strtolower( (string) $theme ) ] = true;
		}
	}
	if ( function_exists( 'wpoptimizing_mu_plugin_slugs' ) ) {
		$slugs += wpoptimizing_mu_plugin_slugs(); // Must-use plugins are installed too.
	}
	return $slugs;
}

/**
 * Smart deactivation: turn off autoload for every option confidently
 * identified as belonging to an uninstalled extension (a true orphan).
 * Non-destructive — the option data is kept, it just stops loading on every
 * request and can be re-enabled at any time.
 *
 * @return array{deactivated:int,names:string[]}
 */
function wpoptimizing_autoload_smart_deactivate() {
	$data    = wpoptimizing_analyze_autoload_full( 0 );
	$targets = array();
	foreach ( $data['options'] as $o ) {
		// Only the confident orphans (attributed to an uninstalled extension).
		if ( ! empty( $o['confident'] ) && ! empty( $o['orphan'] ) ) {
			$targets[] = $o['name'];
		}
	}
	$count = $targets ? wpoptimizing_autoload_disable( $targets ) : 0;
	return array(
		'deactivated' => $count,
		'names'       => $targets,
	);
}

/**
 * Aggressive (but safe) deactivation: turn off autoload for EVERY option that is not
 * core and not owned by an installed extension — including the heuristic "unknown
 * prefix" ones — so the per-request payload actually drops. Options the shared KB
 * shows are widely read are already excluded (classification protected them), so a
 * legitimate-but-unrecognised option is not touched. Still non-destructive and fully
 * reversible: the option keeps its data and is simply fetched on demand when needed.
 *
 * @return array{deactivated:int,names:string[]}
 */
function wpoptimizing_autoload_deactivate_nonessential() {
	$data    = wpoptimizing_analyze_autoload_full( 0 );
	$targets = array();
	foreach ( $data['options'] as $o ) {
		if ( ! empty( $o['orphan'] ) ) {
			$targets[] = $o['name'];
		}
	}
	$count = $targets ? wpoptimizing_autoload_disable( $targets ) : 0;
	return array(
		'deactivated' => $count,
		'names'       => $targets,
	);
}

/**
 * Option-name "option::name" entries for every autoloaded option CONFIDENTLY
 * attributed to an uninstalled extension (the local scan or the runtime map named it, and it
 * is not installed; the crowd alone never qualifies). These are dead data — safe to delete
 * (backup-first) via the standard DB delete path, not merely de-autoloaded.
 *
 * @return string[] Entries in "option::<name>" form.
 */
function wpoptimizing_autoload_confirmed_orphan_entries() {
	$data    = wpoptimizing_analyze_autoload_full( 0 );
	$entries = array();
	foreach ( $data['options'] as $o ) {
		if ( ! empty( $o['confident'] ) && ! empty( $o['orphan'] ) ) {
			$entries[] = 'option::' . $o['name'];
		}
	}
	return $entries;
}

/**
 * Smart activation: enable autoload for every non-autoloaded option that the
 * read-measurement shows is read on (almost) every request — a high-confidence
 * candidate. Conservative thresholds (read on ≥90% of sampled requests, small
 * payload) so only safe wins are applied. Reversible: the option is unchanged
 * apart from its autoload flag.
 *
 * @param float $min_ratio Minimum read ratio (0..1). Defaults to a strict 0.9.
 * @param int   $max_bytes Maximum option size to auto-enable (bytes).
 * @return array{activated:int,names:string[]}
 */
function wpoptimizing_autoload_smart_activate( $min_ratio = 0.9, $max_bytes = 20480 ) {
	$cands   = wpoptimizing_autoload_should_autoload( $min_ratio, 50, $max_bytes );
	$targets = array();
	foreach ( $cands as $c ) {
		$targets[] = $c['name'];
	}
	$count = $targets ? wpoptimizing_autoload_enable( $targets ) : 0;
	return array(
		'activated' => $count,
		'names'     => $targets,
	);
}

/**
 * Option-name prefixes learned from the scan: a leading token (icl, cmplz, mcm) maps to the
 * installed extensions its attributed options belong to — a family such as WPML's included
 * (resolvers/namespace-learning.php).
 *
 * @param array<string,array{0:string,1:string}> $attr wpoptimizing_autoload_scan_attributions().
 * @return array<string,string> token => "slug" or "slug|slug2", most frequent first
 */
function wpoptimizing_autoload_learned_prefixes( array $attr ) {
	if ( ! function_exists( 'wpoptimizing_namespace_learn' ) ) {
		return array();
	}
	$pairs = array();
	foreach ( $attr as $name => $a ) {
		if ( in_array( (string) $a[1], array( 'active', 'inactive' ), true ) ) {
			$pairs[] = array( (string) $name, (string) $a[0] );
		}
	}
	return wpoptimizing_namespace_learn( $pairs, '', 2 );
}

/**
 * Build the set of prefixes/keys that belong to installed extensions + core,
 * used to attribute autoloaded options and flag likely orphans.
 *
 * @return array{core:string[],plugins:string[]}
 */
function wpoptimizing_autoload_known_prefixes() {
	$active = function_exists( 'wpoptimizing_active_plugin_files' )
		? wpoptimizing_active_plugin_files()
		: (array) get_option( 'active_plugins', array() );

	$plugins = array();
	foreach ( $active as $file ) {
		$slug = dirname( (string) $file );
		if ( '.' !== $slug && '' !== $slug ) {
			$plugins[] = $slug;
			$plugins[] = str_replace( '-', '_', $slug );
			$plugins[] = str_replace( '-', '', $slug );
		}
	}
	$plugins = array_values( array_unique( array_filter( $plugins ) ) );

	$core = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'admin_email',
		'users_can_register',
		'start_of_week',
		'date_format',
		'time_format',
		'timezone_string',
		'gmt_offset',
		'permalink_structure',
		'rewrite_rules',
		'active_plugins',
		'template',
		'stylesheet',
		'sidebars_widgets',
		'widget_',
		'theme_mods_',
		'cron',
		'wp_',
		'auto_',
		'fresh_site',
		'db_version',
		'initial_db_version',
		'site_icon',
		'show_on_front',
		'page_on_front',
		'page_for_posts',
		'posts_per_page',
		'default_',
		'comment_',
		'mailserver_',
		'ping_sites',
		'upload_',
		'thumbnail_',
		'medium_',
		'large_',
		'avatar_',
		'close_comments_',
		'thread_comments',
		'comments_per_page',
		'require_name_email',
		'moderation_',
		'disallowed_keys',
		'comment_max_links',
		'uninstall_plugins',
		'recently_activated',
		'finished_',
		'WPLANG',
		'link_manager_enabled',
		'blog_public',
		'category_base',
		'tag_base',
		'current_theme',
		'use_balanceTags',
		'use_smilies',
		'default_role',
		'hack_file',
		'nonce_',
		'auth_',
		'logged_in_',
		'recovery_',
		'can_compress_scripts',
		'html_type',
		'blog_charset',
		'image_default_',
		'_site_transient_',
		'_transient_',
	);

	// Installed but not necessarily active: their options are theirs (inactive owner), not
	// orphans. The active theme and its parent too — Avada's avada_* options came out as
	// orphans of nobody while Avada was running the site.
	$installed = array();
	foreach ( array_keys( wpoptimizing_autoload_installed_slugs() ) as $slug ) {
		$installed[] = $slug;
		$installed[] = str_replace( '-', '_', $slug );
	}

	return array(
		'core'      => $core,
		'plugins'   => $plugins,
		'installed' => array_values( array_unique( array_filter( $installed ) ) ),
	);
}

/**
 * Option-name prefixes that belong to a plugin whose option prefix does not
 * match its folder slug (so the generic active-slug matching would miss them).
 *
 * Map of `option_prefix => owning_plugin_slug`. The owner being among the active
 * plugin slugs decides whether the option is an orphan. Filterable so the set is
 * data-driven, not a hard-coded plugin map: WooCommerce is just the default.
 *
 * @return array<string,string>
 */
function wpoptimizing_autoload_alias_prefixes() {
	/**
	 * Filters the option-prefix → owning-slug aliases used to classify autoload.
	 *
	 * @param array<string,string> $aliases Prefix => owning plugin slug.
	 */
	return (array) apply_filters(
		'wpoptimizing_autoload_alias_prefixes',
		array(
			'woocommerce' => 'woocommerce',
			'wc_'         => 'woocommerce',
		)
	);
}

/**
 * Classify a single autoloaded option name.
 *
 * @param string $name     Option name.
 * @param array  $prefixes Output of wpoptimizing_autoload_known_prefixes().
 * @return array{0:string,1:bool} [component, likely_orphan]
 */
function wpoptimizing_autoload_classify( $name, $prefixes ) {
	$nl = strtolower( (string) $name );

	// WPOptimizing's own options are never orphans — the plugin (and its mu-plugin) are
	// running right now. Guard explicitly so e.g. wpopt_rtmap_sig is never flagged, even if
	// the plugin directory is renamed.
	if ( str_starts_with( $nl, 'wpopt_' ) || str_starts_with( $nl, 'wpoptimizing' ) || str_starts_with( $nl, 'apg_wpopt' ) ) {
		return array( 'wpoptimizing', false );
	}

	// Core: the hand list, then the core registry (sticky_posts, page_comments, show_avatars…
	// and the *_children term caches were otherwise "likely orphans"), and the roles option
	// under any table prefix (apg_user_roles).
	foreach ( $prefixes['core'] as $p ) {
		$p = strtolower( (string) $p );
		if ( $nl === $p || str_starts_with( $nl, $p ) ) {
			return array( 'wordpress-core', false );
		}
	}
	if ( str_ends_with( $nl, '_user_roles' ) || ( class_exists( 'WPOPT_WP_Core_Registry' ) && WPOPT_WP_Core_Registry::is_core( 'option', $nl ) ) ) {
		return array( 'wordpress-core', false );
	}
	$installed = isset( $prefixes['installed'] ) ? (array) $prefixes['installed'] : array();
	// Aliased plugin prefixes (e.g. WooCommerce's wc_* options): the option is an
	// orphan only when its owning plugin is not among the active slugs.
	foreach ( wpoptimizing_autoload_alias_prefixes() as $prefix => $owner_slug ) {
		if ( '' !== (string) $prefix && str_starts_with( $nl, (string) $prefix ) ) {
			$present = in_array( $owner_slug, $prefixes['plugins'], true ) || in_array( $owner_slug, $installed, true );
			return array( (string) $owner_slug, ! $present );
		}
	}
	// Active, then installed plugin/theme prefixes.
	foreach ( array_merge( (array) $prefixes['plugins'], $installed ) as $p ) {
		$p = strtolower( (string) $p );
		if ( '' !== $p && str_starts_with( $nl, $p ) ) {
			return array( $p, false );
		}
	}
	// Transients are not real orphans (they expire); leave neutral.
	if ( str_starts_with( $name, '_transient_' ) || str_starts_with( $name, '_site_transient_' ) ) {
		return array( 'transient', false );
	}
	// Generic owner match: many plugins name their options with a SHORTENED prefix that is
	// not their slug (UpdraftPlus → updraft_*, etc.). If the option's leading token and an
	// installed plugin's leading token are prefixes of one another, treat it as owned — never
	// an orphan. Conservative on purpose (we never auto-delete): better to under-flag.
	$owner = wpoptimizing_autoload_owner_installed( $nl, array_merge( (array) $prefixes['plugins'], $installed ) );
	if ( '' !== $owner ) {
		return array( $owner, false );
	}
	// The prefixes this site's own scan attributed (icl_ → WPML, cmplz_ → Complianz): plugins
	// whose options do not start with their slug. Learned, so no plugin list to keep.
	$token = (string) preg_split( '/[_\-]/', ltrim( $nl, '_' ), 2 )[0];
	if ( isset( $prefixes['learned'][ $token ] ) ) {
		$slug = (string) strtok( (string) $prefixes['learned'][ $token ], '|' ); // The family's most frequent owner.
		return array( $slug, ! in_array( $slug, (array) $prefixes['plugins'], true ) && ! in_array( strtolower( $slug ), $installed, true ) );
	}
	// Unknown prefix and not core/active → likely orphan.
	return array( 'unknown', true );
}

/**
 * Best-effort owning slug for an option whose prefix is a shortened form of an installed
 * plugin's slug (updraft_* ↔ updraftplus, wpopt_* ↔ wpoptimizing). Returns '' when no
 * confident match. Pure (given the installed-slug list).
 *
 * @param string   $name     Lowercased option name.
 * @param string[] $plugins  Installed/active plugin slugs (and underscore variants).
 * @return string Owning slug, or '' if none.
 */
function wpoptimizing_autoload_owner_installed( $name, array $plugins ) {
	$token = (string) preg_split( '/[_\-]/', strtolower( (string) $name ), 2 )[0];
	if ( strlen( $token ) < 5 ) {
		return ''; // Too generic to match safely.
	}
	foreach ( $plugins as $slug ) {
		$slug   = strtolower( (string) $slug );
		$stoken = (string) preg_split( '/[_\-]/', $slug, 2 )[0];
		if ( strlen( $stoken ) < 5 ) {
			continue;
		}
		// One leading token is a prefix of the other: updraft⊂updraftplus, wpopt⊂wpoptimizing.
		if ( 0 === strpos( $stoken, $token ) || 0 === strpos( $token, $stoken ) ) {
			return $slug;
		}
	}
	return '';
}

/**
 * Disable autoload for the given option names (set autoload to 'no'/'off').
 * Premium action.
 *
 * @param string[] $names  Option names.
 * @param string   $op_key Backup operation key ('' = a new one).
 * @return int Number of rows updated.
 */
function wpoptimizing_autoload_disable( array $names, $op_key = '' ) {
	global $wpdb;
	$names = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
	if ( empty( $names ) || ! wpoptimizing_autoload_backup( $names, $op_key ) ) {
		return 0;
	}
	// WP 6.6+ uses 'off'; older uses 'no'. wp_set_option_autoload handles both.
	$updated = 0;
	foreach ( $names as $name ) {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			if ( wp_set_option_autoload( $name, false ) ) {
				++$updated;
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$res = $wpdb->update( $wpdb->options, array( 'autoload' => 'no' ), array( 'option_name' => $name ) );
			if ( false !== $res && $res > 0 ) {
				++$updated;
			}
		}
	}
	wpoptimizing_flush_options_cache();
	// The Performance card IS the autoload weight, and it caches for 5 minutes: without this
	// the user turns options off and the card keeps showing the old size.
	if ( function_exists( 'wpoptimizing_flush_kpi_cache' ) ) {
		wpoptimizing_flush_kpi_cache();
	}
	return $updated;
}
