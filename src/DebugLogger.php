<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent;

use Psr\Log\AbstractLogger;

class DebugLogger extends AbstractLogger
{
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

    public function output()
    {
        $output = '';
        foreach ($this->messages as $message) {
            $output .= $this->format($message);
        }

        return $output;
    }

    private function format(array $messager)
    {
        list($level, $message, $context) = $messager;

        $formatted = str_pad(sprintf('[%s]', strtoupper($level)), 10);
        $formatted .= $message . "\n";

        if ($context) {
            $context = json_encode($context, JSON_PRETTY_PRINT);
            $formatted .= $context . "\n";
        }

        return $formatted;
    }
}
