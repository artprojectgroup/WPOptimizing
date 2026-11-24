<?php
/**
 * Attribution assistant — client side (Fase 3).
 *
 * Asks the data gateway (which proxies the KB-builder "alimentador") to SUGGEST the
 * owning plugin/theme of unattributed identifiers sitting in Pending/Doubtful. The
 * suggestion is advisory: the owner reviews it and, on Accept, it becomes a normal
 * manual attribution rule (see manual-attrib.php). Nothing is deleted or attributed
 * automatically — the whole point is a human-in-the-loop that never produces a false
 * orphan.
 *
 * Privacy: only identifier NAMES travel (option/meta/table/cron names), the same
 * anonymous structural data already shared with reciprocal consent. Opt-in only.
 *
 * Server contract — POST {endpoint}, Bearer <data token>, form/query field
 *   `nombres` = names separated by newlines (optionally `llm=1`); response JSON:
 *   { "resultados": [ { nombre, veredicto, dueno, confianza, motivo, candidatos[] } ] }
 * The default endpoint is the gateway; a filter can point it straight at a local
 * alimentador for development (adding an X-APG-Secret header via the args filter).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Endpoint that answers attribution-suggestion requests.
 *
 * @return string
 */
function wpoptimizing_attr_suggest_endpoint() {
	$default = wpoptimizing_gateway_url() . '?action=attr-suggest';
	return (string) apply_filters( 'wpoptimizing_attr_suggest_endpoint', $default );
}

/**
 * Whether the assistant may run: opt-in (reciprocal data-sharing consent) by default,
 * overridable via filter.
 *
 * @return bool
 */
function wpoptimizing_attr_suggest_enabled() {
	$consent = function_exists( 'wpoptimizing_has_share_consent' ) && wpoptimizing_has_share_consent();
	return (bool) apply_filters( 'wpoptimizing_attr_suggest_enabled', $consent );
}

/**
 * Cache key for a single identifier's suggestion.
 *
 * @param string $name Identifier.
 * @return string
 */
function wpoptimizing_attr_suggest_cache_key( $name ) {
	return 'wpopt_attr_sug_' . md5( strtolower( trim( (string) $name ) ) );
}

/**
 * Normalize one server result row to the client shape.
 *
 * @param array $row Raw result row.
 * @return array{nombre:string,veredicto:string,dueno:string,confianza:string,motivo:string,candidatos:array}
 */
function wpoptimizing_attr_suggest_normalize( array $row ) {
	$allowed = array( 'certain', 'strong', 'medium', 'ambiguo', 'sin_datos' );
	$verd    = strtolower( trim( (string) ( $row['veredicto'] ?? 'sin_datos' ) ) );
	if ( ! in_array( $verd, $allowed, true ) ) {
		$verd = 'sin_datos';
	}
	return array(
		'nombre'     => (string) ( $row['nombre'] ?? '' ),
		// El motivo viaja además como CÓDIGO, porque la prosa del servidor no está en nuestro
		// catálogo de traducción y no hay forma de pintarla en el idioma del usuario. Un
		// gateway antiguo no los manda y la pantalla sigue enseñando `motivo` tal cual.
		'motivo_cod' => sanitize_key( (string) ( $row['motivo_codigo'] ?? '' ) ),
		'motivo_dat' => is_array( $row['motivo_datos'] ?? null ) ? $row['motivo_datos'] : array(),
		'veredicto'  => $verd,
		'dueno'      => sanitize_title( (string) ( $row['dueno'] ?? '' ) ),
		'confianza'  => sanitize_key( (string) ( $row['confianza'] ?? 'none' ) ),
		'motivo'     => (string) ( $row['motivo'] ?? '' ),
		'candidatos' => is_array( $row['candidatos'] ?? null ) ? array_values( $row['candidatos'] ) : array(),
		'tipo'       => wpoptimizing_attr_suggest_owner_class( $row ),
	);
}

/**
 * The class of the suggested owner (plugin, theme, library…), taken from its candidate.
 *
 * The gateway sends it per candidate and not at the top of the row, and the screen read a
 * top-level `tipo` that this normalizer never kept: every accepted suggestion was saved as a
 * plugin, bundled libraries and themes included (26/10/2026).
 *
 * @param array $row Raw result row.
 * @return string An origin of the vocabulary; 'plugin' when there is nothing better.
 */
function wpoptimizing_attr_suggest_owner_class( array $row ) {
	$dueno = sanitize_title( (string) ( $row['dueno'] ?? '' ) );
	foreach ( (array) ( $row['candidatos'] ?? array() ) as $c ) {
		if ( is_array( $c ) && '' !== $dueno && sanitize_title( (string) ( $c['slug'] ?? '' ) ) === $dueno ) {
			$tipo = sanitize_key( (string) ( $c['tipo'] ?? '' ) );
			return ( function_exists( 'wpoptimizing_attribution_origin_exists' ) && wpoptimizing_attribution_origin_exists( $tipo ) ) ? $tipo : 'plugin';
		}
	}
	return 'plugin';
}

/**
 * Request suggestions for a batch of identifier names. Cached per-name; only cache
 * misses hit the network, in one request. Best-effort: returns whatever it can, never
 * throws, empty array when disabled/unreachable.
 *
 * @param array $names   Identifier names (option/meta/table/cron).
 * @param bool  $use_llm Pedir al servidor que además pase un juez LLM. EL GATEWAY NO TIENE
 *                       NINGUNO —la cadena `llm` no aparece en su código— y su propio router
 *                       lo dice: «Retrieval only (no LLM on the gateway)». El parámetro se
 *                       conserva porque el extremo es filtrable y un alimentador local sí
 *                       podría tenerlo; lo que se ha retirado es la casilla que lo ofrecía en
 *                       la pantalla, porque allí no hacía nada.
 * @return array<string,array> Map lowercased-name => normalized suggestion.
 */
function wpoptimizing_attr_suggest( array $names, $use_llm = false ) {
	$out   = array();
	$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
	if ( empty( $names ) || ! wpoptimizing_attr_suggest_enabled() ) {
		return $out;
	}

	// Serve from cache; collect the misses.
	$misses = array();
	foreach ( $names as $name ) {
		$cached = get_transient( wpoptimizing_attr_suggest_cache_key( $name ) );
		if ( is_array( $cached ) ) {
			$out[ strtolower( $name ) ] = $cached;
		} else {
			$misses[] = $name;
		}
	}
	if ( empty( $misses ) ) {
		return $out;
	}

	$token = function_exists( 'wpoptimizing_register_install' ) ? (string) wpoptimizing_register_install() : '';

	$args = array(
		'timeout'   => (int) apply_filters( 'wpoptimizing_attr_suggest_timeout', 20 ),
		'sslverify' => true,
		'headers'   => array(
			'Accept'        => 'application/json',
			'Authorization' => 'Bearer ' . $token,
			'X-WPO-Site'    => function_exists( 'wpoptimizing_site_id' ) ? wpoptimizing_site_id() : '',
		),
		'body'      => array(
			'nombres' => implode( "\n", $misses ),
			'llm'     => $use_llm ? '1' : '',
		),
	);
	/**
	 * Filter the HTTP args (e.g. add an X-APG-Secret header to hit a local alimentador
	 * directly in development).
	 *
	 * @param array $args   wp_remote_post args.
	 * @param array $misses Names being requested.
	 */
	$args = apply_filters( 'wpoptimizing_attr_suggest_args', $args, $misses );

	$resp = wp_remote_post( wpoptimizing_attr_suggest_endpoint(), $args );
	if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 300 ) {
		return $out; // Best-effort: return whatever the cache already gave us.
	}

	$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
	if ( ! is_array( $data ) || ! isset( $data['resultados'] ) || ! is_array( $data['resultados'] ) ) {
		return $out;
	}

	$ttl = (int) apply_filters( 'wpoptimizing_attr_suggest_ttl', 6 * HOUR_IN_SECONDS );
	foreach ( $data['resultados'] as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$norm = wpoptimizing_attr_suggest_normalize( $row );
		if ( '' === $norm['nombre'] ) {
			continue;
		}
		$out[ strtolower( $norm['nombre'] ) ] = $norm;
		set_transient( wpoptimizing_attr_suggest_cache_key( $norm['nombre'] ), $norm, $ttl );
	}

	return $out;
}

/**
 * Accept a suggestion: turn it into an exact manual attribution rule (name → owner),
 * which immediately routes the item out of Pending and feeds the KB lead pipeline.
 *
 * @param string $name  Identifier that was pending.
 * @param string $slug  Suggested owner slug.
 * @param string $type     'plugin' | 'theme'.
 * @param bool   $prefetch Ask wordpress.org for the owner's name now. A batch passes false and
 *                         asks once for all its owners: one request per row timed out.
 * @return bool True when a new rule was stored.
 */
function wpoptimizing_attr_suggest_accept( $name, $slug, $type = 'plugin', $prefetch = true ) {
	$name = trim( (string) $name );
	$slug = sanitize_title( (string) $slug );
	if ( '' === $name || '' === $slug || ! function_exists( 'wpoptimizing_manual_attribution_add' ) ) {
		return false;
	}
	// Drop the cached suggestion so the row reflects the new manual rule.
	delete_transient( wpoptimizing_attr_suggest_cache_key( $name ) );
	// The name of the owner, ready for the table that comes next (only plugins: the cache is
	// filled from the plugin directory, see wpoptimizing_ext_names_prefetch()).
	if ( $prefetch && 'plugin' === $type && function_exists( 'wpoptimizing_ext_names_prefetch' ) ) {
		wpoptimizing_ext_names_prefetch( array( $slug ), 1 );
	}
	return wpoptimizing_manual_attribution_add(
		array(
			'scope' => 'exact',
			'match' => $name,
			'slug'  => $slug,
			'name'  => $slug,
			// Mismo criterio que el formulario: el vocabulario entero, y lo de fuera a 'plugin'.
			// Colapsar aquí a dos valores tiraba el `library` que manda el motor del gateway.
			'type'  => ( function_exists( 'wpoptimizing_attribution_origin_exists' ) && wpoptimizing_attribution_origin_exists( $type ) ) ? $type : 'plugin',
		)
	);
}

/*
 * LO QUE EL ASISTENTE SABE CON SEGURIDAD, APLICADO POR EL ANÁLISIS.
 *
 * El análisis atribuye con las capas que el sitio se descarga; el asistente pregunta al gateway,
 * que tiene además la capa de atribución entera del corpus y no se descarga. Así, los restos de
 * un plugin desinstalado (wpsp_* de WP Support Plus en soporte.artprojectgroup.es, 26/10/2026)
 * se quedaban en «Por identificar» mientras el asistente los daba por seguros, y había que
 * aceptarlos uno a uno.
 *
 * Ahora, al terminar cada análisis, lo que queda sin dueño se pregunta al asistente en segundo
 * plano y las respuestas «certain» —coincidencia exacta con un único dueño en todo el corpus— se
 * guardan aquí. No son reglas manuales: una regla manual manda sobre todo y la escribe una
 * persona. Entran en la clasificación sólo para lo que nada más atribuyó, con confianza
 * `strong`, por debajo de lo que el sitio ha visto con sus propios ojos.
 */

/**
 * Owners the assistant gave with certainty: lowercased name => {slug, tipo, t}.
 *
 * @param bool $flush Drop the per-request cache.
 * @return array<string,array{slug:string,tipo:string,t:int}>
 */
function wpoptimizing_attr_suggest_auto_store( $flush = false ) {
	static $cache = null;
	if ( $flush ) {
		$cache = null;
	}
	if ( null === $cache ) {
		$raw   = get_option( 'wpoptimizing_attr_suggest_auto', array() );
		$cache = is_array( $raw ) ? $raw : array();
	}
	return $cache;
}

/**
 * The owner the assistant gave for this name, if any.
 *
 * Only while it is fresh, and only for the kinds of record it was asked about: the gateway is
 * asked by NAME, and an option and a meta key called `custom_status` can belong to different
 * extensions (audit of 26/10/2026). Entries stored before the kinds were kept carry none and
 * match any kind until they are asked again.
 *
 * @param string $value Identifier.
 * @param string $type  Kind of record (row `type`: option, postmeta, table…); '' for any.
 * @return array{slug:string,tipo:string}|null
 */
function wpoptimizing_attr_suggest_auto_match( $value, $type = '' ) {
	$key   = strtolower( trim( (string) $value ) );
	$store = wpoptimizing_attr_suggest_auto_store();
	if ( '' === $key || empty( $store[ $key ]['slug'] ) ) {
		return null;
	}
	$vida = (int) apply_filters( 'wpoptimizing_attr_suggest_auto_ttl', 30 * DAY_IN_SECONDS );
	if ( $vida > 0 && ( time() - (int) ( $store[ $key ]['t'] ?? 0 ) ) > $vida ) {
		return null; // Expired: the corpus may have changed, and nobody asked again.
	}
	$tipos = (array) ( $store[ $key ]['tipos'] ?? array() );
	$type  = strtolower( trim( (string) $type ) );
	if ( '' !== $type && ! empty( $tipos ) && ! in_array( $type, $tipos, true ) ) {
		return null;
	}
	return array(
		'slug' => (string) $store[ $key ]['slug'],
		'tipo' => (string) ( $store[ $key ]['tipo'] ?? 'plugin' ),
	);
}

/**
 * Keep the certain suggestions of a batch, and drop the ones the gateway no longer gives as
 * certain. Pure apart from the option write.
 *
 * WITHDRAWAL. An owner stored once used to stay forever: a later answer `ambiguo` left it in
 * place (audit of 26/10/2026). Only an ANSWER withdraws: a name that did not come back (network
 * failure, gateway error) is not in $suggestions and keeps what it had.
 *
 * @param array $suggestions Map name => normalized suggestion (wpoptimizing_attr_suggest()).
 * @param array $tipos       Map lowercased name => kinds of record it was asked for.
 * @return int How many were stored.
 */
function wpoptimizing_attr_suggest_auto_apply( array $suggestions, array $tipos = array() ) {
	$store   = wpoptimizing_attr_suggest_auto_store();
	$n       = 0;
	$cambios = 0;
	foreach ( $suggestions as $sug ) {
		if ( ! is_array( $sug ) ) {
			continue;
		}
		$key = strtolower( trim( (string) ( $sug['nombre'] ?? '' ) ) );
		if ( '' === $key ) {
			continue;
		}
		if ( 'certain' !== ( $sug['veredicto'] ?? '' ) || '' === (string) ( $sug['dueno'] ?? '' ) ) {
			if ( isset( $store[ $key ] ) ) {
				unset( $store[ $key ] );
				++$cambios;
			}
			continue;
		}
		$store[ $key ] = array(
			'slug'  => (string) $sug['dueno'],
			'tipo'  => (string) ( $sug['tipo'] ?? 'plugin' ),
			't'     => time(),
			'tipos' => array_values( array_unique( array_filter( array_map( 'strtolower', (array) ( $tipos[ $key ] ?? array() ) ) ) ) ),
		);
		++$n;
		++$cambios;
	}
	if ( $cambios > 0 ) {
		update_option( 'wpoptimizing_attr_suggest_auto', $store, false );
		wpoptimizing_attr_suggest_auto_store( true );
		if ( function_exists( 'wpoptimizing_flush_kpi_cache' ) ) {
			wpoptimizing_flush_kpi_cache();
		}
	}
	return $n;
}

/**
 * Ask the assistant about everything still unattributed after an analysis, and again about the
 * owners it gave more than a week ago.
 *
 * Batches of 200, the gateway's own cap, and at most five of them. The names TAKE TURNS: never
 * asked first, then the longest since asked. It used to take the same first thousand every
 * time, so on a site with more than a thousand unknowns the rest were never asked (audit of
 * 26/10/2026). Answers are cached per name for six hours by wpoptimizing_attr_suggest().
 *
 * @return int Owners stored.
 */
function wpoptimizing_attr_suggest_auto_run() {
	if ( ! wpoptimizing_attr_suggest_enabled() ) {
		return 0;
	}
	if ( function_exists( 'wpoptimizing_load_engine' ) ) {
		wpoptimizing_load_engine();
	}
	if ( ! class_exists( 'WPOPT_Admin_UI' ) ) {
		return 0;
	}
	$tipos = ( new WPOPT_Admin_UI() )->pending_db_rows();
	$ahora = time();
	foreach ( wpoptimizing_attr_suggest_auto_store() as $key => $fila ) {
		if ( ! isset( $tipos[ $key ] ) && ( $ahora - (int) ( $fila['t'] ?? 0 ) ) > 7 * DAY_IN_SECONDS ) {
			$tipos[ $key ] = (array) ( $fila['tipos'] ?? array() );
		}
	}
	$names = wpoptimizing_attr_suggest_auto_turns( array_keys( $tipos ), 1000 );
	$n     = 0;
	foreach ( array_chunk( $names, 200 ) as $lote ) {
		$n += wpoptimizing_attr_suggest_auto_apply( wpoptimizing_attr_suggest( $lote ), array_intersect_key( $tipos, array_flip( $lote ) ) );
	}
	// Their names too, here in the background, so the table shows them the first time.
	if ( $n > 0 && function_exists( 'wpoptimizing_ext_names_prefetch' ) ) {
		$plugins = array();
		foreach ( wpoptimizing_attr_suggest_auto_store( true ) as $fila ) {
			if ( 'plugin' === ( $fila['tipo'] ?? '' ) ) {
				$plugins[] = (string) $fila['slug'];
			}
		}
		wpoptimizing_ext_names_prefetch( $plugins, 20 );
	}
	return $n;
}
add_action( 'wpoptimizing_attr_suggest_auto', 'wpoptimizing_attr_suggest_auto_run' );

/**
 * Which names are asked this time, and note them as asked: never asked first, then the oldest.
 *
 * @param string[] $names Lowercased names.
 * @param int      $max   How many.
 * @return string[]
 */
function wpoptimizing_attr_suggest_auto_turns( array $names, $max ) {
	$asked = get_option( 'wpoptimizing_attr_suggest_asked', array() );
	$asked = is_array( $asked ) ? $asked : array();
	usort(
		$names,
		static function ( $a, $b ) use ( $asked ) {
			return ( (int) ( $asked[ $a ] ?? 0 ) ) <=> ( (int) ( $asked[ $b ] ?? 0 ) );
		}
	);
	$turno = array_slice( $names, 0, max( 0, (int) $max ) );
	$ahora = time();
	foreach ( $turno as $name ) {
		$asked[ $name ] = $ahora;
	}
	// Bounded: the oldest marks go first, and a name that falls out simply counts as never asked.
	if ( count( $asked ) > 5000 ) {
		asort( $asked );
		$asked = array_slice( $asked, -5000, null, true );
	}
	update_option( 'wpoptimizing_attr_suggest_asked', $asked, false );
	return $turno;
}

/**
 * After an analysis, schedule the question in the background: the analysis is not made to wait
 * for a remote service.
 *
 * @return void
 */
function wpoptimizing_attr_suggest_auto_schedule() {
	if ( wpoptimizing_attr_suggest_enabled() && ! wp_next_scheduled( 'wpoptimizing_attr_suggest_auto' ) ) {
		wp_schedule_single_event( time() + 30, 'wpoptimizing_attr_suggest_auto' );
	}
}
add_action( 'wpoptimizing_after_scan', 'wpoptimizing_attr_suggest_auto_schedule', 30 );
