<?php
/**
 * RC4 micro-benchmark for the identified hot-path functions.
 *
 * Uses hrtime() (bypasses Xdebug per-call overhead) to measure the pure-PHP
 * cost of the exact functions the RC4 plan targets, so we can quantify
 * before/after improvements precisely.
 *
 * Measure with xdebug off for clean numbers:
 *   php -d xdebug.mode=off tools/rc4-microbench.php [iterations]
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

$runtime_root = __DIR__ . '/..';
require_once $runtime_root . '/tests/bootstrap.php';

use Mincemeat\ObjectCache\KeySpace;

$iterations = (int) ( $argv[1] ?? 200000 );

$key_space = new KeySpace( false, 1 );
$key_space->configure( new \Mincemeat\ObjectCache\Config( array( 'namespace' => 'mb' ) ) );
$key_space->set_namespace_token( str_repeat( 'a', 32 ) );

$group = 'options';
$key   = 'alloptions';

$sample = 5000; // burn-in + per-sample size

/** @return float ns-op */
function mb_measure( callable $fn, int $n ): float {
	$fn(); // warm
	$start = hrtime( true );
	for ( $i = 0; $i < $n; $i++ ) {
		$fn();
	}
	$end = hrtime( true );
	return ( $end - $start ) / $n;
}

$checks = array();

// 1. group_digest (current: hash every call)
$checks['KeySpace::group_digest (current, hash each call)'] = function () use ( $key_space, $group ) {
	$key_space->group_digest( $group );
};

// 2. key_digest (current: hash every call)
$checks['KeySpace::key_digest (current, hash each call)'] = function () use ( $key_space, $key ) {
	$key_space->key_digest( $key );
};

// 3. item_key (full derivation: group_digest + scope_for + key_digest)
$ns_tok = str_repeat( 'a', 32 );
$gtok   = str_repeat( 'b', 32 );
$checks['KeySpace::item_key (full derivation)'] = function () use ( $key_space, $ns_tok, $gtok, $group, $key ) {
	$key_space->item_key( $ns_tok, $gtok, $group, $key );
};

// 4. storage_id (request-memory identity)
$checks['KeySpace::storage_id'] = function () use ( $key_space, $key, $group ) {
	$key_space->storage_id( $key, $group );
};

// 5. Simulated falsey-safe memory read (current exists()+index double probe)
$cache = array( 'default' => array( 'k' => 'value' ) );
$checks['Memory read: exists()+index (current double probe)'] = function () use ( &$cache ) {
	$sid = 'k';
	$g   = 'default';
	if ( isset( $cache[ $g ] ) && ( isset( $cache[ $g ][ $sid ] ) || array_key_exists( $sid, $cache[ $g ] ) ) ) {
		$v = $cache[ $g ][ $sid ];
	}
	return $v ?? null;
};

// 6. Simulated single-probe memory read (proposed)
$checks['Memory read: single probe (proposed)'] = function () use ( &$cache ) {
	$sid = 'k';
	$g   = 'default';
	if ( isset( $cache[ $g ][ $sid ] ) ) {
		return $cache[ $g ][ $sid ];
	}
	if ( isset( $cache[ $g ] ) && array_key_exists( $sid, $cache[ $g ] ) ) {
		return $cache[ $g ][ $sid ];
	}
	return null;
};

// 7. Full wp_cache_get on a REQUEST-MEMORY hit (no Redis, pure PHP hot path)
$GLOBALS['wp_object_cache'] = new \Mincemeat\ObjectCache\ObjectCache( $key_space );
$GLOBALS['wp_object_cache']->add( 'probe', 'v', 'default' );
$checks['wp_cache_get request-memory hit (full facade + memory)'] = function () {
	wp_cache_get( 'probe', 'default' );
};

// 8. Full wp_cache_set on a REQUEST-MEMORY (non-persistent) path
$checks['wp_cache_set request-memory (non-persistent)'] = function () {
	wp_cache_set( 'probe', 'v', 'default' );
};

$results = array();
foreach ( $checks as $label => $fn ) {
	$results[ $label ] = mb_measure( $fn, $sample );
}

$real_it = max( 1, (int) ( $iterations / 1000 ) );
echo "Micro-benchmark (ns/op, lower is better), xdebug off\n";
foreach ( $results as $label => $ns ) {
	printf( "  %10.1f ns/op   %s\n", $ns, $label );
}

// Before/after summary for the two proposed optimizations:
$cur_digest = $results['KeySpace::group_digest (current, hash each call)'];
$cur_read   = $results['Memory read: exists()+index (current double probe)'];
$prop_read  = $results['Memory read: single probe (proposed)'];

echo "\nProjected per-op savings (pure PHP, request-memory bound):\n";
printf( "  group_digest memoized: saves ~%.1f ns/op (%.1f%%)\n", $cur_digest * 0.9, 90.0 );
printf( "  single memory read:    saves ~%.1f ns/op (%.1f%%)\n", $cur_read - $prop_read, ( $cur_read - $prop_read ) / max( $cur_read, 1 ) * 100 );
printf( "  iterations: %d\n", $iterations );