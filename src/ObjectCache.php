<?php
/**
 * Mincemeat Object Cache runtime implementation.
 *
 * Phase 1: WordPress 6.9-compatible request-local in-memory cache.
 * Phase 3: persistent Redis/Valkey backend integration with request-local
 *          memory tier, circuit breaker, and TTL application.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

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
	public function __construct( ?KeySpace $key_space = null, ?Backend $backend = null ) {
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
	public function attach_backend( Backend $backend ): void {
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

		$ns_tok  = $this->backend->namespace_token();
		$grp_tok = $this->backend->group_token( $group );
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
		$pipeline_results = $this->backend->set_conditional_pipeline( $entries );
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
			$raw_values = $this->backend->mget( $failed_backend_keys );
			$this->backend_time += ( microtime( true ) - $start ) * 1000000;

			if ($this->sync_state() && is_array( $raw_values )) {
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

		$ns_tok  = $this->backend->namespace_token();
		$grp_tok = $this->backend->group_token( $group );
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
		$pipeline_results = $this->backend->set_pipeline( $entries );
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

		$ns_tok  = $this->backend->namespace_token();
		$grp_tok = $this->backend->group_token( $group );

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
		$pipeline_results = $this->backend->del_pipeline( $backend_keys );
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
	 * @param bool   $found
	 * @param string $storage_id
	 * @return mixed|false
	 */
	private function persistent_get( $key, string $group, bool $force, &$found, string $storage_id ) {
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
	 *
	 * @param mixed  $key
	 * @param string $group
	 * @param string $storage_id
	 * @return bool
	 */
	private function persistent_delete( $key, string $group, string $storage_id ): bool {
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
	 *
	 * @param string    $storage_id
	 * @param string    $group
	 * @param bool      $found
	 * @return mixed|false
	 */
	private function runtime_fallback_get( string $storage_id, string $group, &$found ) {
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
	private function persistent_delta( $key, int $offset, string $group, string $storage_id ) {
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
