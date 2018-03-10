<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;
use Hal\Agent\Deploy\S3\FileSync;

class SyncUploader
{
    /**
     * @var FileSync
     */
    private $filesync;

    /**
     * @param FileSync $filesync
     */
    public function __construct(FileSync $filesync)
    {
        $this->filesync = $filesync;
    }

    /**
     * @param S3Client $s3
     * @param string $localPath
     * @param string $bucket
     * @param string $path
     * @param array $metadata
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, string $localPath, string $bucket, string $path, array $metadata = []): bool
    {
        $params = $metadata ? ['params' => ['Metadata' => $metadata]] : [];

        $remotePath = $this->buildObjectPath($path);

        $this->filesync->filesync($s3, $localPath, $bucket, $remotePath);

        return true;
    }

    /**
     * @param string $path
     *
     * @return string
     */
    private function buildObjectPath($path)
    {
        if ($path === '.') {
            return '';

        } elseif (strpos($path, './') === 0) {
            return substr($path, 2);

        } elseif (strpos($path, '/') === 0) {
            return substr($path, 1);
        }

        return $path;
    }
}
