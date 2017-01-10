<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Notifier\NotifierInterface;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;
use Symfony\Component\DependencyInjection\Container;

class NotifierTest extends PHPUnit_Framework_TestCase
{
    public $di;

    public function setUp()
    {
        $this->di = Mockery::mock(Container::class);
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
        $notifierService = Mockery::mock(NotifierInterface::class);
        $notifierService
            ->shouldReceive('send')
            ->with('push.end', Mockery::any())
            ->once();

        $this->di
            ->shouldReceive('has')
            ->with('service')
            ->once()
            ->andReturn(true);
        $this->di
            ->shouldReceive('get')
            ->andReturn($notifierService);

        $push = Mockery::mock(Push::class, [
            'status' => 'Success',
            'application' => Mockery::mock(Application::class),
            'build' => Mockery::mock(Build::class, [
                'environment' => Mockery::mock(Environment::class)
            ]),
            'deployment' => Mockery::mock(Deployment::class, [
                'server' => Mockery::mock(Server::class)
            ])
        ]);

        $notifier = new Notifier($this->di);
        $notifier->addSubscription('push.end', 'service');
        $notifier->sendNotifications('push.end', $push);
    }

    public function testNotifierBuildsCorrectContextForService()
    {
        $spy = null;
        $notifierService = Mockery::mock(NotifierInterface::class);
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

        $application = Mockery::mock(Application::class);
        $environment = Mockery::mock(Environment::class);
        $build = Mockery::mock(Build::class, ['environment' => $environment]);

        $server = Mockery::mock(Server::class);
        $deployment = Mockery::mock(Deployment::class, ['server' => $server]);

        $push = Mockery::mock(Push::class, [
            'status' => 'Success',
            'application' => $application,
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
