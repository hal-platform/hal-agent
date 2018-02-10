<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Worker;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\IOTestCase;
use Hal\Agent\Testing\MemoryLogger;
use Mockery;
use Hal\Core\Entity\Target;
use Hal\Core\Entity\Release;
use Hal\Core\Repository\ReleaseRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class DeployCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $releaseRepo;
    public $em;

    public $builder;
    public $process;
    public $logger;

    public function setUp()
    {
        $this->releaseRepo = Mockery::mock(ReleaseRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->releaseRepo
        ]);
        $this->builder = Mockery::mock(ProcessBuilder::class);
        $this->process = Mockery::mock(Process::class, ['stop' => null]);
        $this->logger = new MemoryLogger;
    }

    public function configureCommand($c)
    {
        DeployCommand::configure($c);
    }

    public function testNoPushesFound()
    {
        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new DeployCommand(
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            '[NOTE] No pending releases found.',
            '[OK] All pending deployments were completed.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testOutputWithMultipleBuilds()
    {
        $push1 = (new Release('1234'))
            ->withTarget(
                (new Target())
                    ->withId('6666')
            );

        $push2 = (new Release('5555'))
            ->withTarget(
                (new Target())
                    ->withId('8888')
            );

        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1, $push2]);

        $this->builder
            ->shouldReceive([
                'setWorkingDirectory' => $this->builder,
                'setArguments' => $this->builder,
                'setTimeout' => $this->builder
            ]);
        $this->builder
            ->shouldReceive('getProcess')
            ->times(2)
            ->andReturn($this->process);

        $this->process
            ->shouldReceive('start')
            ->times(2);
        $this->process
            ->shouldReceive('isRunning')
            ->times(2)
            ->andReturn(false);
        $this->process
            ->shouldReceive('getExitCode')
            ->times(4)
            ->andReturn(0, 0, 1, 1);
        $this->process
            ->shouldReceive([
                'getOutput' => 'output here',
                'getErrorOutput' => ''
            ]);

        $command = new DeployCommand(
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Found 2 releases:',
            ' * Starting release: 1234',
            '   > bin/hal runner:deploy 1234',
            ' * Starting release: 5555',
            '   > bin/hal runner:deploy 5555',

            'Release 1234 finished: success',
            'Release 5555 finished: error',
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testOutputWithPushWithoutDeployment()
    {
        $push1 = new Release();
        $push1->withId('1234');

        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1]);

        $this->em
            ->shouldReceive('merge')
            ->with($push1)
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();

        $command = new DeployCommand(
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Found 1 releases:',
            ' * Skipping release: 1234',
            '   > Release 1234 has no target. Marking as failure.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testOutputWhenDuplicateDeploymentSkipsPush()
    {
        $deploy1 = (new Target())
            ->withId('6666');

        $push1 = (new Release())
            ->withId('1234')
            ->withTarget($deploy1);

        $push2 = (new Release())
            ->withId('5555')
            ->withTarget($deploy1);

        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1, $push2]);

        $this->builder
            ->shouldReceive([
                'setWorkingDirectory' => $this->builder,
                'setArguments' => $this->builder,
                'setTimeout' => $this->builder
            ]);
        $this->builder
            ->shouldReceive('getProcess')
            ->once()
            ->andReturn($this->process);

        $this->process
            ->shouldReceive('start')
            ->once();
        $this->process
            ->shouldReceive('isRunning')
            ->once()
            ->andReturn(false);
        $this->process
            ->shouldReceive([
                'getOutput' => 'output here',
                'getErrorOutput' => '',
                'getExitCode' => 0
            ]);

        $command = new DeployCommand(
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            ' Found 2 releases:',
            ' * Starting release: 1234',
            '   > bin/hal runner:deploy 1234',
            ' * Skipping release: 5555',
            '   > A release to target 6666 is already in progress.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }
}
