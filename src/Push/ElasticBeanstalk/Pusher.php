<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\Exception\AwsException;
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
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $awsEnvironment
     * @param string $s3version
     * @param string $buildId
     * @param string $pushId
     * @param string $environmentKey
     *
     * @return boolean
     */
    public function __invoke(
        ElasticBeanstalkClient $eb,
        $awsApplication,
        $awsEnvironment,
        $s3bucket,
        $s3version,
        $buildId,
        $pushId,
        $environmentKey
    ) {
        $context = [
            'AWS application' => $awsApplication,
            'AWS environment' => $awsEnvironment,
            'version' => $pushId,

            'bucket' => $s3bucket,
            'object' => $s3version
        ];

        try {

            // Error out if version already exists
            if ($this->doesVersionAlreadyExist($eb, $awsApplication, $pushId, $context)) {
                return false;
            }

            // create version
            $eb->createApplicationVersion([
                'ApplicationName' => $awsApplication,
                'VersionLabel' => $pushId,
                'Description' => sprintf('Build %s, Env %s', $buildId, $environmentKey),
                'SourceBundle' => [
                    'S3Bucket' => $s3bucket,
                    'S3Key' => $s3version
                ]
            ]);

            // update environment
            $eb->updateEnvironment([
                'EnvironmentId' => $awsEnvironment,
                'VersionLabel' => $pushId
            ]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);

            return false;
        }

        // Wait for deployment to finish
        if (!$this->wait($eb, $awsApplication, $awsEnvironment, $context)) {
            // unknown if deployment succeeded.
            // Log the timeout and continue.
            return true;
        }

        $this->logger->event('success', self::EVENT_MESSAGE, $context);
        return true;
    }

    /**
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $pushId
     * @param array $context
     *
     * @return bool
     */
    private function doesVersionAlreadyExist(ElasticBeanstalkClient $eb, $awsApplication, $pushId, array $context)
    {
        $versions = $eb->describeApplicationVersions([
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
     * @param ElasticBeanstalkClient $eb
     * @param string $awsApplication
     * @param string $awsEnvironment
     * @param array $context
     *
     * @return bool
     */
    private function wait(ElasticBeanstalkClient $eb, $awsApplication, $awsEnvironment, $context)
    {
        try {
            $eb->waitUntilEnvironmentReady([
                'ApplicationName' => $awsApplication,
                'EnvironmentIds' => [$awsEnvironment],
                'waiter.interval' => self::WAITER_INTERVAL,
                'waiter.max_attempts' => self::WAITER_ATTEMPTS
            ]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;
        }

        return true;
    }
}
