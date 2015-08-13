<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Notifier;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Server;
use QL\Hal\Core\Type\EnumType\ServerEnum;
use Swift_Message;

class EmailNotifierTest extends PHPUnit_Framework_TestCase
{
    public $mailer;
    public $message;
    public $formatter;

    public function setUp()
    {
        $this->mailer = Mockery::mock('Swift_Mailer');
        $this->message = new Swift_Message; # this is unmockable

        $this->formatter = Mockery::mock('QL\Hal\Agent\Notifier\EmailFormatter');
    }

    public function testSuccessfulRsyncPush()
    {
        $this->formatter
            ->shouldReceive('format')
            ->once()
            ->andReturn('message');

        $spy = null;
        $this->mailer
            ->shouldReceive('send')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $notifier = new EmailNotifier($this->mailer, $this->message, $this->formatter);

        $data = [
            'status' => true,
            'application' => Mockery::mock(Application::CLASS, [
                'key' => 'repokey',
                'email' => 'test@example.com'
            ]),
            'environment' => Mockery::mock(Environment::CLASS, ['name' => 'envkey']),
            'deployment' => (new Deployment)
                ->withServer(Mockery::mock(Server::CLASS, ['type' => ServerEnum::TYPE_RSYNC, 'formatPretty' => 'testserver1'])),
            'push' => Mockery::mock(Push::CLASS)
        ];

        $notifier->send('event.name', $data);

        $this->assertSame(3, $spy->getPriority());
        $this->assertSame(['test@example.com' => null], $spy->getTo());
        $this->assertSame('[' . EmailNotifier::ICON_SUCCESS . '] repokey (envkey:testserver1)', $spy->getSubject());
    }

    public function testSuccessfulEBPush()
    {
        $this->formatter
            ->shouldReceive('format')
            ->once()
            ->andReturn('message');

        $spy = null;
        $this->mailer
            ->shouldReceive('send')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $notifier = new EmailNotifier($this->mailer, $this->message, $this->formatter);

        $data = [
            'status' => true,
            'application' => Mockery::mock(Application::CLASS, [
                'key' => 'repokey',
                'email' => 'test@example.com'
            ]),
            'environment' => Mockery::mock(Environment::CLASS, ['name' => 'test']),
            'deployment' => (new Deployment)
                ->withServer(Mockery::mock(Server::CLASS, ['type' => ServerEnum::TYPE_EB]))
                ->withEBEnvironment('eb-env-name-1'),
            'push' => Mockery::mock(Push::CLASS)
        ];

        $notifier->send('event.name', $data);

        $this->assertSame(3, $spy->getPriority());
        $this->assertSame(['test@example.com' => null], $spy->getTo());
        $this->assertSame('[' . EmailNotifier::ICON_SUCCESS . '] repokey (test:EB:eb-env-name-1)', $spy->getSubject());
    }

    public function testSuccessfulPushWithCustomDeploymentName()
    {
        $this->formatter
            ->shouldReceive('format')
            ->once()
            ->andReturn('message');

        $spy = null;
        $this->mailer
            ->shouldReceive('send')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $notifier = new EmailNotifier($this->mailer, $this->message, $this->formatter);

        $data = [
            'status' => true,
            'application' => Mockery::mock(Application::CLASS, [
                'key' => 'repokey',
                'email' => 'test@example.com'
            ]),
            'environment' => Mockery::mock(Environment::CLASS, ['name' => 'test']),
            'deployment' => (new Deployment)
                ->withName('my custom deploy name'),
            'push' => Mockery::mock(Push::CLASS)
        ];

        $notifier->send('event.name', $data);

        $this->assertSame(3, $spy->getPriority());
        $this->assertSame(['test@example.com' => null], $spy->getTo());
        $this->assertSame('[' . EmailNotifier::ICON_SUCCESS . '] repokey (test:my custom deploy name)', $spy->getSubject());
    }

    public function testFailureBuild()
    {
        $this->formatter
            ->shouldReceive('format')
            ->once()
            ->andReturn('message');

        $spy = null;
        $this->mailer
            ->shouldReceive('send')
            ->with(Mockery::on(function($v) use (&$spy) {
                $spy = $v;
                return true;
            }));

        $notifier = new EmailNotifier($this->mailer, $this->message, $this->formatter);

        $data = [
            'status' => false,

            'application' => Mockery::mock(Application::CLASS, [
                'key' => 'repokey',
                'email' => 'test@example.com'
            ]),
            'environment' => Mockery::mock(Environment::CLASS, ['name' => 'envkey']),
            'server' => null,
            'push' => null
        ];

        $notifier->send('event.name', $data);

        $this->assertSame('1', $spy->getPriority());
        $this->assertSame(['test@example.com' => null], $spy->getTo());
        $this->assertSame('[' . EmailNotifier::ICON_FAILURE . '] repokey (envkey)', $spy->getSubject());
    }

    public function testMissingEmailDoesNotSendMessage()
    {
        $this->formatter
            ->shouldReceive('format')
            ->never();

        $notifier = new EmailNotifier($this->mailer, $this->message, $this->formatter);

        $data = [
            'status' => false,
            'application' => Mockery::mock(Application::CLASS, [
                'key' => 'repokey',
                'email' => ''
            ]),
            'environment' => Mockery::mock(Environment::CLASS, ['name' => 'envkey']),
            'server' => null,
            'push' => null
        ];

        $notifier->send('event.name', $data);
    }
}
