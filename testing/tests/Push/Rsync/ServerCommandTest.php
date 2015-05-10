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
    }

    public function testSuccess()
    {
        $this->remoter
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $serverCommand = new ServerCommand($this->remoter);
        $success = $serverCommand('sshuser', 'server', 'path', ['command'], []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $this->remoter
            ->shouldReceive('__invoke')
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

        $env = [];
        $prefixCommand = 'cd "path" &&';

        $this->remoter
            ->shouldReceive('__invoke')
            ->with('sshuser', 'server', 'command1', $env, true, $prefixCommand)
            ->andReturn(true)
            ->once();
        $this->remoter
            ->shouldReceive('__invoke')
            ->with('sshuser', 'server', 'command2', $env, true, $prefixCommand)
            ->andReturn(false)
            ->once();

        $serverCommand = new ServerCommand($this->remoter);
        $success = $serverCommand('sshuser', 'server', 'path', $commands, $env);

        $this->assertSame(false, $success);
    }
}
