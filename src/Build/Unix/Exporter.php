<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\FileSyncManager;
use QL\Hal\Agent\Remoting\SSHProcess;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * This uses RSYNC for file transfer
 */
class Exporter
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Export to build server';
    const ERR_TIMEOUT = 'Export to build server took too long';
    const ERR_PREPARE_BUILD_DIR = 'Failed to prepare build directory';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @type SSHProcess
     */
    private $remoter;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * Time (in seconds) to wait before aborting
     *
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param FileSyncManager $fileSyncManager
     * @param SSHProcess $remoter
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     * @param string $remoteUser
     */
    public function __construct(
        EventLogger $logger,
        FileSyncManager $fileSyncManager,
        SSHProcess $remoter,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->fileSyncManager = $fileSyncManager;
        $this->remoter = $remoter;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return boolean
     */
    public function __invoke($buildPath, $remoteUser, $remoteServer, $remotePath)
    {
        if (!$this->createRemoteDir($remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        if (!$this->transferFiles($buildPath, $remoteUser, $remoteServer, $remotePath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function createRemoteDir($remoteUser, $remoteServer, $remotePath)
    {
        $command = sprintf('if [ -d "%1$s" ]; then \rm -r "%1$s"; fi; mkdir -p "%1$s"', $remotePath);

        $context = $this->remoter
            ->createCommand($remoteUser, $remoteServer, $command);

        if ($response = $this->remoter->run($context, [], [false])) {
            return true;
        }

        $this->logger->event('failure', self::ERR_PREPARE_BUILD_DIR);
        return false;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function transferFiles($buildPath, $remoteUser, $remoteServer, $remotePath)
    {
        $command = $this->fileSyncManager->buildOutgoingRsync($buildPath, $remoteUser, $remoteServer, $remotePath);
        if ($command === null) {
            return false;
        }

        $rsyncCommand = implode(' ', $command);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments([''])
            ->setTimeout($this->commandTimeout)
            ->getProcess()
            // processbuilder escapes input, but it breaks the rsync params
            ->setCommandLine($rsyncCommand . ' 2>&1');

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
}
