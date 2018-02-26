<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\Rsync;

use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use Hal\Core\Type\TargetEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - Rsync';
    const STATUS = 'Deploying push by rsync';
    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'Rsync deployment system is not configured';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Verify
     */
    private $verify;

    /**
     * @var CodeDelta
     */
    private $delta;

    /**
     * @var ServerCommand
     */
    private $serverCommand;

    /**
     * @var Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param Verify $verify
     * @param CodeDelta $delta
     * @param ServerCommand $serverCommand
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        Verify $verify,
        CodeDelta $delta,
        ServerCommand $serverCommand,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->verify = $verify;
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
        if (!isset($properties[TargetEnum::TYPE_RSYNC]) || !$this->verifyConfiguration($properties[TargetEnum::TYPE_RSYNC])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 100;
        }

        // Verify
        if (!$this->verify($properties)) {
            return 101;
        }

        // record code delta
        $this->delta($properties);

        // run pre push commands
        if (!$this->prepush($properties)) {
            return 102;
        }

        // sync code
        if (!$this->push($properties)) {
            return 103;
        }

        // run post push commands
        if (!$this->postpush($properties)) {
            return 104;
        }

        // success
        return 0;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyConfiguration($properties)
    {
        $this->status('Verifying RSync configuration', self::SECTION);

        if (!is_array($properties)) {
            return false;
        }

        if (!array_key_exists('remoteUser', $properties)) {
            return false;
        }

        if (!array_key_exists('remoteServer', $properties)) {
            return false;
        }

        if (!array_key_exists('remotePath', $properties)) {
            return false;
        }

        if (!array_key_exists('syncPath', $properties)) {
            return false;
        }

        if (!array_key_exists('environmentVariables', $properties)) {
            return false;
        }

//        if (!array_key_exists('credential', $properties)) {
//            return false;
//        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function verify(array $properties)
    {
        $this->status('Verifying target directory', self::SECTION);

        $verify = $this->verify;
        return $verify(
            $properties[TargetEnum::TYPE_RSYNC]['remoteUser'],
            $properties[TargetEnum::TYPE_RSYNC]['remoteServer'],
            $properties[TargetEnum::TYPE_RSYNC]['remotePath']
        );
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function delta(array $properties)
    {
        $this->status('Reading previous push data', self::SECTION);

        $delta = $this->delta;
        return $delta(
            $properties[TargetEnum::TYPE_RSYNC]['remoteUser'],
            $properties[TargetEnum::TYPE_RSYNC]['remoteServer'],
            $properties[TargetEnum::TYPE_RSYNC]['remotePath'],
            $properties['pushProperties']
        );
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function prepush(array $properties)
    {
        if (!$properties['configuration']['pre_push']) {
            $this->status('Skipping pre-push command', self::SECTION);
            return true;
        }

        $this->status('Running pre-push command', self::SECTION);

        $prepush = $this->serverCommand;
        return $prepush(
            $properties[TargetEnum::TYPE_RSYNC]['remoteUser'],
            $properties[TargetEnum::TYPE_RSYNC]['remoteServer'],
            $properties[TargetEnum::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['pre_push'],
            $properties[TargetEnum::TYPE_RSYNC]['environmentVariables']
        );
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function push(array $properties)
    {
        $this->status('Pushing code to server', self::SECTION);

        $pusher = $this->pusher;

        return $pusher(
            $properties['location']['path'],
            $properties[TargetEnum::TYPE_RSYNC]['remoteUser'],
            $properties[TargetEnum::TYPE_RSYNC]['remoteServer'],
            $properties[TargetEnum::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['exclude']
        );
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function postpush(array $properties)
    {
        if (!$properties['configuration']['post_push']) {
            $this->status('Skipping post-push command', self::SECTION);
            return true;
        }

        $this->status('Running post-push command', self::SECTION);

        $postpush = $this->serverCommand;
        return $postpush(
            $properties[TargetEnum::TYPE_RSYNC]['remoteUser'],
            $properties[TargetEnum::TYPE_RSYNC]['remoteServer'],
            $properties[TargetEnum::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['post_push'],
            $properties[TargetEnum::TYPE_RSYNC]['environmentVariables']
        );
    }
}
