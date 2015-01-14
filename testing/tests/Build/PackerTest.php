<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Mockery;
use PHPUnit_Framework_TestCase;

class PackerTest extends PHPUnit_Framework_TestCase
{
    public $file;
    public $logger;
    public $filesystem;

    public function setUp()
    {
        $this->file = FIXTURES_DIR . '/archived.file';
        $this->logger = Mockery::mock('QL\Hal\Agent\Logger\EventLogger');
        $this->filesystem = Mockery::mock('Symfony\Component\Filesystem\Filesystem');
    }

    public function testSuccess()
    {
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
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
                'size' => '0.08 MB',
            ])->once();
        $this->logger
            ->shouldReceive('keep')
            ->with('filesize', ['archive' => '87480'])
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true)
            ->twice();

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
        $process = Mockery::mock('Symfony\Component\Process\Process', [
            'run' => null,
            'getCommandLine' => './packer',
            'getExitCode' => 9000,
            'getOutput' => 'test-output',
            'getErrorOutput' => 'test-error-output',
            'isSuccessful' => false
        ])->makePartial();

        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Mockery::any(), [
                'command' => './packer',
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
        $builder = Mockery::mock('Symfony\Component\Process\ProcessBuilder');
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
