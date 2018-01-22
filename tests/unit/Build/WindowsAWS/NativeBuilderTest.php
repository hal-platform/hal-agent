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
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;

class NativeBuilderTest extends MockeryTestCase
{
    public $logger;
    public $runner;
    public $powershell;
    public $ssm;

    public $runs;
    public $logs;

    public function setUp()
    {
        $this->logger = Mockery::mock(EventLogger::class);
        $this->runner = Mockery::mock(SSMCommandRunner::class);
        $this->powershell = new Powershellinator('c:\builds', 'c:\build-scripts', 'c:\tools');

        $this->ssm = Mockery::mock(SsmClient::class);

        $this->runs = [];
        $this->logs = [];
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

        $expectedPrepare = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
if (-not (Test-Path "c:\builds\b1234")) {
    Throw "Build directory is missing: c:\builds\b1234"
}

if (-not (Test-Path "c:\tools\execute-user-script-insecure.ps1")) {
    Throw "Build executor is missing: c:\tools\execute-user-script-insecure.ps1"
}
New-Item c:\build-scripts\b1234 -type directory
$command0 = @'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\b1234\b1234_env.ps1" ) { . "c:\build-scripts\b1234\b1234_env.ps1" }

dir

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

'@
$command0 | Out-File -FilePath c:\build-scripts\b1234\b1234_0.ps1 -Encoding UTF8

$command1 = @'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)

try {

if ( Test-Path "c:\build-scripts\b1234\b1234_env.ps1" ) { . "c:\build-scripts\b1234\b1234_env.ps1" }

cp file1.txt file2.txt

if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }

} catch {
    Write-Error $_
    exit 1
}

'@
$command1 | Out-File -FilePath c:\build-scripts\b1234\b1234_1.ps1 -Encoding UTF8

$envfile = @'
$env:ENV1 = 'test'
$env:ENV_SECOND = 'test2'

'@
$envfile | Out-File -FilePath c:\build-scripts\b1234\b1234_env.ps1 -Encoding UTF8

POWERSHELL;

        $expectedBuild1 = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
c:\tools\execute-user-script-insecure.ps1 -script c:\build-scripts\b1234\b1234_0.ps1 ; Exit $LastExitCode
POWERSHELL;

        $expectedBuild2 = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
c:\tools\execute-user-script-insecure.ps1 -script c:\build-scripts\b1234\b1234_1.ps1 ; Exit $LastExitCode
POWERSHELL;

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

        $builder = new NativeBuilder($this->logger, $this->runner, $this->powershell, '60');
        $result = $builder($this->ssm, 'mydockerimage', 'i-1234', 'b1234', $commands, $env);

        $prepareParams = $this->runs[0];
        $buildCommand1 = $this->runs[1];
        $buildCommand2 = $this->runs[2];

        $this->assertSame(true, $result);
        $this->assertSame($expectedPrepare, implode("\n", $prepareParams['commands']));
        $this->assertSame($expectedBuild1, implode("\n", $buildCommand1['commands']));
        $this->assertSame($expectedBuild2, implode("\n", $buildCommand2['commands']));
    }
}
