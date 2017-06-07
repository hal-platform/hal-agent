<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Mockery;
use Hal\Agent\Testing\MockeryTestCase;
use Hal\Agent\Push\Mover;
use Hal\Agent\Push\ReleasePacker;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class PreparerTest extends MockeryTestCase
{
    public $logger;
    public $filesystem;
    public $mover;
    public $packer;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->filesystem = Mockery::mock(Filesystem::class);

        $this->mover = Mockery::mock(Mover::class);
        $this->packer = Mockery::mock(ReleasePacker::class);
    }

    public function testSuccessFile()
    {
        $isDir = false;

        $process = Mockery::mock(Process::class, ['run' => null, 'isSuccessful' => $isDir])->makePartial();
        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true)
            ->once();

        $this->mover
            ->shouldReceive('__invoke')
            ->with(
                'some/dir/sourcedist',
                '/tmp/file.zip'
            )
            ->once()
            ->andReturn(true);

        $action = new Preparer($this->logger, $this->filesystem, $builder, $this->mover, $this->packer, 10);

        $success = $action('some/dir', 'sourcedist', '/tmp/file.zip', 's3_filename.zip');

        $this->assertSame(true, $success);
    }

    public function testSuccessDir()
    {
        $isDir = true;

        $process = Mockery::mock(Process::class, ['run' => null, 'isSuccessful' => $isDir])->makePartial();
        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');
        $builder
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(true)
            ->once();

        $this->packer
            ->shouldReceive('packZipOrTar')
            ->with(
                'some/dir',
                'sourcedist',
                '/tmp/file.zip',
                's3_filename.zip'
            )
            ->once()
            ->andReturn(true);

        $action = new Preparer($this->logger, $this->filesystem, $builder, $this->mover, $this->packer, 10);

        $success = $action('some/dir', 'sourcedist', '/tmp/file.zip', 's3_filename.zip');

        $this->assertSame(true, $success);
    }

    public function testSourceDoesNotExistFails()
    {
        $builder = Mockery::mock(ProcessBuilder::class . '[getProcess]');

        $this->filesystem
            ->shouldReceive('exists')
            ->andReturn(false)
            ->once();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', Preparer::ERR_DIST_NOT_VALID, [
                'path' => 'sourcedist',
            ])->once();

        $action = new Preparer($this->logger, $this->filesystem, $builder, $this->mover, $this->packer, 10);

        $success = $action('some/dir', 'sourcedist', '/tmp/file.zip', 's3_filename.zip');
        $this->assertSame(false, $success);
    }
}
