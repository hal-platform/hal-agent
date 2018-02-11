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
    private const DEFAULT_MESSAGE = 'System action completed';
    private const DEFAULT_ERR_MESSAGE = 'System action failed';
    private const DEFAULT_ERR_TIMEOUT = 'System action timed out';

    private const DEFAULT_TIMEOUT_SECONDS = 60;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var int
     */
    private $defaultTimeout;

    /**
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;

        $this->defaultTimeout = self::DEFAULT_TIMEOUT_SECONDS;
    }

    /**
     * @param array $args
     * @param string|null $workingDirectory
     * @param int|null $timeout
     *
     * @return Process
     */
    public function prepare(array $args, ?string $workingDirectory, ?int $timeout = null): Process
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
     * @param string|null $timeoutMessage
     *
     * @return bool
     */
    public function run(Process $process, ?string $timeoutMessage = null): bool
    {
        if ($timeoutMessage === null) {
            $timeoutMessage = self::DEFAULT_ERR_TIMEOUT;
        }

        try {
            $process->run();

        } catch (ProcessTimedOutException $ex) {
            $this->logger->event('failure', $timeoutMessage, [
                'maxTimeout' => sprintf('%d seconds', $ex->getExceededTimeout()),
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput()
            ]);

            return false;
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
        if ($successMessage === null) {
            $successMessage = self::DEFAULT_MESSAGE;
        }

        $this->logger->event('success', $successMessage, [
            'command' => $args,
            'output' => $process->getOutput()
        ]);

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
        if ($failureMessage === null) {
            $failureMessage = self::DEFAULT_ERR_MESSAGE;
        }

        $this->logger->event('failure', $failureMessage, [
            'command' => $args,
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode()
        ]);

        return false;
    }
}
