<?php
/**
 * Benchmark and Soak Testing Tool for Mincemeat Object Cache
 *
 * Usage: php tools/benchmark-soak.php [host] [port]
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/bootstrap.php';

use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\Backend;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ( $argv[2] ?? 6379 );

echo "====================================================\n";
echo " Mincemeat Object Cache: Hardening & Benchmark Tool\n";
echo "====================================================\n";
echo "Target Host: $host\n";
echo "Target Port: $port\n\n";

/**
 * Helper to bootstrap a fresh cache instance.
 */
function create_cache_instance( string $host, int $port, string $namespace = 'bench'): ObjectCache {
	$config    = new Config(
		array(
			'namespace'       => $namespace,
			'scheme'          => 'tcp',
			'host'            => $host,
			'port'            => $port,
			'database'        => 0,
			'connect_timeout' => 0.5,
			'read_timeout'    => 0.5,
			'persistent'      => false,
			'max_ttl'         => 3600,
		)
	);
	$key_space = new KeySpace( false, 1 );
	$backend   = new Backend( $key_space );
	$backend->initialize( $config );
	return new ObjectCache( $key_space, $backend );
}

// ---------------------------------------------------------------------------
// 1. Scalar Operations Benchmark
// ---------------------------------------------------------------------------
echo "--- 1. Scalar Benchmark ---\n";
$cache                      = create_cache_instance( $host, $port );
$GLOBALS['wp_object_cache'] = $cache;

// Baseline write (set)
$start      = microtime( true );
$set_result = wp_cache_set( 'scalar_key', 'test_data', 'default', 10 );
$set_time   = microtime( true ) - $start;

// Scalar Hit (read from backend)
$start    = microtime( true );
$hit_val  = wp_cache_get( 'scalar_key', 'default' );
$hit_time = microtime( true ) - $start;

// Local Memory Hit (read from request memory, should not touch backend)
$start      = microtime( true );
$local_val  = wp_cache_get( 'scalar_key', 'default' );
$local_time = microtime( true ) - $start;

// Scalar Miss
$start     = microtime( true );
$miss_val  = wp_cache_get( 'missing_key', 'default' );
$miss_time = microtime( true ) - $start;

echo 'Set Key:       ' . ( $set_result ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $set_time * 1000, 3 ) . " ms)\n";
echo 'Get Hit:       ' . ( $hit_val === 'test_data' ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $hit_time * 1000, 3 ) . " ms)\n";
echo 'Local Hit:     ' . ( $local_val === 'test_data' ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $local_time * 1000, 3 ) . " ms)\n";
echo 'Get Miss:      ' . ( $miss_val === false ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $miss_time * 1000, 3 ) . " ms)\n";

$metrics = Api::metrics();
echo "Backend Calls: {$metrics['backend_calls']} (expected 3: 1 set, 1 get-hit, 1 get-miss)\n";
echo "Cache Hits:    {$metrics['hits']} (expected 2: 1 backend-hit, 1 memory-hit)\n";
echo "Cache Misses:  {$metrics['misses']} (expected 1: 1 backend-miss)\n\n";

// ---------------------------------------------------------------------------
// 2. 100-Key Multiple Operations Benchmark
// ---------------------------------------------------------------------------
echo "--- 2. Batch (100-Key) Benchmark ---\n";
$cache                      = create_cache_instance( $host, $port );
$GLOBALS['wp_object_cache'] = $cache;

$keys_data = array();
for ($i = 0; $i < 100; $i++) {
	$keys_data[ "batch_key_$i" ] = "val_$i";
}

$start          = microtime( true );
$set_multi      = wp_cache_set_multiple( $keys_data, 'default', 60 );
$set_multi_time = microtime( true ) - $start;

$start          = microtime( true );
$get_multi      = wp_cache_get_multiple( array_keys( $keys_data ), 'default' );
$get_multi_time = microtime( true ) - $start;

$start             = microtime( true );
$delete_multi      = wp_cache_delete_multiple( array_keys( $keys_data ), 'default' );
$delete_multi_time = microtime( true ) - $start;

echo 'Set Multiple (100 keys):    ' . ( count( $set_multi ) === 100 ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $set_multi_time * 1000, 3 ) . " ms)\n";
echo 'Get Multiple (100 keys):    ' . ( count( $get_multi ) === 100 ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $get_multi_time * 1000, 3 ) . " ms)\n";
echo 'Delete Multiple (100 keys): ' . ( count( $delete_multi ) === 100 ? 'OK' : 'FAILED' ) . ' (Time: ' . number_format( $delete_multi_time * 1000, 3 ) . " ms)\n";

$metrics = Api::metrics();
echo "Backend Calls: {$metrics['backend_calls']} (expected 3: 1 multi-set, 1 multi-get, 1 multi-delete)\n\n";

// ---------------------------------------------------------------------------
// 3. Failure & Circuit Breaker Degradation Test
// ---------------------------------------------------------------------------
echo "--- 3. Outage Degradation & Circuit Breaker ---\n";
// Create a cache connecting to an unreachable port to trigger connection failure
$bad_cache                  = create_cache_instance( '127.0.0.1', 12345 );
$GLOBALS['wp_object_cache'] = $bad_cache;

$status = Api::status();
echo "Degraded State:   {$status['state']}\n";
echo "Degraded Reason:  {$status['reason']}\n";

$start             = microtime( true );
$op1               = wp_cache_set( 'fallback_key', 'val', 'default' );
$op2               = wp_cache_get( 'fallback_key', 'default' );
$op3               = wp_cache_get( 'missing_fallback', 'default' );
$degraded_ops_time = microtime( true ) - $start;

$metrics = Api::metrics();
echo 'Fallback Set:     ' . ( $op1 ? 'OK' : 'FAILED' ) . " (in request memory)\n";
echo 'Fallback Get:     ' . ( $op2 === 'val' ? 'OK' : 'FAILED' ) . " (from request memory)\n";
echo 'Fallback Miss:    ' . ( $op3 === false ? 'OK' : 'FAILED' ) . "\n";
echo "Backend Calls:    {$metrics['backend_calls']} (expected 0: circuit is open)\n";
echo "Total Errors:     {$metrics['errors']}\n";
echo 'Degraded Ops Time: ' . number_format( $degraded_ops_time * 1000, 3 ) . " ms (fast fallback)\n\n";

// ---------------------------------------------------------------------------
// 4. Concurrency & Repeated Load Soak Test
// ---------------------------------------------------------------------------
echo "--- 4. Concurrency & Soak Testing (1000 operations) ---\n";
$cache                      = create_cache_instance( $host, $port, 'soak' );
$GLOBALS['wp_object_cache'] = $cache;

$ops    = 1000;
$errors = 0;
$start  = microtime( true );

for ($i = 0; $i < $ops; $i++) {
	$key = 'soak_key_' . ( $i % 50 ); // overlap to test overrides
	$val = 'soak_val_' . bin2hex( random_bytes( 4 ) );

	if ( ! wp_cache_set( $key, $val, 'default', 10 )) {
		$errors++;
	}

	$read = wp_cache_get( $key, 'default' );
	if ($read !== $val) {
		$errors++;
	}

	// Periodically trigger a group flush to test generation token invalidation
	if ($i > 0 && $i % 250 === 0) {
		wp_cache_flush_group( 'default' );
	}
}

$soak_time = microtime( true ) - $start;
echo "Soak Operations Run:  $ops\n";
echo "Verification Errors:  $errors\n";
echo 'Total Soak Time:      ' . number_format( $soak_time, 3 ) . ' s (Avg: ' . number_format( ( $soak_time / $ops ) * 1000, 3 ) . " ms per get/set pair)\n";

$final_status  = Api::status();
$final_metrics = Api::metrics();
echo "Final State:          {$final_status['state']}\n";
echo "Final Backend Calls:  {$final_metrics['backend_calls']}\n";
echo "Final Errors:         {$final_metrics['errors']}\n";
echo "====================================================\n";
echo "Hardening & Benchmarking Suite Completed successfully.\n";
echo "====================================================\n";
