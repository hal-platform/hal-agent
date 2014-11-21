<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Notifier;

use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;
use QL\Hal\Core\Entity\Type\EventEnumType;
use Swift_Mailer;
use Swift_Message;

class EmailNotifier implements NotifierInterface
{
    /**
     * @type string
     */
    const BUILD_MESSAGE = '[%s] %s (%s)';
    const PUSH_MESSAGE = '[%s] %s (%s:%s)';

    const ICON_SUCCESS = "\xE2\x9C\x94";
    const ICON_FAILURE = "\xE2\x9C\x96";
    const ICON_INFO = "\xE2\x81\x89";

    /**
     * @type Swift_Mailer
     */
    private $mailer;

    /**
     * @type Swift_Message
     */
    private $message;

    /**
     * @type EmailFormatter
     */
    private $formatter;

    /**
     * @param Swift_Mailer $mailer
     * @param Swift_Message $message
     * @param EmailFormatter $formatter
     */
    public function __construct(Swift_Mailer $mailer, Swift_Message $message, EmailFormatter $formatter)
    {
        $this->mailer = $mailer;
        $this->message = $message;
        $this->formatter = $formatter;
    }

    /**
     * @param string $event
     * @param array $data
     *
     * @return null
     */
    public function send($event, array $data)
    {
        // icon
        $data['icon'] = self::ICON_INFO;
        if ($data['status'] === true) {
            $data['icon'] = self::ICON_SUCCESS;

        } elseif ($data['status'] === false) {
            $data['icon'] = self::ICON_FAILURE;
        }

        // skip if no email
        if (!$to = $data['repository']->getEmail()) {
            return;
        }

        if ($data['push'] instanceof Push) {
            $subject = sprintf(self::PUSH_MESSAGE, $data['icon'], $data['repository']->getKey(), $data['environment']->getKey(), $data['server']->getName());
        } else {
            $subject = sprintf(self::BUILD_MESSAGE, $data['icon'], $data['repository']->getKey(), $data['environment']->getKey());
        }

        $isHighPriority = ($data['status'] === false);
        $message = $this->formatter->format($data);

        $this->sendMessage($to, $subject, $message, $isHighPriority);
    }

    /**
     * @param string $to
     * @param string $subject
     * @param string $message
     * @param bool $isHighPriority
     *
     * @return null
     */
    private function sendMessage($to, $subject, $message, $isHighPriority)
    {
        $email = $this->getMessage();
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setBody($message);

        if ($isHighPriority) {
            $message->setPriority(1);
        }

        $this->mailer->send($email);
    }

    /**
     * @return Swift_Message
     */
    private function getMessage()
    {
        return clone $this->message;
    }
}
