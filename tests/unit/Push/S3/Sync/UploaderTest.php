<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\S3\Sync\SyncManager;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

class UploaderTest extends MockeryTestCase
{
    public $logger;
    public $s3;
    public $syncMaanger;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->s3 = Mockery::mock(S3Client::class);
        $this->syncManager = Mockery::mock(SyncManager::class);
    }

    public function testSuccess()
    {
        $this->s3
            ->shouldReceive('doesBucketExist')
            ->andReturn(true);

        $this->syncManager
            ->shouldReceive('sync')
            ->with($this->s3, 'push.tar.gz', 'bucket-name', 's3-object.tar.gz', 3)
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any());

        $uploader = new Uploader($this->logger, $this->syncManager);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            [
                'Build' => 'b.1234',
                'Push' => 'p.abcd',
                'Environment' => 'test'
            ]
        );

        $this->assertSame(true, $actual);
    }

    public function testBucketDoesNotExist()
    {
        $this->s3
            ->shouldReceive('doesBucketExist')
            ->andReturn(false);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Uploader::ERR_BUCKET_MISSING, Mockery::any());

        $uploader = new Uploader($this->logger, $this->syncManager);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            [
                'Build' => 'b.1234',
                'Push' => 'p.abcd',
                'Environment' => 'test'
            ]
        );

        $this->assertSame(false, $actual);
    }

    public function testUploadFails()
    {
        $this->s3
            ->shouldReceive('doesBucketExist')
            ->andReturn(true);

        $this->syncManager
            ->shouldReceive('sync')
            ->andThrow(new S3Exception('', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Uploader::EVENT_MESSAGE, Mockery::any());

        $uploader = new Uploader($this->logger, $this->syncManager);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            [
                'Build' => 'b.1234',
                'Push' => 'p.abcd',
                'Environment' => 'test'
            ]
        );

        $this->assertSame(false, $actual);
    }
}
