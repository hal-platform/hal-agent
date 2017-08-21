<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\CodeDeploy;

use Aws\CommandInterface;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\CodeDeploy\Exception\CodeDeployException;
use Aws\Result;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Waiter\Waiter;

class PusherTest extends MockeryTestCase
{
    public $cd;

    public $logger;
    public $health;
    public $waiter;

    public function setUp()
    {
        $this->cd = Mockery::mock(CodeDeployClient::class);

        $this->logger = Mockery::mock(EventLogger::class);
        $this->health = Mockery::mock(HealthChecker::class);
        $this->waiter = new Waiter(.1, 10);
    }

    public function testSuccess()
    {
        $result = new Result([
            'deploymentId' => '1234'
        ]);

        $expectedDescription = <<<TEXT
[test]http://hal.local/pushes/p2.push
TEXT;
        $this->cd
            ->shouldReceive('createDeployment')
            ->with([
                'applicationName' => 'cd_app',
                'deploymentGroupName' => 'cd_group',
                'deploymentConfigName' => 'cd_config',

                'description' => $expectedDescription,
                'ignoreApplicationStopFailures' => false,
                'revision' => [
                    'revisionType' => 'S3',
                    's3Location' => [
                        'bucket' => 'bucket',
                        'bundleType' => 'tgz',
                        'key' => 'file.tar.gz'
                    ]
                ]
            ])
            ->andReturn($result);

        $this->health
            ->shouldReceive('getDeploymentInstancesHealth')
            ->with($this->cd, '1234')
            ->andReturn([
                'status' => 'Succeeded'
            ])
            ->times(6);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Code Deployment', Mockery::type('array'))
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter, 'http://hal.local');
        $status = $pusher(
            $this->cd,
            'cd_app',
            'cd_group',
            'cd_config',

            'bucket',
            'file.tar.gz',

            'b2.build',
            'p2.push',
            'test'
        );

        $this->assertSame(true, $status);
    }

    public function testCreateDeploymentHasAnErrorFails()
    {
        $ex = new CodeDeployException('msg', Mockery::mock(CommandInterface::class));
        $this->cd
            ->shouldReceive('createDeployment')
            ->andThrow($ex);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Code Deployment', Mockery::type('array'))
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter, 'http://hal.local');
        $status = $pusher(
            $this->cd,
            'cd_app',
            'cd_group',
            'cd_config',

            'bucket',
            'file.tar.gz',

            'b2.build',
            'p2.push',
            'test'
        );

        $this->assertSame(false, $status);
    }

    public function testStatusNotSucceededReturnsFalse()
    {
        $result = new Result([
            'deploymentId' => '1234'
        ]);

        $this->cd
            ->shouldReceive('createDeployment')
            ->andReturn($result);

        $this->health
            ->shouldReceive('getDeploymentInstancesHealth')
            ->andReturn([
                'status' => 'Failed'
            ])
            ->times(6);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Code Deployment', Mockery::type('array'))
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter, 'http://hal.local');
        $status = $pusher(
            $this->cd,
            'cd_app',
            'cd_group',
            'cd_config',

            'bucket',
            'file.tar.gz',

            'b2.build',
            'p2.push',
            'test'
        );

        $this->assertSame(false, $status);
    }

    public function testWaitTimeoutExpires()
    {
        $this->waiter = new Waiter(.1, 15);

        $result = new Result([
            'deploymentId' => '1234'
        ]);

        $this->cd
            ->shouldReceive('createDeployment')
            ->andReturn($result);

        $this->health
            ->shouldReceive('getDeploymentInstancesHealth')
            ->andReturn([
                'status' => 'Queued',
                'overview' => [
                    'Pending' => 11,
                    'InProgress' => 0,
                    'Succeeded' => 0,
                    'Failed' => 0,
                    'Skipped' => 0
                ]
            ])
            ->times(9);
        $this->health
            ->shouldReceive('getDeploymentInstancesHealth')
            ->andReturn([
                'status' => 'InProgress',
                'overview' => [
                    'Pending' => 5,
                    'InProgress' => 0,
                    'Succeeded' => 2,
                    'Failed' => 0,
                    'Skipped' => 4
                ]
            ])
            ->times(6);
        $this->health
            ->shouldReceive('getDeploymentInstancesHealth')
            ->andReturn([
                'status' => 'InProgress',
                'overview' => [
                    'Pending' => 1,
                    'InProgress' => 0,
                    'Succeeded' => 6,
                    'Failed' => 0,
                    'Skipped' => 4
                ]
            ])
            ->times(1);

        $this->logger
            ->shouldReceive('event')
            ->with('info', 'Still deploying. Completed 0 of 11', Mockery::type('array'))
            ->once();
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Waited for deployment to finish, but the operation timed out.', [
                'codeDeployApplication' => 'cd_app',
                'codeDeployConfiguration' => 'cd_config',
                'codeDeployGroup' => 'cd_group',
                'bucket' => 'bucket',
                'object' => 'file.tar.gz',
                'codeDeployID' => '1234',
                'status' => 'InProgress',
                'overview' => [
                    'Pending' => 1,
                    'InProgress' => 0,
                    'Succeeded' => 6,
                    'Failed' => 0,
                    'Skipped' => 4
                ]
            ])
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter, 'http://hal.local');
        $status = $pusher(
            $this->cd,
            'cd_app',
            'cd_group',
            'cd_config',

            'bucket',
            'file.tar.gz',

            'b2.build',
            'p2.push',
            'test'
        );

        $this->assertSame(false, $status);
    }
}
