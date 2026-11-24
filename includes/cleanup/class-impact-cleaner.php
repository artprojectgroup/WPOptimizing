<?php
/**
 * Cleaner: detects database elements that are neither native nor protected by INSTALLED projects,
 * and (optionally) flags "suspicious" items that look like they belong to INACTIVE projects
 * by simple prefix heuristics.
 *
 * Produces: WPOPT_CSV_SCAN_DB with extended columns:
 * [ type, value, suggested_status, project_status, project_origins, project_slugs, size_bytes ]
 *
 * Strategy
 * --------
 * 1) Runtime shield (authoritative):
 *    - Protect anything currently registered by WordPress (post types, taxonomies, roles).
 * 2) Code shield (from WPOPT_CSV_DB):
 *    - Protect values declared by ACTIVE projects that the static scanner found.
 * 3) Heuristic shield (tables + options only):
 *    - If a value starts with a known prefix for any INSTALLED extension, treat as protected.
 *      Useful for plugins that create tables/options via installers the scanner may miss.
 *    - Prefix map is seeded with common plugins and is filterable via
 *      'wpoptimizing_active_prefix_map'.
 * 4) Suspicion (optional):
 *    - If not protected but its prefix matches any INACTIVE slug seen in the map,
 *      mark as "suspicious" with project_status = 'suspected' and include likely slugs.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Impact_Cleaner {

	use WPOptimizing_Slug_Matching_Trait;

	/** @var array Native items loaded from includes/natives.json. */
	protected $natives = array();

	/**
	 * Índice de slugs para el escudo de atribución, memoizado por instancia.
	 *
	 * @var array{active:array<string,int>,installed:array<string,int>}|null
	 */
	protected $slug_index_shield = null;

	/**
	 * Detection map (from scanner CSV) grouped by bucket and active/inactive.
	 *
	 * @var array<string,array<string,array<string,array<int,array{origen:string,slug:string}>>>>
	 */
	protected $det_map = array(
		'options'          => array(),
		'transients'       => array(),
		'post_types'       => array(),
		'taxonomies'       => array(),
		'roles'            => array(),
		'tables'           => array(),
		'post_meta'        => array(),
		'user_meta'        => array(),
		'term_meta'        => array(),
		'meta_key'         => array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'shortcodes'       => array(),
		'cron'             => array(),
		'action_scheduler' => array(),
		'i18n'             => array(),
		'post'             => array(),
	);

	/** @var string Output CSV path. */
	protected $csv_result_path;

	/** @var string[] Active extension slugs (plugin/theme directories). */
	protected $active_slugs = array();

	/** @var string[] Installed extension slugs (plugin/theme directories). */
	protected $installed_slugs = array();

	/** @var string[] Installed but inactive slugs. */
	protected $inactive_slugs = array();

	/** @var string[] Installed plugin slugs. */
	protected $installed_plugin_slugs = array();

	/** @var string[] Installed theme slugs. */
	protected $installed_theme_slugs = array();

	/** @var array<string,string> Normalized slug => original slug. */
	protected $installed_slug_map = array();

	/** @var array<string,string[]> Slug => aliases (normalized). */
	protected $installed_slug_aliases = array();

	/** @var array<string,string[]> Slug => regex patterns for matching values. */
	protected $installed_slug_patterns = array();

	/** @var array<string,string[]> Prefix map for installed extensions (filled at runtime + filter). */
	protected $prefix_map = array();

	/** @var array<string,array<string,array{origins:string,slugs:string}>> Dataset map for orphan linking. */
	protected $dataset_map = array();

	/** @var array<string,bool> Cache for heuristic code evidence checks. */
	protected $heuristic_evidence_cache = array();

	/** @var array<string,bool> Cache for bucket/slug scanner evidence checks. */
	protected $bucket_slug_cache = array();

	/** @var string[] Allowed code extensions for heuristic evidence checks. */
	protected $heuristic_scan_ext = array( 'php', 'phtml', 'inc', 'php5', 'js' );
	/** @var bool Whether to require strong evidence for owner attribution. */
	protected $strict_attribution = true;

	/** @var WPOPT_Runtime_Resolver|null Runtime map resolver (certainty 100%). */
	protected $runtime_resolver = null;

	/**
	 * Quote a trusted SQL identifier such as a table name.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	protected function quote_identifier( $identifier ) {
		$identifier = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $identifier );
		if ( '' === $identifier ) {
			return '';
		}
		return '`' . $identifier . '`';
	}

	/**
	 * Constructor.
	 *
	 * @param string|null $csv_result_path Alternative output CSV path.
	 */
	public function __construct( $csv_result_path = null ) {
		$this->csv_result_path = $csv_result_path ?: WPOPT_CSV_SCAN_DB;
		$this->load_natives();
		$this->load_detected_map();
		$this->active_slugs    = $this->get_active_project_slugs();
		$this->installed_slugs = $this->get_installed_project_slugs();
		$this->inactive_slugs  = array_values( array_diff( $this->installed_slugs, $this->active_slugs ) );
		$this->load_local_trackers();
		$this->installed_slug_patterns = $this->build_installed_slug_patterns( $this->installed_slugs );
		$this->prefix_map              = $this->build_prefix_map( $this->installed_slugs );
		$this->dataset_map             = ( new WPOPT_Dataset_Resolver() )->get_map();
		$this->strict_attribution      = (bool) apply_filters( 'wpoptimizing_strict_attribution', true, 'db' );
		if ( class_exists( 'WPOPT_Runtime_Resolver' ) ) {
			$this->runtime_resolver = new WPOPT_Runtime_Resolver();
			// Enrich with registered meta + full DB scan before any attribution.
			// force_db_scan=true bypasses the 6 h transient so each explicit analysis
			// always runs with the most complete data available.
			$this->runtime_resolver->enrich( true );
		}
		$this->merge_runtime_db_evidence();
	}

	/**
	 * Merge runtime DB evidence as active ownership hints.
	 *
	 * @return void
	 */
	protected function merge_runtime_db_evidence() {
		if ( ! class_exists( 'WPOPT_Runtime_Evidence' ) ) {
			return;
		}
		$map = WPOPT_Runtime_Evidence::get_map();
		$db  = (array) ( $map['db'] ?? array() );
		foreach ( $db as $bucket => $items ) {
			$bucket = (string) $bucket;
			if ( ! isset( $this->det_map[ $bucket ] ) || ! is_array( $items ) ) {
				continue;
			}
			foreach ( $items as $value => $meta ) {
				$key = $this->normalize_bucket_value( $bucket, (string) $value );
				if ( '' === $key ) {
					continue;
				}
				if ( ! isset( $this->det_map[ $bucket ][ $key ] ) ) {
					$this->det_map[ $bucket ][ $key ] = array(
						'active'   => array(),
						'inactive' => array(),
					);
				}
				// origins and slugs are independent sets (deduplicated values), NOT
				// positional tuples, so they cannot be paired by index — doing so
				// mis-attributes origins when the two lists differ in length or order.
				// Origin describes the kind (plugin/theme/mu-plugin) and is almost
				// always a single value per record, so use one representative origin
				// for every slug.
				$origins = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $meta['origins'] ?? '' ) ) ) ) );
				$slugs   = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $meta['slugs'] ?? '' ) ) ) ) );
				$origin  = $origins[0] ?? '';
				foreach ( $slugs as $slug ) {
					if ( '' === $slug ) {
						continue;
					}
					$this->det_map[ $bucket ][ $key ]['active'][] = array(
						'origen' => (string) $origin,
						'slug'   => (string) $slug,
					);
				}
			}
		}
	}

	/**
	 * Load natives.json into $this->natives.
	 *
	 * @return void
	 */
	protected function load_natives() {
		$path = plugin_dir_path( __FILE__ ) . 'natives.json';
		if ( is_file( $path ) ) {
			$contents = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$data     = is_string( $contents ) ? json_decode( $contents, true, 5 ) : array();
		} else {
			$data = array();
		}
		$this->natives = is_array( $data ) ? $data : array();
	}

	/**
	 * Populate $this->det_map from WPOPT_CSV_DB, grouping by bucket and active/inactive.
	 * Input header: [ type, origin, project_slug, status, extracted_value ].
	 *
	 * @return void
	 */
	protected function load_detected_map() {
		if ( ! file_exists( WPOPT_CSV_DB ) ) {
			return;
		}
		$fh = function_exists( 'wpoptimizing_scan_read_handle' ) ? wpoptimizing_scan_read_handle( WPOPT_CSV_DB ) : fopen( WPOPT_CSV_DB, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- decrypts if encrypted at rest
		if ( false === $fh ) {
			return;
		}
		fgetcsv( $fh, 0, ',', '"', '\\' ); // header
		while ( ( $row = fgetcsv( $fh, 0, ',', '"', '\\' ) ) !== false ) {
			if ( count( $row ) < 5 ) {
				continue;
			}
			list( $type, $origin, $slug, $status, $value ) = $row;
			$value  = trim( (string) $value );
			$bucket = $this->type_to_bucket( (string) $type );
			if ( ! $bucket || '' === $value ) {
				continue;
			}
			$key = $this->normalize_bucket_value( $bucket, $value );
			if ( '' === $key ) {
				continue;
			}
			if ( ! isset( $this->det_map[ $bucket ][ $key ] ) ) {
				$this->det_map[ $bucket ][ $key ] = array(
					'active'   => array(),
					'inactive' => array(),
				);
			}
			$status_key                                        = ( 'active' === $status ) ? 'active' : 'inactive';
			$this->det_map[ $bucket ][ $key ][ $status_key ][] = array(
				'origen' => (string) $origin,
				'slug'   => (string) $slug,
				'borra'  => in_array( (string) $type, array( 'delete_option', 'site_delete_option' ), true ) || '_delete' === substr( (string) $type, -7 ),
			);
		}
		fclose( $fh ); // phpcs:ignore
		$this->det_map = self::drop_delete_only_owners( $this->det_map );
	}

	/**
	 * Deleting someone else's option is not owning it. Pure.
	 *
	 * MCP Content Manager calls delete_option( 'fusion_dynamic_css_ids' ) to flush Avada's CSS
	 * cache, and the code scan listed it next to Avada as an owner of the option (emiliocalvo.es,
	 * 16/10/2026). When some extension creates or writes a key, the ones that only delete it are
	 * dropped. When deleting is all anybody does — an uninstall.php clearing its own options —
	 * it stays, because then it is the only evidence there is.
	 *
	 * @param array $map det_map: bucket → key → {active,inactive} → entries.
	 * @return array
	 */
	/**
	 * Does a key carry the name of the extension? Pure. The slug as a prefix, with - read as _ or
	 * dropped, or its first word when that word is long enough to mean something (4+ letters):
	 * fusion-builder → fusion_options, autoptimize → autoptimize_ccss_rlimit; not mcp-… → fusion_….
	 *
	 * @param string $key  Key.
	 * @param string $slug Slug.
	 * @return bool
	 */
	public static function key_resembles_slug( $key, $slug ) {
		$key  = ltrim( strtolower( (string) $key ), '_' );
		$slug = strtolower( trim( (string) $slug ) );
		if ( '' === $slug || '' === $key ) {
			return false;
		}
		$first = (string) strtok( $slug, '-_' );
		foreach ( array( str_replace( '-', '_', $slug ), str_replace( array( '-', '_' ), '', $slug ), strlen( $first ) >= 4 ? $first : '' ) as $p ) {
			if ( '' !== $p && 0 === strpos( $key, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Every shortcode tag opened in a piece of content, registered or not. Pure.
	 *
	 * NOT get_shortcode_regex(). Without arguments it is built from the tags registered right
	 * now, so it can only ever find shortcodes that are NOT orphans: the scan looking for
	 * shortcodes left behind by a removed plugin had, by construction, nothing to find. Any
	 * opening tag counts here; escaped [[tag]] does not, closing tags carry a '/', and the name
	 * must be followed by a space, ']' or '/' — "[Note: …" is not a shortcode. Who owns each
	 * tag is decided afterwards, like any other row.
	 *
	 * @param string $content Post content.
	 * @return string[]
	 */
	public static function shortcode_tags_in( $content ) {
		if ( ! preg_match_all( '/(?<!\[)\[([A-Za-z][\w-]*)(?=[\s\]\/])/', (string) $content, $m ) ) {
			return array();
		}
		return array_map( 'strval', $m[1] );
	}

	public static function drop_delete_only_owners( array $map ) {
		foreach ( $map as $bucket => $keys ) {
			foreach ( $keys as $key => $by_status ) {
				$writes = false;
				foreach ( array( 'active', 'inactive' ) as $st ) {
					foreach ( (array) ( $by_status[ $st ] ?? array() ) as $e ) {
						$writes = $writes || empty( $e['borra'] );
					}
				}
				if ( ! $writes ) {
					continue;
				}
				foreach ( array( 'active', 'inactive' ) as $st ) {
					$map[ $bucket ][ $key ][ $st ] = array_values(
						array_filter(
							(array) ( $by_status[ $st ] ?? array() ),
							static function ( $e ) use ( $key ) {
								// A key named after the one deleting it is still theirs: Autoptimize only
								// deletes autoptimize_ccss_rlimit, and a bundled copy elsewhere writes it.
								return empty( $e['borra'] ) || self::key_resembles_slug( (string) $key, (string) ( $e['slug'] ?? '' ) );
							}
						)
					);
				}
			}
		}
		return $map;
	}

	/**
	 * Return the highest confidence level from a list of det_map entries.
	 *
	 * Old scanner CSV entries lack the 'confianza' field → default 'medium'.
	 *
	 * @param array<int,array{origen:string,slug:string,confianza?:string}> $entries
	 * @return string 'certain'|'strong'|'medium'|'weak'|'heuristic'
	 */
	protected function best_confianza_from_det( array $entries ) {
		/*
		 * EL ORDEN SALE DEL VOCABULARIO ÚNICO, y no de una tabla aparte. Esta tenía cinco de
		 * los siete niveles, así que `manual` y `none` caían al `?? 0` y puntuaban lo MÍNIMO:
		 * entre una aserción humana y una coincidencia del conjunto compartido ganaba la
		 * segunda, cuando lo manual manda sobre todo lo automático por definición.
		 */
		$niveles = array();
		foreach ( $entries as $e ) {
			// Las entradas viejas del escáner no traen el campo: 'medium' sigue siendo su valor.
			$niveles[] = '' !== (string) ( $e['confianza'] ?? '' ) ? (string) $e['confianza'] : 'medium';
		}
		return empty( $niveles ) ? 'medium' : wpoptimizing_confidence_best( $niveles );
	}

	/**
	 * Load local mu-plugin activation tracker CSVs into det_map.
	 *
	 * These CSVs are tab-separated and written by apg-wpoptimizing when a plugin
	 * or theme is activated/deactivated on this installation. Confidence: strong.
	 * Must be called after $this->active_slugs is populated.
	 *
	 * @return void
	 */
	protected function load_local_trackers() {
		$base = trailingslashit( WP_CONTENT_DIR . '/wpoptimizing' );

		$trackers = array(
			array(
				'file'       => 'wp-options-tracker.csv',
				'bucket'     => 'options',
				'key_col'    => 'option_name',
				'slug_col'   => 'plugin_slug',
				'origen_val' => 'plugin',
			),
			array(
				'file'       => 'wp-usermeta-tracker.csv',
				'bucket'     => 'user_meta',
				'key_col'    => 'meta_key',
				'slug_col'   => 'plugin_slug',
				'origen_val' => 'plugin',
			),
			array(
				'file'       => 'wp-postmeta-tracker.csv',
				'bucket'     => 'post_meta',
				'key_col'    => 'meta_key',
				'slug_col'   => 'plugin_slug',
				'origen_val' => 'plugin',
			),
			array(
				'file'       => 'wp-cron-hooks.csv',
				'bucket'     => 'cron',
				'key_col'    => 'hook_name',
				'slug_col'   => 'plugin_slug',
				'origen_val' => 'plugin',
			),
			array(
				'file'       => 'wp-plugin-tables.csv',
				'bucket'     => 'tables',
				'key_col'    => 'table_name',
				'slug_col'   => 'plugin_slug',
				'origen_val' => 'plugin',
			),
			array(
				'file'       => 'wp-roles-tracker.csv',
				'bucket'     => 'roles',
				'key_col'    => 'role',
				'slug_col'   => 'plugin_slug',
				'origen_val' => 'plugin',
			),
		);

		foreach ( $trackers as $tracker ) {
			$path = $base . $tracker['file'];
			if ( ! file_exists( $path ) ) {
				continue;
			}
			$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false === $fh ) {
				continue;
			}

			$header_row = fgetcsv( $fh, 0, "\t", '"', '\\' );
			if ( ! is_array( $header_row ) ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				continue;
			}

			$hmap          = array_flip( array_map( 'trim', $header_row ) );
			$col_key       = $hmap[ $tracker['key_col'] ] ?? null;
			$col_slug      = $hmap[ $tracker['slug_col'] ] ?? null;
			$col_accion    = $hmap['accion'] ?? null;
			$col_confianza = $hmap['confianza'] ?? null;

			if ( null === $col_key || null === $col_slug ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				continue;
			}

			while ( ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) !== false ) {
				$key       = trim( (string) ( $row[ $col_key ] ?? '' ) );
				$slug      = trim( (string) ( $row[ $col_slug ] ?? '' ) );
				$accion    = null !== $col_accion ? trim( (string) ( $row[ $col_accion ] ?? '' ) ) : '';
				$confianza = null !== $col_confianza ? trim( (string) ( $row[ $col_confianza ] ?? 'strong' ) ) : 'strong';
				if ( '' === $confianza ) {
					$confianza = 'strong';
				}

				if ( '' === $key || '' === $slug ) {
					continue;
				}
				// 'eliminado' records indicate removal, not ownership.
				if ( 'eliminado' === $accion ) {
					continue;
				}

				$bucket   = $tracker['bucket'];
				$norm_key = $this->normalize_bucket_value( $bucket, $key );
				if ( '' === $norm_key ) {
					continue;
				}

				if ( ! isset( $this->det_map[ $bucket ][ $norm_key ] ) ) {
					$this->det_map[ $bucket ][ $norm_key ] = array(
						'active'   => array(),
						'inactive' => array(),
					);
				}

				$status_key = in_array( $slug, $this->active_slugs, true ) ? 'active' : 'inactive';

				// Skip if this slug is already recorded for this key+status.
				foreach ( $this->det_map[ $bucket ][ $norm_key ][ $status_key ] as $existing ) {
					if ( (string) ( $existing['slug'] ?? '' ) === $slug ) {
						continue 2;
					}
				}

				$this->det_map[ $bucket ][ $norm_key ][ $status_key ][] = array(
					'origen'    => $tracker['origen_val'],
					'slug'      => $slug,
					'confianza' => $confianza,
				);
			}

			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Normalize a bucket value for deterministic map lookups.
	 *
	 * @param string $bucket Bucket.
	 * @param string $value Raw value.
	 * @return string
	 */
	protected function normalize_bucket_value( $bucket, $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$bucket = (string) $bucket;
		if ( 'tables' === $bucket ) {
			$value = $this->strip_table_prefix( $value );
		}
		if ( 'capabilities' === $bucket && false !== strpos( $value, ':' ) ) {
			$value = (string) substr( $value, strrpos( $value, ':' ) + 1 ); // role:cap from the code scan.
		}
		if ( 'transients' === $bucket ) {
			/*
			 * One spelling for both sides. The code scan stores set_transient( 'foo' ) as `foo`,
			 * and the options loop asked for `_transient_foo`: no transient ever met its owner.
			 */
			foreach ( array( '_site_transient_timeout_', '_transient_timeout_', '_site_transient_', '_transient_' ) as $prefix ) {
				if ( 0 === stripos( $value, $prefix ) ) {
					$value = (string) substr( $value, strlen( $prefix ) );
					break;
				}
			}
		}
		if ( in_array( $bucket, array( 'options', 'transients', 'tables', 'post_meta', 'user_meta', 'term_meta', 'meta_key', 'cron', 'action_scheduler', 'i18n', 'shortcodes', 'post_types', 'taxonomies', 'roles', 'capabilities' ), true ) ) {
			$value = strtolower( $value );
		}
		return $value;
	}

	/**
	 * Return det_map entry by normalized key.
	 *
	 * @param string $bucket Bucket.
	 * @param string $value  Value.
	 * @return array{active:array<int,array{origen:string,slug:string}>,inactive:array<int,array{origen:string,slug:string}>}
	 */
	protected function get_det_entry( $bucket, $value ) {
		$key = $this->normalize_bucket_value( $bucket, $value );
		if ( '' === $key ) {
			return array(
				'active'   => array(),
				'inactive' => array(),
			);
		}
		$entry = $this->det_map[ $bucket ][ $key ] ?? array();
		return array(
			'active'   => (array) ( $entry['active'] ?? array() ),
			'inactive' => (array) ( $entry['inactive'] ?? array() ),
		);
	}

	/**
	 * Map CSV "type" to our internal bucket.
	 *
	 * @param string $type CSV type.
	 * @return string|null
	 */
	protected function type_to_bucket( $type ) {
		// `<type>_delete` (see the code scanner's append_db_row()) lands in its type's bucket.
		if ( '_delete' === substr( (string) $type, -7 ) && 'transient_delete' !== $type ) {
			$type = substr( (string) $type, 0, -7 );
		}
		switch ( $type ) {
			case 'post_type':
				return 'post_types';
			case 'taxonomy':
				return 'taxonomies';
			case 'option':
			case 'update_option':
			case 'delete_option':
				return 'options';
			case 'transient':
			case 'transient_set':
			case 'transient_delete':
				return 'transients';
			case 'post_meta':
				return 'post_meta';
			case 'user_meta':
				return 'user_meta';
			case 'term_meta':
				return 'term_meta';
			case 'meta_key':
				return 'meta_key';
			case 'shortcode':
				return 'shortcodes';
			case 'cron':
				return 'cron';
			case 'action_scheduler':
				return 'action_scheduler';
			case 'i18n':
				return 'i18n';
			case 'post':
				return 'post';
			case 'role':
				return 'roles';
			case 'cap':
				// add_cap( role, cap ): the code scan writes role:cap, the bucket is the cap.
				return 'capabilities';
			case 'db_delta':
			case 'create_table':
				return 'tables';
		}
		return null;
	}

	/**
	 * Build a list of active extension slugs (plugins + active theme/parent).
	 *
	 * @return string[] Unique slugs.
	 */
	protected function get_active_project_slugs() {
		$slugs = array();

		// Plugins (single + network).
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active      = (array) get_option( 'active_plugins', array() );
		$network     = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();

		foreach ( $all_plugins as $plugin_file => $_data ) {
			// e.g. 'woocommerce/woocommerce.php' → 'woocommerce'
			$slug = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
			if ( in_array( $plugin_file, $active, true ) || in_array( $plugin_file, $network, true ) ) {
				$slugs[ $slug ] = true;
			}
		}

		// Must-use plugins: always loaded, never in active_plugins.
		if ( function_exists( 'wpoptimizing_mu_plugin_slugs' ) ) {
			$slugs += wpoptimizing_mu_plugin_slugs();
		}

		// Theme (stylesheet + template).
		$stylesheet = get_stylesheet();
		$template   = get_template();
		if ( $stylesheet ) {
			$slugs[ $stylesheet ] = true;
		}
		if ( $template ) {
			$slugs[ $template ] = true;
		}

		return array_keys( $slugs );
	}

	/**
	 * Build the installed prefix map for tables/options (filterable).
	 *
	 * @param string[] $slugs Installed slugs.
	 * @return array<string,string[]>
	 */
	protected function build_prefix_map( array $slugs ) {
		$map = array();

		// Derive simple prefixes from the slug itself (natural: based on installed extensions).
		foreach ( $slugs as $slug ) {
			$candidates   = array();
			$candidates[] = $slug . '_';
			$candidates[] = str_replace( '-', '_', $slug ) . '_';
			$map[ $slug ] = isset( $map[ $slug ] )
				? array_values( array_unique( array_merge( $map[ $slug ], $candidates ) ) )
				: $candidates;
		}

		/**
		 * Filter to adjust the map: array slug => [prefix, ...]
		 *
		 * @param array $map   Current map.
		 * @param array $slugs List of installed slugs.
		 */
		$map = apply_filters( 'wpoptimizing_active_prefix_map', $map, $slugs );

		// Add discovered prefixes from detected map (options/tables only).
		foreach ( $this->det_map as $bucket => $values ) {
			if ( 'options' !== $bucket && 'tables' !== $bucket ) {
				continue;
			}
			foreach ( (array) $values as $value => $status_groups ) {
				$prefix = $this->derive_detected_prefix( (string) $value );
				if ( '' === $prefix ) {
					continue;
				}
				foreach ( array( 'active', 'inactive' ) as $status_key ) {
					foreach ( (array) ( $status_groups[ $status_key ] ?? array() ) as $meta ) {
						$slug = (string) ( $meta['slug'] ?? '' );
						if ( '' === $slug ) {
							continue;
						}
						$map[ $slug ] = isset( $map[ $slug ] )
							? array_values( array_unique( array_merge( $map[ $slug ], array( $prefix ) ) ) )
							: array( $prefix );
					}
				}
			}
		}

		// Keep only installed slugs.
		$out = array();
		foreach ( $slugs as $slug ) {
			if ( ! empty( $map[ $slug ] ) ) {
				$out[ $slug ] = array_values( array_unique( array_map( 'strval', (array) $map[ $slug ] ) ) );
			}
		}
		return $out;
	}

	/**
	 * Execute comparisons against the real DB and generate the disposable CSV.
	 *
	 * @return void
	 */
	public function run() {
		global $wpdb;
		// Failures of a previous run in this request are not this one's (see wpoptimizing_db_failed()).
		if ( function_exists( 'wpoptimizing_db_failures_take' ) ) {
			wpoptimizing_db_failures_take();
		}

		$output   = array(); // rows: [type, value, suggested_status, project_status, project_origins, project_slugs, size_bytes]
		$existing = array();

		// Batch counts for post types and taxonomies.
		$pt_counts = array();
		$pt_rows   = $wpdb->get_results( "SELECT post_type, COUNT(*) AS cnt FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','trash') GROUP BY post_type", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'post type counts' );
		foreach ( (array) $pt_rows as $r ) {
			$pt_counts[ (string) $r['post_type'] ] = (int) $r['cnt'];
		}
		$tax_counts = array();
		$tax_rows   = $wpdb->get_results( "SELECT taxonomy, COUNT(*) AS cnt FROM {$wpdb->term_taxonomy} GROUP BY taxonomy", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'taxonomy counts' );
		foreach ( (array) $tax_rows as $r ) {
			$tax_counts[ (string) $r['taxonomy'] ] = (int) $r['cnt'];
		}
		// User counts per role (avail_roles key).
		$role_counts     = array();
		$user_count_data = count_users();
		foreach ( (array) ( $user_count_data['avail_roles'] ?? array() ) as $rk => $rc ) {
			$role_counts[ (string) $rk ] = (int) $rc;
		}
		// Table row counts from information_schema (estimated).
		$table_row_counts = array();
		if ( function_exists( 'wpoptimizing_db_live_stats' ) ) {
			wpoptimizing_db_live_stats();
		}
		$tbl_info = $wpdb->get_results( 'SELECT TABLE_NAME AS tname, TABLE_ROWS AS trows FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'table row counts' );
		foreach ( (array) $tbl_info as $r ) {
			// Alias the columns so the result is consistent regardless of whether the
			// server returns information_schema column names lower- or upper-cased.
			$tname = (string) ( $r['tname'] ?? '' );
			if ( '' === $tname ) {
				continue;
			}
			$table_row_counts[ $tname ] = (int) ( $r['trows'] ?? 0 );
		}

		// Options — each option_name is unique (1 row per key).
		$option_sizes = $this->get_option_sizes();
		foreach ( array_keys( $option_sizes ) as $option ) {
			if ( 0 === strpos( $option, '_transient_' ) || 0 === strpos( $option, '_site_transient_' ) ) {
				// Skip the "_timeout_" companion: it is the expiry partner of a
				// transient and is removed together with it, so listing it as its own
				// row just duplicates every transient entry.
				if ( 0 === strpos( $option, '_transient_timeout_' ) || 0 === strpos( $option, '_site_transient_timeout_' ) ) {
					continue;
				}
				if ( $this->is_native( 'transients', $option ) ) {
					continue;
				}
				$output[] = $this->build_disposable_row( 'transient', $option, 'transients', $option_sizes[ $option ] ?? 0, 1 );
				continue;
			}
			if ( $this->is_native( 'options', $option ) ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'option', $option, 'options', $option_sizes[ $option ] ?? 0, 1 );
		}

		// Post types (from DB, not runtime).
		$post_types = $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'post types' );
		foreach ( (array) $post_types as $pt ) {
			if ( $this->is_native( 'post_types', $pt ) ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'post_type', $pt, 'post_types', 0, $pt_counts[ $pt ] ?? 0 );
		}

		// Taxonomies (from DB, not runtime).
		$taxonomies = $wpdb->get_col( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'taxonomies' );
		foreach ( (array) $taxonomies as $tax ) {
			if ( $this->is_native( 'taxonomies', $tax ) ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'taxonomy', $tax, 'taxonomies', 0, $tax_counts[ $tax ] ?? 0 );
		}

		// Roles (from stored option, not runtime).
		$roles_opt = get_option( $wpdb->prefix . 'user_roles', array() );
		$roles     = is_array( $roles_opt ) ? array_keys( $roles_opt ) : array();
		foreach ( (array) $roles as $role_key ) {
			if ( $this->is_native( 'roles', $role_key ) ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'role', $role_key, 'roles', 0, $role_counts[ $role_key ] ?? 0 );
		}

		// Tables (without prefix)
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'tables' );
		$table_sizes = $this->get_table_sizes();
		foreach ( (array) $tables as $table ) {
			// Skip WordPress core tables, including every sub-site's core tables and
			// the network/global tables in a multisite install (never disposable).
			if ( $this->is_core_table( $table ) ) {
				continue;
			}
			// In a network, SHOW TABLES returns EVERY sub-site's tables. Only scan the
			// ones that belong to the current blog — each sub-site is analysed in its
			// own context, so another sub-site's plugin tables (e.g. wp_2_woocommerce_*)
			// must not surface here as unknown/pending.
			if ( ! $this->table_belongs_to_current_blog( $table ) ) {
				continue;
			}
			$no_prefix  = $this->strip_table_prefix( $table );
			$table_rows = $table_row_counts[ $table ] ?? 0;
			$output[]   = $this->build_disposable_row( 'table', $no_prefix, 'tables', $table_sizes[ $no_prefix ] ?? 0, $table_rows );
		}

		// Post meta keys
		$post_meta_stats = $this->get_meta_sizes( $wpdb->postmeta );
		$post_meta       = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->failed( 'postmeta keys' );
		foreach ( (array) $post_meta as $mk ) {
			$mk = (string) $mk;
			if ( '' === $mk ) {
				continue;
			}
			if ( $this->is_native( 'post_meta', $mk ) ) {
				continue;
			}
			$stat     = $post_meta_stats[ $mk ] ?? array(
				'size'  => 0,
				'count' => 0,
			);
			$output[] = $this->build_disposable_row( 'post_meta', $mk, 'post_meta', $stat['size'], $stat['count'] );
		}

		// User meta keys
		$user_meta_stats = $this->get_meta_sizes( $wpdb->usermeta );
		$user_meta       = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->usermeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->failed( 'usermeta keys' );
		foreach ( (array) $user_meta as $mk ) {
			$mk = (string) $mk;
			if ( '' === $mk ) {
				continue;
			}
			if ( $this->is_native( 'user_meta', $mk ) ) {
				continue;
			}
			$stat     = $user_meta_stats[ $mk ] ?? array(
				'size'  => 0,
				'count' => 0,
			);
			$output[] = $this->build_disposable_row( 'user_meta', $mk, 'user_meta', $stat['size'], $stat['count'] );
		}

		// Term meta keys
		$term_meta_stats = $this->get_meta_sizes( $wpdb->termmeta );
		$term_meta       = $wpdb->get_col( "SELECT DISTINCT meta_key FROM {$wpdb->termmeta}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		$this->failed( 'termmeta keys' );
		foreach ( (array) $term_meta as $mk ) {
			$mk = (string) $mk;
			if ( '' === $mk ) {
				continue;
			}
			if ( $this->is_native( 'term_meta', $mk ) ) {
				continue;
			}
			$stat     = $term_meta_stats[ $mk ] ?? array(
				'size'  => 0,
				'count' => 0,
			);
			$output[] = $this->build_disposable_row( 'term_meta', $mk, 'term_meta', $stat['size'], $stat['count'] );
		}

		// Shortcodes used in content but not registered now. Scanned in keyset
		// batches with a hard cap, so a site with millions of posts never loads all
		// post_content into memory at once (the previous unbounded query was an OOM
		// risk). A statement timeout keeps the leading-wildcard LIKE from dragging.
		$shortcode_counts = array();
		if ( function_exists( 'wpoptimizing_db_statement_timeout' ) ) {
			wpoptimizing_db_statement_timeout( 30 );
		}
		$sc_cap   = (int) apply_filters( 'wpoptimizing_scan_shortcode_cap', 50000 );
		$sc_batch = 2000;
		$sc_last  = 0;
		$sc_seen  = 0;
		while ( $sc_seen < $sc_cap ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ID, post_content FROM {$wpdb->posts} WHERE ID > %d AND post_status <> 'auto-draft' AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
					$sc_last,
					'%[%',
					$sc_batch
				),
				ARRAY_A
			);
			if ( $this->failed( 'shortcodes' ) || empty( $rows ) || ! is_array( $rows ) ) {
				break;
			}
			foreach ( $rows as $r ) {
				$sc_last = (int) ( $r['ID'] ?? $sc_last );
				++$sc_seen;
				foreach ( self::shortcode_tags_in( (string) ( $r['post_content'] ?? '' ) ) as $tag ) {
					$shortcode_counts[ $tag ] = ( $shortcode_counts[ $tag ] ?? 0 ) + 1;
				}
			}
			if ( count( $rows ) < $sc_batch ) {
				break;
			}
		}
		foreach ( $shortcode_counts as $tag => $sc_count ) {
			if ( $this->is_native( 'shortcodes', $tag ) ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'shortcode', $tag, 'shortcodes', 0, $sc_count );
		}

		// Scheduled cron hooks (from DB option).
		// Structure: [ timestamp => [ hook_name => [ callback_hash => event_data ] ] ]
		if ( function_exists( '_get_cron_array' ) ) {
			$cron             = _get_cron_array();
			$cron_hook_counts = array();
			if ( is_array( $cron ) ) {
				foreach ( $cron as $hooks ) {
					if ( ! is_array( $hooks ) ) {
						continue;
					}
					foreach ( $hooks as $hook => $callbacks ) {
						$hook = (string) $hook;
						if ( '' === $hook ) {
							continue;
						}
						$cron_hook_counts[ $hook ] = ( $cron_hook_counts[ $hook ] ?? 0 ) + count( (array) $callbacks );
					}
				}
			}
			foreach ( $cron_hook_counts as $hook => $cron_count ) {
				if ( $this->is_native( 'cron', $hook ) ) {
					continue;
				}
				$output[] = $this->build_disposable_row( 'cron', $hook, 'cron', 0, max( 1, $cron_count ) );
			}
		}

		// Scheduled Action Scheduler actions (custom tables; e.g. WooCommerce and
		// many others). Each distinct hook is attributed to its owning plugin the
		// same way WP-Cron hooks are. Only surfaces when the AS tables exist.
		$as_actions = $wpdb->prefix . 'actionscheduler_actions';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$as_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $as_actions ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( (string) $as_exists === $as_actions ) {
			$as_rows = $wpdb->get_results( "SELECT hook, COUNT(*) AS cnt FROM `{$as_actions}` GROUP BY hook", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name is $wpdb->prefix + a fixed Action Scheduler suffix; identifiers cannot be bound via prepare().
			$this->failed( 'Action Scheduler hooks' );
			foreach ( (array) $as_rows as $as_r ) {
				$hook = (string) ( $as_r['hook'] ?? '' );
				if ( '' === $hook || $this->is_native( 'action_scheduler', $hook ) ) {
					continue;
				}
				// Action Scheduler's own infrastructure hooks (e.g.
				// "action_scheduler_run_recurring_actions_schedule_hook" or
				// "action_scheduler/migration_hook") belong to the library itself;
				// if the AS tables exist, AS is present, so they are never orphans.
				if ( 0 === strpos( $hook, 'action_scheduler' ) ) {
					continue;
				}
				$output[] = $this->build_disposable_row( 'action_scheduler', $hook, 'action_scheduler', 0, max( 1, (int) ( $as_r['cnt'] ?? 0 ) ) );
			}
		}

		// NOTE: Expired transients are intentionally NOT surfaced here as "orphans".
		// They are stale cache of (often still-installed, active) plugins — not
		// entries of uninstalled extensions — so listing them under Orphans with
		// "certain / belongs to a removed extension" was misleading. Non-expired,
		// non-core transients are still attributed to their owner by the options
		// loop above; bulk clearing of expired transients is handled by the
		// General cleanup "Delete expired transients" task (enabled by default).

		// Capabilities: non-core capabilities stored in roles.
		$all_roles = wp_roles();
		if ( $all_roles ) {
			$cap_role_counts = array();
			foreach ( $all_roles->roles as $role_data ) {
				if ( empty( $role_data['capabilities'] ) ) {
					continue;
				}
				foreach ( array_keys( $role_data['capabilities'] ) as $cap ) {
					$cap = (string) $cap;
					if ( '' === $cap ) {
						continue;
					}
					$cap_role_counts[ $cap ] = ( $cap_role_counts[ $cap ] ?? 0 ) + 1;
				}
			}
			foreach ( $cap_role_counts as $cap => $cap_count ) {
				if ( $this->is_native( 'capabilities', $cap ) ) {
					continue;
				}
				$output[] = $this->build_disposable_row( 'capability', $cap, 'capabilities', 0, $cap_count );
			}
		}

		// REST namespaces: capture from registered routes (requires WP REST server init).
		if ( did_action( 'rest_api_init' ) ) {
			$server = rest_get_server();
			if ( $server ) {
				$core_ns = array( 'wp/v2', 'wp-block-editor/v1', 'wp-site-health/v1', 'oembed/1.0', 'batch/v1' );
				$ns_seen = array();
				foreach ( array_keys( $server->get_routes() ) as $route ) {
					if ( preg_match( '#^/([^/]+/v\d+)#i', $route, $m ) ) {
						$ns = strtolower( $m[1] );
					} elseif ( preg_match( '#^/([^/]+)#', $route, $m ) ) {
						$ns = strtolower( $m[1] );
					} else {
						continue;
					}
					if ( in_array( $ns, $core_ns, true ) || isset( $ns_seen[ $ns ] ) ) {
						continue;
					}
					$ns_seen[ $ns ] = true;
					$output[]       = $this->build_disposable_row( 'rest_namespace', $ns, 'rest_namespace', 0 );
				}
			}
		}

		// Post-type content: posts that exist in DB for unregistered post types.
		$registered_pt = array_keys( get_post_types() );
		$db_post_types = $wpdb->get_col( "SELECT DISTINCT post_type FROM {$wpdb->posts} WHERE post_status NOT IN ('auto-draft','trash')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'live post types' );
		foreach ( (array) $db_post_types as $pt ) {
			$pt = (string) $pt;
			if ( '' === $pt || in_array( $pt, $registered_pt, true ) ) {
				continue;
			}
			if ( $this->is_native( 'post_types', $pt ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off count of orphan-post-type rows during a scan; not cacheable.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','trash')",
					$pt
				)
			);
			if ( $this->failed( 'orphan post type count' ) || $count < 1 ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'post_type_content', $pt, 'post_types', $count );
		}

		// Taxonomy terms: terms that exist in DB for unregistered taxonomies.
		$registered_tax = array_keys( get_taxonomies() );
		$db_taxonomies  = $wpdb->get_col( "SELECT DISTINCT taxonomy FROM {$wpdb->term_taxonomy}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->failed( 'live taxonomies' );
		foreach ( (array) $db_taxonomies as $tax ) {
			$tax = (string) $tax;
			if ( '' === $tax || in_array( $tax, $registered_tax, true ) ) {
				continue;
			}
			if ( $this->is_native( 'taxonomies', $tax ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off count of orphan-taxonomy terms during a scan; not cacheable.
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s",
					$tax
				)
			);
			if ( $this->failed( 'orphan taxonomy count' ) || $count < 1 ) {
				continue;
			}
			$output[] = $this->build_disposable_row( 'taxonomy_term', $tax, 'taxonomies', $count );
		}

		// Plugin-created pages: options with page_ref context in the runtime map.
		if ( null !== $this->runtime_resolver ) {
			$page_ref_options = array();
			foreach ( array( 'option' ) as $tipo ) {
				// Iterate all slugs and look for page_ref context entries.
				foreach ( $this->installed_slugs as $slug ) {
					$opts = $this->runtime_resolver->get_keys_for_slug( $slug, $tipo );
					foreach ( $opts as $clave => $ctx ) {
						if ( 'page_ref' === $ctx ) {
							$page_ref_options[ $clave ] = $slug;
						}
					}
				}
			}
			foreach ( $page_ref_options as $opt_key => $owning_slug ) {
				$post_id = (int) get_option( $opt_key );
				if ( $post_id < 1 ) {
					continue;
				}
				$post = get_post( $post_id );
				if ( ! $post || 'trash' === $post->post_status ) {
					continue;
				}
				$is_active = in_array( $owning_slug, $this->active_slugs, true );
				$output[]  = array(
					'plugin_page',
					$post_id . ':' . $post->post_title,
					$is_active ? 'keep' : 'disposable',
					$is_active ? 'active' : ( isset( $this->installed_plugin_slugs[ $owning_slug ] ) || isset( $this->installed_theme_slugs[ $owning_slug ] ) ? 'inactive' : 'orphan' ),
					'plugin',
					$owning_slug,
					(int) mb_strlen( $post->post_content ?? '' ),
					0,
					'certain',
				);
			}
		}

		// Add det_map-only buckets so they appear in orphans/inactives lists.
		$existing = $this->build_existing_map( $output );
		$this->append_det_map_only_rows( $output, $existing, 'i18n', 'i18n' );
		$this->append_det_map_only_rows( $output, $existing, 'post', 'post' );

		// A query the database cut short is not "nothing there". Keep the last good result
		// rather than write over it a list with whole sections missing, and say so on screen.
		$failures = function_exists( 'wpoptimizing_db_failures_take' ) ? wpoptimizing_db_failures_take() : array();
		if ( function_exists( 'wpoptimizing_scan_partial_set' ) ) {
			wpoptimizing_scan_partial_set( 'impact_db', $failures );
		}
		if ( ! empty( $failures ) && file_exists( $this->csv_result_path ) ) {
			return;
		}
		$this->save_csv( $output );
	}

	/**
	 * Whether the query that just ran failed (see wpoptimizing_db_failed()).
	 *
	 * @param string $what Short name of the query.
	 * @return bool
	 */
	protected function failed( $what ) {
		return function_exists( 'wpoptimizing_db_failed' ) && wpoptimizing_db_failed( 'database: ' . $what );
	}

	/**
	 * Build a map of existing rows by type/value.
	 *
	 * @param array $rows Output rows.
	 * @return array<string,array<string,bool>>
	 */
	protected function build_existing_map( array $rows ) {
		$map = array();
		foreach ( $rows as $row ) {
			$type  = (string) ( $row[0] ?? '' );
			$value = (string) ( $row[1] ?? '' );
			if ( '' === $type || '' === $value ) {
				continue;
			}
			if ( ! isset( $map[ $type ] ) ) {
				$map[ $type ] = array();
			}
			$map[ $type ][ $value ] = true;
		}
		return $map;
	}

	/**
	 * Append rows from det_map for buckets that don't exist in DB sources.
	 *
	 * @param array  $output   Output rows (by reference).
	 * @param array  $existing Existing map.
	 * @param string $bucket   det_map bucket.
	 * @param string $csv_type CSV type to write.
	 * @return void
	 */
	protected function append_det_map_only_rows( array &$output, array &$existing, $bucket, $csv_type ) {
		$bucket   = (string) $bucket;
		$csv_type = (string) $csv_type;
		if ( empty( $this->det_map[ $bucket ] ) ) {
			return;
		}
		foreach ( $this->det_map[ $bucket ] as $value => $arr ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			if ( isset( $existing[ $csv_type ][ $value ] ) ) {
				continue;
			}
			if ( $this->is_native( $bucket, $value ) ) {
				continue;
			}
			if ( $this->is_protected_by_active( $bucket, $value ) ) {
				continue;
			}
			$output[]                        = $this->build_disposable_row( $csv_type, $value, $bucket, 0 );
			$existing[ $csv_type ][ $value ] = true;
		}
	}

	/**
	 * Is value native according to natives.json?
	 *
	 * @param string $bucket Bucket ('options','transients','post_types','taxonomies','roles','tables').
	 * @param string $value  Value to check.
	 * @return bool
	 */
	protected function is_native( $bucket, $value ) {
		// Core registry has certainty 100% — check it first.
		if ( class_exists( 'WPOPT_WP_Core_Registry' ) ) {
			$tipo = $this->bucket_to_tipo( $bucket );
			if ( '' !== $tipo && WPOPT_WP_Core_Registry::is_core( $tipo, $value ) ) {
				return true;
			}
		}
		return in_array( $value, (array) ( $this->natives[ $bucket ] ?? array() ), true );
	}

	/**
	 * Map a det_map bucket name to an WPOPT_WP_Core_Registry tipo name.
	 *
	 * @param string $bucket Bucket key.
	 * @return string Tipo key, or '' if no mapping.
	 */
	protected function bucket_to_tipo( $bucket ) {
		$map = array(
			'options'        => 'option',
			'transients'     => 'option',
			'post_types'     => 'post_type',
			'taxonomies'     => 'taxonomy',
			'tables'         => 'table',
			'roles'          => 'role',
			'capabilities'   => 'capability',
			'post_meta'      => 'post_meta',
			'user_meta'      => 'user_meta',
			'term_meta'      => 'term_meta',
			'shortcodes'     => 'shortcode',
			'rest_namespace' => 'rest_namespace',
		);
		return $map[ $bucket ] ?? '';
	}

	/**
	 * Is a value registered right now (runtime)? Applies to post_types, taxonomies and roles.
	 *
	 * @param string $bucket Bucket.
	 * @param string $value  Value.
	 * @return bool
	 */
	protected function is_registered_now( $bucket, $value ) {
		switch ( $bucket ) {
			case 'post_types':
				return post_type_exists( $value );
			case 'taxonomies':
				return taxonomy_exists( $value );
			case 'roles':
				return isset( wp_roles()->roles[ $value ] );
			case 'shortcodes':
				global $shortcode_tags;
				return is_array( $shortcode_tags ) && isset( $shortcode_tags[ $value ] );
		}
		return false;
	}

	/**
	 * Is it protected by an ACTIVE project?
	 * 1) Scanner CSV map (det_map['active'])
	 * 2) Heuristic prefixes for options/tables
	 *
	 * @param string $bucket Bucket.
	 * @param string $value  Value.
	 * @return bool
	 */
	protected function is_protected_by_active( $bucket, $value ) {
		$det = $this->get_det_entry( $bucket, $value );
		// From scanner CSV (active only).
		if ( ! empty( $det['active'] ) ) {
			return true;
		}

		// Runtime registry is authoritative for dynamic registrations.
		if ( $this->is_registered_now( $bucket, $value ) ) {
			return true;
		}

		// Special case: external updates by plugin slug.
		if ( 'options' === $bucket ) {
			$ext = $this->external_updates_status( $value );
			if ( 'active' === $ext['status'] ) {
				return true;
			}
		}

		// Generic slug match (installed active extensions).
		if ( $this->bucket_allows_slug_match( $bucket ) ) {
			$match = $this->match_installed_slug_in_value( $value, $bucket );
			if ( 'active' === $match['status'] ) {
				return true;
			}
		}

		// Heuristic for options and tables.
		if ( 'options' === $bucket || 'tables' === $bucket ) {
			$match = $this->prefix_match_status( $value, $bucket );
			if ( 'active' === $match['status'] ) {
				return true;
			}
		}

		// Public dataset can still be authoritative when it resolves to an active installed project.
		$ds = $this->dataset_lookup( $bucket, $value );
		if ( ! empty( $ds['slugs'] ) ) {
			$resolved = $this->resolve_installed_slugs_list( $ds['slugs'] );
			if ( ! empty( $resolved['active'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Match by prefix against installed slugs, returning status + metadata.
	 *
	 * @param string $value Value to check.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function prefix_match_status( $value, $bucket = 'options' ) {
		$value          = strtolower( (string) $value );
		$inactive_slugs = array();
		$inactive_orig  = array();
		$active_slugs   = array();
		$active_orig    = array();
		foreach ( $this->prefix_map as $slug => $prefixes ) {
			foreach ( (array) $prefixes as $prefix ) {
				$prefix = strtolower( (string) $prefix );
				if ( '' === $prefix || 0 !== strpos( $value, $prefix ) ) {
					continue;
				}
				if ( ! $this->heuristic_slug_has_evidence( $slug, $value, $bucket ) ) {
					continue;
				}
				if ( in_array( $slug, $this->active_slugs, true ) ) {
					$active_slugs[ $slug ] = true;
					$active_orig[ $slug ]  = $this->slug_origin( $slug );
					continue;
				}
				if ( in_array( $slug, $this->inactive_slugs, true ) ) {
					$inactive_slugs[ $slug ] = true;
					$inactive_orig[ $slug ]  = $this->slug_origin( $slug );
				}
			}
		}

		if ( ! empty( $active_slugs ) ) {
			return array(
				'status'  => 'active',
				'origins' => implode( '|', array_values( array_unique( array_filter( $active_orig ) ) ) ),
				'slugs'   => implode( '|', array_keys( $active_slugs ) ),
			);
		}

		if ( ! empty( $inactive_slugs ) ) {
			return array(
				'status'  => 'inactive',
				'origins' => implode( '|', array_values( array_unique( array_filter( $inactive_orig ) ) ) ),
				'slugs'   => implode( '|', array_keys( $inactive_slugs ) ),
			);
		}

		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * Detect "external_updates-<slug>" entries and map them to installed slugs.
	 *
	 * @param string $value Value to check.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function external_updates_status( $value ) {
		$prefixes       = array( 'external_updates-', 'external_updates_' );
		$matched_prefix = '';
		foreach ( $prefixes as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				$matched_prefix = $prefix;
				break;
			}
		}
		if ( '' === $matched_prefix ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}
		$suffix = substr( $value, strlen( $matched_prefix ) );
		$suffix = trim( (string) $suffix );
		if ( '' === $suffix ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}
		$key = $this->normalize_slug_key( $suffix );
		if ( ! isset( $this->installed_slug_map[ $key ] ) ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}
		$slug = $this->installed_slug_map[ $key ];
		if ( in_array( $slug, $this->active_slugs, true ) ) {
			return array(
				'status'  => 'active',
				'origins' => $this->slug_origin( $slug ),
				'slugs'   => $slug,
			);
		}
		return array(
			'status'  => 'inactive',
			'origins' => $this->slug_origin( $slug ),
			'slugs'   => $slug,
		);
	}

	/**
	 * Resolve a "|" separated slug list to installed active/inactive slugs.
	 *
	 * @param string $slugs Slug list.
	 * @return array{
	 *   active:string[],
	 *   inactive:string[],
	 *   origins:string,
	 *   origins_active:string,
	 *   origins_inactive:string
	 * }
	 */
	protected function resolve_installed_slugs_list( $slugs ) {
		$items            = array_filter( array_map( 'trim', explode( '|', (string) $slugs ) ) );
		$active           = array();
		$inactive         = array();
		$origins          = array();
		$origins_active   = array();
		$origins_inactive = array();

		foreach ( $items as $slug ) {
			$key = $this->normalize_slug_key( $slug );
			if ( ! isset( $this->installed_slug_map[ $key ] ) ) {
				continue;
			}
			$real = $this->installed_slug_map[ $key ];
			if ( in_array( $real, $this->active_slugs, true ) ) {
				$active[ $real ]  = true;
				$origins[ $real ] = $this->slug_origin( $real );
				if ( '' !== $origins[ $real ] ) {
					$origins_active[ $real ] = $origins[ $real ];
				}
				continue;
			}
			if ( in_array( $real, $this->inactive_slugs, true ) ) {
				$inactive[ $real ] = true;
				$origins[ $real ]  = $this->slug_origin( $real );
				if ( '' !== $origins[ $real ] ) {
					$origins_inactive[ $real ] = $origins[ $real ];
				}
			}
		}

		return array(
			'active'           => array_keys( $active ),
			'inactive'         => array_keys( $inactive ),
			'origins'          => implode( '|', array_values( array_unique( array_filter( $origins ) ) ) ),
			'origins_active'   => implode( '|', array_values( array_unique( array_filter( $origins_active ) ) ) ),
			'origins_inactive' => implode( '|', array_values( array_unique( array_filter( $origins_inactive ) ) ) ),
		);
	}

	/**
	 * Convert a slug to regex patterns with boundaries.
	 *
	 * @param string $slug Slug.
	 * @return string[]
	 */
	protected function slug_to_patterns( $slug ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return array();
		}
		// Minimum 6 chars: shorter fragments ("post", "smtp", "wpop"…) are too
		// generic and produce false-positive matches in unrelated meta keys.
		if ( strlen( $slug ) < 6 ) {
			return array();
		}
		$base    = preg_quote( $slug, '/' );
		$base    = str_replace( '\-', '[-_]', $base );
		$pattern = '/(^|[^a-z0-9])' . $base . '([^a-z0-9]|$)/';
		return array( $pattern );
	}

	/**
	 * Match any installed slug pattern in a value.
	 *
	 * @param string $value Value to check.
	 * @return array{status:string,origins:string,slugs:string}
	 */
	protected function match_installed_slug_in_value( $value, $bucket = 'options' ) {
		$value  = strtolower( (string) $value );
		$values = array( $value );
		foreach ( array( '_transient_timeout_', '_site_transient_timeout_', '_transient_', '_site_transient_' ) as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				$values[] = substr( $value, strlen( $prefix ) );
				break;
			}
		}
		$inactive_slugs = array();
		$inactive_orig  = array();
		$active_slugs   = array();
		$active_orig    = array();
		foreach ( $this->installed_slug_patterns as $slug => $patterns ) {
			foreach ( $patterns as $pattern ) {
				foreach ( $values as $val ) {
					if ( preg_match( $pattern, $val ) ) {
						$orig_slug = $this->installed_slug_map[ $this->normalize_slug_key( $slug ) ] ?? $slug;
						if ( ! $this->heuristic_slug_has_evidence( $orig_slug, $value, $bucket ) ) {
							continue;
						}
						if ( in_array( $orig_slug, $this->active_slugs, true ) ) {
							$active_slugs[ $orig_slug ] = true;
							$active_orig[ $orig_slug ]  = $this->slug_origin( $orig_slug );
							break;
						}
						if ( in_array( $orig_slug, $this->inactive_slugs, true ) ) {
							$inactive_slugs[ $orig_slug ] = true;
							$inactive_orig[ $orig_slug ]  = $this->slug_origin( $orig_slug );
						}
						break;
					}
				}
			}
		}
		if ( ! empty( $active_slugs ) ) {
			return array(
				'status'  => 'active',
				'origins' => implode( '|', array_values( array_unique( array_filter( $active_orig ) ) ) ),
				'slugs'   => implode( '|', array_keys( $active_slugs ) ),
			);
		}
		if ( ! empty( $inactive_slugs ) ) {
			return array(
				'status'  => 'inactive',
				'origins' => implode( '|', array_values( array_unique( array_filter( $inactive_orig ) ) ) ),
				'slugs'   => implode( '|', array_keys( $inactive_slugs ) ),
			);
		}
		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * Whether slug-based matching should be used for this bucket.
	 *
	 * @param string $bucket Bucket.
	 * @return bool
	 */
	protected function bucket_allows_slug_match( $bucket ) {
		// Capabilities too: manage_woocommerce names WooCommerce, and the evidence guard
		// (heuristic_slug_has_evidence()) still asks WooCommerce's code to spell it out.
		return in_array( $bucket, array( 'options', 'tables', 'transients', 'post_meta', 'user_meta', 'term_meta', 'cron', 'action_scheduler', 'i18n', 'post', 'shortcodes', 'post_types', 'taxonomies', 'roles', 'capabilities' ), true );
	}

	/**
	 * Derive a conservative prefix from a detected value.
	 *
	 * @param string $value Value.
	 * @return string Prefix or empty.
	 */
	protected function derive_detected_prefix( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$base = $value;
		if ( false !== strpos( $base, '-' ) ) {
			$base = substr( $base, 0, strpos( $base, '-' ) );
		}
		if ( false === strpos( $base, '_' ) ) {
			return '';
		}
		if ( strlen( $base ) < 8 ) {
			return '';
		}
		return $base;
	}

	/**
	 * Build the output row. If there are related INACTIVE projects, include them.
	 * Otherwise, try a "suspicion" guess by matching prefixes to INACTIVE slugs.
	 *
	 * Returns 9 elements: type, value, suggested_status, project_status,
	 * project_origins, project_slugs, size_bytes, count_rows, confianza.
	 *
	 * @param string $csv_type option|post_type|taxonomy|role|table
	 * @param string $value    Value.
	 * @param string $bucket   Internal bucket.
	 * @param int    $size     Size in bytes (optional).
	 * @param int    $count    Row count (optional).
	 * @return array{string,string,string,string,string,string,int,int,string}
	 */
	/**
	 * Los slugs de este sitio, en la forma que espera el escudo. Se construyen UNA vez.
	 *
	 * Esto se armaba dentro de build_disposable_row(), que es el bucle más caliente del
	 * análisis: dos `array_map` + dos `array_flip` sobre los cientos de extensiones del sitio,
	 * por cada fila de la base de datos. Medido, no era la causa del análisis lento que se
	 * reportó —0,012 ms por fila con todo incluido—, pero son cientos de miles de operaciones
	 * para recalcular algo que no cambia en toda la pasada. Se quita porque sobra, no porque
	 * se le haya probado nada.
	 *
	 * @return array{active:array<string,int>,installed:array<string,int>}
	 */
	/**
	 * ¿Nombra esta clave a una extensión que sigue instalada aquí?
	 *
	 * No contesta de quién es la clave —eso no se puede saber para un fork—, sino lo único
	 * decidible: si algo que sigue en el sitio responde a ese nombre. Con eso basta para no
	 * ofrecerla como huérfana.
	 *
	 * Reutiliza match_installed_slug_in_value(), que no es una coincidencia de prefijo a secas:
	 * pasa por heuristic_slug_has_evidence(), que exige que el escáner haya visto la clave en
	 * el código de esa extensión.
	 *
	 * @param string $value  Clave.
	 * @param string $bucket Cubo interno.
	 * @return bool
	 */
	protected function value_names_an_installed_extension( $value, $bucket ) {
		if ( ! $this->bucket_allows_slug_match( $bucket ) ) {
			return false;
		}
		$match  = (array) $this->match_installed_slug_in_value( $value, $bucket );
		$estado = (string) ( $match['status'] ?? '' );

		return 'active' === $estado || 'inactive' === $estado;
	}

	protected function slug_index_for_shield() {
		if ( null !== $this->slug_index_shield ) {
			return $this->slug_index_shield;
		}
		$this->slug_index_shield = array(
			'active'    => array_flip( array_map( 'strtolower', array_values( (array) $this->active_slugs ) ) ),
			'installed' => array_flip(
				array_map(
					'strtolower',
					array_merge(
						array_keys( (array) $this->installed_plugin_slugs ),
						array_keys( (array) $this->installed_theme_slugs )
					)
				)
			),
		);
		return $this->slug_index_shield;
	}

	protected function build_disposable_row( $csv_type, $value, $bucket, $size = 0, $count = 0 ) {
		if ( function_exists( 'wpoptimizing_scan_heartbeat' ) ) {
			wpoptimizing_scan_heartbeat(); // One call per row: a large database keeps the stage's lock alive.
		}
		$det      = $this->get_det_entry( $bucket, $value );
		$active   = (array) $det['active'];
		$inactive = (array) $det['inactive'];
		$size     = (int) $size;
		$count    = (int) $count;

		/*
		 * LOS CUATRO ESCUDOS, ANTES QUE NADA, y así no llegan siquiera a escribirse mal en el
		 * CSV del escáner. Aquí estaban sólo DOS —lo nuestro y el núcleo, éste a través de
		 * is_native()—, y faltaban la librería empotrada y los metadatos de plataforma, que sí
		 * estaban en la superposición de la pantalla.
		 *
		 * Y no era una asimetría inocua: este CSV no sólo lo lee la pantalla, que corrige al
		 * pintar. Lo sirve también `GET /results` de la API REST, tal cual, así que quien
		 * automatizara la limpieza contra la API recibía `as_has_wp_comment_logs` como huérfana
		 * de `ip-locator` con la máxima confianza. La guarda escrita donde alguien se acordó de
		 * ella y no donde se decide. Visto en producción por el otro lado: `manage_wpoptimizing`
		 * atribuida a `booktics` con confianza SEGURO.
		 */
		if ( function_exists( 'wpoptimizing_attribution_shield' ) ) {
			$escudo = wpoptimizing_attribution_shield( $this->bucket_to_tipo( $bucket ), $value, $this->slug_index_for_shield() );
			if ( null !== $escudo ) {
				return array(
					$csv_type,
					$value,
					'keep',
					$escudo['status'],
					$escudo['origin'],
					$escudo['slug'],
					$size,
					$count,
					$escudo['confianza'],
				);
			}
		}

		// Runtime map: certainty 100% — captured live by mu-plugin.
		if ( null !== $this->runtime_resolver ) {
			$tipo         = $this->bucket_to_tipo( $bucket );
			$runtime_slug = $this->runtime_resolver->get_slug_for_key( $tipo, $value );
			if ( '' !== $runtime_slug ) {
				/*
				 * TRES CASOS, NO DOS. Esto decía `? 'plugin' : 'theme'`: si el slug que da el mapa de
				 * runtime no era un plugin instalado, se daba por tema. Pero hay un tercer caso, y es
				 * justo el que importa aquí: que no esté instalado en absoluto, que es la definición
				 * de un huérfano. Resultado medido en el sitio local: el 100 % de los huérfanos
				 * atribuidos por esta vía salían como «Theme: <plugin>», ninguno como plugin.
				 *
				 * El índice que lo contesta bien estaba cargado y se usa CINCO LÍNEAS más abajo, y el
				 * comentario de ahí ya decía «Plugin not installed at all → confirmed orphan»: el
				 * tercer caso estaba contemplado en todas partes menos en esta línea.
				 *
				 * El mapa en tiempo de ejecución dice de QUIÉN es una clave y nunca de qué clase era,
				 * así que para lo no instalado la respuesta honesta es `unknown`: la columna pinta el
				 * nombre sin afirmar clase.
				 */
				if ( isset( $this->installed_plugin_slugs[ $runtime_slug ] ) ) {
					$origin = 'plugin';
				} elseif ( isset( $this->installed_theme_slugs[ $runtime_slug ] ) ) {
					$origin = 'theme';
				} else {
					$origin = 'unknown';
				}
				$confianza = $this->runtime_resolver->get_confianza_for_key( $tipo, $value );
				if ( in_array( $runtime_slug, $this->active_slugs, true ) ) {
					return array( $csv_type, $value, 'keep', 'active', $origin, $runtime_slug, $size, $count, $confianza );
				}
				/*
				 * ACTIVE EVIDENCE FIRST. The map names one owner, the one it saw; it does not say
				 * nobody else uses the key. When an active extension's code writes it, or it is
				 * registered right now, it is in use whatever the map says — and the two branches
				 * below used to return `disposable` before either was looked at. Falling through
				 * lets the active-evidence branch keep it.
				 */
				$live = ! empty( $active ) || $this->is_registered_now( $bucket, $value );
				if ( ! $live && ( isset( $this->installed_plugin_slugs[ $runtime_slug ] ) || isset( $this->installed_theme_slugs[ $runtime_slug ] ) ) ) {
					return array( $csv_type, $value, 'disposable', 'inactive', $origin, $runtime_slug, $size, $count, $confianza );
				}
				/*
				 * «El que lo registró no está» NO ES LO MISMO que «esto ya no lo usa nadie».
				 *
				 * Un fork o un reempaquetado lleva el espacio de nombres del original: el mapa
				 * vio a `slimjetpack` crear `jetpack_options` porque slimjetpack ES el código de
				 * Jetpack, y la clave es de Jetpack. Reportado en producción marcada SEGURO y
				 * ofrecida para borrar — con Jetpack instalado y usándola. Es la misma forma que
				 * la librería empotrada: quien la registró es el portador, no el dueño.
				 *
				 * La regla de espacio de nombres no puede cazarlo, y es por construcción: un
				 * fork reclama una familia entera de claves coherentes, que es exactamente lo
				 * que parece un espacio de nombres propio.
				 *
				 * Pero hay una pregunta que SÍ es decidible sin saber de quién es: ¿nombra la
				 * clave a una extensión que sigue instalada en este sitio? Si la hay, el dato
				 * está en uso y no es un huérfano, lo registrara quien lo registrara. La
				 * comprobación ya existía unas líneas más abajo —con su guarda de evidencia, que
				 * exige que el escáner haya visto la clave en el código de esa extensión— y
				 * simplemente no se llegaba a ella porque esta rama devolvía antes.
				 *
				 * Al no devolver aquí, la fila sigue el camino normal y la trata quien ya sabe.
				 */
				if ( ! $live && ! $this->value_names_an_installed_extension( $value, $bucket ) ) {
					// Plugin not installed at all → confirmed orphan.
					return array( $csv_type, $value, 'disposable', 'orphan', $origin, $runtime_slug, $size, $count, $confianza );
				}
			}
		}

		// Active evidence from scanner/tracker map (exact value).
		if ( ! empty( $active ) ) {
			$orig = array_values(
				array_unique(
					array_map(
						static function ( $x ) {
							return (string) $x['origen'];
						},
						$active
					)
				)
			);
			$slgs = array_values(
				array_unique(
					array_map(
						static function ( $x ) {
							return (string) $x['slug'];
						},
						$active
					)
				)
			);
			return array( $csv_type, $value, 'keep', 'active', implode( '|', $orig ), implode( '|', $slgs ), $size, $count, $this->best_confianza_from_det( $active ) );
		}

		// Item is live in WP memory now but no attribution found yet — keep it.
		// Confidence is 'medium': we know it's registered but not who owns it.
		if ( $this->is_registered_now( $bucket, $value ) ) {
			return array( $csv_type, $value, 'keep', 'active', '', '', $size, $count, 'medium' );
		}

		// Inactive evidence from scanner/tracker map.
		if ( ! empty( $inactive ) ) {
			$orig = array_values(
				array_unique(
					array_map(
						static function ( $x ) {
							return (string) $x['origen'];
						},
						$inactive
					)
				)
			);
			$slgs = array_values(
				array_unique(
					array_map(
						static function ( $x ) {
							return (string) $x['slug'];
						},
						$inactive
					)
				)
			);
			return array( $csv_type, $value, 'disposable', 'inactive', implode( '|', $orig ), implode( '|', $slgs ), $size, $count, $this->best_confianza_from_det( $inactive ) );
		}

		// External updates mapping to installed slugs (specific option naming).
		if ( 'options' === $bucket ) {
			$ext = $this->external_updates_status( $value );
			if ( 'active' === $ext['status'] ) {
				return array( $csv_type, $value, 'keep', 'active', $ext['origins'], $ext['slugs'], $size, $count, 'heuristic' );
			}
			if ( 'inactive' === $ext['status'] ) {
				return array( $csv_type, $value, 'disposable', 'inactive', $ext['origins'], $ext['slugs'], $size, $count, 'heuristic' );
			}
		}

		// Generic slug match.
		if ( $this->bucket_allows_slug_match( $bucket ) ) {
			$match = $this->match_installed_slug_in_value( $value, $bucket );
			if ( 'active' === $match['status'] ) {
				return array( $csv_type, $value, 'keep', 'active', $match['origins'], $match['slugs'], $size, $count, 'heuristic' );
			}
			if ( 'inactive' === $match['status'] ) {
				return array( $csv_type, $value, 'disposable', 'inactive', $match['origins'], $match['slugs'], $size, $count, 'heuristic' );
			}
		}

		// Prefix match against installed slugs.
		if ( 'options' === $bucket || 'tables' === $bucket ) {
			$pref = $this->prefix_match_status( $value, $bucket );
			if ( 'active' === $pref['status'] ) {
				return array( $csv_type, $value, 'keep', 'active', $pref['origins'], $pref['slugs'], $size, $count, 'heuristic' );
			}
			if ( 'inactive' === $pref['status'] ) {
				return array( $csv_type, $value, 'disposable', 'inactive', $pref['origins'], $pref['slugs'], $size, $count, 'heuristic' );
			}
		}

		// Otherwise, try to guess inactive slugs by prefix.
		$sus = $this->suspect_by_prefix( $bucket, $value );
		if ( 'suspected' === $sus['status'] ) {
			$resolved = $this->resolve_installed_slugs_list( $sus['slugs'] );
			if ( ! empty( $resolved['active'] ) ) {
				return array( $csv_type, $value, 'keep', 'active', (string) $resolved['origins_active'], implode( '|', $resolved['active'] ), $size, $count, 'heuristic' );
			}
			if ( ! empty( $resolved['inactive'] ) ) {
				return array( $csv_type, $value, 'disposable', 'inactive', (string) $resolved['origins_inactive'], implode( '|', $resolved['inactive'] ), $size, $count, 'heuristic' );
			}
		}

		// Last chance: match against public datasets (wpoptimizing.com).
		$ds = $this->dataset_lookup( $bucket, $value );
		if ( ! empty( $ds['slugs'] ) ) {
			$resolved = $this->resolve_installed_slugs_list( $ds['slugs'] );
			if ( ! empty( $resolved['active'] ) ) {
				return array( $csv_type, $value, 'keep', 'active', (string) $resolved['origins_active'], implode( '|', $resolved['active'] ), $size, $count, 'weak' );
			}
			if ( ! empty( $resolved['inactive'] ) ) {
				return array( $csv_type, $value, 'disposable', 'inactive', (string) $resolved['origins_inactive'], implode( '|', $resolved['inactive'] ), $size, $count, 'weak' );
			}
		}

		// A non-core capability nobody claims is still granted by a role: deleting it strips it
		// from every role at once. Without an owner it is kept, never offered.
		if ( 'capabilities' === $bucket ) {
			return array( $csv_type, $value, 'keep', 'active', '', '', $size, $count, 'none' );
		}

		// No attribution found.
		return array( $csv_type, $value, 'disposable', 'none', '', '', $size, $count, 'none' );
	}

	/**
	 * Save the disposable DB CSV with header.
	 *
	 * @param array<int,array{string,string,string,string,string,string,int,int,string}> $rows Rows.
	 * @return void
	 */
	protected function save_csv( $rows ) {
		$tmp_path = wpoptimizing_atomic_tmp_path( $this->csv_result_path );
		if ( file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		$fh = fopen( $tmp_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}
		fputcsv( $fh, array( 'type', 'value', 'suggested_status', 'project_status', 'project_origins', 'project_slugs', 'size_bytes', 'count_rows', 'confianza' ), ',', '"', '\\' );
		foreach ( (array) $rows as $item ) {
			fputcsv( $fh, $item, ',', '"', '\\' );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		// Atomic swap to avoid partial reads. rename() is atomic within the same
		// directory and overwrites the destination in one step, so a reader never
		// sees a missing or partially written file (copy()+delete left a window
		// where the destination did not exist).
		if ( file_exists( $tmp_path ) ) {
			rename( $tmp_path, $this->csv_result_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename, WordPress.WP.AlternativeFunctions.rename_rename
		}
	}

	/**
	 * Get option sizes (bytes) keyed by option name.
	 *
	 * @return array<string,int>
	 */
	protected function get_option_sizes() {
		global $wpdb;
		$map  = array();
		$rows = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) AS size_bytes FROM {$wpdb->options}", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $this->failed( 'option sizes' ) || ! is_array( $rows ) ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			if ( empty( $row['option_name'] ) ) {
				continue;
			}
			$map[ (string) $row['option_name'] ] = (int) ( $row['size_bytes'] ?? 0 );
		}
		return $map;
	}

	/**
	 * Get meta stats (byte total + row count) keyed by meta_key for a given meta table.
	 *
	 * @param string $table Meta table name.
	 * @return array<string,array{size:int,count:int}>
	 */
	protected function get_meta_sizes( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( '' === $table ) {
			return array();
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! $exists ) {
			return array();
		}
		// On very large meta tables, SUM(LENGTH(meta_value)) reads every row's value
		// (multi-minute, heavy I/O on the live DB). In large-site mode, group by the
		// indexed meta_key only (no value reads) and report size as unknown (0). A
		// statement timeout keeps either variant from running away.
		$large = function_exists( 'wpoptimizing_scan_large_site' ) && wpoptimizing_scan_large_site();
		if ( function_exists( 'wpoptimizing_db_statement_timeout' ) ) {
			wpoptimizing_db_statement_timeout( $large ? 20 : 60 );
		}
		$size_expr = $large ? '0' : 'SUM(LENGTH(meta_value))';
		$rows      = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			'SELECT meta_key, COUNT(*) AS count_rows, ' . $size_expr . ' AS size_bytes FROM ' . $this->quote_identifier( $table ) . ' GROUP BY meta_key', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		if ( $this->failed( 'meta sizes of ' . $this->strip_table_prefix( $table ) ) || ! is_array( $rows ) ) {
			return array();
		}
		$map = array();
		foreach ( $rows as $row ) {
			if ( '' === (string) ( $row['meta_key'] ?? '' ) ) {
				continue;
			}
			$map[ (string) $row['meta_key'] ] = array(
				'size'  => (int) ( $row['size_bytes'] ?? 0 ),
				'count' => (int) ( $row['count_rows'] ?? 0 ),
			);
		}
		return $map;
	}

	/**
	 * Get table sizes (bytes) keyed by table name without prefix.
	 *
	 * @return array<string,int>
	 */
	protected function get_table_sizes() {
		global $wpdb;
		if ( function_exists( 'wpoptimizing_db_live_stats' ) ) {
			wpoptimizing_db_live_stats();
		}
		$map  = array();
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'SELECT table_name, (data_length + index_length) AS size_bytes FROM information_schema.TABLES WHERE table_schema = DATABASE()',
			ARRAY_A
		);
		if ( ! is_array( $rows ) || empty( $rows ) ) {
			$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}
		if ( ! is_array( $rows ) ) {
			return $map;
		}
		foreach ( $rows as $row ) {
			$table = (string) ( $row['table_name'] ?? $row['Name'] ?? '' );
			if ( '' === $table ) {
				continue;
			}
			$size = 0;
			if ( isset( $row['size_bytes'] ) ) {
				$size = (int) $row['size_bytes'];
			} elseif ( isset( $row['Data_length'] ) || isset( $row['Index_length'] ) ) {
				$size = (int) ( (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) );
			}
			$no_prefix         = $this->strip_table_prefix( $table );
			$map[ $no_prefix ] = $size;
		}
		return $map;
	}

	/**
	 * Strip the DB prefix from a table name if present.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	protected function strip_table_prefix( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( '' === $table ) {
			return '';
		}
		$prefix = (string) $wpdb->prefix;
		if ( '' !== $prefix && 0 === strpos( $table, $prefix ) ) {
			return substr( $table, strlen( $prefix ) );
		}
		return $table;
	}

	/**
	 * Whether a physical table is a WordPress core table — multisite aware.
	 *
	 * In a network the database holds the core tables of EVERY sub-site, named
	 * "<base_prefix><blog_id>_<core_name>" (e.g. wp_2_posts), plus the global
	 * network tables (wp_blogs, wp_site, wp_sitemeta, …). Using $wpdb->prefix (the
	 * main site's) would leave a leftover "<blog_id>_" and miss them, so they were
	 * wrongly flagged as unknown/disposable. We strip the base prefix and the
	 * optional per-blog segment before matching, and recognise network tables.
	 *
	 * @param string $table Full physical table name.
	 * @return bool
	 */
	protected function is_core_table( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( '' === $table ) {
			return false;
		}
		$base = (string) ( ! empty( $wpdb->base_prefix ) ? $wpdb->base_prefix : $wpdb->prefix );
		if ( '' === $base || 0 !== strpos( $table, $base ) ) {
			return false;
		}
		$rest    = substr( $table, strlen( $base ) );
		$blog_id = 0;
		if ( preg_match( '/^([0-9]+)_(.+)$/', $rest, $m ) ) {
			// Per-blog table: wp_<id>_<name>.
			$blog_id = (int) $m[1];
			$name    = $m[2];
		} else {
			$name = $rest;
		}
		// Single-site / main-site / per-blog core tables (posts, options, …).
		if ( $this->is_native( 'tables', $name ) ) {
			return true;
		}
		// Multisite network/global tables (base-prefixed, no per-blog id).
		if ( 0 === $blog_id && function_exists( 'is_multisite' ) && is_multisite() ) {
			$network = array( 'blogs', 'blogmeta', 'blog_versions', 'registration_log', 'signups', 'site', 'sitemeta' );
			if ( in_array( $name, $network, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a physical table belongs to the current blog (multisite aware).
	 *
	 * Single-site, or tables that do not start with the base prefix, always count as
	 * "this blog" (foreign/unknown tables are still evaluated). In a network, a
	 * per-blog table named "<base><id>_<name>" only belongs here when <id> matches the
	 * current blog; base-prefixed tables with no id segment belong to the main site.
	 *
	 * @param string $table Full physical table name.
	 * @return bool
	 */
	protected function table_belongs_to_current_blog( $table ) {
		return wpoptimizing_table_belongs_to_current_blog( $table );
	}

	/**
	 * Return a simple leading token from a key (before '_' or '-').
	 * Example: 'updraft' from 'updraft_debug_mode'.
	 *
	 * @param string $key Option/table key.
	 * @return string
	 */
	protected function simple_prefix( $key ) {
		if ( preg_match( '/^([a-z0-9]+)[_-]/i', (string) $key, $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * Given a non-native, non-active-protected value, try to suspect inactive projects by prefix.
	 *
	 * Rule of thumb:
	 * - If the value begins with an INACTIVE project slug, or
	 * - Its simple prefix (before '_' or '-') equals an INACTIVE slug,
	 * mark as "suspected" and return the matching slugs/origins.
	 *
	 * @param string $bucket One of: options|transients|post_types|taxonomies|roles|tables|post_meta|user_meta|term_meta|meta_key|shortcodes
	 * @param string $value  The value being evaluated
	 * @return array{status:string,origins:string,slugs:string}  status ∈ {'none','suspected'}
	 */
	protected function suspect_by_prefix( $bucket, $value ) {
		if ( $this->strict_attribution ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}

		$inactive_meta = (array) ( $this->det_map[ $bucket ][ $value ]['inactive'] ?? array() );

		// Already handled by build_disposable_row() if present.
		if ( ! empty( $inactive_meta ) ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}

		// Build the set of inactive slugs seen anywhere in this bucket.
		$inactive_slugs = array();
		foreach ( $this->det_map[ $bucket ] as $_val => $arr ) {
			foreach ( (array) ( $arr['inactive'] ?? array() ) as $meta ) {
				$slug = strtolower( (string) $meta['slug'] );
				$ori  = (string) ( $meta['origen'] ?? '' );
				if ( '' !== $slug ) {
					$inactive_slugs[ $slug ] = $ori;
				}
			}
		}

		if ( empty( $inactive_slugs ) ) {
			return array(
				'status'  => 'none',
				'origins' => '',
				'slugs'   => '',
			);
		}

		$key  = strtolower( (string) $value );
		$pref = $this->simple_prefix( $key );
		$hits = array();
		$oris = array();

		foreach ( $inactive_slugs as $slug => $ori ) {
			if ( '' === $slug ) {
				continue; }
			if ( ( 0 === strpos( $key, $slug ) || ( $pref && $pref === $slug ) )
				&& $this->heuristic_slug_has_evidence( $slug, $value, $bucket ) ) {
				$hits[] = $slug;
				if ( $ori ) {
					$oris[] = $ori; }
			}
		}

		if ( ! empty( $hits ) ) {
			$hits = array_values( array_unique( $hits ) );
			$oris = array_values( array_unique( $oris ) );
			return array(
				'status'  => 'suspected',
				'origins' => implode( '|', $oris ),
				'slugs'   => implode( '|', $hits ),
			);
		}

		return array(
			'status'  => 'none',
			'origins' => '',
			'slugs'   => '',
		);
	}

	/**
	 * Validate heuristic slug matches against scanner evidence and code contents.
	 *
	 * @param string $slug   Candidate slug.
	 * @param string $value  DB value being classified.
	 * @param string $bucket Internal bucket.
	 * @return bool
	 */
	protected function heuristic_slug_has_evidence( $slug, $value, $bucket ) {
		$slug  = (string) $slug;
		$value = strtolower( (string) $value );
		$key   = $bucket . '|' . $slug . '|' . $value;
		if ( isset( $this->heuristic_evidence_cache[ $key ] ) ) {
			return $this->heuristic_evidence_cache[ $key ];
		}

		// Fast path: scanner already found a compatible value for this slug in the same bucket.
		if ( $this->bucket_has_scanner_evidence_for_slug( $bucket, $slug, $value ) ) {
			$this->heuristic_evidence_cache[ $key ] = true;
			return true;
		}

		// Meta buckets require a stricter check: the exact key must appear in a write
		// context. A plugin that merely READS a meta key (get_post_meta) must not be
		// attributed as its owner — only plugins that write/register it count.
		$is_meta_bucket = in_array( $bucket, array( 'post_meta', 'user_meta', 'term_meta' ), true );
		if ( $is_meta_bucket ) {
			$has                                    = $this->search_slug_code_for_meta_write( $slug, $value );
			$this->heuristic_evidence_cache[ $key ] = $has;
			return $has;
		}

		$tokens = array( $value );
		foreach ( array( '_transient_timeout_', '_site_transient_timeout_', '_transient_', '_site_transient_' ) as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				$tokens[] = substr( $value, strlen( $prefix ) );
				break;
			}
		}
		$simple = $this->simple_prefix( $value );
		if ( '' !== $simple ) {
			$tokens[] = $simple;
		}
		// Do NOT add the slug itself as a token: every plugin's own code contains
		// its own name, so it would always pass the evidence check and produce
		// false-positive attributions for any value whose pattern happens to match.
		$tokens = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $token ) {
							return strtolower( trim( (string) $token ) );
						},
						$tokens
					),
					static function ( $token ) {
						return strlen( $token ) >= 6;
					}
				)
			)
		);

		// Without any token long enough to search for, we cannot confirm evidence.
		if ( empty( $tokens ) ) {
			$this->heuristic_evidence_cache[ $key ] = false;
			return false;
		}

		$has                                    = $this->search_slug_code_for_tokens( $slug, $tokens );
		$this->heuristic_evidence_cache[ $key ] = $has;
		return $has;
	}

	/**
	 * Check if scanner map contains any row for a bucket/slug combination.
	 *
	 * @param string $bucket Bucket.
	 * @param string $slug   Slug.
	 * @return bool
	 */
	protected function bucket_has_scanner_evidence_for_slug( $bucket, $slug, $value = '' ) {
		$cache_key = $bucket . '|' . $slug . '|' . strtolower( (string) $value );
		if ( isset( $this->bucket_slug_cache[ $cache_key ] ) ) {
			return $this->bucket_slug_cache[ $cache_key ];
		}

		foreach ( (array) ( $this->det_map[ $bucket ] ?? array() ) as $known_value => $statuses ) {
			foreach ( array( 'active', 'inactive' ) as $status_key ) {
				foreach ( (array) ( $statuses[ $status_key ] ?? array() ) as $meta ) {
					if ( isset( $meta['slug'] ) && (string) $meta['slug'] === (string) $slug && $this->value_matches_detected_candidate( $bucket, $value, (string) $known_value ) ) {
						$this->bucket_slug_cache[ $cache_key ] = true;
						return true;
					}
				}
			}
		}

		$this->bucket_slug_cache[ $cache_key ] = false;
		return false;
	}

	/**
	 * Check whether a candidate DB value is compatible with a known scanner value.
	 *
	 * @param string $bucket     Bucket.
	 * @param string $candidate  Candidate value.
	 * @param string $known_value Known scanner value.
	 * @return bool
	 */
	protected function value_matches_detected_candidate( $bucket, $candidate, $known_value ) {
		$candidate   = $this->normalize_bucket_value( $bucket, (string) $candidate );
		$known_value = $this->normalize_bucket_value( $bucket, (string) $known_value );
		if ( '' === $candidate || '' === $known_value ) {
			return false;
		}
		if ( $candidate === $known_value ) {
			return true;
		}

		$candidate_base   = $this->normalize_value_for_evidence( $candidate );
		$known_base       = $this->normalize_value_for_evidence( $known_value );
		$candidate_pref   = $this->derive_detected_prefix( $candidate_base );
		$known_pref       = $this->derive_detected_prefix( $known_base );
		$candidate_simple = $this->simple_prefix( $candidate_base );
		$known_simple     = $this->simple_prefix( $known_base );

		if ( '' !== $candidate_pref && $candidate_pref === $known_pref ) {
			return true;
		}
		if ( strlen( $candidate_simple ) >= 4 && $candidate_simple === $known_simple ) {
			return true;
		}
		if ( strlen( $known_base ) >= 6 && 0 === strpos( $candidate_base, $known_base ) ) {
			return true;
		}
		if ( strlen( $candidate_base ) >= 6 && 0 === strpos( $known_base, $candidate_base ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Normalize volatile wrappers before comparing values.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	protected function normalize_value_for_evidence( $value ) {
		$value = strtolower( trim( (string) $value ) );
		foreach ( array( '_transient_timeout_', '_site_transient_timeout_', '_transient_', '_site_transient_' ) as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				return substr( $value, strlen( $prefix ) );
			}
		}
		return $value;
	}

	/**
	 * Search plugin/theme code files for any token.
	 *
	 * @param string   $slug   Slug.
	 * @param string[] $tokens Lowercased tokens.
	 * @return bool
	 */
	/**
	 * The directories a slug may be read from, or an empty list.
	 *
	 * Today every slug reaching the code walkers comes from get_plugins(), get_stylesheet() or
	 * get_template() — the filesystem describing itself — so a traversal is not reachable. That
	 * is a property of the callers, and callers change: three of the flaws found in this audit
	 * were guards that were correct until something new called them. A slug is a directory
	 * name, so it is checked as one before it is ever concatenated to a path, and the check
	 * lives here, where the path is built.
	 *
	 * @since 0.1.0
	 * @param string $slug Plugin or theme slug.
	 * @return string[] Existing directories to walk.
	 */
	protected function slug_code_dirs( $slug ) {
		$slug = (string) $slug;
		// A directory name, and nothing that can climb out of one.
		if ( '' === $slug || ! preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) || false !== strpos( $slug, '..' ) ) {
			return array();
		}

		$dirs = array();
		foreach ( array( WP_PLUGIN_DIR, get_theme_root() ) as $root ) {
			$dir = wp_normalize_path( trailingslashit( (string) $root ) . $slug );
			if ( is_dir( $dir ) ) {
				$dirs[] = $dir;
			}
		}

		return $dirs;
	}

	protected function search_slug_code_for_tokens( $slug, array $tokens ) {
		if ( empty( $tokens ) ) {
			return false;
		}

		$base_dirs = $this->slug_code_dirs( $slug );
		if ( empty( $base_dirs ) ) {
			return false;
		}

		$skip_dirs = array( 'vendor', 'node_modules', '.git', '.svn', 'tests', 'test', 'languages' );
		$max_files = 600;

		foreach ( $base_dirs as $base_dir ) {
			try {
				$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
				$files = 0;
				foreach ( $it as $info ) {
					if ( function_exists( 'wpoptimizing_scan_heartbeat' ) ) {
						wpoptimizing_scan_heartbeat(); // Up to 600 files for one row: keep the stage alive.
					}
					if ( ! $info->isFile() ) {
						continue;
					}
					$path = wp_normalize_path( $info->getPathname() );
					$rel  = ltrim( str_replace( $base_dir, '', $path ), '/' );
					if ( '' === $rel ) {
						continue;
					}
					$parts = explode( '/', $rel );
					$skip  = false;
					foreach ( $parts as $part ) {
						if ( in_array( $part, $skip_dirs, true ) ) {
							$skip = true;
							break;
						}
					}
					if ( $skip ) {
						continue;
					}
					$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					if ( '' === $ext || ! in_array( $ext, $this->heuristic_scan_ext, true ) ) {
						continue;
					}
					$size = (int) $info->getSize();
					if ( $size <= 0 || $size > 1024 * 1024 ) {
						continue;
					}
					$content = file_get_contents( $path ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
					if ( ! is_string( $content ) || '' === $content ) {
						continue;
					}
					$content_lc = strtolower( $content );
					foreach ( $tokens as $token ) {
						if ( false !== strpos( $content_lc, $token ) ) {
							return true;
						}
					}
					++$files;
					if ( $files >= $max_files ) {
						break;
					}
				}
			} catch ( Exception $e ) {
				continue;
			}
		}

		return false;
	}

	/**
	 * Check that a plugin's code writes (or registers) a specific meta key.
	 *
	 * A plugin that only reads a meta key via get_post_meta/get_user_meta/get_term_meta
	 * is NOT the owner of that key. This check requires the exact quoted key to appear
	 * in the same file as a write function, preventing false attributions like
	 * `_weight` → APG Weight Shipping (which reads it from WooCommerce products).
	 *
	 * @param string $slug     Plugin/theme slug.
	 * @param string $meta_key Exact meta key (e.g. '_weight', 'free_shipping').
	 * @return bool
	 */
	protected function search_slug_code_for_meta_write( $slug, $meta_key ) {
		static $write_functions = array(
			'add_post_meta',
			'update_post_meta',
			'delete_post_meta',
			'add_user_meta',
			'update_user_meta',
			'delete_user_meta',
			'add_term_meta',
			'update_term_meta',
			'delete_term_meta',
			'register_meta',
		);

		if ( '' === $meta_key ) {
			return false;
		}

		$base_dirs = $this->slug_code_dirs( $slug );
		if ( empty( $base_dirs ) ) {
			return false;
		}

		$skip_dirs = array( 'vendor', 'node_modules', '.git', '.svn', 'tests', 'test', 'languages' );
		$max_files = 600;
		// The key must appear as a quoted string literal in the source.
		$needle_sq = "'" . $meta_key . "'";
		$needle_dq = '"' . $meta_key . '"';

		foreach ( $base_dirs as $base_dir ) {
			try {
				$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
				$files = 0;
				foreach ( $it as $info ) {
					if ( function_exists( 'wpoptimizing_scan_heartbeat' ) ) {
						wpoptimizing_scan_heartbeat(); // Up to 600 files for one row: keep the stage alive.
					}
					if ( ! $info->isFile() ) {
						continue;
					}
					$path = wp_normalize_path( $info->getPathname() );
					$rel  = ltrim( str_replace( $base_dir, '', $path ), '/' );
					if ( '' === $rel ) {
						continue;
					}
					$parts = explode( '/', $rel );
					$skip  = false;
					foreach ( $parts as $part ) {
						if ( in_array( $part, $skip_dirs, true ) ) {
							$skip = true;
							break;
						}
					}
					if ( $skip ) {
						continue;
					}
					$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
					if ( '' === $ext || ! in_array( $ext, $this->heuristic_scan_ext, true ) ) {
						continue;
					}
					$size = (int) $info->getSize();
					if ( $size <= 0 || $size > 1024 * 1024 ) {
						continue;
					}
					$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents
					if ( ! is_string( $content ) || '' === $content ) {
						continue;
					}
					$content_lc = strtolower( $content );

					// The exact meta key must appear as a quoted string literal in this file.
					if ( false === strpos( $content_lc, $needle_sq ) && false === strpos( $content_lc, $needle_dq ) ) {
						++$files;
						if ( $files >= $max_files ) {
							break;
						}
						continue;
					}

					// AND a write/register function must also appear in the same file.
					foreach ( $write_functions as $func ) {
						if ( false !== strpos( $content_lc, $func ) ) {
							return true;
						}
					}

					++$files;
					if ( $files >= $max_files ) {
						break;
					}
				}
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				continue;
			}
		}

		return false;
	}

	/**
	 * Dataset lookup by bucket/value.
	 *
	 * @param string $bucket Bucket name.
	 * @param string $value  Value.
	 * @return array{origins:string,slugs:string}
	 */
	protected function dataset_lookup( $bucket, $value ) {
		$type_map = array(
			'options'    => 'option',
			'post_types' => 'post_type',
			'taxonomies' => 'taxonomy',
			'roles'      => 'role',
			'tables'     => 'table',
		);

		$type = $type_map[ $bucket ] ?? '';
		if ( '' === $type || empty( $this->dataset_map[ $type ] ) ) {
			return array(
				'origins' => '',
				'slugs'   => '',
			);
		}

		$key = strtolower( trim( (string) $value ) );
		if ( '' === $key ) {
			return array(
				'origins' => '',
				'slugs'   => '',
			);
		}

		$hit = $this->dataset_map[ $type ][ $key ] ?? null;
		if ( ! is_array( $hit ) ) {
			return array(
				'origins' => '',
				'slugs'   => '',
			);
		}

		return array(
			'origins' => (string) ( $hit['origins'] ?? '' ),
			'slugs'   => (string) ( $hit['slugs'] ?? '' ),
		);
	}
}
