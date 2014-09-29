<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Environment;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Repository;
use QL\Hal\Core\Entity\Server;
use Swift_Message;

class CommandLoggerTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $handler;
    public $message;
    public $env;
    public $repo;

    public function setUp()
    {
        $this->logger = new MemoryLogger;
        $this->handler = Mockery::mock('Monolog\Handler\BufferHandler');
        $this->message = new Swift_Message; # this is unmockable

        $this->env = new Environment;
        $this->env->setKey('unittest');

        $this->repo = new Repository;
        $this->repo->setKey('repo');
    }

    public function testInvalidEntityDoesNothing()
    {
        $this->handler
            ->shouldReceive('flush')
            ->never();

        $logger = new CommandLogger($this->logger, $this->handler, $this->message);
        $logger->success('what');
    }

    public function testSuccessMessageIsBuiltAndHandlerFlushed()
    {
        $build = new Build;
        $build->setEnvironment($this->env);
        $build->setRepository($this->repo);

        $this->handler
            ->shouldReceive('flush')
            ->once();
        $logger = new CommandLogger($this->logger, $this->handler, $this->message);
        $logger->success($build);

        $message = $this->logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('repo (unittest)', $message[1]);

        $this->assertSame('repo (unittest)', $this->message->getSubject());
    }

    public function testFailureMessageIsBuiltAndHandlerFlushed()
    {
        $server = new Server;
        $server->setname('servername');

        $deploy = new Deployment;
        $deploy->setServer($server);

        $build = new Build;
        $build->setEnvironment($this->env);
        $build->setRepository($this->repo);
        $build->setEnvironment($this->env);

        $push = new Push;
        $push->setDeployment($deploy);
        $push->setBuild($build);

        $this->handler
            ->shouldReceive('flush')
            ->once();

        $logger = new CommandLogger($this->logger, $this->handler, $this->message);
        $logger->failure($push);

        $message = $this->logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('repo (unittest:servername)', $message[1]);

        $this->assertSame('repo (unittest:servername)', $this->message->getSubject());
    }
}
