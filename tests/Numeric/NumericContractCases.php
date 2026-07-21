<?php
/**
 * Shared numeric contract cases for runtime and persistent cache tiers.
 *
 * @package Mincemeat\ObjectCache
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Numeric;

/**
 * Provides values that expose WordPress and Mincemeat coercion boundaries.
 */
final class NumericContractCases
{
    /**
     * @return array<string,array{0:mixed,1:mixed,2:int,3:int|float}>
     */
    public static function increments(): array
    {
        return array(
            'integer'                => array(42, 1, 43, 43),
            'zero'                   => array(0, 1, 1, 1),
            'boolean true'           => array(true, 1, 1, 1),
            'boolean false'          => array(false, 1, 1, 1),
            'null'                   => array(null, 1, 1, 1),
            'non-numeric string'     => array('word', 2, 2, 2),
            'integer string'         => array('42', 1, 43, 43),
            'signed integer string'  => array('  +42  ', 1, 43, 43),
            'decimal string'         => array('3.75', 1, 4, 4.75),
            'exponent string'        => array('1e2', 1, 101, 101.0),
            'float'                  => array(3.75, 1, 4, 4.75),
            'negative integer'       => array(-10, 1, 0, 0),
            'negative crossing zero' => array(-2, 5, 3, 3),
            'array'                  => array(array('value'), 1, 1, 1),
            'object'                 => array((object) array('value' => 1), 1, 1, 1),
            'fractional offset'      => array(10, 1.75, 11, 11),
            'numeric string offset'  => array(10, '2', 12, 12),
            'negative offset'        => array(10, -3, 7, 7),
            'minimum integer value'  => array(PHP_INT_MIN, PHP_INT_MAX, 0, 0),
            'minimum signed offset'  => array(PHP_INT_MAX, PHP_INT_MIN, 0, 0),
            'integer saturation'     => array(PHP_INT_MAX, 1, PHP_INT_MAX, PHP_INT_MAX + 1),
        );
    }

    /**
     * @return array<string,array{0:mixed,1:mixed,2:int,3:int|float}>
     */
    public static function decrements(): array
    {
        return array(
            'ordinary'        => array(10, 3, 7, 7),
            'floor'           => array(2, 5, 0, 0),
            'boolean true'    => array(true, 1, 0, 0),
            'non-numeric'     => array('word', 1, 0, 0),
            'negative offset' => array(10, -2, 12, 12),
            'minimum offset'  => array(PHP_INT_MAX, PHP_INT_MIN, 0, PHP_INT_MAX - PHP_INT_MIN),
        );
    }

    private function __construct()
    {
    }
}
