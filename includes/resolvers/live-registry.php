<?php
/**
 * Owners read from what WordPress has registered right now: the class behind a widget, the
 * callback behind a shortcode, and the file each one is defined in.
 *
 * A widget keeps its settings in "widget_{$id_base}" and that name is built by WP_Widget, never
 * written in the plugin's code; a shortcode's tag often comes from a constant. So the scan does
 * not find them, and on wpoptimizing.com ten of Avada's widget options and the site's own
 * `wpo-capturas` shortcode sat in Pending while the code that registers them was running
 * (27/10/2026). The file a class or a function is declared in is a fact, not a guess.
 *
 * Only for what is registered now: an orphan, by definition, is not.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * The extension a file belongs to, from its path. Pure but for the directory constants.
 *
 * @param string $file Absolute path.
 * @return array{origin:string,slug:string}|null
 */
function wpoptimizing_owner_from_file( $file ) {
	$file = wp_normalize_path( (string) $file );
	if ( '' === $file ) {
		return null;
	}
	$raices = array(
		'plugin' => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
		'mu'     => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
		'theme'  => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
	);
	foreach ( $raices as $clase => $raiz ) {
		$raiz = '' !== $raiz ? trailingslashit( wp_normalize_path( $raiz ) ) : '';
		if ( '' === $raiz || 0 !== strpos( $file, $raiz ) ) {
			continue;
		}
		$primero = explode( '/', substr( $file, strlen( $raiz ) ) )[0];
		if ( 'mu' === $clase ) {
			$primero = preg_replace( '/\.php$/i', '', $primero );
		}
		$slug = sanitize_key( $primero );
		return '' === $slug ? null : array(
			'origin' => 'theme' === $clase ? 'theme' : 'plugin',
			'slug'   => $slug,
		);
	}
	return null;
}

/**
 * The file a callable is declared in, or ''.
 *
 * @param mixed $callback Callable.
 * @return string
 */
function wpoptimizing_callable_file( $callback ) {
	try {
		if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
			$callback = explode( '::', $callback, 2 );
		}
		if ( is_array( $callback ) && 2 === count( $callback ) ) {
			$r = new ReflectionMethod( is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0], (string) $callback[1] );
		} elseif ( is_object( $callback ) && ! ( $callback instanceof Closure ) ) {
			$r = new ReflectionMethod( $callback, '__invoke' );
		} else {
			$r = new ReflectionFunction( $callback );
		}
		return (string) $r->getFileName();
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Owner of an option or shortcode from WordPress's live registries, or null.
 *
 * @param string $tipo  Map bucket: `option` or `shortcode`.
 * @param string $value Key, lowercase.
 * @return array{origin:string,slug:string}|null
 */
function wpoptimizing_live_registry_owner( $tipo, $value ) {
	static $widgets = null;
	$value = strtolower( (string) $value );
	if ( 'option' === $tipo && 0 === strpos( $value, 'widget_' ) ) {
		if ( null === $widgets ) {
			$widgets = array();
			global $wp_widget_factory;
			foreach ( (array) ( $wp_widget_factory->widgets ?? array() ) as $w ) {
				if ( is_object( $w ) && ! empty( $w->option_name ) ) {
					try {
						$widgets[ strtolower( (string) $w->option_name ) ] = (string) ( new ReflectionClass( $w ) )->getFileName();
					} catch ( Throwable $e ) {
						continue;
					}
				}
			}
		}
		return isset( $widgets[ $value ] ) ? wpoptimizing_owner_from_file( $widgets[ $value ] ) : null;
	}
	if ( 'shortcode' === $tipo ) {
		global $shortcode_tags;
		foreach ( (array) $shortcode_tags as $tag => $callback ) {
			if ( strtolower( (string) $tag ) === $value ) {
				return wpoptimizing_owner_from_file( wpoptimizing_callable_file( $callback ) );
			}
		}
	}
	return null;
}
