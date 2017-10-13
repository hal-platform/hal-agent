<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Core\Entity\Application;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\JobProcess;
use Hal\Core\Entity\Release;

class ProcessHandlerTest extends MockeryTestCase
{
    public $em;
    public $repo;

    public function setUp()
    {
        $this->repo = Mockery::mock(EntityRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->repo
        ]);
    }

    public function testUnknownAbortionDoesNothing()
    {
        $this->repo
            ->shouldReceive('findBy')
            ->never();

        $handler = new ProcessHandler($this->em);

        $handler->abort(new \stdClass);
    }

    public function testUnknownLaunchDoesNothing()
    {
        $this->repo
            ->shouldReceive('findBy')
            ->never();

        $handler = new ProcessHandler($this->em);

        $handler->abort(new \stdClass);
    }

    public function testAbortAbortsChildrenAndPersists()
    {
        $parent = new Build('abcdef');

        $process1 = new JobProcess('1234');
        $process1->withParent($parent);

        $process2 = new JobProcess('1234');
        $process2->withParent($parent);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parentID' => 'abcdef'])
            ->andReturn([$process1, $process2]);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($process2)
            ->once();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $process1->status());
        $this->assertSame('pending', $process2->status());

        $handler->abort($parent);

        $this->assertSame('aborted', $process1->status());
        $this->assertSame('aborted', $process2->status());
    }

    public function testLaunchAbortsWeirdChildren()
    {
        $parent = new Build('abcdef');

        $process1 = new JobProcess('1234');
        $process1->withParent($parent);

        $process2 = new JobProcess('1234');
        $process2->withParent($parent);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parentID' => 'abcdef'])
            ->andReturn([$process1, $process2]);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($process2)
            ->once();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $process1->status());
        $this->assertSame('pending', $process2->status());

        $handler->launch($parent);

        $this->assertSame('aborted', $process1->status());
        $this->assertSame('aborted', $process2->status());
    }

    public function testLaunchesChildPush()
    {
        $target = new Target('d1234');
        $parent = (new Build('abcdef'))
            ->withApplication(new Application);

        $process1 = new JobProcess('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Release')
            ->withParameters(['deployment' => 'd1234']);

        $process2 = new JobProcess('1234');
        $process2->withParent($parent);

        $release = null;

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parentID' => 'abcdef'])
            ->andReturn([$process1, $process2]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 'd1234', 'application' => $parent->application()])
            ->andReturn($target);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($process2)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($target)
            ->once();
        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$release) {
                    $release = $v;
                    return true;
                })
            )
            ->once();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $process1->status());
        $this->assertSame('pending', $process2->status());

        $handler->launch($parent);

        $this->assertSame('launched', $process1->status());
        $this->assertSame('aborted', $process2->status());

        $this->assertInstanceOf(Release::class, $release);

        $this->assertSame($parent, $release->build());
        $this->assertSame($parent->application(), $release->application());
        $this->assertSame($target, $release->target());
    }

    public function testPreventMissingDeployment()
    {
        $target = (new Target('d1234'))
            ->withRelease(new Release('derp123'));

        $parent = (new Build('abcdef'));

        $process1 = new JobProcess('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Release');

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parentID' => 'abcdef'])
            ->andReturn([$process1]);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('persist')
            ->never();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $process1->status());

        $handler->launch($parent);

        $this->assertSame('aborted', $process1->status());
        $this->assertSame('No target specified', $process1->message());
    }

    public function testPreventInvalidDeployment()
    {
        $target = (new Target('d1234'))
            ->withRelease(new Release('derp123'));

        $parent = (new Build('abcdef'))
            ->withApplication(new Application);

        $process1 = new JobProcess('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Release')
            ->withParameters(['deployment' => 'd1234']);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parentID' => 'abcdef'])
            ->andReturn([$process1]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 'd1234', 'application' => $parent->application()])
            ->andReturnNull();

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('persist')
            ->never();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $process1->status());

        $handler->launch($parent);

        $this->assertSame('aborted', $process1->status());
        $this->assertSame('Invalid target specified', $process1->message());
    }

    public function testPreventClobbering()
    {
        $target = (new Target('d1234'))
            ->withRelease(new Release('derp123'));

        $parent = (new Build('abcdef'))
            ->withApplication(new Application);

        $process1 = new JobProcess('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Release')
            ->withParameters(['deployment' => 'd1234']);

        $process2 = new JobProcess('1234');
        $process2->withParent($parent);

        $push = null;

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parentID' => 'abcdef'])
            ->andReturn([$process1, $process2]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 'd1234', 'application' => $parent->application()])
            ->andReturn($target);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($process2)
            ->once();
        $this->em
            ->shouldReceive('persist')
            ->never();

        $handler = new ProcessHandler($this->em);

        $this->assertSame('pending', $process1->status());
        $this->assertSame('pending', $process2->status());

        $handler->launch($parent);

        $this->assertSame('aborted', $process1->status());
        $this->assertSame('aborted', $process2->status());

        $this->assertSame(null, $push);
        $this->assertSame('Release derp123 to target already in progress', $process1->message());
    }
}
