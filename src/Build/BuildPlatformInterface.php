<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Symfony\IOAwareInterface;

interface BuildPlatformInterface extends IOAwareInterface
{
    /**
     * @param array $config
     *                Project configuration (from .hal.yaml)
     * @param array $properties
     *                Build/Release properties
     *
     * @return bool
     */
    public function __invoke(array $config, array $properties): bool;
}
