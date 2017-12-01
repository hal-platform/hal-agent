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
            'system' => 'unix',
            'dist' => '.',
            'exclude' => [],

            'env' => [],

            'build' => [],
            'build_transform' => [],
            'before_deploy' => [],
            'pre_push' => [],
            'deploy' => [],
            'post_push' =>[],
            'after_deploy' => []
        ];
    }
}
