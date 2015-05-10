<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $syncer;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->syncer = Mockery::mock('QL\Hal\Agent\Remoting\FileSyncManager');
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
