<?php
/**
 * Background data work: the CSV cache, the unified datasets, the knowledge-base sync and the
 * odds and ends only those paths need.
 *
 * All of it runs from cron, from WP-CLI or from an admin screen, and all of it is bounded by
 * wpoptimizing_request_needs_engine(). None of it belongs in the bytes a visitor compiles.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether the current user may install/refresh OUR drop-ins. Mirrors the drop-in UI gate
 * (WPOPT_Admin_UI::can_manage_dropin): network-admins on multisite, the plugin capability on
 * single-site. Used by the drop-in auto-refresh so a delegated manager who can act in the UI
 * also triggers the refresh — otherwise, gating the write on manage_options only meant a stale
 * own drop-in lingered until a full administrator loaded wp-admin.
 *
 * @return bool
 */
function wpoptimizing_user_can_manage_dropin() {
	if ( is_multisite() ) {
		return current_user_can( 'manage_network_options' );
	}
	return current_user_can( wpoptimizing_cap() );
}


/**
 * Return all active plugin basenames for the current context, including
 * network-active plugins on multisite. `active_plugins` is a numeric array of
 * basenames; `active_sitewide_plugins` is keyed by basename — this merges both.
 *
 * @return string[] Plugin basenames (e.g. "akismet/akismet.php").
 */
function wpoptimizing_active_plugin_files() {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );
		$active  = array_merge( $active, array_keys( $network ) );
	}
	return array_values( array_unique( $active ) );
}


/**
 * Does a database table belong to the CURRENT blog (or to the shared globals)?
 *
 * On multisite every blog shares one database, so `SHOW TABLES` lists every site's tables.
 * This is the single rule for "ours": per-blog tables (prefix + "<id>_") only match their
 * own blog, tables with no blog segment belong to the main site, and a foreign table from
 * another application sharing the database is left alone. Single-site always returns true.
 *
 * It gates both what the UI lists AND what the actions accept, so a forged POST from one
 * sub-site can never OPTIMIZE or ALTER another sub-site's tables.
 *
 * @since 0.1.0
 * @param string $table Full table name.
 * @return bool
 */
function wpoptimizing_table_belongs_to_current_blog( $table ) {
	if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
		return true;
	}
	global $wpdb;
	$table = (string) $table;
	$base  = (string) ( ! empty( $wpdb->base_prefix ) ? $wpdb->base_prefix : $wpdb->prefix );
	if ( '' === $base || 0 !== strpos( $table, $base ) ) {
		return true; // Foreign table (another app sharing the DB): not ours to hide or touch.
	}
	$current = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
	$rest    = substr( $table, strlen( $base ) );
	if ( preg_match( '/^([0-9]+)_/', $rest, $m ) ) {
		return (int) $m[1] === $current; // Per-blog table: only ours.
	}
	return 1 === $current; // No per-blog id segment -> main site / shared globals.
}

/**
 * Quote a trusted SQL identifier (table/column name) as `name`.
 *
 * Single source of truth shared by WPOPT_Admin_UI and WPOPT_General_Cleanup (their
 * quote_identifier() methods delegate here) so the sanitising regex lives once.
 * Strips everything except [A-Za-z0-9_]; returns '' for an empty result.
 *
 * @param string $identifier Identifier to quote.
 * @return string Backtick-quoted identifier, or '' when nothing usable remains.
 */
function wpoptimizing_quote_identifier( $identifier ) {
	$identifier = preg_replace( '/[^A-Za-z0-9_]/', '', (string) $identifier );
	if ( '' === $identifier ) {
		return '';
	}
	return '`' . $identifier . '`';
}

/**
 * Read encrypted CSV cache (decrypting on the fly).
 *
 * @param string $which 'db' or 'files'.
 * @return string|WP_Error
 */
function wpoptimizing_read_csv_cache( $which ) {
	$path = ( 'files' === $which ) ? WPOPT_PROTECTED_FILES : WPOPT_PROTECTED_DB;
	if ( ! file_exists( $path ) ) {
		return new WP_Error( 'cache_missing', 'Cache not found.' );
	}

	$payload = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
	if ( false === $payload || '' === $payload ) {
		return new WP_Error( 'cache_empty', 'Cache empty.' );
	}

	$token = wpoptimizing_fetch_remote_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}

	return wpoptimizing_decrypt_csv( $payload, $token );
}

/**
 * Get cached CSV (refreshing daily if needed).
 *
 * @param string $which 'db' or 'files'.
 * @return string|WP_Error
 */
function wpoptimizing_get_cached_csv( $which ) {
	$which = ( 'files' === $which ) ? 'files' : 'db';
	$path  = ( 'files' === $which ) ? WPOPT_PROTECTED_FILES : WPOPT_PROTECTED_DB;

	// No license gate: the attribution dataset is part of the free experience.
	$stale = true;
	if ( file_exists( $path ) ) {
		$mtime = filemtime( $path );
		if ( false !== $mtime && ( time() - $mtime ) < DAY_IN_SECONDS ) {
			$stale = false;
		}
	}

	if ( $stale ) {
		wpoptimizing_refresh_csv_cache();
	}

	$csv = wpoptimizing_read_csv_cache( $which );
	// A decrypt failure usually means the token rotated since the cache was
	// written; refresh once and re-read with the current token.
	if ( is_wp_error( $csv ) ) {
		wpoptimizing_refresh_csv_cache();
		$csv = wpoptimizing_read_csv_cache( $which );
	}
	return is_wp_error( $csv ) ? '' : $csv;
}

/**
 * An extension name as plain text. wordpress.org returns some titles with markup inside
 * («<span>WPML Multilingual &amp; Multicurrency</span> for WooCommerce»), escaped or not, and
 * whoever paints the name escapes it again, so the tags showed up as text in the Extension
 * column. Entities are decoded first so an escaped tag is stripped too; a plugin header can
 * carry markup the same way.
 *
 * @param string $name Raw name.
 * @return string
 */
function wpoptimizing_plain_name( $name ) {
	$name = html_entity_decode( (string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$name = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $name ) : strip_tags( $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
	return trim( (string) preg_replace( '/\s+/u', ' ', $name ) );
}

/**
 * Local copy of the `nombres` layer, or '' when it has not arrived.
 *
 * @return string
 */
function wpoptimizing_kb_names_path() {
	if ( ! defined( 'WPOPT_DIR' ) ) {
		return '';
	}
	$path = trailingslashit( WPOPT_DIR ) . 'wp-nombres-distrib.csv';
	return is_readable( $path ) ? $path : '';
}

/**
 * Names from the `nombres` layer for some cache keys (`slug` for a plugin, `theme:slug`).
 *
 * Streams the file once and stops when every key is found: the layer is ~130,000 rows and only
 * a handful are ever needed, so it is never loaded whole. Rows of another width are skipped.
 *
 * @param string[] $keys Cache keys.
 * @return array<string,string> key => plain-text name, only for the ones found.
 */
function wpoptimizing_kb_names_lookup( array $keys ) {
	$path = wpoptimizing_kb_names_path();
	$want = array_fill_keys( array_map( 'strtolower', $keys ), true );
	$out  = array();
	if ( '' === $path || empty( $want ) ) {
		return $out;
	}
	$fh = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $fh ) {
		return $out;
	}
	$header = fgetcsv( $fh, 0, "\t", '"', '\\' );
	if ( is_array( $header ) && array( 'slug', 'tipo', 'nombre' ) === array_map( 'trim', $header ) ) {
		while ( ( $row = fgetcsv( $fh, 0, "\t", '"', '\\' ) ) !== false ) {
			if ( ! is_array( $row ) || 3 !== count( $row ) ) {
				continue;
			}
			$slug = strtolower( trim( (string) $row[0] ) );
			$key  = ( 'theme' === strtolower( trim( (string) $row[1] ) ) ) ? 'theme:' . $slug : $slug;
			if ( isset( $want[ $key ] ) && ! isset( $out[ $key ] ) ) {
				$nombre = wpoptimizing_plain_name( (string) $row[2] );
				if ( '' !== $nombre ) {
					$out[ $key ] = $nombre;
					if ( count( $out ) === count( $want ) ) {
						break;
					}
				}
			}
		}
	}
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $out;
}

/**
 * Is a cached name worth asking wordpress.org again?
 *
 * A name found stays. «Not in the directory» is asked again after a week (a slug can be
 * published later), and a failed request after an hour: before, both were kept forever, and one
 * timeout left `akismet` as a bare slug for good (audit of 26/10/2026).
 *
 * @param mixed $entry Cache entry: {name, installs, ts, error} or a legacy string.
 * @return bool
 */
function wpoptimizing_ext_name_stale( $entry ) {
	if ( ! is_array( $entry ) ) {
		return '' === (string) $entry;
	}
	if ( '' !== (string) ( $entry['name'] ?? '' ) ) {
		return false;
	}
	// A `nombres` layer newer than the «not known» answers them again: it may know it now.
	$capa = wpoptimizing_kb_names_path();
	if ( '' !== $capa && (int) @filemtime( $capa ) > (int) ( $entry['ts'] ?? 0 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}
	$edad = time() - (int) ( $entry['ts'] ?? 0 );
	return ! empty( $entry['error'] ) ? $edad > HOUR_IN_SECONDS : $edad > WEEK_IN_SECONDS;
}

/**
 * Whether a lowercased value can be an extension slug worth asking for its name.
 *
 * A library label with spaces («Action Scheduler») or the `unknown` placeholder stayed in the
 * cache as a name nobody knows and went back to the wordpress.org queue every week (27/10/2026).
 *
 * @param string $slug Lowercased slug.
 * @return bool
 */
function wpoptimizing_ext_slug_askable( $slug ) {
	$slug = (string) $slug;
	return '' !== $slug && 'unknown' !== $slug && 1 === preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $slug );
}

/**
 * Human display name for a plugin/theme slug that is NOT installed locally, looked up once
 * from the WordPress.org directory and cached in the local KB. Returns '' when unknown
 * (uncached, private, or not on .org) — the caller then shows the slug.
 *
 * Performance contract: NEVER hits the network on this (render) path. A cache miss only
 * buffers the slug; a background cron resolves it, so the name appears on a later view.
 *
 * Names come from the directory and, for what it does not know (paid, withdrawn, themes), from
 * the `nombres` layer of the knowledge base.
 *
 * @param string $slug Plugin/theme slug.
 * @param string $tipo 'plugin' or 'theme'.
 * @return string Cached display name, or ''.
 */
function wpoptimizing_ext_name( $slug, $tipo = 'plugin' ) {
	$slug = strtolower( trim( (string) $slug ) );
	if ( ! wpoptimizing_ext_slug_askable( $slug ) ) {
		return '';
	}
	// A theme has its own key: the directory and the `nombres` layer know the plugin `amp` and
	// the theme `amp` as two things (272 slugs of the corpus are both).
	if ( 'theme' === $tipo ) {
		$slug = 'theme:' . $slug;
	}
	wpoptimizing_ext_names_backfill_if_layer_newer();
	$cache = (array) get_option( 'wpoptimizing_ext_names', array() );
	if ( array_key_exists( $slug, $cache ) && ! wpoptimizing_ext_name_stale( $cache[ $slug ] ) ) {
		$cached = (string) ( is_array( $cache[ $slug ] ) ? ( $cache[ $slug ]['name'] ?? '' ) : $cache[ $slug ] );

		/*
		 * SE DEVUELVE EL NOMBRE EN TEXTO, NO EN HTML. wp.org devuelve el titulo YA escapado
		 * («WPML Multilingual &amp; Multicurrency for WooCommerce»), y quien pinta esto le
		 * vuelve a aplicar esc_html(), asi que al comprador le salia el «&amp;» a la vista en
		 * medio del nombre del plugin. Visto en produccion.
		 *
		 * Se decodifica AQUI, al leer, y no solo al guardar, porque los cacheados antes de este
		 * arreglo siguen teniendo las entidades dentro y no se van a reescribir solos.
		 */
		return wpoptimizing_plain_name( $cached );
	}
	// Cache miss: buffer for the shutdown flush (one option write per request, not per slug).
	if ( ! isset( $GLOBALS['wpopt_ext_name_buf'] ) ) {
		$GLOBALS['wpopt_ext_name_buf'] = array();
		add_action( 'shutdown', 'wpoptimizing_ext_name_flush', 0 );
	}
	$GLOBALS['wpopt_ext_name_buf'][ $slug ] = true;
	return '';
}

/**
 * Flush the buffered unknown slugs into the resolution queue (once, on shutdown) and make
 * sure a background resolver is scheduled. No network here.
 *
 * @return void
 */
/**
 * Resolve now, and not on the next cron run, the names of slugs a person has just attributed.
 *
 * The name was asked only when the table was drawn, and resolved by the cron 30 s later: the
 * first time a rule from the attribution assistant showed up, the Extension column always
 * carried the slug («Plugin: akismet»), and on a site whose cron waits for visits it stayed
 * that way for a while (soporte.artprojectgroup.es, 26/10/2026). Only slugs not cached yet are
 * asked, and at most `$max`, so a bulk accept costs a few requests to WordPress.org at most.
 *
 * @param string[] $slugs Plugin slugs.
 * @param int      $max   Most slugs asked in this request.
 * @return void
 */
function wpoptimizing_ext_names_prefetch( array $slugs, $max = 10 ) {
	$cache  = (array) get_option( 'wpoptimizing_ext_names', array() );
	$nuevos = array();
	foreach ( $slugs as $slug ) {
		$slug = strtolower( trim( (string) $slug ) );
		if ( wpoptimizing_ext_slug_askable( $slug ) && ( ! array_key_exists( $slug, $cache ) || wpoptimizing_ext_name_stale( $cache[ $slug ] ) ) ) {
			$nuevos[ $slug ] = true;
		}
	}
	$nuevos = array_slice( array_keys( $nuevos ), 0, max( 0, (int) $max ) );
	if ( empty( $nuevos ) ) {
		return;
	}
	$queue = array_values( array_diff( (array) get_option( 'wpoptimizing_ext_names_q', array() ), $nuevos ) );
	update_option( 'wpoptimizing_ext_names_q', array_merge( $nuevos, $queue ), false );
	$lote = static function () use ( $nuevos ) {
		return count( $nuevos );
	};
	add_filter( 'wpoptimizing_ext_names_batch', $lote );
	wpoptimizing_resolve_ext_names();
	remove_filter( 'wpoptimizing_ext_names_batch', $lote );
}

/**
 * Names the cache holds as «not known», looked up in the `nombres` layer that has just arrived.
 *
 * The resolver may have answered «not known» seconds before the layer was downloaded in the same
 * cron run, and then the slug stayed on screen until a later view queued it again and the cron
 * after that resolved it: on soporte.artprojectgroup.es the theme Avada and SeedProd Pro still
 * showed their slugs after the layer had arrived (27/10/2026). Local file only, no network.
 *
 * @return int Names filled.
 */
function wpoptimizing_ext_names_backfill_from_kb() {
	$cache  = (array) get_option( 'wpoptimizing_ext_names', array() );
	$vacios = array();
	foreach ( $cache as $k => $e ) {
		if ( is_array( $e ) && '' === (string) ( $e['name'] ?? '' ) && wpoptimizing_ext_slug_askable( preg_replace( '/^theme:/', '', (string) $k ) ) ) {
			$vacios[] = (string) $k;
		}
	}
	if ( empty( $vacios ) ) {
		return 0;
	}
	$hallados = wpoptimizing_kb_names_lookup( $vacios );
	if ( empty( $hallados ) ) {
		return 0;
	}
	wp_cache_delete( 'wpoptimizing_ext_names', 'options' );
	$cache = (array) get_option( 'wpoptimizing_ext_names', array() );
	foreach ( $hallados as $k => $n ) {
		$cache[ $k ] = array_merge(
			is_array( $cache[ $k ] ?? null ) ? $cache[ $k ] : array(),
			array(
				'name'  => $n,
				'ts'    => time(),
				'error' => false,
			)
		);
	}
	update_option( 'wpoptimizing_ext_names', $cache, false );
	return count( $hallados );
}

/**
 * Backfill once per `nombres` layer on disk, whenever it is newer than the last backfill.
 *
 * Tied only to the download, the backfill never ran where the layer had arrived before the
 * version that brings it was installed: the layer did not change, so it was not downloaded
 * again, and the names waited for a cron that site only runs on visits. On
 * soporte.artprojectgroup.es Avada and SeedProd Pro kept their slugs after the update
 * (27/10/2026). Costs a stat of the layer per name asked (PHP caches it); the file is read once
 * per layer.
 *
 * @return int Names filled.
 */
function wpoptimizing_ext_names_backfill_if_layer_newer() {
	static $visto = null;
	$capa         = wpoptimizing_kb_names_path();
	$mtime        = '' !== $capa ? (int) @filemtime( $capa ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	if ( $visto === $mtime ) {
		return 0;
	}
	$visto = $mtime;
	if ( $mtime <= 0 || $mtime <= (int) get_option( 'wpoptimizing_ext_names_kb_mtime', 0 ) ) {
		return 0;
	}
	// Recorded first: a request that dies halfway does not make every later one read the file.
	update_option( 'wpoptimizing_ext_names_kb_mtime', $mtime, false );
	return wpoptimizing_ext_names_backfill_from_kb();
}

/**
 * Instalaciones activas de una extensión según wordpress.org, o 0 si no se sabe.
 *
 * Sólo para DESEMPATAR el orden en que se enseñan varios dueños propuestos para la misma
 * clave. Nunca para elegir dueño ni para subir la confianza: ver el comentario en el
 * resolutor de arriba, y `action_scheduler_run_queue` como contraejemplo.
 *
 * @param string $slug Slug de la extensión.
 * @return int
 */
function wpoptimizing_ext_installs( $slug ) {
	$slug  = strtolower( trim( (string) $slug ) );
	$cache = (array) get_option( 'wpoptimizing_ext_names', array() );
	$fila  = $cache[ $slug ] ?? null;

	return is_array( $fila ) ? max( 0, (int) ( $fila['installs'] ?? 0 ) ) : 0;
}

function wpoptimizing_ext_name_flush() {
	if ( empty( $GLOBALS['wpopt_ext_name_buf'] ) || ! is_array( $GLOBALS['wpopt_ext_name_buf'] ) ) {
		return;
	}
	$queue = (array) get_option( 'wpoptimizing_ext_names_q', array() );
	$queue = array_values( array_unique( array_merge( $queue, array_keys( $GLOBALS['wpopt_ext_name_buf'] ) ) ) );
	$queue = array_slice( $queue, 0, 1000 ); // Bound the queue.
	update_option( 'wpoptimizing_ext_names_q', $queue, false );
	if ( ! wp_next_scheduled( 'wpoptimizing_resolve_ext_names' ) ) {
		wp_schedule_single_event( time() + 30, 'wpoptimizing_resolve_ext_names' );
	}
}

/**
 * The name of a plugin closed on wordpress.org, read from the directory's own answer.
 *
 * plugins_api() reports a closed plugin as a WP_Error with the message «closed» and nothing else,
 * though the answer it received still names it. Asked again here, once, straight to the same
 * endpoint and with the same fields. The directory answers it with a 404.
 *
 * @param string $slug Plugin slug.
 * @return string Name in plain text, or '' when it cannot be read.
 */
function wpoptimizing_ext_closed_name( $slug ) {
	$url = add_query_arg(
		array(
			'action'  => 'plugin_information',
			'request' => array(
				'slug'   => (string) $slug,
				'fields' => array( 'sections' => 0 ),
			),
		),
		'https://api.wordpress.org/plugins/info/1.2/'
	);
	// It comes with a 404 and the name in the body: the body is what counts, and only when it
	// says «closed», so a missing plugin never borrows a name.
	$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
	if ( is_wp_error( $resp ) ) {
		return '';
	}
	$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $data ) || 'closed' !== ( $data['error'] ?? '' ) || empty( $data['name'] ) || ! is_string( $data['name'] ) ) {
		return '';
	}
	return wpoptimizing_plain_name( html_entity_decode( $data['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
}

/**
 * Background worker: resolve a batch of queued slugs to their WordPress.org names and cache
 * them. Private/unknown slugs are cached with an empty name (negative cache) so we do not
 * re-query them every run. Reschedules itself while the queue is non-empty.
 *
 * @return void
 */
function wpoptimizing_resolve_ext_names() {
	$queue = (array) get_option( 'wpoptimizing_ext_names_q', array() );
	if ( empty( $queue ) ) {
		return;
	}
	if ( ! function_exists( 'plugins_api' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	}
	$cache  = (array) get_option( 'wpoptimizing_ext_names', array() );
	$batch  = array_splice( $queue, 0, (int) apply_filters( 'wpoptimizing_ext_names_batch', 20 ) );
	$nuevos = array();
	/*
	 * A TIME BUDGET, AND ONLY WHAT WAS ASKED LEAVES THE QUEUE. Twenty lookups take 15-20 s (a
	 * closed plugin costs two), and WP-Cron runs this in the same request as the preload and
	 * every other due task: PHP cut the request before the end, where everything was saved, and
	 * the same 38 slugs waited a whole night in the Review tab of artprojectgroup.es (11/11/2026).
	 */
	$hasta  = microtime( true ) + (float) apply_filters( 'wpoptimizing_ext_names_seconds', 8 );
	$hechos = array();
	foreach ( $batch as $slug ) {
		if ( $hechos && microtime( true ) > $hasta ) {
			break;
		}
		$hechos[] = $slug;
		$slug     = strtolower( trim( (string) $slug ) );
		if ( ! wpoptimizing_ext_slug_askable( preg_replace( '/^theme:/', '', $slug ) ) || ( array_key_exists( $slug, $cache ) && ! wpoptimizing_ext_name_stale( $cache[ $slug ] ) ) ) {
			continue;
		}
		$name     = '';
		$installs = 0;
		$fallo    = false;
		// Themes are not in the plugin directory: only the `nombres` layer, below, names them.
		if ( 0 !== strpos( $slug, 'theme:' ) && function_exists( 'plugins_api' ) ) {
			$info = plugins_api(
				'plugin_information',
				array(
					'slug'   => $slug,
					'fields' => array(
						'sections'          => false,
						'short_description' => false,
						'icons'             => false,
					),
				)
			);
			// A failure of the request is not «it does not exist»: it is tried again in an hour,
			// not in a week (wpoptimizing_ext_name_stale()). wordpress.org says «Plugin not found».
			$fallo = is_wp_error( $info ) && false === stripos( $info->get_error_message(), 'not found' );
			// A plugin CLOSED on wordpress.org: its answer still carries the name, but plugins_api()
			// turns any answer with an «error» into a WP_Error and drops it. Every closed plugin
			// kept its slug on screen and went back to the queue every hour: BruteProtect,
			// Slimpack, TracPress… in the Review tab of artprojectgroup.es (10/11/2026).
			if ( is_wp_error( $info ) && 'closed' === strtolower( trim( $info->get_error_message() ) ) ) {
				$closed = wpoptimizing_ext_closed_name( $slug );
				if ( '' !== $closed ) {
					$info  = (object) array( 'name' => $closed );
					$fallo = false;
				}
			}
			if ( ! is_wp_error( $info ) && ! empty( $info->name ) ) {
				// Se guarda ya decodificado para que lo nuevo entre limpio; lo viejo lo arregla
				// la decodificacion de wpoptimizing_ext_name() al leer.
				$name = html_entity_decode( (string) $info->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			/*
			 * LAS INSTALACIONES ACTIVAS VIENEN EN ESTA MISMA RESPUESTA Y SE TIRABAN.
			 *
			 * No sirven para decidir de quién es una clave —la popularidad no es propiedad, y
			 * `action_scheduler_run_queue` la reclaman 24 plugins cuyo más instalado es
			 * WooCommerce cuando la respuesta correcta es Action Scheduler—, pero sí para
			 * ORDENAR a varios candidatos que la base de conocimiento ya propone para la misma
			 * clave. Entre un plugin y su copia reempaquetada, el original tiene órdenes de
			 * magnitud más instalaciones, y enseñarlo primero es la diferencia entre que el
			 * usuario entienda la fila y que desconfíe de la pantalla entera.
			 *
			 * Cuesta cero: la petición ya se hacía para el nombre.
			 */
			if ( ! is_wp_error( $info ) && isset( $info->active_installs ) ) {
				$installs = max( 0, (int) $info->active_installs );
			}
		}
		$nuevos[ $slug ] = array(
			'name'     => wpoptimizing_plain_name( (string) $name ),
			'installs' => $installs,
			'ts'       => time(),
			'error'    => $fallo,
		);
	}
	// What wordpress.org did not name (paid, withdrawn, themes), from the `nombres` layer: one
	// pass over the file for the whole batch. A label only, never evidence of ownership.
	$faltan = array();
	foreach ( $nuevos as $k => $fila ) {
		if ( '' === $fila['name'] ) {
			$faltan[] = $k;
		}
	}
	if ( ! empty( $faltan ) ) {
		foreach ( wpoptimizing_kb_names_lookup( $faltan ) as $k => $n ) {
			$nuevos[ $k ]['name']  = $n;
			$nuevos[ $k ]['error'] = false;
		}
	}

	/*
	 * READ AGAIN BEFORE WRITING. The requests above take seconds, and meanwhile another request
	 * may have queued slugs or another run stored names: writing the copy read at the start
	 * dropped them (audit of 26/10/2026). Only what this run resolved is added, and only the
	 * slugs of this batch leave the queue.
	 */
	wp_cache_delete( 'wpoptimizing_ext_names', 'options' );
	wp_cache_delete( 'wpoptimizing_ext_names_q', 'options' );
	$cache = array_replace( (array) get_option( 'wpoptimizing_ext_names', array() ), $nuevos );
	$queue = array_values( array_diff( (array) get_option( 'wpoptimizing_ext_names_q', array() ), $hechos ) );
	// Labels queued before wpoptimizing_ext_slug_askable() existed leave the cache for good.
	foreach ( array_keys( $cache ) as $k ) {
		if ( ! wpoptimizing_ext_slug_askable( preg_replace( '/^theme:/', '', (string) $k ) ) ) {
			unset( $cache[ $k ] );
		}
	}
	// Keep the cache bounded (most-recent wins is fine; this is a convenience map).
	if ( count( $cache ) > 5000 ) {
		$cache = array_slice( $cache, -5000, null, true );
	}
	update_option( 'wpoptimizing_ext_names', $cache, false );
	update_option( 'wpoptimizing_ext_names_q', $queue, false );
	if ( ! empty( $queue ) && ! wp_next_scheduled( 'wpoptimizing_resolve_ext_names' ) ) {
		wp_schedule_single_event( time() + 30, 'wpoptimizing_resolve_ext_names' );
	}
}

/**
 * Generate unified CSVs from code scans only.
 *
 * @return void
 */
function wpoptimizing_generate_unified_files() {
	// The unified/asset CSVs exist ONLY to be uploaded to the shared KB. If the
	// site isn't contributing, never build them — there is no reason to write our
	// collection schema to disk on a non-participating install.
	if ( ! wpoptimizing_has_share_consent() ) {
		return;
	}
	if ( get_transient( 'wpoptimizing_unified_lock' ) ) {
		return;
	}
	set_transient( 'wpoptimizing_unified_lock', 1, 10 * MINUTE_IN_SECONDS );

	// Reset unified files.
	if ( file_exists( WPOPT_CSV_UNIFIED_DB ) ) {
		wp_delete_file( WPOPT_CSV_UNIFIED_DB );
	}
	if ( file_exists( WPOPT_CSV_UNIFIED_FILES ) ) {
		wp_delete_file( WPOPT_CSV_UNIFIED_FILES );
	}
	// Reset the asset registry: the scanner re-seeds the static handle->owner rows;
	// the runtime feeder re-enriches them (src + deps) between scans.
	if ( file_exists( WPOPT_CSV_ASSETS ) ) {
		wp_delete_file( WPOPT_CSV_ASSETS );
	}

	// Scan code to rebuild unified datasets.
	( new WPOPT_Code_Scanner( WP_PLUGIN_DIR, 'plugin' ) )->run();
	if ( defined( 'WPMU_PLUGIN_DIR' ) && is_dir( WPMU_PLUGIN_DIR ) ) {
		( new WPOPT_Code_Scanner( WPMU_PLUGIN_DIR, 'plugin' ) )->run();
	}
	( new WPOPT_Code_Scanner( get_theme_root(), 'theme' ) )->run();

	// Running the scanner here also (re)wrote the impact CSVs as plaintext; re-encrypt
	// them at rest so a sync doesn't leave our schema readable between scans.
	if ( function_exists( 'wpoptimizing_scan_encrypt_file' ) ) {
		wpoptimizing_scan_encrypt_file( WPOPT_CSV_DB );
		wpoptimizing_scan_encrypt_file( WPOPT_CSV_FS_CODE );
	}

	delete_transient( 'wpoptimizing_unified_lock' );
}

/**
 * How long to wait between periodic "turn on data sharing" reminders, in seconds.
 * Filterable via wpoptimizing_share_nudge_interval_days (default 30 days).
 *
 * @return int
 */
function wpoptimizing_share_nudge_interval() {
	$days = (int) apply_filters( 'wpoptimizing_share_nudge_interval_days', 30 );
	return max( 1, $days ) * DAY_IN_SECONDS;
}

/**
 * Run a full knowledge-base sync on demand: upload this site's contributions (only
 * if sharing is on) and then refresh the local copy of the shared dataset. Used by
 * the manual "Sync KB now" button to verify the gateway end-to-end without waiting
 * for the daily crons.
 *
 * @return array{consent:bool,upload:array,downloaded:bool} Outcome report.
 */
function wpoptimizing_sync_kb_now() {
	$report = array(
		'consent'       => wpoptimizing_has_share_consent(),
		'upload'        => array(),
		'downloaded'    => false,
		'download_code' => 0,
	);

	if ( $report['consent'] ) {
		// Make sure there is something fresh to send, then upload unified + assets
		// + autoload contributions.
		if ( function_exists( 'wpoptimizing_generate_unified_files' ) ) {
			wpoptimizing_generate_unified_files();
		}
		$report['upload'] = (array) wpoptimizing_upload_unified_files( true );
		if ( function_exists( 'wpoptimizing_upload_autoload_dataset' ) ) {
			// Report the autoload result alongside the other layers, in the same shape (HTTP
			// code | 'empty' | error text). Discarding it meant "Sync now" said "synced" even
			// when the gateway 400'd the autoload upload.
			$al = wpoptimizing_upload_autoload_dataset( true );
			if ( is_wp_error( $al ) ) {
				$data                         = $al->get_error_data();
				$report['upload']['autoload'] = ( is_array( $data ) && isset( $data['status'] ) )
					? (int) $data['status']
					: $al->get_error_message();
			} else {
				$report['upload']['autoload'] = ( true === $al ) ? 200 : 'empty';
			}
		}
	}

	// Always refresh the local copy of the shared dataset (download is license-free).
	if ( function_exists( 'wpoptimizing_refresh_csv_cache' ) ) {
		wpoptimizing_refresh_csv_cache();
	}
	// …and EVERY other layer (manifest, runtime, autoload, assets, rules). Refreshing only
	// db/files here was how the newer layers ended up depending on nothing but their own lazy
	// daily TTL: "Sync now" did not actually sync them.
	if ( function_exists( 'wpoptimizing_refresh_kb_layers' ) ) {
		wpoptimizing_refresh_kb_layers( true ); // Asked for now: past the daily spacing.
	}
	/*
	 * QUÉ BAJÓ Y QUÉ NO, CAPA POR CAPA, en lugar de sondear una sola.
	 *
	 * Esto hacía una petición más a `?file=db` y daba su código como «el» resultado de la
	 * descarga. Con eso, «Download: OK» salía igual de verde con seis capas de siete caídas,
	 * y durante meses la que faltaba era `manifest` —la lista blanca de extensiones
	 * conocidas— sin que el mensaje lo insinuara. La sonda, además, era una llamada de red
	 * extra para contestar menos que lo que el propio bucle de descarga acaba de anotar.
	 *
	 * Ahora se lee el registro que escribe wpoptimizing_download_kb_file(): se nombran las
	 * capas que no llegaron, con su motivo, y se callan las que sí.
	 */
	$report['layers'] = array();
	if ( function_exists( 'wpoptimizing_kb_layers' ) && function_exists( 'wpoptimizing_kb_download_outcome' ) ) {
		foreach ( wpoptimizing_kb_layers() as $kb_file => $kb_key ) {
			$ultimo                      = wpoptimizing_kb_download_outcome( $kb_key );
			$report['layers'][ $kb_key ] = (string) $ultimo['result'];
		}
	}
	$fallidas             = array_filter(
		$report['layers'],
		static function ( $resultado ) {
			return 'ok' !== $resultado;
		}
	);
	$report['downloaded'] = ! empty( $report['layers'] ) && empty( $fallidas );
	// Se conserva por compatibilidad con quien lo leyera: 200 cuando todas llegaron.
	$report['download_code'] = $report['downloaded'] ? 200 : 0;

	return $report;
}

/**
 * Upload this site's autoload contribution dataset to the central aggregator.
 *
 * Mirrors wpoptimizing_upload_unified_files(): a public challenge/response
 * handshake (no license required) followed by a CSV POST. Runs on every install
 * so the central knowledge base learns which options are commonly read but not
 * autoloaded (real "should autoload" candidates) and which are common orphans —
 * exactly what feeds the rest of the plugin network. Only option names, sizes
 * and read frequencies are sent; option values never leave the site. No-op when
 * there is not enough measured data yet.
 *
 * Goes through the same gate as the unified layers — back-off, daily cap, minimum spacing —
 * which it used to skip entirely: a failing autoload upload retried on every run, and one whose
 * data kept changing was sent on every run too.
 *
 * @param bool $manual True for a sync the user asked for (see wpoptimizing_upload_gate()).
 * @return true|WP_Error|null True on a 2xx upload, WP_Error on a network/HTTP failure or when
 *                            the gate holds it (code `wpoptimizing_upload_deferred`, message
 *                            'backoff', 'budget' or 'wait'), null when there was nothing to send
 *                            (no consent, no data). The caller must NOT treat a failure as sent —
 *                            that is how a rejected upload (e.g. the gateway 400'ing an unread
 *                            row) went unnoticed and the contribution silently never arrived.
 */
function wpoptimizing_upload_autoload_dataset( $manual = false ) {
	// Opt-in gate: never upload without explicit consent.
	if ( ! wpoptimizing_has_share_consent() ) {
		return null;
	}
	if ( ! function_exists( 'wpoptimizing_autoload_build_contribution_csv' ) ) {
		return null;
	}
	$csv = wpoptimizing_autoload_build_contribution_csv();
	if ( '' === $csv ) {
		return null;
	}

	$key  = 'autoload';
	$gate = wpoptimizing_upload_gate( $key, $manual );
	if ( '' !== $gate ) {
		return new WP_Error( 'wpoptimizing_upload_deferred', $gate );
	}
	$hash = wpoptimizing_upload_body_hash( $csv, true );
	if ( wpoptimizing_upload_unchanged( $key, $hash, $manual ) ) {
		return new WP_Error( 'wpoptimizing_upload_deferred', 'unchanged' );
	}

	$site  = wpoptimizing_site_id();
	$token = wpoptimizing_get_data_token();

	$resp = wp_remote_post(
		wpoptimizing_gateway_url() . '?file=' . $key,
		array(
			'headers' => array(
				'Content-Type'  => 'text/csv; charset=utf-8',
				'Authorization' => 'Bearer ' . $token,
				'X-WPO-Site'    => $site,
			),
			'body'    => $csv,
			'timeout' => 30,
		)
	);

	wpoptimizing_upload_count( $key, ! is_wp_error( $resp ) );
	if ( is_wp_error( $resp ) ) {
		wpoptimizing_upload_record_attempt( $key, false );
		return $resp;
	}
	$code = (int) wp_remote_retrieve_response_code( $resp );
	if ( wpoptimizing_upload_busy( $key, $resp ) ) {
		return new WP_Error( 'wpoptimizing_upload_deferred', 'busy' );
	}
	// Classified like every other layer. Until now any 2xx was success, so a maintenance page
	// answering 200 counted as a stored contribution; and no failure ever armed the back-off.
	$outcome = wpoptimizing_upload_outcome(
		$code,
		(string) wp_remote_retrieve_header( $resp, 'content-type' ),
		substr( (string) wp_remote_retrieve_body( $resp ), 0, 512 )
	);
	if ( 'ok' === $outcome || 'failed' === $outcome || 'throttled' === $outcome ) {
		wpoptimizing_upload_record_attempt( $key, 'ok' === $outcome );
	}
	if ( 'ok' === $outcome ) {
		wpoptimizing_upload_remember( $key, $hash );
	}
	if ( 'ok' !== $outcome ) {
		return new WP_Error(
			'wpoptimizing_autoload_upload_failed',
			sprintf( 'Autoload upload rejected (HTTP %d): %s', $code, (string) wp_remote_retrieve_body( $resp ) ),
			array( 'status' => $code )
		);
	}
	return true;
}

/**
 * Daily unified job: generate + upload.
 *
 * @return void
 */
function wpoptimizing_unified_daily_job() {
	/**
	 * Filters whether WPOptimizing may contribute anonymous datasets (option/file
	 * names, sizes and frequencies — never values) to the shared network.
	 *
	 * Return false to fully opt this install out of all uploads while keeping every
	 * local analysis feature working.
	 *
	 * @since 0.1.0
	 * @param bool $enabled Default true.
	 */
	if ( ! apply_filters( 'wpoptimizing_dataset_contribution_enabled', true ) ) {
		return;
	}

	/**
	 * Fires before the daily dataset generation + upload job runs.
	 *
	 * @since 0.1.0
	 */
	do_action( 'wpoptimizing_before_dataset_upload' );

	wpoptimizing_generate_unified_files();
	wpoptimizing_upload_unified_files();
	wpoptimizing_upload_autoload_dataset();

	/**
	 * Fires after the daily dataset generation + upload job finishes.
	 *
	 * @since 0.1.0
	 */
	do_action( 'wpoptimizing_after_dataset_upload' );
}

/**
 * WHO serves this site's persistent object cache — us, another plugin, or nobody.
 *
 * The dashboard card used to ask "is the drop-in OURS?", which is not the question the user
 * is asking. On a site where LiteSpeed (or Redis Object Cache, or W3TC) owns
 * object-cache.php, the site HAS a working persistent object cache — and we told the user
 * it was "not enabled" and offered a button that leads to a page explaining we will not
 * touch it. A dead end, and a card that says something false about their site.
 *
 * We still never overwrite a foreign drop-in. We just stop pretending it is not there.
 *
 * @return array{active:bool,ours:bool,name:string}
 */
function wpoptimizing_object_cache_provider() {
	static $memo = null;
	if ( is_array( $memo ) ) {
		return $memo;
	}

	$dest   = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';
	$exists = file_exists( $dest );
	$ours   = wpoptimizing_object_cache_active();

	// wp_using_ext_object_cache() is the honest signal: a well-behaved drop-in that cannot
	// reach its backend turns it off (LiteSpeed's does; so does ours, since today). So this
	// says "there is a persistent object cache working here", whoever wrote it.
	$using = function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache();

	$name = '';
	if ( $exists && ! $ours ) {
		// The same reading as every other drop-in screen (dropin-owner.php): header name, line
		// endings normalised, and the owning plugin when it is installed.
		if ( function_exists( 'wpoptimizing_dropin_owner' ) ) {
			$name = (string) wpoptimizing_dropin_owner( $dest )['name'];
		}
		if ( '' === $name ) {
			$name = __( 'another plugin', 'wpoptimizing' );
		}
	}

	$memo = array(
		'active' => (bool) $using,
		'ours'   => (bool) $ours,
		'name'   => $name,
	);
	return $memo;
}

/**
 * Retention window (in months) for the performance-history timeline. 0 = keep indefinitely.
 * Default: 3 months.
 *
 * @return int
 */
function wpoptimizing_history_retention_months() {
	return max( 0, (int) get_option( 'wpoptimizing_history_retention', 3 ) );
}

/**
 * Prune the history timeline to the configured retention window (always keeps the pinned
 * baseline and the origin snapshot).
 *
 * @return void
 */
function wpoptimizing_history_prune_run() {
	$months = wpoptimizing_history_retention_months();
	if ( $months <= 0 || ! class_exists( 'WPOPT_Performance_Logger' ) ) {
		return;
	}
	$baseline_ts = (string) get_option( 'wpoptimizing_history_baseline_ts', '' );
	( new WPOPT_Performance_Logger() )->prune_history( $months, $baseline_ts );
}

/**
 * Execute scheduled General cleanup.
 *
 * @return void
 */
function wpoptimizing_run_general_cleanup_cron( $attempt = 0 ) {
	// A scheduled run (attempt 0) needs the schedule to be allowed, which is Pro; the retries
	// of a manual run that failed always go ahead.
	if ( 0 === (int) $attempt && ! in_array( (string) get_option( 'wpoptimizing_general_cleanup_schedule', 'manual' ), WPOPT_General_Cleanup::get_allowed_schedules(), true ) ) {
		return;
	}
	$cleanup = new WPOPT_General_Cleanup();
	$cleanup->run( false, (int) $attempt, true );
}

/**
 * Next pass of a cleanup that stopped at its time budget (WPOPT_General_Cleanup::RESUME_OPTION).
 *
 * Not tied to the schedule being allowed: it finishes a run somebody already started, manual
 * or scheduled.
 *
 * @return void
 */
function wpoptimizing_run_general_cleanup_resume() {
	$state = WPOPT_General_Cleanup::resume_state();
	if ( null === $state ) {
		return;
	}
	if ( (int) ( $state['idle'] ?? 0 ) >= WPOPT_General_Cleanup::RESUME_IDLE_MAX ) {
		WPOPT_General_Cleanup::end_resume(); // Passes that keep dying or deleting nothing: stop.
		return;
	}
	( new WPOPT_General_Cleanup() )->run( false, 0, true, (array) $state['tasks'] );
}

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_resolve_ext_names', 'wpoptimizing_resolve_ext_names' );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_unified_daily', 'wpoptimizing_unified_daily_job' );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_data_flush', 'wpoptimizing_unified_daily_job' );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_history_prune', 'wpoptimizing_history_prune_run' );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_general_cleanup_cron', 'wpoptimizing_run_general_cleanup_cron', 10, 1 );

/*
 * Registrado aquí, junto a la función, y no a nivel de fichero en wpoptimizing.php. Allí el
 * registro era incondicional mientras la función sólo existe con el motor cargado
 * (wpoptimizing_request_needs_engine(), que además es FILTRABLE), así que disparar el gancho
 * sin ese contexto era un TypeError de «callback no válido». Juntos no pueden separarse.
 */
add_action( 'wpoptimizing_general_cleanup_resume', 'wpoptimizing_run_general_cleanup_resume' );

// Tables too big for a click, rebuilt one per run by WP-Cron; it registers its own hook.
require_once dirname( __DIR__ ) . '/db-rebuild.php';
