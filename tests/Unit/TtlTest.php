<?php
/**
 * Unit tests for Ttl: normalization, capping, and remaining-TTL representation.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\Ttl;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class TtlTest extends TestCase
{
    /**
     * @dataProvider resolve_cases
     */
    public function test_resolve(int $caller, int $max_ttl, int $expected)
    {
        $this->assertSame($expected, Ttl::resolve($caller, $max_ttl));
    }

    public function resolve_cases(): array
    {
        return array(
            'caller zero, max applies'        => array(0, 2592000, 2592000),
            'caller negative, max applies'     => array(-5, 2592000, 2592000),
            'caller zero, no max'              => array(0, 0, 0),
            'caller negative, no max'          => array(-1, 0, 0),
            'caller under max'                 => array(60, 2592000, 60),
            'caller equals max'                => array(2592000, 2592000, 2592000),
            'caller exceeds max'              => array(9999999, 2592000, 2592000),
            'caller exceeds max (zero max)'    => array(60, 0, 60),
        );
    }

    public function test_remaining_from_pttl()
    {
        $this->assertNull(Ttl::remaining_from_pttl(Ttl::NO_EXPIRY_MS));
        $this->assertNull(Ttl::remaining_from_pttl(Ttl::MISSING_MS));
        $this->assertNull(Ttl::remaining_from_pttl(-5));
        $this->assertSame(1000, Ttl::remaining_from_pttl(1000));
    }

    public function test_to_ms()
    {
        $this->assertNull(Ttl::to_ms(0));
        $this->assertSame(1000, Ttl::to_ms(1));
        $this->assertSame(60000, Ttl::to_ms(60));
    }
}