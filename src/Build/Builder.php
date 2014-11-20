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
     * @var string
     */
    const EVENT_MESSAGE = 'Run build command';
    const ERR_BUILDING_TIMEOUT = 'Build command took too long';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var PackageManagerPreparer
     */
    private $preparer;

    /**
     * Time (in seconds) to wait for the build to process before aborting
     *
     * @var int
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
     * @param string $buildPath
     * @param string $command
     * @param array $env
     * @return boolean
     */
    public function __invoke($buildPath, $command, array $env)
    {
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
            return false;
        }

        if ($process->isSuccessful()) {
            return $this->processSuccess($process);
        }

        return $this->processFailure($process);
    }

    /**
     * @var string $command
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
     * @return bool
     */
    private function processFailure(Process $process)
    {
        $this->logger->failure(self::EVENT_MESSAGE, [
            'command' => $process->getCommandLine(),
            'output' => $process->getOutput(),
            'errorOutput' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode()
        ]);

        return false;
    }

    /**
     * @param Process $process
     * @return bool
     */
    private function processSuccess(Process $process)
    {
        $this->logger->success(self::EVENT_MESSAGE, [
            'command' => $process->getCommandLine(),
            'output' => $process->getOutput()
        ]);

        return true;
    }
}
