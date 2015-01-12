<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;

class ServerCommandTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getCommandLine' => 'servercmd',
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'command' => 'servercmd',
                'output' => 'test-output'
            ])->once();

        $action = new ServerCommand($this->logger, $builder, 'sshuser', 10);

        $success = $action('host', 'sync/path', ['env && pwd'], ['derp' => 'derp1', 'derp2' => 'derp3']);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getCommandLine' => 'servercmd',
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 500,
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'servercmd',
                'output' => 'test-output',
                'errorOutput' => 'test-error-output',
                'exitCode' => 500
            ])->once();

        $action = new ServerCommand($this->logger, $builder, 'sshuser', 10);

        $success = $action('host', 'sync/path', ['bin/cmd'], []);
        $this->assertFalse($success);
    }
}
