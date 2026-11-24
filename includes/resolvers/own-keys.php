<?php
/**
 * Lo que registra WPOptimizing es de WPOptimizing: el escudo propio.
 *
 * Existía el escudo del núcleo —una clave que registra WordPress no puede atribuirse a un
 * plugin, por ruidoso que sea el dato de la multitud— y no existía el equivalente para lo
 * nuestro. Resultado visto en producción: `manage_wpoptimizing`, que es LA CAPACIDAD DE ESTE
 * PLUGIN, aparecía como huérfana de un plugin llamado `booktics`, con confianza SEGURO y por
 * tanto borrable.
 *
 * El mecanismo es el mismo que el de las capacidades en general: el mapa en tiempo de ejecución
 * captura quién se estaba ejecutando cuando apareció la clave, y `add_cap()` lo llama
 * cualquiera. Pero este caso no necesita heurística ninguna: el espacio de nombres es nuestro,
 * lo controlamos nosotros, y nadie más tiene por qué escribir en él.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Los prefijos que ocupa este plugin. Añadir uno aquí es el único paso.
 *
 * `wpopt_` y `wpoptimizing_` cubren opciones, transitorios, crones y metadatos; `manage_wpoptimizing`
 * es la capacidad propia, que no lleva prefijo porque WordPress las nombra por la acción.
 *
 * @return array<int,string>
 */
function wpoptimizing_own_prefixes() {
	return array( 'wpoptimizing_', 'wpopt_', '_transient_wpopt', '_site_transient_wpopt' );
}

/**
 * ¿Es esta clave de WPOptimizing?
 *
 * Deliberadamente NO mira el tipo: una clave de nuestro espacio de nombres es nuestra sea
 * opción, cron, capacidad o metadato. Mirar el tipo sólo añadiría una forma de fallar.
 *
 * @param string $clave Clave o valor del elemento.
 * @return bool
 */
function wpoptimizing_is_own_key( $clave ) {
	$clave = strtolower( trim( (string) $clave ) );
	if ( '' === $clave ) {
		return false;
	}
	if ( 'manage_wpoptimizing' === $clave ) {
		return true;
	}
	foreach ( wpoptimizing_own_prefixes() as $prefijo ) {
		if ( 0 === strpos( $clave, $prefijo ) ) {
			return true;
		}
	}

	return false;
}
