<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Environment;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\System\VersionControlProvider;
use Hal\Core\VersionControl\VCS;
use Hal\Core\VersionControl\VCSDownloaderInterface;
use Hal\Core\VersionControl\VCSException;
use Mockery;

class DownloaderTest extends MockeryTestCase
{
    public $logger;
    public $compression;
    public $vcs;

    public $downloader;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->compression = Mockery::mock(FileCompression::class);
        $this->vcs = Mockery::mock(VCS::class);

        $this->downloader = Mockery::mock(VCSDownloaderInterface::class);
    }

    public function testSuccessWithNoSourceCode()
    {
        $build = $this->createMockBuild();

        $this->compression
            ->shouldReceive('createWorkspace')
            ->with('/workspace')
            ->once()
            ->andReturn(true);

        $this->vcs
            ->shouldReceive('downloader')
            ->never();

        $downloader = new Downloader($this->logger, $this->compression, $this->vcs);

        $success = $downloader($build, '/tmp/artifact.tgz', '/workspace');
        $this->assertSame(true, $success);
    }

    public function testSuccess()
    {
        $build = $this->createMockBuild();

        $provider = new VersionControlProvider;
        $build->application()->withProvider($provider);

        $this->compression
            ->shouldReceive('createWorkspace')
            ->with(__DIR__ . '/.fixtures/workspace')
            ->once()
            ->andReturn(true);

        $this->compression
            ->shouldReceive('unpackTarArchive')
            ->with(__DIR__ . '/.fixtures/workspace', __DIR__ . '/.fixtures/source_code.tgz', 1)
            ->once()
            ->andReturn(true);

        $this->vcs
            ->shouldReceive('downloader')
            ->with($provider)
            ->once()
            ->andReturn($this->downloader);

        $this->downloader
            ->shouldReceive('download')
            ->once()
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Download source code from version control provider', ['size' => '0.01 MB'])
            ->once();

        $downloader = new Downloader($this->logger, $this->compression, $this->vcs);

        $success = $downloader($build, __DIR__ . '/.fixtures', __DIR__ . '/.fixtures/workspace');
        $this->assertSame(true, $success);
    }

    public function testFailOnMissingDownloader()
    {
        $build = $this->createMockBuild();

        $provider = new VersionControlProvider;
        $build->application()->withProvider($provider);

        $this->vcs
            ->shouldReceive('downloader')
            ->with($provider)
            ->andReturnNull();
        $this->vcs
            ->shouldReceive('errors')
            ->andReturn(['error message 1']);

        $this->logger
            ->shouldReceive('event')
            ->once()
            ->with('failure', 'Download source code from version control provider', [
                'errors' => ['error message 1'],
            ]);

        $downloader = new Downloader($this->logger, $this->compression, $this->vcs);

        $success = $downloader($build, '/tmp/artifact.tgz', '/workspace');
        $this->assertSame(false, $success);
    }

    public function testFailOnDownload()
    {
        $build = $this->createMockBuild();

        $provider = new VersionControlProvider;
        $build->application()->withProvider($provider);

        $this->compression
            ->shouldReceive('createWorkspace')
            ->with(__DIR__ . '/.fixtures/workspace')
            ->once()
            ->andReturn(true);

        $this->compression
            ->shouldReceive('unpackTarArchive')
            ->never();

        $this->vcs
            ->shouldReceive('downloader')
            ->with($provider)
            ->once()
            ->andReturn($this->downloader);

        $this->downloader
            ->shouldReceive('download')
            ->once()
            ->andThrow(VCSException::class);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Download source code from version control provider')
            ->once();

        $downloader = new Downloader($this->logger, $this->compression, $this->vcs);

        $success = $downloader($build, '/tmp/artifact.tgz', __DIR__ . '/.fixtures/workspace');
        $this->assertSame(false, $success);
    }

    private function createMockBuild()
    {
        return (new Build('1234'))
            ->withStatus('pending')
            ->withReference('master')
            ->withCommit('7de49f3')

            ->withApplication(new Application)
            ->withEnvironment(
                (new Environment)
                    ->withName('staging')
            );
    }
}
