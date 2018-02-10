<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Linux\Steps;

use Hal\Agent\Job\FileCompression;
use Hal\Agent\Remoting\SSHSessionManager;
use phpseclib\Net\SCP;

/**
 * This uses SCP to transfer a single build archive (tar).
 */
class Exporter
{
    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var FileCompression
     */
    private $fileCompression;

    /**
     * @param SSHSessionManager $sshManager
     * @param FileCompression $fileCompression
     */
    public function __construct(SSHSessionManager $sshManager, FileCompression $fileCompression)
    {
        $this->sshManager = $sshManager;
        $this->fileCompression = $fileCompression;
    }

    /**
     * @param string $buildPath
     * @param string $buildFile
     * @param string $remoteConnection
     * @param string $remoteFile
     *
     * @return bool
     */
    public function __invoke(string $buildPath, string $buildFile, string $remoteConnection, string $remoteFile)
    {
        if (!$this->fileCompression->packTarArchive($buildPath, $buildFile)) {
            return false;
        }

        [$remoteUser, $remoteServer] = explode('@', $remoteConnection);

        if (!$this->transferFile($buildFile, $remoteUser, $remoteServer, $remoteFile)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildFile
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remoteFile
     *
     * @return bool
     */
    private function transferFile($buildFile, $remoteUser, $remoteServer, $remoteFile)
    {
        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            return false;
        }

        $scp = new SCP($ssh);
        $result = $scp->put($remoteFile, $buildFile, SCP::SOURCE_LOCAL_FILE);

        if ($result !== true) {
            return false;
        }

        return true;
    }
}
