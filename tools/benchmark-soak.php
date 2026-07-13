<?php
/**
 * Benchmark and Soak Testing Tool for Mincemeat Object Cache
 *
 * Usage: php tools/benchmark-soak.php [host] [port] [--save-baseline|--compare]
 */

declare(strict_types=1);

require_once __DIR__ . '/../tests/bootstrap.php';

use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\Backend;

// Parse arguments
$save_baseline = false;
$compare = false;
$filtered_args = array();

foreach ($argv as $arg) {
	if ($arg === '--save-baseline') {
		$save_baseline = true;
	} elseif ($arg === '--compare') {
		$compare = true;
	} else {
		$filtered_args[] = $arg;
	}
}

$host = $filtered_args[1] ?? '127.0.0.1';
$port = (int) ( $filtered_args[2] ?? 6383 ); // default to redis8 dev port

echo "====================================================\n";
echo " Mincemeat Object Cache: Repeatable Benchmark Suite\n";
echo "====================================================\n";
echo "Target Host: $host\n";
echo "Target Port: $port\n\n";

/**
 * Helper to bootstrap a fresh cache instance.
 */
function create_cache_instance( string $host, int $port, string $namespace = 'bench' ): ObjectCache {
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

// Ensure the cache starts persistent for the initial runs
$cache                      = create_cache_instance( $host, $port );
$GLOBALS['wp_object_cache'] = $cache;

if ( $cache->state() !== ObjectCache::STATE_PERSISTENT ) {
	echo "WARNING: Cache is not in persistent state (currently: " . $cache->state() . ").\n";
	echo "Fallback connection benchmarks will still run, but backend paths may fail.\n\n";
}

$benchmarks = array(
	'scalar_set' => array(
		'name' => 'Scalar Set (100 ops)',
		'run'  => function() {
			$start = microtime( true );
			for ( $i = 0; $i < 100; $i++ ) {
				wp_cache_set( "scalar_key_$i", "val_$i", 'default' );
			}
			return ( microtime( true ) - $start ) * 1000;
		}
	),
	'backend_hit' => array(
		'name' => 'Backend Hit (100 ops, forced)',
		'run'  => function() {
			wp_cache_set( 'hit_key', 'hit_val', 'default' );
			$start = microtime( true );
			for ( $i = 0; $i < 100; $i++ ) {
				wp_cache_get( 'hit_key', 'default', true );
			}
			return ( microtime( true ) - $start ) * 1000;
		}
	),
	'request_memory_hit' => array(
		'name' => 'Request Memory Hit (1000 ops)',
		'run'  => function() {
			wp_cache_set( 'mem_key', 'mem_val', 'default' );
			wp_cache_get( 'mem_key', 'default' ); // warm up memory
			$start = microtime( true );
			for ( $i = 0; $i < 1000; $i++ ) {
				wp_cache_get( 'mem_key', 'default' );
			}
			return ( microtime( true ) - $start ) * 1000;
		}
	),
	'backend_miss' => array(
		'name' => 'Backend Miss (100 ops)',
		'run'  => function() {
			$start = microtime( true );
			for ( $i = 0; $i < 100; $i++ ) {
				wp_cache_get( "missing_key_$i", 'default' );
			}
			return ( microtime( true ) - $start ) * 1000;
		}
	),
	'multiple_get' => array(
		'name' => 'Multiple Get (50 ops of 100 keys)',
		'run'  => function() {
			$keys_data = array();
			for ( $i = 0; $i < 100; $i++ ) {
				$keys_data[ "batch_key_$i" ] = "val_$i";
			}
			wp_cache_set_multiple( $keys_data, 'default' );
			$keys = array_keys( $keys_data );
			$start = microtime( true );
			for ( $i = 0; $i < 50; $i++ ) {
				wp_cache_get_multiple( $keys, 'default', true );
			}
			return ( microtime( true ) - $start ) * 1000;
		}
	),
	'group_flush' => array(
		'name' => 'Group Flush Token Rotation (100 ops)',
		'run'  => function() {
			$start = microtime( true );
			for ( $i = 0; $i < 100; $i++ ) {
				wp_cache_flush_group( 'default' );
			}
			return ( microtime( true ) - $start ) * 1000;
		}
	),
	'failed_connection' => array(
		'name' => 'Failed Backend Connection (1000 ops)',
		'run'  => function() {
			// Save current cache to restore later
			$old_cache = $GLOBALS['wp_object_cache'] ?? null;

			// Create degraded cache instance connecting to invalid port
			$bad_cache = create_cache_instance( '127.0.0.1', 12345 );
			$GLOBALS['wp_object_cache'] = $bad_cache;
			// Trigger failure to open circuit
			wp_cache_get( 'any_key', 'default' );
			
			$start = microtime( true );
			for ( $i = 0; $i < 1000; $i++ ) {
				wp_cache_get( 'fallback_key', 'default' );
			}
			$elapsed = ( microtime( true ) - $start ) * 1000;

			// Restore
			$GLOBALS['wp_object_cache'] = $old_cache;
			return $elapsed;
		}
	),
	'soak_test_avg' => array(
		'name' => 'Soak Test 1000 ops (Avg per pair)',
		'run'  => function() {
			$ops   = 1000;
			$start = microtime( true );
			for ( $i = 0; $i < $ops; $i++ ) {
				$key = 'soak_key_' . ( $i % 50 );
				$val = 'soak_val_' . $i;
				wp_cache_set( $key, $val, 'default' );
				wp_cache_get( $key, 'default' );
				if ( $i > 0 && $i % 250 === 0 ) {
					wp_cache_flush_group( 'default' );
				}
			}
			return ( ( microtime( true ) - $start ) * 1000 ) / $ops;
		}
	)
);

$results = array();
foreach ( $benchmarks as $key => $bench ) {
	// Re-bootstrap fresh clean cache instance before each benchmark to isolate states
	$cache                      = create_cache_instance( $host, $port );
	$GLOBALS['wp_object_cache'] = $cache;

	$results[ $key ] = $bench['run']();
	echo sprintf( "%-48s: %10.3f ms\n", $bench['name'], $results[ $key ] );
}
echo "\n";

$baseline_file = __DIR__ . '/../tests/benchmarks-baseline.json';

if ( $save_baseline ) {
	file_put_contents( $baseline_file, json_encode( $results, JSON_PRETTY_PRINT ) );
	echo "Saved baseline to: $baseline_file\n";
} elseif ( $compare ) {
	if ( ! file_exists( $baseline_file ) ) {
		echo "Error: Baseline file not found at $baseline_file. Please run with --save-baseline first.\n";
		exit( 1 );
	}

	$baseline = json_decode( (string) file_get_contents( $baseline_file ), true );
	if ( ! is_array( $baseline ) ) {
		echo "Error: Invalid baseline file content at $baseline_file.\n";
		exit( 1 );
	}

	echo "========================================================================\n";
	echo "                     Mincemeat Object Cache: Comparison\n";
	echo "========================================================================\n";
	echo sprintf( "%-40s %12s %12s %10s %8s\n", 'Metric', 'Baseline', 'Current', 'Diff', 'Status' );
	echo "------------------------------------------------------------------------\n";

	foreach ( $benchmarks as $key => $bench ) {
		$base_val = $baseline[ $key ] ?? null;
		if ( $base_val === null ) {
			echo sprintf( "%-40s %12s %12.3f ms %10s %8s\n", $bench['name'], 'N/A', $results[ $key ], 'N/A', 'N/A' );
			continue;
		}

		$diff = $results[ $key ] - $base_val;
		$pct  = $base_val > 0 ? ( $diff / $base_val ) * 100 : 0;

		// We consider change within +/- 5% as no change due to CPU/run noise
		if ( abs( $pct ) <= 5.0 ) {
			$status = 'STABLE';
		} elseif ( $pct > 0 ) {
			$status = 'SLOWER';
		} else {
			$status = 'FASTER';
		}

		echo sprintf(
			"%-40s %8.3f ms %8.3f ms %+8.1f%% %8s\n",
			$bench['name'],
			$base_val,
			$results[ $key ],
			$pct,
			$status
		);
	}
	echo "========================================================================\n";
}
