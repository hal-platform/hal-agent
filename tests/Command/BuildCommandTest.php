<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use MCP\DataType\Time\Clock;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Helper\MemoryLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $em;
    public $clock;
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
        $this->logger = new MemoryLogger;
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->clock = new Clock('now', 'UTC');
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

        $command = new BuildCommand(
            'cmd',
            $this->logger,
            $this->em,
            $this->clock,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving...
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

        $this->em
            ->shouldReceive('merge')
            ->with($build);
        $this->em
            ->shouldReceive('flush');

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
                'buildFile' => 'path/file'
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

        $command = new BuildCommand(
            'cmd',
            $this->logger,
            $this->em,
            $this->clock,
            $this->resolver,
            $this->downloader,
            $this->unpacker,
            $this->builder,
            $this->packer,
            $this->downloadProgress,
            $this->processBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving...
Build properties: {
    "build": {

    },
    "archiveFile": "path/file",
    "buildPath": "path/dir",
    "githubUser": "user1",
    "githubRepo": "repo1",
    "githubReference": "master",
    "buildCommand": "bin/build",
    "environmentVariables": [

    ],
    "buildFile": "path/file"
}
Downloading...
Unpacking...
Building...
Packing...
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
