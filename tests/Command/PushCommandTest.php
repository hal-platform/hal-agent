<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Command;

use MCP\DataType\Time\Clock;
use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Helper\MemoryLogger;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class PushCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $em;
    public $clock;
    public $resolver;
    public $unpacker;
    public $pusher;
    public $command;
    public $processBuilder;

    public $input;
    public $output;

    public function setUp()
    {
        $this->logger = new MemoryLogger;
        $this->em = Mockery::mock('Doctrine\ORM\EntityManager');
        $this->clock = new Clock('now', 'UTC');
        $this->resolver = Mockery::mock('QL\Hal\Agent\Push\Resolver');
        $this->unpacker = Mockery::mock('QL\Hal\Agent\Push\Unpacker');
        $this->pusher = Mockery::mock('QL\Hal\Agent\Push\Pusher');
        $this->command = Mockery::mock('QL\Hal\Agent\Push\ServerCommand');
        $this->processBuilder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');

        $this->output = new BufferedOutput;
    }

    public function testBuildResolvingFails()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1',
            'METHOD' => 'rsync'
        ]);

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturnNull();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->em,
            $this->clock,
            $this->resolver,
            $this->unpacker,
            $this->pusher,
            $this->command,
            $this->processBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving...
Push details could not be resolved.

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }

    public function testSuccess()
    {
        $this->input = new ArrayInput([
            'PUSH_ID' => '1',
            'METHOD' => 'rsync'
        ]);

        $push = Mockery::mock('QL\Hal\Core\Entity\Push', [
            'setStatus' => null,
            'setStart' => null,
            'setEnd' => null,
        ]);

        $this->em
            ->shouldReceive('merge')
            ->with($push);
        $this->em
            ->shouldReceive('flush');

        $this->resolver
            ->shouldReceive('__invoke')
            ->andReturn([
                'push' => $push,
                'buildPath' => 'path/dir',
                'archiveFile' => 'path/file',
                'pushProperties' => [],
                'prePushCommand' => 'bin/cmd',
                'postPushCommand' => 'bin/cmd',
                'hostname' => 'localhost',
                'remotePath' => 'path/dir',
                'environmentVariables' => [],
                'syncPath' => 'user@localhost:path/dir',
                'excludedFiles' => []
            ]);
        $this->unpacker
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->command
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->pusher
            ->shouldReceive('__invoke')
            ->andReturn(true);

        // cleanup
        $this->processBuilder
            ->shouldReceive('getProcess->run')
            ->once();

        $command = new PushCommand(
            'cmd',
            $this->logger,
            $this->em,
            $this->clock,
            $this->resolver,
            $this->unpacker,
            $this->pusher,
            $this->command,
            $this->processBuilder
        );

        $command->run($this->input, $this->output);
        $expected = <<<'OUTPUT'
Resolving...
Push properties: {
    "push": {

    },
    "buildPath": "path\/dir",
    "archiveFile": "path\/file",
    "pushProperties": [

    ],
    "prePushCommand": "bin\/cmd",
    "postPushCommand": "bin\/cmd",
    "hostname": "localhost",
    "remotePath": "path\/dir",
    "environmentVariables": [

    ],
    "syncPath": "user@localhost:path\/dir",
    "excludedFiles": [

    ]
}
Unpacking...
Pre push command executing...
Pushing...
Post push command executing...
Success!

OUTPUT;
        $this->assertSame($expected, $this->output->fetch());
    }
}
