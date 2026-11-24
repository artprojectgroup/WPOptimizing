<?php
/**
 * Import source: W3 Total Cache.
 *
 * Its configuration is wp-content/w3tc-config/master.php: the 14 bytes `<?php exit; ?>` and then
 * one flat JSON object `key => value` (ConfigUtil.php:99-110, read back in Config.php:121-122).
 * Older installs have master.json (plain JSON); with W3TC_CONFIG_DATABASE it is the option
 * `w3tc_config_0` (ConfigDbStorage.php:141-171). The file is read as text, never included.
 * Keys read in W3 Total Cache 2.11.0 (ConfigKeys.php; docs/cache-2026-11-06/importadores-A.md §4).
 *
 * The master config holds every key (ConfigCompiler.php:254-262), but a hand-edited or legacy
 * one may not, so each key falls back to W3TC's own default.
 *
 * Extensions (Delay Scripts, Preload Requests) are Pro: their settings stay in the file after a
 * licence ends, so they only come in when `plugin.type` says Pro (Util_Environment.php:1476-1484).
 *
 * Never read: anything secret (licence key, Redis/Memcached passwords, CDN keys, Cloudflare key
 * and e-mail). They are dropped as soon as the file is decoded.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * The configuration as an array, from the text of master.php / master.json / the database row,
 * without any secret. Pure.
 *
 * @param string $content File contents.
 * @return array|null
 */
function wpoptimizing_import_src_w3tc_decode( $content ) {
	$content = (string) $content;
	if ( 0 === strpos( $content, '<?php exit; ?>' ) ) {
		$content = substr( $content, 14 );
	}
	$raw = json_decode( $content, true );
	if ( ! is_array( $raw ) || empty( $raw ) ) {
		return null;
	}
	foreach ( array_keys( $raw ) as $key ) {
		$last = substr( (string) $key, (int) strrpos( '.' . $key, '.' ) );
		// Ends in a credential word: cdn.cf2.key, objectcache.redis.password, plugin.license_key…
		if ( preg_match( '/(pass|password|secret|key|token|email|user|username|auth)$/i', $last ) || 'cloudflare' === $key ) {
			unset( $raw[ $key ] );
		}
	}
	return $raw;
}

/**
 * The configuration a site of a network really runs: the network's (master) with the site's own
 * values on top, only where W3 Total Cache lets a site override it. Pure.
 *
 * Port of ConfigCompiler::child_key_sealed() (ConfigCompiler.php:56-120): a site's value counts
 * only in a section the site has «overloaded» (`minify.configuration_overloaded` and the rest,
 * ConfigKeys.php:2842-2879), in an extension block it overloaded, or for those flags themselves;
 * everything else, and everything when the network forces its own (`common.force_master`), comes
 * from the network.
 *
 * @param array $master The network's configuration.
 * @param array $child  The site's file.
 * @return array
 */
function wpoptimizing_import_src_w3tc_compile( array $master, array $child ) {
	if ( ! empty( $master['common.force_master'] ) ) {
		return $master;
	}
	$postfix = '.configuration_overloaded';
	$scopes  = array( 'browsercache.', 'cdn.', 'dbcache.', 'minify.', 'objectcache.', 'fragmentcache.', 'pgcache.', 'varnish.', 'imageservice.' );
	$out     = $master;
	foreach ( $child as $key => $value ) {
		$key = (string) $key;
		if ( 'pgcache.engine' === $key && 'file_generic' === ( $master['pgcache.engine'] ?? null ) ) {
			continue;
		}
		if ( in_array( $key, array( 'minify.rewrite', 'browsercache.rewrite', 'version' ), true ) ) {
			continue;
		}
		$open = substr( $key, -strlen( $postfix ) ) === $postfix || ! empty( $child[ $key . $postfix ] );
		foreach ( $scopes as $prefix ) {
			if ( ! $open && 0 === strpos( $key, $prefix ) ) {
				$open = ! empty( $child[ rtrim( $prefix, '.' ) . $postfix ] );
				break;
			}
		}
		if ( $open ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

/**
 * The live configuration. On a network, the current site's when it has a file of its own
 * (Config::util_config_filename(): `000002.php` for site 2, Config.php:140-148; the row
 * `w3tc_config_2` with W3TC_CONFIG_DATABASE), put together with the network's; the network's
 * otherwise.
 *
 * @return array|null
 */
function wpoptimizing_import_src_w3tc_read() {
	$blog = is_multisite() ? (int) get_current_blog_id() : 0;
	if ( defined( 'W3TC_CONFIG_DATABASE' ) && W3TC_CONFIG_DATABASE ) {
		// With its own table (W3TC_CONFIG_DATABASE_TABLE) the row is out of get_option()'s reach.
		if ( defined( 'W3TC_CONFIG_DATABASE_TABLE' ) ) {
			return null;
		}
		$content = is_multisite() && function_exists( 'get_main_site_id' ) ? get_blog_option( get_main_site_id(), 'w3tc_config_0', '' ) : get_option( 'w3tc_config_0', '' );
		$master  = is_string( $content ) && '' !== $content ? wpoptimizing_import_src_w3tc_decode( $content ) : null;
		$own     = $blog > 0 ? get_option( 'w3tc_config_' . $blog, '' ) : '';
		$child   = is_string( $own ) && '' !== $own ? wpoptimizing_import_src_w3tc_decode( $own ) : null;
	} else {
		$dir    = defined( 'W3TC_CONFIG_DIR' ) ? (string) W3TC_CONFIG_DIR : WP_CONTENT_DIR . '/w3tc-config';
		$load   = static function ( $base ) use ( $dir ) {
			foreach ( array( '.php', '.json' ) as $ext ) {
				$file = $dir . '/' . $base . $ext;
				if ( is_readable( $file ) ) {
					return wpoptimizing_import_src_w3tc_decode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local config file read as text, never executed.
				}
			}
			return null;
		};
		$master = $load( 'master' );
		$child  = $blog > 0 ? $load( sprintf( '%06d', $blog ) ) : null;
	}
	if ( null === $master ) {
		return null;
	}
	return is_array( $child ) ? wpoptimizing_import_src_w3tc_compile( $master, $child ) : $master;
}

/**
 * Translate it. Pure apart from translations.
 *
 * @param array $r master.php decoded.
 * @return array{changes:array,notes:array}
 */
function wpoptimizing_import_src_w3tc_map( array $r ) {
	$c        = array();
	$notes    = array();
	$defaults = array(
		'pgcache.enabled'       => false,
		'pgcache.engine'        => 'file_generic',
		'pgcache.lifetime'      => 3600,
		'pgcache.reject.logged' => true,
		'minify.enabled'        => false,
		'minify.css.enable'     => true,
		'minify.js.enable'      => true,
		'lazyload.process_img'  => true,
		'cdn.uploads.enable'    => true,
		'cdn.includes.enable'   => true,
		'cdn.theme.enable'      => true,
		'cdn.custom.enable'     => true,
		'cdn.reject.files'      => array( '{uploads_dir}/wpcf7_captcha/*', '{uploads_dir}/imagerotator.swf', '{plugins_dir}/wp-fb-autoconnect/facebook-platform/channel.html' ),
		'objectcache.engine'    => 'file',
	);
	$get      = static function ( $key ) use ( $r, $defaults ) {
		return array_key_exists( $key, $r ) ? $r[ $key ] : ( $defaults[ $key ] ?? null );
	};
	$on       = static function ( $key ) use ( $get ) {
		return ! empty( $get( $key ) );
	};
	$list     = static function ( $key ) use ( $get ) {
		$value = $get( $key );
		$value = is_array( $value ) ? $value : preg_split( '/\R/', (string) $value );
		return array_values( array_filter( array_map( 'trim', array_filter( (array) $value, 'is_scalar' ) ), 'strlen' ) );
	};
	$pro      = in_array( $get( 'plugin.type' ), array( 'pro', 'pro_dev' ), true );
	$active   = is_array( $r['extensions.active'] ?? null ) ? $r['extensions.active'] : array();

	// Page cache.
	if ( array_key_exists( 'pgcache.lifetime', $r ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ttl', wpoptimizing_importer_ttl( (int) $r['pgcache.lifetime'], $notes ), __( 'Maximum lifetime of cache objects', 'wpoptimizing' ) );
	}
	// Its two default rules (wp-.*\.php, index\.php) are files our engine never caches anyway.
	$uri = wpoptimizing_importer_patterns( array_diff( $list( 'pgcache.reject.uri' ), array( 'wp-.*\.php', 'index\.php' ) ), __( 'Never cache the following pages', 'wpoptimizing' ), $notes );
	if ( $uri ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_uri', $uri, __( 'Never cache the following pages', 'wpoptimizing' ) );
	}
	$cookies = wpoptimizing_importer_patterns( array_diff( $list( 'pgcache.reject.cookie' ), array( 'wptouch_switch_toggle' ) ), __( 'Rejected cookies', 'wpoptimizing' ), $notes );
	if ( $cookies ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_cookies', $cookies, __( 'Rejected cookies', 'wpoptimizing' ) );
	}
	$ua = wpoptimizing_importer_patterns( $list( 'pgcache.reject.ua' ), __( 'Rejected user agents', 'wpoptimizing' ), $notes );
	if ( $ua ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'reject_ua', $ua, __( 'Rejected user agents', 'wpoptimizing' ) );
	}
	// pgcache.accept.qs: parameters W3TC ignores when caching (PgCache_ContentGrabber.php:2830).
	$qs = wpoptimizing_importer_patterns( $list( 'pgcache.accept.qs' ), __( 'Accepted query strings', 'wpoptimizing' ), $notes );
	if ( $qs ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'ignored_query', $qs, __( 'Accepted query strings', 'wpoptimizing' ) );
	}
	if ( array_key_exists( 'mobile.enabled', $r ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'mobile_separate', $on( 'mobile.enabled' ), __( 'User Agent Groups', 'wpoptimizing' ) );
		if ( $on( 'mobile.enabled' ) ) {
			$notes[] = wpoptimizing_importer_note( 'review', __( 'User Agent Groups: WPOptimizing keeps one separate copy for all mobile devices, without groups, themes or redirects of its own.', 'wpoptimizing' ) );
		}
	}
	if ( array_key_exists( 'pgcache.prime.enabled', $r ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'preload', $on( 'pgcache.prime.enabled' ), __( 'Automatically prime the page cache', 'wpoptimizing' ) );
	}
	if ( $on( 'pgcache.enabled' ) && ! $on( 'pgcache.reject.logged' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing never serves cached pages to logged-in users.', 'wpoptimizing' ), __( 'Don\'t cache pages for logged in users', 'wpoptimizing' ) )
		);
	}
	if ( $on( 'pgcache.enabled' ) && ! in_array( $get( 'pgcache.engine' ), array( 'file', 'file_generic' ), true ) ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'Page Cache Method: W3 Total Cache kept pages in memory (Memcached, Redis or APC); WPOptimizing keeps them on disk.', 'wpoptimizing' ) );
	}

	// Minify. Each type only acts when minify itself is on, and minifies only when its method is
	// not «Combine only» (Minify_MinifiedFileRequestHandler.php:397-424); for JavaScript, «combine
	// only» per position in the page too.
	$combine_only = array(
		'css' => 'combine' === (string) $get( 'minify.css.method' ),
		'js'  => 'combine' === (string) $get( 'minify.js.method' ) || $on( 'minify.js.combine.header' ) || $on( 'minify.js.combine.body' ) || $on( 'minify.js.combine.footer' ),
	);
	foreach ( array(
		'css' => array( 'minify_css', __( 'Minify › CSS', 'wpoptimizing' ) ),
		'js'  => array( 'minify_js', __( 'Minify › JS', 'wpoptimizing' ) ),
	) as $type => $to ) {
		if ( array_key_exists( 'minify.enabled', $r ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', $to[0], $on( 'minify.enabled' ) && $on( 'minify.' . $type . '.enable' ) && ! $combine_only[ $type ], $to[1] );
		}
	}
	$exclude = array_values( array_unique( array_merge( $list( 'minify.reject.files.css' ), $list( 'minify.reject.files.js' ) ) ) );
	if ( $exclude ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'exclude', $exclude, __( 'Never minify the following JS / CSS files', 'wpoptimizing' ) );
	}
	$embed = array();
	foreach ( array( 'header', 'body', 'footer' ) as $where ) {
		$embed[] = (string) $get( 'minify.js.' . $where . '.embed_type' );
	}
	if ( $on( 'minify.enabled' ) && $on( 'minify.js.enable' ) && in_array( 'nb-defer', $embed, true ) ) {
		$c[]     = wpoptimizing_importer_change( 'wpoptimizing_asset_optimize', 'defer_js', true, __( 'Embed type', 'wpoptimizing' ) );
		$notes[] = wpoptimizing_importer_note(
			'review',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: WPOptimizing defers every script except the ones you exclude, wherever it sits in the page. Check the exclusions after importing.', 'wpoptimizing' ), __( 'Embed type', 'wpoptimizing' ) )
		);
	}
	if ( $on( 'minify.enabled' ) && ( $on( 'minify.js.combine.header' ) || $on( 'minify.js.combine.body' ) || $on( 'minify.js.combine.footer' ) || $list( 'minify.css.groups' ) || $list( 'minify.js.groups' ) ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported. Combining files makes HTTP/2 sites slower and breaks scripts, so WPOptimizing does not do it.', 'wpoptimizing' ), __( 'Minify › JS › Combine only', 'wpoptimizing' ) )
		);
	}
	// The page cache minifies the HTML of the copies it stores (html-minify.php).
	if ( $on( 'minify.enabled' ) && $on( 'minify.html.enable' ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_page_cache', 'minify_html', true, __( 'Minify › HTML & XML', 'wpoptimizing' ) );
	}

	// Pro extensions: stored settings act only with a Pro licence.
	$delay   = isset( $active['user-experience-defer-scripts'] );
	$preload = isset( $active['user-experience-preload-requests'] );
	if ( ( $delay || $preload ) && ! $pro ) {
		$notes[] = wpoptimizing_importer_note( 'info', __( 'Delay Scripts and Preload Requests: they are W3 Total Cache Pro features and this site has no Pro licence, so they were not acting and were not imported.', 'wpoptimizing' ) );
	}
	if ( $delay && $pro ) {
		$defer = is_array( $r['user-experience-defer-scripts'] ?? null ) ? $r['user-experience-defer-scripts'] : array();
		// W3TC delays only the scripts it lists, and nothing with an empty list
		// (UserExperience_DeferScripts_Mutator.php:144): that list alone is our «custom» profile.
		$inc = array_values( array_filter( array_map( 'trim', array_filter( (array) ( $defer['includes'] ?? array() ), 'is_scalar' ) ), 'strlen' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'enabled', (bool) $inc, __( 'Delay Scripts', 'wpoptimizing' ) );
		if ( $inc ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'profile', 'custom', __( 'Delay Scripts', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'include', $inc, __( 'Delay Scripts', 'wpoptimizing' ) );
		}
		if ( $inc && isset( $defer['timeout'] ) && (int) $defer['timeout'] > 0 ) {
			// Milliseconds there, seconds here (UserExperience_DeferScripts_Page_View.php:22-23).
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_delay_js', 'timeout', max( 1, (int) round( (int) $defer['timeout'] / 1000 ) ), __( 'Delay Scripts › Timeout', 'wpoptimizing' ) );
		}
	}
	if ( $preload && $pro ) {
		$req  = is_array( $r['user-experience-preload-requests'] ?? null ) ? $r['user-experience-preload-requests'] : array();
		$urls = array();
		foreach ( array( 'preload-css', 'preload-js', 'preload-fonts', 'preload-images', 'preload-videos', 'preload-audio', 'preload-documents' ) as $k ) {
			$urls = array_merge( $urls, array_filter( array_map( 'trim', array_filter( (array) ( $req[ $k ] ?? array() ), 'is_scalar' ) ), 'strlen' ) );
		}
		if ( $urls ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload', true, __( 'Preload Requests', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'preload_urls', array_values( array_unique( $urls ) ), __( 'Preload Requests', 'wpoptimizing' ) );
		}
		if ( ! empty( $req['dns-prefetch'] ) || ! empty( $req['preconnect'] ) ) {
			$c[]     = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'resource_hints', true, __( 'Preload Requests', 'wpoptimizing' ) );
			$notes[] = wpoptimizing_importer_note(
				'review',
				/* translators: %s: the other plugin's setting. */
				sprintf( __( '%s: WPOptimizing finds the hosts to connect to early on its own, so the list itself was not copied.', 'wpoptimizing' ), __( 'DNS Prefetch / Preconnect', 'wpoptimizing' ) )
			);
		}
	}

	// User experience and core tweaks.
	if ( array_key_exists( 'lazyload.enabled', $r ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_frontend_perf', 'image_opt', $on( 'lazyload.enabled' ) && $on( 'lazyload.process_img' ), __( 'Lazy Load Images', 'wpoptimizing' ) );
	}
	if ( array_key_exists( 'extensions.active', $r ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'emojis', isset( $active['user-experience-emoji'] ), __( 'Disable Emoji', 'wpoptimizing' ) );
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'embeds', isset( $active['user-experience-oembed'] ), __( 'Disable wp-embed script', 'wpoptimizing' ) );
	}
	if ( array_key_exists( 'jquerymigrate.disabled', $r ) ) {
		$c[] = wpoptimizing_importer_change( 'wpoptimizing_core_tweaks', 'jquery_migrate', $on( 'jquerymigrate.disabled' ), __( 'Disable jquery-migrate on the front-end', 'wpoptimizing' ) );
	}

	// CDN: only the engines that rewrite URLs to a host we can point at. The ones that upload
	// files (S3, FTP, Azure, Rackspace, Google Drive…) have no equivalent here.
	if ( $on( 'cdn.enabled' ) ) {
		$engine = (string) $get( 'cdn.engine' );
		$hosts  = array();
		if ( 'mirror' === $engine ) {
			$hosts = $list( 'cdn.mirror.domain' );
		} elseif ( 'cf2' === $engine ) {
			$hosts = $list( 'cdn.cf2.cname' );
		} elseif ( 'bunnycdn' === $engine ) {
			$hosts = $list( 'cdn.bunnycdn.cdn_hostname' );
		}
		// Our «content» scope is themes, plugins and uploads together. W3 Total Cache reaches the
		// plugins through its custom file list (on by default), so the theme scope alone would
		// widen it; and its exclusions, beyond the three defaults, have no equivalent here.
		$reject     = $list( 'cdn.reject.files' );
		$restricted = ( $on( 'cdn.theme.enable' ) && ! $on( 'cdn.custom.enable' ) )
			|| array() !== array_diff( $reject, array( '{uploads_dir}/wpcf7_captcha/*', '{uploads_dir}/imagerotator.swf', '{plugins_dir}/wp-fb-autoconnect/facebook-platform/channel.html' ) );
		if ( $hosts && $restricted ) {
			$notes[] = wpoptimizing_importer_cdn_restricted_note( __( 'CDN', 'wpoptimizing' ), $hosts[0] );
		} elseif ( $hosts ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'enabled', true, __( 'CDN', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cdn', 'host', $hosts[0], __( 'CDN host name', 'wpoptimizing' ) );
			$c[] = wpoptimizing_importer_change(
				'wpoptimizing_cdn',
				'dirs',
				array(
					'uploads'  => $on( 'cdn.uploads.enable' ) ? 1 : 0,
					'content'  => $on( 'cdn.theme.enable' ) ? 1 : 0,
					'includes' => $on( 'cdn.includes.enable' ) ? 1 : 0,
				),
				__( 'CDN', 'wpoptimizing' )
			);
			if ( count( $hosts ) > 1 ) {
				$notes[] = wpoptimizing_importer_note(
					'review',
					/* translators: %s: the other plugin's setting. */
					sprintf( __( '%s: WPOptimizing uses one CDN host, so only the first one was imported.', 'wpoptimizing' ), __( 'CDN host name', 'wpoptimizing' ) )
				);
			}
		} else {
			$notes[] = wpoptimizing_importer_note( 'skipped', __( 'CDN: W3 Total Cache uploads your files to the CDN; WPOptimizing only points file addresses at a CDN host. Set the host in WPOptimizing\'s CDN settings.', 'wpoptimizing' ) );
		}
	}

	// Object and database cache: the connection only, never a password, never switched on.
	if ( $on( 'objectcache.enabled' ) ) {
		$engine = (string) $get( 'objectcache.engine' );
		$kinds  = array(
			'redis'     => 'redis',
			'memcached' => 'memcached',
			'apc'       => 'apcu',
		);
		if ( isset( $kinds[ $engine ] ) ) {
			$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', 'backend', $kinds[ $engine ], __( 'Object Cache', 'wpoptimizing' ) );
			if ( 'apc' !== $engine ) {
				$server = (string) ( $list( 'objectcache.' . $engine . '.servers' )[0] ?? '' );
				if ( preg_match( '/^([^:\/]+):(\d+)$/', $server, $m ) ) {
					$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', $engine . '_host', $m[1], __( 'Object Cache', 'wpoptimizing' ) );
					$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', $engine . '_port', (int) $m[2], __( 'Object Cache', 'wpoptimizing' ) );
				}
				if ( 'redis' === $engine && array_key_exists( 'objectcache.redis.dbid', $r ) ) {
					$c[] = wpoptimizing_importer_change( 'wpoptimizing_cache_settings', 'redis_database', (int) $r['objectcache.redis.dbid'], __( 'Object Cache', 'wpoptimizing' ) );
				}
				$notes[] = wpoptimizing_importer_note(
					'review',
					/* translators: %s: the other plugin's setting. */
					sprintf( __( '%s: the connection was imported, but not switched on and without any password. If the server needs one, type it again in Cache › Object cache.', 'wpoptimizing' ), __( 'Object Cache', 'wpoptimizing' ) )
				);
			}
		} else {
			$notes[] = wpoptimizing_importer_note( 'skipped', __( 'Object Cache: W3 Total Cache kept it in files on disk, which WPOptimizing does not offer. Choose Redis, Memcached or APCu in Cache › Object cache if the server has one.', 'wpoptimizing' ) );
		}
	}
	if ( $on( 'dbcache.enabled' ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: not imported; WPOptimizing has no equivalent.', 'wpoptimizing' ), __( 'Database Cache', 'wpoptimizing' ) )
		);
	}

	// Cloudflare and Varnish.
	if ( isset( $active['cloudflare'] ) ) {
		$notes[] = wpoptimizing_importer_note(
			'skipped',
			/* translators: %s: the other plugin's setting. */
			sprintf( __( '%s: the other plugin connects to Cloudflare with your account\'s credentials, which WPOptimizing does not copy. Connect Cloudflare in Cache › Cloudflare with an API token.', 'wpoptimizing' ), 'Cloudflare' )
		);
	}
	if ( $on( 'varnish.enabled' ) ) {
		$notes[] = wpoptimizing_importer_note( 'review', __( 'Varnish: switch on its purge in Cache › Browser & server once WPOptimizing has checked that Varnish is in front of the site.', 'wpoptimizing' ) );
	}

	return array(
		'changes' => $c,
		'notes'   => $notes,
	);
}
