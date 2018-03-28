<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\AWS;

use Aws\S3\S3Client;
use Hal\Agent\Logger\EventLogger;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryTestCase;
use RuntimeException;

class S3UploaderTest extends MockeryTestCase
{
    public $s3;
    public $logger;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
        $this->logger = Mockery::mock(EventLogger::class);
    }

    public function testSuccess()
    {
        $this->s3
            ->shouldReceive('waitUntil')
            ->with(
                'ObjectExists',
                [
                    'Bucket' => 'bucket',
                    'Key' => 'object',
                    '@waiter' => [
                        'delay' => 10,
                        'maxAttempts' => 30
                    ]
                ]
            )
            ->once();

        $this->s3
            ->shouldReceive('upload')
            ->with(
                'bucket',
                'object',
                './local_file:r+',
                'bucket-owner-full-control',
                [
                    'params' => [
                        'Metadata' => ['meta' => 'data']
                    ]
                ]
            )
            ->once();

        $uploader = new S3Uploader($this->logger, function($file, $mode) {
            return "${file}:${mode}";
        });

        $actual = $uploader(
            $this->s3,
            './local_file',
            'bucket',
            'object',
            ['meta' => 'data']
        );

        $this->assertSame(true, $actual);
    }

    public function testErrorOnUpload()
    {
        $this->s3
            ->shouldReceive('waitUntil')
            ->never();

        $this->s3
            ->shouldReceive('upload')
            ->andThrow(RuntimeException::class);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $uploader = new S3Uploader($this->logger, function($file, $mode) {
            return '';
        });

        $actual = $uploader($this->s3, './local_file', 'bucket', 'object');

        $this->assertSame(false, $actual);
    }

    public function testErrorOnWait()
    {
        $this->s3
            ->shouldReceive('waitUntil')
            ->once()
            ->andThrow(RuntimeException::class);

        $this->s3
            ->shouldReceive('upload')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $uploader = new S3Uploader($this->logger, function($file, $mode) {
            return '';
        });

        $actual = $uploader($this->s3, './local_file', 'bucket', 'object');

        $this->assertSame(false, $actual);
    }
}
