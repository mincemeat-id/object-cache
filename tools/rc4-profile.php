<?php
/**
 * RC4 deep performance profiling driver.
 *
 * Self-contained: builds a real persistent ObjectCache against Redis and
 * exercises the concrete hot paths the RC4 plan targets:
 *
 *   - persistent set  (persistent_set -> generation_tokens + item_key + set)
 *   - persistent get  (persistent_get -> generation_tokens + item_key + get)
 *   - request-memory hit (get served from $this->cache only)
 *   - mixed set/get soak (realistic WordPress access pattern)
 *
 * Run under Xdebug profiler:
 *   XDEBUG_MODE=profile php tools/rc4-profile.php 127.0.0.1 6379 [iterations]
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

/**
 * Counting adapter so we can report backend calls/round-trips.
 */
final class Rc4ProfileAdapter extends PhpRedisAdapter {
	/** @var int */
	public $round_trips = 0;
	/** @var int */
	public $commands   = 0;

	public function connect( Config $config ): void {
		parent::connect( $config );
	}

	public function get( string $key ) {
		$this->round_trips++;
		$this->commands++;
		return parent::get( $key );
	}

	public function set( string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false ): bool {
		$this->round_trips++;
		$this->commands++;
		return parent::set( $key, $value, $ttl_ms, $nx, $xx );
	}
}

$host     = $argv[1] ?? '127.0.0.1';
$port     = (int) ( $argv[2] ?? 6379 );
$iterations = (int) ( $argv[3] ?? 2000 );

$config = new Config(
	array(
		'namespace'       => 'rc4-profile-' . bin2hex( random_bytes( 4 ) ),
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

$key_space = new KeySpace( false, 1 );
$adapter   = new Rc4ProfileAdapter();
$backend   = new Backend( $key_space, $adapter );
$backend->initialize( $config );
$cache = new ObjectCache( $key_space, $backend );
$GLOBALS['wp_object_cache'] = $cache;

if ( $cache->state() !== ObjectCache::STATE_PERSISTENT ) {
	fwrite( STDERR, "Backend did not reach persistent state.\n" );
	exit( 2 );
}

// Warm a request-memory entry so the memory-hit path is measured in isolation.
wp_cache_set( 'memory-hit', 'value', 'default' );

// --- 1. Persistent set (hot write path) ---
for ( $i = 0; $i < $iterations; $i++ ) {
	wp_cache_set( 'set-key-' . ( $i % 50 ), 'value-' . $i, 'default' );
}

// --- 2. Persistent get (forced, hot read path past request memory) ---
for ( $i = 0; $i < $iterations; $i++ ) {
	wp_cache_get( 'set-key-' . ( $i % 50 ), 'default', true );
}

// --- 3. Request-memory hit (fastest path) ---
for ( $i = 0; $i < $iterations; $i++ ) {
	wp_cache_get( 'memory-hit', 'default' );
}

// --- 4. Mixed soak (realistic WordPress access pattern) ---
for ( $i = 0; $i < $iterations; $i++ ) {
	$key = 'soak-key-' . ( $i % 50 );
	wp_cache_set( $key, 'value-' . $i, 'default' );
	wp_cache_get( $key, 'default' );
	if ( $i > 0 && $i % 250 === 0 ) {
		wp_cache_flush_group( 'default' );
	}
}

fwrite( STDERR, sprintf(
	"Profiled %d iterations/path. state=%s hits=%d misses=%d backend_calls=%d round_trips=%d peak=%.2fMB\n",
	$iterations,
	$cache->state(),
	$cache->hits(),
	$cache->misses(),
	$cache->backend_calls(),
	$adapter->round_trips,
	memory_get_peak_usage( true ) / 1048576.0
) );
