<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Release;
use Mockery;
use QL\MCP\Common\Time\Clock;

class EventLoggerTest extends MockeryTestCase
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

    public function testReleaseIsSuccessAndLaunchesChildren()
    {
        $push = new Release;

        $this->factory
            ->shouldReceive('setStage')
            ->with('release.success')
            ->once();
        $this->handler
            ->shouldReceive('launch')
            ->once();

        $this->factory
            ->shouldReceive('setRelease');
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
