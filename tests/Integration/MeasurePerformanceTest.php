<?php
/**
 * Integration tests for the RC4 `measure_performance` opt-out flag.
 *
 * Verifies that when `measure_performance` is disabled, the runtime skips the
 * `microtime( true )` capture around backend commands (so `backend_time` stays
 * `0.0`) while still counting the cheap `backend_calls` counter. Requires a
 * live Redis/Valkey backend; skips gracefully when none is reachable.
 *
 * @package Mincemeat\ObjectCache
 * @group integration
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Integration;

use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 */
class MeasurePerformanceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
        $GLOBALS['__mincemeat_deprecated']      = array();
        $GLOBALS['__mincemeat_actions']          = array();

        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('PhpRedis extension not available.');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_object_cache']);
        parent::tearDown();
    }

    /**
     * Builds a persistent ObjectCache using the given measure_performance flag.
     */
    private function buildCache(bool $measure_performance): ?ObjectCache
    {
        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6383);

        $config = new Config(array(
            'namespace'           => 'mp-' . bin2hex(random_bytes(8)),
            'scheme'              => 'tcp',
            'host'                => $host,
            'port'                => $port,
            'database'            => 0,
            'connect_timeout'     => 1.0,
            'read_timeout'        => 1.0,
            'persistent'          => false,
            'max_ttl'             => 2592000,
            'debug'               => false,
            'measure_performance' => $measure_performance,
        ));

        $key_space = new KeySpace(false, 1);
        $backend   = new Backend($key_space);
        $backend->initialize($config);

        if (! $backend->is_persistent()) {
            return null;
        }

        return new ObjectCache($key_space, $backend);
    }

    public function test_measure_performance_enabled_accumulates_backend_time()
    {
        $cache = $this->buildCache(true);
        if ($cache === null) {
            $this->markTestSkipped('No Redis/Valkey server reachable.');
        }

        $cache->set('k', 'v', 'default');
        $cache->get('k', 'default', true);

        $this->assertGreaterThan(0, $cache->backend_calls());
        $this->assertGreaterThan(0.0, $cache->backend_time());
    }

    public function test_measure_performance_disabled_keeps_backend_time_zero_but_counts_calls()
    {
        $cache = $this->buildCache(false);
        if ($cache === null) {
            $this->markTestSkipped('No Redis/Valkey server reachable.');
        }

        $cache->set('k', 'v', 'default');
        $cache->get('k', 'default', true);

        $this->assertGreaterThan(0, $cache->backend_calls());
        $this->assertSame(0.0, $cache->backend_time());
    }
}