<?php

/**
 * Static code scanner for plugins and themes (refined).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPOPT_Code_Scanner
 *
 * Scans PHP/JS files in plugins or themes to detect DB/FS impact,
 * writing into:
 * - WPOPT_CSV_DB:      [ type, origin, project_slug, status, extracted_value ]
 * - WPOPT_CSV_FS_CODE: [ type, origin, project_slug, status, relative_file ]
 *
 * Hardening:
 * - De-duplicates rows.
 * - Ignores vendor/test folders and non-code assets.
 * - Accepts only literal string args for slugs/paths.
 * - Validates option/key slugs.
 * - Parses SQL blocks robustly and strips $wpdb prefixes.
 */
class WPOPT_Code_Scanner {

	/** @var string Base path to scan (plugins or themes). */
	protected $base_path;

	/** @var string Origin: 'plugin' | 'theme'. */
	protected $origin;

	/** @var array<string,string> Search patterns. */
	protected $patterns = array(
		// ---- DB / WP ecosystem ----
		'/\bregister_post_type\s*\(/i'            => 'post_type',
		'/\bregister_taxonomy\s*\(/i'             => 'taxonomy',
		'/\badd_role\s*\(/i'                      => 'role',
		'/\badd_option\s*\(/i'                    => 'option',
		'/\bupdate_option\s*\(/i'                 => 'update_option',
		'/\bdelete_option\s*\(/i'                 => 'delete_option',
		'/\bdbDelta\s*\(/i'                       => 'db_delta',
		'/\bCREATE\s+TABLE\b/i'                   => 'create_table',
		'/\badd_image_size\s*\(/i'                => 'image_size',

		// ---- Posts / Post Meta / User Meta ----
		'/\bwp_insert_post\s*\(/i'                => 'post_insert',
		'/\bwp_update_post\s*\(/i'                => 'post_update',

		'/\badd_post_meta\s*\(/i'                 => 'post_meta_add',
		'/\bupdate_post_meta\s*\(/i'              => 'post_meta_update',
		'/\bdelete_post_meta\s*\(/i'              => 'post_meta_delete',
		'/\bdelete_post_meta_by_key\s*\(/i'       => 'post_meta_delete_by_key',

		'/\badd_user_meta\s*\(/i'                 => 'user_meta_add',
		'/\bupdate_user_meta\s*\(/i'              => 'user_meta_update',
		'/\bdelete_user_meta\s*\(/i'              => 'user_meta_delete',

		// (Opcional, por si se usan funciones genéricas de meta en posts/usuarios)
		'/\badd_metadata\s*\(/i'                  => 'meta_add',
		'/\bupdate_metadata\s*\(/i'               => 'meta_update',
		'/\bdelete_metadata\s*\(/i'               => 'meta_delete',

		// Transients (set/delete) – detection strictly on write/delete ops
		'/\bset_transient\s*\(/i'                 => 'transient_set',
		'/\bdelete_transient\s*\(/i'              => 'transient_delete',

		'/\bload_plugin_textdomain\s*\(/i'        => 'i18n',
		'/\badd_shortcode\s*\(/i'                 => 'shortcode',
		'/\bregister_rest_route\s*\(/i'           => 'rest_namespace',
		'/\bregister_setting\s*\(/i'              => 'register_setting',

		// ---- Network / Site options ----
		'/\badd_site_option\s*\(/i'               => 'site_option',
		'/\bupdate_site_option\s*\(/i'            => 'site_update_option',
		'/\bdelete_site_option\s*\(/i'            => 'site_delete_option',

		// ---- Meta registration (CPT/Term/User) ----
		'/\bregister_post_meta\s*\(/i'            => 'register_post_meta',
		'/\bregister_term_meta\s*\(/i'            => 'register_term_meta',
		'/\bregister_meta\s*\(/i'                 => 'register_meta',

		// ---- Roles / Caps (beyond add_role) ----
		'/\bremove_role\s*\(/i'                   => 'role_remove',
		'/\badd_cap\s*\(/i'                       => 'cap_add',
		'/\bremove_cap\s*\(/i'                    => 'cap_remove',

		// ---- Widgets / Sidebars ----
		'/\bregister_sidebar\s*\(/i'              => 'register_sidebar',
		'/\bregister_widget\s*\(/i'               => 'register_widget',

		// ---- Cron events ----
		'/\bwp_schedule_event\s*\(/i'             => 'cron_schedule',
		'/\bwp_schedule_single_event\s*\(/i'      => 'cron_single',
		'/\bwp_clear_scheduled_hook\s*\(/i'       => 'cron_clear',
		'/\bwp_unschedule_hook\s*\(/i'            => 'cron_unschedule',

		// ---- Action Scheduler (WooCommerce and many others) ----
		// The hook name lives at a different argument position per function.
		'/\bas_enqueue_async_action\s*\(/i'       => 'as_async',
		'/\bas_schedule_single_action\s*\(/i'     => 'as_single',
		'/\bas_schedule_recurring_action\s*\(/i'  => 'as_recurring',
		'/\bas_schedule_cron_action\s*\(/i'       => 'as_cron',
		'/\bas_unschedule_all_actions\s*\(/i'     => 'as_unschedule_all',
		'/\bas_unschedule_action\s*\(/i'          => 'as_unschedule',

		// ---- Theme: nav menu locations / rewrite / theme support ----
		// (register_nav_menus must precede the singular: the singular regex would
		// not match the plural anyway, but keeping it first documents intent.)
		'/\bregister_nav_menus\s*\(/i'            => 'nav_menus',
		'/\bregister_nav_menu\s*\(/i'             => 'nav_menu',
		'/\badd_rewrite_endpoint\s*\(/i'          => 'rewrite_endpoint',
		'/\badd_rewrite_tag\s*\(/i'               => 'rewrite_tag',
		'/\badd_theme_support\s*\(/i'             => 'theme_support',

		// ---- Gutenberg blocks (PHP & JS) ----
		'/\bregister_block_type\s*\(/i'           => 'block_type_php',
		// JS patterns are informational
		'/\bwp\.blocks\.registerBlockType\s*\(/i' => 'block_type_js',

		// ---- Front-end assets (CSS/JS) — KB collection for Smart Assets ----
		// First arg is the handle; recorded with its owner so a crowd map of
		// "handle -> plugin/theme" accumulates for the future unloading feature.
		'/\bwp_enqueue_script\s*\(/i'             => 'asset_script',
		'/\bwp_register_script\s*\(/i'            => 'asset_script',
		'/\bwp_enqueue_style\s*\(/i'              => 'asset_style',
		'/\bwp_register_style\s*\(/i'             => 'asset_style',

		// ---- JS informational (ignored for CSV) ----
		'/\bwp\.apiFetch\b/i'                     => 'js_api_fetch',

		// ---- Filesystem / FS ----
		'/\bfile_put_contents\s*\(/i'             => 'file_write',
		'/\bmkdir\s*\(/i'                         => 'dir_create',
		'/\bwp_mkdir_p\s*\(/i'                    => 'dir_create',
		'/\bunlink\s*\(/i'                        => 'file_delete',
		'/\brename\s*\(/i'                        => 'file_rename',
		'/\bcopy\s*\(/i'                          => 'file_copy',
		'/\bmove_uploaded_file\s*\(/i'            => 'file_move_upload',
	);

	/** @var string[] Types considered DB impact. */
	protected $db_types = array(
		'post_type',          // Custom post types registered with register_post_type()
		'taxonomy',           // Custom taxonomies registered with register_taxonomy()
		'role',               // User roles created with add_role()
		'option',             // Options created with add_option()
		'update_option',      // Options updated with update_option()
		'delete_option',      // Options deleted with delete_option()
		'register_setting',   // Settings registered with register_setting()
		'db_delta',           // Database schema changes with dbDelta()
		'create_table',       // Raw CREATE TABLE statements
		'image_size',         // Custom image sizes registered with add_image_size()
		'transient_set',      // Transients set with set_transient()
		'transient_delete',   // Transients deleted with delete_transient()
		'i18n',               // Textdomain loading with load_plugin_textdomain()
		'shortcode',          // Shortcodes registered with add_shortcode()
		'rest_namespace',     // REST API namespace registered with register_rest_route()
		'post_insert',        // Insert new posts via wp_insert_post()
		'post_update',        // Update existing posts via wp_update_post()
		'post_meta_add',      // Post meta added with add_post_meta()
		'post_meta_update',   // Post meta updated with update_post_meta()
		'post_meta_delete',   // Post meta deleted with delete_post_meta()
		'post_meta_delete_by_key', // Post meta deleted by key with delete_post_meta_by_key()
		'user_meta_add',      // User meta added with add_user_meta()
		'user_meta_update',   // User meta updated with update_user_meta()
		'user_meta_delete',   // User meta deleted with delete_user_meta()
		'meta_add',           // Generic metadata added with add_metadata()
		'meta_update',        // Generic metadata updated with update_metadata()
		'meta_delete',        // Generic metadata deleted with delete_metadata()

		// Network/site options:
		'site_option',        // Site-wide options added with add_site_option()
		'site_update_option', // Site-wide options updated with update_site_option()
		'site_delete_option', // Site-wide options deleted with delete_site_option()

		// Meta registration:
		'register_post_meta', // Post meta registered with register_post_meta()
		'register_term_meta', // Term meta registered with register_term_meta()
		'register_meta',      // Generic meta registered with register_meta()

		// Roles / caps:
		'role_remove',        // Roles removed with remove_role()
		'cap_add',            // Capabilities added with add_cap()
		'cap_remove',         // Capabilities removed with remove_cap()

		// Widgets / sidebars:
		'register_sidebar',   // Sidebars registered with register_sidebar()
		'register_widget',    // Widgets registered with register_widget()

		// Cron:
		'cron_schedule',      // Recurring events with wp_schedule_event()
		'cron_single',        // Single events with wp_schedule_single_event()
		'cron_clear',         // Clear scheduled hooks with wp_clear_scheduled_hook()
		'cron_unschedule',    // Unschedule hooks with wp_unschedule_hook()

		// Action Scheduler (action hooks scheduled in custom AS tables):
		'as_async',           // as_enqueue_async_action()      → hook is 1st arg
		'as_single',          // as_schedule_single_action()    → hook is 2nd arg
		'as_recurring',       // as_schedule_recurring_action() → hook is 3rd arg
		'as_cron',            // as_schedule_cron_action()       → hook is 3rd arg
		'as_unschedule_all',  // as_unschedule_all_actions()    → hook is 1st arg
		'as_unschedule',      // as_unschedule_action()          → hook is 1st arg

		// Block types:
		'block_type_php',     // Blocks registered in PHP with register_block_type()
		'block_type_js',      // Blocks registered in JS with wp.blocks.registerBlockType()

		// Theme registration metadata (nav menu locations / rewrite / theme support):
		'nav_menu',           // Single menu location via register_nav_menu()
		'nav_menus',          // Multiple menu locations via register_nav_menus()
		'rewrite_endpoint',   // Rewrite endpoints via add_rewrite_endpoint()
		'rewrite_tag',        // Rewrite tags via add_rewrite_tag()
		'theme_support',      // Theme features via add_theme_support()
	);

	/** @var string[] Types considered FS impact (by code). */
	protected $fs_types = array(
		'file_write',
		'dir_create',
		'file_delete',
		'file_rename',
		'file_copy',
		'file_move_upload',
	);

	/** @var string[] Front-end asset types (CSS/JS handles) — KB collection only. */
	protected $asset_types = array(
		'asset_script',
		'asset_style',
	);

	/** @var array<string,bool> De-dup guard for asset rows within a scan run. */
	protected $seen_assets = array();

	/** @var string[] Top-level folders to ignore. */
	protected $ignore_dirs = array(
		'vendor',
		'node_modules',
		'tests',
		'test',
		'.git',
		'.svn',
		'languages',
		'assets',
		'dist',
		'build',
	);

	/** @var string[] File extensions to scan. */
	protected $scan_ext = array( 'php', 'phtml', 'inc', 'php5', 'js' );

	/** @var array<string,bool> De-dup set for DB rows. */
	protected $seen_db = array();

	/** @var array<string,bool> De-dup set for FS rows. */
	protected $seen_fs = array();

	/** @var array<string,bool> De-dup set for unified DB rows. */
	protected $seen_unified_db = array();

	/** @var array<string,bool> De-dup set for unified Files rows. */
	protected $seen_unified_files = array();

	/** @var string|null Cached site URL for unified rows. */
	protected $site_url = null;

	/** @var array<string,string> Project name cache. */
	protected $project_names = array();

	/** @var array<string,bool>|null Cache of active slugs (null = not yet built). */
	protected $active_slugs_cache = null;

	/** @var array<string,string> Project-wide constant map (NAME => literal value) for the dir being scanned. */
	protected static $scan_consts = array();

	/** @var array<int,array{pos:int,name:string,expr:string}> Ordered literal-ish var assignments for the current file. */
	protected static $scan_var_exprs = array();

	/**
	 * Constructor.
	 *
	 * @param string $base_path Base path to scan.
	 * @param string $origin    'plugin' | 'theme'.
	 */
	public function __construct( $base_path, $origin ) {
		$this->base_path = untrailingslashit( $base_path );
		$this->origin    = $origin;
		// The unified + asset CSVs are ONLY the KB contribution. Build them only when
		// the site is sharing; otherwise the scan still produces the local impact
		// CSVs for the UI but never writes our collection schema to disk.
		$this->contribute = ! function_exists( 'wpoptimizing_has_share_consent' ) || (bool) wpoptimizing_has_share_consent();
	}

	/** @var bool Whether to also write the KB-contribution (unified/asset) rows. */
	protected $contribute = true;

	/**
	 * Runs recursive scan and initializes CSV headers if missing.
	 *
	 * @return void
	 */
	public function run() {
		$this->maybe_init_csv( WPOPT_CSV_DB, array( 'type', 'origin', 'project_slug', 'status', 'extracted_value' ) );
		$this->maybe_init_csv( WPOPT_CSV_FS_CODE, array( 'type', 'origin', 'project_slug', 'status', 'relative_file' ) );

		$dirs = glob( $this->base_path . '/*', GLOB_ONLYDIR );
		foreach ( (array) $dirs as $dir ) {
			$this->scan_item( $dir );
		}

		// Most must-use plugins are a single file in mu-plugins/, which the folder walk above
		// never sees. Each one is its own owner, named like get_mu_plugins() names it.
		if ( $this->is_mu_base() ) {
			foreach ( (array) glob( $this->base_path . '/*.php' ) as $file ) {
				if ( 'index.php' !== basename( (string) $file ) ) {
					$this->scan_item( dirname( (string) $file ), array( (string) $file ), strtolower( basename( (string) $file, '.php' ) ) );
				}
			}
		}
	}

	/**
	 * Whether this scan walks the must-use plugins folder.
	 *
	 * @return bool
	 */
	protected function is_mu_base() {
		return defined( 'WPMU_PLUGIN_DIR' ) && wp_normalize_path( untrailingslashit( (string) WPMU_PLUGIN_DIR ) ) === wp_normalize_path( $this->base_path );
	}

	/**
	 * Ensure CSV file exists with a header row.
	 *
	 * @param string   $file   Absolute path to the CSV file.
	 * @param string[] $header Header columns.
	 * @return void
	 */
	protected function maybe_init_csv( $file, array $header ) {
		$file = wp_normalize_path( $file );

		$dir = dirname( $file );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// If a previous scan encrypted this file at rest, reset it: appending fresh
		// plaintext rows onto ciphertext would corrupt it. (The full scan's prepare
		// stage already deletes these; this also covers the generate-unified path.)
		if ( file_exists( $file ) && function_exists( 'wpoptimizing_scan_file_is_encrypted' ) && wpoptimizing_scan_file_is_encrypted( $file ) ) {
			wp_delete_file( $file );
		}

		if ( ! file_exists( $file ) || 0 === (int) filesize( $file ) ) {
			$fh = fopen( $file, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( false !== $fh ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
				fputcsv( $fh, array_values( $header ), ',', '"', '\\' );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
	}

	/**
	 * Scans a specific plugin/theme directory.
	 *
	 * @param string        $item_dir Absolute path to the item.
	 * @param string[]|null $files    Its files; null = walk the folder.
	 * @param string        $slug     Owner slug; '' = the folder's name.
	 * @return void
	 */
	protected function scan_item( $item_dir, $files = null, $slug = '' ) {
		$slug   = '' !== (string) $slug ? (string) $slug : basename( $item_dir );
		$status = $this->is_project_active( $slug ) ? 'active' : 'inactive';
		$files  = is_array( $files ) ? $files : $this->get_code_files( $item_dir );

		// Read each file ONCE and cache it (bounded), then build the project-wide
		// constant map from memory so a call in file B can resolve a constant
		// defined in file A — without re-reading the files for the constant pass.
		$contents = array();
		$used     = 0;
		$budget   = 64 * 1024 * 1024; // Cap in-memory cache so huge projects stay safe.
		foreach ( $files as $file ) {
			if ( $used >= $budget || ! is_readable( $file ) ) {
				continue;
			}
			$c = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			if ( false === $c ) {
				continue;
			}
			$contents[ $file ] = $c;
			$used             += strlen( $c );
		}
		self::$scan_consts = $this->build_constants_from_contents( $contents );

		$beat = function_exists( 'wpoptimizing_scan_heartbeat' );
		foreach ( $files as $file ) {
			if ( $beat ) {
				wpoptimizing_scan_heartbeat(); // A big tree runs for minutes: keep the stage's lock.
			}
			try {
				// Use the cached content; only re-read files skipped by the cache budget.
				if ( array_key_exists( $file, $contents ) ) {
					$content = $contents[ $file ];
				} elseif ( is_readable( $file ) ) {
					$content = file_get_contents( $file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
				} else {
					continue;
				}
				if ( false === $content ) {
					continue;
				}

				// Per-file local string variables, pre-resolved ONCE (avoids re-resolving
				// them on every matched call — which is O(matches × vars) otherwise).
				self::$scan_var_exprs = self::resolve_var_list( self::collect_var_exprs( $content ), self::$scan_consts );

				// Contact methods: fields added through the user_contactmethods filter are saved
				// by WordPress as user meta under their array key, with no add/update_user_meta
				// in the plugin's code (Avada's author_facebook, author_twitter… on
				// wpoptimizing.com, 14/10/2026, sat in Pending with no owner).
				if ( false !== stripos( $content, 'user_contactmethods' ) ) {
					foreach ( self::extract_contact_method_keys( $content ) as $meta_key ) {
						$this->append_db_row( array( 'user_meta', $this->origin, $slug, $status, $meta_key ), $file, 'user_meta_update' );
					}
				}

				// Run each detection pattern on the current file.
				foreach ( $this->patterns as $pattern => $type ) {
					if ( ! preg_match_all( $pattern, $content, $matches, PREG_OFFSET_CAPTURE ) ) {
						continue;
					}

					foreach ( $matches[0] as $match ) {
						$offset = (int) $match[1];

						// ---- FS impact: a file this code creates/writes at a literal path ----
						if ( in_array( $type, $this->fs_types, true ) ) {
							$this->detect_fs_path( $content, $offset, $type, $slug, $status, $file );
							continue;
						}

						// ---- Front-end asset (CSS/JS): record handle -> owner ----
						if ( in_array( $type, $this->asset_types, true ) ) {
							$handle = self::extract_first_arg_string_from_offset( $content, $offset );
							if ( '' !== $handle && preg_match( '/^[A-Za-z0-9._\-]{1,191}$/', $handle ) ) {
								$kind = ( 'asset_style' === $type ) ? 'style' : 'script';
								$this->append_asset_row( $kind, $handle, $this->origin, $slug, '', '', 'static' );
							}
							continue;
						}

						// ---- DB impact ----
						if ( in_array( $type, $this->db_types, true ) ) {

							/*
							=========================
							 * Tables via SQL (dbDelta / CREATE TABLE)
							 * ========================= */
							if ( in_array( $type, array( 'create_table', 'db_delta' ), true ) ) {
								$block = self::extract_sql_block( $content, $offset );
								$info  = $this->extract_table_info( $block );
								$table = $info['name'] ?? '';
								if ( '' !== $table ) {
									$this->append_db_row(
										array( $type, $this->origin, $slug, $status, $table ),
										$file,
										$type,
										array( 'table_prefixed' => (bool) ( $info['prefixed'] ?? false ) )
									);
								}
								continue;
							}

							/*
							=========================
							 * register_setting( ..., $option_name, ... )
							 * ========================= */
							if ( 'register_setting' === $type ) {
								$option = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $option && $this->is_valid_slug( $option ) ) {
									$this->append_db_row( array( 'option', $this->origin, $slug, $status, $option ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Transients (set/delete)
							 * ========================= */
							if ( 'transient_set' === $type || 'transient_delete' === $type ) {
								$tkey = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $tkey && preg_match( '/^[A-Za-z0-9._-]{2,191}$/', $tkey ) ) {
									$this->append_db_row( array( 'transient', $this->origin, $slug, $status, strtolower( $tkey ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Post meta (2nd arg is the key)
							 * ========================= */
							if ( in_array( $type, array( 'post_meta_add', 'post_meta_update', 'post_meta_delete' ), true ) ) {
								$meta_key = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $meta_key && $this->is_valid_slug( $meta_key ) ) {
									$this->append_db_row( array( 'post_meta', $this->origin, $slug, $status, sanitize_key( $meta_key ) ), $file, $type );
								}
								continue;
							}
							if ( 'post_meta_delete_by_key' === $type ) {
								$meta_key = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $meta_key && $this->is_valid_slug( $meta_key ) ) {
									$this->append_db_row( array( 'post_meta', $this->origin, $slug, $status, sanitize_key( $meta_key ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * User meta (2nd arg is the key)
							 * ========================= */
							if ( in_array( $type, array( 'user_meta_add', 'user_meta_update', 'user_meta_delete' ), true ) ) {
								$meta_key = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $meta_key && $this->is_valid_slug( $meta_key ) ) {
									$this->append_db_row( array( 'user_meta', $this->origin, $slug, $status, sanitize_key( $meta_key ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Generic metadata layer
							 * ========================= */
							if ( in_array( $type, array( 'meta_add', 'meta_update', 'meta_delete' ), true ) ) {
								$meta_key = self::extract_nth_arg_string_from_offset( $content, $offset, 3 );
								if ( '' !== $meta_key && $this->is_valid_slug( $meta_key ) ) {
									$this->append_db_row( array( 'meta_key', $this->origin, $slug, $status, sanitize_key( $meta_key ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Posts (insert/update) – try to extract 'post_type'
							 * ========================= */
							if ( in_array( $type, array( 'post_insert', 'post_update' ), true ) ) {
								$post_type = self::extract_assoc_value_from_first_array_arg( $content, $offset, 'post_type' );
								if ( '' !== $post_type && $this->is_valid_slug( $post_type ) ) {
									$this->append_db_row( array( 'post', $this->origin, $slug, $status, sanitize_key( $post_type ) ), $file, $type );
								} else {
									$this->append_db_row( array( 'post', $this->origin, $slug, $status, 'posts' ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Network/Site options
							 * ========================= */
							if ( in_array( $type, array( 'site_option', 'site_update_option', 'site_delete_option' ), true ) ) {
								$opt = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $opt && $this->is_valid_slug( $opt ) ) {
									$this->append_db_row( array( 'site_option', $this->origin, $slug, $status, sanitize_key( $opt ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Meta registration
							 * ========================= */
							if ( 'register_post_meta' === $type ) {
								$mk = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $mk && $this->is_valid_slug( $mk ) ) {
									$this->append_db_row( array( 'post_meta', $this->origin, $slug, $status, sanitize_key( $mk ) ), $file, $type );
								}
								continue;
							}
							if ( 'register_term_meta' === $type ) {
								$mk = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $mk && $this->is_valid_slug( $mk ) ) {
									$this->append_db_row( array( 'term_meta', $this->origin, $slug, $status, sanitize_key( $mk ) ), $file, $type );
								}
								continue;
							}
							if ( 'register_meta' === $type ) {
								$mk = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $mk && $this->is_valid_slug( $mk ) ) {
									$this->append_db_row( array( 'meta_key', $this->origin, $slug, $status, sanitize_key( $mk ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Roles & capabilities
							 * ========================= */
							if ( 'role_remove' === $type ) {
								$role = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $role && $this->is_valid_slug( $role ) ) {
									$this->append_db_row( array( 'role', $this->origin, $slug, $status, sanitize_key( $role ) ), $file, $type );
								}
								continue;
							}
							if ( in_array( $type, array( 'cap_add', 'cap_remove' ), true ) ) {
								$role = self::extract_first_arg_string_from_offset( $content, $offset );
								$cap  = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								if ( '' !== $role && $this->is_valid_slug( $role ) && '' !== $cap && $this->is_valid_slug( $cap ) ) {
									// Each half on its own: sanitize_key() drops the ':', and `administratormanage_x`
									// could never be split back into the capability it names.
									$this->append_db_row( array( 'cap', $this->origin, $slug, $status, sanitize_key( $role ) . ':' . sanitize_key( $cap ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Widgets / sidebars
							 * ========================= */
							if ( 'register_sidebar' === $type ) {
								$id = self::extract_assoc_value_from_first_array_arg( $content, $offset, 'id' );
								if ( '' !== $id && $this->is_valid_slug( $id ) ) {
									$this->append_db_row( array( 'sidebar', $this->origin, $slug, $status, sanitize_key( $id ) ), $file, $type );
								}
								continue;
							}
							if ( 'register_widget' === $type ) {
								$cls = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $cls ) {
									$this->append_db_row( array( 'widget', $this->origin, $slug, $status, $cls ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Cron events
							 * ========================= */
							if ( 'cron_schedule' === $type ) {
								$hook = self::extract_nth_arg_string_from_offset( $content, $offset, 3 );
								if ( '' === $hook ) {
									$hook = self::extract_nth_arg_string_from_offset( $content, $offset, 2 );
								}
								if ( '' !== $hook && $this->is_valid_slug( $hook ) ) {
									$this->append_db_row( array( 'cron', $this->origin, $slug, $status, sanitize_key( $hook ) ), $file, $type );
								}
								continue;
							}
							if ( in_array( $type, array( 'cron_clear', 'cron_unschedule' ), true ) ) {
								$hook = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $hook && $this->is_valid_slug( $hook ) ) {
									$this->append_db_row( array( 'cron', $this->origin, $slug, $status, sanitize_key( $hook ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * WP-Cron single events + Action Scheduler actions
							 * The hook/action name lives at a different argument position
							 * per function; resolve it and attribute it. WP-Cron single
							 * events feed the existing 'cron' type; Action Scheduler hooks
							 * (custom AS tables) get their own 'action_scheduler' type.
							 * ========================= */
							static $hook_arg_map = array(
								'cron_single'       => array( 'cron', 2 ),
								'as_async'          => array( 'action_scheduler', 1 ),
								'as_single'         => array( 'action_scheduler', 2 ),
								'as_recurring'      => array( 'action_scheduler', 3 ),
								'as_cron'           => array( 'action_scheduler', 3 ),
								'as_unschedule'     => array( 'action_scheduler', 1 ),
								'as_unschedule_all' => array( 'action_scheduler', 1 ),
							);
							if ( isset( $hook_arg_map[ $type ] ) ) {
								list( $emit_tipo, $arg_n ) = $hook_arg_map[ $type ];
								$hook                      = self::extract_nth_arg_string_from_offset( $content, $offset, $arg_n );
								if ( 'cron' === $emit_tipo ) {
									if ( '' !== $hook && $this->is_valid_slug( $hook ) ) {
										$this->append_db_row( array( 'cron', $this->origin, $slug, $status, sanitize_key( $hook ) ), $file, $type );
									}
								} else {
									// Action Scheduler hooks may contain "/" or ":" (e.g.
									// "action_scheduler/run"), so they are stored lowercased
									// and verbatim (matching the AS table read side) rather
									// than passed through sanitize_key().
									$hook = strtolower( trim( $hook ) );
									if ( '' !== $hook && preg_match( '#^[a-z0-9_\-\./:]{1,191}$#', $hook ) ) {
										$this->append_db_row( array( 'action_scheduler', $this->origin, $slug, $status, $hook ), $file, $type );
									}
								}
								continue;
							}

							/*
							=========================
							 * Block types (Gutenberg)
							 * ========================= */
							if ( 'block_type_php' === $type ) {
								$blk = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $blk ) {
									$this->append_db_row( array( 'block_type', $this->origin, $slug, $status, $blk ), $file, $type );
								}
								continue;
							}
							if ( 'block_type_js' === $type ) {
								$blk = self::extract_js_block_name_from_offset( $content, $offset );
								if ( '' !== $blk ) {
									$this->append_db_row( array( 'block_type', $this->origin, $slug, $status, $blk ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Theme nav menu locations
							 * register_nav_menu( $location, $description )  → 1st arg is the slug.
							 * register_nav_menus( array( 'slug' => 'Label', … ) ) → array keys.
							 * ========================= */
							if ( 'nav_menu' === $type ) {
								$loc = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $loc && $this->is_valid_slug( $loc ) ) {
									$this->append_db_row( array( 'nav_menu_location', $this->origin, $slug, $status, sanitize_key( $loc ) ), $file, $type );
								}
								continue;
							}
							if ( 'nav_menus' === $type ) {
								foreach ( self::extract_assoc_keys_from_first_array_arg( $content, $offset ) as $loc ) {
									if ( '' !== $loc && $this->is_valid_slug( $loc ) ) {
										$this->append_db_row( array( 'nav_menu_location', $this->origin, $slug, $status, sanitize_key( $loc ) ), $file, $type );
									}
								}
								continue;
							}

							/*
							=========================
							 * Rewrite endpoints / tags
							 * add_rewrite_endpoint( $name, … )  → 1st arg is the endpoint name.
							 * add_rewrite_tag( '%tag%', … )     → 1st arg is the tag (strip %).
							 * (add_rewrite_rule is intentionally skipped: its 1st arg is a regex,
							 *  not a clean key, so we never guess a value for it.)
							 * ========================= */
							if ( 'rewrite_endpoint' === $type ) {
								$name = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $name && $this->is_valid_slug( $name ) ) {
									$this->append_db_row( array( 'rewrite_endpoint', $this->origin, $slug, $status, sanitize_key( $name ) ), $file, $type );
								}
								continue;
							}
							if ( 'rewrite_tag' === $type ) {
								$tag = trim( self::extract_first_arg_string_from_offset( $content, $offset ), '%' );
								if ( '' !== $tag && $this->is_valid_slug( $tag ) ) {
									$this->append_db_row( array( 'rewrite_tag', $this->origin, $slug, $status, sanitize_key( $tag ) ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Theme support features
							 * add_theme_support( 'feature', … ) → 1st arg is the feature name
							 * (e.g. post-thumbnails, custom-logo, html5). Hyphens are valid.
							 * ========================= */
							if ( 'theme_support' === $type ) {
								$feature = self::extract_first_arg_string_from_offset( $content, $offset );
								if ( '' !== $feature && $this->is_valid_slug( $feature ) ) {
									$this->append_db_row( array( 'theme_support', $this->origin, $slug, $status, $feature ), $file, $type );
								}
								continue;
							}

							/*
							=========================
							 * Generic WP eco calls (fallback)
							 * add_option / add_role / register_post_type / register_taxonomy / i18n / shortcode / rest_namespace / image_size
							 * ========================= */
							$value = self::extract_first_arg_string_from_offset( $content, $offset );

							/*
							 * UN ESPACIO DE NOMBRES REST NO ES UN SLUG, y tratarlo como tal lo
							 * borraba del mapa. `is_valid_slug()` exige letras, dígitos, guiones
							 * y puntos — y TODO espacio de nombres lleva una barra: `wp/v2`,
							 * `woocommerce/v3`. Medido sobre los 157 plugins de un sitio real:
							 * de 173 llamadas a `register_rest_route()` con el argumento
							 * literal, 170 se descartaban. Sólo pasaban las tres mal formadas,
							 * sin barra. De ahí los 5 registros que había en toda la base de
							 * conocimiento.
							 *
							 * Y `sanitize_key()` remataba: convierte `woocommerce/v3` en
							 * `woocommercev3`, así que aunque hubiera pasado nunca habría
							 * coincidido con lo que publica el mu-plugin, que lo guarda entero.
							 *
							 * Es el mismo error que con las tablas: una regla escrita para las
							 * opciones aplicada a algo que no es una opción.
							 */
							if ( 'rest_namespace' === $type ) {
								$value = strtolower( trim( (string) $value, "/ \t\n\r" ) );
								if ( preg_match( '#^[a-z0-9_\-]+/[a-z0-9_\-\.]+$#', $value ) ) {
									$this->append_db_row( array( $type, $this->origin, $slug, $status, $value ), $file, $type );
								}
								continue;
							}

							if ( '' !== $value && $this->is_valid_slug( $value ) ) {
								$this->append_db_row( array( $type, $this->origin, $slug, $status, sanitize_key( $value ) ), $file, $type );
							}
							continue;
						}
					}
				}
			} catch ( \Throwable $e ) {
				// Do not abort the whole scan because of a single problematic file.
				continue;
			}
		}
	}

	/**
	 * Record a filesystem path that a piece of code creates/writes, when the path
	 * is a *literal* (resolved from quotes/constants/variables) that lands inside
	 * wp-content. Dynamic/unknown paths are skipped — we never guess a file path.
	 *
	 * @param string $content File contents.
	 * @param int    $offset  Match offset of the FS call.
	 * @param string $type    FS type (file_write|dir_create|file_delete|file_rename|file_copy|file_move_upload).
	 * @param string $slug    Owning project slug.
	 * @param string $status  active|inactive.
	 * @param string $file    Absolute path of the source file (for relative resolution).
	 * @return void
	 */
	protected function detect_fs_path( $content, $offset, $type, $slug, $status, $file ) {
		// The argument that carries the destination path differs per function.
		$arg  = in_array( $type, array( 'file_rename', 'file_copy', 'file_move_upload' ), true ) ? 2 : 1;
		$path = self::extract_nth_arg_string_from_offset( $content, $offset, $arg );
		if ( '' === $path ) {
			return; // Dynamic/unknown path — never guess.
		}
		$rel = $this->normalize_to_wpcontent( $path, $file );
		if ( '' === $rel ) {
			return; // Resolves outside wp-content (or not resolvable) — out of scope.
		}
		$this->append_fs_row( array( $type, $this->origin, $slug, $status, $rel ) );
	}

	/**
	 * Returns list of code files in a directory (filters vendor/tests/assets).
	 *
	 * @param string $dir Directory.
	 * @return string[] Absolute paths.
	 */
	protected function get_code_files( $dir ) {
		// An unreadable subdirectory (hosting-managed caches, another user's 0700 folder) makes RecursiveDirectoryIterator throw mid-walk, which aborts the whole scan. CATCH_GET_CHILD skips what it cannot open and keeps walking the rest.
		$rii   = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		$files = array();

		foreach ( $rii as $spl ) {
			/** @var SplFileInfo $spl */
			if ( $spl->isDir() ) {
				continue;
			}

			$path = wp_normalize_path( $spl->getPathname() );
			$rel  = ltrim( str_replace( wp_normalize_path( $dir ) . '/', '', $path ), '/' );

			// Ignore a set of noisy folders (any segment of the path, not just the first).
			$parts       = explode( '/', $rel );
			$should_skip = false;
			foreach ( $parts as $segment ) {
				if ( in_array( $segment, $this->ignore_dirs, true ) ) {
					$should_skip = true;
					break;
				}
			}
			if ( $should_skip ) {
				continue;
			}

			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			// Guard: skip very large files (> 1.5 MB) to keep scan cost sane.
			if ( $spl->getSize() > 1572864 ) {
				continue;
			}
			if ( in_array( $ext, $this->scan_ext, true ) ) {
				$files[] = $path;
			}
		}
		return $files;
	}

	/**
	 * Build the project-wide constant map from already-read file contents (no I/O).
	 * Two in-memory passes let a constant defined later (or in another file) still
	 * feed an earlier reference. Only strings that mention const/define are parsed.
	 *
	 * @param array<string,string> $contents Map of file path => contents.
	 * @return array<string,string> NAME => literal value.
	 */
	protected function build_constants_from_contents( array $contents ) {
		// Pre-filter to the files that actually declare constants (cheap strpos).
		$candidates = array();
		foreach ( $contents as $content ) {
			if ( false !== strpos( $content, 'const' ) || false !== stripos( $content, 'define' ) ) {
				$candidates[] = $content;
			}
		}
		if ( empty( $candidates ) ) {
			return array();
		}
		$consts = array();
		for ( $pass = 0; $pass < 2; $pass++ ) {
			$before = count( $consts );
			foreach ( $candidates as $content ) {
				$consts = self::collect_constants( $content, $consts );
			}
			// A second pass only helps if the first discovered new names.
			if ( count( $consts ) === $before ) {
				break;
			}
		}
		return $consts;
	}

	/**
	 * Checks if the project (plugin or theme) is active.
	 *
	 * @param string $slug Project directory slug.
	 * @return bool
	 */
	protected function is_project_active( $slug ) {
		// Must-use plugins are always loaded and cannot be deactivated. They are not in
		// active_plugins, so this answered `inactive` for every one of them.
		if ( $this->is_mu_base() ) {
			return true;
		}
		if ( null === $this->active_slugs_cache ) {
			$this->active_slugs_cache = array();
			if ( 'plugin' === $this->origin ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				$active         = (array) get_option( 'active_plugins', array() );
				$active_network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
				$all_active     = array_merge( $active, $active_network );
				$all_plugins    = function_exists( 'get_plugins' ) ? get_plugins() : array();
				foreach ( $all_plugins as $plugin_file => $_data ) {
					$dir = dirname( $plugin_file );
					if ( $dir && '.' !== $dir && in_array( $plugin_file, $all_active, true ) ) {
						$this->active_slugs_cache[ $dir ] = true;
					}
				}
			} elseif ( 'theme' === $this->origin ) {
				$this->active_slugs_cache[ get_stylesheet() ] = true;
				$this->active_slugs_cache[ get_template() ]   = true;
			}
		}

		return isset( $this->active_slugs_cache[ $slug ] );
	}

	/*
	============================================================
	 * Helpers: extraction, validation, normalization, CSV appends
	 * ============================================================ */

	/**
	 * Extracts a “line” around an offset to safely parse args.
	 *
	 * @param string $content Full file content.
	 * @param int    $offset  Byte offset.
	 * @return string
	 */
	protected function extract_line_from_offset( $content, $offset ) {
		$start = max( 0, $offset - 300 );
		$end   = min( strlen( $content ), $offset + 300 );
		$chunk = substr( $content, $start, $end - $start );

		// Trim to first “);” or newline after offset
		if ( preg_match( '/^[\s\S]{0,400}?\);/m', $chunk, $m ) ) {
			return $m[0];
		}
		if ( preg_match( '/^[^\n\r]{0,400}/', $chunk, $m ) ) {
			return $m[0];
		}
		return $chunk;
	}

	/**
	 * Extracts an SQL block around offset (for dbDelta/CREATE TABLE).
	 *
	 * @param string $content Full content.
	 * @param int    $offset  Offset of match.
	 * @return string
	 */
	protected function extract_sql_block( $content, $offset ) {
		$start = max( 0, $offset - 800 );
		$end   = min( strlen( $content ), $offset + 2000 );
		$chunk = substr( $content, $start, $end - $start );
		return $chunk;
	}

	/**
	 * El nombre de tabla cuando la sentencia lo recibe en una VARIABLE.
	 *
	 * ES LA FORMA DOMINANTE Y SE PERDÍA ENTERA. Medido sobre los 157 plugins de un sitio real,
	 * contando cada `CREATE TABLE`:
	 *
	 *     variable suelta   `$tabla` / `{$tabla}`     153
	 *     propiedad         `$this->tabla`             12
	 *     prefijo en el SQL `{$wpdb->prefix}foo`       44   <- lo único que se reconocía
	 *
	 * Cuatro de cada cinco fuera. Y el efecto se veía en la base de conocimiento: 27 de esos
	 * 157 plugins llaman a `dbDelta()` y sólo 3 tenían la forma literal, un 11 % de recuerdo.
	 * Por eso WooCommerce aparecía con sus 31 tablas y Wordfence o MailPoet con ninguna: no es
	 * que no las creen, es que las escriben así:
	 *
	 *     $tabla = $wpdb->prefix . 'mi_tabla';
	 *     dbDelta( "CREATE TABLE $tabla ( … )" );
	 *
	 * La asignación cae dentro del bloque que extract_sql_block() ya recorta (800 caracteres
	 * por delante del `CREATE TABLE`), así que no hace falta maquinaria nueva: se busca ahí.
	 *
	 * EL PREFIJO SE TRATA COMO CADENA VACÍA, no como incógnita. La base de conocimiento guarda
	 * el nombre SIN prefijo —es lo único común entre sitios—, así que `$wpdb->prefix` no
	 * estorba para resolver: aporta exactamente nada al nombre que se quiere. Lo que sí se
	 * descarta, como en todo el escáner, es cualquier otro fragmento dinámico: un
	 * `self::TABLA` o un `$otra` que no se sepa resolver devuelven vacío. Una tabla de menos no
	 * cuesta nada; una inventada sí.
	 *
	 * @param string $sql Bloque SQL con su contexto previo.
	 * @param string $ref Referencia tal como aparece: `$tabla`, `{$tabla}`, `$this->tabla`…
	 * @return array{name:string,prefixed:bool}
	 */
	protected function resolve_table_reference( $sql, $ref ) {
		$vacio = array(
			'name'     => '',
			'prefixed' => false,
		);

		$ref = trim( (string) $ref, "{}` \t\n\r" );
		if ( ! preg_match( '/^\$(this->)?([A-Za-z_][A-Za-z0-9_]*)$/', $ref, $m ) ) {
			return $vacio;
		}
		// `$this->tabla` se asigna como `$this->tabla = …`; una variable suelta, como `$tabla = …`.
		$patron = $m[1]
			? '/\$this->' . preg_quote( $m[2], '/' ) . '\s*=\s*([^;]+);/'
			: '/\$' . preg_quote( $m[2], '/' ) . '\s*=\s*([^;]+);/';

		// La ÚLTIMA asignación anterior manda, que es la que estaría en vigor al llegar al SQL.
		if ( ! preg_match_all( $patron, $sql, $todas ) ) {
			return $vacio;
		}
		$expr = (string) end( $todas[1] );

		// El prefijo no es una incógnita: es lo que hay que quitar.
		$prefijada = (bool) preg_match( '/\$wpdb->(base_)?prefix|\$table_prefix/i', $expr );
		$expr      = preg_replace( '/\{\s*\$wpdb->(base_)?prefix\s*\}/i', '', $expr );
		$expr      = preg_replace( '/\$wpdb->(base_)?prefix/i', '', $expr );
		$expr      = preg_replace( '/\{\s*\$table_prefix\s*\}/i', '', $expr );
		$expr      = preg_replace( '/\$table_prefix/i', '', $expr );
		$expr      = trim( $expr );
		$expr      = preg_replace( '/^\.\s*|\s*\.$/', '', $expr );
		$expr      = trim( $expr );

		// Lo que quede tiene que ser UNA cadena literal. Cualquier otra cosa —una constante de
		// clase, otra variable, una llamada— es dinámica y se descarta sin adivinar.
		if ( ! preg_match( '/^([\'"])([A-Za-z0-9_]+)\1$/', $expr, $lit ) ) {
			return $vacio;
		}

		return array(
			'name'     => strtolower( $lit[2] ),
			'prefixed' => $prefijada,
		);
	}

	/**
	 * Extracts the table name and prefix usage from SQL (strips $wpdb->prefix and quotes).
	 *
	 * Supports: CREATE TABLE [IF NOT EXISTS] `{$wpdb->prefix}foo` (...)
	 * Y también la forma dominante, en la que el nombre llega en una variable asignada unas
	 * líneas antes: ver resolve_table_reference(), que es donde está medido el porqué.
	 *
	 * @param string $sql SQL snippet.
	 * @return array{name:string,prefixed:bool} Info.
	 */
	protected function extract_table_info( $sql ) {
		$info = $this->extract_table_info_raw( $sql );

		/*
		 * NINGUNA TABLA DE VERDAD SE LLAMA `s`. La capa db del gateway acumuló 73 filas `table`
		 * de una letra (`s`, `a`, `c`, `i`, `_`…), repartidas entre updraftplus, WooCommerce,
		 * redsys, el propio WPOptimizing y otros: trozos de SQL de ejemplo, de plantillas y de
		 * nombres compuestos que el escáner tomó por el nombre entero. Se purgaron a mano el
		 * 15/10/2026 y esto evita que vuelvan. Tres caracteres es el mínimo para un nombre sin
		 * prefijo con algún sentido; una tabla de menos no cuesta nada, una inventada sí.
		 */
		if ( strlen( (string) $info['name'] ) < 3 ) {
			$info['name'] = '';
		}

		return $info;
	}

	/**
	 * extract_table_info() sin el mínimo de longitud.
	 *
	 * @param string $sql SQL snippet.
	 * @return array{name:string,prefixed:bool} Info.
	 */
	protected function extract_table_info_raw( $sql ) {
		global $wpdb;
		$prefixed = false;

		/*
		 * EL NOMBRE SUELE IR SIN COMILLAS, y así no se leía. El patrón de antes era
		 * ([`"\']?)(.+?)\1 : con el delimitador opcional vacío, la retro-referencia también
		 * empareja vacío y el `.+?` perezoso se queda en UN carácter. Es decir, sólo funcionaba
		 * cuando el nombre venía entre comillas o tildes invertidas.
		 *
		 * Medido sobre los 157 plugins de un sitio real: 264 sentencias con el nombre desnudo
		 * frente a 66 entrecomilladas. Se perdía el 80 % antes siquiera de llegar al problema
		 * de la variable. Las propias tablas de WooCommerce son `CREATE TABLE $table_name`:
		 * desnudas Y en variable, las dos cosas a la vez.
		 *
		 * Ahora son dos alternativas explícitas, sin retro-referencia opcional: entrecomillada
		 * hasta su cierre, o desnuda hasta el primer espacio o paréntesis.
		 */
		/*
		 * Y LA SENTENCIA TIENE QUE ABRIR SU LISTA DE COLUMNAS. Al aceptar el nombre desnudo se
		 * coló basura de inmediato: `if`, `statement`, `syntax`, `with`, `temporary`… Son las
		 * palabras que siguen a «CREATE TABLE» en la DOCUMENTACIÓN y en el código de los
		 * plugins que hablan de SQL —un gestor de base de datos, un limpiador, un analizador—,
		 * no nombres de tabla. Medido: aparecían entre los primeros resultados.
		 *
		 * Un `CREATE TABLE` de verdad abre paréntesis con las columnas; una frase que lo
		 * menciona, no. Exigirlo quita la prosa sin lista a mano de palabras prohibidas, y de
		 * paso descarta el `CREATE TEMPORARY TABLE` y los `%s` de los `prepare()`.
		 *
		 * El nombre, además, tiene que parecer un identificador: letras, dígitos y guiones
		 * bajos, o una referencia que empiece por `$`.
		 */
		/*
		 * DOS EXCLUSIONES MÁS, las dos medidas en el corpus:
		 *
		 * `SHOW CREATE TABLE` NO CREA NADA: lee la definición de una tabla existente. Quien la
		 * ejecuta la está inspeccionando, no declarándola suya — es la misma distinción entre
		 * leer y poseer sobre la que se sostiene todo el sistema de atribución. Lo hace
		 * `advanced-database-cleaner`, que es justamente un plugin que mira tablas ajenas: la
		 * peor atribución posible.
		 *
		 * Y LA LISTA DE COLUMNAS TIENE QUE PARECERLO. Exigir el paréntesis quitó la prosa suelta
		 * pero no «CREATE TABLE statement (full DDL)», que es un COMENTARIO y cumple la forma.
		 * Una tabla de verdad declara al menos una columna con su tipo, así que se exige ver un
		 * tipo SQL dentro. Es una lista cerrada por la gramática de SQL, no una lista de casos
		 * particulares que haya que ir ampliando.
		 */
		if ( preg_match( '/(?<!SHOW[ \t])CREATE[ \t]+TABLE[ \t]+(?:IF[ \t]+NOT[ \t]+EXISTS[ \t]+)?(?:([`"\'])(.+?)\1|((?:\{?\$|[A-Za-z_])[^\s(;`"\']*))[ \t]*\((?=[^;]{0,400}?\b(?:tinyint|smallint|mediumint|bigint|int|integer|decimal|numeric|float|double|char|varchar|tinytext|mediumtext|longtext|text|blob|date|datetime|timestamp|time|year|enum|json)\b)/i', $sql, $m, PREG_OFFSET_CAPTURE ) ) {
			/*
			 * Y NO VALE SI ESTÁ EN UN COMENTARIO. `updraftplus` documenta el esquema de una
			 * tabla ajena dentro de un docblock, con un CREATE TABLE perfectamente válido:
			 *
			 *     /*
			 *      * CREATE TABLE _sequences (
			 *      *   name VARCHAR(70) NOT NULL UNIQUE, …
			 *      *\/
			 *
			 * Cumple todas las guardas anteriores porque ES una sentencia correcta; lo que no
			 * es, es código que se ejecute. Basta con mirar cómo empieza su línea: un `*`, un
			 * `//` o un `#` delatan la documentación, que es donde han aparecido los dos falsos
			 * positivos del corpus.
			 */
			$inicio_linea = strrpos( substr( $sql, 0, (int) $m[0][1] ), "\n" );
			$linea        = ltrim( substr( $sql, false === $inicio_linea ? 0 : $inicio_linea + 1, 8 ) );
			if ( '' !== $linea && ( '*' === $linea[0] || 0 === strpos( $linea, '//' ) || '#' === $linea[0] ) ) {
				return array(
					'name'     => '',
					'prefixed' => false,
				);
			}
			$m   = array_map(
				static function ( $g ) {
					return is_array( $g ) ? $g[0] : $g;
				},
				$m
			);
			$raw = ( isset( $m[2] ) && '' !== $m[2] ) ? $m[2] : ( $m[3] ?? '' );

			/*
			 * «CREATE TABLE» TAMBIÉN APARECE EN PROSA, y entonces lo que viene detrás no es una
			 * tabla sino el código siguiente. Medido en el corpus: `wp-phpmyadmin-extension` y
			 * `updraftplus` hablan de «create table» en un mensaje y a continuación abren un
			 * `if (…)`, que cumplía todo lo demás —identificador válido y paréntesis detrás— y
			 * entraba en la base de conocimiento como una tabla llamada «if».
			 *
			 * Los separadores son ya sólo espacios y tabuladores, no `\s`, para que el patrón
			 * no salte de una frase a la línea de código de abajo. Y se descartan además las
			 * palabras reservadas, que es lo que queda cuando sí están en la misma línea. Son
			 * palabras que NINGUNA tabla puede llamarse, no una lista de casos particulares.
			 */
			if ( '' === $raw || in_array( strtolower( $raw ), array( 'if', 'else', 'elseif', 'foreach', 'for', 'while', 'switch', 'return', 'function', 'echo', 'print' ), true ) ) {
				return array(
					'name'     => '',
					'prefixed' => false,
				);
			}

			/*
			 * El nombre llega en una variable o en una propiedad —la forma dominante, 165 de
			 * 209 apariciones medidas— y entonces hay que ir a buscar su asignación, que está
			 * unas líneas más arriba dentro de este mismo bloque. Si no se resuelve a un
			 * literal, se devuelve vacío y la entrada se descarta: nunca se adivina.
			 */
			if ( preg_match( '/^\{?\s*\$(?!wpdb->|table_prefix)/', $raw ) ) {
				return $this->resolve_table_reference( $sql, $raw );
			}

			if ( preg_match( '/\{\s*\$wpdb->prefix\s*\}|\$wpdb->prefix|\$table_prefix/i', $raw ) ) {
				$prefixed = true;
			}

			$raw = preg_replace( '/\{\s*\$wpdb->prefix\s*\}/i', '', $raw );
			$raw = preg_replace( '/\$wpdb->prefix/i', '', $raw );

			$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : '';
			if ( $prefix && 0 === strpos( $raw, $prefix ) ) {
				$raw      = substr( $raw, strlen( $prefix ) );
				$prefixed = true;
			}

			$raw = preg_replace( '/^[`"\']|[`"\']$/', '', $raw );
			$raw = preg_replace( '/^[a-z0-9_]+\./i', '', $raw );
			$raw = trim( $raw );

			if ( preg_match( '/^([a-z0-9_]+)/i', $raw, $n ) ) {
				return array(
					'name'     => strtolower( $n[1] ),
					'prefixed' => $prefixed,
				);
			}
		}
		return array(
			'name'     => '',
			'prefixed' => $prefixed,
		);
	}
	/**
	 * Extracts first literal string argument: 'foo' or "foo".
	 *
	 * @param string $line Line-like chunk.
	 * @return string
	 */
	protected function extract_first_arg_string( $line ) {
		// First quoted string inside parentheses
		if ( preg_match( '/\(\s*([\'"])(.*?)\1/s', $line, $m ) ) {
			$val = html_entity_decode( stripslashes( (string) $m[2] ) );
			return trim( $val );
		}
		return '';
	}

	/**
	 * Extracts a first path-looking argument if it is a literal.
	 *
	 * @param string $line Chunk around call.
	 * @return string Empty if not a literal path.
	 */
	protected function extract_first_path_arg( $line ) {
		if ( preg_match( '/\(\s*([\'"])(.*?)\1/s', $line, $m ) ) {
			$val = html_entity_decode( stripslashes( (string) $m[2] ) );
			// Quick path heuristic (has slash or dot)
			if ( preg_match( '#[\\/\.]#', $val ) ) {
				return $val;
			}
		}
		return '';
	}

	/**
	 * Validates slugs/keys to avoid invalid or unsafe values (e.g., error messages or arbitrary text).
	 *
	 * This method is designed to ensure that the provided string can be safely used
	 * as an option name, meta key, or similar identifier in WordPress. It enforces:
	 * - Non-empty value.
	 * - Maximum length of 191 characters (safe for utf8mb4 index in MySQL).
	 * - Only allows letters, numbers, underscores, and hyphens.
	 *
	 * @param string $s The candidate string to validate.
	 * @return bool True if the string is valid, false otherwise.
	 */
	protected function is_valid_slug( $s ) {
		$s = (string) $s;

		if ( '' === $s ) {
			return false;
		}

		// Enforce a reasonable maximum length for option_name or meta keys.
		$len = function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
		if ( $len > 191 ) {
			return false;
		}

		// Allow only letters, numbers, underscores, hyphens, and dots (valid in WP option/meta keys).
		return (bool) preg_match( '/^[A-Za-z0-9_\-\.]+$/u', $s );
	}

	/**
	 * Normalizes an absolute or relative path to a wp-content relative one.
	 *
	 * @param string $path  Original path string.
	 * @param string $file  Current file absolute path (for resolving relative).
	 * @return string Empty string if cannot be normalized.
	 */
	protected function normalize_to_wpcontent( $path, $file ) {
		// An absolute path stands on its own; a relative literal is resolved against the source
		// file (WPOPT_FS_Scanner::normalize_to_wpcontent() does both). This used to try the
		// literal as wp-content-relative first, which always "succeeded", so the file-relative
		// resolution below it never ran — and an absolute path outside wp-content was then
		// glued onto the source folder.
		return WPOPT_FS_Scanner::normalize_to_wpcontent( $path, $file );
	}

	/**
	 * Convert absolute path to "wp-content/..." path.
	 *
	 * @param string $abs Absolute path.
	 * @return string
	 */
	protected function to_wpcontent_path( $abs ) {
		$abs  = wp_normalize_path( $abs );
		$base = wp_normalize_path( WP_CONTENT_DIR );
		$rel  = ltrim( str_replace( $base, '', $abs ), '/' );
		return 'wp-content/' . $rel;
	}

	/**
	 * Resolve project name by slug.
	 *
	 * @param string $slug Project slug.
	 * @param string $origin plugin|theme
	 * @return string
	 */
	protected function get_project_name( $slug, $origin ) {
		$key = $origin . ':' . $slug;
		if ( isset( $this->project_names[ $key ] ) ) {
			return $this->project_names[ $key ];
		}
		$name = $slug;
		if ( 'plugin' === $origin ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			$all = function_exists( 'get_plugins' ) ? get_plugins() : array();
			foreach ( $all as $plugin_file => $data ) {
				if ( 0 === strpos( $plugin_file, $slug . '/' ) ) {
					$name = isset( $data['Name'] ) ? (string) $data['Name'] : $slug;
					break;
				}
			}
		} elseif ( 'theme' === $origin ) {
			$themes = wp_get_themes();
			if ( isset( $themes[ $slug ] ) ) {
				$name = (string) $themes[ $slug ]->get( 'Name' );
			}
		}
		$this->project_names[ $key ] = $name;
		return $name;
	}

	/**
	 * Get a normalized site URL for unified rows.
	 *
	 * @return string
	 */
	protected function get_site_url() {
		if ( null !== $this->site_url ) {
			return $this->site_url;
		}

		$site           = function_exists( 'home_url' ) ? home_url() : '';
		$site           = is_string( $site ) ? trim( $site ) : '';
		$site           = preg_replace( '#/$#', '', $site );
		$this->site_url = $site;

		return $this->site_url;
	}

	/**
	 * Map internal type to unified type.
	 *
	 * @param string $type Internal type.
	 * @return string
	 */
	protected function map_unified_type( $type ) {
		$map = array(
			'post_type'     => 'custom_type',
			'taxonomy'      => 'taxonomy',
			'role'          => 'role',
			'option'        => 'option',
			'update_option' => 'option',
			'delete_option' => 'option',
			'site_option'   => 'option',
			'table'         => 'table',
			'db_delta'      => 'table',
			'create_table'  => 'table',
			'cron'          => 'cron',
			'shortcode'     => 'shortcode',
			'block_type'    => 'block',
		);
		return $map[ $type ] ?? $type;
	}

	/**
	 * Map internal type to unified function name.
	 *
	 * @param string $type Internal type.
	 * @return string
	 */
	protected function map_unified_func( $type ) {
		$map = array(
			'post_type'     => 'register_post_type',
			'taxonomy'      => 'register_taxonomy',
			'role'          => 'add_role',
			'option'        => 'add_option',
			'update_option' => 'update_option',
			'delete_option' => 'delete_option',
			'site_option'   => 'add_site_option',
			'table'         => 'dbDelta',
			'db_delta'      => 'dbDelta',
			'create_table'  => 'CREATE TABLE',
			'cron'          => 'wp_schedule_event',
			'shortcode'     => 'add_shortcode',
			'block_type'    => 'register_block_type',
		);
		return $map[ $type ] ?? $type;
	}

	/**
	 * Map unified type to source CSV file name.
	 *
	 * @param string $type Unified type.
	 * @return string
	 */
	protected function map_unified_source_file( $type ) {
		$map = array(
			'custom_type' => 'wp-custom-types.csv',
			'taxonomy'    => 'wp-taxonomies.csv',
			'role'        => 'wp-roles.csv',
			'option'      => 'wp-options.csv',
			'table'       => 'wp-tables.csv',
			'cron'        => 'wp-cron-hooks.csv',
			'shortcode'   => 'wp-shortcodes.csv',
			'block'       => 'wp-blocks.csv',
		);
		return $map[ $type ] ?? 'wp-unificado.csv';
	}

	/**
	 * Append a unified row to the local "unificado" CSVs.
	 *
	 * @param string              $bucket  'bd' or 'archivos'.
	 * @param string              $slug    Project slug.
	 * @param string              $origin  'plugin' or 'theme'.
	 * @param string              $file    Absolute file path.
	 * @param string              $type    Internal type.
	 * @param string              $value   Extracted value.
	 * @param array<string,mixed> $meta    Optional metadata.
	 * @return void
	 */
	/**
	 * Record a front-end asset (script/style) handle and its owner into the asset
	 * registry CSV — KB collection for the future Smart Assets feature. Structural
	 * data only (handle, owner, normalized src, dependency handles), never the
	 * asset's contents. De-duped per scan run.
	 *
	 * @param string $kind       'script'|'style'.
	 * @param string $handle     Asset handle.
	 * @param string $owner_type plugin|theme|mu-plugin|core|unknown.
	 * @param string $owner_slug Owner slug.
	 * @param string $src_rel    Normalized wp-content-relative src (or '').
	 * @param string $deps       Pipe-joined dependency handles (or '').
	 * @param string $source     'static'|'runtime'.
	 * @return void
	 */
	protected function append_asset_row( $kind, $handle, $owner_type, $owner_slug, $src_rel = '', $deps = '', $source = 'static' ) {
		if ( ! $this->contribute ) {
			return; // Not sharing: never write the asset registry to disk.
		}
		$key = $kind . '|' . $handle . '|' . $owner_slug . '|' . $source;
		if ( isset( $this->seen_assets[ $key ] ) ) {
			return;
		}
		$this->seen_assets[ $key ] = true;

		$new = ! file_exists( WPOPT_CSV_ASSETS ) || 0 === (int) filesize( WPOPT_CSV_ASSETS );
		$fh  = fopen( WPOPT_CSV_ASSETS, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( $fh && flock( $fh, LOCK_EX ) ) {
			if ( $new ) {
				fputcsv( $fh, array( 'kind', 'handle', 'owner_type', 'owner_slug', 'src_rel', 'deps', 'source' ), ',', '"', '\\' );
			}
			fputcsv( $fh, array( $kind, $handle, (string) $owner_type, (string) $owner_slug, (string) $src_rel, (string) $deps, $source ), ',', '"', '\\' );
			fflush( $fh );
			flock( $fh, LOCK_UN );
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	protected function append_unified_row( $bucket, $slug, $origin, $file, $type, $value, array $meta = array() ) {
		if ( ! $this->contribute ) {
			return; // Not sharing: never write the KB-contribution CSVs to disk.
		}
		if ( '' === $file || '' === $type || '' === $value ) {
			return;
		}
		$unified_type = $this->map_unified_type( $type );
		$func         = $this->map_unified_func( $type );
		$source_file  = $this->map_unified_source_file( $unified_type );
		$name         = $this->get_project_name( $slug, $origin );
		if ( 'table' === $unified_type && array_key_exists( 'table_prefixed', $meta ) && false === (bool) $meta['table_prefixed'] ) {
			$func .= ' (no_prefix)';
		}

		$file_rel = $this->to_wpcontent_path( $file );
		$dir_rel  = $this->to_wpcontent_path( dirname( $file ) );

		$row = array(
			$source_file,
			$bucket,
			$slug,
			$name,
			$origin,
			$file_rel,
			$dir_rel,
			$unified_type,
			$func,
			$value,
			$this->get_site_url(),
		);

		$key = implode( '|', $row );
		if ( 'bd' === $bucket ) {
			if ( isset( $this->seen_unified_db[ $key ] ) ) {
				return;
			}
			$this->seen_unified_db[ $key ] = true;
			$fh = fopen( WPOPT_CSV_UNIFIED_DB, 'a' ); // phpcs:ignore
		} else {
			if ( isset( $this->seen_unified_files[ $key ] ) ) {
				return;
			}
			$this->seen_unified_files[ $key ] = true;
			$fh = fopen( WPOPT_CSV_UNIFIED_FILES, 'a' ); // phpcs:ignore
		}

		if ( $fh && flock( $fh, LOCK_EX ) ) {
			fputcsv( $fh, $row, ',', '"', '\\' );
			fflush( $fh );
			flock( $fh, LOCK_UN );
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore
		}
	}

	/**
	 * Append a DB row with de-duplication + flock.
	 *
	 * @param array{0:string,1:string,2:string,3:string,4:string} $row Row.
	 * @param array<string,mixed>                                 $meta Optional metadata.
	 * @return void
	 */
	protected function append_db_row( array $row, $file = '', $pattern_type = '', array $meta = array() ) {
		list( $type, $origin, $slug, $status, $value ) = $row;
		/*
		 * A DELETION KEEPS ITS VERB. Deleting someone else's transient, meta key or cron hook is not
		 * owning it (a plugin that clears Avada's cache, an uninstaller tidying up a neighbour), and
		 * the row used to carry only the bucket, so it read as one more owner. The impact cleaner
		 * reads `<type>_delete` into the same bucket as an owner that only deletes
		 * (WPOPT_Impact_Cleaner::drop_delete_only_owners()). Options already carry delete_option.
		 */
		if ( in_array( (string) $pattern_type, array( 'transient_delete', 'post_meta_delete', 'post_meta_delete_by_key', 'user_meta_delete', 'meta_delete', 'cron_clear', 'cron_unschedule' ), true ) && '_delete' !== substr( (string) $type, -7 ) ) {
			$type .= '_delete';
		}
		$key = implode( '|', array( $type, $origin, $slug, $status, $value ) );
		if ( isset( $this->seen_db[ $key ] ) ) {
			return;
		}
		$this->seen_db[ $key ] = true;

		$fh = fopen( WPOPT_CSV_DB, 'a' ); // phpcs:ignore
		if ( $fh && flock( $fh, LOCK_EX ) ) {
			fputcsv( $fh, array( $type, $origin, $slug, $status, $value ), ',', '"', '\\' );
			fflush( $fh );
			flock( $fh, LOCK_UN );
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore
		}

		if ( '' !== $file && '' !== $value ) {
			$use_type = ( '' !== $pattern_type ) ? $pattern_type : $type;
			$this->append_unified_row( 'bd', $slug, $origin, $file, $use_type, $value, $meta );
			$this->append_unified_row( 'archivos', $slug, $origin, $file, $use_type, $value, $meta );

			$rel = WPOPT_FS_Scanner::normalize_to_wpcontent( $file );
			if ( '' !== $rel ) {
				$this->append_fs_row( array( $use_type, $origin, $slug, $status, $rel ) );
			}
		}
	}

	/**
	 * Append an FS row with de-duplication + flock.
	 *
	 * @param array{0:string,1:string,2:string,3:string,4:string} $row Row.
	 * @return void
	 */
	protected function append_fs_row( array $row ) {
		list( $type, $origin, $slug, $status, $rel ) = $row;
		$rel = ltrim( $rel, '/' );
		$key = implode( '|', array( $type, $origin, $slug, $status, $rel ) );
		if ( isset( $this->seen_fs[ $key ] ) ) {
			return;
		}
		$this->seen_fs[ $key ] = true;

		$fh = fopen( WPOPT_CSV_FS_CODE, 'a' ); // phpcs:ignore
		if ( $fh && flock( $fh, LOCK_EX ) ) {
			fputcsv( $fh, array( $type, $origin, $slug, $status, $rel ), ',', '"', '\\' );
			fflush( $fh );
			flock( $fh, LOCK_UN );
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore
		}
	}

	/**
	 * Keys a file adds through the user_contactmethods filter: the hooked callback (a closure,
	 * a function or a method named in the add_filter call) is located and every
	 * `$fields['key'] = …` or `'key' => …` in its body is a contact field, which WordPress stores
	 * as user meta under that key.
	 *
	 * @param string $content File contents.
	 * @return string[] Sanitized meta keys.
	 */
	public static function extract_contact_method_keys( $content ) {
		$content = (string) $content;
		if ( ! preg_match_all( '/add_filter\s*\(\s*([\'"])user_contactmethods\1\s*,/i', $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$bodies = array();
		foreach ( $m[0] as $hit ) {
			$after = substr( $content, (int) $hit[1] + strlen( $hit[0] ), 400 );
			if ( preg_match( '/^\s*(?:static\s+)?function\s*\(/', $after ) ) {
				// Closure: its body follows.
				$bodies[] = self::brace_body( $content, (int) $hit[1] + strlen( $hit[0] ) );
				continue;
			}
			if ( preg_match( '/^\s*(?:array\s*\(|\[)[^,\])]*,\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1/', $after, $cb )
				|| preg_match( '/^\s*([\'"])([A-Za-z_][A-Za-z0-9_]*)\1/', $after, $cb ) ) {
				if ( preg_match( '/function\s+' . preg_quote( $cb[2], '/' ) . '\s*\(/i', $content, $fm, PREG_OFFSET_CAPTURE ) ) {
					$bodies[] = self::brace_body( $content, (int) $fm[0][1] );
				}
			}
		}
		$keys = array();
		foreach ( $bodies as $body ) {
			if ( '' === $body ) {
				continue;
			}
			preg_match_all( '/\$\w+\s*\[\s*([\'"])([A-Za-z0-9_\-]{2,64})\1\s*\]\s*=(?!=)/', $body, $a );
			preg_match_all( '/([\'"])([A-Za-z0-9_\-]{2,64})\1\s*=>/', $body, $b );
			foreach ( array_merge( $a[2], $b[2] ) as $k ) {
				$keys[ sanitize_key( $k ) ] = true;
			}
		}
		unset( $keys[''] );
		return array_keys( $keys );
	}

	/**
	 * The `{…}` block that starts at the first brace after $offset (quotes skipped), or ''.
	 *
	 * @param string $content Source.
	 * @param int    $offset  Where to start looking.
	 * @return string
	 */
	protected static function brace_body( $content, $offset ) {
		$start = strpos( $content, '{', $offset );
		if ( false === $start || $start - $offset > 400 ) {
			return '';
		}
		$depth = 0;
		$len   = min( strlen( $content ), $start + 20000 );
		$quote = '';
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $content[ $i ];
			if ( '' !== $quote ) {
				if ( '\\' === $ch ) {
					++$i;
				} elseif ( $ch === $quote ) {
					$quote = '';
				}
				continue;
			}
			if ( "'" === $ch || '"' === $ch ) {
				$quote = $ch;
			} elseif ( '{' === $ch ) {
				++$depth;
			} elseif ( '}' === $ch && 0 === --$depth ) {
				return substr( $content, $start, $i - $start + 1 );
			}
		}
		return '';
	}

	/**
	 * Extracts the first quoted string argument from a function call that begins
	 * at (or shortly after) the given byte offset within the file contents.
	 *
	 * This is a convenience wrapper around ::extract_nth_arg_string_from_offset()
	 * requesting the first argument (n = 1).
	 *
	 * Robustness:
	 * - Handles basic multiline calls.
	 * - Walks balanced parentheses and respects quoted strings/escapes.
	 *
	 * @param string $content Full file contents to inspect.
	 * @param int    $offset  Byte offset where the function name was matched.
	 * @return string The first quoted argument (unmodified), or an empty string if none is found.
	 */
	protected static function extract_first_arg_string_from_offset( $content, $offset ) {
		return self::extract_nth_arg_string_from_offset( $content, $offset, 1 );
	}

	/**
	 * Extracts the Nth quoted string argument from a function call that begins
	 * at (or shortly after) the given byte offset within the file contents.
	 *
	 * The parser scans forward from the opening parenthesis, keeps track of
	 * parenthesis depth, and respects quoted strings and escape sequences until
	 * the matching closing parenthesis of the call is found. It then tokenizes
	 * only the quoted strings inside the argument list and returns the Nth one.
	 *
	 * Limitations:
	 * - Only literal quoted arguments are considered (e.g., 'foo' or "bar").
	 * - Complex expressions (arrays, concatenations, variables) are ignored.
	 *
	 * @param string $content Full file contents to inspect.
	 * @param int    $offset  Byte offset where the function name was matched.
	 * @param int    $n       1-based index of the quoted argument to return (1 = first).
	 * @return string The Nth quoted argument (unmodified), or an empty string if not found.
	 */
	protected static function extract_nth_arg_string_from_offset( $content, $offset, $n ) {
		// Pull the Nth argument as a raw expression, then resolve it to a literal —
		// transparently handling quoted strings, constants (define/const, incl.
		// Class::CONST), local string variables and literal/constant concatenation,
		// using the maps the scanner sets up for the current file/project.
		$expr = self::extract_nth_arg_expression( $content, $offset, $n );
		if ( '' === $expr ) {
			return '';
		}
		$vars = self::vars_before( self::$scan_var_exprs, (int) $offset, self::$scan_consts );
		return self::resolve_expression( $expr, self::$scan_consts, $vars );
	}

	/**
	 * Extract the raw text of the Nth top-level argument of a call beginning at (or
	 * just after) the given offset. Balanced across (), [], {} and respecting
	 * quoted strings; arguments are split on depth-1 commas. Returns '' if absent.
	 *
	 * @param string $content Full file contents.
	 * @param int    $offset  Byte offset of the matched call.
	 * @param int    $n       1-based argument index.
	 * @return string Raw (trimmed) argument expression, or ''.
	 */
	protected static function extract_nth_arg_expression( $content, $offset, $n ) {
		$open = strpos( $content, '(', $offset );
		if ( false === $open ) {
			return '';
		}
		$len   = strlen( $content );
		$depth = 0;
		$in_s  = false;
		$s_q   = '';
		$esc   = false;
		$args  = array();
		$cur   = '';

		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $content[ $i ];

			if ( $in_s ) {
				$cur .= $ch;
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$esc = true;
					continue;
				}
				if ( $ch === $s_q ) {
					$in_s = false;
				}
				continue;
			}

			if ( '\'' === $ch || '"' === $ch ) {
				$in_s = true;
				$s_q  = $ch;
				$cur .= $ch;
				continue;
			}
			if ( '(' === $ch || '[' === $ch || '{' === $ch ) {
				++$depth;
				if ( $depth > 1 ) {
					$cur .= $ch;
				}
				continue;
			}
			if ( ')' === $ch || ']' === $ch || '}' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$args[] = $cur;
					break;
				}
				$cur .= $ch;
				continue;
			}
			if ( ',' === $ch && 1 === $depth ) {
				$args[] = $cur;
				$cur    = '';
				continue;
			}
			if ( $depth >= 1 ) {
				$cur .= $ch;
			}
		}

		$n = (int) $n;
		if ( $n < 1 || $n > count( $args ) ) {
			return '';
		}
		return trim( $args[ $n - 1 ] );
	}

	/**
	 * Resolve an argument expression to a literal string when it can be determined
	 * with certainty: a quoted literal, a known constant (bare or Class::CONST), a
	 * resolved local variable, or a concatenation whose every part resolves. Any
	 * unknown/dynamic part yields '' (we never guess).
	 *
	 * @param string               $expr   Raw expression.
	 * @param array<string,string> $consts Constant map.
	 * @param array<string,string> $vars   Resolved variable map.
	 * @return string Literal value, or '' if not certain.
	 */
	public static function resolve_expression( $expr, array $consts = array(), array $vars = array() ) {
		$expr = trim( (string) $expr );
		if ( '' === $expr ) {
			return '';
		}
		$parts = self::split_top_level_concat( $expr );
		if ( count( $parts ) > 1 ) {
			$out = '';
			foreach ( $parts as $p ) {
				$r = self::resolve_atom( trim( $p ), $consts, $vars );
				if ( '' === $r ) {
					return '';
				}
				$out .= $r;
			}
			return $out;
		}
		return self::resolve_atom( $expr, $consts, $vars );
	}

	/**
	 * Resolve a single (non-concatenated) atom to a literal string, or ''.
	 *
	 * @param string               $atom   Atom text.
	 * @param array<string,string> $consts Constant map.
	 * @param array<string,string> $vars   Variable map.
	 * @return string
	 */
	protected static function resolve_atom( $atom, array $consts, array $vars ) {
		$atom = trim( (string) $atom );
		if ( '' === $atom ) {
			return '';
		}
		// Quoted literal: 'foo' or "foo".
		if ( preg_match( '/^([\'"])(.*)\1$/s', $atom, $m ) ) {
			return self::unquote_literal( $m[2] );
		}
		// Class/interface constant: Class::CONST, self::CONST, static::CONST (match by short name).
		if ( preg_match( '/^(?:\\\\?[A-Za-z_][A-Za-z0-9_\\\\]*::|self::|static::|parent::)([A-Za-z_][A-Za-z0-9_]*)$/', $atom, $m ) ) {
			return isset( $consts[ $m[1] ] ) ? $consts[ $m[1] ] : '';
		}
		// Bare global constant.
		if ( preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $atom ) ) {
			if ( in_array( strtolower( $atom ), array( 'true', 'false', 'null' ), true ) ) {
				return '';
			}
			return isset( $consts[ $atom ] ) ? $consts[ $atom ] : '';
		}
		// Simple variable.
		if ( preg_match( '/^\$([A-Za-z_][A-Za-z0-9_]*)$/', $atom, $m ) ) {
			return isset( $vars[ $m[1] ] ) ? $vars[ $m[1] ] : '';
		}
		return '';
	}

	/**
	 * Split an expression on top-level concatenation dots, respecting strings and
	 * (), [], {} nesting so dots inside literals or calls are not split points.
	 *
	 * @param string $expr Expression.
	 * @return string[] Parts (length 1 when there is no top-level concatenation).
	 */
	protected static function split_top_level_concat( $expr ) {
		$parts = array();
		$cur   = '';
		$depth = 0;
		$in_s  = false;
		$s_q   = '';
		$esc   = false;
		$len   = strlen( $expr );
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $expr[ $i ];
			if ( $in_s ) {
				$cur .= $ch;
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$esc = true;
					continue;
				}
				if ( $ch === $s_q ) {
					$in_s = false;
				}
				continue;
			}
			if ( '\'' === $ch || '"' === $ch ) {
				$in_s = true;
				$s_q  = $ch;
				$cur .= $ch;
				continue;
			}
			if ( '(' === $ch || '[' === $ch || '{' === $ch ) {
				++$depth;
				$cur .= $ch;
				continue;
			}
			if ( ')' === $ch || ']' === $ch || '}' === $ch ) {
				--$depth;
				$cur .= $ch;
				continue;
			}
			if ( '.' === $ch && 0 === $depth ) {
				$parts[] = $cur;
				$cur     = '';
				continue;
			}
			$cur .= $ch;
		}
		$parts[] = $cur;
		return $parts;
	}

	/**
	 * Unescape a quoted literal's inner text (parity with the previous extractor:
	 * strip slashes and decode entities), so the resolved value matches the runtime.
	 *
	 * @param string $inner Inner text between the quotes.
	 * @return string
	 */
	protected static function unquote_literal( $inner ) {
		return trim( html_entity_decode( stripslashes( (string) $inner ) ) );
	}

	/**
	 * Collect constants whose value is a certain literal, from define() and const
	 * declarations, resolving in source order so later constants can reference
	 * earlier ones. Class constants are keyed by their short name.
	 *
	 * @param string               $content File contents.
	 * @param array<string,string> $seed    Constants already known (project scope).
	 * @return array<string,string> Map NAME => literal value.
	 */
	public static function collect_constants( $content, array $seed = array() ) {
		$content = (string) $content;
		$consts  = $seed;
		$found   = array();

		// define( 'NAME', <expr> )
		if ( preg_match_all( '/\bdefine\s*\(/i', $content, $dm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $dm[0] as $mm ) {
				$off  = (int) $mm[1];
				$name = self::resolve_expression( self::extract_nth_arg_expression( $content, $off, 1 ), array(), array() );
				if ( '' === $name || ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) ) {
					continue;
				}
				$found[] = array(
					'pos'  => $off,
					'name' => $name,
					'kind' => 'define',
				);
			}
		}
		// const NAME = <expr>;
		if ( preg_match_all( '/\bconst\s+([A-Za-z_][A-Za-z0-9_]*)\s*=/i', $content, $cm, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $cm[1] as $i => $name_cap ) {
				$found[] = array(
					'pos'  => (int) $cm[0][ $i ][1],
					'name' => $name_cap[0],
					'kind' => 'const',
				);
			}
		}

		usort(
			$found,
			static function ( $a, $b ) {
				return $a['pos'] <=> $b['pos'];
			}
		);

		foreach ( $found as $f ) {
			if ( 'define' === $f['kind'] ) {
				$expr = self::extract_nth_arg_expression( $content, $f['pos'], 2 );
			} else {
				$eq   = strpos( $content, '=', $f['pos'] );
				$sc   = ( false !== $eq ) ? strpos( $content, ';', $eq ) : false;
				$expr = ( false !== $eq && false !== $sc ) ? substr( $content, $eq + 1, $sc - $eq - 1 ) : '';
			}
			$val = self::resolve_expression( $expr, $consts, array() );
			if ( '' !== $val ) {
				$consts[ $f['name'] ] = $val;
			}
		}
		return $consts;
	}

	/**
	 * Collect simple "$name = <expr>;" assignments (ordered by position) so an
	 * argument that passes a variable can be resolved to the nearest preceding
	 * literal assignment.
	 *
	 * @param string $content File contents.
	 * @return array<int,array{pos:int,name:string,expr:string}>
	 */
	public static function collect_var_exprs( $content ) {
		$out = array();
		if ( preg_match_all( '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*([^;]+);/', (string) $content, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[1] as $i => $name_cap ) {
				$out[] = array(
					'pos'  => (int) $m[0][ $i ][1],
					'name' => $name_cap[0],
					'expr' => trim( $m[2][ $i ][0] ),
				);
			}
		}
		return $out;
	}

	/**
	 * Pre-resolve a var-assignment list once: each assignment's literal value is
	 * computed a single time (in source order, so later vars may reference earlier
	 * ones), and stored under 'value'. This turns per-call resolution into a cheap
	 * position scan.
	 *
	 * @param array<int,array{pos:int,name:string,expr:string}> $assignments Var list.
	 * @param array<string,string>                              $consts      Constant map.
	 * @return array<int,array{pos:int,name:string,expr:string,value:string}>
	 */
	protected static function resolve_var_list( array $assignments, array $consts ) {
		$running = array();
		foreach ( $assignments as $i => $a ) {
			$val                        = self::resolve_expression( $a['expr'], $consts, $running );
			$assignments[ $i ]['value'] = $val;
			if ( '' !== $val ) {
				$running[ $a['name'] ] = $val; // Earlier vars feed later assignments.
			}
		}
		return $assignments;
	}

	/**
	 * Build a name => literal map from the var assignments that occur before a byte
	 * offset (last assignment wins). Uses the pre-resolved 'value' when present
	 * (the hot path); falls back to resolving the expression otherwise.
	 *
	 * @param array<int,array<string,mixed>> $assignments Var list.
	 * @param int                            $offset      Call offset.
	 * @param array<string,string>           $consts      Constant map.
	 * @return array<string,string>
	 */
	protected static function vars_before( array $assignments, $offset, array $consts ) {
		$vars = array();
		foreach ( $assignments as $a ) {
			if ( (int) $a['pos'] >= (int) $offset ) {
				break;
			}
			if ( array_key_exists( 'value', $a ) ) {
				if ( '' !== $a['value'] ) {
					$vars[ $a['name'] ] = $a['value'];
				}
				continue;
			}
			$v = self::resolve_expression( $a['expr'], $consts, array() );
			if ( '' !== $v ) {
				$vars[ $a['name'] ] = $v;
			}
		}
		return $vars;
	}

	/**
	 * Extract a scalar string value for a given associative key from the first array argument
	 * of a function call that starts at (or near) the given offset.
	 *
	 * This performs a lightweight parse: it scans the argument list, finds the first array
	 * literal ( [...] or array( ... ) ), and searches for a "'key' => 'value'" or
	 * '"key" => "value"' pattern, returning the literal value if found.
	 *
	 * Limitations:
	 * - Only supports simple literal arrays in the first argument position.
	 * - Only returns literal quoted values (no variables or expressions).
	 *
	 * @param string $content Full file contents.
	 * @param int    $offset  Byte offset where the function name was matched.
	 * @param string $assoc_key The associative key to look for (e.g. 'post_type').
	 * @return string The literal value if found, or an empty string.
	 */
	protected static function extract_assoc_value_from_first_array_arg( $content, $offset, $assoc_key ) {
		// Find opening parenthesis after the match.
		$open = strpos( $content, '(', $offset );
		if ( false === $open ) {
			return '';
		}

		// Find end of the call (matching ')')—reuse a small balanced parse like above.
		$depth = 0;
		$end   = $open;
		$len   = strlen( $content );
		$in_s  = false;
		$s_q   = '';
		$esc   = false;
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $content[ $i ];
			if ( $in_s ) {
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$esc = true;
					continue;
				}
				if ( $ch === $s_q ) {
					$in_s = false;
				}
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			} elseif ( '\'' === $ch || '"' === $ch ) {
				$in_s = true;
				$s_q  = $ch;
			}
		}
		if ( $end <= $open ) {
			return '';
		}

		$args_str = substr( $content, $open + 1, $end - $open - 1 );

		// Try to capture the first array-like literal in the argument list.
		// Heuristics: find either "[ ... ]" or "array( ... )" earliest in $args_str.
		if ( preg_match( '/\[\s*.*?\s\]|array\s*\(\s*.*?\s*\)/s', $args_str, $m ) ) {
			$arr = $m[0];

			// Pattern for "'key' => 'value'" or '"key" => "value"'
			$k = preg_quote( $assoc_key, '/' );
			if ( preg_match( '/[\'"]' . $k . '[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/', $arr, $kv ) ) {
				return trim( stripslashes( $kv[1] ) );
			}
		}

		return '';
	}

	/**
	 * Extract every string key from the first array literal in a call's argument
	 * list, e.g. register_nav_menus( array( 'primary' => 'Primary', 'footer' => … ) )
	 * yields array( 'primary', 'footer' ). Only literal quoted keys are returned;
	 * dynamic keys are ignored (we never guess).
	 *
	 * @param string $content Full file contents.
	 * @param int    $offset  Match offset of the call.
	 * @return string[] List of literal key slugs (possibly empty).
	 */
	protected static function extract_assoc_keys_from_first_array_arg( $content, $offset ) {
		$open = strpos( $content, '(', $offset );
		if ( false === $open ) {
			return array();
		}

		// Balanced parse of the call to isolate its argument list (string-aware).
		$depth = 0;
		$end   = $open;
		$len   = strlen( $content );
		$in_s  = false;
		$s_q   = '';
		$esc   = false;
		for ( $i = $open; $i < $len; $i++ ) {
			$ch = $content[ $i ];
			if ( $in_s ) {
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$esc = true;
					continue;
				}
				if ( $ch === $s_q ) {
					$in_s = false;
				}
				continue;
			}
			if ( '(' === $ch ) {
				++$depth;
			} elseif ( ')' === $ch ) {
				--$depth;
				if ( 0 === $depth ) {
					$end = $i;
					break;
				}
			} elseif ( '\'' === $ch || '"' === $ch ) {
				$in_s = true;
				$s_q  = $ch;
			}
		}
		if ( $end <= $open ) {
			return array();
		}

		$args_str = substr( $content, $open + 1, $end - $open - 1 );

		// Isolate the first array literal with a balanced, string-aware scan so
		// that function calls used as values (e.g. __( 'Header', 'td' )) and
		// nested arrays do not prematurely terminate the block.
		$block = self::isolate_first_array_literal( $args_str );
		if ( '' === $block ) {
			return array();
		}

		// Collect every literal "'key' =>" / '"key" =>'.
		if ( ! preg_match_all( '/[\'"]([A-Za-z0-9_\-\.]+)[\'"]\s*=>/', $block, $keys ) ) {
			return array();
		}

		return array_map(
			static function ( $k ) {
				return trim( stripslashes( $k ) );
			},
			$keys[1]
		);
	}

	/**
	 * Isolate the first array literal ("[ … ]" or "array( … )") in a fragment,
	 * matching brackets/parens with a balanced, string-aware scan. Returns the
	 * literal (including its delimiters) or '' when none is found.
	 *
	 * @param string $fragment Argument-list fragment to scan.
	 * @return string
	 */
	protected static function isolate_first_array_literal( $fragment ) {
		$len = strlen( $fragment );

		// Find the start of the first array literal and its opening delimiter.
		$start  = -1;
		$opener = '';
		for ( $i = 0; $i < $len; $i++ ) {
			$ch = $fragment[ $i ];
			if ( '[' === $ch ) {
				$start  = $i;
				$opener = '[';
				break;
			}
			// "array(" (case-insensitive), as a word boundary.
			if ( ( 'a' === $ch || 'A' === $ch ) && 0 === substr_compare( $fragment, 'array', $i, 5, true ) ) {
				$paren = strpos( $fragment, '(', $i + 5 );
				if ( false !== $paren && '' === trim( substr( $fragment, $i + 5, $paren - ( $i + 5 ) ) ) ) {
					$start  = $paren;
					$opener = '(';
					break;
				}
			}
		}
		if ( $start < 0 ) {
			return '';
		}

		$closer = '[' === $opener ? ']' : ')';
		$depth  = 0;
		$in_s   = false;
		$s_q    = '';
		$esc    = false;
		for ( $i = $start; $i < $len; $i++ ) {
			$ch = $fragment[ $i ];
			if ( $in_s ) {
				if ( $esc ) {
					$esc = false;
					continue;
				}
				if ( '\\' === $ch ) {
					$esc = true;
					continue;
				}
				if ( $ch === $s_q ) {
					$in_s = false;
				}
				continue;
			}
			if ( '\'' === $ch || '"' === $ch ) {
				$in_s = true;
				$s_q  = $ch;
			} elseif ( $ch === $opener ) {
				++$depth;
			} elseif ( $ch === $closer ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $fragment, $start, $i - $start + 1 );
				}
			}
		}

		return '';
	}

	/**
	 * Extract the first block name literal from a registerBlockType call.
	 *
	 * @param string $content Full file contents.
	 * @param int    $offset  Match offset for "wp.blocks.registerBlockType".
	 * @return string Block name like "namespace/block" or ''.
	 */
	protected static function extract_js_block_name_from_offset( $content, $offset ) {
		$first = self::extract_first_arg_string_from_offset( $content, $offset );
		// Basic sanity: block name should contain a slash
		if ( '' !== $first && false !== strpos( $first, '/' ) ) {
			return trim( $first );
		}
		return '';
	}
}
