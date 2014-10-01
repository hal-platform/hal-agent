<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Logger;

use QL\Hal\Core\Entity\Build;
use QL\Hal\Core\Entity\Push;

class Resolver
{
    /**
     * @type string
     */
    const BUILD_MESSAGE = '%s (%s)';
    const PUSH_MESSAGE = '%s (%s:%s)';

    /**
     * @param Build|Push|null $entity
     * @return array
     */
    public function resolveProperties($entity, $context)
    {
        $props = [];

        if ($entity instanceof Push) {
            $props = $this->pushProperties($entity);
             $props['email']['subject'] = sprintf(self::PUSH_MESSAGE, $props['repository'], $props['environment'], $props['server']);

        } elseif ($entity instanceof Build) {
            $props = $this->buildProperties($entity);
             $props['email']['subject'] = sprintf(self::BUILD_MESSAGE, $props['repository'], $props['environment']);
        }

        $props['email']['sanitized_subject'] = $props['email']['subject'];

        $prefix = ($entity && $entity->getStatus() === 'Success') ? "\xE2\x9C\x94" : "\xE2\x9C\x96";
        $props['email']['subject'] = sprintf('[%s] ', $prefix) . $props['email']['subject'];

        return array_merge($context, $props);
    }

    /**
     * @param Build $build
     * @return array
     */
    private function buildProperties(Build $build)
    {
        $repository = $build->getRepository();

        $githubRepo = sprintf('%s/%s', $repository->getGithubUser(), $repository->getGithubRepo());
        $github = sprintf('%s:%s (%s)', $githubRepo, $build->getBranch(), $build->getCommit());

        list($githubUrl, $githubTitle) = $this->getGithubStuff($githubRepo, $build->getBranch(), $build->getCommit());

        return [
            'buildId' => $build->getId(),
            'github' => $github,
            'repository' => $repository->getKey(),
            'repository_group' => $repository->getGroup()->getName(),
            'repository_description' => $repository->getDescription(),
            'environment' => $build->getEnvironment()->getKey(),
            'email' => [
                'to' => $repository->getEmail()
            ],
            'link' => [
                'build' => 'build/' . $build->getId(),
                'repo_status' => 'repositories/' . $repository->getId() . '/status',
                'github' => $githubUrl,
                'github_title' => $githubTitle
            ]
        ];
    }

    /**
     * @param Push $push
     * @param boolean $isSuccess
     * @return array
     */
    private function pushProperties(Push $push)
    {
        $buildProperties = $this->buildProperties($push->getBuild());
        $server = $push->getDeployment()->getServer();

        $props = array_merge_recursive($buildProperties, [
            'pushId' => $push->getId(),
            'server' => $server->getName(),
            'link' => [
                'push' => 'push/' . $push->getId()
            ]
        ]);

        return $props;
    }

    /**
     * @return string $repo
     * @return string $branch
     * @return string $commit
     * @return array
     */
    private function getGithubStuff($repo, $branch, $commit)
    {
        $base = $repo;

        // commit
        if ($branch === $commit) {
            return [
                $base . '/commit/' . $commit,
                'commit ' . $commit
            ];
        }

        // pull request
        if (substr($branch, 0, 5) === 'pull/') {
            return [
                $base . '/' . $branch,
                'pull request #' . substr($branch, 5)
            ];
        }

        // branch
        return [
            $base . '/tree/' . $branch,
            $branch . ' ' . $branch
        ];
    }
}
