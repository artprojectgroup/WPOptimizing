<?php
/**
 * Page-cache engine — pure decision + path logic, shared by the advanced-cache.php
 * drop-in (which runs BEFORE WordPress core loads) and the unit tests.
 *
 * The drop-in cannot call plugin/WordPress functions at the point it decides
 * whether to serve a cached page, so every rule here is a plain function that
 * takes the request state as arguments (method, URI, cookie header, UA, query
 * params, plus a config array) and returns a boolean/path. No I/O, no globals →
 * fully unit-testable, and identical logic in the drop-in and in the tests.
 *
 * Prefix `wpopt_pc_` (page cache). Kept dependency-free on purpose.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Default config used when a key is missing from the precompiled sidecar. The
 * sidecar (written by the plugin from the saved options) can extend these lists.
 *
 * @return array
 */
function wpopt_pc_default_config() {
	return array(
		// Cookie name fragments that forbid caching (logged-in, password-protected,
		// commenters). Substring match against the raw Cookie header.
		'reject_cookies'    => array(
			'wordpress_logged_in_',
			'wp-postpass_',
			'comment_author_',
			'comment_author_email_',
			/*
			 * A guest with a WooCommerce session is as personal as a logged-in user, and the
			 * store does NOT define DONOTCACHEPAGE outside cart/checkout/account. Storefront
			 * and most themes print the cart count in the header of every page, so without
			 * this a visitor with three items in their basket writes "3" into the shared
			 * cache and the next anonymous visitor is served it. Verified on the reference
			 * store: the front page and /shop/ both carry <span class="count">.
			 */
			'wp_woocommerce_session_',
			'woocommerce_items_in_cart',
			'woocommerce_cart_hash',
			// EDD does the same thing with its own cart.
			'edd_items_in_cart',
		),
		// URI substrings/patterns that are never cached (feeds, embeds, the WP REST
		// namespace). Checked as substrings against the path.
		'reject_uri'        => array(
			'/feed/',
			'/embed/',
			'/wp-json/',
		),
		// Request path endings never cached (dynamic endpoints).
		'reject_extensions' => array( '.php', '.xml', '.xsl' ),
		// Exact filenames never cached.
		'reject_files'      => array( 'robots.txt', '.htaccess', 'wp-login.php', 'xmlrpc.php', 'wp-cron.php' ),
		// User agents never served/stored (link-preview bots).
		'reject_ua'         => array( 'facebookexternalhit', 'WhatsApp' ),
		/*
		 * Query parameters that STILL allow caching. Presence of any other one disables
		 * caching for that request.
		 *
		 * `s` IS DELIBERATELY NOT HERE. Every distinct value of a whitelisted parameter
		 * gets its own cache file, so a parameter whose value space is unbounded lets a
		 * visitor create unbounded files: /?s=aaaa1, /?s=aaaa2, … until the disk fills.
		 * It is the same reasoning that already guards the Host header in
		 * wpopt_pc_host_ok(), applied to the one place it had been missed. Search results
		 * are also close to worthless in a page cache: almost every query is unique.
		 *
		 * The sidecar can only ever ADD to this list, so a site that really wants to
		 * cache a bounded parameter of its own can still do it — knowingly.
		 */
		'query_whitelist'   => array( 'lang' ),
		/*
		 * REST API routes stored like pages, for anonymous GETs only (Pro, the site's own list:
		 * «Cache these REST API routes»). Empty by default: a route's answer may depend on the
		 * visitor (a nonce, a geolocated region, the consent given), and only the site can say
		 * that one of its routes does not. Matched whole, without «/wp-json/» and slashes.
		 */
		'rest_routes'       => array(),
		/*
		 * Text files WordPress or a plugin generates in PHP, stored like pages for anonymous GETs
		 * (Pro): llms.txt, robots.txt and the XML sitemaps. Paths from the site root, «*» for
		 * any run of characters but «/». Empty here: the sidecar brings the list
		 * (wpoptimizing_page_cache_text_file_patterns()).
		 */
		'text_files'        => array(),
		/*
		 * Tracking params stripped before the query check, so ?utm_source=… still gets the cached
		 * page. Every ad network, newsletter and analytics tool adds its own; with only ten of
		 * them, a link shared from Google Ads (gbraid, gad_source), Microsoft (msclkid), TikTok
		 * (ttclid) or Matomo (mtm_*) skipped the cache. The common list of the page caches that
		 * maintain one (WP Rocket's, 06/11/2026), minus the few that change the page.
		 */
		'ignored_query'     => array(
			'_bta_c',
			'_bta_tid',
			'_ga',
			'_gl',
			'_hsenc',
			'_hsmi',
			'_ke',
			'_kx',
			'_sc',
			'_se',
			'adgroupid',
			'adid',
			'campaignid',
			'dm_i',
			'ef_id',
			'epik',
			'fb_action_ids',
			'fb_action_types',
			'fb_source',
			'fbadid',
			'fbclid',
			'gad',
			'gad_campaignid',
			'gad_source',
			'gadid',
			'gbraid',
			'gclid',
			'gclsrc',
			'gdffi',
			'gdfms',
			'gdftrk',
			'hsa_acc',
			'hsa_ad',
			'hsa_cam',
			'hsa_grp',
			'hsa_kw',
			'hsa_la',
			'hsa_mt',
			'hsa_net',
			'hsa_ol',
			'hsa_src',
			'hsa_tgt',
			'hsa_ver',
			'igshid',
			'li_fat_id',
			'mc_cid',
			'mc_eid',
			'mkwid',
			'msclkid',
			'mtm_campaign',
			'mtm_cid',
			'mtm_content',
			'mtm_keyword',
			'mtm_kwd',
			'mtm_medium',
			'mtm_source',
			'pcrid',
			'pk_campaign',
			'pk_cid',
			'pk_content',
			'pk_keyword',
			'pk_medium',
			'pk_source',
			'redirect_log_mongo_id',
			'redirect_mongo_id',
			's_kwcid',
			'sb_referer_host',
			'sfmc_activityid',
			'sfmc_id',
			'srsltid',
			'sscid',
			'ssp_iaba',
			'ssp_iabi',
			'trk_contact',
			'trk_module',
			'trk_msg',
			'trk_sid',
			'ttclid',
			'twclid',
			'usqp',
			'utm_campaign',
			'utm_content',
			'utm_creative_format',
			'utm_expid',
			'utm_id',
			'utm_marketing_tactic',
			'utm_medium',
			'utm_source',
			'utm_source_platform',
			'utm_term',
			'vgo_ee',
			'wbraid',
			'yclid',
		),
		/*
		 * Cookies that change the PRICE on the same URL (wpopt_pc_cookie_key()). EMPTY here on
		 * purpose: the sidecar lists only those of the multi-currency plugin that is ACTIVE
		 * (wpoptimizing_page_cache_vary_cookies()). Listing them always let anyone create a copy
		 * per invented value on a store that does not even read the cookie (Codex, 06/11/2026).
		 */
		'vary_cookies'      => array(),
	);
}

/**
 * Merge a (partial) sidecar config over the defaults. Lists in the sidecar EXTEND
 * the defaults (union), they don't replace them, so a user can only ever add more
 * exclusions, never remove a safety-critical one.
 *
 * @param array $config Sidecar config (may be partial/empty).
 * @return array
 */
function wpopt_pc_config( $config = array() ) {
	$defaults = wpopt_pc_default_config();
	$out      = $defaults;
	foreach ( $defaults as $key => $default_list ) {
		if ( isset( $config[ $key ] ) && is_array( $config[ $key ] ) ) {
			$out[ $key ] = array_values( array_unique( array_merge( $default_list, $config[ $key ] ) ) );
		}
	}
	// Authoritative (not extendable) sidecar values: the host allow-list is a restriction,
	// so it replaces rather than extends, and an absent list means "no restriction".
	if ( isset( $config['allowed_hosts'] ) && is_array( $config['allowed_hosts'] ) ) {
		$out['allowed_hosts'] = array_values( array_filter( array_map( 'strval', $config['allowed_hosts'] ) ) );
	}
	return $out;
}

/**
 * Is the request method cacheable? Only GET and HEAD.
 *
 * @param string $method HTTP method.
 * @return bool
 */
function wpopt_pc_method_ok( $method ) {
	$method = strtoupper( (string) $method );
	return 'GET' === $method || 'HEAD' === $method;
}

/**
 * Does the raw Cookie header contain any reject cookie?
 *
 * @param string $cookie_header Raw HTTP Cookie header.
 * @param array  $config        Merged config.
 * @return bool True when a reject cookie is present.
 */
function wpopt_pc_has_reject_cookie( $cookie_header, $config ) {
	$cookie_header = (string) $cookie_header;
	if ( '' === $cookie_header ) {
		return false;
	}
	foreach ( (array) $config['reject_cookies'] as $needle ) {
		if ( '' !== $needle && false !== strpos( $cookie_header, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Is the user agent on the reject list?
 *
 * @param string $ua     User agent.
 * @param array  $config Merged config.
 * @return bool
 */
function wpopt_pc_is_rejected_ua( $ua, $config ) {
	$ua = (string) $ua;
	if ( '' === $ua ) {
		return false;
	}
	foreach ( (array) $config['reject_ua'] as $needle ) {
		if ( '' !== $needle && false !== stripos( $ua, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Split a request URI into path and parsed query params.
 *
 * @param string $request_uri $_SERVER['REQUEST_URI'].
 * @return array{path:string,query:array}
 */
function wpopt_pc_parse_uri( $request_uri ) {
	$request_uri = (string) $request_uri;
	$path        = $request_uri;
	$query       = array();
	$qpos        = strpos( $request_uri, '?' );
	if ( false !== $qpos ) {
		$path      = substr( $request_uri, 0, $qpos );
		$query_str = substr( $request_uri, $qpos + 1 );
		parse_str( $query_str, $query );
	}
	return array(
		'path'  => $path,
		'query' => is_array( $query ) ? $query : array(),
	);
}

/**
 * Is the path a rejected file/extension/uri pattern?
 *
 * @param string $path   URI path (no query).
 * @param array  $config Merged config.
 * @return bool
 */
function wpopt_pc_is_rejected_path( $path, $config ) {
	return '' !== wpopt_pc_rejected_path_reason( $path, $config );
}

/**
 * Why the path is never cached: 'file' (a named file or a dynamic extension: wp-cron.php,
 * admin-ajax.php, sitemap.xml…), 'uri' (a URI pattern: /feed/, /wp-json/, the site's own) or ''
 * when it may be cached. Two reasons because the hit diagnostics must tell endpoints that are
 * never pages from pages the site excluded. Pure.
 *
 * @param string $path   URI path (no query).
 * @param array  $config Merged config.
 * @return string
 */
function wpopt_pc_rejected_path_reason( $path, $config ) {
	$path  = (string) $path;
	$lower = strtolower( $path );
	// A text file the site chose to cache (llms.txt, robots.txt, a sitemap): the .xml/.xsl and
	// robots.txt rules below are for pages that are not pages, and these are kept on purpose.
	$text = false;

	// Exact filename at the end of the path (robots.txt, wp-login.php, …).
	$basename = basename( $lower );
	foreach ( (array) $config['reject_files'] as $file ) {
		if ( strtolower( $file ) === $basename && ! ( $text && 'robots.txt' === $basename ) ) {
			return 'file';
		}
	}
	// Dynamic extensions (.php/.xml/.xsl), but /index.php is allowed (front controller).
	if ( 'index.php' !== $basename && ! $text ) {
		foreach ( (array) $config['reject_extensions'] as $ext ) {
			if ( '' !== $ext && substr( $lower, -strlen( $ext ) ) === strtolower( $ext ) ) {
				return 'file';
			}
		}
	}
	// Substring patterns (/feed/, /embed/, /wp-json/). Also match a trailing form
	// without the closing slash (…/feed).
	$rest = false;
	foreach ( (array) $config['reject_uri'] as $needle ) {
		$needle = strtolower( (string) $needle );
		if ( '' === $needle ) {
			continue;
		}
		// A REST route the site chose to cache passes the REST namespace's own pattern (and only
		// that one: any other pattern of the site still keeps it out).
		if ( $rest && '/wp-json/' === $needle ) {
			continue;
		}
		if ( false !== strpos( $lower, $needle ) ) {
			return 'uri';
		}
		$trimmed = rtrim( $needle, '/' );
		if ( '' !== $trimmed && substr( $lower, -strlen( $trimmed ) ) === $trimmed ) {
			return 'uri';
		}
	}
	return '';
}


/**
 * After removing ignored (tracking) params, is the remaining query cacheable?
 * Cacheable when empty, or when every remaining key is whitelisted.
 *
 * @param array $query  Parsed query params.
 * @param array $config Merged config.
 * @return bool
 */
function wpopt_pc_query_ok( $query, $config ) {
	if ( ! is_array( $query ) || array() === $query ) {
		return true;
	}
	$ignored   = array_map( 'strtolower', (array) $config['ignored_query'] );
	$whitelist = array_map( 'strval', (array) $config['query_whitelist'] );
	foreach ( $query as $key => $value ) {
		$key = (string) $key;
		if ( in_array( strtolower( $key ), $ignored, true ) ) {
			continue; // Tracking param → ignored, does not block caching (it never changes the render).
		}
		/*
		 * Case-SENSITIVE, like $_GET and WPML. Lower-casing the name let ?LANG=fr through the
		 * gate and into the ?lang=fr entry, while WordPress ignored LANG and rendered the default
		 * language: one visitor poisoned the French page for everybody.
		 */
		if ( ! in_array( $key, $whitelist, true ) ) {
			return false; // A non-whitelisted, non-ignored param → don't cache.
		}
		if ( ! wpopt_pc_query_value_ok( $key, $value ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Is a whitelisted parameter's value one the cache may file? Pure.
 *
 * Every distinct value gets its own cache file, so a parameter whose values are unbounded lets a
 * visitor fill the disk (the same reason `s` is not whitelisted). `lang` only ever carries a
 * language code (fr, pt-br, zh-hans), so anything else is rendered uncached. An array value
 * (lang[]=x) is never a language either.
 *
 * @param string $key   Parameter name.
 * @param mixed  $value Parameter value.
 * @return bool
 */
function wpopt_pc_query_value_ok( $key, $value ) {
	if ( 'lang' !== $key ) {
		return true; // A parameter the site added itself: that decision was taken knowingly.
	}
	return is_string( $value ) && 1 === preg_match( '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/i', $value );
}

/**
 * Is this request's Host header one the install actually answers to? Pure.
 *
 * The cache path is keyed by host, so an unvalidated Host header lets a visitor create an
 * unbounded number of cache directories. When the sidecar carries no host list (a config
 * written by an older version) we fail OPEN, so upgrading never turns caching off.
 *
 * @param string $host   Raw Host header (may carry a port).
 * @param array  $config Merged config.
 * @return bool
 */
function wpopt_pc_host_ok( $host, $config ) {
	$allowed = isset( $config['allowed_hosts'] ) ? (array) $config['allowed_hosts'] : array();
	if ( array() === $allowed ) {
		return true;
	}
	$host = strtolower( (string) $host );
	$host = explode( ':', $host, 2 )[0]; // Drop the port.
	foreach ( $allowed as $candidate ) {
		if ( strtolower( (string) $candidate ) === $host ) {
			return true;
		}
	}
	return false;
}

/*
 * ── Multisite: which site of the network a request belongs to ────────────────
 * On a network every site keeps its own settings, its own purges and its own lifetime, but there
 * is one advanced-cache.php for all of them, and it runs before WordPress knows which site is
 * being asked for. So each site writes its sidecar under a name made of its host and path
 * («sites/example.com~en.php»), and the drop-in looks for the file of the request's host and
 * first path segments, longest first, the way core's get_site_by_path() picks a site — with no
 * central list to keep in step, so two sites saving at once never overwrite each other (WP Rocket
 * does the same with its per-site config files). A host with no file is not cached. What this
 * cannot reproduce (sunrise.php, the site_by_path filters) is caught after the render: a page is
 * only stored when the site WordPress loaded is the one whose sidecar was read (advanced-cache.php).
 */

/**
 * A host as a network stores a site's domain: lower case, without the default ports, which
 * WordPress strips before looking a site up (ms-settings.php); any other port stays. Pure.
 *
 * @param string $host Host header or site domain.
 * @return string
 */
function wpopt_pc_site_host( $host ) {
	$host = strtolower( trim( (string) $host ) );
	if ( 1 === preg_match( '/^(.+):(80|443)$/', $host, $m ) ) {
		$host = $m[1];
	}
	return $host;
}


/**
 * Stable cache key for the part of the query string that is allowed to be cached.
 *
 * `query_whitelist` lets a few parameters through the gate (`s`, `lang`, …), so those
 * requests MUST get their own cache entry: without this, /?lang=fr and / mapped to the
 * same file and the first visitor decided what everybody else saw. Tracking parameters
 * (`ignored_query`) are dropped first, so ?utm_source=x still shares the clean entry.
 *
 * Pure: returns '' when nothing cache-relevant remains, otherwise a short hex digest.
 *
 * @param array $query  Parsed query params.
 * @param array $config Merged config.
 * @return string
 */
function wpopt_pc_query_key( $query, $config ) {
	if ( ! is_array( $query ) || array() === $query ) {
		return '';
	}
	$ignored = array_map( 'strtolower', (array) $config['ignored_query'] );
	$kept    = array();
	foreach ( $query as $key => $value ) {
		$key = (string) $key;
		if ( in_array( strtolower( $key ), $ignored, true ) ) {
			continue;
		}
		// The name is kept as sent: the gate compares it case-sensitively (wpopt_pc_query_ok()).
		$kept[ $key ] = is_scalar( $value ) ? (string) $value : wpopt_pc_json_fallback( $value );
	}
	if ( array() === $kept ) {
		return '';
	}
	ksort( $kept );
	$flat = array();
	foreach ( $kept as $k => $v ) {
		$flat[] = $k . '=' . $v;
	}
	return substr( md5( implode( '&', $flat ) ), 0, 12 );
}

/**
 * Minimal json_encode fallback for the drop-in, which runs before WordPress loads.
 *
 * @param mixed $value Value.
 * @return string
 */
function wpopt_pc_json_fallback( $value ) {
	$encoded = json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- runs before WordPress loads.
	return is_string( $encoded ) ? $encoded : '';
}

/**
 * EARLY gate (drop-in, before WordPress loads): can this request be served from
 * cache and/or start the caching buffer, judged from $_SERVER + cookies alone?
 *
 * @param string $method       HTTP method.
 * @param string $request_uri  $_SERVER['REQUEST_URI'].
 * @param string $cookie_header Raw Cookie header.
 * @param string $ua           User agent.
 * @param array  $config       Merged config (from wpopt_pc_config()).
 * @return bool
 */
function wpopt_pc_can_serve_early( $method, $request_uri, $cookie_header, $ua, $config ) {
	return '' === wpopt_pc_early_reason( $method, $request_uri, $cookie_header, $ua, $config );
}

/**
 * The early gate with its verdict spelled out: '' when the request may be served from cache
 * or stored, otherwise why not — 'method', 'loggedin' (the WordPress login cookie), 'cookie'
 * (any other reject cookie: cart, password, commenter, the site's own), 'agent', 'file', 'uri'
 * or 'query'. A hit ratio alone does not say what to fix; the reason does (W3TC keeps one for
 * the same purpose). Same order and rules as before, so the yes/no answer is unchanged. Pure.
 *
 * @param string $method        HTTP method.
 * @param string $request_uri   $_SERVER['REQUEST_URI'].
 * @param string $cookie_header Raw Cookie header.
 * @param string $ua            User agent.
 * @param array  $config        Merged config (from wpopt_pc_config()).
 * @return string
 */
function wpopt_pc_early_reason( $method, $request_uri, $cookie_header, $ua, $config ) {
	if ( ! wpopt_pc_method_ok( $method ) ) {
		return 'method';
	}
	if ( wpopt_pc_has_reject_cookie( $cookie_header, $config ) ) {
		return false !== strpos( (string) $cookie_header, 'wordpress_logged_in_' ) ? 'loggedin' : 'cookie';
	}
	if ( wpopt_pc_is_rejected_ua( $ua, $config ) ) {
		return 'agent';
	}
	$parts = wpopt_pc_parse_uri( $request_uri );
	$path  = wpopt_pc_rejected_path_reason( $parts['path'], $config );
	if ( '' !== $path ) {
		return $path;
	}
	if ( ! wpopt_pc_query_ok( $parts['query'], $config ) ) {
		return 'query';
	}
	return '';
}

/**
 * LATE gate (at buffer close, WordPress loaded): can this rendered response be
 * stored? Judged from the HTTP code, the buffer, and WP state flags passed in by
 * the drop-in (so this stays pure and testable).
 *
 * @param int    $http_code HTTP status code.
 * @param string $buffer    Rendered HTML.
 * @param array  $flags     { is_admin, is_404, is_search, is_feed, donotcachepage,
 *                            is_user_logged_in, is_rejected_page, is_rejected_type,
 *                            sets_cookie, forbids_store } booleans.
 * @return bool
 */
function wpopt_pc_can_store( $http_code, $buffer, $flags ) {
	return '' === wpopt_pc_late_reason( $http_code, $buffer, $flags );
}

/**
 * The late gate with its verdict spelled out: '' when the rendered page may be stored,
 * otherwise the first rule that refused it ('admin', '404', 'search', 'feed', 'donotcache',
 * 'loggedin', 'page', 'type', 'setcookie', 'nostore', 'status', 'nothtml'). The flags are
 * read before the status code so a not-found page reads «404», not just «status». Pure.
 *
 * @param int    $http_code HTTP status code.
 * @param string $buffer    Rendered HTML.
 * @param array  $flags     Same flags as wpopt_pc_can_store().
 * @return string
 */
function wpopt_pc_late_reason( $http_code, $buffer, $flags ) {
	$flags = (array) $flags;
	/*
	 * 'sets_cookie' is the rule the others are special cases of. Listing cookie names only
	 * ever covers the plugins somebody thought of; a response that emits Set-Cookie is telling
	 * us, whatever it is, that this render belongs to one visitor. Storing it hands that
	 * visitor's page to everybody else, and the cookie names that would have caught it are
	 * exactly the ones nobody listed yet.
	 */
	/*
	 * 'is_feed' as well: a hit is always served as text/html, so a feed stored here (?feed=rss2
	 * on a site that whitelists it, a custom feed endpoint the URI patterns do not know) came
	 * back to feed readers as an HTML page.
	 */
	// 'is_rejected_type': the site's own list of page types (front page, archives…:
	// wpopt_pc_type_rejected()).
	$reasons = array(
		'is_admin'          => 'admin',
		'is_404'            => '404',
		'is_search'         => 'search',
		'is_feed'           => 'feed',
		'donotcachepage'    => 'donotcache',
		'is_user_logged_in' => 'loggedin',
		'is_rejected_page'  => 'page',
		'is_rejected_type'  => 'type',
		'sets_cookie'       => 'setcookie',
		'forbids_store'     => 'nostore',
		'incomplete'        => 'incomplete',
	);
	foreach ( $reasons as $flag => $reason ) {
		if ( ! empty( $flags[ $flag ] ) ) {
			return $reason;
		}
	}
	if ( 200 !== (int) $http_code ) {
		return 'status';
	}
	$buffer = (string) $buffer;
	// A REST route the site chose to cache: a JSON document, not a page ('is_rest' is only ever
	// set for those, see wpopt_pc_rest_allowed()). An empty answer or an error page is not kept.
	if ( ! empty( $flags['is_rest'] ) ) {
		$first = substr( ltrim( $buffer ), 0, 1 );
		return '{' === $first || '[' === $first || '"' === $first ? '' : 'nothtml';
	}
	// A text file the site chose to cache (wpopt_pc_text_file_allowed()): kept only as the text
	// or XML it is meant to be. A theme's 404 page or an empty answer under that address is not.
	if ( ! empty( $flags['is_text'] ) ) {
		return ! empty( $flags['text_type_ok'] ) && '' !== trim( $buffer ) ? '' : 'nothtml';
	}
	// Must look like a full HTML document.
	if ( strlen( $buffer ) < 255 || false === stripos( $buffer, '</html>' ) ) {
		return 'nothtml';
	}
	return '';
}

/**
 * Was the render cut short? Pure.
 *
 * A page has to end in </html> to be stored, so a cut page never was; a text file has no such
 * end, and half of artprojectgroup.es's llms.txt was served for hours (18/11/2026): Rank Math
 * writes it line by line for seconds, and the request that rebuilt it had already left. When
 * PHP stops a script, the output buffer is still closed and handed over, so this is asked there.
 *
 * @param int        $status       connection_status(): 1 aborted, 2 time limit.
 * @param bool       $ignore_abort ignore_user_abort(): the script runs on when the client leaves.
 * @param array|null $last_error   error_get_last().
 * @return bool
 */
function wpopt_pc_render_incomplete( $status, $ignore_abort, $last_error ) {
	$status = (int) $status;
	if ( $status & 2 ) {
		return true;
	}
	if ( ( $status & 1 ) && ! $ignore_abort ) {
		return true;
	}
	$fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR;
	return is_array( $last_error ) && isset( $last_error['type'] ) && ( (int) $last_error['type'] & $fatal );
}

/**
 * Is this page of a type the site chose never to cache? Pure.
 *
 * The rules come from the sidecar ('reject_types', Pro) and the context from WordPress at buffer
 * close (wpopt_pc_page_context()). A sidecar written before these rules existed has no key, so
 * nothing is excluded. The posts page counts only when it is not also the front page: on a site
 * that shows its latest posts on the front page both conditionals are true, and that page is
 * the front page (W3TC draws the same line). The front page keeps its own pagination with it
 * (is_front_page() is still true on /page/2/), which is what someone excluding it expects.
 *
 * @param array $rules { front, home, archives, paged: bool, post_types: string[] }.
 * @param array $ctx   { front, home, archive, paged: bool, post_type: string ('' unless singular) }.
 * @return bool
 */
function wpopt_pc_type_rejected( $rules, $ctx ) {
	$rules = (array) $rules;
	$ctx   = (array) $ctx;
	if ( ! empty( $rules['front'] ) && ! empty( $ctx['front'] ) ) {
		return true;
	}
	if ( ! empty( $rules['home'] ) && ! empty( $ctx['home'] ) && empty( $ctx['front'] ) ) {
		return true;
	}
	if ( ! empty( $rules['archives'] ) && ! empty( $ctx['archive'] ) ) {
		return true;
	}
	if ( ! empty( $rules['paged'] ) && ! empty( $ctx['paged'] ) ) {
		return true;
	}
	$type = isset( $ctx['post_type'] ) ? (string) $ctx['post_type'] : '';
	return '' !== $type && ! empty( $rules['post_types'] ) && in_array( $type, (array) $rules['post_types'], true );
}

/**
 * What kind of page WordPress rendered, for wpopt_pc_type_rejected(). Read at buffer close.
 *
 * Every conditional is guarded: the buffer can close on a request that never set up the main
 * query (an early exit, a fatal), and is_front_page() without one calls _doing_it_wrong() from
 * inside an output handler.
 *
 * @return array { front, home, archive, paged: bool, post_type: string }
 */
function wpopt_pc_page_context() {
	$ctx = array(
		'front'     => false,
		'home'      => false,
		'archive'   => false,
		'paged'     => false,
		'post_type' => '',
	);
	if ( ! isset( $GLOBALS['wp_query'] ) || ! is_object( $GLOBALS['wp_query'] ) || ! function_exists( 'is_front_page' ) ) {
		return $ctx;
	}
	$ctx['front']   = (bool) is_front_page();
	$ctx['home']    = (bool) is_home();
	$ctx['archive'] = (bool) is_archive();
	$ctx['paged']   = (bool) is_paged();
	if ( is_singular() ) {
		$obj = get_queried_object();
		if ( is_object( $obj ) && isset( $obj->post_type ) ) {
			$ctx['post_type'] = (string) $obj->post_type;
		}
	}
	return $ctx;
}

/**
 * Did the sidecar change while this request was rendering?
 *
 * Saving exclusions rewrites the sidecar and then empties the cache, but a request that started
 * before the save read the old rules and stores its page when it finishes: after the purge, an
 * excluded page was back on disk until it expired. Comparing the sidecar this request started
 * with against the one on disk now closes that window. Only on a request about to store a page;
 * the include is served by OPcache, which wpoptimizing_page_cache_write_config() invalidates.
 *
 * @param string $file   Sidecar path.
 * @param mixed  $loaded The sidecar as this request read it.
 * @return bool True when it is gone or differs.
 */
function wpopt_pc_config_changed( $file, $loaded ) {
	if ( '' === (string) $file || ! is_readable( $file ) ) {
		return true;
	}
	$now = include $file;
	return $now !== $loaded;
}

/*
 * ── Invalidation generations ─────────────────────────────────────────────────
 * A request that started rendering BEFORE an edit read the old content, but it stores its page
 * when it ends, which can be AFTER the purge that followed the edit: the old page was back on
 * disk until it expired (Codex, 08/11/2026). wpopt_pc_config_changed() only catches a changed
 * sidecar, not a content change. So every purge leaves a time mark — «.purged» in the folder of
 * the purged URL, «.purged-all» in the cache root for a full purge — and a request that is about
 * to store (and once more after storing) compares those marks with the moment it started: a
 * mark from after its start means its page may predate the purge, and it is not kept.
 *
 * The purge writes the mark BEFORE it deletes anything. Then either the render's write lands
 * before the deletion (and is deleted), or after it — and the render's own check after writing
 * is later still, so it already sees the mark. The marks are dot files: the sweep and the purges
 * only look at «index*» / «static*», and the cache folder's .htaccess denies everything but the
 * static twins, so a mark is never served nor counted as a page.
 */

/**
 * The marks that can invalidate a copy being written. Pure.
 *
 * Its own folder (every variant of one URL lives there), the full-purge mark in the root, and
 * for «…/page/N/» the folder of the listing it paginates: purging a listing also deletes its
 * pagination (wpoptimizing_page_cache_clear_url()), including pages being rendered for the first
 * time, whose folder does not exist yet and so could not carry a mark of its own.
 *
 * On a network, a site's full purge leaves its «.purged-all» in the site's own folder
 * ($site_root): the cache root's mark is the whole network's.
 *
 * @param string $plain     Path of the plain copy (wpopt_pc_cache_file()).
 * @param string $base      Cache root.
 * @param string $site_root The site's folder on a network ('' = none: a single site).
 * @return string[]
 */
function wpopt_pc_purge_marks( $plain, $base, $site_root = '' ) {
	$dir   = dirname( (string) $plain );
	$marks = array( $dir . '/.purged' );
	if ( 1 === preg_match( '#^(.+)/page/[0-9]+$#', $dir, $m ) ) {
		$marks[] = $m[1] . '/.purged';
	}
	$base    = rtrim( (string) $base, '/' );
	$marks[] = $base . '/.purged-all';
	$site    = rtrim( (string) $site_root, '/' );
	if ( '' !== $site && $site !== $base ) {
		$marks[] = $site . '/.purged-all';
	}
	return $marks;
}

/**
 * Is any of these purge times later than the start of the render? Pure.
 *
 * @param array $stamps Purge times (microtime floats; 0 = no mark).
 * @param float $start  When the render started (microtime).
 * @return bool
 */
function wpopt_pc_purged_since( array $stamps, $start ) {
	foreach ( $stamps as $stamp ) {
		if ( (float) $stamp > (float) $start ) {
			return true;
		}
	}
	return false;
}

/**
 * Was anything that covers this copy purged after the render started?
 *
 * Read only by a request about to store a page, never on a hit: a few bytes per mark.
 *
 * @param string $plain     Path of the plain copy.
 * @param string $base      Cache root.
 * @param float  $start     When the render started (microtime).
 * @param string $site_root The site's folder on a network ('' = a single site).
 * @return bool
 */
function wpopt_pc_purged_after( $plain, $base, $start, $site_root = '' ) {
	$stamps = array();
	foreach ( wpopt_pc_purge_marks( $plain, $base, $site_root ) as $mark ) {
		$stamps[] = is_file( $mark ) ? (float) @file_get_contents( $mark ) : 0.0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Runs before WordPress; a mark replaced meanwhile reads as 0.
	}
	return wpopt_pc_purged_since( $stamps, $start );
}

/**
 * The lock that orders purge marks and page publications: «.purge.lock» in the cache root.
 *
 * ONE LOCK, TWO JOBS (Codex, 08/11/2026). A purge takes it exclusively while it reads and
 * rewrites its mark, so two purges of the same URL can no longer publish their marks out of
 * order (wpopt_pc_mark_purge()). A request storing a page takes it shared while it makes its
 * last check and renames its files into place (wpopt_pc_publish_copy()), so no purge can mark
 * between that check and the publication: a page is either published before the mark — and the
 * purge's deletion, which follows the mark, removes it — or not published at all. The root is
 * never moved by a full purge and the lock is never deleted, so every process always locks the
 * same file. Only held for microseconds; the kernel releases it if the process dies.
 *
 * WHAT A HOLDER WAITS ON. A request holding it shared takes one more lock inside: its variant's
 * publication lock, without waiting (LOCK_NB, wpopt_pc_variant_lock()). A full purge holding it
 * exclusively waits on nothing but its own renames. So the holders never block on a second lock
 * and no two of them can wait on each other; what can wait is a purge asking for it while
 * requests publish, for as long as their renames take.
 *
 * A LOCK FILE IT CANNOT WRITE TO. Created first by `wp … --allow-root` or a deploy user, the file
 * was root:root 0644 and the web server's fopen( 'c' ) failed: no page was ever stored again, and
 * nothing said so (audit, 08/11/2026). wpopt_pc_open_lock() falls back to a read-only handle,
 * which flock() accepts on local filesystems; the cache screen reports the file meanwhile
 * (wpoptimizing_page_cache_unwritable()), and a root process now gives what it creates to the
 * cache folder's owner (wpopt_pc_own()).
 *
 * @param string $base Cache root.
 * @param int    $mode LOCK_SH or LOCK_EX, blocking unless LOCK_NB is added.
 * @return resource|false False when the file cannot be opened or locked (see the callers).
 */
function wpopt_pc_marks_lock( $base, $mode ) {
	$fh = wpopt_pc_open_lock( rtrim( (string) $base, '/' ) . '/.purge.lock' );
	if ( false === $fh ) {
		return false;
	}
	if ( ! flock( $fh, (int) $mode ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false;
	}
	return $fh;
}

/**
 * Leave a purge mark, written atomically so a reader never sees half of it. The folder is
 * created when missing: a URL whose first copy is being rendered right now has no folder yet,
 * and that render is exactly the one the mark has to stop.
 *
 * A MARK NEVER GOES BACK. The time used to be taken before the write and nothing ordered two
 * purges: one suspended between its clock read and its rename published an OLDER time over a
 * newer purge's mark, and a render that started between the two purges then passed the check
 * and stored a page from before the second one (Codex, 08/11/2026). Now the time is read inside
 * the exclusive lock and the mark becomes max(mark on disk, now): purges are serialized and a
 * mark only ever moves forward, so «mark > start of the render» holds for every purge that came
 * after the render started. The drop-in compares with microtime() too: the same clock.
 *
 * @param string $dir  Folder of the purged URL, or the cache root.
 * @param bool   $all  Full purge («.purged-all»).
 * @param string $base Cache root, where the lock lives (wpopt_pc_marks_lock()); '' = $dir.
 * @return bool
 */
function wpopt_pc_mark_purge( $dir, $all = false, $base = '' ) {
	$dir  = rtrim( (string) $dir, '/' );
	$mark = $dir . '/' . ( $all ? '.purged-all' : '.purged' );
	$base = '' !== (string) $base ? (string) $base : $dir;
	if ( ! is_dir( $base ) ) {
		wpopt_pc_mkdir( $base );
	}
	// Without the lock (a filesystem that refuses flock) the mark is still written: an unordered
	// mark is better than none, and the max() still keeps the common case right.
	$lock = wpopt_pc_marks_lock( $base, LOCK_EX );
	$ok   = wpopt_pc_write_mark( $mark );
	wpopt_pc_release_lock( $lock );
	return $ok;
}

/**
 * Write one purge mark as max(mark on disk, now). The caller holds the marks lock exclusively
 * (wpopt_pc_mark_purge(), or the full purge, which keeps it while it moves the host folders).
 *
 * @param string $mark Path of the «.purged» / «.purged-all» file.
 * @return bool
 */
function wpopt_pc_write_mark( $mark ) {
	clearstatcache( true, (string) $mark );
	$last = is_file( $mark ) ? (float) @file_get_contents( $mark ) : 0.0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Runs before WordPress.
	return wpopt_pc_write_atomic( (string) $mark, sprintf( '%.6F', max( $last, microtime( true ) ) ) );
}

/**
 * Remove a copy this request has just written: the page, its gzip, its Brotli copy, its .meta
 * and the twins the web server would serve without PHP. The Brotli path is derived here (it is
 * always the plain one plus «.br»), so a caller written before Brotli cannot leave one behind.
 *
 * Only for drop-ins up to v15, which publish first and check after. It deletes BY NAME, so it can
 * also delete a newer request's good copy written meanwhile (Codex, 08/11/2026); v16 publishes
 * through wpopt_pc_publish_copy() instead, which never deletes a file it did not write.
 *
 * @param string $plain Plain copy.
 * @param string $gz    Gzip copy.
 * @return void
 */
function wpopt_pc_forget_copy( $plain, $gz ) {
	foreach ( array( (string) $plain, (string) $gz, (string) $plain . '.br', (string) $plain . '.meta' ) as $file ) {
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Before WordPress.
	}
}

/*
 * ── WP-Cron from cache hits (a fallback, not a guarantee) ────────────────────
 * WordPress runs its scheduled tasks from the requests that load it. When almost every visit is
 * answered from this cache, WordPress hardly ever loads, and scheduled posts, the preload or
 * WooCommerce's tasks wait for the next miss or login. So a hit served by PHP fires wp-cron.php
 * when a task is due, at most once a minute for the whole site.
 *
 * It is only a fallback: a page sent by the web server without PHP (page-cache-static.php) or by
 * a CDN never runs this code, and a site with no visits has no hits at all. A site served almost
 * entirely from cache wants a system cron that calls wp-cron.php every minute (the cache screen
 * says so when the cron runs late), with DISABLE_WP_CRON — which this code respects.
 *
 * Knowing that a task is due without WordPress: WordPress writes the time of the next event to
 * «.cron-next» in the cache root every time it saves its cron list (page-cache.php). The request
 * carries NO doing_wp_cron token: wp-cron.php only accepts a token that matches the doing_cron
 * transient spawn_cron() sets, which this code cannot set; without one, wp-cron.php takes its
 * own lock, exactly as when a system cron calls it.
 */

/**
 * Is a scheduled task due? Pure.
 *
 * @param int $next Time of the next event (0 = none known).
 * @param int $now  Now.
 * @return bool
 */
function wpopt_pc_cron_due( $next, $now ) {
	return (int) $next > 0 && (int) $next <= (int) $now;
}

/**
 * Where to connect and what to send to call wp-cron.php. Pure.
 *
 * Straight to the address the plugin learnt this server answers on (includes/loopback.php),
 * when there is one, so the request never goes through the CDN in front of the site (whose bot
 * protection would challenge it); the host name is kept in the Host header and as the TLS name.
 *
 * @param string $url  wp-cron.php URL (site_url( 'wp-cron.php' )).
 * @param string $addr Learnt IP address, or '' for the URL's own host.
 * @return array|null { remote, peer, request } or null when the URL is unusable.
 */
function wpopt_pc_cron_request( $url, $addr = '' ) {
	$parts = parse_url( (string) $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs before WordPress.
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return null;
	}
	$https = 'https' === strtolower( (string) ( $parts['scheme'] ?? 'http' ) );
	$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( $https ? 443 : 80 );
	$host  = (string) $parts['host'];
	$addr  = false !== filter_var( (string) $addr, FILTER_VALIDATE_IP ) ? (string) $addr : '';
	$to    = '' !== $addr ? $addr : $host;
	if ( false !== strpos( $to, ':' ) && '[' !== $to[0] ) {
		$to = '[' . $to . ']'; // IPv6.
	}
	$path = ( isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/wp-cron.php' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	$head = $host . ( isset( $parts['port'] ) ? ':' . $port : '' );
	return array(
		'remote'  => ( $https ? 'ssl://' : 'tcp://' ) . $to . ':' . $port,
		'peer'    => trim( $host, '[]' ),
		'request' => 'GET ' . $path . " HTTP/1.1\r\nHost: " . $head . "\r\nUser-Agent: WPOptimizing cron trigger\r\nConnection: close\r\n\r\n",
	);
}

/**
 * May this hit fire wp-cron.php, and how? Pure.
 *
 * ONLY WHERE THE ANSWER CAN BE FINISHED FIRST. Under Apache with mod_php (no
 * fastcgi_finish_request(), no litespeed_finish_request()) flushing the buffers does not end the
 * response: the visitor's connection stays open until PHP exits, so connecting to wp-cron.php
 * there delayed the HIT itself, by up to two half-second attempts when the learnt address was
 * down (Codex, 08/11/2026). Such a hit fires nothing; WordPress still runs its cron on every miss
 * and admin visit, and the cache screen recommends a system cron when tasks run late.
 *
 * ONLY TO AN IP ADDRESS. The address this server answers on (learnt by loopback.php), or the URL's
 * host when it is an IP already. Resolving a host name has no timeout PHP can set, so it is never
 * done on a hit: without an address, the hit fires nothing either.
 *
 * @param array $sidecar   Sidecar config.
 * @param bool  $fastcgi   fastcgi_finish_request() exists.
 * @param bool  $litespeed litespeed_finish_request() exists.
 * @return array|null { finish: function that ends the response, request: wpopt_pc_cron_request() }
 */
function wpopt_pc_cron_plan( $sidecar, $fastcgi, $litespeed ) {
	$finish = $fastcgi ? 'fastcgi_finish_request' : ( $litespeed ? 'litespeed_finish_request' : '' );
	if ( '' === $finish || empty( $sidecar['cron_url'] ) ) {
		return null;
	}
	$addr = isset( $sidecar['loopback_addr'] ) ? (string) $sidecar['loopback_addr'] : '';
	if ( false === filter_var( $addr, FILTER_VALIDATE_IP ) ) {
		$host = (string) parse_url( (string) $sidecar['cron_url'], PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Runs before WordPress.
		$addr = false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ? trim( $host, '[]' ) : '';
	}
	// No public address learnt (a server behind a local proxy, a development site: its own
	// address is 127.0.0.1, which wpoptimizing_loopback_learn() rightly does not keep) and a host
	// name that would need DNS: the machine itself. The call goes out after the answer has, so a
	// refused connection costs the visitor nothing; the site never fired WP-Cron from a hit
	// otherwise (found on limpio.local, 09/11/2026).
	if ( '' === $addr ) {
		$addr = '127.0.0.1';
	}
	$req = '' !== $addr ? wpopt_pc_cron_request( (string) $sidecar['cron_url'], $addr ) : null;
	if ( null === $req ) {
		return null;
	}
	return array(
		'finish'  => $finish,
		'request' => $req,
	);
}

/**
 * Where a site keeps «.cron-next» and «.cron-spawn»: the cache root, or on a network the site's
 * own folder, since every site has its own WP-Cron list and its own wp-cron.php to call. Pure.
 *
 * @param array $sidecar Sidecar config.
 * @return string
 */
function wpopt_pc_cron_dir( $sidecar ) {
	$dir = ! empty( $sidecar['cron_dir'] ) ? (string) $sidecar['cron_dir'] : (string) ( $sidecar['base_dir'] ?? '' );
	return rtrim( $dir, '/' );
}

/**
 * On a hit about to be served by PHP: when a task is due and nothing fired wp-cron.php in the
 * last minute, call it once the answer has gone out. Two tiny reads per hit (the time of the
 * next event and the date of the last call); nothing else unless a call is due.
 *
 * @param array     $sidecar Sidecar config.
 * @param int       $now     Now.
 * @param bool|null $finish  Whether this PHP can end the response early (null = ask it).
 * @return bool Whether a call was scheduled for the end of this request.
 */
function wpopt_pc_cron_arm( $sidecar, $now, $finish = null ) {
	if ( ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) || empty( $sidecar['cron_url'] ) || empty( $sidecar['base_dir'] ) || ! empty( $GLOBALS['wpopt_pc_cron_armed'] ) ) {
		return false;
	}
	// Decided before any read: a hit that cannot fire must not take the minute either, so the
	// next request that can (another PHP pool, a LiteSpeed worker) still does.
	$fastcgi   = null === $finish ? function_exists( 'fastcgi_finish_request' ) : (bool) $finish;
	$litespeed = null === $finish && function_exists( 'litespeed_finish_request' );
	if ( null === wpopt_pc_cron_plan( $sidecar, $fastcgi, $litespeed ) ) {
		return false;
	}
	$base = wpopt_pc_cron_dir( $sidecar );
	$next = is_file( $base . '/.cron-next' ) ? (int) @file_get_contents( $base . '/.cron-next' ) : 0; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Runs before WordPress.
	if ( ! wpopt_pc_cron_due( $next, $now ) || (int) @filemtime( $base . '/.cron-spawn' ) > (int) $now - 60 ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing on the first call.
		return false;
	}
	$GLOBALS['wpopt_pc_cron_armed'] = true;
	register_shutdown_function( 'wpopt_pc_cron_spawn', $sidecar );
	return true;
}

/**
 * Fire wp-cron.php without waiting for it (shutdown function of a hit). Never fails the page and
 * never makes the visitor wait: the answer is finished first (wpopt_pc_cron_plan() refuses a PHP
 * that cannot), and the call gets one attempt under a total time limit.
 *
 * @param array $sidecar Sidecar config.
 * @return void
 */
function wpopt_pc_cron_spawn( $sidecar ) {
	// WordPress loaded after all (a copy that turned out not to match its .meta became a miss):
	// it runs its own cron check on that request.
	if ( function_exists( 'wp_cron' ) ) {
		return;
	}
	try {
		$plan = wpopt_pc_cron_plan( $sidecar, function_exists( 'fastcgi_finish_request' ), function_exists( 'litespeed_finish_request' ) );
		if ( null === $plan ) {
			return;
		}
		@call_user_func( $plan['finish'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$base = wpopt_pc_cron_dir( $sidecar );
		$now  = time();
		// One call a minute for the whole site: the lock picks one request among those that saw
		// the same old date, and the date is moved forward before connecting.
		$lock = wpopt_pc_try_lock( $base . '/.cron-spawn.lock' );
		if ( false === $lock ) {
			return;
		}
		clearstatcache( true, $base . '/.cron-spawn' );
		if ( (int) @filemtime( $base . '/.cron-spawn' ) > $now - 60 || ! @touch( $base . '/.cron-spawn', $now ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing on the first call.
			wpopt_pc_release_lock( $lock );
			return;
		}
		wpopt_pc_release_lock( $lock );
		// One attempt, no fallback to the host name: a second, chained attempt doubled the time a
		// dead address cost, and a name would need a DNS lookup. The next minute tries again.
		wpopt_pc_cron_send( $plan['request'] );
	} catch ( Throwable $e ) {
		unset( $e ); // A trigger must never surface on a visitor's page.
	}
}

/**
 * Send the wp-cron.php request within a total time limit, without blocking on anything.
 *
 * The old call gave each of its two attempts half a second for the connection, which did not
 * cover the DNS lookup of the second one nor the write (blocking, under default_socket_timeout).
 * Here there is one attempt, to an IP address, on a socket that connects asynchronously and
 * stays non-blocking: every step — connect, TLS handshake, write — waits in stream_select()
 * against one deadline. Nothing is read back, like spawn_cron(): wp-cron.php ignores the client
 * going away (ignore_user_abort).
 *
 * Once written, the socket stays open until the first byte of the answer or $linger seconds: a
 * proxy in front of PHP (Local's router, Plesk's nginx) drops a request whose client leaves
 * before it has passed it on, and closing right after the write lost every call — the log never
 * showed it (found on limpio.local, 09/11/2026). A fixed 10 ms, as spawn_cron() waits, was not
 * enough behind Plesk's nginx → Apache → PHP-FPM: nginx logged the call as 499 (client gone) and
 * WordPress never ran it (Mundotoro, 09/11/2026). So it waits for the first byte of the answer,
 * which wp-cron.php sends at once (it finishes its own request before running the tasks), with
 * $linger only as the limit when something is wrong. The visitor's answer has gone out already:
 * on PHP-FPM the triggering hit measured 8–40 ms, the same as any other.
 *
 * @param array $req    wpopt_pc_cron_request() (its 'remote' is an IP address).
 * @param float $budget Seconds to connect and write.
 * @param float $linger Seconds to wait afterwards for the proxy to pass it on.
 * @return bool Whether the request was handed to the network in full.
 */
function wpopt_pc_cron_send( array $req, $budget = 0.15, $linger = 0.5 ) {
	$deadline = microtime( true ) + (float) $budget;
	$tls      = 0 === strpos( (string) $req['remote'], 'ssl://' );
	// Plain TCP first, TLS turned on afterwards: an asynchronous connect cannot do the handshake
	// itself, and doing it here keeps it inside the deadline.
	$remote = 'tcp://' . substr( (string) $req['remote'], (int) strpos( (string) $req['remote'], '://' ) + 3 );
	$ctx    = stream_context_create(
		array(
			'ssl' => array(
				'peer_name'         => $req['peer'],
				'SNI_enabled'       => true,
				// Only a trigger to this same site, often over a local certificate; nothing is read back.
				'verify_peer'       => false,
				'verify_peer_name'  => false,
				'allow_self_signed' => true,
			),
		)
	);
	$fp     = @stream_socket_client( $remote, $errno, $errstr, (float) $budget, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreachable: nothing to do.
	if ( false === $fp ) {
		return false;
	}
	stream_set_blocking( $fp, false );
	$ok = wpopt_pc_cron_wait( $fp, $deadline, false );
	while ( $ok && $tls ) {
		$done = @stream_socket_enable_crypto( $fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A failed handshake: nothing to do.
		if ( true === $done ) {
			break;
		}
		// 0 = the handshake needs the server's answer: wait for it, within the deadline.
		$ok = 0 === $done && wpopt_pc_cron_wait( $fp, $deadline, true );
	}
	$data = (string) $req['request'];
	while ( $ok && '' !== $data ) {
		$sent = @fwrite( $fp, $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		if ( false === $sent ) {
			$ok = false;
			break;
		}
		$data = (string) substr( $data, $sent );
		$ok   = '' === $data || wpopt_pc_cron_wait( $fp, $deadline, false );
	}
	if ( $ok && '' === $data && (float) $linger > 0 ) {
		// Read until the answer's first bytes really arrive. Waiting only for the socket to be
		// readable was not enough over TLS 1.3: the server's session tickets, sent right after
		// the handshake, make it readable at once, the wait ended, the connection closed and
		// Plesk's nginx logged 499 again (Mundotoro, 09/11/2026). fread() returns only
		// application data, so an empty read means «not yet».
		$until = microtime( true ) + (float) $linger;
		while ( wpopt_pc_cron_wait( $fp, $until, true ) ) {
			$got = @fread( $fp, 64 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( ( is_string( $got ) && '' !== $got ) || feof( $fp ) ) {
				break;
			}
		}
	}
	@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	return $ok && '' === $data;
}

/**
 * Wait until a non-blocking socket can be read (or written) or the deadline passes.
 *
 * @param resource $fp       Socket.
 * @param float    $deadline microtime() limit.
 * @param bool     $read     Wait to read (TLS handshake) rather than to write (connect, send).
 * @return bool False when the deadline passed or the socket failed.
 */
function wpopt_pc_cron_wait( $fp, $deadline, $read ) {
	$left = (float) $deadline - microtime( true );
	if ( $left <= 0 ) {
		return false;
	}
	$r      = $read ? array( $fp ) : null;
	$w      = $read ? null : array( $fp );
	$e      = null;
	$secs   = (int) $left;
	$micros = (int) ( ( $left - $secs ) * 1000000 );
	return 0 < (int) @stream_select( $r, $w, $e, $secs, $micros ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Interrupted: treated as a timeout.
}

/**
 * May the page buffer be stored at this call of its output callback? Pure.
 *
 * The callback sees the whole page only when PHP closes the buffer in one go (FINAL, possibly
 * with START). A FLUSH (ob_flush()) has already sent part of the page, and a CLEAN (ob_clean(),
 * ob_end_clean()) has thrown part of it away: in both cases what the callback holds is not the
 * page the visitor got, and the drop-in stops caching that request.
 *
 * @param int $phase PHP_OUTPUT_HANDLER_* bitmask passed to the callback.
 * @return bool
 */
function wpopt_pc_buffer_phase_ok( $phase ) {
	return 0 === ( (int) $phase & ( PHP_OUTPUT_HANDLER_FLUSH | PHP_OUTPUT_HANDLER_CLEAN ) );
}

/**
 * Did this response emit a Set-Cookie header? Pure.
 *
 * @since 0.1.0
 * @param array $headers Output of headers_list().
 * @return bool
 */
function wpopt_pc_response_sets_cookie( $headers ) {
	foreach ( (array) $headers as $header ) {
		if ( 0 === stripos( (string) $header, 'set-cookie:' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Did this response say it must not be stored by a shared cache? Pure.
 *
 * `private` and `no-store` are the origin's own "this is not for everybody". A maintenance or
 * coming-soon plugin sends them to anonymous visitors, so does a plugin that personalises a page
 * without a cookie we know about; storing that page anyway kept it served for hours after the
 * site went live, and at Cloudflare's edge as well (wpoptimizing.com, 16/10/2026). `no-cache`
 * and `max-age=0` alone do not forbid storing, only reusing without asking, and WordPress hosts
 * add them to everything, so they are not read as a refusal.
 *
 * @since 0.1.0
 * @param array $headers Output of headers_list().
 * @return bool
 */
function wpopt_pc_response_forbids_store( $headers ) {
	foreach ( (array) $headers as $header ) {
		$header = (string) $header;
		if ( 0 === stripos( $header, 'cache-control:' ) && preg_match( '/(?:^|[\s,:])(private|no-store)\b/i', substr( $header, 14 ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Filesystem path of the cache file for a request. Layout mirrors WP Rocket:
 *   <base>/<host>/<uri>/index<-variant>.html
 * Each URI segment goes through wpopt_pc_path_segment(), which prevents traversal and keeps two
 * different URLs from sharing a file. The purge (wpoptimizing_page_cache_clear_url()) builds its
 * path with this same function, so what is stored and what is purged cannot drift apart.
 *
 * @param string $base_dir Cache base dir (e.g. WP_CONTENT_DIR/cache/wpopt-page).
 * @param string $host      Request host.
 * @param string $path      URI path (no query).
 * @param string $variant   Variant suffix (e.g. '', 'mobile', 'https'); joined with '-'.
 * @param bool|string $gzip Coding of the copy: true or 'gzip' (.gz), 'br' (.br), false or
 *                          'identity' (the plain page).
 * @return string
 */
function wpopt_pc_cache_file( $base_dir, $host, $path, $variant = '', $gzip = false ) {
	$host = strtolower( preg_replace( '/[^a-z0-9\.\-]/i', '', (string) $host ) );
	$path = (string) $path;
	// Strip query, decode once, then map every segment to a safe directory name.
	$path = rawurldecode( $path );
	$path = (string) preg_replace( '#\?.*$#', '', $path );
	$path = implode( '/', array_filter( array_map( 'wpopt_pc_path_segment', explode( '/', $path ) ), 'strlen' ) );

	$dir  = rtrim( (string) $base_dir, '/' ) . '/' . $host . ( '' !== $path ? '/' . $path : '' );
	$name = 'index' . ( '' !== $variant ? '-' . preg_replace( '/[^a-z0-9\-]/i', '', $variant ) : '' ) . '.html';
	return $dir . '/' . $name . wpopt_pc_encoding_ext( $gzip );
}

/**
 * File extension of a copy in this coding. Pure. Accepts the old boolean (true = gzip) so the
 * callers written before Brotli keep their meaning.
 *
 * @param bool|string $encoding 'identity', 'gzip', 'br', or a boolean (gzip or not).
 * @return string '', '.gz' or '.br'.
 */
function wpopt_pc_encoding_ext( $encoding ) {
	$encoding = wpopt_pc_encoding_name( $encoding );
	return 'identity' === $encoding ? '' : ( 'br' === $encoding ? '.br' : '.gz' );
}

/**
 * The coding a value names: 'identity', 'gzip' or 'br'. Pure. true is gzip (the boolean the
 * engine passed around before Brotli); anything unknown is the plain copy.
 *
 * @param bool|string $encoding Coding or boolean.
 * @return string
 */
function wpopt_pc_encoding_name( $encoding ) {
	if ( true === $encoding || 'gzip' === $encoding ) {
		return 'gzip';
	}
	return 'br' === $encoding ? 'br' : 'identity';
}

/**
 * The key of a coding in the fingerprints of the .meta: 'plain', 'gz' or 'br'. Pure.
 *
 * @param bool|string $encoding Coding or boolean.
 * @return string
 */
function wpopt_pc_encoding_key( $encoding ) {
	$encoding = wpopt_pc_encoding_name( $encoding );
	return 'identity' === $encoding ? 'plain' : ( 'br' === $encoding ? 'br' : 'gz' );
}

/**
 * One URL path segment as a directory name. Pure.
 *
 * A segment made only of safe characters is kept as is. Any other one is hashed whole: replacing
 * each unsafe byte with '-' gave every Cyrillic, Greek, Arabic or Chinese slug of the same byte
 * length the same name (/привет/ and /мирабв/ both became /------------/), so one page was served
 * for the other, and "/hello world/" shared the file of /hello-world/. The hash is taken over the
 * decoded bytes, so the percent-encoded permalink a purge starts from and the request a visitor
 * sends land on the same name whatever case their %XX escapes use. '.' and '..' are dropped.
 *
 * @param string $segment Decoded segment.
 * @return string Safe name, or '' to drop the segment.
 */
function wpopt_pc_path_segment( $segment ) {
	$segment = (string) $segment;
	if ( '' === trim( $segment, '.' ) ) {
		return '';
	}
	if ( 1 === preg_match( '/^[a-z0-9_.\-]+$/i', $segment ) ) {
		return $segment;
	}
	return 'h-' . md5( $segment );
}

/**
 * Is the request over HTTPS? Deliberately the same two signals core's is_ssl() uses, and
 * in the same order, because WordPress renders the page from is_ssl() while we file it
 * under this answer. Any signal we honour that core ignores splits the two apart: a
 * visitor who adds X-Forwarded-Proto: https to a plain HTTP request would make core
 * render http-flavoured HTML and make us store it under the https key, where the real
 * HTTPS visitors of a proxied site would then read it.
 *
 * That leaves nothing to configure. A site behind a terminating proxy sets
 * $_SERVER['HTTPS'] in wp-config.php, which is what WordPress itself documents and what
 * managed hosts ship; wp-config runs long before this drop-in, so the first branch below
 * already sees it and the cache follows core for free.
 *
 * @param array $server $_SERVER.
 * @return bool
 */
function wpopt_pc_request_is_https( $server ) {
	if ( isset( $server['HTTPS'] ) ) {
		$https = strtolower( (string) $server['HTTPS'] );
		return ( 'on' === $https || '1' === $https );
	}
	// Only when HTTPS is absent, exactly like core: a server that sets HTTPS has spoken.
	return ( isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'] );
}

/**
 * Is the request from a mobile device? A copy of core's wp_is_mobile(), signal for signal:
 * the theme decides what to render with that function, so the cache has to split visitors
 * exactly where the theme does, or a phone would be handed the desktop page it never asked
 * for. It runs before WordPress loads, which is why it cannot just call wp_is_mobile().
 *
 * @param array $server $_SERVER.
 * @return bool
 */
function wpopt_pc_is_mobile( $server ) {
	if ( isset( $server['HTTP_SEC_CH_UA_MOBILE'] ) ) {
		// Client Hints (Chromium): the browser says it outright.
		return '?1' === (string) $server['HTTP_SEC_CH_UA_MOBILE'];
	}
	$ua = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';
	if ( '' === $ua ) {
		return false;
	}
	foreach ( array( 'Mobile', 'Android', 'Silk/', 'Kindle', 'BlackBerry', 'Opera Mini', 'Opera Mobi' ) as $needle ) {
		if ( false !== strpos( $ua, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Digest of the price cookies a request carries, for the cache key. Pure.
 *
 * '' when it carries none: the shared copy. FALSE when one of them holds anything but a currency
 * or country code (2–3 letters): its value is the visitor's to choose, and caching every value
 * would let anyone fill the disk one request at a time — the same reason `s` is not cacheable.
 * Such a request renders uncached.
 *
 * @param array $cookies $_COOKIE.
 * @param array $config  Merged config.
 * @return string|false 12 hex characters, '' or false.
 */
function wpopt_pc_cookie_key( $cookies, $config ) {
	$picked = array();
	foreach ( (array) ( $config['vary_cookies'] ?? array() ) as $name ) {
		if ( ! isset( $cookies[ $name ] ) || '' === (string) $cookies[ $name ] ) {
			continue;
		}
		$value = (string) $cookies[ $name ];
		if ( 1 !== preg_match( '/^[A-Za-z]{2,3}$/', $value ) ) {
			return false;
		}
		$picked[ $name ] = strtoupper( $value );
	}
	if ( ! $picked ) {
		return '';
	}
	ksort( $picked );
	return substr( md5( http_build_query( $picked ) ), 0, 12 );
}

/**
 * Cache variant suffix for a request: mobile (premium, off by default), then https, then
 * the whitelisted query string, then the price cookies. Every dimension that can change the
 * rendered HTML has to appear here, or two different responses would share one file.
 *
 * @param bool   $is_https   Request is HTTPS.
 * @param bool   $is_mobile  Serve a separate mobile cache (premium; default false).
 * @param string $query_key  Digest of the cacheable query string (see wpopt_pc_query_key()).
 * @param string $cookie_key Digest of the price cookies (see wpopt_pc_cookie_key()).
 * @return string
 */
function wpopt_pc_variant( $is_https, $is_mobile = false, $query_key = '', $cookie_key = '' ) {
	$parts = array();
	if ( $is_mobile ) {
		$parts[] = 'mobile';
	}
	if ( $is_https ) {
		$parts[] = 'https';
	}
	// Only ever the digest wpopt_pc_query_key() produces; anything else is discarded whole
	// rather than "cleaned", so a crafted value can never shape a path segment.
	$query_key = strtolower( (string) $query_key );
	if ( 1 === preg_match( '/^[a-f0-9]{12}$/', $query_key ) ) {
		$parts[] = 'q' . $query_key;
	}
	$cookie_key = strtolower( (string) $cookie_key );
	if ( 1 === preg_match( '/^[a-f0-9]{12}$/', $cookie_key ) ) {
		$parts[] = 'c' . $cookie_key;
	}
	return implode( '-', $parts );
}

/**
 * The response headers a cached copy must repeat. Pure.
 *
 * A HIT is answered before WordPress runs, so whatever the site sent from PHP on the render —
 * HSTS, a Content-Security-Policy, Link preloads, frame or referrer policies — was simply
 * missing from every later visit. These are kept beside the copy and sent again. Only these:
 * anything per-visitor (Set-Cookie, which is never stored anyway) or about the transfer stays
 * out.
 *
 * @param array $headers headers_list() of the render.
 * @return string[] Header lines.
 */
function wpopt_pc_kept_headers( $headers ) {
	$keep = array( 'strict-transport-security', 'content-security-policy', 'content-security-policy-report-only', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'permissions-policy', 'cross-origin-opener-policy', 'cross-origin-embedder-policy', 'cross-origin-resource-policy', 'link', 'x-robots-tag' );
	$out  = array();
	foreach ( (array) $headers as $line ) {
		$line = (string) $line;
		$pos  = strpos( $line, ':' );
		if ( false === $pos || false !== strpbrk( $line, "\r\n" ) ) {
			continue;
		}
		$name = strtolower( trim( substr( $line, 0, $pos ) ) );
		// A JSON answer (a REST route the site caches, wpopt_pc_rest_allowed()) or a text file
		// (wpopt_pc_text_file_allowed()) keeps its type, charset included: a hit is sent as
		// text/html unless the copy says otherwise. Pages keep the default.
		$typed = false;
		if ( in_array( $name, $keep, true ) || $typed ) {
			$out[] = $line;
		}
	}
	return $out;
}


/**
 * The content codings a client accepts, best first. Pure.
 *
 * Accept-Encoding is a list of codings with optional weights («br;q=1, gzip;q=0.8, *;q=0»). A
 * weight of 0 is a client saying no, and «*» stands for every coding the client did not name,
 * with its own weight. Matching the word alone sent «gzip;q=0» clients a compressed copy (Codex,
 * 06/11/2026); doing it with «br» would send Brotli to a client that just said it cannot decode it.
 *
 * Order: higher weight first; on a tie the server's preference, br then gzip, because Brotli is
 * smaller and a client that names it decodes it. Only the codings in $available take part: a
 * site that stores no Brotli copies never looks for one on disk.
 *
 * «identity» (the plain copy) goes where its explicit weight puts it, and otherwise last. It is
 * left out only when the client refuses it, as HTTP says: «identity;q=0», or «*;q=0» without an
 * «identity» of its own. It used to be added back even then (Codex, 08/11/2026). When the client
 * refuses everything this site has, the list is empty: the drop-in finds no copy to send and
 * WordPress answers, as it would without this cache — never a 406, which no page cache sends and
 * which would leave that visitor with no page at all. A request with no header gets only the
 * plain copy, as before: a client that names nothing may decode nothing.
 *
 * @param string   $header    Raw Accept-Encoding ('' when the client sent none).
 * @param string[] $available Compressed codings the site stores: 'br', 'gzip'.
 * @return string[] Codings to try in this order; empty when the client accepts none of them.
 */
function wpopt_pc_negotiate_encoding( $header, array $available = array( 'gzip' ) ) {
	$named = array();
	$star  = null;
	foreach ( explode( ',', strtolower( (string) $header ) ) as $part ) {
		$bits   = array_map( 'trim', explode( ';', $part ) );
		$coding = 'x-gzip' === $bits[0] ? 'gzip' : $bits[0];
		if ( '' === $coding ) {
			continue;
		}
		$q = 1.0;
		foreach ( array_slice( $bits, 1 ) as $param ) {
			if ( 1 === preg_match( '/^q\s*=\s*([0-9]+\.?[0-9]*|\.[0-9]+)$/', $param, $m ) ) {
				// Clamped: «q=2» still means «yes», «q=-1» never parses as a number here.
				$q = max( 0.0, min( 1.0, (float) $m[1] ) );
			}
		}
		if ( '*' === $coding ) {
			$star = null === $star ? $q : $star;
		} elseif ( ! isset( $named[ $coding ] ) ) {
			// Named twice: the first one counts.
			$named[ $coding ] = $q;
		}
	}
	$rank  = array(
		'br'       => 3,
		'gzip'     => 2,
		'identity' => 1,
	);
	$found = array();
	foreach ( array( 'br', 'gzip' ) as $coding ) {
		if ( in_array( $coding, $available, true ) ) {
			$found[ $coding ] = isset( $named[ $coding ] ) ? $named[ $coding ] : ( null !== $star ? $star : 0.0 );
		}
	}
	// Only an explicit weight moves the plain copy up; unnamed, it is the fallback.
	$plain = true;
	if ( isset( $named['identity'] ) ) {
		$found['identity'] = $named['identity'];
		$plain             = $named['identity'] > 0;
	} elseif ( null !== $star && $star <= 0 ) {
		$plain = false;
	}
	$found = array_filter(
		$found,
		static function ( $q ) {
			return $q > 0;
		}
	);
	uksort(
		$found,
		static function ( $a, $b ) use ( $found, $rank ) {
			$by_q = $found[ $b ] <=> $found[ $a ];
			return 0 !== $by_q ? $by_q : $rank[ $b ] <=> $rank[ $a ];
		}
	);
	$out = array_keys( $found );
	if ( $plain && ! in_array( 'identity', $out, true ) ) {
		$out[] = 'identity';
	}
	return $out;
}

/**
 * Does the client accept gzip? Pure. Kept for drop-ins written before Brotli (an old drop-in
 * runs with the new engine until the plugin rewrites it).
 *
 * @param string $header Raw Accept-Encoding.
 * @return bool
 */
function wpopt_pc_accepts_gzip( $header ) {
	return in_array( 'gzip', wpopt_pc_negotiate_encoding( (string) $header, array( 'gzip' ) ), true );
}

/**
 * The validator of a cached copy: weak, from the file actually sent — its inode, time and size.
 * Pure.
 *
 * The inode is what makes it safe: every write is an atomic rename (a new inode) and every
 * variant is its own file, so two copies of the same size written in the same second (EUR and
 * USD) never share a tag, and the tag always belongs to the body it is sent with. A tag kept in
 * a sidecar could belong to a newer write than the body (Codex, 06/11/2026).
 *
 * Each coding carries its own suffix: the gzip and Brotli copies are different bodies of the
 * same page, and a cache that keeps one must never answer «not modified» for the other on the
 * strength of an inode it cannot see. The plain copy keeps the tag it always had.
 *
 * @param int    $mtime    Modification time.
 * @param int    $size     Size in bytes.
 * @param int    $inode    Inode number (0 when unknown).
 * @param string $encoding Coding of the copy ('identity', 'gzip', 'br').
 * @return string
 */
function wpopt_pc_etag( $mtime, $size, $inode = 0, $encoding = 'identity' ) {
	$encoding = wpopt_pc_encoding_name( $encoding );
	$suffix   = 'identity' === $encoding ? '' : '-' . ( 'br' === $encoding ? 'br' : 'gz' );
	return 'W/"' . ( $inode > 0 ? dechex( (int) $inode ) . '-' : '' ) . dechex( (int) $mtime ) . '-' . dechex( (int) $size ) . $suffix . '"';
}

/**
 * Does the browser already hold this copy? If-None-Match first, as HTTP says; the date only
 * when there is no ETag to compare. Pure.
 *
 * @param string $etag          Our validator.
 * @param int    $mtime         Modification time.
 * @param string $if_none_match Raw If-None-Match.
 * @param string $if_mod_since  Raw If-Modified-Since.
 * @return bool
 */
function wpopt_pc_not_modified( $etag, $mtime, $if_none_match, $if_mod_since ) {
	$if_none_match = (string) $if_none_match;
	if ( '' !== $if_none_match ) {
		foreach ( explode( ',', $if_none_match ) as $candidate ) {
			$candidate = trim( $candidate );
			if ( '*' === $candidate || $candidate === $etag || 'W/' . $candidate === $etag ) {
				return true;
			}
		}
		return false;
	}
	$since = '' !== (string) $if_mod_since ? strtotime( (string) $if_mod_since ) : 0;
	return $since > 0 && $since >= (int) $mtime;
}

/**
 * How long Cloudflare's edge may still keep a copy: what is left of its life, not the whole
 * lifetime again. A copy served at 9 h of a 10 h life used to tell the edge «10 h», so the edge
 * served it until 19 h — past the nonces in it. Pure.
 *
 * @param int $edge_ttl Edge lifetime the site set (0 = edge off).
 * @param int $ttl      Page lifetime (0 = never expires).
 * @param int $age      Age of the copy in seconds.
 * @return int Seconds, 0 when the edge is off.
 */
function wpopt_pc_edge_max_age( $edge_ttl, $ttl, $age ) {
	$edge_ttl = (int) $edge_ttl;
	if ( $edge_ttl <= 0 ) {
		return 0;
	}
	$left = (int) $ttl > 0 ? (int) $ttl - max( 0, (int) $age ) : $edge_ttl;
	return max( 60, min( $edge_ttl, $left ) );
}

/**
 * Does the copy belong to the same version as its .meta? The .meta carries the fingerprint of the
 * body, the gzip and the Brotli copy, and only a body whose fingerprint it holds is served.
 *
 * NO FINGERPRINT, NO HIT. A copy without a .meta (or with one from before fingerprints) used to be
 * trusted for the plain and gzip codings. But the first publication after a purge renamed the body
 * before its .meta, and a request in between found the body alone: it was served without its CSP,
 * HSTS or Link headers — for good, if the writer died before the .meta (Codex, 09/11/2026). The
 * .meta is now published first (wpopt_pc_publish_copy()), and a body with no fingerprint vouching
 * for it is a miss, whatever the reason: a missing, unreadable or pre-fingerprint .meta. Copies
 * from before fingerprints are therefore rendered once more; a miss costs one render, a page sent
 * without its security headers can break or expose the site for hours.
 *
 * Given an open handle, the fingerprint is taken from that handle (wpopt_pc_send_hit() then sends
 * the same bytes); given a path, from whatever file the name points to now.
 *
 * @param array           $info     Decoded .meta.
 * @param string|resource $file     Copy about to be sent: its path, or a handle open on it.
 * @param bool|string     $encoding Its coding ('identity', 'gzip', 'br'; true = gzip, as before).
 * @return bool
 */
function wpopt_pc_meta_matches( array $info, $file, $encoding ) {
	if ( ! isset( $info['md5'] ) || ! is_array( $info['md5'] ) ) {
		return false;
	}
	$want = (string) ( $info['md5'][ wpopt_pc_encoding_key( $encoding ) ] ?? '' );
	if ( '' === $want ) {
		return false;
	}
	if ( is_resource( $file ) ) {
		return wpopt_pc_stream_md5( $file ) === $want;
	}
	return @md5_file( (string) $file ) === $want; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- A copy deleted meanwhile: a miss.
}

/**
 * The md5 of everything behind an open handle, read in chunks (a page is never held whole in
 * memory), with the handle left at the start for the caller to send the same bytes.
 *
 * @param resource $fh Handle open for reading.
 * @return string Hex digest, '' when the handle cannot be read.
 */
function wpopt_pc_stream_md5( $fh ) {
	if ( ! @rewind( $fh ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Runs before WordPress.
		return '';
	}
	$ctx = hash_init( 'md5' );
	hash_update_stream( $ctx, $fh );
	return rewind( $fh ) ? hash_final( $ctx ) : '';
}

/**
 * Answer a request from a cached copy, headers and body, and stop (or return, without sending
 * anything, when the copy and its .meta are from different versions).
 *
 * @param string      $file     Copy to send.
 * @param bool|string $encoding Its coding: 'identity', 'gzip' or 'br' (true = gzip, the boolean
 *                              drop-ins before v15 pass).
 * @param int    $mtime  Its modification time (the open file's own is used when it can be read).
 * @param int    $now    Current time.
 * @param int    $ttl    Page lifetime.
 * @param int    $edge   Cloudflare edge lifetime (0 = off).
 * @param array  $server $_SERVER.
 * @return void
 */
function wpopt_pc_send_hit( $file, $encoding, $mtime, $now, $ttl, $edge, $server, $label = 'HIT' ) {
	$encoding = wpopt_pc_encoding_name( $encoding );
	$ext      = wpopt_pc_encoding_ext( $encoding );
	$meta     = $file . '.meta';
	if ( '' !== $ext && substr( $file, -strlen( $ext ) ) === $ext ) {
		$meta = substr( $file, 0, -strlen( $ext ) ) . '.meta';
	}
	$info = is_readable( $meta ) ? json_decode( (string) file_get_contents( $meta ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file.
	$info = is_array( $info ) ? $info : array();
	// ONE OPEN FILE FOR THE FINGERPRINT, THE TAG AND THE BODY. The fingerprint used to be taken by
	// name (md5_file()) and the body sent by name again (readfile()): a write renamed in between
	// sent its body with the headers of the .meta checked against the other one — a CSP nonce
	// that blocks every script — and a tag of neither (Codex, 08/11/2026). A copy is never
	// changed in place, only replaced by rename, so the file this handle holds stays the same
	// bytes until it is closed. Streamed twice through the handle rather than read into memory:
	// the second pass comes from the system's file cache, and it costs what md5_file() +
	// readfile() did (0.13 / 0.97 / 2.40 ms for 100 KB / 830 KB / 2 MB, against 0.15 / 1.00 /
	// 2.39, 08/11/2026; reading into memory was no faster), with no page held whole in memory.
	$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Runs before WordPress; a copy deleted meanwhile is a miss.
	if ( false === $fh ) {
		return;
	}
	if ( ! wpopt_pc_meta_matches( $info, $fh, $encoding ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return; // Mid-rewrite or mixed versions: the caller treats it as a miss.
	}
	$st    = fstat( $fh );
	$mtime = false !== $st ? (int) $st['mtime'] : (int) $mtime;
	$etag  = wpopt_pc_etag( $mtime, false !== $st ? (int) $st['size'] : 0, false !== $st ? (int) $st['ino'] : 0, $encoding );
	// Sent on the 304 too: it tells a cache which of its stored bodies the answer refreshes.
	if ( 'identity' !== $encoding ) {
		header( 'Content-Encoding: ' . $encoding );
	}
	header( 'Content-Type: text/html; charset=UTF-8' );
	// The body depends on Accept-Encoding, so an intermediary must not reuse one for the other.
	header( 'Vary: Accept-Encoding' );
	header( 'X-WPOpt-Cache: ' . ( 'STALE' === $label ? 'STALE' : 'HIT' ) );
	header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', (int) $mtime ) . ' GMT' );
	header( 'ETag: ' . $etag );
	// The browser asks again every time, and gets a 304 while the copy is the same: without a
	// Cache-Control it guessed a lifetime from Last-Modified and showed an edited page stale.
	header( 'Cache-Control: no-cache' );
	// A stale copy is never offered to the edge: it is only bridging the seconds a rebuild takes.
	$edge_age = 'STALE' === $label ? 0 : wpopt_pc_edge_max_age( $edge, $ttl, (int) $now - (int) $mtime );
	if ( $edge_age > 0 ) {
		header( 'Cloudflare-CDN-Cache-Control: max-age=' . $edge_age );
	}
	foreach ( wpopt_pc_kept_headers( isset( $info['headers'] ) && is_array( $info['headers'] ) ? $info['headers'] : array() ) as $line ) {
		header( $line, false );
	}
	$inm = isset( $server['HTTP_IF_NONE_MATCH'] ) ? (string) $server['HTTP_IF_NONE_MATCH'] : '';
	$ims = isset( $server['HTTP_IF_MODIFIED_SINCE'] ) ? (string) $server['HTTP_IF_MODIFIED_SINCE'] : '';
	// Counted here, before each exit: past this line nothing else of this request runs.
	if ( wpopt_pc_not_modified( $etag, $mtime, $inm, $ims ) ) {
		http_response_code( 304 );
		exit;
	}
	fpassthru( $fh );
	fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	exit;
}

/**
 * Room for one more price variant of a URL? A cap on top of the active-plugin list: even a
 * real currency cookie only has a handful of real values, and anything past that is someone
 * inventing them. Pure apart from the directory listing.
 *
 * @param string $plain Path of the copy about to be written.
 * @param int    $cap   Variants allowed per URL.
 * @return bool
 */
function wpopt_pc_variant_room( $plain, $cap = 24 ) {
	$found = glob( dirname( (string) $plain ) . '/index*-c*.html' );
	return ! is_array( $found ) || count( $found ) < (int) $cap || in_array( (string) $plain, $found, true );
}


/**
 * The page compressed in one coding, or false when this PHP cannot (or the compressor failed).
 *
 * Brotli at quality 5, text mode, measured with libbrotli on a real 830 KB home page and its
 * first 500 KB (08/11/2026): 5.9 ms and 3.1 ms, against 10.0 ms and 4.9 ms for gzencode() at 6,
 * for a body 13 % and 10 % smaller than the gzip one. Quality 6 gains under 2 % for 10 % more
 * time; 11 (the extension's default) took 590 ms and 360 ms, which no request that renders a
 * page can pay. This runs once per miss, in that request, so the cheaper quality wins.
 *
 * @param string $buffer   The page.
 * @param string $encoding 'gzip' or 'br'.
 * @return string|false
 */
function wpopt_pc_compress( $buffer, $encoding ) {
	if ( 'br' === $encoding ) {
		if ( ! function_exists( 'brotli_compress' ) ) {
			return false;
		}
		$out = defined( 'BROTLI_TEXT' ) ? brotli_compress( (string) $buffer, 5, BROTLI_TEXT ) : brotli_compress( (string) $buffer, 5 );
	} elseif ( 'gzip' === $encoding && function_exists( 'gzencode' ) ) {
		$out = gzencode( (string) $buffer, 6 );
	} else {
		return false;
	}
	return is_string( $out ) && '' !== $out ? $out : false;
}

/**
 * Write a copy and its compressed versions, then its .meta, in the one safe order: bodies first,
 * the .meta with their fingerprints last. A HIT trusts a body only when the .meta it reads holds
 * that body's fingerprint (wpopt_pc_meta_matches()), so a reader between two of these writes
 * sees a miss, never a page with another version's headers.
 *
 * A coding that is off or failed this time loses its old file, before the .meta is written: a
 * .br from an earlier write must never stay beside a newer page. Its fingerprint would not match
 * anyway, but a stale compressed copy on disk is one bug away from being served, and the web
 * server's twins are linked from these files.
 *
 * @param string               $plain   Path of the plain copy (the others are it plus .gz / .br).
 * @param string               $buffer  The page.
 * @param array<string,string|false> $bodies  Compressed bodies by coding ('gzip', 'br'); false or
 *                                            missing = none this time.
 * @param string[]             $headers Headers a HIT must send again (wpopt_pc_kept_headers()).
 * @return array{gz:string,br:string} Paths of the compressed copies written ('' when none).
 */
function wpopt_pc_store_copy( $plain, $buffer, array $bodies, array $headers ) {
	$plain   = (string) $plain;
	$written = array(
		'gz' => '',
		'br' => '',
	);
	$md5     = array(
		'plain' => '',
		'gz'    => '',
		'br'    => '',
	);
	if ( wpopt_pc_write_atomic( $plain, (string) $buffer ) ) {
		$md5['plain'] = md5( (string) $buffer );
	}
	foreach ( array(
		'gzip' => 'gz',
		'br'   => 'br',
	) as $encoding => $key ) {
		$file = $plain . '.' . $key;
		$body = isset( $bodies[ $encoding ] ) ? $bodies[ $encoding ] : false;
		if ( is_string( $body ) && '' !== $body && wpopt_pc_write_atomic( $file, $body ) ) {
			$md5[ $key ]     = md5( $body );
			$written[ $key ] = $file;
		} elseif ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Before WordPress.
		}
	}
	// Last, beside the copy: the headers a HIT must send again (HSTS, CSP, Link…) and the
	// fingerprints they go with. Several files cannot be replaced at once; a HIT whose file does
	// not match its fingerprint is a miss, so a page is never sent with another version's
	// headers — a CSP nonce from the old one blocks every script of the new (Codex, 06/11/2026).
	wpopt_pc_write_atomic(
		$plain . '.meta',
		(string) json_encode(
			array(
				'headers' => array_values( $headers ),
				'md5'     => $md5,
			)
		)
	);
	return $written;
}

/**
 * Store a copy only if no purge or settings change happened since the render started, without
 * ever deleting a file another request wrote.
 *
 * WHY NOT «WRITE, CHECK, DELETE». Drop-ins up to v15 published the copy, checked the purge marks
 * and, when a purge had come in between, deleted the copy by name. A request B that started after
 * the purge could have written its fresh copy under those same names meanwhile, and the old
 * request deleted it (Codex, 08/11/2026). Comparing fingerprints before deleting only narrows
 * that window: B can still rename over the file between the comparison and the unlink. Here the
 * files are written under private temporary names, and the last check and the renames run under
 * the shared marks lock (wpopt_pc_marks_lock()), which a purge needs exclusively to leave its
 * mark. So either the check sees the mark and this request removes only its own temporary files,
 * or the publication completes before the mark exists and the purge's own deletion, which comes
 * after its mark, removes it. Nothing is deleted after publishing, and the twins the web server
 * serves without PHP are linked inside the same lock, so no purge can slip between them either.
 *
 * ORDER OF THE RENAMES: THE .META FIRST. Its bodies used to go first and the .meta last, as in
 * wpopt_pc_store_copy(); on the first publication after a purge a request in between found a body
 * and no .meta, and served it without its security headers (Codex, 09/11/2026). Now the .meta
 * goes first, then the twins, then the bodies: every body that becomes visible already has its
 * .meta, and a request that comes in between sees the new fingerprints beside an old body (or no
 * body), which is a miss (wpopt_pc_meta_matches()). A .meta that cannot be renamed publishes
 * nothing at all. The old file of a coding that is off or failed this time goes before the .meta,
 * so no old .br is ever left beside fingerprints that do not name it.
 *
 * The twins are served without PHP, and therefore without the .meta; they are linked from this
 * request's own temporary files only after its .meta is in place, so a twin never exists before
 * the .meta of the page it is a copy of.
 *
 * ONE WRITER PER VARIANT. The marks lock is shared, so two requests storing the same URL used to
 * publish at the same time: A renamed its files, B renamed its own (with a CSP) and removed the
 * twins, and A then linked B's files as twins with its own «may be served without PHP» — the web
 * server sent B's page without its CSP (Codex, 08/11/2026). Now each writer also holds its
 * variant's own lock («index….html.publish.lock», exclusive, taken inside the shared one) from the
 * last check to the last link, and links the twins from its own temporary files before renaming
 * them: a twin is always the body whose headers decided that it may exist.
 *
 * NO LOCK, NO COPY. A filesystem that refuses flock (rare: an NFS mount without lockd) used to
 * publish, check again and delete the files whose inode was still its own; but a check by inode
 * followed by an unlink by name is not atomic, and a newer copy renamed in between was deleted
 * (Codex, 08/11/2026). Without the lock nothing orders a publication against a purge, so nothing
 * is stored: the page is served uncached, which is always correct, rather than cached, which
 * there could be wrong for hours.
 *
 * @param string                     $plain   Path of the plain copy.
 * @param string                     $buffer  The page.
 * @param array<string,string|false> $bodies  Compressed bodies by coding ('gzip', 'br').
 * @param string[]                   $headers Headers a HIT must send again.
 * @param string                     $base    Cache root (where the marks lock lives).
 * @param callable                   $stale   Returns true when the copy must not be kept.
 * @param array                      $twin    { wanted: bool, hours: string[] } for
 *                                            wpopt_pc_static_twin(); empty = no twins.
 * @return array{stored:bool,gz:string,br:string}
 */
function wpopt_pc_publish_copy( $plain, $buffer, array $bodies, array $headers, $base, $stale, array $twin = array() ) {
	$plain = (string) $plain;
	$none  = array(
		'stored' => false,
		'gz'     => '',
		'br'     => '',
	);
	$out   = $none;
	$tmp   = array();
	$drop  = array();
	$md5   = array(
		'plain' => '',
		'gz'    => '',
		'br'    => '',
	);
	$first = wpopt_pc_write_temp( $plain, (string) $buffer );
	if ( false === $first ) {
		return $none;
	}
	$tmp[ $plain ] = $first;
	$md5['plain']  = md5( (string) $buffer );
	foreach ( array(
		'gzip' => 'gz',
		'br'   => 'br',
	) as $encoding => $key ) {
		$file = $plain . '.' . $key;
		$body = isset( $bodies[ $encoding ] ) ? $bodies[ $encoding ] : false;
		$made = is_string( $body ) && '' !== $body ? wpopt_pc_write_temp( $file, $body ) : false;
		if ( false !== $made ) {
			$tmp[ $file ] = $made;
			$md5[ $key ]  = md5( $body );
			$out[ $key ]  = $file;
		} else {
			$drop[] = $file;
		}
	}
	$meta = wpopt_pc_write_temp(
		$plain . '.meta',
		(string) json_encode(
			array(
				'headers' => array_values( $headers ),
				'md5'     => $md5,
			)
		)
	);
	if ( false === $meta ) {
		wpopt_pc_unlink_all( $tmp );
		return $none;
	}

	$lock = wpopt_pc_marks_lock( $base, LOCK_SH );
	$mine = false !== $lock ? wpopt_pc_variant_lock( $plain ) : false;
	if ( false === $mine || call_user_func( $stale ) ) {
		wpopt_pc_variant_unlock( $mine, $plain );
		wpopt_pc_release_lock( $lock );
		wpopt_pc_unlink_all( $tmp + array( 'meta' => $meta ) );
		return $none;
	}
	foreach ( $drop as $file ) {
		if ( file_exists( $file ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Before WordPress.
		}
	}
	// The .meta before any body (see above): without it in place, no body may become visible.
	if ( ! @rename( $meta, $plain . '.meta' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		wpopt_pc_variant_unlock( $mine, $plain );
		wpopt_pc_release_lock( $lock );
		wpopt_pc_unlink_all( $tmp + array( 'meta' => $meta ) );
		return $none;
	}
	$all = true;
	foreach ( $tmp as $file => $from ) {
		if ( ! @rename( $from, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			@unlink( $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Our own temporary file.
			$all = false;
			foreach ( array( 'gz', 'br' ) as $key ) {
				$out[ $key ] = $out[ $key ] === $file ? '' : $out[ $key ];
			}
		}
	}
	wpopt_pc_variant_unlock( $mine, $plain );
	wpopt_pc_release_lock( $lock );
	$out['stored'] = true;
	return $out;
}

/**
 * Take the publication lock of one variant, WITHOUT WAITING, on «<copy>.publish.lock». Called
 * inside the shared marks lock.
 *
 * NEVER IN A QUEUE. It used to block: after a purge, sixty requests for the same URL published
 * one after another, each adding its wait to its response (up to ~145 ms measured), a purge
 * asking for the marks lock waited behind all of them, and 33 of the 60 gave up after twenty
 * retries anyway (audit, 08/11/2026). Now a request that finds another one publishing this same
 * variant publishes nothing and moves on: the other one is storing the same page, rendered at
 * the same moment, so the copy will be there either way.
 *
 * The holder deletes the file before it lets go (wpopt_pc_variant_unlock()), so no lock file is
 * left beside every copy; the sweep also deletes old ones nobody holds. A writer that opened the
 * file before it was deleted and got the lock after holds a lock on a name that no longer exists
 * while another writer may lock a new file. So the lock only counts when the name still points to
 * the file this handle holds; otherwise it is tried again, a few times and at once — the previous
 * holder is gone, so this is not waiting for anyone.
 *
 * @param string $plain Path of the plain copy.
 * @return resource|false False when the file cannot be opened, another request holds it, or its
 *                        name kept changing (nothing is stored).
 */
function wpopt_pc_variant_lock( $plain ) {
	$path = (string) $plain . '.publish.lock';
	for ( $try = 0; $try < 3; $try++ ) {
		$fh = wpopt_pc_open_lock( $path );
		if ( false === $fh ) {
			return false;
		}
		if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false; // Another request is publishing this variant right now.
		}
		if ( wpopt_pc_holds_name( $fh, $path ) ) {
			return $fh;
		}
		wpopt_pc_release_lock( $fh );
	}
	return false;
}

/**
 * Is the file this locked handle holds still the one its name points to?
 *
 * A lock file that is deleted while held (wpopt_pc_variant_unlock(), the sweep) can be opened by
 * one process just before the deletion and locked by it just after: it then holds a lock on a
 * name that no longer exists, or that already points to a newer file another process holds. Its
 * lock protects nothing, and deleting «the lock file» by name would delete the other process's
 * (Codex, 09/11/2026). Asked while the lock is held: only a holder deletes the name, so the
 * answer cannot change until this process lets go.
 *
 * @param resource $fh   Locked handle.
 * @param string   $path The lock file's name.
 * @return bool
 */
function wpopt_pc_holds_name( $fh, $path ) {
	clearstatcache( true, (string) $path );
	$held = is_resource( $fh ) ? fstat( $fh ) : false;
	$now  = @stat( (string) $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Deleted meanwhile: false.
	return false !== $held && false !== $now && $held['ino'] === $now['ino'] && $held['dev'] === $now['dev'];
}

/**
 * Give a variant's publication lock back, deleting its file first, while it is still held: a
 * writer waiting on it then finds the name gone and takes a new one (wpopt_pc_variant_lock()).
 *
 * @param resource|false $fh    Handle from wpopt_pc_variant_lock().
 * @param string         $plain Path of the plain copy.
 * @return void
 */
function wpopt_pc_variant_unlock( $fh, $plain ) {
	if ( is_resource( $fh ) ) {
		@unlink( (string) $plain . '.publish.lock' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Before WordPress; our own lock file, held.
	}
	wpopt_pc_release_lock( $fh );
}

/**
 * Delete files this request created under temporary names.
 *
 * @param string[] $files Paths.
 * @return void
 */
function wpopt_pc_unlink_all( array $files ) {
	foreach ( $files as $file ) {
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- Before WordPress; our own temporary files.
	}
}

/**
 * May an expired copy still bridge a rebuild? Only shortly past its lifetime. The plugin works
 * out the grace so the oldest page served stays inside the time a nonce is guaranteed
 * (wpoptimizing_page_cache_stale_grace(), in the sidecar); 7200 is for a sidecar from before. Pure.
 *
 * @param int $mtime Copy time.
 * @param int $now   Now.
 * @param int $ttl   Lifetime (0 = never expires: nothing is ever stale).
 * @param int $grace Seconds past the lifetime.
 * @return bool
 */
function wpopt_pc_is_stale_ok( $mtime, $now, $ttl, $grace = 7200 ) {
	return (int) $ttl > 0 && ( (int) $now - (int) $mtime ) <= (int) $ttl + (int) $grace;
}

/**
 * Take the right to rebuild a page. Exactly one request gets it; the rest are told no and
 * serve the expired copy meanwhile, instead of all rendering the same page at once (a
 * stampede: after a purge, every concurrent visitor of a popular page ran WordPress).
 *
 * AN OPERATING-SYSTEM LOCK (flock), NOT A FILE THAT EXISTS. The first version created the file
 * and deleted it on shutdown: shutdown runs before the output buffer stores the page, so a
 * second request rebuilt meanwhile, and taking over a lock left by a dead request could let two
 * requests win (Codex, 06/11/2026). flock is held by this process alone, is released when it
 * calls wpopt_pc_release_lock() after storing, and by the kernel if the process dies — so no
 * lock is ever left behind.
 *
 * @param string $lock Lock file (created if missing, never deleted).
 * @return resource|false The handle to keep until the page is stored, or false.
 */
function wpopt_pc_try_lock( $lock ) {
	$fh = wpopt_pc_open_lock( (string) $lock );
	if ( false === $fh ) {
		return false;
	}
	if ( ! flock( $fh, LOCK_EX | LOCK_NB ) ) {
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return false;
	}
	return $fh;
}

/**
 * Open a lock file, creating it when missing. Read-only when it exists but this user cannot write
 * to it (left by root or a deploy user): flock() needs no write access on a local filesystem, so
 * the lock still works there; on NFS, where flock() may be emulated with fcntl() and an exclusive
 * lock needs write access, flock() then fails and the caller behaves as without the lock.
 *
 * @param string $path Lock file.
 * @return resource|false
 */
function wpopt_pc_open_lock( $path ) {
	$path = (string) $path;
	$new  = ! file_exists( $path );
	$fh   = @fopen( $path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Runs before WordPress.
	if ( false === $fh ) {
		return @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Runs before WordPress; missing or unreadable: false.
	}
	if ( $new ) {
		wpopt_pc_own( $path );
	}
	return $fh;
}

/**
 * The effective user id of this process, read once. -1 where POSIX functions are missing (then
 * nothing is ever re-owned). Tests pass a value to stand in for root.
 *
 * @param int|null $set Value to use from now on; null = read it.
 * @return int
 */
function wpopt_pc_euid( $set = null ) {
	static $euid = null;
	if ( null !== $set ) {
		$euid = (int) $set;
	} elseif ( null === $euid ) {
		$euid = function_exists( 'posix_geteuid' ) ? (int) posix_geteuid() : -1;
	}
	return $euid;
}

/**
 * Give a file or folder this process just created in the cache to the owner and group of the
 * folder that holds it — only when this process runs as root.
 *
 * WHY. The cache is written by the web server (www-data) and, now and then, by WP-CLI. Run as
 * root (`wp … --allow-root`, a deploy script, a root cron), WP-CLI created «.purge.lock», a
 * host folder after a full purge, or a page folder after a URL purge as root:root 0755/0644, and
 * the web server could no longer store a page there, nor a full purge move it — silently
 * (audit, 08/11/2026). Each folder is created one level at a time (wpopt_pc_mkdir()), so the
 * folder holding a new one already has the right owner; the first one copies the owner of the
 * cache folder (or of the folder above it, when the cache folder itself is being created).
 * Never anything as the web server: chown() needs root, and the check costs one comparison.
 *
 * @param string $path File or folder.
 * @return bool True when it was re-owned.
 */
function wpopt_pc_own( $path ) {
	if ( 0 !== wpopt_pc_euid() ) {
		return false;
	}
	$path = rtrim( (string) $path, '/' );
	$ref  = dirname( $path );
	$uid  = @fileowner( $ref ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing: false.
	$gid  = @filegroup( $ref ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing: false.
	if ( false === $uid || false === $gid || ! file_exists( $path ) ) {
		return false;
	}
	$ok = @chown( $path, $uid ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chown -- Runs before WordPress; best effort.
	return @chgrp( $path, $gid ) && $ok; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp -- Runs before WordPress; best effort.
}

/**
 * Create a folder and the missing ones above it, one level at a time, each given to the owner of
 * the folder that holds it when this process is root (wpopt_pc_own()). mkdir( …, true ) would
 * create the whole chain as root with no way to re-own the levels in between.
 *
 * @param string $dir Folder.
 * @return bool True when it exists afterwards.
 */
function wpopt_pc_mkdir( $dir ) {
	$dir = rtrim( (string) $dir, '/' );
	if ( '' === $dir || is_dir( $dir ) ) {
		return '' !== $dir;
	}
	$missing = array();
	$d       = $dir;
	while ( ! is_dir( $d ) ) {
		$missing[] = $d;
		if ( dirname( $d ) === $d ) {
			break; // The top of a path that does not exist at all.
		}
		$d = dirname( $d );
	}
	foreach ( array_reverse( $missing ) as $d ) {
		if ( @mkdir( $d, 0755 ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Runs before WordPress.
			wpopt_pc_own( $d );
		} elseif ( ! is_dir( $d ) ) {
			return false; // Not a race with another request that created it: a real failure.
		}
	}
	return true;
}

/**
 * Give the rebuild right back.
 *
 * @param resource|false $fh Handle from wpopt_pc_try_lock().
 * @return void
 */
function wpopt_pc_release_lock( $fh ) {
	if ( is_resource( $fh ) ) {
		flock( $fh, LOCK_UN );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}

/**
 * Is a cached page still young enough to serve?
 *
 * A cached page used to live until something purged it. Pages carry nonces (forms, the
 * comment form, "add to cart" in some themes) that WordPress stops accepting after 12–24 h,
 * so a page nobody edited for two days served a form that failed on submit. A stale file is
 * simply treated as a miss: the request renders and the fresh copy replaces it.
 *
 * @param int $mtime File modification time.
 * @param int $now   Current time.
 * @param int $ttl   Lifetime in seconds; 0 = never expires.
 * @return bool
 */
function wpopt_pc_is_fresh( $mtime, $now, $ttl ) {
	$ttl = (int) $ttl;
	return $ttl <= 0 || ( (int) $now - (int) $mtime ) < $ttl;
}

/**
 * Write a cache file atomically: create the directory, write to a unique temp file
 * in the same dir, then rename() over the target so a concurrent read never sees a
 * half-written page. Best-effort (a cache write must never fatal a page render).
 *
 * @param string $file     Destination path.
 * @param string $contents File contents.
 * @return bool True on success.
 */
function wpopt_pc_write_atomic( $file, $contents ) {
	$tmp = wpopt_pc_write_temp( $file, $contents );
	if ( false === $tmp ) {
		return false;
	}
	if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_unlink, WordPress.WP.AlternativeFunctions.unlink_unlink -- drop-in runs before WordPress; wp_delete_file() is unavailable. Cleaning up our own temp file.
		return false;
	}
	return true;
}

/**
 * Write contents to a unique temporary file beside the destination (same folder, so a later
 * rename() over it is atomic), creating the folder when missing.
 *
 * @param string $file     Destination path.
 * @param string $contents File contents.
 * @return string|false The temporary path, or false.
 */
function wpopt_pc_write_temp( $file, $contents ) {
	$dir = dirname( (string) $file );
	if ( ! wpopt_pc_mkdir( $dir ) ) {
		return false;
	}
	$tmp = $file . '.tmp.' . getmypid() . '.' . uniqid( '', true );
	if ( false === @file_put_contents( $tmp, $contents, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink -- A partial temporary file of our own (disk full).
		return false;
	}
	// Owned before the rename: the published name never belongs to root, not even for an instant.
	wpopt_pc_own( $tmp );
	return $tmp;
}

/*
 * ── Hit diagnostics (Pro) ────────────────────────────────────────────────────
 * One file per hour and outcome in a folder whose name carries a random token
 * (<cache dir>/.stats-<token>/2026110614-hit), grown by one byte per request: its size is the
 * count. Appending a byte needs neither a lock nor a read, so thousands of visitors at once
 * never wait on each other, and a lost byte under a race costs one count, never the page. The
 * plugin only puts 'stats_dir' in the sidecar when the site asked for it (off by default), so
 * a site that did not pays nothing but one empty() per request.
 */

