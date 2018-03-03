<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;

class ArtifactVerifier
{
    // 10s * 30 attempts = 5 minutes
    const WAITER_INTERVAL = 10;
    const WAITER_ATTEMPTS = 30;

    public function __invoke(S3Client $s3, string $bucket, string $key): bool
    {
        $s3->waitUntil('ObjectExists', [
            'Bucket' => $bucket,
            'Key' => $key,
            '@waiter' => [
                'delay' => self::WAITER_INTERVAL,
                'maxAttempts' => self::WAITER_ATTEMPTS
            ]
        ]);

        return true;
    }
}
