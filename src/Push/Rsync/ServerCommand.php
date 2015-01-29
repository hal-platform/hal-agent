<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\RemoteProcess;

class ServerCommand
{
    /**
     * @type RemoteProcess
     */
    private $remoter;

    /**
     * @param RemoteProcess $remoter
     */
    public function __construct(RemoteProcess $remoter)
    {
        $this->remoter = $remoter;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($remoteUser, $remoteServer, $remotePath, array $commands, array $env)
    {
        $chdir = sprintf('cd "%s" &&', $remotePath);

        $remoter = $this->remoter;
        foreach ($commands as $command) {
            // $command = $remoter->sanitize($command);
            if (!$response = $remoter($remoteUser, $remoteServer, $command, $env, true, $chdir)) {
                return false;
            }
        }

        // all good
        return true;
    }
}
