<?php
/**
 * WP_List_Table subclasses used by the WPOptimizing admin UI.
 *
 * Extracted from class-admin-ui.php. These tables only call public methods of
 * WPOPT_Admin_UI; the base class holds the bulk-selection helpers.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Base WP_List_Table for WPOptimizing.
 */
class WPOptimizing_Base_List_Table extends WP_List_Table {
	/** @var WPOPT_Admin_UI */
	protected $ui;
	/** @var array */
	protected $items_all = array();
	/** @var bool */
	protected $checked = false;
	/** @var bool */
	protected $show_projects = false;
	/** @var string */
	protected $scope = '';
	/** @var int */
	protected $per_page = 50;
	/** @var array */
	protected $type_choices = array();
	/** @var string */
	protected $type_filter = '';
	/** @var array */
	protected $project_choices = array();
	/** @var string */
	protected $project_filter = '';
	/** @var string Label for the second filter's "all" option (extensions by default). */
	protected $project_all_label = '';
	/** @var string Label for the first (type) filter's "all" option (empty = "All types"). */
	protected $type_all_label = '';
	/** @var array */
	protected $hidden_fields = array();
	/** @var string */
	protected $page_param = '';
	/** @var string */
	protected $cb_name = '';
	/** @var string */
	protected $search_param = 's';
	/** @var bool */
	protected $selectable = true;

	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct(
			array(
				'singular' => 'wpoptimizing_item',
				'plural'   => 'wpoptimizing_items',
				'ajax'     => false,
			)
		);
		$this->ui                = $ui;
		$this->items_all         = $rows;
		$this->checked           = (bool) $checked;
		$this->show_projects     = (bool) $show_projects;
		$this->scope             = $args['scope'] ?? '';
		$this->per_page          = isset( $args['per_page'] ) ? (int) $args['per_page'] : 50;
		$this->type_filter       = $args['type_filter'] ?? '';
		$this->type_choices      = $args['type_choices'] ?? array();
		$this->project_filter    = $args['project_filter'] ?? '';
		$this->project_choices   = $args['project_choices'] ?? array();
		$this->project_all_label = isset( $args['project_all_label'] ) ? (string) $args['project_all_label'] : __( 'All extensions', 'wpoptimizing' );
		$this->type_all_label    = isset( $args['type_all_label'] ) ? (string) $args['type_all_label'] : '';
		$this->hidden_fields     = $args['hidden_fields'] ?? array();
		$this->page_param        = $this->scope . '_paged';
		$this->search_param      = isset( $args['search_param'] ) ? (string) $args['search_param'] : 's';
		$this->selectable        = isset( $args['selectable'] ) ? (bool) $args['selectable'] : true;
	}

	public function prepare_items() {
		$this->maybe_sort_items();
		$total = count( $this->items_all );
		$paged = $this->get_pagenum();
		$pages = ( $this->per_page > 0 ) ? (int) ceil( $total / $this->per_page ) : 1;
		if ( $pages < 1 ) {
			$pages = 1;
		}
		if ( $paged > $pages ) {
			$paged = 1;
		}
		$offset      = ( $paged - 1 ) * $this->per_page;
		$this->items = array_slice( $this->items_all, $offset, $this->per_page );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $this->per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	public function get_pagenum() {
		// Read this table's OWN page param (scope-prefixed), so several list tables
		// can coexist on one screen without sharing WP's global 'paged'.
		$paged = isset( $_REQUEST[ $this->page_param ] ) ? absint( $_REQUEST[ $this->page_param ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging.
		if ( isset( $this->_pagination_args['total_pages'] ) && $paged > $this->_pagination_args['total_pages'] ) {
			$paged = $this->_pagination_args['total_pages'];
		}
		return max( 1, $paged );
	}

	/**
	 * Reuse WordPress' own pagination markup verbatim, but bound to this table's
	 * scope-prefixed page param instead of the global 'paged'. We temporarily hide
	 * our param from the request URL so core builds clean 'paged' links, capture the
	 * output, and rewrite 'paged' → page_param. Identical look to every other table.
	 *
	 * @param string $which 'top' | 'bottom'.
	 * @return void
	 */
	protected function pagination( $which ) {
		if ( 's' === $this->page_param || '' === $this->page_param ) {
			parent::pagination( $which );
			return;
		}
		$orig_uri   = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$orig_paged = isset( $_REQUEST['paged'] ) ? absint( wp_unslash( $_REQUEST['paged'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only save/restore of the paging cursor.
		// Core derives link URLs from REQUEST_URI; strip our scoped param so it does
		// not get duplicated, and feed core the current page through 'paged'.
		$_SERVER['REQUEST_URI'] = remove_query_arg( $this->page_param, $orig_uri );
		$_REQUEST['paged']      = $this->get_pagenum();
		ob_start();
		parent::pagination( $which );
		$html                   = (string) ob_get_clean();
		$_SERVER['REQUEST_URI'] = $orig_uri;
		if ( null === $orig_paged ) {
			unset( $_REQUEST['paged'] );
		} else {
			$_REQUEST['paged'] = $orig_paged;
		}
		$html = str_replace( array( "name='paged'", 'name="paged"', "id='current-page-selector'", 'id="current-page-selector"' ), array( "name='" . $this->page_param . "'", 'name="' . $this->page_param . '"', "id='" . $this->page_param . '-selector\'', 'id="' . $this->page_param . '-selector"' ), $html );
		$html = preg_replace( '/([?&;]|&amp;)paged=/', '${1}' . $this->page_param . '=', $html );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core-generated, already escaped.
	}


	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		$this->render_filter_controls();
	}

	public function search_box( $text, $input_id ) {
		$input_id = $input_id . '-search-input';
		$search   = isset( $_REQUEST[ $this->search_param ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $this->search_param ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only search form.

		// Wrapper forces the search row to the full width on mobile (a bare
		// <p class="search-box"> shrink-wraps to its content there).
		echo '<div class="wpopt-search">';
		echo '<p class="search-box">';
		echo '<label class="screen-reader-text" for="' . esc_attr( $input_id ) . '">' . esc_html( $text ) . ':</label>';
		echo '<input type="search" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( $this->search_param ) . '" value="' . esc_attr( $search ) . '" />';
		// Custom submit so the button can collapse to a magnifier-only icon on mobile
		// (CSS hides .wpopt-search-label under 782px and reveals the icon).
		echo '<button type="submit" id="search-submit" class="button">'
			. wpoptimizing_icon( 'search' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
			. '<span class="wpopt-search-label">' . esc_html( $text ) . '</span>'
			. '</button>';
		echo '</p>';
		echo '</div>';
	}

	/**
	 * Render filter controls for use inside a GET form.
	 *
	 * @return void
	 */
	public function render_filter_controls() {
		echo '<div class="alignleft actions">';
		foreach ( (array) $this->hidden_fields as $k => $v ) {
			printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( $v ) );
		}

		if ( ! empty( $this->type_choices ) ) {
			echo '<label class="screen-reader-text" for="' . esc_attr( $this->scope . '_tipo' ) . '">' . esc_html__( 'Filter by type', 'wpoptimizing' ) . '</label>';
			echo '<select name="' . esc_attr( $this->scope . '_tipo' ) . '" id="' . esc_attr( $this->scope . '_tipo' ) . '" class="wpopt-filter-select wpopt-select2-auto">';
			echo '<option value="all">' . esc_html( '' !== $this->type_all_label ? $this->type_all_label : __( 'All types', 'wpoptimizing' ) ) . '</option>';
			// Accept either a flat list of values (value === label) or an associative
			// value => human label map (so labels can be translated). Back-compatible.
			foreach ( $this->type_choices as $key => $val ) {
				$is_assoc = is_string( $key );
				$value    = $is_assoc ? $key : $val;
				$label    = (string) $val;
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( $value ),
					selected( $this->type_filter, $value, false ),
					esc_html( $label )
				);
			}
			echo '</select>';
		}

		if ( ! empty( $this->project_choices ) ) {
			echo '<label class="screen-reader-text" for="' . esc_attr( $this->scope . '_ext' ) . '">' . esc_html__( 'Filter by extension', 'wpoptimizing' ) . '</label>';
			echo '<select name="' . esc_attr( $this->scope . '_ext' ) . '" id="' . esc_attr( $this->scope . '_ext' ) . '" class="wpopt-filter-select wpopt-select2-auto">';
			echo '<option value="all">' . esc_html( '' !== $this->project_all_label ? $this->project_all_label : __( 'All extensions', 'wpoptimizing' ) ) . '</option>';
			foreach ( $this->project_choices as $value => $label ) {
				printf(
					'<option value="%s"%s>%s</option>',
					esc_attr( (string) $value ),
					selected( $this->project_filter, (string) $value, false ),
					esc_html( (string) $label )
				);
			}
			echo '</select>';
		}

		echo '<label class="screen-reader-text" for="' . esc_attr( $this->scope . '_pp' ) . '">' . esc_html__( 'Items per page', 'wpoptimizing' ) . '</label>';
		echo '<select name="' . esc_attr( $this->scope . '_pp' ) . '" id="' . esc_attr( $this->scope . '_pp' ) . '" class="wpopt-select2-auto">';
		foreach ( array( 10, 25, 50, 100, 200 ) as $pp ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $pp ),
				selected( $this->per_page, $pp, false ),
				esc_html( (string) $pp )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'wpoptimizing' ), 'secondary', 'filter_action', false );
		echo '<input type="hidden" name="' . esc_attr( $this->page_param ) . '" value="1" />';
		echo '</div>';
	}

	public function column_default( $item, $column_name ) {
		if ( 'project' === $column_name ) {
			return $this->ui->format_projects_nicely( $item );
		}
		if ( isset( $item[ $column_name ] ) ) {
			return '<code>' . esc_html( (string) $item[ $column_name ] ) . '</code>';
		}
		return '';
	}

	public function column_cb( $item ) {
		if ( ! $this->selectable ) {
			return '';
		}
		$val = $this->cb_value( $item );
		if ( '' === $val ) {
			return '';
		}
		return sprintf(
			'<input type="checkbox" name="%s" value="%s"%s />',
			esc_attr( $this->cb_name ),
			esc_attr( $val ),
			$this->checked ? ' checked' : ''
		);
	}

	protected function get_row_value( $item ) {
		return '';
	}

	/**
	 * The exact checkbox value for a row (what the delete handler receives).
	 * Defaults to get_row_value(); subclasses override when the checkbox value
	 * differs from the sort/row value. Return '' for non-selectable rows.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function cb_value( $item ) {
		return (string) $this->get_row_value( $item );
	}

	/**
	 * Shield toggle for the identifying column: marks a "type::value" DB item as
	 * protected from deletion (the `wpoptimizing_can_delete_item` guard reads the same
	 * option). Reflects the current state; toggled via AJAX by the DB live-query panel's
	 * script. Returns '' for a malformed entry.
	 *
	 * @param string $entry "type::value" entry.
	 * @return string Safe HTML.
	 */
	protected function protect_toggle_html( $entry ) {
		$entry = (string) $entry;
		// Protection only matters where a row can actually be deleted. Non-selectable tables
		// (the Active tab) are informational and never a deletion surface, so the shield would
		// guard against an action that cannot happen — omit it there.
		if ( ! $this->selectable || '' === $entry || false === strpos( $entry, '::' ) || ! function_exists( 'wpoptimizing_item_is_protected' ) ) {
			return '';
		}
		$on    = wpoptimizing_item_is_protected( $entry );
		$title = $on
			? __( 'Protected from deletion — click to unprotect', 'wpoptimizing' )
			: __( 'Protect from deletion', 'wpoptimizing' );
		return '<button type="button" class="wpopt-shield-toggle' . ( $on ? ' is-protected' : '' ) . '" data-entry="' . esc_attr( $entry ) . '" data-protected="' . ( $on ? '1' : '0' ) . '" title="' . esc_attr( $title ) . '" aria-pressed="' . ( $on ? 'true' : 'false' ) . '">' . wpoptimizing_icon( 'shield' ) . '<span class="screen-reader-text">' . esc_html( $title ) . '</span></button>';
	}

	/**
	 * All checkbox values for the FULL filtered set (every page, not just the
	 * current one). Used by the async "process all" action. Bounded for safety.
	 *
	 * @param int $max Hard cap on returned values.
	 * @return array{values:string[],truncated:bool,low:int} low = how many of them carry a
	 *         low confidence (estimated, doubtful, undetermined), so "process all" can warn
	 *         about them the way "delete selected" always did.
	 */
	public function get_all_cb_values( $max = 20000 ) {
		$out       = array();
		$truncated = false;
		$low       = 0;
		foreach ( $this->items_all as $it ) {
			$v = $this->cb_value( $it );
			if ( '' === $v ) {
				continue;
			}
			if ( count( $out ) >= $max ) {
				$truncated = true;
				break;
			}
			$out[] = $v;
			if ( in_array( strtolower( trim( (string) ( $it['confianza'] ?? '' ) ) ), array( 'heuristic', 'unknown', 'none', 'weak' ), true ) ) {
				++$low;
			}
		}
		return array(
			'values'    => $out,
			'truncated' => $truncated,
			'low'       => $low,
		);
	}

	/**
	 * Bulk-engine scope key (which delete handler this table maps to). Empty
	 * disables the async controls for the table.
	 *
	 * @return string
	 */
	public function bulk_scope() {
		return '';
	}

	/**
	 * The checkbox name the table renders (for the JS to collect checked rows).
	 *
	 * @return string
	 */
	public function bulk_cb_name() {
		return (string) $this->cb_name;
	}

	/**
	 * Whether this table currently renders selectable checkboxes.
	 *
	 * @return bool
	 */
	public function is_selectable() {
		return (bool) $this->selectable;
	}

	/**
	 * Render the full table UI with horizontal scroll.
	 *
	 * Mirrors WP_List_Table::display() exactly but wraps ONLY the <table> element
	 * in <div class="wpopt-table-scroll"> so filters and pagination stay outside
	 * the scroll container and never move out of view.
	 *
	 * @return void
	 */
	public function display() {
		$singular = $this->_args['singular'];

		$this->display_tablenav( 'top' );

		$this->screen->render_screen_reader_content( 'heading_list' );

		echo '<div class="wpopt-table-scroll">';
		echo '<table class="wp-list-table ' . esc_attr( implode( ' ', $this->get_table_classes() ) ) . '">';
		$this->print_table_description();
		echo '<thead><tr>';
		$this->print_column_headers();
		echo '</tr></thead>';
		echo '<tbody id="the-list"' . ( $singular ? ' data-wp-lists="list:' . esc_attr( $singular ) . '"' : '' ) . '>';
		$this->display_rows_or_placeholder();
		echo '</tbody>';
		echo '<tfoot><tr>';
		$this->print_column_headers( false );
		echo '</tr></tfoot>';
		echo '</table>';
		echo '</div>';

		$this->display_tablenav( 'bottom' );
	}

	protected function maybe_sort_items() {
		$sortable = $this->get_sortable_columns();
		if ( empty( $sortable ) ) {
			return;
		}
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'asc'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $orderby || ! isset( $sortable[ $orderby ] ) ) {
			foreach ( array( 'value', 'relative_path', 'name', 'slug', 'type' ) as $default_key ) {
				if ( isset( $sortable[ $default_key ] ) ) {
					$orderby = $default_key;
					break;
				}
			}
		}
		if ( '' === $orderby || ! isset( $sortable[ $orderby ] ) ) {
			return;
		}
		$order     = ( 'desc' === strtolower( $order ) ) ? 'desc' : 'asc';
		$direction = ( 'desc' === $order ) ? -1 : 1;

		usort(
			$this->items_all,
			function ( $a, $b ) use ( $orderby, $direction ) {
				$av = $this->get_sort_value( $a, $orderby );
				$bv = $this->get_sort_value( $b, $orderby );
				if ( $av === $bv ) {
					return 0;
				}
				if ( is_numeric( $av ) && is_numeric( $bv ) ) {
					return ( $av < $bv ) ? -1 * $direction : 1 * $direction;
				}
				return ( (string) $av < (string) $bv ) ? -1 * $direction : 1 * $direction;
			}
		);
	}

	protected function get_sort_value( $item, $orderby ) {
		if ( 'project' === $orderby ) {
			return $this->ui->get_row_project_sort_value( $item );
		}
		if ( 'size' === $orderby || 'size_bytes' === $orderby ) {
			return isset( $item['size_bytes'] ) ? (int) $item['size_bytes'] : 0;
		}
		if ( 'records' === $orderby ) {
			return isset( $item['count_rows'] ) ? (int) $item['count_rows'] : 0;
		}
		if ( isset( $item[ $orderby ] ) ) {
			return $item[ $orderby ];
		}
		return '';
	}
}

/**
 * DB list table.
 */
class WPOptimizing_DB_List_Table extends WPOptimizing_Base_List_Table {
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name = 'bd_selected[]';
	}

	/**
	 * Override checkbox to include data-conf attribute for JS safety confirmation.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function column_cb( $item ) {
		if ( ! $this->selectable ) {
			return '';
		}
		$val  = $this->cb_value( $item );
		$conf = strtolower( trim( (string) ( $item['confianza'] ?? '' ) ) );
		return sprintf(
			'<input type="checkbox" name="%s" value="%s" data-conf="%s"%s />',
			esc_attr( $this->cb_name ),
			esc_attr( $val ),
			esc_attr( $conf ),
			$this->checked ? ' checked' : ''
		);
	}

	public function bulk_scope() {
		return 'db';
	}

	public function get_columns() {
		// Value (the identifying name) goes first so WordPress keeps it visible when
		// the table collapses on mobile, like the Cron table's hook column.
		$cols = array(
			'value'     => esc_html__( 'Value', 'wpoptimizing' ),
			'type'      => esc_html__( 'Type', 'wpoptimizing' ),
			'records'   => esc_html__( 'Records', 'wpoptimizing' ),
			'size'      => esc_html__( 'Size', 'wpoptimizing' ),
			'confianza' => esc_html__( 'Confidence', 'wpoptimizing' ),
		);
		if ( $this->selectable ) {
			$cols = array_merge( array( 'cb' => '<input type="checkbox" />' ), $cols );
		}
		if ( $this->show_projects ) {
			$cols['project'] = esc_html__( 'Extension', 'wpoptimizing' );
		}
		return $cols;
	}

	/**
	 * Render the Confidence column as a coloured badge.
	 *
	 * @param array $item Row data.
	 * @return string Safe HTML.
	 */
	public function column_confianza( $item ) {
		// El vocabulario sale de resolvers/confidence-levels.php: aquí había una de las seis
		// copias que tenía el mismo concepto, y dos de ellas deciden qué se puede borrar.
		$level = wpoptimizing_confidence_normalize( $item['confianza'] ?? '' );
		return '<span class="wpopt-conf-badge wpopt-conf-' . esc_attr( $level ) . '" title="'
			. esc_attr( wpoptimizing_confidence_description( $level ) ) . '">'
			. esc_html( wpoptimizing_confidence_label( $level ) ) . '</span>';
	}

	/**
	 * Render the Type column with a human-readable badge.
	 *
	 * @param array $item Row data.
	 * @return string Safe HTML.
	 */
	public function column_type( $item ) {
		$type  = strtolower( (string) ( $item['type'] ?? '' ) );
		$label = $this->ui->get_db_type_label( $type );
		return '<span class="wpopt-type-badge wpopt-type-' . esc_attr( $type ) . '">' . esc_html( $label ) . '</span>';
	}

	public function column_value( $item ) {
		$type  = strtolower( (string) ( $item['type'] ?? '' ) );
		$value = (string) ( $item['value'] ?? '' );

		$shield = $this->protect_toggle_html( $this->get_row_value( $item ) );
		// One flex row: the shield and the value can never break onto two lines; the value is
		// what gives way, ellipsised beside the shield (admin.css, .wpopt-value-cell).
		return '<span class="wpopt-value-cell">' . $shield . $this->ui->record_query_button( $type, $value, $this->ui->format_db_value( $item ) ) . '</span>';
	}

	public function get_sortable_columns() {
		$cols = array(
			'type'      => array( 'type', false ),
			'value'     => array( 'value', false ),
			'records'   => array( 'records', false ),
			'size'      => array( 'size', false ),
			'confianza' => array( 'confianza', false ),
		);
		if ( $this->show_projects ) {
			$cols['project'] = array( 'project', false );
		}
		return $cols;
	}

	/**
	 * Render the Records column: number of DB rows for this entry.
	 *
	 * For meta buckets the count comes from a GROUP BY query (exact).
	 * For other types each entry is unique (options, roles, tables…) so the
	 * minimum is 1 — we show 1 when no precise count is available.
	 *
	 * @param array $item Row data.
	 * @return string Safe HTML.
	 */
	public function column_records( $item ) {
		$count = (int) ( $item['count_rows'] ?? 0 );
		// Any item that exists in the DB has at least 1 record.
		if ( $count < 1 ) {
			$count = 1;
		}
		return esc_html( number_format_i18n( $count ) );
	}

	public function column_size( $item ) {
		$type  = strtolower( (string) ( $item['type'] ?? '' ) );
		$bytes = isset( $item['size_bytes'] ) ? (int) $item['size_bytes'] : 0;
		// For content types size_bytes holds row count, not bytes.
		if ( in_array( $type, array( 'post_type_content', 'taxonomy_term' ), true ) ) {
			return esc_html( number_format_i18n( $bytes ) . ' ' . _n( 'row', 'rows', $bytes, 'wpoptimizing' ) );
		}
		return esc_html( size_format( max( 0, $bytes ), 2 ) );
	}

	protected function get_row_value( $item ) {
		return (string) ( ( $item['type'] ?? '' ) . '::' . ( $item['value'] ?? '' ) );
	}
}

/**
 * FS list table.
 */
class WPOptimizing_FS_List_Table extends WPOptimizing_Base_List_Table {
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name = 'fs_selected[]';
	}

	public function get_columns() {
		// Path first so it stays visible when the table collapses on mobile.
		$cols = array(
			'relative_path' => esc_html__( 'Path relative to wp-content', 'wpoptimizing' ),
			'type'          => esc_html__( 'Type', 'wpoptimizing' ),
			'size'          => esc_html__( 'Size', 'wpoptimizing' ),
		);
		if ( $this->selectable ) {
			$cols = array_merge( array( 'cb' => '<input type="checkbox" />' ), $cols );
		}
		if ( $this->show_projects ) {
			$cols['project'] = esc_html__( 'Extension', 'wpoptimizing' );
		}
		return $cols;
	}

	public function get_sortable_columns() {
		$cols = array(
			'type'          => array( 'type', false ),
			'relative_path' => array( 'relative_path', false ),
			'size'          => array( 'size', false ),
		);
		if ( $this->show_projects ) {
			$cols['project'] = array( 'project', false );
		}
		return $cols;
	}

	public function column_size( $item ) {
		$bytes = isset( $item['size_bytes'] ) ? (int) $item['size_bytes'] : 0;
		return esc_html( size_format( max( 0, $bytes ), 2 ) );
	}

	protected function get_row_value( $item ) {
		return (string) ( $item['relative_path'] ?? '' );
	}

	/**
	 * Checkbox value in "type::relative_path" form, as delete_fs_paths() expects
	 * (it splits on "::" and resolves the path under wp-content). The plain
	 * relative_path alone has no "::" and would be skipped.
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function cb_value( $item ) {
		$rel = (string) ( $item['relative_path'] ?? '' );
		if ( '' === $rel ) {
			return '';
		}
		$type = (string) ( $item['type'] ?? '' );
		if ( '' === $type ) {
			$type = 'fs';
		}
		return $type . '::' . $rel;
	}

	public function bulk_scope() {
		return 'fs';
	}
}

/**
 * Projects (plugins/themes) list table.
 */
class WPOptimizing_Projects_List_Table extends WPOptimizing_Base_List_Table {
	/** @var string */
	protected $action_mode = 'uninstall';

	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->action_mode = isset( $args['action_mode'] ) ? (string) $args['action_mode'] : 'uninstall';
	}

	public function get_columns() {
		// Name first so it stays visible when the table collapses on mobile.
		$cols = array(
			'name' => esc_html__( 'Name', 'wpoptimizing' ),
			'type' => esc_html__( 'Type', 'wpoptimizing' ),
			'slug' => esc_html__( 'Slug', 'wpoptimizing' ),
		);
		if ( $this->selectable ) {
			$cols = array_merge( array( 'cb' => '<input type="checkbox" />' ), $cols );
		}
		return $cols;
	}

	public function column_cb( $item ) {
		if ( ! $this->selectable ) {
			return '';
		}
		$kind          = isset( $item['kind'] ) ? (string) $item['kind'] : 'plugin';
		$is_deactivate = ( 'deactivate' === $this->action_mode );
		if ( 'theme' === $kind ) {
			$name = $is_deactivate ? 'deactivate_themes[]' : 'uninstall_themes[]';
		} else {
			$name = $is_deactivate ? 'deactivate_plugins[]' : 'uninstall_plugins[]';
		}
		$slug = (string) ( $item['slug'] ?? '' );
		// data-label: the confirmation names what is about to be uninstalled/deactivated.
		return sprintf(
			'<input type="checkbox" name="%s" value="%s" data-label="%s" />',
			esc_attr( $name ),
			esc_attr( $slug ),
			esc_attr( (string) ( $item['name'] ?? $slug ) )
		);
	}

	public function column_default( $item, $column_name ) {
		if ( 'slug' === $column_name ) {
			return '<code>' . esc_html( (string) ( $item['slug'] ?? '' ) ) . '</code>';
		}
		if ( isset( $item[ $column_name ] ) ) {
			return esc_html( (string) $item[ $column_name ] );
		}
		return '';
	}

	protected function get_table_classes() {
		return array_merge( array( 'wpopt-proj' ), parent::get_table_classes() );
	}

	// Column widths for wpopt-proj are handled by admin.css (.wpopt-proj rules).
}

/**
 * List table for autoloaded options (Autoload tab, premium).
 * Items: array{name,size,component,orphan,confident}.
 */
class WPOptimizing_Autoload_List_Table extends WPOptimizing_Base_List_Table {
	/** @var bool Whether to show the measured read-frequency column. */
	protected $show_reads = false;

	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name    = isset( $args['cb_name'] ) ? (string) $args['cb_name'] : 'autoload_selected[]';
		$this->show_reads = ! empty( $args['show_reads'] );
	}

	public function get_columns() {
		$cols = array(
			'name'      => esc_html__( 'Option', 'wpoptimizing' ),
			'component' => esc_html__( 'Attribution', 'wpoptimizing' ),
			'crowd'     => esc_html__( 'Crowd', 'wpoptimizing' ),
			'size'      => esc_html__( 'Weight', 'wpoptimizing' ),
		);
		if ( $this->show_reads ) {
			$cols['reads'] = esc_html__( 'Read on', 'wpoptimizing' );
		}
		if ( $this->selectable ) {
			$cols = array_merge( array( 'cb' => '<input type="checkbox" />' ), $cols );
		}
		return $cols;
	}

	public function get_sortable_columns() {
		return array(
			'name' => array( 'name', false ),
			'size' => array( 'size', true ),
		);
	}

	protected function get_row_value( $item ) {
		return (string) ( $item['name'] ?? '' );
	}

	/**
	 * Core autoload options are never selectable; everything else is keyed by
	 * its option name (what wpoptimizing_autoload_disable() expects).
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function cb_value( $item ) {
		if ( 'wordpress-core' === ( $item['component'] ?? '' ) ) {
			return '';
		}
		return (string) ( $item['name'] ?? '' );
	}

	public function bulk_scope() {
		return 'autoload';
	}

	public function get_sort_value( $item, $orderby ) {
		if ( 'size' === $orderby ) {
			return (int) ( $item['size'] ?? 0 );
		}
		return strtolower( (string) ( $item['name'] ?? '' ) );
	}

	public function column_cb( $item ) {
		if ( ! $this->selectable ) {
			return '';
		}
		// Core options are never selectable (never touch core autoload).
		if ( 'wordpress-core' === ( $item['component'] ?? '' ) ) {
			return '';
		}
		return sprintf(
			'<input type="checkbox" name="%s" value="%s" />',
			esc_attr( $this->cb_name ),
			esc_attr( (string) ( $item['name'] ?? '' ) )
		);
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'name':
				return $this->ui->record_query_button( 'option', (string) ( $item['name'] ?? '' ), '<code class="wpopt-code-sm">' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</code>' );
			case 'crowd':
				$sites = (int) ( $item['crowd_orphan_sites'] ?? 0 );
				if ( $sites <= 0 ) {
					return '<span class="wpopt-muted">—</span>';
				}
				return '<span class="wpopt-badge-crowd" title="' . esc_attr__( 'Other sites confirm this autoloaded option belongs to an uninstalled extension — likely safe to disable.', 'wpoptimizing' ) . '">' . esc_html(
					sprintf(
						/* translators: %s: number of sites */
						_n( 'orphan · %s site', 'orphan · %s sites', $sites, 'wpoptimizing' ),
						number_format_i18n( $sites )
					)
				) . '</span>';
			case 'size':
				return '<span class="wpopt-nowrap">' . esc_html( size_format( (int) ( $item['size'] ?? 0 ), 1 ) ) . '</span>';
			case 'reads':
				$ratio = isset( $item['ratio'] ) ? (float) $item['ratio'] : 0;
				$pct   = round( $ratio * 100 );
				$reads = (int) ( $item['reads'] ?? 0 );
				$req   = (int) ( $item['requests'] ?? 0 );
				return '<span class="wpopt-reads-pct">' . esc_html( $pct . '%' ) . '</span>'
					. '<br><span class="wpopt-meta">' . esc_html( $reads . ' / ' . $req ) . '</span>';
			case 'component':
				$comp = (string) ( $item['component'] ?? '' );
				if ( 'wordpress-core' === $comp ) {
					return '<span class="wpopt-muted">' . esc_html__( 'WordPress core', 'wpoptimizing' ) . '</span>';
				}
				if ( 'transient' === $comp ) {
					return '<span class="wpopt-meta">' . esc_html__( 'Transient', 'wpoptimizing' ) . '</span>';
				}
				// Resolve the owner slug to its plugin/theme display name (installed → wp.org
				// cache → slug), so the column reads "UpdraftPlus", not "updraftplus".
				// Several owners come as `slug|slug2`: each one by its own name, never the whole cell as one slug.
				$comp_name = $comp;
				if ( '' !== $comp && 'unknown' !== $comp && method_exists( $this->ui, 'ext_display_name' ) ) {
					$comp_name = implode( ', ', array_map( array( $this->ui, 'ext_display_name' ), array_filter( array_map( 'trim', explode( '|', $comp ) ) ) ) );
				}
				if ( ! empty( $item['confident'] ) && ! empty( $item['orphan'] ) ) {
					return '<span class="wpopt-badge-orphan">'
						. esc_html__( 'Orphan', 'wpoptimizing' ) . '</span> <span class="wpopt-key">' . esc_html( $comp_name ) . '</span>';
				}
				// Nobody claims it: say so, instead of "likely orphan" of no one.
				if ( ! empty( $item['orphan'] ) && ( '' === $comp || 'unknown' === $comp ) ) {
					return '<span class="wpopt-muted" title="' . esc_attr__( 'No installed plugin or theme, the scan or other sites claim this option. It may be left over from something uninstalled, or belong to code WPOptimizing cannot see.', 'wpoptimizing' ) . '">'
						. esc_html__( 'Unattributed', 'wpoptimizing' ) . '</span>';
				}
				if ( ! empty( $item['orphan'] ) ) {
					return '<span class="wpopt-badge-likely">'
						. esc_html__( 'Likely orphan', 'wpoptimizing' ) . '</span> <span class="wpopt-key">' . esc_html( $comp_name ) . '</span>';
				}
				return '<span class="wpopt-code-sm">' . esc_html( 'unknown' === $comp ? '—' : $comp_name ) . '</span>';
		}
		return '';
	}
}

/**
 * List table for orphan media files (Orphan files / thumbnails / Other files).
 * Items: { file, size_bytes, type }. Provides search, sort and pagination.
 */
class WPOptimizing_Media_List_Table extends WPOptimizing_Base_List_Table {
	/** @var string wp-content-relative uploads prefix; '' = not deletable. */
	protected $delete_prefix = '';
	/** @var string Uploads base URL for thumbnails. */
	protected $baseurl = '';
	/** @var bool Show the suspected-owner column (Other files). */
	protected $show_owner = false;

	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name       = 'fs_selected[]';
		$this->delete_prefix = isset( $args['delete_prefix'] ) ? (string) $args['delete_prefix'] : '';
		$this->baseurl       = isset( $args['baseurl'] ) ? (string) $args['baseurl'] : '';
		$this->show_owner    = ! empty( $args['show_owner'] );
		$this->selectable    = '' !== $this->delete_prefix && ! empty( $args['can_delete'] );
	}

	protected function get_table_classes() {
		return array_merge( array( 'wpopt-media' ), parent::get_table_classes() );
	}

	public function get_columns() {
		$cols = array(
			'preview' => esc_html__( 'Preview', 'wpoptimizing' ),
			'file'    => esc_html__( 'File (relative to uploads)', 'wpoptimizing' ),
		);
		if ( $this->show_owner ) {
			$cols['owner'] = esc_html__( 'Suspected owner', 'wpoptimizing' );
		}
		$cols['size'] = esc_html__( 'Size', 'wpoptimizing' );
		if ( $this->selectable ) {
			$cols = array_merge( array( 'cb' => '<input type="checkbox" />' ), $cols );
		}
		return $cols;
	}

	public function get_sortable_columns() {
		return array(
			'file' => array( 'file', false ),
			'size' => array( 'size', true ),
		);
	}

	protected function get_row_value( $item ) {
		return (string) ( $item['file'] ?? '' );
	}

	/**
	 * Checkbox value "media::<wp-content-relative path>", handled by
	 * delete_fs_paths() (the media orphans share the FS delete pipeline).
	 *
	 * @param array $item Row data.
	 * @return string
	 */
	public function cb_value( $item ) {
		$file = (string) ( $item['file'] ?? '' );
		if ( '' === $file ) {
			return '';
		}
		return 'media::' . ltrim( $this->delete_prefix, '/' ) . $file;
	}

	public function bulk_scope() {
		return 'fs';
	}

	public function get_sort_value( $item, $orderby ) {
		if ( 'size' === $orderby ) {
			return (int) ( $item['size_bytes'] ?? 0 );
		}
		return strtolower( (string) ( $item['file'] ?? '' ) );
	}

	public function column_cb( $item ) {
		if ( ! $this->selectable ) {
			return '';
		}
		$val = $this->cb_value( $item );
		if ( '' === $val ) {
			return '';
		}
		return sprintf( '<input type="checkbox" name="%s" value="%s" />', esc_attr( $this->cb_name ), esc_attr( $val ) );
	}

	public function column_default( $item, $column_name ) {
		$file = (string) ( $item['file'] ?? '' );
		switch ( $column_name ) {
			case 'preview':
				$type = (string) ( $item['type'] ?? pathinfo( $file, PATHINFO_EXTENSION ) );
				return $this->ui->media_thumb_cell( $file, $type, $this->baseurl );
			case 'file':
				return '<code class="wpopt-code-wrap">' . esc_html( $file ) . '</code>';
			case 'owner':
				return esc_html( $this->ui->media_suspected_owner( $file ) );
			case 'size':
				return '<span class="wpopt-nowrap">' . esc_html( size_format( (int) ( $item['size_bytes'] ?? 0 ), 1 ) ) . '</span>';
		}
		return '';
	}
}


/**
 * List table for potentially-unused attachments: thumbnail, ID, file and size,
 * with native search, sorting and pagination. Optional bulk delete (premium) makes
 * a backup of every file FIRST and never deletes a set that could not be fully
 * backed up. Still heuristic — surfaced for review.
 */
class WPOptimizing_Unused_List_Table extends WPOptimizing_Base_List_Table {

	/**
	 * Uploads base URL (for thumbnails).
	 *
	 * @var string
	 */
	protected $baseurl = '';

	/**
	 * Whether delete actions are available (active license).
	 *
	 * @var bool
	 */
	protected $can_delete = false;

	/**
	 * Constructor — uses the shared base engine (same as the other media tables)
	 * so the filter / per-page / search / pagination controls are uniform. Rows
	 * arrive already filtered by the caller via WPOPT_Admin_UI::filter_media_rows().
	 *
	 * @param WPOPT_Admin_UI $ui            Owning UI.
	 * @param array        $rows          Candidate rows ({id,file,bytes}).
	 * @param bool         $checked       Kept for the base signature (unused here).
	 * @param bool         $show_projects Kept for the base signature (unused here).
	 * @param array        $args          scope/per_page/type_filter/type_choices/baseurl/can_delete.
	 */
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name    = 'mu_selected[]';
		$this->baseurl    = isset( $args['baseurl'] ) ? (string) $args['baseurl'] : '';
		$this->can_delete = ! empty( $args['can_delete'] );
		// Always selectable: even without a license the (non-destructive) "Mark as
		// known" action is available, so free users can dismiss false positives.
		$this->selectable = true;
	}

	/**
	 * Table CSS classes (adds wpopt-media + attachments for the shared styles).
	 *
	 * @return array<int,string>
	 */
	protected function get_table_classes() {
		return array_merge( array( 'wpopt-media', 'attachments' ), parent::get_table_classes() );
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$cols = array();
		if ( $this->selectable ) {
			$cols['cb'] = '<input type="checkbox" />';
		}
		$cols['preview'] = esc_html__( 'Preview', 'wpoptimizing' );
		$cols['id']      = esc_html__( 'ID', 'wpoptimizing' );
		$cols['file']    = esc_html__( 'File', 'wpoptimizing' );
		$cols['size']    = esc_html__( 'Size', 'wpoptimizing' );
		return $cols;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'id'   => array( 'id', false ),
			'file' => array( 'file', false ),
			'size' => array( 'size', true ),
		);
	}

	/**
	 * Row value (attachment ID) used by the "process all" helper.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function get_row_value( $item ) {
		$id = (int) ( $item['id'] ?? 0 );
		return $id > 0 ? (string) $id : '';
	}

	/**
	 * Checkbox value — the attachment ID, deleted via the async bulk engine
	 * (run_bulk_batch 'unused' → delete_unused_batch()).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function cb_value( $item ) {
		return $this->get_row_value( $item );
	}

	/**
	 * Async bulk-engine scope (maps to run_bulk_batch's 'unused' case). Only when
	 * deletion is available; otherwise the controls stay hidden.
	 *
	 * @return string
	 */
	public function bulk_scope() {
		return $this->can_delete ? 'unused' : '';
	}

	/**
	 * Sort value per column ({id,file,bytes}).
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return mixed
	 */
	public function get_sort_value( $item, $orderby ) {
		if ( 'size' === $orderby ) {
			return (int) ( $item['bytes'] ?? 0 );
		}
		if ( 'id' === $orderby ) {
			return (int) ( $item['id'] ?? 0 );
		}
		return strtolower( (string) ( $item['file'] ?? '' ) );
	}

	/**
	 * Thumbnail preview cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_preview( $item ) {
		$file = (string) ( $item['file'] ?? '' );
		$ext  = strtolower( (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		return $this->ui->media_thumb_cell( $file, $ext, $this->baseurl );
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No matching attachments.', 'wpoptimizing' );
	}

	/**
	 * Render a cell.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		switch ( $column ) {
			case 'id':
				$id   = (string) (int) ( $item['id'] ?? 0 );
				$edit = get_edit_post_link( (int) ( $item['id'] ?? 0 ) );
				// The ID opens the record viewer; the edit screen stays one click away.
				return $this->ui->record_query_button( 'attachment', $id, esc_html( $id ) )
					. ( $edit ? ' <a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener" class="wpopt-meta" aria-label="' . esc_attr__( 'Edit attachment', 'wpoptimizing' ) . '">' . wpoptimizing_icon( 'edit' ) . '</a>' : '' );
			case 'file':
				$file = (string) ( $item['file'] ?? '' );
				$url  = '' !== $this->baseurl ? trailingslashit( $this->baseurl ) . ltrim( $file, '/' ) : '';
				return '' !== $url
					? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><code>' . esc_html( $file ) . '</code></a>'
					: '<code>' . esc_html( $file ) . '</code>';
			case 'size':
				return esc_html( size_format( (int) ( $item['bytes'] ?? 0 ), 1 ) );
		}
		return '';
	}
}

/**
 * Image diagnostics list table ("Heaviest images"): a per-image, selectable view of the
 * worst-offender source images with thumbnail, dimensions, weight, capability-aware issue
 * badges and an estimated saving. When the server can process images and the plan is Pro, the
 * selected rows can be optimized in bulk (run_bulk_batch 'image_opt'); otherwise it is a
 * read-only report. Rows: { rel, ext, w, h, bytes, oversized, no_webp, no_avif, no_nextgen, save }.
 */
class WPOptimizing_Image_Diag_List_Table extends WPOptimizing_Base_List_Table {

	/** @var string Uploads base URL (thumbnails + file links). */
	protected $baseurl = '';
	/** @var bool Can generate WebP on this server. */
	protected $webp_ok = false;
	/** @var bool Can generate AVIF on this server. */
	protected $avif_ok = false;
	/** @var bool Can recompress/resize on this server. */
	protected $comp_ok = false;

	/**
	 * Constructor.
	 *
	 * @param WPOPT_Admin_UI $ui            Owning UI.
	 * @param array        $rows          Worst-offender rows.
	 * @param bool         $checked       Unused (base signature).
	 * @param bool         $show_projects Unused (base signature).
	 * @param array        $args          scope/per_page/type_filter/type_choices/baseurl/webp_ok/avif_ok/comp_ok/actionable.
	 */
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name    = 'img_selected[]';
		$this->baseurl    = isset( $args['baseurl'] ) ? (string) $args['baseurl'] : '';
		$this->webp_ok    = ! empty( $args['webp_ok'] );
		$this->avif_ok    = ! empty( $args['avif_ok'] );
		$this->comp_ok    = ! empty( $args['comp_ok'] );
		$this->selectable = ! empty( $args['actionable'] );
	}

	/**
	 * Table CSS classes (shared media/attachment styles + our diagnostics class).
	 *
	 * @return array<int,string>
	 */
	protected function get_table_classes() {
		return array_merge( array( 'wpopt-media', 'attachments', 'wpopt-img-detective' ), parent::get_table_classes() );
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$cols = array();
		if ( $this->selectable ) {
			$cols['cb'] = '<input type="checkbox" />';
		}
		$cols['preview']    = esc_html__( 'Preview', 'wpoptimizing' );
		$cols['id']         = esc_html__( 'ID', 'wpoptimizing' );
		$cols['file']       = esc_html__( 'Image', 'wpoptimizing' );
		$cols['dimensions'] = esc_html__( 'Dimensions', 'wpoptimizing' );
		$cols['size']       = esc_html__( 'Size', 'wpoptimizing' );
		$cols['issues']     = esc_html__( 'What would help', 'wpoptimizing' );
		$cols['save']       = esc_html__( 'Est. saving', 'wpoptimizing' );
		return $cols;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'id'   => array( 'id', false ),
			'file' => array( 'file', false ),
			'size' => array( 'size', true ),
			'save' => array( 'save', true ),
		);
	}

	/**
	 * Sort value per column.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return mixed
	 */
	public function get_sort_value( $item, $orderby ) {
		if ( 'size' === $orderby ) {
			return (int) ( $item['bytes'] ?? 0 );
		}
		if ( 'save' === $orderby ) {
			return (int) ( $item['save'] ?? 0 );
		}
		if ( 'id' === $orderby ) {
			return (int) ( $item['id'] ?? 0 );
		}
		return strtolower( (string) ( $item['rel'] ?? '' ) );
	}

	/**
	 * Row value / checkbox value: the path relative to uploads (consumed by
	 * run_bulk_batch 'image_opt').
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function get_row_value( $item ) {
		return (string) ( $item['rel'] ?? '' );
	}

	/**
	 * Checkbox value.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function cb_value( $item ) {
		return $this->get_row_value( $item );
	}

	/**
	 * Async bulk-engine scope: optimize the selected images (Pro + server-capable only).
	 *
	 * @return string
	 */
	public function bulk_scope() {
		return $this->selectable ? 'image_opt' : '';
	}

	/**
	 * Thumbnail preview cell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_preview( $item ) {
		$rel = (string) ( $item['rel'] ?? '' );
		$ext = strtolower( (string) ( $item['ext'] ?? pathinfo( $rel, PATHINFO_EXTENSION ) ) );
		// Non-image or no base URL: fall back to the shared cell (mime icon).
		if ( '' === $this->baseurl || ! ( class_exists( 'WPOPT_Media_Analyzer' ) && in_array( $ext, WPOPT_Media_Analyzer::IMAGE_EXTS, true ) ) ) {
			return $this->ui->media_thumb_cell( $rel, $ext, $this->baseurl );
		}
		// Enrich the shared lightbox hook with info + an edit link (read by render_media_modal).
		$url  = trailingslashit( $this->baseurl ) . ltrim( $rel, '/' );
		$w    = (int) ( $item['w'] ?? 0 );
		$h    = (int) ( $item['h'] ?? 0 );
		$meta = array();
		if ( $w > 0 && $h > 0 ) {
			$meta[] = number_format_i18n( $w ) . ' × ' . number_format_i18n( $h );
		}
		$meta[] = size_format( (int) ( $item['bytes'] ?? 0 ), 1 );
		$meta[] = strtoupper( $ext );
		$issues = trim( (string) wp_strip_all_tags( $this->issue_badges( $item ) ) );
		if ( '' !== $issues ) {
			$meta[] = $issues;
		}
		$id   = (int) ( $item['id'] ?? 0 );
		$edit = $id > 0 ? (string) get_edit_post_link( $id ) : '';
		return '<a href="#" class="wpopt-mthumb" data-full="' . esc_url( $url ) . '"'
			. ' data-title="' . esc_attr( basename( $rel ) ) . '"'
			. ' data-meta="' . esc_attr( implode( '   ·   ', $meta ) ) . '"'
			. ( '' !== $edit ? ' data-edit="' . esc_url( $edit ) . '"' : '' )
			. ' title="' . esc_attr__( 'View image', 'wpoptimizing' ) . '">'
			. '<img src="' . esc_url( $url ) . '" loading="lazy" alt="" class="wpopt-media-thumb" /></a>';
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No images need attention.', 'wpoptimizing' );
	}

	/**
	 * Render a cell.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		$rel = (string) ( $item['rel'] ?? '' );
		switch ( $column ) {
			case 'id':
				$id = (int) ( $item['id'] ?? 0 );
				if ( $id < 1 ) {
					return '—';
				}
				$edit = get_edit_post_link( $id );
				return $edit
					? '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener">' . esc_html( (string) $id ) . '</a>'
					: esc_html( (string) $id );
			case 'file':
				// Plain filename (no raw-image link) to match the other Media subsections;
				// the thumbnail cell is the single, consistent way to open the preview modal.
				$name = esc_html( basename( $rel ) );
				$ext  = '<span class="wpopt-img-ext">' . esc_html( strtoupper( (string) ( $item['ext'] ?? '' ) ) ) . '</span>';
				return '<code class="wpopt-code-wrap">' . $name . '</code> ' . $ext;
			case 'dimensions':
				$w = (int) ( $item['w'] ?? 0 );
				$h = (int) ( $item['h'] ?? 0 );
				return ( $w > 0 && $h > 0 ) ? esc_html( number_format_i18n( $w ) . ' × ' . number_format_i18n( $h ) ) : '—';
			case 'size':
				return esc_html( size_format( (int) ( $item['bytes'] ?? 0 ), 1 ) );
			case 'issues':
				return $this->issue_badges( $item );
			case 'save':
				// Only a WebP-conversion estimate, and only when WebP can actually be produced.
				$save = (int) ( $item['save'] ?? 0 );
				return ( $this->webp_ok && $save > 0 ) ? esc_html( '~' . size_format( $save, 1 ) ) : '—';
		}
		return '';
	}

	/**
	 * Capability-aware issue badges: never recommend a next-gen format the server cannot
	 * produce, nor a resize when recompression is unavailable.
	 *
	 * @param array $item Row.
	 * @return string Safe HTML.
	 */
	protected function issue_badges( $item ) {
		$out = '';
		if ( ! empty( $item['oversized'] ) && $this->comp_ok ) {
			$out .= '<span class="wpopt-img-badge wpopt-img-badge--bad">' . esc_html__( 'Oversized', 'wpoptimizing' ) . '</span> ';
		}
		if ( ! empty( $item['no_webp'] ) && $this->webp_ok ) {
			$out .= '<span class="wpopt-img-badge wpopt-img-badge--warn">' . esc_html__( 'No WebP', 'wpoptimizing' ) . '</span> ';
		}
		if ( ! empty( $item['no_avif'] ) && $this->avif_ok ) {
			$out .= '<span class="wpopt-img-badge wpopt-img-badge--warn">' . esc_html__( 'No AVIF', 'wpoptimizing' ) . '</span> ';
		}
		if ( '' === $out ) {
			$out = '<span class="wpopt-img-badge">' . esc_html__( 'Large file', 'wpoptimizing' ) . '</span>';
		}
		return trim( $out );
	}
}

/**
 * Cron events list table — read-only listing of WordPress scheduled events with
 * orphan flagging (hook without callback). Only orphaned events are selectable;
 * active and core events render without a checkbox. Uses the shared base engine
 * (search / per-page / pagination) like the other tables.
 */
class WPOptimizing_Cron_List_Table extends WPOptimizing_Base_List_Table {

	/**
	 * Whether unschedule actions are available (active license).
	 *
	 * @var bool
	 */
	protected $can_delete = false;

	/**
	 * Constructor.
	 *
	 * @param WPOPT_Admin_UI $ui            Owning UI.
	 * @param array        $rows          Cron rows from get_cron_events().
	 * @param bool         $checked       Kept for the base signature (unused).
	 * @param bool         $show_projects Kept for the base signature (unused).
	 * @param array        $args          scope/per_page/can_delete.
	 */
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name    = 'cron_selected[]';
		$this->can_delete = ! empty( $args['can_delete'] );
		$this->selectable = $this->can_delete;
	}

	/**
	 * Table CSS classes.
	 *
	 * @return array<int,string>
	 */
	protected function get_table_classes() {
		return array_merge( array( 'wpopt-cron' ), parent::get_table_classes() );
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$cols = array();
		if ( $this->selectable ) {
			$cols['cb'] = '<input type="checkbox" />';
		}
		$cols['hook']     = esc_html__( 'Hook', 'wpoptimizing' );
		$cols['schedule'] = esc_html__( 'Schedule', 'wpoptimizing' );
		$cols['next']     = esc_html__( 'Next run', 'wpoptimizing' );
		$cols['status']   = esc_html__( 'Status', 'wpoptimizing' );
		return $cols;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'hook'   => array( 'hook', false ),
			'next'   => array( 'next', false ),
			'status' => array( 'status', false ),
		);
	}

	/**
	 * Row value — only orphaned events are removable (others get no checkbox).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function get_row_value( $item ) {
		return ! empty( $item['orphan'] ) ? (string) ( $item['id'] ?? '' ) : '';
	}

	/**
	 * Checkbox value (encoded event id) — empty for non-orphan rows.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function cb_value( $item ) {
		return $this->get_row_value( $item );
	}

	/**
	 * Async bulk-engine scope (maps to run_bulk_batch's 'cron' case).
	 *
	 * @return string
	 */
	public function bulk_scope() {
		return $this->can_delete ? 'cron' : '';
	}

	/**
	 * Sort value per column.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return mixed
	 */
	public function get_sort_value( $item, $orderby ) {
		if ( 'next' === $orderby ) {
			return (int) ( $item['timestamp'] ?? 0 );
		}
		if ( 'status' === $orderby ) {
			return ! empty( $item['orphan'] ) ? 0 : ( ! empty( $item['core'] ) ? 2 : 1 );
		}
		return strtolower( (string) ( $item['hook'] ?? '' ) );
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No scheduled events.', 'wpoptimizing' );
	}

	/**
	 * Is this event's hook paused?
	 *
	 * @param array $item Row.
	 * @return bool
	 */
	protected function is_paused( $item ) {
		static $paused = null;
		if ( null === $paused ) {
			$paused = function_exists( 'wpoptimizing_cron_paused' ) ? array_flip( wpoptimizing_cron_paused() ) : array();
		}
		return isset( $paused[ (string) ( $item['hook'] ?? '' ) ] );
	}

	/**
	 * Row actions: run now, pause / resume. None for an orphan (nothing would run), and no pause
	 * for a WordPress core task.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function task_actions( $item ) {
		if ( ! empty( $item['orphan'] ) || ! method_exists( $this->ui, 'cron_task_url' ) ) {
			return '';
		}
		$hook    = (string) ( $item['hook'] ?? '' );
		$args    = isset( $item['args'] ) && is_array( $item['args'] ) ? $item['args'] : array();
		$paused  = $this->is_paused( $item );
		$actions = array();
		if ( ! $paused ) {
			$actions[] = '<a href="' . esc_url( $this->ui->cron_task_url( 'run', $hook, $args ) ) . '">' . esc_html__( 'Run now', 'wpoptimizing' ) . '</a>';
		}
		if ( empty( $item['core'] ) ) {
			$actions[] = $paused
				? '<a href="' . esc_url( $this->ui->cron_task_url( 'resume', $hook ) ) . '">' . esc_html__( 'Resume', 'wpoptimizing' ) . '</a>'
				: '<a href="' . esc_url( $this->ui->cron_task_url( 'pause', $hook ) ) . '">' . esc_html__( 'Pause', 'wpoptimizing' ) . '</a>';
		}
		return '<div class="row-actions">' . implode( ' | ', $actions ) . '</div>';
	}

	/**
	 * Render a cell.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		switch ( $column ) {
			case 'hook':
				$hook = (string) ( $item['hook'] ?? '' );
				$args = ! empty( $item['args'] )
					? ' <span class="wpopt-meta">' . esc_html( (string) wp_json_encode( $item['args'] ) ) . '</span>'
					: '';
				return $this->ui->record_query_button( 'cron', $hook, '<code>' . esc_html( $hook ) . '</code>' ) . $args . $this->task_actions( $item );
			case 'schedule':
				$sched     = (string) ( $item['schedule'] ?? 'once' );
				$schedules = wp_get_schedules();
				if ( isset( $schedules[ $sched ]['display'] ) ) {
					return esc_html( (string) $schedules[ $sched ]['display'] );
				}
				return 'once' === $sched ? esc_html__( 'One-time', 'wpoptimizing' ) : esc_html( $sched );
			case 'next':
				$ts = (int) ( $item['timestamp'] ?? 0 );
				if ( $ts <= 0 ) {
					return '&mdash;';
				}
				$human = human_time_diff( $ts, time() );
				$when  = $ts > time()
					/* translators: %s: human-readable time difference. */
					? sprintf( __( 'in %s', 'wpoptimizing' ), $human )
					/* translators: %s: human-readable time difference. */
					: sprintf( __( '%s ago', 'wpoptimizing' ), $human );
				return esc_html( wp_date( 'Y-m-d H:i', $ts ) . ' (' . $when . ')' );
			case 'status':
				if ( $this->is_paused( $item ) ) {
					return '<span class="wpopt-conf-badge wpopt-conf-heuristic">' . esc_html__( 'Paused', 'wpoptimizing' ) . '</span>';
				}
				if ( ! empty( $item['orphan'] ) ) {
					return '<span class="wpopt-conf-badge wpopt-conf-heuristic">' . esc_html__( 'Orphaned', 'wpoptimizing' ) . '</span>';
				}
				if ( ! empty( $item['core'] ) ) {
					return '<span class="wpopt-conf-badge wpopt-conf-certain">' . esc_html__( 'WordPress core', 'wpoptimizing' ) . '</span>';
				}
				return '<span class="wpopt-conf-badge wpopt-conf-strong">' . esc_html__( 'Active', 'wpoptimizing' ) . '</span>';
		}
		return '';
	}
}

/**
 * Database tables list table — read-only inventory (engine, rows, size, overhead)
 * with selective OPTIMIZE. Uses the shared base engine.
 */
class WPOptimizing_Tables_List_Table extends WPOptimizing_Base_List_Table {

	/**
	 * Whether OPTIMIZE actions are available (active license).
	 *
	 * @var bool
	 */
	protected $can_optimize = false;

	/**
	 * Constructor.
	 *
	 * @param WPOPT_Admin_UI $ui            Owning UI.
	 * @param array        $rows          Table rows from get_db_tables().
	 * @param bool         $checked       Kept for the base signature (unused).
	 * @param bool         $show_projects Kept for the base signature (unused).
	 * @param array        $args          scope/per_page/can_optimize.
	 */
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name      = 'tables_selected[]';
		$this->can_optimize = ! empty( $args['can_optimize'] );
		$this->selectable   = $this->can_optimize;
	}

	/**
	 * Table CSS classes.
	 *
	 * @return array<int,string>
	 */
	protected function get_table_classes() {
		return array_merge( array( 'wpopt-tables' ), parent::get_table_classes() );
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		$cols = array();
		if ( $this->selectable ) {
			$cols['cb'] = '<input type="checkbox" />';
		}
		$cols['name']     = esc_html__( 'Table', 'wpoptimizing' );
		$cols['engine']   = esc_html__( 'Engine', 'wpoptimizing' );
		$cols['rows']     = esc_html__( 'Rows', 'wpoptimizing' );
		$cols['size']     = esc_html__( 'Size', 'wpoptimizing' );
		$cols['overhead'] = esc_html__( 'Overhead', 'wpoptimizing' );
		$cols['owner']    = esc_html__( 'Suspected owner', 'wpoptimizing' );
		return $cols;
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string,array>
	 */
	public function get_sortable_columns() {
		return array(
			'name'     => array( 'name', false ),
			'engine'   => array( 'engine', false ),
			'rows'     => array( 'rows', false ),
			'size'     => array( 'size', true ),
			'overhead' => array( 'overhead', true ),
		);
	}

	/**
	 * Row value (table name).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function get_row_value( $item ) {
		return (string) ( $item['name'] ?? '' );
	}

	/**
	 * Checkbox value (table name).
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function cb_value( $item ) {
		return $this->get_row_value( $item );
	}

	/**
	 * Async bulk-engine scope (maps to run_bulk_batch's 'optimize_tables' case).
	 *
	 * @return string
	 */
	public function bulk_scope() {
		return $this->can_optimize ? 'optimize_tables' : '';
	}

	/**
	 * Sort value per column.
	 *
	 * @param array  $item    Row.
	 * @param string $orderby Column.
	 * @return mixed
	 */
	public function get_sort_value( $item, $orderby ) {
		switch ( $orderby ) {
			case 'rows':
				return (int) ( $item['rows'] ?? 0 );
			case 'size':
				return (int) ( $item['size_bytes'] ?? 0 );
			case 'overhead':
				return (int) ( $item['overhead_bytes'] ?? 0 );
			case 'engine':
				return strtolower( (string) ( $item['engine'] ?? '' ) );
		}
		return strtolower( (string) ( $item['name'] ?? '' ) );
	}

	/**
	 * Empty state.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No tables found.', 'wpoptimizing' );
	}

	/**
	 * Render a cell.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column key.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		switch ( $column ) {
			case 'name':
				return $this->ui->record_query_button( 'table', (string) ( $item['name'] ?? '' ), '<code>' . esc_html( (string) ( $item['name'] ?? '' ) ) . '</code>' );
			case 'engine':
				return esc_html( (string) ( $item['engine'] ?? '—' ) );
			case 'rows':
				return esc_html( number_format_i18n( (int) ( $item['rows'] ?? 0 ) ) );
			case 'size':
				return esc_html( size_format( (int) ( $item['size_bytes'] ?? 0 ), 1 ) );
			case 'overhead':
				$ov = (int) ( $item['overhead_bytes'] ?? 0 );
				// Only show overhead OPTIMIZE can actually reclaim (MyISAM/Aria). On
				// InnoDB the free space is reused internally, so showing it here would
				// imply a cleanup that never happens — render a dash instead.
				if ( $ov <= 0 || empty( $item['overhead_reclaimable'] ) ) {
					return '&mdash;';
				}
				return '<span class="wpopt-warn">' . esc_html( size_format( $ov, 1 ) ) . '</span>';
			case 'owner':
				// owner_html is pre-escaped by WPOPT_Admin_UI::format_projects_nicely().
				$html   = (string) ( $item['owner_html'] ?? '' );
				$status = (string) ( $item['owner_status'] ?? '' );
				if ( '' === $html || '<em>-</em>' === $html ) {
					return '&mdash;';
				}
				// Deletion stays in the curated, backup-first Orphans flow; from here we
				// only point to it when a table looks orphaned.
				if ( 'orphan' === $status ) {
					$url = add_query_arg(
						array(
							'page' => 'wpoptimizing',
							'tab'  => 'orphans',
							'osec' => 'bd',
						),
						admin_url( 'admin.php' )
					);
					return '<span class="wpopt-warn">' . $html . '</span><br>'
						. '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage in Orphans', 'wpoptimizing' ) . ' &rarr;</a>';
				}
				return $html;
		}
		return '';
	}
}


/**
 * Manual-attribution rules list. Reuses the shared list-table framework (same
 * search box, owner filter, per-page selector and pagination as every section);
 * not selectable / no async bulk. Each row carries its original index for removal
 * via a nonced GET link (handled in render_pending_tab).
 */
class WPOptimizing_Manual_Rules_List_Table extends WPOptimizing_Base_List_Table {

	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->selectable = false;
	}

	public function get_columns() {
		return array(
			'match'  => esc_html__( 'Match', 'wpoptimizing' ),
			'owner'  => esc_html__( 'Owner', 'wpoptimizing' ),
			'action' => '',
		);
	}

	public function get_sortable_columns() {
		return array();
	}

	protected function get_default_primary_column_name() {
		return 'match';
	}

	public function column_match( $item ) {
		$scope = ( 'exact' === ( $item['scope'] ?? '' ) ) ? __( 'exact', 'wpoptimizing' ) : __( 'contains', 'wpoptimizing' );
		return '<code>' . esc_html( (string) ( $item['match'] ?? '' ) ) . '</code> <span class="description">(' . esc_html( $scope ) . ')</span>';
	}

	public function column_owner( $item ) {
		$slug  = (string) ( $item['slug'] ?? '' );
		$name  = method_exists( $this->ui, 'manual_rule_owner_name' ) ? (string) $this->ui->manual_rule_owner_name( (array) $item ) : $slug;
		$tipos = array(
			'plugin'  => __( 'Plugin', 'wpoptimizing' ),
			'theme'   => __( 'Theme', 'wpoptimizing' ),
			'library' => __( 'Library', 'wpoptimizing' ),
			'core'    => __( 'WordPress core', 'wpoptimizing' ),
		);
		$type  = $tipos[ (string) ( $item['type'] ?? '' ) ] ?? $tipos['plugin'];
		// The slug next to the name only when they differ: `tgmpa tgmpa` said nothing twice.
		return esc_html( $name ) . ( $name !== $slug ? ' <code>' . esc_html( $slug ) . '</code>' : '' ) . ' · ' . esc_html( $type );
	}

	public function column_action( $item ) {
		// Keyed by rule identity, never by position: positions shift on every removal, so an
		// index carried by a stale link would delete whichever rule now sits in that slot.
		$key = (string) ( $item['key'] ?? '' );
		if ( '' === $key ) {
			return '';
		}
		$url = wp_nonce_url( add_query_arg( 'wpopt_ma_remove', $key ), 'wpopt_ma_remove_' . $key, '_manonce' );
		return '<a class="button-link delete wpopt-ma-remove" href="' . esc_url( $url ) . '">' . esc_html__( 'Remove', 'wpoptimizing' ) . '</a>';
	}
}

/**
 * Empty-records list table. Extends the DB table (so it inherits the Value/Type/Records/Size/
 * Confidence columns, the live-query value button and the type::value identity), and adds a
 * Status column (Pending/Orphan/Inactive/Active). Rows owned by an ACTIVE extension or by
 * WordPress core are read-only here: their checkbox is suppressed so they can never be
 * selected for deletion (they may be empty on purpose).
 */
class WPOptimizing_Empty_List_Table extends WPOptimizing_DB_List_Table {
	public function __construct( WPOPT_Admin_UI $ui, array $rows, $checked, $show_projects, array $args ) {
		parent::__construct( $ui, $rows, $checked, $show_projects, $args );
		$this->cb_name = 'empty_selected[]';
	}

	public function bulk_scope() {
		return 'empty';
	}

	/**
	 * Effective status for a row (active|inactive|orphan|none) via the shared engine.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	protected function row_status( $item ) {
		return $this->ui->get_effective_row_status( (array) $item );
	}

	/**
	 * A row is read-only when its owner is active or it is WordPress core (overlay marks core
	 * as active). Such rows expose no checkbox.
	 *
	 * @param array $item Row.
	 * @return bool
	 */
	protected function is_readonly_row( $item ) {
		return 'active' === $this->row_status( $item );
	}

	public function column_cb( $item ) {
		if ( ! $this->selectable || $this->is_readonly_row( $item ) ) {
			return '';
		}
		return parent::column_cb( $item );
	}

	public function get_columns() {
		// Value first (mobile-collapse keeps it visible), then Status, then the rest.
		$cols = array();
		if ( $this->selectable ) {
			$cols['cb'] = '<input type="checkbox" />';
		}
		$cols['value']     = esc_html__( 'Value', 'wpoptimizing' );
		$cols['status']    = esc_html__( 'Status', 'wpoptimizing' );
		$cols['type']      = esc_html__( 'Type', 'wpoptimizing' );
		$cols['records']   = esc_html__( 'Empty records', 'wpoptimizing' );
		$cols['confianza'] = esc_html__( 'Confidence', 'wpoptimizing' );
		if ( $this->show_projects ) {
			$cols['project'] = esc_html__( 'Extension', 'wpoptimizing' );
		}
		return $cols;
	}

	public function get_sortable_columns() {
		// 'status' is computed per row (not a stored column), so it is intentionally not
		// sortable — the rest mirror the DB table.
		$cols = array(
			'value'     => array( 'value', false ),
			'type'      => array( 'type', false ),
			'records'   => array( 'records', false ),
			'confianza' => array( 'confianza', false ),
		);
		if ( $this->show_projects ) {
			$cols['project'] = array( 'project', false );
		}
		return $cols;
	}

	/**
	 * Render the Status column as a coloured badge that mirrors the section it would live in.
	 *
	 * @param array $item Row.
	 * @return string Safe HTML.
	 */
	public function column_status( $item ) {
		$status = $this->row_status( $item );
		$labels = array(
			'active'   => __( 'In use', 'wpoptimizing' ),
			'inactive' => __( 'Inactive', 'wpoptimizing' ),
			'orphan'   => __( 'Orphan', 'wpoptimizing' ),
			'none'     => __( 'To identify', 'wpoptimizing' ),
		);
		$label  = $labels[ $status ] ?? $labels['none'];
		$html   = '<span class="wpopt-status-badge wpopt-status-' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
		if ( 'active' === $status ) {
			$html .= ' <span class="description wpopt-readonly-hint">' . esc_html__( 'read-only', 'wpoptimizing' ) . '</span>';
		}
		return $html;
	}
}

/**
 * Read-only list table for the Performance history timeline. Reuses the shared framework
 * (unified Select2 filters, per-page selector, pagination and search) instead of hand-rolled
 * controls. The trait precomputes each row's cell HTML (dates, event labels, metric values +
 * delta badges), so this class stays a thin, generic renderer.
 */
class WPOptimizing_History_List_Table extends WPOptimizing_Base_List_Table {

	/** @var array<string,string> column key => header label. */
	protected $columns_map = array();

	/**
	 * @param WPOPT_Admin_UI                   $ui   Admin UI.
	 * @param array<int,array<string,mixed>> $rows Prebuilt rows (each with a 'cells' map).
	 * @param array<string,mixed>            $args Table args (adds 'columns').
	 */
	public function __construct( WPOPT_Admin_UI $ui, array $rows, array $args ) {
		$args['selectable'] = false;
		parent::__construct( $ui, $rows, false, false, $args );
		$this->columns_map = ( isset( $args['columns'] ) && is_array( $args['columns'] ) ) ? $args['columns'] : array();
	}

	/**
	 * Column header map.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return $this->columns_map;
	}

	/**
	 * No sortable columns: rows arrive already ordered (newest first).
	 *
	 * @return array<string,mixed>
	 */
	public function get_sortable_columns() {
		return array();
	}

	/**
	 * Skip the base's request-driven sort; the trait pre-orders the rows.
	 *
	 * @return void
	 */
	protected function maybe_sort_items() {}

	/**
	 * Every cell is precomputed HTML built by the trait (already escaped).
	 *
	 * @param array<string,mixed> $item        Row.
	 * @param string              $column_name Column key.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return isset( $item['cells'][ $column_name ] ) ? (string) $item['cells'][ $column_name ] : '';
	}

	/**
	 * Empty-state message when filters match nothing.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No snapshots match these filters.', 'wpoptimizing' );
	}
}

/**
 * Backups list table — the same generic pre-rendered-cells engine as the History
 * timeline (search + filters + pagination for free), with a backups-specific empty
 * message. Rows carry a 'cells' map built by the Backups trait.
 */
class WPOptimizing_Backups_List_Table extends WPOptimizing_History_List_Table {

	/**
	 * Keep the fixed-width column styling the Backups tab relies on.
	 *
	 * @return string[]
	 */
	protected function get_table_classes() {
		return array_merge( array( 'wpopt-backups-table' ), parent::get_table_classes() );
	}

	/**
	 * Empty-state message when the search/filter matches no backup.
	 *
	 * @return void
	 */
	public function no_items() {
		esc_html_e( 'No backups match the current filter.', 'wpoptimizing' );
	}
}
