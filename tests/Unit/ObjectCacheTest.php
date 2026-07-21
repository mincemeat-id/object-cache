<?php
/**
 * Unit tests for the runtime ObjectCache: explicit behaviors beyond the core
 * contract suite — forced reads, case sensitivity, whitespace/Unicode/long
 * keys, blog switch-back, non-persistent groups, counters, and the read-only
 * API.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\Api;
use Mincemeat\ObjectCache\KeySpace;
use Mincemeat\ObjectCache\ObjectCache;
use Mincemeat\ObjectCache\Tests\Numeric\NumericContractCases;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class ObjectCacheTest extends TestCase
{
    /**
     * @var ObjectCache
     */
    private $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
        $GLOBALS['__mincemeat_deprecated']      = array();
        $GLOBALS['blog_id']                       = 1;

        $this->cache = new ObjectCache();
    }

    protected function tearDown(): void
    {
        $this->cache->flush();
        if (function_exists('wp_suspend_cache_addition')) {
            wp_suspend_cache_addition(false);
        }
        parent::tearDown();
    }

    public function test_empty_group_normalizes_to_default()
    {
        $this->cache->set('k', 'v', '');
        $this->assertSame('v', $this->cache->get('k'));
        $this->assertSame('v', $this->cache->get('k', 'default'));
    }

    public function test_case_sensitive_keys_and_groups_do_not_collide()
    {
        $this->cache->set('Key', 'lower-kept');
        $this->cache->set('KEY', 'upper');

        $this->assertSame('lower-kept', $this->cache->get('Key'));
        $this->assertSame('upper', $this->cache->get('KEY'));

        $this->cache->set('k', 'g1', 'Group');
        $this->cache->set('k', 'g2', 'group');

        $this->assertSame('g1', $this->cache->get('k', 'Group'));
        $this->assertSame('g2', $this->cache->get('k', 'group'));
    }

    public function test_whitespace_in_keys_is_preserved()
    {
        $this->cache->set(' spaced ', 'a');
        $this->assertSame('a', $this->cache->get(' spaced '));
        $this->assertFalse($this->cache->get('spaced'));
    }

    public function test_punctuation_in_keys_is_preserved()
    {
        $key = "a!@#$%^&*()[]{};:'\",.<>/?\\|`~";
        $this->cache->set($key, 'punct');
        $this->assertSame('punct', $this->cache->get($key));
    }

    public function test_unicode_keys_are_preserved()
    {
        $key = "日本語-key-café-emoji";

        $this->cache->set($key, 'utf8');
        $this->assertSame('utf8', $this->cache->get($key));
    }

    public function test_very_long_keys_are_preserved()
    {
        $key = str_repeat('k', 5000);

        $this->cache->set($key, 'long');
        $this->assertSame('long', $this->cache->get($key));
    }

    public function test_integer_and_string_keys_follow_php_semantics()
    {
        // Integer 1 and string '1' are the same PHP array key; core stores
        // under whatever the caller passes, but PHP array normalization makes
        // them collapse. Core's behavior: add(1) then add('1') fails because
        // they share storage. Mirror that.
        $this->assertTrue($this->cache->add(1, 'int'));
        $this->assertFalse($this->cache->add('1', 'str'));
        $this->assertSame('int', $this->cache->get(1));
    }

    public function test_forced_read_bypasses_runtime_and_misses_cleanly()
    {
        $this->cache->set('k', 'v', 'g');

        // At the runtime tier there is no backend, so a forced read must still
        // resolve from memory and set $found correctly.
        $found = null;
        $this->assertSame('v', $this->cache->get('k', 'g', true, $found));
        $this->assertTrue($found);

        $found = null;
        $this->assertSame(false, $this->cache->get('absent', 'g', true, $found));
        $this->assertFalse($found);
    }

    public function test_found_is_always_assigned()
    {
        $found = 'untouched';

        $this->cache->set('k', 'v');
        $this->assertSame('v', $this->cache->get('k', '', false, $found));
        $this->assertTrue($found);

        $this->assertSame(false, $this->cache->get('miss', '', false, $found));
        $this->assertFalse($found);
    }

    public function test_invalid_key_get_returns_false_without_touching_found_or_misses()
    {
        $found   = 'sentinel';
        $before  = $this->cache->misses();

        $this->assertSame(false, $this->cache->get('', 'g', false, $found));
        $this->assertSame('sentinel', $found);
        $this->assertSame($before, $this->cache->misses());
    }

    public function test_suspend_addition_blocks_add_but_not_set()
    {
        wp_suspend_cache_addition(true);

        $this->assertFalse($this->cache->add('a', 1));
        $this->assertFalse($this->cache->add('b', 2, 'g'));

        $this->assertTrue($this->cache->set('a', 1));
        $this->assertSame(1, $this->cache->get('a'));
    }

    public function test_suspend_addition_blocks_add_multiple_per_key()
    {
        wp_suspend_cache_addition(true);

        $result = $this->cache->add_multiple(array('a' => 1, 'b' => 2), 'g');
        $this->assertSame(array('a' => false, 'b' => false), $result);
    }

    public function test_counters_track_hits_and_misses()
    {
        $hits   = $this->cache->hits();
        $misses = $this->cache->misses();

        $this->cache->set('h', 'v', 'g');
        $this->cache->get('h', 'g');     // hit
        $this->cache->get('m', 'g');     // miss
        $this->cache->get_multiple(array('h', 'm2'), 'g'); // 1 hit + 1 miss

        $this->assertSame($hits + 2, $this->cache->hits());
        $this->assertSame($misses + 2, $this->cache->misses());
    }

    public function test_state_is_runtime_only_with_reason()
    {
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $this->cache->state());
        $this->assertSame('no-backend', $this->cache->reason());
    }

    public function test_non_persistent_groups_are_registered()
    {
        $this->cache->add_non_persistent_groups(array('counts', 'plugins'));
        $this->cache->add_non_persistent_groups('theme_json');

        $this->assertTrue($this->cache->is_non_persistent_group('counts'));
        $this->assertTrue($this->cache->is_non_persistent_group('plugins'));
        $this->assertTrue($this->cache->is_non_persistent_group('theme_json'));
        $this->assertFalse($this->cache->is_non_persistent_group('options'));
    }

    public function test_non_persistent_groups_still_work_in_memory()
    {
        $this->cache->add_non_persistent_groups('counts');

        $this->cache->set('k', 5, 'counts');
        $this->assertSame(5, $this->cache->get('k', 'counts'));
        $this->assertSame(6, $this->cache->incr('k', 1, 'counts'));
    }

    public function test_non_persistent_batch_operations_clone_objects_and_never_call_backend()
    {
        $this->cache->add_non_persistent_groups('local-batch');
        $object = (object) array('value' => 'original');

        $this->assertSame(
            array('object' => true, 'number' => true),
            $this->cache->set_multiple(array('object' => $object, 'number' => 4), 'local-batch')
        );
        $values = $this->cache->get_multiple(array('object', 'number', 'missing'), 'local-batch');
        $this->assertEquals($object, $values['object']);
        $this->assertNotSame($object, $values['object']);
        $values['object']->value = 'mutated';

        $again = $this->cache->get_multiple(array('object'), 'local-batch');
        $this->assertSame('original', $again['object']->value);
        $this->assertSame(4, $values['number']);
        $this->assertFalse($values['missing']);
        $this->assertSame(
            array('object' => true, 'missing' => false),
            $this->cache->delete_multiple(array('object', 'missing'), 'local-batch')
        );
        $this->assertSame(0, $this->cache->backend_calls());
    }

    public function test_numeric_operations_clamp_integer_boundaries_without_overflow()
    {
        $this->cache->add_non_persistent_groups('numbers');
        $this->cache->set('minimum-offset', PHP_INT_MAX, 'numbers');
        $this->assertSame(0, $this->cache->decr('minimum-offset', PHP_INT_MIN, 'numbers'));

        $this->cache->set('maximum', PHP_INT_MAX, 'numbers');
        $this->assertSame(PHP_INT_MAX, $this->cache->incr('maximum', 1, 'numbers'));
        $this->cache->set('negative', -10, 'numbers');
        $this->assertSame(0, $this->cache->incr('negative', 1, 'numbers'));
        $this->cache->set('not-numeric', 'word', 'numbers');
        $this->assertSame(2, $this->cache->incr('not-numeric', 2, 'numbers'));
    }

    public function test_runtime_numeric_contract_matrix()
    {
        $this->cache->add_non_persistent_groups('numeric-contract');

        foreach (NumericContractCases::increments() as $label => $case) {
            list($value, $offset, $expected) = $case;
            $key = 'incr-' . md5($label);
            $this->cache->set($key, $value, 'numeric-contract');
            $this->assertSame($expected, $this->cache->incr($key, $offset, 'numeric-contract'), $label);
            $this->assertSame($expected, $this->cache->get($key, 'numeric-contract'), $label);
        }

        foreach (NumericContractCases::decrements() as $label => $case) {
            list($value, $offset, $expected) = $case;
            $key = 'decr-' . md5($label);
            $this->cache->set($key, $value, 'numeric-contract');
            $this->assertSame($expected, $this->cache->decr($key, $offset, 'numeric-contract'), $label);
            $this->assertSame($expected, $this->cache->get($key, 'numeric-contract'), $label);
        }
    }

    public function test_switch_to_blog_back_restores_scope()
    {
        $multisite_cache = new ObjectCache(new KeySpace(true, 2));

        $multisite_cache->set('k', 'on-2');
        $this->assertSame('on-2', $multisite_cache->get('k'));

        $multisite_cache->switch_to_blog(3);
        $this->assertFalse($multisite_cache->get('k'));
        $multisite_cache->set('k', 'on-3');
        $this->assertSame('on-3', $multisite_cache->get('k'));

        $multisite_cache->switch_to_blog(2);
        $this->assertSame('on-2', $multisite_cache->get('k'));
    }

    public function test_multisite_global_group_shared_across_blogs()
    {
        $cache = new ObjectCache(new KeySpace(true, 2));
        $cache->add_global_groups('global-group');

        $cache->set('k', 'shared', 'global-group');
        $this->assertSame('shared', $cache->get('k', 'global-group'));

        $cache->switch_to_blog(9);
        $this->assertSame('shared', $cache->get('k', 'global-group'));

        $cache->set('k', 'overwritten', 'global-group');
        $this->assertSame('overwritten', $cache->get('k', 'global-group'));

        $cache->switch_to_blog(2);
        $this->assertSame('overwritten', $cache->get('k', 'global-group'));
    }

    public function test_reset_reports_deprecation_and_clears_non_global_groups()
    {
        $this->cache->add_global_groups('global');
        $this->cache->set('k', 'g', 'global');
        $this->cache->set('k', 'l', 'local');

        $this->cache->reset();

        $this->assertSame('g', $this->cache->get('k', 'global'));
        $this->assertFalse($this->cache->get('k', 'local'));

        $called = $GLOBALS['__mincemeat_deprecated'] ?? array();
        $this->assertNotEmpty($called);
    }

    public function test_api_status_reports_runtime_only_when_initialized()
    {
        $GLOBALS['wp_object_cache'] = $this->cache;

        $status = Api::status();
        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $status['state']);
        $this->assertSame('no-backend', $status['reason']);

        unset($GLOBALS['wp_object_cache']);
    }

    public function test_api_capabilities_reports_phpredis_and_six_features()
    {
        $caps = Api::capabilities();
        $this->assertSame('phpredis', $caps['client']);
        $this->assertSame(
            array('add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group'),
            $caps['features']
        );
    }

    public function test_api_metrics_reads_live_counters()
    {
        $GLOBALS['wp_object_cache'] = $this->cache;
        $this->cache->get('miss');

        $m = Api::metrics();
        $this->assertSame($this->cache->hits(), $m['hits']);
        $this->assertSame($this->cache->misses(), $m['misses']);
        $this->assertSame(0, $m['backend_calls']);
        $this->assertSame(0.0, $m['backend_time']);
        $this->assertSame(0, $m['errors']);

        unset($GLOBALS['wp_object_cache']);
    }

    public function test_api_diagnostics_is_secret_free()
    {
        $GLOBALS['wp_object_cache'] = $this->cache;
        $this->cache->add_global_groups('users');

        $d = Api::diagnostics();

        $this->assertSame(ObjectCache::STATE_RUNTIME_ONLY, $d['state']);
        $this->assertArrayNotHasKey('password', $d);
        $this->assertArrayNotHasKey('username', $d);
        $this->assertContains('users', $d['global_groups']);
        $this->assertArrayHasKey('metrics', $d);
        $this->assertArrayHasKey('versions', $d);

        unset($GLOBALS['wp_object_cache']);
    }

    public function test_api_version_reports_implementation_and_schema()
    {
        $v = Api::version();
        $this->assertSame(Api::IMPLEMENTATION_VERSION, $v['implementation']);
        $this->assertSame(Api::SCHEMA_VERSION, $v['schema']);
    }
}
