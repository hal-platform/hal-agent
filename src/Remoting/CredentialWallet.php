<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Remoting;

class CredentialWallet
{
    /**
     * @var Credentials[]
     */
    private $credentials;

    /**
     * @param Credentials[] $credentials
     */
    public function __construct(array $credentials = [])
    {
        $this->credentials = [];

        foreach ($credentials as $cred) {
            if ($cred instanceof Credential) {
                $this->credentials[] = $cred;

            } else {
                // Silently skip invalids
            }
        }
    }

    /**
     * @param Credentials $credential
     *
     * @return void
     */
    public function addCredential(Credential $credential)
    {
        $this->credentials[] = $credential;
    }

    /**
     * @param string $username
     * @param string $server
     * @param string $credential
     *
     * @return void
     */
    public function importCredential($username, $server, $credential)
    {
        $cred = new Credential($username, $server, $credential);
        $this->addCredential($cred);
    }

    /**
     * @param array $credentials
     *
     * @return void
     */
    public function importCredentials(array $credentials)
    {
        foreach ($credentials as $credential) {
            call_user_func_array([$this, 'importCredential'], $credential);
        }
    }

    /**
     * @param string $user
     * @param string $server
     *
     * @return Credential|null
     */
    public function findCredential($user, $server)
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
            if ($credentials->server() !== '*') {
                return $credentials;
            }
        }

        // otherwise get the top credential
        return array_shift($matchingServerCredentials);
    }

    /**
     * @param string $matchingUser
     *
     * @return callable
     */
    private function filterUserCredentials($matchingUser)
    {
        return function(Credential $credential) use ($matchingUser) {
            return ($credential->username() == $matchingUser);
        };
    }

    /**
     * @param string $matchingServer
     *
     * @return callable
     */
    private function filterServerCredentials($matchingServer)
    {
        return function(Credential $credential) use ($matchingServer) {
            if ($credential->server() === '*') {
                return true;
            }

            return ($credential->server() == $matchingServer);
        };
    }
}
