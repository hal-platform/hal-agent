<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Notifier;

use Mockery;
use PHPUnit_Framework_TestCase;
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

    public function testSuccessfulPush()
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
            'repository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => 'repokey',
                'getEmail' => 'test@example.com'
            ]),
            'environment' => Mockery::mock('QL\Hal\Core\Entity\Environment', ['getKey' => 'envkey']),
            'server' => Mockery::mock('QL\Hal\Core\Entity\Server', ['getName' => 'servername']),
            'push' => Mockery::mock('QL\Hal\Core\Entity\Push')
        ];

        $notifier->send('event.name', $data);

        $this->assertSame(3, $spy->getPriority());
        $this->assertSame(['test@example.com' => null], $spy->getTo());
        $this->assertSame('[' . EmailNotifier::ICON_SUCCESS . '] repokey (envkey:servername)', $spy->getSubject());
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
            'repository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => 'repokey',
                'getEmail' => 'test@example.com'
            ]),
            'environment' => Mockery::mock('QL\Hal\Core\Entity\Environment', ['getKey' => 'envkey']),
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
            'repository' => Mockery::mock('QL\Hal\Core\Entity\Repository', [
                'getKey' => 'repokey',
                'getEmail' => ''
            ]),
            'environment' => Mockery::mock('QL\Hal\Core\Entity\Environment', ['getKey' => 'envkey']),
            'server' => null,
            'push' => null
        ];

        $notifier->send('event.name', $data);
    }
}
