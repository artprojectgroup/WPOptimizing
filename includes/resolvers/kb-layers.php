<?php
/**
 * The knowledge-base layers: the single list of what this site downloads from the gateway.
 *
 * One source of truth, used by BOTH the downloader (which turns a filename into the
 * gateway's ?file= key) and the settings screen (which reports each layer's freshness).
 * They used to be unrelated: the map lived next to the downloader and the UI knew about
 * exactly one layer. That is how the assets layer shipped with no map entry — the download
 * silently returned false and the layer would never have reached a single site.
 *
 * A missing layer is invisible by nature: nothing errors, the feature just quietly does
 * nothing. So the rule is that adding a layer here is the ONLY step, and both the download
 * and the health panel follow from it (KbFileKeyTest reads the resolvers and enforces it).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every layer this site downloads: local filename => gateway file key.
 *
 * @return array<string,string>
 */
function wpoptimizing_kb_layers() {
	return array(
		// The names of the files we DOWNLOAD. Every one carries -distrib, and deliberately so:
		// sharing a name with a file this site WRITES made the resolver read our own
		// contribution as the community's dataset.
		//
		// Three of these lacked the suffix until 2026-09-26 — manifest, runtime and rules — and
		// runtime was the dangerous one: apg-runtime-map.csv is the file the must-use runtime
		// writes (APG_WPOPT_RTMAP_CSV, same directory, same name), and it is the plugin's ONLY
		// source of `certain` confidence. Two separate paths destroyed it on any site with the
		// mu-plugin: this refresh downloaded over it, and WPOPT_Runtime_Resolver::invalidate_cache()
		// deleted it outright. The rule was applied where somebody remembered it (four names)
		// rather than to every name that can collide.
		'wp-db-distrib.csv'              => 'db',
		'wp-files-distrib.csv'           => 'files',
		'wp-plugin-manifest-distrib.csv' => 'manifest',
		'apg-runtime-map-distrib.csv'    => 'runtime',
		'wp-autoload-distrib.csv'        => 'autoload',
		'wp-assets-distrib.csv'          => 'assets',
		'wp-unload-rules-distrib.csv'    => 'rules',
		// What belongs to WordPress, measured per version (resolvers/core-nucleo.php).
		'wp-nucleo-distrib.csv'          => 'nucleo',
		// The name WordPress would show for each extension (storage/background-data.php): only
		// for display, never to attribute.
		'wp-nombres-distrib.csv'         => 'nombres',
	);
}

/**
 * Human label for a layer, for the freshness panel.
 *
 * @param string $key Layer key.
 * @return string
 */
function wpoptimizing_kb_layer_label( $key ) {
	$labels = array(
		'db'       => __( 'Database attribution', 'wpoptimizing' ),
		'files'    => __( 'File attribution', 'wpoptimizing' ),
		'manifest' => __( 'Known extensions', 'wpoptimizing' ),
		'runtime'  => __( 'Runtime evidence', 'wpoptimizing' ),
		'autoload' => __( 'Autoloaded options', 'wpoptimizing' ),
		'assets'   => __( 'Asset owners', 'wpoptimizing' ),
		'rules'    => __( 'Unload rules', 'wpoptimizing' ),
		'nucleo'   => __( 'WordPress core inventory', 'wpoptimizing' ),
		'nombres'  => __( 'Extension names', 'wpoptimizing' ),
	);
	return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
}

/**
 * Option holding why each layer's last download ended as it did.
 *
 * @var string
 */
const WPOPTIMIZING_KB_DOWNLOAD_LOG = 'wpoptimizing_kb_download_log';

/**
 * Anotar cómo acabó la última descarga de una capa.
 *
 * UNA CAPA QUE NO LLEGA NO DA ERROR: wpoptimizing_download_kb_file() devuelve `false` por
 * ocho motivos distintos —sin consentimiento, 404, cuerpo vacío, una página HTML servida con
 * un 200, el disco— y todos se veían igual desde fuera: «Not downloaded yet» en rojo, sin más.
 * Dos de las siete capas no están llegando a este sitio ahora mismo y el panel lo lleva
 * diciendo desde que existe, pero sin poder decir por qué, así que nadie lo miró.
 *
 * Se guarda un código corto y la hora, NUNCA el cuerpo de la respuesta ni cabeceras: ahí
 * viaja el token de instalación.
 *
 * @param string $key     Clave de la capa.
 * @param string $outcome Código: 'ok' | 'no-consent' | 'no-key' | 'network' | 'http-<código>'
 *                        | 'empty' | 'html' | 'write'.
 * @return void
 */
function wpoptimizing_kb_download_record( $key, $outcome ) {
	$key = (string) $key;
	if ( '' === $key ) {
		return;
	}
	$log = get_option( WPOPTIMIZING_KB_DOWNLOAD_LOG, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$log[ $key ] = array(
		't' => time(),
		'r' => substr( preg_replace( '/[^a-z0-9\-]/', '', strtolower( (string) $outcome ) ), 0, 20 ),
	);
	// Acotado a las capas que existen: así un `?file=` inventado no puede hacer crecer la opción.
	$log = array_intersect_key( $log, array_flip( array_values( wpoptimizing_kb_layers() ) ) );
	update_option( WPOPTIMIZING_KB_DOWNLOAD_LOG, $log, false );
	// A new `nombres` layer (recorded `ok` once it is in place) names at once what the cache gave
	// up on, not on a later view: wpoptimizing_ext_names_backfill_if_layer_newer().
	if ( 'nombres' === $key && 'ok' === $outcome && function_exists( 'wpoptimizing_ext_names_backfill_if_layer_newer' ) ) {
		wpoptimizing_ext_names_backfill_if_layer_newer();
	}
}

/**
 * Qué pasó en la última descarga de una capa.
 *
 * @param string $key Clave de la capa.
 * @return array{result:string,when:int} 'result' vacío cuando nunca se intentó.
 */
function wpoptimizing_kb_download_outcome( $key ) {
	$log = get_option( WPOPTIMIZING_KB_DOWNLOAD_LOG, array() );
	$row = ( is_array( $log ) && isset( $log[ (string) $key ] ) && is_array( $log[ (string) $key ] ) ) ? $log[ (string) $key ] : array();
	return array(
		'result' => (string) ( $row['r'] ?? '' ),
		'when'   => (int) ( $row['t'] ?? 0 ),
	);
}

/**
 * ¿Puede seguir la copia en disco sin pedir la capa ahora?
 *
 * Sí cuando se trajo (o se confirmó igual) hace menos del intervalo mínimo. Y TAMBIÉN durante
 * una hora tras un fallo de red o del gateway: sin esto, una descarga fallida se reintentaba en
 * cada carga del panel —hasta 3 intentos de 20 s por capa, síncronos— mientras el gateway
 * siguiera caído. «Sin consentimiento» o «sin clave» no son fallos: no hacen esperar. Quien
 * llama sólo pregunta si hay copia en disco; sin ella no hay con qué seguir.
 *
 * @param string $key Clave de la capa.
 * @return bool
 */
function wpoptimizing_kb_download_can_wait( $key ) {
	$last = wpoptimizing_kb_download_outcome( $key );
	$age  = time() - (int) $last['when'];
	if ( in_array( $last['result'], array( 'ok', 'unchanged' ), true ) ) {
		return $age < wpoptimizing_kb_download_min_interval();
	}
	return ! in_array( $last['result'], array( '', 'no-consent', 'no-key' ), true ) && $age < HOUR_IN_SECONDS;
}

/**
 * El código de la última descarga, en una frase que diga qué hacer.
 *
 * @param string $outcome Código.
 * @return string '' cuando no hay nada que contar (nunca se intentó, o salió bien).
 */
function wpoptimizing_kb_download_reason_label( $outcome ) {
	$outcome = (string) $outcome;
	if ( '' === $outcome || 'ok' === $outcome || 'unchanged' === $outcome ) {
		return '';
	}
	$fijos = array(
		'no-consent' => __( 'this site shares nothing, so it receives nothing', 'wpoptimizing' ),
		'no-key'     => __( 'this layer has no entry in the download map (a bug: please report it)', 'wpoptimizing' ),
		'network'    => __( 'the server could not be reached', 'wpoptimizing' ),
		'empty'      => __( 'the server sent an empty file', 'wpoptimizing' ),
		'html'       => __( 'the server sent a web page instead of data', 'wpoptimizing' ),
		'write'      => __( 'the file could not be written to disk', 'wpoptimizing' ),
	);
	if ( isset( $fijos[ $outcome ] ) ) {
		return $fijos[ $outcome ];
	}
	if ( 0 === strpos( $outcome, 'http-' ) ) {
		$codigo = (int) substr( $outcome, 5 );
		if ( 404 === $codigo ) {
			// Lo que de verdad pasa con `manifest` y `rules`: el gateway no tiene el artefacto.
			return __( 'the server has not built this layer yet (HTTP 404)', 'wpoptimizing' );
		}
		/* translators: %d: HTTP status code returned by the shared-dataset server. */
		return sprintf( __( 'the server answered HTTP %d', 'wpoptimizing' ), $codigo );
	}
	return $outcome;
}

/**
 * Map a knowledge-base filename to its gateway file key.
 *
 * @param string $filename Local/remote filename.
 * @return string '' when not a KB file.
 */
function wpoptimizing_kb_file_key( $filename ) {
	$map  = wpoptimizing_kb_layers();
	$base = basename( (string) $filename );
	return isset( $map[ $base ] ) ? $map[ $base ] : '';
}

/**
 * Refresh EVERY knowledge-base layer from the gateway.
 *
 * Driven by wpoptimizing_kb_layers(), so adding a layer there is the only step: it downloads,
 * it shows its freshness, and it refreshes — no third list to forget. (Forgetting a list is
 * precisely how the assets layer shipped unable to reach a single site.)
 *
 * Download is licence-free but consent-gated inside wpoptimizing_download_kb_file(): a site
 * that shares nothing receives nothing, and the call is a no-op.
 *
 * @param bool $force Past the once-a-day spacing (a sync the user asked for); still conditional.
 * @return array<string,bool> layer key => refreshed.
 */
function wpoptimizing_refresh_kb_layers( $force = false ) {
	$out = array();
	if ( ! function_exists( 'wpoptimizing_download_kb_file' ) ) {
		return $out;
	}
	foreach ( wpoptimizing_kb_layers() as $file => $key ) {
		$path        = trailingslashit( WPOPT_DIR ) . $file;
		$out[ $key ] = (bool) wpoptimizing_download_kb_file( $file, $path, $force );
	}
	return $out;
}

/**
 * Freshness of every layer's local copy, for the settings screen.
 *
 * @return array<int,array{key:string,file:string,label:string,status:string,mtime:int,exists:bool}>
 */
function wpoptimizing_kb_layers_freshness() {
	$out = array();
	foreach ( wpoptimizing_kb_layers() as $file => $key ) {
		$f      = wpoptimizing_kb_file_freshness( $file );
		$ultimo = wpoptimizing_kb_download_outcome( $key );
		$motivo = wpoptimizing_kb_download_reason_label( $ultimo['result'] );
		/*
		 * LA CONTRADICCIÓN QUE DELATA UN BORRADO, y es la que hoy habría hecho falta.
		 *
		 * «La última descarga fue bien» y «el fichero no está» no pueden ser verdad a la vez.
		 * Cuando lo son, la capa llegó y algo se la llevó después — que es exactamente lo que
		 * pasaba con el manifiesto: se descargaba y la línea siguiente de la sincronización lo
		 * borraba. Desde fuera eso era indistinguible de una capa que no llegaba nunca, y por
		 * eso la búsqueda se fue al gateway durante medio día.
		 *
		 * Un fallo que se ve igual que otro fallo distinto cuesta el doble de encontrar. Que
		 * lo diga el panel.
		 */
		if ( 'ok' === $ultimo['result'] && ! $f['exists'] ) {
			$motivo = __( 'it downloaded and then disappeared — something is deleting it after the download', 'wpoptimizing' );
		}
		$out[] = array(
			'key'    => $key,
			'file'   => $file,
			'label'  => wpoptimizing_kb_layer_label( $key ),
			'status' => (string) $f['status'],
			'mtime'  => (int) $f['mtime'],
			'exists' => (bool) $f['exists'],
			// Por qué no llegó, si no llegó. Vacío cuando salió bien o nunca se intentó.
			'reason' => $motivo,
		);
	}
	return $out;
}

/*
 * ───────────────── Sincronización de la KB, SIEMPRE en segundo plano ─────────────────
 *
 * Analysing is when this site's picture of itself is freshest, so it is the right moment to
 * push its contribution and pull the shared data down. But that moves several MB, and the
 * user must never wait for it: the analysis reports "complete" and this runs afterwards, on
 * its own, invisibly. The daily job keeps running as it always did.
 */

/**
 * Olvidar lo que los resolutores memoizaron de las capas, DEJANDO LOS FICHEROS EN SU SITIO.
 *
 * Se llama justo después de descargar, para que la siguiente atribución lea lo nuevo y no los
 * índices de lo viejo. Aquí estaba `WPOPT_Manifest_Resolver::invalidate_cache()`, cuyo nombre
 * decía eso y cuyo cuerpo además BORRABA DEL DISCO el fichero que la línea de arriba acababa
 * de traer. Medido en producción: el manifiesto aparecía tras una sincronización manual —que
 * no pasa por aquí— y desaparecía en la siguiente automática.
 *
 * Función con nombre propio y no una línea suelta, para que KbSyncKeepsLayersTest pueda
 * comprobar lo único que importa: que después de llamarla las siete capas siguen en disco.
 *
 * @return void
 */
function wpoptimizing_kb_forget_memos() {
	if ( class_exists( 'WPOPT_Manifest_Resolver' ) && method_exists( 'WPOPT_Manifest_Resolver', 'forget_indexes' ) ) {
		WPOPT_Manifest_Resolver::forget_indexes();
	}
}

/**
 * Hand the KB sync to a background job. Cheap and non-blocking: schedule, nudge cron, return.
 *
 * After the DAILY scan it waits this site's own spread first: every install schedules that scan
 * at the same local hour (02:00 by default), so without it every site in a time zone would reach
 * the gateway in the same few minutes. A scan somebody started, or a plugin change, is already
 * spread out by the people causing it, and its result is wanted now.
 *
 * @param string $reason What started the scan that just finished.
 * @return void
 */
function wpoptimizing_kb_sync_schedule( $reason = '' ) {
	$delay = 5 + ( 'scheduled' === (string) $reason ? wpoptimizing_kb_sync_spread() : 0 );
	if ( ! wp_next_scheduled( 'wpoptimizing_kb_sync_cron' ) ) {
		wp_schedule_single_event( time() + $delay, 'wpoptimizing_kb_sync_cron' );
	}
	// Nudge cron so the job starts now rather than on the next visitor — but only when WP-Cron
	// is actually allowed to run, and only if it is due now. Never spawn synchronously here:
	// this is the last thing the scan does, and the user is watching a progress bar.
	if ( $delay <= 5 && ( ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
		spawn_cron( time() );
	}
}

/**
 * This site's fixed delay, in seconds, before the sync that follows its daily scan.
 *
 * Derived from the site, not drawn at random: the same site lands at the same time every day,
 * and a thousand sites spread evenly over two hours.
 *
 * @return int 0 to 7199.
 */
function wpoptimizing_kb_sync_spread() {
	$site = function_exists( 'wpoptimizing_site_id' ) ? (string) wpoptimizing_site_id() : (string) home_url();
	return (int) ( hexdec( substr( md5( 'wpopt-kb-sync|' . $site ), 0, 7 ) ) % ( 2 * HOUR_IN_SECONDS ) );
}

/**
 * Sync the knowledge base BOTH WAYS. Runs in the background (cron), never in a page request.
 *
 * Uploads are consent-gated; downloads are consent-gated too (reciprocal sharing), so an
 * install that shares nothing does nothing here and costs nothing.
 *
 * @return array{uploaded:bool,layers:array<string,bool>}
 */
function wpoptimizing_kb_sync_run() {
	$out = array(
		'uploaded' => false,
		'layers'   => array(),
	);

	// A scan finishing while a sync is already running must not start a second one: both would
	// upload the same files and fight over the same downloads.
	if ( get_transient( 'wpoptimizing_kb_sync_lock' ) ) {
		return $out;
	}
	set_transient( 'wpoptimizing_kb_sync_lock', 1, 10 * MINUTE_IN_SECONDS );

	if ( function_exists( 'wpoptimizing_has_share_consent' ) && wpoptimizing_has_share_consent() ) {
		// The contribution files were rebuilt by the scan's code stages, so they go up as they
		// are: re-running the code scan here would repeat minutes of work for nothing.
		if ( function_exists( 'wpoptimizing_upload_unified_files' ) ) {
			wpoptimizing_upload_unified_files();
			$out['uploaded'] = true;
		}
		if ( function_exists( 'wpoptimizing_upload_autoload_dataset' ) ) {
			wpoptimizing_upload_autoload_dataset();
		}
	}

	$out['layers'] = wpoptimizing_refresh_kb_layers();
	wpoptimizing_kb_forget_memos();

	delete_transient( 'wpoptimizing_kb_sync_lock' );
	return $out;
}
add_action( 'wpoptimizing_kb_sync_cron', 'wpoptimizing_kb_sync_run' );
