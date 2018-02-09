<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Utility;

use InvalidArgumentException;

/**
 * $s = New-PSSession -ComputerName xx -Credential yy
 * Invoke-Command -Session $s
 */
class Powershellinator
{
    // const EXECUTE_USER_SCRIPT_PS1 = 'execute-user-script.ps1';
    const EXECUTE_USER_SCRIPT_PS1 = 'execute-user-script-insecure.ps1';

    /**
     * @var string
     */
    private $buildPath;
    private $buildScriptsPath;
    private $toolsPath;

    /**
     * @var string
     */
    private $executorScript;

    /**
     * @param string $buildPath
     * @param string $buildScriptsPath
     * @param string $toolsPath
     */
    public function __construct($buildPath, $buildScriptsPath, $toolsPath)
    {
        $this->buildPath = $buildPath;
        $this->buildScriptsPath = $buildScriptsPath;
        $this->toolsPath = $toolsPath;

        $this->executorScript = self::EXECUTE_USER_SCRIPT_PS1;
    }

    /**
     * @return string
     */
    public function getBaseBuildPath()
    {
        return $this->buildPath;
    }

    /**
     * @return string
     */
    public function getBaseBuildScriptPath()
    {
        return $this->buildScriptsPath;
    }

    /**
     * @return string
     */
    public function getToolsPath()
    {
        return $this->toolsPath;
    }

    /**
     * @return string
     */
    public function getStandardPowershellHeader()
    {
        return implode("\n", [
            PowershellScripts::SAFE_POWERSHELL_HEADER,
            PowershellScripts::WIDE_CONSOLE_HEADER
        ]);
    }

    /**
     * @param string $buildID
     *
     * @return string
     */
    public function getBuildScriptPath($buildID)
    {
        $buildScriptPath = $this->getBaseBuildScriptPath();
        return "${buildScriptPath}\\${buildID}";
    }

    /**
     * @param string $buildID
     * @param string $scriptNum
     *
     * @return string
     */
    public function getUserScriptFile($buildID, $scriptNum)
    {
        $buildPrefix = strtolower(str_replace('.', '', $buildID));
        return "${buildPrefix}_${scriptNum}.ps1";
    }

    /**
     * @param string $buildID
     * @param string $scriptNum
     *
     * @return string
     */
    public function getUserScriptFilePath($buildID, $scriptNum)
    {
        $scriptFilename = $this->getUserScriptFile($buildID, $scriptNum);
        return $this->getBuildScriptPath($buildID) . "\\${scriptFilename}";
    }

    /**
     * @param string $scriptBasePath
     * @param string $buildID
     * @param string $scriptNum
     *
     * @return string
     */
    public function getUserScriptFilePathForContainer($scriptBasePath, $buildID, $scriptNum)
    {
        $scriptFilename = $this->getUserScriptFile($buildID, $scriptNum);
        return "${scriptBasePath}\\${scriptFilename}";
    }

    /**
     * @return string
     */
    public function getExecutorScriptPath()
    {
        return $this->getToolsPath() . '\\' . $this->executorScript;
    }

    /**
     * @param string $name
     * @param array $params
     *
     * @return string
     */
    public function getScript($name, array $params = [])
    {
        # verifiers
        if ($name === 'verifyAndPrepareBuilder') {
            $basePath = $this->getBaseBuildPath();
            $scriptsPath = $this->getBaseBuildScriptPath();
            $toolsPath = $this->getToolsPath();
            $executorScript = $this->getExecutorScriptPath();
            return PowershellScripts::verifyAndPrepareBuilder($basePath, $scriptsPath, $toolsPath, $executorScript);

        } elseif ($name === 'verifyBuildEnvironment') {
            $this->verifyScriptParameters(['inputDir'], $params);
            return PowershellScripts::verifyBuildEnvironment($this->getExecutorScriptPath(), $params['inputDir']);

        } elseif ($name === 'loginDocker') {
            return PowershellScripts::loginDocker();

        # script runners
        } elseif ($name === 'runBuildScriptNative') {
            $this->verifyScriptParameters(['buildScript'], $params);
            return PowershellScripts::runBuildScriptNative($this->getExecutorScriptPath(), $params['buildScript']);

        } elseif ($name === 'runBuildScriptDocker') {
            $this->verifyScriptParameters(['buildScript'], $params);
            return PowershellScripts::runBuildScriptDocker($params['buildScript']);

        } elseif ($name === 'runBuildCommandDocker') {
            $this->verifyScriptParameters(['buildCommand'], $params);
            return PowershellScripts::runBuildCommandDocker($params['buildCommand']);

        # internal
        } elseif ($name === 'transferBuildToOutput') {
            $this->verifyScriptParameters(['inputDir', 'outputDir'], $params);
            return PowershellScripts::transferBuildToOutput($params['inputDir'], $params['outputDir']);

        } elseif ($name === 'shiftBuildWorkspaceFromOutput') {
            $this->verifyScriptParameters(['inputDir', 'outputDir'], $params);
            return PowershellScripts::shiftBuildWorkspaceFromOutput($params['outputDir'], $params['inputDir']);

        } elseif ($name === 'cleanupAfterBuild') {
            $this->verifyScriptParameters(['inputDir', 'buildID'], $params);
            $buildScriptPath = $this->getBuildScriptPath($params['buildID']);
            return PowershellScripts::cleanupAfterBuild($params['inputDir'], $buildScriptPath);

        } elseif ($name === 'cleanupAfterBuildOutput') {
            $this->verifyScriptParameters(['inputDir', 'buildID', 'outputDir'], $params);
            $buildScriptPath = $this->getBuildScriptPath($params['buildID']);
            return PowershellScripts::cleanupAfterBuildOutput($params['outputDir'], $params['inputDir'], $buildScriptPath);

        # pack/unpack files
        } elseif ($name === 'untarBuild') {
            $this->verifyScriptParameters(['localFile', 'unpackDir'], $params);
            return PowershellScripts::untarBuild($params['localFile'], $params['unpackDir']);

        } elseif ($name === 'tarBuild') {
            $this->verifyScriptParameters(['localFile', 'buildDir'], $params);
            return PowershellScripts::tarBuild($params['localFile'], $params['buildDir']);

        # download/upload files
        } elseif ($name === 'downloadBuild') {
            $this->verifyScriptParameters(['localFile', 'bucket', 'object'], $params);
            return PowershellScripts::downloadBuild($params['localFile'], $params['bucket'], $params['object']);

        } elseif ($name === 'uploadBuild') {
            $this->verifyScriptParameters(['localFile', 'bucket', 'object'], $params);
            return PowershellScripts::uploadBuild($params['localFile'], $params['bucket'], $params['object']);

        # commands -> scripts
        } elseif ($name === 'writeUserCommandsToBuildScripts') {
            $this->verifyScriptParameters(['commandsParsed', 'buildID'], $params);
            $buildScriptPath = $this->getBuildScriptPath($params['buildID']);
            return PowershellScripts::writeUserCommandsToBuildScripts($buildScriptPath, $params['commandsParsed']);

        } elseif ($name === 'getBuildScript') {
            $this->verifyScriptParameters(['command', 'envFile'], $params);
            return PowershellScripts::getBuildScript($params['command'], $params['envFile']);

        } elseif ($name === 'writeEnvFile') {
            $this->verifyScriptParameters(['buildID', 'environment'], $params);
            $envFilePath = $this->getUserScriptFilePath($params['buildID'], 'env');
            return PowershellScripts::writeEnvFile($envFilePath, $params['environment']);
        }

        throw new InvalidArgumentException(sprintf('Invalid script specified: "%s"', $name));
    }

    /**
     * @param array $required
     * @param array $params
     *
     * @throws InvalidArgumentException
     */
    private function verifyScriptParameters(array $required, array $params)
    {
        foreach ($required as $name) {
            if (!isset($params[$name])) {
                throw new InvalidArgumentException(sprintf('Missing value from powershell parameters: "%s"', $name));
            }
        }
    }
}
