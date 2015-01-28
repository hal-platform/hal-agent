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
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return bool
     */
    public function __invoke($buildServer, $remotePath)
    {
        $rmdir = sprintf('rm -r %s', $remotePath);

        $remoter = $this->remoter;
        return $remoter($buildServer, $rmdir, [], false, false);
    }
}
