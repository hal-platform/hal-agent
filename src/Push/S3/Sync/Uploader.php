<?php
/**
 * @copyright (c) 2016 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\S3\Transfer;
use Aws\S3\BatchDelete;
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
     * @var SyncManager
     */
    private $syncManager;

    /**
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger, SyncManager $syncManager)
    {
        $this->logger = $logger;
        $this->syncManager = $syncManager;
    }

    /**
     * @param S3Client $s3
     * @param string $tempArchive
     * @param string $bucket
     * @param string $directory
     * @param array $metadata
     *
     * @return boolean
     */
    public function __invoke(S3Client $s3, $tempArchive, $bucket, $directory, array $metadata = [])
    {
        $context = [
            'bucket' => $bucket,
            'object' => $directory
        ];

        try {
            if (!$s3->doesBucketExist($bucket)) {
                $this->logger->event('failure', self::ERR_BUCKET_MISSING, $context);
                return false;
            }

            $params = $metadata ? ['params' => ['Metadata' => $metadata]] : [];

            if ($directory === '.') {
                $directory = '';
            } elseif (strpos($directory, './') === 0) {
                $directory = substr($directory, 2);
            } elseif (strpos($directory, '/') === 0) {
                $directory = substr($directory, 1);
            }

            $this->syncManager->sync(
                $s3,
                $tempArchive,
                $bucket,
                $directory,
                Sync::COMPARE | Sync::REMOVE
            );

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        } catch (InvalidArgumentException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        } catch (CredentialsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        return true;
    }
}
