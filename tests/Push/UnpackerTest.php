<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Helper\MemoryLogger;
use Symfony\Component\Yaml\Dumper;

class UnpackerTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new MemoryLogger;
        $dumper = new Dumper;

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder, $dumper);

        $success = $action('archive', 'php://temp', []);
        $this->assertTrue($success);

        $message = $logger->messages()[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Repository unpacked', $message[1]);

        $message = $logger->messages()[1];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Push details written to application directory', $message[1]);
    }

    public function testFail()
    {
        $logger = new MemoryLogger;
        $dumper = new Dumper;

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getOutput' => 'test-output',
            'isSuccessful' => false
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->andReturn(0)
            ->once();

        $process
            ->shouldReceive('run')
            ->andReturn(1)
            ->once();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder, $dumper);

        $success = $action('archive', 'php://temp', []);
        $this->assertFalse($success);

        $message = $logger->messages()[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Unable to unpack repository archive', $message[1]);
    }
}
