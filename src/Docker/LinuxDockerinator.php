<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Docker;

use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\SSHProcess;

class LinuxDockerinator
{
    use InternalDebugLoggingTrait;

    const CONTAINER_WORKING_DIR = '/workspace';
    const DOCKER_SHELL = 'bash -l -c %s';

    private const STEP_1_CREATE_CONTAINER = 'Create Docker container';
    private const STEP_2_DOCKER_COPY_IN = 'Copy source code into container';
    private const STEP_3_START_CONTAINER = 'Start Docker container';
    //  STEP_4 = run build steps
    private const STEP_5_DOCKER_COPY_OUT = 'Copy artifacts from container';

    private const STEP_KILL_CONTAINER = 'Kill Docker container';
    private const STEP_REMOVE_CONTAINER = 'Remove Docker container';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSHProcess
     */
    private $remoter;
    private $buildRemoter;

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
     * @param EventLogger $logger
     * @param SSHProcess $remoter
     * @param SSHProcess $buildRemoter
     * @param string $manualDNS
     */
    public function __construct(
        EventLogger $logger,
        SSHProcess $remoter,
        SSHProcess $buildRemoter,
        string $manualDNS = ''
    ) {
        $this->logger = $logger;
        $this->remoter = $remoter;
        $this->buildRemoter = $buildRemoter;

        $this->manualDNS = $this->parseDNS($manualDNS);
    }

    /**
     * We use bash so the container stays open, while we run other commands
     *
     * @param string $remoteConnection
     * @param string $imageName
     * @param string $containerName
     * @param array $env
     *
     * @return bool
     */
    public function createContainer(string $remoteConnection, string $imageName, string $containerName, array $env): bool
    {
        $command = [
            $this->docker('create'),
            '--tty=true',
            '--interactive=true',
            sprintf('--name "%s"', $containerName),
            sprintf('--workdir="%s"', self::CONTAINER_WORKING_DIR)
        ];

        foreach ($this->manualDNS as $name => $ip) {
            $command[] = sprintf('--add-host=%s:%s', $name, $ip);
        }

        // @todo replace this with envfile in docker container like windows system

        # Docker env-file doesn't support newlines
        // $command[] = sprintf('--env-file %s', $filename);
        foreach ($env as $name => $var) {
            $command[] = sprintf('--env %s', $name);
        }

        $command[] = $imageName;
        $command[] = 'bash -l';

        if (!$response = $this->runInternalRemote($remoteConnection, $this->safetize($command), self::STEP_1_CREATE_CONTAINER, $env)) {
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
     * @param string $remoteConnection
     * @param string $jobID
     *
     * @param string $containerName
     * @param string $inputFile
     *
     * @return bool
     */
    public function copyIntoContainer(string $remoteConnection, string $jobID, string $containerName, string $inputFile): bool
    {
        $copyInto = [
            sprintf('cat %s', $inputFile),
            '|',
            $this->docker('cp'),
            '-',
            sprintf('%s:%s', $containerName, self::CONTAINER_WORKING_DIR)
        ];

        // $scriptsPath = $this->powershell->getBuildScriptPath($buildID);
        // $copyScriptsInto = [
        //     $this->docker('cp'),
        //     "${scriptsPath}\.",
        //     sprintf('%s:%s', $containerName, self::CONTAINER_SCRIPTS_DIR)
        // ];

        if (!$this->runInternalRemote($remoteConnection, $this->safetize($copyInto), self::STEP_2_DOCKER_COPY_IN)) {
            return false;
        }

        return true;
    }

    /**
     * Start docker container
     *
     * @param string $remoteConnection
     * @param string $containerName
     *
     * @return bool
     */
    public function startContainer(string $remoteConnection, string $containerName): bool
    {
        $start = [
            $this->docker('start'),
            $containerName
        ];

        if (!$this->runInternalRemote($remoteConnection, $this->safetize($start), self::STEP_3_START_CONTAINER)) {
            return false;
        }

        return true;
    }

    /**
     * Run command within a running docker container
     *
     * @param string $remoteConnection
     * @param string $containerName
     * @param string $command
     * @param string $message
     *
     * @return bool
     */
    public function runCommand(string $remoteConnection, string $containerName, string $command, string $message): bool
    {
        $prefix = [
            $this->docker('exec'),
            sprintf('"%s"', $containerName),
        ];

        $prefix = $this->safetize($prefix);
        $command = $this->safetize($command);

        if (!$this->runBuildRemote($remoteConnection, $prefix, $command, $message)) {
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
     * @param string $remoteConnection
     *
     * @param string $containerName
     * @param string $outputFile
     *
     * @return bool
     */
    public function copyFromContainer(string $remoteConnection, string $containerName, string $outputFile): bool
    {
        $copyFrom = [
            $this->docker('cp'),
            sprintf('%s:%s/.', $containerName, self::CONTAINER_WORKING_DIR),
            '-',
            '|', 'gzip',
            '>', $outputFile
        ];

        if (!$this->runInternalRemote($remoteConnection, $this->safetize($copyFrom), self::STEP_5_DOCKER_COPY_OUT)) {
            return false;
        }

        return true;
    }

    /**
     * Kill and remove container
     *
     * @param string $remoteConnection
     * @param string $containerName
     *
     * @return bool
     */
    public function cleanupContainer(string $remoteConnection, string $containerName): bool
    {
        $kill = [
            $this->docker('kill'),
            sprintf('"%s"', $containerName),
        ];

        $rm = [
            $this->docker('rm'),
            sprintf('"%s"', $containerName),
        ];

        // Do not care whether these fail
        $this->runInternalRemote($remoteConnection, $this->safetize($kill), self::STEP_KILL_CONTAINER);
        $this->runInternalRemote($remoteConnection, $this->safetize($rm), self::STEP_REMOVE_CONTAINER);

        return true;
    }

    /**
     * @param string $remoteConnection
     * @param string $command
     * @param string $message
     * @param array $env
     *
     * @return bool
     */
    private function runInternalRemote($remoteConnection, $command, $message, array $env = [])
    {
        [$remoteUser, $remoteServer] = explode('@', $remoteConnection);

        $command = $this->remoter->createCommand($remoteUser, $remoteServer, $command);
        $options = [$this->isDebugLoggingEnabled(), $message];

        return $this->remoter->runWithLoggingOnFailure($command, $env, $options);
    }

    /**
     * @param string $remoteConnection
     * @param string $exec
     * @param string $userCommand
     * @param string $message
     *
     * @return bool
     */
    private function runBuildRemote($remoteConnection, $exec, $userCommand, $message)
    {
        [$remoteUser, $remoteServer] = explode('@', $remoteConnection);

        $actual = [
            $exec,
            $this->dockerEscaped($userCommand)
        ];

        $command = $this->buildRemoter
            ->createCommand($remoteUser, $remoteServer, $actual)
            ->withSanitized($userCommand);

        $options = [true, $message];

        return $this->remoter->runWithLoggingOnFailure($command, [], $options);
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
     * @param string $command
     *
     * @return string
     */
    private function dockerEscaped($command)
    {
        $escaped = escapeshellarg($command);
        return sprintf(self::DOCKER_SHELL, $escaped);
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
