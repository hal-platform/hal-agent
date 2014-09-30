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

        return array_merge($context, $props);
    }

    /**
     * @param Build $build
     * @return array
     */
    private function buildProperties(Build $build)
    {
        $repository = $build->getRepository();
        $github = sprintf(
            '%s/%s:%s (%s)',
            $repository->getGithubUser(),
            $repository->getGithubRepo(),
            $build->getBranch(),
            $build->getCommit()
        );

        return [
            'buildId' => $build->getId(),
            'github' => $github,
            'repository' => $repository->getKey(),
            'environment' => $build->getEnvironment()->getKey(),
            'email' => [
                'to' => $repository->getEmail()
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

        $props = array_merge($buildProperties, [
            'pushId' => $push->getId(),
            'server' => $server->getName()
        ]);

        return $props;
    }
}
