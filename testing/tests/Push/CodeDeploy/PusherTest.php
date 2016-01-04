<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\CodeDeploy;

use Aws\CommandInterface;
use Aws\CodeDeploy\CodeDeployClient;
use Aws\CodeDeploy\Exception\CodeDeployException;
use Aws\Result;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Waiter\Waiter;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $cd;

    public $logger;
    public $health;
    public $waiter;

    public function setUp()
    {
        $this->cd = Mockery::mock(CodeDeployClient::CLASS);

        $this->logger = Mockery::mock(EventLogger::CLASS);
        $this->health = Mockery::mock(HealthChecker::CLASS);
        $this->waiter = new Waiter(.25, 10);
    }

    public function testSuccess()
    {
        $result = new Result([
            'deploymentId' => '1234'
        ]);

        $this->cd
            ->shouldReceive('createDeployment')
            ->with([
                'applicationName' => 'cd_app',
                'deploymentGroupName' => 'cd_group',
                'deploymentConfigName' => 'cd_config',

                'description' => 'Build b2.build, Env test',
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
            ->shouldReceive('getDeploymentHealth')
            ->with($this->cd, '1234')
            ->andReturn([
                'status' => 'Succeeded'
            ])
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'Code Deployment', Mockery::type('array'))
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
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
        $ex = new CodeDeployException('msg', Mockery::mock(CommandInterface::CLASS));
        $this->cd
            ->shouldReceive('createDeployment')
            ->andThrow($ex);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Code Deployment', Mockery::type('array'))
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
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
            ->shouldReceive('getDeploymentHealth')
            ->andReturn([
                'status' => 'Failed'
            ])
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Code Deployment', Mockery::type('array'))
            ->once();

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
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
            ->shouldReceive('getDeploymentHealth')
            ->andReturn([
                'status' => 'Queued',
                'overview' => [
                    'Pending' => 0,
                    'InProgress' => 0,
                    'Succeeded' => 3,
                    'Failed' => 0,
                    'Skipped' => 0
                ]
            ])
            ->times(11);
        $this->health
            ->shouldReceive('getDeploymentHealth')
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
            ->times(1);
        $this->health
            ->shouldReceive('getDeploymentHealth')
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
            ->times(4);

        $this->logger
            ->shouldReceive('event')
            ->with('info', 'Deployed 2 of 11', Mockery::type('array'))
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

        $pusher = new Pusher($this->logger, $this->health, $this->waiter);
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
