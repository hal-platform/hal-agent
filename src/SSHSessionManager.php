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

        $this->activeSessions = [];
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

        $ssh = new Net_SSH2($server);

        // Suppress errors because this lib still has PHP4 support so it has shitty error handling.
        $isLoggedIn = @$ssh->login($user, $privateKey);

        // Login failure
        if (!$isLoggedIn) {
            // Suppress errors because this lib still has PHP4 support so it has shitty error handling.
            @$ssh->disconnect();

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
        foreach ($this->activeSessions as $session) {
            // Suppress errors because this lib still has PHP4 support so it has shitty error handling.
            @$session->disconnect();
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
        foreach ($matchingServerCredentials as $credential) {
            if ($credential[2] !== '*') {
                return $credential;
            }
        }

        // otherwise pop the top credential
        return $matchingServerCredentials[0];
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
        $key->loadKey($privateKey);

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

    private function getDefaultFileLoader()
    {
        return 'file_get_contents';
    }
}
