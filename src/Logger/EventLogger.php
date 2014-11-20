<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Doctrine\ORM\EntityManager;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\EventLog;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Type\EventEnumType;

class EventLogger
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
     * @type int
     */
    private $count;

    /**
     * @param EntityManager $entityManager
     */
    public function __construct(EntityManager $entityManager)
    {
        $this->entityManager = $entityManager;

        $this->currentStage = 'unknown';
        $this->count = 0;
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
        $stages = EventEnumType::values();
        if (!in_array($stage, $stages)) {
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
        $log = new EventLog;
        $log->setStatus($type);
        $log->setEvent($this->currentStage);
        $log->setOrder(++$this->count);

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
        foreach ($context as $key => $data) {
            if (is_object($data)) {
                if (method_exists($data, '__toString')) {
                    $context[$key] = (string) $data;
                } else {
                    unset($context[$key]);
                }
            }
        }

        return $context;
    }
}
