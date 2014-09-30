<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use Monolog\Handler\BufferHandler as BaseBufferHandler;

/**
 * This is a custom buffer handler which allows a callable to be set to fire when the buffer is closed out.
 */
class BufferHandler extends BaseBufferHandler
{
    private $command;

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        if ($this->bufferSize > 0 && $this->command !== null) {
            call_user_func($this->command);
        }

        $this->flush();
    }

    /**
     * @param callable $command
     * @return null
     */
    public function setCommandOnFlush(callable $command)
    {
        $this->command = $command;
    }
}
