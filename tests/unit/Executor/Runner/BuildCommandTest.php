<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Runner;

use Hal\Agent\Build\Artifacter;
use Hal\Agent\Build\Builder;
use Hal\Agent\Build\Downloader;
use Hal\Agent\Build\Resolver;
use Hal\Agent\Job\LocalCleaner;
use Hal\Agent\JobConfiguration\ConfigurationReader;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHSessionManager;
use Hal\Agent\Testing\IOTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class BuildCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $cleaner;
    public $ssh;

    public $resolver;
    public $downloader;
    public $reader;
    public $builder;
    public $artifacter;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->cleaner = Mockery::mock(LocalCleaner::class);
        $this->ssh = Mockery::mock(SSHSessionManager::class, [
            'disconnectAll' => null
        ]);

        $this->resolver = Mockery::mock(Resolver::class);
        $this->downloader = Mockery::mock(Downloader::class);
        $this->reader = Mockery::mock(ConfigurationReader::class);
        $this->builder = Mockery::mock(Builder::class);
        $this->artifacter = Mockery::mock(Artifacter::class);
    }

    public function configureCommand($c)
    {
        BuildCommand::configure($c);
    }

    public function testSuccess()
    {
        $build = $this->generateMockBuild();

        $properties = [
            'build'  => $build,

            'default_configuration' => [
                'platform' => 'linux',
                'image' => 'default',
                'build' => [],
            ],
            'workspace_path' => '/path/to/job-1234',
            'artifact_stored_file' => '/artifacts/job-1234.tgz',

            'encrypted_sources' => []
        ];

        $config = [
            'platform' => 'windows',
            'image' => 'my-project-image:latest',
            'dist' => '.',
            'build' => ['command1 --flag', 'path/to/command2 arg1'],
        ];

        $this->resolver
            ->shouldReceive('__invoke')
            ->with('1')
            ->andReturn($properties);

        $this->downloader
            ->shouldReceive('__invoke')
            ->with($build, '/path/to/job-1234')
            ->andReturn(true);

        $this->reader
            ->shouldReceive('__invoke')
            ->with('/path/to/job-1234/build', [
                'platform' => 'linux',
                'image' => 'default',
                'build' => [],
            ])
            ->andReturn($config);

        $this->builder
            ->shouldReceive('__invoke')
            ->with(Mockery::any(), 'windows', $config, $properties)
            ->andReturn(true);

        $this->artifacter
            ->shouldReceive('__invoke')
            ->with('/path/to/job-1234/build', '.', '/path/to/job-1234/artifact.tgz', '/artifacts/job-1234.tgz')
            ->andReturn(true);

        $this->cleaner
            ->shouldReceive('__invoke')
            ->with(['/path/to/job-1234'])
            ->andReturn(true);
        $this->ssh
            ->shouldReceive('disconnectAll')
            ->once();

        $this->logger
            ->shouldReceive('start')
            ->with($build)
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Resolved build configuration', Mockery::any())
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

        $command = new BuildCommand(
            $this->logger,
            $this->cleaner,
            $this->ssh,
            $this->resolver,
            $this->downloader,
            $this->reader,
            $this->builder,
            $this->artifacter
        );

        $io = $this->ioForCommand('configureCommand', [
            'BUILD_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            '[1/5] Resolving configuration',
            ' * Build: 1234',
            ' * Application: derp (ID: a-1234)',
            ' * Environment: staging (ID: e-1234)',

            '[2/5] Downloading source code',
            ' * Build Workspace: /path/to/job-1234',
            ' * VCS Provider: None (Type: N/A)',
            ' * VCS Reference: master (Commit: 7de49f3)',

            '[3/5] Reading .hal.yml configuration',
            'Application configuration:',
            '  platform        "windows"',
            '  image           "my-project-image:latest"',
            '  dist            "."',

            '[4/5] Running build stage',
            ' * Platform: windows',
            ' * Docker Image: my-project-image:latest',

            'Build steps:',
            ' * command1 --flag',
            ' * path/to/command2 arg1',

            '[5/5] Storing build artifact',
            ' * Artifact Path: /path/to/job-1234/build/.',
            ' * Artifact File: /path/to/job-1234/artifact.tgz',
            ' * Artifact Repository: Filesystem',
            ' * Repository Location: /artifacts/job-1234.tgz',

            'Build clean-up',
            'Build artifacts to remove:',
            ' * /path/to/job-1234',

            '[OK] Build was run successfully.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(0, $exit);
    }

    public function testBuildResolvingFails()
    {
        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([]);

        $this->logger
            ->shouldReceive('setStage')
            ->with('created')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();

        // No cleanup occurs until the build is started.
        $this->ssh
            ->shouldReceive('disconnectAll')
            ->never();

        $command = new BuildCommand(
            $this->logger,
            $this->cleaner,
            $this->ssh,
            $this->resolver,
            $this->downloader,
            $this->reader,
            $this->builder,
            $this->artifacter
        );

        $io = $this->ioForCommand('configureCommand', [
            'BUILD_ID' => '1'
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Runner - Run build',
            '[1/5] Resolving configuration',
            '[ERROR] Build cannot be run.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function generateMockBuild()
    {
        return (new Build('1234'))
            ->withReference('master')
            ->withCommit('7de49f3')
            ->withApplication(
                (new Application('a-1234'))
                    ->withName('derp')
            )
            ->withEnvironment(
                (new Environment('e-1234'))
                    ->withName('staging')
            );
    }
}
