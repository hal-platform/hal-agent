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

class CommandLoggerTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $handler;
    public $env;
    public $repo;

    public function setUp()
    {
        $this->logger = new MemoryLogger;
        $this->handler = Mockery::mock('Monolog\Handler\BufferHandler');

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

        $logger = new CommandLogger($this->logger, $this->handler);
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

        $logger = new CommandLogger($this->logger, $this->handler);
        $logger->success($build);

        $message = $this->logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('repo (unittest) - Build - Success', $message[1]);
    }

    public function testFailureMessageIsBuiltAndHandlerFlushed()
    {
        $server = new Server;
        $server->setname('servername');
        $server->setEnvironment($this->env);
        $deploy = new Deployment;
        $deploy->setServer($server);
        $deploy->setRepository($this->repo);

        $push = new Push;
        $push->setDeployment($deploy);

        $this->handler
            ->shouldReceive('flush')
            ->once();

        $logger = new CommandLogger($this->logger, $this->handler);
        $logger->failure($push);

        $message = $this->logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('repo (unittest:servername) - Push - Failure', $message[1]);
    }
}
