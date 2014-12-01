<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use JsonSerializable;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\EventLog;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Type\EventEnumType;

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
    /**
     * @type string
     */
    private $currentStage;

    /**
     * @type Build|Push
     */
    private $entity;

    /**
     * @type EntityManager
     */
    private $entityManager;

    /**
     * @type EventLog[]
     */
    private $logs;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;

        $this->currentStage = 'unknown';
        $this->logs = [];
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
        if (!in_array($stage, EventEnumType::values())) {
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

        $log = new EventLog;
        $log->setStatus($type);
        $log->setEvent($this->currentStage);
        $log->setOrder($count);

        if ($message) {
            $log->setMessage($message);
        }

        $context = $this->sanitizeContext($context);
        if ($context) {
            $log->setData($context);
        }

        if ($this->entity instanceof Build) {
            $log->setBuild($this->entity);

        } elseif ($this->entity instanceof Push) {
            $log->setPush($this->entity);
        }

        // persist
        $this->logs[] = $log;
        $this->entityManager->persist($log);

        // flush?
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
                    $sanitized[$key] = (string) $data;

                } elseif ($data instanceof JsonSerializable) {
                    $sanitized[$key] = $data->jsonSerialize();

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
