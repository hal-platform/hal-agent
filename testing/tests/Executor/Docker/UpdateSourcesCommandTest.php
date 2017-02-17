<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Executor\Docker;

use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Github\ArchiveApi;
use Hal\Agent\Testing\ExecutorTestCase;
use Mockery;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class UpdateSourcesCommandTest extends ExecutorTestCase
{
    public $fileSync;
    public $process;
    public $archiveDownloader;

    public function setUp()
    {
        $this->fileSync = Mockery::mock(FileSyncManager::class);
        $this->process = Mockery::mock(ProcessBuilder::class);
        $this->archiveDownloader = Mockery::mock(ArchiveApi::class);
    }

    public function configureCommand($c)
    {
        UpdateSourcesCommand::configure($c);
    }

    public function testLocalTempNotWriteable()
    {
        $command = new UpdateSourcesCommand(
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            '/tmp/halscratch',
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $io = $this->io('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Repository: hal/docker-images',
            'Reference: master',
            'Download: /tmp/halscratch/docker-images.tar.gz',
            'Directory: /tmp/halscratch/docker-images',
            '[ERROR] Temp directory "/tmp/halscratch" is not writeable!',
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testDownloadFails()
    {
        $this->archiveDownloader
            ->shouldReceive('download')
            ->with('custom', 'repo', '1.0', Mockery::any())
            ->once()
            ->andReturn(false);

        $process = Mockery::mock(Process::class, ['stop' => null]);
        $this->process
            ->shouldReceive('setWorkingDirectory')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('setArguments')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('getProcess')
            ->andReturn($process);
        $process
            ->shouldReceive('run')
            ->times(2);

        $command = new UpdateSourcesCommand(
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal',
            'master'
        );

        $io = $this->io('configureCommand', [
            'GIT_REPOSITORY' => 'custom/repo',
            'GIT_REFERENCE' => '1.0',
        ]);
        $exit = $command->execute($io);

        $expected = [
            'Repository: custom/repo',
            'Reference: 1.0',
            '[ERROR] Invalid GitHub repository or reference.',
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testUnpackFails()
    {
        $this->archiveDownloader
            ->shouldReceive('download')
            ->with('hal', 'docker-images', 'master', Mockery::any())
            ->once()
            ->andReturn(true);

        $process = Mockery::mock(Process::class, ['stop' => null]);

        $this->process
            ->shouldReceive('setWorkingDirectory')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('setArguments')
            ->andReturn($this->process)
            ->times(2);
        $this->process
            ->shouldReceive('getProcess')
            ->andReturn($process)
            ->times(2);

        $process
            ->shouldReceive('run')
            ->times(2);

        $process
            ->shouldReceive('isSuccessful')
            ->twice()
            ->andReturn(true, false);

        // cleanup
        $this->process
            ->shouldReceive('setArguments')
            ->andReturn($this->process)
            ->times(2);
        $this->process
            ->shouldReceive('getProcess')
            ->andReturn($process)
            ->times(2);
        $process
            ->shouldReceive('run')
            ->times(2);

        $command = new UpdateSourcesCommand(
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $io = $this->io('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            'Archive download and unpack failed.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testTransferFails()
    {
        $this->archiveDownloader
            ->shouldReceive('download')
            ->with('hal', 'docker-images', 'master', Mockery::any())
            ->once()
            ->andReturn(true);

        $process = Mockery::mock(Process::class, ['stop' => null]);

        $this->process
            ->shouldReceive('setWorkingDirectory')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('setArguments')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $process
            ->shouldReceive('run');
        $process
            ->shouldReceive('isSuccessful')
            ->twice()
            ->andReturn(true);

        $this->fileSync
            ->shouldReceive('buildOutgoingRsync')
            ->with(Mockery::any(), 'builduser', 'buildserver', '/target/docker/images')
            ->andReturn(['rsync', 'my', 'stuff', 'plz']);

        // rsync process
        $process
            ->shouldReceive('setCommandLine')
            ->with('rsync my stuff plz')
            ->andReturn($process);
        $process
            ->shouldReceive('isSuccessful')
            ->once()
            ->andReturn(false);
        $process
            ->shouldReceive('getExitCode')
            ->andReturn(2);
        $process
            ->shouldReceive('getErrorOutput')
            ->andReturn("derp herp bad stuff happened\n");

        $command = new UpdateSourcesCommand(
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $io = $this->io('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            '[NOTE] An error occurred while transferring dockerfiles to build server.',
            'derp herp bad stuff happened',
            '[NOTE] Ensure "/target/docker/images" exists on the build server',
            '[ERROR] An error occurred while transferring dockerfiles to build server.'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(1, $exit);
    }

    public function testSuccess()
    {
        $this->archiveDownloader
            ->shouldReceive('download')
            ->with('hal', 'docker-images', 'master', Mockery::any())
            ->once()
            ->andReturn(true);

        $process = Mockery::mock(Process::class, ['stop' => null]);

        $this->process
            ->shouldReceive('setWorkingDirectory')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('setArguments')
            ->andReturn($this->process);
        $this->process
            ->shouldReceive('getProcess')
            ->andReturn($process);

        $process
            ->shouldReceive('run');
        $process
            ->shouldReceive('isSuccessful')
            ->times(3)
            ->andReturn(true);

        $this->fileSync
            ->shouldReceive('buildOutgoingRsync')
            ->with(Mockery::any(), 'builduser', 'buildserver', '/target/docker/images')
            ->andReturn(['rsync', 'my', 'stuff', 'plz']);

        // rsync process
        $process
            ->shouldReceive('setCommandLine')
            ->with('rsync my stuff plz')
            ->andReturn($process);

        $command = new UpdateSourcesCommand(
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $io = $this->io('configureCommand');
        $exit = $command->execute($io);

        $expected = [
            '[OK] Dockerfiles refreshed!'
        ];

        $this->assertContainsLines($expected, $this->output());
        $this->assertSame(0, $exit);
    }
}
