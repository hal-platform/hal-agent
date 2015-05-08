<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

trait FileSyncTrait
{
    private $DEFAULT_SSH_PORT = 22;

    /**
     * Parse servername or servername:port into an array containing [$server, $port]
     *
     * @param string $server
     *
     * @return array
     */
    private function parseServer($server)
    {
        $exploded = explode(':', $server);

        $servername = array_shift($exploded);

        $port = $this->DEFAULT_SSH_PORT;
        if ($exploded) {
            $port = (int) array_shift($exploded);
        }

        return [$servername, $port];
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return string[]
     */
    private function buildOutgoingRsync($buildPath, $remoteUser, $buildServer, $remotePath)
    {
        list($buildServer, $buildServerPort) = $this->parseServer($buildServer);

        $from = $buildPath . '/';
        $to = sprintf('%s@%s:%s', $remoteUser, $buildServer, $remotePath);

        return $this->buildRsync($from, $to, $buildServerPort);
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return string[]
     */
    private function buildIncomingRsync($buildPath, $remoteUser, $buildServer, $remotePath)
    {
        list($buildServer, $buildServerPort) = $this->parseServer($buildServer);

        $to = $buildPath . '/';
        $from = sprintf('%s@%s:%s', $remoteUser, $buildServer, $remotePath);

        return $this->buildRsync($from, $to, $buildServerPort);
    }

    /**
     * @param string $from
     * @param string $to
     * @param string $port
     *
     * @return string[]
     */
    private function buildRsync($from, $to, $port)
    {
        $remoteShell = sprintf('ssh -o BatchMode=yes -p %d', $port);
        $command = [
            'rsync',
            sprintf('--rsh="%s"', $remoteShell),
            '--recursive',
            '--links',
            '--perms',
            '--group',
            '--owner',
            '--devices',
            '--specials',
            '--checksum',
            '--verbose',
            '--delete-after'
        ];

        $command[] = $from;
        $command[] = $to;

        return $command;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return string[]
     */
    private function buildOutgoingScp($buildPath, $remoteUser, $buildServer, $remotePath)
    {
        list($buildServer, $buildServerPort) = $this->parseServer($buildServer);

        $from = $buildPath;
        $to = sprintf('%s@%s:%s', $remoteUser, $buildServer, $remotePath);

        return $this->buildScp($from, $to, $buildServerPort);
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return string[]
     */
    private function buildIncomingScp($buildPath, $remoteUser, $buildServer, $remotePath)
    {
        list($buildServer, $buildServerPort) = $this->parseServer($buildServer);

        $from = sprintf('%s@%s:%s/.', $remoteUser, $buildServer, rtrim($remotePath, '/'));
        $to = $buildPath;

        return $this->buildScp($from, $to, $buildServerPort);
    }

    /**
     * @param string $from
     * @param string $to
     * @param string $port
     *
     * @return string[]
     */
    private function buildScp($from, $to, $port)
    {
        return [
            'scp',
            '-r',
            '-P',
            $port,
            $from,
            $to
        ];
    }
}
