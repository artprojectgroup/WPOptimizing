<?php
/**
 * Which URLs a content change makes stale, and the captures that remember the old ones. Also
 * the pages purged with every purge (Pro) and the page lifetime policy, shared the same way.
 *
 * Shared by every cache that purges per URL: the page cache (Pro), Cloudflare and Varnish
 * (free). It lived in page-cache.php until the page cache became Pro (23/10/2026); it moved
 * here so the free version keeps its partial purges without shipping the page cache.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/*
 * The page lifetime policy lives here, next to the purge lists, because this file is the one
 * every caller can reach: the page-cache screen (Pro), the importers and the settings file (both
 * builds), and the page cache itself. One policy, so the screen and an import never disagree.
 */
defined( 'WPOPTIMIZING_PC_TTL_MAX_HOURS' ) || define( 'WPOPTIMIZING_PC_TTL_MAX_HOURS', 12 );
defined( 'WPOPTIMIZING_PC_TTL_DEFAULT_HOURS' ) || define( 'WPOPTIMIZING_PC_TTL_DEFAULT_HOURS', 10 );

/**
 * A page lifetime brought inside the policy: whole hours from 1 to 12. Pure.
 *
 * Forms, «add to cart» and many buttons carry WordPress nonces, which stop working after 12 to
 * 24 hours; a page served for longer shows forms that fail. So anything longer than 12 hours,
 * and «never expires» (0), becomes the default 10 hours (WP Rocket's). Less than an hour, or a
 * part of one, goes to the nearest whole hour, which is what the screen can show. A longer
 * lifetime is still possible through the wpoptimizing_page_cache_ttl filter, on purpose.
 *
 * @param int $seconds Lifetime in seconds (0 = never).
 * @return array{ttl:int,change:string} 'change': '' (kept), 'long' (over the limit or never) or 'rounded'.
 */
function wpoptimizing_page_cache_ttl_policy( $seconds ) {
	$seconds = max( 0, (int) $seconds );
	if ( 0 === $seconds || $seconds > WPOPTIMIZING_PC_TTL_MAX_HOURS * HOUR_IN_SECONDS ) {
		return array(
			'ttl'    => WPOPTIMIZING_PC_TTL_DEFAULT_HOURS * HOUR_IN_SECONDS,
			'change' => 'long',
		);
	}
	$hours = min( WPOPTIMIZING_PC_TTL_MAX_HOURS, max( 1, (int) round( $seconds / HOUR_IN_SECONDS ) ) );
	return array(
		'ttl'    => $hours * HOUR_IN_SECONDS,
		'change' => $hours * HOUR_IN_SECONDS === $seconds ? '' : 'rounded',
	);
}

/**
 * The «also purge these» list typed by hand: pages of this site only, as absolute URLs. Pure.
 *
 * A path («/contact/») is read from the root of the site's host, as a browser would and as WP
 * Rocket did with the same list. A full URL must be on the home host (and port): purging another
 * site's address would do nothing here and send someone else's URL to Cloudflare. Query strings
 * and fragments go (the page cache keys pages by path). Wildcards are refused rather than
 * purged as text: they would never match a real page.
 *
 * @param string|string[] $lines One entry per line, or a list.
 * @param string          $home  The site's home URL.
 * @param int             $max   Most entries kept.
 * @return array{urls:string[],rejected:string[]}
 */
function wpoptimizing_content_always_purge_parse( $lines, $home, $max = 100 ) {
	$lines  = is_array( $lines ) ? $lines : preg_split( '/[\r\n]+/', (string) $lines );
	$home_p = wp_parse_url( (string) $home );
	$out    = array(
		'urls'     => array(),
		'rejected' => array(),
	);
	if ( ! is_array( $home_p ) || empty( $home_p['host'] ) ) {
		return $out;
	}
	$scheme = isset( $home_p['scheme'] ) && 'http' === strtolower( $home_p['scheme'] ) ? 'http' : 'https';
	$host   = strtolower( $home_p['host'] ) . ( isset( $home_p['port'] ) ? ':' . (int) $home_p['port'] : '' );
	foreach ( (array) $lines as $line ) {
		$line = is_scalar( $line ) ? trim( (string) $line ) : '';
		if ( '' === $line ) {
			continue;
		}
		$p = wp_parse_url( $line );
		if ( ! is_array( $p ) || preg_match( '/[\s*<>"]/', $line ) ) {
			$out['rejected'][] = $line;
			continue;
		}
		if ( isset( $p['host'] ) ) {
			$this_host = strtolower( $p['host'] ) . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
			$ok_scheme = isset( $p['scheme'] ) && in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true );
			if ( $this_host !== $host || ! $ok_scheme ) {
				$out['rejected'][] = $line;
				continue;
			}
		} elseif ( ! isset( $p['path'] ) || '' === $p['path'] || '/' !== $p['path'][0] ) {
			$out['rejected'][] = $line; // «contact» alone: not a path we can place for sure.
			continue;
		}
		$url = $scheme . '://' . $host . ( isset( $p['path'] ) && '' !== $p['path'] ? $p['path'] : '/' );
		if ( ! in_array( $url, $out['urls'], true ) && count( $out['urls'] ) < (int) $max ) {
			$out['urls'][] = $url;
		}
	}
	return $out;
}

/**
 * The pages purged with every page purge (Pro): «Purge also these URLs» on the page-cache screen,
 * WP Rocket's «Always Purge URL(s)». Read here, where the URLs of a change are worked out, so the
 * page cache, Cloudflare, Varnish and the hosting's cache all get them from one place, with our
 * page cache switched off too.
 *
 * @return string[] Absolute URLs.
 */
function wpoptimizing_content_always_purge_urls() {
	$urls = array();
	return $urls;
}

/**
 * A list of purged URLs with the always-purged ones added, once and without repeats.
 *
 * Added to the list a purge works out, never inside the purge of one URL: clearing one of
 * these pages does not add the list again, so there is no loop.
 *
 * @param string[] $urls Absolute URLs.
 * @return string[]
 */
function wpoptimizing_content_with_always_purge( array $urls ) {
	return array_values( array_unique( array_filter( array_map( 'strval', array_merge( $urls, wpoptimizing_content_always_purge_urls() ) ) ) ) );
}

/**
 * The URLs a content change makes stale: the post's own URL plus the home, its post type
 * archive and its terms — the pages whose listing includes it.
 *
 * Shared by every cache that purges per URL — our page cache and Cloudflare — so they never
 * disagree about which pages one change touches.
 *
 * The list also carries the URLs the post had BEFORE the change (wpoptimizing_content_remember_urls()):
 * a renamed slug, an unpublished or trashed post and a deleted one are all stale at an address
 * get_permalink() no longer returns.
 *
 * @param int $post_id Post id.
 * @return string[] Absolute URLs, or an empty list for a revision, an auto-draft or no post.
 */
function wpoptimizing_content_purge_urls( $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 || ! function_exists( 'get_post' ) ) {
		return array();
	}
	$old  = wpoptimizing_content_remembered_urls( $post_id );
	$post = get_post( $post_id );
	if ( ! $post ) {
		// Deleted for good: only what was captured before the delete is left to purge.
		return array() === $old ? array() : (array) apply_filters( 'wpoptimizing_page_cache_purge_urls', wpoptimizing_content_with_always_purge( array_merge( array( home_url( '/' ) ), $old ) ), $post_id );
	}
	if ( 'revision' === $post->post_type || 'auto-draft' === $post->post_status ) {
		return array();
	}
	$urls = array_merge( array( home_url( '/' ) ), $old );
	// Only a public post has its own page. A draft or trashed one answers with /?p=ID, which our
	// cache files under the home page and Cloudflare under an address nobody visits.
	$permalink = wpoptimizing_content_status_is_public( $post->post_status ) ? get_permalink( $post ) : '';
	if ( $permalink ) {
		$urls[] = $permalink;
	}
	$archive = get_post_type_archive_link( $post->post_type );
	if ( $archive ) {
		$urls[] = $archive;
	}
	// The blog page when it is not the front page, the author's archive, the date archives
	// and every ancestor page — all of them list or link this post.
	$posts_page = (int) get_option( 'page_for_posts' );
	if ( 'post' === $post->post_type && $posts_page > 0 ) {
		$urls[] = get_permalink( $posts_page );
	}
	if ( post_type_supports( $post->post_type, 'author' ) && $post->post_author ) {
		$urls[] = get_author_posts_url( (int) $post->post_author );
	}
	if ( 'post' === $post->post_type ) {
		$time   = strtotime( (string) $post->post_date );
		$urls[] = get_year_link( (int) gmdate( 'Y', $time ) );
		$urls[] = get_month_link( (int) gmdate( 'Y', $time ), (int) gmdate( 'm', $time ) );
		$urls[] = get_day_link( (int) gmdate( 'Y', $time ), (int) gmdate( 'm', $time ), (int) gmdate( 'd', $time ) );
	}
	foreach ( get_post_ancestors( $post ) as $ancestor ) {
		$urls[] = get_permalink( $ancestor );
	}
	foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
		$terms = get_the_terms( $post_id, $tax );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$urls[] = $link;
				}
			}
		}
	}
	$urls = array_merge( $urls, wpoptimizing_content_translation_urls( $post ) );
	/**
	 * Filter the URLs purged for a content change.
	 *
	 * @param array $urls    Absolute URLs (the always-purged ones included).
	 * @param int   $post_id Post id.
	 */
	return (array) apply_filters( 'wpoptimizing_page_cache_purge_urls', wpoptimizing_content_with_always_purge( $urls ), $post_id );
}

/**
 * The translations of a post, and the home page of each of their languages.
 *
 * Each language has its own home page listing its latest posts, and the purge only reached the
 * default one. And WooCommerce Multilingual shares stock and price between the translations
 * of a product, so selling the last unit in Spanish leaves the English page «in stock».
 * Through each plugin's public API (Polylang's functions, WPML's filters), never their tables.
 *
 * @param WP_Post $post Post.
 * @return string[]
 */
function wpoptimizing_content_translation_urls( $post ) {
	$urls = array();
	$ids  = array();
	if ( function_exists( 'pll_get_post_translations' ) ) {
		foreach ( (array) pll_get_post_translations( $post->ID ) as $lang => $id ) {
			$ids[] = (int) $id;
			if ( function_exists( 'pll_home_url' ) ) {
				$urls[] = (string) pll_home_url( (string) $lang );
			}
		}
	} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$type = 'post_' . $post->post_type;
		$trid = apply_filters( 'wpml_element_trid', null, $post->ID, $type ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API hook.
		if ( $trid ) {
			foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $lang => $tr ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API hook.
				if ( is_object( $tr ) && ! empty( $tr->element_id ) ) {
					$ids[]  = (int) $tr->element_id;
					$urls[] = (string) apply_filters( 'wpml_permalink', home_url( '/' ), (string) $lang ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own API hook.
				}
			}
		}
	}
	foreach ( array_unique( $ids ) as $id ) {
		if ( $id > 0 && $id !== (int) $post->ID && wpoptimizing_content_status_is_public( (string) get_post_status( $id ) ) ) {
			$urls[] = (string) get_permalink( $id );
		}
	}
	return $urls;
}

/**
 * Does a post in this status have a public page of its own?
 *
 * @param string $status Post status.
 * @return bool
 */
function wpoptimizing_content_status_is_public( $status ) {
	$status = (string) $status;
	if ( 'publish' === $status || 'inherit' === $status ) {
		return true; // 'inherit': an attachment's page. Revisions never get this far.
	}
	return function_exists( 'is_post_status_viewable' ) && is_post_status_viewable( $status );
}

/**
 * Remember URLs a post had before the change being saved, so every purge of that post during
 * this request — ours, Cloudflare's, Varnish's, all through wpoptimizing_content_purge_urls() —
 * includes them. Per request only.
 *
 * @param int      $post_id Post id.
 * @param string[] $urls    Absolute URLs.
 * @return void
 */
function wpoptimizing_content_remember_urls( $post_id, array $urls ) {
	$post_id = (int) $post_id;
	$urls    = array_filter( array_map( 'strval', $urls ) );
	if ( $post_id <= 0 || array() === $urls ) {
		return;
	}
	$known = isset( $GLOBALS['wpoptimizing_content_old_urls'][ $post_id ] ) ? (array) $GLOBALS['wpoptimizing_content_old_urls'][ $post_id ] : array();

	$GLOBALS['wpoptimizing_content_old_urls'][ $post_id ] = array_values( array_unique( array_merge( $known, $urls ) ) );
}

/**
 * The URLs remembered for a post in this request.
 *
 * @param int $post_id Post id.
 * @return string[]
 */
function wpoptimizing_content_remembered_urls( $post_id ) {
	$post_id = (int) $post_id;
	return isset( $GLOBALS['wpoptimizing_content_old_urls'][ $post_id ] ) ? (array) $GLOBALS['wpoptimizing_content_old_urls'][ $post_id ] : array();
}

/**
 * post_updated: the post's address before this save, when it had one and it changes — a new
 * slug or parent, or a public post going to draft, private or the trash. By save_post the row
 * already holds the new values and get_permalink() only knows the new address, so the old page
 * stayed cached (here, at Cloudflare, in Varnish) for its whole lifetime.
 *
 * @param int     $post_id     Post id.
 * @param WP_Post $post_after  Post after the update.
 * @param WP_Post $post_before Post before the update.
 * @return void
 */
function wpoptimizing_content_capture_before_update( $post_id, $post_after = null, $post_before = null ) {
	if ( ! is_object( $post_before ) || ! wpoptimizing_content_status_is_public( $post_before->post_status ) ) {
		return;
	}
	$old = (string) get_permalink( $post_before );
	$new = ( is_object( $post_after ) && wpoptimizing_content_status_is_public( $post_after->post_status ) ) ? (string) get_permalink( $post_after ) : '';
	if ( '' !== $old && $old !== $new ) {
		wpoptimizing_content_remember_urls( (int) $post_id, array( $old ) );
	}
}

/**
 * wp_trash_post / before_delete_post: everything the post makes stale, computed while it is still
 * there and still public. After a permanent delete there is no post left to compute it from.
 *
 * @param int $post_id Post id.
 * @return void
 */
function wpoptimizing_content_capture_before_removal( $post_id ) {
	$post_id = (int) $post_id;
	$post    = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
	if ( ! $post || ! wpoptimizing_content_status_is_public( $post->post_status ) ) {
		return;
	}
	wpoptimizing_content_remember_urls( $post_id, wpoptimizing_content_purge_urls( $post_id ) );
}

/**
 * set_object_terms: the archives of terms a post just left or joined. A post taken out of a
 * category is no longer listed there, but get_the_terms() only knows the categories it has now.
 *
 * In the block editor this runs AFTER save_post (the REST controller assigns terms afterwards),
 * which is why the purges also listen to wp_after_insert_post.
 *
 * @param int    $object_id  Object id.
 * @param array  $terms      Terms passed in.
 * @param array  $tt_ids     New term_taxonomy ids.
 * @param string $taxonomy   Taxonomy.
 * @param bool   $append     Appended rather than replaced.
 * @param array  $old_tt_ids Term_taxonomy ids before.
 * @return void
 */
function wpoptimizing_content_capture_term_change( $object_id, $terms = array(), $tt_ids = array(), $taxonomy = '', $append = false, $old_tt_ids = array() ) {
	$tt_ids     = array_map( 'intval', (array) $tt_ids );
	$old_tt_ids = array_map( 'intval', (array) $old_tt_ids );
	$changed    = array_unique( array_merge( array_diff( $tt_ids, $old_tt_ids ), array_diff( $old_tt_ids, $tt_ids ) ) );
	if ( array() === $changed || ! function_exists( 'get_term_by' ) ) {
		return;
	}
	$urls = array();
	foreach ( $changed as $tt_id ) {
		$term = get_term_by( 'term_taxonomy_id', $tt_id, (string) $taxonomy );
		if ( $term ) {
			$link = get_term_link( $term );
			if ( ! is_wp_error( $link ) ) {
				$urls[] = (string) $link;
			}
		}
	}
	wpoptimizing_content_remember_urls( (int) $object_id, $urls );
}

/**
 * Purge a post's pages when one of its comments shows up on them or stops showing: hook a
 * purger's «comment id» callback to the three moments that can do it. One rule for every purger.
 *
 * A comment that waits for moderation, or goes straight to spam, changes no page anyone sees.
 * They used to purge anyway — comment_post fires for every one of them — so a spam bot posting
 * to a post emptied its copies (and asked Cloudflare, Varnish and the hosting to do the same)
 * as fast as it posted. Now: a new comment only when it is published at once; a status change
 * only when it enters or leaves «approved» (transition_comment_status, which also says what it
 * was before, unlike wp_set_comment_status); an edit only of a published comment.
 *
 * @param callable $callback Called with the comment id.
 * @return void
 */
function wpoptimizing_content_register_comment_hooks( $callback ) {
	add_action(
		'comment_post',
		static function ( $comment_id, $approved = 0 ) use ( $callback ) {
			if ( 1 === (int) $approved ) { // 0 waits for moderation; 'spam' and 'trash' are 0 too.
				call_user_func( $callback, (int) $comment_id );
			}
		},
		10,
		2
	);
	add_action(
		'transition_comment_status',
		static function ( $new_status, $old_status, $comment ) use ( $callback ) {
			if ( is_object( $comment ) && ! empty( $comment->comment_ID ) && wpoptimizing_content_comment_status_shows( $new_status, $old_status ) ) {
				call_user_func( $callback, (int) $comment->comment_ID );
			}
		},
		10,
		3
	);
	add_action(
		'edit_comment',
		static function ( $comment_id ) use ( $callback ) {
			$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
			if ( $comment && '1' === (string) $comment->comment_approved ) {
				call_user_func( $callback, (int) $comment_id );
			}
		}
	);
}

/**
 * Does a comment's change of status change what its post's pages show? Pure.
 *
 * Only when it enters or leaves «approved» (published): «unapproved», «spam», «trash» and a
 * deletion («delete») are all invisible, so going from one of them to another changes nothing.
 *
 * @param string $new_status New status (transition_comment_status).
 * @param string $old_status Old status.
 * @return bool
 */
function wpoptimizing_content_comment_status_shows( $new_status, $old_status ) {
	$new_status = (string) $new_status;
	$old_status = (string) $old_status;
	return $new_status !== $old_status && ( 'approved' === $new_status || 'approved' === $old_status );
}

/**
 * The hooks after which a post's URLs are stale. One list for the three per-URL purgers.
 *
 * @return string[]
 */
function wpoptimizing_content_purge_hooks() {
	return array( 'save_post', 'wp_after_insert_post', 'deleted_post', 'trashed_post' );
}

/**
 * WooCommerce events that change a product page without saving the product: stock and stock
 * status (an order, a refund, a manual edit) and a scheduled sale starting or ending. Every
 * purger that works per URL listens to them; before, only the page cache did, so Cloudflare
 * and Varnish kept «in stock» and the old price for hours after the last unit sold.
 *
 * @return string[]
 */
function wpoptimizing_content_product_hooks() {
	return array( 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'wc_after_products_starting_sales', 'wc_after_products_ending_sales' );
}

/**
 * The post ids a product event concerns: the product and, for a variation, its parent (the
 * page a visitor sees). The events pass a WC_Product, a product id or a list of ids.
 *
 * @param mixed $product WC_Product, id or ids.
 * @return int[]
 */
function wpoptimizing_content_product_ids( $product ) {
	$ids = array();
	if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
		$ids[] = (int) $product->get_id();
		if ( method_exists( $product, 'get_parent_id' ) && $product->get_parent_id() ) {
			$ids[] = (int) $product->get_parent_id();
		}
	} else {
		foreach ( array_map( 'intval', (array) $product ) as $id ) {
			$ids[]  = $id;
			$parent = $id > 0 && function_exists( 'wp_get_post_parent_id' ) ? (int) wp_get_post_parent_id( $id ) : 0;
			if ( $parent > 0 && function_exists( 'get_post_type' ) && 'product_variation' === get_post_type( $id ) ) {
				$ids[] = $parent;
			}
		}
	}
	return array_values( array_unique( array_filter( $ids ) ) );
}

/**
 * Register the "before the change" captures. Called by every purger that works per URL (page
 * cache, Cloudflare, Varnish) when it is on; add_action() keeps a single copy of each.
 *
 * @return void
 */
function wpoptimizing_content_register_captures() {
	add_action( 'post_updated', 'wpoptimizing_content_capture_before_update', 10, 3 );
	add_action( 'wp_trash_post', 'wpoptimizing_content_capture_before_removal' );
	add_action( 'before_delete_post', 'wpoptimizing_content_capture_before_removal' );
	add_action( 'set_object_terms', 'wpoptimizing_content_capture_term_change', 10, 6 );
}

/**
 * Is this post a piece of layout that appears on many pages — a block template or template
 * part, the global styles, a navigation menu, a synced pattern, an Elementor or Avada
 * template? Saving one changes the header or footer of the whole site, and purging only its
 * own (non-public) URL left every cached page with the old one.
 *
 * Lives here, in a file Lite ships, because the hosting caches are purged in Lite too: in
 * includes/page-cache.php (Pro) a saved template went to them as a plain post and a host that can
 * only empty everything was never emptied (Codex, 08/11/2026).
 *
 * @param int $post_id Post id.
 * @return bool
 */
function wpoptimizing_page_cache_is_shared_template( $post_id ) {
	$type  = (string) get_post_type( $post_id );
	$types = array( 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_block', 'elementor_library', 'fusion_tb_section', 'fusion_tb_layout', 'fusion_element', 'et_template', 'et_header_layout', 'et_footer_layout', 'et_body_layout', 'ct_template', 'bricks_template', 'kadence_element', 'gp_elements' );
	/**
	 * Filters the post types whose change purges the whole page cache.
	 *
	 * @since 0.1.0
	 * @param string[] $types Post types.
	 */
	return '' !== $type && in_array( $type, (array) apply_filters( 'wpoptimizing_page_cache_layout_post_types', $types ), true );
}
