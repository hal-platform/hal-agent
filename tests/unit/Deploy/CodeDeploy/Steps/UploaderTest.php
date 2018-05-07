<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Aws\S3\S3Client;
use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Logger\EventLogger;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use RuntimeException;

class UploaderTest extends MockeryTestCase
{
    public $s3;
    public $logger;
    public $s3Uploader;

    public function setUp()
    {
        $this->s3 = Mockery::mock(S3Client::class);
        $this->logger = Mockery::mock(EventLogger::class);
        $this->s3Uploader = Mockery::mock(S3Uploader::class);
    }

    public function testSuccess()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->with('bucket', 'object')
            ->andReturn(false);

        $this->s3Uploader
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $uploader = new Uploader($this->logger, $this->s3Uploader);

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
            ->shouldReceive('doesObjectExist')
            ->with('bucket', 'object')
            ->andReturn(false);

        $this->s3Uploader
            ->shouldReceive('__invoke')
            ->andReturn(false);

        $uploader = new Uploader($this->logger, $this->s3Uploader);

        $actual = $uploader($this->s3, './local_file', 'bucket', 'object');

        $this->assertSame(false, $actual);
    }

    public function testErrorWhenObjectAlreadyExists()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->with('bucket', 'object')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any());

        $uploader = new Uploader($this->logger, $this->s3Uploader);

        $actual = $uploader($this->s3, './local_file', 'bucket', 'object');

        $this->assertSame(false, $actual);
    }
}
