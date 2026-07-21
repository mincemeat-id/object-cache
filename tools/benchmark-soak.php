<?php
/**
 * Repeatable performance and command-count guardrails for hot cache paths.
 *
 * Usage: php tools/benchmark-soak.php [host] [port] [options]
 *
 * Latencies are medians from fixed-size samples. Release evidence is written as
 * a CI artifact and compared only on an identical controlled runner/backend.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

$benchmark_runtime_root = dirname( __DIR__ );
foreach (array_slice( $argv, 1 ) as $benchmark_argument) {
	if (strpos( $benchmark_argument, '--runtime-root=' ) === 0) {
		$benchmark_runtime_root = substr( $benchmark_argument, strlen( '--runtime-root=' ) );
	}
}
$benchmark_runtime_root = realpath( $benchmark_runtime_root );
if ( ! is_string( $benchmark_runtime_root ) || ! is_readable( $benchmark_runtime_root . '/tests/bootstrap.php' )) {
	fwrite( STDERR, "Benchmark error: runtime root does not contain tests/bootstrap.php.\n" );
	exit( 2 );
}

require_once $benchmark_runtime_root . '/tests/bootstrap.php';

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\LuaScripts;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;

const MINCEMEAT_BENCHMARK_SCHEMA_VERSION = 2;
const MINCEMEAT_BENCHMARK_SUITE_VERSION  = 3;
const MINCEMEAT_BENCHMARK_SAMPLES        = 21;
const MINCEMEAT_BENCHMARK_WARMUPS        = 3;
const MINCEMEAT_BENCHMARK_REGRESSION_PCT = 25.0;
const MINCEMEAT_BENCHMARK_NOISE_FLOOR_MS = 2.0;

/**
 * Counts adapter calls that cross the network boundary.
 */
final class MincemeatBenchmarkAdapter extends PhpRedisAdapter {

	/** @var int */
	private $round_trips = 0;
	/** @var int */
	private $commands = 0;
	/** @var int */
	private $connections = 0;

	public function reset_round_trips(): void {
		$this->round_trips = 0;
		$this->commands    = 0;
	}

	public function round_trips(): int {
		return $this->round_trips;
	}

	public function commands(): int {
		return $this->commands;
	}

	public function connections(): int {
		return $this->connections;
	}

	public function connect( Config $config ): void {
		$this->connections++;
		parent::connect( $config );
	}

	public function get( string $key ) {
		$this->round_trips++;
		$this->commands++;
		return parent::get( $key );
	}

	public function mget( array $keys ): array {
		$this->round_trips++;
		$this->commands++;
		return parent::mget( $keys );
	}

	public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false ): bool {
		$this->round_trips++;
		$this->commands++;
		return parent::set( $key, $value, $ttl_ms, $nx, $xx );
	}

	public function del( string $key ): int {
		$this->round_trips++;
		$this->commands++;
		return parent::del( $key );
	}

	public function pipeline( array $commands ): array {
		$this->round_trips++;
		$this->commands += count( $commands );
		return parent::pipeline( $commands );
	}

	public function eval( string $script, array $keys = array(), array $args = array() ) {
		$this->round_trips++;
		$this->commands++;
		return parent::eval( $script, $keys, $args );
	}

	public function server_info(): ?array {
		// PhpRedis obtains product, version, and INFO fields separately.
		$this->round_trips += 3;
		$this->commands    += 3;
		return parent::server_info();
	}
}

/**
 * @return array{cache:ObjectCache,backend:Backend,adapter:MincemeatBenchmarkAdapter,key_space:KeySpace}
 */
function mincemeat_benchmark_context( string $host, int $port, string $namespace ): array {
	$config = new Config(
		array(
			'namespace'       => $namespace,
			'scheme'          => 'tcp',
			'host'            => $host,
			'port'            => $port,
			'database'        => 0,
			'connect_timeout' => 0.5,
			'read_timeout'    => 0.5,
			'max_retries'     => 0,
			'persistent'      => false,
			'max_ttl'         => 3600,
		)
	);
	$key_space = new KeySpace( false, 1 );
	$adapter   = new MincemeatBenchmarkAdapter();
	$backend   = new Backend( $key_space, $adapter );
	$backend->initialize( $config );
	$cache = new ObjectCache( $key_space, $backend );

	if ($cache->state() !== ObjectCache::STATE_PERSISTENT) {
		throw new RuntimeException( 'The benchmark backend did not enter persistent state.' );
	}

	$GLOBALS['wp_object_cache'] = $cache;

	return array(
		'cache'     => $cache,
		'backend'   => $backend,
		'adapter'   => $adapter,
		'key_space' => $key_space,
	);
}

/**
 * Removes exact non-expiring control keys created by a measured sample.
 * Item keys use the benchmark's one-hour maximum TTL and expire naturally.
 *
 * @param array<string,mixed> $context
 */
function mincemeat_benchmark_cleanup( array $context ): void {
	if (
		! isset( $context['adapter'], $context['key_space'], $context['backend'] )
		|| ! $context['backend']->is_persistent()
	) {
		return;
	}

	$keys = array(
		$context['key_space']->namespace_control_key(),
		$context['key_space']->group_control_key( 'default' ),
	);
	for ($i = 0; $i < 100; $i++) {
		$keys[] = $context['key_space']->group_control_key( 'group-' . $i );
	}

	$context['adapter']->del_multiple( $keys );
}

/**
 * @param float[] $values
 */
function mincemeat_benchmark_median( array $values ): float {
	sort( $values, SORT_NUMERIC );
	$count = count( $values );
	$mid   = intdiv( $count, 2 );

	if ($count % 2 === 1) {
		return $values[ $mid ];
	}

	return ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
}

/**
 * Returns a bounded CPU identity for controlled-runner comparison.
 *
 * @return array{model:string,logical_processors:int,architecture:string}
 */
function mincemeat_benchmark_cpu_identity(): array {
	$model      = 'unknown';
	$processors = 0;
	$cpu_info   = is_readable( '/proc/cpuinfo' ) ? (string) file_get_contents( '/proc/cpuinfo' ) : '';
	if ($cpu_info !== '') {
		if (preg_match( '/^model name\s*:\s*(.+)$/mi', $cpu_info, $match ) === 1) {
			$model = trim( $match[1] );
		}
		$processors = preg_match_all( '/^processor\s*:/mi', $cpu_info );
		if ( ! is_int( $processors )) {
			$processors = 0;
		}
	}

	return array(
		'model'              => substr( $model, 0, 160 ),
		'logical_processors' => $processors,
		'architecture'       => php_uname( 'm' ),
	);
}

/**
 * @return array<string,string>
 */
function mincemeat_benchmark_extension_versions(): array {
	$extensions = get_loaded_extensions();
	sort( $extensions, SORT_STRING );
	$versions = array();
	foreach ($extensions as $extension) {
		$version                = phpversion( $extension );
		$versions[ $extension ] = is_string( $version ) ? $version : 'built-in';
	}

	return $versions;
}

/**
 * Returns the source commit without including a workspace path.
 */
function mincemeat_benchmark_source_commit( string $runtime_root ): string {
	$override = getenv( 'MINCEMEAT_BENCHMARK_COMMIT' );
	if (is_string( $override ) && preg_match( '/^[0-9A-Za-z.+_-]{1,128}$/', $override ) === 1) {
		return $override;
	}

	$command = 'git -C ' . escapeshellarg( $runtime_root ) . ' rev-parse HEAD 2>/dev/null';
	$commit  = shell_exec( $command );
	$commit  = is_string( $commit ) ? trim( $commit ) : '';

	return preg_match( '/^[a-f0-9]{40}$/', $commit ) === 1 ? $commit : 'working-tree';
}

/**
 * @param array<string,string>|null $server_info
 * @return array<string,mixed>
 */
function mincemeat_benchmark_environment( ?array $server_info ): array {
	$loaded_ini  = php_ini_loaded_file();
	$scanned_ini = php_ini_scanned_files();
	$image       = getenv( 'MINCEMEAT_BENCHMARK_BACKEND_IMAGE_DIGEST' );
	$runner      = getenv( 'MINCEMEAT_BENCHMARK_RUNNER' );

	return array(
		'runner_identity'     => is_string( $runner ) && $runner !== '' ? substr( $runner, 0, 160 ) : 'local-uncontrolled',
		'operating_system'    => php_uname( 's' ) . ' ' . php_uname( 'r' ),
		'cpu'                 => mincemeat_benchmark_cpu_identity(),
		'php'                 => array(
			'version'           => PHP_VERSION,
			'sapi'              => PHP_SAPI,
			'loaded_ini'        => is_string( $loaded_ini ) ? $loaded_ini : 'none',
			'loaded_ini_sha256' => is_string( $loaded_ini ) && is_readable( $loaded_ini ) ? hash_file( 'sha256', $loaded_ini ) : 'none',
			'scanned_ini'       => is_string( $scanned_ini ) ? array_values( array_filter( array_map( 'trim', explode( ',', $scanned_ini ) ) ) ) : array(),
			'ini_values'        => array(
				'opcache.enable_cli'             => (string) ini_get( 'opcache.enable_cli' ),
				'opcache.jit'                    => (string) ini_get( 'opcache.jit' ),
				'pcov.enabled'                   => (string) ini_get( 'pcov.enabled' ),
				'redis.pconnect.pooling_enabled' => (string) ini_get( 'redis.pconnect.pooling_enabled' ),
				'redis.pconnect.pool_pattern'    => (string) ini_get( 'redis.pconnect.pool_pattern' ),
				'xdebug.mode'                    => (string) ini_get( 'xdebug.mode' ),
			),
			'extensions'        => mincemeat_benchmark_extension_versions(),
		),
		'backend'             => array(
			'product'      => $server_info['product'] ?? 'unknown',
			'version'      => $server_info['version'] ?? 'unknown',
			'image_digest' => is_string( $image ) && $image !== '' ? substr( $image, 0, 256 ) : 'unreported',
		),
	);
}

/**
 * @return array<string,array<string,mixed>>
 */
function mincemeat_benchmark_definitions(): array {
	$batch = array();
	for ($i = 0; $i < 100; $i++) {
		$batch[ 'batch-key-' . $i ] = 'value-' . $i;
	}
	$batch_keys = array_keys( $batch );
	$delete_batch = array();
	for ($i = 0; $i < 2500; $i++) {
		$delete_batch[ 'delete-key-' . $i ] = 'value-' . $i;
	}

	return array(
		'cold_bootstrap_get_miss' => array(
			'label'       => 'Cold connect/bootstrap + first GET miss',
			'iterations'  => 1,
			'round_trips' => 3,
			'commands'    => 4,
			'connections' => 1,
			'cold'        => true,
			'run'         => function () {
				wp_cache_get( 'cold-missing', 'default', true );
			},
		),
		'cold_existing_get_hit' => array(
			'label'       => 'Cold connect + first GET hit',
			'iterations'  => 1,
			'round_trips' => 2,
			'commands'    => 2,
			'connections' => 1,
			'cold'        => true,
			'prepare'     => function (array $context) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set( 'cold-hit', 'value', 'default' );
			},
			'run'         => function () {
				wp_cache_get( 'cold-hit', 'default', true );
			},
		),
		'cold_existing_get_miss' => array(
			'label'       => 'Cold connect + first GET miss (existing controls)',
			'iterations'  => 1,
			'round_trips' => 2,
			'commands'    => 2,
			'connections' => 1,
			'cold'        => true,
			'prepare'     => function (array $context) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set( 'control-seed', 'value', 'default' );
			},
			'run'         => function () {
				wp_cache_get( 'cold-existing-missing', 'default', true );
			},
		),
		'cold_existing_set' => array(
			'label'       => 'Cold connect + first SET (existing controls)',
			'iterations'  => 1,
			'round_trips' => 2,
			'commands'    => 2,
			'connections' => 1,
			'cold'        => true,
			'prepare'     => function (array $context) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set( 'control-seed', 'value', 'default' );
			},
			'run'         => function () {
				wp_cache_set( 'cold-set', 'value', 'default' );
			},
		),
		'cold_first_group' => array(
			'label'       => 'Cold connect + first SET in a new group',
			'iterations'  => 1,
			'round_trips' => 3,
			'commands'    => 3,
			'connections' => 1,
			'cold'        => true,
			'prepare'     => function (array $context) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set( 'control-seed', 'value', 'default' );
			},
			'run'         => function () {
				wp_cache_set( 'cold-first-group', 'value', 'first-group' );
			},
		),
		'request_memory_hit' => array(
			'label'       => 'Request-memory hit',
			'iterations'  => 1000,
			'round_trips' => 0,
			'commands'    => 0,
			'setup'       => function (array $context) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set( 'memory-hit', 'value', 'default' );
			},
			'run'         => function () {
				for ($i = 0; $i < 1000; $i++) {
					wp_cache_get( 'memory-hit', 'default' );
				}
			},
		),
		'backend_hit' => array(
			'label'       => 'Backend hit (forced)',
			'iterations'  => 100,
			'round_trips' => 100,
			'commands'    => 100,
			'setup'       => function (array $context) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set( 'backend-hit', 'value', 'default' );
			},
			'run'         => function () {
				for ($i = 0; $i < 100; $i++) {
					wp_cache_get( 'backend-hit', 'default', true );
				}
			},
		),
		'backend_miss' => array(
			'label'       => 'Backend miss (forced)',
			'iterations'  => 100,
			'round_trips' => 100,
			'commands'    => 100,
			'setup'       => function (array $context) {
				$context['backend']->namespace_token();
				$context['backend']->group_token( 'default' );
			},
			'run'         => function () {
				for ($i = 0; $i < 100; $i++) {
					wp_cache_get( 'missing-' . $i, 'default', true );
				}
			},
		),
		'get_multiple' => array(
			'label'       => 'get_multiple (100 keys)',
			'iterations'  => 50,
			'round_trips' => 50,
			'commands'    => 50,
			'setup'       => function (array $context) use ($batch) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set_multiple( $batch, 'default' );
			},
			'run'         => function () use ($batch_keys) {
				for ($i = 0; $i < 50; $i++) {
					wp_cache_get_multiple( $batch_keys, 'default', true );
				}
			},
		),
		'set_multiple' => array(
			'label'       => 'set_multiple (100 keys)',
			'iterations'  => 25,
			'round_trips' => 25,
			'commands'    => 2500,
			'setup'       => function (array $context) {
				$context['backend']->namespace_token();
				$context['backend']->group_token( 'default' );
			},
			'run'         => function () use ($batch) {
				for ($i = 0; $i < 25; $i++) {
					wp_cache_set_multiple( $batch, 'default' );
				}
			},
		),
		'delete_multiple' => array(
			'label'       => 'delete_multiple (100 keys)',
			'iterations'  => 25,
			'round_trips' => 25,
			'commands'    => 2500,
			'setup'       => function (array $context) use ($delete_batch) {
				$GLOBALS['wp_object_cache'] = $context['cache'];
				wp_cache_set_multiple( $delete_batch, 'default' );
			},
			'run'         => function () {
				for ($i = 0; $i < 25; $i++) {
					$keys = array();
					for ($j = 0; $j < 100; $j++) {
						$keys[] = 'delete-key-' . ( $i * 100 + $j );
					}
					wp_cache_delete_multiple( $keys, 'default' );
				}
			},
		),
		'group_token_resolution' => array(
			'label'       => 'Cold group token resolution',
			'iterations'  => 100,
			'round_trips' => 100,
			'commands'    => 100,
			'run'         => function (array $context) {
				for ($i = 0; $i < 100; $i++) {
					$context['backend']->group_token( 'group-' . $i );
				}
			},
		),
		'namespace_flush' => array(
			'label'       => 'Namespace flush',
			'iterations'  => 100,
			'round_trips' => 100,
			'commands'    => 100,
			'run'         => function () {
				for ($i = 0; $i < 100; $i++) {
					wp_cache_flush();
				}
			},
		),
		'group_flush' => array(
			'label'       => 'Group flush',
			'iterations'  => 100,
			'round_trips' => 100,
			'commands'    => 100,
			'run'         => function () {
				for ($i = 0; $i < 100; $i++) {
					wp_cache_flush_group( 'default' );
				}
			},
		),
		'failed_connection_circuit_open' => array(
			'label'       => 'Failed connection / circuit-open path',
			'iterations'  => 1000,
			'round_trips' => 0,
			'commands'    => 0,
			'context'     => 'failed',
			'run'         => function () {
				for ($i = 0; $i < 1000; $i++) {
					wp_cache_get( 'fallback-key', 'default' );
				}
			},
		),
		'lua_eval' => array(
			'label'       => 'Numeric Lua EVAL (missing key)',
			'iterations'  => 100,
			'round_trips' => null,
			'commands'    => null,
			'context'     => 'raw-eval',
			'run'         => function (array $context) {
				for ($i = 0; $i < 100; $i++) {
					mincemeat_benchmark_eval( $context['redis'], $context['key'] );
				}
			},
		),
		'lua_evalsha' => array(
			'label'       => 'Numeric Lua preloaded EVALSHA (missing key)',
			'iterations'  => 100,
			'round_trips' => null,
			'commands'    => null,
			'context'     => 'raw-evalsha',
			'run'         => function (array $context) {
				for ($i = 0; $i < 100; $i++) {
					mincemeat_benchmark_evalsha( $context['redis'], $context['sha'], $context['key'] );
				}
			},
		),
		'soak' => array(
			'label'       => 'Mixed set/get/group-flush soak',
			'iterations'  => 1000,
			'round_trips' => 1003,
			'commands'    => 1003,
			'setup'       => function (array $context) {
				$context['backend']->namespace_token();
				$context['backend']->group_token( 'default' );
			},
			'run'         => function () {
				for ($i = 0; $i < 1000; $i++) {
					$key = 'soak-key-' . ( $i % 50 );
					wp_cache_set( $key, 'value-' . $i, 'default' );
					wp_cache_get( $key, 'default' );
					if ($i > 0 && $i % 250 === 0) {
						wp_cache_flush_group( 'default' );
					}
				}
			},
		),
	);
}

/**
 * Runs and validates the raw EVAL comparison command.
 *
 * @return array<int,mixed>
 */
function mincemeat_benchmark_eval( Redis $redis, string $key ): array {
	try {
		$result = $redis->eval( LuaScripts::INCR_DECR, array( $key, '1' ), 1 );
	} catch (Throwable $e) {
		throw new RuntimeException( 'The raw EVAL benchmark command failed.' );
	}

	if ( ! is_array( $result ) || ( $result[0] ?? null ) !== LuaScripts::RESULT_MISSING) {
		throw new RuntimeException( 'The raw EVAL benchmark returned an unexpected result.' );
	}

	return $result;
}

/**
 * Runs and validates the raw EVALSHA comparison command.
 *
 * @return array<int,mixed>
 */
function mincemeat_benchmark_evalsha( Redis $redis, string $sha, string $key ): array {
	try {
		$result = $redis->evalSha( $sha, array( $key, '1' ), 1 );
	} catch (Throwable $e) {
		throw new RuntimeException( 'The raw EVALSHA benchmark command failed.' );
	}

	if ( ! is_array( $result ) || ( $result[0] ?? null ) !== LuaScripts::RESULT_MISSING) {
		throw new RuntimeException( 'The raw EVALSHA benchmark returned an unexpected result.' );
	}

	return $result;
}

/**
 * @return array<string,mixed>
 */
function mincemeat_benchmark_create_context( string $type, string $host, int $port, string $namespace ): array {
	if ($type === 'failed') {
		$config = new Config(
			array(
				'namespace'       => $namespace,
				'host'            => '127.0.0.1',
				'port'            => 1,
				'connect_timeout' => 0.05,
				'read_timeout'    => 0.05,
				'max_retries'     => 0,
			)
		);
		$key_space = new KeySpace( false, 1 );
		$adapter   = new MincemeatBenchmarkAdapter();
		$backend   = new Backend( $key_space, $adapter );
		$old_error_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
		$backend->initialize( $config );
		ini_set( 'error_log', $old_error_log );
		$cache = new ObjectCache( $key_space, $backend );
		$GLOBALS['wp_object_cache'] = $cache;

		if ($cache->state() !== ObjectCache::STATE_RUNTIME_ONLY) {
			throw new RuntimeException( 'The failed-connection fixture unexpectedly connected.' );
		}

		return array( 'cache' => $cache, 'backend' => $backend, 'adapter' => $adapter, 'key_space' => $key_space );
	}

	if ($type === 'raw-eval' || $type === 'raw-evalsha') {
		$redis = new Redis();
		try {
			$connected = $redis->connect( $host, $port, 0.5, null, 0, 0.5 );
		} catch (Throwable $e) {
			throw new RuntimeException( 'Unable to connect the raw Lua benchmark client.' );
		}
		if ( ! $connected) {
			throw new RuntimeException( 'Unable to connect the raw Lua benchmark client.' );
		}
		$context = array( 'redis' => $redis, 'key' => $namespace . '-missing-lua-key' );
		if ($type === 'raw-evalsha') {
			try {
				$sha = $redis->script( 'load', LuaScripts::INCR_DECR );
			} catch (Throwable $e) {
				throw new RuntimeException( 'Unable to load the numeric Lua benchmark script.' );
			}
			if ( ! is_string( $sha ) || ! hash_equals( sha1( LuaScripts::INCR_DECR ), strtolower( $sha ) )) {
				throw new RuntimeException( 'Unable to load the numeric Lua benchmark script.' );
			}
			$context['sha'] = $sha;
		}
		return $context;
	}

	return mincemeat_benchmark_context( $host, $port, $namespace );
}

/**
 * @param array<string,array<string,mixed>> $definitions
 * @return array{results:array<string,array<string,mixed>>,guardrail_failures:string[]}
 */
function mincemeat_benchmark_run( array $definitions, string $host, int $port, bool $quiet ): array {
	$results            = array();
	$guardrail_failures = array();
	$run_id             = bin2hex( random_bytes( 6 ) );

	foreach ($definitions as $key => $definition) {
		$samples       = array();
		$round_trips   = array();
		$commands      = array();
		$connections   = array();
		$total_samples = MINCEMEAT_BENCHMARK_WARMUPS + MINCEMEAT_BENCHMARK_SAMPLES;

		for ($sample = 0; $sample < $total_samples; $sample++) {
			$type      = isset( $definition['context'] ) ? (string) $definition['context'] : 'cache';
			$namespace = 'bench-' . $run_id . '-' . $key . '-' . $sample;
			$cold      = ! empty( $definition['cold'] );

			if (isset( $definition['prepare'] )) {
				$preparation = mincemeat_benchmark_create_context( $type, $host, $port, $namespace );
				$definition['prepare']( $preparation );
				if (isset( $preparation['backend'] )) {
					$preparation['backend']->close();
				}
			}

			if ($cold) {
				gc_collect_cycles();
				$start   = hrtime( true );
				$context = mincemeat_benchmark_create_context( $type, $host, $port, $namespace );
			} else {
				$context = mincemeat_benchmark_create_context( $type, $host, $port, $namespace );
			}

			if (isset( $definition['setup'] )) {
				$definition['setup']( $context );
			}
			if (isset( $context['adapter'] ) && ! $cold) {
				$context['adapter']->reset_round_trips();
			}

			if ( ! $cold) {
				gc_collect_cycles();
				$start = hrtime( true );
			}
			$definition['run']( $context );
			$elapsed_ms = ( hrtime( true ) - $start ) / 1000000;

			if ($sample >= MINCEMEAT_BENCHMARK_WARMUPS) {
				$samples[] = round( $elapsed_ms, 3 );
				if (isset( $context['adapter'] )) {
					$round_trips[] = $context['adapter']->round_trips();
					$commands[]    = $context['adapter']->commands();
					$connections[] = $cold ? $context['adapter']->connections() : 0;
				}
			}

			mincemeat_benchmark_cleanup( $context );
		}

		$actual_round_trips   = count( $round_trips ) > 0 ? $round_trips[0] : null;
		$expected_round_trips = $definition['round_trips'];
		$actual_commands      = count( $commands ) > 0 ? $commands[0] : null;
		$expected_commands    = $definition['commands'] ?? null;
		$actual_connections   = count( $connections ) > 0 ? $connections[0] : null;
		$expected_connections = $definition['connections'] ?? null;
		$unexpected_round_trips = $expected_round_trips !== null
			? array_values( array_unique( array_filter( $round_trips, function ($count) use ($expected_round_trips) {
				return $count !== $expected_round_trips;
			} ) ) )
			: array();
		if (count( $unexpected_round_trips ) > 0) {
			$guardrail_failures[] = sprintf(
				'%s used unexpected backend round-trip counts (%s); expected %d in every sample.',
				$definition['label'],
				implode( ', ', $unexpected_round_trips ),
				$expected_round_trips
			);
		}
		$unexpected_commands = $expected_commands !== null
			? array_values( array_unique( array_filter( $commands, function ($count) use ($expected_commands) {
				return $count !== $expected_commands;
			} ) ) )
			: array();
		if (count( $unexpected_commands ) > 0) {
			$guardrail_failures[] = sprintf(
				'%s used unexpected backend command counts (%s); expected %d in every sample.',
				$definition['label'],
				implode( ', ', $unexpected_commands ),
				$expected_commands
			);
		}
		$unexpected_connections = $expected_connections !== null
			? array_values( array_unique( array_filter( $connections, function ($count) use ($expected_connections) {
				return $count !== $expected_connections;
			} ) ) )
			: array();
		if (count( $unexpected_connections ) > 0) {
			$guardrail_failures[] = sprintf(
				'%s used unexpected connection counts (%s); expected %d in every sample.',
				$definition['label'],
				implode( ', ', $unexpected_connections ),
				$expected_connections
			);
		}

		$median = round( mincemeat_benchmark_median( $samples ), 3 );
		$results[ $key ] = array(
			'label'                        => $definition['label'],
			'iterations'                   => $definition['iterations'],
			'median_ms'                    => $median,
			'samples_ms'                   => $samples,
			'backend_round_trips'          => $actual_round_trips,
			'backend_round_trip_samples'   => count( $round_trips ) > 0 ? $round_trips : null,
			'expected_backend_round_trips' => $expected_round_trips,
			'backend_commands'             => $actual_commands,
			'backend_command_samples'      => count( $commands ) > 0 ? $commands : null,
			'expected_backend_commands'    => $expected_commands,
			'connections'                  => $actual_connections,
			'connection_samples'           => count( $connections ) > 0 ? $connections : null,
			'expected_connections'         => $expected_connections,
		);

		if ( ! $quiet) {
			$trip_display    = $actual_round_trips === null ? 'n/a' : (string) $actual_round_trips;
			$command_display = $actual_commands === null ? 'n/a' : (string) $actual_commands;
			echo sprintf( "%-52s %10.3f ms  commands/trips: %s/%s\n", $definition['label'], $median, $command_display, $trip_display );
		}
	}

	return array( 'results' => $results, 'guardrail_failures' => $guardrail_failures );
}

/**
 * @param array<string,mixed> $report
 * @return string[]
 */
function mincemeat_benchmark_compare( array $report, string $baseline_file, bool $quiet ): array {
	if ( ! is_readable( $baseline_file )) {
		throw new RuntimeException( 'Baseline file not found. Run with --save-baseline first.' );
	}

	$baseline = json_decode( (string) file_get_contents( $baseline_file ), true );
	if ( ! is_array( $baseline ) || ( $baseline['schema_version'] ?? null ) !== MINCEMEAT_BENCHMARK_SCHEMA_VERSION ) {
		throw new RuntimeException( 'Baseline file has an unsupported schema.' );
	}
	if (( $baseline['suite_version'] ?? null ) !== MINCEMEAT_BENCHMARK_SUITE_VERSION) {
		throw new RuntimeException( 'Baseline suite version differs; save a fresh baseline intentionally.' );
	}
	if (( $baseline['environment'] ?? null ) !== $report['environment']) {
		throw new RuntimeException( 'Baseline environment differs from the current PHP, PhpRedis, or backend version.' );
	}
	if (( $baseline['configuration'] ?? null ) !== $report['configuration']) {
		throw new RuntimeException( 'Baseline benchmark configuration differs; save a fresh baseline intentionally.' );
	}
	if (array_keys( $baseline['benchmarks'] ?? array() ) !== array_keys( $report['benchmarks'] )) {
		throw new RuntimeException( 'Baseline benchmark set differs; save a fresh baseline intentionally.' );
	}

	$failures = array();
	if ( ! $quiet) {
		echo sprintf(
			"\nComparison (regression threshold: %.0f%%, %.2f ms noise floor)\n",
			MINCEMEAT_BENCHMARK_REGRESSION_PCT,
			MINCEMEAT_BENCHMARK_NOISE_FLOOR_MS
		);
		echo sprintf( "%-42s %11s %11s %9s %12s\n", 'Metric', 'Baseline', 'Current', 'Change', 'Status' );
	}

	foreach ($report['benchmarks'] as $key => $current) {
		$base = $baseline['benchmarks'][ $key ];
		if (
			( $base['label'] ?? null ) !== $current['label']
			|| ( $base['iterations'] ?? null ) !== $current['iterations']
			|| ( $base['expected_backend_round_trips'] ?? null ) !== $current['expected_backend_round_trips']
			|| ( $base['expected_backend_commands'] ?? null ) !== $current['expected_backend_commands']
			|| ( $base['expected_connections'] ?? null ) !== $current['expected_connections']
		) {
			throw new RuntimeException( 'Baseline workload metadata differs for ' . $current['label'] . '.' );
		}
		if ( ! isset( $base['median_ms'] ) || ! is_numeric( $base['median_ms'] )) {
			throw new RuntimeException( 'Baseline latency is invalid for ' . $current['label'] . '.' );
		}
		$base_ms    = (float) $base['median_ms'];
		if ( ! is_finite( $base_ms ) || $base_ms <= 0.0) {
			throw new RuntimeException( 'Baseline latency must be finite and positive for ' . $current['label'] . '.' );
		}
		$current_ms = (float) $current['median_ms'];
		$delta_ms   = $current_ms - $base_ms;
		$pct        = $base_ms > 0.0 ? ( $delta_ms / $base_ms ) * 100 : 0.0;
		$regressed  = $pct > MINCEMEAT_BENCHMARK_REGRESSION_PCT && $delta_ms > MINCEMEAT_BENCHMARK_NOISE_FLOOR_MS;
		$status     = $regressed ? 'REGRESSION' : ( $pct < -MINCEMEAT_BENCHMARK_REGRESSION_PCT ? 'FASTER' : 'STABLE' );
		if ($regressed) {
			$failures[] = sprintf( '%s regressed by %.1f%% (%.3f ms).', $current['label'], $pct, $delta_ms );
		}
		if ( ! $quiet) {
			echo sprintf( "%-42s %8.3f ms %8.3f ms %+8.1f%% %12s\n", $current['label'], $base_ms, $current_ms, $pct, $status );
		}
	}

	return $failures;
}

$save_baseline = false;
$compare       = false;
$json_only     = false;
$skip_guardrails = false;
$output_file   = '';
$run_label     = 'local';
$positionals   = array();

foreach (array_slice( $argv, 1 ) as $argument) {
	if ($argument === '--save-baseline') {
		$save_baseline = true;
	} elseif ($argument === '--compare') {
		$compare = true;
	} elseif ($argument === '--json') {
		$json_only = true;
	} elseif ($argument === '--skip-guardrails') {
		$skip_guardrails = true;
	} elseif (strpos( $argument, '--output=' ) === 0) {
		$output_file = substr( $argument, strlen( '--output=' ) );
	} elseif (strpos( $argument, '--label=' ) === 0) {
		$run_label = substr( $argument, strlen( '--label=' ) );
	} elseif (strpos( $argument, '--runtime-root=' ) === 0) {
		continue;
	} else {
		$positionals[] = $argument;
	}
}

if ($save_baseline && $compare) {
	fwrite( STDERR, "Choose either --save-baseline or --compare, not both.\n" );
	exit( 2 );
}

$host = $positionals[0] ?? '127.0.0.1';
$port = (int) ( $positionals[1] ?? 6383 );

try {
	$probe = mincemeat_benchmark_context( $host, $port, 'bench-probe-' . bin2hex( random_bytes( 4 ) ) );
	$info  = $probe['cache']->server_info();

	if ( ! $json_only) {
		echo "Mincemeat Object Cache performance guardrails\n";
		echo sprintf(
			"PHP %s; PhpRedis %s; backend %s %s\n\n",
			PHP_VERSION,
			phpversion( 'redis' ) ?: 'unknown',
			$info['product'] ?? 'unknown',
			$info['version'] ?? 'unknown'
		);
	}

	$run = mincemeat_benchmark_run( mincemeat_benchmark_definitions(), $host, $port, $json_only );
	if ($skip_guardrails) {
		$run['guardrail_failures'] = array();
	}
	$report = array(
		'schema_version' => MINCEMEAT_BENCHMARK_SCHEMA_VERSION,
		'suite_version'  => MINCEMEAT_BENCHMARK_SUITE_VERSION,
		'artifact'       => array(
			'label'                 => preg_match( '/^[0-9A-Za-z.+_-]{1,80}$/', $run_label ) === 1 ? $run_label : 'invalid-label',
			'generated_at_utc'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source_commit'         => mincemeat_benchmark_source_commit( $benchmark_runtime_root ),
			'runtime_version'       => Api::IMPLEMENTATION_VERSION,
			'runtime_dropin_sha256' => hash_file( 'sha256', $benchmark_runtime_root . '/stubs/object-cache.php' ),
			'harness_sha256'        => hash_file( 'sha256', __FILE__ ),
		),
		'environment'    => mincemeat_benchmark_environment( $info ),
		'configuration'  => array(
			'samples'                 => MINCEMEAT_BENCHMARK_SAMPLES,
			'warmups'                 => MINCEMEAT_BENCHMARK_WARMUPS,
			'warmup_policy'            => 'discard-first-3-per-workload',
			'statistic'                => 'median',
			'regression_threshold_pct' => MINCEMEAT_BENCHMARK_REGRESSION_PCT,
			'noise_floor_ms'          => MINCEMEAT_BENCHMARK_NOISE_FLOOR_MS,
		),
		'benchmarks'     => $run['results'],
		'guardrails'     => array(
			'enforced' => ! $skip_guardrails,
			'status'   => count( $run['guardrail_failures'] ) === 0 ? 'pass' : 'fail',
			'failures' => $run['guardrail_failures'],
		),
		'comparisons'    => array(
			'evalsha_vs_eval_pct' => round(
				( ( $run['results']['lua_evalsha']['median_ms'] / $run['results']['lua_eval']['median_ms'] ) - 1 ) * 100,
				1
			),
		),
	);

	$failures      = $run['guardrail_failures'];
	$baseline_file = __DIR__ . '/../tests/benchmarks-baseline.json';
	$encoded_report = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	if ( ! is_string( $encoded_report )) {
		throw new RuntimeException( 'Unable to encode the benchmark report.' );
	}
	if ($output_file !== '') {
		$output_dir = dirname( $output_file );
		if ( ! is_dir( $output_dir ) || file_put_contents( $output_file, $encoded_report . "\n" ) === false) {
			throw new RuntimeException( 'Unable to write the benchmark artifact.' );
		}
	}

	if ( ! $json_only) {
		echo sprintf(
			"\nPreloaded EVALSHA versus EVAL: %+.1f%%\n",
			$report['comparisons']['evalsha_vs_eval_pct']
		);
	}

	if ($save_baseline && count( $failures ) === 0) {
		if (file_put_contents( $baseline_file, $encoded_report . "\n" ) === false) {
			throw new RuntimeException( 'Unable to write the local benchmark baseline.' );
		}
		if ( ! $json_only) {
			echo "\nSaved local baseline to tests/benchmarks-baseline.json\n";
		}
	} elseif ($compare) {
		$failures = array_merge( $failures, mincemeat_benchmark_compare( $report, $baseline_file, $json_only ) );
	}

	if ($json_only) {
		echo $encoded_report . "\n";
	}

	if (count( $failures ) > 0) {
		foreach ($failures as $failure) {
			fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
		}
		exit( 1 );
	}
} catch (Throwable $e) {
	fwrite( STDERR, 'Benchmark error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}
