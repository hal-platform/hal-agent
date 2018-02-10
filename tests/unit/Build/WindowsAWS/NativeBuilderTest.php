<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Testing\IOTestCase;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;

class NativeBuilderTest extends IOTestCase
{
    use MockeryPHPUnitIntegration;

    public $logger;
    public $runner;
    public $powershell;

    public $ssm;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->runner = Mockery::mock(SSMCommandRunner::class);
        $this->powershell = new Powershellinator('c:\builds', 'c:\build-scripts', 'c:\tools');

        $this->ssm = Mockery::mock(SsmClient::class);
    }

    public function testSuccess()
    {
        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', Mockery::any(), Mockery::any())
            ->times(4)
            ->andReturn(true);

        $builder = new NativeBuilder($this->logger, $this->runner, $this->powershell, 600);
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
            '* Running build step [ [1/2] step1 ] in Windows AWS',
            '* Running build step [ [2/2] step2 --flag ] in Windows AWS',
        ];

        $this->assertSame(true, $success);
        $this->assertContainsLines($expected, $this->output());
    }

    public function testFailOnPrepareInstance()
    {
        $this->logger
            ->shouldReceive('event')
            ->with('failure', 'Failed to prepare builder')
            ->once();

        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', Mockery::any(), Mockery::any())
            ->once()
            ->andReturn(false);

        $builder = new NativeBuilder($this->logger, $this->runner, $this->powershell, 600);
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
        $runs = [];
        $capture = Mockery::on(function($v) use (&$runs) {
            $runs[] = $v;
            return true;
        });

        $this->runner
            ->shouldReceive('__invoke')
            ->with($this->ssm, 'i-1234', 'AWS-RunPowerShellScript', $capture, Mockery::any())
            ->times(4)
            ->andReturn(true);

        $builder = new NativeBuilder($this->logger, $this->runner, $this->powershell, 600);
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
        $this->assertCount(4, $runs);

        $prepareSSM = $runs[0];
        $step1SSM = $runs[1];
        $step2SSM = $runs[2];
        $exportSSM = $runs[3];

        $this->assertSame($this->expectedPrepareScript(), implode("\n", $prepareSSM['commands']));
        $this->assertSame($this->expectedStep1Script(), implode("\n", $step1SSM['commands']));
        $this->assertSame($this->expectedStep2Script(), implode("\n", $step2SSM['commands']));
        $this->assertSame($this->expectedExportScript(), implode("\n", $exportSSM['commands']));
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
New-Item c:\build-scripts\J-1234 -type directory
$command0 = @'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\J-1234\j-1234_env.ps1" ) { . "c:\build-scripts\J-1234\j-1234_env.ps1" }

step1

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

'@
$command0 | Out-File -FilePath c:\build-scripts\J-1234\j-1234_0.ps1 -Encoding UTF8

$command1 = @'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\J-1234\j-1234_env.ps1" ) { . "c:\build-scripts\J-1234\j-1234_env.ps1" }

step2 --flag

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

'@
$command1 | Out-File -FilePath c:\build-scripts\J-1234\j-1234_1.ps1 -Encoding UTF8

$envfile = @'
$env:TEST_VAR = '1234'

'@
$envfile | Out-File -FilePath c:\build-scripts\J-1234\j-1234_env.ps1 -Encoding UTF8

POWERSHELL_TEXT;
    }

    private function expectedStep1Script()
    {
        return <<<'POWERSHELL_TEXT'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
c:\tools\execute-user-script-insecure.ps1 -script c:\build-scripts\J-1234\j-1234_0.ps1 ; Exit $LastExitCode
POWERSHELL_TEXT;
    }

    private function expectedStep2Script()
    {
        return <<<'POWERSHELL_TEXT'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
c:\tools\execute-user-script-insecure.ps1 -script c:\build-scripts\J-1234\j-1234_1.ps1 ; Exit $LastExitCode
POWERSHELL_TEXT;
    }

    private function expectedExportScript()
    {
        return <<<'POWERSHELL_TEXT'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
New-Item c:\builds\J-1234-output -type directory
Copy-Item c:\builds\J-1234\* -Destination c:\builds\J-1234-output -Recurse
POWERSHELL_TEXT;
    }
}
