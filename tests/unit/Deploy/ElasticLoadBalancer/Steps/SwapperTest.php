<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Aws\CommandInterface;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\ElasticLoadBalancing\Exception\ElasticLoadBalancingException;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\Result;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Waiter\Waiter;
use Mockery;

class SwapperTest extends MockeryTestCase
{
    public $logger;
    public $elb;
    public $waiter;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->elb = Mockery::mock(ElasticLoadBalancingClient::class);
        $this->waiter = new Waiter();
    }

    public function testElasticLoadBalancingException()
    {
        $this->elb
            ->shouldReceive('registerInstancesWithLoadBalancer')
            ->andThrow(new ElasticLoadBalancingException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $this->elb
            ->shouldReceive('deregisterInstancesFromLoadBalancer')
            ->andThrow(new ElasticLoadBalancingException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $swapper = new Swapper($this->logger, $this->waiter);

        $addInstances = [
            'i-abcd1234',
            'i-abcd1236'
        ];

        $removeInstances = [
            'i-abcd1247',
            'i-abcd1257'
        ];

        $actual = $swapper(
        $this->elb,
            'elbName',
            $addInstances,
            $removeInstances);

        $this->assertSame(false, $actual);
    }

    public function testCredentialsException()
    {
        $this->elb
            ->shouldReceive('registerInstancesWithLoadBalancer')
            ->andThrow(new CredentialsException('errorMessage'));

        $this->elb
            ->shouldReceive('deregisterInstancesFromLoadBalancer')
            ->andThrow(new CredentialsException('errorMessage'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $swapper = new Swapper($this->logger, $this->waiter);

        $addInstances = [
            'i-abcd1234',
            'i-abcd1236'
        ];

        $removeInstances = [
            'i-abcd1247',
            'i-abcd1257'
        ];

        $actual = $swapper(
        $this->elb,
            'elbName',
            $addInstances,
            $removeInstances);

        $this->assertSame(false, $actual);
    }

    public function testWaiterInstanceInServiceFail()
    {
        $this->elb
            ->shouldReceive('registerInstancesWithLoadBalancer')
            ->andReturn(true);

        $this->elb
            ->shouldReceive('deregisterInstancesFromLoadBalancer')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $swapper = new Swapper($this->logger, $this->waiter);

        $addInstances = [
            'i-abcd1234',
            'i-abcd1236'
        ];

        $removeInstances = [
            'i-abcd1247',
            'i-abcd1257'
        ];

        $this->elb->shouldReceive('describeInstanceHealth')
            ->with(Mockery::any())
            ->andThrow(new AwsException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $actual = $swapper(
        $this->elb,
            'elbName',
            $addInstances,
            $removeInstances);

        $this->assertSame(false, $actual);
    }

    public function testWaiterInstanceOutOfServiceFail()
    {
        $this->elb
            ->shouldReceive('registerInstancesWithLoadBalancer')
            ->andReturn(true);

        $this->elb
            ->shouldReceive('deregisterInstancesFromLoadBalancer')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $swapper = new Swapper($this->logger, $this->waiter);

        $addInstances = [
            'i-abcd1234',
            'i-abcd1236'
        ];

        $removeInstances = [
            'i-abcd1247',
            'i-abcd1257'
        ];

        $instancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1234',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1235',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1236',
                    'ReasonCode' => 'N/A',
                    'State' => 'Unknown'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1237',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
            ]
        ];

        $this->elb->shouldReceive('describeInstanceHealth')
            ->with(Mockery::any())
            ->andReturn(new Result(
                $instancesStates
            ))
            ->once();

        $this->elb->shouldReceive('describeInstanceHealth')
            ->with(Mockery::any())
            ->andThrow(new AwsException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $actual = $swapper(
        $this->elb,
            'elbName',
            $addInstances,
            $removeInstances);

        $this->assertSame(false, $actual);
    }

    public function testWaitersSuccess()
    {
        $this->elb
            ->shouldReceive('registerInstancesWithLoadBalancer')
            ->andReturn(true);

        $this->elb
            ->shouldReceive('deregisterInstancesFromLoadBalancer')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $swapper = new Swapper($this->logger, $this->waiter);

        $addInstances = [
            'i-abcd1234',
            'i-abcd1236'
        ];

        $removeInstances = [
            'i-abcd1247',
            'i-abcd1257'
        ];

        $addedInstancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1234',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1235',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1236',
                    'ReasonCode' => 'N/A',
                    'State' => 'Unknown'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1237',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
            ]
        ];

        $this->elb->shouldReceive('describeInstanceHealth')
            ->with(Mockery::any())
            ->andReturn(new Result(
                $addedInstancesStates
            ))
            ->once();

        $removedInstancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1247',
                    'ReasonCode' => 'N/A',
                    'State' => 'OutOfService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd1247',
                    'ReasonCode' => 'N/A',
                    'State' => 'OutOfService'
                ]
            ]
        ];

        $this->elb->shouldReceive('describeInstanceHealth')
            ->with(Mockery::any())
            ->andReturn(new Result(
                $addedInstancesStates
            ))
            ->once();

        $actual = $swapper(
        $this->elb,
            'elbName',
            $addInstances,
            $removeInstances);

        $this->assertSame(true, $actual);
    }
}
