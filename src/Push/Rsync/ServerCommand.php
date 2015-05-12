<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Remoting\SSHProcess;

class ServerCommand
{
    /**
     * @type SSHProcess
     */
    private $remoter;

    /**
     * @param SSHProcess $remoter
     */
    public function __construct(SSHProcess $remoter)
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

        foreach ($commands as $command) {
            $context = $this->remoter
                ->createCommand($remoteUser, $remoteServer, [$chdir, $command])
                ->withIsInteractive(true)
                ->withSanitized($command);

            if (!$response = $this->remoter->run($context, $env, [true])) {
                return false;
            }
        }

        // all good
        return true;
    }
}
