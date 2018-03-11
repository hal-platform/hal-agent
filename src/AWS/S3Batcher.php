<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\AWS;

use ArrayIterator;
use Aws\S3\BatchDelete;
use Aws\S3\Transfer;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\PromisorInterface;

class S3Batcher
{
    /**
     * @param S3Client $s3
     * @param array $uploads
     *
     * @param string $localPath
     * @param string $bucket
     * @param string $path
     *
     * @return bool
     */
    public function transferFiles(S3Client $s3, array $uploads, string $localPath, string $bucket, string $path): bool
    {
        $from = new ArrayIterator($uploads);
        $to = rtrim("s3://${bucket}/${path}", '/');

        $options = [
            'base_dir' => $localPath,
            'concurrency' => 20,
            // 'debug' => true,
        ];

        $async = new Transfer($s3, $from, $to, $options);
        $this->wait($async);

        return true;
    }

    /**
     * @param S3Client $s3
     * @param array $removals
     *
     * @param string $bucket
     *
     * @return bool
     */
    public function deleteFiles(S3Client $s3, array $removals, string $bucket): bool
    {
        $iterator = new ArrayIterator($removals);

        $async = BatchDelete::fromIterator($s3, $bucket, $iterator);
        $this->wait($async);

        return true;
    }

    /**
     * @param PromisorInterface $async
     *
     * @return void
     */
    private function wait(PromisorInterface $async)
    {
        $promise = $async->promise();
        $promise->wait();
    }
}
