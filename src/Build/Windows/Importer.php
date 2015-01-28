<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * Art Vandaley
 */
class Importer
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Import from build server';
    const ERR_TIMEOUT = 'Import from build server took too long';

    /**
     * @type EventLogger
     */
    private $logger;

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
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     * @param string $remoteUser
     */
    public function __construct(
        EventLogger $logger,
        ProcessBuilder $processBuilder,
        $commandTimeout,
        $remoteUser
    ) {
        $this->logger = $logger;
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
        if (!$this->transferFiles($buildPath, $buildServer, $remotePath)) {
            return false;
        }

        return true;
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
        $from = sprintf('%s@%s:%s', $this->remoteUser, $buildServer, $remotePath);
        $from = sprintf('%s/.', rtrim($from, '/'));
        $to = '.';

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
}
