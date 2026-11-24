<?php
/**
 * Plugin Name:  WPOptimizing — Runtime Map
 * Plugin URI:   https://wpoptimizing.com
 * Description:  Captures in real time what each plugin and theme registers (post types, taxonomies, options, shortcodes, widgets…) and builds a local attribution map used by WPOptimizing for orphan analysis. Automatically installed by the WPOptimizing plugin. Do not edit manually.
 * Version:      1.0.13
 * Author:       Art Project Group
 * Author URI:   https://artprojectgroup.es
 * License:      GPL-3.0-or-later
 * License URI:  https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:  wpoptimizing
 *
 * APG_WPOPT_RUNTIME v12  (signature — do not remove: WPOptimizing reads it to decide whether the
 * installed copy is stale. Until it existed this file was written once, at activation, and never
 * again: every later fix looked deployed and reached no existing site.)
 *
 * Rendimiento: coste cero cuando el ecosistema de plugins no ha cambiado.
 * La firma MD5 de la lista de plugins activos se guarda como option autoloaded;
 * en cada request la comprobación cuesta una única búsqueda en array PHP.
 * Los hooks — y debug_backtrace() — solo se registran cuando la firma difiere
 * de la almacenada, es decir, tras un cambio en los plugins instalados.
 */

// PANIC SWITCH. `define( 'WPOPT_DISABLE', true );` in wp-config.php stands every piece of
// WPOptimizing that runs BEFORE the plugin down — this drop-in, the other drop-ins and the
// must-use runtime — without FTP, without touching the database and without deactivating
// anything. Code that executes before WordPress has any recovery path of its own needs a way
// out that does not depend on the admin being reachable; this is it, and it is the first thing
// any agency asks for before installing software that loads this early.
if ( defined( 'WPOPT_DISABLE' ) && WPOPT_DISABLE ) {
	return;
}


defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
// This mu-plugin uses the "apg_wpopt_rtmap_" prefix throughout. PHPCS expects
// "wpoptimizing_" (the main plugin slug) but this file is intentionally a
// standalone mu-plugin with its own coherent namespace prefix.

define( 'APG_WPOPT_RTMAP_CSV', WP_CONTENT_DIR . '/wpoptimizing/apg-runtime-map.csv' );
define( 'APG_WPOPT_RTMAP_SIG_OPT', 'wpopt_rtmap_sig' );
/*
 * Firma PROPIA del escaneo de ajustes registrados, y no es duplicar la de arriba.
 * `wpopt_rtmap_sig` solo avanza cuando se escribió una fila nueva (ver on_shutdown), así que en
 * un sitio ya mapeado —donde no hay nada nuevo que escribir— nunca avanza: el escaneo volvía a
 * correr en CADA petición de admin, incluidas admin-ajax y el heartbeat, descifrando y
 * parseando el mapa completo cada vez. Esta se graba cuando el escaneo TERMINA, haya añadido
 * filas o no, que es lo que de verdad significa «este ecosistema ya está escaneado».
 */
define( 'APG_WPOPT_RTMAP_SETTINGS_SIG_OPT', 'wpopt_rtmap_settings_sig' );
// Asset registry (front-end CSS/JS handles) — KB collection for Smart Assets.
// The scanner re-seeds static rows; this runtime pass enriches them (src + deps).
define( 'APG_WPOPT_ASSETS_CSV', WP_CONTENT_DIR . '/wpoptimizing/wp-assets.csv' );

// ── Entry point ───────────────────────────────────────────────────────────────

add_action(
	'plugins_loaded',
	static function () {
		// get_option() reads from the in-memory alloptions cache → free.
		if ( get_option( APG_WPOPT_RTMAP_SIG_OPT ) === apg_wpopt_rtmap_sig() ) {
			return; // Ecosystem unchanged: zero additional work.
		}
		apg_wpopt_rtmap_register_hooks();
		add_action( 'shutdown', 'apg_wpopt_rtmap_on_shutdown', 999 );
	},
	1
);

// Write capture runs regardless of ecosystem signature (priority 2).
// Covers meta writes (post/user/term) and option saves (added + updated).
// Context guard is inside each function so REST_REQUEST is resolved at call time.
// Adding the same shutdown callback twice is safe: WordPress deduplicates by name.
add_action(
	'plugins_loaded',
	static function () {
		add_action( 'added_post_meta', 'apg_wpopt_rtmap_capture_post_meta_write', 10, 3 );
		add_action( 'updated_post_meta', 'apg_wpopt_rtmap_capture_post_meta_write', 10, 3 );
		add_action( 'added_user_meta', 'apg_wpopt_rtmap_capture_user_meta_write', 10, 3 );
		add_action( 'updated_user_meta', 'apg_wpopt_rtmap_capture_user_meta_write', 10, 3 );
		add_action( 'added_term_meta', 'apg_wpopt_rtmap_capture_term_meta_write', 10, 3 );
		add_action( 'updated_term_meta', 'apg_wpopt_rtmap_capture_term_meta_write', 10, 3 );
		// Options: capture both creation and updates so settings saves are always recorded.
		add_action( 'added_option', 'apg_wpopt_rtmap_capture_option_write', 10, 1 );
		add_action( 'updated_option', 'apg_wpopt_rtmap_capture_option_write', 10, 1 );
		// Scan all registered settings at admin_init for attribution via sanitize callbacks.
		add_action( 'admin_init', 'apg_wpopt_rtmap_scan_registered_settings', 9999 );
		// SQL-level write capture: catches HPOS, direct $wpdb calls, and settings-API writes.
		add_filter( 'query', 'apg_wpopt_rtmap_capture_write_query', 1 );
		add_action( 'shutdown', 'apg_wpopt_rtmap_on_shutdown', 999 );
	},
	2
);

// ── Autoload read instrumentation ──────────────────────────────────────────────
// Only while a measurement window is open (wpoptimizing_autoload_track_until is
// an AUTOLOADED timestamp, so the check is a free array lookup). Records which
// NON-autoloaded options are actually read each request, so WPOptimizing can
// suggest the ones worth autoloading based on real frequency, not guesses.

add_action(
	'plugins_loaded',
	static function () {
		$until = (int) get_option( 'wpoptimizing_autoload_track_until', 0 );
		if ( $until <= time() ) {
			return; // Not measuring: zero overhead.
		}
		// Sample: only instrument a fraction of requests, so anonymous front-end
		// traffic carries effectively zero per-query cost even while the monthly
		// measurement window is open. The analyzer ranks options by RELATIVE read
		// frequency, which sampling preserves across the multi-day window. Set the
		// rate to 1 (or filter it) to measure every request.
		$sample = (int) apply_filters( 'wpoptimizing_autoload_sample_rate', 20 );
		if ( $sample > 1 && 1 !== mt_rand( 1, $sample ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Sampling only; no crypto needed, and pluggable wp_rand() may not be loaded this early.
			return;
		}
		$GLOBALS['apg_wpopt_autoload_reads'] = array();
		add_filter( 'query', 'apg_wpopt_capture_option_read', 1 );
		add_action( 'shutdown', 'apg_wpopt_flush_option_reads', 998 );

		// Also measure REAL usage of the largest AUTOLOADED options. They are served
		// from the alloptions cache, so they never appear in the SQL stream above — yet
		// they load on EVERY request. Knowing which big autoloaded options are never
		// actually read lets WPOptimizing de-autoload them surgically (by use, not
		// guesswork) and feeds that as a crowd signal to the shared KB. Bounded: only the
		// top-N largest are instrumented, so the cost is N add_filter() calls when sampled.
		apg_wpopt_setup_autoload_usage_capture();
	},
	1
);

// ── Front-end asset snapshot ────────────────────────────────────────────────
// Sampled, front-end only: at the end of the request, record which script/style
// handles were actually printed, with their owner (resolved from src), normalized
// src and dependency handles. Feeds the Smart Assets knowledge base. Heavily
// sampled so a public page carries effectively no overhead.

add_action(
	'plugins_loaded',
	static function () {
		if ( is_admin()
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		// The asset registry is ONLY a KB contribution. Don't write it on sites that
		// are not sharing — no reason to put our collection schema on their disk.
		if ( ! get_option( 'wpoptimizing_share_consent' ) ) {
			return;
		}
		$rate = (int) apply_filters( 'wpoptimizing_assets_sample_rate', 25 );
		if ( $rate > 1 && 1 !== mt_rand( 1, $rate ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Sampling only; no crypto needed.
			return;
		}
		add_action( 'shutdown', 'apg_wpopt_capture_assets', 5 );
	},
	1
);

/**
 * Snapshot the script/style handles printed on this front-end request.
 *
 * @return void
 */
function apg_wpopt_capture_assets() {
	$deps = array(
		'script' => isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null,
		'style'  => isset( $GLOBALS['wp_styles'] ) ? $GLOBALS['wp_styles'] : null,
	);
	$seen = array();
	foreach ( $deps as $kind => $dep ) {
		if ( ! ( $dep instanceof WP_Dependencies ) ) {
			continue;
		}
		foreach ( (array) $dep->done as $handle ) {
			if ( isset( $seen[ $kind . $handle ] ) || ! isset( $dep->registered[ $handle ] ) ) {
				continue;
			}
			$seen[ $kind . $handle ] = true;
			$obj                     = $dep->registered[ $handle ];
			$src                     = isset( $obj->src ) && is_string( $obj->src ) ? $obj->src : '';
			$rel                     = apg_wpopt_normalize_asset_src( $src );
			$owner                   = apg_wpopt_asset_owner_from_src( $rel );
			// Src-less handles (aliases like jquery, inline/placeholder core styles,
			// plugin/theme inline assets) can't be attributed by path. Recover them
			// so the KB doesn't get "unknown" for core: WP's own default registries
			// (authoritative) for core, else handle-prefix vs the active slugs.
			if ( 'unknown' === $owner['type'] ) {
				$core = apg_wpopt_core_asset_handles();
				$up   = apg_wpopt_asset_owner_from_upload_dir( $rel, apg_wpopt_active_slug_index() );
				if ( null !== $up ) {
					$owner = $up;
				} elseif ( isset( $core[ (string) $handle ] ) ) {
					$owner = array(
						'type' => 'core',
						'slug' => 'wordpress-core',
					);
				} else {
					$byh = apg_wpopt_asset_owner_by_handle( (string) $handle );
					if ( null !== $byh ) {
						$owner = $byh;
					}
				}
			}
			if ( 'core' === $owner['type'] && '' === (string) $owner['slug'] ) {
				$owner['slug'] = 'wordpress-core';
			}
			$dlist = isset( $obj->deps ) ? implode( '|', array_map( 'strval', (array) $obj->deps ) ) : '';
			apg_wpopt_assets_write( $kind, (string) $handle, $owner['type'], $owner['slug'], $rel, $dlist );
		}
	}
}

/**
 * Append one asset row to the registry CSV (LOCK_EX, header once, size-capped).
 * Mirrors the main plugin's wpoptimizing_* asset helpers; kept inline because the
 * feeder is a standalone mu-plugin.
 *
 * @param string $kind       script|style.
 * @param string $handle     Handle.
 * @param string $owner_type plugin|theme|core|unknown.
 * @param string $owner_slug Owner slug.
 * @param string $src_rel    Normalized src.
 * @param string $deps       Pipe-joined deps.
 * @return void
 */
function apg_wpopt_assets_write( $kind, $handle, $owner_type, $owner_slug, $src_rel, $deps ) {
	if ( '' === $handle ) {
		return;
	}
	$path = APG_WPOPT_ASSETS_CSV;
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		return; // The plugin owns directory creation; don't create it from here.
	}
	// Bound growth: the scan resets this file; between scans we only enrich it.
	if ( file_exists( $path ) && filesize( $path ) > 2 * 1024 * 1024 ) {
		return;
	}
	$new = ! file_exists( $path ) || 0 === (int) filesize( $path );
	$fh  = @fopen( $path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
	/*
	 * NO BLOQUEANTE, y el lock no se quita del todo a propósito.
	 *
	 * Esto corre en peticiones públicas muestreadas, así que un LOCK_EX bloqueante pone a los
	 * workers a hacer cola en el mismo inodo mientras sirven visitas. Pero quitar el lock
	 * entero tiene un filo: con el fichero recién vacío dos peticiones a la vez escribirían la
	 * CABECERA las dos, y la segunda queda como una fila de datos falsa en medio del CSV.
	 *
	 * Con LOCK_NB el que lo tiene escribe y el que no, se va. Perder una muestra no cuesta
	 * nada: el escaneo reinicia este fichero y entre escaneos esto sólo lo enriquece.
	 */
	if ( $fh && flock( $fh, LOCK_EX | LOCK_NB ) ) {
		if ( $new ) {
			fputcsv( $fh, array( 'kind', 'handle', 'owner_type', 'owner_slug', 'src_rel', 'deps', 'source' ), ',', '"', '\\' );
		}
		fputcsv( $fh, array( $kind, $handle, $owner_type, $owner_slug, $src_rel, $deps, 'runtime' ), ',', '"', '\\' );
		fflush( $fh );
		flock( $fh, LOCK_UN );
	}
	if ( $fh ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}

/**
 * Normalize an asset src to a privacy-safe, wp-content-relative path (no host, no
 * query string). Standalone mirror of wpoptimizing_normalize_asset_src().
 *
 * @param string $src Raw src.
 * @return string Normalized path or ''.
 */
function apg_wpopt_normalize_asset_src( $src ) {
	$src = trim( (string) $src );
	if ( '' === $src ) {
		return '';
	}
	$src = preg_replace( '/[?#].*$/', '', $src );
	$src = preg_replace( '#^[a-z][a-z0-9+.-]*://#i', '', $src );
	$src = preg_replace( '#^//#', '', $src );
	if ( '' !== $src && '/' !== $src[0] && preg_match( '#^[^/]+\.[^/]+/#', $src ) ) {
		$src = substr( $src, (int) strpos( $src, '/' ) );
	}
	$pos = stripos( $src, 'wp-content/' );
	if ( false !== $pos ) {
		$src = substr( $src, $pos + strlen( 'wp-content/' ) );
	} else {
		$src = ltrim( $src, '/' );
	}
	return $src;
}

/**
 * Resolve an asset owner from its normalized src. Standalone mirror of
 * wpoptimizing_asset_owner_from_src().
 *
 * @param string $rel Normalized src.
 * @return array{type:string,slug:string}
 */
function apg_wpopt_asset_owner_from_src( $rel ) {
	$rel = ltrim( (string) $rel, '/' );
	if ( '' === $rel ) {
		return array(
			'type' => 'unknown',
			'slug' => '',
		);
	}
	if ( 0 === strpos( $rel, 'plugins/' ) || 0 === strpos( $rel, 'mu-plugins/' ) ) {
		$after = substr( $rel, (int) strpos( $rel, '/' ) + 1 );
		return array(
			'type' => 'plugin',
			'slug' => (string) strtok( $after, '/' ),
		);
	}
	if ( 0 === strpos( $rel, 'themes/' ) ) {
		return array(
			'type' => 'theme',
			'slug' => (string) strtok( substr( $rel, strlen( 'themes/' ) ), '/' ),
		);
	}
	if ( 0 === strpos( $rel, 'wp-includes/' ) || 0 === strpos( $rel, 'wp-admin/' ) ) {
		return array(
			'type' => 'core',
			'slug' => 'wordpress-core',
		);
	}
	return array(
		'type' => 'unknown',
		'slug' => '',
	);
}

/**
 * Authoritative set of WordPress core script/style handles, from WP's own default
 * registries (no hardcoded list). Built once per request; only ever runs on the
 * sampled shutdown capture, after the page is delivered. Mirrors the main plugin's
 * wpoptimizing_core_asset_handles().
 *
 * @return array<string,bool> handle => true
 */
function apg_wpopt_core_asset_handles() {
	static $set = null;
	if ( null !== $set ) {
		return $set;
	}
	$set = array();
	if ( ! defined( 'ABSPATH' ) || ! defined( 'WPINC' ) ) {
		return $set;
	}
	// wp-settings.php loads the script loader on every request; nothing to require.
	if ( ! class_exists( 'WP_Scripts' ) || ! class_exists( 'WP_Styles' ) || ! function_exists( 'wp_default_scripts' ) || ! function_exists( 'wp_default_styles' ) ) {
		return $set;
	}
	try {
		$s = new WP_Scripts();
		wp_default_scripts( $s );
		if ( function_exists( 'wp_default_packages' ) ) {
			wp_default_packages( $s );
		}
		foreach ( array_keys( $s->registered ) as $h ) {
			$set[ (string) $h ] = true;
		}
		$st = new WP_Styles();
		wp_default_styles( $st );
		foreach ( array_keys( $st->registered ) as $h ) {
			$set[ (string) $h ] = true;
		}
	} catch ( \Throwable $e ) {
		$set = array();
	}
	return $set;
}

/**
 * Index of ACTIVE plugin/theme slugs (cheap: no get_plugins()), for handle-prefix
 * attribution of src-less assets. An asset printed on the page belongs to an
 * active extension or core, so the active set suffices.
 *
 * @return array<string,array{0:string,1:string}> lc-slug => [type, slug]
 */
function apg_wpopt_active_slug_index() {
	static $idx = null;
	if ( null !== $idx ) {
		return $idx;
	}
	$idx = array();
	if ( function_exists( 'wp_get_active_and_valid_plugins' ) ) {
		foreach ( (array) wp_get_active_and_valid_plugins() as $file ) {
			$slug = basename( dirname( (string) $file ) );
			if ( '.' === $slug || '' === $slug ) {
				$slug = basename( (string) $file, '.php' );
			}
			if ( '' !== $slug ) {
				$idx[ strtolower( $slug ) ] = array( 'plugin', $slug );
			}
		}
	}
	if ( function_exists( 'wp_get_theme' ) ) {
		$t = wp_get_theme();
		if ( $t && $t->exists() ) {
			$ss = (string) $t->get_stylesheet();
			$tp = (string) $t->get_template();
			if ( '' !== $ss ) {
				$idx[ strtolower( $ss ) ] = array( 'theme', $ss );
			}
			if ( '' !== $tp ) {
				$idx[ strtolower( $tp ) ] = array( 'theme', $tp );
			}
		}
	}
	return $idx;
}

/**
 * Mirror of wpoptimizing_asset_owner_from_upload_dir() — keep both in step.
 *
 * Owner of an asset generated into uploads/ (Complianz's banner CSS, Avada's compiled styles,
 * Elementor's per-post CSS…), from the folder it lives in. Path attribution only knew plugins/,
 * themes/ and core, so these came out ownerless — and their handles rarely start with the slug
 * (`cmplz-banner-1-optin` is complianz-gdpr's), so the handle fallback missed them too.
 *
 * The first folder under uploads/ (skipping sites/N/ and year folders) is matched against the
 * installed slugs: equal, the slug starting with "<folder>-" (complianz → complianz-gdpr), or the
 * folder starting with "<slug>-" (elementor-pro…). A few well-known folders that name no slug go
 * through an alias map. Ambiguous matches stay unattributed: the owner decides what Smart Assets
 * may unload, so a wrong owner is worse than none.
 *
 * @param string $rel Normalized path from apg_wpopt_normalize_asset_src().
 * @param array  $idx Slug index: lowercase slug => array( type, slug ).
 * @return array{type:string,slug:string}|null
 */
function apg_wpopt_asset_owner_from_upload_dir( $rel, array $idx ) {
	$rel = ltrim( strtolower( (string) $rel ), '/' );
	if ( 0 !== strpos( $rel, 'uploads/' ) ) {
		return null;
	}
	$parts = explode( '/', substr( $rel, 8 ) );
	if ( isset( $parts[0], $parts[1] ) && 'sites' === $parts[0] && ctype_digit( $parts[1] ) ) {
		$parts = array_slice( $parts, 2 );
	}
	if ( count( $parts ) < 2 || '' === $parts[0] || 1 === preg_match( '/^\d{4}$/', $parts[0] ) ) {
		return null;
	}
	$dir     = $parts[0];
	$aliases = array(
		'fusion-styles'       => 'avada',
		'fusion-scripts'      => 'avada',
		'fusion-gfonts'       => 'avada',
		'fusion-icons'        => 'avada',
		'et-cache'            => 'divi',
		'oxygen'              => 'oxygen',
		'bb-plugin'           => 'bb-plugin',
		'wc-logs'             => 'woocommerce',
		'woocommerce_uploads' => 'woocommerce',
		'wc-imports'          => 'woocommerce',
	);
	if ( isset( $aliases[ $dir ] ) ) {
		$dir = $aliases[ $dir ];
		if ( isset( $idx[ $dir ] ) ) {
			return array(
				'type' => $idx[ $dir ][0],
				'slug' => $idx[ $dir ][1],
			);
		}
	}
	if ( isset( $idx[ $dir ] ) ) {
		return array(
			'type' => $idx[ $dir ][0],
			'slug' => $idx[ $dir ][1],
		);
	}
	$found = array();
	foreach ( $idx as $slug => $info ) {
		$slug = (string) $slug;
		if ( strlen( $dir ) >= 4 && ( 0 === strpos( $slug, $dir . '-' ) || 0 === strpos( $dir, $slug . '-' ) ) ) {
			$found[ $slug ] = $info;
		}
	}
	if ( 1 !== count( $found ) ) {
		return null;
	}
	$info = reset( $found );
	return array(
		'type' => $info[0],
		'slug' => $info[1],
	);
}

/**
 * Attribute a src-less handle by matching it against an active slug at a '-'
 * boundary (longest wins). Returns null when nothing matches. Mirrors the main
 * plugin's wpoptimizing_asset_owner_by_handle().
 *
 * @param string $handle Asset handle.
 * @return array{type:string,slug:string}|null
 */
function apg_wpopt_asset_owner_by_handle( $handle ) {
	$h = strtolower( trim( (string) $handle ) );
	if ( '' === $h ) {
		return null;
	}
	$idx  = apg_wpopt_active_slug_index();
	$best = '';
	foreach ( $idx as $slug => $info ) {
		if ( ( $h === $slug || 0 === strpos( $h, $slug . '-' ) ) && strlen( $slug ) > strlen( $best ) ) {
			$best = $slug;
		}
	}
	if ( '' === $best ) {
		return null;
	}
	return array(
		'type' => $idx[ $best ][0],
		'slug' => $idx[ $best ][1],
	);
}

/**
 * Capture reads of non-autoloaded options from the SQL stream. WordPress reads
 * such options with: SELECT option_value FROM {prefix}options WHERE option_name = 'X'.
 * De-duped per request (each option counts once even if read many times).
 *
 * @param string $query SQL.
 * @return string Unchanged.
 */
function apg_wpopt_capture_option_read( $query ) {
	// Fast pre-flight.
	if ( 0 !== stripos( ltrim( $query ), 'SELECT option_value' ) ) {
		return $query;
	}
	if ( false === stripos( $query, 'WHERE option_name' ) ) {
		return $query;
	}
	if ( preg_match( '/option_name`?\s*=\s*\'([^\']+)\'/i', $query, $m ) ) {
		$name = $m[1];
		if ( '' !== $name
			&& 0 !== strncmp( $name, '_transient_', 11 )
			&& 0 !== strncmp( $name, '_site_transient_', 16 )
			&& 0 !== strncmp( $name, 'wpopt', 5 ) ) {
			$GLOBALS['apg_wpopt_autoload_reads'][ $name ] = true;
		}
	}
	return $query;
}

/**
 * Persist the per-request read set into the running tally.
 *
 * @return void
 */
function apg_wpopt_flush_option_reads() {
	if ( empty( $GLOBALS['apg_wpopt_autoload_reads'] ) || ! is_array( $GLOBALS['apg_wpopt_autoload_reads'] ) ) {
		// Still count the request so ratios reflect total traffic.
		$GLOBALS['apg_wpopt_autoload_reads'] = array();
	}
	$tally = get_option( 'wpoptimizing_autoload_reads', array() );
	if ( ! is_array( $tally ) ) {
		$tally = array();
	}
	$tally['requests'] = (int) ( $tally['requests'] ?? 0 ) + 1;
	$tally['started']  = (int) ( $tally['started'] ?? time() );
	$reads             = is_array( $tally['reads'] ?? null ) ? $tally['reads'] : array();
	foreach ( array_keys( $GLOBALS['apg_wpopt_autoload_reads'] ) as $name ) {
		$reads[ $name ] = (int) ( $reads[ $name ] ?? 0 ) + 1;
	}
	// Cap stored names to keep the option from growing unbounded.
	if ( count( $reads ) > 3000 ) {
		arsort( $reads );
		$reads = array_slice( $reads, 0, 3000, true );
	}
	$tally['reads'] = $reads;
	update_option( 'wpoptimizing_autoload_reads', $tally, false );
}

// ── Autoloaded-usage capture ────────────────────────────────────────────────
// The block above measures NON-autoloaded reads (from the SQL stream). This block
// measures the opposite, and harder, question: of the big options that ARE
// autoloaded (served from the alloptions cache, so invisible to the SQL stream),
// which ones is the request actually reading? An autoloaded option that loads on
// every request but is never read is dead weight that WPOptimizing can de-autoload
// surgically — by measured use, not by name heuristics. Bounded to the top-N
// largest options so the cost is N add_filter() registrations on a sampled request.

/**
 * Instrument the largest autoloaded options to capture which are actually read.
 *
 * Runs inside the already-sampled measurement window (see plugins_loaded above).
 * Reads alloptions once (already in cache), keeps the top-N by serialized size
 * above a byte floor, and hooks each one's `option_{$name}` filter to flip a flag
 * when WordPress hands its value to a caller.
 *
 * @return void
 */
function apg_wpopt_setup_autoload_usage_capture() {
	if ( ! function_exists( 'wp_load_alloptions' ) ) {
		return;
	}
	$all = wp_load_alloptions();
	if ( ! is_array( $all ) || empty( $all ) ) {
		return;
	}
	// Only worth de-autoloading options with real weight; tiny scalars are noise.
	$min_bytes = (int) apply_filters( 'wpoptimizing_autoload_usage_min_bytes', 256 );
	$sizes     = array();
	foreach ( $all as $name => $value ) {
		$len = is_string( $value ) ? strlen( $value ) : strlen( (string) maybe_serialize( $value ) );
		if ( $len >= $min_bytes ) {
			$sizes[ $name ] = $len;
		}
	}
	if ( empty( $sizes ) ) {
		return;
	}
	arsort( $sizes );
	$top = (int) apply_filters( 'wpoptimizing_autoload_usage_top', 150 );
	if ( $top > 0 && count( $sizes ) > $top ) {
		$sizes = array_slice( $sizes, 0, $top, true );
	}
	$GLOBALS['apg_wpopt_aload_measured'] = $sizes;          // name => size (bytes).
	$GLOBALS['apg_wpopt_aload_read']     = array();         // name => true when read.
	foreach ( array_keys( $sizes ) as $name ) {
		add_filter( 'option_' . $name, 'apg_wpopt_capture_autoload_read', 99, 2 );
	}
	add_action( 'shutdown', 'apg_wpopt_flush_autoload_usage', 997 );
}

/**
 * Flag an autoloaded option as read for this request. Hooked on `option_{$name}`.
 *
 * @param mixed  $value  The option value (passed through untouched).
 * @param string $option The option name.
 * @return mixed The unchanged value.
 */
function apg_wpopt_capture_autoload_read( $value, $option ) {
	$GLOBALS['apg_wpopt_aload_read'][ (string) $option ] = true;
	return $value;
}

/**
 * Persist the per-request autoloaded-usage measurement into a running tally.
 *
 * Stored in option `wpoptimizing_autoload_aloaded`:
 *   requests int, started int, reads{name:count}, sizes{name:bytes}.
 * Every measured option gets a read entry (incremented when read, created at 0
 * otherwise) so the analyzer can compute a never-read ratio across requests.
 *
 * @return void
 */
function apg_wpopt_flush_autoload_usage() {
	if ( empty( $GLOBALS['apg_wpopt_aload_measured'] ) || ! is_array( $GLOBALS['apg_wpopt_aload_measured'] ) ) {
		return;
	}
	$measured = $GLOBALS['apg_wpopt_aload_measured'];
	$read     = is_array( $GLOBALS['apg_wpopt_aload_read'] ?? null ) ? $GLOBALS['apg_wpopt_aload_read'] : array();

	$tally = get_option( 'wpoptimizing_autoload_aloaded', array() );
	if ( ! is_array( $tally ) ) {
		$tally = array();
	}
	$tally['requests'] = (int) ( $tally['requests'] ?? 0 ) + 1;
	$tally['started']  = (int) ( $tally['started'] ?? time() );
	$reads             = is_array( $tally['reads'] ?? null ) ? $tally['reads'] : array();
	$sizes             = is_array( $tally['sizes'] ?? null ) ? $tally['sizes'] : array();
	foreach ( $measured as $name => $bytes ) {
		$name           = (string) $name;
		$sizes[ $name ] = (int) $bytes; // Latest observed size.
		$reads[ $name ] = (int) ( $reads[ $name ] ?? 0 ) + ( isset( $read[ $name ] ) ? 1 : 0 );
	}
	// Cap stored names to keep the option bounded (drop the smallest first).
	if ( count( $sizes ) > 1000 ) {
		arsort( $sizes );
		$sizes = array_slice( $sizes, 0, 1000, true );
		$reads = array_intersect_key( $reads, $sizes );
	}
	$tally['reads'] = $reads;
	$tally['sizes'] = $sizes;
	update_option( 'wpoptimizing_autoload_aloaded', $tally, false );
}

// ── Signature ─────────────────────────────────────────────────────────────────

/**
 * Current ecosystem signature: MD5 of sorted active plugins + active theme.
 *
 * @return string
 */
function apg_wpopt_rtmap_sig() {
	/*
	 * The STORED values, not get_option(): plugins that unload other plugins per URL (Freesoul,
	 * Perfmatters, Asset CleanUp Pro) filter option_active_plugins, so on those pages the
	 * signature differed from the stored one and the capture hooks — debug_backtrace() on every
	 * option write — were registered on visitor requests. wp_load_alloptions() is the same
	 * in-memory array get_option() reads, before any filter; same values, same signature, so an
	 * unfiltered site sees no change.
	 */
	$all     = function_exists( 'wp_load_alloptions' ) ? (array) wp_load_alloptions() : array();
	$plugins = isset( $all['active_plugins'] ) ? (array) maybe_unserialize( $all['active_plugins'] ) : (array) get_option( 'active_plugins', array() );
	$theme   = isset( $all['stylesheet'] ) ? (string) $all['stylesheet'] : (string) get_option( 'stylesheet', '' );
	$plugins = array_values( array_map( 'strval', $plugins ) );
	sort( $plugins );
	$plugins[] = $theme;
	return md5( implode( ',', $plugins ) );
}

// ── One-time repair of rows a database replacement took ──────────────────────

/**
 * The plugins that replace $wpdb, with the prefixes of the keys that really are theirs.
 *
 * @return array<string,string[]>
 */
function apg_wpopt_rtmap_wpdb_replacers() {
	return array(
		'query-monitor'               => array( 'qm_', 'qm-', 'query_monitor', 'query-monitor' ),
		'w3-total-cache'              => array( 'w3tc' ),
		'ludicrousdb'                 => array( 'ludicrousdb' ),
		'hyperdb'                     => array( 'hyperdb' ),
		'sqlite-database-integration' => array( 'sqlite' ),
	);
}

/**
 * Should this map row go? A database write (option or meta) given to a plugin that replaces
 * $wpdb, on a key without that plugin's own prefix: what the SQL-level capture recorded before
 * it skipped those frames. Pure.
 *
 * @param array $row Map row: slug, tipo, clave, …
 * @return bool
 */
function apg_wpopt_rtmap_is_wpdb_artifact( array $row ) {
	$slug   = strtolower( trim( (string) ( $row[0] ?? '' ) ) );
	$tipo   = strtolower( trim( (string) ( $row[1] ?? '' ) ) );
	$clave  = strtolower( trim( (string) ( $row[2] ?? '' ) ) );
	$owners = apg_wpopt_rtmap_wpdb_replacers();
	if ( ! isset( $owners[ $slug ] ) || ! in_array( $tipo, array( 'option', 'post_meta', 'user_meta', 'term_meta', 'order_meta' ), true ) ) {
		return false;
	}
	foreach ( $owners[ $slug ] as $prefix ) {
		if ( 0 === strpos( ltrim( $clave, '_' ), $prefix ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Remove those rows once from this site's map. Their keys leave the dedup index too, so the
 * next write of each one is captured again, this time with its real owner. In the admin only,
 * and only marked done once the map was read: an encrypted map this request cannot open is
 * left for the next one.
 *
 * @return void
 */
function apg_wpopt_rtmap_repair_wpdb_artifacts() {
	if ( '1' === (string) get_option( 'wpopt_rtmap_repair_wpdb', '' ) ) {
		return;
	}
	$src = APG_WPOPT_RTMAP_CSV;

	// EN CLARO, EN FLUJO: se filtra fila a fila contra un temporal y se publica con rename, sin
	// el mapa en memoria en ningún momento. Lo de abajo —leerlo entero— sólo queda para el mapa
	// cifrado, que hay que descifrar de una pieza.
	if ( apg_wpopt_rtmap_en_claro( $src ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		$in = file_exists( $src ) ? @fopen( $src, 'r' ) : false;
		if ( ! $in ) {
			update_option( 'wpopt_rtmap_repair_wpdb', '1', false );
			return;
		}
		$tmp = $src . '.tmp.' . uniqid( '', true );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		$out = @fopen( $tmp, 'w' );
		if ( ! $out ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $in );
			return;
		}
		$gone    = 0;
		$claves  = array();
		$primera = true;
		while ( false !== ( $row = fgetcsv( $in, 0, "\t", '"', '\\' ) ) ) {
			if ( $primera ) {
				$primera = false;
				fputcsv( $out, $row, "\t", '"', '\\' );
				continue;
			}
			if ( ! is_array( $row ) || count( $row ) < 3 ) {
				continue;
			}
			if ( apg_wpopt_rtmap_is_wpdb_artifact( $row ) ) {
				++$gone;
				continue;
			}
			fputcsv( $out, $row, "\t", '"', '\\' );
			if ( '' !== trim( (string) $row[0] ) ) {
				$claves[ apg_wpopt_rtmap_key_hash( apg_wpopt_rtmap_clave_de( $row[0], $row[1], $row[2] ) ) ] = true;
			}
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $gone > 0 && @rename( $tmp, $src ) ) {
			clearstatcache( true, $src );
			apg_wpopt_rtmap_index_write( $claves );
		} elseif ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		update_option( 'wpopt_rtmap_repair_wpdb', '1', false );
		return;
	}

	$content = apg_wpopt_rtmap_load_content();
	if ( false === $content ) {
		return;
	}
	// Fila a fila y no con preg_split(): si éste no puede con el sujeto devuelve false, y la
	// reparación se daba por hecha sin haber mirado una sola fila.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem path.
	$in = fopen( 'php://temp', 'r+' );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem path.
	$out = fopen( 'php://temp', 'r+' );
	if ( ! $in || ! $out ) {
		return;
	}
	fwrite( $in, (string) $content );
	rewind( $in );
	$gone    = 0;
	$primera = true;
	while ( false !== ( $row = fgetcsv( $in, 0, "\t", '"', '\\' ) ) ) {
		if ( ! is_array( $row ) || array( null ) === $row ) {
			continue;
		}
		if ( ! $primera && apg_wpopt_rtmap_is_wpdb_artifact( $row ) ) {
			++$gone;
			continue;
		}
		$primera = false;
		fputcsv( $out, $row, "\t", '"', '\\' );
	}
	rewind( $out );
	$clean = (string) stream_get_contents( $out );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $in );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $out );
	if ( $gone > 0 ) {
		apg_wpopt_rtmap_persist( APG_WPOPT_RTMAP_CSV, $clean );
		apg_wpopt_rtmap_index_write( apg_wpopt_rtmap_parse_keys( $clean ) );
	}
	update_option( 'wpopt_rtmap_repair_wpdb', '1', false );
}
add_action(
	'admin_init',
	static function () {
		if ( current_user_can( 'manage_options' ) ) {
			apg_wpopt_rtmap_repair_wpdb_artifacts();
		}
	},
	1
);

// ── Hook registration ─────────────────────────────────────────────────────────

/**
 * Register all capture hooks. Called only when the signature has changed.
 *
 * @return void
 */
function apg_wpopt_rtmap_register_hooks() {
	add_action( 'registered_post_type', 'apg_wpopt_rtmap_capture_post_type', 10, 1 );
	add_action( 'registered_taxonomy', 'apg_wpopt_rtmap_capture_taxonomy', 10, 1 );
	add_action( 'registered_setting', 'apg_wpopt_rtmap_capture_setting', 10, 2 );
	add_action( 'added_option', 'apg_wpopt_rtmap_capture_new_option', 10, 1 );
	add_filter( 'schedule_event', 'apg_wpopt_rtmap_capture_cron_event', 10, 1 );
	add_action( 'init', 'apg_wpopt_rtmap_capture_init', 9999 );
	add_action( 'rest_api_init', 'apg_wpopt_rtmap_capture_rest', 9999 );
	add_action( 'widgets_init', 'apg_wpopt_rtmap_capture_widgets', 9999 );
	add_action( 'wp_loaded', 'apg_wpopt_rtmap_capture_roles', 9999 );
	add_action( 'wp_loaded', 'apg_wpopt_rtmap_capture_registered_meta', 9999 );
}

// ── Capture callbacks ─────────────────────────────────────────────────────────

/**
 * True when running in a context where plugin/theme meta writes are worth capturing.
 *
 * Covers:
 *  - WordPress admin (includes admin-ajax.php).
 *  - REST API requests.
 *  - Any frontend POST request: WooCommerce checkout, My Account address/password
 *    updates, contact forms, etc.  POST requests are infrequent, so the backtrace
 *    overhead is negligible.
 *
 * REST_REQUEST is checked at call time (not at plugins_loaded) so it is reliable.
 *
 * @return bool
 */
function apg_wpopt_rtmap_is_capture_context() {
	if ( is_admin() ) {
		return true;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}
	// Frontend form submissions (checkout, My Account, plugin forms…).
	return isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( (string) $_SERVER['REQUEST_METHOD'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

/**
 * Shared pre-flight for meta write capture: context guard + key sanity + skip list.
 * Returns the normalised key or '' to signal "skip this entry".
 *
 * @param string $meta_key Raw meta key from the hook.
 * @return string
 */
function apg_wpopt_rtmap_meta_key_guard( $meta_key ) {
	if ( ! apg_wpopt_rtmap_is_capture_context() ) {
		return '';
	}
	$key = trim( (string) $meta_key );
	if (
		'' === $key ||
		str_starts_with( $key, '_wp_' ) ||
		str_starts_with( $key, 'wpopt_' ) ||
		str_starts_with( $key, 'wpoptimizing_' )
	) {
		return '';
	}
	return $key;
}

/**
 * Capture post meta written by plugins/themes (added + updated).
 * Registered with $accepted_args = 3 to avoid receiving potentially large $meta_value.
 * Backtrace runs at most once per unique key, ever — CSV dedup guards all subsequent calls.
 *
 * @param int    $meta_id   Meta row ID (unused).
 * @param int    $object_id Post ID (unused).
 * @param string $meta_key  Meta key.
 * @return void
 */
function apg_wpopt_rtmap_capture_post_meta_write( $meta_id, $object_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	$key = apg_wpopt_rtmap_meta_key_guard( $meta_key );
	if ( '' === $key ) {
		return;
	}
	list( $slug, $confianza ) = apg_wpopt_rtmap_resolve_meta_slug( $key );
	if ( '' === $slug ) {
		return; // Cannot attribute → skip write so the next save can retry.
	}
	apg_wpopt_rtmap_write( $slug, 'post_meta', $key, '', $confianza );
}

/**
 * Capture user meta written by plugins/themes (added + updated).
 *
 * @param int    $meta_id   Meta row ID (unused).
 * @param int    $object_id User ID (unused).
 * @param string $meta_key  Meta key.
 * @return void
 */
function apg_wpopt_rtmap_capture_user_meta_write( $meta_id, $object_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	$key = apg_wpopt_rtmap_meta_key_guard( $meta_key );
	if ( '' === $key ) {
		return;
	}
	list( $slug, $confianza ) = apg_wpopt_rtmap_resolve_meta_slug( $key );
	if ( '' === $slug ) {
		return;
	}
	apg_wpopt_rtmap_write( $slug, 'user_meta', $key, '', $confianza );
}

/**
 * Capture term meta written by plugins/themes (added + updated).
 *
 * @param int    $meta_id   Meta row ID (unused).
 * @param int    $object_id Term ID (unused).
 * @param string $meta_key  Meta key.
 * @return void
 */
function apg_wpopt_rtmap_capture_term_meta_write( $meta_id, $object_id, $meta_key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	$key = apg_wpopt_rtmap_meta_key_guard( $meta_key );
	if ( '' === $key ) {
		return;
	}
	list( $slug, $confianza ) = apg_wpopt_rtmap_resolve_meta_slug( $key );
	if ( '' === $slug ) {
		return;
	}
	apg_wpopt_rtmap_write( $slug, 'term_meta', $key, '', $confianza );
}

/**
 * Capture option writes (added_option + updated_option) in admin context.
 * Skips transients, WP-internal and our own keys.
 * Uses backtrace for certain attribution; falls back to option-name prefix heuristic.
 * Does NOT write when no slug can be resolved — allows retry on next save.
 *
 * @param string $option Option key.
 * @return void
 */
function apg_wpopt_rtmap_capture_option_write( $option ) {
	if ( ! is_admin() ) {
		return;
	}
	$option = trim( (string) $option );
	if (
		'' === $option ||
		str_starts_with( $option, '_transient_' ) ||
		str_starts_with( $option, '_site_transient_' ) ||
		str_starts_with( $option, 'wpopt_' ) ||
		str_starts_with( $option, 'wpoptimizing_' ) ||
		str_starts_with( $option, '_wp_' )
	) {
		return;
	}
	$slug      = apg_wpopt_rtmap_slug_from_backtrace( 25 );
	$confianza = 'certain';
	if ( null === $slug ) {
		return; // A mu-plugin's or our own write: nothing to record.
	}

	if ( '' === $slug && function_exists( 'get_registered_settings' ) ) {
		// Try the sanitize callback registered by the plugin for certain attribution.
		$registered = get_registered_settings();
		$cb         = $registered[ $option ]['sanitize_callback'] ?? null;
		if ( is_callable( $cb ) ) {
			$slug = apg_wpopt_rtmap_slug_from_callback( $cb );
			if ( '' !== $slug ) {
				$confianza = 'certain';
			}
		}
	}

	if ( '' === $slug ) {
		// Fallback: prefix heuristic on the option name.
		$slug = apg_wpopt_rtmap_attr_by_prefix( $option );
		if ( '' === $slug ) {
			$parts = explode( '_', $option, 2 );
			if ( isset( $parts[0] ) && strlen( $parts[0] ) >= 2 ) {
				$slug = apg_wpopt_rtmap_attr_by_prefix( $parts[0] );
			}
		}
		$confianza = '' !== $slug ? 'heuristic' : '';
	}

	if ( '' === $slug ) {
		return; // Cannot attribute — skip so the next save can retry.
	}

	apg_wpopt_rtmap_write( $slug, 'option', $option, '', $confianza );
}

/**
 * Scan all options registered via register_setting() at admin_init.
 * Uses the sanitize_callback to resolve slug via reflection — works even when
 * options.php processes the form independently of the registering plugin's stack.
 * Dedup-guarded so subsequent admin requests are nearly free.
 *
 * @return void
 */
function apg_wpopt_rtmap_scan_registered_settings() {
	if ( ! function_exists( 'get_registered_settings' ) ) {
		return;
	}
	// Una comparación de opción autocargada: en admin-ajax y en el heartbeat el coste acaba
	// aquí. Lo que hay debajo descifra y parsea el mapa entero y no puede correr por petición.
	$sig = apg_wpopt_rtmap_sig();
	if ( get_option( APG_WPOPT_RTMAP_SETTINGS_SIG_OPT ) === $sig ) {
		return;
	}
	$registered = get_registered_settings();
	if ( empty( $registered ) ) {
		return;
	}
	apg_wpopt_rtmap_ensure_loaded();
	global $apg_wpopt_rtmap_written;
	foreach ( $registered as $option => $args ) {
		$option = strtolower( trim( (string) $option ) );
		if (
			'' === $option ||
			str_starts_with( $option, '_transient_' ) ||
			str_starts_with( $option, '_site_transient_' ) ||
			str_starts_with( $option, 'wpopt_' ) ||
			str_starts_with( $option, 'wpoptimizing_' ) ||
			str_starts_with( $option, '_wp_' )
		) {
			continue;
		}
		if ( isset( $apg_wpopt_rtmap_written[ apg_wpopt_rtmap_key_hash( 'option:' . $option ) ] ) ) {
			continue; // Already captured.
		}
		$slug      = '';
		$confianza = 'heuristic';
		// Certain attribution via sanitize callback (set by the plugin itself).
		$cb = $args['sanitize_callback'] ?? null;
		if ( is_callable( $cb ) ) {
			$slug = apg_wpopt_rtmap_slug_from_callback( $cb );
			if ( '' !== $slug ) {
				$confianza = 'certain';
			}
		}
		// Fallback: prefix heuristic on the option name.
		if ( '' === $slug ) {
			$slug = apg_wpopt_rtmap_attr_by_prefix( $option );
			if ( '' === $slug ) {
				$parts = explode( '_', $option, 2 );
				if ( isset( $parts[0] ) && strlen( $parts[0] ) >= 2 ) {
					$slug = apg_wpopt_rtmap_attr_by_prefix( $parts[0] );
				}
			}
		}
		if ( '' === $slug ) {
			continue;
		}
		apg_wpopt_rtmap_write( $slug, 'option', $option, '', $confianza );
	}

	// Terminó: este ecosistema queda escaneado. Se sella aunque no se haya añadido nada —
	// «no había nada nuevo» es un resultado, no un motivo para repetirlo en cada petición.
	update_option( APG_WPOPT_RTMAP_SETTINGS_SIG_OPT, $sig, true );
}

/**
 * Intercept SQL write queries (INSERT / UPDATE / REPLACE) to capture option
 * and meta writes at the database level.
 *
 * Catches:
 *  - Options saved through options.php (settings API) where the plugin frame
 *    is absent from the backtrace at action-hook time.
 *  - WooCommerce HPOS writes to wc_orders_meta.
 *  - Direct $wpdb->query() / $wpdb->update() calls that bypass WP hooks.
 *
 * A static recursion guard prevents re-entering during helper DB reads.
 * Must return $query unchanged (it is a filter).
 *
 * @param string $query Raw SQL query.
 * @return string Unchanged query.
 */
function apg_wpopt_rtmap_capture_write_query( $query ) {
	// Recursion guard: our helpers may trigger additional DB reads.
	static $busy = false;
	if ( $busy ) {
		return $query;
	}

	// Fast pre-flight: only INSERT / UPDATE / REPLACE touch persistent data.
	$q7 = strtoupper( substr( ltrim( $query ), 0, 7 ) );
	if ( 'INSERT ' !== $q7 && 'UPDATE ' !== $q7 && 'REPLACE' !== $q7 ) {
		return $query;
	}

	if ( ! apg_wpopt_rtmap_is_capture_context() ) {
		return $query;
	}

	global $wpdb;

	// Table → tipo map, built once per request.
	static $tables = null;
	if ( null === $tables ) {
		$p      = $wpdb->prefix;
		$tables = array(
			$p . 'options'   => 'option',
			$p . 'postmeta'  => 'post_meta',
			$p . 'usermeta'  => 'user_meta',
			$p . 'termmeta'  => 'term_meta',
			'wc_orders_meta' => 'order_meta',
		);
	}

	$tipo  = '';
	$clave = '';

	foreach ( $tables as $table => $t ) {
		if ( false === stripos( $query, $table ) ) {
			continue;
		}
		$tipo = $t;

		if ( 'option' === $t ) {
			// UPDATE … WHERE `option_name` = 'key'
			if ( preg_match( '/`?option_name`?\s*=\s*\'([^\']+)\'/i', $query, $m ) ) {
				$clave = $m[1];
			} elseif (
				// INSERT … (`option_name`, …) VALUES ('key', …) — option_name is first column.
				false !== stripos( $query, 'option_name' ) &&
				preg_match( '/VALUES\s*\(\s*\'([^\']+)\'/i', $query, $m )
			) {
				$clave = $m[1];
			}
		} else {
			// UPDATE … WHERE `meta_key` = 'key'
			if ( preg_match( '/`?meta_key`?\s*=\s*\'([^\']+)\'/i', $query, $m ) ) {
				$clave = $m[1];
			}
			// INSERT meta: rely on added_post_meta / added_user_meta / added_term_meta hooks.
		}
		break;
	}

	if ( '' === $tipo || '' === $clave ) {
		return $query;
	}

	// Noise filter.
	if (
		str_starts_with( $clave, '_transient_' ) ||
		str_starts_with( $clave, '_site_transient_' ) ||
		str_starts_with( $clave, 'wpopt_' ) ||
		str_starts_with( $clave, 'wpoptimizing_' ) ||
		str_starts_with( $clave, '_wp_' )
	) {
		return $query;
	}

	// Ya no se puede deduplicar antes del backtrace: la identidad lleva el dueño dentro y el
	// dueño sale precisamente de ahí. La comprobación de verdad la hace rtmap_write().
	$busy = true;

	// Attribution: backtrace first, then prefix heuristic.
	$slug      = apg_wpopt_rtmap_slug_from_backtrace( 30 );
	$confianza = 'certain';
	if ( null === $slug ) {
		$busy = false;
		return $query; // A mu-plugin's or our own write: nothing to record.
	}

	if ( '' === $slug ) {
		$bare = ltrim( $clave, '_' );
		$slug = apg_wpopt_rtmap_attr_by_prefix( $bare );
		if ( '' === $slug ) {
			$parts = explode( '_', $bare, 2 );
			if ( isset( $parts[0] ) && strlen( $parts[0] ) >= 2 ) {
				$slug = apg_wpopt_rtmap_attr_by_prefix( $parts[0] );
			}
		}
		$confianza = '' !== $slug ? 'heuristic' : '';
	}

	if ( '' !== $slug ) {
		apg_wpopt_rtmap_write( $slug, $tipo, $clave, '', $confianza );
	}

	$busy = false;
	return $query;
}

/**
 * @param string $post_type Post type slug.
 * @return void
 */
function apg_wpopt_rtmap_capture_post_type( $post_type ) {
	apg_wpopt_rtmap_write( apg_wpopt_rtmap_slug_from_backtrace(), 'post_type', $post_type );
}

/**
 * @param string $taxonomy Taxonomy slug.
 * @return void
 */
function apg_wpopt_rtmap_capture_taxonomy( $taxonomy ) {
	apg_wpopt_rtmap_write( apg_wpopt_rtmap_slug_from_backtrace(), 'taxonomy', $taxonomy );
}

/**
 * @param string $option_group Option group.
 * @param string $option_name  Option key.
 * @return void
 */
function apg_wpopt_rtmap_capture_setting( $option_group, $option_name ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	apg_wpopt_rtmap_write( apg_wpopt_rtmap_slug_from_backtrace(), 'option', $option_name );
}

/**
 * Capture options created for the first time (add_option / update_option on new key).
 * Skip transients and internal options to avoid noise.
 *
 * @param string $option Option key.
 * @return void
 */
function apg_wpopt_rtmap_capture_new_option( $option ) {
	// Skip transients, WP internal keys and our own options.
	if (
		str_starts_with( $option, '_transient_' ) ||
		str_starts_with( $option, '_site_transient_' ) ||
		str_starts_with( $option, 'wpopt_' ) ||
		str_starts_with( $option, 'wpoptimizing_' ) ||
		str_starts_with( $option, '_wp_' )
	) {
		return;
	}
	apg_wpopt_rtmap_write( apg_wpopt_rtmap_slug_from_backtrace(), 'option', $option );
}

/**
 * Capture shortcodes registered during init.
 *
 * @return void
 */
function apg_wpopt_rtmap_capture_init() {
	global $shortcode_tags;
	if ( ! is_array( $shortcode_tags ) ) {
		return;
	}
	foreach ( $shortcode_tags as $tag => $cb ) {
		apg_wpopt_rtmap_write(
			apg_wpopt_rtmap_slug_from_callback( $cb ),
			'shortcode',
			$tag
		);
	}
}

/**
 * Capture REST namespaces.
 *
 * @return void
 */
function apg_wpopt_rtmap_capture_rest() {
	$server = rest_get_server();
	foreach ( $server->get_namespaces() as $ns ) {
		apg_wpopt_rtmap_write(
			apg_wpopt_rtmap_attr_by_prefix( $ns ),
			'rest_namespace',
			$ns,
			'',
			'heuristic'
		);
	}
}

/**
 * Capture registered widgets.
 *
 * @return void
 */
function apg_wpopt_rtmap_capture_widgets() {
	global $wp_widget_factory;
	if ( empty( $wp_widget_factory->widgets ) ) {
		return;
	}
	foreach ( array_keys( $wp_widget_factory->widgets ) as $class ) {
		apg_wpopt_rtmap_write(
			apg_wpopt_rtmap_slug_from_file( apg_wpopt_rtmap_file_from_class( $class ) ),
			'widget',
			$class
		);
	}
}

/**
 * Capture non-core roles.
 *
 * @return void
 */
function apg_wpopt_rtmap_capture_roles() {
	static $core = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
	foreach ( array_keys( wp_roles()->roles ) as $role ) {
		if ( in_array( $role, $core, true ) ) {
			continue;
		}
		apg_wpopt_rtmap_write( apg_wpopt_rtmap_attr_by_prefix( $role ), 'role', $role, '', 'heuristic' );
	}
}

/**
 * Capture a newly scheduled cron event via the 'schedule_event' filter (WP 5.1+).
 *
 * This is a FILTER (fired by wp_schedule_event() and wp_schedule_single_event()),
 * not an action, so it MUST return the event unchanged — returning falsy would
 * abort the scheduling. Skips WP core/internal hooks. (The earlier code hooked a
 * non-existent 'scheduled_event' action, so runtime cron attribution never ran.)
 *
 * @param object|false $event Scheduled event object with ->hook, ->schedule, ->args.
 * @return object|false The event, unchanged.
 */
function apg_wpopt_rtmap_capture_cron_event( $event ) {
	if ( ! is_object( $event ) ) {
		return $event;
	}
	$hook = strtolower( trim( (string) ( $event->hook ?? '' ) ) );
	if ( '' === $hook ) {
		return $event;
	}
	static $core_hooks = array(
		'wp_scheduled_delete',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_version_check',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'recovery_mode_clean_expired_keys',
		'wp_privacy_delete_old_export_files',
		'wp_site_health_scheduled_check',
	);
	if ( in_array( $hook, $core_hooks, true ) ) {
		return $event;
	}
	apg_wpopt_rtmap_write( apg_wpopt_rtmap_slug_from_backtrace( 20 ), 'cron', $hook );
	return $event;
}

/**
 * Capture all meta keys registered via register_meta() at late wp_loaded.
 * Uses $wp_meta_keys global populated by register_meta().
 *
 * @return void
 */
function apg_wpopt_rtmap_capture_registered_meta() {
	global $wp_meta_keys;
	if ( empty( $wp_meta_keys ) || ! is_array( $wp_meta_keys ) ) {
		return;
	}
	// $wp_meta_keys = [ object_type ][ object_subtype ][ meta_key ] => [ args ]
	foreach ( $wp_meta_keys as $object_type => $subtypes ) {
		if ( ! is_array( $subtypes ) ) {
			continue;
		}
		$bucket = 'post' === $object_type ? 'post_meta' : ( 'user' === $object_type ? 'user_meta' : ( 'term' === $object_type ? 'term_meta' : 'meta_key' ) );
		foreach ( $subtypes as $subtype => $keys ) {
			if ( ! is_array( $keys ) ) {
				continue;
			}
			foreach ( array_keys( $keys ) as $meta_key ) {
				$meta_key = trim( (string) $meta_key );
				if ( '' === $meta_key ) {
					continue;
				}
				$args = $keys[ $meta_key ] ?? array();
				// Try to resolve slug from registered callbacks (certain if found).
				$slug        = '';
				$confianza   = 'heuristic';
				$sanitize_cb = $args['sanitize_callback'] ?? null;
				if ( is_callable( $sanitize_cb ) ) {
					$slug = apg_wpopt_rtmap_slug_from_callback( $sanitize_cb );
				}
				if ( '' === $slug ) {
					$auth_cb = $args['auth_callback'] ?? null;
					if ( is_callable( $auth_cb ) ) {
						$slug = apg_wpopt_rtmap_slug_from_callback( $auth_cb );
					}
				}
				if ( '' !== $slug ) {
					$confianza = 'certain';
				}
				apg_wpopt_rtmap_write( $slug, $bucket, $meta_key, $object_type . ':' . $subtype, $confianza );
			}
		}
	}
}

// ── Shutdown: persist signature ───────────────────────────────────────────────

/**
 * If new entries were written this request, store the current signature so
 * next requests skip all capture hooks.
 *
 * @return void
 */
function apg_wpopt_rtmap_on_shutdown() {
	global $apg_wpopt_rtmap_wrote;
	if ( ! empty( $apg_wpopt_rtmap_wrote ) || ! empty( $GLOBALS['apg_wpopt_rtmap_observed'] ) ) {
		update_option( APG_WPOPT_RTMAP_SIG_OPT, apg_wpopt_rtmap_sig(), true ); // autoload.
	}
}

// ── CSV write ─────────────────────────────────────────────────────────────────

/** @global array|null $apg_wpopt_rtmap_written In-memory dedup: 'tipo:clave' → true. */
global $apg_wpopt_rtmap_written;
$apg_wpopt_rtmap_written = null;

/** @global bool $apg_wpopt_rtmap_wrote True if at least one new row was appended. */
global $apg_wpopt_rtmap_wrote;
$apg_wpopt_rtmap_wrote = false;

/** @global bool $apg_wpopt_rtmap_observed True if a registration was seen with no owner to record. */
global $apg_wpopt_rtmap_observed;
$apg_wpopt_rtmap_observed = false;

/**
 * The magic header marking an at-rest-encrypted runtime map (matches the plugin's
 * scan-crypt module). Kept inline so the drop-in is self-contained.
 *
 * @return string
 */
function apg_wpopt_rtmap_magic() {
	return defined( 'WPOPT_SCAN_ENC_MAGIC' ) ? WPOPT_SCAN_ENC_MAGIC : "WPOPTENC1\n";
}

/**
 * Read the runtime map content, transparently decrypting when it is encrypted at
 * rest. Returns the plaintext CSV string, '' when the file is missing/empty, or
 * FALSE when it is encrypted but we cannot decrypt it (no crypto helpers this
 * request) — in which case the caller must NOT touch the file, to avoid corruption.
 *
 * @return string|false
 */
function apg_wpopt_rtmap_load_content() {
	$path = APG_WPOPT_RTMAP_CSV;
	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		return '';
	}
	// Por encima del techo, lo mismo que un mapa cifrado que no se puede abrir: esta petición
	// no escribe y el fichero no se toca (vaciarlo perdería la atribución acumulada).
	if ( (int) @filesize( $path ) > apg_wpopt_rtmap_max_bytes( 'map' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	$raw = @file_get_contents( $path );
	if ( false === $raw || '' === $raw ) {
		return '';
	}
	$magic = apg_wpopt_rtmap_magic();
	if ( 0 === strncmp( $raw, $magic, strlen( $magic ) ) ) {
		if ( ! function_exists( 'wpoptimizing_decrypt_csv' ) ) {
			return false; // Encrypted but no crypto available this request.
		}
		$plain = wpoptimizing_decrypt_csv( substr( $raw, strlen( $magic ) ), '' );
		if ( is_string( $plain ) ) {
			return $plain;
		}
		/*
		 * THE KEY IS THERE AND THE FILE DOES NOT OPEN WITH IT: the salts were rotated (or the
		 * file is damaged). Returning false here meant "skip, do not risk corrupting it" on
		 * every request, forever — the map stopped growing and nothing said so. It can never
		 * be read again, so it is moved aside and the map starts over. Only when wp_salt()
		 * exists: without it the key is a fallback and the failure says nothing about the file.
		 */
		if ( function_exists( 'wp_salt' ) && is_wp_error( $plain ) && in_array( $plain->get_error_code(), array( 'decrypt_failed', 'invalid_payload' ), true ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			if ( @rename( $path, $path . '.undecryptable' ) ) {
				return '';
			}
		}
		return false;
	}
	return $raw; // Plaintext.
}

/**
 * Parse the dedup keys (tipo:clave) from a runtime-map CSV string, as HASHES.
 *
 * Hashes, not the keys themselves, because the in-memory set is also what the sidecar index
 * stores: the index must not be a plaintext list of the keys of an encrypted map.
 *
 * Para una cadena que YA está en memoria (el único caso que queda: el mapa cifrado, que
 * AES-GCM obliga a descifrar entero). Lo demás se lee en flujo con
 * apg_wpopt_rtmap_keys_from_file(), que nunca carga el fichero.
 *
 * @param string $content CSV content.
 * @return array<string,true>
 */
function apg_wpopt_rtmap_parse_keys( $content ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem path.
	$fh = fopen( 'php://temp', 'r+' );
	if ( ! $fh ) {
		return array();
	}
	fwrite( $fh, (string) $content );
	rewind( $fh );
	$keys = apg_wpopt_rtmap_keys_from_stream( $fh );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $fh );
	return $keys;
}

/**
 * Las claves de deduplicación de un flujo de CSV ya abierto, fila a fila.
 *
 * AQUÍ ESTABA EL AGUJERO, y no se veía porque fallaba en silencio. Esto era un
 * `preg_split()` sobre el contenido entero: con el mapa pequeño funciona, y cuando creció
 * dejó de funcionar sin decir nada —un `preg_split()` que no puede con el sujeto devuelve
 * `false`, y `foreach ( (array) false )` recorre un solo elemento que se descarta como
 * cabecera—. El conjunto salía VACÍO, así que `already_written()` decía «no» a todo y cada
 * petición volvía a escribir lo que ya estaba. El 22/11/2026 el mapa tenía 13.231.896 filas
 * para 325.211 claves distintas: el 97,5 % eran repeticiones de ese fallo, 1,56 GB, y
 * cargarlo reventaba el tope de 2 GB en cada pasada del alimentador. El fatal es código
 * propio, y un fatal propio cierra la guarda de la barrida: los TRES agentes llevaban seis
 * horas sin analizar nada.
 *
 * Leído así, la memoria no depende del tamaño del fichero sino del número de claves
 * DISTINTAS, que es lo único que hace falta tener a mano. Y `fgetcsv()` entiende los saltos
 * de línea dentro de un campo entrecomillado, cosa que partir por `\n` nunca hizo.
 *
 * @param resource $fh Flujo abierto para lectura, posicionado al principio.
 * @return array<string,true>
 */
function apg_wpopt_rtmap_keys_from_stream( $fh ) {
	$keys    = array();
	$primera = true;
	while ( false !== ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) ) {
		if ( $primera ) {
			$primera = false;
			continue; // header
		}
		// Una línea en blanco sale de fgetcsv() como array( null ).
		if ( ! is_array( $row ) || count( $row ) < 3 ) {
			continue;
		}
		// An empty-slug row (written before apg_wpopt_rtmap_write() refused them) does not
		// count as written: the owner it never had can still get in.
		if ( '' === trim( (string) $row[0] ) ) {
			continue;
		}
		$keys[ apg_wpopt_rtmap_key_hash( apg_wpopt_rtmap_clave_de( $row[0], $row[1], $row[2] ) ) ] = true;
	}
	return $keys;
}

/**
 * ¿El mapa está cifrado EN DISCO? Se mira leyendo los primeros bytes, nunca el fichero.
 *
 * @param string $path Ruta del mapa.
 * @return bool
 */
function apg_wpopt_rtmap_cifrado_en_disco( $path ) {
	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		return false;
	}
	$magic = apg_wpopt_rtmap_magic();
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
	$fh = @fopen( $path, 'r' );
	if ( ! $fh ) {
		return false;
	}
	$cabeza = (string) fread( $fh, strlen( $magic ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $fh );
	return 0 === strncmp( $cabeza, $magic, strlen( $magic ) );
}

/**
 * ¿Se puede AÑADIR al mapa en vez de reescribirlo entero?
 *
 * Sólo cuando ya está en claro y este sitio no sabe cifrar, que es exactamente la regla que
 * aplica `persist()` para no degradar un mapa cifrado. Donde hay cifrado se sigue reescribiendo
 * entero porque AES-GCM no admite añadir, y ahí los mapas son de megabytes, no de gigabytes.
 *
 * @param string $path Ruta del mapa.
 * @return bool
 */
function apg_wpopt_rtmap_en_claro( $path ) {
	return ! apg_wpopt_rtmap_cifrado_en_disco( $path ) && ! function_exists( 'wpoptimizing_encrypt_csv' );
}

/**
 * Las claves de deduplicación del mapa que hay en disco, SIN cargarlo.
 *
 * @return array<string,true>|false FALSE si está cifrado y no se puede descifrar ahora.
 */
function apg_wpopt_rtmap_keys_from_file() {
	$path = APG_WPOPT_RTMAP_CSV;
	if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
		return array();
	}
	if ( apg_wpopt_rtmap_cifrado_en_disco( $path ) ) {
		$content = apg_wpopt_rtmap_load_content();
		if ( false === $content ) {
			return false;
		}
		return apg_wpopt_rtmap_parse_keys( $content );
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
	$fh = @fopen( $path, 'r' );
	if ( ! $fh ) {
		return array();
	}
	$keys = apg_wpopt_rtmap_keys_from_stream( $fh );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $fh );
	return $keys;
}

/**
 * Deja la cabecera de AHORA en el fichero, migrando en flujo la que ya estuviera escrita.
 *
 * Es `ensure_header()` para un fichero que no cabe en memoria: lee la primera línea y, sólo si
 * hay que migrarla, copia el resto por trozos a un temporal y lo publica con rename.
 *
 * @param string $path Ruta del mapa (en claro).
 * @return bool FALSE si había que migrar y no se pudo: el que llama no debe escribir.
 */
function apg_wpopt_rtmap_cabecera_en_fichero( $path ) {
	$cabecera = apg_wpopt_rtmap_header();
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! file_exists( $path ) || 0 === (int) @filesize( $path ) ) {
		return true; // No hay nada que migrar: la escribe quien añada la primera fila.
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
	$in = @fopen( $path, 'r' );
	if ( ! $in ) {
		return false;
	}
	$primera = (string) fgets( $in );
	if ( rtrim( $cabecera, "\n" ) === rtrim( $primera, "\r\n" ) || false === strpos( $primera, "slug\ttipo\tclave" ) ) {
		// Ya está, o no es una cabecera nuestra y no se toca lo que no se entiende.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		return true;
	}
	$tmp = $path . '.tmp.' . uniqid( '', true );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
	$out = @fopen( $tmp, 'w' );
	if ( ! $out ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $in );
		return false;
	}
	$ok = ( false !== fwrite( $out, $cabecera ) );
	while ( $ok && ! feof( $in ) ) {
		$trozo = fread( $in, 1048576 );
		if ( false === $trozo ) {
			$ok = false;
			break;
		}
		if ( '' !== $trozo && false === fwrite( $out, $trozo ) ) {
			$ok = false;
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $in );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $out );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
	if ( ! $ok || ! @rename( $tmp, $path ) ) {
		if ( file_exists( $tmp ) ) {
			wp_delete_file( $tmp );
		}
		return false;
	}
	return true;
}

/**
 * Populate the in-memory dedup set from the existing map (once per request). Sets the
 * dedup set to FALSE when the file is encrypted-but-unreadable, signalling that no
 * write should happen this request (never append plaintext to an encrypted file).
 *
 * @return void
 */
function apg_wpopt_rtmap_ensure_loaded() {
	global $apg_wpopt_rtmap_written;
	if ( null !== $apg_wpopt_rtmap_written ) {
		return;
	}

	// El camino rápido. La pregunta que se hace aquí es sólo «¿tengo ya esta clave?», y para
	// contestarla esto descifraba (AES-GCM) y parseaba con str_getcsv el mapa entero —2,2 MB en
	// un sitio real— en cada petición que capturaba algo, checkout incluido. El índice lateral
	// contesta lo mismo leyendo un fichero de hashes.
	$rapido = apg_wpopt_rtmap_index_read();
	if ( null !== $rapido ) {
		$apg_wpopt_rtmap_written = $rapido;
		return;
	}

	// Y cuando el índice no vale, se reconstruye LEYENDO EL MAPA EN FLUJO. Antes se cargaba
	// entero, que es lo que acabó parando el laboratorio entero: ver
	// apg_wpopt_rtmap_keys_from_stream().
	$claves = apg_wpopt_rtmap_keys_from_file();
	if ( false === $claves ) {
		$apg_wpopt_rtmap_written = false; // Encrypted + unreadable: skip writes this request.
		return;
	}
	$apg_wpopt_rtmap_written = $claves;
	apg_wpopt_rtmap_index_write( $claves );
}

/**
 * Hash of a dedup key. 48 bits: con ~20.000 claves la probabilidad de colisión es ~1e-6, y una
 * colisión sólo pierde UNA atribución (la da por ya escrita), nunca corrompe el mapa.
 *
 * @param string $key 'tipo:clave'.
 * @return string
 */
function apg_wpopt_rtmap_key_hash( $key ) {
	return substr( hash_hmac( 'md5', (string) $key, apg_wpopt_rtmap_sal() ), 0, 12 );
}

/**
 * La sal con la que se marcan los hashes del índice lateral.
 *
 * POR QUÉ HACE FALTA, y es una propiedad que había que devolver, no añadir.
 *
 * El `.idx` está documentado en el cuaderno entre agentes como publicable-sin-riesgo por una
 * razón concreta: lleva «hashes truncados de las claves, sin cifrar y SIN slugs (lo sensible es
 * el emparejamiento clave→plugin, y eso no está ahí)». Al pasar la identidad a
 * dueño+tipo+clave el 22/11/2026, los hashes empezaron a ser de `slug|tipo:clave`, o sea
 * exactamente ese emparejamiento. Con `md5()` a secas eso es COMPROBABLE: quien tenga un par
 * candidato lo confirma hasheándolo, y 48 bits no defienden nada frente a una conjetura.
 *
 * Con `hash_hmac()` y una sal del sitio, el índice deja de poder usarse para confirmar nada
 * desde fuera. La sal no se guarda en el índice —lo que se guarda es su huella, y es una huella
 * HMAC, no la sal—, y el índice sigue siendo local, reconstruible y fuera del envío al gateway.
 *
 * Sin `NONCE_KEY` y `NONCE_SALT` la sal es vacía: los hashes siguen siendo coherentes entre sí y la huella del
 * sello lo delata, así que un índice salado y uno sin salar nunca se mezclan.
 *
 * @return string
 */
function apg_wpopt_rtmap_sal() {
	static $sal = null;
	if ( null !== $sal ) {
		return $sal;
	}
	// De las constantes de wp-config.php y no de wp_salt(): wp_salt() es «pluggable» y no existe
	// mientras cargan los plugins, que es justo cuando se captura. Con wp_salt() la primera
	// llamada de cada petición decidía la sal, el sello del índice cambiaba de una petición a
	// otra y cada cambio obligaba a recorrer el mapa entero para rehacerlo. Es la misma pareja
	// que usa wp_salt( 'nonce' ) cuando está definida.
	$sal = ( defined( 'NONCE_KEY' ) && defined( 'NONCE_SALT' ) ) ? (string) NONCE_KEY . (string) NONCE_SALT : '';
	return $sal;
}

/**
 * Huella de la sal, para el sello del índice. No es la sal ni permite recuperarla.
 *
 * @return string
 */
function apg_wpopt_rtmap_sal_huella() {
	return substr( hash_hmac( 'md5', 'idx', apg_wpopt_rtmap_sal() ), 0, 8 );
}

/**
 * La clave de identidad de una fila: DUEÑO, tipo y clave.
 *
 * EL MAPA DICE QUIÉN HA SIDO VISTO REGISTRANDO ESTO, no de quién es esto. Dos extensiones que
 * registran el mismo `rest_namespace` son dos observaciones, no una repetición: así lo decidió
 * Emilio el 22/11/2026, y es la identidad que ya usaba el OTRO escritor del mismo fichero
 * —`apg_runtime_map_registrar()` fusiona por `slug`+`tipo`+`clave`—, que llevaba razón.
 *
 * Con la identidad de dos columnas que había aquí, el primero que reclamaba una clave se la
 * quedaba y los demás dueños no entraban nunca. Medido sobre el mapa de ese día: 23.679 filas
 * de 348.940 eran dueños distintos de una clave ya reclamada, o sea el 6,8 % del mapa.
 *
 * @param string $slug  Dueño.
 * @param string $tipo  Tipo de elemento.
 * @param string $clave Clave del elemento.
 * @return string
 */
function apg_wpopt_rtmap_clave_de( $slug, $tipo, $clave ) {
	return strtolower( trim( (string) $slug ) ) . '|'
		. strtolower( trim( (string) $tipo ) ) . ':'
		. strtolower( trim( (string) $clave ) );
}

/**
 * Size ceilings for what this file reads into memory on a live request.
 *
 * El mapa y su índice se leen enteros en las peticiones que capturan (escritorio, REST y
 * cualquier POST del front, checkout incluido). En un sitio real pesan 3,6 MB y 316 KB, pero
 * nada impedía que un fichero crecido por un fallo nuestro, o dañado, se cargara entero en cada
 * una de esas peticiones: el 11/11/2026 una caída por memoria en comefruta.es registró 15
 * fatales en la lectura del índice. No eran la causa (fue un fichero de idioma), pero este
 * código no debe poder ser nunca el que agota la memoria. Por encima del techo: el índice se da
 * por no válido y se rehace desde el mapa; el mapa no se toca y esa petición no captura.
 *
 * @param string $que 'index' or 'map'.
 * @return int Bytes.
 */
function apg_wpopt_rtmap_max_bytes( $que ) {
	return 'index' === $que ? 8 * 1024 * 1024 : 32 * 1024 * 1024;
}

/**
 * Sidecar index path.
 *
 * @return string
 */
function apg_wpopt_rtmap_index_file() {
	return APG_WPOPT_RTMAP_CSV . '.idx';
}

/**
 * Read the sidecar index, but only when it still describes the map on disk.
 *
 * NO LLEVA NADA SENSIBLE. Lo que se cifra en el mapa es el EMPAREJAMIENTO «clave → plugin que
 * la creó»; aquí no hay slugs, sólo hashes truncados de las claves. Y la validez se comprueba
 * contra el mtime y el tamaño del CSV: si alguien reescribe o re-cifra el mapa, el índice deja
 * de valer y se reconstruye solo en la siguiente petición que capture.
 *
 * @return array<string,true>|null Set of key hashes, or null when there is no usable index.
 */
function apg_wpopt_rtmap_index_read() {
	$csv = APG_WPOPT_RTMAP_CSV;
	if ( ! file_exists( $csv ) ) {
		return null; // Sin mapa no hay nada que deduplicar; que lo resuelva el camino normal.
	}
	$idx = apg_wpopt_rtmap_index_file();
	if ( ! file_exists( $idx ) || ! is_readable( $idx ) ) {
		return null;
	}
	if ( (int) @filesize( $idx ) > apg_wpopt_rtmap_max_bytes( 'index' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return null; // Demasiado grande para ser un índice sano: se rehace desde el mapa.
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	$raw = @file_get_contents( $idx );
	if ( false === $raw || '' === $raw ) {
		return null;
	}
	$corte = strpos( $raw, "\n" );
	if ( false === $corte ) {
		return null;
	}
	// v2: empty-slug rows stopped counting as written; a v1 index still listed them.
	$esperado = 'APGIDX4 ' . apg_wpopt_rtmap_sal_huella() . ' ' . (int) @filemtime( $csv ) . ' ' . (int) @filesize( $csv ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( substr( $raw, 0, $corte ) !== $esperado ) {
		return null; // Índice viejo: el mapa cambió por debajo.
	}
	$cuerpo = substr( $raw, $corte + 1 );
	if ( '' === trim( $cuerpo ) ) {
		return array();
	}
	return array_fill_keys( explode( "\n", trim( $cuerpo ) ), true );
}

/**
 * Write the sidecar index for the map as it stands on disk right now.
 *
 * Atomic temp+rename with a unique temp name, por lo mismo que persist(): esto corre en
 * cualquier POST del front, así que dos peticiones a la vez es el caso normal.
 *
 * @param array<string,true> $hashes Key hashes.
 * @return void
 */
function apg_wpopt_rtmap_index_write( array $hashes ) {
	$csv = APG_WPOPT_RTMAP_CSV;
	if ( ! file_exists( $csv ) ) {
		return;
	}
	$cabecera = 'APGIDX4 ' . apg_wpopt_rtmap_sal_huella() . ' ' . (int) @filemtime( $csv ) . ' ' . (int) @filesize( $csv ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	$datos    = $cabecera . "\n" . implode( "\n", array_keys( $hashes ) ) . "\n";
	$idx      = apg_wpopt_rtmap_index_file();
	$tmp      = $idx . '.tmp.' . uniqid( '', true );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false !== @file_put_contents( $tmp, $datos ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $idx ) ) {
			wp_delete_file( $tmp );
		}
	} elseif ( file_exists( $tmp ) ) {
		wp_delete_file( $tmp );
	}
}

/**
 * ¿Está ya en el mapa esta terna dueño/tipo/clave?
 *
 * ANTES ESTO SE PREGUNTABA SIN EL DUEÑO, y era medio truco: los capturadores llamaban aquí
 * ANTES del `debug_backtrace()` para que una clave ya mapeada costara una búsqueda en un array
 * en vez de un recorrido de pila. Con la identidad por dueño eso no se puede: la respuesta
 * depende de quién sea, y quién sea es justo lo que cuesta averiguar. El atajo desaparece y los
 * capturadores resuelven el dueño primero; lo que se compraba con él era velocidad, y lo que
 * costaba era no registrar nunca al segundo dueño de una clave.
 *
 * @param string $slug  Dueño.
 * @param string $tipo  Item type.
 * @param string $clave Item key.
 * @return bool
 */
function apg_wpopt_rtmap_already_written( $slug, $tipo, $clave ) {
	global $apg_wpopt_rtmap_written;
	if ( '' === strtolower( trim( (string) $slug ) ) ) {
		return false; // Sin dueño no hay identidad que preguntar.
	}
	apg_wpopt_rtmap_ensure_loaded();
	if ( false === $apg_wpopt_rtmap_written ) {
		return true; // Encrypted map we cannot read this request: nothing would be written anyway.
	}
	return isset( $apg_wpopt_rtmap_written[ apg_wpopt_rtmap_key_hash( apg_wpopt_rtmap_clave_de( $slug, $tipo, $clave ) ) ] );
}

/**
 * Queue a new entry for the runtime map (dedup-safe). Entries are buffered in memory
 * and flushed once, ENCRYPTED at rest, on shutdown — so the sensitive attribution
 * data (item name -> owning plugin) is never left in plaintext on disk. Appending was
 * replaced by a buffered encrypted rewrite because AES-GCM cannot append.
 *
 * @param string $slug      Plugin/theme slug ('' = unknown: nothing is queued).
 * @param string $tipo      Item type (post_type, taxonomy, option…).
 * @param string $clave     Item key.
 * @param string $contexto  Optional context string.
 * @param string $confianza Confidence level ('certain'|'strong'|'heuristic').
 * @return void
 */
function apg_wpopt_rtmap_write( $slug, $tipo, $clave, $contexto = '', $confianza = 'certain' ) {
	global $apg_wpopt_rtmap_written, $apg_wpopt_rtmap_wrote, $apg_wpopt_rtmap_pending, $apg_wpopt_rtmap_flush_hooked;

	$tipo  = strtolower( trim( (string) $tipo ) );
	$clave = strtolower( trim( (string) $clave ) );
	if ( '' === $tipo || '' === $clave ) {
		return;
	}

	/*
	 * NO OWNER, NO ROW. The dedup is on tipo:clave, so a row queued with an empty slug —
	 * a registration seen before its owner could be told — kept the key "already written"
	 * for good, and the owner it had when it was finally known never got in. The readers
	 * drop empty-slug rows anyway. It still counts as observed, so the signature gets sealed
	 * exactly as it did when the empty row was written.
	 */
	if ( '' === strtolower( trim( (string) $slug ) ) ) {
		$GLOBALS['apg_wpopt_rtmap_observed'] = true;
		return;
	}

	apg_wpopt_rtmap_ensure_loaded();
	if ( false === $apg_wpopt_rtmap_written ) {
		return; // Encrypted map we cannot read this request — do not risk corrupting it.
	}

	$key = apg_wpopt_rtmap_key_hash( apg_wpopt_rtmap_clave_de( $slug, $tipo, $clave ) );
	if ( isset( $apg_wpopt_rtmap_written[ $key ] ) ) {
		return;
	}
	$apg_wpopt_rtmap_written[ $key ] = true;
	$apg_wpopt_rtmap_wrote           = true;

	if ( ! is_array( $apg_wpopt_rtmap_pending ) ) {
		$apg_wpopt_rtmap_pending = array();
	}

	/*
	 * LAS FECHAS EN EPOCH, no `gmdate('Y-m-d')`. El otro productor escribe epoch y el gateway
	 * compara con `(int)`: `(int) '2026-09-26'` es 2026, así que frente a un epoch de diez
	 * cifras una fecha legible pierde SIEMPRE el «gana la más reciente», y el `min()` de
	 * `first_seen` podía dejar grabado 2026 — enero de 1970 leído como epoch. Decidido epoch
	 * en los dos lados el 9/10/2026.
	 *
	 * Y `clase` al final, que es de quién es esta clave: ver apg_wpopt_rtmap_clase_from_slug().
	 */
	$ahora = (string) time();
	$slug  = strtolower( trim( (string) $slug ) );

	$apg_wpopt_rtmap_pending[] = array(
		$slug,
		$tipo,
		$clave,
		(string) $contexto,
		(string) $confianza,
		'',
		get_bloginfo( 'version' ),
		$ahora,
		$ahora,
		apg_wpopt_rtmap_clase_from_slug( $slug ),
		// `observed` sin condiciones: esto es un interceptor, y la fila existe porque el
		// registro, la escritura o el gancho ACABAN DE PASAR en esta petición. No hay otra
		// forma de llegar aquí, así que no hay nada que decidir.
		'observed',
	);

	if ( empty( $apg_wpopt_rtmap_flush_hooked ) ) {
		$apg_wpopt_rtmap_flush_hooked = true;
		if ( function_exists( 'add_action' ) ) {
			add_action( 'shutdown', 'apg_wpopt_rtmap_flush', 999 );
		} else {
			register_shutdown_function( 'apg_wpopt_rtmap_flush' );
		}
	}
}

/**
 * Flush the buffered entries to disk once, re-reading the current map first (so
 * concurrent writers are merged, not clobbered) and writing the whole file encrypted
 * at rest. No-op when nothing was queued.
 *
 * @return void
 */
function apg_wpopt_rtmap_flush() {
	global $apg_wpopt_rtmap_pending;
	if ( empty( $apg_wpopt_rtmap_pending ) ) {
		return;
	}
	/*
	 * NOT AFTER AN OUT-OF-MEMORY FATAL. This decrypts and rewrites a map of several MB, and a
	 * request that ran out of memory has nothing left to do it with: the attempt only dies a
	 * second time. No reserve small enough to set aside on every request would cover it, so the
	 * samples of that request are dropped, which this file already accepts. The write is temp +
	 * rename, so skipping never leaves the map half written either.
	 */
	$fatal = error_get_last();
	if ( is_array( $fatal ) && E_ERROR === (int) $fatal['type'] && false !== strpos( (string) $fatal['message'], 'Allowed memory size' ) ) {
		$apg_wpopt_rtmap_pending = array();
		return;
	}
	$pending                 = $apg_wpopt_rtmap_pending;
	$apg_wpopt_rtmap_pending = array();

	$path = APG_WPOPT_RTMAP_CSV;
	$dir  = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
	}

	/*
	 * EN CLARO SE AÑADE; CIFRADO SE REESCRIBE.
	 *
	 * El camino de abajo —leer el mapa entero, concatenarle las filas nuevas y volver a
	 * escribirlo— existe porque AES-GCM no admite añadir, y mientras el mapa fue de megabytes
	 * daba igual. Cuando llegó a 1,56 GB dejó de dar igual: la concatenación sola ya pide el
	 * doble del fichero en memoria. Sobre un mapa que no está cifrado y que este sitio tampoco
	 * puede cifrar —la misma condición que mira persist() para no degradarlo— añadir al final
	 * cuesta lo que ocupan las filas nuevas y nada más.
	 */
	if ( apg_wpopt_rtmap_en_claro( $path ) ) {
		// La cabecera se migra ANTES de coger el candado: migrarla es un rename, y un candado
		// sobre el inode viejo no protegería al nuevo.
		if ( ! apg_wpopt_rtmap_cabecera_en_fichero( $path ) ) {
			return; // Había que migrar la cabecera y no se pudo: no se añade sobre la vieja.
		}

		/*
		 * EL CANDADO CUBRE LEER Y AÑADIR, NO SOLO AÑADIR.
		 *
		 * Con el candado puesto únicamente alrededor de la escritura, la secuencia real era
		 * «leo las claves, decido, escribo», y entre lo primero y lo último cabe otro proceso.
		 * Aquí corren tres agentes de barrida y el cron a la vez, así que dos procesos leían
		 * «esta clave no está» en la misma ventana y los dos la escribían. Medido el 22/11/2026
		 * sobre el mapa recién compactado, ventana de 3 min: +49 filas y +0 claves, o sea el
		 * 100 % de lo añadido repetido, con el índice lateral perfectamente sano —mismas claves
		 * que el mapa y el sello encajando—. No era el índice: era la ventana.
		 *
		 * El candado no cuesta lo que parece: dentro de él nadie puede añadir, así que el
		 * sello del índice describe de verdad lo que hay en disco y vale el camino rápido
		 * —0,02 s—. Solo cuando el índice no sirve se recorre el mapa.
		 */
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged
		$fh = @fopen( $path, 'a+' );
		if ( ! $fh ) {
			return;
		}
		// NO BLOQUEANTE: esto corre al final de peticiones de visitantes, y un LOCK_EX que
		// espera pone en cola a los procesos PHP sobre un mismo inode. Si otro está volcando,
		// éste no vuelca: nada de lo pendiente llegó al mapa, así que se volverá a capturar.
		if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return;
		}

		clearstatcache( true, $path );
		$existing = apg_wpopt_rtmap_index_read();
		if ( null === $existing ) {
			$existing = apg_wpopt_rtmap_keys_from_file();
		}
		if ( ! is_array( $existing ) ) {
			flock( $fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem path.
		$buf   = fopen( 'php://temp', 'r+' );
		$added = false;
		foreach ( $pending as $r ) {
			$k = apg_wpopt_rtmap_key_hash( apg_wpopt_rtmap_clave_de( $r[0], $r[1], $r[2] ) );
			if ( isset( $existing[ $k ] ) ) {
				continue;
			}
			$existing[ $k ] = true;
			fputcsv( $buf, $r, "\t", '"', '\\' );
			$added = true;
		}
		if ( ! $added ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $buf );
			flock( $fh, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return;
		}
		rewind( $buf );
		$nuevas = (string) stream_get_contents( $buf );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $buf );

		$stat = fstat( $fh );
		if ( empty( $stat['size'] ) ) {
			fwrite( $fh, apg_wpopt_rtmap_header() );
		} elseif ( 0 === fseek( $fh, -1, SEEK_END ) && "\n" !== fread( $fh, 1 ) ) {
			// Un fichero sin salto final pegaría la primera fila nueva a la última vieja.
			fwrite( $fh, "\n" );
		}
		$escrito = ( false !== fwrite( $fh, $nuevas ) );
		fflush( $fh );

		// El índice se sella con el mtime y el TAMAÑO del CSV, y añadir no los invalida en la
		// caché de stat de PHP como sí hace rename(). Se escribe DENTRO del candado a propósito:
		// fuera, otro proceso podría haber añadido ya, y este sello describiría un fichero que no
		// es el que trae estas claves.
		if ( $escrito ) {
			clearstatcache( true, $path );
			apg_wpopt_rtmap_index_write( $existing );
		}

		flock( $fh, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );
		return;
	}

	// Re-read fresh (decrypt) so rows added by a concurrent request are preserved.
	$content = apg_wpopt_rtmap_load_content();
	if ( false === $content ) {
		return; // Encrypted + unreadable right now: skip rather than corrupt.
	}
	$content = (string) $content;
	$content = apg_wpopt_rtmap_ensure_header( $content );
	if ( "\n" !== substr( $content, -1 ) ) {
		$content .= "\n";
	}

	$existing = apg_wpopt_rtmap_parse_keys( $content );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream, not a filesystem path.
	$buf   = fopen( 'php://temp', 'r+' );
	$added = false;
	foreach ( $pending as $r ) {
		$k = apg_wpopt_rtmap_key_hash( apg_wpopt_rtmap_clave_de( $r[0], $r[1], $r[2] ) );
		if ( isset( $existing[ $k ] ) ) {
			continue;
		}
		$existing[ $k ] = true;
		fputcsv( $buf, $r, "\t", '"', '\\' );
		$added = true;
	}
	if ( ! $added ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $buf );
		return;
	}
	rewind( $buf );
	$content .= (string) stream_get_contents( $buf );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	fclose( $buf );

	apg_wpopt_rtmap_persist( $path, $content );

	// El mapa acaba de cambiar, así que el índice que lo describía ya no vale. Reescribirlo
	// aquí —donde el set completo ya está en memoria— evita que la siguiente petición pague el
	// descifrado entero sólo para reconstruirlo.
	apg_wpopt_rtmap_index_write( $existing );
}

/**
 * Write the runtime map, encrypted at rest when the plugin crypto is available. When
 * it is not, fall back to plaintext ONLY if the on-disk file is not already encrypted
 * (never corrupt an encrypted file). Atomic temp+rename.
 *
 * @param string $path    Destination path.
 * @param string $content Plaintext CSV content.
 * @return void
 */
function apg_wpopt_rtmap_persist( $path, $content ) {
	$magic = apg_wpopt_rtmap_magic();
	$data  = null;
	if ( function_exists( 'wpoptimizing_encrypt_csv' ) ) {
		$enc = wpoptimizing_encrypt_csv( $content, '' );
		if ( is_string( $enc ) && '' !== $enc ) {
			$data = $magic . $enc;
		}
	}
	if ( null === $data ) {
		/*
		 * SÓLO LOS PRIMEROS BYTES. Esto leía el fichero ENTERO para comparar diez caracteres de
		 * cabecera: con el mapa en 1,56 GB era reservar 1,5 GB para saber si empieza por «WPOPTENC1».
		 */
		if ( apg_wpopt_rtmap_cifrado_en_disco( $path ) ) {
			return; // Encrypted on disk but we cannot encrypt → skip to avoid corruption.
		}
		$data = $content;
	}
	/*
	 * A temp name unique to this attempt. The fixed "<path>.tmp" this used to write meant two
	 * concurrent requests shared one file — and this runs on ANY front-end POST, so two
	 * checkouts at the same second is the ordinary case, not the corner one. The second request
	 * truncates and rewrites the temp while the first is still filling it, and whichever
	 * reaches rename() first publishes the other's bytes. The map is encrypted, so a half
	 * written file does not decrypt at all: the runtime attribution — the only layer that is
	 * certain rather than inferred — disappears without a message anywhere.
	 *
	 * Deliberately NOT calling the plugin's shared helper: this file has to keep working when
	 * the plugin is deactivated or gone, which is the whole point of a must-use runtime.
	 */
	$tmp = $path . '.tmp.' . uniqid( '', true );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
	if ( false !== @file_put_contents( $tmp, $data, LOCK_EX ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! @rename( $tmp, $path ) ) {
			wp_delete_file( $tmp );
		}
	} elseif ( file_exists( $tmp ) ) {
		wp_delete_file( $tmp ); // A failed write leaves no litter behind either.
	}
}

// ── Attribution helpers ───────────────────────────────────────────────────────

/**
 * Build a map of absolute path → plugin/theme slug (cached per-request).
 *
 * @return array<string,string>
 */
function apg_wpopt_rtmap_file_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	/*
	 * FOLDERS END IN A SLASH, single-file plugins are exact files. Without the slash
	 * `plugins/jetpack` was a prefix of `plugins/jetpack-boost/...` and the first entry that
	 * matched won: Jetpack Boost's writes went to Jetpack, WooCommerce Subscriptions' to
	 * WooCommerce, twentytwentyone's to twentytwenty. apg_wpopt_rtmap_match_path() also keeps
	 * the LONGEST match, so a nested folder never loses to its parent.
	 */
	foreach ( array_keys( get_plugins() ) as $basename ) {
		$dir = dirname( $basename );
		if ( '.' === $dir ) {
			$map[ WP_PLUGIN_DIR . '/' . $basename ] = basename( $basename, '.php' );
		} else {
			$map[ WP_PLUGIN_DIR . '/' . $dir . '/' ] = $dir;
		}
	}
	foreach ( wp_get_themes() as $slug => $theme ) {
		$map[ rtrim( (string) $theme->get_stylesheet_directory(), '/' ) . '/' ] = $slug;
	}
	return $map;
}

/**
 * Owner of a file in a path => slug map: the LONGEST folder prefix (keys ending in '/') or an
 * exact single-file key. Pure.
 *
 * @param string               $file Absolute path.
 * @param array<string,string> $map  Path => slug.
 * @return string Slug or ''.
 */
function apg_wpopt_rtmap_match_path( $file, array $map ) {
	$file = (string) $file;
	$best = '';
	$long = 0;
	foreach ( $map as $path => $slug ) {
		$path = (string) $path;
		$len  = strlen( $path );
		if ( $len <= $long ) {
			continue;
		}
		$hit = ( '/' === substr( $path, -1 ) ) ? ( 0 === strncmp( $file, $path, $len ) ) : ( $file === $path );
		if ( $hit ) {
			$best = (string) $slug;
			$long = $len;
		}
	}
	return $best;
}

/**
 * La cabecera del mapa local, con `clase`.
 *
 * @return string
 */
function apg_wpopt_rtmap_header() {
	return "slug\ttipo\tclave\tcontexto\tconfianza\tplugin_version\twp_version\tfirst_seen\tlast_seen\tclase\tevidencia\n";
}

/**
 * Deja el contenido con la cabecera de ahora, migrando la que ya esté escrita.
 *
 * SIN ESTO, LA COLUMNA NUEVA NO EXISTE PARA NADIE. El mapa se escribe añadiendo al final y
 * conservando la cabecera que ya tenía el fichero, así que un fichero de antes seguiría
 * anunciando nueve columnas mientras las filas nuevas traen diez — y el lector, que lee por
 * NOMBRE de columna, no encontraría `clase` en la cabecera y tiraría el dato sin un solo error.
 *
 * Cambiar la cabecera no toca las filas viejas: les falta la décima columna, el lector la lee
 * como vacía y vacío significa «no lo sé», que es exactamente lo que se sabe de ellas.
 *
 * @param string $content Contenido en claro.
 * @return string
 */
function apg_wpopt_rtmap_ensure_header( $content ) {
	$content = (string) $content;
	if ( '' === trim( $content ) ) {
		return apg_wpopt_rtmap_header();
	}
	$corte   = strpos( $content, "\n" );
	$primera = ( false === $corte ) ? $content : substr( $content, 0, $corte );
	// Se compara con la cabecera de AHORA, no se busca una columna concreta: así cada ampliación
	// de la forma se migra sola y no hay que acordarse de cambiar también esta comprobación.
	if ( rtrim( apg_wpopt_rtmap_header(), "\n" ) === rtrim( $primera, "\r" ) ) {
		return $content; // Ya está.
	}
	if ( false === strpos( $primera, "slug\ttipo\tclave" ) ) {
		return $content; // No es una cabecera nuestra: no se toca lo que no se entiende.
	}

	return apg_wpopt_rtmap_header() . ( ( false === $corte ) ? '' : substr( $content, $corte + 1 ) );
}

/**
 * De qué CLASE es un slug: `plugin`, `theme` o ''.
 *
 * El mapa de arriba dice de quién es un fichero y nunca de qué clase es ese quién, y ese hueco
 * es el que hacía que TODOS los huérfanos se pintaran como temas: el cliente deducía la clase
 * del índice de lo instalado, que sirve para lo instalado y no para un huérfano, que por
 * definición ya no está. Anotándola AQUÍ —cuando la extensión sí está y está ejecutándose— el
 * dato sobrevive a su desinstalación, que es justo cuando hace falta.
 *
 * Vacío cuando no se sabe, porque «no lo sé» es una respuesta y `unknown` es una afirmación.
 *
 * @param string $slug Slug ya normalizado.
 * @return string
 */
function apg_wpopt_rtmap_clase_from_slug( $slug ) {
	static $clases = null;
	$slug          = strtolower( trim( (string) $slug ) );
	if ( '' === $slug ) {
		return '';
	}
	if ( null === $clases ) {
		$clases = array();
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		foreach ( array_keys( get_plugins() ) as $basename ) {
			$dir = dirname( $basename );
			$clases[ strtolower( ( '.' === $dir ) ? basename( $basename, '.php' ) : $dir ) ] = 'plugin';
		}
		foreach ( array_keys( wp_get_themes() ) as $tema ) {
			// Un slug que es a la vez plugin y tema en el mismo sitio no existe en la práctica,
			// pero si lo hubiera gana el plugin: es lo que ya estaba anotado.
			if ( ! isset( $clases[ strtolower( (string) $tema ) ] ) ) {
				$clases[ strtolower( (string) $tema ) ] = 'theme';
			}
		}
	}

	return isset( $clases[ $slug ] ) ? $clases[ $slug ] : '';
}

/**
 * Resolve an absolute file path to a plugin/theme slug.
 *
 * @param string $file Absolute path.
 * @return string Slug or ''.
 */
function apg_wpopt_rtmap_slug_from_file( $file ) {
	if ( '' === $file ) {
		return '';
	}
	return apg_wpopt_rtmap_match_path( $file, apg_wpopt_rtmap_file_map() );
}

/**
 * The wp-content drop-ins that stand in for core, by the path PHP reports for them.
 *
 * They are NOT their plugin's code running. Query Monitor's db.php is a link into
 * plugins/query-monitor/, and PHP reports the link's target: every database write on a site
 * with Query Monitor active went through that file before reaching the `query` filter, so the
 * SQL-level capture attributed Avada's options, `cron`, `rewrite_rules` and `active_plugins`
 * to Query Monitor as CERTAIN (emiliocalvo.es, 16/10/2026: 62 options). Both spellings of each
 * drop-in, the link and its target, are skipped.
 *
 * @return array<string,true>
 */
function apg_wpopt_rtmap_dropin_files() {
	static $files = null;
	if ( null !== $files ) {
		return $files;
	}
	$files = array();
	foreach ( array( 'db.php', 'object-cache.php', 'advanced-cache.php' ) as $dropin ) {
		$path = WP_CONTENT_DIR . '/' . $dropin;
		if ( file_exists( $path ) ) {
			$files[ $path ] = true;
			$real           = realpath( $path );
			if ( false !== $real ) {
				$files[ $real ] = true;
			}
		}
	}
	return $files;
}

/**
 * A frame of the database class or of a replacement for it (Query Monitor, LudicrousDB,
 * HyperDB, W3 Total Cache's database cache…). Those replacements keep their code in their own
 * plugin folder, so skipping the drop-in file alone would still hand them the write: whoever
 * called $wpdb is the owner, never the class that carried the query out.
 *
 * @param array $frame Backtrace frame.
 * @return bool
 */
function apg_wpopt_rtmap_is_wpdb_frame( array $frame ) {
	$class = (string) ( $frame['class'] ?? '' );
	if ( '' === $class ) {
		return false;
	}
	return 'wpdb' === strtolower( $class ) || is_subclass_of( $class, 'wpdb' );
}

/**
 * Walk the call stack to find the first frame outside WP core and this file.
 * When a frame has no file (internal/closure), falls back to ReflectionClass.
 *
 * Returns NULL when the write must not be recorded at all, not even by the prefix fallback:
 *  - a must-use plugin made the call. Skipping its frame handed the write to whatever was
 *    further out — a mu-plugin writing inside WooCommerce's do_action() was recorded as
 *    woocommerce/certain. The mu-plugin is the owner, and it is always active.
 *  - only WPOptimizing's own code was on the stack. Our autoload analyzer de-autoloading a
 *    third-party option was recorded as wpoptimizing/certain, and the dedup then kept the
 *    real owner from ever being captured. Our frames are skipped, so a plugin calling into
 *    us is still found further out.
 *
 * @param int $limit Max frames to inspect.
 * @return string|null Slug, '' (unknown) or null (do not record).
 */
function apg_wpopt_rtmap_slug_from_backtrace( $limit = 25 ) {
	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
	return apg_wpopt_rtmap_owner_from_frames( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, $limit ) );
}

/**
 * The owner of the innermost frame that is not core, a drop-in, the database class or ours.
 *
 * @param array      $trace Backtrace frames, innermost first.
 * @param array|null $dirs  {skip: string[], mu: string, own: string}; null = this site's.
 * @return string|null See apg_wpopt_rtmap_slug_from_backtrace().
 */
function apg_wpopt_rtmap_owner_from_frames( array $trace, $dirs = null ) {
	if ( ! is_array( $dirs ) ) {
		$dirs = array(
			'skip' => array(
				trailingslashit( ABSPATH . WPINC ),
				trailingslashit( ABSPATH . 'wp-admin' ),
			),
			'mu'   => defined( 'WPMU_PLUGIN_DIR' ) ? trailingslashit( WPMU_PLUGIN_DIR ) : '',
			'own'  => defined( 'WPOPTIMIZING_FILE' ) ? trailingslashit( dirname( WPOPTIMIZING_FILE ) ) : '',
		);
	}
	$skip    = (array) ( $dirs['skip'] ?? array() );
	$mu      = (string) ( $dirs['mu'] ?? '' );
	$own     = (string) ( $dirs['own'] ?? '' );
	$ours    = false;
	$dropins = apg_wpopt_rtmap_dropin_files();
	foreach ( $trace as $frame ) {
		if ( apg_wpopt_rtmap_is_wpdb_frame( $frame ) ) {
			continue;
		}
		$file = $frame['file'] ?? '';
		if ( isset( $dropins[ $file ] ) ) {
			continue;
		}
		if ( '' === $file ) {
			// Frame has no file (e.g. internal function or closure): try class reflection.
			$class = $frame['class'] ?? '';
			if ( '' !== $class ) {
				try {
					$file = ( new ReflectionClass( $class ) )->getFileName() ?: '';
				} catch ( ReflectionException $e ) { // A catch without a variable is PHP 8 only: on 7.4 it is a parse error in an mu-plugin.
					$file = '';
				}
			}
		}
		if ( '' === $file || __FILE__ === $file ) {
			continue;
		}
		if ( '' !== $own && 0 === strncmp( $file, $own, strlen( $own ) ) ) {
			$ours = true;
			continue;
		}
		if ( '' !== $mu && 0 === strncmp( $file, $mu, strlen( $mu ) ) ) {
			return null;
		}
		foreach ( $skip as $s ) {
			if ( 0 === strncmp( $file, $s, strlen( $s ) ) ) {
				continue 2;
			}
		}
		$slug = apg_wpopt_rtmap_slug_from_file( $file );
		if ( '' !== $slug ) {
			return $slug;
		}
	}
	return $ours ? null : '';
}

/**
 * Resolve the slug and confidence for a meta key write.
 * Tries backtrace first (certain); falls back to prefix heuristic (heuristic).
 * Returns ['', ''] when attribution is impossible — caller should skip the write.
 *
 * @param string $meta_key Normalised meta key (already trimmed, not empty).
 * @return array{0: string, 1: string} [ slug, confianza ] or [ '', '' ].
 */
function apg_wpopt_rtmap_resolve_meta_slug( $meta_key ) {
	$slug = apg_wpopt_rtmap_slug_from_backtrace( 30 );
	if ( null === $slug ) {
		return array( '', '' ); // A mu-plugin's or our own write: nothing to record.
	}
	if ( '' !== $slug ) {
		return array( $slug, 'certain' );
	}
	// Fallback: match the bare key (strip leading underscores) against plugin slugs.
	$bare = ltrim( (string) $meta_key, '_' );
	$slug = apg_wpopt_rtmap_attr_by_prefix( $bare );
	if ( '' !== $slug ) {
		return array( $slug, 'heuristic' );
	}
	// Try just the leading segment: "wc" from "wc_product_type".
	$parts = explode( '_', $bare, 2 );
	if ( isset( $parts[0] ) && strlen( $parts[0] ) >= 2 ) {
		$slug = apg_wpopt_rtmap_attr_by_prefix( $parts[0] );
		if ( '' !== $slug ) {
			return array( $slug, 'heuristic' );
		}
	}
	return array( '', '' );
}

/**
 * Resolve a callback to a slug via Reflection.
 *
 * @param callable $cb Callback.
 * @return string Slug or ''.
 */
function apg_wpopt_rtmap_slug_from_callback( $cb ) {
	try {
		if ( is_array( $cb ) ) {
			$ref = new ReflectionMethod( $cb[0], $cb[1] );
		} elseif ( is_string( $cb ) && strpos( $cb, '::' ) !== false ) {
			list( $cls, $meth ) = explode( '::', $cb, 2 );
			$ref                = new ReflectionMethod( $cls, $meth );
		} elseif ( is_object( $cb ) && ! ( $cb instanceof Closure ) ) {
			$ref = new ReflectionMethod( $cb, '__invoke' ); // Invokable object: ReflectionFunction would throw a TypeError.
		} else {
			$ref = new ReflectionFunction( $cb ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
		}
		return apg_wpopt_rtmap_slug_from_file( $ref->getFileName() ?: '' );
	} catch ( Throwable $e ) {
		return '';
	}
}

/**
 * Get the source file of a class via Reflection.
 *
 * @param string $class Class name.
 * @return string Absolute path or ''.
 */
function apg_wpopt_rtmap_file_from_class( $class ) {
	try {
		return ( new ReflectionClass( $class ) )->getFileName() ?: '';
	} catch ( ReflectionException $e ) {
		return '';
	}
}

/**
 * Attribute a key (role slug, REST namespace…) by matching against installed slugs.
 * Normalizes hyphens/underscores before comparing.
 *
 * @param string $key Key to attribute.
 * @return string Matching plugin slug or ''.
 */
function apg_wpopt_rtmap_attr_by_prefix( $key ) {
	return apg_wpopt_rtmap_prefix_owner( $key, array_unique( array_values( apg_wpopt_rtmap_file_map() ) ) );
}

/**
 * The slug a key starts with, hyphens and underscores ignored: an exact match first, then the
 * LONGEST prefix. The first hit used to win, so with WooCommerce and WooCommerce Subscriptions
 * installed `woocommerce_subscriptions_*` went to whichever was listed first. Pure.
 *
 * @param string   $key   Key.
 * @param string[] $slugs Installed slugs.
 * @return string Slug or ''.
 */
function apg_wpopt_rtmap_prefix_owner( $key, array $slugs ) {
	$key      = strtolower( (string) $key );
	$norm_key = str_replace( array( '-', '_' ), '', $key );
	$best     = '';
	$long     = 0;
	foreach ( $slugs as $slug ) {
		$slug = (string) $slug;
		if ( strtolower( $slug ) === $key ) {
			return $slug; // Exact match.
		}
		$norm_slug = str_replace( array( '-', '_' ), '', strtolower( $slug ) );
		$len       = strlen( $norm_slug );
		if ( $len > $long && 0 === strncmp( $norm_key, $norm_slug, $len ) ) {
			$best = $slug;
			$long = $len;
		}
	}
	return $best;
}
