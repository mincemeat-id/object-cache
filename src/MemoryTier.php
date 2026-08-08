<?php
/**
 * Request-local memory tier for the object cache.
 *
 * Owns the request-scoped `$cache` array and the falsey-safe read/write
 * semantics so hot-path and memory behavior land in one small, well-tested
 * collaborator. `ObjectCache` keeps all WordPress-facing behavior and
 * delegates memory reads/writes here. The tier is request-scoped and
 * unbounded by design (growth is freed at request end; eviction is deferred
 * to v1).
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

/**
 * Request-local cache storage keyed by normalized group then storage id.
 *
 * @internal This class is an implementation detail of `ObjectCache`.
 */
final class MemoryTier {

	/**
	 * Request-local cache, keyed by normalized group then storage identifier.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $cache = array();

	/**
	 * Stores a value in request memory, cloning objects.
	 *
	 * @param string $storage_id
	 * @param string $group
	 * @param mixed  $data
	 * @return bool
	 */
	public function set( string $storage_id, string $group, $data ): bool {
		if (is_object( $data )) {
			$data = clone $data;
		}

		$this->cache[ $group ][ $storage_id ] = $data;

		return true;
	}

	/**
	 * Single falsey-safe request-memory read.
	 *
	 * Returns `array( $found, $value )` using the same `isset() ||
	 * array_key_exists()` semantics as `exists()` but in a single probe, so a
	 * cached falsey value (`false`, `0`, `''`, `null`) is a true hit and no
	 * second array index is performed on the hot read path.
	 *
	 * @param string $storage_id
	 * @param string $group
	 * @return array{0:bool,1:mixed} array( $found, $value ).
	 */
	public function read( string $storage_id, string $group ): array {
		if (isset( $this->cache[ $group ][ $storage_id ] )) {
			return array( true, $this->cache[ $group ][ $storage_id ] );
		}

		if (isset( $this->cache[ $group ] ) && array_key_exists( $storage_id, $this->cache[ $group ] )) {
			return array( true, $this->cache[ $group ][ $storage_id ] );
		}

		return array( false, false );
	}

	/**
	 * Whether a storage identifier exists in a group.
	 *
	 * @param string $storage_id
	 * @param string $group
	 * @return bool
	 */
	public function exists( string $storage_id, string $group ): bool {
		return isset( $this->cache[ $group ] )
			&& ( isset( $this->cache[ $group ][ $storage_id ] ) || array_key_exists( $storage_id, $this->cache[ $group ] ) );
	}

	/**
	 * Removes a single storage identifier from a group.
	 *
	 * @param string $storage_id
	 * @param string $group
	 * @return void
	 */
	public function remove( string $storage_id, string $group ): void {
		unset( $this->cache[ $group ][ $storage_id ] );
	}

	/**
	 * Removes an entire group from the request tier.
	 *
	 * @param string $group
	 * @return void
	 */
	public function remove_group( string $group ): void {
		unset( $this->cache[ $group ] );
	}

	/**
	 * Clears the entire request tier.
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->cache = array();
	}

	/**
	 * Returns the number of live entries held in the request tier.
	 *
	 * Computed only on demand for observability; never called on the hot path.
	 *
	 * @return int
	 */
	public function entry_count(): int {
		$count = 0;
		foreach ($this->cache as $group) {
			$count += count( $group );
		}

		return $count;
	}

	/**
	 * Returns the raw groups map for diagnostics and legacy iteration.
	 *
	 * Exposed for `ObjectCache::stats()` and `reset()`, which need the raw
	 * per-group arrays (to serialize sizes and to clear non-global groups).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function groups(): array {
		return $this->cache;
	}
}
