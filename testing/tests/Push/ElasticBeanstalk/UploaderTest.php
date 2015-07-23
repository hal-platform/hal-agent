<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use Mockery;
use PHPUnit_Framework_TestCase;

class UploaderTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $s3;
    public $streamer;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->s3 = Mockery::mock('Aws\S3\S3Client');
        $this->streamer = function() {return 'file';};
    }

    public function testSuccess()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->andReturn(false);

        $this->s3
            ->shouldReceive('upload')
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
            'push.zip',
            'bucket-name',
            's3-object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );
        $this->assertSame(true, $actual);
    }

    public function testObjectAlreadyUploadedFails()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->andReturn(true);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any());

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.zip',
            'bucket-name',
            's3-object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testUploadingBlowsUp()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->andReturn(false);

        $this->s3
            ->shouldReceive('upload')
            ->andThrow(new S3Exception('', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any());

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.zip',
            'bucket-name',
            's3-object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testWaitingForObjectExpiresAndFails()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->andReturn(false);

        $this->s3
            ->shouldReceive('upload')
            ->once();
        $this->s3
            ->shouldReceive('waitUntil')
            ->andThrow(new AwsException('', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Uploader::ERR_WAITING, Mockery::any());

        $uploader = new Uploader($this->logger, $this->streamer);
        $actual = $uploader(
            $this->s3,
            'push.zip',
            'bucket-name',
            's3-object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }
}
