<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Psr\Log\AbstractLogger;

class Logger extends AbstractLogger
{
    /**
     * @array[]
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
    public function log($level, $message, array $context = array())
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
     * @return string
     */
    public function output()
    {
        $output = '';
        foreach ($this->messages as $message) {
            $output .= $this->format($message);
        }

        return $output;
    }

    /**
     * @param array $message
     * @return string
     */
    private function format(array $message)
    {
        list($level, $subject, $context) = $message;

        $formatted = str_pad(sprintf('[%s]', strtoupper($level)), 10) . ' ';
        $formatted .= $subject . "\n";

        if ($context) {
            $context = json_encode($context, JSON_PRETTY_PRINT);
            $formatted .= $context . "\n";
        }

        return $formatted;
    }
}
