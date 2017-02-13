<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Generic;

use Mockery;
use PHPUnit_Framework_TestCase;

class CleanerTest extends PHPUnit_Framework_TestCase
{
    public $remoter;

    public function setUp()
    {
        $this->remoter = Mockery::mock('Hal\Agent\Remoting\SSHProcess');
    }

    public function testRunCleaner()
    {
        $expectedCommand = ['\rm -rf', '"/path"'];

        $command = Mockery::mock('Hal\Agent\Remoting\CommandContext');
        $this->remoter
            ->shouldReceive('createCommand')
            ->with('sshuser', 'server', $expectedCommand)
            ->andReturn($command);
        $this->remoter
            ->shouldReceive('runWithLoggingOnFailure')
            ->with($command, [], [false, 'Clean remote build server'])
            ->andReturn(true)
            ->once();

        $cleaner = new Cleaner($this->remoter);

        $actual = $cleaner('sshuser', 'server', '/path');
        $this->assertSame(true, $actual);
    }
}
