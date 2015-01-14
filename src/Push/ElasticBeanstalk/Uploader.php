<?php
/**
 * @copyright ©2014 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\ElasticBeanstalk;

use Aws\Common\Exception\AwsExceptionInterface;
use Aws\S3\S3Client;
use QL\Hal\Agent\Logger\EventLogger;

class Uploader
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Upload version to S3';
    const ERR_ALREADY_EXISTS = 'S3 object already exists for this version';
    const ERR_WAITING = 'Waited for upload to be available, but the operation timed out.';

    // 10s * 30 attempts = 5 minutes
    const WAITER_INTERVAL = 10;
    const WAITER_ATTEMPTS = 30;

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type S3Client
     */
    private $s3;

    /**
     * @type string
     */
    private $s3BuildsBucket;

    /**
     * @type callable
     */
    private $fileStreamer;

    /**
     * @param EventLogger $logger
     * @param S3Client $s3
     * @param string $s3BuildsBucket
     * @param callable $fileStreamer
     */
    public function __construct(EventLogger $logger, S3Client $s3, $s3BuildsBucket, callable $fileStreamer = null)
    {
        $this->logger = $logger;
        $this->s3 = $s3;
        $this->s3BuildsBucket = $s3BuildsBucket;

        if ($fileStreamer === null) {
            $fileStreamer = $this->getDefaultFileStreamer();
        }

        $this->fileStreamer = $fileStreamer;
    }

    /**
     * @param string $tempZipArchive
     * @param string $s3version
     * @param string $buildId
     * @param string $pushId
     * @param string $environmentKey
     *
     * @return boolean
     */
    public function __invoke($tempZipArchive, $s3version, $buildId, $pushId, $environmentKey)
    {
        $fileHandle = call_user_func($this->fileStreamer, $tempZipArchive, 'r+');

        $context = [
            'object' => $s3version
        ];

        // Error out if object already exists
        if ($this->s3->doesObjectExist($this->s3BuildsBucket, $s3version)) {
            $this->logger->event('failure', self::ERR_ALREADY_EXISTS, $context);
        }

        try {
            $object = $this->s3->upload(
                $this->s3BuildsBucket,
                $s3version,
                $fileHandle,
                'private',
                [
                    'params' => [
                        'Metadata' => [
                            'Build' => $buildId,
                            'Push' => $pushId,
                            'Environment' => $environmentKey
                        ]
                    ]
                ]
            );

        } catch (AwsExceptionInterface $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        // Wait for object to be accessible
        if (!$this->wait($s3version, $context)) {
            return false;
        }

        $this->logger->event('success', self::EVENT_MESSAGE, $context);
        return true;
    }

    /**
     * @param string $s3version
     * @param array $context
     *
     * @return bool
     */
    private function wait($s3version, array $context)
    {
        try {
            $this->s3->waitUntil('ObjectExists', [
                'Bucket' => $this->s3BuildsBucket,
                'Key' => $s3version,
                'waiter.interval' => self::WAITER_INTERVAL,
                'waiter.max_attempts' => self::WAITER_ATTEMPTS
            ]);

        } catch (RuntimeException $e) {
            $context = array_merge($context, [
                'wait time' => sprintf('%d seconds', self::WAITER_INTERVAL * self::WAITER_ATTEMPTS),
                'error' => $e->getMessage()
            ]);

            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;
        }

        return true;
    }

    /**
     * @return callable
     */
    private function getDefaultFileStreamer()
    {
        return 'fopen';
    }
}
