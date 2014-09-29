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
use Swift_Message;

/**
 * A simple proxy for the real logger that can be triggered to flush all messages
 */
class CommandLogger extends AbstractLogger
{
    /**
     * @var string
     */
    const BUILD_MESSAGE = '{repository} ({environment})';
    const PUSH_MESSAGE = '{repository} ({environment}:{server})';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var BaseBufferHandler
     */
    private $buffer;

    /**
     * @var Swift_Message
     */
    private $message;

    /**
     * @param LoggerInterface $logger
     * @param BaseBufferHandler $buffer
     * @param Swift_Message $message
     */
    public function __construct(LoggerInterface $logger, BaseBufferHandler $buffer, Swift_Message $message, array $subjects = [])
    {
        $this->logger = $logger;
        $this->buffer = $buffer;
        $this->message = $message;
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
        $this->prepareMessage($entity);
        $context = $this->logProperties($entity, $context);

        if ($entity instanceof Build) {
            $message = $this->replaceTokens(self::BUILD_MESSAGE, $context);
            return $this->send($message, $context, true);
        }

        if ($entity instanceof Push) {
            $message = $this->replaceTokens(self::PUSH_MESSAGE, $context);
            return $this->send($message, $context, true);
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
        $this->prepareMessage($entity);
        $context = $this->logProperties($entity, $context);

        if ($entity instanceof Build) {
            $message = $this->replaceTokens(self::BUILD_MESSAGE, $context);
            return $this->send($message, $context, false);
        }

        if ($entity instanceof Push) {
            $message = $this->replaceTokens(self::PUSH_MESSAGE, $context);
            return $this->send($message, $context, false);
        }
    }

    /**
     * @param Build $build
     * @return array
     */
    private function buildProperties(Build $build)
    {
        $repository = $build->getRepository();
        $github = sprintf(
            '%s/%s:%s (%s)',
            $repository->getGithubUser(),
            $repository->getGithubRepo(),
            $build->getBranch(),
            $build->getCommit()
        );

        return [
            'buildId' => $build->getId(),
            'pushId' => '',
            'github' => $github,
            'repository' => $repository->getKey(),
            'server' => '',
            'environment' => $build->getEnvironment()->getKey()
        ];
    }

    /**
     * @param string $message
     * @param array $context
     * @param boolean $isSuccess
     * @return null
     */
    private function send($message, array $context, $isSuccess)
    {
        // prepare email message
        $this->message->setSubject($message);
        if (!$isSuccess) {
            $this->message->setPriority(1);
        }

        // send final log
        $level = ($isSuccess) ? 'info' : 'critical';
        $this->logger->$level($message, $context);

        // flush log buffer
        $this->buffer->flush();
    }

    /**
     * @param Build|Push $entity
     * @return array
     */
    private function logProperties($entity, array $context)
    {
        $props = [];

        if ($entity instanceof Push) {
            $props = $this->pushProperties($entity);

        } elseif ($entity instanceof Build) {
            $props = $this->buildProperties($entity);
        }

        return array_merge($context, $props);
    }

    /**
     * @param Push $push
     * @param boolean $isSuccess
     * @return array
     */
    private function pushProperties(Push $push)
    {
        $buildProperties = $this->buildProperties($push->getBuild());
        $server = $push->getDeployment()->getServer();

        $props = array_merge($buildProperties, [
            'pushId' => $push->getId(),
            'server' => $server->getName()
        ]);

        return $props;
    }

    /**
     * @param string $message
     * @param array $properties
     * @return string
     */
    private function replaceTokens($message, array $properties)
    {
        foreach ($properties as $name => $prop) {
            $token = sprintf('{%s}', $name);
            if (false !== strpos($message, $token)) {
                $message = str_replace($token, $prop, $message);
            }
        }

        return $message;
    }

    /**
     * @param Build|Push|null $entity
     * @return null
     */
    private function prepareMessage($entity)
    {
        $notify = null;

        if ($entity instanceof Push) {
            $notify = $entity->getBuild()->getRepository()->getEmail();

        } elseif ($entity instanceof Build) {
            $notify = $entity->getRepository()->getEmail();
        }

        // Add repo group notification
        if ($notify) {
            $this->message->setTo($notify);
        }
    }
}
