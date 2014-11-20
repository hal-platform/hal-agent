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
    public $builder;
    public $packer;
    public $downloadProgress;
    public $processBuilder;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Build\Resolver');
        $this->downloader = Mockery::mock('QL\Hal\Agent\Build\Downloader');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Build\Unpacker');
        $this->builder = Mockery::mock('QL\Hal\Agent\Build\Builder');
        $this->packer = Mockery::mock('QL\Hal\Agent\Build\Packer');
        $this->downloadProgress = Mockery::mock('QL\Hal\Agent\Helper\DownloadProgressHelper');
        $this->processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');

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

        $command = new BuildCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
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
                'archiveFile' => 'path/file',
                'buildPath' => 'path/dir',
                'githubUser' => 'user1',
                'githubRepo' => 'repo1',
                'githubReference' => 'master',
                'buildCommand' => 'bin/build',
                'environmentVariables' => [],
                'buildFile' => 'path/file',
                'artifacts' => [
                    'path/dir',
                    'path/file'
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
        $this->builder
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->packer
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->processBuilder
            ->shouldReceive('getProcess->run')
            ->twice();

        $this->logger
            ->shouldReceive('start')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->twice();
        $this->logger
            ->shouldReceive('setStage')
            ->times(3);
        $this->logger
            ->shouldReceive('success')
            ->once();
        $this->logger
            ->shouldReceive('failure')
            ->once();

        $command = new BuildCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
        );

        $command->disableShutdownHandler();
        $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Resolving build properties
Downloading github repository
Unpacking github repository
Running build command
Packing build into archive
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

        $command = new BuildCommand(
            'cmd',
            $this->logger,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
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
