<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Exception;
use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $resolver;
    public $unpacker;
    public $builder;
    public $pusher;
    public $command;
    public $processBuilder;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Push\Resolver');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Push\Unpacker');
        $this->builder = Mockery::mock('QL\Hal\Agent\Push\Builder');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\Pusher');
        $this->command = Mockery::mock('QL\Hal\Agent\Push\ServerCommand');
        $this->processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1',
            'METHOD' => 'rsync'
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturnNull();

        $this->logger
            ->shouldReceive('failure')
            ->twice();
        $this->logger
            ->shouldReceive('setStage')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->unpacker,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->processBuilder
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Push details could not be resolved.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSuccess()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1',
            'METHOD' => 'rsync'
        ]);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'getStatus' => null,
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getDeployment' => Mockery::mock('QL\Hal\Core\Entity\Deployment', [
                'getServer' => Mockery::mock('QL\Hal\Core\Entity\Server', [
                    'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                        'getKey' => null
                    ]),
                    'getName' => null
                ]),
                'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                    'getKey' => null
                ])
            ]),
            'getId' => 1234
        ]);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->twice();
        $this->logger
            ->shouldReceive('setStage')
            ->times(3);
        $this->logger
            ->shouldReceive('addSubscription')
            ->with('push.failure', 'notifier.email')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
            ->with('push.success', 'notifier.email')
            ->once();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'buildPath' => 'path/dir',
                'archiveFile' => 'path/file',
                'pushProperties' => [],
                'buildCommand' => 'bin/build-cmd',
                'prePushCommand' => 'bin/cmd',
                'postPushCommand' => 'bin/cmd',
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'serverEnvironmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'excludedFiles' => [],
                'artifacts' => [
                    'path/dir'
                ]
            ]);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->processBuilder
            ->shouldReceive('getProcess->run')
            ->once();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->unpacker,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->processBuilder
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Unpacking build archive
Running build command
Running pre-push command
Pushing code to server
Running post-push command
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSuccessSkippingSubCommands()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1',
            'METHOD' => 'rsync'
        ]);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'getStatus' => null,
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getDeployment' => Mockery::mock('QL\Hal\Core\Entity\Deployment', [
                'getServer' => Mockery::mock('QL\Hal\Core\Entity\Server', [
                    'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                        'getKey' => null
                    ]),
                    'getName' => null
                ]),
                'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                    'getKey' => null
                ])
            ]),
            'getId' => 1234
        ]);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->twice();
        $this->logger
            ->shouldReceive('setStage')
            ->times(3);
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'buildPath' => 'path/dir',
                'archiveFile' => 'path/file',
                'pushProperties' => [],
                'buildCommand' => '',
                'prePushCommand' => '',
                'postPushCommand' => '',
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'serverEnvironmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'excludedFiles' => [],
                'artifacts' => [
                    'path/dir'
                ]
            ]);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->processBuilder
            ->shouldReceive('getProcess->run')
            ->once();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->unpacker,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->processBuilder
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Unpacking build archive
Skipping build command
Skipping pre-push command
Pushing code to server
Skipping post-push command
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }


    public function testEmergencyErrorHandling()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1',
            'METHOD' => 'rsync'
        ]);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'getStatus' => 'Pushing',
            'setStart' => null,
            'getDeployment' => Mockery::mock('QL\Hal\Core\Entity\Deployment', [
                'getServer' => Mockery::mock('QL\Hal\Core\Entity\Server', [
                    'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                        'getKey' => null
                    ]),
                    'getName' => null
                ]),
                'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                    'getKey' => null
                ])
            ]),
            'getId' => 1234
        ]);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->twice();
        $this->logger
            ->shouldReceive('setStage')
            ->times(3);
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'buildPath' => 'path/dir',
                'archiveFile' => 'path/file',
                'pushProperties' => [],
                'buildCommand' => '',
                'prePushCommand' => '',
                'postPushCommand' => '',
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'serverEnvironmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'excludedFiles' => [],
                'artifacts' => []
            ]);

        $this->unpacker->shouldReceive(['__invoke' => true]);
        $this->builder->shouldReceive(['__invoke' => true]);
        // simulate an error
        $this->command
            ->shouldReceive('__invoke')
            ->andThrow(new Exception);
        $this->pusher->shouldReceive(['__invoke' => true]);

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->unpacker,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->processBuilder
        );

        try {
            $command->disableShutdownHandler();
            $command->run($this->input, $this->output);
        } catch (Exception $e) {}

        // this will call __destruct
        unset($command);
    }

}
