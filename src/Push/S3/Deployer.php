<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Aws\S3\S3Client;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Type\TargetEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - S3';
    const STATUS = 'Deploying push by S3';

    const INVALID_DEPLOYMENT_SYSTEM_FAILURE_CODE = 400;
    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'S3 deployment system is not configured';

    const UNABLE_TO_DETERMINE_STRATEGY_FAILURE_CODE = 404;
    const ERR_UNABLE_TO_DETERMINE_STRATEGY = 'Unable to determine correct S3 deployment strategy';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for S3 deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for S3 deployment';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     ** An associative array of deployers by strategy
     * Example:
     *     [
     *         artifact => 'push.s3_artifact.deployer',
     *         sync => 'push.s3_sync.deployer'
     *     ]
     *
     * @var array
     */
    private $deployers;

    /**
     * @param EventLogger $logger
     * @param ContainerInterface $container
     * @param array $deployers
     */
    public function __construct(
        EventLogger $logger,
        ContainerInterface $container,
        array $deployers = []
    ) {
        $this->logger = $logger;
        $this->container = $container;
        $this->deployers = $deployers;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[TargetEnum::TYPE_S3]) || !$this->verifyConfiguration($properties[TargetEnum::TYPE_S3])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return self::INVALID_DEPLOYMENT_SYSTEM_FAILURE_CODE;
        }

        if (!$deployer = $this->getDeploymentStrategy($properties)) {
            $this->logger->event('failure', self::ERR_UNABLE_TO_DETERMINE_STRATEGY);
            return self::UNABLE_TO_DETERMINE_STRATEGY_FAILURE_CODE;
        }

        return $deployer($properties);
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyConfiguration($properties)
    {
        $this->status('Verifying S3 configuration', self::SECTION);

        if (!is_array($properties)) {
            return false;
        }

        $required = [
            // aws
            'region',
            'credential',
            // s3
            'bucket',
            'file',
            'src',
            'strategy'
        ];

        foreach ($required as $prop) {
            if (!array_key_exists($prop, $properties)) {
                return false;
            }
        }

        $this->status('Verified S3 configuration', self::SECTION);

        return true;
    }

    /**
     * @param array $properties
     *
     * @return bool|DeployerInterface
     */
    private function getDeploymentStrategy(array $properties)
    {
        $s3Properties = $properties[TargetEnum::TYPE_S3];
        if (!array_key_exists('strategy', $s3Properties)) {
            return false;
        }

        $strategy = $s3Properties['strategy'];
        if (!array_key_exists($strategy, $this->deployers)) {
            return false;
        }

        $this->status("Using ${strategy} deployment strategy", self::SECTION);

        $dependency = $this->deployers[$strategy];
        $deployer = $this->container->get($dependency, ContainerInterface::NULL_ON_INVALID_REFERENCE);

        if (!$deployer instanceof DeployerInterface) {
            return false;
        }

        if ($deployer instanceof OutputAwareInterface) {
            $deployer->setOutput($this->getOutput());
        }

        return $deployer;
    }
}
