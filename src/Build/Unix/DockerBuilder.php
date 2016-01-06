<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Build\EmergencyBuildHandlerTrait;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\CommandContext;
use QL\Hal\Agent\Remoting\SSHProcess;
use QL\Hal\Agent\Symfony\OutputAwareInterface;

class DockerBuilder implements BuilderInterface, OutputAwareInterface
{
    // Comes with OutputAwareTrait
    use EmergencyBuildHandlerTrait;

    /**
     * @type string
     */
    const SECTION = 'Docker';
    const EVENT_MESSAGE = 'Run build command';
    const EVENT_MESSAGE_CUSTOM = 'Run build command "%s"';
    const CONTAINER_WORKING_DIR = '/build';
    const DOCKER_SHELL = <<<SHELL
bash -l -c %s
SHELL;

    const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,25}$/';

    const EVENT_VALIDATE_DOCKERSOURCE = 'Validate Docker image source';
    const EVENT_DOCKER_RUNNING = 'Check Docker daemon status';
    const EVENT_BUILD_CONTAINER = 'Build Docker image "%s"';
    const EVENT_SCRATCH_OWNER = 'Record build owner metadata';
    const EVENT_SCRATCH_GROUP = 'Record build group metadata';
    const EVENT_START_CONTAINER = 'Start Docker container';
    const EVENT_CLEAN_PERMISSIONS = 'Clean build permissions';
    const EVENT_KILL_CONTAINER = 'Kill Docker container';
    const EVENT_REMOVE_CONTAINER = 'Remove Docker container';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type SSHProcess
     */
    private $remoter;
    private $buildRemoter;

    /**
     * @type string
     */
    private $dockerSourcesPath;

    /**
     * @type string|null
     */
    private $remoteUser;
    private $remoteServer;

    /**
     * @type bool
     */
    private $logDockerCommands;
    private $useSudoForDocker;

    /**
     * @param EventLogger $logger
     * @param SSHProcess $remoter
     * @param SSHProcess $buildRemoter
     * @param string $dockerSourcesPath
     */
    public function __construct(
        EventLogger $logger,
        SSHProcess $remoter,
        SSHProcess $buildRemoter,
        $dockerSourcesPath
    ) {
        $this->logger = $logger;
        $this->remoter = $remoter;
        $this->buildRemoter = $buildRemoter;
        $this->dockerSourcesPath = $dockerSourcesPath;

        $this->logDockerCommands = false;
        $this->useSudoForDocker = false;
    }

    /**
     * @return void
     */
    public function enableDockerCommandLogging()
    {
        $this->logDockerCommands = true;
    }

    /**
     * @return void
     */
    public function enableDockerSudo()
    {
        $this->useSudoForDocker = true;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke($imageName, $remoteUser, $remoteServer, $remotePath, array $commands, array $env)
    {
        $fqImageName = sprintf('hal9000/%s', $imageName);
        $imagesBasePath = $this->dockerSourcesPath;

        $this->remoteUser = $remoteUser;
        $this->remoteServer = $remoteServer;

        // 1. Ensure docker source exists
        if (!$this->sanityCheck($imagesBasePath, $imageName)) {
            return $this->bombout(false);
        }

        // 2. Build docker image
        if (!$this->buildImage($imageName, $fqImageName, $imagesBasePath)) {
            return $this->bombout(false);
        }

        // 3. Get owner of build dir
        if (!$dockerMeta = $this->getOwner($remotePath)) {
            return $this->bombout(false);
        }

        $owner = $dockerMeta['owner'];
        $group = $dockerMeta['group'];

        // 4. Start up docker container
        if (!$containerName = $this->startContainer($remotePath, $fqImageName, $env)) {
            return $this->bombout(false);
        }

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler([$this, 'cleanupContainer'], 'Clean up and shutdown Docker container', $containerName, $owner, $group);

        // 5. Run commands
        if (!$this->runCommands($containerName, $commands)) {
            return $this->bombout(false);
        }

        return $this->bombout(true);
    }

    /**
     * @param string $imagesBasePath
     * @param string $imageName
     *
     * @return bool
     */
    private function sanityCheck($imagesBasePath, $imageName)
    {
        $this->status(sprintf('Validating specified Docker image "%s"', $imageName), self::SECTION);

        $imagePath = sprintf('"%s/%s"', rtrim($imagesBasePath, '/'), $imageName);
        $dockerFilePath = sprintf('"%s/%s/Dockerfile"', rtrim($imagesBasePath, '/'), $imageName);

        $validateDockerSourceCommand = [
            'test -d',
            $imagePath,
            '&&',

            'test -f',
            $dockerFilePath
        ];

        $dockerRunningCommand = [
            $this->docker('info')
        ];

        if (!$isDockerImageValid = $this->runRemote($validateDockerSourceCommand, self::EVENT_VALIDATE_DOCKERSOURCE)) {
            return false;
        }

        if (!$isDockerRunning = $this->runRemote($dockerRunningCommand, self::EVENT_DOCKER_RUNNING)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $imageName
     * @param string $fqImageName
     * @param string $imagesBasePath
     *
     * @return bool
     */
    private function buildImage($imageName, $fqImageName, $imagesBasePath)
    {
        $this->status(sprintf('Building container "%s"', $fqImageName), self::SECTION);

        $build = [
            $this->docker('build'),
            sprintf('--tag="%s"', $fqImageName),
            sprintf('"%s/%s"', rtrim($imagesBasePath, '/'), $imageName)
        ];

        return $this->runBuildRemote($build, sprintf(self::EVENT_BUILD_CONTAINER, $imageName));
    }

    /**
     * Retrieve owner and group of the workspace
     *
     * We need to chown any files generated from within the container after the build runs
     *
     * @param string $remotePath
     *
     * @return string|null
     */
    private function getOwner($remotePath)
    {
        $this->status('Grabbing Docker metadata', self::SECTION);

        $getOwnerNumber = [
            'ls -ldn',
            $remotePath,
            '| awk \'{print $3}\''
        ];

        $getGroupNumber = [
            'ls -ldn',
            $remotePath,
            '| awk \'{print $4}\''
        ];

        if (!$response = $this->runRemote($getOwnerNumber, self::EVENT_SCRATCH_OWNER)) {
            return null;
        }

        $owner = trim($this->remoter->getLastOutput());

        if (!$response = $this->runRemote($getGroupNumber, self::EVENT_SCRATCH_GROUP)) {
            return null;
        }

        $group = trim($this->remoter->getLastOutput());

        return [
            'owner' => $owner,
            'group' => $group
        ];
    }

    /**
     * Create and start container
     *
     * We use bash so the container stays open, while we run other commands
     *
     * @param string $remotePath
     * @param string $imageName
     * @param array $env
     *
     * @return string|null
     */
    private function startContainer($remotePath, $imageName, array $env)
    {
        $this->status('Starting Docker container', self::SECTION);

        $command = [
            $this->docker('run'),
            '--detach=true',
            '--tty=true',
            '--interactive=true',
            sprintf('--volume="%s:%s"', $remotePath, self::CONTAINER_WORKING_DIR),
            sprintf('--workdir="%s"', self::CONTAINER_WORKING_DIR)
        ];

        foreach ($env as $name => $var) {
            $command[] = sprintf('--env %s', $name);
        }

        $command[] = $imageName;
        $command[] = 'bash -l';

        if (!$response = $this->runRemote($command, self::EVENT_START_CONTAINER, $env)) {
            return null;
        }

        $containerName = trim($this->remoter->getLastOutput());

        $this->status(sprintf('Docker container "%s" started.', $containerName), self::SECTION);

        return $containerName;
    }

    /**
     * @param string $containerName
     * @param array $commands
     *
     * @return boolean
     */
    private function runCommands($containerName, array $commands)
    {
        $prefix = [
            $this->docker('exec'),
            sprintf('"%s"', $containerName)
        ];
        $prefix = implode(' ', $prefix);

        foreach ($commands as $command) {
            $actual = $this->dockerEscaped($command);

            $this->status('Running user build command inside Docker container', self::SECTION);

            $context = $this->buildRemoter
                ->createCommand($this->remoteUser, $this->remoteServer, [$prefix, $actual])
                ->withSanitized($command);

            // Add build command to log message if short enough
            $msg = self::EVENT_MESSAGE;
            if (1 === preg_match(self::SHORT_COMMAND_VALIDATION, $command)) {
                $msg = sprintf(self::EVENT_MESSAGE_CUSTOM, $command);
            }

            if (!$response = $this->runBuildRemote($context, $msg)) {
                return false;
            }
        }

        // all good
        return true;
    }

    /**
     * 1. Clean up permissions on files generated within container
     * 2. Kill and remove container
     *
     * @param string $containerName
     * @param string $owner
     * @param string $group
     *
     * @return void
     */
    private function cleanupContainer($containerName, $owner, $group)
    {
        $chown = sprintf('chown -R %s:%s "%s"', $owner, $group, self::CONTAINER_WORKING_DIR);
        $chown = [
            $this->docker('exec'),
            sprintf('"%s"', $containerName),
            $this->dockerEscaped($chown)
        ];

        $kill = [
            $this->docker('kill'),
            sprintf('"%s"', $containerName),
        ];

        $rm = [
            $this->docker('rm'),
            sprintf('"%s"', $containerName),
        ];

        // Do not care whether these fail
        $this->runRemote($chown, self::EVENT_CLEAN_PERMISSIONS);
        $this->runRemote($kill, self::EVENT_KILL_CONTAINER);
        $this->runRemote($rm, self::EVENT_REMOVE_CONTAINER);
    }

    /**
     * @param string|string[] $command
     * @param string $customMessage
     * @param string[] $env
     *
     * @return bool
     */
    private function runRemote($command, $customMessage = '', $env = [])
    {
        $command = $this->remoter->createCommand($this->remoteUser, $this->remoteServer, $command);
        return $this->remoter->runWithLoggingOnFailure($command, $env, [$this->logDockerCommands, $customMessage]);
    }

    /**
     * @param string|string[] $command
     * @param string $customMessage
     * @param string[] $env
     *
     * @return bool
     */
    private function runBuildRemote($command, $customMessage = '')
    {
        if (!$command instanceof CommandContext) {
            $command = $this->buildRemoter
                ->createCommand($this->remoteUser, $this->remoteServer, $command);
        }

        return $this->buildRemoter->run($command, [], [true, $customMessage]);
    }

    /**
     * @param string $command
     *
     * @return string
     */
    private function docker($command)
    {
        if ($this->useSudoForDocker) {
            return 'sudo -E docker ' . $command;
        }

        return 'docker ' . $command;
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
     * OVERRIDE for EmergencyBuilderHandler for docker functionality.
     *
     * @param callable $cleaner
     * @param string $message
     *
     * @param string $containerName
     * @param string $owner
     * @param string $group
     *
     * @return null
     */
    private function enableEmergencyHandler(callable $cleaner, $message, $containerName, $owner, $group)
    {
        $this->cleanup(function() use ($cleaner, $containerName, $owner, $group) {
            $cleaner($containerName, $owner, $group);
        }, $message);

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }
}
