<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\S3\Artifact\Steps;

use Aws\S3\S3Client;
use Hal\Agent\Logger\EventLogger;

class Uploader
{
    public function __construct()
    {
    }

    /**
     * @param S3Client $s3
     * @param string $tempArchive
     * @param string $bucket
     * @param string $file
     * @param array $metadata
     *
     * @return boolean
     */
    public function __invoke(S3Client $s3, string $tempArchive, string $bucket, string $file, array $metadata = []): bool
    {
    }

    /**
     * Whether to allow an upload to replace an object if it already exists.
     *
     * Yes
     *     - S3
     * No
     *     - Elastic Beanstalk
     *     - CodeDeploy
     *
     * @param bool $allow
     *
     * @return void
     */
    public function allowOverwrite($allow = true)
    {
    }
}
