<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Core\Entity\Type\ServerEnumType;
use Symfony\Component\Console\Output\OutputInterface;

class Deployer implements DeployerInterface
{
    const STATUS = 'Deploying push by rsync';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type CodeDelta
     */
    private $delta;

    /**
     * @type ServerCommand
     */
    private $serverCommand;

    /**
     * @type Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param CodeDelta $delta
     * @param ServerCommand $serverCommand
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        CodeDelta $delta,
        ServerCommand $serverCommand,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->delta = $delta;
        $this->serverCommand = $serverCommand;
        $this->pusher = $pusher;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(OutputInterface $output, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties[ServerEnumType::TYPE_RSYNC])) {
            return 100;
        }

        // record code delta
        $this->delta($output, $properties);

        // run pre push commands
        if (!$this->prepush($output, $properties)) {
            return 101;
        }

        // sync code
        if (!$this->push($output, $properties)) {
            return 102;
        }

        // run post push commands
        if (!$this->postpush($output, $properties)) {
            return 103;
        }

        // success
        return 0;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function delta(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Reading previous push data');

        $delta = $this->delta;
        return $delta(
            $properties[ServerEnumType::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnumType::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnumType::TYPE_RSYNC]['remotePath'],
            $properties['pushProperties']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function prepush(OutputInterface $output, array $properties)
    {
        if (!$properties['configuration']['pre_push']) {
            $this->status($output, 'Skipping pre-push command');
            return true;
        }

        $this->status($output, 'Running pre-push command');

        $prepush = $this->serverCommand;
        return $prepush(
            $properties[ServerEnumType::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnumType::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnumType::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['pre_push'],
            $properties[ServerEnumType::TYPE_RSYNC]['environmentVariables']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function push(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Pushing code to server');

        $pusher = $this->pusher;
        return $pusher(
            $properties['location']['path'],
            $properties[ServerEnumType::TYPE_RSYNC]['syncPath'],
            $properties['configuration']['exclude']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function postpush(OutputInterface $output, array $properties)
    {
        if (!$properties['configuration']['post_push']) {
            $this->status($output, 'Skipping post-push command');
            return true;
        }

        $this->status($output, 'Running post-push command');

        $postpush = $this->serverCommand;
        return $postpush(
            $properties[ServerEnumType::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnumType::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnumType::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['post_push'],
            $properties[ServerEnumType::TYPE_RSYNC]['environmentVariables']
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
