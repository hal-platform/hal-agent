<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Github;

use Closure;
use Exception;
use Guzzle\Common\Event;
use Github\Client;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ArchiveApi
{
    /**
     * @var client
     */
    private $github;

    /**
     * @param Client $github
     */
    public function __construct(Client $github)
    {
        $this->github = $github;
    }

    /**
     * Get content of archives in a repository
     *
     * @link http://developer.github.com/v3/repos/contents/
     *
     * @param string $username   the user who owns the repository
     * @param string $repository the name of the repository
     * @param string $reference  reference to a branch or commit
     * @param string $target     where to download the file
     *
     * @return boolean
     */
    public function download($username, $repository, $reference, $target)
    {
        $path = sprintf(
            'repos/%s/%s/tarball/%s',
            rawurlencode($username),
            rawurlencode($repository),
            rawurlencode($reference)
        );

        $client = $this->github->getHttpClient();
        $response = $client->request($path, null, 'GET', [], ['allow_redirects'  => false]);

        if (302 !== $response->getStatusCode()) {
            throw new Exception('Unexpected response from github archive link');
        }

        $redirect = $response->getLocation();

        $listener = $this->setResponseBody($target);
        $client->addListener('request.before_send', $listener);
        $client->addListener('request.complete', $this->onCompletion($listener));

        $response = $client->get($redirect);

        return $response->isSuccessful();
    }

    /**
     * @param string $target
     *
     * @return Closure
     */
    private function setResponseBody($target)
    {
        return function (Event $event) use ($target) {
            if (!$event['request']) {
                return;
            }

            $event['request']->setResponseBody($target);
        };
    }

    /**
     * @param Closure $listener
     *
     * @return Closure
     */
    private function onCompletion(Closure $listener)
    {
        return function (Event $event, $name, EventDispatcherInterface $dispatcher) use ($listener) {
            $dispatcher->removeListener('request.before_send', $listener);
        };
    }
}
