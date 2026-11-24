<?php
/**
 * Which CSS a page could actually use.
 *
 * A theme's stylesheet is written for every layout the theme can produce. One page uses a
 * fraction of it, and the rest is still parsed, matched against every element, and kept in
 * memory — on the render path, before anything can paint.
 *
 * What this file computes is what the FIRST PAINT may use. It has no browser, so it cannot know
 * which rule wins the cascade; what it can know is that a rule requiring `.woocommerce-cart`
 * cannot possibly apply to a document with no such class in it, and the generator only hands it
 * the start of the document (wpoptimizing_ccss_fold_html()). Within that:
 *
 *  - interaction rules (:hover, :focus, :active…) are left out: nobody has touched the page yet;
 *  - a script-toggled state (.is-open, [aria-expanded]) is kept only when the HTML carries it;
 *  - anything whose selector this file does not fully understand is kept;
 *  - every @font-face is kept, because a missing font is visible on first paint.
 *
 * Until 09/11/2026 it kept every state and every selector starting with html or body, and on an
 * Avada front page the "critical" CSS weighed 275 KB, a third of all the stylesheets.
 *
 * And the result is never a replacement. It is inlined in the head while the original sheets
 * keep loading asynchronously, so a rule this file got wrong arrives a moment later: the worst
 * case is a flash, not a broken layout. That asymmetry is the whole safety model, and it is why
 * the extraction is allowed to be approximate.
 *
 * @package WPOptimizing
 * @since   0.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip comments. Pure.
 *
 * @since 0.1.0
 * @param string $css Stylesheet source.
 * @return string
 */
function wpoptimizing_ccss_strip_comments( $css ) {
	return (string) preg_replace( '#/\*.*?\*/#s', '', (string) $css );
}

/**
 * Every tag, class and id the document contains. Pure.
 *
 * Read off the markup rather than the DOM: an id or class is a token in an attribute, and a
 * tolerant scan of the raw HTML finds them in malformed markup too, where a parser would give
 * up and hand back a tree with pieces missing — and a missing piece here means a rule dropped
 * that the page needed.
 *
 * @since 0.1.0
 * @param string $html Rendered page.
 * @return array{tags:array<string,bool>,classes:array<string,bool>,ids:array<string,bool>}
 */
function wpoptimizing_ccss_document_tokens( $html ) {
	$html = (string) $html;
	$out  = array(
		'tags'    => array(),
		'classes' => array(),
		'ids'     => array(),
	);

	if ( preg_match_all( '/<([a-zA-Z][a-zA-Z0-9-]*)\b/', $html, $m ) ) {
		foreach ( $m[1] as $tag ) {
			$out['tags'][ strtolower( $tag ) ] = true;
		}
	}

	// Every class attribute, including the ones a template built by concatenation.
	if ( preg_match_all( '/\bclass\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
		foreach ( $m[2] as $value ) {
			foreach ( preg_split( '/\s+/', html_entity_decode( $value, ENT_QUOTES ) ) as $class ) {
				if ( '' !== $class ) {
					$out['classes'][ $class ] = true;
				}
			}
		}
	}

	if ( preg_match_all( '/\bid\s*=\s*(["\'])(.*?)\1/is', $html, $m ) ) {
		foreach ( $m[2] as $value ) {
			$value = trim( html_entity_decode( $value, ENT_QUOTES ) );
			if ( '' !== $value ) {
				$out['ids'][ $value ] = true;
			}
		}
	}

	return $out;
}

/**
 * Selector fragments that mean "this only exists after something happens". Pure.
 *
 * The crawler sees one state of the page: menus closed, modals unmounted, nothing focused, the
 * cart empty. A rule that depends on any of those is not unused, it is not visible yet, and
 * dropping it is how a site ends up with an unstyled menu the first time somebody opens it.
 *
 * @since 0.1.0
 * @return string[]
 */
function wpoptimizing_ccss_state_markers() {
	$markers = array(
		// Interaction pseudo-classes.
		':hover',
		':focus',
		':active',
		':visited',
		':checked',
		':target',
		':disabled',
		':enabled',
		':indeterminate',
		':placeholder-shown',
		':focus-within',
		':focus-visible',
		':user-invalid',
		':invalid',
		':valid',
		':required',
		':optional',
		':popover-open',
		'::backdrop',
		'::selection',
		'::placeholder',
		'::marker',
		// Attributes that scripts toggle.
		'[aria-expanded',
		'[aria-hidden',
		'[aria-selected',
		'[aria-current',
		'[aria-pressed',
		'[open',
		'[hidden',
		'[data-state',
		'[data-open',
		'[inert',
		// The class names frameworks and themes use for the same thing.
		'.is-open',
		'.is-active',
		'.is-visible',
		'.is-hidden',
		'.is-expanded',
		'.is-sticky',
		'.active',
		'.open',
		'.opened',
		'.show',
		'.shown',
		'.visible',
		'.collapsed',
		'.expanded',
		'.toggled',
		'.menu-open',
		'.nav-open',
		'.modal-open',
		'.offcanvas',
		'.drawer',
		'.dropdown-menu',
		'.submenu',
		'.sub-menu',
		'.has-error',
		'.loading',
		'.selected',
		'.current',
		'.sticky',
		'.fixed',
		'.scrolled',
		'.lightbox',
		'.tooltip',
		'.popover',
		'.overlay',
		'.backdrop',
		'.dialog',
		'.mini-cart',
		'.cart-open',
	);

	/**
	 * Filters the markers that force a rule to be kept.
	 *
	 * @since 0.1.0
	 * @param string[] $markers Case-insensitive selector fragments.
	 */
	return (array) apply_filters( 'wpoptimizing_ccss_state_markers', $markers );
}

/**
 * Must this selector be kept whatever the document contains? Pure.
 *
 * @since 0.1.0
 * @param string $selector One selector.
 * @return bool
 */
function wpoptimizing_ccss_always_keep_selector( $selector ) {
	$selector = strtolower( trim( (string) $selector ) );

	// The document's own roots, and the custom properties almost always declared on them: the
	// bare root only. «^(html|body)\b» kept any selector that STARTED with one of them, whatever
	// followed: «html:not(.x) #main», «body.layout-boxed-mode #wrapper»… On an Avada front page
	// that alone kept tens of kilobytes the page could not use (emiliocalvo.es, 09/11/2026).
	return 1 === preg_match( '/^(?::root|html|body|\*)(?:::?(?:before|after))?$/', $selector );
}

/**
 * Does this selector only apply after the visitor does something? Pure.
 *
 * What is inlined is critical CSS: the first paint, before anybody has hovered, focused or
 * clicked anything. The full stylesheets keep loading asynchronously and bring these rules a
 * moment later, long before a pointer reaches the page. Keeping them is what made the inlined
 * block weigh as much as the stylesheets it was meant to stand in for.
 *
 * The other state markers (a script-toggled class, an ARIA attribute) are not dropped here:
 * they are checked against the document like any selector, so `.current-menu-item` or an
 * initial `.active` slide present in the HTML keep their rules.
 *
 * @since 0.1.0
 * @param string $selector One selector.
 * @return bool
 */
function wpoptimizing_ccss_interaction_only( $selector ) {
	return 1 === preg_match( '/:(?:hover|focus|focus-within|focus-visible|active|visited)\b/i', (string) $selector );
}

/**
 * Could this selector match something in the document? Pure.
 *
 * Token containment, not a cascade: every class, id and tag the selector requires must exist
 * SOMEWHERE in the document. `.a .b` is kept when both exist even if nothing nests them, which
 * is an over-keep and the direction this has to err in.
 *
 * Anything the parser cannot read confidently — an attribute test, a functional pseudo-class,
 * an escape sequence — leaves that part unchecked rather than guessing.
 *
 * @since 0.1.0
 * @param string $selector One selector from a selector list.
 * @param array  $tokens   From wpoptimizing_ccss_document_tokens().
 * @return bool
 */
function wpoptimizing_ccss_selector_applies( $selector, array $tokens ) {
	$selector = trim( (string) $selector );
	if ( '' === $selector ) {
		return false;
	}
	if ( wpoptimizing_ccss_always_keep_selector( $selector ) ) {
		return true;
	}
	if ( wpoptimizing_ccss_interaction_only( $selector ) ) {
		return false;
	}

	// The contents of :is()/:where()/:not()/:has() are a selector list of their own; reading
	// them properly is a parser this does not need. Emptying them makes the OUTER selector the
	// only thing checked, which keeps more than it should — the safe direction.
	$work = (string) preg_replace( '/:[a-z-]+\([^()]*\)/i', '', $selector );
	// Attribute tests carry no class/id information; blank them so their contents are not
	// mistaken for one.
	$work = (string) preg_replace( '/\[[^\]]*\]/', '', $work );
	// Remaining pseudo-elements and pseudo-classes.
	$work = (string) preg_replace( '/::?[a-z-]+/i', '', $work );

	// Split on combinators into compounds; every compound has to be satisfiable.
	foreach ( preg_split( '/\s*[>+~]\s*|\s+/', trim( $work ) ) as $compound ) {
		$compound = trim( $compound );
		if ( '' === $compound || '*' === $compound ) {
			continue;
		}
		// An escaped character (a Tailwind-style `.w-1\/2`) is not worth decoding; keep it.
		if ( false !== strpos( $compound, '\\' ) ) {
			continue;
		}

		if ( preg_match_all( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $compound, $cm ) ) {
			foreach ( $cm[1] as $class ) {
				if ( ! isset( $tokens['classes'][ $class ] ) ) {
					return false;
				}
			}
		}
		if ( preg_match_all( '/#(-?[A-Za-z_][A-Za-z0-9_-]*)/', $compound, $im ) ) {
			foreach ( $im[1] as $id ) {
				if ( ! isset( $tokens['ids'][ $id ] ) ) {
					return false;
				}
			}
		}
		if ( preg_match( '/^([a-zA-Z][a-zA-Z0-9-]*)/', $compound, $tm ) ) {
			$tag = strtolower( $tm[1] );
			if ( ! isset( $tokens['tags'][ $tag ] ) ) {
				return false;
			}
		}
	}

	return true;
}

/**
 * Keep the selectors of a list that could apply. Pure.
 *
 * Filtering the list rather than the rule matters: a theme writes `.widget, .wp-block-search,
 * .woocommerce-cart-form` as one rule, and keeping the whole rule because one third of it
 * applies keeps two selectors the browser will match against every element forever.
 *
 * @since 0.1.0
 * @param string $prelude Raw selector list.
 * @param array  $tokens  Document tokens.
 * @return string The surviving list, or '' when none survive.
 */
function wpoptimizing_ccss_filter_prelude( $prelude, array $tokens ) {
	$kept = array();
	// Commas inside :is(...), :not(...) or an attribute value do not separate selectors.
	foreach ( wpoptimizing_ccss_split_selectors( (string) $prelude ) as $selector ) {
		if ( wpoptimizing_ccss_selector_applies( $selector, $tokens ) ) {
			$kept[] = trim( $selector );
		}
	}
	return implode( ',', $kept );
}

/**
 * Split a selector list on its top-level commas. Pure.
 *
 * @since 0.1.0
 * @param string $prelude Raw selector list.
 * @return string[]
 */
function wpoptimizing_ccss_split_selectors( $prelude ) {
	$out   = array();
	$buf   = '';
	$depth = 0;
	$quote = '';
	$len   = strlen( $prelude );

	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $prelude[ $i ];

		if ( '' !== $quote ) {
			$buf .= $ch;
			if ( $ch === $quote && ( $i < 1 || '\\' !== $prelude[ $i - 1 ] ) ) {
				$quote = '';
			}
			continue;
		}
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			$buf  .= $ch;
			continue;
		}
		if ( '(' === $ch || '[' === $ch ) {
			++$depth;
		} elseif ( ')' === $ch || ']' === $ch ) {
			$depth = max( 0, $depth - 1 );
		}
		if ( ',' === $ch && 0 === $depth ) {
			$out[] = $buf;
			$buf   = '';
			continue;
		}
		$buf .= $ch;
	}

	if ( '' !== trim( $buf ) ) {
		$out[] = $buf;
	}

	return $out;
}

/**
 * Reduce a stylesheet to the rules the document could use. Pure.
 *
 * @since 0.1.0
 * @param string $css    Stylesheet source.
 * @param array  $tokens Document tokens.
 * @param int    $depth  Recursion guard for nested at-rules.
 * @return string
 */
function wpoptimizing_ccss_reduce( $css, array $tokens, $depth = 0 ) {
	$css = wpoptimizing_ccss_strip_comments( (string) $css );
	$out = '';
	$i   = 0;
	$len = strlen( $css );

	while ( $i < $len ) {
		// Skip whitespace between constructs.
		if ( '' === trim( $css[ $i ] ) ) {
			++$i;
			continue;
		}

		if ( '@' === $css[ $i ] ) {
			$out .= wpoptimizing_ccss_read_at_rule( $css, $i, $tokens, $depth );
			continue;
		}

		$brace = wpoptimizing_ccss_find_block( $css, $i );
		if ( -1 === $brace[0] ) {
			break; // Truncated stylesheet: stop rather than invent a closing brace.
		}
		list( $open, $close ) = $brace;

		$prelude = wpoptimizing_ccss_filter_prelude( substr( $css, $i, $open - $i ), $tokens );
		$body    = trim( substr( $css, $open + 1, $close - $open - 1 ) );
		if ( '' !== $prelude && '' !== $body ) {
			$out .= $prelude . '{' . $body . '}';
		}
		$i = $close + 1;
	}

	return $out;
}

/**
 * Read one at-rule starting at $i, advancing the cursor. Impure only in that it moves $i.
 *
 * @since 0.1.0
 * @param string $css    Source.
 * @param int    $i      Cursor, by reference.
 * @param array  $tokens Document tokens.
 * @param int    $depth  Recursion depth.
 * @return string The at-rule's contribution to the output.
 */
function wpoptimizing_ccss_read_at_rule( $css, &$i, array $tokens, $depth ) {
	$len = strlen( $css );
	if ( ! preg_match( '/@([a-zA-Z-]+)/', substr( $css, $i, 40 ), $nm ) ) {
		++$i;
		return '';
	}
	$name = strtolower( $nm[1] );

	// Statement at-rules end at the semicolon.
	if ( in_array( $name, array( 'charset', 'import', 'namespace' ), true ) ) {
		$end = strpos( $css, ';', $i );
		if ( false === $end ) {
			$i = $len;
			return '';
		}
		$stmt = substr( $css, $i, $end - $i + 1 );
		$i    = $end + 1;
		// @import would pull a whole second stylesheet back in, undoing the reduction; the
		// original sheets still load asynchronously, so the import is not lost, only deferred.
		return 'import' === $name ? '' : $stmt;
	}

	$brace = wpoptimizing_ccss_find_block( $css, $i );
	if ( -1 === $brace[0] ) {
		$i = $len;
		return '';
	}
	list( $open, $close ) = $brace;
	$prelude              = trim( substr( $css, $i, $open - $i ) );
	$inner                = substr( $css, $open + 1, $close - $open - 1 );
	$i                    = $close + 1;

	// Conditional groups contain ordinary rules: reduce what is inside them.
	if ( in_array( $name, array( 'media', 'supports', 'layer', 'container', 'scope', 'document' ), true ) ) {
		if ( $depth > 4 ) {
			return $prelude . '{' . $inner . '}'; // Too deep to reason about: keep it whole.
		}
		$reduced = wpoptimizing_ccss_reduce( $inner, $tokens, $depth + 1 );
		return '' === trim( $reduced ) ? '' : $prelude . '{' . $reduced . '}';
	}

	// Everything else — @font-face, @keyframes, @page, @property, @counter-style — is kept
	// verbatim. A missing font or a missing custom property is visible on first paint, and
	// these blocks are a rounding error next to the rules.
	return $prelude . '{' . $inner . '}';
}

/**
 * Locate a rule's block: the opening brace at or after $from and its matching close. Pure.
 *
 * @since 0.1.0
 * @param string $css  Source.
 * @param int    $from Start offset.
 * @return array{0:int,1:int} Offsets, or array( -1, -1 ) when the block never closes.
 */
function wpoptimizing_ccss_find_block( $css, $from ) {
	$len   = strlen( $css );
	$open  = -1;
	$depth = 0;
	$quote = '';

	for ( $i = $from; $i < $len; $i++ ) {
		$ch = $css[ $i ];

		if ( '' !== $quote ) {
			if ( $ch === $quote && ( $i < 1 || '\\' !== $css[ $i - 1 ] ) ) {
				$quote = '';
			}
			continue;
		}
		if ( '"' === $ch || "'" === $ch ) {
			$quote = $ch;
			continue;
		}
		if ( '{' === $ch ) {
			if ( 0 === $depth ) {
				$open = $i;
			}
			++$depth;
			continue;
		}
		if ( '}' === $ch ) {
			--$depth;
			if ( 0 === $depth && -1 !== $open ) {
				return array( $open, $i );
			}
		}
	}

	return array( -1, -1 );
}

/**
 * Drop @keyframes nothing kept refers to. Pure.
 *
 * Run after the reduction, because whether an animation is used is a property of the rules that
 * survived, not of the document.
 *
 * @since 0.1.0
 * @param string $css Reduced stylesheet.
 * @return string
 */
function wpoptimizing_ccss_prune_keyframes( $css ) {
	$css = (string) $css;
	if ( false === stripos( $css, '@keyframes' ) ) {
		return $css;
	}

	// What the surviving declarations animate. Reading `animation:` shorthand as well as
	// `animation-name:` because most stylesheets use the shorthand.
	$used = array();
	if ( preg_match_all( '/animation(?:-name)?\s*:([^;}]+)/i', $css, $m ) ) {
		foreach ( $m[1] as $value ) {
			foreach ( preg_split( '/[\s,]+/', trim( $value ) ) as $word ) {
				$word = trim( $word );
				if ( '' !== $word ) {
					$used[ strtolower( $word ) ] = true;
				}
			}
		}
	}

	$marked = preg_replace_callback(
		'/@(-[a-z]+-)?keyframes\s+("[^"]*"|\'[^\']*\'|[^\s{]+)\s*\{/i',
		static function ( $m ) use ( $used ) {
			$name = strtolower( trim( $m[2], "\"'" ) );
			// The marker is a byte no stylesheet contains, so the second pass can find the
			// block boundaries with the same brace walker the reducer uses.
			return isset( $used[ $name ] ) ? $m[0] : "\0DROP\0" . $m[0];
		},
		$css
	);

	// A failed match means something about this stylesheet defeated the pattern. Keep all of
	// it: an animation that never plays costs a few hundred bytes, one that is missing is a
	// visible defect.
	return null === $marked ? $css : wpoptimizing_ccss_drop_marked_blocks( $marked );
}

/**
 * Remove every block whose at-rule was marked for dropping. Pure.
 *
 * @since 0.1.0
 * @param string $css Marked stylesheet.
 * @return string
 */
function wpoptimizing_ccss_drop_marked_blocks( $css ) {
	$out = '';
	$i   = 0;
	$len = strlen( $css );

	while ( $i < $len ) {
		$at = strpos( $css, "\0DROP\0", $i );
		if ( false === $at ) {
			$out .= substr( $css, $i );
			break;
		}
		$out .= substr( $css, $i, $at - $i );

		$block = wpoptimizing_ccss_find_block( $css, $at + 6 );
		if ( -1 === $block[0] ) {
			break;
		}
		$i = $block[1] + 1;
	}

	return $out;
}

/**
 * Drop the custom properties (--name: value) nothing kept uses. Pure.
 *
 * Avada declares about 900 of them on :root, and the reduction keeps :root whole: 23 KB of the
 * 180 KB of critical CSS inlined on artprojectgroup.es, more than half never read by any rule that
 * survived (10/11/2026). A property is used when a var() outside the declarations names it, or
 * when a used property's value does (followed to the end). The full stylesheets still define
 * every one of them as soon as they load, so a script that reads one later finds it.
 *
 * A declaration whose value does not close its quotes or brackets is not touched: it may hold a
 * «;» inside, and then where it ends is not known; its name counts as used.
 *
 * @since 0.1.0
 * @param string $css Reduced stylesheet.
 * @return string
 */
function wpoptimizing_ccss_prune_custom_properties( $css ) {
	$css = (string) $css;
	if ( false === strpos( $css, '--' ) ) {
		return $css;
	}
	$pattern = '/(?<=[{;])(\s*)(--[A-Za-z0-9_-]+)\s*:([^;{}]*)(;|(?=\}))/';
	if ( ! preg_match_all( $pattern, $css, $all, PREG_SET_ORDER ) ) {
		return $css;
	}
	$defs = array();
	$used = array();
	foreach ( $all as $d ) {
		$value = $d[3];
		$sound = substr_count( $value, '(' ) === substr_count( $value, ')' ) && 0 === substr_count( $value, '"' ) % 2 && 0 === substr_count( $value, "'" ) % 2;
		if ( ! $sound ) {
			$used[ $d[2] ] = true;
		}
		$defs[ $d[2] ][] = $value;
	}
	$outside = (string) preg_replace( $pattern, '$1', $css );
	if ( preg_match_all( '/var\(\s*(--[A-Za-z0-9_-]+)/', $outside, $m ) ) {
		foreach ( $m[1] as $name ) {
			$used[ $name ] = true;
		}
	}
	$queue = array_keys( $used );
	while ( $queue ) {
		$name = array_pop( $queue );
		foreach ( isset( $defs[ $name ] ) ? $defs[ $name ] : array() as $value ) {
			if ( preg_match_all( '/var\(\s*(--[A-Za-z0-9_-]+)/', $value, $m ) ) {
				foreach ( $m[1] as $ref ) {
					if ( ! isset( $used[ $ref ] ) ) {
						$used[ $ref ] = true;
						$queue[]      = $ref;
					}
				}
			}
		}
	}
	$css = (string) preg_replace_callback(
		$pattern,
		static function ( $d ) use ( $used ) {
			return isset( $used[ $d[2] ] ) ? $d[0] : $d[1];
		},
		$css
	);
	return wpoptimizing_ccss_drop_empty_rules( $css );
}

/**
 * Drop the @font-face rules of families nothing else in the stylesheet names. Pure.
 *
 * Run after wpoptimizing_ccss_prune_custom_properties(): a family named only by a property that
 * went is not used either. Whether it is named is a plain search for the family's name in the
 * rest of the stylesheet, without case: a name that also appears inside a class or a URL keeps
 * its font, which costs a few bytes and never a missing glyph.
 *
 * @since 0.1.0
 * @param string $css Reduced stylesheet.
 * @return string
 */
function wpoptimizing_ccss_prune_font_faces( $css ) {
	$css = (string) $css;
	if ( false === stripos( $css, '@font-face' ) ) {
		return $css;
	}
	$rest   = strtolower( (string) preg_replace( '/@font-face\s*\{[^{}]*\}/i', '', $css ) );
	$marked = (string) preg_replace_callback(
		'/@font-face\s*\{[^{}]*\}/i',
		static function ( $m ) use ( $rest ) {
			if ( ! preg_match( '/font-family\s*:\s*(?:"([^"]+)"|\'([^\']+)\'|([^;}]+))/i', $m[0], $f ) ) {
				return $m[0];
			}
			$family = strtolower( trim( '' !== $f[1] ? $f[1] : ( isset( $f[2] ) && '' !== $f[2] ? $f[2] : ( isset( $f[3] ) ? $f[3] : '' ) ) ) );
			return '' === $family || false !== strpos( $rest, $family ) ? $m[0] : '';
		},
		$css
	);
	return $marked;
}

/**
 * Remove rules left with no declarations, and at-rules left with no rules. Pure.
 *
 * @since 0.1.0
 * @param string $css Stylesheet.
 * @return string
 */
function wpoptimizing_ccss_drop_empty_rules( $css ) {
	$css = (string) $css;
	for ( $pass = 0; $pass < 3; $pass++ ) {
		$next = (string) preg_replace( '/(^|[{};])\s*[^{};@]+\{\s*\}/', '$1', $css );
		$next = (string) preg_replace( '/(^|[{};])\s*@(?:media|supports|container|layer)[^{};]*\{\s*\}/i', '$1', $next );
		if ( $next === $css ) {
			break;
		}
		$css = $next;
	}
	return $css;
}

/**
 * Relative url()s of a stylesheet, made absolute from the sheet's own address. Pure.
 *
 * Inlined in the page, «url(fonts/brand.woff)» resolves against the page, not against the
 * folder of the sheet it came from: on emiliocalvo.es the critical CSS asked for four fonts at the
 * root of the site, all 404, which cost PageSpeed's best-practices points and the fonts
 * themselves (09/11/2026). Absolute URLs, root-relative paths, data: URIs and fragments are left
 * alone.
 *
 * @since 0.1.0
 * @param string $css       Stylesheet source.
 * @param string $sheet_url Absolute URL of the stylesheet.
 * @return string
 */
function wpoptimizing_ccss_rebase_urls( $css, $sheet_url ) {
	$path = (string) wp_parse_url( (string) $sheet_url, PHP_URL_PATH );
	$base = substr( $path, 0, (int) strrpos( $path, '/' ) + 1 );
	if ( '' === $base ) {
		return (string) $css;
	}
	return (string) preg_replace_callback(
		'/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
		static function ( $m ) use ( $base ) {
			$ref = trim( $m[2] );
			if ( '' === $ref || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $ref ) ) {
				return $m[0];
			}
			$parts = array();
			foreach ( explode( '/', $base . $ref ) as $seg ) {
				if ( '..' === $seg ) {
					array_pop( $parts );
				} elseif ( '.' !== $seg ) {
					$parts[] = $seg;
				}
			}
			return 'url(' . $m[1] . implode( '/', $parts ) . $m[1] . ')';
		},
		(string) $css
	);
}

/**
 * The markup that can carry styles: no scripts, styles, templates or comments, and SVGs kept as
 * their own tag (its classes, and «svg» itself, which icon sizing rules select) without the
 * drawing inside. Pure.
 *
 * Dropping SVGs whole lost the rules that size icons: an icon drawn at its intrinsic size until
 * the stylesheet arrived (10/11/2026).
 *
 * @since 0.1.0
 * @param string $html Markup.
 * @return string
 */
function wpoptimizing_ccss_lean_html( $html ) {
	$html = (string) preg_replace( '#<(script|style|noscript|template)\b.*?</\1>#is', '', (string) $html );
	$html = (string) preg_replace( '#(<svg\b[^>]*>).*?</svg>#is', '$1</svg>', $html );
	return (string) preg_replace( '#<!--.*?-->#s', '', $html );
}

/**
 * The part of the document the first screen is made of. Pure.
 *
 * Without a browser there is no fold to measure, so it is approximated by position: the head
 * and the start of the body, after dropping what paints nothing (scripts, styles, SVG, templates,
 * comments). On an Avada front page the header and the slider end 10 KB into that body and
 * #main starts at 11 KB (emiliocalvo.es, 09/11/2026); the default 24 KB leaves room for long
 * menus. What lies further down gets its rules from the full stylesheets, which keep loading
 * asynchronously and arrive before anybody scrolls there.
 *
 * @since 0.1.0
 * @param string $html  Rendered page.
 * @param int    $bytes Body bytes to keep.
 * @return string
 */
function wpoptimizing_ccss_fold_html( $html, $bytes = 24000 ) {
	$html = wpoptimizing_ccss_lean_html( $html );
	$body = stripos( $html, '<body' );
	if ( false === $body || $bytes <= 0 ) {
		return $html;
	}
	// Counted from where the content starts, not from <body>: everything before it (the header,
	// whatever its size) is on the first screen anyway. A header with a long menu and its hidden
	// submenus took the whole allowance on artprojectgroup.com, the front page's hero was left
	// out, and it painted unstyled until the stylesheets arrived (10/11/2026).
	$from = $body;
	if ( preg_match( '#<main\b|<[a-z0-9]+\b[^>]*\b(?:id=["\'](?:main|content|primary)["\']|role=["\']main["\'])#i', $html, $m, PREG_OFFSET_CAPTURE, $body ) ) {
		$from = (int) $m[0][1];
	}
	return substr( $html, 0, $from + (int) $bytes );
}

/**
 * The markup of every element pinned to the screen, with everything inside it. Pure.
 *
 * The fold is a position in the HTML, and a fixed or sticky element ignores it: a cookie banner
 * printed at the end of the body is painted over the first screen. Without its «position: fixed»
 * the banner sat in the flow while its stylesheet loaded: on emiliocalvo.es Complianz moved it to
 * the top of <body>, it pushed the page 733 px down and everything jumped back, a CLS of 0.244.
 * And the rule alone was not enough: the floating share button then showed its hidden panel and an
 * 800 px ChatGPT icon until its sheet arrived (10/11/2026). So it is the element's whole subtree
 * that joins the first screen, and every rule of what is inside it comes along.
 *
 * Only top-level rules mark an element as pinned: one inside a media query does not.
 *
 * Except an element its own pinned rules keep out of sight (wpoptimizing_ccss_pinned_hidden()): a
 * side panel parked off screen until a button opens it. Of that one only its own tag joins, so it
 * stays hidden; what is inside it paints nothing on the first screen. OneTap's accessibility panel
 * (right: -580px) brought 27 KB of markup and its whole stylesheet into the critical CSS of
 * artprojectgroup.es (10/11/2026). A cookie banner shown at load is not hidden by its rules, and
 * still comes whole.
 *
 * @since 0.1.0
 * @param string $css  Concatenated stylesheets.
 * @param string $html Whole page.
 * @return string Markup to add to the first screen ('' when nothing is pinned).
 */
function wpoptimizing_ccss_pinned_markup( $css, $html ) {
	$lean    = wpoptimizing_ccss_lean_html( (string) $html );
	$reduced = wpoptimizing_ccss_reduce( (string) $css, wpoptimizing_ccss_document_tokens( $lean ) );
	$hooks   = array();
	$depth   = 0;
	$start   = 0;
	$open    = 0;
	$len     = strlen( $reduced );
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $reduced[ $i ];
		if ( '{' === $ch ) {
			if ( 0 === $depth ) {
				$open = $i;
			}
			++$depth;
		} elseif ( '}' === $ch && $depth > 0 ) {
			--$depth;
			if ( 0 !== $depth ) {
				continue;
			}
			$prelude = trim( substr( $reduced, $start, $open - $start ) );
			$body    = substr( $reduced, $open + 1, $i - $open - 1 );
			$start   = $i + 1;
			if ( '' === $prelude || '@' === $prelude[0] || false !== strpos( $body, '{' ) || ! preg_match( '/(^|;)\s*position\s*:\s*(fixed|sticky)\b/i', $body ) ) {
				continue;
			}
			$hidden = wpoptimizing_ccss_pinned_hidden( $body );
			// What names the pinned element: the class or id of the last compound of each selector.
			// Hidden only while every pinned rule naming it hides it.
			foreach ( wpoptimizing_ccss_split_selectors( $prelude ) as $selector ) {
				$parts = preg_split( '/\s*[>+~]\s*|\s+/', trim( (string) preg_replace( '/::?[a-z-]+(\([^)]*\))?/i', '', $selector ) ) );
				$last  = (string) end( $parts );
				$key   = '';
				if ( preg_match( '/#(-?[A-Za-z_][A-Za-z0-9_-]*)/', $last, $m ) ) {
					$key = 'id:' . $m[1];
				} elseif ( preg_match( '/\.(-?[A-Za-z_][A-Za-z0-9_-]*)/', $last, $m ) ) {
					$key = 'class:' . $m[1];
				}
				if ( '' !== $key ) {
					$hooks[ $key ] = isset( $hooks[ $key ] ) ? $hooks[ $key ] && $hidden : $hidden;
				}
			}
		}
	}

	$out = array();
	foreach ( $hooks as $hook => $hidden ) {
		list( $kind, $name ) = explode( ':', $hook, 2 );
		$attr                = 'id' === $kind
			? '\bid\s*=\s*(["\'])' . preg_quote( $name, '#' ) . '\2'
			: '\bclass\s*=\s*(["\'])(?:[^"\']*\s)?' . preg_quote( $name, '#' ) . '(?:\s[^"\']*)?\2';
		if ( preg_match_all( '#<([a-zA-Z][a-zA-Z0-9-]*)\b[^>]*' . $attr . '[^>]*>#i', $lean, $found, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $found[0] as $k => $tag ) {
				$name = strtolower( $found[1][ $k ][0] );
				$sub  = $hidden ? $tag[0] . '</' . $name . '>' : wpoptimizing_ccss_subtree( $lean, (int) $tag[1], $name );
				if ( '' !== $sub ) {
					$out[ md5( $sub ) ] = $sub;
				}
			}
		}
	}
	if ( $out ) {
		foreach ( wpoptimizing_ccss_pinned_state_markup( $css, array_keys( $hooks ) ) as $tag ) {
			$out[ md5( $tag ) ] = $tag;
		}
	}
	return implode( "\n", $out );
}

/**
 * Empty tags carrying the classes a script adds to hide a pinned element. Pure.
 *
 * The page is read as the server prints it, and some banners are hidden afterwards by a class
 * their script adds: Complianz adds «cmplz-dismissed» when consent was already given, and only
 * «.cmplz-cookiebanner.cmplz-dismissed{display:none}» hides it. That class is in no HTML, so the
 * rule was left out, and with the banner's stylesheet loading asynchronously every visitor who
 * had already accepted saw it over the first screen until the sheet arrived (artprojectgroup.es,
 * 11/11/2026). A rule that hides a pinned element under one more class is part of its first paint.
 *
 * @since 0.1.0
 * @param string   $css   Concatenated stylesheets.
 * @param string[] $hooks Pinned elements, as 'class:name' or 'id:name'.
 * @return string[] One tag per element and hiding state.
 */
function wpoptimizing_ccss_pinned_state_markup( $css, array $hooks ) {
	$css   = wpoptimizing_ccss_strip_comments( (string) $css );
	$tags  = array();
	$depth = 0;
	$start = 0;
	$open  = 0;
	$len   = strlen( $css );
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $css[ $i ];
		if ( '{' === $ch ) {
			if ( 0 === $depth ) {
				$open = $i;
			}
			++$depth;
		} elseif ( '}' === $ch && $depth > 0 ) {
			--$depth;
			if ( 0 !== $depth ) {
				continue;
			}
			$prelude = trim( substr( $css, $start, $open - $start ) );
			$body    = substr( $css, $open + 1, $i - $open - 1 );
			$start   = $i + 1;
			if ( '' === $prelude || '@' === $prelude[0] || ! preg_match( '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden)/i', $body ) ) {
				continue;
			}
			foreach ( wpoptimizing_ccss_split_selectors( $prelude ) as $selector ) {
				// Only a single compound: «.banner.state», not «.state .banner» nor a pseudo-class.
				$selector = trim( (string) $selector );
				if ( ! preg_match( '/^(?:[#.]-?[A-Za-z_][A-Za-z0-9_-]*){2,}$/', $selector ) ) {
					continue;
				}
				preg_match_all( '/([#.])(-?[A-Za-z_][A-Za-z0-9_-]*)/', $selector, $m, PREG_SET_ORDER );
				$ids     = array();
				$classes = array();
				foreach ( $m as $part ) {
					if ( '#' === $part[1] ) {
						$ids[] = $part[2];
					} else {
						$classes[] = $part[2];
					}
				}
				foreach ( $hooks as $hook ) {
					list( $kind, $name ) = explode( ':', (string) $hook, 2 );
					if ( 'id' === $kind ? array( $name ) === $ids : ( ! $ids && in_array( $name, $classes, true ) ) ) {
						$attr                       = $ids ? ' id="' . $ids[0] . '"' : '';
						$tag                        = '<div' . $attr . ' class="' . implode( ' ', $classes ) . '"></div>';
						$tags[ strtolower( $tag ) ] = $tag;
					}
				}
			}
		}
	}
	return array_values( $tags );
}

/**
 * An element with everything inside it, from the position of its opening tag. Pure.
 *
 * @since 0.1.0
 * @param string $html Markup.
 * @param int    $at   Offset of the opening tag.
 * @param string $tag  Its tag name.
 * @return string
 */
function wpoptimizing_ccss_subtree( $html, $at, $tag ) {
	$gt = strpos( $html, '>', $at );
	if ( false === $gt ) {
		return '';
	}
	if ( '/' === substr( rtrim( substr( $html, $at, $gt - $at ) ), -1 ) || in_array( $tag, array( 'img', 'input', 'br', 'hr', 'meta', 'link', 'source' ), true ) ) {
		return substr( $html, $at, $gt - $at + 1 );
	}
	$depth = 0;
	if ( ! preg_match_all( '#<(/?)' . preg_quote( $tag, '#' ) . '\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $at ) ) {
		return '';
	}
	foreach ( $m[0] as $k => $t ) {
		$depth += '/' === $m[1][ $k ][0] ? -1 : 1;
		if ( 0 === $depth ) {
			return substr( $html, $at, (int) $t[1] + strlen( $t[0] ) - $at );
		}
	}
	return substr( $html, $at, 20000 ); // Unclosed: take a bounded piece rather than nothing.
}

/**
 * Does a pinned rule keep its element out of sight? Not displayed, invisible, transparent, moved
 * a whole box off screen (translate by 100%) or parked at least 300 px past an edge. Pure.
 *
 * @since 0.1.0
 * @param string $body Declarations of the rule.
 * @return bool
 */
function wpoptimizing_ccss_pinned_hidden( $body ) {
	$body = (string) $body;
	return 1 === preg_match( '/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*hidden|opacity\s*:\s*0(?:\.0*)?\s*(?:!important\s*)?(?:;|$))/i', $body )
		|| 1 === preg_match( '/(?:^|;)\s*transform\s*:\s*translate(?:x|y|3d)?\(\s*-?100%/i', $body )
		|| 1 === preg_match( '/(?:^|;)\s*(?:left|right|top|bottom)\s*:\s*-\s*(?:[3-9]\d{2}|\d{4,})(?:\.\d+)?px/i', $body );
}

/**
 * The whole extraction for one document. Pure.
 *
 * @since 0.1.0
 * @param string $css  Concatenated stylesheets.
 * @param string $html Rendered page.
 * @return string
 */
function wpoptimizing_ccss_extract( $css, $html ) {
	$tokens  = wpoptimizing_ccss_document_tokens( $html );
	$reduced = wpoptimizing_ccss_reduce( $css, $tokens );
	$reduced = wpoptimizing_ccss_prune_keyframes( $reduced );
	$reduced = wpoptimizing_ccss_prune_custom_properties( $reduced );
	return trim( wpoptimizing_ccss_prune_font_faces( $reduced ) );
}
