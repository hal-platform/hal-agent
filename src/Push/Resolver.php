<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Repository\BuildRepository;
use QL\Hal\Core\Entity\Repository\DeploymentRepository;

/**
 * Resolve deployment properties from user and environment input
 */
class Resolver
{
    /**
     * @var string
     */
    const SUCCESS_BUILD_FOUND = 'Found build: %s';
    const ERR_BUILD_NOT_FOUND = 'Build "%s" could not be found!';
    const ERR_BUILD_BAD_STATUS = 'Build "%s" has a status of "%s"! It cannot be deployed.';
    const ERR_DEPLOY_NOT_FOUND = 'Deployment "%s" could not be found!';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BuildRepository
     */
    private $buildRepo;

    /**
     * @var DeploymentRepository
     */
    private $deployRepo;

    /**
     * @param LoggerInterface $logger
     * @param BuildRepository $buildRepo
     * @param DeploymentRepository $deployRepo
     */
    public function __construct(
        LoggerInterface $logger,
        BuildRepository $buildRepo,
        DeploymentRepository $deployRepo
    ) {
        $this->logger = $logger;
        $this->buildRepo = $buildRepo;
        $this->deployRepo = $deployRepo;
    }

    /**
     * @param string $buildId
     * @param string $deployId
     * @param string $method
     * @return array|null
     */
    public function __invoke($buildId, $deployId, $method)
    {
        if (!$build = $this->buildRepo->find($buildId)) {
            $this->logger->error(sprintf(self::ERR_BUILD_NOT_FOUND, $buildId));
            return null;
        }

        $this->logger->info(sprintf(self::SUCCESS_BUILD_FOUND, $buildId));

        if ($build->getStatus() !== 'Success') {
            $this->logger->error(sprintf(self::ERR_BUILD_BAD_STATUS, $buildId, $build->getStatus()));
            return null;
        }

        if (!$deployment = $this->deployRepo->find($deployId)) {
            $this->logger->error(sprintf(self::ERR_DEPLOY_NOT_FOUND, $deployId));
            return null;
        }

        // validate remote hostname
        if (!$hostname = $this->validateHostname($server)) {
            $this->logger->critical(sprintf('Cannot resolve hostname "%s"', $server));
        }

        return [
            'build' => $build,
            'deployment' => $deployment,
            'method' => $method,
            'hostname' => $hostname
        ];
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
