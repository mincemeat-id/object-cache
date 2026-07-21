<?php
/**
 * Unit tests for the Mincemeat Object Cache public Api class.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class ApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Clear global cache reference before each test.
        unset($GLOBALS['wp_object_cache']);
        $GLOBALS['__mincemeat_filters'] = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_object_cache']);
        $GLOBALS['__mincemeat_filters'] = array();
        parent::tearDown();
    }

    public function test_api_status_not_initialized()
    {
        $status = Api::status();
        $this->assertSame('runtime-only', $status['state']);
        $this->assertSame('not-initialized', $status['reason']);
    }

    public function test_api_status_initialized()
    {
        $key_space = new KeySpace(false, 1);
        $backend = new Backend($key_space);
        $cache = new ObjectCache($key_space, $backend);
        $GLOBALS['wp_object_cache'] = $cache;

        $status = Api::status();
        $this->assertSame('runtime-only', $status['state']);
        $this->assertSame('no-backend', $status['reason']);
    }

    public function test_api_capabilities_not_initialized()
    {
        $caps = Api::capabilities();
        $this->assertSame('phpredis', $caps['client']);
        $this->assertSame('none', $caps['transport']);
        $this->assertSame(Api::NATIVE_FEATURES, $caps['features']);
    }

    public function test_api_capabilities_initialized_persistent()
    {
        $key_space = new KeySpace(false, 1);
        $adapter = $this->createMock(PhpRedisAdapter::class);
        $adapter->method('is_connected')->willReturn(true);
        $adapter->method('server_info')->willReturn(array('maxmemory_policy' => 'allkeys-lru'));

        $backend = new Backend($key_space, $adapter);
        $config = new Config(array(
            'namespace' => 'test-ns',
            'scheme'    => 'tls',
            'host'      => '127.0.0.1',
            'port'      => 6379,
        ));
        $backend->initialize($config);

        $cache = new ObjectCache($key_space, $backend);
        $GLOBALS['wp_object_cache'] = $cache;

        $caps = Api::capabilities();
        $this->assertSame('phpredis', $caps['client']);
        $this->assertSame('tls', $caps['transport']);
    }

    public function test_api_metrics_empty()
    {
        $metrics = Api::metrics();
        $this->assertSame(0, $metrics['hits']);
        $this->assertSame(0, $metrics['misses']);
        $this->assertSame(0, $metrics['backend_calls']);
        $this->assertSame(0.0, $metrics['backend_time']);
        $this->assertSame(0, $metrics['errors']);
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $metrics['state']);
        $this->assertSame('not-initialized', $metrics['reason']);
    }

    public function test_api_version()
    {
        $version = Api::version();
        $this->assertSame(Api::IMPLEMENTATION_VERSION, $version['implementation']);
        $this->assertSame(Api::SCHEMA_VERSION, $version['schema']);
    }

    public function test_api_diagnostics_not_initialized()
    {
        $diag = Api::diagnostics();
        $this->assertSame('runtime-only', $diag['state']);
        $this->assertSame('not-initialized', $diag['reason']);
        $this->assertSame('', $diag['last_error']);
        $this->assertFalse($diag['multisite']);
        $this->assertEmpty($diag['global_groups']);
        $this->assertEmpty($diag['non_persistent_groups']);
        $this->assertSame(PHP_VERSION, $diag['php_version']);
        $this->assertArrayHasKey('phpredis_version', $diag);
        $this->assertSame('6.3.0', $diag['phpredis_minimum']);
		$this->assertSame(Api::TOPOLOGY_UNVERIFIED, $diag['topology_status']);
		$this->assertSame('unknown', $diag['topology_mode']);
		$this->assertSame('unknown', $diag['topology_role']);
		$this->assertSame('disabled', $diag['connection_reuse']);
        $this->assertArrayNotHasKey('scheme', $diag);
    }

    public function test_api_diagnostics_initialized()
    {
        $key_space = new KeySpace(false, 1);
        $adapter = $this->createMock(PhpRedisAdapter::class);
        $adapter->method('is_connected')->willReturn(true);
        $adapter->method('server_info')->willReturn(array(
            'product' => 'redis',
            'version' => '8.0',
            'mode' => 'standalone',
            'role' => 'master',
            'maxmemory_policy' => 'allkeys-lru'
        ));

        $backend = new Backend($key_space, $adapter);
        $config = new Config(array(
            'namespace' => 'test-ns',
            'scheme'    => 'tcp',
            'host'      => '127.0.0.1',
            'port'      => 6379,
        ));
        $backend->initialize($config);

        $cache = new ObjectCache($key_space, $backend);
        $GLOBALS['wp_object_cache'] = $cache;

        // 1. Public Mode (default)
        $diag = Api::diagnostics(true);
        $this->assertSame('persistent', $diag['state']);
        $this->assertSame('tcp', $diag['scheme']);
        $this->assertSame('configured', $diag['host']);
        $this->assertSame('***', $diag['port']);
        $this->assertSame('***', $diag['database']);
		$this->assertSame(Api::TOPOLOGY_POLICY, $diag['topology_policy']);
		$this->assertSame(Api::TOPOLOGY_COMPATIBLE, $diag['topology_status']);
		$this->assertSame('standalone', $diag['topology_mode']);
		$this->assertSame('primary', $diag['topology_role']);
		$this->assertFalse($diag['persistent_requested']);
		$this->assertFalse($diag['persistent_reuse']);
		$this->assertSame('disabled', $diag['connection_reuse']);
        $this->assertNotSame('test-ns', $diag['namespace_digest']);
        // Sanitized to product and version only
        $this->assertSame(array('product' => 'redis', 'version' => '8.0'), $diag['server']);

        // 2. Debug Mode retains richer server metadata, never endpoint identity.
        $diag_debug = Api::diagnostics(false);
        $this->assertSame('configured', $diag_debug['host']);
        $this->assertSame('***', $diag_debug['port']);
        $this->assertSame('***', $diag_debug['database']);
        $this->assertSame(array(
            'product' => 'redis',
            'version' => '8.0',
            'mode' => 'standalone',
            'role' => 'master',
            'maxmemory_policy' => 'allkeys-lru'
        ), $diag_debug['server']);
    }

    public function test_api_diagnostics_filter_applied()
    {
        if (function_exists('add_filter')) {
            add_filter('mincemeat_object_cache_diagnostics', function ($diag) {
                $diag['filtered'] = true;
                return $diag;
            });
        }

        $diag = Api::diagnostics();
        $this->assertTrue($diag['filtered'] ?? false);
    }
}
