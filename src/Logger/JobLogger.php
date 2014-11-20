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
use QL\Hal\Core\Entity\Type\EventStatusEnumType;

class JobLogger
{
    /**
     * @type EventLogger
     */
    private $eventLogger;

    /**
     * @type EntityManager
     */
    private $entityManager;

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
     * @param EventLogger $eventLogger
     * @param Clock $clock
     */
    public function __construct(EntityManager $entityManager, EventLogger $eventLogger, Clock $clock)
    {
        $this->entityManager = $entityManager;
        $this->eventLogger = $eventLogger;
        $this->clock = $clock;
    }

    /**
     * @param string $stage
     *
     * @return null
     */
    public function setStage($stage)
    {
        $this->eventLogger->setStage($stage);
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
        $statuses = EventStatusEnumType::values();
        if (!in_array($status, $statuses)) {
            // error?
            return;
        }

        $this->eventLogger->$status($message, $context);
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
            $this->entity->setStatus('Error');
            $this->entity->setEnd($this->clock->read());
            $this->entityManager->merge($this->entity);

            // Trigger email
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
            $this->entity->setStatus('Success');
            $this->entity->setEnd($this->clock->read());
            $this->entityManager->merge($this->entity);

            // Trigger email
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

        if ($this->entity instanceof Build && $this->entity->getStatus() === 'Building') {
            return true;
        }


        if ($this->entity instanceof Push && $this->entity->getStatus() === 'Pushing') {
            return true;
        }

        return false;
    }

    /**
     * @param Build $build
     *
     * @return null
     */
    private function startBuild(Build $build)
    {
        $this->entity = $build;
        $this->entity->setStatus('Building');
        $this->entity->setStart($this->clock->read());

        $this->eventLogger->setBuild($build);
    }

    /**
     * @param Push $push
     *
     * @return null
     */
    private function startPush(Push $push)
    {
        $this->entity = $push;
        $this->entity->setStatus('Pushing');
        $this->entity->setStart($this->clock->read());

        $this->eventLogger->setPush($push);
    }
}
