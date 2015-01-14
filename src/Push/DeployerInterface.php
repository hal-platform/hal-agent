<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push;

use Symfony\Component\Console\Output\OutputInterface;

interface DeployerInterface
{
    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return int
     *     exit code:
     *         0 success
     *         >=1 failure
     */
    public function __invoke(OutputInterface $output, array $properties);
}
