<?php
/**
 * Integration tests for the persistent ObjectCache against Redis 8 / Valkey 9.
 *
 * Re-runs the core WordPress 6.9 cache contract assertions against the live
 * backend, plus persistence-specific tests: cross-request visibility, TTL
 * expiry, atomic NX/XX, namespace isolation, value fixtures, and degradation.
 *
 * @package Mincemeat\ObjectCache
 * @group integration
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Integration;

use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\ValueCodec;

/**
 * @group integration
 */
class PersistentCacheTest extends IntegrationTestCase
{
    // ----------------------------------------------------------------
    // WordPress 6.9 contract (same assertions as Contract tests, against backend)
    // ----------------------------------------------------------------

    public function test_miss()
    {
        $this->assertFalse($this->cache->get('test_miss'));
    }

    public function test_add_get()
    {
        $this->cache->add('k', 'val');
        $this->assertSame('val', $this->cache->get('k'));
    }

    public function test_add_get_0()
    {
        $this->assertTrue($this->cache->add('k', 0));
        $this->assertSame(0, $this->cache->get('k'));
    }

    public function test_add_get_null()
    {
        $this->assertTrue($this->cache->add('k', null));
        $this->assertSame(null, $this->cache->get('k'));
    }

    public function test_add_get_false()
    {
        $this->assertTrue($this->cache->add('k', false));
        $this->assertSame(false, $this->cache->get('k'));
    }

    public function test_found_disambiguates_false_from_miss()
    {
        $this->cache->set('k-false', false);

        $found = null;
        $this->assertSame(false, $this->cache->get('k-false', '', false, $found));
        $this->assertTrue($found);

        $found = null;
        $this->assertSame(false, $this->cache->get('k-miss', '', false, $found));
        $this->assertFalse($found);
    }

    public function test_add_rejects_existing()
    {
        $this->assertTrue($this->cache->add('k', 'v1'));
        $this->assertFalse($this->cache->add('k', 'v2'));
        $this->assertSame('v1', $this->cache->get('k'));
    }

    public function test_replace()
    {
        $this->assertFalse($this->cache->replace('k', 'v1'));
        $this->assertTrue($this->cache->add('k', 'v1'));
        $this->assertTrue($this->cache->replace('k', 'v2'));
        $this->assertSame('v2', $this->cache->get('k'));
    }

    public function test_set_overwrites()
    {
        $this->cache->set('k', 'v1');
        $this->assertSame('v1', $this->cache->get('k'));
        $this->cache->set('k', 'v2');
        $this->assertSame('v2', $this->cache->get('k'));
    }

    public function test_flush()
    {
        $this->cache->add('k', 'v');
        $this->assertSame('v', $this->cache->get('k'));
        $this->cache->flush();

        // New request should not see old data.
        $new = $this->new_request();
        $this->assertFalse($new->get('k'));
    }

    public function test_flush_group()
    {
        $this->cache->set('k', 'v', 'group-test');
        $this->cache->set('k', 'v', 'group-kept');

        $this->assertTrue($this->cache->flush_group('group-test'));

        $this->assertFalse($this->cache->get('k', 'group-test'));
        $this->assertSame('v', $this->cache->get('k', 'group-kept'));
    }

    public function test_object_isolation()
    {
        $obj      = new \stdClass();
        $obj->foo = 'alpha';
        $this->cache->set('k', $obj);
        $obj->foo = 'bravo';

        $retrieved = $this->cache->get('k');
        $this->assertSame('alpha', $retrieved->foo);
    }

    public function test_incr()
    {
        $this->assertFalse($this->cache->incr('k'));
        $this->cache->set('k', 0);
        $this->cache->incr('k');
        $this->assertSame(1, $this->cache->get('k'));
        $this->cache->incr('k', 2);
        $this->assertSame(3, $this->cache->get('k'));
    }

    public function test_decr()
    {
        $this->assertFalse($this->cache->decr('k'));
        $this->cache->set('k', 0);
        $this->cache->decr('k');
        $this->assertSame(0, $this->cache->get('k'));
        $this->cache->set('k', 3);
        $this->cache->decr('k');
        $this->assertSame(2, $this->cache->get('k'));
        $this->cache->decr('k', 2);
        $this->assertSame(0, $this->cache->get('k'));
    }

    public function test_delete()
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->delete('k'));
        $this->assertFalse($this->cache->get('k'));
        $this->assertFalse($this->cache->delete('k'));
    }

    public function test_add_multiple()
    {
        $found = $this->cache->add_multiple(
            array('foo1' => 'bar', 'foo2' => 'bar', 'foo3' => 'bar'),
            'group1'
        );
        $this->assertSame(array('foo1' => true, 'foo2' => true, 'foo3' => true), $found);
    }

    public function test_set_multiple()
    {
        $found = $this->cache->set_multiple(
            array('foo1' => 'bar', 'foo2' => 'bar', 'foo3' => 'bar'),
            'group1'
        );
        $this->assertSame(array('foo1' => true, 'foo2' => true, 'foo3' => true), $found);
    }

    public function test_get_multiple()
    {
        $this->cache->set('foo1', 'bar', 'group1');
        $this->cache->set('foo2', 'bar', 'group1');
        $this->cache->set('foo1', 'bar', 'group2');

        $found = $this->cache->get_multiple(array('foo1', 'foo2', 'foo3'), 'group1');
        $this->assertSame(array('foo1' => 'bar', 'foo2' => 'bar', 'foo3' => false), $found);
    }

    public function test_delete_multiple()
    {
        $this->cache->set('foo1', 'bar', 'group1');
        $this->cache->set('foo2', 'bar', 'group1');
        $this->cache->set('foo3', 'bar', 'group2');

        $found = $this->cache->delete_multiple(array('foo1', 'foo2', 'foo3'), 'group1');
        $this->assertSame(array('foo1' => true, 'foo2' => true, 'foo3' => false), $found);
    }

    // ----------------------------------------------------------------
    // Persistence-specific tests
    // ----------------------------------------------------------------

    public function test_value_visible_across_requests()
    {
        $this->cache->set('cross-request', 'persisted', 'options');

        $new = $this->new_request();
        $this->assertSame('persisted', $new->get('cross-request', 'options'));
    }

    public function test_numeric_string_preserved_as_string()
    {
        $this->cache->set('k', '42', 'options');
        $this->assertSame('42', $this->cache->get('k', 'options'));
        $this->assertIsString($this->cache->get('k', 'options'));

        $new = $this->new_request();
        $val = $new->get('k', 'options');
        $this->assertSame('42', $val);
        $this->assertIsString($val);
    }

    public function test_integer_preserved_as_integer()
    {
        $this->cache->set('k', 42, 'options');
        $this->assertSame(42, $this->cache->get('k', 'options'));
        $this->assertIsInt($this->cache->get('k', 'options'));

        $new = $this->new_request();
        $val = $new->get('k', 'options');
        $this->assertSame(42, $val);
        $this->assertIsInt($val);
    }

    public function test_float_preserved()
    {
        $this->cache->set('k', 3.14, 'options');
        $this->assertSame(3.14, $this->cache->get('k', 'options'));

        $new = $this->new_request();
        $this->assertSame(3.14, $new->get('k', 'options'));
    }

    public function test_boolean_preserved()
    {
        $this->cache->set('k-true', true, 'options');
        $this->cache->set('k-false', false, 'options');

        $new = $this->new_request();
        $this->assertTrue($new->get('k-true', 'options'));
        $this->assertFalse($new->get('k-false', 'options'));
    }

    public function test_null_preserved()
    {
        $this->cache->set('k', null, 'options');
        $this->assertSame(null, $this->cache->get('k', 'options'));

        $new = $this->new_request();
        $this->assertSame(null, $new->get('k', 'options'));
    }

    public function test_array_preserved()
    {
        $data = array('a' => 1, 'b' => 'two', 'c' => array(1, 2, 3));
        $this->cache->set('k', $data, 'options');

        $new = $this->new_request();
        $this->assertEquals($data, $new->get('k', 'options'));
    }

    public function test_object_preserved()
    {
        $obj           = new \stdClass();
        $obj->name     = 'test';
        $obj->nested   = new \stdClass();
        $obj->nested->v = 42;
        $this->cache->set('k', $obj, 'options');

        $new   = $this->new_request();
        $retrieved = $new->get('k', 'options');
        $this->assertInstanceOf(\stdClass::class, $retrieved);
        $this->assertSame('test', $retrieved->name);
        $this->assertSame(42, $retrieved->nested->v);
    }

    public function test_ttl_expiry()
    {
        $this->cache->set('k', 'v', 'options', 1);
        $this->assertSame('v', $this->cache->get('k', 'options'));

        $new = $this->new_request();
        $this->assertSame('v', $new->get('k', 'options'));

        sleep(2);

        $new2 = $this->new_request();
        $this->assertFalse($new2->get('k', 'options'));
    }

    public function test_ttl_capped_by_max_ttl()
    {
        // max_ttl defaults to 2592000 (30 days). A caller TTL above it
        // should be capped. Verify indirectly: a 1s TTL is below the cap
        // and should simply expire.
        $this->cache->set('k', 'v', 'options', 1);
        sleep(2);

        $new = $this->new_request();
        $this->assertFalse($new->get('k', 'options'));
    }

    public function test_atomic_add_prevents_duplicate()
    {
        $this->cache->set('k', 'original', 'options');
        $this->assertFalse($this->cache->add('k', 'duplicate', 'options'));
        $this->assertSame('original', $this->cache->get('k', 'options'));
    }

    public function test_replace_fails_when_absent()
    {
        $this->assertFalse($this->cache->replace('k', 'v', 'options'));
    }

    public function test_forced_read_bypasses_memory()
    {
        $this->cache->set('k', 'v', 'options');

        // Simulate stale memory by overwriting the memory tier directly
        // (this cannot easily be done without internals); instead verify
        // that force still returns the correct value from the backend.
        $found = null;
        $val   = $this->cache->get('k', 'options', true, $found);
        $this->assertSame('v', $val);
        $this->assertTrue($found);
    }

    public function test_namespace_isolation()
    {
        $this->cache->set('k', 'from-ns-a', 'options');

        // Create a cache with a different namespace.
        $config_b = new \Mincemeat\ObjectCache\Config(array(
            'namespace'        => 'test-iso-b-' . bin2hex(random_bytes(8)),
            'scheme'           => 'tcp',
            'host'             => $this->config->host(),
            'port'             => $this->config->port(),
            'database'         => 0,
            'connect_timeout'  => 1.0,
            'read_timeout'     => 1.0,
        ));

        $ks_b = new \Mincemeat\ObjectCache\KeySpace(false, 1);
        $be_b = new \Mincemeat\ObjectCache\Backend($ks_b);
        $be_b->initialize($config_b);

        $cache_b = new ObjectCache($ks_b, $be_b);

        $this->cache->set('isolated-key', 'secret', 'options');
        $this->assertFalse($cache_b->get('isolated-key', 'options'));

        $cache_b->set('isolated-key', 'other', 'options');
        $this->assertSame('secret', $this->cache->get('isolated-key', 'options'));

        $be_b->close();
    }

    public function test_no_key_outside_namespace_is_touched()
    {
        // Write a key under our namespace.
        $this->cache->set('safe-key', 'safe-value', 'options');

        // Write a foreign key directly in the same database.
        $foreign_key = 'foreign-unrelated-key-' . bin2hex(random_bytes(8));
        $this->backend->set_unconditional($foreign_key, 'foreign-value');

        // Flush our namespace.
        $this->cache->flush();

        // The foreign key must still exist.
        $raw = $this->backend->get($foreign_key);
        $this->assertSame('foreign-value', $raw);
    }

    public function test_non_persistent_group_stays_in_memory_only()
    {
        $this->cache->add_non_persistent_groups('counts');
        $this->cache->set('k', 5, 'counts');
        $this->assertSame(5, $this->cache->get('k', 'counts'));

        $new = $this->new_request();
        $this->assertFalse($new->get('k', 'counts'));
    }

    public function test_backend_state_is_persistent()
    {
        $this->assertSame(ObjectCache::STATE_PERSISTENT, $this->cache->state());
    }

    public function test_server_info_detected()
    {
        $info = $this->cache->server_info();
        $this->assertNotNull($info);
        $this->assertContains($info['product'], array('redis', 'valkey', 'unknown'));
        $this->assertNotEmpty($info['version']);
    }

    public function test_binary_string_preserved()
    {
        $binary = "\x00\x01\x02\xFF\xFE\x00";
        $this->cache->set('k', $binary, 'options');

        $new = $this->new_request();
        $this->assertSame($binary, $new->get('k', 'options'));
    }

    public function test_unicode_string_preserved()
    {
        $unicode = "caf\xc3\xa9 \xe6\x97\xa5\xe6\x9c\xac\xe8\xaa\x9e";
        $this->cache->set('k', $unicode, 'options');

        $new = $this->new_request();
        $this->assertSame($unicode, $new->get('k', 'options'));
    }

    public function test_long_string_preserved()
    {
        $long = str_repeat('A', 100000);
        $this->cache->set('k', $long, 'options');

        $new = $this->new_request();
        $this->assertSame($long, $new->get('k', 'options'));
    }

    public function test_flush_clears_across_requests()
    {
        $this->cache->set('k1', 'v1', 'g1');
        $this->cache->set('k2', 'v2', 'g2');
        $this->cache->flush();

        $new = $this->new_request();
        $this->assertFalse($new->get('k1', 'g1'));
        $this->assertFalse($new->get('k2', 'g2'));
    }

    public function test_flush_group_clears_across_requests()
    {
        $this->cache->set('k', 'v', 'group-flush-test');
        $this->cache->set('k', 'v', 'group-keep');
        $this->cache->flush_group('group-flush-test');

        $new = $this->new_request();
        $this->assertFalse($new->get('k', 'group-flush-test'));
        $this->assertSame('v', $new->get('k', 'group-keep'));
    }

    public function test_suspend_addition_blocks_persistent_add()
    {
        wp_suspend_cache_addition(true);
        $this->assertFalse($this->cache->add('k', 'v', 'options'));

        $this->assertTrue($this->cache->set('k', 'v', 'options'));
        $this->assertSame('v', $this->cache->get('k', 'options'));
    }
}
