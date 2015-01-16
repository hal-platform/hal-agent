<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;

class NotifierTest extends PHPUnit_Framework_TestCase
{
    public $di;

    public function setUp()
    {
        $this->di = Mockery::mock('Symfony\Component\DependencyInjection\Container');
    }

    public function testInvalidSubscriptionIsNotAdded()
    {
        $this->di
            ->shouldReceive('has')
            ->never();

        $notifier = new Notifier($this->di);
        $notifier->addSubscription('bad.event', 'service');
        $notifier->sendNotifications('bad.event', null);
    }

    public function testInvalidEntityDoesNotTriggerNotification()
    {
        $this->di
            ->shouldReceive('has')
            ->never();

        $notifier = new Notifier($this->di);
        $notifier->addSubscription('build.end', 'service');
        $notifier->sendNotifications('build.end', null);
    }

    public function testNotificationIsSent()
    {
        $notifierService = Mockery::mock('QL\Hal\Agent\Notifier\NotifierInterface');
        $notifierService
            ->shouldReceive('send')
            ->with('build.end', Mockery::any())
            ->once();

        $this->di
            ->shouldReceive('has')
            ->with('service')
            ->once()
            ->andReturn(true);
        $this->di
            ->shouldReceive('get')
            ->andReturn($notifierService);

        $build = Mockery::mock('QL\Hal\Core\Entity\Build');
        $build
            ->shouldReceive([
                'getStatus' => 'Success',
                'getRepository' => 'repo',
                'getEnvironment' => 'env'
            ]);

        $notifier = new Notifier($this->di);
        $notifier->addSubscription('build.end', 'service');
        $notifier->sendNotifications('build.end', $build);
    }

    public function testNotifierBuildsCorrectContextForService()
    {
        $spy = null;
        $notifierService = Mockery::mock('QL\Hal\Agent\Notifier\NotifierInterface');
        $notifierService
            ->shouldReceive('send')
            ->with('push.success', Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }))
            ->once();

        $this->di
            ->shouldReceive('has')
            ->with('service')
            ->once()
            ->andReturn(true);
        $this->di
            ->shouldReceive('get')
            ->andReturn($notifierService);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push');
        $build = Mockery::mock('QL\Hal\Core\Entity\Build');
        $server = Mockery::mock('QL\Hal\Core\Entity\Server');
        $deployment = Mockery::mock('QL\Hal\Core\Entity\Deployment');
        $repo = Mockery::mock('QL\Hal\Core\Entity\Repository');
        $env = Mockery::mock('QL\Hal\Core\Entity\Environment');

        $push
            ->shouldReceive('getStatus')
            ->andReturn('Success');
        $push
            ->shouldReceive('getBuild')
            ->andReturn($build);
        $push
            ->shouldReceive('getDeployment')
            ->andReturn($deployment);
        $build
            ->shouldReceive('getRepository')
            ->andReturn($repo);
        $build
            ->shouldReceive('getEnvironment')
            ->andReturn($env);
        $deployment
            ->shouldReceive('getServer')
            ->andReturn($server);

        $expectedContext = [
            'event' => 'push.success',
            'status' => true,
            'build' => $build,
            'push' => $push,
            'repository' => $repo,
            'environment' => $env,
            'deployment' => $deployment,
            'server' => $server
        ];

        $notifier = new Notifier($this->di);
        $notifier->addSubscription('push.success', 'service');
        $notifier->sendNotifications('push.success', $push);

        $this->assertSame($expectedContext['event'], $spy['event']);
        $this->assertSame($expectedContext['status'], $spy['status']);
        $this->assertSame($expectedContext['build'], $spy['build']);
        $this->assertSame($expectedContext['push'], $spy['push']);
        $this->assertSame($expectedContext['repository'], $spy['repository']);
        $this->assertSame($expectedContext['environment'], $spy['environment']);
        $this->assertSame($expectedContext['deployment'], $spy['deployment']);
        $this->assertSame($expectedContext['server'], $spy['server']);
    }

}
