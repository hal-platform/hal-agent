<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\EC2;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $output;

    public $authenticator;
    public $finder;
    public $pusher;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->output = new BufferedOutput;

        $this->authenticator = Mockery::mock('QL\Hal\Agent\Push\AWSAuthenticator');
        $this->finder = Mockery::mock('QL\Hal\Agent\Push\EC2\InstanceFinder');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\EC2\Pusher');
    }

    public function testSuccess()
    {
        $properties = [
            'ec2' => [
                'region' => 'us-east-1',
                'credential' => null,
                'pool' => 'poolname',
                'remotePath' => '/path/var/www'
            ],
            'configuration' => [
                'system' => '',
                'build_transform' => ['cmd1'],
                'pre_push' => [],
                'post_push' => [],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'environmentVariables' => []
        ];

        $ec2 = Mockery::mock('Aws\Ec2\Ec2Client');
        $this->authenticator
            ->shouldReceive('getEC2')
            ->andReturn($ec2);

        $this->finder
            ->shouldReceive('__invoke')
            ->with($ec2, 'poolname', 16)
            ->andReturn([
                ['instance1'],
                ['instance2']
            ]);

        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $deployer = new Deployer($this->logger, $this->authenticator, $this->finder, $this->pusher);
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
[Deploying - EC2] Deploying push by EC2
[Deploying - EC2] Verifying EC2 configuration
[Deploying - EC2] Authenticating with AWS
[Deploying - EC2] Finding EC2 instances in pool
[Deploying - EC2] Pushing code to EC2 instances

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testPushCommandsAreLoggedAndSkipped()
    {
        $properties = [
            'ec2' => [
                'region' => 'us-east-1',
                'credential' => null,
                'pool' => '',
                'remotePath' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => ['cmd1'],
                'post_push' => ['cmd2', 'cmd3'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->authenticator
            ->shouldReceive('getEC2')
            ->andReturn(Mockery::mock('Aws\Ec2\Ec2Client'));

        $this->finder
            ->shouldReceive('__invoke')
            ->andReturn([
                ['instance1'],
                ['instance2']
            ]);

        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_PRE_PUSH)
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('info', Deployer::SKIP_POST_PUSH)
            ->once();

        $deployer = new Deployer($this->logger, $this->authenticator, $this->finder, $this->pusher);
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);
        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
[Deploying - EC2] Deploying push by EC2
[Deploying - EC2] Verifying EC2 configuration
[Deploying - EC2] Authenticating with AWS
[Deploying - EC2] Finding EC2 instances in pool
[Deploying - EC2] Pushing code to EC2 instances

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSanityCheckFails()
    {
        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer($this->logger, $this->authenticator, $this->finder, $this->pusher);

        $actual = $deployer($properties);
        $this->assertSame(300, $actual);
    }

    public function testRequiredPropertyMissingFails()
    {
        $properties = [
            'ec2' => [
                'region' => '',
                'pool' => '',
                'remotePath' => ''
            ]
        ];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer($this->logger, $this->authenticator, $this->finder, $this->pusher);

        $actual = $deployer($properties);
        $this->assertSame(300, $actual);
    }

    public function testNoInstanceFoundFails()
    {
        $properties = [
            'ec2' => [
                'region' => '',
                'credential' => '',
                'pool' => '',
                'remotePath' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => ['cmd1'],
                'post_push' => ['cmd2', 'cmd3'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ]
        ];

        $this->authenticator
            ->shouldReceive('getEC2')
            ->andReturn(Mockery::mock('Aws\Ec2\Ec2Client'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_NO_INSTANCES)
            ->once();

        $this->finder
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $deployer = new Deployer($this->logger, $this->authenticator, $this->finder, $this->pusher);

        $actual = $deployer($properties);
        $this->assertSame(302, $actual);
    }

    public function testPushFails()
    {
        $properties = [
            'ec2' => [
                'region' => '',
                'credential' => '',
                'pool' => '',
                'remotePath' => ''
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => [],
                'post_push' => [],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'environmentVariables' => []
        ];


        $this->authenticator
            ->shouldReceive('getEC2')
            ->andReturn(Mockery::mock('Aws\Ec2\Ec2Client'));

        $this->finder
            ->shouldReceive('__invoke')
            ->andReturn([
                ['instance1'],
                ['instance2']
            ]);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer($this->logger, $this->authenticator, $this->finder, $this->pusher);

        $actual = $deployer($properties);
        $this->assertSame(303, $actual);
    }
}
