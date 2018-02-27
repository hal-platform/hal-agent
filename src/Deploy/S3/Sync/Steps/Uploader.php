<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Sync\Steps;

use Aws\Exception\AwsException;
use Aws\Exception\CredentialsException;
use Aws\S3\S3Client;
use InvalidArgumentException;
use Hal\Agent\Deploy\S3\Sync\Upload\SyncManager;
use Hal\Agent\Deploy\S3\Sync\Upload\Sync;

class Uploader
{
    // 10s * 30 attempts = 5 minutes
    const WAITER_INTERVAL = 10;
    const WAITER_ATTEMPTS = 30;

    /**
     * @var SyncManager
     */
    private $syncManager;

    /**
     * @param SyncManager $syncManager
     */
    public function __construct(SyncManager $syncManager)
    {
        $this->syncManager = $syncManager;
    }

    /**
     * @param S3Client $s3
     * @param string $tempArchive
     * @param string $bucket
     * @param string $directory
     * @param array $metadata
     *
     * @throws AwsException
     * @throws InvalidArgumentException
     * @throws CredentialsException
     *
     * @return boolean
     */
    public function __invoke(S3Client $s3, $tempArchive, $bucket, $directory, array $metadata = [])
    {
        $context = [
            'bucket' => $bucket,
            'object' => $directory
        ];

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

        return true;
    }
}
