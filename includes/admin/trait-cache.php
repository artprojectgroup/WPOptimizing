<?php
/**
 * "Cache" tab: install and manage a conservative **persistent object cache**
 * (no full-page HTML caching). Uses the first available system backend
 * (Redis → Memcached; APCu only when chosen) and falls back to a self-contained SQLite store.
 *
 * The actual cache lives in the drop-in `wp-content/object-cache.php` (template
 * at includes/dropins/object-cache.php). This trait only detects status and
 * installs/uninstalls/flushes it. If another plugin already manages the object
 * cache, we DETECT and DEFER — we never overwrite a foreign drop-in.
 *
 * Composed into WPOPT_Admin_UI.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Cache_Trait {

	/**
	 * Default + sanitized cache settings.
	 *
	 * @return array
	 */
	protected function get_cache_settings() {
		return wpoptimizing_cache_settings();
	}

	/**
	 * Whether the current user may install/uninstall the network-wide drop-in.
	 * On multisite the drop-in affects the whole network, so require a super admin.
	 *
	 * @return bool
	 */
	protected function can_manage_dropin() {
		if ( is_multisite() ) {
			return current_user_can( 'manage_network_options' );
		}
		return current_user_can( $this->cap );
	}

	/**
	 * Read the first bytes of a drop-in and tell whether it is ours.
	 *
	 * @param string $path   File path.
	 * @param string $marker Signature to look for.
	 * @return bool
	 */
	protected function dropin_is_ours( $path, $marker = 'WPOPT_OBJECT_CACHE' ) {
		if ( ! is_readable( $path ) ) {
			return false;
		}
		$head = (string) file_get_contents( $path, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header, not a remote/uploaded file.
		return false !== strpos( $head, $marker );
	}

	/**
	 * Take a foreign drop-in's slot: only when asked, only when wpoptimizing_dropin_can_replace()
	 * allows it, and only once the original is safely kept. Says why when it does not.
	 *
	 * @param string $kind    'object' | 'db'.
	 * @param bool   $replace Whether the user asked to replace it.
	 * @return bool True when the slot can be written.
	 */
	protected function take_dropin_slot( $kind, $replace ) {
		$owner = function_exists( 'wpoptimizing_dropin_owner' ) ? wpoptimizing_dropin_owner( wpoptimizing_dropin_path( $kind ) ) : array( 'name' => '' );
		$name  = '' !== (string) $owner['name'] ? (string) $owner['name'] : __( 'another plugin', 'wpoptimizing' );
		$file  = 'db' === $kind ? 'db.php' : 'object-cache.php';
		if ( ! $replace || ! function_exists( 'wpoptimizing_dropin_can_replace' ) ) {
			$this->cache_flash(
				sprintf(
					/* translators: 1: file name, 2: plugin name. */
					__( 'wp-content/%1$s belongs to %2$s. WPOptimizing will not overwrite it unless you choose to replace it.', 'wpoptimizing' ),
					$file,
					$name
				),
				'warning'
			);
			return false;
		}
		$can = wpoptimizing_dropin_can_replace( $kind, $owner );
		if ( ! $can['ok'] ) {
			$this->cache_flash( $this->dropin_refusal_text( $kind, $can['reason'], $name ), 'warning' );
			return false;
		}
		if ( ! wpoptimizing_dropin_backup( $kind ) ) {
			$this->cache_flash(
				/* translators: %s: file name. */
				sprintf( __( 'wp-content/%s could not be copied aside, so it was left in place.', 'wpoptimizing' ), $file ),
				'error'
			);
			return false;
		}
		return true;
	}

	/**
	 * Why a foreign drop-in cannot be replaced, in words.
	 *
	 * @param string $kind   Kind.
	 * @param string $reason wpoptimizing_dropin_can_replace() reason.
	 * @param string $name   Owner name.
	 * @return string
	 */
	protected function dropin_refusal_text( $kind, $reason, $name ) {
		switch ( $reason ) {
			case 'active_cache':
				/* translators: %s: plugin name(s). */
				return sprintf( __( '%s is active and serves its own page cache. Use its cache, or deactivate it first: two page caches on the same pages fight each other.', 'wpoptimizing' ), $name );
			case 'owner_active':
				/* translators: %s: plugin name. */
				return sprintf( __( '%s is active and provides the object cache. Use it, or deactivate it first if you prefer WPOptimizing’s.', 'wpoptimizing' ), $name );
			case 'routes_db':
				/* translators: %s: plugin name. */
				return sprintf( __( 'The db.php of %s routes the connection to the database itself; replacing it could leave the site without a database. WPOptimizing will not touch it.', 'wpoptimizing' ), $name );
		}
		return '';
	}

	/**
	 * Append "the original was put back" to a flash message when a disable restored one.
	 *
	 * @param string $message  Message.
	 * @param string $restored Owner name returned by wpoptimizing_dropin_restore(), or ''.
	 * @return string
	 */
	protected function with_restored( $message, $restored ) {
		if ( '' === (string) $restored ) {
			return $message;
		}
		/* translators: 1: previous message, 2: plugin name. */
		return sprintf( __( '%1$s The drop-in of %2$s that it had replaced is back in place.', 'wpoptimizing' ), $message, $restored );
	}

	/**
	 * "Managed by <owner>" for a status table, or the generic wording when the owner is unknown.
	 *
	 * @param string $kind Kind.
	 * @return string
	 */
	protected function managed_by_label( $kind ) {
		$name = function_exists( 'wpoptimizing_dropin_owner' ) ? (string) wpoptimizing_dropin_owner( wpoptimizing_dropin_path( $kind ) )['name'] : '';
		/* translators: %s: plugin name. */
		return '' !== $name ? sprintf( __( 'Managed by %s', 'wpoptimizing' ), $name ) : __( 'Managed by another plugin', 'wpoptimizing' );
	}

	/**
	 * The notice for a slot taken by another plugin: who owns it, whether it is still active, and
	 * either a "Replace" button (with what it costs) or why it cannot be offered.
	 *
	 * @param string $kind          'object' | 'db' | 'page'.
	 * @param string $field         Submit name of the replace action.
	 * @param array  $active_caches Active third-party page caches (page kind only).
	 * @return void
	 */
	protected function render_foreign_dropin( $kind, $field, array $active_caches = array() ) {
		$path  = wpoptimizing_dropin_path( $kind );
		$file  = basename( $path );
		$owner = wpoptimizing_dropin_owner( $path );
		$name  = '' !== $owner['name'] ? $owner['name'] : ( ! empty( $active_caches ) ? implode( ', ', $active_caches ) : '' );
		if ( '' === $name ) {
			/* translators: %s: file name. */
			$line = sprintf( __( 'wp-content/%s belongs to another plugin, which could not be identified.', 'wpoptimizing' ), $file );
		} elseif ( true === $owner['active'] || ! empty( $active_caches ) ) {
			/* translators: 1: file name, 2: plugin name. */
			$line = sprintf( __( 'wp-content/%1$s belongs to %2$s, which is active.', 'wpoptimizing' ), $file, $name );
		} elseif ( false === $owner['active'] ) {
			/* translators: 1: file name, 2: plugin name. */
			$line = sprintf( __( 'wp-content/%1$s belongs to %2$s, which is no longer active: it is a leftover.', 'wpoptimizing' ), $file, $name );
		} else {
			/* translators: 1: file name, 2: plugin name. */
			$line = sprintf( __( 'wp-content/%1$s belongs to %2$s.', 'wpoptimizing' ), $file, $name );
		}
		$can = wpoptimizing_dropin_can_replace( $kind, $owner, $active_caches );
		echo '<div class="notice notice-warning inline wpopt-prose"><p>' . esc_html( $line ) . '</p>';
		if ( ! $can['ok'] ) {
			echo '<p>' . esc_html( $this->dropin_refusal_text( $kind, $can['reason'], '' !== $name ? $name : $file ) ) . '</p>';
			// The way out of «use its cache or deactivate it first»: bring its settings over before
			// switching (seen on Mundotoro with WP Rocket, 07/11/2026).
			if ( 'active_cache' === $can['reason'] && function_exists( 'wpoptimizing_importer_detected' ) && array() !== wpoptimizing_importer_detected() ) {
				$imp_url = add_query_arg( array( 'page' => 'wpoptimizing-settings' ), admin_url( 'admin.php' ) ) . '#wpopt-set-importer';
				echo '<p><a href="' . esc_url( $imp_url ) . '">' . esc_html__( 'Import from another plugin', 'wpoptimizing' ) . ' →</a></p>';
			}
			echo '</div>';
			return;
		}
		$cost = sprintf(
			/* translators: 1: file name, 2: plugin name. */
			__( 'WPOptimizing can take its place: the current %1$s is kept and put back when you disable this. Meanwhile, whatever %2$s does with that file stops.', 'wpoptimizing' ),
			$file,
			'' !== $name ? $name : __( 'the other plugin', 'wpoptimizing' )
		);
		echo '<p>' . esc_html( $cost ) . '</p>';
		if ( $this->can_manage_dropin() ) {
			$tab_url = add_query_arg(
				array(
					'page' => 'wpoptimizing',
					'tab'  => 'cache',
				),
				admin_url( 'admin.php' )
			);
			echo '<form method="post" action="' . esc_url( $tab_url ) . '"><p>';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( wp_create_nonce( $this->nonce_action ) ) . '" />';
			echo '<input type="hidden" name="tab" value="cache" />';
			echo '<button type="submit" class="button" name="' . esc_attr( $field ) . '" value="1" onclick="return confirm(' . esc_attr( wp_json_encode( $cost ) ) . ');">'
				. esc_html__( 'Replace with WPOptimizing’s', 'wpoptimizing' ) . '</button>';
			echo '</p></form>';
		}
		echo '</div>';
	}

	/**
	 * Read and aggregate the durable analytics file written by the drop-ins.
	 * Returns totals over the retained window plus the per-day series and the
	 * rolling list of most-duplicated queries.
	 *
	 * @return array{has:bool,hits:int,misses:int,requests:int,queries:int,dups:int,ratio:int,days:array,dupes:array}
	 */
	protected function get_cache_analytics() {
		$out  = array(
			'has'        => false,
			'hits'       => 0,
			'misses'     => 0,
			'requests'   => 0,
			'queries'    => 0,
			'dups'       => 0,
			'ratio'      => 0,
			'slow'       => 0,
			'ms'         => 0,
			'types'      => array(
				'sel' => 0,
				'ins' => 0,
				'upd' => 0,
				'del' => 0,
			),
			'components' => array(),
			'days'       => array(),
			'dupes'      => array(),
		);
		$file = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR . '/wpopt-cache-stats.json' : WP_CONTENT_DIR . '/cache/wpopt-cache-stats.json';
		if ( ! is_readable( $file ) ) {
			return $out;
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Local analytics file.
		if ( ! is_array( $data ) ) {
			return $out;
		}
		$daily = ( isset( $data['daily'] ) && is_array( $data['daily'] ) ) ? $data['daily'] : array();
		ksort( $daily );
		foreach ( $daily as $day => $b ) {
			$h                = (int) ( $b['h'] ?? 0 );
			$m                = (int) ( $b['m'] ?? 0 );
			$out['hits']     += $h;
			$out['misses']   += $m;
			$out['requests'] += (int) ( $b['req'] ?? 0 );
			$out['queries']  += (int) ( $b['q'] ?? 0 );
			$out['dups']     += (int) ( $b['dup'] ?? 0 );
			$out['slow']     += (int) ( $b['slow'] ?? 0 );
			$out['ms']       += (int) ( $b['ms'] ?? 0 );
			foreach ( array( 'sel', 'ins', 'upd', 'del' ) as $tk ) {
				$out['types'][ $tk ] += (int) ( $b[ $tk ] ?? 0 );
			}
			$out['days'][] = array(
				'day' => (string) $day,
				'h'   => $h,
				'm'   => $m,
				'q'   => (int) ( $b['q'] ?? 0 ),
				'dup' => (int) ( $b['dup'] ?? 0 ),
			);
		}
		$total        = $out['hits'] + $out['misses'];
		$out['ratio'] = $total > 0 ? (int) round( $out['hits'] / $total * 100 ) : 0;
		if ( isset( $data['dups'] ) && is_array( $data['dups'] ) ) {
			foreach ( $data['dups'] as $d ) {
				if ( isset( $d['sql'], $d['count'] ) ) {
					$out['dupes'][] = array(
						'sql'   => (string) $d['sql'],
						'count' => (int) $d['count'],
						'comp'  => (string) ( $d['comp'] ?? '' ),
					);
				}
			}
		}
		if ( isset( $data['dupe_components'] ) && is_array( $data['dupe_components'] ) ) {
			foreach ( $data['dupe_components'] as $name => $n ) {
				$out['components'][ (string) $name ] = (int) $n;
			}
			arsort( $out['components'] );
		}
		$out['has'] = ( $total > 0 || $out['queries'] > 0 || ! empty( $out['dupes'] ) );
		return $out;
	}

	/**
	 * Snapshot of the object-cache situation for the UI.
	 *
	 * @return array
	 */
	protected function get_cache_status() {
		static $status = null;
		if ( null !== $status ) {
			return $status;
		}

		$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';

		$dropin_exists = file_exists( $dest );
		$dropin_ours   = $dropin_exists && $this->dropin_is_ours( $dest );

		// Version of the installed drop-in (parsed from its signature line) vs the
		// version the plugin currently ships, so we can offer an update.
		$installed_ver = '';
		$current_ver   = defined( 'WPOPT_DROPIN_VERSION' ) ? (string) WPOPT_DROPIN_VERSION : '1';
		if ( $dropin_ours ) {
			$head = (string) file_get_contents( $dest, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header.
			if ( preg_match( '/WPOPT_OBJECT_CACHE\s+v(\d+)/', $head, $m ) ) {
				$installed_ver = $m[1];
			}
		}

		// Backends physically available on this server.
		$backends = array(
			'redis'     => class_exists( 'Redis' ),
			'memcached' => class_exists( 'Memcached' ),
			'apcu'      => function_exists( 'apcu_enabled' ) && apcu_enabled(),
			'sqlite'    => ( class_exists( '\PDO' ) && in_array( 'sqlite', \PDO::getAvailableDrivers(), true ) ) || class_exists( '\SQLite3' ), // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite object-cache backend; not the WP database.
		);

		// Active backend + live (this-request) stats when OUR drop-in is loaded.
		$active_backend = '';
		$stats          = array();
		if ( $dropin_ours && isset( $GLOBALS['wp_object_cache'] ) && is_object( $GLOBALS['wp_object_cache'] ) ) {
			$oc = $GLOBALS['wp_object_cache'];
			if ( property_exists( $oc, 'backend_name' ) ) {
				$active_backend = (string) $oc->backend_name;
			}
			if ( method_exists( $oc, 'stats' ) ) {
				$stats = (array) $oc->stats();
			}
		}

		// Query-deduplication db.php drop-in status.
		$db_dest   = defined( 'WPOPT_DB_DROPIN_DEST' ) ? WPOPT_DB_DROPIN_DEST : WP_CONTENT_DIR . '/db.php';
		$db_exists = file_exists( $db_dest );
		$db_ours   = $db_exists && $this->dropin_is_ours( $db_dest, 'WPOPT_DB_DROPIN' );
		$db_active = $db_ours && isset( $GLOBALS['wpdb'] ) && is_a( $GLOBALS['wpdb'], 'WPOpt_DB' );

		$db_ver         = '';
		$db_current_ver = defined( 'WPOPT_DB_DROPIN_VERSION' ) ? (string) WPOPT_DB_DROPIN_VERSION : '1';
		if ( $db_ours ) {
			$dbhead = (string) file_get_contents( $db_dest, false, null, 0, 1024 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- Reading our own small drop-in header.
			if ( preg_match( '/WPOPT_DB_DROPIN\s+v(\d+)/', $dbhead, $dm ) ) {
				$db_ver = $dm[1];
			}
		}

		$status = array(
			'using_ext'       => function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache(),
			'dropin_exists'   => $dropin_exists,
			'dropin_ours'     => $dropin_ours,
			'dropin_foreign'  => $dropin_exists && ! $dropin_ours,
			'backends'        => $backends,
			'active_backend'  => $active_backend,
			'stats'           => $stats,
			'installed_ver'   => $installed_ver,
			'current_ver'     => $current_ver,
			'needs_update'    => ( $dropin_ours && '' !== $installed_ver && $installed_ver !== $current_ver ),
			'db_exists'       => $db_exists,
			'db_ours'         => $db_ours,
			'db_active'       => $db_active,
			'db_foreign'      => $db_exists && ! $db_ours,
			'db_needs_update' => ( $db_ours && '' !== $db_ver && $db_ver !== $db_current_ver ),
			'can_manage'      => $this->can_manage_dropin(),
		);
		return $status;
	}

	/**
	 * Compose and write the drop-in sidecar config from the saved settings.
	 *
	 * @return bool
	 */
	protected function write_cache_config_sidecar() {
		return wpoptimizing_write_cache_config_sidecar();
	}

	/**
	 * Install the object-cache drop-in (opt-in, from the Cache tab).
	 *
	 * @return void
	 */
	protected function install_object_cache_dropin( $replace = false ) {
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'You do not have permission to manage the object cache.', 'wpoptimizing' ), 'error' );
			return;
		}
		$src  = defined( 'WPOPT_DROPIN_SRC' ) ? WPOPT_DROPIN_SRC : dirname( __DIR__ ) . '/dropins/object-cache.php';
		$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';

		// Never overwrite a drop-in managed by another plugin — unless asked to take its slot and
		// that is safe, and then only after the original is kept for the way back.
		if ( file_exists( $dest ) && ! $this->dropin_is_ours( $dest ) && ! $this->take_dropin_slot( 'object', $replace ) ) {
			return;
		}
		if ( ! is_readable( $src ) ) {
			$this->cache_flash( __( 'The object cache template is missing from the plugin.', 'wpoptimizing' ), 'error' );
			return;
		}

		// Write config first so the drop-in picks the right backend on first load.
		$this->write_cache_config_sidecar();

		// Copy into wp-content/object-cache.php via a temp file and rename(). A plain copy is
		// not atomic: a request arriving mid-write loads a TRUNCATED drop-in, and a truncated
		// drop-in is a fatal during bootstrap — before any of this plugin's recovery paths
		// exist. The auto-refresh routines already used the atomic helper; the first install,
		// where the file does not exist yet and the window is widest, did not.
		$ok = function_exists( 'wpoptimizing_atomic_install_file' )
			? wpoptimizing_atomic_install_file( $src, $dest )
			: false;
		if ( ! $ok ) {
			$this->cache_flash( __( 'Could not write wp-content/object-cache.php. Check filesystem permissions.', 'wpoptimizing' ), 'error' );
			return;
		}
		update_option( 'wpoptimizing_cache_dropin', 1, false );
		// The persistent backend only becomes active on the NEXT request. Until it is
		// primed it can serve a stale/cold `wp_user_roles` (missing manage_options and
		// our own cap), which makes WordPress deny access to the admin page right after
		// enabling the cache from the wizard or the Cache tab. Arm a one-shot flush that
		// runs early on that next request so roles/options are re-read fresh from the DB.
		update_option( 'wpoptimizing_cache_recache_once', 1, true );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'cache_dropin_install', array( 'backend' => $this->get_cache_settings()['backend'] ) );
		}
		$this->cache_flash( __( 'Persistent object cache enabled.', 'wpoptimizing' ), 'updated' );
	}

	/**
	 * Remove our drop-in (only ours) and its sidecar/store.
	 *
	 * @return void
	 */
	protected function uninstall_object_cache_dropin() {
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'You do not have permission to manage the object cache.', 'wpoptimizing' ), 'error' );
			return;
		}
		$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';

		if ( file_exists( $dest ) ) {
			if ( ! $this->dropin_is_ours( $dest ) ) {
				$this->cache_flash( __( 'The active object cache drop-in is not managed by WPOptimizing; it was left untouched.', 'wpoptimizing' ), 'warning' );
				return;
			}
			// Flush before removing so stale data does not linger in the backend.
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			wp_delete_file( $dest );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		// Drop the SQLite store + sidecar (best-effort).
		$cfg = defined( 'WPOPT_CACHE_CONFIG' ) ? WPOPT_CACHE_CONFIG : WP_CONTENT_DIR . '/cache/wpopt-cache-config.php';
		if ( file_exists( $cfg ) ) {
			wp_delete_file( $cfg );
		}
		$cache_dir = defined( 'WPOPT_CACHE_DIR' ) ? WPOPT_CACHE_DIR : WP_CONTENT_DIR . '/cache';
		foreach ( array( '/wpopt-object-cache.sqlite', '/wpopt-object-cache.sqlite-wal', '/wpopt-object-cache.sqlite-shm' ) as $f ) {
			if ( file_exists( $cache_dir . $f ) ) {
				wp_delete_file( $cache_dir . $f );
			}
		}
		update_option( 'wpoptimizing_cache_dropin', 0, false );
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'cache_dropin_uninstall', array() );
		}
		$this->cache_flash( $this->with_restored( __( 'Persistent object cache disabled.', 'wpoptimizing' ), function_exists( 'wpoptimizing_dropin_restore' ) ? wpoptimizing_dropin_restore( 'object' ) : '' ), 'updated' );
	}

	/**
	 * Ensure the cache directory exists and is not browsable (index.php +
	 * .htaccess). The analytics/SQLite files live here.
	 *
	 * @return void
	 */
	protected function ensure_cache_dir_protection() {
		wpoptimizing_ensure_cache_dir_protection();
	}

	/**
	 * Install the query-deduplication db.php drop-in (opt-in).
	 *
	 * @return void
	 */
	protected function install_db_dropin( $replace = false ) {
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'You do not have permission to manage the object cache.', 'wpoptimizing' ), 'error' );
			return;
		}
		$src  = defined( 'WPOPT_DB_DROPIN_SRC' ) ? WPOPT_DB_DROPIN_SRC : dirname( __DIR__ ) . '/dropins/db.php';
		$dest = defined( 'WPOPT_DB_DROPIN_DEST' ) ? WPOPT_DB_DROPIN_DEST : WP_CONTENT_DIR . '/db.php';

		// Never overwrite a db.php from another plugin (Query Monitor, HyperDB, …) — unless asked to
		// take its slot and that is safe (never HyperDB/LudicrousDB), keeping the original.
		if ( file_exists( $dest ) && ! $this->dropin_is_ours( $dest, 'WPOPT_DB_DROPIN' ) && ! $this->take_dropin_slot( 'db', $replace ) ) {
			return;
		}
		if ( ! is_readable( $src ) ) {
			$this->cache_flash( __( 'The query-deduplication template is missing from the plugin.', 'wpoptimizing' ), 'error' );
			return;
		}
		$this->ensure_cache_dir_protection();
		// Atomic for the same reason as the object-cache drop-in: a truncated db.php is a
		// fatal at bootstrap, and this one intercepts every query on the site.
		$ok = function_exists( 'wpoptimizing_atomic_install_file' )
			? wpoptimizing_atomic_install_file( $src, $dest )
			: false;
		if ( ! $ok ) {
			$this->cache_flash( __( 'Could not write wp-content/db.php. Check filesystem permissions.', 'wpoptimizing' ), 'error' );
			return;
		}
		update_option( 'wpoptimizing_db_dropin', 1, false );
		// See install_object_cache_dropin(): the db.php drop-in also only takes effect
		// next request, so arm the same one-shot recache to avoid a stale roles read.
		update_option( 'wpoptimizing_cache_recache_once', 1, true );
		if ( function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'db_dropin_install', array() );
		}
		$this->cache_flash( __( 'Query deduplication enabled.', 'wpoptimizing' ), 'updated' );
	}

	/**
	 * Remove our db.php drop-in (only ours).
	 *
	 * @return void
	 */
	protected function uninstall_db_dropin() {
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'You do not have permission to manage the object cache.', 'wpoptimizing' ), 'error' );
			return;
		}
		$dest = defined( 'WPOPT_DB_DROPIN_DEST' ) ? WPOPT_DB_DROPIN_DEST : WP_CONTENT_DIR . '/db.php';
		if ( file_exists( $dest ) ) {
			if ( ! $this->dropin_is_ours( $dest, 'WPOPT_DB_DROPIN' ) ) {
				$this->cache_flash( __( 'The active database drop-in is not managed by WPOptimizing; it was left untouched.', 'wpoptimizing' ), 'warning' );
				return;
			}
			wp_delete_file( $dest );
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $dest, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		update_option( 'wpoptimizing_db_dropin', 0, false );
		if ( function_exists( 'wpoptimizing_audit_log' ) ) {
			wpoptimizing_audit_log( 'db_dropin_uninstall', array() );
		}
		$this->cache_flash( $this->with_restored( __( 'Query deduplication disabled.', 'wpoptimizing' ), function_exists( 'wpoptimizing_dropin_restore' ) ? wpoptimizing_dropin_restore( 'db' ) : '' ), 'updated' );
	}

	/**
	 * Wizard entry point: enable the object cache, but only when no drop-in is
	 * present yet (never clobber an existing one, ours or foreign).
	 *
	 * @return void
	 */
	public function wizard_install_object_cache() {
		$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';
		if ( file_exists( $dest ) ) {
			return;
		}
		$this->install_object_cache_dropin();
	}

	/**
	 * Wizard entry point: enable query deduplication, but only when no db.php
	 * drop-in is present yet (never clobber an existing one, ours or foreign).
	 *
	 * @return void
	 */
	public function wizard_install_db_dropin() {
		$dest = defined( 'WPOPT_DB_DROPIN_DEST' ) ? WPOPT_DB_DROPIN_DEST : WP_CONTENT_DIR . '/db.php';
		if ( file_exists( $dest ) ) {
			return;
		}
		$this->install_db_dropin();
	}

	/**
	 * Flush the whole object cache.
	 *
	 * @return void
	 */
	protected function flush_object_cache() {
		/*
		 * The network capability, not the per-site one. On a network there is ONE object cache,
		 * and wp_cache_flush() empties all of it — our own drop-in now scopes that to our keys,
		 * but a site running somebody else's (Redis Object Cache and friends) still empties the
		 * instance, which is shared with every other site on the host. This plugin grants its
		 * capability to anyone holding manage_options, so without this the button belongs to
		 * every sub-site administrator.
		 */
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'Only a network administrator can flush the object cache: on a network it is shared by every site.', 'wpoptimizing' ), 'error' );
			return;
		}
		$ok = function_exists( 'wp_cache_flush' ) ? wp_cache_flush() : false;
		$this->cache_flash(
			$ok ? __( 'Object cache flushed.', 'wpoptimizing' ) : __( 'Could not flush the object cache.', 'wpoptimizing' ),
			$ok ? 'updated' : 'warning'
		);
	}

	/**
	 * Persist cache settings; rewrite the sidecar if the drop-in is installed.
	 *
	 * @return void
	 */
	protected function save_cache_settings() {
		if ( ! current_user_can( $this->cap ) ) {
			return;
		}

		/*
		 * Two kinds of setting live on this form and they do not deserve the same permission.
		 *
		 * The backend choice and its connection details are written into the sidecar that
		 * configures the object-cache drop-in — one file, for the whole network. A sub-site
		 * administrator holds this plugin's capability (it is granted to anyone with
		 * manage_options), so without this split they could point every other site's object
		 * cache at a Redis server of their own and read what lands in it: sessions, user
		 * objects, whatever those sites cache. That is not a availability problem, it is an
		 * exfiltration path.
		 *
		 * The rest — cached comment counts, the Heartbeat rate — only affect this site's own
		 * admin screens, so they stay where they were. On single site can_manage_dropin() is
		 * the plugin capability, so nothing changes there at all.
		 */
		$current       = (array) get_option( 'wpoptimizing_cache_settings', array() );
		$may_configure = $this->can_manage_dropin();

		$settings = array(
			'backend'        => isset( $_POST['wpopt_cache_backend'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_cache_backend'] ) ) : 'auto', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			'redis_host'     => isset( $_POST['wpopt_cache_redis_host'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_cache_redis_host'] ) ) : '127.0.0.1', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			'redis_port'     => isset( $_POST['wpopt_cache_redis_port'] ) ? absint( wp_unslash( $_POST['wpopt_cache_redis_port'] ) ) : 6379, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			// Password kept as-is (may contain symbols); only slashes are stripped.
			'redis_password' => isset( $_POST['wpopt_cache_redis_password'] ) ? (string) wp_unslash( $_POST['wpopt_cache_redis_password'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- Opaque credential; stored verbatim, never echoed. Read-only field repopulation; save action verifies the nonce.
			'redis_database' => isset( $_POST['wpopt_cache_redis_database'] ) ? absint( wp_unslash( $_POST['wpopt_cache_redis_database'] ) ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			'memcached_host' => isset( $_POST['wpopt_cache_memcached_host'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_cache_memcached_host'] ) ) : '127.0.0.1', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			'memcached_port' => isset( $_POST['wpopt_cache_memcached_port'] ) ? absint( wp_unslash( $_POST['wpopt_cache_memcached_port'] ) ) : 11211, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			'admin_counts'   => isset( $_POST['wpopt_cache_admin_counts'] ) ? 1 : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
			'heartbeat'      => isset( $_POST['wpopt_cache_heartbeat'] ) ? sanitize_key( wp_unslash( $_POST['wpopt_cache_heartbeat'] ) ) : 'default', // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Read-only field repopulation; save action verifies the nonce.
		);
		if ( ! in_array( $settings['heartbeat'], array( 'default', 'slow', 'off' ), true ) ) {
			$settings['heartbeat'] = 'default';
		}

		// Without the network capability, the connection fields keep whatever they already
		// held: the form's values for them are discarded rather than trusted.
		if ( ! $may_configure ) {
			foreach ( array( 'backend', 'redis_host', 'redis_port', 'redis_password', 'redis_database', 'memcached_host', 'memcached_port' ) as $restricted ) {
				if ( array_key_exists( $restricted, $current ) ) {
					$settings[ $restricted ] = $current[ $restricted ];
				} else {
					unset( $settings[ $restricted ] );
				}
			}
		}

		update_option( 'wpoptimizing_cache_settings', $settings, false );

		// If our drop-in is live, refresh its config so the change takes effect.
		$dest = defined( 'WPOPT_DROPIN_DEST' ) ? WPOPT_DROPIN_DEST : WP_CONTENT_DIR . '/object-cache.php';
		if ( $may_configure && file_exists( $dest ) && $this->dropin_is_ours( $dest ) ) {
			$this->write_cache_config_sidecar();
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
		}
		$this->cache_flash( __( 'Cache settings saved.', 'wpoptimizing' ), 'updated' );
	}

	/**
	 * Save the Cloudflare settings and, when they are complete, prove them with a real purge of
	 * the home page: a wrong zone or a token without the purge permission shows up here, not
	 * silently after the next cleanup.
	 *
	 * @param bool $forget Remove the stored token and switch the integration off.
	 * @return void
	 */
	protected function save_cloudflare( $forget = false ) {
		if ( ! current_user_can( $this->cap ) || ! function_exists( 'wpoptimizing_cf_save' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- maybe_handle_post() verified the nonce before dispatching here.
		$zone  = isset( $_POST['wpopt_cf_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_cf_zone'] ) ) : '';
		$token = isset( $_POST['wpopt_cf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wpopt_cf_token'] ) ) : '';
		$on_cl = ! empty( $_POST['wpopt_cf_on_cleanup'] );
		$on_ct = ! empty( $_POST['wpopt_cf_on_content'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $forget ) {
			wpoptimizing_cf_save( $zone, '', $on_cl, $on_ct, true );
			$this->cache_flash( __( 'The Cloudflare token has been removed. Cloudflare is no longer purged.', 'wpoptimizing' ), 'updated' );
			return;
		}
		if ( '' !== $token && function_exists( 'wpoptimizing_cf_looks_like_global_key' ) && wpoptimizing_cf_looks_like_global_key( $token ) ) {
			$this->cache_flash( __( 'That is the Global API Key, not an API token. It opens your whole Cloudflare account, so it is not saved: create a token with only the Zone › Cache Purge › Purge permission (see “How to get them”).', 'wpoptimizing' ), 'error' );
			return;
		}
		if ( '' !== $zone && ! wpoptimizing_cf_zone_is_valid( strtolower( $zone ) ) ) {
			$this->cache_flash( __( 'That is not a Cloudflare zone ID: it is a 32-character code shown on the zone\'s Overview page.', 'wpoptimizing' ), 'error' );
			return;
		}
		wpoptimizing_cf_save( $zone, $token, $on_cl, $on_ct );
		if ( ! wpoptimizing_cf_configured() ) {
			$this->cache_flash( __( 'Cloudflare settings saved. Add the zone ID and an API token to start purging.', 'wpoptimizing' ), 'updated' );
			return;
		}
		// Cloudflare confirms a purge of URLs outside the zone without purging them: the test
		// purge below would pass with another domain's zone ID.
		if ( function_exists( 'wpoptimizing_cf_zone_check' ) && 0 === strpos( wpoptimizing_cf_zone_check( true ), 'other:' ) ) {
			$this->cache_flash(
				sprintf(
					/* translators: 1: domain the zone belongs to, 2: this site's domain. */
					__( 'That zone ID belongs to %1$s, not to %2$s. Nothing will be purged until you enter the zone ID of this domain.', 'wpoptimizing' ),
					wpoptimizing_cf_zone_mismatch(),
					(string) wp_parse_url( home_url( '/' ), PHP_URL_HOST )
				),
				'error'
			);
			return;
		}
		if ( wpoptimizing_cf_purge_urls( array( home_url( '/' ) ) ) ) {
			$this->cache_flash( __( 'Cloudflare connected: the home page was purged as a test.', 'wpoptimizing' ), 'updated' );
			return;
		}
		$last = (array) get_option( WPOPTIMIZING_CF_LAST_OPTION, array() );
		$this->cache_flash(
			sprintf(
				/* translators: %s: error returned by Cloudflare. */
				__( 'Settings saved, but Cloudflare refused the test purge: %s', 'wpoptimizing' ),
				isset( $last['error'] ) ? (string) $last['error'] : ''
			),
			'error'
		);
	}

	/**
	 * "How to get them": the steps to find the zone ID and create the token, in a dialog.
	 *
	 * Drawn, not a screenshot. Cloudflare's dashboard changes and comes in the user's language;
	 * a picture of it ages and shows another company's interface. What does not change is the
	 * three-part permission line and where the zone ID is, so that is what is drawn.
	 *
	 * @return void
	 */
	protected function render_cloudflare_help() {
		$pill = static function ( array $parts ) {
			$out = '<span class="wpopt-cf-row">';
			foreach ( $parts as $p ) {
				$out .= '<span class="wpopt-cf-pill">' . esc_html( $p ) . '</span>';
			}
			return $out . '</span>';
		};
		echo '<p><button type="button" class="button wpopt-cf-help-btn" onclick="var d=document.getElementById(\'wpopt-cf-help\');if(d&&d.showModal){d.showModal();}">' . wpoptimizing_icon( 'editor-help' ) . ' ' . esc_html__( 'How to get them', 'wpoptimizing' ) . '</button></p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wpoptimizing_icon() escapes.
		echo '<dialog id="wpopt-cf-help" class="wpopt-dialog" aria-labelledby="wpopt-cf-help-title">';
		echo '<h2 id="wpopt-cf-help-title">' . esc_html__( 'Cloudflare: zone ID and API token', 'wpoptimizing' ) . '</h2>';
		echo '<ol class="wpopt-cf-steps">';
		echo '<li><strong>' . esc_html__( 'Zone ID', 'wpoptimizing' ) . '.</strong> ' . esc_html__( 'In Cloudflare, open your domain. On the Overview page, the “API” box on the right shows the Zone ID: copy it into the first field.', 'wpoptimizing' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'API token', 'wpoptimizing' ) . '.</strong> ' . esc_html__( 'Go to My Profile › API Tokens › Create token, and choose “Create custom token”.', 'wpoptimizing' ) . '</li>';
		echo '<li>' . esc_html__( 'Under Permissions, one line:', 'wpoptimizing' ) . ' ' . $pill( array( __( 'Zone', 'wpoptimizing' ), __( 'Cache Purge', 'wpoptimizing' ), __( 'Purge', 'wpoptimizing' ) ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pill escapes each part.
		echo '<li>' . esc_html__( 'To also cache the pages at Cloudflare, a second line:', 'wpoptimizing' ) . ' ' . $pill( array( __( 'Zone', 'wpoptimizing' ), __( 'Cache Rules', 'wpoptimizing' ), __( 'Edit', 'wpoptimizing' ) ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pill escapes each part.
		// Without it the edge still works, but visits with campaign parameters skip it (cloudflare-rules.php).
		echo '<li>' . esc_html__( 'For campaign visits (utm_*, gclid…) to be served from there too, a third line:', 'wpoptimizing' ) . ' ' . $pill( array( __( 'Zone', 'wpoptimizing' ), __( 'Transform Rules', 'wpoptimizing' ), __( 'Edit', 'wpoptimizing' ) ) ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pill escapes each part.
		echo '<li>' . esc_html__( 'Under Zone Resources:', 'wpoptimizing' ) . ' ' . $pill( array( __( 'Include', 'wpoptimizing' ), __( 'Specific zone', 'wpoptimizing' ), wp_parse_url( home_url(), PHP_URL_HOST ) ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $pill escapes each part.
			. '<br><span class="description">' . esc_html__( 'Or “All zones” to use the same token on every site of the account: it can then purge any of them, and nothing else.', 'wpoptimizing' ) . '</span></li>';
		echo '<li>' . esc_html__( 'Continue to summary › Create token. Cloudflare shows the token only once: paste it into the second field and save.', 'wpoptimizing' ) . '</li>';
		echo '</ol>';
		echo '<p class="wpopt-cf-note">' . esc_html__( 'Do not use the Global API Key: it opens your whole account, and WPOptimizing refuses it.', 'wpoptimizing' ) . '</p>';
		echo '<form method="dialog"><button class="button button-primary">' . esc_html__( 'Close', 'wpoptimizing' ) . '</button></form>';
		echo '</dialog>';
	}

	/**
	 * The Cloudflare block of Cache > Settings: its own form.
	 *
	 * @param string $base_url Form action.
	 * @param string $nonce    Nonce.
	 * @return void
	 */
	protected function render_cloudflare_settings( $base_url, $nonce ) {
		if ( ! function_exists( 'wpoptimizing_cf_settings' ) ) {
			return;
		}
		$cf   = wpoptimizing_cf_settings();
		$last = (array) get_option( WPOPTIMIZING_CF_LAST_OPTION, array() );

		echo '<div id="wpopt-cloudflare"></div>';
		if ( function_exists( 'wpoptimizing_cf_detected' ) && wpoptimizing_cf_detected() && ! wpoptimizing_cf_configured() ) {
			echo '<div class="notice notice-info inline wpopt-prose"><p>' . esc_html__( 'This site is served through Cloudflare and it is not being purged yet.', 'wpoptimizing' ) . '</p></div>';
		}
		if ( wpoptimizing_cf_configured() && function_exists( 'wpoptimizing_cf_zone_check' ) ) {
			wpoptimizing_cf_zone_check();
			$other  = wpoptimizing_cf_zone_mismatch();
			$shared = wpoptimizing_cf_zone_shared_with();
			if ( '' !== $other ) {
				/* translators: %s: domain the zone belongs to. */
				echo '<div class="notice notice-error inline wpopt-prose"><p>' . esc_html( sprintf( __( 'The zone ID belongs to %s, not to this site, so nothing of this site is being purged. Enter the zone ID of this domain.', 'wpoptimizing' ), $other ) ) . '</p></div>';
			} elseif ( $shared ) {
				/* translators: %s: other domains of the network. */
				echo '<div class="notice notice-warning inline wpopt-prose"><p>' . esc_html( sprintf( __( 'This zone ID is also saved on %s. A Cloudflare zone is one domain, so one of the sites is purging the other\'s cache and not its own: check the zone ID on each.', 'wpoptimizing' ), implode( ', ', $shared ) ) ) . '</p></div>';
			}
		}
		$this->render_section_header(
			'Cloudflare',
			__( 'If your site is behind Cloudflare, WPOptimizing purges its cache too: everything when it flushes the other caches, and only the affected pages when content changes. It needs the zone ID and an API token.', 'wpoptimizing' ),
			array(
				'icon' => 'networking',
				'doc'  => 'wpopt-doc-cache',
			)
		);
		$this->render_cloudflare_help();
		echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-cache-form">';
		echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
		echo '<input type="hidden" name="tab" value="cache" />';
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="wpopt_cf_zone">%s</label></th><td><input type="text" id="wpopt_cf_zone" name="wpopt_cf_zone" class="regular-text code" value="%s" maxlength="32" spellcheck="false" autocomplete="off" /><p class="description">%s</p></td></tr>',
			esc_html__( 'Zone ID', 'wpoptimizing' ),
			esc_attr( $cf['zone'] ),
			esc_html__( 'Your domain’s Overview page, under “API” on the right.', 'wpoptimizing' )
		);
		$has_token = '' !== $cf['token'];
		printf(
			'<tr><th scope="row"><label for="wpopt_cf_token">%s</label></th><td><input type="password" id="wpopt_cf_token" name="wpopt_cf_token" class="regular-text" value="" placeholder="%s" autocomplete="new-password" spellcheck="false" /><p class="description">%s</p></td></tr>',
			esc_html__( 'API token', 'wpoptimizing' ),
			esc_attr( $has_token ? __( 'Saved — leave empty to keep it', 'wpoptimizing' ) : '' ),
			esc_html__( 'An API token with the Zone › Cache Purge › Purge permission, not the Global API Key. Stored encrypted and never shown again.', 'wpoptimizing' )
		);
		echo '<tr><th scope="row">' . esc_html__( 'When to purge', 'wpoptimizing' ) . '</th><td>';
		foreach ( array(
			'wpopt_cf_on_cleanup' => array( $cf['on_cleanup'], __( 'Purge everything when WPOptimizing flushes caches (after a cleanup and from “Flush all caches”)', 'wpoptimizing' ) ),
			'wpopt_cf_on_content' => array( $cf['on_content'], __( 'Purge the affected pages when a post, page or comment changes', 'wpoptimizing' ) ),
		) as $name => $row ) {
			echo '<p class="wpopt-form-check"><label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="' . esc_attr( $name ) . '" value="1"' . checked( ! empty( $row[0] ), true, false ) . ' />'
				. '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>'
				. '<span class="wpopt-toggle-label">' . esc_html( $row[1] ) . '</span></label></p>';
		}
		echo '</td></tr>';
		if ( ! empty( $last['time'] ) ) {
			$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['time'] );
			$what = 'everything' === ( $last['what'] ?? '' )
				? __( 'everything', 'wpoptimizing' )
				: sprintf(
					/* translators: %d: number of URLs. */
					_n( '%d page', '%d pages', (int) ( $last['count'] ?? 0 ), 'wpoptimizing' ),
					(int) ( $last['count'] ?? 0 )
				);
			echo '<tr><th scope="row">' . esc_html__( 'Last purge', 'wpoptimizing' ) . '</th><td>';
			if ( ! empty( $last['ok'] ) ) {
				/* translators: 1: date and time, 2: what was purged ("everything" or "3 pages"). */
				echo '<span class="wpopt-ok">' . esc_html( sprintf( __( '%1$s — %2$s, confirmed by Cloudflare', 'wpoptimizing' ), $when, $what ) ) . '</span>';
			} else {
				/* translators: 1: date and time, 2: error returned by Cloudflare. */
				echo '<span class="wpopt-warn">' . esc_html( sprintf( __( '%1$s — failed: %2$s', 'wpoptimizing' ), $when, (string) ( $last['error'] ?? '' ) ) ) . '</span>';
				// 10000 is Cloudflare's "this credential is not valid here": almost always the
				// Global API Key in the token field, or a token without the purge permission.
				if ( false !== strpos( (string) ( $last['error'] ?? '' ), '(10000)' ) ) {
					echo '<p class="description">' . esc_html__( 'Cloudflare did not accept the credential. It must be an API token with the Zone › Cache Purge › Purge permission; the Global API Key does not work here.', 'wpoptimizing' ) . ' <button type="button" class="button-link" onclick="var d=document.getElementById(\'wpopt-cf-help\');if(d&&d.showModal){d.showModal();}">' . esc_html__( 'How to get them', 'wpoptimizing' ) . '</button></p>';
				}
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save and test Cloudflare', 'wpoptimizing' ), 'primary wpopt-save-btn', 'wpopt_cf_save', false );
		if ( $has_token ) {
			echo ' ';
			submit_button( __( 'Remove token', 'wpoptimizing' ), 'delete', 'wpopt_cf_forget', false );
		}
		echo '</form>';
	}


	/**
	 * Add or remove the browser-cache / GZIP .htaccess block.
	 *
	 * @param bool $enable True to add the rules, false to remove them.
	 * @return void
	 */
	protected function cache_browser_apply( $enable ) {
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'You do not have permission to manage the browser cache.', 'wpoptimizing' ), 'error' );
			return;
		}
		$r    = wpoptimizing_browser_cache_apply( (bool) $enable );
		$code = isset( $r['code'] ) ? (string) $r['code'] : '';
		if ( ! empty( $r['ok'] ) ) {
			$this->cache_flash(
				$enable ? __( 'Browser cache and compression enabled.', 'wpoptimizing' ) : __( 'Browser cache and compression disabled.', 'wpoptimizing' ),
				'updated'
			);
		} elseif ( 'notwritable' === $code ) {
			$this->cache_flash( __( 'Could not write .htaccess. Check file permissions.', 'wpoptimizing' ), 'error' );
		} elseif ( 'nobackup' === $code ) {
			$this->cache_flash( wpoptimizing_backup_failure_message( wpoptimizing_backup_failure() ), 'error' );
		} else {
			$this->cache_flash( __( 'Could not update .htaccess.', 'wpoptimizing' ), 'error' );
		}
	}

	/**
	 * What a deactivated page cache left behind, on every Cache screen, with the way to remove it.
	 *
	 * @param string $base_url Cache tab URL.
	 * @param string $nonce    Nonce.
	 * @return void
	 */
	protected function render_cache_leftovers( $base_url, $nonce ) {
		if ( ! function_exists( 'wpoptimizing_cache_leftovers' ) ) {
			return;
		}
		$state = wpoptimizing_cache_leftovers();
		if ( ! wpoptimizing_cache_leftovers_any( $state ) ) {
			return;
		}
		$items = array();
		foreach ( $state['htaccess'] as $block ) {
			/* translators: %s: plugin name, e.g. WP Super Cache. */
			$items[] = sprintf( __( 'Rules from %s in .htaccess, which keep serving old pages before WordPress runs.', 'wpoptimizing' ), $block['label'] );
		}
		if ( ! empty( $state['dropin'] ) ) {
			$items[] = '' !== $state['dropin']['name']
				/* translators: %s: plugin name. */
				? sprintf( __( 'wp-content/advanced-cache.php from %s, which is no longer active but still loads on every visit.', 'wpoptimizing' ), $state['dropin']['name'] )
				: __( 'wp-content/advanced-cache.php from a plugin that is no longer active, which still loads on every visit.', 'wpoptimizing' );
		}
		if ( ! empty( $state['wp_cache'] ) ) {
			$items[] = __( 'A second WP_CACHE line in wp-config.php, which makes PHP warn on every visit.', 'wpoptimizing' );
		}
		echo '<div class="notice notice-warning inline wpopt-prose"><p><strong>' . esc_html__( 'A page cache you no longer use left things behind', 'wpoptimizing' ) . '</strong></p><ul class="ul-disc">';
		foreach ( $items as $item ) {
			echo '<li>' . esc_html( $item ) . '</li>';
		}
		echo '</ul>';
		if ( $this->can_manage_dropin() ) {
			echo '<form method="post" action="' . esc_url( $base_url ) . '">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="tab" value="cache" />';
			echo '<p>' . esc_html__( 'Each file is copied to Backups before it is changed, so this can be undone.', 'wpoptimizing' ) . '</p>';
			submit_button( __( 'Remove what was left behind', 'wpoptimizing' ), 'secondary', 'wpopt_cache_leftovers_clean', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/**
	 * Remove what a deactivated page cache left behind.
	 *
	 * @return void
	 */
	protected function cache_leftovers_clean() {
		if ( ! $this->can_manage_dropin() || ! function_exists( 'wpoptimizing_cache_leftovers_clean' ) ) {
			$this->cache_flash( __( 'You do not have permission to change these files.', 'wpoptimizing' ), 'error' );
			return;
		}
		$r = wpoptimizing_cache_leftovers_clean();
		if ( ! empty( $r['ok'] ) ) {
			$this->cache_flash( __( 'Removed what the old page cache left behind. The previous files are in Backups.', 'wpoptimizing' ), 'updated' );
			if ( function_exists( 'wpoptimizing_load_lib' ) ) {
				wpoptimizing_load_lib( 'includes/host-cache.php' );
			}
			if ( function_exists( 'wpoptimizing_flush_all_caches' ) ) {
				wpoptimizing_flush_all_caches();
			}
			return;
		}
		$this->cache_flash( __( 'Some of it could not be removed: the file is not writable or could not be backed up first, so it was left as it was.', 'wpoptimizing' ), 'error' );
	}

	/**
	 * Check the caches in front of the site, or save the Varnish purge switch.
	 *
	 * @param bool $check Run the check instead of saving.
	 * @return void
	 */
	protected function cache_edge_action( $check ) {
		if ( $check ) {
			$state = wpoptimizing_edge_cache_check();
			$this->cache_flash(
				empty( $state['layers'] )
					? __( 'No cache found in front of the site.', 'wpoptimizing' )
					: sprintf(
						/* translators: %s: list of cache names. */
						__( 'Found in front of the site: %s.', 'wpoptimizing' ),
						implode( ', ', wp_list_pluck( $state['layers'], 'label' ) )
					),
				'updated'
			);
			return;
		}
		if ( ! $this->can_manage_dropin() ) {
			$this->cache_flash( __( 'You do not have permission to manage the page cache.', 'wpoptimizing' ), 'error' );
			return;
		}
		$state                  = wpoptimizing_edge_cache_state();
		$state['varnish_purge'] = ! empty( $_REQUEST['wpopt_varnish_purge'] ) && wpoptimizing_edge_cache_has( $state, 'varnish' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked by the shared cache action handler; only its presence is read.
		update_option( 'wpoptimizing_edge_cache', $state, true );
		$this->cache_flash( __( 'Page cache settings saved.', 'wpoptimizing' ), 'updated' );
	}






	/**
	 * Set a flash notice for the Cache tab.
	 *
	 * @param string $msg  Message.
	 * @param string $type updated|warning|error.
	 * @return void
	 */
	protected function cache_flash( $msg, $type = 'updated' ) {
		set_transient(
			'wpoptimizing_flash',
			array(
				'code' => 'wpoptimizing_cache',
				'msg'  => $msg,
				'type' => $type,
			),
			60
		);
	}

	/**
	 * Render the Cache tab.
	 *
	 * @return void
	 */
	protected function render_cache_tab() {
		$st       = $this->get_cache_status();
		$settings = $this->get_cache_settings();
		$nonce    = wp_create_nonce( $this->nonce_action );
		$base_url = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => 'cache',
			),
			admin_url( 'admin.php' )
		);

		$cache_intro = __( 'Speed up the admin and the front end with a persistent object cache and per-request query deduplication — safe and reversible.', 'wpoptimizing' );
		$this->render_section_header(
			__( 'Cache', 'wpoptimizing' ),
			$cache_intro,
			array(
				'icon' => 'cloud',
				'doc'  => 'wpopt-doc-cache',
			)
		);

		$an   = $this->get_cache_analytics();
		$csec = isset( $_GET['csec'] ) ? sanitize_key( wp_unslash( $_GET['csec'] ) ) : 'object'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $csec, array( 'object', 'page', 'browser', 'dedup', 'analytics', 'cloudflare', 'settings' ), true ) ) {
			$csec = 'object';
		}
		// Behind Cloudflare but not purging it: say so on every Cache screen, with the way to fix it.
		// Not once it is configured, and not on the settings screen that already shows the form.
		if ( function_exists( 'wpoptimizing_cf_detected' ) && wpoptimizing_cf_detected() && ! wpoptimizing_cf_configured() && 'cloudflare' !== $csec ) {
			$cf_url = add_query_arg( 'csec', 'cloudflare', $base_url );
			echo '<div class="notice notice-info inline wpopt-prose"><p>'
				. esc_html__( 'This site is served through Cloudflare, which keeps its own copy of your pages and files. Connect it so WPOptimizing purges it along with the other caches.', 'wpoptimizing' )
				. ' <a href="' . esc_url( $cf_url ) . '">' . esc_html__( 'Set up Cloudflare', 'wpoptimizing' ) . '</a></p></div>';
		}
		$this->render_cache_leftovers( $base_url, $nonce );

		echo '<ul class="subsubsub wpopt-subsubsub">';
		$this->cache_menu_link( 'object', __( 'Object cache', 'wpoptimizing' ), $csec );
		echo ' | ';
		$this->cache_menu_link( 'page', __( 'Page cache', 'wpoptimizing' ), $csec );
		echo ' | ';
		$this->cache_menu_link( 'browser', __( 'Browser & server', 'wpoptimizing' ), $csec );
		echo ' | ';
		$this->cache_menu_link( 'dedup', __( 'Query deduplication', 'wpoptimizing' ), $csec );
		echo ' | ';
		$this->cache_menu_link( 'analytics', __( 'Analytics', 'wpoptimizing' ), $csec );
		echo ' | ';
		$this->cache_menu_link( 'cloudflare', 'Cloudflare', $csec );
		echo ' | ';
		$this->cache_menu_link( 'settings', __( 'Settings', 'wpoptimizing' ), $csec );
		echo '</ul><br class="clear" />';

		// ===== Object cache section =====
		if ( 'object' === $csec ) {

			$this->render_section_header(
				__( 'Object cache', 'wpoptimizing' ),
				__( 'A persistent object cache stores the results of expensive database queries in memory (or on disk). It uses your server cache (Redis or Memcached) when available, APCu if you choose it, and a self-contained SQLite store otherwise.', 'wpoptimizing' ),
				array(
					'icon' => 'database',
					'doc'  => 'wpopt-doc-cache',
				)
			);

			// --- Status panel -------------------------------------------------
			echo '<table class="widefat striped wpopt-table-full"><tbody>';

			$persistent = $st['using_ext'] && $st['dropin_ours'];
			$state_html = $persistent
			? '<span class="wpopt-ok">' . esc_html__( 'Active', 'wpoptimizing' ) . '</span>'
			: ( $st['dropin_foreign']
				? '<span class="wpopt-warn">' . esc_html( $this->managed_by_label( 'object' ) ) . '</span>'
				: '<span class="wpopt-muted">' . esc_html__( 'Not active', 'wpoptimizing' ) . '</span>' );
			printf( '<tr><th class="wpopt-th-label" scope="row">%s</th><td>%s</td></tr>', esc_html__( 'Status', 'wpoptimizing' ), $state_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.

			if ( $st['active_backend'] ) {
				printf(
					'<tr><th scope="row">%s</th><td><code>%s</code></td></tr>',
					esc_html__( 'Active backend', 'wpoptimizing' ),
					esc_html( $this->cache_backend_label( $st['active_backend'] ) )
				);
			}

			// Available backends as highlighted chips: available ones stand out (green,
			// bold, check icon); unavailable ones are muted + struck through, so the
			// reader sees at a glance what this server actually offers.
			$chips = '';
			foreach ( $st['backends'] as $name => $ok ) {
				$chips .= sprintf(
					'<span class="wpopt-backend-chip %s">%s%s</span>',
					$ok ? 'is-available' : 'is-missing',
					$ok
					? wpoptimizing_icon( 'yes' )
					: wpoptimizing_icon( 'no-alt' ),
					esc_html( $this->cache_backend_label( $name ) )
				);
			}
			printf(
				'<tr><th scope="row">%s</th><td class="wpopt-backend-chips">%s</td></tr>',
				esc_html__( 'Available backends', 'wpoptimizing' ),
				$chips // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Labels esc_html'd above; classes are static.
			);

			// Live hit/miss for THIS admin request (a quick health signal, like other
			// object-cache tools show "at this point in the page load").
			if ( $persistent && ! empty( $st['stats'] ) ) {
				$hits   = (int) ( $st['stats']['hits'] ?? 0 );
				$misses = (int) ( $st['stats']['misses'] ?? 0 );
				$total  = $hits + $misses;
				$ratio  = $total > 0 ? round( $hits / $total * 100 ) : 0;
				printf(
					'<tr><th scope="row">%s</th><td>%s</td></tr>',
					esc_html__( 'Hits / misses (this request)', 'wpoptimizing' ),
					esc_html(
						sprintf(
						/* translators: 1: hit count, 2: miss count, 3: hit ratio percentage. */
							__( '%1$s hits · %2$s misses · %3$d%% hit rate', 'wpoptimizing' ),
							number_format_i18n( $hits ),
							number_format_i18n( $misses ),
							$ratio
						)
					)
				);
			}
			echo '</tbody></table>';

			// Host / plugin page caches — coexistence. We do NOT cache HTML pages; we
			// detect the provider's page cache and purge it after cleanups instead.
			if ( function_exists( 'wpoptimizing_detect_host_cache' ) ) {
				$page_caches = array_filter(
					wpoptimizing_detect_host_cache(),
					static function ( $c ) {
						return isset( $c['type'] ) && in_array( $c['type'], array( 'page', 'cdn' ), true );
					}
				);
				if ( ! empty( $page_caches ) ) {
					$detected_note = __( 'WPOptimizing detects your host or plugin page cache and purges it automatically after a cleanup (and from the toolbar “Flush all caches”), so visitors never see stale content.', 'wpoptimizing' );
					$this->render_subsection_header(
						__( 'Detected page caches', 'wpoptimizing' ),
						$detected_note
					);
					echo '<table class="widefat striped wpopt-table-full"><thead><tr>';
					printf( '<th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>', esc_html__( 'Cache', 'wpoptimizing' ), esc_html__( 'Integration', 'wpoptimizing' ) );
					foreach ( $page_caches as $c ) {
							$note = ! empty( $c['managed'] )
								? esc_html__( 'Auto-purges on changes', 'wpoptimizing' )
								: esc_html__( 'Purged after cleanup and from the toolbar', 'wpoptimizing' );
							printf( '<tr><td><strong>%s</strong></td><td>%s</td></tr>', esc_html( (string) $c['label'] ), esc_html( $note ) );
					}
					echo '</tbody></table>';
				}
			}

			// Drop-in update available (installed version differs from the bundled one).
			if ( $st['needs_update'] && $st['can_manage'] ) {
				echo '<div class="notice notice-info inline wpopt-prose"><p>'
				. esc_html__( 'A newer version of the object cache drop-in is available. Update to get the latest fixes and backends.', 'wpoptimizing' )
				. '</p></div>';
			}

			// --- Foreign drop-in note ----------------------------------------
			if ( $st['dropin_foreign'] ) {
				$this->render_foreign_dropin( 'object', 'wpopt_cache_replace' );
			}

			// --- Action buttons ----------------------------------------------
			echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-prose">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="tab" value="cache" />';

			if ( ! $st['can_manage'] ) {
				echo '<p class="description">' . esc_html__( 'Only a network administrator can enable or disable the object cache on this multisite network.', 'wpoptimizing' ) . '</p>';
			} elseif ( $persistent ) {
				if ( $st['needs_update'] ) {
					// Re-installs (overwrites) our own drop-in with the bundled version.
					submit_button( __( 'Update drop-in', 'wpoptimizing' ), 'primary', 'wpopt_cache_install', false );
					echo ' ';
				}
				submit_button( __( 'Disable object cache', 'wpoptimizing' ), 'secondary', 'wpopt_cache_uninstall', false );
				echo ' ';
				submit_button( __( 'Flush cache now', 'wpoptimizing' ), 'secondary', 'wpopt_cache_flush', false );
			} elseif ( ! $st['dropin_foreign'] ) {
				submit_button( __( 'Enable object cache', 'wpoptimizing' ), 'primary', 'wpopt_cache_install', false );
			}

			if ( $st['dropin_foreign'] ) {
				submit_button( __( 'Flush cache now', 'wpoptimizing' ), 'secondary', 'wpopt_cache_flush', false );
			}
			echo '</form>';
		} // end Object cache section

		// ===== Page cache section =====
		if ( 'page' === $csec ) {
			// The page cache, its preload and the pages at Cloudflare's edge are Pro (23/10/2026):
			// Lite has none of that code, only the note; Pro without a license shows it locked.
			$pc_pro = false;
			if ( ! $pc_pro ) {
				$this->render_section_header(
					__( 'Page cache', 'wpoptimizing' ),
					__( 'A page cache serves a static HTML copy of each page to anonymous visitors, so most requests never boot WordPress.', 'wpoptimizing' ),
					array(
						'icon' => 'admin-page',
						'doc'  => 'wpopt-doc-cache',
					)
				);
				$pc_note = __( 'A page cache, with background preload and page caching at Cloudflare\'s edge, is not included in this plugin.', 'wpoptimizing' );
				$this->render_pro_note( $pc_note );
			}
		} // end Page cache section

		// ===== Browser & server section =====
		// What sits around the page cache rather than in it: the caches in front of the site and
		// the browser cache. Its own screen, so each screen has a single save button.
		if ( 'browser' === $csec ) {
			$can = $this->can_manage_dropin();
			$this->render_section_header(
				__( 'Browser & server', 'wpoptimizing' ),
				__( 'The caches outside WordPress: the ones your host or CDN keeps in front of the site, and the copies of your files that visitors’ browsers keep.', 'wpoptimizing' ),
				array(
					'icon' => 'admin-site-alt3',
					'doc'  => 'wpopt-doc-cache',
				)
			);
			// --- Caches in front of the site (server / CDN), read from the response headers ---
			if ( function_exists( 'wpoptimizing_edge_cache_state' ) ) {
				$edge = wpoptimizing_edge_cache_state();
				$this->render_subsection_header(
					__( 'Caches in front of your site', 'wpoptimizing' ),
					__( 'Your host or CDN may keep its own copy of each page, which a WordPress plugin cannot see from the inside. This asks for your home page as a visitor and reads what each layer says about itself.', 'wpoptimizing' )
				);
				if ( $edge['checked'] > 0 ) {
					echo '<table class="widefat striped wpopt-table-full"><tbody>';
					if ( empty( $edge['layers'] ) ) {
						printf( '<tr><td colspan="2">%s</td></tr>', esc_html__( 'No cache in front of the site: every visit reaches your server.', 'wpoptimizing' ) );
					}
					foreach ( $edge['layers'] as $layer ) {
						$state = null === $layer['hit'] ? __( 'Present', 'wpoptimizing' ) : ( $layer['hit'] ? __( 'Serving from its cache', 'wpoptimizing' ) : __( 'Present, not serving this page from cache', 'wpoptimizing' ) );
						printf( '<tr><th class="wpopt-th-label" scope="row">%s</th><td>%s</td></tr>', esc_html( (string) $layer['label'] ), esc_html( $state ) );
					}
					echo '</tbody></table>';
					echo '<p class="description">' . esc_html(
						sprintf(
							/* translators: %s: human time difference. */
							__( 'Checked %s ago.', 'wpoptimizing' ),
							human_time_diff( (int) $edge['checked'] )
						)
					) . '</p>';
				}
				echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-prose">';
				echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
				echo '<input type="hidden" name="tab" value="cache" /><input type="hidden" name="csec" value="browser" />';
				$varnish = function_exists( 'wpoptimizing_edge_cache_has' ) && wpoptimizing_edge_cache_has( $edge, 'varnish' );
				if ( $varnish && $can ) {
					if ( wpoptimizing_varnish_purged_elsewhere() ) {
						echo '<p class="description">' . esc_html__( 'Varnish is purged by Proxy Cache Purge, so WPOptimizing leaves it to it.', 'wpoptimizing' ) . '</p>';
					} else {
						echo '<p><label><input type="checkbox" name="wpopt_varnish_purge" value="1"' . checked( $edge['varnish_purge'], true, false ) . ' /> ' . esc_html__( 'Purge Varnish when content changes and when all caches are flushed', 'wpoptimizing' ) . '</label></p>';
						echo '<p class="description">' . esc_html__( 'Sends PURGE requests for the pages a change makes stale. Varnish only accepts them when its configuration allows PURGE from this server, which hosts that run Varnish usually set up.', 'wpoptimizing' ) . '</p>';
						submit_button( __( 'Save settings', 'wpoptimizing' ), 'primary wpopt-save-btn', 'wpopt_edge_save', false );
						echo ' ';
					}
				}
				submit_button( $edge['checked'] > 0 ? __( 'Check again', 'wpoptimizing' ) : __( 'Check now', 'wpoptimizing' ), 'secondary', 'wpopt_edge_check', false );
				echo '</form>';
			}

			// --- The hosting's page cache, purged on every change (host-purge.php) ---
			$host_purge = function_exists( 'wpoptimizing_host_purge_status' ) ? wpoptimizing_host_purge_status() : array();
			if ( $host_purge ) {
				$this->render_subsection_header(
					__( 'Hosting cache', 'wpoptimizing' ),
					__( 'WPOptimizing purges your host’s page cache when content changes and when the whole site does (theme, menus, updates, “Flush all caches”), even with its own page cache off. Hosts that can only empty everything are emptied only for site-wide changes.', 'wpoptimizing' )
				);
				echo '<table class="widefat striped wpopt-table-full"><thead><tr>';
				printf( '<th scope="col">%s</th><th scope="col">%s</th></tr></thead><tbody>', esc_html__( 'Cache', 'wpoptimizing' ), esc_html__( 'Last purge', 'wpoptimizing' ) );
				foreach ( $host_purge as $hp ) {
					$hp_last = $hp['last'];
					if ( empty( $hp_last['time'] ) ) {
						$hp_cell = '<span class="wpopt-muted">' . esc_html__( 'Never', 'wpoptimizing' ) . '</span>';
					} else {
						$hp_what = 'all' === $hp_last['scope']
							? __( 'everything', 'wpoptimizing' )
							/* translators: %d: number of URLs. */
							: sprintf( _n( '%d page', '%d pages', (int) $hp_last['count'], 'wpoptimizing' ), (int) $hp_last['count'] );
						$hp_text = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $hp_last['time'] ) . ' — ' . $hp_what;
						$hp_cell = ! empty( $hp_last['ok'] )
							? '<span class="wpopt-ok">' . esc_html( $hp_text ) . '</span>'
							: '<span class="wpopt-warn">' . esc_html( $hp_text . ' — ' . __( 'Failed', 'wpoptimizing' ) ) . '</span>';
					}
					printf( '<tr><th class="wpopt-th-label" scope="row">%s</th><td>%s</td></tr>', esc_html( $hp['label'] ), $hp_cell ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
				}
				echo '</tbody></table>';
			}

			// --- Browser cache & compression (.htaccess) — free, independent of page cache ---
			$bc = function_exists( 'wpoptimizing_browser_cache_status' ) ? wpoptimizing_browser_cache_status() : array();
			$this->render_subsection_header(
				__( 'Browser cache & compression', 'wpoptimizing' ),
				__( 'Tells returning visitors’ browsers to reuse your CSS, JS, images and fonts instead of re-downloading them, and serves everything GZIP/Brotli-compressed. Adds a managed, reversible block to .htaccess (Apache/LiteSpeed).', 'wpoptimizing' )
			);
			echo '<table class="widefat striped wpopt-table-full"><tbody>';
			$bc_state = ! empty( $bc['active'] )
				? '<span class="wpopt-ok">' . esc_html__( 'Active', 'wpoptimizing' ) . '</span>'
				: '<span class="wpopt-muted">' . esc_html__( 'Not active', 'wpoptimizing' ) . '</span>';
			printf( '<tr><th class="wpopt-th-label" scope="row">%s</th><td>%s</td></tr>', esc_html__( 'Status', 'wpoptimizing' ), $bc_state ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped above.
			echo '</tbody></table>';
			if ( empty( $bc['supported'] ) ) {
				echo '<div class="notice notice-info inline wpopt-prose"><p>'
					. esc_html__( 'Your server does not use .htaccess (e.g. Nginx). Add equivalent expires and gzip rules to your server config instead.', 'wpoptimizing' )
					. '</p></div>';
			}
			echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-prose">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="tab" value="cache" />';
			echo '<input type="hidden" name="csec" value="browser" />';
			if ( $this->can_manage_dropin() ) {
				if ( ! empty( $bc['active'] ) ) {
					submit_button( __( 'Disable browser cache', 'wpoptimizing' ), 'secondary', 'wpopt_browsercache_remove', false );
				} else {
					submit_button( __( 'Enable browser cache & compression', 'wpoptimizing' ), 'primary', 'wpopt_browsercache_apply', false );
				}
			}
			echo '</form>';

		} // end Browser & server section

		// ===== Analytics section =====
		if ( 'analytics' === $csec ) {
			$this->render_section_header(
				__( 'Cache analytics', 'wpoptimizing' ),
				__( 'Hit/miss ratio, query metrics and the most-duplicated queries, aggregated per day over the last 30 days.', 'wpoptimizing' ),
				array(
					'icon' => 'chart-bar',
					'doc'  => 'wpopt-doc-cache',
				)
			);
			if ( ! $an['has'] ) {
				echo '<p class="description">' . esc_html__( 'No analytics yet — figures appear after some traffic flows through the cache.', 'wpoptimizing' ) . '</p>';
			} else {
				echo '<table class="widefat striped wpopt-table-full"><tbody>';
				printf( '<tr><th class="wpopt-th-label" scope="row">%s</th><td><strong>%d%%</strong></td></tr>', esc_html__( 'Overall hit rate', 'wpoptimizing' ), (int) $an['ratio'] );
				$wpopt_ttfb = function_exists( 'wpoptimizing_server_ttfb_ms' ) ? wpoptimizing_server_ttfb_ms() : 0;
				if ( $wpopt_ttfb > 0 ) {
					$ttfb_level  = function_exists( 'wpoptimizing_ttfb_level' ) ? wpoptimizing_ttfb_level( $wpopt_ttfb ) : 'unknown';
					$level_label = array(
						'good' => __( 'Good', 'wpoptimizing' ),
						'ok'   => __( 'Improvable', 'wpoptimizing' ),
						'high' => __( 'High', 'wpoptimizing' ),
					);
					$badge       = isset( $level_label[ $ttfb_level ] )
					? '<span class="wpopt-ttfb-badge wpopt-ttfb-badge--' . esc_attr( $ttfb_level ) . '">' . esc_html( $level_label[ $ttfb_level ] ) . '</span>'
					: '';
					echo '<tr><th class="wpopt-th-label" scope="row">' . esc_html__( 'Server TTFB (avg)', 'wpoptimizing' ) . '</th><td>';
					echo '<strong>' . (int) $wpopt_ttfb . ' ms</strong> ' . $badge . ' '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- badge pre-escaped above.
					echo '<span class="description">' . esc_html__( 'Time WordPress takes to start sending the page — the server share of TTFB (not the visitor network).', 'wpoptimizing' ) . '</span>';
					echo ' <a href="' . esc_url( admin_url( 'admin.php?page=wpoptimizing&tab=performance&psec=server' ) ) . '">' . esc_html__( 'Open the Performance report', 'wpoptimizing' ) . ' →</a>';

					// Prioritised, context-aware actions when the server response is slow.
					if ( function_exists( 'wpoptimizing_ttfb_action_plan' ) && ( 'high' === $ttfb_level || 'ok' === $ttfb_level ) ) {
						$ttfb_plan = wpoptimizing_ttfb_action_plan(
							$wpopt_ttfb,
							array(
								'cache_active'       => function_exists( 'wpoptimizing_object_cache_active' ) && wpoptimizing_object_cache_active(),
								'hit_rate'           => (float) $an['ratio'],
								'autoload_bytes'     => (int) get_option( 'wpoptimizing_autoload_size', 0 ),
								'wp_cron_on_request' => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
							)
						);
						if ( ! empty( $ttfb_plan ) ) {
							$ttfb_base = admin_url( 'admin.php?page=wpoptimizing' );
							echo '<ul class="wpopt-disc-list wpopt-meta wpopt-ttfb-actions">';
							foreach ( $ttfb_plan as $act ) {
								if ( ! empty( $act['tab'] ) ) {
									printf( '<li><a href="%s">%s</a></li>', esc_url( add_query_arg( 'tab', (string) $act['tab'], $ttfb_base ) ), esc_html( (string) $act['text'] ) );
								} else {
									printf( '<li>%s</li>', esc_html( (string) $act['text'] ) );
								}
							}
							echo '</ul>';
						}
					}
					echo '</td></tr>';
				}
				printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html__( 'Requests sampled', 'wpoptimizing' ), esc_html( number_format_i18n( $an['requests'] ) ) );
				printf(
					'<tr><th scope="row">%s</th><td>%s</td></tr>',
					esc_html__( 'Total hits / misses', 'wpoptimizing' ),
					esc_html( number_format_i18n( $an['hits'] ) . ' / ' . number_format_i18n( $an['misses'] ) )
				);
				if ( $an['queries'] > 0 || $an['dups'] > 0 ) {
					printf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html__( 'Duplicate queries avoided', 'wpoptimizing' ),
						esc_html(
							sprintf(
							/* translators: 1: duplicate queries avoided, 2: total read queries. */
								__( '%1$s of %2$s read queries', 'wpoptimizing' ),
								number_format_i18n( $an['dups'] ),
								number_format_i18n( $an['queries'] )
							)
						)
					);
				}
				$ty = $an['types'];
				if ( array_sum( $ty ) > 0 ) {
					$ty_html = '<span class="wpopt-type-badge">SELECT</span> ' . esc_html( number_format_i18n( $ty['sel'] ) )
					. ' &nbsp;<span class="wpopt-type-badge">INSERT</span> ' . esc_html( number_format_i18n( $ty['ins'] ) )
					. ' &nbsp;<span class="wpopt-type-badge">UPDATE</span> ' . esc_html( number_format_i18n( $ty['upd'] ) )
					. ' &nbsp;<span class="wpopt-type-badge">DELETE</span> ' . esc_html( number_format_i18n( $ty['del'] ) );
					printf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html__( 'Read queries by type', 'wpoptimizing' ),
						$ty_html // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped above.
					);
				}
				if ( $an['slow'] > 0 ) {
					printf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html__( 'Slow queries (>50 ms)', 'wpoptimizing' ),
						esc_html( number_format_i18n( $an['slow'] ) )
					);
				}
				if ( $an['ms'] > 0 ) {
					printf(
						'<tr><th scope="row">%s</th><td>%s</td></tr>',
						esc_html__( 'Total query time', 'wpoptimizing' ),
						esc_html(
							sprintf(
							/* translators: %s: total seconds spent running queries. */
								__( '%s s', 'wpoptimizing' ),
								number_format_i18n( $an['ms'] / 1000, 1 )
							)
						)
					);
				}
				echo '</tbody></table>';

				// Which plugins/themes cause the most duplicate queries.
				if ( ! empty( $an['components'] ) ) {
					$this->render_subsection_header( __( 'Duplicate queries by source', 'wpoptimizing' ) );
					echo '<table class="widefat striped wpopt-table-full"><thead><tr>';
					printf( '<th scope="col">%s</th><th class="wpopt-th-label" scope="col">%s</th></tr></thead><tbody>', esc_html__( 'Source', 'wpoptimizing' ), esc_html__( 'Duplicates', 'wpoptimizing' ) );
					$i = 0;
					foreach ( $an['components'] as $name => $n ) {
						if ( $i++ >= 10 ) {
							break;
						}
						printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $this->cache_component_label( $name ) ), esc_html( number_format_i18n( $n ) ) );
					}
					echo '</tbody></table>';
				}

				// Per-day trend as inline SVG charts (same sparkline component as the History
				// tab) instead of a long bar-per-row table — far easier to read at a glance.
				$days = array_slice( $an['days'], -30 );
				if ( count( $days ) > 1 ) {
					$rate_series = array();
					$req_series  = array();
					foreach ( $days as $d ) {
						$t             = (int) $d['h'] + (int) $d['m'];
						$rate_series[] = $t > 0 ? (float) ( $d['h'] / $t * 100 ) : 0.0;
						$req_series[]  = (float) $t;
					}
					$first     = $days[0]['day'];
					$last      = $days[ count( $days ) - 1 ]['day'];
					$fmt       = static function ( $ymd ) {
						$ymd = (string) $ymd;
						return strlen( $ymd ) >= 8 ? substr( $ymd, 0, 4 ) . '-' . substr( $ymd, 4, 2 ) . '-' . substr( $ymd, 6, 2 ) : $ymd;
					};
					$last_rate = (int) round( end( $rate_series ) );
					$last_req  = (int) end( $req_series );

					$this->render_subsection_header( __( 'Per-day trend', 'wpoptimizing' ) );
					echo '<div class="wpopt-cards-grid">';

					echo '<div class="wpopt-card">';
					echo '<div class="wpopt-card-label">' . esc_html__( 'Hit rate', 'wpoptimizing' ) . '</div>';
					echo '<div class="wpopt-card-num">' . esc_html( $last_rate . '%' ) . '</div>';
					echo $this->build_sparkline_svg( $rate_series, '#008a20' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped inline SVG.
					echo '<div class="wpopt-card-sub">' . esc_html( $fmt( $first ) . ' → ' . $fmt( $last ) ) . '</div>';
					echo '</div>';

					echo '<div class="wpopt-card">';
					echo '<div class="wpopt-card-label">' . esc_html__( 'Requests', 'wpoptimizing' ) . '</div>';
					echo '<div class="wpopt-card-num">' . esc_html( number_format_i18n( $last_req ) ) . '</div>';
					echo $this->build_sparkline_svg( $req_series, '#2271b1' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped inline SVG.
					echo '<div class="wpopt-card-sub">' . esc_html( $fmt( $first ) . ' → ' . $fmt( $last ) ) . '</div>';
					echo '</div>';

					echo '</div>';
				}
			}
		} // end Analytics section

		// ===== Query deduplication section =====
		if ( 'dedup' === $csec ) {
			$this->render_section_header(
				__( 'Query deduplication', 'wpoptimizing' ),
				__( 'Installs a db.php drop-in that returns the cached result of identical read queries within the same request (admin and front end), so the same query never hits MySQL twice. Any write clears it immediately, so results are never stale.', 'wpoptimizing' ),
				array(
					'icon' => 'filter',
					'doc'  => 'wpopt-doc-cache',
				)
			);

			if ( $st['db_foreign'] ) {
				$this->render_foreign_dropin( 'db', 'wpopt_cache_db_replace' );
			} else {
				echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-prose">';
				echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
				echo '<input type="hidden" name="tab" value="cache" />';
				if ( ! $st['can_manage'] ) {
					echo '<p class="description">' . esc_html__( 'Only a network administrator can manage this on a multisite network.', 'wpoptimizing' ) . '</p>';
				} elseif ( $st['db_ours'] ) {
					echo '<p class="wpopt-prose"><span class="wpopt-ok">' . esc_html__( 'Query deduplication is active.', 'wpoptimizing' ) . '</span></p>';
					if ( ! empty( $st['db_needs_update'] ) ) {
						submit_button( __( 'Update drop-in', 'wpoptimizing' ), 'primary', 'wpopt_cache_db_install', false );
						echo ' ';
					}
					submit_button( __( 'Disable query deduplication', 'wpoptimizing' ), 'secondary', 'wpopt_cache_db_uninstall', false );
				} else {
					submit_button( __( 'Enable query deduplication', 'wpoptimizing' ), 'primary', 'wpopt_cache_db_install', false );
				}
				echo '</form>';
			}

			// Most-duplicated queries report.
			if ( ! empty( $an['dupes'] ) ) {
				$this->render_subsection_header( __( 'Most duplicated queries', 'wpoptimizing' ) );
				echo '<p class="description wpopt-desc-tight">' . esc_html__( '“Times” is the highest number of times a single page load repeated the exact same read (deduplication collapses them to one hit on MySQL). It is a rolling peak recorded over time, not a fixed window. “Source” is the plugin, theme or WordPress core that fired the query.', 'wpoptimizing' ) . '</p>';
				echo '<table class="widefat striped wpopt-prose-wide"><thead><tr>';
				printf(
					'<th class="wpopt-th-count" scope="col">%s</th><th scope="col">%s</th><th class="wpopt-th-label" scope="col">%s</th></tr></thead><tbody>',
					esc_html__( 'Times', 'wpoptimizing' ),
					esc_html__( 'Query', 'wpoptimizing' ),
					esc_html__( 'Source', 'wpoptimizing' )
				);
				foreach ( array_slice( $an['dupes'], 0, 15 ) as $d ) {
					$d_comp = (string) ( $d['comp'] ?? '' );
					$src    = '' !== $d_comp
						? esc_html( $this->cache_component_label( $d_comp ) )
						: '<span class="wpopt-muted">' . esc_html( '—' ) . '</span>';
					printf(
						'<tr><td>%s×</td><td><code class="wpopt-code-sql">%s</code></td><td>%s</td></tr>',
						esc_html( number_format_i18n( $d['count'] ) ),
						esc_html( $d['sql'] ),
						$src // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html above.
					);
				}
				echo '</tbody></table>';
			}
		} // end Query deduplication section

		// ===== Settings section =====
		if ( 'settings' === $csec ) {
			$this->render_section_header(
				__( 'Cache settings', 'wpoptimizing' ),
				__( 'Choose the cache backend (and the Redis/Memcached connection), plus safe admin-side optimizations.', 'wpoptimizing' ),
				array(
					'icon' => 'forms',
					'doc'  => 'wpopt-doc-cache',
				)
			);
			echo '<form method="post" action="' . esc_url( $base_url ) . '" class="wpopt-cache-form">';
			echo '<input type="hidden" name="' . esc_attr( $this->nonce_field ) . '" value="' . esc_attr( $nonce ) . '" />';
			echo '<input type="hidden" name="tab" value="cache" />';

			$this->render_subsection_header( __( 'Backend', 'wpoptimizing' ), __( 'Auto picks the fastest available backend: Redis, then Memcached, then SQLite. APCu is only used when chosen here, and never from WP-CLI or a system cron, which cannot see its memory.', 'wpoptimizing' ) );

			$choices = array(
				'auto'      => __( 'Auto (recommended)', 'wpoptimizing' ),
				'redis'     => $this->cache_backend_label( 'redis' ),
				'memcached' => $this->cache_backend_label( 'memcached' ),
				'apcu'      => $this->cache_backend_label( 'apcu' ),
				'sqlite'    => $this->cache_backend_label( 'sqlite' ),
			);
			echo '<select name="wpopt_cache_backend" class="wpopt-select2 wpopt-field-std">';
			foreach ( $choices as $val => $label ) {
				$disabled = ( 'auto' !== $val && isset( $st['backends'][ $val ] ) && ! $st['backends'][ $val ] ) ? ' disabled' : '';
				printf(
					'<option value="%s"%s%s>%s</option>',
					esc_attr( $val ),
					selected( $settings['backend'], $val, false ),
					esc_attr( $disabled ),
					esc_html( $label . ( $disabled ? ' — ' . __( 'not available', 'wpoptimizing' ) : '' ) )
				);
			}
			echo '</select>';

			echo '<table class="form-table" role="presentation"><tbody>';
			$hostport_tpl = '<tr><th scope="row">%s</th><td><span class="wpopt-hostport-wrap"><input type="text" class="wpopt-inline wpopt-input-host" name="%s" value="%s" /><span aria-hidden="true">:</span><input type="number" class="wpopt-inline wpopt-input-num" name="%s" value="%d" min="1" max="65535" /></span></td></tr>';
			printf(
				$hostport_tpl, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static template; values escaped below.
				esc_html__( 'Redis host / port', 'wpoptimizing' ),
				'wpopt_cache_redis_host',
				esc_attr( $settings['redis_host'] ),
				'wpopt_cache_redis_port',
				(int) $settings['redis_port']
			);
			printf(
				'<tr><th scope="row">%s</th><td><input type="password" class="wpopt-inline wpopt-input-host" name="wpopt_cache_redis_password" value="%s" autocomplete="new-password" /></td></tr>',
				esc_html__( 'Redis password', 'wpoptimizing' ),
				esc_attr( $settings['redis_password'] )
			);
			printf(
				'<tr><th scope="row">%s</th><td><input type="number" class="wpopt-inline wpopt-input-num" name="wpopt_cache_redis_database" value="%d" min="0" max="255" /> <span class="description">%s</span></td></tr>',
				esc_html__( 'Redis database', 'wpoptimizing' ),
				(int) $settings['redis_database'],
				esc_html__( 'Numeric index (0–255). Leave 0 unless your Redis uses a dedicated database.', 'wpoptimizing' )
			);
			printf(
				$hostport_tpl, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static template; values escaped below.
				esc_html__( 'Memcached host / port', 'wpoptimizing' ),
				'wpopt_cache_memcached_host',
				esc_attr( $settings['memcached_host'] ),
				'wpopt_cache_memcached_port',
				(int) $settings['memcached_port']
			);
			echo '</tbody></table>';

			// --- Admin optimizations (safe, free) ----------------------------
			$this->render_subsection_header( __( 'Admin optimizations', 'wpoptimizing' ), __( 'Safe admin-side speedups. These never cache full admin pages (which would break security tokens).', 'wpoptimizing' ) );
			echo '<p class="wpopt-form-check"><label class="wpopt-toggle"><input type="checkbox" class="wpopt-toggle-input" name="wpopt_cache_admin_counts" value="1"' . checked( ! empty( $settings['admin_counts'] ), true, false ) . ' />'
			. '<span class="wpopt-toggle-track" aria-hidden="true"><span class="wpopt-toggle-knob"></span></span>'
			. '<span class="wpopt-toggle-label">' . esc_html__( 'Cache dashboard comment counts (At a Glance / menu bubble). Refreshed instantly when comments change.', 'wpoptimizing' ) . '</span></label></p>';

			// Heartbeat is set by area under Performance › Server. The preset travels unchanged:
			// a missing field would read as «default» and reset every area on save.
			echo '<input type="hidden" name="wpopt_cache_heartbeat" value="' . esc_attr( (string) $settings['heartbeat'] ) . '" />';
			$hb_url = add_query_arg(
				array(
					'page' => 'wpoptimizing',
					'tab'  => 'performance',
					'psec' => 'server',
				),
				admin_url( 'admin.php' )
			);
			echo '<p><strong>' . esc_html__( 'Heartbeat by area', 'wpoptimizing' ) . '</strong>: <a href="' . esc_url( $hb_url ) . '">' . esc_html__( 'Performance', 'wpoptimizing' ) . ' › ' . esc_html__( 'Server', 'wpoptimizing' ) . '</a></p>';

			submit_button( __( 'Save cache settings', 'wpoptimizing' ), 'primary wpopt-save-btn', 'wpopt_cache_settings', false );
			echo '</form>';
		} // end Settings section

		// ===== Cloudflare section =====
		if ( 'cloudflare' === $csec ) {
			$this->render_cloudflare_settings( $base_url, $nonce );
		}
	}

	/**
	 * Subsection nav link for the Cache tab.
	 *
	 * @param string $key   Subsection key.
	 * @param string $label Label.
	 * @param string $curr  Current subsection.
	 * @return void
	 */
	protected function cache_menu_link( $key, $label, $curr ) {
		$this->subsection_menu_link( 'cache', 'csec', $key, $label, $curr );
	}

	/**
	 * A link of a tab's sub-section menu (Cache, Performance). One renderer, so every tab's
	 * sub-menu looks and behaves the same.
	 *
	 * @param string $tab   Tab.
	 * @param string $param Query arg that selects the sub-section.
	 * @param string $key   Sub-section key.
	 * @param string $label Label.
	 * @param string $curr  Current sub-section.
	 * @return void
	 */
	protected function subsection_menu_link( $tab, $param, $key, $label, $curr ) {
		$url = add_query_arg(
			array(
				'page' => 'wpoptimizing',
				'tab'  => $tab,
				$param => $key,
			),
			admin_url( 'admin.php' )
		);
		printf(
			'<li><a href="%s" class="%s">%s</a></li>',
			esc_url( $url ),
			$curr === $key ? 'current' : '',
			esc_html( $label )
		);
	}

	/**
	 * Human label for a query-source component string produced by db.php
	 * ("plugin:slug" / "theme:slug" / "mu-plugin" / "core"). Resolves slugs to
	 * the real plugin/theme name, e.g. "plugin:woocommerce" → "Plugin: WooCommerce".
	 *
	 * @param string $component Raw component string.
	 * @return string
	 */
	protected function cache_component_label( $component ) {
		$component = (string) $component;
		if ( 'core' === $component ) {
			return __( 'WordPress core', 'wpoptimizing' );
		}
		if ( 'mu-plugin' === $component ) {
			return __( 'Must-use plugin', 'wpoptimizing' );
		}
		if ( 0 === strpos( $component, 'plugin:' ) ) {
			/* translators: %s: plugin name. */
			return sprintf( __( 'Plugin: %s', 'wpoptimizing' ), $this->plugin_name_from_slug( substr( $component, 7 ) ) );
		}
		if ( 0 === strpos( $component, 'theme:' ) ) {
			$slug  = substr( $component, 6 );
			$theme = wp_get_theme( $slug );
			$name  = ( $theme && $theme->exists() ) ? (string) $theme->get( 'Name' ) : $slug;
			/* translators: %s: theme name. */
			return sprintf( __( 'Theme: %s', 'wpoptimizing' ), $name );
		}
		return $component;
	}

	/**
	 * Resolve a plugin folder slug to its display name (cached).
	 *
	 * @param string $slug Plugin folder slug (or single-file basename).
	 * @return string Plugin name, or the slug if unknown.
	 */
	protected function plugin_name_from_slug( $slug ) {
		static $map = null;
		if ( null === $map ) {
			$map = array();
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			foreach ( get_plugins() as $file => $data ) {
				$dir = dirname( $file );
				$key = ( '.' === $dir ) ? preg_replace( '/\.php$/', '', basename( $file ) ) : $dir;
				if ( '' !== $key && ! isset( $map[ $key ] ) && ! empty( $data['Name'] ) ) {
					$map[ $key ] = (string) $data['Name'];
				}
			}
		}
		return isset( $map[ (string) $slug ] ) ? $map[ (string) $slug ] : (string) $slug;
	}

	/**
	 * Human label for a backend key.
	 *
	 * @param string $name Backend key.
	 * @return string
	 */
	protected function cache_backend_label( $name ) {
		switch ( $name ) {
			case 'redis':
				return 'Redis';
			case 'memcached':
				return 'Memcached';
			case 'apcu':
				return 'APCu';
			case 'sqlite':
				return 'SQLite';
			case 'array':
				return __( 'None (per-request only)', 'wpoptimizing' );
		}
		return $name;
	}
}
