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
     * @return bool
     */
    public function __invoke($remoteUser, $remoteServer, $command, array $env, $isLoggingEnabled = true, $prefixCommand = null, $customMessage = '')
    {
        if ($prefixCommand) {
            $command = $command . ' ' . $prefixCommand;
        }

        $context = $this->createCommand($remoteUser, $remoteServer, $command);
        if ($prefixCommand) {
            $context->withSanitized($command);
        }

        return $this->run($context, $env, [$isLoggingEnabled, $customMessage]);
    }

    /**
     * Note:
     * Commands are not escaped or sanitized, and must be done first with the ->sanitize() method.
     *
     * @param CommandContext $command
     * @param array $env
     * @param array $loggingContext
     *                [$alwaysLog=true, $customMessage='']
     *
     * @return bool
     */
    public function run(CommandContext $command, array $env, array $loggingContext = [])
    {
        $this->resetStatus();
        $alwaysLog = (count($loggingContext) > 0) ? array_shift($loggingContext) : true;
        $errorMessage = (count($loggingContext) > 0) ? array_shift($loggingContext) : self::EVENT_MESSAGE;

        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($command->username(), $command->server())) {
            return false;
        }

        $this->runCommand($ssh, $command, $env);

        // timed out, bad exit
        if ($ssh->isTimeout() || $ssh->getExitStatus()) {
            if ($alwaysLog) {
                $errorMessage = $ssh->isTimeout() ? self::ERR_COMMAND_TIMEOUT : $message;
                $this->logLastCommandAsError($errorMessage, $command);
            }

            return false;
        }

        // log if enabled
        if ($alwaysLog) {
            $this->logLastCommandAsSuccess($errorMessage, $command);
        }

        // all good
        return true;
    }

    /**
     * Note:
     * Commands are not escaped or sanitized, and must be done first with the ->sanitize() method.
     *
     * @param CommandContext $command
     * @param array $env
     * @param array $loggingContext
     *                [$forceLogging=false, $customMessage='']
     *
     * @return bool
     */
    public function runWithLoggingOnFailure(CommandContext $command, array $env, array $loggingContext = [])
    {
        $this->resetStatus();
        $forceLogging = (count($loggingContext) > 0) ? array_shift($loggingContext) : false;
        $errorMessage = (count($loggingContext) > 0) ? array_shift($loggingContext) : self::EVENT_MESSAGE;

        // No session could be started/resumed
        if (!$ssh = $this->sshManager->createSession($command->username(), $command->server())) {
            return false;
        }

        $this->runCommand($ssh, $command, $env);

        // timed out, bad exit
        if ($ssh->isTimeout() || $ssh->getExitStatus()) {
            $errorMessage = $ssh->isTimeout() ? self::ERR_COMMAND_TIMEOUT : $errorMessage;
            $this->logLastCommandAsError($errorMessage, $command);

            return false;
        }

        // log if enabled
        if ($forceLogging) {
            $this->logLastCommandAsSuccess($errorMessage, $command);
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
     * @param string $username
     * @param string $server
     * @param string|array $command
     *
     * @return CommandContext
     */
    public function createCommand($username, $server, $command)
    {
        return new CommandContext($username, $server, $command);
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
     * @param CommandContext $command
     * @param array $env
     *
     * @return void
     */
    private function runCommand(Net_SSH2 $sshSession, CommandContext $command, array $env = [])
    {
        $actual = $command->command();

        // Add environment variables if possible
        if ($envSetters = $this->formatEnvSetters($env)) {
            $actual = implode(' && ', [$envSetters, $actual]);
        }

        $sshSession->setTimeout($this->commandTimeout);

        if ($command->isInteractive()) {
            // Enable PTY for pretty colors
            $sshSession->enablePTY();
        }

        $sshSession->exec($actual);

        $this->lastOutput = $sshSession->read();
        $this->lastErrorOutput = $sshSession->getStdError();
        $this->lastExitCode = $sshSession->getExitStatus();
    }

    /**
     * @param string $message
     * @param CommandContext $command
     *
     * @return void
     */
    private function logLastCommandAsError($message, CommandContext $command)
    {
        $sanitized = $command->sanitized() ? $command->sanitized() : $command->command();

        $context = array_merge($this->getLastStatus(), [
            'command' => $sanitized
        ]);

        $this->logger->event('failure', $message, $context);
    }

    /**
     * @param string $message
     * @param CommandContext $command
     *
     * @return void
     */
    private function logLastCommandAsSuccess($message, CommandContext $command)
    {
        $sanitized = $command->sanitized() ? $command->sanitized() : $command->command();

        $this->logger->event('success', $message, [
            'command' => $sanitized,
            'output' => $this->lastOutput
        ]);
    }
}
