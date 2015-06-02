<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;

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
                'status' => 'Success',
                'application' => 'repo',
                'environment' => 'env'
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

        $server = Mockery::mock(Server::CLASS);
        $application = Mockery::mock(Application::CLASS);
        $environment = Mockery::mock(Environment::CLASS);

        $build = Mockery::mock(Build::CLASS, [
            'application' => $application,
            'environment' => $environment
        ]);
        $deployment = Mockery::mock(Deployment::CLASS, [
            'server' => $server
        ]);

        $push = Mockery::mock(Push::CLASS, [
            'status' => 'Success',
            'build' => $build,
            'deployment' => $deployment
        ]);

        $expectedContext = [
            'event' => 'push.success',
            'status' => true,
            'build' => $build,
            'push' => $push,
            'application' => $application,
            'environment' => $environment,
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
        $this->assertSame($expectedContext['application'], $spy['application']);
        $this->assertSame($expectedContext['environment'], $spy['environment']);
        $this->assertSame($expectedContext['deployment'], $spy['deployment']);
        $this->assertSame($expectedContext['server'], $spy['server']);
    }
}
