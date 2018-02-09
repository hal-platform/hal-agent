<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Symfony\IOAwareInterface;

interface BuilderInterface extends IOAwareInterface
{
    /**
     * @param string $jobID
     * @param SsmClient $ssm
     * @param string $image
     *
     * @param string $instanceID
     * @param array $commands
     * @param array $env
     *
     * @return bool
     */
    public function __invoke(string $jobID, $image, SsmClient $ssm, $instanceID, array $commands, array $env): bool;
}
