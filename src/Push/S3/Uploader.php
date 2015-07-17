<?php
/**
 * @copyright ©2015 Quicken Loans Inc. All rights reserved. Trade Secret,
 *    Confidential and Proprietary. Any dissemination outside of Quicken Loans
 *    is strictly prohibited.
 */

namespace QL\Hal\Agent\Push\S3;

use Aws\Common\Exception\AwsExceptionInterface;
use Aws\S3\S3Client;
use QL\Hal\Agent\Logger\EventLogger;

class Uploader
{
    /**
     * @type string
     */
    const EVENT_MESSAGE = 'Upload to S3';
    const ERR_WAITING = 'Waited for upload to be available, but the operation timed out.';
    const ERR_BUCKET_MISSING = 'S3 bucket not found.';

    // 10s * 30 attempts = 5 minutes
    const WAITER_INTERVAL = 10;
    const WAITER_ATTEMPTS = 30;

    /**
     * @type EventLogger
     */
    private $logger;

    /**
     * @type callable
     */
    private $fileStreamer;

    /**
     * @param EventLogger $logger
     * @param S3Client $s3
     * @param callable $fileStreamer
     */
    public function __construct(EventLogger $logger, callable $fileStreamer = null)
    {
        $this->logger = $logger;

        if ($fileStreamer === null) {
            $fileStreamer = $this->getDefaultFileStreamer();
        }

        $this->fileStreamer = $fileStreamer;
    }

    /**
     * @param S3Client $s3
     * @param string $tempArchive
     * @param string $bucket
     * @param string $file
     * @param string $buildID
     * @param string $pushID
     * @param string $environmentName
     *
     * @return boolean
     */
    public function __invoke(S3Client $s3, $tempArchive, $bucket, $file, $buildID, $pushID, $environmentName)
    {
        $fileHandle = call_user_func($this->fileStreamer, $tempArchive, 'r+');

        $context = [
            'bucket' => $bucket,
            'object' => $file
        ];

        try {
            if (!$s3->doesBucketExist($bucket)) {
                $this->logger->event('failure', self::ERR_BUCKET_MISSING, $context);
                return false;
            }

            $object = $s3->upload(
                $bucket,
                $file,
                $fileHandle,
                'private',
                [
                    'params' => [
                        'Metadata' => [
                            'Build' => $buildID,
                            'Push' => $pushID,
                            'Environment' => $environmentName
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
        if (!$this->wait($s3, $bucket, $file, $context)) {
            return false;
        }

        $this->logger->event('success', self::EVENT_MESSAGE, $context);
        return true;
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     * @param string $file
     * @param array $context
     *
     * @return bool
     */
    private function wait(S3Client $s3, $bucket, $file, array $context)
    {
        try {
            $s3->waitUntil('ObjectExists', [
                'Bucket' => $bucket,
                'Key' => $file,
                'waiter.interval' => self::WAITER_INTERVAL,
                'waiter.max_attempts' => self::WAITER_ATTEMPTS
            ]);

        } catch (AwsExceptionInterface $e) {
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
