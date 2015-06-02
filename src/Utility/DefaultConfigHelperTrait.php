<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Utility;

use QL\Hal\Core\Entity\Application;

trait DefaultConfigHelperTrait
{
    /**
     * @param Application $application
     *
     * @return array
     */
    private function buildDefaultConfiguration(Application $application)
    {
        return [
            'system' => 'unix',
            'dist' => '.',
            'exclude' => [
                'config/database.ini',
                'data/'
            ],

            'build' => $this->arrayizeCommand($application->getBuildCmd()),
            'build_transform' => $this->arrayizeCommand($application->getBuildTransformCmd()),
            'pre_push' => $this->arrayizeCommand($application->getPrePushCmd()),
            'post_push' => $this->arrayizeCommand($application->getPostPushCmd())
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
