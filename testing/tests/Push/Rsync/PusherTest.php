<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $syncer;

    public function setUp()
    {
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');
        $this->syncer = Mockery::mock('Hal\Agent\Remoting\FileSyncManager');
    }

    public function testSuccess()
    {
        $this->syncer
            ->shouldReceive('buildOutgoingRsync')
            ->with('build/path', 'xferuser', 'hostname', '/remote/path', [])
            ->andReturn(['rsync', 'param']);

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
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'command' => "rsync\nparam",
                'output' => 'test-output'
            ])->once();

        $action = new Pusher($this->logger, $this->syncer, $builder, 20);

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
            'run' => 0,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 9000,
            'isSuccessful' => false
        ])->makePartial();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => "rsync\nparam",
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Pusher($this->logger, $this->syncer, $builder, 20);

        $success = $action('build/path', 'xferuser', 'hostname', '/remote/path', ['excluded1', 'excluded2']);
        $this->assertFalse($success);
    }
}
