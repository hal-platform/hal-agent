<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Helper\MemoryLogger;
use QL\Hal\Core\Entity\Build;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\BufferedOutput;

class WorkerBuildCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $buildRepo;
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
        $this->buildRepo = Mockery::mock('QL\Hal\Core\Entity\Repository\BuildRepository');
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

    public function testNoBuildsFound()
    {
        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturnNull();

        $command = new WorkerBuildCommand(
            'cmd',
            'build-cmd',
            $this->logger,
            $this->buildRepo,
            $this->em,
            $this->forker
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
No waiting builds found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testConnectionIsResetWhenBuildForked()
    {
        $build1 = new Build;
        $build1->setId('1234');

        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturn([$build1]);

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

        $command = new WorkerBuildCommand(
            'cmd',
            'build-cmd',
            $this->logger,
            $this->buildRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Waiting builds: 1
Starting build workers...

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(0, $exitCode);
    }

    public function testParentOutputWithMultipleBuilds()
    {
        $build1 = new Build;
        $build1->setId('1234');
        $build2 = new Build;
        $build2->setId('5555');

        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturn([$build1, $build2]);

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

        $command = new WorkerBuildCommand(
            'cmd',
            'build-cmd',
            $this->logger,
            $this->buildRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Waiting builds: 2
Starting build workers...
Build ID 1234 started.
Build ID 5555 started.
All waiting builds have been started.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(0, $exitCode);
    }

    public function testParentOutputWithForkFailure()
    {
        $build1 = new Build;
        $build1->setId('1234');
        $build2 = new Build;
        $build2->setId('5555');

        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturn([$build1, $build2]);

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

        $command = new WorkerBuildCommand(
            'cmd',
            'build-cmd',
            $this->logger,
            $this->buildRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Waiting builds: 2
Starting build workers...
Build ID 1234 started.
Could not fork a build worker.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(1, $exitCode);
    }

    public function testBuildCommandNotFound()
    {
        $this->buildRepo
            ->shouldReceive('findBy')
            ->andReturn([new Build]);

        $this->application
            ->shouldReceive('find')
            ->andReturnNull();

        $command = new WorkerBuildCommand(
            'cmd',
            'build-cmd',
            $this->logger,
            $this->buildRepo,
            $this->em,
            $this->forker
        );
        $command->setApplication($this->application);
        $exitCode = $command->run($this->input, $this->output);

        $expected = <<<'OUTPUT'
Build Command not found.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
        $this->assertSame(2, $exitCode);
    }
}
