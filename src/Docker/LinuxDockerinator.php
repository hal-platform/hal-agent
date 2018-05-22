<?php
/**
 * @copyright (c) 2018 Steve Kluck
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
    const DOCKER_ENTRYPOINT = 'bash';

    private const STEP_1_CREATE_VOLUME = 'Create Docker volume';
    private const STEP_1_CREATE_CONTAINER = 'Create Docker container';
    private const STEP_2_DOCKER_COPY_IN = 'Copy source code into container';
    private const STEP_3_START_CONTAINER = 'Start Docker container';
    //  STEP_4 = run build steps
    private const STEP_5_DOCKER_COPY_OUT = 'Copy artifacts from container';

    private const STEP_REMOVE_CONTAINER = 'Remove Docker container';
    private const STEP_REMOVE_VOLUME = 'Remove Docker volume';

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
     * @param string $volumeName
     *
     * @return bool
     */
    public function createVolume(string $volumeName): bool
    {
        $create = [
            $this->docker('volume', 'create'),
            $volumeName
        ];

        if (!$this->runInternal($create, self::STEP_1_CREATE_VOLUME)) {
            return false;
        }

        return true;
    }

    /**
     * We use bash so the container stays open, while we run other commands
     *
     * @param string $imageName
     * @param string $containerName
     * @param string $volumeName
     * @param array $env
     * @param string $userCommand
     *
     * @return bool
     */
    public function createContainer(
        string $imageName,
        string $containerName,
        string $volumeName,
        array $env = [],
        ?string $userCommand = ''
    ): bool {
        $workingDir = self::CONTAINER_WORKING_DIR;

        $command = [
            $this->docker('container', 'create'),
            '--tty',
            '--interactive',
            ['--name', $containerName],
            ['--volume', "${volumeName}:${workingDir}"],
            ['--workdir', $workingDir],
        ];

        foreach ($this->manualDNS as $name => $ip) {
            $command[] = ['--add-host', "${name}:${ip}"];
        }

        // @todo replace this with envfile in docker container like windows system

        # Docker env-file doesn't support newlines
        // $command[] = sprintf('--env-file %s', $filename);
        foreach ($env as $name => $var) {
            $command[] = ['--env', $name];
        }

        $command[] = ['--entrypoint', self::DOCKER_ENTRYPOINT];
        $command[] = $imageName;

        if ($userCommand) {
            $command[] = ['-l', '-c', $userCommand];
        }

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
     * @param string $containerName
     * @param string $inputFile
     *
     * @return bool
     */
    public function copyIntoContainer(string $containerName, string $inputFile): bool
    {
        $copyInto = [
            ['cat', $inputFile],
            '|',
            $this->docker('container', 'cp'),
            '-',
            sprintf('%s:%s', $containerName, self::CONTAINER_WORKING_DIR)
        ];

        // We need to pass the pipes to bash raw, so we need to bypass symfony escaping (by passing string instead of array)
        $copyInto = implode(' ', $this->flattenCommand($copyInto));

        if (!$this->runInternalUnsafe($copyInto, self::STEP_2_DOCKER_COPY_IN)) {
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
    public function startUserContainer(string $containerName, string $command, string $message): bool
    {
        $start = [
            $this->docker('container', 'start'),
            '--attach',
            $containerName,
        ];

        if (!$this->runBuild($start, $command, $message)) {
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
            $this->docker('container', 'cp'),
            sprintf('%s:%s/.', $containerName, self::CONTAINER_WORKING_DIR),
            '-',
            '|', 'gzip',
            '>', $outputFile
        ];

        // We need to pass the pipes to bash raw, so we need to bypass symfony escaping (by passing string instead of array)
        $copyFrom = implode(' ', $this->flattenCommand($copyFrom));

        if (!$this->runInternalUnsafe($copyFrom, self::STEP_5_DOCKER_COPY_OUT)) {
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
        $rm = [
            $this->docker('container', 'rm'),
            '--force',
            $containerName,
        ];

        // Do not care whether these fail
        $this->runInternal($rm, self::STEP_REMOVE_CONTAINER);

        return true;
    }

    /**
     * Remove volume
     *
     * @param string $cleanupVolume
     *
     * @return bool
     */
    public function cleanupVolume(string $cleanupVolume): bool
    {
        $rm = [
            $this->docker('volume', 'rm'),
            $cleanupVolume,
        ];

        // Do not care whether these fail
        $this->runInternal($rm, self::STEP_REMOVE_VOLUME);

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
        $command = $this->flattenCommand($command);

        $dispCommand = implode(' ', $command);

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
     * @param string $command
     * @param string $message
     * @param array $env
     *
     * @return bool
     */
    private function runInternalUnsafe(string $command, $message, array $env = [])
    {
        $dispCommand = $command;

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
     * @param array $command
     * @param string $userCommand
     * @param string $message
     *
     * @return bool
     */
    private function runBuild(array $command, $userCommand, $message)
    {
        $command = $this->flattenCommand($command);

        $process = $this->runner->prepare($command, null, $this->buildStepTimeout);

        if (!$this->runner->run($process, $userCommand, $message)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return $this->runner->onSuccess($process, $userCommand, $message);
        }

        return $this->runner->onFailure($process, $userCommand, $message);
    }

    /**
     * Returns a docker command
     *
     * @param string $system
     * @param string $command
     *
     * @return array
     */
    private function docker($system, $command)
    {
        return [
            'docker',
            $system,
            $command
        ];
    }

    /**
     * Flatten a command args (remove nested arrays)
     *
     * @param array $command
     *
     * @return array
     */
    private function flattenCommand(array $command)
    {
        $final = [];

        foreach ($command as $arg) {
            if (is_array($arg)) {
                $final = array_merge($final, $arg);
            } else {
                $final[] = $arg;
            }
        }

        return $final;
    }
}
