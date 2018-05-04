<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Hal\Agent\Logger\EventLogger;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;
use Aws\Result;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\CommandInterface;
use Aws\ElasticLoadBalancing\Exception\ElasticLoadBalancingException;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;

class ELBManagerTest extends MockeryTestCase
{
    public $logger;
    public $elb;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->elb = Mockery::mock(ElasticLoadBalancingClient::class);
    }

    public function testElasticLoadBalancingException()
    {
        $this->elb
            ->shouldReceive('describeInstanceHealth')
            ->andThrow(new ElasticLoadBalancingException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $taggedInstances = [
            'instanceX'
        ];

        $elbManager = new ELBManager($this->logger);

        $actual = $elbManager->getValidELBInstances(
            $this->elb,
            'elbName',
            $taggedInstances
        );

        $this->assertSame(null, $actual);
    }

    public function testCredentialsException()
    {
        $this->elb
            ->shouldReceive('describeInstanceHealth')
            ->andThrow(new CredentialsException('errorMessage'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $taggedInstances = [
            'instanceX'
        ];

        $elbManager = new ELBManager($this->logger);

        $actual = $elbManager->getValidELBInstances(
            $this->elb,
            'elbName',
            $taggedInstances
        );

        $this->assertSame(null, $actual);
    }

    public function testUnknownOutOfServiceInstances()
    {
        $taggedInstances = [
            'i-abcd1234',
            'i-abcd1236',
            'i-abcd1237',
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
                    'State' => 'OutOfService'
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
            ));

        $this->logger
            ->shouldReceive('event')
            ->with('info', Mockery::any(), Mockery::any())
            ->once();

        $elbManager = new ELBManager($this->logger);

        $actual = $elbManager->getValidELBInstances(
            $this->elb,
            'elbName',
            $taggedInstances
        );

        $expected = [
            'i-abcd1234',
            'i-abcd1236',
            'i-abcd1237',
        ];

        $this->assertSame($expected, $actual);
    }

    public function testUnknownInServiceInstances()
    {
        $taggedInstances = [
            'i-abcd1234',
            'i-abcd1235',
            'i-abcd1236',
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
                    'State' => 'OutOfService'
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
            ));

        $this->logger
            ->shouldReceive('event')
            ->with('info', Mockery::any(), Mockery::any())
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $elbManager = new ELBManager($this->logger);

        $actual = $elbManager->getValidELBInstances(
            $this->elb,
            'elbName',
            $taggedInstances
        );

        $this->assertSame(null, $actual);
    }

    public function testSuccess()
    {
        $taggedInstances = [
            'i-abcd1234',
            'i-abcd1235',
            'i-abcd1236',
            'i-abcd1237'
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
                    'State' => 'OutOfService'
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
            ));

        $elbManager = new ELBManager($this->logger);

        $actual = $elbManager->getValidELBInstances(
            $this->elb,
            'elbName',
            $taggedInstances
        );

        $expected = [
            'i-abcd1234',
            'i-abcd1235',
            'i-abcd1236',
            'i-abcd1237',
        ];

        $this->assertSame($expected, $actual);
    }
}
