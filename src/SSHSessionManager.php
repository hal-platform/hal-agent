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
use Symfony\Component\Filesystem\Filesystem;

class SSHSessionManager
{
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
     * @type Filesystem
     */
    private $filesystem;

    /**
     * Each entry is an array of [$username, $server, $privateKey]
     *
     * $server can be "*" for wildcard.
     *
     * Example:
     * [
     *     ['user',  'server', '/file/path/id_rsa'],
     *     ['user',  '*',      '/file/path/id_rsa'],
     *     ['user2', 'server', '/file/path/id_rsa']
     * ]
     * @type array
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
     * @param Filesystem $filesystem
     * @param string $credentials
     * @param callable $fileLoader
     */
    public function __construct(
        EventLogger $logger,
        Filesystem $filesystem,
        array $credentials = [],
        callable $fileLoader = null
    ) {
        $this->logger = $logger;
        $this->filesystem = $filesystem;
        $this->credentials = $credentials;

        if ($fileLoader === null) {
            $fileLoader = $this->getDefaultFileLoader();
        }

        $this->fileLoader = $fileLoader;

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

        // No credentials
        if (!$credentials = $this->findCredentials($user, $server)) {
            $this->logger->event('failure', self::ERR_NO_CREDENTIALS, $context);
            return null;
        }

        // No key found
        if (!$privateKey = $this->loadPrivateKey($credentials[2])) {
            $this->logger->event('failure', self::ERR_MISSING_PRIVATE_KEY, $context);
            return null;
        }

        list($server, $port) = $this->parseServer($server);

        $ssh = new Net_SSH2($server, $port);

        $command = [$ssh, 'login'];
        $args = [$user, $privateKey];
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
     * @param string $user
     * @param string $server
     *
     * @return array|null
     */
    private function findCredentials($user, $server)
    {
        if (count($this->credentials) === 0) {
            return null;
        }

        // filters
        $filterUser = $this->filterUserCredentials($user);
        $filterServer = $this->filterServerCredentials($server);

        // Find all username matches
        // No results, fail
        if (!$matchingUserCredentials = array_filter($this->credentials, $filterUser)) {
            return null;
        }

        // Find server matches
        // No results, fail
        if (!$matchingServerCredentials = array_filter($matchingUserCredentials, $filterServer)) {
            return null;
        }

        // Find exact servername match
        foreach ($matchingServerCredentials as $credentials) {
            if ($credentials[1] !== '*') {
                return $credentials;
            }
        }

        // otherwise pop the top credential
        return array_pop($matchingServerCredentials);
    }

    /**
     * @param string $keyPath
     *
     * @return Crypt_RSA|null
     */
    private function loadPrivateKey($keyPath)
    {
        if (!$this->filesystem->exists($keyPath)) {
            return null;
        }

        $privateKey = call_user_func($this->fileLoader, $keyPath);
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
     * @param string $matchingUser
     *
     * @return callable
     */
    private function filterUserCredentials($matchingUser)
    {
        return function($credentials) use ($matchingUser) {
            if (!is_array($credentials) || count($credentials) !== 3) {
                // invalid credentials
                return false;
            }

            // first entry is username
            $username = $credentials[0];
            return ($username == $matchingUser);
        };
    }

    /**
     * @param string $matchingServer
     *
     * @return callable
     */
    private function filterServerCredentials($matchingServer)
    {
        return function($credentials) use ($matchingServer) {
            // Passed user filter first, so we know credentials schema is correct

            // second entry is server
            $server = $credentials[1];
            return ($server == $matchingServer || $server === '*');
        };
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

        $port = 22;
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

    private function getDefaultFileLoader()
    {
        return 'file_get_contents';
    }
}
