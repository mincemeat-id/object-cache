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

        // The suspended `add_multiple` must not touch the backend: the value
        // must not be retrievable afterwards, proving nothing was written.
        $this->assertFalse($this->cache->get('a', 'group1'));

        // The single-key facade is equally blocked while suspended.
        $this->assertFalse(wp_cache_add('z', 'v', 'group1'));
        $this->assertFalse($this->cache->get('z', 'group1'));

        // set is not affected by suspend.
        $this->assertSame(array('a' => true, 'b' => true), $this->cache->set_multiple(array('a' => 1, 'b' => 2), 'group1'));
    }

    public function test_capabilities_reports_six_features()
    {
        $supported = array(
            'add_multiple',
            'set_multiple',
            'get_multiple',
            'delete_multiple',
            'flush_runtime',
            'flush_group',
        );
        foreach ($supported as $feature) {
            $this->assertTrue(wp_cache_supports($feature), "Feature {$feature} must be advertised");
        }

        // Parity regression (P3-2): wp_cache_supports() must return true exactly
        // for the implemented set and false for anything else.
        foreach (array('foo', 'nonexistent_feature', 'get', 'set', 'flush', 'multi', '') as $unsupported) {
            $this->assertFalse(wp_cache_supports($unsupported), "{$unsupported} must not be advertised");
        }
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

    public function test_get_and_get_multiple_accepts_non_bool_force_without_error()
    {
        $this->cache->set('k1', 'v1', 'g1');

        $found = null;
        $val1 = wp_cache_get('k1', 'g1', 1, $found);
        $this->assertSame('v1', $val1);
        $this->assertTrue($found);

        $found = null;
        $val2 = wp_cache_get('k1', 'g1', '1', $found);
        $this->assertSame('v1', $val2);
        $this->assertTrue($found);

        $multi = wp_cache_get_multiple(array('k1'), 'g1', 1);
        $this->assertSame(array('k1' => 'v1'), $multi);
    }

    public function test_found_parameter_disambiguates_cached_false_from_miss()
    {
        wp_cache_set('false-key', false, 'g1');

        $found = null;
        $val = wp_cache_get('false-key', 'g1', false, $found);
        $this->assertFalse($val);
        $this->assertTrue($found);

        $found = null;
        $val_miss = wp_cache_get('miss-key', 'g1', false, $found);
        $this->assertFalse($val_miss);
        $this->assertFalse($found);
    }

    public function test_falsey_values_are_true_memory_hits()
    {
        $falsey = array(
            'k-false' => false,
            'k-zero'  => 0,
            'k-empty' => '',
            'k-null'  => null,
        );

        foreach ($falsey as $key => $value) {
            $this->cache->set($key, $value, 'g1');
        }

        // Each falsey value is a single-probe memory hit, never a miss.
        foreach ($falsey as $key => $value) {
            $found = null;
            $got   = $this->cache->get($key, 'g1', false, $found);
            $this->assertSame($value, $got, "get({$key}) must return the cached value.");
            $this->assertTrue($found, "get({$key}) must report a hit.");
        }

        // get_multiple surfaces the same true-hit semantics.
        $multi = $this->cache->get_multiple(array_keys($falsey), 'g1');
        $this->assertSame($falsey, $multi);

        // cache_hits reflects all eight hits (4 get + 4 get_multiple); misses zero.
        $this->assertSame(8, $this->cache->cache_hits);
        $this->assertSame(0, $this->cache->cache_misses);
    }

    public function test_advertised_wp_cache_supports_features_end_to_end()
    {
        $features = array('add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group');
        foreach ($features as $feature) {
            $this->assertTrue(wp_cache_supports($feature), "Feature {$feature} must be supported");
        }

        // Test add_multiple & get_multiple & delete_multiple
        $this->assertSame(array('k1' => true, 'k2' => true), wp_cache_add_multiple(array('k1' => 'v1', 'k2' => 'v2'), 'g_e2e'));
        $this->assertSame(array('k1' => 'v1', 'k2' => 'v2'), wp_cache_get_multiple(array('k1', 'k2'), 'g_e2e'));
        $this->assertSame(array('k1' => true, 'k2' => true), wp_cache_delete_multiple(array('k1', 'k2'), 'g_e2e'));

        // Test set_multiple & flush_group
        $this->assertSame(array('k1' => true, 'k2' => true), wp_cache_set_multiple(array('k1' => 'v1', 'k2' => 'v2'), 'g_e2e'));
        $this->assertTrue(wp_cache_flush_group('g_e2e'));
        $this->assertSame(array('k1' => false, 'k2' => false), wp_cache_get_multiple(array('k1', 'k2'), 'g_e2e'));

        // Test flush_runtime
        wp_cache_set('k_rt', 'v_rt', 'g_rt');
        $this->assertTrue(wp_cache_flush_runtime());
        $this->assertFalse(wp_cache_get('k_rt', 'g_rt'));
    }

    public function test_wp_object_cache_class_alias_and_bootstrap()
    {
        $this->assertTrue(class_exists('WP_Object_Cache'));
        $this->assertInstanceOf('WP_Object_Cache', $this->cache);
    }

    public function test_multisite_switch_to_blog_scoping()
    {
        $ks = new \Mincemeat\ObjectCache\KeySpace(true, 1);
        $cache = new ObjectCache($ks);
        $cache->add_global_groups(array('global-grp'));

        // Blog 1
        $cache->set('blog_item', 'blog1_val', 'local-grp');
        $cache->set('global_item', 'global_val', 'global-grp');

        $this->assertSame('blog1_val', $cache->get('blog_item', 'local-grp'));
        $this->assertSame('global_val', $cache->get('global_item', 'global-grp'));

        // Switch to Blog 2
        $cache->switch_to_blog(2);
        $this->assertFalse($cache->get('blog_item', 'local-grp'));
        $this->assertSame('global_val', $cache->get('global_item', 'global-grp'));

        $cache->set('blog_item', 'blog2_val', 'local-grp');
        $this->assertSame('blog2_val', $cache->get('blog_item', 'local-grp'));

        // Switch back to Blog 1
        $cache->switch_to_blog(1);
        $this->assertSame('blog1_val', $cache->get('blog_item', 'local-grp'));
    }

    /**
     * P3-1: the drop-in wires the multisite `switch_blog` action automatically and
     * the wired callback flips blog scope with global-group preservation and a
     * clean restore to the prior scope.
     */
    public function test_switch_blog_action_is_registered_and_flips_scope()
    {
        // Provide a real `add_action` so `wp_cache_init()` can register the
        // multisite `switch_blog` hook (the bootstrap does not define one).
        if (!function_exists('add_action')) {
            eval('function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { if (!isset($GLOBALS["__test_wp_actions"])) { $GLOBALS["__test_wp_actions"] = array(); } $GLOBALS["__test_wp_actions"][$hook][] = array("callback" => $callback, "accepted_args" => $accepted_args); }');
        }
        $GLOBALS['__test_wp_actions'] = array();

        wp_cache_init();

        // The drop-in must register the switch_blog action automatically.
        $this->assertArrayHasKey('switch_blog', $GLOBALS['__test_wp_actions']);
        $callbacks = array();
        foreach ($GLOBALS['__test_wp_actions']['switch_blog'] as $entry) {
            $callbacks[] = $entry['callback'];
        }
        $this->assertContains('wp_cache_switch_to_blog', $callbacks);

        // Prove the wired callback actually flips blog scope on a multisite cache.
        $ks = new \Mincemeat\ObjectCache\KeySpace(true, 1);
        $cache = new ObjectCache($ks);
        $cache->add_global_groups(array('global-grp'));
        $GLOBALS['wp_object_cache'] = $cache;

        $cache->set('local_k', 'b1', 'local-grp');
        $cache->set('global_k', 'gv', 'global-grp');

        // Simulate `switch_blog` firing: scope moves to blog 2, global survives.
        wp_cache_switch_to_blog(2);
        $this->assertFalse($cache->get('local_k', 'local-grp'));
        $this->assertSame('gv', $cache->get('global_k', 'global-grp'));

        $cache->set('local_k', 'b2', 'local-grp');
        $this->assertSame('b2', $cache->get('local_k', 'local-grp'));

        // Simulate `restore_current_blog()`: scope returns to blog 1.
        wp_cache_switch_to_blog(1);
        $this->assertSame('b1', $cache->get('local_k', 'local-grp'));
    }

    /**
     * P3-6: the `WP_Object_Cache` class alias is only registered when the class is
     * not already defined, so a co-resident cache or plugin is never clobbered.
     */
    public function test_wp_object_cache_alias_guard()
    {
        $key_space_src    = dirname(__FILE__, 3) . '/src/KeySpace.php';
        $object_cache_src = dirname(__FILE__, 3) . '/src/ObjectCache.php';
        $memory_tier_src  = dirname(__FILE__, 3) . '/src/MemoryTier.php';
        $functions_src    = dirname(__FILE__, 3) . '/src/functions.php';

        // Probe 1: WP_Object_Cache already defined -> the alias must NOT be created.
        $probe_defined = <<<'PHP'
class WP_Object_Cache {
    public $marker = 'predefined';
}
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
$o = new WP_Object_Cache();
echo get_class($o) . ':' . $o->marker;
PHP;

        $defined_out = $this->runPhpProbe($probe_defined, array($key_space_src, $object_cache_src, $memory_tier_src, $functions_src));
        $this->assertSame('WP_Object_Cache:predefined', $defined_out);

        // Probe 2: fresh process without WP_Object_Cache -> the alias IS created.
        $probe_fresh = <<<'PHP'
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
echo (class_exists('WP_Object_Cache') ? 'exits' : 'missing');
echo ':';
echo get_class(new WP_Object_Cache());
PHP;

        $fresh_out = $this->runPhpProbe($probe_fresh, array($key_space_src, $object_cache_src, $memory_tier_src, $functions_src));
        $this->assertStringStartsWith('exits:', $fresh_out);
        $this->assertSame('Mincemeat\\ObjectCache\\ObjectCache', substr($fresh_out, 6));
    }

    /**
     * Runs a small self-contained PHP probe in a subprocess and returns stdout.
     *
     * @param string   $code PHP code (may reference $argv[1..n]).
     * @param string[] $args Additional argv arguments (paths).
     * @return string
     */
    private function runPhpProbe(string $code, array $args): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg($arg);
        }

        $descriptors = array(
            0 => array('pipe', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $process = proc_open($cmd, $descriptors, $pipes);
        $this->assertIsResource($process);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        $this->assertSame(0, $status, 'PHP probe failed: ' . $stderr);

        return $stdout;
    }

    /* ----------------------------------------------------------------
     * Phase 2 — object-aliasing isolation (P2-1)
     * ---------------------------------------------------------------- */

    /**
     * Mutating a returned object must never change the cached copy across the
     * non-persistent set() + get() path.
     */
    public function test_aliasing_isolation_set_get()
    {
        $obj           = new MutableCacheTarget();
        $obj->value    = 'original';
        $this->assertTrue($this->cache->set('alias-set', $obj, 'g-alias'));

        $retrieved = $this->cache->get('alias-set', 'g-alias');
        $this->assertInstanceOf(MutableCacheTarget::class, $retrieved);
        $this->assertSame('original', $retrieved->value);

        // Mutating the returned object must not affect the cached copy.
        $retrieved->value = 'mutated';
        $again = $this->cache->get('alias-set', 'g-alias');
        $this->assertSame('original', $again->value, 'Cached object must be isolated from mutation.');
    }

    /**
     * Mutating a returned object must never change the cached copy across the
     * non-persistent get_multiple() path.
     */
    public function test_aliasing_isolation_get_multiple()
    {
        $obj           = new MutableCacheTarget();
        $obj->value    = 'original';
        $this->assertTrue($this->cache->set('alias-multi', $obj, 'g-alias'));

        $values = $this->cache->get_multiple(array('alias-multi'), 'g-alias');
        $this->assertArrayHasKey('alias-multi', $values);
        $this->assertInstanceOf(MutableCacheTarget::class, $values['alias-multi']);
        $this->assertSame('original', $values['alias-multi']->value);

        $values['alias-multi']->value = 'mutated';
        $again = $this->cache->get('alias-multi', 'g-alias');
        $this->assertSame('original', $again->value, 'get_multiple must not alias the cached object.');
    }

    /* ----------------------------------------------------------------
     * Phase 2 — close-path correctness (P2-5)
     * ---------------------------------------------------------------- */

    /**
     * The runtime-only path (no adapter) must close without exception.
     */
    public function test_close_runtime_only_no_adapter()
    {
        $cache = new ObjectCache();
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $cache->state());

        $this->assertTrue($cache->close());
    }

    /**
     * The degraded path (close after a mid-request circuit-open) must close
     * without exception and without leaking a connection.
     */
    public function test_close_degraded_no_exception_or_leak()
    {
        $ks = new \Mincemeat\ObjectCache\KeySpace(false, 1);

        $adapter = $this->createMock(\Mincemeat\ObjectCache\PhpRedisAdapter::class);
        $adapter->expects($this->once())
            ->method('close');
        // A mid-request backend command failure opens the circuit (degraded).
        $adapter->method('get')->willThrowException(new \RedisException('command failed mid-request'));

        $backend = new \Mincemeat\ObjectCache\Backend($ks, $adapter);
        $backend->initialize(
            new \Mincemeat\ObjectCache\Config(array(
                'namespace' => 'close-degraded-test',
                'scheme'    => 'tcp',
                'host'      => '127.0.0.1',
                'port'      => 6379,
            ))
        );

        $cache = new ObjectCache($ks, $backend);
        $this->assertSame(ObjectCache::STATE_PERSISTENT, $cache->state());

        // Trigger a backend read that fails and opens the circuit.
        $found = null;
        $cache->get('k', 'g', false, $found);
        $this->assertSame(ObjectCache::STATE_DEGRADED, $cache->state());

        // Closing a degraded cache must not throw and must still release the
        // underlying adapter connection exactly once.
        $this->assertTrue($cache->close());
    }
}

/**
 * A small mutable test object with a public property, used to prove that
 * object-aliasing isolation holds across every cache entry/exit path.
 */
class MutableCacheTarget
{
    /** @var string */
    public $value = '';
}

