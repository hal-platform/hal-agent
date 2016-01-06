<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Command\Worker;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Testing\MemoryLogger;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $em;
    public $buildRepo;

    public $builder;
    public $process;
    public $logger;

    public $input;
    public $output;

    public function setUp()
    {
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Repository\BuildRepository');
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager', [
            'getRepository' => $this->buildRepo
        ]);

        $this->builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');
        $this->process = Mockery::mock('Symfony\Component\Process\Process', ['stop' => null]);
        $this->logger = new MemoryLogger;

        $this->input = new StringInput('');
        $this->output = new BufferedOutput;
    }

    public function testNoBuildsFound()
    {
        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new BuildCommand(
            'cmd',
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $command->run($this->input, $this->output);

        $expected = [
            'No waiting builds found.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testOutputWithMultipleBuilds()
    {
        $build1 = new Build;
        $build1->withId('1234');
        $build2 = new Build;
        $build2->withId('5555');

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
            'cmd',
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );
        $command->setSleepTime(1);
        $command->run($this->input, $this->output);

        $expected = [
            '[Worker] Waiting builds: 2',
            '[Worker] Starting build: 1234',
            '[Worker] Starting build: 5555',
            'Build 1234 finished: success',

            '[Worker] Checking build status: 5555',
            '[Worker] Waiting 1 seconds...',
            'Build 5555 finished: error'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }
}
