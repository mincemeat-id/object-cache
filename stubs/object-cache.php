<?php
// phpcs:ignoreFile
/**
 * Mincemeat Object Cache Drop-In
 *
 * Owner: mincemeat-object-cache
 * Version: 0.1.0-rc2
 * Drop-in Version: 0.1.0-rc2
 * Schema Version: 1
 * Build Hash: 4b6ae3f565409e9cdf38970a77a30fb49882d55c1c109f8893df34fe36f176ae
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache {
	use InvalidArgumentException;
	use Redis;
	use RuntimeException;

	// --- Api.php ---
	/**
	 * Static, read-only interoperability and diagnostics API.
	 */
	final class Api {

		/** Implementation version. */
		public const IMPLEMENTATION_VERSION = '0.1.0-rc2';

		/** Value envelope schema version. */
		public const SCHEMA_VERSION = '1';

		/** Supported v1 backend topology. */
		public const TOPOLOGY_POLICY = 'standalone-single-primary';

		/** Server-reported topology classifications. */
		public const TOPOLOGY_COMPATIBLE = 'compatible';
		public const TOPOLOGY_UNSUPPORTED = 'unsupported';
		public const TOPOLOGY_UNVERIFIED  = 'unverified';

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
		 * @return array{hits:int,misses:int,backend_calls:int,backend_time:float,errors:int,state:string,reason:string}
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
					'state'         => ObjectCache::STATE_RUNTIME_ONLY,
					'reason'        => 'not-initialized',
				);
			}

			$state  = $cache->state();
			$reason = $cache->reason();

			return array(
				'hits'          => $cache->hits(),
				'misses'        => $cache->misses(),
				'backend_calls' => $cache->backend_calls(),
				'backend_time'  => $cache->backend_time(),
				'errors'        => $cache->errors(),
				'state'         => $state,
				'reason'        => $reason,
			);
		}

		/**
		 * Returns redacted structured diagnostics suitable for Site Health.
		 *
		 * No credentials, raw keys, cached values, or stack traces are included.
		 *
		 * @param bool $is_public If true, returns public diagnostics for Site Health.
		 * @return array<string,mixed>
		 */
		public static function diagnostics( bool $is_public = true ): array {
			$cache = self::cache();

			$redis_version = 'unknown';
			if ( class_exists( 'Redis' ) ) {
				if ( defined( 'Redis::VERSION' ) ) {
					$redis_version = Redis::VERSION;
				} elseif ( method_exists( 'Redis', 'getVersion' ) ) {
					$get_version   = new \ReflectionMethod( Redis::class, 'getVersion' );
					$redis_version = (string) $get_version->invoke( new Redis() );
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
				'phpredis_minimum'      => PhpRedisAdapter::MINIMUM_VERSION,
				'topology_policy'       => self::TOPOLOGY_POLICY,
				'persistent_requested'  => false,
				'persistent_reuse'      => false,
				'connection_reuse'      => 'disabled',
			);

			$server_info = null;
			if ( $cache ) {
				$config = $cache->config();
				if ( $config ) {
					$diagnostics = array_merge( $diagnostics, $config->redacted_diagnostics( $is_public ) );
					$diagnostics['persistent_requested'] = $config->persistent();
				}
				$diagnostics['persistent_reuse'] = $cache->persistent_reuse();
				if ( $diagnostics['persistent_requested'] ) {
					$diagnostics['connection_reuse'] = $diagnostics['persistent_reuse'] ? 'active' : 'request-scoped-safety-fallback';
				}

				$server_info = $cache->server_info();
				if ( $server_info ) {
					if ( $is_public ) {
						$diagnostics['server'] = array(
							'product' => $server_info['product'] ?? 'unknown',
							'version' => $server_info['version'] ?? 'unknown',
						);
					} else {
						$diagnostics['server'] = $server_info;
					}
				}
			}
			$diagnostics = array_merge( $diagnostics, self::topology_diagnostics( $server_info ) );

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
		private static function non_persistent_group_names( ObjectCache $cache ): array {
			return array_keys( $cache->non_persistent_groups() );
		}

		/**
		 * Classifies only bounded, server-reported topology fields.
		 *
		 * Managed proxies cannot be detected reliably and therefore remain outside
		 * the support policy even when they report a compatible-looking identity.
		 *
		 * @param array<string,string>|null $server_info Sanitized server identity.
		 * @return array{topology_status:string,topology_mode:string,topology_role:string}
		 */
		private static function topology_diagnostics( ?array $server_info ): array {
			$mode = $server_info !== null ? strtolower( trim( (string) ( $server_info['mode'] ?? '' ) ) ) : '';
			$role = $server_info !== null ? strtolower( trim( (string) ( $server_info['role'] ?? '' ) ) ) : '';

			if ( ! in_array( $mode, array( 'standalone', 'cluster', 'sentinel' ), true ) ) {
				$mode = 'unknown';
			}

			if ( in_array( $role, array( 'master', 'primary' ), true ) ) {
				$role = 'primary';
			} elseif ( in_array( $role, array( 'slave', 'replica' ), true ) ) {
				$role = 'replica';
			} elseif ( $role !== 'sentinel' ) {
				$role = 'unknown';
			}

			$status = self::TOPOLOGY_UNVERIFIED;
			if ( in_array( $mode, array( 'cluster', 'sentinel' ), true ) || in_array( $role, array( 'replica', 'sentinel' ), true ) ) {
				$status = self::TOPOLOGY_UNSUPPORTED;
			} elseif ( $mode === 'standalone' && $role === 'primary' ) {
				$status = self::TOPOLOGY_COMPATIBLE;
			}

			return array(
				'topology_status' => $status,
				'topology_mode'   => $mode,
				'topology_role'   => $role,
			);
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
		public const REASON_NO_BACKEND             = 'no-backend';
		public const REASON_MISSING_EXTENSION      = 'missing-extension';
		public const REASON_UNSUPPORTED_EXTENSION = 'unsupported-extension';
		public const REASON_CONFIG_INVALID         = 'config-invalid';
		public const REASON_CONNECT_FAILED         = 'connect-failed';
		public const REASON_AUTH_FAILED            = 'auth-failed';
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
		 * Whether server identity has been requested this request.
		 *
		 * @var bool
		 */
		private $server_info_loaded = false;

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

		public function __construct( KeySpace $key_space, ?PhpRedisAdapter $adapter = null ) {
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
		public function initialize( Config $config ): void {
			$this->config = $config;
			$this->key_space->configure( $config );

			if ($this->adapter === null) {
				$this->adapter = new PhpRedisAdapter();
			}

			try {
				$this->adapter->connect( $config );
			} catch (BackendException $e) {
				try {
					$this->adapter->close();
				} catch (\Throwable $close_error) {
					// Initialization reason remains the actionable failure category.
					unset( $close_error );
				}
				$this->state   = ObjectCache::STATE_RUNTIME_ONLY;
				$this->reason  = $e->reason();
				$this->adapter = null;

				$this->log_error( 'Initialization failed: ' . $e->reason(), $e );

				return;
			}

			$this->state  = ObjectCache::STATE_PERSISTENT;
			$this->reason = '';
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
		 * Whether PhpRedis process-persistent connection reuse is effective.
		 */
		public function persistent_reuse(): bool {
			return $this->adapter !== null && $this->adapter->persistent_reuse();
		}

		/**
		 * Returns the adapter required by persistent-only operations.
		 *
		 * @throws \LogicException If called outside the persistent invariant.
		 */
		private function adapter(): PhpRedisAdapter {
			if ($this->adapter === null) {
				throw new \LogicException( 'Persistent backend has no adapter.' );
			}

			return $this->adapter;
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
			if ( ! $this->server_info_loaded && $this->is_persistent()) {
				$this->server_info_loaded = true;
				try {
					$this->server_info = $this->adapter()->server_info();
				} catch (\Throwable $e) {
					$this->server_info = null;
				}
			}

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
		public function group_token( string $group ): string {
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
		 * Resolves namespace and group generation tokens together.
		 *
		 * Existing controls use one MGET. Missing controls are created in one
		 * pipeline, with a coalesced readback if another request wins a SET NX
		 * race. Tokens remain memoized for the request so flushes cannot change the
		 * key generation midway through an operation sequence.
		 *
		 * @param string $group Normalized group name.
		 * @return array{0:string,1:string} Namespace and group tokens.
		 */
		public function generation_tokens( string $group ): array {
			if ( ! $this->is_persistent()) {
				return array( '', '' );
			}

			$group_cache_key = 'grp:' . $group;
			$controls       = array();
			if ( ! $this->namespace_token_loaded) {
				$controls['ns'] = $this->key_space->namespace_control_key();
			}
			if ( ! isset( $this->tokens[ $group_cache_key ] )) {
				$controls[ $group_cache_key ] = $this->key_space->group_control_key( $group );
			}

			if (count( $controls ) > 0) {
				$labels = array_keys( $controls );
				try {
					$values = $this->adapter()->mget( array_values( $controls ) );
				} catch (\Throwable $e) {
					$this->degrade( self::REASON_COMMAND_FAILED, $e );
					return array( KeySpace::generate_token(), KeySpace::generate_token() );
				}

				$missing = array();
				foreach ($labels as $index => $label) {
					$value = $values[ $index ] ?? false;
					$token = is_string( $value ) ? trim( $value ) : '';
					if ($token !== '') {
						$this->tokens[ $label ] = $token;
					} else {
						$missing[ $label ] = $controls[ $label ];
					}
				}

				if (count( $missing ) > 0) {
					$this->initialize_missing_tokens( $missing );
				}
			}

			$this->namespace_token_loaded = true;

			return array(
				$this->tokens['ns'] ?? KeySpace::generate_token(),
				$this->tokens[ $group_cache_key ] ?? KeySpace::generate_token(),
			);
		}

		/**
		 * Resolves group tokens for a batch of groups, coalescing into a single
		 * MGET after initializing any missing ones.
		 *
		 * @param array<int,string> $groups Normalized group names.
		 * @return array<string,string> Map of group name => token.
		 */
		public function group_tokens( array $groups ): array {
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
				$values = $this->adapter()->mget( $keys );
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
				$ok = $this->adapter()->set_unconditional( $key, $token );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );
				return false;
			}

			if ($ok) {
				$this->tokens['ns']           = $token;
				$this->namespace_token_loaded = true;
			}

			return $ok;
		}

		/**
		 * Replaces a group generation token (used by flush_group).
		 *
		 * @param string $group Normalized group name.
		 * @return bool True on success.
		 */
		public function replace_group_token( string $group ): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			$key   = $this->key_space->group_control_key( $group );
			$token = KeySpace::generate_token();

			try {
				$ok = $this->adapter()->set_unconditional( $key, $token );
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
		public function get( string $key ) {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter()->get( $key );
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
		public function mget( array $keys ): array {
			if ( ! $this->is_persistent()) {
				return array_fill( 0, count( $keys ), false );
			}

			try {
				return $this->adapter()->mget( $keys );
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
		public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false ): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter()->set( $key, $value, $ttl_ms, $nx, $xx );
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
		public function set_unconditional( string $key, string $value, ?int $ttl_ms = null ): bool {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter()->set_unconditional( $key, $value, $ttl_ms );
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
		public function del( string $key ): int {
			if ( ! $this->is_persistent()) {
				return 0;
			}

			try {
				return $this->adapter()->del( $key );
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
		public function del_pipeline( array $keys ): array {
			if ( ! $this->is_persistent() || count( $keys ) === 0) {
				return array_fill( 0, count( $keys ), false );
			}

			$commands = array();
			foreach ($keys as $key) {
				$commands[] = array( 'unlink', array( $key ) );
			}

			try {
				$results = $this->adapter()->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $keys ), false );
			}

			if (in_array( false, $results, true )) {
				$this->degrade( self::REASON_COMMAND_FAILED );

				return array_fill( 0, count( $keys ), false );
			}

			$out = array();
			foreach ($results as $i => $r) {
				$out[] = (int) $r > 0;
			}

			return $out;
		}

		/**
		 * Set multiple keys via pipeline (all unconditional).
		 *
		 * @param array<int,array{0:string,1:string,2:?int}> $entries [key, value, ttl_ms].
		 * @return array<int,bool>
		 */
		public function set_pipeline( array $entries ): array {
			if ( ! $this->is_persistent() || count( $entries ) === 0) {
				return array_fill( 0, count( $entries ), false );
			}

			$commands = array();
			foreach ($entries as $entry) {
				$commands[] = $this->build_set_command( $entry[0], $entry[1], $entry[2], false, false );
			}

			try {
				$results = $this->adapter()->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array_fill( 0, count( $entries ), false );
			}

			if (in_array( false, $results, true )) {
				$this->degrade( self::REASON_COMMAND_FAILED );

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
		public function set_conditional_pipeline( array $entries ): array {
			if ( ! $this->is_persistent() || count( $entries ) === 0) {
				return array_fill( 0, count( $entries ), false );
			}

			$commands = array();
			foreach ($entries as $entry) {
				$commands[] = $this->build_set_command( $entry[0], $entry[1], $entry[2], $entry[3], $entry[4] );
			}

			try {
				$results = $this->adapter()->pipeline( $commands );
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
		public function eval( string $script, array $keys = array(), array $args = array() ) {
			if ( ! $this->is_persistent()) {
				return false;
			}

			try {
				return $this->adapter()->eval( $script, $keys, $args );
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
		public function eval_incr( string $item_key, int $offset ): array {
			if ( ! $this->is_persistent()) {
				return array( LuaScripts::RESULT_MISSING, null );
			}

			try {
				$result = $this->adapter()->eval(
					LuaScripts::INCR_DECR,
					array( $item_key ),
					array( (string) $offset )
				);
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return array( LuaScripts::RESULT_MISSING, null );
			}

			if ($result === false) {
				$this->degrade( self::REASON_COMMAND_FAILED );

				return array( LuaScripts::RESULT_MISSING, null );
			}

			if ( ! is_array( $result ) || ! isset( $result[0] )) {
				return array( LuaScripts::RESULT_CORRUPT, null );
			}

			$code = (string) $result[0];

			if ($code === LuaScripts::RESULT_OK && isset( $result[1] )) {
				$val = $result[1];
				return array( LuaScripts::RESULT_OK, (int) $val );
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
		private function init_token( string $key ): string {
			if ( ! $this->is_persistent()) {
				return '';
			}

			$token = KeySpace::generate_token();

			try {
				$created = $this->adapter()->set( $key, $token, null, true );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return KeySpace::generate_token();
			}

			if ($created) {
				return $token;
			}

			// Someone else won the race; read back their token.
			try {
				$existing = $this->adapter()->get( $key );
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
				$created2 = $this->adapter()->set( $key, $token2, null, true );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );

				return KeySpace::generate_token();
			}

			if ($created2) {
				return $token2;
			}

			// Try one more get
			try {
				$existing2 = $this->adapter()->get( $key );
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
		 * Creates control tokens that were absent in a coalesced read.
		 *
		 * @param array<string,string> $missing Token cache label => control key.
		 */
		private function initialize_missing_tokens( array $missing ): void {
			$candidates = array();
			$commands   = array();
			foreach ($missing as $label => $key) {
				$candidates[ $label ] = KeySpace::generate_token();
				$commands[]            = array( 'set', array( $key, $candidates[ $label ], array( 'NX' ) ) );
			}

			try {
				$results = $this->adapter()->pipeline( $commands );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );
				return;
			}

			$losers = array();
			$i      = 0;
			foreach ($missing as $label => $key) {
				if (( $results[ $i ] ?? false ) === true) {
					$this->tokens[ $label ] = $candidates[ $label ];
				} else {
					$losers[ $label ] = $key;
				}
				++$i;
			}

			if (count( $losers ) === 0) {
				return;
			}

			try {
				$values = $this->adapter()->mget( array_values( $losers ) );
			} catch (\Throwable $e) {
				$this->degrade( self::REASON_COMMAND_FAILED, $e );
				return;
			}

			$i = 0;
			foreach ($losers as $label => $key) {
				$value = $values[ $i ] ?? false;
				$token = is_string( $value ) ? trim( $value ) : '';
				$this->tokens[ $label ] = $token !== '' ? $token : $this->init_token( $key );
				++$i;
			}
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
		 * Records a stable error category and emits a bounded debug log.
		 *
		 * Web logs require both debug mode and a shared APCu throttle. Without a
		 * process-shared limiter, web logging is suppressed so a backend outage can
		 * never emit once per request indefinitely. CLI processes may emit once.
		 *
		 * @internal
		 * @param string          $message   The log message.
		 * @param \Throwable|null $exception Optional associated exception.
		 */
		public function log_error( string $message, ?\Throwable $exception = null ): void {
			unset( $exception );
			if ( $this->logged ) {
				return;
			}
			$this->logged = true;

			$this->last_error_message = $message;

			if ( ! $this->should_emit_log( $message )) {
				return;
			}

			$log_msg = 'Mincemeat Object Cache: ' . $message;
			error_log( $log_msg );
		}

		/**
		 * Determines whether this process/request may emit an error log entry.
		 *
		 * @param string $message Stable error category.
		 */
		private function should_emit_log( string $message ): bool {
			if ( $this->config === null || ! $this->config->debug()) {
				return false;
			}

			if ( PHP_SAPI === 'cli' ) {
				return true;
			}

			if ( ! function_exists( 'apcu_add' ) || ! function_exists( 'apcu_enabled' ) || ! apcu_enabled()) {
				return false;
			}

			try {
				return apcu_add( 'mcoc:log:' . hash( 'sha256', $message ), 1, 300 );
			} catch (\Throwable $e) {
				return false;
			}
		}

		/**
		 * Builds a pipeline SET command array.
		 *
		 * @param string   $key
		 * @param string   $value
		 * @param int|null $ttl_ms
		 * @param bool     $nx
		 * @param bool     $xx
		 * @return array{0:string,1:array<int,mixed>}
		 */
		private function build_set_command( string $key, string $value, ?int $ttl_ms, bool $nx, bool $xx ): array {
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

		public function __construct( string $reason, string $message = '', int $code = 0, ?\Throwable $previous = null ) {
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
		public const REASON_MAX_RETRIES     = 'config-max-retries';
		public const REASON_BACKOFF         = 'config-backoff';
		public const REASON_TCP_KEEPALIVE   = 'config-tcp-keepalive';
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

		/** Bounded PhpRedis reconnect defaults. */
		public const DEFAULT_MAX_RETRIES       = 1;
		public const MAX_RETRIES               = 3;
		public const DEFAULT_BACKOFF_ALGORITHM = 'decorrelated_jitter';
		public const DEFAULT_BACKOFF_BASE      = 10;
		public const DEFAULT_BACKOFF_CAP       = 100;
		public const MAX_BACKOFF               = 1000;

		/** Supported PhpRedis reconnect algorithms. */
		private const BACKOFF_ALGORITHMS = array(
			'default',
			'decorrelated_jitter',
			'full_jitter',
			'equal_jitter',
			'exponential',
			'uniform',
			'constant',
		);

		/**
		 * Known config keys, mapped to defaults applied when the key is absent.
		 *
		 * @var array<string,mixed>
		 */
		private const KNOWN_KEYS = array(
			'namespace'         => null,
			'scheme'            => self::SCHEME_TCP,
			'host'              => '127.0.0.1',
			'port'              => 6379,
			'path'              => null,
			'database'          => 0,
			'username'          => null,
			'password'          => null,
			'connect_timeout'   => 0.25,
			'read_timeout'      => 0.25,
			'max_retries'       => self::DEFAULT_MAX_RETRIES,
			'backoff_algorithm' => self::DEFAULT_BACKOFF_ALGORITHM,
			'backoff_base'      => self::DEFAULT_BACKOFF_BASE,
			'backoff_cap'       => self::DEFAULT_BACKOFF_CAP,
			'tcp_keepalive'     => true,
			'persistent'        => false,
			'max_ttl'           => self::DEFAULT_MAX_TTL,
			'tls'               => array(),
			'debug'             => false,
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

		/** @var int */
		private $max_retries;

		/** @var string */
		private $backoff_algorithm;

		/** @var int */
		private $backoff_base;

		/** @var int */
		private $backoff_cap;

		/** @var bool */
		private $tcp_keepalive;

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
		public function __construct( array $input ) {
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

			$max_retries = $input['max_retries'] ?? self::KNOWN_KEYS['max_retries'];
			$this->validate_bounded_integer( $max_retries, 0, self::MAX_RETRIES, self::REASON_MAX_RETRIES );
			$this->max_retries = (int) $max_retries;

			$backoff_algorithm = $input['backoff_algorithm'] ?? self::KNOWN_KEYS['backoff_algorithm'];
			$backoff_base      = $input['backoff_base'] ?? self::KNOWN_KEYS['backoff_base'];
			$backoff_cap       = $input['backoff_cap'] ?? self::KNOWN_KEYS['backoff_cap'];
			$this->validate_backoff( $backoff_algorithm, $backoff_base, $backoff_cap );
			$this->backoff_algorithm = (string) $backoff_algorithm;
			$this->backoff_base      = (int) $backoff_base;
			$this->backoff_cap       = (int) $backoff_cap;

			$tcp_keepalive = $input['tcp_keepalive'] ?? self::KNOWN_KEYS['tcp_keepalive'];
			$this->validate_bool( $tcp_keepalive, self::REASON_TCP_KEEPALIVE );
			$this->tcp_keepalive = (bool) $tcp_keepalive;

			$persistent = $input['persistent'] ?? self::KNOWN_KEYS['persistent'];
			$this->validate_bool( $persistent, self::REASON_PERSISTENT );
			$this->persistent = (bool) $persistent;

			$max_ttl = $input['max_ttl'] ?? self::KNOWN_KEYS['max_ttl'];
			$this->validate_max_ttl( $max_ttl );
			$this->max_ttl = (int) $max_ttl;

			$tls = $input['tls'] ?? self::KNOWN_KEYS['tls'];
			$this->validate_tls( $tls );
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

		public function max_retries(): int {
			return $this->max_retries;
		}

		public function backoff_algorithm(): string {
			return $this->backoff_algorithm;
		}

		public function backoff_base(): int {
			return $this->backoff_base;
		}

		public function backoff_cap(): int {
			return $this->backoff_cap;
		}

		public function tcp_keepalive(): bool {
			return $this->tcp_keepalive;
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
		 * The public flag is retained for API compatibility. Connection identity is
		 * never exposed in either mode; non-public diagnostics may include broader
		 * server metadata through Api::diagnostics(), but not endpoint details.
		 *
		 * @param bool $is_public Whether the caller requested the public view.
		 * @return array<string,mixed>
		 */
		public function redacted_diagnostics( bool $is_public = false ): array {
			unset( $is_public );
			$tls_summary = array();
			if ( $this->scheme === self::SCHEME_TLS ) {
				$tls_summary = array(
					'verify_peer'      => $this->tls_verify_peer(),
					'verify_peer_name' => $this->tls_verify_peer_name(),
				);
			}

			return array(
				'scheme'            => $this->scheme,
				'host'              => $this->scheme === self::SCHEME_UNIX ? '' : 'configured',
				'port'              => $this->scheme === self::SCHEME_UNIX ? null : '***',
				'path'              => $this->scheme === self::SCHEME_UNIX ? 'configured' : null,
				'database'          => '***',
				'namespace_digest'  => substr( $this->namespace_digest, 0, 16 ),
				'connect_timeout'   => $this->connect_timeout,
				'read_timeout'      => $this->read_timeout,
				'max_retries'       => $this->max_retries,
				'backoff_algorithm' => $this->backoff_algorithm,
				'backoff_base_ms'   => $this->backoff_base,
				'backoff_cap_ms'    => $this->backoff_cap,
				'tcp_keepalive'     => $this->tcp_keepalive,
				'persistent'        => $this->persistent,
				'max_ttl'           => $this->max_ttl,
				'debug'             => $this->debug,
				'tls'               => $tls_summary,
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

		/**
		 * @param array<string,mixed> $input
		 */
		private function reject_unknown_keys( array $input ): void {
			foreach (array_keys( $input ) as $key) {
				if ( ! array_key_exists( $key, self::KNOWN_KEYS )) {
					throw new ConfigException( self::REASON_UNKNOWN_KEY );
				}
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_namespace( $value ): void {
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

		/**
		 * @param mixed $value
		 */
		private function validate_scheme( $value ): void {
			if ( ! is_string( $value ) || ! in_array( $value, self::SCHEMES, true )) {
				throw new ConfigException( self::REASON_SCHEME, 'Scheme must be tcp, tls, or unix.' );
			}
		}

		/**
		 * @param mixed  $value
		 * @param string $scheme
		 */
		private function validate_host( $value, string $scheme ): void {
			if ($scheme === self::SCHEME_UNIX) {
				return;
			}

			if ( ! is_string( $value ) || trim( $value ) === '') {
				throw new ConfigException( self::REASON_HOST, 'Host is required for tcp/tls.' );
			}
		}

		/**
		 * @param mixed  $value
		 * @param string $scheme
		 */
		private function validate_port( $value, string $scheme ): void {
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

		/**
		 * @param mixed  $value
		 * @param string $scheme
		 */
		private function validate_path( $value, string $scheme ): void {
			if ($scheme !== self::SCHEME_UNIX) {
				return;
			}

			if ( ! is_string( $value ) || trim( $value ) === '') {
				throw new ConfigException( self::REASON_PATH, 'Path is required for unix sockets.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_database( $value ): void {
			$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			if ( ! $ok || (int) $value < 0) {
				throw new ConfigException( self::REASON_DATABASE, 'Database must be a non-negative integer.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_username( $value ): void {
			if ($value !== null && ! is_string( $value )) {
				throw new ConfigException( self::REASON_USERNAME, 'Username must be null or a string.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_password( $value ): void {
			if ($value !== null && ! is_string( $value )) {
				throw new ConfigException( self::REASON_PASSWORD, 'Password must be null or a string.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_timeout( $value, string $reason ): void {
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

		/**
		 * @param mixed  $value
		 * @param int    $minimum
		 * @param int    $maximum
		 * @param string $reason
		 */
		private function validate_bounded_integer( $value, int $minimum, int $maximum, string $reason ): void {
			$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			if ( ! $ok || (int) $value < $minimum || (int) $value > $maximum) {
				throw new ConfigException( $reason, 'Value is outside the supported integer range.' );
			}
		}

		/**
		 * @param mixed $algorithm
		 * @param mixed $base
		 * @param mixed $cap
		 */
		private function validate_backoff( $algorithm, $base, $cap ): void {
			if ( ! is_string( $algorithm ) || ! in_array( $algorithm, self::BACKOFF_ALGORITHMS, true )) {
				throw new ConfigException( self::REASON_BACKOFF, 'Unsupported backoff algorithm.' );
			}

			$this->validate_bounded_integer( $base, 0, self::MAX_BACKOFF, self::REASON_BACKOFF );
			$this->validate_bounded_integer( $cap, 0, self::MAX_BACKOFF, self::REASON_BACKOFF );

			if ( (int) $cap < (int) $base) {
				throw new ConfigException( self::REASON_BACKOFF, 'Backoff cap must be greater than or equal to its base.' );
			}
		}

		/**
		 * @param mixed  $value
		 * @param string $reason
		 */
		private function validate_bool( $value, string $reason ): void {
			if ( ! is_bool( $value ) && ! ( is_int( $value ) && ( $value === 0 || $value === 1 ) )) {
				throw new ConfigException( $reason, 'Value must be boolean.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_max_ttl( $value ): void {
			$ok = is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			if ( ! $ok || (int) $value < 0) {
				throw new ConfigException( self::REASON_MAX_TTL, 'max_ttl must be a non-negative integer.' );
			}
		}

		/**
		 * @param mixed $value
		 */
		private function validate_tls( $value ): void {
			if ($value === null || $value === array()) {
				return;
			}

			if ( ! is_array( $value )) {
				throw new ConfigException( self::REASON_TLS, 'TLS context must be an array.' );
			}

			foreach ($value as $key => $option) {
				if ( ! is_string( $key ) || is_array( $option ) || is_object( $option ) || is_resource( $option )) {
					throw new ConfigException( self::REASON_TLS, 'TLS context values must be scalar or null.' );
				}
			}
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

		public function __construct( string $reason, string $message = '' ) {
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
		 * @param string $namespace_name Optional installation namespace (Phase 3 injection).
		 */
		public function __construct( bool $multisite, int $blog_id, string $namespace_name = '' ) {
			$this->multisite   = $multisite;
			$this->blog_prefix = $this->multisite ? $blog_id . ':' : '';

			if ( $namespace_name !== '' ) {
				$this->namespace_digest = hash( 'sha256', $namespace_name );
			}
		}

		/**
		 * Sets or replaces the installation namespace digest from a Config.
		 *
		 * @param Config $config
		 */
		public function configure( Config $config ): void {
			$this->namespace_digest = $config->namespace_digest();
		}

		/**
		 * Sets the namespace token for the current namespace generation.
		 *
		 * @param string $token 32-char lowercase hex token.
		 */
		public function set_namespace_token( string $token ): void {
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
		public function add_global_groups( $groups ): void {
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
		public function is_global_group( string $group ): bool {
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
		public function switch_to_blog( int $blog_id ): void {
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
		public function is_valid_key( $key ): bool {
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
			if ( function_exists( '_doing_it_wrong' ) ) {
				_doing_it_wrong( $caller, $message, '6.1.0' );
			}

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
		public function normalize_group( string $group ): string {
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
		public function storage_id( $key, string $group ): string {
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
		public function group_digest( string $group ): string {
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
		public function key_digest( $key ): string {
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
		public function scope_for( string $group ): string {
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
		public function group_control_key( string $group ): string {
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
		public function item_key( string $ns_token, string $group_token, string $group, $key ): string {
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
		 *   {RESULT_CORRUPT}             when the envelope is malformed.
		 *
		 * The script uses only Redis/Valkey-compatible Lua and string/TTL
		 * commands: GET, PTTL, SET, UNLINK. It does not use KEYS, SCAN, or
		 * FLUSHDB. It preserves the existing PTTL (including no-expiry = -1)
		 * and never resets it.
		 */
		public const INCR_DECR = <<<'LUA'
	local function decode_double(str)
	    local b1, b2, b3, b4, b5, b6, b7, b8 = string.byte(str, 1, 8)
	    local sign = 1
	    if b8 >= 128 then
	        sign = -1
	        b8 = b8 - 128
	    end
	    local exponent = b8 * 16 + math.floor(b7 / 16)
	    if exponent == 2047 then
	        return 0
	    end
	    local mantissa = (b7 % 16) * 2^48
	        + b6 * 2^40
	        + b5 * 2^32
	        + b4 * 2^24
	        + b3 * 2^16
	        + b2 * 2^8
	        + b1
	    if exponent == 0 then
	        if mantissa == 0 then
	            return 0
	        else
	            return sign * mantissa * 2^(-1022 - 52)
	        end
	    else
	        return sign * (1 + mantissa / 2^52) * 2^(exponent - 1023)
	    end
	end

	local function encode_double(value)
	    if value == 0 then
	        return string.char(0, 0, 0, 0, 0, 0, 0, 0)
	    end
	    local sign = 0
	    if value < 0 then
	        sign = 1
	        value = -value
	    end
	    local exponent = math.floor(math.log(value) / math.log(2))
	    local mantissa = value / 2^exponent
	    if mantissa < 1 then
	        mantissa = mantissa * 2
	        exponent = exponent - 1
	    elseif mantissa >= 2 then
	        mantissa = mantissa / 2
	        exponent = exponent + 1
	    end
	    exponent = exponent + 1023
	    if exponent >= 2047 then
	        if sign == 1 then
	            return string.char(0, 0, 0, 0, 0, 0, 240, 255)
	        else
	            return string.char(0, 0, 0, 0, 0, 0, 240, 127)
	        end
	    elseif exponent <= 0 then
	        return string.char(0, 0, 0, 0, 0, 0, 0, 0)
	    end
	    mantissa = (mantissa - 1) * 2^52
	    local m_part = mantissa
	    local bytes = {}
	    for i = 1, 6 do
	        local b = m_part % 256
	        bytes[i] = math.floor(b)
	        m_part = (m_part - b) / 256
	    end
	    local b7 = math.floor(m_part % 16) + (exponent % 16) * 16
	    local b8 = math.floor(exponent / 16) + sign * 128
	    return string.char(bytes[1], bytes[2], bytes[3], bytes[4], bytes[5], bytes[6], b7, b8)
	end

	local function cmp_str(a, b)
	    if #a ~= #b then
	        return #a > #b and 1 or -1
	    end
	    if a == b then
	        return 0
	    end
	    return a > b and 1 or -1
	end

	local function add_str(a, b)
	    local res = {}
	    local carry = 0
	    local i = #a
	    local j = #b
	    while i > 0 or j > 0 or carry > 0 do
	        local sum = carry
	        if i > 0 then
	            sum = sum + string.byte(a, i) - 48
	            i = i - 1
	        end
	        if j > 0 then
	            sum = sum + string.byte(b, j) - 48
	            j = j - 1
	        end
	        carry = math.floor(sum / 10)
	        table.insert(res, 1, string.char((sum % 10) + 48))
	    end
	    return table.concat(res)
	end

	local function sub_str(a, b)
	    local res = {}
	    local borrow = 0
	    local i = #a
	    local j = #b
	    while i > 0 do
	        local val = string.byte(a, i) - 48 - borrow
	        i = i - 1
	        local sub_val = 0
	        if j > 0 then
	            sub_val = string.byte(b, j) - 48
	            j = j - 1
	        end
	        if val < sub_val then
	            val = val + 10
	            borrow = 1
	        else
	            borrow = 0
	        end
	        table.insert(res, 1, string.char((val - sub_val) + 48))
	    end
	    local s = table.concat(res)
	    s = s:gsub("^0+", "")
	    if s == "" then
	        return "0"
	    end
	    return s
	end

	local function normalize_unsigned(value)
	    value = value:gsub("^0+", "")
	    if value == "" then
	        return "0"
	    end
	    return value
	end

	local function trim(value)
	    return value:match("^%s*(.-)%s*$")
	end

	local key = KEYS[1]
	local offset_str = ARGV[1]
	local is_negative = false
	if string.sub(offset_str, 1, 1) == '-' then
	    is_negative = true
	    offset_str = string.sub(offset_str, 2)
	end

	local raw = redis.call('GET', key)
	if raw == false then
	    return {'MISSING'}
	end

	if string.len(raw) < 10 then
	    return {'CORRUPT'}
	end

	if string.sub(raw, 1, 4) ~= 'MCOC' then
	    return {'CORRUPT'}
	end

	local ver = string.byte(raw, 5)
	if ver ~= 1 then
	    return {'CORRUPT'}
	end

	local tag = string.byte(raw, 6)

	local b1 = string.byte(raw, 7)
	local b2 = string.byte(raw, 8)
	local b3 = string.byte(raw, 9)
	local b4 = string.byte(raw, 10)
	local length = b1 * 16777216 + b2 * 65536 + b3 * 256 + b4

	local payload = string.sub(raw, 11)
	if string.len(payload) ~= length then
	    return {'CORRUPT'}
	end

	if tag < 1 or tag > 6 then
	    return {'CORRUPT'}
	end

	if tag == 1 and string.match(payload, "^%-?%d+$") == nil then
	    return {'CORRUPT'}
	end

	if tag == 2 and string.len(payload) ~= 8 then
	    return {'CORRUPT'}
	end

	if tag == 4 and (string.len(payload) ~= 1 or (payload ~= string.char(0) and payload ~= string.char(1))) then
	    return {'CORRUPT'}
	end

	if tag == 5 and string.len(payload) ~= 0 then
	    return {'CORRUPT'}
	end

	local string_value = tag == 3 and trim(payload) or ""
	local string_is_integer = string.match(string_value, "^[%+%-]?%d+$") ~= nil
	local string_is_decimal = string_value ~= ""
	    and string.match(string_value, "^[%+%-%.%deE]+$") ~= nil
	    and tonumber(string_value) ~= nil

	local is_int_payload = (tag == 1) or (tag == 4) or (tag == 5) or (tag == 3 and string_is_integer)

	local new_tag = 1
	local new_payload = ''
	local display_val = ''

	if is_int_payload then
	    local current_str = "0"
	    local is_current_negative = false

	    if tag == 1 or tag == 3 then
	        local raw_payload = tag == 3 and string_value or payload
	        if string.sub(raw_payload, 1, 1) == '-' then
	            is_current_negative = true
	            current_str = normalize_unsigned(string.sub(raw_payload, 2))
	        else
	            if string.sub(raw_payload, 1, 1) == '+' then
	                raw_payload = string.sub(raw_payload, 2)
	            end
	            current_str = normalize_unsigned(raw_payload)
	        end
	    elseif tag == 4 then
	        current_str = "0"
	    end

	    local new_val_str = ""
	    if is_current_negative then
	        if is_negative then
	            new_val_str = "0"
	        else
	            if cmp_str(offset_str, current_str) <= 0 then
	                new_val_str = "0"
	            else
	                new_val_str = sub_str(offset_str, current_str)
	            end
	        end
	    else
	        if not is_negative then
	            new_val_str = add_str(current_str, offset_str)
	        else
	            if cmp_str(current_str, offset_str) <= 0 then
	                new_val_str = "0"
	            else
	                new_val_str = sub_str(current_str, offset_str)
	            end
	        end
	    end

	    if #new_val_str > 19 or (#new_val_str == 19 and cmp_str(new_val_str, "9223372036854775807") > 0) then
	        new_val_str = "9223372036854775807"
	    end

	    new_tag = 1
	    new_payload = new_val_str
	    display_val = new_val_str
	else
	    local current = 0
	    if tag == 2 then
	        current = decode_double(payload)
	    elseif tag == 3 and string_is_decimal then
	        current = tonumber(string_value) or 0
	    end

	    if current ~= current or current == math.huge or current == -math.huge then
	        current = 0
	    end

	    if current < 0 then
	        current = math.ceil(current)
	    else
	        current = math.floor(current)
	    end

	    local offset = tonumber(ARGV[1])
	    local new_value = current + offset
	    if new_value < 0 then
	        new_value = 0
	    end

	    new_tag = 1
	    if new_value >= 9223372036854775807 then
	        new_payload = "9223372036854775807"
	    else
	        new_payload = string.format('%.0f', new_value)
	    end
	    display_val = new_payload
	end

	local new_len = string.len(new_payload)
	local new_raw = 'MCOC'
	    .. string.char(1)
	    .. string.char(new_tag)
	    .. string.char(math.floor(new_len / 16777216) % 256)
	    .. string.char(math.floor(new_len / 65536) % 256)
	    .. string.char(math.floor(new_len / 256) % 256)
	    .. string.char(new_len % 256)
	    .. new_payload

	local pttl = redis.call('PTTL', key)
	if pttl == -1 then
	    redis.call('SET', key, new_raw)
	elseif pttl > 0 then
	    redis.call('SET', key, new_raw, 'PX', pttl)
	else
	    return {'MISSING'}
	end

	return {'OK', display_val}
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
		public $cache_hits = 0;

		/**
		 * Total cache misses this request.
		 *
		 * @var int
		 */
		public $cache_misses = 0;

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
		public function __construct( ?KeySpace $key_space = null, ?Backend $backend = null ) {
			$multisite = function_exists( 'is_multisite' ) ? is_multisite() : false;
			$blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

			$this->key_space = $key_space ?? new KeySpace( $multisite, $blog_id );

			if ($backend !== null) {
				$this->backend = $backend;
				$this->state   = $backend->state();
				$this->reason  = $backend->reason();
				if ($this->state !== self::STATE_PERSISTENT && $this->reason !== Backend::REASON_NO_BACKEND) {
					$this->errors = 1;
				}
			}
		}

		/**
		 * Exposes core-compatible internal properties for read access.
		 *
		 * WordPress core makes these properties readable through magic accessors,
		 * and plugins commonly inspect them for diagnostics.
		 *
		 * @param string $name Property name.
		 * @return mixed|null Property value, or null for unsupported properties.
		 */
		public function __get( $name ) {
			if ('global_groups' === $name) {
				return $this->key_space->global_groups();
			}

			if ('blog_prefix' === $name) {
				return $this->key_space->blog_prefix();
			}

			return null;
		}

		/**
		 * Makes core-compatible internal properties checkable.
		 *
		 * @param string $name Property name.
		 * @return bool Whether the compatibility property is available.
		 */
		public function __isset( $name ): bool {
			return 'global_groups' === $name || 'blog_prefix' === $name;
		}

		/**
		 * Attaches a backend for persistent operations.
		 *
		 * @param Backend $backend
		 */
		public function attach_backend( Backend $backend ): void {
			$this->backend = $backend;
			$this->state   = $backend->state();
			$this->reason  = $backend->reason();
			if ($this->state !== self::STATE_PERSISTENT && $this->reason !== Backend::REASON_NO_BACKEND) {
				$this->errors = 1;
			}
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
		public function add( $key, $data, $group = '', $expire = 0 ): bool {
			$group  = (string) $group;
			$expire = (int) $expire;
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
		 * @param array<string|int,mixed> $data   Key/value pairs to add.
		 * @param string                  $group  Optional. The cache group. Default empty.
		 * @param int                     $expire Optional. TTL in seconds.
		 * @return bool[] Per-key results.
		 */
		public function add_multiple( array $data, $group = '', $expire = 0 ): array {
			$group  = (string) $group;
			$expire = (int) $expire;
			$group  = $this->key_space->normalize_group( $group );

			if ($this->is_addition_suspended()) {
				return array_fill_keys( array_keys( $data ), false );
			}

			if ( ! $this->is_persistent_group( $group )) {
				$values = array();
				foreach ($data as $key => $value) {
					$values[ $key ] = $this->add( $key, $value, $group, $expire );
				}
				return $values;
			}

			// Persistent add pipeline.
			$valid_keys = array();
			$storage_ids = array();
			$entries = array();
			$out = array();

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$ttl_ms  = $this->resolve_ttl_ms( $expire );

			foreach ($data as $key => $value) {
				if ( ! $this->key_space->is_valid_key( $key )) {
					$out[ $key ] = false;
					continue;
				}

				$storage_id = $this->key_space->storage_id( $key, $group );

				if ($this->exists( $storage_id, $group )) {
					$out[ $key ] = false;
					continue;
				}

				try {
					$encoded = ValueCodec::encode( $value );
				} catch (ValueCodecException $e) {
					$out[ $key ] = false;
					continue;
				}

				$storage_ids[ $key ] = $storage_id;
				$valid_keys[] = $key;
				$backend_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
				$entries[] = array( $backend_key, $encoded, $ttl_ms, true, false );
			}

			if (count( $valid_keys ) === 0) {
				return $out;
			}

			$this->backend_calls += 1;
			$start = microtime( true );
			$pipeline_results = $this->backend()->set_conditional_pipeline( $entries );
			$this->backend_time += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				foreach ($valid_keys as $key) {
					$storage_id = $storage_ids[ $key ];
					if ( ! $this->exists( $storage_id, $group )) {
						$out[ $key ] = $this->set_in_memory( $storage_id, $group, $data[ $key ] );
					} else {
						$out[ $key ] = false;
					}
				}
				return $out;
			}

			$failed_keys = array();
			$failed_backend_keys = array();

			foreach ($valid_keys as $idx => $key) {
				$storage_id = $storage_ids[ $key ];
				if ($pipeline_results[ $idx ]) {
					$this->set_in_memory( $storage_id, $group, $data[ $key ] );
					$out[ $key ] = true;
				} else {
					$out[ $key ] = false;
					$failed_keys[] = $key;
					$failed_backend_keys[] = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
				}
			}

			if (count( $failed_backend_keys ) > 0) {
				$this->backend_calls += 1;
				$start = microtime( true );
				$raw_values = $this->backend()->mget( $failed_backend_keys );
				$this->backend_time += ( microtime( true ) - $start ) * 1000000;

				if ($this->sync_state()) {
					foreach ($failed_keys as $idx => $key) {
						$raw = $raw_values[ $idx ] ?? null;
						if (is_string( $raw ) && $raw !== '') {
							list($ok2, $val) = ValueCodec::decode( $raw );
							if ($ok2) {
								$storage_id = $storage_ids[ $key ];
								$this->set_in_memory( $storage_id, $group, $val );
							}
						}
					}
				}
			}

			return $out;
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
		public function replace( $key, $data, $group = '', $expire = 0 ): bool {
			$group  = (string) $group;
			$expire = (int) $expire;
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
		public function set( $key, $data, $group = '', $expire = 0 ): bool {
			$group  = (string) $group;
			$expire = (int) $expire;
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
		 * @param array<string|int,mixed> $data   Key/value pairs to store.
		 * @param string                  $group  Optional. The cache group. Default empty.
		 * @param int                     $expire Optional. TTL in seconds.
		 * @return bool[] Per-key results.
		 */
		public function set_multiple( array $data, $group = '', $expire = 0 ): array {
			$group  = (string) $group;
			$expire = (int) $expire;
			$group  = $this->key_space->normalize_group( $group );

			if ( ! $this->is_persistent_group( $group )) {
				$values = array();
				foreach ($data as $key => $value) {
					$values[ $key ] = $this->set( $key, $value, $group, $expire );
				}
				return $values;
			}

			// Persistent set pipeline.
			$valid_keys = array();
			$storage_ids = array();
			$entries = array();
			$out = array();

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$ttl_ms  = $this->resolve_ttl_ms( $expire );

			foreach ($data as $key => $value) {
				if ( ! $this->key_space->is_valid_key( $key )) {
					$out[ $key ] = false;
					continue;
				}

				try {
					$encoded = ValueCodec::encode( $value );
				} catch (ValueCodecException $e) {
					$out[ $key ] = false;
					continue;
				}

				$storage_id = $this->key_space->storage_id( $key, $group );
				$storage_ids[ $key ] = $storage_id;
				$valid_keys[] = $key;
				$backend_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
				$entries[] = array( $backend_key, $encoded, $ttl_ms );
			}

			if (count( $valid_keys ) === 0) {
				return $out;
			}

			$this->backend_calls += 1;
			$start = microtime( true );
			$pipeline_results = $this->backend()->set_pipeline( $entries );
			$this->backend_time += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				foreach ($valid_keys as $key) {
					$storage_id = $storage_ids[ $key ];
					$out[ $key ] = $this->set_in_memory( $storage_id, $group, $data[ $key ] );
				}
				return $out;
			}

			foreach ($valid_keys as $idx => $key) {
				$storage_id = $storage_ids[ $key ];
				if ($pipeline_results[ $idx ]) {
					$this->set_in_memory( $storage_id, $group, $data[ $key ] );
					$out[ $key ] = true;
				} else {
					$out[ $key ] = false;
				}
			}

			return $out;
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
		public function get( $key, $group = '', bool $force = false, &$found = null ) {
			$group = (string) $group;
			if ( ! $this->key_space->is_valid_key( $key )) {
				return false;
			}

			$group      = $this->key_space->normalize_group( $group );
			$storage_id = $this->key_space->storage_id( $key, $group );

			$should_force = $force && $this->is_persistent_group( $group );

			if ( ! $should_force && $this->exists( $storage_id, $group )) {
				$found       = true;
				$this->cache_hits += 1;
				$value       = $this->cache[ $group ][ $storage_id ];

				return is_object( $value ) ? clone $value : $value;
			}

			if ($this->is_persistent_group( $group )) {
				return $this->persistent_get( $key, $group, $force, $found, $storage_id );
			}

			$found         = false;
			$this->cache_misses += 1;

			return false;
		}

		/**
		 * Retrieves multiple values in one call.
		 *
		 * @param array<int,string|int> $keys  The cache keys.
		 * @param string                $group Optional. The cache group. Default empty.
		 * @param bool                  $force Optional. Force reads past the runtime tier.
		 * @return array<string|int,mixed> Per-key values; misses are false.
		 */
		public function get_multiple( array $keys, $group = '', bool $force = false ): array {
			$group = (string) $group;
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
		public function delete( $key, $group = '' ): bool {
			$group = (string) $group;
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
		 * @param array<int,string|int> $keys  The cache keys.
		 * @param string                $group Optional. The cache group. Default empty.
		 * @return bool[] Per-key results.
		 */
		public function delete_multiple( array $keys, $group = '' ): array {
			$group  = (string) $group;
			$group  = $this->key_space->normalize_group( $group );

			if ( ! $this->is_persistent_group( $group )) {
				$values = array();
				foreach ($keys as $key) {
					$values[ $key ] = $this->delete( $key, $group );
				}
				return $values;
			}

			// Persistent delete pipeline.
			$valid_keys = array();
			$storage_ids = array();
			$was_in_memory = array();
			$backend_keys = array();
			$out = array();

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );

			foreach ($keys as $key) {
				if ( ! $this->key_space->is_valid_key( $key )) {
					$out[ $key ] = false;
					continue;
				}
				$storage_id = $this->key_space->storage_id( $key, $group );
				$storage_ids[ $key ] = $storage_id;
				$was_in_memory[ $key ] = $this->exists( $storage_id, $group );
				$valid_keys[] = $key;
				$backend_keys[] = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			}

			if (count( $valid_keys ) === 0) {
				return $out;
			}

			$this->backend_calls += 1;
			$start = microtime( true );
			$pipeline_results = $this->backend()->del_pipeline( $backend_keys );
			$this->backend_time += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				foreach ($valid_keys as $key) {
					$storage_id = $storage_ids[ $key ];
					if ($was_in_memory[ $key ]) {
						unset( $this->cache[ $group ][ $storage_id ] );
						$out[ $key ] = true;
					} else {
						$out[ $key ] = false;
					}
				}
				return $out;
			}

			foreach ($valid_keys as $idx => $key) {
				$storage_id = $storage_ids[ $key ];
				unset( $this->cache[ $group ][ $storage_id ] );
				$out[ $key ] = $pipeline_results[ $idx ] || $was_in_memory[ $key ];
			}

			return $out;
		}

		/**
		 * Increments a numeric cache item.
		 *
		 * @param mixed  $key    The cache key.
		 * @param int    $offset Optional. The increment amount. Default 1.
		 * @param string $group  Optional. The cache group. Default empty.
		 * @return int|false The new value on success, false on miss or invalid key.
		 */
		public function incr( $key, $offset = 1, $group = '' ) {
			$offset = (int) $offset;
			$group  = (string) $group;
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
		public function decr( $key, $offset = 1, $group = '' ) {
			$offset = (int) $offset;
			$group  = (string) $group;
			if ($offset === PHP_INT_MIN) {
				return $this->delta( $key, PHP_INT_MIN, $group );
			}
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
		public function flush_group( $group ): bool {
			$group = (string) $group;
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
		public function add_global_groups( $groups ): void {
			$this->key_space->add_global_groups( $groups );
		}

		/**
		 * Registers non-persistent groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 */
		public function add_non_persistent_groups( $groups ): void {
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
		public function is_non_persistent_group( $group ): bool {
			$group = (string) $group;
			return isset( $this->non_persistent_groups[ $group ] );
		}

		/**
		 * Switches the active blog scope.
		 *
		 * @param int $blog_id The blog ID to switch to.
		 */
		public function switch_to_blog( int $blog_id ): void {
			$this->key_space->switch_to_blog( $blog_id );
		}

		/**
		 * Resets cache keys (deprecated). Clears non-global groups.
		 *
		 * @deprecated Use switch_to_blog().
		 */
		public function reset(): void {
			if (function_exists( '_deprecated_function' )) {
				_deprecated_function( __METHOD__, '3.5.0', 'WP_Object_Cache::switch_to_blog()' );
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
			return $this->cache_hits;
		}

		public function misses(): int {
			return $this->cache_misses;
		}

		/**
		 * Echoes core-compatible cache statistics.
		 *
		 * @return void
		 */
		public function stats(): void {
			echo '<p>';
			echo "<strong>Cache Hits:</strong> {$this->cache_hits}<br />";
			echo "<strong>Cache Misses:</strong> {$this->cache_misses}<br />";
			echo '</p>';
			echo '<ul>';

			$kilobyte = defined( 'KB_IN_BYTES' ) ? KB_IN_BYTES : 1024;
			foreach ($this->cache as $group => $cache) {
				$label = function_exists( 'esc_html' ) ? esc_html( $group ) : htmlspecialchars( $group, ENT_QUOTES, 'UTF-8' );
				echo '<li><strong>Group:</strong> ' . $label . ' - ( ' . number_format( strlen( serialize( $cache ) ) / $kilobyte, 2 ) . 'k )</li>';
			}

			echo '</ul>';
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
		 * Whether PhpRedis process-persistent connection reuse is effective.
		 */
		public function persistent_reuse(): bool {
			return $this->backend !== null && $this->backend->persistent_reuse();
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
		private function exists( string $storage_id, string $group ): bool {
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
		 *
		 * @param string $storage_id
		 * @param string $group
		 * @param mixed  $data
		 * @return bool
		 */
		private function set_in_memory( string $storage_id, string $group, $data ): bool {
			if (is_object( $data )) {
				$data = clone $data;
			}

			$this->cache[ $group ][ $storage_id ] = $data;

			return true;
		}

		/**
		 * Returns the backend required by persistent-only operations.
		 *
		 * @throws \LogicException If called outside the persistent invariant.
		 */
		private function backend(): Backend {
			if ($this->backend === null) {
				throw new \LogicException( 'Persistent cache operation has no backend.' );
			}

			return $this->backend;
		}

		/**
		 * Whether a group should use the persistent backend.
		 *
		 * @param string $group Normalized group name.
		 * @return bool
		 */
		private function is_persistent_group( string $group ): bool {
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

			$previous_state = $this->state;
			$this->state  = $this->backend->state();
			$this->reason = $this->backend->reason();

			if ($previous_state === self::STATE_PERSISTENT && $this->state !== self::STATE_PERSISTENT) {
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
		private function resolve_ttl_ms( int $caller_expire ): ?int {
			$max_ttl = $this->backend !== null ? $this->backend->max_ttl() : 0;
			$seconds = Ttl::resolve( $caller_expire, $max_ttl );

			return Ttl::to_ms( $seconds );
		}

		// ------------------------------------------------------------------
		// Internals: persistent operations
		// ------------------------------------------------------------------

		/**
		 * Persistent GET: reads from backend, populates memory, sets $found.
		 *
		 * @param mixed  $key
		 * @param string $group
		 * @param bool   $force
		 * @param bool|null $found
		 * @param-out bool $found
		 * @param string $storage_id
		 * @return mixed|false
		 */
		private function persistent_get( $key, string $group, bool $force, &$found, string $storage_id ) {
			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$raw                  = $this->backend()->get( $item_key );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				return $this->runtime_fallback_get( $storage_id, $group, $found );
			}

			if (is_string( $raw ) && $raw !== '') {
				list($ok, $value, $err) = ValueCodec::decode( $raw );
				if ($ok) {
					$found       = true;
					$this->cache_hits += 1;
					$this->set_in_memory( $storage_id, $group, $value );

					return is_object( $value ) ? clone $value : $value;
				}

				if ($err !== null) {
					$this->backend()->log_error( 'Value codec decode failed: ' . $err );
					$this->backend()->del( $item_key );
				}
			}

			// Backend miss or corrupt: treat as miss, remove stale memory entry.
			unset( $this->cache[ $group ][ $storage_id ] );
			$found         = false;
			$this->cache_misses += 1;

			return false;
		}

		/**
		 * Persistent GET_MULTIPLE: serves from memory, MGETs missing keys.
		 *
		 * @param array<int,string|int> $keys
		 * @param string                $group
		 * @param bool                  $force
		 * @return array<string|int,mixed>
		 */
		private function persistent_get_multiple( array $keys, string $group, bool $force ): array {
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
					$this->cache_hits += 1;
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

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );

			$backend_keys = array();
			foreach ($missing as $key) {
				$backend_keys[] = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			}

			$this->backend_calls += 1;
			$start                = microtime( true );
			$raw_values           = $this->backend()->mget( $backend_keys );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				foreach ($missing as $key) {
					$storage_id_d = $this->key_space->storage_id( $key, $group );
					if ($this->exists( $storage_id_d, $group )) {
						$value          = $this->cache[ $group ][ $storage_id_d ];
						$values[ $key ] = is_object( $value ) ? clone $value : $value;
						$this->cache_hits += 1;
					} else {
						$this->cache_misses += 1;
					}
				}

				return $values;
			}

			foreach ($missing as $i => $key) {
				$raw = $raw_values[ $i ] ?? false;

				if (is_string( $raw ) && $raw !== '') {
					list($ok, $val, $err) = ValueCodec::decode( $raw );
					if ($ok) {
						$this->cache_hits += 1;
						$this->set_in_memory( $miss_ids[ $key ], $group, $val );
						$values[ $key ] = is_object( $val ) ? clone $val : $val;
						continue;
					}

					if ($err !== null) {
						$this->backend()->log_error( 'Value codec decode failed: ' . $err );
						$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
						$this->backend()->del( $item_key );
					}
				}

				$this->cache_misses += 1;
				$values[ $key ] = false;
			}

			return $values;
		}

		/**
		 * Persistent SET: encodes and writes to backend, updates memory on success.
		 *
		 * @param mixed  $key
		 * @param mixed  $data
		 * @param string $group
		 * @param int    $expire
		 * @param string $storage_id
		 * @return bool
		 */
		private function persistent_set( $key, $data, string $group, int $expire, string $storage_id ): bool {
			try {
				$encoded = ValueCodec::encode( $data );
			} catch (ValueCodecException $e) {
				return false;
			}

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			$ttl_ms   = $this->resolve_ttl_ms( $expire );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$ok                   = $this->backend()->set_unconditional( $item_key, $encoded, $ttl_ms );
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
		 *
		 * @param mixed  $key
		 * @param mixed  $data
		 * @param string $group
		 * @param int    $expire
		 * @param string $storage_id
		 * @return bool
		 */
		private function persistent_add( $key, $data, string $group, int $expire, string $storage_id ): bool {
			try {
				$encoded = ValueCodec::encode( $data );
			} catch (ValueCodecException $e) {
				return false;
			}

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			$ttl_ms   = $this->resolve_ttl_ms( $expire );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$ok                   = $this->backend()->set( $item_key, $encoded, $ttl_ms, true, false );
			$this->backend_time  += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			if ($ok) {
				return $this->set_in_memory( $storage_id, $group, $data );
			}

			// Key exists in backend; read back to populate memory.
			$raw = $this->backend()->get( $item_key );
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
		 *
		 * @param mixed  $key
		 * @param mixed  $data
		 * @param string $group
		 * @param int    $expire
		 * @param string $storage_id
		 * @return bool
		 */
		private function persistent_replace( $key, $data, string $group, int $expire, string $storage_id ): bool {
			try {
				$encoded = ValueCodec::encode( $data );
			} catch (ValueCodecException $e) {
				return false;
			}

			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );
			$ttl_ms   = $this->resolve_ttl_ms( $expire );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$ok                   = $this->backend()->set( $item_key, $encoded, $ttl_ms, false, true );
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
		 *
		 * @param mixed  $key
		 * @param string $group
		 * @param string $storage_id
		 * @return bool
		 */
		private function persistent_delete( $key, string $group, string $storage_id ): bool {
			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );

			$this->backend_calls += 1;
			$start                = microtime( true );
			$deleted              = $this->backend()->del( $item_key );
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
		 *
		 * @param string    $storage_id
		 * @param string    $group
		 * @param bool|null $found
		 * @param-out bool $found
		 * @return mixed|false
		 */
		private function runtime_fallback_get( string $storage_id, string $group, &$found ) {
			if ($this->exists( $storage_id, $group )) {
				$found       = true;
				$this->cache_hits += 1;
				$value       = $this->cache[ $group ][ $storage_id ];

				return is_object( $value ) ? clone $value : $value;
			}

			$found         = false;
			$this->cache_misses += 1;

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
		private function delta( $key, int $offset, string $group ) {
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

			$current = $this->apply_integer_delta( $this->cache[ $group ][ $storage_id ], $offset );

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
		private function persistent_delta( $key, int $offset, string $group, string $storage_id ) {
			list($ns_tok, $grp_tok) = $this->backend()->generation_tokens( $group );
			$item_key = $this->key_space->item_key( $ns_tok, $grp_tok, $group, $key );

			$this->backend_calls   += 1;
			$start                  = microtime( true );
			list($code, $new_value) = $this->backend()->eval_incr( $item_key, $offset );
			$this->backend_time    += ( microtime( true ) - $start ) * 1000000;

			if ( ! $this->sync_state()) {
				// Backend degraded: fall back to in-memory delta if the value
				// is already loaded. This preserves coherent runtime behavior.
				if ($this->exists( $storage_id, $group )) {
					$current = $this->apply_integer_delta( $this->cache[ $group ][ $storage_id ], $offset );
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

		/**
		 * Coerces a cached numeric value to an integer and applies a bounded delta.
		 *
		 * @param mixed $value
		 */
		private function apply_integer_delta( $value, int $offset ): int {
			$current = $this->coerce_numeric_value( $value );

			if ($offset === 0) {
				return max( 0, $current );
			}

			if ($offset > 0 && $current > PHP_INT_MAX - $offset) {
				return PHP_INT_MAX;
			}

			if ($offset > 0 && $current < -$offset) {
				return 0;
			}

			if ($offset < 0 && ( $offset === PHP_INT_MIN || $current < -$offset )) {
				return 0;
			}

			return $current + $offset;
		}

		/**
		 * Coerces a cached value according to the cross-tier numeric contract.
		 *
		 * Integers, floats, and decimal numeric strings are truncated toward zero.
		 * Other values, including booleans and null, normalize to zero as they do in
		 * WordPress core before arithmetic. The explicit decimal grammar prevents
		 * Redis Lua's tonumber() extensions (for example hexadecimal strings) from
		 * diverging from the request-local tier.
		 *
		 * @param mixed $value
		 */
		private function coerce_numeric_value( $value ): int {
			if (is_int( $value ) || is_float( $value )) {
				return (int) $value;
			}

			if ( ! is_string( $value )) {
				return 0;
			}

			$candidate = trim( $value );
			if ($candidate === '' || preg_match( '/^[+-]?(?:(?:\d+\.?\d*)|(?:\.\d+))(?:[eE][+-]?\d+)?$/D', $candidate ) !== 1) {
				return 0;
			}

			return (int) $candidate;
		}
	}

	// --- PhpRedisAdapter.php ---
	/**
	 * Thin command adapter over the PhpRedis \Redis client.
	 *
	 * @internal
	 */
	class PhpRedisAdapter {

		/** Minimum supported PhpRedis extension version. */
		public const MINIMUM_VERSION = '6.3.0';

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

		/**
		 * Loaded Lua script SHA1 values, keyed by source SHA1 for this connection.
		 *
		 * @var array<string,string>
		 */
		private $script_shas = array();

		/**
		 * Whether the active connection uses PhpRedis process-persistent reuse.
		 *
		 * @var bool
		 */
		private $persistent_reuse = false;

		public function __construct() {
		}

		/**
		 * Connects to the backend per the validated config.
		 *
		 * @param Config $config
		 * @throws BackendException On connection, auth, or database-selection failure.
		 */
		public function connect( Config $config ): void {
			if ( ! class_exists( Redis::class )) {
				throw new BackendException( 'missing-extension', 'The PhpRedis extension is not available.' );
			}

			$phpredis_version = $this->phpredis_version();
			if ( ! $phpredis_version || version_compare( $phpredis_version, self::MINIMUM_VERSION, '<' ) ) {
				throw new BackendException( 'unsupported-extension', 'PhpRedis >= 6.3.0 is required.' );
			}

			$this->redis = $this->create_redis_instance();
			$this->script_shas      = array();
			$this->persistent_reuse = false;

			$connected     = false;
			$persistent_id = $config->persistent() && $this->persistent_pool_honors_id()
				? $this->persistent_id( $config )
				: '';

			$context = null;
			if ($config->scheme() === Config::SCHEME_TLS) {
				$context = array( 'stream' => $config->tls() );
			}

			$params = $this->connect_params( $config );

			try {
				if ($persistent_id !== '') {
					if ($context !== null) {
						$connected = $this->redis->pconnect(
							$params['host'],
							$params['port'],
							$config->connect_timeout(),
							$persistent_id,
							0,
							$config->read_timeout(),
							$context
						);
					} else {
						$connected = $this->redis->pconnect(
							$params['host'],
							$params['port'],
							$config->connect_timeout(),
							$persistent_id,
							0,
							$config->read_timeout()
						);
					}
				} elseif ($context !== null) {
						$connected = $this->redis->connect(
							$params['host'],
							$params['port'],
							$config->connect_timeout(),
							null,
							0,
							$config->read_timeout(),
							$context
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
			$this->persistent_reuse = $persistent_id !== '';

			$this->configure_options( $config );
			if ($this->redis === null) {
				throw new BackendException( 'connect-failed', 'Connection option configuration failed.' );
			}

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

			// Unlink is supported on all supported backends (Redis >= 4.0).
			$this->unlink_supported = true;
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
				$result = $this->redis->ping();
				return $result === true || $result === '+PONG';
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
		public function get( string $key ) {
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
		public function mget( array $keys ): array {
			if ($this->redis === null) {
				return array_fill( 0, count( $keys ), false );
			}

			/** @var array<int,string|false>|false $result */
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
		public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false ): bool {
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
		public function set_unconditional( string $key, string $value, ?int $ttl_ms = null ): bool {
			return $this->set( $key, $value, $ttl_ms, false, false );
		}

		/**
		 * DEL a key (uses UNLINK when available).
		 *
		 * @param string $key
		 * @return int Number of keys deleted (0 if absent).
		 */
		public function del( string $key ): int {
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
		public function del_multiple( array $keys ): int {
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
		public function pttl( string $key ): int {
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
		 * Runs a Lua script via EVALSHA with an EVAL fallback on NOSCRIPT.
		 *
		 * @param string          $script
		 * @param array<int,string> $keys
		 * @param array<int,mixed>  $args
		 * @return mixed
		 */
		public function eval( string $script, array $keys = array(), array $args = array() ) {
			if ($this->redis === null) {
				return false;
			}

			$arguments  = array_merge( $keys, $args );
			$source_sha = sha1( $script );
			$loaded_sha = $this->script_shas[ $source_sha ] ?? null;

			if ($loaded_sha === null) {
				$result = $this->redis->eval( $script, $arguments, count( $keys ) );
				if ($result !== false) {
					// EVAL also populates the server-side script cache.
					$this->script_shas[ $source_sha ] = $source_sha;
				}
				return $result;
			}

			$result = $this->redis->evalSha( $loaded_sha, $arguments, count( $keys ) );
			if ($result !== false) {
				return $result;
			}

			$last_error = $this->redis->getLastError();
			if ( ! is_string( $last_error ) || stripos( $last_error, 'NOSCRIPT' ) !== 0) {
				return false;
			}

			$this->redis->clearLastError();

			// EVAL executes and repopulates the server's script cache, allowing the
			// next call on this connection to return to EVALSHA.
			$result = $this->redis->eval( $script, $arguments, count( $keys ) );
			if ($result !== false) {
				$this->script_shas[ $source_sha ] = $source_sha;
			}
			return $result;
		}

		/**
		 * Runs a pipeline of commands. Each entry is [method, args[]].
		 *
		 * @param array<int,array{0:string,1:array<int,mixed>}> $commands
		 * @return array<int,mixed>
		 */
		public function pipeline( array $commands ): array {
			if ($this->redis === null) {
				return array();
			}

			$pipe  = $this->redis->pipeline();
			if ($pipe === false) {
				throw new BackendException( 'command-failed', 'Pipeline initialization failed.' );
			}

			$count = 0;

			foreach ($commands as $cmd) {
				$args = $cmd[1];
				switch ($cmd[0]) {
					case 'set':
						$key     = (string) ( $args[0] ?? '' );
						$value   = (string) ( $args[1] ?? '' );
						$options = $args[2] ?? null;
						if (is_array( $options )) {
							$pipe->set( $key, $value, $options );
						} else {
							$pipe->set( $key, $value );
						}
						break;
					case 'unlink':
						$pipe->unlink( (string) ( $args[0] ?? '' ) );
						break;
					case 'del':
						$pipe->del( (string) ( $args[0] ?? '' ) );
						break;
					default:
						throw new \LogicException( 'Unsupported pipeline command.' );
				}
				++$count;
			}

			if ($count === 0) {
				return array();
			}

			$results = $pipe->exec();
			if ( ! is_array( $results )) {
				throw new BackendException( 'command-failed', 'Pipeline execution failed.' );
			}

			if (count( $results ) !== $count) {
				throw new BackendException( 'command-failed', 'Pipeline result count mismatch.' );
			}

			return $results;
		}

		/**
		 * Captures sanitized server identity, preferring PhpRedis 6.3 methods and
		 * using INFO only for fallback identity and safe ancillary fields.
		 *
		 * @return array<string,string>|null
		 */
		public function server_info(): ?array {
			if ($this->redis === null) {
				return null;
			}

			$product = '';
			$version = '';

			try {
				$name_result    = $this->redis->serverName();
				$version_result = $this->redis->serverVersion();
				$product        = is_string( $name_result ) ? strtolower( $name_result ) : '';
				$version        = is_string( $version_result ) ? $version_result : '';
			} catch (\Throwable $e) {
				$product = '';
				$version = '';
			}

			try {
				$info = $this->redis->info();
			} catch (\Throwable $e) {
				$info = false;
			}

			if ( ! is_array( $info ) && ( $product === '' || $version === '' )) {
				return null;
			}

			if ( ! is_array( $info )) {
				$info = array();
			}

			// Fall back to INFO when HELLO identity is unavailable.
			if ($product === '' || $version === '') {
				if (isset( $info['valkey_version'] )) {
					$product = 'valkey';
					$version = (string) $info['valkey_version'];
				} elseif (isset( $info['redis_version'] )) {
					$product = 'redis';
					$version = (string) $info['redis_version'];
				}
			}

			if ( ! in_array( $product, array( 'redis', 'valkey' ), true )) {
				$product = 'unknown';
			}

			$mode = isset( $info['redis_mode'] ) ? strtolower( trim( (string) $info['redis_mode'] ) ) : 'standalone';
			if ( ! in_array( $mode, array( 'standalone', 'cluster', 'sentinel' ), true ) ) {
				$mode = 'unknown';
			}

			$role = isset( $info['role'] ) ? strtolower( trim( (string) $info['role'] ) ) : 'unknown';
			if ( ! in_array( $role, array( 'master', 'primary', 'slave', 'replica', 'sentinel' ), true ) ) {
				$role = 'unknown';
			}

			$identity = array();
			$identity['product']          = $product;
			$identity['version']          = preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/', $version ) === 1 ? $version : '';
			$identity['mode']             = $mode;
			$identity['role']             = $role;
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

			$this->redis            = null;
			$this->script_shas       = array();
			$this->persistent_reuse = false;
		}

		/**
		 * Whether the active connection is reused across PHP requests/process work.
		 */
		public function persistent_reuse(): bool {
			return $this->persistent_reuse;
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
		private function connect_params( Config $config ): array {
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

		/**
		 * Creates a new Redis instance.
		 *
		 * @return \Redis
		 */
		protected function create_redis_instance(): \Redis {
			return new \Redis();
		}

		/**
		 * Returns the installed PhpRedis extension version.
		 *
		 * @return string|false
		 */
		protected function phpredis_version() {
			return phpversion( 'redis' );
		}

		/**
		 * Whether PhpRedis will include the supplied persistent ID in its pool key.
		 *
		 * Stock PhpRedis pooling keys only by endpoint unless the global pool pattern
		 * contains `i`. Falling back to a request connection is safer than reusing a
		 * socket authenticated or selected for another cache identity.
		 */
		protected function persistent_pool_honors_id(): bool {
			$pooling = ini_get( 'redis.pconnect.pooling_enabled' );
			if ($pooling === false || ! filter_var( $pooling, FILTER_VALIDATE_BOOLEAN )) {
				return true;
			}

			$pattern = ini_get( 'redis.pconnect.pool_pattern' );

			return is_string( $pattern ) && strpos( $pattern, 'i' ) !== false;
		}

		/**
		 * Derives a non-reversible pool identity from every connection-affecting value.
		 */
		private function persistent_id( Config $config ): string {
			$canonical = array(
				'scheme'            => $config->scheme(),
				'host'              => $config->scheme() === Config::SCHEME_UNIX ? '' : $config->host(),
				'port'              => $config->scheme() === Config::SCHEME_UNIX ? 0 : $config->port(),
				'path'              => $config->scheme() === Config::SCHEME_UNIX ? $config->path() : '',
				'database'          => $config->database(),
				'namespace_digest'  => $config->namespace_digest(),
				'auth_digest'       => hash( 'sha256', serialize( array( $config->username(), $config->password() ) ) ),
				'tls_digest'        => hash( 'sha256', serialize( $config->tls() ) ),
				'max_retries'       => $config->max_retries(),
				'backoff_algorithm' => $config->backoff_algorithm(),
				'backoff_base'      => $config->backoff_base(),
				'backoff_cap'       => $config->backoff_cap(),
				'tcp_keepalive'     => $config->tcp_keepalive(),
			);

			return 'mcoc:' . hash( 'sha256', serialize( $canonical ) );
		}

		/**
		 * Applies and verifies bounded reliability and wire-format options.
		 *
		 * @throws BackendException When PhpRedis rejects or normalizes an option unexpectedly.
		 */
		private function configure_options( Config $config ): void {
			if ($this->redis === null) {
				throw new BackendException( 'connect-failed', 'Connection option configuration failed.' );
			}

			$options = array(
				array( Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE, 'int' ),
				array( Redis::OPT_COMPRESSION, Redis::COMPRESSION_NONE, 'int' ),
				array( Redis::OPT_PREFIX, '', 'string' ),
				array( Redis::OPT_REPLY_LITERAL, false, 'bool' ),
				array( Redis::OPT_READ_TIMEOUT, $config->read_timeout(), 'float' ),
				array( Redis::OPT_MAX_RETRIES, $config->max_retries(), 'int' ),
				array( Redis::OPT_BACKOFF_ALGORITHM, $this->backoff_algorithm( $config->backoff_algorithm() ), 'int' ),
				array( Redis::OPT_BACKOFF_BASE, $config->backoff_base(), 'int' ),
				array( Redis::OPT_BACKOFF_CAP, $config->backoff_cap(), 'int' ),
			);
			if ($config->scheme() !== Config::SCHEME_UNIX) {
				$options[] = array( Redis::OPT_TCP_KEEPALIVE, $config->tcp_keepalive(), 'bool' );
			}

			try {
				foreach ($options as $option) {
					if ( ! $this->redis->setOption( $option[0], $option[1] )) {
						throw new BackendException( 'connect-failed', 'Connection option configuration failed.' );
					}

					$actual = $this->redis->getOption( $option[0] );
					if ( ! $this->option_matches( $actual, $option[1], $option[2] )) {
						throw new BackendException( 'connect-failed', 'Connection option verification failed.' );
					}
				}
			} catch (BackendException $e) {
				throw $e;
			} catch (\Throwable $e) {
				throw new BackendException( 'connect-failed', 'Connection option configuration failed.', 0, $e );
			}
		}

		/**
		 * Maps a validated config name to a PhpRedis backoff constant.
		 */
		private function backoff_algorithm( string $algorithm ): int {
			$algorithms = array(
				'default'             => Redis::BACKOFF_ALGORITHM_DEFAULT,
				'decorrelated_jitter' => Redis::BACKOFF_ALGORITHM_DECORRELATED_JITTER,
				'full_jitter'         => Redis::BACKOFF_ALGORITHM_FULL_JITTER,
				'equal_jitter'        => Redis::BACKOFF_ALGORITHM_EQUAL_JITTER,
				'exponential'         => Redis::BACKOFF_ALGORITHM_EXPONENTIAL,
				'uniform'             => Redis::BACKOFF_ALGORITHM_UNIFORM,
				'constant'            => Redis::BACKOFF_ALGORITHM_CONSTANT,
			);

			return $algorithms[ $algorithm ];
		}

		/**
		 * @param mixed  $actual
		 * @param mixed  $expected
		 * @param string $type
		 */
		private function option_matches( $actual, $expected, string $type ): bool {
			if ($type === 'int') {
				return (int) $actual === (int) $expected;
			}

			if ($type === 'float') {
				return abs( (float) $actual - (float) $expected ) < 0.000001;
			}

			if ($type === 'bool') {
				return (bool) $actual === (bool) $expected;
			}

			return (string) $actual === (string) $expected;
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
		public static function resolve( int $caller_expire, int $max_ttl ): int {
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
		public static function remaining_from_pttl( int $pttl_ms ): ?int {
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
		public static function to_ms( int $ttl_seconds ): ?int {
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
		public static function header_inline( int $tag, string $payload ): string {
			return self::header( $tag, strlen( $payload ) ) . $payload;
		}

		/**
		 * Encodes a value into the versioned envelope.
		 *
		 * @param mixed $value The value to encode.
		 * @return string
		 * @throws ValueCodecException On an unsupported type or serialization failure.
		 */
		public static function encode( $value ): string {
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
		public static function decode( string $bytes ): array {
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
		private static function int_to_payload( int $value ): string {
			return (string) $value;
		}

		/**
		 * @param string $payload
		 * @return array{0:bool,1:int|false,2:string|null}
		 */
		private static function decode_int( string $payload ): array {
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

		/**
		 * @param string $payload
		 * @return array{0:bool,1:float|false,2:string|null}
		 */
		private static function decode_double( string $payload ): array {
			if (strlen( $payload ) !== 8) {
				return array( false, false, 'decode-double-length' );
			}
			$unpacked = unpack( 'e', $payload );
			if ($unpacked === false) {
				return array( false, false, 'decode-double-invalid' );
			}
			return array( true, $unpacked[1], null );
		}

		/**
		 * @param mixed $value
		 * @return string
		 * @throws ValueCodecException
		 */
		private static function encode_serialized( $value ): string {
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

		/**
		 * @param string $payload
		 * @return array{0:bool,1:mixed,2:string|null}
		 */
		private static function decode_serialized( string $payload ): array {
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
		private static function header( int $tag, int $length ): string {
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
		private static function read_length( string $bytes, int $offset ): int {
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
		private static function write_length( int $length ): string {
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

		public function __construct( string $category, string $message = '' ) {
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
		 * @return void
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
		function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
			global $wp_object_cache;

			return $wp_object_cache->add( $key, $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_add_multiple' )) {
		/**
		 * Adds multiple values to the cache in one call.
		 *
		 * @param array<string|int,mixed> $data   Key/value pairs to add.
		 * @param string                  $group  Optional. The cache group. Default empty.
		 * @param int                     $expire Optional. TTL in seconds. Default 0.
		 * @return bool[] Per-key results.
		 */
		function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
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
		function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
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
		function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
			global $wp_object_cache;

			return $wp_object_cache->set( $key, $data, $group, (int) $expire );
		}
	}

	if ( ! function_exists( 'wp_cache_set_multiple' )) {
		/**
		 * Stores multiple values in the cache in one call.
		 *
		 * @param array<string|int,mixed> $data   Key/value pairs to store.
		 * @param string                  $group  Optional. The cache group. Default empty.
		 * @param int                     $expire Optional. TTL in seconds. Default 0.
		 * @return bool[] Per-key results.
		 */
		function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
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
		function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
			global $wp_object_cache;

			return $wp_object_cache->get( $key, $group, $force, $found );
		}
	}

	if ( ! function_exists( 'wp_cache_get_multiple' )) {
		/**
		 * Retrieves multiple values from the cache in one call.
		 *
		 * @param array<int,string|int> $keys  The cache keys.
		 * @param string                $group Optional. The cache group. Default empty.
		 * @param bool                  $force Optional. Force reads past the runtime tier.
		 * @return array<string,mixed> Per-key values; misses are false.
		 */
		function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
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
		function wp_cache_delete( $key, $group = '' ) {
			global $wp_object_cache;

			return $wp_object_cache->delete( $key, $group );
		}
	}

	if ( ! function_exists( 'wp_cache_delete_multiple' )) {
		/**
		 * Deletes multiple values from the cache in one call.
		 *
		 * @param array<int,string|int> $keys  The cache keys.
		 * @param string                $group Optional. The cache group. Default empty.
		 * @return bool[] Per-key results.
		 */
		function wp_cache_delete_multiple( array $keys, $group = '' ) {
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
		function wp_cache_incr( $key, $offset = 1, $group = '' ) {
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
		function wp_cache_decr( $key, $offset = 1, $group = '' ) {
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
		function wp_cache_flush_group( $group ) {
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
		function wp_cache_supports( $feature ) {
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
			global $wp_object_cache;
			if ( $wp_object_cache instanceof \Mincemeat\ObjectCache\ObjectCache ) {
				return $wp_object_cache->close();
			}
			return true;
		}
	}

	if ( ! function_exists( 'wp_cache_add_global_groups' )) {
		/**
		 * Registers one or more global groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 * @return void
		 */
		function wp_cache_add_global_groups( $groups ) {
			global $wp_object_cache;

			$wp_object_cache->add_global_groups( $groups );
		}
	}

	if ( ! function_exists( 'wp_cache_add_non_persistent_groups' )) {
		/**
		 * Registers one or more non-persistent groups.
		 *
		 * @param string|string[] $groups A group or list of groups.
		 * @return void
		 */
		function wp_cache_add_non_persistent_groups( $groups ) {
			global $wp_object_cache;

			$wp_object_cache->add_non_persistent_groups( $groups );
		}
	}

	if ( ! function_exists( 'wp_cache_switch_to_blog' )) {
		/**
		 * Switches the internal blog ID for non-global groups.
		 *
		 * @param int $blog_id The blog ID.
		 * @return void
		 */
		function wp_cache_switch_to_blog( $blog_id ) {
			global $wp_object_cache;

			$wp_object_cache->switch_to_blog( (int) $blog_id );
		}
	}

	if ( ! function_exists( 'wp_cache_reset' )) {
		/**
		 * Resets internal cache keys. Deprecated; use wp_cache_switch_to_blog().
		 *
		 * @deprecated 3.5.0
		 * @return void
		 */
		function wp_cache_reset() {
			if (function_exists( '_deprecated_function' )) {
				_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
			}

			global $wp_object_cache;

			if ($wp_object_cache instanceof ObjectCache) {
				$wp_object_cache->reset();
			}
		}
	}

	if ( ! class_exists( 'WP_Object_Cache' ) ) {
		class_alias( \Mincemeat\ObjectCache\ObjectCache::class, 'WP_Object_Cache' );
	}
}