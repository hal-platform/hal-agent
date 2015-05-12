<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\ServerEnumType;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - Rsync';
    const STATUS = 'Deploying push by rsync';
    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'Rsync deployment system is not configured';

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
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[ServerEnumType::TYPE_RSYNC])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
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
        $this->status('Reading previous push data', self::SECTION);

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
            $this->status('Skipping pre-push command', self::SECTION);
            return true;
        }

        $this->status('Running pre-push command', self::SECTION);

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
        $this->status('Pushing code to server', self::SECTION);

        $pusher = $this->pusher;

        return $pusher(
            $properties['location']['path'],
            $properties[ServerEnumType::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnumType::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnumType::TYPE_RSYNC]['remotePath'],
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
            $this->status('Skipping post-push command', self::SECTION);
            return true;
        }

        $this->status('Running post-push command', self::SECTION);

        $postpush = $this->serverCommand;
        return $postpush(
            $properties[ServerEnumType::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnumType::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnumType::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['post_push'],
            $properties[ServerEnumType::TYPE_RSYNC]['environmentVariables']
        );
    }
}
