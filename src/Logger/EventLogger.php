<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\Release;
use Hal\Core\Type\JobEventStatusEnum;
use Hal\Core\Type\JobStatusEnum;
use QL\MCP\Common\Time\Clock;

/**
 * Handles starting and finishing jobs - e.g. Changing the status of a build or push.
 *
 * Event logs are automatically flushed and persisted when this logger saves the job.
 */
class EventLogger
{
    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var EventFactory
     */
    private $factory;

    /**
     * @var ProcessHandler
     */
    private $processHandler;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var Build|Release|null
     */
    private $entity;

    /**
     * @param EntityManagerInterface $em
     * @param EventFactory $factory
     * @param ProcessHandler $processHandler
     * @param Clock $clock
     */
    public function __construct(
        EntityManagerInterface $em,
        EventFactory $factory,
        ProcessHandler $processHandler,
        Clock $clock
    ) {
        $this->em = $em;
        $this->factory = $factory;
        $this->processHandler = $processHandler;
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
        if (!JobEventStatusEnum::isValid($status)) {
            // error?
            return;
        }

        $this->factory->$status($message, $context);
    }

    /**
     * @param Build|Release $job
     *
     * @return null
     */
    public function start($job)
    {
        if ($job instanceof Build) {
            $this->startBuild($job);

        } elseif ($job instanceof Release) {
            $this->startRelease($job);
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
            $this->entity->withStatus(JobStatusEnum::TYPE_FAILURE);
            $this->entity->withEnd($this->clock->read());
            $this->em->merge($this->entity);

            $this->setStage('failure');

            $this->processHandler->abort($this->entity);
        }

        $this->em->flush();
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
            $this->entity->withStatus(JobStatusEnum::TYPE_SUCCESS);
            $this->entity->withEnd($this->clock->read());
            $this->em->merge($this->entity);

            $this->setStage('success');

            $this->processHandler->launch($this->entity);
        }

        $this->em->flush();
    }

    /**
     * @return bool
     */
    private function isInProgress()
    {
        if (!$this->entity) {
            return false;
        }

        if ($this->entity->status() === JobStatusEnum::TYPE_RUNNING) {
            return true;
        }

        return false;
    }

    /**
     * @param string $stage
     *
     * @return string|null
     */
    private function normalizeStage($stage)
    {
        if (substr($stage, 0, 6) === 'build.' || substr($stage, 0, 8) === 'release.') {
            return $stage;

        } elseif ($this->entity) {
            $prefix = ($this->entity instanceof Build) ? 'build' : 'release';

            return sprintf('%s.%s', $prefix, $stage);
        }

        return null;
    }

    /**
     * @param Build $build
     *
     * @return void
     */
    private function startBuild(Build $build)
    {
        $this->entity = $build;
        $this->entity->withStatus(JobStatusEnum::TYPE_RUNNING);
        $this->entity->withStart($this->clock->read());

        $this->factory->setBuild($build);

        // immediately merge and flush, so frontend picks up changes
        $this->em->merge($build);
        $this->em->flush();
    }

    /**
     * @param Release $release
     *
     * @return void
     */
    private function startRelease(Release $release)
    {
        $this->entity = $release;
        $this->entity->withStatus(JobStatusEnum::TYPE_RUNNING);
        $this->entity->withStart($this->clock->read());

        $this->factory->setRelease($release);

        // immediately merge and flush, so frontend picks up changes
        $this->em->merge($release);
        $this->em->flush();
    }
}
