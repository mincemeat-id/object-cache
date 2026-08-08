<?php
/**
 * Unit tests for the MemoryTier request-local cache collaborator.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\MemoryTier;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class MemoryTierTest extends TestCase
{
	public function test_set_and_read_round_trip()
	{
		$tier = new MemoryTier();

		$this->assertTrue($tier->set('id', 'grp', 'value'));
		$this->assertSame(array(true, 'value'), $tier->read('id', 'grp'));
		$this->assertTrue($tier->exists('id', 'grp'));
	}

	public function test_read_miss_returns_false()
	{
		$tier = new MemoryTier();

		$this->assertSame(array(false, false), $tier->read('missing', 'grp'));
		$this->assertFalse($tier->exists('missing', 'grp'));
	}

	public function test_falsey_values_are_real_hits()
	{
		$tier = new MemoryTier();

		foreach (array(false, 0, '', null) as $idx => $falsey) {
			$id = 'k' . $idx;
			$tier->set($id, 'grp', $falsey);
			$this->assertSame(array(true, $falsey), $tier->read($id, 'grp'), 'falsey hit disambiguation');
			$this->assertTrue($tier->exists($id, 'grp'));
		}
	}

	public function test_set_clones_objects_on_store()
	{
		$tier = new MemoryTier();
		$obj  = new \stdClass();
		$obj->name = 'original';

		$tier->set('id', 'grp', $obj);

		$obj->name = 'mutated';

		list($found, $stored) = $tier->read('id', 'grp');
		$this->assertTrue($found);
		$this->assertSame('original', $stored->name, 'store must clone so later mutation is isolated');
		$this->assertNotSame($obj, $stored);
	}

	public function test_remove_and_remove_group()
	{
		$tier = new MemoryTier();
		$tier->set('a', 'g1', 1);
		$tier->set('b', 'g1', 2);
		$tier->set('c', 'g2', 3);

		$tier->remove('a', 'g1');
		$this->assertFalse($tier->exists('a', 'g1'));
		$this->assertTrue($tier->exists('b', 'g1'));

		$tier->remove_group('g1');
		$this->assertFalse($tier->exists('b', 'g1'));
		$this->assertTrue($tier->exists('c', 'g2'));
	}

	public function test_clear_empties_the_tier()
	{
		$tier = new MemoryTier();
		$tier->set('a', 'g1', 1);
		$tier->set('b', 'g2', 2);

		$tier->clear();

		$this->assertSame(array(), $tier->groups());
		$this->assertSame(0, $tier->entry_count());
	}

	public function test_entry_count_and_groups()
	{
		$tier = new MemoryTier();

		$this->assertSame(0, $tier->entry_count());
		$this->assertSame(array(), $tier->groups());

		$tier->set('a', 'g1', 1);
		$tier->set('b', 'g1', 2);
		$tier->set('c', 'g2', 3);

		$this->assertSame(3, $tier->entry_count());
		$this->assertSame(array('g1' => array('a' => 1, 'b' => 2), 'g2' => array('c' => 3)), $tier->groups());
	}
}