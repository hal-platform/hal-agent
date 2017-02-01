<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventFactory;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use QL\MCP\Common\Time\Clock;

class EventLoggerTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $factory;
    public $handler;
    public $clock;

    public function setUp()
    {
        $this->em = Mockery::mock(EntityManager::class);
        $this->factory = Mockery::mock(EventFactory::class);
        $this->handler = Mockery::mock(ProcessHandler::class);
        $this->clock = Mockery::mock(Clock::class);
    }

    public function testEventIsPassedToFactory()
    {
        $this->factory
            ->shouldReceive('info')
            ->with('test message', ['data' => 'test1'])
            ->once();

        $logger = new EventLogger($this->em, $this->factory, $this->handler, $this->clock);

        $logger->event('info', 'test message', ['data' => 'test1']);
    }

    public function testInvalidEventStatusIsIgnored()
    {
        $this->factory
            ->shouldReceive('info')
            ->never();

        $logger = new EventLogger($this->em, $this->factory, $this->handler, $this->clock);

        $logger->event('testing');
    }

    public function testBuildIsSavedAndPersistedWhenStarted()
    {
        $build = new Build;

        $this->factory
            ->shouldReceive('setBuild')
            ->with($build)
            ->once();

        $this->clock
            ->shouldReceive('read')
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($build)
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();

        $logger = new EventLogger($this->em, $this->factory, $this->handler, $this->clock);

        $logger->start($build);
    }

    public function testPushIsSuccessAndLaunchesChildren()
    {
        $push = new Push;

        $this->factory
            ->shouldReceive('setStage')
            ->with('push.success')
            ->once();
        $this->handler
            ->shouldReceive('launch')
            ->once();

        $this->factory
            ->shouldReceive('setPush');
        $this->clock
            ->shouldReceive('read')
            ->twice();
        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');

        $logger = new EventLogger($this->em, $this->factory, $this->handler, $this->clock);

        $logger->start($push);
        $logger->success();
    }

    public function testBuildIsFailureAndAbortsChildren()
    {
        $build = new Build;

        $this->factory
            ->shouldReceive('setStage')
            ->with('build.failure')
            ->once();
        $this->handler
            ->shouldReceive('abort')
            ->once();

        $this->factory
            ->shouldReceive('setBuild');
        $this->clock
            ->shouldReceive('read')
            ->twice();
        $this->em
            ->shouldReceive('merge');
        $this->em
            ->shouldReceive('flush');

        $logger = new EventLogger($this->em, $this->factory, $this->handler, $this->clock);

        $logger->start($build);
        $logger->failure();
    }
}
