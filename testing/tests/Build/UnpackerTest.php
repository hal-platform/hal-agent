<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class UnpackerTest extends PHPUnit_Framework_TestCase
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
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('success')
            ->with(Mockery::any())
            ->once();

        $action = new Unpacker($this->logger, $builder, 10);

        $success = $action('path', 'command', []);
        $this->assertTrue($success);
    }

    public function testMakeDirectoryFails()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getCommandLine' => 'mkdir',
            'getExitCode' => 127,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => true
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->once()
            ->andReturn(1);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'mkdir',
                'exitCode' => 127,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output',
            ])->once();

        $action = new Unpacker($this->logger, $builder, 10);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);
    }

    public function testUnpackingFails()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getCommandLine' => 'tar',
            'getExitCode' => 128,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->once()
            ->andReturn(0);
        $process
            ->shouldReceive('run')
            ->once();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'tar',
                'exitCode' => 128,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output',
            ])->once();

        $action = new Unpacker($this->logger, $builder, 10);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);
    }

    public function testLocatingUnpackedArchiveFails()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getCommandLine' => 'mv',
            'getExitCode' => 128,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output'
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->twice()
            ->andReturn(0);
        $process
            ->shouldReceive('run');

        $process
            ->shouldReceive('isSuccessful')
            ->once()
            ->andReturn(true);
        $process
            ->shouldReceive('isSuccessful')
            ->andReturn(false);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'mv',
                'exitCode' => 128,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $action = new Unpacker($this->logger, $builder, 10);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);
    }

    public function testSanitizingUnpackedArchiveFails()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getCommandLine' => 'mv',
            'getExitCode' => 128,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output'
        ])->makePartial();

        $process
            ->shouldReceive('isSuccessful')
            ->twice()
            ->andReturn(true);
        $process
            ->shouldReceive('isSuccessful')
            ->once()
            ->andReturn(false);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'mv',
                'exitCode' => 128,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $action = new Unpacker($this->logger, $builder, 10);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);
    }
}
