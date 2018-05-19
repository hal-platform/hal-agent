<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Docker;

use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Symfony\ProcessRunner;
use function json_decode;

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
     * @var ProcessRunner
     */
    private $runner;

    /**
     * Manually add hosts entries to the docker container. Provide a list like so:
     *
     * {
     *   "myhostname": "127.0.0.1",
     *   "myhostname2": "192.168.0.1",
     * }
     *
     * @var array
     */
    private $manualDNS;

    /**
     * @var int
     */
    private $internalStepTimeout;
    private $buildStepTimeout;

    /**
     * @param ProcessRunner $runner
     * @param string $manualDNS
     */
    public function __construct(
        ProcessRunner $runner,
        int $internalStepTimeout,
        int $buildStepTimeout,
        string $manualDNS = ''
    ) {
        $this->runner = $runner;
        $this->internalStepTimeout = $internalStepTimeout;
        $this->buildStepTimeout = $buildStepTimeout;

        $this->manualDNS = json_decode($manualDNS) ?? [];
    }

    /**
     * We use bash so the container stays open, while we run other commands
     *
     * @param string $imageName
     * @param string $containerName
     * @param array $env
     *
     * @return bool
     */
    public function createContainer(string $imageName, string $containerName, array $env): bool
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

        if (!$response = $this->runInternal($command, self::STEP_1_CREATE_CONTAINER, $env)) {
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
     * @param string $jobID
     *
     * @param string $containerName
     * @param string $inputFile
     *
     * @return bool
     */
    public function copyIntoContainer(string $jobID, string $containerName, string $inputFile): bool
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

        if (!$this->runInternal($copyInto, self::STEP_2_DOCKER_COPY_IN)) {
            return false;
        }

        return true;
    }

    /**
     * Start docker container
     *
     * @param string $containerName
     *
     * @return bool
     */
    public function startContainer(string $containerName): bool
    {
        $start = [
            $this->docker('start'),
            $containerName
        ];

        if (!$this->runInternal($start, self::STEP_3_START_CONTAINER)) {
            return false;
        }

        return true;
    }

    /**
     * Run command within a running docker container
     *
     * @param string $containerName
     * @param string $command
     * @param string $message
     *
     * @return bool
     */
    public function runCommand(string $containerName, string $command, string $message): bool
    {
        $prefix = [
            $this->docker('exec'),
            sprintf('"%s"', $containerName),
        ];

        if (!$this->runBuild($prefix, $command, $message)) {
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
     * @param string $containerName
     * @param string $outputFile
     *
     * @return bool
     */
    public function copyFromContainer(string $containerName, string $outputFile): bool
    {
        $copyFrom = [
            $this->docker('cp'),
            sprintf('%s:%s/.', $containerName, self::CONTAINER_WORKING_DIR),
            '-',
            '|', 'gzip',
            '>', $outputFile
        ];

        if (!$this->runInternal($copyFrom, self::STEP_5_DOCKER_COPY_OUT)) {
            return false;
        }

        return true;
    }

    /**
     * Kill and remove container
     *
     * @param string $containerName
     *
     * @return bool
     */
    public function cleanupContainer(string $containerName): bool
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
        $this->runInternal($kill, self::STEP_KILL_CONTAINER);
        $this->runInternal($rm, self::STEP_REMOVE_CONTAINER);

        return true;
    }

    /**
     * @param array $command
     * @param string $message
     * @param array $env
     *
     * @return bool
     */
    private function runInternal(array $command, $message, array $env = [])
    {
        $dispCommand = implode(' ', $command;

        $process = $this->runner->prepare($command, null, $this->internalStepTimeout);

        if ($env) {
            $process->setEnv($env);
        }

        if (!$this->runner->run($process, $dispCommand, $message)) {
            return false;
        }

        if ($process->isSuccessful()) {
            $message = $this->isDebugLoggingEnabled() ? $message : null;
            return $this->runner->onSuccess($process, $dispCommand, $message);
        }

        return $this->runner->onFailure($process, $dispCommand, $message);
    }

    /**
     * @param string|array $exec
     * @param string $userCommand
     * @param string $message
     *
     * @return bool
     */
    private function runBuild(array $exec, $userCommand, $message)
    {
        $dispCommand = $userCommand;
        $command = array_merge($exec, [$this->dockerEscaped($userCommand)]);

        $process = $this->runner->prepare($command, null, $this->buildStepTimeout);

        if (!$this->runner->run($process, $dispCommand, $message)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return $this->runner->onSuccess($process, $dispCommand, $message);
        }

        return $this->runner->onFailure($process, $dispCommand, $message);
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
}
