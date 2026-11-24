<?php
/**
 * Cuánto se sabe de una atribución: el vocabulario, en UN solo sitio.
 *
 * Había SEIS listas del mismo concepto y ninguna sabía de las otras: la insignia de la
 * columna, la leyenda de la barra de búsqueda, la leyenda del asistente de bienvenida, las
 * etiquetas del sugeridor de dueño, la puerta de borrado y la tabla de orden que decide cuál
 * de dos pruebas manda. Dos de ellas deciden qué se puede borrar en bloque.
 *
 * Y ya habían divergido, de tres maneras distintas:
 *
 *   · DOS EXPLICACIONES DE LA MISMA INSIGNIA. La leyenda decía que «Possible» es «lo encontró
 *     el escáner estático de código» y el asistente que es «el conjunto de la comunidad con
 *     varias confirmaciones». Son dos cosas distintas y el usuario lee una u otra según la
 *     pantalla en la que esté.
 *   · UN NIVEL QUE FALTABA. El asistente no lista «Manual», que es la máxima autoridad: una
 *     regla puesta a mano gana a todo lo automático. Quien aprende el sistema por el
 *     asistente no sabe que existe.
 *   · UN ORDEN QUE PONÍA LO MÁS FIABLE ABAJO. La tabla de `best_confianza_from_det()` tenía
 *     cinco niveles de los siete, así que `manual` y `none` caían al `?? 0` y puntuaban lo
 *     MÍNIMO: entre una aserción humana y una coincidencia del conjunto compartido, ganaba
 *     la segunda.
 *
 * Añadir o cambiar un nivel aquí es el ÚNICO paso: la insignia, las dos leyendas, el orden y
 * la puerta de borrado salen de esta lista, y ConfidenceLevelsTest cruza además lo que
 * prometen el asistente y el readme con lo que la puerta deja pasar de verdad.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Todos los niveles, de más a menos, con lo que significan y si dejan borrar.
 *
 * `rank` ordena dos pruebas de la misma clave: gana el número más alto. `deletable` es la
 * puerta: un huérfano cuya confianza no la tenga NO se ofrece para borrar, se manda a
 * Pendientes para que lo mire una persona. Esos dos datos estaban en tablas separadas de la
 * lista de etiquetas, que es como se quedaron cortas las dos.
 *
 * @return array<string,array{rank:int,deletable:bool}>
 */
function wpoptimizing_confidence_levels() {
	return array(
		// Una persona lo afirmó: manda sobre cualquier prueba automática, y por eso va arriba.
		'manual'    => array(
			'rank'      => 6,
			'deletable' => true,
		),
		'certain'   => array(
			'rank'      => 5,
			'deletable' => true,
		),
		'strong'    => array(
			'rank'      => 4,
			'deletable' => true,
		),
		'medium'    => array(
			'rank'      => 3,
			'deletable' => true,
		),
		'weak'      => array(
			'rank'      => 2,
			'deletable' => false,
		),
		'heuristic' => array(
			'rank'      => 1,
			'deletable' => false,
		),
		'none'      => array(
			'rank'      => 0,
			'deletable' => false,
		),
	);
}

/**
 * ¿Existe este nivel?
 *
 * @param string $level Nivel.
 * @return bool
 */
function wpoptimizing_confidence_exists( $level ) {
	return isset( wpoptimizing_confidence_levels()[ strtolower( trim( (string) $level ) ) ] );
}

/**
 * El nivel normalizado, o 'none' cuando no se reconoce.
 *
 * @param string $level Nivel crudo.
 * @return string
 */
function wpoptimizing_confidence_normalize( $level ) {
	$level = strtolower( trim( (string) $level ) );
	return wpoptimizing_confidence_exists( $level ) ? $level : 'none';
}

/**
 * La etiqueta de un nivel, en TEXTO PLANO: la escapa quien la pinta.
 *
 * Se conservan los mismos msgid que ya existían para no tirar las traducciones de los ocho
 * idiomas por mover el código de sitio.
 *
 * @param string $level Nivel.
 * @return string
 */
function wpoptimizing_confidence_label( $level ) {
	$etiquetas = array(
		'manual'    => __( 'Manual', 'wpoptimizing' ),
		'certain'   => __( 'Sure', 'wpoptimizing' ),
		'strong'    => __( 'Likely', 'wpoptimizing' ),
		'medium'    => __( 'Possible', 'wpoptimizing' ),
		'weak'      => __( 'Doubtful', 'wpoptimizing' ),
		'heuristic' => __( 'Estimated', 'wpoptimizing' ),
		'none'      => __( 'Undetermined', 'wpoptimizing' ),
	);
	return $etiquetas[ wpoptimizing_confidence_normalize( $level ) ];
}

/**
 * Qué significa un nivel y qué hacer con él: UNA sola frase para las tres pantallas.
 *
 * `certain` ya no promete «atribución al 100 %». Lo que se capta en vivo es qué código estaba
 * ejecutándose cuando la clave apareció, y eso no es lo mismo que de quién es: un plugin que
 * trae una librería dentro crea sus opciones, y uno de copias de seguridad crea las de todos
 * al restaurar. Desde el 2026-10-08 el nivel exige además que la clave esté en el espacio de
 * nombres del reclamante (resolvers/claim-support.php), y la frase dice eso y no más.
 *
 * @param string $level Nivel.
 * @return string
 */
function wpoptimizing_confidence_description( $level ) {
	$frases = array(
		'manual'    => __( 'Manual: you attributed this item by hand. A human assertion outranks every automatic one.', 'wpoptimizing' ),
		'certain'   => __( 'Sure: captured live on this site at the moment the item was registered, by a plugin whose own namespace the item belongs to.', 'wpoptimizing' ),
		'strong'    => __( 'Likely: recorded by comparing what your site registered before and after activating the extension.', 'wpoptimizing' ),
		'medium'    => __( 'Possible: found by reading the extension\'s own code, or confirmed by several sites in the shared dataset. Not verified while running.', 'wpoptimizing' ),
		'weak'      => __( 'Doubtful: one claim only, and the item does not belong to the claimant\'s namespace — or few sites have reported it. Review before deleting.', 'wpoptimizing' ),
		'heuristic' => __( 'Estimated: inferred from a prefix or slug pattern. An educated guess, not evidence.', 'wpoptimizing' ),
		'none'      => __( 'Undetermined: no owner found. Never delete without reviewing it yourself.', 'wpoptimizing' ),
	);
	return $frases[ wpoptimizing_confidence_normalize( $level ) ];
}

/**
 * Orden de un nivel: gana el más alto cuando dos pruebas hablan de la misma clave.
 *
 * @param string $level Nivel.
 * @return int
 */
function wpoptimizing_confidence_rank( $level ) {
	return (int) wpoptimizing_confidence_levels()[ wpoptimizing_confidence_normalize( $level ) ]['rank'];
}

/**
 * ¿Deja este nivel que un huérfano se ofrezca para borrar?
 *
 * @param string $level Nivel.
 * @return bool
 */
function wpoptimizing_confidence_allows_deletion( $level ) {
	return (bool) wpoptimizing_confidence_levels()[ wpoptimizing_confidence_normalize( $level ) ]['deletable'];
}

/**
 * Los niveles que dejan borrar, de más a menos.
 *
 * @return array<int,string>
 */
function wpoptimizing_confidence_deletable_levels() {
	$fuera = array();
	foreach ( wpoptimizing_confidence_levels() as $nivel => $datos ) {
		if ( $datos['deletable'] ) {
			$fuera[] = $nivel;
		}
	}
	return $fuera;
}

/**
 * El mejor nivel de una lista. Con la lista vacía devuelve 'none', que es la verdad.
 *
 * @param array<int,string> $levels Niveles.
 * @return string
 */
function wpoptimizing_confidence_best( array $levels ) {
	$mejor = '';
	foreach ( $levels as $nivel ) {
		$nivel = wpoptimizing_confidence_normalize( $nivel );
		if ( '' === $mejor || wpoptimizing_confidence_rank( $nivel ) > wpoptimizing_confidence_rank( $mejor ) ) {
			$mejor = $nivel;
		}
	}
	return '' === $mejor ? 'none' : $mejor;
}
