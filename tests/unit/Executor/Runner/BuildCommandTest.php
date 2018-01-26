<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Build\Resolver;
use Hal\Agent\Build\Downloader;
use Hal\Agent\Build\Unpacker;
use Hal\Agent\Build\ConfigurationReader;
use Hal\Agent\Build\DelegatingBuilder;
use Hal\Agent\Build\Packer;
use Hal\Agent\Build\Mover;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHSessionManager;
use Hal\Agent\Testing\ExecutorTestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use RuntimeException;
use Symfony\Component\FileSystem\Filesystem;

class BuildCommandTest extends ExecutorTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $resolver;
    public $downloader;
    public $unpacker;
    public $reader;
    public $builder;
    public $packer;
    public $mover;
    public $filesystem;
    public $ssh;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->resolver = Mockery::mock(Resolver::class);
        $this->downloader = Mockery::mock(Downloader::class);
        $this->unpacker = Mockery::mock(Unpacker::class);
        $this->reader = Mockery::mock(ConfigurationReader::class);
        $this->builder = Mockery::mock(DelegatingBuilder::class);
        $this->packer = Mockery::mock(Packer::class);
        $this->mover = Mockery::mock(Mover::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->ssh = Mockery::mock(SSHSessionManager::class);
    }

    public function configureCommand($c)
    {
        BuildCommand::configure($c);
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

        // No cleanup occurs until the build is started.
        $this->ssh
            ->shouldReceive('disconnectAll')
            ->never();

        $command = new BuildCommand(
            $this->logger,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->packer,
            $this->mover,
            $this->filesystem,
            $this->ssh
        );

        $command->disableShutdownHandler();

        $io = $this->io('configureCommand', [
            'BUILD_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Runner - Run build',
            '[1/7] Resolving configuration',
            '[ERROR] Build cannot be run.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testSuccess()
    {
        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $this->generateMockBuild(),
                'location' => [
                    'download' => 'path/file',
                    'path' => 'path/dir',
                    'archive' => 'path/file2',
                    'tempArchive' => 'path/file3'
                ],
                'github' => [
                    'user' => 'user1',
                    'repo' => 'repo1',
                    'reference' => 'master'
                ],
                'configuration' => [
                    'build' => [
                        'bin/build'
                    ],
                    'platform' => 'global',
                    'image' => '',
                    'dist' => '.'
                ],
                'environmentVariables' => [],
                'artifacts' => [
                    'path/dir',
                    'path/file',
                    'path/file3'
                ]
            ]);

        $this->downloader
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->reader
            ->shouldReceive('__invoke')
            ->andReturn([
                'platform' => 'default',
                'image' => 'default',
                'build' => ['command1 --flag', 'path/to/command2 arg1'],
                'dist' => '.'
            ]);
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->mover
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->filesystem
            ->shouldReceive('remove')
            ->times(3);

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->once();
        $this->logger
            ->shouldReceive('setStage')
            ->times(2);
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->never();

        $this->ssh
            ->shouldReceive('disconnectAll')
            ->once();

        $command = new BuildCommand(
            $this->logger,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->packer,
            $this->mover,
            $this->filesystem,
            $this->ssh
        );

        $command->disableShutdownHandler();

        $io = $this->io('configureCommand', [
            'BUILD_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[1/7] Resolving configuration',
            ' * Build: 1234',
            ' * Application: derp (ID: app123)',
            ' * Environment: staging (ID: env123)',

            '[2/7] Downloading source code',
            ' * Source repository: user1/repo1',
            ' * Source reference: master',
            ' * Target: path/file',

            '[3/7] Unpacking source code',
            ' * Source file: path/file',
            ' * Build directory: path/dir',

            '[4/7] Reading .hal.yml configuration',
            ' * Application configuration:',

            '[5/7] Running build process',
            ' * Platform: default',
            ' * Platform Image: default',
            ' Commands:',
            ' * command1 --flag',
            ' * path/to/command2 arg1',

            '[6/7] Packing build artifact',
            ' * Build directory: path/dir',
            ' * Artifact path: .',
            ' * Artifact file: path/dir',

            '[7/7] Exporting build artifact',
            ' * Artifact file: path/file3',
            ' * Repository storage: path/file2',

            'Build clean-up',
            'Build artifacts to remove:',
            ' * path/dir',
            ' * path/file',
            ' * path/file3',

            '[OK] Build was run successfully.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(0, $exit);
    }

    public function testEmergencyErrorHandling()
    {
        $this->downloader->shouldReceive(['__invoke' => true]);
        // simulate an error
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andThrow(new RuntimeException);
        $this->builder->shouldReceive(['__invoke' => true]);
        $this->packer->shouldReceive(['__invoke' => true]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $this->generateMockBuild(),

                'configuration' => [
                    'system' => 'global',
                ],
                'location' => [
                    'download' => 'path/file',
                    'path' => 'path/dir',
                ],
                'github' => [
                    'user' => 'user1',
                    'repo' => 'repo1',
                    'reference' => 'master'
                ],

                'artifacts' => []
            ]);

        $this->logger
            ->shouldReceive('setStage')
            ->times(1);
        $this->logger
            ->shouldReceive('start')
            ->times(1);
        $this->logger
            ->shouldReceive('event')
            ->times(1);
        $this->logger
            ->shouldReceive('failure')
            ->once();

        $this->ssh
            ->shouldReceive('disconnectAll')
            ->once();

        $command = new BuildCommand(
            $this->logger,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->reader,
            $this->builder,
            $this->packer,
            $this->mover,
            $this->filesystem,
            $this->ssh
        );

        $isAborted = false;
        try {
            $io = $this->io('configureCommand', [
                'BUILD_ID' => '1'
            ]);

            $command->execute($io);

        } catch (RuntimeException $e) {
            $isAborted = true;
        }

        $this->assertSame(true, $isAborted);

        $command->emergencyCleanup();
    }

    public function generateMockBuild()
    {
        return Mockery::mock(Build::class, [
            'status' => null,
            'id' => 1234,
            'application' => Mockery::mock(Application::class, [
                'id' => 'app123',
                'name' => 'derp'
            ]),
            'environment' => Mockery::mock(Environment::class, [
                'id' => 'env123',
                'name' => 'staging'
            ])
        ]);
    }
}
