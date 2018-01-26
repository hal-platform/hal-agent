<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build;

use Hal\Agent\Symfony\OutputAwareInterface;

interface PlatformInterface extends OutputAwareInterface
{
    /**
     * @param array $commands
     *                An array of shell commands to run
     *
     * @param array $properties
     *                Push/Build properties
     *
     * @return int
     *     exit code:
     *         0 success
     *         >=1 failure
     */
    public function __invoke(array $commands, array $properties);
}
