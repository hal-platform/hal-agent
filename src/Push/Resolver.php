<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use MCP\DataType\Time\Clock;
use Psr\Log\LoggerInterface;
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
    const FS_BUILD_PREFIX = 'hal9000-%s.tar.gz';

    /**
     * @var string
     */
    const SUCCESS_FOUND = 'Found push: %s';
    const ERR_NOT_FOUND = 'Push "%s" could not be found!';
    const ERR_BAD_STATUS = 'Push "%s" has a status of "%s"! It cannot be redeployed.';

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
    private $buildDirectory;

    /**
     * @param LoggerInterface $logger
     * @param PushRepository $pushRepo
     * @param Clock $clock
     * @param string $sshUser
     */
    public function __construct(
        LoggerInterface $logger,
        PushRepository $pushRepo,
        Clock $clock,
        $sshUser
    ) {
        $this->logger = $logger;
        $this->pushRepo = $pushRepo;
        $this->clock = $clock;
        $this->sshUser = $sshUser;
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

        $server = $push->getDeployment()->getServer()->getName();

        // validate remote hostname
        if (!$hostname = $this->validateHostname($server)) {
            $this->logger->critical(sprintf('Cannot resolve hostname "%s"', $server));
        }

        $build = $push->getBuild();

        return [
            'push' => $push,
            'method' => $method,
            'hostname' => $hostname,
            'syncPath' => sprintf(
                '%s@%s:%s',
                $this->sshUser,
                $hostname,
                $push->getDeployment()->getPath()
            ),
            'excludedFiles' => [
                'config/database.ini',
                'data/'
            ],

            'archiveFile' => $this->generateBuildArchive($build->getId()),
            'buildPath' => $this->generatePushPath($push->getId()),
            'pushProperties' => [
                'id' => $build->getId(),
                'source' => sprintf(
                    'http://git/%s/%s',
                    $build->getRepository()->getGithubUser(),
                    $build->getRepository()->getGithubRepo()
                ),
                'env' => $build->getEnvironment()->getKey(),
                'user' => $push->getUser() ? $push->getUser()->getHandle() : null,
                'branch' => $build->getBranch(),
                'commit' => $build->getCommit(),
                'date' => $this->clock->read()->format('c', 'America/Detroit')
            ]
        ];
    }

    /**
     * @param string $directory
     *  @return null
     */
    public function setBaseBuildDirectory($directory)
    {
        $this->buildDirectory = $directory;
    }

    /**
     *  Generate a target for the github repository archive.
     *
     *  @param string $id
     *  @return string
     */
    private function generateBuildArchive($id)
    {
        return $this->getBuildDirectory() . 'debug-archive/' . sprintf(self::FS_BUILD_PREFIX, $id);
    }

    /**
     *  Generate a target for the build path.
     *
     *  @param string $id
     *  @return string
     */
    private function generatePushPath($id)
    {
        return $this->getBuildDirectory() . 'debug/' . sprintf(self::FS_DIRECTORY_PREFIX, $id);
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
