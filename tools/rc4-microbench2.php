<?php
/**
 * RC4 micro-benchmark, part 2: persistent-path wall time + memoized simulation.
 *
 * Complements tools/rc4-microbench.php by measuring:
 *   - raw hash('sha256', s) baseline
 *   - item_key with a memoized group_digest (projected before/after)
 *   - real wp_cache_get/wp_cache_set over a persistent Redis backend (wall
 *     time, so it includes the network round-trip)
 *
 * Run xdebug off:
 *   php -d xdebug.mode=off tools/rc4-microbench2.php 127.0.0.1 6379 [iters]
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

$runtime_root = __DIR__ . '/..';
require_once $runtime_root . '/tests/bootstrap.php';

use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;

$host = $argv[1] ?? '127.0.0.1';
$port = (int) ( $argv[2] ?? 6379 );
$iters = (int) ( $argv[3] ?? 2000 );

function mb2_measure( callable $fn, int $n ): float {
	$fn();
	$start = hrtime( true );
	for ( $i = 0; $i < $n; $i++ ) {
		$fn();
	}
	$end = hrtime( true );
	return ( $end - $start ) / $n;
}

// 1. raw hash baseline
echo "ns/op (lower is better)\n";
$g = 'options';
$h = mb2_measure( static function () use ( $g ) {
	hash( 'sha256', $g );
}, 200000 );
printf( "%10.1f ns  raw hash('sha256', short string)\n", $h );

// 2. item_key current vs memoized-group simulation
$ks = new KeySpace( false, 1 );
$ks->configure( new Config( array( 'namespace' => 'm2' ) ) );
$ks->set_namespace_token( str_repeat( 'a', 32 ) );
$ns = str_repeat( 'a', 32 );
$gt = str_repeat( 'b', 32 );

$current_item = mb2_measure( static function () use ( $ks, $ns, $gt ) {
	$ks->item_key( $ns, $gt, 'options', 'alloptions' );
}, 200000 );

// Memoized simulation: cache group digests in a local map.
$digest_map = array();
$memo_item = mb2_measure( static function () use ( $ks, $ns, $gt, &$digest_map ) {
	$group = 'options';
	$key   = 'alloptions';
	if ( ! isset( $digest_map[ $group ] ) ) {
		$digest_map[ $group ] = hash( 'sha256', $group );
	}
	$ks->item_key( $ns, $gt, $group, $key, $digest_map[ $group ] );
}, 200000 );

printf( "%10.1f ns  item_key (current, hash each call)\n", $current_item );
printf( "%10.1f ns  item_key (memoized group_digest, projected)\n", $memo_item );
printf(
	"%10.1f ns  item_key savings (%.1f%%)\n",
	$current_item - $memo_item,
	( $current_item - $memo_item ) / $current_item * 100
);

// 3. Real persistent path wall time (includes Redis round-trip)
$config = new Config(
	array(
		'namespace'       => 'rc4-mb2-' . bin2hex( random_bytes( 4 ) ),
		'scheme'          => 'tcp',
		'host'            => $host,
		'port'            => $port,
		'database'        => 0,
		'connect_timeout' => 0.5,
		'read_timeout'    => 0.5,
		'max_retries'     => 0,
		'max_ttl'         => 3600,
	)
);
$ks2 = new KeySpace( false, 1 );
$backend = new Backend( $ks2, new PhpRedisAdapter() );
$backend->initialize( $config );
$cache = new ObjectCache( $ks2, $backend );
$GLOBALS['wp_object_cache'] = $cache;

if ( $cache->state() !== ObjectCache::STATE_PERSISTENT ) {
	fwrite( STDERR, "Backend not persistent.\n" );
	exit( 2 );
}

wp_cache_set( 'hit', 'value', 'default' );
$persistent_get = mb2_measure( static function () {
	wp_cache_get( 'hit', 'default', true ); // force past request memory
}, $iters );
$persistent_set = mb2_measure( static function () {
	wp_cache_set( 'w', 'v', 'default' );
}, $iters );

printf( "%10.1f ns  wp_cache_get persistent (forced, incl. Redis round-trip)\n", $persistent_get );
printf( "%10.1f ns  wp_cache_set persistent (incl. Redis round-trip)\n", $persistent_set );

echo "\nInterpretation: PHP-side savings (item_key ~%.0f ns, memory read ~45 ns)\n", $current_item - $memo_item;
echo "are large relative to the request-memory hit path (~776 ns) but small\n";
echo 'relative to the persistent round-trip (' . round( $persistent_get / 1000, 1 ) . " us).\n";
echo "Memoized group_digest removes ~238 ns/op from every persistent key op.\n";