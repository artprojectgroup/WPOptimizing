<?php
/**
 * Slug/label matching helpers shared by extension-attribution (DB) and FS-orphan detection.
 *
 * Extracted from class-impact-cleaner.php / class-fs-orphans.php (identical copies)
 * into a shared trait. Methods rely on $this, so each composing class still
 * provides its own slug_to_patterns()/search_slug_code_for_tokens()/etc.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

trait WPOptimizing_Slug_Matching_Trait {

	/**
	 * Resolve slug origin for UI.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	protected function slug_origin( $slug ) {
		if ( isset( $this->installed_plugin_slugs[ $slug ] ) ) {
			return 'plugin';
		}
		if ( isset( $this->installed_theme_slugs[ $slug ] ) ) {
			return 'theme';
		}
		return '';
	}

	/**
	 * Build regex patterns for installed slugs.
	 *
	 * @param string[] $slugs Slugs.
	 * @return array<string,string[]>
	 */
	protected function build_installed_slug_patterns( array $slugs ) {
		$out = array();
		foreach ( $slugs as $slug ) {
			$slug = $this->normalize_slug_key( $slug );
			if ( '' === $slug ) {
				continue;
			}
			$patterns  = $this->slug_to_patterns( $slug );
			$orig_slug = $this->installed_slug_map[ $slug ] ?? $slug;
			$aliases   = array_merge(
				(array) ( $this->installed_slug_aliases[ $orig_slug ] ?? array() ),
				$this->build_aliases_from_slug( $orig_slug )
			);
			foreach ( $aliases as $alias ) {
				$alias = $this->normalize_slug_key( $alias );
				if ( '' !== $alias ) {
					$patterns = array_merge( $patterns, $this->slug_to_patterns( $alias ) );
				}
			}
			foreach ( array( 'wc-', 'woocommerce-' ) as $prefix ) {
				if ( 0 === strpos( $slug, $prefix ) ) {
					$short = substr( $slug, strlen( $prefix ) );
					if ( '' !== $short ) {
						$patterns = array_merge( $patterns, $this->slug_to_patterns( $short ) );
					}
				}
			}
			$out[ $slug ] = array_values( array_unique( $patterns ) );
		}
		return $out;
	}

	/**
	 * Normalize slug for map keys.
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	protected function normalize_slug_key( $slug ) {
		$slug = strtolower( (string) $slug );
		$slug = str_replace( '_', '-', $slug );
		return $slug;
	}

	/**
	 * Build installed slugs list (plugins + themes).
	 *
	 * @return string[]
	 */
	protected function get_installed_project_slugs() {
		$slugs = array();

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$all_plugins = function_exists( 'get_plugins' ) ? get_plugins() : array();
		foreach ( $all_plugins as $plugin_file => $_data ) {
			$slug                                  = ( false !== strpos( $plugin_file, '/' ) ) ? explode( '/', $plugin_file, 2 )[0] : basename( $plugin_file, '.php' );
			$slugs[ $slug ]                        = true;
			$this->installed_plugin_slugs[ $slug ] = true;
			$this->installed_slug_map[ $this->normalize_slug_key( $slug ) ] = $slug;
			$this->installed_slug_aliases[ $slug ]                          = $this->build_aliases_from_meta( $slug, $_data );
		}

		// Must-use plugins are installed plugins too (and always active).
		if ( function_exists( 'wpoptimizing_mu_plugin_slugs' ) ) {
			foreach ( array_keys( wpoptimizing_mu_plugin_slugs() ) as $slug ) {
				if ( ! isset( $slugs[ $slug ] ) ) {
					$slugs[ $slug ]                        = true;
					$this->installed_plugin_slugs[ $slug ] = true;
					$this->installed_slug_map[ $this->normalize_slug_key( $slug ) ] = $slug;
					$this->installed_slug_aliases[ $slug ]                          = array( $slug );
				}
			}
		}

		foreach ( wp_get_themes() as $theme_slug => $theme ) {
			$slugs[ $theme_slug ]                       = true;
			$this->installed_theme_slugs[ $theme_slug ] = true;
			$this->installed_slug_map[ $this->normalize_slug_key( $theme_slug ) ] = $theme_slug;
			$this->installed_slug_aliases[ $theme_slug ]                          = $this->build_aliases_from_theme( $theme_slug, $theme );
		}

		return array_keys( $slugs );
	}

	/**
	 * Build alias list for a plugin slug from plugin headers.
	 *
	 * @param string $slug Plugin slug.
	 * @param array  $data Plugin header data.
	 * @return string[]
	 */
	protected function build_aliases_from_meta( $slug, array $data ) {
		$aliases     = array( $slug );
		$text_domain = isset( $data['TextDomain'] ) ? (string) $data['TextDomain'] : '';
		if ( '' !== $text_domain ) {
			$aliases[] = $text_domain;
			$aliases[] = str_replace( '-', '_', $text_domain );
		}
		$name = isset( $data['Name'] ) ? (string) $data['Name'] : '';
		if ( '' !== $name ) {
			$slugified = $this->slugify_label( $name );
			$aliases[] = $slugified;
			$aliases[] = str_replace( '-', '_', $slugified );
			$aliases[] = $this->acronym_from_label( $name );
			$aliases   = array_merge( $aliases, $this->tokens_from_label( $name ) );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $aliases ) ) ) );
	}

	/**
	 * Build alias list for a theme slug from theme headers.
	 *
	 * @param string   $slug Theme slug.
	 * @param WP_Theme $theme Theme object.
	 * @return string[]
	 */
	protected function build_aliases_from_theme( $slug, $theme ) {
		$aliases = array( $slug );
		if ( $theme && method_exists( $theme, 'get' ) ) {
			$text_domain = (string) $theme->get( 'TextDomain' );
			if ( '' !== $text_domain ) {
				$aliases[] = $text_domain;
				$aliases[] = str_replace( '-', '_', $text_domain );
			}
			$name = (string) $theme->get( 'Name' );
			if ( '' !== $name ) {
				$slugified = $this->slugify_label( $name );
				$aliases[] = $slugified;
				$aliases[] = str_replace( '-', '_', $slugified );
				$aliases[] = $this->acronym_from_label( $name );
				$aliases   = array_merge( $aliases, $this->tokens_from_label( $name ) );
			}
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $aliases ) ) ) );
	}

	/**
	 * Slugify a human label.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	protected function slugify_label( $label ) {
		$label = strtolower( (string) $label );
		$label = preg_replace( '/[^a-z0-9]+/i', '-', $label );
		$label = trim( (string) $label, '-' );
		return $label;
	}

	/**
	 * Build an acronym from uppercase letters in a label.
	 *
	 * @param string $label Label.
	 * @return string
	 */
	protected function acronym_from_label( $label ) {
		if ( preg_match_all( '/[A-Z]/', (string) $label, $m ) ) {
			$acronym = strtolower( implode( '', $m[0] ) );
			if ( strlen( $acronym ) >= 3 ) {
				return $acronym;
			}
		}
		return '';
	}

	/**
	 * Build extra aliases from slug tokens.
	 *
	 * @param string $slug Slug.
	 * @return string[]
	 */
	protected function build_aliases_from_slug( $slug ) {
		$slug   = $this->normalize_slug_key( $slug );
		$tokens = array_filter( preg_split( '/[-_]+/', $slug ) );
		if ( empty( $tokens ) ) {
			return array();
		}
		$remove = array( 'wc', 'woocommerce', 'wp', 'wordpress', 'plugin', 'theme', 'extension' );
		$tokens = array_values(
			array_filter(
				$tokens,
				static function ( $t ) use ( $remove ) {
					return ( strlen( $t ) >= 3 ) && ! in_array( $t, $remove, true );
				}
			)
		);
		if ( empty( $tokens ) ) {
			return array();
		}

		return $this->multi_word_aliases( $tokens );
	}

	/**
	 * Aliases made of TWO OR MORE words of a name. Never a single word.
	 *
	 * A single word of a name is a dictionary word, and it became a pattern: `database-cleaner`
	 * produced `database`, and uploads/database was attributed to it; `cleaner`, `cache`, `pro`
	 * fragments did the same. The slug itself and the text domain still match on their own
	 * (build_installed_slug_patterns() adds them directly) — they are names, not words.
	 *
	 * @param string[] $tokens Words of the name, in order.
	 * @return string[]
	 */
	protected function multi_word_aliases( array $tokens ) {
		$tokens = array_values( $tokens );
		$n      = count( $tokens );
		if ( $n < 2 ) {
			return array();
		}
		$aliases = array(
			implode( '-', $tokens ),
			implode( '_', $tokens ),
			$tokens[0] . '-' . $tokens[1],
			$tokens[0] . '_' . $tokens[1],
			$tokens[0] . '-' . $tokens[ $n - 1 ],
			$tokens[0] . '_' . $tokens[ $n - 1 ],
		);
		return array_values( array_unique( array_filter( $aliases ) ) );
	}

	/**
	 * Extract meaningful tokens from a label.
	 *
	 * @param string $label Label.
	 * @return string[]
	 */
	protected function tokens_from_label( $label ) {
		$slugified = $this->slugify_label( $label );
		$tokens    = array_filter( preg_split( '/-+/', $slugified ) );
		$stop      = array( 'the', 'and', 'for', 'with', 'from', 'del', 'de', 'la', 'el', 'los', 'las', 'un', 'una', 'plugin', 'theme', 'woocommerce', 'wordpress' );
		$tokens    = array_values(
			array_filter(
				$tokens,
				static function ( $t ) use ( $stop ) {
					return ( strlen( $t ) >= 4 ) && ! in_array( $t, $stop, true );
				}
			)
		);
		return $this->multi_word_aliases( $tokens );
	}
}
