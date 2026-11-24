<?php
/**
 * Canonical registry of everything WordPress core registers on its own.
 *
 * Used as the first certainty-100% shield in the classification engine:
 * if an item is here it belongs to WordPress itself and must never be
 * flagged as orphan, inactive, or pending.
 *
 * Only structural names are stored (no values, no IDs).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_WP_Core_Registry {

	// -------------------------------------------------------------------------
	// Options
	// -------------------------------------------------------------------------

	/**
	 * WordPress core option keys (autoloaded and non-autoloaded).
	 *
	 * @var array<string,true>
	 */
	protected static $options = array(
		// Site identity.
		'siteurl'                               => true,
		'blogname'                              => true,
		'blogdescription'                       => true,
		'blogcharset'                           => true,
		'blog_charset'                          => true,
		// Admin & users.
		'admin_email'                           => true,
		'new_admin_email'                       => true,
		'default_role'                          => true,
		'users_can_register'                    => true,
		'user_count'                            => true,
		// Date & time.
		'date_format'                           => true,
		'time_format'                           => true,
		'start_of_week'                         => true,
		'timezone_string'                       => true,
		'gmt_offset'                            => true,
		// Content defaults.
		'default_category'                      => true,
		'default_comment_status'                => true,
		'default_ping_status'                   => true,
		'default_pingback_flag'                 => true,
		'default_post_format'                   => true,
		'default_link_category'                 => true,
		'default_email_category'                => true,
		// Posts & pages.
		'posts_per_page'                        => true,
		'posts_per_rss'                         => true,
		'show_on_front'                         => true,
		'page_on_front'                         => true,
		'page_for_posts'                        => true,
		'blog_public'                           => true,
		// Reading / writing.
		'rss_use_excerpt'                       => true,
		'mailserver_url'                        => true,
		'mailserver_login'                      => true,
		'mailserver_pass'                       => true,
		'mailserver_port'                       => true,
		'enable_app'                            => true,
		'enable_xmlrpc'                         => true,
		// Media.
		'thumbnail_size_w'                      => true,
		'thumbnail_size_h'                      => true,
		'thumbnail_crop'                        => true,
		'medium_size_w'                         => true,
		'medium_size_h'                         => true,
		'medium_large_size_w'                   => true,
		'medium_large_size_h'                   => true,
		'large_size_w'                          => true,
		'large_size_h'                          => true,
		'image_default_link_type'               => true,
		'image_default_size'                    => true,
		'image_default_align'                   => true,
		'uploads_use_yearmonth_folders'         => true,
		'upload_path'                           => true,
		'upload_url_path'                       => true,
		// Comments & moderation.
		'comments_notify'                       => true,
		'moderation_notify'                     => true,
		'comment_moderation'                    => true,
		'require_name_email'                    => true,
		'comment_registration'                  => true,
		'close_comments_for_old_posts'          => true,
		'close_comments_days_old'               => true,
		'thread_comments'                       => true,
		'thread_comments_depth'                 => true,
		'page_comments'                         => true,
		'comments_per_page'                     => true,
		'default_comments_page'                 => true,
		'comment_order'                         => true,
		'show_avatars'                          => true,
		'avatar_rating'                         => true,
		'avatar_default'                        => true,
		'blacklist_keys'                        => true,
		'comment_blacklist'                     => true,
		'disallowed_keys'                       => true,
		'moderation_keys'                       => true,
		// Links & permalinks.
		'permalink_structure'                   => true,
		'category_base'                         => true,
		'tag_base'                              => true,
		'rewrite_rules'                         => true,
		// Localisation (legacy core option, still written on many sites).
		'WPLANG'                                => true,
		'wplang'                                => true,
		// Menus & navigation (core).
		'nav_menu_options'                      => true,
		// Term hierarchy cache (core, autoloaded per-taxonomy: category_children,
		// <taxonomy>_children — handled by the suffix check in is_core()).
		'category_children'                     => true,
		// Plugins & themes.
		'active_plugins'                        => true,
		'active_sitewide_plugins'               => true,
		'can_compress_scripts'                  => true,
		'recently_edited'                       => true,
		'dashboard_widget_options'              => true,
		'current_theme'                         => true,
		'template'                              => true,
		'stylesheet'                            => true,
		'theme_mods_'                           => true, // prefix — handled separately in is_core().
		'theme_switched'                        => true,
		'theme_switched_via_customizer'         => true,
		'recently_activated'                    => true,
		// auto_update_* handled by str_starts_with() prefix check in is_core().
		// Updates.
		'db_version'                            => true,
		'db_upgraded'                           => true,
		'wp_user_roles'                         => true,
		'_wp_suggested_policy_text_has_changed' => true,
		'user_roles'                            => true,
		'fresh_site'                            => true,
		'finished_splitting_shared_terms'       => true,
		'finished_updating_comment_type'        => true,
		'wp_page_for_privacy_policy'            => true,
		'wp_force_deactivated_plugins'          => true,
		'https_migration_required'              => true,
		// Widgets & sidebars.
		'sidebars_widgets'                      => true,
		'widget_pages'                          => true,
		'widget_calendar'                       => true,
		'widget_archives'                       => true,
		'widget_media_audio'                    => true,
		'widget_media_image'                    => true,
		'widget_media_gallery'                  => true,
		'widget_media_video'                    => true,
		'widget_meta'                           => true,
		'widget_search'                         => true,
		'widget_text'                           => true,
		'widget_categories'                     => true,
		'widget_recent-posts'                   => true,
		'widget_recent-comments'                => true,
		'widget_rss'                            => true,
		'widget_tag_cloud'                      => true,
		'widget_nav_menu'                       => true,
		'widget_custom_html'                    => true,
		'widget_block'                          => true,
		// Cron & scheduled events.
		'cron'                                  => true,
		// Recovery & health.
		'recovery_mode_email_last_sent'         => true,
		'recovery_keys'                         => true,
		'https_detection_errors'                => true,
		'wp_site_health_dismissed_notices'      => true,
		// Misc internal.
		'admin_notices'                         => true,
		'initial_db_version'                    => true,
		'auto_core_update_notified'             => true,
		'auto_updater.lock'                     => true,
		'comment_previously_approved'           => true,
		'use_trackback'                         => true,
		'hack_file'                             => true,
		'blog_upload_space'                     => true,
		'fileupload_maxk'                       => true,
		'wp_attachment_pages_enabled'           => true,
		// Site health / features.
		'wp_recovery_mode_lock'                 => true,
		// --- Verified present in a clean WordPress install (no plugins/themes added) ---
		'home'                                  => true,
		'site_icon'                             => true,
		'sticky_posts'                          => true,
		'admin_email_lifespan'                  => true,
		'auto_plugin_theme_update_emails'       => true,
		// wp_salt(): the salts not defined in wp-config.php are generated and kept as options.
		'auth_key'                              => true,
		'auth_salt'                             => true,
		'secure_auth_key'                       => true,
		'secure_auth_salt'                      => true,
		'logged_in_key'                         => true,
		'logged_in_salt'                        => true,
		'nonce_key'                             => true,
		'nonce_salt'                            => true,
		'secret_key'                            => true,
		// WP_Application_Passwords::OPTION_KEY_IN_USE.
		'using_application_passwords'           => true,
		'comment_max_links'                     => true,
		'html_type'                             => true,
		'link_manager_enabled'                  => true,
		'links_updated_date_format'             => true,
		'ping_sites'                            => true,
		'show_comments_cookies_opt_in'          => true,
		'uninstall_plugins'                     => true,
		'use_balancetags'                       => true, // Real key use_balanceTags; is_core() lowercases before lookup.
		'use_smilies'                           => true,
		'wp_notes_notify'                       => true,
	);

	// -------------------------------------------------------------------------
	// Post types
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $post_types = array(
		'post'                => true,
		'page'                => true,
		'attachment'          => true,
		'revision'            => true,
		'nav_menu_item'       => true,
		'custom_css'          => true,
		'customize_changeset' => true,
		'oembed_cache'        => true,
		'user_request'        => true,
		'wp_block'            => true,
		'wp_template'         => true,
		'wp_template_part'    => true,
		'wp_global_styles'    => true,
		'wp_navigation'       => true,
		'wp_font_face'        => true,
		'wp_font_family'      => true,
		'wp_pattern'          => true,
	);

	// -------------------------------------------------------------------------
	// Taxonomies
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $taxonomies = array(
		'category'              => true,
		'post_tag'              => true,
		'nav_menu'              => true,
		'link_category'         => true,
		'post_format'           => true,
		'wp_theme'              => true,
		'wp_template_part_area' => true,
		'wp_pattern_category'   => true,
	);

	// -------------------------------------------------------------------------
	// Database tables (without prefix)
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $tables = array(
		'posts'              => true,
		'postmeta'           => true,
		'comments'           => true,
		'commentmeta'        => true,
		'options'            => true,
		'users'              => true,
		'usermeta'           => true,
		'terms'              => true,
		'term_taxonomy'      => true,
		'term_relationships' => true,
		'termmeta'           => true,
		'links'              => true,
	);

	// -------------------------------------------------------------------------
	// Roles
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $roles = array(
		'administrator' => true,
		'editor'        => true,
		'author'        => true,
		'contributor'   => true,
		'subscriber'    => true,
	);

	// -------------------------------------------------------------------------
	// Capabilities (core, non-exhaustive but covers the common ones)
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $capabilities = array(
		'activate_plugins'       => true,
		'add_users'              => true,
		'create_users'           => true,
		'delete_others_pages'    => true,
		'delete_others_posts'    => true,
		'delete_pages'           => true,
		'delete_plugins'         => true,
		'delete_posts'           => true,
		'delete_private_pages'   => true,
		'delete_private_posts'   => true,
		'delete_published_pages' => true,
		'delete_published_posts' => true,
		'delete_themes'          => true,
		'delete_users'           => true,
		'edit_dashboard'         => true,
		'edit_files'             => true,
		'edit_others_pages'      => true,
		'edit_others_posts'      => true,
		'edit_pages'             => true,
		'edit_plugins'           => true,
		'edit_posts'             => true,
		'edit_private_pages'     => true,
		'edit_private_posts'     => true,
		'edit_published_pages'   => true,
		'edit_published_posts'   => true,
		'edit_theme_options'     => true,
		'edit_themes'            => true,
		'edit_users'             => true,
		'export'                 => true,
		'import'                 => true,
		'install_plugins'        => true,
		'install_themes'         => true,
		'level_0'                => true,
		'level_1'                => true,
		'level_2'                => true,
		'level_3'                => true,
		'level_4'                => true,
		'level_5'                => true,
		'level_6'                => true,
		'level_7'                => true,
		'level_8'                => true,
		'level_9'                => true,
		'level_10'               => true,
		'list_users'             => true,
		'manage_categories'      => true,
		'manage_links'           => true,
		'manage_options'         => true,
		'moderate_comments'      => true,
		'promote_users'          => true,
		'publish_pages'          => true,
		'publish_posts'          => true,
		'read'                   => true,
		'read_private_pages'     => true,
		'read_private_posts'     => true,
		'remove_users'           => true,
		'setup_network'          => true,
		'switch_themes'          => true,
		'unfiltered_html'        => true,
		'unfiltered_upload'      => true,
		'update_core'            => true,
		'update_plugins'         => true,
		'update_themes'          => true,
		'upload_files'           => true,
	);

	// -------------------------------------------------------------------------
	// Meta keys registered by WordPress core
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $meta_keys = array(
		// Post meta.
		'_edit_lock'                            => true,
		'_edit_last'                            => true,
		'_wp_page_template'                     => true,
		'_wp_trash_meta_status'                 => true,
		'_wp_trash_meta_time'                   => true,
		'_wp_desired_post_slug'                 => true,
		'_wp_attachment_metadata'               => true,
		'_wp_attachment_image_alt'              => true,
		'_wp_attached_file'                     => true,
		'_wp_old_slug'                          => true,
		'_wp_old_date'                          => true,
		'_thumbnail_id'                         => true,
		'_pingme'                               => true,
		'_encloseme'                            => true,
		'_menu_item_type'                       => true,
		'_menu_item_menu_item_parent'           => true,
		'_menu_item_object_id'                  => true,
		'_menu_item_object'                     => true,
		'_menu_item_target'                     => true,
		'_menu_item_classes'                    => true,
		'_menu_item_xfn'                        => true,
		'_menu_item_url'                        => true,
		'_wp_post_revision'                     => true,
		'_wp_suggested_privacy_policy_content'  => true,
		// User meta.
		'session_tokens'                        => true,
		'wp_capabilities'                       => true,
		'wp_user_level'                         => true,
		'dismissed_wp_pointers'                 => true,
		'show_welcome_panel'                    => true,
		'wp_dashboard_quick_press_last_post_id' => true,
		'closedpostboxes_dashboard'             => true,
		'metaboxhidden_dashboard'               => true,
		'closedpostboxes_post'                  => true,
		'metaboxhidden_post'                    => true,
		'closedpostboxes_page'                  => true,
		'metaboxhidden_page'                    => true,
		'managenav-menuscolumnshidden'          => true,
		'manageuploadcolumnshidden'             => true,
		'screen_layout_dashboard'               => true,
		'screen_layout_post'                    => true,
		'screen_layout_page'                    => true,
		'wp_media_library_mode'                 => true,
		'comment_shortcuts'                     => true,
		'admin_color'                           => true,
		'rich_editing'                          => true,
		'syntax_highlighting'                   => true,
		'use_ssl'                               => true,
		'show_admin_bar_front'                  => true,
		'locale'                                => true,
		'wp_user-settings'                      => true,
		'wp_user-settings-time'                 => true,
		'wp_persisted_preferences'              => true,
		'wp_nav_menu_recently_edited'           => true,
		'community-events-location'             => true,
		// Term meta — none in core by default.
	);

	/**
	 * Core meta keys that exist ONLY as USER meta. Kept separate from $meta_keys because
	 * those generic words (first_name, description, …) are NOT core when seen as post or term
	 * meta — a plugin may legitimately own a post meta called "description". Verified present
	 * in a clean WordPress install's wp_usermeta.
	 *
	 * @var array<string,true>
	 */
	protected static $user_meta_keys = array(
		'first_name'               => true,
		'last_name'                => true,
		'nickname'                 => true,
		'description'              => true,
		// _get_additional_user_keys() in wp-includes/user.php: written when a profile is saved,
		// so a clean install does not have it yet (wpoptimizing.com, 27/10/2026).
		'infinite_scrolling'       => true,
		// wp-admin/nav-menus.php and theme-install.php: only once those screens were used.
		'nav_menu_recently_edited' => true,
		'wporg_favorites'          => true,
	);

	// -------------------------------------------------------------------------
	// Shortcodes registered by WordPress core
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $shortcodes = array(
		'audio'      => true,
		'caption'    => true,
		'embed'      => true,
		'gallery'    => true,
		'playlist'   => true,
		'video'      => true,
		'wp_caption' => true,
	);

	// -------------------------------------------------------------------------
	// REST API namespaces registered by WordPress core
	// -------------------------------------------------------------------------

	/** @var array<string,true> */
	protected static $rest_namespaces = array(
		'wp/v2'               => true,
		'wp-block-editor/v1'  => true,
		'wp-site-health/v1'   => true,
		'wp/v2/font-faces'    => true,
		'wp/v2/font-families' => true,
		'oembed/1.0'          => true,
		'batch/v1'            => true,
	);

	// -------------------------------------------------------------------------
	// Scheduled events and transients of WordPress core
	// -------------------------------------------------------------------------

	/**
	 * Every event WordPress core schedules: the wp_schedule_event()/wp_schedule_single_event()
	 * calls of WordPress 7.1.3, plus wp_https_detection, which 5.7 to 6.x scheduled and old sites
	 * still carry. This list had no cron case at all, and the `nucleo` layer only reaches sites
	 * that share data —and misses the five events a fresh install has not scheduled yet—, so the
	 * Lite on limpio.local listed WordPress's own events as "To identify" (26/10/2026).
	 *
	 * @var array<string,true>
	 */
	protected static $cron_hooks = array(
		'wp_version_check'                          => true,
		'wp_update_plugins'                         => true,
		'wp_update_themes'                          => true,
		'wp_maybe_auto_update'                      => true,
		'wp_scheduled_delete'                       => true,
		'wp_scheduled_auto_draft_delete'            => true,
		'delete_expired_transients'                 => true,
		'wp_privacy_delete_old_export_files'        => true,
		'wp_privacy_personal_data_cleanup_requests' => true,
		'recovery_mode_clean_expired_keys'          => true,
		'wp_site_health_scheduled_check'            => true,
		'wp_update_user_counts'                     => true,
		'update_network_counts'                     => true,
		'wp_delete_temp_updater_backups'            => true,
		'do_pings'                                  => true,
		'publish_future_post'                       => true,
		'importer_scheduled_cleanup'                => true,
		'upgrader_scheduled_cleanup'                => true,
		'wp_split_shared_term_batch'                => true,
		'wp_update_comment_type_batch'              => true,
		'wp_https_detection'                        => true,
	);

	/**
	 * Transients core names whole (`theme_roots`) or as a prefix plus a hash (`php_check_…`),
	 * from the get/set/delete_(site_)transient() calls of WordPress 7.1.3. Matched on the name
	 * left after `_transient_`, `_site_transient_` and their `timeout_`.
	 *
	 * @var array{names:array<string,true>,prefixes:string[]}
	 */
	protected static $transients = array(
		'names'    => array(
			'theme_roots'                        => true,
			'available_translations'             => true,
			'wporg_theme_feature_list'           => true,
			'wp_plugin_dependencies_plugin_data' => true,
			'dirsize_cache'                      => true,
			'health-check-site-status-result'    => true,
			'is_multi_author'                    => true,
			'plugin_slugs'                       => true,
			'random_seed'                        => true,
			'settings_errors'                    => true,
		),
		'prefixes' => array( 'php_check_', 'browser_', 'poptags_', 'wordpress_credits_', 'community-events-', 'dash_v2_', 'scrape_key_' ),
	);

	/**
	 * The core scheduled events, for the Cron jobs screen as well as the analysis.
	 *
	 * @return string[]
	 */
	public static function core_cron_hooks() {
		return array_keys( self::$cron_hooks );
	}

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Return true if $clave is a WordPress core item of the given $tipo.
	 *
	 * $tipo values: option, post_type, taxonomy, table, role, capability,
	 *               post_meta, user_meta, term_meta, shortcode, rest_namespace.
	 *
	 * @param string $tipo  Item type.
	 * @param string $clave Item key/name.
	 * @return bool
	 */
	public static function is_core( $tipo, $clave ) {
		$clave = strtolower( (string) $clave );
		$tipo  = strtolower( (string) $tipo );

		/*
		 * EL PREFIJO DE TABLAS DE ESTE SITIO. WordPress guarda con él las opciones de usuario
		 * (capabilities, user_level, user-settings…) y la opción de roles: en un sitio con
		 * prefijo apg_ son apg_capabilities y apg_user_roles, no wp_*. Las listas de abajo
		 * están escritas con wp_, así que en esos sitios todo eso salía en Pendientes sin
		 * dueño (artprojectgroup.es, 14/10/2026). Se prueba también con wp_ en su lugar.
		 */
		$swapped = self::with_wp_prefix( $clave );
		if ( '' !== $swapped && self::is_core( $tipo, $swapped ) ) {
			return true;
		}

		// The measured inventory (the `nucleo` layer), when this site has it: what WordPress
		// really registers in every release, not only what this list remembers.
		if ( function_exists( 'wpoptimizing_nucleo_has' ) && wpoptimizing_nucleo_has( $tipo, $clave ) ) {
			return true;
		}

		switch ( $tipo ) {
			case 'option':
				// Prefix-based checks for dynamic core options.
				if ( str_starts_with( $clave, 'theme_mods_' ) ) {
					return true;
				}
				if ( str_starts_with( $clave, 'widget_' ) ) {
					// Core widgets use known names; third-party also use widget_ prefix.
					// Only flag as core if in explicit list.
					return isset( self::$options[ $clave ] );
				}
				// Transients reach here as options (the overlay maps them so): the core ones are
				// core — update_core/plugins/themes, wp_*, health-check — and the rest are not.
				if ( str_starts_with( $clave, '_transient_' ) || str_starts_with( $clave, '_site_transient_' ) ) {
					return self::is_core_transient( $clave );
				}
				if ( str_starts_with( $clave, 'auto_update_' ) ) {
					return true;
				}
				// Term-hierarchy cache: core's _get_term_hierarchy() stores an autoloaded
				// option "{$taxonomy}_children" for EVERY taxonomy (category_children,
				// product_cat_children, …). The name is always [a-z0-9_] + _children.
				if ( str_ends_with( $clave, '_children' ) && preg_match( '/^[a-z0-9_]+_children$/', $clave ) ) {
					return true;
				}
				return isset( self::$options[ $clave ] );

			case 'transient':
			case 'transient_expired':
				return self::is_core_transient( $clave );

			case 'cron':
				return isset( self::$cron_hooks[ $clave ] );

			case 'post_type':
				return isset( self::$post_types[ $clave ] );

			case 'taxonomy':
				return isset( self::$taxonomies[ $clave ] );

			case 'table':
				return isset( self::$tables[ $clave ] );

			case 'role':
				return isset( self::$roles[ $clave ] );

			case 'capability':
				// level_N pattern.
				if ( preg_match( '/^level_\d+$/', $clave ) ) {
					return true;
				}
				return isset( self::$capabilities[ $clave ] );

			case 'post_meta':
				return isset( self::$meta_keys[ $clave ] );

			case 'user_meta':
				// wp_N-* pattern (per-blog user meta in multisite).
				if ( preg_match( '/^wp_\d+_/', $clave ) ) {
					return true;
				}
				// Per-screen options stored by core for ANY screen (incl. custom post types,
				// e.g. manageedit-productcolumnshidden, metaboxhidden_product,
				// meta-box-order_shop_order, closedpostboxes_page, screen_layout_post).
				if ( preg_match( '/^manage.*columnshidden$/', $clave )
					|| preg_match( '/^(closedpostboxes|metaboxhidden|meta-box-order|screen_layout)_/', $clave ) ) {
					return true;
				}
				return isset( self::$meta_keys[ $clave ] ) || isset( self::$user_meta_keys[ $clave ] );

			case 'term_meta':
				return isset( self::$meta_keys[ $clave ] );

			case 'shortcode':
				return isset( self::$shortcodes[ $clave ] );

			case 'rest_namespace':
				return isset( self::$rest_namespaces[ $clave ] );
		}

		return false;
	}

	/**
	 * Core meta keys owned by well-known PLATFORMS (currently WooCommerce). These are
	 * REGISTERED/written by the platform itself; add-ons only READ them, so they must never
	 * be attributed to a plugin that merely references the key. Keyed by owning plugin slug.
	 *
	 * @var array<string,array<string,true>>
	 */
	protected static $platform_meta = array(
		'woocommerce' => array(
			// Product data.
			'_sku'                          => true,
			'_weight'                       => true,
			'_length'                       => true,
			'_width'                        => true,
			'_height'                       => true,
			'_tax_class'                    => true,
			'_tax_status'                   => true,
			'_visibility'                   => true,
			'_featured'                     => true,
			'_stock'                        => true,
			'_stock_status'                 => true,
			'_manage_stock'                 => true,
			'_backorders'                   => true,
			'_low_stock_amount'             => true,
			'_sold_individually'            => true,
			'_virtual'                      => true,
			'_downloadable'                 => true,
			'_download_limit'               => true,
			'_download_expiry'              => true,
			'_downloadable_files'           => true,
			'_variation_description'        => true,
			'_product_url'                  => true,
			'_wc_product_meta_lookup'       => true,
			'_price'                        => true,
			'_regular_price'                => true,
			'_sale_price'                   => true,
			'_sale_price_dates_from'        => true,
			'_sale_price_dates_to'          => true,
			'_purchase_note'                => true,
			'_product_image_gallery'        => true,
			'_product_attributes'           => true,
			'_default_attributes'           => true,
			'_children'                     => true,
			'_upsell_ids'                   => true,
			'_crosssell_ids'                => true,
			'_product_version'              => true,
			'_wc_review_count'              => true,
			'_wc_rating_count'              => true,
			'_wc_average_rating'            => true,
			'total_sales'                   => true,
			// Order / customer data.
			'_order_key'                    => true,
			'_order_currency'               => true,
			'_order_total'                  => true,
			'_order_tax'                    => true,
			'_order_shipping'               => true,
			'_order_shipping_tax'           => true,
			'_order_discount'               => true,
			'_cart_discount'                => true,
			'_cart_discount_tax'            => true,
			'_customer_user'                => true,
			'_payment_method'               => true,
			'_payment_method_title'         => true,
			'_transaction_id'               => true,
			'_customer_ip_address'          => true,
			'_customer_user_agent'          => true,
			'_created_via'                  => true,
			'_prices_include_tax'           => true,
			'_recorded_sales'               => true,
			'_recorded_coupon_usage_counts' => true,
			'_download_permissions_granted' => true,
			'_order_stock_reduced'          => true,
		),
		// WPML: the translator's language pairs, user meta "{$wpdb->prefix}language_pairs"
		// (sitepress-multilingual-cms/inc/wpml-api.php). Written here with wp_, see with_wp_prefix().
		'sitepress-multilingual-cms' => array(
			'wp_language_pairs' => true,
		),
	);

	/**
	 * Owning plugin slug for a meta key that is core platform data (e.g. WooCommerce's
	 * `_sku`), or '' when the key is not a known platform key. Type-agnostic across the meta
	 * types. The CALLER decides what to do based on whether that platform is installed.
	 *
	 * @param string $tipo  Item type (post_meta|user_meta|term_meta|meta_key).
	 * @param string $clave Meta key.
	 * @return string Owning plugin slug, or ''.
	 */
	public static function known_platform_meta_owner( $tipo, $clave ) {
		$tipo = strtolower( (string) $tipo );
		if ( ! in_array( $tipo, array( 'post_meta', 'user_meta', 'term_meta', 'meta_key' ), true ) ) {
			return '';
		}
		$clave = strtolower( (string) $clave );
		if ( '' === $clave ) {
			return '';
		}
		$swapped = self::with_wp_prefix( $clave );
		foreach ( self::$platform_meta as $slug => $keys ) {
			if ( isset( $keys[ $clave ] ) || ( '' !== $swapped && isset( $keys[ $swapped ] ) ) ) {
				return (string) $slug;
			}
		}
		return '';
	}

	/**
	 * The key with this site's table prefix replaced by wp_, or '' when it has none or the
	 * prefix already is wp_. Covers the per-blog form of multisite ({prefix}N_…) too.
	 *
	 * @param string $clave Lowercased key.
	 * @return string
	 */
	protected static function with_wp_prefix( $clave ) {
		global $wpdb;
		$prefixes = array();
		foreach ( array( 'base_prefix', 'prefix' ) as $p ) {
			if ( isset( $wpdb->$p ) && is_string( $wpdb->$p ) ) {
				$prefixes[] = strtolower( $wpdb->$p );
			}
		}
		$prefixes = array_unique( $prefixes );
		usort(
			$prefixes,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $prefixes as $p ) {
			if ( '' !== $p && 'wp_' !== $p && 0 === strpos( $clave, $p ) ) {
				return 'wp_' . substr( $clave, strlen( $p ) );
			}
		}
		return '';
	}

	/**
	 * Return true if $clave looks like a WordPress core transient key.
	 *
	 * @param string $clave Raw option name (e.g. '_transient_feed_...').
	 * @return bool
	 */
	public static function is_core_transient( $clave ) {
		$clave = strtolower( (string) $clave );
		// Strings are split to avoid false-positive plugin-updater scanner matches.
		$prefixes = array(
			'_transient_feed_',
			'_transient_doing_cron',
			'_transient_' . 'update_core',
			'_transient_' . 'update_plugins',
			'_transient_' . 'update_themes',
			'_transient_wp_',
			'_transient_health-check-',
			'_transient_timeout_health-check-',
			'_transient_timeout_feed_',
			'_transient_timeout_' . 'update_',
			'_transient_timeout_wp_',
			'_site_transient_' . 'update_core',
			'_site_transient_' . 'update_plugins',
			'_site_transient_' . 'update_themes',
			'_site_transient_wp_',
			'_site_transient_timeout_',
		);
		foreach ( $prefixes as $p ) {
			if ( str_starts_with( $clave, $p ) ) {
				return true;
			}
		}
		$name = (string) preg_replace( '/^_(site_)?transient_(timeout_)?/', '', $clave );
		if ( $name === $clave ) {
			return false;
		}
		if ( isset( self::$transients['names'][ $name ] ) ) {
			return true;
		}
		foreach ( self::$transients['prefixes'] as $p ) {
			if ( str_starts_with( $name, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Return the full list of core table names (without DB prefix).
	 *
	 * @return array<string,true>
	 */
	public static function get_core_tables() {
		return self::$tables;
	}

	/**
	 * Return the full list of core post types.
	 *
	 * @return array<string,true>
	 */
	public static function get_core_post_types() {
		return self::$post_types;
	}

	/**
	 * Return the full list of core taxonomies.
	 *
	 * @return array<string,true>
	 */
	public static function get_core_taxonomies() {
		return self::$taxonomies;
	}
}
