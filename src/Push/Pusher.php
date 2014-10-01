<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Psr\Log\LoggerInterface;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\ProcessBuilder;

class Pusher
{
    use ProcessRunnerTrait;

    /**
     * @var string
     */
    const SUCCESS_PUSH = 'Application code synced to server';
    const ERR_PUSH = 'Unable to finish syncing application code';
    const ERR_PUSHING_TIMEOUT = 'Syncing code to server took too long';

    /**
     * @var LoggerInterface
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
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder, $commandTimeout)
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

        $context = [
            'buildPath' => $buildPath,
            'syncPath' => $syncPath,
            'excludedFiles' => $excludedFiles,
            'command' => implode(' ', $command)
        ];

        $process = $this->processBuilder
            ->setWorkingDirectory(null)
            ->setArguments($command)
            ->setTimeout($this->commandTimeout)
            ->getProcess();
        $process->setCommandLine($process->getCommandLine() . ' 2>&1');

        if (!$this->runProcess($process, $this->logger, self::ERR_PUSHING_TIMEOUT, $this->commandTimeout)) {
            return false;
        }

        // we always want the output
        $context = array_merge($context, ['output' => $process->getOutput()]);

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_PUSH, $context);
            return true;
        }

        $errorContext = ['exitCode' => $process->getExitCode(), 'errorOutput' => $process->getErrorOutput()];
        $context = array_merge($context, $errorContext);

        $this->logger->critical(self::ERR_PUSH, $context);
        return false;
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
}
