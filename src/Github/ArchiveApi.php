<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Github;

use Github\Api\AbstractApi;
use Github\Client;
use Symfony\Component\Filesystem\Filesystem;

class ArchiveApi extends AbstractApi
{
    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @param Client $client
     * @param Filesystem $filesystem Allows null just to maintain parent compatibility
     */
    public function __construct(Client $client, Filesystem $filesystem = null)
    {
        $this->client = $client;
        $this->filesystem = $filesystem ?: new Filesystem;
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
            'repos/%s/%s/tarball',
            rawurlencode($username),
            rawurlencode($repository)
        );

        $response = $this->client->getHttpClient()->get($path, ['ref' => $reference]);
        $this->filesystem->dumpFile($target, $response->getBody());

        return $response->isSuccessful();
    }
}
