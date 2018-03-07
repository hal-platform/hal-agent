<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;
use Hal\Agent\Job\FileCompression;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;

use Hal\Agent\Deploy\DeployException;

class CompressorTest extends MockeryTestCase
{
    public $fileCompression;

    public function setUp()
    {
        $this->fileCompression = Mockery::mock(FileCompression::class);
    }

    public function testZipExtensionUsesZipCompression()
    {
        $this->fileCompression
            ->expects('packZipArchive')
            ->with('sourceFile', 'targetFile')
            ->andReturns(true)
            ->once();

        $compressor = new Compressor($this->fileCompression);

        $actual = $compressor('sourceFile', 'targetFile', 'file.zip');

        $this->assertSame(true, $actual);
    }

    public function testTgzExtensionUsesTarCompression()
    {
        $this->fileCompression
            ->expects('packTarArchive')
            ->with('sourceFile', 'targetFile')
            ->andReturns(true)
            ->once();

        $compressor = new Compressor($this->fileCompression);

        $actual = $compressor('sourceFile', 'targetFile', 'file.tgz');

        $this->assertSame(true, $actual);
    }

    public function testTarGzExtensionUsesTarCompression()
    {
        $this->fileCompression
            ->expects('packTarArchive')
            ->with('sourceFile', 'targetFile')
            ->andReturns(true)
            ->once();

        $compressor = new Compressor($this->fileCompression);

        $actual = $compressor('sourceFile', 'targetFile', 'file.tar.gz');

        $this->assertSame(true, $actual);
    }

    public function testUnsupportedExtensionThrowsException()
    {
        $this->expectException(DeployException::class);

        $compressor = new Compressor($this->fileCompression);
        $actual = $compressor('sourceFile', 'targetFile', 'file.7z');
    }
}
