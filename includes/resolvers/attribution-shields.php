<?php
/**
 * Los escudos de atribución, en UN solo sitio y consultados en los DOS puntos que atribuyen.
 *
 * Hay cuatro casos en los que la pregunta «¿de quién es esto?» tiene una respuesta mejor que
 * la de cualquier capa de datos, y los cuatro estaban escritos UNA sola vez, en el momento de
 * pintar la pantalla:
 *
 *   1. LO NUESTRO. `manage_wpoptimizing` es de este plugin. Visto en producción atribuida a
 *      un plugin llamado `booktics`, con confianza SEGURO y por tanto borrable.
 *   2. EL NÚCLEO. `page_on_front` es de WordPress, diga lo que diga el conjunto compartido.
 *   3. LAS LIBRERÍAS EMPOTRADAS. Action Scheduler va dentro de decenas de plugins, así que el
 *      escáner anota como dueño al anfitrión que le tocó leer —«con certeza», porque el
 *      código está ahí de verdad—. Desinstala ese anfitrión y la taxonomía y las tablas de la
 *      librería salen como huérfanas seguras de un plugin que ya no está, mientras WooCommerce
 *      sigue usando esas mismas filas para su cola. Nadie puede contestar de quién es una
 *      librería empotrada, así que se contesta lo que SÍ es decidible: si sigue instalada.
 *   4. LOS METADATOS DE PLATAFORMA. `_sku` lo registra WooCommerce; un complemento que
 *      sólo lo LEE no es su dueño.
 *
 * EL PROBLEMA ERA DÓNDE ESTABAN. El escáner —que es quien escribe el CSV— tenía el escudo
 * propio y el del núcleo, y NO los otros dos. Y ese CSV no sólo lo lee la pantalla, que
 * aplica la superposición y lo corrige: lo sirve también `GET /results` de la API REST, tal
 * cual. Quien automatizara la limpieza contra la API recibía `as_has_wp_comment_logs` como
 * huérfana de `ip-locator` con la máxima confianza. Otra vez la guarda escrita donde alguien
 * se acordó de ella y no donde se decide.
 *
 * El orden importa y es el mismo en los dos sitios. La anulación humana (atribución manual)
 * queda FUERA a propósito: no es un escudo sino la autoridad máxima, y se resuelve antes.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * ¿Hay un escudo que conteste por esta clave?
 *
 * @param string $tipo  Tipo normalizado (option, post_type, taxonomy, meta, role, capability…).
 * @param string $value Clave.
 * @param array  $slugs Estado de las extensiones de este sitio:
 *                      'active'    => [slug en minúsculas => cualquier cosa],
 *                      'installed' => [slug en minúsculas => cualquier cosa].
 * @return array{slug:string,origin:string,confianza:string,status:string}|null null cuando
 *         ningún escudo aplica y deciden las capas de datos.
 */
function wpoptimizing_attribution_shield( $tipo, $value, array $slugs = array() ) {
	$tipo  = (string) $tipo;
	$value = (string) $value;
	if ( '' === $value ) {
		return null;
	}
	$activos    = isset( $slugs['active'] ) && is_array( $slugs['active'] ) ? $slugs['active'] : array();
	$instalados = isset( $slugs['installed'] ) && is_array( $slugs['installed'] ) ? $slugs['installed'] : array();

	// 1. Lo nuestro, antes que nada: es la comprobación más barata y no se solapa con ninguna
	// otra —ninguna clave puede ser a la vez de WordPress y nuestra.
	if ( function_exists( 'wpoptimizing_is_own_key' ) && wpoptimizing_is_own_key( $value ) ) {
		return array(
			'slug'      => 'wpoptimizing',
			'origin'    => 'plugin',
			'confianza' => 'certain',
			'status'    => 'active',
		);
	}

	// 2. El núcleo. Nunca huérfano, nunca inactivo, nunca pendiente.
	if ( '' !== $tipo && class_exists( 'WPOPT_WP_Core_Registry' ) && WPOPT_WP_Core_Registry::is_core( $tipo, $value ) ) {
		return array(
			'slug'      => 'WordPress',
			'origin'    => 'core',
			'confianza' => 'certain',
			'status'    => 'active',
		);
	}

	// 3. Librería empotrada que sigue presente. Confianza `strong` y no `certain`: se sabe de
	// qué librería es, no de qué extensión.
	if ( '' !== $tipo && function_exists( 'wpoptimizing_shared_library_for' ) ) {
		$lib = wpoptimizing_shared_library_for( $tipo, $value );
		if ( '' !== $lib && wpoptimizing_shared_library_present( $lib ) ) {
			return array(
				'slug'      => wpoptimizing_shared_library_label( $lib ),
				'origin'    => 'library',
				'confianza' => 'strong',
				'status'    => 'active',
			);
		}
	}

	// 4. Metadato de plataforma: el dueño es quien lo registra, si está en este sitio. Si la
	// plataforma no está instalada no se fuerza nada: entonces sí puede ser un huérfano real.
	if ( '' !== $tipo && class_exists( 'WPOPT_WP_Core_Registry' ) ) {
		$plataforma = (string) WPOPT_WP_Core_Registry::known_platform_meta_owner( $tipo, $value );
		if ( '' !== $plataforma ) {
			$clave = strtolower( $plataforma );
			if ( isset( $activos[ $clave ] ) ) {
				return array(
					'slug'      => $plataforma,
					'origin'    => 'plugin',
					'confianza' => 'strong',
					'status'    => 'active',
				);
			}
			if ( isset( $instalados[ $clave ] ) ) {
				return array(
					'slug'      => $plataforma,
					'origin'    => 'plugin',
					'confianza' => 'strong',
					'status'    => 'inactive',
				);
			}
		}
	}

	return null;
}

/**
 * A database write given to a plugin only because it replaces $wpdb. Pure.
 *
 * Query Monitor, LudicrousDB, HyperDB, W3 Total Cache's database cache and the SQLite
 * integration install a db.php whose code lives in their own folder, so the runtime map's
 * SQL-level capture saw them on the stack of EVERY write and recorded them as the certain owner
 * (emiliocalvo.es, 16/10/2026: Avada's fusion_options, cron, rewrite_rules and 59 more given to
 * Query Monitor). The mu-plugin no longer does it and purges its map once; this catches the
 * same rows wherever an older scan or map still carries them. Keys with the plugin's own prefix
 * stay theirs.
 *
 * @param string $slug  Attributed slug.
 * @param string $tipo  Item type (option, post_meta, user_meta, term_meta, order_meta).
 * @param string $value Key.
 * @return bool
 */
function wpoptimizing_is_wpdb_replacer_artifact( $slug, $tipo, $value ) {
	if ( ! function_exists( 'apg_wpopt_rtmap_is_wpdb_artifact' ) ) {
		$own = array(
			'query-monitor'               => array( 'qm_', 'qm-', 'query_monitor', 'query-monitor' ),
			'w3-total-cache'              => array( 'w3tc' ),
			'ludicrousdb'                 => array( 'ludicrousdb' ),
			'hyperdb'                     => array( 'hyperdb' ),
			'sqlite-database-integration' => array( 'sqlite' ),
		);
		$slug  = strtolower( trim( (string) $slug ) );
		$tipo  = strtolower( trim( (string) $tipo ) );
		$value = ltrim( strtolower( trim( (string) $value ) ), '_' );
		if ( ! isset( $own[ $slug ] ) || ! in_array( $tipo, array( 'option', 'post_meta', 'user_meta', 'term_meta', 'order_meta' ), true ) ) {
			return false;
		}
		foreach ( $own[ $slug ] as $prefix ) {
			if ( 0 === strpos( $value, $prefix ) ) {
				return false;
			}
		}
		return true;
	}
	return apg_wpopt_rtmap_is_wpdb_artifact( array( $slug, $tipo, $value ) );
}
