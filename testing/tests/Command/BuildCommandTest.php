<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command;

use Exception;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommandTest extends PHPUnit_Framework_TestCase
{
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

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('Hal\Agent\Build\Resolver');
        $this->downloader = Mockery::mock('Hal\Agent\Build\Downloader');
        $this->unpacker = Mockery::mock('Hal\Agent\Build\Unpacker');
        $this->reader = Mockery::mock('Hal\Agent\Build\ConfigurationReader');
        $this->builder = Mockery::mock('Hal\Agent\Build\DelegatingBuilder');
        $this->packer = Mockery::mock('Hal\Agent\Build\Packer');
        $this->mover = Mockery::mock('Hal\Agent\Build\Mover');
        $this->filesystem = Mockery::mock('Symfony\Component\FileSystem\Filesystem');
        $this->ssh = Mockery::mock('Hal\Agent\Remoting\SSHSessionManager');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'BUILD_ID' => '1'
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

        $this->ssh
            ->shouldReceive('disconnectAll')
            ->twice();

        $command = new BuildCommand(
            'cmd',
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
        $command->run($this->input, $this->output);

        $expected = [
            '[Starting Build] Resolving build properties',
            'Build details could not be resolved.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testSuccess()
    {
        $this->input = new ArrayInput([
            'BUILD_ID' => '1'
        ]);

        $build = Mockery::mock(Build::CLASS, [
            'status' => null,
            'withStatus' => null,
            'withStart' => null,
            'withEnd' => null,
            'id' => 1234,
            'application' => Mockery::mock(Application::CLASS, [
                'key' => null,
                'name' => 'derp'
            ]),
            'environment' => Mockery::mock(Environment::CLASS, [
                'name' => 'beta'
            ])
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $build,
                'system' => 'unix',
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
                    'system' => 'global',
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
            ->andReturn(true);
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
            ->once();

        $this->ssh
            ->shouldReceive('disconnectAll')
            ->twice();

        $command = new BuildCommand(
            'cmd',
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
        $command->run($this->input, $this->output);

        $expected = [
            '[Starting Build] Resolving build properties',
            '[Starting Build] Application: derp',
            '[Starting Build] Environment: beta',
            '[Starting Build] Build: 1234',
            '[Starting Build] Downloading github repository',
            '[Starting Build] Unpacking github repository',
            '[Starting Build] Reading .hal9000.yml',
            '[Building] Building',
            '[Finishing Build] Packing build into archive',
            '[Finishing Build] Moving build to archive',
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
            'BUILD_ID' => '1'
        ]);

        $build = Mockery::mock('QL\Hal\Core\Entity\Build', [
            'getStatus' => 'Building',
            'getId' => 1234,
            'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => null
            ]),
            'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                'getKey' => null
            ])
        ]);

        $this->downloader->shouldReceive(['__invoke' => true]);
        // simulate an error
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andThrow(new Exception);
        $this->builder->shouldReceive(['__invoke' => true]);
        $this->packer->shouldReceive(['__invoke' => true]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $build,

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
            ->shouldReceive('failure')
            ->once();

        $this->ssh
            ->shouldReceive('disconnectAll')
            ->once();

        $command = new BuildCommand(
            'cmd',
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

        try {
            $command->disableShutdownHandler();
            $command->run($this->input, $this->output);
        } catch (Exception $e) {}

        // this will call __destruct
        unset($command);
    }
}
