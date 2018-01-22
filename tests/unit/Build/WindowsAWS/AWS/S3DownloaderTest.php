<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\AWS;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Hal\Agent\Testing\MockeryTestCase;
use InvalidArgumentException;
use Mockery;
use Hal\Agent\Logger\EventLogger;

class S3DownloaderTest extends MockeryTestCase
{
    public $logger;
    public $s3;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->s3 = Mockery::mock(S3Client::class);
    }

    public function testSuccessfulDownload()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->with('bucket-name', 'object1')
            ->andReturn(true);
        $this->s3
            ->shouldReceive('getObject')
            ->with([
                'Bucket' => 'bucket-name',
                'Key' => 'object1',
                'SaveAs' => 'filename.tgz'
            ])
            ->once();

        $this->logger->shouldReceive('event')->with('success', Mockery::any(), Mockery::any())->once();

        $downloader = new S3Downloader($this->logger);
        $downloader->setBuilderDebugLogging(true);
        $result = $downloader($this->s3, 'bucket-name', 'object1', 'filename.tgz');
        $this->assertSame(true, $result);
    }

    public function testErrorIfObjectMissing()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->with('bucket-name', 'object1')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'S3 object not found.', Mockery::any())
            ->once();

        $downloader = new S3Downloader($this->logger);
        $result = $downloader($this->s3, 'bucket-name', 'object1', 'filename.tgz');
        $this->assertSame(false, $result);
    }

    public function testAwsErrorDuringDownloadLogs()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->with('bucket-name', 'object1')
            ->andReturn(true);
        $this->s3
            ->shouldReceive('getObject')
            ->andThrow(new AwsException('msg', Mockery::mock(CommandInterface::class)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Download from S3', Mockery::any())
            ->once();

        $downloader = new S3Downloader($this->logger);
        $result = $downloader($this->s3, 'bucket-name', 'object1', 'filename.tgz');
        $this->assertSame(false, $result);
    }

    public function testInvalidArgErrorDuringDownloadLogs()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->with('bucket-name', 'object1')
            ->andReturn(true);
        $this->s3
            ->shouldReceive('getObject')
            ->andThrow(new InvalidArgumentException('msg'));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Download from S3', Mockery::any())
            ->once();

        $downloader = new S3Downloader($this->logger);
        $result = $downloader($this->s3, 'bucket-name', 'object1', 'filename.tgz');
        $this->assertSame(false, $result);
    }
}
