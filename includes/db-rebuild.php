<?php
/**
 * Tables too big to rebuild from a click, rebuilt by WP-Cron instead.
 *
 * «Optimize all» on the WP Total Updater site (28/10/2026) refused three tables of
 * 4.5 GB, 1.7 GB and 635 MB and told the customer to run «wp db query "OPTIMIZE TABLE …"» on the
 * server. Most customers cannot, and those three had almost nothing to give back: rebuilding
 * them would have taken minutes and gigabytes of temporary disk to recover nothing.
 *
 * The free space is estimated, not read. DATA_FREE only counts whole free extents: a local
 * 134 MB table with 90% of its rows deleted reported 5 MB free and shrank to 16 MB once rebuilt.
 * So the live rows are measured instead (a sample of their real length times the row count)
 * and compared with what the table takes. On wptotalupdater that gave 1.0 GB of rows for a
 * 833 MB table and 3.9 GB for a 4.5 GB one: nothing to recover, as the rebuild would have shown.
 *
 * So a big table is first weighed (decided with Emilio, 28/10/2026). With little free space it
 * is skipped and the notice says so, without calling it a failure. With real free space it goes
 * to a queue that WP-Cron works through one table per run, so no browser waits on it. InnoDB is
 * rebuilt online (ALGORITHM=INPLACE, LOCK=NONE): reads and writes carry on meanwhile. If the
 * database cannot do it online, it says so and the table is left alone rather than locked.
 *
 * A run killed by the host (time limit, restart) leaves the table as it was: MySQL rolls the
 * rebuild back. The queue notices it on the next run and reports it instead of retrying forever.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Option holding the queue: {queue: string[], running: {table, since}|null, done: list}.
 */
const WPOPTIMIZING_REBUILD_OPTION = 'wpoptimizing_rebuild_queue';

/**
 * Cron hook that rebuilds the next table.
 */
const WPOPTIMIZING_REBUILD_HOOK = 'wpoptimizing_rebuild_tables';

/**
 * Whether rebuilding a table would recover enough to be worth it. Pure.
 *
 * Worth it when the space a rebuild would give back is at least 10% of the table and at least
 * the minimum (32 MB by default): below that the rebuild costs more than it gives back.
 *
 * @param int $bytes Data + index bytes.
 * @param int $free  Bytes a rebuild would give back (wpoptimizing_rebuild_table_info()).
 * @param int $min   Minimum free bytes.
 * @return bool
 */
function wpoptimizing_rebuild_worth( $bytes, $free, $min ) {
	$free = (int) $free;
	return $free > 0 && $free >= (int) $min && $free * 10 >= (int) $bytes;
}

/**
 * Minimum free space for a big table to be rebuilt.
 *
 * @return int Bytes.
 */
function wpoptimizing_rebuild_min_free() {
	/**
	 * Minimum space a table too big for the admin screen must give back to be rebuilt in
	 * the background. It must also be at least 10% of the table.
	 *
	 * @since 0.1.0
	 * @param int $bytes Default 32 MB.
	 */
	return max( 0, (int) apply_filters( 'wpoptimizing_rebuild_min_free', 32 * MB_IN_BYTES ) );
}

/**
 * The queue, normalised.
 *
 * @return array{queue:string[],running:array|null,done:array}
 */
function wpoptimizing_rebuild_state() {
	$s = get_option( WPOPTIMIZING_REBUILD_OPTION, array() );
	$s = is_array( $s ) ? $s : array();
	return array(
		'queue'   => array_values( array_filter( array_map( 'strval', (array) ( $s['queue'] ?? array() ) ) ) ),
		'running' => is_array( $s['running'] ?? null ) ? $s['running'] : null,
		'done'    => array_values( (array) ( $s['done'] ?? array() ) ),
	);
}

/**
 * Save the queue, keeping the last ten results.
 *
 * @param array $s State.
 * @return void
 */
function wpoptimizing_rebuild_save( array $s ) {
	$s['done'] = array_slice( (array) $s['done'], -10 );
	update_option( WPOPTIMIZING_REBUILD_OPTION, $s, false );
}

/**
 * What the live rows of a table would take once packed. Pure.
 *
 * InnoDB fills pages to 15/16 when it rebuilds, and every row carries about 20 bytes of header.
 *
 * @param int   $rows    Row count.
 * @param float $avg_len Average length of a row's values, from a sample.
 * @return int Bytes.
 */
function wpoptimizing_rebuild_packed_bytes( $rows, $avg_len ) {
	return (int) ceil( max( 0, (int) $rows ) * ( max( 0.0, (float) $avg_len ) + 20 ) * 16 / 15 );
}

/**
 * Average length of a row's values, from the newest and oldest rows.
 *
 * Two thousand rows at most, read by primary key: cheap even on a table of gigabytes.
 *
 * @param string $table Table name, already checked to exist.
 * @return float|null Null when it cannot be measured.
 */
function wpoptimizing_rebuild_avg_row( $table ) {
	global $wpdb;
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$cols = $wpdb->get_col( $wpdb->prepare( 'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY ORDINAL_POSITION', $table ) );
	$pk   = (string) $wpdb->get_var( $wpdb->prepare( "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX LIMIT 1", $table ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( empty( $cols ) ) {
		return null;
	}
	// Every identifier goes in as %i; only the placeholders themselves are put into the SQL.
	$len  = implode( ' + ', array_fill( 0, count( $cols ), 'IFNULL(LENGTH(%i), 0)' ) );
	$avgs = array();
	$args = array_merge( array_values( $cols ), array( $table ) );
	foreach ( '' !== $pk ? array( 'DESC', 'ASC' ) : array( '' ) as $dir ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only placeholders are interpolated.
		if ( 'DESC' === $dir ) {
			$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG({$len}) FROM (SELECT * FROM %i ORDER BY %i DESC LIMIT 1000) s", array_merge( $args, array( $pk ) ) ) );
		} elseif ( 'ASC' === $dir ) {
			$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG({$len}) FROM (SELECT * FROM %i ORDER BY %i ASC LIMIT 1000) s", array_merge( $args, array( $pk ) ) ) );
		} else {
			$avg = $wpdb->get_var( $wpdb->prepare( "SELECT AVG({$len}) FROM (SELECT * FROM %i LIMIT 1000) s", $args ) );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		if ( null !== $avg ) {
			$avgs[] = (float) $avg;
		}
	}
	return $avgs ? array_sum( $avgs ) / count( $avgs ) : null;
}

/**
 * Size, recoverable space and engine of one table, read live.
 *
 * @param string $table Table name.
 * @return array{bytes:int,free:int,engine:string}|null Null when the table does not exist.
 */
function wpoptimizing_rebuild_table_info( $table ) {
	global $wpdb;
	if ( function_exists( 'wpoptimizing_db_live_stats' ) ) {
		wpoptimizing_db_live_stats();
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ENGINE e, DATA_LENGTH d, INDEX_LENGTH i, DATA_FREE f FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ), ARRAY_A );
	if ( ! is_array( $row ) ) {
		return null;
	}
	$data = (int) $row['d'] + (int) $row['f'];
	$free = (int) $row['f'];
	$avg  = wpoptimizing_rebuild_avg_row( $table );
	if ( null !== $avg ) {
		// The exact count, not TABLE_ROWS: InnoDB's estimate can be off by half.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A live count, never cached.
		$rows = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
		$free = max( $free, $data - wpoptimizing_rebuild_packed_bytes( $rows, $avg ) );
	}
	return array(
		'bytes'  => (int) $row['d'] + (int) $row['i'],
		'free'   => max( 0, $free ),
		'engine' => strtolower( (string) $row['e'] ),
	);
}

/**
 * Add tables to the queue and make sure a run is scheduled.
 *
 * The caller has already checked that each table exists and belongs to this site.
 *
 * @param string[] $tables Table names.
 * @return void
 */
function wpoptimizing_rebuild_enqueue( array $tables ) {
	$s       = wpoptimizing_rebuild_state();
	$running = (string) ( $s['running']['table'] ?? '' );
	foreach ( $tables as $t ) {
		$t = (string) $t;
		if ( '' !== $t && $t !== $running && ! in_array( $t, $s['queue'], true ) ) {
			$s['queue'][] = $t;
		}
	}
	wpoptimizing_rebuild_save( $s );
	if ( ! empty( $s['queue'] ) && ! wp_next_scheduled( WPOPTIMIZING_REBUILD_HOOK ) ) {
		wp_schedule_single_event( time() + 5, WPOPTIMIZING_REBUILD_HOOK );
	}
}

/**
 * Whether this database user has a statement on the table still running.
 *
 * @param string $table Table name.
 * @return bool
 */
function wpoptimizing_rebuild_in_progress( $table ) {
	global $wpdb;
	// Without the CONNECTION_ID() condition this query finds itself: its own text has both strings.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$n = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID <> CONNECTION_ID() AND INFO LIKE %s', '%' . $wpdb->esc_like( '`' . $table . '`' ) . '%ENGINE=InnoDB%' ) );
	return (int) $n > 0;
}

/**
 * Rebuild the next table in the queue. Runs from WP-Cron.
 *
 * @return void
 */
function wpoptimizing_run_rebuild_tables() {
	global $wpdb;
	$s = wpoptimizing_rebuild_state();

	if ( null !== $s['running'] ) {
		$table = (string) ( $s['running']['table'] ?? '' );
		if ( '' !== $table && wpoptimizing_rebuild_in_progress( $table ) ) {
			// Still at it in another process: look again later.
			wp_schedule_single_event( time() + 10 * MINUTE_IN_SECONDS, WPOPTIMIZING_REBUILD_HOOK );
			return;
		}
		// The process that was rebuilding it died without saying so. MySQL undid the half-done
		// rebuild, so the table is intact; report it and do not try again.
		$s['done'][]  = array(
			'table' => $table,
			'ok'    => false,
			'code'  => 'interrupted',
			'ts'    => time(),
		);
		$s['running'] = null;
	}

	$table = array_shift( $s['queue'] );
	if ( null === $table ) {
		wpoptimizing_rebuild_save( $s );
		return;
	}
	$s['running'] = array(
		'table' => $table,
		'since' => time(),
	);
	wpoptimizing_rebuild_save( $s );
	// If this process dies, this run finds out what happened and carries on with the rest.
	wp_schedule_single_event( time() + 15 * MINUTE_IN_SECONDS, WPOPTIMIZING_REBUILD_HOOK );

	$result = wpoptimizing_rebuild_one( $table );

	$s            = wpoptimizing_rebuild_state();
	$s['running'] = null;
	$s['done'][]  = $result + array(
		'table' => $table,
		'ts'    => time(),
	);
	wpoptimizing_rebuild_save( $s );
	wp_clear_scheduled_hook( WPOPTIMIZING_REBUILD_HOOK );
	if ( ! empty( $s['queue'] ) ) {
		wp_schedule_single_event( time() + 5, WPOPTIMIZING_REBUILD_HOOK );
	}
}

/**
 * Rebuild one table, re-checking first that it is still worth it.
 *
 * @param string $table Table name.
 * @return array{ok:bool,code?:string,msg?:string,freed?:int}
 */
function wpoptimizing_rebuild_one( $table ) {
	global $wpdb;
	$info = wpoptimizing_rebuild_table_info( $table );
	if ( null === $info ) {
		return array(
			'ok'   => false,
			'code' => 'gone',
		);
	}
	if ( ! wpoptimizing_rebuild_worth( $info['bytes'], $info['free'], wpoptimizing_rebuild_min_free() ) ) {
		return array(
			'ok'   => true,
			'code' => 'not_worth',
		);
	}
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 0 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A rebuild of gigabytes.
	}
	ignore_user_abort( true );

	// Online for InnoDB. Other engines cannot be rebuilt without locking the table, and a
	// locked multi-gigabyte table is a site that stops writing for minutes: not done.
	if ( 'innodb' !== $info['engine'] ) {
		return array(
			'ok'   => false,
			'code' => 'not_innodb',
		);
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Identifier checked against information_schema.
	$ok  = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB, ALGORITHM=INPLACE, LOCK=NONE', $table ) );
	$err = (string) $wpdb->last_error;
	if ( false === $ok || '' !== $err ) {
		return array(
			'ok'   => false,
			'code' => 'db',
			'msg'  => $err,
		);
	}
	$after = wpoptimizing_rebuild_table_info( $table );
	$freed = max( 0, $info['bytes'] - (int) ( $after['bytes'] ?? $info['bytes'] ) );
	if ( function_exists( 'wpoptimizing_audit_log' ) ) {
		wpoptimizing_audit_log(
			'table_rebuilt',
			array(
				'table' => $table,
				'freed' => $freed,
			)
		);
	}
	return array(
		'ok'    => true,
		'freed' => $freed,
	);
}

/**
 * Why a rebuild did not happen, in the language of whoever reads it. Kept as a code in the
 * queue because WP-Cron runs in the site's language and the screen in the user's.
 *
 * @param array $d One result from the queue.
 * @return string
 */
function wpoptimizing_rebuild_reason( array $d ) {
	switch ( (string) ( $d['code'] ?? '' ) ) {
		case 'interrupted':
			return __( 'the server stopped the rebuild before it finished; the table was left as it was', 'wpoptimizing' );
		case 'gone':
			return __( 'the table no longer exists', 'wpoptimizing' );
		case 'not_worth':
			return __( 'nothing worth recovering any more; skipped', 'wpoptimizing' );
		case 'not_innodb':
			return __( 'only InnoDB tables are rebuilt in the background, because others would be locked meanwhile', 'wpoptimizing' );
	}
	return '' !== (string) ( $d['msg'] ?? '' ) ? (string) $d['msg'] : __( 'rejected by the database', 'wpoptimizing' );
}

add_action( WPOPTIMIZING_REBUILD_HOOK, 'wpoptimizing_run_rebuild_tables' );
