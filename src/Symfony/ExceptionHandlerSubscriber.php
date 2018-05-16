<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Symfony;

use Psr\Log\LoggerInterface;
use Hal\Agent\Utility\StacktraceFormatterTrait;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleErrorEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ExceptionHandlerSubscriber implements EventSubscriberInterface
{
    use StacktraceFormatterTrait;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param LoggerInterface $logger
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param ConsoleErrorEvent $event
     * @param string $eventName
     * @return null
     */
    public function handleException(ConsoleErrorEvent $event, $eventName)
    {
        $exception = $event->getError();

        $context = [
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'exceptionData' => $this->formatExceptionStacktrace($exception)
        ];

        $this->logger->critical($exception->getMessage(), $context);
    }

    /**
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return [
            ConsoleEvents::ERROR => 'handleException'
        ];
    }
}
