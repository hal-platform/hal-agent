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
use Hal\Core\Entity\Target;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Repository\JobType\ReleaseRepository;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class DeployCommandTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $releaseRepo;
    public $em;

    public $processRunner;
    public $process;
    public $logger;

    public function setUp()
    {
        $this->releaseRepo = Mockery::mock(ReleaseRepository::class);
        $this->em = Mockery::mock(EntityManager::class, [
            'getRepository' => $this->releaseRepo
        ]);

        $this->processRunner = Mockery::mock(ProcessRunner::class)->makePartial();
        $this->process = Mockery::mock(Process::class, ['stop' => null]);
        $this->logger = new MemoryLogger;
    }

    public function configureCommand($c)
    {
        DeployCommand::configure($c);
    }

    public function testNoPushesFound()
    {
        $this->markTestSkipped();

        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new DeployCommand(
            $this->em,
            $this->processRunner,
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
        $this->markTestSkipped();

        $push1 = (new Release('1234'))
            ->withTarget(new Target(null, '6666'));

        $push2 = (new Release('5555'))
            ->withTarget(new Target(null, '8888'));

        $push3 = (new Release('6789'))
            ->withTarget(new Target(null, '0000'));

        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1, $push2, $push3]);

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

        $command = new DeployCommand(
            $this->em,
            $this->processRunner,
            $this->logger,
            'workdir'
        );
        $command->setSleepTime(1);

        $io = $this->ioForCommand('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Found 3 releases:',
            ' * Starting release: 1234',
            '   > bin/hal runner:deploy 1234',
            ' * Starting release: 5555',
            '   > bin/hal runner:deploy 5555',
            ' * Starting release: 6789',
            '   > bin/hal runner:deploy 6789',

            'Release 1234 finished: success',

            '[NOTE] Checking release status: <info>5555</info>',
            '[NOTE] Checking release status: <info>6789</info>',
            '[NOTE] Waiting 1 seconds...',
            'Release 5555 finished: error',

            'Release 6789 finished: timed out',
        ];

        $this->assertContainsLines($expected, $this->output());
    }

    public function testOutputWithPushWithoutDeployment()
    {
        $this->markTestSkipped();

        $push1 = new Release('1234');

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
            $this->processRunner,
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
        $this->markTestSkipped();

        $deploy1 = new Target(null, '6666');

        $push1 = (new Release('1234'))
            ->withTarget($deploy1);

        $push2 = (new Release('5555'))
            ->withTarget($deploy1);

        $this->releaseRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1, $push2]);

        $this->processRunner
            ->shouldReceive('prepare')
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
            ->shouldReceive('isSuccessful')
            ->times(1)
            ->andReturn(true);
        $this->process
            ->shouldReceive([
                'getCommandLine' => 'command line args',
                'getOutput' => 'output here',
                'getErrorOutput' => '',
                'getExitCode' => 0,
                'checkTimeout' => null
            ]);

        $command = new DeployCommand(
            $this->em,
            $this->processRunner,
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
