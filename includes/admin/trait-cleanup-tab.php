<?php
/**
 * "General cleanup" tab (profiles, options, stats postbox) and the "Pending" review tab.
 *
 * Extracted from class-admin-ui.php into a trait composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Cleanup_Tab_Trait {

	/**
	 * Render a two-column stats table inside a postbox.
	 *
	 * @param array<int,array{0:string,1:string}> $rows Label/value pairs.
	 * @return void
	 */
	protected function render_gc_stats_table( array $rows ) {
		echo '<table class="widefat striped wpopt-table-borderless">';
		echo '<tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row[0] ) . '</td><td><strong>' . esc_html( $row[1] ) . '</strong></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Open a postbox card.
	 *
	 * @param string $icon    Dashicon slug (without prefix).
	 * @param string $title   Card heading.
	 * @return void
	 */
	protected function render_gc_postbox_open( $icon, $title ) {
		echo '<div class="postbox">';
		echo '<div class="postbox-header"><h2 class="hndle">';
		echo wpoptimizing_icon( $icon, 'wpopt-heading-icon' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
		echo esc_html( $title );
		echo '</h2></div>';
		echo '<div class="inside">';
	}

	/**
	 * Close a postbox card.
	 *
	 * @return void
	 */
	protected function render_gc_postbox_close() {
		echo '</div>'; // .inside
		echo '</div>'; // .postbox
	}

	/**
	 * Render the Pending tab: items with no reliable attribution yet.
	 *
	 * @param array  $bd_none       DB rows with project_status = 'none'.
	 * @param array  $fs_none       FS rows with project_status = 'none'.
	 * @param string $section       Current osec value ('bd' or 'fs').
	 * @param int    $last_scan_ts  Timestamp of last scan (0 = never).
	 * @return void
	 */
	/**
	 * Live owner search for the manual-attribution picker. Merges three sources so
	 * the user pins the EXACT plugin/theme (correct slug + type) even when it is no
	 * longer installed, instead of the alimentador having to guess later:
	 *   1) installed plugins/themes, 2) the public wp.org repository (by name),
	 *   3) the shared KB (cached slug→name map).
	 * Returns Select2-format results: [ { id: "type|slug|name", text: "Name (slug) · source" } ].
	 *
	 * @param string $term Search term.
	 * @return array<int,array{id:string,text:string}>
	 */
	public function owner_search( $term ) {
		$term = trim( (string) $term );
		if ( '' === $term ) {
			return array();
		}
		$results = array();
		$seen    = array();
		$add     = static function ( $type, $slug, $name, $source ) use ( &$results, &$seen ) {
			$slug = sanitize_title( (string) $slug );
			$type = ( 'theme' === $type ) ? 'theme' : 'plugin';
			if ( '' === $slug ) {
				return;
			}
			$key = $type . '|' . $slug;
			if ( isset( $seen[ $key ] ) ) {
				return;
			}
			$seen[ $key ] = true;
			$name         = self::plain_owner_name( $name, $slug );
			$results[]    = array(
				'id'   => $type . '|' . $slug . '|' . $name,
				'text' => sprintf( '%s (%s) · %s', $name, $slug, $source ),
			);
		};

		// 1) Installed.
		$inst = __( 'installed', 'wpoptimizing' );
		foreach ( $this->get_all_plugins_by_slug() as $slug => $name ) {
			if ( false !== stripos( $slug . ' ' . $name, $term ) ) {
				$add( 'plugin', $slug, $name, $inst );
			}
		}
		foreach ( $this->get_all_themes_by_slug() as $slug => $name ) {
			if ( false !== stripos( $slug . ' ' . $name, $term ) ) {
				$add( 'theme', $slug, $name, $inst );
			}
		}

		// 2) Public wp.org repository (graceful no-op when offline / wp_error).
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}
		$pa = plugins_api(
			'query_plugins',
			array(
				'search'   => $term,
				'per_page' => 8,
				'fields'   => array(
					'sections' => false,
					'icons'    => false,
				),
			)
		);
		if ( ! is_wp_error( $pa ) && ! empty( $pa->plugins ) ) {
			foreach ( $pa->plugins as $p ) {
				$p = (array) $p;
				$add( 'plugin', $p['slug'] ?? '', $p['name'] ?? '', 'wp.org' );
			}
		}
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			require_once ABSPATH . 'wp-admin/includes/theme-install.php';
		}
		$ta = themes_api(
			'query_themes',
			array(
				'search'   => $term,
				'per_page' => 6,
				'fields'   => array( 'sections' => false ),
			)
		);
		if ( ! is_wp_error( $ta ) && ! empty( $ta->themes ) ) {
			foreach ( $ta->themes as $t ) {
				$t = (array) $t;
				$add( 'theme', $t['slug'] ?? '', $t['name'] ?? '', 'wp.org' );
			}
		}

		// 3) Shared KB (slug→name; type unknown, default to plugin — the dominant case,
		// and any wp.org/installed match for the same slug already took precedence).
		if ( method_exists( $this, 'load_remote_maps' ) ) {
			$this->load_remote_maps();
		}
		if ( is_array( $this->remote_slug_name_map ) ) {
			$kb = 0;
			foreach ( $this->remote_slug_name_map as $slug => $name ) {
				if ( $kb >= 15 ) {
					break;
				}
				if ( false !== stripos( $slug . ' ' . $name, $term ) ) {
					$add( 'plugin', $slug, $name, __( 'KB', 'wpoptimizing' ) );
					++$kb;
				}
			}
		}

		// Free-text fallback: always offer the typed term as a slug (plugin or theme),
		// so an owner that is not installed and not on wp.org/KB can still be attributed.
		$free = sanitize_title( $term );
		if ( '' !== $free ) {
			/* translators: %s: the slug the user typed. */
			$plugin_label = sprintf( __( 'Use “%s” as a plugin slug', 'wpoptimizing' ), $free );
			/* translators: %s: the slug the user typed. */
			$theme_label = sprintf( __( 'Use “%s” as a theme slug', 'wpoptimizing' ), $free );
			$results[]   = array(
				'id'   => 'plugin|' . $free . '|' . $term,
				'text' => $plugin_label,
			);
			$results[]   = array(
				'id'   => 'theme|' . $free . '|' . $term,
				'text' => $theme_label,
			);
		}

		return $results;
	}

	/**
	 * An owner's display name as plain text. wp.org returns plugin names as HTML
	 * ("Yoast SEO &#8211; Advanced SEO…") and Select2 shows its text as-is, so the entity
	 * reached the owner picker raw; the name is also stored in the rule.
	 *
	 * @param string $name Name as the source gave it.
	 * @param string $slug Fallback when the name is empty.
	 * @return string
	 */
	protected static function plain_owner_name( $name, $slug ) {
		$name = trim( html_entity_decode( wp_strip_all_tags( (string) $name ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return '' !== $name ? $name : (string) $slug;
	}

	/**
	 * Manual attribution panel: define rules (exact value or "contains" substring)
	 * that assign Pending items to a plugin/theme, plus the existing-rules list and
	 * the live owner picker. Applied at display time so matched items leave Pending
	 * without a re-scan.
	 *
	 * @param string $cb_name Checkbox group name of the current section's list table
	 *                        (bd_selected[] / fs_selected[]), for "Attribute selected".
	 * @return void
	 */
	protected function render_manual_attribution_panel( $cb_name = 'bd_selected[]', $osec = 'bd', $tab = 'pending' ) {
		if ( ! function_exists( 'wpoptimizing_manual_attributions' ) ) {
			return;
		}
		$osec      = in_array( $osec, array( 'bd', 'fs' ), true ) ? $osec : 'bd';
		$tab       = in_array( $tab, array( 'pending', 'orphans', 'inactive', 'active' ), true ) ? $tab : 'pending';
		$all_rules = wpoptimizing_manual_attributions();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view state.
		$ma_prefill = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$ma_s       = isset( $_REQUEST['marules_s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['marules_s'] ) ) : '';
		$ma_ext     = isset( $_REQUEST['marules_ext'] ) ? sanitize_title( wp_unslash( $_REQUEST['marules_ext'] ) ) : '';
		$ma_pp      = isset( $_REQUEST['marules_pp'] ) ? (int) $_REQUEST['marules_pp'] : 10;
		$is_open    = ( '' !== $ma_s || ( '' !== $ma_ext && 'all' !== $ma_ext ) || isset( $_REQUEST['marules_paged'] ) || isset( $_REQUEST['marules_pp'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $ma_pp, array( 10, 25, 50, 100, 200 ), true ) ) {
			$ma_pp = 10;
		}
		if ( 'all' === $ma_ext ) {
			$ma_ext = '';
		}

		// Closed by default; opens only when interacting with the rules list (the
		// "Attribute selected" button opens it via JS).
		echo '<details class="wpopt-manual-attrib"' . ( $is_open ? ' open' : '' ) . '>';
		echo '<summary class="wpopt-manual-attrib-summary">' . esc_html__( 'Attribute manually', 'wpoptimizing' );
		if ( ! empty( $all_rules ) ) {
			echo ' <span class="wpopt-count">(' . esc_html( number_format_i18n( count( $all_rules ) ) ) . ')</span>';
		}
		echo ' ' . $this->doc_link( 'wpopt-doc-manual-attrib' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- doc_link() returns pre-escaped HTML; clicking it opens the modal (preventDefault stops the <summary> toggle).
		echo '</summary>';
		echo '<div class="wpopt-manual-attrib-body">';
		echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Tell WPOptimizing who really owns an item when the automatic attribution is wrong or missing. Use “contains” for a family of items (e.g. wysija → MailPoet) or “exact” for one specific value, and pick one or more owners (e.g. MailPoet and MailPoet Premium). Your rule takes priority and the item moves to In use, Inactive or Orphans by whether the owner is installed.', 'wpoptimizing' ) . '</p>';

		echo '<form method="post" class="wpopt-manual-attrib-form" data-cbname="' . esc_attr( $cb_name ) . '">';
		wp_nonce_field( 'wpopt_manual_attrib' );
		echo '<input type="hidden" name="wpopt_manual_attrib" value="1">';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';

		// Rows picked via the table checkboxes land here as hidden wpopt_ma_matches[]
		// inputs (one exact rule per row × owner). Filled by JS; hidden until used.
		echo '<div class="wpopt-ma-selected" hidden>';
		echo '<span class="wpopt-ma-selected-head">' . esc_html__( 'Selected rows', 'wpoptimizing' ) . ' <span class="wpopt-count wpopt-ma-selected-count">0</span> <button type="button" class="button-link wpopt-ma-clear">' . esc_html__( 'Clear', 'wpoptimizing' ) . '</button></span>';
		echo '<span class="wpopt-ma-chips"></span>';
		echo '<span class="description wpopt-ma-selected-note">' . esc_html__( 'These rows will be attributed (exact) to the owner(s) you pick below.', 'wpoptimizing' ) . '</span>';
		echo '</div>';

		echo '<div class="wpopt-ma-grid">';
		// Match row.
		echo '<div class="wpopt-ma-row">';
		echo '<select name="wpopt_ma_scope" class="wpopt-select2-auto" aria-label="' . esc_attr__( 'Match type', 'wpoptimizing' ) . '">';
		echo '<option value="contains">' . esc_html__( 'Contains', 'wpoptimizing' ) . '</option>';
		echo '<option value="exact">' . esc_html__( 'Exact value', 'wpoptimizing' ) . '</option>';
		echo '</select>';
		printf(
			'<input type="text" name="wpopt_ma_match" class="regular-text wpopt-ma-match" aria-label="%1$s" placeholder="%2$s" value="%3$s" />',
			esc_attr__( 'Text to match', 'wpoptimizing' ),
			esc_attr__( 'text to match (e.g. wysija)', 'wpoptimizing' ),
			esc_attr( $ma_prefill )
		);
		echo '</div>';
		// Owner row: multi-select (installed + wp.org + KB) + type for free-typed owners.
		echo '<div class="wpopt-ma-row">';
		echo '<span class="wpopt-ma-owner-wrap"><select name="wpopt_ma_owner[]" class="wpopt-select2 wpopt-select2-ajax wpopt-ma-owner" multiple data-nonce="' . esc_attr( wp_create_nonce( 'wpoptimizing_owner_search' ) ) . '" data-placeholder="' . esc_attr__( 'Owner(s): search installed, wp.org or KB — or type a slug', 'wpoptimizing' ) . '" aria-label="' . esc_attr__( 'Owners', 'wpoptimizing' ) . '"></select></span>';
		echo '<select name="wpopt_ma_type" class="wpopt-select2-auto" aria-label="' . esc_attr__( 'Type for a typed owner', 'wpoptimizing' ) . '">';
		echo '<option value="plugin">' . esc_html__( 'Plugin', 'wpoptimizing' ) . '</option>';
		echo '<option value="theme">' . esc_html__( 'Theme', 'wpoptimizing' ) . '</option>';
		echo '</select>';
		echo '</div>';
		// Actions row. ONE adaptive submit button: it reads "Add rule" when no table rows
		// are selected (you attribute by the match field above), and switches to
		// "Attribute selected (N)" the moment rows are picked from the table (chips present).
		// The table toolbar's own "Attribute selected" button only LOADS the checked rows
		// into this form; the actual save happens here, so the label never promises an
		// action it doesn't perform. On submit, any rows still checked in the table are
		// folded in automatically (see render_manual_attrib_picker_js).
		echo '<div class="wpopt-ma-row wpopt-ma-row-actions">';
		printf(
			'<button type="submit" class="button button-primary wpopt-ma-submit" data-label-add="%1$s" data-label-attr="%2$s">%1$s</button>',
			esc_attr__( 'Add rule', 'wpoptimizing' ),
			esc_attr__( 'Attribute selected', 'wpoptimizing' )
		);
		echo '</div>';
		// Inline validation message: shown by JS when the form is incomplete; the submit is
		// blocked (no page reload) until a match and an owner are present.
		echo '<div class="wpopt-ma-error notice notice-error inline" role="alert" hidden><p></p></div>';
		echo '</div>';
		echo '</form>';

		if ( ! empty( $all_rules ) ) {
			// Owner options for the filter (slug => label).
			$owner_opts = array();
			foreach ( $all_rules as $r ) {
				$nombre                   = $this->manual_rule_owner_name( $r );
				$owner_opts[ $r['slug'] ] = $nombre !== $r['slug'] ? $nombre . ' (' . $r['slug'] . ')' : $r['slug'];
			}
			asort( $owner_opts );

			// Filter to the visible set, tagging each rule with its identity key (used by the
			// per-row Remove link). The list table itself handles per-page + pagination.
			$needle = strtolower( $ma_s );
			$rows   = array();
			foreach ( $all_rules as $r ) {
				if ( '' !== $ma_ext && $r['slug'] !== $ma_ext ) {
					continue;
				}
				if ( '' !== $needle && false === strpos( strtolower( $r['match'] . ' ' . $r['name'] . ' ' . $this->manual_rule_owner_name( $r ) . ' ' . $r['slug'] ), $needle ) ) {
					continue;
				}
				$r['key'] = wpoptimizing_manual_attribution_key( $r );
				$rows[]   = $r;
			}

			// Carry the Pending table's own state (its search/filter/page) across rules
			// search/filter/paging, since the list form rebuilds the query string.
			$hidden = array( 'osec' => $osec );
			foreach ( $_GET as $gk => $gv ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- preserving read-only view state.
				if ( ! is_scalar( $gv ) || in_array( (string) $gk, array( 'marules_s', 'marules_ext', 'marules_pp', 'marules_paged', 'filter_action', 'page', 'tab', 'osec', 'wpopt_ma_remove', '_manonce', '_wpoptimizing_nonce' ), true ) ) {
					continue;
				}
				$hidden[ (string) $gk ] = wp_unslash( (string) $gv );
			}

			// Reuse the shared list-table framework: same search box, owner filter,
			// per-page selector and pagination as every other section.
			$rules_table = new WPOptimizing_Manual_Rules_List_Table(
				$this,
				$rows,
				false,
				false,
				array(
					'scope'             => 'marules',
					'per_page'          => $ma_pp,
					'search_param'      => 'marules_s',
					'project_filter'    => $ma_ext,
					'project_choices'   => $owner_opts,
					'project_all_label' => __( 'All owners', 'wpoptimizing' ),
					'hidden_fields'     => $hidden,
				)
			);
			$rules_table->prepare_items();
			$this->render_table_form( $rules_table, $tab, $osec, null, false, false );
		}
		echo '</div>';
		$this->render_manual_attrib_picker_js();
		echo '</details>';
	}

	/**
	 * One-time JS that feeds rows checked in the Pending list table into the manual
	 * attribution form as wpopt_ma_matches[] (one exact rule each). The bulk button
	 * ".wpopt-ma-pick" (rendered next to the table actions) carries data-cbname so we
	 * read the right checkbox group; DB values are "type::value", so we strip the
	 * "type::" prefix to attribute on the human value.
	 *
	 * @return void
	 */
	protected function render_manual_attrib_picker_js() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
		<?php ob_start(); ?>
		(function(){
			function panel(){ return document.querySelector('.wpopt-manual-attrib'); }
			function form(){ var p=panel(); return p?p.querySelector('.wpopt-manual-attrib-form'):null; }
			function gather(cbname){
				var out=[];
				document.querySelectorAll('input[type=checkbox][name="'+cbname+'"]:checked').forEach(function(b){
					var v=b.value, sep=v.indexOf('::');
					out.push(sep!==-1?v.slice(sep+2):v);
				});
				return out;
			}
			// Keep every "Attribute selected (N)" button in sync with the live tick count
			// of its checkbox group, and enable/disable accordingly.
			function refreshPicks(){
				document.querySelectorAll('.wpopt-ma-pick').forEach(function(btn){
					var cb=btn.getAttribute('data-cbname');
					var n=cb?document.querySelectorAll('input[type=checkbox][name="'+cb+'"]:checked').length:0;
					var c=btn.querySelector('.wpopt-ma-pick-count'); if(c){ c.textContent=String(n); }
					btn.disabled=(n===0);
				});
			}
			function sync(){
				var f=form(); if(!f){return;}
				var wrap=f.querySelector('.wpopt-ma-selected'), chips=f.querySelector('.wpopt-ma-chips');
				var n=chips?chips.querySelectorAll('input[name="wpopt_ma_matches[]"]').length:0;
				if(wrap){ wrap.hidden=(n===0); }
				var cnt=f.querySelector('.wpopt-ma-selected-count'); if(cnt){ cnt.textContent=String(n); }
				f.classList.toggle('wpopt-ma-has-selection', n>0);
				refreshSubmits();
			}
			// Keep the panel's single submit button labelled for what it will do: "Add rule"
			// when attributing by the match field, "Attribute selected (N)" once rows are picked.
			function refreshSubmits(){
				document.querySelectorAll('.wpopt-manual-attrib-form').forEach(function(f){
					var n=f.querySelectorAll('input[name="wpopt_ma_matches[]"]').length;
					var sub=f.querySelector('.wpopt-ma-submit');
					if(sub){ sub.textContent = n>0 ? (sub.getAttribute('data-label-attr')+' ('+n+')') : sub.getAttribute('data-label-add'); }
				});
			}
			function addChip(chips, val){
				if(!val){return;}
				// Skip duplicates already present.
				var ex=chips.querySelectorAll('input[name="wpopt_ma_matches[]"]');
				for(var i=0;i<ex.length;i++){ if(ex[i].value===val){return;} }
				var chip=document.createElement('span'); chip.className='wpopt-ma-chip';
				var txt=document.createElement('span'); txt.className='wpopt-ma-chip-text'; txt.textContent=val; chip.appendChild(txt);
				var inp=document.createElement('input'); inp.type='hidden'; inp.name='wpopt_ma_matches[]'; inp.value=val; chip.appendChild(inp);
				var x=document.createElement('button'); x.type='button'; x.className='wpopt-ma-chip-x'; x.setAttribute('aria-label',<?php echo wp_json_encode( __( 'Remove', 'wpoptimizing' ) ); ?>); x.textContent='×'; chip.appendChild(x);
				chips.appendChild(chip);
			}
			document.addEventListener('click', function(e){
				var pick=e.target.closest('.wpopt-ma-pick');
				if(pick){
					e.preventDefault();
					var f=form(); if(!f){return;}
					var chips=f.querySelector('.wpopt-ma-chips'); if(!chips){return;}
					var vals=gather(pick.getAttribute('data-cbname'));
					if(!vals.length){ window.alert(<?php echo wp_json_encode( __( 'Select at least one row first.', 'wpoptimizing' ) ); ?>); return; }
					vals.forEach(function(v){ addChip(chips, v); });
					sync();
					var p=panel(); if(p){ p.open=true; }
					var owner=f.querySelector('.wpopt-ma-owner'); if(owner){ try{ owner.focus(); }catch(_){} }
					f.scrollIntoView({behavior:'smooth', block:'center'});
					return;
				}
				var rm=e.target.closest('.wpopt-ma-chip-x');
				if(rm){ e.preventDefault(); var c=rm.closest('.wpopt-ma-chip'); if(c){ c.parentNode.removeChild(c); } sync(); return; }
				var clr=e.target.closest('.wpopt-ma-clear');
				if(clr){ e.preventDefault(); var f2=form(); if(f2){ var ch=f2.querySelector('.wpopt-ma-chips'); if(ch){ ch.innerHTML=''; } sync(); } return; }
			});
			// On submit, fold in any rows still ticked in the table that weren't loaded via
			// the "Attribute selected" button — so checking rows then hitting the submit
			// straight away still attributes them (no silent no-op).
			function showErr(f, msg){
				var box=f.querySelector('.wpopt-ma-error'); if(!box){ window.alert(msg); return; }
				var p=box.querySelector('p')||box; p.textContent=msg; box.hidden=false;
				try{ box.scrollIntoView({behavior:'smooth', block:'center'}); }catch(_){}
			}
			function clearErr(f){ var box=f.querySelector('.wpopt-ma-error'); if(box){ box.hidden=true; } }
			document.addEventListener('submit', function(e){
				var f=e.target;
				if(!f||!f.classList||!f.classList.contains('wpopt-manual-attrib-form')){ return; }
				// Fold in any rows still ticked in the table that weren't loaded via the
				// "Attribute selected" button, so they are attributed too.
				var chips=f.querySelector('.wpopt-ma-chips'), cb=f.getAttribute('data-cbname');
				if(chips&&cb){ gather(cb).forEach(function(v){ addChip(chips, v); }); sync(); }
				var n=chips?chips.querySelectorAll('input[name="wpopt_ma_matches[]"]').length:0;
				var mi=f.querySelector('input[name="wpopt_ma_match"]');
				var hasMatch=n>0||(mi&&mi.value.trim()!=='');
				var owner=f.querySelector('.wpopt-ma-owner');
				var hasOwner=owner&&owner.querySelectorAll('option:checked').length>0;
				// Block the submit (no reload) and show an inline error until complete.
				if(!hasMatch){ e.preventDefault(); e.stopImmediatePropagation(); showErr(f, <?php echo wp_json_encode( __( 'Pick at least one row or type a value to match first.', 'wpoptimizing' ) ); ?>); return; }
				if(!hasOwner){ e.preventDefault(); e.stopImmediatePropagation(); showErr(f, <?php echo wp_json_encode( __( 'Choose at least one owner for the rule.', 'wpoptimizing' ) ); ?>); if(owner){ try{ owner.focus(); }catch(_){} } return; }
				clearErr(f);
			}, true);
			// Live count: any checkbox toggle (incl. WP's "select all", which sets
			// .checked without firing change on each row) refreshes the picker buttons.
			document.addEventListener('change', function(e){ if(e.target&&e.target.type==='checkbox'){ setTimeout(refreshPicks,0); } });
			document.addEventListener('click', function(e){ if(e.target&&e.target.type==='checkbox'){ setTimeout(refreshPicks,0); } });
			function initPanel(){ refreshPicks(); refreshSubmits(); }
			if(document.readyState!=='loading'){ initPanel(); } else { document.addEventListener('DOMContentLoaded', initPanel); }
		})();
		<?php wp_print_inline_script_tag( (string) ob_get_clean() ); ?>
		<?php
	}

	/**
	 * Handle a manual-attribution add (self-POST) or remove (nonced GET) BEFORE the orphan
	 * split is computed. Because the split resolves each row's owner through the freshly
	 * saved rules, doing this first means the Pending badge, the sub-nav counts AND the
	 * table all reflect the change in the SAME request (no reload needed). The result is
	 * surfaced through the shared flash (set_pending_flash), so it shows at the top of
	 * whichever tab the action ran on — Pending, Orphans, Inactive or Active.
	 *
	 * @return void
	 */
	protected function preprocess_manual_attribution() {
		if ( ! function_exists( 'wpoptimizing_manual_attribution_add' ) || ! current_user_can( wpoptimizing_cap() ) ) {
			return;
		}

		// Remove a rule (nonced GET link from the rules list table). The link carries the
		// rule's identity, so a link rendered before some other removal either hits the rule
		// the owner clicked or nothing at all — it can no longer hit whichever rule inherited
		// that position.
		if ( isset( $_GET['wpopt_ma_remove'], $_GET['_manonce'] ) && function_exists( 'wpoptimizing_manual_attribution_remove' ) ) {
			$rm_key = sanitize_key( wp_unslash( $_GET['wpopt_ma_remove'] ) );
			if ( '' !== $rm_key && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_manonce'] ) ), 'wpopt_ma_remove_' . $rm_key ) ) {
				if ( wpoptimizing_manual_attribution_remove( $rm_key ) ) {
					$this->set_pending_flash(
						array(
							'code' => 'wpopt_ma_removed',
							'msg'  => esc_html__( 'Attribution rule removed; the affected items were re-evaluated.', 'wpoptimizing' ),
							'type' => 'success',
						)
					);
				} else {
					// Stale link (the rule was already removed, here or in another tab). Say so
					// rather than failing silently, which used to look like a broken button.
					$this->set_pending_flash(
						array(
							'code' => 'wpopt_ma_gone',
							'msg'  => esc_html__( 'That attribution rule no longer exists; nothing was removed.', 'wpoptimizing' ),
							'type' => 'info',
						)
					);
				}
			}
		}

		// Add (self-POST).
		if ( isset( $_POST['wpopt_manual_attrib'] ) ) {
			check_admin_referer( 'wpopt_manual_attrib' );
			// Owners: multi-select. Each value is "type|slug|name" (installed/wp.org/KB) or
			// a free-typed slug (then the Type select decides). A value can be attributed to
			// SEVERAL owners at once (e.g. wysija → MailPoet AND MailPoet Premium) — one rule
			// per (match × owner).
			$owners_raw = isset( $_POST['wpopt_ma_owner'] ) ? (array) wp_unslash( $_POST['wpopt_ma_owner'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitized below.
			$free_type  = ( isset( $_POST['wpopt_ma_type'] ) && 'theme' === $_POST['wpopt_ma_type'] ) ? 'theme' : 'plugin';
			$owners     = array();
			foreach ( $owners_raw as $raw ) {
				$raw = sanitize_text_field( (string) $raw );
				if ( '' === $raw ) {
					continue;
				}
				if ( false !== strpos( $raw, '|' ) ) {
					$parts    = explode( '|', $raw, 3 );
					$owners[] = array(
						'type' => ( 'theme' === ( $parts[0] ?? '' ) ) ? 'theme' : 'plugin',
						'slug' => sanitize_title( (string) ( $parts[1] ?? '' ) ),
						'name' => (string) ( $parts[2] ?? '' ),
					);
				} else {
					$owners[] = array(
						'type' => $free_type,
						'slug' => sanitize_title( $raw ),
						'name' => $raw,
					);
				}
			}
			$owners = array_values(
				array_filter(
					$owners,
					static function ( $o ) {
						return '' !== $o['slug'];
					}
				)
			);

			// Matches: picked rows (exact, one per row) take precedence over the single
			// match field.
			if ( isset( $_POST['wpopt_ma_matches'] ) && is_array( $_POST['wpopt_ma_matches'] ) ) {
				$matches = array_values( array_filter( array_unique( array_map( 'sanitize_text_field', wp_unslash( $_POST['wpopt_ma_matches'] ) ) ) ) );
				$scope   = 'exact';
			} else {
				$single  = isset( $_POST['wpopt_ma_match'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_ma_match'] ) ) : '';
				$matches = ( '' !== $single ) ? array( $single ) : array();
				$scope   = ( isset( $_POST['wpopt_ma_scope'] ) && 'exact' === $_POST['wpopt_ma_scope'] ) ? 'exact' : 'contains';
			}

			$count = 0;
			foreach ( $matches as $m ) {
				foreach ( $owners as $o ) {
					if ( wpoptimizing_manual_attribution_add(
						array(
							'scope' => $scope,
							'match' => $m,
							'slug'  => $o['slug'],
							'name'  => $o['name'],
							'type'  => $o['type'],
						)
					) ) {
						++$count;
					}
				}
			}
			if ( $count > 0 ) {
				$msg = esc_html(
					sprintf(
					/* translators: %s: number of attribution rules saved. */
						_n( '%s manual attribution saved; the affected items were re-attributed.', '%s manual attributions saved; the affected items were re-attributed.', $count, 'wpoptimizing' ),
						number_format_i18n( $count )
					)
				);
				// Contextual growth nudge: they just contributed locally — the ideal moment to
				// ask them to share it, but only when sharing is off. Reset the periodic timer
				// so the top-of-page reminder does not also fire on the same load.
				if ( function_exists( 'wpoptimizing_has_share_consent' ) && ! wpoptimizing_has_share_consent() ) {
					if ( function_exists( 'wpoptimizing_share_nudge_interval' ) ) {
						update_option( 'wpoptimizing_share_nudge_snooze', time() + wpoptimizing_share_nudge_interval(), false );
					}
					$enable_url = wp_nonce_url( admin_url( 'admin.php?page=wpoptimizing&wpopt_enable_sharing=1' ), 'wpopt_enable_sharing' );
					$msg       .= ' ' . esc_html__( 'Sharing is off, so this hint stays on your site. Turn on data sharing to improve automatic attribution for everyone — and receive the shared dataset in return.', 'wpoptimizing' )
						. ' <a href="' . esc_url( $enable_url ) . '">' . esc_html__( 'Turn on sharing', 'wpoptimizing' ) . '</a>';
				}
				$this->set_pending_flash(
					array(
						'code' => 'wpopt_ma_added',
						'msg'  => $msg,
						'type' => 'success',
					)
				);
			} else {
				$this->set_pending_flash(
					array(
						'code' => 'wpopt_ma_none',
						'msg'  => esc_html__( 'No rules were added. Provide a value to match and at least one owner, and check the rule does not already exist.', 'wpoptimizing' ),
						'type' => 'warning',
					)
				);
			}
		}
	}

	protected function render_pending_tab( array $bd_none, array $fs_none, $section, $last_scan_ts ) {
		$osec = $section;
		if ( ! in_array( $osec, array( 'bd', 'fs' ), true ) ) {
			$osec = 'bd';
		}

		// The add/remove of attribution rules was already handled by
		// preprocess_manual_attribution() BEFORE the orphan split, so $bd_none/$fs_none,
		// the Pending badge and the sub-nav counts all already reflect it. Its result
		// notice is rendered page-wide via the shared flash (render_pending_flash), so
		// nothing extra is echoed here.

		// Subsection nav.
		echo '<ul class="subsubsub wpopt-subsubsub">';
		$this->pending_menu_link( 'bd', __( 'Database', 'wpoptimizing' ), $osec, count( $bd_none ) );
		echo ' | ';
		$this->pending_menu_link( 'fs', __( 'Files', 'wpoptimizing' ), $osec, count( $fs_none ) );
		echo '</ul>';
		echo '<br class="clear" />';

		if ( 'bd' === $osec ) {
			$this->render_section_header(
				__( 'Database', 'wpoptimizing' ),
				__( 'These database entries could not be attributed to any installed or known extension. They may belong to a plugin not yet in the manifest database. Review them manually before taking any action.', 'wpoptimizing' ),
				array(
					'icon' => 'database',
					'doc'  => 'wpopt-doc-pending',
				)
			);

			if ( 0 === $last_scan_ts ) {
				$this->render_scan_pending_notice();
				return;
			}
			if ( empty( $bd_none ) ) {
				$this->render_empty_section_notice( __( 'No unattributed records — everything WPOptimizing found is confidently attributed to an extension or to WordPress core.', 'wpoptimizing' ) );
				return;
			}

			$q = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Filter param names must match the list table's scope ('pending-bd'), since
			// the filter controls are named "{scope}_tipo|_ext|_pp". Reading db_* here
			// (the Orphans scope) was why the Pending filters silently did nothing.
			$pp = isset( $_REQUEST['pending-bd_pp'] ) ? (int) $_REQUEST['pending-bd_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
				$pp = 50;
			}
			$type_filter = '';
			if ( isset( $_REQUEST['pending-bd_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['pending-bd_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
					$type_filter = '';
				}
			}
			$type_choices = $this->collect_types( 'db', $bd_none );
			if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
				$type_filter = '';
			}
			$ext_filter = '';
			if ( isset( $_REQUEST['pending-bd_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['pending-bd_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
					$ext_filter = '';
				}
			}
			$project_choices = $this->collect_project_choices( $bd_none );
			if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
				$ext_filter = '';
			}

			$rows = $this->apply_tipo_filter_bd( $bd_none, $type_filter, $q );
			$rows = $this->apply_extension_filter( $rows, $ext_filter );
			$table = new WPOptimizing_DB_List_Table(
				$this,
				$rows,
				false,
				$this->should_show_projects_column( $rows ),
				array(
					'scope'           => 'pending-bd',
					'per_page'        => $pp,
					'type_filter'     => $type_filter,
					'type_choices'    => $type_choices,
					'project_filter'  => $ext_filter,
					'project_choices' => $project_choices,
					'hidden_fields'   => array(
						'tab'  => 'pending',
						'osec' => 'bd',
					),
				)
			);
			$table->prepare_items();
			$this->render_table_form( $table, 'pending', $osec );
			$this->render_manual_attribution_panel( 'bd_selected[]', 'bd', 'pending' );
			if ( method_exists( $this, 'render_attr_suggest_panel' ) ) {
				// The rows of THIS page, not the whole filter: on page 5 it used to send the first ones.
				$this->render_attr_suggest_panel( (array) $table->items );
			}
		} else {
			$this->render_section_header(
				__( 'Files', 'wpoptimizing' ),
				__( 'These files could not be attributed to any installed or known extension. They may belong to a plugin not yet in the manifest database. Review them manually before taking any action.', 'wpoptimizing' ),
				array(
					'icon' => 'media-default',
					'doc'  => 'wpopt-doc-pending',
				)
			);

			if ( 0 === $last_scan_ts ) {
				$this->render_scan_pending_notice();
				return;
			}
			if ( empty( $fs_none ) ) {
				$this->render_empty_section_notice();
				return;
			}

			$q = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// Param names must match the list table scope ('pending-fs'); see the bd note.
			$pp = isset( $_REQUEST['pending-fs_pp'] ) ? (int) $_REQUEST['pending-fs_pp'] : 50; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
				$pp = 50;
			}
			$type_filter = '';
			if ( isset( $_REQUEST['pending-fs_tipo'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['pending-fs_tipo'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
					$type_filter = '';
				}
			}
			$type_choices = $this->collect_types( 'fs', $fs_none );
			if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
				$type_filter = '';
			}
			$ext_filter = '';
			if ( isset( $_REQUEST['pending-fs_ext'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['pending-fs_ext'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
					$ext_filter = '';
				}
			}
			$project_choices = $this->collect_project_choices( $fs_none );
			if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
				$ext_filter = '';
			}

			$rows = $this->apply_tipo_filter_fs( $fs_none, $type_filter, $q );
			$rows = $this->apply_extension_filter( $rows, $ext_filter );
			$table = new WPOptimizing_FS_List_Table(
				$this,
				$rows,
				false,
				$this->should_show_projects_column( $rows ),
				array(
					'scope'           => 'pending-fs',
					'per_page'        => $pp,
					'type_filter'     => $type_filter,
					'type_choices'    => $type_choices,
					'project_filter'  => $ext_filter,
					'project_choices' => $project_choices,
					'hidden_fields'   => array(
						'tab'  => 'pending',
						'osec' => 'fs',
					),
				)
			);
			$table->prepare_items();
			$this->render_table_form( $table, 'pending', $osec );
			$this->render_manual_attribution_panel( 'fs_selected[]', 'fs', 'pending' );
		}
	}

	/**
	 * Empty-records tab: every database record whose value is empty, in ONE list with a Status
	 * column (Pending/Orphan/Inactive/Active) resolved by the attribution engine. Rows owned by
	 * an active extension or by WordPress core are shown read-only (the list table suppresses
	 * their checkbox). Same Free/Pro rules as the other DB sections (25 clear rows + blur).
	 *
	 * @param int $last_scan_ts Last scan timestamp (0 = never).
	 * @return void
	 */
	protected function render_empty_tab( $last_scan_ts ) {
		$this->render_section_header(
			__( 'Empty records', 'wpoptimizing' ),
			__( 'Database entries whose stored value is empty (NULL, an empty string, or an empty serialized/JSON structure). A grouped meta key is listed only when EVERY one of its records is empty, never when only some are. The Status column shows whether each one is To identify, Orphan, Inactive or In use. Records owned by an active extension or by WordPress core are read-only: they may be empty on purpose.', 'wpoptimizing' ),
			array(
				'icon' => 'marker',
				'doc'  => 'wpopt-doc-empty',
			)
		);

		if ( 0 === (int) $last_scan_ts ) {
			$this->render_scan_pending_notice();
			return;
		}

		// Read + attribute. The empty scanner writes rows unattributed, so first inherit the
		// attribution the main DB scan already computed for the same keys, then run the same
		// overlay used everywhere (which re-evaluates status and lets manual rules win). This
		// makes an empty record classify here exactly as it does in Pending/Orphans/etc.
		$rows = $this->overlay_runtime_attributions( $this->enrich_empty_from_main_scan( $this->read_empty_db() ) );
		if ( empty( $rows ) ) {
			$this->render_empty_section_notice( __( 'No empty records were found. Re-run the analysis after making changes to refresh this list.', 'wpoptimizing' ) );
			return;
		}

		// Filters (type + extension) and search, mirroring the other DB sections.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list view state.
		$q  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$pp = isset( $_REQUEST['empty_pp'] ) ? (int) $_REQUEST['empty_pp'] : 50;
		if ( ! in_array( $pp, array( 25, 50, 100, 200 ), true ) ) {
			$pp = 50;
		}
		$type_filter = '';
		if ( isset( $_REQUEST['empty_tipo'] ) ) {
			$type_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['empty_tipo'] ) ) );
			if ( '' === $type_filter || 'all' === strtolower( $type_filter ) ) {
				$type_filter = '';
			}
		}
		$ext_filter = '';
		if ( isset( $_REQUEST['empty_ext'] ) ) {
			$ext_filter = trim( sanitize_text_field( wp_unslash( $_REQUEST['empty_ext'] ) ) );
			if ( '' === $ext_filter || 'all' === strtolower( $ext_filter ) ) {
				$ext_filter = '';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$type_choices = $this->collect_types( 'ibd', $rows );
		if ( '' !== $type_filter && ! in_array( $type_filter, $type_choices, true ) ) {
			$type_filter = '';
		}
		$project_choices = $this->collect_project_choices( $rows );
		if ( '' !== $ext_filter && ! isset( $project_choices[ $ext_filter ] ) ) {
			$ext_filter = '';
		}

		$rows = $this->apply_tipo_filter_bd( $rows, $type_filter, $q );
		$rows = $this->apply_extension_filter( $rows, $ext_filter );


		$table = new WPOptimizing_Empty_List_Table(
			$this,
			$rows,
			false,
			$this->should_show_projects_column( $rows ),
			array(
				'scope'           => 'empty',
				'per_page'        => $pp,
				'type_filter'     => $type_filter,
				'type_choices'    => $type_choices,
				'project_filter'  => $ext_filter,
				'project_choices' => $project_choices,
				'hidden_fields'   => array( 'tab' => 'empty' ),
			)
		);
		$table->prepare_items();
		$this->render_table_form( $table, 'empty' );
		if ( function_exists( 'wpoptimizing_manual_attributions' ) ) {
			$this->render_manual_attribution_panel( $table->bulk_cb_name(), 'bd', 'empty' );
		}
	}

	/**
	 * Render a subsection nav link for the Pending tab.
	 *
	 * @param string $key   Subsection key ('bd' or 'fs').
	 * @param string $label Display label.
	 * @param string $curr  Active subsection key.
	 * @param int    $count Optional count shown in parentheses.
	 * @return void
	 */
	protected function pending_menu_link( $key, $label, $curr, $count = 0 ) {
		$base = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'pending',
				'osec' => $key,
			),
			admin_url( 'admin.php' )
		);

		$is_current = ( $curr === $key );
		printf(
			'<li><a href="%s" class="%s">%s%s</a></li>',
			esc_url( $base ),
			$is_current ? 'current' : '',
			esc_html( $label ),
			$count ? ' <span class="count">(' . esc_html( (string) $count ) . ')</span>' : ''
		);
	}

	/**
	 * Human labels for each general-cleanup estimate bucket. Single source shared by the
	 * always-on "what would be removed" summary and the dry-run preview table.
	 *
	 * @return array<string,string>
	 */
	protected function cleanup_estimate_labels() {
		return array(
			'revisions'        => __( 'Post revisions', 'wpoptimizing' ),
			'posts'            => __( 'Auto-drafts & trashed posts', 'wpoptimizing' ),
			'comments'         => __( 'Spam/trashed/other comments', 'wpoptimizing' ),
			'transients'       => __( 'Transients', 'wpoptimizing' ),
			'oembed'           => __( 'oEmbed caches', 'wpoptimizing' ),
			'tags'             => __( 'Unused tags', 'wpoptimizing' ),
			'orphans'          => __( 'Orphaned elements', 'wpoptimizing' ),
			'duplicate_meta'   => __( 'Duplicate metadata', 'wpoptimizing' ),
			'action_scheduler' => __( 'Action Scheduler logs', 'wpoptimizing' ),
		);
	}

	/**
	 * The note under an estimate some of whose counts the database cut short.
	 *
	 * @return void
	 */
	protected function render_estimate_partial_note() {
		echo '<p class="description wpopt-warn">' . esc_html__( 'Some of these counts did not finish (they took too long or the database returned an error), so the estimate is incomplete and the real figures may be higher. Preview the cleanup again later.', 'wpoptimizing' ) . '</p>';
	}

	/**
	 * Render the per-bucket estimate table (only non-zero rows), reused by the summary and
	 * the dry-run preview.
	 *
	 * @param array<string,int|string> $data bucket => count.
	 * @return void
	 */
	protected function render_cleanup_estimate_rows( array $data ) {
		echo '<table class="widefat striped wpopt-preview-table"><tbody>';
		foreach ( $this->cleanup_estimate_labels() as $k => $label ) {
			if ( isset( $data[ $k ] ) && (int) $data[ $k ] > 0 ) {
				printf( '<tr><td>%s</td><td><strong>%s</strong></td></tr>', esc_html( $label ), esc_html( number_format_i18n( (int) $data[ $k ] ) ) );
			}
		}
		echo '</tbody></table>';
	}

	/**
	 * Progress bar of «Run general cleanup now» and «Continue now».
	 *
	 * The same overlay and step loop as the media bulk actions: the browser asks for one short
	 * pass after another (wpoptimizing_ajax_gc_step()) until nothing is left, instead of one
	 * request that stops at its time limit and leaves the rest to WP-Cron. Without JavaScript the
	 * button still submits the form and the background passes finish the job.
	 *
	 * @param int $total Rows the cleanup is expected to remove (estimate), for the percentage.
	 * @return void
	 */
	protected function render_gc_progress( $total ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-escaped markup helper.
		echo wpoptimizing_progress_overlay_markup(
			__( 'WPOptimizing — Working…', 'wpoptimizing' ),
			array(
				'id_prefix'  => 'wpopt-gc-pb',
				'overlay_id' => 'wpopt-gc-overlay',
				'hidden'     => true,
			)
		);
		$strings = array(
			/* translators: %s: number of rows removed so far. */
			'run'  => __( 'Removed %s rows so far…', 'wpoptimizing' ),
			'wait' => __( 'Waiting for the background pass to finish…', 'wpoptimizing' ),
			'done' => __( 'Done. Reloading…', 'wpoptimizing' ),
			'err'  => __( 'Something went wrong. Please retry.', 'wpoptimizing' ),
		);
		$config  = array(
			'nonce' => wp_create_nonce( 'wpoptimizing_gc_step' ),
			'total' => max( 0, (int) $total ),
			's'     => $strings,
		);
		wp_print_inline_script_tag(
			'(function(){'
			. 'var C=' . wp_json_encode( $config ) . ',S=C.s;'
			. 'var ov=document.getElementById("wpopt-gc-overlay"),fill=document.getElementById("wpopt-gc-pb-fill"),pct=document.getElementById("wpopt-gc-pb-pct"),lab=document.getElementById("wpopt-gc-pb-label");'
			// The estimate can fall short (it is one): the bar never claims 100% before the end.
			. 'function upd(n){var t=Math.max(C.total,Math.round(n*1.05),1),p=Math.min(99,Math.round(n/t*100));if(fill){fill.style.width=Math.max(2,p)+"%";}if(pct){pct.textContent=p+"%";}if(lab){lab.textContent=S.run.replace("%s",n.toLocaleString());}}'
			. 'function post(f){var b=new URLSearchParams();b.set("action","wpoptimizing_gc_step");b.set("nonce",C.nonce);Object.keys(f).forEach(function(k){b.set(k,f[k]);});return fetch(ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded;charset=UTF-8"},body:b.toString()}).then(function(r){return r.json();});}'
			. 'function finish(){if(lab){lab.textContent=S.done;}if(fill){fill.style.width="100%";}if(pct){pct.textContent="100%";}post({finish:1}).finally(function(){setTimeout(function(){location.reload();},700);});}'
			. 'function start(first){if(ov){ov.style.display="flex";}var n=0,f=first?1:0;upd(0);(function step(){post({first:f}).then(function(r){if(!r||!r.success){if(lab){lab.textContent=S.err;}return;}var d=r.data||{};f=0;if(d.locked){if(lab){lab.textContent=S.wait;}setTimeout(step,5000);return;}n+=d.deleted||0;upd(n);if(d.more){step();}else{finish();}}).catch(function(){if(lab){lab.textContent=S.err;}});})();}'
			. 'var run=document.getElementById("wpopt-gc-run");if(run){run.addEventListener("click",function(e){e.preventDefault();if(window.confirm(run.getAttribute("data-confirm")||"")){start(true);}});}'
			. 'var cont=document.getElementById("wpopt-gc-continue");if(cont){cont.addEventListener("click",function(){start(false);});}'
			. '}());'
		);
	}

	/**
	 * Render the General Cleanup tab with postbox cards and native WordPress styles.
	 *
	 * @return void
	 */
	protected function render_general_cleanup_tab() {
		$cleanup     = $this->get_general_cleanup_service();
		$settings    = $cleanup->get_settings();
		$last_run    = (int) get_option( 'wpoptimizing_general_cleanup_last_run', 0 );
		$last_stats  = get_option( 'wpoptimizing_general_cleanup_last_stats', array() );
		$report      = null;
		$history     = $cleanup->get_report_history( 20 );
		$history_all = $cleanup->get_report_history( 100 );
		$view_id     = isset( $_GET['wpopt_gc_report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['wpopt_gc_report_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$gsec        = isset( $_GET['wpopt_gc_section'] ) ? sanitize_key( wp_unslash( $_GET['wpopt_gc_section'] ) ) : 'run'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $gsec, array( 'run', 'logs', 'bytype' ), true ) ) {
			$gsec = 'run';
		}
		if ( ! is_array( $last_stats ) ) {
			$last_stats = array();
		}

		// Build aggregate stats from full history.
		$aggregate_stats = array(
			'revisions'                  => 0,
			'posts'                      => 0,
			'comments'                   => 0,
			'transients'                 => 0,
			'oembed'                     => 0,
			'tags'                       => 0,
			'orphans'                    => 0,
			'action_scheduler'           => 0,
			'action_scheduler_logs'      => 0,
			'optimized_tables'           => 0,
			'db_size_before_bytes'       => 0,
			'db_size_after_bytes'        => 0,
			'deleted_records_size_bytes' => 0,
		);
		$run_count       = 0;
		if ( is_array( $history_all ) ) {
			foreach ( $history_all as $history_item ) {
				if ( ! is_array( $history_item ) || empty( $history_item['stats'] ) || ! is_array( $history_item['stats'] ) ) {
					continue;
				}
				++$run_count;
				$item_stats = $history_item['stats'];
				foreach ( array_keys( $aggregate_stats ) as $stat_key ) {
					$aggregate_stats[ $stat_key ] += (int) ( $item_stats[ $stat_key ] ?? 0 );
				}
			}
		}
		if ( '' !== $view_id ) {
			$selected = $cleanup->get_report_by_id( $view_id );
			if ( ! empty( $selected ) ) {
				$report = $selected;
			}
		}

		// URLs.
		$run_url    = add_query_arg(
			array(
				'page'               => 'wpoptimizing',
				'tab'                => 'general',
				'wpopt_gc_section'   => 'run',
				'wpopt_gc_report_id' => null,
			),
			admin_url( 'admin.php' )
		);
		$logs_url   = add_query_arg(
			array(
				'page'             => 'wpoptimizing',
				'tab'              => 'general',
				'wpopt_gc_section' => 'logs',
			),
			admin_url( 'admin.php' )
		);
		$bytype_url = add_query_arg(
			array(
				'page'             => 'wpoptimizing',
				'tab'              => 'general',
				'wpopt_gc_section' => 'bytype',
			),
			admin_url( 'admin.php' )
		);

		// ── Header ──────────────────────────────────────────────────────────
		$this->render_section_header(
			__( 'General cleanup', 'wpoptimizing' ),
			__( 'Run a quick database maintenance pass: revisions, drafts/trash, comment cleanup, transients, and optional table optimization.', 'wpoptimizing' ),
			array(
				'icon' => 'admin-tools',
				'doc'  => 'wpopt-doc-general',
			)
		);

		// ── Sub-nav ─────────────────────────────────────────────────────────
		echo '<div class="wpopt-nav-bar">';
		echo '<ul class="subsubsub wpopt-subsubsub">';
		echo '<li><a href="' . esc_url( $run_url ) . '" class="' . ( 'run' === $gsec ? 'current' : '' ) . '">' . esc_html__( 'Run cleanup', 'wpoptimizing' ) . '</a></li>';
		echo ' | ';
		echo '<li><a href="' . esc_url( $logs_url ) . '" class="' . ( 'logs' === $gsec ? 'current' : '' ) . '">' . esc_html__( 'Logs', 'wpoptimizing' ) . '</a></li>';
		echo ' | ';
		echo '<li><a href="' . esc_url( $bytype_url ) . '" class="' . ( 'bytype' === $gsec ? 'current' : '' ) . '">' . esc_html__( 'By content type', 'wpoptimizing' ) . '</a></li>';
		echo '</ul>';
		if ( $last_run > 0 ) {
			echo '<span class="description wpopt-muted">';
			echo wpoptimizing_icon( 'clock', 'wpopt-icon-inline' ) . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			/* translators: %s: localized date and time of last cleanup run. */
			echo esc_html( sprintf( __( 'Last run: %s', 'wpoptimizing' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run ) ) );
			echo '</span>';
		}
		echo '</div>';

		// ── RUN section ─────────────────────────────────────────────────────
		if ( 'run' === $gsec ) {

			$this->render_section_header(
				__( 'Run cleanup', 'wpoptimizing' ),
				__( 'Run the cleanup now with your current settings. The cards below show the cumulative totals across all runs and your active configuration.', 'wpoptimizing' ),
				array(
					'icon' => 'controls-play',
					'doc'  => 'wpopt-doc-general',
				)
			);

			// A run cut short by its time budget carries on by itself (RESUME_OPTION): say so, or the
			// totals look stuck while it works.
			$gc_resume = WPOPT_General_Cleanup::resume_state();
			if ( null !== $gc_resume && (int) $gc_resume['passes'] > 0 ) {
				echo '<div class="notice notice-info inline wpopt-notice"><p>' . esc_html(
					sprintf(
						/* translators: 1: number of passes run so far, 2: localized date and time the cleanup started. */
						__( 'The cleanup reached its time limit and is continuing by itself in the background since %2$s (passes so far: %1$s). You can leave this page, or follow it here until it is done.', 'wpoptimizing' ),
						number_format_i18n( (int) $gc_resume['passes'] ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $gc_resume['since'] )
					)
				) . '</p><p><button type="button" class="button" id="wpopt-gc-continue">' . esc_html__( 'Continue now', 'wpoptimizing' ) . '</button></p></div>';
			}

			$yes = esc_html__( 'Yes', 'wpoptimizing' );
			$no  = esc_html__( 'No', 'wpoptimizing' );

			echo '<div class="metabox-holder">';

			// Left card: aggregate stats.
			echo '<div class="postbox-container wpopt-col-left">';
			$this->render_gc_postbox_open( 'chart-bar', __( 'Cleanup totals (all runs)', 'wpoptimizing' ) );
			$this->render_gc_stats_table(
				array(
					array( __( 'Times run', 'wpoptimizing' ), number_format_i18n( $run_count ) ),
					array( __( 'Revisions removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['revisions'] ?? 0 ) ) ),
					array( __( 'Posts removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['posts'] ?? 0 ) ) ),
					array( __( 'Comments removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['comments'] ?? 0 ) ) ),
					array( __( 'Transients removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['transients'] ?? 0 ) ) ),
					array( __( 'oEmbed cache rows removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['oembed'] ?? 0 ) ) ),
					array( __( 'Unused tags removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['tags'] ?? 0 ) ) ),
					array( __( 'Orphan rows removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['orphans'] ?? 0 ) ) ),
					array( __( 'Action Scheduler entries removed', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['action_scheduler'] ?? 0 ) ) ),
					array( __( 'Action Scheduler log rows removed (no backup)', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['action_scheduler_logs'] ?? 0 ) ) ),
					array( __( 'Tables optimized', 'wpoptimizing' ), number_format_i18n( (int) ( $aggregate_stats['optimized_tables'] ?? 0 ) ) ),
					array( __( 'DB size (before)', 'wpoptimizing' ), size_format( (int) ( $aggregate_stats['db_size_before_bytes'] ?? 0 ), 2 ) ),
					array( __( 'DB size (after)', 'wpoptimizing' ), size_format( (int) ( $aggregate_stats['db_size_after_bytes'] ?? 0 ), 2 ) ),
					array( __( 'Estimated freed', 'wpoptimizing' ), size_format( (int) ( $aggregate_stats['deleted_records_size_bytes'] ?? 0 ), 2 ) ),
				)
			);
			$this->render_gc_postbox_close();
			echo '</div>';

			// Right card: active settings.
			echo '<div class="postbox-container wpopt-col-right">';
			$this->render_gc_postbox_open( 'admin-settings', __( 'Active settings', 'wpoptimizing' ) );
			$settings_url = admin_url( 'admin.php?page=wpoptimizing-settings' ) . '#wpopt-set-cleanup';
			echo '<p class="description wpopt-desc-flush">';
			printf(
				/* translators: %s: link to the Settings tab. */
				esc_html__( 'Configure in %s.', 'wpoptimizing' ),
				'<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'wpoptimizing' ) . '</a>'
			);
			echo '</p>';
			$active_rows = array(
				array( __( 'Revisions to keep per post', 'wpoptimizing' ), number_format_i18n( (int) $settings['keep_revisions'] ) ),
				array( __( 'Max revision age (days)', 'wpoptimizing' ), number_format_i18n( (int) $settings['revisions_max_age_days'] ) ),
				array( __( 'Auto-drafts to keep', 'wpoptimizing' ), number_format_i18n( (int) $settings['keep_auto_drafts'] ) ),
				array( __( 'Max auto-draft age (days)', 'wpoptimizing' ), number_format_i18n( (int) $settings['auto_drafts_max_age_days'] ) ),
				array( __( 'Remove auto-drafts', 'wpoptimizing' ), ! empty( $settings['delete_auto_drafts'] ) ? $yes : $no ),
				array( __( 'Remove trashed posts', 'wpoptimizing' ), ! empty( $settings['delete_trashed_posts'] ) ? $yes : $no ),
				array( __( 'Remove spam comments', 'wpoptimizing' ), ! empty( $settings['delete_spam_comments'] ) ? $yes : $no ),
				array( __( 'Remove trashed comments', 'wpoptimizing' ), ! empty( $settings['delete_trashed_comments'] ) ? $yes : $no ),
				array( __( 'Remove unapproved comments', 'wpoptimizing' ), ! empty( $settings['delete_unapproved_comments'] ) ? $yes : $no ),
				array( __( 'Delete pingbacks', 'wpoptimizing' ), ! empty( $settings['delete_pingbacks'] ) ? $yes : $no ),
				array( __( 'Delete trackbacks', 'wpoptimizing' ), ! empty( $settings['delete_trackbacks'] ) ? $yes : $no ),
				array( __( 'Delete expired transients', 'wpoptimizing' ), ! empty( $settings['delete_expired_transients'] ) ? $yes : $no ),
				array( __( 'Delete all transients, including those that have not expired yet', 'wpoptimizing' ), ! empty( $settings['delete_all_transients'] ) ? $yes : $no ),
				array( __( 'Delete oEmbed cache', 'wpoptimizing' ), ! empty( $settings['delete_oembed_cache'] ) ? $yes : $no ),
				array( __( 'Delete unused tags', 'wpoptimizing' ), ! empty( $settings['delete_unused_tags'] ) ? $yes : $no ),
				array( __( 'Delete orphan elements', 'wpoptimizing' ), ! empty( $settings['delete_orphan_elements'] ) ? $yes : $no ),
				array( __( 'Delete duplicate metadata', 'wpoptimizing' ), ! empty( $settings['delete_duplicate_meta'] ) ? $yes : $no ),
			);
			if ( WPOPT_General_Cleanup::has_action_scheduler() ) {
				$active_rows[] = array( __( 'Delete finished Action Scheduler logs', 'wpoptimizing' ), ! empty( $settings['delete_action_scheduler'] ) ? $yes : $no );
				if ( class_exists( 'WPOPT_General_Cleanup' ) && WPOPT_General_Cleanup::has_woocommerce() ) {
					$active_rows[] = array( __( 'Expired WooCommerce sessions', 'wpoptimizing' ), ! empty( $settings['delete_wc_sessions'] ) ? $yes : $no );
					$active_rows[] = array( __( 'Orphaned product variations', 'wpoptimizing' ), ! empty( $settings['delete_wc_orphan_variations'] ) ? $yes : $no );
				}
			}
			$active_rows[] = array( __( 'Optimize database tables', 'wpoptimizing' ), ! empty( $settings['optimize_tables'] ) ? $yes : $no );
			$this->render_gc_stats_table( $active_rows );
			$this->render_gc_postbox_close();
			echo '</div>';

			echo '<br class="clear" />';
			echo '</div>'; // .metabox-holder

			// Run button.
			// Dry-run preview table (shown after pressing Preview; nothing deleted).
			$preview = get_transient( 'wpoptimizing_gc_preview' );
			if ( is_array( $preview ) ) {
				delete_transient( 'wpoptimizing_gc_preview' );
				$total = array_sum( array_map( 'intval', $preview ) );
				echo '<div class="notice notice-info inline wpopt-notice wpopt-preview-notice"><p class="wpopt-desc-flush"><strong>'
					. esc_html__( 'Preview — nothing has been deleted.', 'wpoptimizing' ) . '</strong> '
					. esc_html(
						sprintf(
						/* translators: %s: total estimated records. */
							__( 'About %s records would be removed with the current settings (estimate).', 'wpoptimizing' ),
							number_format_i18n( $total )
						)
					) . '</p>';
				$this->render_cleanup_estimate_rows( $preview );
				if ( get_transient( 'wpoptimizing_gc_preview_partial' ) ) {
					$this->render_estimate_partial_note();
				}
				delete_transient( 'wpoptimizing_gc_preview_partial' );
				echo '</div>';
			}

			// Always-on read-only summary of what the CURRENT settings would remove, so the
			// impact is visible without pressing anything (Preview re-computes on demand).
			$gc_est       = $cleanup->estimate();
			$gc_est_total = array_sum( array_map( 'intval', (array) $gc_est ) );
			echo '<div class="wpopt-gc-summary">';
			echo '<p class="wpopt-desc-tight"><strong>' . esc_html__( 'With your current settings, a cleanup would remove about:', 'wpoptimizing' ) . '</strong></p>';
			if ( $gc_est_total > 0 ) {
				$this->render_cleanup_estimate_rows( $gc_est );
				echo '<p class="description wpopt-desc-tight">' . esc_html(
					sprintf(
					/* translators: %s: total estimated records. */
						__( 'About %s records in total (estimate). Nothing is deleted until you run the cleanup, and everything it deletes is backed up first and can be restored from Backups.', 'wpoptimizing' ),
						number_format_i18n( $gc_est_total )
					)
				) . '</p>';
			} elseif ( ! $cleanup->estimate_is_partial() ) {
				echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Nothing to remove with the current settings.', 'wpoptimizing' ) . '</p>';
			}
			if ( $cleanup->estimate_is_partial() ) {
				$this->render_estimate_partial_note();
			}
			// The one exception to «everything is backed up»: said before the run, not after.
			$gc_as_note = (int) ( $gc_est['action_scheduler'] ?? 0 ) > 0
				? sprintf(
					/* translators: %d: age in days of the Action Scheduler rows the cleanup removes. */
					__( 'Action Scheduler entries are backed up. Their log rows (trace lines such as «action started») are deleted without a backup, and so is any other log row older than %d days.', 'wpoptimizing' ),
					max( 0, (int) ( $settings['action_scheduler_max_age_days'] ?? 30 ) )
				)
				: '';
			if ( '' !== $gc_as_note ) {
				echo '<p class="description wpopt-desc-tight">' . esc_html( $gc_as_note ) . '</p>';
			}
			echo '</div>';

			echo '<p class="description wpopt-desc-tight">' . esc_html__( 'Tip: use Preview (dry run) first to see exactly what would be removed. Like every other deletion, the general cleanup backs up what it removes first (listed under Backups).', 'wpoptimizing' ) . '</p>';
			echo '<form method="post" class="wpopt-desc-tight">';
			wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
			echo '<input type="hidden" name="page" value="wpoptimizing" />';
			echo '<input type="hidden" name="tab" value="general" />';
			echo '<input type="hidden" name="wpopt_gc_section" value="run" />';
			/*
			 * PREVIEW FIRST, AND THE RUN ASKS. It was the one deletion with no backup and went
			 * through on one click. Since 19/10/2026 every batch is backed up before it is deleted
			 * (WPOPT_General_Cleanup::backup()), and the preview stays the primary action anyway:
			 * a restore is the way back, not a reason to delete blind.
			 */
			submit_button( __( 'Preview (dry run)', 'wpoptimizing' ), 'primary large', 'wpopt_gc_preview', false );
			echo ' ';
			$gc_confirm = $gc_est_total > 0
				? sprintf(
					/* translators: %s: estimated number of records. */
					__( 'Run the general cleanup now? About %s records will be removed. They are backed up first and can be restored from Backups.', 'wpoptimizing' ),
					number_format_i18n( $gc_est_total )
				)
				: __( 'Run the general cleanup now? What it removes is backed up first and can be restored from Backups.', 'wpoptimizing' );
			if ( '' !== $gc_as_note ) {
				$gc_confirm .= "\n\n" . $gc_as_note;
			}
			submit_button(
				__( 'Run general cleanup now', 'wpoptimizing' ),
				'secondary large',
				'wpopt_general_run',
				false,
				array(
					'id'           => 'wpopt-gc-run',
					'data-confirm' => $gc_confirm,
				)
			);
			echo '</form>';
			$this->render_gc_progress( $gc_est_total + ( ! empty( $settings['delete_action_scheduler'] ) ? WPOPT_General_Cleanup::as_logs_estimate() : 0 ) );
		} // end 'run'

		// ── LOGS section ────────────────────────────────────────────────────
		if ( 'logs' === $gsec ) {

			$this->render_section_header(
				__( 'Logs', 'wpoptimizing' ),
				__( 'Review the last cleanup run and the history of saved reports — view them on screen or download them.', 'wpoptimizing' ),
				array(
					'icon' => 'media-text',
					'doc'  => 'wpopt-doc-general',
				)
			);

			$date_fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$has_summary = ! empty( $last_stats );
			$has_report  = ! empty( $report );

			if ( ! $has_summary && ! $has_report && empty( $history ) ) {
				echo '<div class="notice notice-info inline wpopt-notice"><p>' . esc_html__( 'No cleanup logs yet. Run a general cleanup to generate your first report.', 'wpoptimizing' ) . '</p></div>';
			}

			echo '<div class="metabox-holder">';

			// Last run summary card (left, if data exists).
			if ( $has_summary ) {
				$col_cls = $has_report ? 'wpopt-col-half-left' : 'wpopt-col-full';
				echo '<div class="postbox-container ' . esc_attr( $col_cls ) . '">';
				$this->render_gc_postbox_open( 'chart-line', __( 'Last run summary', 'wpoptimizing' ) );
				$this->render_gc_stats_table(
					array(
						array( __( 'Revisions removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['revisions'] ?? 0 ) ) ),
						array( __( 'Posts removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['posts'] ?? 0 ) ) ),
						array( __( 'Comments removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['comments'] ?? 0 ) ) ),
						array( __( 'Transients removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['transients'] ?? 0 ) ) ),
						array( __( 'oEmbed cache rows', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['oembed'] ?? 0 ) ) ),
						array( __( 'Unused tags removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['tags'] ?? 0 ) ) ),
						array( __( 'Orphan rows removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['orphans'] ?? 0 ) ) ),
						array( __( 'Action Scheduler entries removed', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['action_scheduler'] ?? 0 ) ) ),
						array( __( 'Action Scheduler log rows removed (no backup)', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['action_scheduler_logs'] ?? 0 ) ) ),
						array( __( 'Tables optimized', 'wpoptimizing' ), number_format_i18n( (int) ( $last_stats['optimized_tables'] ?? 0 ) ) ),
						array( __( 'DB size (before)', 'wpoptimizing' ), size_format( (int) ( $last_stats['db_size_before_bytes'] ?? 0 ), 2 ) ),
						array( __( 'DB size (after)', 'wpoptimizing' ), size_format( (int) ( $last_stats['db_size_after_bytes'] ?? 0 ), 2 ) ),
						array( __( 'Estimated freed', 'wpoptimizing' ), size_format( (int) ( $last_stats['deleted_records_size_bytes'] ?? 0 ), 2 ) ),
					)
				);
				$this->render_gc_postbox_close();
				echo '</div>';
			}

			// Report detail card (right when next to summary, full-width otherwise).
			if ( $has_report ) {
				$report_id     = isset( $report['report_id'] ) ? (string) $report['report_id'] : '';
				$report_status = $this->get_cleanup_status_label( isset( $report['status'] ) ? (string) $report['status'] : 'unknown' );
				$started_at    = isset( $report['started_at'] ) ? (string) $report['started_at'] : '';
				$ended_at      = isset( $report['ended_at'] ) ? (string) $report['ended_at'] : '';
				$duration_ms   = isset( $report['duration_ms'] ) ? (int) $report['duration_ms'] : 0;
				$events        = ( isset( $report['events'] ) && is_array( $report['events'] ) ) ? $report['events'] : array();
				$stats         = ( isset( $report['stats'] ) && is_array( $report['stats'] ) ) ? $report['stats'] : array();
				$started_ts    = '' !== $started_at ? strtotime( $started_at ) : false;
				$ended_ts      = '' !== $ended_at ? strtotime( $ended_at ) : false;
				$status_key    = isset( $report['status'] ) ? (string) $report['status'] : '';

				$col_cls = $has_summary ? 'wpopt-col-half-right' : 'wpopt-col-full';
				echo '<div class="postbox-container ' . esc_attr( $col_cls ) . '">';
				$this->render_gc_postbox_open( 'media-document', __( 'Cleanup report', 'wpoptimizing' ) );

				echo '<p class="description wpopt-desc-flush">' . esc_html__( 'Detailed event lines are included only when "Generate detailed log" is enabled.', 'wpoptimizing' ) . '</p>';

				// Status notice.
				$notice_class = 'completed' === $status_key ? 'notice-success' : ( 'failed' === $status_key ? 'notice-error' : 'notice-info' );
				echo '<div class="notice ' . esc_attr( $notice_class ) . ' inline wpopt-notice">';
				echo '<p>';
				/* translators: %s: cleanup report status label. */
				echo '<strong>' . esc_html( sprintf( __( 'Status: %s', 'wpoptimizing' ), $report_status ) ) . '</strong>';
				if ( false !== $started_ts ) {
					/* translators: %s: cleanup start date/time. */
					echo ' &nbsp;&mdash;&nbsp; ' . esc_html( sprintf( __( 'Started: %s', 'wpoptimizing' ), wp_date( $date_fmt, $started_ts ) ) );
				}
				if ( false !== $ended_ts ) {
					/* translators: %s: cleanup end date/time. */
					echo ' &nbsp;&mdash;&nbsp; ' . esc_html( sprintf( __( 'Finished: %s', 'wpoptimizing' ), wp_date( $date_fmt, $ended_ts ) ) );
				}
				/* translators: %d: cleanup duration in milliseconds. */
				echo ' &nbsp;&mdash;&nbsp; ' . esc_html( sprintf( __( 'Duration: %d ms', 'wpoptimizing' ), $duration_ms ) );
				echo '</p>';
				echo '</div>';

				if ( ! empty( $stats ) ) {
					$this->render_gc_stats_table(
						array(
							array( __( 'Revisions removed', 'wpoptimizing' ), number_format_i18n( (int) ( $stats['revisions'] ?? 0 ) ) ),
							array( __( 'Posts removed', 'wpoptimizing' ), number_format_i18n( (int) ( $stats['posts'] ?? 0 ) ) ),
							array( __( 'Comments removed', 'wpoptimizing' ), number_format_i18n( (int) ( $stats['comments'] ?? 0 ) ) ),
							array( __( 'Transients removed', 'wpoptimizing' ), number_format_i18n( (int) ( $stats['transients'] ?? 0 ) ) ),
							array( __( 'oEmbed cache rows', 'wpoptimizing' ), number_format_i18n( (int) ( $stats['oembed'] ?? 0 ) ) ),
							array( __( 'Tables optimized', 'wpoptimizing' ), number_format_i18n( (int) ( $stats['optimized_tables'] ?? 0 ) ) ),
							array( __( 'DB size (before)', 'wpoptimizing' ), size_format( (int) ( $stats['db_size_before_bytes'] ?? 0 ), 2 ) ),
							array( __( 'DB size (after)', 'wpoptimizing' ), size_format( (int) ( $stats['db_size_after_bytes'] ?? 0 ), 2 ) ),
							array( __( 'Estimated freed', 'wpoptimizing' ), size_format( (int) ( $stats['deleted_records_size_bytes'] ?? 0 ), 2 ) ),
						)
					);
				}

				if ( ! empty( $events ) ) {
					echo '<details class="wpopt-details">';
					/* translators: %d: number of log events. */
					echo '<summary>' . esc_html( sprintf( __( 'Detailed log (%d events)', 'wpoptimizing' ), count( $events ) ) ) . '</summary>';
					echo '<ul class="wpopt-disc-list">';
					foreach ( $events as $event ) {
						$msg = isset( $event['message'] ) ? (string) $event['message'] : '';
						if ( '' === $msg ) {
							continue;
						}
						echo '<li class="description">' . esc_html( $msg ) . '</li>';
					}
					echo '</ul>';
					echo '</details>';
				}

				echo '<form method="post" class="wpopt-notice">';
				wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
				echo '<input type="hidden" name="page" value="wpoptimizing" />';
				echo '<input type="hidden" name="tab" value="general" />';
				echo '<input type="hidden" name="wpopt_gc_section" value="logs" />';
				echo '<input type="hidden" name="wpopt_gc_report_id" value="' . esc_attr( $report_id ) . '" />';
				submit_button( __( 'Download report', 'wpoptimizing' ), 'secondary', 'wpopt_gc_download_report', false );
				echo '</form>';

				$this->render_gc_postbox_close();
				echo '</div>';
			}

			echo '<br class="clear" />';

			// History selector card (full width).
			if ( ! empty( $history ) ) {
				echo '<div class="postbox-container wpopt-col-full">';
				$this->render_gc_postbox_open( 'text-page', __( 'Cleanup logs', 'wpoptimizing' ) );
				echo '<p class="description wpopt-desc-flush">' . esc_html__( 'Select a previous report to view it on screen or download it.', 'wpoptimizing' ) . '</p>';

				// Flex row: select left, buttons right.
				$first_id  = '';
				$active_id = '';
				echo '<div class="wpopt-gc-log-row">';

				// Left column: Select2 log selector.
				echo '<div class="wpopt-gc-log-select-wrap">';
				echo '<select id="wpopt-gc-log-select" class="wpopt-select2">';
				foreach ( $history as $item ) {
					$item_id    = isset( $item['report_id'] ) ? (string) $item['report_id'] : '';
					$item_end   = isset( $item['ended_at'] ) ? (string) $item['ended_at'] : '';
					$item_state = $this->get_cleanup_status_label( isset( $item['status'] ) ? (string) $item['status'] : 'unknown' );
					if ( '' === $item_id ) {
						continue;
					}
					if ( '' === $first_id ) {
						$first_id = $item_id;
					}
					$item_end_ts = '' !== $item_end ? strtotime( $item_end ) : false;
					$item_label  = ( false !== $item_end_ts ) ? wp_date( $date_fmt, $item_end_ts ) : $item_id;
					$item_label .= ' — ' . $item_state;
					echo '<option value="' . esc_attr( $item_id ) . '"' . selected( $view_id, $item_id, false ) . '>' . esc_html( $item_label ) . '</option>';
				}
				echo '</select>';
				echo '</div>';

				$active_id = '' !== $view_id ? $view_id : $first_id;

				// Right column: action buttons.
				echo '<div class="wpopt-gc-log-actions">';

				// View form (GET) — no visible select, value injected by JS.
				echo '<form method="get" id="wpopt-gc-view-form">';
				echo '<input type="hidden" name="page" value="wpoptimizing" />';
				echo '<input type="hidden" name="tab" value="general" />';
				echo '<input type="hidden" name="wpopt_gc_section" value="logs" />';
				echo '<input type="hidden" name="wpopt_gc_report_id" id="wpopt-gc-view-id" value="' . esc_attr( $active_id ) . '" />';
				submit_button( __( 'View log', 'wpoptimizing' ), 'secondary', '', false );
				echo '</form>';

				// Download form (POST) — no visible select, value injected by JS.
				echo '<form method="post" id="wpopt-gc-dl-form">';
				wp_nonce_field( $this->nonce_action, $this->nonce_field, false, true );
				echo '<input type="hidden" name="page" value="wpoptimizing" />';
				echo '<input type="hidden" name="tab" value="general" />';
				echo '<input type="hidden" name="wpopt_gc_section" value="logs" />';
				echo '<input type="hidden" name="wpopt_gc_report_id" id="wpopt-gc-dl-id" value="' . esc_attr( $active_id ) . '" />';
				submit_button( __( 'Download log', 'wpoptimizing' ), 'secondary', 'wpopt_gc_download_report', false );
				echo '</form>';

				// Printable PDF report (GET) — opens a standalone branded report in a
				// new tab. GET (not POST) is required so the report's afterprint
				// reload re-sends the params via the URL; a POST reload drops the body
				// and the report would blank out. Read-only + capability-checked, so
				// GET is safe. Mirrors the impact-report and "View log" forms.
				echo '<form method="get" id="wpopt-gc-report-form" target="_blank">';
				wp_nonce_field( 'wpopt_gc_report', '_wpopt_gcr_nonce', false, true ); // Read-only report: its own nonce, never the action one (this form is GET).
				echo '<input type="hidden" name="page" value="wpoptimizing" />';
				echo '<input type="hidden" name="tab" value="general" />';
				echo '<input type="hidden" name="wpopt_gc_section" value="logs" />';
				echo '<input type="hidden" name="wpopt_gc_report_id" id="wpopt-gc-report-id" value="' . esc_attr( $active_id ) . '" />';
				submit_button( __( 'Report (PDF)', 'wpoptimizing' ), 'primary', 'wpopt_gc_view_report', false );
				echo '</form>';

				echo '</div>'; // right column
				echo '</div>'; // flex row

				// Sync select → hidden inputs. jQuery wrapping ensures we catch
				// Select2's jQuery-triggered change events (native addEventListener misses them).
				wp_print_inline_script_tag(
					'jQuery(function($){'
					. 'var s=document.getElementById("wpopt-gc-log-select");'
					. 'var v=document.getElementById("wpopt-gc-view-id");'
					. 'var d=document.getElementById("wpopt-gc-dl-id");'
					. 'var r=document.getElementById("wpopt-gc-report-id");'
					. 'function sync(){if(v)v.value=s.value;if(d)d.value=s.value;if(r)r.value=s.value;}'
					. 'if(s){$(s).on("change select2:select",sync);sync();}'
					. '});'
				);

				$this->render_gc_postbox_close();
				echo '</div>';
			}

			echo '</div>'; // .metabox-holder
		} // end 'logs'

		// ── BY CONTENT TYPE section ─────────────────────────────────────────
		if ( 'bytype' === $gsec ) {

			$this->render_section_header(
				__( 'Content by type', 'wpoptimizing' ),
				__( 'Where your database weight sits, grouped by post type: live entries, revisions, auto-drafts and attached metadata rows. Read-only — nothing is deleted here.', 'wpoptimizing' ),
				array(
					'icon' => 'chart-pie',
					'doc'  => 'wpopt-doc-general',
				)
			);

			$by_type    = $this->get_general_cleanup_service()->content_by_post_type();
			$large_site = function_exists( 'wpoptimizing_scan_large_site' ) && wpoptimizing_scan_large_site();
			if ( empty( $by_type ) ) {
				echo '<div class="notice notice-info inline wpopt-notice"><p>' . esc_html__( 'No content found.', 'wpoptimizing' ) . '</p></div>';
			} else {
				echo '<div class="wpopt-table-scroll">';
				echo '<table class="widefat striped wp-list-table">';
				echo '<thead><tr>';
				echo '<th scope="col">' . esc_html__( 'Content type', 'wpoptimizing' ) . '</th>';
				echo '<th scope="col" class="wpopt-th-right">' . esc_html__( 'Entries', 'wpoptimizing' ) . '</th>';
				echo '<th scope="col" class="wpopt-th-right">' . esc_html__( 'Revisions', 'wpoptimizing' ) . '</th>';
				echo '<th scope="col" class="wpopt-th-right">' . esc_html__( 'Auto-drafts', 'wpoptimizing' ) . '</th>';
				echo '<th scope="col" class="wpopt-th-right">' . esc_html__( 'Metadata rows', 'wpoptimizing' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $by_type as $row ) {
					echo '<tr>';
					echo '<td><strong>' . esc_html( $row['label'] ) . '</strong> <span class="wpopt-muted">' . esc_html( $row['type'] ) . '</span></td>';
					echo '<td class="wpopt-th-right">' . esc_html( number_format_i18n( (int) $row['entries'] ) ) . '</td>';
					echo '<td class="wpopt-th-right">' . esc_html( number_format_i18n( (int) $row['revisions'] ) ) . '</td>';
					echo '<td class="wpopt-th-right">' . esc_html( number_format_i18n( (int) $row['auto_drafts'] ) ) . '</td>';
					echo '<td class="wpopt-th-right">' . ( $large_site ? '—' : esc_html( number_format_i18n( (int) $row['meta'] ) ) ) . '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
				echo '</div>'; // .wpopt-table-scroll
				echo '<p class="description wpopt-muted">' . esc_html__( 'Revisions and auto-drafts are attributed to their real content type. Use the per-post “Keep all revisions” option in the editor to protect specific entries.', 'wpoptimizing' ) . '</p>';
				if ( $large_site ) {
					echo '<p class="description wpopt-muted">' . esc_html__( 'Metadata totals are omitted on very large databases to keep this page fast.', 'wpoptimizing' ) . '</p>';
				}
			}
		} // end 'bytype'
	}

	/**
	 * Return translated cleanup status label.
	 *
	 * @param string $status Internal status key.
	 * @return string
	 */
	protected function get_cleanup_status_label( $status ) {
		$key = sanitize_key( (string) $status );
		switch ( $key ) {
			case 'completed':
				return __( 'Completed', 'wpoptimizing' );
			case 'partial':
				return __( 'Partial', 'wpoptimizing' );
			case 'failed':
				return __( 'Failed', 'wpoptimizing' );
			case 'running':
				return __( 'Running', 'wpoptimizing' );
			case 'locked':
				return __( 'Locked', 'wpoptimizing' );
			case 'retry_scheduled':
				return __( 'Retry scheduled', 'wpoptimizing' );
			case 'backup_failed':
				return __( 'Stopped: backup failed', 'wpoptimizing' );
			default:
				return __( 'Unknown', 'wpoptimizing' );
		}
	}
}
