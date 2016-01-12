<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\EnumType\ServerEnum;

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
     * @type Verify
     */
    private $verify;

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
        if (!isset($properties[ServerEnum::TYPE_RSYNC]) || !$this->verifyConfiguration($properties[ServerEnum::TYPE_RSYNC])) {
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
            $properties[ServerEnum::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnum::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnum::TYPE_RSYNC]['remotePath']
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
            $properties[ServerEnum::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnum::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnum::TYPE_RSYNC]['remotePath'],
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
            $properties[ServerEnum::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnum::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnum::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['pre_push'],
            $properties[ServerEnum::TYPE_RSYNC]['environmentVariables']
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
            $properties[ServerEnum::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnum::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnum::TYPE_RSYNC]['remotePath'],
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
            $properties[ServerEnum::TYPE_RSYNC]['remoteUser'],
            $properties[ServerEnum::TYPE_RSYNC]['remoteServer'],
            $properties[ServerEnum::TYPE_RSYNC]['remotePath'],
            $properties['configuration']['post_push'],
            $properties[ServerEnum::TYPE_RSYNC]['environmentVariables']
        );
    }
}
