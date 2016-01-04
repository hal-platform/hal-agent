<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\EC2;

use Mockery;
use PHPUnit_Framework_TestCase;

class InstanceFinderTest extends PHPUnit_Framework_TestCase
{
    public $ec2;

    public function setUp()
    {
        $this->ec2 = Mockery::mock('Aws\Ec2\Ec2Client');
    }

    public function testSuccess()
    {
        $this->ec2
            ->shouldReceive('describeInstances')
            ->with([
                'Filters' => [
                    ['Name' => 'tag:hal_pool', 'Values' => ['pool-name']],
                    ['Name' => 'instance-state-code', 'Values' => [16]],
                ]
            ])
            ->andReturn(['Reservations' => [
                [
                    'Instances' => [
                        ['dooger1'],
                        ['johnny5']
                    ]
                ]
            ]]);

        $finder = new InstanceFinder;
        $actual = $finder($this->ec2, 'pool-name', 16);

        $expected = [
            ['dooger1'],
            ['johnny5']
        ];
        $this->assertSame($expected, $actual);
    }

    public function testMultipleReservationsIsInvalid()
    {
        $this->ec2
            ->shouldReceive('describeInstances')
            ->with([
                'Filters' => [
                    ['Name' => 'tag:hal_pool', 'Values' => ['pool-name']]
                ]
            ])
            ->andReturn(['Reservations' => [
                ['derp1'],
                ['derp2']
            ]]);

        $finder = new InstanceFinder;
        $actual = $finder($this->ec2, 'pool-name');

        $this->assertSame([], $actual);
    }

}
