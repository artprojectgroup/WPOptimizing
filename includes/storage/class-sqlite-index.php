<?php
/**
 * Optional SQLite index for the runtime attribution map.
 *
 * The runtime resolver normally loads the whole apg-runtime-map.csv into PHP
 * arrays — fine for typical sites, heavy on installs with 1M+ rows. This class
 * builds an indexed SQLite mirror so the hot lookups (slug/confidence/context
 * for a tipo+clave) run as primary-key queries without holding the entire map
 * in memory.
 *
 * It is strictly additive and opt-in (filter `wpoptimizing_use_sqlite_index`):
 * the CSV stays the source of truth and the in-memory path is the guaranteed
 * fallback. Semantics mirror WPOPT_Runtime_Resolver exactly — values are
 * lowercased/trimmed and the FIRST row for a (tipo, clave) wins.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPOPT_Sqlite_Index
 */
class WPOPT_Sqlite_Index {
 // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

	/** @var string Database file path. */
	protected $db_path;

	/** @var SQLite3|null Lazily-opened handle. */
	protected $db = null;

	/**
	 * @param string $db_path Path to the SQLite database file.
	 */
	public function __construct( $db_path ) {
		$this->db_path = (string) $db_path;
	}

	/**
	 * Whether SQLite is available in this PHP runtime.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'SQLite3' );
	}

	/**
	 * Open (and remember) the database handle, or null if unavailable.
	 *
	 * @return SQLite3|null
	 */
	protected function connect() {
		if ( $this->db instanceof SQLite3 ) {
			return $this->db;
		}
		if ( ! self::is_available() || '' === $this->db_path ) {
			return null;
		}
		try {
			$db = new SQLite3( $this->db_path );
			$db->busyTimeout( 2000 );
			$db->exec( 'PRAGMA journal_mode = WAL' );
			$db->exec( 'PRAGMA synchronous = NORMAL' );
			$this->db = $db;
			return $this->db;
		} catch ( Throwable $e ) {
			$this->db = null;
			return null;
		}
	}

	/**
	 * Whether the index file already holds data.
	 *
	 * @return bool
	 */
	public function is_built() {
		$db = $this->connect();
		if ( null === $db ) {
			return false;
		}
		try {
			$has = $db->querySingle( "SELECT name FROM sqlite_master WHERE type='table' AND name='rtmap'" );
			if ( ! $has ) {
				return false;
			}
			// An index built by an older reading of the CSV is rebuilt: see build_from_runtime_map().
			if ( (int) $db->querySingle( 'PRAGMA user_version' ) < 2 ) {
				return false;
			}
			return (int) $db->querySingle( 'SELECT COUNT(*) FROM rtmap' ) > 0;
		} catch ( Throwable $e ) {
			return false;
		}
	}

	/**
	 * (Re)build the index from a tab-separated runtime-map CSV.
	 *
	 * First-writer-wins per (tipo, clave) via INSERT OR IGNORE on the primary key,
	 * matching WPOPT_Runtime_Resolver. Values are lowercased/trimmed like the resolver.
	 *
	 * @param string $csv_path Path to apg-runtime-map.csv.
	 * @return int Distinct rows actually indexed (duplicates ignored), or -1 on failure.
	 */
	public function build_from_runtime_map( $csv_path ) {
		if ( ! is_readable( (string) $csv_path ) ) {
			return -1;
		}
		$db = $this->connect();
		if ( null === $db ) {
			return -1;
		}

		// Transparently decrypts the runtime map when it is encrypted at rest.
		$fh = function_exists( 'wpoptimizing_scan_read_handle' )
			? wpoptimizing_scan_read_handle( $csv_path )
			: fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return -1;
		}

		$header = fgetcsv( $fh, 0, "\t", '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return -1;
		}
		$hmap = array_flip( array_map( 'trim', $header ) );
		$ci   = array(
			'slug'      => $hmap['slug'] ?? null,
			'tipo'      => $hmap['tipo'] ?? null,
			'clave'     => $hmap['clave'] ?? null,
			'contexto'  => $hmap['contexto'] ?? null,
			'confianza' => $hmap['confianza'] ?? null,
			'evidencia' => $hmap['evidencia'] ?? null,
		);
		if ( null === $ci['slug'] || null === $ci['tipo'] || null === $ci['clave'] ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return -1;
		}

		try {
			$db->exec( 'DROP TABLE IF EXISTS rtmap' );
			$db->exec( 'CREATE TABLE rtmap ( type TEXT NOT NULL, key TEXT NOT NULL, slug TEXT NOT NULL, confidence TEXT NOT NULL DEFAULT "certain", context TEXT NOT NULL DEFAULT "", PRIMARY KEY ( type, key ) )' );
			$db->exec( 'BEGIN' );
			$stmt = $db->prepare( 'INSERT OR IGNORE INTO rtmap ( type, key, slug, confidence, context ) VALUES ( :t, :k, :s, :f, :c )' );

			$n = 0;
			while ( ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) !== false ) {
				if ( ! is_array( $row ) || count( $row ) < 3 ) {
					continue;
				}
				// Una marca interna del alimentador no es el nombre de una extensión: ver wpoptimizing_non_extension_slugs().
				$slug  = wpoptimizing_shared_slug( $row[ $ci['slug'] ] ?? '' );
				$tipo  = strtolower( trim( (string) ( $row[ $ci['tipo'] ] ?? '' ) ) );
				$clave = strtolower( trim( (string) ( $row[ $ci['clave'] ] ?? '' ) ) );
				if ( '' === $slug || '' === $tipo || '' === $clave ) {
					continue;
				}
				$contexto  = ( null !== $ci['contexto'] ) ? trim( (string) ( $row[ $ci['contexto'] ] ?? '' ) ) : '';
				// Las filas de ocho columnas no traen confianza aunque la cabecera la anuncie: ver WPOPT_Runtime_Resolver::row_confidence().
				$confianza = class_exists( 'WPOPT_Runtime_Resolver' )
					? WPOPT_Runtime_Resolver::row_confidence( $row, $ci['confianza'] )
					: ( ( null !== $ci['confianza'] ) ? trim( (string) ( $row[ $ci['confianza'] ] ?? '' ) ) : 'certain' );
				if ( '' === $confianza ) {
					// Same reading as WPOPT_Runtime_Resolver: a row the producer READ from the code,
					// without seeing it run, is `medium`, never `certain`.
					$evidencia = ( null !== $ci['evidencia'] ) ? strtolower( trim( (string) ( $row[ $ci['evidencia'] ] ?? '' ) ) ) : '';
					$confianza = ( 'scanned' === $evidencia ) ? 'medium' : 'certain';
				}

				$stmt->bindValue( ':t', $tipo, SQLITE3_TEXT );
				$stmt->bindValue( ':k', $clave, SQLITE3_TEXT );
				$stmt->bindValue( ':s', $slug, SQLITE3_TEXT );
				$stmt->bindValue( ':f', $confianza, SQLITE3_TEXT );
				$stmt->bindValue( ':c', $contexto, SQLITE3_TEXT );
				$stmt->execute();
				$stmt->reset();
				$n += $db->changes(); // 1 when inserted, 0 when ignored (duplicate PK).
			}
			$db->exec( 'COMMIT' );
			// 2: confidence read through row_confidence() and `evidencia`, 26/10/2026.
			$db->exec( 'PRAGMA user_version = 2' );
			// Mirror lookups by slug too (cheap secondary index).
			$db->exec( 'CREATE INDEX IF NOT EXISTS idx_slug ON rtmap ( slug )' );
		} catch ( Throwable $e ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return -1;
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $n;
	}

	/**
	 * One indexed row for a tipo+clave, or null. Inputs are lowercased to match
	 * the resolver.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key.
	 * @return array{slug:string,confidence:string,context:string}|null
	 */
	protected function row( $tipo, $clave ) {
		$db = $this->connect();
		if ( null === $db ) {
			return null;
		}
		try {
			$stmt = $db->prepare( 'SELECT slug, confidence, context FROM rtmap WHERE type = :t AND key = :k LIMIT 1' );
			$stmt->bindValue( ':t', strtolower( (string) $tipo ), SQLITE3_TEXT );
			$stmt->bindValue( ':k', strtolower( (string) $clave ), SQLITE3_TEXT );
			$res = $stmt->execute();
			$r   = $res ? $res->fetchArray( SQLITE3_ASSOC ) : false;
			return is_array( $r ) ? $r : null;
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Slug that registered a tipo+clave, or '' if unknown.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key.
	 * @return string
	 */
	public function get_slug( $tipo, $clave ) {
		$r = $this->row( $tipo, $clave );
		return ( $r && isset( $r['slug'] ) ) ? (string) $r['slug'] : '';
	}

	/**
	 * Confidence for a tipo+clave, or 'certain' if unknown.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key.
	 * @return string
	 */
	public function get_confidence( $tipo, $clave ) {
		$r = $this->row( $tipo, $clave );
		return ( $r && ! empty( $r['confidence'] ) ) ? (string) $r['confidence'] : 'certain';
	}

	/**
	 * Context for a tipo+clave, or '' if unknown.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key.
	 * @return string
	 */
	public function get_context( $tipo, $clave ) {
		$r = $this->row( $tipo, $clave );
		return ( $r && isset( $r['context'] ) ) ? (string) $r['context'] : '';
	}

	/**
	 * Export the index back to a tab-separated CSV (same shape as the source).
	 *
	 * @param string $out_path Destination path.
	 * @return bool
	 */
	public function export_csv( $out_path ) {
		$db = $this->connect();
		if ( null === $db ) {
			return false;
		}
		$fh = fopen( (string) $out_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return false;
		}
		try {
			fputcsv( $fh, array( 'slug', 'tipo', 'clave', 'contexto', 'confianza' ), "\t", '"', '\\' );
			$res = $db->query( 'SELECT slug, type, key, context, confidence FROM rtmap ORDER BY slug, type, key' );
			while ( $res && ( $r = $res->fetchArray( SQLITE3_ASSOC ) ) ) {
				fputcsv( $fh, array( $r['slug'], $r['type'], $r['key'], $r['context'], $r['confidence'] ), "\t", '"', '\\' );
			}
		} catch ( Throwable $e ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return true;
	}

	/**
	 * Close the handle.
	 *
	 * @return void
	 */
	public function close() {
		if ( $this->db instanceof SQLite3 ) {
			$this->db->close();
			$this->db = null;
		}
	}
}
