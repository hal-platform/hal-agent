<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Generic;

use Mockery;
use PHPUnit_Framework_TestCase;

class CleanerTest extends PHPUnit_Framework_TestCase
{
    public $remoter;

    public function setUp()
    {
        $this->remoter = Mockery::mock('QL\Hal\Agent\Remoting\SSHProcess');
    }

    public function testRunCleaner()
    {
        $expectedCommand = ['\rm -rf', '"/path"'];

        $command = Mockery::mock('QL\Hal\Agent\Remoting\CommandContext');
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
