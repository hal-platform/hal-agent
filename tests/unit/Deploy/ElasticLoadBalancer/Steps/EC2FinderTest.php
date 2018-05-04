<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer\Steps;

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\CommandInterface;
use Aws\Result;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;

class EC2FinderTest extends MockeryTestCase
{
    public $logger;
    public $ec2;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->ec2 = Mockery::mock(Ec2Client::class);
    }

    public function testTagFiltersParseError()
    {
        $ec2Finder = new EC2Finder($this->logger);

        $actual = $ec2Finder(
            $this->ec2,
            ''
        );

        $this->assertSame(null, $actual);
    }

    public function testAwsException()
    {
        $this->ec2
            ->shouldReceive('describeInstances')
            ->andThrow(new AwsException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $ec2Finder = new EC2Finder($this->logger);

        $actual = $ec2Finder(
            $this->ec2,
            'deploymentgroup,Name=myapp'
        );

        $this->assertSame(null, $actual);
    }

    public function testCredentialsException()
    {
        $this->ec2
            ->shouldReceive('describeInstances')
            ->andThrow(new CredentialsException('errorMessage'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $ec2Finder = new EC2Finder($this->logger);

        $actual = $ec2Finder(
            $this->ec2,
            'deploymentgroup,Name=myapp'
        );

        $this->assertSame(null, $actual);
    }

    public function testNoInstancesFound()
    {
        $this->ec2
            ->shouldReceive('describeInstances')
            ->andReturn(new Result([
                'Reservations' => [
                    [
                        'Instances' => []
                    ]
                ]
            ]));

        $ec2Finder = new EC2Finder($this->logger);

        $actual = $ec2Finder(
            $this->ec2,
            'deploymentgroup,Name=myapp'
        );

        $this->assertSame(null, $actual);
    }

    public function testSuccess()
    {
        $result = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2
            ->shouldReceive('describeInstances')
            ->andReturn(new Result([
                'Reservations' => [
                    [
                        'Instances' => [
                            [
                                'InstanceId' => 'instance1',
                                'InstanceType' => 't2.micro',
                                'State' => [
                                    'Code' => 1,
                                    'Name' => 'running'
                                ]
                            ],
                            [
                                'InstanceId' => 'instance2',
                                'InstanceType' => 't2.micro',
                                'State' => [
                                    'Code' => 1,
                                    'Name' => 'running'
                                ]
                            ],
                            [
                                'InstanceId' => 'instance3',
                                'InstanceType' => 't2.micro',
                                'State' => [
                                    'Code' => 1,
                                    'Name' => 'running'
                                ]
                            ]
                        ]
                    ]
                ]
            ]));

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $ec2Finder = new EC2Finder($this->logger);

        $actual = $ec2Finder(
            $this->ec2,
            'deploymentgroup,Name=myapp'
        );

        $this->assertSame($result, $actual);
    }
}
