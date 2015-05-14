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
    public $downloadProgress;
    public $filesystem;
    public $ssh;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Build\Resolver');
        $this->downloader = Mockery::mock('QL\Hal\Agent\Build\Downloader');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Build\Unpacker');
        $this->reader = Mockery::mock('QL\Hal\Agent\Build\ConfigurationReader');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\DelegatingBuilder');
        $this->packer = Mockery::mock('QL\Hal\Agent\Build\Packer');
        $this->mover = Mockery::mock('QL\Hal\Agent\Build\Mover');
        $this->downloadProgress = Mockery::mock('QL\Hal\Agent\Symfony\GuzzleDownloadProgress');
        $this->filesystem = Mockery::mock('Symfony\Component\FileSystem\Filesystem');
        $this->ssh = Mockery::mock('QL\Hal\Agent\Remoting\SSHSessionManager');

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
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

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
            $this->downloadProgress,
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

        $build = Mockery::mock('QL\Hal\Core\Entity\Build', [
            'getStatus' => null,
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
            'getId' => 1234,
            'getRepository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => null,
                'getName' => 'derp'
            ]),
            'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                'getKey' => null
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

        $this->downloadProgress
            ->shouldReceive('enableDownloadProgress');

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
        $this->logger
            ->shouldReceive('addSubscription')
            ->with('build.failure', 'notifier.email')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
            ->with('build.success', 'notifier.email')
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
            $this->downloadProgress,
            $this->filesystem,
            $this->ssh
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = [
            '[Starting Build] Resolving build properties',
            '[Starting Build] Found build: 1234',
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

        $this->downloadProgress->shouldIgnoreMissing();
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
        $this->logger
            ->shouldReceive('addSubscription')
            ->twice();

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
            $this->downloadProgress,
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
