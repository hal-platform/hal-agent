<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk;

use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\PlatformTrait;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\Configurator;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\HealthChecker;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\DeploymentVerifier;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\Deployer;
use Hal\Agent\Deploy\ElasticBeanstalk\Steps\Compressor;
use Hal\Agent\Deploy\S3\Steps\SyncUploader;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;

class EBDeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'EB Platform - Validating EB configuration';
    private const STEP_2_HEALTH_CHECK = 'EB Platform - Checking EB Environment health';
    private const STEP_3_COMPRESSING = 'EB Platform - Compressing source';
    private const STEP_4_UPLOADING = 'EB Platform - Uploading artifacts to S3 bucket';
    private const STEP_5_PUSHING = 'EB Platform - Deploying artifact to ElasticBeanstalk';
    private const STEP_6_DEPLOYMENT_VERIFY = 'EB Platform - Checking EB Environment health after deployment';

    private const ERR_INVALID_JOB = 'The provided job is an invalid type for this job platform';
    private const ERR_CONFIGURATOR = 'ElasticBeanstalk deploy platform is not configured correctly';
    private const ERR_COMPRESSOR = 'The source directory could not be compressed';
    private const ERR_UPLOADER = 'The artifact(s) could not be uploaded to the S3 Bucket';
    private const ERR_DEPLOYER = 'The artifact(s) could not be pushed to the ElasticBeanstalk';
    private const ERR_ENVIRONMENT_HEALTH = 'Elastic Beanstalk environment is not ready';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Compressor
     */
    private $compressor;

    /**
     * @var S3Uploader
     */
    private $artifactUploader;

    /**
     * @var HealthChecker
     */
    private $health;

    /**
     * @var Deployer
     */
    private $deployer;

    /**
     * @var DeploymentVerifier
     */
    private $verifier;

    /**
     * @param EventLogger $logger
     * @param Configurator $configurator
     * @param Compressor $compressor
     * @param S3Uploader $artifactUploader
     * @param HealthChecker $health
     * @param Deployer $deployer
     * @param DeploymentVerifier $verifier
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        Compressor $compressor,
        S3Uploader $artifactUploader,
        HealthChecker $health,
        Deployer $deployer,
        DeploymentVerifier $verifier
    ) {
        $this->logger = $logger;
        $this->configurator = $configurator;
        $this->compressor = $compressor;
        $this->artifactUploader = $artifactUploader;
        $this->health = $health;
        $this->deployer = $deployer;
        $this->verifier = $verifier;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        if (!$job instanceof Release) {
            $this->sendFailureEvent(self::ERR_INVALID_JOB);
            return false;
        }

        if (!$platformConfig = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return false;
        }

        if (!$this->health($platformConfig)) {
            $this->sendFailureEvent(self::ERR_ENVIRONMENT_HEALTH);
            return false;
        }

        if (!$this->compressor($properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_COMPRESSOR);
            return false;
        }

        if (!$this->uploader($job, $properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_UPLOADER);
            return false;
        }

        if (!$this->deployer($job, $properties['workspace_path'], $platformConfig)) {
            $this->sendFailureEvent(self::ERR_DEPLOYER);
            return false;
        }

        return $this->verifier($platformConfig);
    }

    /**
     * @param Release $job
     *
     * @return array|null
     */
    private function configurator(Release $job)
    {
        $this->getIO()->section(self::STEP_1_CONFIGURING);

        $platformConfig = ($this->configurator)($job);

        if (!$platformConfig) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Platform configuration:', $platformConfig);

        return $platformConfig;
    }

    /**
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function compressor($workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_3_COMPRESSING);

        $wholeSourcePath = $workspacePath . '/job/' . $platformConfig['local_path'];
        $tempArtifactFile = $workspacePath . '/build_export.compressed';
        $remotePath = $platformConfig['remote_path'];

        $this->getIO()->listing([
            sprintf('Local Path: <info>%s</info>', $wholeSourcePath),
            sprintf('Temp Artifact: <info>%s</info>', $tempArtifactFile)
        ]);

        return ($this->compressor)($wholeSourcePath, $tempArtifactFile, $remotePath);
    }

    /**
     * @param Release $release
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function uploader(Release $release, $workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_4_UPLOADING);

        $method = $platformConfig['method'];
        $s3 = $platformConfig['sdk']['s3'];

        $sourceFile = $workspacePath . '/build_export.compressed';
        return ($this->artifactUploader)(
            $s3,
            $sourceFile,
            $platformConfig['bucket'],
            $platformConfig['remote_path'],
            [
                'Job' => $release->id(),
                'Environment' => $release->environment()->name()
            ]
        );
    }

    /**
     * @param array $platformConfig
     *
     * @return boolean
     */
    private function health(array $platformConfig)
    {
        $this->getIO()->section(self::STEP_2_HEALTH_CHECK);

        $eb = $platformConfig['sdk']['eb'];

        $health = ($this->health)(
            $eb,
            $platformConfig['application'],
            $platformConfig['environment']
        );

        if ($health['status'] !== 'Ready') {
            return false;
        }

        return true;
    }

    /**
     * @param Release $release
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function deployer(Release $release, $workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_5_PUSHING);

        $eb = $platformConfig['sdk']['eb'];

        return ($this->deployer)(
            $eb,
            $platformConfig['application'],
            $platformConfig['environment'],
            $platformConfig['bucket'],
            $platformConfig['remote_path'],
            $release->id(),
            $release->environment()->name(),
            $platformConfig['deployment_description']
        );
    }

    /**
     * @param array $platformConfig
     *
     * @return boolean
     */
    private function verifier(array $platformConfig)
    {
        $this->getIO()->section(self::STEP_6_DEPLOYMENT_VERIFY);

        $eb = $platformConfig['sdk']['eb'];

        $verifier = ($this->verifier)(
            $eb,
            $platformConfig['application'],
            $platformConfig['environment']
        );

        return $verifier;
    }

    /**
     * @param string $message
     * @param array $context
     *
     * @return void
     */
    private function sendFailureEvent($message, $context = [])
    {
        $this->logger->event('failure', $message, $context);
        $this->getIO()->error($message);
    }
}
