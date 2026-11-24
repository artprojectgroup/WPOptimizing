<?php
/**
 * ¿Se sostiene una reclamación del mapa en tiempo de ejecución?
 *
 * EL MAPA NO GUARDA DE QUIÉN ES UNA CLAVE: guarda quién se estaba ejecutando cuando la
 * clave se registró. Casi siempre coinciden, y de ahí viene la confianza `certain`. Pero
 * hay dos familias enteras donde no coinciden, y las dos se midieron en producción:
 *
 *   · LIBRERÍAS EMPOTRADAS. `ip-locator` «crea» `as_has_wp_comment_logs` porque trae Action
 *     Scheduler dentro. En el mapa real `action_scheduler_run_queue` la reclaman 24 slugs
 *     distintos y `read_documents` 22: quien llama a `add_option()` es el que empaqueta la
 *     librería, no su autor.
 *   · PLUGINS QUE ESCRIBEN DATOS AJENOS POR OFICIO. Copias de seguridad, migración,
 *     importación, clonado. `wp-time-capsule` «crea» `itsec-storage` al restaurar un
 *     respaldo: llama a `add_option()` por cada opción del sitio entero.
 *
 * La lista de esos plugins sería interminable y habría que mantenerla a mano, que es
 * exactamente la clase de lista que este proyecto no quiere. Lo que SÍ se puede comprobar
 * sin lista ninguna es si la clave pertenece al espacio de nombres del que la reclama:
 *
 *   1. LLEVA SU NOMBRE — la clave contiene el slug, empieza por un tramo suyo de cuatro
 *      letras o más, o por sus siglas.
 *   2. ACOMPAÑA A OTRAS SUYAS — el mismo slug reclama alguna otra clave con la que comparte
 *      prefijo. Así `jd_max_characters` sigue siendo de `wp-to-twitter` (reclama once claves
 *      `jd_*`, el nombre viejo del plugin: «JD's WordPress to Twitter»), mientras
 *      `itsec-storage` se queda sin apoyo en `wp-time-capsule`, que no reclama nada más.
 *
 * Medido sobre el mapa compartido completo (16.660 filas): el 44,1 % lleva el nombre del
 * reclamante, el 50,3 % va acompañada, y el 5,6 % se queda sin apoyo. De esas, la
 * degradación NO BORRA NADA: baja la confianza a «dudosa», con lo que la fila deja de pasar
 * la puerta de borrado de get_effective_row_status() y va a Pendientes, donde se revisa.
 * El error que puede cometer esta regla es pedir una revisión de más, nunca borrar de menos.
 *
 * LÍMITE CONOCIDO, y se deja escrito a propósito: un complemento cuyo slug comparte tramo
 * con el del plugin padre pasa como propio. `fluentform_global_modules_status` reclamada por
 * `fluent-forms-connector-for-mailpoet` es de Fluent Forms, no del conector, y el tramo
 * `fluent` la deja pasar. Para eso hace falta saber qué extensión existe de verdad, que es
 * la capa `manifest` — y hoy no llega a los sitios.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Longitud mínima de un tramo del slug para que identifique a su dueño.
 *
 * Cuatro, y no tres, por una razón medida: con tres, el tramo `woo` deja pasar
 * `woocommerce_queue_flush_rewrite_rules` como propia de `woo-payment-gateway`, que es
 * justo uno de los falsos dueños que hay que cazar. El precio de subir a cuatro son unas
 * pocas abreviaturas cortas legítimas (`oss-aliyun` → `oss_options`) que pasan a revisión
 * manual en lugar de darse por seguras, y ése es el lado barato de equivocarse.
 */
const WPOPTIMIZING_CLAIM_TOKEN_MIN = 4;

/**
 * Letras y dígitos de una cadena, en minúsculas. Un slug y una clave usan separadores
 * distintos para lo mismo (`wp-to-twitter` / `wp_to_twitter`), así que se comparan sin ellos.
 *
 * @param string $texto Texto.
 * @return string
 */
function wpoptimizing_claim_squash( $texto ) {
	return strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', (string) $texto ) );
}

/**
 * Tramos de un slug: `accelerated-mobile-pages` → ['accelerated','mobile','pages'].
 *
 * @param string $slug Slug.
 * @return array<int,string>
 */
function wpoptimizing_claim_tokens( $slug ) {
	return array_values( array_filter( preg_split( '/[^a-z0-9]+/', strtolower( (string) $slug ) ) ) );
}

/**
 * ¿La clave lleva el nombre del slug que la reclama?
 *
 * Devuelve la razón, no un booleano, para que el registro de diagnóstico y los tests puedan
 * decir POR QUÉ se aceptó: sin eso, una regla que deja de funcionar sigue dando verde.
 *
 * @param string $slug  Slug que reclama.
 * @param string $clave Clave reclamada.
 * @return string '' cuando no, o 'nombre' | 'tramo:<tramo>' | 'siglas:<siglas>'.
 */
function wpoptimizing_claim_namespace_reason( $slug, $clave ) {
	$s = wpoptimizing_claim_squash( $slug );
	$k = wpoptimizing_claim_squash( $clave );
	if ( '' === $s || '' === $k ) {
		return '';
	}
	// El slug entero, en cualquier posición: `wp-to-twitter` en `wptotwitter_algo`.
	if ( strlen( $s ) >= 4 && false !== strpos( $k, $s ) ) {
		return 'nombre';
	}
	$tramos = wpoptimizing_claim_tokens( $slug );
	foreach ( $tramos as $tramo ) {
		if ( strlen( $tramo ) >= WPOPTIMIZING_CLAIM_TOKEN_MIN && 0 === strpos( $k, $tramo ) ) {
			return 'tramo:' . $tramo;
		}
	}
	// Siglas de los tramos: `accelerated-mobile-pages` → `amp` → `ampforwp_*`.
	if ( count( $tramos ) >= 2 ) {
		$siglas = '';
		foreach ( $tramos as $tramo ) {
			$siglas .= $tramo[0];
		}
		if ( strlen( $siglas ) >= 2 && 0 === strpos( $k, $siglas ) ) {
			return 'siglas:' . $siglas;
		}
	}
	return '';
}

/**
 * Longitud del prefijo común de dos cadenas.
 *
 * @param string $a Una.
 * @param string $b Otra.
 * @return int
 */
function wpoptimizing_claim_common_prefix( $a, $b ) {
	$a    = (string) $a;
	$b    = (string) $b;
	$tope = min( strlen( $a ), strlen( $b ) );
	$i    = 0;
	while ( $i < $tope && $a[ $i ] === $b[ $i ] ) {
		++$i;
	}
	return $i;
}

/**
 * ¿Alguna otra clave del mismo reclamante comparte prefijo con ésta?
 *
 * Tres caracteres bastan y sobran: `jd_` es un espacio de nombres real (once claves de
 * wp-to-twitter) y con cuatro se escapaba. Lo que sostiene la reclamación no es la longitud
 * del prefijo, sino que exista al menos una hermana: una clave suelta fuera del nombre del
 * plugin es una casualidad; dos que se parecen son un espacio de nombres.
 *
 * @param string             $clave    Clave reclamada.
 * @param array<int,string>  $hermanas Las demás claves del mismo slug.
 * @return bool
 */
function wpoptimizing_claim_is_corroborated( $clave, array $hermanas ) {
	$clave = strtolower( (string) $clave );
	foreach ( $hermanas as $otra ) {
		$otra = strtolower( (string) $otra );
		if ( $otra === $clave ) {
			continue;
		}
		if ( wpoptimizing_claim_common_prefix( $clave, $otra ) >= 3 ) {
			return true;
		}
	}
	return false;
}
