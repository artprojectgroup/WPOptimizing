<?php
/**
 * Core tweaks: opt-in toggles that DISABLE unnecessary WordPress core features to
 * trim front-end weight and HTTP noise. Inspired by DietPress, but scoped so it
 * never duplicates what WPOptimizing already does elsewhere (Heartbeat, revisions/
 * autosave, oEmbed cache, object cache, query dedup and asset unloading live in
 * their own screens) and deliberately excludes security toggles (XML-RPC etc.),
 * which belong to dedicated hardening tools.
 *
 * Every tweak is OFF by default, fully reversible (it only removes core hooks /
 * adds short-circuit filters — no data is deleted) and grouped by risk tier:
 *   - safe:     cosmetic / rarely-used head noise, no visible impact.
 *   - moderate: may affect a few setups (legacy jQuery, embeds, dashicons).
 * All tweaks here trim front-end weight or head noise — pure optimization. (Functional,
 * non-optimization toggles such as disabling feeds/sitemap or silencing WordPress emails
 * were intentionally removed to keep the plugin focused on WPOptimizing features.)
 *
 * Loaded unconditionally on every request; each callback is cheap and only the
 * enabled tweaks register any hooks.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which tweaks exist, and the risk tier of each. NO TRANSLATED STRINGS.
 *
 * This is the list the ENGINE needs, and the engine runs on every request. Asking the
 * translated registry for it used to load the whole textdomain on a visitor page load:
 * wpoptimizing_core_tweaks_apply() runs on `init` and goes enabled_ids() → sanitize() →
 * registry(), where the first __() makes WordPress load the catalogue — 313 KB of PHP on
 * a Spanish site, on every request, to answer a question whose answer is eleven ids.
 *
 * The labels stay in the registry below, which only the admin UI calls. CoreTweaksTest
 * asserts the two lists carry the same ids and the same tiers, so they cannot drift.
 *
 * @since 0.1.0
 * @return array<string,string> Tweak id => tier ('safe'|'moderate').
 */
function wpoptimizing_core_tweaks_ids() {
	return array(
		// ── Safe ──────────────────────────────────────────────────────────
		'emojis'                => 'safe',
		'generator'             => 'safe',
		'rsd_link'              => 'safe',
		'wlwmanifest'           => 'safe',
		'shortlink'             => 'safe',
		'self_pingbacks'        => 'safe',
		// ── Moderate ──────────────────────────────────────────────────────
		'jquery_migrate'        => 'moderate',
		'dashicons_frontend'    => 'moderate',
		'embeds'                => 'moderate',
		'remote_block_patterns' => 'moderate',
		'rest_head_links'       => 'moderate',
		'cart_fragments'        => 'moderate',
	);
}

/**
 * The registry of available core tweaks, WITH translated labels and descriptions.
 *
 * ADMIN-ONLY BY CONSEQUENCE, not by convention: calling this loads the textdomain, so
 * front-end code paths must use wpoptimizing_core_tweaks_ids() instead.
 *
 * @since 0.1.0
 * @return array<string,array{tier:string,label:string,desc:string}>
 */
function wpoptimizing_core_tweaks_registry() {
	return array(
		// ── Safe ──────────────────────────────────────────────────────────
		'emojis'                => array(
			'tier'  => 'safe',
			'label' => __( 'Disable emojis', 'wpoptimizing' ),
			'desc'  => __( 'Removes the emoji detection script, inline styles and DNS prefetch. Modern browsers render emoji natively.', 'wpoptimizing' ),
		),
		'generator'             => array(
			'tier'  => 'safe',
			'label' => __( 'Remove WordPress version meta', 'wpoptimizing' ),
			'desc'  => __( 'Removes the generator meta tag that exposes your WordPress version in the page head and feeds.', 'wpoptimizing' ),
		),
		'rsd_link'              => array(
			'tier'  => 'safe',
			'label' => __( 'Remove RSD link', 'wpoptimizing' ),
			'desc'  => __( 'Removes the Really Simple Discovery link, used only by some legacy remote-publishing clients.', 'wpoptimizing' ),
		),
		'wlwmanifest'           => array(
			'tier'  => 'safe',
			'label' => __( 'Remove WLW manifest link', 'wpoptimizing' ),
			'desc'  => __( 'Removes the Windows Live Writer manifest link, for a long-discontinued desktop editor.', 'wpoptimizing' ),
		),
		'shortlink'             => array(
			'tier'  => 'safe',
			'label' => __( 'Remove shortlink tag', 'wpoptimizing' ),
			'desc'  => __( 'Removes the shortlink <link> in the head and its HTTP Link header.', 'wpoptimizing' ),
		),
		'self_pingbacks'        => array(
			'tier'  => 'safe',
			'label' => __( 'Disable self-pingbacks', 'wpoptimizing' ),
			'desc'  => __( 'Stops WordPress from pinging your own site when you link to your own posts.', 'wpoptimizing' ),
		),
		// ── Moderate ──────────────────────────────────────────────────────
		'jquery_migrate'        => array(
			'tier'  => 'moderate',
			'label' => __( 'Remove jQuery Migrate', 'wpoptimizing' ),
			'desc'  => __( 'Drops the jquery-migrate compatibility script on the front end. Disable if an old theme or plugin misbehaves.', 'wpoptimizing' ),
		),
		'dashicons_frontend'    => array(
			'tier'  => 'moderate',
			'label' => __( 'Remove Dashicons on the front end', 'wpoptimizing' ),
			'desc'  => __( 'Dequeues the Dashicons icon font for logged-out visitors. Kept for logged-in users (admin bar needs it).', 'wpoptimizing' ),
		),
		'embeds'                => array(
			'tier'  => 'moderate',
			'label' => __( 'Disable oEmbed extras', 'wpoptimizing' ),
			'desc'  => __( 'Removes oEmbed discovery links and the wp-embed.min.js script so others cannot embed your posts. Embedding external content still works.', 'wpoptimizing' ),
		),
		'remote_block_patterns' => array(
			'tier'  => 'moderate',
			'label' => __( 'Disable remote block patterns', 'wpoptimizing' ),
			'desc'  => __( 'Stops the block editor from fetching the remote pattern directory from WordPress.org.', 'wpoptimizing' ),
		),
		'rest_head_links'       => array(
			'tier'  => 'moderate',
			'label' => __( 'Remove REST API head links', 'wpoptimizing' ),
			'desc'  => __( 'Removes the REST API discovery <link> and Link header from the front end. The REST API keeps working.', 'wpoptimizing' ),
		),
		'cart_fragments'        => array(
			'tier'  => 'moderate',
			'label' => __( 'Load WooCommerce cart fragments only with a cart', 'wpoptimizing' ),
			'desc'  => __( 'Visitors with an empty cart no longer download the script that refreshes the cart in the header, nor make its request on every page. Adding a product still updates the header.', 'wpoptimizing' ),
		),
	);
}


/**
 * The tweaks this site is offered: the registry minus the ones that need a plugin it lacks.
 * The WooCommerce cart one showed on a site without WooCommerce, switched on, doing nothing
 * (soporte.artprojectgroup.es, 26/10/2026). Settings, the wizard and the profiles read this;
 * the engine keeps reading ids(), so a store loses nothing.
 *
 * @since 0.1.0
 * @param bool|null $has_woocommerce Null to detect it.
 * @return array<string,array{tier:string,label:string,desc:string}>
 */
function wpoptimizing_core_tweaks_offered( $has_woocommerce = null ) {
	$registry = wpoptimizing_core_tweaks_registry();
	if ( null === $has_woocommerce ) {
		$has_woocommerce = class_exists( 'WooCommerce' );
	}
	if ( ! $has_woocommerce ) {
		unset( $registry['cart_fragments'] );
	}
	return $registry;
}

/**
 * The risk tiers, in display order, with their labels and one-line notes.
 *
 * @since 0.1.0
 * @return array<string,array{label:string,note:string}>
 */
function wpoptimizing_core_tweaks_tiers() {
	return array(
		'safe'     => array(
			'label' => __( 'Safe', 'wpoptimizing' ),
			'note'  => __( 'No visible impact — head noise only.', 'wpoptimizing' ),
		),
		'moderate' => array(
			'label' => __( 'Moderate', 'wpoptimizing' ),
			'note'  => __( 'May affect a few setups — review each one.', 'wpoptimizing' ),
		),
	);
}

/**
 * Normalize a raw tweaks value (a {id:1} map from the form, or a stored list of
 * ids) into a clean, unique list of enabled tweak ids that exist in the registry.
 * Pure (unit-testable).
 *
 * @since 0.1.0
 * @param array $raw Raw value.
 * @return string[]
 */
function wpoptimizing_core_tweaks_sanitize( array $raw ) {
	// ids(), not registry(): this runs on the front end and registry() is translated.
	$known = array_keys( wpoptimizing_core_tweaks_ids() );
	$ids   = array();
	foreach ( $raw as $key => $value ) {
		// Form posts a map (id => "1"); a stored value is a list (n => id).
		$candidate = is_string( $key ) ? $key : (string) $value;
		$enabled   = is_string( $key ) ? ! empty( $value ) : true;
		if ( $enabled && in_array( $candidate, $known, true ) ) {
			$ids[] = $candidate;
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * The enabled tweak ids for this site.
 *
 * @since 0.1.0
 * @return string[]
 */
function wpoptimizing_core_tweaks_enabled_ids() {
	$raw = get_option( 'wpoptimizing_core_tweaks', array() );
	return wpoptimizing_core_tweaks_sanitize( is_array( $raw ) ? $raw : array() );
}

/**
 * Whether a given tweak is enabled (request-cached).
 *
 * @since 0.1.0
 * @param string $id Tweak id.
 * @return bool
 */
function wpoptimizing_core_tweak_on( $id ) {
	static $map = null;
	if ( null === $map ) {
		$map = array_fill_keys( wpoptimizing_core_tweaks_enabled_ids(), true );
	}
	return isset( $map[ $id ] );
}

/**
 * Register the hooks for every enabled tweak. Each branch only removes core
 * actions or adds short-circuit filters, so toggling a tweak off fully restores
 * the default behaviour on the next request.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_core_tweaks_apply() {
	foreach ( wpoptimizing_core_tweaks_enabled_ids() as $id ) {
		switch ( $id ) {
			case 'emojis':
				wpoptimizing_tweak_disable_emojis();
				break;

			case 'generator':
				remove_action( 'wp_head', 'wp_generator' );
				add_filter( 'the_generator', '__return_empty_string' );
				break;

			case 'rsd_link':
				remove_action( 'wp_head', 'rsd_link' );
				break;

			case 'wlwmanifest':
				remove_action( 'wp_head', 'wlwmanifest_link' );
				break;

			case 'shortlink':
				remove_action( 'wp_head', 'wp_shortlink_wp_head' );
				remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
				break;

			case 'self_pingbacks':
				add_action( 'pre_ping', 'wpoptimizing_tweak_no_self_ping' );
				break;

			case 'jquery_migrate':
				if ( ! is_admin() ) {
					add_action( 'wp_default_scripts', 'wpoptimizing_tweak_remove_jquery_migrate' );
				}
				break;

			case 'dashicons_frontend':
				add_action( 'wp_enqueue_scripts', 'wpoptimizing_tweak_dequeue_dashicons', 100 );
				break;

			case 'embeds':
				wpoptimizing_tweak_disable_embeds();
				break;

			case 'remote_block_patterns':
				add_filter( 'should_load_remote_block_patterns', '__return_false' );
				break;

			case 'rest_head_links':
				remove_action( 'wp_head', 'rest_output_link_wp_head' );
				remove_action( 'template_redirect', 'rest_output_link_header', 11 );
				break;

			case 'cart_fragments':
				if ( ! is_admin() ) {
					add_action( 'wp_enqueue_scripts', 'wpoptimizing_tweak_cart_fragments_only_with_cart', 100 );
					// The mini-cart widget and block enqueue it while they render, after the hook above.
					add_action( 'wp_print_footer_scripts', 'wpoptimizing_tweak_cart_fragments_only_with_cart', 1 );
				}
				break;
		}
	}
}
add_action( 'init', 'wpoptimizing_core_tweaks_apply', 0 );

/**
 * Drop wc-cart-fragments for a visitor whose cart is empty.
 *
 * The script exists to repaint the header's mini-cart on a cached page, and it does so with an
 * uncached admin-ajax request on every page view — the single most common reason a WooCommerce
 * page is slow behind a full-page cache. With nothing in the cart there is nothing to repaint.
 * Adding a product does not need it either: WooCommerce's add-to-cart script swaps the
 * fragments from its own response, and from then on the cart cookie brings this script back.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_tweak_cart_fragments_only_with_cart() {
	if ( ! wpoptimizing_tweak_visitor_has_cart( $_COOKIE ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- only the presence of two cookie names is read.
		wp_dequeue_script( 'wc-cart-fragments' );
	}
}

/**
 * Does the visitor have something in the WooCommerce cart? WooCommerce sets these two cookies
 * as soon as the cart holds an item and clears them when it is emptied. Pure.
 *
 * @since 0.1.0
 * @param array $cookies Request cookies.
 * @return bool
 */
function wpoptimizing_tweak_visitor_has_cart( array $cookies ) {
	return ! empty( $cookies['woocommerce_items_in_cart'] ) || ! empty( $cookies['woocommerce_cart_hash'] );
}

/**
 * Strip every emoji hook WordPress adds across the front end, admin, feeds and
 * email, plus the emoji DNS-prefetch resource hint and the TinyMCE plugin.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_tweak_disable_emojis() {
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'admin_print_styles', 'print_emoji_styles' );
	// WordPress 6.4 moved the emoji CSS to an enqueued style; the two lines above no longer
	// stop it, and the panel said "removed" while every page still printed it.
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
	remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
	remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
	remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
	add_filter( 'tiny_mce_plugins', 'wpoptimizing_tweak_remove_tinymce_emoji' );
	add_filter( 'wp_resource_hints', 'wpoptimizing_tweak_remove_emoji_dns_prefetch', 10, 2 );

	// Remember which host the emoji CDN lives on BEFORE we blank the filters, so the
	// resource-hint filter can recognise it without a hardcoded URL. Reading core's own
	// `emoji_svg_url` filter with an EMPTY default means we only ever learn what core or
	// another plugin actually supplies.
	$emoji_url = (string) apply_filters( 'emoji_svg_url', '' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- reading a core filter, not declaring a plugin hook.
	wpoptimizing_tweak_emoji_host( '' !== $emoji_url ? (string) wp_parse_url( $emoji_url, PHP_URL_HOST ) : '' );

	// Belt and braces: emptying the two core emoji URL filters means nothing on the page
	// can point at the emoji CDN any more, whichever code path would have printed it.
	// These are CORE filters (wp-includes/formatting.php), not hooks we declare.
	add_filter( 'emoji_url', '__return_empty_string' );     // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
	add_filter( 'emoji_svg_url', '__return_empty_string' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
}

/**
 * Drop the emoji plugin from the TinyMCE plugin list.
 *
 * @since 0.1.0
 * @param array $plugins TinyMCE plugins.
 * @return array
 */
function wpoptimizing_tweak_remove_tinymce_emoji( $plugins ) {
	return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
}

/**
 * Get (or set) the host of the emoji CDN this install would use.
 *
 * Captured by wpoptimizing_tweak_disable_emojis() from core's own `emoji_svg_url` filter
 * before that filter is blanked. Kept as a tiny accessor so the hint filter stays pure
 * (and unit-testable) and so the CDN URL never has to be hardcoded in the plugin: it
 * changes with every WordPress release, and a literal copy also makes the plugin look
 * like it offloads assets to a third-party service.
 *
 * @since 0.1.0
 * @param string|null $host Pass a host to store it; omit to read the stored one.
 * @return string Stored host, or '' when nothing declares an emoji CDN.
 */
function wpoptimizing_tweak_emoji_host( $host = null ) {
	static $stored = '';
	if ( null !== $host ) {
		$stored = strtolower( trim( (string) $host ) );
	}
	return $stored;
}

/**
 * Remove any emoji-CDN DNS-prefetch resource hint.
 *
 * On WordPress 6.6+ core no longer adds this hint itself, so this is a safety net for
 * older behaviour and for setups that point the emoji filters at their own CDN.
 *
 * @since 0.1.0
 * @param array  $urls          Resource-hint URLs.
 * @param string $relation_type Hint relation type.
 * @return array
 */
function wpoptimizing_tweak_remove_emoji_dns_prefetch( $urls, $relation_type ) {
	if ( 'dns-prefetch' !== $relation_type || ! is_array( $urls ) ) {
		return $urls;
	}
	$emoji_host = wpoptimizing_tweak_emoji_host();
	if ( '' === $emoji_host ) {
		return $urls; // Nothing declares an emoji CDN: nothing to strip.
	}

	foreach ( $urls as $i => $url ) {
		$href = is_array( $url ) ? ( $url['href'] ?? '' ) : $url;
		if ( ! is_string( $href ) || '' === $href ) {
			continue;
		}
		// A dns-prefetch entry can be a bare host or a full URL.
		$host = (string) wp_parse_url( ( false !== strpos( $href, '//' ) ? $href : '//' . $href ), PHP_URL_HOST );
		if ( '' !== $host && strtolower( $host ) === $emoji_host ) {
			unset( $urls[ $i ] );
		}
	}
	return array_values( $urls );
}

/**
 * Remove the self-ping target from the list of links to ping.
 *
 * @since 0.1.0
 * @param array $links Links being pinged (passed by reference by the hook).
 * @return void
 */
function wpoptimizing_tweak_no_self_ping( &$links ) {
	$home = home_url();
	foreach ( (array) $links as $i => $link ) {
		if ( is_string( $link ) && 0 === strpos( $link, $home ) ) {
			unset( $links[ $i ] );
		}
	}
}

/**
 * Remove jquery-migrate from jQuery's dependencies (front end only).
 *
 * @since 0.1.0
 * @param WP_Scripts $scripts The scripts registry.
 * @return void
 */
function wpoptimizing_tweak_remove_jquery_migrate( $scripts ) {
	if ( ! is_object( $scripts ) || empty( $scripts->registered['jquery'] ) ) {
		return;
	}
	$deps = $scripts->registered['jquery']->deps;
	if ( is_array( $deps ) ) {
		$scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
	}
}

/**
 * Dequeue the Dashicons font for logged-out visitors.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_tweak_dequeue_dashicons() {
	if ( ! is_user_logged_in() ) {
		wp_dequeue_style( 'dashicons' );
	}
}

/**
 * Disable oEmbed extras: the discovery links and host JS in the head, the embed
 * discovery probe, and the front-end wp-embed script. The head callbacks are
 * removed at whatever priority core registered them (it varies by WP version —
 * e.g. discovery links at 4 on recent cores), so a hard-coded priority would miss.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_tweak_disable_embeds() {
	foreach ( array( 'wp_oembed_add_discovery_links', 'wp_oembed_add_host_js' ) as $cb ) {
		// Remove every registration of the callback, whatever its priority (it
		// varies by WP version and may even be hooked more than once).
		$guard = 0;
		while ( ++$guard <= 20 && false !== ( $priority = has_action( 'wp_head', $cb ) ) ) {
			if ( ! remove_action( 'wp_head', $cb, (int) $priority ) ) {
				break;
			}
		}
	}
	add_filter( 'embed_oembed_discover', '__return_false' );
	add_action( 'wp_footer', 'wpoptimizing_tweak_deregister_wp_embed' );
}

/**
 * Deregister the front-end wp-embed script.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_tweak_deregister_wp_embed() {
	wp_dequeue_script( 'wp-embed' );
}
