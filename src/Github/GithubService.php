<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Github;

use Github\Api\Repo;
use Github\Api\User;
use Github\Exception\RuntimeException;
use Github\ResultPager;

class GithubService
{
    /**
     * @var User
     */
    public $userApi;

    /**
     * @var Repo
     */
    public $repoApi;

    /**
     * @var ResultPager
     */
    private $pager;

    /**
     * @param User $user
     * @param Repo $repo
     * @param ResultPager $pager
     */
    public function __construct(User $user, Repo $repo, ResultPager $pager)
    {
        $this->userApi = $user;
        $this->repoApi = $repo;
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
}
