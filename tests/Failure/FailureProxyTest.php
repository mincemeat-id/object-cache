<?php
/**
 * Failure proxy integration tests for Phase 6.
 *
 * Uses a failure proxy to test pre-dispatch and post-commit disconnects
 * against a live backend.
 *
 * @package Mincemeat\ObjectCache
 * @group failure
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Failure;

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;
use PHPUnit\Framework\TestCase;

class FailureProxyTest extends TestCase
{
    private $host;
    private $port;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('PhpRedis extension not available.');
        }

        $this->host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $this->port = (int)(getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6379);

        // Verify server reachable
        $probe = new \Redis();
        if (!@$probe->connect($this->host, $this->port, 1.0)) {
            $this->markTestSkipped('No Redis server reachable.');
        }
        $probe->close();

        // Reset global cache state
        unset($GLOBALS['wp_object_cache']);
    }

    private function get_config(string $ns): Config
    {
        return new Config(array(
            'namespace'       => $ns,
            'scheme'          => 'tcp',
            'host'            => $this->host,
            'port'            => $this->port,
            'connect_timeout' => 1.0,
            'read_timeout'    => 1.0,
        ));
    }

    public function test_pre_dispatch_disconnect()
    {
        $ns = 'fail-pre-' . bin2hex(random_bytes(8));
        $config = $this->get_config($ns);
        $ks = new KeySpace(false, 1);

        // Use custom adapter that simulates pre-dispatch disconnect
        $adapter = new FailureProxyAdapter();
        $adapter->connect($config);

        $be = new Backend($ks, $adapter);
        $be->initialize($config);

        $cache = new ObjectCache($ks, $be);
        $GLOBALS['wp_object_cache'] = $cache;

        $this->assertSame(ObjectCache::STATE_PERSISTENT, $cache->state());

        // Warm up / pre-load the namespace and group tokens
        $cache->get('warmup', 'options');

        // Enable pre-dispatch disconnect simulation
        $adapter->simulate_pre_dispatch_disconnect = true;

        // Reset adapter call counters
        $adapter->call_count = 0;

        // Perform write operation (which should fail pre-dispatch)
        $result = $cache->set('k1', 'val1', 'options');
        $this->assertTrue($result, 'Write should succeed in-memory after pre-dispatch disconnect.');

        // 1. Verify no command is retried (only called once, which threw the exception)
        $this->assertSame(1, $adapter->call_count, 'Command should not be retried.');

        // 2. Verify state becomes degraded
        $this->assertSame(ObjectCache::STATE_DEGRADED, $cache->state());
        $this->assertSame('command-failed', $cache->reason());

        // 3. Verify later backend calls are suppressed
        $adapter->call_count = 0;
        $cache->get('k2', 'options');
        $this->assertSame(0, $adapter->call_count, 'Backend calls should be suppressed when degraded.');

        // 4. Verify diagnostics do not imply persistence succeeded
        $diag = Api::diagnostics();
        $this->assertSame(ObjectCache::STATE_DEGRADED, $diag['state']);
        $this->assertSame('command-failed', $diag['reason']);

        // Clean up connections
        $be->close();

        // 5. Verify the next request observes that the key is absent in backend
        $clean_be = new Backend($ks);
        $clean_be->initialize($config);
        $clean_cache = new ObjectCache($ks, $clean_be);

        $found = null;
        $val = $clean_cache->get('k1', 'options', false, $found);
        $this->assertFalse($val);
        $this->assertFalse($found, 'Key should not exist in backend after pre-dispatch failure.');

        $clean_be->close();
    }

    public function test_post_commit_disconnect()
    {
        $ns = 'fail-post-' . bin2hex(random_bytes(8));
        $config = $this->get_config($ns);
        $ks = new KeySpace(false, 1);

        // Use custom adapter that simulates post-commit disconnect
        $adapter = new FailureProxyAdapter();
        $adapter->connect($config);

        $be = new Backend($ks, $adapter);
        $be->initialize($config);

        $cache = new ObjectCache($ks, $be);
        $GLOBALS['wp_object_cache'] = $cache;

        $this->assertSame(ObjectCache::STATE_PERSISTENT, $cache->state());

        // Warm up / pre-load the namespace and group tokens
        $cache->get('warmup', 'options');

        // Enable post-commit disconnect simulation
        $adapter->simulate_post_commit_disconnect = true;

        // Reset adapter call counters
        $adapter->call_count = 0;

        // Perform write operation (which commits but client disconnects on read response)
        $result = $cache->set('k1', 'val1', 'options');
        $this->assertTrue($result, 'Write should succeed in-memory after post-commit disconnect.');

        // 1. Verify no command is retried
        $this->assertSame(1, $adapter->call_count, 'Command should not be retried.');

        // 2. Verify state becomes degraded
        $this->assertSame(ObjectCache::STATE_DEGRADED, $cache->state());
        $this->assertSame('command-failed', $cache->reason());

        // 3. Verify later backend calls are suppressed
        $adapter->call_count = 0;
        $cache->get('k2', 'options');
        $this->assertSame(0, $adapter->call_count, 'Backend calls should be suppressed when degraded.');

        // Clean up connections
        $be->close();

        // 5. Verify the next request observes that the key IS present in backend (since it was committed!)
        $clean_be = new Backend($ks);
        $clean_be->initialize($config);
        $clean_cache = new ObjectCache($ks, $clean_be);

        $found = null;
        $val = $clean_cache->get('k1', 'options', false, $found);
        $this->assertSame('val1', $val);
        $this->assertTrue($found, 'Key should exist in backend after post-commit failure.');

        $clean_be->close();
    }
}

class FailureProxyAdapter extends PhpRedisAdapter
{
    public $simulate_pre_dispatch_disconnect = false;
    public $simulate_post_commit_disconnect = false;
    public $call_count = 0;

    public function set(string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false): bool
    {
        $this->call_count++;

        if ($this->simulate_pre_dispatch_disconnect) {
            $this->redis->close();
            throw new \RedisException('Connection closed pre-dispatch.');
        }

        // Run the command on real Redis
        $res = parent::set($key, $value, $ttl_ms, $nx, $xx);

        if ($this->simulate_post_commit_disconnect) {
            $this->redis->close();
            throw new \RedisException('Connection lost post-commit.');
        }

        return $res;
    }

    public function get(string $key)
    {
        $this->call_count++;

        if ($this->simulate_pre_dispatch_disconnect) {
            $this->redis->close();
            throw new \RedisException('Connection closed pre-dispatch.');
        }

        $res = parent::get($key);

        if ($this->simulate_post_commit_disconnect) {
            $this->redis->close();
            throw new \RedisException('Connection lost post-commit.');
        }

        return $res;
    }
}
