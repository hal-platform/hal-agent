<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Helper\MemoryLogger;

class ServerCommandTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new MemoryLogger;

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new ServerCommand($logger, $builder, 'sshuser');

        $success = $action('host', 'sync/path', 'bin/cmd', []);
        $this->assertTrue($success);

        $message = $logger->messages()[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Server command executed', $message[1]);
    }

    public function testFail()
    {
        $logger = new MemoryLogger;

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new ServerCommand($logger, $builder, 'sshuser');

        $success = $action('host', 'sync/path', 'bin/cmd', []);
        $this->assertFalse($success);

        $message = $logger->messages()[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Server command executed with errors', $message[1]);
    }
}
