<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Mockery;
use PHPUnit_Framework_TestCase;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $ebs;
    public $streamer;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->ebs = Mockery::mock('Aws\ElasticBeanstalk\ElasticBeanstalkClient');
        $this->streamer = function() {return 'file';};
    }

    public function testSuccess()
    {
        $this->ebs
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(['ApplicationVersions' => []]);

        $this->ebs
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->ebs
            ->shouldReceive('updateEnvironment')
            ->once();
        $this->ebs
            ->shouldReceive('waitUntilEnvironmentReady')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->ebs, 'bucket-name');
        $actual = $pusher('appName', 'envId', 's3_object.zip', 'b.1234', 'p.abcd', 'test');
        $this->assertSame(true, $actual);
    }

    public function testVersionAlreadyExistsFails()
    {
        $this->ebs
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(['ApplicationVersions' => ['version1', 'version2']]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->ebs, 'bucket-name');
        $actual = $pusher('appName', 'envId', 's3_object.zip', 'b.1234', 'p.abcd', 'test');
        $this->assertSame(false, $actual);
    }

    public function testEbsBlowsUpDuringUpdate()
    {
        $this->ebs
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(['ApplicationVersions' => []]);

        $this->ebs
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->ebs
            ->shouldReceive('updateEnvironment')
            ->andThrow('Aws\Common\Exception\RuntimeException');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->ebs, 'bucket-name');
        $actual = $pusher('appName', 'envId', 's3_object.zip', 'b.1234', 'p.abcd', 'test');
        $this->assertSame(false, $actual);
    }

    public function testEbsWaitingForUpdateExpiresButStillSucceeds()
    {
        $this->ebs
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(['ApplicationVersions' => []]);

        $this->ebs
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->ebs
            ->shouldReceive('updateEnvironment')
            ->once();
        $this->ebs
            ->shouldReceive('waitUntilEnvironmentReady')
            ->andThrow('Aws\Common\Exception\RuntimeException');

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Pusher::ERR_WAITING, Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->ebs, 'bucket-name');
        $actual = $pusher('appName', 'envId', 's3_object.zip', 'b.1234', 'p.abcd', 'test');
        $this->assertSame(true, $actual);
    }
}
