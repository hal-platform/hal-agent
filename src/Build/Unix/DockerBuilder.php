<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Build\EmergencyBuildHandlerTrait;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\SSHProcess;
use QL\Hal\Agent\Symfony\OutputAwareInterface;

/**
 * SYSTEM PREP:
 *
 * 1. Make sure dockerfile source is present:
 * - sudo mkdir /docker-images && sudo chown -R hal9000test:hal-agent /docker-images
 * - sudo su hal9000test && cd /docker-images
 * - curl -v -L http://git/api/v3/repos/skluck/docker-images/tarball/master --output master.tar.gz
 * - tar -xzf master.tar.gz && rm master.tar.gz
 * - cd $(ls -d *\/ | grep SKluck) && mv {,.[!.],..?}* ..
 * - cd .. && rm -r $(ls -d *\/ | grep SKluck)
 *
 * 2. Make sure scratch is present:
 * - sudo su hal9000test
 * - mkdir /tmp/hal9000
 *
 * QUICK COMMANDS
 * - Remove untagged docker images:
 * docker rmi $(docker images | grep "^<none>" | awk '{print $3}')
 *
 * - Nuke all containers:
 * docker ps -aq | xargs docker rm -f
 */
class DockerBuilder implements BuilderInterface, OutputAwareInterface
{
    use EmergencyBuildHandlerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run build command';
    const CONTAINER_WORKING_DIR = '/build';
    const DOCKER_SHELL = <<<SHELL
sh -c '%s'
SHELL;

    const EVENT_VALIDATE_DOCKERSOURCE = 'Validate Docker image source';
    const EVENT_BUILD_CONTAINER = 'Build Docker image';
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
    public function __invoke($system, $remoteUser, $remoteServer, $remotePath, array $commands, array $env)
    {
        $imageName = $system;
        $fqImageName = sprintf('hal9000/%s', $imageName);
        $imagesBasePath = $this->dockerSourcesPath;

        $this->remoteUser = $remoteUser;
        $this->remoteServer = $remoteServer;

        // 1. Ensure docker source exists
        if (!$this->sanityCheck($imagesBasePath, $imageName)) {
            return $this->bombout(false);
        }

        // 2. Build docker image
        if (!$this->prepareImage($imageName, $fqImageName, $imagesBasePath)) {
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
        $this->enableEmergencyHandler($containerName, $owner, $group);

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
        $this->status(sprintf('Validating specified Docker image "%s"', $imageName));

        $checkFile = [
            'test -d',
            sprintf('"%s/%s"', rtrim($imagesBasePath, '/'), $imageName)
        ];

        if (!$response = $this->runRemote($checkFile, self::EVENT_VALIDATE_DOCKERSOURCE)) {
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
    private function prepareImage($imageName, $fqImageName, $imagesBasePath)
    {
        $this->status(sprintf('Building container "%s"', $fqImageName));

        $build = [
            'docker build',
            sprintf('--tag="%s"', $fqImageName),
            sprintf('"%s/%s"', rtrim($imagesBasePath, '/'), $imageName)
        ];

        if (!$response = $this->runBuildRemote($build, self::EVENT_BUILD_CONTAINER)) {
            return false;
        }

        return true;
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
        $this->status('Grabbing Docker metadata');

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
        $this->status('Starting Docker container');

        $command = [
            $this->useSudoForDocker ? 'sudo docker run' : 'docker run',
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

        $this->status(sprintf('Docker container "%s" started.', $containerName));

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
        $remoter = $this->buildRemoter;

        $prefix = [
            $this->useSudoForDocker ? 'sudo docker exec' : 'docker exec',
            sprintf('"%s"', $containerName)
        ];
        $prefix = implode(' ', $prefix);

        foreach ($commands as $command) {
            $actual = sprintf(self::DOCKER_SHELL, $command);

            $this->status('Running user build command inside Docker container');

            if (!$response = $remoter($this->remoteUser, $this->remoteServer, $actual, [], true, $prefix, self::EVENT_MESSAGE)) {
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
        $this->status('Clean up and shutdown Docker container');

        $chown = sprintf('chown -R %s:%s "%s"', $owner, $group, self::CONTAINER_WORKING_DIR);
        $chown = [
            $this->useSudoForDocker ? 'sudo docker exec' : 'docker exec',
            sprintf('"%s"', $containerName),
            sprintf(self::DOCKER_SHELL, $chown)
        ];

        $kill = [
            $this->useSudoForDocker ? 'sudo docker kill' : 'docker kill',
            sprintf('"%s"', $containerName),
        ];

        $rm = [
            $this->useSudoForDocker ? 'sudo docker rm' : 'docker rm',
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
        if (is_array($command)) {
            $command = implode(' ', $command);
        }

        $remoter = $this->remoter;
        return $remoter(
            $this->remoteUser,
            $this->remoteServer,
            $command,
            $env,
            $this->logDockerCommands,
            null,
            $customMessage
        );
    }

    /**
     * @param string|string[] $command
     * @param string $customMessage
     * @param string[] $env
     *
     * @return bool
     */
    private function runBuildRemote($command, $customMessage = '', $env = [])
    {
        if (is_array($command)) {
            $command = implode(' ', $command);
        }

        $remoter = $this->buildRemoter;
        return $remoter(
            $this->remoteUser,
            $this->remoteServer,
            $command,
            $env,
            true,
            null,
            $customMessage
        );
    }

    /**
     * OVERRIDE for EmergencyBuilderHandler for docker functionality.
     *
     * @param bool $exitCode
     *
     * @return bool
     */
    private function bombout($status)
    {
        $this->clean();

        return $status;
    }

    /**
     * OVERRIDE for EmergencyBuilderHandler for docker functionality.
     *
     * @param string $containerName
     * @param string $owner
     * @param string $group
     *
     * @return null
     */
    private function enableEmergencyHandler($containerName, $owner, $group)
    {
        $this->cleanup(function() use ($containerName, $owner, $group) {
            $this->cleanupContainer($containerName, $owner, $group);
        });

        // Set emergency handler in case of super fatal
        if ($this->enableShutdownHandler) {
            register_shutdown_function([$this, 'cleanup']);
        }
    }
}
