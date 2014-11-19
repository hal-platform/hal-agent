<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Yaml\Dumper;

class UnpackerTest extends PHPUnit_Framework_TestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
    }

    public function testSuccess()
    {
        $dumper = new Dumper;
        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem', [
            'dumpFile' => null
        ]);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => 0,
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);


        $action = new Unpacker($this->logger, $builder, $filesystem, $dumper, 10);

        $success = $action('archive', 'php://temp', []);
        $this->assertTrue($success);
    }

    public function testFailUnpacking()
    {
        $dumper = new Dumper;
        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem', [
            'dumpFile' => null
        ]);

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getCommandLine' => 'tar',
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'getExitCode' => 500
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

        $this->logger
            ->shouldReceive('failure')
            ->with(Mockery::any(), [
                'command' => 'tar',
                'output' => 'test-output',
                'errorOutput' => 'test-error-output',
                'exitCode' => 500
            ])->once();

        $action = new Unpacker($this->logger, $builder, $filesystem, $dumper, 10);

        $success = $action('archive', 'php://temp', []);
        $this->assertFalse($success);
    }

    public function testFailBuildArtifacts()
    {
        $dumper = new Dumper;

        $filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
        $filesystem
            ->shouldReceive('dumpFile')
            ->andThrow(new IOException('msg'));

        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'getOutput' => 'test-output'
        ])->makePartial();

        $process
            ->shouldReceive('run')
            ->andReturn(0);

        $process
            ->shouldReceive('isSuccessful')
            ->andReturn(true);

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('failure')
            ->once();

        $action = new Unpacker($this->logger, $builder, $filesystem, $dumper, 10);

        $success = $action('archive', 'php://temp', []);
        $this->assertFalse($success);
    }
}
