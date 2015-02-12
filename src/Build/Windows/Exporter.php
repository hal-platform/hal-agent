<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use QL\Hal\Agent\RemoteProcess;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Exporter
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Export to build server';
    const ERR_TIMEOUT = 'Export to build server took too long';

    const PREPARE_BUILD_DIR = 'Failed to prepare build directory';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type RemoteProcess
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
     * @type string
     */
    private $remoteUser;

    /**
     * @param EventLogger $logger
     * @param RemoteProcess $remoter
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     * @param string $remoteUser
     */
    public function __construct(
        EventLogger $logger,
        RemoteProcess $remoter,
        ProcessBuilder $processBuilder,
        $commandTimeout,
        $remoteUser
    ) {
        $this->logger = $logger;
        $this->remoter = $remoter;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
        $this->remoteUser = $remoteUser;
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

        if (!$this->transferFiles($buildPath, $remoteServer, $remotePath)) {
            return false;
        }

        if (!$this->removeLocalFiles($buildPath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildUser
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function createRemoteDir($buildUser, $buildServer, $remotePath)
    {
        $command = sprintf('if [ -d "%1$s" ]; then rm -r "%1$s"; fi; mkdir -p "%1$s"', $remotePath);

        $remoter = $this->remoter;
        if ($response = $remoter($buildUser, $buildServer, $command, [], false)) {
            return true;
        }

        $this->logger->event('failure', self::PREPARE_BUILD_DIR);
        return false;
    }

    /**
     * @param string $buildPath
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function transferFiles($buildPath, $buildServer, $remotePath)
    {
        list($buildServer, $buildServerPort) = $this->parseServer($buildServer);

        $from = '.';
        $to = sprintf('%s@%s:%s', $this->remoteUser, $buildServer, $remotePath);

        $scpCommand = [
            'scp',
            '-r',
            '-P',
            $buildServerPort,
            $from,
            $to
        ];

        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($scpCommand)
            ->getProcess();

        if (!$this->runProcess($process, $this->commandTimeout)) {
            // command timed out, bomb out
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $dispCommand = implode(' ', $scpCommand);
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
        $rmCommand = ['rm', '-r', $buildPath];
        $rmdir = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($rmCommand)
            ->getProcess();

        // create again
        $mkCommand = ['mkdir', $buildPath];
        $mkdir = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($mkCommand)
            ->getProcess();

        $rmdir->run();
        $mkdir->run();

        if ($rmdir->isSuccessful() && $mkdir->isSuccessful()) {
            return true;
        }

        $failedProcess = $rmdir->isSuccessful() ? $mkdir : $rmdir;

        $dispCommand = [
            implode(' ', $rmCommand),
            implode(' ', $mkCommand)
        ];

        return $this->processFailure($dispCommand, $failedProcess);
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

        $port = 22;
        if ($exploded) {
            $port = (int) array_shift($exploded);
        }

        return [$servername, $port];
    }
}
