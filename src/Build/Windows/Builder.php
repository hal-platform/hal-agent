<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\RemoteProcess;

/**
 * C:\Windows\Microsoft.NET\Framework\v2.0.50727\MSBuild.exe
 * C:\Windows\Microsoft.NET\Framework\v3.5\MSBuild.exe
 * C:\Windows\Microsoft.NET\Framework\v4.0.30319\MSBuild.exe
 */
class Builder
{
    const EVENT_MESSAGE = 'Run Windows Build Command';

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
     * @param string $remoteServer
     * @param string $remotePath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($remoteServer, $remotePath, array $commands, array $env)
    {
        $chdir = sprintf('cd "%s" &&', $remotePath);

        $remoter = $this->remoter;
        foreach ($commands as $command) {
            // $command = $remoter->sanitize($command);
            if (!$response = $remoter($remoteServer, $command, $env, true, $chdir, self::EVENT_MESSAGE)) {
                return false;
            }
        }

        // all good
        return true;
    }
}
