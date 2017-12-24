<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use Hal\Core\Entity\Build;
use Hal\Core\Entity\JobEvent;
use Hal\Core\Entity\Release;
use Hal\Core\Type\JobEventStageEnum;
use JsonSerializable;
use Predis\Client as Predis;

/**
 * This makes attaching logs to events very simple.
 *
 * BUILD EVENTS:
 *
 * - created
 *     * worker is trigger
 *         This log is not currently recorded.
 *
 * - start
 *     * resolve
 *     * download
 *     * unpack
 *
 * - building
 *     * build command
 *
 * - end
 *     * pack
 *
 * - success|failure
 *
 *
 * PUSH EVENTS:
 *
 * - created
 *     * worker is trigger
 *         This log is not currently recorded.
 *
 * - start
 *     * resolve
 *     * unpack
 *
 * - pushing
 *     * build
 *     * prepush
 *     * push
 *     * postpush
 *
 * - end
 *
 * - success|failure
 *
 */
class EventFactory
{
    const REDIS_LOG_KEY = 'event-logs:%s';
    const REDIS_LOG_EXPIRY = 3600;

    /**
     * @var string
     */
    private $currentStage;

    /**
     * @var Build|Release
     */
    private $entity;

    /**
     * @var EntityManagerInterface
     */
    private $em;

    /**
     * @var Predis|null
     */
    private $predis;

    /**
     * @var JobEvent[]
     */
    private $logs;

    /**
     * @param EntityManagerInterface $em
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
        $this->predis = null;

        $this->currentStage = JobEventStageEnum::defaultOption();
        $this->logs = [];
    }

    /**
     * If set, log meta data (not context) will be sent to redis INSTANTLY instead of waiting for logs to be flushed.
     *
     * @param Predis $predis
     *
     * @return void
     */
    public function setRedisHandler(Predis $predis)
    {
        $this->predis = $predis;
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function failure($message = '', array $context = [])
    {
        return $this->log('failure', $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function info($message = '', array $context = [])
    {
        return $this->log('info', $message, $context);
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    public function success($message = '', array $context = [])
    {
        return $this->log('success', $message, $context);
    }

    /**
     * @param Build $build
     *
     * @return null
     */
    public function setBuild(Build $build)
    {
        $this->entity = $build;
    }

    /**
     * @param Release $release
     *
     * @return null
     */
    public function setRelease(Release $release)
    {
        $this->entity = $release;
    }

    /**
     * @param string $stage
     *
     * @return null
     */
    public function setStage($stage)
    {
        if (!JobEventStageEnum::isValid($stage)) {
            // error?
            return;
        }

        $this->currentStage = $stage;
    }

    /**
     * @param string $type
     * @param string $message
     * @param array $context
     *
     * @return null
     */
    private function log($type, $message = '', array $context = [])
    {
        $count = count($this->logs) + 1;

        $log = (new JobEvent())
            ->withStatus($type)
            ->withStage($this->currentStage)
            ->withOrder($count);

        if ($message) {
            $log->withMessage($message);
        }

        $context = $this->sanitizeContext($context);
        if ($context) {
            $log->withParameters($context);
        }

        if ($this->entity instanceof Build) {
            $log->withParentID($this->entity->id());
        } elseif ($this->entity instanceof Release) {
            $log->withParentID($this->entity->id());
        }

        // persist
        $this->logs[] = $log;
        $this->em->persist($log);

        $this->trySendingToRedis($log);

        // flush?
    }

    /**
     * @param JobEvent $log
     *
     * @return void
     */
    private function trySendingToRedis(JobEvent $log)
    {
        if ($this->predis === null) {
            return;
        }

        $parent = $log->parentID();

        if (!$parent) {
            return;
        }

        $key = sprintf(self::REDIS_LOG_KEY, $parent);

        // encode the eventlog (this will skip data)
        $data = json_encode($log);

        // push onto list
        $this->predis->lpush($key, $data);
        $this->predis->expire($key, self::REDIS_LOG_EXPIRY);
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

            if (is_object($data)) {
                if (method_exists($data, '__toString')) {
                    $data = (string)$data;

                } elseif ($data instanceof JsonSerializable) {
                    $data = $data->jsonSerialize();
                } else {
                    $data = '';
                }
            } elseif (!is_array($data)) {
                $data = (string)$data;
            }

            // must be array or string at this point

            if (is_string($data)) {
                if (strlen($data) > 0) {
                    $sanitized[$key] = $data;
                }
            } else {
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
}
