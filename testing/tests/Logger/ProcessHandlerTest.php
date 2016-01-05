<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Core\Entity\Application;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Process;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\JobIdGenerator;

class ProcessHandlerTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $repo;
    public $unique;

    public function setUp()
    {
        $this->repo = Mockery::mock(EntityRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->repo
        ]);
        $this->unique = Mockery::mock(JobIdGenerator::class);
    }

    public function testUnknownAbortionDoesNothing()
    {
        $this->repo
            ->shouldReceive('findBy')
            ->never();

        $handler = new ProcessHandler($this->em, $this->unique);

        $handler->abort(new \stdClass);
    }

    public function testUnknownLaunchDoesNothing()
    {
        $this->repo
            ->shouldReceive('findBy')
            ->never();

        $handler = new ProcessHandler($this->em, $this->unique);

        $handler->abort(new \stdClass);
    }

    public function testAbortAbortsChildrenAndPersists()
    {
        $parent = new Build('abcdef');

        $process1 = new Process('1234');
        $process1->withParent($parent);

        $process2 = new Process('1234');
        $process2->withParent($parent);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parent' => 'abcdef'])
            ->andReturn([$process1, $process2]);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($process2)
            ->once();

        $handler = new ProcessHandler($this->em, $this->unique);

        $this->assertSame('Pending', $process1->status());
        $this->assertSame('Pending', $process2->status());

        $handler->abort($parent);

        $this->assertSame('Aborted', $process1->status());
        $this->assertSame('Aborted', $process2->status());
    }

    public function testLaunchAbortsWeirdChildren()
    {
        $parent = new Build('abcdef');

        $process1 = new Process('1234');
        $process1->withParent($parent);

        $process2 = new Process('1234');
        $process2->withParent($parent);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parent' => 'abcdef'])
            ->andReturn([$process1, $process2]);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('merge')
            ->with($process2)
            ->once();

        $handler = new ProcessHandler($this->em, $this->unique);

        $this->assertSame('Pending', $process1->status());
        $this->assertSame('Pending', $process2->status());

        $handler->launch($parent);

        $this->assertSame('Aborted', $process1->status());
        $this->assertSame('Aborted', $process2->status());
    }

    public function testLaunchesChildPush()
    {
        $deployment = new Deployment('d1234');
        $parent = (new Build('abcdef'))
            ->withApplication(new Application);

        $process1 = new Process('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Push')
            ->withContext(['deployment' => 'd1234']);

        $process2 = new Process('1234');
        $process2->withParent($parent);

        $push = null;

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parent' => 'abcdef'])
            ->andReturn([$process1, $process2]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 'd1234', 'application' => $parent->application()])
            ->andReturn($deployment);

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
            ->with($deployment)
            ->once();
        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$push) {
                    $push = $v;
                    return true;
                })
            )
            ->once();

        $this->unique
            ->shouldReceive('generatePushId')
            ->andReturn('h5678');

        $handler = new ProcessHandler($this->em, $this->unique);

        $this->assertSame('Pending', $process1->status());
        $this->assertSame('Pending', $process2->status());

        $handler->launch($parent);

        $this->assertSame('Launched', $process1->status());
        $this->assertSame('Aborted', $process2->status());

        $this->assertInstanceOf(Push::class, $push);

        $this->assertSame('h5678', $push->id());
        $this->assertSame($parent, $push->build());
        $this->assertSame($parent->application(), $push->application());
        $this->assertSame($deployment, $push->deployment());
    }

    public function testPreventMissingDeployment()
    {
        $deployment = (new Deployment('d1234'))
            ->withPush(new Push('derp123'));

        $parent = (new Build('abcdef'));

        $process1 = new Process('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Push');

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parent' => 'abcdef'])
            ->andReturn([$process1]);

        $this->em
            ->shouldReceive('merge')
            ->with($process1)
            ->once();
        $this->em
            ->shouldReceive('persist')
            ->never();

        $handler = new ProcessHandler($this->em, $this->unique);

        $this->assertSame('Pending', $process1->status());

        $handler->launch($parent);

        $this->assertSame('Aborted', $process1->status());
        $this->assertSame('No target specified.', $process1->message());
    }

    public function testPreventInvalidDeployment()
    {
        $deployment = (new Deployment('d1234'))
            ->withPush(new Push('derp123'));

        $parent = (new Build('abcdef'))
            ->withApplication(new Application);

        $process1 = new Process('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Push')
            ->withContext(['deployment' => 'd1234']);

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parent' => 'abcdef'])
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

        $handler = new ProcessHandler($this->em, $this->unique);

        $this->assertSame('Pending', $process1->status());

        $handler->launch($parent);

        $this->assertSame('Aborted', $process1->status());
        $this->assertSame('Invalid target specified.', $process1->message());
    }

    public function testPreventClobbering()
    {
        $deployment = (new Deployment('d1234'))
            ->withPush(new Push('derp123'));

        $parent = (new Build('abcdef'))
            ->withApplication(new Application);

        $process1 = new Process('1234');
        $process1
            ->withParent($parent)
            ->withChildType('Push')
            ->withContext(['deployment' => 'd1234']);

        $process2 = new Process('1234');
        $process2->withParent($parent);

        $push = null;

        $this->repo
            ->shouldReceive('findBy')
            ->with(['parent' => 'abcdef'])
            ->andReturn([$process1, $process2]);
        $this->repo
            ->shouldReceive('findOneBy')
            ->with(['id' => 'd1234', 'application' => $parent->application()])
            ->andReturn($deployment);

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

        $handler = new ProcessHandler($this->em, $this->unique);

        $this->assertSame('Pending', $process1->status());
        $this->assertSame('Pending', $process2->status());

        $handler->launch($parent);

        $this->assertSame('Aborted', $process1->status());
        $this->assertSame('Aborted', $process2->status());

        $this->assertSame(null, $push);
        $this->assertSame('Push derp123 to target already in progress.', $process1->message());
    }
}
