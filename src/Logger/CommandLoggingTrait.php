<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Handler\BufferHandler as MonologBufferHandler;
use Psr\Log\LoggerInterface;

trait CommandLoggingTrait
{
    /**
     * @type LoggerInterface
     */
    private $logger;

    /**
     * @type MonologBufferHandler
     */
    private $loggerBuffer;

    /**
     * @type Resolver
     */
    private $loggerResolver;

    /**
     * Logging levels from syslog protocol defined in RFC 5424
     *
     * @var array $levels
     */
    protected static $loggerLevels = [
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
        'alert',
        'emergency'
    ];

    /**
     * @param LoggerInterface $logger
     * @param MonologBufferHandler $buffer
     * @param Resolver $resolver
     * @return null
     */
    public function addCommandLogging(LoggerInterface $logger, MonologBufferHandler $buffer, Resolver $resolver)
    {
        $this->logger = $logger;
        $this->loggerBuffer = $buffer;
        $this->loggerResolver = $resolver;
    }

    /**
     * @param callable $exposiveBolts
     * @return null
     */
    private function inCaseOfEmergency(callable $exposiveBolts)
    {
        if ($this->loggerBuffer === null) {
            return;
        }

        $this->loggerBuffer->setCommandOnFlush($exposiveBolts);
    }

    /**
     * Log a message, gracefully failing if no logger is set.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     */
    private function log($level, $message, array $context = [])
    {
        if ($this->logger === null) {
            return;
        }

        $levels = array_fill_keys(self::$loggerLevels, true);
        if (!isset($levels[$level])) {
            return;
        }

        $this->logger->$level($message, $context);
    }

    /**
     * Log a master message, and flush the buffer.
     *
     * Status must be 'success' or 'failure'
     *
     * @param string $status
     * @param array $context
     */
    private function logAndFlush($status, array $context = [])
    {
        if ($this->logger === null) {
            return;
        }

        $entity = isset($context['build']) ? $context['build'] : (isset($context['push']) ? $context['push'] : null);
        $context = $this->loggerResolver->resolveProperties($entity, $context);

        $context['master'] = true;
        $context['email']['isHighPriority'] = ($status !== 'success');

        $level = ($status === 'success') ? 'info' : 'critical';
        $this->logger->$level($context['email']['subject'], $context);

        // flush log buffer
        $this->loggerBuffer->flush();
    }
}
