<?php
/**
 * Single source of truth for "another plugin already does this".
 *
 * Before this file there were six independent lists of the same competitors, in four
 * different shapes: wpoptimizing_assetmin_conflict() returned a label,
 * wpoptimizing_lazy_embeds_conflict() returned a bool (so it could not name the plugin it
 * had found), two memoised, two were filterable, and class-general-cleanup.php kept a
 * seventh list for a different purpose. The cost showed up exactly where you would expect:
 * ShortPixel was detected through SHORTPIXEL_PLUGIN_FILE in one file and
 * SHORTPIXEL_PLUGIN_VERSION in another, and Asset CleanUp was checked for lazy loading but
 * not for asset minification, which is its actual job.
 *
 * Detection is by symbol first (a constant, class or function the plugin defines: free, no
 * database read) and by directory slug second, against the active-plugins option. The slug
 * path exists so a plugin can be registered without hunting down an internal constant —
 * getting a constant name wrong fails silently, which is the worst outcome for a guard.
 *
 * NOTHING here touches the filesystem or the network, and no accessor runs at file scope:
 * this file is parsed on every request, including front-end ones. See FrontendBudgetTest.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Our own capabilities that a third-party plugin can already be providing.
 *
 * The key is what feature code asks about; the label is what a notice shows. A capability
 * belongs here only when two plugins doing it at once is a problem — either the work is
 * duplicated or, for delay_js and critical_css, the page visibly breaks.
 *
 * Some of these are listed so the Compatibility panel can REPORT the overlap but on purpose
 * do NOT switch our feature off, because doing it twice is harmless. This is a decision, not
 * an oversight, and it is written down here so nobody reads it as one:
 *
 *   - core_tweaks: removing the emoji script twice is idempotent — remove_action() on an
 *                  already-removed hook does nothing.
 *   - heartbeat:   two plugins filtering heartbeat_settings means the later one wins, and both
 *                  are trying to slow it down. Nothing breaks either way.
 *   - wp_cron:     ours writes DISABLE_WP_CRON into wp-config; WP Crontrol inspects and edits
 *                  scheduled events and never touches that constant. Same subject, different
 *                  jobs, so both are useful at once.
 *   - db_cleanup, as_cleanup, revisions_cleanup: deleting an orphan, a stale log row or an old
 *                  revision twice is a no-op — the second pass finds nothing. Standing down
 *                  would be worse than the overlap: a plugin that only trims Action Scheduler
 *                  would switch off cleanup of everything else.
 *   - media_cleanup: THE OVERLAP THAT IS NOT DUPLICATED WORK. Media Cleaner keeps its own list
 *                  of "unused" attachments, built with its own heuristic, and ours is built
 *                  with a different one. The risk is not doing it twice, it is two lists that
 *                  disagree and a person deleting from whichever is on screen. So the guard is
 *                  a notice WHERE THE DELETE BUTTON IS, not a stand-down: withdrawing our
 *                  detection would leave that person with one list and no warning at all.
 *
 * The capabilities that DO stand down are exactly the ones feature code asks about with
 * wpoptimizing_compat_owner(): page_cache, minify, defer_js… — see CompatCapabilityWiringTest,
 * which fails if a capability is declared here and nothing ever asks about it. That test
 * exists because this list used to claim object_cache, async_css and defer_js stood down when
 * only defer_js… did not: none of the three was wired to anything.
 *
 * @return array<string,string> Capability key => human label.
 */
function wpoptimizing_compat_capabilities() {
	return array(
		'page_cache'        => __( 'Page cache', 'wpoptimizing' ),
		'object_cache'      => __( 'Persistent object cache', 'wpoptimizing' ),
		'minify'            => __( 'CSS and JavaScript minification', 'wpoptimizing' ),
		'lazy_media'        => __( 'Lazy loading of images and embeds', 'wpoptimizing' ),
		'nextgen_images'    => __( 'Serving WebP/AVIF copies', 'wpoptimizing' ),
		'image_optimize'    => __( 'Image compression', 'wpoptimizing' ),
		'google_fonts'      => __( 'Self-hosted Google Fonts', 'wpoptimizing' ),
		'browser_cache'     => __( 'Browser cache headers', 'wpoptimizing' ),
		'cdn'               => __( 'CDN rewriting', 'wpoptimizing' ),
		'heartbeat'         => __( 'Heartbeat control', 'wpoptimizing' ),
		'wp_cron'           => __( 'WP-Cron control', 'wpoptimizing' ),
		'core_tweaks'       => __( 'Core tweaks (emojis, embeds, head cleanup)', 'wpoptimizing' ),
		'db_cleanup'        => __( 'Database cleanup', 'wpoptimizing' ),
		'media_cleanup'     => __( 'Unused and orphaned media detection', 'wpoptimizing' ),
		'as_cleanup'        => __( 'Action Scheduler log cleanup', 'wpoptimizing' ),
		'revisions_cleanup' => __( 'Post revision cleanup', 'wpoptimizing' ),
	);
}

/**
 * Las capacidades que SOLO SE INFORMAN: detectamos el solape y no nos retiramos.
 *
 * Esto existe porque el panel de Compatibilidad pintaba, para toda capacidad detectada, el
 * interruptor «usar WPOptimizing para esto de todos modos» — y para estas no hace NADA: nadie
 * llama a wpoptimizing_compat_owner() con ellas, así que no hay retirada que anular. Un control
 * que no hace nada es peor que no tenerlo, porque quien lo pulsa cree que ha cambiado algo.
 *
 * El motivo de cada una está en el docblock de wpoptimizing_compat_capabilities(). Es la fuente
 * ÚNICA: el panel y CompatCapabilityWiringTest leen de aquí, para no tener dos listas que se
 * separen (que es exactamente el fallo que trajo a esta función a existir).
 *
 * @since 0.1.0
 * @return array<string,true> Capacidad => true.
 */
function wpoptimizing_compat_report_only() {
	return array(
		'core_tweaks'       => true,
		'heartbeat'         => true,
		'wp_cron'           => true,
		'db_cleanup'        => true,
		'as_cleanup'        => true,
		'revisions_cleanup' => true,
		'media_cleanup'     => true,
	);
}

/**
 * The registry: every plugin we stand down for, and which of our capabilities it covers.
 *
 * 'const', 'class' and 'func' are symbols the plugin defines — checked first because they
 * need no database read. 'slug' is its directory name, matched against the active-plugins
 * option. A plugin may carry both; any one match counts.
 *
 * 'covers' lists OUR capability keys. It is deliberately conservative: list a capability
 * only when the plugin really provides it, because an entry here switches our feature off.
 *
 * 'htaccess' lists the .htaccess markers («# BEGIN <marker>») of the block a page cache writes
 * to serve its HTML before PHP; a trailing * means anything may follow («W3TC Page Cache core»).
 * Read in each plugin's source. When the plugin is no longer active that block is a leftover
 * still serving old pages: see includes/cache-leftovers.php.
 *
 * @return array<string,array> Plugin id => definition.
 */
function wpoptimizing_compat_registry() {
	$registry = array(
		'wp-rocket'                    => array(
			'label'    => 'WP Rocket',
			'const'    => array( 'WP_ROCKET_VERSION' ),
			'slug'     => 'wp-rocket',
			'covers'   => array( 'page_cache', 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css', 'lazy_media', 'google_fonts', 'browser_cache', 'cdn', 'heartbeat', 'core_tweaks', 'db_cleanup', 'revisions_cleanup' ),
			'htaccess' => array( 'WP Rocket' ),
		),
		'litespeed'                    => array(
			'label'    => 'LiteSpeed Cache',
			'const'    => array( 'LSCWP_V', 'LSCWP_BASENAME' ),
			'slug'     => 'litespeed-cache',
			// No google_fonts: LiteSpeed removes or async-loads them, it does not host them locally.
			'covers'   => array( 'page_cache', 'object_cache', 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'browser_cache', 'cdn', 'heartbeat', 'core_tweaks', 'db_cleanup', 'revisions_cleanup' ),
			// Its own switches, per site: a capability it has switched off is not its to own. On
			// a network one site can move to us while the other keeps LiteSpeed
			// (artprojectgroup.com, 10/11/2026). Option => value that means on (null: any truthy).
			'settings' => array(
				'page_cache'   => array( 'litespeed.conf.cache' => null ),
				'minify'       => array(
					'litespeed.conf.optm-css_min' => null,
					'litespeed.conf.optm-js_min'  => null,
				),
				'defer_js'     => array( 'litespeed.conf.optm-js_defer' => 1 ),
				'delay_js'     => array( 'litespeed.conf.optm-js_defer' => 2 ),
				'async_css'    => array( 'litespeed.conf.optm-css_async' => null ),
				'critical_css' => array( 'litespeed.conf.optm-ccss_gen' => null ),
				'lazy_media'   => array(
					'litespeed.conf.media-lazy'        => null,
					'litespeed.conf.media-iframe_lazy' => null,
				),
			),
			// NON_LSCACHE too: its block for other servers, left behind (empty) next to LSCACHE when
			// it is deactivated on a network (artprojectgroup.es, 10/11/2026).
			'htaccess' => array( 'LSCACHE', 'NON_LSCACHE' ),
		),
		'w3tc'                         => array(
			'label'    => 'W3 Total Cache',
			'const'    => array( 'W3TC' ),
			'slug'     => 'w3-total-cache',
			// No db_cleanup: its «database cache» caches queries, it never cleans anything.
			'covers'   => array( 'page_cache', 'object_cache', 'minify', 'defer_js', 'delay_js', 'lazy_media', 'nextgen_images', 'image_optimize', 'browser_cache', 'cdn', 'core_tweaks' ),
			'htaccess' => array( 'W3TC Page Cache*' ),
		),
		'wp-super-cache'               => array(
			'label'    => 'WP Super Cache',
			// WPSC_VERSION_ID, not WPCACHEHOME: its advanced-cache.php and wp-config.php define
			// WPCACHEHOME too, so it can exist with the plugin deactivated.
			'const'    => array( 'WPSC_VERSION_ID' ),
			'slug'     => 'wp-super-cache',
			// No browser_cache: its rules only set an expiry on the cached HTML, not on assets.
			'covers'   => array( 'page_cache', 'cdn' ),
			'htaccess' => array( 'WPSuperCache' ),
		),
		'autoptimize'                  => array(
			'label'  => 'Autoptimize',
			'const'  => array( 'AUTOPTIMIZE_PLUGIN_VERSION' ),
			'class'  => array( 'autoptimizeMain' ),
			'slug'   => 'autoptimize',
			// No google_fonts: it removes or combines them but never hosts them locally.
			'covers' => array( 'minify', 'defer_js', 'async_css', 'critical_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'cdn', 'core_tweaks' ),
		),
		'perfmatters'                  => array(
			'label'  => 'Perfmatters',
			'const'  => array( 'PERFMATTERS_VERSION' ),
			'slug'   => 'perfmatters',
			// critical_css stays although 2.6 has no generator: its «used CSS» inlines and re-delivers
			// the stylesheets, which collides with ours just the same.
			'covers' => array( 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css', 'lazy_media', 'google_fonts', 'heartbeat', 'core_tweaks', 'cdn', 'db_cleanup', 'revisions_cleanup' ),
		),
		'sg-optimizer'                 => array(
			'label'  => 'SG Optimizer',
			'class'  => array( 'SiteGround_Optimizer\\Options\\Options' ),
			'slug'   => 'sg-cachepress',
			// No google_fonts: it inlines Google's CSS but the font files still come from gstatic.
			'covers' => array( 'page_cache', 'object_cache', 'minify', 'defer_js', 'lazy_media', 'nextgen_images', 'image_optimize', 'browser_cache', 'heartbeat', 'core_tweaks', 'db_cleanup' ),
		),
		'asset-cleanup'                => array(
			'label'  => 'Asset CleanUp',
			'const'  => array( 'WPACU_PLUGIN_VERSION' ),
			'slug'   => 'wp-asset-clean-up',
			// defer_js is a Pro feature; Pro defines the same constant.
			'covers' => array( 'minify', 'defer_js', 'async_css', 'critical_css', 'lazy_media', 'google_fonts', 'cdn', 'core_tweaks' ),
		),
		'flyingpress'                  => array(
			'label'    => 'FlyingPress',
			'const'    => array( 'FLYING_PRESS_VERSION' ),
			'slug'     => 'flying-press',
			// 5.6 ships its own Redis object-cache drop-in (src/ObjectCache.php).
			'covers'   => array( 'page_cache', 'object_cache', 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'google_fonts', 'browser_cache', 'cdn', 'heartbeat', 'core_tweaks', 'db_cleanup', 'revisions_cleanup' ),
			'htaccess' => array( 'FlyingPress' ),
		),
		'nitropack'                    => array(
			'label'  => 'NitroPack',
			'const'  => array( 'NITROPACK_VERSION' ),
			'slug'   => 'nitropack',
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'cdn' ),
		),
		'hummingbird'                  => array(
			'label'  => 'Hummingbird',
			'const'  => array( 'WPHB_VERSION' ),
			'slug'   => 'hummingbird-performance',
			// No lazy_media (it only lazy-loads comments) and no heartbeat (not in the package).
			'covers' => array( 'page_cache', 'object_cache', 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css', 'browser_cache', 'core_tweaks', 'db_cleanup' ),
		),
		'wp-fastest-cache'             => array(
			'label'    => 'WP Fastest Cache',
			'slug'     => 'wp-fastest-cache',
			// The free plugin alone: lazy load, defer, delay, WebP and the database cleanup only
			// work with the Premium add-on, which has its own entry below.
			'covers'   => array( 'page_cache', 'minify', 'browser_cache', 'cdn', 'core_tweaks' ),
			'htaccess' => array( 'WpFastestCache' ),
		),
		'wp-fastest-cache-premium'     => array(
			'label'    => 'WP Fastest Cache Premium',
			'slug'     => 'wp-fastest-cache-premium',
			'covers'   => array( 'minify', 'defer_js', 'delay_js', 'lazy_media', 'nextgen_images', 'image_optimize', 'db_cleanup', 'revisions_cleanup' ),
			'htaccess' => array( 'WpFastestCache' ),
		),
		'cache-enabler'                => array(
			// Not replaceable for now, and no minify: it minifies HTML only, which used to switch
			// our CSS/JS minification off for nothing.
			'label'  => 'Cache Enabler',
			'slug'   => 'cache-enabler',
			'covers' => array( 'page_cache', 'nextgen_images' ),
		),
		'breeze'                       => array(
			'label'  => 'Breeze',
			'const'  => array( 'BREEZE_VERSION' ),
			'slug'   => 'breeze',
			// No object_cache: it only flushes one, it never installs a drop-in.
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'delay_js', 'lazy_media', 'google_fonts', 'browser_cache', 'cdn', 'heartbeat', 'core_tweaks', 'db_cleanup' ),
		),
		'redis-cache'                  => array(
			'label'  => 'Redis Object Cache',
			'const'  => array( 'WP_REDIS_VERSION' ),
			'slug'   => 'redis-cache',
			'covers' => array( 'object_cache' ),
		),
		'omgf'                         => array(
			// Not replaceable: it processes the whole HTML (not only enqueued styles), with
			// subsets, per-variant preload and a test mode we do not have.
			'label'  => 'OMGF',
			'const'  => array( 'OMGF_PLUGIN_FILE' ),
			'slug'   => 'host-webfonts-local',
			'covers' => array( 'google_fonts' ),
		),
		'imagify'                      => array(
			'label'  => 'Imagify',
			'const'  => array( 'IMAGIFY_VERSION' ),
			'slug'   => 'imagify',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'ewww'                         => array(
			'label'  => 'EWWW Image Optimizer',
			'const'  => array( 'EWWW_IMAGE_OPTIMIZER_VERSION' ),
			'slug'   => 'ewww-image-optimizer',
			'covers' => array( 'nextgen_images', 'image_optimize', 'lazy_media' ),
		),
		'shortpixel'                   => array(
			'label'  => 'ShortPixel',
			// ShortPixelAI is the class of a different plugin (Adaptive Images), which has its own entry.
			'const'  => array( 'SHORTPIXEL_PLUGIN_FILE', 'SHORTPIXEL_IMAGE_OPTIMISER_VERSION' ),
			'slug'   => 'shortpixel-image-optimiser',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'webp-express'                 => array(
			'replaceable' => true,
			'label'       => 'WebP Express',
			'const'       => array( 'WEBP_EXPRESS_PLUGIN_DIR' ),
			'func'        => array( 'webpexpress_init' ),
			'slug'        => 'webp-express',
			'covers'      => array( 'nextgen_images' ),
		),
		'smush'                        => array(
			// The free plugin, by slug only: WP_SMUSH_VERSION is shared with Pro, and the free
			// one does not serve WebP — declaring it switched our serving off for nobody.
			'label'  => 'Smush',
			'slug'   => 'wp-smushit',
			'covers' => array( 'image_optimize', 'lazy_media' ),
		),
		'smush-pro'                    => array(
			'label'  => 'Smush Pro',
			'slug'   => 'wp-smush-pro',
			'covers' => array( 'nextgen_images', 'image_optimize', 'lazy_media', 'cdn' ),
		),
		'optimole'                     => array(
			'label'  => 'Optimole',
			'const'  => array( 'OPTML_VERSION' ),
			'slug'   => 'optimole-wp',
			'covers' => array( 'nextgen_images', 'image_optimize', 'lazy_media', 'cdn' ),
		),
		'heartbeat-control'            => array(
			'replaceable' => true,
			'label'       => 'Heartbeat Control',
			'slug'        => 'heartbeat-control',
			'covers'      => array( 'heartbeat' ),
		),
		'wp-crontrol'                  => array(
			'label'  => 'WP Crontrol',
			'slug'   => 'wp-crontrol',
			'covers' => array( 'wp_cron' ),
		),
		'wp-optimize'                  => array(
			'label'  => 'WP-Optimize',
			'const'  => array( 'WPO_VERSION' ), // Also the premium edition, whose folder is another.
			'slug'   => 'wp-optimize',
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'async_css', 'delay_js', 'lazy_media', 'nextgen_images', 'image_optimize', 'google_fonts', 'browser_cache', 'heartbeat', 'core_tweaks', 'db_cleanup', 'revisions_cleanup' ),
		),
		'advanced-db-cleaner'          => array(
			// Not replaceable while it cleans what we do not (orphaned user meta, table repair).
			'label'  => 'Advanced Database Cleaner',
			'slug'   => 'advanced-database-cleaner',
			'covers' => array( 'db_cleanup', 'revisions_cleanup' ),
		),
		'disable-emojis'               => array(
			'replaceable' => true,
			'label'       => 'Disable Emojis',
			'slug'        => 'disable-emojis',
			'covers'      => array( 'core_tweaks' ),
		),
		/*
		 * Los tres siguientes son de LIMPIEZA, no de rendimiento, y faltaban: el registro
		 * cubría bien el lado rendimiento y no tenía una sola clave para el lado datos, que
		 * es el núcleo de este plugin y su parte destructiva. Se detectaron activos en una
		 * tienda en producción a la que se acababa de instalar la versión Pro.
		 *
		 * SOLO 'slug', sin constante ni clase, a propósito: equivocar un símbolo falla en
		 * silencio, y una guarda que falla en silencio es peor que no tenerla.
		 */
		'media-cleaner'                => array(
			'label'  => 'Media Cleaner',
			'slug'   => 'media-cleaner',
			'covers' => array( 'media_cleanup' ),
		),
		'cleanup-action-scheduler'     => array(
			// Sin 'replaceable': recorta las tablas de Action Scheduler y no está comprobado
			// que cubramos su trabajo entero. Declararlo de más es decirle a alguien que
			// borre un plugin que le funciona.
			'label'  => 'Cleanup Action Scheduler',
			'slug'   => 'cleanup-action-scheduler',
			'covers' => array( 'as_cleanup' ),
		),
		'rvg-optimize-database'        => array(
			// Revisiones + OPTIMIZE TABLE: las dos cosas las hacemos (keep_revisions,
			// revisions_max_age_days y trait-tables.php), así que aquí sí.
			'replaceable' => true,
			'label'       => 'Optimize Database after Deleting Revisions',
			'slug'        => 'rvg-optimize-database',
			'covers'      => array( 'revisions_cleanup', 'db_cleanup' ),
		),

		/*
		 * Added 2026-10-16 from reading each package's code (docs/competencia-2026-10-16). A
		 * symbol is used only where it was found in the code; otherwise the folder name. None is
		 * replaceable: each does something we do not.
		 */
		// Caching and front-end suites.
		'speedycache'                  => array(
			'label'  => 'SpeedyCache',
			'const'  => array( 'SPEEDYCACHE_VERSION' ),
			'slug'   => 'speedycache',
			'covers' => array( 'page_cache', 'minify', 'browser_cache', 'cdn', 'core_tweaks' ),
		),
		'speedycache-pro'              => array(
			'label'  => 'SpeedyCache Pro',
			'const'  => array( 'SPEEDYCACHE_PRO_VERSION' ),
			'slug'   => 'speedycache-pro',
			'covers' => array( 'minify', 'defer_js', 'delay_js', 'critical_css', 'async_css', 'lazy_media', 'google_fonts', 'object_cache', 'nextgen_images', 'image_optimize' ),
		),
		'jetpack-boost'                => array(
			'label'  => 'Jetpack Boost',
			'const'  => array( 'JETPACK_BOOST_VERSION' ),
			'slug'   => 'jetpack-boost',
			// No cdn: its image CDN rewrites images only and would switch off our CSS/JS CDN.
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'critical_css', 'async_css', 'nextgen_images' ),
		),
		'tenweb-booster'               => array(
			'label'  => '10Web Booster',
			'const'  => array( 'TENWEB_SO_VERSION' ),
			'slug'   => 'tenweb-speed-optimizer',
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'delay_js', 'critical_css', 'async_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'browser_cache', 'core_tweaks' ),
		),
		'swift-performance'            => array(
			'label'  => 'Swift Performance',
			'const'  => array( 'SWIFT_PERFORMANCE_VER' ), // Lite and Pro alike.
			'slug'   => 'swift-performance-lite',
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'delay_js', 'critical_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'google_fonts', 'browser_cache', 'cdn', 'heartbeat', 'wp_cron', 'db_cleanup', 'core_tweaks' ),
		),
		'super-page-cache'             => array(
			'label'  => 'Super Page Cache',
			'const'  => array( 'SWCFPC_VERSION' ),
			'slug'   => 'wp-cloudflare-page-cache',
			'covers' => array( 'page_cache', 'lazy_media', 'google_fonts', 'browser_cache', 'heartbeat', 'db_cleanup', 'revisions_cleanup' ),
		),
		'seraphinite'                  => array(
			'label'  => 'Seraphinite Accelerator',
			'const'  => array( 'SERAPH_ACCEL_PLUGIN_DIR' ), // Base and full editions.
			'slug'   => 'seraphinite-accelerator',
			'covers' => array( 'page_cache', 'object_cache', 'minify', 'defer_js', 'delay_js', 'critical_css', 'async_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'cdn', 'core_tweaks' ),
		),
		'berqwp'                       => array(
			'label'  => 'BerqWP',
			'const'  => array( 'BERQWP_VERSION' ),
			'slug'   => 'searchpro',
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'delay_js', 'critical_css', 'async_css', 'lazy_media', 'nextgen_images', 'image_optimize', 'cdn', 'heartbeat', 'db_cleanup', 'revisions_cleanup', 'core_tweaks' ),
		),
		'comet-cache'                  => array(
			'label'  => 'Comet Cache',
			'slug'   => 'comet-cache',
			'covers' => array( 'page_cache', 'minify', 'browser_cache', 'cdn' ),
		),
		'comet-cache-pro'              => array(
			'label'  => 'Comet Cache Pro',
			'slug'   => 'comet-cache-pro',
			'covers' => array( 'page_cache', 'minify', 'browser_cache', 'cdn' ),
		),
		'debloat'                      => array(
			'label'  => 'Debloat',
			'const'  => array( 'DEBLOAT_PLUGIN_FILE' ),
			'slug'   => 'debloat',
			'covers' => array( 'minify', 'defer_js', 'async_css', 'delay_js', 'critical_css' ),
		),
		'fast-velocity-minify'         => array(
			'label'  => 'Fast Velocity Minify',
			'const'  => array( 'FVM_PDIR' ),
			'slug'   => 'fast-velocity-minify',
			'covers' => array( 'minify', 'defer_js', 'async_css', 'delay_js', 'google_fonts', 'cdn', 'core_tweaks' ),
		),
		// Estaban en el catálogo de purga (host-cache.php) y no aquí, así que el panel de
		// compatibilidad no los veía (constantes leídas en su código, 06/11/2026).
		'powered-cache'                => array(
			'label'    => 'Powered Cache',
			'const'    => array( 'POWERED_CACHE_VERSION' ),
			'slug'     => 'powered-cache',
			'covers'   => array( 'page_cache' ),
			'htaccess' => array( 'POWERED CACHE' ),
		),
		'cachify'                      => array(
			'label'    => 'Cachify',
			'const'    => array( 'CACHIFY_VERSION' ),
			'slug'     => 'cachify',
			'covers'   => array( 'page_cache' ),
			'htaccess' => array( 'CACHIFY' ),
		),
		'speed-booster-pack'           => array(
			'label'    => 'Speed Booster Pack',
			'const'    => array( 'SBP_VERSION' ),
			'slug'     => 'speed-booster-pack',
			'covers'   => array( 'page_cache', 'minify', 'defer_js', 'async_css', 'critical_css', 'lazy_media', 'cdn', 'heartbeat', 'core_tweaks' ),
			'htaccess' => array( 'Speed Booster Pack' ),
		),
		'wp-meteor'                    => array(
			'label'  => 'WP Meteor',
			'const'  => array( 'WPMETEOR_VERSION' ),
			'slug'   => 'wp-meteor',
			'covers' => array( 'delay_js' ),
		),
		'flying-scripts'               => array(
			// Not replaceable: our delay is Pro, so for a free user it would be a loss.
			'label'  => 'Flying Scripts',
			'const'  => array( 'FLYING_SCRIPTS_VERSION' ),
			'slug'   => 'flying-scripts',
			'covers' => array( 'delay_js' ),
		),
		'clearfy'                      => array(
			'label'  => 'Clearfy',
			'const'  => array( 'WCL_PLUGIN_VERSION' ),
			'slug'   => 'clearfy',
			'covers' => array( 'page_cache', 'minify', 'defer_js', 'async_css', 'critical_css', 'heartbeat', 'core_tweaks' ),
		),
		'freesoul-pro'                 => array(
			'label'  => 'Freesoul Deactivate Plugins PRO',
			'const'  => array( 'EOS_DP_PRO_VERSION' ),
			'slug'   => 'freesoul-deactivate-plugins-pro',
			'covers' => array( 'core_tweaks', 'heartbeat' ),
		),
		'asenha'                       => array(
			'label'  => 'Admin and Site Enhancements',
			'const'  => array( 'ASENHA_VERSION' ),
			'slug'   => 'admin-site-enhancements',
			'covers' => array( 'image_optimize', 'heartbeat', 'core_tweaks' ),
		),
		'wp-disable'                   => array(
			'label'  => 'WP Disable',
			'const'  => array( 'WP_DISABLE_VERSION' ),
			'slug'   => 'wp-disable',
			'covers' => array( 'core_tweaks', 'heartbeat' ),
		),
		'disable-everything'           => array(
			'label'  => 'Disable Everything',
			'slug'   => 'disable-everything',
			'covers' => array( 'core_tweaks', 'heartbeat' ),
		),
		// Images.
		'shortpixel-ai'                => array(
			'label'  => 'ShortPixel Adaptive Images',
			'const'  => array( 'SHORTPIXEL_AI_VERSION' ),
			'class'  => array( 'ShortPixelAI' ),
			'slug'   => 'shortpixel-adaptive-images',
			'covers' => array( 'nextgen_images', 'image_optimize', 'lazy_media', 'cdn' ),
		),
		'converter-for-media'          => array(
			'label'  => 'Converter for Media',
			'slug'   => 'webp-converter-for-media',
			'covers' => array( 'nextgen_images' ), // Creates and serves copies; never recompresses originals.
		),
		'compressx'                    => array(
			'label'  => 'CompressX',
			'const'  => array( 'COMPRESSX_VERSION' ),
			'slug'   => 'compressx',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'tinypng'                      => array(
			'label'  => 'TinyPNG',
			'class'  => array( 'Tiny_Plugin' ),
			'slug'   => 'tiny-compress-images',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'elementor-image-optimization' => array(
			'label'  => 'Image Optimizer (Elementor)',
			'const'  => array( 'IMAGE_OPTIMIZATION_VERSION' ),
			'slug'   => 'image-optimization',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'robin'                        => array(
			'label'  => 'Robin Image Optimizer',
			'const'  => array( 'WRIO_PLUGIN_VERSION' ),
			'slug'   => 'robin-image-optimizer',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'qode-optimizer'               => array(
			'label'  => 'Qode Optimizer',
			'const'  => array( 'QODE_OPTIMIZER_VERSION' ),
			'slug'   => 'qode-optimizer',
			'covers' => array( 'nextgen_images', 'image_optimize' ),
		),
		'webp-uploads'                 => array(
			'label'  => 'Modern Image Formats',
			'const'  => array( 'WEBP_UPLOADS_VERSION' ),
			'slug'   => 'webp-uploads',
			'covers' => array( 'nextgen_images' ),
		),
		// Fonts and video.
		'disable-remove-google-fonts'  => array(
			'label'  => 'Disable and Remove Google Fonts',
			'const'  => array( 'DRGF_VERSION' ),
			'slug'   => 'disable-remove-google-fonts',
			'covers' => array( 'google_fonts' ), // It removes them: localizing what it strips is wasted work.
		),
		'wp-youtube-lyte'              => array(
			'label'  => 'WP YouTube Lyte',
			'slug'   => 'wp-youtube-lyte',
			'covers' => array( 'lazy_media' ),
		),
		'lazy-load-for-videos'         => array(
			'label'  => 'Lazy Load for Videos',
			'slug'   => 'lazy-load-for-videos',
			'covers' => array( 'lazy_media' ),
		),
		// Object caches (reported in Compatibility; the drop-in itself is guarded by
		// dropin_is_ours(), not by this registry).
		'object-cache-pro'             => array(
			'label'  => 'Object Cache Pro',
			'slug'   => 'object-cache-pro',
			'covers' => array( 'object_cache' ),
		),
		'wp-redis'                     => array(
			'label'  => 'WP Redis',
			'func'   => array( 'wp_redis_get_info' ),
			'slug'   => 'wp-redis',
			'covers' => array( 'object_cache' ),
		),
		'sqlite-object-cache'          => array(
			'label'  => 'SQLite Object Cache',
			'slug'   => 'sqlite-object-cache',
			'covers' => array( 'object_cache' ),
		),
		'apcu-manager'                 => array(
			'label'  => 'APCu Manager',
			'const'  => array( 'APCM_VERSION' ),
			'slug'   => 'apcu-manager',
			'covers' => array( 'object_cache' ),
		),
		'docket-cache'                 => array(
			'label'  => 'Docket Cache',
			'const'  => array( 'DOCKET_CACHE_FILE' ),
			'slug'   => 'docket-cache',
			'covers' => array( 'object_cache', 'core_tweaks', 'wp_cron' ), // It writes DISABLE_WP_CRON too.
		),
		// Cleanup: slug only, the convention for this side (see the comment above media-cleaner).
		// Pro editions live in their own folder and need their own entry.
		'advanced-db-cleaner-premium'  => array(
			'label'  => 'Advanced Database Cleaner Premium',
			'slug'   => 'advanced-database-cleaner-premium',
			'covers' => array( 'db_cleanup', 'revisions_cleanup', 'as_cleanup' ),
		),
		'media-cleaner-pro'            => array(
			'label'  => 'Media Cleaner Pro',
			'slug'   => 'media-cleaner-pro',
			'covers' => array( 'media_cleanup' ),
		),
		'wp-sweep'                     => array(
			'label'  => 'WP-Sweep',
			'slug'   => 'wp-sweep',
			'covers' => array( 'db_cleanup', 'revisions_cleanup' ),
		),
		'database-cleaner'             => array(
			'label'  => 'Database Cleaner',
			'slug'   => 'database-cleaner',
			'covers' => array( 'db_cleanup', 'revisions_cleanup' ),
		),
		'database-cleaner-pro'         => array(
			'label'  => 'Database Cleaner Pro',
			'slug'   => 'database-cleaner-pro',
			'covers' => array( 'db_cleanup', 'revisions_cleanup' ),
		),
		'wps-cleaner'                  => array(
			'label'  => 'WPS Cleaner',
			'slug'   => 'wps-cleaner',
			'covers' => array( 'db_cleanup', 'revisions_cleanup', 'as_cleanup', 'media_cleanup' ),
		),
		'lws-cleaner'                  => array(
			'label'  => 'LWS Cleaner',
			'slug'   => 'lws-cleaner',
			'covers' => array( 'db_cleanup', 'revisions_cleanup' ),
		),
		'smart-cleanup-tools'          => array(
			'label'  => 'Smart Cleanup Tools',
			'slug'   => 'smart-cleanup-tools',
			'covers' => array( 'db_cleanup', 'revisions_cleanup' ),
		),
		'wp-dbmanager'                 => array(
			'label'  => 'WP-DBManager',
			'slug'   => 'wp-dbmanager',
			'covers' => array( 'db_cleanup' ),
		),
		'advanced-cron-manager'        => array(
			'label'  => 'Advanced Cron Manager',
			'slug'   => 'advanced-cron-manager',
			'covers' => array( 'wp_cron' ), // Its server scheduler defines DISABLE_WP_CRON at runtime.
		),
	);

	/**
	 * Filters the compatibility registry.
	 *
	 * Add an entry to make WPOptimizing stand down for a plugin we do not know, or unset
	 * one to take a capability back. Keys: label, const[], class[], func[], slug, covers[].
	 *
	 * @since 0.1.0
	 * @param array $registry Plugin id => definition.
	 */
	return (array) apply_filters( 'wpoptimizing_compat_registry', $registry );
}

/**
 * Active plugin directory slugs, from the options WordPress already autoloads.
 *
 * Reads active_plugins (and the network list on multisite) rather than calling
 * is_plugin_active(), which lives in an admin-only file and is unavailable on the front end.
 *
 * @return array<string,true> Slug => true, for O(1) lookup.
 */
function wpoptimizing_compat_active_slugs() {
	static $slugs = null;
	if ( null !== $slugs ) {
		return $slugs;
	}
	$slugs = array();
	$paths = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		// The network list is keyed BY path, with the activation timestamp as the value.
		$paths = array_merge( $paths, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	foreach ( $paths as $path ) {
		$dir = strtok( (string) $path, '/' );
		if ( is_string( $dir ) && '' !== $dir ) {
			$slugs[ $dir ] = true;
		}
	}
	return $slugs;
}

/**
 * Is this registry entry's plugin active?
 *
 * @param array $def Registry definition.
 * @return bool
 */
function wpoptimizing_compat_entry_active( array $def ) {
	foreach ( (array) ( $def['const'] ?? array() ) as $sym ) {
		if ( defined( $sym ) ) {
			return true;
		}
	}
	foreach ( (array) ( $def['class'] ?? array() ) as $sym ) {
		// No autoload: with ~90 entries, asking every registered autoloader for classes that do
		// not exist can mean file lookups on a visitor's request. An active plugin is caught by
		// its constant or its folder anyway.
		if ( class_exists( $sym, false ) ) {
			return true;
		}
	}
	foreach ( (array) ( $def['func'] ?? array() ) as $sym ) {
		if ( function_exists( $sym ) ) {
			return true;
		}
	}
	$slug = (string) ( $def['slug'] ?? '' );

	return '' !== $slug && isset( wpoptimizing_compat_active_slugs()[ $slug ] );
}

/**
 * Every detected plugin, as id => label. Memoised per request.
 *
 * @return array<string,string>
 */
function wpoptimizing_compat_detected() {
	static $found = null;
	if ( null !== $found ) {
		return $found;
	}
	$found = array();
	foreach ( wpoptimizing_compat_registry() as $id => $def ) {
		if ( wpoptimizing_compat_entry_active( $def ) ) {
			$found[ $id ] = (string) ( $def['label'] ?? $id );
		}
	}
	return $found;
}

/**
 * Capabilities the user has explicitly taken back from a detected plugin.
 *
 * @return array<string,bool> Capability key => true when ours runs anyway.
 */
function wpoptimizing_compat_overrides() {
	$raw  = (array) get_option( 'wpoptimizing_compat_overrides', array() );
	$keys = wpoptimizing_compat_capabilities();
	$out  = array();
	foreach ( $raw as $cap => $on ) {
		if ( isset( $keys[ $cap ] ) && $on ) {
			$out[ $cap ] = true;
		}
	}
	return $out;
}

/**
 * Which detected plugins provide a capability, whether or not we stand down for it.
 *
 * Unlike wpoptimizing_compat_owner(), this ignores the override: the UI needs to say "WP
 * Rocket also does this, you chose us" rather than pretend nothing was found.
 *
 * @param string $capability Capability key.
 * @return array<string,string> Plugin id => label.
 */
function wpoptimizing_compat_providers( $capability ) {
	$out = array();
	foreach ( wpoptimizing_compat_registry() as $id => $def ) {
		if ( ! in_array( $capability, (array) ( $def['covers'] ?? array() ), true ) ) {
			continue;
		}
		if ( isset( $def['settings'][ $capability ] ) && ! wpoptimizing_compat_setting_on( (array) $def['settings'][ $capability ] ) ) {
			continue;
		}
		if ( wpoptimizing_compat_entry_active( $def ) ) {
			$out[ $id ] = (string) ( $def['label'] ?? $id );
		}
	}
	return $out;
}

/**
 * Does the other plugin have this capability switched on, by its own settings? Any of the
 * options at its «on» value (null: any truthy value). An option it never wrote counts as on:
 * an install that never saved its settings runs its defaults, and assuming off would run both.
 *
 * @param array<string,mixed> $spec Option name => value that means on, or null.
 * @return bool
 */
function wpoptimizing_compat_setting_on( array $spec ) {
	foreach ( $spec as $option => $on ) {
		$value = get_option( (string) $option, null );
		if ( null === $value ) {
			return true;
		}
		if ( null === $on ? ! empty( $value ) && 'false' !== $value : (string) $value === (string) $on ) {
			return true;
		}
	}
	return false;
}

/**
 * The plugin that owns a capability, or '' when ours should run.
 *
 * This is THE call feature code makes: an empty string means "go ahead". It returns '' when
 * the user has overridden the capability, so a feature never has to know about overrides.
 *
 * @param string $capability Capability key.
 * @return string Label of the owning plugin, '' when ours runs.
 */
function wpoptimizing_compat_owner( $capability ) {
	if ( isset( wpoptimizing_compat_overrides()[ $capability ] ) ) {
		return '';
	}
	$providers = wpoptimizing_compat_providers( $capability );

	/**
	 * Filters the plugin that owns one of our capabilities.
	 *
	 * @since 0.1.0
	 * @param string $label      Owning plugin label, '' when ours runs.
	 * @param string $capability Capability key.
	 * @param array  $providers  All detected providers, id => label.
	 */
	return (string) apply_filters( 'wpoptimizing_compat_owner', (string) reset( $providers ), $capability, $providers );
}

/**
 * Do we cover everything this plugin is used for?
 *
 * Gates the "you can deactivate it" suggestion, and it is a DECLARED flag, not something
 * derived. The first version of this function compared the plugin's 'covers' against our own
 * capability list — which every entry satisfies by construction, so it answered true for WP
 * Rocket and would have told people to drop a plugin that does plenty we do not. Whether we
 * can replace a plugin is a judgement about that plugin, so somebody has to make it by hand.
 *
 * Set 'replaceable' => true only for a plugin whose whole job is something we do. Leave it
 * off for suites (WP Rocket, LiteSpeed, Perfmatters…) and for image plugins that compress
 * through their own cloud service, which we do not offer.
 *
 * @param string $plugin_id Registry id.
 * @return bool
 */
function wpoptimizing_compat_fully_covered( $plugin_id ) {
	$def = wpoptimizing_compat_registry()[ $plugin_id ] ?? null;
	if ( ! is_array( $def ) || empty( $def['replaceable'] ) ) {
		return false;
	}
	$ours = wpoptimizing_compat_capabilities();
	foreach ( (array) ( $def['covers'] ?? array() ) as $cap ) {
		// A declared flag still cannot promise a capability we do not actually have.
		if ( ! isset( $ours[ $cap ] ) ) {
			return false;
		}
	}

	return ! empty( $def['covers'] );
}
