<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Pusher
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Code Sync';
    const ERR_PUSHING_TIMEOUT = 'Syncing code to server took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $syncPath
     * @param array $excludedFiles
     * @return boolean
     */
    public function __invoke($buildPath, $syncPath, array $excludedFiles)
    {
        $command = $this->buildRsyncCommand($buildPath, $syncPath, $excludedFiles);

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($command)
            ->setTimeout($this->commandTimeout)
            ->getProcess();
        $process->setCommandLine($process->getCommandLine() . ' 2>&1');

        if (!$this->runProcess($process, $this->logger, self::ERR_PUSHING_TIMEOUT, $this->commandTimeout)) {
            return false;
        }

        if ($process->isSuccessful()) {
            return $this->processSuccess($process);
        }

        return $this->processFailure($process);
    }

    /**
     * @param string $buildPath
     * @param string $syncPath
     * @param string[] $excludedFiles
     * @return string[]
     */
    private function buildRsyncCommand($buildPath, $syncPath, array $excludedFiles)
    {
        $command = [
            'rsync',
            '--rsh=ssh -o BatchMode=yes',
            '--recursive',
            '--links',
            '--perms',
            '--group',
            '--owner',
            '--devices',
            '--specials',
            '--checksum',
            '--verbose',
            '--delete-after'
        ];

        foreach ($excludedFiles as $excluded) {
            $command[] = '--exclude=' . $excluded;
        }

        return array_merge($command, [$buildPath . '/', $syncPath]);
    }

    /**
     * @param Process $process
     * @return bool
     */
    private function processFailure(Process $process)
    {
        $this->logger->event('failure', self::EVENT_MESSAGE, [
            'command' => $process->getCommandLine(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode()
        ]);

        return false;
    }

    /**
     * @param Process $process
     * @return bool
     */
    private function processSuccess(Process $process)
    {
        $this->logger->event('success', self::EVENT_MESSAGE, [
            'command' => $process->getCommandLine(),
            'output' => $process->getOutput()
        ]);

        return true;
    }
}
