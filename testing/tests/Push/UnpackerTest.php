<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Yaml\Dumper;

class UnpackerTest extends MockeryTestCase
{
    public $logger;

    public function setUp()
    {
        $this->logger = Mockery::mock('Hal\Agent\Logger\EventLogger');
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
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => [
                    'mkdir php://temp',
                    'tar -vxzf archive --directory=php://temp',
                ],
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
            ->shouldReceive('event')
            ->once();

        $action = new Unpacker($this->logger, $builder, $filesystem, $dumper, 10);

        $success = $action('archive', 'php://temp', []);
        $this->assertFalse($success);
    }
}
