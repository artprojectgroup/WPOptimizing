<?php
/**
 * Real filesystem scanner utilities.
 *
 * Responsibilities:
 *  - Provide a robust, reusable path normalizer that converts absolute/alias
 *    paths into a wp-content–relative path (or '' if outside).
 *  - Optionally crawl wp-content to emit a lightweight manifest CSV that can
 *    be used for debugging or QA (disabled cost-wise by default).
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WPOPT_FS_Scanner
 */
class WPOPT_FS_Scanner {

	/** @var string Absolute wp-content path (normalized). */
	protected $content_path;

	/** @var bool Whether to write a manifest of existing paths (debug/QA). */
	protected $write_manifest = false;

	/** @var string Manifest CSV path (only used if $write_manifest = true). */
	protected $manifest_csv;

	/**
	 * Constructor.
	 *
	 * @param bool $write_manifest Set true to emit wpoptimizing-fs-manifest.csv for QA.
	 */
	public function __construct( $write_manifest = false ) {
		$this->content_path   = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
		$this->write_manifest = (bool) $write_manifest;
		$this->manifest_csv   = WPOPT_DIR . '/wpoptimizing-fs-manifest.csv';
	}

	/**
	 * Write the QA manifest, if this instance was asked for one.
	 *
	 * NOT PART OF THE SCAN. The scan pipeline used to call this with the default constructor,
	 * which means $write_manifest was false and run() returned immediately — a stage that
	 * announced "Scanning filesystem…" and did nothing. That stage is gone. The real
	 * filesystem work belongs to WPOPT_FS_Orphans.
	 *
	 * What this class is actually for is the path normaliser below, which the code scanner
	 * uses heavily. The manifest stays available for triage — `( new WPOPT_FS_Scanner( true )
	 * )->run()` writes every wp-content path with its owner and status, which is how you tell
	 * a false positive from a real orphan — but nothing reads it automatically, so nothing
	 * pays for it.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! $this->write_manifest ) {
			return;
		}

		// Recreate file with header (now enriched for QA).
		$fh = fopen( $this->manifest_csv, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return;
		}
		// Enriched header to validate owner/status logic visually.
		fputcsv( $fh, array( 'type', 'relative_path', 'project_status', 'project_origins', 'project_slugs' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv

		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $this->content_path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		$seen = array();
		foreach ( $it as $fileinfo ) {
			$abs = wp_normalize_path( $fileinfo->getPathname() );
			$rel = self::normalize_to_wpcontent( $abs );
			if ( '' === $rel ) {
				continue;
			}

			// Skip noisy/native areas that we do not want to list.
			if ( self::should_skip_rel( $rel ) ) {
				continue;
			}

			// Hard whitelist (this plugin folder, etc.) – never report.
			if ( $this->fs_is_whitelisted( $rel ) ) {
				continue;
			}

			if ( isset( $seen[ $rel ] ) ) {
				continue;
			}
			$seen[ $rel ] = true;

			$type = $fileinfo->isDir() ? 'dir' : 'file';

			// Derive owner/status (plugin/theme + active/inactive/none).
			$meta = $this->fs_owner_status_for( $rel );
			// $meta = array( 'origins' => 'plugin|theme|', 'slugs' => '<slug or empty>', 'project_status' => 'active|inactive|none' )

			fputcsv(
				$fh,
				array(
					$type,
					$rel,
					$meta['project_status'],
					$meta['origins'],
					$meta['slugs'],
				)
			); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}

	/**
	 * PUBLIC HELPER (static): Normalize a path into a wp-content relative path.
	 *
	 * Accepts:
	 *  - Absolute paths (with different directory separators).
	 *  - Paths that reference known WP roots (plugins, themes, uploads).
	 *  - Relative strings: resolved against $base_file when given; otherwise kept only when
	 *    they start at one of wp-content's own roots (plugins/, uploads/…).
	 *
	 * Returns '' when the path is outside wp-content or cannot be trusted.
	 *
	 * @param string $path      Raw path (possibly absolute or mixed).
	 * @param string $base_file Source file a relative literal is resolved against ('' = none).
	 * @return string Relative path to wp-content or ''.
	 */
	public static function normalize_to_wpcontent( $path, $base_file = '' ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$raw  = str_replace( '\\', '/', $path ); // Before canonicalizing: its leading ../ still mean something.
		$path = self::canonicalize_path( $path );

		// Known bases (normalized, no trailing slash). Cached to avoid repeated FS calls.
		static $content      = null;
		static $plugins      = null;
		static $themes       = null;
		static $uploads_base = null;
		if ( null === $content ) {
			$content      = wp_normalize_path( untrailingslashit( WP_CONTENT_DIR ) );
			$plugins      = wp_normalize_path( untrailingslashit( WP_PLUGIN_DIR ) );
			$themes       = wp_normalize_path( untrailingslashit( get_theme_root() ) );
			$uploads      = wp_get_upload_dir();
			$uploads_base = ! empty( $uploads['basedir'] ) ? wp_normalize_path( untrailingslashit( $uploads['basedir'] ) ) : '';
		}

		// Direct children of wp-content.
		if ( 0 === strpos( $path, $content . '/' ) ) {
			$rel = substr( $path, strlen( $content ) + 1 );
			return ltrim( $rel, '/' );
		}

		// Map plugins → "plugins/..."
		if ( 0 === strpos( $path, $plugins . '/' ) ) {
			$rel = substr( $path, strlen( $plugins ) + 1 );
			return 'plugins/' . ltrim( $rel, '/' );
		}

		// Map themes → "themes/..."
		if ( 0 === strpos( $path, $themes . '/' ) ) {
			$rel = substr( $path, strlen( $themes ) + 1 );
			return 'themes/' . ltrim( $rel, '/' );
		}

		// Map uploads → "uploads/..."
		if ( $uploads_base && 0 === strpos( $path, $uploads_base . '/' ) ) {
			$rel = substr( $path, strlen( $uploads_base ) + 1 );
			return 'uploads/' . ltrim( $rel, '/' );
		}

		/*
		 * A RELATIVE LITERAL IS RELATIVE TO SOMETHING, and wp-content is rarely it. This kept
		 * any relative string as wp-content-relative, so `mkdir( 'database' )` or
		 * `file_put_contents( 'cache/x.txt' )` inside a plugin was recorded as wp-content/database
		 * and wp-content/cache — and that evidence then named shared top-level folders as the
		 * plugin's. With the source file known, the literal is resolved against it; without it,
		 * only a path that starts at one of wp-content's own roots is kept. The colon check
		 * rejects Windows drive paths (e.g. "C:/…").
		 */
		if ( 0 !== strpos( $path, '/' ) && false === strpos( $path, ':' ) ) {
			if ( '' !== (string) $base_file ) {
				return self::normalize_to_wpcontent( wp_normalize_path( dirname( (string) $base_file ) ) . '/' . $raw );
			}
			$maybe = str_replace( '\\', '/', ltrim( $path, './\\' ) );
			$first = (string) strtok( $maybe, '/' );
			if ( false === strpos( $maybe, '..' ) && in_array( $first, array( 'plugins', 'themes', 'uploads', 'mu-plugins', 'languages', 'upgrade' ), true ) ) {
				return $maybe;
			}
		}

		// Unknown/outside.
		return '';
	}

	/**
	 * Canonicalize path: strip query fragments, normalize separators, collapse "..".
	 *
	 * @param string $path Raw path.
	 * @return string Canonical absolute-or-relative path.
	 */
	public static function canonicalize_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		// Remove URL query/hash fragments if someone passed an URL-ish string.
		$path = preg_replace( '/[?#].*$/', '', $path );
		$path = str_replace( '\\', '/', $path );

		// Collapse "//"
		$path = preg_replace( '#/+#', '/', $path );

		// Resolve "../" segments (best-effort for strings).
		$parts = array();
		foreach ( explode( '/', $path ) as $p ) {
			if ( '' === $p || '.' === $p ) {
				continue;
			}
			if ( '..' === $p ) {
				array_pop( $parts );
			} else {
				$parts[] = $p;
			}
		}
		$out = implode( '/', $parts );

		// Re-add leading slash if the input had one (heuristic).
		if ( 0 === strpos( $path, '/' ) ) {
			$out = '/' . $out;
		}

		return $out;
	}

	/**
	 * Decide whether to skip a wp-content–relative path in the optional manifest.
	 *
	 * Keep this conservative: we only skip obvious noise / native areas to
	 * keep the manifest useful but not gigantic.
	 *
	 * @param string $rel Relative path (e.g. "uploads/foo", "plugins/x/file.php").
	 * @return bool True if the path should be ignored.
	 */
	protected static function should_skip_rel( $rel ) {
		$rel   = ltrim( $rel, '/' );
		$rel_l = ltrim( strtolower( $rel ), '/' );

		// Skip this plugin own directory under wp-content/plugins/
		if ( 0 === strpos( $rel_l, 'plugins/wpoptimizing/' ) ) {
			return true;
		}

		// Skip well-known caches, logs and VCS/vendor folders at the root.
		$first      = strtolower( strtok( $rel, '/' ) );
		$skip_roots = array(
			'cache',
			'wpo-cache',
			'w3tc-cache',
			'backup',
			'backups',
			'backwpup-logs',
			'logs',
			'wflogs',
			'.git',
			'.svn',
			'.hg',
			'node_modules',
			'vendor',
		);
		if ( in_array( $first, $skip_roots, true ) ) {
			return true;
		}

		// Skip language packs compiled by WP core.
		if ( 0 === strpos( $rel, 'languages/' ) ) {
			return true;
		}

		// Nothing else to skip.
		return false;
	}

	/**
	 * Return whether the relative path should be whitelisted (never reported).
	 *
	 * @param string $rel Relative path under wp-content.
	 * @return bool
	 */
	protected function fs_is_whitelisted( $rel ) {
		// Normalize to forward slashes.
		$rel = wp_normalize_path( ltrim( (string) $rel, '/' ) );

		// Base whitelist (own plugin dir, cache dirs, etc.). Filterable.
		$list = array(
			'plugins/wpoptimizing',
			'plugins/wpoptimizing/', // defensive
		);
		$list = apply_filters( 'wpoptimizing_fs_whitelist', $list );

		foreach ( $list as $prefix ) {
			$prefix = wp_normalize_path( rtrim( (string) $prefix, '/' ) ) . '/';
			if ( strpos( $rel . '/', $prefix ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Derive project ownership and status for a wp-content–relative path.
	 *
	 * Rules:
	 * - mu-plugins: always treated as active (WordPress loads them unconditionally).
	 * - plugins: active if its slug appears in active_plugins or active_sitewide_plugins.
	 * - themes: active if matches current stylesheet or template.
	 * - anything else under wp-content: owner unknown -> status "none".
	 *
	 * @param string $rel Relative path under wp-content (normalized with forward slashes).
	 * @return array{origins:string,slugs:string,project_status:string}
	 */
	protected function fs_owner_status_for( $rel ) {
		// Normalize defensively to avoid surprises with leading slashes or backslashes.
		$rel = wp_normalize_path( ltrim( (string) $rel, '/' ) );

		// 1) Must‑use plugins: mu-plugins/<slug>/... or mu-plugins/<file>.php
		if ( strpos( $rel, 'mu-plugins/' ) === 0 ) {
			$rest = substr( $rel, strlen( 'mu-plugins/' ) );
			$slug = strtok( $rest, '/' );
			$slug = ( false === $slug ) ? '' : $slug;

			// MU plugins are always loaded by WordPress.
			return array(
				'origins'        => 'mu-plugin',
				'slugs'          => $slug,
				'project_status' => 'active',
			);
		}

		// 2) Regular plugins: plugins/<slug>/...
		if ( strpos( $rel, 'plugins/' ) === 0 ) {
			$rest = substr( $rel, strlen( 'plugins/' ) );
			$slug = strtok( $rest, '/' );
			$slug = ( false === $slug ) ? '' : $slug;

			// Determine active status via core options.
			require_once ABSPATH . 'wp-admin/includes/plugin.php';

			$active_files = (array) get_option( 'active_plugins', array() );

			// Multisite: merge sitewide active plugins (keys are plugin files).
			if ( is_multisite() ) {
				$sitewide     = (array) get_site_option( 'active_sitewide_plugins', array() );
				$active_files = array_merge( $active_files, array_keys( $sitewide ) );
			}

			$is_active = false;
			foreach ( $active_files as $plugin_file ) {
				$pf_slug = ( false !== strpos( $plugin_file, '/' ) )
					? explode( '/', $plugin_file, 2 )[0]
					: basename( $plugin_file, '.php' );
				if ( $pf_slug === $slug ) {
					$is_active = true;
					break;
				}
			}

			return array(
				'origins'        => 'plugin',
				'slugs'          => $slug,
				'project_status' => $is_active ? 'active' : 'inactive',
			);
		}

		// 3) Themes: themes/<slug>/...
		if ( strpos( $rel, 'themes/' ) === 0 ) {
			$rest = substr( $rel, strlen( 'themes/' ) );
			$slug = strtok( $rest, '/' );
			$slug = ( false === $slug ) ? '' : $slug;

			// Current theme (child) and template (parent) are both "active".
			$active_theme_slugs = array( get_stylesheet(), get_template() );
			$is_active          = in_array( $slug, $active_theme_slugs, true );

			return array(
				'origins'        => 'theme',
				'slugs'          => $slug,
				'project_status' => $is_active ? 'active' : 'inactive',
			);
		}

		// 4) Default: unknown owner under wp-content → neutral "none".
		return array(
			'origins'        => '',
			'slugs'          => '',
			'project_status' => 'none',
		);
	}
}
