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
    const EVENT_SCRATCH_OWNER = 'Record build owner metadata';
    const EVENT_SCRATCH_GROUP = 'Record build group metadata';
    const EVENT_START_CONTAINER = 'Start Docker container';
    const EVENT_CLEAN_PERMISSIONS = 'Clean build permissions';
    const EVENT_KILL_CONTAINER = 'Kill Docker container';
    const EVENT_REMOVE_CONTAINER = 'Remove Docker container';

    const ERR_CONTAINER_NOT_RUNNING = 'Docker container is not running.';

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
    public function __invoke($defaultImageName, $remoteUser, $remoteServer, $remotePath, array $commands, array $env)
    {
        // Store as class properties so cleanup operation can use them
        $this->remoteUser = $remoteUser;
        $this->remoteServer = $remoteServer;

        $currentImage = '';
        $containerName = '';
        $cleanup = null;

        foreach ($commands as $command) {
            list($image, $command) = $this->parseCommand($defaultImageName, $command);

            // Image has changed, run cleanup for previous container (if set), and build new container
            if ($image !== $currentImage) {
                $currentImage = $image;

                if (is_callable($cleanup)) {
                    $cleanup();
                    // If we were able to cleanup the previous container, remove it from emergency handler
                    $this->cleanup(null, '');
                }

                if (!$container = $this->buildContainer($currentImage, $remotePath, $env)) {
                    return $this->bombout(false);
                }

                list($containerName, $cleanup) = $container;
            }

            // well thats weird, something seriously bad happened
            if (!$containerName) {
                $this->logger->event('failure', self::ERR_CONTAINER_NOT_RUNNING);
                return $this->bombout(false);
            }

            // 5. Run command
            if (!$this->runCommand($containerName, $command)) {
                return $this->bombout(false);
            }
        }

        // Bombout will automatically run the last cleanup
        return $this->bombout(true);
    }

    /**
     * @param string $imageName
     * @param string $remotePath
     * @param array $env
     *
     * @return array|null Returns either null, or the container name and cleanup closure on success
     */
    private function buildContainer($imageName, $remotePath, array $env)
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

        // 3. Get owner of build dir
        if (!$dockerMeta = $this->getOwner($remotePath)) {
            return null;
        }

        $owner = $dockerMeta['owner'];
        $group = $dockerMeta['group'];

        // 4. Start up docker container
        if (!$containerName = $this->startContainer($remotePath, $fqImageName, $env)) {
            return null;
        }

        $cleanup = function() use ($containerName, $owner, $group) {
            $this->cleanupContainer($containerName, $owner, $group);
        };

        // Set emergency handler in case of super fatal
        $this->enableEmergencyHandler($cleanup, 'Clean up and shutdown Docker container');

        // This sucks more than a little
        return [$containerName, $cleanup];
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
        if ($isDockerImageBuilt = $this->runRemote($imageExists, sprintf(self::EVENT_VALIDATE_IMAGE_BUILT, $imageName))) {
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

        $this->status(sprintf('Running build command [ %s ] in Docker container', $command), self::SECTION_BUILD);

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
        $this->status(sprintf('Cleaning up container "%s"', $containerName), 'Docker');

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
