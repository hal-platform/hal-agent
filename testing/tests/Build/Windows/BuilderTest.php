<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Windows;

use Mockery;
use PHPUnit_Framework_TestCase;

class BuilderTest extends PHPUnit_Framework_TestCase
{
    public $remoter;
    public $command;

    public function setUp()
    {
        $this->remoter = Mockery::mock('Hal\Agent\Remoting\SSHProcess');

        $this->command = Mockery::mock('Hal\Agent\Remoting\CommandContext');
        $this->command
            ->shouldReceive('withSanitized')
            ->andReturn($this->command)
            ->byDefault();
    }

    public function testSuccess()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'server', ['cd "path" &&', 'command'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
            ->times(1)
            ->with($this->command, [], [true, Builder::EVENT_MESSAGE])
            ->andReturn(true);

        $builder = new Builder($this->remoter);
        $success = $builder('sshuser', 'server', 'path', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $this->remoter
            ->shouldReceive('createCommand')
            ->times(1)
            ->with('sshuser', 'server', ['cd "path" &&', 'command'])
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('run')
            ->times(1)
            ->with($this->command, [], [true, Builder::EVENT_MESSAGE])
            ->andReturn(false);

        $builder = new Builder($this->remoter);
        $success = $builder('sshuser', 'server', 'path', ['command'], []);
        $this->assertFalse($success);
    }

    public function testMultipleCommandsAreRun()
    {
        $commands = [
            'command1',
            'command2'
        ];

        $env = [];

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
            ->times(2)
            ->with($this->command, [], [true, Builder::EVENT_MESSAGE])
            ->andReturn(true, false);

        $builder = new Builder($this->remoter);
        $success = $builder('sshuser', 'server', 'path', $commands, $env);

        $this->assertSame(false, $success);
    }
}
