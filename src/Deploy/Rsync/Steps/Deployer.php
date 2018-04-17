<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\Rsync\Steps;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Remoting\FileSyncManager;
use Hal\Agent\Symfony\ProcessRunner;

class Deployer
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Code Deployment';
    const ERR_TIMEOUT = 'Deploying code to server took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @var ProcessRunner
     */
    private $runner;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param FileSyncManager $fileSyncManager
     * @param ProcessRunner $runner
     * @param int $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        FileSyncManager $fileSyncManager,
        ProcessRunner $runner,
        int $commandTimeout
    ) {
        $this->logger = $logger;
        $this->fileSyncManager = $fileSyncManager;
        $this->runner = $runner;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $remotePath
     * @param array $excludedFiles
     *
     * @return bool
     */
    public function __invoke(
        string $buildPath,
        string $remoteUser,
        string $remoteServer,
        string $remotePath,
        array $excludedFiles
    ): bool {
        $command = $this->fileSyncManager->buildOutgoingRsync(
            $buildPath,
            $remoteUser,
            $remoteServer,
            $remotePath,
            $excludedFiles
        );

        if ($command === null) {
            return false;
        }

        $rsyncCommand = implode(' ', $command) . ' 2>&1';
        $dispCommand = implode("\n", $command);

        $process = $this->runner->prepare($command, '', $this->commandTimeout);
        // We manually set the command line because Process' input escaping breaks the rsync parameters
        $process->setCommandLine($rsyncCommand);

        if (!$this->runner->run($process, $dispCommand, static::ERR_TIMEOUT)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return $this->runner->onSuccess($process, $dispCommand, self::EVENT_MESSAGE);
        }

        return $this->runner->onFailure($process, $dispCommand, self::EVENT_MESSAGE);
    }
}
