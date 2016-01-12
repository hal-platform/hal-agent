<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Push\EC2;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Remoting\FileSyncManager;
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
     * @type FileSyncManager
     */
    private $fileSyncManager;

    /**
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param FileSyncManager $fileSyncManager
     * @param int $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        FileSyncManager $fileSyncManager,
        ProcessBuilder $processBuilder,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->fileSyncManager = $fileSyncManager;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param string $remotePath
     * @param string $remoteUser
     * @param array $excludedFiles
     * @param array $instances
     *
     * @return boolean
     */
    public function __invoke($buildPath, $remoteUser, $remotePath, array $excludedFiles, array $instances)
    {
        $instanceStatus = [];

        foreach ($instances as $instance) {

            $command = $this->fileSyncManager->buildOutgoingRsync(
                $buildPath,
                $remoteUser,
                $instance['PublicDnsName'],
                $remotePath,
                $excludedFiles
            );

            $rsyncCommand = implode(' ', $command);

            // run sync for instance
            $process = $this->processBuilder
                ->setWorkingDirectory(null)
                ->setArguments([''])
                ->setTimeout($this->commandTimeout)
                ->getProcess()
                // processbuilder escapes input, but it breaks the rsync params
                ->setCommandLine($rsyncCommand . ' 2>&1');

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
