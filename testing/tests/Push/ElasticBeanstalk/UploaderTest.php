<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

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

        $uploader = new Uploader($this->logger, $this->s3, 'bucket-name', $this->streamer);
        $actual = $uploader('push.zip', 's3-object.zip', 'b.1234', 'p.abcd', 'test');
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

        $uploader = new Uploader($this->logger, $this->s3, 'bucket-name', $this->streamer);
        $actual = $uploader('push.zip', 's3-object.zip', 'b.1234', 'p.abcd', 'test');
        $this->assertSame(false, $actual);
    }

    public function testUploadingBlowsUp()
    {
        $this->s3
            ->shouldReceive('doesObjectExist')
            ->andReturn(false);

        $this->s3
            ->shouldReceive('upload')
            ->andThrow('Aws\Common\Exception\RunTimeException');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any());

        $uploader = new Uploader($this->logger, $this->s3, 'bucket-name', $this->streamer);
        $actual = $uploader('push.zip', 's3-object.zip', 'b.1234', 'p.abcd', 'test');
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
            ->andThrow('Aws\Common\Exception\RunTimeException');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Uploader::ERR_WAITING, Mockery::any());

        $uploader = new Uploader($this->logger, $this->s3, 'bucket-name', $this->streamer);
        $actual = $uploader('push.zip', 's3-object.zip', 'b.1234', 'p.abcd', 'test');
        $this->assertSame(false, $actual);
    }
}
