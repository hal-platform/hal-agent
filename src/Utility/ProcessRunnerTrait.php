<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

trait ProcessRunnerTrait
{
    /**
     * @param Process $process
     * @param int $timeout
     *
     * @return bool
     */
    private function runProcess(Process $process, $timeout): bool
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
     * @return bool
     */
    private function processFailure($cmd, Process $process): bool
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
     * @return bool
     */
    private function processSuccess($cmd, Process $process): bool
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
