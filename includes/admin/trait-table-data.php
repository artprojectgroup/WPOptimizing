<?php
/**
 * Table data helpers: type/value formatting, project columns, and the DB/FS type & extension filters used by the orphan/inactive/active tables.
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Table_Data_Trait {

	/**
	 * Whether the "Extensions" column should be displayed (if any row has slugs).
	 *
	 * @param array $rows Normalized EN rows.
	 * @return bool
	 */
	protected function should_show_projects_column( array $rows ) {
		foreach ( $rows as $row ) {
			if ( ! empty( $row['project_slugs'] ) ) {
				return true;
			}
			$is_fs = isset( $row['relative_path'] );
			if ( $is_fs ) {
				$ext = $this->resolve_remote_fs_extension( (string) ( $row['relative_path'] ?? '' ) );
			} else {
				$ext = $this->resolve_remote_db_extension( (string) ( $row['type'] ?? '' ), (string) ( $row['value'] ?? '' ) );
			}
			if ( ! empty( $ext['slug'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Human-readable label for a DB item type.
	 *
	 * @param string $type Raw type string from the CSV.
	 * @return string Translated label.
	 */
	public function get_db_type_label( $type ) {
		$labels = array(
			'option'            => __( 'option', 'wpoptimizing' ),
			'transient'         => __( 'transient', 'wpoptimizing' ),
			'transient_expired' => __( 'transient (expired)', 'wpoptimizing' ),
			'post_type'         => __( 'post type', 'wpoptimizing' ),
			'post_type_content' => __( 'post type content', 'wpoptimizing' ),
			'taxonomy'          => __( 'taxonomy', 'wpoptimizing' ),
			'taxonomy_term'     => __( 'taxonomy terms', 'wpoptimizing' ),
			'role'              => __( 'role', 'wpoptimizing' ),
			'capability'        => __( 'capability', 'wpoptimizing' ),
			'table'             => __( 'table', 'wpoptimizing' ),
			'files'             => __( 'files', 'wpoptimizing' ),
			'post_meta'         => __( 'post meta', 'wpoptimizing' ),
			'user_meta'         => __( 'user meta', 'wpoptimizing' ),
			'term_meta'         => __( 'term meta', 'wpoptimizing' ),
			'shortcode'         => __( 'shortcode', 'wpoptimizing' ),
			'rest_namespace'    => __( 'REST namespace', 'wpoptimizing' ),
			'widget'            => __( 'widget', 'wpoptimizing' ),
			'plugin_page'       => __( 'plugin page', 'wpoptimizing' ),
			'cron'              => __( 'cron', 'wpoptimizing' ),
			'action_scheduler'  => __( 'Action Scheduler action', 'wpoptimizing' ),
			'i18n'              => __( 'i18n', 'wpoptimizing' ),
			'post'              => __( 'post', 'wpoptimizing' ),
			'revision'          => __( 'revision', 'wpoptimizing' ),
			'product_variation' => __( 'product variation', 'wpoptimizing' ),
			'comment'           => __( 'comment', 'wpoptimizing' ),
			'comment_meta'      => __( 'comment meta', 'wpoptimizing' ),
			'term_relationship' => __( 'term relationship', 'wpoptimizing' ),
			'term_taxonomy'     => __( 'term taxonomy row', 'wpoptimizing' ),
			'wc_session'        => __( 'WooCommerce session', 'wpoptimizing' ),
			'autoload'          => __( 'autoload flag', 'wpoptimizing' ),
			'file'              => __( 'files', 'wpoptimizing' ),
		);
		return $labels[ strtolower( (string) $type ) ] ?? $type;
	}

	/**
	 * Format a DB row value as safe HTML for display.
	 *
	 * @param array $row Normalized EN row.
	 * @return string Safe HTML.
	 */
	public function format_db_value( array $row ) {
		$type  = strtolower( (string) ( $row['type'] ?? '' ) );
		$value = (string) ( $row['value'] ?? '' );

		if ( 'table' === $type && '' !== $value ) {
			$full = $this->get_full_table_name_from_value( $value );
			if ( '' !== $full ) {
				return '<code>' . esc_html( $full ) . '</code>';
			}
		}

		// plugin_page stores "post_id:title".
		if ( 'plugin_page' === $type && str_contains( $value, ':' ) ) {
			$parts   = explode( ':', $value, 2 );
			$post_id = (int) $parts[0];
			$title   = $parts[1] ?? '';
			if ( $post_id > 0 ) {
				$edit_url = get_edit_post_link( $post_id );
				if ( $edit_url ) {
					return '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $title ?: '#' . $post_id ) . '</a>';
				}
				return esc_html( $title ?: '#' . $post_id );
			}
		}

		// post_type_content and taxonomy_term show count in size column; display type name as value.
		if ( in_array( $type, array( 'post_type_content', 'taxonomy_term' ), true ) ) {
			return '<code>' . esc_html( $value ) . '</code>';
		}

		return '<code>' . esc_html( $value ) . '</code>';
	}

	/**
	 * Resolve a table value to its full table name (with prefix if present).
	 *
	 * @param string $value Table name without prefix.
	 * @return string
	 */
	protected function get_full_table_name_from_value( $value ) {
		static $map      = null;
		static $full_set = null;
		global $wpdb;

		if ( null === $map || null === $full_set ) {
			$map      = array();
			$full_set = array();
			$tables   = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( is_array( $tables ) ) {
				foreach ( $tables as $table ) {
					$table = (string) $table;
					if ( '' === $table ) {
						continue;
					}
					$full_set[ $table ] = true;
					if ( '' !== $wpdb->prefix && 0 === strpos( $table, $wpdb->prefix ) ) {
						$no_prefix = substr( $table, strlen( $wpdb->prefix ) );
						if ( '' !== $no_prefix ) {
							$map[ $no_prefix ] = $table;
						}
					}
				}
			}
		}

		if ( isset( $map[ $value ] ) ) {
			return $map[ $value ];
		}
		if ( isset( $full_set[ $value ] ) ) {
			return $value;
		}
		return $value;
	}

	/**
	 * Format related projects into “Plugin: Name / Theme: Name”.
	 *
	 * @param array $row Normalized EN row.
	 * @return string Safe HTML.
	 */
	/**
	 * Varios dueños propuestos para la misma clave: primero el más instalado.
	 *
	 * ESTO ORDENA, NO DECIDE, y la distinción es toda la cuestión. La popularidad NO es
	 * propiedad: `action_scheduler_run_queue` la reclaman 24 plugins y el más instalado de
	 * todos es WooCommerce, cuando la respuesta correcta es Action Scheduler. Si el número de
	 * instalaciones pudiera resolver una disputa, daría ahí una respuesta segura y equivocada,
	 * que es la peor clase.
	 *
	 * Lo que sí resuelve es el orden de lectura. Entre un plugin y su copia reempaquetada el
	 * original tiene órdenes de magnitud más instalaciones, y enseñarlo primero es la
	 * diferencia entre que alguien entienda la fila y que desconfíe de la pantalla entera.
	 *
	 * El dato sale de la consulta a wordpress.org que ya se hacía para el NOMBRE: cero
	 * peticiones nuevas. Con el recuento a cero —una extensión privada, o que aún no se ha
	 * resuelto— el orden de llegada se conserva, porque `usort` aquí es estable desde PHP 8.0 y
	 * este plugin pide 7.4 o superior; de ahí el desempate explícito por posición.
	 *
	 * @param array<int,array{slug:string,origin:string}> $pairs Pares dueño/origen.
	 * @return array<int,array{slug:string,origin:string}>
	 */
	protected function sort_pairs_by_reach( array $pairs ) {
		if ( count( $pairs ) < 2 || ! function_exists( 'wpoptimizing_ext_installs' ) ) {
			return $pairs;
		}
		$con_posicion = array();
		foreach ( array_values( $pairs ) as $i => $pair ) {
			$con_posicion[] = array(
				'pair'     => $pair,
				'pos'      => $i,
				'installs' => wpoptimizing_ext_installs( (string) ( $pair['slug'] ?? '' ) ),
			);
		}
		usort(
			$con_posicion,
			static function ( $a, $b ) {
				if ( $a['installs'] === $b['installs'] ) {
					return $a['pos'] <=> $b['pos'];
				}
				return $b['installs'] <=> $a['installs'];
			}
		);

		return array_column( $con_posicion, 'pair' );
	}

	public function format_projects_nicely( array $row ) {
		$pairs = $this->get_row_project_pairs( $row );
		if ( empty( $pairs ) ) {
			return '<em>-</em>';
		}

		$plugin_names = $this->get_all_plugins_by_slug();
		$theme_names  = $this->get_all_themes_by_slug();

		/*
		 * LA ETIQUETA LA DECIDE EL VOCABULARIO, NO ESTE if/elseif. Aqui habia una cadena de
		 * cuatro ramas escrita a mano, y un origen que no cayera en ninguna desaparecia sin
		 * aviso: la celda salia vacia y nadie se enteraba. Asi vivio `runtime-map`, que no era
		 * un origen, borrando la atribucion de la capa mas certera que tenemos.
		 *
		 * Lo unico que sigue decidiendose aqui es DE QUE MAPA se saca el nombre, porque el de
		 * plugins y el de temas son dos y no se pueden mezclar — que es el otro fallo que hubo:
		 * nombrar un slug de tema desde el cache de plugins.
		 */
		$pairs = $this->sort_pairs_by_reach( $pairs );

		$lines = array();
		foreach ( $pairs as $pair ) {
			$slug = (string) ( $pair['slug'] ?? '' );
			$ori  = (string) ( $pair['origin'] ?? '' );

			$name = $slug;
			if ( 'plugin' === $ori ) {
				$name = $this->resolve_project_name( $slug, $plugin_names, 'plugin' );
			} elseif ( 'theme' === $ori ) {
				$name = $this->resolve_project_name( $slug, $theme_names, 'theme' );
			}

			$label = wpoptimizing_attribution_origin_label( $ori, $name );
			if ( '' !== $label ) {
				$lines[] = esc_html( $label );
			}
		}
		return empty( $lines ) ? '<em>-</em>' : '<small>' . implode( '<br>', $lines ) . '</small>';
	}

	/**
	 * Resolve a plugin/theme slug to its installed display name (case-insensitive). When the
	 * slug is not installed here (e.g. it came from the shared dataset) the raw slug is
	 * returned unchanged — never a guessed/humanised name.
	 *
	 * EL NOMBRE DE RESERVA SALE DEL DIRECTORIO DE PLUGINS, Y SOLO DE AHI. El cache que lo
	 * guarda (`wpoptimizing_ext_names`) se llena unicamente con `plugins_api()`; no hay una sola
	 * llamada a `themes_api` en todo el plugin. Asi que para un slug con origen `theme` que no
	 * esta instalado aqui, ese cache devolvia el nombre del PLUGIN que tiene ese slug en
	 * wordpress.org, y la columna pintaba «Theme: <nombre de un plugin>»: en produccion,
	 * «Theme: LifterLMS – WP LMS» y «Theme: IP Locator» sobre opciones que no son de ningun tema.
	 *
	 * Un nombre resuelto en el directorio equivocado es PEOR que el slug pelado, porque parece
	 * autorizado. Asi que cuando el origen es `theme` no se consulta el nombre de plugin: sólo el
	 * de tema que trae la capa `nombres`, con su propia clave, y si no lo hay, el slug.
	 *
	 * @param string               $slug   Slug.
	 * @param array<string,string> $map    slug => Name of installed plugins/themes.
	 * @param string               $origin 'plugin', 'theme', o '' cuando quien llama no sabe de
	 *                                     cual de los dos es (p. ej. la columna de Autoload, que
	 *                                     no pinta el tipo y busca en los dos mapas a la vez).
	 * @return string
	 */
	protected function resolve_project_name( $slug, array $map, $origin = '' ) {
		$name = $this->resolve_project_name_raw( $slug, $map, $origin );
		return function_exists( 'wpoptimizing_plain_name' ) ? wpoptimizing_plain_name( $name ) : $name;
	}

	/**
	 * resolve_project_name() before its plain-text pass.
	 *
	 * @param string               $slug   Slug.
	 * @param array<string,string> $map    slug => Name.
	 * @param string               $origin Origin.
	 * @return string
	 */
	protected function resolve_project_name_raw( $slug, array $map, $origin = '' ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return $slug;
		}
		if ( isset( $map[ $slug ] ) ) {
			return (string) $map[ $slug ];
		}
		$slug_l = strtolower( $slug );
		foreach ( $map as $s => $name ) {
			if ( strtolower( (string) $s ) === $slug_l ) {
				return (string) $name;
			}
		}
		// Not installed here: use the name resolved from the WordPress.org directory and
		// cached in the local KB (background-resolved, never blocks). Falls back to the slug
		// for private plugins or until the lookup completes.
		//
		// Un tema se busca con su propia clave (`theme:slug`), que sólo llena la capa `nombres`
		// (27/10/2026): el nombre de un plugin homónimo no puede colarse.
		if ( 'theme' === $origin ) {
			$tema = function_exists( 'wpoptimizing_ext_name' ) ? wpoptimizing_ext_name( $slug, 'theme' ) : '';
			return '' !== $tema ? $tema : $slug;
		}
		$nested = self::nested_plugin_name( $slug );
		if ( '' !== $nested ) {
			/* translators: %s: the plugin's own name, read from its header. */
			return sprintf( __( '%s (in a folder WordPress does not load)', 'wpoptimizing' ), $nested );
		}
		if ( function_exists( 'wpoptimizing_ext_name' ) ) {
			$resolved = wpoptimizing_ext_name( $slug );
			if ( '' !== $resolved ) {
				return $resolved;
			}
		}
		return $slug; // Unknown → show the slug as-is.
	}

	/**
	 * The name of a plugin left one or two folders too deep in wp-content/plugins.
	 *
	 * WordPress only looks for a plugin header one level under plugins/, so a package uploaded
	 * as it came from the marketplace (plugins/<download-name>/Plugin/<plugin>/<plugin>.php)
	 * never loads and never shows in the plugin list, and the folder is all the scan has to
	 * name it by: on emiliocalvo.es (17/10/2026) "sigdbe1ce2…mwpdl02Fbulk2Fcodecanyon-7119279-…"
	 * for WooCommerce Dynamic Pricing & Discounts. Its own header says what it is.
	 *
	 * @param string      $slug Folder under wp-content/plugins.
	 * @param string|null $root Plugins folder; WP_PLUGIN_DIR when null.
	 * @return string Plugin name, or '' when there is no such folder or no header in it.
	 */
	protected static function nested_plugin_name( $slug, $root = null ) {
		static $cache   = array();
		static $folders = array();
		$slug = (string) $slug;
		$root = null === $root ? ( defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '' ) : (string) $root;
		$key  = $root . '|' . $slug;
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}
		$cache[ $key ] = '';
		if ( '' === $slug || '' === $root || false !== strpbrk( $slug, '/\\' ) || false !== strpos( $slug, '..' ) || ! function_exists( 'get_file_data' ) ) {
			return '';
		}
		$dir = $root . '/' . $slug;
		if ( ! is_dir( $dir ) ) {
			// The shared layers lowercase slugs; the folder on disk keeps its case.
			if ( ! isset( $folders[ $root ] ) ) {
				$folders[ $root ] = array();
				foreach ( (array) glob( $root . '/*', GLOB_ONLYDIR ) as $f ) {
					$folders[ $root ][ strtolower( basename( (string) $f ) ) ] = (string) $f;
				}
			}
			$dir = $folders[ $root ][ strtolower( $slug ) ] ?? '';
		}
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return '';
		}
		$files = array_merge( (array) glob( $dir . '/*/*.php' ), (array) glob( $dir . '/*/*/*.php' ) );
		foreach ( array_slice( $files, 0, 200 ) as $file ) {
			$data = get_file_data( (string) $file, array( 'name' => 'Plugin Name' ) );
			if ( '' !== trim( (string) $data['name'] ) ) {
				$cache[ $key ] = trim( (string) $data['name'] );
				break;
			}
		}
		return $cache[ $key ];
	}

	/**
	 * Public slug → display name for a single owner, for surfaces that only have the raw slug
	 * on hand (e.g. the Autoload attribution column, rendered by a separate list-table class).
	 * Tries installed plugins, then installed themes, then the wp.org-resolved cache, then the
	 * slug unchanged. Never guesses a humanised name.
	 *
	 * @param string $slug   Plugin/theme slug.
	 * @param string $origin 'plugin' or 'theme' when known; '' looks in both.
	 * @return string
	 */
	public function ext_display_name( $slug, $origin = '' ) {
		$slug = (string) $slug;
		if ( '' === $slug ) {
			return $slug;
		}
		// A library or the core is named by its label («Action Scheduler», «WordPress»), which is
		// no extension slug: asked as one, it ended in the wordpress.org queue (27/10/2026).
		// `unknown` stays asked: it is an extension whose class is not known.
		if ( ! in_array( (string) $origin, array( '', 'plugin', 'theme', 'unknown' ), true ) ) {
			return $slug;
		}
		// With the class known, its own map only: a theme `acme` must not take the name of a plugin
		// `acme`, installed or cached from wordpress.org (audit of 26/10/2026).
		if ( 'theme' === $origin ) {
			return $this->resolve_project_name( $slug, $this->get_all_themes_by_slug(), 'theme' );
		}
		if ( 'plugin' === $origin ) {
			return $this->resolve_project_name( $slug, $this->get_all_plugins_by_slug(), 'plugin' );
		}
		$map = $this->get_all_plugins_by_slug() + $this->get_all_themes_by_slug();
		return $this->resolve_project_name( $slug, $map );
	}

	/**
	 * The owner name of a manual attribution rule: the one a person typed, or, when the rule only
	 * carries the slug (the attribution assistant stores `name` = slug), the display name.
	 *
	 * @param array $rule Rule {slug, name, type}.
	 * @return string
	 */
	public function manual_rule_owner_name( array $rule ) {
		$slug = (string) ( $rule['slug'] ?? '' );
		$name = trim( (string) ( $rule['name'] ?? '' ) );
		if ( '' !== $name && strtolower( $name ) !== strtolower( $slug ) ) {
			return $name;
		}
		$tipo = (string) ( $rule['type'] ?? '' );
		if ( in_array( $tipo, array( 'library', 'core' ), true ) ) {
			return $slug;
		}
		return $this->ext_display_name( $slug, in_array( $tipo, array( 'plugin', 'theme' ), true ) ? $tipo : '' );
	}

	/**
	 * Collect distinct "type" values for the filter (works for DB and FS EN rows).
	 *
	 * @param string $scope Scope (unused, kept for signature parity).
	 * @param array  $rows  Normalized EN rows.
	 * @return string[]
	 */
	protected function collect_types( $scope, array $rows ) {
		$set = array();
		foreach ( $rows as $r ) {
			if ( in_array( $scope, array( 'db', 'ibd' ), true ) ) {
				$type = isset( $r['type_filter'] ) ? (string) $r['type_filter'] : (string) ( $r['type'] ?? '' );
			} else {
				$type = (string) ( $r['type'] ?? '' );
			}
			if ( '' !== $type ) {
				$set[ $type ] = true;
			}
		}
		$types = array_keys( $set );
		natcasesort( $types );
		return array_values( $types );
	}

	/**
	 * Collect distinct project choices for filtering (plugin/theme).
	 *
	 * @param array $rows Normalized EN rows.
	 * @return array<string,string> map of value => label
	 */
	protected function collect_project_choices( array $rows ) {
		$choices = array();
		$plugins = $this->get_all_plugins_by_slug();
		$themes  = $this->get_all_themes_by_slug();

		foreach ( $rows as $row ) {
			foreach ( $this->get_row_project_pairs( $row ) as $pair ) {
				$origin = $pair['origin'];
				$slug   = $pair['slug'];
				if ( '' === $origin || '' === $slug ) {
					continue;
				}
				$value = $origin . ':' . $slug;

				/*
				 * ESTE DESPLEGABLE TAMBIEN SALE DEL VOCABULARIO. Tenia su propia cadena de
				 * ramas, la segunda en este mismo fichero, y no era igual que la de la columna:
				 * se dejaba fuera `core` entera, asi que un elemento del nucleo aparecia en la
				 * tabla y no se podia filtrar por el. Dos listas del mismo concepto a nueve
				 * lineas de distancia, y ya habian divergido.
				 */
				// The same name as the column (installed, then the wordpress.org cache): the list
				// offered slugs the table no longer showed.
				$name = $slug;
				if ( 'plugin' === $origin ) {
					$name = $this->resolve_project_name( $slug, $plugins, 'plugin' );
				} elseif ( 'theme' === $origin ) {
					$name = $this->resolve_project_name( $slug, $themes, 'theme' );
				}

				$label = wpoptimizing_attribution_origin_label( $origin, $name );
				if ( '' === $label ) {
					continue;
				}
				$choices[ $value ] = $label;
			}
		}
		if ( empty( $choices ) ) {
			return array();
		}
		natcasesort( $choices );
		return $choices;
	}

	/**
	 * Extract project origin/slug pairs from a row.
	 *
	 * @param array $row Row data.
	 * @return array<int,array{origin:string,slug:string}>
	 */
	protected function get_row_project_pairs( array $row ) {
		$orig = array_filter( array_map( 'trim', explode( '|', (string) ( $row['project_origins'] ?? '' ) ) ) );
		$slgs = array_filter( array_map( 'trim', explode( '|', (string) ( $row['project_slugs'] ?? '' ) ) ) );
		if ( empty( $orig ) || empty( $slgs ) ) {
			$is_fs = isset( $row['relative_path'] );
			$ext   = $is_fs
				? $this->resolve_remote_fs_extension( (string) ( $row['relative_path'] ?? '' ) )
				: $this->resolve_remote_db_extension( (string) ( $row['type'] ?? '' ), (string) ( $row['value'] ?? '' ) );
			if ( ! empty( $ext['slug'] ) ) {
				$orig = array_filter( array_map( 'trim', explode( '|', (string) ( $ext['origin'] ?? '' ) ) ) );
				$slgs = array_filter( array_map( 'trim', explode( '|', (string) ( $ext['slug'] ?? '' ) ) ) );
			}
		}
		// Local manual attribution: the owner asserted this item belongs to a plugin/theme.
		// Applied last, only when nothing else attributed it, so the item leaves Pending and
		// the existing status logic routes it to Inactive/Orphan by installed state.
		if ( ( empty( $orig ) || empty( $slgs ) ) && function_exists( 'wpoptimizing_manual_attribution_match_all' ) ) {
			$mans = wpoptimizing_manual_attribution_match_all( (string) ( $row['value'] ?? '' ), (string) ( $row['relative_path'] ?? '' ) );
			if ( ! empty( $mans ) ) {
				// A value can belong to several owners (e.g. wysija → MailPoet AND
				// MailPoet Premium); surface them all.
				$orig = array();
				$slgs = array();
				foreach ( $mans as $man ) {
					$orig[] = $man['origin'];
					$slgs[] = $man['slug'];
				}
			}
		}
		$pairs          = array();
		$seen           = array();
		$default_origin = '';
		if ( 1 === count( $orig ) ) {
			$default_origin = (string) reset( $orig );
		}
		foreach ( $slgs as $i => $slug ) {
			$origin = $orig[ $i ] ?? $default_origin;
			$origin = '' !== $origin ? $origin : 'plugin';
			if ( '' !== $slug ) {
				$key = $origin . ':' . $slug;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$pairs[]      = array(
					'origin' => $origin,
					'slug'   => $slug,
				);
			}
		}
		return $pairs;
	}

	/**
	 * Get a sortable string for the project column.
	 *
	 * @param array $row Row.
	 * @return string
	 */
	public function get_row_project_sort_value( array $row ) {
		$pairs = $this->get_row_project_pairs( $row );
		if ( empty( $pairs ) ) {
			return '';
		}
		$pair   = $pairs[0];
		$origin = $pair['origin'];
		$slug   = $pair['slug'];
		// Sorted by the name the column shows, not by the slug behind it.
		if ( 'plugin' === $origin ) {
			return $this->resolve_project_name( $slug, $this->get_all_plugins_by_slug(), 'plugin' );
		}
		if ( 'theme' === $origin ) {
			return $this->resolve_project_name( $slug, $this->get_all_themes_by_slug(), 'theme' );
		}
		return $slug;
	}

	/**
	 * Filter rows by extension (origin:slug).
	 *
	 * @param array  $rows Rows.
	 * @param string $filter Filter value.
	 * @return array
	 */
	protected function apply_extension_filter( array $rows, $filter ) {
		$filter = trim( (string) $filter );
		if ( '' === $filter ) {
			return $rows;
		}
		$origin = '';
		$slug   = $filter;
		if ( false !== strpos( $filter, ':' ) ) {
			list( $origin, $slug ) = array_pad( explode( ':', $filter, 2 ), 2, '' );
		}
		$origin = trim( $origin );
		$slug   = trim( $slug );
		if ( '' === $slug ) {
			return $rows;
		}

		return array_values(
			array_filter(
				$rows,
				function ( $row ) use ( $origin, $slug ) {
					foreach ( $this->get_row_project_pairs( $row ) as $pair ) {
						if ( '' !== $origin && $pair['origin'] !== $origin ) {
							continue;
						}
						if ( $pair['slug'] === $slug ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Split rows by effective status (active|inactive|none).
	 * Uses persisted status first; if missing/none, resolves extension pairs against installed active/inactive sets.
	 *
	 * @param array $rows Rows.
	 * @return array{active:array,inactive:array,none:array}
	 */
	protected function split_rows_by_effective_status( array $rows ) {
		$out = array(
			'active'   => array(),
			'inactive' => array(),
			'orphan'   => array(),
			'none'     => array(),
		);
		foreach ( $rows as $row ) {
			$status = $this->get_effective_row_status( (array) $row );
			if ( ! isset( $out[ $status ] ) ) {
				$status = 'none';
			}
			$out[ $status ][] = $row;
		}
		return $out;
	}

	/**
	 * Resolve effective row status from explicit CSV field and installed extension state.
	 *
	 * @param array $row Row.
	 * @return string active|inactive|orphan|none
	 */
	public function get_effective_row_status( array $row ) {
		$status = $this->resolve_effective_row_status( $row );

		/*
		 * A CAPABILITY NOBODY CLAIMS STAYS PUT. It lives inside the roles, so deleting it strips
		 * it from every role at once — and WooCommerce's edit_shop_orders, granted in a loop the
		 * code scan cannot read, had no owner and sat in Pending with a checkbox. A capability
		 * still in a role is in use by definition; without an owner this site can name, it is
		 * read-only, not "to identify".
		 */
		if ( 'none' === $status && ! isset( $row['relative_path'] ) && 'capability' === strtolower( trim( (string) ( $row['type'] ?? '' ) ) ) ) {
			return 'active';
		}
		return $status;
	}

	/**
	 * The status the row's attribution supports, before the capability rule above.
	 *
	 * @param array $row Row.
	 * @return string active|inactive|orphan|none
	 */
	protected function resolve_effective_row_status( array $row ) {
		$is_fs = isset( $row['relative_path'] );

		/*
		 * CRITICAL safety gate: an ORPHAN or INACTIVE verdict is only trustworthy when the
		 * attribution is manual/certain/strong/medium. A "weak" (crowd-sourced dataset) or
		 * "heuristic" guess pointing at an uninstalled or inactive extension must NEVER put
		 * in-use data in a section built for deleting — e.g. WooCommerce customer meta wrongly
		 * crowd-matched to an unrelated plugin. Such rows go to Pending for manual review. It
		 * applies to the persisted verdict (the overlay bakes it for crowd matches) and to the
		 * computed one, so it is the single choke point.
		 *
		 * FILES TOO. They were exempt "because the manifest attributes them", and the manifest
		 * never answered for a single one (its keys are wp-content/plugins/…, this scan lists
		 * wp-content's top level). A file row has no confidence column of its own: what the
		 * local scan found is code evidence (medium), and the shared dataset marks what it
		 * fills as weak (apply_remote_fs_mapping()).
		 */
		$level = (string) ( $row['confianza'] ?? '' );
		if ( $is_fs && '' === $level ) {
			$level = 'medium';
		}
		// Qué niveles dejan borrar lo dice el vocabulario único (resolvers/confidence-levels.php),
		// no una lista escrita aquí.
		$trust_orphan = wpoptimizing_confidence_allows_deletion( $level );

		$raw = strtolower( trim( (string) ( $row['project_status'] ?? '' ) ) );
		if ( 'active' === $raw ) {
			return $raw;
		}
		if ( 'inactive' === $raw || 'orphan' === $raw ) {
			return $trust_orphan ? $raw : 'none';
		}

		$pairs = $this->get_row_project_pairs( $row );
		if ( empty( $pairs ) ) {
			return 'none'; // No owner identified at all → genuinely "Pending".
		}

		static $active_plugins   = null;
		static $inactive_plugins = null;
		static $active_themes    = null;
		static $inactive_themes  = null;
		if ( null === $active_plugins ) {
			$active_plugins   = array_flip( $this->get_all_active_plugin_slugs() );
			$inactive_plugins = array_flip( $this->get_all_inactive_plugin_slugs() );
			if ( function_exists( 'wpoptimizing_mu_plugin_slugs' ) ) {
				$active_plugins += wpoptimizing_mu_plugin_slugs(); // Must-use: always active.
			}
			$active_themes    = array_flip( $this->get_all_active_theme_slugs() );
			$inactive_themes  = array_flip( $this->get_all_inactive_theme_slugs() );
		}

		$has_inactive = false;
		foreach ( $pairs as $pair ) {
			$origin = (string) ( $pair['origin'] ?? '' );
			$slug   = (string) ( $pair['slug'] ?? '' );
			if ( '' === $slug ) {
				continue;
			}
			if ( 'plugin' === $origin ) {
				if ( isset( $active_plugins[ $slug ] ) ) {
					return 'active';
				}
				if ( isset( $inactive_plugins[ $slug ] ) ) {
					$has_inactive = true;
				}
				continue;
			}
			if ( 'theme' === $origin ) {
				if ( isset( $active_themes[ $slug ] ) ) {
					return 'active';
				}
				if ( isset( $inactive_themes[ $slug ] ) ) {
					$has_inactive = true;
				}
			}
		}

		// We reach here only with a NON-EMPTY owner set (empty pairs returned 'none'
		// above). So the item IS attributed to a known plugin/theme — by the dataset, the
		// KB, or a manual attribution. If that owner is installed-but-inactive it is
		// Inactive; if it is NOT installed, it is a candidate Orphan — but only a
		// trustworthy attribution may say so (see $trust_orphan above); an untrusted DB
		// guess falls back to Pending.
		if ( $has_inactive ) {
			return $trust_orphan ? 'inactive' : 'none';
		}
		return $trust_orphan ? 'orphan' : 'none';
	}

	/**
	 * Filter DB rows by type + query (normalized EN keys).
	 *
	 * @param array  $rows Rows.
	 * @param string $type Filter type.
	 * @param string $q    Search query.
	 * @return array
	 */
	protected function apply_tipo_filter_bd( array $rows, $type, $q = '' ) {
		$type = $this->normalize_db_type( (string) $type );
		if ( '' !== $type ) {
			$rows = array_values(
				array_filter(
					$rows,
					function ( $r ) use ( $type ) {
						if ( isset( $r['type_filter'] ) ) {
							return (string) $r['type_filter'] === $type;
						}
						if ( ! isset( $r['type'] ) ) {
							return false;
						}
						return $this->normalize_db_type( (string) $r['type'] ) === $type;
					}
				)
			);
		}
		$q = trim( (string) $q );
		if ( '' !== $q ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q, 'UTF-8' ) : strtolower( $q );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $needle ) {
						$val = (string) ( $r['value'] ?? '' );
						$val = function_exists( 'mb_strtolower' ) ? mb_strtolower( $val, 'UTF-8' ) : strtolower( $val );
						return '' !== $val && false !== strpos( $val, $needle );
					}
				)
			);
		}
		return $rows;
	}

	/**
	 * Normalize DB "type" values into stable buckets for filters.
	 *
	 * @param string $type Raw type.
	 * @return string
	 */
	protected function normalize_db_type( $type ) {
		$type = strtolower( trim( (string) $type ) );
		$type = preg_replace( '/[^a-z0-9_]+/', '_', $type );
		$type = preg_replace( '/_+/', '_', $type );
		$type = trim( $type, '_' );
		if ( '' === $type ) {
			return '';
		}
		$map = array(
			'update_option'           => 'option',
			'delete_option'           => 'option',
			'register_setting'        => 'option',
			'site_option'             => 'option',
			'site_update_option'      => 'option',
			'site_delete_option'      => 'option',
			'option_transient'        => 'transient',
			'option_transients'       => 'transient',
			'transient_set'           => 'transient',
			'transient_delete'        => 'transient',
			'post_meta_add'           => 'post_meta',
			'post_meta_update'        => 'post_meta',
			'post_meta_delete'        => 'post_meta',
			'post_meta_delete_by_key' => 'post_meta',
			'register_post_meta'      => 'post_meta',
			'postmeta'                => 'post_meta',
			'user_meta_add'           => 'user_meta',
			'user_meta_update'        => 'user_meta',
			'user_meta_delete'        => 'user_meta',
			'usermeta'                => 'user_meta',
			'register_term_meta'      => 'term_meta',
			'termmeta'                => 'term_meta',
			'meta_add'                => 'post_meta',
			'meta_update'             => 'post_meta',
			'meta_delete'             => 'post_meta',
			'meta_key'                => 'post_meta', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		);
		return $map[ $type ] ?? $type;
	}

	/**
	 * Filter FS rows by type + query (normalized EN keys).
	 *
	 * @param array  $rows Rows.
	 * @param string $type Filter type.
	 * @param string $q    Search query.
	 * @return array
	 */
	protected function apply_tipo_filter_fs( array $rows, $type, $q = '' ) {
		if ( '' !== $type ) {
			$rows = array_values(
				array_filter(
					$rows,
					static fn( $r ) => isset( $r['type'] ) && $r['type'] === $type
				)
			);
		}
		$q = trim( (string) $q );
		if ( '' !== $q ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $q, 'UTF-8' ) : strtolower( $q );
			$rows   = array_values(
				array_filter(
					$rows,
					static function ( $r ) use ( $needle ) {
						$val = (string) ( $r['relative_path'] ?? '' );
						$val = function_exists( 'mb_strtolower' ) ? mb_strtolower( $val, 'UTF-8' ) : strtolower( $val );
						return '' !== $val && false !== strpos( $val, $needle );
					}
				)
			);
		}
		return $rows;
	}
}
