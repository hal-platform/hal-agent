<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Testing;

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
