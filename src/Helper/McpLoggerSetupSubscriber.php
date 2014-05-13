<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Helper;

use MCP\DataType\IPv4Address;
use MCP\Service\Logger\Adapter\Psr\MessageFactory;
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
