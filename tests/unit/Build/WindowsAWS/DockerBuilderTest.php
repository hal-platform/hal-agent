<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Testing\MockeryTestCase;
use Mockery;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Docker\DockerImageValidator;
use Hal\Agent\Build\WindowsAWS\Utility\Dockerinator;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;

class DockerBuilderTest extends MockeryTestCase
{
    public $logger;
    public $runner;
    public $docker;
    public $powershell;
    public $validator;
    public $ssm;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->runner = Mockery::mock(SSMCommandRunner::class);
        $this->docker = Mockery::mock(Dockerinator::class);
        $this->powershell = new Powershellinator('c:\builds', 'c:\build-scripts', 'c:\tools');
        $this->validator = Mockery::mock(DockerImageValidator::class);

        $this->ssm = Mockery::mock(SsmClient::class);
    }

    public function testSuccess()
    {
        $commands = [
            'dir',
            'cp file1.txt file2.txt'
        ];

        $env = [
            'ENV1' => 'test',
            'ENV_SECOND' => 'test2'
        ];

        $buildScript1 = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\b1234_env.ps1" ) { . "c:\build-scripts\b1234_env.ps1" }

dir

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

POWERSHELL;

        $buildScript2 = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\b1234_env.ps1" ) { . "c:\build-scripts\b1234_env.ps1" }

cp file1.txt file2.txt

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

POWERSHELL;
        $this->validator
            ->shouldReceive('validate')
            ->with('mycustom/image')
            ->andReturn('mycustom/image')
            ->once();

        $this->runner
            ->shouldReceive('__invoke')
            ->with(
                $this->ssm,
                'i-1234',
                'AWS-RunPowerShellScript',
                Mockery::on(function($v) {
                    $this->runs[] = $v;
                    return true;
                }),
                Mockery::on(function($v) {
                    $this->logs[] = $v;
                    return true;
                })
            )
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->with($this->ssm, 'i-1234', 'mycustom/image', 'b1234')
            ->andReturn(true)
            ->once();
        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->with($this->ssm, 'i-1234', 'b1234', 'b1234', 'c:\builds\b1234')
            ->andReturn(true)
            ->once();
        $this->docker
            ->shouldReceive('startContainer')
            ->with($this->ssm, 'i-1234', 'b1234')
            ->andReturn(true)
            ->once();
        $this->docker
            ->shouldReceive('copyFromContainer')
            ->with($this->ssm, 'i-1234', 'b1234', 'c:\builds\b1234-output')
            ->andReturn(true)
            ->once();
        $this->docker
            ->shouldReceive('cleanupContainer')
            ->with($this->ssm, 'i-1234', 'b1234')
            ->andReturn(true)
            ->once();

        $parsedCommand1 = [
            'command' => 'dir',
            'script' => $buildScript1,
            'file' => 'c:\build-scripts\b1234\b1234_0.ps1',
            'container_file' => 'c:\build-scripts\b1234_0.ps1'
        ];

        $parsedCommand2 = [
            'command' => 'cp file1.txt file2.txt',
            'script' => $buildScript2,
            'file' => 'c:\build-scripts\b1234\b1234_1.ps1',
            'container_file' => 'c:\build-scripts\b1234_1.ps1'
        ];

        $this->docker
            ->shouldReceive('runCommand')
            ->with($this->ssm, 'i-1234', 'b1234', $parsedCommand1, 'Run build command [1/2] "dir"')
            ->andReturn(true)
            ->once();
        $this->docker
            ->shouldReceive('runCommand')
            ->with($this->ssm, 'i-1234', 'b1234', $parsedCommand2, 'Run build command [2/2] "cp file1.txt file2.txt"')
            ->andReturn(true)
            ->once();

        $builder = new DockerBuilder(
            $this->logger,
            $this->runner,
            $this->docker,
            $this->powershell,
            $this->validator
        );
        $builder->disableShutdownHandler();
        $result = $builder($this->ssm, 'mycustom/image', 'i-1234', 'b1234', $commands, $env);

        $this->assertSame(true, $result);
    }

    public function testNotAllowedImageFails()
    {
        $commands = [
            'dir',
            'cp file1.txt file2.txt'
        ];

        $this->validator
            ->shouldReceive('validate')
            ->with('myinvalid/image')
            ->andReturnNull()
            ->once();

        $this->runner
            ->shouldReceive('__invoke')
            ->never();

        $this->docker
            ->shouldReceive('createContainer')
            ->never();


        $builder = new DockerBuilder(
            $this->logger,
            $this->runner,
            $this->docker,
            $this->powershell,
            $this->validator
        );
        $builder->disableShutdownHandler();
        $result = $builder($this->ssm, 'myinvalid/image', 'i-1234', 'b1234', $commands, []);

        $this->assertSame(false, $result);
    }
}
