<?php
/**
 * Autoload knowledge base: consume the curated cross-site autoload aggregate the
 * gateway builds from every install's contribution.
 *
 * The aggregate (wp-autoload-distrib.csv, header
 * `kind,option_name,component,sites,avg_size_bytes,avg_ratio`) carries two crowd
 * signals — both metrics only, never option values:
 *   - kind=orphan : an autoloaded option that, across `sites` installs, belongs to
 *                   an uninstalled extension → crowd-confirmed safe-to-disable.
 *   - kind=read   : a NON-autoloaded option read at `avg_ratio` across `sites`
 *                   installs → the crowd "this should be autoloaded" signal.
 *   - kind=unread : an AUTOLOADED option read at a low `avg_ratio` across `sites`
 *                   installs → the crowd "this should NOT be autoloaded" signal.
 *
 * The parser is pure (offline-testable); the fetcher is a thin WP wrapper.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Parse the curated autoload KB CSV into a lookup keyed by lowercased option name:
 *   array( 'orphan' => array( opt => meta ), 'read' => array( opt => meta ),
 *          'unread' => array( opt => meta ) )
 * where meta = { component, sites, avg_size, avg_ratio }.
 *
 * @param string $csv Raw CSV.
 * @return array{orphan:array<string,array>,read:array<string,array>,unread:array<string,array>}
 */
function wpoptimizing_autoload_kb_parse( $csv ) {
	// The known buckets are always present so callers can rely on them. Any OTHER
	// kind the server introduces in the future is parsed into its own bucket rather
	// than dropped, so a new crowd signal survives on installs whose code does not
	// yet consume it (forward-compatible: parse never has to change to add a kind).
	$out = array(
		'orphan' => array(),
		'read'   => array(),
		'unread' => array(),
	);
	$csv = (string) $csv;
	if ( '' === $csv ) {
		return $out;
	}
	// The shared reader handles BOM, CRLF/LF, blank lines and "#"-comment/metadata
	// lines (so a future "# schema_version: N" header never breaks us) and drops rows
	// with fewer than the 6 canonical columns; extra columns are simply ignored here
	// (and preserved server-side), never an error.
	$rows  = wpoptimizing_csv_parse( $csv, array( 'min_columns' => 6 ) );
	$first = true;
	foreach ( $rows as $row ) {
		if ( $first ) {
			$first = false;
			if ( isset( $row[0] ) && 'kind' === strtolower( trim( (string) $row[0] ) ) ) {
				continue; // header
			}
		}
		$kind = strtolower( trim( (string) $row[0] ) );
		if ( '' === $kind ) {
			continue;
		}
		$opt = strtolower( trim( (string) $row[1] ) );
		if ( '' === $opt ) {
			continue;
		}
		if ( ! isset( $out[ $kind ] ) ) {
			$out[ $kind ] = array();
		}
		$out[ $kind ][ $opt ] = array(
			// Idem: una fila real atribuye `users_can_register`, que es del NÚCLEO, a
			// `valor-compartido`. Vaciarlo deja la opción sin componente, que es la verdad.
			'component' => wpoptimizing_shared_slug( $row[2] ),
			'sites'     => (int) $row[3],
			'avg_size'  => (int) $row[4],
			'avg_ratio' => (float) $row[5],
		);
	}
	return $out;
}

/**
 * Distinct sites that crowd-confirmed an autoloaded option as an orphan, or 0.
 *
 * @param array  $kb     Parsed KB.
 * @param string $option Option name.
 * @return int
 */
function wpoptimizing_autoload_kb_orphan_sites( array $kb, $option ) {
	$opt = strtolower( trim( (string) $option ) );
	return isset( $kb['orphan'][ $opt ]['sites'] ) ? (int) $kb['orphan'][ $opt ]['sites'] : 0;
}

/**
 * Crowd "should-autoload" signal for a (non-autoloaded) option, or null.
 *
 * @param array  $kb     Parsed KB.
 * @param string $option Option name.
 * @return array{component:string,sites:int,avg_size:int,avg_ratio:float}|null
 */
function wpoptimizing_autoload_kb_read_signal( array $kb, $option ) {
	$opt = strtolower( trim( (string) $option ) );
	return isset( $kb['read'][ $opt ] ) ? $kb['read'][ $opt ] : null;
}

/**
 * Crowd "should-NOT-autoload" signal for an (autoloaded) option, or null.
 *
 * @param array  $kb     Parsed KB.
 * @param string $option Option name.
 * @return array{component:string,sites:int,avg_size:int,avg_ratio:float}|null
 */
function wpoptimizing_autoload_kb_unread_signal( array $kb, $option ) {
	$opt = strtolower( trim( (string) $option ) );
	return isset( $kb['unread'][ $opt ] ) ? $kb['unread'][ $opt ] : null;
}

/**
 * Whether the crowd confidently considers an autoloaded option dead weight: enough
 * distinct installs reported it and the aggregate read ratio is at or below the floor.
 *
 * @param array  $kb        Parsed KB.
 * @param string $option    Option name.
 * @param float  $max_ratio Maximum aggregate read ratio to treat as unread (default 0.05).
 * @return bool
 */
function wpoptimizing_autoload_kb_is_unread( array $kb, $option, $max_ratio = 0.05 ) {
	$sig = wpoptimizing_autoload_kb_unread_signal( $kb, $option );
	if ( null === $sig ) {
		return false;
	}
	return wpoptimizing_autoload_kb_is_confident( (int) $sig['sites'] )
		&& (float) $sig['avg_ratio'] <= (float) $max_ratio;
}

/**
 * Whether a crowd signal is trustworthy enough to surface (enough sites).
 *
 * @param int $sites Distinct sites.
 * @return bool
 */
function wpoptimizing_autoload_kb_is_confident( $sites ) {
	/** @since 0.1.0 Distinct sites needed to trust an autoload crowd signal. */
	$threshold = (int) apply_filters( 'wpoptimizing_autoload_kb_threshold', 10 );
	return (int) $sites >= max( 2, $threshold );
}

/**
 * Load + cache the parsed autoload KB for this request, fetching the curated
 * aggregate from the gateway when the local copy is stale (daily). Free + paid.
 *
 * @param bool $force Bypass the in-request memo.
 * @return array{orphan:array<string,array>,read:array<string,array>}
 */
function wpoptimizing_autoload_kb( $force = false ) {
	static $memo = null;
	if ( null !== $memo && ! $force ) {
		return $memo;
	}
	$path  = trailingslashit( WPOPT_DIR ) . 'wp-autoload-distrib.csv';
	$stale = ! file_exists( $path ) || ( time() - (int) filemtime( $path ) ) > DAY_IN_SECONDS;
	if ( $stale && function_exists( 'wpoptimizing_download_kb_file' ) ) {
		wpoptimizing_download_kb_file( 'wp-autoload-distrib.csv', $path );
	}
	$csv  = file_exists( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$memo = wpoptimizing_autoload_kb_parse( $csv );
	return $memo;
}

/**
 * Freshness of a locally cached knowledge-base file, for the UI. Reads the cached CSV's
 * mtime and buckets it green/amber/red so the user can trust (or distrust) the
 * attribution the plugin is showing. Shared by every KB layer.
 *
 * @param string $filename File name inside WPOPT_DIR (e.g. wp-autoload-distrib.csv).
 * @return array{exists:bool,mtime:int,age:int,status:string} status: fresh|aging|stale|missing.
 */
function wpoptimizing_kb_file_freshness( $filename ) {
	$path = trailingslashit( WPOPT_DIR ) . ltrim( (string) $filename, '/' );
	if ( ! file_exists( $path ) ) {
		return array(
			'exists' => false,
			'mtime'  => 0,
			'age'    => 0,
			'status' => 'missing',
		);
	}
	$mtime = (int) filemtime( $path );
	$age   = max( 0, time() - $mtime );
	if ( $age <= DAY_IN_SECONDS ) {
		$status = 'fresh';
	} elseif ( $age <= 7 * DAY_IN_SECONDS ) {
		$status = 'aging';
	} else {
		$status = 'stale';
	}
	return array(
		'exists' => true,
		'mtime'  => $mtime,
		'age'    => $age,
		'status' => $status,
	);
}
