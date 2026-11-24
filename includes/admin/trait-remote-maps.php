<?php
/**
 * Remote KB attribution: load the downloaded manifest/runtime CSV maps and resolve each orphan candidate to an extension + confidence tier.
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// Their only clients: attribute_language_variants() and attribute_by_live_registry().
require_once dirname( __DIR__ ) . '/resolvers/language-variants.php';
require_once dirname( __DIR__ ) . '/resolvers/live-registry.php';

trait WPOptimizing_Remote_Maps_Trait {

	/**
	 * Normalize a wp-content relative path from remote CSV.
	 *
	 * @param string $path Raw path.
	 * @return string
	 */
	protected function normalize_remote_rel_path( $path ) {
		$path = trim( (string) $path );
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '#^/+#', '', $path );
		$path = preg_replace( '#^wp-content/#', '', $path );
		return $path;
	}

	/**
	 * Detect CSV delimiter from the first non-empty line.
	 *
	 * @param string $csv Raw CSV.
	 * @return string
	 */
	protected function detect_csv_delimiter( $csv ) {
		$lines = preg_split( "/\\r\\n|\\n|\\r/", (string) $csv );
		$first = '';
		foreach ( $lines as $line ) {
			if ( '' !== trim( (string) $line ) ) {
				$first = (string) $line;
				break;
			}
		}
		$counts = array(
			','  => substr_count( $first, ',' ),
			';'  => substr_count( $first, ';' ),
			"\t" => substr_count( $first, "\t" ),
		);
		arsort( $counts );
		return (string) array_key_first( $counts );
	}

	/**
	 * Load remote CSV mappings (used only to annotate local orphans).
	 *
	 * @return void
	 */
	protected function load_remote_maps() {
		if ( null !== $this->remote_db_map && null !== $this->remote_fs_map ) {
			return;
		}

		$this->remote_db_map          = array();
		$this->remote_db_value_map    = array();
		$this->remote_db_confidence   = array();
		$this->remote_files_value_map = array();
		$this->remote_slug_index      = array();
		$this->remote_slug_name_map   = array();
		$this->remote_fs_map          = array(
			'files' => array(),
			'dirs'  => array(),
		);

		$db_csv = wpoptimizing_get_cached_csv( 'db' );
		if ( ! is_wp_error( $db_csv ) && '' !== $db_csv ) {
			$delimiter = $this->detect_csv_delimiter( $db_csv );
			$fh        = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $fh ) {
				fwrite( $fh, $db_csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				rewind( $fh );
				while ( ( $row = fgetcsv( $fh, 0, $delimiter, '"', '\\' ) ) !== false ) {
					if ( ! is_array( $row ) || count( $row ) < 10 ) {
						continue;
					}
					if ( isset( $row[0] ) ) {
						$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
					}
					// Legacy remote format: [file, bd|archivos, slug, name, origin, file, dir, type, func, value]
					if ( ! isset( $row[1] ) || 'bd' !== strtolower( (string) $row[1] ) ) {
						continue;
					}
					$type  = isset( $row[7] ) ? strtolower( (string) $row[7] ) : '';
					$value = isset( $row[9] ) ? (string) $row[9] : '';
					if ( '' === $type || '' === $value ) {
						continue;
					}
					$origin = isset( $row[4] ) ? (string) $row[4] : '';
					$slug   = wpoptimizing_shared_slug( isset( $row[2] ) ? (string) $row[2] : '' ); // An internal marker of the feeder is no owner.
					$name   = isset( $row[3] ) ? (string) $row[3] : '';
					$key    = $type . '::' . strtolower( $value );
					// Crowd confidence: distinct sites confirming this attribution
					// (curated 11th column). Absent on older datasets → treated as 1.
					$conf = ( count( $row ) >= 11 && is_numeric( $row[10] ) ) ? (int) $row[10] : 1;
					if ( ! isset( $this->remote_db_confidence[ $key ] ) || $conf > $this->remote_db_confidence[ $key ] ) {
						$this->remote_db_confidence[ $key ] = $conf;
					}
					$vk = strtolower( $value );
					if ( ! isset( $this->remote_db_confidence[ $vk ] ) || $conf > $this->remote_db_confidence[ $vk ] ) {
						$this->remote_db_confidence[ $vk ] = $conf;
					}
					$this->register_remote_pair( $this->remote_db_map, $key, $origin, $slug );
					if ( '' !== $slug ) {
						if ( ! isset( $this->remote_slug_index[ $slug ] ) ) {
							$this->remote_slug_index[ $slug ] = array();
						}
						$this->remote_slug_index[ $slug ][ (string) $origin ] = true;
						if ( '' !== $name && ! isset( $this->remote_slug_name_map[ $slug ] ) ) {
							$this->remote_slug_name_map[ $slug ] = $name;
						}
					}

					$val_key = strtolower( $value );
					if ( '' !== $val_key ) {
						if ( ! isset( $this->remote_db_value_map[ $val_key ] ) ) {
							$this->remote_db_value_map[ $val_key ] = array(
								'origins' => array(),
								'slugs'   => array(),
							);
						}
						if ( '' !== $origin ) {
							$this->remote_db_value_map[ $val_key ]['origins'][ $origin ] = true;
						}
						if ( '' !== $slug ) {
							$this->remote_db_value_map[ $val_key ]['slugs'][ $slug ] = true;
						}
						$this->register_remote_pair( $this->remote_db_value_map, $val_key, $origin, $slug );
					}
				}
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}

		$fs_csv = wpoptimizing_get_cached_csv( 'files' );
		if ( ! is_wp_error( $fs_csv ) && '' !== $fs_csv ) {
			$delimiter = $this->detect_csv_delimiter( $fs_csv );
			$fh        = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( $fh ) {
				fwrite( $fh, $fs_csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				rewind( $fh );
				while ( ( $row = fgetcsv( $fh, 0, $delimiter, '"', '\\' ) ) !== false ) {
					if ( ! is_array( $row ) || count( $row ) < 10 ) {
						continue;
					}
					if ( isset( $row[0] ) ) {
						$row[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $row[0] );
					}
					// Legacy remote format: [file, bd|archivos, slug, name, origin, file, dir, type, func, value]
					if ( ! isset( $row[1] ) || 'archivos' !== strtolower( (string) $row[1] ) ) {
						continue;
					}
					$file = isset( $row[5] ) ? $this->normalize_remote_rel_path( $row[5] ) : '';
					$dir  = isset( $row[6] ) ? $this->normalize_remote_rel_path( $row[6] ) : '';
					$slug = wpoptimizing_shared_slug( isset( $row[2] ) ? (string) $row[2] : '' );
					$orig = isset( $row[4] ) ? (string) $row[4] : '';
					$name = isset( $row[3] ) ? (string) $row[3] : '';
					$type = isset( $row[7] ) ? strtolower( (string) $row[7] ) : '';
					$val  = isset( $row[9] ) ? strtolower( (string) $row[9] ) : '';
					if ( '' !== $file ) {
						$this->register_remote_pair( $this->remote_fs_map['files'], $file, $orig, $slug );
					}
					if ( '' !== $dir ) {
						$this->register_remote_pair( $this->remote_fs_map['dirs'], $dir, $orig, $slug );
						// A data folder from the gateway's reviewed list (wp-content/litespeed is
						// LiteSpeed Cache's), not a site's vote: it is trusted as evidence.
						if ( 'data_dir' === $type ) {
							$this->remote_fs_map['dirs'][ $dir ]['curated'] = true;
							continue;
						}
					}
					if ( '' !== $type && '' !== $val ) {
						$key = $type . '::' . $val;
						$this->register_remote_pair( $this->remote_files_value_map, $key, $orig, $slug );
					}
					if ( '' !== $slug ) {
						if ( ! isset( $this->remote_slug_index[ $slug ] ) ) {
							$this->remote_slug_index[ $slug ] = array();
						}
						$this->remote_slug_index[ $slug ][ (string) $orig ] = true;
						if ( '' !== $name && ! isset( $this->remote_slug_name_map[ $slug ] ) ) {
							$this->remote_slug_name_map[ $slug ] = $name;
						}
					}
				}
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
	}

	/**
	 * Register one origin/slug pair under a remote map key.
	 *
	 * @param array  $store  Target map.
	 * @param string $key    Map key.
	 * @param string $origin Extension origin.
	 * @param string $slug   Extension slug.
	 * @return void
	 */
	protected function register_remote_pair( array &$store, $key, $origin, $slug ) {
		$key    = (string) $key;
		$origin = trim( (string) $origin );
		$slug   = trim( (string) $slug );
		if ( '' === $key || '' === $slug ) {
			return;
		}
		if ( ! isset( $store[ $key ] ) ) {
			$store[ $key ] = array();
		}
		if ( ! isset( $store[ $key ]['pairs'] ) || ! is_array( $store[ $key ]['pairs'] ) ) {
			$store[ $key ]['pairs'] = array();
		}
		$pair_key                            = $origin . ':' . $slug;
		$store[ $key ]['pairs'][ $pair_key ] = array(
			'origin' => $origin,
			'slug'   => $slug,
		);
	}

	/**
	 * Convert remote pairs into pipe-joined project fields.
	 *
	 * @param array $pairs Pairs list.
	 * @return array{origin:string,slug:string}
	 */
	protected function remote_pairs_to_pipe_values( array $pairs ) {
		$origins = array();
		$slugs   = array();
		foreach ( $pairs as $pair ) {
			$slug = trim( (string) ( $pair['slug'] ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$origin    = trim( (string) ( $pair['origin'] ?? '' ) );
			$origin    = '' !== $origin ? $origin : 'plugin';
			$origins[] = $origin;
			$slugs[]   = $slug;
		}
		return array(
			'origin' => implode( '|', $origins ),
			'slug'   => implode( '|', $slugs ),
		);
	}

	/**
	 * Crowd confidence (number of distinct sites that confirmed an attribution)
	 * for a remote DB key, or 0 when unknown.
	 *
	 * @param string $type  Item type.
	 * @param string $value Item value/key.
	 * @return int
	 */
	protected function resolve_remote_db_confidence( $type, $value ) {
		$this->load_remote_maps();
		$type = strtolower( trim( (string) $type ) );
		$val  = strtolower( trim( (string) $value ) );
		$key  = $type . '::' . $val;
		if ( isset( $this->remote_db_confidence[ $key ] ) ) {
			return (int) $this->remote_db_confidence[ $key ];
		}
		return isset( $this->remote_db_confidence[ $val ] ) ? (int) $this->remote_db_confidence[ $val ] : 0;
	}

	/**
	 * Map a crowd-confidence count to an attribution tier. A remote (crowd-sourced)
	 * match is "weak" by default, but enough independent confirmations promote it
	 * to "medium". "Strong" stays reserved for local activation-snapshot evidence.
	 *
	 * @param int $sites Distinct confirming sites.
	 * @return string 'weak'|'medium'
	 */
	protected function remote_confidence_tier( $sites ) {
		/** @since 0.1.0 Distinct sites needed to promote a remote match to "medium". */
		$threshold = (int) apply_filters( 'wpoptimizing_remote_confidence_threshold', 10 );
		return ( (int) $sites >= max( 2, $threshold ) ) ? 'medium' : 'weak';
	}

	/**
	 * Guarantee that an already-attributed, confirmed-installed row carries a confidence
	 * level. The scan attributed the row and its owner is a really-installed extension, so
	 * the Confidence column must never read "Undetermined": prefer the live runtime-map
	 * confidence for this key, otherwise fall back to "Possible" (a deterministic local/
	 * manifest match deserves at least that, regardless of crowd data). No-op when the row
	 * already has a level — we never downgrade a stronger source.
	 *
	 * @param array       $row      Row (by reference).
	 * @param object      $resolver Runtime resolver.
	 * @param string|null $tipo     Mapped runtime "tipo" (may be null when not mappable).
	 * @param string      $value    Row value (may be empty).
	 * @return void
	 */
	protected function ensure_attributed_row_confidence( array &$row, $resolver, $tipo, $value ) {
		$current = strtolower( trim( (string) ( $row['confianza'] ?? '' ) ) );
		if ( '' !== $current && 'none' !== $current ) {
			return; // Already has a confidence level — keep the stronger/explicit source.
		}
		$conf = '';
		if ( null !== $tipo && '' !== (string) $value && is_object( $resolver ) && method_exists( $resolver, 'get_confianza_for_key' ) ) {
			$conf = strtolower( trim( (string) $resolver->get_confianza_for_key( $tipo, (string) $value ) ) );
		}
		$row['confianza'] = ( '' !== $conf && 'none' !== $conf ) ? $conf : 'medium';
	}

	/**
	 * Rellena la CLASE del dueño con la que midió quien capturó la clave, si falta.
	 *
	 * Desde el 9/10/2026 la capa `runtime` trae una columna `clase`, y esto es lo único que
	 * cubre el caso que la deducción local no puede cubrir: la clase se deducía del índice de
	 * extensiones instaladas, y un huérfano —por definición— ya no está instalado. De ese hueco
	 * venía la fila que enseña el nombre sin decir si era un plugin o un tema.
	 *
	 * RELLENA Y NO CORRIGE. Sólo entra cuando ya hay un nombre que etiquetar y su clase está
	 * vacía o es `unknown`: una clase que ya viene de lo instalado es verdad local y gana. Y en
	 * particular NO toca el slug ni el estado de la fila, que es deliberado — mover una fila a
	 * Huérfanos es ponerla delante del botón de borrar, y el mapa puede traer el slug anterior
	 * de una extensión renombrada. Una etiqueta de más informa; una fila de más ahí es un riesgo.
	 *
	 * @param array       $row      Fila, por referencia.
	 * @param object      $resolver Resolutor del mapa.
	 * @param string|null $tipo     Tipo en el vocabulario del mapa.
	 * @param string      $value    Clave.
	 * @return void
	 */
	protected function fill_missing_class( array &$row, $resolver, $tipo, $value ) {
		if ( null === $tipo || '' === (string) $value || ! is_object( $resolver ) || ! method_exists( $resolver, 'get_clase_for_key' ) ) {
			return;
		}
		if ( '' === trim( (string) ( $row['project_slugs'] ?? '' ) ) ) {
			return; // Sin nombre no hay nada que etiquetar.
		}
		$etiqueta = strtolower( trim( (string) ( $row['project_origins'] ?? '' ) ) );
		if ( '' !== $etiqueta && 'unknown' !== $etiqueta ) {
			return;
		}
		$medida = (string) $resolver->get_clase_for_key( $tipo, $value );
		if ( '' !== $medida ) {
			$row['project_origins'] = $medida;
		}
	}

	/**
	 * Resolve remote extension for a DB row without mutating it.
	 *
	 * @param string $type  DB type.
	 * @param string $value DB value.
	 * @return array{slug:string,origin:string}
	 */
	protected function resolve_remote_db_extension( $type, $value ) {
		$this->load_remote_maps();
		$type = strtolower( trim( (string) $type ) );
		$val  = strtolower( trim( (string) $value ) );
		if ( '' === $type || '' === $val ) {
			return array(
				'slug'   => '',
				'origin' => '',
			);
		}

		$key = $type . '::' . $val;
		if ( isset( $this->remote_db_map[ $key ] ) ) {
			$pairs = (array) ( $this->remote_db_map[ $key ]['pairs'] ?? array() );
			return $this->remote_pairs_to_pipe_values( $pairs );
		}

		if ( isset( $this->remote_db_value_map[ $val ] ) ) {
			$pairs = (array) ( $this->remote_db_value_map[ $val ]['pairs'] ?? array() );
			if ( ! empty( $pairs ) ) {
				return $this->remote_pairs_to_pipe_values( $pairs );
			}
		}

		$type_map = array(
			'post_type'     => array( 'custom_type', 'post_type' ),
			'taxonomy'      => array( 'taxonomy' ),
			'role'          => array( 'role' ),
			'option'        => array( 'option' ),
			'update_option' => array( 'option' ),
			'delete_option' => array( 'option' ),
			'table'         => array( 'table' ),
			'post_meta'     => array( 'post_meta', 'meta_key' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'user_meta' => array( 'user_meta', 'meta_key' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'term_meta' => array( 'term_meta', 'meta_key' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'  => array( 'meta_key' ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'shortcode'     => array( 'shortcode' ),
		);
		if ( isset( $type_map[ $type ] ) ) {
			foreach ( $type_map[ $type ] as $remote_type ) {
				$key = $remote_type . '::' . $val;
				if ( isset( $this->remote_files_value_map[ $key ] ) ) {
					$pairs = (array) ( $this->remote_files_value_map[ $key ]['pairs'] ?? array() );
					return $this->remote_pairs_to_pipe_values( $pairs );
				}
			}
		}

		if ( ! empty( $this->remote_slug_index ) ) {
			$hits = array();
			foreach ( $this->remote_slug_index as $slug => $origins_set ) {
				if ( '' !== $slug && ( 0 === strpos( $val, $slug ) || false !== strpos( $val, $slug . '_' ) ) ) {
					$hits[] = array(
						'slug'   => (string) $slug,
						'origin' => is_array( $origins_set ) && ! empty( $origins_set ) ? (string) array_key_first( $origins_set ) : '',
					);
				}
			}
			if ( ! empty( $hits ) ) {
				return $this->remote_pairs_to_pipe_values( $hits );
			}
		}

		if ( ! empty( $this->remote_slug_name_map ) ) {
			$hits = array();
			foreach ( $this->remote_slug_name_map as $slug => $name ) {
				$slug_l = strtolower( $slug );
				$name_l = strtolower( $name );
				if ( '' !== $slug_l && false !== strpos( $val, $slug_l ) ) {
					$origins_set = $this->remote_slug_index[ $slug ] ?? array();
					$hits[]      = array(
						'slug'   => (string) $slug,
						'origin' => is_array( $origins_set ) && ! empty( $origins_set ) ? (string) array_key_first( $origins_set ) : '',
					);
					continue;
				}
				if ( '' !== $name_l && strlen( $name_l ) >= 4 && false !== strpos( $val, $name_l ) ) {
					$origins_set = $this->remote_slug_index[ $slug ] ?? array();
					$hits[]      = array(
						'slug'   => (string) $slug,
						'origin' => is_array( $origins_set ) && ! empty( $origins_set ) ? (string) array_key_first( $origins_set ) : '',
					);
				}
			}
			if ( ! empty( $hits ) ) {
				return $this->remote_pairs_to_pipe_values( $hits );
			}
		}

		return array(
			'slug'   => '',
			'origin' => '',
		);
	}

	/**
	 * Resolve remote extension for an FS row without mutating it.
	 *
	 * @param string $rel Relative path.
	 * @return array{slug:string,origin:string}
	 */
	protected function resolve_remote_fs_extension( $rel ) {
		$this->load_remote_maps();
		$rel = $this->normalize_remote_rel_path( $rel );
		if ( '' === $rel ) {
			return array(
				'slug'   => '',
				'origin' => '',
			);
		}
		if ( isset( $this->remote_fs_map['files'][ $rel ] ) ) {
			$pairs = (array) ( $this->remote_fs_map['files'][ $rel ]['pairs'] ?? array() );
			return $this->remote_pairs_to_pipe_values( $pairs );
		}
		$pairs = $this->remote_fs_dir_pairs( $rel );
		if ( null !== $pairs ) {
			return $this->remote_pairs_to_pipe_values( $pairs );
		}
		if ( ! empty( $this->remote_slug_index ) ) {
			$hits = array();
			foreach ( $this->remote_slug_index as $slug => $origins_set ) {
				// A whole path segment: '/jetpack' is in 'uploads/jetpack-boost' as a substring,
				// and that is not Jetpack's folder.
				if ( '' !== $slug && 1 === preg_match( '#(^|/)' . preg_quote( (string) $slug, '#' ) . '(/|$)#', $rel ) ) {
					$hits[] = array(
						'slug'   => (string) $slug,
						'origin' => is_array( $origins_set ) && ! empty( $origins_set ) ? (string) array_key_first( $origins_set ) : '',
					);
				}
			}
			if ( ! empty( $hits ) ) {
				return $this->remote_pairs_to_pipe_values( $hits );
			}
		}
		return array(
			'slug'   => '',
			'origin' => '',
		);
	}

	/**
	 * Is a folder-name guess more likely someone else's? Pure.
	 *
	 * The guess names slugs that are not installed while an installed plugin or theme carries
	 * one of them as its prefix ("mainwp" → "mainwp-child"). Any guessed slug that IS installed
	 * settles it: the folder is that one's, and the usual status rules apply.
	 *
	 * @param string[] $guessed   Slugs the folder name matched.
	 * @param string[] $installed Installed plugin and theme slugs.
	 * @return bool
	 */
	public static function fs_name_guess_is_ambiguous( array $guessed, array $installed ) {
		$installed = array_map( 'strtolower', array_filter( array_map( 'strval', $installed ) ) );
		$ambiguous = false;
		foreach ( array_filter( array_map( 'trim', $guessed ) ) as $slug ) {
			$slug = strtolower( $slug );
			if ( in_array( $slug, $installed, true ) ) {
				return false;
			}
			foreach ( $installed as $other ) {
				if ( 0 === strpos( $other, $slug . '-' ) ) {
					$ambiguous = true;
				}
			}
		}
		return $ambiguous;
	}

	/**
	 * Slugs of every installed plugin and theme, active or not.
	 *
	 * @return string[]
	 */
	protected function installed_extension_slugs() {
		return array_values(
			array_unique(
				array_merge(
					(array) $this->get_all_active_plugin_slugs(),
					(array) $this->get_all_inactive_plugin_slugs(),
					(array) $this->get_all_active_theme_slugs(),
					(array) $this->get_all_inactive_theme_slugs()
				)
			)
		);
	}

	/**
	 * Pairs of the deepest shared-dataset folder that contains $rel, or null.
	 *
	 * A folder contains a path when the path IS the folder or goes on with a '/'. This was a
	 * bare string prefix, so the dataset's `uploads/wc` owned `uploads/wc-logs`, and whichever
	 * folder came first in the file won over a deeper, more specific one.
	 *
	 * @param string $rel     Normalized wp-content relative path.
	 * @param bool   $curated Set to whether the folder that answered is a reviewed data folder.
	 * @return array|null
	 */
	protected function remote_fs_dir_pairs( $rel, &$curated = false ) {
		$best    = null;
		$long    = 0;
		$curated = false;
		foreach ( (array) ( $this->remote_fs_map['dirs'] ?? array() ) as $dir => $info ) {
			$dir = rtrim( (string) $dir, '/' );
			$len = strlen( $dir );
			if ( '' === $dir || $len <= $long ) {
				continue;
			}
			if ( $rel === $dir || 0 === strpos( $rel, $dir . '/' ) ) {
				$best    = (array) ( $info['pairs'] ?? array() );
				$long    = $len;
				$curated = ! empty( $info['curated'] );
			}
		}
		return $best;
	}

	/**
	 * Apply remote DB mapping to local orphans (adds slug/origin).
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	protected function apply_remote_db_mapping( array $rows ) {
		$this->load_remote_maps();
		if ( empty( $this->remote_db_map ) && empty( $this->remote_db_value_map ) && empty( $this->remote_files_value_map ) ) {
			return $rows;
		}
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';

		foreach ( $rows as &$row ) {
			$slugs = trim( (string) ( $row['project_slugs'] ?? '' ) );
			$type  = strtolower( trim( (string) ( $row['type'] ?? '' ) ) );
			$val   = (string) ( $row['value'] ?? '' );
			if ( '' !== $slugs || '' === $type || '' === $val ) {
				continue;
			}
			$val_key = strtolower( $val );
			$key     = $type . '::' . $val_key;
			if ( isset( $this->remote_db_map[ $key ] ) ) {
				$pairs                  = (array) ( $this->remote_db_map[ $key ]['pairs'] ?? array() );
				$projects               = $this->remote_pairs_to_pipe_values( $pairs );
				$row['project_slugs']   = $projects['slug'];
				$row['project_origins'] = $projects['origin'];
				continue;
			}

			if ( 'table' === $type && 0 !== strpos( $val_key, strtolower( $prefix ) ) ) {
				$prefixed = strtolower( $prefix . $val );
				$key      = $type . '::' . $prefixed;
				if ( isset( $this->remote_db_map[ $key ] ) ) {
					$pairs                  = (array) ( $this->remote_db_map[ $key ]['pairs'] ?? array() );
					$projects               = $this->remote_pairs_to_pipe_values( $pairs );
					$row['project_slugs']   = $projects['slug'];
					$row['project_origins'] = $projects['origin'];
					continue;
				}
			}

			if ( isset( $this->remote_db_value_map[ $val_key ] ) ) {
				$pairs = (array) ( $this->remote_db_value_map[ $val_key ]['pairs'] ?? array() );
				if ( ! empty( $pairs ) ) {
					$projects               = $this->remote_pairs_to_pipe_values( $pairs );
					$row['project_slugs']   = $projects['slug'];
					$row['project_origins'] = $projects['origin'];
				}
			}

			if ( '' === (string) ( $row['project_slugs'] ?? '' ) && ! empty( $this->remote_files_value_map ) ) {
				$type_map = array(
					'post_type'     => array( 'custom_type', 'post_type' ),
					'taxonomy'      => array( 'taxonomy' ),
					'role'          => array( 'role' ),
					'option'        => array( 'option' ),
					'update_option' => array( 'option' ),
					'delete_option' => array( 'option' ),
					'table'         => array( 'table' ),
				);
				if ( isset( $type_map[ $type ] ) ) {
					foreach ( $type_map[ $type ] as $remote_type ) {
						$key = $remote_type . '::' . $val_key;
						if ( isset( $this->remote_files_value_map[ $key ] ) ) {
							$pairs                  = (array) ( $this->remote_files_value_map[ $key ]['pairs'] ?? array() );
							$projects               = $this->remote_pairs_to_pipe_values( $pairs );
							$row['project_slugs']   = $projects['slug'];
							$row['project_origins'] = $projects['origin'];
							break;
						}
					}
				}
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Apply remote FS mapping to local orphans (adds slug/origin).
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	protected function apply_remote_fs_mapping( array $rows ) {
		$this->load_remote_maps();
		if ( empty( $this->remote_fs_map['files'] ) && empty( $this->remote_fs_map['dirs'] ) && empty( $this->remote_slug_index ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			$slugs = trim( (string) ( $row['project_slugs'] ?? '' ) );
			$rel   = $this->normalize_remote_rel_path( (string) ( $row['relative_path'] ?? '' ) );
			if ( '' !== $slugs || '' === $rel ) {
				continue;
			}
			$curated = false;
			$pairs   = isset( $this->remote_fs_map['files'][ $rel ] )
				? (array) ( $this->remote_fs_map['files'][ $rel ]['pairs'] ?? array() )
				: $this->remote_fs_dir_pairs( $rel, $curated );
			if ( empty( $pairs ) ) {
				// Only the folder's name matches a known slug (resolve_remote_fs_extension()).
				// When that plugin is not here but one whose slug starts the same way is, the
				// folder is far more likely to be the installed one's: uploads/mainwp is written
				// by MainWP Child, and the name pointed it at MainWP Dashboard, absent, which made
				// it an orphan offered for deletion (emiliocalvo.es, 09/11/2026). A guess, then,
				// not a verdict: weak, so it waits in Pending.
				$guess = $this->resolve_remote_fs_extension( $rel );
				if ( '' !== (string) ( $guess['slug'] ?? '' ) && self::fs_name_guess_is_ambiguous( explode( '|', (string) $guess['slug'] ), $this->installed_extension_slugs() ) ) {
					$row['project_slugs']   = (string) $guess['slug'];
					$row['project_origins'] = (string) $guess['origin'];
					$row['confianza']       = 'weak';
				}
				continue;
			}
			$projects               = $this->remote_pairs_to_pipe_values( $pairs );
			$row['project_slugs']   = $projects['slug'];
			$row['project_origins'] = $projects['origin'];
			// The shared dataset is a crowd claim: by itself it never makes a file deletable
			// (get_effective_row_status() reads this). The reviewed list of data folders is not.
			$row['confianza'] = $curated ? 'medium' : 'weak';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Apply runtime map attribution to rows that have no project_slugs yet.
	 * Called after read_orphans_db() so the display always reflects the latest
	 * runtime map data, even before a full re-analysis is run.
	 *
	 * @param array $rows Rows from read_orphans_db().
	 * @return array Same rows with project_slugs/project_origins/confianza filled in where possible.
	 */
	protected function overlay_runtime_attributions( array $rows ) {
		if ( ! class_exists( 'WPOPT_Runtime_Resolver' ) ) {
			return $rows;
		}
		$resolver = new WPOPT_Runtime_Resolver();

		// Maps output-CSV 'type' values to runtime-map 'tipo' bucket names.
		$type_to_tipo = array(
			// Direct matches (impact cleaner writes these exact strings).
			'post_meta'          => 'post_meta',
			'user_meta'          => 'user_meta',
			'term_meta'          => 'term_meta',
			'post_type'          => 'post_type',
			'taxonomy'           => 'taxonomy',
			'option'             => 'option',
			'shortcode'          => 'shortcode',
			'role'               => 'role',
			'widget'             => 'widget',
			'cron'               => 'cron',
			'rest_namespace'     => 'rest_namespace',
			// Legacy / alternative type strings.
			'register_setting'   => 'option',
			'update_option'      => 'option',
			'register_sidebar'   => 'widget',
			'register_widget'    => 'widget',
			'cron_schedule'      => 'cron',
			'rest_route'         => 'rest_namespace',
			'post_meta_add'      => 'post_meta',
			'post_meta_update'   => 'post_meta',
			'register_post_meta' => 'post_meta',
			'user_meta_add'      => 'user_meta',
			'user_meta_update'   => 'user_meta',
			'meta_add'           => 'post_meta',
			'meta_update'        => 'post_meta',
			'register_meta'      => 'post_meta',
			// Transient types — treated as options for attribution purposes.
			'transient'          => 'option',
			'transient_expired'  => 'option',
			'autoload'           => 'option',
		);

		// Build the installed-extension index ONCE. It maps every reliable alias of an
		// installed plugin/theme — its folder slug AND its text domain — to the canonical
		// {slug, name, origin}. The dataset/scanner often key a value by the wp.org/text-
		// domain slug, which on this site may differ from the on-disk folder name (zip
		// renamed, private build). Matching on the text domain too is what lets an item
		// owned by an installed-but-differently-named plugin resolve correctly instead of
		// being shown as orphan/inactive under a bare slug. $active_slugs_index holds the
		// aliases that are currently ACTIVE.
		$indexes               = $this->build_installed_index();
		$active_slugs_index    = $indexes['active'];    // alias => true (active only).
		$installed_slugs_index = $indexes['installed'];  // alias => array{slug,name,origin}.

		foreach ( $rows as &$row ) {
			$existing_slugs = trim( (string) ( $row['project_slugs'] ?? '' ) );
			$type           = strtolower( trim( (string) ( $row['type'] ?? '' ) ) );
			$tipo           = $type_to_tipo[ $type ] ?? null;
			$value          = strtolower( trim( (string) ( $row['value'] ?? '' ) ) );

			// A value outside the vocabulary is no level: an analysis stored before
			// WPOPT_Runtime_Resolver::row_confidence() may carry a plugin version («10.2.1») here.
			// It is asked again of the map when the map names the same owner, else left unknown.
			if ( '' !== (string) ( $row['confianza'] ?? '' ) && ! wpoptimizing_confidence_exists( strtolower( trim( (string) $row['confianza'] ) ) ) ) {
				$row['confianza'] = '';
				if ( null !== $tipo && '' !== $value && '' !== $existing_slugs ) {
					$del_mapa = strtolower( $resolver->get_slug_for_key( $tipo, $value ) );
					if ( '' !== $del_mapa && in_array( $del_mapa, array_map( 'strtolower', array_map( 'trim', explode( '|', $existing_slugs ) ) ), true ) ) {
						$row['confianza'] = $resolver->get_confianza_for_key( $tipo, $value );
					}
				}
			}

			/*
			 * LOS CUATRO ESCUDOS, en una sola llamada y en el mismo orden que usa el escáner:
			 * lo nuestro, el núcleo, una librería empotrada que sigue presente y los metadatos
			 * de plataforma. Estaban escritos aquí, en el momento de pintar, y el escáner
			 * —que es quien escribe el CSV— sólo tenía dos de los cuatro; ese CSV lo sirve
			 * además `GET /results` sin pasar por aquí. resolvers/attribution-shields.php lo
			 * cuenta entero.
			 *
			 * Y VAN ANTES QUE LAS REGLAS MANUALES. Una regla «contiene» es amplia por diseño, y
			 * la regla `_wp_` atribuía `_wp_attached_file` a un plugin y lo dejaba borrable.
			 * Corregir una atribución es lo que hace una regla; convertir el núcleo o lo nuestro
			 * en datos de otro, no.
			 */
			if ( function_exists( 'wpoptimizing_attribution_shield' ) ) {
				$escudo = wpoptimizing_attribution_shield(
					(string) $tipo,
					$value,
					array(
						'active'    => $active_slugs_index,
						'installed' => $installed_slugs_index,
					)
				);
				if ( null !== $escudo ) {
					$row['project_slugs']   = $escudo['slug'];
					$row['project_origins'] = $escudo['origin'];
					$row['confianza']       = $escudo['confianza'];
					$row['project_status']  = $escudo['status'];
					continue;
				}
			}

			// TOP AUTHORITY below the shields: an explicit human rule (manual attribution) wins
			// over every automatic attribution, including a wrong dataset/scanner one —
			// correcting a bad attribution is the whole point of letting the user assert
			// ownership. Works on Pending, Orphans, Inactive and Active alike.
			if ( function_exists( 'wpoptimizing_manual_attribution_match_all' ) ) {
				$mans = wpoptimizing_manual_attribution_match_all( (string) ( $row['value'] ?? '' ), (string) ( $row['relative_path'] ?? '' ) );
				if ( ! empty( $mans ) ) {
					$slugs   = array();
					$origins = array();
					foreach ( $mans as $man ) {
						$slugs[]   = (string) $man['slug'];
						$origins[] = (string) $man['origin'];
					}
					$row['project_slugs']   = implode( '|', $slugs );
					$row['project_origins'] = implode( '|', $origins );
					$row['confianza']       = 'manual';
					$cls                    = $this->classify_installed_status( $slugs, $active_slugs_index, $installed_slugs_index );
					$row['project_status']  = $cls['status'];
					continue;
				}
			}

			// An owner given only because it replaces $wpdb is no owner: drop it and let the row
			// be attributed again below (wpoptimizing_is_wpdb_replacer_artifact()).
			if ( '' !== $existing_slugs && null !== $tipo && function_exists( 'wpoptimizing_is_wpdb_replacer_artifact' ) ) {
				$kept = array();
				foreach ( array_filter( array_map( 'trim', explode( '|', $existing_slugs ) ) ) as $s ) {
					if ( ! wpoptimizing_is_wpdb_replacer_artifact( $s, $tipo, $value ) ) {
						$kept[] = $s;
					}
				}
				if ( implode( '|', $kept ) !== $existing_slugs ) {
					$existing_slugs         = implode( '|', $kept );
					$row['project_slugs']   = $existing_slugs;
					$row['project_origins'] = empty( $kept ) ? '' : (string) ( $row['project_origins'] ?? '' );
					$row['project_status']  = '';
					$row['confianza']       = '';
				}
			}

			if ( '' !== $existing_slugs ) {
				// Re-evaluate project_status — scan CSV may have stale 'orphan' from a prior run.
				$is_active   = false;
				$is_inactive = false;
				foreach ( array_filter( array_map( 'trim', explode( '|', $existing_slugs ) ) ) as $s ) {
					$s_lower = strtolower( $s );
					if ( isset( $active_slugs_index[ $s_lower ] ) ) {
						$is_active = true;
						break;
					}
					if ( isset( $installed_slugs_index[ $s_lower ] ) ) {
						$is_inactive = true;
					}
				}
				if ( $is_active ) {
					$row['project_status'] = 'active';
					$this->ensure_attributed_row_confidence( $row, $resolver, $tipo, $value );
					$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue; // Confirmed active — attribution is fine.
				}
				if ( $is_inactive ) {
					$row['project_status'] = 'inactive';
					$this->ensure_attributed_row_confidence( $row, $resolver, $tipo, $value );
					$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue; // Confirmed installed-but-inactive — attribution is fine.
				}
				// Slug(s) not found in any installed extension. The stored slug may be
				// stale or wrong (e.g. remote dataset renamed the plugin). Fall through
				// to Source 1/2 to attempt re-attribution with the correct slug.
				if ( null === $tipo || '' === $value ) {
					$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue; // Type not mappable — nothing more we can do.
				}
			} else {
				// Unattributed row — need tipo and value to proceed. A kind the runtime map does not
				// know (a table, say) can still take the assistant's owner: it is asked by name.
				if ( null === $tipo || '' === $value ) {
					$this->apply_assistant_owner( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue;
				}
			}

			// Source 1: runtime map (live captures from the mu-plugin — most certain).
			// Only trust it if the returned slug is actually installed; otherwise
			// the runtime map may have stale data (e.g. a renamed plugin).
			$slug = $resolver->get_slug_for_key( $tipo, $value );
			if ( '' !== $slug ) {
				$slug_lower = strtolower( $slug );
				/*
				 * EL ORIGEN ES EL DE VERDAD, NO 'runtime-map'. Aqui se escribia esa cadena, que
				 * no es un origen: los unicos que la columna «Extension» sabe pintar son
				 * 'plugin', 'theme', 'core' y 'library', asi que la fila salia con un guion.
				 * Justo la capa mas certera —la captura en vivo del mu-plugin— era la unica que
				 * no producia etiqueta, mientras que el conjunto compartido, que es mas debil,
				 * si la producia. Nadie leia 'runtime-map' en ninguna parte.
				 *
				 * El dato correcto ya estaba a mano: el indice de instalados, con el que se
				 * acaba de comprobar la pertenencia, guarda {slug, name, origin} canonicos. Se
				 * usan los dos, asi que ademas se muestra la carpeta real y no el alias (text
				 * domain) con el que el mapa pudo registrar la clave.
				 */
				if ( isset( $active_slugs_index[ $slug_lower ] ) ) {
					$canon                  = $installed_slugs_index[ $slug_lower ] ?? array();
					$row['project_slugs']   = '' !== (string) ( $canon['slug'] ?? '' ) ? (string) $canon['slug'] : $slug;
					$row['project_origins'] = '' !== (string) ( $canon['origin'] ?? '' ) ? (string) $canon['origin'] : 'plugin';
					$confianza              = $resolver->get_confianza_for_key( $tipo, $value );
					if ( '' !== $confianza ) {
						$row['confianza'] = $confianza;
					}
					$row['project_status'] = 'active';
					$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue;
				}
				if ( isset( $installed_slugs_index[ $slug_lower ] ) ) {
					$canon                  = $installed_slugs_index[ $slug_lower ];
					$row['project_slugs']   = '' !== (string) ( $canon['slug'] ?? '' ) ? (string) $canon['slug'] : $slug;
					$row['project_origins'] = '' !== (string) ( $canon['origin'] ?? '' ) ? (string) $canon['origin'] : 'plugin';
					$confianza              = $resolver->get_confianza_for_key( $tipo, $value );
					if ( '' !== $confianza ) {
						$row['confianza'] = $confianza;
					}
					$row['project_status'] = 'inactive';
					$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue;
				}
				// Slug not found on disk — runtime map data may be stale.
				// Fall through to Source 2 to find the correct current slug.
			} elseif ( method_exists( $resolver, 'get_claimants' ) ) {
				// A key several extensions claim names none of them, unless exactly one of them is
				// installed here: then it is that one (WPOPT_Runtime_Resolver::get_claimants()).
				$aqui = array();
				foreach ( $resolver->get_claimants( $tipo, $value ) as $c ) {
					$c = strtolower( (string) $c );
					if ( isset( $installed_slugs_index[ $c ] ) ) {
						$aqui[ (string) $installed_slugs_index[ $c ]['slug'] ] = $c;
					}
				}
				if ( 1 === count( $aqui ) ) {
					$cls                    = $this->classify_installed_status( reset( $aqui ), $active_slugs_index, $installed_slugs_index );
					$row['project_slugs']   = $cls['slug'];
					$row['project_origins'] = '' !== $cls['origin'] ? $cls['origin'] : 'plugin';
					$row['project_status']  = $cls['status'];
					$row['confianza']       = 'medium';
					$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
					continue;
				}
			}

			// Source 2: remote dataset cached locally from wpoptimizing.com.
			// load_remote_maps() is internally cached per-instance so the
			// file is read and decrypted only once regardless of row count.
			$ext = $this->resolve_remote_db_extension( $type, $value );
			if ( '' !== ( $ext['slug'] ?? '' ) ) {
				$row['project_slugs']   = $ext['slug'];
				$row['project_origins'] = $ext['origin'] ?? 'plugin';
				// Crowd-sourced match: weak by default, promoted to medium when
				// enough independent sites confirm it (curated confidence column).
				$row['confianza'] = $this->remote_confidence_tier( $this->resolve_remote_db_confidence( $type, $value ) );
				// Status from what is ACTUALLY installed (active → inactive → orphan), matching
				// on folder slug OR text domain. A dataset slug that is NOT installed here is an
				// ORPHAN, never "inactive" (the old bug that put wp-reset in Inactive). When an
				// installed alias matches, rewrite to the canonical installed slug+origin so the
				// Extension column shows the real plugin instead of the dataset's bare slug.
				$cls                   = $this->classify_installed_status( $ext['slug'], $active_slugs_index, $installed_slugs_index );
				$row['project_status'] = $cls['status'];
				if ( 'orphan' !== $cls['status'] && '' !== $cls['slug'] ) {
					$row['project_slugs']   = $cls['slug'];
					$row['project_origins'] = '' !== $cls['origin'] ? $cls['origin'] : ( $ext['origin'] ?? 'plugin' );
				}
			}

			// Source 3: what the attribution assistant gave with certainty after the last analysis
			// (resolvers/attr-suggest.php). Only for what nothing above attributed, and `strong`:
			// an exact single owner in the whole corpus, not something seen on this site.
			// And it corroborates the owner a row already names: corroborate_with_assistant().
			if ( '' !== trim( (string) ( $row['project_slugs'] ?? '' ) ) ) {
				$this->corroborate_with_assistant( $row, $value, $active_slugs_index, $installed_slugs_index );
			} else {
				$this->apply_assistant_owner( $row, $value, $active_slugs_index, $installed_slugs_index );
			}

			$this->fill_missing_class( $row, $resolver, $tipo, $value );
		}
		unset( $row );
		$rows = $this->attribute_by_live_registry( $rows, $type_to_tipo, $active_slugs_index, $installed_slugs_index );
		$rows = $this->attribute_language_variants( $rows, $type_to_tipo, $resolver, $active_slugs_index, $installed_slugs_index );
		return self::share_owner_class( $this->attribute_by_learned_namespace( $rows, $active_slugs_index, $installed_slugs_index ) );
	}

	/**
	 * Rows of one owner with no class take the class its other rows on this site agree on.
	 *
	 * POR DUEÑO, Y SÓLO SI NO HAY DUDA. El asistente responde clave a clave, y no a todas: en el
	 * sitio de desarrollo (04/11/2026) `lifterlms` tenía la clase en sus opciones y la columna
	 * seguía pintando el slug pelado en `course` o `course_cat`, por las que nadie había
	 * respondido. Si las filas con clase de ese dueño dicen todas lo mismo, las demás lo toman;
	 * si dicen dos cosas —un tema y un plugin con el mismo slug—, ninguna lo toma. Sólo cambia
	 * la etiqueta: ni el dueño, ni el estado, ni la confianza.
	 *
	 * @param array $rows Rows.
	 * @return array
	 */
	public static function share_owner_class( array $rows ) {
		$clases = array();
		foreach ( $rows as $row ) {
			$slug   = strtolower( trim( (string) ( $row['project_slugs'] ?? '' ) ) );
			$origen = strtolower( trim( (string) ( $row['project_origins'] ?? '' ) ) );
			if ( '' !== $slug && false === strpos( $slug, '|' ) && in_array( $origen, array( 'plugin', 'theme' ), true ) ) {
				$clases[ $slug ][ $origen ] = true;
			}
		}
		foreach ( $rows as &$row ) {
			$slug   = strtolower( trim( (string) ( $row['project_slugs'] ?? '' ) ) );
			$origen = strtolower( trim( (string) ( $row['project_origins'] ?? '' ) ) );
			if ( ( '' === $origen || 'unknown' === $origen ) && isset( $clases[ $slug ] ) && 1 === count( $clases[ $slug ] ) ) {
				$row['project_origins'] = (string) key( $clases[ $slug ] );
			}
		}
		unset( $row );
		return $rows;
	}

	/**
	 * A widget option or a shortcode nothing attributed takes the owner of the code that
	 * registers it now, read from WordPress's own registries (resolvers/live-registry.php).
	 * `strong`: the file is a fact, but what is registered is not always what wrote the row.
	 *
	 * @param array                $rows            Rows.
	 * @param array<string,string> $type_to_tipo    Row type => map bucket.
	 * @param array                $active_index    Active aliases.
	 * @param array                $installed_index Installed aliases.
	 * @return array
	 */
	protected function attribute_by_live_registry( array $rows, array $type_to_tipo, array $active_index, array $installed_index ) {
		if ( ! function_exists( 'wpoptimizing_live_registry_owner' ) ) {
			return $rows;
		}
		foreach ( $rows as &$row ) {
			if ( '' !== trim( (string) ( $row['project_slugs'] ?? '' ) ) ) {
				continue;
			}
			$type  = strtolower( trim( (string) ( $row['type'] ?? '' ) ) );
			$dueno = wpoptimizing_live_registry_owner( (string) ( $type_to_tipo[ $type ] ?? $type ), strtolower( trim( (string) ( $row['value'] ?? '' ) ) ) );
			if ( null === $dueno ) {
				continue;
			}
			$cls                    = $this->classify_installed_status( $dueno['slug'], $active_index, $installed_index );
			$row['project_slugs']   = '' !== $cls['slug'] ? $cls['slug'] : $dueno['slug'];
			$row['project_origins'] = '' !== $cls['origin'] ? $cls['origin'] : $dueno['origin'];
			$row['project_status']  = 'orphan' === $cls['status'] ? 'active' : $cls['status'];
			$row['confianza']       = 'strong';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * A per-language copy of a key takes the owner of the key it copies.
	 *
	 * `fusion_options_ar` is Avada's because `fusion_options_es` and `fusion_options` are. The
	 * strongest attribution in the family wins and also corrects a weak one inside it: on
	 * wpoptimizing.com the shared base gave `fusion_options` itself to WPML (weak, the plugin
	 * that was running when it was seen) while its copies were Avada's with certainty.
	 *
	 * WPML keeps its own per-language copies of two core options, verified in its code:
	 * "{$taxonomy}_children_{$lang}" (WPML_Term_Filters, also with `all`) and
	 * "sticky_posts_{$lang}" (WPML_Sticky_Posts_Sync). Those are WPML's, not core's.
	 *
	 * Only this site's languages count as a suffix, see resolvers/language-variants.php. An
	 * inferred owner is at most `strong`.
	 *
	 * @param array                $rows            Rows.
	 * @param array<string,string> $type_to_tipo    Row type => map bucket.
	 * @param WPOPT_Runtime_Resolver $resolver        Runtime map.
	 * @param array                $active_index    Active aliases.
	 * @param array                $installed_index Installed aliases.
	 * @param string[]|null        $langs           Language suffixes; null = this site's.
	 * @return array
	 */
	protected function attribute_language_variants( array $rows, array $type_to_tipo, $resolver, array $active_index, array $installed_index, $langs = null ) {
		if ( ! function_exists( 'wpoptimizing_language_variant_base' ) ) {
			return $rows;
		}
		$langs = is_array( $langs ) ? $langs : wpoptimizing_site_language_suffixes();
		if ( empty( $langs ) ) {
			return $rows;
		}
		$bucket = static function ( array $r ) use ( $type_to_tipo ) {
			$type = strtolower( trim( (string) ( $r['type'] ?? '' ) ) );
			return (string) ( $type_to_tipo[ $type ] ?? $type );
		};
		$rank = static function ( array $r ) {
			return '' === trim( (string) ( $r['project_slugs'] ?? '' ) ) ? -1 : wpoptimizing_confidence_rank( (string) ( $r['confianza'] ?? '' ) );
		};

		// Families: every row of a key and of its copies, under the key they copy.
		$familias = array();
		foreach ( $rows as $i => $r ) {
			$value = strtolower( trim( (string) ( $r['value'] ?? '' ) ) );
			$copia = wpoptimizing_language_variant_base( $value, $langs );
			$base  = null !== $copia ? $copia['base'] : $value;
			$familias[ $bucket( $r ) . '|' . $base ][] = array(
				'i'    => $i,
				'lang' => null !== $copia ? $copia['lang'] : null,
			);
		}

		$tope  = wpoptimizing_confidence_rank( 'strong' );
		$medio = wpoptimizing_confidence_rank( 'medium' );
		foreach ( $familias as $clave => $miembros ) {
			$copias = array_filter(
				$miembros,
				static function ( $m ) {
					return null !== $m['lang'];
				}
			);
			if ( empty( $copias ) ) {
				continue;
			}
			list( $tipo, $base ) = explode( '|', $clave, 2 );

			if ( 'option' === $tipo && ( str_ends_with( $base, '_children' ) || 'sticky_posts' === $base ) ) {
				$cls = $this->classify_installed_status( 'sitepress-multilingual-cms', $active_index, $installed_index );
				foreach ( $copias as $m ) {
					if ( '' === $m['lang'] ) {
						continue;
					}
					$row                    = &$rows[ $m['i'] ];
					$row['project_slugs']   = '' !== $cls['slug'] ? $cls['slug'] : 'sitepress-multilingual-cms';
					$row['project_origins'] = 'plugin';
					$row['project_status']  = $cls['status'];
					$row['confianza']       = 'strong';
					unset( $row );
				}
				continue;
			}

			// The strongest attribution in the family; the runtime map for the key copied when
			// nothing in the family has an owner (the key itself may not be stored).
			$mejor = null;
			foreach ( $miembros as $m ) {
				if ( $rank( $rows[ $m['i'] ] ) > ( null === $mejor ? -1 : $rank( $mejor ) ) ) {
					$mejor = $rows[ $m['i'] ];
				}
			}
			if ( null === $mejor && null !== $resolver ) {
				$slug = strtolower( (string) $resolver->get_slug_for_key( $tipo, $base ) );
				$cls  = '' !== $slug ? $this->classify_installed_status( $slug, $active_index, $installed_index ) : array( 'status' => 'orphan' );
				if ( 'orphan' !== $cls['status'] ) {
					$mejor = array(
						'project_slugs'   => $cls['slug'],
						'project_origins' => '' !== $cls['origin'] ? $cls['origin'] : 'plugin',
						'project_status'  => $cls['status'],
						'confianza'       => (string) $resolver->get_confianza_for_key( $tipo, $base ),
					);
				}
			}
			if ( null === $mejor ) {
				continue;
			}
			$conf_mejor = (string) ( $mejor['confianza'] ?? '' );
			$heredada   = ( '' === $conf_mejor || wpoptimizing_confidence_rank( $conf_mejor ) > $tope ) ? 'strong' : $conf_mejor;
			foreach ( $miembros as $m ) {
				$row = &$rows[ $m['i'] ];
				$sin = '' === trim( (string) ( $row['project_slugs'] ?? '' ) );
				// A member with an owner keeps it, unless it is a weak guess and the family knows better.
				$corregir = ! $sin && $rank( $row ) < $medio && wpoptimizing_confidence_rank( $conf_mejor ) > $tope
					&& strtolower( (string) $row['project_slugs'] ) !== strtolower( (string) $mejor['project_slugs'] );
				if ( $sin || $corregir ) {
					$row['project_slugs']   = (string) $mejor['project_slugs'];
					$row['project_origins'] = (string) ( $mejor['project_origins'] ?? '' );
					$row['project_status']  = (string) ( $mejor['project_status'] ?? '' );
					$row['confianza']       = $heredada;
				}
				unset( $row );
			}
		}
		return $rows;
	}

	/**
	 * A row nothing attributed takes the owner the assistant gave with certainty, as `strong`.
	 *
	 * @param array  $row             Row, by reference.
	 * @param string $value           Key, lowercase.
	 * @param array  $active_index    Active aliases.
	 * @param array  $installed_index Installed aliases.
	 * @return bool Whether the row got an owner.
	 */
	protected function apply_assistant_owner( array &$row, $value, array $active_index, array $installed_index ) {
		if ( '' === (string) $value || '' !== trim( (string) ( $row['project_slugs'] ?? '' ) ) || ! function_exists( 'wpoptimizing_attr_suggest_auto_match' ) ) {
			return false;
		}
		$auto = wpoptimizing_attr_suggest_auto_match( $value, (string) ( $row['type'] ?? '' ) );
		if ( null === $auto ) {
			return false;
		}
		$row['project_slugs']   = $auto['slug'];
		$row['project_origins'] = $auto['tipo'];
		$row['confianza']       = 'strong';
		$cls                    = $this->classify_installed_status( $auto['slug'], $active_index, $installed_index );
		$row['project_status']  = $cls['status'];
		if ( 'orphan' !== $cls['status'] && '' !== $cls['slug'] ) {
			$row['project_slugs']   = $cls['slug'];
			$row['project_origins'] = '' !== $cls['origin'] ? $cls['origin'] : $auto['tipo'];
		}
		return true;
	}

	/**
	 * Two sources that agree on an owner: the row goes up to `strong`.
	 *
	 * The scan named an owner with little confidence (estimated, doubtful, or nothing) and the
	 * attribution assistant gave that same owner with certainty from the whole corpus
	 * (resolvers/attr-suggest.php). The assistant may answer with the canonical slug while the
	 * row carries the installed folder, so both are compared through the installed index. When
	 * they disagree nothing changes: two sources naming different owners are no confirmation.
	 *
	 * @param array  $row             Row, by reference.
	 * @param string $value           Key, lowercase.
	 * @param array  $active_index    Active aliases.
	 * @param array  $installed_index Installed aliases.
	 * @return bool Whether the row was raised.
	 */
	protected function corroborate_with_assistant( array &$row, $value, array $active_index, array $installed_index ) {
		$slugs = trim( (string) ( $row['project_slugs'] ?? '' ) );
		if ( '' === $slugs || ! function_exists( 'wpoptimizing_attr_suggest_auto_match' ) ) {
			return false;
		}
		$auto = wpoptimizing_attr_suggest_auto_match( $value, (string) ( $row['type'] ?? '' ) );
		if ( null === $auto ) {
			return false;
		}
		/*
		 * SÓLO UN DUEÑO, Y DE LA MISMA CLASE, Y SIN CAMBIARLO. Corroborar es decir «dos fuentes
		 * dicen lo mismo»; con varios dueños en la fila, que el asistente coincida con UNO no
		 * confirma la fila, y reducirla a ése tiraba a los demás: `live-plugin|old-plugin` con
		 * `live-plugin` activo acababa como huérfana de `old-plugin`, `strong`, delante del botón
		 * de borrar (auditoría del 26/10/2026). Y un tema `acme` no es el plugin `acme`.
		 */
		$propios = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', explode( '|', $slugs ) ) ) ) );
		if ( 1 !== count( $propios ) ) {
			return false;
		}
		$origenes = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', explode( '|', (string) ( $row['project_origins'] ?? '' ) ) ) ) ) );
		$origen   = $origenes[0] ?? '';
		if ( count( $origenes ) > 1 || ( '' !== $origen && 'unknown' !== $origen && $origen !== $auto['tipo'] ) ) {
			return false;
		}
		$cls   = $this->classify_installed_status( $auto['slug'], $active_index, $installed_index );
		$suyos = array_filter( array( strtolower( $auto['slug'] ), strtolower( $cls['slug'] ) ) );
		if ( ! in_array( $propios[0], $suyos, true ) ) {
			return false;
		}
		/*
		 * LA CLASE SE TOMA AUNQUE LA FILA YA FUERA SEGURA. El mapa runtime dice de quién es una
		 * clave y a veces no de qué clase, y esas filas llegan ya `strong`: con la comprobación de
		 * confianza delante, nunca recibían la clase que el asistente sí sabe, y la columna
		 * pintaba el slug pelado («lifterlms», «slimjetpack») sin nombre ni «Plugin:»
		 * (sitio de desarrollo, 04/11/2026). Rellenar la clase no cambia dueño, estado ni confianza.
		 */
		if ( '' === $origen || 'unknown' === $origen ) {
			$row['project_origins'] = $auto['tipo'];
		}
		if ( wpoptimizing_confidence_rank( (string) ( $row['confianza'] ?? '' ) ) >= wpoptimizing_confidence_rank( 'strong' ) ) {
			return false;
		}
		$row['confianza']      = 'strong';
		$row['project_status'] = $this->classify_installed_status( $propios[0], $active_index, $installed_index )['status'];
		return true;
	}

	/**
	 * Last pass for rows still without owner: the namespace this site's own attributed rows
	 * show for an installed extension (resolvers/namespace-learning.php). Estimated confidence
	 * only, so it moves a row out of Pending but never makes it deletable.
	 *
	 * @param array $rows            Rows after the other sources.
	 * @param array $active_index    Active aliases.
	 * @param array $installed_index Installed aliases => {slug,name,origin}.
	 * @return array
	 */
	protected function attribute_by_learned_namespace( array $rows, array $active_index, array $installed_index ) {
		if ( ! function_exists( 'wpoptimizing_namespace_learn' ) ) {
			return $rows;
		}
		global $wpdb;
		$tp    = isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		$pairs = array();
		$medio = wpoptimizing_confidence_rank( 'medium' );
		foreach ( $rows as $r ) {
			$slugs = (string) ( $r['project_slugs'] ?? '' );
			$st    = (string) ( $r['project_status'] ?? '' );
			$value = (string) ( $r['value'] ?? '' );
			/*
			 * Only what is known teaches. A weak owner is the plugin that was running when the key
			 * was seen, and a term-hierarchy cache ("{$taxonomy}_children…") carries the name of
			 * a taxonomy, not of whoever writes it (core or WPML). Both put WPML in the `fusion`
			 * family on wpoptimizing.com, five owners, and a family of five is taken for a generic
			 * word: fusion_options-transients and the fusion_form_* tables stayed ownerless
			 * (27/10/2026).
			 */
			if ( '' !== $slugs && in_array( $st, array( 'active', 'inactive' ), true )
				&& wpoptimizing_confidence_rank( (string) ( $r['confianza'] ?? '' ) ) >= $medio
				&& false === strpos( $value, '_children' ) ) {
				$pairs[] = array( $value, $slugs );
			}
		}
		$learned = wpoptimizing_namespace_learn( $pairs, $tp );
		$aliases = array_keys( $installed_index );
		foreach ( $rows as &$row ) {
			if ( '' !== trim( (string) ( $row['project_slugs'] ?? '' ) ) ) {
				continue;
			}
			$token = wpoptimizing_namespace_token( (string) ( $row['value'] ?? '' ), $tp );
			if ( '' === $token ) {
				continue;
			}
			$owners = $learned[ $token ] ?? wpoptimizing_namespace_slug_by_name( $token, $aliases );
			if ( '' === $owners ) {
				continue;
			}
			$cls = $this->classify_installed_status( $owners, $active_index, $installed_index );
			if ( 'orphan' === $cls['status'] ) {
				continue;
			}
			// A family keeps all its members as owners (WPML core|String Translation): the row
			// belongs to the family, and stays out of Orphans while any of them is installed.
			$canon = array();
			foreach ( explode( '|', $owners ) as $o ) {
				$o = strtolower( $o );
				if ( isset( $installed_index[ $o ] ) ) {
					$canon[] = (string) $installed_index[ $o ]['slug'];
				}
			}
			$canon                  = array_values( array_unique( $canon ) );
			$row['project_slugs']   = $canon ? implode( '|', $canon ) : $cls['slug'];
			$row['project_origins'] = '' !== $cls['origin'] ? $cls['origin'] : 'plugin';
			$row['project_status']  = $cls['status'];
			$row['confianza']       = 'heuristic';
		}
		unset( $row );
		return $rows;
	}

	/**
	 * Build (once per request) the index of installed plugins/themes used to decide a row's
	 * active/inactive/orphan status reliably.
	 *
	 * Every extension is registered under MORE than one alias — its on-disk folder slug AND
	 * its text domain — because the dataset/scanner frequently key a value by the wp.org/text-
	 * domain slug while this site's folder may be named differently (renamed zip, private
	 * build). Matching on either alias is what makes the classification trustworthy.
	 *
	 * @return array{active:array<string,true>,installed:array<string,array{slug:string,name:string,origin:string}>}
	 */
	protected function build_installed_index() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		// Igual que en el resto del plugin: se carga sólo si hace falta. Incondicional, este
		// require era además lo único que impedía probar este método — y es el que decide la
		// clase de cada extensión instalada, que no es un detalle para dejar sin cubrir.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_files = function_exists( 'wpoptimizing_active_plugin_files' )
			? wpoptimizing_active_plugin_files()
			: (array) get_option( 'active_plugins', array() );
		$network      = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$all          = function_exists( 'get_plugins' ) ? get_plugins() : array();

		$active    = array();
		$installed = array();
		$add       = static function ( $alias, $canonical, $name, $origin, $is_active ) use ( &$active, &$installed ) {
			$alias = strtolower( trim( (string) $alias ) );
			if ( '' === $alias ) {
				return;
			}
			// First writer wins for the canonical mapping (folder slug is registered before the
			// text domain), so an alias never points at the wrong extension.
			if ( ! isset( $installed[ $alias ] ) ) {
				$installed[ $alias ] = array(
					'slug'   => (string) $canonical,
					'name'   => (string) $name,
					'origin' => (string) $origin,
				);
			}
			if ( $is_active ) {
				$active[ $alias ] = true;
			}
		};

		foreach ( $all as $file => $data ) {
			$dir       = dirname( (string) $file );
			$slug      = strtolower( '.' === $dir ? basename( (string) $file, '.php' ) : $dir );
			$name      = ( isset( $data['Name'] ) && '' !== $data['Name'] ) ? (string) $data['Name'] : $slug;
			$is_active = in_array( $file, $active_files, true ) || in_array( $file, $network, true );
			$add( $slug, $slug, $name, 'plugin', $is_active );
			if ( isset( $data['TextDomain'] ) && '' !== $data['TextDomain'] ) {
				$add( $data['TextDomain'], $slug, $name, 'plugin', $is_active );
			}
		}

		// Must-use plugins: installed and always active, though never in active_plugins.
		if ( function_exists( 'wpoptimizing_mu_plugin_slugs' ) ) {
			foreach ( array_keys( wpoptimizing_mu_plugin_slugs() ) as $mu ) {
				$add( $mu, $mu, $mu, 'plugin', true );
			}
		}

		$active_style = strtolower( (string) get_option( 'stylesheet', '' ) );
		$active_tmpl  = strtolower( (string) get_option( 'template', '' ) );
		foreach ( wp_get_themes() as $tslug => $theme ) {
			$tslug_l   = strtolower( (string) $tslug );
			$name      = (string) $theme->get( 'Name' );
			$name      = '' !== $name ? $name : $tslug_l;
			$is_active = ( $tslug_l === $active_style || $tslug_l === $active_tmpl );
			$add( $tslug_l, $tslug_l, $name, 'theme', $is_active );
			$td = (string) $theme->get( 'TextDomain' );
			if ( '' !== $td ) {
				$add( $td, $tslug_l, $name, 'theme', $is_active );
			}
		}

		$cache = array(
			'active'    => $active,
			'installed' => $installed,
		);
		return $cache;
	}

	/**
	 * Decide the install status of an owner candidate set against the installed index.
	 *
	 * Priority is unambiguous and the SAME everywhere it is used: any active alias → active;
	 * else any installed-but-inactive alias → inactive; else (the candidates name a real
	 * extension that is simply not present) → orphan. Returns the canonical installed slug,
	 * name and origin of the matched extension so callers can display the real name.
	 *
	 * @param string|array $slugs          Pipe-string or array of candidate slugs/aliases.
	 * @param array        $active_index   alias => true (active only).
	 * @param array        $installed_index alias => array{slug,name,origin}.
	 * @return array{status:string,slug:string,name:string,origin:string}
	 */
	protected function classify_installed_status( $slugs, array $active_index, array $installed_index ) {
		$list          = is_array( $slugs ) ? $slugs : explode( '|', (string) $slugs );
		$installed_hit = null;
		foreach ( $list as $s ) {
			$sl = strtolower( trim( (string) $s ) );
			if ( '' === $sl ) {
				continue;
			}
			if ( isset( $active_index[ $sl ] ) ) {
				$canon = $installed_index[ $sl ] ?? array(
					'slug'   => $sl,
					'name'   => '',
					'origin' => 'plugin',
				);
				return array(
					'status' => 'active',
					'slug'   => (string) $canon['slug'],
					'name'   => (string) $canon['name'],
					'origin' => (string) $canon['origin'],
				);
			}
			if ( null === $installed_hit && isset( $installed_index[ $sl ] ) ) {
				$installed_hit = $installed_index[ $sl ];
			}
		}
		if ( null !== $installed_hit ) {
			return array(
				'status' => 'inactive',
				'slug'   => (string) $installed_hit['slug'],
				'name'   => (string) $installed_hit['name'],
				'origin' => (string) $installed_hit['origin'],
			);
		}
		return array(
			'status' => 'orphan',
			'slug'   => '',
			'name'   => '',
			'origin' => '',
		);
	}
}
