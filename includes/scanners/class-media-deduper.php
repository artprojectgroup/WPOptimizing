<?php
/**
 * Duplicate-media deduplication: keep one attachment and reassign references.
 *
 * "Safe" reassignment scope: featured image (_thumbnail_id) and post_content
 * (full + sized URLs, the wp-image-<ID> class, core gallery shortcode ids and
 * Gutenberg image/gallery id attributes). The content-rewriting logic is pure so
 * it can be unit-tested.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

class WPOPT_Media_Deduper {

	/**
	 * Directory portion of a URL (no trailing slash). Pure.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_dir( $url ) {
		$url = (string) $url;
		$pos = strrpos( $url, '/' );
		return false === $pos ? '' : substr( $url, 0, $pos );
	}

	/**
	 * Build the URL replacement map (duplicate URL → kept URL), full size plus
	 * each generated size and the un-scaled original. Pure.
	 *
	 * @param string $dupe_url  Duplicate full-size URL.
	 * @param string $keep_url  Kept full-size URL.
	 * @param array  $dupe_meta Duplicate _wp_attachment_metadata.
	 * @param array  $keep_meta Kept _wp_attachment_metadata.
	 * @return array<string,string>
	 */
	public static function build_replacement_map( $dupe_url, $keep_url, array $dupe_meta, array $keep_meta ) {
		$map      = array();
		$dupe_url = (string) $dupe_url;
		$keep_url = (string) $keep_url;
		if ( '' === $dupe_url || '' === $keep_url || $dupe_url === $keep_url ) {
			return $map;
		}

		$map[ $dupe_url ] = $keep_url;

		$dupe_dir   = self::url_dir( $dupe_url );
		$keep_dir   = self::url_dir( $keep_url );
		$dupe_sizes = isset( $dupe_meta['sizes'] ) && is_array( $dupe_meta['sizes'] ) ? $dupe_meta['sizes'] : array();
		$keep_sizes = isset( $keep_meta['sizes'] ) && is_array( $keep_meta['sizes'] ) ? $keep_meta['sizes'] : array();

		foreach ( $dupe_sizes as $name => $sz ) {
			if ( empty( $sz['file'] ) ) {
				continue;
			}
			$from         = $dupe_dir . '/' . $sz['file'];
			$to           = ( isset( $keep_sizes[ $name ]['file'] ) && '' !== $keep_dir )
				? $keep_dir . '/' . $keep_sizes[ $name ]['file']
				: $keep_url;
			$map[ $from ] = $to;
		}

		if ( ! empty( $dupe_meta['original_image'] ) ) {
			$from         = $dupe_dir . '/' . $dupe_meta['original_image'];
			$map[ $from ] = ! empty( $keep_meta['original_image'] ) ? $keep_dir . '/' . $keep_meta['original_image'] : $keep_url;
		}

		return $map;
	}

	/**
	 * Result of one rewrite step, or the untouched input when PCRE gave up.
	 *
	 * Every step in rewrite_content() goes through here instead of casting with (string).
	 * preg_* returns null on failure and (string) null is '', which rewrite_content()'s
	 * caller would then save over a real post_content. None of the current patterns can
	 * actually fail (PCRE2 auto-possessifies all of them, so they cannot reach the
	 * backtrack limit), so this is insurance rather than a live bug fix: it means editing
	 * a pattern here can never turn into silent data loss. A failed step leaves the content
	 * as it was and the duplicate simply stays referenced for the next run to retry.
	 *
	 * @param string|null $result   What preg_replace()/preg_replace_callback() returned.
	 * @param string      $fallback Content to keep when it returned null.
	 * @return string
	 */
	public static function safe_rewrite( $result, $fallback ) {
		return ( null === $result ) ? (string) $fallback : (string) $result;
	}

	/**
	 * Rewrite post content: apply the URL map, the wp-image-<id> class, and
	 * gallery / Gutenberg id references. Pure.
	 *
	 * @param string $content Post content.
	 * @param array  $map     URL replacement map (from build_replacement_map).
	 * @param int    $dupe_id Duplicate attachment id.
	 * @param int    $keep_id Kept attachment id.
	 * @return string
	 */
	public static function rewrite_content( $content, array $map, $dupe_id, $keep_id ) {
		$content = (string) $content;
		$dupe_id = (int) $dupe_id;
		$keep_id = (int) $keep_id;

		// Apply URL replacements, longest key first to avoid partial overlaps.
		uksort(
			$map,
			static function ( $a, $b ) {
				return strlen( (string) $b ) - strlen( (string) $a );
			}
		);
		foreach ( $map as $from => $to ) {
			if ( '' !== (string) $from ) {
				$content = str_replace( (string) $from, (string) $to, $content );
			}
		}

		if ( $dupe_id <= 0 || $keep_id <= 0 || $dupe_id === $keep_id ) {
			return $content;
		}

		// CSS class on <img>: wp-image-<id>.
		$content = self::safe_rewrite( preg_replace( '/wp-image-' . $dupe_id . '(?=\D|$)/', 'wp-image-' . $keep_id, $content ), $content );

		// Gutenberg single image block: "id":<dupe>.
		$content = self::safe_rewrite( preg_replace( '/("id"\s*:\s*)' . $dupe_id . '(?=\D|$)/', '${1}' . $keep_id, $content ), $content );

		// Replace the duplicate id inside comma-separated id lists.
		$list_cb = static function ( $list ) use ( $dupe_id, $keep_id ) {
			$ids = array_map( 'trim', explode( ',', (string) $list ) );
			foreach ( $ids as &$id ) {
				if ( (string) $dupe_id === $id ) {
					$id = (string) $keep_id;
				}
			}
			unset( $id );
			return implode( ',', $ids );
		};
		// Gallery shortcode: ids="1,2,3".
		$content = self::safe_rewrite(
			preg_replace_callback(
				'/\bids="([0-9,\s]*)"/',
				static function ( $m ) use ( $list_cb ) {
					return 'ids="' . $list_cb( $m[1] ) . '"';
				},
				$content
			),
			$content
		);
		// Gutenberg gallery: "ids":[1,2,3].
		$content = self::safe_rewrite(
			preg_replace_callback(
				'/("ids"\s*:\s*\[)([0-9,\s]*)(\])/',
				static function ( $m ) use ( $list_cb ) {
					return $m[1] . $list_cb( $m[2] ) . $m[3];
				},
				$content
			),
			$content
		);

		return $content;
	}

	/**
	 * All files of an attachment (original + generated sizes + scaled original),
	 * relative to the uploads basedir.
	 *
	 * @param int $att_id Attachment id.
	 * @return string[]
	 */
	public static function attachment_files_relative( $att_id ) {
		$att_id = (int) $att_id;
		return self::files_from_meta(
			(string) get_post_meta( $att_id, '_wp_attached_file', true ),
			wp_get_attachment_metadata( $att_id ),
			get_post_meta( $att_id, '_wp_attachment_backup_sizes', true )
		);
	}

	/**
	 * Every file deleting an attachment can take with it, relative to the uploads folder. Pure.
	 *
	 * The backup used to cover the attached file, original_image and the sizes, while
	 * wp_delete_attachment() also removes the pre-edit originals in
	 * _wp_attachment_backup_sizes and the companions (source_image…), and this plugin's
	 * wp_delete_file filter removes the .webp/.avif copies next to each raster. Anything the
	 * delete takes and the backup did not was simply gone. Candidates that do not exist on disk
	 * are harmless: the backup only counts files it finds.
	 *
	 * @since 0.1.0
	 * @param string $file         _wp_attached_file.
	 * @param mixed  $meta         _wp_attachment_metadata.
	 * @param mixed  $backup_sizes _wp_attachment_backup_sizes.
	 * @return string[]
	 */
	public static function files_from_meta( $file, $meta, $backup_sizes = array() ) {
		$file = ltrim( str_replace( '\\', '/', (string) $file ), '/' );
		if ( '' === $file ) {
			return array();
		}
		$dir   = ( false !== strpos( $file, '/' ) ) ? substr( $file, 0, strrpos( $file, '/' ) + 1 ) : '';
		$names = array();
		if ( is_array( $meta ) ) {
			$names = function_exists( 'wpoptimizing_attachment_companion_files' )
				? wpoptimizing_attachment_companion_files( $meta )
				: ( empty( $meta['original_image'] ) ? array() : array( (string) $meta['original_image'] ) );
			if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
				foreach ( $meta['sizes'] as $sz ) {
					if ( ! empty( $sz['file'] ) && is_string( $sz['file'] ) ) {
						$names[] = $sz['file'];
					}
				}
			}
		}
		if ( is_array( $backup_sizes ) ) {
			foreach ( $backup_sizes as $sz ) {
				if ( is_array( $sz ) && ! empty( $sz['file'] ) && is_string( $sz['file'] ) ) {
					$names[] = $sz['file'];
				}
			}
		}

		$out = array( $file );
		foreach ( $names as $name ) {
			$out[] = $dir . basename( str_replace( '\\', '/', (string) $name ) );
		}
		foreach ( $out as $rel ) {
			if ( preg_match( '/\.(jpe?g|png|gif)$/i', $rel ) ) {
				$out[] = $rel . '.webp';
				$out[] = $rel . '.avif';
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Whether a duplicate set may be merged at all. Pure.
	 *
	 * Two records on one file are one image, not two (deleting either breaks the other's
	 * page), and WPML translations of an attachment are per-language records of one item —
	 * WPML Media copies share the file outright. Neither is a duplicate to clean.
	 *
	 * @since 0.1.0
	 * @param array<int,string> $files_by_id Member ID => _wp_attached_file (keep included).
	 * @param array<int,int>    $trid_by_id  Member ID => WPML translation group, where known.
	 * @return bool
	 */
	public static function group_is_mergeable( array $files_by_id, array $trid_by_id = array() ) {
		$seen = array();
		foreach ( $files_by_id as $file ) {
			$file = strtolower( ltrim( str_replace( '\\', '/', (string) $file ), '/' ) );
			if ( '' === $file || isset( $seen[ $file ] ) ) {
				return false;
			}
			$seen[ $file ] = true;
		}
		$trids = array();
		foreach ( array_intersect_key( $trid_by_id, $files_by_id ) as $trid ) {
			$trid = (int) $trid;
			if ( $trid > 0 && isset( $trids[ $trid ] ) ) {
				return false;
			}
			$trids[ $trid ] = true;
		}
		return count( $files_by_id ) >= 2;
	}

	/**
	 * Reassign all references from $dupe_ids to $keep_id and delete the duplicates.
	 *
	 * Callers should back up the duplicate files first. Reassignment covers the
	 * "Safe" scope (featured image + post_content). Returns operation stats.
	 *
	 * The admin batch does NOT use this: it runs reassign_group() for every set, checks what
	 * still points at each duplicate, and only then calls remove_duplicates(). Deleting right
	 * after the rewrite is safe only when nothing outside that scope references the duplicate.
	 *
	 * @param int   $keep_id   Attachment to keep.
	 * @param int[] $dupe_ids  Duplicate attachments to merge into the kept one.
	 * @return array{deleted:int,reassigned_posts:int,reassigned_thumbnails:int}
	 */
	public function dedupe_group( $keep_id, array $dupe_ids ) {
		return array_merge( $this->reassign_group( $keep_id, $dupe_ids ), $this->remove_duplicates( $keep_id, $dupe_ids ) );
	}

	/**
	 * Point the "Safe" scope (featured images + post_content) of each duplicate at the kept
	 * attachment. Deletes nothing: both images are byte-identical, so the rewrite on its own
	 * changes nothing a visitor can see.
	 *
	 * Every post whose content is rewritten goes into the backup first, when one is given; a
	 * post that cannot be backed up is not rewritten (it then still points at the duplicate,
	 * and the caller's reference check keeps that set).
	 *
	 * @param int                             $keep_id  Attachment to keep.
	 * @param int[]                           $dupe_ids Duplicates.
	 * @param WPOptimizing_Backup_Writer|null $backup   Backup of the operation.
	 * @return array{reassigned_posts:int,reassigned_thumbnails:int}
	 */
	public function reassign_group( $keep_id, array $dupe_ids, $backup = null ) {
		global $wpdb;
		$keep_id = (int) $keep_id;
		$stats   = array(
			'reassigned_posts'      => 0,
			'reassigned_thumbnails' => 0,
		);
		if ( $keep_id <= 0 || 'attachment' !== get_post_type( $keep_id ) ) {
			return $stats;
		}

		$keep_url  = (string) wp_get_attachment_url( $keep_id );
		$keep_meta = wp_get_attachment_metadata( $keep_id );
		$keep_meta = is_array( $keep_meta ) ? $keep_meta : array();

		foreach ( $dupe_ids as $dupe_id ) {
			$dupe_id = (int) $dupe_id;
			if ( $dupe_id <= 0 || $dupe_id === $keep_id || 'attachment' !== get_post_type( $dupe_id ) ) {
				continue;
			}

			$dupe_url  = (string) wp_get_attachment_url( $dupe_id );
			$dupe_meta = wp_get_attachment_metadata( $dupe_id );
			$dupe_meta = is_array( $dupe_meta ) ? $dupe_meta : array();

			// 1) Featured image references.
			$thumb_posts = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d", $dupe_id )
			);
			foreach ( (array) $thumb_posts as $pid ) {
				update_post_meta( (int) $pid, '_thumbnail_id', $keep_id );
				++$stats['reassigned_thumbnails'];
			}

			// 2) post_content references.
			$map      = self::build_replacement_map( $dupe_url, $keep_url, $dupe_meta, $keep_meta );
			$like_url = '%' . $wpdb->esc_like( $dupe_url ) . '%';
			$like_cls = '%' . $wpdb->esc_like( 'wp-image-' . $dupe_id ) . '%';
			$like_id  = '%' . $wpdb->esc_like( '"id":' . $dupe_id ) . '%';
			$post_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status NOT IN ('trash','auto-draft') AND ( post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s )",
					$like_url,
					$like_cls,
					$like_id
				)
			);
			foreach ( (array) $post_ids as $pid ) {
				$post = get_post( (int) $pid );
				if ( ! $post ) {
					continue;
				}
				$new = self::rewrite_content( $post->post_content, $map, $dupe_id, $keep_id );
				// Belt and braces around a destructive write: a rewrite only ever swaps one
				// attachment id for another, so it can never legitimately empty a post that
				// had content. If it somehow did, that is a bug in the rewriting, and saving
				// the result would destroy the post irreversibly.
				if ( '' === $new && '' !== (string) $post->post_content ) {
					continue;
				}
				if ( $new !== $post->post_content ) {
					if ( null !== $backup && ! $backup->post_contents( array( (int) $pid ) ) ) {
						continue;
					}
					wp_update_post(
						array(
							'ID'           => (int) $pid,
							'post_content' => $new,
						)
					);
					++$stats['reassigned_posts'];
				}
			}
		}

		return $stats;
	}

	/**
	 * Delete the duplicates of a set. Callers back up first and check that nothing outside
	 * the reassigned scope still points at them.
	 *
	 * @param int   $keep_id  Attachment kept (never deleted).
	 * @param int[] $dupe_ids Duplicates.
	 * @return array{deleted:int,merged:int}
	 */
	public function remove_duplicates( $keep_id, array $dupe_ids ) {
		global $wpdb;
		$keep_id = (int) $keep_id;
		$stats   = array(
			'deleted' => 0,
			'merged'  => 0,
		);
		if ( $keep_id <= 0 || 'attachment' !== get_post_type( $keep_id ) ) {
			return $stats;
		}
		foreach ( $dupe_ids as $dupe_id ) {
			$dupe_id = (int) $dupe_id;
			if ( $dupe_id <= 0 || $dupe_id === $keep_id || 'attachment' !== get_post_type( $dupe_id ) ) {
				continue;
			}

			// Two cases:
			// (a) Its file is ALSO referenced by another attachment (the survivor or a
			// third record point to the same _wp_attached_file). This is a redundant
			// RECORD, not a redundant file. Delete only the post + meta and KEEP the
			// shared file — references were already reassigned above. wp_delete_file
			// is short-circuited so wp_delete_attachment cannot remove the file.
			// (b) A normal duplicate with its own file → delete file + sizes + post.
			$dupe_file = (string) get_post_meta( $dupe_id, '_wp_attached_file', true );
			$shared    = 0;
			if ( '' !== $dupe_file ) {
				$shared = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s AND post_id <> %d",
						$dupe_file,
						$dupe_id
					)
				);
			}
			if ( $shared > 0 ) {
				$keep_file_cb = static function () {
					return ''; // Empty path → wp_delete_file() unlinks nothing.
				};
				// The filter MUST come off again even if wp_delete_attachment() throws —
				// it runs third-party hooks (offloaders, CDNs), any of which can. Leaving it
				// registered would silently turn every later wp_delete_file() in the request
				// into a no-op, so an unrelated cleanup would report success and delete
				// nothing. Hence finally, not a plain call after the fact.
				add_filter( 'wp_delete_file', $keep_file_cb, 99 );
				try {
					$ok = wp_delete_attachment( $dupe_id, true );
				} finally {
					remove_filter( 'wp_delete_file', $keep_file_cb, 99 );
				}
				if ( $ok ) {
					++$stats['merged']; // Redundant record removed; shared file preserved.
				}
				continue;
			}
			if ( wp_delete_attachment( $dupe_id, true ) ) {
				++$stats['deleted'];
			}
		}

		return $stats;
	}
}
