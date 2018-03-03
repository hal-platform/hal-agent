<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Sync;

use Aws\S3\S3Client;
use GuzzleHttp\Promise\PromiseInterface;
use Symfony\Component\Finder\Finder;

/**
 * Wraps Sync
 *
 * This class hides the process of constructing an Sync instance so higher
 * level modules don't have to depend upon it, enabling dependency inversion.
 */
class SyncManager
{
    /**
     * @var TransferManager
     */
    private $transferManager;

    /**
     * @var BatchDeleteManager
     */
    private $batchDeleteManager;

    /**
     * @var Finder
     */
    private $finder;

    /**
     * @param TransferManager $transferManager
     * @param BatchDeleteManager $batchDeleteManager
     * @param Finder $finder
     */
    public function __construct(TransferManager $transferManager, BatchDeleteManager $batchDeleteManager, Finder $finder)
    {
        $this->transferManager = $transferManager;
        $this->batchDeleteManager = $batchDeleteManager;
        $this->finder = $finder;
    }

    /**
     * @param S3Client    $s3Client The AWS S3 Client
     * @param String      $source   The source directory to sync from
     * @param String      $bucket   The destination to sync to
     * @param String|null $prefix   The s3 directory to sync to
     * @param int         $flags    Bit flags to set sync behavior
     *
     * @return void
     */
    public function sync(S3Client $s3Client, $source, $bucket, $prefix = null, $flags = 0)
    {
        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder->create(),
            $s3Client,
            $source,
            $bucket,
            $prefix,
            $flags
        );

        $sync->sync();
    }

    /**
     * @param S3Client    $s3Client The AWS S3 Client
     * @param String      $source   The source directory to sync from
     * @param String      $bucket   The destination to sync to
     * @param String|null $prefix   The s3 directory to sync to
     * @param int         $flags    Bit flags to set sync behavior
     *
     * @return PromiseInterface
     */
    public function promise(S3Client $s3Client, $source, $bucket, $prefix = null, $flags = 0)
    {
        $sync = new Sync(
            $this->transferManager,
            $this->batchDeleteManager,
            $this->finder->create(),
            $s3Client,
            $source,
            $bucket,
            $prefix,
            $flags
        );

        return $sync->promise();
    }
}
