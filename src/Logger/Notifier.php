<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use QL\Hal\Agent\Notifier\NotifierInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Type\EventEnumType;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Notifier
{
    /**
     * @type ContainerInterface
     */
    private $di;

    /**
     * @type array
     */
    private $subscriptions;

    /**
     * @type array
     */
    private $data;

    /**
     * @param ContainerInterface $di
     */
    public function __construct(ContainerInterface $di)
    {
        $this->di = $di;

        $this->subscriptions = array_fill_keys(EventEnumType::values(), []);
        $this->data = [];
    }

    /**
     * Attach a notification service to an event.
     *
     * @param string $event
     * @param string $service
     *
     * @return null
     */
    public function addSubscription($event, $service)
    {
        if (!array_key_exists($event, $this->subscriptions)) {
            return;
        }

        $this->subscriptions[$event][] = $service;
    }

    /**
     * Send notifications for an event.
     *
     * @param string $event
     * @param Build|Push|null $entity
     *
     * @return null
     */
    public function sendNotifications($event, $entity)
    {
        if (!array_key_exists($event, $this->subscriptions)) {
            return;
        }

        if (!$subscriptions = $this->subscriptions[$event]) {
            return;
        }

        if (!$entity instanceof Build && !$entity instanceof Push) {
            return;
        }

        foreach ($subscriptions as $service) {
            // Skip undefined services
            if (!$this->di->has($service)) continue;

            $notifier = $this->di->get($service, ContainerInterface::NULL_ON_INVALID_REFERENCE);

            // Skip invalid services
            if (!$notifier instanceof NotifierInterface) continue;

            $notifier->send($event, $this->prepareData($event, $entity));
        }
    }

    /**
     * Keep data and send it along with messages to the notifiers.
     *
     * @param string $key
     * @param mixed $data
     *
     * @return null
     */
    public function keep($key, $data)
    {
        if (isset($this->data[$key]) && is_array($data) && is_array($this->data[$key])) {
            $this->data[$key] = array_merge_recursive($this->data[$key], $data);
        } else {
            $this->data[$key] = $data;
        }
    }

    /**
     * @param string $event
     * @param Build|Push $entity
     * @return array
     */
    private function prepareData($event, $entity)
    {
        $status = null;
        if ($entity->getStatus() === 'Success') {
            $status = true;

        } elseif ($entity->getStatus() === 'Error') {
            $status = false;
        }

        if ($entity instanceof Push) {
            $build = $entity->getBuild();
            $push = $entity;
            $server = $push->getDeployment()->getServer();
        } else {
            $build = $entity;
            $push = null;
            $server = null;
        }

        $repo = $build->getRepository();
        $env = $build->getEnvironment();

        return array_merge($this->data, [
            'event' => $event,
            'status' => $status, // null, true, false
            'build' => $build,
            'push' => $push,
            'repository' => $repo,
            'environment' => $env,
            'server' => $server
        ]);
    }
}
