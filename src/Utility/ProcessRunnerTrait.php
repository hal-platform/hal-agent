<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

trait ProcessRunnerTrait
{
    /**
     * @param Process $process
     * @param int $timeout
     *
     * @return boolean
     */
    private function runProcess(Process $process, $timeout)
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException $ex) {

            // Only log if logger present on parent
            if ($this->logger instanceof EventLogger) {

                if (defined('static::ERR_TIMEOUT')) {
                    $err = static::ERR_TIMEOUT;
                } else {
                    $err = 'System action timed out';
                }

                $this->logger->event('failure', $err, [
                    'maxTimeout' => sprintf('%d seconds', $timeout),
                    'output' => $process->getOutput(),
                    'errorOutput' => $process->getErrorOutput()
                ]);
            }

            return false;
        }

        return true;
    }


    /**
     * @param string $cmd
     * @param Process $process
     *
     * @return boolean
     */
    private function processFailure($cmd, Process $process)
    {
        if (defined('static::EVENT_MESSAGE')) {
            $msg = static::EVENT_MESSAGE;
        } else {
            $msg = 'System action';
        }

        $this->logger->event('failure', $msg, [
            'command' => $cmd,
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode()
        ]);

        return false;
    }

    /**
     * @param string $cmd
     * @param Process $process
     *
     * @return boolean
     */
    private function processSuccess($cmd, Process $process)
    {
        if (defined('static::EVENT_MESSAGE')) {
            $msg = static::EVENT_MESSAGE;
        } else {
            $msg = 'System action';
        }

        $this->logger->event('success', $msg, [
            'command' => $cmd,
            'output' => $process->getOutput()
        ]);

        return true;
    }
}
