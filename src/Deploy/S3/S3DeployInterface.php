<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\JobExecution;
use Hal\Core\Entity\Job;

/**
 * A module that understands how to run a job.
 */
interface S3DeployInterface extends IOAwareInterface
{
    /**
     * @param Job $job
     * @param JobExecution $execution
     * @param array $properties
     *                Build/Release properties
     * @param array $config
     *                Platform Configuration
     *
     * @return bool
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties, array $config): bool;
}
