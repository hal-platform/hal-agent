<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use Mockery;
use PHPUnit_Framework_TestCase;

class ExporterTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $syncer;
    public $remoter;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->syncer = Mockery::mock('QL\Hal\Agent\Remoting\FileSyncManager');
        $this->remoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
    }

    public function testCreateTempBuildDirFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Exporter::ERR_PREPARE_BUILD_DIR)
            ->once();

        $expectedCommand = 'if [ -d "/remote/path" ]; then rm -r "/remote/path"; fi; mkdir -p "/remote/path"';
        $this->remoter
            ->shouldReceive('__invoke')
            ->with('sshuser', 'server', $expectedCommand, [], false)
            ->andReturn(false);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');

        $exporter = new Exporter($this->logger, $this->syncer, $this->remoter, $builder, 5);

        $actual = $exporter('local/path', 'sshuser', 'server', '/remote/path');
        $this->assertSame(false, $actual);
    }

    public function testScpFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Exporter::EVENT_MESSAGE, [
                'command' => "scp\nparam1",
                'exitCode' => 5,
                'output' => 'test-output',
                'errorOutput' => 'test-stderr'
            ])->once();

        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->syncer
            ->shouldReceive('buildOutgoingScp')
            ->andReturn(['scp', 'param1']);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getExitCode' => 5,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-stderr',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $exporter = new Exporter($this->logger, $this->syncer, $this->remoter, $builder, 5);

        $actual = $exporter('local/path', 'sshuser', 'server', '/remote/path');
        $this->assertSame(false, $actual);
    }

    public function testLocalCleanupFails()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Exporter::EVENT_MESSAGE, [
                'command' => [
                    'rm -r local/path',
                    'mkdir local/path',
                ],
                'exitCode' => 5,
                'output' => 'test-output',
                'errorOutput' => 'test-stderr'
            ])->once();

        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->syncer
            ->shouldReceive('buildOutgoingScp')
            ->andReturn(['scp', 'param1']);

        $scp = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'isSuccessful' => true
        ])->makePartial();
        $rmdir = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'isSuccessful' => true
        ])->makePartial();
        $mkdir = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'isSuccessful' => false,
            'getExitCode' => 5,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-stderr'
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($scp, $rmdir, $mkdir);

        $exporter = new Exporter($this->logger, $this->syncer, $this->remoter, $builder, 5);

        $actual = $exporter('local/path', 'sshuser', 'server', '/remote/path');
        $this->assertSame(false, $actual);
    }

    public function testSuccess()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);
        $this->syncer
            ->shouldReceive('buildOutgoingScp')
            ->andReturn(['scp', 'param1']);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $exporter = new Exporter($this->logger, $this->syncer, $this->remoter, $builder, 5);

        $actual = $exporter('local/path', 'sshuser', 'server', '/remote/path');
        $this->assertSame(true, $actual);
    }
}
