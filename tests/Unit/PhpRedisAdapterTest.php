<?php
/**
 * Unit tests for the PhpRedisAdapter class.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\PhpRedisAdapter;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\BackendException;
use Mincemeat\ObjectCache\LuaScripts;
use PHPUnit\Framework\TestCase;

class TestablePhpRedisAdapter extends PhpRedisAdapter
{
    private $mockRedis;

    private $version;

    public function __construct($mockRedis, $version = '6.3.0')
    {
        parent::__construct();
        $this->mockRedis = $mockRedis;
        $this->version = $version;
    }

    protected function create_redis_instance(): \Redis
    {
        return $this->mockRedis;
    }

    protected function phpredis_version()
    {
        return $this->version;
    }
}

/**
 * @group unit
 */
class PhpRedisAdapterTest extends TestCase
{
    private function adapterWithRedis(\Redis $redis): PhpRedisAdapter
    {
        $adapter = new PhpRedisAdapter();
        $property = new \ReflectionProperty(PhpRedisAdapter::class, 'redis');
        $property->setAccessible(true);
        $property->setValue($adapter, $redis);
        return $adapter;
    }

    private function config(array $overrides = array()): Config
    {
        return new Config(array_merge(array(
            'namespace' => 'test-ns',
            'scheme' => 'tcp',
            'host' => '127.0.0.1',
            'port' => 6379,
        ), $overrides));
    }

    private function allowOptionConfiguration($redis): void
    {
        $options = array();
        $redis->method('setOption')->willReturnCallback(function ($option, $value) use (&$options) {
            $options[$option] = $value;
            return true;
        });
        $redis->method('getOption')->willReturnCallback(function ($option) use (&$options) {
            return $options[$option] ?? false;
        });
    }

    public function test_connect_with_tls_passes_stream_context()
    {
        if (!class_exists(\Redis::class)) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('Redis extension not available.');
            } else {
                $this->markTestSkipped('Redis extension not available.');
            }
        }

        $config = new Config(array(
            'namespace' => 'test-ns',
            'scheme'    => 'tls',
            'host'      => 'cache.internal',
            'port'      => 6379,
            'connect_timeout' => 1.0,
            'read_timeout' => 1.0,
            'tls'       => array(
                'verify_peer' => false,
            ),
        ));

        $mockRedis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('connect', 'setOption', 'getOption', 'script', 'clearLastError'))
            ->getMock();

        $this->allowOptionConfiguration($mockRedis);
        $mockRedis->expects($this->once())
            ->method('script')
            ->with('load', LuaScripts::INCR_DECR)
            ->willReturn(sha1(LuaScripts::INCR_DECR));

        $mockRedis->expects($this->once())
            ->method('connect')
            ->with(
                'tls://cache.internal',
                6379,
                1.0,
                null,
                0,
                1.0,
                array('stream' => array('verify_peer' => false))
            )
            ->willReturn(true);

        $adapter = new TestablePhpRedisAdapter($mockRedis);
        $adapter->connect($config);
        $this->assertTrue($adapter->supports_unlink());
    }

    public function test_connect_with_persistent_generates_canonical_id()
    {
        if (!class_exists(\Redis::class)) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('Redis extension not available.');
            } else {
                $this->markTestSkipped('Redis extension not available.');
            }
        }

        $config = new Config(array(
            'namespace'  => 'test-ns',
            'scheme'     => 'tcp',
            'host'       => '127.0.0.1',
            'port'       => 6379,
            'connect_timeout' => 1.0,
            'read_timeout' => 1.0,
            'persistent' => true,
        ));

        $canonical = array(
            'scheme'            => 'tcp',
            'host'              => '127.0.0.1',
            'port'              => 6379,
            'path'              => '',
            'database'          => 0,
            'namespace_digest'  => $config->namespace_digest(),
            'username'          => null,
            'tls_non_secret'    => array(),
            'max_retries'       => 1,
            'backoff_algorithm' => 'decorrelated_jitter',
            'backoff_base'      => 10,
            'backoff_cap'       => 100,
            'tcp_keepalive'     => true,
        );
        $expectedId = 'mcoc:' . hash('sha256', serialize($canonical));

        $mockRedis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('pconnect', 'setOption', 'getOption', 'script', 'clearLastError'))
            ->getMock();

        $this->allowOptionConfiguration($mockRedis);
        $mockRedis->method('script')->willReturn(false);

        $mockRedis->expects($this->once())
            ->method('pconnect')
            ->with(
                '127.0.0.1',
                6379,
                1.0,
                $expectedId,
                0,
                1.0
            )
            ->willReturn(true);

        $adapter = new TestablePhpRedisAdapter($mockRedis);
        $adapter->connect($config);
    }

    public function test_ping_issues_exactly_one_command()
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('ping'))->getMock();
        $redis->expects($this->once())->method('ping')->willReturn('+PONG');
        $this->assertTrue($this->adapterWithRedis($redis)->ping());
    }

    public function test_pipeline_dispatches_allowlisted_commands()
    {
        $redis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('pipeline', 'set', 'unlink', 'del', 'exec'))
            ->getMock();
        $redis->expects($this->once())->method('pipeline')->willReturnSelf();
        $redis->expects($this->exactly(2))->method('set')->withConsecutive(
            array('a', 'one'),
            array('b', 'two', array('PX' => 10))
        )->willReturnSelf();
        $redis->expects($this->once())->method('unlink')->with('c')->willReturnSelf();
        $redis->expects($this->once())->method('del')->with('d')->willReturnSelf();
        $redis->expects($this->once())->method('exec')->willReturn(array(true, true, 1, 1));

        $result = $this->adapterWithRedis($redis)->pipeline(array(
            array('set', array('a', 'one')),
            array('set', array('b', 'two', array('PX' => 10))),
            array('unlink', array('c')),
            array('del', array('d')),
        ));
        $this->assertSame(array(true, true, 1, 1), $result);
    }

    public function test_pipeline_rejects_unsupported_command()
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('pipeline'))->getMock();
        $redis->method('pipeline')->willReturnSelf();
        $this->expectException(\LogicException::class);
        $this->adapterWithRedis($redis)->pipeline(array(array('get', array('a'))));
    }

    public function test_pipeline_handles_empty_and_failed_exec()
    {
        $redis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('pipeline', 'set', 'exec'))
            ->getMock();
        $redis->expects($this->exactly(2))->method('pipeline')->willReturnSelf();
        $redis->expects($this->once())->method('set')->willReturnSelf();
        $redis->expects($this->once())->method('exec')->willReturn(false);
        $adapter = $this->adapterWithRedis($redis);
        $this->assertSame(array(), $adapter->pipeline(array()));
        $this->assertSame(array(), $adapter->pipeline(array(array('set', array('a', 'b')))));
    }

    /** @dataProvider serverInfoProvider */
    public function test_server_info_identity(array $info, array $expected)
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('serverName', 'serverVersion', 'info'))->getMock();
        $redis->method('serverName')->willReturn(false);
        $redis->method('serverVersion')->willReturn(false);
        $redis->method('info')->willReturn($info);
        $this->assertSame($expected, $this->adapterWithRedis($redis)->server_info());
    }

    public function test_server_info_prefers_modern_identity_methods()
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('serverName', 'serverVersion', 'info'))->getMock();
        $redis->expects($this->once())->method('serverName')->willReturn('valkey');
        $redis->expects($this->once())->method('serverVersion')->willReturn('9.0.1');
        $redis->method('info')->willReturn(array(
            'redis_version' => '7.2.0',
            'redis_mode' => 'standalone',
            'maxmemory_policy' => 'allkeys-lru',
        ));

        $this->assertSame(
            array('product' => 'valkey', 'version' => '9.0.1', 'mode' => 'standalone', 'os' => '', 'maxmemory_policy' => 'allkeys-lru'),
            $this->adapterWithRedis($redis)->server_info()
        );
    }

    public function serverInfoProvider(): array
    {
        return array(
            'redis' => array(array('redis_version' => '8.0', 'redis_mode' => 'cluster'), array('product' => 'redis', 'version' => '8.0', 'mode' => 'cluster', 'os' => '', 'maxmemory_policy' => '')),
            'valkey' => array(array('redis_version' => '7.2', 'valkey_version' => '9.0', 'os' => 'linux'), array('product' => 'valkey', 'version' => '9.0', 'mode' => 'standalone', 'os' => 'linux', 'maxmemory_policy' => '')),
            'unknown' => array(array(), array('product' => 'unknown', 'version' => '', 'mode' => 'standalone', 'os' => '', 'maxmemory_policy' => '')),
        );
    }

    public function test_server_info_returns_null_on_error()
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('serverName', 'serverVersion', 'info'))->getMock();
        $redis->method('serverName')->willReturn(false);
        $redis->method('serverVersion')->willReturn(false);
        $redis->method('info')->willThrowException(new \RedisException('failed'));
        $this->assertNull($this->adapterWithRedis($redis)->server_info());
    }

    /** @dataProvider setOptionFailureProvider */
    public function test_connect_rejects_set_option_failure($result)
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('connect', 'setOption', 'getOption'))->getMock();
        $redis->method('connect')->willReturn(true);
        if ($result instanceof \Throwable) {
            $redis->method('setOption')->willThrowException($result);
        } else {
            $redis->method('setOption')->willReturn($result);
        }
        $this->expectException(BackendException::class);
        $this->expectExceptionMessage('Connection option configuration failed.');
        (new TestablePhpRedisAdapter($redis))->connect($this->config());
    }

    public function setOptionFailureProvider(): array
    {
        return array('false' => array(false), 'exception' => array(new \RedisException('unsafe detail')));
    }

    /** @dataProvider connectionFailureProvider */
    public function test_connect_auth_and_select_failures(array $config, string $method, $failure, string $reason)
    {
        $methods = array('connect', 'setOption', 'getOption', 'script', 'clearLastError', $method);
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array_values(array_unique($methods)))->getMock();
        $redis->method('connect')->willReturn($method === 'connect' ? $failure : true);
        $this->allowOptionConfiguration($redis);
        $redis->method('script')->willReturn(false);
        if ($method !== 'connect') {
            $redis->method($method)->willReturn($failure);
        }

        try {
            (new TestablePhpRedisAdapter($redis))->connect($this->config($config));
            $this->fail('Expected connection setup to fail.');
        } catch (BackendException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function connectionFailureProvider(): array
    {
        return array(
            'connect false' => array(array(), 'connect', false, 'connect-failed'),
            'auth false' => array(array('password' => 'password'), 'auth', false, 'auth-failed'),
            'select false' => array(array('database' => 2), 'select', false, 'select-db-failed'),
        );
    }

    public function test_disconnected_adapter_returns_safe_defaults()
    {
        $adapter = new PhpRedisAdapter();

        $this->assertFalse($adapter->is_connected());
        $this->assertFalse($adapter->ping());
        $this->assertFalse($adapter->get('key'));
        $this->assertSame(array(false, false), $adapter->mget(array('one', 'two')));
        $this->assertFalse($adapter->set('key', 'value'));
        $this->assertSame(0, $adapter->del('key'));
        $this->assertSame(0, $adapter->del_multiple(array('key')));
        $this->assertSame(-2, $adapter->pttl('key'));
        $this->assertFalse($adapter->eval('script'));
        $this->assertSame(array(), $adapter->pipeline(array(array('set', array('key', 'value')))));
        $this->assertNull($adapter->server_info());
        $adapter->close();
        $this->assertFalse($adapter->supports_unlink());
    }

    public function test_connected_command_results_and_fallbacks()
    {
        $redis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('isConnected', 'ping', 'mget', 'set', 'del', 'pttl', 'eval', 'close'))
            ->getMock();
        $redis->method('isConnected')->willReturn(true);
        $redis->method('ping')->willThrowException(new \RedisException('failed'));
        $redis->method('mget')->willReturn(false);
        $redis->expects($this->exactly(2))->method('set')->withConsecutive(
            array('plain', 'value'),
            array('conditional', 'value', array('NX', 'XX', 'PX' => 50))
        )->willReturnOnConsecutiveCalls(true, false);
        $redis->expects($this->exactly(2))->method('del')->withConsecutive(array('one'), array('one', 'two'))->willReturnOnConsecutiveCalls(1, 2);
        $redis->method('pttl')->willReturnOnConsecutiveCalls(false, 1500);
        $redis->method('eval')->willReturn('result');
        $redis->expects($this->once())->method('close')->willReturn(true);
        $adapter = $this->adapterWithRedis($redis);

        $this->assertTrue($adapter->is_connected());
        $this->assertFalse($adapter->ping());
        $this->assertSame(array(false, false), $adapter->mget(array('one', 'two')));
        $this->assertTrue($adapter->set('plain', 'value'));
        $this->assertFalse($adapter->set('conditional', 'value', 50, true, true));
        $this->assertSame(1, $adapter->del('one'));
        $this->assertSame(2, $adapter->del_multiple(array('one', 'two')));
        $this->assertSame(-2, $adapter->pttl('one'));
        $this->assertSame(1500, $adapter->pttl('one'));
        $this->assertSame('result', $adapter->eval('script', array('key'), array('arg')));
        $adapter->close();
        $this->assertFalse($adapter->is_connected());
    }

    public function test_eval_uses_cached_sha_and_falls_back_to_eval_on_noscript()
    {
        $script = 'return ARGV[1]';
        $sha = sha1($script);
        $redis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('evalSha', 'getLastError', 'clearLastError', 'eval'))
            ->getMock();
        $redis->expects($this->once())->method('evalSha')->with($sha, array('key', 'arg'), 1)->willReturn(false);
        $redis->expects($this->once())->method('getLastError')->willReturn('NOSCRIPT No matching script.');
        $redis->expects($this->once())->method('clearLastError')->willReturn(true);
        $redis->expects($this->once())->method('eval')->with($script, array('key', 'arg'), 1)->willReturn(array('OK'));

        $adapter = $this->adapterWithRedis($redis);
        $property = new \ReflectionProperty(PhpRedisAdapter::class, 'script_shas');
        $property->setAccessible(true);
        $property->setValue($adapter, array($sha => $sha));

        $this->assertSame(array('OK'), $adapter->eval($script, array('key'), array('arg')));
    }

    public function test_eval_does_not_fallback_for_non_noscript_error()
    {
        $script = 'return 1';
        $sha = sha1($script);
        $redis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('evalSha', 'getLastError', 'eval'))
            ->getMock();
        $redis->method('evalSha')->willReturn(false);
        $redis->method('getLastError')->willReturn('NOPERM denied');
        $redis->expects($this->never())->method('eval');

        $adapter = $this->adapterWithRedis($redis);
        $property = new \ReflectionProperty(PhpRedisAdapter::class, 'script_shas');
        $property->setAccessible(true);
        $property->setValue($adapter, array($sha => $sha));

        $this->assertFalse($adapter->eval($script));
    }

    public function test_connect_rejects_phpredis_older_than_minimum()
    {
        $redis = $this->getMockBuilder(\Redis::class)->getMock();

        try {
            (new TestablePhpRedisAdapter($redis, '6.2.0'))->connect($this->config());
            $this->fail('Expected unsupported PhpRedis version to be rejected.');
        } catch (BackendException $e) {
            $this->assertSame('unsupported-extension', $e->reason());
            $this->assertStringContainsString('PhpRedis >= 6.3.0', $e->getMessage());
        }
    }

    public function test_delete_uses_unlink_when_supported()
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('unlink'))->getMock();
        $redis->expects($this->exactly(2))->method('unlink')->withConsecutive(array('key'), array('one', 'two'))->willReturnOnConsecutiveCalls(1, 2);
        $adapter = $this->adapterWithRedis($redis);
        $property = new \ReflectionProperty(PhpRedisAdapter::class, 'unlink_supported');
        $property->setAccessible(true);
        $property->setValue($adapter, true);

        $this->assertSame(1, $adapter->del('key'));
        $this->assertSame(2, $adapter->del_multiple(array('one', 'two')));
    }

    public function test_server_info_rejects_non_array_result()
    {
        $redis = $this->getMockBuilder(\Redis::class)->onlyMethods(array('serverName', 'serverVersion', 'info'))->getMock();
        $redis->method('serverName')->willReturn(false);
        $redis->method('serverVersion')->willReturn(false);
        $redis->method('info')->willReturn(false);
        $adapter = $this->adapterWithRedis($redis);

        $this->assertNull($adapter->server_info());
        $this->assertNull($adapter->cached_server_info());
    }
}
