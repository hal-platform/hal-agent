<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Remoting;

use Mockery;
use PHPUnit_Framework_TestCase;

class SSHProcessTest extends PHPUnit_Framework_TestCase
{
    public $logger;
    public $ssh;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->ssh = Mockery::mock('QL\Hal\Agent\Remoting\SSHSessionManager');
    }

    public function testFailedToCreateSession()
    {
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturnNull();

        $remoter = new SSHProcess($this->logger, $this->ssh, 5);

        $command = new CommandContext('user', 'server', 'command');
        $success = $remoter->run($command, [], [false, '']);
        $this->assertSame(false, $success);
    }

    public function testCommandTimeout()
    {
        $session = Mockery::mock('Net_SSH2', [
            'disconnect' => null,
            'getStdError' => 'err-output',
            'getExitStatus' => 0
        ]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive('setTimeout')
            ->with(5)
            ->once();
        $session
            ->shouldReceive('enablePTY')
            ->never();
        $session
            ->shouldReceive('enableQuietMode')
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

        $remoter = new SSHProcess($this->logger, $this->ssh, 5);

        $command = new CommandContext('user', 'server', 'command');
        $success = $remoter->run($command, [], [false, '']);
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
                'enableQuietMode' => null,
                'exec' => 'test-output',
                'getExitStatus' => 127,
                'isTimeout' => true,
                'getStdError' => 'test-err'
            ]);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', SSHProcess::ERR_COMMAND_TIMEOUT, [
                'command' => 'deployscript',
                'output' => 'test-output',
                'errorOutput' => 'test-err',
                'exitCode' => 127
            ])->once();

        $remoter = new SSHProcess($this->logger, $this->ssh, 5);

        $command = new CommandContext('user', 'server', 'deployscript');
        $success = $remoter->run($command, [], [true, '']);
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
                'enableQuietMode' => null,
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

        $remoter = new SSHProcess($this->logger, $this->ssh, 5);

        $command = new CommandContext('user', 'server', ['prefix cmd &&', 'deployscript']);
        $command->withSanitized('deployscript');
        $success = $remoter->run($command, [], [true, 'custom msg']);
        $this->assertSame(false, $success);
    }

    public function testSuccessWithLogging()
    {
        $session = Mockery::mock('Net_SSH2', [
            'disconnect' => null,
            'getStdError' => '',
            'getExitStatus' => 0
        ]);
        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive([
                'setTimeout' => null,
                'enableQuietMode' => null,
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

        $remoter = new SSHProcess($this->logger, $this->ssh, 5);

        $command = new CommandContext('user', 'server', ['prefix cmd &&', 'deployscript']);
        $command->withSanitized('deployscript');
        $success = $remoter->run($command, [], [true, 'custom msg']);
        $this->assertSame(true, $success);
    }

    public function testSuccess()
    {
        $session = Mockery::mock('Net_SSH2', [
            'disconnect' => null,
            'getStdError' => '',
            'getExitStatus' => 0
        ]);

        $this->ssh
            ->shouldReceive('createSession')
            ->with('user', 'server')
            ->andReturn($session);

        $session
            ->shouldReceive([
                'setTimeout' => null,
                'enableQuietMode' => null,
                'exec' => 'test-output',
                'getExitStatus' => 0,
                'isTimeout' => false
            ]);

        $remoter = new SSHProcess($this->logger, $this->ssh, 5);

        $command = new CommandContext('user', 'server', ['command']);
        $command->withSanitized('deployscript');
        $success = $remoter->run($command, [], [false]);
        $this->assertSame(true, $success);
    }
}
