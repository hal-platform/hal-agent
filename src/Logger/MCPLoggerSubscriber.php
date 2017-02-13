<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use QL\MCP\Common\IPv4Address;
use QL\MCP\Logger\MessageInterface;
use QL\MCP\Logger\MessageFactoryInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class MCPLoggerSubscriber implements EventSubscriberInterface
{
    /**
     * @var MessageFactoryInterface
     */
    private $factory;

    /**
     * @param MessageFactoryInterface $factory
     */
    public function __construct(MessageFactoryInterface $factory)
    {
        $this->factory = $factory;
    }

    /**
     * @param ConsoleEvent $event
     * @param string $eventName
     * @return null
     */
    public function addProperties(ConsoleEvent $event, $eventName)
    {
        $host = gethostname();
        $this->factory->setDefaultProperty(MessageInterface::SERVER_HOSTNAME, $host);

        $ip = gethostbyname($host);
        if ($serverIP = IPv4Address::create($ip)) {
            $this->factory->setDefaultProperty(MessageInterface::SERVER_IP, $serverIP);
        }
    }

    /**
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            ConsoleEvents::COMMAND => 'addProperties'
        ];
    }
}
