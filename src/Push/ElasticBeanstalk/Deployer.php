<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

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
     * @param HealthChecker $health
     * @param Packer $packer
     * @param Uploader $uploader
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        HealthChecker $health,
        Packer $packer,
        Uploader $uploader,
        Pusher $pusher
    ) {
        $this->logger = $logger;
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
        if (!isset($properties[ServerEnum::TYPE_EB])) {
            $this->logger->event('failure', self::ERR_INVALID_DEPLOYMENT_SYSTEM);
            return 200;
        }

        if (!$this->health($properties)) {
            return 201;
        }

        // create zip for s3
        if (!$this->pack($properties)) {
            return 202;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // upload version to S3
        if (!$this->upload($properties)) {
            return 203;
        }

        // push
        if (!$this->push($properties)) {
            return 204;
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
     * @return boolean
     */
    private function health(array $properties)
    {
        $this->status('Checking AWS environment health', self::SECTION);

        $health = $this->health;
        $health = $health(
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
     * @param array $properties
     *
     * @return boolean
     */
    private function upload(array $properties)
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
            $properties['location']['tempZipArchive'],
            $s3version,
            $build->id(),
            $push->id(),
            $env->name()
        );
    }

    /**
     * @param array $properties
     *
     * @return boolean
     */
    private function push(array $properties)
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
            $properties[ServerEnum::TYPE_EB]['application'],
            $properties[ServerEnum::TYPE_EB]['environment'],
            $s3version,
            $build->id(),
            $push->id(),
            $env->name()
        );
    }
}
