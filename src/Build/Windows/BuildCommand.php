<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build\Windows;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

/**
 * C:\Windows\Microsoft.NET\Framework\v2.0.50727\MSBuild.exe
 * C:\Windows\Microsoft.NET\Framework\v3.5\MSBuild.exe
 * C:\Windows\Microsoft.NET\Framework\v4.0.30319\MSBuild.exe
 */
class BuildCommand
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run build command';
    const ERR_BUILDING_TIMEOUT = 'Build command took too long';

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

            $process = $this->processBuilder
                ->setWorkingDirectory($buildPath)
                ->setArguments($command)
                ->addEnvironmentVariables($env)
                ->setTimeout($this->commandTimeout)
                ->getProcess();

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
