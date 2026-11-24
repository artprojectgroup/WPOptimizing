<?php
/**
 * Dataset resolver: loads public datasets and builds a mapping for orphan linking.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Dataset_Resolver {

	/** @var array<string,array<string,array{origins:string,slugs:string}>>|null */
	protected static $map_cache = null;

	/** @var string */
	protected $base_url;

	/** @var string */
	protected $local_dir;

	/** @var int */
	protected $cache_ttl;

	public function __construct() {
		/*
		 * EL HOST ESTÁTICO SE RETIRA DE VERDAD, y el valor por omisión pasa a vacío.
		 *
		 * `https://wpoptimizing.com/datasets/…` llevaba tiempo descrito en el código como
		 * «retirado», pero seguía siendo el valor por omisión, así que cada vez que el gateway
		 * no sirve una capa —y hoy no sirve dos de siete— esto hacía además una petición con
		 * veinte segundos de espera a una ruta que contesta **403 con una página HTML**.
		 * Comprobado hoy con curl.
		 *
		 * Y el día que ese 403 pase a 200 —basta con que el sitio deje de estar en «Coming
		 * Soon» y un catch-all sirva la portada— `download_url()` daría por buena la descarga,
		 * la página HTML se escribiría encima del dataset y quedaría con fecha de hoy: la capa
		 * parecería fresca y el gateway no se volvería a intentar en 24 horas. El fallo real
		 * tapado un día entero cada vez.
		 *
		 * El filtro se queda, que es lo que permite apuntar a un alimentador local en
		 * desarrollo; lo que se va es el valor por omisión. Con la cadena vacía, ensure_local()
		 * no descarga nada por esta vía y la única fuente es el gateway autenticado.
		 */
		$this->base_url  = (string) apply_filters( 'wpoptimizing_dataset_base_url', '' );
		$this->local_dir = (string) apply_filters( 'wpoptimizing_dataset_local_dir', '' );
		$this->cache_ttl = (int) apply_filters( 'wpoptimizing_dataset_cache_ttl', 12 * HOUR_IN_SECONDS );
	}

	/**
	 * Get a normalized map: type -> value -> ['origins' => 'plugin|theme', 'slugs' => 'slug|...'].
	 *
	 * @return array<string,array<string,array{origins:string,slugs:string}>>
	 */
	public function get_map() {
		if ( null !== self::$map_cache ) {
			return self::$map_cache;
		}

		$map = array(
			'option'    => array(),
			'post_type' => array(),
			'taxonomy'  => array(),
			'role'      => array(),
			'table'     => array(),
		);

		// NOT wp-db-unificado.csv / wp-files-unificado.csv: those are the names of the files
		// THIS SITE generates as its contribution. Downloading the community dataset over them
		// (or, worse, finding them "fresh" and reading OUR OWN contribution as if it were the
		// community's) silently reduced the shared dataset to a mirror of the local one — the
		// single most valuable KB layer, quietly turned into an echo of the site itself.
		$this->add_from_dataset( $this->ensure_dataset( 'wp-db-distrib.csv', 'db' ), $map );
		$this->add_from_dataset( $this->ensure_dataset( 'wp-files-distrib.csv', 'files' ), $map );
		$this->normalize_map( $map );

		self::$map_cache = $map;
		return $map;
	}

	/**
	 * Download dataset file if missing or stale; returns local path if available.
	 *
	 * @param string $filename Dataset filename.
	 * @param string $kind     Identifier for filters/logging.
	 * @return string Local path or empty string.
	 */
	protected function ensure_dataset( $filename, $kind ) {
		if ( '' !== $this->local_dir ) {
			$local_src = trailingslashit( $this->local_dir ) . ltrim( $filename, '/' );
			if ( file_exists( $local_src ) ) {
				return $local_src;
			}
		}

		$local = trailingslashit( WPOPT_DIR ) . $filename;

		$stale = ! file_exists( $local );
		if ( ! $stale && $this->cache_ttl > 0 ) {
			$stale = ( time() - filemtime( $local ) ) > $this->cache_ttl;
		}

		if ( $stale && '' !== $this->base_url ) {
			$url = trailingslashit( $this->base_url ) . ltrim( $filename, '/' );
			$this->download_dataset( $url, $local, $kind );
		}

		return file_exists( $local ) ? $local : '';
	}

	/**
	 * Download a dataset to local path (best-effort).
	 *
	 * @param string $url    Remote URL.
	 * @param string $local  Local path.
	 * @param string $kind   Kind identifier.
	 * @return void
	 */
	protected function download_dataset( $url, $local, $kind ) {
		// Preferred: the authenticated gateway (the static datasets host is retired).
		if ( function_exists( 'wpoptimizing_download_kb_file' ) && wpoptimizing_download_kb_file( basename( $local ), $local ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$tmp = download_url( $url, 15 );
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
		 * "Atomic-ish" was not atomic. copy() writes the destination IN PLACE, so a reader —
		 * and every request that resolves attribution is one — can read it half-copied, and
		 * half a dataset is not a smaller dataset: it is wrong answers about who owns what,
		 * fed to a screen that offers deletion.
		 *
		 * Copy into a unique temp beside the destination, then rename over it. rename() within
		 * one directory is atomic, and Plugin Check accepts it with the annotation the rest of
		 * the plugin already uses for exactly this.
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
	 * Read dataset TSV and populate map by type/value.
	 *
	 * @param string $path TSV path.
	 * @param array  $map  Map reference.
	 * @return void
	 */
	protected function add_from_dataset( $path, array &$map ) {
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}

		$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}

		// Pick the format from the first line: legacy tab+header (named columns) vs
		// the current gateway comma format (positional, no header):
		// [origin_file, bucket, slug, name, tipo, file, dir, tipo_rastreo, func, registro, (confidence)]
		$first = fgets( $fh );
		if ( false === $first ) {
			fclose( $fh ); // phpcs:ignore
			return;
		}
		$is_tab_named = ( false !== strpos( $first, "\t" ) && false !== stripos( $first, 'slug' ) );
		rewind( $fh );

		if ( $is_tab_named ) {
			$header = fgetcsv( $fh, 0, "\t", '"', '\\' );
			$header = array_map(
				static function ( $h ) {
					return strtolower( trim( ltrim( (string) $h, "\xEF\xBB\xBF" ) ) );
				},
				is_array( $header ) ? $header : array()
			);
			$hmap   = array_flip( $header );

			while ( ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) !== false ) {
				if ( count( $row ) !== count( $header ) ) {
					continue;
				}
				$this->add_dataset_row(
					$map,
					$this->col( $row, $hmap, 'slug' ),
					$this->col( $row, $hmap, 'tipo' ),
					$this->col( $row, $hmap, 'tipo_rastreo' ),
					$this->col( $row, $hmap, 'funcion_detectada' ),
					$this->col( $row, $hmap, 'registro_detectado' )
				);
			}
		} else {
			// Positional comma format (mirrors the display-time loader).
			while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
				if ( ! is_array( $row ) || count( $row ) < 10 ) {
					continue;
				}
				$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
				$this->add_dataset_row(
					$map,
					(string) ( $row[2] ?? '' ), // slug
					(string) ( $row[4] ?? '' ), // tipo (plugin|theme)
					(string) ( $row[7] ?? '' ), // tipo_rastreo
					(string) ( $row[8] ?? '' ), // funcion_detectada
					(string) ( $row[9] ?? '' )  // registro_detectado
				);
			}
		}

		fclose( $fh ); // phpcs:ignore
	}

	/**
	 * Add one attribution row to the map (shared by both formats).
	 *
	 * @param array  $map          Map (by reference).
	 * @param string $slug         Plugin/theme slug.
	 * @param string $origin       'plugin'|'theme'.
	 * @param string $type_rastreo Registration type.
	 * @param string $func         Detected function.
	 * @param string $value        Registered value/key.
	 * @return void
	 */
	protected function add_dataset_row( array &$map, $slug, $origin, $type_rastreo, $func, $value ) {
		$origin = strtolower( trim( (string) $origin ) );
		if ( 'plugin' !== $origin && 'theme' !== $origin ) {
			return;
		}
		$mapped = $this->map_type( strtolower( trim( (string) $type_rastreo ) ), strtolower( trim( (string) $func ) ) );
		if ( ! $mapped || ! isset( $map[ $mapped ] ) ) {
			return;
		}
		$key = strtolower( trim( (string) $value ) );
		// Una marca interna del alimentador no es el nombre de una extensión: ver wpoptimizing_non_extension_slugs().
		$slug = sanitize_key( wpoptimizing_shared_slug( $slug ) );
		if ( '' === $key || '' === $slug ) {
			return;
		}
		if ( ! isset( $map[ $mapped ][ $key ] ) ) {
			$map[ $mapped ][ $key ] = array(
				'origins' => array(),
				'slugs'   => array(),
			);
		}
		$map[ $mapped ][ $key ]['origins'][] = $origin;
		$map[ $mapped ][ $key ]['slugs'][]   = $slug;
	}

	/**
	 * Normalize arrays into pipe-separated strings.
	 *
	 * @param array $map Map to normalize (by reference).
	 * @return void
	 */
	protected function normalize_map( array &$map ) {
		foreach ( $map as $type => $items ) {
			foreach ( $items as $key => $meta ) {
				$origins              = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $meta['origins'] ?? array() ) ) ) ) );
				$slugs                = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $meta['slugs'] ?? array() ) ) ) ) );
				$map[ $type ][ $key ] = array(
					'origins' => implode( '|', $origins ),
					'slugs'   => implode( '|', $slugs ),
				);
			}
		}
	}

	/**
	 * Safe column accessor.
	 *
	 * @param array  $row  Row values.
	 * @param array  $hmap Header map.
	 * @param string $key Column name.
	 * @return string
	 */
	protected function col( array $row, array $hmap, $key ) {
		$idx = $hmap[ $key ] ?? null;
		if ( null === $idx || ! isset( $row[ $idx ] ) ) {
			return '';
		}
		return trim( (string) $row[ $idx ] );
	}

	/**
	 * Map dataset type/function to our internal type.
	 *
	 * @param string $type_rastreo Dataset type.
	 * @param string $func         Detected function.
	 * @return string|null
	 */
	protected function map_type( $type_rastreo, $func ) {
		if ( 'option' === $type_rastreo || 'options' === $type_rastreo ) {
			return 'option';
		}
		if ( 'custom_type' === $type_rastreo || 'register_post_type' === $func ) {
			return 'post_type';
		}
		if ( 'register_taxonomy' === $func ) {
			return 'taxonomy';
		}
		if ( 'role' === $type_rastreo || 'roles' === $type_rastreo ) {
			return 'role';
		}
		if ( 'table' === $type_rastreo || 'tables' === $type_rastreo || 'tablas' === $type_rastreo ) {
			return 'table';
		}
		return null;
	}
}
