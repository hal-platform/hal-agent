<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\RemoteProcess;

class Cleaner
{
    /**
     * @type RemoteProcess
     */
    private $remoter;

    /**
     * @param EventLogger $logger
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
     *
     * @return bool
     */
    public function __invoke($remoteUser, $remoteServer, $remotePath)
    {
        $rmdir = sprintf('rm -r "%s"', $remotePath);

        $remoter = $this->remoter;
        return $remoter($remoteUser, $remoteServer, $rmdir, [], false);
    }
}
