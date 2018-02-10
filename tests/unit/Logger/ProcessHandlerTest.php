<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Entity\ScheduledAction;
use Hal\Core\Entity\Target;

class ProcessHandlerTest extends MockeryTestCase
{
    public $em;
    public $repo;

    public function setUp()
    {
        $this->repo = Mockery::mock(EntityRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->repo,
            'merge' => null,
            'flush' => null
        ]);
    }

    public function testNoscheduledJobsDoesNothingOnAbort()
    {
        $job = new Job;

        $this->repo
            ->shouldReceive('findBy')
            ->with(['triggerJob' => $job, 'status' => 'pending'])
            ->once();

        $handler = new ProcessHandler($this->em);

        $actual = $handler->abort($job);

        $this->assertSame(false, $actual);
    }

    public function testNoscheduledJobsDoesNothingOnLaunch()
    {
        $job = new Job;

        $this->repo
            ->shouldReceive('findBy')
            ->with(['triggerJob' => $job, 'status' => 'pending'])
            ->once();

        $handler = new ProcessHandler($this->em);

        $actual = $handler->launch($job);

        $this->assertSame(false, $actual);
    }

    public function testAbortStopsScheduledActiosnAndSaves()
    {
        $job = new Build;

        $scheduled1 = (new ScheduledAction)
            ->withTriggerJob($job);
        $scheduled2 = (new ScheduledAction)
            ->withTriggerJob($job);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['triggerJob' => $job, 'status' => 'pending'])
            ->andReturn([$scheduled1, $scheduled2]);

        $this->em
            ->shouldReceive('merge')
            ->with($scheduled1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($scheduled2)
            ->once();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $scheduled1->status());
        $this->assertSame('pending', $scheduled2->status());

        $handler->abort($job);

        $this->assertSame('aborted', $scheduled1->status());
        $this->assertSame('aborted', $scheduled2->status());
    }

    public function testLaunchAbortsUnhandledScheduledActions()
    {
        $job = new Build;

        $scheduled1 = (new ScheduledAction)
            ->withTriggerJob($job);
        $scheduled2 = (new ScheduledAction)
            ->withTriggerJob($job);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['triggerJob' => $job, 'status' => 'pending'])
            ->andReturn([$scheduled1, $scheduled2]);

        $this->em
            ->shouldReceive('merge')
            ->with($scheduled1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($scheduled2)
            ->once();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $scheduled1->status());
        $this->assertSame('pending', $scheduled2->status());

        $handler->launch($job);

        $this->assertSame('aborted', $scheduled1->status());
        $this->assertSame('aborted', $scheduled2->status());
    }

    public function testLaunchesScheduledRelease()
    {
        $target = new Target('', 't1234');
        $job = (new Build)
            ->withApplication(new Application);

        $scheduled1 = (new ScheduledAction)
            ->withTriggerJob($job)
            ->withParameter('entity', 'Release');
        $scheduled2 = (new ScheduledAction)
            ->withTriggerJob($job)
            ->withParameter('entity', 'Release')
            ->withParameter('target_id', 't1234');

        $this->repo
            ->shouldReceive('findBy')
            ->with(['triggerJob' => $job, 'status' => 'pending'])
            ->andReturn([$scheduled1, $scheduled2]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 't1234', 'application' => $job->application()])
            ->andReturn($target);

        [$spyWith, $releaseSpy] = $this->spy();
        $this->em
            ->shouldReceive('persist')
            ->with($spyWith)
            ->once();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $scheduled1->status());
        $this->assertSame('pending', $scheduled2->status());

        $handler->launch($job);

        $this->assertSame('aborted', $scheduled1->status());
        $this->assertSame('launched', $scheduled2->status());


        $this->assertSame('No target specified', $scheduled1->message());
        $this->assertSame('Scheduled job launched successfully', $scheduled2->message());

        $release = $releaseSpy();

        $this->assertInstanceOf(Release::class, $release);

        $this->assertSame($job, $release->build());
        $this->assertSame($job->application(), $release->application());
        $this->assertSame($target, $release->target());
    }

    public function testPreventClobbering()
    {
        $target = (new Target('', 't1234'))
            ->withLastJob(new Release('derp123'));

        $job = (new Build)
            ->withApplication(new Application);

        $scheduled1 = (new ScheduledAction)
            ->withTriggerJob($job)
            ->withParameter('entity', 'Release')
            ->withParameter('target_id', 't1234');

        $this->repo
            ->shouldReceive('findBy')
            ->with(['triggerJob' => $job, 'status' => 'pending'])
            ->andReturn([$scheduled1]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 't1234', 'application' => $job->application()])
            ->andReturn($target);

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $scheduled1->status());

        $handler->launch($job);

        $this->assertSame('aborted', $scheduled1->status());
        $this->assertSame('Release derp123 to target already in progress', $scheduled1->message());
    }
}
