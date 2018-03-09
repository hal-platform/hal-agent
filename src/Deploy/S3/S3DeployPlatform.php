<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use Aws\S3\S3Client;
use Hal\Agent\Command\FormatterTrait;
use Hal\Agent\Deploy\S3\Steps\Configurator;
use Hal\Agent\Deploy\S3\Steps\Validator;
use Hal\Agent\Deploy\S3\Steps\Compressor;
use Hal\Agent\Deploy\S3\Steps\ArtifactUploader;
use Hal\Agent\Deploy\S3\Steps\SyncUploader;
use Hal\Agent\Deploy\S3\Steps\ArtifactVerifier;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\JobPlatformInterface;
use Hal\Agent\JobExecution;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Job;
use Hal\Core\Entity\JobType\Release;
use Hal\Core\Type\TargetEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\S3\Exception\S3Exception;
use Hal\Agent\Deploy\DeployException;
use InvalidArgumentException;
use RuntimeException;

class S3DeployPlatform implements IOAwareInterface, JobPlatformInterface
{
    use FormatterTrait;
    // Comes with EmergencyBuildHandlerTrait, EnvironmentVariablesTrait, IOAwareTrait
    use PlatformTrait;

    private const STEP_1_CONFIGURING = 'S3 Platform - Validating S3 configuration';
    private const STEP_2_AUTHENTICATING = 'S3 Platform - Authenticating with AWS';
    private const STEP_3_VALIDATING = 'S3 Platform - Validating source and target bucket';
    private const STEP_4_COMPRESSING = 'S3 Platform - Compressing source';
    private const STEP_5_UPLOADING = 'S3 Platform - Uploading file(s) to S3 bucket';
    private const STEP_6_VERIFYING = 'S3 Platform - Verifying successful artifact upload';

    private const NOTE_SKIP_COMPRESSION = 'Skipping compression step: in sync mode';
    private const NOTE_ALREADY_COMPRESSED = 'Skipping compression step: source is not a directory';
    private const NOTE_SKIP_VERIFICATION = 'Skipping artifact verification step: in sync mode';
    private const NOTE_ARTIFACT_VERIFIED = 'Artifact upload successfully verified';

    private const ERR_INVALID_JOB = 'The provided job is an invalid type for this job platform';
    private const ERR_CONFIGURATOR = 'S3 deploy platform is not configured correctly';
    private const ERR_AUTHENTICATOR = 'AWS credentials could not be authenticated';
    private const ERR_VALIDATOR = 'Either the source or target bucket could not be validated';
    private const ERR_VALIDATOR_SOURCE = 'The source could not be validated';
    private const ERR_VALIDATOR_TARGET = 'The target bucket could not be validated';
    private const ERR_COMPRESSOR = 'The source directory could not be compressed';
    private const ERR_UPLOADER = 'The file(s) could not be uploaded to the S3 Bucket';
    private const ERR_UPLOADER_CREDENTIALS = 'AWS credentials could not be authenticated';
    private const ERR_VERIFIER = 'The artifact could not be verified as uploaded';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var Configurator
     */
    private $configurator;

    /**
     * @var AWSAuthenticator
     */
    private $awsAuthenticator;

    /**
     * @var Validator
     */
    private $validator;

    /**
     * @var Compressor
     */
    private $compressor;

    /**
     * @var ArtifactUploader
     */
    private $artifactUploader;

    /**
     * @var SyncUploader
     */
    private $syncUploader;

    /**
     * @var ArtifactVerifier
     */
    private $verifier;

    /**
     * @param EventLogger $logger
     *
     * @param Configurator $configurator
     * @param AWSAuthenticator $awsAuthenticator
     * @param Validator $validator
     * @param Compressor $compressor
     * @param ArtifactUploader $artifactUploader
     * @param SyncUploader $syncUploader
     * @param ArtifactVerifier $verifier
     */
    public function __construct(
        EventLogger $logger,
        Configurator $configurator,
        AWSAuthenticator $awsAuthenticator,
        Validator $validator,
        Compressor $compressor,
        ArtifactUploader $artifactUploader,
        SyncUploader $syncUploader,
        ArtifactVerifier $verifier
    ) {
        $this->logger = $logger;

        $this->configurator = $configurator;
        $this->awsAuthenticator = $awsAuthenticator;
        $this->validator = $validator;
        $this->compressor = $compressor;
        $this->artifactUploader = $artifactUploader;
        $this->syncUploader = $syncUploader;
        $this->verifier = $verifier;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties): bool
    {
        if (!$job instanceof Release) {
            $this->sendFailureEvent(self::ERR_INVALID_JOB);
            return $this->bombout(false);
        }

        if (!$config = $this->configurator($job)) {
            $this->sendFailureEvent(self::ERR_CONFIGURATOR);
            return $this->bombout(false);
        }

        if (!$s3 = $this->authenticator($config)) {
            $this->sendFailureEvent(self::ERR_AUTHENTICATOR);
            return $this->bombout(false);
        }

        if (!$this->validator($s3, $properties, $config)) {
            $this->sendFailureEvent(self::ERR_VALIDATOR);
            return $this->bombout(false);
        }

        if (!$artifact = $this->compressor($properties, $config)) {
            $this->sendFailureEvent(self::ERR_COMPRESSOR);
            return $this->bombout(false);
        }

        if (!$this->uploader($job, $s3, $artifact, $config)) {
            $this->sendFailureEvent(self::ERR_UPLOADER);
            return $this->bombout(false);
        }

        if (!$this->verifier($s3, $config)) {
            $this->sendFailureEvent(self::ERR_VERIFIER);
            return $this->bombout(false);
        }

        return $this->bombout(true);
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
     * @param array $config
     *
     * @return S3Client|null
     */
    private function authenticator(array $config)
    {
        $this->getIO()->section(self::STEP_2_AUTHENTICATING);

        $s3 = $this->awsAuthenticator->getS3(
            $config['aws']['region'],
            $config['aws']['credential']
        );
        if (!$s3) {
            return null;
        }

        $this->getIO()->listing([
            sprintf('Region: <info>%s</info>', $config['aws']['region'])
        ]);

        return $s3;
    }

    /**
     * @param S3Client $s3
     * @param array $properties
     * @param array $config
     *
     * @return bool
     */
    private function validator(S3Client $s3, array $properties, array $config)
    {
        $this->getIO()->section(self::STEP_3_VALIDATING);

        $wholeSourcePath = $properties['workspace_path'] . '/job/' . $config[TargetEnum::TYPE_S3]['src'];

        if (!$this->validator->localPathExists($wholeSourcePath)) {
            $this->sendFailureEvent(self::ERR_VALIDATOR_SOURCE);
            return false;
        }

        try {
            $bucketExists = $this->validator->bucketExists($s3, $config[TargetEnum::TYPE_S3]['bucket']);
        } catch (S3Exception $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        }

        if (!$bucketExists) {
            $this->sendFailureEvent(self::ERR_VALIDATOR_TARGET);
            return false;
        }

        $this->getIO()->listing([
            sprintf('Source: <info>%s</info>', $wholeSourcePath),
            sprintf('Target: <info>%s</info>', $config[TargetEnum::TYPE_S3]['bucket'])
        ]);

        return true;
    }


    /**
     * @param array $properties
     * @param array $config
     *
     * @return string|null
     */
    private function compressor(array $properties, array $config)
    {
        $this->getIO()->section(self::STEP_4_COMPRESSING);

        $wholeSourcePath = $properties['workspace_path'] . '/job/' . $config[TargetEnum::TYPE_S3]['src'];

        if ($config[TargetEnum::TYPE_S3]['method'] === 'sync') {
            $this->getIO()->note(self::NOTE_SKIP_COMPRESSION);
            return $wholeSourcePath;
        }

        if ($config[TargetEnum::TYPE_S3]['method'] === 'artifact') {
            try {
                $isDirectory = $this->validator->isDirectory($wholeSourcePath);
            } catch (DeployException $e) {
                $this->sendFailureEvent($e->getMessage());
                return null;
            }

            if (!$isDirectory) {
                $this->getIO()->note(self::NOTE_ALREADY_COMPRESSED);
                return $wholeSourcePath;
            }

            try {
                $isSuccessful = ($this->compressor)(
                    $wholeSourcePath,
                    $properties['artifact_stored_file'],
                    $config[TargetEnum::TYPE_S3]['file']
                );
            } catch (DeployException $e) {
                $this->sendFailureEvent($e->getMessage());
                return null;
            }

            if (!$isSuccessful) {
                return null;
            }

            $this->getIO()->listing([
                sprintf('Original: <info>%s</info>', $wholeSourcePath),
                sprintf('Compressed: <info>%s</info>', $properties['artifact_stored_file'])
            ]);

            return $properties['artifact_stored_file'];
        }

        return null;
    }

    /**
     * @param Release $job
     * @param S3Client $s3
     * @param string $source
     * @param array $config
     *
     * return bool
     */
    private function uploader(Release $job, S3Client $s3, $source, array $config)
    {
        $this->getIO()->section(self::STEP_5_UPLOADING);

        if ($config[TargetEnum::TYPE_S3]['method'] === 'sync') {
            $uploader = $this->syncUploader;
        } else if ($config[TargetEnum::TYPE_S3]['method'] === 'artifact') {
            $uploader = $this->artifactUploader;
        } else {
            return false;
        }

        $metadata = [
            'Build' => $job->build()->id(),
            'Release' => $job->id(),
            'Environment' => $job->environment()->name()
        ];

        try {
            $isSuccessful = $uploader(
                $s3,
                $source,
                $config[TargetEnum::TYPE_S3]['bucket'],
                $config[TargetEnum::TYPE_S3]['file'],
                $metadata
            );
        } catch (AwsException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        } catch (InvalidArgumentException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        } catch (CredentialsException $e) {
            $this->sendFailureEvent(self::ERR_UPLOADER_CREDENTIALS);
            return false;
        }

        if (!$isSuccessful) {
            return false;
        }

        $this->outputTable($this->getIO(), 'Metadata:', $metadata);

        return true;
    }

    /**
     * @param S3Client $s3
     * @param array $config
     *
     * return bool
     */
    private function verifier(S3Client $s3, array $config)
    {
        $this->getIO()->section(self::STEP_6_VERIFYING);

        if ($config[TargetEnum::TYPE_S3]['method'] === 'sync') {
            $this->getIO()->note(self::NOTE_SKIP_VERIFICATION);
            return true;
        }

        try {
            $isSuccessful = ($this->verifier)(
                $s3,
                $config[TargetEnum::TYPE_S3]['bucket'],
                $config[TargetEnum::TYPE_S3]['file']
            );
        } catch (AwsException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        } catch (RuntimeException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        }

        if (!$isSuccessful) {
            return false;
        }

        $this->getIO()->note(self::NOTE_ARTIFACT_VERIFIED);

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
