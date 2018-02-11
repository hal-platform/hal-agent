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
    private const MSG_SUCCESS = 'Job stage completed successfully';

    private const ERR_JOB_FAILURE = 'Job stage failed';
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
     * @param JobExecution $execution
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(Job $job, IOInterface $io, JobExecution $execution, array $properties): bool
    {
        $platform = $execution->platform();

        if (!$platform || !isset($this->platforms[$platform])) {
            return $this->sendFailureEvent($io, $platform ?: 'Unknown', self::ERR_INVALID_BUILDER);
        }

        if (!$service = $this->getPlatform($platform)) {
            return $this->sendFailureEvent($io, $platform, self::ERR_INVALID_BUILDER);
        }

        $this->logger->setStage(JobEventStageEnum::TYPE_RUNNING);

        $service->setIO($io);

        $result = $service($job, $execution, $properties);

        if (!$result) {
            return $this->sendFailureEvent($io, $platform, self::ERR_JOB_FAILURE);
        }

        $io->success(self::MSG_SUCCESS);

        return true;
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
     * @param IOInterface $io
     * @param string $platform
     * @param string $message
     *
     * @return bool
     */
    private function sendFailureEvent(IOInterface $io, $platform, $message)
    {
        $this->logger->event('failure', $message, [
            'platform' => $platform,
            'validPlatforms' => $this->platforms ? array_keys($this->platforms) : self::ERR_NONE_CONFIGURED
        ]);

        $io->error($message);

        return false;
    }
}
