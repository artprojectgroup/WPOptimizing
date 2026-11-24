<?php
/**
 * General cleanup service.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/backup-store.php';

/**
 * Encapsulates all General cleanup settings, scheduling and execution logic.
 */
class WPOPT_General_Cleanup {

	/**
	 * Capability required for manual execution.
	 *
	 * @var string
	 */
	protected $capability = 'manage_options';

	/**
	 * Lock transient key.
	 *
	 * @var string
	 */
	protected $lock_key = 'wpoptimizing_general_cleanup_lock';

	/**
	 * Lifetime of the run lock, in seconds (15 minutes). The per-run time budget is
	 * capped below this so a single run can never outlive its own lock and let a
	 * concurrent cron tick start a second run over the same tables.
	 *
	 * @var int
	 */
	protected $lock_ttl = 900;

	/**
	 * Floor of the silence after which a lock taken from a WEB request counts as dead.
	 *
	 * Two windows, and they are not arbitrary: one is what can be proven, the other what cannot.
	 * A web run stops itself at its budget (time_budget(), 120 s unless filtered) and beats every
	 * LOCK_BEAT seconds between batches, so silence past budget + LOCK_STALE_MARGIN proves the
	 * request was killed from outside — PHP-FPM's request_terminate_timeout sends no shutdown
	 * hook, so nothing in the dead process could release its own lock. The window is stored in the
	 * lock at the moment it is taken, so lowering the budget lowers it too, and this floor keeps a
	 * run with a tiny budget from being declared dead by one slow batch.
	 *
	 * A lock taken from the CLI never counts as dead this way, WHOEVER finds it: there is no
	 * ceiling there, so a slow batch could stay silent longer than any window, and taking over
	 * would run two cleanups at once. What bounds the wait is how the holder could die, not who is
	 * looking. Those keep the full TTL.
	 */
	const LOCK_WEB_STALE = 180;

	/**
	 * Margin over the web budget before a silent web lock counts as dead.
	 */
	const LOCK_STALE_MARGIN = 60;

	/**
	 * Seconds between two heartbeats of the running cleanup.
	 */
	const LOCK_BEAT = 15;

	/**
	 * Where a cleanup cut short by its time budget keeps what it still has to do.
	 *
	 * Until 28/10/2026 a manual run that hit its two minutes simply stopped, and a scheduled one
	 * tried again at most three times. On the WP Total Updater site, with 16 million
	 * Action Scheduler log rows, that meant clicking the button a hundred times — and a customer
	 * cannot be told to do that, nor to empty a table over SSH they may not have. Now a run that
	 * stops at its budget leaves here the tasks it did not finish and schedules the next pass,
	 * which runs only those, until they are done.
	 */
	const RESUME_OPTION = 'wpoptimizing_gc_resume';

	/**
	 * Single event that runs the next pass.
	 */
	const RESUME_HOOK = 'wpoptimizing_general_cleanup_resume';

	/**
	 * Passes in a row that deleted nothing before the chain gives up: something is stuck (a lock
	 * held elsewhere, a table that refuses the DELETE) and trying every minute would not change it.
	 */
	const RESUME_IDLE_MAX = 3;

	/**
	 * Seconds until the rescue pass every run leaves scheduled when it starts.
	 *
	 * A pass that dies halfway (PHP-FPM killing the request, a fatal error) never reaches the
	 * end where it schedules the next one, and the chain stopped there. Longer than the window
	 * after which a dead web run's lock counts as stale (LOCK_WEB_STALE), so the rescue finds it
	 * free.
	 */
	const RESUME_RESCUE = 300;

	/**
	 * Identifier of the lock this run holds, so it only ever refreshes or releases its own.
	 *
	 * @var string
	 */
	protected $lock_id = '';

	/**
	 * Whether the lock this run holds was taken over from a dead web run.
	 *
	 * @var bool
	 */
	protected $lock_taken_over = false;

	/**
	 * Backup of this run. Every batch is written here BEFORE it is deleted, and a batch whose
	 * backup could not be written is not deleted: the run stops there (see backup_lost()).
	 *
	 * @var WPOptimizing_Backup_Writer|null
	 */
	protected $backup = null;

	/**
	 * Constructor.
	 *
	 * @param string $capability Capability required for manual execution.
	 */
	public function __construct( $capability = 'manage_options' ) {
		$this->capability = (string) $capability;
	}

	/**
	 * The backup writer of this run (created on first use when a task runs on its own).
	 *
	 * @return WPOptimizing_Backup_Writer
	 */
	protected function backup() {
		if ( null === $this->backup ) {
			$this->backup = new WPOptimizing_Backup_Writer( '', 'db_cleanup' );
		}
		return $this->backup;
	}

	/**
	 * Whether this run lost its backup: from then on nothing else is deleted.
	 *
	 * @return bool
	 */
	protected function backup_lost() {
		return null !== $this->backup && '' !== $this->backup->failed();
	}

	/**
	 * Quote a trusted SQL identifier such as a table name.
	 *
	 * @param string $identifier Identifier.
	 * @return string
	 */
	protected function quote_identifier( $identifier ) {
		return wpoptimizing_quote_identifier( $identifier );
	}

	/**
	 * Allowed cleanup schedules.
	 *
	 * @return string[]
	 */
	public static function get_allowed_schedules() {
		// Scheduled cleanup is Pro (23/10/2026): Lite and Pro without a license run it by hand.
		// The stored schedule is kept, so a renewed license picks it up again.
		$all = array( 'manual' );
		return $all;
	}

	/**
	 * Every schedule value the option may hold, whether or not this install can run it now: what
	 * an import keeps, so moving settings between sites never loses a schedule.
	 *
	 * @return string[]
	 */
	public static function get_valid_schedules() {
		return array( 'manual', 'hourly', 'twicedaily', 'daily', 'weekly', 'monthly' );
	}

	/**
	 * Return normalized cleanup settings.
	 *
	 * @return array<string,mixed>
	 */
	public function get_settings() {
		$defaults = array(
			'keep_revisions'                => 2,
			'revisions_max_age_days'        => 0,
			'keep_auto_drafts'              => 0,
			'auto_drafts_max_age_days'      => 0,
			'delete_auto_drafts'            => 1,
			'delete_trashed_posts'          => 1,
			'delete_spam_comments'          => 1,
			'delete_trashed_comments'       => 1,
			'trash_min_age_days'            => 0,
			'delete_unapproved_comments'    => 0,
			'delete_pingbacks'              => 0,
			'delete_trackbacks'             => 0,
			'delete_expired_transients'     => 1,
			'delete_all_transients'         => 0,
			'delete_oembed_cache'           => 0,
			'delete_unused_tags'            => 0,
			'delete_orphan_elements'        => 0,
			'delete_duplicate_meta'         => 0,
			'delete_action_scheduler'       => 0,
			'delete_wc_sessions'            => 0,
			'delete_wc_orphan_variations'   => 0,
			'action_scheduler_max_age_days' => 30,
			'optimize_tables'               => 1,
			'generate_report'               => 1,
			'excluded_optimize_tables'      => array(),
		);

		$stored = get_option( 'wpoptimizing_general_cleanup_settings', array() );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}

		$settings                                  = wp_parse_args( $stored, $defaults );
		$settings['keep_revisions']                = max( 0, min( 100, (int) $settings['keep_revisions'] ) );
		$settings['revisions_max_age_days']        = max( 0, min( 3650, (int) $settings['revisions_max_age_days'] ) );
		$settings['keep_auto_drafts']              = max( 0, min( 500, (int) $settings['keep_auto_drafts'] ) );
		$settings['auto_drafts_max_age_days']      = max( 0, min( 3650, (int) $settings['auto_drafts_max_age_days'] ) );
		$settings['delete_auto_drafts']            = empty( $settings['delete_auto_drafts'] ) ? 0 : 1;
		$settings['delete_trashed_posts']          = empty( $settings['delete_trashed_posts'] ) ? 0 : 1;
		$settings['delete_spam_comments']          = empty( $settings['delete_spam_comments'] ) ? 0 : 1;
		$settings['delete_trashed_comments']       = empty( $settings['delete_trashed_comments'] ) ? 0 : 1;
		$settings['trash_min_age_days']            = max( 0, min( 3650, (int) $settings['trash_min_age_days'] ) );
		$settings['delete_unapproved_comments']    = empty( $settings['delete_unapproved_comments'] ) ? 0 : 1;
		$settings['delete_pingbacks']              = empty( $settings['delete_pingbacks'] ) ? 0 : 1;
		$settings['delete_trackbacks']             = empty( $settings['delete_trackbacks'] ) ? 0 : 1;
		$settings['delete_expired_transients']     = empty( $settings['delete_expired_transients'] ) ? 0 : 1;
		$settings['delete_all_transients']         = empty( $settings['delete_all_transients'] ) ? 0 : 1;
		$settings['delete_oembed_cache']           = empty( $settings['delete_oembed_cache'] ) ? 0 : 1;
		$settings['delete_unused_tags']            = empty( $settings['delete_unused_tags'] ) ? 0 : 1;
		$settings['delete_orphan_elements']        = empty( $settings['delete_orphan_elements'] ) ? 0 : 1;
		$settings['delete_duplicate_meta']         = empty( $settings['delete_duplicate_meta'] ) ? 0 : 1;
		$settings['delete_action_scheduler']       = empty( $settings['delete_action_scheduler'] ) ? 0 : 1;
		$settings['delete_wc_sessions']            = empty( $settings['delete_wc_sessions'] ) ? 0 : 1;
		$settings['delete_wc_orphan_variations']   = empty( $settings['delete_wc_orphan_variations'] ) ? 0 : 1;
		$settings['action_scheduler_max_age_days'] = max( 0, min( 365, (int) $settings['action_scheduler_max_age_days'] ) );
		$settings['optimize_tables']               = empty( $settings['optimize_tables'] ) ? 0 : 1;
		$settings['generate_report']               = empty( $settings['generate_report'] ) ? 0 : 1;
		$settings['excluded_optimize_tables']      = $this->normalize_table_list( $settings['excluded_optimize_tables'] );

		return $settings;
	}

	/**
	 * Build normalized cleanup settings from request payload.
	 *
	 * @param array $request Raw request array (e.g. $_POST).
	 * @return array<string,mixed>
	 */
	public function settings_from_request( array $request ) {
		$excluded = array();
		if ( isset( $request['wpopt_gc_excluded_tables'] ) && is_array( $request['wpopt_gc_excluded_tables'] ) ) {
			// Table names only: [A-Za-z0-9_] is all a WordPress table prefix may hold. The
			// select also posts '__none__', which normalize_table_list() drops.
			foreach ( $request['wpopt_gc_excluded_tables'] as $table ) {
				if ( is_scalar( $table ) && preg_match( '/^[A-Za-z0-9_]{1,64}$/', (string) $table ) ) {
					$excluded[] = (string) $table;
				}
			}
		}

		// Tasks multi-select sends an array of task keys; old checkbox fields are kept as fallback.
		$tasks = array();
		if ( isset( $request['wpopt_gc_tasks'] ) && is_array( $request['wpopt_gc_tasks'] ) ) {
			$tasks = array_map( 'sanitize_key', $request['wpopt_gc_tasks'] );
		}
		$has_tasks_field = isset( $request['wpopt_gc_tasks'] );

		return array(
			'keep_revisions'              => isset( $request['wpopt_gc_keep_revisions'] ) ? max( 0, min( 100, absint( wp_unslash( $request['wpopt_gc_keep_revisions'] ) ) ) ) : 2,
			'revisions_max_age_days'      => isset( $request['wpopt_gc_revisions_max_age_days'] ) ? max( 0, min( 3650, absint( wp_unslash( $request['wpopt_gc_revisions_max_age_days'] ) ) ) ) : 0,
			'keep_auto_drafts'            => isset( $request['wpopt_gc_keep_auto_drafts'] ) ? max( 0, min( 500, absint( wp_unslash( $request['wpopt_gc_keep_auto_drafts'] ) ) ) ) : 0,
			'auto_drafts_max_age_days'    => isset( $request['wpopt_gc_auto_drafts_max_age_days'] ) ? max( 0, min( 3650, absint( wp_unslash( $request['wpopt_gc_auto_drafts_max_age_days'] ) ) ) ) : 0,
			'delete_auto_drafts'          => $has_tasks_field ? ( in_array( 'delete_auto_drafts', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_auto_drafts'] ) ? 1 : 0 ),
			'delete_trashed_posts'        => $has_tasks_field ? ( in_array( 'delete_trashed_posts', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_trashed_posts'] ) ? 1 : 0 ),
			'delete_spam_comments'        => $has_tasks_field ? ( in_array( 'delete_spam_comments', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_spam_comments'] ) ? 1 : 0 ),
			'delete_trashed_comments'     => $has_tasks_field ? ( in_array( 'delete_trashed_comments', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_trashed_comments'] ) ? 1 : 0 ),
			'trash_min_age_days'          => isset( $request['wpopt_gc_trash_min_age_days'] ) ? max( 0, min( 3650, absint( wp_unslash( $request['wpopt_gc_trash_min_age_days'] ) ) ) ) : 0,
			'delete_unapproved_comments'  => $has_tasks_field ? ( in_array( 'delete_unapproved_comments', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_unapproved_comments'] ) ? 1 : 0 ),
			'delete_pingbacks'            => $has_tasks_field ? ( in_array( 'delete_pingbacks', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_pingbacks'] ) ? 1 : 0 ),
			'delete_trackbacks'           => $has_tasks_field ? ( in_array( 'delete_trackbacks', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_trackbacks'] ) ? 1 : 0 ),
			'delete_expired_transients'   => $has_tasks_field ? ( in_array( 'delete_expired_transients', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_expired_transients'] ) ? 1 : 0 ),
			'delete_all_transients'       => $has_tasks_field ? ( in_array( 'delete_all_transients', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_all_transients'] ) ? 1 : 0 ),
			'delete_oembed_cache'         => $has_tasks_field ? ( in_array( 'delete_oembed_cache', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_oembed_cache'] ) ? 1 : 0 ),
			'delete_unused_tags'          => $has_tasks_field ? ( in_array( 'delete_unused_tags', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_unused_tags'] ) ? 1 : 0 ),
			'delete_orphan_elements'      => $has_tasks_field ? ( in_array( 'delete_orphan_elements', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_orphan_elements'] ) ? 1 : 0 ),
			'delete_duplicate_meta'       => $has_tasks_field ? ( in_array( 'delete_duplicate_meta', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_duplicate_meta'] ) ? 1 : 0 ),
			'delete_action_scheduler'     => $has_tasks_field ? ( in_array( 'delete_action_scheduler', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_action_scheduler'] ) ? 1 : 0 ),
			'delete_wc_sessions'          => $has_tasks_field ? ( in_array( 'delete_wc_sessions', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_wc_sessions'] ) ? 1 : 0 ),
			'delete_wc_orphan_variations' => $has_tasks_field ? ( in_array( 'delete_wc_orphan_variations', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_delete_wc_orphan_variations'] ) ? 1 : 0 ),
			'optimize_tables'             => $has_tasks_field ? ( in_array( 'optimize_tables', $tasks, true ) ? 1 : 0 ) : ( isset( $request['wpopt_gc_optimize_tables'] ) ? 1 : 0 ),
			'generate_report'             => isset( $request['wpopt_gc_generate_report'] ) ? 1 : 0,
			'excluded_optimize_tables'    => $this->normalize_table_list( $excluded ),
		);
	}

	/**
	 * Persist cleanup settings.
	 *
	 * @param array<string,mixed> $settings Settings array.
	 * @return void
	 */
	public function save_settings( array $settings ) {
		update_option( 'wpoptimizing_general_cleanup_settings', $settings, false );
	}

	/**
	 * Whether the last estimate() could not run every count (see estimate_var()).
	 *
	 * @var bool
	 */
	protected $estimate_partial = false;

	/**
	 * Whether the last estimate() is incomplete because the database cut a count short.
	 *
	 * @return bool
	 */
	public function estimate_is_partial() {
		return (bool) $this->estimate_partial;
	}

	/**
	 * One count of the estimate, noting whether it failed.
	 *
	 * @param string $sql Prepared query.
	 * @return string|null
	 */
	protected function estimate_var( $sql ) {
		global $wpdb;
		$v = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- every caller passes a prepared or fixed query built in estimate().
		if ( function_exists( 'wpoptimizing_db_failed' ) ) {
			wpoptimizing_db_failed( 'cleanup estimate' );
		}
		return $v;
	}

	/**
	 * Dry-run preview: estimate how many records the CURRENT settings would
	 * remove, per task, using read-only COUNT queries that mirror each cleanup's
	 * criteria. Figures are approximate (revisions/transients especially) but
	 * give a reliable "what will be deleted" picture before running. Nothing is
	 * modified.
	 *
	 * @return array<string,int> Map of task key => estimated record count.
	 */
	public function estimate() {
		global $wpdb;
		$s   = $this->get_settings();
		$out = array();
		if ( function_exists( 'wpoptimizing_db_failures_take' ) ) {
			wpoptimizing_db_failures_take();
		}
		$this->estimate_partial = false;
		// Bound every estimate query, and on huge DBs skip the duplicate-meta probe
		// (a derived-table GROUP BY + MD5 over the whole meta table).
		$large = function_exists( 'wpoptimizing_scan_large_site' ) && wpoptimizing_scan_large_site();
		if ( function_exists( 'wpoptimizing_db_statement_timeout' ) ) {
			wpoptimizing_db_statement_timeout( 15 );
		}
		$posts    = $this->quote_identifier( $wpdb->posts );    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$comments = $this->quote_identifier( $wpdb->comments ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$options  = $this->quote_identifier( $wpdb->options );  // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Revisions (approx): total beyond keep-N per parent, bounded by age cutoff.
		$keep      = max( 0, (int) $s['keep_revisions'] );
		$age       = max( 0, (int) ( $s['revisions_max_age_days'] ?? 0 ) );
		$total_rev = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_type = %s", 'revision' ) );
		if ( $total_rev > 0 ) {
			$parents = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(DISTINCT post_parent) FROM {$posts} WHERE post_type = %s", 'revision' ) );
			$by_keep = max( 0, $total_rev - $parents * $keep );
			if ( $age > 0 ) {
				$cut              = gmdate( 'Y-m-d H:i:s', time() - $age * DAY_IN_SECONDS );
				$by_age           = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_type = %s AND post_date_gmt < %s", 'revision', $cut ) );
				$out['revisions'] = min( $by_keep, $by_age );
			} else {
				$out['revisions'] = $by_keep;
			}
		}

		$posts_count = 0;
		if ( ! empty( $s['delete_auto_drafts'] ) ) {
			$keepd        = max( 0, (int) $s['keep_auto_drafts'] );
			$aged         = max( 0, (int) $s['auto_drafts_max_age_days'] );
			$totd         = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_status = %s", 'auto-draft' ) );
			$cut          = self::auto_draft_cutoff( $aged );
			$oldd         = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_status = %s AND post_date < %s", 'auto-draft', $cut ) );
			$posts_count += ( $keepd > 0 ) ? min( $oldd, max( 0, $totd - $keepd ) ) : $oldd;
		}
		$min_age = max( 0, (int) ( $s['trash_min_age_days'] ?? 0 ) );
		$cut_ts  = time() - $min_age * DAY_IN_SECONDS;
		$cut_dt  = gmdate( 'Y-m-d H:i:s', $cut_ts );
		if ( ! empty( $s['delete_trashed_posts'] ) ) {
			$posts_count += (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$posts} WHERE post_status = %s AND post_modified_gmt < %s AND post_type <> %s", 'trash', $min_age > 0 ? $cut_dt : '9999-12-31', 'attachment' ) );
		}
		if ( $posts_count > 0 ) {
			$out['posts'] = $posts_count;
		}

		$c = 0;
		foreach ( array(
			'delete_spam_comments'       => array( 'comment_approved', 'spam' ),
			'delete_trashed_comments'    => array( 'comment_approved', 'trash' ),
			'delete_unapproved_comments' => array( 'comment_approved', '0' ),
			'delete_pingbacks'           => array( 'comment_type', 'pingback' ),
			'delete_trackbacks'          => array( 'comment_type', 'trackback' ),
		) as $flag => $f ) {
			if ( empty( $s[ $flag ] ) ) {
				continue;
			}
			if ( $min_age > 0 && in_array( $f[1], array( 'spam', 'trash' ), true ) ) {
				$cmeta = $this->quote_identifier( $wpdb->commentmeta ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
				$c    += (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments} c LEFT JOIN {$cmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s WHERE c.comment_approved = %s AND ( CAST(m.meta_value AS UNSIGNED) < %d OR ( m.meta_value IS NULL AND c.comment_date_gmt < %s ) )", '_wp_trash_meta_time', $f[1], $cut_ts, $cut_dt ) );
				continue;
			}
			$c += (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$comments} WHERE {$f[0]} = %s", $f[1] ) );
		}
		if ( $c > 0 ) {
			$out['comments'] = $c;
		}

		if ( ! empty( $s['delete_all_transients'] ) ) {
			$out['transients'] = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_%', '_site_transient_%' ) );
		} elseif ( ! empty( $s['delete_expired_transients'] ) ) {
			$out['transients'] = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND CAST(option_value AS UNSIGNED) < %d", '_transient_timeout_%', '_site_transient_timeout_%', time() ) );
		}

		if ( ! empty( $s['delete_oembed_cache'] ) ) {
			$pm            = $this->quote_identifier( $wpdb->postmeta );
			$out['oembed'] = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pm} WHERE meta_key LIKE %s", '_oembed_%' ) );
		}

		if ( ! empty( $s['delete_unused_tags'] ) ) {
			$tt          = $this->quote_identifier( $wpdb->term_taxonomy );
			$trt         = $this->quote_identifier( $wpdb->term_relationships );
			$out['tags'] = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt} tt WHERE tt.taxonomy = %s AND NOT EXISTS ( SELECT 1 FROM {$trt} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id )", 'post_tag' ) );
		}

		if ( ! empty( $s['delete_orphan_elements'] ) ) {
			$pm = $this->quote_identifier( $wpdb->postmeta );
			$cm = $this->quote_identifier( $wpdb->commentmeta );
			$tr = $this->quote_identifier( $wpdb->term_relationships );
			$o  = (int) $this->estimate_var( "SELECT COUNT(*) FROM {$pm} pm LEFT JOIN {$posts} p ON p.ID = pm.post_id WHERE p.ID IS NULL" );
			$o += (int) $this->estimate_var( "SELECT COUNT(*) FROM {$cm} cm LEFT JOIN {$comments} c ON c.comment_ID = cm.comment_id WHERE c.comment_ID IS NULL" );
			// Relationships: the SAME rule the cleanup applies (taxonomies registered only for
			// content types, never links). Counting every relationship without a post promised
			// rows — link categories, terms hung on users — that the cleanup rightly leaves.
			$post_taxonomies = $this->post_object_taxonomies();
			if ( ! empty( $post_taxonomies ) ) {
				$ttq            = $this->quote_identifier( $wpdb->term_taxonomy );
				$lq             = $this->quote_identifier( $wpdb->links );
				$placeholders   = implode( ',', array_fill( 0, count( $post_taxonomies ), '%s' ) );
				$orphan_rel_sql = "SELECT COUNT(*) FROM {$tr} tr INNER JOIN {$ttq} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id LEFT JOIN {$posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL AND tt.taxonomy IN ({$placeholders}) AND NOT EXISTS ( SELECT 1 FROM {$lq} l WHERE l.link_id = tr.object_id )";
				$o             += (int) $this->estimate_var( $wpdb->prepare( $orphan_rel_sql, $post_taxonomies ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- back-quoted identifiers; the taxonomy names are bound.
			}
			foreach ( $this->orphan_object_sql() as $sql ) {
				$o += (int) $this->estimate_var( 'SELECT COUNT(*) FROM ( ' . $sql . ' ) AS o' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built by orphan_object_sql() from back-quoted identifiers and prepared values.
			}
			$out['orphans'] = $o;
		}

		if ( ! empty( $s['delete_duplicate_meta'] ) && ! $large ) {
			$dm     = 0;
			$tables = array(
				$wpdb->postmeta    => 'post_id',
				$wpdb->usermeta    => 'user_id',
				$wpdb->commentmeta => 'comment_id',
			);
			if ( isset( $wpdb->termmeta ) && ! empty( $wpdb->termmeta ) ) {
				$tables[ $wpdb->termmeta ] = 'term_id';
			}
			foreach ( $tables as $t => $obj ) {
				$tq  = $this->quote_identifier( $t );
				$tot = (int) $this->estimate_var( "SELECT COUNT(*) FROM {$tq}" );
				$grp = (int) $this->estimate_var( "SELECT COUNT(*) FROM (SELECT 1 FROM {$tq} GROUP BY {$obj}, meta_key, MD5(COALESCE(meta_value,'')) HAVING COUNT(*) > 0) d" );
				$dm += max( 0, $tot - $grp );
			}
			$out['duplicate_meta'] = $dm;
		}

		if ( ! empty( $s['delete_action_scheduler'] ) && self::has_action_scheduler() ) {
			$as_table                = $this->quote_identifier( $wpdb->prefix . 'actionscheduler_actions' );
			$as_age                  = max( 0, (int) ( $s['action_scheduler_max_age_days'] ?? 30 ) );
			$cut                     = gmdate( 'Y-m-d H:i:s', time() - $as_age * DAY_IN_SECONDS );
			$as_sql                  = "SELECT COUNT(*) FROM {$as_table} WHERE " . self::as_finished_where();
			$out['action_scheduler'] = (int) $this->estimate_var( $wpdb->prepare( $as_sql, $cut, $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed fragment with two placeholders, see as_finished_where().
		}

		// WooCommerce, and only when the store is actually running. Both counts are cheap and
		// exact; neither needs the approximate path large sites fall back to.
		if ( self::has_woocommerce() ) {
			if ( ! empty( $s['delete_wc_sessions'] ) ) {
				$sess_table         = $this->quote_identifier( $wpdb->prefix . 'woocommerce_sessions' ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
				$out['wc_sessions'] = (int) $this->estimate_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$sess_table} WHERE session_expiry < %d", time() ) );
			}
			if ( ! empty( $s['delete_wc_orphan_variations'] ) ) {
				$out['wc_variations'] = (int) $this->estimate_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$posts} v LEFT JOIN {$posts} p ON p.ID = v.post_parent
						 WHERE v.post_type = %s AND ( v.post_parent = 0 OR p.ID IS NULL OR p.post_type <> %s )",
						'product_variation',
						'product'
					)
				);
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// A count the database cut short read as 0, and a page of zeros said "Nothing to
		// remove" — on exactly the big sites with the most to remove. Callers ask
		// estimate_is_partial() and say the estimate is incomplete instead.
		$this->estimate_partial = function_exists( 'wpoptimizing_db_failures_take' ) && ! empty( wpoptimizing_db_failures_take() );

		return $out;
	}

	/**
	 * Read-only breakdown of content grouped by post type.
	 *
	 * Returns one row per used post type with the number of live entries, revisions
	 * and auto-drafts, plus the total post-meta rows attached — so the user can see
	 * where database weight concentrates by content type. Revisions and auto-drafts
	 * are attributed to their real type (revisions via their parent). Read-only and
	 * computed on demand (the meta join can be heavy on very large sites).
	 *
	 * @return array[] Rows sorted by total desc: each has keys
	 *                 type, label, entries, revisions, auto_drafts, meta, total.
	 */
	public function content_by_post_type() {
		global $wpdb;
		// Cache the read-only breakdown for 5 minutes — it runs on every view of the
		// "By content type" sub-tab and the queries are non-trivial on big sites.
		$cached = get_transient( 'wpoptimizing_by_type' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// On a huge DB the postmeta↔posts JOIN is the heaviest part; skip it (counts
		// stay 0, the UI flags it) and bound the rest with a statement timeout.
		$large = function_exists( 'wpoptimizing_scan_large_site' ) && wpoptimizing_scan_large_site();
		if ( function_exists( 'wpoptimizing_db_statement_timeout' ) ) {
			wpoptimizing_db_statement_timeout( 15 );
		}
		$posts = $this->quote_identifier( $wpdb->posts );    // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$pm    = $this->quote_identifier( $wpdb->postmeta ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows  = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		// Live entries + auto-drafts per real post type (revisions excluded here).
		$base   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type AS ptype,
					SUM( CASE WHEN post_status = %s THEN 1 ELSE 0 END ) AS auto_drafts,
					SUM( CASE WHEN post_status <> %s THEN 1 ELSE 0 END ) AS entries
				FROM {$posts} WHERE post_type <> %s GROUP BY post_type",
				'auto-draft',
				'auto-draft',
				'revision'
			),
			ARRAY_A
		);
		$failed = '' !== (string) $wpdb->last_error;
		foreach ( (array) $base as $r ) {
			$type          = (string) $r['ptype'];
			$rows[ $type ] = array(
				'type'        => $type,
				'entries'     => (int) $r['entries'],
				'auto_drafts' => (int) $r['auto_drafts'],
				'revisions'   => 0,
				'meta'        => 0,
			);
		}

		// Revisions attributed to their parent's post type.
		$revs   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_type AS ptype, COUNT(*) AS n
				FROM {$posts} r JOIN {$posts} p ON p.ID = r.post_parent
				WHERE r.post_type = %s GROUP BY p.post_type",
				'revision'
			),
			ARRAY_A
		);
		$failed = $failed || '' !== (string) $wpdb->last_error;
		foreach ( (array) $revs as $r ) {
			$type = (string) $r['ptype'];
			if ( ! isset( $rows[ $type ] ) ) {
				$rows[ $type ] = array(
					'type'        => $type,
					'entries'     => 0,
					'auto_drafts' => 0,
					'revisions'   => 0,
					'meta'        => 0,
				);
			}
			$rows[ $type ]['revisions'] = (int) $r['n'];
		}

		// Post-meta rows per post type — the heaviest query; skipped on large sites.
		if ( ! $large ) {
			$metas  = $wpdb->get_results(
				"SELECT p.post_type AS ptype, COUNT(*) AS n
				FROM {$pm} m JOIN {$posts} p ON p.ID = m.post_id GROUP BY p.post_type",
				ARRAY_A
			);
			$failed = $failed || '' !== (string) $wpdb->last_error;
			foreach ( (array) $metas as $r ) {
				$type = (string) $r['ptype'];
				if ( ! isset( $rows[ $type ] ) ) {
					$rows[ $type ] = array(
						'type'        => $type,
						'entries'     => 0,
						'auto_drafts' => 0,
						'revisions'   => 0,
						'meta'        => 0,
					);
				}
				$rows[ $type ]['meta'] = (int) $r['n'];
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $rows as $type => &$row ) {
			$obj          = get_post_type_object( $type );
			$row['label'] = ( $obj && isset( $obj->labels->singular_name ) && '' !== $obj->labels->singular_name ) ? $obj->labels->singular_name : $type;
			$row['total'] = $row['entries'] + $row['auto_drafts'] + $row['revisions'] + $row['meta'];
		}
		unset( $row );

		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $b['total'] <=> (int) $a['total'];
			}
		);
		// A table with a query cut short is shown once, not cached for five minutes as if whole.
		if ( ! $failed ) {
			set_transient( 'wpoptimizing_by_type', $rows, 5 * MINUTE_IN_SECONDS );
		}
		return $rows;
	}

	/**
	 * Toggle/numeric settings for each preset profile. Pure (no labels, no WP).
	 *
	 * `delete_all_transients` is intentionally 0 in every profile — it removes
	 * non-expired transients too, which can break sessions/carts/rate-limits.
	 *
	 * @return array<string,array<string,int>>
	 */
	public static function profile_settings_map() {
		return array(
			'safe'        => array(
				'keep_revisions'              => 5,
				'keep_auto_drafts'            => 0,
				'auto_drafts_max_age_days'    => 0,
				'revisions_max_age_days'      => 0,
				'delete_auto_drafts'          => 1,
				'delete_trashed_posts'        => 1,
				'delete_spam_comments'        => 1,
				'delete_trashed_comments'     => 1,
				'delete_unapproved_comments'  => 0,
				'delete_pingbacks'            => 0,
				'delete_trackbacks'           => 0,
				'delete_expired_transients'   => 1,
				'delete_all_transients'       => 0,
				'delete_oembed_cache'         => 1,
				'delete_unused_tags'          => 0,
				'delete_orphan_elements'      => 0,
				'delete_duplicate_meta'       => 0,
				'delete_action_scheduler'     => 0,
				'delete_wc_sessions'          => 0,
				'delete_wc_orphan_variations' => 0,
				'optimize_tables'             => 1,
			),
			'blog'        => array(
				'keep_revisions'              => 3,
				'keep_auto_drafts'            => 0,
				'auto_drafts_max_age_days'    => 0,
				'revisions_max_age_days'      => 0,
				'delete_auto_drafts'          => 1,
				'delete_trashed_posts'        => 1,
				'delete_spam_comments'        => 1,
				'delete_trashed_comments'     => 1,
				'delete_unapproved_comments'  => 0,
				'delete_pingbacks'            => 1,
				'delete_trackbacks'           => 1,
				'delete_expired_transients'   => 1,
				'delete_all_transients'       => 0,
				'delete_oembed_cache'         => 1,
				'delete_unused_tags'          => 0,
				'delete_orphan_elements'      => 0,
				'delete_duplicate_meta'       => 0,
				'delete_action_scheduler'     => 0,
				'delete_wc_sessions'          => 0,
				'delete_wc_orphan_variations' => 0,
				'optimize_tables'             => 1,
			),
			'woocommerce' => array(
				'keep_revisions'              => 5,
				'keep_auto_drafts'            => 0,
				'auto_drafts_max_age_days'    => 0,
				'revisions_max_age_days'      => 0,
				'delete_auto_drafts'          => 1,
				'delete_trashed_posts'        => 1,
				'delete_spam_comments'        => 1,
				'delete_trashed_comments'     => 1,
				'delete_unapproved_comments'  => 0,
				'delete_pingbacks'            => 1,
				'delete_trackbacks'           => 1,
				'delete_expired_transients'   => 1,
				'delete_all_transients'       => 0,
				'delete_oembed_cache'         => 1,
				'delete_unused_tags'          => 0,
				'delete_orphan_elements'      => 1,
				'delete_duplicate_meta'       => 0,
				'delete_action_scheduler'     => 1,
				'delete_wc_sessions'          => 1,
				'delete_wc_orphan_variations' => 1,
				'optimize_tables'             => 1,
			),
			'membership'  => array(
				'keep_revisions'              => 5,
				'keep_auto_drafts'            => 0,
				'auto_drafts_max_age_days'    => 0,
				'revisions_max_age_days'      => 0,
				'delete_auto_drafts'          => 1,
				'delete_trashed_posts'        => 1,
				'delete_spam_comments'        => 1,
				'delete_trashed_comments'     => 1,
				'delete_unapproved_comments'  => 0,
				'delete_pingbacks'            => 0,
				'delete_trackbacks'           => 0,
				'delete_expired_transients'   => 1,
				'delete_all_transients'       => 0,
				'delete_oembed_cache'         => 1,
				'delete_unused_tags'          => 0,
				'delete_orphan_elements'      => 0,
				'delete_duplicate_meta'       => 0,
				'delete_action_scheduler'     => 0,
				'delete_wc_sessions'          => 0,
				'delete_wc_orphan_variations' => 0,
				'optimize_tables'             => 1,
			),
			'aggressive'  => array(
				'keep_revisions'              => 2,
				'keep_auto_drafts'            => 0,
				'auto_drafts_max_age_days'    => 0,
				'revisions_max_age_days'      => 0,
				'delete_auto_drafts'          => 1,
				'delete_trashed_posts'        => 1,
				'delete_spam_comments'        => 1,
				'delete_trashed_comments'     => 1,
				'delete_unapproved_comments'  => 1,
				'delete_pingbacks'            => 1,
				'delete_trackbacks'           => 1,
				'delete_expired_transients'   => 1,
				'delete_all_transients'       => 0,
				'delete_oembed_cache'         => 1,
				'delete_unused_tags'          => 1,
				'delete_orphan_elements'      => 1,
				'delete_duplicate_meta'       => 1,
				'delete_action_scheduler'     => 1,
				'delete_wc_sessions'          => 0,
				'delete_wc_orphan_variations' => 0,
				'optimize_tables'             => 1,
			),
		);
	}

	/**
	 * Performance flags recommended per profile.
	 *
	 * These are applied alongside the cleanup-task settings when a profile is
	 * activated via "Apply profile". They are NOT stored in the cleanup settings
	 * option — they are one-time actions (install drop-ins, write heartbeat option).
	 *
	 * Keys:
	 *   enable_object_cache (bool)   — install the persistent object-cache drop-in.
	 *   enable_query_dedup  (bool)   — install the query-deduplication db.php drop-in.
	 *   enable_admin_counts (bool)   — cache dashboard comment counts (admin_counts).
	 *   heartbeat           (string) — 'default'|'slow'|'off'; written to cache settings.
	 *   speculation         (string) — front-end speculative loading: 'off'|'conservative'|'eager'.
	 *   image_opt           (bool)   — content image loading optimization (fetchpriority/lazy).
	 *   server_timing       (bool)   — front-end Server-Timing diagnostic header.
	 *
	 * The front-end keys (speculation/image_opt/server_timing) are applied to the
	 * `wpoptimizing_frontend_perf` option inside apply_profile(), so selecting a
	 * profile anywhere — including the welcome wizard — configures them too.
	 *
	 * @return array<string,array{enable_object_cache:bool,enable_query_dedup:bool,enable_admin_counts:bool,heartbeat:string,speculation:string,image_opt:bool,server_timing:bool,resource_hints:bool,google_fonts:bool}>
	 */
	public static function profile_performance_map() {
		return array(
			'safe'        => array(
				'enable_object_cache' => true,
				'enable_query_dedup'  => true,
				'enable_admin_counts' => true,
				'heartbeat'           => 'default',
				'speculation'         => 'conservative',
				'image_opt'           => true,
				'server_timing'       => false,
				'resource_hints'      => true,
				'google_fonts'        => true,
			),
			'blog'        => array(
				'enable_object_cache' => true,
				'enable_query_dedup'  => true,
				'enable_admin_counts' => true,
				'heartbeat'           => 'default',
				'speculation'         => 'eager',
				'image_opt'           => true,
				'server_timing'       => false,
				'resource_hints'      => true,
				'google_fonts'        => true,
			),
			'woocommerce' => array(
				'enable_object_cache' => true,
				'enable_query_dedup'  => true,
				'enable_admin_counts' => true,
				'heartbeat'           => 'slow',
				'speculation'         => 'conservative',
				'image_opt'           => true,
				'server_timing'       => false,
				'resource_hints'      => true,
				'google_fonts'        => true,
			),
			'membership'  => array(
				'enable_object_cache' => true,
				'enable_query_dedup'  => true,
				'enable_admin_counts' => true,
				'heartbeat'           => 'slow',
				'speculation'         => 'conservative',
				'image_opt'           => true,
				'server_timing'       => false,
				'resource_hints'      => true,
				'google_fonts'        => true,
			),
			'aggressive'  => array(
				'enable_object_cache' => true,
				'enable_query_dedup'  => true,
				'enable_admin_counts' => true,
				'heartbeat'           => 'slow',
				'speculation'         => 'eager',
				'image_opt'           => true,
				'server_timing'       => true,
				'resource_hints'      => true,
				'google_fonts'        => true,
			),
		);
	}

	/**
	 * Core tweaks (see wpoptimizing_core_tweaks_registry()) that travel with each
	 * profile, applied to `wpoptimizing_core_tweaks` inside apply_profile() so the
	 * welcome wizard and Quick Profiles enable sensible ones too. Each list holds
	 * the tweak ids to enable for that profile.
	 *
	 * Only "safe" and selected "moderate" tweaks are auto-enabled. WooCommerce/
	 * membership profiles also skip jquery_migrate and front-end Dashicons, since
	 * their plugin ecosystems are more likely to rely on legacy jQuery or the icon
	 * font.
	 *
	 * @return array<string,string[]>
	 */
	public static function profile_core_tweaks_map() {
		$safe = array( 'emojis', 'generator', 'rsd_link', 'wlwmanifest', 'shortlink', 'self_pingbacks' );
		return array(
			'safe'        => $safe,
			'blog'        => array_merge( $safe, array( 'jquery_migrate', 'embeds', 'remote_block_patterns', 'rest_head_links' ) ),
			'woocommerce' => array_merge( $safe, array( 'embeds', 'remote_block_patterns', 'rest_head_links', 'cart_fragments' ) ),
			'membership'  => array_merge( $safe, array( 'embeds', 'remote_block_patterns', 'rest_head_links' ) ),
			'aggressive'  => array_merge( $safe, array( 'jquery_migrate', 'dashicons_frontend', 'embeds', 'remote_block_patterns', 'rest_head_links', 'cart_fragments' ) ),
		);
	}

	/**
	 * Core tweaks that no profile enables — the ones offered as independent opt-in
	 * toggles in the welcome wizard (currently the "advanced" tier: disable feeds /
	 * sitemap). Computed from the registry minus the union of every profile's set,
	 * so a future tweak left out of the profiles surfaces in the wizard automatically.
	 *
	 * @return string[]
	 */
	public static function tweaks_not_in_any_profile() {
		if ( ! function_exists( 'wpoptimizing_core_tweaks_registry' ) ) {
			return array();
		}
		$in_profile = array();
		foreach ( self::profile_core_tweaks_map() as $ids ) {
			$in_profile = array_merge( $in_profile, (array) $ids );
		}
		$in_profile = array_unique( $in_profile );
		$candidates = array_diff( array_keys( wpoptimizing_core_tweaks_offered() ), $in_profile );
		// Email-group tweaks have their own Settings section; keep them out of the wizard.
		$registry   = wpoptimizing_core_tweaks_registry();
		$candidates = array_filter(
			$candidates,
			static function ( $id ) use ( $registry ) {
				return 'head' === ( isset( $registry[ $id ]['group'] ) ? $registry[ $id ]['group'] : 'head' );
			}
		);
		return array_values( $candidates );
	}

	/**
	 * Detect the kind of site from the plugins that are active, using stable
	 * class/constant/function markers (no fragile basename lists, no need to load
	 * wp-admin/includes/plugin.php). Filterable via `wpoptimizing_site_signals`.
	 *
	 * @return array{woocommerce:bool,membership:bool,seo:bool,caching:bool,image_optim:bool}
	 */
	public static function detect_site_signals() {
		$signals = array(
			'woocommerce' => class_exists( 'WooCommerce' ),
			'membership'  => (
				defined( 'MEPR_VERSION' )            // MemberPress.
				|| defined( 'PMPRO_VERSION' )         // Paid Memberships Pro.
				|| defined( 'RCP_PLUGIN_VERSION' )    // Restrict Content Pro.
				|| defined( 'LEARNDASH_VERSION' )     // LearnDash.
				|| class_exists( 'UM' )               // Ultimate Member.
				|| class_exists( 'LLMS' )             // LifterLMS.
				|| function_exists( 'wc_memberships' )// WooCommerce Memberships.
				|| class_exists( 'SwpmSettings' )     // Simple Membership.
			),
			'seo'         => (
				defined( 'WPSEO_VERSION' )            // Yoast SEO.
				|| class_exists( 'RankMath' )         // Rank Math.
				|| defined( 'AIOSEO_VERSION' )        // All in One SEO.
				|| function_exists( 'aioseo' )
				|| defined( 'SEOPRESS_VERSION' )      // SEOPress.
			),
			// These two used to be private lists of the same competitors the conflict detectors
			// knew, and they drifted: this one looked for SHORTPIXEL_PLUGIN_VERSION while
			// image-frontend.php looked for SHORTPIXEL_PLUGIN_FILE. Both now read the registry,
			// so a plugin is registered once and every consumer sees it.
			'caching'     => ( '' !== wpoptimizing_compat_owner( 'page_cache' ) || '' !== wpoptimizing_compat_owner( 'minify' ) ),
			'image_optim' => ( '' !== wpoptimizing_compat_owner( 'image_optimize' ) ),
		);

		/**
		 * Filters the detected site signals used to recommend a cleanup profile.
		 *
		 * @since 0.1.0
		 * @param array $signals Boolean flags keyed by category.
		 */
		return array_map( 'boolval', (array) apply_filters( 'wpoptimizing_site_signals', $signals ) );
	}

	/**
	 * Recommend the cleanup profile that best fits the detected signals. Pure
	 * (unit-testable). WooCommerce and membership/LMS sites get their tailored
	 * preset; everything else gets the conservative "safe" recommended default.
	 *
	 * @param array $signals Output of detect_site_signals() (or a subset).
	 * @return string Profile key.
	 */
	public static function recommend_profile( array $signals ) {
		if ( ! empty( $signals['woocommerce'] ) ) {
			return 'woocommerce';
		}
		if ( ! empty( $signals['membership'] ) ) {
			return 'membership';
		}
		return 'safe';
	}

	/**
	 * Tailored, reassuring notes for the detected environment, shown next to the
	 * profile chooser so the user knows WPOptimizing fits alongside their stack.
	 *
	 * @param array $signals Output of detect_site_signals().
	 * @return string[] Human-readable notes (already translated).
	 */
	public static function recommendation_notes( array $signals ) {
		$notes = array();
		if ( ! empty( $signals['seo'] ) ) {
			$notes[] = __( 'An SEO plugin is active. If it already serves your sitemap, you can turn on the “Disable the WordPress sitemap” core tweak — just confirm its sitemap is enabled first.', 'wpoptimizing' );
		}
		if ( ! empty( $signals['caching'] ) ) {
			$notes[] = __( 'A caching plugin was detected. WPOptimizing never touches another plugin’s object-cache.php / db.php drop-in, so keep managing page caching from that plugin.', 'wpoptimizing' );
		}
		if ( ! empty( $signals['image_optim'] ) ) {
			$notes[] = __( 'An image-optimization plugin is active. WPOptimizing’s image loading only adds attributes and yields to it, so you can leave image work to that plugin.', 'wpoptimizing' );
		}
		return $notes;
	}

	/**
	 * Alert thresholds recommended per profile, used by the network wizard to
	 * auto-populate the network alert options when a profile is applied.
	 *
	 * Keys match the WPOPTIMIZING_NET_ALERT_* option names (without the prefix):
	 *   alert_reclaim_mb  — reclaimable-space threshold in MB  (0 = disabled)
	 *   alert_stale_days  — stale-scan threshold in days       (0 = disabled)
	 *   alert_autoload_kb — heavy-autoload threshold in KB     (0 = disabled)
	 *
	 * Conservative profiles use looser triggers; aggressive/agency profiles
	 * use tighter ones so problems are caught sooner on managed networks.
	 *
	 * @return array<string,array{alert_reclaim_mb:int,alert_stale_days:int,alert_autoload_kb:int}>
	 */
	public static function profile_alert_thresholds_map() {
		return array(
			'safe'        => array(
				'alert_reclaim_mb'  => 100,
				'alert_stale_days'  => 14,
				'alert_autoload_kb' => 2048,
			),
			'blog'        => array(
				'alert_reclaim_mb'  => 50,
				'alert_stale_days'  => 14,
				'alert_autoload_kb' => 1024,
			),
			'woocommerce' => array(
				'alert_reclaim_mb'  => 50,
				'alert_stale_days'  => 7,
				'alert_autoload_kb' => 1024,
			),
			'membership'  => array(
				'alert_reclaim_mb'  => 100,
				'alert_stale_days'  => 14,
				'alert_autoload_kb' => 2048,
			),
			'aggressive'  => array(
				'alert_reclaim_mb'  => 25,
				'alert_stale_days'  => 7,
				'alert_autoload_kb' => 512,
			),
		);
	}

	/**
	 * Preset profiles with human labels/descriptions for the settings UI.
	 *
	 * @return array<string,array{label:string,description:string,settings:array<string,int>,performance:array}>
	 */
	public static function cleanup_profiles() {
		$map  = self::profile_settings_map();
		$perf = self::profile_performance_map();
		$meta = array(
			'safe'        => array(
				'label'       => __( 'Safe', 'wpoptimizing' ),
				'description' => __( 'Conservative defaults: keeps 5 revisions and removes auto-drafts, trashed posts and comments, spam, expired transients and the oEmbed cache, then optimizes the tables.', 'wpoptimizing' ),
			),
			'blog'        => array(
				'label'       => __( 'Blog', 'wpoptimizing' ),
				'description' => __( 'Like Safe, plus pingbacks/trackbacks; keeps tags and fewer revisions.', 'wpoptimizing' ),
			),
			'woocommerce' => array(
				'label'       => __( 'WooCommerce', 'wpoptimizing' ),
				'description' => __( 'Keeps 5 revisions and product tags; also removes pingbacks and trackbacks, orphan elements, old Action Scheduler logs, expired customer sessions and orphaned variations. Never touches non-expired transients (carts/sessions).', 'wpoptimizing' ),
			),
			'membership'  => array(
				'label'       => __( 'Membership', 'wpoptimizing' ),
				'description' => __( 'Careful preset: keeps moderation queue, pingbacks and member metadata intact.', 'wpoptimizing' ),
			),
			'aggressive'  => array(
				'label'       => __( 'Agency (maximum)', 'wpoptimizing' ),
				'description' => __( 'Most thorough: also unapproved comments, unused tags and orphaned metadata. Still never deletes non-expired transients.', 'wpoptimizing' ),
			),
		);

		$out = array();
		foreach ( $map as $key => $settings ) {
			$out[ $key ] = array(
				'label'       => isset( $meta[ $key ]['label'] ) ? $meta[ $key ]['label'] : $key,
				'description' => isset( $meta[ $key ]['description'] ) ? $meta[ $key ]['description'] : '',
				'settings'    => $settings,
				'performance' => isset( $perf[ $key ] ) ? $perf[ $key ] : array(),
			);
		}
		return $out;
	}

	/**
	 * Apply a preset profile over the current settings and persist it.
	 *
	 * Preserves `generate_report` and `excluded_optimize_tables`; the schedule is
	 * not touched.
	 *
	 * @param string $key Profile key.
	 * @return bool True if applied, false if the key is unknown.
	 */
	public function apply_profile( $key ) {
		$key = sanitize_key( (string) $key );
		$map = self::profile_settings_map();
		if ( ! isset( $map[ $key ] ) ) {
			return false;
		}

		$current  = $this->get_settings();
		$settings = wp_parse_args( $map[ $key ], $current );
		// Keep user-controlled extras as they were.
		$settings['generate_report']          = isset( $current['generate_report'] ) ? (int) $current['generate_report'] : 1;
		$settings['excluded_optimize_tables'] = isset( $current['excluded_optimize_tables'] ) ? $current['excluded_optimize_tables'] : array();

		$this->save_settings( $settings );
		update_option( 'wpoptimizing_general_cleanup_profile', $key, false );

		$perf = self::profile_performance_map();
		$pf   = isset( $perf[ $key ] ) ? $perf[ $key ] : array();

		// Front-end performance preferences travel with the profile so selecting one
		// (Settings button OR the welcome wizard) leaves the public-site features
		// configured. These are plain option writes (no drop-ins), safe here; the
		// object cache / dedup install side-effects stay in the UI/wizard handlers.
		if ( function_exists( 'wpoptimizing_frontend_perf_sanitize' ) ) {
			update_option(
				'wpoptimizing_frontend_perf',
				// Merged over what is saved: a profile sets these five, and writing only them wiped
				// the preload list, lazy embeds and every other front-end setting it does not cover.
				wpoptimizing_frontend_perf_sanitize(
					array_merge(
						function_exists( 'wpoptimizing_frontend_perf_settings' ) ? wpoptimizing_frontend_perf_settings() : array(),
						array(
							'speculation'    => isset( $pf['speculation'] ) ? (string) $pf['speculation'] : 'off',
							'image_opt'      => ! empty( $pf['image_opt'] ) ? 1 : 0,
							'server_timing'  => ! empty( $pf['server_timing'] ) ? 1 : 0,
							'resource_hints' => ! empty( $pf['resource_hints'] ) ? 1 : 0,
							'google_fonts'   => ! empty( $pf['google_fonts'] ) ? 1 : 0,
						)
					)
				),
				true
			);
		}

		// Core tweaks (disable unused WordPress features) also travel with the profile,
		// so the wizard and Quick Profiles enable sensible ones. Plain option write,
		// fully reversible; sanitized against the registry. Advanced tweaks (feeds/
		// sitemap) are never in a profile — see profile_core_tweaks_map().
		if ( function_exists( 'wpoptimizing_core_tweaks_sanitize' ) ) {
			$tweaks_map = self::profile_core_tweaks_map();
			update_option(
				'wpoptimizing_core_tweaks',
				array_values( array_intersect( wpoptimizing_core_tweaks_sanitize( isset( $tweaks_map[ $key ] ) ? $tweaks_map[ $key ] : array() ), array_keys( wpoptimizing_core_tweaks_offered() ) ) ),
				true
			);
		}

		// Admin-side optimizations (comment-count cache + Heartbeat control) also
		// travel with the profile so Quick Profiles AND the welcome wizard configure
		// them. Plain option writes with no drop-in, safe here; object cache / query
		// dedup remain explicit drop-in installs handled by the UI/wizard checkboxes.
		$cache_settings = get_option( 'wpoptimizing_cache_settings', array() );
		if ( ! is_array( $cache_settings ) ) {
			$cache_settings = array();
		}
		$cache_settings['admin_counts'] = ! empty( $pf['enable_admin_counts'] ) ? 1 : 0;
		$hb_mode                        = isset( $pf['heartbeat'] ) ? (string) $pf['heartbeat'] : 'default';
		$hb_mode                        = in_array( $hb_mode, array( 'default', 'slow', 'off' ), true ) ? $hb_mode : 'default';
		// The preset sets every zone (admin-cache.php), so the profile lands the same way whatever
		// zones were customised before.
		$cache_settings = function_exists( 'wpoptimizing_heartbeat_with_preset' ) ? wpoptimizing_heartbeat_with_preset( $cache_settings, $hb_mode ) : array_merge( $cache_settings, array( 'heartbeat' => $hb_mode ) );
		update_option( 'wpoptimizing_cache_settings', $cache_settings, false );

		return true;
	}

	/**
	 * Return current cleanup schedule.
	 *
	 * @return string
	 */
	public function get_schedule() {
		$schedule = (string) get_option( 'wpoptimizing_general_cleanup_schedule', 'manual' );
		if ( ! in_array( $schedule, self::get_allowed_schedules(), true ) ) {
			$schedule = 'manual';
		}
		return $schedule;
	}

	/**
	 * Return stored schedule time config (hour, minute, day_of_week, day_of_month).
	 *
	 * @return array<string,int>
	 */
	public function get_schedule_config() {
		$defaults = array(
			'hour'         => 3,
			'minute'       => 0,
			'day_of_week'  => 1,
			'day_of_month' => 1,
		);
		$saved    = get_option( 'wpoptimizing_gc_schedule_config', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( $defaults, $saved );
	}

	/**
	 * Clamp a schedule time config to valid ranges. Pure (no side effects) so the save
	 * handler AND the settings-import sanitizer can share one source of truth.
	 *
	 * @param array<string,int> $config Schedule time config.
	 * @return array<string,int>
	 */
	public static function sanitize_schedule_config( array $config ) {
		return array(
			'hour'         => max( 0, min( 23, (int) ( $config['hour'] ?? 3 ) ) ),
			'minute'       => max( 0, min( 59, (int) ( $config['minute'] ?? 0 ) ) ),
			'day_of_week'  => max( 0, min( 6, (int) ( $config['day_of_week'] ?? 1 ) ) ),
			'day_of_month' => max( 1, min( 28, (int) ( $config['day_of_month'] ?? 1 ) ) ),
		);
	}

	/**
	 * Persist schedule time config.
	 *
	 * @param array<string,int> $config Schedule time config.
	 * @return void
	 */
	public function save_schedule_config( array $config ) {
		update_option( 'wpoptimizing_gc_schedule_config', self::sanitize_schedule_config( $config ), false );
	}

	/**
	 * Canonical list of schedulable cleanup task keys (order = run order).
	 *
	 * @return string[]
	 */
	public static function task_keys() {
		return array(
			'revisions',
			'auto_drafts',
			'trashed_posts',
			'comments',
			'transients',
			'oembed',
			'unused_tags',
			'orphans',
			'duplicate_meta',
			'action_scheduler',
			'woocommerce',
			'optimize_tables',
		);
	}

	/**
	 * Allowed per-task frequency values.
	 *
	 * @return string[]
	 */
	public static function task_frequencies() {
		return array( 'default', 'daily', 'weekly', 'monthly', 'never' );
	}

	/**
	 * Seconds for a per-task frequency (0 = "run on every scheduled tick").
	 *
	 * @param string $freq Frequency key.
	 * @return int
	 */
	public static function task_interval( $freq ) {
		switch ( $freq ) {
			case 'daily':
				return DAY_IN_SECONDS;
			case 'weekly':
				return WEEK_IN_SECONDS;
			case 'monthly':
				return 30 * DAY_IN_SECONDS;
		}
		return 0;
	}

	/**
	 * Pure due-check for a task. Manual runs always run; scheduled runs honour the
	 * per-task frequency vs the last time the task ran. 'default' = every tick,
	 * 'never' = skip on schedule.
	 *
	 * @param string $freq      Frequency key.
	 * @param int    $last_run  Last run timestamp.
	 * @param int    $now       Current timestamp.
	 * @param bool   $scheduled Whether this is a scheduled (cron) run.
	 * @return bool
	 */
	public static function is_task_due( $freq, $last_run, $now, $scheduled ) {
		if ( ! $scheduled ) {
			return true;
		}
		if ( 'never' === $freq ) {
			return false;
		}
		if ( 'default' === $freq || '' === $freq ) {
			return true;
		}
		$interval = self::task_interval( $freq );
		if ( $interval <= 0 ) {
			return true;
		}
		// 5-minute margin so a tick firing at ~the same clock time isn't skipped.
		return ( (int) $now - (int) $last_run ) >= ( $interval - 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Per-task frequencies (option-backed), normalized to the allowed set.
	 *
	 * @return array<string,string>
	 */
	public function get_task_schedules() {
		$saved = get_option( 'wpoptimizing_gc_task_schedules', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		$out = array();
		foreach ( self::task_keys() as $k ) {
			$v         = isset( $saved[ $k ] ) ? (string) $saved[ $k ] : 'default';
			$out[ $k ] = in_array( $v, self::task_frequencies(), true ) ? $v : 'default';
		}
		return $out;
	}

	/**
	 * Normalize a per-task frequency map to the canonical keys + allowed values. Pure,
	 * so the save handler AND the settings-import sanitizer share one source of truth.
	 *
	 * @param array<string,string> $map task_key => frequency.
	 * @return array<string,string>
	 */
	public static function sanitize_task_schedules( array $map ) {
		$clean = array();
		foreach ( self::task_keys() as $k ) {
			$v           = isset( $map[ $k ] ) ? sanitize_key( (string) $map[ $k ] ) : 'default';
			$clean[ $k ] = in_array( $v, self::task_frequencies(), true ) ? $v : 'default';
		}
		return $clean;
	}

	/**
	 * Persist per-task frequencies.
	 *
	 * @param array<string,string> $map task_key => frequency.
	 * @return void
	 */
	public function save_task_schedules( array $map ) {
		update_option( 'wpoptimizing_gc_task_schedules', self::sanitize_task_schedules( $map ), false );
	}

	/**
	 * Calculate the Unix timestamp for the first cron event, anchored to the
	 * configured time/day in the site's local timezone.
	 *
	 * @param string            $schedule Cron recurrence key.
	 * @param array<string,int> $config   Schedule time config.
	 * @return int Unix timestamp (always in the future).
	 */
	private function calculate_first_run( $schedule, array $config ) {
		if ( 'hourly' === $schedule ) {
			return time() + HOUR_IN_SECONDS;
		}

		$tz   = wp_timezone();
		$now  = new DateTime( 'now', $tz );
		$hour = (int) ( $config['hour'] ?? 3 );
		$min  = (int) ( $config['minute'] ?? 0 );

		if ( 'daily' === $schedule || 'twicedaily' === $schedule ) {
			$next = clone $now;
			$next->setTime( $hour, $min, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 day' );
			}
			return $next->getTimestamp();
		}

		if ( 'weekly' === $schedule ) {
			$dow  = (int) ( $config['day_of_week'] ?? 1 ); // 0=Sun..6=Sat
			$next = clone $now;
			$next->setTime( $hour, $min, 0 );
			$diff = ( $dow - (int) $next->format( 'w' ) + 7 ) % 7;
			if ( 0 === $diff && $next <= $now ) {
				$diff = 7;
			}
			if ( $diff > 0 ) {
				$next->modify( "+{$diff} days" );
			}
			return $next->getTimestamp();
		}

		if ( 'monthly' === $schedule ) {
			$dom  = (int) ( $config['day_of_month'] ?? 1 );
			$next = clone $now;
			$next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $dom );
			$next->setTime( $hour, $min, 0 );
			if ( $next <= $now ) {
				$next->modify( '+1 month' );
				$next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), $dom );
			}
			return $next->getTimestamp();
		}

		return time() + HOUR_IN_SECONDS;
	}

	/**
	 * Persist and apply cleanup schedule.
	 *
	 * @param string            $schedule Cron schedule.
	 * @param array<string,int> $config   Optional schedule time config (hour, minute, day_of_week, day_of_month).
	 * @return void
	 */
	public function update_schedule( $schedule, array $config = array() ) {
		$hook     = 'wpoptimizing_general_cleanup_cron';
		$schedule = in_array( $schedule, self::get_allowed_schedules(), true ) ? $schedule : 'manual';

		update_option( 'wpoptimizing_general_cleanup_schedule', $schedule, true );
		if ( ! empty( $config ) ) {
			$this->save_schedule_config( $config );
		}

		$next = wp_next_scheduled( $hook );
		while ( $next ) {
			wp_unschedule_event( $next, $hook );
			$next = wp_next_scheduled( $hook );
		}

		if ( 'manual' === $schedule ) {
			return;
		}

		$first_run = $this->calculate_first_run( $schedule, $this->get_schedule_config() );
		wp_schedule_event( $first_run, $schedule, $hook );
	}

	/**
	 * Return available tables for optimization exclusion.
	 *
	 * @return string[]
	 */
	public function get_optimizable_tables() {
		global $wpdb;
		$tables = $wpdb->get_col( 'SHOW TABLES' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! is_array( $tables ) ) {
			return array();
		}
		$out = array();
		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( '' === $table ) {
				continue;
			}
			// Ours at all: never another application's tables sharing the database.
			if ( '' !== $wpdb->prefix && 0 !== strpos( $table, $wpdb->prefix ) ) {
				continue;
			}
			// And ours on THIS site. The test above is not that test on a network: the main
			// site's $wpdb->prefix is the base prefix, so "wp_" also matches "wp_2_posts" and
			// every other sub-site's tables. Without this, an administrator of the main site —
			// who need not be a network administrator — optimises every customer's tables and
			// sees all of their names in the exclusion picker.
			//
			// The Tables tab has enforced this since the previous audit; this path did not, so
			// the boundary was a property of one screen rather than of the plugin.
			if ( function_exists( 'wpoptimizing_table_belongs_to_current_blog' )
				&& ! wpoptimizing_table_belongs_to_current_blog( $table ) ) {
				continue;
			}
			$out[] = $table;
		}
		sort( $out );
		return $out;
	}

	/**
	 * Get last cleanup report.
	 *
	 * @return array<string,mixed>
	 */
	public function get_last_report() {
		$report = get_option( 'wpoptimizing_general_cleanup_last_report', array() );
		return is_array( $report ) ? $report : array();
	}

	/**
	 * Get stored cleanup report history.
	 *
	 * @param int $limit Max reports returned.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_report_history( $limit = 20 ) {
		$history = wpoptimizing_blob_get( 'cleanup-reports', 'wpoptimizing_general_cleanup_reports', array() );
		if ( ! is_array( $history ) ) {
			return array();
		}

		$out = array();
		foreach ( $history as $item ) {
			if ( ! is_array( $item ) || empty( $item['report_id'] ) ) {
				continue;
			}
			$out[] = $item;
		}

		$limit = max( 1, min( 100, (int) $limit ) );
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Get one report by ID from stored history.
	 *
	 * @param string $report_id Report id.
	 * @return array<string,mixed>
	 */
	public function get_report_by_id( $report_id ) {
		$report_id = sanitize_text_field( (string) $report_id );
		if ( '' === $report_id ) {
			return array();
		}
		foreach ( $this->get_report_history( 100 ) as $report ) {
			if ( isset( $report['report_id'] ) && $report_id === (string) $report['report_id'] ) {
				return $report;
			}
		}
		return array();
	}

	/**
	 * Stream last cleanup report as JSON download.
	 *
	 * @return void
	 */
	public function download_last_report() {
		$report = $this->get_last_report();
		if ( empty( $report ) ) {
			wp_die( esc_html__( 'No cleanup report available.', 'wpoptimizing' ) );
		}
		$this->stream_report_download( $report );
	}

	/**
	 * Download one report by ID. Falls back to latest report.
	 *
	 * @param string $report_id Report id.
	 * @return void
	 */
	public function download_report( $report_id = '' ) {
		$report = array();
		if ( '' !== (string) $report_id ) {
			$report = $this->get_report_by_id( $report_id );
		}
		if ( empty( $report ) ) {
			$this->download_last_report();
			return;
		}
		$this->stream_report_download( $report );
	}

	/**
	 * Stream one report as JSON download.
	 *
	 * @param array<string,mixed> $report Report data.
	 * @return void
	 */
	protected function stream_report_download( array $report ) {
		// Both callers verify the capability and the nonce before getting here, and the sibling
		// that prints the HTML report checks again anyway. Checking in one of the two and not
		// the other is how a third caller ends up being the one that forgot.
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to view this report.', 'wpoptimizing' ) );
		}
		if ( empty( $report ) ) {
			wp_die( esc_html__( 'No cleanup report available.', 'wpoptimizing' ) );
		}

		$timestamp = isset( $report['ended_at'] ) ? preg_replace( '/[^0-9]/', '', (string) $report['ended_at'] ) : gmdate( 'YmdHis' );
		$report_id = isset( $report['report_id'] ) ? preg_replace( '/[^a-zA-Z0-9\-_]/', '', (string) $report['report_id'] ) : '';
		$suffix    = '' !== $report_id ? $report_id : $timestamp;
		$filename  = 'wpoptimizing-cleanup-report-' . $suffix . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Output a standalone, printable (PDF-ready) post-cleanup report and exit.
	 *
	 * @param string $report_id Report id; falls back to the most recent run.
	 * @return void
	 */
	public function print_report_html( $report_id = '' ) {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'You are not allowed to view this report.', 'wpoptimizing' ) );
		}

		$report = '' !== (string) $report_id ? $this->get_report_by_id( $report_id ) : array();
		if ( empty( $report ) ) {
			$report = (array) get_option( 'wpoptimizing_general_cleanup_last_report', array() );
		}
		if ( empty( $report ) ) {
			wp_die( esc_html__( 'No cleanup report available.', 'wpoptimizing' ) );
		}

		$stats = isset( $report['stats'] ) && is_array( $report['stats'] ) ? $report['stats'] : array();
		$brand = function_exists( 'wpoptimizing_brand_name' ) ? wpoptimizing_brand_name() : 'WPOptimizing';
		$logo  = function_exists( 'wpoptimizing_brand_logo' ) ? wpoptimizing_brand_logo() : '';

		// Per-category record counts (optimized_tables is an action count, shown apart).
		$categories = array(
			'revisions'        => __( 'Post revisions', 'wpoptimizing' ),
			'posts'            => __( 'Auto-drafts & trashed posts', 'wpoptimizing' ),
			'comments'         => __( 'Spam & trashed comments', 'wpoptimizing' ),
			'transients'       => __( 'Expired transients', 'wpoptimizing' ),
			'oembed'           => __( 'oEmbed caches', 'wpoptimizing' ),
			'tags'             => __( 'Unused tags', 'wpoptimizing' ),
			'orphans'          => __( 'Orphaned elements', 'wpoptimizing' ),
			'duplicate_meta'   => __( 'Duplicate metadata', 'wpoptimizing' ),
			'action_scheduler' => __( 'Action Scheduler logs', 'wpoptimizing' ),
			'wc_sessions'      => __( 'Expired WooCommerce sessions', 'wpoptimizing' ),
			'wc_variations'    => __( 'Orphaned product variations', 'wpoptimizing' ),
		);

		$records_deleted = 0;
		foreach ( $categories as $key => $label ) {
			$records_deleted += (int) ( $stats[ $key ] ?? 0 );
		}
		$freed       = (int) ( $stats['deleted_records_size_bytes'] ?? 0 );
		$size_after  = (int) ( $stats['db_size_after_bytes'] ?? 0 );
		$size_before = (int) ( $stats['db_size_before_bytes'] ?? 0 );
		$optimized   = (int) ( $stats['optimized_tables'] ?? 0 );
		$duration    = (int) ( $report['duration_ms'] ?? 0 );
		$status      = (string) ( $report['status'] ?? '' );
		$ended       = isset( $report['ended_at'] ) ? strtotime( (string) $report['ended_at'] ) : 0;
		$gen_date    = $ended ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ended ) : '';
		$site_name   = get_bloginfo( 'name' );
		$dur_human   = $duration >= 1000 ? round( $duration / 1000, 1 ) . ' s' : $duration . ' ms';

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr( get_locale() ); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo esc_html( $site_name . ' — ' . $brand . ' ' . __( 'Cleanup Report', 'wpoptimizing' ) ); ?></title>
		<?php echo wpoptimizing_print_report_styles( 900, 4 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static CSS. ?>
</head>
<body>
		<?php echo wpoptimizing_print_report_toolbar( 'wpopt-print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static toolbar. ?>
<div class="report-header">
	<div>
		<?php if ( '' !== $logo ) : ?>
			<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" class="report-logo">
		<?php else : ?>
			<div class="report-brand"><?php echo esc_html( $brand ); ?></div>
		<?php endif; ?>
		<div class="report-sub"><?php esc_html_e( 'Cleanup Report', 'wpoptimizing' ); ?></div>
	</div>
	<div class="report-meta"><strong><?php echo esc_html( $site_name ); ?></strong><br><?php echo esc_html( home_url() ); ?><br><?php esc_html_e( 'Generated:', 'wpoptimizing' ); ?> <?php echo esc_html( $gen_date ); ?></div>
</div>
<div class="summary-grid">
	<div class="card"><div class="num"><?php echo esc_html( number_format_i18n( $records_deleted ) ); ?></div><div class="lbl"><?php esc_html_e( 'Records deleted', 'wpoptimizing' ); ?></div></div>
	<div class="card"><div class="num"><?php echo esc_html( $freed > 0 ? size_format( $freed, 2 ) : '—' ); ?></div><div class="lbl"><?php esc_html_e( 'Space freed', 'wpoptimizing' ); ?></div></div>
	<div class="card"><div class="num"><?php echo esc_html( number_format_i18n( $optimized ) ); ?></div><div class="lbl"><?php esc_html_e( 'Tables optimized', 'wpoptimizing' ); ?></div></div>
	<div class="card"><div class="num"><?php echo esc_html( $dur_human ); ?></div><div class="lbl"><?php esc_html_e( 'Duration', 'wpoptimizing' ); ?></div></div>
</div>
<h2><?php esc_html_e( 'Deleted by category', 'wpoptimizing' ); ?></h2>
<table>
	<thead><tr><th scope="col"><?php esc_html_e( 'Category', 'wpoptimizing' ); ?></th><th class="r" scope="col"><?php esc_html_e( 'Records', 'wpoptimizing' ); ?></th></tr></thead>
	<tbody>
		<?php foreach ( $categories as $key => $label ) : ?>
		<tr><td><?php echo esc_html( $label ); ?></td><td class="r"><?php echo esc_html( number_format_i18n( (int) ( $stats[ $key ] ?? 0 ) ) ); ?></td></tr>
	<?php endforeach; ?>
		<tr><td><strong><?php esc_html_e( 'Total records deleted', 'wpoptimizing' ); ?></strong></td><td class="r"><strong><?php echo esc_html( number_format_i18n( $records_deleted ) ); ?></strong></td></tr>
	</tbody>
</table>
<h2><?php esc_html_e( 'Database', 'wpoptimizing' ); ?></h2>
<table>
	<tbody>
		<tr><td><?php esc_html_e( 'Size before', 'wpoptimizing' ); ?></td><td class="r"><?php echo esc_html( $size_before > 0 ? size_format( $size_before, 2 ) : '—' ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Size after', 'wpoptimizing' ); ?></td><td class="r"><?php echo esc_html( $size_after > 0 ? size_format( $size_after, 2 ) : '—' ); ?></td></tr>
		<tr><td><strong><?php esc_html_e( 'Space freed', 'wpoptimizing' ); ?></strong></td><td class="r"><strong><?php echo esc_html( $freed > 0 ? size_format( $freed, 2 ) : '—' ); ?></strong></td></tr>
		<tr><td><?php esc_html_e( 'Status', 'wpoptimizing' ); ?></td><td class="r"><?php echo esc_html( ucfirst( $status ) ); ?></td></tr>
	</tbody>
</table>
<div class="footer"><span><?php /* translators: 1: brand/product name, 2: plugin version. */ printf( esc_html__( 'Generated by %1$s %2$s', 'wpoptimizing' ), esc_html( $brand ), esc_html( defined( 'WPOPTIMIZING_VERSION' ) ? WPOPTIMIZING_VERSION : '' ) ); ?></span><span><?php echo esc_html( home_url() ); ?></span></div>
		<?php echo wpoptimizing_print_report_script( 'wpopt-print' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Self-contained static script, no user data. ?>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * How long one cleanup run may work before it stops and leaves the rest for the next pass.
	 *
	 * max_execution_time minus a margin, never past the lock (a run that outlives its lock lets
	 * a second one start in parallel). And, in a WEB request, never past 120 seconds: WP-Cron
	 * runs inside one, and PHP-FPM's request_terminate_timeout can kill it from outside at a
	 * ceiling ini_get() cannot see. Measured on 2026-10-11: php.ini said 1200 and FPM killed at
	 * 300, so this computed 840 s and the run died at 300 with its lock held for 15 minutes and
	 * its report never closed. Nothing is lost by stopping early — the next pass resumes — so
	 * the cap only has to be safely under any ceiling a host is likely to have. CLI (WP-CLI,
	 * a system cron calling wp cron) has no such ceiling and keeps the full budget.
	 *
	 * @param int  $max_exec max_execution_time as PHP reports it (0 = unlimited).
	 * @param bool $is_cli   Whether this runs from the command line.
	 * @param int  $lock_ttl Lock lifetime, in seconds.
	 * @return int Seconds.
	 */
	public static function time_budget( $max_exec, $is_cli, $lock_ttl ) {
		if ( $max_exec > 0 ) {
			$budget = max( 10, $max_exec - 5 );
		} else {
			// PHP CLI always reports 0: there the lock is the only ceiling.
			$budget = $is_cli ? (int) $lock_ttl - 60 : 25;
		}
		$budget = min( $budget, (int) $lock_ttl - 60 );
		if ( ! $is_cli ) {
			/**
			 * Filters the longest a cleanup run may last inside a web request (WP-Cron included).
			 *
			 * Lower it if your host kills requests sooner than two minutes.
			 *
			 * @since 0.1.0
			 * @param int $seconds Default 120.
			 */
			$budget = min( $budget, max( 10, (int) apply_filters( 'wpoptimizing_cleanup_web_budget', 120 ) ) );
		}
		return $budget;
	}

	/**
	 * Run cleanup process.
	 *
	 * @param bool      $show_notice Whether to display admin notice.
	 * @param int       $attempt Retry attempt number.
	 * @param bool|null $scheduled Whether this run is scheduled/cron. Default auto-detect.
	 * @param array|null $resume  Task keys left by a run cut short (see RESUME_OPTION): only those
	 *                            run, whatever their schedule. Null for a normal run.
	 * @return void
	 */
	public function run( $show_notice, $attempt = 0, $scheduled = null, $resume = null ) {
		if ( null === $scheduled ) {
			$scheduled = wp_doing_cron();
		}
		if ( ! $scheduled && ! current_user_can( $this->capability ) ) {
			return;
		}

		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}

		$started     = microtime( true );
		$time_budget = self::time_budget( (int) ini_get( 'max_execution_time' ), 'cli' === PHP_SAPI, $this->lock_ttl );

		$settings = $this->get_settings();
		$report   = $this->new_report( $settings, (int) $attempt, (bool) $scheduled, $started, $time_budget );

		if ( ! $this->acquire_lock( $time_budget ) ) {
			$report['status'] = 'locked';
			$this->append_report_event( $report, __( 'Cleanup skipped: another execution is running.', 'wpoptimizing' ) );
			if ( null !== $resume ) {
				// The pass waits for the run in progress, which records its own state when it ends.
				$this->schedule_resume();
			} elseif ( $scheduled && (int) $attempt < 3 ) {
				$this->schedule_retry( (int) $attempt + 1 );
				$report['status'] = 'retry_scheduled';
				$this->append_report_event( $report, __( 'Retry scheduled due to lock.', 'wpoptimizing' ) );
			}
			$this->finalize_report( $report, array() );
			return;
		}

		if ( $this->lock_taken_over ) {
			$this->append_report_event( $report, __( 'Took over the lock of a previous cleanup that had stopped responding.', 'wpoptimizing' ) );
		}
		$this->start_pass( $resume );

		// Retention first, so the size ceiling is measured after old copies have gone; then one
		// backup operation for the whole run, filled batch by batch before each delete.
		wpoptimizing_backup_apply_retention();
		$this->backup = new WPOptimizing_Backup_Writer( '', $scheduled ? 'general_cleanup' : 'db_cleanup' );

		$stop = false;

		$stats = array(
			'revisions'                  => 0,
			'posts'                      => 0,
			'comments'                   => 0,
			'transients'                 => 0,
			'oembed'                     => 0,
			'tags'                       => 0,
			'orphans'                    => 0,
			'duplicate_meta'             => 0,
			'action_scheduler'           => 0,
			'action_scheduler_logs'      => 0,
			'optimized_tables'           => 0,
			'db_size_before_bytes'       => 0,
			'db_size_after_bytes'        => 0,
			'deleted_records_size_bytes' => 0,
		);

		/**
		 * Fires before the general cleanup begins (lock already acquired).
		 *
		 * @since 0.1.0
		 * @param array $settings  Effective cleanup settings for this run.
		 * @param bool  $scheduled Whether this run was triggered by WP-Cron.
		 */
		do_action( 'wpoptimizing_before_cleanup', $settings, (bool) $scheduled );

		try {
			global $wpdb;
			$stats['db_size_before_bytes'] = $this->get_database_size_bytes();

			// Checked between batches, so it is also where the run says it is still alive. A run
			// that finds its lock no longer its own stops: something judged it dead and another
			// cleanup may already be working.
			$beat_at     = time();
			$should_stop = function () use ( $started, $time_budget, &$beat_at ) {
				if ( $this->backup_lost() ) {
					return true; // A batch could not be backed up: nothing else is deleted.
				}
				if ( time() - $beat_at >= self::LOCK_BEAT ) {
					$beat_at = time();
					if ( ! $this->heartbeat_lock() ) {
						return true;
					}
				}
				return ( microtime( true ) - $started ) >= $time_budget;
			};

			// Per-task scheduling: on scheduled runs each task only runs when its
			// own frequency is due (manual runs always run every enabled task).
			$task_sched = $this->get_task_schedules();
			$task_last  = get_option( 'wpoptimizing_gc_task_last_run', array() );
			$task_last  = is_array( $task_last ) ? $task_last : array();
			$now_ts     = time();
			$task_ran   = array();
			$task_left  = array();
			$run_task   = function ( $key, callable $fn ) use ( $scheduled, $task_sched, $task_last, $now_ts, &$task_ran, &$task_left, $should_stop, $resume ) {
				$due = null !== $resume
					? in_array( $key, (array) $resume, true )
					: self::is_task_due( isset( $task_sched[ $key ] ) ? $task_sched[ $key ] : 'default', isset( $task_last[ $key ] ) ? (int) $task_last[ $key ] : 0, $now_ts, (bool) $scheduled );
				if ( $due ) {
					if ( $should_stop() ) {
						$task_left[] = $key;
						return;
					}
					$fn();
					// A task cut short by the time budget is still due: only a finished one counts as run.
					if ( ! $should_stop() ) {
						$task_ran[ $key ] = $now_ts;
					} else {
						$task_left[] = $key;
					}
				}
			};

			$run_task(
				'revisions',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_revisions( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'auto_drafts',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_auto_drafts( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'trashed_posts',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_trashed_posts( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'comments',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_comments( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'transients',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_transients( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'oembed',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_oembed_cache( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'unused_tags',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_unused_tags( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'orphans',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_orphans( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'duplicate_meta',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_duplicate_meta( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'action_scheduler',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_action_scheduler( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'woocommerce',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->cleanup_wc_sessions( $stats, $settings, $should_stop, $report );
					$this->cleanup_wc_orphan_variations( $stats, $settings, $should_stop, $report );
				}
			);
			$run_task(
				'optimize_tables',
				function () use ( &$stats, $settings, $should_stop, &$report ) {
					$this->optimize_tables( $stats, $settings, $should_stop, $report );
				}
			);

			// Record per-task run times (scheduled runs only) so the next tick can
			// honour each task's frequency. One option write per run.
			if ( $scheduled && null === $resume && ! empty( $task_ran ) ) {
				update_option( 'wpoptimizing_gc_task_last_run', array_merge( $task_last, $task_ran ), false );
			}

			$stop = $should_stop();
		} catch ( Throwable $e ) {
			$report['status'] = 'failed';
			/* translators: %s: cleanup failure message. */
			$this->append_report_event( $report, sprintf( __( 'Cleanup failed: %s', 'wpoptimizing' ), $e->getMessage() ) );
			if ( $scheduled && (int) $attempt < 3 ) {
				$this->schedule_retry( (int) $attempt + 1 );
				$this->append_report_event( $report, __( 'Retry scheduled after failure.', 'wpoptimizing' ) );
			}
		} finally {
			$this->release_lock();
		}

		if ( $this->backup->written() > 0 ) {
			/* translators: %s: backup operation key. */
			$this->append_report_event( $report, sprintf( __( 'Everything deleted was backed up first: %s (listed under Backups).', 'wpoptimizing' ), $this->backup->key() ) );
		}
		if ( 'failed' !== $report['status'] && $this->backup_lost() ) {
			// No retry: the same backup would fail again until somebody frees space or fixes
			// the folder.
			$report['status'] = 'backup_failed';
			$backup_msg       = 'cap' === $this->backup->failed()
				? __( 'Cleanup stopped: the backup folder reached its size limit, so nothing more was deleted. What was already deleted is in the backup listed under Backups.', 'wpoptimizing' )
				: __( 'Cleanup stopped: its backup could not be written (check disk space and folder permissions), so nothing more was deleted. What was already deleted is in the backup listed under Backups.', 'wpoptimizing' );
			$this->append_report_event( $report, $backup_msg );
			if ( $show_notice ) {
				add_settings_error( 'wpoptimizing', 'wpoptimizing_general_cleanup_backup', $backup_msg, 'error' );
			}
		} elseif ( 'failed' !== $report['status'] ) {
			$report['status'] = $stop ? 'partial' : 'completed';
			if ( $stop ) {
				$this->append_report_event( $report, __( 'Execution stopped due to time budget.', 'wpoptimizing' ) );
			}
		}
		$continues = 'partial' === $report['status'] && $this->continue_later( $task_left ?? array(), self::stats_progress( $stats ) );
		if ( $continues ) {
			$this->append_report_event( $report, __( 'The cleanup continues by itself in the background in about a minute, until it is done.', 'wpoptimizing' ) );
		} else {
			self::end_resume();
		}
		$stats['db_size_after_bytes']        = $this->get_database_size_bytes();
		$stats['deleted_records_size_bytes'] = max( 0, (int) $stats['db_size_before_bytes'] - (int) $stats['db_size_after_bytes'] );
		$this->append_report_event(
			$report,
			sprintf(
				/* translators: 1: DB size before cleanup, 2: DB size after cleanup, 3: estimated deleted-records size. */
				__( 'Database size: before %1$s, after %2$s, estimated deleted records %3$s.', 'wpoptimizing' ),
				size_format( max( 0, (int) $stats['db_size_before_bytes'] ), 2 ),
				size_format( max( 0, (int) $stats['db_size_after_bytes'] ), 2 ),
				size_format( max( 0, (int) $stats['deleted_records_size_bytes'] ), 2 )
			)
		);

		update_option( 'wpoptimizing_general_cleanup_last_run', time(), false );
		update_option( 'wpoptimizing_general_cleanup_last_stats', $stats, false );
		$this->finalize_report( $report, $stats );

		/**
		 * Fires after the general cleanup finishes (any final status).
		 *
		 * @since 0.1.0
		 * @param array  $stats  Per-category counts and DB size deltas.
		 * @param string $status Final status: completed|partial|failed|locked|retry_scheduled|backup_failed.
		 * @param array  $report Full structured report (events, settings, timing).
		 */
		do_action( 'wpoptimizing_after_cleanup', $stats, (string) $report['status'], $report );

		if ( $show_notice && $continues ) {
			add_settings_error( 'wpoptimizing', 'wpoptimizing_general_cleanup_done', __( 'The cleanup reached its time limit and continues by itself in the background until it is done. You can leave this page.', 'wpoptimizing' ), 'updated' );
		} elseif ( $show_notice ) {
			/* translators: 1: revisions, 2: posts, 3: comments, 4: transients, 5: optimized tables. */
			$message = sprintf( __( 'General cleanup completed. Revisions: %1$d, Posts: %2$d, Comments: %3$d, Transients: %4$d, Optimized tables: %5$d.', 'wpoptimizing' ), (int) $stats['revisions'], (int) $stats['posts'], (int) $stats['comments'], (int) $stats['transients'], (int) $stats['optimized_tables'] );
			add_settings_error( 'wpoptimizing', 'wpoptimizing_general_cleanup_done', $message, 'updated' );
		}
	}

	/**
	 * Normalize table list from settings or request.
	 *
	 * @param mixed $tables Raw table list.
	 * @return string[]
	 */
	protected function normalize_table_list( $tables ) {
		if ( ! is_array( $tables ) ) {
			return array();
		}
		$out = array();
		foreach ( $tables as $table ) {
			$table = sanitize_text_field( wp_unslash( (string) $table ) );
			if ( '' === $table || '__none__' === $table ) {
				continue;
			}
			$out[] = $table;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Acquire cleanup lock.
	 *
	 * @return bool
	 */
	protected function acquire_lock( $time_budget = 0 ) {
		$current = get_transient( $this->lock_key );
		if ( false !== $current && ! self::lock_is_stale( $current, time() ) ) {
			return false;
		}
		$this->lock_taken_over = ( false !== $current );
		$this->lock_id         = uniqid( 'gc', true );
		set_transient( $this->lock_key, self::new_lock_state( $this->lock_id, time(), 'cli' !== PHP_SAPI, (int) $time_budget ), $this->lock_ttl );
		return true;
	}

	/**
	 * The value stored in the lock.
	 *
	 * @param string $id     Identifier of the run that holds it.
	 * @param int    $now    Current time.
	 * @param bool   $web    Whether it was taken from a web request.
	 * @param int    $budget Time budget of the run that takes it.
	 * @return array{id:string,inicio:int,latido:int,web:bool,ventana:int}
	 */
	public static function new_lock_state( $id, $now, $web, $budget = 0 ) {
		return array(
			'id'      => (string) $id,
			'inicio'  => (int) $now,
			'latido'  => (int) $now,
			'web'     => (bool) $web,
			'ventana' => $web ? max( self::LOCK_WEB_STALE, (int) $budget + self::LOCK_STALE_MARGIN ) : 0,
		);
	}

	/**
	 * Is this lock left behind by a web run that died? See LOCK_WEB_STALE.
	 *
	 * A lock in the old format (a bare timestamp, from before the heartbeat) is never stale here:
	 * nothing says where it was taken, so it keeps its TTL like before.
	 *
	 * @param mixed $lock Stored lock value.
	 * @param int   $now  Current time.
	 * @return bool
	 */
	public static function lock_is_stale( $lock, $now ) {
		if ( ! is_array( $lock ) || empty( $lock['web'] ) || ! isset( $lock['latido'] ) ) {
			return false;
		}
		$window = isset( $lock['ventana'] ) ? max( self::LOCK_WEB_STALE, (int) $lock['ventana'] ) : self::LOCK_WEB_STALE;
		return ( (int) $now - (int) $lock['latido'] ) > $window;
	}

	/**
	 * Refresh this run's heartbeat.
	 *
	 * @return bool False when the lock is no longer this run's (taken over, or gone).
	 */
	protected function heartbeat_lock() {
		$current = get_transient( $this->lock_key );
		if ( ! is_array( $current ) || ! isset( $current['id'] ) || $current['id'] !== $this->lock_id ) {
			return false;
		}
		$current['latido'] = time();
		set_transient( $this->lock_key, $current, $this->lock_ttl );
		return true;
	}

	/**
	 * Release cleanup lock — only if it is still this run's. A run that was judged dead and then
	 * finished anyway must not delete the lock of the cleanup that took over from it.
	 *
	 * @return void
	 */
	protected function release_lock() {
		$current = get_transient( $this->lock_key );
		if ( is_array( $current ) && isset( $current['id'] ) && $current['id'] !== $this->lock_id ) {
			return;
		}
		delete_transient( $this->lock_key );
	}

	/**
	 * Schedule a retry run.
	 *
	 * @param int $attempt Attempt number.
	 * @return void
	 */
	protected function schedule_retry( $attempt ) {
		$hook = 'wpoptimizing_general_cleanup_cron';
		$args = array( (int) $attempt );
		if ( wp_next_scheduled( $hook, $args ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, $hook, $args );
	}

	/**
	 * Rows a run deleted, from its stats: sizes and tables optimised are not progress.
	 *
	 * @param array $stats Run stats.
	 * @return int
	 */
	public static function stats_progress( array $stats ) {
		$total = 0;
		foreach ( $stats as $key => $value ) {
			if ( 'optimized_tables' !== $key && false === strpos( (string) $key, '_bytes' ) ) {
				$total += max( 0, (int) $value );
			}
		}
		return $total;
	}

	/**
	 * Record, before any work, what this run is about to do, and leave the rescue pass scheduled.
	 *
	 * If the run dies, the state stays with every task and the rescue (RESUME_RESCUE) picks it up.
	 * A run that is not a pass of the chain starts a new one. `idle` is counted here and reset at
	 * the end only when the run deleted something, so passes that keep dying also count as idle
	 * and the chain gives up after RESUME_IDLE_MAX of them.
	 *
	 * @param array|null $resume Tasks of this pass, or null for a normal run.
	 * @return void
	 */
	protected function start_pass( $resume ) {
		$state = get_option( self::RESUME_OPTION, array() );
		$chain = null !== $resume && is_array( $state ) && ! empty( $state );
		update_option(
			self::RESUME_OPTION,
			array(
				'tasks'  => null !== $resume ? array_values( array_map( 'strval', (array) $resume ) ) : self::task_keys(),
				'passes' => $chain ? (int) ( $state['passes'] ?? 0 ) : 0,
				'idle'   => ( $chain ? (int) ( $state['idle'] ?? 0 ) : 0 ) + 1,
				'since'  => $chain ? (int) ( $state['since'] ?? time() ) : time(),
			),
			false
		);
		wp_clear_scheduled_hook( self::RESUME_HOOK );
		wp_schedule_single_event( time() + self::RESUME_RESCUE, self::RESUME_HOOK );
	}

	/**
	 * Leave the unfinished tasks for the next pass, and schedule it in a minute.
	 *
	 * @param string[] $tasks    Task keys the run did not finish.
	 * @param int      $progress Rows it deleted.
	 * @return bool Whether a pass was scheduled.
	 */
	protected function continue_later( array $tasks, $progress ) {
		$tasks = array_values( array_unique( array_map( 'strval', $tasks ) ) );
		$state = get_option( self::RESUME_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		$idle  = $progress > 0 ? 0 : max( 1, (int) ( $state['idle'] ?? 1 ) );
		if ( empty( $tasks ) || $idle >= self::RESUME_IDLE_MAX ) {
			self::end_resume();
			return false;
		}
		update_option(
			self::RESUME_OPTION,
			array(
				'tasks'  => $tasks,
				'passes' => (int) ( $state['passes'] ?? 0 ) + 1,
				'idle'   => $idle,
				'since'  => (int) ( $state['since'] ?? time() ),
			),
			false
		);
		wp_clear_scheduled_hook( self::RESUME_HOOK );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RESUME_HOOK );
		return true;
	}

	/**
	 * Nothing left to do: forget the state and the pass waiting for it.
	 *
	 * @return void
	 */
	public static function end_resume() {
		delete_option( self::RESUME_OPTION );
		wp_clear_scheduled_hook( self::RESUME_HOOK );
	}

	/**
	 * Schedule the next pass in a minute, unless one is already waiting.
	 *
	 * @return void
	 */
	protected function schedule_resume() {
		if ( ! wp_next_scheduled( self::RESUME_HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::RESUME_HOOK );
		}
	}

	/**
	 * Rough number of Action Scheduler log rows, for the progress bar: the table statistics,
	 * never a COUNT(*) that takes seconds on millions of rows. 0 without Action Scheduler.
	 *
	 * @return int
	 */
	public static function as_logs_estimate() {
		if ( ! self::has_action_scheduler() ) {
			return 0;
		}
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT table_rows FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', $wpdb->prefix . 'actionscheduler_logs' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Table statistics, read once per screen.
	}

	/**
	 * What is left for the next pass: null when nothing is. While a run works it holds that
	 * run's tasks with `passes` 0 (see start_pass()).
	 *
	 * @return array{tasks:string[],passes:int,idle:int,since:int}|null
	 */
	public static function resume_state() {
		$state = get_option( self::RESUME_OPTION, array() );
		return ( is_array( $state ) && ! empty( $state['tasks'] ) ) ? $state : null;
	}

	/**
	 * Build new report array.
	 *
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @param int                 $attempt  Retry attempt.
	 * @param bool                $scheduled Whether scheduled.
	 * @param float               $started_micro Start timestamp in microseconds.
	 * @param int                 $time_budget   Time budget for this run in seconds.
	 * @return array<string,mixed>
	 */
	protected function new_report( array $settings, $attempt, $scheduled, $started_micro, $time_budget ) {
		return array(
			'report_id'     => 'gc-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false ),
			'status'        => 'running',
			'started_at'    => gmdate( 'c' ),
			'started_micro' => (float) $started_micro,
			'ended_at'      => null,
			'attempt'       => (int) $attempt,
			'scheduled'     => (bool) $scheduled,
			'time_budget'   => (int) $time_budget,
			'settings'      => $settings,
			'duration_ms'   => 0,
			'stats'         => array(),
			'events'        => array(),
		);
	}

	/**
	 * Finalize and persist report.
	 *
	 * @param array<string,mixed> $report Report.
	 * @param array<string,int>   $stats Stats.
	 * @return void
	 */
	protected function finalize_report( array $report, array $stats ) {
		$start_micro = isset( $report['started_micro'] ) ? (float) $report['started_micro'] : 0.0;
		$end_micro   = microtime( true );
		$end_ts      = (int) $end_micro;

		$report['ended_at']    = gmdate( 'c', $end_ts );
		$report['duration_ms'] = ( $start_micro > 0 ) ? (int) round( ( $end_micro - $start_micro ) * 1000 ) : 0;
		$report['stats']       = $stats;
		unset( $report['started_micro'] );

		$settings = isset( $report['settings'] ) && is_array( $report['settings'] ) ? $report['settings'] : array();
		if ( empty( $settings['generate_report'] ) ) {
			$report['events'] = array();
		}

		update_option( 'wpoptimizing_general_cleanup_last_report', $report, false );
		$this->save_report_history( $report );
	}

	/**
	 * Persist report history with size cap.
	 *
	 * @param array<string,mixed> $report Report.
	 * @return void
	 */
	protected function save_report_history( array $report ) {
		if ( empty( $report['report_id'] ) ) {
			return;
		}

		$history = wpoptimizing_blob_get( 'cleanup-reports', 'wpoptimizing_general_cleanup_reports', array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$report_id = (string) $report['report_id'];
		$filtered  = array();
		foreach ( $history as $item ) {
			if ( ! is_array( $item ) || empty( $item['report_id'] ) ) {
				continue;
			}
			if ( $report_id === (string) $item['report_id'] ) {
				continue;
			}
			$filtered[] = $item;
		}

		array_unshift( $filtered, $report );
		$filtered = array_slice( $filtered, 0, 50 );
		wpoptimizing_blob_put( 'cleanup-reports', 'wpoptimizing_general_cleanup_reports', $filtered );
	}

	/**
	 * Append event line to report.
	 *
	 * @param array<string,mixed> $report Report.
	 * @param string              $message Message.
	 * @return void
	 */
	protected function append_report_event( array &$report, $message ) {
		if ( empty( $report['settings']['generate_report'] ) ) {
			return;
		}
		$report['events'][] = array(
			'time'    => gmdate( 'c' ),
			'message' => (string) $message,
		);
	}

	/**
	 * Delete revisions in batches.
	 */
	protected function cleanup_revisions( array &$stats, array $settings, callable $should_stop, array &$report ) {
		global $wpdb;
		$batch_size      = 300;
		$cursor          = PHP_INT_MAX;
		$keep_per_parent = max( 0, (int) $settings['keep_revisions'] );
		$max_age_days    = max( 0, (int) ( $settings['revisions_max_age_days'] ?? 0 ) );
		$cutoff_gmt      = $max_age_days > 0 ? gmdate( 'Y-m-d H:i:s', time() - ( $max_age_days * DAY_IN_SECONDS ) ) : '';
		$seen            = array();
		$protected       = array();
		$posts_table     = $this->quote_identifier( $wpdb->posts ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

		while ( ! $should_stop() ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT ID, post_parent, post_date_gmt FROM {$posts_table} WHERE post_type = %s AND ID < %d ORDER BY ID DESC LIMIT %d",
						'revision',
						$cursor,
						$batch_size
					),
					ARRAY_A
				);
			if ( empty( $rows ) || ! is_array( $rows ) ) {
				break;
			}

			$doomed = array();
			foreach ( $rows as $row ) {
				$parent_id = (int) ( $row['post_parent'] ?? 0 );
				$rev_id    = (int) ( $row['ID'] ?? 0 );
				if ( $rev_id <= 0 ) {
					continue;
				}
				$cursor = min( $cursor, $rev_id );
				// Per-post opt-out: never touch revisions of a post the user flagged to keep.
				if ( ! isset( $protected[ $parent_id ] ) ) {
					$protected[ $parent_id ] = $this->post_keeps_revisions( $parent_id );
				}
				if ( $protected[ $parent_id ] ) {
					continue;
				}
				if ( ! isset( $seen[ $parent_id ] ) ) {
					$seen[ $parent_id ] = 0;
				}
				++$seen[ $parent_id ];
				if ( $seen[ $parent_id ] <= $keep_per_parent ) {
					continue; // Within the keep-N most recent revisions for this post.
				}
				// Age guard: when set, only delete revisions older than the cutoff, so
				// recent edit history is preserved even when it exceeds keep-N.
				if ( '' !== $cutoff_gmt && (string) ( $row['post_date_gmt'] ?? '' ) >= $cutoff_gmt ) {
					continue;
				}
				$doomed[] = $rev_id;
			}
			// The batch is backed up whole before any of it goes; no backup, no delete.
			if ( ! empty( $doomed ) && ! $this->backup()->posts( $doomed, 'revision' ) ) {
				break;
			}
			foreach ( $doomed as $rev_id ) {
				if ( wp_delete_post_revision( $rev_id ) ) {
					++$stats['revisions'];
				}
			}
		}
		/* translators: %d: number of revisions deleted. */
		$this->append_report_event( $report, sprintf( __( 'Revisions cleaned: %d', 'wpoptimizing' ), (int) $stats['revisions'] ) );
	}

	/**
	 * Whether a post is flagged to keep ALL its revisions (excluded from cleanup).
	 *
	 * Honors our own `_wpopt_keep_revisions` meta (set from the editor checkbox) and,
	 * for drop-in compatibility with "Optimize Database after Deleting Revisions", a
	 * custom field named `keep_revisions` with a truthy value.
	 *
	 * @param int $post_id Parent post ID.
	 * @return bool
	 */
	protected function post_keeps_revisions( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return false;
		}
		if ( '' !== (string) get_post_meta( $post_id, '_wpopt_keep_revisions', true ) ) {
			return true;
		}
		$rvg = (string) get_post_meta( $post_id, 'keep_revisions', true );
		return ( '' !== $rvg && '0' !== $rvg && 'N' !== strtoupper( $rvg ) );
	}

	/**
	 * Local-time date before which an auto-draft may go: the configured age, never under one day.
	 *
	 * @param int $days Configured maximum age in days (0 = no age set).
	 * @return string MySQL datetime in the site's time zone.
	 */
	public static function auto_draft_cutoff( $days ) {
		$days   = max( 1, (int) $days );
		$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
		return gmdate( 'Y-m-d H:i:s', time() + $offset - $days * DAY_IN_SECONDS );
	}

	/**
	 * Delete auto drafts in batches.
	 */
	protected function cleanup_auto_drafts( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_auto_drafts'] ) ) {
			return;
		}
		global $wpdb;
		$keep_auto_drafts   = max( 0, (int) $settings['keep_auto_drafts'] );
		$max_auto_draft_age = max( 0, (int) $settings['auto_drafts_max_age_days'] );
		$batch_size         = 250;
		$cutoff             = self::auto_draft_cutoff( $max_auto_draft_age );
		$cursor             = PHP_INT_MAX;
		$seen               = 0;
		$posts_table        = $this->quote_identifier( $wpdb->posts ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

		while ( ! $should_stop() ) {
				$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT ID, post_date FROM {$posts_table} WHERE post_status = %s AND ID < %d ORDER BY ID DESC LIMIT %d",
						'auto-draft',
						$cursor,
						$batch_size
					),
					ARRAY_A
				);
			if ( empty( $rows ) || ! is_array( $rows ) ) {
				break;
			}

			$doomed = array();
			foreach ( $rows as $row ) {
				$post_id = (int) ( $row['ID'] ?? 0 );
				if ( $post_id <= 0 ) {
					continue;
				}
				$cursor = min( $cursor, $post_id );
				++$seen;
				// Auto-drafts leave post_date_gmt at zeros, so the age is read from post_date
				// (local time), as wp_delete_auto_drafts() does. A product or post still being
				// written is an auto-draft too, so nothing younger than a day is ever touched.
				$date   = isset( $row['post_date'] ) ? (string) $row['post_date'] : '';
				$delete = ( '' !== $date && $date < $cutoff );
				if ( $delete && $keep_auto_drafts > 0 ) {
					$delete = $seen > $keep_auto_drafts;
				}

				if ( $delete ) {
					$doomed[] = $post_id;
				}
			}
			if ( ! empty( $doomed ) && ! $this->backup()->posts( $doomed, 'post' ) ) {
				break;
			}
			foreach ( $doomed as $post_id ) {
				if ( wp_delete_post( $post_id, true ) ) {
					++$stats['posts'];
				}
			}
		}
		$this->append_report_event( $report, __( 'Auto-drafts cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Delete trashed posts in batches.
	 */
	protected function cleanup_trashed_posts( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_trashed_posts'] ) ) {
			return;
		}
		global $wpdb;
		$posts_table = $this->quote_identifier( $wpdb->posts ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Trashing a post updates it, so post_modified_gmt is when it went to the trash.
		$min_age = max( 0, (int) ( $settings['trash_min_age_days'] ?? 0 ) );
		$cut_dt  = $min_age > 0 ? gmdate( 'Y-m-d H:i:s', time() - $min_age * DAY_IN_SECONDS ) : '9999-12-31';
		while ( ! $should_stop() ) {
			// Attachments are left out: wp_delete_post() on one deletes its FILES, and files are
			// backed up by the media tools (Media › Unused), not by this database backup.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$posts_table} WHERE post_status = %s AND post_modified_gmt < %s AND post_type <> %s ORDER BY ID ASC LIMIT %d", 'trash', $cut_dt, 'attachment', 250 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				break;
			}
			if ( ! $this->backup()->posts( $ids, 'post' ) ) {
				break;
			}
			$done = 0;
			foreach ( $ids as $post_id ) {
				if ( wp_delete_post( (int) $post_id, true ) ) {
					++$stats['posts'];
					++$done;
				}
			}
			if ( 0 === $done ) {
				break; // Something refuses these deletions (pre_delete_post): the same batch would come back forever.
			}
		}
		$this->append_report_event( $report, __( 'Trashed posts cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Delete comments in batches.
	 */
	protected function cleanup_comments( array &$stats, array $settings, callable $should_stop, array &$report ) {
		global $wpdb;
		$comments_table = $this->quote_identifier( $wpdb->comments ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$filters        = array();
		if ( ! empty( $settings['delete_spam_comments'] ) ) {
			$filters[] = array( 'comment_approved', 'spam' );
		}
		if ( ! empty( $settings['delete_trashed_comments'] ) ) {
			$filters[] = array( 'comment_approved', 'trash' );
		}
		if ( ! empty( $settings['delete_unapproved_comments'] ) ) {
			$filters[] = array( 'comment_approved', '0' );
		}
		if ( ! empty( $settings['delete_pingbacks'] ) ) {
			$filters[] = array( 'comment_type', 'pingback' );
		}
		if ( ! empty( $settings['delete_trackbacks'] ) ) {
			$filters[] = array( 'comment_type', 'trackback' );
		}

		// Minimum age for spam and trash: WordPress stamps _wp_trash_meta_time when a comment is
		// trashed or marked as spam; spam caught on submission (Akismet) has no stamp, so its
		// own date stands in.
		$min_age = max( 0, (int) ( $settings['trash_min_age_days'] ?? 0 ) );
		$cut_ts  = time() - $min_age * DAY_IN_SECONDS;
		$cmeta   = $this->quote_identifier( $wpdb->commentmeta ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $filters as $filter ) {
			if ( $should_stop() ) {
				break;
			}
			list( $column, $value ) = $filter;
			while ( ! $should_stop() ) {
				if ( $min_age > 0 && 'comment_approved' === $column && in_array( $value, array( 'spam', 'trash' ), true ) ) {
					$ids = $wpdb->get_col( $wpdb->prepare( "SELECT c.comment_ID FROM {$comments_table} c LEFT JOIN {$cmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = %s WHERE c.comment_approved = %s AND ( CAST(m.meta_value AS UNSIGNED) < %d OR ( m.meta_value IS NULL AND c.comment_date_gmt < %s ) ) ORDER BY c.comment_ID ASC LIMIT %d", '_wp_trash_meta_time', $value, $cut_ts, gmdate( 'Y-m-d H:i:s', $cut_ts ), 250 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				} elseif ( 'comment_approved' === $column ) {
							$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
								$wpdb->prepare(
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									"SELECT comment_ID FROM {$comments_table} WHERE comment_approved = %s ORDER BY comment_ID ASC LIMIT %d",
									$value,
									250
								)
							);
				} else {
							$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
								$wpdb->prepare(
									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
									"SELECT comment_ID FROM {$comments_table} WHERE comment_type = %s ORDER BY comment_ID ASC LIMIT %d",
									$value,
									250
								)
							);
				}
				if ( empty( $ids ) || ! is_array( $ids ) ) {
					break;
				}
				if ( ! $this->backup()->comments( $ids ) ) {
					break;
				}
				$done = 0;
				foreach ( $ids as $comment_id ) {
					if ( wp_delete_comment( (int) $comment_id, true ) ) {
						++$stats['comments'];
						++$done;
					}
				}
				if ( 0 === $done ) {
					break; // Nothing deleted: the same batch would come back forever.
				}
			}
		}
		$this->append_report_event( $report, __( 'Comments cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Delete transient rows in batches.
	 */
	/**
	 * Delete a transient that was found as a ROW IN THIS BLOG'S OPTIONS TABLE.
	 *
	 * On single site delete_site_transient() is the right call: site transients live in the
	 * options table there. On multisite they do not — set_site_transient() writes to sitemeta —
	 * so a row named "_site_transient_foo" in a blog's options table is a leftover from before
	 * the network, or from a plugin writing the option directly.
	 *
	 * Calling the network API for it would do two wrong things at once: delete a DIFFERENT,
	 * network-wide value that other sites are using, and leave the row that was actually found
	 * exactly where it was. A sub-site's cleanup would be reaching across the network to clear
	 * other customers' caches. The background sweeper already sidesteps this by skipping such
	 * rows on multisite; here they are removed where they really are.
	 *
	 * With a persistent object cache the transient API does not touch the options table at all:
	 * delete_transient() only clears the cache. The rows this cleanup found are in the table —
	 * left there from before the cache, or written directly — so they are removed as options too,
	 * and the result is read back. Measured on wpoptimizing.com on 2026-10-12: every run reported
	 * the same 137 expired transients as deleted, and the next preview found all 137 again.
	 *
	 * @since 0.1.0
	 * @param string $option_name The option row's name.
	 * @return bool Whether the row is gone.
	 */
	protected function delete_transient_row( $option_name ) {
		$option_name = (string) $option_name;
		$names       = array();

		foreach ( array( '_site_transient_timeout_', '_site_transient_' ) as $prefix ) {
			if ( 0 !== strpos( $option_name, $prefix ) ) {
				continue;
			}
			$key = substr( $option_name, strlen( $prefix ) );
			if ( ! is_multisite() ) {
				delete_site_transient( $key );
			}
			$names = array( '_site_transient_' . $key, '_site_transient_timeout_' . $key );
			break;
		}

		if ( empty( $names ) ) {
			foreach ( array( '_transient_timeout_', '_transient_' ) as $prefix ) {
				if ( 0 === strpos( $option_name, $prefix ) ) {
					$key = substr( $option_name, strlen( $prefix ) );
					delete_transient( $key );
					$names = array( '_transient_' . $key, '_transient_timeout_' . $key );
					break;
				}
			}
		}

		if ( empty( $names ) ) {
			return false;
		}
		foreach ( $names as $name ) {
			delete_option( $name ); // A no-op when the API above already removed the row.
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reading back what was just deleted; a cached answer is what must not be trusted.
		return null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $option_name ) );
	}

	/**
	 * Whether a transient row is a lock in use: this cleanup's own, WP-Cron's doing_cron, or
	 * any other *_lock / *lock_* transient. Deleting every transient would otherwise drop the
	 * lock of the run doing the deleting (it then stops halfway and a second run can start)
	 * and doing_cron, which makes wp-cron.php skip the rest of the due events.
	 *
	 * @param string $option_name Row name in the options table.
	 * @return bool
	 */
	public static function is_lock_transient( $option_name ) {
		$name = (string) preg_replace( '/^_(?:site_)?transient_(?:timeout_)?/', '', (string) $option_name );
		return 'doing_cron' === $name || (bool) preg_match( '/(?:^|_)lock(?:$|_)/', $name );
	}

	/**
	 * The two rows of a transient, from either of them: delete_transient_row() removes both, so
	 * both go into the backup.
	 *
	 * @param string $option_name Row name in the options table.
	 * @return string[]
	 */
	public static function transient_pair( $option_name ) {
		$name = (string) $option_name;
		if ( preg_match( '/^(_(?:site_)?transient_)timeout_(.+)$/', $name, $m ) ) {
			return array( $m[1] . $m[2], $name );
		}
		if ( preg_match( '/^(_(?:site_)?transient_)(.+)$/', $name, $m ) ) {
			return array( $name, $m[1] . 'timeout_' . $m[2] );
		}
		return array( $name );
	}

	/**
	 * Back up the transient rows of one batch, both halves of each.
	 *
	 * @param string[] $option_names Rows about to be deleted.
	 * @return bool
	 */
	protected function backup_transient_rows( array $option_names ) {
		$all = array();
		foreach ( $option_names as $name ) {
			$all = array_merge( $all, self::transient_pair( $name ) );
		}
		return $this->backup()->options( $all, 'transient' );
	}

	protected function cleanup_transients( array &$stats, array $settings, callable $should_stop, array &$report ) {
		global $wpdb;
		$options_table = $this->quote_identifier( $wpdb->options ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! empty( $settings['delete_all_transients'] ) ) {
			$cursor = 0;
			while ( ! $should_stop() ) {
						$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								"SELECT option_id, option_name FROM {$options_table} WHERE option_id > %d AND ( option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s ) ORDER BY option_id ASC LIMIT %d",
								$cursor,
								'_transient_%',
								'_site_transient_%',
								'_transient_timeout_%',
								'_site_transient_timeout_%',
								400
							),
							ARRAY_A
						);
				if ( empty( $rows ) || ! is_array( $rows ) ) {
					break;
				}
				$doomed = array();
				foreach ( $rows as $row ) {
					$cursor = (int) $row['option_id'];
					if ( ! self::is_lock_transient( (string) $row['option_name'] ) ) {
						$doomed[] = (string) $row['option_name'];
					}
				}
				if ( ! $this->backup_transient_rows( $doomed ) ) {
					break;
				}
				foreach ( $doomed as $name ) {
					if ( $this->delete_transient_row( $name ) ) {
						++$stats['transients'];
					}
				}
			}
		} elseif ( ! empty( $settings['delete_expired_transients'] ) ) {
			$cursor = 0;
			$now    = time();
			while ( ! $should_stop() ) {
						$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
							$wpdb->prepare(
								// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
								"SELECT option_id, option_name FROM {$options_table} WHERE option_id > %d AND ( option_name LIKE %s OR option_name LIKE %s ) AND CAST(option_value AS UNSIGNED) < %d ORDER BY option_id ASC LIMIT %d",
								$cursor,
								'_transient_timeout_%',
								'_site_transient_timeout_%',
								$now,
								400
							),
							ARRAY_A
						);
				if ( empty( $rows ) || ! is_array( $rows ) ) {
					break;
				}
				$doomed = array_map( 'strval', array_column( $rows, 'option_name' ) );
				$cursor = (int) max( array_map( 'intval', array_column( $rows, 'option_id' ) ) );
				if ( ! $this->backup_transient_rows( $doomed ) ) {
					break;
				}
				foreach ( $doomed as $name ) {
					if ( $this->delete_transient_row( $name ) ) {
						++$stats['transients'];
					}
				}
			}
		}
		$this->append_report_event( $report, __( 'Transients cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Back up rows of one table by their integer key, then delete exactly those rows.
	 *
	 * The shape every SQL-level task of this cleanup shares: select the ids of a batch, write
	 * the rows to the run's backup, delete by id. Deleting by the ids that were backed up — not
	 * by the condition again — is what guarantees nothing goes that is not in the backup.
	 *
	 * @param string $type   Backup type (and the record type the restore dispatches on).
	 * @param string $table  Full table name.
	 * @param string $col    Integer key column.
	 * @param array  $ids    Keys of the batch.
	 * @param string $sample Column that names the batch in the Backups tab.
	 * @return int Rows deleted; 0 when there was nothing, or when the backup failed (nothing deleted).
	 */
	protected function delete_rows_backed_up( $type, $table, $col, array $ids, $sample = '' ) {
		global $wpdb;
		$in = wpoptimizing_backup_in_list( $ids );
		if ( '' === $in || ! $this->backup()->rows( $type, $type, $table, $col, $ids, $sample ) ) {
			return 0;
		}
		$tbl     = $this->quote_identifier( $table );
		$c       = $this->quote_identifier( $col );
		$deleted = $wpdb->query( "DELETE FROM {$tbl} WHERE {$c} IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- quoted identifiers; integer-only list.
		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * Delete oEmbed cache rows in batches.
	 */
	protected function cleanup_oembed_cache( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_oembed_cache'] ) ) {
			return;
		}
		global $wpdb;
		$postmeta_table = $this->quote_identifier( $wpdb->postmeta ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		while ( ! $should_stop() ) {
			$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT meta_id FROM {$postmeta_table} WHERE meta_key LIKE %s OR meta_key LIKE %s LIMIT %d",
					'\_oembed\_%',
					'\_oembed\_time\_%',
					1000
				)
			);
			$deleted = $this->delete_rows_backed_up( 'post_meta', $wpdb->postmeta, 'meta_id', (array) $ids, 'meta_key' );
			if ( $deleted <= 0 ) {
				break;
			}
			$stats['oembed'] += $deleted;
		}
		$this->append_report_event( $report, __( 'oEmbed cache cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Delete unused tags.
	 */
	protected function cleanup_unused_tags( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_unused_tags'] ) ) {
			return;
		}
		global $wpdb;
		$term_taxonomy_table = $this->quote_identifier( $wpdb->term_taxonomy ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$relationships_table = $this->quote_identifier( $wpdb->term_relationships ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$cursor              = 0;
		while ( ! $should_stop() ) {
			// count only counts published posts: a tag used by drafts, scheduled or private
			// posts has count 0 and is still in use. Unused means no relationship at all.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT tt.term_id FROM {$term_taxonomy_table} tt WHERE tt.taxonomy = %s AND tt.term_id > %d AND NOT EXISTS ( SELECT 1 FROM {$relationships_table} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id ) ORDER BY tt.term_id ASC LIMIT %d", 'post_tag', $cursor, 250 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter
			if ( empty( $ids ) || ! is_array( $ids ) ) {
				break;
			}
			$cursor = (int) max( $ids );
			if ( ! $this->backup()->terms( $ids, 'post_tag' ) ) {
				break;
			}
			foreach ( $ids as $term_id ) {
				$deleted = wp_delete_term( (int) $term_id, 'post_tag' );
				if ( ! is_wp_error( $deleted ) && ! empty( $deleted ) ) {
					++$stats['tags'];
				}
			}
		}
		$this->append_report_event( $report, __( 'Unused tags cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Taxonomies this site registers ONLY for post types.
	 *
	 * "Only" is the load-bearing word. A taxonomy registered for both posts and users would
	 * put user relationships in the same table as post ones, and no column tells them apart —
	 * so it is left out entirely rather than half-swept.
	 *
	 * @return string[] Taxonomy names.
	 */
	protected function post_object_taxonomies() {
		if ( ! function_exists( 'get_taxonomies' ) || ! function_exists( 'get_post_types' ) ) {
			return array();
		}
		$post_types = get_post_types( array(), 'names' );
		$out        = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $taxonomy ) {
			$objects = array_filter( (array) $taxonomy->object_type );
			if ( empty( $objects ) ) {
				continue; // Registered for nothing: no way to know what its rows point at.
			}
			if ( ! array_diff( $objects, $post_types ) ) {
				$out[] = (string) $taxonomy->name;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * SELECTs (one id column each) for orphans that are whole objects and must go through
	 * WordPress' own delete functions, plus the usermeta one, keyed by kind. Shared by the
	 * estimate and the cleanup, so the preview counts exactly what the run deletes.
	 *
	 * - revision: a revision whose post no longer exists.
	 * - comment:  a comment on a post that no longer exists — only the kinds WordPress itself
	 *   hangs on posts. Plugins file their own records as comments on objects that are not in
	 *   wp_posts (WooCommerce order notes under HPOS, logs, tickets…): those types are left.
	 * - usermeta: a row whose user no longer exists. usermeta is shared by the whole network,
	 *   so on multisite only the main site cleans it.
	 *
	 * @return array<string,string>
	 */
	protected function orphan_object_sql() {
		global $wpdb;
		$posts    = $this->quote_identifier( $wpdb->posts );
		$comments = $this->quote_identifier( $wpdb->comments );
		$types    = array( 'comment', '', 'pingback', 'trackback', 'review' );
		$out      = array(
			'revision' => $wpdb->prepare( "SELECT r.ID AS id FROM {$posts} r LEFT JOIN {$posts} p ON p.ID = r.post_parent WHERE r.post_type = %s AND r.post_parent > 0 AND p.ID IS NULL", 'revision' ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- back-quoted identifiers.
			'comment'  => $wpdb->prepare( "SELECT c.comment_ID AS id FROM {$comments} c LEFT JOIN {$posts} p ON p.ID = c.comment_post_ID WHERE c.comment_post_ID > 0 AND p.ID IS NULL AND c.comment_type IN (" . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')', $types ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- back-quoted identifiers; one placeholder per type.
		);
		if ( ! is_multisite() || is_main_site() ) {
			$users           = $this->quote_identifier( $wpdb->users );
			$usermeta        = $this->quote_identifier( $wpdb->usermeta );
			$out['usermeta'] = "SELECT um.umeta_id AS id FROM {$usermeta} um LEFT JOIN {$users} u ON u.ID = um.user_id WHERE u.ID IS NULL";
		}
		return $out;
	}

	/**
	 * Delete orphan elements in batches.
	 */
	protected function cleanup_orphans( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_orphan_elements'] ) ) {
			return;
		}
		global $wpdb;

		// Whole objects first, through WordPress, so their meta and counts go with them.
		$objects = $this->orphan_object_sql();
		foreach ( array( 'revision', 'comment' ) as $kind ) {
			while ( isset( $objects[ $kind ] ) && ! $should_stop() ) {
				$ids = $wpdb->get_col( $objects[ $kind ] . ' LIMIT 250' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built by orphan_object_sql() from core table names only, no input.
				if ( empty( $ids ) ) {
					break;
				}
				if ( ! ( 'revision' === $kind ? $this->backup()->posts( $ids, 'revision' ) : $this->backup()->comments( $ids ) ) ) {
					break 2;
				}
				$done = 0;
				foreach ( $ids as $id ) {
					$done += ( 'revision' === $kind ? wp_delete_post( (int) $id, true ) : wp_delete_comment( (int) $id, true ) ) ? 1 : 0;
				}
				$stats['orphans'] += $done;
				if ( 0 === $done ) {
					break; // Nothing would go: do not spin on the same rows.
				}
			}
		}
		$postmeta_table           = $this->quote_identifier( $wpdb->postmeta );
		$posts_table              = $this->quote_identifier( $wpdb->posts );
		$commentmeta_table        = $this->quote_identifier( $wpdb->commentmeta );
		$comments_table           = $this->quote_identifier( $wpdb->comments );
		$term_relationships_table = $this->quote_identifier( $wpdb->term_relationships );
		$terms_table              = $this->quote_identifier( $wpdb->terms );
		$term_taxonomy_table      = $this->quote_identifier( $wpdb->term_taxonomy );

		/*
		 * SELECCIONAR, COPIAR, BORRAR POR ID.
		 *
		 * Cada tarea lee los ids de un lote (LIMIT 1000), escribe esas filas en la copia de esta
		 * ejecución y borra exactamente esos ids. Antes era un DELETE multi-tabla con el LIMIT en
		 * una tabla derivada (MySQL no admite LIMIT en `DELETE pm FROM … JOIN …`) y sin copia: lo
		 * borrado no tenía vuelta. El SELECT conserva la condición y el DELETE ya no la repite, así
		 * que no puede llevarse nada que no esté en la copia.
		 */
		$tasks = array(
			array( 'post_meta', $wpdb->postmeta, 'meta_id', "SELECT pm2.meta_id FROM {$postmeta_table} pm2 LEFT JOIN {$posts_table} p ON p.ID = pm2.post_id WHERE p.ID IS NULL LIMIT 1000", 'meta_key' ),
			array( 'comment_meta', $wpdb->commentmeta, 'meta_id', "SELECT cm2.meta_id FROM {$commentmeta_table} cm2 LEFT JOIN {$comments_table} c ON c.comment_ID = cm2.comment_id WHERE c.comment_ID IS NULL LIMIT 1000", 'meta_key' ),
		);

		/*
		 * term_relationships NO es solo de entradas: los enlaces (`link_category`) viven en la
		 * misma tabla, y wp_set_object_terms() acepta CUALQUIER id: hay plugins que cuelgan
		 * términos de usuarios o de filas de sus propias tablas. Para esas, «su object_id no está
		 * en posts» no significa huérfana. Enumerar excepciones es perder la siguiente, así que
		 * solo se tocan las taxonomías que ESTE sitio registra exclusivamente para tipos de
		 * contenido (y se excluyen además los enlaces). Una taxonomía de un plugin desactivado no
		 * está registrada y sus filas sobreviven — la dirección correcta del error.
		 */
		$links_table     = $this->quote_identifier( $wpdb->links );
		$post_taxonomies = $this->post_object_taxonomies();
		$rel_select      = '';
		if ( ! empty( $post_taxonomies ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_taxonomies ), '%s' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers are back-quoted through quote_identifier(); the only bound values are the taxonomy names, and they go through prepare().
			$rel_select = $wpdb->prepare(
				"SELECT tr2.* FROM {$term_relationships_table} tr2
				 INNER JOIN {$term_taxonomy_table} tt2 ON tt2.term_taxonomy_id = tr2.term_taxonomy_id
				 LEFT JOIN {$posts_table} p ON p.ID = tr2.object_id
				 WHERE p.ID IS NULL
				   AND tt2.taxonomy IN ({$placeholders})
				   AND NOT EXISTS ( SELECT 1 FROM {$links_table} l WHERE l.link_id = tr2.object_id )
				 LIMIT 1000",
				$post_taxonomies
			);
			// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
		}

		if ( isset( $wpdb->termmeta ) && ! empty( $wpdb->termmeta ) ) {
			$termmeta_table = $this->quote_identifier( $wpdb->termmeta );
			$tasks[]        = array( 'term_meta', $wpdb->termmeta, 'meta_id', "SELECT tm2.meta_id FROM {$termmeta_table} tm2 LEFT JOIN {$terms_table} t ON t.term_id = tm2.term_id WHERE t.term_id IS NULL LIMIT 1000", 'meta_key' );
		}
		if ( isset( $objects['usermeta'] ) ) {
			$tasks[] = array( 'user_meta', $wpdb->usermeta, 'umeta_id', $objects['usermeta'] . ' LIMIT 1000', 'meta_key' );
		}
		$tasks[] = array( 'term_taxonomy', $wpdb->term_taxonomy, 'term_taxonomy_id', "SELECT tt2.term_taxonomy_id FROM {$term_taxonomy_table} tt2 LEFT JOIN {$terms_table} t ON t.term_id = tt2.term_id WHERE t.term_id IS NULL LIMIT 1000", 'taxonomy' );

		// Relationships in their own loop: their key is the pair (object, term_taxonomy).
		while ( '' !== $rel_select && ! $should_stop() ) {
			$rows = (array) $wpdb->get_results( $rel_select, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above with $wpdb->prepare().
			if ( empty( $rows ) || ! $this->backup()->row_records( 'term_relationship', 'term_relationship', $rows ) ) {
				break;
			}
			$pairs = array();
			foreach ( $rows as $r ) {
				$pairs[] = '(' . (int) $r['object_id'] . ',' . (int) $r['term_taxonomy_id'] . ')';
			}
			$deleted = $wpdb->query( "DELETE FROM {$term_relationships_table} WHERE (object_id, term_taxonomy_id) IN (" . implode( ',', $pairs ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- quoted identifier; integer-only pairs.
			if ( false === $deleted || 0 === (int) $deleted ) {
				break;
			}
			$stats['orphans'] += (int) $deleted;
		}

		foreach ( $tasks as $task ) {
			list( $type, $table, $col, $select, $sample ) = $task;
			while ( ! $should_stop() ) {
				$ids     = $wpdb->get_col( $select ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built above from quoted identifiers.
				$deleted = $this->delete_rows_backed_up( $type, $table, $col, (array) $ids, $sample );
				if ( $deleted <= 0 ) {
					break;
				}
				$stats['orphans'] += $deleted;
			}
		}
		$this->append_report_event( $report, __( 'Orphan elements cleaned.', 'wpoptimizing' ) );
	}

	/**
	 * Remove EXACT duplicate metadata rows (same object id + meta_key +
	 * meta_value) from post/user/comment/term meta, keeping the oldest row of
	 * each group. Non-destructive in practice: one copy of every distinct value
	 * is always retained. Batched and time-budgeted.
	 */
	protected function cleanup_duplicate_meta( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_duplicate_meta'] ) ) {
			return;
		}
		global $wpdb;

		$tables = array(
			array(
				'table' => $wpdb->postmeta,
				'id'    => 'meta_id',
				'obj'   => 'post_id',
				'type'  => 'post_meta',
			),
			array(
				'table' => $wpdb->usermeta,
				'id'    => 'umeta_id',
				'obj'   => 'user_id',
				'type'  => 'user_meta',
			),
			array(
				'table' => $wpdb->commentmeta,
				'id'    => 'meta_id',
				'obj'   => 'comment_id',
				'type'  => 'comment_meta',
			),
		);
		if ( isset( $wpdb->termmeta ) && ! empty( $wpdb->termmeta ) ) {
			$tables[] = array(
				'table' => $wpdb->termmeta,
				'id'    => 'meta_id',
				'obj'   => 'term_id',
				'type'  => 'term_meta',
			);
		}

		// Each batch re-runs a GROUP BY over the whole table (MD5 of every value): on a 3.3 M-row
		// postmeta that is 1.4 GB read per batch, minutes each, with temporary tables on disk —
		// and the estimate already skipped it on large sites while the cleanup did not. Tables
		// past the threshold are left alone and the report says so; every query is bounded.
		/**
		 * Most rows a meta table may have for "Delete duplicate metadata" to run on it.
		 *
		 * @since 0.1.0
		 * @param int $rows Default 1,000,000.
		 */
		$max_rows = max( 0, (int) apply_filters( 'wpoptimizing_duplicate_meta_max_rows', 1000000 ) );
		$counts   = array();
		$tbl_rows = $wpdb->get_results( 'SELECT TABLE_NAME AS tname, TABLE_ROWS AS trows FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( is_array( $tbl_rows ) ? $tbl_rows : array() as $r ) {
			$counts[ (string) ( $r['tname'] ?? '' ) ] = (int) ( $r['trows'] ?? 0 );
		}
		if ( function_exists( 'wpoptimizing_db_statement_timeout' ) ) {
			wpoptimizing_db_statement_timeout( 30 );
		}

		foreach ( $tables as $t ) {
			if ( $should_stop() ) {
				break;
			}
			$rows_now = (int) ( $counts[ $t['table'] ] ?? 0 );
			if ( $max_rows > 0 && $rows_now > $max_rows ) {
				$this->append_report_event(
					$report,
					sprintf(
						/* translators: 1: table name, 2: number of rows. */
						__( 'Duplicate metadata not checked in %1$s: %2$s rows are too many to compare from here.', 'wpoptimizing' ),
						$t['table'],
						number_format_i18n( $rows_now )
					)
				);
				continue;
			}
			$tbl = $this->quote_identifier( $t['table'] );
			$id  = $t['id'];
			$obj = $t['obj'];
			// Find non-oldest rows of any group sharing (object, meta_key, value).
			// The value is matched by hash so the GROUP BY never sorts a LONGTEXT
			// column directly; a collision would also need the same object+key,
			// which is not realistically possible.
			$select = "SELECT m.{$id} FROM {$tbl} m INNER JOIN ("
				. "SELECT MIN({$id}) AS keep_id, {$obj} AS o, meta_key AS mk, MD5(COALESCE(meta_value,'')) AS h "
				. "FROM {$tbl} GROUP BY {$obj}, meta_key, h HAVING COUNT(*) > 1"
				. ") d ON m.{$obj} = d.o AND m.meta_key = d.mk AND MD5(COALESCE(m.meta_value,'')) = d.h AND m.{$id} > d.keep_id "
				. 'LIMIT 1000';

			while ( ! $should_stop() ) {
				$ids = $wpdb->get_col( $select ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Meta table/column identifier (validated, back-quoted); not bindable.
				// Cut short is not "no duplicates left": stop this table and say so.
				if ( '' !== (string) $wpdb->last_error ) {
					$this->append_report_event(
						$report,
						sprintf(
							/* translators: %s: table name. */
							__( 'Duplicate metadata in %s: the database stopped the search before it finished; run the cleanup again later.', 'wpoptimizing' ),
							$t['table']
						)
					);
					break;
				}
				if ( empty( $ids ) ) {
					break;
				}
				$deleted = $this->delete_rows_backed_up( $t['type'], $t['table'], $id, (array) $ids, 'meta_key' );
				if ( $deleted <= 0 ) {
					break;
				}
				$stats['duplicate_meta'] += $deleted;
			}
		}
		if ( function_exists( 'wpoptimizing_db_statement_timeout_clear' ) ) {
			wpoptimizing_db_statement_timeout_clear(); // The limit was for the searches above only.
		}
		$this->append_report_event(
			$report,
			sprintf(
			/* translators: %d: number of duplicate meta rows removed. */
				__( 'Duplicate metadata removed: %d', 'wpoptimizing' ),
				(int) $stats['duplicate_meta']
			)
		);
	}

	/**
	 * Purge finished Action Scheduler entries (complete/failed/canceled) older than
	 * the configured age, plus their logs. No-op when Action Scheduler is absent.
	 * WooCommerce and many others ship Action Scheduler; its tables grow large.
	 */
	/**
	 * Whether Action Scheduler is present on this site (its own tables exist).
	 * Action Scheduler ships with WooCommerce, its standalone plugin and many
	 * others' vendor/. Cached per-request. UI uses this to hide the option when
	 * it does not apply, for coherence with the ecosystem.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		static $has = null;
		if ( null !== $has ) {
			return $has;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$has   = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		return $has;
	}

	/**
	 * Which Action Scheduler rows count as finished and old: the same fragment for the preview
	 * and the cleanup, so the number promised is the number deleted. Two %s placeholders, both
	 * the cutoff date.
	 *
	 * An action cancelled before it ever ran has no last attempt (a zero date, or NULL on newer
	 * schemas), so «last attempt older than the cutoff» never matched it and it stayed forever —
	 * on a busy shop, thousands of rescheduled-then-cancelled hooks. For those, the date that
	 * says how old they are is the one they were scheduled for.
	 *
	 * @return string
	 */
	protected static function as_finished_where() {
		return "( ( status IN ('complete','failed','canceled') AND last_attempt_gmt IS NOT NULL AND last_attempt_gmt <> '0000-00-00 00:00:00' AND last_attempt_gmt < %s )"
			. " OR ( status = 'canceled' AND ( last_attempt_gmt IS NULL OR last_attempt_gmt = '0000-00-00 00:00:00' ) AND scheduled_date_gmt < %s ) )";
	}

	/**
	 * Log rows older than the cutoff, by ranges of their primary key, whatever their action.
	 *
	 * Through the action_id index, one slice of 5,000 took three to four seconds on
	 * the WP Total Updater site: about 1,200 rows a second, so its 16 million rows would
	 * have taken more than a hundred runs (28/10/2026). An action's log rows are spread over the
	 * whole table, and every one is a random read. Rows in a primary-key range sit together, and
	 * the same deletion ran 2.7 times faster on a test table that fitted in memory.
	 *
	 * A log row is a trace written when it happens, so ids and dates grow together: the oldest
	 * rows are at the start of the table, and the pass stops at a range with nothing old in it
	 * followed by a recent row. Not at the first recent row: a few out of order (a clock that
	 * jumped, rows imported from another site) would stop it with millions of old ones behind.
	 * Nor at the first empty range: deleting by action leaves gaps, and on a test site the pass
	 * stopped at recent rows with 8,000 old ones past the gap that followed (28/10/2026). The
	 * date filter stays in the DELETE, so a recent row is never taken. Rows of
	 * actions that are kept go too once they are older than the cutoff: they are only traces,
	 * and the screen says so. The span is timed and tuned like every other batch.
	 *
	 * @param string   $lt          Quoted logs table.
	 * @param string   $cutoff      GMT date; older rows go.
	 * @param callable $should_stop Time-budget guard.
	 * @return array{0:int,1:bool} Rows deleted, and false when a DELETE failed.
	 */
	protected function purge_old_as_logs( $lt, $cutoff, callable $should_stop ) {
		global $wpdb;
		$deleted = 0;
		$span    = function_exists( 'wpoptimizing_batch_size' ) ? wpoptimizing_batch_size( 'as_log_range', 5000 ) : 5000;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Action Scheduler table identifier (validated and quoted); values are prepared.
		$first = $wpdb->get_row( "SELECT log_id, log_date_gmt FROM {$lt} ORDER BY log_id LIMIT 1", ARRAY_A );
		while ( is_array( $first ) && isset( $first['log_id'] ) && ! $should_stop() ) {
			$from  = (int) $first['log_id'];
			$to    = $from + $span - 1;
			$start = microtime( true );
			$gone  = $wpdb->query( $wpdb->prepare( "DELETE FROM {$lt} WHERE log_id BETWEEN %d AND %d AND log_date_gmt < %s", $from, $to, $cutoff ) );
			if ( false === $gone ) {
				return array( $deleted, false );
			}
			$deleted += (int) $gone;
			if ( function_exists( 'wpoptimizing_tune_batch' ) ) {
				$span = wpoptimizing_tune_batch( $span, microtime( true ) - $start, 1.0, 1000, 100000 );
			}
			// The next row left, past the gaps earlier deletions made.
			$first  = $wpdb->get_row( $wpdb->prepare( "SELECT log_id, log_date_gmt FROM {$lt} WHERE log_id > %d ORDER BY log_id LIMIT 1", $to ), ARRAY_A );
			$recent = ! is_array( $first ) || ! isset( $first['log_date_gmt'] ) || strcmp( (string) $first['log_date_gmt'], $cutoff ) >= 0;
			if ( 0 === (int) $gone && $recent ) {
				break;
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( function_exists( 'wpoptimizing_batch_size_learn' ) ) {
			wpoptimizing_batch_size_learn( 'as_log_range', $span );
		}
		return array( $deleted, true );
	}

	protected function cleanup_action_scheduler( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_action_scheduler'] ) || ! self::has_action_scheduler() ) {
			return;
		}
		global $wpdb;
		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$logs    = $wpdb->prefix . 'actionscheduler_logs';
		$days    = max( 0, min( 365, (int) ( $settings['action_scheduler_max_age_days'] ?? 30 ) ) );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$at      = $this->quote_identifier( $actions );
		$lt      = $this->quote_identifier( $logs );

		list( $logs_deleted, $logs_ok ) = $this->purge_old_as_logs( $lt, $cutoff, $should_stop );

		while ( $logs_ok && ! $should_stop() ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Action Scheduler table/column identifier (validated); the two placeholders live in as_finished_where().
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT action_id FROM {$at} WHERE " . self::as_finished_where() . ' LIMIT 1000',
					$cutoff,
					$cutoff
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
			if ( empty( $ids ) ) {
				break;
			}
			$in = implode( ',', $ids ); // absint-only, safe to inline.
			// Their logs are deleted without a copy. They are trace lines («action started»,
			// «action complete») Action Scheduler runs without, and they can be a hundred per
			// action: 27 million rows on the WP Total Updater site (27/10/2026), which would
			// have meant a 4–5 GB backup of nothing anyone restores. The screen says so before the
			// run, and the report counts them.
			//
			// In slices, and before the actions are copied. One DELETE for a batch whose old actions
			// carried millions of log rows ran for more than nine minutes: the web request died with
			// a 500 while MariaDB went on deleting (28/10/2026). A slice is quick, the time budget is
			// checked between slices, and a run that stops halfway leaves its actions untouched and
			// not yet copied, so the next run takes the same batch and carries on.
			// A failed slice is not an empty one: a lock wait timeout behind another DELETE on the
			// same rows returned false, read as «no logs left», and the actions went with their logs
			// still there (28/10/2026). An error stops the run instead.
			do {
				$sliced = $wpdb->query( "DELETE FROM {$lt} WHERE action_id IN ({$in}) LIMIT 5000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Action Scheduler table/column identifier (validated); absint-only IN() list.
				if ( false === $sliced ) {
					break 2;
				}
				$logs_deleted += (int) $sliced;
			} while ( 5000 === (int) $sliced && ! $should_stop() );
			if ( 5000 === (int) $sliced ) {
				break;
			}
			if ( ! $this->backup()->rows( 'action_scheduler', 'as_action', $actions, 'action_id', $ids, 'hook' ) ) {
				break;
			}
			$deleted = $wpdb->query( "DELETE FROM {$at} WHERE action_id IN ({$in})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Action Scheduler table/column identifier (validated); IN() uses generated placeholders.
			if ( false === $deleted || 0 === (int) $deleted ) {
				break;
			}
			$stats['action_scheduler'] = (int) ( $stats['action_scheduler'] ?? 0 ) + (int) $deleted;
		}
		$stats['action_scheduler_logs'] = (int) ( $stats['action_scheduler_logs'] ?? 0 ) + (int) $logs_deleted;
		$this->append_report_event(
			$report,
			sprintf(
			/* translators: %d: number of Action Scheduler entries removed. */
				__( 'Action Scheduler: removed %d finished entries.', 'wpoptimizing' ),
				(int) ( $stats['action_scheduler'] ?? 0 )
			)
		);
		if ( $logs_deleted > 0 ) {
			$this->append_report_event(
				$report,
				sprintf(
				/* translators: %s: number of Action Scheduler log rows deleted. */
					__( 'Action Scheduler: %s log rows deleted without a backup.', 'wpoptimizing' ),
					number_format_i18n( $logs_deleted )
				)
			);
		}
	}

	/**
	 * Whether WooCommerce is INSTALLED AND ACTIVE on this site.
	 *
	 * class_exists() rather than a table probe: the tables outlive a deactivation, and running
	 * store cleanup against a store whose plugin is switched off is how you delete data the
	 * owner is about to switch back on. Every WooCommerce task is hidden, not merely disabled,
	 * when this is false.
	 *
	 * @since 0.1.0
	 * @return bool
	 */
	public static function has_woocommerce() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Expired customer sessions.
	 *
	 * WooCommerce schedules its own `woocommerce_cleanup_sessions`, and on a busy store that
	 * event routinely falls behind or stops running altogether (a cron that never fires, a
	 * queue that never drains). The table then grows without limit — hundreds of megabytes of
	 * rows whose expiry passed months ago. They are already dead: WooCommerce itself treats an
	 * expired session as absent, so removing them changes nothing a shopper can see.
	 *
	 * Deleted by expiry only. Never by age, never by count.
	 *
	 * @since 0.1.0
	 * @param array    $stats       Running stats.
	 * @param array    $settings    Cleanup settings.
	 * @param callable $should_stop Time-budget guard.
	 * @param array    $report      Report accumulator.
	 * @return void
	 */
	protected function cleanup_wc_sessions( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_wc_sessions'] ) || ! self::has_woocommerce() ) {
			return;
		}
		global $wpdb;
		$table = $this->quote_identifier( $wpdb->prefix . 'woocommerce_sessions' ); // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter
		$now   = time();

		while ( ! $should_stop() ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- WooCommerce table identifier (built from $wpdb->prefix and quoted); value is prepared.
			// 500 a batch: a session row carries the whole cart, and the batch goes to the backup first.
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT session_id FROM {$table} WHERE session_expiry < %d LIMIT 500", $now ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$deleted = $this->delete_rows_backed_up( 'wc_session', $wpdb->prefix . 'woocommerce_sessions', 'session_id', (array) $ids );
			if ( $deleted <= 0 ) {
				break;
			}
			$stats['wc_sessions'] = (int) ( $stats['wc_sessions'] ?? 0 ) + $deleted;
		}

		$this->append_report_event(
			$report,
			sprintf(
				/* translators: %d: number of expired WooCommerce sessions removed. */
				__( 'WooCommerce: removed %d expired customer sessions.', 'wpoptimizing' ),
				(int) ( $stats['wc_sessions'] ?? 0 )
			)
		);
	}

	/**
	 * Product variations whose parent product is gone.
	 *
	 * A variation is meaningless without its parent: it cannot be bought, edited or found, and
	 * it drags its postmeta, its lookup row and its term relationships along with it. They
	 * accumulate when products are deleted directly in the database, by an importer, or by a
	 * migration that missed the children.
	 *
	 * DELETED THROUGH wp_delete_post(), NOT SQL. It is far slower, and that is the price of
	 * being correct: WooCommerce hooks `delete_post` to clear wc_product_meta_lookup, the
	 * product transients and its own caches. A direct DELETE leaves those rows pointing at a
	 * post that no longer exists — trading one kind of orphan for a worse one, inside the
	 * tables the store reads on every shop page.
	 *
	 * The parent test is deliberately narrow: parent 0, a parent row that does not exist, or a
	 * parent that is not a product. A parent in the trash is NOT an orphan — the shop owner can
	 * still restore it.
	 *
	 * @since 0.1.0
	 * @param array    $stats       Running stats.
	 * @param array    $settings    Cleanup settings.
	 * @param callable $should_stop Time-budget guard.
	 * @param array    $report      Report accumulator.
	 * @return void
	 */
	protected function cleanup_wc_orphan_variations( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['delete_wc_orphan_variations'] ) || ! self::has_woocommerce() || ! function_exists( 'wp_delete_post' ) ) {
			return;
		}
		global $wpdb;

		while ( ! $should_stop() ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- Orphan detection needs a LEFT JOIN the post API cannot express.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT v.ID FROM {$wpdb->posts} v
					 LEFT JOIN {$wpdb->posts} p ON p.ID = v.post_parent
					 WHERE v.post_type = %s
					   AND ( v.post_parent = 0 OR p.ID IS NULL OR p.post_type <> %s )
					 LIMIT 100",
					'product_variation',
					'product'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
			if ( empty( $ids ) ) {
				break;
			}
			if ( ! $this->backup()->posts( $ids, 'product_variation' ) ) {
				break;
			}
			$removed = 0;
			foreach ( $ids as $id ) {
				if ( $should_stop() ) {
					break 2;
				}
				if ( wp_delete_post( $id, true ) ) {
					++$removed;
					$stats['wc_variations'] = (int) ( $stats['wc_variations'] ?? 0 ) + 1;
				}
			}

			// A batch where nothing could be deleted would be selected again identically on the
			// next pass: a plugin vetoing deletion through pre_delete_post turns this into a
			// spin that eats the whole time budget, and every cleanup task queued after this one
			// silently never runs.
			if ( 0 === $removed ) {
				break;
			}
		}

		$this->append_report_event(
			$report,
			sprintf(
				/* translators: %d: number of orphaned product variations removed. */
				__( 'WooCommerce: removed %d orphaned product variations.', 'wpoptimizing' ),
				(int) ( $stats['wc_variations'] ?? 0 )
			)
		);
	}

	/**
	 * Optimize tables with exclusion support.
	 */
	protected function optimize_tables( array &$stats, array $settings, callable $should_stop, array &$report ) {
		if ( empty( $settings['optimize_tables'] ) ) {
			return;
		}
		global $wpdb;
		$db_schema = defined( 'DB_NAME' ) ? DB_NAME : ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' );
		$excluded  = isset( $settings['excluded_optimize_tables'] ) && is_array( $settings['excluded_optimize_tables'] ) ? array_flip( $settings['excluded_optimize_tables'] ) : array();

		$tables = $this->get_optimizable_tables();
		foreach ( $tables as $table ) {
			if ( $should_stop() ) {
				break;
			}
			if ( isset( $excluded[ $table ] ) ) {
				continue;
			}

			$engine = '';
			if ( '' !== $db_schema ) {
				$engine = (string) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->prepare(
						'SELECT ENGINE FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s',
						$db_schema,
						$table
					)
				);
			}

			if ( 'innodb' === strtolower( $engine ) ) {
				$result = $wpdb->query( 'ANALYZE TABLE ' . $this->quote_identifier( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			} else {
				$result = $wpdb->query( 'OPTIMIZE TABLE ' . $this->quote_identifier( $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			}
			if ( false !== $result ) {
				++$stats['optimized_tables'];
			}
		}
		$this->append_report_event( $report, __( 'Table optimization finished.', 'wpoptimizing' ) );
	}

	/**
	 * Get current total database size in bytes.
	 *
	 * @return int
	 */
	protected function get_database_size_bytes() {
		global $wpdb;
		// THIS IS THE READING THE WHOLE CLEANUP REPORT RESTS ON. It is taken before and after,
		// and the difference is what the user is told they reclaimed. Without live statistics
		// both readings come from a cache that cannot have noticed the deletion in between, so
		// an optimisation plugin reports "0 bytes freed" after freeing hundreds of megabytes.
		if ( function_exists( 'wpoptimizing_db_live_stats' ) ) {
			wpoptimizing_db_live_stats();
		}

		$db_schema = defined( 'DB_NAME' ) ? DB_NAME : ( isset( $wpdb->dbname ) ? (string) $wpdb->dbname : '' );
		$total     = 0;

		if ( '' !== $db_schema ) {
			$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.TABLES WHERE table_schema = %s',
					$db_schema
				)
			);
			if ( $total > 0 ) {
				return $total;
			}
		}

		$rows = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( ! is_array( $rows ) ) {
			return 0;
		}
		foreach ( $rows as $row ) {
			$total += (int) ( (int) ( $row['Data_length'] ?? 0 ) + (int) ( $row['Index_length'] ?? 0 ) );
		}
		return max( 0, $total );
	}
}
