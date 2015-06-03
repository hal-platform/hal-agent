<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManagerInterface;
use JsonSerializable;
use Predis\Client as Predis;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\EventLog;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Type\EnumType\EventEnum;

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
     * @type string
     */
    private $currentStage;

    /**
     * @type Build|Push
     */
    private $entity;

    /**
     * @type EntityManagerInterface
     */
    private $em;

    /**
     * @type callable
     */
    private $random;

    /**
     * @type Predis|null
     */
    private $predis;

    /**
     * @type EventLog[]
     */
    private $logs;

    /**
     * @param EntityManagerInterface $em
     * @param callable $random
     */
    public function __construct(EntityManagerInterface $em, callable $random)
    {
        $this->em = $em;
        $this->random = $random;
        $this->predis = null;

        $this->currentStage = 'unknown';
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
     * @param Push $push
     *
     * @return null
     */
    public function setPush(Push $push)
    {
        $this->entity = $push;
    }

    /**
     * @param string $stage
     *
     * @return null
     */
    public function setStage($stage)
    {
        if (!in_array($stage, EventEnum::values())) {
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

        $id = call_user_func($this->random);
        $log = (new EventLog($id))
            ->withStatus($type)
            ->withEvent($this->currentStage)
            ->withOrder($count);

        if ($message) {
            $log->withMessage($message);
        }

        $context = $this->sanitizeContext($context);
        if ($context) {
            $log->withData($context);
        }

        if ($this->entity instanceof Build) {
            $log->withBuild($this->entity);

        } elseif ($this->entity instanceof Push) {
            $log->withPush($this->entity);
        }

        // persist
        $this->logs[] = $log;
        $this->em->persist($log);

        $this->trySendingToRedis($log);

        // flush?
    }

    /**
     * @param EventLog $log
     *
     * @return void
     */
    private function trySendingToRedis(EventLog $log)
    {
        if ($this->predis === null) {
            return;
        }

        $parent = null;
        if ($log->build()) $parent = $log->build()->id();
        if ($log->push()) $parent = $log->push()->id();

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
     * @return string
     */
    private function deCamelCase($key)
    {
        $key = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        $key = ucfirst($key);

        return $key;
    }
}
