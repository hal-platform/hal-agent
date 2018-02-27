<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Sync;

use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\PlatformTrait;
use Hal\Agent\Deploy\DeployException;
use Hal\Agent\Deploy\S3\S3DeployInterface;
use Hal\Agent\Deploy\S3\Steps\Validator;
use Hal\Agent\Deploy\S3\Sync\Steps\Uploader;
use Hal\Agent\JobExecution;
use Hal\Agent\Symfony\IOAwareInterface;
use Hal\Agent\Symfony\IOAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Entity\Job;
use Hal\Core\Type\TargetEnum;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use InvalidArgumentException;

class S3SyncDeployPlatform implements IOAwareInterface, S3DeployInterface
{
    use PlatformTrait;

    private const STEP_1_AUTHENTICATING = 'S3 Sync Platform - Authenticating with AWS';
    private const STEP_2_VALIDATING = 'S3 Sync Platform - Validating source directory and target bucket';
    private const STEP_3_UPLOADING = 'S3 Sync Platform - Syncing source directory to S3 bucket';

    private const ERR_AUTHENTICATOR = 'AWS credentials could not be authenticated';
    private const ERR_VALIDATOR = 'Either source directory or target bucket could not be validated';
    private const ERR_VALIDATOR_SOURCE = 'The source directory could not be validated';
    private const ERR_VALIDATOR_TARGET = 'The target bucket could not be validated';
    private const ERR_UPLOADER = 'Source directory could not be synced to S3 Bucket';
    private const ERR_UPLOADER_CREDENTIALS = 'AWS credentials could not be authenticated';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var AWSAuthenticator
     */
    private $awsAuthenticator;

    /**
     * @var Validator
     */
    private $validator;

    /**
     * @var Uploader
     */
    private $uploader;

    /**
     * @param EventLogger $logger
     *
     * @param AWSAuthenticator $awsAuthenticator
     * @param Validator $validator
     * @param Uploader $uploader
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $awsAuthenticator,
        Validator $validator,
        Uploader $uploader
    ) {
        $this->logger = $logger;

        $this->awsAuthenticator = $awsAuthenticator;
        $this->validator = $validator;
        $this->uploader = $uploader;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(Job $job, JobExecution $execution, array $properties, array $config): bool
    {
        if (!$s3 = $this->authenticator($config)) {
            $this->sendFailureEvent(self::ERR_AUTHENTICATOR);
            return $this->bombout(false);
        }

        if (!$directory = $this->validator($s3, $properties, $config)) {
            $this->sendFailureEvent(self::ERR_VALIDATOR);
            return $this->bombout(false);
        }

        if (!$upload = $this->uploader($s3, $properties, $config)) {
            $this->sendFailureEvent(self::ERR_UPLOADER);
            return $this->bombout(false);
        }

        return true;
    }

    /**
     * @param array $config
     *
     * @return S3Client|null
     */
    private function authenticator(array $config)
    {
        $this->getIO()->section(self::STEP_1_AUTHENTICATING);

        $s3 = $this->awsAuthenticator->getS3(
            $config['aws']['region'],
            $config['aws']['credential']
        );

        if (!$s3) {
            return null;
        }

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
        $this->getIO()->section(self::STEP_2_VALIDATING);

        $wholeSourcePath = $properties['workspace_path'] . '/job/' . $config[TargetEnum::TYPE_S3]['src'];

        if (!$this->validator->localPathExists($wholeSourcePath)) {
            $this->sendFailureEvent(self::ERR_VALIDATOR_SOURCE);
            return false;
        }

        try {
            $isDirectory = $this->validator->isDirectory($wholeSourcePath);
        } catch (DeployException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        }

        if (!$isDirectory) {
            $this->sendFailureEvent(self::ERR_VALIDATOR_SOURCE);
            return false;
        }

        if (!$this->validator->bucketExists($s3, $config[TargetEnum::TYPE_S3]['bucket'])) {
            $this->sendFailureEvent(self::ERR_VALIDATOR_TARGET);
            return false;
        }

        return true;
    }

    /**
     * @param S3Client $s3
     * @param array $properties
     * @param array $config
     *
     * @return bool
     */
    private function uploader(S3Client $s3, array $properties, array $config)
    {
        $this->getIO()->section(self::STEP_3_UPLOADING);

        $release = $properties['job'];
        $build = $release->build();
        $environment = $release->environment();

        $metadata = [
            'Build' => $build->id(),
            'Release' => $release->id(),
            'Environment' => $environment->name()
        ];

        $wholeSourcePath = $properties['workspace_path'] . '/job/' . $config[TargetEnum::TYPE_S3]['src'];

        try {
            $result = ($this->uploader)(
                $s3,
                $wholeSourcePath,
                $config[TargetEnum::TYPE_S3]['bucket'],
                $config[TargetEnum::TYPE_S3]['file'],
                $metadata
            );
        } catch (CredentialsException $e) {
            $this->sendFailureEvent(self::ERR_UPLOADER_CREDENTIALS);
            return false;
        } catch (AwsException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        } catch (InvalidArgumentException $e) {
            $this->sendFailureEvent($e->getMessage());
            return false;
        }

        return $result;
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
