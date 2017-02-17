<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Exception;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\ConfigurationReader;
use Hal\Agent\Build\DelegatingBuilder;
use Hal\Agent\Push\DelegatingDeployer;
use Hal\Agent\Push\Mover;
use Hal\Agent\Push\Resolver;
use Hal\Agent\Push\Unpacker;
use Hal\Agent\Testing\ExecutorTestCase;
use Mockery;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
use QL\Hal\Core\Entity\Server;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

class DeployCommandTest extends ExecutorTestCase
{
    public $logger;
    public $resolver;
    public $mover;
    public $unpacker;
    public $reader;
    public $deployer;

    public $filesystem;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->resolver = Mockery::mock(Resolver::class);
        $this->mover = Mockery::mock(Mover::class);
        $this->unpacker = Mockery::mock(Unpacker::class);
        $this->reader = Mockery::mock(ConfigurationReader::class);
        $this->builder = Mockery::mock(DelegatingBuilder::class);
        $this->deployer = Mockery::mock(DelegatingDeployer::class);

        $this->filesystem = Mockery::mock(Filesystem::class);
    }

    public function configureCommand($c)
    {
        DeployCommand::configure($c);
    }

    public function testBuildResolvingFails()
    {
        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturnNull();

        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('setStage')
            ->once();

        $command = new DeployCommand(
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

        $io = $this->io('configureCommand', [
            'RELEASE_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Runner - Deploy release',
            '[1/6] Resolving configuration',
            '[ERROR] Release cannot be run.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testSuccess()
    {
        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('success')
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
                'push' => $this->generateMockPush(),
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
            ->andReturn([
                'system' => 'ok',
                'build_transform' => [],
                'deploy' => ['command1 --flag', 'path/to/command2 arg1'],
            ]);
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

        $command = new DeployCommand(
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

        $io = $this->io('configureCommand', [
            'RELEASE_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[1/6] Resolving configuration',
            ' * Push: 1234',
            ' * Build: 5678',
            ' * Application: test_app (ID: app123)',
            ' * Environment: test (ID: env123)',

            '[2/6] Importing build artifact',
            ' * Artifact file: path/file',
            ' * Target: path/file2',

            '[3/6] Unpacking build artifact',
            ' * Source file: path/file2',
            ' * Release directory: path/dir',

            '[4/6] Reading .hal.yml configuration',
            ' * Application configuration:',

            '[5/6] Running build transform process',
            'No build transform commands found. Skipping transform process.',

            '[6/6] Running build deployment process',
            ' * Method: rsync',

            'Deployment clean-up',
            'Deployment artifacts to remove:',
            ' * path/dir',
            ' * path/file2',

            '[OK] Release was deployed successfully.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testEmergencyErrorHandling()
    {
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
        $this->logger
            ->shouldReceive('event')
            ->once();

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $this->generateMockPush(),
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
            ->andThrow(new RuntimeException);

        $command = new DeployCommand(
            $this->logger,
            $this->resolver,
            $this->mover,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->deployer,
            $this->filesystem
        );

        $isAborted = false;
        try {
            $io = $this->io('configureCommand', [
                'RELEASE_ID' => '1'
            ]);

            $command->execute($io);

        } catch (RuntimeException $e) {
            $isAborted = true;
        }

        $this->assertSame(true, $isAborted);

        $command->emergencyCleanup();
    }

    public function generateMockPush()
    {
        return Mockery::mock(Push::class, [
            'status' => null,

            'application' => Mockery::mock(Repository::class, [
                'id' => 'app123',
                'name' => 'test_app'
            ]),
            'deployment' => Mockery::mock(Deployment::class, [
                'server' => Mockery::mock(Server::class, [
                    'environment' => Mockery::mock(Environment::class, [
                        'name' => null
                    ]),
                    'name' => null
                ])
            ]),
            'id' => 1234,
            'build' => Mockery::mock(Build::class, [
                'id' => 5678,
                'environment' => Mockery::mock(Environment::class, [
                    'id' => 'env123',
                    'name' => 'test'
                ])
            ])
        ]);
    }
}
