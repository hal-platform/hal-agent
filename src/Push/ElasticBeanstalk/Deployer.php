<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\ElasticBeanstalk;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;
use Hal\Agent\AWS\S3Uploader;
use Hal\Agent\Push\DeployerInterface;
use Hal\Agent\Push\ReleasePacker;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Symfony\OutputAwareInterface;
use Hal\Agent\Symfony\OutputAwareTrait;
use Hal\Core\AWS\AWSAuthenticator;
use Hal\Core\Type\TargetEnum;

class Deployer implements DeployerInterface, OutputAwareInterface
{
    use OutputAwareTrait;

    const SECTION = 'Deploying - EB';
    const STATUS = 'Deploying push by EB';

    const ERR_INVALID_DEPLOYMENT_SYSTEM = 'Elastic Beanstalk deployment system is not configured';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for EB deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for EB deployment';
    const ERR_ENVIRONMENT_HEALTH = 'Elastic Beanstalk environment is not ready';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var AWSAuthenticator
     */
    private $authenticator;

    /**
     * @var HealthChecker
     */
    private $health;

    /**
     * @var ReleasePacker
     */
    private $packer;

    /**
     * @var S3Uploader
     */
    private $uploader;

    /**
     * @var Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param HealthChecker $health
     * @param ReleasePacker $packer
     * @param S3Uploader $uploader
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        HealthChecker $health,
        ReleasePacker $packer,
        S3Uploader $uploader,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->authenticator = $authenticator;

        $this->health = $health;
        $this->packer = $packer;
        $this->uploader = $uploader;
        $this->pusher = $pusher;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(array $properties)
    {
        $this->status(self::STATUS, self::SECTION);

        // sanity check
        if (!isset($properties[TargetEnum::TYPE_EB]) || !$this->verifyConfiguration($properties[TargetEnum::TYPE_EB])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 200;
        }

        // authenticate
        if (!$clients = $this->authenticate($properties)) {
            return 201;
        }

        list($eb, $s3) = $clients;

        if (!$this->health($eb, $properties)) {
            return 202;
        }

        // create zip for s3
        if (!$this->pack($properties)) {
            return 203;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // upload version to S3
        if (!$this->upload($s3, $properties)) {
            return 204;
        }

        // push
        if (!$this->push($eb, $properties)) {
            return 205;
        }

        // SKIP post-push commands
        if ($properties['configuration']['post_push']) {
            $this->logger->event('info', self::SKIP_POST_PUSH);
        }

        // success
        return 0;
    }

    /**
     * @param array $properties
     *
     * @return bool
     */
    private function verifyConfiguration($properties)
    {
        $this->status('Verifying EB configuration', self::SECTION);

        if (!is_array($properties)) {
            return false;
        }

        $required = [
            // aws
            'region',
            'credential',
            // codedeploy
            'application',
            'environment',
            // s3
            'bucket',
            'file',
            'src'
        ];

        foreach ($required as $prop) {
            if (!array_key_exists($prop, $properties)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return array|null
     */
    private function authenticate(array $properties)
    {
        $this->status('Authenticating with AWS', self::SECTION);

        $eb = $this->authenticator->getEB(
            $properties[TargetEnum::TYPE_EB]['region'],
            $properties[TargetEnum::TYPE_EB]['credential']
        );

        if (!$eb) {
            return null;
        }

        $s3 = $this->authenticator->getS3(
            $properties[TargetEnum::TYPE_EB]['region'],
            $properties[TargetEnum::TYPE_EB]['credential']
        );

        if (!$s3) {
            return null;
        }

        return [$eb, $s3];
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param array $properties
     *
     * @return boolean
     */
    private function health(ElasticBeanstalkClient $eb, array $properties)
    {
        $this->status('Checking AWS environment health', self::SECTION);

        $health = $this->health;
        $health = $health(
            $eb,
            $properties[TargetEnum::TYPE_EB]['application'],
            $properties[TargetEnum::TYPE_EB]['environment']
        );

        if ($health['status'] !== 'Ready') {
            $this->logger->event('failure', self::ERR_ENVIRONMENT_HEALTH, $health);
            return false;
        }

        return true;
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function pack(array $properties)
    {
        $this->status('Packing build for S3', self::SECTION);

        return $this->packer->packZip(
            $properties['location']['path'],
            $properties[TargetEnum::TYPE_EB]['src'],
            $properties['location']['tempUploadArchive']
        );
    }

    /**
     * @param S3Client $s3
     * @param array $properties
     *
     * @return boolean
     */
    private function upload(S3Client $s3, array $properties)
    {
        $this->status('Pushing code to S3', self::SECTION);

        $release = $properties['release'];
        $build = $properties['build'];
        $environment = $release->target()->group()->environment();

        $metadata = [
            'Build' => $build->id(),
            'Release' => $release->id(),
            'Environment' => $environment->name()
        ];

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $properties['location']['tempUploadArchive'],
            $properties[TargetEnum::TYPE_EB]['bucket'],
            $properties[TargetEnum::TYPE_EB]['file'],
            $metadata
        );
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param array $properties
     *
     * @return boolean
     */
    private function push(ElasticBeanstalkClient $eb, array $properties)
    {
        $this->status('Deploying version to EB', self::SECTION);

        $release = $properties['release'];
        $build = $properties['build'];
        $environment = $release->target()->group()->environment();

        $pusher = $this->pusher;
        return $pusher(
            $eb,
            $properties[TargetEnum::TYPE_EB]['application'],
            $properties[TargetEnum::TYPE_EB]['environment'],
            $properties[TargetEnum::TYPE_EB]['bucket'],
            $properties[TargetEnum::TYPE_EB]['file'],
            $build->id(),
            $release->id(),
            $environment->name()
        );
    }
}
