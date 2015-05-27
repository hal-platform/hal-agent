<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;

class ServerCommandTest extends PHPUnit_Framework_TestCase
{
    public $remoter;

    public function setUp()
    {
        $this->remoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
        $this->command = Mockery::mock('QL\Hal\Agent\Remoting\CommandContext');
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
            ->with($this->command, $env, [true])
            ->andReturn(true, false);

        $serverCommand = new ServerCommand($this->remoter);
        $success = $serverCommand('sshuser', 'server', 'path', $commands, $env);

        $this->assertSame(false, $success);
    }
}
