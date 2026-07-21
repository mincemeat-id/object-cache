<?php
/**
 * Unit tests for Config: strict parsing, unknown-key rejection, redaction,
 * and stable reason codes. Targets 100% branch coverage for validation.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\ConfigException;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class ConfigTest extends TestCase
{
    /**
     * Returns a minimal valid config array, optionally overriding keys.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function base(array $overrides = array()): array
    {
        return array_merge(
            array(
                'namespace'        => 'my-install',
                'scheme'           => 'tcp',
                'host'             => '127.0.0.1',
                'port'             => 6379,
                'database'         => 0,
                'connect_timeout'  => 0.25,
                'read_timeout'     => 0.25,
                'persistent'       => false,
                'max_ttl'          => 2592000,
                'debug'            => false,
            ),
            $overrides
        );
    }

    public function test_minimal_valid_config_applies_defaults()
    {
        $c = new Config(array('namespace' => 'ns'));

        $this->assertSame('ns', $c->namespace());
        $this->assertSame('tcp', $c->scheme());
        $this->assertSame('127.0.0.1', $c->host());
        $this->assertSame(6379, $c->port());
        $this->assertNull($c->path());
        $this->assertSame(0, $c->database());
        $this->assertNull($c->username());
        $this->assertNull($c->password());
        $this->assertSame(0.25, $c->connect_timeout());
        $this->assertSame(0.25, $c->read_timeout());
        $this->assertSame(1, $c->max_retries());
        $this->assertSame('decorrelated_jitter', $c->backoff_algorithm());
        $this->assertSame(10, $c->backoff_base());
        $this->assertSame(100, $c->backoff_cap());
        $this->assertTrue($c->tcp_keepalive());
        $this->assertFalse($c->persistent());
        $this->assertSame(2592000, $c->max_ttl());
        $this->assertFalse($c->debug());

        $this->assertSame(hash('sha256', 'ns'), $c->namespace_digest());
    }

    public function test_unix_scheme_ignores_host_port_and_requires_path()
    {
        $c = new Config(array('namespace' => 'ns', 'scheme' => 'unix', 'path' => '/var/run/redis.sock'));

        $this->assertSame('unix', $c->scheme());
        $this->assertSame('/var/run/redis.sock', $c->path());
        $this->assertSame('127.0.0.1', $c->host());
        $this->assertSame(6379, $c->port());

        // unix without path fails.
        $this->expectException(ConfigException::class);
        new Config(array('namespace' => 'ns', 'scheme' => 'unix'));
    }

    public function test_tls_scheme_accepts_tls_context()
    {
        $c = new Config(array(
            'namespace' => 'ns',
            'scheme'     => 'tls',
            'host'       => 'cache.internal',
            'tls'        => array('verify_peer' => true, 'peer_name' => 'cache.internal'),
        ));

        $this->assertSame('tls', $c->scheme());
        $this->assertSame('cache.internal', $c->host());
        $this->assertTrue($c->tls_verify_peer());
        $this->assertTrue($c->tls_verify_peer_name());
    }

    public function test_acl_credentials_are_accepted()
    {
        $c = new Config(array('namespace' => 'ns', 'username' => 'cache', 'password' => 'secret'));

        $this->assertSame('cache', $c->username());
        $this->assertSame('secret', $c->password());
    }

    public function test_unknown_key_is_rejected_with_reason_code()
    {
        try {
            new Config(array('namespace' => 'ns', 'bogus' => 1));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_UNKNOWN_KEY, $e->reason());
        }
    }

    /** @dataProvider secret_shaped_unknown_keys */
    public function test_unknown_key_error_never_echoes_supplied_key($key)
    {
        try {
            new Config(array('namespace' => 'ns', $key => 1));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_UNKNOWN_KEY, $e->reason());
            $this->assertSame(Config::REASON_UNKNOWN_KEY, $e->getMessage());
            $this->assertStringNotContainsString((string) $key, $e->getMessage());
        }
    }

    public function secret_shaped_unknown_keys(): array
    {
        return array(
            'credential' => array('password_super-secret-value'),
            'dsn' => array('redis://secret-user:secret-pass@private.example:6380'),
            'socket path' => array('/private/run/secret-cache.sock'),
            'raw cache key' => array('wp:options:secret-key-material'),
            'integer key' => array(42),
        );
    }

    /**
     * @dataProvider invalid_namespace
     */
    public function test_namespace_validation($value, string $reason)
    {
        try {
            new Config(array('namespace' => $value));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function invalid_namespace(): array
    {
        return array(
            'not string'   => array(false, Config::REASON_NAMESPACE),
            'empty'        => array('', Config::REASON_NAMESPACE),
            'blank spaces' => array('   ', Config::REASON_NAMESPACE),
            'null byte'    => array("ns\0bad", Config::REASON_NAMESPACE),
            'tab'          => array("ns\tbad", Config::REASON_NAMESPACE),
            'too long'      => array(str_repeat('x', 256), Config::REASON_NAMESPACE),
        );
    }

    public function test_scheme_must_be_known()
    {
        try {
            new Config(array('namespace' => 'ns', 'scheme' => 'tls+unix'));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_SCHEME, $e->reason());
        }
    }

    public function test_host_required_for_tcp()
    {
        try {
            new Config(array('namespace' => 'ns', 'scheme' => 'tcp', 'host' => ''));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_HOST, $e->reason());
        }
    }

    /**
     * @dataProvider invalid_port
     */
    public function test_port_validation($value, string $reason)
    {
        try {
            new Config(array('namespace' => 'ns', 'port' => $value));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function invalid_port(): array
    {
        return array(
            'non-numeric' => array('abc', Config::REASON_PORT),
            'below min'    => array(0, Config::REASON_PORT),
            'above max'    => array(70000, Config::REASON_PORT),
            'float'        => array(1.5, Config::REASON_PORT),
        );
    }

    public function test_string_numeric_port_is_accepted()
    {
        $c = new Config(array('namespace' => 'ns', 'port' => '6380'));
        $this->assertSame(6380, $c->port());
    }

    /**
     * @dataProvider invalid_database
     */
    public function test_database_validation($value, string $reason)
    {
        try {
            new Config(array('namespace' => 'ns', 'database' => $value));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function invalid_database(): array
    {
        return array(
            'negative'    => array(-1, Config::REASON_DATABASE),
            'non-numeric' => array('abc', Config::REASON_DATABASE),
            'float'        => array(0.5, Config::REASON_DATABASE),
        );
    }

    public function test_username_must_be_null_or_string()
    {
        $this->expectException(ConfigException::class);
        new Config(array('namespace' => 'ns', 'username' => 123));
    }

    public function test_password_must_be_null_or_string()
    {
        $this->expectException(ConfigException::class);
        new Config(array('namespace' => 'ns', 'password' => array()));
    }

    /**
     * @dataProvider invalid_timeout
     */
    public function test_timeout_validation($value, string $field, string $reason)
    {
        try {
            new Config(array('namespace' => 'ns', $field => $value));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function invalid_timeout(): array
    {
        return array(
            'connect zero'     => array(0, 'connect_timeout', Config::REASON_CONNECT_TIMEOUT),
            'connect negative' => array(-0.1, 'connect_timeout', Config::REASON_CONNECT_TIMEOUT),
            'connect too big'  => array(61, 'connect_timeout', Config::REASON_CONNECT_TIMEOUT),
            'connect string'   => array('abc', 'connect_timeout', Config::REASON_CONNECT_TIMEOUT),
            'read zero'        => array(0, 'read_timeout', Config::REASON_READ_TIMEOUT),
            'read negative'    => array(-1, 'read_timeout', Config::REASON_READ_TIMEOUT),
            'read too big'     => array(120, 'read_timeout', Config::REASON_READ_TIMEOUT),
            'read nulbytes'    => array("5\0", 'read_timeout', Config::REASON_READ_TIMEOUT),
        );
    }

    public function test_numeric_string_timeout_accepted()
    {
        $c = new Config(array('namespace' => 'ns', 'connect_timeout' => '0.5', 'read_timeout' => '1.0'));
        $this->assertSame(0.5, $c->connect_timeout());
        $this->assertSame(1.0, $c->read_timeout());
    }

    public function test_bounded_retry_configuration_is_accepted()
    {
        $c = new Config(array(
            'namespace'         => 'ns',
            'max_retries'      => 2,
            'backoff_algorithm' => 'constant',
            'backoff_base'     => 25,
            'backoff_cap'      => 50,
            'tcp_keepalive'    => false,
        ));

        $this->assertSame(2, $c->max_retries());
        $this->assertSame('constant', $c->backoff_algorithm());
        $this->assertSame(25, $c->backoff_base());
        $this->assertSame(50, $c->backoff_cap());
        $this->assertFalse($c->tcp_keepalive());
    }

    /**
     * @dataProvider invalid_retry_configuration
     */
    public function test_retry_configuration_validation(array $values, string $reason)
    {
        try {
            new Config(array_merge(array('namespace' => 'ns'), $values));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function invalid_retry_configuration(): array
    {
        return array(
            'negative retries' => array(array('max_retries' => -1), Config::REASON_MAX_RETRIES),
            'excess retries' => array(array('max_retries' => 4), Config::REASON_MAX_RETRIES),
            'retry float' => array(array('max_retries' => 1.5), Config::REASON_MAX_RETRIES),
            'unknown algorithm' => array(array('backoff_algorithm' => 'random'), Config::REASON_BACKOFF),
            'negative base' => array(array('backoff_base' => -1), Config::REASON_BACKOFF),
            'excess cap' => array(array('backoff_cap' => 1001), Config::REASON_BACKOFF),
            'cap below base' => array(array('backoff_base' => 100, 'backoff_cap' => 50), Config::REASON_BACKOFF),
            'invalid keepalive' => array(array('tcp_keepalive' => 'yes'), Config::REASON_TCP_KEEPALIVE),
        );
    }

    public function test_persistent_must_be_bool()
    {
        $this->expectException(ConfigException::class);
        new Config(array('namespace' => 'ns', 'persistent' => 'yes'));
    }

    public function test_int_zero_one_persistent_accepted_as_bool()
    {
        $c = new Config(array('namespace' => 'ns', 'persistent' => 1));
        $this->assertTrue($c->persistent());

        $c2 = new Config(array('namespace' => 'ns', 'persistent' => 0));
        $this->assertFalse($c2->persistent());
    }

    /**
     * @dataProvider invalid_max_ttl
     */
    public function test_max_ttl_validation($value, string $reason)
    {
        try {
            new Config(array('namespace' => 'ns', 'max_ttl' => $value));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame($reason, $e->reason());
        }
    }

    public function invalid_max_ttl(): array
    {
        return array(
            'negative'    => array(-1, Config::REASON_MAX_TTL),
            'non-numeric' => array('abc', Config::REASON_MAX_TTL),
            'float'        => array(1.5, Config::REASON_MAX_TTL),
        );
    }

    public function test_max_ttl_zero_permits_unbounded()
    {
        $c = new Config(array('namespace' => 'ns', 'max_ttl' => 0));
        $this->assertSame(0, $c->max_ttl());
    }

    public function test_tls_non_array_rejected()
    {
        $this->expectException(ConfigException::class);
        new Config(array('namespace' => 'ns', 'tls' => 'invalid'));
    }

    /** @dataProvider malformed_tls_contexts */
    public function test_tls_rejects_nested_or_non_string_key_values(array $tls)
    {
        try {
            new Config(array('namespace' => 'ns', 'tls' => $tls));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_TLS, $e->reason());
            $this->assertSame('TLS context values must be scalar or null.', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function malformed_tls_contexts(): array
    {
        return array(
            'nested array' => array(array('cafile' => array('/secret/ca.pem'))),
            'object' => array(array('cafile' => (object) array('path' => '/secret/ca.pem'))),
            'numeric option key' => array(array('/secret/ca.pem')),
        );
    }

    public function test_tls_rejects_resource_value_without_echoing_it()
    {
        $resource = fopen('php://memory', 'rb');
        $this->assertIsResource($resource);

        try {
            new Config(array('namespace' => 'ns', 'tls' => array('cafile' => $resource)));
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_TLS, $e->reason());
            $this->assertSame('TLS context values must be scalar or null.', $e->getMessage());
        } finally {
            fclose($resource);
        }
    }

    public function test_debug_must_be_bool()
    {
        $this->expectException(ConfigException::class);
        new Config(array('namespace' => 'ns', 'debug' => 2));
    }

    public function test_from_constant_missing_throws()
    {
        // MINCEMEAT_OBJECT_CACHE_CONFIG is not defined in the test environment.
        if (defined('MINCEMEAT_OBJECT_CACHE_CONFIG')) {
            $this->markTestSkipped('Constant already defined.');
        }

        try {
            Config::from_constant();
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_MISSING, $e->reason());
        }
    }

    public function test_from_constant_not_array_throws()
    {
        if (defined('MINCEMEAT_OBJECT_CACHE_CONFIG')) {
            $this->markTestSkipped('Constant already defined.');
        }

        define('MINCEMEAT_OBJECT_CACHE_CONFIG', 'not-an-array');

        try {
            Config::from_constant();
            $this->fail('Expected ConfigException');
        } catch (ConfigException $e) {
            $this->assertSame(Config::REASON_NOT_ARRAY, $e->reason());
        }
    }

    public function test_redacted_diagnostics_never_leaks_credentials_or_namespace()
    {
        $c = new Config(array(
            'namespace' => 'secret-namespace',
            'username'   => 'cache-user',
            'password'   => 'cache-pass',
            'scheme'     => 'tls',
            'host'       => 'cache.internal',
            'tls'        => array(
                'local_cert'      => '/secret/cert.pem',
                'local_pk'        => '/secret/key.pem',
                'cafile'          => '/secret/ca.pem',
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ),
        ));

        // 1. Test Debug mode diagnostics (default is false if we call redacted_diagnostics(false))
        $d       = $c->redacted_diagnostics(false);
        $dump    = serialize($d);

        $this->assertStringNotContainsString('secret-namespace', $dump);
        $this->assertStringNotContainsString('cache-user', $dump);
        $this->assertStringNotContainsString('cache-pass', $dump);
        $this->assertStringNotContainsString('/secret/', $dump);
        $this->assertStringNotContainsString('cert.pem', $dump);
        $this->assertStringNotContainsString('key.pem', $dump);

        // Endpoint identity remains classified in every diagnostics mode.
        $this->assertSame('configured', $d['host']);
        $this->assertSame('***', $d['port']);
        $this->assertSame('***', $d['database']);
        $this->assertSame(1, $d['max_retries']);
        $this->assertSame('decorrelated_jitter', $d['backoff_algorithm']);
        $this->assertSame(10, $d['backoff_base_ms']);
        $this->assertSame(100, $d['backoff_cap_ms']);
        $this->assertTrue($d['tcp_keepalive']);

        // Only the first 16 hex chars of the digest are exposed.
        $this->assertSame(substr(hash('sha256', 'secret-namespace'), 0, 16), $d['namespace_digest']);
        $this->assertFalse($d['tls']['verify_peer']);
        $this->assertFalse($d['tls']['verify_peer_name']);

        // 2. Test Public mode diagnostics (when $public is true)
        $d_public = $c->redacted_diagnostics(true);
        $this->assertSame('configured', $d_public['host']);
        $this->assertSame('***', $d_public['port']);
        $this->assertSame('***', $d_public['database']);
        
        // Supplied loopback and remote endpoints receive the same classification.
        $c_local = new Config(array('namespace' => 'ns', 'host' => '127.0.0.1'));
        $d_local = $c_local->redacted_diagnostics(true);
        $this->assertSame('configured', $d_local['host']);
        
        // Remote IPs are masked
        $c_remote_ip = new Config(array('namespace' => 'ns', 'host' => '10.0.0.5'));
        $d_remote_ip = $c_remote_ip->redacted_diagnostics(true);
        $this->assertSame('configured', $d_remote_ip['host']);

        // Unix paths are classified without retaining their basename.
        $c_unix = new Config(array('namespace' => 'ns', 'scheme' => 'unix', 'path' => '/var/run/redis.sock'));
        $d_unix = $c_unix->redacted_diagnostics(true);
        $this->assertSame('configured', $d_unix['path']);
    }

    public function test_known_keys_returns_expected_set()
    {
        $keys = Config::known_keys();

        $this->assertContains('namespace', $keys);
        $this->assertContains('scheme', $keys);
        $this->assertContains('host', $keys);
        $this->assertContains('port', $keys);
        $this->assertContains('path', $keys);
        $this->assertContains('database', $keys);
        $this->assertContains('username', $keys);
        $this->assertContains('password', $keys);
        $this->assertContains('connect_timeout', $keys);
        $this->assertContains('read_timeout', $keys);
        $this->assertContains('max_retries', $keys);
        $this->assertContains('backoff_algorithm', $keys);
        $this->assertContains('backoff_base', $keys);
        $this->assertContains('backoff_cap', $keys);
        $this->assertContains('tcp_keepalive', $keys);
        $this->assertContains('persistent', $keys);
        $this->assertContains('max_ttl', $keys);
        $this->assertContains('tls', $keys);
        $this->assertContains('debug', $keys);
    }
}
