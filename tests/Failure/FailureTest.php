<?php
/**
 * Unit/Integration tests for Phase 6 — graceful failure and recovery.
 *
 * @package Mincemeat\ObjectCache
 * @group failure
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Failure;

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\BackendException;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\ConfigException;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\PhpRedisAdapter;
use Mincemeat\ObjectCache\ValueCodec;
use PHPUnit\Framework\TestCase;

class FailureTest extends TestCase
{
    private array $logged_messages = array();

    protected function setUp(): void
    {
        parent::setUp();
        $this->logged_messages = array();
        $GLOBALS['__test_error_log_callback'] = function ($msg) {
            $this->logged_messages[] = $msg;
        };
        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
        $GLOBALS['__mincemeat_deprecated']      = array();
        $GLOBALS['__mincemeat_actions']          = array();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__test_error_log_callback']);
        unset($GLOBALS['wp_object_cache']);
        if (defined('MINCEMEAT_OBJECT_CACHE_CONFIG')) {
            // PHP does not support undefining constants, but we clean up global cache
        }
        parent::tearDown();
    }

    private function get_config(array $overrides = array()): Config
    {
        $defaults = array(
            'namespace'       => 'test-namespace',
            'scheme'          => 'tcp',
            'host'            => '127.0.0.1',
            'port'            => 6379,
            'connect_timeout' => 0.1,
            'read_timeout'    => 0.1,
            'password'        => 'secret-password',
            'username'        => 'secret-username',
            'debug'           => true,
        );
        return new Config(array_merge($defaults, $overrides));
    }

    /**
     * Helper to assert that credentials are not in any logs or diagnostics.
     */
    private function assertSecretFree(array $dataOrStrings): void
    {
        foreach ($dataOrStrings as $item) {
            $str = is_array($item) ? json_encode($item) : (string) $item;
            $this->assertStringNotContainsString('secret-password', $str);
            $this->assertStringNotContainsString('secret-username', $str);
        }
    }

    public function test_initialization_failure_missing_extension()
    {
        $config = $this->get_config();
        $key_space = new KeySpace(false, 1);
        $adapter = new MockPhpRedisAdapter();
        $adapter->connect_callback = function ($cfg) {
            throw new BackendException('missing-extension', 'The PhpRedis extension is not available.');
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $backend->state());
        $this->assertSame('missing-extension', $backend->reason());

        // Verify logging occurred once, and was secret-free
        $this->assertCount(1, $this->logged_messages);
        $this->assertStringContainsString('Initialization failed: missing-extension', $this->logged_messages[0]);
        $this->assertSecretFree($this->logged_messages);

        // Verify diagnostics
        $cache = new ObjectCache($key_space, $backend);
        $GLOBALS['wp_object_cache'] = $cache;
        $diag = Api::diagnostics();
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $diag['state']);
        $this->assertSame('missing-extension', $diag['reason']);
        $this->assertSecretFree($diag);
    }

    public function test_initialization_failure_refused_connection()
    {
        $config = $this->get_config();
        $key_space = new KeySpace(false, 1);
        $adapter = new MockPhpRedisAdapter();
        $adapter->connect_callback = function ($cfg) {
            throw new BackendException('connect-failed', 'Connection refused.');
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $backend->state());
        $this->assertSame('connect-failed', $backend->reason());

        $this->assertCount(1, $this->logged_messages);
        $this->assertStringContainsString('Initialization failed: connect-failed', $this->logged_messages[0]);
        $this->assertSecretFree($this->logged_messages);
    }

    public function test_initialization_failure_bad_credentials()
    {
        $config = $this->get_config();
        $key_space = new KeySpace(false, 1);
        $adapter = new MockPhpRedisAdapter();
        $adapter->connect_callback = function ($cfg) {
            throw new BackendException('auth-failed', 'Client sent secret-password which failed auth.');
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $backend->state());
        $this->assertSame('auth-failed', $backend->reason());

        // Verify that the secret was redacted in the log message!
        $this->assertCount(1, $this->logged_messages);
        $this->assertStringContainsString('Initialization failed: auth-failed', $this->logged_messages[0]);
        $this->assertStringNotContainsString('secret-password', $this->logged_messages[0]);
        $this->assertStringContainsString('[REDACTED]', $this->logged_messages[0]);
    }

    public function test_initialization_failure_bad_database()
    {
        $config = $this->get_config();
        $key_space = new KeySpace(false, 1);
        $adapter = new MockPhpRedisAdapter();
        $adapter->connect_callback = function ($cfg) {
            throw new BackendException('select-db-failed', 'Database selection failed.');
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $backend->state());
        $this->assertSame('select-db-failed', $backend->reason());
    }

    public function test_config_invalid_via_facade()
    {
        if (defined('MINCEMEAT_OBJECT_CACHE_CONFIG')) {
            $this->markTestSkipped('MINCEMEAT_OBJECT_CACHE_CONFIG constant already defined; skipping facade test.');
        }

        // Define invalid config constant
        define('MINCEMEAT_OBJECT_CACHE_CONFIG', array(
            'namespace' => 'test-ns',
            'password'  => 'secret-password',
            'username'  => 'secret-username',
            'scheme'    => 'invalid-scheme'
        ));

        wp_cache_init();

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, Api::status()['state']);
        $this->assertSame('config-invalid', Api::status()['reason']);
        $this->assertCount(1, $this->logged_messages);
        $this->assertStringContainsString('Initialization failed: config-invalid', $this->logged_messages[0]);
        $this->assertSecretFree($this->logged_messages);
        $this->assertSecretFree(Api::diagnostics());
    }

    public function test_command_exception_opens_circuit_deduplicates_logs_fires_action()
    {
        $config = $this->get_config();
        $key_space = new KeySpace(false, 1);
        $adapter = new MockPhpRedisAdapter();
        
        // Connect successfully
        $adapter->connect_callback = function ($cfg) {};
        
        // Mock get/mget/set behavior to simulate healthy first commands
        $adapter->get_callback = function ($k) {
            // First time it returns control token
            return 'token123';
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);

        $cache = new ObjectCache($key_space, $backend);
        $GLOBALS['wp_object_cache'] = $cache;

        $this->assertSame(ObjectCache::STATE_PERSISTENT, $cache->state());

        // Make subsequent command throw exception (e.g. timeout / connection closed)
        $adapter->get_callback = function ($k) {
            throw new \RedisException('Connection to Redis closed mysteriously with secret-password!');
        };

        // Trigger command failure
        $found = null;
        $val = $cache->get('k1', 'group1', false, $found);

        $this->assertFalse($val);
        $this->assertFalse($found);

        // Verify circuit is now degraded and open
        $this->assertSame(ObjectCache::STATE_DEGRADED, $cache->state());
        $this->assertSame('command-failed', $cache->reason());

        // Verify action fired exactly once
        $this->assertArrayHasKey('mincemeat_object_cache_degraded', $GLOBALS['__mincemeat_actions']);
        $this->assertCount(1, $GLOBALS['__mincemeat_actions']['mincemeat_object_cache_degraded']);
        $this->assertSame(array('command-failed'), $GLOBALS['__mincemeat_actions']['mincemeat_object_cache_degraded'][0]);

        // Verify error logged exactly once, and secret password is redacted
        $this->assertCount(1, $this->logged_messages);
        $this->assertStringContainsString('Backend degraded: command-failed', $this->logged_messages[0]);
        $this->assertStringNotContainsString('secret-password', $this->logged_messages[0]);
        $this->assertStringContainsString('[REDACTED]', $this->logged_messages[0]);

        // Subsequent get/set operations should be served in memory and should NOT attempt backend connection/commands
        // Let's set callbacks to throw exception if called, to prove the circuit is open and no backend command is attempted.
        $adapter->get_callback = function ($k) {
            $this->fail('Backend adapter get called after circuit was opened.');
        };
        $adapter->set_callback = function ($k, $v) {
            $this->fail('Backend adapter set called after circuit was opened.');
        };

        // Cache writes/reads in degraded state
        $this->assertTrue($cache->set('k2', 'val2', 'group1'));
        $this->assertSame('val2', $cache->get('k2', 'group1'));

        // No new logs should be recorded (deduplicated)
        $this->assertCount(1, $this->logged_messages);
    }

    public function test_corrupt_payload_logs_deletes_best_effort_returns_miss()
    {
        $config = $this->get_config();
        $key_space = new KeySpace(false, 1);
        $adapter = new MockPhpRedisAdapter();

        $adapter->connect_callback = function ($cfg) {};
        $adapter->get_callback = function ($k) {
            if (strpos($k, 'ns_control') !== false) {
                return 'ns_token';
            }
            if (strpos($k, 'grp_control') !== false) {
                return 'grp_token';
            }
            // Return corrupt payload that violates Magic or Version
            return 'corrupted-payload-bad-bytes';
        };

        $deleted_keys = array();
        $adapter->del_callback = function ($k) use (&$deleted_keys) {
            $deleted_keys[] = $k;
            return 1;
        };

        $backend = new Backend($key_space, $adapter);
        $backend->initialize($config);

        $cache = new ObjectCache($key_space, $backend);
        $GLOBALS['wp_object_cache'] = $cache;

        $found = null;
        $val = $cache->get('corrupt_key', 'group1', false, $found);

        $this->assertFalse($val);
        $this->assertFalse($found);

        // Verify that the error category was logged
        $this->assertCount(1, $this->logged_messages);
        $this->assertStringContainsString('Value codec decode failed: decode-magic', $this->logged_messages[0]);

        // Verify it deleted the bad key best-effort
        $this->assertCount(1, $deleted_keys);
        $ns_tok = $backend->namespace_token();
        $grp_tok = $backend->group_token('group1');
        $expected_key = $cache->key_space()->item_key($ns_tok, $grp_tok, 'group1', 'corrupt_key');
        $this->assertSame($expected_key, $deleted_keys[0]);
    }
}

class MockPhpRedisAdapter extends PhpRedisAdapter
{
    public $connect_callback;
    public $get_callback;
    public $set_callback;
    public $mget_callback;
    public $eval_callback;
    public $pipeline_callback;
    public $del_callback;

    public function connect(Config $config): void
    {
        if ($this->connect_callback) {
            call_user_func($this->connect_callback, $config);
        }
    }

    public function get(string $key)
    {
        if ($this->get_callback) {
            return call_user_func($this->get_callback, $key);
        }
        return false;
    }

    public function set(string $key, string $value, ?int $ttl_ms = null, bool $nx = false, bool $xx = false): bool
    {
        if ($this->set_callback) {
            return call_user_func($this->set_callback, $key, $value, $ttl_ms, $nx, $xx);
        }
        return true;
    }

    public function set_unconditional(string $key, string $value, ?int $ttl_ms = null): bool
    {
        return $this->set($key, $value, $ttl_ms, false, false);
    }

    public function mget(array $keys): array
    {
        if ($this->mget_callback) {
            return call_user_func($this->mget_callback, $keys);
        }
        return array_fill(0, count($keys), false);
    }

    public function eval(string $script, array $keys = array(), array $args = array())
    {
        if ($this->eval_callback) {
            return call_user_func($this->eval_callback, $script, $keys, $args);
        }
        return false;
    }

    public function del(string $key): int
    {
        if ($this->del_callback) {
            return call_user_func($this->del_callback, $key);
        }
        return 1;
    }

    public function pipeline(array $commands): array
    {
        if ($this->pipeline_callback) {
            return call_user_func($this->pipeline_callback, $commands);
        }
        return array_fill(0, count($commands), true);
    }
}

namespace Mincemeat\ObjectCache;

if (!function_exists('Mincemeat\ObjectCache\error_log')) {
    function error_log(string $message): void {
        if (isset($GLOBALS['__test_error_log_callback'])) {
            call_user_func($GLOBALS['__test_error_log_callback'], $message);
        } else {
            \error_log($message);
        }
    }
}
