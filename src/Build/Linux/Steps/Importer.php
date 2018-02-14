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
use Symfony\Component\Filesystem\Filesystem;

/**
 * This uses SCP to transfer a single build archive (tar).
 */
class Importer
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
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param SSHSessionManager $sshManager
     * @param FileCompression $fileCompression
     * @param Filesystem $filesystem
     */
    public function __construct(SSHSessionManager $sshManager, FileCompression $fileCompression, Filesystem $filesystem)
    {
        $this->sshManager = $sshManager;
        $this->fileCompression = $fileCompression;
        $this->filesystem = $filesystem;
    }

    /**
     * @param string $buildPath
     * @param string $buildFile
     * @param string $remoteConnection
     * @param string $remoteFile
     *
     * @return boolean
     */
    public function __invoke(string $buildPath, string $buildFile, string $remoteConnection, string $remoteFile)
    {
        [$remoteUser, $remoteServer] = explode('@', $remoteConnection);

        if (!$this->transferFile($buildFile, $remoteUser, $remoteServer, $remoteFile)) {
            return false;
        }

        $this->removeLocalFiles($buildPath);
        $this->fileCompression->createWorkspace($buildPath);

        if (!$this->fileCompression->unpackTarArchive($buildPath, $buildFile)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $localFile
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remoteFile
     *
     * @return bool
     */
    private function transferFile($localFile, $remoteUser, $remoteServer, $remoteFile)
    {
        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            return false;
        }

        $scp = new SCP($ssh);
        $result = $scp->get($remoteFile, $localFile);

        if ($result !== true) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     *
     * @return bool
     */
    private function removeLocalFiles($buildPath)
    {
        if ($this->filesystem->exists($buildPath)) {
            $this->filesystem->remove($buildPath);
        }

        return true;
    }
}
