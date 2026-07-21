<?php
/**
 * PhpRedis adapter: a narrow wrapper around the \Redis extension exposing
 * only the commands the cache needs. PhpRedis serialization, compression, and
 * prefix options are disabled so the plugin owns the wire format.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache;

use Redis;

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
		$this->script_shas = array();

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

		$identity = array();
		$identity['product']          = $product;
		$identity['version']          = preg_match( '/^[0-9][0-9A-Za-z.+_-]{0,63}$/', $version ) === 1 ? $version : '';
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
		$this->script_shas = array();
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
