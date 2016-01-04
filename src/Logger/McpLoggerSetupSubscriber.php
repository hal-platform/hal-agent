<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Logger;

use MCP\DataType\IPv4Address;
use MCP\Logger\Adapter\Psr\MessageFactory;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class McpLoggerSetupSubscriber implements EventSubscriberInterface
{
    /**
     * @var MessageFactory
     */
    private $factory;

    /**
     * @param MessageFactory $factory
     */
    public function __construct(MessageFactory $factory)
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
        $this->factory->setDefaultProperty('machineName', $host);

        $ip = gethostbyname($host);
        if ($serverIp = IPv4Address::create($ip)) {
            $this->factory->setDefaultProperty('machineIPAddress', $serverIp);
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
