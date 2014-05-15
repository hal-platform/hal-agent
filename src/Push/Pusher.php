<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\ProcessBuilder;

class Pusher
{
    /**
     * @var string
     */
    const SUCCESS_PUSH = 'Application code synced to server';
    const ERR_PUSH = 'Unable to finish syncing application code';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @param LoggerInterface $logger
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(LoggerInterface $logger, ProcessBuilder $processBuilder)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
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
            ->setTimeout(300)
            ->getProcess();
        $process->setCommandLine($process->getCommandLine() . ' 2>&1');

        $process->run();

        if ($process->isSuccessful()) {
            $this->logger->info(self::SUCCESS_PUSH, $context);
            return true;
        }

        $context = array_merge($context, [
            'exitCode' => $process->getExitCode(),
            'output' => $process->getOutput()
        ]);

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
            '--verbose'
        ];

        foreach ($excludedFiles as $excluded) {
            $command[] = '--exclude=' . $excluded;
        }

        return array_merge($command, [$buildPath . '/', $syncPath]);
    }
}
