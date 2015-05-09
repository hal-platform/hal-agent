<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\ProcessUtils;

class SSHProcess
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run remote command';
    const ERR_COMMAND_TIMEOUT = 'Remote command took too long';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type SSHSessionManager
     */
    private $sshManager;

    /**
     * @type int
     */
    private $commandTimeout;

    /**
     * @type string
     */
    private $lastOutput;

    /**
     * @param EventLogger $logger
     * @param SSHSessionManager $sshManager
     * @param int $commandTimeout
     */
    public function __construct(EventLogger $logger, SSHSessionManager $sshManager, $commandTimeout)
    {
        $this->logger = $logger;
        $this->sshManager = $sshManager;
        $this->commandTimeout = $commandTimeout;

        $this->lastOutput = '';
    }

    /**
     * Note:
     * Commands are not escaped or sanitized, and must be done first with the ->sanitize() method.
     *
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $command
     * @param array $env
     * @param bool $isLoggingEnabled
     * @param string $prefixCommand
     * @param string $customMessage
     *
     * @return boolean
     */
    public function __invoke($remoteUser, $remoteServer, $command, array $env, $isLoggingEnabled = true, $prefixCommand = null, $customMessage = '')
    {
        $this->lastOutput = '';

        $message = $customMessage ?: self::EVENT_MESSAGE;

        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            return false;
        }

        $remoteCommand = $command;
        if ($prefixCommand) {
            $remoteCommand = $prefixCommand . ' ' . $command;
        }

        // Add environment variables if possible
        if ($envSetters = $this->formatEnvSetters($env)) {
            $remoteCommand = implode(' && ', [$envSetters, $remoteCommand]);
        }

        $ssh->setTimeout($this->commandTimeout);
        $output = $ssh->exec($remoteCommand);
        $this->lastOutput = $output;

        // timed out
        if ($ssh->isTimeout()) {
            if ($isLoggingEnabled) {
                $this->logger->event('failure', self::ERR_COMMAND_TIMEOUT, [
                    'command' => $command,
                    'output' => $output,
                    'errorOutput' => $ssh->getStdError(),
                    'exitCode' => $ssh->getExitStatus()
                ]);
            }

            return false;
        }

        // bad exit
        if ($ssh->getExitStatus() !== 0) {
            if ($isLoggingEnabled) {
                $this->logger->event('failure', $message, [
                    'command' => $command,
                    'output' => $output,
                    'errorOutput' => $ssh->getStdError(),
                    'exitCode' => $ssh->getExitStatus()
                ]);
            }

            return false;
        }

        // log if enabled
        if ($isLoggingEnabled) {
            $this->logger->event('success', $message, [
                'command' => $command,
                'output' => $output
            ]);
        }

        // all good
        return true;
    }

    /**
     * @param string $command
     *
     * @return string
     */
    public function sanitize($command)
    {
        // parameterize the command
        $parameters = explode(' ', $command);

        // remove empty parameters
        $parameters = array_filter($parameters, function($v) {
            return (trim($v) !== '');
        });

        // manually escape user supplied command
        $parameters = array_map(function($v) {
            return ProcessUtils::escapeArgument($v);
        }, $parameters);

        // Combine user command back into string
        return implode(' ', $parameters);
    }

    /**
     * @return string
     */
    public function getLastOutput()
    {
        return $this->lastOutput;
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
            $envSetters[] = sprintf('export %s=%s', $property, ProcessUtils::escapeArgument($value));
        }

        return implode(' ', $envSetters);
    }
}
