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
use PHPUnit\Framework\TestCase;

class TestablePhpRedisAdapter extends PhpRedisAdapter
{
    private $mockRedis;

    public function __construct($mockRedis)
    {
        parent::__construct();
        $this->mockRedis = $mockRedis;
    }

    protected function create_redis_instance(): \Redis
    {
        return $this->mockRedis;
    }
}

class PhpRedisAdapterTest extends TestCase
{
    public function test_connect_with_tls_passes_stream_context()
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('Redis extension not available.');
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
            ->onlyMethods(array('connect', 'setOption'))
            ->getMock();

        $mockRedis->method('setOption')->willReturn(true);

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
            $this->markTestSkipped('Redis extension not available.');
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
        );
        $expectedId = 'mcoc:' . hash('sha256', serialize($canonical));

        $mockRedis = $this->getMockBuilder(\Redis::class)
            ->onlyMethods(array('pconnect', 'setOption'))
            ->getMock();

        $mockRedis->method('setOption')->willReturn(true);

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
}
