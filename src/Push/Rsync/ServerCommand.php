<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\Rsync;

use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\ProcessRunnerTrait;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;
use Symfony\Component\Process\ProcessUtils;

class ServerCommand
{
    use ProcessRunnerTrait;

    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run server command';
    const ERR_COMMAND_TIMEOUT = 'Server command took too long';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ProcessBuilder
     */
    private $processBuilder;

    /**
     * @type string
     */
    private $sshUser;

    /**
     * @type int
     */
    private $commandTimeout;

    /**
     * @param EventLogger $logger
     * @param ProcessBuilder $processBuilder
     * @param string $sshUser
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, ProcessBuilder $processBuilder, $sshUser, $commandTimeout)
    {
        $this->logger = $logger;
        $this->processBuilder = $processBuilder;
        $this->sshUser = $sshUser;
        $this->commandTimeout = $commandTimeout;
    }

    /**
     * @param string $hostname
     * @param string $remotePath
     * @param array $commands
     * @param array $env
     *
     * @return boolean
     */
    public function __invoke($hostname, $remotePath, array $commands, array $env)
    {
        foreach ($commands as $command) {
            $serverCommand = $this->sanitizeCommand($command);

            // Add environment variables if possible
            if ($envSetters = $this->formatEnvSetters($env)) {
                $serverCommand = implode(' ', [$envSetters, $serverCommand]);
            }

            // move to the application directory before command is executed
            $remoteCommand = implode(' && ', [
                sprintf('cd %s', $remotePath),
                $serverCommand
            ]);

            $command = implode(' ', [
                'ssh',
                sprintf('%s@%s', $this->sshUser, $hostname),
                sprintf('"%s"', $remoteCommand)
            ]);

            $process = $this->processBuilder
                ->setWorkingDirectory(null)
                ->setArguments([''])
                ->setTimeout($this->commandTimeout)
                ->getProcess();

            $process->setCommandLine($command);

            if (!$this->runProcess($process, $this->logger, self::ERR_COMMAND_TIMEOUT, $this->commandTimeout)) {
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
     * @param array $env
     *
     * @return string
     */
    private function formatEnvSetters(array $env)
    {
        $envSetters = [];
        foreach ($env as $property => $value) {
            $envSetters[] = sprintf('%s=%s', $property, ProcessUtils::escapeArgument($value));
        }

        return implode(' ', $envSetters);
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

        // manually escape user supplied command
        $parameters = array_map(['Symfony\Component\Process\ProcessUtils', 'escapeArgument'], $parameters);

        // Combine user command back into string
        return implode(' ', $parameters);
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
