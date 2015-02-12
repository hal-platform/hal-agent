<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Unix;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Utility\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class Builder
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run build command';
    const ERR_TIMEOUT = 'Build command took too long';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * Time (in seconds) to wait for the build to process before aborting
     *
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $buildPath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($buildPath, array $commands, array $env)
    {
        foreach ($commands as $command) {
            $command = $this->sanitizeCommand($command);
            $dispCommand = implode(' ', $command);

            $process = $this->processBuilder
                ->setWorkingDirectory($buildPath)
                ->setArguments($command)
                ->addEnvironmentVariables($env)
                ->setTimeout($this->commandTimeout)
                ->getProcess();

            if (!$this->runProcess($process, $this->commandTimeout)) {
                // command timed out, bomb out
                return false;
            }

            if (!$process->isSuccessful()) {
                // Return immediately if one of the commands fails
                return $this->processFailure($dispCommand, $process);
            }

            // record build output
            $this->processSuccess($dispCommand, $process);
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
}
