<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingBuilder
{
    const PREPARING_BUILD_ENVIRONMENT = 'Prepare build environment';
    const ERR_INVALID_BUILDER = 'Invalid build system specified';
    const UNKNOWN_FAILURE_CODE = 5;
    const DOCKER_PREFIX = 'docker:';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ContainerInterface
     */
    private $container;

    /**
     * @type int
     */
    private $exitCode;

    /**
     * An array of builder handlers
     *
     * Example:
     *     unix => 'service.unix.builder'
     *
     * @type array
     */
    private $builders;

    /**
     * @type boolean
     */
    private $enableStaging;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $container
     * @param array $builders
     */
    public function __construct(EventLogger $logger, ContainerInterface $container, array $builders = [])
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->builders = $builders;

        $this->exitCode = 0;
        $this->enableStaging = false;
    }

    /**
     * @param OutputInterface $output
     * @param string $method
     * @param array $properties
     * @param array $commands
     *
     * @return bool
     */
    public function __invoke(OutputInterface $output, $system, array $commands, array $properties)
    {
        // reset exit code
        $this->exitCode = 0;

        // Convert "docker/" system to unix
        if (substr($system, 0, 7) === self::DOCKER_PREFIX) {
            $system = 'unix';
        }

        if (!$system || !isset($this->builders[$system])) {
            return $this->explode($system);
        }

        $serviceId = $this->builders[$system];

        // Get the builder
        $builder = $this->container->get($serviceId, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // Builder must be invokeable
        if (!$builder instanceof BuildHandlerInterface) {
            return $this->explode($system);
        }

        if ($this->enableStaging) {
            $this->logger->setStage('building');
        }

        // Record build environment properties
        if (isset($properties[$system])) {
            $this->logger->event('success', static::PREPARING_BUILD_ENVIRONMENT, $properties[$system]);
        }

        if ($builder instanceof OutputAwareInterface) {
            $builder->setOutput($output);
        }

        $this->exitCode = $builder($commands, $properties);
        return ($this->exitCode === 0);
    }

    /**
     * @param string $system
     * @return bool
     */
    private function explode($system)
    {
        $this->exitCode = static::UNKNOWN_FAILURE_CODE;

        $this->logger->event('failure', self::ERR_INVALID_BUILDER, [
            'system' => $system
        ]);

        return false;
    }

    /**
     * Set whether to start the "building" stage.
     *
     * Do not enable this if building during push.
     *
     * @param EventLogger $logger
     *
     * @return void
     */
    public function enableStaging()
    {
        $this->enableStaging = true;
    }

    /**
     * @return int
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }
}
