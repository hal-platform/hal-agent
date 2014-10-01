<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use MCP\DataType\Time\Clock;
use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Deployment;
use QL\Hal\Core\Entity\Repository\PushRepository;

/**
 * Resolve push properties from user and environment input
 */
class Resolver
{
    /**
     * @var string
     */
    const FS_DIRECTORY_PREFIX = 'hal9000-push-%s';
    const FS_ARCHIVE_PREFIX = 'hal9000-%s.tar.gz';

    /**
     * @var string
     */
    const SUCCESS_FOUND = 'Found push: %s';
    const ERR_NOT_FOUND = 'Push "%s" could not be found!';
    const ERR_BAD_STATUS = 'Push "%s" has a status of "%s"! It cannot be redeployed.';
    const ERR_CLOBBERING_TIME = 'Push "%s" is trying to clobber a running push! It cannot be deployed at this time.';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var PushRepository
     */
    private $pushRepo;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var string
     */
    private $sshUser;

    /**
     * @var string
     */
    private $envPath;

    /**
     * @var string
     */
    private $archivePath;

    /**
     * @var string
     */
    private $buildDirectory;

    /**
     * @var string
     */
    private $homeDirectory;

    /**
     * @param LoggerInterface $logger
     * @param PushRepository $pushRepo
     * @param Clock $clock
     * @param string $sshUser
     * @param string $envPath
     * @param string $archivePath
     */
    public function __construct(LoggerInterface $logger, PushRepository $pushRepo, Clock $clock, $sshUser, $envPath, $archivePath)
    {
        $this->logger = $logger;
        $this->pushRepo = $pushRepo;
        $this->clock = $clock;
        $this->sshUser = $sshUser;
        $this->envPath = $envPath;
        $this->archivePath = $archivePath;
    }

    /**
     * @param string $pushId
     * @param string $method
     * @return array|null
     */
    public function __invoke($pushId, $method)
    {
        if (!$push = $this->pushRepo->find($pushId)) {
            $this->logger->error(sprintf(self::ERR_NOT_FOUND, $pushId));
            return null;
        }

        $this->logger->info(sprintf(self::SUCCESS_FOUND, $pushId));

        if ($push->getStatus() !== 'Waiting') {
            $this->logger->error(sprintf(self::ERR_BAD_STATUS, $pushId, $push->getStatus()));
            return null;
        }

        if ($this->hasConcurrentDeployment($push->getDeployment())) {
            $this->logger->error(sprintf(self::ERR_CLOBBERING_TIME, $pushId));
            return null;
        }

        $build = $push->getBuild();
        $repository = $build->getRepository();
        $deployment = $push->getDeployment();

        // validate remote hostname
        $serverName = $deployment->getServer()->getName();
        if (!$hostname = $this->validateHostname($serverName)) {
            $this->logger->critical(sprintf('Cannot resolve hostname "%s"', $serverName));
        }

        $properties = [
            'push' => $push,
            'method' => $method,
            'hostname' => $hostname,
            'syncPath' => sprintf('%s@%s:%s', $this->sshUser, $hostname, $deployment->getPath()),
            'remotePath' => $deployment->getPath(),
            'excludedFiles' => [
                // could easily make this an application-specific setting
                'config/database.ini',
                'data/'
            ],

            'archiveFile' => $this->generateBuildArchive($build->getId()),
            'buildPath' => $this->generatePushPath($push->getId()),

            'buildCommand' => '',
            'prePushCommand' => $repository->getPrePushCmd(),
            'postPushCommand' => $repository->getPostPushCmd(),

            'pushProperties' => [
                'id' => $build->getId(),
                'source' => sprintf(
                    'http://git/%s/%s',
                    $repository->getGithubUser(),
                    $repository->getGithubRepo()
                ),
                'env' => $build->getEnvironment()->getKey(),
                'user' => $push->getUser() ? $push->getUser()->getHandle() : null,
                'branch' => $build->getBranch(),
                'commit' => $build->getCommit(),
                'date' => $this->clock->read()->format('c', 'America/Detroit')
            ],

            'environmentVariables' => $this->generateBuildEnvironmentVariables($build, $deployment, $hostname),
            'serverEnvironmentVariables' => $this->generateServerEnvironmentVariables($build, $deployment, $hostname),
        ];

        $properties['artifacts'] = $this->findPushArtifacts($properties);

        $this->logger->info('Resolved push properties', $properties);
        return $properties;
    }

    /**
     * Set the base directory in which temporary build artifacts are stored.
     *
     * If none is provided the system temporary directory is used.
     *
     * @param string $directory
     *  @return null
     */
    public function setBaseBuildDirectory($directory)
    {
        $this->buildDirectory = $directory;
    }

    /**
     * Set the home directory for all build scripts. This can easily be changed
     * later to be unique for each build.
     *
     * If none is provided a common location within the shared build directory is used.
     *
     *  @param string $directory
     *  @return string
     */
    public function setHomeDirectory($directory)
    {
        $this->homeDirectory = $directory;
    }

    /**
     * Find the push artifacts that must be cleaned up after push.
     *
     * @param array $properties
     * @return array
     */
    private function findPushArtifacts(array $properties)
    {
        return [
            $properties['buildPath']
        ];
    }

    /**
     *  Generate a target for the github repository archive.
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildArchive($id)
    {
        return sprintf(
            '%s%s%s',
            rtrim($this->archivePath, '/'),
            DIRECTORY_SEPARATOR,
            sprintf(self::FS_ARCHIVE_PREFIX, $id)
        );
    }

    /**
     * @param Build $build
     * @param Deployment $deployment
     * @param string $hostname
     * @return array
     */
    private function generateBuildEnvironmentVariables(Build $build, Deployment $deployment, $hostname)
    {
        $vars = [
            'HOME' => $this->generateHomePath(),
            'PATH' => $this->envPath
        ];

        return array_merge($vars, $this->generateServerEnvironmentVariables($build, $deployment, $hostname));
    }

    /**
     * @param Build $build
     * @param Deployment $deployment
     * @param string $hostname
     * @return array
     */
    private function generateServerEnvironmentVariables(Build $build, Deployment $deployment, $hostname)
    {
        $vars = [
            'HAL_HOSTNAME' => $hostname,
            'HAL_PATH' => $deployment->getPath(),

            'HAL_BUILDID' => $build->getId(),
            'HAL_COMMIT' => $build->getCommit(),
            'HAL_GITREF' => $build->getBranch(),
            'HAL_ENVIRONMENT' => $build->getEnvironment()->getKey(),
            'HAL_REPO' => $build->getRepository()->getKey()
        ];

        return $vars;
    }

    /**
     *  Generate a target for $HOME and/or $TEMP
     *
     *  @param string $id
     *  @return string
     */
    private function generateHomePath()
    {
        if (!$this->homeDirectory) {
            $this->homeDirectory = $this->getBuildDirectory() . 'home';
        }

        return rtrim($this->homeDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     *  Generate a target for the build path.
     *
     *  @param string $id
     *  @return string
     */
    private function generatePushPath($id)
    {
        return $this->getBuildDirectory() . sprintf(self::FS_DIRECTORY_PREFIX, $id);
    }

    /**
     *  @param string $id
     *  @return string
     */
    private function getBuildDirectory()
    {
        if (!$this->buildDirectory) {
            $this->buildDirectory = sys_get_temp_dir();
        }

        return rtrim($this->buildDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * This is rather expensive, but we need to prevent concurrent syncs.
     *
     * The push worker also has logic to avoid concurrent syncs, so this is more of a backup. This doesn't seem
     * to ever hit successfully because the child workers fork so quickly.
     *
     * @param Deployment $deployment
     * @return boolean
     */
    private function hasConcurrentDeployment(Deployment $deployment)
    {
        $concurrentSyncs = $this->pushRepo->findBy([
            'status' => 'Pushing',
            'deployment' => $deployment
        ]);

        return (count($concurrentSyncs) > 0);
    }

    /**
     *  Validate a hostname
     *
     *  @param string $hostname
     *  @return string|null
     */
    private function validateHostname($hostname)
    {
        if (filter_var($hostname, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $this->logger->info('Hostname appears to be an IP, skipping check.');
            return $hostname;
        }

        if ($hostname !== gethostbyname($hostname)) {
            $this->logger->info(sprintf('Hostname "%s" resolved.', $hostname));
            return $hostname;
        }

        $hostname = sprintf('%s.rockfin.com', $hostname);
        if ($hostname !== gethostbyname($hostname)) {
            $this->logger->info(sprintf('Hostname "%s" resolved.', $hostname));
            return $hostname;
        }

        return null;
    }
}
