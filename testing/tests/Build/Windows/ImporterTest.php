<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Windows;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\FileSyncManager;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class ImporterTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $syncer;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->syncer = Mockery::mock(FileSyncManager::class);
    }

    public function testScpTimesOut()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'maxTimeout' => '5 seconds',
                'output' => 'test-output',
                'errorOutput' => 'err-output'
            ])->once();

        $this->syncer
            ->shouldReceive('buildIncomingScpForDirectory')
            ->andReturn(['scp', 'param1']);

        $ex = Mockery::mock(ProcessTimedOutException::class);
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'err-output'
        ])->makePartial();
        $process
            ->shouldReceive('run')
            ->andThrow($ex);

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $importer = new Importer($this->logger, $this->syncer, $builder, 5);

        $actual = $importer('local/path', 'windows-user', 'server', '/remote/path');
        $this->assertSame(false, $actual);
    }

    public function testScpFailure()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => "scp\nparam1",
                'exitCode' => 127,
                'output' => 'test-output',
                'errorOutput' => 'test-stderr'
            ])->once();

        $this->syncer
            ->shouldReceive('buildIncomingScpForDirectory')
            ->andReturn(['scp', 'param1']);

        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getOutput' => 'test-output',
            'getExitCode' => 127,
            'getErrorOutput' => 'test-stderr',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $importer = new Importer($this->logger, $this->syncer, $builder, 5);

        $actual = $importer('local/path', 'windows-user', 'server', '/remote/path');
        $this->assertSame(false, $actual);
    }

    public function testScpBuildsCommandCorrectly()
    {
        $this->syncer
            ->shouldReceive('buildIncomingScpForDirectory')
            ->with('.', 'windows-user', 'server:2200', '/remote/path')
            ->andReturn(['scp', 'param1']);

        $process = Mockery::mock(Process::class, [
            'run' => null,
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class);
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);
        $builder
            ->shouldReceive('setWorkingDirectory')
            ->with('local/path')
            ->andReturn(Mockery::self())
            ->once();
        $builder
            ->shouldReceive('setArguments')
            ->with(['scp', 'param1'])
            ->andReturn(Mockery::self())
            ->once();

        $importer = new Importer($this->logger, $this->syncer, $builder, 5);

        $actual = $importer('local/path', 'windows-user', 'server:2200', '/remote/path');
        $this->assertSame(true, $actual);
    }

    public function testScpSuccess()
    {
        $this->syncer
            ->shouldReceive('buildIncomingScpForDirectory')
            ->andReturn(['scp', 'param1']);

        $process = Mockery::mock(Process::class, [
            'run' => null,
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $importer = new Importer($this->logger, $this->syncer, $builder, 5);

        $actual = $importer('local/path', 'windows-user', 'server', '/remote/path');
        $this->assertSame(true, $actual);
    }
}
