<?php
/**
 * AI attribution assistant — admin surface (Fase 3).
 *
 * Adds the attribution assistant to the Database sub-section of the Pending and Orphans tabs. It
 * asks the assistant (client in includes/resolvers/attr-suggest.php) for the likely
 * owner of the visible unattributed records and renders the verdicts. Confident
 * suggestions offer an Accept button (one, or all the sure ones) that creates a normal
 * manual attribution rule (name -> owner), so the record leaves Pending immediately. The sure
 * ones are also applied after every analysis, as `strong` attributions and not as rules (see
 * resolvers/attr-suggest.php). Nothing is ever deleted here.
 *
 * Only Database records are eligible (the knowledge base attributes by identifier
 * NAME; file paths are not name-attributable).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trait WPOptimizing_Attr_Suggest_Trait
 */
trait WPOptimizing_Attr_Suggest_Trait {

	/** @var array<string,array>|null Suggestions computed this request (name => suggestion). */
	protected $attr_suggest_results = null;

	/**
	 * Dueño que cada fila visible YA muestra: clave en minúsculas => [slug, …].
	 *
	 * Sirve para lo único que importa en Huérfanos: saber si el conjunto compartido dice otra
	 * cosa que la pantalla. Sin esto, el asistente contesta y nadie compara.
	 *
	 * @var array<string,array<int,string>>
	 */
	protected $attr_suggest_actuales = array();

	/** @var string Message about this request's question, shown inside the panel. */
	protected $attr_suggest_notice = '';

	/** @var array<string,array<string,string>> Class of each owner shown now: key => [slug => origin]. */
	protected $attr_suggest_clases = array();

	/**
	 * Names of the database records still without an owner, as the Pending screen shows them.
	 *
	 * Same reading as the screen —scan CSV, overlay, split by status— so the analysis asks the
	 * assistant about exactly what a person would see under "To identify".
	 *
	 * @return string[]
	 */
	public function pending_db_names() {
		return array_keys( $this->pending_db_rows() );
	}

	/**
	 * The same names, each with the kinds of record it appears as (option, postmeta, table…):
	 * the assistant is asked by name, and its answer only applies to those kinds.
	 *
	 * @return array<string,string[]> Lowercased name => kinds.
	 */
	public function pending_db_rows() {
		$split = $this->split_rows_by_effective_status( $this->overlay_runtime_attributions( $this->read_orphans_db() ) );
		$rows  = array();
		foreach ( (array) ( $split['none'] ?? array() ) as $r ) {
			$v = strtolower( trim( (string) ( $r['value'] ?? '' ) ) );
			if ( '' === $v ) {
				continue;
			}
			$t = strtolower( trim( (string) ( $r['type'] ?? '' ) ) );
			if ( ! isset( $rows[ $v ] ) ) {
				$rows[ $v ] = array();
			}
			if ( '' !== $t && ! in_array( $t, $rows[ $v ], true ) ) {
				$rows[ $v ][] = $t;
			}
		}
		return $rows;
	}

	/**
	 * Handle the "Suggest owners" and "Accept suggestion" self-POSTs. Dispatched from
	 * the same early block as manual attribution so an accepted rule applies this request.
	 *
	 * @return void
	 */
	protected function preprocess_attr_suggest() {
		if ( ! current_user_can( wpoptimizing_cap() ) || ! function_exists( 'wpoptimizing_attr_suggest' ) ) {
			return;
		}

		// Accept every sure suggestion at once: the same rule as Accept, one per row. They used to
		// go one by one, a page load each (soporte.artprojectgroup.es, 26/10/2026).
		if ( isset( $_POST['wpopt_attr_accept_all'] ) ) {
			check_admin_referer( 'wpopt_attr_accept_all' );
			$nombres = isset( $_POST['wpopt_attr_all_name'] ) && is_array( $_POST['wpopt_attr_all_name'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['wpopt_attr_all_name'] ) ) : array();
			$duenos  = isset( $_POST['wpopt_attr_all_slug'] ) && is_array( $_POST['wpopt_attr_all_slug'] ) ? array_map( 'sanitize_title', wp_unslash( $_POST['wpopt_attr_all_slug'] ) ) : array();
			$clases  = isset( $_POST['wpopt_attr_all_type'] ) && is_array( $_POST['wpopt_attr_all_type'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['wpopt_attr_all_type'] ) ) : array();
			$hechas  = 0;
			$plugins = array();
			foreach ( array_slice( array_keys( $nombres ), 0, 200 ) as $i ) {
				$name = (string) $nombres[ $i ];
				$slug = (string) ( $duenos[ $i ] ?? '' );
				$type = (string) ( $clases[ $i ] ?? '' );
				$type = wpoptimizing_attribution_origin_exists( $type ) ? $type : 'plugin';
				if ( '' !== $name && '' !== $slug && wpoptimizing_attr_suggest_accept( $name, $slug, $type, false ) ) {
					++$hechas;
					if ( 'plugin' === $type ) {
						$plugins[ $slug ] = true;
					}
				}
			}
			// The names once, after every rule is saved, and only a few: the rest go to the cron
			// through the table drawn next (wpoptimizing_ext_name() queues what it does not know).
			if ( ! empty( $plugins ) && function_exists( 'wpoptimizing_ext_names_prefetch' ) ) {
				wpoptimizing_ext_names_prefetch( array_keys( $plugins ), 10 );
			}
			$this->set_pending_flash(
				array(
					'code' => 'wpopt_attr_accepted_all',
					/* translators: %d: number of suggestions accepted. */
					'msg'  => esc_html( sprintf( _n( '%d suggestion accepted. The records were re-evaluated.', '%d suggestions accepted. The records were re-evaluated.', $hechas, 'wpoptimizing' ), $hechas ) ),
					'type' => $hechas > 0 ? 'success' : 'warning',
				)
			);
			return;
		}

		// Accept one suggestion -> manual attribution rule.
		if ( isset( $_POST['wpopt_attr_accept'] ) ) {
			check_admin_referer( 'wpopt_attr_accept' );
			$name = isset( $_POST['wpopt_attr_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_attr_name'] ) ) : '';
			$slug = isset( $_POST['wpopt_attr_slug'] ) ? sanitize_title( wp_unslash( $_POST['wpopt_attr_slug'] ) ) : '';
			// Cualquier origen del vocabulario, no sólo 'theme'. Lo de fuera cae en 'plugin',
			// que es lo que hacía antes: nunca se inyecta un origen que la columna no sepa pintar.
			$type = isset( $_POST['wpopt_attr_type'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_attr_type'] ) ) : '';
			$type = wpoptimizing_attribution_origin_exists( $type ) ? $type : 'plugin';
			$ok   = ( '' !== $name && '' !== $slug ) ? wpoptimizing_attr_suggest_accept( $name, $slug, $type ) : false;
			$this->set_pending_flash(
				$ok
					? array(
						'code' => 'wpopt_attr_accepted',
						/* translators: 1: identifier name, 2: owner slug. */
						'msg'  => esc_html( sprintf( __( 'Suggestion accepted: "%1$s" attributed to %2$s. The record was re-evaluated.', 'wpoptimizing' ), $name, $this->attr_suggest_owner_name( $slug, $type ) ) ),
						'type' => 'success',
					)
					: array(
						'code' => 'wpopt_attr_accept_none',
						'msg'  => esc_html__( 'The suggestion could not be saved (it may already exist).', 'wpoptimizing' ),
						'type' => 'warning',
					)
			);
			return;
		}

		// Request suggestions for the submitted identifier names.
		if ( isset( $_POST['wpopt_attr_suggest'] ) ) {
			check_admin_referer( 'wpopt_attr_suggest' );
			if ( ! wpoptimizing_attr_suggest_enabled() ) {
				$this->set_pending_flash(
					array(
						'code' => 'wpopt_attr_disabled',
						'msg'  => esc_html__( 'The attribution assistant needs data sharing turned on (it sends only identifier names).', 'wpoptimizing' ),
						'type' => 'warning',
					)
				);
				return;
			}
			$names = isset( $_POST['wpopt_attr_names'] ) && is_array( $_POST['wpopt_attr_names'] )
				? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['wpopt_attr_names'] ) ) ) )
				: array();
			// 200: the gateway's cap per request, and the most rows a page can show.
			$names = array_slice( $names, 0, 200 );
			// Sin juez de IA: el gateway no tiene ninguno y la casilla se ha retirado.
			$llm = false;
			if ( empty( $names ) ) {
				return;
			}
			$this->attr_suggest_results = wpoptimizing_attr_suggest( $names, $llm );
			// Said inside the panel, where the person is looking: as a flash it went to the top of
			// the page and its scroll took the page away from the assistant.
			if ( empty( $this->attr_suggest_results ) ) {
				$this->attr_suggest_notice = __( 'The assistant returned no suggestions (the service may be unreachable). Nothing was changed.', 'wpoptimizing' );
			}
		}
	}

	/**
	 * Render the attribution assistant for a Database section.
	 *
	 * DOS CONTEXTOS, Y LA PREGUNTA NO ES LA MISMA. En Pendientes las filas no tienen dueño y lo
	 * que se pide es uno. En Huérfanos SÍ lo tienen, y lo que se pide es una SEGUNDA OPINIÓN
	 * antes de borrar: ahí lo que vale no es la sugerencia, es la DISCREPANCIA entre lo que
	 * dice la fila y lo que dice el conjunto compartido.
	 *
	 * El panel vivía sólo en Pendientes, que está al revés de como tiene que ser: donde más
	 * falta hace comprobar un dueño es en la pantalla que ofrece un botón de borrar. Reportado
	 * con `jetpack_options` enseñada como de `slimjetpack` —Jetpack reempaquetado— sin más
	 * salida que atribuirla a mano.
	 *
	 * @param array  $rows     Visible DB rows (each with a 'value' identifier).
	 * @param string $contexto 'pending' | 'orphans'.
	 * @return void
	 */
	protected function render_attr_suggest_panel( array $rows, $contexto = 'pending' ) {
		if ( ! function_exists( 'wpoptimizing_attr_suggest_enabled' ) ) {
			return;
		}
		// Collect the visible identifier names, and the owner each row already shows.
		$names = array();
		foreach ( $rows as $r ) {
			$v = isset( $r['value'] ) ? trim( (string) $r['value'] ) : '';
			if ( '' === $v ) {
				continue;
			}
			$names[ strtolower( $v ) ] = $v;

			$duenos = array_values( array_filter( array_map( 'trim', explode( '|', (string) ( $r['project_slugs'] ?? '' ) ) ) ) );
			if ( ! empty( $duenos ) ) {
				$this->attr_suggest_actuales[ strtolower( $v ) ] = $duenos;
				// Their class too, one per owner (or one for all), to name each one from its own map.
				$clases = array_values( array_map( 'trim', explode( '|', (string) ( $r['project_origins'] ?? '' ) ) ) );
				foreach ( $duenos as $i => $d ) {
					$this->attr_suggest_clases[ strtolower( $v ) ][ $d ] = (string) ( $clases[ $i ] ?? ( 1 === count( $clases ) ? $clases[0] : '' ) );
				}
			}
		}
		if ( empty( $names ) ) {
			return;
		}

		$es_huerfanos = ( 'orphans' === $contexto );

		echo '<div class="wpopt-card wpopt-attr-panel" id="wpopt-attr-assistant">';
		echo '<h3 class="wpopt-attr-title">' . esc_html__( 'Attribution assistant', 'wpoptimizing' ) . '</h3>';
		echo '<p class="wpopt-desc-tight">' . esc_html(
			$es_huerfanos
				? __( 'Check the owner shown for these records against the shared knowledge base before you delete anything. A record whose owner does not match is flagged, and you decide: accepting a suggestion creates a manual attribution rule. Nothing is deleted here.', 'wpoptimizing' )
				: __( 'After each analysis, the owners the shared knowledge base gives with certainty are applied on their own. Ask here about the rest: confirm a suggestion with Accept, or all the sure ones at once, and it becomes a manual attribution rule. Nothing is deleted.', 'wpoptimizing' )
		) . '</p>';

		if ( ! wpoptimizing_attr_suggest_enabled() ) {
			$enable_url = wp_nonce_url( admin_url( 'admin.php?page=wpoptimizing&wpopt_enable_sharing=1' ), 'wpopt_enable_sharing' );
			echo '<p class="wpopt-notice">' . esc_html__( 'Turn on data sharing to use the assistant (only identifier names are sent).', 'wpoptimizing' )
				. ' <a href="' . esc_url( $enable_url ) . '">' . esc_html__( 'Turn on sharing', 'wpoptimizing' ) . '</a></p>';
			echo '</div>';
			return;
		}

		// Trigger form: carries the visible names. The answer is drawn in this same request, below
		// the long table: the anchor brings the page back down to it instead of leaving it at the top.
		echo '<form method="post" class="wpopt-attr-form" action="#wpopt-attr-assistant">';
		wp_nonce_field( 'wpopt_attr_suggest' );
		echo '<input type="hidden" name="wpopt_attr_suggest" value="1" />';
		foreach ( $names as $n ) {
			echo '<input type="hidden" name="wpopt_attr_names[]" value="' . esc_attr( $n ) . '" />';
		}
		/*
		 * FUERA EL «JUEZ DE IA», QUE NO EXISTE. Había aquí una casilla «Also run the AI judge»
		 * que enviaba `llm=1`, y la cadena `llm` NO APARECE NI UNA VEZ en todo el repositorio
		 * del gateway: nadie la lee. El propio router lo dice en su cabecera —«Retrieval only
		 * (no LLM on the gateway)»— mientras el cliente ofrecía el botón.
		 *
		 * Una casilla que no hace nada es peor que ninguna, porque quien la marca cree que ha
		 * cambiado algo; este mismo plugin ya retiró por eso el interruptor de la pantalla de
		 * compatibilidad. Y en un producto que se va a vender, poner «IA» a algo que no la
		 * lleva no es sólo un control muerto.
		 *
		 * El sugeridor es RECUPERACIÓN sobre el corpus de atribución, y eso es lo que dice
		 * ahora el botón.
		 */
		echo '<p class="wpopt-btn-row">';
		echo '<button type="submit" class="button button-secondary">' . esc_html(
			$es_huerfanos
				? __( 'Check these owners', 'wpoptimizing' )
				: __( 'Suggest owners', 'wpoptimizing' )
		) . '</button>';
		echo '</p>';
		echo '</form>';

		if ( '' !== $this->attr_suggest_notice ) {
			echo '<div class="notice notice-info inline wpopt-notice"><p>' . esc_html( $this->attr_suggest_notice ) . '</p></div>';
		}
		$this->render_attr_suggest_results( $es_huerfanos ? 'orphans' : 'pending' );

		echo '</div>';
		// The answer is drawn below the long table, and the `#wpopt-attr-assistant` anchor of the
		// form is not enough: the browser jumps before the page has finished laying out and ends up
		// elsewhere (soporte.artprojectgroup.es, 26/10/2026). Once loaded, like the flash notice.
		if ( '' !== $this->attr_suggest_notice || ( is_array( $this->attr_suggest_results ) && ! empty( $this->attr_suggest_results ) ) ) {
			wp_print_inline_script_tag( '(function(){var p=document.getElementById("wpopt-attr-assistant");if(!p||!p.scrollIntoView){return;}window.addEventListener("load",function(){setTimeout(function(){p.scrollIntoView({behavior:"smooth",block:"start"});},300);});})();' );
		}
	}

	/**
	 * El motivo de una sugerencia, en el idioma del usuario.
	 *
	 * El servidor manda la explicación en prosa inglesa, y ésa NO se puede traducir aquí: una
	 * cadena construida allí no está en nuestro catálogo. Reconstruirla a la inversa tampoco
	 * vale, porque el veredicto no basta —`ambiguo` corresponde a tres motivos distintos— y
	 * adivinar sería inventar una explicación, que es peor que no dar ninguna.
	 *
	 * Así que el gateway manda además un CÓDIGO y sus datos, y la frase se escribe aquí. Con
	 * un gateway antiguo el código no llega y se enseña la prosa tal cual, como hasta ahora:
	 * en inglés, pero cierta.
	 *
	 * @param array $sug Sugerencia normalizada.
	 * @return string Texto plano; lo escapa quien lo pinta.
	 */
	protected function attr_suggest_reason( array $sug ) {
		$codigo = (string) ( $sug['motivo_cod'] ?? '' );
		$datos  = is_array( $sug['motivo_dat'] ?? null ) ? $sug['motivo_dat'] : array();

		switch ( $codigo ) {
			case 'libreria-compartida':
				return sprintf(
					/* translators: %s: name of a library bundled inside several plugins. */
					__( 'It belongs to the shared library "%s", which several plugins carry inside: there is no single owner.', 'wpoptimizing' ),
					(string) ( $datos['libreria'] ?? '' )
				);
			case 'exacta-unica':
				return __( 'Exact match with a single owner in the shared knowledge base.', 'wpoptimizing' );
			case 'exacta-espacio-propio':
				return sprintf(
					/* translators: %s: plugin slug that owns the namespace. */
					__( 'Exact match: the record is in the namespace of "%s", and the others only read it.', 'wpoptimizing' ),
					(string) ( $datos['slug'] ?? '' )
				);
			case 'exacta-sin-dueno-claro':
				return sprintf(
					/* translators: %d: number of plugins that use the record. */
					__( '%d plugins use it and none is clearly its owner, so no owner is proposed. Using a record is not owning it.', 'wpoptimizing' ),
					(int) ( $datos['cuantos'] ?? 0 )
				);
			case 'prefijo-dueno-unico':
				return __( 'No exact match, but the prefix belongs to a single owner.', 'wpoptimizing' );
			case 'prefijo-especifico':
				return __( 'A specific prefix of a single owner. A hint to confirm, not a certainty.', 'wpoptimizing' );
			case 'prefijo-compartido':
				return __( 'Only prefixes shared by several owners: too weak to propose anyone.', 'wpoptimizing' );
		}

		// Gateway anterior a los códigos: su frase, tal cual. En inglés, pero cierta.
		return (string) ( $sug['motivo'] ?? '' );
	}

	/**
	 * Display name of an owner slug: installed plugin or theme, else the WordPress.org cache, else
	 * the slug. The same lookup the results tables use (resolve_project_name()).
	 *
	 * @param string $slug  Owner slug.
	 * @param string $clase Owner class: `plugin`, `theme`, `library`, `core`, or '' when unknown.
	 * @return string
	 */
	public function attr_suggest_owner_name( $slug, $clase = 'plugin' ) {
		$slug = (string) $slug;
		if ( '' === $slug || ! method_exists( $this, 'ext_display_name' ) ) {
			return $slug;
		}
		// A library or core is not looked up in the plugin directory: a namesake plugin would
		// lend it its name. Unknown class: both maps, like ext_display_name() does.
		if ( in_array( $clase, array( 'library', 'core' ), true ) ) {
			return $slug;
		}
		return $this->ext_display_name( $slug, in_array( $clase, array( 'plugin', 'theme' ), true ) ? $clase : '' );
	}

	/**
	 * Render the suggestions table computed by preprocess_attr_suggest() this request.
	 *
	 * @return void
	 */
	protected function render_attr_suggest_results( $contexto = 'pending' ) {
		if ( ! is_array( $this->attr_suggest_results ) || empty( $this->attr_suggest_results ) ) {
			return;
		}
		// Every sure suggestion, accepted in one go. Only in Pending: in Orphans the row already
		// has an owner, and what matters there is each disagreement, looked at one by one.
		$seguras = array();
		foreach ( $this->attr_suggest_results as $sug ) {
			if ( is_array( $sug ) && 'certain' === ( $sug['veredicto'] ?? '' ) && '' !== (string) ( $sug['dueno'] ?? '' ) ) {
				$seguras[] = $sug;
			}
		}
		if ( 'pending' === $contexto && count( $seguras ) > 1 ) {
			echo '<form method="post" class="wpopt-attr-accept-all">';
			wp_nonce_field( 'wpopt_attr_accept_all' );
			echo '<input type="hidden" name="wpopt_attr_accept_all" value="1" />';
			foreach ( $seguras as $sug ) {
				echo '<input type="hidden" name="wpopt_attr_all_name[]" value="' . esc_attr( (string) $sug['nombre'] ) . '" />';
				echo '<input type="hidden" name="wpopt_attr_all_slug[]" value="' . esc_attr( (string) $sug['dueno'] ) . '" />';
				echo '<input type="hidden" name="wpopt_attr_all_type[]" value="' . esc_attr( (string) ( $sug['tipo'] ?? 'plugin' ) ) . '" />';
			}
			echo '<p class="wpopt-btn-row"><button type="submit" class="button button-primary">' . esc_html(
				sprintf(
					/* translators: %d: number of sure suggestions. */
					__( 'Accept all sure suggestions (%d)', 'wpoptimizing' ),
					count( $seguras )
				)
			) . '</button></p>';
			echo '</form>';
		}
		/*
		 * Los tres primeros son niveles de confianza y salen del vocabulario único: aquí
		 * estaban escritos otra vez, que es la sexta copia de la misma lista. Los dos últimos
		 * son veredictos del sugeridor y no niveles —«varios candidatos» y «no sé»—, así que
		 * se quedan donde se producen.
		 */
		$labels = array(
			'certain'   => array( wpoptimizing_confidence_label( 'certain' ), 'ok' ),
			'strong'    => array( wpoptimizing_confidence_label( 'strong' ), 'ok' ),
			'medium'    => array( wpoptimizing_confidence_label( 'medium' ), 'warn' ),
			'ambiguo'   => array( __( 'Ambiguous', 'wpoptimizing' ), 'muted' ),
			'sin_datos' => array( __( 'No data', 'wpoptimizing' ), 'muted' ),
		);

		echo '<table class="wp-list-table widefat striped wpopt-attr-table">';
		echo '<thead><tr>';
		echo '<th scope="col">' . esc_html__( 'Record', 'wpoptimizing' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Verdict', 'wpoptimizing' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Shown now', 'wpoptimizing' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Suggested owner', 'wpoptimizing' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Why', 'wpoptimizing' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Action', 'wpoptimizing' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $this->attr_suggest_results as $sug ) {
			if ( ! is_array( $sug ) ) {
				continue;
			}
			$verd  = (string) ( $sug['veredicto'] ?? 'sin_datos' );
			$lab   = $labels[ $verd ] ?? array( $verd, 'muted' );
			$dueno = (string) ( $sug['dueno'] ?? '' );
			$name  = (string) ( $sug['nombre'] ?? '' );

			/*
			 * LA CLASE DEL DUEÑO VIENE EN EL CANDIDATO Y SE TIRABA. El motor del gateway manda
			 * `tipo` en cada candidato —ahí viaja el `library` del corpus de atribución— y aquí
			 * no se pintaba ni se reenviaba: el campo `wpopt_attr_type` que lee el manejador
			 * aparecía UNA sola vez en todo el plugin, en el propio lector, así que su isset()
			 * era siempre falso y toda sugerencia aceptada se guardaba como «plugin». Temas,
			 * núcleo y librerías empaquetadas incluidos.
			 *
			 * Y es el error más duradero de los que había, porque una regla manual manda sobre
			 * todas las automáticas, incluido el conjunto compartido.
			 *
			 * Sólo se acepta un valor del vocabulario: lo que venga fuera de él no entra como
			 * origen, se queda en 'plugin' como hasta ahora.
			 */
			$clase = (string) ( $sug['tipo'] ?? '' );
			if ( ! wpoptimizing_attribution_origin_exists( $clase ) ) {
				$clase = 'plugin';
			}
			// The NAME, as every other table shows it (format_projects_nicely()): the slug alone
			// was the only thing this table printed.
			$etiqueta_dueno = '' !== $dueno ? wpoptimizing_attribution_origin_label( $clase, $this->attr_suggest_owner_name( $dueno, $clase ) ) : '';
			if ( '' === $etiqueta_dueno ) {
				$etiqueta_dueno = $dueno;
			}

			/*
			 * LA DISCREPANCIA ES LO QUE SE VIENE A VER. Una sugerencia que coincide con lo que
			 * la pantalla ya dice no aporta nada; una que NO coincide es la razón de existir de
			 * este panel en Huérfanos, donde hay un botón de borrar al lado.
			 *
			 * Se compara contra TODOS los dueños que la fila muestra, porque pueden ser varios,
			 * y sólo cuando hay sugerencia con dueño: «no sé» no es discrepar.
			 */
			$actuales = $this->attr_suggest_actuales[ strtolower( $name ) ] ?? array();
			$discrepa = ( '' !== $dueno && ! empty( $actuales ) && ! in_array( strtolower( $dueno ), array_map( 'strtolower', $actuales ), true ) );

			echo '<tr' . ( $discrepa ? ' class="wpopt-attr-mismatch"' : '' ) . '>';
			echo '<td><code class="wpopt-code-wrap">' . esc_html( $name ) . '</code></td>';
			echo '<td><span class="wpopt-attr-badge wpopt-attr-' . esc_attr( $lab[1] ) . '">' . esc_html( $lab[0] ) . '</span></td>';
			$mostrados = array();
			foreach ( $actuales as $a ) {
				$mostrados[] = $this->attr_suggest_owner_name( $a, (string) ( $this->attr_suggest_clases[ strtolower( $name ) ][ $a ] ?? '' ) );
			}
			echo '<td>' . ( ! empty( $mostrados ) ? esc_html( implode( ', ', $mostrados ) ) : '&mdash;' ) . '</td>';
			echo '<td>' . ( '' !== $dueno ? esc_html( $etiqueta_dueno ) : '&mdash;' );
			if ( $discrepa ) {
				// El aviso va con palabra Y con color, nunca sólo con color.
				echo ' <strong class="wpopt-attr-mismatch-flag">' . esc_html__( '— does not match', 'wpoptimizing' ) . '</strong>';
			}
			echo '</td>';
			echo '<td class="wpopt-attr-why">' . esc_html( $this->attr_suggest_reason( $sug ) ) . '</td>';
			echo '<td>';
			if ( '' !== $dueno && in_array( $verd, array( 'certain', 'strong', 'medium' ), true ) ) {
				echo '<form method="post" class="wpopt-attr-accept">';
				wp_nonce_field( 'wpopt_attr_accept' );
				echo '<input type="hidden" name="wpopt_attr_accept" value="1" />';
				echo '<input type="hidden" name="wpopt_attr_name" value="' . esc_attr( $name ) . '" />';
				echo '<input type="hidden" name="wpopt_attr_slug" value="' . esc_attr( $dueno ) . '" />';
				echo '<input type="hidden" name="wpopt_attr_type" value="' . esc_attr( $clase ) . '" />';
				echo '<button type="submit" class="button button-primary button-small">' . esc_html__( 'Accept', 'wpoptimizing' ) . '</button>';
				echo '</form>';
			} else {
				echo '<span class="wpopt-attr-muted-text">' . esc_html__( 'review manually', 'wpoptimizing' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}
}
