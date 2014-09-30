<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Psr\Log\AbstractLogger;
use Swift_Mailer;
use Swift_Message;

class EmailLogger extends AbstractLogger
{
    /**
     * @type Swift_Mailer
     */
    private $mailer;

    /**
     * @type Swift_Message
     */
    private $message;

    /**
     * @param Swift_Mailer $mailer
     * @param Swift_Message $message
     */
    public function __construct(Swift_Mailer $mailer, Swift_Message $message)
    {
        $this->mailer = $mailer;
        $this->message = $message;
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        $email = $this->getMessage();
        $email->setBody($message);

        if (isset($context['email']) && is_array($context['email'])) {
            $this->prepareMessage($email, $context['email']);
        }

        $this->mailer->send($email);
    }

    /**
     * @param Swift_Message $message
     * @param array $options
     * @return null
     */
    private function prepareMessage(Swift_Message $message, array $options)
    {
        $options = array_replace(array_fill_keys(['subject', 'to', 'isHighPriority'], null), $options);

        if ($options['subject']) {
            $message->setSubject($options['subject']);
        }

        if ($options['to']) {
            $message->setTo($options['to']);
        }

        if ($options['isHighPriority']) {
            $message->setPriority(1);
        }
    }

    /**
     * @return Swift_Message
     */
    private function getMessage()
    {
        return clone $this->message;
    }
}
