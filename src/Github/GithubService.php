<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Github;

use Github\Api\Repository\Contents;
use Github\Exception\RuntimeException;

class GithubService
{
    /**
     * @var Contents
     */
    public $contentsApi;

    /**
     * @param Contents $contents
     */
    public function __construct(Contents $contents)
    {
        $this->contentsApi = $contents;
    }

    /**
     * Get the extended metadata for an archive.
     *
     * @param string $user
     * @param string $repo
     * @param string $ref
     * @return array|null
     */
    public function download($user, $repo, $ref)
    {
        try {
            $archive = $this->contentsApi->archive($user, $repo, 'tarball', $ref);
        } catch (RuntimeException $e) {
            $archive = null;
        }

        return $archive;
    }
}
