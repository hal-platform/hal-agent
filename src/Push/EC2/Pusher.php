<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\EC2;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Pusher
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Code Deployment to instances';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param string $ec2User
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, $ec2User, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
        $this->ec2User = $ec2User;
    }

    /**
     * @param string $buildPath
     * @param string $remotePath
     * @param array $excludedFiles
     * @param array $instances
     *
     * @return boolean
     */
    public function __invoke($buildPath, $remotePath, array $excludedFiles, array $instances)
    {
        $instanceStatus = [];

        foreach ($instances as $instance) {
            $syncPath = sprintf('%s@%s:%s', $this->ec2User, $instance['PublicDnsName'], $remotePath);
            $command = $this->buildRsyncCommand($buildPath, $syncPath, $excludedFiles);

            // run sync for instance
            $process = $this->processBuilder
                ->setWorkingDirectory(null)
                ->setArguments($command)
                ->setTimeout($this->commandTimeout)
                ->getProcess();
            $process->setCommandLine($process->getCommandLine());

            $rsyncStatus = $this->runProcess($process) ? 'finished' : 'timeout';

            // if the rsync finished, check if failed or succeeded
            if ($rsyncStatus === 'finished') {
                $rsyncStatus = $process->isSuccessful() ? 'success' : 'failure';
            }

            $instanceStatus[] = [
                'ID' => $instance['InstanceId'],
                'public DNS name' => $instance['PublicDnsName'],
                'status' => $rsyncStatus
            ];
        }

        $failure = $success = 0;
        foreach ($instanceStatus as $status) {
            if ($status['status'] === 'success') {
                $success++;
            } else {
                $failure++;
            }
        }

        $context = [
            'instances' => $instanceStatus,
            'success' => $success,
            'failure' => $failure
        ];

        // desmond didn't press the button
        if ($failure > 0) {
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        // dont worry, locke will save us
        $this->logger->event('success', self::EVENT_MESSAGE, $context);
        return true;
    }

    /**
     * @param string $buildPath
     * @param string $syncPath
     * @param string[] $excludedFiles
     *
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
     *
     * @return boolean
     */
    private function runProcess(Process $process)
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException $ex) {
            return false;
        }

        return true;
    }
}
