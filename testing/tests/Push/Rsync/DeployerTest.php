<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Rsync;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class DeployerTest extends MockeryTestCase
{
    public $output;
    public $logger;

    public $verify;
    public $delta;
    public $command;
    public $pusher;

    public function setUp()
    {
        $this->output = new BufferedOutput;
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');

        $this->verify = Mockery::mock('Hal\Agent\Push\Rsync\Verify');
        $this->delta = Mockery::mock('Hal\Agent\Push\Rsync\CodeDelta');
        $this->command = Mockery::mock('Hal\Agent\Push\Rsync\ServerCommand');
        $this->pusher = Mockery::mock('Hal\Agent\Push\Rsync\Pusher');
    }

    public function testSuccess()
    {
        $properties = [
            'rsync' => [
                'remoteUser' => 'sshuser',
                'remoteServer' => 'webserver',
                'remotePath' => '/var/www',
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

        $this->verify
            ->shouldReceive('__invoke')
            ->andReturn(true)
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
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(true)
            ->twice();

        $deployer = new Deployer(
            $this->logger,
            $this->verify,
            $this->delta,
            $this->command,
            $this->pusher
        );
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);

        $this->assertSame(0, $actual);

        $expected = <<<'OUTPUT'
[Deploying - Rsync] Deploying push by rsync
[Deploying - Rsync] Verifying RSync configuration
[Deploying - Rsync] Verifying target directory
[Deploying - Rsync] Reading previous push data
[Deploying - Rsync] Running pre-push command
[Deploying - Rsync] Pushing code to server
[Deploying - Rsync] Running post-push command

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailRsyncSanityCheck()
    {
        $properties = [];

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Deployer::ERR_INVALID_DEPLOYMENT_SYSTEM)
            ->once();

        $deployer = new Deployer(
            $this->logger,
            $this->verify,
            $this->delta,
            $this->command,
            $this->pusher
        );
        $deployer->setOutput($this->output);

        $actual = $deployer($properties);

        $this->assertSame(100, $actual);

        $expected = <<<'OUTPUT'
[Deploying - Rsync] Deploying push by rsync

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testFailVerify()
    {
        $properties = [
            'rsync' => [
                'remoteUser' => 'sshuser',
                'remoteServer' => 'webserver',
                'remotePath' => '/var/www',
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

        $this->verify
            ->shouldReceive('__invoke')
            ->with('sshuser', 'webserver', '/var/www')
            ->andReturn(false)
            ->once();
        $this->delta
            ->shouldReceive('__invoke')
            ->never();

        $deployer = new Deployer(
            $this->logger,
            $this->verify,
            $this->delta,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(101, $actual);
    }

    public function testFailPrePush()
    {
        $properties = [
            'rsync' => [
                'remoteUser' => 'sshuser',
                'remoteServer' => 'webserver',
                'remotePath' => '/var/www',
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

        $this->verify
            ->shouldReceive('__invoke')
            ->andReturn(true)
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
            $this->verify,
            $this->delta,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(102, $actual);
    }

    public function testFailPush()
    {
        $properties = [
            'rsync' => [
                'remoteUser' => 'sshuser',
                'remoteServer' => 'webserver',
                'remotePath' => '/var/www',
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

        $this->verify
            ->shouldReceive('__invoke')
            ->andReturn(true)
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
            $this->verify,
            $this->delta,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(103, $actual);
    }

    public function testFailPostPush()
    {
        $properties = [
            'rsync' => [
                'remoteUser' => 'sshuser',
                'remoteServer' => 'webserver',
                'remotePath' => '/var/www',
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

        $this->verify
            ->shouldReceive('__invoke')
            ->andReturn(true)
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
            $this->verify,
            $this->delta,
            $this->command,
            $this->pusher
        );

        $actual = $deployer($properties);
        $this->assertSame(104, $actual);
    }
}
