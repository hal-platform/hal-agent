<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Worker;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Testing\MemoryLogger;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends PHPUnit_Framework_TestCase
{
    public $pushRepo;
    public $em;

    public $builder;
    public $process;
    public $logger;

    public $input;
    public $output;

    public function setUp()
    {
        $this->pushRepo = Mockery::mock('QL\Hal\Core\Repository\PushRepository');
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager', [
            'getRepository' => $this->pushRepo
        ]);
        $this->builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');
        $this->process = Mockery::mock('Symfony\Component\Process\Process', ['stop' => null]);
        $this->logger = new MemoryLogger;

        $this->input = new StringInput('');
        $this->output = new BufferedOutput;
    }

    public function testNoPushesFound()
    {
        $this->pushRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new PushCommand(
            'cmd',
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );

        $command->run($this->input, $this->output);

        $this->assertContains('No waiting pushes found.', $this->output->fetch());
    }

    public function testOutputWithMultipleBuilds()
    {
        $deploy1 = new Deployment;
        $deploy1->setId('6666');
        $deploy2 = new Deployment;
        $deploy2->setId('8888');

        $push1 = new Push;
        $push1->setId('1234');
        $push1->setDeployment($deploy1);
        $push2 = new Push;
        $push2->setId('5555');
        $push2->setDeployment($deploy2);

        $this->pushRepo
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
            ->times(2)
            ->andReturn(0, 1);
        $this->process
            ->shouldReceive([
                'getOutput' => 'output here',
                'getErrorOutput' => ''
            ]);

        $command = new PushCommand(
            'cmd',
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );
        $command->run($this->input, $this->output);

        $expected = [
            '[Worker] Waiting pushes: 2',
            '[Worker] Starting push: 1234',
            '[Worker] Starting push: 5555',
            'Build 1234 finished: success',
            'Build 5555 finished: error',
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testOutputWithPushWithoutDeployment()
    {
        $push1 = new Push;
        $push1->setId('1234');

        $this->pushRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1]);

        $this->em
            ->shouldReceive('merge')
            ->with($push1)
            ->once();
        $this->em
            ->shouldReceive('flush')
            ->once();

        $command = new PushCommand(
            'cmd',
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );
        $command->run($this->input, $this->output);

        $expected = [
            '[Worker] Waiting pushes: 1',
            '[Worker] Push 1234 has no deployment. Marking as error.',
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }

    public function testOutputWhenDuplicateDeploymentSkipsPush()
    {
        $deploy1 = new Deployment;
        $deploy1->setId('6666');

        $push1 = new Push;
        $push1->setId('1234');
        $push1->setDeployment($deploy1);
        $push2 = new Push;
        $push2->setId('5555');
        $push2->setDeployment($deploy1);

        $this->pushRepo
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

        $command = new PushCommand(
            'cmd',
            $this->em,
            $this->builder,
            $this->logger,
            'workdir'
        );
        $command->run($this->input, $this->output);

        $expected = [
            '[Worker] Waiting pushes: 2',
            '[Worker] Starting push: 1234',
            '[Worker] Skipping push: 5555 - A push to deployment 6666 is already running',
            'Build 1234 finished: success'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }
    }
}
