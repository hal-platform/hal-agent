<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

use Crypt_RSA;
use Net_SSH2;
use QL\Hal\Agent\Logger\EventLogger;

class SSHSessionManager
{
    const DEFAULT_SSH_PORT = 22;
    const ERR_CONNECT_SERVER = 'Failed to connect to server. It may be down.';
    const ERR_MISSING_PRIVATE_KEY = 'Failed to connect to server. Private key is missing.';
    const ERR_NO_CREDENTIALS = 'Failed to connect to server. No valid credentials configured.';

    /**
     * Stolen from Symfony ErrorHandler
     */
    public static $errorLevels = [
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated',
        E_NOTICE => 'Notice',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Runtime Notice',
        E_WARNING => 'Warning',
        E_USER_WARNING => 'User Warning',
        E_COMPILE_WARNING => 'Compile Warning',
        E_CORE_WARNING => 'Core Warning',
        E_USER_ERROR => 'User Error',
        E_RECOVERABLE_ERROR => 'Catchable Fatal Error',
        E_COMPILE_ERROR => 'Compile Error',
        E_PARSE => 'Parse Error',
        E_ERROR => 'Error',
        E_CORE_ERROR => 'Core Error',
    ];

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type CredentialWallet
     */
    private $credentials;

    /**
     * An array of active Net_SSH2 sessions
     *
     * Example:
     * [
     *     'user1@server' => new Net_SSH2,
     *     'user2@server' => new Net_SSH2,
     * ]
     *
     * @type array
     */
    private $activeSessions;

    /**
     * A store to put errors from phpseclib
     *
     * @type array
     */
    private $errors;

    /**
     * @param EventLogger $logger
     * @param CredentialWallet $credentials
     */
    public function __construct(
        EventLogger $logger,
        CredentialWallet $credentials
    ) {
        $this->logger = $logger;
        $this->credentials = $credentials;

        $this->activeSessions = $this->errors = [];
    }

    /**
     * @param string $user
     * @param string $server
     *
     * @return Net_SSH2|null
     */
    public function createSession($user, $server)
    {
        $pair = sprintf('%s@%s', $user, $server);
        if (isset($this->activeSessions[$pair])) {
            return $this->activeSessions[$pair];
        }

        $context = [
            'user' => $user,
            'server' => $server
        ];

        list($server, $port) = $this->parseServer($server);

        // No credentials
        if (!$credential = $this->credentials->findCredential($user, $server)) {
            $this->logger->event('failure', self::ERR_NO_CREDENTIALS, $context);
            return null;
        }

        if ($credential->isKeyCredential()) {
            $sshCredential = $this->loadPrivateKey($credential->privateKey());

            // No key found
            if ($sshCredential === null) {
                $this->logger->event('failure', self::ERR_MISSING_PRIVATE_KEY, $context);
                return null;
            }
        } else {
            $sshCredential = $credential->password();
        }

        $ssh = new Net_SSH2($server, $port);

        $command = [$ssh, 'login'];
        $args = [$user, $sshCredential];
        $isLoggedIn = $this->runCommandWithErrorHandling($command, $args);

        // Login failure
        if (!$isLoggedIn) {
            $this->runCommandWithErrorHandling([$ssh, 'disconnect']);

            $this->errors = array_merge($this->errors, $ssh->getErrors());
            if ($this->errors) {
                $context['errors'] = $this->errors;
            }

            $this->logger->event('failure', self::ERR_CONNECT_SERVER, $context);
            return null;
        }

        // Save active session
        $this->activeSessions[$pair] = $ssh;

        return $ssh;
    }

    /**
     * Manually disconnect all active sessions
     *
     * @return null
     */
    public function disconnectAll()
    {
        foreach ($this->activeSessions as $ssh) {
            $this->runCommandWithErrorHandling([$ssh, 'disconnect']);
        }

        $this->activeSessions = [];
    }

    /**
     * @param string|null $privateKey
     *
     * @return Crypt_RSA|null
     */
    private function loadPrivateKey($privateKey)
    {
        if ($privateKey === null) {
            return null;
        }

        $key = new Crypt_RSA;

        $command = [$key, 'loadKey'];
        $args = [$privateKey];

        $isValid = $this->runCommandWithErrorHandling($command, $args);

        if (!$isValid) {
            return null;
        }

        return $key;
    }

    /**
     * @param callable $command
     * @param array $args
     *
     * @return mixed
     */
    private function runCommandWithErrorHandling(callable $command, array $args = [])
    {
        $this->errors = [];

        // Set custom handler
        set_error_handler([$this, 'recordError'], \E_ALL);

        // Run phpseclib command
        $response = call_user_func_array($command, $args);

        // Restore previous handler
        restore_error_handler();

        return $response;
    }

    /**
     * Parse servername or servername:port into an array containing [$server, $port]
     *
     * @param string $server
     *
     * @return array
     */
    private function parseServer($server)
    {
        $exploded = explode(':', $server);

        $servername = array_shift($exploded);

        $port = self::DEFAULT_SSH_PORT;
        if ($exploded) {
            $port = (int) array_shift($exploded);
        }

        return [$servername, $port];
    }

    /**
     * Custom error handler to wrap and capture notices from phpseclib
     */
    public function recordError($errno, $errstr, $errfile = '', $errline = 0, array $errcontext = [])
    {
        $this->errors[] = sprintf('SSH %s: %s', static::$errorLevels[$errno], $errstr);
        return true;
    }
}