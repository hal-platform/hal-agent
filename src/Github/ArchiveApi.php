<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Github;

use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\LazyOpenStream;
use Psr\Http\Message\ResponseInterface;

class ArchiveApi
{
    /**
     * @var ClientInterface
     */
    private $guzzle;

    /**
     * @var string
     */
    private $baseURL;

    /**
     * @param ClientInterface $guzzle
     * @param string $baseURL
     */
    public function __construct(ClientInterface $guzzle, $baseURL)
    {
        $this->guzzle = $guzzle;
        $this->baseURL = rtrim($baseURL, '/') . '/';
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
        $parts = [
            'repos',
            rawurlencode($username),
            rawurlencode($repository),
            'tarball',
            rawurlencode($reference)
        ];

        $endpoint = $this->baseURL . implode('/', $parts);

        $options = [
            'allow_redirects' => true,
            'connect_timeout' => 5,
            'timeout' => 300, # 5 minutes seems like a reasonable amount of time?
            'http_errors' => false,
            'sink' => new LazyOpenStream($target, 'w+')
        ];

        try {
            $response = $this->guzzle->get($endpoint, $options);
        } catch (Exception $e) {
            throw new GitHubException($e->getMessage(), $e->getCode(), $e);
        }

        return ($response->getStatusCode() === 200);
    }
}
