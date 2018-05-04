<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticLoadBalancer;

use Aws\Ec2\Ec2Client;
use Aws\ElasticLoadBalancing\ElasticLoadBalancingClient;

use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\Configurator;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\EC2Finder;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\ELBManager;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\HealthChecker;
use Hal\Agent\Deploy\ElasticLoadBalancer\Steps\Swapper;

use Hal\Agent\JobExecution;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\JobType\Build;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class ELBDeployPlatformTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $configurator;
    public $ec2Finder;
    public $elbManager;
    public $health;
    public $swapper;

    public $elb;
    public $ec2;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->configurator = Mockery::mock(Configurator::class);
        $this->ec2Finder = Mockery::mock(EC2Finder::class);
        $this->elbManager = Mockery::mock(ELBManager::class);
        $this->health = Mockery::mock(HealthChecker::class);
        $this->swapper = Mockery::mock(Swapper::class);

        $this->elb = Mockery::mock(ElasticLoadBalancingClient::class);
        $this->ec2  = Mockery::Mock(Ec2Client::class);

    }

    public function testWrongJobTypeFails()
    {
        $job = new Job;
        $execution = $this->generateMockExecution();
        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'The provided job is an invalid type for this job platform', Mockery::any())
            ->once();

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] The provided job is an invalid type for this job platform'
        ];
        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testMissingConfigFails()
    {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Elastic Load Balancer deploy platform is not configured correctly', Mockery::any())
            ->once();

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Elastic Load Balancer deploy platform is not configured correctly'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testEmptyTaggedInstances() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Could not find tagged instances', Mockery::any())
            ->once();

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Could not find tagged instances'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testEmptyActiveELBInstances() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Could not find valid instances in Active ELB', Mockery::any())
            ->once();

        $tagedInstances = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn($tagedInstances);

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->andReturn([]);

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Could not find valid instances in Active ELB'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testEmptyPassiveELBInstances() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Could not find valid instances in Passive ELB', Mockery::any())
            ->once();

        $tagedInstances = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn($tagedInstances);

        $activeInstancesStates = [
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
                ]
            ]
        ];

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'active_lb', Mockery::any())
            ->andReturn($activeInstancesStates)
            ->once();

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'passive_lb', Mockery::any())
            ->andReturn([])
            ->once();

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Could not find valid instances in Passive ELB'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFalsePassiveSwapELBInstances() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Could not swap instances in Passive ELB', Mockery::any())
            ->once();

        $tagedInstances = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn($tagedInstances);

        $activeInstancesStates = [
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
                ]
            ]
        ];

        $passiveInstancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2234',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2235',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ]
            ]
        ];
        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'active_lb', Mockery::any())
            ->andReturn($activeInstancesStates)
            ->once();

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'passive_lb', Mockery::any())
            ->andReturn($passiveInstancesStates)
            ->once();

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'passive_lb', $activeInstancesStates, $passiveInstancesStates)
            ->andReturn(false);

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Could not swap instances in Passive ELB'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFalseActiveSwapELBInstances() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Could not swap instances in Active ELB', Mockery::any())
            ->once();

        $tagedInstances = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn($tagedInstances);

        $activeInstancesStates = [
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
                ]
            ]
        ];

        $passiveInstancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2234',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2235',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ]
            ]
        ];
        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'active_lb', Mockery::any())
            ->andReturn($activeInstancesStates)
            ->once();

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'passive_lb', Mockery::any())
            ->andReturn($passiveInstancesStates)
            ->once();

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'passive_lb', $activeInstancesStates, $passiveInstancesStates)
            ->andReturn(true);

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'active_lb', $passiveInstancesStates, $activeInstancesStates)
            ->andReturn(false);

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Could not swap instances in Active ELB'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFalseCheckActiveELBHealth() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Active Elastic Load Balancer is not ready', Mockery::any())
            ->once();

        $tagedInstances = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn($tagedInstances);

        $activeInstancesStates = [
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
                ]
            ]
        ];

        $passiveInstancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2234',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2235',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ]
            ]
        ];
        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'active_lb', Mockery::any())
            ->andReturn($activeInstancesStates)
            ->once();

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'passive_lb', Mockery::any())
            ->andReturn($passiveInstancesStates)
            ->once();

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'passive_lb', $activeInstancesStates, $passiveInstancesStates)
            ->andReturn(true);

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'active_lb', $passiveInstancesStates, $activeInstancesStates)
            ->andReturn(true);

        $this->health
            ->shouldReceive('__invoke')
            ->with($this->elb, 'active_lb')
            ->andReturn(false);

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Active Elastic Load Balancer is not ready'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function testFalseCheckPassiveELBHealth() {
        $job = $this->generateMockRelease();
        $execution = $this->generateMockExecution();
        $properties = [];
        $config = $this->configuration();

        $this->configurator
            ->shouldReceive('__invoke')
            ->andReturn($config);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Passive Elastic Load Balancer is not ready', Mockery::any())
            ->once();

        $tagedInstances = [
            'instance1',
            'instance2',
            'instance3'
        ];

        $this->ec2Finder
            ->shouldReceive('__invoke')
            ->andReturn($tagedInstances);

        $activeInstancesStates = [
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
                ]
            ]
        ];

        $passiveInstancesStates = [
            'InstancesStates' => [
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2234',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ],
                [
                    'Description' => 'N/A',
                    'InstanceId' => 'i-abcd2235',
                    'ReasonCode' => 'N/A',
                    'State' => 'InService'
                ]
            ]
        ];
        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'active_lb', Mockery::any())
            ->andReturn($activeInstancesStates)
            ->once();

        $this->elbManager
            ->shouldReceive('getValidELBInstances')
            ->with(Mockery::any(), 'passive_lb', Mockery::any())
            ->andReturn($passiveInstancesStates)
            ->once();

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'passive_lb', $activeInstancesStates, $passiveInstancesStates)
            ->andReturn(true);

        $this->swapper
            ->shouldReceive('__invoke')
            ->with($this->elb, 'active_lb', $passiveInstancesStates, $activeInstancesStates)
            ->andReturn(true);

        $this->health
            ->shouldReceive('__invoke')
            ->with($this->elb, 'active_lb')
            ->andReturn(true);

        $this->health
            ->shouldReceive('__invoke')
            ->with($this->elb, 'passive_lb')
            ->andReturn(false);

        $platform = new ELBDeployPlatform(
            $this->logger,
            $this->configurator,
            $this->health,
            $this->ec2Finder,
            $this->swapper,
            $this->elbManager
        );

        $platform->setIO($this->io());

        $actual = $platform($job, $execution, $properties);
        $expected = [
            '[ERROR] Passive Elastic Load Balancer is not ready'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(false, $actual);
    }

    public function generateMockExecution()
    {
        return new JobExecution('elb', 'deploy', []);
    }

    public function generateMockRelease()
    {
        return (new Release('1234'))
            ->withEnvironment(
                (new Environment('1234'))
                ->withName('UnitTestEnv')
            )
            ->withBuild(new Build('1234'));
    }

    public function configuration()
    {
        return [
            'sdk' => [
                'elb' => $this->elb,
                'ec2' => $this->ec2
            ],
            'region' => 'us-test-1',

            'active_lb' => 'active_lb',
            'passive_lb' => 'passive_lb',
            'ec2_tag' => 'ec2_tag'
        ];
    }
}
