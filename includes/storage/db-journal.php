<?php
/**
 * Fold the query-analytics journal into its aggregate — on admin_init, never on a visit.
 *
 * The db.php drop-in used to do this work itself at the end of every request that made a single
 * read, anonymous front-end visits included: open the aggregate JSON, take a BLOCKING
 * flock(LOCK_EX), decode the whole file, recompute a rolling top-20 and rewrite it. That is a
 * serialised critical section with file I/O on the visitor's path — under real traffic,
 * concurrent requests queue on one inode, and the plugin's own rule says this kind of work
 * belongs on admin_init and never where a visitor can pay for it.
 *
 * So the drop-in appends one line and stops. Everything expensive happens here, where a lock
 * costs nobody anything, and the aggregate's shape is unchanged so every existing reader keeps
 * working.
 *
 * Sampling was the alternative and was rejected: keeping one request in twenty would throw away
 * precisely the per-component fidelity ("which plugin duplicates queries") that the whole
 * feature exists to report.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where the drop-in appends. Mirrors wpopt_db_journal_file() in the drop-in, which cannot be
 * called from here: the drop-in is standalone and may be absent or of an older version.
 *
 * @since 0.1.0
 * @return string
 */
function wpoptimizing_db_journal_path() {
	$dir = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR : WP_CONTENT_DIR . '/cache';

	return $dir . '/wpopt-db-journal.log';
}

/**
 * Fold one decoded journal row into the aggregate. Pure, so the arithmetic is testable.
 *
 * @since 0.1.0
 * @param array $data Aggregate.
 * @param array $row  One journal row.
 * @return array The aggregate.
 */
function wpoptimizing_db_journal_fold( array $data, array $row ) {
	$day = isset( $row['d'] ) ? preg_replace( '/[^0-9]/', '', (string) $row['d'] ) : '';
	if ( '' === $day ) {
		return $data;
	}
	if ( empty( $data['daily'] ) || ! is_array( $data['daily'] ) ) {
		$data['daily'] = array();
	}
	$b         = ( isset( $data['daily'][ $day ] ) && is_array( $data['daily'][ $day ] ) ) ? $data['daily'][ $day ] : array();
	$b['q']    = (int) ( $b['q'] ?? 0 ) + (int) ( $row['q'] ?? 0 );
	$b['dup']  = (int) ( $b['dup'] ?? 0 ) + (int) ( $row['dup'] ?? 0 );
	$b['slow'] = (int) ( $b['slow'] ?? 0 ) + (int) ( $row['slow'] ?? 0 );
	$b['ms']   = (int) ( $b['ms'] ?? 0 ) + (int) ( $row['ms'] ?? 0 );
	/*
	 * `h`, `m` y `req` las escribe el drop-in object-cache.php (filas `t:oc`), que hasta su v9
	 * fundía el agregado él mismo con un flock bloqueante en cada petición. Se plegan aquí
	 * igual que las demás: son contadores y una fila que no los traiga suma cero.
	 */
	foreach ( array( 'sel', 'ins', 'upd', 'del', 'h', 'm', 'req' ) as $tk ) {
		$add = (int) ( $row[ $tk ] ?? 0 );
		if ( $add ) {
			$b[ $tk ] = (int) ( $b[ $tk ] ?? 0 ) + $add;
		}
	}
	$data['daily'][ $day ] = $b;
	if ( count( $data['daily'] ) > 30 ) {
		ksort( $data['daily'] );
		$data['daily'] = array_slice( $data['daily'], -30, null, true );
	}

	// Rolling top-20 duplicated queries (highest single-request count wins), exactly as before.
	if ( ! empty( $row['x'] ) && is_array( $row['x'] ) ) {
		$dupmap = ( isset( $data['dups'] ) && is_array( $data['dups'] ) ) ? $data['dups'] : array();
		foreach ( $row['x'] as $d ) {
			if ( ! is_array( $d ) || empty( $d['s'] ) ) {
				continue;
			}
			$k = md5( (string) $d['s'] );
			if ( ! isset( $dupmap[ $k ] ) || (int) $dupmap[ $k ]['count'] < (int) $d['c'] ) {
				$dupmap[ $k ] = array(
					'sql'   => (string) $d['s'],
					'count' => (int) $d['c'],
					'comp'  => (string) ( $d['p'] ?? '' ),
				);
			}
		}
		uasort(
			$dupmap,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		$data['dups'] = array_slice( $dupmap, 0, 20, true );
	}

	// Rolling duplicate-hits-avoided by owning plugin/theme.
	if ( ! empty( $row['c'] ) && is_array( $row['c'] ) ) {
		$comp = ( isset( $data['dupe_components'] ) && is_array( $data['dupe_components'] ) ) ? $data['dupe_components'] : array();
		foreach ( $row['c'] as $name => $n ) {
			$name          = (string) $name;
			$comp[ $name ] = (int) ( $comp[ $name ] ?? 0 ) + (int) $n;
		}
		arsort( $comp );
		$data['dupe_components'] = array_slice( $comp, 0, 50, true );
	}

	return $data;
}

/**
 * Turn one DDL journal row into runtime evidence.
 *
 * This is the hole the snapshot-diff attribution cannot close on its own: a plugin that creates
 * its table weeks after activation — a deferred migration, a module that installs on first use
 * — shows up in the diff as a new element with no author. No author means a low confidence
 * level, and low confidence is excluded from automatic cleanup, so it stays as database weight
 * the product cannot remove.
 *
 * The drop-in took a backtrace at the moment of the CREATE, which is free there because DDL is
 * vanishingly rare in a request. Recording it as runtime evidence puts it on the same footing
 * as any other backtrace-derived attribution: certain, and usable by the cleaner.
 *
 * @since 0.1.0
 * @param array $row One decoded journal row with t === 'ddl'.
 * @return int Statements recorded.
 */
function wpoptimizing_db_journal_record_ddl( array $row ) {
	if ( ! class_exists( 'WPOPT_Runtime_Evidence' ) || empty( $row['r'] ) || ! is_array( $row['r'] ) ) {
		return 0;
	}
	$n = 0;
	foreach ( $row['r'] as $ddl ) {
		if ( ! is_array( $ddl ) || empty( $ddl['tbl'] ) ) {
			continue;
		}
		$table = strtolower( trim( (string) $ddl['tbl'] ) );
		$comp  = (string) ( $ddl['comp'] ?? '' );
		if ( '' === $table ) {
			continue;
		}
		// "plugin:woocommerce" / "theme:avada" / "mu:..." / "core". Only an extension is an
		// owner worth recording: core creating its own tables tells us nothing, and an
		// unparseable component would be worse than no attribution.
		$origin = '';
		$slug   = '';
		if ( preg_match( '/^(plugin|theme):(.+)$/', $comp, $m ) ) {
			$origin = $m[1];
			$slug   = $m[2];
		}
		if ( '' === $slug ) {
			continue;
		}
		WPOPT_Runtime_Evidence::record_db_value( 'tables', $table, $origin, $slug );
		++$n;
	}

	return $n;
}

/**
 * Consume the journal and fold it into the aggregate.
 *
 * Rename-then-read: the journal is moved aside before it is parsed, so requests arriving during
 * the fold append to a fresh file instead of writing into one being consumed. Whatever the fold
 * then does, no visitor waits for it.
 *
 * @since 0.1.0
 * @return int Rows folded.
 */
function wpoptimizing_db_journal_consolidate() {
	$journal = wpoptimizing_db_journal_path();
	if ( ! is_file( $journal ) || filesize( $journal ) < 1 ) {
		return 0;
	}
	$taking = $journal . '.' . wp_generate_password( 6, false ) . '.taking';
	if ( ! @rename( $journal, $taking ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic hand-off of the journal on purpose (WP_Filesystem::move() is not atomic); a lost race just means the next pass takes it.
		return 0;
	}

	$raw = (string) file_get_contents( $taking ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Our own journal in the cache dir.
	wp_delete_file( $taking );
	if ( '' === trim( $raw ) ) {
		return 0;
	}

	$stats = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR . '/wpopt-cache-stats.json' : WP_CONTENT_DIR . '/cache/wpopt-cache-stats.json';
	$data  = array();
	if ( is_file( $stats ) ) {
		$decoded = json_decode( (string) file_get_contents( $stats ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Our own aggregate.
		if ( is_array( $decoded ) ) {
			$data = $decoded;
		}
	}

	$n = 0;
	foreach ( explode( "\n", $raw ) as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		$row = json_decode( $line, true );
		// A torn line (two appends that interleaved) simply does not decode. Skipping it loses
		// one sample, which is the cost this design accepts in exchange for never blocking.
		if ( ! is_array( $row ) ) {
			continue;
		}
		// Schema changes are attribution evidence, not cache statistics; they do not belong in
		// the aggregate and must not be folded into its counters.
		if ( isset( $row['t'] ) && 'ddl' === $row['t'] ) {
			wpoptimizing_db_journal_record_ddl( $row );
			++$n;
			continue;
		}
		$data = wpoptimizing_db_journal_fold( $data, $row );
		++$n;
	}

	if ( $n > 0 ) {
		$json = wp_json_encode( $data );
		if ( is_string( $json ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Writes the cache-analytics aggregate into wp-content/cache, not the plugin folder; it is a cache artefact and is expected to be disposable.
			file_put_contents( $stats, $json, LOCK_EX );
		}
	}

	return $n;
}
add_action( 'admin_init', 'wpoptimizing_db_journal_consolidate' );
