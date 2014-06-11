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
    const BUILD_SUBJECT = '{repository} ({environment}) - Build - {status}';
    const PUSH_SUBJECT = '{repository} ({environment}:{server}) - Push - {status}';
    const BUILD_EMAIL_SUBJECT = '{repository} ({environment}) - Build - [{status}]';
    const PUSH_EMAIL_SUBJECT = '{repository} ({environment}:{server}) - Push - [{status}]';

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
     * Optionally, pass an associative array of strings to use non-default email and log subjects.
     * Keys:
     *     - 'email.build'
     *     - 'email.push'
     *     - 'log.build'
     *     - 'log.push'
     *
     * @param LoggerInterface $logger
     * @param BaseBufferHandler $buffer
     * @param Swift_Message $message
     * @param array $subjects
     */
    public function __construct(LoggerInterface $logger, BaseBufferHandler $buffer, Swift_Message $message, array $subjects = [])
    {
        $this->logger = $logger;
        $this->buffer = $buffer;
        $this->message = $message;
        $this->subjects = $subjects;
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
            return $this->finishBuild($entity, $context, true);
        }

        if ($entity instanceof Push) {
            return $this->finishPush($entity, $context, true);
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
            return $this->finishBuild($entity, $context, false);
        }

        if ($entity instanceof Push) {
            return $this->finishPush($entity, $context, false);
        }
    }

    /**
     * @param Build $build
     * @param array $context
     * @param boolean $isSuccess
     * @return null
     */
    private function finishBuild(Build $build, array $context, $isSuccess)
    {
        $props = $this->buildProperties($build, $isSuccess);

        // prepare email message
        $emailSubject = $this->replaceTokens(
            $this->getSubject('email.build', self::BUILD_EMAIL_SUBJECT),
            $props
        );
        $this->message->setSubject($emailSubject);
        if ($notifyEmail = $build->getRepository()->getEmail()) {
            $this->message->setTo($notifyEmail);
        }

        // send final log
        $level = ($isSuccess) ? 'info' : 'critical';
        $this->logger->$level(
            $this->replaceTokens($this->getSubject('log.build', self::BUILD_SUBJECT), $props),
            array_merge($context, $props)
        );

        // flush log buffer
        $this->buffer->flush();
    }

    /**
     * @param Build $push
     * @param array $context
     * @param boolean $isSuccess
     * @return null
     */
    private function finishPush(Push $push, array $context, $isSuccess)
    {
        $build = $push->getBuild();
        $server = $push->getDeployment()->getServer();
        $props = array_merge($this->buildProperties($build, $isSuccess), [
            'pushId' => $push->getId(),
            'server' => $server->getName()
        ]);

        // prepare email message
        $emailSubject = $this->replaceTokens(
            $this->getSubject('email.push', self::PUSH_EMAIL_SUBJECT),
            $props
        );
        $this->message->setSubject($emailSubject);
        if ($notifyEmail = $build->getRepository()->getEmail()) {
            $this->message->setTo($notifyEmail);
        }

        // prepare final log
        $level = ($isSuccess) ? 'info' : 'critical';
        $this->logger->$level(
            $this->replaceTokens($this->getSubject('log.push', self::PUSH_SUBJECT), $props),
            array_merge($context, $props)
        );

        // flush log buffer
        $this->buffer->flush();
    }

    /**
     * @param Build $build
     * @param boolean $isSuccess
     * @return array
     */
    private function buildProperties(Build $build, $isSuccess)
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
            'environment' => $build->getEnvironment()->getKey(),
            'status' => ($isSuccess) ? 'SUCCESS' : 'FAILURE'
        ];
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
     * @param string $key
     * @param string $default
     * @return string
     */
    private function getSubject($key, $default)
    {
        if (isset($this->subjects[$key])) {
            return $this->subjects[$key];
        }

        return $default;
    }
}
