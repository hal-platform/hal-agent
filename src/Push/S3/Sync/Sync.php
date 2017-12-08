<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Aws\S3\S3Client;
use Aws\S3\Transfer;
use Aws\S3\BatchDelete;
use Aws\ResultPaginator;
use Aws\Result;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\PromisorInterface;
use Symfony\Component\Finder\Finder;
use ArrayIterator;
use Iterator;
use SplFileInfo;

/**
 * Handles syncing between a local directory and an S3 bucket
 */
class Sync implements PromisorInterface
{
    /**
     * Bit flag for comparing files from the source against the destination to
     * only upload updated files.
     */
    const COMPARE = 1;
    /**
     * Bit flag for removing files from the destination if they don't exist in
     * the source.
     */
    const REMOVE = 2;

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
     * @var S3Client
     */
    private $s3Client;

    /**
     * @var String
     */
    private $source;

    /**
     * @var String
     */
    private $bucket;

    /**
     * @var String|null
     */
    private $prefix;

    /**
     * @var Integer
     */
    private $flags;

    /**
     * @param TransferManager    $transferManager    The Transfer Manager
     * @param BatchDeleteManager $batchDeleteManager The BatchDelete Manager
     * @param Finder             $finder             A Symfony Finder instance
     * @param S3Client           $s3Client           The AWS S3 Client
     * @param String             $source             The local directory to sync from
     * @param String             $bucket             The s3 bucket to sync to
     * @param String|null        $prefix             The s3 object (directory) to sync to
     * @param int                $flags              Bit flags to set sync behavior
     */
    public function __construct(
        TransferManager $transferManager,
        BatchDeleteManager $batchDeleteManager,
        Finder $finder,
        S3Client $s3Client,
        $source,
        $bucket,
        $prefix,
        $flags = 0
    ) {
        $this->transferManager = $transferManager;
        $this->batchDeleteManager = $batchDeleteManager;
        $this->finder = $finder;
        $this->s3Client = $s3Client;
        $this->source = $source;
        $this->bucket = $bucket;
        $this->prefix = $prefix;
        $this->flags = $flags;
    }

    /**
     * Synchronously syncs S3 storage
     *
     * @return void
     */
    public function sync()
    {
        $this->promise()->wait();
    }

    /**
     * Asynchronously syncs S3 storage and returns a promise
     *
     * @return PromiseInterface
     */
    public function promise()
    {
        $paginatedResults = $this->getS3ObjectLists();

        $objects = [];
        foreach($paginatedResults as $result) {
            $objects = array_merge($objects, $result->search('Contents') ?: []);
        }

        list($uploads, $removals) = $this->buildFilesToActUpon($objects, $this->createLocalFileMap());

        return $this->syncFiles($uploads, $removals);
    }

    /**
     * Determines whether files should be compared before uploading
     *
     * @return bool
     */
    protected function shouldCompareFiles()
    {
        return $this->checkBitFlag(static::COMPARE, $this->flags);
    }

    /**
     * Determines whether remote files that don't exist locally should be removed
     *
     * @return bool
     */
    protected function shouldRemoveFiles()
    {
        return $this->checkBitFlag(static::REMOVE, $this->flags);
    }

    /**
     * Begins the transfer and delete steps of the S3 Sync
     *
     * @param array $uploads
     * @param array $removals
     *
     * @return PromiseInterface $transfer
     */
    protected function syncFiles(array $uploads, array $removals)
    {
        $async = $this->transfer($uploads);

        if ($this->shouldRemoveFiles() && $removals) {
            $async = $async->then(function() use ($removals) {
                return $this->delete($removals);
            });
        }

        return $async;
    }

    /**
     * Loops over a list of S3 Objects to to build a list of files to transfer/delete
     *
     * @param array $objects
     * @param array $localFiles
     *
     * @return array
     */
    protected function buildFilesToActUpon(array $objects, array $localFiles)
    {
        $removals = [];
        foreach ($objects as $s3Object) {
            $path = $this->buildRelativeS3ObjectPathname($s3Object);

            $sourceFile = array_key_exists($path, $localFiles) ? $localFiles[$path] : null;

            if ($this->shouldCompareFiles() && $this->areSame($sourceFile, $s3Object)) {
                unset($localFiles[$path]);

            } elseif ($this->shouldRemoveFiles() && !$sourceFile) {
                $removals[$path] = $s3Object;
            }
        }

        $uploads = array_map(function($fileInfo) {
            return $fileInfo->getPathname();
        }, $localFiles);

        return [
            $uploads,
            $removals
        ];
    }

    /**
     * Returns the relative pathname for the given s3Object
     *
     * @param array $s3Object
     *
     * @return string
     */
    protected function buildRelativeS3ObjectPathname($s3Object)
    {
        if (!$this->prefix) {
            return $s3Object['Key'];
        }

        return preg_replace('#^' . preg_quote($this->prefix . '/', '#') . '#', '', $s3Object['Key']);
    }

    /**
     * Checks flags integer for provided bit flag
     *
     * @param int $bitFlag The flag (or flags) to check for
     * @param int $flags   The int to check for the flag in
     *
     * @return bool
     */
    protected function checkBitFlag($bitFlag, $flags)
    {
        return (bool) (($flags & $bitFlag) === $bitFlag);
    }

    /**
     * Creates a Paginator for looping over AWS S3 ListObjects result pages
     *
     * @return Iterator
     */
    protected function getS3ObjectLists()
    {
        $options = [
            'Bucket' => $this->bucket
        ];

        if ($this->prefix) {
            $options['Prefix'] = $this->prefix;
        }

        return $this->s3Client->getPaginator('ListObjects', $options);
    }

    /**
     * Creates an associative array for faster lookup of source files
     *
     * @return array
     */
    protected function createLocalFileMap()
    {
        $localFiles = $this->finder
            ->files()
            ->in($this->source);

        $fileMap = [];
        foreach ($localFiles as $file) {
            $fileMap[$file->getRelativePathname()] = $file;
        }

        return $fileMap;
    }

    /**
     * Begins transferring files to S3
     *
     * @param array $uploads
     *
     * @return PromiseInterface
     */
    protected function transfer(array $uploads)
    {
        $transfer = $this->transferManager->build(
            $this->s3Client,
            new ArrayIterator($uploads),
            $this->buildS3Target(),
            [
                'base_dir' => $this->source,
                'concurrency' => 20,
                // 'debug' => true,
            ]
        );

        return $transfer->promise();
    }

    /**
     * Creates the s3 transfer destination
     *
     * @return String
     */
    protected function buildS3Target()
    {
        return 's3://' . rtrim($this->bucket . DIRECTORY_SEPARATOR . $this->prefix, DIRECTORY_SEPARATOR);
    }

    /**
     * Begins deleting files from S3
     *
     * @param array $removals
     *
     * @return PromiseInterface
     */
    protected function delete(array $removals)
    {
        $deleteManager = $this->createBatchDelete($removals);

        return $deleteManager->promise();
    }

    /**
     * Creates an AWS S3 BatchDelete object
     *
     * @param array $removals
     *
     * @return BatchDelete
     */
    protected function createBatchDelete(array $removals)
    {
        return $this->batchDeleteManager->fromIterator(
            $this->s3Client,
            $this->bucket,
            new ArrayIterator($removals)
        );
    }

    /**
     * Runs various comparisons between two files
     *
     * @param SplFileInfo $localFile the original file
     * @param array       $s3object  the file to compare against
     *
     * @return bool
     */
    protected function areSame(SplFileInfo $localFile = null, array $s3object)
    {
        if (is_null($localFile)) {
            return false;
        }

        if (!$this->areSameSize($localFile, $s3object)) {
            return false;
        }

        if (!$this->wereLastModifiedSameDate($localFile, $s3object)) {
            return false;
        }

        return true;
    }

    /**
     * Compares the file size between two files
     *
     * @param SplFileInfo $localFile the original file
     * @param array       $s3object  the file to compare against
     *
     * @return bool
     */
    protected function areSameSize(SplFileInfo $localFile, array $s3object)
    {
        if (!isset($s3object['Size'])) {
            return false;
        }

        return $localFile->getSize() === $s3object['Size'];
    }

    /**
     * Compares the date between two files
     *
     * @param SplFileInfo $localFile the original file
     * @param array       $s3object  the file to compare against
     *
     * @return bool
     */
    protected function wereLastModifiedSameDate(SplFileInfo $localFile, array $s3object)
    {
        if (!isset($s3object['LastModified'])) {
            return false;
        }

        return $localFile->getMTime() === $s3object['LastModified']->getTimestamp();
    }
}
