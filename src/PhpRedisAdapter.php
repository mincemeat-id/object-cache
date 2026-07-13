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
	public function connect( Config $config ): void {
		if ( ! class_exists( Redis::class )) {
			throw new BackendException( 'missing-extension', 'The PhpRedis extension is not available.' );
		}

		if ($config->scheme() === Config::SCHEME_TLS) {
			$phpredis_version = phpversion( 'redis' );
			if ( ! $phpredis_version || version_compare( $phpredis_version, '5.3.0', '<' ) ) {
				throw new BackendException( 'missing-extension', 'PhpRedis >= 5.3.0 is required for TLS connection contexts.' );
			}
		}

		$this->redis = $this->create_redis_instance();

		$connected     = false;
		$persistent_id = '';

		if ($config->persistent()) {
			$tls_non_secret = array();
			$raw_tls        = $config->tls();
			foreach ( $raw_tls as $key => $val ) {
				if ( in_array( $key, array( 'local_pk', 'passphrase' ), true ) ) {
					continue;
				}
				if ( is_scalar( $val ) ) {
					$tls_non_secret[ $key ] = $val;
				}
			}

			$canonical     = array(
				'scheme'           => $config->scheme(),
				'host'             => $config->scheme() === Config::SCHEME_UNIX ? '' : $config->host(),
				'port'             => $config->scheme() === Config::SCHEME_UNIX ? 0 : $config->port(),
				'path'             => $config->scheme() === Config::SCHEME_UNIX ? $config->path() : '',
				'database'         => $config->database(),
				'namespace_digest' => $config->namespace_digest(),
				'username'         => $config->username(),
				'tls_non_secret'   => $tls_non_secret,
			);
			$persistent_id = 'mcoc:' . hash( 'sha256', serialize( $canonical ) );
		}

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
	 * Runs a Lua script via EVAL.
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

		return $this->redis->eval( $script, array_merge( $keys, $args ), count( $keys ) );
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
		$count = 0;

		foreach ($commands as $cmd) {
			call_user_func_array( array( $pipe, $cmd[0] ), $cmd[1] );
			++$count;
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
}
