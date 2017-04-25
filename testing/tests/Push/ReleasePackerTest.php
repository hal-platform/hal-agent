<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Hal\Agent\Logger\EventLogger;
use Mockery;
use PHPUnit_Framework_TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class ReleasePackerTest extends PHPUnit_Framework_TestCase
{
    public $file;
    public $filesystem;
    public $logger;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/push.zip';
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->logger = Mockery::mock(EventLogger::class);
    }

    public function testFailPackZip()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getExitCode' => 9000,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true, false)
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'zip --recurse-paths ' . $this->file . ' .',
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $packer = new ReleasePacker($this->logger, $this->filesystem, $builder, 5);
        $success = $packer->packZip('/path/build/path', '.', $this->file);
        $this->assertSame(false, $success);
    }

    public function testSuccessfulZip()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true, false)
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->never();

        $packer = new ReleasePacker($this->logger, $this->filesystem, $builder, 5);
        $success = $packer->packZip('path', 'subdir/path', $this->file);
        $this->assertSame(true, $success);
    }

    public function testFailTar()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getExitCode' => 9000,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true, false)
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'tar -hvczf ' . $this->file . ' .',
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $packer = new ReleasePacker($this->logger, $this->filesystem, $builder, 5);
        $success = $packer->packTar('path', 'subdir/path', $this->file);
        $this->assertSame(false, $success);
    }

    public function testAutoDetectZipCorrectly()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getExitCode' => 9000,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true, false)
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'zip --recurse-paths ' . $this->file . ' .',
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $packer = new ReleasePacker($this->logger, $this->filesystem, $builder, 5);
        $success = $packer->packZipOrTar('path', 'subdir/path', $this->file, 'file.zip');
        $this->assertSame(false, $success);
    }

    public function testAutoDetectTarCorrectly()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getExitCode' => 9000,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true, false)
            ->twice();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'tar -hvczf ' . $this->file . ' .',
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $packer = new ReleasePacker($this->logger, $this->filesystem, $builder, 5);
        $success = $packer->packZipOrTar('path', 'subdir/path', $this->file, 'file.tar.gz');
        $this->assertSame(false, $success);
    }
}
