<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

trait DefaultConfigHelperTrait
{
    /**
     * @return array
     */
    private function buildDefaultConfiguration()
    {
        return [
            'platform' => 'linux',
            'image' => '',

            'dist' => '.',
            'transform_dist' => '.',

            'env' => [],

            // Build stages
            'build' => [],

            // Release stages
            'build_transform' => [],
            'before_deploy' => [],
            'deploy' => [],
            'after_deploy' => [],

            // rsync only
            'exclude' => [],
            'pre_push' => [],
            'post_push' =>[],
        ];
    }
}
