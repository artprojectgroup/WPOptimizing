<?php
/**
 * De qué clase es el dueño de un elemento: el vocabulario, en UN solo sitio.
 *
 * Había cuatro listas para el mismo concepto y ninguna sabía de las otras:
 *
 *   - el lector (la columna «Extension») entendía plugin, theme, core y library;
 *   - el escáner, que es quien ALIMENTA la base de conocimiento, solo sabe emitir plugin y
 *     theme: el origen es un argumento del constructor, fijo por carpeta raíz;
 *   - el alimentador de activos del mu-plugin usa plugin, theme, core y unknown;
 *   - y el alimentador de atribución de base de datos NO TIENE CAMPO para esto: registra el
 *     slug y nunca la clase.
 *
 * De ahí salieron dos averías reales. `library` lleva tiempo en el lector y no la produce
 * nadie, así que una biblioteca empaquetada —Action Scheduler dentro de decenas de plugins—
 * se sube atribuida al plugin que la lleva, distinto en cada sitio que contribuye. Y como el
 * alimentador de base de datos no tiene dónde poner la clase, quien lo consumía se inventó el
 * valor `runtime-map`, que no era un origen: el lector no sabía pintarlo y la fila salía con un
 * guion, justo en la capa más certera que tenemos.
 *
 * Un origen que nadie entiende no da error: la etiqueta desaparece y ya está. Por eso la regla
 * es que añadir uno aquí es el ÚNICO paso, y que el lector y los productores salen de esta
 * lista — `AttributionContractTest` lee los tres y lo exige.
 *
 * NOTA sobre el mu-plugin: se carga antes que los plugins, así que no puede incluir este
 * fichero. Su vocabulario no se comparte por código sino por contrato, y el test lo comprueba
 * leyendo su fuente.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Todo origen que cualquier capa puede emitir, y cómo se pinta.
 *
 * El modo existe para distinguir dos cosas que si no se confunden: un origen que a propósito
 * no lleva etiqueta, y uno que no lleva etiqueta porque nadie lo declaró. El segundo es el
 * fallo, y sin esta columna los dos se ven igual: una celda vacía.
 *
 *   'named' → «Etiqueta: <nombre del proyecto>».
 *   'fixed' → una etiqueta sin nombre, porque no hay proyecto que nombrar.
 *   'bare'  → SÓLO el nombre, sin palabra de clase. Para cuando se sabe DE QUIÉN es algo y no
 *             de qué clase era. Con el nombre vacío no pinta nada, que es el otro uso legítimo.
 *
 * @return array<string,string> origen => modo.
 */
function wpoptimizing_attribution_origins() {
	return array(
		'plugin'  => 'named',
		'theme'   => 'named',
		'core'    => 'fixed',
		'library' => 'named',
		/*
		 * DOS USOS, UNA REGLA. El alimentador de activos lo emite con el slug VACÍO cuando la
		 * ruta no cae bajo plugins/, themes/ ni el núcleo: ahí no hay nada que enseñar y no se
		 * pinta nada, como antes.
		 *
		 * Y desde el 2026-10-07 lo emite también el escáner CON slug, para el caso que no
		 * existía en el vocabulario: el mapa en tiempo de ejecución dice de QUIÉN es una clave y
		 * nunca de qué clase era, así que para un huérfano —que por definición ya no está
		 * instalado— sabemos el nombre y no la clase. Antes eso se resolvía con un `else` que
		 * decía «tema», y por eso TODOS los huérfanos salían como temas.
		 *
		 * Pintar sólo el nombre dice exactamente lo que se sabe, sin inventar y sin perder el
		 * dato útil.
		 */
		'unknown' => 'bare',
	);
}

/**
 * ¿Es este un origen que el plugin entiende?
 *
 * @param string $origin Origen.
 * @return bool
 */
function wpoptimizing_attribution_origin_exists( $origin ) {
	return isset( wpoptimizing_attribution_origins()[ (string) $origin ] );
}

/**
 * La etiqueta de un origen, en TEXTO PLANO: la escapa quien la pinta.
 *
 * Se conservan los mismos msgid que ya existían («Plugin», «Theme», «WordPress core»,
 * «Shared library: %s») para no tirar las traducciones de los ocho idiomas por mover el
 * código de sitio.
 *
 * @param string $origin Origen.
 * @param string $name   Nombre ya resuelto del proyecto (o su slug).
 * @return string Etiqueta, o '' cuando el origen no pinta nada.
 */
function wpoptimizing_attribution_origin_label( $origin, $name = '' ) {
	$origin = (string) $origin;
	$modo   = wpoptimizing_attribution_origins()[ $origin ] ?? '';

	if ( 'library' === $origin ) {
		/* translators: %s: name of a library bundled inside other plugins, e.g. Action Scheduler. */
		return sprintf( __( 'Shared library: %s', 'wpoptimizing' ), $name );
	}
	if ( 'core' === $origin ) {
		return __( 'WordPress core', 'wpoptimizing' );
	}
	if ( 'plugin' === $origin ) {
		return __( 'Plugin', 'wpoptimizing' ) . ': ' . $name;
	}
	if ( 'theme' === $origin ) {
		return __( 'Theme', 'wpoptimizing' ) . ': ' . $name;
	}

	// Sin clase conocida: el nombre a secas, que es lo único que de verdad se sabe. Con el
	// nombre vacío esto devuelve '' y la fila se queda sin etiqueta, igual que antes.
	if ( 'bare' === $modo ) {
		return $name;
	}

	// Un origen que no está declarado: ni etiqueta ni invento.
	return '';
}

/**
 * The must-use plugins of this site, by slug: what get_mu_plugins() lists (a file in
 * mu-plugins/, named without .php) plus every subfolder of mu-plugins/ (the code a loader
 * file requires). Lowercased.
 *
 * They are ALWAYS active: WordPress loads them on every request and they cannot be
 * deactivated. Nothing treated them that way — the code scan wrote their rows as `inactive`
 * because they are not in active_plugins, and an option a mu-plugin writes was offered for
 * deletion as belonging to an inactive extension.
 *
 * @param string|null $dir mu-plugins directory; null = WPMU_PLUGIN_DIR (cached per request).
 * @return array<string,true>
 */
function wpoptimizing_mu_plugin_slugs( $dir = null ) {
	static $cache = null;
	$site         = ( null === $dir );
	if ( $site && null !== $cache ) {
		return $cache;
	}
	if ( $site ) {
		$dir = defined( 'WPMU_PLUGIN_DIR' ) ? (string) WPMU_PLUGIN_DIR : '';
	}
	$dir   = rtrim( (string) $dir, '/' );
	$slugs = array();
	if ( '' !== $dir && is_dir( $dir ) ) {
		$files = ( $site && function_exists( 'get_mu_plugins' ) ) ? array_keys( (array) get_mu_plugins() ) : (array) glob( $dir . '/*.php' );
		foreach ( $files as $file ) {
			$slug = strtolower( basename( (string) $file, '.php' ) );
			if ( '' !== $slug && 'index' !== $slug ) {
				$slugs[ $slug ] = true;
			}
		}
		foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $sub ) {
			$slugs[ strtolower( basename( (string) $sub ) ) ] = true;
		}
	}
	if ( $site ) {
		$cache = $slugs;
	}
	return $slugs;
}
