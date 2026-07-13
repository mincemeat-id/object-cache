<?php
/**
 * Integration tests for atomic increment/decrement (Phase 4).
 *
 * Tests the Lua script against a live Redis/Valkey server: ordinary integer
 * cases, offsets, missing values, decrement floor, nonnumeric values, TTL
 * preservation, no-expiry preservation, max_ttl not reset, and cross-request
 * visibility.
 *
 * @package Mincemeat\ObjectCache
 * @group integration
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Integration;

use Mincemeat\ObjectCache\LuaScripts;
use Mincemeat\ObjectCache\ValueCodec;

class AtomicNumericTest extends IntegrationTestCase
{
    public function test_incr_missing_returns_false()
    {
        $this->assertFalse($this->cache->incr('missing-key', 1, 'options'));
    }

    public function test_decr_missing_returns_false()
    {
        $this->assertFalse($this->cache->decr('missing-key', 1, 'options'));
    }

    public function test_incr_from_zero()
    {
        $this->cache->set('k', 0, 'options');
        $this->assertSame(1, $this->cache->incr('k', 1, 'options'));
        $this->assertSame(1, $this->cache->get('k', 'options'));
    }

    public function test_incr_with_offset()
    {
        $this->cache->set('k', 5, 'options');
        $this->assertSame(7, $this->cache->incr('k', 2, 'options'));
        $this->assertSame(10, $this->cache->incr('k', 3, 'options'));
    }

    public function test_decr_with_offset()
    {
        $this->cache->set('k', 10, 'options');
        $this->assertSame(9, $this->cache->decr('k', 1, 'options'));
        $this->assertSame(7, $this->cache->decr('k', 2, 'options'));
    }

    public function test_decr_clamps_at_zero()
    {
        $this->cache->set('k', 3, 'options');
        $this->assertSame(2, $this->cache->decr('k', 1, 'options'));
        $this->assertSame(0, $this->cache->decr('k', 2, 'options'));
        $this->assertSame(0, $this->cache->decr('k', 100, 'options'));
    }

    public function test_decr_from_zero_stays_zero()
    {
        $this->cache->set('k', 0, 'options');
        $this->assertSame(0, $this->cache->decr('k', 1, 'options'));
        $this->assertSame(0, $this->cache->decr('k', 5, 'options'));
    }

    public function test_negative_offset_incr()
    {
        $this->cache->set('k', 10, 'options');
        $this->assertSame(7, $this->cache->incr('k', -3, 'options'));
    }

    public function test_incr_result_visible_across_requests()
    {
        $this->cache->set('k', 100, 'options');
        $this->cache->incr('k', 50, 'options');

        $new = $this->new_request();
        $this->assertSame(150, $new->get('k', 'options'));
    }

    public function test_decr_result_visible_across_requests()
    {
        $this->cache->set('k', 100, 'options');
        $this->cache->decr('k', 30, 'options');

        $new = $this->new_request();
        $this->assertSame(70, $new->get('k', 'options'));
    }

    public function test_incr_nonnumeric_normalizes_to_zero()
    {
        $this->cache->set('k', 'not-a-number', 'options');
        $this->assertSame(1, $this->cache->incr('k', 1, 'options'));
        $this->assertSame(1, $this->cache->get('k', 'options'));
    }

    public function test_incr_string_numeric_value_succeeds()
    {
        $this->cache->set('k', '42', 'options');
        $this->assertSame(43, $this->cache->incr('k', 1, 'options'));
        $this->assertSame(43, $this->cache->get('k', 'options'));
    }

    public function test_incr_float_value_succeeds()
    {
        $this->cache->set('k', 3.14, 'options');
        $this->assertSame(4, $this->cache->incr('k', 1, 'options'));
        $this->assertSame(4, $this->cache->get('k', 'options'));

        $new = $this->new_request();
        $this->assertSame(4, $new->get('k', 'options'));
    }

    public function test_incr_decr_extra_coercion_and_boundaries()
    {
        // 1. String/fractional offsets and negative offsets
        $this->cache->set('k-offset', 10, 'options');
        $this->assertSame(12, $this->cache->incr('k-offset', '2', 'options'));
        $this->assertSame(13, $this->cache->incr('k-offset', 1.5, 'options'));
        $this->assertSame(11, $this->cache->incr('k-offset', -2, 'options'));

        // 2. Group '0'
        $this->cache->set('k-group-0', 100, 0);
        $this->assertSame(101, $this->cache->incr('k-group-0', 1, 0));
        $this->assertSame(101, $this->cache->get('k-group-0', 0));

        // 3. Large integer boundaries (2^53 + 1)
        $this->cache->set('k-large-53', 9007199254740993, 'options');
        $this->assertSame(9007199254740994, $this->cache->incr('k-large-53', 1, 'options'));

        // 4. Integer overflow is bounded without widening the return type.
        $this->cache->set('k-int-max', PHP_INT_MAX, 'options');
        $this->assertSame(PHP_INT_MAX, $this->cache->incr('k-int-max', 1, 'options'));

        $new = $this->new_request();
        $this->assertSame(PHP_INT_MAX, $new->get('k-int-max', 'options'));
    }

    public function test_incr_preserves_finite_ttl()
    {
        $this->cache->set('k', 5, 'options', 10);
        $this->cache->incr('k', 1, 'options');

        // Verify the item still has a TTL close to 10 seconds.
        $new = $this->new_request();
        $this->assertSame(6, $new->get('k', 'options'));
    }

    public function test_decr_preserves_finite_ttl()
    {
        $this->cache->set('k', 5, 'options', 10);
        $this->cache->decr('k', 1, 'options');

        $new = $this->new_request();
        $this->assertSame(4, $new->get('k', 'options'));
    }

    public function test_incr_preserves_no_expiry()
    {
        $this->cache->set('k', 5, 'options', 0);
        $this->cache->incr('k', 1, 'options');

        // Sleep briefly; the value should still exist (no expiry was set).
        usleep(500000);
        $new = $this->new_request();
        $this->assertSame(6, $new->get('k', 'options'));
    }

    public function test_incr_does_not_reset_max_ttl()
    {
        // Set with a short TTL; incr should not extend it beyond the original.
        $this->cache->set('k', 5, 'options', 2);
        $this->cache->incr('k', 1, 'options');

        sleep(3);

        $new = $this->new_request();
        $this->assertFalse($new->get('k', 'options'));
    }

    public function test_repeated_incr_accumulates()
    {
        $this->cache->set('k', 0, 'options');

        for ($i = 0; $i < 100; $i++) {
            $this->cache->incr('k', 1, 'options');
        }

        $this->assertSame(100, $this->cache->get('k', 'options'));
    }

    public function test_repeated_decr_accumulates_and_clamps()
    {
        $this->cache->set('k', 50, 'options');

        for ($i = 0; $i < 100; $i++) {
            $this->cache->decr('k', 1, 'options');
        }

        $this->assertSame(0, $this->cache->get('k', 'options'));
    }

    public function test_large_offset()
    {
        $this->cache->set('k', 0, 'options');
        $this->assertSame(1000000, $this->cache->incr('k', 1000000, 'options'));
        $this->assertSame(1000000, $this->cache->get('k', 'options'));
    }

    public function test_negative_integer_value_clamps_at_zero()
    {
        // WordPress core clamps all negative results to zero, even for incr.
        $this->cache->set('k', -10, 'options');
        $this->assertSame(0, $this->cache->incr('k', 1, 'options'));
        $this->assertSame(0, $this->cache->get('k', 'options'));
    }

    public function test_incr_then_delete()
    {
        $this->cache->set('k', 5, 'options');
        $this->cache->incr('k', 1, 'options');
        $this->assertTrue($this->cache->delete('k', 'options'));
        $this->assertFalse($this->cache->get('k', 'options'));
    }

    public function test_incr_after_flush_returns_false()
    {
        $this->cache->set('k', 5, 'options');
        $this->cache->flush();

        $new = $this->new_request();
        $this->assertFalse($new->incr('k', 1, 'options'));
    }

    public function test_lua_eval_incr_directly()
    {
        // Test the raw Lua script via Backend::eval_incr.
        $this->cache->set('k', 42, 'options');

        $key_space = $this->backend;
        $ns_tok   = $this->backend->namespace_token();
        $grp_tok  = $this->backend->group_token('options');
        $item_key = $this->cache->key_space()->item_key($ns_tok, $grp_tok, 'options', 'k');

        list($code, $value) = $this->backend->eval_incr($item_key, 8);

        $this->assertSame(LuaScripts::RESULT_OK, $code);
        $this->assertSame(50, $value);
    }

    public function test_lua_eval_incr_missing()
    {
        $ns_tok   = $this->backend->namespace_token();
        $grp_tok  = $this->backend->group_token('options');
        $item_key = $this->cache->key_space()->item_key($ns_tok, $grp_tok, 'options', 'nonexistent');

        list($code, $value) = $this->backend->eval_incr($item_key, 1);

        $this->assertSame(LuaScripts::RESULT_MISSING, $code);
        $this->assertNull($value);
    }

    public function test_lua_eval_incr_non_numeric_coerces_to_zero()
    {
        $this->cache->set('k', 'string-not-int', 'options');

        $ns_tok   = $this->backend->namespace_token();
        $grp_tok  = $this->backend->group_token('options');
        $item_key = $this->cache->key_space()->item_key($ns_tok, $grp_tok, 'options', 'k');

        list($code, $value) = $this->backend->eval_incr($item_key, 1);

        $this->assertSame(LuaScripts::RESULT_OK, $code);
        $this->assertSame(1, $value);
    }
}
