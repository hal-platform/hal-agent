<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Github;

use Github\HttpClient\Plugin\PathPrepend;
use GuzzleHttp\Psr7\LazyOpenStream;
use Http\Client\Common\HttpMethodsClient;
use Http\Client\Common\Plugin\RedirectPlugin;
use Psr\Http\Message\ResponseInterface;

class ArchiveApi
{
    /**
     * @var EnterpriseClient
     */
    private $github;

    /**
     * @param EnterpriseClient $github
     */
    public function __construct(EnterpriseClient $github)
    {
        $this->github = $github;
    }

    /**
     * Get content of archives in a repository
     *
     * @link http://developer.github.com/v3/repos/contents/
     *
     * @param string $username the user who owns the repository
     * @param string $repository the name of the repository
     * @param string $reference reference to a branch or commit
     * @param string $target where to download the file
     *
     * @return bool
     * @throws GitHubException
     */
    public function download($username, $repository, $reference, $target)
    {
        $path = sprintf(
            '/api/%s/repos/%s/%s/tarball/%s',
            rawurlencode($this->github->getApiVersion()),
            rawurlencode($username),
            rawurlencode($repository),
            rawurlencode($reference)
        );

        /*
         * Context on why we are messing with the plugins below
         *
         * see @link https://developer.github.com/v3/repos/contents/#get-archive-link
         * On how we download archive's from github.
         *
         * Our github enterprise archive links are not on a subdomain like they are on
         * gihtub.com, they are under http://git/_codeload example uri-template:
         *
         *     http://git/_codeload/{username}/{repository}/legacy.tar.gz/master
         *
         * However the knplabs github client if configured with an enterprise url will always try and
         * prepend '/api/v3' to the front of the path used. This combined with their redirect plugin will always cause
         * a 404 as it tries:
         *
         *     http://git/api/v3/_codeload/{username}/{repository}/legacy.tar.gz/master`
         *
         * So we are going to remove the plugins that prepend and redirect and handle it all ourselves.
         */
        $this->github->removePlugin(PathPrepend::class);
        $this->github->removePlugin(RedirectPlugin::class);

        /** @var HttpMethodsClient $client */
        $client = $this->github->getHttpClient();

        /** @var ResponseInterface $response */
        $response = $client->get($path);

        if (302 !== $response->getStatusCode()) {
            throw new GitHubException('Unexpected response from github archive link');
        }

        $locationHeader = $response->getHeader('Location');
        $redirect = array_pop($locationHeader);

        $response = $client->get($redirect);
        $responseBody = $response->getBody();

        $target = new LazyOpenStream($target, 'w+');
        while (!$responseBody->eof()) {
            $target->write($responseBody->read(1024));
        }

        //add the plugins back in case the client is used after this has run
        $this->github->addPlugin(new RedirectPlugin());
        $this->github->addPlugin(new PathPrepend(sprintf('/api/%s', $this->github->getApiVersion())));

        return true;
    }
}
