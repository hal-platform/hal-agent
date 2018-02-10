<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy;

use Hal\Agent\Command\IOInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\JobPlatformInterface;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Type\JobEventStageEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Deployer
{
    private const ERR_INVALID_DEPLOYER = 'Invalid deployment platform specified';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * An array of deployment platform services
     *
     * Example:
     *     rsync => 'service.rsync.deployer'
     *
     * @var array
     */
    private $platforms;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $container
     * @param array $platforms
     */
    public function __construct(EventLogger $logger, ContainerInterface $container, array $platforms = [])
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->platforms = $platforms;
    }

    /**
     * @param Release $release
     * @param IOInterface $io
     * @param string $platform
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(Release $release, IOInterface $io, string $platform, array $config, array $properties): bool
    {
        if (!$platform || !isset($this->platforms[$platform])) {
            return $this->explode($platform ?: 'Unknown');
        }

        if (!$service = $this->getPlatform($platform)) {
            return $this->explode($platform);
        }

        $this->logger->setStage(JobEventStageEnum::TYPE_RUNNING);

        $service->setIO($io);

        return $service($release, $config, $properties);
    }

    /**
     * @param string $platform
     *
     * @return BuildPlatformInterface|null
     */
    private function getPlatform($platform)
    {
        $serviceID = $this->platforms[$platform];

        // Get the builder
        $platform = $this->container->get($serviceID, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        if (!$platform instanceof JobPlatformInterface) {
            return null;
        }

        return $platform;
    }

    /**
     * @param string $platform
     *
     * @return bool
     */
    private function explode($platform)
    {
        $this->logger->event('failure', self::ERR_INVALID_DEPLOYER, [
            'platform' => $platform
        ]);

        return false;
    }
}
