<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\Unix;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * This uses SCP to transfer a single build archive (tar).
 */
class Importer
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Import from build server';
    const ERR_TIMEOUT = 'Import from build server took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Unpacker
     */
    private $unpacker;

    /**
     * @var FileSyncManager
     */
    private $fileSyncManager;

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
     * @param Unpacker $unpacker
     * @param FileSyncManager $fileSyncManager
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        Unpacker $unpacker,
        FileSyncManager $fileSyncManager,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->unpacker = $unpacker;
        $this->fileSyncManager = $fileSyncManager;
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
        if (!$this->transferFile($buildFile, $remoteUser, $remoteServer, $remoteFile)) {
            return false;
        }

        $this->removeLocalFiles($buildPath);

        if (!$this->unpackBuild($buildFile, $buildPath)) {
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
        $command = $this->fileSyncManager->buildIncomingScp($buildFile, $remoteUser, $remoteServer, $remoteFile);
        if ($command === null) {
            return false;
        }

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

        #we don't care if this fails
        return true;
    }

    /**
     * @param string $buildFile
     * @param string $buildPath
     *
     * @return bool
     */
    private function unpackBuild($buildFile, $buildPath)
    {
        $unpacker = $this->unpacker;

        return $unpacker($buildFile, $buildPath);
    }
}
