<?php
/**
 * Lo que llega en la columna del slug y no es el nombre de una extensión.
 *
 * Vive aparte de attribution-origins.php, que es el vocabulario de «de qué clase es el
 * dueño», por una razón de carga: de los cinco lectores de capas compartidas, dos
 * —autocargadas y activos— se compilan en el camino del visitante, y ese fichero no. Tenerlo
 * aquí evita el `function_exists()` en la guarda, y una guarda condicional es una guarda que
 * algún día no está.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Valores que llegan en la columna del slug y NO son el nombre de una extensión.
 *
 * Son marcas internas del alimentador: `valor-compartido` significa «esta clave la usan
 * varios y no hay un dueño» y `libreria-empaquetada` «esto viene dentro de otra cosa». Como
 * slug son nombres de plugins que no existen, y el cliente los trataba como extensión: en el
 * mapa compartido que este sitio tiene descargado hay 62 filas con `valor-compartido` de
 * dueño, y en la capa de autocargadas una de ellas atribuye `users_can_register` —una opción
 * DEL NÚCLEO— a ese plugin inexistente.
 *
 * El alimentador dejó de emitirlas el 2026-10-07 (mu-plugin 1.4.20) y lo verificó sobre el
 * fichero publicado. Pero el agregado del gateway es ACUMULATIVO: dejar de enviarlas no
 * retira las que ya se fundieron, así que seguirán llegando mientras nadie purgue esas filas.
 * Por eso la guarda va aquí, en el lector, que es donde se decide: un dato que no es un
 * nombre de extensión no se convierte en uno por venir en la columna del slug.
 *
 * @return array<int,string>
 */
function wpoptimizing_non_extension_slugs() {
	return array( 'valor-compartido', 'libreria-empaquetada' );
}

/**
 * El slug que viene de una capa compartida, normalizado, o '' si no nombra una extensión.
 *
 * Todo lector de una capa descargada pasa por aquí: así la marca interna del alimentador no
 * puede colarse como dueño por ninguna de las cuatro puertas (runtime, conjunto compartido,
 * activos, autocargadas). SentinelSlugTest recorre esas cuatro con un centinela dentro.
 *
 * @param string $slug Valor crudo de la columna.
 * @return string
 */
function wpoptimizing_shared_slug( $slug ) {
	$slug = strtolower( trim( (string) $slug ) );
	return in_array( $slug, wpoptimizing_non_extension_slugs(), true ) ? '' : $slug;
}
