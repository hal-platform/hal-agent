<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class UnpackerTest extends PHPUnit_Framework_TestCase
{
    public function testSuccess()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertTrue($success);

        $message = $logger->messages()[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Repository unpacked', $message[1]);

        $message = $logger->messages()[1];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Unpacked archive located', $message[1]);

        $message = $logger->messages()[2];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Unpacked archive sanitized', $message[1]);
    }

    public function testMakeDirectoryFails()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->once()
            ->andReturn(1);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);

        $message = $logger->messages()[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Unable to unpack repository archive', $message[1]);
    }

    public function testUnpackingFails()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->once()
            ->andReturn(0);
        $process
            ->shouldReceive('run')
            ->once()
            ->andReturn(2);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);

        $message = $logger->messages()[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Unable to unpack repository archive', $message[1]);
    }

    public function testLocatingUnpackedArchiveFails()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getOutput' => 'test-output',
            'isSuccessful' => false
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->twice()
            ->andReturn(0);
        $process
            ->shouldReceive('run')
            ->once()
            ->andReturn(1);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);

        $message = $logger->messages()[1];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Unpacked archive could not be located', $message[1]);
    }

    public function testSanitizingUnpackedArchiveFails()
    {
        $logger = new Logger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'getOutput' => 'test-output'
        ])->makePartial();

        $process
            ->shouldReceive('isSuccessful')
            ->once()
            ->andReturn(true);
        $process
            ->shouldReceive('isSuccessful')
            ->once()
            ->andReturn(false);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Unpacker($logger, $builder);

        $success = $action('path', 'command', []);
        $this->assertFalse($success);

        $message = $logger->messages()[2];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Unpacked archive could not be sanitized', $message[1]);
    }
}
