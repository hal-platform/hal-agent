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
     * @var string
     */
    const SECTION = 'Docker';
    const SECTION_BUILD = 'Docker - Build';

    const EVENT_MESSAGE = 'Run build command';
    const EVENT_MESSAGE_CUSTOM = 'Run build command "%s"';
    const CONTAINER_WORKING_DIR = '/build';

    /**
     * Valid docker pattern:
     *
     * Each component must follow this regex:
     * ([a-zA-Z0-9]{1}[a-zA-Z0-9_.-]{1,29})
     *
     * @see https://github.com/docker/docker/blob/b46f044bf71309088b30c1172d4c69287c6a99df/utils/names.go#L6
     *
     * docker:QL_DOCKERFILE
     * docker:imagename
     * docker:imagename:tag
     * docker:owner/imagename:tag
     */
    const DOCKER_PREFIX = 'docker:';

    const DOCKER_IMAGE_REGEX = '([a-zA-Z0-9]{1}[a-zA-Z0-9_.-]{1,29})';
    const DOCKER_SHELL = <<<SHELL
bash -l -c %s
SHELL;

    const SHORT_COMMAND_VALIDATION = '/^[\S\h]{1,40}$/';

    const EVENT_VALIDATE_IMAGE_BUILT = 'Validate Docker image is built';
    const EVENT_VALIDATE_DOCKERSOURCE = 'Validate Docker image source for "%s"';
    const EVENT_DOCKER_RUNNING = 'Check Docker daemon status';
    const EVENT_BUILD_INFO = 'Using Docker image "%s"';
    const EVENT_BUILD_CONTAINER = 'Build Docker image "%s"';
    const EVENT_START_CONTAINER = 'Start Docker container';
    const EVENT_DOCKER_COPY_IN = 'Copy source into container';
    const EVENT_DOCKER_GET_USER = 'Record docker container user';
    const EVENT_DOCKER_FIX_PERMISSIONS = 'Fix permissions of source files';
    const EVENT_DOCKER_COPY_OUT = 'Copy build from container';
    const EVENT_KILL_CONTAINER = 'Kill Docker container';
    const EVENT_REMOVE_CONTAINER = 'Remove Docker container';
    const EVENT_DOCKER_CLEANUP = 'Clean up and shutdown Docker container';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSHProcess
     */
    private $remoter;
    private $buildRemoter;
    private $transferRemoter;

    /**
     * @var string
     */
    private $dockerSourcesPath;

    /**
     * @var string|null
     */
    private $remoteUser;
    private $remoteServer;

    /**
     * @var bool
     */
    private $logDockerCommands;
    private $useSudoForDocker;

    private static $dockerPatternRegex;

    /**
     * @param EventLogger $logger
     * @param SSHProcess $remoter
     * @param SSHProcess $buildRemoter
     * @param SSHProcess $transferRemoter
     * @param string $dockerSourcesPath
     */
    public function __construct(
        EventLogger $logger,
        SSHProcess $remoter,
        SSHProcess $buildRemoter,
        SSHProcess $transferRemoter,
        $dockerSourcesPath
    ) {
        $this->logger = $logger;
        $this->remoter = $remoter;
        $this->buildRemoter = $buildRemoter;
        $this->transferRemoter = $transferRemoter;

        $this->dockerSourcesPath = $dockerSourcesPath;

        $this->logDockerCommands = false;
        $this->useSudoForDocker = false;

        self::$dockerPatternRegex = sprintf(
            '/^%1$s%2$s(\/%2$s)?(\:%2$s)? /',
            self::DOCKER_PREFIX,
            self::DOCKER_IMAGE_REGEX
        );
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
    public function __invoke($defaultImageName, $remoteUser, $remoteServer, $remoteFile, array $commands, array $env)
    {
        // Store as class properties so cleanup operation can use them
        $this->remoteUser = $remoteUser;
        $this->remoteServer = $remoteServer;

        $imagedCommands = $this->organizeCommands($defaultImageName, $commands);

        foreach ($imagedCommands as $entry) {
            list($image, $commands) = $entry;

            // 1. Build container
            if (!$containerName = $this->buildContainer($image, $env)) {
                return $this->bombout(false);
            }

            // 2. Enable cleanup failsafe
            $cleanup = $this->enableDockerCleanup($containerName);

            // 3. Copy into container
            if (!$this->copyIntoContainer($containerName, $remoteFile)) {
                return $this->bombout(false);
            }

            // 4. Run commands
            foreach ($commands as $command) {
                if (!$this->runCommand($containerName, $command)) {
                    return $this->bombout(false);
                }
            }

            // 5. Copy out of container
            if (!$this->copyFromContainer($containerName, $remoteFile)) {
                return $this->bombout(false);
            }

            // 6. Run and clear docker cleanup/shutdown functionality
            $this->runDockerCleanup($cleanup);
        }

        return $this->bombout(true);
    }

    /**
     * Organize a list of commands into an array such as
     * [
     *     [ $image1, [$command1, $command2] ]
     *     [ $image2, [$command3] ]
     *     [ $image1, [$command4] ]
     * ]
     *
     * @param string $defaultImageName
     * @param array $commands
     *
     * @return array
     */
    private function organizeCommands($defaultImageName, array $commands)
    {
        $organized = [];
        $prevImage = null;
        foreach ($commands as $command) {
            list($image, $command) = $this->parseCommand($defaultImageName, $command);

            // Using same image in a row, rebuild the entire entry with the added command
            if ($image === $prevImage) {
                list($i, $cmds) = array_pop($organized);
                $cmds[] = $command;

                $entry = [$image, $cmds];

            } else {
                $entry = [$image, [$command]];
            }

            $organized[] = $entry;

            $prevImage = $image;
        }

        return $organized;
    }

    /**
     * @param string $imageName
     * @param array $env
     *
     * @return array|null Returns either null, or the container name and cleanup closure on success
     */
    private function buildContainer($imageName, array $env)
    {
        $imagesBasePath = $this->dockerSourcesPath;
        $fqImageName = sprintf('hal9000/%s', $imageName);

        // 1. Ensure docker source exists
        if (!$this->sanityCheck($imagesBasePath, $imageName)) {
            return null;
        }

        // 2. Build docker image
        if (!$this->buildImage($imageName, $fqImageName, $imagesBasePath)) {
            return null;
        }

        // 3. Start up docker container
        if (!$containerName = $this->startContainer($fqImageName, $env)) {
            return null;
        }

        return $containerName;
    }

    /**
     * Example usage in a shell:
     * > cat output.tar | docker cp - $containerName:/build
     *
     * Copy the contents of a tar (NOT in a subdirectory) into a directory in the container
     *
     * Note: When copying files into containers, permissions are root:root
     * so another exec is required to fix permissions.
     *
     * Also note: Docker can understand both raw tars, and gzip'd tars for copying
     * files into containers. However, it only exports to tar, NOT gzip'd tar.
     *
     * @param string $containerName
     * @param string $archiveFile
     *
     * @return bool
     */
    private function copyIntoContainer($containerName, $archiveFile)
    {
        $getUser = [
            $this->docker('inspect'),
            '--format="{{ .Config.User }}"',
            $containerName
        ];

        $copyInto = [
            sprintf('cat %s', $archiveFile),
            '|',
            $this->docker('cp'),
            '-',
            sprintf('%s:%s', $containerName, self::CONTAINER_WORKING_DIR)
        ];

        // Get user container is being run as.
        if (!$this->runRemote($getUser, self::EVENT_DOCKER_GET_USER)) {
            return false;
        }

        $owner = trim($this->remoter->getLastOutput());
        if (!$owner) $owner = 'root';

        // Copy in files
        if (!$this->runTransferRemote($copyInto, self::EVENT_DOCKER_COPY_IN)) {
            return false;
        }

        // Do not need to chown if container user is root
        if ($owner === 'root') {
            return true;
        }

        // Fix permissions copied over (will be owned by root:root)
        $fixPermissions = [
            $this->docker('exec'),
            sprintf('--user %s', 'root'),
            sprintf('"%s"', $containerName),
            sprintf('chown -R %s:%s %s', $owner, $owner, self::CONTAINER_WORKING_DIR)
        ];

        if (!$this->runTransferRemote($fixPermissions, self::EVENT_DOCKER_FIX_PERMISSIONS)) {
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
     * @param string $archiveFile
     *
     * @return bool
     */
    private function copyFromContainer($containerName, $archiveFile)
    {
        $copyFrom = [
            $this->docker('cp'),
            sprintf('%s:%s/.', $containerName, self::CONTAINER_WORKING_DIR),
            '-',
            '|', 'gzip',
            '>', $archiveFile
        ];

        if (!$this->runTransferRemote($copyFrom, self::EVENT_DOCKER_COPY_OUT)) {
            return false;
        }

        return true;
    }

    /**
     * This should return the docker image to use (WITHOUT "docker:" prefix), and command without docker instructions.
     *
     * @param string $defaultImage
     * @param string $command
     *
     * @return array [$imageName, $command]
     */
    private function parseCommand($defaultImage, $command)
    {
        if (preg_match(self::$dockerPatternRegex, $command, $matches)) {

            $image = array_shift($matches);

            // Remove docker prefix from command
            $command = substr($command, strlen($image));

            // return docker image as just the "docker/*" part
            $image = substr($image, strlen(self::DOCKER_PREFIX));

            return [trim($image), trim($command)];
        }

        return [$defaultImage, $command];
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

        if (!$isDockerImageValid = $this->runRemote($validateDockerSourceCommand, sprintf(self::EVENT_VALIDATE_DOCKERSOURCE, $imageName))) {
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

        $imageExists = [
            $this->docker('inspect'),
            '--format="{{ .Id }}"',
            $fqImageName
        ];

        $imageInfo = [
            $this->docker('history'),
            '--no-trunc',
            $fqImageName
        ];

        // Check if container exists, dont build if it does
        $isDockerImageBuilt = $this->runRemote($imageExists, sprintf(self::EVENT_VALIDATE_IMAGE_BUILT, $imageName));
        if ($isDockerImageBuilt) {
            return $this->runBuildRemote($imageInfo, sprintf(self::EVENT_BUILD_INFO, $imageName));
        }

        $build = [
            $this->docker('build'),
            sprintf('--tag="%s"', $fqImageName),
            sprintf('"%s/%s"', rtrim($imagesBasePath, '/'), $imageName)
        ];

        return $this->runBuildRemote($build, sprintf(self::EVENT_BUILD_CONTAINER, $imageName));
    }

    /**
     * Create and start container
     *
     * We use bash so the container stays open, while we run other commands
     *
     * @param string $imageName
     * @param array $env
     *
     * @return string|null
     */
    private function startContainer($imageName, array $env)
    {
        $this->status('Starting Docker container', self::SECTION);

        $command = [
            $this->docker('run'),
            '--detach=true',
            '--tty=true',
            '--interactive=true',
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

        $this->status(sprintf('Docker container "%s" started', $containerName), self::SECTION);

        return $containerName;
    }

    /**
     * @param string $containerName
     * @param array $command
     *
     * @return boolean
     */
    private function runCommand($containerName, $command)
    {
        $prefix = [
            $this->docker('exec'),
            sprintf('"%s"', $containerName)
        ];
        $prefix = implode(' ', $prefix);

        $actual = $this->dockerEscaped($command);

        $this->status(sprintf('Running build command [ %s ] in Docker container', $command), static::SECTION_BUILD);

        $context = $this->buildRemoter
            ->createCommand($this->remoteUser, $this->remoteServer, [$prefix, $actual])
            ->withSanitized($command);

        // Add build command to log message if short enough
        $msg = static::EVENT_MESSAGE;
        if (1 === preg_match(self::SHORT_COMMAND_VALIDATION, $command)) {
            $msg = sprintf(static::EVENT_MESSAGE_CUSTOM, $command);
        }

        if (!$response = $this->runBuildRemote($context, $msg)) {
            return false;
        }

        // all good
        return true;
    }

    /**
     * Kill and remove container
     *
     * @param string $containerName
     *
     * @return void
     */
    private function cleanupContainer($containerName)
    {
        $this->status(sprintf('Cleaning up container "%s"', $containerName), 'Docker');

        $kill = [
            $this->docker('kill'),
            sprintf('"%s"', $containerName),
        ];

        $rm = [
            $this->docker('rm'),
            sprintf('"%s"', $containerName),
        ];

        // Do not care whether these fail
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
     * Run commands through the remoter dedicated to "build commands". This remoter uses a longer timeout
     * so it can wait for long running commands to finish.
     *
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
     * @param string|string[] $command
     * @param string $customMessage
     *
     * @return bool
     */
    private function runTransferRemote($command, $customMessage = '')
    {
        $command = $this->remoter->createCommand($this->remoteUser, $this->remoteServer, $command);
        return $this->remoter->runWithLoggingOnFailure($command, [], [$this->logDockerCommands, $customMessage]);
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
     * @param string $containerName
     *
     * @return callable
     */
    private function enableDockerCleanup($containerName)
    {
        $cleanup = function() use ($containerName) {
            $this->cleanupContainer($containerName);
        };

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($cleanup, self::EVENT_DOCKER_CLEANUP);

        return $cleanup;
    }

    /**
     * @param string $containerName
     *
     * @return callable
     */
    private function runDockerCleanup(callable $cleanup)
    {
        $cleanup();
        $this->cleanup(null);
    }

    /**
     * OVERRIDE for EmergencyBuilderHandler for docker functionality.
     *
     * @param callable $cleaner
     * @param string $message
     *
     * @return null
     */
    private function enableEmergencyHandler(callable $cleaner, $message)
    {
        $this->cleanup($cleaner, $message);

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }
}
