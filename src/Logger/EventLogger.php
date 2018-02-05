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
use QL\MCP\Common\Time\Clock;

/**
 * Handles starting and finishing jobs - e.g. Changing the status of a build or release.
 *
 * Event logs are automatically flushed and persisted when this logger saves the job.
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
    private $logs;

    /**
     * @var string
     */
    private $currentStage;

    /**
     * @param EntityManagerInterface $em
     * @param ProcessHandler $processHandler
     * @param Clock $clock
     */
    public function __construct(
        EntityManagerInterface $em,
        ProcessHandler $processHandler,
        Clock $clock
    ) {
        $this->em = $em;
        $this->processHandler = $processHandler;
        $this->clock = $clock;

        $this->job = null;
        $this->logs = [];

        $this->currentStage = JobEventStageEnum::defaultOption();
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

        if (!JobEventStageEnum::isValid($normalized)) {
            return;
        }

        $this->currentStage = $normalized;
    }

    /**
     * @param string $status
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function event($status, $message, array $context = [])
    {
        if (!$this->job) {
            return;
        }

        if (!JobEventStatusEnum::isValid($status)) {
            return;
        }

        $this->sendEvent($this->job, $this->currentStage, $status, $message, $context);
    }

    /**
     * @param Job $job
     *
     * @return void
     */
    public function start(Job $job)
    {
        $this->job = $job;

        $this->job->withStatus(JobStatusEnum::TYPE_RUNNING);
        $this->job->withStart($this->clock->read());

        // immediately merge and flush, so frontend picks up changes
        $this->em->merge($job);
        $this->em->flush();
    }

    /**
     * @return null
     */
    public function failure()
    {
        if ($this->job && $this->job->inProgress()) {
            $this->job->withStatus(JobStatusEnum::TYPE_FAILURE);
            $this->job->withEnd($this->clock->read());
            $this->em->merge($this->job);

            $this->setStage('failure');

            $this->processHandler->abort($this->job);
        }

        $this->em->flush();
    }

    /**
     * @return void
     */
    public function success()
    {
        if ($this->job && $this->job->inProgress()) {
            $this->job->withStatus(JobStatusEnum::TYPE_SUCCESS);
            $this->job->withEnd($this->clock->read());
            $this->em->merge($this->job);

            $this->setStage('success');

            $this->processHandler->launch($this->job);
        }

        $this->em->flush();
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

        } elseif ($this->job) {
            $prefix = (!$this->job instanceof Build) ? 'release' : 'build';

            return sprintf('%s.%s', $prefix, $stage);
        }

        return null;
    }

    /**
     * @param Job $job
     * @param string $stage
     * @param string $status
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    private function sendEvent(Job $job, $stage, $status, $message, array $context = [])
    {
        $count = count($this->logs) + 1;

        $log = (new JobEvent)
            ->withStage($stage)
            ->withStatus($status)
            ->withOrder($count)
            ->withMessage($message)
            ->withJob($job);

        $context = $this->sanitizeContext($context);
        if ($context) {
            $log->withParameters($context);
        }

        // persist
        $this->logs[] = $log;
        $this->em->persist($log);
        $this->em->flush();

        // @todo replace this with API call to fe
        // $this->trySendingToRedis($log);
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
            $sanitized[$key] = $data;
            $data = substr($data, 0, $maxBytes);
        }

        return $data;
    }
}
