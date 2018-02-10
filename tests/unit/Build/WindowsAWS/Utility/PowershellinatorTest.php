<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Utility;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PowershellinatorTest extends TestCase
{
    public $powershell;

    public function setUp()
    {
        $this->powershell = new Powershellinator('c:\builds', 'c:\build-scripts', 'c:\tools');
    }

    public function testGetConfigurationPaths()
    {
        $this->assertSame('c:\builds', $this->powershell->getBaseBuildPath());
        $this->assertSame('c:\build-scripts', $this->powershell->getBaseBuildScriptPath());
        $this->assertSame('c:\tools', $this->powershell->getToolsPath());
        $this->assertSame('c:\tools\execute-user-script-insecure.ps1', $this->powershell->getExecutorScriptPath());

        $this->assertSame('c:\build-scripts\b1234\b1234_5.ps1', $this->powershell->getUserScriptFilePath('b1234', '5'));
        $this->assertSame('c:\container-scripts\b1234_5.ps1', $this->powershell->getUserScriptFilePathForContainer('c:\container-scripts', 'b1234', '5'));
    }

    public function testGetStandardHeader()
    {
        $expected = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
POWERSHELL;

        $this->assertSame($expected, $this->powershell->getStandardPowershellHeader());
    }

    public function testInvalidPowershellScriptThrowsException()
    {
        $this->expectException(InvalidArgumentException::class);

        $this->powershell->getScript('derpherp');
    }

    public function testGetScriptWithIncompleteParamsThrowsException()
    {
        $this->expectException(InvalidArgumentException::class, 'Missing value from powershell parameters: "inputDir"');

        $this->powershell->getScript('transferBuildToOutput');
    }

    public function testGetPowershellScript()
    {
        $expected = <<<'POWERSHELL'
if (-not (Test-Path "${env:ProgramFiles(x86)}\GnuWin32\bin\bsdtar.exe")) {
    Throw "bsdtar is missing. Please install LibArchive for Windows."
}
Set-Alias bsdtar "${env:ProgramFiles(x86)}\GnuWin32\bin\bsdtar.exe"
if (-not (Test-Path "c:\build")) {
    New-Item c:\build -type directory
}

bsdtar -cz --file=c:\file.tar.gz -C c:\build .

Remove-Item c:\build -Recurse -Force
POWERSHELL;

        $script = $this->powershell->getScript('tarBuild', [
            'localFile' => 'c:\file.tar.gz',
            'buildDir' => 'c:\build'
        ]);

        $this->assertSame($expected, $script);

    }
}
