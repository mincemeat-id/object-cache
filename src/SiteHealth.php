<?php
/**
 * Site Health utility class for registering and running Mincemeat status tests.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use Redis;

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
