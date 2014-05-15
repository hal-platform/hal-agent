<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Handler\BufferHandler as BaseBufferHandler;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;

/**
 * A simple proxy for the real logger that can be triggered to flush all messages
 */
class CommandLogger extends AbstractLogger
{
    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BaseBufferHandler
     */
    private $buffer;

    /**
     * @param LoggerInterface $logger
     * @param BaseBufferHandler $buffer
     */
    public function __construct(LoggerInterface $logger, BaseBufferHandler $buffer)
    {
        $this->logger = $logger;
        $this->buffer = $buffer;
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = [])
    {
        $this->logger->$level($message, $context);
    }

    /**
     * Providing an invalid or nonexistent entity will silently fail.
     *
     * @param Build|Push $entity
     * @param array $context
     * @return null
     */
    public function success($entity, array $context = [])
    {
        if ($entity instanceof Build) {
            return $this->flush('info', $this->formatBuildSubject($entity), $context);
        }

        if ($entity instanceof Push) {
            return $this->flush('info', $this->formatPushSubject($entity), $context);
        }
    }

    /**
     * Providing an invalid or nonexistent entity will silently fail.
     *
     * @param Build|Push $entity
     * @param array $context
     * @return null
     */
    public function failure($entity, array $context = [])
    {
        if ($entity instanceof Build) {
            return $this->flush('error', $this->formatBuildSubject($entity), $context);
        }

        if ($entity instanceof Push) {
            return $this->flush('error', $this->formatPushSubject($entity), $context);
        }
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     * @return null
     */
    private function flush($level, $message, array $context)
    {
        $status = ($level === 'info') ? 'Success' : 'Failure';
        $messageWithStatus = sprintf('%s - %s', $message, $status);

        $this->logger->$level($messageWithStatus, $context);
        $this->buffer->flush();
    }

    /**
     * @param Build $build
     * @return string
     */
    private function formatBuildSubject(Build $build)
    {
        $repositoryName = $build->getRepository()->getKey();
        $environmentName = $build->getEnvironment()->getKey();

        return sprintf(
            '%s (%s) - Build',
            $repositoryName,
            $environmentName
        );
    }

    /**
     * @param Push $push
     * @return string
     */
    private function formatPushSubject(Push $push)
    {
        $deployment = $this->push->getDeployment();
        $server = $deployment->getServer();

        return sprintf(
            '%s (%s:%s) - Push',
            $deployment->getRepository()->getKey(),
            $server->getEnvironment()->getKey(),
            $server->getName()
        );
    }
}
