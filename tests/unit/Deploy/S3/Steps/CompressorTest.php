<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

class CompressorTest extends MockeryTestCase
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

    public function testZipExtensionUsesZipCompression()
    {
        $sourcePath = __DIR__ . '/.fixtures/source_path';

        $this->filesystem
            ->shouldReceive('exists')
            ->with($sourcePath)
            ->once()
            ->andReturn(true);

        $this->fileCompression
            ->shouldReceive('packZipArchive')
            ->with($sourcePath, 'temp_file.zip')
            ->once()
            ->andReturn(true);

        $compressor = new Compressor($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $compressor($sourcePath, 'temp_file.zip', 'file.zip');

        $this->assertSame(true, $actual);
    }

    public function testTgzExtensionUsesTarCompression()
    {
        $sourcePath = __DIR__ . '/.fixtures/source_path';

        $this->filesystem
            ->shouldReceive('exists')
            ->with($sourcePath)
            ->once()
            ->andReturn(true);

        $this->fileCompression
            ->shouldReceive('packTarArchive')
            ->with($sourcePath, 'temp_file.tgz')
            ->once()
            ->andReturn(true);

        $compressor = new Compressor($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $compressor($sourcePath, 'temp_file.tgz', 'file.tgz');

        $this->assertSame(true, $actual);
    }

    public function testBypassCompressionIfSourceIsFile()
    {
        $sourceFile = __DIR__ . '/.fixtures/source_file.txt';

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('copy')
            ->with($sourceFile, 'target_file.ext', true)
            ->once()
            ->andReturn(true);

        $compressor = new Compressor($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $compressor($sourceFile, 'target_file.ext', 'file.ext');

        $this->assertSame(true, $actual);
    }

    public function testMoveFileFails()
    {
        $sourceFile = __DIR__ . '/.fixtures/source_file.txt';

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Failed to prepare artifact for upload', Mockery::any())
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true);

        $this->filesystem
            ->shouldReceive('copy')
            ->andThrow(IOException::class);

        $compressor = new Compressor($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $compressor($sourceFile, 'target_file.ext', 'file.ext');

        $this->assertSame(false, $actual);
    }

    public function testFailIfDirTraversal()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Invalid source file or directory specified', Mockery::any())
            ->once();

        $compressor = new Compressor($this->logger, $this->filesystem, $this->fileCompression);

        $actual = $compressor('/path/../files', 'target_file.tgz', 'file.tar.gz');

        $this->assertSame(false, $actual);
    }

    public function testFailOnUnsupportedExtension()
    {
        $sourcePath = __DIR__ . '/.fixtures/source_path';

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Artifact file extension is not valid', Mockery::any())
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with($sourcePath)
            ->once()
            ->andReturn(true);

        $compressor = new Compressor($this->logger, $this->filesystem, $this->fileCompression);
        $actual = $compressor($sourcePath, 'targetFile', 'file.7z');
    }
}

