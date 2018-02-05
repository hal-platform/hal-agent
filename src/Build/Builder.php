<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Command\IOInterface;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Builder
{
    const ERR_INVALID_BUILDER = 'Invalid build platform specified';

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
     * @param IOInterface $io
     * @param string $platform
     * @param array $config
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(IOInterface $io, string $platform, array $config, array $properties)
    {
        if (!$platform || !isset($this->platforms[$platform])) {
            return $this->explode($platform ?: 'Unknown');
        }

        if (!$platform = $this->getPlatform($platform)) {
            return $this->explode($platform);
        }

        $this->logger->setStage('running');

        $platform->setIO($io);

        return $platform($config, $properties);
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

        // Builder must be invokeable
        if (!$platform instanceof BuildPlatformInterface) {
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
            'platform' => $platform
        ]);

        return false;
    }
}
