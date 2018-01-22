<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Utility;

/**
 * Powershell is not great.
 *
 * http://joshua.poehls.me/2012/powershell-script-module-boilerplate
 *
 * I'll just leave these here:
 * https://github.com/Microsoft/vsts-tasks
 * https://github.com/Microsoft/vsts-agent
 */
class PowershellScripts
{
    /**
     * Log in to the private ECR for this account.
     */
    const PRIME_DOCKER_LOGIN = <<<'POWERSHELL'
$token = Get-ECRAuthorizationToken

# Split the token into username and password segments
$tokenSegments = [System.Text.Encoding]::ASCII.GetString([System.Convert]::FromBase64String($token.AuthorizationToken)).Split(":")

# Get the host name without https, as this can confuse some Windows machines
$ecrHost = (New-Object System.Uri $token.ProxyEndpoint).DnsSafeHost

docker login `
    -u $($tokenSegments[0]) `
    -p $($tokenSegments[1]) `
    $ecrHost
POWERSHELL;

    const SAFE_POWERSHELL_HEADER = <<<'POWERSHELL'
Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"
$ProgressPreference = 'SilentlyContinue'
POWERSHELL;

    const WIDE_CONSOLE_HEADER = <<<'POWERSHELL'
$Host.UI.RawUI.BufferSize = New-Object Management.Automation.Host.Size (500, 25)
POWERSHELL;

    const EXECUTOR_SCRIPT = <<<'POWERSHELL'
Param (
    [Parameter(Mandatory=$True)]  [string] $script
)

if (-not (Test-Path $script)) {
    Throw "User script is missing: $script"
}

$ps_args = @(
    "-InputFormat None",
    "-NonInteractive",
    "-NoProfile",
    "-ExecutionPolicy Unrestricted",
    "-File $script"
)

try
{
    $process = Start-Process `
        -FilePath 'powershell.exe' `
        -ArgumentList $ps_args `
        -PassThru `
        -NoNewWindow `
        -Wait

    $exitCode = $process.ExitCode
    Exit $exitCode

} catch {
    Write-Error $_
    Exit 1
}

POWERSHELL;

    /**
     * @param string $baseBuildPath
     * @param string $baseBuildScriptPath
     * @param string $baseToolsPath
     * @param string $executorScriptFile
     *
     * @return string
     */
    public static function verifyAndPrepareBuilder($baseBuildPath, $baseBuildScriptPath, $baseToolsPath, $executorScriptFile)
    {
        $executorScriptContent = self::EXECUTOR_SCRIPT;

        $powershell = <<<POWERSHELL
if (-not (Test-Path "${baseBuildPath}")) {
    New-Item ${baseBuildPath} -type directory
}

if (-not (Test-Path "${baseBuildScriptPath}")) {
    New-Item ${baseBuildScriptPath} -type directory
}

if (-not (Test-Path "${baseToolsPath}")) {
    New-Item ${baseToolsPath} -type directory
}

if (-not (Test-Path "${executorScriptFile}")) {

\$executorScript = @'
${executorScriptContent}
'@
\$executorScript | Out-File -FilePath ${executorScriptFile} -Encoding UTF8

}

POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $executorScript
     * @param string $inputDir
     *
     * @return string
     */
    public static function verifyBuildEnvironment($executorScript, $inputDir)
    {
        $powershell = <<<POWERSHELL
if (-not (Test-Path "${inputDir}")) {
    Throw "Build directory is missing: ${inputDir}"
}

if (-not (Test-Path "${executorScript}")) {
    Throw "Build executor is missing: ${executorScript}"
}
POWERSHELL;

        return $powershell;
    }

    /**
     * @return string
     */
    public static function loginDocker()
    {
        $powershell = self::PRIME_DOCKER_LOGIN;

        return $powershell;
    }

    /**
     * @param string $executorScript
     * @param string $buildScript
     *
     * @return string
     */
    public static function runBuildScriptNative($executorScript, $buildScript)
    {
        $powershell = <<<POWERSHELL
${executorScript} -script ${buildScript} ; Exit \$LastExitCode
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $buildScript
     *
     * @return string
     */
    public static function runBuildScriptDocker($buildScript)
    {
        $powershell = <<<POWERSHELL
powershell -NonInteractive -NoProfile -InputFormat None -OutputFormat Text -ExecutionPolicy Unrestricted -File ${buildScript} ; Exit \$LastExitCode
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $command
     *
     * @return string
     */
    public static function runBuildCommandDocker($command)
    {
        // It is important to remember - Windows is the tool of Satan - DO NOT USE BASE64 ENCODE
        // $buildCommand = base64_encode(mb_convert_encoding($command, 'utf-16', 'utf-8'));
        $buildCommand = str_replace("\n", ";", $command);
        $escaped = str_replace(['"'], ['`"'], $buildCommand);

        $powershell = <<<POWERSHELL
powershell -NonInteractive -NoProfile -InputFormat None -OutputFormat Text -ExecutionPolicy Unrestricted -Command "${escaped}"
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $inputDir
     * @param string $outputDir
     *
     * @return string
     */
    public static function transferBuildToOutput($inputDir, $outputDir)
    {
        $powershell = <<<POWERSHELL
New-Item ${outputDir} -type directory
Copy-Item ${inputDir}\* -Destination ${outputDir} -Recurse
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $inputDir
     * @param string $buildScriptPath
     *
     * @return string
     */
    public static function cleanupAfterBuild($inputDir, $buildScriptPath)
    {
        $powershell = <<<POWERSHELL
if (Test-Path "${inputDir}") { Remove-Item "${inputDir}" -Recurse -Force }
if (Test-Path "${buildScriptPath}") { Remove-Item "${buildScriptPath}" -Recurse -Force }
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $outputDir
     * @param string $inputDir
     * @param string $buildScriptPath
     *
     * @return string
     */
    public static function cleanupAfterBuildOutput($outputDir, $inputDir, $buildScriptPath)
    {
        $powershell = <<<POWERSHELL
if (Test-Path "${outputDir}") { Remove-Item "${outputDir}" -Recurse -Force }
POWERSHELL;
        return $powershell . "\n" . self::cleanupAfterBuild($inputDir, $buildScriptPath);
    }

    /**
     * @param string $localFile
     * @param string $unpackDir
     *
     * @return string
     */
    public static function untarBuild($localFile, $unpackDir)
    {
        $header = <<<'POWERSHELL'
if (-not (Test-Path "${env:ProgramFiles(x86)}\GnuWin32\bin\bsdtar.exe")) {
    Throw "bsdtar is missing. Please install LibArchive for Windows."
}
Set-Alias bsdtar "${env:ProgramFiles(x86)}\GnuWin32\bin\bsdtar.exe"
POWERSHELL;

        $command = <<<POWERSHELL
New-Item ${unpackDir} -type directory

bsdtar -xz --file=${localFile} --directory=${unpackDir}

Remove-Item ${localFile} -Force
POWERSHELL;

        return $header . "\n" . $command;
    }

    /**
     * @param string $localFile
     * @param string $buildDir
     *
     * @return string
     */
    public static function tarBuild($localFile, $buildDir)
    {
        $header = <<<'POWERSHELL'
if (-not (Test-Path "${env:ProgramFiles(x86)}\GnuWin32\bin\bsdtar.exe")) {
    Throw "bsdtar is missing. Please install LibArchive for Windows."
}
Set-Alias bsdtar "${env:ProgramFiles(x86)}\GnuWin32\bin\bsdtar.exe"
POWERSHELL;

        $command = <<<POWERSHELL
bsdtar -cz --file=${localFile} -C ${buildDir} .

Remove-Item ${buildDir} -Recurse -Force
POWERSHELL;

        return $header . "\n" . $command;
    }

    /**
     * @param string $localFile
     * @param string $bucket
     * @param string $object
     *
     * @return string
     */
    public static function downloadBuild($localFile, $bucket, $object)
    {
        $powershell = <<<POWERSHELL
Write-Host 'Downloading artifact from s3://${bucket}/${object}'

Read-S3Object `
    -BucketName ${bucket} `
    -Key ${object} `
    -File ${localFile}
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $localFile
     * @param string $bucket
     * @param string $object
     *
     * @return string
     */
    public static function uploadBuild($localFile, $bucket, $object)
    {
        $powershell = <<<POWERSHELL
Write-Host 'Uploading artifact to s3://${bucket}/${object}'

Write-S3Object `
    -BucketName ${bucket} `
    -Key ${object} `
    -File ${localFile} `
    -CannedACLName "bucket-owner-full-control"

Remove-Item ${localFile} -Force
POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $buildScriptDir
     * @param array $commandsWithScripts
     *
     * @return string
     */
    public static function writeUserCommandsToBuildScripts($buildScriptDir, array $commandsWithScripts)
    {
        $powershell = <<<POWERSHELL
New-Item ${buildScriptDir} -type directory
POWERSHELL;
        foreach ($commandsWithScripts as $num => $command) {
            $scriptContents = $command['script'];
            $scriptFile = $command['file'];
            $writeScript = <<<POWERSHELL
\$command${num} = @'
${scriptContents}
'@
\$command{$num} | Out-File -FilePath ${scriptFile} -Encoding UTF8

POWERSHELL;

            $powershell .= "\n" . $writeScript;
        }

        return $powershell;
    }

    /**
     * @param string $envFile
     * @param array $env
     *
     * @return string
     */
    public static function writeEnvFile($envFile, array $env)
    {
        $scriptContents = '';
        foreach ($env as $name => $value) {
            // $buildCommand = str_replace("\n", ";", $command);
            // $escaped = str_replace(['"'], ['`"'], $buildCommand);
            $value = str_replace("'", "\'", $value);

            $scriptContents .= <<<POWERSHELL
\$env:${name} = '${value}'

POWERSHELL;
        }

        $powershell = <<<POWERSHELL
\$envfile = @'
${scriptContents}
'@
\$envfile | Out-File -FilePath ${envFile} -Encoding UTF8

POWERSHELL;

        return $powershell;
    }

    /**
     * @param string $command
     * @param string $envFile
     *
     * @return string
     */
    public static function getBuildScript($command, $envFile = '')
    {
        $header = self::SAFE_POWERSHELL_HEADER;
        $wide = self::WIDE_CONSOLE_HEADER;

        $sourceEnvFile = '';
        if ($envFile) {
            $sourceEnvFile = <<<POWERSHELL
if ( Test-Path "${envFile}" ) { . "${envFile}" }

POWERSHELL;
        }

        $powershell = <<<POWERSHELL
${header}
${wide}

try {

${sourceEnvFile}
${command}

if ( Test-Path variable:global:LastExitCode ) { Exit \$LastExitCode }

} catch {
    Write-Error \$_
    exit 1
}

POWERSHELL;

        return $powershell;
    }
}
