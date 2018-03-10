<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\Job\JobEvent;
use Hal\Core\Type\JobEventStageEnum;
use Hal\Core\Type\JobEventStatusEnum;
use Hal\Core\Type\JobStatusEnum;
use JsonSerializable;
use QL\MCP\Common\Time\Clock;

/**
 * Handles starting and finishing jobs - e.g. Changing the status of a build or release.
 *
 * Events are automatically flushed and persisted when this logger saves the job.
 */
class EventLogger
{
    private const MAX_DATA_SIZE_KB = 500;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var ProcessHandler
     */
    private $processHandler;

    /**
     * @var MetadataHandler
     */
    private $metaHandler;

    /**
     * @var Clock
     */
    private $clock;

    /**
     * @var Job|null
     */
    private $job;

    /**
     * @var array
     */
    private $events;

    /**
     * @var string
     */
    private $currentStage;

    /**
     * @param EntityManagerInterface $em
     * @param ProcessHandler $processHandler
     * @param MetadataHandler $metaHandler
     * @param Clock $clock
     */
    public function __construct(
        EntityManagerInterface $em,
        ProcessHandler $processHandler,
        MetadataHandler $metaHandler,
        Clock $clock
    ) {
        $this->em = $em;
        $this->processHandler = $processHandler;
        $this->metaHandler = $metaHandler;
        $this->clock = $clock;

        $this->job = null;
        $this->events = [];

        $this->currentStage = JobEventStageEnum::defaultOption();
    }

    /**
     * @param string $stage
     *
     * @return void
     */
    public function setStage($stage): void
    {
        if (!JobEventStageEnum::isValid($stage)) {
            return;
        }

        $this->currentStage = $stage;
    }

    /**
     * @param string $status
     * @param string $message
     * @param array $context
     *
     * @return JobEvent|null
     */
    public function event($status, string $message, array $context = []): ?JobEvent
    {
        if (!$this->job) {
            return null;
        }

        if (!JobEventStatusEnum::isValid($status)) {
            return null;
        }

        return $this->sendEvent($this->job, $this->currentStage, $status, $message, $context);
    }

    /**
     * @param string $name
     * @param string $value
     *
     * @return void
     */
    public function meta(string $name, string $value): void
    {
        if (!$this->job) {
            return;
        }

        // $this->metaHandler->send($this->job, $name, $value);
    }

    /**
     * @param Job $job
     *
     * @return void
     */
    public function start(Job $job): void
    {
        $this->job = $job;

        $this->setStage(JobEventStageEnum::TYPE_STARTING);

        $this->job->withStatus(JobStatusEnum::TYPE_RUNNING);
        $this->job->withStart($this->clock->read());

        // immediately merge and flush, so frontend picks up changes
        $this->em->merge($job);
        $this->em->flush();
    }

    /**
     * @return void
     */
    public function failure(): void
    {
        if ($this->job && $this->job->inProgress()) {
            $this->job->withStatus(JobStatusEnum::TYPE_FAILURE);
            $this->job->withEnd($this->clock->read());
            $this->em->merge($this->job);

            $this->setStage(JobEventStageEnum::TYPE_FAILURE);

            $this->processHandler->abort($this->job);
        }

        $this->em->flush();
    }

    /**
     * @return void
     */
    public function success(): void
    {
        if ($this->job && $this->job->inProgress()) {
            $this->job->withStatus(JobStatusEnum::TYPE_SUCCESS);
            $this->job->withEnd($this->clock->read());
            $this->em->merge($this->job);

            $this->setStage(JobEventStageEnum::TYPE_SUCCESS);

            $this->processHandler->launch($this->job);
        }

        $this->em->flush();
    }

    /**
     * @param Job $job
     * @param string $stage
     * @param string $status
     * @param string $message
     * @param array $context
     *
     * @return JobEvent
     */
    private function sendEvent(Job $job, $stage, $status, $message, array $context = []): JobEvent
    {
        $count = count($this->events) + 1;

        $event = (new JobEvent)
            ->withStage($stage)
            ->withStatus($status)
            ->withOrder($count)
            ->withMessage($message)
            ->withJob($job);

        $context = $this->sanitizeContext($context);
        if ($context) {
            $event->withParameters($context);
        }

        // persist
        $this->events[] = $event;
        $this->em->persist($event);
        $this->em->flush();

        // @todo replace this with API call to fe
        // $this->trySendingToRedis($event);

        return $event;
    }

    /**
     * @param array $context
     *
     * @return array
     */
    private function sanitizeContext(array $context)
    {
        $sanitized = [];

        foreach ($context as $oldKey => $data) {
            $key = $this->deCamelCase($oldKey);
            $data = $this->sanitizeString($data);

            if ($data) {
                $sanitized[$key] = $data;
            }
        }

        return $sanitized;
    }

    /**
     * @param string $key
     *
     * @return string
     */
    private function deCamelCase($key)
    {
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        $key = ucfirst($key);

        return $key;
    }

    /**
     * @param mixed $data
     *
     * @return string
     */
    private function sanitizeString($data)
    {
        if (is_object($data)) {
            if (method_exists($data, '__toString')) {
                $data = (string) $data;

            } elseif ($data instanceof JsonSerializable) {
                $data = $data->jsonSerialize();
            } else {
                $data = '';
            }
        } elseif (!is_array($data)) {
            $data = (string) $data;
        }

        // must be array or string at this point

        if (is_string($data) && strlen($data) === 0) {
            return '';
        }

        if (is_array($data) && count($data) === 0) {
            return '';
        }

        if (is_array($data)) {
            $data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $maxBytes = self::MAX_DATA_SIZE_KB * 1000;
        if (strlen($data) > $maxBytes) {
            $data = substr($data, 0, $maxBytes);
        }

        return $data;
    }
}
