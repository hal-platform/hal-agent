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
        $this->loop(9, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('owner', 'group', 'container-name-2');

        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/unix']);
        $this->expectDockerCommandStatus(0);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name-2"', "bash -l -c 'command'"]);
        $this->expectDockerCommandStatus(0);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFailAtSanityCheck()
    {
        $this->expectCommand('builduser', 'buildserver', ['test -d', '"/docker-images/unix"', '&&', 'test -f', '"/docker-images/unix/Dockerfile"']);
        $this->expectCommandStatus(1);

        $this->buildRemoter
            ->shouldReceive('run')
            ->never();

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtBuildInfo()
    {
        $this->expectUntestedCommand();
        $this->expectCommandStatus(0);

        $this->expectCommand('builduser', 'buildserver', ['docker info']);
        $this->expectCommandStatus(0);

        $this->expectCommand('builduser', 'buildserver', ['docker inspect', '--format="{{ .Id }}"', 'hal9000/unix']);
        $this->expectCommandStatus(0);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/unix']);
        $this->expectDockerCommandStatus(1);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtBuildImage()
    {
        $this->expectUntestedCommand();
        $this->expectCommandStatus(0);

        $this->expectCommand('builduser', 'buildserver', ['docker info']);
        $this->expectCommandStatus(0);

        $this->expectCommand('builduser', 'buildserver', ['docker inspect', '--format="{{ .Id }}"', 'hal9000/unix']);
        $this->expectCommandStatus(1);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/unix"', '"/docker-images/unix"']);
        $this->expectDockerCommandStatus(1);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtGetMeta()
    {
        $this->loop(3, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/unix']);
        $this->expectDockerCommandStatus(0);

        // meta
        $this->expectCommand('builduser', 'buildserver', ['ls -ldn', 'buildpath', '| awk \'{print $3}\'']);
        $this->expectCommandStatus(0);

        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(1)
            ->andReturn('builduser-owner');

        $this->expectCommand('builduser', 'buildserver', ['ls -ldn', 'buildpath', '| awk \'{print $4}\'']);
        $this->expectCommandStatus(1);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtStartContainer()
    {
        $this->loop(3 + 2, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/unix']);
        $this->expectDockerCommandStatus(0);

        // meta
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('builduser-owner', 'builduser-group');

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

        $this->expectCommand('builduser', 'buildserver', $expectedCommand);
        $this->expectCommandStatus(1);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], ['HAL_DERP' => 'testing']);
        $this->assertFalse($success);
    }

    public function testFailAtRunCommandAndCleanupRuns()
    {
        $this->loop(3 + 2 + 1, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/unix']);
        $this->expectDockerCommandStatus(0);

        // meta, start
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('builduser-owner', 'builduser-group', 'container-name');

        // run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name"', "bash -l -c 'command'"]);
        $this->expectDockerCommandStatus(1);

        // cleanup
        $this->expectCommand('builduser', 'buildserver', ['docker exec', '"container-name"', "bash -l -c 'chown -R builduser-owner:builduser-group \"/build\"'"]);
        $this->expectCommandStatus(0);

        $this->expectCommand('builduser', 'buildserver', ['docker kill', '"container-name"']);
        $this->expectCommandStatus(0);

        $this->expectCommand('builduser', 'buildserver', ['docker rm', '"container-name"']);
        $this->expectCommandStatus(0);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command'], []);
        $this->assertFalse($success);
    }

    public function testMultipleCommandsAreRun()
    {
        $this->loop(3 + 2 + 1, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/unix']);
        $this->expectDockerCommandStatus(0);

        // meta, start
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('builduser-owner', 'builduser-group', 'container-name');

        // run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name"', "bash -l -c 'command1'"]);
        $this->expectDockerCommandStatus(0);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name"', "bash -l -c 'command2'"]);
        $this->expectDockerCommandStatus(0);

        // cleanup
        $this->loop(3, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('unix', 'builduser', 'buildserver', 'buildpath', ['command1', 'command2'], []);

        $this->assertSame(true, $success);
    }

    public function testMultipleCommandsAreRunInMultipleContainers()
    {
        $userCommands = [
            'command1',                                 # container 1
            'docker:node5 command2',                    # container 2
            'docker:image_owner/imgname:tag command3',  # container 3
            'docker:image_owner/imgname:tag command4',  # container 3
            'command5',                                 # container 4
        ];

        // container 1: sanity, build, start
        $this->loop(3 + 2 + 1, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // container 1: build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/legacy']);
        $this->expectDockerCommandStatus(0);

        // container 1: run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name1"', "bash -l -c 'command1'"]);
        $this->expectDockerCommandStatus(0);

        // container 2: sanity, build, start
        $this->loop(3 + 2 + 1, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // container 2: build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/node5']);
        $this->expectDockerCommandStatus(0);

        // container 2: run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name2"', "bash -l -c 'command2'"]);
        $this->expectDockerCommandStatus(0);

        // container 3: sanity, build, start
        $this->loop(3 + 2 + 1, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // container 3: build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/image_owner/imgname:tag']);
        $this->expectDockerCommandStatus(0);

        // container 3: run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name3"', "bash -l -c 'command3'"]);
        $this->expectDockerCommandStatus(0);

        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name3"', "bash -l -c 'command4'"]);
        $this->expectDockerCommandStatus(0);

        // container 4: sanity, build, start
        $this->loop(3 + 2 + 1, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // container 4: build
        $this->expectDockerCommand('builduser', 'buildserver', ['docker history', '--no-trunc', 'hal9000/legacy']);
        $this->expectDockerCommandStatus(0);

        // container 4: run
        $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name4"', "bash -l -c 'command5'"]);
        $this->expectDockerCommandStatus(0);

        // outputs
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3 * 4)
            ->andReturn(
                'builduser-owner1', 'builduser-group1', 'container-name1',
                'builduser-owner2', 'builduser-group2', 'container-name2',
                'builduser-owner3', 'builduser-group3', 'container-name3',
                'builduser-owner4', 'builduser-group4', 'container-name4'
            );

        // cleanup
        $this->loop(3 * 4, function() {
            $this->expectUntestedCommand();
            $this->expectCommandStatus(0);
        });

        // run
        // $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/legacy"', '"/docker-images/legacy"']);
        // $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name1"', "bash -l -c 'command1'"]);

        // $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/node5"', '"/docker-images/node5"']);
        // $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name2"', "bash -l -c 'command2'"]);

        // $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/image_owner/imgname:tag"', '"/docker-images/image_owner/imgname:tag"']);
        // $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name3"', "bash -l -c 'command3'"]);
        // $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name3"', "bash -l -c 'command4'"]);

        // $this->expectDockerCommand('builduser', 'buildserver', ['docker build', '--tag="hal9000/legacy"', '"/docker-images/legacy"']);
        // $this->expectDockerCommand('builduser', 'buildserver', ['docker exec "container-name4"', "bash -l -c 'command5'"]);

        // $this->buildRemoter
        //     ->shouldReceive('run')
        //     ->times(2 * 4 + 1)
        //     ->andReturn(true);

        $action = new DockerBuilder($this->logger, $this->remoter, $this->buildRemoter, $this->dockerSourcesPath);
        $action->disableShutdownHandler();

        $success = $action('legacy', 'builduser', 'buildserver', 'buildpath', $userCommands, []);

        $this->assertSame(true, $success);
    }

    private function expectUntestedCommand()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->andReturn($this->command)
            ->ordered();
    }

    private function expectCommand($user, $server, $command)
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with($user, $server, $command)
            ->andReturn($this->command)
            ->ordered();
    }

    private function expectCommandStatus($exitCode)
    {
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(1)
            ->andReturn($exitCode === 0 ? true : false);
    }

    // When a string is passed for command, only the first argument is matched
    // e.g. you want to match the docker command run, but dont care about the args
    private function expectDockerCommand($user, $server, $command)
    {
        if (is_string($command)) {
            $dockerCommand = $command;
            $command = Mockery::on(function($v) use ($dockerCommand) {
                return ($v[0] === $dockerCommand);
            });
        }

        $this->buildRemoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with($user, $server, $command)
            ->andReturn($this->buildCommand)
            ->ordered();
    }

    private function expectDockerCommandStatus($exitCode)
    {
        $this->buildRemoter
            ->shouldReceive('run')
            ->times(1)
            ->andReturn($exitCode === 0 ? true : false);
    }

    private function loop($times, callable $func)
    {
        while ($times-- > 0) {
            $func();
        }
    }
}
