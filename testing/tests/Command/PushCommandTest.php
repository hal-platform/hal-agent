<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command;

use Exception;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
use QL\Hal\Core\Entity\Server;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $resolver;
    public $mover;
    public $unpacker;
    public $reader;
    public $deployer;

    public $filesystem;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Push\Resolver');
        $this->mover = Mockery::mock('QL\Hal\Agent\Push\Mover');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Push\Unpacker');
        $this->reader = Mockery::mock('QL\Hal\Agent\Build\ConfigurationReader');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\DelegatingBuilder');
        $this->deployer = Mockery::mock('QL\Hal\Agent\Push\DelegatingDeployer');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1'
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

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->deployer,
            $this->filesystem
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = [
            '[Starting Deployment] Resolving push properties',
            'Push details could not be resolved.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testSuccess()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1'
        ]);

        $push = Mockery::mock(Push::CLASS, [
            'status' => null,

            'withStatus' => null,
            'withStart' => null,
            'withEnd' => null,

            'application' => Mockery::mock(Repository::CLASS, [
                'name' => 'test_app'
            ]),
            'deployment' => Mockery::mock(Deployment::CLASS, [
                'server' => Mockery::mock(Server::CLASS, [
                    'environment' => Mockery::mock(Environment::CLASS, [
                        'name' => null
                    ]),
                    'name' => null
                ])
            ]),
            'id' => 1234,
            'build' => Mockery::mock(Build::CLASS, [
                'id' => 5678,
                'environment' => Mockery::mock(Environment::CLASS, [
                    'name' => 'test'
                ])
            ])
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
            ->twice();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'method' => 'rsync',

                'configuration' => [
                    'system' => 'unix',
                    'build_transform' => [
                        'cmd'
                    ]
                ],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'legacy_archive' => 'oldpath/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
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
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->deployer
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
            $this->builder,
            $this->deployer,
            $this->filesystem
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = [
            '[Starting Deployment] Resolving push properties',
            '[Starting Deployment] Application: test_app',
            '[Starting Deployment] Environment: test',
            '[Starting Deployment] Push: 1234',
            '[Starting Deployment] Build: 5678',
            '[Starting Deployment] Moving archive to local storage',
            '[Starting Deployment] Unpacking build archive',
            '[Starting Deployment] Reading .hal9000.yml',
            '[Building] Running build transform command',
            '[Deploying] Deploying application',
            'Success!'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testEmergencyErrorHandling()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1'
        ]);

        $push = Mockery::mock(Push::CLASS, [
            'status' => 'Pushing',

            'withStart' => null,

            'deployment' => Mockery::mock(Deployment::CLASS, [
                'server' => Mockery::mock(Server::CLASS, [
                    'environment' => Mockery::mock(Environment::CLASS, [
                        'name' => null
                    ]),
                    'name' => null
                ]),
                'application' => Mockery::mock(Repository::CLASS, [
                    'name' => null
                ]),
            ]),
            'id' => 1234
        ]);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
            ->never();
        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('setStage')
            ->once();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'method' => 'rsync',

                'configuration' => [],

                'location' => [
                    'path' => 'path/dir',
                    'archive' => 'path/file',
                    'legacy_archive' => 'oldpath/file',
                    'tempArchive' => 'path/file2',
                ],

                'pushProperties' => [],
                'artifacts' => []
            ]);

        $this->mover->shouldReceive(['__invoke' => true]);
        $this->unpacker->shouldReceive(['__invoke' => true]);
        $this->reader->shouldReceive(['__invoke' => true]);

        // simulate an error
        $this->deployer
            ->shouldReceive('__invoke')
            ->andThrow(new Exception);

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->deployer,
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
