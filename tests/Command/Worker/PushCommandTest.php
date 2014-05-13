<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command\Worker;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Helper\MemoryLogger;
use QL\Hal\Core\Entity\Push;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $pushRepo;
    public $em;
    public $forker;

    public $connection;
    public $application;
    public $command;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = new MemoryLogger;
        $this->pushRepo = Mockery::mock('QL\Hal\Core\Entity\Repository\PushRepository');
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->forker = Mockery::mock('QL\Hal\Agent\Helper\ForkHelper');

        $this->connection = Mockery::mock('Doctrine\DBAL\Connection');

        // omgwtfbbq
        $this->application = Mockery::mock('Symfony\Component\Console\Application', [
            'getHelperSet' => Mockery::mock('Symfony\Component\Console\Helper\HelperSet'),
            'getDefinition' => Mockery::mock('Symfony\Component\Console\Input\InputDefinition', [
                'getArguments' => [],
                'getOptions' => []
            ])
        ]);
        $this->command = Mockery::mock('Symfony\Component\Console\Command\Command');

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
            'push-cmd',
            $this->logger,
            $this->pushRepo,
            $this->em,
            $this->forker
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
No waiting pushes found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testConnectionIsResetWhenPushForked()
    {
        $push1 = new Push;
        $push1->setId('1234');

        $this->pushRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1]);

        $this->application
            ->shouldReceive('find')
            ->andReturn($this->command);

        // fork
        $this->forker
            ->shouldReceive('fork')
            ->andReturn(0);

        // db connection
        $this->em
            ->shouldReceive('getConnection')
            ->andReturn($this->connection);
        $this->connection
            ->shouldReceive('close')
            ->once();
        $this->connection
            ->shouldReceive('connect')
            ->once();

        $this->command
            ->shouldReceive('run')
            ->andReturn(0);

        $command = new PushCommand(
            'cmd',
            'push-cmd',
            $this->logger,
            $this->pushRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Waiting pushes: 1
Starting push workers...

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(0, $exitCode);
    }

    public function testParentOutputWithMultipleBuilds()
    {
        $push1 = new Push;
        $push1->setId('1234');
        $push2 = new Push;
        $push2->setId('5555');

        $this->pushRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1, $push2]);

        $this->application
            ->shouldReceive('find')
            ->andReturn($this->command);

        // fork
        $this->forker
            ->shouldReceive('fork')
            ->andReturn(1)
            ->once();
        $this->forker
            ->shouldReceive('fork')
            ->andReturn(2)
            ->once();

        // parent never resets connection
        $this->em
            ->shouldReceive('getConnection')
            ->never();

        $command = new PushCommand(
            'cmd',
            'push-cmd',
            $this->logger,
            $this->pushRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Waiting pushes: 2
Starting push workers...
Push ID 1234 started.
Push ID 5555 started.
All waiting pushes have been started.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(0, $exitCode);
    }

    public function testParentOutputWithForkFailure()
    {
        $push1 = new Push;
        $push1->setId('1234');
        $push2 = new Push;
        $push2->setId('5555');

        $this->pushRepo
            ->shouldReceive('findBy')
            ->andReturn([$push1, $push2]);

        $this->application
            ->shouldReceive('find')
            ->andReturn($this->command);

        // fork
        $this->forker
            ->shouldReceive('fork')
            ->andReturn(1)
            ->once();
        $this->forker
            ->shouldReceive('fork')
            ->andReturn(-1)
            ->once();

        // parent never resets connection
        $this->em
            ->shouldReceive('getConnection')
            ->never();

        $command = new PushCommand(
            'cmd',
            'push-cmd',
            $this->logger,
            $this->pushRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Waiting pushes: 2
Starting push workers...
Push ID 1234 started.
Could not fork a push worker.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(1, $exitCode);
    }

    public function testPushCommandNotFound()
    {
        $this->pushRepo
            ->shouldReceive('findBy')
            ->andReturn([new Push]);

        $this->application
            ->shouldReceive('find')
            ->andReturnNull();

        $command = new PushCommand(
            'cmd',
            'push-cmd',
            $this->logger,
            $this->pushRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Push Command not found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(2, $exitCode);
    }
}