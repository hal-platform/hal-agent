<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use Mockery;
use PHPUnit_Framework_TestCase;

class PusherTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'command' => 'rsync --rsh=ssh -o BatchMode=yes --recursive --links --perms --group --owner --devices --specials --checksum --verbose --delete-after build/path/ sync/path',
                'output' => 'test-output'
            ])->once();

        $action = new Pusher($this->logger, $builder, 20);

        $success = $action('build/path', 'sync/path', []);
        $this->assertTrue($success);
    }

    public function testFail()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 9000,
            'isSuccessful' => false
        ])->makePartial();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'rsync --rsh=ssh -o BatchMode=yes --recursive --links --perms --group --owner --devices --specials --checksum --verbose --delete-after --exclude=excluded1 --exclude=excluded2 build/path/ sync/path',
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Pusher($this->logger, $builder, 20);

        $success = $action('build/path', 'sync/path', ['excluded1', 'excluded2']);
        $this->assertFalse($success);
    }
}
