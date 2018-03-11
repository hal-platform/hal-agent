<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\AWS;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use RuntimeException;

class S3Uploader
{
    // 10s * 30 attempts = 5 minutes
    private const WAITER_INTERVAL = 10;
    private const WAITER_ATTEMPTS = 30;

    /**
     * @var callable
     */
    private $fileStreamer;

    /**
     * @param callable $fileStreamer
     */
    public function __construct(callable $fileStreamer = null)
    {
        if ($fileStreamer === null) {
            $fileStreamer = $this->getDefaultFileStreamer();
        }

        $this->fileStreamer = $fileStreamer;
    }

    /**
     * @param S3Client $s3
     * @param string $localArtifact
     * @param string $bucket
     * @param string $object
     * @param array $metadata
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, string $localArtifact, string $bucket, string $object, array $metadata = []): bool
    {
        $fileHandle = call_user_func($this->fileStreamer, $localArtifact, 'r+');

        $params = $metadata ? ['params' => ['Metadata' => $metadata]] : [];

        try {
            $result = $s3->upload(
                $bucket,
                $object,
                $fileHandle,
                'bucket-owner-full-control', # 'private|public-read|public-read-write|authenticated-read|aws-exec-read|bucket-owner-read|bucket-owner-full-control'
                $params
            );

        } catch (AwsException $e) {
            return false;

        } catch (RuntimeException $e) {
            return false;
        }

        return $this->wait($s3, $bucket, $object);
    }

    /**
     * @return callable
     */
    private function getDefaultFileStreamer()
    {
        return 'fopen';
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     * @param string $object
     *
     * @return bool
     */
    private function wait(S3Client $s3, string $bucket, string $object)
    {
        try {
            $s3->waitUntil('ObjectExists', [
                'Bucket' => $bucket,
                'Key' => $object,
                '@waiter' => [
                    'delay' => static::WAITER_INTERVAL,
                    'maxAttempts' => static::WAITER_ATTEMPTS
                ]
            ]);

        } catch (AwsException $e) {
            return false;

        } catch (RuntimeException $e) {
            return false;
        }

        return true;
    }

}
