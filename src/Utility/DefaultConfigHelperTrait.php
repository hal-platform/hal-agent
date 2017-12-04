<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Utility;

use Hal\Core\Entity\Application;

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
            'build' => [],
            'build_transform' => [],
            'pre_push' => [],
            'deploy' => [],
            'post_push' =>[]
        ];
    }

    /**
     * @param string|null $cmd
     *
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
