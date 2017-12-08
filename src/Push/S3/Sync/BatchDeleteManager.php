<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Aws\AwsClientInterface;
use Aws\S3\BatchDelete;
use Iterator;

/**
 * Wraps AWS BatchDelete
 *
 * This class hides the process of constructing a BatchDelete instance so
 * higher level modules don't have to depend upon it, enabling dependency
 * inversion.
 */
class BatchDeleteManager
{
    /**
     * @var BatchDelete
     */
    private $batchDelete;

    /**
     * Creates a BatchDelete object from all of the paginated results of a
     * ListObjects operation. Each result that is returned by the ListObjects
     * operation will be deleted.
     *
     * @param AwsClientInterface $client            AWS Client to use.
     * @param array              $listObjectsParams ListObjects API parameters
     * @param array              $options           BatchDelete options.
     *
     * @return BatchDelete
     */
    public function fromListObjects(AwsClientInterface $client, array $listObjectsParams, array $options = [])
    {
        return BatchDelete::fromListObjects($client, $listObjectsParams, $options);
    }

    /**
     * Creates a BatchDelete object from an iterator that yields results.
     *
     * @param AwsClientInterface $client  AWS Client to use to execute commands
     * @param string             $bucket  Bucket where the objects are stored
     * @param \Iterator          $iter    Iterator that yields assoc arrays
     * @param array              $options BatchDelete options
     *
     * @return BatchDelete
     */
    public function fromIterator(AwsClientInterface $client, $bucket, Iterator $iter, array $options = [])
    {
        return BatchDelete::fromIterator($client, $bucket, $iter, $options);
    }
}
