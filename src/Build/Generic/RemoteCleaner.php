<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Generic;

use Hal\Agent\Remoting\SSHProcess;

class RemoteCleaner
{
    const EVENT_MESSAGE = 'Clean remote build server';

    /**
     * @var SSHProcess
     */
    private $remoter;

    /**
     * @var bool
     */
    private $doHorribleThing;

    /**
     * @param SSHProcess $remoter
     */
    public function __construct(SSHProcess $remoter)
    {
        $this->remoter = $remoter;
        $this->doHorribleThing = true;
    }

    /**
     * @param string $remoteConnection
     * @param string $remotePath
     *
     * @return bool
     */
    public function __invoke(string $remoteConnection, string $remotePath)
    {
        // -f is required because the target system has LIKELY aliased 'rm' to 'rm -i',
        // and the backslash doesn't seem to cancel the alias for some reason

        $rmdir = [
            $this->doHorribleThing ? '\rm -rf' : '\rm -r',
            sprintf('"%s"', $remotePath)
        ];

        [$remoteUser, $remoteServer] = explode('@', $remoteConnection);

        $command = $this->remoter->createCommand($remoteUser, $remoteServer, $rmdir);

        return $this->remoter->runWithLoggingOnFailure($command, [], [false, self::EVENT_MESSAGE]);
    }
}
