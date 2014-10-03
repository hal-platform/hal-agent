<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Testing\MemoryLogger;

class PackerTest extends PHPUnit_Framework_TestCase
{
    public $file;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/archived.file';
    }

    public function testSuccess()
    {
        $logger = new MemoryLogger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Packer($logger, $builder, 10);

        $success = $action('path', $this->file);
        $this->assertTrue($success);


        $message = $logger[0];
        $this->assertSame('info', $message[0]);
        $this->assertSame('Build archived', $message[1]);
        $this->assertSame('0.08 MB', $message[2]['archiveSize']);
    }

    public function testFail()
    {
        $logger = new MemoryLogger;
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $action = new Packer($logger, $builder, 10);

        $success = $action('path', $this->file);
        $this->assertFalse($success);

        $message = $logger[0];
        $this->assertSame('critical', $message[0]);
        $this->assertSame('Build archive did not pack correctly', $message[1]);
        $this->assertSame('test-output', $message[2]['output']);
    }
}
