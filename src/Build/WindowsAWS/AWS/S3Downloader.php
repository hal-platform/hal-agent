<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\AWS;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use InvalidArgumentException;
use Hal\Agent\Logger\EventLogger;

class S3Downloader
{
    use InternalDebugLoggingTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Download from S3';
    const ERR_OBJECT_MISSING = 'S3 object not found.';

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @param EventLogger $logger
     */
    public function __construct(EventLogger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     * @param string $object
     * @param string $localFile
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, $bucket, $object, $localFile)
    {
        $context = [
            'bucket' => $bucket,
            'object' => $object
        ];

        try {
            if (!$s3->doesObjectExist($bucket, $object)) {
                $this->logger->event('failure', self::ERR_OBJECT_MISSING, $context);
                return false;
            }

            $object = $s3->getObject([
                'Bucket' => $bucket,
                'Key' => $object,
                'SaveAs' => $localFile
            ]);

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', static::EVENT_MESSAGE, $context);
            return false;

        } catch (InvalidArgumentException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', static::EVENT_MESSAGE, $context);
            return false;
        }

        if ($this->isDebugLoggingEnabled()) {
            $this->logger->event('success', static::EVENT_MESSAGE, $context);
        }

        return true;
    }
}
