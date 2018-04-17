<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Docker;

use Aws\Ssm\SsmClient;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Type\JobEventStatusEnum;
use Hal\Core\Type\JobStatusEnum;

class WindowsSSMDockerinator
{
    use InternalDebugLoggingTrait;

    const CONTAINER_WORKING_DIR = 'c:\workspace';
    const CONTAINER_SCRIPTS_DIR = 'c:\build-scripts';

    private const STEP_1_CREATE_CONTAINER = 'Create Docker container';
    private const STEP_2_DOCKER_COPY_IN = 'Copy source into container';
    private const STEP_3_START_CONTAINER = 'Start Docker container';
    private const STEP_3A_PREPARE_CONTAINER = 'Prepare Docker container';
    //  STEP_4 = run build steps
    private const STEP_5_DOCKER_COPY_OUT = 'Copy artifacts from container';

    private const STEP_KILL_CONTAINER = 'Kill Docker container';
    private const STEP_REMOVE_CONTAINER = 'Remove Docker container';

    private const DEFAULT_TIMEOUT_INTERNAL_COMMAND = 120;
    private const DEFAULT_TIMEOUT_BUILD_COMMAND = 1800;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * Manually add hosts entries to the docker container. Provide a list like so:
     *
     * [
     *   myhostname: '127.0.0.1',
     *   myhostname2: '192.168.0.1',
     * ]
     *
     * @var array
     */
    private $manualDNS;

    /**
     * @var int
     */
    private $internalTimeout;
    private $buildTimeout;

    /**
     * @param EventLogger $logger
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     * @param string $manualDNS
     */
    public function __construct(
        EventLogger $logger,
        SSMCommandRunner $runner,
        Powershellinator $powershell,
        string $manualDNS = ''
    ) {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->powershell = $powershell;

        $this->manualDNS = $this->parseDNS($manualDNS);

        $this->internalTimeout = self::DEFAULT_TIMEOUT_INTERNAL_COMMAND;
        $this->buildTimeout = self::DEFAULT_TIMEOUT_BUILD_COMMAND;
    }

    /**
     * @param int $seconds
     *
     * @return void
     */
    public function setInternalCommandTimeout(int $seconds)
    {
        $this->internalTimeout = $seconds;
    }

    /**
     * @param int $seconds
     *
     * @return void
     */
    public function setBuildCommandTimeout(int $seconds)
    {
        $this->buildTimeout = $seconds;
    }

    /**
     * We use powershell so the container stays open, while we run other commands
     *
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $imageName
     * @param string $containerName
     *
     * @return bool
     */
    public function createContainer(SsmClient $ssm, $instanceID, $imageName, $containerName)
    {
        $command = [
            $this->docker('create'),
            '--tty=true',
            '--interactive=true',
            sprintf('--name "%s"', $containerName),
            sprintf('--workdir="%s"', self::CONTAINER_WORKING_DIR)
        ];

        # Docker for Windows is busted. See L170 - prepareContainer for the solution
        // foreach ($this->manualDNS as $name => $ip) {
        //     $command[] = sprintf('--add-host=%s:%s', $name, $ip);
        // }

        # Docker env-file doesn't support newlines
        // $command[] = sprintf('--env-file %s', $filename);
        // foreach ($env as $name => $var) {
        //     $command[] = sprintf('--env %s', $name);
        // }

        $command[] = $imageName;
        $command[] = 'powershell';

        if (!$response = $this->runInternalRemote($ssm, $instanceID, $this->safetize($command), self::STEP_1_CREATE_CONTAINER)) {
            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $containerName
     *
     * @return bool
     */
    public function prepareContainer(SsmClient $ssm, $instanceID, $containerName)
    {
        if (!$this->manualDNS) {
            return true;
        }

        $exec = [];
        foreach ($this->manualDNS as $name => $ip) {
            $command = [
                $this->docker('exec'),
                sprintf('"%s"', $containerName),
                $this->powershell->getScript('runBuildCommandDocker', [
                    'buildCommand' => sprintf("Add-Content '%s' '%s %s'", 'C:\Windows\System32\Drivers\etc\hosts', $ip, $name)
                ]),
            ];

            $exec[] = $this->safetize($command);
        }

        if (!$this->runInternalRemote($ssm, $instanceID, $exec, self::STEP_3A_PREPARE_CONTAINER)) {
            return false;
        }

        return true;
    }

    /**
     * Example usage in a shell:
     * > cat output.tar | docker cp - $containerName:/build
     *
     * Copy the contents of a tar (NOT in a subdirectory) into a directory in the container
     *
     * Also note: Docker can understand both raw tars, and gzip'd tars for copying
     * files into containers. However, it only exports to tar, NOT gzip'd tar.
     *
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $buildID
     *
     * @param string $containerName
     * @param string $inputDir
     *
     * @return bool
     */
    public function copyIntoContainer(SsmClient $ssm, $instanceID, $buildID, $containerName, $inputDir)
    {
        $copyInto = [
            $this->docker('cp'),
            "${inputDir}\.",
            sprintf('%s:%s', $containerName, self::CONTAINER_WORKING_DIR)
        ];

        $scriptsPath = $this->powershell->getBuildScriptPath($buildID);
        $copyScriptsInto = [
            $this->docker('cp'),
            "${scriptsPath}\.",
            sprintf('%s:%s', $containerName, self::CONTAINER_SCRIPTS_DIR)
        ];

        $commands = [
            $this->safetize($copyInto),
            $this->safetize($copyScriptsInto)
        ];

        if (!$this->runInternalRemote($ssm, $instanceID, $commands, self::STEP_2_DOCKER_COPY_IN)) {
            return false;
        }

        return true;
    }

    /**
     * Start container
     *
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     *
     * @return bool
     */
    public function startContainer(SsmClient $ssm, $instanceID, $containerName)
    {
        $start = [
            $this->docker('start'),
            $containerName
        ];

        if (!$this->runInternalRemote($ssm, $instanceID, $this->safetize($start), self::STEP_3_START_CONTAINER)) {
            return false;
        }

        if (!$this->prepareContainer($ssm, $instanceID, $containerName)) {
            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     * @param array $commandWithScript
     * @param string $customMessage
     *
     * @return bool|array
     */
    public function runCommand(SsmClient $ssm, $instanceID, $containerName, array $commandWithScript, $customMessage)
    {
        $exec = [
            $this->docker('exec'),
            sprintf('"%s"', $containerName),
            $this->powershell->getScript('runBuildScriptDocker', [
                'buildScript' => $commandWithScript['container_file']
            ])
        ];

        $customContext = [
            'command' => $commandWithScript['command'],
            'script' => $commandWithScript['script'],
            'scriptFile' => $commandWithScript['container_file']
        ];

        if (!$this->runBuildRemote($ssm, $instanceID, $this->safetize($exec), $customMessage, $customContext)) {
            return false;
        }

        // Ugh, powershell. Fail if there is any output to stderr
        $lastStatus = $this->runner->getLastStatus();
        if ($lastStatus['errorOutput']) {
            return false;
        }

        return true;
    }

    /**
     * Example usage in a shell:
     * > docker cp $containerName:/build/. - > output.tar
     * > docker cp $containerName:/build/. - | gzip > output.tar.gz
     *
     * Docker exports as tar when copying files out as an archive.
     * We pipe it to gzip so its tar.gz for use elsewhere in the hal system.
     *
     * It's a bit unnecessary but fine for now.
     *
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     * @param string $outputDir
     *
     * @return bool
     */
    public function copyFromContainer(SsmClient $ssm, $instanceID, $containerName, $outputDir)
    {
        $copyFrom = [
            $this->docker('cp'),
            sprintf('%s:%s', $containerName, self::CONTAINER_WORKING_DIR),
            $outputDir
        ];

        if (!$this->runInternalRemote($ssm, $instanceID, $this->safetize($copyFrom), self::STEP_5_DOCKER_COPY_OUT)) {
            return false;
        }

        return true;
    }

    /**
     * Kill and remove container
     *
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $containerName
     *
     * @return bool
     */
    public function cleanupContainer(SsmClient $ssm, $instanceID, $containerName)
    {
        $kill = [
            $this->docker('kill'),
            sprintf('"%s"', $containerName)
        ];

        $rm = [
            $this->docker('rm'),
            sprintf('"%s"', $containerName)
        ];

        // Do not care whether these fail
        $this->runInternalRemote($ssm, $instanceID, $this->safetize($kill), self::STEP_KILL_CONTAINER);
        $this->runInternalRemote($ssm, $instanceID, $this->safetize($rm), self::STEP_REMOVE_CONTAINER);

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string|string[] $command
     * @param string $customMessage
     * @param array $customContext
     *
     * @return bool
     */
    private function runBuildRemote(SsmClient $ssm, $instanceID, $command, $customMessage, $customContext)
    {
        if (!is_array($command)) {
            $command = [$command];
        }

        $commands = array_merge(
            $command,
            ['if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }']
        );

        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => $command,

            // 'workingDirectory' => $workDir,
            'executionTimeout' => [(string) $this->buildTimeout],
        ], [true, $customMessage, $customContext]);

        return $result;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string|string[] $command
     * @param string $customMessage
     *
     * @return bool
     */
    private function runInternalRemote(SsmClient $ssm, $instanceID, $command, $customMessage)
    {
        if (!is_array($command)) {
            $command = [$command];
        }

        $commands = array_merge(
            [$this->powershell->getStandardPowershellHeader()],
            $command,
            ['if ( Test-Path variable:global:LastExitCode ) { Exit $LastExitCode }']
        );

        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => $commands,

            // 'workingDirectory' => $workDir,
            'executionTimeout' => [(string) $this->internalTimeout],
        ], [$this->isDebugLoggingEnabled(), $customMessage]);

        return $result;
    }

    /**
     * @param array|string $command
     *
     * @return array
     */
    private function safetize($command)
    {
        if (is_array($command)) {
            $command = implode(" ", $command);
        }

        return $command;
    }

    /**
     * Returns a docker command
     *
     * @param string $command
     *
     * @return string
     */
    private function docker($command)
    {
        return 'docker ' . $command;
    }

    /**
     * @param string $manualDNS
     *
     * @return array
     */
    private function parseDNS($manualDNS)
    {
        $dnsPairs = explode(':', $manualDNS);
        $parsedDNS = [];

        foreach ($dnsPairs as $dns) {
            $matches = [];
            if (!preg_match('/([a-zA-z].*)=([a-zA-Z0-9\.].*)/', $dns, $matches)) {
                //TODO:: should we error here if the agent is not configured correctly
                continue;
            }

            $parsedDns[$matches[1]] = $matches[2];
        }

        return $parsedDNS;
    }
}
