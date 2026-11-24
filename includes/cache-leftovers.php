<?php
/**
 * Restos de otras cachés de página: lo que un plugin ya desactivado sigue haciendo.
 *
 * POR QUÉ EXISTE. Desactivar un plugin de caché no siempre se lleva lo que puso fuera de su
 * carpeta, y lo que queda sigue actuando:
 *
 *  - un bloque de .htaccess que reescribe la petición a un HTML estático (WP Super Cache en
 *    modo experto, W3TC con disco mejorado, WP Fastest Cache, WP Rocket…): Apache sirve esa
 *    página vieja ANTES de llegar a PHP, así que ninguna purga nuestra la alcanza;
 *  - un advanced-cache.php cuyo plugin ya no está activo: WordPress lo sigue cargando en cada
 *    petición mientras WP_CACHE sea verdadero;
 *  - un define( 'WP_CACHE', … ) suelto en wp-config.php fuera de nuestro bloque: con nuestra
 *    caché de página puesta, PHP avisa de constante ya definida en cada petición.
 *
 * Sólo cuenta como resto lo de un plugin INACTIVO: si está activo, es su caché funcionando y
 * de eso se ocupa la convivencia (wpoptimizing_page_cache_coexistence()).
 *
 * Todo lo que cambia lleva copia previa en la pestaña Copias de seguridad, y sin copia no se
 * toca nada. Viaja en Lite: es convivencia, no la caché de página.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Los bloques de .htaccess que SIRVEN PÁGINAS, sacados del registro de compatibilidad (la clave
 * `htaccess` de cada entrada): los competidores se declaran en un solo sitio.
 *
 * Sólo los que reescriben a un HTML en disco: los de caché del navegador o compresión de otros
 * plugins no sirven contenido viejo y aquí no se tocan. Un mismo marcador puede ser de dos
 * entradas (la versión gratuita y la de pago): es resto sólo si no está activa NINGUNA.
 *
 * @return array<int,array{marker:string,prefix:bool,label:string,active:bool}>
 */
function wpoptimizing_cache_leftover_catalog() {
	$out = array();
	if ( ! function_exists( 'wpoptimizing_compat_registry' ) ) {
		return $out;
	}
	foreach ( wpoptimizing_compat_registry() as $def ) {
		$active = function_exists( 'wpoptimizing_compat_entry_active' ) && wpoptimizing_compat_entry_active( $def );
		foreach ( (array) ( $def['htaccess'] ?? array() ) as $marker ) {
			$prefix = '*' === substr( (string) $marker, -1 );
			$marker = rtrim( (string) $marker, '*' );
			if ( isset( $out[ $marker ] ) ) {
				$out[ $marker ]['active'] = $out[ $marker ]['active'] || $active;
				continue;
			}
			$out[ $marker ] = array(
				'marker' => $marker,
				'prefix' => $prefix,
				'label'  => (string) ( $def['label'] ?? $marker ),
				'active' => $active,
			);
		}
	}
	return array_values( $out );
}

/**
 * Los bloques de un .htaccess que son restos: su marcador está y su plugin no está activo.
 * Pura.
 *
 * Un bloque cuenta sólo con su «# END» correspondiente detrás: sin él no se sabe dónde acaba,
 * y entonces ni se informa ni se quita.
 *
 * @param string $contents Contenido del .htaccess.
 * @param array  $catalog  wpoptimizing_cache_leftover_catalog().
 * @return array<int,array{begin:string,end:string,label:string}>
 */
function wpoptimizing_cache_leftover_blocks( $contents, array $catalog ) {
	$lines = preg_split( '/\r\n|\r|\n/', (string) $contents );
	$found = array();
	foreach ( $catalog as $def ) {
		if ( ! empty( $def['active'] ) ) {
			continue;
		}
		foreach ( $lines as $i => $line ) {
			$line = trim( $line );
			if ( 0 !== strpos( $line, '# BEGIN ' ) ) {
				continue;
			}
			$name = trim( substr( $line, 8 ) );
			// A version after the name counts as the same marker: WP Rocket writes
			// «# BEGIN WP Rocket v3.9.2» and closes with a bare «# END WP Rocket» (seen on
			// Mundotoro, 07/11/2026, where the block was missed).
			$versioned = 0 === strpos( $name, $def['marker'] . ' v' ) && 1 === preg_match( '/^v[0-9][0-9.]*$/', substr( $name, strlen( $def['marker'] ) + 1 ) );
			if ( $name !== $def['marker'] && ! $versioned && ! ( $def['prefix'] && 0 === strpos( $name, $def['marker'] ) ) ) {
				continue;
			}
			for ( $j = $i + 1, $n = count( $lines ); $j < $n; $j++ ) {
				$end = trim( $lines[ $j ] );
				if ( '# END ' . $name === $end || ( $versioned && '# END ' . $def['marker'] === $end ) ) {
					$found[] = array(
						'begin' => $line,
						'end'   => $end,
						'label' => $def['label'],
					);
					break;
				}
			}
		}
	}
	return $found;
}

/**
 * El .htaccess sin esos bloques, marcadores incluidos. Pura. Respeta el salto de línea del
 * fichero y no toca nada fuera de cada BEGIN…END.
 *
 * @param string                                          $contents Contenido.
 * @param array<int,array{begin:string,end:string}>        $blocks   Bloques de wpoptimizing_cache_leftover_blocks().
 * @return string
 */
function wpoptimizing_cache_leftover_strip( $contents, array $blocks ) {
	$contents = (string) $contents;
	$eol      = false !== strpos( $contents, "\r\n" ) ? "\r\n" : "\n";
	$lines    = preg_split( '/\r\n|\r|\n/', $contents );
	foreach ( $blocks as $b ) {
		$out  = array();
		$skip = false;
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( ! $skip && $t === $b['begin'] ) {
				$skip = true;
				continue;
			}
			if ( $skip ) {
				if ( $t === $b['end'] ) {
					$skip = false;
				}
				continue;
			}
			$out[] = $line;
		}
		// Un bloque sin cierre se queda como estaba: sin saber dónde acaba no se borra nada.
		$lines = $skip ? $lines : $out;
	}
	return (string) preg_replace( '/(' . preg_quote( $eol, '/' ) . '){3,}/', $eol . $eol, implode( $eol, $lines ) );
}

/**
 * Las líneas de wp-config.php que definen WP_CACHE fuera de nuestros bloques gestionados. Pura.
 *
 * Sólo una sentencia sola en su línea (con un comentario detrás, como mucho): una línea que
 * hace más cosas no se reconoce y no se toca.
 *
 * @param string $contents Contenido de wp-config.php.
 * @return array<int,int> Índices de línea (desde 0).
 */
function wpoptimizing_cache_leftover_wp_cache_lines( $contents ) {
	$lines   = preg_split( '/\r\n|\r|\n/', (string) $contents );
	$out     = array();
	$managed = false;
	foreach ( $lines as $i => $line ) {
		if ( false !== strpos( $line, 'BEGIN WPOptimizing' ) ) {
			$managed = true;
			continue;
		}
		if ( false !== strpos( $line, 'END WPOptimizing' ) ) {
			$managed = false;
			continue;
		}
		if ( ! $managed && preg_match( '/^\s*define\s*\(\s*([\'"])WP_CACHE\1\s*,[^;]*\)\s*;\s*(\/\/.*|#.*|\/\*.*\*\/\s*)?$/i', $line ) ) {
			$out[] = $i;
		}
	}
	return $out;
}

/**
 * wp-config.php con esas líneas protegidas: `define( … );` pasa a `if ( ! defined( 'WP_CACHE' ) )
 * { define( … ); }`. Pura.
 *
 * PROTEGIDAS, NO COMENTADAS. Comentarlas rompía un `if ( ! defined( 'WP_CACHE' ) )` sin llaves
 * de la línea de antes: su cuerpo pasaba a ser la sentencia siguiente, el require de
 * wp-settings.php, y WordPress dejaba de cargar (auditoría de Codex, 06/11/2026). Así la línea
 * sigue siendo una sentencia, hace lo mismo cuando nadie más la ha definido y no avisa cuando
 * nuestro bloque ya lo hizo. El valor del otro plugin se conserva tal cual.
 *
 * CON LLAVES, NO CON `||`. La primera versión escribía `defined( 'WP_CACHE' ) || define( … );`, y
 * LiteSpeed Cache, al desactivarse, quita de wp-config.php todo `define( 'WP_CACHE', … );` que
 * encuentra: quedaba `defined( 'WP_CACHE' ) ||` colgando, unido a la sentencia siguiente (el
 * define de DB_NAME), que dejaba de ejecutarse en cuanto nuestro bloque definía WP_CACHE
 * (multisitio-limpio.local, 10/11/2026). Con llaves, lo que queda es un `if` vacío, inofensivo.
 * Las líneas protegidas con la versión vieja las arregla wpoptimizing_cache_leftover_repair_dangling().
 *
 * @param string $contents Contenido.
 * @return string
 */
function wpoptimizing_cache_leftover_neutralize_wp_cache( $contents ) {
	$contents = (string) $contents;
	$eol      = false !== strpos( $contents, "\r\n" ) ? "\r\n" : "\n";
	$lines    = preg_split( '/\r\n|\r|\n/', $contents );
	foreach ( wpoptimizing_cache_leftover_wp_cache_lines( $contents ) as $i ) {
		$lines[ $i ] = (string) preg_replace( '/^(\s*)(define\s*\([^;]*\)\s*;)/i', "$1if ( ! defined( 'WP_CACHE' ) ) { $2 }", $lines[ $i ], 1 );
	}
	return implode( $eol, $lines );
}

/**
 * wp-config.php sin los `defined( 'WP_CACHE' ) ||` que se han quedado colgando. Pura.
 *
 * Los deja la protección vieja (`defined( 'WP_CACHE' ) || define( … );`) cuando otro plugin
 * borra el define de detrás, como hace LiteSpeed Cache al desactivarse: el `||` se une a la
 * sentencia siguiente y la salta en cuanto WP_CACHE está definida. Sólo se quita la línea que no
 * tiene nada más que eso (y, como mucho, un comentario).
 *
 * @param string $contents Contenido.
 * @return string
 */
function wpoptimizing_cache_leftover_repair_dangling( $contents ) {
	return (string) preg_replace( '/^[ \t]*defined\s*\(\s*([\'"])WP_CACHE\1\s*\)\s*\|\|[ \t]*(?:\/\/[^\r\n]*|#[^\r\n]*)?(\r\n|\r|\n|$)/m', '', (string) $contents );
}

/**
 * Arreglar en wp-config.php los `defined( 'WP_CACHE' ) ||` colgando, con copia previa.
 *
 * @return bool True cuando no queda ninguno (o no había).
 */
function wpoptimizing_cache_leftovers_repair_wp_cache() {
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/wpconfig-block.php' );
	}
	if ( ! function_exists( 'wpoptimizing_wpconfig_path' ) ) {
		return false;
	}
	$path = wpoptimizing_wpconfig_path();
	if ( '' === $path || ! is_readable( $path ) ) {
		return false;
	}
	$old = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$new = wpoptimizing_cache_leftover_repair_dangling( $old );
	if ( $new === $old ) {
		return true;
	}
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	if ( ! wp_is_writable( $path ) || ! function_exists( 'wpoptimizing_backup_file_first' ) || ! wpoptimizing_backup_file_first( $path, 'wpconfig' ) ) {
		return false;
	}
	return wpoptimizing_cache_leftover_write( $path, $new );
}

/**
 * Lo que hay ahora en el sitio.
 *
 * @return array{htaccess:array,dropin:array|null,wp_cache:int}
 *   dropin: {name,plugin} del advanced-cache.php de un plugin inactivo, o null.
 *   wp_cache: líneas WP_CACHE ajenas, sólo contadas cuando nuestro bloque de caché de página
 *   está puesto (sin él, la de otro es la única y no molesta).
 */
function wpoptimizing_cache_leftovers() {
	$out = array(
		'htaccess' => array(),
		'dropin'   => null,
		'wp_cache' => 0,
	);
	$ht  = function_exists( 'get_home_path' ) ? get_home_path() . '.htaccess' : ABSPATH . '.htaccess';
	if ( is_readable( $ht ) ) {
		$out['htaccess'] = wpoptimizing_cache_leftover_blocks( (string) file_get_contents( $ht ), wpoptimizing_cache_leftover_catalog() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	}

	$adv = WP_CONTENT_DIR . '/advanced-cache.php';
	if ( file_exists( $adv ) && function_exists( 'wpoptimizing_dropin_owner' ) ) {
		$ours = function_exists( 'wpoptimizing_page_cache_is_ours' ) ? wpoptimizing_page_cache_is_ours( $adv ) : ( false !== strpos( (string) file_get_contents( $adv, false, null, 0, 4096 ), 'WPOPT_PAGE_CACHE' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		if ( ! $ours ) {
			$owner = wpoptimizing_dropin_owner( $adv );
			if ( false === $owner['active'] ) {
				$out['dropin'] = array(
					'name'   => (string) $owner['name'],
					'plugin' => (string) $owner['plugin'],
				);
			}
		}
	}

	if ( function_exists( 'wpoptimizing_wpconfig_path' ) && function_exists( 'wpoptimizing_wpconfig_has_block' ) ) {
		$path = wpoptimizing_wpconfig_path();
		if ( '' !== $path && is_readable( $path ) ) {
			$cfg = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
			if ( wpoptimizing_wpconfig_has_block( $cfg, 'Page-Cache' ) ) {
				$out['wp_cache'] = count( wpoptimizing_cache_leftover_wp_cache_lines( $cfg ) );
			}
		}
	}
	return $out;
}

/**
 * ¿Hay algo que limpiar?
 *
 * @param array $state wpoptimizing_cache_leftovers().
 * @return bool
 */
function wpoptimizing_cache_leftovers_any( array $state ) {
	return ! empty( $state['htaccess'] ) || ! empty( $state['dropin'] ) || ! empty( $state['wp_cache'] );
}

/**
 * Escribir un fichero de forma atómica conservando sus permisos.
 *
 * @param string $path    Destino.
 * @param string $content Contenido.
 * @return bool
 */
function wpoptimizing_cache_leftover_write( $path, $content ) {
	$mode = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable mode: nothing to keep.
	$tmp  = function_exists( 'wpoptimizing_atomic_tmp_path' ) ? wpoptimizing_atomic_tmp_path( $path ) : $path . '.tmp-' . wp_generate_password( 8, false );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- .htaccess / wp-config.php via a temp file; not a plugin-dir asset.
	if ( false === file_put_contents( $tmp, $content, LOCK_EX ) ) {
		return false;
	}
	if ( false !== $mode ) {
		@chmod( $tmp, $mode & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Keep the original permissions.
	}
	if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic replace.
		wp_delete_file( $tmp );
		return false;
	}
	return true;
}

/**
 * Comentar sólo las WP_CACHE ajenas, con copia previa. Lo llama la instalación de nuestra
 * caché de página, que es la que las vuelve un problema: sin nuestro bloque, la de otro es la
 * única y no molesta.
 *
 * @return bool True cuando no queda ninguna (o no había).
 */
function wpoptimizing_cache_leftovers_fix_wp_cache() {
	if ( ! function_exists( 'wpoptimizing_wpconfig_path' ) ) {
		return false;
	}
	$path = wpoptimizing_wpconfig_path();
	if ( '' === $path || ! is_readable( $path ) ) {
		return false;
	}
	$old = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$new = wpoptimizing_cache_leftover_neutralize_wp_cache( $old );
	if ( $new === $old ) {
		return true;
	}
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	if ( ! wp_is_writable( $path ) || ! function_exists( 'wpoptimizing_backup_file_first' ) || ! wpoptimizing_backup_file_first( $path, 'wpconfig' ) ) {
		return false;
	}
	return wpoptimizing_cache_leftover_write( $path, $new );
}

/**
 * Quitar los restos: los bloques del .htaccess, el advanced-cache.php del plugin inactivo
 * (apartado, se restaura desde Copias de seguridad) y las WP_CACHE ajenas (comentadas).
 *
 * Cada parte con su copia previa; si una copia falla, esa parte no se toca y se dice.
 *
 * @return array{ok:bool,done:string[],failed:string[]}
 */
function wpoptimizing_cache_leftovers_clean() {
	$state  = wpoptimizing_cache_leftovers();
	$done   = array();
	$failed = array();
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
	}
	$backup = static function ( $path, $kind ) {
		return function_exists( 'wpoptimizing_backup_file_first' ) && wpoptimizing_backup_file_first( $path, $kind );
	};

	if ( ! empty( $state['htaccess'] ) ) {
		$ht  = function_exists( 'get_home_path' ) ? get_home_path() . '.htaccess' : ABSPATH . '.htaccess';
		$old = (string) file_get_contents( $ht ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$new = wpoptimizing_cache_leftover_strip( $old, $state['htaccess'] );
		if ( $new !== $old && wp_is_writable( $ht ) && $backup( $ht, 'htaccess' ) && wpoptimizing_cache_leftover_write( $ht, $new ) ) {
			$done[] = 'htaccess';
		} elseif ( $new !== $old ) {
			$failed[] = 'htaccess';
		}
	}

	if ( ! empty( $state['dropin'] ) ) {
		$adv = WP_CONTENT_DIR . '/advanced-cache.php';
		// Sólo la copia de Copias de seguridad, NO wpoptimizing_dropin_backup( 'page' ): ésa es la
		// que nuestra caché de página devuelve a su sitio al desinstalarse, y devolvería el resto.
		if ( $backup( $adv, 'dropin' ) ) {
			wp_delete_file( $adv );
			if ( file_exists( $adv ) ) {
				$failed[] = 'dropin';
			} else {
				$done[] = 'dropin';
			}
		} else {
			$failed[] = 'dropin';
		}
	}

	if ( ! empty( $state['wp_cache'] ) && function_exists( 'wpoptimizing_wpconfig_path' ) ) {
		$path = wpoptimizing_wpconfig_path();
		$old  = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
		$new  = wpoptimizing_cache_leftover_neutralize_wp_cache( $old );
		if ( $new !== $old && wp_is_writable( $path ) && $backup( $path, 'wpconfig' ) && wpoptimizing_cache_leftover_write( $path, $new ) ) {
			$done[] = 'wp_cache';
		} elseif ( $new !== $old ) {
			$failed[] = 'wp_cache';
		}
	}

	if ( $done && function_exists( 'wpoptimizing_audit_log' ) ) {
		wpoptimizing_audit_log( 'cache_leftovers_cleaned', array( 'parts' => $done ) );
	}
	return array(
		'ok'     => empty( $failed ),
		'done'   => $done,
		'failed' => $failed,
	);
}
