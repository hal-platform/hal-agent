<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent;

use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Core\Entity\Job;

/**
 * A module that understands how to run a job.
 */
interface JobPlatformInterface extends IOAwareInterface
{
    /**
     * @param Job $job
     *
     * @param array $config
     *                Project configuration (from .hal.yaml)
     *
     * @param array $properties
     *                Build/Release properties
     *
     * @return bool
     */
    public function __invoke(Job $job, array $config, array $properties): bool;
}
