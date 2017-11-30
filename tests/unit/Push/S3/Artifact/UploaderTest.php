<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Artifact;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;

class UploaderTest extends MockeryTestCase
{
    public $logger;
    public $s3;
    public $streamer;

    public function setUp()
    {
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');
        $this->s3 = Mockery::mock('Aws\S3\S3Client');
        $this->streamer = function() {return 'file';};
    }

    public function testSuccess()
    {
        $this->s3
            ->shouldReceive('doesBucketExist')
            ->andReturn(true);

        $this->s3
            ->shouldReceive('upload')
            ->with(
                'bucket-name',
                's3-object.tar.gz',
                Mockery::any(),
                'bucket-owner-full-control',
                [
                    'params' => [
                        'Metadata' => [
                            'Build' => 'b.1234',
                            'Push' => 'p.abcd',
                            'Environment' => 'test'
                        ]
                    ]
                ]
            )
            ->once();
        $this->s3
            ->shouldReceive('waitUntil')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any());

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            'b.1234',
            'p.abcd',
            'test'
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

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testUploadFails()
    {
        $this->s3
            ->shouldReceive('doesBucketExist')
            ->andReturn(true);

        $this->s3
            ->shouldReceive('upload')
            ->andThrow(new S3Exception('', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Uploader::EVENT_MESSAGE, Mockery::any());

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testUploadWaitFails()
    {
        $this->s3
            ->shouldReceive('doesBucketExist')
            ->andReturn(true);

        $this->s3
            ->shouldReceive('upload');


        $this->s3
            ->shouldReceive('waitUntil')
            ->andThrow(new AwsException('', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Uploader::ERR_WAITING, [
                'bucket' => 'bucket-name',
                'object' => 's3-object.tar.gz',
                'wait time' => '300 seconds',
                'error' => '',
            ]);

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.tar.gz',
            'bucket-name',
            's3-object.tar.gz',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }
}
