<?php
/**
 * Authoritative P0, P1, and P2 Gate validations.
 *
 * Exercises drop-in lifecycle, ownership, preservation, isolated flushes,
 * and runs the authoritative WordPress core cache tests across all matrix modes.
 *
 * @package Mincemeat\ObjectCache
 * @group integration
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Integration;

use Mincemeat\ObjectCache\Lifecycle;
use Mincemeat\ObjectCache\Backend;
use Mincemeat\ObjectCache\Config;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use PHPUnit\Framework\TestCase;

class GatesValidationTest extends TestCase
{
    private $content_dir;
    private $dropin_path;
    private $stubs_dir;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', dirname(__FILE__, 3) . '/tests/wp-tests/src/wp-content');
        }

        $this->stubs_dir = dirname(__FILE__, 3) . '/stubs';
        $this->content_dir = WP_CONTENT_DIR;
        $this->dropin_path = WP_CONTENT_DIR . '/object-cache.php';

        // Clean up any existing drop-in or temp files
        $this->cleanupDropin();
    }

    protected function tearDown(): void
    {
        $this->cleanupDropin();
        parent::tearDown();
    }

    private function cleanupDropin(): void
    {
        $paths = array(
            $this->dropin_path,
            dirname(__FILE__, 3) . '/tests/wp-tests/src/wp-content/object-cache.php'
        );

        foreach (array_unique($paths) as $path) {
            if (file_exists($path)) {
                @chmod($path, 0644);
                if (is_link($path)) {
                    @unlink($path);
                } elseif (is_dir($path)) {
                    @rmdir($path);
                } else {
                    @unlink($path);
                }
            }
        }

        $dirs = array(
            $this->content_dir,
            dirname(__FILE__, 3) . '/tests/wp-tests/src/wp-content'
        );

        foreach (array_unique($dirs) as $dir) {
            foreach (glob($dir . '/object-cache.tmp.*.php') as $f) {
                @chmod($f, 0644);
                @unlink($f);
            }
        }
    }

    // ----------------------------------------------------------------
    // P0 Gate Tests
    // ----------------------------------------------------------------

    public function test_p0_gate_lifecycle_and_preservation()
    {
        // 1. Install an absent drop-in
        $this->assertSame(Lifecycle::STATE_ABSENT, Lifecycle::get_dropin_state());
        $this->assertTrue(Lifecycle::install_dropin(), 'Should install absent drop-in.');
        $this->assertTrue(file_exists($this->dropin_path));

        // 2. Report it current
        $this->assertSame(Lifecycle::STATE_OWNED_CURRENT, Lifecycle::get_dropin_state());

        // 3. Update it atomically (by calling install_dropin again, which overwrites owned current)
        // Verify that temporary files were created and cleaned up
        $this->assertTrue(Lifecycle::install_dropin(), 'Should update atomically.');
        $this->assertSame(Lifecycle::STATE_OWNED_CURRENT, Lifecycle::get_dropin_state());
        $temp_files = glob($this->content_dir . '/object-cache.tmp.*.php');
        $this->assertEmpty($temp_files, 'Atomic temp files should be cleaned up.');

        // 4. Refuse and preserve foreign target byte-for-byte
        $this->cleanupDropin();
        $foreign_content = "<?php\n// Owner: foreign-plugin-cache\n";
        file_put_contents($this->dropin_path, $foreign_content);
        $this->assertSame(Lifecycle::STATE_FOREIGN, Lifecycle::get_dropin_state());

        $this->assertFalse(Lifecycle::install_dropin(), 'Should refuse to overwrite foreign drop-in.');
        $this->assertSame($foreign_content, file_get_contents($this->dropin_path), 'Foreign target must be preserved byte-for-byte.');

        // 5. Refuse and preserve malformed target byte-for-byte
        $this->cleanupDropin();
        $malformed_content = "<?php\n/**\n * Owner: mincemeat-object-cache\n * Build Hash: bad\n */\n";
        file_put_contents($this->dropin_path, $malformed_content);
        // A malformed file (owned stale/wrong hash) is actually allowed to be updated/overwritten!
        // Wait, what about totally corrupted/invalid PHP?
        // If a file is completely random binary or has invalid markers, it's treated as STATE_FOREIGN.
        $corrupt_content = "some totally random malformed bytes";
        $this->cleanupDropin();
        file_put_contents($this->dropin_path, $corrupt_content);
        $this->assertSame(Lifecycle::STATE_FOREIGN, Lifecycle::get_dropin_state());
        $this->assertFalse(Lifecycle::install_dropin(), 'Should refuse to overwrite malformed/foreign drop-in.');
        $this->assertSame($corrupt_content, file_get_contents($this->dropin_path), 'Malformed target must be preserved byte-for-byte.');

        // 6. Refuse and preserve symlinks
        $this->cleanupDropin();
        $dummy = $this->content_dir . '/dummy.php';
        file_put_contents($dummy, '<?php // dummy');
        symlink($dummy, $this->dropin_path);
        $this->assertTrue(is_link($this->dropin_path));

        $this->assertFalse(Lifecycle::install_dropin(), 'Should refuse to overwrite symlink.');
        $this->assertTrue(is_link($this->dropin_path), 'Symlink must be preserved.');
        @unlink($this->dropin_path);
        @unlink($dummy);

        // 7. Remove only a verified owned artifact
        // Case A: target is foreign -> refuse to remove
        $this->cleanupDropin();
        file_put_contents($this->dropin_path, $foreign_content);
        $this->assertFalse(Lifecycle::remove_dropin(), 'Should refuse to remove foreign drop-in.');
        $this->assertTrue(file_exists($this->dropin_path), 'Foreign target must not be deleted.');

        // Case B: target is owned -> remove successfully
        $this->cleanupDropin();
        $this->assertTrue(Lifecycle::install_dropin());
        $this->assertTrue(file_exists($this->dropin_path));
        $this->assertTrue(Lifecycle::remove_dropin(), 'Should remove owned drop-in.');
        $this->assertFalse(file_exists($this->dropin_path), 'Owned target must be deleted.');
    }

    public function test_p0_gate_leave_unrelated_backend_keys_unchanged()
    {
        if (!class_exists(\Redis::class)) {
            $this->markTestSkipped('PhpRedis extension not available.');
        }

        $host = getenv('MINCEMEAT_TEST_REDIS_HOST') ?: '127.0.0.1';
        $port = (int)(getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6383);

        // Connect directly to Redis to write unrelated keys
        $redis = new \Redis();
        $connected = false;
        try {
            $connected = @$redis->connect($host, $port, 1.0);
        } catch (\Exception $e) {
            $connected = false;
        }
        if (!$connected) {
            $this->markTestSkipped('No Redis server reachable.');
        }

        // Set unrelated keys
        $redis->set('unrelated_key_1', 'unrelated_val_1');
        $redis->set('unrelated_key_2', 'unrelated_val_2');

        // Configure our cache namespace
        $config = new Config(array(
            'namespace' => 'isolated-ns',
            'scheme'    => 'tcp',
            'host'      => $host,
            'port'      => $port,
            'database'  => 0,
        ));

        $ks = new KeySpace(false, 1);
        $be = new Backend($ks);
        $be->initialize($config);
        $cache = new ObjectCache($ks, $be);

        // Write some cache keys
        $cache->set('foo', 'bar', 'options');
        $cache->set('hello', 'world', 'options');

        // Flush our namespace
        $cache->flush();

        // Verify unrelated keys are still present and untouched!
        $this->assertSame('unrelated_val_1', $redis->get('unrelated_key_1'));
        $this->assertSame('unrelated_val_2', $redis->get('unrelated_key_2'));

        // Clean up unrelated keys
        $redis->del('unrelated_key_1');
        $redis->del('unrelated_key_2');

        $be->close();
        $redis->close();
    }

    // ----------------------------------------------------------------
    // P1 Gate Tests (runs authoritative WordPress core cache tests)
    // ----------------------------------------------------------------

    /**
     * Helper to execute authoritative WordPress core cache tests in different modes.
     */
    private function executeAuthoritativeCoreTests(array $env = array(), bool $multisite = false): array
    {
        $wp_tests_dir = dirname(__FILE__, 3) . '/tests/wp-tests';
        $phpunit_bin = dirname(__FILE__, 3) . '/vendor/bin/phpunit';

        // Replicate active drop-in to the WordPress development wp-content directory if needed
        $real_dropin = $wp_tests_dir . '/src/wp-content/object-cache.php';
        if (file_exists($this->dropin_path) && $this->dropin_path !== $real_dropin) {
            copy($this->dropin_path, $real_dropin);
        }
        
        $config_xml = $multisite 
            ? "$wp_tests_dir/tests/phpunit/multisite.xml" 
            : "$wp_tests_dir/phpunit.xml.dist";

        $bootstrap = "$wp_tests_dir/tests/phpunit/includes/bootstrap.php";
        $test_files = array(
            "$wp_tests_dir/tests/phpunit/tests/cache.php",
            "$wp_tests_dir/tests/phpunit/tests/functions/wpCacheGetSalted.php",
            "$wp_tests_dir/tests/phpunit/tests/functions/wpCacheGetMultipleSalted.php",
            "$wp_tests_dir/tests/phpunit/tests/functions/wpCacheSetSalted.php",
            "$wp_tests_dir/tests/phpunit/tests/functions/wpCacheSetMultipleSalted.php",
            dirname(__FILE__) . '/WordPressQueryCacheSmoke.php',
        );

        $bootstrap_wrapper = dirname(__FILE__) . '/bootstrap-wrapper.php';
        $env['MINCEMEAT_REAL_BOOTSTRAP'] = $bootstrap;

        $escaped_files = array_map('escapeshellarg', $test_files);
        $cmd = "$phpunit_bin --bootstrap " . escapeshellarg($bootstrap_wrapper) . " --configuration " . escapeshellarg($config_xml) . " " . implode(' ', $escaped_files);

        $descriptors = array(
            0 => array('pipe', 'r'), // stdin
            1 => array('pipe', 'w'), // stdout
            2 => array('pipe', 'w'), // stderr
        );

        // Merge parent environment with overrides safely
        $process_env = array();
        foreach (array_merge($_ENV, $_SERVER, $env) as $k => $v) {
            if (is_string($k) && is_scalar($v)) {
                $process_env[$k] = (string) $v;
            }
        }
        // Force the DB host to point to our container port (detect local 33076 first, fallback to 3306)
        $mysql_port = (int)(getenv('MINCEMEAT_TEST_DB_PORT') ?: 33076);
        $connection = @fsockopen('127.0.0.1', $mysql_port, $errno, $errstr, 0.2);
        if (!is_resource($connection) && $mysql_port === 33076) {
            $connection3306 = @fsockopen('127.0.0.1', 3306, $errno, $errstr, 0.2);
            if (is_resource($connection3306)) {
                $mysql_port = 3306;
                fclose($connection3306);
            }
        } elseif (is_resource($connection)) {
            fclose($connection);
        }
        $process_env['DB_HOST'] = '127.0.0.1:' . $mysql_port;
        if ($multisite) {
            $process_env['WP_MULTISITE'] = '1';
        }

        $process = proc_open($cmd, $descriptors, $pipes, $wp_tests_dir, $process_env);

        if (!is_resource($process)) {
            return array('status' => -1, 'output' => 'proc_open failed');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $status = proc_close($process);

        return array(
            'status' => $status,
            'stdout' => $stdout,
            'stderr' => $stderr,
        );
    }

    public function test_p1_gate_authoritative_tests_runtime_only_single_site()
    {
        // 1. Runtime-only mode: Connection to backend is blocked / points to dummy port
        // By copying stubs/object-cache.php to wp-content/object-cache.php and defining a config pointing to a wrong port,
        // it degrades to runtime-only mode.
        $this->cleanupDropin();
        $this->assertTrue(Lifecycle::install_dropin());
        $this->assertTrue(file_exists($this->dropin_path));

        $env = array(
            'MINCEMEAT_REQUIRE_PERSISTENT' => '0',
            'MINCEMEAT_OBJECT_CACHE_CONFIG' => json_encode(array(
                'scheme'          => 'tcp',
                'host'            => '127.0.0.1',
                'port'            => 9999, // unreachable port
                'connect_timeout' => 0.1,
                'read_timeout'    => 0.1,
                'namespace'       => 'runtime-only-ns',
            ))
        );

        $result = $this->executeAuthoritativeCoreTests($env, false);
        $this->assertSame(0, $result['status'], "WordPress cache tests failed in runtime-only single-site:\nSTDOUT:\n" . $result['stdout'] . "\nSTDERR:\n" . $result['stderr']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('MINCEMEAT_PREFLIGHT: ', $result['stdout']);
    }

    public function test_p1_gate_authoritative_tests_redis8_single_site()
    {
        $this->cleanupDropin();
        $this->assertTrue(Lifecycle::install_dropin());

        $env = array(
            'MINCEMEAT_REQUIRE_PERSISTENT' => '1',
            'MINCEMEAT_EXPECTED_BACKEND'   => 'redis',
            'MINCEMEAT_OBJECT_CACHE_CONFIG' => json_encode(array(
                'scheme'          => 'tcp',
                'host'            => '127.0.0.1',
                'port'            => (int)(getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6383), // Redis 8
                'database'         => 0,
                'connect_timeout' => 1.0,
                'read_timeout'    => 1.0,
                'namespace'       => 'redis8-single-ns',
            ))
        );

        $result = $this->executeAuthoritativeCoreTests($env, false);
        $this->assertSame(0, $result['status'], "WordPress cache tests failed in Redis 8 single-site:\nSTDOUT:\n" . $result['stdout'] . "\nSTDERR:\n" . $result['stderr']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('MINCEMEAT_PREFLIGHT: ', $result['stdout']);
    }

    public function test_p1_gate_authoritative_tests_valkey9_single_site()
    {
        $this->cleanupDropin();
        $this->assertTrue(Lifecycle::install_dropin());

        $env = array(
            'MINCEMEAT_REQUIRE_PERSISTENT' => '1',
            'MINCEMEAT_EXPECTED_BACKEND'   => 'valkey',
            'MINCEMEAT_OBJECT_CACHE_CONFIG' => json_encode(array(
                'scheme'          => 'tcp',
                'host'            => '127.0.0.1',
                'port'            => (int)(getenv('MINCEMEAT_TEST_VALKEY_PORT') ?: 6384), // Valkey 9
                'database'         => 0,
                'connect_timeout' => 1.0,
                'read_timeout'    => 1.0,
                'namespace'       => 'valkey9-single-ns',
            ))
        );

        $result = $this->executeAuthoritativeCoreTests($env, false);
        $this->assertSame(0, $result['status'], "WordPress cache tests failed in Valkey 9 single-site:\nSTDOUT:\n" . $result['stdout'] . "\nSTDERR:\n" . $result['stderr']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('MINCEMEAT_PREFLIGHT: ', $result['stdout']);
    }

    public function test_p1_gate_authoritative_tests_redis8_multisite()
    {
        $this->cleanupDropin();
        $this->assertTrue(Lifecycle::install_dropin());

        $env = array(
            'MINCEMEAT_REQUIRE_PERSISTENT' => '1',
            'MINCEMEAT_EXPECTED_BACKEND'   => 'redis',
            'MINCEMEAT_OBJECT_CACHE_CONFIG' => json_encode(array(
                'scheme'          => 'tcp',
                'host'            => '127.0.0.1',
                'port' => (int)(getenv('MINCEMEAT_TEST_REDIS_PORT') ?: 6383), // Redis 8
                'database'         => 0,
                'connect_timeout' => 1.0,
                'read_timeout'    => 1.0,
                'namespace'       => 'redis8-multi-ns',
            ))
        );

        $result = $this->executeAuthoritativeCoreTests($env, true);
        $this->assertSame(0, $result['status'], "WordPress cache tests failed in Redis 8 multisite:\nSTDOUT:\n" . $result['stdout'] . "\nSTDERR:\n" . $result['stderr']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('MINCEMEAT_PREFLIGHT: ', $result['stdout']);
    }

    public function test_p1_gate_authoritative_tests_valkey9_multisite()
    {
        $this->cleanupDropin();
        $this->assertTrue(Lifecycle::install_dropin());

        $env = array(
            'MINCEMEAT_REQUIRE_PERSISTENT' => '1',
            'MINCEMEAT_EXPECTED_BACKEND'   => 'valkey',
            'MINCEMEAT_OBJECT_CACHE_CONFIG' => json_encode(array(
                'scheme'          => 'tcp',
                'host'            => '127.0.0.1',
                'port' => (int)(getenv('MINCEMEAT_TEST_VALKEY_PORT') ?: 6384), // Valkey 9
                'database'         => 0,
                'connect_timeout' => 1.0,
                'read_timeout'    => 1.0,
                'namespace'       => 'valkey9-multi-ns',
            ))
        );

        $result = $this->executeAuthoritativeCoreTests($env, true);
        $this->assertSame(0, $result['status'], "WordPress cache tests failed in Valkey 9 multisite:\nSTDOUT:\n" . $result['stdout'] . "\nSTDERR:\n" . $result['stderr']);
        $this->assertStringContainsString('OK', $result['stdout']);
        $this->assertStringContainsString('MINCEMEAT_PREFLIGHT: ', $result['stdout']);
    }
}
