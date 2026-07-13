<?php
/**
 * Connection scenarios integration tests: TLS, ACL, Unix Socket, and Persistent pooling.
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
class ConnectionScenariosTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\Redis::class)) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('PhpRedis extension not available in release CI.');
            }
            $this->markTestSkipped('PhpRedis extension not available.');
        }
    }

    public function test_unix_socket_connection()
    {
        $socket_path = getenv('MINCEMEAT_TEST_UNIX_SOCKET');
        if (!$socket_path) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('MINCEMEAT_TEST_UNIX_SOCKET env var not set in release CI.');
            }
            $this->markTestSkipped('MINCEMEAT_TEST_UNIX_SOCKET env var not set.');
        }

        $config = new Config(array(
            'namespace' => 'test-unix',
            'scheme'    => 'unix',
            'path'      => $socket_path,
            'database'  => 0,
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        $this->assertTrue($be->is_persistent(), 'Unix socket backend is not persistent.');

        $cache = new ObjectCache($ks, $be);
        $this->assertTrue($cache->set('foo', 'bar', 'options'));
        $this->assertSame('bar', $cache->get('foo', 'options'));

        $be->close();
    }

    public function test_acl_connection()
    {
        $port = getenv('MINCEMEAT_TEST_ACL_PORT');
        $username = getenv('MINCEMEAT_TEST_ACL_USER');
        $password = getenv('MINCEMEAT_TEST_ACL_PASS');

        if (!$port || !$username || !$password) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('MINCEMEAT_TEST_ACL_* env vars not fully set in release CI.');
            }
            $this->markTestSkipped('MINCEMEAT_TEST_ACL_* env vars not fully set.');
        }

        $config = new Config(array(
            'namespace' => 'test-acl',
            'scheme'    => 'tcp',
            'host'      => '127.0.0.1',
            'port'      => (int)$port,
            'username'  => $username,
            'password'  => $password,
            'database'  => 0,
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        $this->assertTrue($be->is_persistent(), 'ACL backend is not persistent.');

        $cache = new ObjectCache($ks, $be);
        $this->assertTrue($cache->set('foo', 'bar', 'options'));
        $this->assertSame('bar', $cache->get('foo', 'options'));

        $be->close();
    }

    public function test_tls_connection()
    {
        $port = getenv('MINCEMEAT_TEST_TLS_PORT');
        if (!$port) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('MINCEMEAT_TEST_TLS_PORT env var not set in release CI.');
            }
            $this->markTestSkipped('MINCEMEAT_TEST_TLS_PORT env var not set.');
        }

        // Configure connection with self-signed certificate validation disabled for testing
        $config = new Config(array(
            'namespace' => 'test-tls',
            'scheme'    => 'tls',
            'host'      => '127.0.0.1',
            'port'      => (int)$port,
            'database'  => 0,
            'tls'       => array(
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ),
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        $this->assertTrue($be->is_persistent(), 'TLS backend is not persistent.');

        $cache = new ObjectCache($ks, $be);
        $this->assertTrue($cache->set('foo', 'bar', 'options'));
        $this->assertSame('bar', $cache->get('foo', 'options'));

        $be->close();
    }

    public function test_tls_connection_verified()
    {
        $port = getenv('MINCEMEAT_TEST_TLS_PORT');
        $ca_file = getenv('MINCEMEAT_TEST_TLS_CA');
        if (!$port || !$ca_file) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('MINCEMEAT_TEST_TLS_PORT / CA env vars not set in release CI.');
            }
            $this->markTestSkipped('MINCEMEAT_TEST_TLS_PORT or CA env var not set.');
        }

        // Configure connection with CA certificate verification enabled
        $config = new Config(array(
            'namespace' => 'test-tls-verify',
            'scheme'    => 'tls',
            'host'      => '127.0.0.1',
            'port'      => (int)$port,
            'database'  => 0,
            'tls'       => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => $ca_file,
            ),
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        $this->assertTrue($be->is_persistent(), 'Verified TLS backend is not persistent.');

        $cache = new ObjectCache($ks, $be);
        $this->assertTrue($cache->set('foo', 'bar', 'options'));
        $this->assertSame('bar', $cache->get('foo', 'options'));

        $be->close();
    }

    public function test_tls_connection_fails_with_untrusted_ca()
    {
        $port = getenv('MINCEMEAT_TEST_TLS_PORT');
        $untrusted_ca = getenv('MINCEMEAT_TEST_TLS_UNTRUSTED_CA');
        if (!$port || !$untrusted_ca) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('MINCEMEAT_TEST_TLS_PORT / UNTRUSTED CA env vars not set in release CI.');
            }
            $this->markTestSkipped('MINCEMEAT_TEST_TLS_PORT or UNTRUSTED CA env var not set.');
        }

        $config = new Config(array(
            'namespace' => 'test-tls-fail',
            'scheme'    => 'tls',
            'host'      => '127.0.0.1',
            'port'      => (int)$port,
            'database'  => 0,
            'tls'       => array(
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => $untrusted_ca,
            ),
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $be->state());
        $this->assertSame('connect-failed', $be->reason());

        $cache = new ObjectCache($ks, $be);
        $GLOBALS['wp_object_cache'] = $cache;

        // WordPress request flow remains available (falls back to memory tier)
        $this->assertTrue($cache->set('foo', 'bar', 'options'));
        $this->assertSame('bar', $cache->get('foo', 'options'));

        // Site Health diagnostics reports degraded/failed state without secrets
        $diag = \Mincemeat\ObjectCache\Api::diagnostics();
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $diag['state']);
        $this->assertSame('connect-failed', $diag['reason']);
        
        $diag_str = json_encode($diag);
        $this->assertStringNotContainsString($untrusted_ca, $diag_str);

        // Metrics captures the error state
        $metrics = \Mincemeat\ObjectCache\Api::metrics();
        $this->assertGreaterThanOrEqual(1, $metrics['errors'] ?? 0);

        unset($GLOBALS['wp_object_cache']);
        $be->close();
    }

    public function test_acl_authentication_failure()
    {
        $port = getenv('MINCEMEAT_TEST_ACL_PORT');
        $username = getenv('MINCEMEAT_TEST_ACL_USER');

        if (!$port || !$username) {
            if (getenv('MINCEMEAT_RELEASE_CI')) {
                $this->fail('MINCEMEAT_TEST_ACL_* env vars not fully set in release CI.');
            }
            $this->markTestSkipped('MINCEMEAT_TEST_ACL_* env vars not fully set.');
        }

        $config = new Config(array(
            'namespace' => 'test-acl-fail',
            'scheme'    => 'tcp',
            'host'      => '127.0.0.1',
            'port'      => (int)$port,
            'username'  => $username,
            'password'  => 'wrong-password', // invalid credentials
            'database'  => 0,
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $be->state());
        $this->assertSame('auth-failed', $be->reason());

        $cache = new ObjectCache($ks, $be);
        $GLOBALS['wp_object_cache'] = $cache;

        // WordPress request flow remains available (falls back to memory tier)
        $this->assertTrue($cache->set('foo', 'bar', 'options'));
        $this->assertSame('bar', $cache->get('foo', 'options'));

        // Site Health diagnostics reports degraded/failed state without secrets
        $diag = \Mincemeat\ObjectCache\Api::diagnostics();
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $diag['state']);
        $this->assertSame('auth-failed', $diag['reason']);
        
        $diag_str = json_encode($diag);
        $this->assertStringNotContainsString('wrong-password', $diag_str);

        // Metrics captures the error state
        $metrics = \Mincemeat\ObjectCache\Api::metrics();
        $this->assertGreaterThanOrEqual(1, $metrics['errors'] ?? 0);

        unset($GLOBALS['wp_object_cache']);
        $be->close();
    }

    public function test_persistent_pooling_connection_isolation()
    {
        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6383);

        // Define two configs with different ports or namespaces but persistent pooling enabled.
        $config1 = new Config(array(
            'namespace'  => 'pool-ns-1',
            'scheme'     => 'tcp',
            'host'       => $host,
            'port'       => $port,
            'persistent' => true,
            'database'   => 0,
        ));

        $config2 = new Config(array(
            'namespace'  => 'pool-ns-2',
            'scheme'     => 'tcp',
            'host'       => $host,
            'port'       => $port,
            'persistent' => true,
            'database'   => 0,
        ));

        $ks1 = new KeySpace(false, 1);
        $be1 = new Backend($ks1);
        $be1->initialize($config1);

        $ks2 = new KeySpace(false, 1);
        $be2 = new Backend($ks2);
        $be2->initialize($config2);

        $this->assertTrue($be1->is_persistent());
        $this->assertTrue($be2->is_persistent());

        $cache1 = new ObjectCache($ks1, $be1);
        $cache2 = new ObjectCache($ks2, $be2);

        $this->assertTrue($cache1->set('shared-key', 'value-1', 'options'));
        $this->assertTrue($cache2->set('shared-key', 'value-2', 'options'));

        // Assert keys do not collide because of namespaces even though persistent connections are reused
        $this->assertSame('value-1', $cache1->get('shared-key', 'options'));
        $this->assertSame('value-2', $cache2->get('shared-key', 'options'));

        $be1->close();
        $be2->close();
    }
}
