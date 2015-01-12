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

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Build\Resolver');
        $this->downloader = Mockery::mock('QL\Hal\Agent\Build\Downloader');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Build\Unpacker');
        $this->reader = Mockery::mock('QL\Hal\Agent\Build\ConfigurationReader');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Builder');
        $this->packer = Mockery::mock('QL\Hal\Agent\Build\Packer');
        $this->mover = Mockery::mock('QL\Hal\Agent\Build\Mover');
        $this->downloadProgress = Mockery::mock('QL\Hal\Agent\Helper\DownloadProgressHelper');
        $this->filesystem = Mockery::mock('Symfony\Component\FileSystem\Filesystem');

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
            $this->filesystem
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving build properties
Build details could not be resolved.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
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
                'getKey' => null
            ]),
            'getEnvironment' => Mockery::mock('QL\Hal\Core\Entity\Environment', [
                'getKey' => null
            ])
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'build'  => $build,
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
            ->times(3);
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
            $this->filesystem
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving build properties
Found build: 1234
Downloading github repository
Unpacking github repository
Reading .hal9000.yml
Running build command
Packing build into archive
Moving build to archive
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
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
                'buildPath' => 'path/dir',
                'githubUser' => 'user1',
                'githubRepo' => 'repo1',
                'githubReference' => 'master',
                'buildFile' => 'path/file',
                'artifacts' => []
            ]);

        $this->logger
            ->shouldReceive('failure')
            ->once();
        $this->logger
            ->shouldReceive('addSubscription')
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
