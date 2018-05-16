<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Remoting;

use phpseclib\Net\SSH2;
use Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\ProcessUtils;

/**
 * This class got too big :(
 *
 * Need to break out __invoke into smaller pieces
 */
class SSHProcess
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Run remote command';
    const ERR_COMMAND_TIMEOUT = 'Remote command took too long';

    const MAX_OUTPUT_SIZE = 1000000; // 1 mb

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSHSessionManager
     */
    private $sshManager;

    /**
     * @var int
     */
    private $commandTimeout;

    /**
     * @var string
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
                $errorMessage = $ssh->isTimeout() ? self::ERR_COMMAND_TIMEOUT : $errorMessage;
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
        $parameters = array_filter($parameters, function ($v) {
            return (trim($v) !== '');
        });

        // manually escape user supplied command
        $parameters = array_map(function ($v) {
            return $this->escapeArgument($v);
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
     * @return array
     */
    public function getLastStatus(): array
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
            $envSetters[] = sprintf('export %s=%s', $property, $this->escapeArgument($value));
        }

        return implode(' ', $envSetters);
    }

    /**
     * @param SSH2 $sshSession
     * @param CommandContext $command
     * @param array $env
     *
     * @return void
     */
    private function runCommand(SSH2 $sshSession, CommandContext $command, array $env = [])
    {
        $actual = $command->command();

        // Add environment variables if possible
        if ($envSetters = $this->formatEnvSetters($env)) {
            $actual = implode(' && ', [$envSetters, $actual]);
        }

        $sshSession->setTimeout($this->commandTimeout);

        // Quiet mode ensures stdout and stderr remain separate.
        // DO NOT COMBINE WITH ENABLEPTY
        $sshSession->enableQuietMode();

        $output = $sshSession->exec($actual);

        $this->lastOutput = $output;
        $this->lastErrorOutput = $sshSession->getStdError();
        $this->lastExitCode = $sshSession->getExitStatus();

        // just a dumb sanity check.
        if (strlen($this->lastOutput) > self::MAX_OUTPUT_SIZE) {
            $this->lastOutput = substr($this->lastOutput, 0, self::MAX_OUTPUT_SIZE);
        }

        if (strlen($this->lastErrorOutput) > self::MAX_OUTPUT_SIZE) {
            $this->lastErrorOutput = substr($this->lastErrorOutput, 0, self::MAX_OUTPUT_SIZE);
        }
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

        $context = array_merge([
            'command' => $sanitized
        ], $this->getLastStatus());

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

    /**
     * Stolen from Symfony\Component\Process\ProcessUtils::escapeArgument ~3.4
     *
     * @param string $argument
     *
     * @return string
     */
    private function escapeArgument($argument)
    {
        //Fix for PHP bug #43784 escapeshellarg removes % from given string
        //Fix for PHP bug #49446 escapeshellarg doesn't work on Windows
        //@see https://bugs.php.net/bug.php?id=43784
        //@see https://bugs.php.net/bug.php?id=49446
        if ('\\' === DIRECTORY_SEPARATOR) {
            if ('' === $argument) {
                return escapeshellarg($argument);
            }
            $escapedArgument = '';
            $quote = false;
            foreach (preg_split('/(")/', $argument, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE) as $part) {
                if ('"' === $part) {
                    $escapedArgument .= '\\"';
                } elseif ($this->isSurroundedBy($part, '%')) {
                    // Avoid environment variable expansion
                    $escapedArgument .= '^%"'.substr($part, 1, -1).'"^%';
                } else {
                    // escape trailing backslash
                    if ('\\' === substr($part, -1)) {
                        $part .= '\\';
                    }
                    $quote = true;
                    $escapedArgument .= $part;
                }
            }
            if ($quote) {
                $escapedArgument = '"'.$escapedArgument.'"';
            }
            return $escapedArgument;
        }
        return "'".str_replace("'", "'\\''", $argument)."'";
    }

    /**
     * Stolen from Symfony\Component\Process\ProcessUtils::isSurroundedBy ~3.4
     *
     * @param string $arg
     * @param string $char
     *
     * @return bool
     */
    private function isSurroundedBy($arg, $char)
    {
        return 2 < strlen($arg) && $char === $arg[0] && $char === $arg[strlen($arg) - 1];
    }
}
