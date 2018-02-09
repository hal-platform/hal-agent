<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Steps;

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
     * @param string $jobID
     *
     * @param string $bucket
     * @param array $artifacts
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, SsmClient $ssm, string $instanceID, string $jobID, string $bucket, array $artifacts)
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
        $ssmStatus = $this->cleanBuilder($ssm, $instanceID, $jobID);

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
        $result = true;

        foreach ($artifacts as $artifact) {
            if (!$this->cleanObject($s3, $bucket, $artifact)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @param SsmClient $ssm
     * @param string $bucket
     * @param string $file
     *
     * @return bool
     */
    private function cleanObject(S3Client $s3, $bucket, $file)
    {
        try {

            if (!$s3->doesObjectExist($bucket, $file)) {
                return true;
            }

            $s3->deleteObject([
                'Bucket' => $bucket,
                'Key' => $file
            ]);

        } catch (AwsException $e) {
            $this->logger->event('failure', self::EVENT_MESSAGE, [
                'bucket' => $bucket,
                'object' => $file,
                'error' => $e->getMessage()
            ]);

            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     * @param string $jobID
     *
     * @return bool
     */
    private function cleanBuilder(SsmClient $ssm, $instanceID, $jobID)
    {
        $workDir = $this->powershell->getBaseBuildPath();

        $inputDir = "${workDir}\\${jobID}";
        $outputDir = "${workDir}\\${jobID}-output";

        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('cleanupAfterBuildOutput', [
                    'buildID' => $jobID,
                    'inputDir' => $inputDir,
                    'outputDir' => $outputDir
                ])
            ],
            'executionTimeout' => [(string) self::TIMEOUT_INTERNAL_COMMAND],
        ];

        return ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [$this->isDebugLoggingEnabled(), self::EVENT_MESSAGE]
        );
    }
}
