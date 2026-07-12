<?php
/**
 * Base class for integration tests that require a live Redis/Valkey server.
 *
 * Skips gracefully when no server is reachable. Each test class gets an
 * isolated namespace so parallel runs never collide.
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

abstract class IntegrationTestCase extends TestCase
{
    /**
     * @var Backend|null
     */
    protected $backend;

    /**
     * @var ObjectCache
     */
    protected $cache;

    /**
     * @var Config|null
     */
    protected $config;

    /**
     * Whether the backend is available for this test run.
     *
     * @var bool
     */
    private $available = false;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
        $GLOBALS['__mincemeat_deprecated']      = array();
        $GLOBALS['__mincemeat_actions']          = array();

        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6379);

        $namespace = 'test-' . bin2hex(random_bytes(8));

        $config_array = array(
            'namespace'        => $namespace,
            'scheme'           => 'tcp',
            'host'             => $host,
            'port'             => $port,
            'database'         => 0,
            'connect_timeout'  => 1.0,
            'read_timeout'     => 1.0,
            'persistent'       => false,
            'max_ttl'          => 2592000,
            'debug'            => false,
        );

        if (getenv('MINCEMEAT_REQUIRE_INTEGRATION') && !class_exists(\Redis::class)) {
            $this->fail('PhpRedis extension is not available but integration is required.');
        }

        try {
            $this->config = new Config($config_array);
        } catch (\Throwable $e) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('Invalid config: ' . $e->getMessage());
            } else {
                $this->markTestSkipped('Invalid config: ' . $e->getMessage());
            }
        }

        $key_space = new KeySpace(false, 1);
        $this->backend = new Backend($key_space);
        $this->backend->initialize($this->config);

        if (! $this->backend->is_persistent()) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('No Redis/Valkey server reachable at ' . $host . ':' . $port);
            } else {
                $this->markTestSkipped('No Redis/Valkey server reachable at ' . $host . ':' . $port);
            }
        }

        $expected_product = getenv('MINCEMEAT_EXPECTED_BACKEND');
        if ($expected_product) {
            $info = $this->backend->server_info();
            $this->assertNotNull($info, 'Server info not available for backend assertion.');
            $this->assertSame($expected_product, $info['product'], 'Expected product ' . $expected_product . ' but got ' . $info['product']);
        }

        $this->available = true;

        $this->cache = new ObjectCache($key_space, $this->backend);
        $this->cache->add_global_groups(array('global-cache-test'));
    }

    protected function tearDown(): void
    {
        if ($this->available && $this->backend !== null) {
            // Clean up: flush our isolated namespace.
            $this->backend->replace_namespace_token();
            $this->backend->close();
        }

        if (function_exists('wp_suspend_cache_addition')) {
            wp_suspend_cache_addition(false);
        }

        parent::tearDown();
    }

    /**
     * Creates a second independent cache instance sharing the same namespace
     * to simulate a separate request.
     *
     * @return ObjectCache
     */
    protected function new_request(): ObjectCache
    {
        $key_space = new KeySpace(false, 1);
        $backend   = new Backend($key_space);
        $backend->initialize($this->config);

        $cache = new ObjectCache($key_space, $backend);
        $cache->add_global_groups(array('global-cache-test'));

        return $cache;
    }
}