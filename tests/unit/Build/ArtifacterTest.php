<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Symfony\Component\Filesystem\Filesystem;

class ArtifacterTest extends MockeryTestCase
{
    public $logger;
    public $filesystem;
    public $fileCompression;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->fileCompression = Mockery::mock(FileCompression::class);
    }

    public function testSuccess()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('/workspace/build/.')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/workspace/build/.hal.yaml')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/workspace/build/./.hal.yaml')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('copy')
            ->with('/workspace/build/.hal.yaml', '/workspace/build/./.hal.yaml', true)
            ->andReturn(false);

        $this->fileCompression
            ->shouldReceive('packTarArchive')
            ->with('/workspace/build', '/workspace/build.tgz')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/workspace/build.tgz')
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('copy')
            ->with('/workspace/build.tgz', '/permanent/build.tgz', true)
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Store artifact in artifact repository')
            ->once();

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $artifacter(
            '/workspace/build',
            '.',
            '/workspace/build.tgz',
            '/permanent/build.tgz'
        );

        $this->assertSame(true, $actual);
    }

    public function testFailIfDistTraversesUpTree()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid distribution directory specified', ['path' => '../..'])
            ->once();

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $invalidDist = '../../';
        $actual = $artifacter('/workspace/build', $invalidDist, '', '');

        $this->assertSame(false, $actual);
    }

    public function testFailIfDistTraversesUpTreeOnce()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid distribution directory specified', ['path' => '..'])
            ->once();

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $invalidDist = '..';
        $actual = $artifacter('/workspace/build', $invalidDist, '', '');

        $this->assertSame(false, $actual);
    }

    public function testFailIfDistDoesNotExist()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Distribution directory not found', ['path' => 'subdir'])
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/workspace/build/subdir')
            ->andReturn(false);

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $artifacter('/workspace/build', '/subdir', '', '');

        $this->assertSame(false, $actual);
    }
}
