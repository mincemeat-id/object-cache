<?php
/**
 * Records request-memory tier growth as before/after memory evidence.
 *
 * Usage: php tools/benchmark-memory.php [host] [port]
 *        --label=... --runtime-root=... [--output=file.json]
 *
 * This is a companion to tools/benchmark-soak.php and is intentionally
 * non-gating: it measures peak-usage growth across a representative soak of
 * request-memory writes so RC4 can report before/after *memory* alongside
 * time. It never uses broad keyspace operations; the soak writes only to a
 * non-persistent group, so nothing crosses the network during the measured
 * phase.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

$memory_runtime_root = dirname( __DIR__ );
foreach (array_slice( $argv, 1 ) as $memory_argument) {
	if (strpos( $memory_argument, '--runtime-root=' ) === 0) {
		$memory_runtime_root = substr( $memory_argument, strlen( '--runtime-root=' ) );
	}
}
$memory_runtime_root = realpath( $memory_runtime_root );
if ( ! is_string( $memory_runtime_root ) || ! is_readable( $memory_runtime_root . '/tests/bootstrap.php' )) {
	fwrite( STDERR, "Memory benchmark error: runtime root does not contain tests/bootstrap.php.\n" );
	exit( 2 );
}

require_once $memory_runtime_root . '/tests/bootstrap.php';

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;

const MINCEMEAT_MEMORY_SCHEMA_VERSION = 1;
const MINCEMEAT_MEMORY_ENTRIES        = 5000;
const MINCEMEAT_MEMORY_VALUE_BYTES    = 64;
const MINCEMEAT_MEMORY_GROUP          = 'memsoak';

/**
 * @return array{cache:ObjectCache,backend:Backend}
 */
function mincemeat_memory_context( string $host, int $port, string $namespace ): array {
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
	$adapter   = new PhpRedisAdapter();
	$backend   = new Backend( $key_space, $adapter );
	$backend->initialize( $config );
	$cache = new ObjectCache( $key_space, $backend );
	if ($cache->state() !== ObjectCache::STATE_PERSISTENT) {
		throw new RuntimeException( 'The memory benchmark backend did not enter persistent state.' );
	}

	// The soak targets the request-memory tier only; keep Redis writes out of the
	// measured phase by registering a non-persistent group.
	$cache->add_non_persistent_groups( array( MINCEMEAT_MEMORY_GROUP ) );
	$GLOBALS['wp_object_cache'] = $cache;

	return array( 'cache' => $cache, 'backend' => $backend );
}

/**
 * Returns the source commit without including a workspace path.
 */
function mincemeat_memory_source_commit( string $runtime_root ): string {
	$override = getenv( 'MINCEMEAT_BENCHMARK_COMMIT' );
	if (is_string( $override ) && preg_match( '/^[0-9A-Za-z.+_-]{1,128}$/', $override ) === 1) {
		return $override;
	}

	$command = 'git -C ' . escapeshellarg( $runtime_root ) . ' rev-parse HEAD 2>/dev/null';
	$commit  = shell_exec( $command );
	$commit  = is_string( $commit ) ? trim( $commit ) : '';

	return preg_match( '/^[a-f0-9]{40}$/', $commit ) === 1 ? $commit : 'working-tree';
}

$output_file = '';
$run_label   = 'local';
$positionals = array();

foreach (array_slice( $argv, 1 ) as $argument) {
	if (strpos( $argument, '--output=' ) === 0) {
		$output_file = substr( $argument, strlen( '--output=' ) );
	} elseif (strpos( $argument, '--label=' ) === 0) {
		$run_label = substr( $argument, strlen( '--label=' ) );
	} elseif (strpos( $argument, '--runtime-root=' ) === 0) {
		continue;
	} else {
		$positionals[] = $argument;
	}
}

$host = $positionals[0] ?? '127.0.0.1';
$port = (int) ( $positionals[1] ?? 6383 );

try {
	$namespace = 'bench-memory-' . bin2hex( random_bytes( 4 ) );
	$context   = mincemeat_memory_context( $host, $port, $namespace );

	gc_collect_cycles();
	// Fine-grained emalloc usage is used rather than memory_get_peak_usage( true ),
	// whose real-memory figure is rounded to the allocator's coarse (multi-MB)
	// granularity and is unstable near a boundary for soaks of this size.
	$baseline_peak = memory_get_peak_usage( false );
	$baseline_used = memory_get_usage( false );
	$value         = str_repeat( 'x', MINCEMEAT_MEMORY_VALUE_BYTES );
	for ($i = 0; $i < MINCEMEAT_MEMORY_ENTRIES; $i++) {
		wp_cache_set( 'mem-' . $i, $value, MINCEMEAT_MEMORY_GROUP );
	}
	$peak_after   = memory_get_peak_usage( false );
	$used_after   = memory_get_usage( false );
	$peak_delta   = $peak_after - $baseline_peak;
	$living_delta = $used_after - $baseline_used;

	$context['backend']->close();

	$report = array(
		'schema_version' => MINCEMEAT_MEMORY_SCHEMA_VERSION,
		'artifact'       => array(
			'label'                 => preg_match( '/^[0-9A-Za-z.+_-]{1,80}$/', $run_label ) === 1 ? $run_label : 'invalid-label',
			'generated_at_utc'      => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'source_commit'         => mincemeat_memory_source_commit( $memory_runtime_root ),
			'runtime_version'       => Api::IMPLEMENTATION_VERSION,
			'runtime_dropin_sha256' => is_readable( $memory_runtime_root . '/stubs/object-cache.php' ) ? hash_file( 'sha256', $memory_runtime_root . '/stubs/object-cache.php' ) : 'missing',
			'harness_sha256'        => hash_file( 'sha256', __FILE__ ),
		),
		'memory'         => array(
			'workload'            => 'request-memory-tier-write',
			'group'               => MINCEMEAT_MEMORY_GROUP,
			'entries'             => MINCEMEAT_MEMORY_ENTRIES,
			'value_bytes'         => MINCEMEAT_MEMORY_VALUE_BYTES,
			'baseline_peak_bytes' => $baseline_peak,
			'peak_bytes'          => $peak_after,
			'peak_delta_bytes'    => $peak_delta,
			'living_delta_bytes'  => $living_delta,
			'bytes_per_entry'     => intdiv( $peak_delta, MINCEMEAT_MEMORY_ENTRIES ),
			'gating'              => false,
		),
	);

	$encoded_report = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION );
	if ( ! is_string( $encoded_report )) {
		throw new RuntimeException( 'Unable to encode the memory benchmark report.' );
	}
	if ($output_file !== '') {
		$output_dir = dirname( $output_file );
		if ( ! is_dir( $output_dir ) || file_put_contents( $output_file, $encoded_report . "\n" ) === false) {
			throw new RuntimeException( 'Unable to write the memory benchmark artifact.' );
		}
	}

	echo $encoded_report . "\n";
} catch (Throwable $e) {
	fwrite( STDERR, 'Memory benchmark error: ' . $e->getMessage() . "\n" );
	exit( 1 );
}