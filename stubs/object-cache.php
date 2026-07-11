<?php
// phpcs:ignoreFile
/**
 * Mincemeat Object Cache Drop-In
 *
 * Owner: mincemeat-object-cache
 * Version: 1.0.0-dev
 * Drop-in Version: 1.0.0-dev
 * Schema Version: 1
 * Build Hash: 197259d5e0ef621034721659c5b78535a476279ad314a5789b0c719e9dd428ce
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache {
	use InvalidArgumentException;
	use Redis;
	use RuntimeException;
	use WP_CLI;

	// --- Api.php ---
	/**
	 * Static, read-only interoperability and diagnostics API.
	 */
	final class Api {

		/** Implementation version. */
		public const IMPLEMENTATION_VERSION = '1.0.0-dev';

		/** Value envelope schema version. */
		public const SCHEMA_VERSION = '1';

		/** Native WordPress cache features implemented by this drop-in. */
		public const NATIVE_FEATURES = array(
			'add_multiple',
			'set_multiple',
			'get_multiple',
			'delete_multiple',
			'flush_runtime',
			'flush_group',
		);

		/**
		 * Returns the current cache state and a stable reason code.
		 *
		 * @return array{state:string,reason:string}
		 */
		public static function status(): array {
			$cache = self::cache();

			return array(
				'state'  => $cache ? $cache->state() : ObjectCache::STATE_RUNTIME_ONLY,
				'reason' => $cache ? $cache->reason() : 'not-initialized',
			);
		}

		/**
		 * Returns advertised transport, client, and native WordPress capabilities.
		 *
		 * In v1 the client is PhpRedis. The persistent transport is reported once
		 * the backend is connected (Phase 3); at the runtime tier it is 'none'.
		 *
		 * @return array{client:string,transport:string,features:array<int,string>}
		 */
		public static function capabilities(): array {
			$cache     = self::cache();
			$transport = 'none';

			if ( $cache && $cache->state() === ObjectCache::STATE_PERSISTENT ) {
				$transport = $cache->scheme();
			}

			return array(
				'client'    => 'phpredis',
				'transport' => $transport,
				'features'  => self::NATIVE_FEATURES,
			);
		}

		/**
		 * Returns request-local counters.
		 *
		 * @return array{hits:int,misses:int,backend_calls:int,backend_time:float,errors:int}
		 */
		public static function metrics(): array {
			$cache = self::cache();

			if ( ! $cache) {
				return array(
					'hits'          => 0,
					'misses'        => 0,
					'backend_calls' => 0,
					'backend_time'  => 0.0,
					'errors'        => 0,
				);
			}

			return array(
				'hits'          => $cache->hits(),
				'misses'        => $cache->misses(),
				'backend_calls' => $cache->backend_calls(),
				'backend_time'  => $cache->backend_time(),
				'errors'        => $cache->errors(),
			);
		}

		/**
		 * Returns redacted structured diagnostics suitable for Site Health.
		 *
		 * No credentials, raw keys, cached values, or stack traces are included.
		 *
		 * @return array<string,mixed>
		 */
		public static function diagnostics(): array {
			$cache = self::cache();

			$redis_version = 'unknown';
			if ( class_exists( 'Redis' ) ) {
				if ( defined( 'Redis::VERSION' ) ) {
					$redis_version = Redis::VERSION;
				} elseif ( method_exists( 'Redis', 'getVersion' ) ) {
					$redis_version = ( new Redis() )->getVersion();
				} else {
					$redis_version = phpversion( 'redis' ) ? phpversion( 'redis' ) : 'unknown';
				}
			}

			$diagnostics = array(
				'state'                 => $cache ? $cache->state() : ObjectCache::STATE_RUNTIME_ONLY,
				'reason'                => $cache ? $cache->reason() : 'not-initialized',
				'last_error'            => $cache ? $cache->last_error() : '',
				'multisite'             => $cache ? $cache->key_space()->is_multisite() : false,
				'global_groups'         => $cache ? array_keys( $cache->key_space()->global_groups() ) : array(),
				'non_persistent_groups' => $cache ? self::non_persistent_group_names( $cache ) : array(),
				'metrics'               => self::metrics(),
				'versions'              => self::version(),
				'php_version'           => PHP_VERSION,
				'phpredis_version'      => $redis_version,
			);

			if ( $cache ) {
				$config = $cache->config();
				if ( $config ) {
					$diagnostics = array_merge( $diagnostics, $config->redacted_diagnostics() );
				}
				$server_info = $cache->server_info();
				if ( $server_info ) {
					$diagnostics['server'] = $server_info;
				}
			}

			if ( function_exists( 'apply_filters' ) ) {
				$diagnostics = apply_filters( 'mincemeat_object_cache_diagnostics', $diagnostics );
			}

			return $diagnostics;
		}

		/**
		 * Returns implementation and schema version information.
		 *
		 * @return array{implementation:string,schema:string}
		 */
		public static function version(): array {
			return array(
				'implementation' => self::IMPLEMENTATION_VERSION,
				'schema'         => self::SCHEMA_VERSION,
			);
		}

		/**
		 * Returns the global cache instance or null when not initialized.
		 *
		 * @return ObjectCache|null
		 */
		private static function cache(): ?ObjectCache {
			if ( ! isset( $GLOBALS['wp_object_cache'] ) || ! $GLOBALS['wp_object_cache'] instanceof ObjectCache) {
				return null;
			}

			return $GLOBALS['wp_object_cache'];
		}

		/**
		 * Returns the list of non-persistent group names for diagnostics.
		 *
		 * @param ObjectCache $cache The cache instance.
		 * @return array<int,string>
		 */
		private static function non_persistent_group_names( ObjectCache $cache): array {
			return array_keys( $cache->non_persistent_groups() );
		}
	}

	// --- Backend.php ---
	/**
	 * Persistent backend coordinator: connection, tokens, circuit breaker.
	 *
	 * @internal
	 */
	final class Backend {

		/** Reason codes for the circuit breaker. */
		public const REASON_NO_BACKEND        = 'no-backend';
		public const REASON_MISSING_EXTENSION = 'missing-extension';
		public const REASON_CONFIG_INVALID    = 'config-invalid';
		public const REASON_CONNECT_FAILED    = 'connect-failed';
		public const REASON_AUTH_FAILED       = 'auth-failed';
		public const REASON_SELECT_DB_FAILED  = 'select-db-failed';
		public const REASON_COMMAND_FAILED    = 'command-failed';

		/**
		 * @var PhpRedisAdapter|null
		 */
		private $adapter;

		/**
		 * @var Config|null
		 */
		private $config;

		/**
		 * @var KeySpace
		 */
		private $key_space;

		/**
		 * Current circuit state: one of ObjectCache::STATE_*.
		 *
		 * @var string
		 */
		private $state;

		/**
		 * Stable reason code for the current state.
		 *
		 * @var string
		 */
		private $reason;

		/**
		 * Memoized tokens: namespace => string, group => string.
		 *
		 * @var array<string,string>
		 */
		private $tokens = array();

		/**
		 * Whether the namespace token has been resolved this request.
		 *
		 * @var bool
		 */
		private $namespace_token_loaded = false;

		/**
		 * Sanitized server identity or null.
		 *
		 * @var array<string,string>|null
		 */
		private $server_info;

		/**
		 * Whether an error has been logged already this request.
		 *
		 * @var bool
		 */
		private $logged = false;

		/**
		 * Redacted last error message.
		 *
		 * @var string
		 */
		private $last_error_message = '';

		/**
		 * Whether the degraded action has been fired already this request.
		 *
		 * @var bool
		 */
		private $degraded_fired = false;

		public function __construct( KeySpace $key_space, ?PhpRedisAdapter $adapter = null) {
			$this->key_space = $key_space;
			$this->state     = ObjectCache::STATE_RUNTIME_ONLY;
			$this->reason    = self::REASON_NO_BACKEND;
			$this->adapter   = $adapter;
		}

		/**
		 * Attempts to establish the persistent connection from a Config.
		 *
		 * On failure, the backend remains in runtime-only with a stable reason so
		 * the ObjectCache continues with coherent in-memory behavior.
		 *
		 * @param Config $config
		 */
		public function initialize( Config $config): void {
			$this->config = $config;
			$this->key_space->configure( $config );

			if ($this->adapter === null) {
				$this->adapter = new PhpRedisAdapter();
			}

			try {
				$this->adapter->connect( $config );
			} catch (BackendException $e) {
				$this->state   = ObjectCache::STATE_RUNTIME_ONLY;
				$this->reason  = $e->reason();
				$this->adapter = null;

				$this->log_error( 'Initialization failed: ' . $e->reason(), $e );

				return;
			}

			$this->state  = ObjectCache::STATE_PERSISTENT;
			$this->reason = '';

			// Capture server identity for diagnostics (no behavior forks).
			$this->server_info = $this->adapter->server_info();
		}

		/**
		 * The current circuit state.
		 *
		 * @return string One of ObjectCache::STATE_*.
		 */
		public function state(): string {
			return $this->state;
		}

		/**
		 * The stable reason code for the current state.
		 *
		 * @return string
		 */
		public function reason(): string {
			return $this->reason;
		}

		/**
		 * Whether the backend is currently usable for commands.
		 *
		 * @return bool
		 */
		public function is_persistent(): bool {
			return $this->state === ObjectCache::STATE_PERSISTENT;
		}

		/**
		 * Transition the backend to runtime-only mode due to an external initialization failure.
		 *
		 * @param string     $reason    Stable reason category.
		 * @param \Throwable $exception Underlying exception.
		 */
		public function degrade_to_runtime_only( string $reason, \Throwable $exception ): void {
			$this->state  = ObjectCache::STATE_RUNTIME_ONLY;
			$this->reason = $reason;
			$this->log_error( 'Initialization failed: ' . $reason, $exception );
		}

		/**
		 * Returns the redacted last error message.
		 *
		 * @return string
		 */
		public function last_error(): string {
			return $this->last_error_message;
		}

		/**
		 * Sanitized server identity, or null when not connected.
		 *
		 * @return array<string,string>|null
		 */
		public function server_info(): ?array {
			return $this->server_info;
		}

		/**
		 * The configured max_ttl, or 0 when not initialized.
		 *
		 * @return int
		 */
		public function max_ttl(): int {
			return $this->config !== null ? $this->config->max_ttl() : 0;
		}

		/**
		 * Returns the configured connection scheme.
		 *
		 * @return string
		 */
		public function scheme(): string {
			return $this->config !== null ? $this->config->scheme() : 'none';
		}

		/**
		 * Returns the configuration instance, or null.
		 *
		 * @return Config|null
		 */
		public function config(): ?Config {
			return $this->config;
		}

		/**
		 * Resolves the namespace generation token, initializing it with
		 * SET NX on first use and memoizing for the request.
		 *
		 * @return string 32-char hex token, or empty string when not persistent.
		 */
		public function namespace_token(): string {
			if ( ! $this->is_persistent()) {
				return '';
			}

			if ($this->namespace_token_loaded) {
				return $this->tokens['ns'] ?? '';
			}

			$key   = $this->key_space->namespace_control_key();
			$token = $this->init_token( $key );

			$this->tokens['ns']           = $token;
			$this->namespace_token_loaded = true;

			return $token;
		}

		/**
		 * Resolves a group generation token, initializing it on first use.
		 *
		 * @param string $group Normalized group name.
		 * @return string 32-char hex token, or empty string when not persistent.
		 */
		public function group_token( string $group): string {
			if ( ! $this->is_persistent()) {
				return '';
			}

			$cache_key = 'grp:' . $group;

			if (isset( $this->tokens[ $cache_key ] )) {
				return $this->tokens[ $cache_key ];
			}

			$key   = $this->key_space->group_control_key( $group );
			$token = $this->init_token( $key );

			$this->tokens[ $cache_key ] = $token;

			return $token;
		}

		/**
		 * Resolves group tokens for a batch of groups, coalescing into a single
		 * MGET after initializing any missing ones.
		 *
		 * @param array<int,string> $groups Normalized group names.
		 * @return array<string,string> Map of group name => token.
		 */
		public function group_tokens( array $groups): array {
			if ( ! $this->is_persistent()) {
				return array();
			}

			$missing  = array();
			$resolved = array();

			foreach ($groups as $group) {
				$cache_key = 'grp:' . $group;
				if (isset( $this->tokens[ $cache_key ] )) {
					$resolved[ $group ] = $this->tokens[ $cache_key ];
				} else {
					$missing[] = $group;
				}
			}

			if (count( $missing ) === 0) {
				return $resolved;
			}

			$keys = array();
			foreach ($missing as $group) {
				$keys[] = $this->key_space->group_control_key( $group );
			}

			try {
				$values = $this->adapter->mget( $keys );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );
				return array();
			}

			$still_missing = array();
			foreach ($missing as $i => $group) {
				$raw = $values[ $i ] ?? false;
				$tok = is_string( $raw ) ? trim( $raw ) : '';
				if ($tok !== '') {
					$this->tokens[ 'grp:' . $group ] = $tok;
					$resolved[ $group ]              = $tok;
				} else {
					$still_missing[] = $group;
				}
			}

			if (count( $still_missing ) > 0) {
				foreach ($still_missing as $group) {
					$key   = $this->key_space->group_control_key( $group );
					$token = $this->init_token( $key );
					if ($token === '') {
						$token = KeySpace::generate_token();
					}
					$this->tokens[ 'grp:' . $group ] = $token;
					$resolved[ $group ]              = $token;
				}
			}

			return $resolved;
		}

		/**
		 * Replaces the namespace generation token (used by flush).
		 *
		 * @return bool True on success.
		 */
		public function replace_namespace_token(): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			$key   = $this->key_space->namespace_control_key();
			$token = KeySpace::generate_token();

			try {
				$ok = $this->adapter->set_unconditional( $key, $token );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );
				return false;
			}

			if ($ok) {
				$this->tokens['ns'] = $token;
			}

			return $ok;
		}

		/**
		 * Replaces a group generation token (used by flush_group).
		 *
		 * @param string $group Normalized group name.
		 * @return bool True on success.
		 */
		public function replace_group_token( string $group): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			$key   = $this->key_space->group_control_key( $group );
			$token = KeySpace::generate_token();

			try {
				$ok = $this->adapter->set_unconditional( $key, $token );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );
				return false;
			}

			if ($ok) {
				$this->tokens[ 'grp:' . $group ] = $token;
			}

			return $ok;
		}

		/**
		 * GET a single encoded value.
		 *
		 * @param string $key Backend key.
		 * @return string|false
		 */
		public function get( string $key) {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter->get( $key );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return false;
			}
		}

		/**
		 * MGET a batch of encoded values.
		 *
		 * @param array<int,string> $keys
		 * @return array<int,string|false>
		 */
		public function mget( array $keys): array {
			if ( ! $this->is_persistent()) {
				return array_fill( 0, count( $keys ), false );
			}

			try {
				return $this->adapter->mget( $keys );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $keys ), false );
			}
		}

		/**
		 * Conditional SET (NX for add, XX for replace).
		 *
		 * @param string   $key
		 * @param string   $value
		 * @param int|null $ttl_ms
		 * @param bool     $nx
		 * @param bool     $xx
		 * @return bool
		 */
		public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter->set( $key, $value, $ttl_ms, $nx, $xx );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return false;
			}
		}

		/**
		 * Unconditional SET.
		 *
		 * @param string   $key
		 * @param string   $value
		 * @param int|null $ttl_ms
		 * @return bool
		 */
		public function set_unconditional( string $key, string $value, ?int $ttl_ms = null): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter->set_unconditional( $key, $value, $ttl_ms );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return false;
			}
		}

		/**
		 * DEL a single key.
		 *
		 * @param string $key
		 * @return int
		 */
		public function del( string $key): int {
			if ( ! $this->is_persistent()) {
				return 0;
			}

			try {
				return $this->adapter->del( $key );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return 0;
			}
		}

		/**
		 * DEL multiple keys via pipeline.
		 *
		 * @param array<int,string> $keys
		 * @return array<int,bool> Per-key success (true if deleted/cleared).
		 */
		public function del_pipeline( array $keys): array {
			if ( ! $this->is_persistent() || count( $keys ) === 0) {
				return array_fill( 0, count( $keys ), false );
			}

			$commands = array();
			foreach ($keys as $key) {
				$commands[] = array( 'unlink', array( $key ) );
			}

			try {
				$results = $this->adapter->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $keys ), false );
			}

			$out = array();
			foreach ($results as $i => $r) {
				$out[] = $r !== false && (int) $r >= 0;
			}

			// If UNLINK produced an error result (false), fall back to DEL.
			if (in_array( false, $results, true )) {
				$commands = array();
				foreach ($keys as $key) {
					$commands[] = array( 'del', array( $key ) );
				}

				try {
					$results = $this->adapter->pipeline( $commands );
				} catch (\Throwable $e) {
					$this->degrade( self::REASON_COMMAND_FAILED, $e );

					return array_fill( 0, count( $keys ), false );
				}

				$out = array();
				foreach ($results as $i => $r) {
					$out[] = $r !== false && (int) $r >= 0;
				}
			}

			return $out;
		}

		/**
		 * Set multiple keys via pipeline (all unconditional).
		 *
		 * @param array<int,array{0:string,1:string,2:?int}> $entries [key, value, ttl_ms].
		 * @return array<int,bool>
		 */
		public function set_pipeline( array $entries): array {
			if ( ! $this->is_persistent() || count( $entries ) === 0) {
				return array_fill( 0, count( $entries ), false );
			}

			$commands = array();
			foreach ($entries as $entry) {
				$commands[] = $this->build_set_command( $entry[0], $entry[1], $entry[2], false, false );
			}

			try {
				$results = $this->adapter->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $entries ), false );
			}

			$out = array();
			foreach ($results as $r) {
				$out[] = $r === true;
			}

			return $out;
		}

		/**
		 * Conditional set (NX/XX) for multiple keys via pipeline.
		 *
		 * @param array<int,array{0:string,1:string,2:?int,3:bool,4:bool}> $entries [key, value, ttl_ms, nx, xx].
		 * @return array<int,bool>
		 */
		public function set_conditional_pipeline( array $entries): array {
			if ( ! $this->is_persistent() || count( $entries ) === 0) {
				return array_fill( 0, count( $entries ), false );
			}

			$commands = array();
			foreach ($entries as $entry) {
				$commands[] = $this->build_set_command( $entry[0], $entry[1], $entry[2], $entry[3], $entry[4] );
			}

			try {
				$results = $this->adapter->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $entries ), false );
			}

			$out = array();
			foreach ($results as $r) {
				$out[] = $r === true;
			}

			return $out;
		}

		/**
		 * EVAL a Lua script.
		 *
		 * @param string             $script
		 * @param array<int,string> $keys
		 * @param array<int,mixed>  $args
		 * @return mixed
		 */
		public function eval( string $script, array $keys = array(), array $args = array()) {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter->eval( $script, $keys, $args );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return false;
			}
		}

		/**
		 * Atomic increment/decrement via Lua script.
		 *
		 * @param string $item_key The backend item key.
		 * @param int    $offset   Signed offset (positive for incr, negative for decr).
		 * @return array{0:string,1:?int} Tuple [result_code, new_value].
		 *         result_code is one of LuaScripts::RESULT_*; new_value is null
		 *         for all non-success results.
		 */
		public function eval_incr( string $item_key, int $offset): array {
			if ( ! $this->is_persistent()) {
				return array( LuaScripts::RESULT_MISSING, null );
			}

			try {
				$result = $this->adapter->eval(
					LuaScripts::INCR_DECR,
					array( $item_key ),
					array( (string) $offset )
				);
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array( LuaScripts::RESULT_MISSING, null );
			}

			if ( ! is_array( $result ) || ! isset( $result[0] )) {
				return array( LuaScripts::RESULT_CORRUPT, null );
			}

			$code = (string) $result[0];

			if ($code === LuaScripts::RESULT_OK && isset( $result[1] )) {
				return array( LuaScripts::RESULT_OK, (int) $result[1] );
			}

			return array( $code, null );
		}

		/**
		 * Closes the connection.
		 *
		 * @return void
		 */
		public function close(): void {
			if ($this->adapter !== null) {
				$this->adapter->close();
			}
		}

		// ------------------------------------------------------------------
		// Internals
		// ------------------------------------------------------------------

		/**
		 * Initializes a token via SET NX and reads back the winner.
		 *
		 * If the SET NX succeeds, the generated token is the winner. If someone
		 * else won the race, we read back their token. Either way, the token is
		 * stable for this request.
		 *
		 * @param string $key The control key.
		 * @return string 32-char hex token.
		 */
		private function init_token( string $key): string {
			if ( ! $this->is_persistent()) {
				return '';
			}

			$token = KeySpace::generate_token();

			try {
				$created = $this->adapter->set( $key, $token, null, true );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return KeySpace::generate_token();
			}

			if ($created) {
				return $token;
			}

			// Someone else won the race; read back their token.
			try {
				$existing = $this->adapter->get( $key );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return KeySpace::generate_token();
			}

			if (is_string( $existing ) && trim( $existing ) !== '') {
				return trim( $existing );
			}

			// Edge: control key was evicted between SET NX failure and GET.
			// Retry with a fresh token.
			$token2 = KeySpace::generate_token();
			try {
				$created2 = $this->adapter->set( $key, $token2, null, true );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return KeySpace::generate_token();
			}

			if ($created2) {
				return $token2;
			}

			// Try one more get
			try {
				$existing2 = $this->adapter->get( $key );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return KeySpace::generate_token();
			}

			if (is_string( $existing2 ) && trim( $existing2 ) !== '') {
				return trim( $existing2 );
			}

			return KeySpace::generate_token();
		}

		/**
		 * Opens the circuit breaker: transitions to degraded state, fires the
		 * low-volume action once, and prevents all further backend commands.
		 *
		 * @param string          $reason
		 * @param \Throwable|null $exception
		 */
		private function degrade( string $reason, ?\Throwable $exception = null ): void {
			if ($this->state === ObjectCache::STATE_DEGRADED) {
				return;
			}

			$this->state  = ObjectCache::STATE_DEGRADED;
			$this->reason = $reason;

			$this->log_error( 'Backend degraded: ' . $reason, $exception );

			if ( ! $this->degraded_fired && function_exists( 'do_action' )) {
				$this->degraded_fired = true;
				do_action( 'mincemeat_object_cache_degraded', $reason );
			}
		}

		/**
		 * Logs a sanitized, redacted error message to the error log exactly once per request.
		 *
		 * @internal
		 * @param string          $message   The log message.
		 * @param \Throwable|null $exception Optional associated exception.
		 */
		public function log_error( string $message, ?\Throwable $exception = null ): void {
			if ( $this->logged ) {
				return;
			}
			$this->logged = true;

			$raw_msg                  = $exception !== null ? $exception->getMessage() : $message;
			$this->last_error_message = $this->redact_secrets( $raw_msg );

			$log_msg = 'Mincemeat Object Cache: ' . $message;
			if ( $this->config !== null && $this->config->debug() ) {
				if ( $exception !== null ) {
					$log_msg .= sprintf(
						' (Exception: %s, Message: %s, Code: %d)',
						get_class( $exception ),
						$this->redact_secrets( $exception->getMessage() ),
						$exception->getCode()
					);
				}
			}

			error_log( $log_msg );
		}

		/**
		 * Redacts credentials and other sensitive data from a string.
		 *
		 * @param string $msg Input string.
		 * @return string Redacted string.
		 */
		private function redact_secrets( string $msg ): string {
			if ( $this->config === null ) {
				return $msg;
			}

			$search = array();

			$password = $this->config->password();
			if ( $password !== null && $password !== '' ) {
				$search[] = $password;
			}

			$username = $this->config->username();
			if ( $username !== null && $username !== '' ) {
				$search[] = $username;
			}

			if ( count( $search ) > 0 ) {
				return str_replace( $search, '[REDACTED]', $msg );
			}

			return $msg;
		}

		/**
		 * Builds a pipeline SET command array.
		 *
		 * @param string   $key
		 * @param string   $value
		 * @param int|null $ttl_ms
		 * @param bool     $nx
		 * @param bool     $xx
		 * @return array{0:string,1:array}
		 */
		private function build_set_command( string $key, string $value, ?int $ttl_ms, bool $nx, bool $xx): array {
			$args = array( $key, $value );

			$options = array();
			if ($nx) {
				$options[] = 'NX';
			}
			if ($xx) {
				$options[] = 'XX';
			}
			if ($ttl_ms !== null && $ttl_ms > 0) {
				$options['PX'] = $ttl_ms;
			}

			if (count( $options ) > 0) {
				$args[] = $options;
			}

			return array( 'set', $args );
		}
	}

	// --- BackendException.php ---
	/**
	 * Thrown when the persistent backend fails to connect, authenticate, or
	 * execute a command. The reason code is stable for Site Health; the message
	 * never carries credentials, keys, or cached values.
	 */
	final class BackendException extends RuntimeException {

		/**
		 * @var string
		 */
		private $reason;

		public function __construct( string $reason, string $message = '', int $code = 0, ?\Throwable $previous = null) {
			parent::__construct( $message === '' ? $reason : $message, $code, $previous );
			$this->reason = $reason;
		}

		/**
		 * The stable reason code for diagnostics.
		 *
		 * @return string
		 */
		public function reason(): string {
			return $this->reason;
		}
	}

	// --- CliCommand.php ---
	/**
	 * Manage the Mincemeat Object Cache drop-in lifecycle and check status.
	 */
	final class CliCommand {

		/**
		 * Displays the object cache status.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mincemeat-cache status
		 *
		 * @param array $args       Command arguments.
		 * @param array $assoc_args Command associative arguments.
		 */
		public function status( array $args, array $assoc_args ): void {
			$state       = Lifecycle::get_dropin_state();
			$diagnostics = Api::diagnostics();

			WP_CLI::line( 'Drop-in Status: ' . $state );
			WP_CLI::line( 'Cache Status:   ' . $diagnostics['state'] );
			WP_CLI::line( 'Reason:         ' . $diagnostics['reason'] );

			if ( isset( $diagnostics['scheme'] ) ) {
				WP_CLI::line( 'Scheme:         ' . $diagnostics['scheme'] );
				WP_CLI::line( 'Host:           ' . ( $diagnostics['host'] ?? '' ) );
				WP_CLI::line( 'Port:           ' . ( $diagnostics['port'] ?? '' ) );
				WP_CLI::line( 'Database:       ' . ( $diagnostics['database'] ?? '' ) );
				WP_CLI::line( 'Namespace:      ' . ( $diagnostics['namespace_digest'] ?? '' ) );
			}
		}

		/**
		 * Installs the Mincemeat Object Cache drop-in.
		 *
		 * Refuses to overwrite a foreign drop-in.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mincemeat-cache install-dropin
		 *
		 * @param array $args       Command arguments.
		 * @param array $assoc_args Command associative arguments.
		 */
		public function install_dropin( array $args, array $assoc_args ): void {
			$state = Lifecycle::get_dropin_state();

			if ( $state === Lifecycle::STATE_OWNED_CURRENT ) {
				WP_CLI::success( 'Drop-in is already installed and up to date.' );
				return;
			}

			if ( $state === Lifecycle::STATE_FOREIGN ) {
				WP_CLI::error( 'A foreign object-cache.php drop-in is present. Overwriting foreign drop-ins is refused.' );
				return;
			}

			$success = Lifecycle::install_dropin();
			if ( $success ) {
				WP_CLI::success( 'Mincemeat Object Cache drop-in installed successfully.' );
			} else {
				WP_CLI::error( 'Failed to install Mincemeat Object Cache drop-in. Please check filesystem permissions.' );
			}
		}

		/**
		 * Updates the Mincemeat Object Cache drop-in.
		 *
		 * Refuses to overwrite a foreign drop-in.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mincemeat-cache update-dropin
		 *
		 * @param array $args       Command arguments.
		 * @param array $assoc_args Command associative arguments.
		 */
		public function update_dropin( array $args, array $assoc_args ): void {
			$state = Lifecycle::get_dropin_state();

			if ( $state === Lifecycle::STATE_OWNED_CURRENT ) {
				WP_CLI::success( 'Drop-in is already up to date.' );
				return;
			}

			if ( $state === Lifecycle::STATE_FOREIGN ) {
				WP_CLI::error( 'A foreign object-cache.php drop-in is present. Updating foreign drop-ins is refused.' );
				return;
			}

			if ( $state === Lifecycle::STATE_ABSENT ) {
				WP_CLI::error( 'Drop-in is not installed. Use "install-dropin" instead.' );
				return;
			}

			$success = Lifecycle::install_dropin();
			if ( $success ) {
				WP_CLI::success( 'Mincemeat Object Cache drop-in updated successfully.' );
			} else {
				WP_CLI::error( 'Failed to update Mincemeat Object Cache drop-in.' );
			}
		}

		/**
		 * Removes the Mincemeat Object Cache drop-in.
		 *
		 * Refuses to remove a foreign drop-in.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mincemeat-cache remove-dropin
		 *
		 * @param array $args       Command arguments.
		 * @param array $assoc_args Command associative arguments.
		 */
		public function remove_dropin( array $args, array $assoc_args ): void {
			$state = Lifecycle::get_dropin_state();

			if ( $state === Lifecycle::STATE_ABSENT ) {
				WP_CLI::success( 'No drop-in found to remove.' );
				return;
			}

			if ( $state === Lifecycle::STATE_FOREIGN ) {
				WP_CLI::error( 'A foreign object-cache.php drop-in is present. Removing foreign drop-ins is refused.' );
				return;
			}

			$success = Lifecycle::remove_dropin();
			if ( $success ) {
				WP_CLI::success( 'Mincemeat Object Cache drop-in removed successfully.' );
			} else {
				WP_CLI::error( 'Failed to remove Mincemeat Object Cache drop-in.' );
			}
		}
	}

	// --- Config.php ---
	/**
	 * Immutable, validated configuration for the object cache.
	 */
	final class Config {

		/** Schema/version marker used in derived key layout. */
		public const SCHEMA_MARKER = 'mcoc1';

		/** Reason codes. */
		public const REASON_MISSING         = 'config-missing';
		public const REASON_NOT_ARRAY       = 'config-not-array';
		public const REASON_UNKNOWN_KEY     = 'config-unknown-key';
		public const REASON_NAMESPACE       = 'config-namespace';
		public const REASON_SCHEME          = 'config-scheme';
		public const REASON_HOST            = 'config-host';
		public const REASON_PORT            = 'config-port';
		public const REASON_PATH            = 'config-path';
		public const REASON_DATABASE        = 'config-database';
		public const REASON_USERNAME        = 'config-username';
		public const REASON_PASSWORD        = 'config-password';
		public const REASON_CONNECT_TIMEOUT = 'config-connect-timeout';
		public const REASON_READ_TIMEOUT    = 'config-read-timeout';
		public const REASON_PERSISTENT      = 'config-persistent';
		public const REASON_MAX_TTL         = 'config-max-ttl';
		public const REASON_TLS             = 'config-tls';
		public const REASON_DEBUG           = 'config-debug';

		/** Schemes. */
		public const SCHEME_TCP  = 'tcp';
		public const SCHEME_TLS  = 'tls';
		public const SCHEME_UNIX = 'unix';

		private const SCHEMES = array( self::SCHEME_TCP, self::SCHEME_TLS, self::SCHEME_UNIX );

		/** Upper bound for any single timeout in seconds. */
		public const MAX_TIMEOUT = 60.0;

		/** Maximum namespace length in bytes. */
		public const NAMESPACE_MAX_LENGTH = 255;

		/** Largest usable TCP port. */
		public const PORT_MIN = 1;
		public const PORT_MAX = 65535;

		/** Default maximum TTL: 30 days in seconds. */
		public const DEFAULT_MAX_TTL = 2592000;

		/**
		 * Known config keys, mapped to defaults applied when the key is absent.
		 *
		 * @var array<string,mixed>
		 */
		private const KNOWN_KEYS = array(
			'namespace'       => null,
			'scheme'          => self::SCHEME_TCP,
			'host'            => '127.0.0.1',
			'port'            => 6379,
			'path'            => null,
			'database'        => 0,
			'username'        => null,
			'password'        => null,
			'connect_timeout' => 0.25,
			'read_timeout'    => 0.25,
			'persistent'      => false,
			'max_ttl'         => self::DEFAULT_MAX_TTL,
			'tls'             => array(),
			'debug'           => false,
		);

		/** @var string */
		private $namespace;

		/** @var string */
		private $scheme;

		/** @var string */
		private $host;

		/** @var int */
		private $port;

		/** @var string|null */
		private $path;

		/** @var int */
		private $database;

		/** @var string|null */
		private $username;

		/** @var string|null */
		private $password;

		/** @var float */
		private $connect_timeout;

		/** @var float */
		private $read_timeout;

		/** @var bool */
		private $persistent;

		/** @var int */
		private $max_ttl;

		/** @var array<string,mixed> */
		private $tls;

		/** @var bool */
		private $debug;

		/** @var string */
		private $namespace_digest;

		/**
		 * @param array<string,mixed> $input Raw config array.
		 * @throws ConfigException On any validation failure.
		 */
		public function __construct( array $input) {
			$this->reject_unknown_keys( $input );

			$namespace = $input['namespace'] ?? null;
			$this->validate_namespace( $namespace );
			$this->namespace = $namespace;

			$scheme = $input['scheme'] ?? self::SCHEME_TCP;
			$this->validate_scheme( $scheme );
			$this->scheme = $scheme;

			$host = $input['host'] ?? self::KNOWN_KEYS['host'];
			$this->validate_host( $host, $this->scheme );
			$this->host = $scheme === self::SCHEME_UNIX ? self::KNOWN_KEYS['host'] : $host;

			$port = $input['port'] ?? self::KNOWN_KEYS['port'];
			$this->validate_port( $port, $this->scheme );
			$this->port = $scheme === self::SCHEME_UNIX ? self::KNOWN_KEYS['port'] : (int) $port;

			$path = $input['path'] ?? self::KNOWN_KEYS['path'];
			$this->validate_path( $path, $this->scheme );
			$this->path = $scheme === self::SCHEME_UNIX ? $path : null;

			$database = $input['database'] ?? self::KNOWN_KEYS['database'];
			$this->validate_database( $database );
			$this->database = (int) $database;

			$username = $input['username'] ?? null;
			$this->validate_username( $username );
			$this->username = $username === null ? null : (string) $username;

			$password = $input['password'] ?? null;
			$this->validate_password( $password );
			$this->password = $password === null ? null : (string) $password;

			$ct = $input['connect_timeout'] ?? self::KNOWN_KEYS['connect_timeout'];
			$this->validate_timeout( $ct, self::REASON_CONNECT_TIMEOUT );
			$this->connect_timeout = (float) $ct;

			$rt = $input['read_timeout'] ?? self::KNOWN_KEYS['read_timeout'];
			$this->validate_timeout( $rt, self::REASON_READ_TIMEOUT );
			$this->read_timeout = (float) $rt;

			$persistent = $input['persistent'] ?? self::KNOWN_KEYS['persistent'];
			$this->validate_bool( $persistent, self::REASON_PERSISTENT );
			$this->persistent = (bool) $persistent;

			$max_ttl = $input['max_ttl'] ?? self::KNOWN_KEYS['max_ttl'];
			$this->validate_max_ttl( $max_ttl );
			$this->max_ttl = (int) $max_ttl;

			$tls = $input['tls'] ?? self::KNOWN_KEYS['tls'];
			$this->validate_tls( $tls, $this->scheme );
			$this->tls = is_array( $tls ) ? $tls : array();

			$debug = $input['debug'] ?? self::KNOWN_KEYS['debug'];
			$this->validate_bool( $debug, self::REASON_DEBUG );
			$this->debug = (bool) $debug;

			$this->namespace_digest = hash( 'sha256', $this->namespace );
		}

		/**
		 * Reads and validates MINCEMEAT_OBJECT_CACHE_CONFIG.
		 *
		 * @throws ConfigException When the constant is missing or invalid.
		 */
		public static function from_constant(): Config {
			if ( ! defined( 'MINCEMEAT_OBJECT_CACHE_CONFIG' )) {
				throw new ConfigException( self::REASON_MISSING );
			}

			$input = MINCEMEAT_OBJECT_CACHE_CONFIG;

			if ( ! is_array( $input )) {
				throw new ConfigException( self::REASON_NOT_ARRAY );
			}

			return new self( $input );
		}

		/**
		 * The source namespace string (never exposed via redacted diagnostics).
		 *
		 * @return string
		 */
		public function namespace(): string {
			return $this->namespace;
		}

		/**
		 * SHA-256 digest of the source namespace; safe for diagnostics.
		 *
		 * @return string
		 */
		public function namespace_digest(): string {
			return $this->namespace_digest;
		}

		public function scheme(): string {
			return $this->scheme;
		}

		public function host(): string {
			return $this->host;
		}

		public function port(): int {
			return $this->port;
		}

		public function path(): ?string {
			return $this->path;
		}

		public function database(): int {
			return $this->database;
		}

		public function username(): ?string {
			return $this->username;
		}

		public function password(): ?string {
			return $this->password;
		}

		public function connect_timeout(): float {
			return $this->connect_timeout;
		}

		public function read_timeout(): float {
			return $this->read_timeout;
		}

		public function persistent(): bool {
			return $this->persistent;
		}

		public function max_ttl(): int {
			return $this->max_ttl;
		}

		/**
		 * The raw TLS context array. Exposed only to the PhpRedis adapter, never
		 * to the public API.
		 *
		 * @return array<string,mixed>
		 */
		public function tls(): array {
			return $this->tls;
		}

		public function debug(): bool {
			return $this->debug;
		}

		/**
		 * Redacted diagnostics suitable for Site Health. Includes only safe
		 * identifiers; never the source namespace, username, password, DSN, or
		 * TLS key material paths.
		 *
		 * @return array<string,mixed>
		 */
		public function redacted_diagnostics(): array {
			$tls_summary = array(
				'verify_peer'      => $this->tls_verify_peer(),
				'verify_peer_name' => $this->tls_verify_peer_name(),
			);

			return array(
				'scheme'           => $this->scheme,
				'host'             => $this->scheme === self::SCHEME_UNIX ? '' : $this->host,
				'port'             => $this->scheme === self::SCHEME_UNIX ? null : $this->port,
				'database'         => $this->database,
				'namespace_digest' => substr( $this->namespace_digest, 0, 16 ),
				'connect_timeout'  => $this->connect_timeout,
				'read_timeout'     => $this->read_timeout,
				'persistent'       => $this->persistent,
				'max_ttl'          => $this->max_ttl,
				'debug'            => $this->debug,
				'tls'              => $tls_summary,
			);
		}

		/**
		 * Whether peer verification is allowed to remain disabled (Site Health
		 * warns when TLS is configured and verification is off).
		 *
		 * @return bool
		 */
		public function tls_verify_peer(): bool {
			if (isset( $this->tls['verify_peer'] ) && $this->tls['verify_peer'] === false) {
				return false;
			}

			return true;
		}

		public function tls_verify_peer_name(): bool {
			if (isset( $this->tls['verify_peer_name'] ) && $this->tls['verify_peer_name'] === false) {
				return false;
			}

			return true;
		}

		/**
		 * Lists the known config keys.
		 *
		 * @return array<int,string>
		 */
		public static function known_keys(): array {
			return array_keys( self::KNOWN_KEYS );
		}

		private function reject_unknown_keys( array $input): void {
			foreach (array_keys( $input ) as $key) {
				if ( ! array_key_exists( $key, self::KNOWN_KEYS )) {
					throw new ConfigException( self::REASON_UNKNOWN_KEY, sprintf( 'Unknown config key "%s".', $this->redact_value( $key ) ) );
				}
			}
		}

		private function validate_namespace( $value): void {
			$reason = self::REASON_NAMESPACE;

			if ( ! is_string( $value )) {
				throw new ConfigException( $reason, 'Namespace must be a string.' );
			}

			if (trim( $value ) === '') {
				throw new ConfigException( $reason, 'Namespace must not be blank.' );
			}

			if (strlen( $value ) > self::NAMESPACE_MAX_LENGTH) {
				throw new ConfigException( $reason, 'Namespace exceeds the maximum length.' );
			}

			if (preg_match( '/[\x00-\x1F\x7F]/', $value ) === 1) {
				throw new ConfigException( $reason, 'Namespace must not contain control characters.' );
			}
		}

		private function validate_scheme( $value): void {
			if ( ! is_string( $value ) || ! in_array( $value, self::SCHEMES, true )) {
				throw new ConfigException( self::REASON_SCHEME, 'Scheme must be tcp, tls, or unix.' );
			}
		}

		private function validate_host( $value, string $scheme): void {
			if ($scheme === self::SCHEME_UNIX) {
				return;
			}

			if ( ! is_string( $value ) || trim( $value ) === '') {
				throw new ConfigException( self::REASON_HOST, 'Host is required for tcp/tls.' );
			}
		}

		private function validate_port( $value, string $scheme): void {
			if ($scheme === self::SCHEME_UNIX) {
				return;
			}

			$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			if ( ! $ok) {
				throw new ConfigException( self::REASON_PORT, 'Port must be an integer.' );
			}

			$port = (int) $value;
			if ($port < self::PORT_MIN || $port > self::PORT_MAX) {
				throw new ConfigException( self::REASON_PORT, 'Port must be between 1 and 65535.' );
			}
		}

		private function validate_path( $value, string $scheme): void {
			if ($scheme !== self::SCHEME_UNIX) {
				return;
			}

			if ( ! is_string( $value ) || trim( $value ) === '') {
				throw new ConfigException( self::REASON_PATH, 'Path is required for unix sockets.' );
			}
		}

		private function validate_database( $value): void {
			$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			if ( ! $ok || (int) $value < 0) {
				throw new ConfigException( self::REASON_DATABASE, 'Database must be a non-negative integer.' );
			}
		}

		private function validate_username( $value): void {
			if ($value !== null && ! is_string( $value )) {
				throw new ConfigException( self::REASON_USERNAME, 'Username must be null or a string.' );
			}
		}

		private function validate_password( $value): void {
			if ($value !== null && ! is_string( $value )) {
				throw new ConfigException( self::REASON_PASSWORD, 'Password must be null or a string.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_timeout( $value, string $reason): void {
			$ok = is_int( $value )
				|| is_float( $value )
				|| ( is_string( $value ) && is_numeric( $value ) && strpos( $value, "\0" ) === false );
			if ( ! $ok) {
				throw new ConfigException( $reason, 'Timeout must be a positive number.' );
			}

			$f = (float) $value;
			if ($f <= 0 || $f > self::MAX_TIMEOUT) {
				throw new ConfigException( $reason, 'Timeout must be greater than zero and at most 60 seconds.' );
			}
		}

		private function validate_bool( $value, string $reason): void {
			if ( ! is_bool( $value ) && ! ( is_int( $value ) && ( $value === 0 || $value === 1 ) )) {
				throw new ConfigException( $reason, 'Value must be boolean.' );
			}
		}

		private function validate_max_ttl( $value): void {
			$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			if ( ! $ok || (int) $value < 0) {
				throw new ConfigException( self::REASON_MAX_TTL, 'max_ttl must be a non-negative integer.' );
			}
		}

		private function validate_tls( $value, string $scheme): void {
			if ($value === null || $value === array()) {
				return;
			}

			if ( ! is_array( $value )) {
				throw new ConfigException( self::REASON_TLS, 'TLS context must be an array.' );
			}
		}

		/**
		 * Best-effort redaction of a value used in error messages.
		 *
		 * @param mixed $value
		 */
		private function redact_value( $value): string {
			if ( ! is_string( $value )) {
				return '(non-string)';
			}

			return $value;
		}
	}

	// --- ConfigException.php ---
	/**
	 * Thrown when MINCEMEAT_OBJECT_CACHE_CONFIG fails validation.
	 *
	 * The reason code is stable for Site Health; the message is redacted and
	 * never contains credentials, the source namespace, or DSN strings.
	 */
	final class ConfigException extends InvalidArgumentException {

		/**
		 * @var string
		 */
		private $reason;

		public function __construct( string $reason, string $message = '') {
			parent::__construct( $message === '' ? $reason : $message );
			$this->reason = $reason;
		}

		/**
		 * The stable reason code for Site Health.
		 *
		 * @return string
		 */
		public function reason(): string {
			return $this->reason;
		}
	}

	// --- KeySpace.php ---
	/**
	 * Validate identity and derive scoped storage identifiers.
	 *
	 * @internal This class is an implementation detail of the runtime cache and
	 *           the generated drop-in. It is not part of the public API.
	 */
	final class KeySpace {

		/** Schema/version marker used in derived key layout. */
		public const SCHEMA_MARKER = 'mcoc1';

		/** Component markers within the derived key layout. */
		private const NS_TOKEN_MARKER    = 'nstok';
		private const GROUP_TOKEN_MARKER = 'gtok';
		private const ITEM_MARKER        = 'i';

		/** Single-site scope marker. */
		private const SINGLE_SITE_SCOPE = 's';

		/** Global scope marker. */
		private const GLOBAL_SCOPE = 'global';

		/**
		 * Random 128-bit generation token, lowercase hex (32 chars).
		 *
		 * @var string
		 */
		private $namespace_token = '';

		/**
		 * Registered global groups, keyed by group name for O(1) lookup.
		 *
		 * @var array<string,true>
		 */
		private $global_groups = array();

		/**
		 * Whether the cache is running in a multisite context.
		 *
		 * @var bool
		 */
		private $multisite;

		/**
		 * The blog prefix prepended to keys in non-global groups.
		 *
		 * @var string
		 */
		private $blog_prefix;

		/**
		 * The SHA-256 digest of the installation namespace; empty until set.
		 *
		 * @var string
		 */
		private $namespace_digest = '';

		/**
		 * Constructor.
		 *
		 * @param bool   $multisite Whether multisite is active.
		 * @param int    $blog_id   The current blog ID.
		 * @param string $namespace Optional installation namespace (Phase 3 injection).
		 */
		public function __construct( bool $multisite, int $blog_id, string $namespace = '') {
			$this->multisite   = $multisite;
			$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';

			if ($namespace !== '') {
				$this->namespace_digest = hash( 'sha256', $namespace );
			}
		}

		/**
		 * Sets or replaces the installation namespace digest from a Config.
		 *
		 * @param Config $config
		 */
		public function configure( Config $config): void {
			$this->namespace_digest = $config->namespace_digest();
		}

		/**
		 * Sets the namespace token for the current namespace generation.
		 *
		 * @param string $token 32-char lowercase hex token.
		 */
		public function set_namespace_token( string $token): void {
			$this->namespace_token = $token;
		}

		/**
		 * The currently memoized namespace token.
		 *
		 * @return string
		 */
		public function namespace_token(): string {
			return $this->namespace_token;
		}

		/**
		 * Registers one or more global groups.
		 *
		 * Accepts a scalar or array of group names, matching core behavior. Names
		 * are cast to string and deduplicated.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 */
		public function add_global_groups( $groups): void {
			$groups = (array) $groups;

			foreach ($groups as $group) {
				$this->global_groups[ (string) $group ] = true;
			}
		}

		/**
		 * Whether the given group is registered as global.
		 *
		 * @param string $group Normalized group name.
		 * @return bool
		 */
		public function is_global_group( string $group): bool {
			return isset( $this->global_groups[ $group ] );
		}

		/**
		 * The registered global groups.
		 *
		 * @return array<string,true>
		 */
		public function global_groups(): array {
			return $this->global_groups;
		}

		/**
		 * Whether the cache is running in a multisite context.
		 *
		 * @return bool
		 */
		public function is_multisite(): bool {
			return $this->multisite;
		}

		/**
		 * Switches the active blog scope used to derive non-global item identity.
		 *
		 * @param int $blog_id The blog ID to switch to.
		 */
		public function switch_to_blog( int $blog_id): void {
			$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';
		}

		/**
		 * The current blog prefix string (empty on single-site).
		 *
		 * @return string
		 */
		public function blog_prefix(): string {
			return $this->blog_prefix;
		}

		/**
		 * Validates a cache key against WordPress 6.9 rules.
		 *
		 * Integer keys and non-blank strings are valid. Any other type, and blank
		 * or whitespace-only strings, are invalid: `_doing_it_wrong()` is invoked
		 * when available and false is returned.
		 *
		 * @param mixed $key The key to validate.
		 * @return bool True when the key is valid.
		 */
		public function is_valid_key( $key): bool {
			if (is_int( $key )) {
				return true;
			}

			if (is_string( $key ) && '' !== trim( $key )) {
				return true;
			}

			$message = is_string( $key )
				? 'Cache key must not be an empty string.'
				: sprintf( 'Cache key must be an integer or a non-empty string, %s given.', gettype( $key ) );

			$caller = $this->invalid_key_caller();
			_doing_it_wrong( $caller, $message, '1.0.0' );

			return false;
		}

		/**
		 * Normalizes a group name: an empty group becomes 'default'.
		 *
		 * Groups are case-sensitive and never otherwise rewritten.
		 *
		 * @param string $group The raw group name.
		 * @return string The normalized group name.
		 */
		public function normalize_group( string $group): string {
			return '' === $group ? 'default' : $group;
		}

		/**
		 * Derives the in-memory storage identifier for a key within a group.
		 *
		 * For non-global groups under multisite, the active blog prefix is
		 * prepended so items cannot leak across blogs. Global groups use the key
		 * verbatim so they remain shared across the network.
		 *
		 * @param mixed  $key   The validated cache key.
		 * @param string $group The normalized group name.
		 * @return string The storage identifier.
		 */
		public function storage_id( $key, string $group): string {
			if ($this->multisite && ! isset( $this->global_groups[ $group ] )) {
				return $this->blog_prefix . $key;
			}

			return (string) $key;
		}

		/** ------------------------------------------------------------------
		 * Persistent key-space derivation (Phase 2)
		 * ---------------------------------------------------------------- */

		/**
		 * SHA-256 digest of the installation namespace (hex, lowercase, 64 chars).
		 *
		 * @return string
		 */
		public function namespace_digest(): string {
			return $this->namespace_digest;
		}

		/**
		 * SHA-256 digest of a group name (hex, lowercase, 64 chars).
		 *
		 * Group identity is case-sensitive and never normalized by removing
		 * characters; the digest is taken over the normalized group string.
		 *
		 * @param string $group Normalized group name.
		 * @return string
		 */
		public function group_digest( string $group): string {
			return hash( 'sha256', $group );
		}

		/**
		 * SHA-256 digest of a cache key (hex, lowercase, 64 chars).
		 *
		 * The original key is never normalized; `(string) $key` is hashed after
		 * WordPress validation so case and punctuation are preserved and Redis
		 * key-length concerns are eliminated.
		 *
		 * @param mixed $key The validated cache key.
		 * @return string
		 */
		public function key_digest( $key): string {
			return hash( 'sha256', (string) $key );
		}

		/**
		 * The item scope identifier for a group.
		 *
		 * Global groups share a network scope; non-global groups use the current
		 * blog ID under multisite and a stable single-site marker otherwise.
		 *
		 * @param string $group Normalized group name.
		 * @return string
		 */
		public function scope_for( string $group): string {
			if (isset( $this->global_groups[ $group ] )) {
				return self::GLOBAL_SCOPE;
			}

			if ($this->multisite) {
				return 'b' . rtrim( $this->blog_prefix, ':' );
			}

			return self::SINGLE_SITE_SCOPE;
		}

		/**
		 * The control key holding the random namespace generation token.
		 *
		 * @return string
		 */
		public function namespace_control_key(): string {
			return self::SCHEMA_MARKER . ':' . $this->namespace_digest . ':' . self::NS_TOKEN_MARKER;
		}

		/**
		 * The control key holding the random per-group generation token.
		 *
		 * Group-token identity deliberately excludes blog scope so a standard
		 * `flush_group` invalidates the group across the installation/network,
		 * while item keys remain blog/global scoped.
		 *
		 * @param string $group Normalized group name.
		 * @return string
		 */
		public function group_control_key( string $group): string {
			return self::SCHEMA_MARKER . ':' . $this->namespace_digest . ':g:' . $this->group_digest( $group ) . ':' . self::GROUP_TOKEN_MARKER;
		}

		/**
		 * The item key for a cached value.
		 *
		 * Carries both generation tokens so a change to either invalidates the
		 * item, plus scope and key digest for full logical identity.
		 *
		 * @param string $ns_token    Random namespace token (32 hex chars).
		 * @param string $group_token Random group token (32 hex chars).
		 * @param string $group       Normalized group name.
		 * @param mixed  $key         The validated cache key.
		 * @return string
		 */
		public function item_key( string $ns_token, string $group_token, string $group, $key): string {
			return self::SCHEMA_MARKER
				. ':' . $this->namespace_digest
				. ':' . self::ITEM_MARKER
				. ':' . $ns_token
				. ':' . $this->group_digest( $group )
				. ':' . $group_token
				. ':' . $this->scope_for( $group )
				. ':' . $this->key_digest( $key );
		}

		/**
		 * Generates a cryptographically secure random 128-bit hex token.
		 *
		 * @return string 32 lowercase hex characters.
		 * @throws \RuntimeException On CSPRNG failure.
		 */
		public static function generate_token(): string {
			$bytes = random_bytes( 16 );

			return bin2hex( $bytes );
		}

		/**
		 * Builds the `_doing_it_wrong` caller label for an invalid key.
		 *
		 * Uses a bounded backtrace to identify the public method that invoked
		 * validation, mirroring core's `Class::method` format. The caller's own
		 * class is used so a split between the public cache class and this
		 * component still reports the user-facing class.
		 *
		 * @return string
		 */
		private function invalid_key_caller(): string {
			$frame = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 3 );

			// The deepest frame is this helper, the next is is_valid_key, and the
			// shallowest caller is the public method that triggered validation.
			$method = isset( $frame[2]['function'] ) ? $frame[2]['function'] : 'unknown';
			$class  = isset( $frame[2]['class'] ) ? $frame[2]['class'] : __CLASS__;

			return sprintf( '%s::%s', $class, $method );
		}
	}

	// --- Lifecycle.php ---
	/**
	 * Manages drop-in installation, update, deactivation, and ownership state.
	 */
	final class Lifecycle {

		/** Drop-in state constants. */
		public const STATE_ABSENT           = 'absent';
		public const STATE_OWNED_CURRENT    = 'owned-current';
		public const STATE_OWNED_STALE      = 'owned-stale';
		public const STATE_FOREIGN          = 'foreign';
		public const STATE_INVALID_READABLE = 'invalid/unreadable';

		/**
		 * Determines the current ownership state of the drop-in.
		 *
		 * @return string One of the STATE_* constants.
		 */
		public static function get_dropin_state(): string {
			$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			$target     = $target_dir . '/object-cache.php';

			if ( ! file_exists( $target ) ) {
				return self::STATE_ABSENT;
			}

			if ( ! is_readable( $target ) ) {
				return self::STATE_INVALID_READABLE;
			}

			$target_markers = self::parse_markers( $target );

			if ( $target_markers['owner'] !== 'mincemeat-object-cache' ) {
				return self::STATE_FOREIGN;
			}

			$source = dirname( __DIR__ ) . '/stubs/object-cache.php';
			if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
				// If source stub is missing (should not happen), treat target as stale to encourage rebuild/re-install.
				return self::STATE_OWNED_STALE;
			}

			$source_markers = self::parse_markers( $source );

			if ( $target_markers['build_hash'] === $source_markers['build_hash'] && $target_markers['version'] === $source_markers['version'] ) {
				return self::STATE_OWNED_CURRENT;
			}

			return self::STATE_OWNED_STALE;
		}

		/**
		 * Parses machine-readable markers from a drop-in file header.
		 *
		 * @param string $path Absolute path to the drop-in file.
		 * @return array{owner:string|null,version:string|null,dropin_version:string|null,schema_version:string|null,build_hash:string|null}
		 */
		public static function parse_markers( string $path ): array {
			$default = array(
				'owner'          => null,
				'version'        => null,
				'dropin_version' => null,
				'schema_version' => null,
				'build_hash'     => null,
			);

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return $default;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = @file_get_contents( $path, false, null, 0, 8192 );
			if ( $content === false ) {
				return $default;
			}

			$owner          = null;
			$version        = null;
			$dropin_version = null;
			$schema_version = null;
			$build_hash     = null;

			if ( preg_match( '/Owner:\s*([^\s\r\n]+)/i', $content, $m ) ) {
				$owner = trim( $m[1] );
			}
			if ( preg_match( '/Version:\s*([^\s\r\n]+)/i', $content, $m ) ) {
				$version = trim( $m[1] );
			}
			if ( preg_match( '/Drop-in Version:\s*([^\s\r\n]+)/i', $content, $m ) ) {
				$dropin_version = trim( $m[1] );
			}
			if ( preg_match( '/Schema Version:\s*([^\s\r\n]+)/i', $content, $m ) ) {
				$schema_version = trim( $m[1] );
			}
			if ( preg_match( '/Build Hash:\s*([^\s\r\n]+)/i', $content, $m ) ) {
				$build_hash = trim( $m[1] );
			}

			return array(
				'owner'          => $owner,
				'version'        => $version,
				'dropin_version' => $dropin_version,
				'schema_version' => $schema_version,
				'build_hash'     => $build_hash,
			);
		}

		/**
		 * Checks if direct safe filesystem writes are possible.
		 *
		 * Respects DISALLOW_FILE_MODS and target directory/file writability.
		 *
		 * @return bool True if direct modification is allowed and possible.
		 */
		public static function has_direct_access(): bool {
			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				return false;
			}

			$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			if ( ! is_writable( $target_dir ) ) {
				return false;
			}

			$target = $target_dir . '/object-cache.php';
			if ( file_exists( $target ) && ! is_writable( $target ) ) {
				return false;
			}

			return true;
		}

		/**
		 * Installs or updates the drop-in safely and atomically.
		 *
		 * @return bool True on success, false on failure.
		 */
		public static function install_dropin(): bool {
			if ( ! self::has_direct_access() ) {
				return false;
			}

			$source     = dirname( __DIR__ ) . '/stubs/object-cache.php';
			$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			$target     = $target_dir . '/object-cache.php';

			if ( ! file_exists( $source ) || ! is_readable( $source ) ) {
				return false;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $source );
			if ( $content === false ) {
				return false;
			}

			$source_markers = self::parse_markers( $source );
			if ( empty( $source_markers['build_hash'] ) ) {
				return false;
			}

			// Perform same-directory temporary write.
			$temp_path = $target_dir . '/object-cache.tmp.' . bin2hex( random_bytes( 8 ) ) . '.php';

			// phpcs:ignore
			if ( file_put_contents( $temp_path, $content ) === false ) {
				return false;
			}

			// Read back and validate the temp file.
			$temp_markers = self::parse_markers( $temp_path );
			if ( empty( $temp_markers['build_hash'] ) || $temp_markers['build_hash'] !== $source_markers['build_hash'] ) {
				@unlink( $temp_path );
				return false;
			}

			// Set appropriate permissions.
			$perms = 0644;
			if ( file_exists( $target ) ) {
				$perms = fileperms( $target ) & 0777;
			} elseif ( defined( 'FS_CHMOD_FILE' ) ) {
				$perms = FS_CHMOD_FILE;
			}

			@chmod( $temp_path, $perms );

			// Atomic rename.
			if ( ! @rename( $temp_path, $target ) ) {
				@unlink( $temp_path );
				return false;
			}

			return true;
		}

		/**
		 * Removes the drop-in if positively owned by the plugin.
		 *
		 * @return bool True on success or if absent; false on failure.
		 */
		public static function remove_dropin(): bool {
			$target_dir = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			$target     = $target_dir . '/object-cache.php';

			if ( ! file_exists( $target ) ) {
				return true;
			}

			$state = self::get_dropin_state();
			if ( $state !== self::STATE_OWNED_CURRENT && $state !== self::STATE_OWNED_STALE ) {
				return false;
			}

			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				return false;
			}

			return @unlink( $target );
		}

		/**
		 * Activation hook callback.
		 */
		public static function activate(): void {
			if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
				return;
			}

			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				set_transient( 'mincemeat_object_cache_activation_notice', 'disallowed', 45 );
				return;
			}

			$state = self::get_dropin_state();

			if ( $state === self::STATE_OWNED_CURRENT ) {
				return;
			}

			if ( $state === self::STATE_FOREIGN ) {
				set_transient( 'mincemeat_object_cache_activation_notice', 'foreign', 45 );
				return;
			}

			if ( ! self::has_direct_access() ) {
				set_transient( 'mincemeat_object_cache_activation_notice', 'not_writable', 45 );
				return;
			}

			$installed = self::install_dropin();
			if ( ! $installed ) {
				set_transient( 'mincemeat_object_cache_activation_notice', 'failed', 45 );
			}
		}

		/**
		 * Deactivation hook callback.
		 */
		public static function deactivate(): void {
			if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
				return;
			}

			if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
				return;
			}

			$state = self::get_dropin_state();

			if ( $state === self::STATE_OWNED_CURRENT || $state === self::STATE_OWNED_STALE ) {
				if ( ! self::has_direct_access() ) {
					set_transient( 'mincemeat_object_cache_deactivate_fail', true, 45 );
					return;
				}

				$removed = self::remove_dropin();
				if ( ! $removed ) {
					set_transient( 'mincemeat_object_cache_deactivate_fail', true, 45 );
				}
			}
		}

		/**
		 * Renders notices in the admin panel.
		 */
		public static function admin_notices(): void {
			if ( ! current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
				return;
			}

			$activation_notice = get_transient( 'mincemeat_object_cache_activation_notice' );
			if ( $activation_notice !== false ) {
				delete_transient( 'mincemeat_object_cache_activation_notice' );
				$class   = 'notice notice-error is-dismissible';
				$message = '';

				switch ( $activation_notice ) {
					case 'disallowed':
						$message = __( 'Mincemeat Object Cache could not be installed because file modifications are disabled (DISALLOW_FILE_MODS). Please install stubs/object-cache.php manually as wp-content/object-cache.php.', 'mincemeat-object-cache' );
						break;
					case 'foreign':
						$message = __( 'Mincemeat Object Cache could not be installed because a foreign or conflicting object-cache.php drop-in is already present in wp-content/. Please remove it first.', 'mincemeat-object-cache' );
						break;
					case 'not_writable':
						$message = __( 'Mincemeat Object Cache could not be installed because the wp-content/ directory is not writable. Please check permissions or copy stubs/object-cache.php manually.', 'mincemeat-object-cache' );
						break;
					default:
						$message = __( 'Mincemeat Object Cache could not be installed due to a filesystem error. Please check permissions.', 'mincemeat-object-cache' );
						break;
				}

				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			}

			$deactivate_fail = get_transient( 'mincemeat_object_cache_deactivate_fail' );
			if ( $deactivate_fail !== false ) {
				delete_transient( 'mincemeat_object_cache_deactivate_fail' );
				$class   = 'notice notice-warning is-dismissible';
				$message = __( 'Mincemeat Object Cache was deactivated, but the wp-content/object-cache.php drop-in could not be removed automatically due to permissions. Please remove it manually to prevent it from running.', 'mincemeat-object-cache' );

				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			}

			$state = self::get_dropin_state();
			if ( $state === self::STATE_OWNED_STALE ) {
				$class   = 'notice notice-warning';
				$message = __( 'The Mincemeat Object Cache drop-in is outdated. Please update it using WP-CLI (wp mincemeat-cache update-dropin) or by re-activating the plugin.', 'mincemeat-object-cache' );
				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			} elseif ( $state === self::STATE_FOREIGN ) {
				$class   = 'notice notice-error';
				$message = __( 'A foreign object-cache.php drop-in is present in wp-content/. Mincemeat Object Cache is inactive.', 'mincemeat-object-cache' );
				printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
			}
		}
	}

	// --- LuaScripts.php ---
	/**
	 * Holds the Lua source and result-code constants for atomic operations.
	 *
	 * @internal
	 */
	final class LuaScripts {

		/** Successful update; the new integer value follows this code. */
		public const RESULT_OK = 'OK';

		/** The key does not exist in the backend. */
		public const RESULT_MISSING = 'MISSING';

		/** The key exists but the value is not a supported numeric envelope. */
		public const RESULT_INVALID = 'INVALID';

		/** The envelope is corrupt or has an unknown version/tag. */
		public const RESULT_CORRUPT = 'CORRUPT';

		/**
		 * The increment/decrement Lua script.
		 *
		 * KEYS[1] = the item key.
		 * ARGV[1] = the signed integer offset (string, e.g. "1" or "-5").
		 *
		 * Returns:
		 *   {RESULT_OK,    <new_value>}  on success.
		 *   {RESULT_MISSING}             when the key is absent.
		 *   {RESULT_INVALID}             when the value is not an integer envelope.
		 *   {RESULT_CORRUPT}             when the envelope is malformed.
		 *
		 * The script uses only Redis/Valkey-compatible Lua and string/TTL
		 * commands: GET, PTTL, SET, UNLINK. It does not use KEYS, SCAN, or
		 * FLUSHDB. It preserves the existing PTTL (including no-expiry = -1)
		 * and never resets it.
		 */
		public const INCR_DECR = <<<'LUA'
	local key = KEYS[1]
	local offset = tonumber(ARGV[1])

	local raw = redis.call('GET', key)
	if raw == false then
	    return {'MISSING'}
	end

	-- Validate minimum length (4 magic + 1 version + 1 tag + 4 length = 10).
	if string.len(raw) < 10 then
	    return {'CORRUPT'}
	end

	-- Validate magic.
	if string.sub(raw, 1, 4) ~= 'MCOC' then
	    return {'CORRUPT'}
	end

	-- Validate version.
	local ver = string.byte(raw, 5)
	if ver ~= 1 then
	    return {'CORRUPT'}
	end

	-- Validate tag is integer (tag 1).
	local tag = string.byte(raw, 6)
	if tag ~= 1 then
	    return {'INVALID'}
	end

	-- Read the 4-byte big-endian length.
	local b1 = string.byte(raw, 7)
	local b2 = string.byte(raw, 8)
	local b3 = string.byte(raw, 9)
	local b4 = string.byte(raw, 10)
	local length = b1 * 16777216 + b2 * 65536 + b3 * 256 + b4

	-- Extract and validate the payload.
	local payload = string.sub(raw, 11)
	if string.len(payload) ~= length then
	    return {'CORRUPT'}
	end

	if length == 0 then
	    return {'CORRUPT'}
	end

	-- Validate the payload is a decimal integer string.
	local abs_str = payload
	if string.sub(payload, 1, 1) == '-' then
	    abs_str = string.sub(payload, 2)
	end

	if abs_str == '' then
	    return {'CORRUPT'}
	end

	-- Check every byte of abs_str is a digit.
	local valid = true
	for i = 1, string.len(abs_str) do
	    local c = string.byte(abs_str, i)
	    if c < 48 or c > 57 then
	        valid = false
	        break
	    end
	end
	if not valid then
	    return {'CORRUPT'}
	end

	-- Apply the offset.
	local current = tonumber(payload)
	local new_value = current + offset

	-- Clamp at zero for decrements (WordPress behavior).
	if new_value < 0 then
	    new_value = 0
	end

	-- Re-encode the integer envelope: magic + version + tag + length + payload.
	local new_payload = tostring(new_value)
	local new_len = string.len(new_payload)
	local new_raw = 'MCOC'
	    .. string.char(1)
	    .. string.char(1)
	    .. string.char(math.floor(new_len / 16777216) % 256)
	    .. string.char(math.floor(new_len / 65536) % 256)
	    .. string.char(math.floor(new_len / 256) % 256)
	    .. string.char(new_len % 256)
	    .. new_payload

	-- Preserve the existing PTTL (including no-expiry = -1).
	local pttl = redis.call('PTTL', key)
	if pttl == -1 then
	    redis.call('SET', key, new_raw)
	elseif pttl > 0 then
	    redis.call('SET', key, new_raw, 'PX', pttl)
	else
	    return {'MISSING'}
	end

	return {'OK', tostring(new_value)}
	LUA;

		private function __construct() {
		}
	}

	// --- ObjectCache.php ---
	/**
	 * Object cache with a request-local memory tier and optional persistent backend.
	 *
	 * @internal The public interoperability surface is the `wp_cache_*` functions
	 *           and `Mincemeat\ObjectCache\API`. Do not rely on these internals.
	 */
	final class ObjectCache {

		/** Cache states. */
		public const STATE_PERSISTENT   = 'persistent';
		public const STATE_RUNTIME_ONLY = 'runtime-only';
		public const STATE_DEGRADED     = 'degraded';

		/**
		 * Request-local cache, keyed by normalized group then storage identifier.
		 *
		 * @var array<string,array<string,mixed>>
		 */
		private $cache = array();

		/**
		 * Registered non-persistent groups, keyed by name for O(1) lookup.
		 *
		 * @var array<string,true>
		 */
		private $non_persistent_groups = array();

		/**
		 * @var KeySpace
		 */
		private $key_space;

		/**
		 * @var Backend|null
		 */
		private $backend;

		/**
		 * Total cache hits this request.
		 *
		 * @var int
		 */
		private $hits = 0;

		/**
		 * Total cache misses this request.
		 *
		 * @var int
		 */
		private $misses = 0;

		/**
		 * Number of backend commands issued this request (zero in runtime-only).
		 *
		 * @var int
		 */
		private $backend_calls = 0;

		/**
		 * Total time spent in backend commands, in microseconds.
		 *
		 * @var float
		 */
		private $backend_time = 0.0;

		/**
		 * Number of backend errors recorded this request.
		 *
		 * @var int
		 */
		private $errors = 0;

		/**
		 * Current cache state (when no backend is attached, remains runtime-only).
		 *
		 * @var string
		 */
		private $state = self::STATE_RUNTIME_ONLY;

		/**
		 * Stable reason code for the current state.
		 *
		 * @var string
		 */
		private $reason = 'no-backend';

		/**
		 * Constructor.
		 *
		 * @param KeySpace|null $key_space Optional injected key space (for testing).
		 * @param Backend|null  $backend   Optional injected backend (for testing).
		 */
		public function __construct( ?KeySpace $key_space = null, ?Backend $backend = null) {
			$multisite = function_exists( 'is_multisite' ) ? is_multisite() : false;
			$blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

			$this->key_space = $key_space ?? new KeySpace( $multisite, $blog_id );

			if ($backend !== null) {
				$this->backend = $backend;
				$this->state   = $backend->state();
				$this->reason  = $backend->reason();
			}
		}

		/**
		 * Attaches a backend for persistent operations.
		 *
		 * @param Backend $backend
		 */
		public function attach_backend( Backend $backend): void {
			$this->backend = $backend;
			$this->state   = $backend->state();
			$this->reason  = $backend->reason();
		}

		/**
		 * Adds data to the cache only if it does not already exist.
		 *
		 * @param mixed  $key    The cache key.
		 * @param mixed  $data   The data to add.
		 * @param string $group  Optional. The cache group. Default empty ('default').
		 * @param int    $expire Optional. TTL in seconds.
		 * @return bool True on success, false if it already exists or addition is suspended.
		 */
		public function add( $key, $data, string $group = '', int $expire = 0): bool {
			if ($this->is_addition_suspended()) {
				return false;
			}

			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			if ($this->exists( $storage_id, $group )) {
				return false;
			}

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_add( $key, $data, $group, $expire, $storage_id );
			}

			return $this->set_in_memory( $storage_id, $group, $data );
		}

		/**
		 * Adds multiple values in one call.
		 *
		 * @param array  $data   Key/value pairs to add.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds.
		 * @return bool[] Per-key results.
		 */
		public function add_multiple( array $data, string $group = '', int $expire = 0): array {
			$values = array();

			foreach ($data as $key => $value) {
				$values[ $key ] = $this->add( $key, $value, $group, $expire );
			}

			return $values;
		}

		/**
		 * Replaces the contents of an existing cache item.
		 *
		 * @param mixed  $key    The cache key.
		 * @param mixed  $data   The new data.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds.
		 * @return bool True on success, false if the item does not exist.
		 */
		public function replace( $key, $data, string $group = '', int $expire = 0): bool {
			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_replace( $key, $data, $group, $expire, $storage_id );
			}

			if ( ! $this->exists( $storage_id, $group )) {
				return false;
			}

			return $this->set_in_memory( $storage_id, $group, $data );
		}

		/**
		 * Stores data in the cache, overwriting any existing value.
		 *
		 * @param mixed  $key    The cache key.
		 * @param mixed  $data   The data to store.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds.
		 * @return bool True on success, false if the key is invalid.
		 */
		public function set( $key, $data, string $group = '', int $expire = 0): bool {
			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_set( $key, $data, $group, $expire, $storage_id );
			}

			return $this->set_in_memory( $storage_id, $group, $data );
		}

		/**
		 * Stores multiple values in one call.
		 *
		 * @param array  $data   Key/value pairs to store.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds.
		 * @return bool[] Per-key results.
		 */
		public function set_multiple( array $data, string $group = '', int $expire = 0): array {
			$values = array();

			foreach ($data as $key => $value) {
				$values[ $key ] = $this->set( $key, $value, $group, $expire );
			}

			return $values;
		}

		/**
		 * Retrieves a cached value.
		 *
		 * @param mixed       $key   The cache key.
		 * @param string      $group Optional. The cache group. Default empty.
		 * @param bool        $force Optional. Force a read past the runtime tier.
		 * @param bool|null   $found Optional. Whether the key was found (reference).
		 * @return mixed|false The cached value on hit, false on miss or invalid key.
		 */
		public function get( $key, string $group = '', bool $force = false, &$found = null) {
			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			$should_force = $force && $this->is_persistent_group( $group );

			if ( ! $should_force && $this->exists( $storage_id, $group )) {
				$found       = true;
				$this->hits += 1;
				$value       = $this->cache[ $group ][ $storage_id ];

				return is_object( $value ) ? clone $value : $value;
			}

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_get( $key, $group, $force, $found, $storage_id );
			}

			$found         = false;
			$this->misses += 1;

			return false;
		}

		/**
		 * Retrieves multiple values in one call.
		 *
		 * @param array  $keys  The cache keys.
		 * @param string $group Optional. The cache group. Default empty.
		 * @param bool   $force Optional. Force reads past the runtime tier.
		 * @return array<string,mixed> Per-key values; misses are false.
		 */
		public function get_multiple( array $keys, string $group = '', bool $force = false): array {
			$group = $this->key_space->normalize_group( $group );

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_get_multiple( $keys, $group, $force );
			}

			$values = array();

			foreach ($keys as $key) {
				$values[ $key ] = $this->get( $key, $group, $force );
			}

			return $values;
		}

		/**
		 * Deletes a cache item.
		 *
		 * @param mixed  $key   The cache key.
		 * @param string $group Optional. The cache group. Default empty.
		 * @return bool True on success, false if absent or invalid key.
		 */
		public function delete( $key, string $group = ''): bool {
			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_delete( $key, $group, $storage_id );
			}

			if ( ! $this->exists( $storage_id, $group )) {
				return false;
			}

			unset( $this->cache[ $group ][ $storage_id ] );

			return true;
		}

		/**
		 * Deletes multiple values in one call.
		 *
		 * @param array  $keys  The cache keys.
		 * @param string $group Optional. The cache group. Default empty.
		 * @return bool[] Per-key results.
		 */
		public function delete_multiple( array $keys, string $group = ''): array {
			$values = array();

			foreach ($keys as $key) {
				$values[ $key ] = $this->delete( $key, $group );
			}

			return $values;
		}

		/**
		 * Increments a numeric cache item.
		 *
		 * @param mixed  $key    The cache key.
		 * @param int    $offset Optional. The increment amount. Default 1.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @return int|false The new value on success, false on miss or invalid key.
		 */
		public function incr( $key, int $offset = 1, string $group = '') {
			return $this->delta( $key, $offset, $group );
		}

		/**
		 * Decrements a numeric cache item, clamped at zero.
		 *
		 * @param mixed  $key    The cache key.
		 * @param int    $offset Optional. The decrement amount. Default 1.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @return int|false The new value on success, false on miss or invalid key.
		 */
		public function decr( $key, int $offset = 1, string $group = '') {
			return $this->delta( $key, -$offset, $group );
		}

		/**
		 * Clears the entire cache: replaces the namespace token and clears memory.
		 *
		 * @return bool True on success.
		 */
		public function flush(): bool {
			if ($this->backend !== null && $this->backend->is_persistent()) {
				$this->backend_calls += 1;
				$start                = microtime( true );
				$ok                   = $this->backend->replace_namespace_token();
				$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

				if ($this->sync_state()) {
					$this->cache = array();

					if ($ok && function_exists( 'do_action' )) {
						do_action( 'mincemeat_object_cache_flushed' );
					}

					return $ok;
				}
			}

			$this->cache = array();

			return true;
		}

		/**
		 * Clears only the request-local cache, never affecting the backend.
		 *
		 * @return bool True on success.
		 */
		public function flush_runtime(): bool {
			$this->cache = array();

			return true;
		}

		/**
		 * Removes all items in a group by replacing the group's generation token.
		 *
		 * @param string $group The group name to flush.
		 * @return bool True on success.
		 */
		public function flush_group( string $group): bool {
			if ('' === $group) {
				$group = 'default';
			}

			if ($this->backend !== null && $this->backend->is_persistent()) {
				$this->backend_calls += 1;
				$start                = microtime( true );
				$ok                   = $this->backend->replace_group_token( $group );
				$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

				if ($this->sync_state()) {
					unset( $this->cache[ $group ] );

					if ($ok && function_exists( 'do_action' )) {
						do_action( 'mincemeat_object_cache_group_flushed', $group );
					}

					return $ok;
				}
			}

			unset( $this->cache[ $group ] );

			return true;
		}

		/**
		 * Registers global groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 */
		public function add_global_groups( $groups): void {
			$this->key_space->add_global_groups( $groups );
		}

		/**
		 * Registers non-persistent groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 */
		public function add_non_persistent_groups( $groups): void {
			$groups = (array) $groups;

			foreach ($groups as $group) {
				$this->non_persistent_groups[ (string) $group ] = true;
			}
		}

		/**
		 * Whether a group is registered as non-persistent.
		 *
		 * @param string $group Normalized group name.
		 * @return bool
		 */
		public function is_non_persistent_group( string $group): bool {
			return isset( $this->non_persistent_groups[ $group ] );
		}

		/**
		 * Switches the active blog scope.
		 *
		 * @param int $blog_id The blog ID to switch to.
		 */
		public function switch_to_blog( int $blog_id): void {
			$this->key_space->switch_to_blog( $blog_id );
		}

		/**
		 * Resets cache keys (deprecated). Clears non-global groups.
		 *
		 * @deprecated Use switch_to_blog().
		 */
		public function reset(): void {
			if (function_exists( '_deprecated_function' )) {
				_deprecated_function( __METHOD__, '1.0.0', __CLASS__ . '::switch_to_blog()' );
			}

			foreach (array_keys( $this->cache ) as $group) {
				if ( ! $this->key_space->is_global_group( $group )) {
					unset( $this->cache[ $group ] );
				}
			}
		}

		/**
		 * Closes the cache and the backend connection.
		 *
		 * @return bool True.
		 */
		public function close(): bool {
			if ($this->backend !== null) {
				$this->backend->close();
			}

			return true;
		}

		// ------------------------------------------------------------------
		// Accessors
		// ------------------------------------------------------------------

		public function state(): string {
			if ($this->backend !== null) {
				$this->sync_state();
			}

			$this->state = $this->state;
			return $this->state;
		}

		public function reason(): string {
			if ($this->backend !== null) {
				$this->sync_state();
			}

			return $this->reason;
		}

		public function last_error(): string {
			return $this->backend !== null ? $this->backend->last_error() : '';
		}

		public function hits(): int {
			return $this->hits;
		}

		public function misses(): int {
			return $this->misses;
		}

		public function backend_calls(): int {
			return $this->backend_calls;
		}

		public function backend_time(): float {
			return $this->backend_time;
		}

		public function errors(): int {
			return $this->errors;
		}

		public function key_space(): KeySpace {
			return $this->key_space;
		}

		/**
		 * Sanitized server identity, or null.
		 *
		 * @return array<string,string>|null
		 */
		public function server_info(): ?array {
			return $this->backend !== null ? $this->backend->server_info() : null;
		}

		/**
		 * Returns the configured maximum TTL in seconds.
		 *
		 * @return int
		 */
		public function max_ttl(): int {
			return $this->backend !== null ? $this->backend->max_ttl() : 0;
		}

		/**
		 * Returns the configured connection scheme.
		 *
		 * @return string
		 */
		public function scheme(): string {
			return $this->backend !== null ? $this->backend->scheme() : 'none';
		}

		/**
		 * Returns the configuration instance from the backend, or null.
		 *
		 * @return Config|null
		 */
		public function config(): ?Config {
			return $this->backend !== null ? $this->backend->config() : null;
		}

		/**
		 * Returns the registered non-persistent groups.
		 *
		 * @return array<string,bool>
		 */
		public function non_persistent_groups(): array {
			return $this->non_persistent_groups;
		}

		// ------------------------------------------------------------------
		// Internals: memory tier
		// ------------------------------------------------------------------

		/**
		 * Whether a storage identifier exists in a group.
		 */
		private function exists( string $storage_id, string $group): bool {
			return isset( $this->cache[ $group ] )
				&& ( isset( $this->cache[ $group ][ $storage_id ] ) || array_key_exists( $storage_id, $this->cache[ $group ] ) );
		}

		/**
		 * Whether cache addition is suspended.
		 */
		private function is_addition_suspended(): bool {
			return function_exists( 'wp_suspend_cache_addition' )
				&& (bool) wp_suspend_cache_addition();
		}

		/**
		 * Stores a value in request memory, cloning objects.
		 */
		private function set_in_memory( string $storage_id, string $group, $data): bool {
			if (is_object( $data )) {
				$data = clone $data;
			}

			$this->cache[ $group ][ $storage_id ] = $data;

			return true;
		}

		/**
		 * Whether a group should use the persistent backend.
		 *
		 * @param string $group Normalized group name.
		 * @return bool
		 */
		private function is_persistent_group( string $group): bool {
			return $this->backend !== null
				&& $this->backend->is_persistent()
				&& ! isset( $this->non_persistent_groups[ $group ] );
		}

		/**
		 * Syncs state/reason from the backend (circuit breaker tracking).
		 *
		 * @return bool True if still persistent.
		 */
		private function sync_state(): bool {
			if ($this->backend === null) {
				return false;
			}

			$this->state  = $this->backend->state();
			$this->reason = $this->backend->reason();

			if ($this->backend->state() !== self::STATE_PERSISTENT) {
				$this->errors += 1;
			}

			return $this->backend->is_persistent();
		}

		/**
		 * Resolves the TTL in milliseconds for a write.
		 *
		 * @param int $caller_expire
		 * @return int|null
		 */
		private function resolve_ttl_ms( int $caller_expire): ?int {
			$max_ttl = $this->backend !== null ? $this->backend->max_ttl() : 0;
			$seconds = Ttl::resolve( $caller_expire, $max_ttl );

			return Ttl::to_ms( $seconds );
		}

		// ------------------------------------------------------------------
		// Internals: persistent operations
		// ------------------------------------------------------------------

		/**
		 * Persistent GET: reads from backend, populates memory, sets $found.
		 */
		private function persistent_get( $key, string $group, bool $force, &$found, string $storage_id) {
			$ns_tok   = $this->backend->namespace_token();
			$grp_tok  = $this->backend->group_token( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$raw                  = $this->backend->get( $item_key );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				return $this->runtime_fallback_get( $storage_id, $group, $found );
			}

			if (is_string( $raw ) && $raw !== '') {
				list($ok, $value, $err) = ValueCodec::decode( $raw );
				if ($ok) {
					$found       = true;
					$this->hits += 1;
					$this->set_in_memory( $storage_id, $group, $value );

					return is_object( $value ) ? clone $value : $value;
				}

				if ($err !== null) {
					$this->backend->log_error( 'Value codec decode failed: ' . $err );
					$this->backend->del( $item_key );
				}
			}

			// Backend miss or corrupt: treat as miss, remove stale memory entry.
			unset( $this->cache[ $group ][ $storage_id ] );
			$found         = false;
			$this->misses += 1;

			return false;
		}

		/**
		 * Persistent GET_MULTIPLE: serves from memory, MGETs missing keys.
		 */
		private function persistent_get_multiple( array $keys, string $group, bool $force): array {
			$values   = array();
			$missing  = array();
			$miss_ids = array();

			foreach ($keys as $key) {
				if ( ! $this->key_space->is_valid_key( $key )) {
					$values[ $key ] = false;
					continue;
				}

				$storage_id = $this->key_space->storage_id( $key, $group );

				if ( ! $force && $this->exists( $storage_id, $group )) {
					$this->hits    += 1;
					$val            = $this->cache[ $group ][ $storage_id ];
					$values[ $key ] = is_object( $val ) ? clone $val : $val;
				} else {
					$missing[]        = $key;
					$miss_ids[ $key ] = $storage_id;
					$values[ $key ]   = false;
				}
			}

			if (count( $missing ) === 0) {
				return $values;
			}

			$ns_tok  = $this->backend->namespace_token();
			$grp_tok = $this->backend->group_token( $group );

			$backend_keys = array();
			foreach ($missing as $key) {
				$backend_keys[] = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			}

			$this->backend_calls += 1;
			$start                = microtime( true );
			$raw_values           = $this->backend->mget( $backend_keys );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				foreach ($missing as $key) {
					$storage_id_d = $this->key_space->storage_id( $key, $group );
					if ($this->exists( $storage_id_d, $group )) {
						$values[ $key ] = $this->cache[ $group ][ $storage_id_d ];
						$this->hits    += 1;
					} else {
						$this->misses += 1;
					}
				}

				return $values;
			}

			foreach ($missing as $i => $key) {
				$raw = $raw_values[ $i ] ?? false;

				if (is_string( $raw ) && $raw !== '') {
					list($ok, $val, $err) = ValueCodec::decode( $raw );
					if ($ok) {
						$this->hits += 1;
						$this->set_in_memory( $miss_ids[ $key ], $group, $val );
						$values[ $key ] = is_object( $val ) ? clone $val : $val;
						continue;
					}

					if ($err !== null) {
						$this->backend->log_error( 'Value codec decode failed: ' . $err );
						$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
						$this->backend->del( $item_key );
					}
				}

				$this->misses  += 1;
				$values[ $key ] = false;
			}

			return $values;
		}

		/**
		 * Persistent SET: encodes and writes to backend, updates memory on success.
		 */
		private function persistent_set( $key, $data, string $group, int $expire, string $storage_id): bool {
			try {
				$encoded = ValueCodec::encode( $data );
			} catch (ValueCodecException $e) {
				return false;
			}

			$ns_tok   = $this->backend->namespace_token();
			$grp_tok  = $this->backend->group_token( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			$ttl_ms   = $this->resolve_ttl_ms( $expire );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$ok                   = $this->backend->set_unconditional( $item_key, $encoded, $ttl_ms );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			if ($ok) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			return false;
		}

		/**
		 * Persistent ADD: atomic SET NX, update memory only on success.
		 */
		private function persistent_add( $key, $data, string $group, int $expire, string $storage_id): bool {
			try {
				$encoded = ValueCodec::encode( $data );
			} catch (ValueCodecException $e) {
				return false;
			}

			$ns_tok   = $this->backend->namespace_token();
			$grp_tok  = $this->backend->group_token( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			$ttl_ms   = $this->resolve_ttl_ms( $expire );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$ok                   = $this->backend->set( $item_key, $encoded, $ttl_ms, true, false );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			if ($ok) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			// Key exists in backend; read back to populate memory.
			$raw = $this->backend->get( $item_key );
			if (is_string( $raw ) && $raw !== '') {
				list($ok2, $val) = ValueCodec::decode( $raw );
				if ($ok2) {
					$this->set_in_memory( $storage_id, $group, $val );
				}
			}

			return false;
		}

		/**
		 * Persistent REPLACE: atomic SET XX, update memory only on success.
		 */
		private function persistent_replace( $key, $data, string $group, int $expire, string $storage_id): bool {
			try {
				$encoded = ValueCodec::encode( $data );
			} catch (ValueCodecException $e) {
				return false;
			}

			$ns_tok   = $this->backend->namespace_token();
			$grp_tok  = $this->backend->group_token( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			$ttl_ms   = $this->resolve_ttl_ms( $expire );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$ok                   = $this->backend->set( $item_key, $encoded, $ttl_ms, false, true );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				if ($this->exists( $storage_id, $group )) {
					return $this->set_in_memory( $storage_id, $group, $data );
				}

				return false;
			}

			if ($ok) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			return false;
		}

		/**
		 * Persistent DELETE: DEL from backend, then memory.
		 */
		private function persistent_delete( $key, string $group, string $storage_id): bool {
			$ns_tok   = $this->backend->namespace_token();
			$grp_tok  = $this->backend->group_token( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$deleted              = $this->backend->del( $item_key );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			$was_in_memory = $this->exists( $storage_id, $group );

			if ( ! $this->sync_state()) {
				if ($was_in_memory) {
					unset( $this->cache[ $group ][ $storage_id ] );

					return true;
				}

				return false;
			}

			unset( $this->cache[ $group ][ $storage_id ] );

			return $deleted > 0 || $was_in_memory;
		}

		/**
		 * Runtime-only GET fallback when the backend degrades mid-request.
		 */
		private function runtime_fallback_get( string $storage_id, string $group, &$found) {
			if ($this->exists( $storage_id, $group )) {
				$found       = true;
				$this->hits += 1;
				$value       = $this->cache[ $group ][ $storage_id ];

				return is_object( $value ) ? clone $value : $value;
			}

			$found         = false;
			$this->misses += 1;

			return false;
		}

		/**
		 * Applies a signed integer delta to a numeric item.
		 *
		 * For persistent groups, an atomic Lua script performs the update
		 * preserving the existing TTL. Request memory is updated only after
		 * confirmed script success. Transport failures are not retried because
		 * the script may already have committed.
		 *
		 * @param mixed  $key    The cache key.
		 * @param int    $offset The signed offset (negative for decrement).
		 * @param string $group  The cache group.
		 * @return int|false The new value on success, false on miss or invalid key.
		 */
		private function delta( $key, int $offset, string $group) {
			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_delta( $key, $offset, $group, $storage_id );
			}

			if ( ! $this->exists( $storage_id, $group )) {
				return false;
			}

			$current = $this->cache[ $group ][ $storage_id ];

			if ( ! is_numeric( $current )) {
				$current = 0;
			}

			$current += $offset;

			if (0 > $current) {
				$current = 0;
			}

			$this->cache[ $group ][ $storage_id ] = $current;

			return $current;
		}

		/**
		 * Persistent atomic delta via Lua script.
		 *
		 * @param mixed  $key        The cache key.
		 * @param int    $offset     Signed offset.
		 * @param string $group      Normalized group.
		 * @param string $storage_id Memory storage identifier.
		 * @return int|false
		 */
		private function persistent_delta( $key, int $offset, string $group, string $storage_id) {
			$ns_tok   = $this->backend->namespace_token();
			$grp_tok  = $this->backend->group_token( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );

			$this->backend_calls   += 1;
			$start                  = microtime( true );
			list($code, $new_value) = $this->backend->eval_incr( $item_key, $offset );
			$this->backend_time    += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				// Backend degraded: fall back to in-memory delta if the value
				// is already loaded. This preserves coherent runtime behavior.
				if ($this->exists( $storage_id, $group )) {
					$current = $this->cache[ $group ][ $storage_id ];
					if ( ! is_numeric( $current )) {
						$current = 0;
					}
					$current += $offset;
					if (0 > $current) {
						$current = 0;
					}
					$this->cache[ $group ][ $storage_id ] = $current;

					return $current;
				}

				return false;
			}

			if ($code === LuaScripts::RESULT_OK && $new_value !== null) {
				$this->cache[ $group ][ $storage_id ] = $new_value;

				return $new_value;
			}

			// Missing or invalid: remove stale memory entry.
			unset( $this->cache[ $group ][ $storage_id ] );

			return false;
		}
	}

	// --- PhpRedisAdapter.php ---
	/**
	 * Thin command adapter over the PhpRedis \Redis client.
	 *
	 * @internal
	 */
	class PhpRedisAdapter {

		/**
		 * @var Redis|null
		 */
		private $redis;

		/**
		 * Whether UNLINK is available (Redis 4.0+/Valkey). Tested once at connect.
		 *
		 * @var bool
		 */
		private $unlink_supported = false;

		/**
		 * Sanitized server identity from INFO, or null when unavailable.
		 *
		 * @var array<string,string>|null
		 */
		private $server_info;

		public function __construct() {
		}

		/**
		 * Connects to the backend per the validated config.
		 *
		 * @param Config $config
		 * @throws BackendException On connection, auth, or database-selection failure.
		 */
		public function connect( Config $config): void {
			if ( ! class_exists( Redis::class )) {
				throw new BackendException( 'missing-extension', 'The PhpRedis extension is not available.' );
			}

			$this->redis = new Redis();

			$connected     = false;
			$persistent_id = '';

			if ($config->persistent()) {
				$persistent_id = 'mcoc:' . $config->namespace_digest() . ':' . $config->database();
			}

			$params = $this->connect_params( $config );

			try {
				if ($persistent_id !== '') {
					$connected = $this->redis->pconnect(
						$params['host'],
						$params['port'],
						$config->connect_timeout(),
						$persistent_id,
						0,
						$config->read_timeout()
					);
				} else {
					$connected = $this->redis->connect(
						$params['host'],
						$params['port'],
						$config->connect_timeout(),
						null,
						0,
						$config->read_timeout()
					);
				}
			} catch (\Throwable $e) {
				throw new BackendException( 'connect-failed', 'Connection attempt failed.', 0, $e );
			}

			if ( ! $connected) {
				throw new BackendException( 'connect-failed', 'Connection attempt failed.' );
			}

			// Disable PhpRedis serializer/compressor so the plugin owns the wire format.
			$this->redis->setOption( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE );
			$this->redis->setOption( Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE );
			// No automatic prefix; key-space layout is in the KeySpace component.
			$this->redis->setOption( Redis::OPT_PREFIX, '' );

			// Auth (ACL username + password, or just password).
			if ($config->username() !== null || $config->password() !== null) {
				try {
					if ($config->username() !== null) {
						$ok = $this->redis->auth( array( $config->username(), (string) $config->password() ) );
					} else {
						$ok = $this->redis->auth( (string) $config->password() );
					}
				} catch (\Throwable $e) {
					throw new BackendException( 'auth-failed', 'Authentication failed.', 0, $e );
				}

				if ( ! $ok) {
					throw new BackendException( 'auth-failed', 'Authentication failed.' );
				}
			}

			// Database selection.
			if ($config->database() !== 0) {
				try {
					$ok = $this->redis->select( $config->database() );
				} catch (\Throwable $e) {
					throw new BackendException( 'select-db-failed', 'Database selection failed.', 0, $e );
				}

				if ( ! $ok) {
					throw new BackendException( 'select-db-failed', 'Database selection failed.' );
				}
			}

			// Test UNLINK availability via the EXISTS command (cheap).
			try {
				$this->redis->unlink( '_mcoc_probe' );
				$this->unlink_supported = true;
			} catch (\Throwable $e) {
				$this->unlink_supported = false;
			}
		}

		/**
		 * Returns whether the connection is alive.
		 *
		 * @return bool
		 */
		public function is_connected(): bool {
			return $this->redis !== null && $this->redis->isConnected();
		}

		/**
		 * Pings the backend once (used at initialization only).
		 *
		 * @return bool
		 */
		public function ping(): bool {
			if ($this->redis === null) {
				return false;
			}

			try {
				return $this->redis->ping() === true || $this->redis->ping() === '+PONG';
			} catch (\Throwable $e) {
				return false;
			}
		}

		/**
		 * GET a single key.
		 *
		 * @param string $key
		 * @return string|false
		 */
		public function get( string $key) {
			if ($this->redis === null) {
				return false;
			}

			return $this->redis->get( $key );
		}

		/**
		 * MGET multiple keys. Returns values in the same order, false for missing.
		 *
		 * @param array<int,string> $keys
		 * @return array<int,string|false>
		 */
		public function mget( array $keys): array {
			if ($this->redis === null) {
				return array_fill( 0, count( $keys ), false );
			}

			$result = $this->redis->mget( $keys );
			if ( ! is_array( $result )) {
				return array_fill( 0, count( $keys ), false );
			}

			return $result;
		}

		/**
		 * SET with NX or XX conditional and optional expiry in milliseconds.
		 *
		 * @param string   $key
		 * @param string   $value
		 * @param int|null $ttl_ms TTL in ms, or null for no expiry.
		 * @param bool     $nx    If true, only set if the key does not exist.
		 * @param bool     $xx    If true, only set if the key already exists.
		 * @return bool True on success, false on condition failure.
		 */
		public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false): bool {
			if ($this->redis === null) {
				return false;
			}

			$options = array();

			if ($nx) {
				$options[] = 'NX';
			}
			if ($xx) {
				$options[] = 'XX';
			}
			if ($ttl_ms !== null && $ttl_ms > 0) {
				$options['PX'] = $ttl_ms;
			}

			if (count( $options ) === 0) {
				$ok = $this->redis->set( $key, $value );
			} else {
				$ok = $this->redis->set( $key, $value, $options );
			}

			return $ok === true;
		}

		/**
		 * Unconditional SET with optional TTL in milliseconds.
		 *
		 * @param string   $key
		 * @param string   $value
		 * @param int|null $ttl_ms
		 * @return bool
		 */
		public function set_unconditional( string $key, string $value, ?int $ttl_ms = null): bool {
			return $this->set( $key, $value, $ttl_ms, false, false );
		}

		/**
		 * DEL a key (uses UNLINK when available).
		 *
		 * @param string $key
		 * @return int Number of keys deleted (0 if absent).
		 */
		public function del( string $key): int {
			if ($this->redis === null) {
				return 0;
			}

			if ($this->unlink_supported) {
				$result = $this->redis->unlink( $key );
			} else {
				$result = $this->redis->del( $key );
			}

			return (int) $result;
		}

		/**
		 * DEL multiple keys in a single call (uses UNLINK when available).
		 *
		 * @param array<int,string> $keys
		 * @return int Number of keys deleted.
		 */
		public function del_multiple( array $keys): int {
			if ($this->redis === null || count( $keys ) === 0) {
				return 0;
			}

			if ($this->unlink_supported) {
				$result = call_user_func_array( array( $this->redis, 'unlink' ), $keys );
			} else {
				$result = call_user_func_array( array( $this->redis, 'del' ), $keys );
			}

			return (int) $result;
		}

		/**
		 * PTTL of a key.
		 *
		 * @param string $key
		 * @return int PTTL in ms, or -1 for no-expiry, -2 for missing.
		 */
		public function pttl( string $key): int {
			if ($this->redis === null) {
				return Ttl::MISSING_MS;
			}

			$result = $this->redis->pttl( $key );
			if ($result === false) {
				return Ttl::MISSING_MS;
			}

			return (int) $result;
		}

		/**
		 * Runs a Lua script via EVAL.
		 *
		 * @param string          $script
		 * @param array<int,string> $keys
		 * @param array<int,mixed>  $args
		 * @return mixed
		 */
		public function eval( string $script, array $keys = array(), array $args = array()) {
			if ($this->redis === null) {
				return false;
			}

			return $this->redis->eval( $script, array_merge( $keys, $args ), count( $keys ) );
		}

		/**
		 * Runs a pipeline of commands. Each entry is [method, args[]].
		 *
		 * @param array<int,array{0:string,1:array}> $commands
		 * @return array<int,mixed>
		 */
		public function pipeline( array $commands): array {
			if ($this->redis === null) {
				return array();
			}

			$pipe  = $this->redis->pipeline();
			$count = 0;

			foreach ($commands as $cmd) {
				call_user_func_array( array( $pipe, $cmd[0] ), $cmd[1] );
				$count++;
			}

			if ($count === 0) {
				return array();
			}

			$results = $pipe->exec();
			if ( ! is_array( $results )) {
				return array();
			}

			return $results;
		}

		/**
		 * Captures sanitized server identity from INFO.
		 *
		 * @return array<string,string>|null
		 */
		public function server_info(): ?array {
			if ($this->redis === null) {
				return null;
			}

			try {
				$info = $this->redis->info();
			} catch (\Throwable $e) {
				return null;
			}

			if ( ! is_array( $info )) {
				return null;
			}

			$identity = array();

			// Detect Redis vs Valkey without behavior forks.
			$server = isset( $info['redis_version'] ) ? $info['redis_version'] : null;
			$valkey = isset( $info['valkey_version'] ) ? $info['valkey_version'] : null;

			if ($valkey !== null) {
				$identity['product'] = 'valkey';
				$identity['version'] = (string) $valkey;
			} elseif ($server !== null) {
				$identity['product'] = 'redis';
				$identity['version'] = (string) $server;
			} else {
				$identity['product'] = 'unknown';
				$identity['version'] = '';
			}

			$identity['mode']             = isset( $info['redis_mode'] ) ? (string) $info['redis_mode'] : 'standalone';
			$identity['os']               = isset( $info['os'] ) ? (string) $info['os'] : '';
			$identity['maxmemory_policy'] = isset( $info['maxmemory_policy'] ) ? (string) $info['maxmemory_policy'] : '';

			$this->server_info = $identity;

			return $identity;
		}

		/**
		 * Returns cached server info (call server_info() first to populate).
		 *
		 * @return array<string,string>|null
		 */
		public function cached_server_info(): ?array {
			return $this->server_info;
		}

		/**
		 * Closes the connection.
		 *
		 * @return void
		 */
		public function close(): void {
			if ($this->redis !== null && $this->redis->isConnected()) {
				$this->redis->close();
			}

			$this->redis = null;
		}

		/**
		 * @return bool
		 */
		public function supports_unlink(): bool {
			return $this->unlink_supported;
		}

		/**
		 * Builds the connect host/port params from config.
		 *
		 * @param Config $config
		 * @return array{host:string,port:int}
		 */
		private function connect_params( Config $config): array {
			if ($config->scheme() === Config::SCHEME_UNIX) {
				return array(
					'host' => (string) $config->path(),
					'port' => 0,
				);
			}

			if ($config->scheme() === Config::SCHEME_TLS) {
				return array(
					'host' => 'tls://' . $config->host(),
					'port' => $config->port(),
				);
			}

			return array(
				'host' => $config->host(),
				'port' => $config->port(),
			);
		}
	}

	// --- SiteHealth.php ---
	/**
	 * Site Health checks coordinator.
	 *
	 * @internal
	 */
	final class SiteHealth {

		/**
		 * Registers custom Mincemeat tests with WordPress Site Health.
		 *
		 * Callback for the WordPress `site_status_tests` filter.
		 *
		 * @param array $tests Existing Site Health tests.
		 * @return array Updated Site Health tests.
		 */
		public static function register_tests( array $tests ): array {
			$tests['direct']['mincemeat_object_cache_dropin'] = array(
				'label' => __( 'Mincemeat Object Cache drop-in status', 'mincemeat-object-cache' ),
				'test'  => array( self::class, 'test_dropin' ),
			);

			$tests['direct']['mincemeat_object_cache_connection'] = array(
				'label' => __( 'Mincemeat Object Cache connection status', 'mincemeat-object-cache' ),
				'test'  => array( self::class, 'test_connection' ),
			);

			$tests['direct']['mincemeat_object_cache_tls'] = array(
				'label' => __( 'Mincemeat Object Cache TLS verification', 'mincemeat-object-cache' ),
				'test'  => array( self::class, 'test_tls_verification' ),
			);

			$tests['direct']['mincemeat_object_cache_ttl'] = array(
				'label' => __( 'Mincemeat Object Cache TTL configuration', 'mincemeat-object-cache' ),
				'test'  => array( self::class, 'test_ttl' ),
			);

			$tests['direct']['mincemeat_object_cache_eviction'] = array(
				'label' => __( 'Mincemeat Object Cache eviction policy', 'mincemeat-object-cache' ),
				'test'  => array( self::class, 'test_eviction_policy' ),
			);

			return $tests;
		}

		/**
		 * Tests the object-cache.php drop-in file state and ownership.
		 *
		 * @return array Test result details.
		 */
		public static function test_dropin(): array {
			$state = Lifecycle::get_dropin_state();

			switch ( $state ) {
				case Lifecycle::STATE_ABSENT:
					return array(
						'label'       => __( 'Mincemeat Object Cache drop-in is missing', 'mincemeat-object-cache' ),
						'status'      => 'critical',
						'badge'       => self::badge(),
						'description' => sprintf(
							'<p>%s</p>',
							__( 'The object-cache.php drop-in is not present in wp-content/. Mincemeat cannot cache data persistently without this file.', 'mincemeat-object-cache' )
						),
					);
				case Lifecycle::STATE_INVALID_READABLE:
					return array(
						'label'       => __( 'Mincemeat Object Cache drop-in is unreadable', 'mincemeat-object-cache' ),
						'status'      => 'critical',
						'badge'       => self::badge(),
						'description' => sprintf(
							'<p>%s</p>',
							__( 'The object-cache.php drop-in is present in wp-content/ but is not readable. Please verify file permissions.', 'mincemeat-object-cache' )
						),
					);
				case Lifecycle::STATE_FOREIGN:
					return array(
						'label'       => __( 'Conflicting object-cache.php drop-in detected', 'mincemeat-object-cache' ),
						'status'      => 'critical',
						'badge'       => self::badge(),
						'description' => sprintf(
							'<p>%s</p>',
							__( 'A foreign or unrecognized object-cache.php drop-in is present in wp-content/. To prevent conflicts, Mincemeat will not overwrite this file. Please remove or backup the foreign file first.', 'mincemeat-object-cache' )
						),
					);
				case Lifecycle::STATE_OWNED_STALE:
					return array(
						'label'       => __( 'Mincemeat Object Cache drop-in is outdated', 'mincemeat-object-cache' ),
						'status'      => 'recommended',
						'badge'       => self::badge(),
						'description' => sprintf(
							'<p>%s</p>',
							__( 'The active object-cache.php drop-in does not match the companion plugin version. Please update the drop-in using WP-CLI or by deactivating and re-activating the plugin.', 'mincemeat-object-cache' )
						),
					);
				case Lifecycle::STATE_OWNED_CURRENT:
				default:
					return array(
						'label'       => __( 'Mincemeat Object Cache drop-in is active and up to date', 'mincemeat-object-cache' ),
						'status'      => 'good',
						'badge'       => self::badge(),
						'description' => sprintf(
							'<p>%s</p>',
							__( 'The object-cache.php drop-in is correctly installed and matches the companion plugin.', 'mincemeat-object-cache' )
						),
					);
			}
		}

		/**
		 * Tests backend persistence reachability and server configuration.
		 *
		 * @return array Test result details.
		 */
		public static function test_connection(): array {
			if ( ! extension_loaded( 'redis' ) ) {
				return array(
					'label'       => __( 'PhpRedis extension is not loaded', 'mincemeat-object-cache' ),
					'status'      => 'critical',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'The Redis PHP extension (PhpRedis) is required for Mincemeat to connect to the persistent cache store.', 'mincemeat-object-cache' )
					),
				);
			}

			$status = Api::status();
			$state  = $status['state'];
			$reason = $status['reason'];

			if ( strpos( $reason, 'config-' ) === 0 ) {
				return array(
					'label'       => __( 'Mincemeat configuration is invalid', 'mincemeat-object-cache' ),
					'status'      => 'critical',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s <code>%s</code>.</p>',
						__( 'The configuration defined in MINCEMEAT_OBJECT_CACHE_CONFIG is invalid or incomplete. Reason:', 'mincemeat-object-cache' ),
						esc_html( $reason )
					),
				);
			}

			if ( $state === ObjectCache::STATE_RUNTIME_ONLY ) {
				return array(
					'label'       => __( 'Could not connect to persistent cache server', 'mincemeat-object-cache' ),
					'status'      => 'critical',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s <code>%s</code>. %s</p>',
						__( 'Mincemeat Object Cache is running in runtime-only mode because it failed to establish a connection to the persistent backend. Reason:', 'mincemeat-object-cache' ),
						esc_html( $reason ),
						__( 'Please verify that your database server is running and configured correctly in wp-config.php.', 'mincemeat-object-cache' )
					),
				);
			}

			if ( $state === ObjectCache::STATE_DEGRADED ) {
				return array(
					'label'       => __( 'Object cache connection is degraded', 'mincemeat-object-cache' ),
					'status'      => 'critical',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s <code>%s</code>.</p>',
						__( 'A transport or protocol error occurred during the current request. The persistent cache connection has been disconnected to protect request latency. Error code:', 'mincemeat-object-cache' ),
						esc_html( $reason )
					),
				);
			}

			// Persistent state: verify server version requirements.
			$diagnostics = Api::diagnostics();
			$server      = $diagnostics['server'] ?? null;

			if ( is_array( $server ) ) {
				$is_valkey      = isset( $server['valkey_version'] );
				$server_name    = $is_valkey ? 'Valkey' : 'Redis';
				$server_version = $is_valkey ? $server['valkey_version'] : ( $server['redis_version'] ?? 'unknown' );

				if ( $is_valkey ) {
					$version_ok = version_compare( $server_version, '9.0', '>=' );
					$min_ver    = '9.0';
				} else {
					$version_ok = version_compare( $server_version, '8.0', '>=' );
					$min_ver    = '8.0';
				}

				if ( ! $version_ok ) {
					return array(
						'label'       => sprintf( /* translators: 1: Server name, 2: Version */ __( 'Unsupported %1$s server version %2$s', 'mincemeat-object-cache' ), $server_name, $server_version ),
						'status'      => 'recommended',
						'badge'       => self::badge(),
						'description' => sprintf(
							'<p>%s</p>',
							sprintf(
								/* translators: 1: Server name, 2: Required version, 3: Detected version */
								__( 'Mincemeat Object Cache officially supports %1$s %2$s or higher. Your server reports version %3$s. Running an unsupported version may lead to unexpected compatibility issues.', 'mincemeat-object-cache' ),
								$server_name,
								$min_ver,
								$server_version
							)
						),
					);
				}

				return array(
					'label'       => __( 'Connected to persistent cache backend', 'mincemeat-object-cache' ),
					'status'      => 'good',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						sprintf(
							/* translators: 1: Server name, 2: Version */
							__( 'Mincemeat is successfully communicating with %1$s server version %2$s.', 'mincemeat-object-cache' ),
							$server_name,
							$server_version
						)
					),
				);
			}

			return array(
				'label'       => __( 'Connected to persistent cache backend', 'mincemeat-object-cache' ),
				'status'      => 'good',
				'badge'       => self::badge(),
				'description' => sprintf(
					'<p>%s</p>',
					__( 'Mincemeat is successfully connected to the persistent backend database.', 'mincemeat-object-cache' )
				),
			);
		}

		/**
		 * Tests TLS peer verification state when TLS is configured.
		 *
		 * @return array Test result details.
		 */
		public static function test_tls_verification(): array {
			$diagnostics = Api::diagnostics();
			$scheme      = $diagnostics['scheme'] ?? null;

			if ( $scheme !== 'tls' ) {
				return array(
					'label'       => __( 'TLS verification check is not applicable', 'mincemeat-object-cache' ),
					'status'      => 'good',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'TLS is not configured for the persistent database connection.', 'mincemeat-object-cache' )
					),
				);
			}

			$tls              = $diagnostics['tls'] ?? array();
			$verify_peer      = $tls['verify_peer'] ?? true;
			$verify_peer_name = $tls['verify_peer_name'] ?? true;

			if ( $verify_peer === false || $verify_peer_name === false ) {
				return array(
					'label'       => __( 'TLS peer verification is disabled', 'mincemeat-object-cache' ),
					'status'      => 'recommended',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'TLS is configured but peer verification or peer name verification has been disabled. This creates a risk of man-in-the-middle attacks because the authenticity of the cache server certificate is not being validated.', 'mincemeat-object-cache' )
					),
				);
			}

			return array(
				'label'       => __( 'TLS certificate verification is secure', 'mincemeat-object-cache' ),
				'status'      => 'good',
				'badge'       => self::badge(),
				'description' => sprintf(
					'<p>%s</p>',
					__( 'TLS peer certificate and hostname verification are enabled.', 'mincemeat-object-cache' )
				),
			);
		}

		/**
		 * Tests the object cache maximum TTL configuration.
		 *
		 * Unbounded TTL (max_ttl = 0) poses an orphan risk when using logical
		 * namespace flushes.
		 *
		 * @return array Test result details.
		 */
		public static function test_ttl(): array {
			$cache = self::cache();

			if ( $cache === null || $cache->state() !== ObjectCache::STATE_PERSISTENT ) {
				return array(
					'label'       => __( 'Object cache TTL check is not active', 'mincemeat-object-cache' ),
					'status'      => 'good',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'The object cache is not running in persistent mode, so TTL verification is bypassed.', 'mincemeat-object-cache' )
					),
				);
			}

			$max_ttl = $cache->max_ttl();

			if ( $max_ttl === 0 ) {
				return array(
					'label'       => __( 'Object cache has an unbounded TTL (max_ttl = 0)', 'mincemeat-object-cache' ),
					'status'      => 'recommended',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'The Mincemeat Object Cache is configured with a maximum TTL of 0 (unbounded). Because Mincemeat uses logical namespace and group tokens to flush the cache in O(1) time without performing destructive scans or clearing the database, old cache entries will remain in the database indefinitely and become "orphaned". This creates a high risk of memory exhaustion over time. We recommend configuring a non-zero max_ttl (e.g., the default 30 days) to allow old keys to expire naturally.', 'mincemeat-object-cache' )
					),
					'actions'     => sprintf(
						'<p><a href="%s" target="_blank">%s</a></p>',
						'https://github.com/Mincemeat/wp-object-cache#configuration',
						__( 'Configure max_ttl in wp-config.php', 'mincemeat-object-cache' )
					),
				);
			}

			$days = (int) round( $max_ttl / 86400 );

			return array(
				'label'       => __( 'Object cache TTL configuration is safe', 'mincemeat-object-cache' ),
				'status'      => 'good',
				'badge'       => self::badge(),
				'description' => sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: 1: TTL in seconds, 2: TTL in days */
						__( 'The maximum cache TTL is configured to %1$d seconds (approximately %2$d days). Flushed namespace generations will eventually expire and reclaim memory automatically.', 'mincemeat-object-cache' ),
						$max_ttl,
						$days
					)
				),
			);
		}

		/**
		 * Tests the backend database eviction policy.
		 *
		 * A maxmemory-policy of noeviction risks write failures under memory pressure.
		 *
		 * @return array Test result details.
		 */
		public static function test_eviction_policy(): array {
			$cache = self::cache();

			if ( $cache === null || $cache->state() !== ObjectCache::STATE_PERSISTENT ) {
				return array(
					'label'       => __( 'Object cache eviction check is not active', 'mincemeat-object-cache' ),
					'status'      => 'good',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'The object cache is not running in persistent mode, so eviction policy verification is bypassed.', 'mincemeat-object-cache' )
					),
				);
			}

			$info   = $cache->server_info();
			$policy = isset( $info['maxmemory_policy'] ) ? (string) $info['maxmemory_policy'] : '';

			if ( strcasecmp( $policy, 'noeviction' ) === 0 ) {
				return array(
					'label'       => __( 'Database eviction policy is set to "noeviction"', 'mincemeat-object-cache' ),
					'status'      => 'recommended',
					'badge'       => self::badge(),
					'description' => sprintf(
						'<p>%s</p>',
						__( 'The persistent backend database server is configured with the "noeviction" maxmemory policy. When the database reaches its memory limit, write commands will fail rather than evicting older cache entries. For a dynamic cache workload, we recommend changing the database maxmemory-policy to an eviction policy like "volatile-lru" or "allkeys-lru" so the server automatically frees memory when needed.', 'mincemeat-object-cache' )
					),
				);
			}

			return array(
				'label'       => __( 'Database eviction policy is healthy', 'mincemeat-object-cache' ),
				'status'      => 'good',
				'badge'       => self::badge(),
				'description' => sprintf(
					'<p>%s</p>',
					sprintf(
						/* translators: %s: current eviction policy */
						__( 'The backend database server is configured with the "%s" eviction policy, which allows old keys to be reclaimed safely under memory pressure.', 'mincemeat-object-cache' ),
						$policy === '' ? __( 'unknown', 'mincemeat-object-cache' ) : $policy
					)
				),
			);
		}

		/**
		 * Registers custom Mincemeat debug information for Site Health.
		 *
		 * Callback for the WordPress `debug_information` filter.
		 *
		 * @param array $info Existing Site Health debug information.
		 * @return array Updated Site Health debug information.
		 */
		public static function debug_information( array $info ): array {
			$diagnostics    = Api::diagnostics();
			$dropin_state   = Lifecycle::get_dropin_state();
			$target_dir     = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
			$target         = $target_dir . '/object-cache.php';
			$dropin_markers = Lifecycle::parse_markers( $target );

			$dropin_ver = __( 'none', 'mincemeat-object-cache' );
			if ( ! empty( $dropin_markers['version'] ) ) {
				$hash_str   = ! empty( $dropin_markers['build_hash'] ) ? substr( $dropin_markers['build_hash'], 0, 8 ) : 'no hash';
				$dropin_ver = sprintf( '%s (%s)', $dropin_markers['version'], $hash_str );
			}

			$fields = array(
				'dropin_status'    => array(
					'label' => __( 'Drop-in Status', 'mincemeat-object-cache' ),
					'value' => $dropin_state,
				),
				'dropin_version'   => array(
					'label' => __( 'Drop-in Version', 'mincemeat-object-cache' ),
					'value' => $dropin_ver,
				),
				'plugin_version'   => array(
					'label' => __( 'Plugin Version', 'mincemeat-object-cache' ),
					'value' => Api::IMPLEMENTATION_VERSION,
				),
				'cache_state'      => array(
					'label' => __( 'Cache State', 'mincemeat-object-cache' ),
					'value' => $diagnostics['state'],
				),
				'cache_reason'     => array(
					'label' => __( 'Cache Reason Code', 'mincemeat-object-cache' ),
					'value' => $diagnostics['reason'],
				),
				'php_version'      => array(
					'label' => __( 'PHP Version', 'mincemeat-object-cache' ),
					'value' => $diagnostics['php_version'],
				),
				'phpredis_version' => array(
					'label' => __( 'PhpRedis Version', 'mincemeat-object-cache' ),
					'value' => $diagnostics['phpredis_version'],
				),
			);

			// Server info.
			$server_name = __( 'none', 'mincemeat-object-cache' );
			if ( isset( $diagnostics['server'] ) && is_array( $diagnostics['server'] ) ) {
				$info_arr    = $diagnostics['server'];
				$product     = isset( $info_arr['valkey_version'] ) ? 'Valkey' : 'Redis';
				$ver         = $info_arr['valkey_version'] ?? $info_arr['redis_version'] ?? 'unknown';
				$server_name = $product . ' ' . $ver;
			}
			$fields['server_identity'] = array(
				'label' => __( 'Cache Server', 'mincemeat-object-cache' ),
				'value' => $server_name,
			);

			// Transport details.
			$scheme   = $diagnostics['scheme'] ?? 'none';
			$host     = $diagnostics['host'] ?? '';
			$port     = $diagnostics['port'] ?? '';
			$endpoint = $scheme;
			if ( $scheme === 'tcp' || $scheme === 'tls' ) {
				$endpoint .= '://' . $host . ':' . $port;
			} elseif ( $scheme === 'unix' ) {
				$endpoint .= '://' . ( $diagnostics['path'] ?? 'unknown' );
			}
			$fields['endpoint'] = array(
				'label' => __( 'Connection Endpoint', 'mincemeat-object-cache' ),
				'value' => $endpoint,
			);

			$fields['database'] = array(
				'label' => __( 'Database', 'mincemeat-object-cache' ),
				'value' => isset( $diagnostics['database'] ) ? (string) $diagnostics['database'] : __( 'none', 'mincemeat-object-cache' ),
			);

			$fields['namespace_digest'] = array(
				'label' => __( 'Namespace Digest', 'mincemeat-object-cache' ),
				'value' => $diagnostics['namespace_digest'] ?? __( 'none', 'mincemeat-object-cache' ),
			);

			$fields['connect_timeout'] = array(
				'label' => __( 'Connect Timeout', 'mincemeat-object-cache' ),
				'value' => isset( $diagnostics['connect_timeout'] ) ? $diagnostics['connect_timeout'] . 's' : __( 'none', 'mincemeat-object-cache' ),
			);

			$fields['read_timeout'] = array(
				'label' => __( 'Read Timeout', 'mincemeat-object-cache' ),
				'value' => isset( $diagnostics['read_timeout'] ) ? $diagnostics['read_timeout'] . 's' : __( 'none', 'mincemeat-object-cache' ),
			);

			$fields['max_ttl'] = array(
				'label' => __( 'Maximum TTL', 'mincemeat-object-cache' ),
				'value' => isset( $diagnostics['max_ttl'] ) ? $diagnostics['max_ttl'] . 's' : __( 'none', 'mincemeat-object-cache' ),
			);

			$fields['multisite'] = array(
				'label' => __( 'Multisite', 'mincemeat-object-cache' ),
				'value' => $diagnostics['multisite'] ? __( 'Yes', 'mincemeat-object-cache' ) : __( 'No', 'mincemeat-object-cache' ),
			);

			$fields['capabilities'] = array(
				'label' => __( 'Native Capabilities', 'mincemeat-object-cache' ),
				'value' => implode( ', ', Api::NATIVE_FEATURES ),
			);

			$metrics           = $diagnostics['metrics'];
			$metrics_str       = sprintf(
				'Hits: %d | Misses: %d | Backend Calls: %d | Backend Time: %.4fs | Errors: %d',
				$metrics['hits'],
				$metrics['misses'],
				$metrics['backend_calls'],
				$metrics['backend_time'],
				$metrics['errors']
			);
			$fields['metrics'] = array(
				'label' => __( 'Request Metrics', 'mincemeat-object-cache' ),
				'value' => $metrics_str,
			);

			$fields['last_error'] = array(
				'label' => __( 'Last Error Message', 'mincemeat-object-cache' ),
				'value' => $diagnostics['last_error'] ? $diagnostics['last_error'] : __( 'none', 'mincemeat-object-cache' ),
			);

			$info['mincemeat-object-cache'] = array(
				'label'       => __( 'Mincemeat Object Cache', 'mincemeat-object-cache' ),
				'description' => __( 'Diagnostics and configuration for Mincemeat.', 'mincemeat-object-cache' ),
				'fields'      => $fields,
			);

			return $info;
		}

		/**
		 * Returns the site health badge structure.
		 *
		 * @return array Badge info.
		 */
		private static function badge(): array {
			return array(
				'label' => __( 'Mincemeat', 'mincemeat-object-cache' ),
				'color' => 'blue',
			);
		}

		/**
		 * Retrieves the global cache instance.
		 *
		 * @return ObjectCache|null Cache instance, or null.
		 */
		private static function cache(): ?ObjectCache {
			if ( ! isset( $GLOBALS['wp_object_cache'] ) || ! $GLOBALS['wp_object_cache'] instanceof ObjectCache ) {
				return null;
			}

			return $GLOBALS['wp_object_cache'];
		}
	}

	// --- Ttl.php ---
	/**
	 * Pure TTL resolution per DESIGN 3.4.
	 */
	final class Ttl {

		/** The Redis no-expiry sentinel (PTTL = -1). */
		public const NO_EXPIRY_MS = -1;

		/** The Redis missing-key sentinel (PTTL = -2). */
		public const MISSING_MS = -2;

		private function __construct() {
		}

		/**
		 * Resolves the effective TTL in seconds for a cache write.
		 *
		 * Semantics:
		 * - Caller expiry <= 0 means "no caller-requested expiry"; the configured
		 *   max_ttl still applies when nonzero.
		 * - A positive caller expiry is capped by max_ttl when max_ttl is nonzero.
		 * - A return value of 0 means "no expiry" (store without TTL).
		 *
		 * @param int $caller_expire Caller-supplied expiry in seconds.
		 * @param int $max_ttl        Configured maximum TTL in seconds (0 = unbounded).
		 * @return int Effective TTL seconds; 0 means "no expiry".
		 */
		public static function resolve( int $caller_expire, int $max_ttl): int {
			$caller = $caller_expire < 0 ? 0 : $caller_expire;

			if ($caller <= 0) {
				return $max_ttl > 0 ? $max_ttl : 0;
			}

			if ($max_ttl > 0 && $caller > $max_ttl) {
				return $max_ttl;
			}

			return $caller;
		}

		/**
		 * Normalizes a PTTL response from the backend to a remaining TTL value.
		 *
		 * @param int $pttl_ms PTTL in milliseconds, or NO_EXPIRY_MS / MISSING_MS.
		 * @return int|null TTL in milliseconds; null for no-expiry; the MISSING
		 *                  sentinel is normalized to null (caller treats as absent).
		 */
		public static function remaining_from_pttl( int $pttl_ms): ?int {
			if ($pttl_ms === self::NO_EXPIRY_MS || $pttl_ms === self::MISSING_MS) {
				return null;
			}

			return $pttl_ms < 0 ? null : $pttl_ms;
		}

		/**
		 * Converts the resolved TTL seconds to milliseconds for backend commands.
		 *
		 * @param int $ttl_seconds Resolved TTL; 0 means no expiry.
		 * @return int|null TTL in ms, or null for no expiry.
		 */
		public static function to_ms( int $ttl_seconds): ?int {
			return $ttl_seconds > 0 ? $ttl_seconds * 1000 : null;
		}
	}

	// --- ValueCodec.php ---
	/**
	 * Encodes and decodes the plugin-owned wire format for cached values.
	 *
	 * @internal This class is an implementation detail of the persistent adapter.
	 */
	final class ValueCodec {

		/** Plugin magic bytes. */
		public const MAGIC = 'MCOC';

		/** Envelope schema version. */
		public const VERSION = 0x01;

		/** Type tags. */
		public const TAG_INT        = 0x01;
		public const TAG_DOUBLE     = 0x02;
		public const TAG_STRING     = 0x03;
		public const TAG_BOOL       = 0x04;
		public const TAG_NULL       = 0x05;
		public const TAG_SERIALIZED = 0x06;

		/** The PHP serialization of boolean false, used to disambiguate failure. */
		private const SERIALIZED_FALSE = 'b:0;';

		/** Envelope header size: 4 magic + 1 version + 1 tag + 4 length. */
		private const HEADER_SIZE = 10;

		private function __construct() {
		}

		/**
		 * Builds a raw envelope from a pre-serialized payload. Useful for fixtures
		 * and tests that need to construct specific envelopes (including corrupt
		 * ones) without going through encode().
		 *
		 * @param int    $tag     Type tag.
		 * @param string $payload Raw payload bytes.
		 * @return string
		 */
		public static function header_inline( int $tag, string $payload): string {
			return self::header( $tag, strlen( $payload ) ) . $payload;
		}

		/**
		 * Encodes a value into the versioned envelope.
		 *
		 * @param mixed $value The value to encode.
		 * @return string
		 * @throws ValueCodecException On an unsupported type or serialization failure.
		 */
		public static function encode( $value): string {
			if ($value === null) {
				return self::header( self::TAG_NULL, 0 );
			}

			if (is_bool( $value )) {
				return self::header( self::TAG_BOOL, 1 ) . ( $value ? "\x01" : "\x00" );
			}

			if (is_int( $value )) {
				$payload = self::int_to_payload( $value );
				return self::header( self::TAG_INT, strlen( $payload ) ) . $payload;
			}

			if (is_float( $value )) {
				$payload = pack( 'e', $value );
				return self::header( self::TAG_DOUBLE, strlen( $payload ) ) . $payload;
			}

			if (is_string( $value )) {
				return self::header( self::TAG_STRING, strlen( $value ) ) . $value;
			}

			if (is_array( $value ) || is_object( $value )) {
				return self::encode_serialized( $value );
			}

			throw new ValueCodecException( 'encode-unsupported', sprintf( 'Unsupported value type: %s.', gettype( $value ) ) );
		}

		/**
		 * Decodes the versioned envelope.
		 *
		 * @param string $bytes The encoded envelope.
		 * @return array{0:bool,1:mixed,2:?string} Tuple [found, value, error_category].
		 *         found is false for a miss/corruption (value is false, error set).
		 *         found is true on success (error is null).
		 */
		public static function decode( string $bytes): array {
			if ($bytes === '') {
				return array( false, false, 'decode-empty' );
			}

			if (strlen( $bytes ) < self::HEADER_SIZE) {
				return array( false, false, 'decode-truncated' );
			}

			if (substr( $bytes, 0, 4 ) !== self::MAGIC) {
				return array( false, false, 'decode-magic' );
			}

			$version = ord( $bytes[4] );
			if ($version !== self::VERSION) {
				return array( false, false, 'decode-version' );
			}

			$tag     = ord( $bytes[5] );
			$length  = self::read_length( $bytes, 6 );
			$payload = substr( $bytes, self::HEADER_SIZE );

			if (strlen( $payload ) !== $length) {
				return array( false, false, 'decode-length' );
			}

			switch ($tag) {
				case self::TAG_NULL:
					if ($length !== 0) {
						return array( false, false, 'decode-null-payload' );
					}
					return array( true, null, null );

				case self::TAG_BOOL:
					if ($length !== 1) {
						return array( false, false, 'decode-bool-payload' );
					}
					return array( true, $payload !== "\x00", null );

				case self::TAG_INT:
					return self::decode_int( $payload );

				case self::TAG_DOUBLE:
					return self::decode_double( $payload );

				case self::TAG_STRING:
					return array( true, $payload, null );

				case self::TAG_SERIALIZED:
					return self::decode_serialized( $payload );

				default:
					return array( false, false, 'decode-unknown-tag' );
			}
		}

		/**
		 * Encodes an integer as its decimal-string payload, round-tripping any
		 * integer supported by the running PHP build.
		 *
		 * @param int $value
		 * @return string
		 */
		private static function int_to_payload( int $value): string {
			return (string) $value;
		}

		private static function decode_int( string $payload): array {
			if ($payload === '') {
				return array( false, false, 'decode-int-empty' );
			}

			$str = $payload;
			$abs = $str[0] === '-' ? substr( $str, 1 ) : $str;

			if ($abs === '' || ! ctype_digit( $abs )) {
				return array( false, false, 'decode-int-invalid' );
			}

			return array( true, (int) $payload, null );
		}

		private static function decode_double( string $payload): array {
			if (strlen( $payload ) !== 8) {
				return array( false, false, 'decode-double-length' );
			}
			$unpacked = unpack( 'e', $payload );
			if ($unpacked === false) {
				return array( false, false, 'decode-double-invalid' );
			}
			return array( true, $unpacked[1], null );
		}

		private static function encode_serialized( $value): string {
			$payload = null;
			$prev    = error_reporting( 0 );

			try {
				$payload = serialize( $value );
			} catch (\Throwable $e) {
				$payload = false;
			}

			error_reporting( $prev );

			if ($payload === false) {
				throw new ValueCodecException( 'encode-serialize-failed', 'Failed to serialize value.' );
			}

			return self::header( self::TAG_SERIALIZED, strlen( $payload ) ) . $payload;
		}

		private static function decode_serialized( string $payload): array {
			if ($payload === '') {
				return array( false, false, 'decode-serialized-empty' );
			}

			$value = null;
			$prev  = error_reporting( 0 );

			set_error_handler(
				static function () {
					return true;
				}
			);

			try {
				$value = @unserialize( $payload );
			} catch (\Throwable $e) {
				$value = false;
			}

			restore_error_handler();
			error_reporting( $prev );

			// unserialize returns false both on failure and for a legitimate
			// boolean false. Disambiguate by comparing the payload to the known
			// serialization of false. Any other non-false result is a success.
			if ($value === false && $payload !== self::SERIALIZED_FALSE) {
				return array( false, false, 'decode-serialized-failed' );
			}

			return array( true, $value, null );
		}

		/**
		 * Builds the 10-byte envelope header.
		 *
		 * @param int $tag
		 * @param int $length
		 * @return string
		 */
		private static function header( int $tag, int $length): string {
			return self::MAGIC
				. chr( self::VERSION )
				. chr( $tag )
				. self::write_length( $length );
		}

		/**
		 * Reads the 4-byte big-endian length field starting at offset.
		 *
		 * @param string $bytes
		 * @param int    $offset
		 * @return int
		 */
		private static function read_length( string $bytes, int $offset): int {
			$unpacked = unpack( 'N', substr( $bytes, $offset, 4 ) );
			if ($unpacked === false) {
				return 0;
			}
			return (int) $unpacked[1];
		}

		/**
		 * Writes a 4-byte big-endian length field.
		 *
		 * @param int $length
		 * @return string
		 */
		private static function write_length( int $length): string {
			return pack( 'N', $length );
		}
	}

	// --- ValueCodecException.php ---
	/**
	 * Thrown when a value cannot be encoded (unsupported type, serialization failure).
	 *
	 * Decode never throws: corrupt/unknown payloads are reported as a miss with a
	 * sanitized error category by ValueCodec::decode().
	 */
	final class ValueCodecException extends RuntimeException {

		/**
		 * @var string
		 */
		private $category;

		public function __construct( string $category, string $message = '') {
			parent::__construct( '' === $message ? $category : $message );
			$this->category = $category;
		}

		/**
		 * The stable error category for diagnostics.
		 *
		 * @return string
		 */
		public function category(): string {
			return $this->category;
		}
	}
}

namespace {
	use Mincemeat\ObjectCache\ObjectCache;

	if ( ! function_exists( 'wp_cache_init' )) {
		/**
		 * Initializes the object cache and assigns the global instance.
		 *
		 * @global ObjectCache $wp_object_cache
		 */
		function wp_cache_init() {
			$multisite = function_exists( 'is_multisite' ) ? is_multisite() : false;
			$blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

			$key_space = new \Mincemeat\ObjectCache\KeySpace( $multisite, $blog_id );
			$backend   = new \Mincemeat\ObjectCache\Backend( $key_space );

			if ( defined( 'MINCEMEAT_OBJECT_CACHE_CONFIG' ) ) {
				try {
					$config = \Mincemeat\ObjectCache\Config::from_constant();
					$backend->initialize( $config );
				} catch ( \Mincemeat\ObjectCache\ConfigException $e ) {
					$backend->degrade_to_runtime_only( \Mincemeat\ObjectCache\Backend::REASON_CONFIG_INVALID, $e );
				} catch ( \Throwable $e ) {
					$backend->degrade_to_runtime_only( \Mincemeat\ObjectCache\Backend::REASON_COMMAND_FAILED, $e );
				}
			}

			$GLOBALS['wp_object_cache'] = new ObjectCache( $key_space, $backend );
		}
	}

	if ( ! function_exists( 'wp_cache_add' )) {
		/**
		 * Adds data to the cache if the key does not already exist.
		 *
		 * @param mixed  $key    The cache key.
		 * @param mixed  $data   The data to add.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds. Default 0.
		 * @return bool True on success, false if it already exists.
		 */
		function wp_cache_add( $key, $data, $group = '', $expire = 0) {
			global $wp_object_cache;

			return $wp_object_cache->add( $key, $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_add_multiple' )) {
		/**
		 * Adds multiple values to the cache in one call.
		 *
		 * @param array  $data   Key/value pairs to add.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds. Default 0.
		 * @return bool[] Per-key results.
		 */
		function wp_cache_add_multiple( array $data, $group = '', $expire = 0) {
			global $wp_object_cache;

			return $wp_object_cache->add_multiple( $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_replace' )) {
		/**
		 * Replaces the contents of an existing cache item.
		 *
		 * @param mixed  $key    The cache key.
		 * @param mixed  $data   The new data.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds. Default 0.
		 * @return bool True on success, false if the item does not exist.
		 */
		function wp_cache_replace( $key, $data, $group = '', $expire = 0) {
			global $wp_object_cache;

			return $wp_object_cache->replace( $key, $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_set' )) {
		/**
		 * Stores data in the cache, overwriting any existing value.
		 *
		 * @param mixed  $key    The cache key.
		 * @param mixed  $data   The data to store.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds. Default 0.
		 * @return bool True on success, false on failure.
		 */
		function wp_cache_set( $key, $data, $group = '', $expire = 0) {
			global $wp_object_cache;

			return $wp_object_cache->set( $key, $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_set_multiple' )) {
		/**
		 * Stores multiple values in the cache in one call.
		 *
		 * @param array  $data   Key/value pairs to store.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @param int    $expire Optional. TTL in seconds. Default 0.
		 * @return bool[] Per-key results.
		 */
		function wp_cache_set_multiple( array $data, $group = '', $expire = 0) {
			global $wp_object_cache;

			return $wp_object_cache->set_multiple( $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_get' )) {
		/**
		 * Retrieves a cached value.
		 *
		 * @param mixed       $key   The cache key.
		 * @param string      $group Optional. The cache group. Default empty.
		 * @param bool        $force Optional. Force a read past the runtime tier.
		 * @param bool|null   $found Optional. Whether the key was found (reference).
		 * @return mixed|false The cached value on hit, false on miss.
		 */
		function wp_cache_get( $key, $group = '', $force = false, &$found = null) {
			global $wp_object_cache;

			return $wp_object_cache->get( $key, $group, $force, $found );
		}
	}

	if ( ! function_exists( 'wp_cache_get_multiple' )) {
		/**
		 * Retrieves multiple values from the cache in one call.
		 *
		 * @param array  $keys  The cache keys.
		 * @param string $group Optional. The cache group. Default empty.
		 * @param bool   $force Optional. Force reads past the runtime tier.
		 * @return array<string,mixed> Per-key values; misses are false.
		 */
		function wp_cache_get_multiple( $keys, $group = '', $force = false) {
			global $wp_object_cache;

			return $wp_object_cache->get_multiple( $keys, $group, $force );
		}
	}

	if ( ! function_exists( 'wp_cache_delete' )) {
		/**
		 * Removes a cache item.
		 *
		 * @param mixed  $key   The cache key.
		 * @param string $group Optional. The cache group. Default empty.
		 * @return bool True on success, false if absent.
		 */
		function wp_cache_delete( $key, $group = '') {
			global $wp_object_cache;

			return $wp_object_cache->delete( $key, $group );
		}
	}

	if ( ! function_exists( 'wp_cache_delete_multiple' )) {
		/**
		 * Deletes multiple values from the cache in one call.
		 *
		 * @param array  $keys  The cache keys.
		 * @param string $group Optional. The cache group. Default empty.
		 * @return bool[] Per-key results.
		 */
		function wp_cache_delete_multiple( array $keys, $group = '') {
			global $wp_object_cache;

			return $wp_object_cache->delete_multiple( $keys, $group );
		}
	}

	if ( ! function_exists( 'wp_cache_incr' )) {
		/**
		 * Increments a numeric cache item.
		 *
		 * @param mixed  $key    The cache key.
		 * @param int    $offset Optional. The increment amount. Default 1.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @return int|false The new value on success, false on failure.
		 */
		function wp_cache_incr( $key, $offset = 1, $group = '') {
			global $wp_object_cache;

			return $wp_object_cache->incr( $key, $offset, $group );
		}
	}

	if ( ! function_exists( 'wp_cache_decr' )) {
		/**
		 * Decrements a numeric cache item.
		 *
		 * @param mixed  $key    The cache key.
		 * @param int    $offset Optional. The decrement amount. Default 1.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @return int|false The new value on success, false on failure.
		 */
		function wp_cache_decr( $key, $offset = 1, $group = '') {
			global $wp_object_cache;

			return $wp_object_cache->decr( $key, $offset, $group );
		}
	}

	if ( ! function_exists( 'wp_cache_flush' )) {
		/**
		 * Removes all cache items.
		 *
		 * @return bool True on success, false on failure.
		 */
		function wp_cache_flush() {
			global $wp_object_cache;

			return $wp_object_cache->flush();
		}
	}

	if ( ! function_exists( 'wp_cache_flush_runtime' )) {
		/**
		 * Removes all items from the in-memory runtime cache only.
		 *
		 * @return bool True on success, false on failure.
		 */
		function wp_cache_flush_runtime() {
			global $wp_object_cache;

			return $wp_object_cache->flush_runtime();
		}
	}

	if ( ! function_exists( 'wp_cache_flush_group' )) {
		/**
		 * Removes all items in a group.
		 *
		 * @param string $group The group name to flush.
		 * @return bool True on success, false on failure.
		 */
		function wp_cache_flush_group( $group) {
			global $wp_object_cache;

			return $wp_object_cache->flush_group( $group );
		}
	}

	if ( ! function_exists( 'wp_cache_supports' )) {
		/**
		 * Reports whether a native cache feature is supported.
		 *
		 * @param string $feature The feature name.
		 * @return bool True when supported.
		 */
		function wp_cache_supports( $feature) {
			switch ($feature) {
				case 'add_multiple':
				case 'set_multiple':
				case 'get_multiple':
				case 'delete_multiple':
				case 'flush_runtime':
				case 'flush_group':
					return true;

				default:
					return false;
			}
		}
	}

	if ( ! function_exists( 'wp_cache_close' )) {
		/**
		 * Closes the cache. Retained for the WordPress contract.
		 *
		 * @return bool Always true.
		 */
		function wp_cache_close() {
			return true;
		}
	}

	if ( ! function_exists( 'wp_cache_add_global_groups' )) {
		/**
		 * Registers one or more global groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 */
		function wp_cache_add_global_groups( $groups) {
			global $wp_object_cache;

			$wp_object_cache->add_global_groups( $groups );
		}
	}

	if ( ! function_exists( 'wp_cache_add_non_persistent_groups' )) {
		/**
		 * Registers one or more non-persistent groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 */
		function wp_cache_add_non_persistent_groups( $groups) {
			global $wp_object_cache;

			$wp_object_cache->add_non_persistent_groups( $groups );
		}
	}

	if ( ! function_exists( 'wp_cache_switch_to_blog' )) {
		/**
		 * Switches the internal blog ID for non-global groups.
		 *
		 * @param int $blog_id The blog ID.
		 */
		function wp_cache_switch_to_blog( $blog_id) {
			global $wp_object_cache;

			$wp_object_cache->switch_to_blog( (int) $blog_id );
		}
	}

	if ( ! function_exists( 'wp_cache_reset' )) {
		/**
		 * Resets internal cache keys. Deprecated; use wp_cache_switch_to_blog().
		 *
		 * @deprecated 1.0.0
		 */
		function wp_cache_reset() {
			if (function_exists( '_deprecated_function' )) {
				_deprecated_function( __FUNCTION__, '1.0.0', 'wp_cache_switch_to_blog()' );
			}

			global $wp_object_cache;

			if ($wp_object_cache instanceof ObjectCache) {
				$wp_object_cache->reset();
			}
		}
	}
}