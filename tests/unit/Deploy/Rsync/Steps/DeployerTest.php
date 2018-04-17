<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync\Steps;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;

use Symfony\Component\Process\Exception\ProcessTimedOutException;

class DeployerTest extends MockeryTestCase
{
    public $logger;
    public $syncer;
    public $processRunner;

    public function setUp()
    {
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');
        $this->syncer = Mockery::mock('Hal\Agent\Remoting\FileSyncManager');

        $this->processRunner = Mockery::mock('Hal\Agent\Symfony\ProcessRunner', [$this->logger])->makePartial();
    }

    public function testSuccess()
    {
        $this->syncer
            ->shouldReceive('buildOutgoingRsync')
            ->with('build/path', 'xferuser', 'hostname', '/remote/path', [])
            ->andReturn(['rsync', 'param']);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'isSuccessful' => true,
            'getOutput' => 'test-output'
        ])->makePartial();

        $this->processRunner
            ->shouldReceive('prepare')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::type('string'), Mockery::type('array'));

        $action = new Deployer($this->logger, $this->syncer, $this->processRunner, 20);

        $success = $action('build/path', 'xferuser', 'hostname', '/remote/path', []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $this->syncer
            ->shouldReceive('buildOutgoingRsync')
            ->with('build/path', 'xferuser', 'hostname', '/remote/path', ['excluded1', 'excluded2'])
            ->andReturn(['rsync', 'param']);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 9000,
            'isSuccessful' => false,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 9000
        ])->makePartial();

        $this->processRunner
            ->shouldReceive('prepare')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::type('string'), Mockery::type('array'));

        $action = new Deployer($this->logger, $this->syncer, $this->processRunner, 20);

        $success = $action('build/path', 'xferuser', 'hostname', '/remote/path', ['excluded1', 'excluded2']);
        $this->assertFalse($success);
    }

    public function testTimeout()
    {
        $this->syncer
            ->shouldReceive('buildOutgoingRsync')
            ->with('build/path', 'xferuser', 'hostname', '/remote/path', ['excluded1', 'excluded2'])
            ->andReturn(['rsync', 'param']);

        $process = Mockery::mock('Symfony\Component\Process\Process')->makePartial();

        $this->processRunner
            ->shouldReceive('prepare')
            ->andReturn($process);

        $process->shouldReceive('run')
            ->andThrow(new ProcessTimedOutException($process, ProcessTimedOutException::TYPE_GENERAL));

        $process->shouldReceive([
            'getTimeout' => 20,
            'getOutput' => 'output here',
            'getErrorOutput' => ''
        ]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::type('string'), Mockery::type('array'));

        $action = new Deployer($this->logger, $this->syncer, $this->processRunner, 20);

        $success = $action('build/path', 'xferuser', 'hostname', '/remote/path', ['excluded1', 'excluded2']);
        $this->assertFalse($success);
    }
}
