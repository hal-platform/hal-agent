<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use MCP\DataType\Time\Clock;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Type\EnumType\EventStatusEnum;

/**
 * Handles starting and finishing jobs - e.g. Changing the status of a build or push.
 *
 * Event logs are automatically flushed and persisted when this logger saves the job.
 */
class EventLogger
{
    /**
     * @type EntityManager
     */
    private $entityManager;

    /**
     * @type EventFactory
     */
    private $factory;

    /**
     * @type Notifier
     */
    private $notifier;

    /**
     * @type Clock
     */
    private $clock;

    /**
     * @type Build|Push
     */
    private $entity;

    /**
     * @param EntityManager $entityManager
     * @param EventFactory $factory
     * @param Notifier $notifier
     * @param Clock $clock
     */
    public function __construct(EntityManager $entityManager, EventFactory $factory, Notifier $notifier, Clock $clock)
    {
        $this->entityManager = $entityManager;
        $this->factory = $factory;
        $this->notifier = $notifier;
        $this->clock = $clock;
    }

    /**
     * @param string $stage
     *
     * @return null
     */
    public function setStage($stage)
    {
        if (!$normalized = $this->normalizeStage($stage)) {
            return;
        }

        $this->factory->setStage($normalized);
        $this->notifier->sendNotifications($normalized, $this->entity);
    }

    /**
     * @param string $stage
     * @param string $service
     *
     * @return null
     */
    public function addSubscription($stage, $service)
    {
        if (!$normalized = $this->normalizeStage($stage)) {
            return;
        }

        $this->notifier->addSubscription($normalized, $service);
    }

    /**
     * @param string $status
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function event($status, $message = '', array $context = [])
    {
        $statuses = EventStatusEnum::values();
        if (!in_array($status, $statuses)) {
            // error?
            return;
        }

        $this->factory->$status($message, $context);
    }

    /**
     * @param string $key
     * @param mixed $value
     *
     * @return null
     */
    public function keep($key, $value)
    {
        $this->notifier->keep($key, $value);
    }

    /**
     * @param Build|Push $job
     *
     * @return null
     */
    public function start($job)
    {
        if ($job instanceof Build) {
            $this->startBuild($job);

        } elseif ($job instanceof Push) {
            $this->startPush($job);
        }

        if ($this->entity) {
            // immediately merge and flush
            $this->entityManager->merge($this->entity);
            $this->entityManager->flush();
        }
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function failure()
    {
        if ($this->isInProgress()) {
            $this->entity->withStatus('Error');
            $this->entity->withEnd($this->clock->read());
            $this->entityManager->merge($this->entity);

            $this->setStage('failure');
        }

        $this->entityManager->flush();
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function success()
    {
        if ($this->isInProgress()) {
            $this->entity->withStatus('Success');
            $this->entity->withEnd($this->clock->read());
            $this->entityManager->merge($this->entity);

            $this->setStage('success');
        }

        $this->entityManager->flush();
    }

    /**
     * @return bool
     */
    private function isInProgress()
    {
        if (!$this->entity) {
            return false;
        }

        if ($this->entity instanceof Build && $this->entity->status() === 'Building') {
            return true;
        }


        if ($this->entity instanceof Push && $this->entity->status() === 'Pushing') {
            return true;
        }

        return false;
    }

    /**
     * @param string $stage
     * @return string|null
     */
    private function normalizeStage($stage)
    {
        if (substr($stage, 0, 6) === 'build.' || substr($stage, 0, 5) === 'push.') {
            return $stage;

        } else if ($this->entity) {
            $prefix = ($this->entity instanceof Build) ? 'build' : 'push';
            return sprintf('%s.%s', $prefix, $stage);
        }

        return null;
    }

    /**
     * @param Build $build
     *
     * @return null
     */
    private function startBuild(Build $build)
    {
        $this->entity = $build;
        $this->entity->withStatus('Building');
        $this->entity->withStart($this->clock->read());

        $this->factory->setBuild($build);
    }

    /**
     * @param Push $push
     *
     * @return null
     */
    private function startPush(Push $push)
    {
        $this->entity = $push;
        $this->entity->withStatus('Pushing');
        $this->entity->withStart($this->clock->read());

        $this->factory->setPush($push);
    }
}
