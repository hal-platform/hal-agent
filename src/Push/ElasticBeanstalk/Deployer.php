<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use Aws\S3\S3Client;
use QL\Hal\Agent\Push\AWSAuthenticator;
use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Agent\Symfony\OutputAwareInterface;
use QL\Hal\Agent\Symfony\OutputAwareTrait;
use QL\Hal\Core\Type\EnumType\ServerEnum;

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
     * @type EventLogger
     */
    private $logger;

    /**
     * @type AWSAuthenticator
     */
    private $authenticator;

    /**
     * @type HealthChecker
     */
    private $health;

    /**
     * @type Packer
     */
    private $packer;

    /**
     * @type Uploader
     */
    private $uploader;

    /**
     * @type Pusher
     */
    private $pusher;

    /**
     * @param EventLogger $logger
     * @param AWSAuthenticator $authenticator
     * @param HealthChecker $health
     * @param Packer $packer
     * @param Uploader $uploader
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        AWSAuthenticator $authenticator,
        HealthChecker $health,
        Packer $packer,
        Uploader $uploader,
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
        if (!isset($properties[ServerEnum::TYPE_EB]) || !$this->verifyConfiguration($properties[ServerEnum::TYPE_EB])) {
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

        if (!array_key_exists('region', $properties)) {
            return false;
        }

        if (!array_key_exists('credential', $properties)) {
            return false;
        }

        if (!array_key_exists('application', $properties)) {
            return false;
        }

        if (!array_key_exists('environment', $properties)) {
            return false;
        }

        if (!array_key_exists('bucket', $properties)) {
            return false;
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
            $properties[ServerEnum::TYPE_EB]['region'],
            $properties[ServerEnum::TYPE_EB]['credential']
        );

        if (!$eb) return null;

        $s3 = $this->authenticator->getS3(
            $properties[ServerEnum::TYPE_EB]['region'],
            $properties[ServerEnum::TYPE_EB]['credential']
        );

        if (!$s3) return null;

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
            $properties[ServerEnum::TYPE_EB]['application'],
            $properties[ServerEnum::TYPE_EB]['environment']
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

        $packer = $this->packer;
        return $packer(
            $properties['location']['path'],
            $properties['location']['tempZipArchive']
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

        $push = $properties['push'];
        $build = $properties['push']->build();
        $env = $build->environment();

        $s3version = sprintf(
            '%s/%s',
            $properties['push']->application()->id(),
            $push->id()
        );

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $properties['location']['tempZipArchive'],
            $properties[ServerEnum::TYPE_EB]['bucket'],
            $s3version,
            $build->id(),
            $push->id(),
            $env->name()
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

        $push = $properties['push'];
        $build = $properties['push']->build();
        $env = $build->environment();

        $s3version = sprintf(
            '%s/%s',
            $properties['push']->application()->id(),
            $push->id()
        );

        $pusher = $this->pusher;
        return $pusher(
            $eb,
            $properties[ServerEnum::TYPE_EB]['application'],
            $properties[ServerEnum::TYPE_EB]['environment'],
            $properties[ServerEnum::TYPE_EB]['bucket'],
            $s3version,
            $build->id(),
            $push->id(),
            $env->name()
        );
    }
}
