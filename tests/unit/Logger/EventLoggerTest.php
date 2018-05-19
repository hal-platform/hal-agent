<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\Target;
use Mockery;
use QL\MCP\Common\Clock;
use QL\MCP\Common\Time\TimePoint;

class EventLoggerTest extends MockeryTestCase
{
    public $em;
    public $handler;
    public $clock;

    public function setUp()
    {
        $this->em = Mockery::mock(EntityManager::class, [
            'merge' => null,
            'flush' => null
        ]);

        $this->handler = Mockery::mock(ProcessHandler::class);
        $this->meta = Mockery::mock(MetadataHandler::class);

        $this->clock = Mockery::mock(Clock::class, [
            'read' => new TimePoint(2018, 1, 15, 12, 30, 45, 'UTC')
        ]);
    }

    public function testNoEventCreatedWithNoJob()
    {
        $logger = new EventLogger($this->em, $this->handler, $this->meta, $this->clock);

        $actual = $logger->event('info', 'test message', ['data' => 'test1']);

        $this->assertSame(null, $actual);
    }

    public function testBuildIsSavedAndPersistedWhenStarted()
    {
        $build = new Build;

        $this->em
            ->shouldReceive('merge')
            ->with($build)
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();

        $logger = new EventLogger($this->em, $this->handler, $this->meta, $this->clock);
        $logger->start($build);

        $this->assertSame('running', $build->status());
        $this->assertSame('2018-01-15T12:30:45Z', $build->start()->jsonSerialize());
    }

    public function testNoEventCreatedWithInvalidStatus()
    {
        $build = new Build;

        $logger = new EventLogger($this->em, $this->handler, $this->meta, $this->clock);
        $logger->start($build);

        $actual = $logger->event('testing', 'test message');

        $this->assertSame(null, $actual);
    }

    public function testReleaseIsSuccessAndLaunchesChildren()
    {
        $release = new Release;
        $release->withTarget(new Target);

        $this->handler
            ->shouldReceive('launch')
            ->with($release)
            ->once();

        $logger = new EventLogger($this->em, $this->handler, $this->meta, $this->clock);

        $logger->start($release);
        $logger->success();

        $this->assertSame('success', $release->status());
    }

    public function testBuildIsFailureAndAbortsChildren()
    {
        $build = new Build;

        $this->handler
            ->shouldReceive('abort')
            ->with($build)
            ->once();

        $logger = new EventLogger($this->em, $this->handler, $this->meta, $this->clock);

        $logger->start($build);
        $logger->failure();

        $this->assertSame('failure', $build->status());
    }
}
