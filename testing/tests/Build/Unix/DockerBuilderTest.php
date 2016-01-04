<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Unix;

use Mockery;
use PHPUnit_Framework_TestCase;

class DockerBuilderTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $remoter;
    public $buildRemoter;
    public $command;
    public $buildCommand;
    public $dockerSourcesPath;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->remoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
        $this->buildRemoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');

        $this->command = Mockery::mock('QL\Hal\Agent\Remoting\CommandContext');

        $this->buildCommand = Mockery::mock('QL\Hal\Agent\Remoting\CommandContext');
        $this->buildCommand
            ->shouldReceive('withSanitized')
            ->andReturn($this->buildCommand)
            ->byDefault();

        $this->dockerSourcesPath = '/docker-images';
    }

    public function testSuccess()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(8)
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(8)
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('owner', 'group', 'container-name');

        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(2)
            ->andReturn($this->buildCommand);
        $this->buildRemoter
            ->shouldReceive('run')
            ->times(2)
            ->andReturn(true);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFailAtSanityCheck()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->andReturn($this->command);

        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(1)
            ->andReturn(false);

        $this->buildRemoter
            ->shouldReceive('run')
            ->never();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtBuildImage()
    {
        $expectedCommand = ['docker build', '--tag="hal9000/unix"', '"/docker-images/unix"'];

        $this->remoter
            ->shouldReceive('createCommand')
            ->times(2)
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(2)
            ->andReturn(true);

        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand)
            ->andReturn($this->buildCommand);
        $this->buildRemoter
            ->shouldReceive('run')
            ->times(1)
            ->andReturn(false);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtGetMeta()
    {
        $expectedCommand1 = ['ls -ldn', 'buildpath', '| awk \'{print $3}\''];
        $expectedCommand2 = ['ls -ldn', 'buildpath', '| awk \'{print $4}\''];

        $this->remoter
            ->shouldReceive('createCommand')
            ->times(2)
            ->andReturn($this->command)
            ->ordered();
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->andReturn(true, true, true, false);

        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->andReturn($this->buildCommand);
        $this->buildRemoter
            ->shouldReceive('run')
            ->andReturn(true);

        // meta
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('builduser', 'buildserver', $expectedCommand1)
            ->andReturn($this->command)
            ->ordered();
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('builduser', 'buildserver', $expectedCommand2)
            ->andReturn($this->command)
            ->ordered();
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(1)
            ->andReturn('builduser-owner');


        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtStartContainer()
    {
        $expectedCommand = [
            'docker run',
            '--detach=true',
            '--tty=true',
            '--interactive=true',
            '--volume="buildpath:/build"',
            '--workdir="/build"',
            '--env HAL_DERP',
            'hal9000/unix',
            'bash -l'
        ];

        // 2 - sanity check, 2 - meta
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(4)
            ->andReturn($this->command)
            ->ordered();
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(5)
            ->andReturn(true, true, true, true, false);
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->andReturn($this->buildCommand);
        $this->buildRemoter
            ->shouldReceive('run')
            ->times(1)
            ->andReturn(true);

        // meta
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('builduser-owner', 'builduser-group');

        // start
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand)
            ->andReturn($this->command)
            ->ordered();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], ['HAL_DERP' => 'testing']);
        $this->assertFalse($success);
    }

    public function testFailAtRunCommand()
    {
        $expectedCommand = [
            'docker exec "container-name"',
            "bash -l -c 'command'"
        ];

        // 2 - sanity check, 2 - meta, 1 - start, 3 cleanup
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(8)
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(8)
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('builduser-owner', 'builduser-group', 'container-name');

        // build image
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->andReturn($this->buildCommand)
            ->ordered();

        // run
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand)
            ->andReturn($this->buildCommand)
            ->ordered();

        $this->buildRemoter
            ->shouldReceive('run')
            ->times(2)
            ->andReturn(true, false);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testMultipleCommandsAreRun()
    {
        $prefix = 'docker exec "container-name"';
        $expectedCommand1 = [$prefix, "bash -l -c 'command1'"];
        $expectedCommand2 = [$prefix, "bash -l -c 'command2'"];

        // 2 - sanity check, 2 - meta, 1 - start, 3 cleanup
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(8)
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(8)
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('builduser-owner', 'builduser-group', 'container-name');

        // build image
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->andReturn($this->buildCommand)
            ->ordered();

        // run
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand1)
            ->andReturn($this->buildCommand)
            ->ordered();
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('builduser', 'buildserver', $expectedCommand2)
            ->andReturn($this->buildCommand)
            ->ordered();

        $this->buildRemoter
            ->shouldReceive('run')
            ->times(3)
            ->andReturn(true);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command1', 'command2'], []);

        $this->assertSame(true, $success);
    }
}
