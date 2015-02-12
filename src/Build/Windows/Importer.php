<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
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
        list($buildServer, $buildServerPort) = $this->parseServer($buildServer);

        $from = sprintf('%s@%s:%s', $this->remoteUser, $buildServer, $remotePath);
        $from = sprintf('%s/.', rtrim($from, '/'));
        $to = '.';

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
