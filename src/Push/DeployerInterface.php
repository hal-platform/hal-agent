<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push;

interface DeployerInterface
{
    /**
     * @param array $properties
     *
     * @return int
     *     exit code:
     *         0 success
     *         >=1 failure
     */
    public function __invoke(array $properties);
}
