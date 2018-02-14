<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Docker\DockerImageValidator;
use Hal\Agent\Docker\WindowsSSMDockerinator;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\JobConfiguration\StepParser;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class DockerBuilderTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $runner;
    public $docker;
    public $validator;
    public $powershell;
    public $steps;

    public $ssm;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->runner = Mockery::mock(SSMCommandRunner::class);
        $this->docker = Mockery::mock(WindowsSSMDockerinator::class, [
            'cleanupContainer' => true
        ]);
        $this->validator = Mockery::mock(DockerImageValidator::class);
        $this->powershell = new Powershellinator('c:\builds', 'c:\build-scripts', 'c:\tools');
        $this->steps = Mockery::mock(StepParser::class);

        $this->ssm = Mockery::mock(SsmClient::class);
    }

    public function testSuccess()
    {
        $this->validator
            ->shouldReceive('validate')
            ->with('my-image:latest')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->with('my-image:latest', ['step1', 'step2 --flag'])
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->with($this->ssm, 'i-1234', 'my-image:latest', '1_j-1234')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->with($this->ssm, 'i-1234', 'J-1234', '1_j-1234', 'c:\builds\J-1234')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startContainer')
            ->with($this->ssm, 'i-1234', '1_j-1234')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->with($this->ssm, 'i-1234', '1_j-1234', 'c:\builds\J-1234-output')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('cleanupContainer')
            ->with($this->ssm, 'i-1234', '1_j-1234')
            ->once()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('runCommand')
            ->with($this->ssm, 'i-1234', '1_j-1234', Mockery::any(), 'Build step [1/2] "step1"')
            ->andReturn(true)
            ->once();
        $this->docker
            ->shouldReceive('runCommand')
            ->with($this->ssm, 'i-1234', '1_j-1234', Mockery::any(), 'Build step [2/2] "step2 --flag"')
            ->andReturn(true)
            ->once();

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $expected = [
            '! [NOTE] Preparing AWS instance for job ',
            '! [NOTE] Starting Docker container',
            '! [NOTE] Docker container "1_j-1234" started',
            '* Running build step [ [1/2] step1 ] in Windows AWS Docker container',
            '* Running build step [ [2/2] step2 --flag ] in Windows AWS Docker container',
            '! [NOTE] Cleaning up Docker container "1_j-1234"'
        ];

        $this->assertSame(true, $success);
        $this->assertContainsLines($expected, $this->output());
    }

    public function testFailOnValidateImage()
    {
        $this->validator
            ->shouldReceive('validate')
            ->with('my-image:latest')
            ->andReturn(false);

        // next thing never runs
        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnPrepareInstance()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->runner
            ->shouldReceive('__invoke')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->never();

        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Failed to prepare builder')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailAtCopyIn()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->runner
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnStartContainer()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->runner
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startContainer')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('runCommand')
            ->never();

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnBuildStep()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->runner
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('runCommand')
            ->once()
            ->andReturn(false);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->never();

        $this->logger
            ->shouldReceive('event')
            ->with('info', 'Skipping 1 remaining build steps')
            ->once();

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testFailOnCopyFromContainer()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:latest');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:latest', ['step1', 'step2 --flag']]
            ]);

        $this->runner
            ->shouldReceive('__invoke')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('runCommand')
            ->twice()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->once()
            ->andReturn(false);

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(false, $success);
    }

    public function testPowershellScriptsAreCreatedCorrectly()
    {
        $this->validator
            ->shouldReceive('validate')
            ->andReturn('my-image:1.0');

        $this->steps
            ->shouldReceive('organizeCommandsIntoJobs')
            ->andReturn([
                ['my-image:1.0', ['step1']],
                ['my-other-image:2.0', ['step2 --flag']],
            ]);

        $runs = [];
        $capture = Mockery::on(function($v) use (&$runs) {
            $runs[] = $v;
            return true;
        });

        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', $capture, Mockery::any())
            ->twice()
            ->andReturn(true);

        $this->docker
            ->shouldReceive('createContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyIntoContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('startContainer')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('runCommand')
            ->andReturn(true);

        $this->docker
            ->shouldReceive('copyFromContainer')
            ->andReturn(true);

        $builder = new DockerBuilder($this->logger, $this->runner, $this->docker, $this->validator, $this->powershell, $this->steps);
        $builder->setIO($this->io());
        $success = $builder(
            'J-1234',
            'my-image:latest',
            $this->ssm,
            'i-1234',
            ['step1', 'step2 --flag'],
            ['TEST_VAR' => '1234']
        );

        $this->assertSame(true, $success);
        $this->assertCount(2, $runs);

        $prepareSSM = $runs[0];
        $shiftSSM = $runs[1];

        $this->assertSame($this->expectedPrepareScript(), implode("\n", $prepareSSM['commands']));
        $this->assertSame($this->expectedShiftScript(), implode("\n", $shiftSSM['commands']));
    }

    private function expectedPrepareScript()
    {
        return <<<'POWERSHELL_TEXT'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
if (-not (Test-Path "c:\builds\J-1234")) {
    Throw "Build directory is missing: c:\builds\J-1234"
}

if (-not (Test-Path "c:\tools\execute-user-script-insecure.ps1")) {
    Throw "Build executor is missing: c:\tools\execute-user-script-insecure.ps1"
}
$token = Get-ECRAuthorizationToken

# Split the token into username and password segments
$tokenSegments = [System.Text.Encoding]::ASCII.GetString([System.Convert]::FromBase64String($token.AuthorizationToken)).Split(":")

# Get the host name without https, as this can confuse some Windows machines
$ecrHost = (New-Object System.Uri $token.ProxyEndpoint).DnsSafeHost

docker login `
    -u $($tokenSegments[0]) `
    -p $($tokenSegments[1]) `
    $ecrHost
New-Item c:\build-scripts\J-1234 -type directory
$command0 = @'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\j-1234_env.ps1" ) { . "c:\build-scripts\j-1234_env.ps1" }

step1

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

'@
$command0 | Out-File -FilePath c:\build-scripts\J-1234\j-1234_1_1.ps1 -Encoding UTF8

$command1 = @'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\j-1234_env.ps1" ) { . "c:\build-scripts\j-1234_env.ps1" }

step2 --flag

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

'@
$command1 | Out-File -FilePath c:\build-scripts\J-1234\j-1234_2_1.ps1 -Encoding UTF8

$envfile = @'
$env:TEST_VAR = '1234'

'@
$envfile | Out-File -FilePath c:\build-scripts\J-1234\j-1234_env.ps1 -Encoding UTF8

POWERSHELL_TEXT;
    }

    private function expectedShiftScript()
    {
        return <<<'POWERSHELL_TEXT'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
if (Test-Path "c:\builds\J-1234") { Remove-Item "c:\builds\J-1234" -Recurse -Force }
New-Item c:\builds\J-1234 -type directory

Copy-Item c:\builds\J-1234-output\* -Destination c:\builds\J-1234 -Recurse

Remove-Item "c:\builds\J-1234-output" -Recurse -Force
New-Item c:\builds\J-1234-output -type directory
POWERSHELL_TEXT;
    }
}
