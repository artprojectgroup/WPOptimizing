<?php
/**
 * Interface icons: WordPress's SVG icon library instead of the Dashicons font.
 *
 * WordPress 7.2 moved its admin menu and toolbar to SVG icons (@wordpress/icons). The plugin
 * ships its own copy of the few it uses, in assets/icons/, so the screens look the same on any
 * WordPress version and never depend on a core file that may move between releases. Those
 * copies are refreshed from a local WordPress by tools/sync-icons.py, which reads the table
 * below — this function is the ONLY place the correspondence lives.
 *
 * Loaded from includes/admin/chrome.php, so it is present wherever an admin screen renders,
 * in Lite and in Pro alike.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashicon name (without the `dashicons-` prefix, as the code passes it around) → name of the
 * SVG in WordPress's icon library (assets/icons/<name>.svg).
 *
 * Where the library manifest declares an official replacement (its `dashicons` key) that one is
 * used; the rest were chosen by meaning. One official replacement, asterisk, is not in the
 * 331-icon library this copy is synced from, so marker takes the nearest drawing there: drafts,
 * a ring with a dot, as the Dashicon.
 *
 * @return array<string,string>
 */
function wpoptimizing_icon_map() {
	return array(
		// Official replacements (icon-library manifest, key `dashicons`).
		'admin-home'       => 'home',
		'admin-links'      => 'link',
		'admin-media'      => 'media',
		'admin-network'    => 'key',
		'admin-page'       => 'page',
		'admin-plugins'    => 'plugins',
		'admin-settings'   => 'settings',
		'admin-tools'      => 'tool',
		'admin-users'      => 'people',
		'arrow-left-alt2'  => 'chevron-left',
		'calendar-alt'     => 'calendar',
		'chart-bar'        => 'chart-bar',
		'controls-forward' => 'next',
		'dashboard'        => 'dashboard',
		'edit'             => 'pencil',
		'editor-help'      => 'help',
		'editor-table'     => 'table',
		'format-image'     => 'image',
		'info-outline'     => 'info',
		'lightbulb'        => 'tip',
		'marker'           => 'asterisk',
		'menu'             => 'menu',
		'rss'              => 'rss',
		'search'           => 'search',
		'share'            => 'share',
		'shield'           => 'shield',
		'translation'      => 'language',
		'update'           => 'update',
		'warning'          => 'caution',
		'yes'              => 'check',
		'yes-alt'          => 'published',
		// No official replacement: chosen by meaning.
		'admin-customizer' => 'styles',
		'admin-generic'    => 'cog',
		'admin-site-alt3'  => 'globe',
		'art'              => 'swatch',
		'backup'           => 'backup',
		'chart-area'       => 'trending-up',
		'chart-line'       => 'trending-up',
		'chart-pie'        => 'percent',
		'clipboard'        => 'copy',
		'clock'            => 'time',
		'cloud'            => 'cloud',
		'controls-play'    => 'play',
		'database'         => 'database',
		'database-view'    => 'table',
		'editor-code'      => 'code',
		'editor-ul'        => 'format-list-bullets',
		'editor-unlink'    => 'link-off',
		'filter'           => 'filter',
		'flag'             => 'tag', // "To identify": items still without an owner label.
		'forms'            => 'settings', // Cache settings: the sliders of a settings form.
		'heart'            => 'loop', // "Are the tasks running?": recurring work, whatever its state.
		'hidden'           => 'unseen',
		'hourglass'        => 'pending',
		'image-rotate'     => 'rotate-right',
		'images-alt'       => 'gallery',
		'images-alt2'      => 'gallery',
		'list-view'        => 'list-view',
		'lock'             => 'lock',
		'media-archive'    => 'archive', // Files kept in named folders: the library's folder.
		'media-code'       => 'block-default', // Smart Assets: the pieces a page loads (a cube reads better than the word «HTML»).
		'media-default'    => 'file',
		'media-document'   => 'page', // Cleanup report: a document.
		'media-text'       => 'page',
		'migrate'          => 'move-to',
		'networking'       => 'globe',
		'no-alt'           => 'close-small',
		'performance'      => 'dashboard',
		'rest-api'         => 'connection',
		'text-page'        => 'post-content', // Cleanup logs: lines of text.
		'trash'            => 'trash',
		'undo'             => 'undo',
		'visibility'       => 'seen',
		'x'                => 'close',
	);
}

/**
 * Elements and attributes an interface icon may carry, for wp_kses(). Shared by the icon
 * loader and by the markup that prints icons inside other controls. Pure.
 *
 * @return array<string,array<string,bool>>
 */
function wpoptimizing_icon_allowed_tags() {
	return array(
		'svg'     => array(
			'xmlns'       => true,
			'viewbox'     => true,
			'width'       => true,
			'height'      => true,
			'class'       => true,
			'aria-hidden' => true,
			'focusable'   => true,
			'role'        => true,
		),
		// The 7.2 set is drawn with strokes: the lines keep their own stroke and fill
		// attributes, and circles are part of it (they lost them before, and the icon with it).
		'path'    => array(
			'd'               => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'polygon' => array(
			'points'          => true,
			'fill-rule'       => true,
			'clip-rule'       => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
		'circle'  => array(
			'cx'              => true,
			'cy'              => true,
			'r'               => true,
			'fill'            => true,
			'stroke'          => true,
			'stroke-width'    => true,
			'stroke-linecap'  => true,
			'stroke-linejoin' => true,
		),
	);
}

/**
 * The inner markup of one library SVG, sanitised, read once per request.
 *
 * @param string $file Library name (assets/icons/<file>.svg).
 * @return array{0:string,1:string}|null [viewBox, inner markup], or null if the file is missing.
 */
function wpoptimizing_icon_svg( $file ) {
	static $cache = array();
	if ( array_key_exists( $file, $cache ) ) {
		return $cache[ $file ];
	}
	$cache[ $file ] = null;
	if ( ! preg_match( '/^[a-z0-9-]+$/', $file ) ) {
		return null;
	}
	$path = dirname( __DIR__ ) . '/assets/icons/' . $file . '.svg';
	$svg  = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file of our own.
	$deco = wpoptimizing_icon_presentation( $svg );
	/*
	 * The file is ours, but it is copied from another project: wp_kses with the few elements and
	 * attributes a library icon is drawn with keeps anything else (a script, an event handler, an
	 * external reference) from ever reaching an admin screen should a future copy carry it.
	 *
	 * The WHOLE file goes through, <svg> included: since WordPress 7.2 kses parses with the HTML
	 * API, and a <path /> outside its <svg> is read as HTML, loses its self-closing slash and
	 * swallows the next path as a child — which is then not drawn.
	 */
	$svg = wp_kses( $svg, wpoptimizing_icon_allowed_tags() );
	if ( ! preg_match( '#<svg\b([^>]*)>(.*)</svg>#is', $svg, $m ) ) {
		return null;
	}
	$view_box = preg_match( '/viewBox="([0-9.\s-]+)"/i', $m[1], $vb ) ? trim( $vb[1] ) : '0 0 24 24';
	$inner    = trim( $m[2] );
	if ( '' === trim( $inner ) ) {
		return null;
	}
	$cache[ $file ] = array( $view_box, $inner, $deco );
	return $cache[ $file ];
}

/**
 * How the outer <svg> of a library icon paints: the 7.2 set says it there once for every line
 * («style="fill: none" stroke="currentColor" stroke-width="1.5"»), and without it the lines are
 * filled black instead of drawn. Only these three, with safe values, become attributes. Pure.
 *
 * @param string $svg The file's markup.
 * @return string Attributes, each with a leading space ('' for a filled icon).
 */
function wpoptimizing_icon_presentation( $svg ) {
	if ( ! preg_match( '#<svg\b([^>]*)>#i', (string) $svg, $m ) ) {
		return '';
	}
	$open = $m[1];
	$out  = '';
	if ( preg_match( '/style="[^"]*fill:\s*none/i', $open ) || preg_match( '/\sfill="none"/i', $open ) ) {
		$out .= ' fill="none"';
	}
	if ( preg_match( '/\sstroke="(currentColor)"/', $open, $v ) ) {
		$out .= ' stroke="' . $v[1] . '"';
	}
	if ( preg_match( '/\sstroke-width="([0-9.]{1,4})"/', $open, $v ) ) {
		$out .= ' stroke-width="' . $v[1] . '"';
	}
	return $out;
}

/**
 * A decorative interface icon: the library SVG in line, or the Dashicon it replaces.
 *
 * 20×20 by default (a Dashicon's box); the size, colour (fill: currentColor) and alignment come
 * from the `.wpopt-icon` rules of assets/css/admin.css. When the name has no SVG — not in the
 * map, or the file is missing — it falls back to the Dashicon span of old, so an
 * icon can go missing but never break the screen.
 *
 * @param string $name  Dashicon name without the prefix ('database', 'trash'…).
 * @param string $class Extra classes.
 * @return string Escaped HTML.
 */
function wpoptimizing_icon( $name, $class = '' ) {
	$name  = (string) $name;
	$class = trim( (string) $class );
	$map   = wpoptimizing_icon_map();
	$svg   = isset( $map[ $name ] ) ? wpoptimizing_icon_svg( $map[ $name ] ) : null;
	if ( null === $svg ) {
		return '<span class="' . esc_attr( trim( 'dashicons dashicons-' . $name . ' ' . $class ) ) . '" aria-hidden="true"></span>';
	}
	return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . esc_attr( $svg[0] ) . '" width="20" height="20"' . ( $svg[2] ?? '' )
		. ' class="' . esc_attr( trim( 'wpopt-icon ' . $class ) ) . '" aria-hidden="true" focusable="false">'
		. $svg[1] // Sanitised by wp_kses in wpoptimizing_icon_svg().
		. '</svg>';
}
