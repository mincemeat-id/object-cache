<?php
/**
 * Global WordPress object-cache function faade.
 *
 * Signatures match WordPress 6.9's wp-includes/cache.php exactly: untyped,
 * call-by-reference $found, and default group/expire behavior. This file is
 * included by the drop-in (and by the test bootstrap). It defines nothing
 * until the runtime ObjectCache class is available.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

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
	 * @deprecated 1.0.0
	 * @return void
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

if ( ! class_exists( 'WP_Object_Cache' ) ) {
	class_alias( \Mincemeat\ObjectCache\ObjectCache::class, 'WP_Object_Cache' );
}
