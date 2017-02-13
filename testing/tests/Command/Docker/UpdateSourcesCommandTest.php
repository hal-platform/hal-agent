<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Command\Docker;

use Doctrine\ORM\EntityManagerInterface;
use Mockery;
use PHPUnit_Framework_TestCase;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Github\ArchiveApi;
use QL\MCP\Common\Time\Clock;
use QL\MCP\Common\Time\TimePoint;
use QL\Hal\Core\Entity\User;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\Process;

class UpdateSourcesCommandTest extends PHPUnit_Framework_TestCase
{
    public $fileSync;
    public $process;
    public $archiveDownloader;
    public $em;
    public $clock;
    public $random;

    public $input;
    public $output;

    public function setUp()
    {
        $this->fileSync = Mockery::mock(FileSyncManager::class);
        $this->process = Mockery::mock(ProcessBuilder::class);
        $this->archiveDownloader = Mockery::mock(ArchiveApi::class);
        $this->em = Mockery::mock(EntityManagerInterface::class);
        $this->clock = Mockery::mock(Clock::class);
        $this->random = function() {
            return '1234';
        };

        $this->input = new ArrayInput([]);
        $this->output = new BufferedOutput;
    }

    public function testLocalTempNotWriteable()
    {
        $command = new UpdateSourcesCommand(
            'cmd',
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            $this->em,
            $this->clock,
            $this->random,
            '/tmp/halscratch',
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $exit = $command->run($this->input, $this->output);

        $expected = [
            '[GitHub] Repository: hal/docker-images',
            '[GitHub] Reference: master',
            '[Temp] Download: /tmp/halscratch/docker-images.tar.gz',
            '[Temp] Directory: /tmp/halscratch/docker-images',
            'Temp directory "/tmp/halscratch" is not writeable!',
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame(1, $exit);
    }

    public function testDownloadFails()
    {
        $this->input = new ArrayInput([
            'GIT_REPOSITORY' => 'custom/repo',
            'GIT_REFERENCE' => '1.0',
        ]);

        $this->archiveDownloader
            ->shouldReceive('download')
            ->with('custom', 'repo', '1.0', Mockery::any())
            ->once()
            ->andReturn(false);

        $command = new UpdateSourcesCommand(
            'cmd',
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            $this->em,
            $this->clock,
            $this->random,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal',
            'master'
        );

        $exit = $command->run($this->input, $this->output);

        $expected = [
            '[GitHub] Repository: custom/repo',
            '[GitHub] Reference: 1.0',
            'Invalid GitHub repository or reference.',
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame(2, $exit);
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
            'cmd',
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            $this->em,
            $this->clock,
            $this->random,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $exit = $command->run($this->input, $this->output);

        $expected = [
            'Archive download and unpack failed.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame(3, $exit);
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
            'cmd',
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            $this->em,
            $this->clock,
            $this->random,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $exit = $command->run($this->input, $this->output);

        $expected = [
            'Exit Code: 2',
            'derp herp bad stuff happened',
            'Ensure "/target/docker/images" exists on the build server and is owned by "builduser"',
            'An error occured while transferring dockerfile sources to build server.'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame(4, $exit);
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

        // audit
        $user = new User;

        $timepoint = Mockery::mock(TimePoint::class);
        $this->clock
            ->shouldReceive('read')
            ->andReturn($timepoint);

        $this->em
            ->shouldReceive('flush');
        $this->em
            ->shouldReceive('find')
            ->with(User::class, 9000)
            ->andReturn($user);

        $log = null;
        $this->em
            ->shouldReceive('persist')
            ->with(Mockery::on(function($v) use (&$log) {
                $log = $v;
                return true;
            }));

        $command = new UpdateSourcesCommand(
            'cmd',
            $this->fileSync,
            $this->process,
            $this->archiveDownloader,
            $this->em,
            $this->clock,
            $this->random,
            __DIR__,
            'builduser',
            'buildserver',
            '/target/docker/images',
            'hal/docker-images',
            'master'
        );

        $exit = $command->run($this->input, $this->output);

        $expected = [
            'Dockerfiles refreshed!'
        ];

        $output = $this->output->fetch();
        foreach ($expected as $exp) {
            $this->assertContains($exp, $output);
        }

        $this->assertSame(0, $exit);

        $this->assertSame($user, $log->user());
        $this->assertSame('DockerImages', $log->entity());
        $this->assertSame('UPDATE', $log->action());
    }
}
