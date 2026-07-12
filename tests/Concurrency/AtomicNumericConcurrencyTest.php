<?php
/**
 * Concurrency tests for atomic numeric operations (Phase 4).
 *
 * Forks PHP worker processes that race increment/decrement against a live
 * Redis/Valkey server. Proves no lost updates across thousands of operations.
 *
 * @package Mincemeat\ObjectCache
 * @group concurrency
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Concurrency;

use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use PHPUnit\Framework\TestCase;

class AtomicNumericConcurrencyTest extends TestCase
{
    /**
     * Number of parallel worker processes.
     */
    private const WORKERS = 8;

    /**
     * Increments per worker.
     */
    private const INCR_PER_WORKER = 500;

    protected function setUp(): void
    {
        parent::setUp();

        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6379);

        if (! class_exists(\Redis::class)) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('PhpRedis extension not available.');
            } else {
                $this->markTestSkipped('PhpRedis extension not available.');
            }
        }

        if (! function_exists('pcntl_fork')) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('pcntl extension is not available.');
            } else {
                $this->markTestSkipped('pcntl extension is not available.');
            }
        }

        // Probe connectivity with a raw Redis connection.
        $probe = new \Redis();
        $connected = @$probe->connect($host, $port, 1.0);
        if (! $connected) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('No Redis/Valkey server reachable at ' . $host . ':' . $port);
            } else {
                $this->markTestSkipped('No Redis/Valkey server reachable at ' . $host . ':' . $port);
            }
        }
        $probe->close();
    }

    public function test_parallel_increments_have_no_lost_updates()
    {
        $namespace = 'conc-incr-' . bin2hex(random_bytes(8));
        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6379);

        // Set up: initialize the value to 0.
        $config = new Config(array(
            'namespace'        => $namespace,
            'scheme'           => 'tcp',
            'host'             => $host,
            'port'             => $port,
            'database'         => 0,
            'connect_timeout'  => 1.0,
            'read_timeout'     => 1.0,
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        if (! $be->is_persistent()) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('Backend not persistent.');
            } else {
                $this->markTestSkipped('Backend not persistent.');
            }
        }

        $cache = new ObjectCache($ks, $be);
        $cache->set('counter', 0, 'options');
        $be->close();

        // Fork workers.
        $pids   = array();

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                    $this->fail('pcntl_fork failed.');
                } else {
                    $this->markTestSkipped('pcntl_fork not available.');
                }
            }

            if ($pid === 0) {
                // Child process.
                $config = new Config(array(
                    'namespace'        => $namespace,
                    'scheme'           => 'tcp',
                    'host'             => $host,
                    'port'             => $port,
                    'database'         => 0,
                    'connect_timeout'  => 2.0,
                    'read_timeout'     => 2.0,
                ));

                $ks_child = new KeySpace(false, 1);
                $be_child = new Backend($ks_child);
                $be_child->initialize($config);
                $child_cache = new ObjectCache($ks_child, $be_child);

                for ($j = 0; $j < self::INCR_PER_WORKER; $j++) {
                    $child_cache->incr('counter', 1, 'options');
                }

                $be_child->close();
                exit(0);
            }

            $pids[] = $pid;
        }

        // Wait for all workers.
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // Verify the result.
        $ks_check = new KeySpace(false, 1);
        $be_check = new Backend($ks_check);
        $be_check->initialize($config);
        $check_cache = new ObjectCache($ks_check, $be_check);

        $final = $check_cache->get('counter', 'options');
        $expected = self::WORKERS * self::INCR_PER_WORKER;

        $this->assertSame($expected, $final, 'Lost updates detected: expected ' . $expected . ' got ' . $final);

        $be_check->close();
    }

    public function test_parallel_decrements_clamp_at_zero()
    {
        $namespace = 'conc-decr-' . bin2hex(random_bytes(8));
        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6379);

        $config = new Config(array(
            'namespace'        => $namespace,
            'scheme'           => 'tcp',
            'host'             => $host,
            'port'             => $port,
            'database'         => 0,
            'connect_timeout'  => 1.0,
            'read_timeout'     => 1.0,
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);

        if (! $be->is_persistent()) {
            if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                $this->fail('Backend not persistent.');
            } else {
                $this->markTestSkipped('Backend not persistent.');
            }
        }

        $initial = 100;
        $cache = new ObjectCache($ks, $be);
        $cache->set('counter', $initial, 'options');
        $be->close();

        // Each worker decrements more times than the initial value; all
        // should clamp at zero.
        $workers     = 4;
        $decr_per    = 50;

        $pids = array();

        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                if (getenv('MINCEMEAT_REQUIRE_INTEGRATION')) {
                    $this->fail('pcntl_fork failed.');
                } else {
                    $this->markTestSkipped('pcntl_fork not available.');
                }
            }

            if ($pid === 0) {
                $child_config = new Config(array(
                    'namespace'        => $namespace,
                    'scheme'           => 'tcp',
                    'host'             => $host,
                    'port'             => $port,
                    'database'         => 0,
                    'connect_timeout'  => 2.0,
                    'read_timeout'     => 2.0,
                ));

                $ks_child = new KeySpace(false, 1);
                $be_child = new Backend($ks_child);
                $be_child->initialize($child_config);
                $child_cache = new ObjectCache($ks_child, $be_child);

                for ($j = 0; $j < $decr_per; $j++) {
                    $child_cache->decr('counter', 1, 'options');
                }

                $be_child->close();
                exit(0);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        $ks_check = new KeySpace(false, 1);
        $be_check = new Backend($ks_check);
        $be_check->initialize($config);
        $check_cache = new ObjectCache($ks_check, $be_check);

        // The total decrements far exceed the initial value, so the result
        // must be exactly 0 (never negative).
        $final = $check_cache->get('counter', 'options');
        $this->assertSame(0, $final, 'Decrement did not clamp at zero.');

        $be_check->close();
    }
}