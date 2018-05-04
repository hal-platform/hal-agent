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

class HealthCheckerTest extends MockeryTestCase
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
            ->shouldReceive('describeLoadBalancers')
            ->andThrow(new ElasticLoadBalancingException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $this->elb
            ->shouldReceive('deregisterInstancesFromLoadBalancer')
            ->andThrow(new ElasticLoadBalancingException('errorMessage', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $health = new HealthChecker($this->logger);

        $actual = $health(
            $this->elb,
            'elbName');

        $this->assertSame(false, $actual);
    }

    public function testCredentialsException()
    {
        $this->elb
            ->shouldReceive('describeLoadBalancers')
            ->andThrow(new CredentialsException('errorMessage'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $health = new HealthChecker($this->logger);

        $actual = $health(
            $this->elb,
            'elbName');

        $this->assertSame(false, $actual);
    }

    public function testInvalidElbResponse() {
        $this->elb
            ->shouldReceive('describeLoadBalancers')
            ->andReturn(new Result([]));

        $health = new HealthChecker($this->logger);

        $actual = $health(
            $this->elb,
            'elbName');

        $this->assertSame(false, $actual);

    }

    public function testEmptyInstanceStates() {

        $describeResult = $this->describeLoadBalancersResponce();

        $this->elb
            ->shouldReceive('describeLoadBalancers')
            ->andReturn($describeResult);

        $instancesStates = [
            'InstancesStates' => []
        ];

        $this->elb->shouldReceive('describeInstanceHealth')
            ->with(Mockery::any())
            ->andReturn(new Result(
                $instancesStates
            ))
            ->once();

        $health = new HealthChecker($this->logger);

        $actual = $health(
            $this->elb,
            'elbName');

        $this->assertSame(false, $actual);
    }

    public function testSuccess () {
        $describeResult = $this->describeLoadBalancersResponce();

        $this->elb
            ->shouldReceive('describeLoadBalancers')
            ->andReturn($describeResult);

        $instancesStates = [
            'InstanceStates' => [
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

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $health = new HealthChecker($this->logger);

        $actual = $health(
            $this->elb,
            'elbName'
        );

        $this->assertSame(true, $actual);
    }

    private function describeLoadBalancersResponce() {
        $response =
                  [
                      'LoadBalancerDescriptions' => [
                          [
                              'AvailabilityZones' => [
                                  'us-west-2a',
                              ],
                              'BackendServerDescriptions' => [
                                  [
                                      'InstancePort' => 80,
                                      'PolicyNames' => [
                                          'my-ProxyProtocol-policy',
                                      ],
                                  ],
                              ],
                              'CanonicalHostedZoneName' => 'my-load-balancer-1234567890.us-west-2.elb.amazonaws.com',
                              'CanonicalHostedZoneNameID' => 'Z3DZXE0EXAMPLE',
                              'CreatedTime' => '',
                              'DNSName' => 'my-load-balancer-1234567890.us-west-2.elb.amazonaws.com',
                              'HealthCheck' => [
                                  'HealthyThreshold' => 2,
                                  'Interval' => 30,
                                  'Target' => 'HTTP:80/png',
                                  'Timeout' => 3,
                                  'UnhealthyThreshold' => 2,
                              ],
                              'Instances' => [
                                  [
                                      'InstanceId' => 'i-207d9717',
                                  ],
                                  [
                                      'InstanceId' => 'i-afefb49b',
                                  ],
                              ],
                              'ListenerDescriptions' => [
                                  [
                                      'Listener' => [
                                          'InstancePort' => 80,
                                          'InstanceProtocol' => 'HTTP',
                                          'LoadBalancerPort' => 80,
                                          'Protocol' => 'HTTP',
                                      ],
                                      'PolicyNames' => [
                                      ],
                                  ],
                                  [
                                      'Listener' => [
                                          'InstancePort' => 443,
                                          'InstanceProtocol' => 'HTTPS',
                                          'LoadBalancerPort' => 443,
                                          'Protocol' => 'HTTPS',
                                          'SSLCertificateId' => 'arn:aws:iam::123456789012:server-certificate/my-server-cert',
                                      ],
                                      'PolicyNames' => [
                                          'ELBSecurityPolicy-2015-03',
                                      ],
                                  ],
                              ],
                              'LoadBalancerName' => 'my-load-balancer',
                              'Policies' => [
                                  'AppCookieStickinessPolicies' => [
                                  ],
                                  'LBCookieStickinessPolicies' => [
                                      [
                                          'CookieExpirationPeriod' => 60,
                                          'PolicyName' => 'my-duration-cookie-policy',
                                      ],
                                  ],
                                  'OtherPolicies' => [
                                      'my-PublicKey-policy',
                                      'my-authentication-policy',
                                      'my-SSLNegotiation-policy',
                                      'my-ProxyProtocol-policy',
                                      'ELBSecurityPolicy-2015-03',
                                  ],
                              ],
                              'Scheme' => 'internet-facing',
                              'SecurityGroups' => [
                                  'sg-a61988c3',
                              ],
                              'SourceSecurityGroup' => [
                                  'GroupName' => 'my-elb-sg',
                                  'OwnerAlias' => '123456789012',
                              ],
                              'Subnets' => [
                                  'subnet-15aaab61',
                              ],
                              'VPCId' => 'vpc-a01106c2',
                          ],
                      ],
                  ];

        $result = new Result($response);

        return $result;
    }
}