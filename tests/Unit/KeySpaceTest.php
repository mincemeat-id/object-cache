<?php
/**
 * Unit tests for the runtime KeySpace: WordPress 6.9 key validation and
 * group/scope derivation. Targets 100% branch coverage for these paths.
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
class KeySpaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__mincemeat_doing_it_wrong'] = array();
    }

    public function test_int_and_non_blank_string_keys_are_valid()
    {
        $ks = new KeySpace(false, 1);

        $this->assertTrue($ks->is_valid_key(0));
        $this->assertTrue($ks->is_valid_key(1));
        $this->assertTrue($ks->is_valid_key('0'));
        $this->assertTrue($ks->is_valid_key('key'));
        $this->assertTrue($ks->is_valid_key('a b')); // spaces allowed, just not blank-only
    }

    /**
     * @dataProvider invalid_keys
     */
    public function test_invalid_keys_record_doing_it_wrong_and_return_false($key, string $expectSubstring)
    {
        $ks = new KeySpace(false, 1);

        $this->assertFalse($ks->is_valid_key($key));

        $calls = $GLOBALS['__mincemeat_doing_it_wrong'] ?? array();
        $this->assertNotEmpty($calls);
        $last = end($calls);
        $this->assertStringContainsString($expectSubstring, $last[1]);
    }

    public function invalid_keys(): array
    {
        return array(
            'false'     => array(false, 'integer or a non-empty string'),
            'null'      => array(null, 'integer or a non-empty string'),
            'float'     => array(0.0, 'double'),
            'array'     => array(array(), 'array'),
            'object'    => array(new \stdClass(), 'object'),
            'empty str' => array('', 'must not be an empty string'),
            'space'     => array(' ', 'must not be an empty string'),
            'two space' => array('  ', 'must not be an empty string'),
            'newline'   => array("\n", 'must not be an empty string'),
            'null byte' => array("\0", 'must not be an empty string'),
        );
    }

    public function test_invalid_key_caller_label_reflects_calling_class_and_method()
    {
        $ks = new KeySpace(false, 1);

        // When KeySpace::is_valid_key is called directly from a test, the
        // caller label is the test class and method.
        $this->assertFalse($ks->is_valid_key(''));

        $calls = $GLOBALS['__mincemeat_doing_it_wrong'];
        $last  = end($calls);
        $this->assertSame(__CLASS__ . '::test_invalid_key_caller_label_reflects_calling_class_and_method', $last[0]);
    }

    public function test_normalize_group()
    {
        $ks = new KeySpace(false, 1);

        $this->assertSame('default', $ks->normalize_group(''));
        $this->assertSame('0', $ks->normalize_group('0')); // '0' is a valid, non-empty group
        $this->assertSame('foo', $ks->normalize_group('foo'));
        $this->assertSame('Foo', $ks->normalize_group('Foo')); // case-sensitive
        $this->assertSame('0', $ks->normalize_group('0'));
        $this->assertSame(' a ', $ks->normalize_group(' a ')); // whitespace preserved
    }

    public function test_storage_id_single_site_ignores_global_registration()
    {
        $ks = new KeySpace(false, 1);

        $this->assertSame('k', $ks->storage_id('k', 'default'));
        $this->assertSame('k', $ks->storage_id('k', 'non-global-group'));
    }

    public function test_storage_id_global_group_has_no_blog_prefix_under_multisite()
    {
        $ks = new KeySpace(true, 5);
        $ks->add_global_groups(array('global-group'));

        $this->assertSame('k', $ks->storage_id('k', 'global-group'));

        // Non-global stores scoped to active blog.
        $this->assertSame('5:k', $ks->storage_id('k', 'local'));

        $ks->switch_to_blog(7);
        $this->assertSame('k', $ks->storage_id('k', 'global-group'));
        $this->assertSame('7:k', $ks->storage_id('k', 'local'));

        $this->assertSame('7:', $ks->blog_prefix());
        $this->assertTrue($ks->is_multisite());

        $ks->switch_to_blog(9);
        $this->assertSame('9:', $ks->blog_prefix());
    }

    public function test_storage_id_single_site_switch_is_noop()
    {
        $ks = new KeySpace(false, 1);
        $this->assertSame('', $ks->blog_prefix());
        $this->assertFalse($ks->is_multisite());

        $ks->switch_to_blog(123);
        $this->assertSame('', $ks->blog_prefix());
        $this->assertSame('k', $ks->storage_id('k', 'local'));
    }

    public function test_add_global_groups_accepts_scalar_or_array_and_dedupes()
    {
        $ks = new KeySpace(true, 1);

        $ks->add_global_groups('one');
        $ks->add_global_groups(array('two', 'three', 'one'));

        $this->assertSame(array('one' => true, 'two' => true, 'three' => true), $ks->global_groups());
        $this->assertTrue($ks->is_global_group('one'));
        $this->assertFalse($ks->is_global_group('four'));
    }

    public function test_add_global_groups_casts_non_strings_to_string()
    {
        $ks = new KeySpace(true, 1);
        $ks->add_global_groups(array(7, 8));

        $this->assertTrue($ks->is_global_group('7'));
        $this->assertTrue($ks->is_global_group('8'));
    }
}