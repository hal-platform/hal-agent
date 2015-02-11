<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingDeployer
{
    const ERR_INVALID_DEPLOYMENT = 'Invalid deployment method specified';
    const UNKNOWN_FAILURE_CODE = 6;

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
     * An array of deployment handlers
     * Example:
     *     rsync => 'service.rsync.deployer'
     *
     * @type array
     */
    private $deployers;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $container
     * @param array $deployers
     */
    public function __construct(EventLogger $logger, ContainerInterface $container, array $deployers = [])
    {
        $this->logger = $logger;
        $this->container = $container;
        $this->deployers = $deployers;

        $this->exitCode = 0;
    }

    /**
     * @param OutputInterface $output
     * @param string $method
     * @param array $properties
     *
     * @return bool
     */
    public function __invoke(OutputInterface $output, $method, array $properties)
    {
        // reset exit code
        $this->exitCode = 0;

        if (!$method || !isset($this->deployers[$method])) {
            return $this->explode($method);
        }

        $serviceId = $this->deployers[$method];

        // Get the deployer
        $deployer = $this->container->get($serviceId, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // Deployer must be invokeable
        if (!is_callable($deployer)) {
            return $this->explode($method);
        }

        $this->logger->setStage('pushing');

        $this->exitCode = $deployer($output, $properties);
        return ($this->exitCode === 0);
    }

    /**
     * @param string $method
     * @return bool
     */
    private function explode($method)
    {
        $this->exitCode = self::UNKNOWN_FAILURE_CODE;

        $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT, [
            'method' => $method
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
