<?php
/**
 * Cache side of a backup restore.
 *
 * Kept out of trait-backups.php so it can be loaded on its own: a trait file holds only the trait.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Make WordPress see the option rows a restore wrote straight into the table.
 *
 * The restore writes with $wpdb->replace(), which goes round the object cache. With a
 * persistent cache the site kept serving what it had cached after the deletion: measured on
 * 2026-10-12 with the plugin's own drop-in, the row was back in the table while get_option()
 * still returned false and get_role() still found nothing. Worse for roles: the next role
 * edit saves the stale in-memory copy over the restored row, so the restore is undone.
 *
 * @param string[] $names Option names that were written.
 * @return void
 */
function wpoptimizing_forget_restored_options( array $names ) {
	if ( empty( $names ) || ! function_exists( 'wp_cache_delete' ) ) {
		return;
	}
	foreach ( array_unique( $names ) as $name ) {
		wp_cache_delete( $name, 'options' );
	}
	// Autoloaded rows are served from this one blob, and a deleted name sits in notoptions.
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );

	global $wpdb;
	if ( isset( $wpdb->prefix ) && in_array( $wpdb->prefix . 'user_roles', $names, true ) && function_exists( 'wp_roles' ) ) {
		wp_roles()->for_site(); // Re-read the roles now, so nothing later in this request saves the old set.
	}
}

/**
 * Make WordPress see the meta rows a restore wrote straight into the tables.
 *
 * Same cause as the options: $wpdb->replace() goes round the object cache, and with a
 * persistent cache every object kept serving the meta it had before the restore.
 *
 * @param array<string,int[]> $ids_by_group Cache group (post_meta|user_meta|term_meta|comment_meta) => object IDs.
 * @return void
 */
function wpoptimizing_forget_restored_meta( array $ids_by_group ) {
	if ( ! function_exists( 'wp_cache_delete' ) ) {
		return;
	}
	foreach ( $ids_by_group as $group => $ids ) {
		if ( ! in_array( $group, array( 'post_meta', 'user_meta', 'term_meta', 'comment_meta' ), true ) ) {
			continue;
		}
		foreach ( array_unique( array_filter( array_map( 'intval', (array) $ids ) ) ) as $id ) {
			wp_cache_delete( $id, $group );
		}
	}
}
