<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use InvalidArgumentException;
use Hal\Agent\Logger\EventLogger;
use RuntimeException;

class Uploader
{
    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Upload to S3';
    const ERR_WAITING = 'Waited for upload to be available, but the operation timed out.';
    const ERR_UNEXPECTED = 'An unexpected error occurred during an S3 multipart upload.';
    const ERR_BUCKET_MISSING = 'S3 bucket not found.';
    const ERR_OBJECT_EXISTS = 'S3 object already exists for this version';

    // 10s * 30 attempts = 5 minutes
    const WAITER_INTERVAL = 10;
    const WAITER_ATTEMPTS = 30;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var callable
     */
    private $fileStreamer;

    /**
     * @var bool
     */
    private $allowOverwrite;

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

        $this->allowOverwrite = true;
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

            if (!$this->allowOverwrite) {
                // Error out if object already exists
                if ($s3->doesObjectExist($bucket, $file)) {
                    $this->logger->event('failure', self::ERR_OBJECT_EXISTS, $context);
                    return false;
                }
            }

            $object = $s3->upload(
                $bucket,
                $file,
                $fileHandle,
                'bucket-owner-full-control', # 'private|public-read|public-read-write|authenticated-read|aws-exec-read|bucket-owner-read|bucket-owner-full-control'
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

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;

        } catch (InvalidArgumentException $e) {
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
     * Whether to allow an upload to replace an object if it already exists.
     *
     * Yes
     *     - S3
     * No
     *     - Elastic Beanstalk
     *     - CodeDeploy
     *
     * @param bool $allow
     *
     * @return void
     */
    public function allowOverwrite($allow = true)
    {
        $this->allowOverwrite = (bool) $allow;
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
                '@waiter' => [
                    'delay' => self::WAITER_INTERVAL,
                    'maxAttempts' => self::WAITER_ATTEMPTS
                ]
            ]);

        } catch (AwsException $e) {
            $context = array_merge($context, [
                'wait time' => sprintf('%d seconds', self::WAITER_INTERVAL * self::WAITER_ATTEMPTS),
                'error' => $e->getMessage()
            ]);

            $this->logger->event('failure', self::ERR_WAITING, $context);
            return false;

        } catch(RuntimeException $e) {
            $this->logger->event('failure', self::ERR_UNEXPECTED, [
                'Bucket' => $bucket,
                'Key' => $file,
            ]);
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
