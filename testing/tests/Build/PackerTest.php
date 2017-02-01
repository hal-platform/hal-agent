<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class PackerTest extends PHPUnit_Framework_TestCase
{
    public $file;
    public $logger;
    public $filesystem;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/archived.file';
        $this->logger = Mockery::mock(EventLogger::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
    }

    public function testSuccessWithoutLogging()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->never();

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true)
            ->twice();
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(false)
            ->once();

        $this->filesystem
            ->shouldReceive('copy')
            ->with('path/.hal9000.yml', 'path/subdir/.hal9000.yml', true)
            ->once();

        $action = new Packer($this->logger, $this->filesystem, $builder, 10);
        $action->disableForcedLogging();

        $success = $action('path', 'subdir', $this->file);
        $this->assertTrue($success);
    }

    public function testSuccess()
    {
        $process = Mockery::mock(Process::class, [
            'run' => null,
            'getOutput' => 'test-output',
            'isSuccessful' => true
        ])->makePartial();

        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('success', Mockery::any(), [
                'size' => '0.08 MB',
            ])->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true)
            ->twice();
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(false)
            ->once();

        $this->filesystem
            ->shouldReceive('copy')
            ->with('path/.hal9000.yml', 'path/subdir/.hal9000.yml', true)
            ->once();

        $action = new Packer($this->logger, $this->filesystem, $builder, 10);

        $success = $action('path', 'subdir', $this->file);
        $this->assertTrue($success);
    }

    public function testFail()
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

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => 'tar -vczf ' . $this->file . ' .',
                'exitCode' => 9000,
                'output' => 'test-output',
                'errorOutput' => 'test-error-output'
            ])->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true)
            ->once();
        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(false)
            ->once();

        $action = new Packer($this->logger, $this->filesystem, $builder, 10);

        $success = $action('path', '.', $this->file);
        $this->assertFalse($success);
    }

    public function testFailIfDistDoesNotExist()
    {
        $builder = Mockery::mock(ProcessBuilder::class . '');
        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'path' => 'subdir',
            ])->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('path/subdir')
            ->andReturn(false);

        $action = new Packer($this->logger, $this->filesystem, $builder, 10);

        $success = $action('path', '/subdir', $this->file);
        $this->assertFalse($success);
    }
}
