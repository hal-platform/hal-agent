<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Job;

use Hal\Agent\Symfony\ProcessRunner;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Symfony\Component\Process\Process;

class FileCompressionTest extends MockeryTestCase
{
    public $runner;

    public function setUp()
    {
        $this->runner = Mockery::mock(ProcessRunner::class);
    }

    public function testCreateWorkspace()
    {
        $process = Mockery::mock(Process::class, ['isSuccessful' => true]);

        $this->runner
            ->shouldReceive('prepare')
            ->with(['mkdir', '/path/workspace'], null, 10)
            ->once()
            ->andReturn($process);

        $this->runner
            ->shouldReceive('run')
            ->with($process, 'Filesystem action timed out')
            ->once()
            ->andReturn(true);

        $compression = new FileCompression($this->runner, 10);

        $actual = $compression->createWorkspace('/path/workspace');

        $this->assertSame(true, $actual);
    }

    public function testUnpackTar()
    {
        $process = Mockery::mock(Process::class, ['isSuccessful' => true]);

        $this->runner
            ->shouldReceive('prepare')
            ->with(['tar', '-vxz', '--strip-components=2', '--file=/path/artifact.tgz', '--directory=/path/workspace'], null, 10)
            ->once()
            ->andReturn($process);

        $this->runner
            ->shouldReceive('run')
            ->with($process, 'Filesystem action timed out')
            ->once()
            ->andReturn(true);

        $compression = new FileCompression($this->runner, 10);

        $actual = $compression->unpackTarArchive('/path/workspace', '/path/artifact.tgz', 2);

        $this->assertSame(true, $actual);
    }

    public function testPackTar()
    {
        $sourcePath = __DIR__ . '/.fixtures';

        $process = Mockery::mock(Process::class, ['isSuccessful' => true]);

        $this->runner
            ->shouldReceive('prepare')
            ->with(['tar', '-vcz', '--file=/path/artifact.tgz', '.'], $sourcePath, 10)
            ->once()
            ->andReturn($process);

        $this->runner
            ->shouldReceive('run')
            ->with($process, 'Filesystem action timed out')
            ->once()
            ->andReturn(true);

        $compression = new FileCompression($this->runner, 10);

        $actual = $compression->packTarArchive($sourcePath, '/path/artifact.tgz');

        $this->assertSame(true, $actual);
    }
}
