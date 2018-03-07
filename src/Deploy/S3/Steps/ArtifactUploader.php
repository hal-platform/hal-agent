<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Steps;

use Aws\S3\S3Client;

use Aws\S3\Exception\S3Exception;

class ArtifactUploader
{
    /**
     * @var callable
     */
    private $fileStreamer;

    /**
     * @param callable $fileStreamer
     */
    public function __construct(callable $fileStreamer = null)
    {
        if ($fileStreamer === null) {
            $fileStreamer = $this->getDefaultFileStreamer();
        }

        $this->fileStreamer = $fileStreamer;
    }

    /**
     * @param S3Client $s3
     * @param string $tempArchive
     * @param string $bucket
     * @param string $file
     * @param array $metadata
     *
     * @return boolean
     * @throws S3Exception
     */
    public function __invoke(S3Client $s3, string $tempArchive, string $bucket, string $file, array $metadata = []): bool
    {
        $fileHandle = call_user_func($this->fileStreamer, $tempArchive, 'r+');

        $params = $metadata ? ['params' => ['Metadata' => $metadata]] : [];

        $object = $s3->upload(
            $bucket,
            $file,
            $fileHandle,
            'bucket-owner-full-control', # 'private|public-read|public-read-write|authenticated-read|aws-exec-read|bucket-owner-read|bucket-owner-full-control'
            $params
        );

        return true;
    }

    /**
     * @return callable
     */
    private function getDefaultFileStreamer()
    {
        return 'fopen';
    }
}
