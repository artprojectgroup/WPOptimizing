<?php
/**
 * Runtime evidence collector.
 *
 * Captures deterministic runtime creations/updates and stores them as
 * supplementary ownership evidence for scanner classification.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Runtime_Evidence {

	/** @var string Option key. */
	const OPTION_KEY = 'wpoptimizing_runtime_evidence';

	/** @var int Max values per DB bucket. */
	const MAX_BUCKET_ITEMS = 1200;

	/** @var int Max FS paths. */
	const MAX_FS_PATHS = 500;
	/** @var int Max owners stored per value. */
	const MAX_OWNERS_PER_VALUE = 12;
	/** @var int Hard cap for serialized option size. */
	const MAX_OPTION_BYTES = 524288;
	/** @var int Target size after compaction when cap is exceeded. */
	const TARGET_OPTION_BYTES = 393216;
	/** @var int Minimum values kept per DB bucket during compaction. */
	const MIN_BUCKET_ITEMS = 200;
	/** @var int Minimum FS paths kept during compaction. */
	const MIN_FS_PATHS = 100;

	/** @var array|null In-memory evidence cache. */
	protected static $cache = null;

	/** @var bool Whether cache has pending changes. */
	protected static $dirty = false;

	/** @var bool Prevent duplicate hook registration. */
	protected static $bootstrapped = false;

	/**
	 * Register runtime capture hooks.
	 *
	 * @return void
	 */
	public static function bootstrap() {
		if ( self::$bootstrapped ) {
			return;
		}
		self::$bootstrapped = true;

		add_action( 'registered_post_type', array( __CLASS__, 'hook_registered_post_type' ), 10, 2 );
		add_action( 'registered_taxonomy', array( __CLASS__, 'hook_registered_taxonomy' ), 10, 3 );

		add_action( 'added_option', array( __CLASS__, 'hook_added_option' ), 10, 2 );
		add_action( 'updated_option', array( __CLASS__, 'hook_updated_option' ), 10, 3 );
		add_action( 'deleted_option', array( __CLASS__, 'hook_deleted_option' ), 10, 1 );

		add_action( 'set_transient', array( __CLASS__, 'hook_set_transient' ), 10, 3 );
		add_action( 'deleted_transient', array( __CLASS__, 'hook_deleted_transient' ), 10, 1 );
		add_action( 'set_site_transient', array( __CLASS__, 'hook_set_site_transient' ), 10, 3 );
		add_action( 'deleted_site_transient', array( __CLASS__, 'hook_deleted_site_transient' ), 10, 1 );

		add_action( 'added_post_meta', array( __CLASS__, 'hook_added_post_meta' ), 10, 4 );
		add_action( 'updated_post_meta', array( __CLASS__, 'hook_updated_post_meta' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'hook_deleted_post_meta' ), 10, 4 );

		add_action( 'added_user_meta', array( __CLASS__, 'hook_added_user_meta' ), 10, 4 );
		add_action( 'updated_user_meta', array( __CLASS__, 'hook_updated_user_meta' ), 10, 4 );
		add_action( 'deleted_user_meta', array( __CLASS__, 'hook_deleted_user_meta' ), 10, 4 );

		add_action( 'added_term_meta', array( __CLASS__, 'hook_added_term_meta' ), 10, 4 );
		add_action( 'updated_term_meta', array( __CLASS__, 'hook_updated_term_meta' ), 10, 4 );
		add_action( 'deleted_term_meta', array( __CLASS__, 'hook_deleted_term_meta' ), 10, 4 );
		add_action( 'rest_api_init', array( __CLASS__, 'hook_rest_api_init' ), 999 );

		add_filter( 'woocommerce_log_directory', array( __CLASS__, 'hook_woocommerce_log_directory' ), 9999, 1 );
		add_filter( 'woocommerce_product_csv_importer_uploads_dir', array( __CLASS__, 'hook_woocommerce_imports_dir' ), 9999, 1 );

		add_action( 'shutdown', array( __CLASS__, 'flush' ), 1 );

		// Deterministic known WooCommerce uploads paths.
		if ( self::is_plugin_effectively_active( 'woocommerce/woocommerce.php' ) ) {
			self::record_fs_path( 'uploads/wc-logs', 'plugin', 'woocommerce' );
			self::record_fs_path( 'uploads/wc-imports', 'plugin', 'woocommerce' );
		}
	}

	/**
	 * Get normalized runtime evidence map.
	 *
	 * @return array{db:array,fs:array}
	 */
	public static function get_map() {
		$data = self::get_data();

		$db = array();
		foreach ( (array) ( $data['db'] ?? array() ) as $bucket => $items ) {
			$db[ $bucket ] = array();
			foreach ( (array) $items as $value => $meta ) {
				$origins = self::set_to_values( (array) ( $meta['origins'] ?? array() ) );
				$slugs   = self::set_to_values( (array) ( $meta['slugs'] ?? array() ) );
				if ( empty( $origins ) || empty( $slugs ) ) {
					continue;
				}
				$db[ $bucket ][ (string) $value ] = array(
					'origins' => implode( '|', $origins ),
					'slugs'   => implode( '|', $slugs ),
				);
			}
		}

		$fs = array();
		foreach ( (array) ( $data['fs'] ?? array() ) as $path => $meta ) {
			$origins = self::set_to_values( (array) ( $meta['origins'] ?? array() ) );
			$slugs   = self::set_to_values( (array) ( $meta['slugs'] ?? array() ) );
			if ( empty( $origins ) || empty( $slugs ) ) {
				continue;
			}
			$fs[ (string) $path ] = array(
				'origins' => implode( '|', $origins ),
				'slugs'   => implode( '|', $slugs ),
			);
		}

		return array(
			'db' => $db,
			'fs' => $fs,
		);
	}

	/**
	 * Persist pending evidence.
	 *
	 * @return void
	 */
	public static function flush() {
		if ( ! self::$dirty || null === self::$cache ) {
			return;
		}
		wpoptimizing_blob_put( 'runtime-evidence', self::OPTION_KEY, self::$cache );
		self::$dirty = false;
	}

	/**
	 * Erase all accumulated evidence (call before a fresh scan).
	 *
	 * @return void
	 */
	public static function reset() {
		self::$cache = null;
		self::$dirty = false;
		wpoptimizing_blob_delete( 'runtime-evidence', self::OPTION_KEY );
	}

	/**
	 * Record one DB value.
	 *
	 * @param string      $bucket DB bucket.
	 * @param string      $value  Value.
	 * @param string|null $origin Optional origin.
	 * @param string|null $slug   Optional slug.
	 * @return void
	 */
	public static function record_db_value( $bucket, $value, $origin = null, $slug = null ) {
		$bucket = strtolower( trim( (string) $bucket ) );
		$value  = trim( (string) $value );
		if ( '' === $bucket || '' === $value ) {
			return;
		}

		$ctx = self::resolve_context( $origin, $slug );
		if ( '' === $ctx['origin'] || '' === $ctx['slug'] ) {
			return;
		}

		$data = self::get_data();
		if ( ! isset( $data['db'][ $bucket ] ) || ! is_array( $data['db'][ $bucket ] ) ) {
			$data['db'][ $bucket ] = array();
		}
		if ( ! isset( $data['db'][ $bucket ][ $value ] ) || ! is_array( $data['db'][ $bucket ][ $value ] ) ) {
			$data['db'][ $bucket ][ $value ] = array(
				'origins' => array(),
				'slugs'   => array(),
			);
		}
		$changed = false;
		$changed = self::set_add( $data['db'][ $bucket ][ $value ]['origins'], $ctx['origin'], self::MAX_OWNERS_PER_VALUE ) || $changed;
		$changed = self::set_add( $data['db'][ $bucket ][ $value ]['slugs'], $ctx['slug'], self::MAX_OWNERS_PER_VALUE ) || $changed;
		if ( ! $changed ) {
			return;
		}

		if ( count( $data['db'][ $bucket ] ) > self::MAX_BUCKET_ITEMS ) {
			$data['db'][ $bucket ] = array_slice( $data['db'][ $bucket ], -1 * self::MAX_BUCKET_ITEMS, null, true );
		}

		self::$cache = $data;
		self::$dirty = true;
	}

	/**
	 * Record one filesystem path (relative to wp-content when possible).
	 *
	 * @param string      $path   Absolute or relative path.
	 * @param string|null $origin Optional origin.
	 * @param string|null $slug   Optional slug.
	 * @return void
	 */
	public static function record_fs_path( $path, $origin = null, $slug = null ) {
		$path = self::normalize_fs_path( $path );
		if ( '' === $path ) {
			return;
		}

		$ctx = self::resolve_context( $origin, $slug );
		if ( '' === $ctx['origin'] || '' === $ctx['slug'] ) {
			return;
		}

		$data = self::get_data();
		if ( ! isset( $data['fs'] ) || ! is_array( $data['fs'] ) ) {
			$data['fs'] = array();
		}
		if ( ! isset( $data['fs'][ $path ] ) || ! is_array( $data['fs'][ $path ] ) ) {
			$data['fs'][ $path ] = array(
				'origins' => array(),
				'slugs'   => array(),
			);
		}
		$changed = false;
		$changed = self::set_add( $data['fs'][ $path ]['origins'], $ctx['origin'], self::MAX_OWNERS_PER_VALUE ) || $changed;
		$changed = self::set_add( $data['fs'][ $path ]['slugs'], $ctx['slug'], self::MAX_OWNERS_PER_VALUE ) || $changed;
		if ( ! $changed ) {
			return;
		}

		if ( count( $data['fs'] ) > self::MAX_FS_PATHS ) {
			$data['fs'] = array_slice( $data['fs'], -1 * self::MAX_FS_PATHS, null, true );
		}

		self::$cache = $data;
		self::$dirty = true;
	}

	/** Hook handlers below */
	public static function hook_registered_post_type( $post_type ) {
		self::record_db_value( 'post_types', (string) $post_type );
	}

	public static function hook_registered_taxonomy( $taxonomy ) {
		self::record_db_value( 'taxonomies', (string) $taxonomy );
	}

	public static function hook_added_option( $option ) {
		self::record_db_value( 'options', (string) $option );
	}

	public static function hook_updated_option( $option ) {
		self::record_db_value( 'options', (string) $option );
	}

	public static function hook_deleted_option( $option ) {
		self::record_db_value( 'options', (string) $option );
	}

	public static function hook_set_transient( $transient ) {
		self::record_db_value( 'transients', (string) $transient );
	}

	public static function hook_deleted_transient( $transient ) {
		self::record_db_value( 'transients', (string) $transient );
	}

	public static function hook_set_site_transient( $transient ) {
		self::record_db_value( 'transients', (string) $transient );
	}

	public static function hook_deleted_site_transient( $transient ) {
		self::record_db_value( 'transients', (string) $transient );
	}

	public static function hook_rest_api_init() {
		$server = rest_get_server();
		if ( ! is_object( $server ) || ! method_exists( $server, 'get_routes' ) ) {
			return;
		}

		foreach ( (array) $server->get_routes() as $route => $handlers ) {
			if ( ! is_string( $route ) || '' === trim( $route ) ) {
				continue;
			}
			self::record_db_value( 'rest_routes', (string) $route );
		}
	}

	public static function hook_added_post_meta( $meta_id, $object_id, $meta_key ) {
		self::record_db_value( 'post_meta', (string) $meta_key );
	}

	public static function hook_updated_post_meta( $meta_id, $object_id, $meta_key ) {
		self::record_db_value( 'post_meta', (string) $meta_key );
	}

	public static function hook_deleted_post_meta( $meta_ids, $object_id, $meta_key ) {
		self::record_db_value( 'post_meta', (string) $meta_key );
	}

	public static function hook_added_user_meta( $meta_id, $object_id, $meta_key ) {
		self::record_db_value( 'user_meta', (string) $meta_key );
	}

	public static function hook_updated_user_meta( $meta_id, $object_id, $meta_key ) {
		self::record_db_value( 'user_meta', (string) $meta_key );
	}

	public static function hook_deleted_user_meta( $meta_ids, $object_id, $meta_key ) {
		self::record_db_value( 'user_meta', (string) $meta_key );
	}

	public static function hook_added_term_meta( $meta_id, $object_id, $meta_key ) {
		self::record_db_value( 'term_meta', (string) $meta_key );
	}

	public static function hook_updated_term_meta( $meta_id, $object_id, $meta_key ) {
		self::record_db_value( 'term_meta', (string) $meta_key );
	}

	public static function hook_deleted_term_meta( $meta_ids, $object_id, $meta_key ) {
		self::record_db_value( 'term_meta', (string) $meta_key );
	}

	public static function hook_woocommerce_log_directory( $dir ) {
		self::record_fs_path( (string) $dir, 'plugin', 'woocommerce' );
		return $dir;
	}

	public static function hook_woocommerce_imports_dir( $dir ) {
		self::record_fs_path( (string) $dir, 'plugin', 'woocommerce' );
		return $dir;
	}

	/**
	 * Load cache from option.
	 *
	 * @return array
	 */
	protected static function get_data() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$stored = (array) wpoptimizing_blob_get( 'runtime-evidence', self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		if ( ! isset( $stored['db'] ) || ! is_array( $stored['db'] ) ) {
			$stored['db'] = array();
		}
		if ( ! isset( $stored['fs'] ) || ! is_array( $stored['fs'] ) ) {
			$stored['fs'] = array();
		}
		$normalized  = self::normalize_storage_shape( $stored );
		$normalized  = self::enforce_storage_limits( $stored ) || $normalized;
		$normalized  = self::compact_storage_size( $stored ) || $normalized;
		self::$cache = $stored;
		if ( $normalized ) {
			self::$dirty = true;
		}
		return self::$cache;
	}

	/**
	 * Normalize stored data to set-shape arrays and prune oversized sets.
	 *
	 * @param array $stored Stored option value.
	 * @return bool True when modified.
	 */
	protected static function normalize_storage_shape( array &$stored ) {
		$changed = false;
		foreach ( array( 'db', 'fs' ) as $root_key ) {
			foreach ( (array) ( $stored[ $root_key ] ?? array() ) as $k => $entry ) {
				if ( 'db' === $root_key ) {
					if ( ! is_array( $entry ) ) {
						unset( $stored[ $root_key ][ $k ] );
						$changed = true;
						continue;
					}
					foreach ( (array) $entry as $value => $meta ) {
						$orig = self::values_to_set( (array) ( $meta['origins'] ?? array() ), self::MAX_OWNERS_PER_VALUE );
						$slgs = self::values_to_set( (array) ( $meta['slugs'] ?? array() ), self::MAX_OWNERS_PER_VALUE );
						if ( empty( $orig ) || empty( $slgs ) ) {
							if ( isset( $stored[ $root_key ][ $k ][ $value ] ) ) {
								unset( $stored[ $root_key ][ $k ][ $value ] );
								$changed = true;
							}
							continue;
						}
						$new = array(
							'origins' => $orig,
							'slugs'   => $slgs,
						);
						if ( ! isset( $stored[ $root_key ][ $k ][ $value ] ) || $stored[ $root_key ][ $k ][ $value ] !== $new ) {
							$stored[ $root_key ][ $k ][ $value ] = $new;
							$changed                             = true;
						}
					}
				} else {
					$orig = self::values_to_set( (array) ( $entry['origins'] ?? array() ), self::MAX_OWNERS_PER_VALUE );
					$slgs = self::values_to_set( (array) ( $entry['slugs'] ?? array() ), self::MAX_OWNERS_PER_VALUE );
					if ( empty( $orig ) || empty( $slgs ) ) {
						if ( isset( $stored[ $root_key ][ $k ] ) ) {
							unset( $stored[ $root_key ][ $k ] );
							$changed = true;
						}
						continue;
					}
					$new = array(
						'origins' => $orig,
						'slugs'   => $slgs,
					);
					if ( ! isset( $stored[ $root_key ][ $k ] ) || $stored[ $root_key ][ $k ] !== $new ) {
						$stored[ $root_key ][ $k ] = $new;
						$changed                   = true;
					}
				}
			}
		}
		return $changed;
	}

	/**
	 * Enforce in-memory storage limits for buckets and fs paths.
	 *
	 * @param array $stored Stored option value.
	 * @return bool True when modified.
	 */
	protected static function enforce_storage_limits( array &$stored ) {
		$changed = false;
		foreach ( (array) ( $stored['db'] ?? array() ) as $bucket => $items ) {
			if ( ! is_array( $items ) ) {
				unset( $stored['db'][ $bucket ] );
				$changed = true;
				continue;
			}
			if ( count( $items ) > self::MAX_BUCKET_ITEMS ) {
				$stored['db'][ $bucket ] = array_slice( $items, -1 * self::MAX_BUCKET_ITEMS, null, true );
				$changed                 = true;
			}
		}
		if ( count( (array) ( $stored['fs'] ?? array() ) ) > self::MAX_FS_PATHS ) {
			$stored['fs'] = array_slice( (array) $stored['fs'], -1 * self::MAX_FS_PATHS, null, true );
			$changed      = true;
		}
		return $changed;
	}

	/**
	 * Compact stored payload when serialized size is too large.
	 *
	 * @param array $stored Stored option value.
	 * @return bool True when modified.
	 */
	protected static function compact_storage_size( array &$stored ) {
		$serialized = serialize( $stored ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Size measurement only (strlen); never stored or unserialized.
		if ( strlen( $serialized ) <= self::MAX_OPTION_BYTES ) {
			return false;
		}
		$changed = false;
		$passes  = 0;
		while ( strlen( $serialized ) > self::TARGET_OPTION_BYTES && $passes < 8 ) { // phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- $serialized is re-serialized (shrunk) each pass, so its length must be re-measured.
			++$passes;
			$reduced = false;
			foreach ( (array) ( $stored['db'] ?? array() ) as $bucket => $items ) {
				$count = is_array( $items ) ? count( $items ) : 0;
				if ( $count <= self::MIN_BUCKET_ITEMS ) {
					continue;
				}
				$keep = max( self::MIN_BUCKET_ITEMS, (int) floor( $count * 0.75 ) );
				if ( $keep >= $count ) {
					continue;
				}
				$stored['db'][ $bucket ] = array_slice( $items, -1 * $keep, null, true );
				$reduced                 = true;
				$changed                 = true;
			}
			$fs_count = count( (array) ( $stored['fs'] ?? array() ) );
			if ( $fs_count > self::MIN_FS_PATHS ) {
				$keep = max( self::MIN_FS_PATHS, (int) floor( $fs_count * 0.75 ) );
				if ( $keep < $fs_count ) {
					$stored['fs'] = array_slice( (array) $stored['fs'], -1 * $keep, null, true );
					$reduced      = true;
					$changed      = true;
				}
			}
			if ( ! $reduced ) {
				break;
			}
			$serialized = serialize( $stored ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Size measurement only (strlen); never stored or unserialized.
		}
		return $changed;
	}

	/**
	 * Add one value to a set-like array.
	 *
	 * @param array  $set Set array (by reference).
	 * @param string $value Value.
	 * @param int    $max Max set size.
	 * @return bool True when changed.
	 */
	protected static function set_add( array &$set, $value, $max ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return false;
		}
		$normalized = self::values_to_set( $set, (int) $max );
		if ( isset( $normalized[ $value ] ) ) {
			$set = $normalized;
			return false;
		}
		if ( count( $normalized ) >= (int) $max ) {
			$set = $normalized;
			return false;
		}
		$normalized[ $value ] = true;
		$set                  = $normalized;
		return true;
	}

	/**
	 * Convert mixed list/set array to normalized set map.
	 *
	 * @param array $values Values.
	 * @param int   $max Max output size.
	 * @return array<string,bool>
	 */
	protected static function values_to_set( array $values, $max ) {
		$out = array();
		foreach ( $values as $k => $v ) {
			$value = null;
			if ( is_string( $k ) && true === $v ) {
				$value = $k;
			} elseif ( is_scalar( $v ) ) {
				$value = (string) $v;
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}
			$out[ $value ] = true;
			if ( count( $out ) >= (int) $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Convert a set-like array to sorted values.
	 *
	 * @param array $set Set/list array.
	 * @return string[]
	 */
	protected static function set_to_values( array $set ) {
		$normalized = self::values_to_set( $set, self::MAX_OWNERS_PER_VALUE );
		$values     = array_keys( $normalized );
		sort( $values, SORT_STRING );
		return $values;
	}

	/**
	 * Resolve owner context from explicit params or call stack.
	 *
	 * @param string|null $origin Origin.
	 * @param string|null $slug Slug.
	 * @return array{origin:string,slug:string}
	 */
	protected static function resolve_context( $origin = null, $slug = null ) {
		$origin = strtolower( trim( (string) $origin ) );
		$slug   = sanitize_key( (string) $slug );
		if ( '' !== $origin && '' !== $slug ) {
			return array(
				'origin' => $origin,
				'slug'   => $slug,
			);
		}
		return self::infer_owner_from_backtrace();
	}

	/**
	 * Infer plugin/theme owner from current stack.
	 *
	 * @return array{origin:string,slug:string}
	 */
	protected static function infer_owner_from_backtrace() {
		// Runtime attribution needs stack inspection to map writes to the originating extension.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$frames     = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 );
		$plugins    = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
		$mu_plugins = defined( 'WPMU_PLUGIN_DIR' ) ? wp_normalize_path( trailingslashit( WPMU_PLUGIN_DIR ) ) : '';
		$themes     = wp_normalize_path( trailingslashit( get_theme_root() ) );
		// Skip frames originating from this plugin's own directory.
		$self_dir = wp_normalize_path( trailingslashit( dirname( __DIR__ ) ) );

		foreach ( $frames as $frame ) {
			// A database replacement (Query Monitor's db.php links into its plugin folder) is
			// never the owner of what goes through it: see apg_wpopt_rtmap_is_wpdb_frame().
			$class = (string) ( $frame['class'] ?? '' );
			if ( '' !== $class && ( 'wpdb' === strtolower( $class ) || is_subclass_of( $class, 'wpdb' ) ) ) {
				continue;
			}
			$file = isset( $frame['file'] ) ? wp_normalize_path( (string) $frame['file'] ) : '';
			if ( '' === $file || isset( self::dropin_files()[ $file ] ) ) {
				continue;
			}
			if ( 0 === strpos( $file, $self_dir ) ) {
				continue;
			}
			if ( '' !== $plugins && 0 === strpos( $file, $plugins ) ) {
				$rel  = ltrim( substr( $file, strlen( $plugins ) ), '/' );
				$slug = explode( '/', $rel )[0] ?? '';
				$slug = sanitize_key( $slug );
				if ( '' !== $slug ) {
					return array(
						'origin' => 'plugin',
						'slug'   => $slug,
					);
				}
			}
			if ( '' !== $mu_plugins && 0 === strpos( $file, $mu_plugins ) ) {
				$rel  = ltrim( substr( $file, strlen( $mu_plugins ) ), '/' );
				$slug = explode( '/', $rel )[0] ?? '';
				// A single-file mu-plugin is named after its file, the way get_mu_plugins() and
				// wpoptimizing_mu_plugin_slugs() name it: sanitize_key() alone made `host.php`
				// into `hostphp`, an owner nothing else knows.
				$slug = sanitize_key( preg_replace( '/\.php$/i', '', $slug ) );
				if ( '' !== $slug ) {
					return array(
						'origin' => 'plugin',
						'slug'   => $slug,
					);
				}
			}
			if ( '' !== $themes && 0 === strpos( $file, $themes ) ) {
				$rel  = ltrim( substr( $file, strlen( $themes ) ), '/' );
				$slug = explode( '/', $rel )[0] ?? '';
				$slug = sanitize_key( $slug );
				if ( '' !== $slug ) {
					return array(
						'origin' => 'theme',
						'slug'   => $slug,
					);
				}
			}
		}
		return array(
			'origin' => '',
			'slug'   => '',
		);
	}

	/**
	 * The wp-content drop-ins, as a link and as its target, normalized.
	 *
	 * @return array<string,true>
	 */
	protected static function dropin_files() {
		static $files = null;
		if ( null === $files ) {
			$files = array();
			foreach ( array( 'db.php', 'object-cache.php', 'advanced-cache.php' ) as $dropin ) {
				$path = WP_CONTENT_DIR . '/' . $dropin;
				if ( file_exists( $path ) ) {
					$files[ wp_normalize_path( $path ) ] = true;
					$real                                = realpath( $path );
					if ( false !== $real ) {
						$files[ wp_normalize_path( $real ) ] = true;
					}
				}
			}
		}
		return $files;
	}

	/**
	 * Normalize FS path to relative wp-content path when possible.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected static function normalize_fs_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}
		$path         = wp_normalize_path( $path );
		$content_base = wp_normalize_path( trailingslashit( WP_CONTENT_DIR ) );
		if ( 0 === strpos( $path, $content_base ) ) {
			return ltrim( substr( $path, strlen( $content_base ) ), '/' );
		}
		if ( 0 === strpos( $path, 'uploads/' ) ) {
			return ltrim( $path, '/' );
		}
		return '';
	}

	/**
	 * Check if a plugin is currently active (single or network).
	 *
	 * @param string $plugin_file Plugin file.
	 * @return bool
	 */
	protected static function is_plugin_effectively_active( $plugin_file ) {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		return in_array( $plugin_file, $active, true ) || in_array( $plugin_file, $network, true );
	}
}
