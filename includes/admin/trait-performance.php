<?php
/**
 * Performance assistant (TTFB): a dedicated tab that diagnoses the server response
 * time and, for every fix WPOptimizing can perform itself, offers a one-click button
 * that reuses the existing, nonce-protected action handlers (reversible). Things the
 * plugin cannot change (WP-Cron on the system, PHP/OPcache/host) are shown as guidance.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Composed into WPOPT_Admin_UI. Relies on members already provided by the class/other
 * traits: $nonce_field, $nonce_action, wpopt_ttfb_signals(), get_cache_settings(),
 * render_section_header(), render_empty_section_notice().
 */
trait WPOptimizing_Performance_Trait {

	/**
	 * Render the Performance (TTFB) assistant tab.
	 *
	 * @return void
	 */
	/**
	 * CSS & JavaScript (Speed group): minify, delay JS, critical CSS. It was Performance ›
	 * Browser until the navigation was regrouped (UX audit, 15/10/2026): these transform the
	 * page the browser gets, so they sit with Cache and Smart Assets under Speed, and
	 * Performance is left as the diagnosis — what happens and how much.
	 *
	 * @return void
	 */
	public function render_frontend_tab() {
		/*
		 * TWO SUB-SECTIONS: what the browser runs (CSS & JavaScript: minify, delay JS, critical
		 * CSS) and how the page is loaded and delivered (speculative loading, images, preload,
		 * embeds, hints, fonts, CDN). The second was Settings › Front-end performance until
		 * 15/10/2026: speed options that lived in Settings, apart from every other speed option.
		 */
		/*
		 * ONE PANEL PER SUB-SECTION (16/10/2026): minify, delay JS, critical CSS and plugins by URL
		 * were four forms on one screen, each with its own save button, and an edit in one was
		 * lost by pressing the button of another.
		 */
		$fsecs = array( 'assets', 'loading' );
		$fsec = isset( $_GET['fsec'] ) ? sanitize_key( wp_unslash( $_GET['fsec'] ) ) : 'assets'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( ! in_array( $fsec, $fsecs, true ) ) {
			$fsec = 'assets';
		}
		echo '<ul class="subsubsub wpopt-subsubsub">';
		$this->subsection_menu_link( 'frontend', 'fsec', 'assets', __( 'Minification', 'wpoptimizing' ), $fsec );
		echo ' | ';
		$this->subsection_menu_link( 'frontend', 'fsec', 'loading', __( 'Loading & delivery', 'wpoptimizing' ), $fsec );
		echo '</ul><br class="clear" />';

		if ( 'loading' === $fsec ) {
			$loading_intro = __( 'How the page reaches the visitor: speculative loading, image loading, resource hints and a Server-Timing diagnostic. All optional and reversible; none of them cache whole pages.', 'wpoptimizing' );
			$this->render_section_header(
				__( 'Loading & delivery', 'wpoptimizing' ),
				$loading_intro,
				array(
					'icon' => 'controls-forward',
					'doc'  => 'wpopt-doc-frontend',
				)
			);
			echo '<form method="post" action="' . esc_url(
				add_query_arg(
					array(
						'page' => 'wpoptimizing',
						'tab'  => 'frontend',
						'fsec' => 'loading',
					),
					admin_url( 'admin.php' )
				)
			) . '">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( wp_create_nonce( $this->nonce_action ) ) . '" />';
			echo '<table class="form-table wpopt-table" role="presentation"><tbody>';
			$this->render_frontend_perf_rows();
			echo '</tbody></table>';
			submit_button( __( 'Save front-end settings', 'wpoptimizing' ), 'primary wpopt-save-btn', 'wpopt_frontend_save' );
			echo '</form>';
			return;
		}

		// Retraso de JS y CSS crítico no viajan en Lite.
		$cssjs_desc = __( 'What the visitor’s browser downloads and runs: minified copies of your own CSS and JavaScript.', 'wpoptimizing' );
		$this->render_section_header(
			__( 'CSS & JavaScript', 'wpoptimizing' ),
			$cssjs_desc,
			array(
				'icon' => 'editor-code',
				'doc'  => 'wpopt-doc-performance',
			)
		);
		$this->render_asset_optimize_panel();
	}

	public function render_performance_tab() {
		// Dedicated report view (read-only navigation, no state change): the detailed
		// breakdown lives on its own screen so the assistant stays compact and the report
		// is deep-linkable, bookmarkable and printable.

		// Retraso de JS y CSS crítico no viajan en Lite.
		$assistant_desc = __( 'Diagnose your server response time (TTFB) and apply the fixes WPOptimizing can do for you in one click. Steps the plugin cannot perform (system cron, PHP/OPcache, hosting) are shown as guidance. Minification is under Speed › Page & browser › CSS & JavaScript.', 'wpoptimizing' );
		$this->render_section_header(
			__( 'Performance assistant', 'wpoptimizing' ),
			$assistant_desc,
			array(
				'icon' => 'performance',
				'doc'  => 'wpopt-doc-performance',
			)
		);

		/*
		 * TWO SUB-SECTIONS, like the Cache tab: summary (where you are: server TTFB, its trend,
		 * PageSpeed) and server (how to lower TTFB: the levers, then the deep analysis). The
		 * browser side moved to its own tab, CSS & JavaScript, under Speed; old ?psec=browser
		 * links land there (see the tab resolution in class-admin-ui.php).
		 */
		$psec = isset( $_GET['psec'] ) ? sanitize_key( wp_unslash( $_GET['psec'] ) ) : 'summary'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( ! in_array( $psec, array( 'summary', 'server' ), true ) ) {
			$psec = 'summary';
		}
		echo '<ul class="subsubsub wpopt-subsubsub">';
		$this->subsection_menu_link( 'performance', 'psec', 'summary', __( 'Summary', 'wpoptimizing' ), $psec );
		echo ' | ';
		$this->subsection_menu_link( 'performance', 'psec', 'server', __( 'Server', 'wpoptimizing' ), $psec );
		echo '</ul><br class="clear" />';

		if ( 'server' === $psec ) {
			$this->render_section_header(
				__( 'Server response time', 'wpoptimizing' ),
				__( 'What WordPress spends before it starts sending the page. The levers WPOptimizing can apply come first; the ones that need server access follow as guidance.', 'wpoptimizing' ),
				array(
					'icon' => 'hourglass',
					'doc'  => 'wpopt-doc-performance',
				)
			);
			$this->render_perf_levers();
			$this->render_db_redis_health();
			return;
		}

		$ttfb = function_exists( 'wpoptimizing_server_ttfb_ms' ) ? wpoptimizing_server_ttfb_ms() : 0;

		// --- Diagnosis ---------------------------------------------------------
		if ( $ttfb <= 0 ) {
			$this->render_empty_section_notice( __( 'No TTFB measurement yet. Let some front-end traffic flow (or run an analysis) and the server response time will appear here, sampled automatically.', 'wpoptimizing' ) );
		} else {
			$level       = function_exists( 'wpoptimizing_ttfb_level' ) ? wpoptimizing_ttfb_level( $ttfb ) : 'unknown';
			$level_label = array(
				'good' => __( 'Good', 'wpoptimizing' ),
				'ok'   => __( 'Improvable', 'wpoptimizing' ),
				'high' => __( 'High', 'wpoptimizing' ),
			);
			$badge       = isset( $level_label[ $level ] )
				? '<span class="wpopt-ttfb-badge wpopt-ttfb-badge--' . esc_attr( $level ) . '">' . esc_html( $level_label[ $level ] ) . '</span>'
				: '';
			echo '<div class="wpopt-perf-diag">';
			echo '<div class="wpopt-perf-diag-num"><strong>' . (int) $ttfb . ' ms</strong> ' . $badge . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge pre-escaped.
			echo '<p class="description">' . esc_html__( 'Time WordPress takes to start sending the page — the server share of TTFB (not the visitor network).', 'wpoptimizing' ) . '</p>';
			// One-line verdict from the context-aware plan.
			if ( function_exists( 'wpoptimizing_ttfb_action_plan' ) && ( 'high' === $level || 'ok' === $level ) ) {
				$plan = wpoptimizing_ttfb_action_plan( $ttfb, $this->wpopt_ttfb_signals() );
				if ( ! empty( $plan ) ) {
					echo '<p class="wpopt-perf-verdict">' . esc_html( (string) $plan[0]['text'] ) . '</p>';
				}
			} elseif ( 'good' === $level ) {
				echo '<p class="wpopt-perf-verdict">' . esc_html__( 'Your server response time is good. Keep the levers below enabled to stay there.', 'wpoptimizing' ) . '</p>';
			}
			echo '</div>';
		}

		// --- TTFB evolution chart ---------------------------------------------
		// Per-day server-TTFB averages over time, so the trend (improving / worsening) is
		// visible at a glance. Same sparkline component as History and Cache analytics.
		$ttfb_days = function_exists( 'wpoptimizing_ttfb_day_series' ) ? wpoptimizing_ttfb_day_series() : array();
		if ( count( $ttfb_days ) > 1 && method_exists( $this, 'build_sparkline_svg' ) ) {
			$labels = array_keys( $ttfb_days );
			$series = array_map(
				static function ( $v ) {
					return (float) $v;
				},
				array_values( $ttfb_days )
			);
			$first  = (string) $labels[0];
			$last   = (string) $labels[ count( $labels ) - 1 ];
			$fmt    = static function ( $ymd ) {
				$ymd = (string) $ymd;
				return strlen( $ymd ) >= 8 ? substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 ) : $ymd;
			};
			// Lower TTFB is better: green when the latest day improved on the first, red when
			// it got worse, neutral when flat.
			$delta = (int) end( $series ) - (int) reset( $series );
			$color = ( $delta < -10 ) ? '#008a20' : ( ( $delta > 10 ) ? '#d63638' : '#646970' );

			$this->render_subsection_header( __( 'Server TTFB over time', 'wpoptimizing' ) );
			echo '<div class="wpopt-cards-grid">';
			echo '<div class="wpopt-card">';
			echo '<div class="wpopt-card-label">' . esc_html__( 'Server TTFB (daily average)', 'wpoptimizing' ) . '</div>';
			echo '<div class="wpopt-card-num">' . esc_html( (int) end( $series ) . ' ms' ) . '</div>';
			echo $this->build_sparkline_svg( $series, $color ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped inline SVG.
			echo '<div class="wpopt-card-sub">' . esc_html( $fmt( $first ) . ' → ' . $fmt( $last ) ) . '</div>';
			echo '</div>';
			echo '</div>';
		}

		// --- PageSpeed Insights (real-world scores from Google) -----------------
		$this->render_pagespeed_panel();
	}

	/**
	 * The server levers: the ones WPOptimizing applies in one click first, then the guidance
	 * ones (system cron, hosting). Performance > Server.
	 *
	 * @return void
	 */
	protected function render_perf_levers() {
		// --- One-click levers --------------------------------------------------
		$sig      = $this->wpopt_ttfb_signals();
		$nonce    = wp_create_nonce( $this->nonce_action );
		$base     = admin_url( 'admin.php?page=wpoptimizing&tab=performance&psec=server' );
		$cache_on = ! empty( $sig['cache_active'] );
		$db_on    = isset( $GLOBALS['wpdb'] ) && is_a( $GLOBALS['wpdb'], 'WPOpt_DB' );
		$autoload = (int) $sig['autoload_bytes'];
		$hb       = method_exists( $this, 'get_cache_settings' ) ? (string) $this->get_cache_settings()['heartbeat'] : 'default';
		$cron_web = ! empty( $sig['wp_cron_on_request'] );

		echo '<ul class="wpopt-perf-levers">';

		// 1) Persistent object cache.
		$this->render_perf_lever(
			array_merge(
				array(
					'title'        => __( 'Persistent object cache', 'wpoptimizing' ),
					'desc'         => __( 'Keeps expensive database queries in memory — the single biggest server-TTFB win.', 'wpoptimizing' ),
					'done'         => $cache_on,
					'done_label'   => __( 'Active', 'wpoptimizing' ),
					'action_field' => 'wpopt_cache_install',
					'action_value' => '1',
					'action_label' => __( 'Enable now', 'wpoptimizing' ),
					'nonce'        => $nonce,
					'base'         => $base,
				),
				$cache_on ? array() : $this->perf_lever_foreign_dropin( 'object', 'wpopt_cache_replace' )
			)
		);

		// 2) Query deduplication.
		$this->render_perf_lever(
			array_merge(
				array(
					'title'        => __( 'Query deduplication', 'wpoptimizing' ),
					'desc'         => __( 'Collapses identical database queries repeated within the same request.', 'wpoptimizing' ),
					'done'         => $db_on,
					'done_label'   => __( 'Active', 'wpoptimizing' ),
					'action_field' => 'wpopt_cache_db_install',
					'action_value' => '1',
					'action_label' => __( 'Enable now', 'wpoptimizing' ),
					'nonce'        => $nonce,
					'base'         => $base,
				),
				$db_on ? array() : $this->perf_lever_foreign_dropin( 'db', 'wpopt_cache_db_replace' )
			)
		);

		// 3) Autoload weight.
		$autoload_ok = ( $autoload > 0 && $autoload <= 819200 );
		$this->render_perf_lever(
			array(
				'title'        => __( 'Autoload weight', 'wpoptimizing' ),
				'desc'         => $autoload > 0
					/* translators: %s: human-readable autoload size. */
					? sprintf( __( 'Options loaded on every request: %s (target under 800 KB). Smart Deactivation turns off autoload for options owned by uninstalled plugins.', 'wpoptimizing' ), size_format( $autoload, 1 ) )
					: __( 'Options loaded on every request. Smart Deactivation turns off autoload for options owned by uninstalled plugins.', 'wpoptimizing' ),
				'done'         => $autoload_ok,
				'done_label'   => __( 'Healthy', 'wpoptimizing' ),
				'action_field' => 'wpopt_autoload_smart',
				'action_value' => '1',
				'action_label' => __( 'Smart Deactivation', 'wpoptimizing' ),
				'confirm'      => __( 'Smart Deactivation will turn off autoload for options confidently attributed to plugins that are no longer installed. This is reversible. Continue?', 'wpoptimizing' ),
				'nonce'        => $nonce,
				'base'         => $base,
			)
		);

		// 4) Heartbeat.
		$this->render_perf_lever(
			array(
				'title'        => __( 'WordPress Heartbeat', 'wpoptimizing' ),
				'desc'         => __( 'The admin Heartbeat fires admin-ajax every minute. Slowing or disabling it lowers PHP/DB load during active sessions.', 'wpoptimizing' ),
				'done'         => ( 'off' === $hb || 'slow' === $hb ),
				'done_label'   => ( 'off' === $hb ) ? __( 'Disabled', 'wpoptimizing' ) : __( 'Slowed', 'wpoptimizing' ),
				'action_field' => 'wpopt_perf_heartbeat',
				'action_value' => 'off',
				'action_label' => __( 'Disable Heartbeat', 'wpoptimizing' ),
				'nonce'        => $nonce,
				'base'         => $base,
			)
		);

		// 5) WP-Cron (guidance only — needs wp-config + system cron).
		$this->render_perf_lever(
			array(
				'title'      => __( 'WP-Cron on visitor requests', 'wpoptimizing' ),
				'desc'       => __( 'By default WordPress runs scheduled tasks during visits, which can add to TTFB. Define DISABLE_WP_CRON in wp-config.php and add a real system cron that hits wp-cron.php every few minutes. This needs server access, so WPOptimizing does not change it for you.', 'wpoptimizing' ),
				'done'       => ! $cron_web,
				'done_label' => __( 'On system cron', 'wpoptimizing' ),
				'guidance'   => true,
			)
		);

		// 6) Server / host (guidance only).
		$php_ver    = PHP_VERSION;
		$opcache_on = false;
		if ( function_exists( 'opcache_get_status' ) ) {
			$st         = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$opcache_on = is_array( $st ) && ! empty( $st['opcache_enabled'] );
		}
		$this->render_perf_lever(
			array(
				'title'      => __( 'Server & hosting', 'wpoptimizing' ),
				'desc'       => sprintf(
					/* translators: 1: PHP version, 2: OPcache status (on/off). */
					__( 'PHP %1$s · OPcache %2$s. If the levers above are all green and TTFB is still high, the remaining time is your server: a modern PHP version, OPcache enabled, enough hosting resources and a light theme matter most. WPOptimizing cannot change these.', 'wpoptimizing' ),
					esc_html( $php_ver ),
					$opcache_on ? esc_html__( 'enabled', 'wpoptimizing' ) : esc_html__( 'disabled', 'wpoptimizing' )
				),
				'done'       => $opcache_on,
				'done_label' => __( 'OPcache on', 'wpoptimizing' ),
				'guidance'   => true,
			)
		);

		echo '</ul>';

		$this->render_heartbeat_zones_form( $base, $nonce );
	}

	/**
	 * The Heartbeat of each zone: admin screens, editors and front end (admin-cache.php). The
	 * cache form's single select is a preset over these.
	 *
	 * @param string $base  Form action URL.
	 * @param string $nonce Nonce value.
	 * @return void
	 */
	protected function render_heartbeat_zones_form( $base, $nonce ) {
		if ( ! function_exists( 'wpoptimizing_heartbeat_zones' ) ) {
			return;
		}
		$zones = wpoptimizing_heartbeat_zones( (array) get_option( 'wpoptimizing_cache_settings', array() ) );
		$this->render_subsection_header(
			__( 'Heartbeat by area', 'wpoptimizing' ),
			__( 'How often the browser calls admin-ajax.php in each area. In the editor it is autosave and the edit lock, so it can be slowed (up to 120 seconds, before locks expire) but not switched off.', 'wpoptimizing' )
		);
		echo '<form method="post" action="' . esc_url( (string) $base ) . '">';
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( (string) $nonce ) . '" />';
		echo '<input type="hidden" name="tab" value="performance" />';
		echo '<input type="hidden" name="wpopt_return_tab" value="performance" />';
		echo '<table class="form-table wpopt-table"><tbody>';
		foreach ( array(
			'admin'  => __( 'Admin screens', 'wpoptimizing' ),
			'editor' => __( 'Editor (posts, pages and Site Editor)', 'wpoptimizing' ),
			'front'  => __( 'Front end (logged-in users)', 'wpoptimizing' ),
		) as $zone => $label ) {
			$current = $zones[ $zone ];
			$choices = array( 'default' => __( 'Default', 'wpoptimizing' ) );
			if ( 'editor' !== $zone ) {
				$choices['off'] = __( 'Off', 'wpoptimizing' );
			}
			$seconds = array( 15, 30, 45, 60, 90, 120, 180, 240, 300 );
			if ( is_int( $current ) && ! in_array( $current, $seconds, true ) ) {
				// An imported value off the usual steps (LiteSpeed accepts any number) is shown as is.
				$seconds[] = $current;
				sort( $seconds );
			}
			foreach ( $seconds as $sec ) {
				if ( $sec <= wpoptimizing_heartbeat_max_interval( $zone ) ) {
					/* translators: %d: number of seconds between two Heartbeat requests. */
					$choices[ (string) $sec ] = sprintf( __( 'Every %d seconds', 'wpoptimizing' ), $sec );
				}
			}
			$id = 'wpopt-hb-zone-' . $zone;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<select name="wpopt_hb_zone[' . esc_attr( $zone ) . ']" id="' . esc_attr( $id ) . '" class="wpopt-select2 wpopt-field-std">';
			foreach ( $choices as $val => $text ) {
				echo '<option value="' . esc_attr( (string) $val ) . '"' . selected( (string) $current, (string) $val, false ) . '>' . esc_html( $text ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p><button type="submit" name="wpopt_perf_heartbeat_zones" value="1" class="button button-primary">' . esc_html__( 'Save settings', 'wpoptimizing' ) . '</button></p>';
		echo '</form>';
	}

	/**
	 * Save the Heartbeat zones. Partial update of the cache settings: only the zone keys change,
	 * and wpoptimizing_heartbeat_before_save() rewrites the preset word from them. Verified by
	 * maybe_handle_post's shared nonce before this runs.
	 *
	 * @return void
	 */
	protected function save_heartbeat_zones() {
		if ( ! current_user_can( wpoptimizing_cap() ) || ! function_exists( 'wpoptimizing_heartbeat_zone_value' ) || ! method_exists( $this, 'get_cache_settings' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified by the shared action handler.
		$posted   = isset( $_POST['wpopt_hb_zone'] ) && is_array( $_POST['wpopt_hb_zone'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['wpopt_hb_zone'] ) ) : array();
		$settings = $this->get_cache_settings();
		foreach ( wpoptimizing_heartbeat_zone_keys() as $zone => $key ) {
			if ( isset( $posted[ $zone ] ) ) {
				$settings[ $key ] = wpoptimizing_heartbeat_zone_value( $posted[ $zone ], $zone );
			}
		}
		update_option( 'wpoptimizing_cache_settings', $settings, false );
		if ( function_exists( 'wpoptimizing_audit_log' ) && function_exists( 'wpoptimizing_heartbeat_zones' ) ) {
			wpoptimizing_audit_log( 'heartbeat_set', array( 'zones' => wpoptimizing_heartbeat_zones( $settings ) ) );
		}
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_perf',
				'msg'  => __( 'Heartbeat updated.', 'wpoptimizing' ),
				'type' => 'updated',
			),
			60
		);
	}

	/**
	 * Render the PageSpeed Insights panel: a run button (mobile/desktop), the last result
	 * (score + Core Web Vitals) and an optional API-key field. The test makes one outbound
	 * request to Google's public PSI API with the site's URL; it changes nothing on the site.
	 *
	 * @return void
	 */
	protected function render_pagespeed_panel() {
		if ( ! function_exists( 'wpoptimizing_psi_run' ) ) {
			return;
		}
		$this->render_subsection_header(
			__( 'PageSpeed Insights', 'wpoptimizing' ),
			__( 'Fetch your Google PageSpeed score and Core Web Vitals. This sends your site URL to Google’s public API and changes nothing on your site.', 'wpoptimizing' )
		);
		$base  = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'performance',
				'psec' => 'summary',
			),
			admin_url( 'admin.php' )
		);
		$nonce = wp_create_nonce( $this->nonce_action );
		echo '<form method="post" action="' . esc_url( $base ) . '" class="wpopt-prose wpopt-btn-row">';
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
		echo '<input type="hidden" name="tab" value="performance" />';
		$pages      = function_exists( 'wpoptimizing_psi_template_urls' ) ? wpoptimizing_psi_template_urls() : array( 'home' => home_url( '/' ) );
		$page_names = $this->psi_page_labels();
		echo '<label for="wpopt_psi_page" class="screen-reader-text">' . esc_html__( 'Page to test', 'wpoptimizing' ) . '</label>';
		echo '<select name="wpopt_psi_page" id="wpopt_psi_page">';
		foreach ( $pages as $key => $url ) {
			printf( '<option value="%s" title="%s">%s</option>', esc_attr( $key ), esc_attr( $url ), esc_html( $page_names[ $key ] ?? $key ) );
		}
		echo '</select> ';
		echo '<button type="submit" name="wpopt_psi_run" value="mobile" class="button button-primary">' . esc_html__( 'Test mobile', 'wpoptimizing' ) . '</button> ';
		echo '<button type="submit" name="wpopt_psi_run" value="desktop" class="button">' . esc_html__( 'Test desktop', 'wpoptimizing' ) . '</button>';
		echo '</form>';

		$res = get_option( 'wpoptimizing_psi_result', null );
		if ( is_array( $res ) && isset( $res['score'] ) ) {
			$grade = wpoptimizing_psi_grade( $res['score'] );
			$strat = ( isset( $res['strategy'] ) && 'desktop' === $res['strategy'] ) ? __( 'desktop', 'wpoptimizing' ) : __( 'mobile', 'wpoptimizing' );
			echo '<div class="wpopt-cards-grid">';
			echo '<div class="wpopt-card wpopt-psi-score wpopt-psi-score--' . esc_attr( $grade ) . '">';
			echo '<div class="wpopt-card-num">' . esc_html( null === $res['score'] ? '—' : (string) (int) $res['score'] ) . '<span class="wpopt-card-sub">/100</span></div>';
			echo '<div class="wpopt-card-label">' . esc_html( sprintf( /* translators: %s: mobile or desktop. */ __( 'Performance (%s)', 'wpoptimizing' ), $strat ) ) . '</div>';
			echo '</div>';
			$labels = array(
				'lcp' => __( 'LCP', 'wpoptimizing' ),
				'inp' => __( 'INP', 'wpoptimizing' ),
				'cls' => __( 'CLS', 'wpoptimizing' ),
				'fcp' => __( 'FCP', 'wpoptimizing' ),
				'tbt' => __( 'TBT', 'wpoptimizing' ),
				'si'  => __( 'Speed Index', 'wpoptimizing' ),
			);
			$lab    = isset( $res['lab'] ) && is_array( $res['lab'] ) ? $res['lab'] : array();
			foreach ( $lab as $k => $v ) {
				if ( ! isset( $labels[ $k ] ) ) {
					continue;
				}
				echo '<div class="wpopt-card"><div class="wpopt-card-num">' . esc_html( (string) $v ) . '</div><div class="wpopt-card-label">' . esc_html( $labels[ $k ] ) . '</div></div>';
			}
			echo '</div>';
			if ( ! empty( $res['lcp_element'] ) && is_array( $res['lcp_element'] ) ) {
				echo '<p><strong>' . esc_html__( 'Largest element (LCP):', 'wpoptimizing' ) . '</strong> <code>' . esc_html( (string) ( $res['lcp_element']['snippet'] ?: $res['lcp_element']['selector'] ) ) . '</code></p>';
				$img = (string) ( $res['lcp_element']['image'] ?? '' );
			}
			if ( ! empty( $res['field'] ) && is_array( $res['field'] ) && ! empty( $res['field']['overall'] ) ) {
				echo '<p class="description">' . esc_html__( 'Field data (real visitors, last 28 days) is included when Google has enough traffic for your site.', 'wpoptimizing' ) . '</p>';
			}
			if ( ! empty( $res['ts'] ) ) {
				echo '<p class="description">' . esc_html(
					sprintf(
						/* translators: %s: human time-diff, e.g. "5 minutes". */
						__( 'Last tested %s ago.', 'wpoptimizing' ),
						human_time_diff( (int) $res['ts'], time() )
					)
				) . '</p>';
			}
		}

		$this->render_psi_history();
		$this->render_crux_history( $base, $nonce );

		// Optional API key (raises the keyless quota).
		echo '<form method="post" action="' . esc_url( $base ) . '" class="wpopt-prose">';
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
		echo '<input type="hidden" name="tab" value="performance" />';
		echo '<p><label for="wpopt_psi_key"><strong>' . esc_html__( 'PageSpeed API key (optional)', 'wpoptimizing' ) . '</strong></label><br />';
		// type=password: it is a quota-bearing credential, so it should not sit in plain
		// sight on a shared screen. autocomplete=new-password stops browsers and password
		// managers from treating it as a login field.
		echo '<input type="password" id="wpopt_psi_key" aria-describedby="wpopt_psi_key-desc" name="wpopt_psi_key" class="regular-text" value="' . esc_attr( wpoptimizing_psi_key() ) . '" autocomplete="new-password" spellcheck="false" /></p>';
		echo '<p id="wpopt_psi_key-desc" class="description">' . esc_html__( 'Not required. A free Google API key raises the request quota if you test often.', 'wpoptimizing' ) . '</p>';
		$this->render_psi_key_help();
		submit_button( __( 'Save API key', 'wpoptimizing' ), 'secondary wpopt-save-btn', 'wpopt_psi_key_save', false );
		echo '</form>';
	}

	/**
	 * Redis and MySQL, read from their own counters: findings when something is wrong, and the
	 * few figures that explain them.
	 *
	 * @return void
	 */
	protected function render_db_redis_health() {
		if ( ! function_exists( 'wpoptimizing_mysql_snapshot' ) ) {
			return;
		}
		$this->render_subsection_header(
			__( 'Database and Redis', 'wpoptimizing' ),
			__( 'What MySQL and, if you use it, Redis say about themselves: only what needs fixing, with the setting that fixes it. Most of these are changed by your host.', 'wpoptimizing' )
		);
		$mysql    = wpoptimizing_mysql_snapshot();
		$redis    = wpoptimizing_redis_info();
		$findings = wpoptimizing_mysql_findings( $mysql['vars'], $mysql['status'], (int) $mysql['innodb'] );
		if ( $redis ) {
			$findings = array_merge( wpoptimizing_redis_findings( $redis ), $findings );
		}
		if ( empty( $findings ) ) {
			echo '<p><span class="wpopt-status-badge wpopt-status-active">' . esc_html__( 'Nothing to fix', 'wpoptimizing' ) . '</span></p>';
		} else {
			echo '<ul class="wpopt-perf-findings">';
			foreach ( $findings as $f ) {
				$t     = wpoptimizing_server_finding_text( $f );
				$badge = 'high' === $f['severity'] ? 'wpopt-status-orphan' : 'wpopt-status-inactive';
				echo '<li><span class="wpopt-status-badge ' . esc_attr( $badge ) . '">' . esc_html( 'high' === $f['severity'] ? __( 'Important', 'wpoptimizing' ) : __( 'Worth a look', 'wpoptimizing' ) ) . '</span> <strong>' . esc_html( $t['title'] ) . '</strong><br /><span class="description">' . esc_html( $t['tip'] ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '<table class="widefat striped wpopt-table-full"><tbody>';
		$rows = array(
			__( 'Database server', 'wpoptimizing' )        => (string) ( $mysql['vars']['version'] ?? '—' ),
			__( 'InnoDB buffer pool', 'wpoptimizing' )     => isset( $mysql['vars']['innodb_buffer_pool_size'] ) ? size_format( (int) $mysql['vars']['innodb_buffer_pool_size'] ) : '—',
			__( 'Your InnoDB data', 'wpoptimizing' )       => size_format( (int) $mysql['innodb'] ),
			__( 'Connections used / max', 'wpoptimizing' ) => (int) ( $mysql['status']['max_used_connections'] ?? 0 ) . ' / ' . (int) ( $mysql['vars']['max_connections'] ?? 0 ),
		);
		if ( $redis ) {
			$hits                                  = (float) ( $redis['keyspace_hits'] ?? 0 );
			$misses                                = (float) ( $redis['keyspace_misses'] ?? 0 );
			$rows[ __( 'Redis', 'wpoptimizing' ) ] = (string) $redis['redis_version'];
			$rows[ __( 'Redis memory', 'wpoptimizing' ) ]          = size_format( (int) ( $redis['used_memory'] ?? 0 ) ) . ( ! empty( $redis['maxmemory'] ) ? ' / ' . size_format( (int) $redis['maxmemory'] ) : '' );
			$rows[ __( 'Redis eviction policy', 'wpoptimizing' ) ] = (string) ( $redis['maxmemory_policy'] ?? '—' );
			$rows[ __( 'Redis hit rate', 'wpoptimizing' ) ]        = ( $hits + $misses ) > 0 ? number_format_i18n( 100 * $hits / ( $hits + $misses ), 1 ) . ' %' : '—';
		}
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th class="wpopt-th-label" scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Human names of the kinds of page PageSpeed can test.
	 *
	 * @return array<string,string>
	 */
	protected function psi_page_labels() {
		return array(
			'home'    => __( 'Home page', 'wpoptimizing' ),
			'post'    => __( 'Latest post', 'wpoptimizing' ),
			'page'    => __( 'A page', 'wpoptimizing' ),
			'product' => __( 'Latest product', 'wpoptimizing' ),
			'shop'    => __( 'Shop', 'wpoptimizing' ),
			'archive' => __( 'Busiest category', 'wpoptimizing' ),
		);
	}

	/**
	 * The last tests, so a change can be judged against what came before it.
	 *
	 * @return void
	 */
	protected function render_psi_history() {
		$history = get_option( 'wpoptimizing_psi_history', array() );
		if ( ! is_array( $history ) || count( $history ) < 2 ) {
			return;
		}
		$names = $this->psi_page_labels();
		echo '<h4>' . esc_html__( 'Previous tests', 'wpoptimizing' ) . '</h4>';
		echo '<table class="widefat striped wpopt-table-full"><thead><tr><th>' . esc_html__( 'Date', 'wpoptimizing' ) . '</th><th>' . esc_html__( 'Page', 'wpoptimizing' ) . '</th><th>' . esc_html__( 'Device', 'wpoptimizing' ) . '</th><th>' . esc_html__( 'Score', 'wpoptimizing' ) . '</th><th>LCP</th><th>CLS</th><th>TBT</th></tr></thead><tbody>';
		foreach ( array_slice( $history, 0, 10 ) as $h ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $h['ts'] ) ),
				esc_html( $names[ $h['page'] ?? '' ] ?? (string) wp_parse_url( (string) $h['url'], PHP_URL_PATH ) ),
				esc_html( 'desktop' === ( $h['strategy'] ?? '' ) ? __( 'desktop', 'wpoptimizing' ) : __( 'mobile', 'wpoptimizing' ) ),
				esc_html( null === $h['score'] ? '—' : (string) (int) $h['score'] ),
				esc_html( null === $h['lcp'] ? '—' : number_format_i18n( (float) $h['lcp'] / 1000, 1 ) . ' s' ),
				esc_html( null === $h['cls'] ? '—' : number_format_i18n( (float) $h['cls'], 3 ) ),
				esc_html( null === $h['tbt'] ? '—' : number_format_i18n( (float) $h['tbt'] ) . ' ms' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Real-visitor trend from the Chrome UX Report: the 75th percentile of LCP, INP and CLS over
	 * the last weeks, first and last period side by side.
	 *
	 * @param string $base  Form action.
	 * @param string $nonce Nonce.
	 * @return void
	 */
	protected function render_crux_history( $base, $nonce ) {
		if ( ! function_exists( 'wpoptimizing_crux_history_run' ) ) {
			return;
		}
		echo '<h4>' . esc_html__( 'Real visitors over time (Chrome UX Report)', 'wpoptimizing' ) . '</h4>';
		$crux = get_option( 'wpoptimizing_crux_history', array() );
		if ( is_array( $crux ) && ! empty( $crux['metrics'] ) && ! empty( $crux['periods'] ) ) {
			$periods = (array) $crux['periods'];
			$first   = 0;
			$last    = count( $periods ) - 1;
			echo '<table class="widefat striped wpopt-table-full"><thead><tr><th></th><th>' . esc_html( (string) $periods[ $first ] ) . '</th><th>' . esc_html( (string) $periods[ $last ] ) . '</th></tr></thead><tbody>';
			$fmt = static function ( $key, $v ) {
				if ( null === $v ) {
					return '—';
				}
				return 'cls' === $key ? number_format_i18n( (float) $v, 2 ) : number_format_i18n( (float) $v ) . ' ms';
			};
			foreach ( array(
				'lcp' => 'LCP',
				'inp' => 'INP',
				'cls' => 'CLS',
			) as $key => $label ) {
				if ( empty( $crux['metrics'][ $key ] ) ) {
					continue;
				}
				$series = array_values( (array) $crux['metrics'][ $key ] );
				printf( '<tr><th scope="row">%s</th><td>%s</td><td>%s</td></tr>', esc_html( $label ), esc_html( $fmt( $key, $series[ $first ] ?? null ) ), esc_html( $fmt( $key, $series[ count( $series ) - 1 ] ?? null ) ) );
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Values are the 75th percentile of real Chrome visits to the whole site, in 28-day windows. Lower is better.', 'wpoptimizing' ) . '</p>';
		}
		echo '<form method="post" action="' . esc_url( $base ) . '" class="wpopt-prose">';
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
		echo '<input type="hidden" name="tab" value="performance" />';
		if ( '' === wpoptimizing_psi_key() ) {
			echo '<p class="description">' . esc_html__( 'Needs the API key below, with the Chrome UX Report API enabled for it in the Google Cloud console.', 'wpoptimizing' ) . '</p>';
		} else {
			submit_button( __( 'Load real-visitor history', 'wpoptimizing' ), 'secondary', 'wpopt_crux_run', false );
		}
		echo '</form>';
	}

	/**
	 * Fetch the CrUX history and flash the outcome.
	 *
	 * @return void
	 */
	protected function run_crux_history() {
		if ( ! current_user_can( wpoptimizing_cap() ) ) {
			$this->cache_flash( __( 'You do not have permission.', 'wpoptimizing' ), 'error' );
			return;
		}
		$res = wpoptimizing_crux_history_run();
		if ( is_wp_error( $res ) ) {
			$this->cache_flash( $res->get_error_message(), 'error' );
			return;
		}
		$this->cache_flash( __( 'Real-visitor history loaded.', 'wpoptimizing' ), 'updated' );
	}

	/**
	 * Render the "how to get a PageSpeed API key" help: one line with a direct link to the
	 * Google Cloud console plus the shared help icon, which opens the docs modal with the
	 * step-by-step (single source: WPOptimizing_Docs_Trait::psi_key_steps_html()). Purely
	 * informational — no request is made and nothing is stored.
	 *
	 * @return void
	 */
	protected function render_psi_key_help() {
		echo '<p class="description wpopt-desc-tight">';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: link to the Google Cloud console credentials page. */
				__( 'Get one free at the <a href="%s" target="_blank" rel="noopener noreferrer">Google Cloud console</a> — it takes a couple of minutes and no card is required.', 'wpoptimizing' ),
				esc_url( 'https://console.cloud.google.com/apis/credentials' )
			)
		);
		echo $this->doc_link( 'wpopt-doc-pagespeed' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link() returns pre-escaped markup.
		echo '</p>';
	}

	/**
	 * Run a PageSpeed Insights test and flash the outcome.
	 *
	 * @return void
	 */
	protected function run_pagespeed_test() {
		if ( ! current_user_can( wpoptimizing_cap() ) ) {
			$this->cache_flash( __( 'You do not have permission.', 'wpoptimizing' ), 'error' );
			return;
		}
		$strategy = isset( $_REQUEST['wpopt_psi_run'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpopt_psi_run'] ) ) : 'mobile'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by the shared action handler.
		$page     = isset( $_REQUEST['wpopt_psi_page'] ) ? sanitize_key( wp_unslash( $_REQUEST['wpopt_psi_page'] ) ) : 'home'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by the shared action handler.
		$urls     = wpoptimizing_psi_template_urls();
		$res      = wpoptimizing_psi_run( $urls[ $page ] ?? home_url( '/' ), $strategy );
		if ( is_wp_error( $res ) ) {
			$this->cache_flash( sprintf( /* translators: %s: error message. */ __( 'PageSpeed test failed: %s', 'wpoptimizing' ), $res->get_error_message() ), 'error' );
			return;
		}
		$this->cache_flash( __( 'PageSpeed test completed.', 'wpoptimizing' ), 'updated' );
	}


	/**
	 * Save the optional PageSpeed API key.
	 *
	 * @return void
	 */
	protected function save_pagespeed_key() {
		if ( ! current_user_can( wpoptimizing_cap() ) ) {
			$this->cache_flash( __( 'You do not have permission.', 'wpoptimizing' ), 'error' );
			return;
		}
		$key = isset( $_REQUEST['wpopt_psi_key'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['wpopt_psi_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by the shared action handler.
		update_option( 'wpoptimizing_psi_key', $key, false );
		$this->cache_flash( __( 'PageSpeed API key saved.', 'wpoptimizing' ), 'updated' );
	}

	/**
	 * Render the CSS/JS minification panel (Fase 3a): status/conflict + toggles.
	 *
	 * @return void
	 */
	protected function render_asset_optimize_panel() {
		if ( ! function_exists( 'wpoptimizing_assetmin_options' ) ) {
			return;
		}
		$opt      = wpoptimizing_assetmin_options();
		$conflict = wpoptimizing_assetmin_conflict();
		$nonce    = wp_create_nonce( $this->nonce_action );
		$base_url = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'frontend',
				'fsec' => 'assets',
			),
			admin_url( 'admin.php' )
		);
		$this->render_subsection_header(
			__( 'CSS & JavaScript minification', 'wpoptimizing' ),
			__( 'Serves minified copies of your local CSS and JS so they download faster. Safe and free: already-minified, external and excluded files are left untouched, and it never runs alongside another optimizer.', 'wpoptimizing' )
		);
		if ( '' !== $conflict ) {
			echo '<div class="notice notice-warning inline wpopt-prose"><p>'
				. esc_html(
					sprintf(
						/* translators: %s: detected asset-optimizer plugin name. */
						__( 'Another asset optimizer is active (%s). WPOptimizing minification stays off to avoid conflicts.', 'wpoptimizing' ),
						$conflict
					)
				)
				. '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-prose">';
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
		echo '<input type="hidden" name="tab" value="performance" />';
		printf( '<p><label><input type="checkbox" name="wpopt_am_css" value="1" %s /> %s</label></p>', checked( ! empty( $opt['minify_css'] ), true, false ), esc_html__( 'Minify CSS', 'wpoptimizing' ) );
		printf( '<p><label><input type="checkbox" name="wpopt_am_js" value="1" %s /> %s</label></p>', checked( ! empty( $opt['minify_js'] ), true, false ), esc_html__( 'Minify JavaScript', 'wpoptimizing' ) );
		$this->render_test_mode_toggle( 'name="wpopt_am_test" value="1"', ! empty( $opt['test_mode'] ) );
		$excl = implode( "\n", (array) ( isset( $opt['exclude'] ) ? $opt['exclude'] : array() ) );
		echo '<p><label for="wpopt_am_exclude"><strong>' . esc_html__( 'Exclude these handles or URL fragments (one per line)', 'wpoptimizing' ) . '</strong></label><br />';
		echo '<textarea id="wpopt_am_exclude" name="wpopt_am_exclude" rows="3" class="large-text code">' . esc_textarea( $excl ) . '</textarea></p>';

		// Fase 3b — Defer JavaScript (premium). Lite has neither the controls nor the saver.
		$is_premium = false;
		submit_button( __( 'Save minification settings', 'wpoptimizing' ), 'primary wpopt-save-btn', 'wpopt_assetmin_save', false );
		echo '</form>';
		if ( ! $is_premium ) {
			// Lite: una nota neutra, sin candado; el enlace a Pro lo añade render_pro_note().
			$pro_note_text = __( 'JavaScript deferral, asynchronous CSS loading and inlined critical CSS are not included in this plugin.', 'wpoptimizing' );
			$this->render_pro_note( $pro_note_text );
		}
	}



	/**
	 * Persist the CSS/JS minification settings and clear the minified cache.
	 *
	 * @return void
	 */
	protected function save_asset_optimize() {
		if ( ! current_user_can( wpoptimizing_cap() ) ) {
			$this->cache_flash( __( 'You do not have permission.', 'wpoptimizing' ), 'error' );
			return;
		}
		$opt               = (array) get_option( 'wpoptimizing_asset_optimize', array() );
		$opt['minify_css'] = isset( $_REQUEST['wpopt_am_css'] );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified by the shared action handler.
		$opt['minify_js']  = isset( $_REQUEST['wpopt_am_js'] );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$opt['test_mode']  = isset( $_REQUEST['wpopt_am_test'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw               = isset( $_REQUEST['wpopt_am_exclude'] ) ? (string) wp_unslash( $_REQUEST['wpopt_am_exclude'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line (split + sanitize_text_field).
		$opt['exclude']    = array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'trim', (array) preg_split( '/[\r\n]+/', $raw ) ) ) ) ); // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- our own settings key, not a WP_Query/get_posts() argument.
		update_option( 'wpoptimizing_asset_optimize', $opt, true );
		if ( function_exists( 'wpoptimizing_assetmin_clear' ) ) {
			wpoptimizing_assetmin_clear();
		}
		$this->cache_flash( __( 'Minification settings saved.', 'wpoptimizing' ), 'updated' );
	}


	/**
	 * What a server lever does when another plugin's drop-in holds the slot.
	 *
	 * «Enable now» only ever asked to install, so with Redis Object Cache's object-cache.php or
	 * Query Monitor's db.php in place it answered «WPOptimizing will not overwrite it» and nothing
	 * happened, also after choosing ours in Settings > Compatibility; and a working Redis showed
	 * as a missing cache (the WP Total Updater site, 27/10/2026). When the slot can be
	 * taken, the button replaces it, keeping the original, after the same warning as the Cache
	 * tab. When it cannot and another persistent cache is working, the lever is done.
	 *
	 * @param string $kind  'object' | 'db'.
	 * @param string $field Replace action field.
	 * @return array Lever spec keys to override.
	 */
	protected function perf_lever_foreign_dropin( $kind, $field ) {
		if ( ! function_exists( 'wpoptimizing_dropin_path' ) || ! function_exists( 'wpoptimizing_dropin_owner' ) ) {
			return array();
		}
		$path   = wpoptimizing_dropin_path( $kind );
		$marker = 'db' === $kind ? 'WPOPT_DB_DROPIN' : 'WPOPT_OBJECT_CACHE';
		if ( '' === $path || ! file_exists( $path ) || $this->dropin_is_ours( $path, $marker ) ) {
			return array();
		}
		$owner = wpoptimizing_dropin_owner( $path );
		$name  = '' !== (string) $owner['name'] ? (string) $owner['name'] : __( 'the other plugin', 'wpoptimizing' );
		$can   = wpoptimizing_dropin_can_replace( $kind, $owner );
		if ( $can['ok'] ) {
			return array(
				'action_field' => $field,
				'confirm'      => sprintf(
					/* translators: 1: file name, 2: plugin name. */
					__( 'WPOptimizing can take its place: the current %1$s is kept and put back when you disable this. Meanwhile, whatever %2$s does with that file stops.', 'wpoptimizing' ),
					basename( $path ),
					$name
				),
			);
		}
		if ( 'object' === $kind && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
			return array(
				'done'       => true,
				/* translators: %s: plugin name. */
				'done_label' => sprintf( __( 'Active with %s', 'wpoptimizing' ), $name ),
			);
		}
		return array();
	}

	/**
	 * Render one assistant lever row: a status marker, title/description, and either a
	 * one-click action form (reusing an existing handler via $action_field) or, for
	 * guidance-only items, no button.
	 *
	 * @param array $a Lever spec.
	 * @return void
	 */
	protected function render_perf_lever( array $a ) {
		$done     = ! empty( $a['done'] );
		$guidance = ! empty( $a['guidance'] );
		$state    = $done ? 'done' : ( $guidance ? 'info' : 'todo' );

		echo '<li class="wpopt-perf-lever wpopt-perf-lever--' . esc_attr( $state ) . '">';
		echo wpoptimizing_icon( $done ? 'yes-alt' : ( $guidance ? 'info-outline' : 'warning' ), 'wpopt-perf-lever-mark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
		echo '<span class="wpopt-perf-lever-body">';
		echo '<strong class="wpopt-perf-lever-title">' . esc_html( (string) $a['title'] ) . '</strong>';
		echo '<span class="wpopt-perf-lever-desc">' . esc_html( (string) $a['desc'] ) . '</span>';
		echo '</span>';

		echo '<span class="wpopt-perf-lever-action">';
		if ( $done ) {
			echo '<span class="wpopt-perf-lever-ok">' . esc_html( isset( $a['done_label'] ) ? (string) $a['done_label'] : __( 'Done', 'wpoptimizing' ) ) . '</span>';
		} elseif ( ! $guidance && ! empty( $a['action_field'] ) ) {
			$confirm = isset( $a['confirm'] ) ? ' onclick="return confirm(' . esc_attr( wp_json_encode( (string) $a['confirm'] ) ) . ')"' : '';
			echo '<form method="post" action="' . esc_url( (string) $a['base'] ) . '" class="wpopt-perf-lever-form">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( (string) $a['nonce'] ) . '" />';
			echo '<input type="hidden" name="tab" value="performance" />';
			echo '<input type="hidden" name="wpopt_return_tab" value="performance" />';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $confirm is built from esc_attr(wp_json_encode()).
			echo '<button type="submit" name="' . esc_attr( (string) $a['action_field'] ) . '" value="' . esc_attr( isset( $a['action_value'] ) ? (string) $a['action_value'] : '1' ) . '" class="button button-primary"' . $confirm . '>' . esc_html( (string) $a['action_label'] ) . '</button>';
			echo '</form>';
		} else {
			echo '<span class="wpopt-perf-lever-guide">' . esc_html__( 'Manual step', 'wpoptimizing' ) . '</span>';
		}
		echo '</span>';
		echo '</li>';
	}

	/**
	 * One-click Heartbeat change from the Performance assistant. Safe partial update:
	 * reads the stored cache settings, changes only the heartbeat key, saves. Verified
	 * by maybe_handle_post's shared nonce before this runs.
	 *
	 * @param string $mode default|slow|off
	 * @return void
	 */
	protected function apply_heartbeat_mode( $mode ) {
		$mode = in_array( $mode, array( 'default', 'slow', 'off' ), true ) ? $mode : 'off';
		if ( ! method_exists( $this, 'get_cache_settings' ) ) {
			return;
		}
		$settings = $this->get_cache_settings();
		// The lever is a preset: it sets every zone (admin-cache.php).
		$settings = function_exists( 'wpoptimizing_heartbeat_with_preset' ) ? wpoptimizing_heartbeat_with_preset( $settings, $mode ) : array_merge( $settings, array( 'heartbeat' => $mode ) );
		update_option( 'wpoptimizing_cache_settings', $settings, false );
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'heartbeat_set', array( 'mode' => $mode ) );
		}
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_perf',
				'msg'  => ( 'off' === $mode ) ? __( 'Heartbeat disabled.', 'wpoptimizing' ) : __( 'Heartbeat updated.', 'wpoptimizing' ),
				'type' => 'updated',
			),
			60
		);
	}
}
