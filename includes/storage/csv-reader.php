<?php
/**
 * Shared, robust CSV/TSV parsing primitive.
 *
 * One place that handles the tricky bits every reader in the plugin used to repeat:
 * a UTF-8 BOM, CRLF/CR/LF line endings, blank lines, "#"-comment/metadata lines, and
 * quoted fields. Resolvers and the knowledge-base parser build on top of this instead
 * of re-implementing line splitting, so behaviour (and bug fixes) live in one spot.
 *
 * Pure and side-effect free, so it is fully unit-testable offline.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Neutralise spreadsheet formula injection in one exported CSV cell.
 *
 * Excel, LibreOffice and Google Sheets evaluate a cell that starts with =, +, - or @ (and
 * treat a leading tab/CR as whitespace before those). Our exports carry option names, meta
 * keys, file paths and site names written by OTHER plugins, so a hostile value can reach
 * the file. Prefixing a single quote keeps the text readable and stops the evaluation.
 *
 * Only for files a human downloads: the plugin's own scan CSVs are read back by the
 * attribution engine and must round-trip byte for byte.
 *
 * @since 0.1.0
 * @param mixed $value Cell value.
 * @return string
 */
function wpoptimizing_csv_safe_cell( $value ) {
	$value = (string) $value;
	if ( '' === $value ) {
		return $value;
	}
	$probe = ltrim( $value, "\t\r\n " );
	if ( '' === $probe ) {
		return $value;
	}
	return ( false !== strpos( '=+-@', $probe[0] ) ) ? "'" . $value : $value;
}

/**
 * Apply wpoptimizing_csv_safe_cell() to every cell of an exported row.
 *
 * @since 0.1.0
 * @param array $row Row.
 * @return array
 */
function wpoptimizing_csv_safe_row( array $row ) {
	return array_map( 'wpoptimizing_csv_safe_cell', $row );
}

/**
 * Parse delimited text into rows.
 *
 * @param string $text Raw CSV/TSV text.
 * @param array  $opts {
 *     Optional settings.
 *     @type string $delimiter   Field delimiter. Default ",".
 *     @type string $enclosure   Field enclosure. Default '"'.
 *     @type string $comment     Lines starting with this (after trim) are skipped.
 *                               Default "#". Pass "" to disable comment skipping.
 *     @type bool   $header      When true, the first non-comment row is treated as a
 *                               header and each data row is returned as an associative
 *                               array keyed by the lowercased, trimmed header names
 *                               (short rows padded with "", extra columns ignored).
 *                               Default false (numeric arrays).
 *     @type int    $min_columns Drop rows with fewer than this many columns. Default 0.
 * }
 * @return array<int,array> Parsed rows (numeric arrays, or associative when header=true).
 */
function wpoptimizing_csv_parse( $text, array $opts = array() ) {
	$text = (string) $text;
	if ( '' === $text ) {
		return array();
	}

	$delimiter = isset( $opts['delimiter'] ) ? (string) $opts['delimiter'] : ',';
	$enclosure = isset( $opts['enclosure'] ) ? (string) $opts['enclosure'] : '"';
	$comment   = array_key_exists( 'comment', $opts ) ? (string) $opts['comment'] : '#';
	$header    = ! empty( $opts['header'] );
	$min_cols  = isset( $opts['min_columns'] ) ? (int) $opts['min_columns'] : 0;

	// Strip a UTF-8 BOM so the first line (often the header) parses cleanly.
	$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
	// Normalize line endings to LF so fgetcsv() splits rows consistently (it does
	// not treat a lone CR as a row break). A quoted field's embedded LF is kept.
	$text = str_replace( array( "\r\n", "\r" ), "\n", $text );

	// Strip comment lines on the RAW line (before CSV parsing), and only at the top
	// level — never inside a quoted field. Doing this post-parse would wrongly drop a
	// legit quoted value like "#handle". Quote state is tracked by enclosure parity.
	if ( '' !== $comment && '' !== $enclosure ) {
		$kept     = array();
		$in_quote = false;
		foreach ( explode( "\n", $text ) as $line ) {
			if ( ! $in_quote && 0 === strncmp( ltrim( $line ), $comment, strlen( $comment ) ) ) {
				continue;
			}
			$kept[] = $line;
			if ( 1 === ( substr_count( $line, $enclosure ) & 1 ) ) {
				$in_quote = ! $in_quote; // Odd number of quotes on the line flips state.
			}
		}
		$text = implode( "\n", $kept );
	}

	$rows    = array();
	$keys    = null; // header map (when $header)
	$is_head = $header;

	// Parse with fgetcsv over an in-memory stream so quoted fields that contain
	// newlines stay a single field (a per-line split would break them).
	$fh = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream; WP_Filesystem has no CSV reader.
	if ( false === $fh ) {
		return array();
	}
	fwrite( $fh, $text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory stream.
	rewind( $fh );

	// Empty escape => strict RFC 4180 (only "" escapes a quote). This matches how the
	// plugin WRITES its CSV (str_replace('"','""')) and keeps fgetcsv() in sync with the
	// quote-parity comment pre-strip above, which only counts enclosures. A backslash
	// escape would desync the two and could drop a "#"-line living inside a quoted field.
	while ( false !== ( $fields = fgetcsv( $fh, 0, $delimiter, $enclosure, '' ) ) ) {
		// Skip blank lines (fgetcsv yields array(null) for an empty record).
		if ( null === $fields || ( 1 === count( $fields ) && ( null === $fields[0] || '' === trim( (string) $fields[0] ) ) ) ) {
			continue;
		}
		// Comment lines were already removed above (quote-aware, on the raw line).

		if ( $is_head ) {
			$keys    = array_map(
				static function ( $h ) {
					return strtolower( trim( (string) $h ) );
				},
				$fields
			);
			$is_head = false;
			continue;
		}

		if ( $min_cols > 0 && count( $fields ) < $min_cols ) {
			continue;
		}

		if ( $header && is_array( $keys ) ) {
			$assoc = array();
			foreach ( $keys as $i => $k ) {
				if ( '' === $k ) {
					continue;
				}
				$assoc[ $k ] = isset( $fields[ $i ] ) ? $fields[ $i ] : '';
			}
			$rows[] = $assoc;
		} else {
			$rows[] = $fields;
		}
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.

	return $rows;
}

/**
 * Central registry of the plugin's CSV schemas — a single source of truth for the
 * column header, the schema version and the allowed signal kinds, so these never
 * drift across the contribution builder, the KB consumer, tests and docs.
 *
 * Bump the 'version' when a column's MEANING changes; ADDING a trailing column does
 * not need a bump because every reader ignores extra columns (forward-compatible).
 *
 * @param string $key Schema key, e.g. 'autoload_contribution' or 'autoload_kb'.
 * @return array{version:int,header:string,columns:string[],kinds:string[]}|null Null if unknown.
 */
function wpoptimizing_csv_schema( $key ) {
	$schemas = array(
		// What each install UPLOADS (per-site measured signals).
		'autoload_contribution' => array(
			'version' => 1,
			'columns' => array( 'kind', 'option_name', 'size_bytes', 'reads', 'requests', 'ratio', 'component' ),
			'kinds'   => array( 'read', 'unread', 'orphan' ),
		),
		// What the gateway SERVES back (curated cross-site aggregate).
		'autoload_kb'           => array(
			'version' => 1,
			'columns' => array( 'kind', 'option_name', 'component', 'sites', 'avg_size_bytes', 'avg_ratio' ),
			'kinds'   => array( 'orphan', 'read', 'unread' ),
		),
	);
	if ( ! isset( $schemas[ (string) $key ] ) ) {
		return null;
	}
	$s           = $schemas[ (string) $key ];
	$s['header'] = implode( ',', $s['columns'] );
	return $s;
}

/**
 * ¿Lo descargado es un CSV, o una página web servida como si lo fuera?
 *
 * Un CSV nunca empieza por `<`. La descarga autenticada del gateway ya lo comprobaba, y las
 * tres descargas de respaldo de los resolutores no: una portada o una pantalla de error
 * servida con un 200 se escribía encima del dataset y quedaba con fecha de hoy, así que la
 * capa parecía fresca y no se volvía a intentar en veinticuatro horas. Medio dataset no es un
 * dataset más pequeño: son respuestas equivocadas sobre de quién es qué, en una pantalla que
 * ofrece borrar.
 *
 * Vive aquí, con el resto de la lectura de CSV, y este fichero se carga siempre: los tres
 * resolutores que lo usan se construyen en contextos distintos.
 *
 * @param string $path Fichero recién descargado.
 * @return bool True cuando parece un CSV y se puede usar.
 */
function wpoptimizing_looks_like_csv( $path ) {
	$path = (string) $path;
	if ( '' === $path || ! is_readable( $path ) ) {
		return false;
	}
	$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $fh ) {
		return false;
	}
	$inicio = (string) fread( $fh, 512 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Sniffs the first 512 bytes of our own CSV; WP_Filesystem has no partial read.
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	$inicio = ltrim( $inicio );
	if ( '' === $inicio ) {
		return false; // Un fichero vacío tampoco sirve, y antes se guardaba igual.
	}
	return '<' !== $inicio[0];
}
