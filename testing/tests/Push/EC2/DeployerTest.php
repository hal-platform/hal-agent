<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\EC2;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $output;

    public $finder;
    public $pusher;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->output = new BufferedOutput;

        $this->finder = Mockery::mock('QL\Hal\Agent\Push\EC2\InstanceFinder');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\EC2\Pusher');
    }

    public function testSuccess()
    {
        $properties = [
            'ec2' => [
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

        $this->finder
            ->shouldReceive('__invoke')
            ->andReturn([
                ['instance1'],
                ['instance2']
            ]);

        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $deployer = new Deployer($this->logger, $this->finder, $this->pusher);

        $actual = $deployer($this->output, $properties);
        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Deploying push by EC2
Finding EC2 instances in pool
Pushing code to EC2 instances

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testPushCommandsAreLoggedAndSkipped()
    {
        $properties = [
            'ec2' => [
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

        $deployer = new Deployer($this->logger, $this->finder, $this->pusher);

        $actual = $deployer($this->output, $properties);
        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Deploying push by EC2
Finding EC2 instances in pool
Pushing code to EC2 instances

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSanityCheckFails()
    {
        $properties = [];

        $deployer = new Deployer($this->logger, $this->finder, $this->pusher);

        $actual = $deployer($this->output, $properties);
        $this->assertSame(300, $actual);
    }

    public function testNoInstanceFoundFails()
    {
        $properties = [
            'ec2' => [
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

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_NO_INSTANCES)
            ->once();

        $this->finder
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $deployer = new Deployer($this->logger, $this->finder, $this->pusher);

        $actual = $deployer($this->output, $properties);
        $this->assertSame(301, $actual);
    }

    public function testPushFails()
    {
        $properties = [
            'ec2' => [
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

        $this->finder
            ->shouldReceive('__invoke')
            ->andReturn([
                ['instance1'],
                ['instance2']
            ]);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $deployer = new Deployer($this->logger, $this->finder, $this->pusher);

        $actual = $deployer($this->output, $properties);
        $this->assertSame(302, $actual);
    }
}
