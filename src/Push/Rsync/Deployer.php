<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Push\Builder;
use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Output\OutputInterface;

class Deployer implements DeployerInterface
{
    const TYPE = 'rsync';
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
     * @type Builder
     */
    private $builder;

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
     * @param Builder $builder
     * @param ServerCommand $serverCommand
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        CodeDelta $delta,
        Builder $builder,
        ServerCommand $serverCommand,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->delta = $delta;
        $this->builder = $builder;
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
        if (!isset($properties[self::TYPE])) {
            return 100;
        }

        // record code delta
        $this->delta($output, $properties);

        // run build transform commands
        if (!$this->build($output, $properties)) {
            return 101;
        }

        $this->logger->setStage('pushing');

        // run pre push commands
        if (!$this->prepush($output, $properties)) {
            return 102;
        }

        // sync code
        if (!$this->push($output, $properties)) {
            return 103;
        }

        // run post push commands
        if (!$this->postpush($output, $properties)) {
            return 104;
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
            $properties[self::TYPE]['hostname'],
            $properties[self::TYPE]['remotePath'],
            $properties['pushProperties']
        );
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
        return $builder(
            $properties['configuration']['system'],
            $properties['location']['path'],
            $properties['configuration']['build_transform'],
            $properties['environmentVariables']
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
            $properties[self::TYPE]['hostname'],
            $properties[self::TYPE]['remotePath'],
            $properties['configuration']['pre_push'],
            $properties[self::TYPE]['environmentVariables']
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
            $properties[self::TYPE]['syncPath'],
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
            $properties[self::TYPE]['hostname'],
            $properties[self::TYPE]['remotePath'],
            $properties['configuration']['post_push'],
            $properties[self::TYPE]['environmentVariables']
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
