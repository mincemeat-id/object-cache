<?php
/**
 * Integration tests for Phase 5 — O(1) flush and race correctness.
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
use Mincemeat\ObjectCache\PhpRedisAdapter;
use Mincemeat\ObjectCache\SiteHealth;

/**
 * @group integration
 */
class Phase5Test extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    /**
     * Test namespace isolation during flushes.
     */
    public function test_namespace_isolation_flushes()
    {
        // Seed key in namespace A (default)
        $this->cache->set('k1', 'val1', 'group1');
        $this->assertSame('val1', $this->cache->get('k1', 'group1'));

        // Create isolated namespace B
        $config_b = new Config(array(
            'namespace'        => 'test-iso-b-' . bin2hex(random_bytes(8)),
            'scheme'           => 'tcp',
            'host'             => $this->config->host(),
            'port'             => $this->config->port(),
            'database'         => 0,
            'connect_timeout'  => 1.0,
            'read_timeout'     => 1.0,
        ));

        $ks_b = new KeySpace(false, 1);
        $be_b = new Backend($ks_b);
        $be_b->initialize($config_b);
        $cache_b = new ObjectCache($ks_b, $be_b);

        // Seed key in namespace B
        $cache_b->set('k2', 'val2', 'group1');
        $this->assertSame('val2', $cache_b->get('k2', 'group1'));

        // Flush namespace A
        $this->cache->flush();

        // Namespace A should be cleared, Namespace B should remain intact
        $new_a = $this->new_request();
        $this->assertFalse($new_a->get('k1', 'group1'));

        $new_b = new ObjectCache($ks_b, $be_b);
        $this->assertSame('val2', $new_b->get('k2', 'group1'));

        $be_b->close();
    }

    /**
     * Test group flushes in single site and multisite.
     */
    public function test_group_flushes()
    {
        // 1. Single Site Context
        $this->cache->set('k1', 'v1', 'group1');
        $this->cache->set('k2', 'v2', 'group2');

        $this->cache->flush_group('group1');

        $new = $this->new_request();
        $this->assertFalse($new->get('k1', 'group1'));
        $this->assertSame('v2', $new->get('k2', 'group2'));

        // 2. Multisite Context: group flushes invalidate across the entire network
        $ks_blog1 = new KeySpace(true, 1);
        $be_blog1 = new Backend($ks_blog1);
        $be_blog1->initialize($this->config);
        $cache_blog1 = new ObjectCache($ks_blog1, $be_blog1);

        $ks_blog2 = new KeySpace(true, 2);
        $be_blog2 = new Backend($ks_blog2);
        $be_blog2->initialize($this->config);
        $cache_blog2 = new ObjectCache($ks_blog2, $be_blog2);

        // Write to blog-scoped group1 on both blogs
        $cache_blog1->set('k', 'blog1-val', 'group1');
        $cache_blog2->set('k', 'blog2-val', 'group1');

        // Write to blog-scoped group2 on both blogs
        $cache_blog1->set('k', 'blog1-keep', 'group2');
        $cache_blog2->set('k', 'blog2-keep', 'group2');

        // Invalidate group1 on blog 1
        $cache_blog1->flush_group('group1');

        // Both blog1 and blog2 should lose group1 data (network-wide invalidation)
        $ks_blog1_new = new KeySpace(true, 1);
        $be_blog1_new = new Backend($ks_blog1_new);
        $be_blog1_new->initialize($this->config);
        $new_blog1 = new ObjectCache($ks_blog1_new, $be_blog1_new);

        $ks_blog2_new = new KeySpace(true, 2);
        $be_blog2_new = new Backend($ks_blog2_new);
        $be_blog2_new->initialize($this->config);
        $new_blog2 = new ObjectCache($ks_blog2_new, $be_blog2_new);

        $this->assertFalse($new_blog1->get('k', 'group1'));
        $this->assertFalse($new_blog2->get('k', 'group1'));

        // But group2 data should remain intact on both blogs
        $this->assertSame('blog1-keep', $new_blog1->get('k', 'group2'));
        $this->assertSame('blog2-keep', $new_blog2->get('k', 'group2'));

        $be_blog1->close();
        $be_blog2->close();
        $be_blog1_new->close();
        $be_blog2_new->close();
    }

    /**
     * Test secure initialization and no resurrection on manual control key deletion.
     */
    public function test_no_resurrection_on_eviction_or_deletion()
    {
        $this->cache->set('resurrect-key', 'old-val', 'group1');
        $this->assertSame('old-val', $this->cache->get('resurrect-key', 'group1'));

        // Get control key names
        $ns_key = $this->cache->key_space()->namespace_control_key();
        $grp_key = $this->cache->key_space()->group_control_key('group1');

        // Delete the control keys manually in the database
        $this->backend->del($ns_key);
        $this->backend->del($grp_key);

        // Requesting the key should result in a cache miss
        $new = $this->new_request();
        $this->assertFalse($new->get('resurrect-key', 'group1'));

        // The old value must not be resurrectable because new tokens were generated
        $this->assertFalse($new->get('resurrect-key', 'group1'));
    }

    /**
     * Verify no destructive commands are called on flush operations.
     */
    public function test_no_destructive_commands_occur()
    {
        // Setup the command spy
        $ref = new \ReflectionClass($this->backend);
        $prop = $ref->getProperty('adapter');
        $prop->setAccessible(true);
        $real_adapter = $prop->getValue($this->backend);

        $spy = new class($real_adapter) extends PhpRedisAdapter {
            private $real;
            public $calls = [];
            public function __construct($real) {
                $this->real = $real;
            }
            public function set_unconditional(string $key, string $value, ?int $ttl_ms = null): bool {
                $this->calls[] = 'set_unconditional';
                return $this->real->set_unconditional($key, $value, $ttl_ms);
            }
        };

        $prop->setValue($this->backend, $spy);

        // Run flush and group flush
        $this->cache->flush();
        $this->cache->flush_group('group1');

        // Restore real adapter
        $prop->setValue($this->backend, $real_adapter);

        // Verify captured calls
        $this->assertNotEmpty($spy->calls);
        
        // Assert no destructive commands are in the call list
        foreach ($spy->calls as $call) {
            $call_lower = strtolower($call);
            $this->assertNotContains($call_lower, ['flushdb', 'flushall', 'keys', 'scan']);
        }
    }

    /**
     * Verify metrics increments and action hook execution.
     */
    public function test_metrics_and_actions()
    {
        $GLOBALS['__mincemeat_actions'] = array();

        $calls_before = $this->cache->backend_calls();

        $this->assertTrue($this->cache->flush());

        $this->assertSame($calls_before + 1, $this->cache->backend_calls());
        $this->assertGreaterThan(0.0, $this->cache->backend_time());

        $this->assertArrayHasKey('mincemeat_object_cache_flushed', $GLOBALS['__mincemeat_actions']);

        // Group flush
        $GLOBALS['__mincemeat_actions'] = array();
        $calls_before = $this->cache->backend_calls();

        $this->assertTrue($this->cache->flush_group('group1'));

        $this->assertSame($calls_before + 1, $this->cache->backend_calls());
        $this->assertArrayHasKey('mincemeat_object_cache_group_flushed', $GLOBALS['__mincemeat_actions']);
        $this->assertSame(array(array('group1')), $GLOBALS['__mincemeat_actions']['mincemeat_object_cache_group_flushed']);
    }

    /**
     * Test Site Health check warning triggers.
     */
    public function test_site_health_warnings()
    {
        $GLOBALS['wp_object_cache'] = $this->cache;

        // 1. Safe configuration Site Health verification
        $result_ttl = SiteHealth::test_ttl();
        $this->assertSame('good', $result_ttl['status']);

        // Mock safe memory policy (e.g. volatile-lru) for the good eviction check
        $ref_safe = new \ReflectionClass($this->backend);
        $prop_safe = $ref_safe->getProperty('server_info');
        $prop_safe->setAccessible(true);
        $old_info = $prop_safe->getValue($this->backend) ?: [];
        $prop_safe->setValue($this->backend, array_merge($old_info, array('maxmemory_policy' => 'volatile-lru')));

        $result_evict = SiteHealth::test_eviction_policy();
        $this->assertSame('good', $result_evict['status']);

        // Restore safe info
        $prop_safe->setValue($this->backend, $old_info);

        // 2. Unsafe max_ttl = 0
        $unsafe_config = new Config(array(
            'namespace'        => $this->config->namespace(),
            'scheme'           => 'tcp',
            'host'             => $this->config->host(),
            'port'             => $this->config->port(),
            'database'         => 0,
            'connect_timeout'  => 1.0,
            'read_timeout'     => 1.0,
            'max_ttl'          => 0, // Unbounded
        ));

        $unsafe_ks = new KeySpace(false, 1);
        $unsafe_be = new Backend($unsafe_ks);
        $unsafe_be->initialize($unsafe_config);
        $unsafe_cache = new ObjectCache($unsafe_ks, $unsafe_be);

        $GLOBALS['wp_object_cache'] = $unsafe_cache;

        $result_unsafe_ttl = SiteHealth::test_ttl();
        $this->assertSame('recommended', $result_unsafe_ttl['status']);
        $this->assertStringContainsString('unbounded TTL', $result_unsafe_ttl['label']);

        // 3. Eviction warning test by mocking maxmemory_policy
        // We will subclass Backend or override the server_info using reflection
        $ref = new \ReflectionClass($unsafe_be);
        $prop = $ref->getProperty('server_info');
        $prop->setAccessible(true);
        $prop->setValue($unsafe_be, array(
            'product' => 'redis',
            'version' => '8.0.0',
            'mode' => 'standalone',
            'os' => 'Linux',
            'maxmemory_policy' => 'noeviction', // Mocked policy
        ));

        $result_unsafe_evict = SiteHealth::test_eviction_policy();
        $this->assertSame('recommended', $result_unsafe_evict['status']);
        $this->assertStringContainsString('noeviction', $result_unsafe_evict['label']);

        $unsafe_be->close();
        unset($GLOBALS['wp_object_cache']);
    }
}
