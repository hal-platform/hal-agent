<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Artifact;

use Hal\Agent\Deploy\S3\S3DeployInterface;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\JobExecution;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Job;

class S3ArtifactDeployPlatform implements IOAwareInterface, S3DeployInterface
{
    use IOAwareTrait;

    public function __construct()
    {
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties, array $config): bool
    {
    }
}
