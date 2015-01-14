<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use QL\Hal\Agent\Push\Builder;
use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use Symfony\Component\Console\Output\OutputInterface;

class Deployer implements DeployerInterface
{
    const TYPE = 'elasticbeanstalk';
    const STATUS = 'Deploying push by EBS';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for AWS deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for AWS deployment';
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
     * @type Builder
     */
    private $builder;

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
     * @param Builder $builder
     * @param Uploader $uploader
     * @param Pusher $pusher
     */
    public function __construct(
        EventLogger $logger,
        HealthChecker $health,
        Builder $builder,
        Packer $packer,
        Uploader $uploader,
        Pusher $pusher
    ) {
        $this->logger = $logger;
        $this->health = $health;
        $this->builder = $builder;
        $this->packer = $packer;
        $this->uploader = $uploader;
        $this->pusher = $pusher;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(OutputInterface $output, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties['self::TYPE'])) {
            return 200;
        }

        if (!$this->health($output, $properties)) {
            return 201;
        }

        // run build transform commands
        if (!$this->build($output, $properties)) {
            return 202;
        }

        $this->logger->setStage('pushing');

        // create zip for s3
        if (!$this->pack($output, $properties)) {
            return 203;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // upload version to S3
        if (!$this->upload($output, $properties)) {
            return 204;
        }

        // push
        if (!$this->push($output, $properties)) {
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
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function health(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Checking AWS environment health');

        $health = $this->health;
        $health = $health(
            $properties['self::TYPE']['application'],
            $properties['self::TYPE']['environment']
        );

        if ($health['status'] !== 'Ready') {
            $this->logger->event('failure', self::ERR_ENVIRONMENT_HEALTH, $health);
            return false;
        }

        return true;
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function build(OutputInterface $output, array $properties)
    {
        if (!$properties['configuration']['build_transform']) {
            $this->status($output, 'Skipping build command');
            return true;
        }

        $this->status($output, 'Running build command');

        $builder = $this->builder;
        return $builder(
            $properties['configuration']['system'],
            $properties['location']['path'],
            $properties['configuration']['build_transform'],
            $properties['environmentVariables']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function pack(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Packing build for S3');

        $packer = $this->packer;
        return $packer(
            $properties['location']['path'],
            $properties['location']['tempZipArchive']
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function upload(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Pushing code to S3');

        $push = $properties['push'];
        $build = $properties['push']->getBuild();
        $env = $build->getEnvironment();

        $s3version = sprintf(
            '%s/%s',
            $properties['push']->getRepository()->getId(),
            $push->getId()
        );

        $uploader = $this->uploader;
        return $uploader(
            $properties['location']['tempZipArchive'],
            $s3version,
            $build->getId(),
            $push->getId(),
            $env->getKey()
        );
    }

    /**
     * @param OutputInterface $output
     * @param array $properties
     *
     * @return boolean
     */
    private function push(OutputInterface $output, array $properties)
    {
        $this->status($output, 'Deploying version to EBS');

        $push = $properties['push'];
        $build = $properties['push']->getBuild();
        $env = $build->getEnvironment();

        $s3version = sprintf(
            '%s/%s',
            $properties['push']->getRepository()->getId(),
            $push->getId()
        );

        $pusher = $this->pusher;
        return $pusher(
            $properties['self::TYPE']['application'],
            $properties['self::TYPE']['environment'],
            $s3version,
            $build->getId(),
            $push->getId(),
            $env->getKey()
        );
    }

    /**
     * @param OutputInterface $output
     * @param string $message
     *
     * @return null
     */
    private function status(OutputInterface $output, $message)
    {
        $message = sprintf('<comment>%s</comment>', $message);
        $output->writeln($message);
    }
}
