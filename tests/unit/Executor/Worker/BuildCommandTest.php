<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Worker;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Testing\ExecutorTestCase;
use Hal\Agent\Testing\MemoryLogger;
use Mockery;
use Hal\Core\Entity\Build;
use Hal\Core\Repository\BuildRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class BuildCommandTest extends ExecutorTestCase
{
    use MockeryPHPUnitIntegration;

    public $em;
    public $buildRepo;

    public $builder;
    public $process;
    public $logger;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->buildRepo
        ]);

        $this->builder = Mockery::mock(ProcessBuilder::class);
        $this->process = Mockery::mock(Process::class, ['stop' => null]);
        $this->logger = new MemoryLogger;
    }

    public function configureCommand($c)
    {
        BuildCommand::configure($c);
    }

    public function testNoBuildsFound()
    {
        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new BuildCommand(
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $io = $this->io('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            '[NOTE] No pending builds found.',
            '[OK] All pending builds were completed.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testOutputWithMultipleBuilds()
    {
        $build1 = new Build('1234');
        $build2 = new Build('5555');

        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturn([$build1, $build2]);

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
            ->times(3)
            ->andReturn(false, true, false);
        $this->process
            ->shouldReceive('checkTimeout')
            ->once();
        $this->process
            ->shouldReceive('getExitCode')
            ->times(4)
            ->andReturn(0, 0, 1, 1);
        $this->process
            ->shouldReceive([
                'getOutput' => 'output here',
                'getErrorOutput' => ''
            ]);

        $command = new BuildCommand(
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );
        $command->setSleepTime(1);

        $io = $this->io('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Found 2 builds:',
            ' * Starting build: 1234',
            '   > bin/hal runner:build 1234',
            ' * Starting build: 5555',
            '   > bin/hal runner:build 5555',

            'Build 1234 finished: success',

            '[NOTE] Checking build status: <info>5555</info>',
            '[NOTE] Waiting 1 seconds...',
            'Build 5555 finished: error'
        ];

        $this->assertContainsLines($expected, $this->output());
    }
}
