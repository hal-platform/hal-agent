<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Generic;

use Hal\Agent\Remoting\CommandContext;
use Hal\Agent\Remoting\SSHProcess;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;

class RemoteCleanerTest extends MockeryTestCase
{
    public $remoter;
    public $command;

    public function setUp()
    {
        $this->remoter = Mockery::mock(SSHProcess::class);
        $this->command = Mockery::mock(CommandContext::class);
    }

    public function testRunCleaner()
    {
        $expectedCommand = ['\rm -rf', '"/path"'];

        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'server', $expectedCommand)
            ->andReturn($this->command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->with($this->command, [], [false, 'Clean remote build server'])
            ->andReturn(true)
            ->once();

        $cleaner = new RemoteCleaner($this->remoter);

        $actual = $cleaner('sshuser@server', '/path');
        $this->assertSame(true, $actual);
    }
}
