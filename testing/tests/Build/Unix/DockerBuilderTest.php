<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\CommandContext;
use Hal\Agent\Remoting\SSHProcess;

class DockerBuilderTest extends MockeryTestCase
{
    public $logger;
    public $remoter;
    public $buildRemoter;
    public $transferRemoter;
    public $command;
    public $buildCommand;
    public $dockerSourcesPath;

    public $buildUser;
    public $buildServer;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->remoter = Mockery::mock(SSHProcess::class);
        $this->buildRemoter = Mockery::mock(SSHProcess::class);
        $this->transferRemoter = Mockery::mock(SSHProcess::class);

        $this->command = Mockery::mock(CommandContext::class);

        $this->buildCommand = Mockery::mock(CommandContext::class);
        $this->buildCommand
            ->shouldReceive('withSanitized')
            ->andReturn($this->buildCommand)
            ->byDefault();

        $this->dockerSourcesPath = '/docker-images';
        $this->buildUser = 'builduser';
        $this->buildServer = 'buildserver';
    }

    public function xtestSuccess()
    {
        $this->loop(9, function() {
            $this->expectUntestedCommand(0);
        });

        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(3)
            ->andReturn('owner', 'group', 'container-name-2');

        $this->expectDockerCommand(['docker history', '--no-trunc', 'hal9000/unix'], 0);

        $this->expectDockerCommand(['docker exec "container-name-2"', "bash -l -c 'command'"], 0);

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFailAtSanityCheck()
    {
        $this->expectCommand(['test -d', '"/docker-images/unix"', '&&', 'test -f', '"/docker-images/unix/Dockerfile"'], 1);

        $this->buildRemoter
            ->shouldReceive('run')
            ->never();

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtBuildInfo()
    {
        $this->expectUntestedCommand(0);

        $this->expectCommand(['docker info'], 0);

        $this->expectCommand(['docker inspect', '--format="{{ .Id }}"', 'hal9000/unix'], 0);

        $this->expectDockerCommand(['docker history', '--no-trunc', 'hal9000/unix'], 1);

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtBuildImage()
    {
        $this->expectUntestedCommand(0);

        $this->expectCommand('docker info', 0);

        $this->expectCommand(['docker inspect', '--format="{{ .Id }}"', 'hal9000/unix'], 1);

        $this->expectDockerCommand(['docker build', '--tag="hal9000/unix"', '"/docker-images/unix"'], 1);

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtStartContainer()
    {
        // sanity(2) + build(1)
        $this->loop(2 + 1, function() {
            $this->expectUntestedCommand(0);
        });

        // build
        $this->expectDockerCommand('docker history', 0);

        $expectedCommand = [
            'docker run',
            '--detach=true',
            '--tty=true',
            '--interactive=true',
            '--workdir="/build"',
            '--env HAL_DERP',
            'hal9000/unix',
            'bash -l'
        ];

        $this->expectCommand($expectedCommand, 1);

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], ['HAL_DERP' => 'testing']);
        $this->assertFalse($success);
    }

    public function testFailAtCopyIn()
    {
        // sanity(2) + build/start(2)
        $this->loop(2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // build
        $this->expectDockerCommand('docker history', 0);

        // start, meta
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('container-name', 'container-user');

        // get meta (user)
        $this->expectCommand(['docker inspect', '--format="{{ .Config.User }}"', 'container-name'], 0);

        // copy
        $this->expectCommand(['cat buildfile.tar.gz', '|', 'docker cp', '-', 'container-name:/build'], 1);

        // cleanup
        $this->expectCommand(['docker kill', '"container-name"']);
        $this->expectCommand(['docker rm', '"container-name"']);

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );
        $action->disableShutdownHandler();

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtChownWhenNonRoot()
    {
        // sanity(2) + build/start(2) + copy(2)
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // build
        $this->expectDockerCommand('docker history', 0);

        // start, meta
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('container2', 'container-user');

        // permissions
        $this->expectCommand(['docker exec', '--user root', '"container2"', 'chown -R container-user:container-user /build'], 1);

        // cleanup
        $this->expectCommand(['docker kill', '"container2"']);
        $this->expectCommand(['docker rm', '"container2"']);

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );
        $action->disableShutdownHandler();

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertFalse($success);
    }

    public function testFailAtRunCommandAndCleanupRuns()
    {
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // build
        $this->expectDockerCommand('docker history', 0);

        // start, meta
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('container-name', '0');

        // run
        $this->expectDockerCommand(['docker exec "container-name"', "bash -l -c 'command'"], 1);

        // cleanup
        $this->expectCommand('docker kill');
        $this->expectCommand('docker rm');

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );
        $action->disableShutdownHandler();

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command'], []);
        $this->assertFalse($success);
    }

    public function testSuccessOnMultipleCommandsAreRun()
    {
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // build
        $this->expectDockerCommand('docker history', 0);

        // start, meta
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2)
            ->andReturn('container3', 'root');

        // run
        $this->expectDockerCommand(['docker exec "container3"', "bash -l -c 'command1'"], 0);

        $this->expectDockerCommand(['docker exec "container3"', "bash -l -c 'command2'"], 0);

        // copy out
        $this->expectCommand(['docker cp', 'container3:/build/.', '-', '|', 'gzip', '>', 'buildfile.tar.gz'], 0);

        // cleanup

        $this->expectCommand('docker kill');
        $this->expectCommand('docker rm');

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );
        $action->disableShutdownHandler();

        $success = $action('unix', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', ['command1', 'command2'], []);

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

        // container 1: sanity(2) + build/start(2) + copy(2)
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // container 1: build, permissions, run, copy out, cleanup
        $this->expectDockerCommand(['docker history', '--no-trunc', 'hal9000/legacy'], 0);
        $this->expectCommand(['docker exec', '--user root', '"container1"', 'chown -R cuser1:cuser1 /build'], 0);

        $this->expectDockerCommand(['docker exec "container1"', "bash -l -c 'command1'"], 0);

        $this->expectCommand('docker cp', 0);
        $this->expectCommand('docker kill');
        $this->expectCommand('docker rm');

        // container 2: sanity, build, start
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // container 2: build, permissions, run, copy out, cleanup
        $this->expectDockerCommand(['docker history', '--no-trunc', 'hal9000/node5'], 0);
        $this->expectCommand(['docker exec', '--user root', '"container2"', 'chown -R cuser2:cuser2 /build'], 0);

        $this->expectDockerCommand(['docker exec "container2"', "bash -l -c 'command2'"], 0);

        $this->expectCommand('docker cp', 0);
        $this->expectCommand('docker kill');
        $this->expectCommand('docker rm');

        // container 3: sanity, build, start
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // container 3: build, permissions, run, copy out, cleanup
        $this->expectDockerCommand(['docker history', '--no-trunc', 'hal9000/image_owner/imgname:tag'], 0);
        $this->expectCommand(['docker exec', '--user root', '"container3"', 'chown -R cuser3:cuser3 /build'], 0);

        $this->expectDockerCommand(['docker exec "container3"', "bash -l -c 'command3'"], 0);
        $this->expectDockerCommand(['docker exec "container3"', "bash -l -c 'command4'"], 0);

        $this->expectCommand('docker cp', 0);
        $this->expectCommand('docker kill');
        $this->expectCommand('docker rm');

        // container 4: sanity, build, start
        $this->loop(2 + 2 + 2, function() {
            $this->expectUntestedCommand(0);
        });

        // container 4: build, permissions, run, copy out, cleanup
        $this->expectDockerCommand(['docker history', '--no-trunc', 'hal9000/legacy'], 0);
        $this->expectCommand(['docker exec', '--user root', '"container4"', 'chown -R cuser4:cuser4 /build'], 0);

        $this->expectDockerCommand(['docker exec "container4"', "bash -l -c 'command5'"], 0);

        $this->expectCommand('docker cp', 0);
        $this->expectCommand('docker kill');
        $this->expectCommand('docker rm');

        // outputs
        $this->remoter
            ->shouldReceive('getLastOutput')
            ->times(2 * 4)
            ->andReturn(
                'container1', 'cuser1',
                'container2', 'cuser2',
                'container3', 'cuser3',
                'container4', 'cuser4'
            );

        $action = new DockerBuilder(
            $this->logger,
            $this->remoter,
            $this->buildRemoter,
            $this->transferRemoter,
            $this->dockerSourcesPath
        );
        $action->disableShutdownHandler();

        $success = $action('legacy', $this->buildUser, $this->buildServer, 'buildfile.tar.gz', $userCommands, []);

        $this->assertSame(true, $success);
    }

    private function expectUntestedCommand($exitCode)
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->andReturn($this->command)
            ->ordered();

        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(1)
            ->andReturn($exitCode === 0 ? true : false);
    }

    private function expectCommand($command, $exitCode = 0)
    {
        // When a string is passed for command, only the first argument is matched
        // e.g. you want to match the docker command run, but dont care about the args
        if (is_string($command)) {
            $expectCommand = $command;
            $command = Mockery::on(function($v) use ($expectCommand) {
                // echo "\nEXPECT: ".str_pad($expectCommand, 20)." ------- GOT: $v[0] - ".json_encode($v);
                // $d = ($v[0] === $expectCommand);

                // if ($d) echo " FOUND\n";
                // return $d;
                return ($v[0] === $expectCommand);
            });
        }

        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with($this->buildUser, $this->buildServer, $command)
            ->andReturn($this->command)
            ->ordered();

        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->times(1)
            ->andReturn($exitCode === 0 ? true : false);
    }

    // When a string is passed for command, only the first argument is matched
    // e.g. you want to match the docker command run, but dont care about the args
    private function expectDockerCommand($command, $exitCode = 0)
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
            ->with($this->buildUser, $this->buildServer, $command)
            ->andReturn($this->buildCommand)
            ->ordered();

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
