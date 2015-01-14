<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends PHPUnit_Framework_TestCase
{
    public $output;
    public $logger;

    public $delta;
    public $builder;
    public $command;
    public $pusher;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');

        $this->delta = Mockery::mock('QL\Hal\Agent\Push\Rsync\CodeDelta');
        $this->builder = Mockery::mock('QL\Hal\Agent\Push\Builder');
        $this->command = Mockery::mock('QL\Hal\Agent\Push\Rsync\ServerCommand');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\Rsync\Pusher');
    }

    public function testSuccess()
    {
        $properties = [
            'rsync' => [
                'hostname' => '',
                'remotePath' => '',
                'syncPath' => '',
                'environmentVariables' => []
            ],
            'pushProperties' => [],
            'configuration' => [
                'system' => '',
                'build_transform' => ['cmd1'],
                'pre_push' => ['cmd2'],
                'post_push' => ['cmd3', 'cmd4'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'environmentVariables' => []
        ];

        $this->logger
            ->shouldReceive('setStage')
            ->with('pushing')
            ->once();

        $this->delta
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();

        // non-essential commands
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->twice();

        $deployer = new Deployer(
            $this->logger,
            $this->delta,
            $this->builder,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($this->output, $properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
Deploying push by rsync
Reading previous push data
Running build command
Running pre-push command
Pushing code to server
Running post-push command

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailRsyncSanityCheck()
    {
        $properties = [];
        $deployer = new Deployer(
            $this->logger,
            $this->delta,
            $this->builder,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($this->output, $properties);

        $this->assertSame(100, $actual);

        $expected = <<<'OUTPUT'
Deploying push by rsync

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailBuild()
    {
        $properties = [
            'rsync' => [
                'hostname' => '',
                'remotePath' => '',
                'syncPath' => '',
            ],
            'configuration' => [
                'system' => '',
                'build_transform' => ['cmd1'],
                'pre_push' => ['cmd2'],
                'post_push' => ['cmd3', 'cmd4'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'pushProperties' => [],
            'environmentVariables' => []
        ];

        $this->delta
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->delta,
            $this->builder,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($this->output, $properties);
        $this->assertSame(101, $actual);
    }

    public function testFailPrePush()
    {
        $properties = [
            'rsync' => [
                'hostname' => '',
                'remotePath' => '',
                'syncPath' => '',
                'environmentVariables' => [],
            ],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => ['cmd2'],
                'post_push' => ['cmd3', 'cmd4'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'pushProperties' => [],
            'environmentVariables' => []
        ];

        $this->logger
            ->shouldReceive('setStage')
            ->with('pushing')
            ->once();
        $this->delta
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->delta,
            $this->builder,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($this->output, $properties);
        $this->assertSame(102, $actual);
    }

    public function testFailPush()
    {
        $properties = [
            'rsync' => [
                'hostname' => '',
                'remotePath' => '',
                'syncPath' => '',
                'environmentVariables' => [],
            ],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => [],
                'post_push' => ['cmd3', 'cmd4'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'pushProperties' => [],
            'environmentVariables' => []
        ];

        $this->logger
            ->shouldReceive('setStage')
            ->with('pushing')
            ->once();
        $this->delta
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->delta,
            $this->builder,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($this->output, $properties);
        $this->assertSame(103, $actual);
    }

    public function testFailPostPush()
    {
        $properties = [
            'rsync' => [
                'hostname' => '',
                'remotePath' => '',
                'syncPath' => '',
                'environmentVariables' => [],
            ],
            'configuration' => [
                'system' => '',
                'build_transform' => [],
                'pre_push' => [],
                'post_push' => ['cmd3', 'cmd4'],
                'exclude' => [],
            ],
            'location' => [
                'path' => ''
            ],
            'pushProperties' => [],
            'environmentVariables' => []
        ];

        $this->logger
            ->shouldReceive('setStage')
            ->with('pushing')
            ->once();
        $this->delta
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->once();
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(false)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->delta,
            $this->builder,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($this->output, $properties);
        $this->assertSame(104, $actual);
    }
}
