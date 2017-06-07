<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\ElasticBeanstalk;

use Aws\CommandInterface;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\ElasticBeanstalk\Exception\ElasticBeanstalkException;
use Aws\Result;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\ElasticBeanstalk\HealthChecker;
use Hal\Agent\Waiter\Waiter;

class PusherTest extends MockeryTestCase
{
    public $logger;
    public $eb;
    public $streamer;

    public $health;
    public $waiter;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->eb = Mockery::mock(ElasticBeanstalkClient::class);
        $this->streamer = function() {return 'file';};

        $this->health = Mockery::mock(HealthChecker::class);
        $this->waiter = new Waiter(.1, 10);
    }

    public function testSuccess()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result((['ApplicationVersions' => []])));

        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->once();

        $this->health
            ->shouldReceive('__invoke')
            ->with($this->eb, 'appName', 'envId')
            ->andReturn([
                'status' => 'Updating',
                'health' => 'Grey',
            ])
            ->times(4);
        $this->health
            ->shouldReceive('__invoke')
            ->with($this->eb, 'appName', 'envId')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ])
            ->times(2);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Deployment finished. Waiting for health check')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Code Deployment', Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
        $actual = $pusher(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );
        $this->assertSame(true, $actual);
    }

    public function testVersionAlreadyExistsFails()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => ['version1', 'version2']]));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
        $actual = $pusher(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testebBlowsUpDuringUpdate()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => []]));

        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->andThrow(new ElasticBeanstalkException('', Mockery::mock(CommandInterface::CLASS)));

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
        $actual = $pusher(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testAwsErrorThrownDuringHealthCheckStopsWaiter()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => []]));

        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->once();
        $this->health
            ->shouldReceive('__invoke')
            ->andThrow(new ElasticBeanstalkException('', Mockery::mock(CommandInterface::CLASS)))
            ->once();

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ])
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Deployment finished. Waiting for health check')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('success', Pusher::EVENT_MESSAGE, Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
        $actual = $pusher(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(true, $actual);
    }

    public function testTimeoutResultsInFailureOfPush()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => []]));

        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->once();

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Updating',
                'health' => 'Grey'
            ])
            ->times(5);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Pusher::ERR_WAITING, Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->health, new Waiter(.1, 5));
        $actual = $pusher(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }

    public function testPushWorksButThenHealthCheckFails()
    {
        $this->eb
            ->shouldReceive('describeApplicationVersions')
            ->andReturn(new Result(['ApplicationVersions' => []]));

        $this->eb
            ->shouldReceive('createApplicationVersion')
            ->once();
        $this->eb
            ->shouldReceive('updateEnvironment')
            ->once();

        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Updating',
                'health' => 'Grey'
            ])
            ->times(3);
        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Green'
            ])
            ->times(2);
        $this->health
            ->shouldReceive('__invoke')
            ->andReturn([
                'status' => 'Ready',
                'health' => 'Red'
            ])
            ->times(1);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Deployment finished. Waiting for health check')
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Code Deployment', Mockery::any())
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
        $actual = $pusher(
            $this->eb,
            'appName',
            'envId',
            'bucket-name',
            's3_object.zip',
            'b.1234',
            'p.abcd',
            'test'
        );

        $this->assertSame(false, $actual);
    }
}
