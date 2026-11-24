<?php
/**
 * Redis and MySQL: what their own counters say is wrong, in a sentence somebody can act on.
 *
 * Same idea as the OPcache diagnosis: no gauges to interpret, only findings, each with the
 * setting that fixes it. A server that is fine produces no findings at all.
 *
 * The finders are pure (arrays in, findings out); the collectors only read: INFO from Redis,
 * SHOW GLOBAL VARIABLES / STATUS and the schema size from MySQL. Admin only.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Findings from Redis INFO. Pure.
 *
 * @param array $info INFO as an associative array (all sections flattened).
 * @return array<int,array{code:string,severity:string,data:array}>
 */
function wpoptimizing_redis_findings( array $info ) {
	$out    = array();
	$used   = (float) ( $info['used_memory'] ?? 0 );
	$max    = (float) ( $info['maxmemory'] ?? 0 );
	$policy = strtolower( (string) ( $info['maxmemory_policy'] ?? '' ) );
	$uptime = (int) ( $info['uptime_in_seconds'] ?? 0 );
	$hits   = (float) ( $info['keyspace_hits'] ?? 0 );
	$misses = (float) ( $info['keyspace_misses'] ?? 0 );

	if ( $max > 0 && $used / $max >= 0.9 ) {
		$out[] = array(
			'code'     => 'redis_full',
			'severity' => 'high',
			'data'     => array(
				'used' => (int) $used,
				'max'  => (int) $max,
			),
		);
	}
	if ( 'noeviction' === $policy && $max > 0 ) {
		// Full + noeviction = every write fails: WordPress then works without its cache.
		$out[] = array(
			'code'     => 'redis_noeviction',
			'severity' => 'high',
			'data'     => array(),
		);
	}
	if ( (int) ( $info['evicted_keys'] ?? 0 ) > 0 && $uptime > 0 && ( (int) $info['evicted_keys'] / max( 1, $uptime / 3600 ) ) > 100 ) {
		$out[] = array(
			'code'     => 'redis_evicting',
			'severity' => 'medium',
			'data'     => array( 'per_hour' => (int) round( (int) $info['evicted_keys'] / max( 1, $uptime / 3600 ) ) ),
		);
	}
	if ( $uptime >= 3600 && ( $hits + $misses ) >= 10000 && $hits / ( $hits + $misses ) < 0.8 ) {
		$out[] = array(
			'code'     => 'redis_low_hits',
			'severity' => 'medium',
			'data'     => array( 'ratio' => (int) round( 100 * $hits / ( $hits + $misses ) ) ),
		);
	}
	if ( (int) ( $info['rejected_connections'] ?? 0 ) > 0 ) {
		$out[] = array(
			'code'     => 'redis_rejected',
			'severity' => 'high',
			'data'     => array( 'count' => (int) $info['rejected_connections'] ),
		);
	}
	$frag = (float) ( $info['mem_fragmentation_ratio'] ?? 0 );
	if ( $frag >= 1.5 && $used >= 64 * 1048576 ) {
		$out[] = array(
			'code'     => 'redis_fragmented',
			'severity' => 'low',
			'data'     => array( 'ratio' => round( $frag, 1 ) ),
		);
	}
	return $out;
}

/**
 * Findings from MySQL/MariaDB global variables and status. Pure.
 *
 * @param array $vars       SHOW GLOBAL VARIABLES (name => value), names lower-cased.
 * @param array $status     SHOW GLOBAL STATUS (name => value), names lower-cased.
 * @param int   $innodb_size Data + indexes of this database's InnoDB tables, in bytes.
 * @return array<int,array{code:string,severity:string,data:array}>
 */
function wpoptimizing_mysql_findings( array $vars, array $status, $innodb_size ) {
	$out    = array();
	$pool   = (float) ( $vars['innodb_buffer_pool_size'] ?? 0 );
	$uptime = (int) ( $status['uptime'] ?? 0 );

	if ( $pool > 0 && (float) $innodb_size > $pool ) {
		$out[] = array(
			'code'     => 'mysql_pool_small',
			'severity' => 'high',
			'data'     => array(
				'pool' => (int) $pool,
				'data' => (int) $innodb_size,
			),
		);
	}
	$tmp      = (float) ( $status['created_tmp_tables'] ?? 0 );
	$tmp_disk = (float) ( $status['created_tmp_disk_tables'] ?? 0 );
	if ( $uptime >= 3600 && $tmp >= 1000 && $tmp_disk / $tmp > 0.25 ) {
		$out[] = array(
			'code'     => 'mysql_tmp_disk',
			'severity' => 'medium',
			'data'     => array( 'ratio' => (int) round( 100 * $tmp_disk / $tmp ) ),
		);
	}
	$max_conn  = (float) ( $vars['max_connections'] ?? 0 );
	$used_conn = (float) ( $status['max_used_connections'] ?? 0 );
	if ( $max_conn > 0 && $used_conn / $max_conn >= 0.85 ) {
		$out[] = array(
			'code'     => 'mysql_connections',
			'severity' => 'high',
			'data'     => array(
				'used' => (int) $used_conn,
				'max'  => (int) $max_conn,
			),
		);
	}
	$opened = (float) ( $status['opened_tables'] ?? 0 );
	if ( $uptime >= 3600 && $opened / ( $uptime / 3600 ) > 500 ) {
		$out[] = array(
			'code'     => 'mysql_table_cache',
			'severity' => 'low',
			'data'     => array( 'per_hour' => (int) round( $opened / ( $uptime / 3600 ) ) ),
		);
	}
	$version = (string) ( $vars['version'] ?? '' );
	if ( '' !== $version && wpoptimizing_mysql_version_is_old( $version ) ) {
		$out[] = array(
			'code'     => 'mysql_old',
			'severity' => 'medium',
			'data'     => array( 'version' => $version ),
		);
	}
	return $out;
}

/**
 * Is this server version out of support? MySQL before 8.0, MariaDB before 10.6. Pure.
 *
 * @param string $version e.g. "8.0.36", "10.5.23-MariaDB".
 * @return bool
 */
function wpoptimizing_mysql_version_is_old( $version ) {
	if ( ! preg_match( '/^(\d+)\.(\d+)/', (string) $version, $m ) ) {
		return false;
	}
	$num = (int) $m[1] * 100 + (int) $m[2];
	return false !== stripos( (string) $version, 'mariadb' ) ? $num < 1006 : $num < 800;
}

/**
 * Redis INFO for the active object cache: ours, or the connection of Redis Object Cache.
 * Empty when Redis is not the backend or the server refuses INFO.
 *
 * @return array
 */
function wpoptimizing_redis_info() {
	global $wp_object_cache;
	if ( ! is_object( $wp_object_cache ) ) {
		return array();
	}
	$info = array();
	try {
		if ( method_exists( $wp_object_cache, 'backend_info' ) ) {
			$info = (array) $wp_object_cache->backend_info();
		} elseif ( method_exists( $wp_object_cache, 'redis_instance' ) ) {
			$redis = $wp_object_cache->redis_instance();
			$info  = is_object( $redis ) && method_exists( $redis, 'info' ) ? (array) $redis->info() : array();
		}
	} catch ( \Throwable $e ) {
		return array();
	}
	// phpredis returns sections flattened; Predis nests them by section.
	$flat = array();
	foreach ( $info as $k => $v ) {
		if ( is_array( $v ) ) {
			foreach ( $v as $k2 => $v2 ) {
				$flat[ strtolower( (string) $k2 ) ] = $v2;
			}
		} else {
			$flat[ strtolower( (string) $k ) ] = $v;
		}
	}
	return isset( $flat['redis_version'] ) ? $flat : array();
}

/**
 * MySQL figures: the variables and status counters the finder reads, plus this database's
 * InnoDB size. Admin only; read-only queries.
 *
 * @return array{vars:array,status:array,innodb:int}
 */
function wpoptimizing_mysql_snapshot() {
	global $wpdb;
	$want_vars   = array( 'version', 'innodb_buffer_pool_size', 'max_connections', 'tmp_table_size', 'max_heap_table_size', 'table_open_cache', 'slow_query_log' );
	$want_status = array( 'uptime', 'created_tmp_tables', 'created_tmp_disk_tables', 'max_used_connections', 'opened_tables', 'threads_connected' );
	$vars        = array();
	$status      = array();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- live server counters, read once on an admin screen.
	foreach ( (array) $wpdb->get_results( 'SHOW GLOBAL VARIABLES', ARRAY_N ) as $row ) {
		$name = strtolower( (string) $row[0] );
		if ( in_array( $name, $want_vars, true ) ) {
			$vars[ $name ] = $row[1];
		}
	}
	foreach ( (array) $wpdb->get_results( 'SHOW GLOBAL STATUS', ARRAY_N ) as $row ) {
		$name = strtolower( (string) $row[0] );
		if ( in_array( $name, $want_status, true ) ) {
			$status[ $name ] = $row[1];
		}
	}
	$innodb = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s AND engine = %s', DB_NAME, 'InnoDB' ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array(
		'vars'   => $vars,
		'status' => $status,
		'innodb' => $innodb,
	);
}

/**
 * The sentence for a finding.
 *
 * @param array $f Finding.
 * @return array{title:string,tip:string}
 */
function wpoptimizing_server_finding_text( array $f ) {
	$d = (array) $f['data'];
	switch ( $f['code'] ) {
		case 'redis_full':
			return array(
				/* translators: 1: used memory, 2: memory limit. */
				'title' => sprintf( __( 'Redis is almost full (%1$s of %2$s)', 'wpoptimizing' ), size_format( $d['used'] ), size_format( $d['max'] ) ),
				'tip'   => __( 'When it fills up it starts throwing away keys or refusing writes. Raise maxmemory, or ask your host for a bigger Redis.', 'wpoptimizing' ),
			);
		case 'redis_noeviction':
			return array(
				'title' => __( 'Redis is set to refuse writes when it fills up', 'wpoptimizing' ),
				'tip'   => __( 'With maxmemory-policy noeviction, a full Redis rejects every write and WordPress quietly works without its cache. For a cache, allkeys-lru is the right policy.', 'wpoptimizing' ),
			);
		case 'redis_evicting':
			return array(
				/* translators: %d: keys per hour. */
				'title' => sprintf( __( 'Redis throws away about %d keys an hour to make room', 'wpoptimizing' ), $d['per_hour'] ),
				'tip'   => __( 'Every evicted key is a query that runs again. More memory for Redis makes the cache keep what it already computed.', 'wpoptimizing' ),
			);
		case 'redis_low_hits':
			return array(
				/* translators: %d: hit percentage. */
				'title' => sprintf( __( 'Only %d%% of Redis lookups are hits', 'wpoptimizing' ), $d['ratio'] ),
				'tip'   => __( 'Something is flushing the cache often or keys expire too soon. Check for plugins that flush the whole cache on every save, and that Redis has enough memory.', 'wpoptimizing' ),
			);
		case 'redis_rejected':
			return array(
				/* translators: %d: number of connections. */
				'title' => sprintf( __( 'Redis has refused %d connections', 'wpoptimizing' ), $d['count'] ),
				'tip'   => __( 'It hit maxclients: on those requests the site ran without a cache. Raise maxclients or ask your host.', 'wpoptimizing' ),
			);
		case 'redis_fragmented':
			return array(
				/* translators: %s: fragmentation ratio. */
				'title' => sprintf( __( 'Redis memory is fragmented (%s×)', 'wpoptimizing' ), number_format_i18n( $d['ratio'], 1 ) ),
				'tip'   => __( 'It holds more memory than its data needs. activedefrag yes, or a restart in a quiet hour, gives it back.', 'wpoptimizing' ),
			);
		case 'mysql_pool_small':
			return array(
				/* translators: 1: buffer pool size, 2: data size. */
				'title' => sprintf( __( 'The InnoDB buffer pool (%1$s) is smaller than your data (%2$s)', 'wpoptimizing' ), size_format( $d['pool'] ), size_format( $d['data'] ) ),
				'tip'   => __( 'MySQL cannot keep the tables in memory and reads them from disk. Raise innodb_buffer_pool_size to at least the size of your data, or ask your host.', 'wpoptimizing' ),
			);
		case 'mysql_tmp_disk':
			return array(
				/* translators: %d: percentage. */
				'title' => sprintf( __( '%d%% of MySQL temporary tables go to disk', 'wpoptimizing' ), $d['ratio'] ),
				'tip'   => __( 'Sorting and grouping that does not fit in memory is written to disk. Raise tmp_table_size and max_heap_table_size together.', 'wpoptimizing' ),
			);
		case 'mysql_connections':
			return array(
				/* translators: 1: connections used, 2: maximum. */
				'title' => sprintf( __( 'MySQL has used %1$d of its %2$d connections', 'wpoptimizing' ), $d['used'], $d['max'] ),
				'tip'   => __( 'At the limit, new visits get "Error establishing a database connection". Raise max_connections or find what holds connections open.', 'wpoptimizing' ),
			);
		case 'mysql_table_cache':
			return array(
				/* translators: %d: tables per hour. */
				'title' => sprintf( __( 'MySQL opens about %d tables an hour', 'wpoptimizing' ), $d['per_hour'] ),
				'tip'   => __( 'Its table cache is too small for the number of tables, so it keeps closing and reopening them. Raise table_open_cache.', 'wpoptimizing' ),
			);
		case 'mysql_old':
			return array(
				/* translators: %s: server version. */
				'title' => sprintf( __( 'Your database server (%s) is out of support', 'wpoptimizing' ), $d['version'] ),
				'tip'   => __( 'It no longer receives security fixes and is slower than current versions. Ask your host to move you to MySQL 8 or MariaDB 10.6 or later.', 'wpoptimizing' ),
			);
	}
	return array(
		'title' => (string) $f['code'],
		'tip'   => '',
	);
}
