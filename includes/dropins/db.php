<?php
/**
 * Plugin Name: WPOptimizing Query Deduplication
 * Description: Database drop-in installed by WPOptimizing. Memoises the result of
 *              IDENTICAL read queries (SELECT/SHOW/DESCRIBE/EXPLAIN) within a
 *              SINGLE request so the same query never hits MySQL twice. Any write
 *              (INSERT/UPDATE/DELETE/…) clears the per-request memo, so results
 *              are never stale. It is per-request only (nothing persists between
 *              requests) and degrades to the stock wpdb on any error.
 *
 * WPOPT_DB_DROPIN v11  (signature — do not remove: WPOptimizing uses it to
 * recognise and safely manage/uninstall its own drop-in.)
 *
 * Self-contained drop-in: loads before plugins, cannot use WPOptimizing classes.
 * Do not edit by hand; manage it from the plugin's Cache tab.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// PANIC SWITCH. `define( 'WPOPT_DISABLE', true );` in wp-config.php stands every piece of
// WPOptimizing that runs BEFORE the plugin down — this drop-in, the other drop-ins and the
// must-use runtime — without FTP, without touching the database and without deactivating
// anything. Code that executes before WordPress has any recovery path of its own needs a way
// out that does not depend on the admin being reachable; this is it, and it is the first thing
// any agency asks for before installing software that loads this early.
if ( defined( 'WPOPT_DISABLE' ) && WPOPT_DISABLE ) {
	return;
}

// A db.php drop-in must define the global $wpdb (a wpdb subclass). The prefix and
// file-name sniffs do not apply; low-level I/O is unavoidable this early.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors
// phpcs:disable Squiz.Commenting.FunctionComment, Generic.Commenting.DocComment

if ( ! class_exists( 'wpdb' ) ) {
	// wpdb is normally loaded just before this drop-in; if not, do nothing and
	// let WordPress create the stock $wpdb.
	return;
}

/**
 * wpdb subclass that memoises identical read queries for the duration of one
 * request. Conservative by design: only deterministic SELECT/SHOW reads are
 * cached, any write busts the memo, and any internal error falls back to wpdb.
 */
class WPOpt_DB extends wpdb {

	/** @var array<string,array> Per-request memo: SQL => snapshot. */
	public $wpopt_memo = array();

	/** @var array<string,int> Per-request execution counts per SQL. */
	public $wpopt_counts = array();

	/** @var int Total read queries seen. */
	public $wpopt_reads = 0;

	/** @var int Read queries served from the memo (duplicates avoided). */
	public $wpopt_dups = 0;

	/** @var int Soft cap on memoised rows per query (bounds memory). */
	private $wpopt_max_rows = 500;

	/**
	 * Hard cap on how many DISTINCT queries are memoised.
	 *
	 * The per-query row cap bounds one entry; nothing bounded how many entries there were. A
	 * normal page load has a few hundred distinct reads and never approaches this, but the
	 * memo lives for the whole process — and a WP-CLI run, a long cron or this plugin's own
	 * scanner reads for minutes without a single write to clear it. At 500 rows an entry,
	 * tens of thousands of distinct reads exhaust the memory limit, and the process that dies
	 * is killed by the drop-in that was supposed to make it faster.
	 *
	 * Past the cap nothing new is stored: the entries already held keep serving duplicates, so
	 * the feature degrades instead of failing.
	 *
	 * @var int
	 */
	private $wpopt_max_keys = 2000;

	/** @var array<string,int> Query counts by verb: sel|ins|upd|del. */
	public $wpopt_types = array();

	/** @var int Queries slower than the threshold. */
	public $wpopt_slow = 0;

	/** @var float Total time spent in real DB queries (seconds). */
	public $wpopt_time = 0.0;

	/** @var array<string,int> Duplicate hits avoided, by owning component. */
	public $wpopt_components = array();

	/** @var array<string,string> Cache of SQL => attributed component. */
	private $wpopt_query_component = array();

	/** @var array<int,array{op:string,tbl:string,comp:string}> DDL seen this request. */
	public $wpopt_ddl = array();

	/** @var float Slow-query threshold in seconds. */
	private $wpopt_slow_threshold = 0.05;

	/**
	 * Intercept queries: memoise duplicate reads, bust the memo on writes.
	 *
	 * @param string $query SQL.
	 * @return int|bool Same return contract as wpdb::query().
	 */
	public function query( $query ) {
		try {
			$trim = ltrim( (string) $query );
			$this->wpopt_count_type( $trim );

			// Non-read statements (writes, SET, transaction control, …) may change
			// what reads return, so drop the whole per-request memo.
			if ( ! preg_match( '/^\(?\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $trim ) ) {
				if ( $this->wpopt_memo ) {
					$this->wpopt_memo = array();
				}
				$this->wpopt_note_ddl( $trim );
				return $this->wpopt_run( $query );
			}

			// Skip non-deterministic reads (time/random/locks/found_rows/vars).
			if ( $this->wpopt_is_volatile( $trim ) ) {
				return $this->wpopt_run( $query );
			}

			++$this->wpopt_reads;
			$key = (string) $query;

			if ( isset( $this->wpopt_memo[ $key ] ) ) {
				$snap                       = $this->wpopt_memo[ $key ];
				$this->last_result          = $this->wpopt_clone_rows( $snap['last_result'] );
				$this->num_rows             = $snap['num_rows'];
				$this->last_query           = $query;
				$this->last_error           = '';
				$this->col_info             = $snap['col_info'];
				$this->wpopt_counts[ $key ] = ( isset( $this->wpopt_counts[ $key ] ) ? $this->wpopt_counts[ $key ] : 1 ) + 1;
				++$this->wpopt_dups;
				// Attribute the duplicate to its owning plugin/theme (backtrace is
				// taken once per distinct duplicated query, then cached).
				if ( ! isset( $this->wpopt_query_component[ $key ] ) ) {
					$this->wpopt_query_component[ $key ] = $this->wpopt_caller_component();
				}
				$comp                            = $this->wpopt_query_component[ $key ];
				$this->wpopt_components[ $comp ] = ( isset( $this->wpopt_components[ $comp ] ) ? $this->wpopt_components[ $comp ] : 0 ) + 1;
				return $snap['return'];
			}

			$ret = $this->wpopt_run( $query );

			// Only memoise a successful, reasonably small result set — and only while there is
			// room, so a long-running read loop cannot grow the memo without limit.
			if ( '' === (string) $this->last_error && is_array( $this->last_result )
				&& count( $this->last_result ) <= $this->wpopt_max_rows
				&& count( $this->wpopt_memo ) < $this->wpopt_max_keys ) {
				$this->wpopt_memo[ $key ]   = array(
					'return'      => $ret,
					'last_result' => $this->wpopt_clone_rows( $this->last_result ),
					'num_rows'    => $this->num_rows,
					'col_info'    => $this->col_info,
				);
				$this->wpopt_counts[ $key ] = 1;
			}
			return $ret;
		} catch ( \Throwable $e ) {
			// Never let memoisation break a query: fall back to the stock path.
			return parent::query( $query );
		}
	}

	/**
	 * Copies of the result rows, so the memo and each caller hold their own objects.
	 *
	 * wpdb hands back stdClass rows, which PHP passes by handle. The memo kept the very objects
	 * the first caller received and gave the same ones to the second: a caller that changed a row
	 * (cast a field, unset it, hung a computed value on it) changed what the next identical query
	 * returned, and a field of the wrong type reached code that relied on the stock wpdb's fresh
	 * rows and threw a TypeError.
	 * Cloned when stored and again on every hit. Shallow is enough: the properties are scalars.
	 *
	 * @param mixed $rows last_result.
	 * @return mixed
	 */
	private function wpopt_clone_rows( $rows ) {
		if ( ! is_array( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as $i => $row ) {
			if ( is_object( $row ) ) {
				$rows[ $i ] = clone $row;
			}
		}
		return $rows;
	}

	/**
	 * Run a real DB query, timing it and flagging slow ones.
	 *
	 * @param string $query SQL.
	 * @return int|bool
	 */
	private function wpopt_run( $query ) {
		$t0                = microtime( true );
		$ret               = parent::query( $query );
		$dt                = microtime( true ) - $t0;
		$this->wpopt_time += $dt;
		if ( $dt >= $this->wpopt_slow_threshold ) {
			++$this->wpopt_slow;
		}
		return $ret;
	}

	/**
	 * Tally the query verb (cheap leading-keyword match).
	 *
	 * @param string $trim Left-trimmed SQL.
	 * @return void
	 */
	private function wpopt_count_type( $trim ) {
		if ( preg_match( '/^\(?\s*(SELECT|INSERT|REPLACE|UPDATE|DELETE)/i', $trim, $m ) ) {
			$map                     = array(
				'SELECT'  => 'sel',
				'INSERT'  => 'ins',
				'REPLACE' => 'ins',
				'UPDATE'  => 'upd',
				'DELETE'  => 'del',
			);
			$k                       = $map[ strtoupper( $m[1] ) ];
			$this->wpopt_types[ $k ] = ( isset( $this->wpopt_types[ $k ] ) ? $this->wpopt_types[ $k ] : 0 ) + 1;
		}
	}

	/**
	 * The caller WordPress records for every logged query.
	 *
	 * WHY THIS OVERRIDE EXISTS. wpdb::get_caller() calls wp_debug_backtrace_summary( __CLASS__ ),
	 * and that filter is a STRING COMPARISON against 'wpdb'. This class is WPOpt_DB, so our own
	 * frames are not filtered out and end up as the reported caller of every query on the site:
	 * `WPOpt_DB->wpopt_run`. Everything that reads $wpdb->queries then attributes the entire
	 * database workload to us — Query Monitor, Performance Lab's Server-Timing, external
	 * profilers, and this plugin's own performance report, which is how it was found: a profile
	 * of a production site came back with all fifteen duplicate-query patterns sharing one
	 * caller, ours.
	 *
	 * Installing a db.php drop-in must not cost the site its query attribution. Both this class
	 * and every parent of it are skipped, so the first frame reported is the code that actually
	 * asked for the query.
	 *
	 * @since 0.1.0
	 * @return string Comma-separated call chain, in the same shape core produces.
	 */
	public function get_caller() {
		if ( ! function_exists( 'wp_debug_backtrace_summary' ) ) {
			return parent::get_caller();
		}

		$skip   = array( get_class( $this ) );
		$parents = class_parents( $this );
		if ( is_array( $parents ) ) {
			$skip = array_merge( $skip, array_values( $parents ) );
		}

		// Which plugin ran the query: the attribution the query analytics show, not debugging output.
		$frames = wp_debug_backtrace_summary( null, 1, false ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_wp_debug_backtrace_summary
		if ( ! is_array( $frames ) ) {
			return (string) $frames;
		}

		$kept = array();
		foreach ( $frames as $frame ) {
			$own = false;
			foreach ( $skip as $class ) {
				if ( 0 === strpos( $frame, $class . '->' ) || 0 === strpos( $frame, $class . '::' ) ) {
					$own = true;
					break;
				}
			}
			if ( ! $own ) {
				$kept[] = $frame;
			}
		}

		// Core reverses before joining; match it so consumers parse the same order.
		return implode( ', ', array_reverse( $kept ) );
	}

	/**
	 * Best-effort owning component of the current query, from the call stack.
	 *
	 * @return string e.g. "plugin:woocommerce", "theme:astra", "mu-plugin", "core".
	 */
	/**
	 * Record who issued a schema change.
	 *
	 * The attribution engine works by comparing snapshots across lifecycle events — install,
	 * activate, deactivate, update, scan. That leaves a hole it cannot close: a plugin that
	 * creates its table WEEKS after activation, which is what a deferred migration or a module
	 * that installs on first use does. The diff sees a new table with no author, the element
	 * falls to a low confidence level, and low confidence is excluded from automatic cleanup.
	 * It becomes database weight the product cannot remove.
	 *
	 * A backtrace answers it exactly, and here it is free: DDL is vanishingly rare in a normal
	 * request — most requests run none at all — so the cost that would be unacceptable on a
	 * SELECT is unmeasurable on a CREATE TABLE. The result is CERTAIN authorship, from the
	 * stack, where today there is none at all.
	 *
	 * What this attributes is "whose code created it", which is the honest answer and not
	 * always the same as "whose data it is": a library bundled inside a host attributes to that
	 * host. The bundled-library shield handles that case on top of this.
	 *
	 * @param string $trim Trimmed statement.
	 * @return void
	 */
	private function wpopt_note_ddl( $trim ) {
		// Cheap first test: skip the overwhelming majority (INSERT/UPDATE/DELETE/SET/…) on one
		// character-class check before touching a real pattern.
		if ( ! preg_match( '/^(CREATE|ALTER|DROP|RENAME|TRUNCATE)\b/i', $trim ) ) {
			return;
		}
		if ( count( $this->wpopt_ddl ) >= 20 ) {
			return; // A migration storm records its first 20; nobody needs the rest.
		}
		$tables = $this->wpopt_ddl_tables( $trim );
		if ( ! $tables ) {
			return;
		}
		$comp = $this->wpopt_caller_component();
		$op   = strtoupper( (string) preg_replace( '/^\s*(\w+).*$/s', '$1', $trim ) );
		foreach ( $tables as $t ) {
			$this->wpopt_ddl[] = array(
				'op'   => $op,
				'tbl'  => $t,
				'comp' => $comp,
			);
		}
	}

	/**
	 * Table names touched by a DDL statement.
	 *
	 * Deliberately narrow: only the shapes WordPress code actually emits. Anything it does not
	 * recognise yields nothing, which costs an attribution we never had — never a wrong one.
	 *
	 * @param string $trim Trimmed statement.
	 * @return string[] Unqualified table names, lowercased.
	 */
	private function wpopt_ddl_tables( $trim ) {
		$out = array();
		// Anchored to the START of the statement. Unanchored, an UPDATE whose value happens to
		// contain the words "CREATE TABLE" parses as DDL — and relying on the caller having
		// already checked the leading keyword is exactly the kind of guard-lives-elsewhere
		// assumption that has bitten this codebase before.
		$pat = '/^\s*(?:CREATE\s+(?:TEMPORARY\s+)?TABLE(?:\s+IF\s+NOT\s+EXISTS)?'
			. '|ALTER\s+TABLE'
			. '|DROP\s+(?:TEMPORARY\s+)?TABLE(?:\s+IF\s+EXISTS)?'
			. '|RENAME\s+TABLE'
			. '|TRUNCATE(?:\s+TABLE)?)\s+([`"\w.,\s]+?)(?:\s*\(|\s+TO\b|\s+SET\b|\s|$)/i';
		if ( ! preg_match( $pat, $trim, $m ) ) {
			return $out;
		}
		foreach ( explode( ',', (string) $m[1] ) as $raw ) {
			$raw = trim( str_replace( array( '`', '"' ), '', $raw ) );
			if ( '' === $raw ) {
				continue;
			}
			// db.table -> table.
			$parts = explode( '.', $raw );
			$name  = strtolower( trim( (string) end( $parts ) ) );
			if ( '' !== $name && preg_match( '/^[a-z0-9_]+$/', $name ) ) {
				$out[ $name ] = $name;
			}
		}

		return array_values( $out );
	}

	private function wpopt_caller_component() {
		$self   = str_replace( '\\', '/', __FILE__ );
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.RestrictedFunctions.debug_backtrace_debug_backtrace, WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Runtime attribution evidence; intentional.
		foreach ( $frames as $f ) {
			if ( empty( $f['file'] ) ) {
				continue;
			}
			$file = str_replace( '\\', '/', $f['file'] );
			// Skip THIS file by identity, not by the literal path "/wp-content/db.php". A
			// hardened install with a renamed content directory puts the drop-in somewhere
			// else, the frame stops being skipped, and every attribution collapses to the
			// drop-in's own component — which resolves to "core". That silently degraded the
			// duplicate-query attribution too, not just the schema capture, and only showed up
			// by running it on a simulated renamed install.
			if ( $file === $self
				|| false !== strpos( $file, '/wp-includes/' )
				|| false !== strpos( $file, '/wp-admin/includes/' ) ) {
				continue;
			}
			return $this->wpopt_file_component( $file );
		}
		return 'core';
	}

	/**
	 * Map an absolute file path to a component label.
	 *
	 * @param string $file Forward-slashed absolute path.
	 * @return string
	 */
	private function wpopt_file_component( $file ) {
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$pd = str_replace( '\\', '/', WP_PLUGIN_DIR );
			if ( 0 === strpos( $file, $pd . '/' ) ) {
				$rest = ltrim( substr( $file, strlen( $pd ) ), '/' );
				$slug = explode( '/', $rest )[0];
				return 'plugin:' . preg_replace( '/\.php$/', '', $slug );
			}
		}
		if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
			$md = str_replace( '\\', '/', WPMU_PLUGIN_DIR );
			if ( 0 === strpos( $file, $md . '/' ) ) {
				return 'mu-plugin';
			}
		}
		$themes = ( defined( 'WP_CONTENT_DIR' ) ? str_replace( '\\', '/', WP_CONTENT_DIR ) : '' ) . '/themes';
		if ( '/themes' !== $themes && 0 === strpos( $file, $themes . '/' ) ) {
			$rest = ltrim( substr( $file, strlen( $themes ) ), '/' );
			return 'theme:' . explode( '/', $rest )[0];
		}
		return 'core';
	}

	/**
	 * Whether a read query is non-deterministic and must not be memoised.
	 *
	 * @param string $q SQL.
	 * @return bool
	 */
	private function wpopt_is_volatile( $q ) {
		// A LOCKING read is never memoisable, and this is the one entry here whose absence
		// corrupts data rather than merely returning something stale. `SELECT ... FOR UPDATE`
		// starts with SELECT, so without this it was memoised like any other read: the second
		// identical lock read in a request returned the cached rows WITHOUT reaching MySQL and
		// therefore WITHOUT taking the row lock. The caller believes it holds exclusivity and
		// does not. Nothing errors; you get a race under concurrency — stock, payments,
		// sequential invoice numbers — on exactly the kind of site this plugin is sold for.
		//
		// \s+ spans newlines and tabs on its own, so a statement a query builder emitted across
		// several lines ("FOR\n  UPDATE") is caught without normalising the string first.
		//
		// This deliberately also matches the words inside a string literal — WHERE note =
		// 'ready for update' is treated as volatile and simply not deduplicated. That is the
		// safe direction to be wrong in: the cost is one missed optimisation, while the cost of
		// the opposite mistake is a lock that was never taken. Do not "tighten" this to skip
		// quoted sections.
		if ( preg_match( '/\bFOR\s+(?:UPDATE|SHARE)\b|\bLOCK\s+IN\s+SHARE\s+MODE\b/i', (string) $q ) ) {
			return true;
		}

		return (bool) preg_match(
			'/\b(RAND|NOW|SYSDATE|CURDATE|CURTIME|CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME|LOCALTIME|LOCALTIMESTAMP|UNIX_TIMESTAMP|UTC_DATE|UTC_TIME|UTC_TIMESTAMP|LAST_INSERT_ID|FOUND_ROWS|GET_LOCK|RELEASE_LOCK|IS_FREE_LOCK|IS_USED_LOCK|SLEEP|UUID|UUID_SHORT|CONNECTION_ID|BENCHMARK)\s*\(/i',
			$q
		) || false !== stripos( $q, 'SQL_CALC_FOUND_ROWS' )
			|| false !== strpos( $q, '@@' )
			|| (bool) preg_match( '/(?<![A-Za-z0-9_])@[A-Za-z0-9_]/', $q );
	}

	/**
	 * The top duplicated queries this request (executed more than once).
	 *
	 * @param int $limit Max entries.
	 * @return array<int,array{sql:string,count:int}>
	 */
	public function wpopt_duplicates( $limit = 20 ) {
		$out = array();
		foreach ( $this->wpopt_counts as $sql => $count ) {
			if ( $count > 1 ) {
				$out[] = array(
					'sql'   => (string) substr( preg_replace( '/\s+/', ' ', (string) $sql ), 0, 300 ),
					'count' => (int) $count,
					'comp'  => (string) ( isset( $this->wpopt_query_component[ $sql ] ) ? $this->wpopt_query_component[ $sql ] : '' ),
				);
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);
		return array_slice( $out, 0, $limit );
	}
}

/**
 * Path to the analytics file shared with the object-cache drop-in.
 *
 * @return string
 */
function wpopt_db_stats_file() {
	$base = WP_CONTENT_DIR;
	return $base . '/cache/wpopt-cache-stats.json';
}

/**
 * The append-only journal the visitor path writes to.
 *
 * Kept beside the aggregate it feeds. One line of compact JSON per request; folded into
 * wpopt_db_stats_file() by wpoptimizing_db_journal_consolidate() on admin_init.
 *
 * @return string
 */
function wpopt_db_journal_file() {
	$base = WP_CONTENT_DIR;
	return $base . '/cache/wpopt-db-journal.log';
}

/**
 * After an out-of-memory fatal, give the shutdown handlers 32 MB more to run in. Once per
 * request, however many handlers ask: the gateway and the object-cache drop-in have their own copy and all
 * share the wpopt_oom_raised flag. Not one shared, guarded function: a function defined inside an
 * `if` only exists once execution reaches it, and this file returns early on some paths.
 *
 * The raise is what saves the handler: measured on 14/10/2026 with PHP 8.5, after the fatal a
 * handler can fail to allocate 2 MB with half the limit free, and raising memory_limit inside
 * it always worked. The 64 KB reserve stays for hosts where ini_set() is disabled. What this
 * cannot fix is WordPress's own fatal-error handler, registered before any drop-in and run
 * first: if it dies, PHP skips every handler after it.
 *
 * @return void
 */
function wpopt_db_oom_raise() {
	if ( ! empty( $GLOBALS['wpopt_oom_raised'] ) ) {
		return;
	}
	$error = error_get_last();
	if ( ! is_array( $error ) || E_ERROR !== (int) $error['type'] || false === strpos( (string) $error['message'], 'Allowed memory size' ) ) {
		return;
	}
	$GLOBALS['wpopt_oom_raised'] = true;
	$limit = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0;
	if ( $limit > 0 ) {
		@ini_set( 'memory_limit', (string) ( $limit + 32 * 1024 * 1024 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.IniSet, Squiz.PHP.DiscouragedFunctions.Discouraged -- 32 MB more only while dying of memory exhaustion, so the error page can be built.
	}
}

/**
 * Shutdown callback: fold this request's query/dup counts and the duplicate
 * sample into the durable daily analytics bucket.
 *
 * @return void
 */
function wpopt_db_persist_stats() {
	unset( $GLOBALS['wpopt_db_reserve'] ); // First: after a memory fatal this is all the room there is.
	wpopt_db_oom_raise();
	$wpdb = isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb'] : null;
	if ( ! ( $wpdb instanceof WPOpt_DB ) ) {
		return;
	}
	$reads = (int) $wpdb->wpopt_reads;
	$dups  = (int) $wpdb->wpopt_dups;
	$ddl   = is_array( $wpdb->wpopt_ddl ) ? $wpdb->wpopt_ddl : array();
	if ( $reads < 1 && $dups < 1 && ! $ddl ) {
		return;
	}

	$file = wpopt_db_journal_file();
	$dir  = dirname( $file );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return;
	}

	// Schema changes go on their own line, and go FIRST. They are the rare, valuable record
	// here — a request that made no read at all can still have created a table — so they must
	// not ride on the counters' early return or share their size budget.
	if ( $ddl ) {
		$line = wp_json_encode(
			array(
				't' => 'ddl',
				'd' => gmdate( 'Ymd' ),
				'r' => array_slice( $ddl, 0, 20 ),
			)
		);
		if ( is_string( $line ) ) {
			wpopt_db_journal_append( $file, $line );
		}
	}
	// Stop growing without bound if nothing ever consolidates (a site whose admin is never
	// opened and whose cron never fires). Cheap: one stat, and only when there is data.
	$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file is not an error here.
	if ( false !== $size && $size > 2097152 ) {
		return;
	}

	$row = array(
		'd'    => gmdate( 'Ymd' ),
		'q'    => $reads,
		'dup'  => $dups,
		'slow' => (int) $wpdb->wpopt_slow,
		'ms'   => (int) round( $wpdb->wpopt_time * 1000 ),
	);
	foreach ( array( 'sel', 'ins', 'upd', 'del' ) as $tk ) {
		$add = (int) ( $wpdb->wpopt_types[ $tk ] ?? 0 );
		if ( $add ) {
			$row[ $tk ] = $add;
		}
	}
	$sample = array();
	foreach ( array_slice( $wpdb->wpopt_duplicates( 5 ), 0, 5 ) as $d ) {
		$sample[] = array(
			's' => (string) substr( (string) $d['sql'], 0, 120 ),
			'c' => (int) $d['count'],
			'p' => (string) ( isset( $d['comp'] ) ? $d['comp'] : '' ),
		);
	}
	if ( $sample ) {
		$row['x'] = $sample;
	}
	$comp = array();
	foreach ( (array) $wpdb->wpopt_components as $name => $n ) {
		$comp[ (string) $name ] = (int) $n;
	}
	if ( $comp ) {
		$row['c'] = $comp;
	}

	$line = wp_json_encode( $row );
	if ( ! is_string( $line ) ) {
		return;
	}
	// One append has to land whole, or a concurrent request interleaves halfway through a line
	// and both samples are lost. A single small write in O_APPEND mode is atomic in practice;
	// past a few KB that stops being safe, so an oversized line sheds its detail and keeps the
	// counters, which are the part that must not be wrong.
	if ( strlen( $line ) > 3500 ) {
		unset( $row['x'], $row['c'] );
		$line = wp_json_encode( $row );
		if ( ! is_string( $line ) ) {
			return;
		}
	}

	// APPEND ONLY. No read, no decode, no rewrite, no lock.
	//
	// This used to open the aggregate JSON, take a BLOCKING flock(LOCK_EX), decode the whole
	// file, recompute the rolling top-20 and rewrite it — at the end of every request that made
	// a single read, anonymous front-end visits included. That is a serialised critical section
	// with file I/O on the visitor's path: under real traffic, concurrent requests queue on one
	// inode. The plugin's own rule is that this kind of work belongs on admin_init and never on
	// the visitor's path; this was breaking it at the hottest point there is.
	//
	// The fold now happens in wpoptimizing_db_journal_consolidate(), on admin_init, where a lock
	// costs nobody anything. Sampling was the other option and was rejected: it would have
	// thrown away exactly the per-component fidelity the feature exists to report.
	wpopt_db_journal_append( $file, $line );
}

/**
 * Append one line to the journal. No read, no decode, no lock.
 *
 * @param string $file Journal path.
 * @param string $line One line of compact JSON, without the newline.
 * @return void
 */
function wpopt_db_journal_append( $file, $line ) {
	$fp = @fopen( $file, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Append-only journal in the cache dir; failure is silent on purpose.
	if ( ! $fp ) {
		return;
	}
	@fwrite( $fp, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- Losing a sample is acceptable; blocking a visitor is not.
	@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

global $wpdb;
try {
	$wpdb = new WPOpt_DB( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
	// Room for the handler after an out-of-memory fatal, when nothing else is left: without it
	// the schema-change line — the valuable one — dies with the request. 64 KB is a few lines.
	$GLOBALS['wpopt_db_reserve'] = str_repeat( ' ', 65536 );
	register_shutdown_function( 'wpopt_db_persist_stats' );
} catch ( \Throwable $e ) {
	// Leave $wpdb unset so WordPress falls back to the stock wpdb.
	unset( $wpdb );
}

/**
 * Stand down when the plugin that installed this file is gone.
 *
 * A drop-in is self-contained on purpose: delete the plugin folder by FTP and the site keeps
 * working. The cost of that is an orphan — a wpdb subclass intercepting every query on the
 * site, with nobody to fix it and no interface to remove it.
 *
 * The check costs nothing on the normal path: WPOptimizing defines WPOPTIMIZING_VERSION when
 * it loads, so a single defined() answers "my owner is here" for every ordinary request. Only
 * when that constant is missing does this look at the filesystem at all — and an absent
 * constant is also what an update looks like for an instant, so it confirms with a glob before
 * doing anything, and a merely deactivated (but installed) plugin is left alone.
 *
 * Removing the file rather than skipping instantiation is deliberate: skipping would mean
 * paying for the check on every request forever, while removing it fixes the problem for good
 * from the next one, with WordPress falling back to its own wpdb.
 *
 * @return void
 */
function wpopt_db_stand_down_if_orphaned() {
	if ( defined( 'WPOPTIMIZING_VERSION' ) ) {
		return; // Owner loaded. Nothing to do — this is the path every real request takes.
	}
	if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
		return;
	}
	// Installed but deactivated, or mid-update: the folder is still there. Leave it be.
	$found = glob( WP_PLUGIN_DIR . '/*/wpoptimizing.php' );
	if ( ! empty( $found ) ) {
		return;
	}
	// Only ever remove a file carrying our own signature.
	$self = __FILE__;
	if ( ! is_file( $self ) ) {
		return;
	}
	$head = (string) @file_get_contents( $self, false, null, 0, 1024 );
	if ( false === strpos( $head, 'WPOPT_DB_DROPIN' ) ) {
		return;
	}
	@unlink( $self );
}
add_action( 'plugins_loaded', 'wpopt_db_stand_down_if_orphaned', PHP_INT_MAX );
