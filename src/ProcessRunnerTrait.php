<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

trait ProcessRunnerTrait
{
    /**
     * @param Process $process
     * @param LoggerInterface $logger
     * @param string $err
     * @param int $timeout
     *
     * @return boolean
     */
    private function runProcess(Process $process, LoggerInterface $logger, $err, $timeout)
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException $ex) {
            $logger->critical($err, [
                'maxTimeout' => $timeout . ' seconds',
                'output' => $process->getOutput()
            ]);

            return false;
        }

        return true;
    }
}
