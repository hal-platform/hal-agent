<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
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
                'filter' => [
                    'tag:hal_pool' => 'pool-name',
                    'instance-state-code' => 16,
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

        $finder = new InstanceFinder($this->ec2);
        $actual = $finder('pool-name', 16);

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
                'filter' => [
                    'tag:hal_pool' => 'pool-name',
                    'instance-state-code' => 16,
                ]
            ])
            ->andReturn(['Reservations' => [
                ['derp1'],
                ['derp2']
            ]]);

        $finder = new InstanceFinder($this->ec2);
        $actual = $finder('pool-name', 16);

        $this->assertSame([], $actual);
    }

}
