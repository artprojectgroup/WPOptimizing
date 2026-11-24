<?php
/**
 * Manifest resolver: descarga el manifiesto de archivos por plugin/tema desde
 * wpoptimizing.com y construye un mapa slug → rutas conocidas.
 *
 * Se usa en class-fs-orphans para atribuir archivos físicos a su plugin/tema
 * de origen con certeza del 100% cuando la ruta está en el manifiesto.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Manifest_Resolver {

	/** @var array<string,true>|null slug→ruta_relativa index, null = not loaded yet */
	protected static $path_index = null;

	/** @var array<string,string>|null path→slug reverse map, null = not loaded yet */
	protected static $slug_for_path = null;

	/**
	 * Whitelist of KNOWN extensions: slug → 'plugin'|'theme'.
	 *
	 * Every manifest row lives inside its own owner's folder, so path → slug is derivable
	 * locally. What is NOT derivable is whether a folder belongs to a real, known extension
	 * — and that is the guard that stops a random folder from being attributed (and then
	 * treated as an orphan). The server therefore ships this whitelist instead of a
	 * multi-hundred-MB file list.
	 *
	 * @var array<string,string>|null
	 */
	protected static $known_exts = null;

	/** @var string */
	protected $base_url;

	/** @var string */
	protected $local_dir;

	/** @var int Seconds before re-downloading the manifest. */
	protected $cache_ttl;

	public function __construct() {
		// Vacío a propósito: el host estático está retirado y contesta HTML. El por qué,
		// entero, en class-dataset-resolver.php; el filtro sigue sirviendo para desarrollo.
		$this->base_url  = (string) apply_filters( 'wpoptimizing_dataset_base_url', '' );
		$this->local_dir = (string) apply_filters( 'wpoptimizing_dataset_local_dir', '' );
		$this->cache_ttl = (int) apply_filters( 'wpoptimizing_manifest_cache_ttl', 24 * HOUR_IN_SECONDS );
	}

	/**
	 * Return the slug that owns $rel_path, or empty string if unknown.
	 *
	 * @param string $rel_path Relative path from ABSPATH (e.g. wp-content/plugins/woo/woo.php).
	 * @return string Plugin/theme slug or ''.
	 */
	public function get_slug_for_path( $rel_path ) {
		$this->ensure_loaded();
		$key = strtolower( ltrim( str_replace( '\\', '/', (string) $rel_path ), '/' ) );

		// 1) Exact hit in the file list (older, full manifests still resolve this way).
		if ( isset( self::$slug_for_path[ $key ] ) ) {
			return self::$slug_for_path[ $key ];
		}

		// 2) Derive the owner from the path. Every manifest row lives inside its own
		// owner's folder, so wp-content/plugins/<slug>/… IS owned by <slug> — but only
		// trust it when <slug> is a KNOWN extension, so a random folder is never
		// attributed (and never turned into a deletable orphan).
		if ( preg_match( '#^wp-content/(plugins|themes)/([^/]+)/#', $key, $m ) ) {
			$slug     = $m[2];
			$expected = ( 'themes' === $m[1] ) ? 'theme' : 'plugin';
			if ( isset( self::$known_exts[ $slug ] ) && self::$known_exts[ $slug ] === $expected ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Return true if the manifest has been loaded (even if empty).
	 *
	 * @return bool
	 */
	public function is_loaded() {
		return null !== self::$path_index;
	}

	/**
	 * Olvidar los índices en memoria. NO toca el disco.
	 *
	 * Esto es lo que quiere quien acaba de descargar la capa: que la siguiente atribución lea
	 * el fichero nuevo en lugar de los índices del viejo. Ver delete_local_copy() para lo otro,
	 * que es una cosa distinta y hasta hoy se llamaban igual.
	 *
	 * @return void
	 */
	public static function forget_indexes() {
		self::$path_index    = null;
		self::$slug_for_path = null;
		self::$known_exts    = null;
	}

	/**
	 * BORRAR la copia local, para forzar una descarga nueva. Destructivo, y el nombre lo dice.
	 *
	 * SE LLAMABA `invalidate_cache()`, Y ESE NOMBRE COSTÓ LA CAPA ENTERA. En
	 * wpoptimizing_kb_sync_run() se llamaba JUSTO DESPUÉS de descargar las siete capas, con
	 * este comentario al lado: «the manifest resolver memoises its indexes; drop them so the
	 * freshly downloaded whitelist is what the next attribution actually reads». La intención
	 * era olvidar los índices. Lo que hacía, además, era borrar del disco el fichero que la
	 * línea anterior acababa de traer.
	 *
	 * Resultado medido en producción el 2026-10-08: el manifiesto aparecía a las 10:59:45
	 * —sincronización manual, que no pasa por aquí— y a las 11:05:20 ya no estaba, borrado por
	 * la sincronización automática de después del análisis. La capa no podía sobrevivir a
	 * ninguna sincronización, así que llevaba desde que existe sin llegar a ningún sitio, y la
	 * búsqueda se fue al gateway —donde además faltaba el agregado— en lugar de aquí.
	 *
	 * Es la SEGUNDA vez que esta llamada destruye un dato que alguien acababa de poner: el
	 * docblock de resolvers/kb-layers.php cuenta cómo la gemela de WPOPT_Runtime_Resolver
	 * borraba el mapa que escribe el mu-plugin. Aquello se arregló cambiando los nombres de
	 * los ficheros; el nombre del método, que es lo que engaña a quien lo llama, se quedó.
	 *
	 * @return void
	 */
	public static function delete_local_copy() {
		self::forget_indexes();
		$local = trailingslashit( WPOPT_DIR ) . 'wp-plugin-manifest-distrib.csv';
		if ( file_exists( $local ) ) {
			wp_delete_file( $local );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Ensure both indexes are populated (lazy-load on first use).
	 *
	 * @return void
	 */
	protected function ensure_loaded() {
		if ( null !== self::$path_index ) {
			return;
		}

		self::$path_index    = array();
		self::$slug_for_path = array();
		self::$known_exts    = array();

		$local = $this->ensure_local();
		if ( '' === $local ) {
			return;
		}

		$this->build_indexes( $local );
	}

	/**
	 * Return local manifest path, downloading if stale or missing.
	 *
	 * @return string Local path, or '' if unavailable.
	 */
	protected function ensure_local() {
		// A pathologically large manifest (a mis-generated server dataset can balloon to
		// hundreds of MB) is treated as UNAVAILABLE: we neither re-download it (fetching 100+ MB
		// alone stalls the worker for minutes on every pass) nor parse it. Attribution simply
		// falls back to the other signals. This is the safety valve that stops the file-orphan
		// stage from choking on a broken manifest, regardless of memory/time limits.
		$cap = (int) apply_filters( 'wpoptimizing_manifest_max_bytes', 40 * MB_IN_BYTES );

		// Optional override: point to a local directory (dev/testing).
		if ( '' !== $this->local_dir ) {
			$src = trailingslashit( $this->local_dir ) . 'wp-plugin-manifest-distrib.csv';
			if ( file_exists( $src ) ) {
				return ( $cap > 0 && (int) filesize( $src ) > $cap ) ? '' : $src;
			}
		}

		$local = trailingslashit( WPOPT_DIR ) . 'wp-plugin-manifest-distrib.csv';

		// Already have an oversized copy → never re-download it and never parse it.
		if ( $cap > 0 && file_exists( $local ) && (int) filesize( $local ) > $cap ) {
			return '';
		}

		$stale = ! file_exists( $local );
		if ( ! $stale && $this->cache_ttl > 0 ) {
			$stale = ( time() - filemtime( $local ) ) > $this->cache_ttl;
		}

		if ( $stale && '' !== $this->base_url ) {
			$url = trailingslashit( $this->base_url ) . 'wp-plugin-manifest.csv';
			$this->download( $url, $local );
			// Reject an oversized freshly-downloaded manifest instead of parsing it every run.
			if ( $cap > 0 && file_exists( $local ) && (int) filesize( $local ) > $cap ) {
				return '';
			}
		}

		return file_exists( $local ) ? $local : '';
	}

	/**
	 * Download manifest CSV to local path (best-effort, no fatal on failure).
	 *
	 * @param string $url   Remote URL.
	 * @param string $local Local destination path.
	 * @return void
	 */
	protected function download( $url, $local ) {
		// Preferred: the authenticated gateway (the static datasets host is retired).
		if ( function_exists( 'wpoptimizing_download_kb_file' ) && wpoptimizing_download_kb_file( basename( $local ), $local ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $url, 20 );
		if ( is_wp_error( $tmp ) ) {
			return;
		}
		// Una página web servida con un 200 no es el dataset: ver wpoptimizing_looks_like_csv().
		if ( ! wpoptimizing_looks_like_csv( $tmp ) ) {
			wp_delete_file( $tmp );
			return;
		}

		$dir = dirname( $local );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		/*
		 * copy() writes the destination IN PLACE, so a reader — and every request that resolves
		 * attribution is one — can read it half-copied. Half a dataset is not a smaller
		 * dataset: it is wrong answers about who owns what, on a screen that offers deletion.
		 * Stage beside the destination and rename over it; rename() within one directory is
		 * atomic.
		 */
		$staged = wpoptimizing_atomic_tmp_path( $local );
		if ( ! copy( $tmp, $staged ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			wp_delete_file( $tmp );
			return;
		}
		wp_delete_file( $tmp );
		if ( ! @rename( $staged, $local ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged -- failure leaves the previous copy in place, which is the safe outcome.
			wp_delete_file( $staged );
		}
	}

	/**
	 * Parse the manifest CSV and populate path_index and slug_for_path.
	 *
	 * CSV columns (tab-separated with header):
	 *   slug | version | tipo | ruta_relativa | hash | tamano | extension
	 *
	 * @param string $path Local manifest CSV path.
	 * @return void
	 */
	protected function build_indexes( $path ) {
		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}

		$header = fgetcsv( $fh, 0, "\t", '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}

		// Normalize header and build column index.
		$header = array_map( 'trim', $header );
		$hmap   = array_flip( $header );

		$col_slug = $hmap['slug'] ?? null;
		$col_rel  = $hmap['ruta_relativa'] ?? null;
		$col_tipo = $hmap['tipo'] ?? null;

		if ( null === $col_slug || null === $col_rel ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}

		// HARD BUDGETS. A mis-generated server manifest can balloon to hundreds of MB; parsing
		// it wholesale into these arrays previously exhausted memory / time and hard-fataled the
		// worker (killing the file-orphan stage). Bound the build by rows, wall-clock AND memory
		// so it can NEVER take down the scan — past any cap we simply stop indexing and let
		// attribution fall back to the other signals. All three are filterable.
		$max_rows    = (int) apply_filters( 'wpoptimizing_manifest_max_rows', 500000 );
		$time_budget = (float) apply_filters( 'wpoptimizing_manifest_build_budget', 8.0 );
		$deadline    = microtime( true ) + max( 1.0, $time_budget );
		$mem_limit   = function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0;
		$mem_ceiling = ( $mem_limit > 0 ) ? (int) ( $mem_limit * 0.75 ) : PHP_INT_MAX; // Leave 25% headroom.
		$n           = 0;

		while ( ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) !== false ) {
			if ( count( $row ) !== count( $header ) ) {
				continue;
			}

			$slug = strtolower( trim( (string) ( $row[ $col_slug ] ?? '' ) ) );
			$rel  = strtolower( ltrim( str_replace( '\\', '/', trim( (string) ( $row[ $col_rel ] ?? '' ) ) ), '/' ) );

			if ( '' === $slug || '' === $rel ) {
				continue;
			}

			// Whitelist of known extensions (what the distributable actually ships).
			if ( null !== $col_tipo ) {
				$tipo = strtolower( trim( (string) ( $row[ $col_tipo ] ?? '' ) ) );
				if ( 'plugin' === $tipo || 'theme' === $tipo ) {
					self::$known_exts[ $slug ] = $tipo;
				}
			}

			// slug → [rel_path → true]
			if ( ! isset( self::$path_index[ $slug ] ) ) {
				self::$path_index[ $slug ] = array();
			}
			self::$path_index[ $slug ][ $rel ] = true;

			// rel_path → slug (first writer wins; avoids shared-file collisions)
			if ( ! isset( self::$slug_for_path[ $rel ] ) ) {
				self::$slug_for_path[ $rel ] = $slug;
			}

			// Stop cleanly at whichever budget is reached first (checked every few thousand
			// rows so the checks themselves never become the cost).
			if ( ++$n >= $max_rows ) {
				break;
			}
			if ( 0 === ( $n % 4096 ) ) {
				if ( microtime( true ) > $deadline ) {
					break;
				}
				if ( PHP_INT_MAX !== $mem_ceiling && function_exists( 'memory_get_usage' ) && memory_get_usage( true ) >= $mem_ceiling ) {
					break;
				}
			}
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
