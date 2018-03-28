<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Deploy\CodeDeploy\Steps;

use Hal\Agent\Logger\EventLogger;

use Aws\S3\S3Client;
use Hal\Agent\AWS\S3Uploader;

class Uploader
{
    protected const ERR_OBJECT_EXISTS = 'Object already exists: cannot overwrite existing artifacts';

    /**
     * @var EventLogger
     */
    protected $logger;

    /**
     * @var S3Uploader
     */
    protected $s3Uploader;

    /**
     * @param EventLogger $logger
     * @param S3Uploader $s3Uploader
     */
    public function __construct(EventLogger $logger, S3Uploader $s3Uploader)
    {
        $this->logger = $logger;
        $this->s3Uploader = $s3Uploader;
    }

    /**
     * {@inheritdoc}
     */
    public function __invoke(S3Client $s3, string $localArtifact, string $bucket, string $object, array $metadata = []): bool
    {
        // CodeDeploy upload shouldn't overwrite an S3 object
        if ($s3->doesObjectExist($bucket, $object)) {
            $this->logger->event('failure', static::ERR_OBJECT_EXISTS);
            return false;
        }

        // Continue to S3Uploader as normal
        return ($this->s3Uploader)($s3, $localArtifact, $bucket, $object, $metadata);
    }
}
