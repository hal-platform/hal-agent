<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\EC2;

use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Core\Type\ServerEnumType;
use Symfony\Component\Console\Output\OutputInterface;

class Deployer implements DeployerInterface
{
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
    public function __invoke(OutputInterface $output, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties[ServerEnumType::TYPE_EC2])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 300;
        }

        if (!$instances = $this->finder($output, $properties)) {
            $this->logger->event('failure', self::ERR_NO_INSTANCES);
            return 301;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // push
        if (!$this->push($output, $properties, $instances)) {
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
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return array|null
     */
    private function finder(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Finding EC2 instances in pool');

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
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        if (!$properties['configuration']['build_transform']) {
            $this->status($output, 'Skipping build command');
            return true;
        }

        $this->status($output, 'Running build command');

        $builder = $this->builder;
        return $builder($output, $properties['configuration']['system'], $properties);
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     * @param array $instances
     *
     * @return boolean
     */
    private function push(OutputInterface $output, array $properties, array $instances)
    {
        $this->status($output, 'Pushing code to EC2 instances');

        $pusher = $this->pusher;
        return $pusher(
            $properties['location']['path'],
            $properties[ServerEnumType::TYPE_EC2]['remotePath'],
            $properties['configuration']['exclude'],
            $instances
        );
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     *
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $message = sprintf('<comment>%s</comment>', $message);
        $output->writeln($message);
    }
}
