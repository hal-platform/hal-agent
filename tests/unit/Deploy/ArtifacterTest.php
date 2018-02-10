<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

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
            ->with('/permanent/build.tgz')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('copy')
            ->with('/permanent/build.tgz', '/workspace/deploy.tgz', true)
            ->once();

        $this->fileCompression
            ->shouldReceive('unpackTarArchive')
            ->with('/workspace/deploy', '/workspace/deploy.tgz')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Download artifact from artifact repository')
            ->once();

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $artifacter(
            '/workspace/deploy',
            '/workspace/deploy.tgz',
            '/permanent/build.tgz'
        );

        $this->assertSame(true, $actual);
    }

    public function testFailOnArtifactDoesNotExist()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('/permanent/build.tgz')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Download artifact from artifact repository')
            ->once();

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $artifacter(
            '/workspace/deploy',
            '/workspace/deploy.tgz',
            '/permanent/build.tgz'
        );

        $this->assertSame(false, $actual);
    }

    public function testFailOnUnpack()
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('/permanent/build.tgz')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('copy')
            ->with('/permanent/build.tgz', '/workspace/deploy.tgz', true)
            ->once();

        $this->fileCompression
            ->shouldReceive('unpackTarArchive')
            ->with('/workspace/deploy', '/workspace/deploy.tgz')
            ->andReturn(false);

        $artifacter = new Artifacter($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $artifacter(
            '/workspace/deploy',
            '/workspace/deploy.tgz',
            '/permanent/build.tgz'
        );

        $this->assertSame(false, $actual);
    }
}
