<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Helper;

use Psr\Log\AbstractLogger;

/**
 * A custom PSR-3 logger that lets us send a shit ton of messages to the logger
 * and collect them at the end of a command run.
 */
class MemoryLogger extends AbstractLogger
{
    /**
     *            level,  message, context
     * @var array(string $level, string $message,  array $context)[]
     */
    private $messages = [];

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = [])
    {
        $this->messages[] = [$level, $message, $context];
    }

    /**
     * @return array
     */
    public function messages()
    {
        return $this->messages;
    }

    /**
     * @param boolean $showContext
     * @return string
     */
    public function output($showContext = false)
    {
        $output = '';
        foreach ($this->messages as $message) {
            $output .= $this->format($message, $showContext);
        }

        return $output;
    }

    /**
     * @param array $message
     * @param boolean $showContext
     * @return string
     */
    private function format(array $message, $showContext)
    {
        list($level, $subject, $context) = $message;

        $formatted = str_pad(sprintf('[%s]', strtoupper($level)), 10) . ' ';
        $formatted .= $subject . "\n";

        if ($context && $showContext) {
            $context = json_encode($context, JSON_PRETTY_PRINT);
            $formatted .= $context . "\n";
        }

        return $formatted;
    }
}
