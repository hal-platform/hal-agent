<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

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

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
    }

    public function testSuccessDirectory()
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

        $action = new Preparer($this->logger, $this->filesystem, $builder, 10);

        $success = $action('some/dir', 'sourcedist');

        $this->assertSame(true, $success);
    }

    public function testFileFails()
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

        $action = new Preparer($this->logger, $this->filesystem, $builder, 10);

        $success = $action('some/dir', 'sourcedist');

        $this->assertSame(false, $success);
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

        $action = new Preparer($this->logger, $this->filesystem, $builder, 10);

        $success = $action('some/dir', 'sourcedist');
        $this->assertSame(false, $success);
    }
}
