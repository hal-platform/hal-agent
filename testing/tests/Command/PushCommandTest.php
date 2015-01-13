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
    public $mover;
    public $unpacker;
    public $reader;
    public $builder;
    public $pusher;
    public $command;

    public $filesystem;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Push\Resolver');
        $this->mover = Mockery::mock('QL\Hal\Agent\Push\Mover');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Push\Unpacker');
        $this->reader = Mockery::mock('QL\Hal\Agent\Push\ConfigurationReader');
        $this->delta = Mockery::mock('QL\Hal\Agent\Push\CodeDelta');
        $this->builder = Mockery::mock('QL\Hal\Agent\Push\Builder');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\Pusher');
        $this->command = Mockery::mock('QL\Hal\Agent\Push\ServerCommand');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');

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
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->delta,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->filesystem
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
            ->once();
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

                'configuration' => [
                    'system' => 'global',
                    'build' => [],
                    'build_transform' => [
                        'bin/cmd'
                    ],
                    'pre_push' => [
                        'bin/cmd'
                    ],
                    'post_push' => [
                        'bin/cmd'
                    ],
                    'dist' => '.',
                    'exclude' => [
                        'config/database.ini',
                        'data/'
                    ]
                ],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'serverEnvironmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'artifacts' => [
                    'path/dir',
                    'path/file2'
                ]
            ]);
        $this->mover
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->reader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->delta
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
        $this->filesystem
            ->shouldReceive('remove')
            ->twice();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->delta,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->filesystem
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Found push: 1234
Moving archive to local storage
Unpacking build archive
Reading .hal9000.yml
Reading previous push data
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
            ->once();
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

                'configuration' => [
                    'system' => 'global',
                    'build' => [],
                    'build_transform' => [],
                    'pre_push' => [],
                    'post_push' => [],
                    'dist' => '.',
                    'exclude' => []
                ],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'serverEnvironmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'artifacts' => [
                    'path/dir',
                    'path/file2'
                ]
            ]);
        $this->mover
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->reader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->delta
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
        $this->filesystem
            ->shouldReceive('remove')
            ->twice();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->delta,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->filesystem
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving push properties
Found push: 1234
Moving archive to local storage
Unpacking build archive
Reading .hal9000.yml
Reading previous push data
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
            ->once();
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

                'configuration' => [
                    'system' => 'global',
                    'build' => [],
                    'build_transform' => [],
                    'pre_push' => [],
                    'post_push' => [],
                    'dist' => '.',
                    'exclude' => []
                ],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'serverEnvironmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'artifacts' => []
            ]);

        $this->mover->shouldReceive(['__invoke' => true]);
        $this->unpacker->shouldReceive(['__invoke' => true]);
        $this->reader->shouldReceive(['__invoke' => true]);
        $this->delta->shouldReceive(['__invoke' => true]);
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
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->delta,
            $this->builder,
            $this->pusher,
            $this->command,
            $this->filesystem
        );

        try {
            $command->disableShutdownHandler();
            $command->run($this->input, $this->output);
        } catch (Exception $e) {}

        // this will call __destruct
        unset($command);
    }

}
