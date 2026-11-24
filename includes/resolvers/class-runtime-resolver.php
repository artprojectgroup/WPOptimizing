<?php
/**
 * Runtime resolver: downloads apg-runtime-map.csv from wpoptimizing.com
 * and builds in-memory indexes for fast attribution lookups.
 *
 * Evidence certainty: 100% — the runtime map records were captured live by
 * the mu-plugin at the exact moment each item was registered in WordPress.
 *
 * CSV columns (tab-separated):
 *   slug | tipo | clave | contexto | plugin_version | wp_version | first_seen | last_seen
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Runtime_Resolver {

	/**
	 * Option holding, per meta table, the key the next scan resumes after.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const META_CURSOR_OPTION = 'wpoptimizing_rtmap_meta_cursor';

	/**
	 * Primary index: [ tipo ][ clave ] → slug
	 *
	 * @var array<string,array<string,string>>|null null = not loaded yet
	 */
	protected static $slug_for_key = null;

	/**
	 * Secondary index: [ slug ][ tipo ] → [ clave → contexto ]
	 *
	 * @var array<string,array<string,array<string,string>>>|null
	 */
	protected static $keys_for_slug = null;

	/**
	 * Índice de clase: [ tipo ][ clave ] → clase del dueño.
	 *
	 * La capa decía de QUIÉN es una clave y nunca de qué clase era ese quién. El cliente lo
	 * deducía del índice de lo instalado, que sirve para lo instalado y NO para un huérfano,
	 * que por definición ya no está — y de ahí salían todos los huérfanos pintados como temas.
	 * Desde el 9/10/2026 la capa trae la clase puesta por quien la midió.
	 *
	 * @var array<string,array<string,string>>|null
	 */
	protected static $clase_for_key = null;

	/**
	 * Confidence index: [ tipo ][ clave ] → confianza
	 *
	 * @var array<string,array<string,string>>|null
	 */
	protected static $confianza_for_key = null;

	/** @var string Remote base URL (filterable). */
	protected $base_url;

	/** @var string Optional local override directory for dev/testing. */
	protected $local_dir;

	/** @var int Seconds before re-downloading. */
	protected $cache_ttl;

	/** @var string Local CSV path. */
	protected $local_path;

	/** @var WPOPT_Sqlite_Index|false|null Lazy: null=untried, false=off/failed, instance=ready. */
	protected $sqlite = null;

	/**
	 * Claves que reclama más de un slug distinto: tipo → clave → slug → true (todos los que la
	 * reclaman, el primero incluido).
	 *
	 * @var array<string,array<string,array<string,bool>>>
	 */
	protected static $disputada = array();

	/**
	 * Veredicto memoizado de reclamacion_sostenida(): tipo → clave → bool.
	 *
	 * @var array<string,array<string,bool>>
	 */
	protected static $sostenida = array();

	/**
	 * Claves de cada slug en lista plana, memoizadas: slug → [clave, …].
	 *
	 * @var array<string,array<int,string>>
	 */
	protected static $hermanas = array();

	/**
	 * @return void
	 */
	public function __construct() {
		/*
		 * If the mu-plugin is installed it writes directly to the local CSV path. In that case
		 * the file is always authoritative: skip remote download entirely.
		 *
		 * Y SE LE PREGUNTA A LA CONSTANTE DÓNDE ESCRIBE. Esto comprobaba que
		 * `APG_WPOPT_RTMAP_CSV` estuviera definida y acto seguido leía una ruta escrita a mano,
		 * que es la misma que la constante vale hoy — pero la constante existe precisamente
		 * para poder valer otra cosa. Con un WP_CONTENT_DIR movido, el mu-plugin escribía su
		 * mapa en un sitio y esto lo buscaba en otro: no fallaba, se iba a descargar el mapa
		 * compartido y el sitio perdía en silencio su única fuente de confianza `certain`.
		 */
		$mupl_csv = defined( 'APG_WPOPT_RTMAP_CSV' )
			? (string) APG_WPOPT_RTMAP_CSV
			: WP_CONTENT_DIR . '/wpoptimizing/apg-runtime-map.csv';
		if ( '' !== $mupl_csv && ( defined( 'APG_WPOPT_RTMAP_CSV' ) || file_exists( $mupl_csv ) ) ) {
			$this->base_url   = '';
			$this->local_dir  = '';
			$this->cache_ttl  = 0;
			$this->local_path = $mupl_csv;
			return;
		}
		// Fallback: download from wpoptimizing.com (no local mu-plugin).
		// Vacío a propósito: el host estático está retirado y contesta HTML. El por qué,
		// entero, en class-dataset-resolver.php; el filtro sigue sirviendo para desarrollo.
		$this->base_url   = (string) apply_filters( 'wpoptimizing_dataset_base_url', '' );
		$this->local_dir  = (string) apply_filters( 'wpoptimizing_dataset_local_dir', '' );
		$this->cache_ttl  = (int) apply_filters( 'wpoptimizing_runtime_map_cache_ttl', 24 * HOUR_IN_SECONDS );
		$this->local_path = trailingslashit( WPOPT_DIR ) . 'apg-runtime-map-distrib.csv';
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Optional SQLite-backed lookups. Returns an index instance when the
	 * `wpoptimizing_use_sqlite_index` filter is on, SQLite is available, and the
	 * index can be built/refreshed from the CSV; otherwise null (CSV fallback).
	 * Rebuilds when the CSV is newer than the index. Default off → no-op.
	 *
	 * @return WPOPT_Sqlite_Index|null
	 */
	protected function sqlite_index() {
		if ( null !== $this->sqlite ) {
			return $this->sqlite ? $this->sqlite : null;
		}
		$enabled = (bool) apply_filters( 'wpoptimizing_use_sqlite_index', false );
		if ( ! $enabled || ! class_exists( 'WPOPT_Sqlite_Index' ) || ! WPOPT_Sqlite_Index::is_available() ) {
			$this->sqlite = false;
			return null;
		}
		$dir  = defined( 'WPOPT_DIR' ) ? WPOPT_DIR : dirname( $this->local_path );
		$path = trailingslashit( $dir ) . 'runtime-map.sqlite';
		$csv  = $this->ensure_local();
		$idx  = new WPOPT_Sqlite_Index( $path );

		$need_build = ! $idx->is_built();
		if ( ! $need_build && '' !== $csv && file_exists( $path ) && file_exists( $csv ) && filemtime( $csv ) > filemtime( $path ) ) {
			$need_build = true; // CSV changed since the index was built.
		}
		if ( $need_build && ( '' === $csv || $idx->build_from_runtime_map( $csv ) < 0 ) ) {
			$this->sqlite = false;
			return null; // Fall back to the in-memory CSV path.
		}
		$this->sqlite = $idx;
		return $idx;
	}

	/**
	 * Return the slug that registered $clave of $tipo, or '' if unknown.
	 *
	 * @param string $tipo  Item type (post_type, taxonomy, option, shortcode…).
	 * @param string $clave Item key/name.
	 * @return string Plugin/theme slug or ''.
	 */
	public function get_slug_for_key( $tipo, $clave ) {
		$tipo  = strtolower( (string) $tipo );
		$clave = strtolower( (string) $clave );

		/*
		 * DOS COSAS QUE ESTE MAPA NO PUEDE CONTESTAR, y antes contestaba igualmente con
		 * confianza `certain` — que es el nivel que deja borrar.
		 *
		 * UNA CAPACIDAD NO TIENE DUEÑO POR EJECUCIÓN. `add_cap()` lo llama cualquiera, y lo que
		 * el mu-plugin captura es quién se estaba ejecutando, no de quién es. Es el tipo con más
		 * disputa de largo: 80 de 697 claves (11 %), frente al 0 % de las opciones. Y da los
		 * fallos más visibles — `manage_product_terms` atribuida a un plugin llamado «manage», o
		 * `manage_wpoptimizing`, que es NUESTRA, atribuida a otro.
		 *
		 * UNA CLAVE DISPUTADA NO TIENE UN DUEÑO ÚNICO. Si varios slugs la registran, lo honesto
		 * es no nombrar a ninguno y dejar que respondan las capas de debajo.
		 */
		if ( 'capability' === $tipo ) {
			return '';
		}
		if ( $this->es_disputada( $tipo, $clave ) ) {
			return '';
		}

		$idx = $this->sqlite_index();
		if ( null !== $idx ) {
			return $idx->get_slug( $tipo, $clave );
		}
		$this->ensure_loaded();

		return self::$slug_for_key[ $tipo ][ $clave ] ?? '';
	}

	/**
	 * ¿Reclaman esta clave varios slugs distintos?
	 *
	 * Fuerza la carga del CSV a propósito: el índice SQLite no guarda la disputa, así que si se
	 * contestara desde él diría «no disputada» para todo y la guarda de arriba no serviría de
	 * nada. Ese índice está detrás del filtro `wpoptimizing_use_sqlite_index`, apagado por
	 * defecto; el día que se encienda de verdad, la columna de disputa tiene que ir a su esquema.
	 *
	 * @param string $tipo  Tipo de elemento.
	 * @param string $clave Clave.
	 * @return bool
	 */
	public function es_disputada( $tipo, $clave ) {
		$this->ensure_loaded();

		return isset( self::$disputada[ strtolower( (string) $tipo ) ][ strtolower( (string) $clave ) ] );
	}

	/**
	 * Todos los slugs que reclaman una clave disputada, o [] si no lo está.
	 *
	 * get_slug_for_key() no nombra a ninguno, y es lo honesto en general; pero si de todos ellos
	 * sólo uno está instalado aquí, es ése: `mfrh_options` lo reclaman Media File Renamer y su
	 * versión Pro, y en un sitio con sólo la gratuita quedaba en Pendientes (wpoptimizing.com,
	 * 27/10/2026). Lo decide quien conoce lo instalado, el overlay.
	 *
	 * @param string $tipo  Tipo de elemento.
	 * @param string $clave Clave.
	 * @return string[]
	 */
	public function get_claimants( $tipo, $clave ) {
		$this->ensure_loaded();

		return array_keys( self::$disputada[ strtolower( (string) $tipo ) ][ strtolower( (string) $clave ) ] ?? array() );
	}

	/**
	 * ¿Se sostiene la reclamación de esta clave por su propio contenido?
	 *
	 * El mapa dice quién se estaba ejecutando cuando la clave se registró, no de quién es;
	 * resolvers/claim-support.php explica las dos familias donde eso difiere y por qué la
	 * comprobación es el espacio de nombres y no una lista de plugins.
	 *
	 * Memoizada por tipo+clave: la pantalla de huérfanos pregunta por cada fila y el barrido
	 * de hermanas recorre todas las claves del reclamante, que para `polylang-theme-strings`
	 * son 2.157.
	 *
	 * @param string $tipo  Tipo de elemento.
	 * @param string $clave Clave.
	 * @return bool True cuando la clave lleva el nombre del reclamante o acompaña a otras suyas.
	 */
	public function reclamacion_sostenida( $tipo, $clave ) {
		$tipo  = strtolower( (string) $tipo );
		$clave = strtolower( (string) $clave );

		if ( isset( self::$sostenida[ $tipo ][ $clave ] ) ) {
			return self::$sostenida[ $tipo ][ $clave ];
		}
		// Sin las funciones cargadas no se degrada nada: la regla sólo puede quitar confianza,
		// así que su ausencia deja el comportamiento anterior en lugar de romperlo.
		if ( ! function_exists( 'wpoptimizing_claim_namespace_reason' ) ) {
			return true;
		}

		$this->ensure_loaded();
		$slug = self::$slug_for_key[ $tipo ][ $clave ] ?? '';
		if ( '' === $slug ) {
			return true; // Nadie la reclama: no hay nada que sostener.
		}

		$sostenida = ( '' !== wpoptimizing_claim_namespace_reason( $slug, $clave ) )
			|| wpoptimizing_claim_is_corroborated( $clave, $this->claves_hermanas( $slug ) );

		self::$sostenida[ $tipo ][ $clave ] = $sostenida;
		return $sostenida;
	}

	/**
	 * Todas las claves que reclama un slug, de cualquier tipo, en una lista plana.
	 *
	 * Un espacio de nombres no respeta los tipos: `wpforms_` es de WPForms tanto en una
	 * opción como en un tipo de contenido, así que las hermanas se buscan entre todas.
	 * Memoizada por slug.
	 *
	 * @param string $slug Slug.
	 * @return array<int,string>
	 */
	protected function claves_hermanas( $slug ) {
		if ( isset( self::$hermanas[ $slug ] ) ) {
			return self::$hermanas[ $slug ];
		}
		$todas = array();
		foreach ( (array) ( self::$keys_for_slug[ $slug ] ?? array() ) as $entradas ) {
			foreach ( array_keys( (array) $entradas ) as $clave ) {
				$todas[] = $clave;
			}
		}
		self::$hermanas[ $slug ] = $todas;
		return $todas;
	}

	/**
	 * Return the confidence level for a tipo+clave pair, or 'certain' if unknown.
	 *
	 * «Si no se sabe, `certain`» NO es generosidad: la capa son capturas en vivo y una captura
	 * en vivo es atribución al 100 %. Con una excepción desde el 9/10/2026: una fila cuya
	 * `evidencia` es `scanned` NO se vio ejecutar —el productor la leyó del código— y entra como
	 * `medium`. La premisa del `certain` no la respalda, y es build_indexes() quien lo aplica.
	 *
	 * TOPE POR FALTA DE APOYO. `certain` promete «captado en vivo, atribución al 100 %», y lo
	 * que el mapa captó es qué código estaba en la pila. Cuando la clave no pertenece al
	 * espacio de nombres del que la reclama, esa promesa no la respalda el dato: se rebaja a
	 * `weak`, que es el nivel que la puerta de get_effective_row_status() NO deja borrar, con
	 * lo que la fila va a Pendientes para que la mire una persona. Visto en producción:
	 * `itsec-storage` dada por SEGURA de `wp-time-capsule`.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key/name.
	 * @return string 'certain'|'strong'|'medium'|'weak'|'heuristic'
	 */
	public function get_confianza_for_key( $tipo, $clave ) {
		$idx = $this->sqlite_index();
		if ( null !== $idx ) {
			$conf = $idx->get_confidence( $tipo, $clave );
		} else {
			$this->ensure_loaded();
			$conf = self::$confianza_for_key[ strtolower( (string) $tipo ) ][ strtolower( (string) $clave ) ] ?? 'certain';
		}
		if ( ! $this->reclamacion_sostenida( $tipo, $clave ) ) {
			return 'weak';
		}
		return $conf;
	}

	/**
	 * De qué clase es el dueño de esta clave, según quien la midió.
	 *
	 * Vacío cuando la capa no lo dice, y entonces quien pregunta se queda con lo que ya hacía:
	 * deducirlo de lo instalado. Lo que esto añade es precisamente el caso que esa deducción no
	 * cubre — un huérfano, que ya no está instalado.
	 *
	 * @param string $tipo  Tipo de elemento.
	 * @param string $clave Clave.
	 * @return string 'plugin'|'theme'|'core'|'library'|'unknown'|''
	 */
	public function get_clase_for_key( $tipo, $clave ) {
		$this->ensure_loaded();
		$clase = self::$clase_for_key[ strtolower( (string) $tipo ) ][ strtolower( (string) $clave ) ] ?? '';

		return ( '' !== $clase && function_exists( 'wpoptimizing_attribution_origin_exists' ) && ! wpoptimizing_attribution_origin_exists( $clase ) )
			? '' // Una palabra que este cliente no sabe pintar no se propaga: ver attribution-origins.php.
			: $clase;
	}

	/**
	 * Return all clave → contexto pairs for a slug and tipo.
	 *
	 * @param string $slug Plugin/theme slug.
	 * @param string $tipo Item type. Pass '' to get all types.
	 * @return array<string,string> Map of clave → contexto.
	 */
	public function get_keys_for_slug( $slug, $tipo = '' ) {
		$this->ensure_loaded();
		$slug = strtolower( (string) $slug );
		$tipo = strtolower( (string) $tipo );

		if ( ! isset( self::$keys_for_slug[ $slug ] ) ) {
			return array();
		}

		if ( '' !== $tipo ) {
			return self::$keys_for_slug[ $slug ][ $tipo ] ?? array();
		}

		// Merge all types.
		$all = array();
		foreach ( self::$keys_for_slug[ $slug ] as $t => $entries ) {
			$all = array_merge( $all, $entries );
		}
		return $all;
	}

	/**
	 * Return context string for a specific tipo + clave.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key.
	 * @return string Context string or ''.
	 */
	public function get_context( $tipo, $clave ) {
		$idx = $this->sqlite_index();
		if ( null !== $idx ) {
			return $idx->get_context( $tipo, $clave );
		}
		$this->ensure_loaded();
		$tipo  = strtolower( (string) $tipo );
		$clave = strtolower( (string) $clave );
		$slug  = self::$slug_for_key[ $tipo ][ $clave ] ?? '';
		if ( '' === $slug ) {
			return '';
		}
		return self::$keys_for_slug[ $slug ][ $tipo ][ $clave ] ?? '';
	}

	/**
	 * Return true if the runtime map has been loaded (even if empty).
	 *
	 * @return bool
	 */
	public function is_loaded() {
		return null !== self::$slug_for_key;
	}

	/**
	 * Enrich the runtime map before analysis or on tab open.
	 *
	 * Step 1 — registered meta: runs the mu-plugin's capture_registered_meta()
	 *           (or a direct $wp_meta_keys scan when the mu-plugin is absent).
	 * Step 2 — DB scan: discovers existing meta keys in wp_postmeta / wp_usermeta /
	 *           wp_termmeta / wc_orders_meta (HPOS) and attributes via registered_meta
	 *           callbacks or prefix heuristics. Rate-limited to once every 6 h unless
	 *           $force is true (used when an analysis is explicitly started).
	 *
	 * After either step writes new CSV rows the static indexes are reset so the
	 * next ensure_loaded() reads the updated file.
	 *
	 * @param bool $force_db_scan Skip the transient rate-limit and always run step 2.
	 * @return void
	 */
	public function enrich( $force_db_scan = false ) {
		// ── Step 1: registered meta ─────────────────────────────────────────────
		if ( function_exists( 'apg_wpopt_rtmap_capture_registered_meta' ) ) {
			apg_wpopt_rtmap_capture_registered_meta();
		} else {
			$this->scan_registered_meta();
		}

		// ── Step 1b: registered settings (options) ───────────────────────────
		// Covers plugins that use register_setting() so their option names are
		// attributed even if mode-A hooks never ran (e.g. signature unchanged).
		// Works in any context (admin, cron, REST) unlike the mu-plugin's
		// admin_init-hooked apg_wpopt_rtmap_scan_registered_settings().
		$this->scan_registered_settings_options();

		// ── Step 2: DB scan ─────────────────────────────────────────────────────
		$transient = 'wpopt_rtmap_db_enrich';
		if ( $force_db_scan || ! get_transient( $transient ) ) {
			$this->scan_db_meta_keys();
			if ( ! $force_db_scan ) {
				set_transient( $transient, 1, 6 * HOUR_IN_SECONDS );
			}
		}

		// Reset so next ensure_loaded() reads the freshly written CSV.
		self::reset_indexes();
	}

	/**
	 * Scan all options registered via register_setting() and attribute them.
	 * Only writes entries not already in the runtime map (dedup via static index).
	 * Works in any execution context (admin, cron, REST).
	 *
	 * @return void
	 */
	protected function scan_registered_settings_options() {
		if ( ! function_exists( 'get_registered_settings' ) ) {
			return;
		}
		$registered = get_registered_settings();
		if ( empty( $registered ) ) {
			return;
		}
		$this->ensure_loaded();

		$skip_prefixes = array( '_transient_', '_site_transient_', 'wpopt_', 'wpoptimizing_', '_wp_' );

		foreach ( $registered as $option => $args ) {
			$option = strtolower( trim( (string) $option ) );
			if ( '' === $option ) {
				continue;
			}
			$skip = false;
			foreach ( $skip_prefixes as $pfx ) {
				if ( str_starts_with( $option, $pfx ) ) {
					$skip = true;
					break;
				}
			}
			if ( $skip || isset( self::$slug_for_key['option'][ $option ] ) ) {
				continue; // Already attributed — skip.
			}

			$slug      = '';
			$confianza = 'heuristic';

			// Certain: sanitize callback defined by the plugin itself.
			$cb = $args['sanitize_callback'] ?? null;
			if ( is_callable( $cb ) && function_exists( 'apg_wpopt_rtmap_slug_from_callback' ) ) {
				$slug = apg_wpopt_rtmap_slug_from_callback( $cb );
				if ( '' !== $slug ) {
					$confianza = 'certain';
				}
			}

			// Fallback: prefix heuristic.
			if ( '' === $slug && function_exists( 'apg_wpopt_rtmap_attr_by_prefix' ) ) {
				$slug = apg_wpopt_rtmap_attr_by_prefix( $option );
				if ( '' === $slug ) {
					$parts = explode( '_', $option, 2 );
					if ( isset( $parts[0] ) && strlen( $parts[0] ) >= 2 ) {
						$slug = apg_wpopt_rtmap_attr_by_prefix( $parts[0] );
					}
				}
			}

			if ( '' === $slug ) {
				continue;
			}

			$this->append_to_csv( $slug, 'option', $option, '', $confianza );
			$this->update_indexes( $slug, 'option', $option, '', $confianza );
		}
	}

	/**
	 * Fallback for scan_registered_meta() when the mu-plugin is not active.
	 * Reads $wp_meta_keys, tries to resolve each unknown key via callbacks or
	 * prefix, and appends hits to the CSV + in-memory indexes.
	 *
	 * @return void
	 */
	protected function scan_registered_meta() {
		global $wp_meta_keys;
		if ( empty( $wp_meta_keys ) || ! is_array( $wp_meta_keys ) ) {
			return;
		}
		$this->ensure_loaded();
		foreach ( $wp_meta_keys as $object_type => $subtypes ) {
			if ( ! is_array( $subtypes ) ) {
				continue;
			}
			$tipo = 'post' === $object_type ? 'post_meta'
				: ( 'user' === $object_type ? 'user_meta'
				: ( 'term' === $object_type ? 'term_meta' : 'meta_key' ) );
			foreach ( $subtypes as $subtype => $keys ) {
				if ( ! is_array( $keys ) ) {
					continue;
				}
				foreach ( array_keys( $keys ) as $meta_key ) {
					$meta_key = strtolower( trim( (string) $meta_key ) );
					if ( '' === $meta_key || isset( self::$slug_for_key[ $tipo ][ $meta_key ] ) ) {
						continue;
					}
					$args      = $keys[ $meta_key ] ?? array();
					$slug      = '';
					$confianza = 'heuristic';
					foreach ( array( 'sanitize_callback', 'auth_callback' ) as $cb_key ) {
						$cb = $args[ $cb_key ] ?? null;
						if ( is_callable( $cb ) && function_exists( 'apg_wpopt_rtmap_slug_from_callback' ) ) {
							$slug = apg_wpopt_rtmap_slug_from_callback( $cb );
							if ( '' !== $slug ) {
								$confianza = 'certain';
								break;
							}
						}
					}
					if ( '' === $slug && function_exists( 'apg_wpopt_rtmap_attr_by_prefix' ) ) {
						$slug = apg_wpopt_rtmap_attr_by_prefix( ltrim( $meta_key, '_' ) );
					}
					if ( '' === $slug ) {
						continue;
					}
					$contexto = $object_type . ':' . $subtype;
					$this->append_to_csv( $slug, $tipo, $meta_key, $contexto, $confianza );
					$this->update_indexes( $slug, $tipo, $meta_key, $contexto, $confianza );
				}
			}
		}
	}

	/**
	 * Scan wp_postmeta, wp_usermeta, wp_termmeta and (if present) wc_orders_meta
	 * for distinct meta keys not yet in the runtime map.
	 * Attribution: registered_meta callbacks (certain) → prefix heuristic (heuristic).
	 * Keys with no attribution are silently skipped so future retries remain possible.
	 *
	 * @return void
	 */
	protected function scan_db_meta_keys() {
		global $wpdb;
		$this->ensure_loaded();

		$tables = array(
			'post_meta' => preg_replace( '/[^a-z0-9_]/i', '', $wpdb->postmeta ),
			'user_meta' => preg_replace( '/[^a-z0-9_]/i', '', $wpdb->usermeta ),
			'term_meta' => preg_replace( '/[^a-z0-9_]/i', '', $wpdb->termmeta ),
		);

		// HPOS: WooCommerce order meta stored in wc_orders_meta (WC 7+).
		$hpos = preg_replace( '/[^a-z0-9_]/i', '', $wpdb->prefix . 'wc_orders_meta' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos ) ) === $hpos ) {
			$tables['post_meta_hpos'] = $hpos; // same key structure, attribute as post_meta
		}

		$skip_prefixes = array( '_wp_', 'wpopt_', 'wpoptimizing_' );

		/**
		 * Meta keys examined per table and run.
		 *
		 * @since 0.1.0
		 * @param int $limit Keys per table per run.
		 */
		$limit  = max( 1, (int) apply_filters( 'wpoptimizing_rtmap_meta_keys_per_run', 500 ) );
		$cursor = get_option( self::META_CURSOR_OPTION, array() );
		$cursor = is_array( $cursor ) ? $cursor : array();

		foreach ( $tables as $tipo_key => $table ) {
			$tipo = ( 'post_meta_hpos' === $tipo_key ) ? 'post_meta' : $tipo_key;

			// PAGE THROUGH THE KEYS, do not keep re-reading the first ones. This used to be
			// `LIMIT 500` with no ORDER BY and no cursor. DISTINCT on an indexed column comes
			// back in index order, so every run got the same first 500 keys; the loop below
			// skips the ones already attributed, so once those 500 were done the scan did
			// nothing at all and key 501 onwards was NEVER attributed on a site with more
			// than that. Nothing reported it: attribution simply plateaued, and the rest sat
			// in Pending for good.
			$after   = isset( $cursor[ $tipo_key ] ) ? (string) $cursor[ $tipo_key ] : '';
			$results = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT DISTINCT meta_key FROM `{$table}` WHERE meta_key != '' AND meta_key > %s ORDER BY meta_key ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is allow-listed above.
					$after,
					$limit
				)
			);

			// Advance the cursor, and wrap when the page came back short: the far end of the
			// alphabet must not become a place where new keys go to be ignored.
			$cursor[ $tipo_key ] = self::next_meta_cursor( (array) $results, $limit );

			foreach ( (array) $results as $meta_key ) {
				$meta_key = strtolower( trim( (string) $meta_key ) );
				if ( '' === $meta_key ) {
					continue;
				}
				$skip = false;
				foreach ( $skip_prefixes as $pfx ) {
					if ( str_starts_with( $meta_key, $pfx ) ) {
						$skip = true;
						break;
					}
				}
				if ( $skip || isset( self::$slug_for_key[ $tipo ][ $meta_key ] ) ) {
					continue;
				}

				// Try registered_meta callbacks first (certain confidence).
				$slug      = $this->slug_from_registered_meta( $tipo, $meta_key );
				$confianza = '' !== $slug ? 'certain' : '';

				// Fallback: prefix heuristic (heuristic confidence).
				if ( '' === $slug && function_exists( 'apg_wpopt_rtmap_attr_by_prefix' ) ) {
					$bare = ltrim( $meta_key, '_' );
					$slug = apg_wpopt_rtmap_attr_by_prefix( $bare );
					if ( '' === $slug ) {
						$parts = explode( '_', $bare, 2 );
						if ( isset( $parts[0] ) && strlen( $parts[0] ) >= 2 ) {
							$slug = apg_wpopt_rtmap_attr_by_prefix( $parts[0] );
						}
					}
					$confianza = '' !== $slug ? 'heuristic' : '';
				}

				if ( '' === $slug ) {
					continue; // Cannot attribute — skip, allow future retry.
				}

				$this->append_to_csv( $slug, $tipo, $meta_key, '', $confianza );
				$this->update_indexes( $slug, $tipo, $meta_key, '', $confianza );
			}
		}

		update_option( self::META_CURSOR_OPTION, $cursor, false );
	}

	/**
	 * Where the next run should resume from, given the page just read.
	 *
	 * Pure, so the wrap-around is testable: a full page resumes after its last key, and a
	 * short page means the end of the table was reached, which restarts at the beginning so
	 * keys added later are picked up.
	 *
	 * @since 0.1.0
	 * @param array $keys  Keys returned by this page, in ascending order.
	 * @param int   $limit Page size that was requested.
	 * @return string Cursor for the next run ('' restarts).
	 */
	public static function next_meta_cursor( array $keys, $limit ) {
		$limit = (int) $limit;
		if ( count( $keys ) < $limit || empty( $keys ) ) {
			return '';
		}
		return (string) end( $keys );
	}

	/**
	 * Resolve a meta key against the $wp_meta_keys registry using callbacks.
	 *
	 * @param string $tipo     Runtime map tipo (post_meta / user_meta / term_meta).
	 * @param string $meta_key Meta key (lowercased).
	 * @return string Slug or ''.
	 */
	protected function slug_from_registered_meta( $tipo, $meta_key ) {
		global $wp_meta_keys;
		if ( empty( $wp_meta_keys ) ) {
			return '';
		}
		$object_type = 'post_meta' === $tipo ? 'post'
			: ( 'user_meta' === $tipo ? 'user'
			: ( 'term_meta' === $tipo ? 'term' : '' ) );
		if ( '' === $object_type || ! isset( $wp_meta_keys[ $object_type ] ) ) {
			return '';
		}
		if ( ! function_exists( 'apg_wpopt_rtmap_slug_from_callback' ) ) {
			return '';
		}
		foreach ( $wp_meta_keys[ $object_type ] as $subtype => $keys ) {
			if ( ! isset( $keys[ $meta_key ] ) ) {
				continue;
			}
			$args = $keys[ $meta_key ];
			foreach ( array( 'sanitize_callback', 'auth_callback' ) as $cb_key ) {
				$cb = $args[ $cb_key ] ?? null;
				if ( is_callable( $cb ) ) {
					$slug = apg_wpopt_rtmap_slug_from_callback( $cb );
					if ( '' !== $slug ) {
						return $slug;
					}
				}
			}
		}
		return '';
	}

	/**
	 * Append a single attributed row to the runtime map CSV.
	 *
	 * @param string $slug      Plugin/theme slug.
	 * @param string $tipo      Item type.
	 * @param string $clave     Item key.
	 * @param string $contexto  Optional context string.
	 * @param string $confianza Confidence level.
	 * @return void
	 */
	protected function append_to_csv( $slug, $tipo, $clave, $contexto, $confianza ) {
		$path = $this->local_path;
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// The runtime map holds sensitive attribution data (item name -> owning plugin),
		// so it is encrypted at rest. AES-GCM cannot append, so this is a read-decrypt +
		// add-row + write-encrypt cycle (this is the fallback writer used when the runtime
		// mu-plugin is not capturing; the mu-plugin buffers + flushes once per request).
		$content = $this->read_local_content();
		if ( false === $content ) {
			return; // Encrypted but unreadable right now — never risk corrupting it.
		}
		$content = (string) $content;
		$content = self::ensure_header( $content );
		if ( "\n" !== substr( $content, -1 ) ) {
			$content .= "\n";
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem path; WP_Filesystem cannot build a TSV-escaped record in memory.
		$buf = fopen( 'php://temp', 'r+' );
		if ( ! $buf ) {
			return;
		}

		/*
		 * Epoch y no `gmdate('Y-m-d')`, y `clase` al final: este escritor es el respaldo del que
		 * usa el mu-plugin, así que escribe EXACTAMENTE su misma forma. Cuando los dos no
		 * escriben igual, el fichero acaba con dos formatos dentro y quien lo lee elige mal.
		 */
		$ahora = (string) time();

		/*
		 * LA EVIDENCIA SE DEJA VACÍA A PROPÓSITO. Las filas de este escritor salen de tres
		 * sitios con naturaleza distinta —el registro vivo de WordPress, el de metadatos y una
		 * heurística por prefijo sobre la base de datos—, y ni `observed` ni `scanned` describen
		 * a las tres. Vacío es «no lo sé», que es verdad, y no cambia nada: estas filas ya
		 * traen su `confianza` puesta una por una, así que no dependen de la omisión que
		 * build_indexes() aplica cuando falta.
		 *
		 * Poner `observed` «porque casi todas lo son» es exactamente el `else` que pintaba todos
		 * los huérfanos como temas.
		 */
		fputcsv(
			$buf,
			array( $slug, $tipo, $clave, $contexto, $confianza, '', get_bloginfo( 'version' ), $ahora, $ahora, self::clase_from_slug( $slug ), '' ),
			"\t",
			'"',
			'\\'
		);
		rewind( $buf );
		$content .= (string) stream_get_contents( $buf );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the in-memory php://temp stream above.
		fclose( $buf );

		// Write plaintext to a temp, encrypt it in place, then atomically swap it in.
		$tmp = wpoptimizing_atomic_tmp_path( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) {
			return;
		}
		if ( function_exists( 'wpoptimizing_scan_encrypt_file' ) ) {
			wpoptimizing_scan_encrypt_file( $tmp ); // no-op if crypto unavailable (stays plaintext).
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
		}
	}

	/**
	 * La cabecera del mapa local, con `clase`. Espejo de apg_wpopt_rtmap_header() en el
	 * mu-plugin: los dos escriben el mismo fichero y no pueden discrepar de su forma.
	 *
	 * @return string
	 */
	protected static function header() {
		return "slug\ttipo\tclave\tcontexto\tconfianza\tplugin_version\twp_version\tfirst_seen\tlast_seen\tclase\tevidencia\n";
	}

	/**
	 * Deja el contenido con la cabecera de ahora, migrando la que ya esté escrita.
	 *
	 * El mapa se escribe añadiendo al final y conservando su propia cabecera, así que un fichero
	 * de antes anunciaría nueve columnas mientras las filas nuevas traen diez: el lector lee por
	 * NOMBRE y no encontraría `clase`, tirando el dato sin un solo error. Las filas viejas se
	 * quedan sin esa columna, que se lee vacía — «no lo sé», que es lo que se sabe de ellas.
	 *
	 * @param string $content Contenido en claro.
	 * @return string
	 */
	protected static function ensure_header( $content ) {
		$content = (string) $content;
		if ( '' === trim( $content ) ) {
			return self::header();
		}
		$corte   = strpos( $content, "\n" );
		$primera = ( false === $corte ) ? $content : substr( $content, 0, $corte );
		// Se compara con la cabecera de AHORA y no se busca una columna concreta: así cada
		// ampliación se migra sola sin tener que acordarse de cambiar también esta comprobación.
		if ( rtrim( self::header(), "\n" ) === rtrim( $primera, "\r" ) ) {
			return $content;
		}
		if ( false === strpos( $primera, "slug\ttipo\tclave" ) ) {
			return $content; // No es una cabecera nuestra: no se toca lo que no se entiende.
		}

		return self::header() . ( ( false === $corte ) ? '' : substr( $content, $corte + 1 ) );
	}

	/**
	 * La confianza de una fila leída por nombre de columna, o '' si la fila no la trae.
	 *
	 * HAY FILAS QUE NO SON DE SU CABECERA. La forma de ocho columnas de antes no tenía
	 * `confianza` (slug, tipo, clave, contexto, plugin_version, wp_version, first_seen, last_seen),
	 * y ensure_header() sólo cambia la primera línea: las filas viejas se quedan debajo de una
	 * cabecera que pone `confianza` justo donde ellas tienen la versión del plugin. Medido en un
	 * mapa local real: 15.014 de 15.713 filas así, y «10.2.1» como confianza de lifterlms, que
	 * ningún nivel reconoce y que dejaba la fila en «Por identificar».
	 *
	 * En esa columna una fila vieja sólo puede traer una versión o nada, y ninguna versión es un
	 * nivel: lo que no sea del vocabulario se lee como «no lo sé», que es lo que esa fila sabe.
	 *
	 * @param array    $row           Fila del CSV.
	 * @param int|null $col_confianza Posición de `confianza` según la cabecera.
	 * @return string
	 */
	public static function row_confidence( array $row, $col_confianza ) {
		if ( null === $col_confianza ) {
			return '';
		}
		$confianza = strtolower( trim( (string) ( $row[ $col_confianza ] ?? '' ) ) );
		if ( '' !== $confianza && function_exists( 'wpoptimizing_confidence_exists' ) && ! wpoptimizing_confidence_exists( $confianza ) ) {
			return '';
		}
		return $confianza;
	}

	/**
	 * De qué clase es un slug instalado: `plugin`, `theme` o ''.
	 *
	 * Vacío cuando no se sabe, porque «no lo sé» es una respuesta y `unknown` es una afirmación.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	protected static function clase_from_slug( $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( '' === $slug || ! function_exists( 'wpoptimizing_installed_slug_index' ) ) {
			return '';
		}
		$fila  = wpoptimizing_installed_slug_index()[ $slug ] ?? array();
		$clase = strtolower( trim( (string) ( $fila[0] ?? '' ) ) );

		return ( '' !== $clase && function_exists( 'wpoptimizing_attribution_origin_exists' ) && wpoptimizing_attribution_origin_exists( $clase ) )
			? $clase
			: '';
	}

	/**
	 * Read the local runtime map, transparently decrypting when encrypted at rest.
	 * Returns the plaintext string, '' when missing/empty, or FALSE when the file is
	 * encrypted but cannot be decrypted right now (caller must not overwrite it).
	 *
	 * @return string|false
	 */
	protected function read_local_content() {
		$path = $this->local_path;
		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}
		$fh = function_exists( 'wpoptimizing_scan_read_handle' )
			? wpoptimizing_scan_read_handle( $path )
			: fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return false; // Encrypted + undecryptable.
		}
		$data = stream_get_contents( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );
		return is_string( $data ) ? $data : '';
	}

	/**
	 * Update the three static in-memory indexes for a newly attributed entry.
	 *
	 * @param string $slug      Plugin/theme slug.
	 * @param string $tipo      Item type.
	 * @param string $clave     Item key.
	 * @param string $contexto  Context string.
	 * @param string $confianza Confidence level.
	 * @return void
	 */
	protected function update_indexes( $slug, $tipo, $clave, $contexto, $confianza ) {
		if ( ! isset( self::$slug_for_key[ $tipo ] ) ) {
			self::$slug_for_key[ $tipo ] = array();
		}
		self::$slug_for_key[ $tipo ][ $clave ] = $slug;

		if ( null === self::$confianza_for_key ) {
			self::$confianza_for_key = array();
		}
		if ( ! isset( self::$confianza_for_key[ $tipo ] ) ) {
			self::$confianza_for_key[ $tipo ] = array();
		}
		self::$confianza_for_key[ $tipo ][ $clave ] = $confianza;

		if ( ! isset( self::$keys_for_slug[ $slug ] ) ) {
			self::$keys_for_slug[ $slug ] = array();
		}
		if ( ! isset( self::$keys_for_slug[ $slug ][ $tipo ] ) ) {
			self::$keys_for_slug[ $slug ][ $tipo ] = array();
		}
		self::$keys_for_slug[ $slug ][ $tipo ][ $clave ] = $contexto;
	}

	/**
	 * Olvidar TODO lo que se derivó del CSV, en un solo sitio.
	 *
	 * Había dos sitios que ponían a null tres de los índices, y los dos se dejaban
	 * `$disputada` —la marca de «esta clave la reclaman varios»— que así sobrevivía a la
	 * recarga con los datos del mapa anterior. Es el mismo descuido de siempre: el olvido
	 * escrito donde alguien se acordó de él en lugar de donde se decide. Cualquier índice
	 * nuevo se añade AQUÍ y queda cubierto por los dos caminos (RuntimeResetTest lo sujeta).
	 *
	 * @return void
	 */
	protected static function reset_indexes() {
		self::$slug_for_key      = null;
		self::$keys_for_slug     = null;
		self::$confianza_for_key = null;
		self::$clase_for_key     = null;
		self::$disputada         = array();
		self::$sostenida         = array();
		self::$hermanas          = array();
	}

	/**
	 * BORRAR la copia local y el índice, para forzar una descarga nueva. Destructivo.
	 *
	 * Se llamaba `invalidate_cache()`, igual que la del manifiesto, y por ese nombre la del
	 * manifiesto acabó borrando la capa que la línea de antes acababa de descargar. Aquí hoy
	 * no la llama nadie en producción, que es peor señal y no mejor: un método destructivo sin
	 * usos es una trampa esperando a quien lea el nombre y no el cuerpo. Para olvidar sólo los
	 * índices está reset_indexes().
	 *
	 * @return void
	 */
	public static function delete_local_copy() {
		self::reset_indexes();
		$local = trailingslashit( WPOPT_DIR ) . 'apg-runtime-map-distrib.csv';
		if ( file_exists( $local ) ) {
			wp_delete_file( $local );
		}
		// Drop the optional SQLite index too so it rebuilds from the fresh CSV.
		$sqlite = trailingslashit( WPOPT_DIR ) . 'runtime-map.sqlite';
		if ( file_exists( $sqlite ) ) {
			wp_delete_file( $sqlite );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Lazy-load indexes on first access.
	 *
	 * @return void
	 */
	protected function ensure_loaded() {
		if ( null !== self::$slug_for_key ) {
			return;
		}

		// Se va a leer el CSV otra vez: lo derivado del anterior no vale. Vaciar aquí y no
		// sólo en reset_indexes() cubre el camino en que los índices se pusieron a null por
		// separado y alguien llega a preguntar antes de que nadie los recargue.
		self::reset_indexes();
		self::$slug_for_key      = array();
		self::$keys_for_slug     = array();
		self::$confianza_for_key = array();
		self::$clase_for_key     = array();

		$local = $this->ensure_local();
		if ( '' === $local ) {
			return;
		}

		$this->build_indexes( $local );
	}

	/**
	 * Return local CSV path, downloading if stale or missing.
	 *
	 * @return string Local path or '' if unavailable.
	 */
	protected function ensure_local() {
		if ( '' !== $this->local_dir ) {
			$src = trailingslashit( $this->local_dir ) . 'apg-runtime-map-distrib.csv';
			if ( file_exists( $src ) ) {
				return $src;
			}
		}

		$local = $this->local_path;
		$stale = ! file_exists( $local );
		if ( ! $stale && $this->cache_ttl > 0 ) {
			$stale = ( time() - filemtime( $local ) ) > $this->cache_ttl;
		}

		if ( $stale && '' !== $this->base_url ) {
			$url = trailingslashit( $this->base_url ) . 'apg-runtime-map.csv';
			$this->download( $url, $local );
		}

		return file_exists( $local ) ? $local : '';
	}

	/**
	 * Download CSV to local path (best-effort, silent on failure).
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
	 * Parse the CSV and populate both indexes.
	 *
	 * @param string $path Local CSV path.
	 * @return void
	 */
	protected function build_indexes( $path ) {
		// Transparently decrypts the map when it is encrypted at rest (no-op for plaintext).
		$fh = function_exists( 'wpoptimizing_scan_read_handle' )
			? wpoptimizing_scan_read_handle( $path )
			: fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}

		$header = fgetcsv( $fh, 0, "\t", '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}

		$header = array_map( 'trim', $header );
		$hmap   = array_flip( $header );

		$col_slug      = $hmap['slug'] ?? null;
		$col_tipo      = $hmap['tipo'] ?? null;
		$col_clave     = $hmap['clave'] ?? null;
		$col_contexto  = $hmap['contexto'] ?? null;
		$col_confianza = $hmap['confianza'] ?? null;
		$col_clase     = $hmap['clase'] ?? null;
		$col_evidencia = $hmap['evidencia'] ?? null;

		if ( null === $col_slug || null === $col_tipo || null === $col_clave ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}

		if ( null === self::$confianza_for_key ) {
			self::$confianza_for_key = array();
		}
		if ( null === self::$clase_for_key ) {
			self::$clase_for_key = array();
		}

		while ( ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) !== false ) {
			if ( count( $row ) < 3 ) {
				continue;
			}

			// Una marca interna del alimentador no es el nombre de una extensión: ver wpoptimizing_non_extension_slugs().
			$slug      = wpoptimizing_shared_slug( $row[ $col_slug ] ?? '' );
			$tipo      = strtolower( trim( (string) ( $row[ $col_tipo ] ?? '' ) ) );
			$clave     = strtolower( trim( (string) ( $row[ $col_clave ] ?? '' ) ) );
			$contexto  = null !== $col_contexto ? trim( (string) ( $row[ $col_contexto ] ?? '' ) ) : '';
			$evidencia = null !== $col_evidencia ? strtolower( trim( (string) ( $row[ $col_evidencia ] ?? '' ) ) ) : '';
			$confianza = self::row_confidence( $row, $col_confianza );
			if ( '' === $confianza ) {
				/*
				 * EL `certain` POR OMISIÓN LO JUSTIFICA UNA PREMISA, Y `scanned` LA CONTRADICE.
				 * La premisa es «esta capa son capturas en vivo: el mu-plugin estaba mirando en
				 * el momento exacto del registro». Desde el 9/10/2026 la capa trae también filas
				 * que el productor LEYÓ DEL CÓDIGO sin ejecutarlo —así es como entran las tablas
				 * y los espacios de nombres del escáner estático— y de ésas nadie vio nada.
				 *
				 * Darlas por `certain` sería afirmar «captado en vivo, atribución al 100 %» de
				 * algo inferido, y `certain` es el nivel que deja borrar en bloque. Se bajan a
				 * `medium`, que sigue pasando la puerta de get_effective_row_status() —no se
				 * pierde ninguna función— pero ya no promete lo que no se midió. Y queda por
				 * debajo del tope por falta de apoyo de get_confianza_for_key(), que puede
				 * rebajarla más.
				 *
				 * Lo que diga el productor manda sobre esto: esta rama sólo corre cuando la
				 * columna viene vacía, que es «no lo sé», no «es segura».
				 */
				$confianza = ( 'scanned' === $evidencia ) ? 'medium' : 'certain';
			}
			$clase = null !== $col_clase ? strtolower( trim( (string) ( $row[ $col_clase ] ?? '' ) ) ) : '';

			if ( '' === $slug || '' === $tipo || '' === $clave ) {
				continue;
			}

			/*
			 * tipo → clave → slug. AQUÍ SE ANOTA LA DISPUTA, que antes se tiraba.
			 *
			 * Esto decía «first writer wins for conflicts»: el conflicto se conocía y se
			 * resolvía por orden de llegada, en silencio y con confianza `certain`. Medido en el
			 * mapa real: 128 claves de 16.135 las reclaman varios plugins, y entre ellas
			 * `action_scheduler_run_queue` con 24 y `read_documents` con 22. Que veintidós
			 * plugins registren la misma capacidad no es certeza de ninguno: es una librería
			 * empaquetada o un falso positivo.
			 *
			 * El primero sigue ganando para no cambiar el orden de nada, pero la clave queda
			 * marcada y `get_slug_for_key()` deja de responder por ella.
			 */
			if ( ! isset( self::$slug_for_key[ $tipo ] ) ) {
				self::$slug_for_key[ $tipo ] = array();
			}
			if ( ! isset( self::$slug_for_key[ $tipo ][ $clave ] ) ) {
				self::$slug_for_key[ $tipo ][ $clave ] = $slug;
			} elseif ( self::$slug_for_key[ $tipo ][ $clave ] !== $slug ) {
				self::$disputada[ $tipo ][ $clave ][ self::$slug_for_key[ $tipo ][ $clave ] ] = true;
				self::$disputada[ $tipo ][ $clave ][ $slug ]                                = true;
			}

			// tipo → clave → confianza (first writer wins).
			if ( ! isset( self::$confianza_for_key[ $tipo ] ) ) {
				self::$confianza_for_key[ $tipo ] = array();
			}
			if ( ! isset( self::$confianza_for_key[ $tipo ][ $clave ] ) ) {
				self::$confianza_for_key[ $tipo ][ $clave ] = $confianza;
			}

			/*
			 * tipo → clave → clase. Gana el PRIMERO QUE LA DECLARE, no el primero que llegue: una
			 * fila sin clase no puede tapar a otra que sí la trae, porque vacío es «no lo sé» y
			 * la mezcla de productores garantiza que haya de las dos durante un tiempo.
			 */
			if ( '' !== $clase ) {
				if ( ! isset( self::$clase_for_key[ $tipo ] ) ) {
					self::$clase_for_key[ $tipo ] = array();
				}
				if ( ! isset( self::$clase_for_key[ $tipo ][ $clave ] ) ) {
					self::$clase_for_key[ $tipo ][ $clave ] = $clase;
				}
			}

			// slug → tipo → clave → contexto.
			if ( ! isset( self::$keys_for_slug[ $slug ] ) ) {
				self::$keys_for_slug[ $slug ] = array();
			}
			if ( ! isset( self::$keys_for_slug[ $slug ][ $tipo ] ) ) {
				self::$keys_for_slug[ $slug ][ $tipo ] = array();
			}
			self::$keys_for_slug[ $slug ][ $tipo ][ $clave ] = $contexto;
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
