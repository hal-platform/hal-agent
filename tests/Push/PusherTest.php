<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\MemoryLogger;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new MemoryLogger;

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Pusher($logger, $builder);

        $success = $action('build/path', 'sync/path', []);
        $this->assertTrue($success);

        $message = $logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Application code synced to server', $message[1]);
    }

    public function testFail()
    {
        $logger = new MemoryLogger;

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Pusher($logger, $builder);

        $success = $action('build/path', 'sync/path', []);
        $this->assertFalse($success);

        $message = $logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Unable to finish syncing application code', $message[1]);
    }
}
