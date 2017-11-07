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
    const ERR_INVALID_RELEASE = 'Invalid Release method specified';
    const UNKNOWN_FAILURE_CODE = 6;

    const ERR_UNKNOWN = 'Unknown deployment failure';

    const EXIT_CODES = [
        100 => 'Required properties for rsync are missing.',
        101 => 'Could not verify target directory.',
        102 => 'Pre push command failed.',
        103 => 'Rsync push failed.',
        104 => 'Post push command failed.',

        200 => 'Required properties for EB are missing.',
        201 => 'Failed to authenticate with AWS.',
        202 => 'Elastic Beanstalk environment is not ready.',
        203 => 'Build could not be packed for S3.',
        204 => 'Upload to S3 failed.',
        205 => 'Deploying application to EB failed.',

        300 => 'Required properties for script are missing.',
        301 => 'No deployment scripts are defined.',
        302 => 'Deployment command failed.',

        400 => 'Required properties for S3 are missing.',
        401 => 'Failed to authenticate with AWS.',
        402 => 'Build could not be packed for S3.',
        403 => 'Upload to S3 failed.',

        500 => 'Required properties for CodeDeploy are missing.',
        501 => 'Failed to authenticate with AWS.',
        502 => 'CodeDeploy group is not ready.',
        503 => 'Build could not be packed for S3.',
        504 => 'Upload to S3 failed.',
        505 => 'Deploying application to CodeDeploy failed.'
    ];

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

        $this->logger->event('failure', self::ERR_INVALID_RELEASE, [
            'method' => $method
        ]);

        return false;
    }

    /**
     * @return string
     */
    public function getFailureMessage()
    {
        return self::EXIT_CODES[$this->exitCode] ?? self::ERR_UNKNOWN;
    }
}
