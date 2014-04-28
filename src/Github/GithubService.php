<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Github;

use Github\Api\Repo;
use Github\Api\Repository\Contents;
use Github\Exception\RuntimeException;
use Github\ResultPager;

class GithubService
{
    /**
     * @var Repo
     */
    public $repoApi;

    /**
     * @var Contents
     */
    public $contentsApi;

    /**
     * @var ResultPager
     */
    private $pager;

    /**
     * @param Repo $repo
     * @param Contents $contents
     * @param ResultPager $pager
     */
    public function __construct(Repo $repo, Contents $contents, ResultPager $pager)
    {
        $this->repoApi = $repo;
        $this->contentsApi = $contents;
        $this->pager = $pager;
    }

    /**
     * Get the extended metadata for a repository.
     *
     * @param string $user
     * @param string $repo
     * @return array|null
     */
    public function repository($user, $repo)
    {
        try {
            $repository = $this->repoApi->show($user, $repo);
        } catch (RuntimeException $e) {
            $repository = null;
        }

        return $repository;
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
