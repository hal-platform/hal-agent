<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace QL\Hal\Agent\Github;

use Github\Api\Repository\Commits as CommitApi;
use Github\Api\GitData\References as ReferenceApi;
use Github\Api\PullRequest as PullRequestApi;
use Github\Exception\RuntimeException;

/**
 * Resolve a commit sha from a git reference
 *
 * Supports commits, branches, tags, and pull requests
 */
class ReferenceResolver
{
    /**
     * @var ReferenceApi
     */
    private $refApi;

    /**
     * @var CommitApi
     */
    private $commitApi;

    /**
     * @var PullRequestApi
     */
    private $pullApi;

    /**
     * @param ReferenceApi $refApi
     * @param CommitApi $commitApi
     * @param PullRequestApi $pullApi
     */
    public function __construct(ReferenceApi $refApi, CommitApi $commitApi, PullRequestApi $pullApi)
    {
        $this->refApi = $refApi;
        $this->commitApi = $commitApi;
        $this->pullApi = $pullApi;
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $reference
     * @return string|null
     */
    public function resolve($user, $repo, $reference)
    {
        // This will bomb out if the ref is not formatted as "tag/TAG_HERE"
        if ($tag = $this->getTag($user, $repo, $reference)) {
            return $tag;
        }

        // This will bomb out if the ref is not formatted as "pull/PR_NUMBER"
        if ($pullRequest = $this->getPullRequest($user, $repo, $reference)) {
            return $pullRequest;
        }

        // This will bomb out if the ref is not 40 characters
        if ($branch = $this->getCommit($user, $repo, $reference)) {
            return $branch;
        }

        // Last resort, it must be a branch?
        if ($branch = $this->getBranch($user, $repo, $reference)) {
            return $branch;
        }

        return null;
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $branch
     * @return string|null
     */
    private function getBranch($user, $repo, $branch)
    {
        try {
            $result = $this->refApi->show($user, $repo, sprintf('heads/%s', $branch));
        } catch (RuntimeException $e) {
            return null;
        }

        return $result['object']['sha'];
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $sha
     * @return string|null
     */
    private function getCommit($user, $repo, $sha)
    {
        if (strlen($sha) !== 40) {
            return null;
        }

        try {
            $result = $this->commitApi->show($user, $repo, $sha);
        } catch (RuntimeException $e) {
            return null;
        }

        return $result['sha'];
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $tag
     * @return string|null
     */
    private function getPullRequest($user, $repo, $tag)
    {
        if (preg_match('#^pull/([\d]+)#', $tag, $match) !== 1) {
            return null;
        }

        try {
            $result = $this->pullApi->show($user, $repo, $match[1]);
        } catch (RuntimeException $e) {
            return null;
        }

        return $result['head']['sha'];
    }

    /**
     * @param string $user
     * @param string $repo
     * @param string $tag
     * @return string|null
     */
    private function getTag($user, $repo, $tag)
    {
        if (preg_match('#^tag/([[:print:]]+)#', $tag, $match) !== 1) {
            return null;
        }

        try {
            $result = $this->refApi->show($user, $repo, sprintf('tags/%s', $match[1]));
        } catch (RuntimeException $e) {
            return null;
        }

        return $result['object']['sha'];
    }
}
