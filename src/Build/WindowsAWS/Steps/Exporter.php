<?php
/**
 * @copyright (c) 2018 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS\Steps;

use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
use Hal\Agent\Build\Generic\FileCompression;
use Hal\Agent\Build\InternalDebugLoggingTrait;
use Hal\Agent\Logger\EventLogger;
use Hal\Agent\Build\WindowsAWS\AWS\S3Uploader;
use Hal\Agent\Build\WindowsAWS\AWS\SSMCommandRunner;
use Hal\Agent\Build\WindowsAWS\Utility\Powershellinator;

class Exporter
{
    use InternalDebugLoggingTrait;

    /**
     * @var string
     */
    const EVENT_MESSAGE = 'Export to AWS build server';

    const TIMEOUT_COMMAND = 120;

    /**
     * @var EventLogger
     */
    private $logger;

    /**
     * @var FileCompression
     */
    private $fileCompression;

    /**
     * @var S3Uploader
     */
    private $uploader;

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
     * @param FileCompression $fileCompression
     * @param S3Uploader $uploader
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     */
    public function __construct(
        EventLogger $logger,
        FileCompression $fileCompression,
        S3Uploader $uploader,
        SSMCommandRunner $runner,
        Powershellinator $powershell
    ) {
        $this->logger = $logger;
        $this->fileCompression = $fileCompression;

        $this->uploader = $uploader;
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
     * @param string $buildPath
     * @param string $buildFile
     * @param string $bucket
     * @param string $object
     *
     * @return bool
     */
    public function __invoke(S3Client $s3, SsmClient $ssm, $instanceID, $jobID, $buildPath, $buildFile, $bucket, $object)
    {
        if (!$this->prepareBuilder($ssm, $instanceID)) {
            return false;
        }

        if (!$this->fileCompression->packTarArchive($buildPath, $buildFile)) {
            return false;
        }

        if (!$this->transferFile($s3, $buildFile, $bucket, $object, $jobID)) {
            return false;
        }

        if (!$this->unpackAWSFile($ssm, $instanceID, $bucket, $object, $jobID)) {
            return false;
        }

        return true;
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @return bool
     */
    private function prepareBuilder(SsmClient $ssm, $instanceID)
    {
        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('verifyAndPrepareBuilder')
            ],
            'executionTimeout' => [(string) self::TIMEOUT_COMMAND]
        ];

        return ($this->runner)(
            $ssm,
            $instanceID,
            SSMCommandRunner::TYPE_POWERSHELL,
            $config,
            [$this->isDebugLoggingEnabled(), self::EVENT_MESSAGE]
        );
    }

    /**
     * @param S3Client $s3
     * @param string $buildFile
     * @param string $bucket
     * @param string $object
     * @param string $buildID
     *
     * @return bool
     */
    private function transferFile(S3Client $s3, $buildFile, $bucket, $object, $buildID)
    {
        $metadata =[
            'Build' => $buildID
        ];

        return ($this->uploader)($s3, $buildFile, $bucket, $object, $metadata);
    }

    /**
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $bucket
     * @param string $object
     * @param string $buildID
     *
     * @return bool
     */
    private function unpackAWSFile(SsmClient $ssm, $instanceID, $bucket, $object, $buildID)
    {
        $workDir = $this->powershell->getBaseBuildPath();

        $localFile = "${workDir}\\${buildID}.tar.gz";
        $inputDir = "${workDir}\\${buildID}";

        $config = [
            'commands' => [
                $this->powershell->getStandardPowershellHeader(),
                $this->powershell->getScript('downloadBuild', [
                    'localFile' => $localFile,
                    'bucket' => $bucket,
                    'object' => $object,
                ]),
                $this->powershell->getScript('untarBuild', [
                    'localFile' => $localFile,
                    'unpackDir' => $inputDir
                ])
            ],
            'workingDirectory' => [$workDir],
            'executionTimeout' => [(string) self::TIMEOUT_COMMAND]
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
