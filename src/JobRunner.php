<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent;

use Hal\Agent\Command\IOInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Core\Entity\Job;
use Hal\Core\Type\JobEventStageEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;

class JobRunner
{
    private const ERR_INVALID_BUILDER = 'Invalid job platform specified';
    private const ERR_NONE_CONFIGURED = 'No platforms configured';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * An array of build platform services
     *
     * Example:
     *     linux => 'service.linux.builder'
     *     windows => 'service.windows.builder'
     *     rsync => 'service.rsync.deployer'
     *
     * @var array
     */
    private $platforms;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $container
     * @param array $builders
     */
    public function __construct(EventLogger $logger, ContainerInterface $container, array $platforms = [])
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->platforms = $platforms;
    }

    /**
     * @param Job $job
     * @param IOInterface $io
     * @param string $platform
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(Job $job, IOInterface $io, string $platform, array $config, array $properties): bool
    {
        if (!$platform || !isset($this->platforms[$platform])) {
            return $this->explode($platform ?: 'Unknown');
        }

        if (!$service = $this->getPlatform($platform)) {
            return $this->explode($platform);
        }

        $this->logger->setStage(JobEventStageEnum::TYPE_RUNNING);

        $service->setIO($io);

        return $service($job, $config, $properties);
    }

    /**
     * @param string $platform
     *
     * @return JobPlatformInterface|null
     */
    private function getPlatform($platform)
    {
        $serviceID = $this->platforms[$platform];

        // Get the builder
        $platform = $this->container->get($serviceID, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // Builder must be invokeable
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
        $this->logger->event('failure', self::ERR_INVALID_BUILDER, [
            'platform' => $platform,
            'validPlatforms' => $this->platforms ? array_keys($this->platforms) : self::ERR_NONE_CONFIGURED
        ]);

        return false;
    }
}
