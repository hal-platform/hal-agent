<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Remoting;

use Exception;

class Credential
{
    const KEY_PREFIX = 'key:';
    const PASS_PREFIX = 'pass:';
    const DEFAULT_SSH_PORT = 22;
    const ERR_INVALID_CREDENTIAL = 'Invalid credential specified. Must be "key" or "pass".';

    /**
     * Username
     *
     * @type string
     */
    private $username;

    /**
     * Server name, or "*" for wildcard
     *
     * @type string
     */
    private $server;

    /**
     * @type string
     */
    private $port;

    /**
     * @type string|null
     */
    private $keyPath;

    /**
     * @type string|null
     */
    private $password;

    /**
     * @type callable
     */
    private $keyFetcher;

    /**
     * @param string $username
     * @param string $server
     * @param string $credential
     *        Must be one of the following:
     *        - "key:/path/to/key"
     *        - "password:enter_password_here"
     */
    public function __construct($username, $server, $credential)
    {
        $this->username = $username;

        list($server, $port) = $this->parseServer($server);
        $this->server = $server;
        $this->port = $port;

        $this->keyPath = null;
        $this->password = null;

        if (substr($credential, 0, 4) === self::KEY_PREFIX) {
            $this->keyPath = substr($credential, 4);
        } elseif (substr($credential, 0, 5) === self::PASS_PREFIX) {
            $this->password = substr($credential, 5);
        } else {
            throw new Exception(self::ERR_INVALID_CREDENTIAL);
        }
    }

    /**
     * @return string
     */
    public function username()
    {
        return $this->username;
    }

    /**
     * @return string
     */
    public function server()
    {
        return $this->server;
    }

    /**
     * @return string
     */
    public function port()
    {
        return $this->port;
    }

    /**
     * @return string|null
     */
    public function password()
    {
        return $this->password;
    }

    /**
     * @return string|null
     */
    public function privateKey()
    {
        if ($this->keyPath === null) {
            return null;
        }

        if ($this->keyFetcher === null) {
            $this->keyFetcher = $this->getDefaultKeyFetcher();
        }

        return call_user_func($this->keyFetcher, $this->keyPath);
    }

    /**
     * @return bool
     */
    public function isPasswordCredential()
    {
        return is_string($this->password);
    }

    /**
     * @return bool
     */
    public function isKeyCredential()
    {
        return is_string($this->keyPath);
    }

    /**
     * @param callable $fetcher
     *
     * @return void
     */
    public function setKeyFetcher(callable $fetcher)
    {
        $this->keyFetcher = $fetcher;
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
     * @return callable
     */
    private function getDefaultKeyFetcher()
    {
        return function($keyPath) {

            if (!is_readable($keyPath) || !is_file($keyPath)) {
                return null;
            }

            return file_get_contents($keyPath);
        };
    }
}
