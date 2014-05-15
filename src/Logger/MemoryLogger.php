<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use ArrayAccess;
use Psr\Log\AbstractLogger;

/**
 * A simple logger that stores logs in memory for later analyzation.
 *
 * Mostly useful for unit testing.
 */
class MemoryLogger extends AbstractLogger implements ArrayAccess
{
    /**
     * Each entry is an array containing:
     *     - (string) $level
     *     - (string) $message
     *     - (array) $context
     *
     * @var array
     */
    private $messages;

    public function __construct()
    {
        $this->messages = [];
    }

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
        $this[] = [$level, $message, $context];
    }

    public function offsetExists($offset)
    {
        return isset($this->messages[$offset]);
    }

    public function offsetGet($offset)
    {
        return $this->messages[$offset];
    }

    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            $this->messages[] = $value;
            return;
        }

        $this->messages[$offset] = $value;
    }

    public function offsetUnset($offset)
    {
        unset($this->messages[$offset]);
    }
}
