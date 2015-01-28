<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
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
    const PREPARE_BUILD_DIR = 'Prepare build directory';
    const RESET_LOCAL_DIR = 'Reset local build directory';
    const ERR_TIMEOUT = 'Export to build server took too long';

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
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return boolean
     */
    public function __invoke($buildPath, $buildServer, $remotePath)
    {
        if (!$this->createRemoteDir($buildServer, $remotePath)) {
            return false;
        }

        if (!$this->transferFiles($buildPath, $buildServer, $remotePath)) {
            return false;
        }

        if (!$this->removeLocalFiles($buildPath)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildServer
     * @param string $remotePath
     *
     * @return bool
     */
    private function createRemoteDir($buildServer, $remotePath)
    {
        $command = sprintf('if [ -d %1$s ]; then rm -r %1$s; fi; mkdir -p %1$s', $remotePath);

        $remoter = $this->remoter;
        if ($response = $remoter($buildServer, $command, [], false, false)) {
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
        $from = '.';
        $to = sprintf('%s@%s:%s', $this->remoteUser, $buildServer, $remotePath);

        $cmd = ['scp', '-r', $from, $to];
        $process = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->getProcess();

        if (!$this->runProcess($process, $this->logger, self::ERR_TIMEOUT, $this->commandTimeout)) {
            // command timed out, bomb out
            return false;
        }

        if ($process->isSuccessful()) {
            return true;
        }

        $this->logger->event('failure', self::EVENT_MESSAGE, [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput()
        ]);

        return false;
    }

    /**
     * @param string $buildPath
     *
     * @return bool
     */
    private function removeLocalFiles($buildPath)
    {
        // remove local build dir
        $cmd = ['rm', '-r', $buildPath];
        $rmdir = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->getProcess();

        // create again
        $cmd = ['mkdir', $buildPath];
        $mkdir = $this->processBuilder
            ->setWorkingDirectory($buildPath)
            ->setArguments($cmd)
            ->getProcess();

        $rmdir->run();
        $mkdir->run();

        if ($rmdir->isSuccessful() && $mkdir->isSuccessful()) {
            return true;
        }

        $this->logger->event('failure', self::RESET_LOCAL_DIR, [
            'command' => $process->getCommandLine(),
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput()
        ]);

        return false;
    }
}
