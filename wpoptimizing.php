<?php
/**
 * Plugin Name: WPOptimizing
 * Description: All the WPO your WordPress needs in a single plugin: it measures where the time goes, speeds up the site with caching and front-end and image optimization, and cleans up leftovers knowing who owns each one.
 * Version:     0.1.0
 * Author:      Art Project Group
 * Author URI:  https://wpoptimizing.com
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 11.2
 * License:     GNU General Public License v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: wpoptimizing
 * Domain Path: /languages
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPTIMIZING_VERSION' ) ) {
	define( 'WPOPTIMIZING_VERSION', '0.1.0' );
}
if ( ! defined( 'WPOPTIMIZING_FILE' ) ) {
	define( 'WPOPTIMIZING_FILE', __FILE__ );
}

/**
 * Declare WooCommerce compatibility. WPOptimizing is HPOS-safe: it never reads or
 * writes order data directly (orders under High-Performance Order Storage live in
 * their own tables, outside the generic wp_posts/postmeta cleanup, and the opt-in
 * CPT/taxonomy deletes only ever touch types that are NOT registered). It adds no
 * cart/checkout UI. Declaring this lets WooCommerce recognise the plugin and keeps
 * the site off the "incompatible plugin" warning when HPOS is enabled.
 *
 * @return void
 */
function wpoptimizing_declare_wc_compatibility() {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPOPTIMIZING_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WPOPTIMIZING_FILE, true );
	}
}
add_action( 'before_woocommerce_init', 'wpoptimizing_declare_wc_compatibility' );
if ( ! defined( 'WPOPTIMIZING_BASENAME' ) ) {
	define( 'WPOPTIMIZING_BASENAME', plugin_basename( __FILE__ ) );
}
if ( ! defined( 'WPOPTIMIZING_EXPECTED_DIR' ) ) {
	define( 'WPOPTIMIZING_EXPECTED_DIR', 'wpoptimizing' );
}
if ( ! defined( 'WPOPTIMIZING_EXPECTED_PLUGIN' ) ) {
	define( 'WPOPTIMIZING_EXPECTED_PLUGIN', WPOPTIMIZING_EXPECTED_DIR . '/wpoptimizing.php' );
}

/**
 * Make the plugin's bundled translations loadable.
 *
 * WordPress' just-in-time loader only auto-discovers translations in the GLOBAL
 * wp-content/languages directory; the .mo / .l10n.php files shipped inside the
 * plugin's own /languages folder are otherwise ignored and the UI stays in
 * English. The `lang_dir_for_domain` filter (WordPress 6.6+) points the loader at
 * the bundled folder when no global language pack exists — the modern,
 * Plugin-Check-friendly replacement for the discouraged load_plugin_textdomain().
 *
 * @param string $path   Resolved language directory, or '' when none was found.
 * @param string $domain Text domain being loaded.
 * @return string
 */
function wpoptimizing_lang_dir_for_domain( $path, $domain ) {
	if ( 'wpoptimizing' === $domain && ! $path ) {
		return plugin_dir_path( WPOPTIMIZING_FILE ) . 'languages/';
	}
	return $path;
}
add_filter( 'lang_dir_for_domain', 'wpoptimizing_lang_dir_for_domain', 10, 2 );

/**
 * Always read OUR translations from the PHP format.
 *
 * The package ships languages/*.l10n.php and no .mo, because the two are the same catalogue
 * twice and the .mo half was 2.8 MB. That is safe by default — core tries the PHP file and
 * falls back to the .mo — but not unconditionally. Core builds the candidate list like this:
 *
 *     if ( 'mo' !== $preferred_format ) { $files[] = <the .l10n.php>; }
 *     $files[] = $mofile;
 *
 * So on a site where something filters `translation_file_format` to 'mo' — a translation
 * plugin, or somebody debugging — the PHP file is never even tried, and with no .mo to fall
 * back to the whole plugin would silently revert to English. The filter is given the text
 * domain, so this pins the format for ours and leaves every other plugin's choice alone.
 *
 * Remove this ONLY together with shipping the .mo files again.
 *
 * @since 0.1.0
 * @param string $format Preferred format ('php' or 'mo').
 * @param string $domain Text domain being loaded.
 * @return string
 */
function wpoptimizing_translation_file_format( $format, $domain ) {
	return ( 'wpoptimizing' === $domain ) ? 'php' : $format;
}
add_filter( 'translation_file_format', 'wpoptimizing_translation_file_format', 10, 2 );


/*
 * Reparación del paquete de actualización. SIEMPRE cargado, y su filtro se registra dentro
 * del propio fichero: `upgrader_source_selection` es un filtro de WordPress que recorre la
 * actualización de CUALQUIER plugin, y aquí había un add_filter incondicional apuntando a una
 * función que sólo existía con el motor cargado. En una petición REST `is_admin()` es falso,
 * así que se lanzaba un TypeError y se rompía la actualización de todo el sitio.
 *
 * Sólo Pro: el build de wordpress.org se actualiza desde el directorio, que entrega el ZIP con
 * la carpeta correcta, así que Lite no tiene nada que reparar.
 */

$GLOBALS['wpoptimizing_info'] = array( // WP-CLI carga los plugins dentro de una función.
	'plugin'     => 'WPOptimizing',
	'plugin_url' => 'https://wpoptimizing.com',
);

/**
 * Absolute data directory for a given blog.
 *
 * Multisite: scan results, backups and reports are isolated per subsite so a
 * scan on one site never overwrites another's data. The main site keeps the
 * root folder for backward compatibility; subsites live under /sites/{blog_id}.
 * The encrypted remote dataset (db.enc/files.enc) and the runtime attribution
 * map stay shared network-wide on purpose — attribution is identical across
 * sites that run the same plugins.
 *
 * @param int|null $blog_id Blog id, or null for the current blog.
 * @return string
 */
function wpoptimizing_blog_data_dir( $blog_id = null ) {
	$base = WP_CONTENT_DIR . '/wpoptimizing';
	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		$blog_id = $blog_id ? (int) $blog_id : (int) get_current_blog_id();
		if ( ! function_exists( 'is_main_site' ) || ! is_main_site( $blog_id ) ) {
			$base .= '/sites/' . $blog_id;
		}
	}
	return $base;
}

/**
 * Count scan rows by status for a given site's data directory.
 * Reads the per-blog wp-scan-db.csv + wp-scan-fs.csv directly (no DB queries,
 * no engine classes). Shared by the network dashboard and the REST API.
 *
 * @param string $dir Absolute data directory for the site.
 * @return array{total:int,orphan:int,pending:int,size:int,reclaimable:int}
 */
function wpoptimizing_network_count_dir( $dir ) {
	$out = array(
		'total'       => 0,
		'orphan'      => 0,
		'pending'     => 0,
		'size'        => 0,
		'reclaimable' => 0,
	);
	foreach ( array( '/wp-scan-db.csv', '/wp-scan-fs.csv' ) as $file ) {
		$path = $dir . $file;
		if ( ! is_readable( $path ) ) {
			continue;
		}
		$fh = wpoptimizing_scan_read_handle( $path ); // decrypts if encrypted at rest (cross-blog: wp_salt key is network-stable)
		if ( ! $fh ) {
			continue;
		}
		$first = fgets( $fh );
		if ( false === $first ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			continue;
		}
		$delims = array(
			','  => substr_count( $first, ',' ),
			"\t" => substr_count( $first, "\t" ),
			';'  => substr_count( $first, ';' ),
		);
		arsort( $delims );
		$delim  = (string) array_key_first( $delims );
		$header = str_getcsv( $first, $delim, '"', '\\' );
		$hmap   = $header ? array_flip( array_map( 'strtolower', array_map( 'trim', $header ) ) ) : array();
		$si     = $hmap['project_status'] ?? $hmap['proyecto_estado'] ?? $hmap['status'] ?? null;
		$bi     = $hmap['size_bytes'] ?? $hmap['tamano'] ?? $hmap['size'] ?? null;
		while ( ( $row = fgetcsv( $fh, 0, $delim, '"', '\\' ) ) !== false ) {
			++$out['total'];
			$st   = null !== $si ? strtolower( trim( (string) ( $row[ $si ] ?? '' ) ) ) : '';
			$size = null !== $bi ? (int) ( $row[ $bi ] ?? 0 ) : 0;
			if ( 'orphan' === $st ) {
				++$out['orphan'];
				$out['reclaimable'] += $size; // orphans are safe to delete
			} elseif ( '' === $st || 'none' === $st ) {
				++$out['pending'];
			}
			$out['size'] += $size;
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
	return $out;
}

/**
 * Path and URL constants.
 */
if ( ! defined( 'WPOPT_DIR' ) ) {
	define( 'WPOPT_DIR', wpoptimizing_blog_data_dir() );
}
if ( ! defined( 'WPOPT_BACKUP_DIR' ) ) {
	define( 'WPOPT_BACKUP_DIR', WPOPT_DIR . '/backups' );
}

/**
 * Refresh the installed runtime mu-plugin when the plugin ships a newer one.
 *
 * It is installed on activation and was never looked at again, so a site that simply UPDATED
 * the plugin kept running whatever copy it activated with — and a fix to the mu-plugin looked
 * deployed while reaching nobody. The drop-ins have had a version signature and an update offer
 * for exactly this reason; this file had neither.
 *
 * No prompt: unlike the drop-ins, the user never chose to install this one, so keeping it
 * current is maintenance rather than a decision. A file that is not ours is never touched.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_ensure_mu_plugin_current() {
	if ( ! defined( 'WPOPT_MUPL_DEST' ) || ! file_exists( WPOPT_MUPL_DEST ) ) {
		return; // Not installed: activation owns that, and a site that removed it meant to.
	}

	$current = (string) WPOPT_MUPL_VERSION;
	// Fast path: an autoloaded option, so the usual admin request pays an array lookup.
	if ( (string) get_option( 'wpoptimizing_mupl_version', '' ) === $current ) {
		return;
	}

	$head = (string) file_get_contents( WPOPT_MUPL_DEST, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small mu-plugin header.
	if ( false === strpos( $head, 'APG_WPOPT_RUNTIME' ) ) {
		return; // Somebody else's file under our name: leave it alone.
	}

	if ( preg_match( '/APG_WPOPT_RUNTIME\s+v(\d+)/', $head, $m ) && (string) $m[1] === $current ) {
		update_option( 'wpoptimizing_mupl_version', $current, false ); // Already current; stop re-reading.
		return;
	}

	if ( file_exists( WPOPT_MUPL_SRC ) && wpoptimizing_atomic_install_file( WPOPT_MUPL_SRC, WPOPT_MUPL_DEST ) ) {
		update_option( 'wpoptimizing_mupl_version', $current, false );
		delete_option( 'wpopt_rtmap_sig' ); // Capture again on the next boot with the new code.
		delete_option( 'wpopt_rtmap_settings_sig' );
	}
}
add_action( 'admin_init', 'wpoptimizing_ensure_mu_plugin_current' );

/** Unified CSVs */
if ( ! defined( 'WPOPT_CSV_DB' ) ) {
	define( 'WPOPT_CSV_DB', WPOPT_DIR . '/wpoptimizing-impact-slugs-db.csv' );
}
if ( ! defined( 'WPOPT_CSV_FS_CODE' ) ) {
	define( 'WPOPT_CSV_FS_CODE', WPOPT_DIR . '/wpoptimizing-impact-files.csv' );
}
if ( ! defined( 'WPOPT_CSV_SCAN_DB' ) ) {
	define( 'WPOPT_CSV_SCAN_DB', WPOPT_DIR . '/wp-scan-db.csv' );
}
if ( ! defined( 'WPOPT_CSV_SCAN_FS' ) ) {
	define( 'WPOPT_CSV_SCAN_FS', WPOPT_DIR . '/wp-scan-fs.csv' );
}
if ( ! defined( 'WPOPT_CSV_SCAN_EMPTY' ) ) {
	define( 'WPOPT_CSV_SCAN_EMPTY', WPOPT_DIR . '/wp-scan-empty.csv' );
}
/** Runtime map mu-plugin paths. */
if ( ! defined( 'WPOPT_MUPL_SRC' ) ) {
	define( 'WPOPT_MUPL_SRC', plugin_dir_path( __FILE__ ) . 'includes/mu-plugin/apg-wpoptimizing-runtime.php' );
}
if ( ! defined( 'WPOPT_MUPL_DEST' ) ) {
	define( 'WPOPT_MUPL_DEST', WPMU_PLUGIN_DIR . '/apg-wpoptimizing-runtime.php' );
}

/** Object-cache drop-in paths (Cache tab). */
if ( ! defined( 'WPOPT_DROPIN_SRC' ) ) {
	define( 'WPOPT_DROPIN_SRC', plugin_dir_path( __FILE__ ) . 'includes/dropins/object-cache.php' );
}
if ( ! defined( 'WPOPT_DROPIN_DEST' ) ) {
	define( 'WPOPT_DROPIN_DEST', WP_CONTENT_DIR . '/object-cache.php' );
}
if ( ! defined( 'WPOPT_DB_DROPIN_SRC' ) ) {
	define( 'WPOPT_DB_DROPIN_SRC', plugin_dir_path( __FILE__ ) . 'includes/dropins/db.php' );
}
if ( ! defined( 'WPOPT_DB_DROPIN_DEST' ) ) {
	define( 'WPOPT_DB_DROPIN_DEST', WP_CONTENT_DIR . '/db.php' );
}
if ( ! defined( 'WPOPT_CACHE_DIR' ) ) {
	define( 'WPOPT_CACHE_DIR', WP_CONTENT_DIR . '/cache' );
}
if ( ! defined( 'WPOPT_CACHE_CONFIG' ) ) {
	define( 'WPOPT_CACHE_CONFIG', WPOPT_CACHE_DIR . '/wpopt-cache-config.php' );
}
// Page cache (advanced-cache.php drop-in + engine + precompiled sidecar).
if ( ! defined( 'WPOPT_ADVCACHE_SRC' ) ) {
	define( 'WPOPT_ADVCACHE_SRC', plugin_dir_path( __FILE__ ) . 'includes/dropins/advanced-cache.php' );
}
if ( ! defined( 'WPOPT_ADVCACHE_DEST' ) ) {
	define( 'WPOPT_ADVCACHE_DEST', WP_CONTENT_DIR . '/advanced-cache.php' );
}
if ( ! defined( 'WPOPT_PC_ENGINE' ) ) {
	define( 'WPOPT_PC_ENGINE', plugin_dir_path( __FILE__ ) . 'includes/dropins/page-cache-engine.php' );
}
if ( ! defined( 'WPOPT_PC_CACHE_DIR' ) ) {
	define( 'WPOPT_PC_CACHE_DIR', WPOPT_CACHE_DIR . '/wpopt-page' );
}
if ( ! defined( 'WPOPT_PC_CONFIG' ) ) {
	define( 'WPOPT_PC_CONFIG', WPOPT_PC_CACHE_DIR . '/config.php' );
}
// Bump in lockstep with the "WPOPT_PAGE_CACHE vN" signature in advanced-cache.php.
if ( ! defined( 'WPOPT_ADVCACHE_VERSION' ) ) {
	// v5: separate copy for mobile devices when the sidecar asks for it.
	// v6: wpoptimizing_page_cache_html filter on the page before it is stored.
	// v7: Cloudflare-CDN-Cache-Control on pages served from disk when HTML is cached at the edge.
	// v8: private/no-store responses are neither stored nor kept at the edge.
	// v9: a buffer flushed or cleaned before the end of the page is not stored (truncated pages).
	// v10: inert when run from another wp-content.
	// v18: a render cut short (client gone, time limit, fatal) is not stored; text files, one copy.
	define( 'WPOPT_ADVCACHE_VERSION', '18' );
}
// Bump in lockstep with the "WPOPT_OBJECT_CACHE vN" signature in the drop-in
// template so installed sites are offered an update when the drop-in changes.
if ( ! defined( 'WPOPT_DROPIN_VERSION' ) ) {
	// v6: panic switch (WPOPT_DISABLE) and stand-down when the plugin is gone.
	// v9: the per-request analytics write no longer takes a blocking lock on the visitor's
	// path. Installed sites MUST pick this up — on v8 and earlier every request ended in a
	// serialised read-modify-write of one JSON file, so concurrent requests queued on it.
	// v10: on a multisite with no persistent backend (or WPOPT_DISABLE) the drop-in no longer
	// fatals the network: it tells WordPress the truth at ms_loaded instead of during init.
	// v11: the analytics write has memory set aside to run in after an out-of-memory fatal.
	// v12: and raises the memory limit first, which is what actually lets it run (measured).
	// v13: Redis read timeout, WP_REDIS_* constants, no EXISTS per miss, flush of the store we
	// switch to after an outage (no stale data), wp_suspend_cache_addition() honoured.
	// v14: flush_group really empties the group (prefix delete), igbinary when available, and
	// Redis SCAN patterns escaped.
	// v15: APCu never chosen automatically (and no persistent cache on the CLI when it is), a
	// memcached flush really invalidates (generation in every key) and long TTLs survive, and
	// the key salt is bound to the install so a staging clone stops sharing production's keys.
	define( 'WPOPT_DROPIN_VERSION', '16' );
}
// Same idea for the db.php (query-dedup) drop-in: "WPOPT_DB_DROPIN vN".
// Bump in lockstep with the "APG_WPOPT_RUNTIME vN" signature in the mu-plugin.
if ( ! defined( 'WPOPT_MUPL_VERSION' ) ) {
	// v3: el escaneo de ajustes registrados ya no corre en cada petición de admin, y la
	// deduplicación no descifra el mapa entero para preguntar si una clave ya está. Los sitios
	// instalados DEBEN coger esto: en la v2 cada petición de admin —heartbeat y admin-ajax
	// incluidos— descifraba y parseaba el mapa completo.
	// v5: tras un fatal por memoria agotada, el volcado del mapa no lo intenta (moriría otra vez).
	// v6: assets generated into uploads/ (Complianz, Avada, Elementor…) reach the map with their owner.
	// v7: WooCommerce's uploads folders (wc-logs, woocommerce_uploads) named as its own.
	// v8: the ecosystem signature reads the stored active plugins, not the filtered list, so a
	// per-URL plugin unloader no longer arms the capture hooks on visitor requests.
	// v9: a database replacement (Query Monitor's db.php) is never the owner of a write, and the
	// rows it was given are purged once.
	// v10: longest-path ownership (jetpack-boost is not jetpack), no rows without an owner, a
	// mu-plugin or WPOptimizing on the stack is never recorded as someone else, and a map the
	// rotated salts cannot open is moved aside instead of freezing the capture.
	// v11: size ceilings on the map (32 MB) and its index (8 MB).
	// v12: the map is read as a stream (a failed preg_split emptied the dedup set and the map
	// grew to 1.5 GB), appended under a lock when unencrypted, one row per owner and key, and
	// the index hashes are salted (APGIDX4).
	define( 'WPOPT_MUPL_VERSION', '12' );
}
if ( ! defined( 'WPOPT_DB_DROPIN_VERSION' ) ) {
	// v5: locking reads (FOR UPDATE / LOCK IN SHARE MODE) are never memoised. Installed
	// sites MUST pick this up — on v4 a repeated lock read silently skipped its lock.
	// v6: panic switch (WPOPT_DISABLE) and stand-down when the plugin is gone.
	// v7: DDL authorship captured by backtrace (certain attribution for runtime-created tables).
	// v9: the journal write has memory set aside to run in after an out-of-memory fatal.
	// v10: and raises the memory limit first, which is what actually lets it run (measured).
	// v11: memoised rows are copies, so a caller that modifies one no longer changes the next hit.
	define( 'WPOPT_DB_DROPIN_VERSION', '11' );
}

/**
 * Load one of the plugin's admin/cron-only libraries on demand.
 *
 * Those files register no front-end hook, so they are required from
 * wpoptimizing_load_engine() instead of on every request. The handful of code paths that
 * can reach them WITHOUT the engine (the front-end admin bar, the public REST routes, the
 * consented front-end profiler) call this helper first. require_once makes it a no-op when
 * the engine already loaded the file.
 *
 * @since 0.1.0
 * @param string $relative Path relative to the plugin root, e.g. 'includes/host-cache.php'.
 * @return void
 */
function wpoptimizing_load_lib( $relative ) {
	$relative = ltrim( (string) $relative, '/' );
	// Hard allow-list: this helper never resolves a caller-built path.
	$allowed = array(
		'includes/host-cache.php',
		'includes/wpconfig-block.php',
		'includes/db-index-advisor.php',
		'includes/email-template.php',
		'includes/scanners/class-autoload-analyzer.php',
		'includes/scanners/image-optim.php', // big_image_size_threshold fires on REST uploads, which have no engine.
		'includes/cleanup/backup-store.php', // Backup-first for the editors reachable without the engine (wp-config.php, .htaccess, sweeper).
	);
	if ( ! in_array( $relative, $allowed, true ) ) {
		return;
	}
	require_once __DIR__ . '/' . $relative;
}

// Safe admin optimizations (comment-count cache + Heartbeat control). Loaded on
// every request; each hook is a no-op unless the matching option is enabled.
require_once __DIR__ . '/includes/admin/admin-cache.php';

/**
 * Atomically install/refresh one of OUR files in wp-content (a drop-in or the runtime
 * mu-plugin): copy the source to a temp file in the SAME directory, then rename() over the
 * target. rename() is atomic on the same filesystem, so a concurrent request that loads the
 * drop-in (WordPress includes object-cache.php / db.php on every boot) never sees a
 * half-written file — a direct copy() writes the destination in place and can be read mid-write
 * as a parse error. Invalidates opcache for the destination on success.
 *
 * @param string $src  Source path (our bundled copy).
 * @param string $dest Destination path in wp-content.
 * @return bool True when the destination was replaced atomically.
 */
function wpoptimizing_atomic_install_file( $src, $dest ) {
	if ( '' === (string) $src || '' === (string) $dest || ! is_readable( $src ) ) {
		return false;
	}
	// uniqid() (not wp_generate_password) so this is safe at plugins_loaded:1, before
	// pluggable.php is loaded. The suffix only needs to be unique, not unpredictable.
	$tmp = $dest . '.tmp.' . uniqid( '', true );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Writing our own drop-in/mu-plugin into wp-content (standard mechanism), not the plugin directory.
	if ( ! @copy( $src, $tmp ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false;
	}
	if ( ! @rename( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rename, WordPress.WP.AlternativeFunctions.rename_rename, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- atomic replace (tmp+rename); WP_Filesystem::move() is not atomic.
		wp_delete_file( $tmp );
		return false;
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	return true;
}

/**
 * Copy the runtime-map mu-plugin to wp-content/mu-plugins/ if missing, or refresh it (once
 * per hour) when the bundled copy changed. Uses the atomic installer so a concurrent boot
 * never loads a half-written mu-plugin.
 *
 * @return void
 */
function wpoptimizing_ensure_mupl() {
	if ( ! file_exists( WPOPT_MUPL_SRC ) ) {
		return;
	}
	// Missing destination: install immediately (file_exists is < 0.1 ms and more
	// reliable than a cache that could be stale if the user deletes the file).
	if ( ! file_exists( WPOPT_MUPL_DEST ) ) {
		wpoptimizing_atomic_install_file( WPOPT_MUPL_SRC, WPOPT_MUPL_DEST );
		return;
	}
	// Destination exists: refresh it when the bundled feeder changed (e.g. after a
	// plugin update without reactivation). The cheap gate is the modification time —
	// two stat() calls, already warm in PHP's stat cache — and only a source that is
	// NEWER than the installed copy is worth hashing. The previous hourly transient
	// cost one or two non-autoloaded option queries on EVERY request, front end
	// included, which is exactly the overhead this plugin exists to remove.
	$src_mtime  = (int) @filemtime( WPOPT_MUPL_SRC );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing/unreadable file degrades to 0 (skip).
	$dest_mtime = (int) @filemtime( WPOPT_MUPL_DEST ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- idem.
	if ( $src_mtime <= 0 || $dest_mtime <= 0 || $src_mtime <= $dest_mtime ) {
		return;
	}
	if ( md5_file( WPOPT_MUPL_SRC ) !== md5_file( WPOPT_MUPL_DEST ) ) {
		wpoptimizing_atomic_install_file( WPOPT_MUPL_SRC, WPOPT_MUPL_DEST );
		delete_option( 'wpopt_rtmap_sig' ); // Re-capture on next boot with the new feeder.
		delete_option( 'wpopt_rtmap_settings_sig' );
	} else {
		// Same content, newer source (a re-upload of the same build): touch the copy so the
		// mtime gate stops matching and we never hash it again.
		@touch( WPOPT_MUPL_DEST, $src_mtime ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch -- best-effort throttle for our own file.
	}
}
add_action( 'plugins_loaded', 'wpoptimizing_ensure_mupl', 1 );

/**
 * Persist manage_wpoptimizing in WP roles once per version bump.
 * Runs on plugins_loaded so existing installs get the cap without needing
 * to deactivate and reactivate.
 *
 * @return void
 */
function wpoptimizing_maybe_grant_cap() {
	// Autoloaded on purpose: this guard is read on EVERY request, so a non-autoloaded
	// option would cost one SELECT per page load just to find out there is nothing to do.
	$granted_ver = get_option( 'wpoptimizing_cap_version', '' );
	if ( WPOPTIMIZING_VERSION === $granted_ver ) {
		return;
	}
	wpoptimizing_grant_cap();
	wpoptimizing_demote_autoloaded_options();
	wpoptimizing_revive_abandoned_uploads();
	// Seed the once-per-install flag so the plugins_loaded:0 read never misses (a missing
	// option is a query per request, an autoloaded one is free).
	add_option( 'wpoptimizing_cache_recache_once', 0, '', true );
	update_option( 'wpoptimizing_cap_version', WPOPTIMIZING_VERSION, true );
}

/**
 * Move our admin/cron-only options out of the autoload set.
 *
 * New writes already pass autoload=false, but options created by an older version keep
 * autoload=yes forever — including report and scan-state arrays that can run to tens of
 * kilobytes and are read from `alloptions` on every single request. Runs once per version
 * bump, from the same guard that persists the capability.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_demote_autoloaded_options() {
	if ( ! function_exists( 'wp_set_options_autoload' ) ) {
		return; // WordPress < 6.4: new writes still fix each option as it is saved.
	}
	wp_set_options_autoload(
		array(
			'wpoptimizing_general_cleanup_last_report',
			'wpoptimizing_general_cleanup_reports',
			'wpoptimizing_general_cleanup_last_stats',
			'wpoptimizing_general_cleanup_settings',
			'wpoptimizing_general_cleanup_last_run',
			'wpoptimizing_general_cleanup_profile',
			'wpoptimizing_gc_task_schedules',
			'wpoptimizing_gc_task_last_run',
			'wpoptimizing_gc_schedule_config',
			'wpoptimizing_scan_state',
			'wpoptimizing_scan_started_at',
			'wpoptimizing_scan_failed_at',
			'wpoptimizing_scan_scheduled_reason',
			'wpoptimizing_scan_offpeak_start',
			'wpoptimizing_scan_offpeak_end',
			'wpoptimizing_last_scan_reason',
			'wpoptimizing_manual_attributions',
			'wpoptimizing_manual_lead_queue',
			'wpoptimizing_trusted_attachments',
			'wpoptimizing_ext_names',
			'wpoptimizing_ext_names_q',
			'wpoptimizing_webhooks',
			'wpoptimizing_profile_result',
			'wpoptimizing_history_baseline_ts',
			'wpoptimizing_history_retention',
			'wpoptimizing_media_orphan_bytes',
			'wpoptimizing_media_dupes_bytes',
			'wpoptimizing_fs_orphans_skipped',
			'wpoptimizing_fs_orphans_attempts',
			'wpoptimizing_notification_email',
			'wpoptimizing_delete_data_on_uninstall',
			'wpoptimizing_share_nudge_snooze',
			'wpoptimizing_share_consent_prelicense',
			'wpoptimizing_dropin_autorefreshed_ver',
			'wpoptimizing_db_dropin_autorefreshed_ver',
			'wpoptimizing_first_run',
			'wpoptimizing_backup_max_days',
			'wpoptimizing_backup_max_per_type',
			'wpoptimizing_backup_max_mb',
			'wpoptimizing_psi_result',
			'wpoptimizing_psi_key',
			'wpoptimizing_dbindex_applied',
			// Added when every remaining update_option() was given an explicit flag. The
			// first four are the ones whose call sites now pass false; 'last_scan' was the
			// odd one out next to 'last_scan_reason', which was already here.
			'wpoptimizing_cache_settings',
			'wpoptimizing_autoclean_baseline_bytes',
			'wpoptimizing_report_frequency',
			'wpoptimizing_last_scan',
			'wpoptimizing_rtmap_meta_cursor',
		),
		false
	);
}
add_action( 'plugins_loaded', 'wpoptimizing_maybe_grant_cap', 20 );

/**
 * The options a visitor's page load reads, which must therefore be autoloaded.
 *
 * The other half of wpoptimizing_demote_autoloaded_options(). These were saved with autoload
 * off and then read on every uncached front-end request (the delay-JS boot, the minify and
 * defer gates, compat_owner(), the critical-CSS gate, the Google Fonts swap, the TTFB sampler):
 * one SELECT each, per page load, on exactly the requests this plugin exists to make cheap.
 *
 * @since 0.1.0
 * @return string[]
 */
function wpoptimizing_frontend_read_options() {
	return array(
		'wpoptimizing_delay_js',
		'wpoptimizing_asset_optimize',
		'wpoptimizing_compat_overrides',
		'wpoptimizing_ccss',
		'wpoptimizing_gfonts_map',
		'wpoptimizing_gfonts_seen',
		'wpoptimizing_ttfb_stats',
		'wpoptimizing_slug_index',
	);
}

/**
 * Bump when wpoptimizing_frontend_read_options() gains an entry, so installs migrate again.
 */
const WPOPT_FRONTEND_AUTOLOAD_V = 1;

/**
 * Promote the front-end options to autoload, once per install.
 *
 * New writes already pass autoload=true; this moves the rows an earlier version wrote. Not tied
 * to the version bump that runs the demotion, because the version does not change until a
 * release and the installs that need this already have it. Gated on its own AUTOLOADED flag, so
 * on every later request the check is an array lookup.
 *
 * The TTFB stats are created if missing: the sampler reads them on every visitor request, and
 * a missing option is a query each time.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_promote_frontend_options() {
	if ( WPOPT_FRONTEND_AUTOLOAD_V <= (int) get_option( 'wpoptimizing_frontend_autoload_v', 0 ) ) {
		return;
	}
	if ( function_exists( 'wp_set_options_autoload' ) ) {
		wp_set_options_autoload( wpoptimizing_frontend_read_options(), true );
	}
	add_option( 'wpoptimizing_ttfb_stats', array(), '', true );
	update_option( 'wpoptimizing_frontend_autoload_v', WPOPT_FRONTEND_AUTOLOAD_V, true );
}
add_action( 'plugins_loaded', 'wpoptimizing_promote_frontend_options', 20 );

/**
 * One-shot cache refresh after a drop-in (object-cache.php / db.php) install.
 *
 * A persistent object cache only becomes active on the request AFTER it is written,
 * and until it is primed it can serve a cold/stale `wp_user_roles` blob that lacks
 * the administrator's capabilities. That made WordPress deny access to the plugin
 * page ("Sorry, you are not allowed to access this page.") right after enabling the
 * cache from the welcome wizard or the Cache tab. When an install armed the flag,
 * flush once here — very early, before the admin capability gate runs — so roles and
 * options are re-read fresh from the database, then re-assert our own capability.
 *
 * @return void
 */
function wpoptimizing_cache_recache_once() {
	// Read on EVERY request at plugins_loaded:0, so the flag is kept as an AUTOLOADED
	// option that is reset to 0 instead of deleted. A deleted (or non-autoloaded) option
	// costs one SELECT per page load just to learn there is nothing to do; an autoloaded
	// one arrives with alloptions and is free.
	if ( ! get_option( 'wpoptimizing_cache_recache_once' ) ) {
		return;
	}
	update_option( 'wpoptimizing_cache_recache_once', 0, true );
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
	if ( function_exists( 'wpoptimizing_grant_cap' ) ) {
		wpoptimizing_grant_cap();
	}
}
add_action( 'plugins_loaded', 'wpoptimizing_cache_recache_once', 0 );
if ( ! defined( 'WPOPT_CSV_UNIFIED_DB' ) ) {
	define( 'WPOPT_CSV_UNIFIED_DB', WPOPT_DIR . '/wp-db-unificado.csv' );
}
if ( ! defined( 'WPOPT_CSV_UNIFIED_FILES' ) ) {
	define( 'WPOPT_CSV_UNIFIED_FILES', WPOPT_DIR . '/wp-files-unificado.csv' );
}
// Asset registry (front-end CSS/JS handles -> owner + deps) for the future Smart
// Assets feature. Written by the scanner (static) and the feeder (runtime),
// uploaded to the shared KB when data sharing is on. Contents are structural only.
if ( ! defined( 'WPOPT_CSV_ASSETS' ) ) {
	define( 'WPOPT_CSV_ASSETS', WPOPT_DIR . '/wp-assets.csv' );
}

/** Protected local cache (encrypted) */
if ( ! defined( 'WPOPT_PROTECTED_DIR' ) ) {
	// Kept OUTSIDE the plugin folder (in the per-site data dir) so the plugin
	// never writes into its own directory — least of all a hidden .htaccess,
	// which Plugin Check forbids (hidden_files) and read-only installs reject.
	define( 'WPOPT_PROTECTED_DIR', WPOPT_DIR . '/protected' );
}
if ( ! defined( 'WPOPT_PROTECTED_DB' ) ) {
	define( 'WPOPT_PROTECTED_DB', WPOPT_PROTECTED_DIR . '/db.enc' );
}
if ( ! defined( 'WPOPT_PROTECTED_FILES' ) ) {
	define( 'WPOPT_PROTECTED_FILES', WPOPT_PROTECTED_DIR . '/files.enc' );
}

/**
 * Custom capability name for all WPOptimizing admin operations.
 * Using a dedicated cap allows granting access to non-admin roles
 * without giving them full manage_options.
 *
 * @return string
 */
function wpoptimizing_cap() {
	return 'manage_wpoptimizing';
}


/**
 * Settings portability (export/import all settings to/from a JSON file).
 * Kept in its own include so the test suite can load it in isolation.
 */

/*
 * LICENCIA, MARCA BLANCA REMOTA, TOKEN DE ACTUALIZACIÓN Y RUTAS QUE LLAMA LA TIENDA: todo Pro.
 * El build de wordpress.org no lleva nada de esto (directrices 5, 7 y 8): ni guarda una clave,
 * ni habla con el servidor de licencias, ni abre rutas REST para él. Lo que el resto del código
 * pregunta de aquí está tras function_exists() o dentro de su propio bloque WPOPT-PREMIUM.
 */

/**
 * Dynamically grant manage_wpoptimizing to any user who already has manage_options.
 * This runs on every request so it works even before the role has been persisted
 * (e.g. right after upgrading from a version that didn't call grant_cap on activation).
 *
 * @param bool[]   $allcaps All caps the user has.
 * @param string[] $caps    Primitive caps requested.
 * @return bool[]
 */
function wpoptimizing_user_has_cap( $allcaps, $caps ) {
	if ( in_array( 'manage_wpoptimizing', (array) $caps, true ) && ! empty( $allcaps['manage_options'] ) ) {
		$allcaps['manage_wpoptimizing'] = true;
	}
	return $allcaps;
}
add_filter( 'user_has_cap', 'wpoptimizing_user_has_cap', 10, 2 );

/**
 * Grant the manage_wpoptimizing capability to all roles that have manage_options.
 * Called on plugin activation and upgrade.
 *
 * @return void
 */
function wpoptimizing_grant_cap() {
	$roles = function_exists( 'wp_roles' ) ? wp_roles() : new WP_Roles();
	// On multisite, after switch_to_blog() the cached role_key would point to
	// the wrong blog; for_site() rebinds it to the current blog.
	if ( method_exists( $roles, 'for_site' ) ) {
		$roles->for_site( get_current_blog_id() );
	}
	foreach ( $roles->roles as $role_slug => $role_data ) {
		if ( ! empty( $role_data['capabilities']['manage_options'] ) ) {
			$role = $roles->get_role( $role_slug );
			if ( $role ) {
				$role->add_cap( wpoptimizing_cap() );
			}
		}
	}
}

// Cifrado en reposo de secretos guardados (token de Cloudflare; en Pro también el de
// actualización). Compartido: Lite lo necesita para Cloudflare, así que va fuera del bloque.
require_once __DIR__ . '/includes/token-crypt.php';


/**
 * Resolve who receives WPOptimizing notifications. Agencies managing many sites need the
 * mail to reach the MAINTAINER, not the site owner. Resolution order:
 *   1. Local "Notification email" setting (wpoptimizing_notification_email) — per-site
 *      override; one or more addresses separated by comma/space/semicolon. Highest priority.
 *   2. White-label maintainer email (set remotely by a reseller for ALL their sites).
 *   3. The provided default (e.g. the license customer email), else the site admin_email.
 *
 * @param array $default Fallback recipients when nothing is configured.
 * @return string[] De-duplicated list of valid email addresses.
 */
function wpoptimizing_notification_recipients( array $default = array() ) {
	// 1) Local per-site setting wins.
	$local = array();
	foreach ( preg_split( '/[\s,;]+/', (string) get_option( 'wpoptimizing_notification_email', '' ) ) as $e ) {
		$e = trim( (string) $e );
		if ( '' !== $e && is_email( $e ) ) {
			$local[] = $e;
		}
	}
	if ( ! empty( $local ) ) {
		return array_values( array_unique( $local ) );
	}
	// 3) Caller default (license customer emails), else the site admin.
	$default = array_values( array_filter( array_unique( $default ), 'is_email' ) );
	if ( ! empty( $default ) ) {
		return $default;
	}
	$admin = get_option( 'admin_email' );
	return is_email( $admin ) ? array( $admin ) : array();
}


/**
 * Shared print script for every standalone report (general-cleanup, network,
 * impact). Prints a clone of the page from a hidden iframe so the main window
 * never calls print() (Safari/WebKit can't blank it). CRITICAL: waits for the
 * iframe's images to load AND for two animation frames (layout + paint) before
 * printing — printing right after document.write() raced the first paint and
 * intermittently produced a blank page. Single source of truth: do not inline.
 *
 * @param string $btn_id DOM id of the print button.
 * @return string The script tag, from wp_get_inline_script_tag() ($btn_id is JSON-encoded).
 */
function wpoptimizing_print_report_script( $btn_id ) {
	$id = wp_json_encode( (string) $btn_id );
	return wp_get_inline_script_tag(
		'(function(){var b=document.getElementById(' . $id . ');if(!b)return;'
		. 'b.addEventListener("click",function(e){e.preventDefault();'
		. 'var f=document.createElement("iframe");f.setAttribute("aria-hidden","true");'
		. 'f.style.cssText="position:fixed;left:-9999px;top:0;width:794px;height:1123px;border:0;";'
		. 'document.body.appendChild(f);var iw=f.contentWindow,d=iw.document;'
		. 'd.open();d.write("<!DOCTYPE html>"+document.documentElement.outerHTML);d.close();'
		. 'function go(){requestAnimationFrame(function(){requestAnimationFrame(function(){'
		. 'try{iw.focus();iw.print();}catch(err){window.print();}});});}'
		. 'var imgs=d.images?Array.prototype.slice.call(d.images):[];'
		. 'var pending=imgs.filter(function(im){return !im.complete;});'
		. 'if(!pending.length){go();}else{var n=0,fin=function(){if(++n>=pending.length)go();};'
		. 'pending.forEach(function(im){im.addEventListener("load",fin);im.addEventListener("error",fin);});'
		. 'setTimeout(go,1500);}'
		. 'setTimeout(function(){if(f.parentNode)f.parentNode.removeChild(f);},4000);'
		. '});}());'
	);
}


add_filter( 'plugin_action_links_' . WPOPTIMIZING_BASENAME, 'wpoptimizing_plugin_action_links' );

/*
 * The autoload analyzer is NOT loaded here.
 *
 * It used to be, because the once-a-day measurement heartbeat runs on `init` for every
 * request, front end included — but the heartbeat reads a timestamp and returns on all but
 * one request per day, and the analyzer is 40 KB. It is now pulled in by the two callers
 * that actually get past that check (the heartbeat itself and activation) through
 * wpoptimizing_load_lib(), and by wpoptimizing_load_engine() for the Autoload tab. Every
 * consumer is admin-only; nothing on a visitor request touches it.
 */

/**
 * Outbound webhooks. Lightweight: only registers listeners on WPOptimizing's own
 * lifecycle actions (which never fire on the frontend), so this is free there.
 */

/*
 * Front-end performance features (Speculative Loading, Server-Timing, content image
 * loading optimization). Always loaded; each hook short-circuits unless enabled.
 */
// Unique temp names for every atomic write; always loaded because always-loaded code calls it.
require_once __DIR__ . '/includes/storage/atomic-write.php';
require_once __DIR__ . '/includes/storage/upload-backoff.php';
// Single source of truth for "another plugin already does this". Always loaded, and BEFORE
// the feature files below, because every one of them asks it whether to stand down. Data and
// pure accessors only — no filesystem, no network, nothing at file scope.
require_once __DIR__ . '/includes/compat/registry.php';
require_once __DIR__ . '/includes/frontend-perf.php';
// The basic page cache is in both editions (24/11/2026); its Pro parts load from inside it.
require_once __DIR__ . '/includes/page-cache.php'; // Inert until installed.
require_once __DIR__ . '/includes/core-tweaks.php';
require_once __DIR__ . '/includes/cron-tasks.php'; // Scheduled tasks: health, run now, pause (the guard hooks only on cron requests).
require_once __DIR__ . '/includes/cloudflare.php'; // Cloudflare purge; one autoloaded flag on a visitor request, inert until configured.
require_once __DIR__ . '/includes/edge-cache.php'; // Server/CDN caches seen in the response headers, and native Varnish purge (off by default).
require_once __DIR__ . '/includes/host-purge.php'; // The hosting's page cache (WP Engine, Kinsta, SiteGround…) purged on every change; inert without one.
require_once __DIR__ . '/includes/asset-optimize.php'; // CSS/JS minification (Fase 3a); inert until enabled.
require_once __DIR__ . '/includes/cleanup/sweeper.php';
require_once __DIR__ . '/includes/frontend-assets.php';


/*
 * Image optimization runtime wiring (settings → engine + native upload down-scaling +
 * front-end WebP/AVIF serving). Always loaded; each hook short-circuits unless enabled.
 */
require_once __DIR__ . '/includes/image-frontend.php';

/*
 * Host / third-party cache coexistence: detect provider/plugin caches and purge
 * them after cleanups (and from the toolbar) instead of competing with them.
 */

/*
 * Scale guards (large-DB detection + SQL statement timeout) used by the scan and
 * the on-demand admin aggregations. Tiny; only defines helpers.
 */
require_once __DIR__ . '/includes/scanners/scale-guard.php';

/*
 * Asset-registry helpers (front-end CSS/JS attribution) for the future Smart
 * Assets feature. Pure; used by the scanner and mirrored by the feeder.
 */
require_once __DIR__ . '/includes/scanners/asset-map.php';

/*
 * At-rest encryption for the local scan/impact working CSVs (our attribution
 * method). Transparent read helper + encrypt-on-finalize.
 */
require_once __DIR__ . '/includes/storage/scan-crypt.php';

/**
 * White-label normaliser (pure helper for the account-managed branding flow). Pro only:
 * the branding arrives with the licence, which Lite does not have.
 */

/**
 * Sensitive-value redaction for the live-query viewer (pure helpers, no side
 * effects at load).
 */
require_once __DIR__ . '/includes/api/redaction.php';

/**
 * Autoload knowledge base consumer (pure parser + cached fetcher).
 *
 * Only the three layers Smart Assets reads on the front end stay here. The rest —
 * scan orchestration, the KB layer map, the media report, the KPI caches, the
 * content-cleanup helpers and the protected-items guard — moved into
 * wpoptimizing_load_engine(): every caller of theirs runs in admin, cron, WP-CLI or
 * admin-ajax, so a visitor page load was compiling ~23 KB it could never call.
 */
require_once __DIR__ . '/includes/storage/csv-reader.php';
require_once __DIR__ . '/includes/resolvers/shared-slug.php';   // Una marca interna no es un nombre de extensión.
require_once __DIR__ . '/includes/resolvers/autoload-kb.php';
require_once __DIR__ . '/includes/resolvers/assets-kb.php';


/**
 * Condition-based auto-cleanup (runs General cleanup when the DB grows past a
 * configured threshold). Pure decision helper; cron does the work.
 */

/**
 * Load the analysis/admin engine: scanners, cleaners, resolvers, the admin UI
 * and the runtime-evidence capture layer (~17k lines combined).
 *
 * These classes are only ever exercised on admin screens, WP-Cron jobs, WP-CLI
 * and explicit scans, so they are NOT parsed or executed on frontend page loads.
 * That keeps the plugin's frontend overhead at zero, which is a hard project
 * requirement. The function is idempotent (static guard) so it is safe to call
 * from any entry point that needs the engine on demand.
 *
 * @since 0.1.0
 * @return void
 */
function wpoptimizing_load_engine() {
	static $loaded = false;
	if ( $loaded ) {
		return;
	}
	$loaded = true;

	// Admin/cron-only libraries. They register no front-end hook, so parsing them on a
	// visitor page load would be pure overhead; everything that needs them runs here
	// (admin, cron, WP-CLI) or pulls them in explicitly via wpoptimizing_load_lib().
	// Blob store first: activity-log, runtime-evidence, general-cleanup and the maintenance
	// report all read through it.
	// Admin/cron/CLI-only. Every caller of these runs behind wpoptimizing_request_needs_engine():
	// the scan stages (cron + admin-ajax), the KB sync (cron), the Media tab, the dashboard
	// cards, and the deletion routine in trait-data-ops.php — which is also the ONLY place the
	// wpoptimizing_can_delete_item filter is applied, so registering its guards here is enough.
	require_once __DIR__ . '/includes/scanners/attachment-companions.php'; // Companion-file list shared by both media scans.
	require_once __DIR__ . '/includes/image-nextgen-rewrite.php';          // WebP/AVIF answered by the server: the uploads .htaccess block, off by default.
	require_once __DIR__ . '/includes/cache-leftovers.php';
	require_once __DIR__ . '/includes/importers/importer.php';
	require_once __DIR__ . '/includes/scanners/css-usage.php';             // Used-CSS extractor (pure).
	require_once __DIR__ . '/includes/scanners/scan.php';
	require_once __DIR__ . '/includes/scan-engine.php'; // The staged-scan worker itself.
	require_once __DIR__ . '/includes/lifecycle.php';            // Activation/deactivation/update plumbing.
	require_once __DIR__ . '/includes/admin/chrome.php';         // Branding, progress markup, audit trail.
	require_once __DIR__ . '/includes/storage/background-data.php'; // CSV cache, unified datasets, KB sync.
	require_once __DIR__ . '/includes/storage/db-journal.php'; // Folds the query-analytics journal off the visitor path.
	require_once __DIR__ . '/includes/storage/markup-audit.php';
	require_once __DIR__ . '/includes/storage/media-report.php';
	require_once __DIR__ . '/includes/storage/kpi-cache.php';
	require_once __DIR__ . '/includes/resolvers/kb-layers.php';
	require_once __DIR__ . '/includes/resolvers/attribution-origins.php'; // De qué clase es el dueño: vocabulario único.
	require_once __DIR__ . '/includes/resolvers/confidence-levels.php';  // Cuánto se sabe de una atribución: vocabulario único.
	require_once __DIR__ . '/includes/resolvers/own-keys.php';           // Lo nuestro no se atribuye a nadie más.
	require_once __DIR__ . '/includes/resolvers/claim-support.php';      // Quién estaba en la pila no es de quién es la clave.
	require_once __DIR__ . '/includes/resolvers/core-nucleo.php';        // Lo que es de WordPress, medido por versión (capa nucleo).
	require_once __DIR__ . '/includes/resolvers/namespace-learning.php'; // icl_*, WPML(…) son de WPML aunque no digan su carpeta.
	require_once __DIR__ . '/includes/resolvers/attribution-shields.php'; // Los cuatro escudos, para los dos puntos que atribuyen.
	require_once __DIR__ . '/includes/cleanup/content-cleanup.php';
	require_once __DIR__ . '/includes/cleanup/delete-safety.php';    // Backup escaping, cron capture, own paths, symlinks: the delete guards.
	require_once __DIR__ . '/includes/cleanup/backup-store.php';     // Backup-first: the one writer every change of site data goes through.
	require_once __DIR__ . '/includes/cleanup/protected-items.php';

	require_once __DIR__ . '/includes/resolvers/assets-rules-audit.php';
	require_once __DIR__ . '/includes/storage/asset-weight.php';
	require_once __DIR__ . '/includes/opcache-health.php';
	require_once __DIR__ . '/includes/server-health.php'; // Redis and MySQL: findings from their own counters.
	require_once __DIR__ . '/includes/storage/blob-store.php';
	require_once __DIR__ . '/includes/storage/settings-portability.php';
	require_once __DIR__ . '/includes/wpconfig-block.php'; // Shared managed-block editor (used by wp-cron-control + page cache).
	require_once __DIR__ . '/includes/wp-cron-control.php';
	require_once __DIR__ . '/includes/browser-cache.php'; // Browser cache + GZIP via a managed .htaccess block; inert until enabled.
	require_once __DIR__ . '/includes/db-index-advisor.php'; // Pure EXPLAIN diagnosis core (read-only advisor); no side effects on load.
	require_once __DIR__ . '/includes/pagespeed.php'; // PageSpeed Insights fetch (admin-initiated, read-only); no side effects on load.
	require_once __DIR__ . '/includes/activity-log.php';
	require_once __DIR__ . '/includes/email-template.php';
	require_once __DIR__ . '/includes/maintenance-report.php';
	require_once __DIR__ . '/includes/host-cache.php';
	require_once __DIR__ . '/includes/dropin-owner.php'; // Who owns object-cache.php / db.php / advanced-cache.php, and the replace-with-backup path.

	require_once __DIR__ . '/includes/scanners/class-wp-core-registry.php';
	require_once __DIR__ . '/includes/resolvers/class-manifest-resolver.php';
	require_once __DIR__ . '/includes/storage/class-sqlite-index.php';
	require_once __DIR__ . '/includes/resolvers/class-runtime-resolver.php';
	require_once __DIR__ . '/includes/resolvers/shared-libraries.php'; // Vendored libraries (Action Scheduler…): presence, not ownership.
	require_once __DIR__ . '/includes/resolvers/manual-attrib.php';
	require_once __DIR__ . '/includes/resolvers/attr-suggest.php';
	require_once __DIR__ . '/includes/scanners/class-code-scanner.php';
	require_once __DIR__ . '/includes/scanners/class-fs-scanner.php';
	require_once __DIR__ . '/includes/scanners/trait-slug-matching.php';
	require_once __DIR__ . '/includes/cleanup/class-impact-cleaner.php';
	require_once __DIR__ . '/includes/scanners/class-empty-scanner.php';
	require_once __DIR__ . '/includes/scanners/class-fs-orphans.php';
	require_once __DIR__ . '/includes/storage/analytics.php';
	require_once __DIR__ . '/includes/storage/table-analytics.php';
	require_once __DIR__ . '/includes/storage/class-performance-logger.php';
	require_once __DIR__ . '/includes/cleanup/class-general-cleanup.php';
	require_once __DIR__ . '/includes/admin/restore-cache.php';
	require_once __DIR__ . '/includes/admin/trait-backups.php';
	require_once __DIR__ . '/includes/admin/trait-history.php';
	require_once __DIR__ . '/includes/admin/trait-activity.php';
	require_once __DIR__ . '/includes/admin/trait-media.php';
	require_once __DIR__ . '/includes/admin/trait-autoload.php';
	require_once __DIR__ . '/includes/admin/trait-cron.php';
	require_once __DIR__ . '/includes/admin/trait-tables.php';
	require_once __DIR__ . '/includes/admin/trait-remote-maps.php';
	require_once __DIR__ . '/includes/admin/trait-data-ops.php';
	require_once __DIR__ . '/includes/admin/trait-settings.php';
	require_once __DIR__ . '/includes/admin/trait-cleanup-tab.php';
	require_once __DIR__ . '/includes/admin/trait-attr-suggest.php';
	require_once __DIR__ . '/includes/admin/trait-table-data.php';
	require_once __DIR__ . '/includes/scanners/class-autoload-analyzer.php';
	require_once __DIR__ . '/includes/admin/cache-config.php';
	require_once __DIR__ . '/includes/admin/trait-cache.php';
	require_once __DIR__ . '/includes/admin/trait-performance.php';
	require_once __DIR__ . '/includes/admin/trait-docs.php';
	require_once __DIR__ . '/includes/admin/class-admin-ui.php';
	require_once __DIR__ . '/includes/admin/screen.php';
	require_once __DIR__ . '/includes/admin/ajax.php';
	require_once __DIR__ . '/includes/admin/class-admin-list-tables.php';
	require_once __DIR__ . '/includes/resolvers/class-dataset-resolver.php';
	require_once __DIR__ . '/includes/scanners/class-runtime-evidence.php';
	require_once __DIR__ . '/includes/scanners/class-media-analyzer.php';
	require_once __DIR__ . '/includes/scanners/class-media-deduper.php';
	require_once __DIR__ . '/includes/scanners/image-optim.php';


	// Register runtime-evidence capture hooks (option/meta/transient origin tracking).
	if ( class_exists( 'WPOPT_Runtime_Evidence' ) ) {
		WPOPT_Runtime_Evidence::bootstrap();
	}

	/**
	 * Fires once the analysis engine and all its classes are loaded.
	 *
	 * Add-ons should register scanners/resolvers that depend on the core engine
	 * classes (WPOPT_Impact_Cleaner, WPOPT_Runtime_Resolver, …) on this hook rather
	 * than on `plugins_loaded`, because the engine is loaded lazily.
	 *
	 * @since 0.1.0
	 */
	do_action( 'wpoptimizing_engine_loaded' );
}

/**
 * Whether the current request needs the heavy analysis/admin engine.
 *
 * Frontend page loads never do. Add-ons that expose their own frontend or REST
 * surface can force the engine on via the `wpoptimizing_should_load_engine`
 * filter, then call wpoptimizing_load_engine() when appropriate.
 *
 * @since 0.1.0
 * @return bool
 */
function wpoptimizing_request_needs_engine() {
	$needed = is_admin()
		|| wp_doing_cron()
		|| ( defined( 'WP_CLI' ) && WP_CLI );

	/**
	 * Filters whether the analysis/admin engine loads on this request.
	 *
	 * @since 0.1.0
	 * @param bool $needed True if the engine will be loaded for this request.
	 */
	return (bool) apply_filters( 'wpoptimizing_should_load_engine', $needed );
}

if ( wpoptimizing_request_needs_engine() ) {
	wpoptimizing_load_engine();
}


/**
 * Ensure protected storage exists and is blocked from direct download.
 *
 * @return bool
 */
function wpoptimizing_ensure_protected_dir() {
	// The hardening files never change once written, so verify them once per request and
	// remember the answer: the scan pipeline calls this on every stage.
	static $ready = null;
	if ( true === $ready ) {
		return true;
	}
	if ( ! wp_mkdir_p( WPOPT_PROTECTED_DIR ) ) {
		return false;
	}

	$htaccess = WPOPT_PROTECTED_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			. "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n";
		file_put_contents( $htaccess, $rules, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	$index = WPOPT_PROTECTED_DIR . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	$webconfig = WPOPT_PROTECTED_DIR . '/web.config';
	if ( ! file_exists( $webconfig ) ) {
		file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n", LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	$ready = true;
	return true;
}

/**
 * Base URL of the data gateway. Filterable for staging/self-host.
 *
 * @return string
 */
function wpoptimizing_gateway_url() {
	return (string) apply_filters( 'wpoptimizing_gateway_url', 'https://wpoptimizing.com/wpoptimizing.php' );
}

/**
 * This install's site identity sent to the gateway (the bare host).
 *
 * @return string
 */
function wpoptimizing_site_id() {
	$home = function_exists( 'home_url' ) ? (string) home_url() : '';
	$host = (string) wp_parse_url( $home, PHP_URL_HOST );
	return '' !== $host ? strtolower( $host ) : strtolower( trim( $home ) );
}

/**
 * Manual-attribution lead pipeline (client side).
 *
 * When the owner manually attributes content (see manual-attrib.php), we send the hint —
 * a "lead" — to the data gateway so the KB-builder ("alimentador") can install the named
 * extension and deep-scan it, feeding the knowledge base with VERIFIED data rather than
 * the raw user assertion. The send is:
 *   - opt-in: only with reciprocal data-sharing consent (and a `wpoptimizing_send_manual_leads` filter),
 *   - off-request: queued to a one-off cron event so the admin action never blocks,
 *   - best-effort: failures are silent; the local attribution already took effect.
 *
 * Server contract — POST {gateway}?action=manual-lead, Bearer <data token>, JSON body:
 *   { scope:"contains|exact", match, slug, name, type:"plugin|theme", site, ts }
 * The endpoint stores the lead for the alimentador to act on; a 2xx means accepted.
 */
add_action( 'wpoptimizing_manual_attribution_added', 'wpoptimizing_queue_manual_lead', 10, 1 );

/**
 * Buffer a manual-attribution lead for a single, batched off-request delivery.
 * "Attribute selected" can add tens or hundreds of rules at once, so instead of
 * one cron event + one POST per rule we collect them per request and flush them
 * to the gateway in one chunked POST.
 *
 * @param array $rule The added rule { scope, match, slug, name, type }.
 * @return void
 */
function wpoptimizing_queue_manual_lead( $rule ) {
	if ( ! is_array( $rule ) || empty( $rule['slug'] ) ) {
		return;
	}
	if ( ! function_exists( 'wpoptimizing_has_share_consent' ) || ! wpoptimizing_has_share_consent() ) {
		return; // Reciprocal sharing off → never contact the gateway.
	}
	if ( ! (bool) apply_filters( 'wpoptimizing_send_manual_leads', true ) ) {
		return;
	}
	if ( ! isset( $GLOBALS['wpoptimizing_lead_buffer'] ) ) {
		$GLOBALS['wpoptimizing_lead_buffer'] = array();
		add_action( 'shutdown', 'wpoptimizing_persist_lead_buffer', 1 );
	}
	$GLOBALS['wpoptimizing_lead_buffer'][] = array(
		'scope' => ( 'exact' === ( $rule['scope'] ?? '' ) ) ? 'exact' : 'contains',
		'match' => (string) ( $rule['match'] ?? '' ),
		'slug'  => (string) ( $rule['slug'] ?? '' ),
		'name'  => (string) ( $rule['name'] ?? '' ),
		'type'  => ( 'theme' === ( $rule['type'] ?? '' ) ) ? 'theme' : 'plugin',
	);
}

/**
 * Persist the request's buffered leads into the pending-queue option (deduped,
 * capped) and schedule one batched flush. Runs once per request on shutdown, so a
 * bulk add of N rules costs one option write + one scheduled event.
 *
 * @return void
 */
function wpoptimizing_persist_lead_buffer() {
	$buffer = isset( $GLOBALS['wpoptimizing_lead_buffer'] ) ? (array) $GLOBALS['wpoptimizing_lead_buffer'] : array();
	if ( empty( $buffer ) ) {
		return;
	}
	$queue = (array) get_option( 'wpoptimizing_manual_lead_queue', array() );
	$seen  = array();
	foreach ( $queue as $q ) {
		if ( is_array( $q ) ) {
			$seen[ ( $q['type'] ?? '' ) . '|' . ( $q['slug'] ?? '' ) . '|' . ( $q['scope'] ?? '' ) . '|' . ( $q['match'] ?? '' ) ] = true;
		}
	}
	foreach ( $buffer as $lead ) {
		$key = $lead['type'] . '|' . $lead['slug'] . '|' . $lead['scope'] . '|' . $lead['match'];
		if ( '' === $lead['slug'] || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$queue[]      = $lead;
	}
	if ( count( $queue ) > 5000 ) {
		$queue = array_slice( $queue, -5000 ); // Bound the best-effort contribution.
	}
	update_option( 'wpoptimizing_manual_lead_queue', array_values( $queue ), false );
	if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpoptimizing_manual_lead_flush' ) ) {
		wp_schedule_single_event( time() + 10, 'wpoptimizing_manual_lead_flush' );
	}
}

/**
 * Flush the pending manual-attribution leads to the gateway in one batched POST,
 * chunked under the gateway's size cap. Best-effort: a failed chunk (and the rest)
 * stays queued and a retry is rescheduled; delivered chunks are removed as we go.
 * Body: { leads: [ {scope,match,slug,name,type}, … ], site, ts }.
 *
 * @return void
 */
function wpoptimizing_manual_lead_flush() {
	if ( ! function_exists( 'wpoptimizing_has_share_consent' ) || ! wpoptimizing_has_share_consent() ) {
		return;
	}
	$queue = (array) get_option( 'wpoptimizing_manual_lead_queue', array() );
	if ( empty( $queue ) ) {
		return;
	}
	$token = function_exists( 'wpoptimizing_register_install' ) ? wpoptimizing_register_install() : '';
	if ( '' === $token ) { // Gateway unreachable → keep the queue, retry later.
		if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpoptimizing_manual_lead_flush' ) ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'wpoptimizing_manual_lead_flush' );
		}
		return;
	}
	$chunk_size = max( 1, (int) apply_filters( 'wpoptimizing_manual_lead_chunk', 200 ) );
	$site       = wpoptimizing_site_id();
	$remaining  = array_values( $queue );
	while ( ! empty( $remaining ) ) {
		$chunk = array_splice( $remaining, 0, $chunk_size );
		$resp  = wp_remote_post(
			wpoptimizing_gateway_url() . '?action=manual-lead',
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $token,
					'X-WPO-Site'    => $site,
				),
				'body'      => wp_json_encode(
					array(
						'leads' => array_values( $chunk ),
						'site'  => $site,
						'ts'    => time(),
					)
				),
			)
		);
		if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 300 ) {
			$remaining = array_merge( $chunk, $remaining ); // Re-queue the failed chunk + rest.
			update_option( 'wpoptimizing_manual_lead_queue', array_values( $remaining ), false );
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wpoptimizing_manual_lead_flush' ) ) {
				wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, 'wpoptimizing_manual_lead_flush' );
			}
			return;
		}
		update_option( 'wpoptimizing_manual_lead_queue', array_values( $remaining ), false ); // Save progress per chunk.
	}
	delete_option( 'wpoptimizing_manual_lead_queue' );
}
add_action( 'wpoptimizing_manual_lead_flush', 'wpoptimizing_manual_lead_flush' );

/**
 * Register this install with the gateway and obtain a per-install data token
 * bound to its domain. Works for free AND licensed installs (the license is sent
 * for context only; it does not gate the token). Stores the token locally.
 *
 * @param bool $force Re-register even if a token is already cached.
 * @return string Token, or '' on failure.
 */
function wpoptimizing_register_install( $force = false ) {
	$stored = (string) get_option( 'wpoptimizing_data_token', '' );
	// Reciprocal sharing: with no consent we never contact the data gateway (the
	// site domain is not sent), so the plugin runs on local attribution only.
	if ( ! wpoptimizing_has_share_consent() ) {
		return $stored;
	}
	$site = wpoptimizing_site_id();
	if ( '' !== $stored && ! $force && (string) get_option( 'wpoptimizing_data_token_site', '' ) === $site ) {
		return $stored;
	}
	if ( '' === $site ) {
		return $stored;
	}
	// Spaced, capped and stopped by a 429 (see wpoptimizing_register_gate()): every caller that
	// needs a token lands here, so without this a site with a stale token registers on every call.
	if ( '' !== wpoptimizing_register_gate() ) {
		return $stored;
	}

	$headers = array(
		'Accept'     => 'application/json',
		'X-WPO-Site' => $site,
	);
	$resp = wp_remote_post(
		wpoptimizing_gateway_url() . '?action=register',
		array(
			'timeout'   => 15,
			'sslverify' => true,
			'headers'   => $headers,
		)
	);
	wpoptimizing_register_note( is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp ) );
	if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
		return $stored;
	}
	$data  = json_decode( (string) wp_remote_retrieve_body( $resp ), true, 5 );
	$token = ( is_array( $data ) && ! empty( $data['token'] ) ) ? sanitize_text_field( (string) $data['token'] ) : '';
	if ( '' === $token ) {
		return $stored;
	}

	// New token → drop the at-rest cache so it re-fetches/re-encrypts consistently.
	if ( $token !== $stored ) {
		foreach ( array( WPOPT_PROTECTED_DB, WPOPT_PROTECTED_FILES ) as $enc ) {
			if ( defined( 'WPOPT_PROTECTED_DB' ) && file_exists( $enc ) ) {
				wp_delete_file( $enc );
			}
		}
		delete_transient( 'wpoptimizing_remote_token' );
	}
	update_option( 'wpoptimizing_data_token', $token, false );
	update_option( 'wpoptimizing_data_token_site', $site, false );
	return $token;
}

/**
 * A token the gateway rejects is forgotten, so the next request registers again.
 *
 * Registering revokes every earlier token of the same domain (rotation). So a second install
 * answering to the same name — a clone, a staging copy, or a local tool run against the wrong
 * database, which is how it was found on 15/10/2026 — silently revoked the first one's token,
 * and that install kept sending the revoked token forever: every download and upload got a 401
 * and nothing ever re-registered. One hook sees every gateway answer; on a 401 from a
 * token-gated call the site binding is dropped, and wpoptimizing_get_data_token() registers
 * again on the next call, within the spacing and cap of wpoptimizing_register_gate().
 *
 * @param mixed  $response HTTP response or WP_Error.
 * @param string $context  Hook context.
 * @param string $klass    Transport class.
 * @param array  $args     Request args.
 * @param string $url      Request URL.
 * @return void
 */
function wpoptimizing_gateway_auth_watch( $response, $context, $klass, $args, $url ) {
	if ( 'response' !== $context || is_wp_error( $response ) || ! function_exists( 'wpoptimizing_gateway_url' ) ) {
		return;
	}
	$url = (string) $url;
	if ( 0 !== strpos( $url, (string) wpoptimizing_gateway_url() ) || false !== strpos( $url, 'action=register' ) ) {
		return;
	}
	if ( 401 === (int) wp_remote_retrieve_response_code( $response ) ) {
		delete_option( 'wpoptimizing_data_token_site' );
	}
}
add_action( 'http_api_debug', 'wpoptimizing_gateway_auth_watch', 10, 5 );

/**
 * The per-install data token, registering on demand if missing. Not gated by the
 * license — free installs get attribution data too.
 *
 * @return string
 */
function wpoptimizing_get_data_token() {
	$token = (string) get_option( 'wpoptimizing_data_token', '' );
	if ( '' !== $token && wpoptimizing_site_id() === (string) get_option( 'wpoptimizing_data_token_site', '' ) ) {
		return $token;
	}
	return wpoptimizing_register_install();
}


/**
 * Download a curated KB file from the gateway into $dest (atomic write).
 *
 * At most once per wpoptimizing_kb_download_min_interval() per layer, and conditional: the
 * gateway's ETag goes back as If-None-Match and a 304 keeps the local copy. Every sync of every
 * site used to fetch the eight layers whole (~40 MB) whether they had changed or not, which with
 * a thousand sites is tens of GB a day out of a server shared with other sites (21/10/2026).
 *
 * @param string $filename Logical KB file name.
 * @param string $dest     Absolute destination path.
 * @param bool   $force    Skip the once-a-day spacing (a sync the user asked for). The request
 *                         is still conditional, so an unchanged layer still costs a 304.
 * @return bool
 */
function wpoptimizing_download_kb_file( $filename, $dest, $force = false ) {
	// NEVER on a visitor request, whatever asks for it.
	//
	// This makes up to three HTTP calls with a 20-second timeout, and it IS reachable from a
	// public page load: wp_enqueue_scripts → wpoptimizing_assets_auto_apply() → the presence
	// map → the KB filter → here, whenever the local CSV is a day old. On a site whose gateway
	// is slow or unreachable that is up to a minute added to somebody's page, which in a
	// performance plugin is indefensible. The stale copy is fine — attribution data a day old
	// is still attribution data — and the daily cron job refreshes it off the visitor's path.
	//
	// It also keeps this function's own dependencies honest: the layer map lives in
	// resolvers/kb-layers.php, which only loads for the engine.
	if ( function_exists( 'wpoptimizing_request_needs_engine' ) && ! wpoptimizing_request_needs_engine() ) {
		return false;
	}
	$key = function_exists( 'wpoptimizing_kb_file_key' ) ? wpoptimizing_kb_file_key( $filename ) : '';
	// Reciprocal sharing: no consent → no download of the shared dataset.
	if ( ! wpoptimizing_has_share_consent() ) {
		wpoptimizing_kb_download_note( $key, 'no-consent' );
		return false;
	}
	if ( '' === $key ) {
		wpoptimizing_kb_download_note( basename( (string) $filename ), 'no-key' );
		return false;
	}
	// Fetched (or confirmed unchanged) recently: the copy on disk is the current one.
	$have = file_exists( $dest );
	if ( ! $force && $have && function_exists( 'wpoptimizing_kb_download_can_wait' ) && wpoptimizing_kb_download_can_wait( $key ) ) {
		return true;
	}
	$etags   = (array) get_option( 'wpoptimizing_kb_etags', array() );
	$headers = array(
		'Authorization' => 'Bearer ' . wpoptimizing_get_data_token(),
		'Accept'        => 'text/csv',
	);
	// Only with a copy to fall back on: a 304 without one would leave the layer missing.
	if ( $have && ! empty( $etags[ $key ] ) ) {
		$headers['If-None-Match'] = (string) $etags[ $key ];
	}
	// Retry transient failures (network error, 429, 5xx) a couple of times with a
	// short backoff so a brief blip at the daily refresh does not leave the KB stale
	// for a whole day. A 4xx (other than 429) is permanent — fail fast, no retry.
	$attempts = (int) apply_filters( 'wpoptimizing_kb_download_attempts', 3 );
	$attempts = max( 1, min( 5, $attempts ) );
	$resp     = null;
	$code     = 0;
	for ( $i = 0; $i < $attempts; $i++ ) {
		if ( $i > 0 ) {
			usleep( 300000 * $i ); // 0.3s, 0.6s … bounded backoff.
		}
		$resp = wp_remote_get(
			wpoptimizing_gateway_url() . '?file=' . $key,
			array(
				'timeout'   => 20,
				'sslverify' => true,
				'headers'   => $headers,
			)
		);
		if ( is_wp_error( $resp ) ) {
			continue; // transient → retry
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( 200 === $code || 304 === $code ) {
			break;
		}
		// 409 and 507 retry instead of aborting: they mean "busy" and "no disk", not "your
		// request is wrong". Treating every non-429 4xx as permanent stopped the retry loop
		// dead on a 409 — which the gateway returns exactly when another site is merging
		// this same layer, so the busier the KB, the more a healthy client gave up.
		// An HTML answer is retried too: it is the site in front of the gateway, not us.
		$outcome = wpoptimizing_upload_outcome(
			$code,
			(string) wp_remote_retrieve_header( $resp, 'content-type' ),
			substr( (string) wp_remote_retrieve_body( $resp ), 0, 512 )
		);
		if ( 'failed' === $outcome ) {
			wpoptimizing_kb_download_note( $key, 'http-' . $code );
			return false; // permanent client error → don't hammer the gateway
		}
	}
	if ( 304 === $code && $have ) {
		// Unchanged: the copy is current. Touched so the freshness panel says so.
		touch( $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch
		wpoptimizing_kb_download_note( $key, 'unchanged' );
		return true;
	}
	if ( is_wp_error( $resp ) || 200 !== $code ) {
		// Se anota POR QUÉ, y no sólo que no llegó: una capa que falla en silencio no se mira.
		// Así ha estado `manifest` desde que existe, con el panel diciéndolo en rojo sin más.
		wpoptimizing_kb_download_note( $key, is_wp_error( $resp ) ? 'network' : 'http-' . $code );
		return false; // out of attempts; keep the previous good copy
	}
	$body = (string) wp_remote_retrieve_body( $resp );
	if ( '' === $body ) {
		wpoptimizing_kb_download_note( $key, 'empty' );
		return false;
	}
	// Guard against a misrouted error page (e.g. an HTML 404/500 served with a 200)
	// being cached as if it were the dataset: a CSV never starts with '<'. Keeping
	// the previous good copy is far better than poisoning the local KB for a day.
	if ( '<' === substr( ltrim( $body ), 0, 1 ) ) {
		wpoptimizing_kb_download_note( $key, 'html' );
		return false;
	}
	$tmp = $dest . '.tmp.' . wp_generate_password( 6, false, false );
	if ( false === file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents
		wpoptimizing_kb_download_note( $key, 'write' );
		return false;
	}
	$movido = rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	wpoptimizing_kb_download_note( $key, $movido ? 'ok' : 'write' );
	if ( $movido ) {
		// An older gateway sends no ETag: then nothing is kept, and the next request is plain.
		$etag = trim( (string) wp_remote_retrieve_header( $resp, 'etag' ) );
		if ( '' !== $etag && strlen( $etag ) <= 64 ) {
			$etags[ $key ] = $etag;
		} else {
			unset( $etags[ $key ] );
		}
		update_option( 'wpoptimizing_kb_etags', $etags, false );
	}
	return $movido;
}

/**
 * Anotar el resultado de una descarga, si el motor está cargado.
 *
 * El registro vive con el mapa de capas (resolvers/kb-layers.php), que sólo se compila para
 * el motor; esta función existe para que las ocho salidas de arriba no lleven cada una su
 * propio `function_exists`, que es como se olvida una.
 *
 * @param string $key     Clave de la capa.
 * @param string $outcome Código del resultado.
 * @return void
 */
function wpoptimizing_kb_download_note( $key, $outcome ) {
	if ( function_exists( 'wpoptimizing_kb_download_record' ) ) {
		wpoptimizing_kb_download_record( $key, $outcome );
	}
}

/**
 * Fetch the remote token used to access protected CSVs.
 *
 * Now returns the per-install data token (replacing the old license-gated static
 * token.txt). Kept as a thin wrapper so existing callers keep working.
 *
 * @return string|WP_Error
 */
function wpoptimizing_fetch_remote_token() {
	$token = wpoptimizing_get_data_token();
	return ( '' !== $token ) ? $token : '';
}

/**
 * Derive an encryption key from the remote token.
 *
 * @param string $token Remote token.
 * @return string Binary key.
 */
function wpoptimizing_derive_key( $token ) {
	// hash_hkdf() is available since PHP 7.1.2; plugin requires PHP 7.4, so always present.
	// It rejects an empty key, though: on a fresh install / gateway grace mode there is
	// no per-install token yet, so fall back to a stable per-site secret. The CSV cache
	// is re-fetched (cache miss, not a fatal) once a real token re-keys it.
	$token = (string) $token;
	if ( '' === $token ) {
		$token = function_exists( 'wp_salt' ) ? wp_salt( 'secure_auth' ) : 'wpoptimizing-default-key';
	}
	return hash_hkdf( 'sha256', $token, 32, 'wpoptimizing-csv', '' );
}

/**
 * Encrypt CSV content.
 *
 * @param string $plaintext CSV content.
 * @param string $token     Remote token.
 * @return string|WP_Error
 */
function wpoptimizing_encrypt_csv( $plaintext, $token ) {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return new WP_Error( 'openssl_missing', 'OpenSSL not available.' );
	}

	$key = wpoptimizing_derive_key( $token );
	$iv  = random_bytes( 12 );
	$tag = '';

	$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	if ( false === $cipher ) {
		return new WP_Error( 'encrypt_failed', 'Encryption failed.' );
	}

	$payload = array(
		'v'   => 1,
		'iv'  => base64_encode( $iv ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary AES-256-GCM IV encoded for JSON transport, not code obfuscation.
		'tag' => base64_encode( $tag ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary AES-256-GCM auth tag, not code obfuscation.
		'ct'  => base64_encode( $cipher ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary AES-256-GCM ciphertext, not code obfuscation.
		'ts'  => time(),
	);

	return wp_json_encode( $payload );
}

/**
 * Decrypt CSV content.
 *
 * @param string $payload Encrypted JSON payload.
 * @param string $token   Remote token.
 * @return string|WP_Error
 */
function wpoptimizing_decrypt_csv( $payload, $token ) {
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		return new WP_Error( 'openssl_missing', 'OpenSSL not available.' );
	}

	$data = json_decode( $payload, true, 10 );
	if ( ! is_array( $data ) || ! isset( $data['v'] ) || empty( $data['iv'] ) || empty( $data['tag'] ) || empty( $data['ct'] ) ) {
		return new WP_Error( 'invalid_payload', 'Invalid encrypted payload.' );
	}

	$iv  = base64_decode( $data['iv'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own AES-256-GCM IV, not code obfuscation.
	$tag = base64_decode( $data['tag'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own AES-256-GCM auth tag.
	$ct  = base64_decode( $data['ct'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding our own AES-256-GCM ciphertext.
	if ( false === $iv || false === $tag || false === $ct ) {
		return new WP_Error( 'invalid_payload', 'Invalid encrypted payload.' );
	}

	$key = wpoptimizing_derive_key( $token );
	$out = openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
	if ( false === $out ) {
		return new WP_Error( 'decrypt_failed', 'Decryption failed.' );
	}

	return (string) $out;
}

/**
 * Fetch a protected CSV through the gateway.
 *
 * @param string $which 'db' or 'files'.
 * @param string $token Remote token.
 * @return string|WP_Error
 */
function wpoptimizing_fetch_remote_csv( $which, $token ) {
	// Reciprocal sharing: no consent → no download of the shared dataset.
	if ( ! wpoptimizing_has_share_consent() ) {
		return '';
	}
	$which = ( 'files' === $which ) ? 'files' : 'db';
	$url   = wpoptimizing_gateway_url() . '?file=' . $which;

	$response = wp_remote_get(
		$url,
		array(
			'timeout'   => 30,
			'sslverify' => true,
			'headers'   => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'text/csv',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	$body = (string) wp_remote_retrieve_body( $response );

	if ( 200 !== $code ) {
		return new WP_Error( 'remote_error', 'HTTP ' . $code . ': ' . substr( $body, 0, 200 ) );
	}

	return $body;
}

/**
 * Write encrypted CSV to local cache.
 *
 * @param string $which 'db' or 'files'.
 * @param string $csv   CSV content.
 * @param string $token Remote token.
 * @return bool|WP_Error
 */
function wpoptimizing_store_csv_cache( $which, $csv, $token ) {
	if ( ! wpoptimizing_ensure_protected_dir() ) {
		return new WP_Error( 'storage_unavailable', 'Protected dir unavailable.' );
	}

	$payload = wpoptimizing_encrypt_csv( $csv, $token );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	// Round-trip check BEFORE publishing: encrypt then decrypt, so a payload that cannot be
	// read back never becomes the live cache (which would strand attribution on an
	// undecryptable file until the daily refresh).
	$verify = wpoptimizing_decrypt_csv( $payload, $token );
	if ( is_wp_error( $verify ) || $verify !== $csv ) {
		return new WP_Error( 'cache_verify_failed', 'Encrypted cache did not round-trip.' );
	}

	$path = ( 'files' === $which ) ? WPOPT_PROTECTED_FILES : WPOPT_PROTECTED_DB;

	// Atomic write: tmp + rename, so a concurrent reader sees either the old complete cache or
	// the new one, never the half-written encrypted payload that a direct write to $path left
	// exposed (which read back as a decrypt error and, downstream, as an empty dataset).
	$tmp = $path . '.tmp';
	$ok  = file_put_contents( $tmp, $payload, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === $ok ) {
		return false;
	}
	if ( ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename, WordPress.WP.AlternativeFunctions.rename_rename -- atomic replace (tmp+rename); WP_Filesystem::move() is not atomic.
		wp_delete_file( $tmp );
		return false;
	}
	return true;
}


/**
 * Refresh both CSV caches (db + files).
 *
 * From the layer copy wpoptimizing_download_kb_file() keeps on disk, which is the same
 * `?file=db` / `?file=files` download: this used to fetch both again on its own, daily and on
 * every decrypt miss, unconditionally — the same 5 MB twice. Only without that copy (the
 * layer map not loaded, a failed download) does it still ask the gateway directly.
 *
 * @return void
 */
function wpoptimizing_refresh_csv_cache() {
	// Per-install token (free + paid). Empty is fine while the gateway is in
	// grace; the install will register on demand.
	$token = wpoptimizing_fetch_remote_token();
	if ( is_wp_error( $token ) ) {
		return;
	}

	$layer_files = array(
		'db'    => 'wp-db-distrib.csv',
		'files' => 'wp-files-distrib.csv',
	);
	foreach ( $layer_files as $which => $file ) {
		$local = trailingslashit( WPOPT_DIR ) . $file;
		$csv   = '';
		if ( wpoptimizing_download_kb_file( $file, $local ) && is_readable( $local ) ) {
			$csv = (string) file_get_contents( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		}
		if ( '' === $csv ) {
			$csv = wpoptimizing_fetch_remote_csv( $which, $token );
		}
		if ( is_wp_error( $csv ) || '' === $csv ) {
			continue;
		}
		wpoptimizing_store_csv_cache( $which, $csv, $token );
	}
}

/**
 * Schedule daily refresh of the CSV cache.
 *
 * @return void
 */
function wpoptimizing_schedule_csv_cache_refresh() {
	// NOTE: this runs on init for EVERY request (front end included), so it must stay free:
	// no filesystem work here. wpoptimizing_ensure_protected_dir() is called by the writers
	// (wpoptimizing_store_csv_cache(), the scan pipeline), which is where it matters.

	// RECIPROCIDAD, NO PAGO. Esto exigía una LICENCIA para programar el refresco diario del
	// conjunto compartido, y desprogramaba el evento si no la había. Contradecía el resto del
	// producto: wpoptimizing_download_kb_file() se controla por CONSENTIMIENTO y su propio
	// comentario dice "download is license-free", y la pantalla de ajustes se lo promete al
	// usuario. O sea: le decíamos al usuario gratuito que la descarga era gratis mientras le
	// quitábamos el cron que la hace.
	//
	// La regla del producto es la que estaba escrita en todas partes menos aquí: se recibe si
	// se comparte, no si se paga. Una licencia desbloquea funcionalidades; el conjunto
	// compartido no está en venta.
	if ( ! wpoptimizing_has_share_consent() ) {
		$timestamp = wp_next_scheduled( 'wpoptimizing_refresh_csv_cache' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wpoptimizing_refresh_csv_cache' );
		}
		return;
	}
	if ( ! wp_next_scheduled( 'wpoptimizing_refresh_csv_cache' ) ) {
		wp_schedule_event( time() + 300, 'daily', 'wpoptimizing_refresh_csv_cache' );
	}
}



register_activation_hook( __FILE__, 'wpoptimizing_activate' );

/**
 * Per-site activation setup. Safe to call inside a switch_to_blog() context.
 *
 * @return void
 */
function wpoptimizing_activate_single_site() {
	wpoptimizing_grant_cap();
	wpoptimizing_schedule_csv_cache_refresh();
	wpoptimizing_schedule_unified_generation();
	// Register this install with the data gateway (free + paid) to obtain a
	// per-install, domain-bound token. Best-effort; retried on demand later.
	if ( function_exists( 'wpoptimizing_register_install' ) ) {
		wpoptimizing_register_install();
	}
	update_option( 'wpoptimizing_first_run', '1', false );
	// Begin measuring autoload read-frequency right away (no button needed).
	wpoptimizing_load_lib( 'includes/scanners/class-autoload-analyzer.php' );
	if ( function_exists( 'wpoptimizing_autoload_tracking_ensure' ) ) {
		wpoptimizing_autoload_tracking_ensure();
	}

	/**
	 * Fires after WPOptimizing has been set up on a single site (also for each
	 * subsite during a network activation and for newly created subsites).
	 *
	 * @since 0.1.0
	 * @param int $blog_id The blog the plugin was activated on.
	 */
	do_action( 'wpoptimizing_site_activated', get_current_blog_id() );
}

/**
 * When a new subsite is created on a network where the plugin is network-active,
 * run the per-site setup so the new blog is ready to use immediately.
 *
 * @param WP_Site $new_site The new site object.
 * @return void
 */
function wpoptimizing_on_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( WPOPTIMIZING_BASENAME ) ) {
		return;
	}
	switch_to_blog( (int) $new_site->blog_id );
	wpoptimizing_activate_single_site();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'wpoptimizing_on_new_site', 900 );

// ---------------------------------------------------------------------------
// Rate limiting
// ---------------------------------------------------------------------------


// ---------------------------------------------------------------------------
// Audit log
// ---------------------------------------------------------------------------


register_deactivation_hook( __FILE__, 'wpoptimizing_deactivate' );

/*
 * Plugin uninstall (deletion) is handled by uninstall.php, which WordPress runs in
 * its own isolated context and which takes precedence over register_uninstall_hook().
 * It performs the complete data purge (gated by the "Delete all data on uninstall"
 * setting). The final CSV upload to the remote server already happens on deactivation
 * (wpoptimizing_deactivate), which always runs before a plugin can be deleted.
 */

add_action( 'init', 'wpoptimizing_schedule_csv_cache_refresh' );
add_action( 'wpoptimizing_refresh_csv_cache', 'wpoptimizing_refresh_csv_cache' );

/**
 * Schedule the daily unified dataset generation (always, license or not).
 *
 * @return void
 */
function wpoptimizing_schedule_unified_generation() {
	if ( ! wp_next_scheduled( 'wpoptimizing_unified_daily' ) ) {
		wp_schedule_event( time() + 300, 'daily', 'wpoptimizing_unified_daily' );
	}
}
add_action( 'init', 'wpoptimizing_schedule_unified_generation' );

/**
 * Keep autoload read-measurement self-running on every install (free or not),
 * front-end traffic included. Throttled to once per day.
 *
 * The throttle uses an AUTOLOADED option timestamp rather than a transient: an
 * expiring transient is not autoloaded, so reading it would cost one extra SQL
 * query on every request without a persistent object cache. The autoloaded
 * option is already in memory after WordPress loads `alloptions`, so the common
 * path is a zero-query in-memory read plus a time comparison. The option is only
 * written (busting the alloptions cache) at most once per day.
 *
 * @return void
 */
function wpoptimizing_autoload_tracking_heartbeat() {
	$last = (int) get_option( 'wpoptimizing_autoload_ensure_ts', 0 );
	if ( $last > 0 && ( time() - $last ) < DAY_IN_SECONDS ) {
		return; // The common path: an in-memory read of an autoloaded option, and out.
	}
	// Past the throttle — at most once a day — so it is worth the 40 KB.
	wpoptimizing_load_lib( 'includes/scanners/class-autoload-analyzer.php' );
	if ( ! function_exists( 'wpoptimizing_autoload_tracking_ensure' ) ) {
		return;
	}
	update_option( 'wpoptimizing_autoload_ensure_ts', time(), true );
	wpoptimizing_autoload_tracking_ensure();
}
add_action( 'init', 'wpoptimizing_autoload_tracking_heartbeat', 5 );


/**
 * Whether the site owner has explicitly consented to contributing anonymized
 * attribution metadata to the shared dataset. Opt-in: defaults to NO consent, so
 * nothing is uploaded until the user turns it on (WordPress.org-safe).
 *
 * Sharing is reciprocal: without consent the plugin keeps working on locally
 * captured attribution, but it neither uploads nor (optionally) downloads the
 * shared dataset.
 *
 * @return bool
 */
function wpoptimizing_has_share_consent() {
	return (bool) get_option( 'wpoptimizing_share_consent', false );
}

/**
 * Give back the user's own sharing choice on installs where activating a licence turned
 * sharing on by itself (versions before 18/10/2026 stored the earlier choice in
 * wpoptimizing_share_consent_prelicense). Runs once: the option is gone afterwards.
 *
 * @return void
 */
function wpoptimizing_share_consent_restore_prelicense() {
	$prev = get_option( 'wpoptimizing_share_consent_prelicense', 'unset' );
	if ( 'unset' === $prev ) {
		return;
	}
	update_option( 'wpoptimizing_share_consent', (int) $prev, true );
	delete_option( 'wpoptimizing_share_consent_prelicense' );
}
add_action( 'admin_init', 'wpoptimizing_share_consent_restore_prelicense' );


/**
 * Upload unified CSVs to the remote aggregator.
 *
 * @param bool $manual True for a sync the user asked for (see wpoptimizing_upload_gate()).
 * @return array Per-layer outcome: HTTP status, 'empty', 'backoff', 'budget', 'wait' or an error.
 */
function wpoptimizing_upload_unified_files( $manual = false ) {
	// Opt-in gate: never upload without explicit consent.
	if ( ! wpoptimizing_has_share_consent() ) {
		return array();
	}
	$site  = wpoptimizing_site_id();
	$token = wpoptimizing_get_data_token();

	$targets = array(
		'db'     => WPOPT_CSV_UNIFIED_DB,
		'files'  => WPOPT_CSV_UNIFIED_FILES,
		'assets' => WPOPT_CSV_ASSETS,
	);

	// Per-file outcome so callers (e.g. the manual "Sync KB now" action) can report
	// what happened: HTTP status code, 'empty' (nothing to send) or an error string.
	$result = array();

	foreach ( $targets as $key => $path ) {
		// Stay quiet while this layer is backing off, over its daily cap or inside its spacing.
		// Checked BEFORE the file is read, so a held layer costs nothing at all. The CSV stays on
		// disk (it is deleted only after a 2xx), so whatever was held goes up next time.
		$gate = wpoptimizing_upload_gate( $key, $manual );
		if ( '' !== $gate ) {
			$result[ $key ] = $gate;
			continue;
		}
		if ( ! file_exists( $path ) || 0 === filesize( $path ) ) {
			$result[ $key ] = 'empty';
			continue;
		}

		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $body || '' === $body ) {
			$result[ $key ] = 'empty';
			continue;
		}
		// Already there: nothing to send. The CSV goes like after an accepted upload.
		$hash = wpoptimizing_upload_body_hash( $body, 'assets' === $key );
		if ( wpoptimizing_upload_unchanged( $key, $hash, $manual ) ) {
			$result[ $key ] = 'unchanged';
			if ( ! apply_filters( 'wpoptimizing_keep_local_contribution_csv', false ) ) {
				wp_delete_file( $path );
			}
			continue;
		}

		$response = wp_remote_post(
			wpoptimizing_gateway_url() . '?file=' . $key,
			array(
				'headers' => array(
					'Content-Type'  => 'text/csv; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
					'X-WPO-Site'    => $site, // used only as a grace-mode fallback server-side
				),
				'body'    => $body,
				'timeout' => 30,
			)
		);

		wpoptimizing_upload_count( $key, ! is_wp_error( $response ) );
		if ( is_wp_error( $response ) ) {
			$result[ $key ] = $response->get_error_message();
			wpoptimizing_upload_record_attempt( $key, false );
			continue;
		}
		$code           = (int) wp_remote_retrieve_response_code( $response );
		$result[ $key ] = $code;
		if ( wpoptimizing_upload_busy( $key, $response ) ) {
			$result[ $key ] = 'busy';
			continue; // The gateway is busy for everyone: wait, no failure, CSV kept.
		}
		// Only a real fault arms the back-off. A 409 (another site is merging this layer) or a
		// 507 (gateway out of disk) leaves the counter untouched: nothing is wrong with our
		// file, and counting them would make a client punish itself for the KB being busy.
		// The content type and body go in too, because an HTML answer did not come from the
		// gateway at all (maintenance mode, a WAF, a host error page) and must not count —
		// not even a 200, which would delete the CSV below without anything having stored it.
		$outcome = wpoptimizing_upload_outcome(
			$code,
			(string) wp_remote_retrieve_header( $response, 'content-type' ),
			substr( (string) wp_remote_retrieve_body( $response ), 0, 512 )
		);
		if ( 'ok' === $outcome || 'failed' === $outcome || 'throttled' === $outcome ) {
			wpoptimizing_upload_record_attempt( $key, 'ok' === $outcome );
		}
		if ( 'ok' === $outcome ) {
			wpoptimizing_upload_remember( $key, $hash );
		}

		// Don't keep the contribution CSVs on disk: their schema reveals what we
		// collect for the KB. Delete after a successful upload — they are rebuilt on
		// the next scan/sync. The Alimentador regenerates before hashing, so this
		// never breaks its change-detection. Filterable for debugging.
		if ( 'ok' === $outcome && ! apply_filters( 'wpoptimizing_keep_local_contribution_csv', false ) ) {
			wp_delete_file( $path );
		}
	}

	return $result;
}



// Out-of-band flush: the same generate+upload job, run shortly after a trigger
// such as a plugin being deactivated/deleted, so its already-captured attribution
// is shipped before it could be lost between daily uploads. Reuses the daily job
// (and its opt-out filter).

/**
 * Schedule an immediate (async, non-blocking) dataset flush.
 *
 * @return void
 */
function wpoptimizing_schedule_data_flush() {
	if ( ! wp_next_scheduled( 'wpoptimizing_data_flush' ) ) {
		wp_schedule_single_event( time() + 5, 'wpoptimizing_data_flush' );
	}
	if ( ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
		spawn_cron( time() );
	}
}

// Optional: allow a local dataset dir (e.g., private datasets).
if ( defined( 'WPOPT_DATASET_LOCAL_DIR' ) && WPOPT_DATASET_LOCAL_DIR ) {
	add_filter(
		'wpoptimizing_dataset_local_dir',
		static function () {
			return (string) WPOPT_DATASET_LOCAL_DIR;
		}
	);
}

/**
 * Register the top-level admin menu page.
 *
 * Capability: manage_options.
 * Security: Page callback performs its own capability check.
 *
 * @return void
 */
/**
 * The white WPOptimizing isotype as an inline SVG data URI, cached per request. Shared by
 * the admin-menu icon and the admin-bar icon so the brand mark lives in one place. Empty
 * string if the asset cannot be read (callers fall back to a dashicon).
 *
 * @return string data:image/svg+xml;base64,… or ''.
 */
function wpoptimizing_isotype_data_uri() {
	static $uri = null;
	if ( null !== $uri ) {
		return $uri;
	}
	$uri = '';
	$svg = plugin_dir_path( WPOPTIMIZING_FILE ) . 'assets/images/isotipo-blanco.svg';
	if ( is_readable( $svg ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = file_get_contents( $svg );
		if ( is_string( $data ) && '' !== $data ) {
			$uri = 'data:image/svg+xml;base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
	}
	return $uri;
}

/**
 * Render the main admin page (non‑blocking).
 *
 * Responsibilities:
 *  1) Gate access by capability and render the page frame (title + description).
 *  2) Process POST cleanup/uninstall actions via WPOPT_Admin_UI::maybe_handle_post()
 *     (this method performs nonce + capability checks internally).
 *  3) Provide a synchronous “Run analysis now” action (with nonce) for environments
 *     where WP‑Cron loopback may be blocked. The action:
 *        - Runs the full analysis immediately,
 *        - Stores a transient-based flash message,
 *        - Redirects (PRG pattern) to avoid duplicate actions on refresh.
 *  4) Print last analysis metadata (time, reason).
 *  5) Render the tabbed UI using whatever CSVs exist at this moment (no blocking).
 *
 * Security:
 *  - Capability check (manage_options) guards the whole page.
 *  - Action links are nonce-protected and validated.
 *  - All dynamic output is properly escaped with esc_* helpers.
 *
 * I18n:
 *  - All user-facing strings are run through translation functions using the
 *    'wpoptimizing' text domain.
 *
 * Performance:
 *  - The heavy analysis runs either via cron or synchronously on demand.
 *  - Page render never blocks on a long scan.
 *
 * @return void
 */
/**
 * SCAN SCHEDULING (WP-Cron).
 */


/**
 * Schedule a single analysis run via WP‑Cron (idempotent).
 *
 * Tries to tick WP‑Cron immediately via spawn_cron() if available so that
 * users often see fresh results after a quick refresh.
 *
 * @param string $reason One of: 'activation'|'plugin-change'|'theme-change'|'upgrade'|'manual'.
 * @return void
 */
function wpoptimizing_schedule_scan( $reason = 'manual' ) {
	$hook = 'wpoptimizing_cron_scan';
	if ( ! wpoptimizing_scan_event_pending() ) {
		// Programa casi inmediato para no bloquear upgrades/activaciones en curso.
		wp_schedule_single_event( time() + 5, $hook, array( $reason ) );
		update_option( 'wpoptimizing_scan_scheduled_reason', sanitize_key( $reason ), false );
	}

	// Intento de disparar cron ya (loopback). Si está deshabilitado, no pasa nada.
	if ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) {
		if ( function_exists( 'spawn_cron' ) ) {
			// Fuerza un “tick” ahora.
			spawn_cron( time() );
		} else {
			// Fallback mínimo.
			wp_remote_post(
				site_url( 'wp-cron.php' ),
				array(
					'timeout'  => 0.5,
					'blocking' => false,
					'body'     => array( 'doing_wp_cron' => microtime( true ) ),
				)
			);
		}
	}
}


/**
 * Start an analysis the user asked for: queue it and flag that someone is watching, so the
 * progress overlay drives it from its own polls. The one path for every "Analyze now" — the
 * link and the in-page button.
 *
 * @return void
 */
function wpoptimizing_start_manual_scan() {
	wpoptimizing_schedule_scan( 'manual' );
	set_transient( 'wpoptimizing_scan_pending_ui', 1, 5 * MINUTE_IN_SECONDS );
	set_transient( 'wpoptimizing_scan_pending_since', time(), 5 * MINUTE_IN_SECONDS );
}


/**
 * Whether a one-off analysis is queued in WP-Cron, whatever its reason.
 *
 * The event carries the reason as its argument, and wp_next_scheduled( $hook ) only finds the
 * events scheduled WITHOUT arguments, so every check that asked it answered "nothing queued".
 * A second click queued a second scan, cancelling left the queued one in place, and the pump
 * that starts a manual scan in the foreground left it there too, so the server's cron ran the
 * whole analysis again afterwards. Measured on artprojectgroup.es on 2026-10-12: a "post-clean"
 * scan was queued while every check reported none.
 *
 * @return bool
 */
function wpoptimizing_scan_event_pending() {
	foreach ( (array) ( function_exists( '_get_cron_array' ) ? _get_cron_array() : array() ) as $hooks ) {
		if ( is_array( $hooks ) && ! empty( $hooks['wpoptimizing_cron_scan'] ) ) {
			return true;
		}
	}
	return false;
}


/**
 * Whether WPOptimizing's own persistent object-cache drop-in is the active one.
 * Cached per request (the admin bar/footer hooks may each call it).
 *
 * @return bool
 */
function wpoptimizing_object_cache_active() {
	static $active = null;
	if ( null !== $active ) {
		return $active;
	}
	$active = false;
	if ( function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache()
		&& defined( 'WPOPT_DROPIN_DEST' ) && file_exists( WPOPT_DROPIN_DEST ) ) {
		$head   = (string) file_get_contents( WPOPT_DROPIN_DEST, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header.
		$active = ( false !== strpos( $head, 'WPOPT_OBJECT_CACHE' ) );
	}
	return $active;
}


/**
 * Ensure the daily history-prune event is scheduled.
 *
 * @return void
 */
function wpoptimizing_history_prune_arm() {
	if ( ! wp_next_scheduled( 'wpoptimizing_history_prune' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wpoptimizing_history_prune' );
	}
}
add_action( 'init', 'wpoptimizing_history_prune_arm' );


require_once __DIR__ . '/includes/admin-bar.php'; // The toolbar menu.


/**
 * Persist the "Keep all revisions" per-post flag.
 *
 * @param int $post_id Post being saved.
 * @return void
 */
function wpoptimizing_revision_keep_save( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! isset( $_POST['wpoptimizing_keep_revisions_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wpoptimizing_keep_revisions_nonce'] ) ), 'wpoptimizing_keep_revisions' ) ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! empty( $_POST['wpopt_keep_revisions'] ) ) {
		update_post_meta( $post_id, '_wpopt_keep_revisions', '1' );
	} else {
		delete_post_meta( $post_id, '_wpopt_keep_revisions' );
	}
}
add_action( 'save_post', 'wpoptimizing_revision_keep_save' );





/**
 * Register custom cron schedules used by General cleanup.
 *
 * @param array $schedules Existing schedules.
 * @return array
 */
function wpoptimizing_add_general_cleanup_cron_schedules( $schedules ) {
	// TRANSLATED ONLY WHERE SOMEBODY CAN READ IT. `cron_schedules` is applied by _wp_cron()
	// on wp_loaded, so this callback runs on EVERY visitor request, and a __() here made
	// WordPress load the entire textdomain for a front-end page view — 313 KB of PHP on a
	// localised site — to fill in a label that is only ever displayed in wp-admin (our
	// Settings screen, Site Health, WP Crontrol). `interval` is what cron actually schedules
	// on, and it is identical either way, so no behaviour changes.
	$readable = is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );

	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => $readable ? __( 'Once Weekly', 'wpoptimizing' ) : 'Once Weekly',
		);
	}
	if ( ! isset( $schedules['monthly'] ) ) {
		$schedules['monthly'] = array(
			'interval' => 30 * DAY_IN_SECONDS,
			'display'  => $readable ? __( 'Once Monthly', 'wpoptimizing' ) : 'Once Monthly',
		);
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'wpoptimizing_add_general_cleanup_cron_schedules' );


/**
 * Keep General cleanup cron event in sync with saved schedule option.
 *
 * @return void
 */
function wpoptimizing_sync_general_cleanup_schedule() {
	$hook     = 'wpoptimizing_general_cleanup_cron';
	$schedule = (string) get_option( 'wpoptimizing_general_cleanup_schedule', 'manual' );
	if ( ! class_exists( 'WPOPT_General_Cleanup' ) ) {
		return; // It decides which schedules are allowed.
	}
	$allowed = WPOPT_General_Cleanup::get_allowed_schedules();
	if ( ! in_array( $schedule, $allowed, true ) ) {
		$schedule = 'manual';
	}

	$next = wp_next_scheduled( $hook );
	if ( 'manual' === $schedule ) {
		while ( $next ) {
			wp_unschedule_event( $next, $hook );
			$next = wp_next_scheduled( $hook );
		}
		return;
	}
	if ( ! $next ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, $schedule, $hook );
	}
}
add_action( 'init', 'wpoptimizing_sync_general_cleanup_schedule' );



/**
 * Next Unix timestamp at the off-peak start hour in the site timezone (so a recurring scan
 * first fires at the start of the quiet window).
 *
 * @return int
 */
function wpoptimizing_next_offpeak_timestamp() {
	$start = max( 0, min( 23, (int) get_option( 'wpoptimizing_scan_offpeak_start', 2 ) ) );
	try {
		$tz     = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		$now    = new DateTime( 'now', $tz );
		$target = new DateTime( 'today', $tz );
		$target->setTime( $start, 0, 0 );
		if ( $target->getTimestamp() <= $now->getTimestamp() ) {
			$target->modify( '+1 day' );
		}
		return $target->getTimestamp();
	} catch ( Exception $e ) {
		return time() + HOUR_IN_SECONDS;
	}
}

/**
 * Keep the recurring background-analysis event in sync with the saved schedule option
 * (default 'daily'; values off|daily|weekly). The event firing time is only the earliest the
 * scan may begin — the heavy stages still self-gate to the off-peak window.
 *
 * @param bool $force Reschedule even if an event already exists (after a settings change).
 * @return void
 */
function wpoptimizing_sync_scan_schedule( $force = false ) {
	$hook     = 'wpoptimizing_scan_cron';
	$schedule = (string) get_option( 'wpoptimizing_scan_schedule', 'daily' );
	if ( ! in_array( $schedule, array( 'off', 'daily', 'weekly' ), true ) ) {
		$schedule = 'daily';
	}
	$next = wp_next_scheduled( $hook );
	if ( 'off' === $schedule || $force ) {
		while ( $next ) {
			wp_unschedule_event( $next, $hook );
			$next = wp_next_scheduled( $hook );
		}
		if ( 'off' === $schedule ) {
			return;
		}
	}
	if ( ! wp_next_scheduled( $hook ) ) {
		$recurrence = ( 'weekly' === $schedule ) ? 'weekly' : 'daily';
		wp_schedule_event( wpoptimizing_next_offpeak_timestamp(), $recurrence, $hook );
	}
}
add_action( 'init', 'wpoptimizing_sync_scan_schedule' );


/**
 * TRIGGERS: installation/activation/uninstallation.
 */

register_activation_hook( __FILE__, 'wpoptimizing_on_activation' );

register_deactivation_hook( __FILE__, 'wpoptimizing_on_deactivation' );

/**
 * Plugin changes: activate/deactivate/delete → schedule scan.
 *
 * @param string $plugin_file Plugin basename.
 */
function wpoptimizing_on_plugin_activated( $plugin_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	wpoptimizing_schedule_scan( 'plugin-change' );
}
add_action( 'activated_plugin', 'wpoptimizing_on_plugin_activated', 10, 1 );

/**
 * Plugin deactivation trigger.
 *
 * @param string $plugin_file Plugin basename.
 * @return void
 */
function wpoptimizing_on_plugin_deactivated( $plugin_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	wpoptimizing_schedule_scan( 'plugin-change' );
	// Capture + ship now (plugin still on disk) so deleting it next never loses
	// its attribution data.
	wpoptimizing_schedule_data_flush();
}
add_action( 'deactivated_plugin', 'wpoptimizing_on_plugin_deactivated', 10, 1 );

/**
 * Plugin deletion trigger.
 *
 * @param string $plugin_file Plugin basename.
 * @param bool   $deleted     Deletion success flag.
 * @return void
 */
function wpoptimizing_on_plugin_deleted( $plugin_file, $deleted ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	wpoptimizing_schedule_scan( 'plugin-change' );
	// Ship whatever we already captured before regenerating without this plugin.
	wpoptimizing_schedule_data_flush();
}
add_action( 'deleted_plugin', 'wpoptimizing_on_plugin_deleted', 10, 2 );

/**
 * Theme changes: switch/delete/install/update → schedule scan.
 */
function wpoptimizing_on_switch_theme() {
	wpoptimizing_schedule_scan( 'theme-change' );
}
add_action( 'switch_theme', 'wpoptimizing_on_switch_theme' );
