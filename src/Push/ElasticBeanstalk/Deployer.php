<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use QL\Hal\Agent\Push\DeployerInterface;
use QL\Hal\Agent\Logger\EventLogger;
use QL\Hal\Core\Entity\Type\ServerEnumType;
use Symfony\Component\Console\Output\OutputInterface;

class Deployer implements DeployerInterface
{
    const STATUS = 'Deploying push by EB';

    const SKIP_PRE_PUSH = 'Skipping pre-push commands for EB deployment';
    const SKIP_POST_PUSH = 'Skipping post-push commands for EBdeployment';
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
    public function __invoke(OutputInterface $output, array $properties)
    {
        $this->status($output, self::STATUS);

        // sanity check
        if (!isset($properties[ServerEnumType::TYPE_EB])) {
            return 200;
        }

        if (!$this->health($output, $properties)) {
            return 201;
        }

        // create zip for s3
        if (!$this->pack($output, $properties)) {
            return 202;
        }

        // SKIP pre-push commands
        if ($properties['configuration']['pre_push']) {
            $this->logger->event('info', self::SKIP_PRE_PUSH);
        }

        // upload version to S3
        if (!$this->upload($output, $properties)) {
            return 203;
        }

        // push
        if (!$this->push($output, $properties)) {
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
            $properties[ServerEnumType::TYPE_EB]['application'],
            $properties[ServerEnumType::TYPE_EB]['environment']
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
        $this->status($output, 'Deploying version to EB');

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
            $properties[ServerEnumType::TYPE_EB]['application'],
            $properties[ServerEnumType::TYPE_EB]['environment'],
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
