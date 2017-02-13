<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Remoting\SSHProcess;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * This uses SCP to transfer a single build archive (tar).
 */
class Exporter
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Export to build server';
    const ERR_TIMEOUT = 'Export to build server took too long';
    const ERR_PREPARE_BUILD_DIR = 'Failed to prepare build directory';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Packer
     */
    private $packer;

    /**
     * @var FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @var SSHProcess
     */
    private $remoter;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * Time (in seconds) to wait before aborting
     *
     * @var int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param Packer $packer
     * @param FileSyncManager $fileSyncManager
     * @param SSHProcess $remoter
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     * @param string $remoteUser
     */
    public function __construct(
        EventLogger $logger,
        Packer $packer,
        FileSyncManager $fileSyncManager,
        SSHProcess $remoter,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->packer = $packer;
        $this->fileSyncManager = $fileSyncManager;
        $this->remoter = $remoter;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $buildFile
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remoteFile
     *
     * @return boolean
     */
    public function __invoke($buildPath, $buildFile, $remoteUser, $remoteServer, $remoteFile)
    {
        if (!$this->packBuild($buildPath, $buildFile)) {
            return false;
        }

        if (!$this->transferFile($buildFile, $remoteUser, $remoteServer, $remoteFile)) {
            return false;
        }

        if (!$this->removeLocalFiles($buildPath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $buildFile
     *
     * @return bool
     */
    private function packBuild($buildPath, $buildFile)
    {
        $packer = $this->packer;

        return $packer($buildPath, '.', $buildFile);
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
        $command = $this->fileSyncManager->buildOutgoingScp($buildFile, $remoteUser, $remoteServer, $remoteFile);
        if ($command === null) {
            return false;
        }

        $rsyncCommand = implode(' ', $command);

        $process = $this->processBuilder
            ->setArguments($command)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            // command timed out, bomb out
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $dispCommand = implode("\n", $command);
        return $this->processFailure($dispCommand, $process);
    }

    /**
     * @param string $buildPath
     *
     * @return bool
     */
    private function removeLocalFiles($buildPath)
    {
        // remove local build dir
        $command = ['rm', '-r', $buildPath];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($command)
            ->getProcess();

        $process->run();

        if ($process->isSuccessful()) {
            return true;
        }

        $dispCommand = implode("\n", $command);
        return $this->processFailure($dispCommand, $process);
    }
}
