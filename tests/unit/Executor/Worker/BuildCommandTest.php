<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Worker;

use Doctrine\ORM\EntityManager;
use Hal\Agent\Symfony\ProcessRunner;
use Hal\Agent\Testing\IOTestCase;
use Hal\Agent\Testing\MemoryLogger;
use Mockery;
use Hal\Core\Entity\JobType\Build;
use Hal\Core\Repository\JobType\BuildRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class BuildCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $em;
    public $buildRepo;

    public $processRunner;
    public $process;
    public $logger;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock(BuildRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->buildRepo
        ]);

        $this->processRunner = Mockery::mock(ProcessRunner::class)->makePartial();
        $this->process = Mockery::mock(Process::class, ['stop' => null]);
        $this->logger = new MemoryLogger();
    }

    public function configureCommand($c)
    {
        BuildCommand::configure($c);
    }

    public function testNoBuildsFound()
    {
        $this->markTestSkipped();

        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new BuildCommand(
            $this->em,
            $this->processRunner,
            $this->logger,
            'workdir'
        );

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            '[NOTE] No pending builds found.',
            '[OK] All pending builds were completed.'
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testOutputWithMultipleBuilds()
    {
        $this->markTestSkipped();

        $build1 = new Build('1234');
        $build2 = new Build('5555');
        $build3 = new Build('6789');

        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturn([$build1, $build2, $build3]);

        $this->processRunner
            ->shouldReceive('prepare')
            ->times(3)
            ->andReturn($this->process);

        $this->process
            ->shouldReceive([
                'getCommandLine' => 'command args here',
                'getOutput' => 'output here',
                'getErrorOutput' => '',
                'getTimeout' => 1
            ]);
        $this->process
            ->shouldReceive('start')
            ->times(3);
        $this->process
            ->shouldReceive('isRunning')
            ->times(6)
            ->andReturn(false, true, true, false, true, true);
        $this->process
            ->shouldReceive('checkTimeout')
            ->times(3);
        $this->process
            ->shouldReceive('checkTimeout')
            ->andThrow(new ProcessTimedOutException($this->process, ProcessTimedOutException::TYPE_GENERAL));
        $this->process
            ->shouldReceive('isSuccessful')
            ->times(2)
            ->andReturn(true, false);
        $this->process
            ->shouldReceive('getExitCode')
            ->times(4)
            ->andReturn(0, 1, 1, null);

        $command = new BuildCommand(
            $this->em,
            $this->processRunner,
            $this->logger,
            'workdir'
        );
        $command->setSleepTime(1);

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Found 3 builds:',
            ' * Starting build: 1234',
            '   > bin/hal runner:build 1234',
            ' * Starting build: 5555',
            '   > bin/hal runner:build 5555',
            ' * Starting build: 6789',
            '   > bin/hal runner:build 6789',

            'Build 1234 finished: success',

            '[NOTE] Checking build status: <info>5555</info>',
            '[NOTE] Checking build status: <info>6789</info>',
            '[NOTE] Waiting 1 seconds...',
            'Build 5555 finished: error',

            'Build 6789 finished: timed out'
        ];

        $this->assertContainsLines($expected, $this->output());
    }
}
