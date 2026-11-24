<?php
/**
 * Plugin Name: WPOptimizing Object Cache
 * Description: Persistent object cache drop-in installed by WPOptimizing. Uses the
 *              first available system backend (Redis → Memcached; APCu only when
 *              chosen) and falls back to a self-contained SQLite store, then to a
 *              non-persistent in-memory array. It NEVER fatals: any backend error
 *              degrades safely.
 *
 * WPOPT_OBJECT_CACHE v16  (signature — do not remove: WPOptimizing uses it to
 * recognise and safely manage/uninstall its own drop-in.)
 *
 * This file is a self-contained WordPress drop-in. It loads before plugins, so it
 * cannot use any WPOptimizing class. Do not edit by hand; reinstall from the
 * plugin's Cache tab instead.
 *
 * @package WPOptimizing
 */

defined( 'ABSPATH' ) || exit;

// PANIC SWITCH. `define( 'WPOPT_DISABLE', true );` in wp-config.php stands the WPOptimizing
// code that runs BEFORE the plugin down — this drop-in, the other drop-ins and the must-use
// runtime — without FTP, without touching the database and without deactivating anything.
//
// What it leaves behind, precisely, because the earlier wording over-promised: returning here
// skips every class declaration, but PHP hoists top-level function declarations, so the
// wp_cache_* API and WP_Object_Cache still exist and WordPress still uses them. With no backend
// class declared, select_backend() finds nothing and the cache is a correct PER-REQUEST one —
// no persistence, no sidecar, no plugin logic. It does NOT hand the cache back to core: once a
// drop-in declares WP_Object_Cache, core's own class is never loaded. Code that executes before WordPress has any recovery path of its own needs a way
// out that does not depend on the admin being reachable; this is it, and it is the first thing
// any agency asks for before installing software that loads this early.
if ( defined( 'WPOPT_DISABLE' ) && WPOPT_DISABLE ) {
	return;
}

// This file is a WordPress object-cache DROP-IN. By contract it must define the
// core cache API (wp_cache_* functions and the WP_Object_Cache class) in the
// global namespace, so the global-prefix and class-naming sniffs do not apply.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// Adapter methods implement an already-documented interface contract.
// phpcs:disable Squiz.Commenting.FunctionComment, Squiz.Commenting.VariableComment, Generic.Commenting.DocComment
// A drop-in legitimately uses low-level file I/O (no WP_Filesystem this early).
// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.NoSilencedErrors

/**
 * Read the optional sidecar config written by the plugin. Never fatals.
 *
 * @return array
 */
function wpopt_oc_config() {
	static $cfg = null;
	if ( null !== $cfg ) {
		return $cfg;
	}
	$cfg  = array();
	$path = WP_CONTENT_DIR . '/cache/wpopt-cache-config.php';
	if ( is_readable( $path ) ) {
		// The sidecar returns a plain array literal; it contains no WP calls.
		$data = include $path;
		if ( is_array( $data ) ) {
			$cfg = $data;
		}
	}
	return $cfg;
}

/**
 * The key prefix actually used: the stored salt bound to THIS install's location.
 *
 * The salt lives in an option, so cloning a site to staging copies it with the database, and
 * the sidecar with the files. Both copies then wrote the same keys into a shared Redis or
 * memcached: staging read production's cached options and posts, and its own edits landed in
 * production's cache. Mixing in the path, the database name and the table prefix — what any
 * clone changes — keeps each install in its own namespace and changes nothing for the original
 * beyond one cold cache after the update.
 *
 * @param string $stored Salt from the sidecar.
 * @return string
 */
function wpopt_oc_runtime_salt( $stored ) {
	$prefix = isset( $GLOBALS['table_prefix'] ) ? (string) $GLOBALS['table_prefix'] : '';
	return substr( md5( (string) $stored . '|' . ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $prefix ), 0, 12 );
}

/**
 * Is this a command-line run (WP-CLI, a system cron calling php)?
 *
 * @return bool
 */
function wpopt_oc_is_cli() {
	return ( defined( 'WP_CLI' ) && WP_CLI ) || 'cli' === PHP_SAPI;
}

/* -------------------------------------------------------------------------
 * Backend adapters. Each implements: get($k,&$found), get_multiple($keys),
 * set($k,$v,$ttl), add($k,$v,$ttl), delete($k), flush(). Values are passed
 * already serialized to byte-stores (redis/sqlite); apcu/memcached keep native
 * values.
 * ---------------------------------------------------------------------- */

/**
 * The fallback for get_multiple(): one get() per key, which is what every lookup cost before
 * the batched read existed. A backend uses it when its own multi-key read is not available or
 * failed as a whole, so a batching problem degrades to the old speed, never to wrong answers.
 *
 * @param WPOpt_Cache_Backend $backend Store.
 * @param array               $keys    Backend keys.
 * @return array Found entries only, keyed by backend key.
 */
function wpopt_oc_get_each( $backend, array $keys ) {
	$out = array();
	foreach ( $keys as $k ) {
		$found = false;
		$v     = $backend->get( $k, $found );
		if ( $found ) {
			$out[ $k ] = $v;
		}
	}
	return $out;
}

interface WPOpt_Cache_Backend {
	/** @return bool Whether the backend is usable. */
	public function is_ready();
	/** @return mixed Found value or false; sets $found by reference. */
	public function get( $key, &$found );
	/**
	 * Read several keys in ONE round trip to the store.
	 *
	 * WordPress asks for lists of keys at once (post meta, terms, options by the dozen); doing a
	 * get() per key made a page with a few hundred lookups pay a few hundred network round trips
	 * to Redis or memcached. The answer only lists what was really found: a key that is absent
	 * was not there OR could not be read, and the caller treats both as a miss, so a partial or
	 * total failure of the store can never come back as a hit. A stored value that is itself
	 * false is present in the array (array_key_exists()), not confused with a miss.
	 *
	 * @param string[] $keys Backend keys (distinct).
	 * @return array Found entries only, keyed by backend key.
	 */
	public function get_multiple( array $keys );
	/** @return bool */
	public function set( $key, $value, $ttl );
	/** @return bool True if added (key did not exist). */
	public function add( $key, $value, $ttl );
	/** @return bool */
	public function delete( $key );
	/** @return bool */
	public function flush();
	/**
	 * Delete only OUR keys.
	 *
	 * The salt exists because installs share a backend — and then flush() emptied the whole
	 * one. On a host where several sites share a Redis database, a memcached instance or a PHP
	 * pool (the default everywhere), one site's "flush all caches" destroyed every other
	 * site's. This is the scoped version; a backend that cannot express "delete by prefix"
	 * returns false and the caller falls back to something that does not damage the neighbours.
	 *
	 * @param string $prefix Key prefix owned by this install.
	 * @return bool True when the scoped delete was performed.
	 */
	public function flush_prefix( $prefix );
}

/**
 * Redis backend (phpredis extension).
 */
class WPOpt_Cache_Backend_Redis implements WPOpt_Cache_Backend {
	private $redis = null;

	/** @var bool A connection was attempted (the extension exists) and failed. */
	public $failed = false;

	public function __construct( array $cfg ) {
		if ( ! class_exists( 'Redis' ) ) {
			return;
		}
		$c = isset( $cfg['redis'] ) && is_array( $cfg['redis'] ) ? $cfg['redis'] : array();
		// The WP_REDIS_* constants in wp-config.php win: they are how hosts that run Redis tell
		// every cache plugin where it is, and ignoring them meant the drop-in tried 127.0.0.1,
		// failed, and quietly ran on SQLite on exactly the hosts that had Redis to offer.
		$host     = defined( 'WP_REDIS_HOST' ) ? (string) WP_REDIS_HOST : ( isset( $c['host'] ) ? (string) $c['host'] : '127.0.0.1' );
		$port     = defined( 'WP_REDIS_PORT' ) ? (int) WP_REDIS_PORT : ( isset( $c['port'] ) ? (int) $c['port'] : 6379 );
		$password = defined( 'WP_REDIS_PASSWORD' ) ? WP_REDIS_PASSWORD : ( isset( $c['password'] ) ? (string) $c['password'] : '' );
		$database = defined( 'WP_REDIS_DATABASE' ) ? (int) WP_REDIS_DATABASE : ( isset( $c['database'] ) ? (int) $c['database'] : 0 );
		$scheme   = defined( 'WP_REDIS_SCHEME' ) ? strtolower( (string) WP_REDIS_SCHEME ) : '';
		if ( 'unix' === $scheme && defined( 'WP_REDIS_PATH' ) ) {
			$host = (string) WP_REDIS_PATH;
			$port = 0;
		} elseif ( in_array( $scheme, array( 'tls', 'rediss' ), true ) && false === strpos( $host, '://' ) ) {
			$host = 'tls://' . $host;
		}
		$timeout = defined( 'WP_REDIS_TIMEOUT' ) ? (float) WP_REDIS_TIMEOUT : 0.5;
		// A read timeout as well as a connect timeout: a Redis that accepts the connection and
		// then hangs (out of memory, a blocking command from a neighbour) used to hold the
		// visitor's request for PHP's default of 60 seconds.
		$read_timeout = defined( 'WP_REDIS_READ_TIMEOUT' ) ? (float) WP_REDIS_READ_TIMEOUT : 1.0;
		try {
			$r = new Redis();
			if ( ! $r->connect( $host, $port, $timeout, null, 0, $read_timeout ) ) {
				$this->failed = true;
				return;
			}
			if ( is_array( $password ) || '' !== (string) $password ) {
				// An array is [ user, password ]: Redis 6 ACL users.
				$r->auth( $password );
			}
			if ( $database > 0 ) {
				$r->select( $database );
			}
			$r->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
			$this->redis = $r;
		} catch ( \Throwable $e ) {
			$this->redis  = null;
			$this->failed = true;
		}
	}

	public function is_ready() {
		return $this->redis instanceof Redis;
	}

	public function get( $key, &$found ) {
		$found = false;
		try {
			// Every value we store is a non-empty envelope string (see pack()), so Redis
			// answering false can only mean "not there". The EXISTS that used to follow every
			// miss was a second round trip that could never change the answer.
			$v = $this->redis->get( $key );
			if ( false === $v ) {
				return false;
			}
			$found = true;
			return $v;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function get_multiple( array $keys ) {
		$keys = array_values( $keys );
		if ( empty( $keys ) ) {
			return array();
		}
		try {
			// MGET answers positionally, false for each missing key. One command, one round
			// trip, however many keys; there is no pipeline or cluster client here to respect.
			$vals = $this->redis->mget( $keys );
		} catch ( \Throwable $e ) {
			return array(); // All misses: the store is in trouble, never pretend otherwise.
		}
		// A reply that does not line up with the request cannot be mapped back to keys
		// safely; treating it as all misses costs a database query, mapping it wrong would
		// serve one key's value under another's name.
		if ( ! is_array( $vals ) || count( $vals ) !== count( $keys ) ) {
			return array();
		}
		$vals = array_values( $vals );
		$out  = array();
		foreach ( $keys as $i => $k ) {
			// Same rule as get(): every stored value is a non-empty envelope string (pack()),
			// so false can only mean "not there".
			if ( false !== $vals[ $i ] && null !== $vals[ $i ] ) {
				$out[ $k ] = $vals[ $i ];
			}
		}
		return $out;
	}

	public function set( $key, $value, $ttl ) {
		try {
			return $ttl > 0 ? (bool) $this->redis->setex( $key, $ttl, $value ) : (bool) $this->redis->set( $key, $value );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function add( $key, $value, $ttl ) {
		try {
			$opt = $ttl > 0 ? array( 'nx', 'ex' => $ttl ) : array( 'nx' );
			return (bool) $this->redis->set( $key, $value, $opt );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function delete( $key ) {
		try {
			return $this->redis->del( $key ) > 0;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Redis INFO, for the server diagnosis. Read-only.
	 *
	 * @return array
	 */
	public function info() {
		if ( ! $this->redis ) {
			return array();
		}
		try {
			return (array) $this->redis->info();
		} catch ( \Throwable $e ) {
			return array();
		}
	}

	public function flush() {
		// Deliberately not the whole-database command: see flush_prefix(). Kept as the
		// contract's last resort, it delegates so no caller reaches the wide version by
		// accident.
		return $this->flush_prefix( '' );
	}

	public function flush_prefix( $prefix ) {
		if ( ! $this->redis ) {
			return false;
		}
		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return false; // No prefix means "everything", and everything is not ours.
		}
		try {
			// SCAN, never KEYS: KEYS walks the whole keyspace in one blocking call, which on a
			// shared Redis stalls every site on the box for as long as it takes.
			$it      = null;
			$deleted = 0;
			$this->redis->setOption( Redis::OPT_SCAN, Redis::SCAN_RETRY );
			// The prefix is a glob pattern to SCAN: a group called "a*" or "x[1]" must match itself,
			// not every key it happens to describe as a pattern.
			$pattern = addcslashes( $prefix, '*?[]\\' ) . '*';
			do {
				$keys = $this->redis->scan( $it, $pattern, 500 );
				if ( ! empty( $keys ) ) {
					$this->redis->del( $keys );
					$deleted += count( $keys );
				}
			} while ( $it > 0 );
			return true;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}

/**
 * Memcached backend (memcached extension).
 */
class WPOpt_Cache_Backend_Memcached implements WPOpt_Cache_Backend {
	private $mc = null;

	public function __construct( array $cfg ) {
		if ( ! class_exists( 'Memcached' ) ) {
			return;
		}
		$c = isset( $cfg['memcached'] ) && is_array( $cfg['memcached'] ) ? $cfg['memcached'] : array();
		try {
			$m    = new Memcached();
			$host = isset( $c['host'] ) ? (string) $c['host'] : '127.0.0.1';
			$port = isset( $c['port'] ) ? (int) $c['port'] : 11211;
			$m->setOption( Memcached::OPT_CONNECT_TIMEOUT, 500 );
			$m->addServer( $host, $port );
			// A cheap round-trip to confirm the server actually answers.
			$m->set( '_wpopt_ping', 1, 5 );
			if ( Memcached::RES_SUCCESS !== $m->getResultCode() ) {
				return;
			}
			$this->mc = $m;
		} catch ( \Throwable $e ) {
			$this->mc = null;
		}
	}

	public function is_ready() {
		return $this->mc instanceof Memcached;
	}

	public function get( $key, &$found ) {
		$found = false;
		try {
			$v = $this->mc->get( $key );
			if ( Memcached::RES_SUCCESS === $this->mc->getResultCode() ) {
				$found = true;
				return $v;
			}
			return false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function get_multiple( array $keys ) {
		$keys = array_values( $keys );
		if ( empty( $keys ) ) {
			return array();
		}
		try {
			// getMulti returns only the keys it found, with their native values: a stored
			// false is present as a key, a miss is absent. One round trip per server.
			$vals = $this->mc->getMulti( $keys );
		} catch ( \Throwable $e ) {
			return array();
		}
		if ( is_array( $vals ) ) {
			$out = array();
			foreach ( $keys as $k ) {
				if ( array_key_exists( $k, $vals ) ) {
					$out[ $k ] = $vals[ $k ];
				}
			}
			return $out;
		}
		// The whole call was refused. If it was one key memcached will not accept (too long,
		// a control character), asking key by key — as before — keeps the others readable.
		// Any other failure (server down) is all misses: retrying N times would only add N
		// timeouts to the visitor's request.
		$bad = defined( 'Memcached::RES_BAD_KEY_PROVIDED' ) ? constant( 'Memcached::RES_BAD_KEY_PROVIDED' ) : 33;
		if ( $bad === $this->mc->getResultCode() ) {
			return wpopt_oc_get_each( $this, $keys );
		}
		return array();
	}

	/**
	 * Memcached reads an expiration above 30 days as a Unix timestamp, so "keep for 31 days"
	 * became a date in January 1970 and the item expired on arrival. Past that, send the
	 * absolute time it means.
	 *
	 * @param int $ttl Seconds (0 = no expiry).
	 * @return int
	 */
	public static function expiration( $ttl ) {
		$ttl = (int) $ttl;
		if ( $ttl <= 0 ) {
			return 0;
		}
		return $ttl > 2592000 ? time() + $ttl : $ttl;
	}

	public function set( $key, $value, $ttl ) {
		try {
			return (bool) $this->mc->set( $key, $value, self::expiration( $ttl ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function add( $key, $value, $ttl ) {
		try {
			return (bool) $this->mc->add( $key, $value, self::expiration( $ttl ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function delete( $key ) {
		try {
			return (bool) $this->mc->delete( $key );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	public function flush() {
		// Memcached has no namespaces and no prefix delete, so flushing it would empty an
		// instance this site almost certainly shares. Refusing here is what makes the cache
		// object bump its generation instead (WP_Object_Cache::bump_generation()), which
		// orphans our keys and leaves everybody else's alone.
		return false;
	}

	public function flush_prefix( $prefix ) {
		return false; // Not expressible in the memcached protocol.
	}
}

/**
 * APCu backend (in-process, per-worker shared memory).
 */
class WPOpt_Cache_Backend_APCu implements WPOpt_Cache_Backend {
	private $ok = false;

	public function __construct() {
		$this->ok = function_exists( 'apcu_enabled' ) && apcu_enabled();
	}

	public function is_ready() {
		return $this->ok;
	}

	public function get( $key, &$found ) {
		$found = false;
		$v     = apcu_fetch( $key, $found );
		return $found ? $v : false;
	}

	public function get_multiple( array $keys ) {
		$keys = array_values( $keys );
		if ( empty( $keys ) ) {
			return array();
		}
		// apcu_fetch() takes an array and returns only what it found, native values included
		// (a stored false is present as a key). Shared memory, so this saves function calls and
		// locks rather than network time, but it is the same contract for free.
		$ok   = false;
		$vals = apcu_fetch( $keys, $ok );
		if ( ! is_array( $vals ) ) {
			return array();
		}
		$out = array();
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $vals ) ) {
				$out[ $k ] = $vals[ $k ];
			}
		}
		return $out;
	}

	public function set( $key, $value, $ttl ) {
		return (bool) apcu_store( $key, $value, $ttl > 0 ? $ttl : 0 );
	}

	public function add( $key, $value, $ttl ) {
		return (bool) apcu_add( $key, $value, $ttl > 0 ? $ttl : 0 );
	}

	public function delete( $key ) {
		return (bool) apcu_delete( $key );
	}

	public function flush() {
		return $this->flush_prefix( '' );
	}

	public function flush_prefix( $prefix ) {
		$prefix = (string) $prefix;
		if ( '' === $prefix || ! class_exists( 'APCUIterator' ) ) {
			// Clearing the store outright would empty the whole PHP pool, which is shared
			// with every other site served by it.
			return false;
		}
		try {
			return (bool) apcu_delete( new APCUIterator( '/^' . preg_quote( $prefix, '/' ) . '/' ) );
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}

/**
 * Self-contained SQLite backend (PDO sqlite or the SQLite3 extension). This is
 * the "fabricate our own" fallback when no system backend is available.
 */
class WPOpt_Cache_Backend_SQLite implements WPOpt_Cache_Backend {
	private $pdo  = null;
	private $db3  = null;
	private $ok   = false;

	public function __construct() {
		$dir = WP_CONTENT_DIR . '/cache';
		if ( ! is_dir( $dir ) ) {
			// 0775 — created early; WP constants for chmod may not exist yet.
			@mkdir( $dir, 0775, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return;
		}
		$file = $dir . '/wpopt-object-cache.sqlite';
		try {
			if ( class_exists( '\PDO' ) && in_array( 'sqlite', \PDO::getAvailableDrivers(), true ) ) { // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite object-cache backend; not the WP database.
				$this->pdo = new \PDO( 'sqlite:' . $file ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite object-cache backend; not the WP database.
				$this->pdo->setAttribute( \PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite object-cache backend; not the WP database.
				$this->pdo->exec( 'PRAGMA journal_mode=WAL' );
				$this->pdo->exec( 'PRAGMA busy_timeout=2000' );
				$this->pdo->exec( 'PRAGMA synchronous=NORMAL' );
				$this->pdo->exec( 'CREATE TABLE IF NOT EXISTS c (k TEXT PRIMARY KEY, v BLOB, e INTEGER)' );
				$this->ok = true;
			} elseif ( class_exists( '\SQLite3' ) ) {
				$this->db3 = new \SQLite3( $file );
				$this->db3->busyTimeout( 2000 );
				$this->db3->exec( 'PRAGMA journal_mode=WAL' );
				$this->db3->exec( 'PRAGMA synchronous=NORMAL' );
				$this->db3->exec( 'CREATE TABLE IF NOT EXISTS c (k TEXT PRIMARY KEY, v BLOB, e INTEGER)' );
				$this->ok = true;
			}
		} catch ( \Throwable $ex ) {
			$this->ok = false;
		}
	}

	public function is_ready() {
		return $this->ok;
	}

	public function get( $key, &$found ) {
		$found = false;
		$now   = time();
		try {
			if ( $this->pdo ) {
				$st = $this->pdo->prepare( 'SELECT v,e FROM c WHERE k=?' );
				$st->execute( array( $key ) );
				$row = $st->fetch( \PDO::FETCH_NUM ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite object-cache backend; not the WP database.
				if ( ! $row ) {
					return false;
				}
				if ( 0 !== (int) $row[1] && (int) $row[1] < $now ) {
					$this->delete( $key );
					return false;
				}
				$found = true;
				return $row[0];
			}
			$st = $this->db3->prepare( 'SELECT v,e FROM c WHERE k=:k' );
			$st->bindValue( ':k', $key, SQLITE3_TEXT );
			$res = $st->execute();
			$row = $res ? $res->fetchArray( SQLITE3_NUM ) : false;
			if ( ! $row ) {
				return false;
			}
			if ( 0 !== (int) $row[1] && (int) $row[1] < $now ) {
				$this->delete( $key );
				return false;
			}
			$found = true;
			return $row[0];
		} catch ( \Throwable $ex ) {
			return false;
		}
	}

	public function get_multiple( array $keys ) {
		$keys = array_values( array_unique( array_map( 'strval', $keys ) ) );
		if ( empty( $keys ) ) {
			return array();
		}
		$out     = array();
		$expired = array();
		$now     = time();
		try {
			// One indexed IN () query per 500 keys (k is the PRIMARY KEY). The chunk keeps every
			// statement under SQLite's bound-variable limit, 999 on builds older than 3.32.
			foreach ( array_chunk( $keys, 500 ) as $chunk ) {
				$sql  = 'SELECT k,v,e FROM c WHERE k IN (' . implode( ',', array_fill( 0, count( $chunk ), '?' ) ) . ')';
				$rows = array();
				if ( $this->pdo ) {
					$st = $this->pdo->prepare( $sql );
					if ( ! $st || ! $st->execute( $chunk ) ) {
						return wpopt_oc_get_each( $this, $keys );
					}
					$rows = $st->fetchAll( \PDO::FETCH_NUM ); // phpcs:ignore WordPress.DB.RestrictedClasses.mysql__PDO -- SQLite object-cache backend; not the WP database.
				} else {
					$st = $this->db3->prepare( $sql );
					if ( ! $st ) {
						return wpopt_oc_get_each( $this, $keys );
					}
					foreach ( $chunk as $i => $k ) {
						$st->bindValue( $i + 1, $k, SQLITE3_TEXT );
					}
					$res = $st->execute();
					if ( ! $res ) {
						return wpopt_oc_get_each( $this, $keys );
					}
					while ( $row = $res->fetchArray( SQLITE3_NUM ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
						$rows[] = $row;
					}
				}
				foreach ( (array) $rows as $row ) {
					// Expired rows are misses and go away, exactly as get() handles them.
					if ( 0 !== (int) $row[2] && (int) $row[2] < $now ) {
						$expired[] = (string) $row[0];
						continue;
					}
					$out[ (string) $row[0] ] = $row[1];
				}
			}
		} catch ( \Throwable $ex ) {
			// Whatever went wrong with the batched statement, the per-key path still works.
			return wpopt_oc_get_each( $this, $keys );
		}
		foreach ( $expired as $k ) {
			$this->delete( $k );
		}
		return $out;
	}

	public function set( $key, $value, $ttl ) {
		$exp = $ttl > 0 ? time() + (int) $ttl : 0;
		try {
			if ( $this->pdo ) {
				$st = $this->pdo->prepare( 'INSERT OR REPLACE INTO c (k,v,e) VALUES (?,?,?)' );
				return (bool) $st->execute( array( $key, $value, $exp ) );
			}
			$st = $this->db3->prepare( 'INSERT OR REPLACE INTO c (k,v,e) VALUES (:k,:v,:e)' );
			$st->bindValue( ':k', $key, SQLITE3_TEXT );
			$st->bindValue( ':v', $value, SQLITE3_BLOB );
			$st->bindValue( ':e', $exp, SQLITE3_INTEGER );
			return (bool) $st->execute();
		} catch ( \Throwable $ex ) {
			return false;
		}
	}

	public function add( $key, $value, $ttl ) {
		$found = false;
		$this->get( $key, $found );
		if ( $found ) {
			return false;
		}
		return $this->set( $key, $value, $ttl );
	}

	public function delete( $key ) {
		try {
			if ( $this->pdo ) {
				$st = $this->pdo->prepare( 'DELETE FROM c WHERE k=?' );
				return (bool) $st->execute( array( $key ) );
			}
			$st = $this->db3->prepare( 'DELETE FROM c WHERE k=:k' );
			$st->bindValue( ':k', $key, SQLITE3_TEXT );
			return (bool) $st->execute();
		} catch ( \Throwable $ex ) {
			return false;
		}
	}

	public function flush() {
		// This file is ours alone, so emptying it harms nobody.
		try {
			if ( $this->pdo ) {
				return false !== $this->pdo->exec( 'DELETE FROM c' );
			}
			return (bool) $this->db3->exec( 'DELETE FROM c' );
		} catch ( \Throwable $ex ) {
			return false;
		}
	}

	public function flush_prefix( $prefix ) {
		$prefix = (string) $prefix;
		if ( '' === $prefix ) {
			return $this->flush();
		}
		try {
			$like = str_replace( array( '\\', '%', '_' ), array( '\\\\', '\\%', '\\_' ), $prefix ) . '%';
			if ( $this->pdo ) {
				$st = $this->pdo->prepare( "DELETE FROM c WHERE k LIKE ? ESCAPE '\\'" );
				return (bool) $st->execute( array( $like ) );
			}
			$st = $this->db3->prepare( "DELETE FROM c WHERE k LIKE ? ESCAPE '\\'" );
			$st->bindValue( 1, $like, SQLITE3_TEXT );
			return false !== $st->execute();
		} catch ( \Throwable $ex ) {
			return false;
		}
	}
}

/**
 * The WordPress object cache. Wraps one persistent backend (or none) plus a
 * runtime (per-request) cache, mirroring WP core's WP_Object_Cache contract.
 */
class WP_Object_Cache {

	/** @var array Runtime (per-request) cache: group => key => value. */
	private $cache = array();

	/** @var WPOpt_Cache_Backend|null Persistent backend, or null (array-only). */
	private $backend = null;

	/** @var string Selected backend name, for diagnostics. */
	public $backend_name = 'array';

	/** @var array<string,bool> Groups shared across the whole multisite network. */
	private $global_groups = array();

	/** @var array<string,bool> Groups never written to the persistent backend. */
	private $non_persistent_groups = array();

	/** @var string Key prefix for the current blog (multisite). */
	private $blog_prefix = '';

	/** @var bool Whether this is a multisite install. */
	private $multisite = false;

	/** @var string Install-wide salt so installs sharing a backend never collide. */
	private $salt = '';

	/** @var string HMAC key for payloads stored on a NETWORK backend (see pack()). */
	private $hmac_key = '';

	/** @var string Memcached namespace generation, read once per request (see generation()). */
	private $generation = '';

	/** @var bool Whether the last unpack() rejected its payload (see unpack()). */
	private $unpack_failed = false;

	/** @var int */
	public $cache_hits = 0;

	/** @var int */
	public $cache_misses = 0;

	public function __construct() {
		$cfg             = wpopt_oc_config();
		$this->multisite = ( defined( 'MULTISITE' ) && MULTISITE );
		$this->blog_prefix = $this->multisite ? ( (int) ( $GLOBALS['blog_id'] ?? 1 ) ) . ':' : '';

		// Install-wide isolation salt.
		if ( ! empty( $cfg['salt'] ) ) {
			$this->salt = wpopt_oc_runtime_salt( (string) $cfg['salt'] );
		} elseif ( defined( 'WP_CACHE_KEY_SALT' ) && WP_CACHE_KEY_SALT ) {
			$this->salt = (string) WP_CACHE_KEY_SALT;
		} else {
			$this->salt = substr( md5( ABSPATH . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) ), 0, 8 );
		}

		// Authentication key for serialized payloads that leave this server (Redis). Built
		// from the install's own secrets, which are defined by wp-config.php long before a
		// drop-in loads; the salt is the last-resort fallback.
		$secret         = '';
		foreach ( array( 'AUTH_SALT', 'SECURE_AUTH_SALT', 'AUTH_KEY' ) as $const ) {
			if ( defined( $const ) && '' !== (string) constant( $const ) ) {
				$secret .= (string) constant( $const );
			}
		}
		$this->hmac_key = hash( 'sha256', 'wpopt-oc|' . ( '' !== $secret ? $secret : $this->salt ) );

		$this->backend = $this->select_backend( $cfg );

		// Session safety (prevents the "constant logout" race). WordPress stores
		// `session_tokens` inside the per-user `user_meta` blob. Under heavy concurrent
		// traffic (Action Scheduler, Heartbeat, scans) a request holding a STALE
		// persistent copy of that blob can rewrite it and drop a freshly-issued login
		// token, so the user's cookie no longer validates and they get logged out.
		// Keeping `user_meta` out of the PERSISTENT store makes every request read it
		// fresh from the DB, closing the cross-request staleness window (it is still
		// cached in-memory for the duration of each request). Advanced setups with low
		// write contention can opt back in via the sidecar: 'persist_user_meta' => true.
		if ( empty( $cfg['persist_user_meta'] ) ) {
			$this->non_persistent_groups['user_meta'] = true;
		}
	}

	/**
	 * Pick a backend honouring config; otherwise auto-detect by priority.
	 *
	 * @param array $cfg Sidecar config.
	 * @return WPOpt_Cache_Backend|null
	 */
	/**
	 * Is a PERSISTENT backend actually up? Not "did we load", but "will a value written now
	 * still be there on the next request". wp_cache_init() asks before letting WordPress
	 * believe it has an external object cache — because if it believes that wrongly, every
	 * transient on the site is written to RAM and lost.
	 *
	 * @return bool
	 */
	public function has_persistent_backend() {
		return (bool) $this->backend;
	}

	private function select_backend( array $cfg ) {
		$want = isset( $cfg['backend'] ) ? (string) $cfg['backend'] : 'auto';

		// A configured backend is a preference, not a suicide pact. If redis is asked for and
		// redis is down, falling through to the remaining backends beats running with none:
		// SQLite needs nothing but a writable directory, so in practice there is always a
		// persistent option. Explicit choice first, then the rest as a safety net.
		//
		// APCu is NEVER picked on its own, and is not a fallback either. Its memory belongs to one
		// PHP-FPM pool, and WP-CLI or a system cron run in another process (usually with APCu off),
		// so nothing they change reaches what the web workers hold: an option saved from the
		// command line kept its old value on the site, and with the SQLite fallback the CLI wrote
		// its own copy of `cron` that the web never saw. Only a site that asks for APCu gets it,
		// and then the command line runs WITHOUT a persistent cache rather than on a second store.
		$order = array( 'redis', 'memcached', 'sqlite' );
		if ( 'apcu' === $want ) {
			if ( wpopt_oc_is_cli() ) {
				$this->backend_name = 'array';
				return null;
			}
			$order = array_merge( array( 'apcu' ), $order );
		} elseif ( 'auto' !== $want && '' !== $want ) {
			$order = array_merge( array( $want ), array_diff( $order, array( $want ) ) );
		}

		// Redis going down and coming back is where an object cache serves wrong data: while
		// it was down we wrote to the fallback, so what Redis still holds was never
		// invalidated (a post edited during the outage reappears in its old version). A small
		// marker file records the outage; whichever store we switch TO is emptied of our keys
		// first. It also stops every request paying the connect timeout while Redis is down:
		// for 30 seconds after a failure we go straight to the fallback.
		$marker   = WP_CONTENT_DIR . '/cache/wpopt-oc-down';
		$was_down = is_file( $marker );
		$went_now = false;
		foreach ( $order as $name ) {
			if ( 'redis' === $name && $was_down && ( time() - (int) @filemtime( $marker ) ) < 30 ) {
				continue;
			}
			$be = $this->make_backend( $name, $cfg );
			if ( $be && $be->is_ready() ) {
				$this->backend_name = $name;
				if ( 'redis' === $name && $was_down ) {
					$be->flush_prefix( $this->salt . ':' );
					@unlink( $marker );
				} elseif ( $went_now ) {
					if ( 'memcached' === $name ) {
						$this->bump_generation( $be ); // Memcached cannot delete by prefix.
					} else {
						$be->flush_prefix( $this->salt . ':' );
					}
				}
				return $be;
			}
			if ( 'redis' === $name && $be && ! empty( $be->failed ) ) {
				$went_now = ! $was_down;
				// Runs from the object-cache drop-in, before WordPress has loaded WP_Filesystem, and the
				// marker lives in wp-content/cache/ (not in the plugin folder), so it survives updates.
				@touch( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch, PluginCheck.CodeAnalysis.WriteFile.PluginDirectoryWrite -- Pre-WordPress drop-in; one empty marker file under wp-content/cache/, failure is harmless.
			}
		}

		// Nothing persistent came up. We remain a valid PER-REQUEST cache, but WordPress must
		// be told (see wp_cache_init), or it will keep storing transients here and losing them.
		$this->backend_name = 'array';
		return null;
	}

	/**
	 * @param string $name redis|memcached|apcu|sqlite
	 * @param array  $cfg  Sidecar config.
	 * @return WPOpt_Cache_Backend|null
	 */
	private function make_backend( $name, array $cfg ) {
		// class_exists() before every `new`, because a missing backend class must degrade, never
		// fatal. This is not hypothetical: the panic switch above returns before the class
		// declarations, but PHP hoists top-level FUNCTION declarations, so WordPress still calls
		// the hoisted wp_cache_init() and constructs WP_Object_Cache (hoisted too, it implements
		// nothing) — while these backends implement an interface and are therefore NOT hoisted.
		// Without this guard the emergency off-switch took the site down instead of saving it.
		$class = array(
			'redis'     => 'WPOpt_Cache_Backend_Redis',
			'memcached' => 'WPOpt_Cache_Backend_Memcached',
			'apcu'      => 'WPOpt_Cache_Backend_APCu',
			'sqlite'    => 'WPOpt_Cache_Backend_SQLite',
		);
		if ( ! isset( $class[ $name ] ) || ! class_exists( $class[ $name ], false ) ) {
			return null;
		}
		switch ( $name ) {
			case 'redis':
				return new WPOpt_Cache_Backend_Redis( $cfg );
			case 'memcached':
				return new WPOpt_Cache_Backend_Memcached( $cfg );
			case 'apcu':
				return new WPOpt_Cache_Backend_APCu();
			case 'sqlite':
				return new WPOpt_Cache_Backend_SQLite();
		}
		return null;
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Build the namespaced backend key for a (key, group) pair.
	 *
	 * @param int|string $key   Item key.
	 * @param string     $group Group.
	 * @return string
	 */
	private function key( $key, $group ) {
		$group  = $group ? $group : 'default';
		$prefix = isset( $this->global_groups[ $group ] ) ? '' : $this->blog_prefix;
		return $this->salt . ':' . $this->generation() . $prefix . $group . ':' . $key;
	}

	/**
	 * Memcached only: the namespace generation, as a key segment ("g<token>:"), '' elsewhere.
	 *
	 * Memcached cannot delete by prefix, and emptying the instance would empty everybody else's
	 * cache too, so flush() did nothing at all while the comment said a generation was bumped.
	 * Now every key carries the current generation; bumping it (bump_generation()) leaves all
	 * the old keys unreachable at once, and memcached evicts them as it needs the room. One
	 * extra get per request, the first time a key is built.
	 *
	 * @return string
	 */
	private function generation() {
		if ( 'memcached' !== $this->backend_name || ! $this->backend ) {
			return '';
		}
		if ( '' === $this->generation ) {
			$gkey  = $this->salt . ':_generation';
			$found = false;
			$value = $this->backend->get( $gkey, $found );
			if ( ! $found || ! is_string( $value ) || '' === $value ) {
				$value = self::new_generation();
				// add(), not set(): two requests starting together must agree on one generation.
				if ( ! $this->backend->add( $gkey, $value, 0 ) ) {
					$again = $this->backend->get( $gkey, $found );
					if ( $found && is_string( $again ) && '' !== $again ) {
						$value = $again;
					}
				}
			}
			$this->generation = $value;
		}
		return 'g' . $this->generation . ':';
	}

	/**
	 * Start a new memcached generation: every key written before it is unreachable from now on.
	 * A fresh random token rather than a counter, so two concurrent flushes cannot land on the
	 * same value and resurrect anything.
	 *
	 * @param WPOpt_Cache_Backend|null $backend The store (defaults to the active one).
	 * @return bool
	 */
	private function bump_generation( $backend = null ) {
		$backend = $backend ? $backend : $this->backend;
		if ( ! $backend ) {
			return false;
		}
		$this->generation = self::new_generation();
		return (bool) $backend->set( $this->salt . ':_generation', $this->generation, 0 );
	}

	/**
	 * A short unique token for a generation.
	 *
	 * @return string
	 */
	private static function new_generation() {
		return substr( md5( uniqid( '', true ) . mt_rand() ), 0, 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- uniqueness, not security.
	}

	private function is_non_persistent( $group ) {
		return isset( $this->non_persistent_groups[ $group ? $group : 'default' ] );
	}

	/**
	 * Effective runtime (L1) cache group. Non-global groups are namespaced by the
	 * current blog so that, after switch_to_blog(), an L1 hit cannot return another
	 * blog's value: the persistent key() already includes the blog prefix, so the
	 * runtime array must match it. Mirrors how core keys its own runtime cache.
	 *
	 * @param string $group Group.
	 * @return string
	 */
	private function rt_group( $group ) {
		$group = $group ? $group : 'default';
		return isset( $this->global_groups[ $group ] ) ? $group : $this->blog_prefix . $group;
	}

	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->global_groups[ $g ] = true;
		}
	}

	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->non_persistent_groups[ $g ] = true;
		}
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = $this->multisite ? ( (int) $blog_id ) . ':' : '';
	}

	/**
	 * Get a value. Runtime cache is the L1; the backend is L2.
	 *
	 * @param int|string $key   Key.
	 * @param string     $group Group.
	 * @param bool       $force Bypass the runtime cache.
	 * @param bool       $found Set by reference.
	 * @return mixed
	 */
	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$group = $group ? $group : 'default';
		$rg    = $this->rt_group( $group );

		if ( ! $force && isset( $this->cache[ $rg ] ) && array_key_exists( $key, $this->cache[ $rg ] ) ) {
			$found = true;
			++$this->cache_hits;
			return $this->maybe_clone( $this->cache[ $rg ][ $key ] );
		}

		if ( $this->is_non_persistent( $group ) || ! $this->backend ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}

		$bfound = false;
		$raw    = $this->backend->get( $this->key( $key, $group ), $bfound );
		if ( ! $bfound ) {
			$found = false;
			++$this->cache_misses;
			return false;
		}
		$value = $this->unpack( $raw );
		if ( $this->unpack_failed ) {
			// A payload that failed its authentication or could not be decoded is a miss and
			// is not remembered: caching it as false made a tampered or pre-upgrade entry look
			// like a stored false for the rest of the request.
			$found = false;
			++$this->cache_misses;
			return false;
		}
		$this->cache[ $rg ][ $key ] = $value;
		$found                      = true;
		++$this->cache_hits;
		return $this->maybe_clone( $value );
	}

	/**
	 * @param array  $keys  Keys.
	 * @param string $group Group.
	 * @param bool   $force Bypass runtime cache.
	 * @return array key => value|false
	 */
	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$group   = $group ? $group : 'default';
		$rg      = $this->rt_group( $group );
		$keys    = (array) $keys;
		$persist = $this->backend && ! $this->is_non_persistent( $group );

		// Same answers, hits and misses as calling get() once per key — which is what this
		// did — but the keys the runtime cache cannot answer go to the store in ONE request
		// (MGET, getMulti, one IN query) instead of one round trip each.
		//
		// Pass 1, in the caller's order: the runtime cache answers what it can (unless
		// $force), everything else starts as false and, if the group is persistent, is queued
		// for the store under the same key() get() would use (salt, generation, blog prefix
		// unless the group is global). A repeated key keeps its first position, as the array
		// assignment in the old loop did.
		$out   = array();
		$found = array();
		$need  = array();
		foreach ( $keys as $k ) {
			if ( array_key_exists( $k, $out ) ) {
				continue;
			}
			if ( ! $force && isset( $this->cache[ $rg ] ) && array_key_exists( $k, $this->cache[ $rg ] ) ) {
				$out[ $k ]   = $this->maybe_clone( $this->cache[ $rg ][ $k ] );
				$found[ $k ] = true;
				continue;
			}
			$out[ $k ]   = false;
			$found[ $k ] = false;
			if ( $persist ) {
				$need[ $k ] = $this->key( $k, $group );
			}
		}

		// Pass 2: one trip to the store. Only what comes back AND authenticates/decodes is a
		// hit; anything the store did not return — missing, or lost to a partial failure —
		// stays a miss, and is not written into the runtime cache.
		if ( ! empty( $need ) ) {
			$raw = $this->backend->get_multiple( array_values( $need ) );
			foreach ( $need as $k => $bkey ) {
				if ( ! is_array( $raw ) || ! array_key_exists( $bkey, $raw ) ) {
					continue;
				}
				$value = $this->unpack( $raw[ $bkey ] );
				if ( $this->unpack_failed ) {
					continue;
				}
				$this->cache[ $rg ][ $k ] = $value;
				$out[ $k ]                = $this->maybe_clone( $value );
				$found[ $k ]              = true;
			}
		}

		// Statistics per requested key, repeats included, exactly as the per-key loop counted:
		// a repeat of a found key was a runtime hit, a repeat of a miss was another miss.
		foreach ( $keys as $k ) {
			if ( ! empty( $found[ $k ] ) ) {
				++$this->cache_hits;
			} else {
				++$this->cache_misses;
			}
		}
		return $out;
	}

	public function set( $key, $value, $group = 'default', $ttl = 0 ) {
		$group = $group ? $group : 'default';
		$this->cache[ $this->rt_group( $group ) ][ $key ] = $this->maybe_clone( $value );

		if ( $this->is_non_persistent( $group ) || ! $this->backend ) {
			return true;
		}
		return $this->backend->set( $this->key( $key, $group ), $this->pack( $value ), max( 0, (int) $ttl ) );
	}

	public function add( $key, $value, $group = 'default', $ttl = 0 ) {
		// Importers and bulk operations suspend additions to keep memory flat; core's own
		// cache honours it and ours ignored it.
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		$group = $group ? $group : 'default';
		$rg    = $this->rt_group( $group );

		// Already present in runtime → cannot add.
		if ( isset( $this->cache[ $rg ] ) && array_key_exists( $key, $this->cache[ $rg ] ) ) {
			return false;
		}
		if ( $this->is_non_persistent( $group ) || ! $this->backend ) {
			$this->cache[ $rg ][ $key ] = $this->maybe_clone( $value );
			return true;
		}
		$ok = $this->backend->add( $this->key( $key, $group ), $this->pack( $value ), max( 0, (int) $ttl ) );
		if ( $ok ) {
			$this->cache[ $rg ][ $key ] = $this->maybe_clone( $value );
		}
		return $ok;
	}

	public function replace( $key, $value, $group = 'default', $ttl = 0 ) {
		$found = false;
		$this->get( $key, $group, true, $found );
		if ( ! $found ) {
			return false;
		}
		return $this->set( $key, $value, $group, $ttl );
	}

	public function delete( $key, $group = 'default' ) {
		$group = $group ? $group : 'default';
		unset( $this->cache[ $this->rt_group( $group ) ][ $key ] );
		if ( $this->is_non_persistent( $group ) || ! $this->backend ) {
			return true;
		}
		return $this->backend->delete( $this->key( $key, $group ) );
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$found = false;
		$val   = $this->get( $key, $group, true, $found );
		if ( ! $found ) {
			return false;
		}
		if ( ! is_numeric( $val ) ) {
			$val = 0;
		}
		$val = $val + (int) $offset;
		if ( $val < 0 ) {
			$val = 0;
		}
		$this->set( $key, $val, $group );
		return $val;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, -abs( (int) $offset ), $group );
	}

	public function flush() {
		$this->cache = array();
		if ( ! $this->backend ) {
			return true;
		}

		/*
		 * Scoped to our own keys. The salt in key() exists precisely because installs share a
		 * backend, and until now flush() emptied the whole one: on any host where sites share a
		 * Redis database, a memcached instance or a PHP pool — the default in all three — one
		 * site's "flush all caches" wiped every neighbour's cache, including applications that
		 * are not WordPress at all.
		 *
		 * Memcached cannot express "delete by prefix" and emptying the instance is damage to
		 * somebody else that cannot be undone, so there the generation in every key moves on
		 * instead (bump_generation()). If even that write fails, wp_cache_flush() returns false
		 * and the caller says so instead of reporting a success that did not happen.
		 */
		if ( 'memcached' === $this->backend_name ) {
			return $this->bump_generation();
		}
		return (bool) $this->backend->flush_prefix( $this->salt . ':' );
	}

	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	/**
	 * Empty one group, for real: every key of a group shares the prefix key() builds
	 * ("salt:blog:group:"), so the same prefix delete flush() uses removes exactly that group
	 * — of this site, or of the whole network for a global group — and costs nothing on reads.
	 * Memcached cannot delete by prefix: there the whole generation moves on, which empties more
	 * than the group but never leaves a stale value in it.
	 *
	 * @param string $group Group.
	 * @return bool
	 */
	public function flush_group( $group ) {
		unset( $this->cache[ $this->rt_group( $group ) ] );
		if ( ! $this->backend || $this->is_non_persistent( $group ) ) {
			return true;
		}
		if ( 'memcached' === $this->backend_name ) {
			return $this->bump_generation();
		}
		$group  = $group ? $group : 'default';
		$prefix = isset( $this->global_groups[ $group ] ) ? '' : $this->blog_prefix;
		return (bool) $this->backend->flush_prefix( $this->salt . ':' . $prefix . $group . ':' );
	}

	public function supports( $feature ) {
		switch ( $feature ) {
			case 'get_multiple':
			case 'set_multiple':
			case 'add_multiple':
			case 'delete_multiple':
			case 'flush_runtime':
			case 'flush_group': // Memcached included, through its generation (flush_group()).
				return true;
		}
		return false;
	}

	/**
	 * What the active store says about itself (Redis INFO), for the admin's server diagnosis.
	 *
	 * @return array
	 */
	public function backend_info() {
		return ( $this->backend && method_exists( $this->backend, 'info' ) ) ? (array) $this->backend->info() : array();
	}

	public function stats() {
		return array(
			'hits'    => $this->cache_hits,
			'misses'  => $this->cache_misses,
			'backend' => $this->backend_name,
		);
	}

	/* --- helpers --- */

	/**
	 * Is the active backend reachable from outside this server?
	 *
	 * Redis usually lives on a shared host and is frequently exposed without auth, so its
	 * bytes are NOT inside the site's trust boundary. The local stores (SQLite file, APCu)
	 * are — anyone who can write to them already has code execution here.
	 *
	 * @return bool
	 */
	private function backend_is_networked() {
		return 'redis' === $this->backend_name;
	}

	/**
	 * Use igbinary for the byte stores when the extension is there: its payloads are about half
	 * the size of serialize()'s and faster both ways, which is memory in Redis and time on every
	 * hit. WPOPT_OC_IGBINARY = false turns it off.
	 *
	 * @return bool
	 */
	private function use_igbinary() {
		return function_exists( 'igbinary_serialize' ) && ! ( defined( 'WPOPT_OC_IGBINARY' ) && ! WPOPT_OC_IGBINARY );
	}

	private function pack( $value ) {
		// APCu/Memcached store native PHP values; byte-stores need serialization.
		if ( in_array( $this->backend_name, array( 'apcu', 'memcached' ), true ) ) {
			return $value;
		}
		$igb = $this->use_igbinary();
		$ser = $igb ? igbinary_serialize( $value ) : serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		if ( ! $this->backend_is_networked() ) {
			// Local store: a short marker says which serializer wrote the bytes.
			return $igb ? 'igb1|' . $ser : $ser;
		}
		// Networked store: authenticate the payload so a third party with write access to
		// the shared Redis cannot hand us a crafted object graph to unserialize. wpo2 = igbinary.
		return ( $igb ? 'wpo2|' : 'wpo1|' ) . hash_hmac( 'sha256', $ser, $this->hmac_key ) . '|' . $ser;
	}

	private function unpack( $raw ) {
		// Whether THIS call rejected the payload. A return value cannot say it: false is also a
		// perfectly good stored value. get() and get_multiple() read the flag right after.
		$this->unpack_failed = false;
		if ( in_array( $this->backend_name, array( 'apcu', 'memcached' ), true ) ) {
			return $raw;
		}
		$raw = (string) $raw;

		// Authenticated envelope (written for networked backends). Verify BEFORE
		// unserialize(): a failed check is treated as a cache miss, never as data.
		$igb = false;
		if ( 0 === strpos( $raw, 'wpo1|' ) || 0 === strpos( $raw, 'wpo2|' ) ) {
			$igb   = 0 === strpos( $raw, 'wpo2|' );
			$parts = explode( '|', $raw, 3 );
			if ( 3 !== count( $parts ) ) {
				$this->unpack_failed = true;
				return false;
			}
			$expected = hash_hmac( 'sha256', $parts[2], $this->hmac_key );
			if ( ! hash_equals( $expected, (string) $parts[1] ) ) {
				$this->unpack_failed = true;
				return false;
			}
			$raw = $parts[2];
		} elseif ( 0 === strpos( $raw, 'igb1|' ) && ! $this->backend_is_networked() ) {
			$igb = true;
			$raw = substr( $raw, 5 );
		} elseif ( $this->backend_is_networked() ) {
			// A networked store must never hand us an unauthenticated payload (it could be
			// a pre-upgrade entry, or someone else's). Miss, and let it be rewritten.
			$this->unpack_failed = true;
			return false;
		}

		// Objects ARE allowed on purpose: WordPress legitimately caches them (WP_Post,
		// WP_Term, WP_User, ...) and a persistent object cache that stripped classes would
		// hand back broken __PHP_Incomplete_Class objects. What makes that safe is the step
		// above plus, for local stores, the fact that writing to them already requires code
		// execution on this server.
		if ( $igb ) {
			// Written with igbinary on a server that had it: without the extension it is a miss
			// (and gets rewritten), never bytes handed to the wrong decoder.
			$val = function_exists( 'igbinary_unserialize' ) ? @igbinary_unserialize( $raw ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			// A decoder failure looks like false (or null); only the encoding of that very value
			// proves it was stored.
			if ( ( false === $val || null === $val ) && ( ! function_exists( 'igbinary_serialize' ) || igbinary_serialize( $val ) !== $raw ) ) {
				$this->unpack_failed = true;
				return false;
			}
			return $val;
		}
		$val = @unserialize( $raw, array( 'allowed_classes' => true ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		if ( false === $val && 'b:0;' !== $raw ) {
			$this->unpack_failed = true; // Corrupt or truncated bytes, not a stored false.
		}
		return $val;
	}

	private function maybe_clone( $value ) {
		return is_object( $value ) ? clone $value : $value;
	}
}

/* -------------------------------------------------------------------------
 * Procedural API expected by WordPress.
 * ---------------------------------------------------------------------- */

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();

	// THE DROP-IN MUST NOT LIE ABOUT BEING PERSISTENT.
	//
	// The mere existence of object-cache.php makes WordPress declare an external object
	// cache (wp_start_object_cache() calls wp_using_ext_object_cache( true ) right after
	// requiring us). From that moment set_transient() STOPS WRITING TO wp_options and stores
	// through wp_cache_set() instead — because WP assumes the cache outlives the request.
	//
	// If no persistent backend came up (no redis/memcached/apcu/sqlite, or the configured one
	// is down), we are an in-request array. So every transient on the site — ours, WooCommerce's,
	// core's — would be written into an array and thrown away microseconds later. Silently.
	// That is what made the Media report vanish "just by opening its section": the report IS a
	// transient, it was never anywhere but in RAM, and the next request could not find it.
	//
	// wp_cache_init() runs AFTER core has set the flag, which is exactly where we can correct
	// it: tell WP the truth, and transients go back to the options table where they survive.
	// We stay a perfectly good per-request cache; we just stop pretending to be more.
	//
	// BUT NOT YET ON A MULTISITE. ms-settings.php calls wp_start_object_cache() a second time, and
	// that call does `if ( ! wp_using_ext_object_cache() ) require_once wp-includes/cache.php` —
	// which declares wp_cache_init() again and is a FATAL ("Cannot redeclare"). Setting the flag
	// false here took every multisite down whenever no persistent backend came up, and with it the
	// WPOPT_DISABLE panic switch, whose whole point is to save the site. Measured on 2026-10-11 on
	// a multisite install. So on a network the truth waits for ms_loaded, the action that closes
	// ms-settings.php, after that second call; nothing sets a transient before it.
	if ( ! $GLOBALS['wp_object_cache']->has_persistent_backend() && function_exists( 'wp_using_ext_object_cache' ) ) {
		if ( defined( 'MULTISITE' ) && MULTISITE && function_exists( 'add_action' ) && function_exists( 'did_action' ) && ! did_action( 'ms_loaded' ) ) {
			add_action( 'ms_loaded', 'wpopt_oc_not_persistent' );
		} else {
			wp_using_ext_object_cache( false );
		}
	}

	// Persist per-day hit/miss aggregates for the Cache tab analytics. Stored in
	// a small JSON file (NOT the cache backend, so flushing never wipes history).
	// The reserve is what the handler runs in after an out-of-memory fatal, when nothing else is
	// left: one JSON line needs far less than 64 KB. See wpopt_oc_persist_stats().
	$GLOBALS['wpopt_oc_reserve'] = str_repeat( ' ', 65536 );
	register_shutdown_function( 'wpopt_oc_persist_stats' );
}

/**
 * Tell WordPress there is no persistent object cache. See wp_cache_init() for why this waits for
 * ms_loaded on a network.
 *
 * @return void
 */
function wpopt_oc_not_persistent() {
	wp_using_ext_object_cache( false );
}

/**
 * Where this drop-in appends its per-request counters.
 *
 * Mirrors wpopt_db_journal_file() in the db.php drop-in deliberately: ONE journal and ONE
 * consolidator for both. The drop-ins cannot call each other — each is standalone and either
 * may be absent, or of a different version — so the path is spelled out in both places.
 *
 * @return string
 */
function wpopt_oc_journal_file() {
	$base = WP_CONTENT_DIR;
	return $base . '/cache/wpopt-db-journal.log';
}

/**
 * Append one line to the journal. No read, no decode, no lock.
 *
 * @param string $file Journal path.
 * @param string $line One line of compact JSON, without the newline.
 * @return void
 */
function wpopt_oc_journal_append( $file, $line ) {
	$fp = @fopen( $file, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.PHP.NoSilencedErrors.Discouraged -- Append-only journal in the cache dir; failure is silent on purpose.
	if ( ! $fp ) {
		return;
	}
	@fwrite( $fp, $line . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.NoSilencedErrors.Discouraged -- Losing a sample is acceptable; blocking a visitor is not.
	@fclose( $fp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

/**
 * After an out-of-memory fatal, give the shutdown handlers 32 MB more to run in. Once per
 * request, however many handlers ask: the gateway and the db.php drop-in have their own copy and all
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
function wpopt_oc_oom_raise() {
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
 * Shutdown callback: record this request's hit/miss counts.
 *
 * APPEND ONLY. No read, no decode, no rewrite, no lock.
 *
 * This used to open cache/wpopt-cache-stats.json, take a BLOCKING flock( LOCK_EX ), decode the
 * whole file, merge today's bucket and rewrite it — at the end of EVERY request, anonymous
 * front-end visits included, because an object cache answers on all of them. That is a
 * serialised critical section with file I/O on the visitor's path: under real traffic the PHP
 * workers queue on one inode at the end of each request, and from outside it looks like "the
 * database is slow" with MySQL idle.
 *
 * The db.php drop-in had the same bug and was fixed the same way in its v8; this side was
 * missed because the merge function lived here and its docblock claimed both drop-ins used it,
 * which stopped being true when db.php moved to the journal.
 *
 * The fold now happens in wpoptimizing_db_journal_consolidate(), on admin_init, where a lock
 * costs nobody anything. Sampling was the other option and was rejected for the same reason as
 * there: it would under-report exactly when the site is busiest, which is when the numbers are
 * read.
 *
 * @return void
 */
function wpopt_oc_persist_stats() {
	unset( $GLOBALS['wpopt_oc_reserve'] ); // First: after a memory fatal this is all the room there is.
	wpopt_oc_oom_raise();
	$oc = isset( $GLOBALS['wp_object_cache'] ) ? $GLOBALS['wp_object_cache'] : null;
	if ( ! is_object( $oc ) || ! method_exists( $oc, 'stats' ) ) {
		return;
	}
	$s = $oc->stats();
	$h = (int) ( $s['hits'] ?? 0 );
	$m = (int) ( $s['misses'] ?? 0 );
	if ( $h < 1 && $m < 1 ) {
		return;
	}

	$file = wpopt_oc_journal_file();
	$dir  = dirname( $file );
	if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
		return;
	}

	// Stop growing without bound if nothing ever consolidates (a site whose admin is never
	// opened and whose cron never fires). Cheap: one stat, and only when there is data.
	$size = @filesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Missing file is not an error here.
	if ( false !== $size && $size > 2097152 ) {
		return;
	}

	$line = wp_json_encode(
		array(
			't'   => 'oc',
			'd'   => gmdate( 'Ymd' ),
			'h'   => $h,
			'm'   => $m,
			'req' => 1,
		)
	);
	if ( ! is_string( $line ) ) {
		return;
	}
	wpopt_oc_journal_append( $file, $line );
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	$out = array();
	foreach ( $data as $k => $v ) {
		$out[ $k ] = wp_cache_add( $k, $v, $group, $expire );
	}
	return $out;
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	$out = array();
	foreach ( $data as $k => $v ) {
		$out[ $k ] = wp_cache_set( $k, $v, $group, $expire );
	}
	return $out;
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = '' ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	$out = array();
	foreach ( $keys as $k ) {
		$out[ $k ] = wp_cache_delete( $k, $group );
	}
	return $out;
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}

function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush();
}

function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	return $GLOBALS['wp_object_cache']->flush_group( $group );
}

function wp_cache_supports( $feature ) {
	return $GLOBALS['wp_object_cache']->supports( $feature );
}

function wp_cache_close() {
	return true;
}

function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
}

/**
 * Stand down when the plugin that installed this file is gone.
 *
 * A drop-in is self-contained on purpose: delete the plugin folder by FTP and the site keeps
 * working. The cost of that is an orphan — a cache implementation serving every request, with
 * nobody to fix it and no interface to remove it.
 *
 * The check costs nothing on the normal path: WPOptimizing defines WPOPTIMIZING_VERSION when
 * it loads, so a single defined() answers "my owner is here" for every ordinary request. Only
 * when that constant is missing does this look at the filesystem at all — and an absent
 * constant is also what an update looks like for an instant, so it confirms with a glob before
 * doing anything, and a merely deactivated (but installed) plugin is left alone.
 *
 * Removing the file rather than skipping instantiation is deliberate: skipping would mean
 * paying for the check on every request forever, while removing it fixes the problem for good
 * from the next one, with WordPress falling back to its own object cache.
 *
 * @return void
 */
function wpopt_oc_stand_down_if_orphaned() {
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
	if ( false === strpos( $head, 'WPOPT_OBJECT_CACHE' ) ) {
		return;
	}
	@unlink( $self );
}
add_action( 'plugins_loaded', 'wpopt_oc_stand_down_if_orphaned', PHP_INT_MAX );
