<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Push\S3\Sync;

use Aws\S3\S3ClientInterface;
use Aws\S3\Transfer;

/**
 * Wraps AWS Transfer
 *
 * This class hides the process of constructing a Transfer instance so higher
 * level modules don't have to depend upon it, enabling dependency inversion.
 */
class TransferManager
{
    /**
     * @var Transfer
     */
    private $transfer;

    /**
     * @param S3ClientInterface $client  Client used for transfers.
     * @param string|Iterator   $source  Where the files are transferred from.
     * @param string            $dest    Where the files are transferred to.
     * @param array             $options Hash of options.
     *
     * @return Transfer
     */
    public function build(S3ClientInterface $s3Client, $source, $dest, array $options = [])
    {
        return new Transfer($s3Client, $source, $dest, $options);
    }
}
