<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;

class ServerCommandTest extends PHPUnit_Framework_TestCase
{
    public $remoter;

    public function setUp()
    {
        $this->remoter = Mockery::mock('Hal\Agent\Remoting\SSHProcess');
        $this->command = Mockery::mock('Hal\Agent\Remoting\CommandContext');
    }

    public function testSuccess()
    {
        $this->command
            ->shouldReceive('withSanitized')
            ->with('command')
            ->andReturn($this->command)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'server', ['cd "path" &&', 'command'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(true);

        $serverCommand = new ServerCommand($this->remoter);
        $success = $serverCommand('sshuser', 'server', 'path', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $this->command
            ->shouldReceive('withSanitized')
            ->with('command')
            ->andReturn($this->command)
            ->once();
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'server', ['cd "path" &&', 'command'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
            ->andReturn(false);

        $serverCommand = new ServerCommand($this->remoter);
        $success = $serverCommand('sshuser', 'server', 'path', ['command'], []);
        $this->assertFalse($success);
    }

    public function testMultipleCommandsAreRun()
    {
        $commands = [
            'command1',
            'command2'
        ];

        $env = ['HERP' => 'DERP'];

        $this->command
            ->shouldReceive('withSanitized')
            ->with('command1')
            ->andReturn($this->command)
            ->times(1);
        $this->command
            ->shouldReceive('withSanitized')
            ->with('command2')
            ->andReturn($this->command)
            ->times(1);

        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'server', ['cd "path" &&', 'command1'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'server', ['cd "path" &&', 'command2'])
            ->andReturn($this->command);

        $this->remoter
            ->shouldReceive('run')
            ->with($this->command, $env, [true, 'Run remote command "command1"'])
            ->andReturn(true)
            ->once();
        $this->remoter
            ->shouldReceive('run')
            ->with($this->command, $env, [true, 'Run remote command "command2"'])
            ->andReturn(false)
            ->once();

        $serverCommand = new ServerCommand($this->remoter);
        $success = $serverCommand('sshuser', 'server', 'path', $commands, $env);

        $this->assertSame(false, $success);
    }
}
