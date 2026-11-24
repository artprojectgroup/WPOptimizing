<?php
/**
 * Backup store: the one place that writes what a change is about to remove or overwrite.
 *
 * Rule (Emilio, 19/10/2026): every action of the plugin that changes or deletes site data in the
 * database, or changes or deletes a file, takes a backup first that the Backups tab can restore,
 * and when that backup cannot be written the action does not run. The plugin's own settings are
 * not site data and stay out.
 *
 * The admin traits already had a backup writer, but it lives inside WPOPT_Admin_UI, and the paths
 * that ran without a backup are exactly the ones that run without that class: the general
 * cleanup (from WP-Cron, WP-CLI, REST and the threshold auto-clean), the background sweeper, the
 * autoload helpers, the .htaccess and wp-config.php editors. So the low-level pieces moved here,
 * free of the admin class, and the trait delegates to them: one file format, one manifest, one
 * retention, one restore.
 *
 * File layout (unchanged, so every backup ever written still lists and restores):
 * - db-<type>-<op-key>.jsonl  one JSON record per line; restore_db_backup() reads it by 'type';
 * - db-file-<op-key>.php      same, for copies of files (wp-config.php, .htaccess): a .php whose
 *                             first line exits, so a server that ignores the folder's .htaccess
 *                             (nginx) runs that line and prints nothing;
 * - op-manifest-<op-key>.json what the operation holds and why (the Backups tab label).
 *
 * Engine-loaded; the few callers reachable without the engine pull it in through
 * wpoptimizing_load_lib(). Nothing here runs on a visitor request.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WPOPT_BACKUP_PHP_GUARD' ) ) {
	// First line of every db-file-*.php backup: it stops execution before any copied byte runs.
	define( 'WPOPT_BACKUP_PHP_GUARD', "<?php exit; // WPOptimizing backup, never executed. ?>\n" );
}

require_once __DIR__ . '/class-backup-writer.php';

/**
 * Create the backup folder and its access guards.
 *
 * @return bool
 */
function wpoptimizing_backup_ensure_dir() {
	if ( ! defined( 'WPOPT_BACKUP_DIR' ) || ! wp_mkdir_p( WPOPT_BACKUP_DIR ) ) {
		return false;
	}
	$index = WPOPT_BACKUP_DIR . '/index.php';
	if ( ! file_exists( $index ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $index, "<?php\n// Silence is golden.\n", LOCK_EX );
	}
	$htaccess = WPOPT_BACKUP_DIR . '/.htaccess';
	if ( ! file_exists( $htaccess ) ) {
		$rules = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n"
			. "<IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $htaccess, $rules, LOCK_EX );
	}
	return true;
}

/**
 * Append raw text to a backup file.
 *
 * @param string $path Absolute file path.
 * @param string $text Text to append.
 * @return bool Whether every byte was written.
 */
function wpoptimizing_backup_append( $path, $text ) {
	$text = (string) $text;
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	$written = @file_put_contents( $path, $text, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- the failure is reported through the return value.
	return false !== $written && strlen( $text ) === (int) $written;
}

/**
 * A fresh, filesystem-safe operation key. The timestamp keeps it sortable; the suffix avoids
 * collisions. A token (the async job token, or a fixed daily name) makes the batches of one job
 * share it.
 *
 * @param string $token Optional token.
 * @return string
 */
function wpoptimizing_backup_new_op_key( $token = '' ) {
	$token = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $token ) );
	if ( '' !== $token ) {
		return 'op-' . substr( $token, 0, 24 );
	}
	return 'op-' . gmdate( 'Ymd-His' ) . '-' . substr( md5( uniqid( '', true ) ), 0, 4 );
}

/**
 * Path of one component file of an operation.
 *
 * @param string $type Record family (file label).
 * @param string $key  Operation key (or legacy day).
 * @param string $ext  jsonl|php.
 * @return string
 */
function wpoptimizing_backup_db_path( $type, $key, $ext = 'jsonl' ) {
	return WPOPT_BACKUP_DIR . '/db-' . sanitize_key( (string) $type ) . '-' . (string) $key . '.' . ( 'php' === $ext ? 'php' : 'jsonl' );
}

/**
 * Regular expression of a database component file name: db-<type>-<key>.<ext>.
 *
 * @return string
 */
function wpoptimizing_backup_db_file_regex() {
	return '/^db-([a-z0-9_]+)-(op-[A-Za-z0-9-]+|\\d{8})\\.(jsonl|sql|php)$/';
}

/**
 * How many records a component file holds.
 *
 * @param string $path Absolute path.
 * @param string $ext  jsonl|sql|php.
 * @return int
 */
function wpoptimizing_backup_count_records( $path, $ext ) {
	if ( 'sql' === $ext ) {
		return 0;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$n = (int) substr_count( (string) file_get_contents( $path ), "\n" );
	return max( 0, 'php' === $ext ? $n - 1 : $n ); // The guard line is not a record.
}

/**
 * Merge what one write added into the operation's manifest.
 *
 * @param string                                                 $op_key  Operation key.
 * @param string                                                 $trigger Why the backup was taken.
 * @param array<string,array{count:int,samples:array<int,string>}> $by_type Counts and samples per type.
 * @param int                                                    $total   Items added.
 * @return bool
 */
function wpoptimizing_backup_merge_manifest( $op_key, $trigger, array $by_type, $total ) {
	$op_key = (string) $op_key;
	if ( '' === $op_key || ! wpoptimizing_backup_ensure_dir() ) {
		return false;
	}
	$rec  = array(
		'op'      => $op_key,
		'ts'      => time(),
		'trigger' => (string) $trigger,
		'total'   => (int) $total,
		'types'   => $by_type,
	);
	$path = WPOPT_BACKUP_DIR . '/op-manifest-' . sanitize_file_name( $op_key ) . '.json';
	// One manifest per operation; merge if it already exists (async batches, task after task).
	if ( file_exists( $path ) ) {
		$prev = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( is_array( $prev ) ) {
			$rec['total'] += (int) ( $prev['total'] ?? 0 );
			foreach ( (array) ( $prev['types'] ?? array() ) as $t => $info ) {
				if ( ! isset( $rec['types'][ $t ] ) ) {
					$rec['types'][ $t ] = array(
						'count'   => 0,
						'samples' => array(),
					);
				}
				$rec['types'][ $t ]['count']  += (int) ( $info['count'] ?? 0 );
				$rec['types'][ $t ]['samples'] = array_slice( array_values( array_unique( array_merge( (array) ( $info['samples'] ?? array() ), $rec['types'][ $t ]['samples'] ) ) ), 0, 6 );
			}
			if ( '' === $rec['trigger'] ) {
				$rec['trigger'] = (string) ( $prev['trigger'] ?? '' );
			}
		}
	}
	return false !== file_put_contents( $path, (string) wp_json_encode( $rec ), LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
}

/**
 * Ceiling for the whole backup folder, in bytes (0 = none).
 *
 * Scheduled cleanups now back up everything they delete. Retention (copies per type, days)
 * bounds the number of copies, not their size, so a site that deletes a lot every night could
 * still fill the disk; a write that would cross this line fails, and the action stops with a
 * clear status instead of filling the disk.
 *
 * @return int
 */
function wpoptimizing_backup_max_bytes() {
	$mb = (int) get_option( 'wpoptimizing_backup_max_mb', 2048 );
	/**
	 * Filters the size ceiling of the backup folder, in bytes. 0 removes the ceiling.
	 *
	 * @since 0.1.0
	 * @param int $bytes Default: the wpoptimizing_backup_max_mb option (2048 MB).
	 */
	return max( 0, (int) apply_filters( 'wpoptimizing_backup_max_bytes', max( 0, $mb ) * MB_IN_BYTES ) );
}

/**
 * Bytes the backup folder holds now.
 *
 * @return int
 */
function wpoptimizing_backup_dir_bytes() {
	if ( ! defined( 'WPOPT_BACKUP_DIR' ) || ! is_dir( WPOPT_BACKUP_DIR ) ) {
		return 0;
	}
	$total = 0;
	try {
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( WPOPT_BACKUP_DIR, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);
		foreach ( $it as $f ) {
			if ( $f->isFile() ) {
				$total += (int) $f->getSize();
			}
		}
	} catch ( Exception $e ) {
		return $total;
	}
	return $total;
}

/**
 * Integer-only IN() list.
 *
 * @param array $ids IDs.
 * @return string '' when nothing is left.
 */
function wpoptimizing_backup_in_list( array $ids ) {
	return implode( ',', wpoptimizing_backup_ids( $ids ) );
}

/**
 * IDs for an IN() list: integers, unique, no zeros.
 *
 * The list then goes through $wpdb->prepare() with `implode( ',', array_fill( …, '%d' ) )`
 * written at the call, never interpolated nor built by a helper: Plugin Check flags any other
 * variable in the SQL as an unescaped parameter, and wordpress.org reviews by it.
 *
 * @param array $ids IDs.
 * @return int[]
 */
function wpoptimizing_backup_ids( array $ids ) {
	return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
}

/**
 * Backup records of posts and of everything wp_delete_post( $id, true ) removes or rewrites
 * with them: meta, term relations, comments with their meta, revisions with theirs, and the
 * children it re-points at the post's own parent.
 *
 * One query per table for the whole set, not five per post: the cleanup passes batches of
 * hundreds of revisions.
 *
 * @param int[] $ids Post IDs.
 * @param int   $now Timestamp of the backup.
 * @return array<int,array<string,mixed>>|false Records keyed by post ID (a post that is gone is
 *                                              missing); false when a read failed.
 */
function wpoptimizing_backup_post_records( array $ids, $now ) {
	global $wpdb;
	$ids = wpoptimizing_backup_ids( $ids );
	if ( ! $ids ) {
		return array();
	}
	$in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$failed = false;
	// Takes the rows already read, so every query stays prepared at its own call.
	$read  = static function ( $rows ) use ( $wpdb, &$failed ) {
		$failed = $failed || '' !== (string) $wpdb->last_error;
		return (array) $rows;
	};
	$group = static function ( array $rows, $col ) {
		$out = array();
		foreach ( $rows as $r ) {
			$out[ (int) ( $r[ $col ] ?? 0 ) ][] = $r;
		}
		return $out;
	};

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Backing up what is about to go: a cached copy could be stale. Only %d placeholders are interpolated.
	$posts = $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID IN ({$in})", $ids ), ARRAY_A ) );
	if ( $failed ) {
		return false;
	}
	if ( empty( $posts ) ) {
		return array();
	}
	$meta     = $group( $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$in})", $ids ), ARRAY_A ) ), 'post_id' );
	$rels     = $group( $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ({$in})", $ids ), ARRAY_A ) ), 'object_id' );
	$comments = $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_post_ID IN ({$in})", $ids ), ARRAY_A ) );
	$cmeta    = array();
	$cids     = wpoptimizing_backup_ids( array_column( $comments, 'comment_ID' ) );
	if ( $cids ) {
		$cin   = implode( ',', array_fill( 0, count( $cids ), '%d' ) );
		$cmeta = $group( $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$cin})", $cids ), ARRAY_A ) ), 'comment_id' );
	}
	$revisions = $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_parent IN ({$in}) AND post_type = 'revision'", $ids ), ARRAY_A ) );
	$rmeta     = array();
	$rids      = wpoptimizing_backup_ids( array_column( $revisions, 'ID' ) );
	if ( $rids ) {
		$rin   = implode( ',', array_fill( 0, count( $rids ), '%d' ) );
		$rmeta = $group( $read( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ({$rin})", $rids ), ARRAY_A ) ), 'post_id' );
	}
	// wp_delete_post() moves the post's children (same type, and attachments) up to its parent.
	$children = $read( $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_parent FROM {$wpdb->posts} WHERE post_parent IN ({$in}) AND post_type <> 'revision'", $ids ), ARRAY_A ) );
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	if ( $failed ) {
		return false;
	}

	$by_post_comments = $group( $comments, 'comment_post_ID' );
	$by_post_revs     = $group( $revisions, 'post_parent' );
	$by_post_children = $group( $children, 'post_parent' );
	$out              = array();
	foreach ( $posts as $post ) {
		$id     = (int) $post['ID'];
		$c_rows = $by_post_comments[ $id ] ?? array();
		$c_meta = array();
		foreach ( $c_rows as $c ) {
			foreach ( $cmeta[ (int) $c['comment_ID'] ] ?? array() as $m ) {
				$c_meta[] = $m;
			}
		}
		$revs = array();
		foreach ( $by_post_revs[ $id ] ?? array() as $rev ) {
			$revs[] = array(
				'post' => $rev,
				'meta' => $rmeta[ (int) $rev['ID'] ] ?? array(),
			);
		}
		$out[ $id ] = array(
			'ts'           => (int) $now,
			'type'         => 'cpt_post',
			'post'         => $post,
			'meta'         => $meta[ $id ] ?? array(),
			'rels'         => $rels[ $id ] ?? array(),
			'comments'     => $c_rows,
			'comment_meta' => $c_meta,
			'revisions'    => $revs,
			'children'     => array_map( 'intval', array_column( $by_post_children[ $id ] ?? array(), 'ID' ) ),
		);
	}
	return $out;
}

/**
 * Backup records of comments and of what wp_delete_comment( $id, true ) takes or rewrites:
 * their meta, and the replies it re-points at the comment's own parent.
 *
 * @param int[] $ids Comment IDs.
 * @param int   $now Timestamp of the backup.
 * @return array<int,array<string,mixed>>|false
 */
function wpoptimizing_backup_comment_records( array $ids, $now ) {
	global $wpdb;
	$ids = wpoptimizing_backup_ids( $ids );
	if ( ! $ids ) {
		return array();
	}
	$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %d placeholders are interpolated.
	$comments = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ({$in})", $ids ), ARRAY_A );
	$ok       = '' === (string) $wpdb->last_error;
	$meta     = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ({$in})", $ids ), ARRAY_A );
	$ok       = $ok && '' === (string) $wpdb->last_error;
	$children = (array) $wpdb->get_results( $wpdb->prepare( "SELECT comment_ID, comment_parent FROM {$wpdb->comments} WHERE comment_parent IN ({$in})", $ids ), ARRAY_A );
	$ok       = $ok && '' === (string) $wpdb->last_error;
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	if ( ! $ok ) {
		return false;
	}
	$by_meta = array();
	foreach ( $meta as $m ) {
		$by_meta[ (int) $m['comment_id'] ][] = $m;
	}
	$by_child = array();
	foreach ( $children as $c ) {
		$by_child[ (int) $c['comment_parent'] ][] = (int) $c['comment_ID'];
	}
	$out = array();
	foreach ( $comments as $c ) {
		$id         = (int) $c['comment_ID'];
		$out[ $id ] = array(
			'ts'       => (int) $now,
			'type'     => 'comment',
			'comment'  => $c,
			'meta'     => $by_meta[ $id ] ?? array(),
			'children' => $by_child[ $id ] ?? array(),
		);
	}
	return $out;
}

/**
 * Backup records of terms of one taxonomy, in the tax_term shape the restore already rebuilds:
 * term, term_taxonomy, its meta and its relationships.
 *
 * @param int[]  $term_ids Term IDs.
 * @param string $taxonomy Taxonomy.
 * @param int    $now      Timestamp of the backup.
 * @return array<int,array<string,mixed>>|false
 */
function wpoptimizing_backup_term_records( array $term_ids, $taxonomy, $now ) {
	global $wpdb;
	$term_ids = wpoptimizing_backup_ids( $term_ids );
	if ( ! $term_ids ) {
		return array();
	}
	$in = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %d placeholders are interpolated.
	$tts   = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id IN ({$in})", array_merge( array( (string) $taxonomy ), $term_ids ) ), ARRAY_A );
	$ok    = '' === (string) $wpdb->last_error;
	$terms = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->terms} WHERE term_id IN ({$in})", $term_ids ), ARRAY_A );
	$ok    = $ok && '' === (string) $wpdb->last_error;
	$meta  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->termmeta} WHERE term_id IN ({$in})", $term_ids ), ARRAY_A );
	$ok    = $ok && '' === (string) $wpdb->last_error;
	$rels  = array();
	$ttids = wpoptimizing_backup_ids( array_column( $tts, 'term_taxonomy_id' ) );
	if ( $ttids ) {
		$ttin = implode( ',', array_fill( 0, count( $ttids ), '%d' ) );
		$rels = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$ttin})", $ttids ), ARRAY_A );
		$ok   = $ok && '' === (string) $wpdb->last_error;
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	if ( ! $ok ) {
		return false;
	}
	$by_term = array();
	foreach ( $terms as $t ) {
		$by_term[ (int) $t['term_id'] ] = $t;
	}
	$out = array();
	foreach ( $tts as $tt ) {
		$id = (int) $tt['term_id'];
		if ( ! isset( $by_term[ $id ] ) ) {
			continue;
		}
		$tt_id      = (int) $tt['term_taxonomy_id'];
		$out[ $id ] = array(
			'ts'            => (int) $now,
			'type'          => 'tax_term',
			'term'          => $by_term[ $id ],
			'term_taxonomy' => $tt,
			'meta'          => array_values( array_filter( $meta, static fn( $m ) => (int) $m['term_id'] === $id ) ),
			'rels'          => array_values( array_filter( $rels, static fn( $r ) => (int) $r['term_taxonomy_id'] === $tt_id ) ),
		);
	}
	return $out;
}

/**
 * Where a file lives, as a root the restore can resolve on this site (even after a move) and a
 * path below it.
 *
 * @param string $abs Absolute path.
 * @return array{root:string,rel:string}|null Null when the file is outside every known root.
 */
function wpoptimizing_backup_file_location( $abs ) {
	$abs = wp_normalize_path( (string) $abs );
	if ( function_exists( 'wpoptimizing_wpconfig_path' ) && '' !== wpoptimizing_wpconfig_path() && wp_normalize_path( wpoptimizing_wpconfig_path() ) === $abs ) {
		return array(
			'root' => 'wpconfig',
			'rel'  => 'wp-config.php',
		);
	}
	$roots = array();
	if ( function_exists( 'wp_get_upload_dir' ) ) {
		$up = wp_get_upload_dir();
		if ( ! empty( $up['basedir'] ) ) {
			$roots['uploads'] = (string) $up['basedir'];
		}
	}
	$roots['content'] = WP_CONTENT_DIR;
	$roots['abspath'] = ABSPATH;
	foreach ( $roots as $root => $dir ) {
		$base = trailingslashit( wp_normalize_path( (string) $dir ) );
		if ( '' !== $base && 0 === strpos( $abs, $base ) ) {
			return array(
				'root' => $root,
				'rel'  => substr( $abs, strlen( $base ) ),
			);
		}
	}
	return null;
}

/**
 * Absolute path of a backed-up file on this site, or '' when the record does not name one the
 * restore may write. Never a path with "..", never outside its root.
 *
 * @param array<string,mixed> $rec File record.
 * @return string
 */
function wpoptimizing_backup_file_target( array $rec ) {
	$root = (string) ( $rec['root'] ?? '' );
	$rel  = ltrim( wp_normalize_path( (string) ( $rec['rel'] ?? '' ) ), '/' );
	if ( '' === $rel || false !== strpos( $rel, '..' ) || false !== strpos( $rel, "\0" ) ) {
		return '';
	}
	switch ( $root ) {
		case 'wpconfig':
			return function_exists( 'wpoptimizing_wpconfig_path' ) ? (string) wpoptimizing_wpconfig_path() : '';
		case 'uploads':
			$up = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
			return empty( $up['basedir'] ) ? '' : trailingslashit( (string) $up['basedir'] ) . $rel;
		case 'content':
			return trailingslashit( WP_CONTENT_DIR ) . $rel;
		case 'abspath':
			return ABSPATH . $rel;
	}
	return '';
}

/**
 * Put a backed-up file back as it was: its old bytes, or gone if it did not exist then.
 *
 * @param array<string,mixed> $rec File record.
 * @return bool
 */
function wpoptimizing_backup_restore_file( array $rec ) {
	$path = wpoptimizing_backup_file_target( $rec );
	if ( '' === $path || is_link( $path ) ) {
		return false;
	}
	if ( empty( $rec['existed'] ) ) {
		if ( is_file( $path ) ) {
			wp_delete_file( $path );
		}
		return ! file_exists( $path );
	}
	$bytes = base64_decode( (string) ( $rec['content'] ?? '' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- the copy is stored base64 so any byte survives JSON.
	if ( false === $bytes ) {
		return false;
	}
	$tmp = function_exists( 'wpoptimizing_atomic_tmp_path' ) ? wpoptimizing_atomic_tmp_path( $path ) : $path . '.tmp.' . uniqid( '', true );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	if ( false === @file_put_contents( $tmp, $bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false;
	}
	if ( isset( $rec['mode'] ) && (int) $rec['mode'] > 0 ) {
		@chmod( $tmp, (int) $rec['mode'] & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
	}
	if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		wp_delete_file( $tmp );
		return false;
	}
	return true;
}

/**
 * Delete backups past their age, then the oldest of each type past the count, then the
 * manifests left with no component. Moved here from the Backups trait so a scheduled cleanup,
 * which has no admin class, applies the same retention before it writes.
 *
 * @param int $max_per_type Copies kept per type.
 * @param int $max_days     Days a copy is kept.
 * @return void
 */
function wpoptimizing_backup_prune( $max_per_type, $max_days ) {
	if ( ! defined( 'WPOPT_BACKUP_DIR' ) || ! is_dir( WPOPT_BACKUP_DIR ) ) {
		return;
	}
	$max_per_type = max( 1, (int) $max_per_type );
	$cutoff       = time() - ( max( 1, (int) $max_days ) * DAY_IN_SECONDS );

	// DB backup files: keys are either a legacy day (8 digits) or a per-operation key (op-…).
	// Pruning is driven by file mtime so both formats age uniformly.
	$by_type = array();
	foreach ( (array) glob( WPOPT_BACKUP_DIR . '/db-*' ) as $path ) {
		if ( ! preg_match( wpoptimizing_backup_db_file_regex(), basename( (string) $path ), $m ) ) {
			continue;
		}
		$ts = (int) filemtime( $path );
		if ( $ts && $ts < $cutoff ) {
			wp_delete_file( $path );
			continue;
		}
		$by_type[ $m[1] ][] = array(
			'path' => $path,
			'ts'   => $ts,
		);
	}
	foreach ( $by_type as $list ) {
		usort( $list, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
		foreach ( array_slice( $list, $max_per_type ) as $item ) {
			wp_delete_file( $item['path'] );
		}
	}

	// FS backups: age + count, driven by the manifest mtime.
	$fs_list = array();
	foreach ( (array) glob( WPOPT_BACKUP_DIR . '/fs-*.jsonl' ) as $path ) {
		if ( ! preg_match( '/fs-([A-Za-z0-9_-]+)\\.jsonl$/', basename( (string) $path ), $m ) ) {
			continue;
		}
		$ts = (int) filemtime( $path );
		if ( $ts && $ts < $cutoff ) {
			wpoptimizing_backup_drop_fs( $m[1] );
			continue;
		}
		$fs_list[] = array(
			'key' => $m[1],
			'ts'  => $ts,
		);
	}
	usort( $fs_list, static fn( $a, $b ) => $b['ts'] <=> $a['ts'] );
	foreach ( array_slice( $fs_list, $max_per_type ) as $item ) {
		wpoptimizing_backup_drop_fs( $item['key'] );
	}

	// Manifests whose component files are all gone: the tab never shows an empty entry.
	foreach ( (array) glob( WPOPT_BACKUP_DIR . '/op-manifest-*.json' ) as $mpath ) {
		if ( ! preg_match( '/^op-manifest-(op-[A-Za-z0-9-]+)\\.json$/', basename( (string) $mpath ), $m ) ) {
			continue;
		}
		if ( ! wpoptimizing_backup_has_components( $m[1] ) ) {
			wp_delete_file( $mpath );
		}
	}
}

/**
 * Remove one filesystem backup (its folder and its list).
 *
 * @param string $key Operation key or legacy day.
 * @return void
 */
function wpoptimizing_backup_drop_fs( $key ) {
	$dir = WPOPT_BACKUP_DIR . '/fs-' . $key;
	if ( is_dir( $dir ) && ! is_link( $dir ) ) {
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $path ) {
			if ( $path->isDir() && ! $path->isLink() ) {
				rmdir( $path->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			} else {
				wp_delete_file( $path->getPathname() );
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
	$list = WPOPT_BACKUP_DIR . '/fs-' . $key . '.jsonl';
	if ( file_exists( $list ) ) {
		wp_delete_file( $list );
	}
}

/**
 * Whether an operation still has any component on disk.
 *
 * @param string $key Operation key.
 * @return bool
 */
function wpoptimizing_backup_has_components( $key ) {
	$key = (string) $key;
	if ( is_dir( WPOPT_BACKUP_DIR . '/fs-' . $key ) ) {
		return true;
	}
	foreach ( (array) glob( WPOPT_BACKUP_DIR . '/db-*-' . $key . '.*' ) as $path ) {
		if ( preg_match( wpoptimizing_backup_db_file_regex(), basename( (string) $path ), $m ) && $m[2] === $key ) {
			return true;
		}
	}
	return false;
}

/**
 * Apply the retention the customer configured (Settings › Backups).
 *
 * @return void
 */
function wpoptimizing_backup_apply_retention() {
	$per_type = (int) get_option( 'wpoptimizing_backup_max_per_type', 25 );
	$days     = (int) get_option( 'wpoptimizing_backup_max_days', 30 );
	wpoptimizing_backup_prune( $per_type > 0 ? $per_type : 25, $days > 0 ? $days : 30 );
}

/**
 * Remember, for the rest of this request, that a backup could not be written; or ask.
 *
 * @param string|null $reason dir|write|read|cap to record one; null to read.
 * @return string '' when none failed.
 */
function wpoptimizing_backup_failure( $reason = null ) {
	static $last = '';
	if ( null !== $reason ) {
		$last = (string) $reason;
	}
	return $last;
}

/**
 * The customer-facing explanation of a backup that could not be written.
 *
 * @param string $reason dir|write|read|cap.
 * @return string
 */
function wpoptimizing_backup_failure_message( $reason ) {
	if ( 'cap' === $reason ) {
		return __( 'Not done: the backups have reached their size limit. Delete old backups under Backups, or raise “Max backup size” in Settings, and try again.', 'wpoptimizing' );
	}
	return __( 'Not done: a backup could not be created first (check disk space and folder permissions). Please try again.', 'wpoptimizing' );
}

/**
 * Show the backup failure of this request, if any, as the admin flash notice.
 *
 * @return bool Whether there was one.
 */
function wpoptimizing_backup_flash_failure() {
	$reason = wpoptimizing_backup_failure();
	if ( '' === $reason ) {
		return false;
	}
	set_transient(
		'wpoptimizing_flash',
		array(
			'code' => 'wpoptimizing_backup_failed',
			'msg'  => wpoptimizing_backup_failure_message( $reason ),
			'type' => 'error',
		),
		60
	);
	return true;
}

/**
 * Back up one file, as its own operation, before it is edited or removed.
 *
 * The editors of .htaccess and wp-config.php call this and change nothing when it says no.
 *
 * @param string $abs     Absolute path.
 * @param string $trigger Backup reason (htaccess|wpconfig).
 * @return bool
 */
function wpoptimizing_backup_file_first( $abs, $trigger ) {
	return ( new WPOptimizing_Backup_Writer( '', (string) $trigger ) )->file( (string) $abs );
}
