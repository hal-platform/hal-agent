<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Builder
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run build command';
    const ERR_INVALID_SYSTEM = 'Invalid build environment specified';
    const ERR_BUILDING_TIMEOUT = 'Build command took too long';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type PackageManagerPreparer
     */
    private $preparer;

    /**
     * Time (in seconds) to wait for the build to process before aborting
     *
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param PackageManagerPreparer $preparer
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, PackageManagerPreparer $preparer, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->preparer = $preparer;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $system
     * @param string $buildPath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($system, $buildPath, array $commands, array $env)
    {
        if ($system !== 'global') {
            $this->logger->event('failure', self::ERR_INVALID_SYSTEM);
            return false;
        }

        foreach ($commands as $command) {
            $command = $this->sanitizeCommand($command);

            $process = $this->processBuilder
                ->setWorkingDirectory($buildPath)
                ->setArguments($command)
                ->addEnvironmentVariables($env)
                ->setTimeout($this->commandTimeout)
                ->getProcess();

            // prepare package manager configuration
            call_user_func($this->preparer, $env);

            if (!$this->runProcess($process, $this->logger, self::ERR_BUILDING_TIMEOUT, $this->commandTimeout)) {
                // command timed out, bomb out
                return false;
            }

            if (!$process->isSuccessful()) {
                // Return immediately if one of the commands fails
                return $this->processFailure($process);
            }

            // record build output
            $this->processSuccess($process);
        }

        // all good
        return true;
    }

    /**
     * @param string $command
     *
     * @return string
     */
    private function sanitizeCommand($command)
    {
        // parameterize the command
        $parameters = explode(' ', $command);

        // remove empty parameters
        $parameters = array_filter($parameters, function($v) {
            return (trim($v) !== '');
        });

        // collapse array elements
        return array_values($parameters);
    }

    /**
     * @param Process $process
     *
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
     *
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
