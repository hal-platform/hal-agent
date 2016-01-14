<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Unix;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\CommandContext;
use QL\Hal\Agent\Remoting\SSHProcess;

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
        $this->logger = Mockery::mock(EventLogger::class);
        $this->remoter = Mockery::mock(SSHProcess::class);
        $this->buildRemoter = Mockery::mock(SSHProcess::class);

        $this->command = Mockery::mock(CommandContext::class);

        $this->buildCommand = Mockery::mock(CommandContext::class);
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
        $userCommands = [
            'command1',
            'command2'
        ];

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

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', $userCommands, []);

        $this->assertSame(true, $success);
    }

    public function testMultipleCommandsAreRunInMultipleContainers()
    {
        $userCommands = [
            'command1',
            'docker:node5 command2',
            'docker:image_owner/imgname:tag command3',
            'docker:image_owner/imgname:tag command4',
            'command5',
        ];

        // per container: 2 - sanity check, 2 - meta, 1 - start, 3 cleanup
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(8 * 4)
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(8 * 4)
            ->andReturn(true);
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3 * 4)
            ->andReturn(
                'builduser-owner1', 'builduser-group1', 'container-name1',
                'builduser-owner2', 'builduser-group2', 'container-name2',
                'builduser-owner3', 'builduser-group3', 'container-name3',
                'builduser-owner4', 'builduser-group4', 'container-name4'
            );

        // run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/legacy"', '"/docker-images/legacy"']);
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name1"', "bash -l -c 'command1'"]);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/node5"', '"/docker-images/node5"']);
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name2"', "bash -l -c 'command2'"]);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/image_owner/imgname:tag"', '"/docker-images/image_owner/imgname:tag"']);
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name3"', "bash -l -c 'command3'"]);
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name3"', "bash -l -c 'command4'"]);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/legacy"', '"/docker-images/legacy"']);
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name4"', "bash -l -c 'command5'"]);

        $this->buildRemoter
            ->shouldReceive('run')
            ->times(2 * 4 + 1)
            ->andReturn(true);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('legacy', 'builduser', 'buildserver', 'buildpath', $userCommands, []);

        $this->assertSame(true, $success);
    }

    private function expectDockerCommand($user, $server, $command)
    {
        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with($user, $server, $command)
            ->andReturn($this->buildCommand)
            ->ordered();
    }
}
