<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

interface BuildHandlerInterface
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
