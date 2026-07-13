<?php
/**
 * Read-only public API for the Mincemeat Object Cache.
 *
 * Other plugins should normally use the `wp_cache_*` functions. This class
 * exposes immutable status, capabilities, request metrics, redacted
 * diagnostics, and version information only. It never exposes the raw backend
 * client, credentials, mutable configuration, keys, or cached values.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use Redis;

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
		);

		if ( $cache ) {
			$config = $cache->config();
			if ( $config ) {
				$diagnostics = array_merge( $diagnostics, $config->redacted_diagnostics( $is_public ) );
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
}
