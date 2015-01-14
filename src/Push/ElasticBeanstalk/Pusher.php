<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\Common\Exception\AwsExceptionInterface;
use Aws\ElasticBeanstalk\ElasticBeanstalkClient;
use QL\Hal\Agent\Logger\EventLogger;

class Pusher
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Code Deployment';
    const ERR_ALREADY_EXISTS = 'Application version already exists';
    const ERR_WAITING = 'Waited for deployment to finish, but the operation timed out.';

    // 10s * 60 attempts = 10 minutes
    const WAITER_INTERVAL = 10;
    const WAITER_ATTEMPTS = 60;

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type ElasticBeanstalkClient
     */
    private $ebs;

    /**
     * @type string
     */
    private $s3BuildsBucket;

    /**
     * @param EventLogger $logger
     * @param ElasticBeanstalkClient $ebs
     * @param string $s3BuildsBucket
     */
    public function __construct(EventLogger $logger, ElasticBeanstalkClient $ebs, $s3BuildsBucket)
    {
        $this->logger = $logger;
        $this->ebs = $ebs;
        $this->s3BuildsBucket = $s3BuildsBucket;
    }

    /**
     * @param string $awsApplication
     * @param string $awsEnvironment
     * @param string $s3version
     * @param string $buildId
     * @param string $pushId
     * @param string $environmentKey
     *
     * @return boolean
     */
    public function __invoke($awsApplication, $awsEnvironment, $s3version, $buildId, $pushId, $environmentKey)
    {
        $context = [
            'AWS application' => $awsApplication,
            'AWS environment' => $awsEnvironment,
            'version' => $pushId,
            'object' => $s3version
        ];

        // Error out if version already exists
        if ($this->doesVersionAlreadyExist($awsApplication, $pushId, $s3version, $context)) {
            return false;
        }

        try {
            # create version
            $this->ebs->createApplicationVersion([
                'ApplicationName' => $awsApplication,
                'VersionLabel' => $pushId,
                'Description' => "Build $buildId, Env $environmentKey",
                'SourceBundle' => [
                    'S3Bucket' => $this->s3BuildsBucket,
                    'S3Key' => $s3version
                ]
            ]);

            # update environment
            $this->ebs->updateEnvironment([
                'EnvironmentId' => $awsEnvironment,
                'VersionLabel' => $pushId
            ]);

        } catch (AwsExceptionInterface $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return false;
        }

        // Wait for deployment to finish
        if (!$this->wait($awsApplication, $awsEnvironment, $context)) {
            // unknown if deployment succeeded.
            // Log the timeout and continue.
            return true;
        }

        $this->logger->event('success', self::EVENT_MESSAGE, $context);
        return true;
    }

    /**
     * @param string $awsApplication
     * @param string $pushId
     * @param string $s3version
     * @param array $context
     *
     * @return bool
     */
    private function doesVersionAlreadyExist($awsApplication, $pushId, $s3version, array $context)
    {
        $versions = $this->ebs->describeApplicationVersions([
            'ApplicationName' => $awsApplication,
            'VersionLabels' => [$pushId]
        ]);
        $versions = $versions['ApplicationVersions'];

        if (count($versions) !== 0) {
            $this->logger->event('failure', self::ERR_ALREADY_EXISTS, $context);
            return true;
        }

        return false;
    }

    /**
     * @param string $awsApplication
     * @param string $awsEnvironment
     * @param array $context
     *
     * @return bool
     */
    private function wait($awsApplication, $awsEnvironment, $context)
    {
        try {
            $this->ebs->waitUntilEnvironmentReady([
                'ApplicationName' => $awsApplication,
                'EnvironmentIds' => [$awsEnvironment],
                'waiter.interval' => self::WAITER_INTERVAL,
                'waiter.max_attempts' => self::WAITER_ATTEMPTS
            ]);

        } catch (RuntimeException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;
        }

        return true;
    }
}
