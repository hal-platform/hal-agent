<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Build;

use Symfony\Component\Console\Output\OutputInterface;

interface BuildHandlerInterface
{
    /**
     * @param OutputInterface $output
     *
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
    public function __invoke(OutputInterface $output, array $commands, array $properties);
}