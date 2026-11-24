<?php
/**
 * Page cache — install/uninstall, coexistence and precompiled config.
 *
 * The serving/exclusion logic lives in the self-contained engine
 * (includes/dropins/page-cache-engine.php) and the advanced-cache.php drop-in;
 * this module is the plugin-side controller that installs that drop-in, toggles
 * WP_CACHE (via the shared wp-config helper), writes the precompiled config
 * sidecar from the saved options, and refuses to run alongside a third-party
 * page cache. It composes existing infrastructure and adds no new file I/O
 * mechanics beyond what the engine/wpconfig helpers already provide.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/*
 * The engine may already be loaded from ANOTHER path: a clone (staging, a migration) whose
 * advanced-cache.php still points at the original site's plugin. Requiring ours on top of it
 * redeclared wpopt_pc_default_config() and took the whole clone down before the admin could
 * repair anything (staging.emiliocalvo.es, 23/10/2026). With the other copy loaded we keep it
 * for this request, and wpoptimizing_maybe_refresh_page_cache_dropin() rewrites the drop-in.
 */
if ( ! function_exists( 'wpopt_pc_default_config' ) ) {
	require_once __DIR__ . '/dropins/page-cache-engine.php';
}
require_once __DIR__ . '/content-purge.php';
// After a full purge, rebuild with the code on the disk, not OPcache's older copy.
require_once __DIR__ . '/opcache-refresh.php';

/** Marker of the serve-without-PHP block in the site's .htaccess (page-cache-static.php, Pro). */
const WPOPTIMIZING_PC_STATIC_MARKER = 'WPOptimizing Page Cache';

/**
 * Remove our serve-without-PHP block from .htaccess contents (markers included). Pure.
 *
 * Here and not in page-cache-static.php because the free edition needs it too: a site that comes
 * from Pro with that block in place must lose it (wpoptimizing_page_cache_pro_leftovers()).
 *
 * @param string $contents .htaccess contents.
 * @return string
 */
function wpoptimizing_pc_static_strip( $contents ) {
	$begin = '# BEGIN ' . WPOPTIMIZING_PC_STATIC_MARKER;
	$end   = '# END ' . WPOPTIMIZING_PC_STATIC_MARKER;
	// The block and the blank line insert() puts after it: strip( insert( $x ) ) === $x.
	return (string) preg_replace( '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\R(\R)?/s', '', (string) $contents );
}

/**
 * Is this a multisite network, as far as the page cache is concerned?
 *
 * The free edition has one sidecar per install and never serves a network
 * (wpoptimizing_page_cache_unsupported() refuses it there), so for it this is never one.
 *
 * @return bool
 */
function wpoptimizing_page_cache_network() {
	$network = false;
	return $network;
}

/**
 * The sidecar file of this site: the cache root's config.php on a single install, the site's own
 * file on a network (page-cache-network.php, Pro).
 *
 * @return string '' when this site cannot have one (see wpoptimizing_page_cache_site_paths()).
 */
function wpoptimizing_page_cache_config_file() {
	return WPOPT_PC_CONFIG;
}

/**
 * The folder of this site's copies: the cache root on a single install, the site's own folder on
 * a network (where its full-purge mark, its WP-Cron files and its preload locks live).
 *
 * @return string
 */
function wpoptimizing_page_cache_site_root() {
	return WPOPT_PC_CACHE_DIR;
}

/**
 * Name of our managed wp-config block for WP_CACHE.
 *
 * @return string
 */
function wpoptimizing_page_cache_block_name() {
	return 'Page-Cache';
}

/**
 * PHP body of the WP_CACHE managed block.
 *
 * @return string
 */
function wpoptimizing_page_cache_block_body() {
	return "if ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true );\n}";
}

/**
 * Does a drop-in file carry OUR page-cache marker? Pure string check on the head.
 *
 * @param string $head First bytes of the file.
 * @return bool
 */
function wpoptimizing_page_cache_is_ours_string( $head ) {
	return false !== strpos( (string) $head, 'WPOPT_PAGE_CACHE' );
}

/**
 * Is the installed wp-content/advanced-cache.php ours? Reads the header only.
 *
 * @param string $path Path to advanced-cache.php.
 * @return bool
 */
function wpoptimizing_page_cache_is_ours( $path ) {
	if ( ! is_readable( $path ) ) {
		return false;
	}
	$head = (string) file_get_contents( $path, false, null, 0, 256 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads our own drop-in header to detect ownership; not a plugin-dir asset.
	return wpoptimizing_page_cache_is_ours_string( $head );
}

/**
 * Fill the drop-in template with real paths. Pure (template string in → PHP out).
 *
 * @param string $template    advanced-cache.php template contents.
 * @param string $engine_path Absolute path to the engine.
 * @param string $config_path Absolute path to the config sidecar.
 * @param string $home        Folder the drop-in is installed in, as its __DIR__ will read it; ''
 *                            skips the check that a cloned copy does nothing.
 * @return string
 */
function wpoptimizing_page_cache_build_dropin( $template, $engine_path, $config_path, $home = '' ) {
	return strtr(
		(string) $template,
		array(
			'%%WPOPT_PC_ENGINE%%' => $engine_path,
			'%%WPOPT_PC_CONFIG%%' => $config_path,
			'%%WPOPT_PC_HOME%%'   => $home,
		)
	);
}

/**
 * Folder the drop-in lives in, resolved like PHP resolves __DIR__ (symlinks followed).
 *
 * @return string
 */
function wpoptimizing_page_cache_dropin_home() {
	$dir  = dirname( WPOPT_ADVCACHE_DEST );
	$real = realpath( $dir );

	return false !== $real ? $real : $dir;
}

/**
 * The drop-in as this install would write it now.
 *
 * @return string '' when the bundled template is missing.
 */
function wpoptimizing_page_cache_expected_dropin() {
	if ( ! is_readable( WPOPT_ADVCACHE_SRC ) ) {
		return '';
	}
	$template = (string) file_get_contents( WPOPT_ADVCACHE_SRC ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads our own bundled drop-in template.

	return wpoptimizing_page_cache_build_dropin( $template, WPOPT_PC_ENGINE, WPOPT_PC_CONFIG, wpoptimizing_page_cache_dropin_home() );
}

/**
 * Build the precompiled config array from the saved options.
 *
 * @param bool $active Whether caching should be live.
 * @return array
 */
function wpoptimizing_page_cache_build_config( $active ) {
	$opt = (array) get_option( 'wpoptimizing_page_cache', array() );

	$config = array(
		'active'          => (bool) $active,
		'base_dir'        => WPOPT_PC_CACHE_DIR,
		'gzip'            => ! isset( $opt['gzip'] ) ? true : (bool) $opt['gzip'],
		'reject_page_ids' => wpoptimizing_page_cache_reject_page_ids(),
		// Hosts this install actually answers to. The drop-in keys the cache directory by
		// the request Host header, so an unvalidated header let anyone create unlimited
		// directories under the cache folder (and fill the disk). Anything not on this list
		// is served uncached.
		'allowed_hosts'   => wpoptimizing_page_cache_allowed_hosts(),
		'ttl'             => wpoptimizing_page_cache_ttl(),
		// How long an expired page may bridge its rebuild without passing the nonce window.
		'stale_grace'     => wpoptimizing_page_cache_stale_grace( wpoptimizing_page_cache_ttl(), wpoptimizing_page_cache_nonce_window() ),
		'vary_cookies'    => wpoptimizing_page_cache_vary_cookies(),
		// A hit served by PHP fires WP-Cron when a task is due (wpopt_pc_cron_arm()): where to
		// call it. Pro also gives the address this server answers on, to skip the CDN
		// (loopback.php); without it the drop-in calls the site's own host name.
		'cron_url'        => site_url( 'wp-cron.php' ),
		'loopback_addr'   => '',
		// Which build of the plugin wrote it (wpoptimizing_page_cache_sidecar_catch_up()).
		'rev'             => wpoptimizing_page_cache_config_rev(),
	);

	// The site's own exclusions extend the defaults (union in the engine), which can never be
	// removed. Both editions: an exclusion only ever caches less.
	foreach ( array( 'reject_cookies', 'reject_uri', 'query_whitelist', 'reject_ua', 'ignored_query' ) as $key ) {
		if ( ! empty( $opt[ $key ] ) && is_array( $opt[ $key ] ) ) {
			$config[ $key ] = array_values( array_filter( array_map( 'strval', $opt[ $key ] ) ) );
		}
	}
	// ?lang= only on a site whose multilingual plugin reads it, and only with its real languages
	// (wpopt_pc_query_value_ok()): elsewhere WordPress ignores it and every invented value was a copy.
	$langs = wpoptimizing_page_cache_lang_codes();
	if ( $langs ) {
		$config['query_whitelist'] = array_values( array_unique( array_merge( isset( $config['query_whitelist'] ) ? (array) $config['query_whitelist'] : array(), array( 'lang' ) ) ) );
		$config['lang_codes']      = $langs;
	}
	// A theme that prints different HTML on phones needs a second copy for them.
	$config['mobile_separate'] = ! empty( $opt['mobile_separate'] );
	// Page types never stored (front page, archives, chosen content types…), judged at buffer
	// close by wpopt_pc_type_rejected(). Left out when nothing is excluded, so the drop-in does not
	// even read the page context on those sites.
	$types = wpoptimizing_page_cache_type_rules( $opt );
	if ( wpoptimizing_page_cache_type_rules_any( $types ) ) {
		$config['reject_types'] = $types;
	}

	return $config;
}


/**
 * The page types the site chose never to cache, normalised from the page-cache option. Pure.
 *
 * Flat keys in the option (reject_front, reject_home, reject_archives, reject_paged,
 * reject_post_types), like the other preferences there, so settings export and import carry
 * them; one array in the sidecar, the shape wpopt_pc_type_rejected() reads. An option saved
 * before these settings existed has none of the keys: nothing is excluded.
 *
 * @param array $opt Page-cache option.
 * @return array { front, home, archives, paged: bool, post_types: string[] }
 */
function wpoptimizing_page_cache_type_rules( $opt ) {
	$opt   = (array) $opt;
	$types = array();
	foreach ( isset( $opt['reject_post_types'] ) ? (array) $opt['reject_post_types'] : array() as $type ) {
		$type = is_scalar( $type ) ? sanitize_key( (string) $type ) : '';
		if ( '' !== $type ) {
			$types[] = $type;
		}
	}
	return array(
		'front'      => ! empty( $opt['reject_front'] ),
		'home'       => ! empty( $opt['reject_home'] ),
		'archives'   => ! empty( $opt['reject_archives'] ),
		'paged'      => ! empty( $opt['reject_paged'] ),
		'post_types' => array_values( array_unique( $types ) ),
	);
}

/**
 * Does a set of page-type rules exclude anything at all? Pure.
 *
 * @param array $rules Output of wpoptimizing_page_cache_type_rules().
 * @return bool
 */
function wpoptimizing_page_cache_type_rules_any( array $rules ) {
	return ! empty( $rules['front'] ) || ! empty( $rules['home'] ) || ! empty( $rules['archives'] ) || ! empty( $rules['paged'] ) || ! empty( $rules['post_types'] );
}


/**
 * The price cookies of the multi-currency plugin that is active, for the cache key.
 *
 * Only an active one: on a store that does not read the cookie, a copy per value would just let
 * anyone fill the disk. Names and detection symbols read in each plugin's source (06/11/2026).
 * Language cookies are left out on purpose: WPML, Polylang and TranslatePress put the language
 * in the URL.
 *
 * @return string[]
 */
function wpoptimizing_page_cache_vary_cookies() {
	$out = array();
	if ( class_exists( 'WC_Aelia_CurrencySwitcher', false ) ) {
		$out = array_merge( $out, array( 'aelia_cs_selected_currency', 'aelia_customer_country', 'aelia_billing_country' ) );
	}
	if ( defined( 'WOOMULTI_CURRENCY_F_VERSION' ) || defined( 'WOOMULTI_CURRENCY_VERSION' ) ) {
		$out[] = 'wmc_current_currency';
	}
	if ( defined( 'WOOCS_VERSION' ) ) {
		$out[] = 'woocs_current_currency';
	}
	if ( defined( 'WCML_VERSION' ) ) {
		$out[] = 'wcml_client_currency';
	}
	/**
	 * Filters the cookies that get a cached copy per value (a currency or country code).
	 *
	 * @since 0.1.0
	 * @param string[] $out Cookie names.
	 */
	return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'wpoptimizing_page_cache_vary_cookies', $out ) ) ) );
}


/**
 * Rewrite the sidecar when a plugin is switched on or off: a multi-currency plugin changes the
 * cookies the cache key reads (wpoptimizing_page_cache_vary_cookies()).
 *
 * @return void
 */
function wpoptimizing_page_cache_refresh_config() {
	if ( wpoptimizing_page_cache_is_active() ) {
		wpoptimizing_page_cache_write_config( true );
	}
	// The .htaccess rules carry the same cookies: without rewriting them, a visitor with the
	// new currency cookie was served the default currency by the web server (Codex, 06/11/2026).
	if ( function_exists( 'wpoptimizing_pc_static_enabled' ) && wpoptimizing_pc_static_enabled() ) {
		wpoptimizing_pc_static_apply( true, false );
	}
}

/**
 * After importing settings (from a file or from another plugin, or undoing that): the sidecar,
 * the server rules and the copies follow the page-cache settings now stored. Exclusions that
 * arrived must stop pages from being served at once, not when they next expire.
 *
 * @param string[] $applied Options written.
 * @return void
 */
function wpoptimizing_page_cache_after_import( $applied ) {
	if ( ! in_array( 'wpoptimizing_page_cache', (array) $applied, true ) ) {
		return;
	}
	wpoptimizing_page_cache_refresh_config();
	if ( wpoptimizing_page_cache_is_active() ) {
		wpoptimizing_page_cache_clear();
	}
}
add_action( 'wpoptimizing_settings_imported', 'wpoptimizing_page_cache_after_import' );


/**
 * Settings that change the HTML a page is stored with. Pure.
 *
 * Saving any of them left every cached page as it was: minify, delay JavaScript, critical CSS,
 * the preloads or the unloaded assets changed on screen and visitors kept getting the old pages
 * until each copy expired (emiliocalvo.es, 09/11/2026).
 *
 * @return string[]
 */
function wpoptimizing_page_cache_output_options() {
	/**
	 * Options whose change empties the page cache.
	 *
	 * @since 0.1.0
	 * @param string[] $options Option names.
	 */
	return (array) apply_filters(
		'wpoptimizing_page_cache_output_options',
		array(
			'wpoptimizing_asset_optimize',
			'wpoptimizing_delay_js',
			'wpoptimizing_ccss',
			'wpoptimizing_frontend_perf',
			'wpoptimizing_assets_rules',
			'wpoptimizing_assets_unload_enabled',
			'wpoptimizing_assets_auto_enabled',
			'wpoptimizing_assets_url_exclusions',
			'wpoptimizing_core_tweaks',
			'wpoptimizing_cdn',
			'wpoptimizing_image_settings',
			'wpoptimizing_cache_settings',
		)
	);
}

/**
 * One of those settings changed: empty the page cache, once per request however many changed.
 *
 * @return void
 */
function wpoptimizing_page_cache_output_changed() {
	if ( function_exists( 'wpoptimizing_page_cache_is_active' ) && wpoptimizing_page_cache_is_active() ) {
		wpoptimizing_page_cache_purge_all();
	}
}

/**
 * Hook the purge to those settings. On plugins_loaded, not when the file loads: the drop-in can
 * load this file before WordPress has its hook functions.
 *
 * @return void
 */
function wpoptimizing_page_cache_output_hooks() {
	foreach ( wpoptimizing_page_cache_output_options() as $option ) {
		add_action( 'update_option_' . $option, 'wpoptimizing_page_cache_output_changed', 10, 0 );
		add_action( 'add_option_' . $option, 'wpoptimizing_page_cache_output_changed', 10, 0 );
	}
}
if ( function_exists( 'did_action' ) && did_action( 'plugins_loaded' ) ) {
	wpoptimizing_page_cache_output_hooks();
} else {
	add_action( 'plugins_loaded', 'wpoptimizing_page_cache_output_hooks' );
}


/**
 * How long a cached page may be served before it is rebuilt: 10 hours by default, the same
 * as WP Rocket, and safely inside the 12-hour half-life of a WordPress nonce. 0 = never.
 *
 * The saved value goes through the shared policy (1 to 12 whole hours), so an old setting or a
 * hand-edited option cannot keep pages longer than the screen allows; only the filter can.
 *
 * @return int Seconds.
 */
function wpoptimizing_page_cache_ttl() {
	$opt = (array) get_option( 'wpoptimizing_page_cache', array() );
	$ttl = isset( $opt['ttl'] ) ? wpoptimizing_page_cache_ttl_policy( (int) $opt['ttl'] )['ttl'] : WPOPTIMIZING_PC_TTL_DEFAULT_HOURS * HOUR_IN_SECONDS;
	/**
	 * Filters the page-cache lifetime in seconds (0 = pages never expire on their own).
	 *
	 * @since 0.1.0
	 * @param int $ttl Seconds.
	 */
	return max( 0, (int) apply_filters( 'wpoptimizing_page_cache_ttl', $ttl ) );
}

/**
 * How long a WordPress nonce is guaranteed to stay valid: half its lifetime (12 hours by default).
 * A nonce is accepted during the tick it was made in and the next one, so one made at the very
 * end of a tick lasts only one tick (wp_verify_nonce()). A page older than this can show a form
 * whose nonce is refused. The filter is asked as wp_create_nonce() asks it, for the default
 * action; a plugin that shortens it for one action only cannot be seen from here.
 *
 * @return int Seconds.
 */
function wpoptimizing_page_cache_nonce_window() {
	// Literal seconds: this also runs while the plugin is being deactivated, outside a full load.
	/** This filter is documented in wp-includes/pluggable.php */
	$life = (int) apply_filters( 'nonce_life', 86400, -1 ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own filter.
	return max( 0, intdiv( max( 0, $life ), 2 ) );
}

/**
 * How long past its lifetime an expired page may still be served while one request rebuilds it.
 * Pure.
 *
 * Two hours at most, and never so long that the oldest page served passes the nonce window:
 * with a lifetime of 12 hours, the old fixed two hours let a page reach 14 hours, past the 12 a
 * nonce is guaranteed (review, 09/11/2026). Five minutes are kept back for the render and for
 * clocks. A page that never expires (0) is never stale.
 *
 * @param int $ttl    Page lifetime in seconds (0 = never expires).
 * @param int $window wpoptimizing_page_cache_nonce_window().
 * @return int Seconds.
 */
function wpoptimizing_page_cache_stale_grace( $ttl, $window ) {
	if ( (int) $ttl <= 0 ) {
		return 0;
	}
	return max( 0, min( 7200, (int) $window - (int) $ttl - 300 ) ); // 2 hours at most, 5 minutes kept back.
}

/**
 * Daily sweep of expired page files. Serving already ignores them; this keeps them from piling
 * up on disk for pages nobody visits again.
 *
 * @return int Files deleted.
 */
function wpoptimizing_page_cache_gc() {
	// A full purge whose end-of-request cleanup did not finish (the request died) left a trash.
	foreach ( (array) glob( WPOPT_PC_CACHE_DIR . '/.trash-*', GLOB_ONLYDIR ) as $trash ) {
		wpoptimizing_page_cache_delete_tree( $trash, true );
	}
	// Hit counters: Pro keeps 7 days of its own, before the lifetime check, so a site whose pages
	// never expire does too. The free edition counts nothing: a folder Pro left goes whole.
	$stats_left = (array) glob( WPOPT_PC_CACHE_DIR . '/.stats-*', GLOB_ONLYDIR );
	foreach ( $stats_left as $old ) {
		wpoptimizing_page_cache_delete_tree( $old, true );
	}
	// This site's pages, with this site's lifetime: on a network, its own folder without the
	// folders of the sites under it, which have lifetimes of their own.
	$dir = wpoptimizing_page_cache_site_root();
	$ttl = wpoptimizing_page_cache_ttl();
	if ( ! is_dir( $dir ) ) {
		return 0;
	}
	$now     = time();
	$deleted = 0;
	$it      = wpoptimizing_page_cache_site_files( $dir );
	foreach ( $it as $file ) {
		// A purge mark (wpopt_pc_purged_after()) only matters to the requests that were rendering
		// when it was left. Once it is older than any request can run, it goes, so a site that
		// purges thousands of URLs does not keep a mark in each of their folders for ever.
		if ( $file->isFile() && in_array( $file->getFilename(), array( '.purged', '.purged-all' ), true ) ) {
			if ( (int) $file->getMTime() < $now - wpoptimizing_page_cache_mark_life() ) {
				wpoptimizing_page_cache_drop_mark( $file->getPathname(), wpoptimizing_page_cache_mark_life() );
			}
			continue;
		}
		// Leftovers of requests that died while writing — a temporary file before its rename, the
		// publication lock of a variant — are not pages and have no lifetime: before the lifetime
		// check, or a site whose pages never expire (TTL 0) kept every one of them for ever
		// (audit, 08/11/2026). A temporary file lives for milliseconds, so ten minutes means its
		// writer is gone; a publication lock goes only if nobody holds it.
		if ( $file->isFile() && (int) $file->getMTime() < $now - 10 * MINUTE_IN_SECONDS ) {
			if ( false !== strpos( $file->getFilename(), '.tmp.' ) ) {
				wp_delete_file( $file->getPathname() );
				continue;
			}
			if ( '.publish.lock' === substr( $file->getFilename(), -13 ) ) {
				wpoptimizing_page_cache_delete_unheld_lock( $file->getPathname() );
				continue;
			}
		}
		if ( $ttl <= 0 || ! $file->isFile() || ( 0 !== strpos( $file->getFilename(), 'index' ) && 0 !== strpos( $file->getFilename(), 'static' ) ) || 'index.php' === $file->getFilename()
			|| wpopt_pc_is_fresh( (int) $file->getMTime(), $now, $ttl ) ) {
			continue;
		}
		// A rebuild lock («index….html.lock») keeps its old date while it is held: deleting it
		// then let a second request create a new one and rebuild the same page at the same time
		// (Codex, 06/11/2026). Only a lock nobody holds goes.
		if ( '.lock' === substr( $file->getFilename(), -5 ) ) {
			wpoptimizing_page_cache_delete_unheld_lock( $file->getPathname() );
			continue;
		}
		wp_delete_file( $file->getPathname() );
		++$deleted;
	}
	return $deleted;
}
add_action( 'wpoptimizing_page_cache_gc', 'wpoptimizing_page_cache_gc' );

/**
 * Every file under a site's folder, leaves first: the whole cache folder on a single install; on
 * a network, the site's folder without the folders of the sites under it (a subdirectory network's
 * subsites live in the main site's host folder) nor the sidecars and the trash.
 *
 * @param string $dir Folder (wpoptimizing_page_cache_site_root()).
 * @return RecursiveIteratorIterator
 */
function wpoptimizing_page_cache_site_files( $dir ) {
	$inner = new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS );
	return new RecursiveIteratorIterator( $inner, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
}

/**
 * Delete a lock file only when nobody holds it (taken without waiting, deleted while held, then
 * given back). A request that opened it just before keeps a lock on a name that is gone, which
 * the publication lock detects by its inode (wpopt_pc_variant_lock()).
 *
 * THE LOCK HAS TO BE ON THE FILE THE NAME POINTS TO. The sweep could open publisher A's lock and
 * pause before flock(); A finished and deleted the name, B created a new lock file and began to
 * publish, and the sweep then locked A's old file — which nobody held any more — and deleted by
 * name B's. C could create another one and publish beside B (Codex, 09/11/2026). The same check
 * the publishers make (wpopt_pc_holds_name()), under the lock: when the name points elsewhere,
 * that file belongs to someone else and is left alone.
 *
 * @param string $path Lock file.
 * @return bool True when it was deleted.
 */
function wpoptimizing_page_cache_delete_unheld_lock( $path ) {
	$held = wpopt_pc_try_lock( $path );
	if ( false === $held ) {
		return false;
	}
	if ( ! wpopt_pc_holds_name( $held, $path ) ) {
		wpopt_pc_release_lock( $held );
		return false;
	}
	wp_delete_file( $path );
	wpopt_pc_release_lock( $held );
	return true;
}

/**
 * Delete a purge mark the sweep found old, under the marks lock, and only if it is still old.
 *
 * The sweep used to check the age and delete by name, without the lock a purge takes to write its
 * mark: a purge could renew the mark in between and the sweep deleted the new one. A render that
 * started before that purge then found no mark and stored the old page (Codex, 09/11/2026). With
 * the lock held exclusively no purge can write a mark, so the age read again inside it is the age
 * of the file that is deleted. No lock (a filesystem without flock): the mark stays, as a mark
 * kept too long only costs a few bytes.
 *
 * @param string $path Mark file.
 * @param int    $life Seconds a mark must outlive (wpoptimizing_page_cache_mark_life()).
 * @return bool True when it was deleted.
 */
function wpoptimizing_page_cache_drop_mark( $path, $life ) {
	$lock = wpopt_pc_marks_lock( WPOPT_PC_CACHE_DIR, LOCK_EX );
	if ( false === $lock ) {
		return false;
	}
	clearstatcache( true, (string) $path );
	$mtime = @filemtime( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Gone meanwhile: false.
	$gone  = false !== $mtime && (int) $mtime < time() - (int) $life;
	if ( $gone ) {
		wp_delete_file( (string) $path );
	}
	wpopt_pc_release_lock( $lock );
	return $gone;
}

/**
 * Cache paths the web server cannot write to: the cache folder, its «.purge.lock» and each host
 * folder in it. Any of them owned by another user (root, after `wp … --allow-root` from before
 * the cache re-owned what root creates, or a deploy user) stops pages from being stored or a full
 * purge from moving them, and nothing else says so. Meaningful when the web server asks (the
 * cache screen); run as root, everything is writable.
 *
 * @return string[] Paths relative to the content folder when inside it; at most five.
 */
function wpoptimizing_page_cache_unwritable() {
	$dir = WPOPT_PC_CACHE_DIR;
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	$check = array( $dir, $dir . '/.purge.lock' );
	foreach ( (array) glob( $dir . '/*', GLOB_ONLYDIR ) as $host ) {
		$check[] = $host;
	}
	$bad = array();
	foreach ( $check as $path ) {
		if ( file_exists( $path ) && ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- The web server's own access, not WP_Filesystem's.
			$bad[] = defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $path, WP_CONTENT_DIR . '/' ) ? substr( $path, strlen( WP_CONTENT_DIR ) + 1 ) : $path;
		}
		if ( count( $bad ) >= 5 ) {
			break;
		}
	}
	return $bad;
}

/**
 * How long a purge mark is kept: longer than any render can last. An hour, or the PHP time limit
 * when a host sets a longer one (0 = none: the hour stands).
 *
 * @return int Seconds.
 */
function wpoptimizing_page_cache_mark_life() {
	return max( HOUR_IN_SECONDS, 2 * (int) ini_get( 'max_execution_time' ) );
}

/**
 * Time of the next WP-Cron event in a cron list. Pure.
 *
 * WordPress keeps the list sorted by time (ksort() on every schedule), so the first numeric key
 * is the next event: no walk over a list that holds thousands of events on some stores.
 *
 * @param mixed $cron The 'cron' option (with or without its 'version' key).
 * @return int 0 when nothing is scheduled.
 */
function wpoptimizing_page_cache_cron_next( $cron ) {
	if ( ! is_array( $cron ) ) {
		return 0;
	}
	foreach ( $cron as $time => $unused ) {
		if ( is_int( $time ) || ctype_digit( (string) $time ) ) {
			return (int) $time;
		}
	}
	return 0;
}

/**
 * Tell the drop-in when the next scheduled task is due: «.cron-next» in the cache root, which a
 * hit compares with the clock to fire wp-cron.php (wpopt_pc_cron_arm()). Written whenever
 * WordPress saves its cron list, and only when the value changes (a cron run saves the list
 * once per event).
 *
 * @param mixed $cron  The 'cron' option; null to read it.
 * @param bool  $force Write it even if the cache is not marked on yet (it is being switched on).
 * @return void
 */
function wpoptimizing_page_cache_write_cron_next( $cron = null, $force = false ) {
	// By folder: on a network each site has its own list and its own file, and one request can
	// switch between sites (switch_to_blog()).
	static $last = array();
	if ( ( ! $force && ! wpoptimizing_page_cache_is_active() ) || ! is_dir( WPOPT_PC_CACHE_DIR ) ) {
		return;
	}
	$dir  = wpoptimizing_page_cache_site_root();
	$next = wpoptimizing_page_cache_cron_next( null === $cron ? get_option( 'cron' ) : $cron );
	if ( isset( $last[ $dir ] ) && $next === $last[ $dir ] && ! $force ) {
		return;
	}
	$last[ $dir ] = $next;
	if ( WPOPT_PC_CACHE_DIR !== $dir ) {
		wpopt_pc_mkdir( $dir );
	}
	wpopt_pc_write_atomic( $dir . '/.cron-next', (string) $next );
}

/**
 * The cron list was updated.
 *
 * @param mixed $old_value Previous list.
 * @param mixed $value     New list.
 * @return void
 */
function wpoptimizing_page_cache_cron_updated( $old_value, $value ) {
	unset( $old_value );
	wpoptimizing_page_cache_write_cron_next( $value );
}
add_action( 'update_option_cron', 'wpoptimizing_page_cache_cron_updated', 10, 2 );

/**
 * The cron list was created.
 *
 * @param string $option Option name.
 * @param mixed  $value  New list.
 * @return void
 */
function wpoptimizing_page_cache_cron_added( $option, $value ) {
	unset( $option );
	wpoptimizing_page_cache_write_cron_next( $value );
}
add_action( 'add_option_cron', 'wpoptimizing_page_cache_cron_added', 10, 2 );

/**
 * Has WP-Cron fallen behind? A due event older than $late seconds, on a site that relies on
 * visits to run it (no DISABLE_WP_CRON). The cache screen then recommends a system cron.
 *
 * @param int $late Seconds of delay that count as late.
 * @return bool
 */
function wpoptimizing_page_cache_cron_late( $late = 600 ) {
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		return false;
	}
	$next = wpoptimizing_page_cache_cron_next( get_option( 'cron' ) );
	return $next > 0 && $next < time() - (int) $late;
}


/**
 * The host names this install answers to (home + site URL, with and without "www"), used
 * by the drop-in to reject forged Host headers. Filterable for multi-domain setups.
 *
 * @return string[]
 */
function wpoptimizing_page_cache_allowed_hosts() {
	$hosts = array();
	foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			continue;
		}
		$hosts[] = $host;
		$hosts[] = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : 'www.' . $host;
	}
	// A domain per language (WPML, Polylang): the same WordPress answers on each, and a host left
	// out of this list is never cached — emiliocalvo.com, the English side of emiliocalvo.es,
	// went to PHP on every visit (09/11/2026). The purges already reach those domains.
	foreach ( wpoptimizing_page_cache_language_domains() as $host ) {
		$hosts[] = $host;
		$hosts[] = ( 0 === strpos( $host, 'www.' ) ) ? substr( $host, 4 ) : 'www.' . $host;
	}

	/**
	 * Filters the host names the page cache will store/serve for.
	 *
	 * Add extra domains here when the site legitimately answers on more than one (domain
	 * mapping, a staging alias). Anything outside the list is simply not cached, and an empty
	 * list caches nothing (wpopt_pc_allowed_host()).
	 *
	 * @since 0.1.0
	 * @param string[] $hosts Host names, lower-case; «host:port» accepts that port only.
	 */
	$hosts = (array) apply_filters( 'wpoptimizing_page_cache_allowed_hosts', array_values( array_unique( array_filter( $hosts ) ) ) );

	return array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $hosts ) ) ) );
}

/**
 * The hosts of a multilingual site that gives each language its own domain, or none.
 *
 * WPML through its public setting filter (negotiation type 2 = "a different domain per
 * language"); Polylang through its saved options (force_lang 3 = the same). Values may be full
 * URLs or bare hosts; only the host is kept, lower-case, with its port when it has one.
 *
 * @return string[]
 */
function wpoptimizing_page_cache_language_domains() {
	$domains = array();
	if ( defined( 'ICL_SITEPRESS_VERSION' ) && 2 === (int) apply_filters( 'wpml_setting', 0, 'language_negotiation_type' ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
		$domains = (array) apply_filters( 'wpml_setting', array(), 'language_domains' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
	} elseif ( defined( 'POLYLANG_VERSION' ) ) {
		$pll = (array) get_option( 'polylang', array() );
		if ( isset( $pll['force_lang'] ) && 3 === (int) $pll['force_lang'] && ! empty( $pll['domains'] ) ) {
			$domains = (array) $pll['domains'];
		}
	}
	return wpoptimizing_page_cache_domain_hosts( $domains );
}

/**
 * The language codes a multilingual plugin answers ?lang= with, lower case; none without one.
 *
 * WPML (wpml_active_languages, keyed by code) and Polylang (pll_languages_list(), its slugs) both
 * read ?lang= on the front end. Without either, WordPress ignores the parameter, so caching it
 * only made copies of one page under invented names. Filterable for another plugin that reads it.
 *
 * @return string[]
 */
function wpoptimizing_page_cache_lang_codes() {
	$codes = array();
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$codes = array_keys( (array) apply_filters( 'wpml_active_languages', array(), 'skip_missing=0' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's public API.
	} elseif ( function_exists( 'pll_languages_list' ) ) {
		$codes = (array) pll_languages_list( array( 'fields' => 'slug' ) );
	}

	/**
	 * Filters the values of ?lang= the page cache stores a copy for.
	 *
	 * Only real language codes: every value is a copy of every page that is asked with it.
	 *
	 * @since 0.1.0
	 * @param string[] $codes Language codes, e.g. array( 'es', 'en', 'pt-br' ).
	 */
	$codes = (array) apply_filters( 'wpoptimizing_page_cache_lang_codes', $codes );
	return wpoptimizing_page_cache_clean_lang_codes( $codes );
}

/**
 * Language codes the drop-in may file a copy under: lower case, the shape of a code. Pure.
 *
 * @param array $codes Codes.
 * @return string[]
 */
function wpoptimizing_page_cache_clean_lang_codes( array $codes ) {
	$out = array();
	foreach ( $codes as $code ) {
		$code = strtolower( trim( (string) $code ) );
		if ( 1 === preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/', $code ) ) {
			$out[] = $code;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * Hosts out of a list of domains or URLs. Pure.
 *
 * @param array $domains Bare hosts ("example.com") or URLs ("https://example.com/").
 * @return string[]
 */
function wpoptimizing_page_cache_domain_hosts( array $domains ) {
	$hosts = array();
	foreach ( $domains as $domain ) {
		$domain = trim( (string) $domain );
		if ( '' === $domain ) {
			continue;
		}
		$parts = wp_parse_url( false === strpos( $domain, '://' ) ? 'http://' . $domain : $domain );
		if ( empty( $parts['host'] ) ) {
			continue;
		}
		$hosts[] = strtolower( $parts['host'] ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
	return array_values( array_unique( $hosts ) );
}

/**
 * Serialise a config array into a returnable PHP file. Pure.
 *
 * @param array $config Config.
 * @return string
 */
function wpoptimizing_page_cache_build_config_php( $config ) {
	return "<?php\n// Generated by WPOptimizing page cache. Do not edit; it is rewritten from settings.\nreturn "
		. var_export( $config, true ) . ";\n"; // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- serialising a config array into a PHP sidecar file, not debug output.
}

/**
 * WooCommerce/EDD page ids that must never be cached (cart/checkout/account/purchase).
 *
 * @return array<int>
 */
function wpoptimizing_page_cache_reject_page_ids() {
	$ids = array();
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $slug ) {
			$id = (int) wc_get_page_id( $slug );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
	}
	if ( function_exists( 'edd_get_option' ) ) {
		$purchase = (int) edd_get_option( 'purchase_page', 0 );
		if ( $purchase > 0 ) {
			$ids[] = $purchase;
		}
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Detect anything that conflicts with installing our page cache: a third-party
 * page cache, or a foreign advanced-cache.php we must not overwrite.
 *
 * @return array { conflict:bool, foreign_dropin:bool, caches:array<string> }
 */
function wpoptimizing_page_cache_coexistence() {
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/host-cache.php' );
	}
	// An empty file (WP Rocket leaves a 0-byte advanced-cache.php when it is deactivated, seen on
	// Mundotoro 07/11/2026) does nothing and belongs to nobody: it is not a drop-in to protect.
	$foreign_dropin = file_exists( WPOPT_ADVCACHE_DEST ) && ! wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST )
		&& '' !== trim( (string) file_get_contents( WPOPT_ADVCACHE_DEST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$caches         = array();
	if ( function_exists( 'wpoptimizing_detect_host_cache' ) ) {
		foreach ( wpoptimizing_detect_host_cache() as $c ) {
			// A THIRD-PARTY page cache conflicts; our own entry never does.
			if ( isset( $c['type'] ) && 'page' === $c['type'] && 'wpopt-page-cache' !== ( isset( $c['id'] ) ? $c['id'] : '' ) ) {
				$caches[] = (string) $c['label'];
			}
		}
	}
	/*
	 * Y LOS DEL REGISTRO DE COMPATIBILIDAD, que conoce más cachés de página que el catálogo de
	 * purga: Swift, Seraphinite o Speed Booster Pack no están en host-cache.php (no tienen una
	 * purga segura que llamar), y sin mirarlos aquí «Sustituir» se quedaba con el
	 * advanced-cache.php de un plugin ACTIVO. Se ignora la elección del panel de compatibilidad:
	 * dos cachés de página a la vez nunca es una opción.
	 */
	if ( function_exists( 'wpoptimizing_compat_providers' ) ) {
		foreach ( wpoptimizing_compat_providers( 'page_cache' ) as $label ) {
			if ( ! in_array( (string) $label, $caches, true ) ) {
				$caches[] = (string) $label;
			}
		}
	}
	// LiteSpeed Cache with its page cache switched off for this site (its «Enable Cache»): still
	// active for its other parts, but it stores no pages here, so nothing to conflict with. On a
	// network that is how a site moves to our cache while another site keeps LiteSpeed's
	// (artprojectgroup.com, 10/11/2026).
	if ( wpoptimizing_page_cache_litespeed_off() ) {
		$caches = array_values( array_diff( $caches, array( 'LiteSpeed Cache' ) ) );
	}
	return array(
		'conflict'       => $foreign_dropin || ! empty( $caches ),
		'foreign_dropin' => $foreign_dropin,
		'caches'         => $caches,
	);
}

/**
 * Does LiteSpeed Cache's own setting say its page cache is off for this site? Its setting, or the
 * wp-config constant that overrides it (LITESPEED_CONF + LITESPEED_CONF__CACHE). Whether the
 * plugin is active at all is the compatibility registry's to say (wpoptimizing_page_cache_coexistence()).
 *
 * @return bool
 */
function wpoptimizing_page_cache_litespeed_off() {
	if ( defined( 'LITESPEED_CONF' ) && LITESPEED_CONF && defined( 'LITESPEED_CONF__CACHE' ) ) {
		return ! constant( 'LITESPEED_CONF__CACHE' );
	}
	$on = get_option( 'litespeed.conf.cache', null );
	return null !== $on && in_array( $on, array( 0, '0', '', false ), true );
}

/**
 * Ensure the page-cache directory exists and is protected from direct listing.
 *
 * @return void
 */
function wpoptimizing_page_cache_ensure_dir() {
	// Not wp_mkdir_p(): run as root (WP-CLI), every new level must go to the owner of the folder
	// above it, or the web server can never store a page in it (wpopt_pc_own()).
	wpopt_pc_mkdir( WPOPT_PC_CACHE_DIR );
	$index = WPOPT_PC_CACHE_DIR . '/index.php';
	if ( ! file_exists( $index ) ) {
		wpopt_pc_write_atomic( $index, "<?php\n// Silence is golden.\n" );
	}
}

/**
 * Write the precompiled config sidecar from current options.
 *
 * @param bool $active Whether caching should be live.
 * @return bool
 */
function wpoptimizing_page_cache_write_config( $active ) {
	wpoptimizing_page_cache_ensure_dir();
	$file = wpoptimizing_page_cache_config_file();
	if ( '' === $file ) {
		return false;
	}
	$config = wpoptimizing_page_cache_build_config( $active );
	// The drop-in never creates the counters' folder (it only writes when the folder exists).
	if ( ! empty( $config['stats_dir'] ) && function_exists( 'wpoptimizing_page_cache_stats_ensure_dir' ) ) {
		wpoptimizing_page_cache_stats_ensure_dir( $config['stats_dir'] );
	}
	$php = wpoptimizing_page_cache_build_config_php( $config );
	$ok  = wpopt_pc_write_atomic( $file, $php );
	// The next WP-Cron event, for the hits that fire it: a cache switched on now must not wait for
	// the next change of the cron list to know it.
	if ( $active ) {
		wpoptimizing_page_cache_write_cron_next( null, true );
	}
	// The drop-in include()s this file, so OPcache keeps serving the compiled old copy until it
	// revalidates — forever with validate_timestamps=0. Switching the cache off wrote
	// 'active' => false and visitors kept being served from disk.
	if ( $ok && function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	return $ok;
}

/**
 * Install (or refresh) the page cache: verify coexistence, write advanced-cache.php
 * (atomic), the config sidecar and the WP_CACHE block.
 *
 * @param bool $replace Take the slot of a foreign advanced-cache.php (kept and restored on uninstall).
 *                      Only honoured when no other page cache is active: see wpoptimizing_dropin_can_replace().
 * @return array { ok:bool, code:string, ... } code: done|conflict|foreign_dropin|nobackup|writefail|notemplate|wpconfig-<code>.
 */
function wpoptimizing_page_cache_install( $replace = false ) {
	if ( wpoptimizing_page_cache_unsupported() ) {
		return array(
			'ok'   => false,
			'code' => 'unsupported',
		);
	}
	// A site of a network whose address cannot name a sidecar (see wpoptimizing_page_cache_site_paths()).
	if ( '' === wpoptimizing_page_cache_config_file() ) {
		return array(
			'ok'   => false,
			'code' => 'multisite',
		);
	}
	$co       = wpoptimizing_page_cache_coexistence();
	$takeover = $replace && $co['foreign_dropin'] && empty( $co['caches'] ) && function_exists( 'wpoptimizing_dropin_backup' );
	if ( $takeover && function_exists( 'wpoptimizing_dropin_can_replace' ) && function_exists( 'wpoptimizing_dropin_owner' ) ) {
		$takeover = wpoptimizing_dropin_can_replace( 'page', wpoptimizing_dropin_owner( WPOPT_ADVCACHE_DEST ), $co['caches'] )['ok'];
	}
	if ( $co['conflict'] && ! $takeover ) {
		return array(
			'ok'   => false,
			'code' => $co['foreign_dropin'] && empty( $co['caches'] ) ? 'foreign_dropin' : 'conflict',
			'co'   => $co,
		);
	}
	/*
	 * Y LA CACHÉ DEL HOSTING. Con WP Engine, Kinsta, un Nginx FastCGI o un Varnish que no
	 * purgamos delante, la nuestra sería una segunda copia de cada página y la suya seguiría
	 * sirviendo la vieja después de cada purga. Se mira lo que la portada contestó en la última
	 * comprobación de capas, y si es de hace más de un día se vuelve a preguntar.
	 */
	if ( function_exists( 'wpoptimizing_edge_host_page_caches' ) ) {
		$edge = wpoptimizing_edge_cache_state();
		if ( time() - (int) $edge['checked'] > DAY_IN_SECONDS && function_exists( 'wpoptimizing_edge_cache_check' ) ) {
			$edge = wpoptimizing_edge_cache_check();
		}
		$hosts = wpoptimizing_edge_host_page_caches( $edge, function_exists( 'wpoptimizing_varnish_purge_on' ) && wpoptimizing_varnish_purge_on(), wpoptimizing_edge_host_by_env() );
		if ( $hosts ) {
			return array(
				'ok'    => false,
				'code'  => 'host_cache',
				'hosts' => $hosts,
			);
		}
	}
	if ( $takeover && ! wpoptimizing_dropin_backup( 'page' ) ) {
		return array(
			'ok'   => false,
			'code' => 'nobackup',
		);
	}
	$content = wpoptimizing_page_cache_expected_dropin();
	if ( '' === $content ) {
		return array(
			'ok'   => false,
			'code' => 'notemplate',
		);
	}

	// Write the config sidecar BEFORE the drop-in so the drop-in never loads without it.
	wpoptimizing_page_cache_write_config( true );

	if ( ! wpopt_pc_write_atomic( WPOPT_ADVCACHE_DEST, $content ) ) {
		return array(
			'ok'   => false,
			'code' => 'writefail',
		);
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( WPOPT_ADVCACHE_DEST, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/wpconfig-block.php' );
	}
	$wp = wpoptimizing_wpconfig_apply_block( wpoptimizing_page_cache_block_name(), wpoptimizing_page_cache_block_body(), true );
	// Un define( 'WP_CACHE' ) que dejó otro plugin, fuera de nuestro bloque, haría avisar a PHP en
	// cada visita ahora que el nuestro va primero: se comenta, con copia (cache-leftovers.php).
	if ( ! empty( $wp['ok'] ) && function_exists( 'wpoptimizing_cache_leftovers_fix_wp_cache' ) ) {
		wpoptimizing_cache_leftovers_fix_wp_cache();
	}

	// Mark enabled so the purge hooks register on the next load (the sidecar's
	// 'active' already drives the drop-in itself).
	wpoptimizing_page_cache_set_enabled( true );
	delete_option( 'wpoptimizing_page_cache_taken_over' );

	return array(
		'ok'       => (bool) $wp['ok'],
		'code'     => $wp['ok'] ? 'done' : 'wpconfig-' . $wp['code'],
		'wpconfig' => $wp,
	);
}

/**
 * Uninstall the page cache: remove OUR advanced-cache.php (never a foreign one),
 * strip the WP_CACHE block, mark the sidecar inactive and clear the cache.
 *
 * @return array { ok:bool, code:string }
 */
function wpoptimizing_page_cache_uninstall() {
	// Mark inactive first so any in-flight request stops caching immediately.
	wpoptimizing_page_cache_write_config( false );
	// The server must stop serving the copies itself before they go.
	if ( function_exists( 'wpoptimizing_pc_static_apply' ) && function_exists( 'wpoptimizing_pc_static_enabled' ) && wpoptimizing_pc_static_enabled() ) {
		wpoptimizing_pc_static_apply( false );
	}
	// Without the page cache nothing tells Cloudflare's edge what it may keep: its rule goes too.
	if ( function_exists( 'wpoptimizing_cf_edge_disable' ) && wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_EDGE ) ) {
		wpoptimizing_cf_edge_disable();
	}
	wp_clear_scheduled_hook( 'wpoptimizing_page_cache_gc' );
	wp_clear_scheduled_hook( 'wpoptimizing_page_cache_text_refresh' );

	// On a network the drop-in and WP_CACHE serve every site: they go with the last one.
	$restored = '';
	$last     = true;
	if ( $last ) {
		$restored = wpoptimizing_page_cache_remove_dropin();
	}
	// Off before the last purge, and the preload emptied after it: a purge of an active cache
	// queues every page for rebuilding, and Mundotoro was left with 430 pages queued for a cache
	// that no longer existed (07/11/2026).
	wpoptimizing_page_cache_set_enabled( false );
	wpoptimizing_page_cache_clear();
	if ( function_exists( 'wpoptimizing_pc_preload_reset' ) ) {
		wpoptimizing_pc_preload_reset();
	}

	return array(
		'ok'       => true,
		'code'     => 'done',
		'restored' => $restored,
	);
}

/**
 * Remove OUR advanced-cache.php (never a foreign one) and the WP_CACHE block, and put back the
 * advanced-cache.php ours replaced, if any.
 *
 * @return string What was restored ('' = nothing).
 */
function wpoptimizing_page_cache_remove_dropin() {
	if ( file_exists( WPOPT_ADVCACHE_DEST ) && wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST ) ) {
		wp_delete_file( WPOPT_ADVCACHE_DEST );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( WPOPT_ADVCACHE_DEST, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/wpconfig-block.php' );
	}
	wpoptimizing_wpconfig_apply_block( wpoptimizing_page_cache_block_name(), wpoptimizing_page_cache_block_body(), false );
	// The advanced-cache.php ours replaced, if any, goes back where it was.
	return function_exists( 'wpoptimizing_dropin_restore' ) ? (string) wpoptimizing_dropin_restore( 'page' ) : '';
}

/**
 * Delete all cached page files under the page-cache directory.
 *
 * EN DOS TIEMPOS. Borrar fichero a fichero miles de páginas, en la petición que guardó una
 * entrada o activó un plugin, la hacía esperar a que terminara. Ahora cada carpeta de host se
 * RENOMBRA a una papelera (instantáneo y atómico: desde ese momento ningún visitante recibe
 * una copia vieja) y el borrado se hace al final de la petición, después de contestar cuando
 * el servidor lo permite. Lo que no se llegue a borrar lo recoge la limpieza diaria.
 *
 * El sidecar config.php y el index.php de la carpeta base no se tocan: borrar config.php con
 * las páginas apagaba la caché en silencio (el drop-in sale sin él).
 *
 * @return void
 */
function wpoptimizing_page_cache_clear() {
	wpoptimizing_page_cache_clear_network();
}

/**
 * Delete the cached pages of every site: the whole cache folder but its own files (see
 * wpoptimizing_page_cache_clear(), which this was). On a single install, the same thing.
 *
 * @return void
 */
function wpoptimizing_page_cache_clear_network() {
	$dir = WPOPT_PC_CACHE_DIR;
	if ( ! is_dir( $dir ) ) {
		return;
	}
	// The mark first, then the deletion: a render that started before this purge and writes
	// after it is stopped by the mark (wpopt_pc_purged_after()); one that writes before the
	// deletion loses its copy with the rest. In the root, which a full purge never moves.
	wpopt_pc_mark_purge( $dir, true );
	/**
	 * Fires once a full purge has left its mark, before it moves any cached copy away.
	 *
	 * @since 0.1.0
	 */
	do_action( 'wpoptimizing_page_cache_clearing' );
	// THE MOVE, UNDER THE LOCK, AND THE MARK AGAIN AFTER IT. Moving a host folder to the trash
	// also moves the «.purged» marks of the URL purges inside it: a render that started after
	// the mark above, and before a URL purge that came next, found only that older mark and
	// stored a page from before the URL purge (Codex, 08/11/2026). So the folders move while the
	// marks lock is held exclusively — no render can make its last check and publish meanwhile
	// (wpopt_pc_publish_copy()) — and the full-purge mark is written again after the last move,
	// still inside the lock: every render that started before the end of this purge sees a mark
	// later than its start and stores nothing; one that published before the lock is moved away.
	$lock   = wpopt_pc_marks_lock( $dir, LOCK_EX );
	$trash  = $dir . '/.trash-' . str_replace( '.', '', uniqid( '', true ) );
	$moved  = false;
	$failed = array();
	foreach ( (array) scandir( $dir ) as $name ) {
		$path = $dir . '/' . $name;
		// The hit counters are not cached pages: a purge on every edit would empty them.
		// Nor the sites' sidecars on a network.
		if ( '.' === $name || '..' === $name || 0 === strpos( (string) $name, '.trash-' ) || 0 === strpos( (string) $name, '.stats-' ) || 'sites' === $name || ! is_dir( $path ) ) {
			continue;
		}
		if ( ! $moved && ! @mkdir( $trash, 0755 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Inside our own cache dir.
			break;
		}
		if ( ! $moved ) {
			wpopt_pc_own( $trash ); // A root purge's trash must stay removable by the web server's sweep.
		}
		$moved = true;
		if ( ! @rename( $path, $trash . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move inside our own cache dir.
			$failed[] = $path;
		}
	}
	wpopt_pc_write_mark( $dir . '/.purged-all' );
	wpopt_pc_release_lock( $lock );
	// A host dir that would not move goes now, file by file, outside the lock: a render that
	// publishes into it from here on started after the mark above, so what it stores is current.
	// KEEPING WHAT IS LIVE. This walk used to delete everything, the «.purged» marks and the
	// «.publish.lock» files in use included: a URL purge made during the walk lost its mark, so a
	// render that started before it could store a page from before it; and a publication whose
	// lock vanished let a second writer of the same variant in (audit, 08/11/2026). Doing the walk
	// inside the exclusive lock would close that, but it would also stop every visitor's
	// publication for as long as thousands of files take to delete. Re-marking «.purged-all»
	// afterwards would still leave the window between the deletion of a mark and the new mark.
	// So the walk keeps the marks (they are not pages; the sweep removes them once old) and every
	// lock someone holds — nothing it removes is something another process relies on.
	foreach ( $failed as $path ) {
		wpoptimizing_page_cache_delete_tree( $path, false, true );
	}
	// Files directly in the base dir (none are pages today) keep the old file-by-file path.
	foreach ( (array) glob( $dir . '/index*.html*' ) as $f ) {
		wp_delete_file( $f );
	}
	if ( $moved && empty( $GLOBALS['wpoptimizing_page_cache_trash_queued'] ) ) {
		$GLOBALS['wpoptimizing_page_cache_trash_queued'] = true;
		add_action( 'shutdown', 'wpoptimizing_page_cache_empty_trash', 99 );
	}
	/**
	 * Fires after every cached page has been deleted (the preload queues a full rebuild).
	 *
	 * @since 0.1.0
	 */
	do_action( 'wpoptimizing_page_cache_cleared' );
}

/**
 * Delete a directory tree of cached pages.
 *
 * @param string $root      Directory.
 * @param bool   $self_too  Remove the directory itself as well.
 * @param bool   $keep_live A tree that is still in use (not in a trash): keep its purge marks
 *                          and the lock files another process holds.
 * @return void
 */
function wpoptimizing_page_cache_delete_tree( $root, $self_too = true, $keep_live = false ) {
	if ( ! is_dir( $root ) ) {
		return;
	}
	// Another request emptying the same trash can remove it between the check and the walk.
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $it as $file ) {
			if ( $file->isDir() ) {
				@rmdir( $file->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own cache dir; a folder that still holds a mark stays.
			} elseif ( $keep_live && in_array( $file->getFilename(), array( '.purged', '.purged-all' ), true ) ) {
				continue;
			} elseif ( $keep_live && '.lock' === substr( $file->getFilename(), -5 ) ) {
				wpoptimizing_page_cache_delete_unheld_lock( $file->getPathname() );
			} elseif ( WPOPT_PC_CONFIG !== $file->getPathname() ) {
				wp_delete_file( $file->getPathname() );
			}
		}
	} catch ( UnexpectedValueException $e ) {
		unset( $e ); // Already gone: nothing left to delete.
	}
	if ( $self_too ) {
		@rmdir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own cache dir.
	}
}

/**
 * Empty the trash a full purge left, after the response when the server allows it.
 *
 * @return void
 */
function wpoptimizing_page_cache_empty_trash() {
	if ( function_exists( 'fastcgi_finish_request' ) && ! headers_sent() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		@fastcgi_finish_request();
	}
	foreach ( (array) glob( WPOPT_PC_CACHE_DIR . '/.trash-*', GLOB_ONLYDIR ) as $trash ) {
		wpoptimizing_page_cache_delete_tree( $trash, true );
	}
}

/** Autoloaded 0/1 copy of 'enabled', the only page-cache value a visitor request reads. */
const WPOPTIMIZING_PAGE_CACHE_ON_OPTION = 'wpoptimizing_page_cache_on';

/**
 * Is our page cache live (enabled option set)? Cheap check used to gate the purge
 * hooks; the purge functions themselves no-op when the cache dir is empty.
 *
 * It runs on init, on every request. The settings array (wpoptimizing_page_cache) is not
 * autoloaded, so reading it here cost one SELECT per page view, cached or not. The answer now
 * lives in its own autoloaded flag, kept present at 0 rather than deleted (a missing option is a
 * query too). An install from before the flag existed reads the array once and creates it.
 *
 * @return bool
 */
function wpoptimizing_page_cache_is_active() {
	$flag = get_option( WPOPTIMIZING_PAGE_CACHE_ON_OPTION, null );
	if ( null === $flag ) {
		$opt  = (array) get_option( 'wpoptimizing_page_cache', array() );
		$flag = empty( $opt['enabled'] ) ? 0 : 1;
		add_option( WPOPTIMIZING_PAGE_CACHE_ON_OPTION, $flag, '', true );
	}
	return 1 === (int) $flag;
}

/**
 * Record whether the page cache is on, in the settings array and in the autoloaded flag.
 *
 * @param bool $enabled On or off.
 * @return void
 */
function wpoptimizing_page_cache_set_enabled( $enabled ) {
	$opt            = (array) get_option( 'wpoptimizing_page_cache', array() );
	$opt['enabled'] = (bool) $enabled;
	update_option( 'wpoptimizing_page_cache', $opt, false );
	update_option( WPOPTIMIZING_PAGE_CACHE_ON_OPTION, $enabled ? 1 : 0, true );
}

/**
 * Is this an install the page cache cannot serve correctly?
 *
 * A multisite network, in the free edition: one sidecar and one advanced-cache.php would serve
 * the whole network, and on a subdirectory network every other site's pages would be stored
 * under the shared host and never purged when they changed. Pro gives each site its own sidecar
 * and its own full purge (page-cache-network.php, 09/11/2026), with an active licence.
 *
 * @return bool
 */
function wpoptimizing_page_cache_unsupported() {
	$unsupported = function_exists( 'is_multisite' ) && is_multisite();
	/**
	 * Filters whether this install refuses the page cache. Kept for a host that must say no.
	 *
	 * @since 0.1.0
	 * @param bool $unsupported Default: true on a multisite network without Pro.
	 */
	return (bool) apply_filters( 'wpoptimizing_page_cache_unsupported', $unsupported );
}

/**
 * Delete every cached variant for a single URL (index*.html[.gz] under its dir).
 *
 * @param string $url Absolute URL.
 * @return void
 */
function wpoptimizing_page_cache_clear_url( $url ) {
	$parts = wp_parse_url( (string) $url );
	if ( empty( $parts['host'] ) ) {
		return;
	}
	$path = isset( $parts['path'] ) ? $parts['path'] : '/';
	// The drop-in files a page under the Host header, which carries the port when it is not the
	// default one: without it here, a site on «:8080» never had a page purged.
	$host = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	$file = wpopt_pc_cache_file( WPOPT_PC_CACHE_DIR, $host, $path, '', false );
	$dir  = dirname( $file );
	// Mark first, delete after (see wpoptimizing_page_cache_clear()). The pages of its
	// pagination check this same mark (wpopt_pc_purge_marks()), so one mark covers them all.
	// Never on a site without the cache folder: a purge asked for with the cache off must not
	// create folders for it.
	// The root is where the marks lock lives (wpopt_pc_marks_lock()): the same file the drop-in
	// locks while it publishes a page, so this mark and that publication are ordered.
	if ( is_dir( WPOPT_PC_CACHE_DIR ) ) {
		wpopt_pc_mark_purge( $dir, false, WPOPT_PC_CACHE_DIR );
	}
	// The copies and the twins the web server serves without PHP (static-…html).
	foreach ( array( 'index', 'static' ) as $prefix ) {
		// And its pagination: a new post shifts every /page/N/ of a listing by one, and those
		// pages live in their own folders under it. Never a rebuild lock: see the sweep.
		$files = array_merge( (array) glob( $dir . '/' . $prefix . '*.html*' ), (array) glob( $dir . '/page/*/' . $prefix . '*.html*' ) );
		foreach ( array_filter( $files ) as $f ) {
			if ( '.lock' !== substr( $f, -5 ) ) {
				wp_delete_file( $f );
			}
		}
	}
	/**
	 * Fires after one URL's cached copies have been deleted (the preload rebuilds it first).
	 *
	 * @since 0.1.0
	 * @param string $url Purged URL.
	 */
	do_action( 'wpoptimizing_page_cache_url_cleared', (string) $url );
}

/**
 * Partial purge on a content change: the URLs of wpoptimizing_content_purge_urls().
 *
 * @param int $post_id Post id.
 * @return void
 */
function wpoptimizing_page_cache_purge_post( $post_id ) {
	// Once per post and HOOK in a request (a full purge already queued covers every post). Not
	// once per post: the block editor saves through REST, which assigns the terms between
	// save_post and wp_after_insert_post, so only the second purge knows a category the post
	// just joined (Codex, 06/11/2026).
	static $done = array();
	$post_id     = (int) $post_id;
	$hook        = function_exists( 'current_filter' ) ? (string) current_filter() : '';
	if ( isset( $done[ $hook ][ $post_id ] ) || ! empty( $GLOBALS['wpoptimizing_page_cache_full_queued'] ) ) {
		return;
	}
	$done[ $hook ][ $post_id ] = true;
	if ( wpoptimizing_page_cache_is_shared_template( $post_id ) ) {
		wpoptimizing_page_cache_purge_all();
		return;
	}
	// The always-purged pages come with every post's list; a request that saves fifty posts
	// (a bulk edit, an import) clears them once per hook, not fifty times. Cloudflare, Varnish
	// and the hosting queue by URL, so they repeat nothing already.
	static $extra_done = array();
	$extras            = wpoptimizing_content_always_purge_urls();
	foreach ( wpoptimizing_content_purge_urls( $post_id ) as $url ) {
		if ( in_array( $url, $extras, true ) ) {
			if ( isset( $extra_done[ $hook ][ $url ] ) ) {
				continue;
			}
			$extra_done[ $hook ][ $url ] = true;
		}
		wpoptimizing_page_cache_clear_url( $url );
	}
}


/**
 * WooCommerce: a stock change (an order, a manual edit, a scheduled sale starting or ending)
 * changes the product page and every listing that shows its price or "out of stock" badge.
 *
 * @param mixed $product A WC_Product, or the product ids of a sale event.
 * @return void
 */
function wpoptimizing_page_cache_purge_product( $product ) {
	foreach ( wpoptimizing_content_product_ids( $product ) as $id ) {
		wpoptimizing_page_cache_purge_post( $id );
	}
}

/**
 * Full purge (delegates to clear()). Named separately so it reads well as a hook.
 *
 * @return void
 */
function wpoptimizing_page_cache_purge_all() {
	// The site that asked, kept for the end of the request: code that switch_to_blog()s to another
	// site and changes a menu there must purge that site, not the one the request started on.
	if ( function_exists( 'get_current_blog_id' ) ) {
		$GLOBALS['wpoptimizing_page_cache_full_sites'][ (int) get_current_blog_id() ] = true;
	}
	// Once per request, at the end of it: an import that creates a hundred terms, or an update
	// of ten plugins, asked for a hundred (or ten) full purges in a row.
	if ( empty( $GLOBALS['wpoptimizing_page_cache_full_queued'] ) ) {
		$GLOBALS['wpoptimizing_page_cache_full_queued'] = true;
		add_action( 'shutdown', 'wpoptimizing_page_cache_purge_all_now', 1 );
	}
}

/**
 * The full purge the request queued: this site's, or every site's when something network-wide
 * asked for it (wpoptimizing_page_cache_purge_code()).
 *
 * @return void
 */
function wpoptimizing_page_cache_purge_all_now() {
	$GLOBALS['wpoptimizing_page_cache_full_queued'] = false;
	$sites = array_keys( (array) ( $GLOBALS['wpoptimizing_page_cache_full_sites'] ?? array() ) );
	$GLOBALS['wpoptimizing_page_cache_full_sites'] = array();
	if ( ! empty( $GLOBALS['wpoptimizing_page_cache_network_queued'] ) ) {
		$GLOBALS['wpoptimizing_page_cache_network_queued'] = false;
		wpoptimizing_page_cache_clear_network();
		return;
	}
	$here = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
	if ( ! wpoptimizing_page_cache_network() || ! $sites ) {
		wpoptimizing_page_cache_clear();
		return;
	}
	// Each site in its own context, so what follows a purge (the preload, Cloudflare, the
	// hosting) is that site's too.
	foreach ( $sites as $id ) {
		if ( (int) $id === $here || ! function_exists( 'switch_to_blog' ) ) {
			wpoptimizing_page_cache_clear();
			continue;
		}
		switch_to_blog( (int) $id );
		wpoptimizing_page_cache_clear();
		restore_current_blog();
	}
}

/**
 * Code was updated (core, a plugin, a theme, a translation): every page of every site may change.
 *
 * @return void
 */
function wpoptimizing_page_cache_purge_code() {
	$GLOBALS['wpoptimizing_page_cache_network_queued'] = wpoptimizing_page_cache_network();
	wpoptimizing_page_cache_purge_all();
}

/**
 * A plugin switched on or off: this site's pages, or every site's when it was for the network.
 *
 * @param string $plugin       Plugin file.
 * @param bool   $network_wide Switched for the whole network.
 * @return void
 */
function wpoptimizing_page_cache_purge_plugin( $plugin = '', $network_wide = false ) {
	unset( $plugin );
	if ( $network_wide && wpoptimizing_page_cache_network() ) {
		$GLOBALS['wpoptimizing_page_cache_network_queued'] = true;
	}
	wpoptimizing_page_cache_purge_all();
}

/**
 * Purge the post behind a comment id (comment approved/changed).
 *
 * @param int $comment_id Comment id.
 * @return void
 */
function wpoptimizing_page_cache_purge_comment( $comment_id ) {
	if ( ! function_exists( 'get_comment' ) ) {
		return;
	}
	$comment = get_comment( $comment_id );
	if ( $comment && ! empty( $comment->comment_post_ID ) ) {
		wpoptimizing_page_cache_purge_post( (int) $comment->comment_post_ID );
	}
}

/**
 * Register purge hooks when the cache is live, and plug into the shared flush
 * system so "flush all" / post-cleanup also drop the page HTML.
 *
 * @return void
 */
function wpoptimizing_page_cache_register_purge_hooks() {
	if ( ! wpoptimizing_page_cache_is_active() ) {
		// On a network, updates and network-wide plugins are run from the network admin, whose
		// site may not cache at all: the other sites' pages must still go (review, 09/11/2026).
		if ( function_exists( 'wpoptimizing_page_cache_network_installed' ) && wpoptimizing_page_cache_network_installed() ) {
			add_action( 'upgrader_process_complete', 'wpoptimizing_page_cache_purge_code' );
			add_action( 'activated_plugin', 'wpoptimizing_page_cache_purge_plugin', 10, 2 );
			add_action( 'deactivated_plugin', 'wpoptimizing_page_cache_purge_plugin', 10, 2 );
		}
		return;
	}
	// Partial (content changes). wp_after_insert_post as well as save_post: the block editor
	// saves through REST, which assigns the categories and tags after save_post has fired, so
	// only the later hook sees the archives the post now appears in.
	wpoptimizing_content_register_captures();
	foreach ( wpoptimizing_content_purge_hooks() as $hook ) {
		add_action( $hook, 'wpoptimizing_page_cache_purge_post' );
	}
	// Only comments that appear on, or disappear from, the page (wpoptimizing_content_register_comment_hooks()).
	wpoptimizing_content_register_comment_hooks( 'wpoptimizing_page_cache_purge_comment' );

	// WooCommerce stock and scheduled sales.
	foreach ( wpoptimizing_content_product_hooks() as $hook ) {
		add_action( $hook, 'wpoptimizing_page_cache_purge_product' );
	}

	// A multi-currency plugin switched on or off changes which cookies the key reads.
	add_action( 'activated_plugin', 'wpoptimizing_page_cache_refresh_config' );
	add_action( 'deactivated_plugin', 'wpoptimizing_page_cache_refresh_config' );

	// Full (global changes that can affect every page) — including code: an updated or
	// (de)activated plugin or theme can change the markup of every page.
	foreach ( array( 'switch_theme', 'wp_update_nav_menu', 'customize_save_after', 'update_option_permalink_structure', 'created_term', 'edited_term', 'delete_term', 'update_option_sidebars_widgets' ) as $hook ) {
		add_action( $hook, 'wpoptimizing_page_cache_purge_all' );
	}
	// Code changes: on a network, an update or a plugin switched on for the whole network changes
	// every site's pages, not just the one whose request ran it.
	add_action( 'upgrader_process_complete', 'wpoptimizing_page_cache_purge_code' );
	add_action( 'activated_plugin', 'wpoptimizing_page_cache_purge_plugin', 10, 2 );
	add_action( 'deactivated_plugin', 'wpoptimizing_page_cache_purge_plugin', 10, 2 );

	if ( ! wp_next_scheduled( 'wpoptimizing_page_cache_gc' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpoptimizing_page_cache_gc' );
	}

	// Shared flush system: "flush all" and post-cleanup also drop the page HTML.
	add_action( 'wpoptimizing_flush_all', 'wpoptimizing_page_cache_purge_all' );
	add_action( 'wpoptimizing_after_cleanup', 'wpoptimizing_page_cache_purge_all' );
	add_filter( 'wpoptimizing_detected_caches', 'wpoptimizing_page_cache_register_detected' );
}
add_action( 'init', 'wpoptimizing_page_cache_register_purge_hooks' );

/**
 * Advertise our page cache to the shared cache detector (so the "Flush all cache"
 * UI and status list include it).
 *
 * @param array $found Detected caches.
 * @return array
 */
function wpoptimizing_page_cache_register_detected( $found ) {
	$found   = (array) $found;
	$found[] = array(
		'id'      => 'wpopt-page-cache',
		'label'   => __( 'Page cache (WPOptimizing)', 'wpoptimizing' ),
		'type'    => 'page',
		'purge'   => array(
			'kind' => 'function',
			'name' => 'wpoptimizing_page_cache_purge_all',
		),
		'managed' => false,
	);
	return $found;
}

/**
 * Version marker ("WPOPT_PAGE_CACHE vN") of the installed drop-in, or '' when the file is
 * missing or not ours. Pure-ish: reads the file head only.
 *
 * @return string
 */
function wpoptimizing_page_cache_installed_version() {
	if ( ! is_readable( WPOPT_ADVCACHE_DEST ) ) {
		return '';
	}
	$head = (string) file_get_contents( WPOPT_ADVCACHE_DEST, false, null, 0, 256 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own drop-in header.
	return preg_match( '/WPOPT_PAGE_CACHE v(\d+)/', $head, $m ) ? (string) $m[1] : '';
}

/**
 * May this request rewrite the files the page cache keeps outside its folder (advanced-cache.php,
 * wp-config.php, .htaccess)? Only someone who may install the drop-in from the screen
 * (wpoptimizing_user_can_manage_dropin(): on a network, a network administrator) or WP-CLI.
 * WP-Cron does not ask: it runs the refresh it was given (wpoptimizing_page_cache_schedule_refresh()).
 *
 * admin_init also runs for admin-ajax.php and admin-post.php, where nobody needs to be logged in:
 * without this, any visitor could make the site rewrite those files, as often as they liked.
 *
 * @return bool
 */
function wpoptimizing_page_cache_may_rewrite_files() {
	return wpoptimizing_page_cache_rewrite_allowed(
		defined( 'WP_CLI' ) && WP_CLI,
		function_exists( 'wpoptimizing_user_can_manage_dropin' )
			? wpoptimizing_user_can_manage_dropin()
			: ( is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( wpoptimizing_cap() ) )
	);
}

/**
 * The rule of wpoptimizing_page_cache_may_rewrite_files(), apart from where its answers come from. Pure.
 *
 * @param bool $cli Running under WP-CLI.
 * @param bool $can The current user may manage the drop-in.
 * @return bool
 */
function wpoptimizing_page_cache_rewrite_allowed( $cli, $can ) {
	return (bool) $cli || (bool) $can;
}

/**
 * Bring the drop-in up to date in a WP-Cron run of its own, once.
 *
 * For a request that may not do it itself (a visitor's, after an update): the visitor never
 * touches the files, and the refresh still happens without waiting for an administrator.
 *
 * @return void
 */
function wpoptimizing_page_cache_schedule_refresh() {
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpoptimizing_page_cache_refresh_dropin' ) ) {
		wp_schedule_single_event( time(), 'wpoptimizing_page_cache_refresh_dropin' );
	}
}
add_action( 'wpoptimizing_page_cache_refresh_dropin', 'wpoptimizing_page_cache_refresh_dropin' );

/**
 * Refresh the drop-in from an admin page load, for someone allowed to (see
 * wpoptimizing_page_cache_may_rewrite_files()); anyone else's request leaves the files alone.
 *
 * @return void
 */
function wpoptimizing_maybe_refresh_page_cache_dropin() {
	if ( ! wpoptimizing_page_cache_may_rewrite_files() ) {
		return;
	}
	wpoptimizing_page_cache_refresh_dropin();
}
add_action( 'admin_init', 'wpoptimizing_maybe_refresh_page_cache_dropin' );

/**
 * Refresh OUR installed page-cache drop-in (and its config sidecar) when the bundled
 * version is newer than the one on disk.
 *
 * Without this, a security or correctness fix in advanced-cache.php would only reach sites
 * that manually toggled the feature off and on again — the copy in wp-content is never
 * touched by a plugin update. Mirrors the object-cache / db.php refreshers: it only ever
 * rewrites a drop-in that carries our own marker.
 *
 * Checks no capability: its callers do (wpoptimizing_maybe_refresh_page_cache_dropin(), the
 * catch-up after an update) or run it from WP-Cron (wpoptimizing_page_cache_schedule_refresh()).
 *
 * @return void
 */
function wpoptimizing_page_cache_refresh_dropin() {
	// Not served here (a network, in the free edition): ours goes, and a site whose switch was left
	// on by Pro is switched off quietly. Nobody took its place, so no «replaced by» notice.
	if ( wpoptimizing_page_cache_unsupported() ) {
		if ( wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST ) ) {
			wpoptimizing_page_cache_uninstall();
		} elseif ( wpoptimizing_page_cache_is_active() ) {
			wpoptimizing_page_cache_set_enabled( false );
		}
		return;
	}
	if ( ! wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST ) ) {
		/*
		 * Y SI ESTABA PUESTA, OTRO SE LA HA QUEDADO. WP Rocket y FlyingPress escriben su
		 * advanced-cache.php sin mirar de quién es el sitio; desde ahí nuestra opción seguía
		 * «activa» y sus enganches purgaban un directorio que ya no servía nadie. Se apaga la
		 * nuestra (sin tocar el fichero ajeno ni WP_CACHE, que ahora usa el otro) y se dice.
		 */
		if ( wpoptimizing_page_cache_is_active() ) {
			$owner = file_exists( WPOPT_ADVCACHE_DEST ) && function_exists( 'wpoptimizing_dropin_owner' ) ? wpoptimizing_dropin_owner( WPOPT_ADVCACHE_DEST ) : array( 'name' => '' );
			wpoptimizing_page_cache_write_config( false );
			wpoptimizing_page_cache_set_enabled( false );
			update_option(
				'wpoptimizing_page_cache_taken_over',
				array(
					'name' => (string) ( $owner['name'] ?? '' ),
					'when' => time(),
				),
				false
			);
		}
		return; // Not installed, or someone else's drop-in: never touch it.
	}
	$installed = wpoptimizing_page_cache_installed_version();
	$current   = (string) WPOPT_ADVCACHE_VERSION;
	$content   = wpoptimizing_page_cache_expected_dropin();
	// A clone keeps the original site's paths in its drop-in and in its sidecar (base_dir, hosts).
	// Same version or not, a drop-in that is not what this install would write is rewritten.
	$foreign = '' !== $content && (string) file_get_contents( WPOPT_ADVCACHE_DEST ) !== $content; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- our own drop-in.
	if ( ! $foreign && '' !== $installed && version_compare( $installed, $current, '>=' ) ) {
		// Up to date: still rewrite the sidecar when it predates a config key we now rely on, or
		// when it is gone altogether (sites hit by the old clear() that deleted it).
		$file    = wpoptimizing_page_cache_config_file();
		$sidecar = '' !== $file && is_readable( $file ) ? include $file : null;
		if ( is_array( $sidecar ) && ( ! isset( $sidecar['allowed_hosts'] ) || ! isset( $sidecar['ttl'] ) || ( ! empty( $sidecar['active'] ) && ( wpoptimizing_page_cache_allowed_hosts() !== (array) $sidecar['allowed_hosts'] || wpoptimizing_page_cache_lang_codes() !== (array) ( $sidecar['lang_codes'] ?? array() ) ) ) ) ) {
			// The hosts too: a language given its own domain in WPML or Polylang is cached from the
			// next admin page load on, without saving the cache settings again. And its ?lang= code.
			wpoptimizing_page_cache_write_config( ! empty( $sidecar['active'] ) );
		} elseif ( ! is_array( $sidecar ) && wpoptimizing_page_cache_is_active() ) {
			wpoptimizing_page_cache_write_config( true );
		}
		return;
	}
	if ( '' === $content ) {
		return;
	}

	// Rewrite the sidecar first so the new drop-in never reads a config that lacks the keys
	// it expects, then swap the drop-in atomically and drop the pages built by the old one.
	$file    = wpoptimizing_page_cache_config_file();
	$sidecar = '' !== $file && is_readable( $file ) ? include $file : null;
	$network = false;
	if ( ! $network ) {
		wpoptimizing_page_cache_write_config( is_array( $sidecar ) ? ! empty( $sidecar['active'] ) : true );
	}

	if ( wpopt_pc_write_atomic( WPOPT_ADVCACHE_DEST, $content ) ) {
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( WPOPT_ADVCACHE_DEST, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// Entries keyed by the old scheme are not valid any more, on any site of a network.
		wpoptimizing_page_cache_clear_network();
	}
}

/**
 * The build of the plugin a sidecar is written by: its version and the time this file and the
 * engine were written to disk, which every update (from a ZIP too, where the version can stay the
 * same) changes. Cheap: two stats of files PHP has just loaded.
 *
 * @return string
 */
function wpoptimizing_page_cache_config_rev() {
	$version = defined( 'WPOPTIMIZING_VERSION' ) ? WPOPTIMIZING_VERSION : '';
	$engine  = __DIR__ . '/dropins/page-cache-engine.php';
	return substr( md5( $version . '|' . (int) @filemtime( __FILE__ ) . '|' . (int) @filemtime( $engine ) ), 0, 12 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * After an update, bring this site's drop-in and sidecar up to date on its first request.
 *
 * wpoptimizing_maybe_refresh_page_cache_dropin() only runs on admin pages, and rewrites only the
 * sidecar of the site whose dashboard was opened. On a network the other sites kept a sidecar
 * without the keys the new build relies on until someone saved their settings: after the update
 * of 10/11/2026 artprojectgroup.com never got 'text_files', and llms.txt and the sitemaps stayed
 * uncached. With no admin visit at all, not even the drop-in was replaced.
 *
 * So every request WordPress renders compares the sidecar's 'rev' with this build's
 * (wpoptimizing_page_cache_config_rev()), a single include that OPcache keeps in memory. Only
 * when they differ, once per site and update, the drop-in check runs and the sidecar is rewritten
 * from this site's own settings. Hits served from disk never get here, and need not.
 *
 * @return void
 */
function wpoptimizing_page_cache_sidecar_catch_up() {
	if ( ! wpoptimizing_page_cache_is_active() || ! wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST ) ) {
		return;
	}
	$file = wpoptimizing_page_cache_config_file();
	if ( '' === $file || ! is_readable( $file ) ) {
		return;
	}
	$sidecar = include $file;
	if ( ! is_array( $sidecar ) || ( isset( $sidecar['rev'] ) && wpoptimizing_page_cache_config_rev() === (string) $sidecar['rev'] ) ) {
		return;
	}
	// The drop-in, wp-config.php and .htaccess are rewritten only by someone allowed to, or by
	// WP-Cron: a visitor's request asks for that run and only writes the sidecar, which lives in
	// the cache folder. Once the sidecar carries this build's 'rev', no later request gets here.
	if ( wpoptimizing_page_cache_may_rewrite_files() ) {
		wpoptimizing_page_cache_refresh_dropin();
	} else {
		wpoptimizing_page_cache_schedule_refresh();
	}
	// The refresher may have rewritten it already, or switched the cache off: read it again.
	$sidecar = is_readable( $file ) ? include $file : null;
	if ( is_array( $sidecar ) && ( ! isset( $sidecar['rev'] ) || wpoptimizing_page_cache_config_rev() !== (string) $sidecar['rev'] ) && wpoptimizing_page_cache_is_active() ) {
		wpoptimizing_page_cache_write_config( ! empty( $sidecar['active'] ) );
	}
}
add_action( 'wp_loaded', 'wpoptimizing_page_cache_sidecar_catch_up' );

/**
 * Another plugin has just been deactivated: put wp-config.php right if it touched WP_CACHE.
 *
 * LiteSpeed Cache, on deactivation, removes every `define( 'WP_CACHE', … );` in wp-config.php
 * (Activation::manage_wp_cache_const( false ) in its src/activation.cls.php), including the one
 * in our block and the foreign one we had guarded with the old `defined( 'WP_CACHE' ) ||` form.
 * That left `defined( 'WP_CACHE' ) ||` joined to the next statement (DB_NAME's define), which
 * stops running as soon as WP_CACHE is defined — and our page cache would also stop silently.
 * Seen on multisitio-limpio.local on 10/11/2026, about to happen on a real network. So: first the
 * dangling operators go, then our block is written again when our drop-in is installed.
 *
 * @param string $plugin Deactivated plugin file.
 * @return void
 */
function wpoptimizing_page_cache_after_plugin_deactivated( $plugin ) {
	if ( false !== strpos( (string) $plugin, 'wpoptimizing' ) ) {
		return; // Our own teardown has its own path (lifecycle.php).
	}
	// A plugin can deactivate itself (or another) on any request, a visitor's included: wp-config.php
	// is only edited for someone who may manage the drop-in, or from WP-CLI.
	if ( ! wpoptimizing_page_cache_may_rewrite_files() ) {
		return;
	}
	if ( function_exists( 'wpoptimizing_load_lib' ) ) {
		wpoptimizing_load_lib( 'includes/cache-leftovers.php' );
		wpoptimizing_load_lib( 'includes/wpconfig-block.php' );
	}
	if ( function_exists( 'wpoptimizing_cache_leftovers_repair_wp_cache' ) ) {
		wpoptimizing_cache_leftovers_repair_wp_cache();
	}
	if ( ! defined( 'WPOPT_ADVCACHE_DEST' ) || ! wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST ) || ! function_exists( 'wpoptimizing_wpconfig_apply_block' ) ) {
		return;
	}
	$name = wpoptimizing_page_cache_block_name();
	$body = wpoptimizing_page_cache_block_body();
	$path = wpoptimizing_wpconfig_path();
	$now  = '' !== $path && is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	// Adding a block that is there does nothing, even with its define gone: it is taken out and
	// written again, whole.
	if ( '' !== $now && false === strpos( $now, wpoptimizing_wpconfig_managed_block( $name, $body ) ) ) {
		wpoptimizing_wpconfig_apply_block( $name, $body, false );
		wpoptimizing_wpconfig_apply_block( $name, $body, true );
	}
}
add_action( 'deactivated_plugin', 'wpoptimizing_page_cache_after_plugin_deactivated', 20 );

/**
 * Say, on WPOptimizing's screens, that another plugin took the page cache's place.
 *
 * @return void
 */
function wpoptimizing_page_cache_taken_over_notice() {
	$taken = get_option( 'wpoptimizing_page_cache_taken_over' );
	if ( ! is_array( $taken ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || false === strpos( (string) $screen->id, 'wpoptimizing' ) ) {
		return;
	}
	// Seen once: switching the page cache on again clears it too (wpoptimizing_page_cache_install()).
	delete_option( 'wpoptimizing_page_cache_taken_over' );
	$name = '' !== (string) ( $taken['name'] ?? '' ) ? (string) $taken['name'] : __( 'another plugin', 'wpoptimizing' );
	printf(
		'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: %s: plugin name, e.g. WP Rocket. */
				__( '%s replaced WPOptimizing\'s page cache file, so pages are now cached by it. WPOptimizing\'s page cache has been switched off so the two do not get in each other\'s way.', 'wpoptimizing' ),
				$name
			)
		)
	);
}
add_action( 'admin_notices', 'wpoptimizing_page_cache_taken_over_notice' );


/**
 * A site that comes from Pro to the free edition: what Pro left that the free edition has no code
 * to run, taken out at the next admin page load, so nothing stays half on. The settings
 * themselves stay in the option, ignored (the sidecar is built without them), for if Pro comes
 * back; only the serve-without-PHP switch goes off, because its rules go.
 *
 *  - The serve-without-PHP block in .htaccess (backed up first) and the cache folder's own rules:
 *    without them the web server never answers from the cache folder.
 *  - The scheduled events of the preload and of the twins' hourly sweep: nothing handles them.
 *  - With those rules gone, the cached copies, once. Copies stored by Pro (minified, with preload
 *    hints, with a Brotli twin) go anyway: the free edition's drop-in is not Pro's, and
 *    wpoptimizing_maybe_refresh_page_cache_dropin() purges everything when it rewrites it.
 *
 * In Pro, page-cache-static.php and the preload are there and take their own things out
 * (wpoptimizing_page_cache_license_sync()), so this does nothing.
 *
 * @return void
 */
function wpoptimizing_page_cache_pro_leftovers() {
	if ( function_exists( 'wpoptimizing_pc_static_apply' ) ) {
		return;
	}
	// admin_init runs for admin-ajax.php and admin-post.php without a session too: .htaccess is
	// only edited for someone who may manage the drop-in.
	if ( ! wpoptimizing_page_cache_may_rewrite_files() ) {
		return;
	}
	foreach ( array( 'wpoptimizing_page_cache_gc_hourly', 'wpoptimizing_page_cache_preload', 'wpoptimizing_page_cache_text_refresh' ) as $hook ) {
		if ( wp_next_scheduled( $hook ) ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
	$opt = (array) get_option( 'wpoptimizing_page_cache', array() );
	if ( empty( $opt['static'] ) ) {
		return;
	}
	if ( ! function_exists( 'get_home_path' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	$path    = get_home_path() . '.htaccess';
	$current = is_readable( $path ) ? (string) file_get_contents( $path ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file.
	$new     = wpoptimizing_pc_static_strip( $current );
	if ( $new !== $current ) {
		if ( function_exists( 'wpoptimizing_load_lib' ) ) {
			wpoptimizing_load_lib( 'includes/cleanup/backup-store.php' );
		}
		// No backup, no edit: the block stays and this is tried again later. Not on the next admin
		// page: every try makes a backup before it writes, and a write that keeps failing filled the
		// Backups tab with copies of the same .htaccess, one per page load. A day between tries.
		if ( get_transient( 'wpoptimizing_pc_leftovers_retry' ) ) {
			return;
		}
		if ( ! wp_is_writable( $path ) || ! function_exists( 'wpoptimizing_backup_file_first' ) || ! wpoptimizing_backup_file_first( $path, 'htaccess' ) || ! wpopt_pc_write_atomic( $path, $new ) ) {
			set_transient( 'wpoptimizing_pc_leftovers_retry', 1, DAY_IN_SECONDS );
			return;
		}
		delete_transient( 'wpoptimizing_pc_leftovers_retry' );
	}
	if ( file_exists( WPOPT_PC_CACHE_DIR . '/.htaccess' ) ) {
		wp_delete_file( WPOPT_PC_CACHE_DIR . '/.htaccess' );
	}
	$opt['static'] = false;
	update_option( 'wpoptimizing_page_cache', $opt, false );
	if ( wpoptimizing_page_cache_is_active() ) {
		wpoptimizing_page_cache_write_config( true );
		wpoptimizing_page_cache_clear();
	}
}
add_action( 'admin_init', 'wpoptimizing_page_cache_pro_leftovers', 5 );

/**
 * Status snapshot for the admin UI.
 *
 * @return array
 */
function wpoptimizing_page_cache_status() {
	$installed = file_exists( WPOPT_ADVCACHE_DEST );
	return array(
		'installed'   => $installed,
		'ours'        => $installed && wpoptimizing_page_cache_is_ours( WPOPT_ADVCACHE_DEST ),
		'wp_cache'    => defined( 'WP_CACHE' ) && WP_CACHE,
		'coexistence' => wpoptimizing_page_cache_coexistence(),
		'unwritable'  => wpoptimizing_page_cache_unwritable(),
	);
}

/**
 * «Purge this page» in the WPOptimizing toolbar menu, on the front end, while the page cache is
 * on: after editing a widget or a block a page shares, the person fixes what they are looking
 * at without emptying the whole cache.
 *
 * @param WP_Admin_Bar $bar Toolbar.
 * @return void
 */
function wpoptimizing_page_cache_admin_bar_purge( $bar ) {
	if ( is_admin() || ! wpoptimizing_page_cache_is_active() || ! current_user_can( wpoptimizing_cap() ) || ! $bar->get_node( 'wpoptimizing' ) ) {
		return;
	}
	$scheme = is_ssl() ? 'https' : 'http';
	$host   = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
	$url    = $scheme . '://' . $host . strtok( $uri, '?' );
	$bar->add_node(
		array(
			'parent' => 'wpoptimizing',
			'id'     => 'wpoptimizing-purge-page',
			'title'  => '<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>' . esc_html__( 'Purge this page', 'wpoptimizing' ),
			'href'   => wp_nonce_url(
				add_query_arg(
					array(
						'action' => 'wpoptimizing_purge_page',
						'url'    => rawurlencode( $url ),
					),
					admin_url( 'admin-post.php' )
				),
				'wpoptimizing_purge_page'
			),
			'meta'   => array( 'title' => __( 'Delete the cached copy of this page here, at Cloudflare and in Varnish', 'wpoptimizing' ) ),
		)
	);
}
add_action( 'admin_bar_menu', 'wpoptimizing_page_cache_admin_bar_purge', 101 );

/**
 * Purge one page (its copies here, its pagination, Cloudflare and Varnish), then go back to it.
 *
 * @return void
 */
function wpoptimizing_page_cache_purge_page_action() {
	check_admin_referer( 'wpoptimizing_purge_page' );
	if ( ! current_user_can( wpoptimizing_cap() ) ) {
		wp_die( esc_html__( 'You do not have permission to manage the page cache.', 'wpoptimizing' ), '', array( 'response' => 403 ) );
	}
	$url  = isset( $_GET['url'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['url'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- esc_url_raw() is the sanitizer.
	$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	// Only a page of this site: anything else goes home and purges nothing.
	if ( '' === $url || strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) !== strtolower( $home ) ) {
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}
	wpoptimizing_page_cache_purge_everywhere( array( $url ) );
	wp_safe_redirect( $url );
	exit;
}

/**
 * Purge some pages on purpose (the toolbar's «Purge this page», `wp wpoptimizing purge <url>`):
 * with the always-purged pages, in our cache, at Cloudflare, in Varnish and in the hosting's
 * cache, worked out once. The CLI used to empty only our copy (Codex, 08/11/2026).
 *
 * @param string[] $urls Absolute URLs of this site.
 * @return string[] The URLs purged.
 */
function wpoptimizing_page_cache_purge_everywhere( array $urls ) {
	$urls = wpoptimizing_content_with_always_purge( array_values( array_filter( array_map( 'strval', $urls ) ) ) );
	foreach ( $urls as $one ) {
		wpoptimizing_page_cache_clear_url( $one );
	}
	if ( function_exists( 'wpoptimizing_cf_purge_urls' ) && function_exists( 'wpoptimizing_cf_active' ) && wpoptimizing_cf_active( WPOPTIMIZING_CF_BIT_CONTENT ) ) {
		wpoptimizing_cf_purge_urls( $urls );
	}
	if ( function_exists( 'wpoptimizing_varnish_purge_on' ) && wpoptimizing_varnish_purge_on() ) {
		wpoptimizing_varnish_purge( $urls );
	}
	// With our page cache off, clearing a URL tells nobody: the hosting hears it from here.
	if ( function_exists( 'wpoptimizing_host_purge_request' ) ) {
		wpoptimizing_host_purge_request( 'url', $urls );
	}
	return $urls;
}
add_action( 'admin_post_wpoptimizing_purge_page', 'wpoptimizing_page_cache_purge_page_action' );
