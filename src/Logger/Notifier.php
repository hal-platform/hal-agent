<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use QL\Hal\Agent\Notifier\NotifierInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Type\EnumType\EventEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;

class Notifier
{
    /**
     * @var ContainerInterface
     */
    private $di;

    /**
     * @var array
     */
    private $subscriptions;

    /**
     * @var array
     */
    private $data;

    /**
     * @param ContainerInterface $di
     */
    public function __construct(ContainerInterface $di)
    {
        $this->di = $di;

        $this->subscriptions = array_fill_keys(EventEnum::values(), []);
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

        if (!$entity instanceof Push) {
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
     * @param Push $entity
     * @return array
     */
    private function prepareData($event, Push $entity)
    {
        $status = null;
        if ($entity->status() === 'Success') {
            $status = true;

        } elseif ($entity->status() === 'Error') {
            $status = false;
        }

        $build = $entity->build();
        $push = $entity;
        $deployment = $push->deployment();
        $server = $deployment->server();

        $application = $build->application();
        $env = $build->environment();

        return array_merge($this->data, [
            'event' => $event,
            'status' => $status, // null, true, false
            'build' => $build,
            'push' => $push,
            'application' => $application,
            'environment' => $env,
            'deployment' => $deployment,
            'server' => $server
        ]);
    }
}
