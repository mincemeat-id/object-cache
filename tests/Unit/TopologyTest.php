<?php
/**
 * Unit tests for the Topology classifier.
 *
 * @package Mincemeat\ObjectCache
 * @group unit
 */

declare(strict_types=1);

namespace Mincemeat\ObjectCache\Tests\Unit;

use Mincemeat\ObjectCache\Topology;
use Mincemeat\ObjectCache\Api;
use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class TopologyTest extends TestCase
{
    /**
     * @dataProvider topologyDataProvider
     */
    public function test_topology_classification(?array $info, string $expected_status, string $expected_mode, string $expected_role)
    {
        $result = Topology::classify($info);

        $this->assertSame($expected_status, $result['topology_status']);
        $this->assertSame($expected_mode, $result['topology_mode']);
        $this->assertSame($expected_role, $result['topology_role']);
    }

    public function topologyDataProvider(): array
    {
        return array(
            'null info' => array(
                null,
                Topology::UNVERIFIED,
                'unknown',
                'unknown',
            ),
            'standalone primary' => array(
                array('mode' => 'standalone', 'role' => 'primary'),
                Topology::COMPATIBLE,
                'standalone',
                'primary',
            ),
            'standalone master' => array(
                array('mode' => 'standalone', 'role' => 'master'),
                Topology::COMPATIBLE,
                'standalone',
                'primary',
            ),
            'cluster primary' => array(
                array('mode' => 'cluster', 'role' => 'primary'),
                Topology::UNSUPPORTED,
                'cluster',
                'primary',
            ),
            'sentinel mode' => array(
                array('mode' => 'sentinel', 'role' => 'master'),
                Topology::UNSUPPORTED,
                'sentinel',
                'primary',
            ),
            'standalone replica' => array(
                array('mode' => 'standalone', 'role' => 'replica'),
                Topology::UNSUPPORTED,
                'standalone',
                'replica',
            ),
            'standalone slave' => array(
                array('mode' => 'standalone', 'role' => 'slave'),
                Topology::UNSUPPORTED,
                'standalone',
                'replica',
            ),
            'incomplete info' => array(
                array('product' => 'redis'),
                Topology::UNVERIFIED,
                'unknown',
                'unknown',
            ),
        );
    }
}
