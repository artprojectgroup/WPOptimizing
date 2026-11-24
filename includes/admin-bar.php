<?php
/**
 * The WPOptimizing menu in the toolbar: its nodes, and the CSS and JS of the items that run
 * by AJAX. Loaded on every request, because the toolbar shows on the front end too.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add the WPOptimizing dropdown menu to the admin toolbar.
 *
 * The one-click actions, for any user with the plugin capability. 'icon' is the WordPress
 * 7.2 SVG (core-admin only); the empty .ab-icon Dashicon in the title is for older versions,
 * and 7.2 hides it.
 *
 * @param WP_Admin_Bar $bar Toolbar instance.
 * @return void
 */
function wpoptimizing_admin_bar_menu_node( $bar ) {
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		return;
	}
	$dashboard = admin_url( 'admin.php?page=wpoptimizing' );
	$iso       = '' !== wpoptimizing_isotype_data_uri();

	// Parent node: the brand isotype; a stock icon only if that asset is missing.
	$bar->add_node(
		array(
			'id'    => 'wpoptimizing',
			'title' => '<span class="ab-icon wpopt-ab-icon' . ( $iso ? ' wpopt-ab-isotype' : ' dashicons dashicons-performance' ) . '" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'WPOptimizing', 'wpoptimizing' ) . '</span>',
			'href'  => $dashboard,
			'icon'  => $iso ? '' : 'core-admin/dashboard',
			'meta'  => array( 'title' => __( 'WPOptimizing — performance & cleanup', 'wpoptimizing' ) ),
		)
	);

	// Dashicon, core-admin icon, label, link, tooltip. Cleanup and flush are AJAX (see
	// wpoptimizing_admin_bar_assets()). Flush needs our object cache or a detected host/plugin
	// cache; the toolbar renders on the front end too, so the detector is loaded on demand.
	$items = array(
		'analyze' => array( 'search', 'search', __( 'Analyze now', 'wpoptimizing' ), wp_nonce_url( add_query_arg( 'wpopt_run_async', '1', $dashboard ), 'wpoptimizing_run_async', '_wpopt_nonce' ), __( 'Run a full analysis of code, files and database', 'wpoptimizing' ) ),
		'cleanup' => array( 'trash', '', __( 'Run general cleanup', 'wpoptimizing' ), '#', __( 'Run the general database cleanup with your saved settings', 'wpoptimizing' ) ),
	);
	wpoptimizing_load_lib( 'includes/host-cache.php' );
	if ( wpoptimizing_object_cache_active() || ( function_exists( 'wpoptimizing_detect_host_cache' ) && wpoptimizing_detect_host_cache() ) ) {
		$items['flush-cache'] = array( 'update', 'update', __( 'Flush all caches', 'wpoptimizing' ), '#', __( 'Flush the object cache and any detected host/plugin page cache', 'wpoptimizing' ) );
	}
	$items['dashboard'] = array( 'dashboard', 'dashboard', __( 'Go to dashboard', 'wpoptimizing' ), $dashboard, '' );
	foreach ( $items as $id => $it ) {
		$bar->add_node(
			array(
				'parent' => 'wpoptimizing',
				'id'     => 'wpoptimizing-' . $id,
				'title'  => '<span class="ab-icon dashicons dashicons-' . $it[0] . '" aria-hidden="true"></span><span class="wpopt-ab-text">' . esc_html( $it[2] ) . '</span>',
				'icon'   => '' !== $it[1] ? 'core-admin/' . $it[1] : '',
				'href'   => $it[3],
				'meta'   => array( 'title' => $it[4] ), // An empty one is not printed.
			)
		);
	}
}
add_action( 'admin_bar_menu', 'wpoptimizing_admin_bar_menu_node', 100 );


/**
 * Minimal CSS + JS for the toolbar menu, attached to core's own admin-bar style and script, so
 * they load wherever the toolbar does (front end included, where admin.css is not loaded).
 * Styles are scoped to #wpadminbar; the JS wires the AJAX-driven items (cleanup, flush,
 * critical CSS) while plain links (analyze, dashboard) navigate normally.
 *
 * @return void
 */
function wpoptimizing_admin_bar_assets() {
	if ( ! is_admin_bar_showing() || ! current_user_can( wpoptimizing_cap() ) ) {
		return;
	}
	// Internally built (base64 of our own SVG). esc_url() would STRIP it — the data: scheme
	// is not in wp_allowed_protocols by default — so validate the shape instead: a fixed
	// prefix plus base64, which cannot break out of the CSS url() or inject anything.
	$isotype = wpoptimizing_isotype_data_uri();
	if ( '' !== $isotype && ! preg_match( '#^data:image/svg\+xml;base64,[A-Za-z0-9+/=]+$#', $isotype ) ) {
		$isotype = '';
	}
	$top = '#wpadminbar #wp-admin-bar-wpoptimizing';
	$sub = $top . ' .ab-sub-wrapper';
	$css = ''
		// Brand isotype as a CSS mask, tinted with currentColor so it behaves like a native
		// admin-bar icon (white by default, follows the hover/focus colour). Falls back to the
		// dashicon rule below only when the SVG asset is missing.
		. ( '' !== $isotype
			? $top . '>.ab-item .wpopt-ab-isotype{display:inline-block;width:18px;height:18px;top:5px;position:relative;background-color:currentColor;'
				. '-webkit-mask:url(' . $isotype . ') no-repeat center/contain;mask:url(' . $isotype . ') no-repeat center/contain}' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- validated base64 data URI of our own asset.
			: '' )
		. $top . '>.ab-item .wpopt-ab-icon.dashicons{font-family:dashicons!important;top:2px;font-size:18px}'
		// Submenu rows: icon + text centred. !important: WP's mobile toolbar forces .ab-icon to 52x46 / 40px.
		. $sub . ' .ab-item{display:flex;align-items:center;gap:6px}'
		. $sub . ' .ab-icon{flex:0 0 20px;width:20px!important;height:20px!important;margin:0!important;padding:0!important;float:none;top:0;opacity:.85;color:inherit;text-align:center}'
		. $sub . ' .dashicons,' . $sub . ' .dashicons::before{font:18px/20px dashicons!important;width:20px;height:20px}'
		. $sub . ' .svg-icon{display:inline-flex;align-items:center;justify-content:center}'
		. $sub . ' .svg-icon svg{width:20px!important;height:20px!important}'
		. $sub . '{width:max-content}'
		// Mobile: ours stays (WP hides secondary nodes), icon only, submenu anchored right.
		. '@media screen and (max-width:782px){'
		. $top . '{display:block!important;position:relative}'
		. $top . '>.ab-item .ab-label{display:none}'
		. $top . '>.ab-item .wpopt-ab-isotype{width:52px;height:46px;top:0;-webkit-mask-size:28px 28px;mask-size:28px 28px}'
		. $sub . '{left:auto!important;right:0!important}'
		. '}';
	wp_add_inline_style( 'admin-bar', $css );

	// AJAX items: toolbar node id => action, nonce, label, done text and an optional confirm.
	$acts = array(
		'cleanup'     => array( 'wpoptimizing_quick_cleanup', wp_create_nonce( 'wpoptimizing_quick_cleanup' ), __( 'Run general cleanup', 'wpoptimizing' ), __( 'Cleanup done ✓', 'wpoptimizing' ), __( 'Run a general cleanup now with your saved settings?', 'wpoptimizing' ) ),
		'flush-cache' => array( 'wpoptimizing_cache_flush', wp_create_nonce( 'wpoptimizing_cache_flush' ), __( 'Flush all caches', 'wpoptimizing' ), __( 'Cache flushed ✓', 'wpoptimizing' ), '' ),
	);
	$cfg = array(
		'ajax'    => admin_url( 'admin-ajax.php' ),
		'acts'    => $acts,
		'working' => __( 'Working…', 'wpoptimizing' ),
		'fail'    => __( 'Failed', 'wpoptimizing' ),
	);
	$js  = '(function(){var C=' . wp_json_encode( $cfg ) . ';'
		. 'document.addEventListener("click",function(e){'
		. 'var a=e.target.closest("a.ab-item");if(!a||!a.parentNode){return;}'
		. 'var k=String(a.parentNode.id).replace("wp-admin-bar-wpoptimizing-",""),x=Object.prototype.hasOwnProperty.call(C.acts,k)?C.acts[k]:null;'
		. 'if(!x||a.parentNode.id!=="wp-admin-bar-wpoptimizing-"+k){return;}'
		. 'e.preventDefault();if(x[4]&&!window.confirm(x[4])){return;}'
		. 'var lab=a.querySelector(".wpopt-ab-text")||a;lab.textContent=C.working;'
		. 'var b=new URLSearchParams();b.set("action",x[0]);b.set("nonce",x[1]);'
		. 'var end=function(t){lab.textContent=t;setTimeout(function(){lab.textContent=x[2];},2500);};'
		. 'fetch(C.ajax,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:b.toString()})'
		. '.then(function(r){return r.json();}).then(function(r){end(r&&r.success?x[3]:C.fail);})'
		. '.catch(function(){end(C.fail);});'
		. '});}());';
	wp_add_inline_script( 'admin-bar', $js );
}
add_action( 'wp_enqueue_scripts', 'wpoptimizing_admin_bar_assets', 100 );
add_action( 'admin_enqueue_scripts', 'wpoptimizing_admin_bar_assets', 100 );
