<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy;

use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\PlatformTrait;
use Hal\Agent\Deploy\CodeDeploy\Steps\Compressor;
use Hal\Agent\Deploy\CodeDeploy\Steps\Configurator;
use Hal\Agent\Deploy\CodeDeploy\Steps\Deployer;
use Hal\Agent\Deploy\CodeDeploy\Steps\Verifier;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\JobExecution;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;

class CodeDeployDeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'CodeDeploy Platform - Validating CodeDeploy configuration';
    private const STEP_2_VERIFYING_HEALTH = 'CodeDeploy Platform - Validating CodeDeploy instance health';
    private const STEP_3_COMPRESSING = 'CodeDeploy Platform - Compressing source';
    private const STEP_4_UPLOADING = 'CodeDeply Platform - Uploading code to S3 bucket';
    private const STEP_5_DEPLOYING = 'CodeDeploy Platform - Deploying version to CodeDeploy';
    private const STEP_6_VERIFYING_DEPLOY = 'CodeDeploy Platform - Verifying CodeDeploy deployment';

    private const ERR_INVALID_JOB = 'The provided job is an invalid type for this job platform';
    private const ERR_CONFIGURATOR = 'CodeDeploy deploy platform is not configured correctly';
    private const ERR_HEALTH_VERIFIER = 'Could not validate CodeDeploy instance health';
    private const ERR_COMPRESSOR = 'The source artifact could not be compressed';
    private const ERR_UPLOADER = 'The artifact(s) could not be uploaded to the S3 Bucket';
    private const ERR_DEPLOYER = 'New application version failed to deploy to CodeDeploy';
    private const ERR_DEPLOY_VERIFIER = 'Could not validate CodeDeploy deployment';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var Verifier
     */
    private $verifier;

    /**
     * @var Compressor
     */
    private $compressor;

    /**
     * @var S3Uploader
     */
    private $uploader;

    /**
     * @var Deployer
     */
    private $deployer;

    /**
     * @param EventLogger $logger
     * @param Configurator $configurator
     * @param Verifier $verifier
     * @param Compressor $compressor
     * @param S3Uploader $uploader
     * @param Deployer $deployer
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        Verifier $verifier,
        Compressor $compressor,
        S3Uploader $uploader,
        Deployer $deployer
    ) {
        $this->logger = $logger;

        $this->configurator = $configurator;
        $this->verifier = $verifier;
        $this->compressor = $compressor;
        $this->uploader = $uploader;
        $this->deployer = $deployer;
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

        if (!$this->healthVerifier($platformConfig)) {
            $this->sendFailureEvent(self::ERR_HEALTH_VERIFIER);
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

        if (!$deployment = $this->deployer($job, $platformConfig)) {
            $this->sendFailureEvent(self::ERR_DEPLOYER);
            return false;
        }

        if (!$this->validateDeployment($platformConfig, $deployment)) {
            $this->sendFailureEvent(self::ERR_DEPLOY_VERIFIER);
            return false;
        }

        return true;
    }

    /**
     * @param Job $job
     *
     * @return array|null
     */
    private function configurator(Job $job)
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
     * @param array $platformConfig
     *
     * @return bool
     */
    private function healthVerifier(array $platformConfig)
    {
        $this->getIO()->section(self::STEP_2_VERIFYING_HEALTH);

        $cd = $platformConfig['sdk']['cd'];
        $application = $platformConfig['application'];
        $group = $platformConfig['group'];

        return $this->verifier->isDeploymentGroupHealthy($cd, $application, $group);
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
     * @param Release $job
     * @param string $workspacePath
     * @param array $platformConfig
     *
     * @return bool
     */
    private function uploader(Release $job, $workspacePath, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_4_UPLOADING);

        $s3 = $platformConfig['sdk']['s3'];
        $sourceFile = $workspacePath . '/build_export.compressed';

        return ($this->uploader)(
            $s3,
            $sourceFile,
            $platformConfig['bucket'],
            $platformConfig['remote_path'],
            [
                'Job' => $job->id(),
                'Environment' => $job->environment()->name()
            ]
        );
    }

    /**
     * @param array $platformConfig
     *
     * @return array|null
     */
    private function deployer(Job $job, array $platformConfig)
    {
        $this->getIO()->section(self::STEP_5_DEPLOYING);

        $cd = $platformConfig['sdk']['cd'];

        $deployment = ($this->deployer)(
            $job,
            $cd,
            $platformConfig['bucket'],
            $platformConfig['remote_path'],
            $platformConfig['application'],
            $platformConfig['group'],
            $platformConfig['configuration'],
            $platformConfig['deployment_description']
        );

        if (!$deployment) {
            return null;
        }

        $this->outputTable($this->getIO(), 'Deployment information:', $deployment);

        return $deployment;
    }

    /**
     * @param array $platformConfig
     * @param array $deploymentInformation
     *
     * @return bool
     */
    private function validateDeployment(array $platformConfig, array $deploymentInformation)
    {
        $this->getIO()->section(self::STEP_6_VERIFYING_DEPLOY);

        $cd = $platformConfig['sdk']['cd'];
        $deploymentID = $deploymentInformation['codeDeployID'];

        $completed = $this->verifier->waitForHealth($cd, $deploymentID);
        if (!$completed) {
            return false;
        }

        $health = $this->verifier->checkDeploymentHealth($cd, $deploymentID);
        if (!$health) {
            return false;
        }

        return true;
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function sendFailureEvent($message)
    {
        $this->logger->event('failure', $message);
        $this->getIO()->error($message);
    }
}
