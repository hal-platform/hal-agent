<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Generic;

use QL\Hal\Agent\Remoting\SSHProcess;

class Cleaner
{
    const EVENT_MESSAGE = 'Clean remote build server';

    /**
     * @type SSHProcess
     */
    private $remoter;

    /**
     * @type bool
     */
    private $doHorribleThing;

    /**
     * @param EventLogger $logger
     * @param SSHProcess $remoter
     */
    public function __construct(SSHProcess $remoter)
    {
        $this->remoter = $remoter;
        $this->doHorribleThing = true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    public function __invoke($remoteUser, $remoteServer, $remotePath)
    {
        // -f is required because the target system has LIKELY aliased 'rm' to 'rm -i',
        // and the backslash doesn't seem to cancel the alias for some reason
        $rmdir = [
            $this->doHorribleThing ? '\rm -rf' : '\rm -r',
            sprintf('"%s"', $remotePath)
        ];

        $command = $this->remoter->createCommand($remoteUser, $remoteServer, $rmdir);

        return $this->remoter->runWithLoggingOnFailure($command, [], [false, self::EVENT_MESSAGE]);
    }
}
