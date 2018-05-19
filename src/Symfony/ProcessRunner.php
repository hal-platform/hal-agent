<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Symfony;

use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class ProcessRunner
{
    private const DEFAULT_TIMEOUT_SECONDS = 60.0;

    /**
     * @var EventLogger|null
     */
    private $logger;

    /**
     * @var float
     */
    private $defaultTimeout;

    /**
     * @param EventLogger|null $logger
     */
    public function __construct(?EventLogger $logger = null)
    {
        $this->logger = $logger;

        $this->defaultTimeout = self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * @param array $args
     * @param string|null $workingDirectory
     * @param float|null $timeout
     *
     * @return Process
     */
    public function prepare(array $args, ?string $workingDirectory, ?float $timeout = null): Process
    {
        if ($timeout === null) {
            $timeout = $this->defaultTimeout;
        }

        $process = new Process($args, $workingDirectory);
        $process->setTimeout($timeout);

        return $process;
    }

    /**
     * @param Process $process
     * @param string $args
     * @param string|null $timeoutMessage
     *
     * @return bool
     */
    public function run(Process $process, string $args, ?string $timeoutMessage = null): bool
    {
        try {
            $process->run();
        } catch (ProcessTimedOutException $ex) {
            return $this->onTimeout($process, $args, $ex->getExceededTimeout(), $timeoutMessage);
        }

        return true;
    }

    /**
     * @param Process $process
     * @param string $args
     * @param string|null $successMessage
     *
     * @return bool
     */
    public function onSuccess(Process $process, string $args, ?string $successMessage = null): bool
    {
        if ($successMessage) {
            $this->tryLogging('success', $successMessage, [
                'command' => $args,
                'output' => $process->getOutput()
            ]);
        }

        return true;
    }

    /**
     * @param Process $process
     * @param string $args
     * @param string|null $failureMessage
     *
     * @return bool
     */
    public function onFailure(Process $process, string $args, ?string $failureMessage = null): bool
    {
        if ($failureMessage) {
            $this->tryLogging('failure', $failureMessage, [
                'command' => $args,
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
                'exitCode' => $process->getExitCode()
            ]);
        }

        return false;
    }

    /**
     * @param Process $process
     * @param string $args
     * @param float $timedOutAt
     * @param string|null $timeoutMessage
     *
     * @return bool
     */
    public function onTimeout(Process $process, string $args, float $timedOutAt, ?string $timeoutMessage = null): bool
    {
        if ($timeoutMessage) {
            $this->tryLogging('failure', $timeoutMessage, [
                'command' => $args,
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
                'maxTimeout' => $timedOutAt
            ]);
        }

        return false;
    }

    /**
     * @param string $level
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    private function tryLogging(string $level, string $message, array $context)
    {
        if (!$this->logger) {
            return;
        }

        $this->logger->event($level, $message, $context);
    }
}
