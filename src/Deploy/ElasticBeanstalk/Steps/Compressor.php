<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\ElasticBeanstalk\Steps;

use Hal\Agent\Deploy\S3\Steps\Compressor as S3Compressor;

class Compressor
{
    /**
     * @var S3Compressor
     */
    private $s3Compressor;

    /**
     * @param S3Compressor $s3Compressor
     */
    public function __construct(S3Compressor $s3Compressor)
    {
        $this->s3Compressor = $s3Compressor;
    }

    /**
     * @param string $sourcePath
     * @param string $targetFile
     * @param string $remoteFile
     *
     * @return bool
     */
    public function __invoke(string $sourcePath, string $targetFile, string $remoteFile): bool
    {
        return ($this->s3Compressor)($sourcePath, $targetFile, $remoteFile);
    }
}
