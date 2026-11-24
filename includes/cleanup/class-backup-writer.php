<?php
/**
 * WPOptimizing_Backup_Writer: the backup of one operation, written batch by batch before the
 * batch is changed. File format, manifest and retention: see backup-store.php, which loads this.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes the backup of one operation, a batch at a time, before the batch is changed.
 *
 * Every method answers whether the whole batch is on disk; the caller changes nothing when it
 * is false, and every later call of the same writer answers false too, so a run that lost its
 * backup stops instead of going on with the next task.
 */
class WPOptimizing_Backup_Writer {

	/**
	 * Operation key.
	 *
	 * @var string
	 */
	protected $op_key;

	/**
	 * Why the backup is taken (the Backups tab label).
	 *
	 * @var string
	 */
	protected $trigger;

	/**
	 * Bytes this writer may still add before the folder reaches its ceiling (null = not measured yet).
	 *
	 * @var int|null
	 */
	protected $room = null;

	/**
	 * Why the writer stopped ('' while it works).
	 *
	 * @var string
	 */
	protected $failed = '';

	/**
	 * Bytes written by this writer.
	 *
	 * @var int
	 */
	protected $written = 0;

	/**
	 * Constructor.
	 *
	 * @param string $op_key  Operation key ('' for a fresh one).
	 * @param string $trigger Backup reason.
	 */
	public function __construct( $op_key = '', $trigger = 'cleanup' ) {
		$this->op_key  = '' !== (string) $op_key ? (string) $op_key : wpoptimizing_backup_new_op_key();
		$this->trigger = (string) $trigger;
	}

	/**
	 * Operation key.
	 *
	 * @return string
	 */
	public function key() {
		return $this->op_key;
	}

	/**
	 * Why the writer stopped, or '' while it works.
	 *
	 * @return string dir|write|read|cap
	 */
	public function failed() {
		return $this->failed;
	}

	/**
	 * Bytes written so far.
	 *
	 * @return int
	 */
	public function written() {
		return $this->written;
	}

	/**
	 * Stop the writer for good.
	 *
	 * @param string $reason dir|write|read|cap.
	 * @return false
	 */
	protected function fail( $reason ) {
		$this->failed = (string) $reason;
		wpoptimizing_backup_failure( $this->failed );
		return false;
	}

	/**
	 * Write records into one component file of the operation, all of them or none counted.
	 *
	 * @param string                         $file_type Component label (db-<label>-<key>).
	 * @param array<int,array<string,mixed>> $records   Records.
	 * @param string[]                       $samples   Names that identify the batch in the tab.
	 * @param string                         $ext       jsonl|php.
	 * @return bool
	 */
	public function write( $file_type, array $records, array $samples = array(), $ext = 'jsonl' ) {
		if ( '' !== $this->failed ) {
			return false;
		}
		if ( empty( $records ) ) {
			return true;
		}
		if ( ! wpoptimizing_backup_ensure_dir() ) {
			return $this->fail( 'dir' );
		}
		$text = '';
		foreach ( $records as $rec ) {
			$line = wp_json_encode( $rec );
			if ( ! is_string( $line ) ) {
				return $this->fail( 'write' );
			}
			$text .= $line . "\n";
		}
		$path = wpoptimizing_backup_db_path( $file_type, $this->op_key, $ext );
		if ( 'php' === $ext && ! file_exists( $path ) ) {
			$text = WPOPT_BACKUP_PHP_GUARD . $text;
		}
		if ( null === $this->room ) {
			$max        = wpoptimizing_backup_max_bytes();
			$this->room = $max > 0 ? $max - wpoptimizing_backup_dir_bytes() : PHP_INT_MAX;
		}
		if ( strlen( $text ) > $this->room ) {
			return $this->fail( 'cap' );
		}
		if ( ! wpoptimizing_backup_append( $path, $text ) ) {
			return $this->fail( 'write' );
		}
		$this->room    -= strlen( $text );
		$this->written += strlen( $text );
		wpoptimizing_backup_merge_manifest(
			$this->op_key,
			$this->trigger,
			array(
				$file_type => array(
					'count'   => count( $records ),
					'samples' => array_slice( array_values( array_filter( array_map( 'strval', $samples ) ) ), 0, 6 ),
				),
			),
			count( $records )
		);
		return true;
	}

	/**
	 * Whether the last query failed; if so the writer stops.
	 *
	 * @return bool True when it failed.
	 */
	protected function read_failed() {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			$this->fail( 'read' );
			return true;
		}
		return false;
	}

	/**
	 * Back up whole rows of one table, selected by an integer column.
	 *
	 * @param string $file_type Component label.
	 * @param string $rec_type  Record type the restore dispatches on.
	 * @param string $table     Full table name.
	 * @param string $col       Integer column the IDs are matched on.
	 * @param int[]  $ids       IDs.
	 * @param string $sample    Column whose values name the batch in the tab ('' = none).
	 * @return bool
	 */
	public function rows( $file_type, $rec_type, $table, $col, array $ids, $sample = '' ) {
		global $wpdb;
		if ( '' !== $this->failed ) {
			return false;
		}
		$ids = wpoptimizing_backup_ids( $ids );
		if ( ! $ids ) {
			return true;
		}
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE %i IN ({$in})", array_merge( array( (string) $table, (string) $col ), $ids ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %d placeholders are interpolated.
		if ( $this->read_failed() ) {
			return false;
		}
		return $this->row_records( $file_type, $rec_type, $rows, $sample );
	}

	/**
	 * Back up rows already read.
	 *
	 * @param string $file_type Component label.
	 * @param string $rec_type  Record type.
	 * @param array  $rows      Rows (ARRAY_A).
	 * @param string $sample    Column naming the batch ('' = none).
	 * @return bool
	 */
	public function row_records( $file_type, $rec_type, array $rows, $sample = '' ) {
		$now     = time();
		$records = array();
		$names   = array();
		foreach ( $rows as $row ) {
			$records[] = array(
				'ts'   => $now,
				'type' => (string) $rec_type,
				'row'  => $row,
			);
			if ( '' !== $sample && isset( $row[ $sample ] ) && count( $names ) < 6 ) {
				$names[] = (string) $row[ $sample ];
			}
		}
		return $this->write( $file_type, $records, $names );
	}

	/**
	 * Back up the content of posts before it is rewritten (put back by ID on restore).
	 *
	 * @param int[] $ids Post IDs.
	 * @return bool
	 */
	public function post_contents( array $ids ) {
		global $wpdb;
		if ( '' !== $this->failed ) {
			return false;
		}
		$ids = wpoptimizing_backup_ids( $ids );
		if ( ! $ids ) {
			return true;
		}
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_content FROM {$wpdb->posts} WHERE ID IN ({$in})", $ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only %d placeholders are interpolated.
		if ( $this->read_failed() ) {
			return false;
		}
		$records = array();
		foreach ( $rows as $row ) {
			$records[] = array(
				'ts'           => time(),
				'type'         => 'post_content',
				'post_id'      => (int) $row['ID'],
				'post_content' => (string) $row['post_content'],
			);
		}
		return $this->write( 'post', $records, array_map( static fn( $r ) => '#' . $r['post_id'], $records ) );
	}

	/**
	 * Back up the rows of one meta key of some posts before they are rewritten.
	 *
	 * @param int[]  $post_ids Post IDs.
	 * @param string $meta_key Meta key.
	 * @return bool
	 */
	public function post_meta( array $post_ids, $meta_key ) {
		global $wpdb;
		if ( '' !== $this->failed ) {
			return false;
		}
		$post_ids = wpoptimizing_backup_ids( $post_ids );
		if ( ! $post_ids ) {
			return true;
		}
		$in  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
		$ids = (array) $wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN ({$in})", array_merge( array( (string) $meta_key ), $post_ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Only %d placeholders are interpolated.
		if ( $this->read_failed() ) {
			return false;
		}
		return $this->rows( 'post_meta', 'post_meta', $wpdb->postmeta, 'meta_id', $ids, 'meta_key' );
	}

	/**
	 * Back up posts with everything their deletion takes along (see wpoptimizing_backup_post_records()).
	 *
	 * @param int[]  $ids       Post IDs.
	 * @param string $file_type Component label.
	 * @return bool
	 */
	public function posts( array $ids, $file_type = 'post' ) {
		if ( '' !== $this->failed ) {
			return false;
		}
		// Fifty at a time: a revision carries the whole post content.
		foreach ( array_chunk( array_values( array_unique( array_map( 'intval', $ids ) ) ), 50 ) as $chunk ) {
			$recs = wpoptimizing_backup_post_records( $chunk, time() );
			if ( false === $recs ) {
				return $this->fail( 'read' );
			}
			$names = array();
			foreach ( $recs as $id => $rec ) {
				$title   = (string) ( $rec['post']['post_title'] ?? '' );
				$names[] = '#' . $id . ( '' !== $title ? ' ' . ( function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 40 ) : substr( $title, 0, 40 ) ) : '' );
			}
			if ( ! $this->write( $file_type, array_values( $recs ), $names ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Back up comments with their meta and replies.
	 *
	 * @param int[] $ids Comment IDs.
	 * @return bool
	 */
	public function comments( array $ids ) {
		if ( '' !== $this->failed ) {
			return false;
		}
		$recs = wpoptimizing_backup_comment_records( $ids, time() );
		if ( false === $recs ) {
			return $this->fail( 'read' );
		}
		return $this->write( 'comment', array_values( $recs ), array_map( static fn( $id ) => '#' . $id, array_keys( $recs ) ) );
	}

	/**
	 * Back up terms of one taxonomy.
	 *
	 * @param int[]  $ids      Term IDs.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	public function terms( array $ids, $taxonomy ) {
		if ( '' !== $this->failed ) {
			return false;
		}
		$recs = wpoptimizing_backup_term_records( $ids, $taxonomy, time() );
		if ( false === $recs ) {
			return $this->fail( 'read' );
		}
		return $this->write( 'taxonomy_term', array_values( $recs ), array_map( static fn( $r ) => (string) ( $r['term']['name'] ?? '' ), $recs ) );
	}

	/**
	 * Back up option rows (transients are options), whole.
	 *
	 * @param string[] $names    Option names.
	 * @param string   $rec_type option|transient.
	 * @return bool
	 */
	public function options( array $names, $rec_type = 'transient' ) {
		global $wpdb;
		if ( '' !== $this->failed ) {
			return false;
		}
		$names = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
		if ( empty( $names ) ) {
			return true;
		}
		$records = array();
		$now     = time();
		foreach ( array_chunk( $names, 200 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name IN ({$ph})", $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per name.
			if ( $this->read_failed() ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$records[] = array(
					'ts'       => $now,
					'type'     => (string) $rec_type,
					'name'     => (string) $row['option_name'],
					'value'    => $row['option_value'],
					'autoload' => (string) $row['autoload'],
				);
			}
		}
		return $this->write( 'option' === $rec_type ? 'option' : 'transient', $records, array_column( $records, 'name' ) );
	}

	/**
	 * Back up the autoload flag of options, so a change of it can be put back.
	 *
	 * @param string[] $names Option names.
	 * @return bool
	 */
	public function autoload( array $names ) {
		global $wpdb;
		if ( '' !== $this->failed ) {
			return false;
		}
		$names = array_values( array_unique( array_filter( array_map( 'strval', $names ) ) ) );
		if ( empty( $names ) ) {
			return true;
		}
		$records = array();
		$now     = time();
		foreach ( array_chunk( $names, 200 ) as $chunk ) {
			$ph   = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name IN ({$ph})", $chunk ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one %s per name.
			if ( $this->read_failed() ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$records[] = array(
					'ts'       => $now,
					'type'     => 'autoload',
					'name'     => (string) $row['option_name'],
					'autoload' => (string) $row['autoload'],
				);
			}
		}
		return $this->write( 'autoload', $records, array_column( $records, 'name' ) );
	}

	/**
	 * Back up every scheduled event of cron hooks (merged back at their own timestamps).
	 *
	 * @param string[] $hooks Hook names.
	 * @return bool
	 */
	public function cron( array $hooks ) {
		if ( '' !== $this->failed ) {
			return false;
		}
		if ( ! function_exists( '_get_cron_array' ) || ! function_exists( 'wpoptimizing_cron_events_for_hook' ) ) {
			return $this->fail( 'read' );
		}
		$cron    = (array) _get_cron_array();
		$records = array();
		foreach ( array_unique( array_map( 'strval', $hooks ) ) as $hook ) {
			$events = wpoptimizing_cron_events_for_hook( $cron, $hook );
			if ( ! empty( $events ) ) {
				$records[] = array(
					'ts'     => time(),
					'type'   => 'cron',
					'hook'   => $hook,
					'events' => $events,
				);
			}
		}
		return $this->write( 'cron', $records, array_column( $records, 'hook' ) );
	}

	/**
	 * Back up one file before it is edited, replaced or removed. A file that does not exist yet
	 * is recorded as such, so the restore removes what the change created.
	 *
	 * @param string $abs Absolute path.
	 * @return bool
	 */
	public function file( $abs ) {
		if ( '' !== $this->failed ) {
			return false;
		}
		$abs = (string) $abs;
		$loc = wpoptimizing_backup_file_location( $abs );
		if ( null === $loc || is_link( $abs ) ) {
			return $this->fail( 'read' );
		}
		$rec = array(
			'ts'      => time(),
			'type'    => 'file',
			'root'    => $loc['root'],
			'rel'     => $loc['rel'],
			'existed' => is_file( $abs ),
		);
		if ( $rec['existed'] ) {
			$bytes = @file_get_contents( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $bytes ) {
				return $this->fail( 'read' );
			}
			$rec['content'] = base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- any byte has to survive JSON.
			$rec['mode']    = (int) @fileperms( $abs ) & 0777; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $this->write( 'file', array( $rec ), array( $loc['rel'] ), 'php' );
	}
}
