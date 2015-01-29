<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent;

use Crypt_RSA;
use Net_SSH2;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Process\ProcessUtils;

class RemoteProcess
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Run remote command';
    const ERR_COMMAND_TIMEOUT = 'Remote command took too long';
    const ERR_CONNECT_SERVER = 'Failed to connect to server. It may be down.';

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type string
     */
    private $remoteUser;

    /**
     * @type string
     */
    private $sshKeyPath;

    /**
     * @type int
     */
    private $commandTimeout;

    /**
     * @type Net_SSH2|null
     */
    private $session;

    /**
     * @type string
     */
    private $lastOutput;

    /**
     * @param EventLogger $logger
     * @param string $remoteUser
     * @param string $sshKeyPath
     * @param int $commandTimeout
     */
    public function __construct(
        EventLogger $logger,
        $remoteUser,
        $sshKeyPath,
        $commandTimeout
    ) {
        $this->logger = $logger;
        $this->remoteUser = $remoteUser;
        $this->sshKeyPath = $sshKeyPath;
        $this->commandTimeout = $commandTimeout;

        $this->session = null;
        $this->lastOutput = '';
    }

    /**
     * Note:
     * Commands are not escaped or sanitized, and must be done first with the ->sanitize() method.
     *
     * @param string $remoteServer
     * @param string $command
     * @param array $env
     * @param bool $isLoggingEnabled
     * @param string $prefixCommand
     * @param string $customMessage
     *
     * @return boolean
     */
    public function __invoke($remoteServer, $command, array $env, $isLoggingEnabled = true, $prefixCommand = null, $customMessage = '')
    {
        $this->lastOutput = '';

        $message = $customMessage ?: self::EVENT_MESSAGE;

        // No session exists yet
        if ($this->session === null) {
            $this->session = $this->createSession($remoteServer);
        }

        // Not logged in
        if ($this->session === null) {
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

        $this->session->setTimeout($this->commandTimeout);
        $output = $this->session->exec($remoteCommand);
        $this->lastOutput = $output;

        // timed out
        if ($this->session->isTimeout()) {
            if ($isLoggingEnabled) {
                $this->logger->event('failure', self::ERR_COMMAND_TIMEOUT, [
                    'command' => $command,
                    'output' => $output,
                    'errorOutput' => $this->session->getStdError(),
                    'exitCode' => $this->session->getExitStatus()
                ]);
            }

            return false;
        }

        // bad exit
        if ($this->session->getExitStatus() !== 0) {
            if ($isLoggingEnabled) {
                $this->logger->event('failure', $message, [
                    'command' =>$command,
                    'output' => $output,
                    'errorOutput' => $this->session->getStdError(),
                    'exitCode' => $this->session->getExitStatus()
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
     * @param string $remoteServer
     *
     * @return Net_SSH2|null
     */
    public function createSession($remoteServer)
    {
        $ssh = new Net_SSH2($remoteServer);

        // @todo symfony filesystem
        $privateKey = file_get_contents($this->sshKeyPath);

        $key = new Crypt_RSA;
        $key->loadKey($privateKey);

        $isLoggedIn = @$ssh->login($this->remoteUser, $key);
        if ($isLoggedIn) {
            return $ssh;
        }

        $this->logger->event('failure', self::ERR_CONNECT_SERVER, [
            'server' => $remoteServer
        ]);

        return null;
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
