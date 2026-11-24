<?php
/**
 * Settings portability: export the plugin configuration to a JSON file and
 * import it back here or on another site.
 *
 * An ALLOWLIST, not "every prefixed option": only the settings listed in
 * wpoptimizing_portable_option_sanitizers() travel, each through its own
 * sanitizer, on the way out and on the way in. It used to be the other way
 * round — everything with our prefix minus a denylist — and every option added
 * later was portable by default: the licence grace stamp (importing a far
 * future date kept the grace period open for ever), the PageSpeed API key,
 * webhook endpoints with their HMAC secrets, the data-sharing consent, the
 * Redis password inside the cache settings. A new option now stays put until
 * somebody decides it should move and writes its sanitizer.
 *
 * Pure-ish helpers kept out of the bootstrap so the test suite can load them
 * in isolation.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// The page lifetime policy and the «also purge» list parser (page-cache settings below).
require_once dirname( __DIR__ ) . '/content-purge.php';

/**
 * Options that must NEVER travel between sites, whatever the allowlist or its filter say.
 *
 * This is the second lock, applied after the allowlist filter, so an add-on that widens the
 * allowlist still cannot make one of these portable:
 *  - Secrets and per-site identity: licence key, status and last check (its grace stamp),
 *    data tokens, cache salt, PageSpeed API key, Cloudflare token, webhook endpoints (they
 *    carry HMAC secrets and decide where this site's data is sent), the licence-supplied
 *    white label.
 *  - Consent: data sharing is switched on by a person on this site, never by a file.
 *  - Runtime / measured state: scan progress, baselines, last-run stamps, cached sizes,
 *    autoload tally, drop-in install flags, the lead queue, runtime-map signatures.
 *
 * Extend via the `wpoptimizing_settings_export_denylist` filter (it can add, not remove).
 *
 * @since 0.1.0
 * @return string[] Option names never exported/imported.
 */
function wpoptimizing_settings_export_denylist() {
	$deny = array(
		'wpoptimizing_data_token',
		'wpoptimizing_data_token_site',
		'wpoptimizing_cache_salt',
		'wpoptimizing_psi_key',
		'wpoptimizing_webhooks',
		// Consent is given on this site, by a person.
		'wpoptimizing_share_consent',
		'wpoptimizing_share_consent_prelicense',
		'wpoptimizing_share_nudge_snooze',
		// Cloudflare: the zone belongs to this domain and the option carries the API token.
		'wpoptimizing_cloudflare',
		'wpoptimizing_cloudflare_active',
		'wpoptimizing_cloudflare_last',
		// Drop-in install flags (the drop-in files are per-site, not config).
		'wpoptimizing_cache_dropin',
		'wpoptimizing_db_dropin',
		// Internal / first-run bookkeeping.
		'wpoptimizing_first_run',
		'wpoptimizing_cap_version',
		'wpoptimizing_manual_lead_queue',
		'wpopt_rtmap_sig',
		'wpopt_rtmap_settings_sig',
		// Runtime ownership evidence (measured per-site, can reach ~512 KB).
		'wpoptimizing_runtime_evidence',
		// Scan runtime state.
		'wpoptimizing_scan_state',
		'wpoptimizing_scan_started_at',
		'wpoptimizing_scan_failed_at',
		'wpoptimizing_scan_scheduled_reason',
		'wpoptimizing_last_scan',
		'wpoptimizing_last_scan_reason',
		// Autoload measurement tally.
		'wpoptimizing_autoload_aloaded',
		'wpoptimizing_autoload_count',
		'wpoptimizing_autoload_ensure_ts',
		'wpoptimizing_autoload_reads',
		'wpoptimizing_autoload_size',
		'wpoptimizing_autoload_track_until',
		// Measured baselines / cached sizes / last reports.
		'wpoptimizing_autoclean_baseline_bytes',
		'wpoptimizing_gc_task_last_run',
		'wpoptimizing_general_cleanup_last_report',
		'wpoptimizing_general_cleanup_last_run',
		'wpoptimizing_general_cleanup_last_stats',
		'wpoptimizing_general_cleanup_reports',
		'wpoptimizing_history_baseline_ts',
		'wpoptimizing_image_copies_present',
		'wpoptimizing_media_dupes_bytes',
		'wpoptimizing_media_orphan_bytes',
	);

	/**
	 * Filters the options excluded from settings export/import. The built-in entries are
	 * always kept: the filter can add to the list, not take a secret off it.
	 *
	 * @since 0.1.0
	 * @param string[] $deny Option names never exported/imported.
	 */
	$deny = array_merge( $deny, (array) apply_filters( 'wpoptimizing_settings_export_denylist', $deny ) );

	return array_values( array_unique( array_filter( array_map( 'strval', $deny ) ) ) );
}

/**
 * Portable options that configure something the whole network shares on a multisite: the
 * object-cache sidecar, the page-cache config and the must-use plugin-rules file. A sub-site
 * administrator holds this plugin's capability, so importing these is gated on the same
 * check the settings screens use (can_manage_dropin() / wpoptimizing_user_can_manage_dropin()).
 *
 * @since 0.1.0
 * @return string[]
 */
function wpoptimizing_settings_dropin_keys() {
	return array( 'wpoptimizing_cache_settings', 'wpoptimizing_page_cache', 'wpoptimizing_plugin_rules' );
}

/**
 * Whether an option key is a portable WPOptimizing setting: on the allowlist (it has a
 * sanitizer) and not on the denylist. Single source of truth shared by export and import.
 *
 * @since 0.1.0
 * @param string $key Option name.
 * @return bool
 */
function wpoptimizing_is_portable_option( $key ) {
	$key = (string) $key;
	if ( '' === $key ) {
		return false;
	}
	$map = wpoptimizing_portable_option_sanitizers();
	return isset( $map[ $key ] );
}

/**
 * Build the settings export payload: a versioned envelope plus the sanitized value of every
 * allowlisted option that exists on this site. A secret held inside an option (the Redis
 * password) is dropped by that option's sanitizer, so it never reaches the file.
 *
 * @since 0.1.0
 * @return array{_wpoptimizing_export:array<string,mixed>,options:array<string,mixed>}
 */
function wpoptimizing_export_settings_payload() {
	$options  = array();
	$sentinel = '__wpopt_absent__';
	foreach ( wpoptimizing_portable_option_sanitizers() as $key => $sanitize ) {
		$value = get_option( $key, $sentinel );
		if ( $sentinel === $value ) {
			continue;
		}
		$options[ $key ] = call_user_func( $sanitize, $value );
	}
	ksort( $options );

	return array(
		'_wpoptimizing_export' => array(
			'plugin'   => 'wpoptimizing',
			'version'  => defined( 'WPOPTIMIZING_VERSION' ) ? WPOPTIMIZING_VERSION : '',
			'exported' => gmdate( 'c' ),
			'site'     => home_url( '/' ),
			'schema'   => 1,
		),
		'options'              => $options,
	);
}

/**
 * Fold what this site owns back into an imported, already-sanitized value, or say the key
 * must be skipped (null).
 *
 * - Object cache: the Redis password never travels, so the one stored here is kept. Without
 *   the network capability the connection fields (written into the network-wide sidecar)
 *   keep their current values and only the per-site ones are imported — exactly how the
 *   Cache screen saves them.
 * - Page cache and plugin rules: network-wide files, so skipped without the capability.
 *   The page cache's 'enabled' flag says whether the drop-in is installed HERE: kept.
 *
 * @since 0.1.0
 * @param string $key               Option name.
 * @param mixed  $value             Sanitized imported value.
 * @param bool   $can_manage_dropin Whether the importing user may configure network-wide files.
 * @return mixed|null
 */
function wpoptimizing_portable_keep_local( $key, $value, $can_manage_dropin ) {
	if ( 'wpoptimizing_cache_settings' === $key ) {
		$current = get_option( 'wpoptimizing_cache_settings', array() );
		$current = is_array( $current ) ? $current : array();
		$keep    = $can_manage_dropin
			? array( 'redis_password' )
			: array( 'backend', 'redis_host', 'redis_port', 'redis_password', 'redis_database', 'memcached_host', 'memcached_port' );
		foreach ( $keep as $field ) {
			if ( array_key_exists( $field, $current ) ) {
				$value[ $field ] = $current[ $field ];
			} else {
				unset( $value[ $field ] );
			}
		}
		return $value;
	}
	if ( ! $can_manage_dropin && in_array( $key, wpoptimizing_settings_dropin_keys(), true ) ) {
		return null;
	}
	if ( 'wpoptimizing_page_cache' === $key ) {
		$current          = get_option( 'wpoptimizing_page_cache', array() );
		$value['enabled'] = is_array( $current ) && ! empty( $current['enabled'] );
		// Serving without PHP is this site's .htaccess too, not a preference: it is kept, never
		// set or dropped by a file. Dropping it left the rules in place with nothing to take
		// them out (Codex, 06/11/2026).
		$value['static'] = is_array( $current ) && ! empty( $current['static'] );
		foreach ( array( 'static_offset', 'static_checked', 'static_rules' ) as $local ) {
			if ( is_array( $current ) && isset( $current[ $local ] ) ) {
				$value[ $local ] = $current[ $local ];
			}
		}
	}
	return $value;
}

/**
 * Apply an imported settings payload. Writes only allowlisted options, each through its
 * sanitizer; secrets, runtime state and anything not ours are reported as skipped, so a
 * tampered or foreign file can never set arbitrary or sensitive options. Returns a report
 * the UI turns into a flash notice.
 *
 * @since 0.1.0
 * @param mixed     $payload           Decoded JSON (associative array expected).
 * @param bool|null $can_manage_dropin Whether the importing user may configure what the whole
 *                                     network shares; null asks wpoptimizing_user_can_manage_dropin().
 * @return array{ok:bool,error:string,applied:string[],skipped:string[]}
 */
function wpoptimizing_import_settings_payload( $payload, $can_manage_dropin = null ) {
	$report = array(
		'ok'      => false,
		'error'   => '',
		'applied' => array(),
		'skipped' => array(),
	);

	if ( ! is_array( $payload ) || ! isset( $payload['options'] ) || ! is_array( $payload['options'] ) ) {
		$report['error'] = 'shape';
		return $report;
	}
	$meta = isset( $payload['_wpoptimizing_export'] ) && is_array( $payload['_wpoptimizing_export'] ) ? $payload['_wpoptimizing_export'] : array();
	if ( isset( $meta['plugin'] ) && 'wpoptimizing' !== $meta['plugin'] ) {
		$report['error'] = 'foreign';
		return $report;
	}
	if ( null === $can_manage_dropin ) {
		$can_manage_dropin = ! is_multisite() || ( function_exists( 'wpoptimizing_user_can_manage_dropin' ) && wpoptimizing_user_can_manage_dropin() );
	}

	$sanitizers = wpoptimizing_portable_option_sanitizers();
	foreach ( $payload['options'] as $key => $value ) {
		$key = (string) $key;
		if ( ! isset( $sanitizers[ $key ] ) ) {
			$report['skipped'][] = $key;
			continue;
		}
		$value = wpoptimizing_portable_keep_local( $key, call_user_func( $sanitizers[ $key ], $value ), (bool) $can_manage_dropin );
		if ( null === $value ) {
			$report['skipped'][] = $key;
			continue;
		}
		// No explicit autoload here, on purpose. Every other update_option() in the plugin
		// states it, because we know where that option is read; passing a flag here would
		// overwrite the autoload the option's own writer chose. Leaving it out keeps the stored
		// value for options that exist and lets core size-check new ones. Do not "fix" this.
		update_option( $key, $value );
		$report['applied'][] = $key;
	}

	$report['ok'] = ! empty( $report['applied'] );
	if ( $report['ok'] ) {
		/**
		 * Fires after an import wrote settings, so what runs from them (the page cache's sidecar
		 * and rules) catches up.
		 *
		 * @since 0.1.0
		 * @param string[] $applied Options written.
		 */
		do_action( 'wpoptimizing_settings_imported', $report['applied'] );
	}
	return $report;
}

/**
 * Clean a list of short strings (exclusion lists and the like): scalars only, through
 * sanitize_text_field(), no empties or duplicates, capped.
 *
 * @since 0.1.0
 * @param mixed $value Raw list (array, or a newline-separated string).
 * @param int   $max   Maximum entries kept.
 * @return string[]
 */
function wpoptimizing_portable_text_list( $value, $max = 200 ) {
	if ( is_string( $value ) ) {
		$value = preg_split( '/[\r\n]+/', $value );
	}
	$out = array();
	foreach ( (array) $value as $item ) {
		if ( ! is_scalar( $item ) ) {
			continue;
		}
		$item = sanitize_text_field( (string) $item );
		if ( '' !== $item ) {
			$out[] = $item;
		}
	}
	return array_slice( array_values( array_unique( $out ) ), 0, $max );
}

/**
 * Database table names from an imported list: identifier characters only ([A-Za-z0-9_],
 * which is all a WordPress table prefix may contain), no empties or duplicates.
 *
 * @since 0.1.0
 * @param mixed $value Raw list.
 * @return string[]
 */
function wpoptimizing_portable_table_names( $value ) {
	$out = array();
	foreach ( (array) $value as $item ) {
		if ( is_scalar( $item ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', (string) $item ) ) {
			$out[] = (string) $item;
		}
	}
	return array_values( array_unique( $out ) );
}

/**
 * The ALLOWLIST of portable settings: option name => sanitizer. Only these options are
 * exported and imported, and every value goes through its sanitizer both ways — on export
 * that is what keeps a secret held inside an option (the Redis password) out of the file.
 * Most reuse the routine the normal save handler uses.
 *
 * Extend via `wpoptimizing_portable_option_sanitizers`. Whatever the filter adds, an entry
 * that is not one of ours, not callable, or on the denylist is dropped.
 *
 * @since 0.1.0
 * @return array<string,callable>
 */
function wpoptimizing_portable_option_sanitizers() {
	$map       = array();
	$int_clamp = static function ( $min, $max ) {
		return static function ( $value ) use ( $min, $max ) {
			return max( $min, min( $max, is_scalar( $value ) ? (int) $value : $min ) );
		};
	};
	$bool01    = static function ( $value ) {
		return empty( $value ) ? 0 : 1;
	};
	$as_array  = static function ( $value ) {
		return is_array( $value ) ? $value : array();
	};

	if ( function_exists( 'wpoptimizing_frontend_perf_sanitize' ) ) {
		$map['wpoptimizing_frontend_perf'] = static function ( $value ) use ( $as_array ) {
			return wpoptimizing_frontend_perf_sanitize( $as_array( $value ) );
		};
	}
	if ( function_exists( 'wpoptimizing_core_tweaks_sanitize' ) ) {
		$map['wpoptimizing_core_tweaks'] = static function ( $value ) use ( $as_array ) {
			return wpoptimizing_core_tweaks_sanitize( $as_array( $value ) );
		};
	}

	// General cleanup. Schedule options re-arm cron on import, so they MUST land within valid
	// ranges — reuse the cleanup engine's own clamps (single source of truth).
	if ( class_exists( 'WPOPT_General_Cleanup' ) ) {
		$map['wpoptimizing_general_cleanup_schedule'] = static function ( $value ) {
			$value = is_scalar( $value ) ? (string) $value : '';
			return in_array( $value, WPOPT_General_Cleanup::get_valid_schedules(), true ) ? $value : 'manual';
		};
		$map['wpoptimizing_gc_schedule_config']       = static function ( $value ) use ( $as_array ) {
			return WPOPT_General_Cleanup::sanitize_schedule_config( $as_array( $value ) );
		};
		$map['wpoptimizing_gc_task_schedules']        = static function ( $value ) use ( $as_array ) {
			return WPOPT_General_Cleanup::sanitize_task_schedules( $as_array( $value ) );
		};
		$map['wpoptimizing_general_cleanup_profile']  = static function ( $value ) {
			$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
			return isset( WPOPT_General_Cleanup::profile_settings_map()[ $value ] ) ? $value : '';
		};
	}
	// The task toggles and limits: integers here, clamped to their ranges by get_settings()
	// on every read. Table exclusions are identifiers only.
	$map['wpoptimizing_general_cleanup_settings'] = static function ( $value ) use ( $as_array ) {
		$out = array();
		foreach ( $as_array( $value ) as $k => $v ) {
			$k = (string) $k;
			if ( 'excluded_optimize_tables' === $k ) {
				$out[ $k ] = wpoptimizing_portable_table_names( $v );
			} elseif ( preg_match( '/^[a-z0-9_]{1,64}$/', $k ) && is_scalar( $v ) ) {
				$out[ $k ] = (int) $v;
			}
		}
		return $out;
	};

	$map['wpoptimizing_autoclean_growth_mb'] = $int_clamp( 0, 100000 );

	// Automatic background-analysis schedule + off-peak window (re-armed after import).
	$map['wpoptimizing_scan_schedule']      = static function ( $value ) {
		$value = is_scalar( $value ) ? (string) $value : '';
		return in_array( $value, array( 'off', 'daily', 'weekly' ), true ) ? $value : 'daily';
	};
	$map['wpoptimizing_scan_offpeak_start'] = $int_clamp( 0, 23 );
	$map['wpoptimizing_scan_offpeak_end']   = $int_clamp( 0, 23 );

	// Backups and history.
	$map['wpoptimizing_backup_max_per_type'] = $int_clamp( 1, 100 );
	$map['wpoptimizing_backup_max_days']     = $int_clamp( 1, 3650 );
	$map['wpoptimizing_backup_max_mb']       = $int_clamp( 0, 1000000 );
	$map['wpoptimizing_history_retention']   = static function ( $value ) {
		$value = is_scalar( $value ) ? (int) $value : 3;
		return in_array( $value, array( 0, 3, 6, 12 ), true ) ? $value : 3;
	};
	if ( defined( 'WPOPT_REPORT_FREQ_OPTION' ) && function_exists( 'wpoptimizing_report_frequencies' ) ) {
		$map[ WPOPT_REPORT_FREQ_OPTION ] = static function ( $value ) {
			$value = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
			return isset( wpoptimizing_report_frequencies()[ $value ] ) ? $value : 'off';
		};
	}
	if ( defined( 'WPOPT_SWEEPER_OPTION' ) ) {
		$map[ WPOPT_SWEEPER_OPTION ] = $bool01;
	}
	if ( defined( 'WPOPT_PROTECTED_ITEMS_OPTION' ) && function_exists( 'wpoptimizing_protected_items_normalize' ) ) {
		$map[ WPOPT_PROTECTED_ITEMS_OPTION ] = static function ( $value ) use ( $as_array ) {
			return wpoptimizing_protected_items_normalize( $as_array( $value ) );
		};
	}

	// Media / images. Image settings drive big_image_size_threshold on upload, so a bad import
	// must not land a junk max_width/max_height — reuse the getter's pure normalizer.
	if ( function_exists( 'wpoptimizing_image_settings_normalize' ) ) {
		$map['wpoptimizing_image_settings'] = 'wpoptimizing_image_settings_normalize';
	}
	// Attachments the owner marked as known: only ever removes items from the "unused" list.
	$map['wpoptimizing_trusted_attachments'] = static function ( $value ) use ( $as_array ) {
		$ids = array();
		foreach ( $as_array( $value ) as $id ) {
			if ( is_scalar( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		return array_values( array_unique( $ids ) );
	};

	// Object cache: the backend and its connection details, the per-site admin tweaks. The
	// Redis password is left out here, so it is not exported; the import keeps the local one
	// (wpoptimizing_portable_keep_local()).
	$map['wpoptimizing_cache_settings'] = static function ( $value ) use ( $as_array ) {
		$value = $as_array( $value );
		$str   = static function ( $v, $default ) {
			return is_scalar( $v ) ? sanitize_text_field( (string) $v ) : $default;
		};
		$port  = static function ( $v, $default ) {
			$v = is_scalar( $v ) ? (int) $v : $default;
			return ( $v > 0 && $v <= 65535 ) ? $v : $default;
		};
		$out   = array();
		if ( isset( $value['backend'] ) ) {
			$out['backend'] = is_scalar( $value['backend'] ) ? sanitize_key( (string) $value['backend'] ) : 'auto';
		}
		if ( isset( $value['redis_host'] ) ) {
			$out['redis_host'] = $str( $value['redis_host'], '127.0.0.1' );
		}
		if ( isset( $value['redis_port'] ) ) {
			$out['redis_port'] = $port( $value['redis_port'], 6379 );
		}
		if ( isset( $value['redis_database'] ) ) {
			$out['redis_database'] = max( 0, min( 255, is_scalar( $value['redis_database'] ) ? (int) $value['redis_database'] : 0 ) );
		}
		if ( isset( $value['memcached_host'] ) ) {
			$out['memcached_host'] = $str( $value['memcached_host'], '127.0.0.1' );
		}
		if ( isset( $value['memcached_port'] ) ) {
			$out['memcached_port'] = $port( $value['memcached_port'], 11211 );
		}
		if ( isset( $value['admin_counts'] ) ) {
			$out['admin_counts'] = empty( $value['admin_counts'] ) ? 0 : 1;
		}
		if ( isset( $value['heartbeat'] ) ) {
			$out['heartbeat'] = in_array( $value['heartbeat'], array( 'default', 'slow', 'off' ), true ) ? $value['heartbeat'] : 'default';
		}
		// The Heartbeat per zone. A file from before the zones has only the word above, and the
		// save turns it into the zones it always meant (wpoptimizing_heartbeat_before_save()).
		foreach ( array(
			'admin'  => 'heartbeat_admin',
			'editor' => 'heartbeat_editor',
			'front'  => 'heartbeat_front',
		) as $zone => $key ) {
			if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) && function_exists( 'wpoptimizing_heartbeat_zone_value' ) ) {
				$out[ $key ] = wpoptimizing_heartbeat_zone_value( $value[ $key ], $zone );
			}
		}
		return $out;
	};
	// Page cache: preferences only. 'enabled' is the install state of this site's drop-in.
	$map['wpoptimizing_page_cache'] = static function ( $value ) use ( $as_array ) {
		$value = $as_array( $value );
		$out   = array();
		// The page types never cached (reject_*) travel too; importing purges the whole cache
		// (wpoptimizing_page_cache_after_import()), so copies of newly excluded pages go.
		// 'stats' (hit diagnostics) travels as the switch only; the counters' folder token stays
		// in its own option, so a file never points one site at another's folder.
		// Compression switches are flags: brotli joins 'gzip' here when it exists (fase 4 B).
		// The free edition keeps only what its page cache uses (24/11/2026): a file or an import
		// from Pro, or from another plugin, cannot leave Pro switches stored in it.
		$flags = array( 'gzip', 'mobile_separate', 'reject_front', 'reject_home', 'reject_archives', 'reject_paged' );
		$lists = array( 'reject_uri', 'reject_cookies', 'query_whitelist', 'reject_ua', 'ignored_query' );
		foreach ( $flags as $flag ) {
			if ( isset( $value[ $flag ] ) ) {
				$out[ $flag ] = ! empty( $value[ $flag ] );
			}
		}
		// The page-cache screen's policy (1 to 12 whole hours), the same one the importers use:
		// a file cannot bring a lifetime the screen would not offer.
		if ( isset( $value['ttl'] ) ) {
			$out['ttl'] = wpoptimizing_page_cache_ttl_policy( is_scalar( $value['ttl'] ) ? (int) $value['ttl'] : 0 )['ttl'];
		}
		foreach ( $lists as $list ) {
			if ( isset( $value[ $list ] ) ) {
				$out[ $list ] = wpoptimizing_portable_text_list( $value[ $list ] );
			}
		}
		// Content type slugs. Kept even when this site does not register one (yet): a type a
		// plugin adds later is excluded as soon as it exists, as the source site meant.
		if ( isset( $value['reject_post_types'] ) ) {
			$out['reject_post_types'] = array_values( array_unique( array_filter( array_map( 'sanitize_key', wpoptimizing_portable_text_list( $value['reject_post_types'], 100 ) ) ) ) );
		}
		return $out;
	};
	// Plugins by URL: rules are re-checked against THIS site's active plugins (and, through
	// wpoptimizing_plugin_rules_active_plugins(), the importing user's right to touch network
	// plugins). They always arrive switched off: saving them is gated on a live test with
	// real requests, and a file must not skip that. The admin runs "Test and save".
	if ( function_exists( 'wpoptimizing_plugin_rules_sanitize' ) && function_exists( 'wpoptimizing_plugin_rules_active_plugins' ) ) {
		$map['wpoptimizing_plugin_rules'] = static function ( $value ) use ( $as_array ) {
			$value  = $as_array( $value );
			$posted = array();
			foreach ( $as_array( $value['rules'] ?? array() ) as $rule ) {
				if ( ! is_array( $rule ) || ! isset( $rule['plugin'] ) || ! is_scalar( $rule['plugin'] ) ) {
					continue;
				}
				$posted[ (string) $rule['plugin'] ] = array(
					'mode'     => is_scalar( $rule['mode'] ?? null ) ? (string) $rule['mode'] : '',
					'patterns' => implode( "\n", wpoptimizing_portable_text_list( $rule['patterns'] ?? array(), 50 ) ),
				);
			}
			$owner = defined( 'WPOPTIMIZING_BASENAME' ) ? WPOPTIMIZING_BASENAME : '';
			return array(
				'enabled' => false,
				'owner'   => $owner,
				'rules'   => wpoptimizing_plugin_rules_sanitize( $posted, array_keys( wpoptimizing_plugin_rules_active_plugins() ), $owner ),
				'test'    => array(),
				'tripped' => array(),
			);
		};
	}
	if ( function_exists( 'wpoptimizing_cdn_sanitize' ) ) {
		$map['wpoptimizing_cdn'] = static function ( $value ) use ( $as_array ) {
			return wpoptimizing_cdn_sanitize( $as_array( $value ) );
		};
	}

	// Smart Assets (manual unloading) and CSS/JS optimisation.
	$map['wpoptimizing_assets_unload_enabled'] = $bool01;
	$map['wpoptimizing_assets_test_mode']      = $bool01;
	if ( function_exists( 'wpoptimizing_assets_sanitize_rule' ) ) {
		$map['wpoptimizing_assets_rules'] = static function ( $value ) use ( $as_array ) {
			$out = array();
			foreach ( $as_array( $value ) as $rule ) {
				$rule = wpoptimizing_assets_sanitize_rule( $rule );
				if ( null !== $rule ) {
					$out[] = $rule;
				}
			}
			return $out;
		};
	}
	if ( function_exists( 'wpoptimizing_assets_normalize_url_patterns' ) ) {
		$map['wpoptimizing_assets_url_exclusions'] = static function ( $value ) {
			return implode( "\n", wpoptimizing_assets_normalize_url_patterns( is_array( $value ) || is_string( $value ) ? $value : '' ) );
		};
	}
	// Minification/defer/async: the critical CSS is inlined on every page, so it goes through
	// the same </style> guard the settings screen uses (or is dropped without it).
	$map['wpoptimizing_asset_optimize'] = static function ( $value ) use ( $as_array ) {
		$value = $as_array( $value );
		$out   = array();
		foreach ( array( 'minify_css', 'minify_js', 'test_mode', 'defer_js', 'async_css' ) as $flag ) {
			if ( isset( $value[ $flag ] ) ) {
				$out[ $flag ] = ! empty( $value[ $flag ] );
			}
		}
		foreach ( array( 'exclude', 'defer_exclude', 'async_css_exclude' ) as $list ) {
			if ( isset( $value[ $list ] ) ) {
				$out[ $list ] = wpoptimizing_portable_text_list( $value[ $list ] );
			}
		}
		if ( isset( $value['critical_css'] ) ) {
			$out['critical_css'] = ( is_string( $value['critical_css'] ) && function_exists( 'wpoptimizing_assetmin_sanitize_critical' ) )
				? trim( wpoptimizing_assetmin_sanitize_critical( $value['critical_css'] ) )
				: '';
		}
		return $out;
	};
	// Delaying JavaScript decides what runs on every visitor's page, and its getter merges
	// defaults without re-sanitising — so the import is the only place a junk profile or an
	// unbounded safety timer can be stopped. Reuse the settings screen's own sanitizer.
	if ( function_exists( 'wpoptimizing_delayjs_sanitize' ) ) {
		$map['wpoptimizing_delay_js'] = static function ( $value ) use ( $as_array ) {
			return wpoptimizing_delayjs_sanitize( $as_array( $value ) );
		};
	}
	// Critical CSS: the artefacts themselves are files, but 'applied' decides whether they are
	// inlined for every visitor and an imported file must not switch that on by itself.
	$map['wpoptimizing_ccss'] = static function ( $value ) use ( $as_array ) {
		$value = $as_array( $value );
		return array(
			// Never imported as live. The artefacts belong to the site that generated them,
			// against its own theme and plugins; applying another site's would inline the
			// wrong CSS on every page.
			'applied'     => false,
			'fingerprint' => '',
			'exclude'     => wpoptimizing_portable_text_list( $value['exclude'] ?? array() ), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- our own settings key, not a WP_Query argument.
			'templates'   => array(),
		);
	};

	// Attribution and compatibility.
	$map['wpoptimizing_manual_attributions'] = static function ( $value ) use ( $as_array ) {
		$out = array();
		foreach ( $as_array( $value ) as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$match = is_scalar( $rule['match'] ?? null ) ? trim( (string) $rule['match'] ) : '';
			$slug  = is_scalar( $rule['slug'] ?? null ) ? sanitize_title( (string) $rule['slug'] ) : '';
			if ( '' === $match || '' === $slug ) {
				continue;
			}
			$type  = is_scalar( $rule['type'] ?? null ) ? (string) $rule['type'] : '';
			$out[] = array(
				'scope' => ( 'exact' === ( $rule['scope'] ?? '' ) ) ? 'exact' : 'contains',
				'match' => $match,
				'slug'  => $slug,
				'name'  => is_scalar( $rule['name'] ?? null ) ? sanitize_text_field( (string) $rule['name'] ) : '',
				'type'  => function_exists( 'wpoptimizing_manual_attribution_type' ) ? wpoptimizing_manual_attribution_type( $type ) : sanitize_key( $type ),
			);
		}
		return $out;
	};
	if ( function_exists( 'wpoptimizing_compat_capabilities' ) ) {
		$map['wpoptimizing_compat_overrides'] = static function ( $value ) use ( $as_array ) {
			$known = wpoptimizing_compat_capabilities();
			$out   = array();
			foreach ( array_keys( $as_array( $value ) ) as $cap ) {
				if ( isset( $known[ $cap ] ) ) {
					$out[ $cap ] = true;
				}
			}
			return $out;
		};
	}

	/**
	 * Filters the allowlist of portable settings and their sanitizers.
	 *
	 * @since 0.1.0
	 * @param array<string,callable> $map Option name => sanitizer callable.
	 */
	$map  = (array) apply_filters( 'wpoptimizing_portable_option_sanitizers', $map );
	$deny = array_fill_keys( wpoptimizing_settings_export_denylist(), true );
	foreach ( $map as $key => $sanitize ) {
		$key = (string) $key;
		if ( isset( $deny[ $key ] ) || ! is_callable( $sanitize )
			|| ( 0 !== strpos( $key, 'wpoptimizing_' ) && 0 !== strpos( $key, 'wpopt_' ) ) ) {
			unset( $map[ $key ] );
		}
	}
	return $map;
}
