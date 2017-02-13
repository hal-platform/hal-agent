<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Symfony\OutputAwareInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DelegatingDeployer
{
    const ERR_INVALID_DEPLOYMENT = 'Invalid deployment method specified';
    const UNKNOWN_FAILURE_CODE = 6;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var int
     */
    private $exitCode;

    /**
     * An array of deployment handlers
     * Example:
     *     rsync => 'service.rsync.deployer'
     *
     * @var array
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
        if (!$deployer instanceof DeployerInterface) {
            return $this->explode($method);
        }

        $this->logger->setStage('pushing');

        if ($deployer instanceof OutputAwareInterface) {
            $deployer->setOutput($output);
        }

        $this->exitCode = $deployer($properties);
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
