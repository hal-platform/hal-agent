<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Remoting;

class FileSyncManager
{
    const DEFAULT_SSH_PORT = 22;

    /**
     * @var CredentialWallet
     */
    private $credentials;

    /**
     * @param CredentialWallet $credentials
     */
    public function __construct(CredentialWallet $credentials)
    {
        $this->credentials = $credentials;
    }

    /**
     * @param string $localPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     * @param string[] $excludedFiles
     *
     * @return string[]|null
     */
    public function buildOutgoingRsync($localPath, $remoteUser, $remoteServer, $remotePath, array $excludedFiles = [])
    {
        list($server, $port) = $this->parseServer($remoteServer);

        if (!$credential = $this->findCredential($remoteUser, $server)) {
            return null;
        }

        $from = $localPath . '/';
        $to = sprintf('%s@%s:%s', $credential->username(), $server, $remotePath);

        $rsync = $this->buildBaseRsync($port, $credential->keyPath());

        $rsync[] = $from;
        $rsync[] = $to;

        foreach ($excludedFiles as $excluded) {
            $rsync[] = '--exclude=' . $excluded;
        }

        return $rsync;
    }

    /**
     * @param string $localPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     * @param string[] $excludedFiles
     *
     * @return string[]|null
     */
    public function buildIncomingRsync($localPath, $remoteUser, $remoteServer, $remotePath, array $excludedFiles = [])
    {
        list($server, $port) = $this->parseServer($remoteServer);

        if (!$credential = $this->findCredential($remoteUser, $server)) {
            return null;
        }

        $to = $localPath . '/';
        $from = sprintf('%s@%s:%s', $credential->username(), $server, $remotePath);

        $rsync = $this->buildBaseRsync($port, $credential->keyPath());

        $rsync[] = $from;
        $rsync[] = $to;

        foreach ($excludedFiles as $excluded) {
            $rsync[] = '--exclude=' . $excluded;
        }

        return $rsync;
    }

    /**
     * alternative? http://www.cis.upenn.edu/~bcpierce/unison/
     *
     * @param string $port
     * @param string $identity
     *
     * @return string[]
     */
    private function buildBaseRsync($port, $identity)
    {
        $remoteShell = sprintf('ssh -o BatchMode=yes -p %d -i %s', $port, $identity);

        $command = [
            'rsync',
            sprintf('--rsh="%s"', $remoteShell),
            '--recursive',
            '--links',
            '--perms',
            '--checksum',
            '--verbose',
            '--delete-after'
        ];

        return $command;
    }

    /**
     * @param string $localPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return string[]|null
     */
    public function buildOutgoingScp($localPath, $remoteUser, $remoteServer, $remotePath)
    {
        list($server, $port) = $this->parseServer($remoteServer);

        if (!$credential = $this->findCredential($remoteUser, $server)) {
            return null;
        }

        $from = $localPath;
        $to = sprintf('%s@%s:%s', $credential->username(), $server, $remotePath);

        return $this->buildScp($from, $to, $port, $credential->keyPath());
    }

    /**
     * @param string $localPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return string[]|null
     */
    public function buildIncomingScp($localPath, $remoteUser, $remoteServer, $remotePath)
    {
        list($server, $port) = $this->parseServer($remoteServer);

        if (!$credential = $this->findCredential($remoteUser, $server)) {
            return null;
        }

        $from = sprintf('%s@%s:%s', $credential->username(), $server, $remotePath);
        $to = $localPath;

        return $this->buildScp($from, $to, $port, $credential->keyPath());
    }

    /**
     * @param string $localPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return string[]|null
     */
    public function buildIncomingScpForDirectory($localPath, $remoteUser, $remoteServer, $remotePath)
    {
        $remotePath = sprintf('%s/.', rtrim($remotePath, '/'));

        return $this->buildIncomingScp($localPath, $remoteUser, $remoteServer, $remotePath);
    }

    /**
     * @param string $from
     * @param string $to
     * @param string $port
     * @param string $identity
     *
     * @return string[]
     */
    private function buildScp($from, $to, $port, $identity)
    {
        return [
            'scp',
            '-r',
            sprintf('-P %d', $port),
            sprintf('-i %s', $identity),
            $from,
            $to
        ];
    }


    /**
     * @param string $user
     * @param string $server
     *
     * @return Credential|null
     */
    private function findCredential($user, $server)
    {
        if (!$credential = $this->credentials->findCredential($user, $server)) {
            return null;
        }

        if (!$credential->isKeyCredential()) {
            return null;
        }

        return $credential;
    }

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

        $port = self::DEFAULT_SSH_PORT;
        if ($exploded) {
            $port = (int) array_shift($exploded);
        }

        return [$servername, $port];
    }
}
