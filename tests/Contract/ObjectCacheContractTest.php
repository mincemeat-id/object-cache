<?php
/**
 * Contract tests: adapt the authoritative supported WordPress core cache tests
 * against the Mincemeat runtime ObjectCache and the wp_cache_* facade.
 *
 * These mirror the behavior assertions in
 * tests/phpunit/tests/cache.php of the maintained WordPress tags. Runtime-only here;
 * the same suite is run against Redis 8 and Valkey 9 in the Integration phase.
 *
 * @package Mincemeat\ObjectCache
 * @group contract
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Contract;

use Mincemeat\ObjectCache\ObjectCache;
use PHPUnit\Framework\TestCase;

/**
 * Adapts the supported WordPress core cache contract tests.
 */
/**
 * @group contract
 */
class ObjectCacheContractTest extends TestCase
{
    /**
     * @var ObjectCache
     */
    private $cache;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset recorded _doing_it_wrong / deprecation notices between tests.
        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
        $GLOBALS['__mincemeat_deprecated']     = array();

        $this->cache = new ObjectCache();
        $this->cache->add_global_groups(array('global-cache-test'));
        $GLOBALS['wp_object_cache'] = $this->cache;
    }

    protected function tearDown(): void
    {
        if (isset($this->cache)) {
            $this->cache->flush();
        }
        unset($GLOBALS['wp_object_cache']);
        // Restore the addition-suspend flag default state.
        if (function_exists('wp_suspend_cache_addition')) {
            wp_suspend_cache_addition(false);
        }
        parent::tearDown();
    }

    /**
     * Resets the recorded _doing_it_wrong notices helper for assertions.
     */
    private function doingItWrongCount(): int
    {
        return isset($GLOBALS['__mincemeat_doing_it_wrong']) ? count($GLOBALS['__mincemeat_doing_it_wrong']) : 0;
    }

    /**
     * Loads WordPress compatibility helpers for direct contract tests.
     */
    private function loadWordPressCacheCompatibilityHelpers(): void
    {
        require_once dirname(__FILE__, 3) . '/tests/wp-tests/src/wp-includes/cache-compat.php';
    }

    /**
     * @dataProvider data_is_valid_key
     */
    public function test_is_valid_key($key, bool $valid)
    {
        $before = $this->doingItWrongCount();

        if ($valid) {
            $this->assertTrue($this->cache->add($key, 'val'));
            $this->assertSame('val', $this->cache->get($key));
        } else {
            $this->assertFalse($this->cache->add($key, 'val'));
            $this->assertGreaterThan($before, $this->doingItWrongCount());
        }
    }

    public function data_is_valid_key(): array
    {
        return array(
            'false'          => array(false, false),
            'null'           => array(null, false),
            'line break'     => array("\n", false),
            'null character' => array("\0", false),
            'empty string'   => array('', false),
            'single space'   => array(' ', false),
            'two spaces'     => array('  ', false),
            'float 0'        => array(0.0, false),
            'int 0'          => array(0, true),
            'int 1'          => array(1, true),
            'string 0'       => array('0', true),
            'string'         => array('key', true),
        );
    }

    public function test_miss()
    {
        $this->assertFalse($this->cache->get('test_miss'));
    }

    public function test_add_get()
    {
        $key = __FUNCTION__;
        $val = 'val';

        $this->cache->add($key, $val);
        $this->assertSame($val, $this->cache->get($key));
    }

    public function test_add_get_0()
    {
        $key = __FUNCTION__;
        $val = 0;

        $this->assertTrue($this->cache->add($key, $val));
        $this->assertSame($val, $this->cache->get($key));
    }

    public function test_add_get_null()
    {
        $key = __FUNCTION__;
        $val = null;

        $this->assertTrue($this->cache->add($key, $val));
        $this->assertSame($val, $this->cache->get($key));
    }

    public function test_add_get_false()
    {
        $key = __FUNCTION__;
        $val = false;

        $this->assertTrue($this->cache->add($key, $val));
        $this->assertSame($val, $this->cache->get($key));
    }

    public function test_add_get_found_disambiguates_false_from_miss()
    {
        $found = null;

        $this->cache->set('k-false', false);
        $this->assertSame(false, $this->cache->get('k-false', '', false, $found));
        $this->assertTrue($found);

        $found = null;
        $this->assertSame(false, $this->cache->get('k-miss', '', false, $found));
        $this->assertFalse($found);
    }

    public function test_core_compatibility_properties_track_cache_state()
    {
        $cache = new ObjectCache(new \Mincemeat\ObjectCache\KeySpace(true, 7));

        $this->assertSame(0, $cache->cache_hits);
        $this->assertSame(0, $cache->cache_misses);
        $this->assertTrue(isset($cache->cache_hits));
        $this->assertTrue(isset($cache->cache_misses));
        $this->assertTrue(isset($cache->global_groups));
        $this->assertTrue(isset($cache->blog_prefix));

        $cache->add_global_groups(array('users', 'site-options'));
        $this->assertSame(array('users' => true, 'site-options' => true), $cache->global_groups);
        $this->assertSame('7:', $cache->blog_prefix);

        $cache->set('hit', 'value');
        $this->assertSame('value', $cache->get('hit'));
        $this->assertFalse($cache->get('miss'));
        $this->assertSame(1, $cache->cache_hits);
        $this->assertSame(1, $cache->cache_misses);

        $cache->switch_to_blog(12);
        $this->assertSame('12:', $cache->blog_prefix);
    }

    public function test_stats_matches_core_output_shape()
    {
        $this->cache->set('key', 'value', '<group>');
        $this->cache->get('key', '<group>');
        $this->cache->get('missing', '<group>');

        ob_start();
        $this->cache->stats();
        $output = (string) ob_get_clean();

        $this->assertStringStartsWith('<p><strong>Cache Hits:</strong> 1<br />', $output);
        $this->assertStringContainsString('<strong>Cache Misses:</strong> 1<br /></p><ul>', $output);
        $this->assertStringContainsString('<li><strong>Group:</strong> <group> - ( ', $output);
        $this->assertStringEndsWith('k )</li></ul>', $output);
    }

    public function test_wp_cache_get_salted_rejects_stale_or_malformed_entries()
    {
        $this->loadWordPressCacheCompatibilityHelpers();

        wp_cache_set('salted-key', array('data' => 'fresh', 'salt' => 'posts:terms'), 'post-queries');

        $this->assertSame('fresh', wp_cache_get_salted('salted-key', 'post-queries', array('posts', 'terms')));
        $this->assertFalse(wp_cache_get_salted('salted-key', 'post-queries', 'stale'));

        wp_cache_set('salted-key', 'malformed', 'post-queries');
        $this->assertFalse(wp_cache_get_salted('salted-key', 'post-queries', 'posts:terms'));
    }

    public function test_wp_cache_set_salted_stores_core_envelope()
    {
        $this->loadWordPressCacheCompatibilityHelpers();

        $this->assertTrue(wp_cache_set_salted('salted-key', false, 'term-queries', array('terms', 'posts')));
        $this->assertSame(
            array('data' => false, 'salt' => 'terms:posts'),
            wp_cache_get('salted-key', 'term-queries')
        );
        $this->assertSame(false, wp_cache_get_salted('salted-key', 'term-queries', array('terms', 'posts')));
    }

    public function test_wp_cache_get_multiple_salted_filters_each_entry()
    {
        $this->loadWordPressCacheCompatibilityHelpers();

        wp_cache_set('fresh', array('data' => 0, 'salt' => 'comments'), 'comment-queries');
        wp_cache_set('stale', array('data' => 'old', 'salt' => 'old-comments'), 'comment-queries');
        wp_cache_set('malformed', array('data' => 'missing-salt'), 'comment-queries');

        $this->assertSame(
            array('fresh' => 0, 'stale' => false, 'malformed' => false, 'missing' => false),
            wp_cache_get_multiple_salted(array('fresh', 'stale', 'malformed', 'missing'), 'comment-queries', 'comments')
        );
    }

    public function test_wp_cache_set_multiple_salted_stores_and_returns_per_key_results()
    {
        $this->loadWordPressCacheCompatibilityHelpers();

        $this->assertSame(
            array('one' => true, 'two' => true),
            wp_cache_set_multiple_salted(array('one' => 1, 'two' => false), 'user-queries', array('users', 'sites'))
        );
        $this->assertSame(
            array('one' => 1, 'two' => false),
            wp_cache_get_multiple_salted(array('one', 'two'), 'user-queries', array('users', 'sites'))
        );
    }

    public function test_core_notice_versions_are_used_for_invalid_keys_and_reset()
    {
        $this->cache->get('');
        $this->cache->reset();

        $this->assertSame('6.1.0', $GLOBALS['__mincemeat_doing_it_wrong'][0][2]);
        $this->assertSame('3.5.0', $GLOBALS['__mincemeat_deprecated'][0][1]);
        $this->assertSame('WP_Object_Cache::switch_to_blog()', $GLOBALS['__mincemeat_deprecated'][0][2]);
    }

    public function test_add()
    {
        $key  = __FUNCTION__;
        $val1 = 'val1';
        $val2 = 'val2';

        $this->assertTrue($this->cache->add($key, $val1));
        $this->assertSame($val1, $this->cache->get($key));
        $this->assertFalse($this->cache->add($key, $val2));
        $this->assertSame($val1, $this->cache->get($key));
    }

    public function test_replace()
    {
        $key  = __FUNCTION__;
        $val  = 'val1';
        $val2 = 'val2';

        $this->assertFalse($this->cache->replace($key, $val));
        $this->assertFalse($this->cache->get($key));
        $this->assertTrue($this->cache->add($key, $val));
        $this->assertSame($val, $this->cache->get($key));
        $this->assertTrue($this->cache->replace($key, $val2));
        $this->assertSame($val2, $this->cache->get($key));
    }

    public function test_set()
    {
        $key  = __FUNCTION__;
        $val1 = 'val1';
        $val2 = 'val2';

        $this->assertTrue($this->cache->set($key, $val1));
        $this->assertSame($val1, $this->cache->get($key));
        $this->assertTrue($this->cache->set($key, $val2));
        $this->assertSame($val2, $this->cache->get($key));
    }

    public function test_flush()
    {
        $key = __FUNCTION__;
        $val = 'val';

        $this->cache->add($key, $val);
        $this->assertSame($val, $this->cache->get($key));
        $this->cache->flush();
        $this->assertFalse($this->cache->get($key));
    }

    public function test_flush_group()
    {
        $key = 'my-key';
        $val = 'my-val';

        $this->cache->set($key, $val, 'group-test');
        $this->cache->set($key, $val, 'group-kept');

        $this->assertSame($val, $this->cache->get($key, 'group-test'));

        $this->assertTrue($this->cache->flush_group('group-test'));
        $this->assertFalse($this->cache->get($key, 'group-test'));
        $this->assertSame($val, $this->cache->get($key, 'group-kept'));
    }

    public function test_flush_group_is_case_sensitive()
    {
        $this->cache->set('k', 'v', 'Group');
        $this->cache->set('k', 'v', 'group');

        $this->assertTrue($this->cache->flush_group('group'));
        $this->assertSame('v', $this->cache->get('k', 'Group'));
        $this->assertFalse($this->cache->get('k', 'group'));
    }

    public function test_flush_runtime_only_affects_memory()
    {
        $this->cache->set('k', 'v');
        $this->assertTrue($this->cache->flush_runtime());
        $this->assertFalse($this->cache->get('k'));
    }

    public function test_object_refs()
    {
        $key           = __FUNCTION__ . '_1';
        $object_a      = new \stdClass();
        $object_a->foo = 'alpha';
        $this->cache->set($key, $object_a);
        $object_a->foo = 'bravo';
        $object_b      = $this->cache->get($key);
        $this->assertSame('alpha', $object_b->foo);
        $object_b->foo = 'charlie';
        $this->assertSame('bravo', $object_a->foo);

        $key           = __FUNCTION__ . '_2';
        $object_a      = new \stdClass();
        $object_a->foo = 'alpha';
        $this->cache->add($key, $object_a);
        $object_a->foo = 'bravo';
        $object_b      = $this->cache->get($key);
        $this->assertSame('alpha', $object_b->foo);
        $object_b->foo = 'charlie';
        $this->assertSame('bravo', $object_a->foo);
    }

    public function test_incr()
    {
        $key = __FUNCTION__;

        $this->assertFalse($this->cache->incr($key));

        $this->cache->set($key, 0);
        $this->cache->incr($key);
        $this->assertSame(1, $this->cache->get($key));

        $this->cache->incr($key, 2);
        $this->assertSame(3, $this->cache->get($key));
    }

    public function test_decr()
    {
        $key = __FUNCTION__;

        $this->assertFalse($this->cache->decr($key));

        $this->cache->set($key, 0);
        $this->cache->decr($key);
        $this->assertSame(0, $this->cache->get($key));

        $this->cache->set($key, 3);
        $this->cache->decr($key);
        $this->assertSame(2, $this->cache->get($key));

        $this->cache->decr($key, 2);
        $this->assertSame(0, $this->cache->get($key));
    }

    public function test_incr_decr_non_numeric_normalizes_to_zero()
    {
        $key = __FUNCTION__;
        $this->cache->set($key, 'not-a-number');
        $this->assertSame(1, $this->cache->incr($key));
        $this->assertSame(0, $this->cache->decr($key, 5));
    }

    public function test_numeric_behavior_and_coercion()
    {
        // 1. Numeric and non-numeric strings
        $this->cache->set('k-num-str', '42');
        $this->assertSame(43, $this->cache->incr('k-num-str'));
        $this->assertSame(43, $this->cache->get('k-num-str'));

        $this->cache->set('k-non-num-str', 'hello');
        $this->assertSame(1, $this->cache->incr('k-non-num-str'));
        $this->assertSame(1, $this->cache->get('k-non-num-str'));

        // 2. Floats are normalized to WordPress's integer return contract.
        $this->cache->set('k-float', 3.14);
        $this->assertSame(4, $this->cache->incr('k-float'));
        $this->assertSame(4, $this->cache->get('k-float'));

        // 3. String/fractional offsets and negative offsets
        $this->cache->set('k-offset', 10);
        $this->assertSame(12, $this->cache->incr('k-offset', '2'));
        $this->assertSame(13, $this->cache->incr('k-offset', 1.5)); // 1.5 coerced to 1
        $this->assertSame(11, $this->cache->incr('k-offset', -2)); // negative offset

        // 4. Group '0'
        $this->cache->set('k-group-0', 100, 0);
        $this->assertSame(101, $this->cache->incr('k-group-0', 1, 0));
        $this->assertSame(101, $this->cache->get('k-group-0', 0));

        // 5. Large integer boundaries (2^53 + 1)
        $this->cache->set('k-large-53', 9007199254740993);
        $this->assertSame(9007199254740994, $this->cache->incr('k-large-53', 1));

        // 6. Integer overflow is bounded without widening the return type.
        $this->cache->set('k-int-max', PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, $this->cache->incr('k-int-max', 1));
    }

    public function test_delete()
    {
        $key = __FUNCTION__;
        $val = 'val';

        $this->assertTrue($this->cache->set($key, $val));
        $this->assertSame($val, $this->cache->get($key));

        $this->assertTrue($this->cache->delete($key));
        $this->assertFalse($this->cache->get($key));

        $this->assertFalse($this->cache->delete($key, 'default'));
    }

    public function test_switch_to_blog_single_site_is_global()
    {
        // Single-site: switch_to_blog is a no-op; data is shared.
        $this->assertTrue($this->cache->set('k', 'v1'));
        $this->assertSame('v1', $this->cache->get('k'));
        $this->cache->switch_to_blog(999);
        $this->assertSame('v1', $this->cache->get('k'));
        $this->assertTrue($this->cache->set('k', 'v2'));
        $this->assertSame('v2', $this->cache->get('k'));
        $this->cache->switch_to_blog(1);
        $this->assertSame('v2', $this->cache->get('k'));

        // Global group remains visible across the switch.
        $this->assertTrue($this->cache->set('k', 'g1', 'global-cache-test'));
        $this->assertSame('g1', $this->cache->get('k', 'global-cache-test'));
        $this->cache->switch_to_blog(999);
        $this->assertSame('g1', $this->cache->get('k', 'global-cache-test'));
        $this->cache->switch_to_blog(1);
    }

    public function test_add_multiple()
    {
        $found = $this->cache->add_multiple(
            array(
                'foo1' => 'bar',
                'foo2' => 'bar',
                'foo3' => 'bar',
            ),
            'group1'
        );

        $this->assertSame(array('foo1' => true, 'foo2' => true, 'foo3' => true), $found);
    }

    public function test_set_multiple()
    {
        $found = $this->cache->set_multiple(
            array(
                'foo1' => 'bar',
                'foo2' => 'bar',
                'foo3' => 'bar',
            ),
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

    public function test_add_multiple_respects_suspend_addition()
    {
        wp_suspend_cache_addition(true);

        $found = $this->cache->add_multiple(
            array('a' => 1, 'b' => 2),
            'group1'
        );

        $this->assertSame(array('a' => false, 'b' => false), $found);

        // set is not affected by suspend.
        $this->assertSame(array('a' => true, 'b' => true), $this->cache->set_multiple(array('a' => 1, 'b' => 2), 'group1'));
    }

    public function test_capabilities_reports_six_features()
    {
        $this->assertTrue(wp_cache_supports('add_multiple'));
        $this->assertTrue(wp_cache_supports('set_multiple'));
        $this->assertTrue(wp_cache_supports('get_multiple'));
        $this->assertTrue(wp_cache_supports('delete_multiple'));
        $this->assertTrue(wp_cache_supports('flush_runtime'));
        $this->assertTrue(wp_cache_supports('flush_group'));
        $this->assertFalse(wp_cache_supports('nonexistent_feature'));
    }

    public function test_wp_cache_flush_group_facade()
    {
        $key = 'facade-key';
        $val = 'facade-val';

        wp_cache_set($key, $val, 'facade-group-flush');
        wp_cache_set($key, $val, 'facade-group-keep');

        $this->assertSame($val, wp_cache_get($key, 'facade-group-flush'));
        $this->assertSame($val, wp_cache_get($key, 'facade-group-keep'));

        $this->assertTrue(wp_cache_supports('flush_group'));
        $this->assertTrue(wp_cache_flush_group('facade-group-flush'));

        $this->assertFalse(wp_cache_get($key, 'facade-group-flush'));
        $this->assertSame($val, wp_cache_get($key, 'facade-group-keep'));
    }

    public function test_wp_cache_close_delegates_to_object_cache()
    {
        $adapter = $this->createMock(\Mincemeat\ObjectCache\PhpRedisAdapter::class);
        $adapter->expects($this->once())
            ->method('close');

        $ks = new \Mincemeat\ObjectCache\KeySpace(false, 1);
        $backend = new \Mincemeat\ObjectCache\Backend($ks, $adapter);
        $cache = new ObjectCache($ks, $backend);

        $GLOBALS['wp_object_cache'] = $cache;

        $this->assertTrue(wp_cache_close());
    }
}
