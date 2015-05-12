<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\EC2;

use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\ServerEnumType;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - EC2';
    const STATUS = 'Deploying push by EC2';
    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'EC2 deployment system is not configured';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for EC2 deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for EC2 deployment';
    const ERR_NO_INSTANCES = 'No EC2 instances are running';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type InstanceFinder
     */
    private $finder;

    /**
     * @type Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param InstanceFinder $finder
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        InstanceFinder $finder,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->finder = $finder;
        $this->pusher = $pusher;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[ServerEnumType::TYPE_EC2])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 300;
        }

        if (!$instances = $this->finder($properties)) {
            $this->logger->event('failure', self::ERR_NO_INSTANCES);
            return 301;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // push
        if (!$this->push($properties, $instances)) {
            return 302;
        }

        // SKIP post-push commands
        if ($properties['configuration']['post_push']) {
            $this->logger->event('info', self::SKIP_POST_PUSH);
        }

        // success
        return 0;
    }

    /**
     * @param array $properties
     *
     * @return array|null
     */
    private function finder(array $properties)
    {
        $this->status('Finding EC2 instances in pool', self::SECTION);

        $finder = $this->finder;
        $instances = $finder(
            $properties[ServerEnumType::TYPE_EC2]['pool'],
            InstanceFinder::RUNNING
        );

        if (!$instances) {
            return null;
        }

        return $instances;
    }

    /**
     * @param array $properties
     * @param array $instances
     *
     * @return boolean
     */
    private function push(array $properties, array $instances)
    {
        $this->status('Pushing code to EC2 instances', self::SECTION);

        $pusher = $this->pusher;
        return $pusher(
            $properties['location']['path'],
            $properties[ServerEnumType::TYPE_EC2]['remotePath'],
            $properties['configuration']['exclude'],
            $instances
        );
    }
}
