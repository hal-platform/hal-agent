<?php
/**
 * @copyright (c) 2017 Quicken Loans Inc.
 *
 * For full license information, please view the LICENSE distributed with this source code.
 */

namespace Hal\Agent\Build\WindowsAWS;

use Aws\S3\S3Client;
use Aws\Ssm\SsmClient;
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
     * @var Packer
     */
    private $packer;

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
     * @param Packer $packer
     * @param S3Uploader $uploader
     * @param SSMCommandRunner $runner
     * @param Powershellinator $powershell
     */
    public function __construct(
        EventLogger $logger,
        Packer $packer,
        S3Uploader $uploader,
        SSMCommandRunner $runner,
        Powershellinator $powershell
    ) {
        $this->logger = $logger;
        $this->packer = $packer;
        $this->uploader = $uploader;
        $this->runner = $runner;
        $this->powershell = $powershell;
    }

    /**
     * @param S3Client $s3
     * @param SsmClient $ssm
     * @param string $instanceID
     *
     * @param string $buildPath
     * @param string $buildFile
     * @param string $bucket
     * @param string $object
     * @param string $buildID
     *
     * @return boolean
     */
    public function __invoke(S3Client $s3, SsmClient $ssm, $instanceID, $buildPath, $buildFile, $bucket, $object, $buildID)
    {
        if (!$this->packBuild($buildPath, $buildFile)) {
            return false;
        }

        if (!$this->transferFile($s3, $buildFile, $bucket, $object, $buildID)) {
            return false;
        }

        if (!$this->unpackAWSFile($ssm, $instanceID, $bucket, $object, $buildID)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $buildPath
     * @param string $buildFile
     *
     * @return bool
     */
    private function packBuild($buildPath, $buildFile)
    {
        $packer = $this->packer;

        return $packer($buildPath, '.', $buildFile);
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

        $uploader = $this->uploader;
        return $uploader(
            $s3,
            $buildFile,
            $bucket,
            $object,
            $metadata
        );
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

        $runner = $this->runner;
        $result = $runner($ssm, $instanceID, SSMCommandRunner::TYPE_POWERSHELL, [
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
            'executionTimeout' => [(string) self::TIMEOUT_COMMAND],
        ], [$this->isDebugLoggingEnabled()]);

        return $result;
    }
}
