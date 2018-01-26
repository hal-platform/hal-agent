<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

trait InternalDebugLoggingTrait
{
    /**
     * @var bool
     */
    private $logInternalCommands = false;

    /**
     * @param bool $logCommands
     *
     * @return void
     */
    public function setBuilderDebugLogging($logCommands)
    {
        $this->logInternalCommands = (bool) $logCommands;
    }

    /**
     * @return bool
     */
    private function isDebugLoggingEnabled()
    {
        return (bool) $this->logInternalCommands;
    }
}
