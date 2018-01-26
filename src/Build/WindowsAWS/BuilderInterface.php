<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Ssm\SsmClient;
use Hal\Agent\Symfony\OutputAwareInterface;

interface BuilderInterface extends OutputAwareInterface
{
    /**
     * @param SsmClient $ssm
     * @param string $image
     *
     * @param string $instanceID
     * @param string $buildID
     * @param array $commands
     * @param array $env
     *
     * @return bool
     */
    public function __invoke(SsmClient $ssm, $image, $instanceID, $buildID, array $commands, array $env);
}
