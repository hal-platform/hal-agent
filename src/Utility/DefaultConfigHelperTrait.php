<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use QL\Hal\Core\Entity\Repository;

trait DefaultConfigHelperTrait
{
    /**
     * @param Repository $repository
     *
     * @return array
     */
    private function buildDefaultConfiguration(Repository $repository)
    {
        return [
            'system' => 'unix',
            'dist' => '.',
            'exclude' => [
                'config/database.ini',
                'data/'
            ],

            'build' => $this->arrayizeCommand($repository->getBuildCmd()),
            'build_transform' => $this->arrayizeCommand($repository->getBuildTransformCmd()),
            'pre_push' => $this->arrayizeCommand($repository->getPrePushCmd()),
            'post_push' => $this->arrayizeCommand($repository->getPostPushCmd())
        ];
    }

    /**
     * @param string|null $cmd
     * @return array
     */
    private function arrayizeCommand($cmd)
    {
        if ($cmd) {
            return [$cmd];
        }

        return [];
    }
}
