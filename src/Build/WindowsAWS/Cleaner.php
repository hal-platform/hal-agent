<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;
use Hal\Agent\Logger\EventLogger;

class Cleaner
{
    use InternalDebugLoggingTrait;

    const EVENT_MESSAGE = 'Clean remote AWS artifacts';
    const TIMEOUT_INTERNAL_COMMAND = 120;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var SSMCommandRunner
     */
    private $runner;

    /**
     * @var Powershellinator
     */
    private $powershell;

    /**
     * @param EventLogger $logger
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     */
    public function __construct(EventLogger $logger, SSMCommandRunner $runner, Powershellinator $powershell)
    {
        $this->logger = $logger;
        $this->runner = $runner;

        $this->powershell = $powershell;
    }

    /**
     * @param S3Client $s3
     * @param SsmClient $ssm
     *
     * @param string $instanceID
     * @param string $bucket
     * @param array $artifacts
     * @param string $buildID
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, SsmClient $ssm, $instanceID, $bucket, array $artifacts, $buildID)
    {
        # S3
        # - Remove input (if exists)
        # - Remove output (if exists)
        # - Remove SSM logs (if exists)
        $s3Status = $this->cleanBucket($s3, $bucket, $artifacts);

        # SSM
        # - Remove input (if exists)
        # - Remove output (if exists)
        # - Remove user scripts (if exists)
        $ssmStatus = $this->cleanBuilder($ssm, $instanceID, $buildID);

        if ($s3Status && $ssmStatus) {
            return true;
        }

        return false;
    }

    /**
     * @param S3Client $s3
     * @param string $bucket
     * @param array $artifacts
     *
     * @return bool
     */
    private function cleanBucket(S3Client $s3, $bucket, array $artifacts)
    {
        $context = [
            'bucket' => $bucket,
            'artifacts' => $artifacts
        ];

        try {
            foreach ($artifacts as $file) {
                if ($s3->doesObjectExist($bucket, $file)) {
                    $s3->deleteObject([
                        'Bucket' => $bucket,
                        'Key' => $file
                    ]);
                }
            }

        } catch (AwsException $e) {
            $context = array_merge($context, ['error' => $e->getMessage()]);
            $this->logger->event('failure', self::EVENT_MESSAGE, $context);
            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $buildID
     *
     * @return bool
     */
    private function cleanBuilder(SsmClient $ssm, $instanceID, $buildID)
    {
        $workDir = $this->powershell->getBaseBuildPath();

        $inputDir = "${workDir}\\${buildID}";
        $outputDir = "${workDir}\\${buildID}-output";

        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('cleanupAfterBuildOutput', [
                    'buildID' => $buildID,
                    'inputDir' => $inputDir,
                    'outputDir' => $outputDir
                ])
            ],
            'executionTimeout' => [(string) self::TIMEOUT_INTERNAL_COMMAND],
        ], [$this->isDebugLoggingEnabled()]);

        return $result;
    }
}
