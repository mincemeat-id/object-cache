<?php
/**
 * Unit tests for KeySpace persistent-key derivation: SHA-256 digests, stable
 * control/item key layout, scope markers, and random token generation.
 *
 * Also covers distinct-key behavior for case, spaces, punctuation,
 * integer/string identity, Unicode, and long inputs at the digest level.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\KeySpace;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class KeySpacePersistentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
    }

    private function ks(bool $multisite = false, int $blog = 1, string $namespace = 'install-a'): KeySpace
    {
        $ks = new KeySpace($multisite, $blog, $namespace);
        $ks->add_global_groups(array('users', 'site-options'));

        return $ks;
    }

    public function test_namespace_digest_is_sha256_of_namespace()
    {
        $ks = $this->ks();

        $this->assertSame(hash('sha256', 'install-a'), $ks->namespace_digest());
    }

    public function test_group_digest_is_sha256_of_normalized_group()
    {
        $ks = $this->ks();

        $this->assertSame(hash('sha256', 'options'), $ks->group_digest('options'));
        $this->assertSame(hash('sha256', 'users'), $ks->group_digest('users'));
        $this->assertSame(hash('sha256', 'MixedCase'), $ks->group_digest('MixedCase'));
    }

    public function test_key_digest_is_sha256_of_string_key()
    {
        $ks = $this->ks();

        $this->assertSame(hash('sha256', 'key'), $ks->key_digest('key'));
        $this->assertSame(hash('sha256', '42'), $ks->key_digest(42));
        $this->assertSame(hash('sha256', ' spaced '), $ks->key_digest(' spaced '));
    }

    public function test_distinct_keys_produce_distinct_digests()
    {
        $ks = $this->ks();

        $this->assertNotEquals($ks->key_digest('Key'), $ks->key_digest('KEY'));
        $this->assertNotEquals($ks->key_digest(' spaced '), $ks->key_digest('spaced'));
        $this->assertNotEquals($ks->key_digest("punct!"), $ks->key_digest("punct"));
        $this->assertNotEquals($ks->group_digest('Group'), $ks->group_digest('group'));

        // Unicode vs ascii
        $this->assertNotEquals($ks->key_digest('cafe'), $ks->key_digest("caf\xc3\xa9"));

        // integer 1 and string '1' produce the same digest (int cast to '1');
        // this matches core's array-key normalization for integer/string 1.
        $this->assertSame($ks->key_digest(1), $ks->key_digest('1'));

        // Very long keys produce 64-char digests regardless of input length.
        $long_digest = $ks->key_digest(str_repeat('x', 50000));
        $this->assertSame(64, strlen($long_digest));
    }

    public function test_distinct_installations_produce_distinct_control_keys()
    {
        $a = $this->ks(false, 1, 'install-a');
        $b = $this->ks(false, 1, 'install-b');

        $this->assertNotEquals($a->namespace_control_key(), $b->namespace_control_key());
        $this->assertNotEquals($a->group_control_key('options'), $b->group_control_key('options'));
    }

    public function test_namespace_control_key_layout()
    {
        $ks = $this->ks();

        $expected = KeySpace::SCHEMA_MARKER . ':' . hash('sha256', 'install-a') . ':nstok';
        $this->assertSame($expected, $ks->namespace_control_key());
    }

    public function test_group_control_key_layout()
    {
        $ks = $this->ks();

        $expected = KeySpace::SCHEMA_MARKER
            . ':' . hash('sha256', 'install-a')
            . ':g:' . hash('sha256', 'options')
            . ':gtok';
        $this->assertSame($expected, $ks->group_control_key('options'));
    }

    public function test_item_key_layout_single_site()
    {
        $ks = $this->ks(false, 1, 'install-a');
        $ns_tok   = 'aaaaaaaaaaaaaaaa';
        $grp_tok  = 'bbbbbbbbbbbbbbbb';

        $expected = KeySpace::SCHEMA_MARKER
            . ':' . hash('sha256', 'install-a')
            . ':i:' . $ns_tok
            . ':' . hash('sha256', 'options')
            . ':' . $grp_tok
            . ':s:' . hash('sha256', 'key');
        $this->assertSame($expected, $ks->item_key($ns_tok, $grp_tok, 'options', 'key'));
    }

    public function test_item_key_layout_multisite_blog_scope()
    {
        $ks = $this->ks(true, 5, 'install-a');
        $ns_tok   = 'aaaaaaaaaaaaaaaa';
        $grp_tok  = 'bbbbbbbbbbbbbbbb';

        $expected = KeySpace::SCHEMA_MARKER
            . ':' . hash('sha256', 'install-a')
            . ':i:' . $ns_tok
            . ':' . hash('sha256', 'local')
            . ':' . $grp_tok
            . ':b5:' . hash('sha256', 'key');
        $this->assertSame($expected, $ks->item_key($ns_tok, $grp_tok, 'local', 'key'));
    }

    public function test_item_key_global_scope_is_network_marker()
    {
        $ks = $this->ks(true, 5, 'install-a');

        $this->assertSame('global', $ks->scope_for('users'));
        $this->assertSame('b5', $ks->scope_for('local'));
    }

    public function test_scope_single_site_is_stable_marker()
    {
        $ks = $this->ks(false, 1, 'install-a');

        $this->assertSame('s', $ks->scope_for('local'));
        $this->assertSame('global', $ks->scope_for('users'));

        // Switching blog on single-site does not change scope.
        $ks->switch_to_blog(999);
        $this->assertSame('s', $ks->scope_for('local'));
    }

    public function test_group_control_key_excludes_blog_scope_across_multisite()
    {
        $ks_a = $this->ks(true, 1, 'install-a');
        $ks_b = clone $ks_a;
        $ks_b->switch_to_blog(99);

        // Group token key is independent of blog scope so flush_group
        // invalidates the group across the installation/network.
        $this->assertSame($ks_a->group_control_key('options'), $ks_b->group_control_key('options'));
    }

    public function test_set_and_get_namespace_token()
    {
        $ks = $this->ks();
        $this->assertSame('', $ks->namespace_token());

        $tok = 'deadbeefdeadbeefdeadbeefdeadbeef';
        $ks->set_namespace_token($tok);
        $this->assertSame($tok, $ks->namespace_token());
    }

    public function test_generate_token_is_32_hex_chars_and_random()
    {
        $a = KeySpace::generate_token();
        $b = KeySpace::generate_token();

        $this->assertSame(32, strlen($a));
        $this->assertSame(32, strlen($b));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $a);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $b);
        $this->assertNotEquals($a, $b);
    }

    public function test_configure_sets_namespace_digest_from_config()
    {
        // KeySpace constructed without a namespace initially.
        $ks = new KeySpace(false, 1);
        $this->assertSame('', $ks->namespace_digest());

        $config = new \Mincemeat\ObjectCache\Config(array('namespace' => 'configured-ns'));
        $ks->configure($config);

        $this->assertSame(hash('sha256', 'configured-ns'), $ks->namespace_digest());
    }

    public function test_cloning_isolates_blog_scope_state()
    {
        $orig = $this->ks(true, 2);
        $copy = clone $orig;

        $copy->switch_to_blog(77);

        // Original retains its blog prefix; clone is independent.
        $this->assertSame('2:', $orig->blog_prefix());
        $this->assertSame('77:', $copy->blog_prefix());

        // Global group registration is copied.
        $this->assertTrue($copy->is_global_group('users'));
    }
}