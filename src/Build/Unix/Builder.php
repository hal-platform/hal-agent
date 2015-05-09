<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\RemoteProcess;

class Builder
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run build command';
    const CONTAINER_WORKING_DIR = '/build';
    const DOCKERFILE_SOURCES = '/docker-images';

    /**
     * @type RemoteProcess
     */
    private $remoter;
    private $buildRemoter;

    /**
     * @type string|null
     */
    private $remoteUser;
    private $remoteServer;

    // debug
    private $logDockerCommands = true;

    /**
     * @param RemoteProcess $remoter
     * @param RemoteProcess $buildRemoter
     */
    public function __construct(RemoteProcess $remoter, RemoteProcess $buildRemoter)
    {
        $this->remoter = $remoter;
        $this->buildRemoter = $buildRemoter;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($remoteUser, $remoteServer, $remotePath, array $commands, array $env)
    {
        $imageName = 'php55';
        $fqImageName = sprintf('hal9000/%s', $imageName);
        $imagesBasePath = self::DOCKERFILE_SOURCES;

        $this->remoteUser = $remoteUser;
        $this->remoteServer = $remoteServer;

        // 1. Ensure docker source exists
        if (!$this->sanityCheck($imagesBasePath, $imageName)) {
            return $this->resetAndReturn(false);
        }

        // 2. Build docker image
        if (!$this->prepareImage($imageName, $fqImageName, $imagesBasePath)) {
            return $this->resetAndReturn(false);
        }

        // 3. Get owner of build dir
        if (!$dockerMeta = $this->getOwner($remotePath)) {
            return $this->resetAndReturn(false);
        }

        $owner = $dockerMeta['owner'];
        $group = $dockerMeta['group'];

        // 4. Start up docker container
        if (!$dockerMeta = $this->startContainer($remotePath, $fqImageName, $env)) {
            return $this->resetAndReturn(false);
        }

        $containerName = $dockerMeta['container'];

        // 5. Run commands
        if (!$this->runCommands($containerName, $commands)) {
            // @todo cleanup on fail
            return $this->resetAndReturn(false);
        }

        // 6. Shut down docker container
        if (!$this->cleanupContainer($containerName, $owner, $group)) {
            return $this->resetAndReturn(false);
        }

        return $this->resetAndReturn(true);
    }

    /**
     * @param bool $response
     *
     * @return bool
     */
    private function resetAndReturn($response)
    {
        $this->remoteUser = $this->remoteServer = null;
        return $response;
    }

    /**
     * @param string $imagesBasePath
     * @param string $imageName
     *
     * @return bool
     */
    private function sanityCheck($imagesBasePath, $imageName)
    {
        $remoter = $this->remoter;

        $checkFile = sprintf(
            'test -f "%s/%s"',
            rtrim($imagesBasePath, '/'),
            $imageName
        );

        // SYSTEM PREP:

        // 1. Make sure dockerfile source is present:

        // sudo mkdir /docker-images && sudo chown -R hal9000test:hal-agent /docker-images
        // sudo su hal9000test && cd /docker-images
        // curl -v -L http://git/api/v3/repos/skluck/docker-images/tarball/master --output master.tar.gz
        // tar -xzf master.tar.gz && rm master.tar.gz
        // cd $(ls -d */ | grep SKluck) && mv {,.[!.],..?}* ..
        // cd .. && rm -r $(ls -d */ | grep SKluck)

        // 2. Make sure scratch is present:

        // sudo su hal9000test
        // mkdir /tmp/hal9000

        if (!$response = $remoter($this->remoteUser, $this->remoteServer, $checkFile, [], $this->logDockerCommands)) {
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
        $remoter = $this->buildRemoter;

        $build = [
            'docker build',
            sprintf('--tag="%s"', $fqImageName),
            sprintf('"%s/%s"', rtrim($imagesBasePath, '/'), $imageName)
        ];

        if (!$response = $remoter($this->remoteUser, $this->remoteServer, implode(' ', $build), [], $this->logDockerCommands)) {
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
        $remoter = $this->remoter;

        $getOwnerNumber = [
            'ls -ldn',
            $remotePath,
            '|',
            'awk \'{print $3}\''
        ];

        $getGroupNumber = [
            'ls -ldn',
            $remotePath,
            '|',
            'awk \'{print $4}\''
        ];

        if (!$response = $remoter($this->remoteUser, $this->remoteServer, implode(' ', $getOwnerNumber), [], $this->logDockerCommands)) {
            return null;
        }

        $owner = $remoter->getLastOutput();

        if (!$response = $remoter($this->remoteUser, $this->remoteServer, implode(' ', $getGroupNumber), [], $this->logDockerCommands)) {
            return null;
        }

        $group = $remoter->getLastOutput();

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
        $remoter = $this->remoter;

        $command = [
            'docker run',
            '--detach=true',
            '--tty=true',
            '--interactive=true',
            sprintf('--volume="%s:%s"', $remotePath, self::CONTAINER_WORKING_DIR),
            sprintf('--workdir="%s"', self::CONTAINER_WORKING_DIR),
            $imageName
        ];

        foreach ($env as $name => $var) {
            $command[] = sprintf('--env %s', $name);
        }

        $command[] = 'bash -l';

        if (!$response = $remoter($this->remoteUser, $this->remoteServer, implode(' ', $command), $env, $this->logDockerCommands)) {
            return null;
        }

        $containerName = $response->getLastOutput();

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
            'docker exec',
            sprintf('"%s"', $containerName)
        ];
        $prefix = implode(' ', $prefix);

        foreach ($commands as $command) {
            $actual = sprintf('sh -c "%s"', $command);
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
     * @return bool
     */
    private function cleanupContainer($containerName, $owner, $group)
    {
        $remoter = $this->remoter;

        $chown = sprintf('chown -R %s:%s "%s"', $owner, $group, self::CONTAINER_WORKING_DIR);
        $chown = [
            'docker exec',
            sprintf('"%s"', $containerName),
            sprintf('sh -c "%s"', $chown)
        ];

        $kill = [
            // Kill
            'docker kill',
            sprintf('"%s"', $containerName),

            '&&',

            // ...and remove container
            'docker rm',
            sprintf('"%s"', $containerName),
        ];

        // Do not care whether chown fails
        $response = $remoter($this->remoteUser, $this->remoteServer, implode(' ', $chown), [], $this->logDockerCommands);

        if (!$response = $remoter($this->remoteUser, $this->remoteServer, implode(' ', $kill), [], $this->logDockerCommands)) {
            return false;
        }

        return true;
    }
}
