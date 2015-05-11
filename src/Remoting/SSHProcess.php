<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

use Net_SSH2;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\ProcessUtils;

/**
 * This class got too big :(
 *
 * Need to break out __invoke into smaller pieces
 */
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
    private $lastErrorOutput;
    private $lastExitCode;

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

        $this->resetStatus();
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
        $this->resetStatus();

        $message = $customMessage ?: self::EVENT_MESSAGE;

        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            return false;
        }

        $this->runCommand($ssh, $command, $prefixCommand, $env);

        // timed out, bad exit
        if ($ssh->isTimeout() || $ssh->getExitStatus()) {
            if ($isLoggingEnabled) {
                $errorMessage = $ssh->isTimeout() ? self::ERR_COMMAND_TIMEOUT : $message;
                $this->logLastCommandAsError($errorMessage, $command);
            }

            return false;
        }

        // log if enabled
        if ($isLoggingEnabled) {
            $this->logLastCommandAsSuccess($message, $command);
        }

        // all good
        return true;
    }

    /**
     * Note:
     * Commands are not escaped or sanitized, and must be done first with the ->sanitize() method.
     *
     * @param string $remoteUser
     * @param string $remoteServer
     * @param string $command
     * @param array $env
     * @param bool $forceLogging
     * @param string $prefixCommand
     * @param string $customMessage
     *
     * @return boolean
     */
    public function runWithLoggingOnFailure($remoteUser, $remoteServer, $command, array $env, $forceLogging = false, $prefixCommand = null, $customMessage = '')
    {
        $this->resetStatus();

        $message = $customMessage ?: self::EVENT_MESSAGE;

        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($remoteUser, $remoteServer)) {
            return false;
        }

        $this->runCommand($ssh, $command, $prefixCommand, $env);

        // timed out, bad exit
        if ($ssh->isTimeout() || $ssh->getExitStatus()) {
            $errorMessage = $ssh->isTimeout() ? self::ERR_COMMAND_TIMEOUT : $message;
            $this->logLastCommandAsError($errorMessage, $command);

            return false;
        }

        // log if enabled
        if ($forceLogging) {
            $this->logLastCommandAsSuccess($message, $command);
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
     * @return string
     */
    public function getLastStatus()
    {
        return [
            'output' => $this->lastOutput,
            'errorOutput' => $this->lastErrorOutput,
            'exitCode' => $this->lastExitCode,
        ];
    }

    /**
     * @return void
     */
    private function resetStatus()
    {
        $this->lastOutput = '';
        $this->lastErrorOutput = '';
        $this->lastExitCode = '';
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

    /**
     * @param Net_SSH2 $sshSession
     * @param string $remoteCommand
     * @param string $prefixCommand
     * @param array $env
     *
     * @return void
     */
    private function runCommand(Net_SSH2 $sshSession, $remoteCommand, $prefixCommand, array $env = [])
    {
        if ($prefixCommand) {
            $remoteCommand = $prefixCommand . ' ' . $remoteCommand;
        }

        // Add environment variables if possible
        if ($envSetters = $this->formatEnvSetters($env)) {
            $remoteCommand = implode(' && ', [$envSetters, $remoteCommand]);
        }

        $sshSession->setTimeout($this->commandTimeout);

        // Enable PTY for pretty colors
        $sshSession->enablePTY();

        $sshSession->exec($remoteCommand);

        $this->lastOutput = $sshSession->read();
        $this->lastErrorOutput = $sshSession->getStdError();
        $this->lastExitCode = $sshSession->getExitStatus();
    }

    /**
     * @param string $message
     * @param string $command
     *
     * @return void
     */
    private function logLastCommandAsError($message, $command)
    {
        $context = array_merge($this->getLastStatus(), [
            'command' => $command
        ]);

        $this->logger->event('failure', $message, $context);
    }

    /**
     * @param string $message
     * @param string $command
     *
     * @return void
     */
    private function logLastCommandAsSuccess($message, $command)
    {
        $this->logger->event('success', $message, [
            'command' => $command,
            'output' => $this->lastOutput
        ]);
    }
}
