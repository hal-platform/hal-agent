<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent;

use Mockery;
use PHPUnit_Framework_TestCase;

class RemoteProcessTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $ssh;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->ssh = Mockery::mock('QL\Hal\Agent\SSHSessionManager');
    }

    public function testFailedToCreateSession()
    {
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturnNull();

        $remoter = new RemoteProcess($this->logger, $this->ssh, 5);

        $success = $remoter('user', 'server', 'command', [], false, '');
        $this->assertSame(false, $success);
    }

    public function testCommandTimeout()
    {
        $session = Mockery::mock('Net_SSH2', ['disconnect' => null]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive('setTimeout')
            ->with(5)
            ->once();
        $session
            ->shouldReceive('exec')
            ->with('command')
            ->andReturn('command output')
            ->once();
        $session
            ->shouldReceive('isTimeout')
            ->andReturn(true)
            ->once();

        $remoter = new RemoteProcess($this->logger, $this->ssh, 5);

        $success = $remoter('user', 'server', 'command', [], false, '');
        $this->assertSame(false, $success);
    }

    public function testCommandTimeoutWithLoggingEnabled()
    {
        $session = Mockery::mock('Net_SSH2', ['disconnect' => null]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive([
                'setTimeout' => null,
                'exec' => 'test-output',
                'getExitStatus' => 127,
                'isTimeout' => true,
                'getStdError' => 'test-err'
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', RemoteProcess::ERR_COMMAND_TIMEOUT, [
                'command' => 'deployscript',
                'output' => 'test-output',
                'errorOutput' => 'test-err',
                'exitCode' => 127
            ])->once();

        $remoter = new RemoteProcess($this->logger, $this->ssh, 5);

        $success = $remoter('user', 'server', 'deployscript', [], true, '');
        $this->assertSame(false, $success);
    }

    public function testCommandFailure()
    {
        $session = Mockery::mock('Net_SSH2', ['disconnect' => null]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive([
                'setTimeout' => null,
                'getExitStatus' => 127,
                'isTimeout' => false,
                'getStdError' => 'test-err'
            ]);

        $session
            ->shouldReceive('exec')
            ->with('prefix cmd && deployscript')
            ->andReturn('test-output')
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'custom msg', [
                'command' => 'deployscript',
                'output' => 'test-output',
                'errorOutput' => 'test-err',
                'exitCode' => 127
            ])->once();

        $remoter = new RemoteProcess($this->logger, $this->ssh, 5);

        $success = $remoter('user', 'server', 'deployscript', [], true, 'prefix cmd &&', 'custom msg');
        $this->assertSame(false, $success);
    }

    public function testSuccessWithLogging()
    {
        $session = Mockery::mock('Net_SSH2', ['disconnect' => null]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive([
                'setTimeout' => null,
                'exec' => 'test-output',
                'getExitStatus' => 0,
                'isTimeout' => false
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('success', 'custom msg', [
                'command' => 'deployscript',
                'output' => 'test-output'
            ])->once();

        $remoter = new RemoteProcess($this->logger, $this->ssh, 5);

        $success = $remoter('user', 'server', 'deployscript', [], true, 'prefix cmd &&', 'custom msg');
        $this->assertSame(true, $success);
    }

    public function testSuccess()
    {
        $session = Mockery::mock('Net_SSH2', ['disconnect' => null]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive([
                'setTimeout' => null,
                'exec' => 'test-output',
                'getExitStatus' => 0,
                'isTimeout' => false
            ]);

        $remoter = new RemoteProcess($this->logger, $this->ssh, 5);

        $success = $remoter('user', 'server', 'command', [], false, '');
        $this->assertSame(true, $success);
    }
}
