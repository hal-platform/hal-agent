<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3;

use ArrayIterator;
use Aws\S3\S3Client;
use Aws\S3\Transfer;
use Aws\S3\BatchDelete;
use GuzzleHttp\Promise\PromiseInterface;
use Hal\Agent\Utility\OptionTrait;
use Symfony\Component\Finder\Finder;

class FileSync
{
    use OptionTrait;

    /**
     * Bit flag for comparing files from the source against the destination to only upload updated files.
     */
    public const COMPARE_FILES = 1;

    /**
     * Bit flag for removing files from the destination if they don't exist in the source.
     */
    public const REMOVE_EXTRA_FILES = 2;

    /**
     * @var Finder
     */
    private $finder;

    /**
     * @var Comparator
     */
    private $comparator;

    /**
     * @param Finder $finder
     * @param Comparator $comparator
     */
    public function __construct(Finder $finder, Comparator $comparator)
    {
        $this->finder = $finder;
        $this->comparator = $comparator;
    }

    /**
     * @param S3Client $s3
     * @param string $localPath
     * @param string $bucket
     * @param string $prefixPath
     *
     * @return void
     */
    public function filesync(S3Client $s3, $localPath, $bucket, $prefixPath)
    {
        $localFiles = $this->createLocalFileMap($localPath);
        $remoteObjects = $this->getS3Objects($s3, $bucket, $prefixPath);

        [$uploads, $removals] = $this->buildFilesToActUpon($prefixPath, $localFiles, $remoteObjects);

        $async = $this->transferFiles($s3, $uploads, $localPath, $bucket, $prefixPath);
        $async = $this->maybeDeleteExtras($async, $s3, $removals, $bucket);

        $async->wait();
    }

    /**
     * @param PromiseInterface $promise
     * @param S3Client $s3
     * @param array $removals
     * @param string $bucket
     *
     * @return PromiseInterface
     */
    private function maybeDeleteExtras(PromiseInterface $promise, S3Client $s3, array $removals, $bucket)
    {
        if (!$this->isFlagEnabled(self::REMOVE_EXTRA_FILES)) {
            return $promise;
        }

        if (!$removals) {
            return $promise;
        }

        $delete = function () use ($s3, $removals, $bucket) {
            return $this->deleteExtraFiles($s3, $removals, $bucket);
        };

        return $promise->then($delete);
    }

    /**
     * Loops over a list of S3 Objects to to build a list of files to transfer/delete
     *
     * @param string $prefixPath
     * @param array $localFiles
     * @param array $remoteObjects
     *
     * @return array
     */
    private function buildFilesToActUpon($prefixPath, array $localFiles, array $remoteObjects)
    {
        $removals = [];

        foreach ($remoteObjects as $s3Object) {
            $path = $this->buildRelativeS3ObjectPathname($prefixPath, $s3Object);

            $sourceFile = array_key_exists($path, $localFiles) ? $localFiles[$path] : null;

            if ($this->isFlagEnabled(self::COMPARE_FILES) && $this->comparator->areSame($sourceFile, $s3Object)) {
                unset($localFiles[$path]);

            } elseif ($this->isFlagEnabled(self::REMOVE_EXTRA_FILES) && !$sourceFile) {
                $removals[$path] = $s3Object;
            }
        }

        $uploads = array_map(function ($fileInfo) {
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
     * @param string $prefix
     * @param array $s3Object
     *
     * @return string
     */
    private function buildRelativeS3ObjectPathname($prefix, $s3Object)
    {
        if (!$prefix) {
            return $s3Object['Key'];
        }

        $objectPathPrefix = preg_quote($prefix . '/', '#');
        return preg_replace("#^${objectPathPrefix}#", '', $s3Object['Key']);
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     * @param string $prefix
     *
     * @return array
     */
    private function getS3Objects(S3Client $s3, $bucket, $prefix)
    {
        $options = [
            'Bucket' => $bucket
        ];

        if ($prefix) {
            $options['Prefix'] = $prefix;
        }

        $paginator = $s3->getPaginator('ListObjects', $options);

        $objects = [];
        foreach ($paginator as $result) {
            $objects = array_merge($objects, $result->search('Contents') ?: []);
        }

        return $objects;
    }

    /**
     * Creates an associative array for faster lookup of source files
     *
     * @param string $localPath
     *
     * @return array
     */
    private function createLocalFileMap($localPath)
    {
        $finder = $this->finder->create();

        $localFiles = $finder
            ->files()
            ->in($localPath);

        $map = [];
        foreach ($localFiles as $file) {
            $map[$file->getRelativePathname()] = $file;
        }

        return $map;
    }

    /**
     * @param S3Client $s3
     * @param array $uploads
     * @param string $localPath
     * @param string $bucket
     * @param string $prefix
     *
     * @return PromiseInterface
     */
    private function transferFiles(S3Client $s3, array $uploads, $localPath, $bucket, $prefix)
    {
        $from = new ArrayIterator($uploads);
        $to = rtrim("s3://${bucket}/${prefix}", '/');

        $options = [
            'base_dir' => $localPath,
            'concurrency' => 20,
            // 'debug' => true,
        ];

        $transfer = new Transfer($s3, $from, $to, $options);

        return $transfer->promise();
    }

    /**
     * @param S3Client $s3
     * @param array $removals
     * @param string $bucket
     *
     * @return PromiseInterface
     */
    private function deleteExtraFiles(S3Client $s3, array $removals, $bucket)
    {
        $iterator = new ArrayIterator($removals);

        $async = BatchDelete::fromIterator($s3, $bucket, $iterator);

        return $async->promise();
    }
}
