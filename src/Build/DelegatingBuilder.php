<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingBuilder
{
    const ERR_INVALID_BUILDER = 'Invalid build system specified';

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
     * Example:
     *     unix => 'service.unix.builder'
     *
     * @type array
     */
    private $builders;

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
    }

    /**
     * @param OutputInterface $output
     * @param string $method
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(OutputInterface $output, $system, array $properties)
    {
        // reset exit code
        $this->exitCode = 0;

        if (!$system || !isset($this->builders[$system])) {
            return $this->explode($system);
        }

        $serviceId = $this->builders[$system];

        // Get the builder
        $builder = $this->container->get($serviceId, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // Builder must be invokeable
        if (!is_callable($builder)) {
            return $this->explode($system);
        }

        $this->exitCode = $builder($output, $properties);
        return ($this->exitCode === 0);
    }

    /**
     * @param string $system
     * @return bool
     */
    private function explode($system)
    {
        $this->exitCode = 5;
        $this->logger->event('failure', self::ERR_INVALID_BUILDER, [
            'system' => $system
        ]);
        return false;
    }

    /**
     * @return int
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }
}
